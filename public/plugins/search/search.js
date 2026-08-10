(() => {
    'use strict';

    const root = document.querySelector('[data-tm-static-search]');
    if (!root) {
        return;
    }

    const status = root.querySelector('[data-tm-static-search-status]');
    const results = root.querySelector('[data-tm-static-search-results]');
    const pagination = root.querySelector('[data-tm-static-search-pagination]');
    const minimumLength = Math.max(2, Number.parseInt(root.dataset.minQueryLength || '2', 10));
    const params = new URLSearchParams(window.location.search);
    const query = (params.get('q') || '').trim();
    const page = Math.max(1, Number.parseInt(params.get('page') || '1', 10));
    const normalize = (value) => String(value || '').normalize('NFKC').toLocaleLowerCase();

    document.querySelectorAll('[data-tm-search-input]').forEach((input) => {
        input.value = query;
    });

    const setStatus = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    const appendResult = (item) => {
        const article = document.createElement('article');
        article.className = 'tm-static-search-result';

        const heading = document.createElement('h2');
        heading.className = 'tm-static-search-result-title';
        const link = document.createElement('a');
        link.href = String(item.url || '');
        link.textContent = String(item.title || 'Untitled');
        heading.append(link);
        article.append(heading);

        const metaParts = [];
        if (item.author_label) {
            metaParts.push(`by ${item.author_label}`);
        }
        if (item.published_at) {
            metaParts.push(String(item.published_at));
        }
        if (metaParts.length > 0) {
            const meta = document.createElement('p');
            meta.className = 'tm-static-search-result-meta';
            meta.textContent = metaParts.join(' | ');
            article.append(meta);
        }

        if (item.summary) {
            const summary = document.createElement('p');
            summary.className = 'tm-static-search-result-summary';
            summary.textContent = String(item.summary);
            article.append(summary);
        }

        results?.append(article);
    };

    const pageUrl = (pageNumber) => {
        const url = new URL(window.location.href);
        url.searchParams.set('q', query);
        if (pageNumber > 1) {
            url.searchParams.set('page', String(pageNumber));
        } else {
            url.searchParams.delete('page');
        }
        return url.href;
    };

    const renderPagination = (currentPage, totalPages) => {
        if (!pagination || totalPages < 2) {
            return;
        }
        pagination.hidden = false;
        if (currentPage > 1) {
            const previous = document.createElement('a');
            previous.href = pageUrl(currentPage - 1);
            previous.textContent = 'Previous';
            pagination.append(previous);
        }
        const position = document.createElement('span');
        position.className = 'text-body-secondary';
        position.textContent = `Page ${currentPage} of ${totalPages}`;
        pagination.append(position);
        if (currentPage < totalPages) {
            const next = document.createElement('a');
            next.href = pageUrl(currentPage + 1);
            next.textContent = 'Next';
            pagination.append(next);
        }
    };

    if (Array.from(query).length < minimumLength) {
        setStatus(`Enter at least ${minimumLength} characters to search.`);
        return;
    }

    setStatus('Searching...');
    fetch(root.dataset.indexUrl || '', { credentials: 'same-origin' })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`Search index returned ${response.status}`);
            }
            return response.json();
        })
        .then((index) => {
            const terms = normalize(query).split(/\s+/u).filter(Boolean);
            const matches = Array.isArray(index.items)
                ? index.items.filter((item) => terms.every((term) => normalize(item.search_text).includes(term)))
                : [];
            const perPage = Math.max(1, Number.parseInt(index.results_per_page || '10', 10));
            const totalPages = Math.max(1, Math.ceil(matches.length / perPage));
            const currentPage = Math.min(page, totalPages);
            const visible = matches.slice((currentPage - 1) * perPage, currentPage * perPage);

            results?.replaceChildren();
            visible.forEach(appendResult);
            if (matches.length === 0) {
                setStatus(`No matches for "${query}".`);
                return;
            }

            setStatus(`Showing ${visible.length} of ${matches.length} matches for "${query}".`);
            renderPagination(currentPage, totalPages);
        })
        .catch(() => {
            setStatus('Search is unavailable in this export.');
        });
})();
