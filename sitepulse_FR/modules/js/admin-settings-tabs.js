(function () {
    'use strict';

    const onReady = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    };

    const speak = (message, politeness = 'polite') => {
        if (!message) {
            return;
        }

        const channel = politeness === 'assertive' ? 'assertive' : 'polite';

        if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
            window.wp.a11y.speak(message, channel);
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

        const enhanceToggle = (input) => {
            if (!input || input.dataset.sitepulseToggleEnhanced) {
                return;
            }

            input.dataset.sitepulseToggleEnhanced = 'true';

            const statusTargetId = input.getAttribute('data-sitepulse-toggle-status-target');
            const statusTarget = statusTargetId ? document.getElementById(statusTargetId) : null;
            const statusOn = (statusTarget && statusTarget.getAttribute('data-sitepulse-status-on')) || input.getAttribute('data-sitepulse-toggle-on') || '';
            const statusOff = (statusTarget && statusTarget.getAttribute('data-sitepulse-status-off')) || input.getAttribute('data-sitepulse-toggle-off') || '';
            const toggleLabel = input.getAttribute('data-sitepulse-toggle-label') || '';

            const formatStatus = (raw) => {
                if (!raw) {
                    return '';
                }

                return raw.charAt(0).toUpperCase() + raw.slice(1);
            };

            const updateStatus = (announce) => {
                const isChecked = input.checked;
                const statusText = isChecked ? statusOn : statusOff;

                if (statusTarget && statusText) {
                    statusTarget.textContent = formatStatus(statusText);
                    statusTarget.classList.toggle('is-active', isChecked);
                    statusTarget.classList.toggle('is-inactive', !isChecked);
                }

                if (announce) {
                    const messageParts = [];

                    if (toggleLabel) {
                        messageParts.push(toggleLabel);
                    }

                    if (statusText) {
                        messageParts.push(statusText);
                    }

                    if (messageParts.length) {
                        speak(messageParts.join(' : '));
                    }
                }
            };

            input.addEventListener('change', () => updateStatus(true));
            updateStatus(false);
        };

        const container = document.querySelector('.sitepulse-settings-tabs-container');
        const settingsRoot = document.querySelector('[data-sitepulse-settings-wrap]');
        const viewModeSection = document.querySelector('.sitepulse-settings-mode-toggle');
        const viewModeControls = settingsRoot
            ? Array.from(settingsRoot.querySelectorAll('[data-sitepulse-view-control]'))
            : [];
        const viewModeOptions = settingsRoot
            ? Array.from(settingsRoot.querySelectorAll('.sitepulse-view-mode-option'))
            : [];
        const liveRegionPolite = settingsRoot
            ? settingsRoot.querySelector('[data-sitepulse-live-region="polite"]')
            : null;
        const liveRegionAssertive = settingsRoot
            ? settingsRoot.querySelector('[data-sitepulse-live-region="assertive"]')
            : null;
        const viewAnnouncements = {
            simple:
                (viewModeSection && viewModeSection.getAttribute('data-sitepulse-view-announce-simple')) ||
                '',
            expert:
                (viewModeSection && viewModeSection.getAttribute('data-sitepulse-view-announce-expert')) ||
                '',
        };
        const VIEW_MODE_STORAGE_KEY = 'sitepulseSettingsViewMode';
        const allowedViewModes = new Set(['simple', 'expert']);

        document.querySelectorAll('input[type="checkbox"][data-sitepulse-toggle]').forEach((input) => {
            enhanceToggle(input);
        });

        const updateLiveRegion = (message, politeness = 'polite') => {
            const region = politeness === 'assertive' ? liveRegionAssertive : liveRegionPolite;

            if (!region) {
                return;
            }

            region.textContent = '';

            if (message) {
                window.requestAnimationFrame(() => {
                    region.textContent = message;
                });
            }
        };

        const setViewModeSelectionState = (mode) => {
            viewModeOptions.forEach((option) => {
                const control = option.querySelector('[data-sitepulse-view-control]');
                const isSelected = Boolean(control && control.value === mode && control.checked);
                option.classList.toggle('is-selected', isSelected);
            });
        };

        const applyViewMode = (mode, options = {}) => {
            if (!settingsRoot || !allowedViewModes.has(mode)) {
                return;
            }

            const { announce = false, persist = false } = options;
            const normalizedMode = mode === 'expert' ? 'expert' : 'simple';

            settingsRoot.setAttribute('data-sitepulse-view-mode', normalizedMode);

            viewModeControls.forEach((control) => {
                if (control.value === normalizedMode) {
                    control.checked = true;
                } else if (control.type === 'radio') {
                    control.checked = false;
                }
            });

            setViewModeSelectionState(normalizedMode);

            if (persist) {
                try {
                    window.localStorage.setItem(VIEW_MODE_STORAGE_KEY, normalizedMode);
                } catch (storageError) {
                    // Storage might be unavailable (private mode, user preferences).
                }
            }

            if (announce) {
                const announcement = normalizedMode === 'expert'
                    ? viewAnnouncements.expert
                    : viewAnnouncements.simple;

                if (announcement) {
                    updateLiveRegion(announcement, 'polite');
                    speak(announcement);
                } else {
                    const fallback = normalizedMode === 'expert'
                        ? 'Mode expert activé'
                        : 'Mode guidé activé';
                    updateLiveRegion(fallback, 'polite');
                    speak(fallback);
                }
            }
        };

        if (settingsRoot && viewModeControls.length) {
            let currentMode = settingsRoot.getAttribute('data-sitepulse-view-mode') || 'simple';

            if (!allowedViewModes.has(currentMode)) {
                currentMode = 'simple';
            }

            applyViewMode(currentMode, { announce: false, persist: false });

            try {
                const storedMode = window.localStorage.getItem(VIEW_MODE_STORAGE_KEY);

                if (storedMode && allowedViewModes.has(storedMode) && storedMode !== currentMode) {
                    currentMode = storedMode;
                    applyViewMode(currentMode, { announce: false, persist: false });
                }
            } catch (storageError) {
                // Ignore storage access issues.
            }

            viewModeControls.forEach((control) => {
                control.addEventListener('change', () => {
                    if (!control.checked) {
                        return;
                    }

                    const nextMode = allowedViewModes.has(control.value) ? control.value : 'simple';

                    if (nextMode === currentMode) {
                        return;
                    }

                    currentMode = nextMode;
                    applyViewMode(nextMode, { announce: true, persist: true });
                });
            });
        }

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

            tocLinks.forEach((tocLink) => {
                const isCurrent = tocLink.dataset.tabTarget === targetId;
                tocLink.setAttribute('aria-current', isCurrent ? 'page' : 'false');
                tocLink.setAttribute('tabindex', isCurrent ? '0' : '-1');
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
                const trimmedLabel = label ? label.trim() : '';

                if (trimmedLabel) {
                    updateLiveRegion(trimmedLabel, 'polite');
                }

                speak(trimmedLabel);
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

        const adminData = window.sitepulseAdminSettingsData || {};
        const asyncCard = document.querySelector('[data-sitepulse-async-card]');
        const asyncList = asyncCard ? asyncCard.querySelector('[data-sitepulse-async-jobs-list]') : null;

        if (
            asyncCard &&
            asyncList &&
            adminData.ajaxUrl &&
            adminData.asyncJobsNonce &&
            typeof window.fetch === 'function'
        ) {
            const errorRegion = asyncCard.querySelector('[data-sitepulse-async-error]');
            const emptyMessage =
                asyncList.getAttribute('data-sitepulse-async-empty-message') ||
                (adminData.i18n && adminData.i18n.asyncEmpty) ||
                '';
            let initialJobs = [];

            try {
                const initialRaw = asyncList.getAttribute('data-sitepulse-async-initial');
                initialJobs = initialRaw ? JSON.parse(initialRaw) : [];
            } catch (error) {
                initialJobs = [];
            }

            asyncList.removeAttribute('data-sitepulse-async-initial');

            let lastJobsSignature = JSON.stringify(initialJobs || []);
            let hasFetchedOnce = false;
            let isFetching = false;

            const setCardState = (state) => {
                asyncCard.dataset.sitepulseAsyncState = state;
            };

            if (Array.isArray(initialJobs) && initialJobs.some((job) => job && job.is_active)) {
                setCardState('busy');
            }

            const showError = (message) => {
                if (!errorRegion) {
                    return;
                }

                if (message) {
                    errorRegion.textContent = message;
                    errorRegion.hidden = false;
                    errorRegion.setAttribute('role', 'alert');
                    errorRegion.setAttribute('aria-live', 'assertive');
                    updateLiveRegion(message, 'assertive');
                    speak(message, 'assertive');
                } else {
                    errorRegion.textContent = '';
                    errorRegion.hidden = true;
                    errorRegion.removeAttribute('role');
                    errorRegion.removeAttribute('aria-live');
                }
            };

            const renderJobs = (jobs) => {
                const signature = JSON.stringify(jobs || []);

                if (signature === lastJobsSignature) {
                    return false;
                }

                lastJobsSignature = signature;
                asyncList.innerHTML = '';

                if (!Array.isArray(jobs) || !jobs.length) {
                    const emptyItem = document.createElement('li');
                    emptyItem.className = 'sitepulse-async-job sitepulse-async-job--empty';
                    emptyItem.textContent = emptyMessage;
                    asyncList.appendChild(emptyItem);
                    setCardState('idle');
                    return true;
                }

                let hasActive = false;

                jobs.forEach((job) => {
                    if (!job) {
                        return;
                    }

                    const containerClass =
                        typeof job.container_class === 'string' && job.container_class
                            ? ` sitepulse-async-job--${job.container_class}`
                            : '';
                    const listItem = document.createElement('li');
                    listItem.className = `sitepulse-async-job${containerClass}`;

                    if (job.id) {
                        listItem.setAttribute('data-sitepulse-async-id', job.id);
                    }

                    const header = document.createElement('div');
                    header.className = 'sitepulse-async-job__header';

                    const badge = document.createElement('span');
                    const badgeClasses = ['sitepulse-status'];

                    if (job.badge_class) {
                        badgeClasses.push(job.badge_class);
                    }

                    badge.className = badgeClasses.join(' ');
                    badge.textContent = job.status_label || '';
                    header.appendChild(badge);

                    const label = document.createElement('span');
                    label.className = 'sitepulse-async-job__label';
                    label.textContent = job.label || '';
                    header.appendChild(label);

                    listItem.appendChild(header);

                    if (job.message) {
                        const messageEl = document.createElement('p');
                        messageEl.className = 'sitepulse-async-job__message';
                        messageEl.textContent = job.message;
                        listItem.appendChild(messageEl);
                    }

                    const shouldShowMeta =
                        (job.progress_label && job.progress_label.length > 0) ||
                        (job.relative && job.relative.length > 0) ||
                        (job.progress_percent && job.progress_percent > 0 && job.progress_percent < 100);

                    if (shouldShowMeta) {
                        const meta = document.createElement('p');
                        meta.className = 'sitepulse-async-job__meta';

                        if (job.progress_label) {
                            const progressSpan = document.createElement('span');
                            progressSpan.textContent = job.progress_label;
                            meta.appendChild(progressSpan);
                        } else if (job.progress_percent && job.progress_percent > 0 && job.progress_percent < 100) {
                            const progressSpan = document.createElement('span');
                            progressSpan.textContent = `${job.progress_percent}%`;
                            meta.appendChild(progressSpan);
                        }

                        if (job.relative) {
                            const relativeSpan = document.createElement('span');
                            relativeSpan.textContent = job.relative;
                            meta.appendChild(relativeSpan);
                        }

                        if (meta.childNodes.length) {
                            listItem.appendChild(meta);
                        }
                    }

                    if (Array.isArray(job.logs) && job.logs.length) {
                        const details = document.createElement('details');
                        details.className = 'sitepulse-async-job__logs';

                        if (job.is_active) {
                            details.open = true;
                        }

                        const summary = document.createElement('summary');
                        summary.className = 'sitepulse-async-job__logs-summary';

                        const summaryIcon = document.createElement('span');
                        summaryIcon.className = 'dashicons dashicons-list-view';
                        summaryIcon.setAttribute('aria-hidden', 'true');
                        summary.appendChild(summaryIcon);

                        const summaryText = document.createElement('span');
                        summaryText.className = 'sitepulse-async-job__logs-summary-text';
                        summaryText.textContent = (adminData.i18n && adminData.i18n.asyncLogSummary) || 'Journal';
                        summary.appendChild(summaryText);

                        const summarySr = document.createElement('span');
                        summarySr.className = 'screen-reader-text';
                        summarySr.textContent = (adminData.i18n && adminData.i18n.asyncLogToggle) || '';
                        summary.appendChild(summarySr);

                        details.appendChild(summary);

                        const logList = document.createElement('ul');
                        logList.className = 'sitepulse-async-job__log-list';

                        job.logs.forEach((log) => {
                            if (!log || !log.message) {
                                return;
                            }

                            const logItem = document.createElement('li');
                            let logClass = 'sitepulse-async-job__log';

                            if (log.level_class) {
                                logClass += ` sitepulse-async-job__log--${log.level_class}`;
                            }

                            logItem.className = logClass;

                            if (log.level_label) {
                                const labelEl = document.createElement('span');
                                labelEl.className = 'sitepulse-async-job__log-label';
                                labelEl.textContent = log.level_label;
                                logItem.appendChild(labelEl);
                            }

                            const messageEl = document.createElement('span');
                            messageEl.className = 'sitepulse-async-job__log-message';
                            messageEl.textContent = log.message;
                            logItem.appendChild(messageEl);

                            if (log.relative) {
                                const timeEl = document.createElement('time');
                                timeEl.className = 'sitepulse-async-job__log-time';

                                if (log.iso) {
                                    timeEl.setAttribute('datetime', log.iso);
                                }

                                timeEl.textContent = log.relative;
                                logItem.appendChild(timeEl);
                            }

                            logList.appendChild(logItem);
                        });

                        details.appendChild(logList);
                        listItem.appendChild(details);
                    }

                    asyncList.appendChild(listItem);

                    if (job.is_active) {
                        hasActive = true;
                    }
                });

                setCardState(hasActive ? 'busy' : 'idle');

                return true;
            };

            const pollInterval = Math.max(15000, parseInt(adminData.asyncPollInterval, 10) || 45000);

            const fetchJobs = () => {
                if (isFetching || document.hidden) {
                    return;
                }

                isFetching = true;

                const formData = new window.FormData();
                formData.append('action', 'sitepulse_async_jobs_overview');
                formData.append('nonce', adminData.asyncJobsNonce);

                window.fetch(adminData.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error('request_failed');
                        }

                        return response.json();
                    })
                    .then((payload) => {
                        if (!payload || payload.success !== true || !payload.data) {
                            throw new Error('invalid_payload');
                        }

                        const jobs = Array.isArray(payload.data.jobs) ? payload.data.jobs : [];
                        const updated = renderJobs(jobs);
                        showError('');

                        if (updated && hasFetchedOnce && adminData.i18n && adminData.i18n.asyncUpdated) {
                            speak(adminData.i18n.asyncUpdated);
                        }

                        hasFetchedOnce = true;
                    })
                    .catch(() => {
                        const message = (adminData.i18n && adminData.i18n.asyncError) || '';
                        showError(message);

                        if (message) {
                            speak(message);
                        }
                    })
                    .finally(() => {
                        isFetching = false;
                    });
            };

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    fetchJobs();
                }
            });

            const pollId = window.setInterval(fetchJobs, pollInterval);

            window.addEventListener('beforeunload', () => {
                window.clearInterval(pollId);
            });

            fetchJobs();
        }

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
