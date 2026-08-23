function togglePw(id, btn) {
  const inp = document.getElementById(id);
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.textContent = inp.type === 'password' ? '👁️' : '🙈';
}

function openEdit(u) {
  document.getElementById('edit-id').value        = u.id;
  document.getElementById('edit-username').value  = u.username;
  document.getElementById('edit-firstname').value = u.firstname;
  document.getElementById('edit-lastname').value  = u.lastname;
  document.getElementById('edit-email').value     = u.email;
  document.getElementById('edit-pw').value        = '';
  document.getElementById('edit-role').value      = u.role;
  document.getElementById('edit-modal').classList.add('open');
}

function closeEdit() {
  document.getElementById('edit-modal').classList.remove('open');
}

function closeModalBg(e) {
  if (e.target === e.currentTarget) { closeEdit(); closeDelete(); }
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeEdit(); closeDelete(); }
});