<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('menu.manage');
include("../api/add_item.php");

// Fetch active ingredients for progressive recipe composition
$ingredients = $pdo->query("
    SELECT id, name, unit, quantity
    FROM ingredients
    WHERE archived_at IS NULL
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$existingProductNames = array_values(array_filter(array_map(fn($p) => strtolower(trim($p['name'])), $products)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Menu Manager — Kofee POS</title>
  
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            espresso: {
              DEFAULT: '#150E1B',
              surface: '#1E1426',
              card: '#271A31',
              border: '#382546',
            },
            caramel: {
              DEFAULT: '#C97B3D',
              light: '#EAA869',
              dark: '#A65F29',
            },
            cream: '#FAF5EE',
          }
        }
      }
    }
  </script>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <script src="../assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-menu-manager" class="page active">
  <div class="page-header">
    <div>
      <h1>Menu Manager</h1>
      <p><?= $view === 'archived' ? 'Review archived products' : 'Edit and manage your drink menu' ?></p>

    
      
    </div>
     <?php if ($view === 'active'): ?>
     <button class="btn-msave" onclick="openAdd()">➕ Add Item</button>
     <?php endif; ?>
  </div>

  <div class="page-body">

    <div class="view-tabs" style="padding:14px 32px 0;display:flex;gap:8px">
      <a class="filter-pill <?= $view === 'active' ? 'active' : '' ?>" href="add_item.php">📦 Active</a>
      <a class="filter-pill <?= $view === 'archived' ? 'active' : '' ?>" href="add_item.php?view=archived">🗄 Archived <?= $archived_count ? '(' . $archived_count . ')' : '' ?></a>
    </div>

    <div class="filter-bar" style="align-items:center">
      <input class="filter-input" type="text" id="search-products"
             placeholder="🔍 Search by item name…" oninput="applyFilters()"
             style="flex:1;min-width:220px"/>

      <select class="filter-select" id="filter-category" onchange="applyFilters()" style="width:auto">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="filter-select" id="filter-status" onchange="applyFilters()" style="width:auto">
        <option value="">All Statuses</option>
        <option value="available">Available</option>
        <option value="unavailable">Unavailable</option>
      </select>

      <span id="record-count" style="font-size:12px;color:var(--text-muted);white-space:nowrap;margin-left:auto"></span>
    </div>

    <div class="table-scroll-wrapper">
      <table>
        <thead>
          <tr>
            <th>Item</th>
            <th>Category</th>
            <th>Price</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="menu-tbody">
          <?php if (empty($products)): ?>
          <tr class="empty-row"><td colspan="5">🫙 No menu items yet — add drinks from the Inventory module first.</td></tr>
          <?php else: ?>
            <?php
            $cat_icons = ['Ice Coffee'=>'🧊','Hot Coffee'=>'☕','Milk Tea'=>'🧋','Fruit Tea'=>'🍹'];
            foreach ($products as $p):
              $available = (int)$p['stock'] > 0;
              $cat_name  = $p['category_name'] ?? '—';
              $icon      = $cat_icons[$cat_name] ?? '🥤';
              $edit_data = htmlspecialchars(json_encode([
                'id'          => $p['id'],
                'name'        => $p['name'],
                'description' => $p['description'],
                'price_small' => $p['price_small'],
                'price_large' => $p['price_large'],
                'category_id' => $p['category_id'],
              ]), ENT_QUOTES);
            ?>
            <tr class="menu-row" id="prow-<?= $p['id'] ?>"
                data-cat="<?= (int)$p['category_id'] ?>"
                data-status="<?= $available ? 'available' : 'unavailable' ?>"
                data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>">
              <td>
                <div style="display:flex;align-items:center;gap:10px">
                  <div style="width:36px;height:36px;border-radius:10px;background:var(--accent-lt);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0"><?= $icon ?></div>
                  <div>
                    <div class="prod-name" style="font-weight:600;color:var(--espresso)"><?= htmlspecialchars($p['name']) ?></div>
                    <?php if (!empty($p['description'])): ?>
                      <div style="font-size:11.5px;color:var(--text-muted)"><?= htmlspecialchars($p['description']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="muted-cell"><?= htmlspecialchars($cat_name) ?></td>
              <td class="prod-price">₱<?= number_format($p['price_small'], 2) ?> · ₱<?= number_format($p['price_large'], 2) ?></td>
              <td>
                <span class="status-badge <?= $available ? 'status-active' : 'status-blocked' ?>" id="status-<?= $p['id'] ?>">
                  <?= $available ? '✅ Available' : '❌ Unavailable' ?>
                </span>
              </td>
              <td>
                <div class="act-group">
                  <?php if ($view === 'active' && has_permission('menu.edit')): ?>
                  <button class="act-btn <?= $available ? 'act-hold' : 'act-activate' ?>"
                          id="toggle-<?= $p['id'] ?>"
                          data-state="<?= $available ? 'on' : 'off' ?>"
                          onclick="toggleAvail(<?= $p['id'] ?>, this)">
                    <?= $available ? 'Mark Unavailable' : 'Mark Available' ?>
                  </button>
                  <button class="act-btn" onclick='openEdit(<?= $edit_data ?>)'>✏️ Edit</button>
                  <?php endif; ?>
                  <?php if ($view === 'active' && has_permission('menu.delete')): ?>
                  <button class="act-btn act-block" onclick='confirmDelete(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES, 'UTF-8') ?>)'>🗑️</button>
                  <?php endif; ?>
                  <?php if ($view === 'archived' && has_permission('menu.edit')): ?>
                  <button class="act-btn act-activate" onclick='restoreProduct(<?= (int)$p['id'] ?>)'>↩️ Restore</button>
                  <?php endif; ?>
                  <?php if ($view === 'archived' && has_permission('menu.delete')): ?>
                  <button class="act-btn act-block" onclick='purgeProduct(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES, 'UTF-8') ?>)'>🗑️ Delete Forever</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<!-- Edit modal -->
<div class="modal-overlay" id="edit-modal">
  <div class="modal">
    <div class="modal-header">
      <h3>✏️ Edit Menu Item</h3>
      <button class="modal-close" onclick="closeEdit()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="e-id"/>
      <div class="field-group">
        <label class="field-label">Category</label>
        <select class="field-select" id="e-category">
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field-group">
        <label class="field-label">Drink Name</label>
        <input class="field-input" type="text" id="e-name"/>
      </div>
      <div class="field-group">
        <label class="field-label">Description</label>
        <textarea class="field-textarea" id="e-desc"></textarea>
      </div>
      <div class="field-row">
        <div class="field-group">
          <label class="field-label">Regular Price (₱)</label>
          <input class="field-input" type="number" id="e-price-small" step="0.01" min="0"/>
        </div>
        <div class="field-group">
          <label class="field-label">Up Size Price (₱)</label>
          <input class="field-input" type="number" id="e-price-large" step="0.01" min="0"/>
        </div>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeEdit()">Cancel</button>
      <button class="btn-msave" onclick="saveEdit()">
    💾 Save Changes
</button>
    </div>
  </div>
</div>

<!-- ── Progressive Multi-Step "Add Item" Modal (Tailwind CSS) ── -->
<div id="progressive-add-modal" class="fixed inset-0 z-[250] flex items-center justify-center bg-black/75 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-200">
  <div id="progressive-modal-dialog" class="bg-[#1C1224] border border-[#3A244A] rounded-3xl w-full max-w-2xl mx-4 overflow-hidden shadow-2xl transform scale-95 transition-all duration-200 flex flex-col max-h-[90vh]">
    
    <!-- Modal Header -->
    <div class="px-6 py-4 bg-[#23152E] border-b border-[#352044] flex items-center justify-between shrink-0">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-[#EAA869] to-[#C97B3D] flex items-center justify-center text-white font-bold text-lg shadow-md shadow-[#C97B3D]/25">
          ✨
        </div>
        <div>
          <h3 class="font-bold text-base text-white tracking-tight">Add Menu Item &amp; Recipe</h3>
          <p class="text-[11px] text-slate-400">Step-by-step beverage identity and recipe composition</p>
        </div>
      </div>
      <button onclick="requestCloseProgressiveModal()" class="w-8 h-8 rounded-xl bg-[#2A1A37] hover:bg-[#382449] text-slate-400 hover:text-white flex items-center justify-center text-sm transition" title="Close (Esc)">✕</button>
    </div>

    <!-- Responsive Visual Step Tracker -->
    <div class="px-6 py-3.5 bg-[#170E1E] border-b border-[#2E1C3A] shrink-0">
      <div class="flex items-center justify-between relative max-w-lg mx-auto">
        <!-- Connecting Lines -->
        <div class="absolute left-6 right-6 top-4 h-[2px] bg-[#331F41] -z-0">
          <div id="step-progress-bar" class="h-full bg-[#C97B3D] transition-all duration-300 w-0"></div>
        </div>

        <!-- Step 1 Indicator -->
        <div class="flex flex-col items-center relative z-10 cursor-pointer" onclick="goToStep(1)">
          <div id="step-ind-1" class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-[#C97B3D] text-white ring-4 ring-[#C97B3D]/25 shadow-lg transition-all">
            1
          </div>
          <span id="step-lbl-1" class="text-[11px] font-bold text-amber-200 mt-1.5 transition-colors">1. Item Identity</span>
        </div>

        <!-- Step 2 Indicator -->
        <div class="flex flex-col items-center relative z-10 cursor-pointer" onclick="goToStep(2)">
          <div id="step-ind-2" class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-[#251731] text-slate-400 border border-[#3E2850] transition-all">
            2
          </div>
          <span id="step-lbl-2" class="text-[11px] font-semibold text-slate-400 mt-1.5 transition-colors">2. Regular Recipe</span>
        </div>

        <!-- Step 3 Indicator -->
        <div class="flex flex-col items-center relative z-10 cursor-pointer" onclick="goToStep(3)">
          <div id="step-ind-3" class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-[#251731] text-slate-400 border border-[#3E2850] transition-all">
            3
          </div>
          <span id="step-lbl-3" class="text-[11px] font-semibold text-slate-400 mt-1.5 transition-colors">3. Upsize Recipe</span>
        </div>
      </div>
    </div>

    <!-- Scrollable Modal Body: Step Panels -->
    <div class="p-6 overflow-y-auto flex-1 space-y-4">

      <!-- Inline Validation Banner -->
      <div id="prog-error-banner" class="hidden p-3 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2">
        <span>⚠️</span>
        <span id="prog-error-text">Please fill in all required fields before continuing.</span>
      </div>

      <!-- ═══════════════════════════════════════════════════════════ -->
      <!-- STEP 1: ITEM IDENTITY                                       -->
      <!-- ═══════════════════════════════════════════════════════════ -->
      <div id="step-panel-1" class="step-panel space-y-4 transition-all duration-200">
        <div>
          <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">
            Drink / Item Name <span class="text-rose-400">*</span>
          </label>
          <input type="text" id="prog-name" placeholder="e.g. Spanish Iced Latte" oninput="validateStep1Live()"
                 class="w-full px-3.5 py-2.5 text-xs bg-[#24172E] border border-[#3A244A] focus:border-[#C97B3D] rounded-xl text-white placeholder-slate-500 focus:outline-none transition"/>
          <span id="prog-name-err" class="text-[11px] text-rose-400 mt-1 hidden block"></span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">
              Category <span class="text-rose-400">*</span>
            </label>
            <select id="prog-category" onchange="validateStep1Live()"
                    class="w-full px-3.5 py-2.5 text-xs bg-[#24172E] border border-[#3A244A] focus:border-[#C97B3D] rounded-xl text-white focus:outline-none transition">
              <option value="">Select a Category…</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">
              Quick Icon / Emoji
            </label>
            <div class="flex items-center gap-1.5 flex-wrap" id="icon-picker">
              <?php foreach (['☕','🧊','🧋','🍵','🥤','🥐','🍰','🍹'] as $emoji): ?>
              <button type="button" onclick="selectDrinkEmoji('<?= $emoji ?>', this)"
                      class="emoji-btn w-9 h-9 rounded-xl bg-[#24172E] border border-[#3A244A] hover:border-[#C97B3D] text-base flex items-center justify-center transition <?= $emoji === '☕' ? 'border-[#C97B3D] bg-[#331F40]' : '' ?>">
                <?= $emoji ?>
              </button>
              <?php endforeach; ?>
              <input type="hidden" id="prog-icon" value="☕"/>
            </div>
          </div>
        </div>

        <div>
          <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">
            Description &amp; Notes
          </label>
          <textarea id="prog-desc" rows="3" placeholder="Describe the drink, roast profile, or preparation notes…"
                    class="w-full px-3.5 py-2.5 text-xs bg-[#24172E] border border-[#3A244A] focus:border-[#C97B3D] rounded-xl text-white placeholder-slate-500 focus:outline-none transition"></textarea>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════ -->
      <!-- STEP 2: REGULAR SIZE & RECIPE                               -->
      <!-- ═══════════════════════════════════════════════════════════ -->
      <div id="step-panel-2" class="step-panel hidden space-y-4 transition-all duration-200">
        <div class="bg-[#24172E] border border-[#3A244A] p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <span class="text-xs font-bold text-white block">Regular Size (12oz) Base Price</span>
            <span class="text-[11px] text-slate-400">Customer retail ring-up price at counter</span>
          </div>
          <div class="relative w-full sm:w-44">
            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-amber-300 font-bold text-xs pointer-events-none">₱</span>
            <input type="number" step="0.01" min="0.01" id="prog-price-small" placeholder="0.00" oninput="validateStep2Live()"
                   class="w-full pl-7 pr-3 py-2 text-xs bg-[#1A1022] border border-[#482D5C] focus:border-[#C97B3D] rounded-xl text-amber-200 font-mono font-bold focus:outline-none transition"/>
          </div>
        </div>

        <div class="border-t border-[#2E1C3A] pt-3">
          <div class="flex items-center justify-between mb-2">
            <div>
              <h4 class="text-xs font-bold text-white">Regular Recipe Composition</h4>
              <p class="text-[10.5px] text-slate-400">Ingredients automatically deducted when Regular size is ordered</p>
            </div>
            <button type="button" onclick="addRecipeRow('small')" class="px-2.5 py-1.5 rounded-lg bg-[#2E1A3D] hover:bg-[#3D2352] text-[#EAA869] text-[11px] font-bold border border-[#4E2B6A] transition flex items-center gap-1">
              <span>➕</span><span>Add Ingredient</span>
            </button>
          </div>

          <div id="prog-recipe-small-rows" class="space-y-2">
            <!-- Dynamic Recipe Ingredient Rows inserted here -->
          </div>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════ -->
      <!-- STEP 3: UPSIZE & RECIPE                                     -->
      <!-- ═══════════════════════════════════════════════════════════ -->
      <div id="step-panel-3" class="step-panel hidden space-y-4 transition-all duration-200">
        <div class="bg-[#24172E] border border-[#3A244A] p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <span class="text-xs font-bold text-white block">Upsize / Large (16oz - 22oz) Base Price</span>
            <span class="text-[11px] text-slate-400">Retail price when customer requests an upsized drink</span>
          </div>
          <div class="relative w-full sm:w-44">
            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-amber-300 font-bold text-xs pointer-events-none">₱</span>
            <input type="number" step="0.01" min="0.01" id="prog-price-large" placeholder="0.00" oninput="validateStep3Live()"
                   class="w-full pl-7 pr-3 py-2 text-xs bg-[#1A1022] border border-[#482D5C] focus:border-[#C97B3D] rounded-xl text-amber-200 font-mono font-bold focus:outline-none transition"/>
          </div>
        </div>

        <div class="border-t border-[#2E1C3A] pt-3">
          <div class="flex items-center justify-between mb-2">
            <div>
              <h4 class="text-xs font-bold text-white">Upsize Recipe Composition</h4>
              <p class="text-[10.5px] text-slate-400">Ingredients deducted when Upsize is ordered</p>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" onclick="copyRecipeFromRegular()" class="px-2.5 py-1.5 rounded-lg bg-[#C97B3D]/15 hover:bg-[#C97B3D]/25 text-[#EAA869] text-[11px] font-bold border border-[#C97B3D]/30 transition flex items-center gap-1 shadow-sm">
                <span>⚡</span><span>Copy from Regular</span>
              </button>
              <button type="button" onclick="addRecipeRow('large')" class="px-2.5 py-1.5 rounded-lg bg-[#2E1A3D] hover:bg-[#3D2352] text-slate-300 hover:text-white text-[11px] font-bold border border-[#4E2B6A] transition flex items-center gap-1">
                <span>➕</span><span>Add</span>
              </button>
            </div>
          </div>

          <div id="prog-recipe-large-rows" class="space-y-2">
            <!-- Dynamic Recipe Ingredient Rows inserted here -->
          </div>
        </div>
      </div>

    </div>

    <!-- Modal Footer & Step Navigation Controls -->
    <div class="px-6 py-4 bg-[#23152E] border-t border-[#352044] flex items-center justify-between shrink-0">
      <button type="button" onclick="requestCloseProgressiveModal()" class="px-4 py-2 rounded-xl bg-[#2A1A37] hover:bg-[#372347] text-slate-400 hover:text-white text-xs font-semibold transition">
        Cancel
      </button>

      <div class="flex items-center gap-2.5">
        <button type="button" id="prog-btn-back" onclick="prevStep()" class="hidden px-4 py-2 rounded-xl bg-[#2A1A37] hover:bg-[#372347] text-slate-200 text-xs font-bold transition flex items-center gap-1.5">
          <span>←</span><span>Back</span>
        </button>

        <button type="button" id="prog-btn-next" onclick="nextStep()" class="px-5 py-2 rounded-xl bg-[#C97B3D] hover:bg-[#D98B4D] text-white text-xs font-bold transition shadow-md shadow-[#C97B3D]/25 flex items-center gap-1.5">
          <span>Next</span><span>→</span>
        </button>

        <button type="button" id="prog-btn-save" onclick="submitProgressiveItem()" class="hidden px-5 py-2 rounded-xl bg-gradient-to-r from-[#C97B3D] to-[#A65F29] hover:from-[#D98B4D] hover:to-[#B76E36] text-white text-xs font-bold transition shadow-lg shadow-[#C97B3D]/30 flex items-center gap-1.5">
          <span id="save-spinner" class="hidden animate-spin text-xs">⏳</span>
          <span id="save-label">💾 Save Item</span>
        </button>
      </div>
    </div>

  </div>
</div>


<!-- Availability confirm modal -->
<div class="modal-overlay" id="avail-modal">
  <div class="modal" style="max-width:360px">
    <div class="modal-body" style="text-align:center">
      <div style="font-size:46px;margin-bottom:12px" id="avail-icon">❓</div>
      <h3 style="font-size:17px;margin-bottom:8px" id="avail-title">Change Availability?</h3>
      <p id="avail-msg" style="font-size:13px;color:var(--text-muted)"></p>
    </div>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeAvail()">Cancel</button>
      <button class="btn-msave" id="avail-confirm-btn" onclick="doToggleAvail()">Yes, Confirm</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast" style="display:none"></div>

<script>

// ══════════════════════════════════════════════════════════════
// PROGRESSIVE MULTI-STEP "ADD ITEM" MODAL CONTROLLER
// ══════════════════════════════════════════════════════════════
window.AVAILABLE_INGREDIENTS = <?= json_encode($ingredients) ?>;
window.EXISTING_PRODUCT_NAMES = <?= json_encode($existingProductNames) ?>;

let currentStep = 1;
let isDirty = false;

function openProgressiveAddModal() {
  currentStep = 1;
  isDirty = false;

  // Reset Step 1 fields
  document.getElementById('prog-name').value = '';
  document.getElementById('prog-category').value = '';
  document.getElementById('prog-desc').value = '';
  document.getElementById('prog-icon').value = '☕';
  document.getElementById('prog-name-err').classList.add('hidden');
  document.querySelectorAll('#icon-picker .emoji-btn').forEach((b, i) => {
    b.classList.toggle('border-[#C97B3D]', i === 0);
    b.classList.toggle('bg-[#331F40]', i === 0);
  });

  // Reset Step 2 fields
  document.getElementById('prog-price-small').value = '';
  document.getElementById('prog-recipe-small-rows').innerHTML = '';
  addRecipeRow('small'); // add 1 blank row by default

  // Reset Step 3 fields
  document.getElementById('prog-price-large').value = '';
  document.getElementById('prog-recipe-large-rows').innerHTML = '';
  addRecipeRow('large'); // add 1 blank row by default

  hideProgError();
  goToStep(1);

  const modal = document.getElementById('progressive-add-modal');
  modal.classList.remove('opacity-0', 'pointer-events-none');
  modal.querySelector('#progressive-modal-dialog').classList.remove('scale-95');
  document.getElementById('prog-name').focus();
}

// Fallback for any legacy onclick="openAdd()"
function openAdd() {
  openProgressiveAddModal();
}
function closeAdd() {
  closeProgressiveAddModal();
}

function closeProgressiveAddModal() {
  const modal = document.getElementById('progressive-add-modal');
  modal.classList.add('opacity-0', 'pointer-events-none');
  modal.querySelector('#progressive-modal-dialog').classList.add('scale-95');
  isDirty = false;
}

function requestCloseProgressiveModal() {
  if (isFormDirty()) {
    if (confirm("You have unsaved changes in this wizard. Are you sure you want to exit?")) {
      closeProgressiveAddModal();
    }
  } else {
    closeProgressiveAddModal();
  }
}

function isFormDirty() {
  const name = document.getElementById('prog-name')?.value.trim();
  const priceSmall = document.getElementById('prog-price-small')?.value.trim();
  const priceLarge = document.getElementById('prog-price-large')?.value.trim();
  return isDirty || Boolean(name || priceSmall || priceLarge);
}

function selectDrinkEmoji(emoji, btn) {
  document.getElementById('prog-icon').value = emoji;
  document.querySelectorAll('#icon-picker .emoji-btn').forEach(b => {
    b.classList.remove('border-[#C97B3D]', 'bg-[#331F40]');
  });
  btn.classList.add('border-[#C97B3D]', 'bg-[#331F40]');
  isDirty = true;
}

function showProgError(msg) {
  const banner = document.getElementById('prog-error-banner');
  const text = document.getElementById('prog-error-text');
  text.textContent = msg;
  banner.classList.remove('hidden');
}

function hideProgError() {
  document.getElementById('prog-error-banner').classList.add('hidden');
}

// ── Step Navigation & Indicators ─────────────────────────────────
function goToStep(step) {
  if (step < 1 || step > 3) return;

  // If advancing forward, validate prior steps
  if (step > currentStep) {
    for (let s = currentStep; s < step; s++) {
      if (!validateStep(s)) return;
    }
  }

  currentStep = step;
  hideProgError();

  // Switch step panels
  for (let s = 1; s <= 3; s++) {
    const panel = document.getElementById(`step-panel-${s}`);
    if (panel) {
      panel.classList.toggle('hidden', s !== currentStep);
    }
  }

  // Update indicators & progress bar
  const progressBar = document.getElementById('step-progress-bar');
  if (currentStep === 1) progressBar.style.width = '0%';
  if (currentStep === 2) progressBar.style.width = '50%';
  if (currentStep === 3) progressBar.style.width = '100%';

  for (let s = 1; s <= 3; s++) {
    const ind = document.getElementById(`step-ind-${s}`);
    const lbl = document.getElementById(`step-lbl-${s}`);

    if (s < currentStep) {
      // Completed
      ind.className = 'w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-emerald-600 text-white shadow-md transition-all';
      ind.textContent = '✓';
      lbl.className = 'text-[11px] font-bold text-emerald-400 mt-1.5 transition-colors';
    } else if (s === currentStep) {
      // Active
      ind.className = 'w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-[#C97B3D] text-white ring-4 ring-[#C97B3D]/25 shadow-lg transition-all';
      ind.textContent = s;
      lbl.className = 'text-[11px] font-bold text-amber-200 mt-1.5 transition-colors';
    } else {
      // Inactive
      ind.className = 'w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-[#251731] text-slate-400 border border-[#3E2850] transition-all';
      ind.textContent = s;
      lbl.className = 'text-[11px] font-semibold text-slate-400 mt-1.5 transition-colors';
    }
  }

  // Toggle footer action buttons
  const backBtn = document.getElementById('prog-btn-back');
  const nextBtn = document.getElementById('prog-btn-next');
  const saveBtn = document.getElementById('prog-btn-save');

  backBtn.classList.toggle('hidden', currentStep === 1);
  nextBtn.classList.toggle('hidden', currentStep === 3);
  saveBtn.classList.toggle('hidden', currentStep !== 3);
}

function nextStep() {
  if (validateStep(currentStep)) {
    goToStep(currentStep + 1);
  }
}

function prevStep() {
  goToStep(currentStep - 1);
}

// ── Validation Rules ─────────────────────────────────────────────
function validateStep(step) {
  if (step === 1) {
    const name = document.getElementById('prog-name').value.trim();
    const cat = document.getElementById('prog-category').value;
    const nameErr = document.getElementById('prog-name-err');

    if (!name || name.length < 2) {
      nameErr.textContent = 'Drink name is required and must be at least 2 characters.';
      nameErr.classList.remove('hidden');
      showProgError('Please provide a valid item name (minimum 2 characters).');
      document.getElementById('prog-name').focus();
      return false;
    }

    if (window.EXISTING_PRODUCT_NAMES && window.EXISTING_PRODUCT_NAMES.includes(name.toLowerCase())) {
      nameErr.textContent = `A menu item named "${name}" already exists.`;
      nameErr.classList.remove('hidden');
      showProgError(`Duplicate item name: "${name}" already exists on the menu.`);
      document.getElementById('prog-name').focus();
      return false;
    }
    nameErr.classList.add('hidden');

    if (!cat) {
      showProgError('Please select a drink category from the dropdown.');
      document.getElementById('prog-category').focus();
      return false;
    }
    return true;
  }

  if (step === 2) {
    const price = parseFloat(document.getElementById('prog-price-small').value);
    if (isNaN(price) || price <= 0) {
      showProgError('Please enter a valid base price for Regular size (e.g. ₱85.00).');
      document.getElementById('prog-price-small').focus();
      return false;
    }
    return true;
  }

  if (step === 3) {
    const price = parseFloat(document.getElementById('prog-price-large').value);
    if (isNaN(price) || price <= 0) {
      showProgError('Please enter a valid base price for Upsize (e.g. ₱110.00).');
      document.getElementById('prog-price-large').focus();
      return false;
    }
    return true;
  }

  return true;
}

function validateStep1Live() {
  isDirty = true;
  const name = document.getElementById('prog-name').value.trim();
  const nameErr = document.getElementById('prog-name-err');
  if (name.length >= 2) {
    if (window.EXISTING_PRODUCT_NAMES && window.EXISTING_PRODUCT_NAMES.includes(name.toLowerCase())) {
      nameErr.textContent = `A menu item named "${name}" already exists.`;
      nameErr.classList.remove('hidden');
    } else {
      nameErr.classList.add('hidden');
      hideProgError();
    }
  }
}

function validateStep2Live() {
  isDirty = true;
  const price = parseFloat(document.getElementById('prog-price-small').value);
  if (price > 0) hideProgError();
}

function validateStep3Live() {
  isDirty = true;
  const price = parseFloat(document.getElementById('prog-price-large').value);
  if (price > 0) hideProgError();
}

// ── Dynamic Recipe Row Composition ──────────────────────────────
function addRecipeRow(size, defaultIngId = '', defaultQty = '', defaultUnit = '') {
  isDirty = true;
  const container = document.getElementById(`prog-recipe-${size}-rows`);
  if (!container) return;

  const row = document.createElement('div');
  row.className = 'recipe-row flex items-center gap-2 p-2.5 bg-[#1F1327] border border-[#3A244A] rounded-xl text-xs transition';

  let optionsHtml = '<option value="">Select ingredient…</option>';
  (window.AVAILABLE_INGREDIENTS || []).forEach(ing => {
    const isSelected = String(ing.id) === String(defaultIngId) ? 'selected' : '';
    optionsHtml += `<option value="${ing.id}" data-unit="${ing.unit || 'pcs'}" ${isSelected}>${escapeHtml(ing.name)} (${ing.unit || 'pcs'})</option>`;
  });

  row.innerHTML = `
    <select class="recipe-ing-select flex-1 px-3 py-1.5 bg-[#160D1D] border border-[#3F2850] focus:border-[#C97B3D] rounded-lg text-slate-100 text-xs focus:outline-none" onchange="onRecipeIngChange(this)">
      ${optionsHtml}
    </select>
    <div class="relative w-24 shrink-0">
      <input type="number" step="0.01" min="0.01" placeholder="Qty" value="${defaultQty}"
             class="recipe-qty-input w-full px-2.5 py-1.5 bg-[#160D1D] border border-[#3F2850] focus:border-[#C97B3D] rounded-lg text-white font-mono text-xs focus:outline-none" oninput="isDirty=true"/>
    </div>
    <span class="recipe-unit-badge w-12 text-center text-[11px] font-mono font-bold text-[#EAA869] bg-[#2E1C3A] py-1.5 rounded-lg border border-[#442858]">
      ${defaultUnit || 'unit'}
    </span>
    <button type="button" onclick="removeRecipeRow(this)" class="w-8 h-8 rounded-lg bg-rose-500/10 hover:bg-rose-500/25 text-rose-300 flex items-center justify-center text-sm transition" title="Remove ingredient">
      🗑️
    </button>
  `;

  container.appendChild(row);
  const selectEl = row.querySelector('.recipe-ing-select');
  if (selectEl.value) {
    onRecipeIngChange(selectEl);
  }
}

function removeRecipeRow(btn) {
  isDirty = true;
  const row = btn.closest('.recipe-row');
  if (row) row.remove();
}

function onRecipeIngChange(selectEl) {
  isDirty = true;
  const selectedOpt = selectEl.options[selectEl.selectedIndex];
  const unit = selectedOpt ? selectedOpt.dataset.unit : 'unit';
  const row = selectEl.closest('.recipe-row');
  if (row) {
    const badge = row.querySelector('.recipe-unit-badge');
    if (badge) badge.textContent = unit || 'unit';
  }
}

function collectRecipeRows(size) {
  const container = document.getElementById(`prog-recipe-${size}-rows`);
  if (!container) return [];

  const rows = container.querySelectorAll('.recipe-row');
  const list = [];
  rows.forEach(r => {
    const sel = r.querySelector('.recipe-ing-select');
    const inp = r.querySelector('.recipe-qty-input');
    const ingId = parseInt(sel?.value || '0', 10);
    const qty = parseFloat(inp?.value || '0');
    const unit = r.querySelector('.recipe-unit-badge')?.textContent.trim() || 'unit';

    if (ingId > 0 && qty > 0) {
      list.push({ ingredient_id: ingId, qty_used: qty, unit: unit });
    }
  });
  return list;
}

// ── Quick-Action: Clone Recipe from Regular to Upsize ────────────
function copyRecipeFromRegular() {
  const smallRows = collectRecipeRows('small');
  if (!smallRows.length) {
    showToast('⚠️ No ingredients found in Regular size to copy.', 'error');
    return;
  }

  const largeContainer = document.getElementById('prog-recipe-large-rows');
  largeContainer.innerHTML = '';

  smallRows.forEach(item => {
    const upsizedQty = parseFloat((item.qty_used * 1.25).toFixed(2));
    addRecipeRow('large', item.ingredient_id, upsizedQty, item.unit);
  });

  const regPrice = parseFloat(document.getElementById('prog-price-small').value);
  const upPriceInput = document.getElementById('prog-price-large');
  if (regPrice > 0 && (!upPriceInput.value || parseFloat(upPriceInput.value) <= 0)) {
    upPriceInput.value = (regPrice + 20).toFixed(2);
  }

  showToast('⚡ Recipe copied from Regular (scaled ~1.25x)!', 'success');
}

// ── Submit Progressive Item Payload to Backend ───────────────────
function submitProgressiveItem() {
  if (!validateStep(1) || !validateStep(2) || !validateStep(3)) {
    return;
  }

  const payload = {
    name: document.getElementById('prog-name').value.trim(),
    category_id: parseInt(document.getElementById('prog-category').value, 10),
    description: document.getElementById('prog-desc').value.trim(),
    price_small: parseFloat(document.getElementById('prog-price-small').value),
    price_large: parseFloat(document.getElementById('prog-price-large').value),
    recipe_small: collectRecipeRows('small'),
    recipe_large: collectRecipeRows('large')
  };

  const saveBtn = document.getElementById('prog-btn-save');
  const spinner = document.getElementById('save-spinner');
  const label   = document.getElementById('save-label');

  saveBtn.disabled = true;
  spinner.classList.remove('hidden');
  label.textContent = 'Saving Item & Recipes…';

  fetch('../api/save_item.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(r => {
    if (!r.ok) {
      return r.json().then(errData => { throw new Error(errData.error || `Error ${r.status}`); });
    }
    return r.json();
  })
  .then(res => {
    saveBtn.disabled = false;
    spinner.classList.add('hidden');
    label.textContent = '💾 Save Item';

    if (res.ok) {
      closeProgressiveAddModal();
      Swal.fire({
        title: 'Item Created!',
        text: `"${payload.name}" and its size recipes have been saved.`,
        icon: 'success',
        confirmButtonColor: '#C97B3D',
        timer: 1800,
        showConfirmButton: false
      }).then(() => {
        window.location.reload();
      });
    } else {
      showProgError(res.error || 'Failed to save item.');
    }
  })
  .catch(err => {
    saveBtn.disabled = false;
    spinner.classList.add('hidden');
    label.textContent = '💾 Save Item';
    showProgError(err.message || 'Network error while saving item.');
  });
}

const SELF = window.location.pathname;

const CAT_ICONS = { 'Ice Coffee':'🧊','Hot Coffee':'☕','Milk Tea':'🧋','Fruit Tea':'🍹' };

// ── Toast ─────────────────────────────────────
let toastTimer;
function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent  = msg;
  t.className    = 'toast toast-' + type + ' show';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
}

// ── Filters (search + category + status) ───────
function applyFilters() {
  const q      = document.getElementById('search-products').value.toLowerCase().trim();
  const cat    = document.getElementById('filter-category').value;
  const status = document.getElementById('filter-status').value;

  let visible = 0;
  document.querySelectorAll('.menu-row').forEach(row => {
    const matchesName   = !q || row.dataset.name.includes(q);
    const matchesCat    = !cat || row.dataset.cat === cat;
    const matchesStatus = !status || row.dataset.status === status;
    const show = matchesName && matchesCat && matchesStatus;
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  const countEl = document.getElementById('record-count');
  if (countEl) countEl.textContent = visible + (visible === 1 ? ' item' : ' items');
}
document.addEventListener('DOMContentLoaded', applyFilters);

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

// ── Toggle availability ────────────────────────
let pendingToggle = null; // { id, btn, makingAvailable }

function toggleAvail(id, btn) {
  const makingAvailable = btn.dataset.state === 'off'; // currently off -> about to turn on
  pendingToggle = { id, btn, makingAvailable };

  const row      = document.getElementById('prow-' + id);
  const itemName = row.querySelector('.prod-name').textContent;

  document.getElementById('avail-icon').textContent  = makingAvailable ? '✅' : '❌';
  document.getElementById('avail-title').textContent = makingAvailable ? 'Mark as Available?' : 'Mark as Unavailable?';
  document.getElementById('avail-msg').textContent = makingAvailable
    ? `"${itemName}" will be visible and orderable again.`
    : `"${itemName}" will be hidden from ordering until re-enabled.`;

  document.getElementById('avail-confirm-btn').style.background = makingAvailable ? '' : 'var(--red)';
  document.getElementById('avail-modal').classList.add('open');
}
function closeAvail() {
  document.getElementById('avail-modal').classList.remove('open');
  pendingToggle = null;
}
function doToggleAvail() {
  if (!pendingToggle) return;
  const { id, btn } = pendingToggle;

  const fd = new FormData();
  fd.append('action', 'toggle');
  fd.append('id', id);

      fetch("../api/add_item.php", {
        method: "POST",
        body: fd
    })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        const row    = document.getElementById('prow-' + id);
        const status = document.getElementById('status-' + id);
        if (res.available) {
          btn.textContent      = 'Mark Unavailable';
          btn.className        = 'act-btn act-hold';
          btn.dataset.state    = 'on';
          status.className     = 'status-badge status-active';
          status.textContent   = '✅ Available';
          row.dataset.status   = 'available';
          showToast('✅ Item set to Available');
        } else {
          btn.textContent      = 'Mark Available';
          btn.className        = 'act-btn act-activate';
          btn.dataset.state    = 'off';
          status.className     = 'status-badge status-blocked';
          status.textContent   = '❌ Unavailable';
          row.dataset.status   = 'unavailable';
          showToast('❌ Item set to Unavailable', 'error');
        }
        applyFilters();
      } else {
        showToast('⚠️ ' + res.error, 'error');
      }
    })
    .catch(() => showToast('⚠️ Network error.', 'error'))
    .finally(closeAvail);
}

// ── Edit modal ────────────────────────────────
function openEdit(p) {
  document.getElementById('e-id').value          = p.id;
  document.getElementById('e-category').value    = p.category_id;
  document.getElementById('e-name').value        = p.name;
  document.getElementById('e-desc').value        = p.description || '';
  document.getElementById('e-price-small').value = p.price_small;
  document.getElementById('e-price-large').value = p.price_large;
  document.getElementById('edit-modal').classList.add('open');
}
function closeEdit() { document.getElementById('edit-modal').classList.remove('open'); }

function saveEdit() {
  const cat = document.getElementById('e-category');
  const name = document.getElementById('e-name');
  const priceSmall = document.getElementById('e-price-small');
  const priceLarge = document.getElementById('e-price-large');

  let valid = true;
  let firstInvalid = null;

  if (!name.value.trim()) {
    KofeeValidator.showError(name, 'Drink name is required.');
    valid = false;
    if (!firstInvalid) firstInvalid = name;
  } else {
    KofeeValidator.clearError(name);
  }

  if (!priceSmall.value || parseFloat(priceSmall.value) <= 0) {
    KofeeValidator.showError(priceSmall, 'Regular price must be greater than 0.');
    valid = false;
    if (!firstInvalid) firstInvalid = priceSmall;
  } else {
    KofeeValidator.clearError(priceSmall);
  }

  if (!priceLarge.value || parseFloat(priceLarge.value) <= 0) {
    KofeeValidator.showError(priceLarge, 'Up size price must be greater than 0.');
    valid = false;
    if (!firstInvalid) firstInvalid = priceLarge;
  } else {
    KofeeValidator.clearError(priceLarge);
  }

  if (!valid && firstInvalid) {
    firstInvalid.focus();
    return;
  }

  const id = document.getElementById('e-id').value;
  const btn = document.querySelector('#edit-modal .btn-msave');
  KofeeValidator.setLoading(btn, 'Saving…');

  const fd = new FormData();
  fd.append('action',      'edit');
  fd.append('id',          id);
  fd.append('category_id', cat.value);
  fd.append('name',        name.value.trim());
  fd.append('description', document.getElementById('e-desc').value);
  fd.append('price_small', priceSmall.value);
  fd.append('price_large', priceLarge.value);
  const editImage = document.getElementById('e-image').files[0];
  if (editImage) fd.append('image', editImage);

  fetch(SELF, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      KofeeValidator.resetLoading(btn, '💾 Save Changes');
      if (res.ok) {
        closeEdit();
        showToast('✅ Item updated!');
        updateRowInDOM(id, {
          name: name.value.trim(),
          description: document.getElementById('e-desc').value,
          price_small: priceSmall.value,
          price_large: priceLarge.value,
          category_id: cat.value,
        });
      } else {
        showToast('⚠️ ' + res.error, 'error');
      }
    })
    .catch(() => {
      KofeeValidator.resetLoading(btn, '💾 Save Changes');
      showToast('⚠️ Network error.', 'error');
    });
}

function updateRowInDOM(id, p) {
  const row = document.getElementById('prow-' + id);
  if (!row) return;
  const catSelect = document.getElementById('e-category');
  const catName   = catSelect.options[catSelect.selectedIndex].textContent;

  row.querySelector('.prod-name').textContent = p.name;
  row.querySelector('.prod-price').textContent =
    `₱${parseFloat(p.price_small).toFixed(2)} · ₱${parseFloat(p.price_large).toFixed(2)}`;
  row.querySelector('.muted-cell').textContent = catName;
  row.dataset.cat  = p.category_id;
  row.dataset.name = p.name.toLowerCase();

  // Keep the Edit button's stored data current for the next click
  const editBtn = row.querySelector('.act-group button:nth-child(2)');
  if (editBtn) editBtn.setAttribute('onclick', `openEdit(${JSON.stringify(p).replace(/"/g, '&quot;')})`);
}

// Delete and archive actions
async function confirmDelete(id, name) {
  const result = await Swal.fire({
    title: 'Delete Product?',
    text: `Are you sure you want to delete "${name}"?`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, Delete',
    cancelButtonText: 'Cancel',
    confirmButtonColor: 'var(--red)',
    reverseButtons: true,
  });
  if (result.isConfirmed) await submitProductAction('delete', id);
}

async function restoreProduct(id) {
  const result = await Swal.fire({
    title: 'Restore Product?',
    text: 'This product will be available in the active menu again.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Restore',
    cancelButtonText: 'Cancel',
    confirmButtonColor: 'var(--caramel)',
    reverseButtons: true,
  });
  if (result.isConfirmed) await submitProductAction('restore', id);
}

async function purgeProduct(id, name) {
  const result = await Swal.fire({
    title: 'Delete Permanently?',
    text: `Delete "${name}" forever? This cannot be undone.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Delete Forever',
    cancelButtonText: 'Cancel',
    confirmButtonColor: 'var(--red)',
    reverseButtons: true,
  });
  if (result.isConfirmed) await submitProductAction('purge', id);
}

async function submitProductAction(action, id) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('id', id);
  try {
    const response = await fetch('../api/add_item.php', { method: 'POST', body: fd });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Action failed.');
    await Swal.fire({
      title: action === 'restore' ? 'Restored' : action === 'purge' ? 'Deleted' : 'Archived',
      text: result.message,
      icon: 'success',
      confirmButtonColor: 'var(--caramel)',
    });
    location.reload();
  } catch (error) {
    await Swal.fire({ title: 'Action Failed', text: error.message, icon: 'error', confirmButtonColor: 'var(--red)' });
  }
}

document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => {
    if (e.target === el) { closeAdd(); closeEdit(); closeAvail(); closeAddConfirm(); }
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeAdd(); closeEdit(); closeAvail(); closeAddConfirm(); }
});

</script>

<script src="../js/validator.js"></script>
</body>
</html>