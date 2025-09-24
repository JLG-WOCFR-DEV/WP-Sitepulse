(function () {
    if (typeof window === 'undefined') {
        return;
    }

    const settings = window.SitePulseDashboardLayout || {};
    const container = document.getElementById('sitepulse-dashboard-grid');

    if (!container || typeof Sortable === 'undefined') {
        return;
    }

    const speak = (message, politeness = 'polite') => {
        if (!message) {
            return;
        }

        if (window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
            wp.a11y.speak(message, politeness);
        }
    };

    let isSaving = false;

    const saveOrder = () => {
        if (!settings.ajaxUrl || !settings.nonce || isSaving) {
            return;
        }

        const order = Array.from(container.querySelectorAll('[data-widget]'))
            .map((card) => card.getAttribute('data-widget'))
            .filter(Boolean);

        if (!order.length) {
            return;
        }

        isSaving = true;
        container.classList.add('is-saving');

        const payload = new URLSearchParams();
        payload.set('action', 'sitepulse_save_dashboard_order');
        payload.set('nonce', settings.nonce);
        payload.set('order', JSON.stringify(order));

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: payload.toString(),
        })
            .then((response) => response.json())
            .then((data) => {
                if (data && data.success) {
                    speak(settings.strings && settings.strings.saved);
                } else {
                    speak(settings.strings && settings.strings.error, 'assertive');
                }
            })
            .catch(() => {
                speak(settings.strings && settings.strings.error, 'assertive');
            })
            .finally(() => {
                isSaving = false;
                container.classList.remove('is-saving');
            });
    };

    Sortable.create(container, {
        animation: 150,
        handle: '.sitepulse-card-handle',
        dragClass: 'sitepulse-card-dragging',
        ghostClass: 'sitepulse-card-ghost',
        onEnd: saveOrder,
    });

    container.addEventListener('keydown', (event) => {
        const handle = event.target.closest('.sitepulse-card-handle');

        if (!handle) {
            return;
        }

        const card = handle.closest('[data-widget]');

        if (!card) {
            return;
        }

        const keys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];

        if (!keys.includes(event.key)) {
            return;
        }

        event.preventDefault();

        const cards = Array.from(container.querySelectorAll('[data-widget]'));
        const index = cards.indexOf(card);

        if (index === -1) {
            return;
        }

        let newIndex = index;

        if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
            newIndex = Math.max(0, index - 1);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
            newIndex = Math.min(cards.length - 1, index + 1);
        }

        if (newIndex === index) {
            return;
        }

        const reference = cards[newIndex];

        if (newIndex < index) {
            container.insertBefore(card, reference);
        } else {
            container.insertBefore(card, reference.nextElementSibling);
        }

        handle.focus();
        saveOrder();
    });
})();
