(function (window, document) {
    'use strict';

    var settings = window.SitePulseSpeedAnalyzer || {};
    var chartInstance = null;
    var automation = settings.automation || { presets: {} };
    var currentSourceKey = 'manual';
    var profileCatalog = {};
    var manualProfile = settings.manualProfile || null;
    var manualThresholds = {};
    var activeSourceCatalog = {};
    var profilerSettings = settings.profiler || null;

    updateProfileCatalog(settings.profiles || {});
    updateManualProfile(manualProfile || {});
    updateManualThresholds(settings.thresholds || {});

    function cloneThresholds(thresholds) {
        var clone = {};

        if (thresholds && typeof thresholds === 'object') {
            if (typeof thresholds.warning !== 'undefined') {
                clone.warning = Number(thresholds.warning);
            }

            if (typeof thresholds.critical !== 'undefined') {
                clone.critical = Number(thresholds.critical);
            }

            if (typeof thresholds.profile === 'string') {
                clone.profile = thresholds.profile;
            }
        }

        return clone;
    }

    function getProfileMeta(slug) {
        var normalized = typeof slug === 'string' && slug ? slug : '';

        if (!normalized) {
            if (manualProfile && manualProfile.slug) {
                normalized = manualProfile.slug;
            } else if (manualThresholds.profile) {
                normalized = manualThresholds.profile;
            } else {
                normalized = 'default';
            }
        }

        var meta = profileCatalog[normalized] || {};
        var label = meta.label || '';
        var description = meta.description || '';

        if ((!label || !description) && manualProfile && manualProfile.slug === normalized) {
            if (!label && manualProfile.label) {
                label = manualProfile.label;
            }

            if (!description && manualProfile.description) {
                description = manualProfile.description;
            }
        }

        if (!label) {
            label = normalized.charAt(0).toUpperCase() + normalized.slice(1);
        }

        return {
            slug: normalized,
            label: label,
            description: description || ''
        };
    }

    function updateProfileCatalog(profiles) {
        if (!profiles || typeof profiles !== 'object') {
            return;
        }

        var updated = {};

        Object.keys(profiles).forEach(function (slug) {
            if (typeof slug !== 'string' || !slug) {
                return;
            }

            var meta = profiles[slug] || {};

            updated[slug] = {
                label: typeof meta.label === 'string' ? meta.label : '',
                description: typeof meta.description === 'string' ? meta.description : ''
            };
        });

        profileCatalog = updated;
    }

    function updateManualProfile(profile) {
        if (!profile || typeof profile !== 'object') {
            return;
        }

        var slug = typeof profile.slug === 'string' && profile.slug
            ? profile.slug
            : (manualProfile && manualProfile.slug) || manualThresholds.profile || 'default';

        manualProfile = {
            slug: slug,
            label: typeof profile.label === 'string'
                ? profile.label
                : (manualProfile && manualProfile.slug === slug ? manualProfile.label : ''),
            description: typeof profile.description === 'string'
                ? profile.description
                : (manualProfile && manualProfile.slug === slug ? manualProfile.description : '')
        };

        if (manualProfile.slug && manualThresholds.profile !== manualProfile.slug) {
            manualThresholds.profile = manualProfile.slug;
        }
    }

    function updateManualThresholds(thresholds) {
        manualThresholds = cloneThresholds(thresholds || {});

        if (!manualThresholds.profile) {
            if (manualProfile && manualProfile.slug) {
                manualThresholds.profile = manualProfile.slug;
            } else if (settings.thresholds && settings.thresholds.profile) {
                manualThresholds.profile = settings.thresholds.profile;
            } else {
                manualThresholds.profile = 'default';
            }
        }
    }

    function buildSourceCatalog(meta) {
        var catalog = {};

        if (!Array.isArray(meta)) {
            return catalog;
        }

        meta.forEach(function (item) {
            if (!item || typeof item !== 'object') {
                return;
            }

            var key = typeof item.key === 'string' && item.key ? item.key : null;

            if (!key) {
                return;
            }

            catalog[key] = {
                key: key,
                label: typeof item.label === 'string' ? item.label : '',
                type: typeof item.type === 'string' ? item.type : 'site',
                profile: typeof item.profile === 'string' ? item.profile : null,
                budget: typeof item.budget === 'number' && isFinite(item.budget) ? item.budget : null
            };
        });

        return catalog;
    }

    function sanitizeHistory(history, sourceCatalog) {
        if (!Array.isArray(history)) {
            return [];
        }

        var catalog = sourceCatalog && typeof sourceCatalog === 'object' ? sourceCatalog : {};

        return history
            .map(function (entry) {
                if (!entry || typeof entry !== 'object') {
                    return null;
                }

                var timestamp = parseInt(entry.timestamp, 10);
                var value = typeof entry.server_processing_ms === 'number'
                    ? entry.server_processing_ms
                    : parseFloat(entry.server_processing_ms);
                var sourceKey = typeof entry.source === 'string' && entry.source
                    ? entry.source
                    : 'site';
                var sourceMeta = catalog[sourceKey] || {};
                var sourceLabel = typeof entry.source_label === 'string' && entry.source_label
                    ? entry.source_label
                    : (sourceMeta.label || (sourceKey === 'site'
                        ? (settings.i18n && settings.i18n.ownSourceLabel ? settings.i18n.ownSourceLabel : 'Site')
                        : (settings.i18n && settings.i18n.competitorSourceLabel ? settings.i18n.competitorSourceLabel : 'Concurrent')));
                var sourceType = typeof entry.source_type === 'string' && entry.source_type
                    ? entry.source_type
                    : (sourceMeta.type || (sourceKey === 'site' ? 'site' : 'competitor'));
                var profile = typeof entry.profile === 'string' && entry.profile
                    ? entry.profile
                    : (sourceMeta.profile || null);
                var budget = null;

                if (typeof entry.benchmark_budget === 'number' && isFinite(entry.benchmark_budget)) {
                    budget = entry.benchmark_budget;
                } else if (typeof sourceMeta.budget === 'number' && isFinite(sourceMeta.budget)) {
                    budget = sourceMeta.budget;
                }

                if (!isFinite(timestamp) || timestamp <= 0 || !isFinite(value) || value < 0) {
                    return null;
                }

                return {
                    timestamp: timestamp,
                    server_processing_ms: value,
                    source: sourceKey,
                    source_label: sourceLabel,
                    source_type: sourceType,
                    profile: profile,
                    benchmark_budget: budget,
                    url: typeof entry.url === 'string' ? entry.url : null
                };
            })
            .filter(function (entry) {
                return !!entry;
            })
            .sort(function (a, b) {
                return a.timestamp - b.timestamp;
            });
    }

    function buildMetaMap(detailedHistory) {
        if (!Array.isArray(detailedHistory)) {
            return {};
        }

        return detailedHistory.reduce(function (accumulator, entry) {
            if (!entry || typeof entry !== 'object') {
                return accumulator;
            }

            var timestamp = parseInt(entry.timestamp, 10);

            if (!isFinite(timestamp) || timestamp <= 0) {
                return accumulator;
            }

            accumulator[timestamp] = entry;

            return accumulator;
        }, {});
    }

    function getAutomationPreset(slug) {
        if (!automation || typeof automation !== 'object') {
            return null;
        }

        if (!automation.presets || typeof automation.presets !== 'object') {
            return null;
        }

        if (!Object.prototype.hasOwnProperty.call(automation.presets, slug)) {
            return null;
        }

        return automation.presets[slug];
    }

    function getSourceData(sourceKey) {
        if (!sourceKey || sourceKey === 'manual') {
            return {
                key: 'manual',
                history: settings.history || [],
                detailedHistory: null,
                aggregates: settings.aggregates || {},
                recommendations: settings.recommendations || [],
                profile: manualThresholds.profile,
                thresholds: cloneThresholds(manualThresholds),
                sourcesMeta: [
                    {
                        key: 'site',
                        label: (settings.i18n && settings.i18n.ownSourceLabel) || '',
                        type: 'site',
                        profile: manualThresholds.profile || null,
                        budget: null
                    }
                ]
            };
        }

        if (sourceKey.indexOf('automation:') === 0) {
            var slug = sourceKey.substring('automation:'.length);
            var preset = getAutomationPreset(slug);

            if (preset) {
                return {
                    key: sourceKey,
                    history: preset.history || [],
                    detailedHistory: preset.detailedHistory || [],
                    aggregates: preset.aggregates || {},
                    recommendations: [],
                    profile: preset.profile || (preset.thresholds && preset.thresholds.profile) || null,
                    thresholds: cloneThresholds(preset.thresholds || {}),
                    sourcesMeta: Array.isArray(preset.sources) ? preset.sources : []
                };
            }
        }

        return null;
    }

    function updateProfileBadge(profileKey, dom) {
        if (!dom || !dom.profileBadge) {
            return;
        }

        var badge = dom.profileBadge;
        var meta = getProfileMeta(profileKey);
        var labelEl = badge.querySelector('.profile-label');
        var descriptionEl = badge.querySelector('.profile-description');

        badge.dataset.profile = meta.slug;

        if (labelEl) {
            labelEl.textContent = meta.label || '';
        }

        if (descriptionEl) {
            if (meta.description) {
                descriptionEl.textContent = meta.description;
                descriptionEl.style.display = '';
            } else {
                descriptionEl.textContent = '';
                descriptionEl.style.display = 'none';
            }
        }
    }

    function applySource(sourceKey, dom) {
        var source = getSourceData(sourceKey);

        if (!source) {
            source = getSourceData('manual');
            currentSourceKey = 'manual';

            if (dom.sourceSelect) {
                dom.sourceSelect.value = 'manual';
            }
        } else {
            currentSourceKey = sourceKey;
        }

        var thresholds = cloneThresholds(source.thresholds || {});

        if (!thresholds.profile) {
            thresholds.profile = source.profile || manualThresholds.profile;
        }

        if (typeof thresholds.warning === 'undefined' && typeof manualThresholds.warning !== 'undefined') {
            thresholds.warning = manualThresholds.warning;
        }

        if (typeof thresholds.critical === 'undefined' && typeof manualThresholds.critical !== 'undefined') {
            thresholds.critical = manualThresholds.critical;
        }

        settings.thresholds = thresholds;
        updateProfileBadge(thresholds.profile, dom);

        renderHistory(source.history, source.detailedHistory, dom, source.sourcesMeta || []);
        renderSummary(source.aggregates || {}, dom);
        updateRecommendations(source.recommendations || [], dom);
    }

    function getStatusMeta(status) {
        var labels = settings.statusLabels || {};

        if (labels[status]) {
            return labels[status];
        }

        return labels['status-warn'] || { icon: '', label: '', sr: '' };
    }

    function buildThresholdDatasets(length) {
        var thresholds = settings.thresholds || {};
        var datasets = [];
        var i18n = settings.i18n || {};

        if (!length) {
            return datasets;
        }

        var warning = Number(thresholds.warning);
        var critical = Number(thresholds.critical);

        if (Number.isFinite(warning)) {
            datasets.push({
                label: i18n.warningThresholdLabel || '',
                data: new Array(length).fill(warning),
                borderColor: '#f0ad4e',
                borderWidth: 1,
                borderDash: [6, 4],
                pointRadius: 0,
                pointHitRadius: 0,
                fill: false,
                tension: 0,
                order: 0,
                tooltip: {
                    enabled: false
                }
            });
        }

        if (Number.isFinite(critical)) {
            datasets.push({
                label: i18n.criticalThresholdLabel || '',
                data: new Array(length).fill(critical),
                borderColor: '#d63638',
                borderWidth: 1,
                borderDash: [4, 4],
                pointRadius: 0,
                pointHitRadius: 0,
                fill: false,
                tension: 0,
                order: 1,
                tooltip: {
                    enabled: false
                }
            });
        }

        return datasets;
    }

    function buildTooltipLines() {
        var aggregates = settings.aggregates || {};
        var metrics = aggregates.metrics || {};
        var summaryMeta = settings.summaryMeta || {};
        var i18n = settings.i18n || {};
        var unit = i18n.summaryUnit || 'ms';
        var noData = i18n.summaryNoData || '';
        var order = ['mean', 'median', 'p95', 'best', 'worst'];
        var lines = [];

        order.forEach(function (key) {
            if (!Object.prototype.hasOwnProperty.call(summaryMeta, key)) {
                return;
            }

            var meta = summaryMeta[key] || {};
            var metric = metrics[key] || {};
            var label = meta.label || key;
            var value = metric.value;

            if (typeof value === 'number' && isFinite(value)) {
                value = value.toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }) + ' ' + unit;
            } else {
                value = noData;
            }

            lines.push(label + ': ' + value);
        });

        return lines;
    }

    function tooltipAfterBody() {
        return buildTooltipLines();
    }

    function renderSummary(aggregates, dom) {
        var grid = dom.summaryGrid;
        var meta = settings.summaryMeta || {};
        var i18n = settings.i18n || {};
        var unit = i18n.summaryUnit || 'ms';
        var noData = i18n.summaryNoData || 'N/A';

        if (!grid) {
            return;
        }

        var metrics = aggregates && aggregates.metrics ? aggregates.metrics : {};

        Object.keys(meta).forEach(function (key) {
            var card = grid.querySelector('[data-metric="' + key + '"]');

            if (!card) {
                return;
            }

            var metric = metrics[key] || {};
            var status = metric.status || 'status-warn';
            var badge = card.querySelector('.status-badge');
            var sr = card.querySelector('[data-summary-sr]');
            var valueEl = card.querySelector('[data-summary-value]');
            var statusMeta = getStatusMeta(status);

            if (badge) {
                badge.classList.remove('status-ok', 'status-warn', 'status-bad');
                badge.classList.add(status);

                var icon = badge.querySelector('.status-icon');
                var text = badge.querySelector('.status-text');

                if (icon && typeof statusMeta.icon !== 'undefined') {
                    icon.textContent = statusMeta.icon;
                }

                if (text && typeof statusMeta.label !== 'undefined') {
                    text.textContent = statusMeta.label;
                }
            }

            if (sr && typeof statusMeta.sr !== 'undefined') {
                sr.textContent = statusMeta.sr;
            }

            if (valueEl) {
                var value = metric.value;

                if (typeof value === 'number' && isFinite(value)) {
                    valueEl.textContent = value.toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) + ' ' + unit;
                } else {
                    valueEl.textContent = noData;
                }
            }
        });

        var noteEl = dom.summaryNote;

        if (noteEl) {
            var noteParts = [];
            var count = aggregates && typeof aggregates.count === 'number' ? aggregates.count : 0;
            var excluded = aggregates && typeof aggregates.excluded_outliers === 'number' ? aggregates.excluded_outliers : 0;

            if (count > 0) {
                var sampleTemplate = count === 1 ? i18n.summarySampleSingular : i18n.summarySamplePlural;

                if (sampleTemplate) {
                    noteParts.push(sampleTemplate.replace('%d', count));
                }
            }

            if (excluded > 0) {
                var outlierTemplate = excluded === 1 ? i18n.summaryOutlierSingular : i18n.summaryOutlierPlural;

                if (outlierTemplate) {
                    noteParts.push(outlierTemplate.replace('%d', excluded));
                }
            }

            noteEl.textContent = noteParts.join(' ');
        }
    }

    function formatTimestamp(timestamp) {
        var date = new Date(timestamp * 1000);

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleString();
    }

    function renderHistory(history, detailedHistory, dom, sourcesMeta) {
        activeSourceCatalog = buildSourceCatalog(sourcesMeta || []);
        var sanitized = sanitizeHistory(history, activeSourceCatalog);
        var canvas = dom.canvas;
        var tableBody = dom.tableBody;
        var i18n = settings.i18n || {};
        var metaMap = buildMetaMap(detailedHistory);
        var timestamps = [];
        var seriesMap = {};

        sanitized.forEach(function (entry) {
            if (timestamps.indexOf(entry.timestamp) === -1) {
                timestamps.push(entry.timestamp);
            }

            var sourceKey = entry.source || 'site';

            if (!seriesMap[sourceKey]) {
                var meta = activeSourceCatalog[sourceKey] || {};
                var label = entry.source_label || meta.label || (sourceKey === 'site'
                    ? (i18n.ownSourceLabel || 'Site')
                    : (i18n.competitorSourceLabel || 'Concurrent'));
                seriesMap[sourceKey] = {
                    key: sourceKey,
                    label: label,
                    type: entry.source_type || meta.type || 'site',
                    budget: typeof entry.benchmark_budget === 'number' ? entry.benchmark_budget : (meta.budget || null),
                    data: {}
                };
            }

            seriesMap[sourceKey].data[entry.timestamp] = entry.server_processing_ms;
        });

        timestamps.sort(function (a, b) {
            return a - b;
        });

        if (tableBody) {
            tableBody.innerHTML = '';

            if (!sanitized.length) {
                var emptyRow = document.createElement('tr');
                var emptyCell = document.createElement('td');
                emptyCell.colSpan = 4;
                emptyCell.textContent = i18n.noHistory || '';
                emptyRow.appendChild(emptyCell);
                tableBody.appendChild(emptyRow);
            } else {
                sanitized.forEach(function (entry) {
                    var row = document.createElement('tr');
                    var dateCell = document.createElement('td');
                    var sourceCell = document.createElement('td');
                    var valueCell = document.createElement('td');
                    var statusCell = document.createElement('td');
                    statusCell.className = 'speed-history-status';
                    dateCell.textContent = formatTimestamp(entry.timestamp);
                    sourceCell.textContent = entry.source_label || (i18n.ownSourceLabel || 'Site');
                    valueCell.textContent = entry.server_processing_ms.toFixed(2);

                    var meta = metaMap[entry.timestamp] || null;
                    var statusText = '—';

                    statusCell.classList.remove('status-ok', 'status-error');

                    if (meta) {
                        if (meta.error) {
                            statusText = String(meta.error);
                            statusCell.classList.add('status-error');
                        } else if (typeof meta.http_code !== 'undefined') {
                            var code = parseInt(meta.http_code, 10);

                            if (Number.isFinite(code) && code > 0) {
                                statusText = String(code);

                                if (code >= 400) {
                                    statusCell.classList.add('status-error');
                                } else {
                                    statusCell.classList.add('status-ok');
                                }
                            }
                        }
                    }

                    statusCell.textContent = statusText;

                    row.appendChild(dateCell);
                    row.appendChild(sourceCell);
                    row.appendChild(valueCell);
                    row.appendChild(statusCell);
                    tableBody.appendChild(row);
                });
            }
        }

        if (!canvas || typeof window.Chart === 'undefined') {
            return;
        }

        var labels = timestamps.map(function (timestamp) {
            return formatTimestamp(timestamp);
        });

        var datasets = [];
        var colorPalette = {
            site: {
                border: '#0073aa',
                background: 'rgba(0, 115, 170, 0.15)'
            },
            competitor: {
                border: '#d63638',
                background: 'rgba(214, 54, 56, 0.15)'
            }
        };

        Object.keys(seriesMap).forEach(function (sourceKey, index) {
            var series = seriesMap[sourceKey];
            var palette = colorPalette[series.type] || colorPalette.site;
            var seriesValues = timestamps.map(function (timestamp) {
                if (Object.prototype.hasOwnProperty.call(series.data, timestamp)) {
                    return series.data[timestamp];
                }

                return null;
            });

            datasets.push({
                label: series.label,
                data: seriesValues,
                borderColor: palette.border,
                backgroundColor: palette.background,
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: palette.border,
                tension: 0.25,
                fill: series.type !== 'competitor',
                spanGaps: true,
                order: 2 + index
            });

            if (series.type === 'site' && typeof series.budget === 'number') {
                datasets.push({
                    label: (i18n.budgetLabel || 'Budget') + ' – ' + series.label,
                    data: new Array(timestamps.length).fill(series.budget),
                    borderColor: '#46b450',
                    borderWidth: 1,
                    borderDash: [4, 4],
                    pointRadius: 0,
                    fill: false,
                    tension: 0,
                    order: 1
                });
            }
        });

        datasets = datasets.concat(buildThresholdDatasets(timestamps.length));

        if (!chartInstance) {
            chartInstance = new window.Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function (value) {
                                    return value + ' ms';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    var label = context.dataset.label ? context.dataset.label + ': ' : '';
                                    return label + context.parsed.y.toFixed(2) + ' ms';
                                },
                                afterBody: tooltipAfterBody
                            }
                        }
                    }
                }
            });
        } else {
            chartInstance.data.labels = labels;
            chartInstance.data.datasets = datasets;
            chartInstance.options.plugins.tooltip.callbacks.afterBody = tooltipAfterBody;
            chartInstance.options.plugins.legend.display = true;
            chartInstance.update();
        }
    }

    function updateRecommendations(recommendations, dom) {
        var list = dom.recommendations;
        var i18n = settings.i18n || {};

        if (!list) {
            return;
        }

        list.innerHTML = '';

        if (!Array.isArray(recommendations) || !recommendations.length) {
            var item = document.createElement('li');
            item.textContent = i18n.noHistory || '';
            list.appendChild(item);
            return;
        }

        recommendations.forEach(function (recommendation) {
            var li = document.createElement('li');
            li.textContent = recommendation;
            list.appendChild(li);
        });
    }

    function setStatus(message, type, dom) {
        var statusEl = dom.status;

        if (!statusEl) {
            return;
        }

        statusEl.textContent = message || '';
        statusEl.className = 'sitepulse-speed-status';

        if (type) {
            statusEl.classList.add('status-' + type);
        }
    }

    function setButtonState(isRunning, dom) {
        var button = dom.button;
        var i18n = settings.i18n || {};

        if (!button) {
            return;
        }

        if (typeof button.dataset.originalLabel === 'undefined') {
            button.dataset.originalLabel = button.textContent || '';
        }

        if (isRunning) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = i18n.running || button.dataset.originalLabel;
        } else {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = i18n.retry || button.dataset.originalLabel;
        }
    }

    function handleError(message, dom) {
        var i18n = settings.i18n || {};
        setStatus(message || i18n.error || '', 'error', dom);
        setButtonState(false, dom);
    }

    function initProfilerFeature() {
        if (!profilerSettings || !profilerSettings.enabled) {
            return;
        }

        var button = document.getElementById('sitepulse-speed-profiler-run');
        var statusEl = document.getElementById('sitepulse-speed-profiler-status');
        var resultsEl = document.getElementById('sitepulse-speed-profiler-results');

        if (!button || !statusEl || !resultsEl) {
            return;
        }

        var state = {
            button: button,
            status: statusEl,
            results: resultsEl,
            hooksBody: resultsEl.querySelector('[data-role="profiler-hooks"]'),
            queriesBody: resultsEl.querySelector('[data-role="profiler-queries"]'),
            token: null,
            pollTimer: null,
            startedAt: 0,
            running: false
        };

        button.addEventListener('click', function (event) {
            event.preventDefault();

            if (state.running) {
                return;
            }

            startProfilerSession(state);
        });

        setProfilerButtonState(state, 'idle');
    }

    function setProfilerButtonState(state, mode) {
        if (!state || !state.button) {
            return;
        }

        var button = state.button;
        var i18n = (profilerSettings && profilerSettings.i18n) || {};

        if (typeof button.dataset.profilerIdleLabel === 'undefined') {
            button.dataset.profilerIdleLabel = button.textContent || '';
        }

        if (mode === 'running') {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = i18n.buttonRunning || button.dataset.profilerIdleLabel;
        } else if (mode === 'retry') {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = i18n.buttonRetry || button.dataset.profilerIdleLabel;
        } else {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = i18n.buttonIdle || button.dataset.profilerIdleLabel;
        }
    }

    function updateProfilerStatus(state, message, tone) {
        if (!state || !state.status) {
            return;
        }

        var statusEl = state.status;
        statusEl.textContent = message || '';
        statusEl.className = 'sitepulse-speed-profiler__status';

        if (tone) {
            statusEl.classList.add('is-' + tone);
        }
    }

    function clearProfilerTimer(state) {
        if (!state || !state.pollTimer) {
            return;
        }

        window.clearTimeout(state.pollTimer);
        state.pollTimer = null;
    }

    function startProfilerSession(state) {
        if (!profilerSettings || !profilerSettings.startAction || !profilerSettings.fetchAction) {
            handleProfilerFailure(state, 'statusFailed');
            return;
        }

        var ajaxUrl = settings.ajaxUrl;

        if (!ajaxUrl || !profilerSettings.nonce) {
            handleProfilerFailure(state, 'statusFailed');
            return;
        }

        state.running = true;
        state.startedAt = Date.now();
        state.token = null;
        state.results.hidden = true;

        if (state.hooksBody) {
            state.hooksBody.innerHTML = '';
        }

        if (state.queriesBody) {
            state.queriesBody.innerHTML = '';
        }

        setProfilerButtonState(state, 'running');
        updateProfilerStatus(state, (profilerSettings.i18n && profilerSettings.i18n.statusTrigger) || '', 'info');

        var formData = new window.FormData();
        formData.append('action', profilerSettings.startAction);
        formData.append('nonce', profilerSettings.nonce);
        formData.append('target', window.location.href);

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function (response) {
                return response.json()
                    .catch(function () {
                        return {};
                    })
                    .then(function (payload) {
                        return { ok: response.ok, payload: payload };
                    });
            })
            .then(function (result) {
                if (!result || !result.payload || !result.payload.success) {
                    handleProfilerFailure(state, 'statusFailed');
                    return;
                }

                var data = result.payload.data || {};

                if (!data.token || !data.url) {
                    handleProfilerFailure(state, 'statusFailed');
                    return;
                }

                state.token = data.token;
                triggerProfilerRequest(state, data.url);
            })
            .catch(function () {
                handleProfilerFailure(state, 'statusFailed');
            });
    }

    function triggerProfilerRequest(state, url) {
        if (!url) {
            handleProfilerFailure(state, 'statusFailed');
            return;
        }

        updateProfilerStatus(state, (profilerSettings.i18n && profilerSettings.i18n.statusPending) || '', 'info');

        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .catch(function () {
                // Even if the request fails, attempt to poll once in case the trace succeeded.
            })
            .then(function () {
                scheduleProfilerPoll(state, profilerSettings.pollInterval || 2000);
            });
    }

    function scheduleProfilerPoll(state, delay) {
        if (!state) {
            return;
        }

        clearProfilerTimer(state);

        state.pollTimer = window.setTimeout(function () {
            pollProfilerResult(state);
        }, delay);
    }

    function pollProfilerResult(state) {
        if (!state.running || !state.token) {
            return;
        }

        var ajaxUrl = settings.ajaxUrl;

        if (!ajaxUrl || !profilerSettings || !profilerSettings.fetchAction) {
            handleProfilerFailure(state, 'statusFailed');
            return;
        }

        var timeout = profilerSettings.timeout || 30000;

        if (Date.now() - state.startedAt > timeout) {
            handleProfilerFailure(state, 'statusFailed');
            return;
        }

        var formData = new window.FormData();
        formData.append('action', profilerSettings.fetchAction);
        formData.append('nonce', profilerSettings.nonce);
        formData.append('token', state.token);

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function (response) {
                return response.json()
                    .catch(function () {
                        return {};
                    })
                    .then(function (payload) {
                        return { ok: response.ok, payload: payload };
                    });
            })
            .then(function (result) {
                if (!result || !result.payload || !result.payload.success) {
                    handleProfilerFailure(state, 'statusFailed');
                    return;
                }

                var data = result.payload.data || {};
                var status = data.status || 'pending';

                if (status === 'pending') {
                    scheduleProfilerPoll(state, profilerSettings.pollInterval || 2000);
                    return;
                }

                if (status === 'failed') {
                    handleProfilerFailure(state, 'statusFailed');
                    return;
                }

                clearProfilerTimer(state);
                state.running = false;
                state.token = null;

                renderProfilerResults(state, data);
            })
            .catch(function () {
                handleProfilerFailure(state, 'statusFailed');
            });
    }

    function handleProfilerFailure(state, messageKey) {
        clearProfilerTimer(state);

        if (state) {
            state.running = false;
            state.token = null;

            if (state.results) {
                state.results.hidden = true;
            }
        }

        var i18n = (profilerSettings && profilerSettings.i18n) || {};
        var message = messageKey && i18n[messageKey] ? i18n[messageKey] : (i18n.statusFailed || '');

        updateProfilerStatus(state, message, 'error');
        setProfilerButtonState(state, 'retry');
    }

    function renderProfilerResults(state, payload) {
        if (!state || !state.results) {
            return;
        }

        var hooks = Array.isArray(payload.hooks) ? payload.hooks : [];
        var queries = Array.isArray(payload.queries) ? payload.queries : [];
        var i18n = (profilerSettings && profilerSettings.i18n) || {};

        state.results.hidden = false;

        renderProfilerRows(state.hooksBody, hooks, 5, i18n.noHooks || '', function (entry) {
            return [
                entry.hook || '',
                formatNumber(entry.count || 0, 0),
                formatNumber(entry.total_ms || 0, 2),
                formatNumber(entry.avg_ms || 0, 2),
                formatNumber(entry.max_ms || 0, 2)
            ];
        });

        renderProfilerRows(state.queriesBody, queries, 5, i18n.noQueries || '', function (entry) {
            var callers = Array.isArray(entry.callers) ? entry.callers.slice(0, 5) : [];

            return [
                entry.sql || '',
                formatNumber(entry.count || 0, 0),
                formatNumber(entry.total_ms || 0, 2),
                formatNumber(entry.avg_ms || 0, 2),
                callers.join(', ')
            ];
        });

        var summary = payload.summary || {};
        var pieces = [];

        if (typeof summary.duration_ms === 'number' && isFinite(summary.duration_ms)) {
            pieces.push(formatNumber(summary.duration_ms, 2) + ' ms');
        }

        if (typeof summary.hook_count === 'number' && isFinite(summary.hook_count)) {
            pieces.push(formatNumber(summary.hook_count, 0) + ' hooks');
        }

        if (typeof summary.query_count === 'number' && isFinite(summary.query_count)) {
            pieces.push(formatNumber(summary.query_count, 0) + ' SQL');
        }

        var statusMessage = i18n.statusCompleted || '';

        if (pieces.length) {
            statusMessage += statusMessage ? ' (' + pieces.join(' · ') + ')' : pieces.join(' · ');
        }

        updateProfilerStatus(state, statusMessage, 'success');
        setProfilerButtonState(state, 'retry');
    }

    function renderProfilerRows(body, items, columns, emptyMessage, mapRow) {
        if (!body) {
            return;
        }

        body.innerHTML = '';

        if (!Array.isArray(items) || items.length === 0) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = columns;
            emptyCell.textContent = emptyMessage || '';
            emptyRow.appendChild(emptyCell);
            body.appendChild(emptyRow);
            return;
        }

        items.forEach(function (item) {
            var values = mapRow(item) || [];
            var row = document.createElement('tr');

            values.forEach(function (value) {
                var cell = document.createElement('td');
                var text = value;

                if (text === null || typeof text === 'undefined') {
                    text = '';
                }

                cell.textContent = typeof text === 'string' ? text : String(text);
                row.appendChild(cell);
            });

            body.appendChild(row);
        });
    }

    function formatNumber(value, decimals) {
        var number = Number(value);

        if (!isFinite(number)) {
            number = 0;
        }

        var options = {};

        if (typeof decimals === 'number') {
            options.minimumFractionDigits = decimals;
            options.maximumFractionDigits = decimals;
        }

        try {
            return number.toLocaleString(undefined, options);
        } catch (error) {
            if (typeof decimals === 'number') {
                return number.toFixed(decimals);
            }

            return String(number);
        }
    }

    function init() {
        var dom = {
            button: document.getElementById('sitepulse-speed-rescan'),
            status: document.getElementById('sitepulse-speed-scan-status'),
            canvas: document.getElementById('sitepulse-speed-history-chart'),
            tableBody: document.querySelector('#sitepulse-speed-history-table tbody'),
            recommendations: document.querySelector('#sitepulse-speed-recommendations ul'),
            summaryGrid: document.getElementById('sitepulse-speed-summary-grid'),
            summaryNote: document.getElementById('sitepulse-speed-summary-note'),
            sourceSelect: document.getElementById('sitepulse-speed-history-source'),
            queueWarning: document.querySelector('.speed-history-queue-warning'),
            profileBadge: document.getElementById('sitepulse-speed-history-profile')
        };

        applySource(currentSourceKey, dom);

        initProfilerFeature();

        if (dom.sourceSelect) {
            dom.sourceSelect.value = currentSourceKey;
            dom.sourceSelect.addEventListener('change', function () {
                applySource(dom.sourceSelect.value, dom);
            });
        }

        if (!dom.button) {
            return;
        }

        dom.button.addEventListener('click', function () {
            var ajaxUrl = settings.ajaxUrl;
            var nonce = settings.nonce;
            var i18n = settings.i18n || {};

            if (!ajaxUrl || !nonce) {
                handleError(i18n.error || '', dom);
                return;
            }

            setButtonState(true, dom);
            setStatus(i18n.running || '', 'info', dom);

            var body = new URLSearchParams();
            body.append('action', 'sitepulse_run_speed_scan');
            body.append('nonce', nonce);

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json()
                        .catch(function () {
                            return {};
                        })
                        .then(function (payload) {
                            return {
                                ok: response.ok,
                                payload: payload
                            };
                        });
                })
                .then(function (result) {
                    if (!result) {
                        handleError(null, dom);
                        return;
                    }

                    var payload = result.payload || {};

                    if (!payload.success) {
                        var errorData = payload.data || {};
                        var message = errorData.message || (settings.i18n && settings.i18n.error) || '';
                        var type = errorData.status === 'throttled' ? 'warning' : 'error';

                        if (errorData.status === 'throttled' && errorData.next_available) {
                            var nextDate = new Date(errorData.next_available * 1000);
                            var human = nextDate.toLocaleTimeString();
                            if (settings.i18n && settings.i18n.rateLimitIntro) {
                                message += ' ' + settings.i18n.rateLimitIntro + ' ' + human;
                            }
                        }

                        if (Array.isArray(errorData.recommendations)) {
                            updateRecommendations(errorData.recommendations, dom);
                            settings.recommendations = errorData.recommendations;
                        }

                        if (errorData.aggregates) {
                            settings.aggregates = errorData.aggregates;
                        }

                        if (Array.isArray(errorData.history)) {
                            settings.history = errorData.history;
                        }

                        if (errorData.automation) {
                            automation = errorData.automation;
                            settings.automation = automation;

                            if (automation.manualThresholds) {
                                updateManualThresholds(automation.manualThresholds);
                            }
                        }

                        if (errorData.manualProfile) {
                            updateManualProfile(errorData.manualProfile);
                            settings.manualProfile = manualProfile;
                        }

                        if (errorData.profiles) {
                            updateProfileCatalog(errorData.profiles);
                            settings.profiles = profileCatalog;
                        }

                        if (currentSourceKey === 'manual') {
                            applySource('manual', dom);
                        }

                        setStatus(message, type, dom);
                        setButtonState(false, dom);
                        return;
                    }

                    var data = payload.data || {};

                    settings.history = Array.isArray(data.history) ? data.history : [];
                    settings.recommendations = Array.isArray(data.recommendations) ? data.recommendations : [];
                    settings.lastRun = data.last_run || settings.lastRun;
                    settings.rateLimit = data.rate_limit || settings.rateLimit;
                    settings.aggregates = data.aggregates || settings.aggregates;

                    if (data.automation) {
                        automation = data.automation;
                        settings.automation = automation;

                        if (automation.manualThresholds) {
                            updateManualThresholds(automation.manualThresholds);
                        }
                    }

                    if (data.manualProfile) {
                        updateManualProfile(data.manualProfile);
                        settings.manualProfile = manualProfile;
                    }

                    if (data.profiles) {
                        updateProfileCatalog(data.profiles);
                        settings.profiles = profileCatalog;
                    }

                    if (currentSourceKey === 'manual') {
                        // Ensure manual view reflects refreshed thresholds and labels.
                        applySource('manual', dom);
                    } else {
                        applySource(currentSourceKey, dom);
                    }

                    settings.manualProfile = manualProfile;
                    settings.profiles = profileCatalog;

                    setStatus(data.message || '', 'success', dom);
                    setButtonState(false, dom);
                })
                .catch(function () {
                    handleError(null, dom);
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
