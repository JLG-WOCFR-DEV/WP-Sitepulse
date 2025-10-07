(function () {
    'use strict';

    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    const data = window.SitePulseMetricsData;

    if (!data || typeof data !== 'object') {
        return;
    }

    const root = document.querySelector('[data-sitepulse-metrics]');

    if (!root) {
        return;
    }

    const selectors = {
        rangeFieldset: '[data-sitepulse-range]',
        rangeOptions: '[data-sitepulse-range-option]',
        rangeSelect: '[data-sitepulse-range-select]',
        rangeLabel: '[data-sitepulse-range-label]',
        generated: '[data-sitepulse-generated]',
        banner: '[data-sitepulse-banner]',
        bannerIcon: '[data-sitepulse-banner-icon]',
        bannerMessage: '[data-sitepulse-banner-message]',
        bannerSr: '[data-sitepulse-banner-sr]',
        bannerCta: '[data-sitepulse-banner-cta]',
        metricsGrid: '[data-sitepulse-metrics-grid]',
        metricsError: '[data-sitepulse-metrics-error]',
        announcer: '[data-sitepulse-metrics-announcer]',
    };

    const strings = Object.assign(
        {
            loading: 'Loading…',
            error: 'Unable to refresh metrics. Please try again.',
            announcement: 'Dashboard metrics updated.',
        },
        typeof data.strings === 'object' && data.strings ? data.strings : {}
    );

    let rangeOptions = Array.isArray(data.ranges) ? data.ranges.slice() : [];
    let currentRange = typeof data.view === 'object' && data.view && typeof data.view.range === 'string'
        ? data.view.range
        : '';
    let currentView = typeof data.view === 'object' && data.view ? data.view : {};
    let isFetching = false;

    const fieldset = root.querySelector(selectors.rangeFieldset);
    const rangeLabelEl = root.querySelector(selectors.rangeLabel);
    const generatedEl = root.querySelector(selectors.generated);
    const bannerEl = root.querySelector(selectors.banner);
    const bannerIconEl = bannerEl ? bannerEl.querySelector(selectors.bannerIcon) : null;
    const bannerMessageEl = bannerEl ? bannerEl.querySelector(selectors.bannerMessage) : null;
    const bannerSrEl = bannerEl ? bannerEl.querySelector(selectors.bannerSr) : null;
    let bannerCtaEl = bannerEl ? bannerEl.querySelector(selectors.bannerCta) : null;
    const metricsGrid = root.querySelector(selectors.metricsGrid);
    const errorEl = root.querySelector(selectors.metricsError);
    const announcerEl = root.querySelector(selectors.announcer);

    function speak(message, politeness) {
        if (announcerEl) {
            announcerEl.textContent = message;
        }

        if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
            window.wp.a11y.speak(message, politeness);
        }
    }

    function setLoading(state) {
        isFetching = state;
        root.dataset.loading = state ? 'true' : 'false';
        root.setAttribute('aria-busy', state ? 'true' : 'false');

        const radios = root.querySelectorAll(selectors.rangeOptions);
        radios.forEach((input) => {
            input.disabled = state;
        });

        const select = root.querySelector(selectors.rangeSelect);
        if (select) {
            select.disabled = state;
        }
    }

    function sanitizeRangeId(id) {
        if (typeof id !== 'string') {
            return '';
        }

        return id.trim();
    }

    function formatRangeLabel(rangeId) {
        const option = rangeOptions.find((item) => item && item.id === rangeId);
        if (option && typeof option.label === 'string' && option.label) {
            return option.label;
        }

        return rangeId;
    }

    function syncRangeControls(activeRange) {
        const radios = root.querySelectorAll(selectors.rangeOptions);
        radios.forEach((input) => {
            input.checked = input.value === activeRange;
            const parentLabel = input.closest('.sitepulse-range-picker__option');
            if (parentLabel) {
                parentLabel.classList.toggle('is-selected', input.value === activeRange);
            }
        });

        const select = root.querySelector(selectors.rangeSelect);
        if (select) {
            select.value = activeRange;
        }
    }

    function buildRangeControls(options, activeRange) {
        const optionsWrapper = fieldset ? fieldset.querySelector('.sitepulse-range-picker__options') : null;
        const select = root.querySelector(selectors.rangeSelect);

        if (!optionsWrapper || !select) {
            return;
        }

        optionsWrapper.innerHTML = '';
        select.innerHTML = '';

        options.forEach((option) => {
            if (!option || typeof option.id !== 'string') {
                return;
            }

            const id = sanitizeRangeId(option.id);

            if (!id) {
                return;
            }

            const label = typeof option.label === 'string' && option.label ? option.label : id;
            const inputId = `sitepulse-metrics-range-${id}`;
            const labelEl = document.createElement('label');
            labelEl.className = 'sitepulse-range-picker__option';
            labelEl.setAttribute('for', inputId);

            const input = document.createElement('input');
            input.type = 'radio';
            input.id = inputId;
            input.name = 'sitepulse-metrics-range';
            input.value = id;
            input.dataset.sitepulseRangeOption = '';

            if (id === activeRange) {
                input.checked = true;
                labelEl.classList.add('is-selected');
            }

            const labelText = document.createElement('span');
            labelText.textContent = label;

            labelEl.appendChild(input);
            labelEl.appendChild(labelText);
            optionsWrapper.appendChild(labelEl);

            const optionEl = document.createElement('option');
            optionEl.value = id;
            optionEl.textContent = label;
            if (id === activeRange) {
                optionEl.selected = true;
            }
            select.appendChild(optionEl);
        });
    }

    function toggleHidden(element, shouldHide) {
        if (!element) {
            return;
        }

        if (shouldHide) {
            element.hidden = true;
            element.setAttribute('aria-hidden', 'true');
        } else {
            element.hidden = false;
            element.removeAttribute('aria-hidden');
        }
    }

    function updateBanner(view) {
        if (!bannerEl) {
            return;
        }

        const tone = view && typeof view.tone === 'string' ? view.tone : 'ok';
        bannerEl.className = `sitepulse-status-banner sitepulse-status-banner--${tone}`;

        if (bannerIconEl) {
            bannerIconEl.textContent = view && typeof view.icon === 'string' ? view.icon : '✅';
        }

        if (bannerMessageEl) {
            bannerMessageEl.textContent = view && typeof view.message === 'string' ? view.message : '';
        }

        if (bannerSrEl) {
            bannerSrEl.textContent = view && typeof view.sr === 'string' ? view.sr : '';
        }

        if (bannerCtaEl && bannerCtaEl.tagName !== 'A' && view && view.cta && typeof view.cta.label === 'string' && view.cta.label && typeof view.cta.url === 'string' && view.cta.url) {
            const replacement = document.createElement('a');
            replacement.className = `${bannerCtaEl.className} button button-primary`.trim();
            replacement.dataset.sitepulseBannerCta = '';
            bannerEl.replaceChild(replacement, bannerCtaEl);
            bannerCtaEl = replacement;
        } else if (!bannerCtaEl && view && view.cta && typeof view.cta.label === 'string' && view.cta.label && typeof view.cta.url === 'string' && view.cta.url) {
            bannerCtaEl = document.createElement('a');
            bannerCtaEl.className = 'button button-primary sitepulse-status-banner__cta';
            bannerCtaEl.dataset.sitepulseBannerCta = '';
            bannerEl.appendChild(bannerCtaEl);
        }

        if (bannerCtaEl) {
            const cta = view && typeof view.cta === 'object' ? view.cta : null;
            const hasCta = !!(cta && typeof cta.label === 'string' && cta.label && typeof cta.url === 'string' && cta.url);

            if (bannerCtaEl.tagName === 'A' && hasCta) {
                bannerCtaEl.textContent = cta.label;
                bannerCtaEl.setAttribute('href', cta.url);
                if (typeof cta.data === 'string' && cta.data) {
                    bannerCtaEl.dataset.cta = cta.data;
                } else {
                    delete bannerCtaEl.dataset.cta;
                }
                toggleHidden(bannerCtaEl, false);
            } else if (bannerCtaEl.tagName === 'A') {
                bannerCtaEl.textContent = '';
                bannerCtaEl.removeAttribute('href');
                delete bannerCtaEl.dataset.cta;
                toggleHidden(bannerCtaEl, true);
            } else {
                bannerCtaEl.textContent = hasCta ? cta.label : '';
                if (hasCta && typeof cta.data === 'string' && cta.data) {
                    bannerCtaEl.dataset.cta = cta.data;
                } else {
                    delete bannerCtaEl.dataset.cta;
                }
                toggleHidden(bannerCtaEl, !hasCta);
            }
        }
    }

    function ensureCardElement(cardKey) {
        if (!metricsGrid) {
            return null;
        }

        const existing = metricsGrid.querySelector(`[data-sitepulse-metric-card="${cardKey}"]`);

        if (existing) {
            return existing;
        }

        const article = document.createElement('article');
        article.className = 'sitepulse-kpi-card';
        article.dataset.sitepulseMetricCard = cardKey;
        article.dataset.status = '';

        article.innerHTML = ''
            + '<header class="sitepulse-kpi-card__header">'
            + '    <h2 class="sitepulse-kpi-card__title" data-sitepulse-metric-label></h2>'
            + '    <span class="status-badge" data-sitepulse-metric-status-badge>'
            + '        <span class="status-icon" data-sitepulse-metric-status-icon></span>'
            + '        <span class="status-text" data-sitepulse-metric-status-label></span>'
            + '    </span>'
            + '    <span class="screen-reader-text" data-sitepulse-metric-status-sr></span>'
            + '</header>'
            + '<p class="sitepulse-kpi-card__value">'
            + '    <span class="sitepulse-kpi-card__value-number" data-sitepulse-metric-value></span>'
            + '    <span class="sitepulse-kpi-card__value-unit" data-sitepulse-metric-unit hidden></span>'
            + '</p>'
            + '<p class="sitepulse-kpi-card__summary" data-sitepulse-metric-summary hidden></p>'
            + '<p class="sitepulse-kpi-card__trend" data-sitepulse-metric-trend data-trend="flat" hidden>'
            + '    <span aria-hidden="true" data-sitepulse-metric-trend-text></span>'
            + '    <span class="screen-reader-text" data-sitepulse-metric-trend-sr></span>'
            + '</p>'
            + '<dl class="sitepulse-kpi-card__details" data-sitepulse-metric-details hidden></dl>'
            + '<p class="sitepulse-kpi-card__description" data-sitepulse-metric-description hidden></p>'
            + '<p class="sitepulse-kpi-card__inactive" data-sitepulse-metric-inactive hidden></p>';

        metricsGrid.appendChild(article);

        return article;
    }

    function removeMissingCards(activeKeys) {
        if (!metricsGrid) {
            return;
        }

        const elements = metricsGrid.querySelectorAll('[data-sitepulse-metric-card]');
        elements.forEach((element) => {
            const key = element.getAttribute('data-sitepulse-metric-card');
            if (activeKeys.indexOf(key) === -1) {
                element.remove();
            }
        });
    }

    function updateCard(cardKey, cardView) {
        const cardEl = ensureCardElement(cardKey);

        if (!cardEl) {
            return;
        }

        const classesToRemove = Array.from(cardEl.classList).filter((className) => className.indexOf('sitepulse-kpi-card--') === 0);
        classesToRemove.forEach((className) => cardEl.classList.remove(className));

        const statusClass = cardView && cardView.status && typeof cardView.status.class === 'string'
            ? cardView.status.class
            : '';

        if (statusClass) {
            cardEl.classList.add(`sitepulse-kpi-card--${statusClass}`);
        }

        if (cardView && cardView.inactive) {
            cardEl.classList.add('sitepulse-kpi-card--inactive');
            cardEl.setAttribute('data-inactive', 'true');
        } else {
            cardEl.classList.remove('sitepulse-kpi-card--inactive');
            cardEl.removeAttribute('data-inactive');
        }

        cardEl.dataset.status = statusClass;

        const labelEl = cardEl.querySelector('[data-sitepulse-metric-label]');
        if (labelEl) {
            labelEl.textContent = cardView && typeof cardView.label === 'string' && cardView.label
                ? cardView.label
                : cardKey.charAt(0).toUpperCase() + cardKey.slice(1);
        }

        const badgeEl = cardEl.querySelector('[data-sitepulse-metric-status-badge]');
        if (badgeEl) {
            badgeEl.className = 'status-badge' + (statusClass ? ` ${statusClass}` : '');
        }

        const statusIconEl = cardEl.querySelector('[data-sitepulse-metric-status-icon]');
        if (statusIconEl) {
            statusIconEl.textContent = cardView && cardView.status && typeof cardView.status.icon === 'string'
                ? cardView.status.icon
                : '';
        }

        const statusLabelEl = cardEl.querySelector('[data-sitepulse-metric-status-label]');
        if (statusLabelEl) {
            statusLabelEl.textContent = cardView && cardView.status && typeof cardView.status.label === 'string'
                ? cardView.status.label
                : '';
        }

        const statusSrEl = cardEl.querySelector('[data-sitepulse-metric-status-sr]');
        if (statusSrEl) {
            statusSrEl.textContent = cardView && cardView.status && typeof cardView.status.sr === 'string'
                ? cardView.status.sr
                : '';
        }

        const valueEl = cardEl.querySelector('[data-sitepulse-metric-value]');
        if (valueEl) {
            valueEl.textContent = cardView && cardView.value && typeof cardView.value.text === 'string'
                ? cardView.value.text
                : '';
        }

        const valueUnitEl = cardEl.querySelector('[data-sitepulse-metric-unit]');
        if (valueUnitEl) {
            const unit = cardView && cardView.value && typeof cardView.value.unit === 'string'
                ? cardView.value.unit
                : '';
            valueUnitEl.textContent = unit;
            toggleHidden(valueUnitEl, !unit);
        }

        const summaryEl = cardEl.querySelector('[data-sitepulse-metric-summary]');
        if (summaryEl) {
            const summary = cardView && typeof cardView.summary === 'string' ? cardView.summary : '';
            summaryEl.textContent = summary;
            toggleHidden(summaryEl, !summary);
        }

        const trendEl = cardEl.querySelector('[data-sitepulse-metric-trend]');
        if (trendEl) {
            const trend = cardView && cardView.trend ? cardView.trend : null;
            const trendText = trend && typeof trend.text === 'string' ? trend.text : '';
            const trendDirection = trend && typeof trend.direction === 'string' ? trend.direction : 'flat';
            trendEl.dataset.trend = trendDirection;
            const trendTextEl = trendEl.querySelector('[data-sitepulse-metric-trend-text]');
            if (trendTextEl) {
                trendTextEl.textContent = trendText;
            }
            const trendSrEl = trendEl.querySelector('[data-sitepulse-metric-trend-sr]');
            if (trendSrEl) {
                trendSrEl.textContent = trend && typeof trend.sr === 'string' ? trend.sr : '';
            }
            toggleHidden(trendEl, !trendText);
        }

        const detailsEl = cardEl.querySelector('[data-sitepulse-metric-details]');
        if (detailsEl) {
            const details = Array.isArray(cardView && cardView.details) ? cardView.details : [];
            detailsEl.innerHTML = '';

            details.forEach((detail) => {
                if (!detail || (typeof detail.label !== 'string' && typeof detail.value !== 'string')) {
                    return;
                }

                const detailLabel = typeof detail.label === 'string' ? detail.label : '';
                const detailValue = typeof detail.value === 'string' ? detail.value : '';

                if (!detailLabel && !detailValue) {
                    return;
                }

                const wrapper = document.createElement('div');
                wrapper.className = 'sitepulse-kpi-card__detail';

                const dt = document.createElement('dt');
                dt.textContent = detailLabel;
                const dd = document.createElement('dd');
                dd.textContent = detailValue;

                wrapper.appendChild(dt);
                wrapper.appendChild(dd);
                detailsEl.appendChild(wrapper);
            });

            toggleHidden(detailsEl, detailsEl.children.length === 0);
        }

        const descriptionEl = cardEl.querySelector('[data-sitepulse-metric-description]');
        if (descriptionEl) {
            const description = cardView && typeof cardView.description === 'string' ? cardView.description : '';
            descriptionEl.textContent = description;
            toggleHidden(descriptionEl, !description);
        }

        const inactiveEl = cardEl.querySelector('[data-sitepulse-metric-inactive]');
        if (inactiveEl) {
            const inactiveMessage = cardView && typeof cardView.inactive_message === 'string'
                ? cardView.inactive_message
                : '';
            inactiveEl.textContent = inactiveMessage;
            toggleHidden(inactiveEl, !(cardView && cardView.inactive && inactiveMessage));
        }
    }

    function renderView(view) {
        if (!view || typeof view !== 'object') {
            return;
        }

        currentView = view;
        currentRange = typeof view.range === 'string' ? view.range : currentRange;

        syncRangeControls(currentRange);

        if (rangeLabelEl) {
            rangeLabelEl.textContent = typeof view.range_label === 'string' ? view.range_label : formatRangeLabel(currentRange);
        }

        if (generatedEl) {
            generatedEl.textContent = typeof view.generated_text === 'string' ? view.generated_text : '';
        }

        updateBanner(view.banner || {});

        const cards = view.cards || {};
        const keys = Object.keys(cards);
        keys.forEach((key) => {
            updateCard(key, cards[key]);
        });
        removeMissingCards(keys);
    }

    function handleError(error) {
        if (errorEl) {
            errorEl.textContent = strings.error;
            toggleHidden(errorEl, false);
        }

        speak(strings.error, 'assertive');
        // eslint-disable-next-line no-console
        console.error(error);
    }

    function clearError() {
        if (errorEl) {
            errorEl.textContent = '';
            toggleHidden(errorEl, true);
        }
    }

    function requestMetrics(range) {
        if (!data.restUrl) {
            return Promise.reject(new Error('Missing REST endpoint.'));
        }

        const params = new URLSearchParams();
        if (range) {
            params.append('range', range);
        }

        const query = params.toString();
        const url = query ? `${data.restUrl}?${query}` : data.restUrl;

        const headers = new Headers();
        if (typeof data.nonce === 'string' && data.nonce) {
            headers.append('X-WP-Nonce', data.nonce);
        }

        return fetch(url, {
            credentials: 'same-origin',
            headers,
        }).then((response) => {
            if (!response.ok) {
                throw new Error(`Request failed with status ${response.status}`);
            }

            return response.json();
        });
    }

    function onRangeChange(newRange) {
        const rangeId = sanitizeRangeId(newRange);

        if (!rangeId || (rangeId === currentRange && !isFetching)) {
            return;
        }

        clearError();
        syncRangeControls(rangeId);
        setLoading(true);

        requestMetrics(rangeId)
            .then((payload) => {
                if (!payload || typeof payload !== 'object') {
                    throw new Error('Invalid payload.');
                }

                if (Array.isArray(payload.available_ranges)) {
                    rangeOptions = payload.available_ranges.slice();
                    buildRangeControls(rangeOptions, rangeId);
                }

                if (payload.view && typeof payload.view === 'object') {
                    renderView(payload.view);
                }

                setLoading(false);
                clearError();

                const rangeLabel = formatRangeLabel(rangeId);
                const announcement = strings.announcement.replace('%s', rangeLabel);
                speak(announcement, 'polite');
            })
            .catch((error) => {
                setLoading(false);
                syncRangeControls(currentRange);
                handleError(error);
            });
    }

    root.addEventListener('change', (event) => {
        const target = event.target;

        if (target && target.matches(selectors.rangeOptions)) {
            onRangeChange(target.value);
        }

        if (target && target.matches(selectors.rangeSelect)) {
            onRangeChange(target.value);
        }
    });

    if (fieldset) {
        buildRangeControls(rangeOptions, currentRange);
    } else {
        syncRangeControls(currentRange);
    }

    toggleHidden(errorEl, true);
    renderView(currentView);
})();
