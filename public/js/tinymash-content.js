(() => {
    'use strict';

    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element
            ? event.target.closest('[data-tm-embed-provider="youtube"][data-tm-embed-id]')
            : null;
        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }

        const videoId = link.dataset.tmEmbedId || '';
        if (!/^[A-Za-z0-9_-]{11}$/.test(videoId)) {
            return;
        }

        const frame = document.createElement('iframe');
        frame.title = 'YouTube video';
        frame.loading = 'lazy';
        frame.referrerPolicy = 'no-referrer';
        frame.allow = 'autoplay; encrypted-media; picture-in-picture';
        frame.allowFullscreen = true;
        frame.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(videoId) + '?autoplay=1';

        event.preventDefault();
        link.replaceWith(frame);
    });
})();
