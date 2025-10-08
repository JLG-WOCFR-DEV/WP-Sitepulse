(function () {
    'use strict';

    const config = window.sitepulsePluginImpactScanner || {};
    const thresholds = Object.assign(
        {
            impactWarning: 30,
            impactCritical: 60,
            weightWarning: 10,
            weightCritical: 20,
        },
        config.thresholds || {}
    );

    const table = document.querySelector('[data-sitepulse-impact-table]');

    if (!table) {
        return;
    }

    const tbody = table.querySelector('tbody');
    const wrapper = table.closest('.sitepulse-impact-table-wrapper') || table.parentElement;
    const controls = document.querySelector('[data-sitepulse-impact-controls]');

    if (!tbody || !controls) {
        return;
    }

    const state = {
        sort: 'impact-desc',
        minWeight: null,
        maxWeight: null,
    };

    const sortSelect = controls.querySelector('[data-sitepulse-impact-sort]');
    const minInput = controls.querySelector('[data-sitepulse-impact-weight-min]');
    const maxInput = controls.querySelector('[data-sitepulse-impact-weight-max]');
    const resetButton = controls.querySelector('[data-sitepulse-impact-reset]');
    const exportButton = controls.querySelector('[data-sitepulse-impact-export]');

    let rows = Array.from(tbody.querySelectorAll('tr')).filter((row) => row.dataset && row.dataset.pluginFile);

    if (!rows.length) {
        if (exportButton) {
            exportButton.disabled = true;
        }

        return;
    }

    const emptyNotice = document.createElement('div');
    emptyNotice.className = 'notice notice-info sitepulse-impact-empty';
    emptyNotice.setAttribute('data-sitepulse-impact-empty', '');
    emptyNotice.textContent = (config.i18n && config.i18n.noResult) || 'Aucun plugin ne correspond aux filtres.';
    emptyNotice.hidden = true;

    if (wrapper && wrapper.appendChild) {
        wrapper.appendChild(emptyNotice);
    }

    function parseNumber(value) {
        const parsed = typeof value === 'string' ? parseFloat(value) : Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function getRowData(row) {
        if (!row.__sitepulseImpactData) {
            row.__sitepulseImpactData = {
                pluginFile: row.dataset.pluginFile || '',
                pluginName: row.dataset.pluginName || '',
                impact: parseNumber(row.dataset.impact),
                lastMs: parseNumber(row.dataset.lastMs),
                weight: parseNumber(row.dataset.weight),
                samples: parseNumber(row.dataset.samples),
                diskSpace: parseNumber(row.dataset.diskSpace),
                diskFiles: parseNumber(row.dataset.diskFiles),
                diskRecorded: parseNumber(row.dataset.diskRecorded),
                lastRecorded: parseNumber(row.dataset.lastRecorded),
                isMeasured: row.dataset.isMeasured === '1',
            };
        }

        return row.__sitepulseImpactData;
    }

    function compareRows(a, b, sortKey) {
        const dataA = getRowData(a);
        const dataB = getRowData(b);

        const nameA = dataA.pluginName.toLocaleLowerCase();
        const nameB = dataB.pluginName.toLocaleLowerCase();

        switch (sortKey) {
            case 'impact-asc':
                return compareNumeric(dataA.impact, dataB.impact, true) || nameA.localeCompare(nameB);
            case 'weight-desc':
                return compareNumeric(dataB.weight, dataA.weight, false) || nameA.localeCompare(nameB);
            case 'name-asc':
                return nameA.localeCompare(nameB, undefined, { sensitivity: 'base' });
            case 'impact-desc':
            default:
                return compareNumeric(dataB.impact, dataA.impact, false) || nameA.localeCompare(nameB);
        }
    }

    function compareNumeric(a, b, asc) {
        const isANumber = Number.isFinite(a);
        const isBNumber = Number.isFinite(b);

        if (isANumber && isBNumber) {
            return asc ? a - b : b - a;
        }

        if (isANumber && !isBNumber) {
            return -1;
        }

        if (!isANumber && isBNumber) {
            return 1;
        }

        return 0;
    }

    function applySort() {
        const sorted = rows.slice().sort((a, b) => compareRows(a, b, state.sort));
        sorted.forEach((row) => {
            tbody.appendChild(row);
        });
        rows = sorted;
    }

    function applyFilters() {
        let visibleCount = 0;

        rows.forEach((row) => {
            const data = getRowData(row);
            let isVisible = true;

            if (state.minWeight !== null) {
                if (!Number.isFinite(data.weight) || data.weight < state.minWeight) {
                    isVisible = false;
                }
            }

            if (isVisible && state.maxWeight !== null) {
                if (!Number.isFinite(data.weight) || data.weight > state.maxWeight) {
                    isVisible = false;
                }
            }

            row.hidden = !isVisible;

            if (isVisible) {
                visibleCount += 1;
            }
        });

        if (emptyNotice) {
            emptyNotice.hidden = visibleCount !== 0;
        }

        if (exportButton) {
            exportButton.disabled = visibleCount === 0;
        }
    }

    function applyHighlights() {
        rows.forEach((row) => {
            const data = getRowData(row);
            let severity = '';

            if (Number.isFinite(data.weight) && data.weight >= thresholds.weightCritical) {
                severity = 'critical';
            } else if (Number.isFinite(data.impact) && data.impact >= thresholds.impactCritical) {
                severity = 'critical';
            } else if (Number.isFinite(data.weight) && data.weight >= thresholds.weightWarning) {
                severity = 'warning';
            } else if (Number.isFinite(data.impact) && data.impact >= thresholds.impactWarning) {
                severity = 'warning';
            }

            row.classList.remove('sitepulse-impact-row--warning', 'sitepulse-impact-row--critical');

            if (severity) {
                row.classList.add(`sitepulse-impact-row--${severity}`);
            }
        });
    }

    function onSortChange(event) {
        state.sort = event.target.value;
        applySort();
    }

    function onWeightChange() {
        state.minWeight = parseNumber(minInput && minInput.value);
        state.maxWeight = parseNumber(maxInput && maxInput.value);

        applyFilters();
    }

    function onReset() {
        state.sort = 'impact-desc';
        state.minWeight = null;
        state.maxWeight = null;

        if (sortSelect) {
            sortSelect.value = 'impact-desc';
        }

        if (minInput) {
            minInput.value = '';
        }

        if (maxInput) {
            maxInput.value = '';
        }

        applySort();
        applyFilters();
    }

    function formatCsvValue(value) {
        if (value === null || typeof value === 'undefined') {
            return '""';
        }

        const stringValue = String(value).replace(/"/g, '""');
        return `"${stringValue}"`;
    }

    function formatTimestamp(timestamp) {
        if (!Number.isFinite(timestamp) || timestamp <= 0) {
            return '';
        }

        const date = new Date(timestamp * 1000);

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return date.toISOString();
    }

    function exportCsv() {
        const visibleRows = rows.filter((row) => !row.hidden);

        if (!visibleRows.length) {
            return;
        }

        const header = [
            'plugin_name',
            'plugin_file',
            'impact_avg_ms',
            'last_measure_ms',
            'weight_percent',
            'samples',
            'disk_space_bytes',
            'last_recorded',
            'is_measured',
        ];

        const csvLines = [header.map(formatCsvValue).join(';')];

        visibleRows.forEach((row) => {
            const data = getRowData(row);
            csvLines.push(
                [
                    data.pluginName,
                    data.pluginFile,
                    Number.isFinite(data.impact) ? data.impact.toFixed(4) : '',
                    Number.isFinite(data.lastMs) ? data.lastMs.toFixed(4) : '',
                    Number.isFinite(data.weight) ? data.weight.toFixed(4) : '',
                    Number.isFinite(data.samples) ? Math.round(data.samples) : '',
                    Number.isFinite(data.diskSpace) ? Math.round(data.diskSpace) : '',
                    formatTimestamp(data.lastRecorded),
                    data.isMeasured ? '1' : '0',
                ]
                    .map(formatCsvValue)
                    .join(';')
            );
        });

        const csvContent = csvLines.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = (config.i18n && config.i18n.fileName) || 'sitepulse-plugin-impact.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    if (sortSelect) {
        sortSelect.addEventListener('change', onSortChange);
    }

    if (minInput) {
        minInput.addEventListener('input', onWeightChange);
    }

    if (maxInput) {
        maxInput.addEventListener('input', onWeightChange);
    }

    if (resetButton) {
        resetButton.addEventListener('click', onReset);
    }

    if (exportButton) {
        exportButton.addEventListener('click', exportCsv);
    }

    applySort();
    applyFilters();
    applyHighlights();
})();
