(function () {
    'use strict';

    function initializeCalendar(calendar) {
        const panels = Array.from(calendar.querySelectorAll('[data-tm-calendar-panel]'));
        const previous = calendar.querySelector('[data-tm-calendar-prev]');
        const next = calendar.querySelector('[data-tm-calendar-next]');
        if (panels.length < 2 || !previous || !next) {
            return;
        }

        const setDisabled = function (control, disabled) {
            if (control.tagName === 'BUTTON') {
                control.disabled = disabled;
            }
            control.classList.toggle('disabled', disabled);
            control.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            control.setAttribute('tabindex', disabled ? '-1' : '0');
        };

        let current = Number.parseInt(calendar.getAttribute('data-current-panel') || '0', 10);
        if (!Number.isInteger(current) || current < 0 || current >= panels.length) {
            current = 0;
        }
        const display = function (index) {
            current = Math.max(0, Math.min(panels.length - 1, index));
            panels.forEach(function (panel, panelIndex) {
                const hidden = panelIndex !== current;
                panel.hidden = hidden;
                panel.classList.toggle('d-none', hidden);
            });
            calendar.setAttribute('data-current-panel', String(current));
            setDisabled(previous, current === 0);
            setDisabled(next, current === panels.length - 1);
        };
        previous.addEventListener('click', function (event) {
            event.preventDefault();
            if (previous.getAttribute('aria-disabled') === 'true') {
                return;
            }
            display(current - 1);
        });
        next.addEventListener('click', function (event) {
            event.preventDefault();
            if (next.getAttribute('aria-disabled') === 'true') {
                return;
            }
            display(current + 1);
        });
        display(current);
    }

    document.querySelectorAll('[data-tm-whats-up-calendar]').forEach(initializeCalendar);
}());
