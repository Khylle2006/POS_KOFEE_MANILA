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
  const errBox = document.getElementById('staff-form-error');
  if (errBox) {
    errBox.style.display = 'none';
    errBox.textContent = '';
  }
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

  // Clear any existing validator errors
  if (window.KofeeValidator) {
    document.querySelectorAll('#staff-form input, #staff-form select').forEach(inp => {
      KofeeValidator.clearError(inp);
    });
  }
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
  }
  toggleSection('employee');

  document.getElementById('staff-modal').classList.add('open');
}

function closeModal() { document.getElementById('staff-modal').classList.remove('open'); }
function closeModalBg(e) { if (e.target === e.currentTarget) closeModal(); }

// ── Attach real-time clearing to inputs ──
document.querySelectorAll('#staff-form input, #staff-form select').forEach(inp => {
  inp.addEventListener('input', () => {
    if (window.KofeeValidator && inp.classList.contains('is-invalid')) {
      KofeeValidator.clearError(inp);
    }
  });
  inp.addEventListener('change', () => {
    if (window.KofeeValidator && inp.classList.contains('is-invalid')) {
      KofeeValidator.clearError(inp);
    }
  });
});

// ── Save (Add/Edit) — AJAX with KofeeValidator feedback ──
document.getElementById('staff-form').addEventListener('submit', function (e) {
  e.preventDefault();

  const errBox = document.getElementById('staff-form-error');
  errBox.style.display = 'none';
  errBox.textContent = '';

  const saveBtn = document.getElementById('save-btn');
  const existingUserId = document.getElementById('f-user-id').value;
  const existingEmpId  = document.getElementById('f-emp-id').value;

  const fnInput = document.getElementById('f-fn');
  const lnInput = document.getElementById('f-ln');
  const emailInput = document.getElementById('f-email');
  const wantAccount = document.getElementById('f-want-account').checked;
  const wantEmployee = document.getElementById('f-want-employee').checked;

  let isValid = true;
  let firstInvalid = null;

  function markInvalid(input, msg) {
    if (window.KofeeValidator) {
      KofeeValidator.showError(input, msg);
    }
    if (!firstInvalid) firstInvalid = input;
    isValid = false;
  }

  // 1. Basic Name Check
  if (!fnInput.value.trim()) {
    markInvalid(fnInput, 'First name is required.');
  } else if (fnInput.value.trim().length < 2) {
    markInvalid(fnInput, 'First name must be at least 2 characters.');
  }

  if (!lnInput.value.trim()) {
    markInvalid(lnInput, 'Last name is required.');
  } else if (lnInput.value.trim().length < 2) {
    markInvalid(lnInput, 'Last name must be at least 2 characters.');
  }

  // 2. Email format check (if provided)
  if (emailInput.value.trim()) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(emailInput.value.trim())) {
      markInvalid(emailInput, 'Please enter a valid email address.');
    }
  }

  // 3. Section Toggles Check
  if (!wantAccount && !wantEmployee) {
    errBox.textContent = '⚠️ Enable at least a Login Account or an Employee Profile.';
    errBox.style.display = 'block';
    return;
  }

  // 4. Account Validation
  if (wantAccount) {
    const usernameInput = document.getElementById('f-username');
    const passwordInput = document.getElementById('f-password');
    const confirmInput  = document.getElementById('f-confirm');
    const anyRole = [...document.querySelectorAll('#f-roles input[type=checkbox]')].some(cb => cb.checked);

    if (!usernameInput.value.trim()) {
      markInvalid(usernameInput, 'Username is required for login account.');
    } else if (usernameInput.value.trim().length < 3) {
      markInvalid(usernameInput, 'Username must be at least 3 characters.');
    }

    if (!anyRole) {
      errBox.textContent = '⚠️ Select at least one role for the login account.';
      errBox.style.display = 'block';
      if (!firstInvalid) firstInvalid = document.querySelector('#f-roles input');
      isValid = false;
    }

    const pw = passwordInput.value;
    const cpw = confirmInput.value;

    if (!existingUserId) {
      // New account requires password
      if (!pw) {
        markInvalid(passwordInput, 'Password is required for a new account.');
      } else if (pw.length < 6) {
        markInvalid(passwordInput, 'Password must be at least 6 characters.');
      }

      if (!cpw) {
        markInvalid(confirmInput, 'Please confirm the password.');
      } else if (pw !== cpw) {
        markInvalid(confirmInput, 'Passwords do not match.');
      }
    } else {
      // Existing account — password optional
      if (pw !== '') {
        if (pw.length < 6) {
          markInvalid(passwordInput, 'Password must be at least 6 characters.');
        }
        if (pw !== cpw) {
          markInvalid(confirmInput, 'Passwords do not match.');
        }
      }
    }
  }

  // 5. Employee Validation
  if (wantEmployee) {
    const codeInput = document.getElementById('f-code');
    const posInput  = document.getElementById('f-pos');
    const phoneInput = document.getElementById('f-phone');
    const salaryInput = document.getElementById('f-salary');

    if (!existingEmpId) {
      if (!codeInput.value.trim()) {
        markInvalid(codeInput, 'Employee code is required (e.g. EMP-0001).');
      }
    }

    if (!posInput.value.trim()) {
      markInvalid(posInput, 'Position is required (e.g. Barista).');
    }

    if (phoneInput.value.trim()) {
      const phoneClean = phoneInput.value.replace(/[\s\-]/g, '');
      if (!/^\+?[0-9]{7,15}$/.test(phoneClean)) {
        markInvalid(phoneInput, 'Enter a valid phone number (e.g. 09171234567).');
      }
    }

    if (salaryInput.value !== '' && parseFloat(salaryInput.value) < 0) {
      markInvalid(salaryInput, 'Base salary cannot be negative.');
    }
  }

  if (!isValid) {
    if (firstInvalid) {
      firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      firstInvalid.focus();
    }
    return;
  }

  const fd = new FormData(this);
  if (window.KofeeValidator) {
    KofeeValidator.setLoading(saveBtn, 'Saving…');
  } else {
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';
  }

  fetch(window.location.pathname + window.location.search, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: fd
  })
    .then(r => r.json())
    .then(res => {
      if (window.KofeeValidator) {
        KofeeValidator.resetLoading(saveBtn);
      } else {
        saveBtn.disabled = false;
      }

      if (res.ok) {
        closeModal();
        location.reload();
      } else {
        errBox.textContent = res.message || 'Something went wrong. Please check your entries.';
        errBox.style.display = 'block';
      }
    })
    .catch(() => {
      if (window.KofeeValidator) {
        KofeeValidator.resetLoading(saveBtn);
      } else {
        saveBtn.disabled = false;
      }
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

// Attach loading state to status confirm submit
document.getElementById('status-confirm-form')?.addEventListener('submit', function() {
  const btn = document.getElementById('sc-confirm-btn');
  if (btn && window.KofeeValidator) {
    KofeeValidator.setLoading(btn, 'Processing…');
  }
});

// ── Global escape / backdrop handling ──
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeModal(); closeStatusConfirm(); }
});