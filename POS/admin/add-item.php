<!DOCTYPE html>
<html>
<head>
    <title>POS System</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/add-items.css">
</head>
<body>
    <?php include('../includes/admin_sidebar.php'); ?>

      <div id="page-addmenu" class="page active">
    <div class="page-header">
      <div>
        <h1>Add Menu Item</h1>
        <p>Add a new drink to your menu</p>
      </div>
    </div>
    <div class="page-body">
      <div class="add-menu-wrap">

        <div class="form-card">
          <h2>Item Details</h2>

          <div class="field-group">
            <label class="field-label">Category</label>
            <select class="field-select" id="add-category">
              <option value="">Select a category…</option>
              <option value="ice-coffee">Ice Coffee</option>
              <option value="hot-coffee">Hot Coffee</option>
              <option value="milk-tea">Milk Tea</option>
              <option value="fruit-tea">Fruit Tea</option>
            </select>
          </div>

          <div class="field-group">
            <label class="field-label">Drink Name</label>
            <input class="field-input" type="text" id="add-name" placeholder="e.g. Taro Milk Tea" />
          </div>

          <div class="field-group">
            <label class="field-label">Description (optional)</label>
            <textarea class="field-textarea" id="add-desc" placeholder="Brief description of the drink…"></textarea>
          </div>
        </div>

        <div class="form-card">
          <h2>Pricing</h2>
          <div class="price-row">
            <div class="field-group">
              <label class="field-label">Small Price (₱)</label>
              <input class="field-input" type="number" id="add-price-small" placeholder="0.00" min="0" />
            </div>
            <div class="field-group">
              <label class="field-label">Large Price (₱)</label>
              <input class="field-input" type="number" id="add-price-large" placeholder="0.00" min="0" />
            </div>
          </div>
        </div>


        <button class="submit-btn" onclick="addMenuItem()">➕ Add to Menu</button>
      </div>
    </div>
  </div>

  <script>
        function addMenuItem() {
        const cat = document.getElementById('add-category').value;
        const name = document.getElementById('add-name').value.trim();
        const priceSmall = parseFloat(document.getElementById('add-price-small').value);
        const priceLarge = parseFloat(document.getElementById('add-price-large').value);

        if (!cat) { showToast('⚠️','Please select a category'); return; }
        if (!name) { showToast('⚠️','Please enter a drink name'); return; }
        if (isNaN(priceSmall) || priceSmall <= 0) { showToast('⚠️','Enter a valid small price'); return; }
        if (isNaN(priceLarge) || priceLarge <= 0) { showToast('⚠️','Enter a valid large price'); return; }

        const id = 'item_' + Date.now();
        menuData[cat].push({ id, name, icon: selectedEmoji, priceSmall, priceLarge });

        // Reset form
        document.getElementById('add-category').value = '';
        document.getElementById('add-name').value = '';
        document.getElementById('add-desc').value = '';
        document.getElementById('add-price-small').value = '';
        document.getElementById('add-price-large').value = '';
        selectedEmoji = '🍵';
        initEmojiPicker();

        showToast('✅', '"' + name + '" added to menu!');
        }

  </script>

</body>
</html>