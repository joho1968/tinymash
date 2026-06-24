(() => {
    'use strict';

    const itemSelector = '[data-tm-photos-lightbox-item]';
    const items = Array.from(document.querySelectorAll(itemSelector))
        .filter((item) => item instanceof HTMLAnchorElement);
    if (items.length === 0) {
        return;
    }

    let activeIndex = 0;
    let previousFocus = null;
    let pointerStartX = 0;
    let slideshowTimer = null;
    let slideshowRunning = false;

    const iconFallbacks = {
        'bi-info-circle': 'i',
        'bi-play-fill': '▶',
        'bi-pause-fill': 'Ⅱ',
        'bi-download': '↓',
        'bi-x-lg': '×',
        'bi-chevron-left': '‹',
        'bi-chevron-right': '›',
    };

    const createIcon = (iconName) => {
        const icon = document.createElement('span');
        icon.className = 'tm-photos-lightbox__icon';
        icon.setAttribute('aria-hidden', 'true');

        const bootstrapIcon = document.createElement('span');
        bootstrapIcon.className = 'tm-photos-lightbox__icon-bi bi ' + iconName;

        const fallbackIcon = document.createElement('span');
        fallbackIcon.className = 'tm-photos-lightbox__icon-fallback';
        fallbackIcon.textContent = iconFallbacks[iconName] || '';

        icon.append(bootstrapIcon, fallbackIcon);
        return icon;
    };

    const createButton = (className, label, iconName) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'tm-photos-lightbox__button ' + className;
        button.title = label;
        button.setAttribute('aria-label', label);
        button.appendChild(createIcon(iconName));
        return button;
    };

    const createLinkButton = (className, label, iconName) => {
        const link = document.createElement('a');
        link.className = 'tm-photos-lightbox__button ' + className;
        link.title = label;
        link.setAttribute('aria-label', label);
        link.setAttribute('download', '');
        link.appendChild(createIcon(iconName));
        return link;
    };

    const setButtonIcon = (button, iconName) => {
        const icon = button.querySelector('.tm-photos-lightbox__icon');
        if (icon) {
            const bootstrapIcon = icon.querySelector('.tm-photos-lightbox__icon-bi');
            if (bootstrapIcon) {
                bootstrapIcon.className = 'tm-photos-lightbox__icon-bi bi ' + iconName;
            }
            const fallbackIcon = icon.querySelector('.tm-photos-lightbox__icon-fallback');
            if (fallbackIcon) {
                fallbackIcon.textContent = iconFallbacks[iconName] || '';
            }
        }
    };

    const lightbox = document.createElement('div');
    lightbox.className = 'tm-photos-lightbox';
    lightbox.setAttribute('role', 'dialog');
    lightbox.setAttribute('aria-modal', 'true');
    lightbox.setAttribute('aria-label', 'Photo viewer');
    lightbox.hidden = true;

    const topBar = document.createElement('div');
    topBar.className = 'tm-photos-lightbox__bar';

    const titleWrap = document.createElement('div');
    titleWrap.className = 'tm-photos-lightbox__title-wrap';

    const entryTitle = document.createElement('div');
    entryTitle.className = 'tm-photos-lightbox__entry-title';
    entryTitle.hidden = true;

    const caption = document.createElement('div');
    caption.className = 'tm-photos-lightbox__caption';
    titleWrap.append(entryTitle, caption);

    const count = document.createElement('div');
    count.className = 'tm-photos-lightbox__count';

    const detailsButton = createButton('', 'Show details', 'bi-info-circle');
    detailsButton.setAttribute('aria-expanded', 'false');

    const slideshowButton = createButton('', 'Start slideshow', 'bi-play-fill');
    slideshowButton.hidden = true;

    const downloadButton = createLinkButton('', 'Download image', 'bi-download');
    downloadButton.hidden = true;

    const closeButton = createButton('', 'Close', 'bi-x-lg');

    topBar.append(titleWrap, count, detailsButton, slideshowButton, downloadButton, closeButton);

    const body = document.createElement('div');
    body.className = 'tm-photos-lightbox__body';

    const stage = document.createElement('div');
    stage.className = 'tm-photos-lightbox__stage';

    const figure = document.createElement('figure');
    figure.className = 'tm-photos-lightbox__figure';

    let image = document.createElement('img');
    image.className = 'tm-photos-lightbox__image';
    image.decoding = 'async';
    image.alt = '';

    figure.appendChild(image);

    const previousButton = createButton('tm-photos-lightbox__nav tm-photos-lightbox__nav--prev', 'Previous photo', 'bi-chevron-left');
    const nextButton = createButton('tm-photos-lightbox__nav tm-photos-lightbox__nav--next', 'Next photo', 'bi-chevron-right');

    stage.append(figure, previousButton, nextButton);

    const detailsPanel = document.createElement('aside');
    detailsPanel.className = 'tm-photos-lightbox__details';
    detailsPanel.hidden = true;

    const detailsTitle = document.createElement('h2');
    detailsTitle.className = 'tm-photos-lightbox__details-title';
    detailsTitle.textContent = 'Details';

    const metadataList = document.createElement('div');
    metadataList.className = 'tm-photos-lightbox__metadata';

    detailsPanel.append(detailsTitle, metadataList);
    body.append(stage, detailsPanel);
    lightbox.append(topBar, body);
    document.body.appendChild(lightbox);

    const getItemGallery = (item) => {
        const gallery = item.closest('[data-tm-photos-gallery]');
        return gallery instanceof HTMLElement ? gallery : null;
    };

    const normalizeSlideshowDelay = (value) => {
        const seconds = parseInt(String(value || ''), 10);
        if (!Number.isFinite(seconds) || seconds < 2) {
            return 2;
        }
        if (seconds > 60) {
            return 60;
        }
        return seconds;
    };

    const readItemData = (item) => {
        const gallery = getItemGallery(item);
        let metadata = [];
        try {
            const parsed = JSON.parse(item.getAttribute('data-tm-photos-metadata') || '[]');
            metadata = Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            metadata = [];
        }

        return {
            src: String(item.getAttribute('data-tm-photos-src') || item.getAttribute('href') || ''),
            label: String(item.getAttribute('data-tm-photos-label') || ''),
            entryTitle: gallery ? String(gallery.getAttribute('data-tm-photos-entry-title') || '') : '',
            entryTitleHtml: gallery ? String(gallery.getAttribute('data-tm-photos-entry-title-html') || '') : '',
            downloadUrl: String(item.getAttribute('data-tm-photos-download-url') || ''),
            slideshowEnabled: gallery ? gallery.getAttribute('data-tm-photos-slideshow-enabled') === '1' : false,
            slideshowDelaySeconds: normalizeSlideshowDelay(gallery ? gallery.getAttribute('data-tm-photos-slideshow-delay-seconds') : ''),
            metadata,
        };
    };

    const clearSlideshowTimer = () => {
        if (slideshowTimer !== null) {
            window.clearTimeout(slideshowTimer);
            slideshowTimer = null;
        }
    };

    const updateSlideshowControl = (data) => {
        const currentData = data || readItemData(items[activeIndex]);
        const available = currentData.slideshowEnabled && items.length > 1;
        slideshowButton.hidden = !available;
        if (!available) {
            slideshowRunning = false;
            clearSlideshowTimer();
        }
        slideshowButton.title = slideshowRunning ? 'Pause slideshow' : 'Start slideshow';
        slideshowButton.setAttribute('aria-label', slideshowRunning ? 'Pause slideshow' : 'Start slideshow');
        setButtonIcon(slideshowButton, slideshowRunning ? 'bi-pause-fill' : 'bi-play-fill');
    };

    const scheduleSlideshow = () => {
        clearSlideshowTimer();
        if (!slideshowRunning || lightbox.hidden || items.length < 2) {
            return;
        }
        const data = readItemData(items[activeIndex]);
        if (!data.slideshowEnabled) {
            slideshowRunning = false;
            updateSlideshowControl(data);
            return;
        }
        slideshowTimer = window.setTimeout(() => {
            showItem(activeIndex + 1, { preserveDetails: true, fromSlideshow: true });
        }, data.slideshowDelaySeconds * 1000);
    };

    const stopSlideshow = () => {
        slideshowRunning = false;
        clearSlideshowTimer();
        updateSlideshowControl();
    };

    const setActiveImage = (data) => {
        const nextImage = document.createElement('img');
        nextImage.className = 'tm-photos-lightbox__image';
        nextImage.decoding = 'async';
        nextImage.alt = data.label;
        nextImage.src = data.src;
        figure.replaceChildren(nextImage);
        image = nextImage;
        if (slideshowRunning) {
            scheduleSlideshow();
        }
    };

    const renderMetadata = (metadata) => {
        metadataList.replaceChildren();
        const rows = metadata
            .map((row) => ({
                group: String(row && row.group ? row.group : 'Image').trim(),
                label: String(row && row.label ? row.label : '').trim(),
                value: String(row && row.value ? row.value : '').trim(),
            }))
            .filter((row) => row.label !== '' && row.value !== '');

        detailsButton.hidden = rows.length === 0;
        if (rows.length === 0) {
            detailsPanel.hidden = true;
            detailsButton.setAttribute('aria-expanded', 'false');
            return;
        }

        const groupedRows = new Map();
        rows.forEach((row) => {
            const group = row.group || 'Image';
            if (!groupedRows.has(group)) {
                groupedRows.set(group, []);
            }
            groupedRows.get(group).push(row);
        });

        groupedRows.forEach((groupRows, group) => {
            const section = document.createElement('section');
            section.className = 'tm-photos-lightbox__metadata-group';

            const groupTitle = document.createElement('div');
            groupTitle.className = 'tm-photos-lightbox__metadata-group-title';
            groupTitle.textContent = group;
            section.appendChild(groupTitle);

            groupRows.forEach((row) => {
                const pair = document.createElement('dl');
                pair.className = 'tm-photos-lightbox__metadata-row';

                const term = document.createElement('dt');
                term.textContent = row.label;

                const description = document.createElement('dd');
                description.textContent = row.value;

                pair.append(term, description);
                section.appendChild(pair);
            });

            metadataList.appendChild(section);
        });
    };

    const setDetailsVisible = (visible) => {
        if (detailsButton.hidden) {
            visible = false;
        }
        detailsPanel.hidden = !visible;
        lightbox.classList.toggle('tm-photos-lightbox--details-open', visible);
        detailsButton.setAttribute('aria-expanded', visible ? 'true' : 'false');
        detailsButton.title = visible ? 'Hide details' : 'Show details';
        detailsButton.setAttribute('aria-label', visible ? 'Hide details' : 'Show details');
    };

    const showItem = (index, options = {}) => {
        const preserveDetails = Boolean(options && options.preserveDetails);
        const detailsWereVisible = !detailsPanel.hidden;
        activeIndex = (index + items.length) % items.length;
        const data = readItemData(items[activeIndex]);
        setActiveImage(data);
        if (data.entryTitleHtml !== '') {
            entryTitle.innerHTML = data.entryTitleHtml;
        } else {
            entryTitle.textContent = data.entryTitle;
        }
        entryTitle.hidden = data.entryTitle === '' && data.entryTitleHtml === '';
        caption.textContent = data.label;
        count.textContent = String(activeIndex + 1) + ' / ' + String(items.length);
        if (data.downloadUrl) {
            downloadButton.href = data.downloadUrl;
            downloadButton.hidden = false;
        } else {
            downloadButton.removeAttribute('href');
            downloadButton.hidden = true;
        }
        previousButton.hidden = items.length < 2;
        nextButton.hidden = items.length < 2;
        renderMetadata(data.metadata);
        setDetailsVisible(preserveDetails && detailsWereVisible);
        updateSlideshowControl(data);
    };

    const open = (index) => {
        previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        lightbox.hidden = false;
        document.body.classList.add('tm-photos-lightbox-open');
        showItem(index);
        closeButton.focus({ preventScroll: true });
    };

    const close = () => {
        stopSlideshow();
        lightbox.hidden = true;
        document.body.classList.remove('tm-photos-lightbox-open');
        image.removeAttribute('src');
        if (previousFocus instanceof HTMLElement) {
            previousFocus.focus({ preventScroll: true });
        }
    };

    const focusableElements = () => Array.from(lightbox.querySelectorAll('button:not([hidden]), a[href]:not([hidden])'))
        .filter((element) => element instanceof HTMLElement && !element.disabled);

    items.forEach((item, index) => {
        item.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            open(index);
        });
    });

    closeButton.addEventListener('click', close);
    previousButton.addEventListener('click', () => showItem(activeIndex - 1, { preserveDetails: true }));
    nextButton.addEventListener('click', () => showItem(activeIndex + 1, { preserveDetails: true }));
    detailsButton.addEventListener('click', () => setDetailsVisible(detailsPanel.hidden));
    slideshowButton.addEventListener('click', () => {
        if (slideshowRunning) {
            stopSlideshow();
            return;
        }
        slideshowRunning = true;
        updateSlideshowControl();
        scheduleSlideshow();
    });

    lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox || event.target === stage) {
            close();
        }
    });

    lightbox.addEventListener('pointerdown', (event) => {
        pointerStartX = event.clientX;
    });

    lightbox.addEventListener('pointerup', (event) => {
        const delta = event.clientX - pointerStartX;
        if (Math.abs(delta) < 48 || items.length < 2) {
            return;
        }
        showItem(delta > 0 ? activeIndex - 1 : activeIndex + 1, { preserveDetails: true });
    });

    document.addEventListener('keydown', (event) => {
        if (lightbox.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key === 'ArrowLeft' && items.length > 1) {
            event.preventDefault();
            showItem(activeIndex - 1, { preserveDetails: true });
            return;
        }
        if (event.key === 'ArrowRight' && items.length > 1) {
            event.preventDefault();
            showItem(activeIndex + 1, { preserveDetails: true });
            return;
        }
        if ((event.key === ' ' || event.key === 'Spacebar') && items.length > 1) {
            const activeElement = document.activeElement;
            if (activeElement instanceof HTMLButtonElement || activeElement instanceof HTMLAnchorElement) {
                return;
            }
            event.preventDefault();
            showItem(activeIndex + 1, { preserveDetails: true });
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }

        const controls = focusableElements();
        if (controls.length === 0) {
            return;
        }

        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
})();
