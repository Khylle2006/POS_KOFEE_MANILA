// ─────────────────────────────────────────────
//  manage_users.js
//  Add/Edit staff modal + status-change confirm modal
// ─────────────────────────────────────────────

function togglePw(id, btn) {
  const inp = document.getElementById(id);
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.textContent = inp.type === 'password' ? '👁️' : '🙈';
}

function toggleSection(kind) {
  const on = document.getElementById('f-want-' + kind).checked;
  document.getElementById(kind + '-fields').style.display = on ? '' : 'none';
}

function resetModal() {
  document.getElementById('staff-form').reset();
  document.getElementById('staff-form-error').style.display = 'none';
  document.getElementById('f-emp-id').value  = '';
  document.getElementById('f-user-id').value = '';
  document.getElementById('f-want-account').checked  = true;
  document.getElementById('f-want-employee').checked = true;
  document.getElementById('f-want-account').disabled  = false;
  document.getElementById('f-want-employee').disabled = false;
  document.querySelectorAll('#f-roles input[type=checkbox]').forEach(cb => cb.checked = false);
  toggleSection('account');
  toggleSection('employee');
  document.getElementById('code-field').style.display = '';
  document.getElementById('pw-hint').textContent = '';
}

function openAdd() {
  resetModal();
  document.getElementById('modal-title').textContent = '➕ Add Staff Member';
  document.getElementById('save-btn').textContent     = '➕ Add Staff Member';
  document.getElementById('staff-modal').classList.add('open');
}

function openEdit(r) {
  resetModal();
  document.getElementById('modal-title').textContent = '✏️ Edit Staff Member';
  document.getElementById('save-btn').textContent     = '💾 Save Changes';

  document.getElementById('f-emp-id').value  = r.emp_id  || '';
  document.getElementById('f-user-id').value = r.user_id || '';
  document.getElementById('f-fn').value      = r.fn;
  document.getElementById('f-ln').value      = r.ln;
  document.getElementById('f-email').value   = r.display_email || '';

  // Account section
  const hasAccount = !!r.user_id;
  document.getElementById('f-want-account').checked = hasAccount;
  if (hasAccount) {
    document.getElementById('f-username').value = r.username || '';
    document.getElementById('pw-hint').textContent = '— leave blank to keep current';
    // Already has an account: don't let them uncheck it away here
    document.getElementById('f-want-account').disabled = true;

    // Check every role box this account currently holds
    const currentRoles = (r.role_array || []);
    document.querySelectorAll('#f-roles input[type=checkbox]').forEach(cb => {
      cb.checked = currentRoles.includes(cb.value);
    });
  }
  toggleSection('account');

  // Employee section
  const hasProfile = !!r.emp_id;
  document.getElementById('f-want-employee').checked = hasProfile;
  if (hasProfile) {
    document.getElementById('f-code').value       = r.employee_code || '';
    document.getElementById('f-pos').value        = r.position || '';
    document.getElementById('f-department').value = r.department || 'crew';
    document.getElementById('f-etype').value       = r.employment_type || 'Full-time';
    document.getElementById('f-phone').value       = r.contact_number || '';
    document.getElementById('f-hire').value        = r.hire_date || '';
    document.getElementById('f-salary').value      = r.base_salary || '';
    document.getElementById('code-field').style.display = 'none'; // code is immutable once set
    document.getElementById('f-want-employee').disabled = true;
  }
  toggleSection('employee');

  document.getElementById('staff-modal').classList.add('open');
}

function closeModal() { document.getElementById('staff-modal').classList.remove('open'); }
function closeModalBg(e) { if (e.target === e.currentTarget) closeModal(); }

// ── Save (Add/Edit) — AJAX so errors show inline without wiping the form ──
document.getElementById('staff-form').addEventListener('submit', function (e) {
  e.preventDefault();

  const errBox = document.getElementById('staff-form-error');
  errBox.style.display = 'none';

  const wantAccount = document.getElementById('f-want-account').checked;
  const anyRole = [...document.querySelectorAll('#f-roles input[type=checkbox]')].some(cb => cb.checked);
  if (wantAccount && !anyRole) {
    errBox.textContent = 'Select at least one role for the login account.';
    errBox.style.display = 'block';
    return;
  }

  const form = e.target;
  const fd   = new FormData(form);
  const saveBtn = document.getElementById('save-btn');
  const originalLabel = saveBtn.textContent;
  saveBtn.disabled = true;
  saveBtn.textContent = 'Saving…';

  fetch(window.location.pathname + window.location.search, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: fd
  })
    .then(r => r.json())
    .then(res => {
      saveBtn.disabled = false;
      saveBtn.textContent = originalLabel;

      if (res.ok) {
        closeModal();
        location.reload(); // simplest way to reflect the new/updated row
      } else {
        errBox.textContent = res.message || 'Something went wrong. Please check your entries.';
        errBox.style.display = 'block';
      }
    })
    .catch(() => {
      saveBtn.disabled = false;
      saveBtn.textContent = originalLabel;
      errBox.textContent = '⚠️ Network error — nothing was saved.';
      errBox.style.display = 'block';
    });
});

// ── Status change confirm modal (Activate / Block / Deactivate / Reactivate) ──
const STATUS_CONFIG = {
  set_account_status: {
    active:  { icon: '✅', title: 'Activate Account?', msg: n => `"${n}"'s login account will be reactivated and they can sign in again.`, cls: '' },
    blocked: { icon: '🚫', title: 'Block Account?',    msg: n => `"${n}" will be blocked and cannot sign in until reactivated.`, cls: 'background:var(--red)' },
  },
  set_employee_status: {
    active:   { icon: '✅', title: 'Reactivate Employee?', msg: n => `"${n}" will be marked active again.`, cls: '' },
    inactive: { icon: '⏸️', title: 'Deactivate Employee?', msg: n => `"${n}" will be marked inactive.`, cls: 'background:var(--amber)' },
  }
};

function askStatusConfirm(action, id, status, name, idKind) {
  const cfg = STATUS_CONFIG[action] && STATUS_CONFIG[action][status];
  if (!cfg) return;

  document.getElementById('sc-icon').textContent    = cfg.icon;
  document.getElementById('sc-title').textContent   = cfg.title;
  document.getElementById('sc-message').textContent = cfg.msg(name);
  document.getElementById('sc-confirm-btn').setAttribute('style', cfg.cls);

  document.getElementById('sc-action').value  = action;
  document.getElementById('sc-status').value  = status;
  document.getElementById('sc-user-id').value = idKind === 'account'  ? id : '';
  document.getElementById('sc-emp-id').value  = idKind === 'employee' ? id : '';

  document.getElementById('status-confirm-modal').classList.add('open');
}

function closeStatusConfirm() {
  document.getElementById('status-confirm-modal').classList.remove('open');
}

// ── Global escape / backdrop handling ──
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeModal(); closeStatusConfirm(); }
});