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

    function normalizeDatasets(datasets) {
        if (!Array.isArray(datasets)) {
            return [];
        }

        return datasets.map(function (dataset) {
            var normalized = {
                data: Array.isArray(dataset.data) ? dataset.data : [],
                backgroundColor: Array.isArray(dataset.backgroundColor) ? dataset.backgroundColor : [],
            };

            if (typeof dataset.borderWidth === 'number') {
                normalized.borderWidth = dataset.borderWidth;
            } else {
                normalized.borderWidth = 0;
            }

            if (typeof dataset.borderRadius !== 'undefined') {
                normalized.borderRadius = dataset.borderRadius;
            }

            if (typeof dataset.hoverOffset === 'number') {
                normalized.hoverOffset = dataset.hoverOffset;
            } else {
                normalized.hoverOffset = 6;
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

        container.removeChild(canvas);
        var placeholder = document.createElement('p');
        placeholder.className = 'sitepulse-chart-empty';
        placeholder.textContent = message;
        container.appendChild(placeholder);
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
        var canvas = document.getElementById(canvasId);
        if (!canvas) {
            return null;
        }

        if (!chartConfig || chartConfig.empty || !Array.isArray(chartConfig.datasets) || chartConfig.datasets.length === 0) {
            showFallback(canvas, strings && strings.noData ? strings.noData : 'No data');
            return null;
        }

        var data = {
            labels: Array.isArray(chartConfig.labels) ? chartConfig.labels : [],
            datasets: normalizeDatasets(chartConfig.datasets),
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

        var options = mergeOptions(baseOptions, overrides || {});

        return new Chart(canvas, {
            type: chartConfig.type || 'doughnut',
            data: data,
            options: options,
        });
    }

    function init() {
        if (typeof Chart === 'undefined') {
            return;
        }

        var config = window.SitePulseDashboardData || {};
        var charts = config.charts || {};
        var strings = config.strings || {};

        if (charts.speed) {
            createChart(
                'sitepulse-speed-chart',
                charts.speed,
                {
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
                                    var unit = charts.speed.unit || 'ms';
                                    return label + ': ' + formatValue(raw, unit, 0);
                                },
                            },
                        },
                    },
                },
                strings
            );
        } else {
            showFallback(document.getElementById('sitepulse-speed-chart'), strings.noData || 'No data');
        }

        if (charts.uptime) {
            createChart(
                'sitepulse-uptime-chart',
                charts.uptime,
                {
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    var isUp = typeof context.raw === 'number' && context.raw >= 100;
                                    var label = isUp ? strings.uptimeTooltipUp : strings.uptimeTooltipDown;
                                    return (label || '') + ': ' + formatValue(context.raw, charts.uptime.unit || '%', 0);
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
                },
                strings
            );
        } else {
            showFallback(document.getElementById('sitepulse-uptime-chart'), strings.noData || 'No data');
        }

        if (charts.database) {
            createChart(
                'sitepulse-database-chart',
                charts.database,
                {
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
                },
                strings
            );
        } else {
            showFallback(document.getElementById('sitepulse-database-chart'), strings.noData || 'No data');
        }

        if (charts.logs) {
            createChart(
                'sitepulse-log-chart',
                charts.logs,
                {
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
                },
                strings
            );
        } else {
            showFallback(document.getElementById('sitepulse-log-chart'), strings.noData || 'No data');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
