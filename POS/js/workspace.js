document.addEventListener('DOMContentLoaded', () => {
    const main = document.querySelector('.page, .pages, .kfs-approvals-wrap, body > main');
    if (main) {
        // Preserve existing IDs because screen scripts depend on them.
        const target = document.createElement('span');
        target.id = 'workspace-main';
        target.tabIndex = -1;
        main.prepend(target);
        if (main.tagName !== 'MAIN') main.setAttribute('role', 'main');
        document.querySelector('.kfs-skip-link')?.addEventListener('click', () => target.focus());
    }
    document.querySelectorAll('.table-scroll-wrapper').forEach(wrapper => {
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        const heading = wrapper.closest('.table-card, .recent-section')?.querySelector('h2, h3');
        wrapper.setAttribute('aria-label', (heading?.textContent.trim() || 'Data table') + '. Scroll horizontally to see all columns.');
    });
});
