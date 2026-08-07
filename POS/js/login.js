document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('form');
  const usernameInput = document.querySelector('input[type="text"]');
  const passwordInput = document.querySelector('input[type="password"]');
  const loginBtn = document.querySelector('.btn-brew');
  const rememberCheckbox = document.querySelector('input[type="checkbox"]');

  if (!form || !usernameInput || !passwordInput || !loginBtn) return;

  // Optional: i-load ang naka-save na username kung "Tandaan ako" ang ginamit dati
  const savedUsername = localStorage.getItem('pos_cafe_username');
  if (savedUsername) {
    usernameInput.value = savedUsername;
    if (rememberCheckbox) rememberCheckbox.checked = true;
  }

  function showError(input, message) {
    clearError(input);
    input.classList.add('border-red-400');
    const errorEl = document.createElement('p');
    errorEl.className = 'error-msg text-[11px] text-red-500 mt-1';
    errorEl.textContent = message;
    input.parentElement.appendChild(errorEl);
  }

  function clearError(input) {
    input.classList.remove('border-red-400');
    const existing = input.parentElement.querySelector('.error-msg');
    if (existing) existing.remove();
  }

  function validate() {
    let valid = true;

    if (!usernameInput.value.trim()) {
      showError(usernameInput, 'Kailangan ang username.');
      valid = false;
    } else {
      clearError(usernameInput);
    }

    if (!passwordInput.value.trim()) {
      showError(passwordInput, 'Kailangan ang password.');
      valid = false;
    } else if (passwordInput.value.length < 4) {
      showError(passwordInput, 'Dapat 4+ characters ang password.');
      valid = false;
    } else {
      clearError(passwordInput);
    }

    return valid;
  }

  function handleLogin(e) {
    // Client-side validation lang dito. Ang totoong pag-login (checking
    // ng username/password sa DB, session, at pag-redirect papunta sa
    // tamang dashboard) ay ginagawa ng server sa auth/login_process.php,
    // kaya hinahayaan na lang ng form na mag-submit ng normal kapag valid.
    if (!validate()) {
      e.preventDefault();
      return;
    }

    if (rememberCheckbox && rememberCheckbox.checked) {
      localStorage.setItem('pos_cafe_username', usernameInput.value.trim());
    } else {
      localStorage.removeItem('pos_cafe_username');
    }

    loginBtn.disabled = true;
    loginBtn.textContent = 'Naglo-login...';
    loginBtn.style.opacity = '0.7';
    // Walang e.preventDefault() dito — normal form submit na ang tatakbo.
  }

  // IMPORTANT: 'submit' listener lang dapat dito, hindi 'click' sa button.
  // Dating may click listener din na tumatawag sa handleLogin — kaya
  // na-disable ang button bago pa man makapag-submit ang form, kaya
  // hindi na talaga nagpapadala ng request papunta sa server.
  form.addEventListener('submit', handleLogin);

  // I-clear ang error message habang nagta-type
  [usernameInput, passwordInput].forEach((input) => {
    input.addEventListener('input', () => clearError(input));
  });
});