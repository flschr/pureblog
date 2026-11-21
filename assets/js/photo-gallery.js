const gallery = document.querySelector('[data-photo-gallery]');

if (gallery) {
    const items = gallery.querySelector('[data-photo-gallery-items]');
    const status = gallery.querySelector('[data-photo-gallery-status]');

    gallery.addEventListener('click', async (event) => {
        const link = event.target.closest('[data-photo-gallery-more]');
        if (!link || !items) return;
        event.preventDefault();
        if (link.getAttribute('aria-busy') === 'true') return;
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
            } else {
                link.remove();
            }
            if (status) status.textContent = page.html ? (gallery.dataset.loadedLabel || '') : '';
        } catch (error) {
            window.location.assign(link.href);
        }
    });
}
