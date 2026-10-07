document.addEventListener('DOMContentLoaded', () => {
    const button = document.getElementById('toggle-password');
    const password = document.getElementById('password');
    button?.addEventListener('click', () => {
        if (!password) return;
        const show = password.type === 'password';
        password.type = show ? 'text' : 'password';
        button.textContent = show ? 'Hide' : 'Show';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', String(show));
    });
});
