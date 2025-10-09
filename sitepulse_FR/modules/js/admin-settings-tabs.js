(function () {
    'use strict';

    const onReady = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    };

    const speak = (message) => {
        if (!message) {
            return;
        }

        if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
            window.wp.a11y.speak(message, 'polite');
        }
    };

    const normalizeHash = (hashValue) => {
        if (!hashValue) {
            return '';
        }

        return hashValue.charAt(0) === '#' ? hashValue.substring(1) : hashValue;
    };

    onReady(() => {
        const stickyActions = document.querySelector('[data-sitepulse-sticky-actions]');

        if (stickyActions) {
            const updateFloatingState = () => {
                const threshold = 160;
                window.requestAnimationFrame(() => {
                    stickyActions.classList.toggle('is-floating', window.scrollY > threshold);
                });
            };

            updateFloatingState();
            window.addEventListener('scroll', updateFloatingState, { passive: true });
            window.addEventListener('resize', updateFloatingState);
        }

        const scrollButtons = document.querySelectorAll('[data-sitepulse-scroll-top]');

        scrollButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();

                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        const container = document.querySelector('.sitepulse-settings-tabs-container');

        if (!container) {
            return;
        }

        const tabLinks = Array.from(container.querySelectorAll('.sitepulse-tab-link[data-tab-target]'));
        const tabPanels = Array.from(container.querySelectorAll('.sitepulse-tab-panel[id]'));
        const tocLinks = Array.from(document.querySelectorAll('.sitepulse-tab-trigger[data-tab-target]'));

        if (!tabLinks.length || !tabPanels.length) {
            return;
        }

        container.classList.add('is-enhanced');

        const getLinkForTarget = (targetId) => tabLinks.find((link) => link.dataset.tabTarget === targetId) || null;

        const setTabState = (targetId) => {
            const panel = tabPanels.find((element) => element.id === targetId);

            if (!panel) {
                return null;
            }

            tabLinks.forEach((link) => {
                const isActive = link.dataset.tabTarget === targetId;
                link.classList.toggle('nav-tab-active', isActive);
                link.classList.toggle('is-active', isActive);
                link.setAttribute('aria-selected', isActive ? 'true' : 'false');
                link.setAttribute('tabindex', isActive ? '0' : '-1');
            });

            tabPanels.forEach((panelElement) => {
                const isActive = panelElement.id === targetId;
                panelElement.classList.toggle('is-active', isActive);
                panelElement.toggleAttribute('hidden', !isActive);
                panelElement.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                panelElement.setAttribute('tabindex', isActive ? '0' : '-1');
            });

            return {
                panel,
                link: getLinkForTarget(targetId),
            };
        };

        const updateHashFromPanel = (panel) => {
            if (!panel) {
                return;
            }

            const firstSection = panel.querySelector('.sitepulse-settings-section[id]');

            if (firstSection && firstSection.id) {
                window.location.hash = firstSection.id;
                return;
            }

            window.location.hash = panel.id;
        };

        const findPanelFromHash = (hashValue) => {
            const normalizedHash = normalizeHash(hashValue);

            if (!normalizedHash) {
                return null;
            }

            const directTarget = document.getElementById(normalizedHash);

            if (!directTarget) {
                return null;
            }

            if (directTarget.classList && directTarget.classList.contains('sitepulse-tab-panel')) {
                return directTarget;
            }

            return directTarget.closest('.sitepulse-tab-panel');
        };

        const activateTab = (targetId, options = {}) => {
            const { updateHash = false, focusLink = null, announce = false } = options;
            const state = setTabState(targetId);

            if (!state) {
                return null;
            }

            if (announce && state.link) {
                const label = state.link.getAttribute('aria-label') || state.link.textContent;
                speak(label ? label.trim() : '');
            }

            if (focusLink && typeof focusLink.focus === 'function') {
                focusLink.focus();
            }

            if (updateHash) {
                updateHashFromPanel(state.panel);
            }

            return state.panel;
        };

        tabLinks.forEach((link, index) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();

                const targetId = link.dataset.tabTarget;

                if (!targetId) {
                    return;
                }

                activateTab(targetId, { updateHash: true, announce: true });
            });

            link.addEventListener('keydown', (event) => {
                const { key } = event;
                let targetLink = null;

                if (key === 'ArrowRight') {
                    event.preventDefault();
                    targetLink = tabLinks[(index + 1) % tabLinks.length];
                } else if (key === 'ArrowLeft') {
                    event.preventDefault();
                    targetLink = tabLinks[(index - 1 + tabLinks.length) % tabLinks.length];
                } else if (key === 'Home') {
                    event.preventDefault();
                    targetLink = tabLinks[0];
                } else if (key === 'End') {
                    event.preventDefault();
                    targetLink = tabLinks[tabLinks.length - 1];
                } else if (key === 'Enter' || key === ' ' || key === 'Spacebar') {
                    event.preventDefault();
                    targetLink = link;
                }

                if (!targetLink) {
                    return;
                }

                const targetId = targetLink.dataset.tabTarget;

                if (!targetId) {
                    return;
                }

                activateTab(targetId, { updateHash: true, focusLink: targetLink, announce: true });
            });
        });

        tocLinks.forEach((link) => {
            link.addEventListener('click', () => {
                const targetId = link.dataset.tabTarget;

                if (!targetId) {
                    return;
                }

                activateTab(targetId, { announce: true });
            });
        });

        window.addEventListener('hashchange', () => {
            const panel = findPanelFromHash(window.location.hash);

            if (panel) {
                activateTab(panel.id);
            }
        });

        const initialPanel = findPanelFromHash(window.location.hash) || tabPanels[0];

        if (initialPanel) {
            activateTab(initialPanel.id);
        }
    });
})();
