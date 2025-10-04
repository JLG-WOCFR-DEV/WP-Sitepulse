(function(window, document) {
    'use strict';

    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    var settings = window.SitePulseResourceMonitor;

    if (!settings) {
        return;
    }

    var history = Array.isArray(settings.history) ? settings.history : [];
    var container = document.getElementById('sitepulse-resource-history');
    var emptyMessage = container ? container.querySelector('[data-empty]') : null;
    var canvas = document.getElementById('sitepulse-resource-history-chart');

    if (emptyMessage) {
        emptyMessage.hidden = history.length > 0;
    }

    if (!canvas || typeof window.Chart === 'undefined') {
        return;
    }

    if (!history.length) {
        return;
    }

    var locale = typeof settings.locale === 'string' && settings.locale ? settings.locale : undefined;
    var i18n = settings.i18n || {};
    var loadLabel = typeof i18n.loadLabel === 'string' ? i18n.loadLabel : 'CPU';
    var memoryLabel = typeof i18n.memoryLabel === 'string' ? i18n.memoryLabel : 'Memory';
    var diskLabel = typeof i18n.diskLabel === 'string' ? i18n.diskLabel : 'Disk';
    var percentAxisLabel = typeof i18n.percentAxisLabel === 'string' ? i18n.percentAxisLabel : '%';
    var unavailableLabel = typeof i18n.unavailable === 'string' ? i18n.unavailable : 'N/A';

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

    function toFixed(value, precision) {
        if (typeof value !== 'number' || !isFinite(value)) {
            return unavailableLabel;
        }

        return value.toFixed(precision);
    }

    var labels = [];
    var loadDataset = [];
    var memoryPercentDataset = [];
    var diskPercentDataset = [];

    for (var index = 0; index < history.length; index++) {
        var entry = history[index];
        var timestamp = typeof entry.timestamp === 'number' ? entry.timestamp : null;

        labels.push(timestamp ? formatDate(timestamp) : unavailableLabel);

        if (entry.load && Array.isArray(entry.load) && typeof entry.load[0] === 'number') {
            loadDataset.push(entry.load[0]);
        } else {
            loadDataset.push(null);
        }

        var memoryPercent = entry.memory && typeof entry.memory.percentUsage === 'number'
            ? entry.memory.percentUsage
            : null;
        var diskPercent = entry.disk && typeof entry.disk.percentFree === 'number'
            ? entry.disk.percentFree
            : null;

        memoryPercentDataset.push(memoryPercent !== null ? memoryPercent : null);
        diskPercentDataset.push(diskPercent !== null ? diskPercent : null);
    }

    var chartContext = canvas.getContext('2d');

    if (!chartContext) {
        return;
    }

    new window.Chart(chartContext, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: loadLabel,
                    data: loadDataset,
                    borderColor: '#1d4ed8',
                    backgroundColor: 'rgba(29, 78, 216, 0.1)',
                    tension: 0.3,
                    spanGaps: true,
                    yAxisID: 'y',
                },
                {
                    label: memoryLabel,
                    data: memoryPercentDataset,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22, 163, 74, 0.1)',
                    tension: 0.3,
                    spanGaps: true,
                    yAxisID: 'yPercent',
                },
                {
                    label: diskLabel,
                    data: diskPercentDataset,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.1)',
                    tension: 0.3,
                    spanGaps: true,
                    yAxisID: 'yPercent',
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
                            var datasetLabel = context.dataset.label || '';
                            var value = context.parsed.y;
                            var precision = context.dataset.yAxisID === 'y' ? 2 : 1;
                            var suffix = context.dataset.yAxisID === 'y' ? '' : '%';

                            return datasetLabel + ': ' + toFixed(value, precision) + suffix;
                        },
                        title: function(context) {
                            if (!context.length) {
                                return '';
                            }

                            var first = context[0];
                            var idx = typeof first.dataIndex === 'number' ? first.dataIndex : 0;
                            var entry = history[idx];

                            if (!entry || typeof entry.timestamp !== 'number') {
                                return first.label;
                            }

                            return (i18n.timestamp ? i18n.timestamp + ': ' : '') + formatDate(entry.timestamp);
                        },
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: loadLabel,
                    },
                },
                yPercent: {
                    position: 'right',
                    min: 0,
                    max: 100,
                    title: {
                        display: true,
                        text: percentAxisLabel,
                    },
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        },
                    },
                },
            },
        },
    });
})(window, document);
