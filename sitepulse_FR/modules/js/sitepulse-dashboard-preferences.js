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
    const themeToggle = document.querySelector('[data-sitepulse-theme-toggle]');
    const themeOptionInputs = themeToggle ? Array.from(themeToggle.querySelectorAll('[data-sitepulse-theme-option]')) : [];
    const themeAnnouncer = themeToggle ? themeToggle.querySelector('[data-sitepulse-theme-announcer]') : null;
    const themeChoices = Array.isArray(data.themeOptions) && data.themeOptions.length ? data.themeOptions.slice() : ['auto', 'light', 'dark'];
    const fallbackTheme = themeChoices.length ? themeChoices[0] : 'auto';
    const defaultTheme = normaliseThemeValue(typeof data.defaultTheme === 'string' ? data.defaultTheme : fallbackTheme);
    const themeLabels = (data.themeLabels && typeof data.themeLabels === 'object') ? data.themeLabels : {};
    const THEME_STORAGE_KEY = 'sitepulseDashboardTheme';

    if (!panel || !toggleButton || !list) {
        return;
    }

    let savedState = normaliseState(data.preferences || {});
    let currentState = deepClone(savedState);
    let lastActiveElement = null;

    const storedThemeRaw = readStoredTheme();
    const storedTheme = storedThemeRaw ? normaliseThemeValue(storedThemeRaw) : '';

    if (storedTheme && storedTheme !== savedState.theme) {
        savedState.theme = storedTheme;
        currentState = deepClone(savedState);
    }

    initialise();

    function initialise() {
        bindEvents();
        setControlsFromState(savedState);
        applyStateToGrid(savedState);
        updateEmptyState();
    }

    function bindEvents() {
        toggleButton.addEventListener('click', (event) => {
            if (panel.hasAttribute('hidden')) {
                openPanel();
            } else {
                closePanel(event.currentTarget || toggleButton);
            }
        });

        if (cancelButton) {
            cancelButton.addEventListener('click', (event) => {
                currentState = deepClone(savedState);
                setControlsFromState(savedState);
                applyStateToGrid(savedState);
                clearNotice();
                closePanel(event.currentTarget || cancelButton);
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

        if (themeToggle) {
            themeToggle.addEventListener('change', (event) => {
                const target = event.target;

                if (!target || !target.matches('[data-sitepulse-theme-option]')) {
                    return;
                }

                currentState = buildStateFromUI();
                applyTheme(currentState.theme, true);
                persistState(currentState);
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

    function closePanel(focusTarget) {
        rememberActiveElement(focusTarget);

        panel.setAttribute('hidden', 'hidden');
        toggleButton.setAttribute('aria-expanded', 'false');

        focusAfterTransition(() => {
            if (isFocusableElement(lastActiveElement)) {
                lastActiveElement.focus();
            }

            lastActiveElement = null;
        });
    }

    function rememberActiveElement(focusTarget) {
        const activeElement = focusTarget || document.activeElement || lastActiveElement;

        if (isFocusableElement(activeElement) && !panel.contains(activeElement)) {
            lastActiveElement = activeElement;
            return;
        }

        lastActiveElement = toggleButton;
    }

    function normaliseState(prefs) {
        const state = {
            order: Array.isArray(prefs.order) ? prefs.order.slice() : [],
            visibility: typeof prefs.visibility === 'object' && prefs.visibility !== null ? { ...prefs.visibility } : {},
            sizes: typeof prefs.sizes === 'object' && prefs.sizes !== null ? { ...prefs.sizes } : {},
            theme: normaliseThemeValue(prefs.theme),
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
        state.theme = normaliseThemeValue(state.theme);

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

        if ($list && typeof $list.sortable === 'function' && $list.hasClass('ui-sortable')) {
            $list.sortable('refresh');
        }

        updateThemeControls(state.theme);
        applyTheme(state.theme, false);
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

        const theme = getThemeFromUI();

        return normaliseState({ order, visibility, sizes, theme });
    }

    function normaliseThemeValue(value) {
        const candidate = typeof value === 'string' ? value.toLowerCase() : '';

        if (themeChoices.indexOf(candidate) !== -1) {
            return candidate;
        }

        if (themeChoices.indexOf('auto') !== -1) {
            return 'auto';
        }

        return themeChoices.length ? themeChoices[0] : 'auto';
    }

    function getThemeFromUI() {
        if (!themeOptionInputs.length) {
            return savedState.theme || defaultTheme;
        }

        const checked = themeOptionInputs.find((input) => input.checked);

        if (checked) {
            return normaliseThemeValue(checked.value);
        }

        return defaultTheme;
    }

    function updateThemeControls(theme) {
        const activeTheme = normaliseThemeValue(theme);

        themeOptionInputs.forEach((input) => {
            const optionValue = normaliseThemeValue(input.value);
            const isSelected = optionValue === activeTheme;

            input.checked = isSelected;

            const container = input.closest('.sitepulse-theme-toggle__option');

            if (container) {
                container.classList.toggle('is-selected', isSelected);
            }
        });
    }

    function applyTheme(theme, announce) {
        const activeTheme = normaliseThemeValue(theme || savedState.theme || defaultTheme);
        const body = document.body;

        if (body) {
            body.classList.add('sitepulse-theme');

            themeChoices.forEach((choice) => {
                const className = 'sitepulse-theme--' + choice;

                if (choice !== activeTheme) {
                    body.classList.remove(className);
                }
            });

            body.classList.add('sitepulse-theme--' + activeTheme);
        }

        if (document.documentElement) {
            if (activeTheme === 'auto') {
                document.documentElement.style.colorScheme = 'light dark';
            } else if (activeTheme === 'dark') {
                document.documentElement.style.colorScheme = 'dark';
            } else {
                document.documentElement.style.colorScheme = 'light';
            }

            document.documentElement.setAttribute('data-sitepulse-theme', activeTheme);
        }

        writeStoredTheme(activeTheme);

        if (announce) {
            announceTheme(activeTheme);
        } else if (themeAnnouncer) {
            const label = themeLabels[activeTheme] || activeTheme;
            const template = data.strings && data.strings.themeAnnouncement ? data.strings.themeAnnouncement : '';
            const message = template ? template.replace('%s', label) : label;
            themeAnnouncer.textContent = message;
        }
    }

    function announceTheme(theme) {
        const label = themeLabels[theme] || theme;
        const spokenTemplate = data.strings && data.strings.themeSpoken ? data.strings.themeSpoken : '';
        const announcementTemplate = data.strings && data.strings.themeAnnouncement ? data.strings.themeAnnouncement : '';
        const announcement = announcementTemplate ? announcementTemplate.replace('%s', label) : label;
        const spoken = spokenTemplate ? spokenTemplate.replace('%s', label) : announcement;

        if (themeAnnouncer) {
            themeAnnouncer.textContent = announcement;
        }

        speak(spoken, 'polite');
    }

    function readStoredTheme() {
        if (!('localStorage' in window)) {
            return '';
        }

        try {
            const storedValue = window.localStorage.getItem(THEME_STORAGE_KEY);

            return typeof storedValue === 'string' ? storedValue : '';
        } catch (error) {
            return '';
        }
    }

    function writeStoredTheme(theme) {
        if (!('localStorage' in window)) {
            return;
        }

        try {
            window.localStorage.setItem(THEME_STORAGE_KEY, theme);
        } catch (error) {
            // Ignore storage issues (quota exceeded, private mode, etc.).
        }
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
            theme: state.theme,
        }).done((response) => {
            if (response && response.success && response.data && response.data.preferences) {
                savedState = normaliseState(response.data.preferences);
                currentState = deepClone(savedState);
                showNotice((data.strings && data.strings.saveSuccess) || '', false);
                speak((data.strings && data.strings.changesSaved) || '', 'assertive');

                if (panel && !panel.hasAttribute('hidden')) {
                    closePanel(toggleButton);
                }
            } else {
                showNotice(getErrorMessage(response), true);
            }
        }).fail(() => {
            showNotice((data.strings && data.strings.saveError) || '', true);
        }).always(() => {
            toggleLoading(false);
            applyStateToGrid(savedState);
            setControlsFromState(savedState);
            applyTheme(savedState.theme, false);
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

    function isFocusableElement(element) {
        if (!element || typeof element.focus !== 'function') {
            return false;
        }

        if (element.disabled) {
            return false;
        }

        if (typeof element.closest === 'function') {
            const hiddenParent = element.closest('[hidden]');

            if (hiddenParent) {
                return false;
            }
        }

        if (element.getAttribute && element.getAttribute('aria-hidden') === 'true') {
            return false;
        }

        return true;
    }

    function focusAfterTransition(callback) {
        if (typeof callback !== 'function') {
            return;
        }

        if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(callback);
        } else {
            setTimeout(callback, 0);
        }
    }

    function getErrorMessage(response) {
        if (response && response.data && response.data.message) {
            return response.data.message;
        }

        return (data.strings && data.strings.saveError) || '';
    }

    function speak(message, politeness) {
        if (!message) {
            return;
        }

        if (window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
            const politenessLevel = typeof politeness === 'string' && politeness ? politeness : 'assertive';
            wp.a11y.speak(message, politenessLevel);
        }
    }
})(jQuery);
