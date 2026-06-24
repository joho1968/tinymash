(() => {
    'use strict';

    function resolveLanguage(block) {
        const resultLanguage = block && block.result && typeof block.result.language === 'string'
            ? block.result.language
            : '';
        if (resultLanguage !== '') {
            return resultLanguage;
        }

        const className = typeof block.className === 'string' ? block.className : '';
        const match = className.match(/(?:^|\s)language-([a-z0-9_+-]+)/i);
        return match ? String(match[1] || '').toLowerCase() : '';
    }

    function fallbackCopyText(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.top = '-1000px';
        textarea.style.left = '-1000px';
        document.body.appendChild(textarea);
        textarea.select();

        let copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }

        textarea.remove();
        return copied;
    }

    function copyTextToClipboard(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(text).then(() => true).catch(() => fallbackCopyText(text));
        }

        return Promise.resolve(fallbackCopyText(text));
    }

    function setCopyButtonState(button, copied) {
        const icon = button.querySelector('.bi');
        const text = button.querySelector('.tm-syntax-copy-text');
        button.classList.toggle('tm-syntax-copy-button-copied', copied);
        button.setAttribute('title', copied ? 'Copied' : 'Copy');
        button.setAttribute('aria-label', copied ? 'Copied' : 'Copy');
        if (icon instanceof HTMLElement) {
            icon.className = copied ? 'bi bi-check2' : 'bi bi-copy';
        }
        if (text instanceof HTMLElement) {
            text.textContent = copied ? 'Copied' : 'Copy';
        }
    }

    function mountCopyButton(frame, block) {
        if (!(frame instanceof HTMLElement) || frame.querySelector('.tm-syntax-copy-button')) {
            return;
        }

        const button = document.createElement('button');
        button.className = 'tm-syntax-copy-button';
        button.type = 'button';
        button.setAttribute('title', 'Copy');
        button.setAttribute('aria-label', 'Copy');
        button.innerHTML = '<i class="bi bi-copy" aria-hidden="true"></i><span class="tm-syntax-copy-text">Copy</span>';
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            copyTextToClipboard(block.textContent || '').then((copied) => {
                setCopyButtonState(button, copied);
                window.setTimeout(() => setCopyButtonState(button, false), 1400);
            });
        });
        frame.insertBefore(button, frame.firstChild);
    }

    function mountSyntaxHighlighting() {
        if (typeof window.hljs === 'undefined') {
            return;
        }

        const roots = Array.from(document.querySelectorAll('[data-tm-syntax-root]'));
        roots.forEach((root) => {
            const codeBlocks = Array.from(root.querySelectorAll('pre code'));
            codeBlocks.forEach((block) => {
                if (!(block instanceof HTMLElement) || block.dataset.tmSyntaxHighlighted === '1') {
                    return;
                }

                window.hljs.highlightElement(block);
                block.dataset.tmSyntaxHighlighted = '1';

                const frame = block.parentElement instanceof HTMLElement ? block.parentElement : null;
                if (frame instanceof HTMLElement) {
                    frame.classList.add('tm-syntax-frame');
                    mountCopyButton(frame, block);
                }

                if (root.getAttribute('data-tm-syntax-language-labels') !== '1' || !(frame instanceof HTMLElement) || frame.querySelector('.tm-syntax-language-label')) {
                    return;
                }

                const language = resolveLanguage(block).replace(/[^a-z0-9_+-]/gi, '');
                if (language === '') {
                    return;
                }

                const labelClass = (root.getAttribute('data-tm-syntax-language-label-class') || '').trim();
                const label = document.createElement('label');
                if (labelClass !== '') {
                    label.className = labelClass;
                }
                label.setAttribute('aria-hidden', 'true');
                label.textContent = language;
                frame.insertBefore(label, block);
                frame.classList.add('tm-syntax-frame-has-label');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountSyntaxHighlighting, { once: true });
    } else {
        mountSyntaxHighlighting();
    }
})();
