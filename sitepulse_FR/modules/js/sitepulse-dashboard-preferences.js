(function ($) {
    'use strict';

    const data = window.SitePulsePreferencesData || null;

    if (!data) {
        return;
    }

    const grid = document.querySelector('[data-sitepulse-card-grid]');
    const emptyState = document.querySelector('[data-sitepulse-empty-state]');
    const panel = document.getElementById('sitepulse-preferences-panel');
    const toggleButton = document.querySelector('.sitepulse-preferences__toggle');
    const list = document.querySelector('[data-sitepulse-preferences-list]');
    const notice = panel ? panel.querySelector('.sitepulse-preferences__notice') : null;
    const saveButton = panel ? panel.querySelector('.sitepulse-preferences__save') : null;
    const cancelButton = panel ? panel.querySelector('.sitepulse-preferences__cancel') : null;
    const cardKeys = Object.keys(data.cards || {});
    const sizeKeys = Object.keys(data.sizes || {});
    const $list = list ? $(list) : null;

    if (!panel || !toggleButton || !list) {
        return;
    }

    let savedState = normaliseState(data.preferences || {});
    let currentState = deepClone(savedState);

    initialise();

    function initialise() {
        setControlsFromState(savedState);
        applyStateToGrid(savedState);
        bindEvents();
        updateEmptyState();
    }

    function bindEvents() {
        toggleButton.addEventListener('click', () => {
            if (panel.hasAttribute('hidden')) {
                openPanel();
            } else {
                closePanel();
            }
        });

        if (cancelButton) {
            cancelButton.addEventListener('click', () => {
                currentState = deepClone(savedState);
                setControlsFromState(savedState);
                applyStateToGrid(savedState);
                clearNotice();
                closePanel();
            });
        }

        if (saveButton) {
            saveButton.addEventListener('click', () => {
                currentState = buildStateFromUI();
                persistState(currentState);
            });
        }

        list.addEventListener('change', (event) => {
            const target = event.target;

            if (!target.classList) {
                return;
            }

            if (target.classList.contains('sitepulse-preferences__visibility') || target.classList.contains('sitepulse-preferences__size')) {
                currentState = buildStateFromUI();
                applyStateToGrid(currentState);
            }
        });

        if ($list && typeof $list.sortable === 'function') {
            $list.sortable({
                axis: 'y',
                handle: '.sitepulse-preferences__drag-handle',
                placeholder: 'sitepulse-preferences__placeholder',
                update: () => {
                    currentState = buildStateFromUI();
                    applyStateToGrid(currentState);
                }
            });
        }
    }

    function openPanel() {
        currentState = deepClone(savedState);
        setControlsFromState(savedState);
        clearNotice();
        panel.removeAttribute('hidden');
        toggleButton.setAttribute('aria-expanded', 'true');
        panel.focus();
    }

    function closePanel() {
        panel.setAttribute('hidden', 'hidden');
        toggleButton.setAttribute('aria-expanded', 'false');
    }

    function normaliseState(prefs) {
        const state = {
            order: Array.isArray(prefs.order) ? prefs.order.slice() : [],
            visibility: typeof prefs.visibility === 'object' && prefs.visibility !== null ? { ...prefs.visibility } : {},
            sizes: typeof prefs.sizes === 'object' && prefs.sizes !== null ? { ...prefs.sizes } : {},
        };

        const order = [];

        state.order.forEach((key) => {
            if (cardKeys.indexOf(key) !== -1 && order.indexOf(key) === -1) {
                order.push(key);
            }
        });

        cardKeys.forEach((key) => {
            if (order.indexOf(key) === -1) {
                order.push(key);
            }

            state.visibility[key] = typeof state.visibility[key] !== 'undefined' ? Boolean(state.visibility[key]) : true;

            const size = typeof state.sizes[key] === 'string' ? state.sizes[key] : '';
            state.sizes[key] = sizeKeys.indexOf(size) !== -1 ? size : (data.cards[key] && data.cards[key].defaultSize) || 'medium';
        });

        state.order = order;

        return state;
    }

    function deepClone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function setControlsFromState(state) {
        if (!list) {
            return;
        }

        const fragment = document.createDocumentFragment();

        state.order.forEach((key) => {
            const item = list.querySelector('[data-card-key="' + key + '"]');

            if (!item) {
                return;
            }

            const checkbox = item.querySelector('.sitepulse-preferences__visibility');
            const select = item.querySelector('.sitepulse-preferences__size');

            if (checkbox) {
                checkbox.checked = Boolean(state.visibility[key]);
            }

            if (select) {
                select.value = state.sizes[key] || select.value;
            }

            fragment.appendChild(item);
        });

        list.appendChild(fragment);

        if ($list && typeof $list.sortable === 'function') {
            $list.sortable('refresh');
        }
    }

    function buildStateFromUI() {
        if (!list) {
            return deepClone(savedState);
        }

        const order = [];
        const visibility = {};
        const sizes = {};

        list.querySelectorAll('[data-card-key]').forEach((item) => {
            const key = item.getAttribute('data-card-key');

            if (!key) {
                return;
            }

            order.push(key);

            const checkbox = item.querySelector('.sitepulse-preferences__visibility');
            const select = item.querySelector('.sitepulse-preferences__size');

            visibility[key] = checkbox ? Boolean(checkbox.checked) : true;
            sizes[key] = select && typeof select.value === 'string' && select.value ? select.value : ((data.cards[key] && data.cards[key].defaultSize) || 'medium');
        });

        return normaliseState({ order, visibility, sizes });
    }

    function applyStateToGrid(state) {
        if (!grid) {
            return;
        }

        state.order.forEach((key) => {
            const card = grid.querySelector('[data-card-key="' + key + '"]');

            if (!card) {
                return;
            }

            grid.appendChild(card);

            const size = state.sizes[key] || 'medium';
            card.setAttribute('data-card-size', size);
            card.classList.remove('sitepulse-card--small', 'sitepulse-card--medium', 'sitepulse-card--large');
            card.classList.add('sitepulse-card--' + size);

            const isVisible = Boolean(state.visibility[key]);

            if (isVisible) {
                card.classList.remove('sitepulse-card--is-hidden');
                card.removeAttribute('hidden');
                card.removeAttribute('aria-hidden');
            } else {
                card.classList.add('sitepulse-card--is-hidden');
                card.setAttribute('hidden', 'hidden');
                card.setAttribute('aria-hidden', 'true');
            }
        });

        updateEmptyState();
    }

    function updateEmptyState() {
        if (!emptyState) {
            return;
        }

        if (!grid) {
            emptyState.removeAttribute('hidden');
            return;
        }

        const visibleCards = grid.querySelectorAll('.sitepulse-card:not(.sitepulse-card--is-hidden)');

        if (visibleCards.length === 0) {
            emptyState.removeAttribute('hidden');
        } else {
            emptyState.setAttribute('hidden', 'hidden');
        }
    }

    function persistState(state) {
        if (!data.ajaxUrl) {
            return;
        }

        toggleLoading(true);

        $.post(data.ajaxUrl, {
            action: 'sitepulse_save_dashboard_preferences',
            nonce: data.nonce,
            order: state.order,
            visibility: state.visibility,
            sizes: state.sizes,
        }).done((response) => {
            if (response && response.success && response.data && response.data.preferences) {
                savedState = normaliseState(response.data.preferences);
                currentState = deepClone(savedState);
                showNotice((data.strings && data.strings.saveSuccess) || '', false);
                speak((data.strings && data.strings.changesSaved) || '');
                closePanel();
            } else {
                showNotice(getErrorMessage(response), true);
            }
        }).fail(() => {
            showNotice((data.strings && data.strings.saveError) || '', true);
        }).always(() => {
            toggleLoading(false);
            applyStateToGrid(savedState);
            setControlsFromState(savedState);
        });
    }

    function toggleLoading(isLoading) {
        if (saveButton) {
            saveButton.disabled = Boolean(isLoading);
        }

        if (panel) {
            panel.classList.toggle('is-loading', Boolean(isLoading));
        }
    }

    function showNotice(message, isError) {
        if (!notice) {
            return;
        }

        notice.textContent = message || '';
        notice.classList.toggle('is-error', Boolean(isError));

        if (message) {
            notice.classList.remove('is-hidden');
        } else {
            notice.classList.add('is-hidden');
        }
    }

    function clearNotice() {
        showNotice('', false);
    }

    function getErrorMessage(response) {
        if (response && response.data && response.data.message) {
            return response.data.message;
        }

        return (data.strings && data.strings.saveError) || '';
    }

    function speak(message) {
        if (!message) {
            return;
        }

        if (window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
            wp.a11y.speak(message, 'assertive');
        }
    }
})(jQuery);
