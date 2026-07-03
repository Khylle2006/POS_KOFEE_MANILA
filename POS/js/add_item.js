// js/add_item.js

async function addMenuItem() {
  const msgBox  = document.getElementById('add-msg');
  const btn     = document.querySelector('.submit-btn');

  const cat_id      = document.getElementById('add-category').value;
  const name        = document.getElementById('add-name').value.trim();
  const desc        = document.getElementById('add-desc').value.trim();
  const price_small = document.getElementById('add-price-small').value;
  const price_large = document.getElementById('add-price-large').value;

  // ── Client-side validation (mirrors PHP) ─────────────────────
  if (!cat_id)        return showMsg('error', '⚠️ Please select a category.');
  if (!name)          return showMsg('error', '⚠️ Please enter a drink name.');
  if (!price_small || parseFloat(price_small) <= 0)
                      return showMsg('error', '⚠️ Enter a valid small price.');
  if (!price_large || parseFloat(price_large) <= 0)
                      return showMsg('error', '⚠️ Enter a valid large price.');

  // ── Build form data ───────────────────────────────────────────
  const body = new FormData();
  body.append('category_id',  cat_id);
  body.append('name',         name);
  body.append('description',  desc);
  body.append('price_small',  price_small);
  body.append('price_large',  price_large);

  // ── Send ─────────────────────────────────────────────────────
  btn.disabled    = true;
  btn.textContent = 'Saving…';

  try {
    // POST to the same PHP file (it handles both GET and POST)
    const res  = await fetch('add_item.php', {
      method: 'POST',
      body,
    });

    const data = await res.json();

    if (data.ok) {
      showMsg('success', `✅ "${data.name}" added to menu!`);
      resetForm();
    } else {
      showMsg('error', '⚠️ ' + (data.error ?? 'Something went wrong.'));
    }

  } catch (err) {
    // This is where "Network Error" comes from — wrong URL or server not running
    console.error('Fetch failed:', err);
    showMsg('error', '❌ Network error — make sure the server is running and the URL is correct.');
  } finally {
    btn.disabled    = false;
    btn.textContent = '➕ Add to Menu';
  }
}

// ── Helpers ──────────────────────────────────────────────────────
function showMsg(type, text) {
  const box = document.getElementById('add-msg');
  box.style.display     = 'block';
  box.style.background  = type === 'success' ? '#e8f5e9' : '#fdecea';
  box.style.color       = type === 'success' ? '#2e7d32' : '#c62828';
  box.style.border      = `1.5px solid ${type === 'success' ? '#a5d6a7' : '#ef9a9a'}`;
  box.textContent       = text;

  // Auto-hide success after 3 s
  if (type === 'success') setTimeout(() => { box.style.display = 'none'; }, 3000);
}

function resetForm() {
  document.getElementById('add-category').value    = '';
  document.getElementById('add-name').value        = '';
  document.getElementById('add-desc').value        = '';
  document.getElementById('add-price-small').value = '';
  document.getElementById('add-price-large').value = '';
}