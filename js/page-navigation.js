(() => {
    const root = document.documentElement;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const transitionDuration = 150;
    let navigating = false;

    root.classList.add('page-navigation-ready');

    document.addEventListener('click', (event) => {
        if (
            event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
            || reducedMotion.matches
            || navigating
        ) {
            return;
        }

        const link = event.target.closest('a[href]');
        if (
            !link
            || link.target
            || link.hasAttribute('download')
            || link.hasAttribute('data-no-transition')
            || link.getAttribute('href')?.startsWith('javascript:')
        ) {
            return;
        }

        const destination = new URL(link.href, window.location.href);
        if (destination.origin !== window.location.origin) return;
        if (
            destination.pathname === window.location.pathname
            && destination.search === window.location.search
            && destination.hash
        ) {
            return;
        }

        event.preventDefault();
        navigating = true;
        root.classList.add('page-navigation-leaving');
        window.setTimeout(() => {
            window.location.assign(destination.href);
        }, transitionDuration);
    });
})();
