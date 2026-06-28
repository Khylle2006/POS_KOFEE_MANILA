// Toggle password visibility
function togglePassword() {
    const input = document.getElementById('password');
    const button = document.querySelector('.toggle-pw');
    
    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = '🙈';
    } else {
        input.type = 'password';
        button.textContent = '👁️';
    }
}

// Handle login with AJAX (optional - or use form submit)
function handleLogin() {
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    const errorDiv = document.getElementById('error-msg');
    const errorText = document.getElementById('error-text');
    const btn = document.querySelector('.btn-login');
    
    // Validate
    if (!username || !password) {
        errorText.textContent = 'Please enter both username and password.';
        errorDiv.classList.add('visible');
        return;
    }
    
    // Disable button and show loading
    btn.textContent = '⏳ Signing in...';
    btn.disabled = true;
    errorDiv.classList.remove('visible');
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'auth/login_process.php';
    
    const usernameInput = document.createElement('input');
    usernameInput.type = 'hidden';
    usernameInput.name = 'username';
    usernameInput.value = username;
    form.appendChild(usernameInput);
    
    const passwordInput = document.createElement('input');
    passwordInput.type = 'hidden';
    passwordInput.name = 'password';
    passwordInput.value = password;
    form.appendChild(passwordInput);
    
    document.body.appendChild(form);
    form.submit();
}

// Handle Enter key
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    passwordInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleLogin();
        }
    });
    
    const usernameInput = document.getElementById('username');
    usernameInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleLogin();
        }
    });
});