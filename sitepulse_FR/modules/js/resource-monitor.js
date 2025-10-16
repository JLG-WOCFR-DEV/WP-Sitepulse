(function(window, document) {
    'use strict';

    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    var settings = window.SitePulseResourceMonitor;

    if (!settings) {
        return;
    }

    var refreshFeedback = typeof settings.refreshFeedback === 'string' ? settings.refreshFeedback : '';
    var refreshStatusId = typeof settings.refreshStatusId === 'string' ? settings.refreshStatusId : '';

    if (refreshFeedback) {
        if (refreshStatusId) {
            var statusRegion = document.getElementById(refreshStatusId);

            if (statusRegion) {
                statusRegion.textContent = refreshFeedback;
            }
        }

        if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
            window.wp.a11y.speak(refreshFeedback, 'polite');
        }
    }

    var i18n = settings.i18n || {};
    var restConfig = settings.rest || {};
    var restHeaders = {};

    if (restConfig.nonce) {
        restHeaders['X-WP-Nonce'] = restConfig.nonce;
    }

    var locale = typeof settings.locale === 'string' && settings.locale ? settings.locale : undefined;
    var percentAxisLabel = typeof i18n.percentAxisLabel === 'string' ? i18n.percentAxisLabel : '%';
    var unavailableLabel = typeof i18n.unavailable === 'string' ? i18n.unavailable : 'N/A';
    var loadLabel = typeof i18n.loadLabel === 'string' ? i18n.loadLabel : 'CPU';
    var memoryLabel = typeof i18n.memoryLabel === 'string' ? i18n.memoryLabel : 'Memory';
    var diskLabel = typeof i18n.diskLabel === 'string' ? i18n.diskLabel : 'Disk';
    var trendTexts = {
        up: typeof i18n.trendUp === 'string' ? i18n.trendUp : 'Up',
        down: typeof i18n.trendDown === 'string' ? i18n.trendDown : 'Down',
        flat: typeof i18n.trendFlat === 'string' ? i18n.trendFlat : 'Flat',
    };

    var historyContainer = document.getElementById('sitepulse-resource-history');
    var historySummaryElement = document.getElementById('sitepulse-resource-history-summary');
    var emptyMessage = historyContainer ? historyContainer.querySelector('[data-empty]') : null;
    var canvas = document.getElementById('sitepulse-resource-history-chart');
    var granularitySelect = document.getElementById('sitepulse-resource-history-granularity');
    var aggregatesContainer = document.querySelector('[data-aggregates]');
    var aggregatesSummary = document.getElementById('sitepulse-resource-aggregates-summary');

    var chartInstance = null;
    var currentChartData = null;
    var currentGranularity = settings.granularity && typeof settings.granularity.default === 'string'
        ? settings.granularity.default
        : 'raw';
    var pendingGranularity = null;

    var initialHistory = settings.initialHistory || {
        entries: Array.isArray(settings.history) ? settings.history : [],
        summaryText: '',
    };

    function formatDate(timestamp) {
        var date = new Date(timestamp * 1000);

        if (!Number.isFinite(date.getTime())) {
            return unavailableLabel;
        }

        try {
            return date.toLocaleString(locale);
        } catch (error) {
            return date.toLocaleString();
        }
    }

    function formatNumber(value, precision, suffix) {
        if (typeof value !== 'number' || !isFinite(value)) {
            return unavailableLabel;
        }

        var formatted = value.toFixed(precision);

        if (suffix) {
            formatted += suffix;
        }

        return formatted;
    }

    var metricPrecision = {
        load_1: { decimals: 2, suffix: '' },
        load_5: { decimals: 2, suffix: '' },
        load_15: { decimals: 2, suffix: '' },
        memory_percent: { decimals: 1, suffix: '%' },
        disk_used: { decimals: 1, suffix: '%' },
    };

    function formatMetricValue(metric, key) {
        if (!metric) {
            return unavailableLabel;
        }

        var config = metricPrecision[key] || { decimals: 2, suffix: '' };

        if (typeof metric !== 'number' || !isFinite(metric)) {
            return unavailableLabel;
        }

        return formatNumber(metric, config.decimals, config.suffix);
    }

    function formatTrend(trend, key) {
        if (!trend || typeof trend !== 'object') {
            return unavailableLabel;
        }

        var config = metricPrecision[key] || { decimals: 2, suffix: '' };
        var slope = typeof trend.slope_per_hour === 'number' && isFinite(trend.slope_per_hour)
            ? trend.slope_per_hour
            : null;
        var direction = typeof trend.direction === 'string' ? trend.direction : 'flat';
        var symbol = '→';

        if (direction === 'up') {
            symbol = '↑';
        } else if (direction === 'down') {
            symbol = '↓';
        }

        if (slope === null) {
            return trendTexts[direction] || unavailableLabel;
        }

        var suffix = config.suffix ? config.suffix + '/h' : '/h';
        var value = formatNumber(slope, config.decimals, suffix);

        if (value === unavailableLabel) {
            return unavailableLabel;
        }

        return symbol + ' ' + value;
    }

    function setEmptyState(entries) {
        if (!emptyMessage) {
            return;
        }

        emptyMessage.hidden = Array.isArray(entries) && entries.length > 0;
    }

    function buildChartData(entries) {
        if (!Array.isArray(entries) || !entries.length) {
            return null;
        }

        var labels = [];
        var loadDataset = [];
        var memoryDataset = [];
        var diskDataset = [];
        var pointRadius = [];
        var pointHoverRadius = [];
        var pointStyles = [];
        var pointBorderWidth = [];
        var cronFlags = [];

        for (var index = 0; index < entries.length; index++) {
            var entry = entries[index] || {};
            var timestamp = typeof entry.timestamp === 'number' ? entry.timestamp : null;
            labels.push(timestamp ? formatDate(timestamp) : unavailableLabel);

            var isCron = !!entry.isCron;
            cronFlags.push(isCron);
            pointRadius.push(isCron ? 5 : 3);
            pointHoverRadius.push(isCron ? 7 : 5);
            pointStyles.push(isCron ? 'rectRounded' : 'circle');
            pointBorderWidth.push(isCron ? 2 : 1);

            if (entry.load && Array.isArray(entry.load) && typeof entry.load[0] === 'number') {
                loadDataset.push(entry.load[0]);
            } else {
                loadDataset.push(null);
            }

            var memoryPercent = entry.memory && typeof entry.memory.percentUsage === 'number'
                ? entry.memory.percentUsage
                : null;
            var diskPercent = null;

            if (entry.disk) {
                if (typeof entry.disk.percentUsed === 'number') {
                    diskPercent = entry.disk.percentUsed;
                } else if (typeof entry.disk.percentFree === 'number') {
                    diskPercent = Math.max(0, Math.min(100, 100 - entry.disk.percentFree));
                }
            }

            memoryDataset.push(memoryPercent !== null ? memoryPercent : null);
            diskDataset.push(diskPercent !== null ? diskPercent : null);
        }

        return {
            labels: labels,
            load: loadDataset,
            memory: memoryDataset,
            disk: diskDataset,
            pointRadius: pointRadius,
            pointHoverRadius: pointHoverRadius,
            pointStyles: pointStyles,
            pointBorderWidth: pointBorderWidth,
            cronFlags: cronFlags,
        };
    }

    function renderChart(data) {
        if (!canvas || typeof window.Chart === 'undefined') {
            return;
        }

        if (!data) {
            if (chartInstance) {
                chartInstance.destroy();
                chartInstance = null;
                currentChartData = null;
            }

            return;
        }

        currentChartData = data;

        if (!chartInstance) {
            var context = canvas.getContext('2d');

            if (!context) {
                return;
            }

            chartInstance = new window.Chart(context, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: loadLabel,
                            data: data.load,
                            borderColor: '#1d4ed8',
                            backgroundColor: 'rgba(29, 78, 216, 0.1)',
                            tension: 0.3,
                            spanGaps: true,
                            yAxisID: 'y',
                            pointRadius: data.pointRadius,
                            pointHoverRadius: data.pointHoverRadius,
                            pointStyle: data.pointStyles,
                            pointBorderWidth: data.pointBorderWidth,
                            pointBorderColor: '#1d4ed8',
                            pointBackgroundColor: function(context) {
                                var idx = typeof context.dataIndex === 'number' ? context.dataIndex : 0;
                                return currentChartData && currentChartData.cronFlags[idx] ? '#1d4ed8' : '#ffffff';
                            },
                        },
                        {
                            label: memoryLabel,
                            data: data.memory,
                            borderColor: '#16a34a',
                            backgroundColor: 'rgba(22, 163, 74, 0.1)',
                            tension: 0.3,
                            spanGaps: true,
                            yAxisID: 'yPercent',
                            pointRadius: data.pointRadius,
                            pointHoverRadius: data.pointHoverRadius,
                            pointStyle: data.pointStyles,
                            pointBorderWidth: data.pointBorderWidth,
                            pointBorderColor: '#16a34a',
                            pointBackgroundColor: function(context) {
                                var idx = typeof context.dataIndex === 'number' ? context.dataIndex : 0;
                                return currentChartData && currentChartData.cronFlags[idx] ? '#16a34a' : '#ffffff';
                            },
                        },
                        {
                            label: diskLabel,
                            data: data.disk,
                            borderColor: '#f97316',
                            backgroundColor: 'rgba(249, 115, 22, 0.1)',
                            tension: 0.3,
                            spanGaps: true,
                            yAxisID: 'yPercent',
                            pointRadius: data.pointRadius,
                            pointHoverRadius: data.pointHoverRadius,
                            pointStyle: data.pointStyles,
                            pointBorderWidth: data.pointBorderWidth,
                            pointBorderColor: '#f97316',
                            pointBackgroundColor: function(context) {
                                var idx = typeof context.dataIndex === 'number' ? context.dataIndex : 0;
                                return currentChartData && currentChartData.cronFlags[idx] ? '#f97316' : '#ffffff';
                            },
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index',
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var label = context.dataset.label || '';
                                    var value = context.parsed.y;
                                    if (typeof value !== 'number') {
                                        return label;
                                    }
                                    return label + ': ' + value.toFixed(2);
                                },
                            },
                        },
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            position: 'left',
                            ticks: {
                                callback: function(value) {
                                    return typeof value === 'number' ? value.toFixed(2) : value;
                                },
                            },
                        },
                        yPercent: {
                            type: 'linear',
                            position: 'right',
                            suggestedMin: 0,
                            suggestedMax: 100,
                            title: {
                                display: true,
                                text: percentAxisLabel,
                            },
                        },
                    },
                },
            });
        } else {
            chartInstance.data.labels = data.labels;
            chartInstance.data.datasets[0].data = data.load;
            chartInstance.data.datasets[1].data = data.memory;
            chartInstance.data.datasets[2].data = data.disk;
            chartInstance.data.datasets.forEach(function(dataset) {
                dataset.pointRadius = data.pointRadius;
                dataset.pointHoverRadius = data.pointHoverRadius;
                dataset.pointStyle = data.pointStyles;
                dataset.pointBorderWidth = data.pointBorderWidth;
            });
            chartInstance.update();
        }
    }

    function renderHistory(historyData) {
        var entries = [];
        var summaryText = '';

        if (Array.isArray(historyData)) {
            entries = historyData;
        } else if (historyData && typeof historyData === 'object') {
            entries = Array.isArray(historyData.entries) ? historyData.entries : [];
            summaryText = typeof historyData.summaryText === 'string' ? historyData.summaryText : '';
        }

        setEmptyState(entries);
        renderChart(buildChartData(entries));

        if (historySummaryElement && summaryText) {
            historySummaryElement.textContent = summaryText;
        }
    }

    function updateAggregates(aggregatesData) {
        if (!aggregatesContainer) {
            return;
        }

        var metrics = aggregatesData && aggregatesData.metrics ? aggregatesData.metrics : {};
        var summaryText = aggregatesData && typeof aggregatesData.summaryText === 'string'
            ? aggregatesData.summaryText
            : '';

        if (aggregatesSummary && summaryText) {
            aggregatesSummary.textContent = summaryText;
        }

        var cards = aggregatesContainer.querySelectorAll('[data-metric]');

        for (var index = 0; index < cards.length; index++) {
            var card = cards[index];
            var metricKey = card.getAttribute('data-metric');
            var metric = metrics && metrics[metricKey] ? metrics[metricKey] : null;

            var averageEl = card.querySelector('[data-metric-average]');
            var maxEl = card.querySelector('[data-metric-max]');
            var percentileEl = card.querySelector('[data-metric-percentiles]');
            var trendEl = card.querySelector('[data-metric-trend]');

            if (averageEl) {
                averageEl.textContent = metric && metric.average !== null
                    ? formatMetricValue(metric.average, metricKey)
                    : unavailableLabel;
            }

            if (maxEl) {
                maxEl.textContent = metric && metric.max !== null
                    ? formatMetricValue(metric.max, metricKey)
                    : unavailableLabel;
            }

            if (percentileEl) {
                var p95 = metric && metric.percentiles && metric.percentiles.p95 !== null
                    ? metric.percentiles.p95
                    : null;
                percentileEl.textContent = p95 !== null
                    ? formatMetricValue(p95, metricKey)
                    : unavailableLabel;
            }

            if (trendEl) {
                trendEl.textContent = formatTrend(metric && metric.trend, metricKey);
            }

            card.classList.remove('is-up', 'is-down', 'is-flat');
            var direction = metric && metric.trend && typeof metric.trend.direction === 'string'
                ? metric.trend.direction
                : 'flat';
            card.classList.add('is-' + direction);
        }
    }

    function fetchJson(url) {
        return fetch(url, { headers: restHeaders }).then(function(response) {
            if (!response.ok) {
                throw new Error('Request failed with status ' + response.status);
            }

            return response.json();
        });
    }

    function buildHistoryUrl(granularity) {
        if (!restConfig.history) {
            return null;
        }

        var params = new URLSearchParams();

        if (settings.request && settings.request.perPage) {
            params.set('per_page', settings.request.perPage);
        }

        if (settings.request && settings.request.since) {
            params.set('since', settings.request.since);
        }

        params.set('granularity', granularity || 'raw');
        params.set('include_snapshot', 'false');

        return restConfig.history + '?' + params.toString();
    }

    function buildAggregatesUrl(granularity) {
        if (!restConfig.aggregates) {
            return null;
        }

        var params = new URLSearchParams();

        if (settings.request && settings.request.since) {
            params.set('since', settings.request.since);
        }

        params.set('granularity', granularity || 'raw');

        return restConfig.aggregates + '?' + params.toString();
    }

    function loadHistory(granularity) {
        var url = buildHistoryUrl(granularity);

        if (!url) {
            return;
        }

        pendingGranularity = granularity;

        fetchJson(url).then(function(response) {
            if (!response || !response.history) {
                return;
            }

            if (pendingGranularity !== granularity) {
                return;
            }

            renderHistory({
                entries: Array.isArray(response.history.entries) ? response.history.entries : [],
                summaryText: typeof response.history.summary_text === 'string' ? response.history.summary_text : '',
            });
        }).catch(function(error) {
            if (console && console.error) {
                console.error('SitePulse history request failed:', error);
            }
        });
    }

    function loadAggregates(granularity) {
        var url = buildAggregatesUrl(granularity);

        if (!url) {
            return;
        }

        fetchJson(url).then(function(response) {
            if (!response) {
                return;
            }

            updateAggregates({
                metrics: response.metrics || {},
                summaryText: typeof response.summary_text === 'string' ? response.summary_text : '',
            });
        }).catch(function(error) {
            if (console && console.error) {
                console.error('SitePulse aggregates request failed:', error);
            }
        });
    }

    renderHistory(initialHistory);
    updateAggregates(settings.aggregates || {});

    if (granularitySelect) {
        granularitySelect.value = currentGranularity;
        granularitySelect.addEventListener('change', function() {
            var selected = granularitySelect.value || 'raw';
            currentGranularity = selected;
            loadHistory(selected);
            loadAggregates(selected);
        });
    }

    // Ensure initial data matches the configured granularity when it differs from the default dataset.
    if (currentGranularity !== 'raw') {
        loadHistory(currentGranularity);
        loadAggregates(currentGranularity);
    }
})();
