function showToast(msg, type = 'success') {
  const existing = document.getElementById('rbac-toast');
  if (existing) existing.remove();
  const t = document.createElement('div');
  t.id = 'rbac-toast';
  t.className = 'toast toast-' + type;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3000);
}

// ── Toggle a permission (local only — Save changes commits it) ──
function togglePerm(el) {
  el.classList.toggle('on');
}

// ── Save changes: diff every row against its original state,
//    then push each change through the existing per-permission endpoint ──
function saveChanges() {
  const CONFIG = window.CONFIG || {};

    const role = CONFIG.role;        // Works! Gets 'admin'
    const userId = CONFIG.userId;    // Works! Gets 123
    const apiUrl = CONFIG.apiUrl;    // Works! Gets '../api/'

    console.log('Role:', role);
  const rows = [...document.querySelectorAll('.perm-toggle')];
  const changed = rows.filter(el => (el.classList.contains('on') ? '1' : '0') !== el.dataset.original);

  if (!changed.length) { showToast('Nothing to save.'); return; }

  const btn = document.getElementById('save-btn');
  btn.disabled = true;
  btn.textContent = 'Saving…';

  Promise.all(changed.map(el => {
    const granted = el.classList.contains('on');
    return fetch('save_permissions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ role, perm_key: el.dataset.perm, granted })
    }).then(r => r.json()).then(res => ({ el, granted, res }));
  }))
  .then(results => {
    let failed = 0;
    results.forEach(({ el, granted, res }) => {
      if (res.ok) {
        el.dataset.original = granted ? '1' : '0';
      } else {
        failed++;
        el.classList.toggle('on'); // revert this one
      }
    });
    btn.disabled = false;
    btn.textContent = 'Save changes';
    if (failed) showToast('⚠️ ' + failed + ' change(s) failed to save.', 'error');
    else showToast('✅ Changes saved!');
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Save changes';
    showToast('⚠️ Network error — nothing was saved.', 'error');
  });
}

// ── Add role ──
function openAddRole() {
  document.getElementById('new-role-key').value = '';
  document.getElementById('new-role-label').value = '';
  document.getElementById('ar-msg').textContent = '';
  document.getElementById('add-role-modal').classList.add('open');
}
function closeAddRole() { document.getElementById('add-role-modal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAddRole(); });

function addRole() {
  const key   = document.getElementById('new-role-key').value.trim();
  const label = document.getElementById('new-role-label').value.trim();
  const msg   = document.getElementById('ar-msg');
  msg.textContent = '';
  msg.className = 'ar-msg';

  fetch('../api/manage_roles.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'add', role_key: key, label })
  })
  .then(r => r.json())
  .then(res => {
    if (res.ok) {
      showToast('✅ Role "' + label + '" created!');
      window.location.href = 'manage_permissions.php?role=' + encodeURIComponent(key);
    } else {
      msg.textContent = '⚠️ ' + res.error;
      msg.className = 'ar-msg error';
    }
  })
  .catch(() => {
    msg.textContent = '⚠️ Network error.';
    msg.className = 'ar-msg error';
  });
}

// ── Remove the currently-selected role ──
function removeRole() {
  const select = document.getElementById('role-picker');
  const key   = select.value;
  const label = select.options[select.selectedIndex].text;
  if (!confirm('Remove the "' + label + '" role? This cannot be undone, and only works if no users currently have this role.')) {
    return;
  }
  fetch('../api/manage_roles.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete', role_key: key })
  })
  .then(r => r.json())
  .then(res => {
    if (res.ok) {
      showToast('🗑️ Role "' + label + '" removed.');
      window.location.href = 'manage_permissions.php';
    } else {
      showToast('⚠️ ' + res.error, 'error');
    }
  })
  .catch(() => showToast('⚠️ Network error.', 'error'));
}