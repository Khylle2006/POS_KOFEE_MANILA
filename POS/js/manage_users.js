// ── Role pills (register form) ──
  function setRegRole(role, el) {
    document.getElementById('reg-role-val').value = role;
    document.querySelectorAll('#page-users .form-card .role-pills .role-pill-opt')
      .forEach(p => p.classList.remove('selected'));
    el.classList.add('selected');
  }

  // ── Role pills (edit modal) ──
  function setEditRole(role, el) {
    document.getElementById('edit-role-val').value = role;
    document.getElementById('edit-pill-staff').classList.remove('selected');
    document.getElementById('edit-pill-admin').classList.remove('selected');
    el.classList.add('selected');
  }

  // ── Password show/hide ──
  function togglePw(id, btn) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    btn.textContent = inp.type === 'password' ? '👁️' : '🙈';
  }

  // ── Edit modal ──
  function openEdit(user) {
    document.getElementById('edit-id').value    = user.id;
    document.getElementById('edit-name').value  = user.name;
    document.getElementById('edit-email').value = user.email;
    document.getElementById('edit-pw').value    = '';
    setEditRole(user.role, document.getElementById('edit-pill-' + user.role));
    document.getElementById('edit-modal').classList.add('open');
  }
  function closeEdit() { document.getElementById('edit-modal').classList.remove('open'); }

  // ── Delete modal ──
  function confirmDelete(id, name) {
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-msg').textContent =
      'This will permanently remove "' + name + '". This cannot be undone.';
    document.getElementById('delete-modal').classList.add('open');
  }
  function closeDelete() { document.getElementById('delete-modal').classList.remove('open'); }

  // Close modal on backdrop click
  function closeModalBg(e) {
    if (e.target === e.currentTarget) {
      closeEdit(); closeDelete();
    }
  }

  // Escape key closes modals
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeEdit(); closeDelete(); }
  });