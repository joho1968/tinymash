(function () {
    'use strict';

    function initializeCalendar(calendar) {
        const panels = Array.from(calendar.querySelectorAll('[data-tm-calendar-panel]'));
        const previous = calendar.querySelector('[data-tm-calendar-prev]');
        const next = calendar.querySelector('[data-tm-calendar-next]');
        if (panels.length < 2 || !previous || !next) {
            return;
        }

        let current = Number.parseInt(calendar.getAttribute('data-current-panel') || '0', 10);
        if (!Number.isInteger(current) || current < 0 || current >= panels.length) {
            current = 0;
        }
        const display = function (index) {
            current = Math.max(0, Math.min(panels.length - 1, index));
            panels.forEach(function (panel, panelIndex) {
                panel.hidden = panelIndex !== current;
            });
            previous.disabled = current === 0;
            next.disabled = current === panels.length - 1;
        };
        previous.addEventListener('click', function () {
            display(current - 1);
        });
        next.addEventListener('click', function () {
            display(current + 1);
        });
        display(current);
    }

    document.querySelectorAll('[data-tm-whats-up-calendar]').forEach(initializeCalendar);
}());
