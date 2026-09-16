<?php
// ─────────────────────────────────────────────────────────────
//  includes/shift_guard.php
//  Mandatory clock-in gatekeeper for operational pages.
//
//  Usage on a page:   require_once '../includes/shift_guard.php';
//                     ... after the sidebar include:
//                     render_shift_gate();
//
//  Usage in an API:   require_shift_for_api();
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Roles that are never gated (they manage the system, not a till).
if (!defined('SHIFT_EXEMPT_ROLES')) {
    define('SHIFT_EXEMPT_ROLES', ['admin', 'manager', 'hr', 'supplier']);
}

/**
 * Current shift state for the logged-in user.
 *
 * @return array{gated:bool, open:bool, employee_id:?int, reason:string, time_in:?string}
 *   gated=false means this account isn't subject to the guard at all.
 */
function shift_state(): array {
    $out = ['gated' => false, 'open' => true, 'employee_id' => null, 'reason' => '', 'time_in' => null];

    if (empty($_SESSION['user_id'])) return $out;

    $roles = array_map('strtolower', $_SESSION['roles'] ?? []);
    if (!$roles) $roles = [strtolower($_SESSION['role'] ?? '')];

    if (array_intersect($roles, SHIFT_EXEMPT_ROLES)) return $out;

    try {
        $pdo = get_db();

        $emp = $pdo->prepare(
            "SELECT id FROM employees WHERE user_id = :u AND status = 'active' LIMIT 1"
        );
        $emp->execute([':u' => (int)$_SESSION['user_id']]);
        $employee_id = $emp->fetchColumn();

        if (!$employee_id) {
            // No employee profile = cannot clock in. Don't lock them out
            // of the whole app over a data gap — surface it instead.
            return ['gated' => true, 'open' => false, 'employee_id' => null,
                    'reason' => 'no_employee', 'time_in' => null];
        }

        $att = $pdo->prepare(
            "SELECT time_in FROM attendance
              WHERE employee_id = :e
                AND attendance_date = CURDATE()
                AND time_in  IS NOT NULL
                AND time_out IS NULL
                AND status IN ('present','late','half_day')
              LIMIT 1"
        );
        $att->execute([':e' => (int)$employee_id]);
        $time_in = $att->fetchColumn();

        return [
            'gated'       => true,
            'open'        => (bool)$time_in,
            'employee_id' => (int)$employee_id,
            'reason'      => $time_in ? '' : 'not_clocked_in',
            'time_in'     => $time_in ?: null,
        ];
    } catch (Throwable $e) {
        error_log('shift_state failed: ' . $e->getMessage());
        // Fail open on infrastructure errors — never strand staff mid-rush.
        return ['gated' => false, 'open' => true, 'employee_id' => null,
                'reason' => 'error', 'time_in' => null];
    }
}

/** True when the user may perform operational actions right now. */
function has_active_shift(): bool {
    $s = shift_state();
    return !$s['gated'] || $s['open'];
}

/** Hard block for JSON endpoints. Call before any mutation. */
function require_shift_for_api(): void {
    if (has_active_shift()) return;

    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => false,
        'success' => false,
        'error'   => 'Active shift required. You must clock in before performing any system actions.',
        'code'    => 'SHIFT_REQUIRED',
    ]);
    exit;
}

/**
 * Render the non-dismissible full-screen gate.
 * Call AFTER the sidebar include so Tailwind (css/index.css) is loaded.
 * No-op when the user has an open shift.
 */
function render_shift_gate(): void {
    $state = shift_state();
    if (!$state['gated'] || $state['open']) return;

    $no_employee = $state['reason'] === 'no_employee';
    ?>
    <div id="shift-gate"
         class="fixed inset-0 z-[9999] flex items-center justify-center p-5
                bg-[rgba(28,17,8,0.82)] backdrop-blur-sm"
         role="alertdialog" aria-modal="true" aria-labelledby="shift-gate-title">

      <div class="w-full max-w-[420px] rounded-2xl bg-white shadow-2xl overflow-hidden
                  border border-[var(--latte,#efe0cc)]">

        <div class="px-6 pt-7 pb-5 text-center
                    bg-[linear-gradient(150deg,var(--caramel,#c47d3e)_0%,var(--espresso-deep,#1c1108)_150%)]">
          <div class="mx-auto w-14 h-14 rounded-full bg-[rgba(251,243,233,0.15)]
                      flex items-center justify-center mb-3">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fbf3e9"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>
            </svg>
          </div>
          <h2 id="shift-gate-title" class="font-['Playfair_Display',serif] text-[20px] font-bold text-[#fbf3e9]">
            Active Shift Required
          </h2>
        </div>

        <div class="px-6 py-6">
          <?php if ($no_employee): ?>
            <p class="text-[13px] leading-5 text-[var(--text-main,#2b2130)] text-center">
              Your account isn't linked to an employee profile yet, so a shift can't be
              started. Ask HR or an administrator to link it, then reload this page.
            </p>
            <div class="mt-5 flex gap-2">
              <a href="../auth/logout.php"
                 class="flex-1 text-center py-2.5 rounded-lg text-[13px] font-semibold
                        border border-[var(--latte,#efe0cc)] text-[var(--text-muted,#8b7c88)]
                        hover:bg-[var(--accent-lt,#fcefe1)]">Log out</a>
              <button type="button" onclick="location.reload()"
                 class="flex-1 py-2.5 rounded-lg text-[13px] font-semibold text-white
                        bg-[var(--caramel,#c47d3e)] hover:opacity-90">Reload</button>
            </div>
          <?php else: ?>
            <p class="text-[13px] leading-5 text-[var(--text-main,#2b2130)] text-center">
              You must clock in before performing any system actions. Your shift start
              time will be recorded now.
            </p>

            <p id="shift-gate-error"
               class="hidden mt-4 text-[12px] leading-4 text-red-600 bg-red-50 border border-red-200
                      rounded-lg px-3 py-2 text-center"></p>

            <button type="button" id="shift-gate-btn" onclick="shiftGateClockIn()"
                    class="mt-5 w-full py-3 rounded-lg text-[14px] font-bold text-white
                           bg-[var(--caramel,#c47d3e)] hover:opacity-90 disabled:opacity-60
                           disabled:cursor-not-allowed transition">
              Clock In Now
            </button>

            <div class="mt-3 flex gap-2">
              <a href="employee_dashboard.php"
                 class="flex-1 text-center py-2 rounded-lg text-[12px] font-semibold
                        border border-[var(--latte,#efe0cc)] text-[var(--text-muted,#8b7c88)]
                        hover:bg-[var(--accent-lt,#fcefe1)]">Clock in with photo</a>
              <a href="../auth/logout.php"
                 class="flex-1 text-center py-2 rounded-lg text-[12px] font-semibold
                        border border-[var(--latte,#efe0cc)] text-[var(--text-muted,#8b7c88)]
                        hover:bg-[var(--accent-lt,#fcefe1)]">Log out</a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <script>
    // Non-dismissible: no backdrop click, no Escape, no scroll behind it.
    document.documentElement.style.overflow = 'hidden';
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && document.getElementById('shift-gate')) {
        e.stopImmediatePropagation();
        e.preventDefault();
      }
    }, true);

    async function shiftGateClockIn() {
      const btn = document.getElementById('shift-gate-btn');
      const err = document.getElementById('shift-gate-error');
      btn.disabled = true;
      btn.textContent = 'Clocking in…';
      err.classList.add('hidden');

      try {
        const res  = await fetch('../api/shift_clock.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'clock_in' })
        });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.error || 'Clock-in failed.');
        location.reload();
      } catch (e) {
        err.textContent = e.message;
        err.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Clock In Now';
      }
    }
    </script>
    <?php
}