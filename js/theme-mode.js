(() => {
    const storageKey = 'ecommerce-theme-mode';
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    const savedMode = localStorage.getItem(storageKey);
    let mode = ['light', 'dark', 'system'].includes(savedMode) ? savedMode : 'system';

    const applyTheme = () => {
        const theme = mode === 'system' ? (mediaQuery.matches ? 'dark' : 'light') : mode;
        document.documentElement.dataset.theme = theme;
        document.documentElement.style.colorScheme = theme;
        if (document.body) document.body.dataset.theme = theme;
    };
    applyTheme();

    let selects = Array.from(document.querySelectorAll('[data-theme-mode]'));
    if (selects.length === 0 && window.self === window.top) {
        const control = document.createElement('div');
        control.className = 'theme-mode-control theme-mode-floating';
        const label = document.createElement('label');
        label.className = 'theme-mode-label';
        label.htmlFor = 'themeModeFallback';
        label.textContent = 'Theme';

        const select = document.createElement('select');
        select.id = 'themeModeFallback';
        select.setAttribute('aria-label', 'Color theme');
        select.innerHTML = '<option value="system">System</option><option value="light">Light</option><option value="dark">Dark</option>';
        control.append(label, select);
        selects = [select];
        document.addEventListener('DOMContentLoaded', () => {
            document.body.append(control);
        }, { once: true });
    }

    selects.forEach((select) => {
        select.value = mode;
        select.addEventListener('change', () => {
            mode = select.value;
            localStorage.setItem(storageKey, mode);
            selects.forEach((control) => {
                control.value = mode;
            });
            applyTheme();
        });
    });
    mediaQuery.addEventListener('change', () => {
        if (mode === 'system') applyTheme();
    });
})();
