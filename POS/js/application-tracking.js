(() => {
    'use strict';
    // If the page already has an application tracking section or modal, do NOT inject a redundant section.
    const existing = document.getElementById('secure-application-tracking') ||
                     document.getElementById('tracking-section') ||
                     document.getElementById('trackModalBackdrop') ||
                     document.getElementById('track');

    const token = new URLSearchParams(location.hash.slice(1)).get('track');

    if (existing) {
        if (token) {
            if (typeof window.openTrackModal === 'function') {
                window.openTrackModal();
            } else if (existing.scrollIntoView) {
                existing.scrollIntoView({ behavior: 'smooth' });
            }
        }
        return;
    }

    const supplier = location.pathname.endsWith('supplier_partnership.php');
    const endpoint = supplier ? 'api/track_supplier_application.php' : 'api/track_application.php';
    const section = document.createElement('section');
    section.id = 'secure-application-tracking';
    section.setAttribute('aria-label', 'Application status');
    section.style.cssText = 'max-width:760px;margin:48px auto;padding:36px;background:#ffffff;border:1.5px solid rgba(217,186,133,0.35);border-radius:24px;box-shadow:0 16px 48px rgba(30,21,23,0.07);font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;color:#1E1517;box-sizing:border-box;';
    
    section.innerHTML = `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:42px;height:42px;border-radius:12px;background:#FAF7F2;border:1.5px solid #D9BA85;display:flex;align-items:center;justify-content:center;color:#D9BA85;flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </div>
            <div>
                <span style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#D9BA85;display:block;">REAL-TIME REVIEW TRACKER</span>
                <h2 style="font-family:'Playfair Display',serif;font-size:22px;color:#1E1517;margin:2px 0 0;font-weight:700;">Application Status</h2>
            </div>
        </div>
        <p role="status" style="font-size:13.5px;color:#68584B;line-height:1.6;margin-bottom:20px;">
            Use your emailed private link to view status. To request a new link, enter your registered email below.
        </p>
        <form method="post" style="background:#FAF7F2;border:1px solid rgba(104,88,75,0.18);border-radius:16px;padding:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <label style="flex:1;min-width:260px;display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:700;color:#1E1517;">
                Registered Email <span style="color:#DC2626;">*</span>
                <input type="email" name="email" autocomplete="email" required placeholder="you@domain.com" style="padding:11px 16px;border:1.5px solid rgba(217,186,133,0.4);border-radius:12px;font-size:13.5px;outline:none;background:#FFFFFF;color:#1E1517;box-sizing:border-box;">
            </label>
            <button type="submit" style="padding:12px 24px;background:linear-gradient(135deg,#1E1517 0%,#2A1D20 100%);color:#D9BA85;border:1px solid rgba(217,186,133,0.4);border-radius:999px;font-size:13px;font-weight:700;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease;display:inline-flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                Email Private Status Link &rarr;
            </button>
        </form>
        <div id="secure-tracking-details" style="display:none;margin-top:20px;"></div>
    `;

    document.body.append(section);
    const message = section.querySelector('[role="status"]');
    const details = section.querySelector('#secure-tracking-details');

    async function request(payload) {
        try {
            message.textContent = 'Verifying private status link…';
            const response = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Unable to retrieve status.');
            if (data.recovery) {
                message.innerHTML = `
                    <div style="background:#ECFDF5;border:1.5px solid #A7F3D0;border-radius:14px;padding:16px;color:#065F46;">
                        <div style="font-weight:700;font-size:13.5px;margin-bottom:2px;display:flex;align-items:center;gap:6px;">
                            ✓ Private Status Link Emailed
                        </div>
                        <p style="margin:0;font-size:12.5px;line-height:1.5;">${data.message || 'Please check your inbox to view your live evaluation status.'}</p>
                    </div>
                `;
                if (details) details.style.display = 'none';
            } else {
                const isApproved = (data.status === 'approved' || data.status === 'hired');
                const isDeclined = (data.status === 'rejected');
                const badgeColor = isApproved ? '#166534' : (isDeclined ? '#991B1B' : '#92400E');
                const badgeBg = isApproved ? '#DCFCE7' : (isDeclined ? '#FEE2E2' : '#FEF3C7');
                const badgeText = isApproved ? 'Approved • Accredited' : (isDeclined ? 'Declined' : 'Under Review');

                message.innerHTML = `
                    <div style="background:#FAF7F2;border:1.5px solid rgba(217,186,133,0.3);border-radius:16px;padding:20px;margin-bottom:8px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                            <div>
                                <span style="font-size:11px;font-weight:700;color:#D9BA85;text-transform:uppercase;letter-spacing:0.04em;">APPLICATION UPDATE</span>
                                <h4 style="margin:2px 0 0;font-size:16px;color:#1E1517;">${data.company_name || data.position_title || 'Application Submission'}</h4>
                            </div>
                            <span style="background:${badgeBg};color:${badgeColor};font-size:12px;font-weight:700;padding:4px 12px;border-radius:999px;">${badgeText}</span>
                        </div>
                        <div style="font-size:12.5px;color:#68584B;">Submitted on: <strong>${data.submitted_at || 'Recently'}</strong></div>
                    </div>
                `;
            }
        } catch (error) {
            message.innerHTML = `<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:12px 16px;border-radius:12px;font-size:13px;">${error.message || 'Unable to retrieve status.'}</div>`;
        }
    }

    section.querySelector('form').addEventListener('submit', event => {
        event.preventDefault();
        request({ action: 'recover', email: new FormData(event.target).get('email') });
    });

    if (token) {
        history.replaceState(null, '', location.pathname + location.search);
        request({ token });
        section.scrollIntoView({ behavior: 'smooth' });
    }
})();
