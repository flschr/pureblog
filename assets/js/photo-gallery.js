const gallery = document.querySelector('[data-photo-gallery]');

if (gallery) {
    const items = gallery.querySelector('[data-photo-gallery-items]');
    const status = gallery.querySelector('[data-photo-gallery-status]');
    let observer = null;

    const loadPage = async (link, navigateOnError) => {
        if (link.getAttribute('aria-busy') === 'true') return;
        if (observer) observer.unobserve(link);
        link.setAttribute('aria-busy', 'true');
        link.textContent = '…';
        try {
            const url = new URL(link.href);
            url.searchParams.set('partial', '1');
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Gallery request failed');
            const page = await response.json();
            items.insertAdjacentHTML('beforeend', page.html || '');
            if (page.next_url) {
                link.href = page.next_url;
                link.textContent = link.dataset.label || '';
                link.removeAttribute('aria-busy');
                if (observer) requestAnimationFrame(() => observer.observe(link));
            } else {
                link.remove();
            }
            if (status) status.textContent = page.html ? (gallery.dataset.loadedLabel || '') : '';
        } catch {
            link.textContent = link.dataset.label || '';
            link.removeAttribute('aria-busy');
            if (navigateOnError) window.location.assign(link.href);
        }
    };

    gallery.addEventListener('click', (event) => {
        const link = event.target.closest('[data-photo-gallery-more]');
        if (!link || !items) return;
        event.preventDefault();
        void loadPage(link, true);
    });

    if (items && 'IntersectionObserver' in window) {
        observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) void loadPage(entry.target, false);
            }
        }, { rootMargin: '800px 0px' });
        const link = gallery.querySelector('[data-photo-gallery-more]');
        if (link) observer.observe(link);
    }
}
