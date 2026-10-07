/* Navigation is independent of notifications and never changes access checks. */
function toggleSidebarOrMinimize() {
    if (window.innerWidth >= 1024) toggleSidebarMinimize();
    else toggleSidebar();
}

function sidebarBackground() {
    return document.querySelectorAll('.page, .pages, .kfs-approvals-wrap, body > main, #kofee-topbar');
}

function toggleSidebar(force) {
    // Escape closes a drawer; it must never toggle the desktop preference.
    if (window.innerWidth >= 1024) return;
    const panel = document.getElementById('main-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    if (!panel || !backdrop) return;
    const willOpen = typeof force === 'boolean' ? force : !panel.classList.contains('open');
    const wasOpen = panel.classList.contains('open');
    panel.inert = !willOpen;
    panel.classList.toggle('open', willOpen);
    panel.classList.toggle('translate-x-0', willOpen);
    panel.classList.toggle('-translate-x-full', !willOpen);
    backdrop.classList.toggle('opacity-0', !willOpen);
    backdrop.classList.toggle('pointer-events-none', !willOpen);
    backdrop.classList.toggle('opacity-100', willOpen);
    backdrop.classList.toggle('pointer-events-auto', willOpen);
    document.body.classList.toggle('overflow-hidden', willOpen);
    sidebarBackground().forEach(element => { element.inert = willOpen; });
    updateCollapseButtonState(document.documentElement.classList.contains('sidebar-minimized'));
    if (willOpen && !wasOpen) panel.querySelector('button, a')?.focus();
    else if (wasOpen && !willOpen) document.getElementById('sidebar-menu-btn')?.focus();
}

function toggleSidebarMinimize() {
    const isMin = document.documentElement.classList.toggle('sidebar-minimized');
    document.body.classList.toggle('sidebar-minimized', isMin);
    try {
        localStorage.setItem('kfs_sidebar_minimized', String(isMin));
    } catch (error) {
        // Browser storage can be unavailable; the current session still works.
    }
    updateCollapseButtonState(isMin);
    document.getElementById(isMin ? 'sidebar-menu-btn' : 'sidebar-collapse-btn')?.focus();
}

function updateCollapseButtonState(isMin) {
    const desktop = window.innerWidth >= 1024;
    const panel = document.getElementById('main-sidebar');
    const expanded = desktop ? !isMin : Boolean(panel?.classList.contains('open'));
    const label = document.getElementById('menu-btn-label');
    const button = document.getElementById('sidebar-menu-btn');
    if (label) label.textContent = desktop ? (isMin ? 'Expand' : 'Collapse') : 'Menu';
    if (button) {
        button.setAttribute('aria-expanded', String(expanded));
        button.setAttribute('aria-label', desktop ? (isMin ? 'Expand navigation' : 'Collapse navigation') : 'Open navigation');
    }
    const collapse = document.getElementById('sidebar-collapse-btn');
    if (collapse) {
        collapse.setAttribute('aria-label', desktop ? 'Collapse navigation' : 'Close navigation');
        collapse.title = desktop ? 'Collapse navigation' : 'Close navigation';
    }
}

function setSidebarCategory(group, open) {
    group.classList.toggle('is-open', open);
    group.querySelector('.kfs-cat-header')?.setAttribute('aria-expanded', String(open));
    const content = group.querySelector('.kfs-cat-body');
    if (content) content.inert = !open;
}

function toggleSidebarCategory(catId) {
    const group = document.getElementById('cat-group-' + catId);
    if (!group) return;
    const open = !group.classList.contains('is-open');
    setSidebarCategory(group, open);
    try {
        const saved = JSON.parse(localStorage.getItem('kfs_open_cats') || '{}');
        saved[catId] = open;
        localStorage.setItem('kfs_open_cats', JSON.stringify(saved));
    } catch (error) {
        // A saved preference is optional and must not block navigation.
    }
}

function syncSidebarViewport() {
    const panel = document.getElementById('main-sidebar');
    if (!panel) return;
    if (window.innerWidth >= 1024) {
        panel.inert = false;
        if (panel.classList.contains('open')) {
            panel.classList.remove('open', 'translate-x-0');
            document.body.classList.remove('overflow-hidden');
            sidebarBackground().forEach(element => { element.inert = false; });
        }
    } else {
        panel.inert = !panel.classList.contains('open');
    }
    updateCollapseButtonState(document.documentElement.classList.contains('sidebar-minimized'));
}

document.addEventListener('DOMContentLoaded', () => {
    document.body.classList.toggle('sidebar-minimized', document.documentElement.classList.contains('sidebar-minimized'));
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem('kfs_open_cats') || '{}') || {}; } catch (error) {
        // Corrupt or unavailable storage uses the server-rendered defaults.
    }
    document.querySelectorAll('.kfs-cat-group').forEach(group => {
        const key = group.id.replace('cat-group-', '');
        const open = group.classList.contains('has-active-child') ||
            (typeof saved[key] === 'boolean' ? saved[key] : group.classList.contains('is-open'));
        setSidebarCategory(group, open);
    });
    syncSidebarViewport();
});
window.addEventListener('resize', syncSidebarViewport);
document.addEventListener('keydown', event => {
    const panel = document.getElementById('main-sidebar');
    if (window.innerWidth >= 1024 || !panel?.classList.contains('open')) return;
    if (event.key === 'Escape') toggleSidebar(false);
    if (event.key === 'Tab') {
        const controls = Array.from(panel.querySelectorAll('button, a[href]')).filter(element => !element.closest('[inert]') && element.getClientRects().length > 0);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
});
