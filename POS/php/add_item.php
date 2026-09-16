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
              DEFAULT: '#241A2E',
              deep: '#181120',
              mid: '#2F2238',
            },
            caramel: {
              DEFAULT: '#C97B3D',
              light: '#E6A25C',
              dark: '#A65F29',
            },
            latte: '#EFE0CC',
            cream: '#FBF3E9',
            'accent-lt': '#FCEFE1',
            cafe: {
              text: '#2B2130',
              muted: '#8B7C88',
              border: '#EFE0CC',
              bg: '#FBF3E9',
              card: '#FFFFFF',
            }
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
                'image_path'  => $p['image_path'] ?? '',
              ]), ENT_QUOTES);
            ?>
            <tr class="menu-row" id="prow-<?= $p['id'] ?>"
                data-cat="<?= (int)$p['category_id'] ?>"
                data-status="<?= $available ? 'available' : 'unavailable' ?>"
                data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>">
              <td>
                <div style="display:flex;align-items:center;gap:12px">
                  <?php if (!empty($p['image_path'])): 
                    $cleanedPath = ltrim($p['image_path'], '/');
                    $rowImgSrc = str_starts_with($cleanedPath, 'assets/') ? ('../' . $cleanedPath) : ('../assets/' . $cleanedPath);
                  ?>
                    <div style="width:42px;height:42px;border-radius:10px;overflow:hidden;border:1.5px solid #D8C7B5;background:#FAF5EE;flex-shrink:0;display:flex;align-items:center;justify-content:center">
                      <img src="<?= htmlspecialchars($rowImgSrc) ?>" alt="<?= htmlspecialchars($p['name']) ?>" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
                      <span style="display:none;font-size:18px"><?= $icon ?></span>
                    </div>
                  <?php else: ?>
                    <div style="width:42px;height:42px;border-radius:10px;background:#F8ECDC;border:1.5px solid #D8C7B5;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0"><?= $icon ?></div>
                  <?php endif; ?>
                  <div>
                    <div class="prod-name" style="font-weight:700;color:#1E1224;font-size:13.5px"><?= htmlspecialchars($p['name']) ?></div>
                    <?php if (!empty($p['description'])): ?>
                      <div style="font-size:11.5px;color:#52434F;margin-top:2px"><?= htmlspecialchars($p['description']) ?></div>
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

<!-- ── Progressive Multi-Step "Add / Edit Item" Modal (Warm Cafe Theme) ── -->
<div id="progressive-add-modal" onclick="onProgressiveBackdropClick(event)" class="fixed inset-0 z-[250] flex items-center justify-center bg-[#181120]/60 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-200">
  <div id="progressive-modal-dialog" class="bg-white border-[1.5px] border-[#DFCBB5] rounded-3xl w-full max-w-2xl mx-4 overflow-hidden shadow-2xl transform scale-95 transition-all duration-200 flex flex-col max-h-[90vh]">
    
    <!-- Hidden input to store item ID in edit mode -->
    <input type="hidden" id="prog-id" value=""/>

    <!-- Modal Header -->
    <div class="px-6 py-4 bg-[#F8EFE3] border-b-[1.5px] border-[#DFCBB5] flex items-center justify-between shrink-0">
      <div class="flex items-center gap-3">
        <div id="prog-modal-badge" class="w-10 h-10 rounded-2xl bg-gradient-to-br from-[#A85A1E] to-[#8B4513] flex items-center justify-center text-white font-bold text-lg shadow-md shadow-[#8B4513]/25">
          ✨
        </div>
        <div>
          <h3 id="prog-modal-title" class="font-bold text-base text-[#1E1224] tracking-tight font-display">Add Menu Item &amp; Recipe</h3>
          <p id="prog-modal-subtitle" class="text-[11px] text-[#52434F] font-medium">Step-by-step beverage identity, photo, and recipe composition</p>
        </div>
      </div>
      <button type="button" onclick="requestCloseProgressiveModal()" class="w-8 h-8 rounded-full bg-[#F5E6D3] hover:bg-[#EBD6BE] text-[#7A3A10] flex items-center justify-center text-sm font-bold transition" title="Close (Esc)">✕</button>
    </div>

    <!-- Responsive Visual Step Tracker -->
    <div class="px-6 py-3.5 bg-[#FAF6EE] border-b-[1.5px] border-[#DFCBB5] shrink-0">
      <div class="flex items-center justify-between relative max-w-lg mx-auto">
        <!-- Connecting Lines -->
        <div class="absolute left-6 right-6 top-4 h-[2px] bg-[#DFCBB5] -z-0">
          <div id="step-progress-bar" class="h-full bg-[#8B4513] transition-all duration-300 w-0"></div>
        </div>

        <!-- Step 1 Indicator -->
        <div class="flex flex-col items-center relative z-10 cursor-pointer" onclick="goToStep(1)">
          <div id="step-ind-1" class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-[#8B4513] text-white ring-4 ring-[#8B4513]/20 shadow-md transition-all">
            1
          </div>
          <span id="step-lbl-1" class="text-[11px] font-bold text-[#7A3A10] mt-1.5 transition-colors">1. Item Identity</span>
        </div>

        <!-- Step 2 Indicator -->
        <div class="flex flex-col items-center relative z-10 cursor-pointer" onclick="goToStep(2)">
          <div id="step-ind-2" class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-white text-[#52434F] border-2 border-[#DFCBB5] transition-all">
            2
          </div>
          <span id="step-lbl-2" class="text-[11px] font-semibold text-[#52434F] mt-1.5 transition-colors">2. Regular Recipe</span>
        </div>

        <!-- Step 3 Indicator -->
        <div class="flex flex-col items-center relative z-10 cursor-pointer" onclick="goToStep(3)">
          <div id="step-ind-3" class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-white text-[#52434F] border-2 border-[#DFCBB5] transition-all">
            3
          </div>
          <span id="step-lbl-3" class="text-[11px] font-semibold text-[#52434F] mt-1.5 transition-colors">3. Upsize Recipe</span>
        </div>
      </div>
    </div>

    <!-- Scrollable Modal Body: Step Panels -->
    <div class="p-6 overflow-y-auto flex-1 space-y-4 bg-white">

      <!-- Inline Validation Banner -->
      <div id="prog-error-banner" class="hidden p-3 rounded-xl bg-[#FDECEA] border border-[#FBDCD8] text-[#C62828] text-xs flex items-center gap-2 font-semibold">
        <span>⚠️</span>
        <span id="prog-error-text">Please fill in all required fields before continuing.</span>
      </div>

      <!-- ═══════════════════════════════════════════════════════════ -->
      <!-- STEP 1: ITEM IDENTITY & PHOTO                                -->
      <!-- ═══════════════════════════════════════════════════════════ -->
      <div id="step-panel-1" class="step-panel space-y-4 transition-all duration-200">
        <div>
          <label class="block text-[11px] font-extrabold uppercase tracking-wider text-[#1E1224] mb-1.5">
            Drink / Item Name <span class="text-rose-600">*</span>
          </label>
          <input type="text" id="prog-name" placeholder="e.g. Spanish Iced Latte" oninput="validateStep1Live()"
                 class="w-full px-3.5 py-2.5 text-xs bg-white border-[1.5px] border-[#DFCBB5] focus:border-[#8B4513] focus:ring-2 focus:ring-[#8B4513]/20 rounded-xl text-[#1E1224] placeholder-[#7A6B77] focus:outline-none transition font-medium"/>
          <span id="prog-name-err" class="text-[11px] text-[#C62828] font-bold mt-1 hidden block"></span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-[11px] font-extrabold uppercase tracking-wider text-[#1E1224] mb-1.5">
              Category <span class="text-rose-600">*</span>
            </label>
            <select id="prog-category" onchange="validateStep1Live()"
                    class="w-full px-3.5 py-2.5 text-xs bg-white border-[1.5px] border-[#DFCBB5] focus:border-[#8B4513] focus:ring-2 focus:ring-[#8B4513]/20 rounded-xl text-[#1E1224] font-medium focus:outline-none transition">
              <option value="">Select a Category…</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block text-[11px] font-extrabold uppercase tracking-wider text-[#1E1224] mb-1.5">
              Quick Icon / Emoji
            </label>
            <div class="flex items-center gap-1.5 flex-wrap" id="icon-picker">
              <?php foreach (['☕','🧊','🧋','🍵','🥤','🥐','🍰','🍹'] as $emoji): ?>
              <button type="button" onclick="selectDrinkEmoji('<?= $emoji ?>', this)"
                      class="emoji-btn w-9 h-9 rounded-xl bg-[#FAF5EE] border border-[#DFCBB5] hover:border-[#8B4513] text-base flex items-center justify-center transition <?= $emoji === '☕' ? 'border-2 border-[#8B4513] bg-[#F8ECDC] shadow-sm' : '' ?>">
                <?= $emoji ?>
              </button>
              <?php endforeach; ?>
              <input type="hidden" id="prog-icon" value="☕"/>
            </div>
          </div>
        </div>

        <!-- Item Image Drag-and-Drop & Browse Section -->
        <div>
          <label class="block text-[11px] font-extrabold uppercase tracking-wider text-[#1E1224] mb-1.5">
            Item Photo <span class="text-[11px] font-normal text-[#52434F] lowercase">(optional image for POS &amp; menu)</span>
          </label>
          <input type="file" id="prog-image-input" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" onchange="previewProductImage(this)"/>
          <input type="hidden" id="prog-existing-image" value=""/>
          <input type="hidden" id="prog-remove-image" value="0"/>

          <div id="prog-image-dropzone" onclick="triggerImageBrowse()"
               ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)"
               class="relative border-2 border-dashed border-[#DFCBB5] hover:border-[#8B4513] bg-[#FAF6EF] hover:bg-[#F5EDE3] rounded-2xl p-4 text-center cursor-pointer transition-all flex flex-col items-center justify-center min-h-[110px] group">
            
            <!-- Placeholder state -->
            <div id="prog-img-placeholder" class="flex flex-col items-center gap-1.5 py-1">
              <div class="w-10 h-10 rounded-full bg-[#F5E6D3] text-[#7A3A10] flex items-center justify-center text-xl group-hover:scale-110 transition-transform">
                📷
              </div>
              <div class="text-xs font-bold text-[#1E1224]">
                Click to browse or drag &amp; drop item photo
              </div>
              <div class="text-[11px] text-[#52434F] font-medium">
                PNG, JPG, WEBP, or GIF up to 5MB
              </div>
            </div>

            <!-- Preview state -->
            <div id="prog-img-preview-container" class="hidden w-full flex items-center justify-between gap-3 p-1">
              <div class="flex items-center gap-3">
                <div class="w-14 h-14 rounded-xl overflow-hidden border border-[#DFCBB5] bg-white flex-shrink-0 shadow-sm">
                  <img id="prog-img-preview" src="" alt="Preview" class="w-full h-full object-cover"/>
                </div>
                <div class="text-left">
                  <div id="prog-img-filename" class="text-xs font-bold text-[#1E1224] truncate max-w-[220px]">item-photo.jpg</div>
                  <div id="prog-img-filesize" class="text-[11px] text-[#52434F] font-medium">Ready to save</div>
                </div>
              </div>
              <button type="button" onclick="event.stopPropagation(); clearProductImage();"
                      class="px-3 py-1.5 rounded-xl bg-[#FDECEA] hover:bg-[#FBDCD8] text-[#C62828] text-xs font-bold border border-[#F8CBC5] flex items-center gap-1.5 transition shrink-0 shadow-sm"
                      title="Remove photo">
                <span>🗑️</span><span>Remove</span>
              </button>
            </div>
          </div>
        </div>

        <div>
          <label class="block text-[11px] font-extrabold uppercase tracking-wider text-[#1E1224] mb-1.5">
            Description &amp; Notes
          </label>
          <textarea id="prog-desc" rows="3" placeholder="Describe the drink, roast profile, or preparation notes…"
                    class="w-full px-3.5 py-2.5 text-xs bg-white border-[1.5px] border-[#DFCBB5] focus:border-[#8B4513] focus:ring-2 focus:ring-[#8B4513]/20 rounded-xl text-[#1E1224] placeholder-[#7A6B77] focus:outline-none transition font-medium"></textarea>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════ -->
      <!-- STEP 2: REGULAR SIZE & RECIPE                               -->
      <!-- ═══════════════════════════════════════════════════════════ -->
      <div id="step-panel-2" class="step-panel hidden space-y-4 transition-all duration-200">
        <div class="bg-[#F8EFE3] border-[1.5px] border-[#DFCBB5] p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <span class="text-xs font-bold text-[#1E1224] block">Regular Size (12oz) Base Price</span>
            <span class="text-[11px] text-[#52434F] font-medium">Customer retail ring-up price at counter</span>
          </div>
          <div class="relative w-full sm:w-44">
            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-[#7A3A10] font-bold text-xs pointer-events-none"></span>
            <input type="number" step="0.01" min="0.01" id="prog-price-small" placeholder="0.00" oninput="validateStep2Live()"
                   class="w-full pl-7 pr-3 py-2 text-xs bg-white border border-[#DFCBB5] focus:border-[#8B4513] focus:ring-2 focus:ring-[#8B4513]/20 rounded-xl text-[#1E1224] font-mono font-bold focus:outline-none transition"/>
          </div>
        </div>

        <div class="border-t-[1.5px] border-[#DFCBB5] pt-3">
          <div class="flex items-center justify-between mb-2">
            <div>
              <h4 class="text-xs font-bold text-[#1E1224]">Regular Recipe Composition</h4>
              <p class="text-[10.5px] text-[#52434F] font-medium">Ingredients automatically deducted when Regular size is ordered</p>
            </div>
            <button type="button" onclick="addRecipeRow('small')" class="px-2.5 py-1.5 rounded-lg bg-[#F8ECDC] hover:bg-[#EEDBCA] text-[#7A3A10] text-[11px] font-bold border border-[#DFCBB5] transition flex items-center gap-1 shadow-sm">
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
        <div class="bg-[#F8EFE3] border-[1.5px] border-[#DFCBB5] p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <span class="text-xs font-bold text-[#1E1224] block">Upsize / Large (16oz - 22oz) Base Price</span>
            <span class="text-[11px] text-[#52434F] font-medium">Retail price when customer requests an upsized drink</span>
          </div>
          <div class="relative w-full sm:w-44">
            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-[#7A3A10] font-bold text-xs pointer-events-none">₱</span>
            <input type="number" step="0.01" min="0.01" id="prog-price-large" placeholder="0.00" oninput="validateStep3Live()"
                   class="w-full pl-7 pr-3 py-2 text-xs bg-white border border-[#DFCBB5] focus:border-[#8B4513] focus:ring-2 focus:ring-[#8B4513]/20 rounded-xl text-[#1E1224] font-mono font-bold focus:outline-none transition"/>
          </div>
        </div>

        <div class="border-t-[1.5px] border-[#DFCBB5] pt-3">
          <div class="flex items-center justify-between mb-2">
            <div>
              <h4 class="text-xs font-bold text-[#1E1224]">Upsize Recipe Composition</h4>
              <p class="text-[10.5px] text-[#52434F] font-medium">Ingredients deducted when Upsize is ordered</p>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" onclick="copyRecipeFromRegular()" class="px-2.5 py-1.5 rounded-lg bg-[#FAF6EE] hover:bg-[#EEDBCA] text-[#7A3A10] text-[11px] font-bold border border-[#DFCBB5] transition flex items-center gap-1 shadow-sm">
                <span>⚡</span><span>Copy from Regular</span>
              </button>
              <button type="button" onclick="addRecipeRow('large')" class="px-2.5 py-1.5 rounded-lg bg-[#F8ECDC] hover:bg-[#EEDBCA] text-[#7A3A10] text-[11px] font-bold border border-[#DFCBB5] transition flex items-center gap-1 shadow-sm">
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
    <div class="px-6 py-4 bg-[#F8EFE3] border-t-[1.5px] border-[#DFCBB5] flex items-center justify-between shrink-0">
      <button type="button" onclick="requestCloseProgressiveModal()" class="px-4 py-2 rounded-xl bg-white hover:bg-[#FAF6EE] text-[#382836] hover:text-[#7A3A10] text-xs font-bold border border-[#DFCBB5] transition">
        Cancel
      </button>

      <div class="flex items-center gap-2.5">
        <button type="button" id="prog-btn-back" onclick="prevStep()" class="hidden px-4 py-2 rounded-xl bg-white hover:bg-[#FAF6EE] text-[#1E1224] text-xs font-bold border border-[#DFCBB5] transition flex items-center gap-1.5">
          <span>←</span><span>Back</span>
        </button>

        <button type="button" id="prog-btn-next" onclick="nextStep()" class="px-5 py-2 rounded-xl bg-gradient-to-r from-[#8B4513] to-[#241A2E] hover:opacity-95 text-white text-xs font-bold transition shadow-md shadow-[#8B4513]/25 flex items-center gap-1.5">
          <span>Next</span><span>→</span>
        </button>

        <button type="button" id="prog-btn-save" onclick="submitProgressiveItem()" class="hidden px-5 py-2 rounded-xl bg-gradient-to-r from-[#8B4513] to-[#241A2E] hover:opacity-95 text-white text-xs font-bold transition shadow-lg shadow-[#8B4513]/30 flex items-center gap-1.5">
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
let editingOriginalName = '';

function openProgressiveModal(mode = 'add', itemData = null) {
  currentStep = 1;
  isDirty = false;
  hideProgError();

  const idInput = document.getElementById('prog-id');
  const modalTitle = document.getElementById('prog-modal-title');
  const modalSubtitle = document.getElementById('prog-modal-subtitle');
  const modalBadge = document.getElementById('prog-modal-badge');
  const saveLabel = document.getElementById('save-label');
  const nameErr = document.getElementById('prog-name-err');
  if (nameErr) nameErr.classList.add('hidden');

  const imgInput = document.getElementById('prog-image-input');
  const existingImgInput = document.getElementById('prog-existing-image');
  const removeImgInput = document.getElementById('prog-remove-image');
  const placeholderEl = document.getElementById('prog-img-placeholder');
  const previewContainerEl = document.getElementById('prog-img-preview-container');
  const previewImg = document.getElementById('prog-img-preview');
  const filenameEl = document.getElementById('prog-img-filename');
  const filesizeEl = document.getElementById('prog-img-filesize');

  if (imgInput) imgInput.value = '';
  if (removeImgInput) removeImgInput.value = '0';

  if (mode === 'edit' && itemData) {
    idInput.value = itemData.id;
    editingOriginalName = (itemData.name || '').trim();
    if (modalTitle) modalTitle.textContent = 'Edit Menu Item & Recipe';
    if (modalSubtitle) modalSubtitle.textContent = 'Update beverage details, photo, and recipe composition';
    if (modalBadge) modalBadge.textContent = '✏️';
    if (saveLabel) saveLabel.textContent = '💾 Save Changes';

    // Populate Step 1 fields
    document.getElementById('prog-name').value = itemData.name || '';
    document.getElementById('prog-category').value = itemData.category_id || '';
    document.getElementById('prog-desc').value = itemData.description || '';

    // Handle existing image preview
    if (existingImgInput) existingImgInput.value = itemData.image_path || '';
    if (itemData.image_path) {
      if (placeholderEl) placeholderEl.classList.add('hidden');
      if (previewContainerEl) previewContainerEl.classList.remove('hidden');
      const raw = (itemData.image_path || '').replace(/^\/+/, '');
      const imgSrc = raw.startsWith('assets/') ? ('../' + raw) : ('../assets/' + raw);
      if (previewImg) previewImg.src = imgSrc;
      if (filenameEl) filenameEl.textContent = itemData.image_path.split('/').pop() || 'Current Item Image';
      if (filesizeEl) filesizeEl.textContent = 'Existing image loaded';
    } else {
      if (placeholderEl) placeholderEl.classList.remove('hidden');
      if (previewContainerEl) previewContainerEl.classList.add('hidden');
      if (previewImg) previewImg.src = '';
    }

    // Choose appropriate emoji based on category or default
    const catIcons = { '1': '🧊', '2': '☕', '3': '🧋', '4': '🍹' };
    const matchedEmoji = catIcons[String(itemData.category_id)] || '☕';
    selectDrinkEmoji(matchedEmoji);

    // Populate Step 2 & 3 prices
    document.getElementById('prog-price-small').value = itemData.price_small ? parseFloat(itemData.price_small).toFixed(2) : '';
    document.getElementById('prog-price-large').value = itemData.price_large ? parseFloat(itemData.price_large).toFixed(2) : '';

    // Show loading state for recipe rows
    const smallContainer = document.getElementById('prog-recipe-small-rows');
    const largeContainer = document.getElementById('prog-recipe-large-rows');
    if (smallContainer) smallContainer.innerHTML = '<div class="py-3 text-center text-xs text-[#52434F] font-semibold">⏳ Loading recipe ingredients…</div>';
    if (largeContainer) largeContainer.innerHTML = '<div class="py-3 text-center text-xs text-[#52434F] font-semibold">⏳ Loading recipe ingredients…</div>';

    // Fetch existing recipe from api/recipe.php
    fetch(`../api/recipe.php?product_id=${itemData.id}`)
      .then(r => r.json())
      .then(res => {
        if (smallContainer) smallContainer.innerHTML = '';
        if (largeContainer) largeContainer.innerHTML = '';

        if (res.ok && res.recipe) {
          const smallList = res.recipe.small || [];
          const largeList = res.recipe.large || [];

          if (smallList.length > 0) {
            smallList.forEach(r => addRecipeRow('small', r.ingredient_id, r.qty_used, r.unit));
          } else {
            addRecipeRow('small');
          }

          if (largeList.length > 0) {
            largeList.forEach(r => addRecipeRow('large', r.ingredient_id, r.qty_used, r.unit));
          } else {
            addRecipeRow('large');
          }
        } else {
          addRecipeRow('small');
          addRecipeRow('large');
        }
        isDirty = false;
      })
      .catch(() => {
        if (smallContainer) smallContainer.innerHTML = '';
        if (largeContainer) largeContainer.innerHTML = '';
        addRecipeRow('small');
        addRecipeRow('large');
        isDirty = false;
      });

  } else {
    // Add mode
    idInput.value = '';
    editingOriginalName = '';
    if (modalTitle) modalTitle.textContent = 'Add Menu Item & Recipe';
    if (modalSubtitle) modalSubtitle.textContent = 'Step-by-step beverage identity, photo, and recipe composition';
    if (modalBadge) modalBadge.textContent = '✨';
    if (saveLabel) saveLabel.textContent = '💾 Save Item';

    // Reset Step 1 fields
    document.getElementById('prog-name').value = '';
    document.getElementById('prog-category').value = '';
    document.getElementById('prog-desc').value = '';
    selectDrinkEmoji('☕');

    // Reset image fields
    if (existingImgInput) existingImgInput.value = '';
    if (placeholderEl) placeholderEl.classList.remove('hidden');
    if (previewContainerEl) previewContainerEl.classList.add('hidden');
    if (previewImg) previewImg.src = '';

    // Reset Step 2 fields
    document.getElementById('prog-price-small').value = '';
    const smallContainer = document.getElementById('prog-recipe-small-rows');
    if (smallContainer) smallContainer.innerHTML = '';
    addRecipeRow('small');

    // Reset Step 3 fields
    document.getElementById('prog-price-large').value = '';
    const largeContainer = document.getElementById('prog-recipe-large-rows');
    if (largeContainer) largeContainer.innerHTML = '';
    addRecipeRow('large');

    isDirty = false;
  }

  goToStep(1);

  const modal = document.getElementById('progressive-add-modal');
  modal.classList.remove('opacity-0', 'pointer-events-none');
  modal.querySelector('#progressive-modal-dialog').classList.remove('scale-95');
  setTimeout(() => document.getElementById('prog-name').focus(), 60);
}

// Aliases for unified invocation
function openAdd() { openProgressiveModal('add'); }
function openProgressiveAddModal() { openProgressiveModal('add'); }
function openEdit(p) { openProgressiveModal('edit', p); }
function closeAdd() { closeProgressiveAddModal(); }
function closeEdit() { closeProgressiveAddModal(); }

function closeProgressiveAddModal() {
  const modal = document.getElementById('progressive-add-modal');
  if (modal) {
    modal.classList.add('opacity-0', 'pointer-events-none');
    modal.querySelector('#progressive-modal-dialog').classList.add('scale-95');
  }
  isDirty = false;
  editingOriginalName = '';
}

function onProgressiveBackdropClick(e) {
  if (e.target.id === 'progressive-add-modal') {
    requestCloseProgressiveModal();
  }
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
  const hasImage = document.getElementById('prog-image-input')?.files?.length > 0;
  return isDirty || Boolean(name || priceSmall || priceLarge || hasImage);
}

// ── Item Photo Handlers ──────────────────────────────────────────
function triggerImageBrowse() {
  const input = document.getElementById('prog-image-input');
  if (input) input.click();
}

function handleDragOver(e) {
  e.preventDefault();
  e.stopPropagation();
  const dropzone = document.getElementById('prog-image-dropzone');
  if (dropzone) dropzone.classList.add('border-[#8B4513]', 'bg-[#F5EDE3]');
}

function handleDragLeave(e) {
  e.preventDefault();
  e.stopPropagation();
  const dropzone = document.getElementById('prog-image-dropzone');
  if (dropzone) dropzone.classList.remove('border-[#8B4513]', 'bg-[#F5EDE3]');
}

function handleDrop(e) {
  e.preventDefault();
  e.stopPropagation();
  const dropzone = document.getElementById('prog-image-dropzone');
  if (dropzone) dropzone.classList.remove('border-[#8B4513]', 'bg-[#F5EDE3]');
  if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
    const file = e.dataTransfer.files[0];
    const input = document.getElementById('prog-image-input');
    if (input) {
      try {
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
      } catch (err) {
        // Fallback for older browsers
      }
      previewProductImage(input);
    }
  }
}

function previewProductImage(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
  if (!validTypes.includes(file.type)) {
    showProgError('Please upload a valid image file (JPEG, PNG, WEBP, or GIF).');
    input.value = '';
    return;
  }
  if (file.size > 5 * 1024 * 1024) {
    showProgError('The image file is too large. Maximum size is 5MB.');
    input.value = '';
    return;
  }

  isDirty = true;
  hideProgError();
  const reader = new FileReader();
  reader.onload = function(e) {
    const previewImg = document.getElementById('prog-img-preview');
    const placeholderEl = document.getElementById('prog-img-placeholder');
    const previewContainerEl = document.getElementById('prog-img-preview-container');
    const filenameEl = document.getElementById('prog-img-filename');
    const filesizeEl = document.getElementById('prog-img-filesize');
    const removeImgInput = document.getElementById('prog-remove-image');

    if (previewImg) previewImg.src = e.target.result;
    if (placeholderEl) placeholderEl.classList.add('hidden');
    if (previewContainerEl) previewContainerEl.classList.remove('hidden');
    if (filenameEl) filenameEl.textContent = file.name;
    if (filesizeEl) filesizeEl.textContent = `${(file.size / 1024).toFixed(1)} KB (New)`;
    if (removeImgInput) removeImgInput.value = '0';
  };
  reader.readAsDataURL(file);
}

function clearProductImage() {
  isDirty = true;
  const input = document.getElementById('prog-image-input');
  if (input) input.value = '';
  const removeImgInput = document.getElementById('prog-remove-image');
  if (removeImgInput) removeImgInput.value = '1';
  const existingImgInput = document.getElementById('prog-existing-image');
  if (existingImgInput) existingImgInput.value = '';

  const placeholderEl = document.getElementById('prog-img-placeholder');
  const previewContainerEl = document.getElementById('prog-img-preview-container');
  const previewImg = document.getElementById('prog-img-preview');

  if (previewImg) previewImg.src = '';
  if (previewContainerEl) previewContainerEl.classList.add('hidden');
  if (placeholderEl) placeholderEl.classList.remove('hidden');
}

function selectDrinkEmoji(emoji, btn) {
  const iconInput = document.getElementById('prog-icon');
  if (iconInput) iconInput.value = emoji;
  document.querySelectorAll('#icon-picker .emoji-btn').forEach(b => {
    const isSelected = btn ? (b === btn) : (b.textContent.trim() === emoji);
    b.classList.toggle('border-[#8B4513]', isSelected);
    b.classList.toggle('border-2', isSelected);
    b.classList.toggle('bg-[#F8ECDC]', isSelected);
    b.classList.toggle('shadow-sm', isSelected);
  });
  if (btn) isDirty = true;
}

function showProgError(msg) {
  const banner = document.getElementById('prog-error-banner');
  const text = document.getElementById('prog-error-text');
  if (text) text.textContent = msg;
  if (banner) banner.classList.remove('hidden');
}

function hideProgError() {
  const banner = document.getElementById('prog-error-banner');
  if (banner) banner.classList.add('hidden');
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
      ind.className = 'w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-[#1b5e20] text-white shadow-sm transition-all';
      ind.textContent = '✓';
      lbl.className = 'text-[11px] font-bold text-[#1b5e20] mt-1.5 transition-colors';
    } else if (s === currentStep) {
      // Active
      ind.className = 'w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-[#8B4513] text-white ring-4 ring-[#8B4513]/20 shadow-md transition-all';
      ind.textContent = s;
      lbl.className = 'text-[11px] font-bold text-[#7A3A10] mt-1.5 transition-colors';
    } else {
      // Inactive
      ind.className = 'w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold bg-white text-[#52434F] border-2 border-[#DFCBB5] transition-all';
      ind.textContent = s;
      lbl.className = 'text-[11px] font-semibold text-[#52434F] mt-1.5 transition-colors';
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

    const isDuplicate = window.EXISTING_PRODUCT_NAMES &&
      window.EXISTING_PRODUCT_NAMES.includes(name.toLowerCase()) &&
      name.toLowerCase() !== editingOriginalName.toLowerCase();

    if (isDuplicate) {
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
    const isDuplicate = window.EXISTING_PRODUCT_NAMES &&
      window.EXISTING_PRODUCT_NAMES.includes(name.toLowerCase()) &&
      name.toLowerCase() !== editingOriginalName.toLowerCase();

    if (isDuplicate) {
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
  row.className = 'recipe-row flex items-center gap-2 p-2.5 bg-[#FAF6EF] border border-[#DFCBB5] rounded-xl text-xs transition';

  let optionsHtml = '<option value="">Select ingredient…</option>';
  (window.AVAILABLE_INGREDIENTS || []).forEach(ing => {
    const isSelected = String(ing.id) === String(defaultIngId) ? 'selected' : '';
    optionsHtml += `<option value="${ing.id}" data-unit="${ing.unit || 'pcs'}" ${isSelected}>${escapeHtml(ing.name)} (${ing.unit || 'pcs'})</option>`;
  });

  row.innerHTML = `
    <select class="recipe-ing-select flex-1 px-3 py-1.5 bg-white border border-[#DFCBB5] focus:border-[#8B4513] focus:ring-1 focus:ring-[#8B4513]/20 rounded-lg text-[#1E1224] font-medium text-xs focus:outline-none" onchange="onRecipeIngChange(this)">
      ${optionsHtml}
    </select>
    <div class="relative w-24 shrink-0">
      <input type="number" step="0.01" min="0.01" placeholder="Qty" value="${defaultQty !== '' ? defaultQty : ''}"
             class="recipe-qty-input w-full px-2.5 py-1.5 bg-white border border-[#DFCBB5] focus:border-[#8B4513] focus:ring-1 focus:ring-[#8B4513]/20 rounded-lg text-[#1E1224] font-mono font-bold text-xs focus:outline-none" oninput="isDirty=true"/>
    </div>
    <span class="recipe-unit-badge w-12 text-center text-[11px] font-mono font-bold text-[#6E350E] bg-[#F5E6D3] py-1.5 rounded-lg border border-[#DFCBB5]">
      ${defaultUnit || 'unit'}
    </span>
    <button type="button" onclick="removeRecipeRow(this)" class="w-8 h-8 rounded-lg bg-[#FDECEA] hover:bg-[#FBDCD8] text-[#C62828] flex items-center justify-center text-sm transition border border-[#F8CBC5]" title="Remove ingredient">
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

// ── Submit Progressive Item Payload to Backend (with Image Upload) ─
function submitProgressiveItem() {
  if (!validateStep(1) || !validateStep(2) || !validateStep(3)) {
    return;
  }

  const idVal = document.getElementById('prog-id').value;
  const isEdit = Boolean(idVal && parseInt(idVal, 10) > 0);

  const fd = new FormData();
  if (isEdit) {
    fd.append('id', idVal);
  }
  fd.append('name', document.getElementById('prog-name').value.trim());
  fd.append('category_id', document.getElementById('prog-category').value);
  fd.append('description', document.getElementById('prog-desc').value.trim());
  fd.append('price_small', document.getElementById('prog-price-small').value);
  fd.append('price_large', document.getElementById('prog-price-large').value);
  fd.append('recipe_small', JSON.stringify(collectRecipeRows('small')));
  fd.append('recipe_large', JSON.stringify(collectRecipeRows('large')));

  const imgInput = document.getElementById('prog-image-input');
  if (imgInput && imgInput.files && imgInput.files[0]) {
    fd.append('image', imgInput.files[0]);
  }
  const removeImage = document.getElementById('prog-remove-image')?.value === '1';
  if (removeImage) {
    fd.append('remove_image', '1');
  }

  const saveBtn = document.getElementById('prog-btn-save');
  const spinner = document.getElementById('save-spinner');
  const label   = document.getElementById('save-label');

  saveBtn.disabled = true;
  spinner.classList.remove('hidden');
  label.textContent = isEdit ? 'Updating Item & Recipes…' : 'Saving Item & Recipes…';

  fetch('../api/save_item.php', {
    method: 'POST',
    body: fd
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
    label.textContent = isEdit ? '💾 Save Changes' : '💾 Save Item';

    if (res.ok) {
      closeProgressiveAddModal();
      Swal.fire({
        title: isEdit ? 'Item Updated!' : 'Item Created!',
        text: `"${document.getElementById('prog-name').value.trim()}" and its size recipes have been saved.`,
        icon: 'success',
        confirmButtonColor: '#8B4513',
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
    label.textContent = isEdit ? '💾 Save Changes' : '💾 Save Item';
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

// ── Helper to update row in DOM (used when updating in-place) ──
function updateRowInDOM(id, p) {
  const row = document.getElementById('prow-' + id);
  if (!row) return;
  const catSelect = document.getElementById('prog-category');
  let catName = '—';
  if (catSelect) {
    for (let i = 0; i < catSelect.options.length; i++) {
      if (String(catSelect.options[i].value) === String(p.category_id)) {
        catName = catSelect.options[i].textContent;
        break;
      }
    }
  }

  const nameEl = row.querySelector('.prod-name');
  if (nameEl) nameEl.textContent = p.name;
  const priceEl = row.querySelector('.prod-price');
  if (priceEl) {
    priceEl.textContent = `₱${parseFloat(p.price_small).toFixed(2)} · ₱${parseFloat(p.price_large).toFixed(2)}`;
  }
  const mutedEl = row.querySelector('.muted-cell');
  if (mutedEl) mutedEl.textContent = catName;
  row.dataset.cat  = p.category_id;
  row.dataset.name = (p.name || '').toLowerCase();

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
    if (e.target === el) {
      if (typeof closeAvail === 'function') closeAvail();
    }
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    const progModal = document.getElementById('progressive-add-modal');
    if (progModal && !progModal.classList.contains('pointer-events-none')) {
      requestCloseProgressiveModal();
      return;
    }
    if (typeof closeAvail === 'function') closeAvail();
  }
});

</script>

<script src="../js/validator.js"></script>
</body>
</html>