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

    function init() {
        if (typeof Chart === 'undefined') {
            return;
        }

        var config = window.SitePulseDashboardData || {};
        var charts = config.charts || {};
        var strings = config.strings || {};

        if (charts.speed) {
            var speedOverrides;

            if (charts.speed.type === 'line') {
                speedOverrides = {
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
                                    var unit = charts.speed.unit || 'ms';
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
            } else {
                speedOverrides = {
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
                };
            }

            createChart('sitepulse-speed-chart', charts.speed, speedOverrides, strings);
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

        if (charts.resource) {
            createChart(
                'sitepulse-resource-chart',
                charts.resource,
                {
                    cutout: '60%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    var label = context.label || '';
                                    var raw = typeof context.raw === 'number' ? context.raw : 0;
                                    var unit = charts.resource.unit || 'MB';
                                    var parts = [];

                                    if (label) {
                                        parts.push(label);
                                    }

                                    parts.push(formatValue(raw, unit, 1));

                                    return parts.join(': ');
                                },
                            },
                        },
                    },
                },
                strings
            );
        } else {
            showFallback(document.getElementById('sitepulse-resource-chart'), strings.noData || 'No data');
        }

        if (charts.plugins) {
            var pluginImpacts = charts.plugins.meta && Array.isArray(charts.plugins.meta.impacts)
                ? charts.plugins.meta.impacts
                : [];

            createChart(
                'sitepulse-plugins-chart',
                charts.plugins,
                {
                    indexAxis: charts.plugins.options && charts.plugins.options.indexAxis ? charts.plugins.options.indexAxis : 'y',
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    var labelParts = [];
                                    var impactValue = pluginImpacts[context.dataIndex];
                                    var impactLabel = strings.pluginsImpactLabel || 'Impact';
                                    var impactUnit = strings.pluginsImpactUnit || 'ms';
                                    var shareLabel = strings.pluginsShareLabel || 'Share';
                                    var rawShare = typeof context.raw === 'number' ? context.raw : 0;

                                    if (typeof impactValue === 'number' && !isNaN(impactValue)) {
                                        labelParts.push(impactLabel + ': ' + formatValue(impactValue, impactUnit, 2));
                                    }

                                    labelParts.push(shareLabel + ': ' + formatValue(rawShare, charts.plugins.unit || '%', 1));

                                    var name = context.label || '';

                                    if (name) {
                                        return name + ' — ' + labelParts.join(' · ');
                                    }

                                    return labelParts.join(' · ');
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function (value) {
                                    return value + '%';
                                },
                            },
                        },
                        y: {
                            ticks: {
                                autoSkip: false,
                            },
                        },
                    },
                },
                strings
            );
        } else {
            showFallback(document.getElementById('sitepulse-plugins-chart'), strings.noData || 'No data');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
