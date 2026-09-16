// ============================================================
// Kofee Manila — Manage Permissions Controller (js/manage_permissions.js)
// Supports dynamic category filtering, real-time search, bulk actions,
// and single-batch API synchronization with live dirty detection.
// ============================================================

let activeCategory = 'all';

function showToast(msg, type = 'success') {
  const existing = document.getElementById('rbac-toast');
  if (existing) existing.remove();
  const t = document.createElement('div');
  t.id = 'rbac-toast';
  t.className = 'toast toast-' + type;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => {
    t.style.opacity = '0';
    setTimeout(() => t.remove(), 400);
  }, 3200);
}

// ── Toggle a single permission ──────────────────────────────
function togglePerm(el) {
  el.classList.toggle('on');
  const isNowOn = el.classList.contains('on');
  el.title = isNowOn ? 'Granted — click to revoke' : 'Not granted — click to grant';
  updateUIStats();
}

// ── Select Category Tab ─────────────────────────────────────
function selectCategory(catName, tabBtn) {
  activeCategory = catName;

  document.querySelectorAll('.cat-tab').forEach(b => b.classList.remove('active'));
  if (tabBtn) tabBtn.classList.add('active');

  filterPerms();
}

// ── Filter Permissions (Search + Category) ───────────────────
function filterPerms() {
  const searchInput = document.getElementById('perm-search');
  const query = searchInput ? searchInput.value.toLowerCase().trim() : '';

  const groups = document.querySelectorAll('.perm-category-group');

  groups.forEach(group => {
    const groupCat = group.dataset.cat;
    const catMatches = (activeCategory === 'all' || activeCategory === groupCat);

    let groupVisibleCount = 0;
    const rows = group.querySelectorAll('.perm-row');

    rows.forEach(row => {
      const searchContent = row.dataset.search || '';
      const textMatches = (!query || searchContent.includes(query));

      if (catMatches && textMatches) {
        row.style.display = '';
        groupVisibleCount++;
      } else {
        row.style.display = 'none';
      }
    });

    // Hide entire category header/group if no rows match
    group.style.display = (groupVisibleCount > 0) ? '' : 'none';
  });
}

// ── Bulk Grant / Revoke all visible rows ─────────────────────
function bulkSetVisible(grantState) {
  const rows = document.querySelectorAll('.perm-row');
  let affected = 0;

  rows.forEach(row => {
    if (row.style.display !== 'none') {
      const toggle = row.querySelector('.perm-toggle');
      if (toggle) {
        if (grantState) {
          if (!toggle.classList.contains('on')) {
            toggle.classList.add('on');
            toggle.title = 'Granted — click to revoke';
            affected++;
          }
        } else {
          if (toggle.classList.contains('on')) {
            toggle.classList.remove('on');
            toggle.title = 'Not granted — click to grant';
            affected++;
          }
        }
      }
    }
  });

  if (affected > 0) {
    updateUIStats();
    showToast(grantState ? `Granted ${affected} permission(s).` : `Revoked ${affected} permission(s).`);
  } else {
    showToast('No permissions needed updating in current view.');
  }
}

// ── Update Count Badges and Dirty State ──────────────────────
function updateUIStats() {
  const allToggles = [...document.querySelectorAll('.perm-toggle')];
  const totalCount = allToggles.length;
  let grantedCount = 0;
  let changedCount = 0;

  // Category counts
  const catCounts = {};

  allToggles.forEach(t => {
    const isOn = t.classList.contains('on');
    const isOriginal = (t.dataset.original === '1');
    const cat = t.dataset.cat;

    if (!catCounts[cat]) {
      catCounts[cat] = { granted: 0, total: 0 };
    }
    catCounts[cat].total++;

    if (isOn) {
      grantedCount++;
      catCounts[cat].granted++;
    }

    if (isOn !== isOriginal) {
      changedCount++;
    }
  });

  // Update top counter
  const counterEl = document.getElementById('granted-counter');
  if (counterEl) {
    counterEl.textContent = `${grantedCount} OF ${totalCount} GRANTED`;
  }

  // Update All badge
  const badgeAll = document.getElementById('badge-all');
  if (badgeAll) {
    badgeAll.textContent = `${grantedCount}/${totalCount}`;
  }

  // Update Category badges
  Object.keys(catCounts).forEach(cat => {
    const badge = document.getElementById(`badge-cat-${cat}`);
    if (badge) {
      badge.textContent = `${catCounts[cat].granted}/${catCounts[cat].total}`;
    }
  });

  // Update Dirty Indicator & Save Button
  const dirtyDot = document.getElementById('dirty-dot');
  const dirtyText = document.getElementById('dirty-text');
  const saveBtn = document.getElementById('save-btn');

  if (changedCount > 0) {
    if (dirtyDot) dirtyDot.classList.add('active');
    if (dirtyText) dirtyText.textContent = `${changedCount} pending change(s) unsaved`;
    if (saveBtn) {
      saveBtn.disabled = false;
      saveBtn.style.opacity = '1';
    }
  } else {
    if (dirtyDot) dirtyDot.classList.remove('active');
    if (dirtyText) dirtyText.textContent = 'All changes saved to database';
  }
}

// ── Save Changes: Preview Modal ──────────────────────────────
function saveChanges() {
  const rows = [...document.querySelectorAll('.perm-toggle')];
  const changed = rows.filter(el => (el.classList.contains('on') ? '1' : '0') !== el.dataset.original);

  if (!changed.length) {
    showToast('No permission changes to save.');
    return;
  }

  const roleSelect = document.getElementById('role-picker');
  const role = roleSelect ? roleSelect.value : (window.CONFIG?.role || '');

  if (!role) {
    showToast('⚠️ No role selected.', 'error');
    return;
  }

  const roleLabel = roleSelect ? roleSelect.options[roleSelect.selectedIndex].text.split('—')[0].trim() : role;
  document.getElementById('save-confirm-role').textContent = roleLabel;

  document.getElementById('save-confirm-list').innerHTML = changed.map(el => {
    const row     = el.closest('.perm-row');
    const label   = row ? row.querySelector('.perm-label').textContent : el.dataset.perm;
    const granted = el.classList.contains('on');
    return `<div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;font-size:12.5px;border-bottom:1px solid #F6EDE2">
      <span style="font-weight:600;color:var(--espresso)">${escapeHtml(label)}</span>
      <span style="font-weight:700;padding:2px 8px;border-radius:6px;font-size:11px;background:${granted ? 'rgba(46,125,50,0.1)' : 'rgba(198,40,40,0.1)'};color:${granted ? 'var(--green,#2e7d32)' : 'var(--red,#c62828)'}">
        ${granted ? '✅ Grant' : '❌ Revoke'}
      </span>
    </div>`;
  }).join('');

  document.getElementById('save-confirm-modal').classList.add('open');
}

function closeSaveConfirm() {
  const modal = document.getElementById('save-confirm-modal');
  if (modal) modal.classList.remove('open');
}

// ── Save Changes: Commit to Server ───────────────────────────
function doSaveChanges() {
  const roleSelect = document.getElementById('role-picker');
  const role = roleSelect ? roleSelect.value : (window.CONFIG?.role || '');

  const rows = [...document.querySelectorAll('.perm-toggle')];
  const changed = rows.filter(el => (el.classList.contains('on') ? '1' : '0') !== el.dataset.original);

  closeSaveConfirm();

  if (!changed.length) {
    showToast('No changes to save.');
    return;
  }
  if (!role) {
    showToast('⚠️ No role selected.', 'error');
    return;
  }

  const confirmBtn = document.getElementById('save-confirm-btn');
  const saveBtn = document.getElementById('save-btn');

  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';
  }

  // Collect all currently granted permission keys for bulk save
  const grantedPermKeys = rows
    .filter(el => el.classList.contains('on'))
    .map(el => el.dataset.perm);

  fetch('../api/save_permissions.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      role: role,
      permissions: grantedPermKeys
    })
  })
  .then(r => {
    if (!r.ok) {
      return r.json().then(errData => { throw new Error(errData.error || `Server status ${r.status}`); });
    }
    return r.json();
  })
  .then(res => {
    if (saveBtn) {
      saveBtn.disabled = false;
      saveBtn.textContent = '💾 Save Changes';
    }

    if (res.ok) {
      // Synchronize dataset.original to the new state
      rows.forEach(el => {
        el.dataset.original = el.classList.contains('on') ? '1' : '0';
      });
      updateUIStats();
      showToast('✅ Permissions updated successfully!');
    } else {
      showToast('⚠️ ' + (res.error || 'Failed to update permissions.'), 'error');
    }
  })
  .catch(err => {
    if (saveBtn) {
      saveBtn.disabled = false;
      saveBtn.textContent = '💾 Save Changes';
    }
    showToast('⚠️ ' + err.message, 'error');
  });
}

// ── Add Role Modal Controls ──────────────────────────────────
function openAddRole() {
  document.getElementById('new-role-key').value = '';
  document.getElementById('new-role-label').value = '';
  const msg = document.getElementById('ar-msg');
  if (msg) {
    msg.textContent = '';
    msg.className = 'ar-msg';
  }
  document.getElementById('add-role-modal').classList.add('open');
  document.getElementById('new-role-key').focus();
}

function closeAddRole() {
  const modal = document.getElementById('add-role-modal');
  if (modal) modal.classList.remove('open');
}

function addRole() {
  const keyInput   = document.getElementById('new-role-key');
  const labelInput = document.getElementById('new-role-label');
  const msg        = document.getElementById('ar-msg');
  const btn        = document.getElementById('btn-create-role');

  const key   = keyInput.value.trim().toLowerCase();
  const label = labelInput.value.trim();

  msg.textContent = '';
  msg.className = 'ar-msg';

  if (!key || !label) {
    msg.textContent = '⚠️ Role key and display label are required.';
    msg.className = 'ar-msg error';
    return;
  }

  if (!/^[a-z0-9_]{2,30}$/.test(key)) {
    msg.textContent = '⚠️ Role key must be lowercase letters, numbers, or underscores (2-30 chars).';
    msg.className = 'ar-msg error';
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Creating…';

  fetch('../api/manage_roles.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'create', role_key: key, label: label })
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.textContent = '➕ Create Role';
    if (res.ok) {
      closeAddRole();
      showToast('🎉 Role "' + label + '" created!');
      window.location.href = 'manage_permissions.php?role=' + encodeURIComponent(key);
    } else {
      msg.textContent = '⚠️ ' + (res.error || 'Failed to create role.');
      msg.className = 'ar-msg error';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = '➕ Create Role';
    msg.textContent = '⚠️ Network error creating role.';
    msg.className = 'ar-msg error';
  });
}

// ── Remove Selected Role ─────────────────────────────────────
function removeRole() {
  const select = document.getElementById('role-picker');
  if (!select) return;

  const key   = select.value;
  const label = select.options[select.selectedIndex].text.split('—')[0].trim();

  if (!confirm(`Are you sure you want to remove the role "${label}"?\n\nThis action cannot be undone and is only allowed if no staff accounts are currently assigned to this role.`)) {
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
      showToast(`🗑️ Role "${label}" removed.`);
      setTimeout(() => {
        window.location.href = 'manage_permissions.php';
      }, 500);
    } else {
      showToast('⚠️ ' + (res.error || 'Failed to remove role.'), 'error');
    }
  })
  .catch(() => {
    showToast('⚠️ Network error deleting role.', 'error');
  });
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

// Keyboard shortcuts (Escape closes modals)
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeAddRole();
    closeSaveConfirm();
  }
});