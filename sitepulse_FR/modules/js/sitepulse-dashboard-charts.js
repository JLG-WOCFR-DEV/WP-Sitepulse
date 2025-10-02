
(function (window, document) {
    'use strict';

    function mergeOptions(base, overrides) {
        var result = Array.isArray(base) ? base.slice() : Object.assign({}, base || {});

        if (!overrides || typeof overrides !== 'object') {
            return result;
        }

        Object.keys(overrides).forEach(function (key) {
            var value = overrides[key];

            if (value && typeof value === 'object' && !Array.isArray(value)) {
                result[key] = mergeOptions(base ? base[key] : undefined, value);
            } else {
                result[key] = value;
            }
        });

        return result;
    }

    function normalizeDatasets(datasets, chartType) {
        if (!Array.isArray(datasets)) {
            return [];
        }

        return datasets.map(function (dataset) {
            var source = dataset && typeof dataset === 'object' ? dataset : {};
            var normalized = {
                data: Array.isArray(source.data) ? source.data.slice() : [],
            };

            if (Array.isArray(source.backgroundColor)) {
                normalized.backgroundColor = source.backgroundColor.slice();
            } else if (typeof source.backgroundColor === 'string') {
                normalized.backgroundColor = source.backgroundColor;
            }

            if (typeof source.borderWidth === 'number') {
                normalized.borderWidth = source.borderWidth;
            } else if (chartType === 'doughnut' || chartType === 'pie') {
                normalized.borderWidth = 0;
            }

            if (typeof source.borderRadius !== 'undefined') {
                normalized.borderRadius = source.borderRadius;
            }

            if (typeof source.hoverOffset === 'number') {
                normalized.hoverOffset = source.hoverOffset;
            } else if (chartType === 'doughnut' || chartType === 'pie') {
                normalized.hoverOffset = 6;
            }

            var passThroughKeys = [
                'label',
                'fill',
                'tension',
                'pointRadius',
                'pointHoverRadius',
                'pointBorderWidth',
                'pointBackgroundColor',
                'pointBorderColor',
                'borderColor',
                'type',
            ];

            passThroughKeys.forEach(function (key) {
                var value = source[key];

                if (Array.isArray(value)) {
                    normalized[key] = value.slice();
                } else if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
                    normalized[key] = value;
                }
            });

            if (Array.isArray(source.borderDash)) {
                normalized.borderDash = source.borderDash.slice();
            }

            return normalized;
        });
    }

    function showFallback(canvas, message) {
        if (!canvas) {
            return;
        }

        var container = canvas.parentElement;
        if (!container) {
            return;
        }

        var placeholder = container.querySelector('.sitepulse-chart-empty');

        if (!placeholder) {
            placeholder = document.createElement('p');
            placeholder.className = 'sitepulse-chart-empty';
            container.appendChild(placeholder);
        }

        placeholder.textContent = message || '';
        placeholder.hidden = false;
        placeholder.classList.remove('is-hidden');
        canvas.classList.add('is-hidden');
    }

    function hideFallback(canvas) {
        if (!canvas) {
            return;
        }

        canvas.classList.remove('is-hidden');

        var container = canvas.parentElement;
        if (!container) {
            return;
        }

        var placeholder = container.querySelector('.sitepulse-chart-empty');
        if (placeholder) {
            placeholder.hidden = true;
            placeholder.classList.add('is-hidden');
        }
    }

    function formatValue(value, unit, fractionDigits) {
        if (typeof value !== 'number' || !isFinite(value)) {
            value = 0;
        }

        var options = {};
        if (typeof fractionDigits === 'number') {
            options.minimumFractionDigits = fractionDigits;
            options.maximumFractionDigits = fractionDigits;
        } else {
            options.maximumFractionDigits = 2;
        }

        var formatted = value.toLocaleString(undefined, options);
        if (unit) {
            if (unit === '%') {
                return formatted + unit;
            }
            return formatted + ' ' + unit;
        }

        return formatted;
    }

    function createChart(canvasId, chartConfig, overrides, strings) {
        var canvas = typeof canvasId === 'string' ? document.getElementById(canvasId) : canvasId;
        if (!canvas) {
            return null;
        }

        hideFallback(canvas);

        if (!chartConfig || chartConfig.empty || !Array.isArray(chartConfig.datasets) || chartConfig.datasets.length === 0) {
            showFallback(canvas, strings && strings.noData ? strings.noData : 'No data');
            return null;
        }

        var chartType = chartConfig.type || 'doughnut';

        var data = {
            labels: Array.isArray(chartConfig.labels) ? chartConfig.labels : [],
            datasets: normalizeDatasets(chartConfig.datasets, chartType),
        };

        var baseOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                },
                tooltip: {},
            },
        };

        var options = mergeOptions(baseOptions, chartConfig.options || {});
        options = mergeOptions(options, overrides || {});

        return new Chart(canvas, {
            type: chartType,
            data: data,
            options: options,
        });
    }

    function formatSummaryValue(value, unit) {
        var formatted;

        if (typeof value === 'number' && isFinite(value)) {
            var precision = Math.floor(value) === value ? 0 : 2;
            formatted = value.toLocaleString(undefined, {
                minimumFractionDigits: precision,
                maximumFractionDigits: precision,
            });
        } else if (value !== null && typeof value !== 'undefined') {
            formatted = String(value);
        }

        if (!formatted) {
            return '';
        }

        if (unit) {
            if (unit === '%') {
                return formatted + unit;
            }
            return formatted + ' ' + unit;
        }

        return formatted;
    }

    function updateSummary(canvasId, chartData) {
        var summary = document.getElementById(canvasId + '-summary');

        if (!summary) {
            return;
        }

        while (summary.firstChild) {
            summary.removeChild(summary.firstChild);
        }

        if (!chartData || chartData.empty || !Array.isArray(chartData.labels) || !Array.isArray(chartData.datasets) || chartData.datasets.length === 0) {
            summary.setAttribute('hidden', 'hidden');
            return;
        }

        var labels = chartData.labels;
        var datasets = chartData.datasets;
        var unit = typeof chartData.unit === 'string' ? chartData.unit : '';
        var hasItems = false;

        for (var i = 0; i < labels.length; i += 1) {
            var label = labels[i];
            var values = [];

            for (var d = 0; d < datasets.length; d += 1) {
                var dataset = datasets[d];
                if (!dataset || !Array.isArray(dataset.data)) {
                    continue;
                }

                if (!Object.prototype.hasOwnProperty.call(dataset.data, i)) {
                    continue;
                }

                var summaryValue = formatSummaryValue(dataset.data[i], unit);
                if (summaryValue) {
                    values.push(summaryValue);
                }
            }

            if (!values.length) {
                continue;
            }

            hasItems = true;
            var item = document.createElement('li');
            item.textContent = String(label) + ': ' + values.join(', ');
            summary.appendChild(item);
        }

        if (hasItems) {
            summary.removeAttribute('hidden');
        } else {
            summary.setAttribute('hidden', 'hidden');
        }
    }

    var chartInstances = {};
    var strings = {};
    var chartIds = {};
    var statusLabels = {};
    var actionBar = null;
    var refreshButton = null;
    var periodButtons = [];
    var moduleCheckboxes = [];
    var loadingText = null;
    var state = {
        period: 30,
        modules: [],
    };
    var restUrl = '';
    var restNonce = '';
    var isLoading = false;
    var pendingFetch = false;

    function destroyChart(canvasId) {
        if (chartInstances[canvasId]) {
            chartInstances[canvasId].destroy();
            chartInstances[canvasId] = null;
        }
    }

    function resolveOverrides(chartKey, chartConfig) {
        if (chartKey === 'speed') {
            if (chartConfig && chartConfig.type === 'line') {
                return {
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    var datasetLabel = '';

                                    if (context.dataset && typeof context.dataset.label === 'string') {
                                        datasetLabel = context.dataset.label;
                                    } else if (strings.speedTrendLabel) {
                                        datasetLabel = strings.speedTrendLabel;
                                    }

                                    var value = context.parsed && typeof context.parsed.y === 'number'
                                        ? context.parsed.y
                                        : 0;
                                    var unit = chartConfig.unit || 'ms';
                                    var formatted = formatValue(value, unit, 0);

                                    if (datasetLabel) {
                                        return datasetLabel + ': ' + formatted;
                                    }

                                    return formatted;
                                },
                            },
                        },
                    },
                    interaction: {
                        intersect: false,
                        mode: 'nearest',
                    },
                    scales: {
                        x: {
                            ticks: {
                                autoSkip: true,
                                maxRotation: 0,
                            },
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: !!strings.speedAxisLabel,
                                text: strings.speedAxisLabel || '',
                            },
                        },
                    },
                };
            }

            return {
                cutout: '65%',
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var label = context.label || strings.speedTooltipLabel || '';
                                var raw = typeof context.raw === 'number' ? context.raw : 0;
                                var unit = chartConfig.unit || 'ms';
                                return label + ': ' + formatValue(raw, unit, 0);
                            },
                        },
                    },
                },
            };
        }

        if (chartKey === 'uptime') {
            return {
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var isUp = typeof context.raw === 'number' && context.raw >= 100;
                                var label = isUp ? strings.uptimeTooltipUp : strings.uptimeTooltipDown;
                                return (label || '') + ': ' + formatValue(context.raw, chartConfig.unit || '%', 0);
                            },
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function (value) {
                                return value + '%';
                            },
                        },
                        title: {
                            display: !!strings.uptimeAxisLabel,
                            text: strings.uptimeAxisLabel || '',
                        },
                    },
                    x: {
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 8,
                        },
                    },
                },
            };
        }

        if (chartKey === 'database') {
            return {
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var label = context.label || strings.revisionsTooltip || '';
                                var raw = typeof context.raw === 'number' ? context.raw : 0;
                                return label + ': ' + formatValue(raw, null, 0);
                            },
                        },
                    },
                },
            };
        }

        if (chartKey === 'logs') {
            return {
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var label = context.label || strings.logEventsLabel || '';
                                var raw = typeof context.raw === 'number' ? context.raw : 0;
                                return label + ': ' + formatValue(raw, null, 0);
                            },
                        },
                    },
                },
            };
        }

        return {};
    }

    function renderChart(chartKey, chartData) {
        var canvasId = chartIds[chartKey];
        if (!canvasId) {
            return;
        }

        var canvas = document.getElementById(canvasId);
        if (!canvas) {
            return;
        }

        if (!chartData || chartData.empty || !Array.isArray(chartData.datasets) || chartData.datasets.length === 0) {
            destroyChart(canvasId);
            showFallback(canvas, strings.noData || 'No data');
            updateSummary(canvasId, null);
            return;
        }

        hideFallback(canvas);
        destroyChart(canvasId);
        chartInstances[canvasId] = createChart(canvasId, chartData, resolveOverrides(chartKey, chartData), strings);
        updateSummary(canvasId, chartData);
    }

    function updateCharts(charts) {
        Object.keys(chartIds).forEach(function (chartKey) {
            if (charts && Object.prototype.hasOwnProperty.call(charts, chartKey)) {
                renderChart(chartKey, charts[chartKey]);
            } else {
                renderChart(chartKey, null);
            }
        });
    }

    function applyStatus(card, status) {
        if (!card) {
            return;
        }

        var badge = card.querySelector('.js-sitepulse-status-badge');
        if (badge) {
            badge.classList.remove('status-ok', 'status-warn', 'status-bad');
            if (status) {
                badge.classList.add(status);
            }
        }

        var meta = statusLabels[status] || statusLabels['status-warn'] || {};
        var icon = card.querySelector('.js-sitepulse-status-icon');
        if (icon) {
            icon.textContent = meta.icon || '';
        }

        var text = card.querySelector('.js-sitepulse-status-text');
        if (text) {
            text.textContent = meta.label || '';
        }

        var sr = card.querySelector('.js-sitepulse-status-sr');
        if (sr) {
            sr.textContent = meta.sr || '';
        }
    }

    function updateSpeedDescription(thresholds) {
        var description = document.getElementById('sitepulse-speed-description');
        if (!description || !thresholds || typeof thresholds.warning === 'undefined' || typeof thresholds.critical === 'undefined') {
            return;
        }

        if (strings.speedDescriptionTemplate) {
            var warningText = Number(thresholds.warning).toLocaleString();
            var criticalText = Number(thresholds.critical).toLocaleString();
            var template = strings.speedDescriptionTemplate;
            template = template.replace('%1$d', warningText);
            template = template.replace('%2$d', criticalText);
            description.textContent = template;
        }
    }

    function updateCards(cards) {
        if (!cards || typeof cards !== 'object') {
            return;
        }

        Object.keys(cards).forEach(function (key) {
            var data = cards[key];
            if (!data) {
                return;
            }

            var card = document.querySelector('.sitepulse-card[data-card="' + key + '"]');
            if (!card) {
                return;
            }

            applyStatus(card, data.status);

            if (key === 'speed') {
                var speedValue = card.querySelector('.js-sitepulse-metric-value');
                if (speedValue && typeof data.display === 'string') {
                    speedValue.textContent = data.display;
                }
                if (data.thresholds) {
                    updateSpeedDescription(data.thresholds);
                }
            } else if (key === 'uptime') {
                var uptimeValue = card.querySelector('.js-sitepulse-metric-value');
                var uptimeNumber = typeof data.percentage === 'number' ? data.percentage : parseFloat(data.percentage);
                if (uptimeValue && !isNaN(uptimeNumber)) {
                    uptimeValue.textContent = uptimeNumber.toLocaleString(undefined, {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 2,
                    });
                }
            } else if (key === 'database') {
                var databaseValue = card.querySelector('.js-sitepulse-metric-value');
                var revisions = typeof data.revisions === 'number' ? data.revisions : parseFloat(data.revisions);
                if (databaseValue && !isNaN(revisions)) {
                    databaseValue.textContent = revisions.toLocaleString();
                }
            } else if (key === 'logs') {
                var logSummary = card.querySelector('.js-sitepulse-log-summary');
                if (logSummary && typeof data.summary === 'string') {
                    logSummary.textContent = data.summary;
                }

                var counts = data.counts || {};
                var countElements = card.querySelectorAll('.js-sitepulse-log-count');
                countElements.forEach(function (element) {
                    var type = element.getAttribute('data-log-type');
                    if (!type || typeof counts[type] === 'undefined') {
                        return;
                    }

                    var numeric = typeof counts[type] === 'number' ? counts[type] : parseFloat(counts[type]);
                    if (!isNaN(numeric)) {
                        element.textContent = numeric.toLocaleString();
                    }
                });
            }
        });
    }

    function updateCardVisibility(modules) {
        var activeModules = Array.isArray(modules) && modules.length ? modules : null;
        var cards = document.querySelectorAll('.sitepulse-card[data-module]');

        cards.forEach(function (card) {
            var moduleKey = card.getAttribute('data-module');
            if (!activeModules || activeModules.indexOf(moduleKey) !== -1) {
                card.classList.remove('is-filtered-out');
            } else {
                card.classList.add('is-filtered-out');
            }
        });
    }

    function setActivePeriodButton(period) {
        periodButtons.forEach(function (button) {
            if (parseInt(button.getAttribute('data-period'), 10) === period) {
                button.classList.add('is-active');
            } else {
                button.classList.remove('is-active');
            }
        });
    }

    function setLoadingState(loading) {
        if (actionBar) {
            if (loading) {
                actionBar.classList.add('is-loading');
            } else {
                actionBar.classList.remove('is-loading');
            }
        }

        if (refreshButton) {
            refreshButton.disabled = loading;
        }

        periodButtons.forEach(function (button) {
            button.disabled = loading;
        });

        moduleCheckboxes.forEach(function (checkbox) {
            checkbox.disabled = loading;
        });

        if (loadingText) {
            loadingText.hidden = !loading;
        }
    }

    function requestDashboardData() {
        if (!restUrl) {
            return;
        }

        if (isLoading) {
            pendingFetch = true;
            return;
        }

        isLoading = true;
        setLoadingState(true);

        var params = new URLSearchParams();
        params.append('period', state.period);
        (state.modules || []).forEach(function (moduleKey) {
            params.append('modules[]', moduleKey);
        });

        var requestUrl = restUrl + (restUrl.indexOf('?') === -1 ? '?' : '&') + params.toString();
        var headers = {};

        if (restNonce) {
            headers['X-WP-Nonce'] = restNonce;
        }

        fetch(requestUrl, {
            credentials: 'same-origin',
            headers: headers,
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(function (data) {
                if (data && typeof data === 'object') {
                    if (data.statusLabels && typeof data.statusLabels === 'object') {
                        statusLabels = data.statusLabels;
                    }

                    if (data.period) {
                        state.period = parseInt(data.period, 10) || state.period;
                        setActivePeriodButton(state.period);
                    }

                    if (Array.isArray(data.modules) && data.modules.length) {
                        state.modules = data.modules.slice();
                        moduleCheckboxes.forEach(function (checkbox) {
                            checkbox.checked = state.modules.indexOf(checkbox.value) !== -1;
                        });
                    }

                    updateCharts(data.charts || {});
                    updateCards(data.cards || {});
                    updateCardVisibility(state.modules);
                }
            })
            .catch(function (error) {
                if (window.console) {
                    console.error(error);
                }
                if (strings.refreshError) {
                    window.alert(strings.refreshError);
                }
            })
            .finally(function () {
                isLoading = false;
                setLoadingState(false);
                if (pendingFetch) {
                    pendingFetch = false;
                    requestDashboardData();
                }
            });
    }

    function getSelectedModules() {
        var modules = [];
        moduleCheckboxes.forEach(function (checkbox) {
            if (checkbox.checked) {
                modules.push(checkbox.value);
            }
        });
        return modules;
    }

    function init() {
        if (typeof Chart === 'undefined') {
            return;
        }

        var config = window.SitePulseDashboardData || {};
        strings = config.strings || {};
        chartIds = config.chartIds || {
            speed: 'sitepulse-speed-chart',
            uptime: 'sitepulse-uptime-chart',
            database: 'sitepulse-database-chart',
            logs: 'sitepulse-log-chart',
        };
        statusLabels = config.statusLabels || {};
        restUrl = typeof config.restUrl === 'string' ? config.restUrl : '';
        restNonce = typeof config.restNonce === 'string' ? config.restNonce : '';

        if (config.initialState) {
            if (config.initialState.period) {
                state.period = parseInt(config.initialState.period, 10) || state.period;
            }
            if (Array.isArray(config.initialState.modules)) {
                state.modules = config.initialState.modules.slice();
            }
        }

        actionBar = document.querySelector('.sitepulse-dashboard-actions');
        refreshButton = actionBar ? actionBar.querySelector('.sitepulse-refresh-button') : null;
        loadingText = actionBar ? actionBar.querySelector('.sitepulse-loading-text') : null;
        periodButtons = actionBar ? Array.prototype.slice.call(actionBar.querySelectorAll('.sitepulse-period-button')) : [];
        moduleCheckboxes = actionBar ? Array.prototype.slice.call(actionBar.querySelectorAll('.sitepulse-module-filter input[type="checkbox"]')) : [];

        if (refreshButton) {
            refreshButton.addEventListener('click', function () {
                requestDashboardData();
            });
        }

        periodButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var value = parseInt(button.getAttribute('data-period'), 10);
                if (!value || value === state.period) {
                    return;
                }
                state.period = value;
                setActivePeriodButton(state.period);
                requestDashboardData();
            });
        });

        moduleCheckboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                var selected = getSelectedModules();
                if (!selected.length) {
                    checkbox.checked = true;
                    if (strings.moduleFilterMinimum) {
                        window.alert(strings.moduleFilterMinimum);
                    }
                    return;
                }

                state.modules = selected;
                updateCardVisibility(state.modules);
                requestDashboardData();
            });
        });

        setActivePeriodButton(state.period);
        updateCardVisibility(state.modules);

        updateCharts(config.charts || {});
        updateCards(config.cards || {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
