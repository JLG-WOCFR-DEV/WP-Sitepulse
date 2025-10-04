(function (window, document) {
    'use strict';

    var settings = window.SitePulseSpeedAnalyzer || {};
    var chartInstance = null;

    function sanitizeHistory(history) {
        if (!Array.isArray(history)) {
            return [];
        }

        return history
            .map(function (entry) {
                if (!entry || typeof entry !== 'object') {
                    return null;
                }

                var timestamp = parseInt(entry.timestamp, 10);
                var value = typeof entry.server_processing_ms === 'number'
                    ? entry.server_processing_ms
                    : parseFloat(entry.server_processing_ms);

                if (!isFinite(timestamp) || timestamp <= 0 || !isFinite(value) || value < 0) {
                    return null;
                }

                return {
                    timestamp: timestamp,
                    server_processing_ms: value
                };
            })
            .filter(function (entry) {
                return !!entry;
            })
            .sort(function (a, b) {
                return a.timestamp - b.timestamp;
            });
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

    function renderHistory(history, dom) {
        var sanitized = sanitizeHistory(history);
        var canvas = dom.canvas;
        var tableBody = dom.tableBody;
        var i18n = settings.i18n || {};

        if (tableBody) {
            tableBody.innerHTML = '';

            if (!sanitized.length) {
                var emptyRow = document.createElement('tr');
                var emptyCell = document.createElement('td');
                emptyCell.colSpan = 2;
                emptyCell.textContent = i18n.noHistory || '';
                emptyRow.appendChild(emptyCell);
                tableBody.appendChild(emptyRow);
            } else {
                sanitized.forEach(function (entry) {
                    var row = document.createElement('tr');
                    var dateCell = document.createElement('td');
                    var valueCell = document.createElement('td');

                    dateCell.textContent = formatTimestamp(entry.timestamp);
                    valueCell.textContent = entry.server_processing_ms.toFixed(2);

                    row.appendChild(dateCell);
                    row.appendChild(valueCell);
                    tableBody.appendChild(row);
                });
            }
        }

        if (!canvas || typeof window.Chart === 'undefined') {
            return;
        }

        var labels = sanitized.map(function (entry) {
            return formatTimestamp(entry.timestamp);
        });
        var values = sanitized.map(function (entry) {
            return entry.server_processing_ms;
        });
        var datasets = [
            {
                label: i18n.chartLabel || '',
                data: values,
                borderColor: '#0073aa',
                backgroundColor: 'rgba(0, 115, 170, 0.15)',
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#0073aa',
                tension: 0.25,
                fill: true,
                order: 2
            }
        ].concat(buildThresholdDatasets(values.length));

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
                            display: false
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

    function init() {
        var dom = {
            button: document.getElementById('sitepulse-speed-rescan'),
            status: document.getElementById('sitepulse-speed-scan-status'),
            canvas: document.getElementById('sitepulse-speed-history-chart'),
            tableBody: document.querySelector('#sitepulse-speed-history-table tbody'),
            recommendations: document.querySelector('#sitepulse-speed-recommendations ul'),
            summaryGrid: document.getElementById('sitepulse-speed-summary-grid'),
            summaryNote: document.getElementById('sitepulse-speed-summary-note')
        };

        renderHistory(settings.history || [], dom);
        updateRecommendations(settings.recommendations || [], dom);
        renderSummary(settings.aggregates || {}, dom);

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
                        }

                        if (errorData.aggregates) {
                            settings.aggregates = errorData.aggregates;
                        }

                        if (Array.isArray(errorData.history)) {
                            renderHistory(errorData.history, dom);
                        }

                        renderSummary(settings.aggregates || {}, dom);
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

                    renderHistory(settings.history, dom);
                    updateRecommendations(settings.recommendations, dom);
                    renderSummary(settings.aggregates || {}, dom);

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
