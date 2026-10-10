(() => {
    'use strict';
    const script = document.currentScript;
    const base = new URL('../', script.src);
    const nativeFetch = window.fetch.bind(window);
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    let confirmation = null;
    const operations = new Map();
    const storageKey = 'kofee-order-operations';
    try {
        const stored = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
        if (Array.isArray(stored)) {
            for (const entry of stored) {
                if (Array.isArray(entry) && typeof entry[0] === 'string' && typeof entry[1] === 'string') operations.set(entry[0], entry[1]);
            }
        }
    } catch (error) { /* Private browsing can disable storage; retain keys for this page. */ }

    function saveOperations() {
        try { sessionStorage.setItem(storageKey, JSON.stringify([...operations])); }
        catch (error) { /* The in-memory key still protects network retries. */ }
    }

    async function operationIdentity(request) {
        const digest = await crypto.subtle.digest('SHA-256', await request.clone().arrayBuffer());
        const hash = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
        return request.url + ':' + request.method + ':' + hash;
    }

    function operationKey(operation) {
        if (!operations.has(operation)) operations.set(operation, crypto.randomUUID());
        saveOperations();
        return operations.get(operation);
    }

    function ensureConfirmStyles() {
        if (typeof document === 'undefined') return;
        if (document.getElementById && document.getElementById('kfs-confirm-dialog-styles')) return;
        const target = document.head || document.body;
        if (!target || typeof target.appendChild !== 'function') return;
        try {
            const style = document.createElement('style');
            style.id = 'kfs-confirm-dialog-styles';
            style.textContent = `
.kfs-confirm-dialog {
  position: fixed !important;
  inset: auto !important;
  top: 50% !important;
  left: 50% !important;
  transform: translate(-50%, -50%) !important;
  margin: 0 !important;
  padding: 0 !important;
  width: min(440px, calc(100vw - 32px)) !important;
  max-width: 440px !important;
  border: 1px solid rgba(36, 26, 46, 0.2) !important;
  border-radius: 20px !important;
  background: #FFFFFF !important;
  color: #1E1224 !important;
  box-shadow: 0 24px 64px rgba(24, 17, 32, 0.38) !important;
  z-index: 10002 !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
  outline: none !important;
}
.kfs-confirm-dialog::backdrop {
  background: rgba(24, 17, 32, 0.65) !important;
  backdrop-filter: blur(4px) !important;
  -webkit-backdrop-filter: blur(4px) !important;
}
.kfs-confirm-dialog-card {
  padding: 0 !important;
  box-sizing: border-box !important;
  display: flex !important;
  flex-direction: column !important;
  background: #FFFFFF !important;
  border-radius: 20px !important;
  overflow: hidden !important;
  font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
}
.kfs-confirm-header {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  gap: 12px !important;
  padding: 20px 24px !important;
  background: linear-gradient(165deg, #241A2E 0%, #181120 100%) !important;
  border-bottom: 1px solid rgba(251, 243, 233, 0.1) !important;
  color: #FBF3E9 !important;
}
.kfs-confirm-header-left {
  display: flex !important;
  align-items: center !important;
  gap: 14px !important;
}
.kfs-confirm-icon-badge {
  width: 40px !important;
  height: 40px !important;
  border-radius: 12px !important;
  background: rgba(251, 243, 233, 0.12) !important;
  border: 1px solid rgba(251, 243, 233, 0.22) !important;
  color: #E6A25C !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  flex-shrink: 0 !important;
}
.kfs-confirm-title {
  margin: 0 !important;
  font-size: 16px !important;
  font-weight: 700 !important;
  color: #FFFFFF !important;
  line-height: 1.25 !important;
  letter-spacing: -0.01em !important;
}
.kfs-confirm-subtitle {
  margin: 3px 0 0 0 !important;
  font-size: 12px !important;
  color: rgba(251, 243, 233, 0.72) !important;
  line-height: 1.4 !important;
}
.kfs-confirm-close-btn {
  background: transparent !important;
  border: none !important;
  cursor: pointer !important;
  color: rgba(251, 243, 233, 0.65) !important;
  padding: 6px !important;
  border-radius: 8px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  transition: all 0.15s ease !important;
  flex-shrink: 0 !important;
}
.kfs-confirm-close-btn:hover {
  background: rgba(251, 243, 233, 0.15) !important;
  color: #FFFFFF !important;
}
.kfs-confirm-form {
  display: flex !important;
  flex-direction: column !important;
  padding: 22px 24px 24px !important;
  margin: 0 !important;
  background: #FFFFFF !important;
}
.kfs-confirm-field {
  display: flex !important;
  flex-direction: column !important;
}
.kfs-confirm-label {
  display: block !important;
  font-size: 11.5px !important;
  font-weight: 700 !important;
  text-transform: uppercase !important;
  letter-spacing: 0.04em !important;
  color: #52434F !important;
  margin-bottom: 8px !important;
}
.kfs-confirm-input-wrap {
  position: relative !important;
  display: flex !important;
  align-items: center !important;
  width: 100% !important;
}
.kfs-confirm-input {
  width: 100% !important;
  height: 44px !important;
  padding: 0 44px 0 14px !important;
  border-radius: 10px !important;
  border: 1.5px solid #D8C7B5 !important;
  font-size: 14px !important;
  color: #1E1224 !important;
  background: #FFFFFF !important;
  box-sizing: border-box !important;
  outline: none !important;
  font-family: inherit !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
}
.kfs-confirm-input:focus {
  border-color: #C97B3D !important;
  box-shadow: 0 0 0 3px rgba(201, 123, 61, 0.18) !important;
}
.kfs-confirm-eye-btn {
  position: absolute !important;
  right: 8px !important;
  top: 50% !important;
  transform: translateY(-50%) !important;
  background: transparent !important;
  border: none !important;
  cursor: pointer !important;
  color: #8B4513 !important;
  padding: 6px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  border-radius: 6px !important;
  transition: color 0.15s ease !important;
}
.kfs-confirm-eye-btn:hover {
  color: #1E1224 !important;
}
.kfs-confirm-alert {
  background: #FEF2F2 !important;
  border: 1px solid #FCA5A5 !important;
  color: #DC2626 !important;
  border-radius: 10px !important;
  padding: 10px 14px !important;
  font-size: 12.5px !important;
  font-weight: 500 !important;
  margin: 12px 0 0 0 !important;
  line-height: 1.4 !important;
}
.kfs-confirm-actions {
  display: flex !important;
  justify-content: flex-end !important;
  align-items: center !important;
  gap: 10px !important;
  margin-top: 22px !important;
  padding-top: 16px !important;
  border-top: 1px solid #F3EDE6 !important;
}
.kfs-confirm-btn-cancel {
  height: 40px !important;
  padding: 0 20px !important;
  border: 1px solid #D8C7B5 !important;
  border-radius: 10px !important;
  background: #FBF3E9 !important;
  color: #241A2E !important;
  font-family: inherit !important;
  font-size: 13px !important;
  font-weight: 600 !important;
  cursor: pointer !important;
  transition: all 0.15s ease !important;
}
.kfs-confirm-btn-cancel:hover {
  background: #EDE2D3 !important;
}
.kfs-confirm-btn-submit {
  height: 40px !important;
  padding: 0 24px !important;
  border: 1px solid #241A2E !important;
  border-radius: 10px !important;
  background: #241A2E !important;
  color: #FFFFFF !important;
  font-family: inherit !important;
  font-size: 13px !important;
  font-weight: 600 !important;
  cursor: pointer !important;
  transition: all 0.15s ease !important;
  box-shadow: 0 4px 14px rgba(36, 26, 46, 0.25) !important;
}
.kfs-confirm-btn-submit:hover {
  background: #382A45 !important;
  border-color: #382A45 !important;
}
`;
            target.appendChild(style);
        } catch (e) {}
    }

    function confirmPassword() {
        if (confirmation) return confirmation;
        confirmation = new Promise((resolve, reject) => {
            ensureConfirmStyles();
            const dialog = document.createElement('dialog');
            dialog.className = 'kfs-confirm-dialog';
            dialog.innerHTML = '<div class="kfs-confirm-dialog-card" style="padding:0;box-sizing:border-box;display:flex;flex-direction:column;background:#FFFFFF;border-radius:20px;overflow:hidden;font-family:\'Poppins\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;"><div class="kfs-confirm-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:20px 24px;background:linear-gradient(165deg,#241A2E 0%,#181120 100%);border-bottom:1px solid rgba(251,243,233,0.1);color:#FBF3E9;"><div class="kfs-confirm-header-left" style="display:flex;align-items:center;gap:14px;"><div class="kfs-confirm-icon-badge" style="width:40px;height:40px;border-radius:12px;background:rgba(251,243,233,0.12);border:1px solid rgba(251,243,233,0.22);color:#E6A25C;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div><div><h2 class="kfs-confirm-title" style="margin:0;font-size:16px;font-weight:700;color:#FFFFFF;line-height:1.25;letter-spacing:-0.01em;">Confirm Your Password</h2><p class="kfs-confirm-subtitle" style="margin:3px 0 0 0;font-size:12px;color:rgba(251,243,233,0.72);line-height:1.4;">Security verification required to proceed.</p></div></div><button type="button" class="kfs-confirm-close-btn" data-close aria-label="Close dialog" style="background:transparent;border:none;cursor:pointer;color:rgba(251,243,233,0.65);padding:6px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div><form method="dialog" class="kfs-confirm-form" style="display:flex;flex-direction:column;padding:22px 24px 24px;margin:0;background:#FFFFFF;"><div class="kfs-confirm-field" style="display:flex;flex-direction:column;"><label class="kfs-confirm-label" for="kfs-confirm-pw-input" style="display:block;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#52434F;margin-bottom:8px;">Current Password</label><div class="kfs-confirm-input-wrap" style="position:relative;display:flex;align-items:center;width:100%;"><input id="kfs-confirm-pw-input" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required class="kfs-confirm-input" style="width:100%;height:44px;padding:0 44px 0 14px;border-radius:10px;border:1.5px solid #D8C7B5;font-size:14px;color:#1E1224;background:#FFFFFF;box-sizing:border-box;outline:none;font-family:inherit;"><button type="button" class="kfs-confirm-eye-btn" aria-label="Toggle password visibility" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;color:#8B4513;padding:6px;display:flex;align-items:center;justify-content:center;border-radius:6px;"><svg class="kfs-eye-show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><svg class="kfs-eye-hide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button></div></div><p role="alert" class="kfs-confirm-alert" style="display:none;background:#FEF2F2;border:1px solid #FCA5A5;color:#DC2626;border-radius:10px;padding:10px 14px;font-size:12.5px;font-weight:500;margin:12px 0 0 0;line-height:1.4;"></p><div class="kfs-confirm-actions" style="display:flex;justify-content:flex-end;align-items:center;gap:10px;margin-top:22px;padding-top:16px;border-top:1px solid #F3EDE6;"><button type="button" data-cancel class="kfs-confirm-btn-cancel" style="height:40px;padding:0 20px;border:1px solid #D8C7B5;border-radius:10px;background:#FBF3E9;color:#241A2E;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button><button type="submit" class="kfs-confirm-btn-submit" style="height:40px;padding:0 24px;border:1px solid #241A2E;border-radius:10px;background:#241A2E;color:#FFFFFF;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 4px 14px rgba(36,26,46,0.25);">Continue</button></div></form></div>';
            if (dialog.style) dialog.style.cssText = 'position:fixed!important;inset:auto!important;top:50%!important;left:50%!important;transform:translate(-50%,-50%)!important;margin:0!important;padding:0!important;border:1px solid rgba(36,26,46,0.2)!important;border-radius:20px!important;background:#FFFFFF!important;box-shadow:0 24px 64px rgba(24,17,32,0.38)!important;width:min(440px,calc(100vw - 32px))!important;max-width:440px!important;z-index:10002!important;overflow:hidden!important;box-sizing:border-box!important;outline:none!important;';
            document.body.append(dialog);
            dialog.querySelector('[data-cancel]').addEventListener('click', () => dialog.close());
            const closeBtn = dialog.querySelector('[data-close]');
            if (closeBtn) closeBtn.addEventListener('click', () => dialog.close());
            const eyeBtn = dialog.querySelector('.kfs-confirm-eye-btn');
            if (eyeBtn) {
                eyeBtn.addEventListener('click', () => {
                    const input = dialog.querySelector('input');
                    const showSvg = eyeBtn.querySelector('.kfs-eye-show');
                    const hideSvg = eyeBtn.querySelector('.kfs-eye-hide');
                    if (input && input.type === 'password') {
                        input.type = 'text';
                        if (showSvg) showSvg.style.display = 'none';
                        if (hideSvg) hideSvg.style.display = 'inline';
                    } else if (input) {
                        input.type = 'password';
                        if (showSvg) showSvg.style.display = 'inline';
                        if (hideSvg) hideSvg.style.display = 'none';
                    }
                });
            }
            dialog.addEventListener('close', () => { dialog.remove(); reject(new Error('Password confirmation cancelled.')); }, { once: true });
            dialog.querySelector('form').addEventListener('submit', async event => {
                event.preventDefault();
                const input = dialog.querySelector('input');
                try {
                    const response = await nativeFetch(new URL('api/reauthenticate.php', base), {
                        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token() },
                        body: JSON.stringify({ password: input.value }), credentials: 'same-origin',
                    });
                    input.value = '';
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.error || 'Password could not be verified.');
                    dialog.remove();
                    resolve();
                } catch (error) {
                    const alertEl = dialog.querySelector('[role="alert"]');
                    if (alertEl) { alertEl.textContent = error.message; alertEl.style.display = 'block'; }
                }
            });
            dialog.showModal();
            dialog.querySelector('input').focus();
        }).finally(() => { confirmation = null; });
        return confirmation;
    }

    window.fetch = async (input, options = {}) => {
        const url = new URL(typeof input === 'string' || input instanceof URL ? input : input.url, location.href);
        const method = (options.method || input.method || 'GET').toUpperCase();
        const localMutation = url.origin === location.origin && !['GET', 'HEAD', 'OPTIONS'].includes(method);
        if (!localMutation) return nativeFetch(input, options);
        const financial = /\/(checkout|save_order|place_order|create_paymongo_checkout|payroll)\.php$/.test(url.pathname);
        const request = new Request(input instanceof Request ? input : url, options);
        let operation = null;
        request.headers.set('X-CSRF-Token', token());
        if (financial && !request.headers.has('Idempotency-Key')) {
            operation = await operationIdentity(request);
            request.headers.set('Idempotency-Key', operationKey(operation));
        }
        // Keep an unconsumed copy for the retry after password confirmation.
        let response = await nativeFetch(request.clone());
        if (response.status === 428) {
            await confirmPassword();
            response = await nativeFetch(request.clone());
        }
        if (operation !== null && response.ok && financial) {
            const result = await response.clone().json();
            if ((result.success || result.ok) && !result.pending) {
                operations.delete(operation);
                saveOperations();
            }
        }
        return response;
    };

    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.closest('dialog')) return;
        const method = (event.submitter?.getAttribute('formmethod') || form.method).toUpperCase();
        if (method !== 'POST') return;
        const url = new URL(event.submitter?.getAttribute('formaction') || form.action || location.href, location.href);
        if (url.origin !== location.origin) {
            for (const field of form.querySelectorAll('[name="_csrf"], [name="csrf_token"]')) field.remove();
            return;
        }
        if (!form.querySelector('[name="_csrf"]')) {
            const field = document.createElement('input');
            field.type = 'hidden'; field.name = '_csrf'; field.value = token(); form.append(field);
        }
        const sensitiveRoute = /\/(manage_users|manage_permissions|payments|suppliers)\.php$/.test(url.pathname);
        const action = new FormData(form).get('action');
        const sensitiveAction = ['request_payment_change', 'hr_review_request', 'approve', 'release', 'release_individual', 'batch_release_selected', 'finance_review', 'paymongo_dispatch_payout', 'paymongo_retry_item', 'update_settings', 'update_payslip_payment_method'].includes(action);
        if ((sensitiveRoute || sensitiveAction) && !form.dataset.passwordConfirmed) {
            event.preventDefault();
            event.stopImmediatePropagation();
            try { await confirmPassword(); form.dataset.passwordConfirmed = 'true'; form.requestSubmit(event.submitter); }
            catch (error) { /* Cancellation leaves the form available to retry. */ }
        }
    }, true);

    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[href]');
        if (!link) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !/\/auth\/logout\.php$/.test(url.pathname)) return;
        event.preventDefault();
        const form = document.createElement('form'); form.method = 'post'; form.action = link.href;
        const field = document.createElement('input'); field.type = 'hidden'; field.name = '_csrf'; field.value = token(); form.append(field);
        document.body.append(form); form.submit();
    });

    window.kofeePrivateFileUrl = path => path && path.startsWith('uploads/')
        ? new URL('php/download_file.php?f=' + encodeURIComponent(path), base).href : path;
})();
