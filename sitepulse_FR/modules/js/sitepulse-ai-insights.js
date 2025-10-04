(function ($) {
    'use strict';

    function formatTimestamp(timestamp) {
        if (!timestamp) {
            return '';
        }

        var parsed = parseInt(timestamp, 10);

        if (!isFinite(parsed) || parsed <= 0) {
            return '';
        }

        var date = new Date(parsed * 1000);

        if (isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleString();
    }

    function generateHistoryFallbackId(parts) {
        var input = parts.join('|');
        var hash = 0;

        for (var i = 0; i < input.length; i += 1) {
            hash = (hash << 5) - hash + input.charCodeAt(i);
            hash |= 0;
        }

        return 'local-' + Math.abs(hash).toString(36);
    }

    function setStatus($statusEl, message) {
        if (typeof message === 'string' && message.trim() !== '') {
            $statusEl.text(message).show();
            $statusEl.removeAttr('aria-hidden');
        } else {
            $statusEl.hide().text('');
            $statusEl.attr('aria-hidden', 'true');
        }
    }

    function normalizeHistoryEntry(entry) {
        if (!entry || typeof entry !== 'object') {
            return null;
        }

        var entryId = '';

        if (typeof entry.id === 'string') {
            entryId = entry.id.trim();
        } else if (typeof entry.id !== 'undefined') {
            entryId = String(entry.id || '').trim();
        }

        var text = typeof entry.text === 'string' ? entry.text : '';
        var html = typeof entry.html === 'string' ? entry.html : '';
        var trimmedText = text.trim();
        var trimmedHtml = html.trim();

        if (trimmedText.length === 0 && trimmedHtml.length > 0) {
            var temp = $('<div/>').html(trimmedHtml);
            trimmedText = temp.text().trim();
        }

        if (trimmedText.length === 0) {
            return null;
        }

        var timestamp = entry.timestamp;

        if (typeof timestamp === 'string') {
            timestamp = parseInt(timestamp, 10);
        }

        if (!isFinite(timestamp) || timestamp <= 0) {
            timestamp = null;
        }

        var timestampDisplay = typeof entry.timestamp_display === 'string' ? entry.timestamp_display : '';
        var timestampIso = typeof entry.timestamp_iso === 'string' ? entry.timestamp_iso : '';

        if (timestamp && (!timestampDisplay || !timestampIso)) {
            var timestampDate = new Date(timestamp * 1000);

            if (!timestampDisplay && !isNaN(timestampDate.getTime())) {
                timestampDisplay = timestampDate.toLocaleString();
            }

            if (!timestampIso && !isNaN(timestampDate.getTime())) {
                timestampIso = timestampDate.toISOString();
            }
        }

        var model = entry.model && typeof entry.model === 'object' ? entry.model : {};
        var rateLimit = entry.rate_limit && typeof entry.rate_limit === 'object' ? entry.rate_limit : {};

        var modelKey = typeof model.key === 'string' ? model.key : '';
        var rateLimitKey = typeof rateLimit.key === 'string' ? rateLimit.key : '';

        if (!entryId) {
            entryId = generateHistoryFallbackId([
                String(timestamp || ''),
                modelKey,
                rateLimitKey,
                trimmedText
            ]);
        }

        var note = '';

        if (typeof entry.note === 'string') {
            note = entry.note.trim();
        }

        return {
            id: entryId,
            text: trimmedText,
            html: trimmedHtml,
            timestamp: timestamp,
            timestamp_display: timestampDisplay,
            timestamp_iso: timestampIso,
            model: {
                key: modelKey,
                label: typeof model.label === 'string' ? model.label : '',
            },
            rate_limit: {
                key: rateLimitKey,
                label: typeof rateLimit.label === 'string' ? rateLimit.label : '',
            },
            note: note,
        };
    }

    function buildHistoryListItem(entry) {
        var normalized = entry && typeof entry === 'object' && typeof entry.text === 'string'
            ? entry
            : normalizeHistoryEntry(entry);

        if (!normalized) {
            return null;
        }

        var $item = $('<li/>')
            .addClass('sitepulse-ai-history-item')
            .attr('data-entry-id', normalized.id || '')
            .attr('data-model', normalized.model && normalized.model.key ? normalized.model.key : '')
            .attr('data-rate-limit', normalized.rate_limit && normalized.rate_limit.key ? normalized.rate_limit.key : '');

        var metaParts = [];
        var formattedTimestamp = formatTimestamp(normalized.timestamp);

        if (formattedTimestamp) {
            metaParts.push(formattedTimestamp);
        }

        if (normalized.model && normalized.model.label) {
            metaParts.push(normalized.model.label);
        }

        if (normalized.rate_limit && normalized.rate_limit.label) {
            metaParts.push(normalized.rate_limit.label);
        }

        if (metaParts.length > 0) {
            $('<p/>')
                .addClass('sitepulse-ai-history-meta')
                .text(metaParts.join(' • '))
                .appendTo($item);
        }

        var $textEl = $('<div/>').addClass('sitepulse-ai-history-text');

        if (normalized.html && normalized.html.trim() !== '') {
            $textEl.html(normalized.html);
        } else {
            $textEl.text(normalized.text);
        }

        $item.append($textEl);

        var noteLabel = sitepulseAIInsights.strings && sitepulseAIInsights.strings.historyNoteLabel
            ? sitepulseAIInsights.strings.historyNoteLabel
            : 'Note';
        var notePlaceholder = sitepulseAIInsights.strings && sitepulseAIInsights.strings.historyNotePlaceholder
            ? sitepulseAIInsights.strings.historyNotePlaceholder
            : '';
        var noteFieldId = 'sitepulse-ai-history-note-' + normalized.id;

        var $noteWrapper = $('<div/>').addClass('sitepulse-ai-history-note');
        $('<label/>')
            .attr('for', noteFieldId)
            .text(noteLabel)
            .appendTo($noteWrapper);

        $('<textarea/>')
            .attr({
                id: noteFieldId,
                rows: 2,
                placeholder: notePlaceholder
            })
            .addClass('sitepulse-ai-history-note-field')
            .attr('data-entry-id', normalized.id)
            .val(normalized.note || '')
            .data('savedNote', normalized.note || '')
            .appendTo($noteWrapper);

        $item.append($noteWrapper);

        return $item;
    }

    function renderResult($resultContainer, $textEl, $timestampEl, $statusEl, data) {
        var text = data && typeof data.text === 'string' ? data.text : '';
        var html = data && typeof data.html === 'string' ? data.html : '';
        var trimmedText = text.trim();
        var trimmedHtml = html.trim();

        if (trimmedText.length === 0 && trimmedHtml.length > 0) {
            trimmedText = $('<div/>').html(trimmedHtml).text().trim();
        }

        var timestamp = data && data.timestamp ? data.timestamp : null;
        var isCached = data && typeof data.cached !== 'undefined' ? !!data.cached : false;
        var model = data && data.model ? data.model : null;
        var rateLimit = data && data.rate_limit ? data.rate_limit : null;
        var entryId = data && typeof data.id === 'string' ? data.id : '';
        var note = data && typeof data.note === 'string' ? data.note : '';
        var timestampDisplay = data && typeof data.timestamp_display === 'string' ? data.timestamp_display : '';
        var timestampIso = data && typeof data.timestamp_iso === 'string' ? data.timestamp_iso : '';

        var normalized = {
            text: trimmedText,
            html: trimmedHtml,
            timestamp: timestamp,
            cached: isCached,
            model: model,
            rate_limit: rateLimit,
            id: entryId,
            note: note,
            timestamp_display: timestampDisplay,
            timestamp_iso: timestampIso
        };

        if (trimmedText.length === 0 && trimmedHtml.length === 0) {
            $resultContainer.hide();
            $textEl.empty();
            $timestampEl.hide().text('');
            setStatus($statusEl, '');
            return normalized;
        }

        if (trimmedHtml.length > 0) {
            $textEl.html(trimmedHtml);
        } else {
            $textEl.text(trimmedText);
        }

        if (timestamp && !timestampIso) {
            var isoDate = new Date(timestamp * 1000);

            if (!isNaN(isoDate.getTime())) {
                timestampIso = isoDate.toISOString();
                normalized.timestamp_iso = timestampIso;
            }
        }

        var formattedTimestamp = timestampDisplay || formatTimestamp(timestamp);

        if (formattedTimestamp) {
            normalized.timestamp_display = formattedTimestamp;
            $timestampEl.text(sitepulseAIInsights.strings.cachedPrefix + ' ' + formattedTimestamp).show();
        } else {
            $timestampEl.hide().text('');
        }

        setStatus(
            $statusEl,
            isCached ? sitepulseAIInsights.strings.statusCached : sitepulseAIInsights.strings.statusFresh
        );

        $resultContainer.show();

        return normalized;
    }

    function showError($errorContainer, $errorText, message) {
        var displayMessage = message;

        if (typeof displayMessage !== 'string' || displayMessage.trim() === '') {
            displayMessage = sitepulseAIInsights.strings.defaultError;
        }

        $errorText.text(displayMessage);
        $errorContainer.show();

        window.setTimeout(function () {
            $errorContainer.trigger('focus');
        }, 0);
    }

    function reinstateLastResult($resultContainer, $resultText, $timestampEl, $statusEl, lastResultData) {
        var hasText = lastResultData && typeof lastResultData.text === 'string' && lastResultData.text.trim() !== '';
        var hasHtml = lastResultData && typeof lastResultData.html === 'string' && lastResultData.html.trim() !== '';

        if (hasText || hasHtml) {
            renderResult($resultContainer, $resultText, $timestampEl, $statusEl, lastResultData);

            return true;
        }

        setStatus($statusEl, '');
        $resultContainer.hide();

        return false;
    }

    $(function () {
        if (typeof sitepulseAIInsights === 'undefined') {
            return;
        }

        var $button = $('#sitepulse-ai-generate');
        var $spinner = $('#sitepulse-ai-spinner');
        var $errorContainer = $('#sitepulse-ai-insight-error');
        var $errorText = $errorContainer.find('p');
        var $resultContainer = $('#sitepulse-ai-insight-result');
        var $statusEl = $resultContainer.find('.sitepulse-ai-insight-status');
        var $resultText = $resultContainer.find('.sitepulse-ai-insight-text');
        var $timestampEl = $resultContainer.find('.sitepulse-ai-insight-timestamp');
        var $forceRefreshToggle = $('#sitepulse-ai-force-refresh');
        var $actionsContainer = $('.sitepulse-ai-insight-actions');
        var $historyList = $('#sitepulse-ai-history-list');
        var $historyEmpty = $('#sitepulse-ai-history-empty');
        var $modelFilter = $('#sitepulse-ai-history-filter-model');
        var $rateFilter = $('#sitepulse-ai-history-filter-rate');
        var $historyExportButton = $('#sitepulse-ai-history-export-csv');
        var $historyCopyButton = $('#sitepulse-ai-history-copy');
        var $historyFeedback = $('#sitepulse-ai-history-feedback');
        var historyEntries = [];
        var historyMaxEntries = parseInt(sitepulseAIInsights.historyMaxEntries, 10);
        var lastResultData = null;
        var pollTimer = null;
        var activeJobId = null;
        var historyExportConfig = sitepulseAIInsights.historyExport || {};
        var historyExportHeaders = historyExportConfig.headers || {};
        var historyExportColumns = Array.isArray(historyExportConfig.columns) && historyExportConfig.columns.length
            ? historyExportConfig.columns
            : Object.keys(historyExportHeaders || {});
        var historyExportFileName = typeof historyExportConfig.fileName === 'string' && historyExportConfig.fileName
            ? historyExportConfig.fileName
            : 'sitepulse-ai-historique';
        var historyExportRowsMap = {};
        var historyContext = sitepulseAIInsights.historyContext || {};

        if (!Array.isArray(historyExportColumns) || historyExportColumns.length === 0) {
            historyExportColumns = ['timestamp_display', 'model', 'rate_limit', 'text', 'note'];
        }

        if (Array.isArray(historyExportConfig.rows)) {
            historyExportConfig.rows.forEach(function (row) {
                if (!row || typeof row !== 'object') {
                    return;
                }

                var rowId = '';

                if (typeof row.id === 'string') {
                    rowId = row.id;
                } else if (typeof row.id !== 'undefined') {
                    rowId = String(row.id || '');
                }

                if (!rowId) {
                    return;
                }

                historyExportRowsMap[rowId] = {
                    id: rowId,
                    timestamp: typeof row.timestamp === 'number' ? row.timestamp : parseInt(row.timestamp, 10) || 0,
                    timestamp_display: typeof row.timestamp_display === 'string' ? row.timestamp_display : '',
                    timestamp_iso: typeof row.timestamp_iso === 'string' ? row.timestamp_iso : '',
                    model: typeof row.model === 'string' ? row.model : '',
                    model_key: typeof row.model_key === 'string' ? row.model_key : '',
                    rate_limit: typeof row.rate_limit === 'string' ? row.rate_limit : '',
                    rate_limit_key: typeof row.rate_limit_key === 'string' ? row.rate_limit_key : '',
                    text: typeof row.text === 'string' ? row.text : '',
                    note: typeof row.note === 'string' ? row.note : ''
                };
            });
        }

        if (!isFinite(historyMaxEntries) || historyMaxEntries <= 0) {
            historyMaxEntries = 20;
        }

        if (Array.isArray(sitepulseAIInsights.historyEntries)) {
            sitepulseAIInsights.historyEntries.forEach(function (entry) {
                var normalizedEntry = normalizeHistoryEntry(entry);

                if (normalizedEntry) {
                    historyEntries.push(normalizedEntry);
                    syncHistoryExportRow(normalizedEntry);
                }
            });
        }

        if (historyMaxEntries > 0 && historyEntries.length > historyMaxEntries) {
            historyEntries = historyEntries.slice(historyEntries.length - historyMaxEntries);
        }

        var existingIds = {};

        historyEntries.forEach(function (entry) {
            if (entry && entry.id) {
                existingIds[entry.id] = true;
            }
        });

        Object.keys(historyExportRowsMap).forEach(function (id) {
            if (!existingIds[id]) {
                delete historyExportRowsMap[id];
            }
        });

        function setActionsBusy(isBusy) {
            if ($actionsContainer.length === 0) {
                return;
            }

            $actionsContainer.attr('aria-busy', isBusy ? 'true' : 'false');
        }

        function announceHistoryMessage(message) {
            if (!$historyFeedback.length) {
                return;
            }

            var text = '';

            if (typeof message === 'string' && message.trim() !== '') {
                text = message.trim();
            } else if (sitepulseAIInsights.strings && sitepulseAIInsights.strings.historyAriaDefault) {
                text = sitepulseAIInsights.strings.historyAriaDefault;
            }

            if (text) {
                $historyFeedback.text(text);
            }
        }

        function syncHistoryExportRow(entry) {
            var normalized = entry && typeof entry === 'object' && typeof entry.text === 'string'
                ? entry
                : normalizeHistoryEntry(entry);

            if (!normalized) {
                return;
            }

            var entryId = typeof normalized.id === 'string' ? normalized.id : '';

            if (!entryId) {
                return;
            }

            var existing = historyExportRowsMap[entryId] || {};
            var timestamp = normalized.timestamp;

            if (typeof timestamp !== 'number') {
                timestamp = existing.timestamp || 0;
            }

            var timestampDisplay = normalized.timestamp_display || existing.timestamp_display || '';
            var timestampIso = normalized.timestamp_iso || existing.timestamp_iso || '';

            if (timestamp && (!timestampDisplay || !timestampIso)) {
                var fallbackDate = new Date(timestamp * 1000);

                if (!timestampDisplay && !isNaN(fallbackDate.getTime())) {
                    timestampDisplay = fallbackDate.toLocaleString();
                }

                if (!timestampIso && !isNaN(fallbackDate.getTime())) {
                    timestampIso = fallbackDate.toISOString();
                }
            }

            historyExportRowsMap[entryId] = {
                id: entryId,
                timestamp: timestamp || 0,
                timestamp_display: timestampDisplay || '',
                timestamp_iso: timestampIso || '',
                model: normalized.model && normalized.model.label
                    ? normalized.model.label
                    : existing.model || '',
                model_key: normalized.model && normalized.model.key
                    ? normalized.model.key
                    : existing.model_key || '',
                rate_limit: normalized.rate_limit && normalized.rate_limit.label
                    ? normalized.rate_limit.label
                    : existing.rate_limit || '',
                rate_limit_key: normalized.rate_limit && normalized.rate_limit.key
                    ? normalized.rate_limit.key
                    : existing.rate_limit_key || '',
                text: normalized.text || existing.text || '',
                note: normalized.note || ''
            };
        }

        function updateHistoryEntryNoteLocal(entryId, note) {
            if (!entryId) {
                return;
            }

            historyEntries.forEach(function (entry) {
                if (entry && entry.id === entryId) {
                    entry.note = note;
                }
            });

            if (historyExportRowsMap[entryId]) {
                historyExportRowsMap[entryId].note = note;
            }

            if (sitepulseAIInsights.historyExport && Array.isArray(sitepulseAIInsights.historyExport.rows)) {
                sitepulseAIInsights.historyExport.rows = Object.keys(historyExportRowsMap).map(function (key) {
                    return historyExportRowsMap[key];
                });
            }
        }

        function clearPollingTimer() {
            if (pollTimer) {
                window.clearTimeout(pollTimer);
                pollTimer = null;
            }
        }

        function finalizeRequest() {
            clearPollingTimer();
            activeJobId = null;
            $spinner.removeClass('is-active');
            $button.prop('disabled', false);
            setActionsBusy(false);
        }

        function ensureFilterOption($select, data) {
            if (!$select.length || !data || typeof data.key === 'undefined') {
                return;
            }

            var value = typeof data.key === 'string' ? data.key : String(data.key || '');

            if (!value) {
                return;
            }

            var label = typeof data.label === 'string' && data.label.trim() !== '' ? data.label : value;
            var exists = false;

            $select.find('option').each(function () {
                if ($(this).val() === value) {
                    exists = true;
                    return false;
                }
            });

            if (exists) {
                return;
            }

            $select.append($('<option/>').attr('value', value).text(label));
        }

        function initializeHistoryFilters() {
            if (!sitepulseAIInsights.historyFilters) {
                return;
            }

            if ($modelFilter.length && Array.isArray(sitepulseAIInsights.historyFilters.models)) {
                sitepulseAIInsights.historyFilters.models.forEach(function (option) {
                    ensureFilterOption($modelFilter, option);
                });
            }

            if ($rateFilter.length && Array.isArray(sitepulseAIInsights.historyFilters.rateLimits)) {
                sitepulseAIInsights.historyFilters.rateLimits.forEach(function (option) {
                    ensureFilterOption($rateFilter, option);
                });
            }
        }

        function getFilteredHistoryEntries() {
            var filtered = [];
            var selectedModel = $modelFilter.length ? $modelFilter.val() : '';
            var selectedRate = $rateFilter.length ? $rateFilter.val() : '';

            if (Array.isArray(historyEntries)) {
                historyEntries.forEach(function (entry) {
                    if (!entry || typeof entry !== 'object') {
                        return;
                    }

                    if (selectedModel && entry.model && entry.model.key !== selectedModel) {
                        return;
                    }

                    if (selectedRate && entry.rate_limit && entry.rate_limit.key !== selectedRate) {
                        return;
                    }

                    filtered.push(entry);
                });
            }

            filtered.sort(function (a, b) {
                var aTime = a && a.timestamp ? a.timestamp : 0;
                var bTime = b && b.timestamp ? b.timestamp : 0;

                return bTime - aTime;
            });

            return filtered;
        }

        function renderHistoryEntriesList() {
            if ($historyList.length === 0) {
                return;
            }

            var filtered = getFilteredHistoryEntries();

            $historyList.empty();

            if (filtered.length === 0) {
                if ($historyEmpty.length) {
                    var emptyText = sitepulseAIInsights.strings && sitepulseAIInsights.strings.historyEmpty
                        ? sitepulseAIInsights.strings.historyEmpty
                        : $historyEmpty.text();

                    $historyEmpty.text(emptyText).show();
                }

                return;
            }

            if ($historyEmpty.length) {
                $historyEmpty.hide();
            }

            filtered.forEach(function (entry) {
                var $item = buildHistoryListItem(entry);

                if ($item) {
                    $historyList.append($item);
                }
            });
        }

        function escapeCsvValue(value) {
            var stringValue = String(value);

            if (/[";\n\r]/.test(stringValue)) {
                stringValue = '"' + stringValue.replace(/"/g, '""') + '"';
            }

            return stringValue;
        }

        function buildHistoryPlainText(entries) {
            var lines = [];

            if (historyContext.siteName) {
                lines.push(historyContext.siteName);
            }

            if (historyContext.siteUrl) {
                lines.push(historyContext.siteUrl);
            }

            if (historyContext.pageUrl) {
                lines.push(historyContext.pageUrl);
            }

            if (lines.length) {
                lines.push('');
            }

            var noteLabel = sitepulseAIInsights.strings && sitepulseAIInsights.strings.historyNoteLabel
                ? sitepulseAIInsights.strings.historyNoteLabel
                : 'Note';

            entries.forEach(function (entry) {
                if (!entry || typeof entry !== 'object') {
                    return;
                }

                syncHistoryExportRow(entry);

                var exportRow = entry.id ? historyExportRowsMap[entry.id] : null;
                var headerParts = [];

                if (exportRow && exportRow.timestamp_display) {
                    headerParts.push(exportRow.timestamp_display);
                } else if (entry.timestamp) {
                    var formatted = formatTimestamp(entry.timestamp);

                    if (formatted) {
                        headerParts.push(formatted);
                    }
                }

                if (entry.model && entry.model.label) {
                    headerParts.push(entry.model.label);
                }

                if (entry.rate_limit && entry.rate_limit.label) {
                    headerParts.push(entry.rate_limit.label);
                }

                if (headerParts.length) {
                    lines.push(headerParts.join(' • '));
                }

                lines.push(entry.text || '');

                if (entry.note) {
                    lines.push(noteLabel + ' : ' + entry.note);
                }

                lines.push('');
            });

            return lines.join('\n').trim();
        }

        function copyHistoryToClipboard(entries) {
            var text = buildHistoryPlainText(entries);

            if (!text) {
                announceHistoryMessage(sitepulseAIInsights.strings.historyNoEntries || '');
                return;
            }

            function fallbackCopy(textToCopy) {
                var $textarea = $('<textarea readonly class="sitepulse-ai-history-copy-buffer" />')
                    .css({
                        position: 'absolute',
                        left: '-9999px',
                        top: 'auto'
                    })
                    .val(textToCopy);

                $('body').append($textarea);
                $textarea[0].select();

                try {
                    document.execCommand('copy');
                    announceHistoryMessage(sitepulseAIInsights.strings.historyCopied || '');
                } catch (err) {
                    announceHistoryMessage(sitepulseAIInsights.strings.historyCopyError || '');
                }

                $textarea.remove();
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    announceHistoryMessage(sitepulseAIInsights.strings.historyCopied || '');
                }).catch(function () {
                    fallbackCopy(text);
                });
            } else {
                fallbackCopy(text);
            }
        }

        function exportHistoryCsv(entries) {
            if (!Array.isArray(entries) || entries.length === 0) {
                announceHistoryMessage(sitepulseAIInsights.strings.historyNoEntries || '');
                return;
            }

            var columns = historyExportColumns && historyExportColumns.length
                ? historyExportColumns
                : ['timestamp_display', 'model', 'rate_limit', 'text', 'note'];
            var headerRow = columns.map(function (column) {
                var label = historyExportHeaders[column];

                if (typeof label !== 'string') {
                    label = column;
                }

                return escapeCsvValue(label);
            });
            var csvRows = [headerRow.join(';')];

            entries.forEach(function (entry) {
                if (!entry || typeof entry !== 'object') {
                    return;
                }

                syncHistoryExportRow(entry);

                var exportRow = entry.id ? historyExportRowsMap[entry.id] : null;

                if (!exportRow) {
                    return;
                }

                var rowValues = columns.map(function (column) {
                    var value = exportRow[column];

                    if (typeof value === 'undefined' || value === null) {
                        value = '';
                    }

                    if (!value && column === 'text') {
                        value = entry.text || '';
                    }

                    if (!value && column === 'note') {
                        value = entry.note || '';
                    }

                    return escapeCsvValue(value);
                });

                csvRows.push(rowValues.join(';'));
            });

            if (csvRows.length <= 1) {
                announceHistoryMessage(sitepulseAIInsights.strings.historyNoEntries || '');
                return;
            }

            var csvContent = csvRows.join('\r\n');
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a');
            var timestampSuffix = new Date().toISOString().replace(/[:.]/g, '-');

            link.href = url;
            link.download = historyExportFileName + '-' + timestampSuffix + '.csv';

            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            window.setTimeout(function () {
                URL.revokeObjectURL(url);
            }, 1000);

            announceHistoryMessage(sitepulseAIInsights.strings.historyDownload || '');
        }

        function saveHistoryNote(entryId, note, $field) {
            if (!entryId) {
                return;
            }

            var requestData = {
                action: sitepulseAIInsights.noteAction || 'sitepulse_save_ai_history_note',
                nonce: sitepulseAIInsights.nonce,
                entry_id: entryId,
                note: note
            };

            if ($field && $field.length) {
                $field.attr('aria-busy', 'true').addClass('is-saving');
            }

            $.post(sitepulseAIInsights.ajaxUrl, requestData).done(function (response) {
                if (response && response.success && response.data) {
                    var savedNote = typeof response.data.note === 'string' ? response.data.note : '';

                    if ($field && $field.length) {
                        $field.val(savedNote);
                        $field.data('savedNote', savedNote);
                        $field.attr('aria-busy', 'false');
                    }

                    updateHistoryEntryNoteLocal(entryId, savedNote);
                    announceHistoryMessage(sitepulseAIInsights.strings.historyNoteSaved || '');
                } else {
                    if ($field && $field.length) {
                        $field.val($field.data('savedNote') || '');
                        $field.attr('aria-busy', 'false');
                    }

                    announceHistoryMessage(sitepulseAIInsights.strings.historyNoteError || '');
                }
            }).fail(function () {
                if ($field && $field.length) {
                    $field.val($field.data('savedNote') || '');
                    $field.attr('aria-busy', 'false');
                }

                announceHistoryMessage(sitepulseAIInsights.strings.historyNoteError || '');
            }).always(function () {
                if ($field && $field.length) {
                    $field.removeClass('is-saving');
                }
            });
        }

        function addHistoryEntryFromResult(result) {
            var entry = normalizeHistoryEntry({
                text: result && typeof result.text === 'string' ? result.text : '',
                html: result && typeof result.html === 'string' ? result.html : '',
                timestamp: result ? result.timestamp : null,
                model: result ? result.model : null,
                rate_limit: result ? result.rate_limit : null,
                id: result && typeof result.id === 'string' ? result.id : '',
                note: result && typeof result.note === 'string' ? result.note : '',
                timestamp_display: result && typeof result.timestamp_display === 'string' ? result.timestamp_display : '',
                timestamp_iso: result && typeof result.timestamp_iso === 'string' ? result.timestamp_iso : ''
            });

            if (!entry) {
                return;
            }

            historyEntries = historyEntries.filter(function (existing) {
                return !existing || existing.id !== entry.id;
            });

            historyEntries.push(entry);

            if (historyMaxEntries > 0 && historyEntries.length > historyMaxEntries) {
                historyEntries = historyEntries.slice(historyEntries.length - historyMaxEntries);
            }

            syncHistoryExportRow(entry);

            var currentIds = {};

            historyEntries.forEach(function (item) {
                if (item && item.id) {
                    currentIds[item.id] = true;
                }
            });

            Object.keys(historyExportRowsMap).forEach(function (id) {
                if (!currentIds[id]) {
                    delete historyExportRowsMap[id];
                }
            });

            ensureFilterOption($modelFilter, entry.model);
            ensureFilterOption($rateFilter, entry.rate_limit);
            renderHistoryEntriesList();
        }

        function scheduleJobPoll(jobId, immediate) {
            clearPollingTimer();

            var interval = parseInt(sitepulseAIInsights.pollInterval, 10);

            if (!isFinite(interval) || interval <= 0) {
                interval = 5000;
            }

            pollTimer = window.setTimeout(function () {
                $.post(sitepulseAIInsights.ajaxUrl, {
                    action: sitepulseAIInsights.statusAction || 'sitepulse_get_ai_insight_status',
                    nonce: sitepulseAIInsights.nonce,
                    job_id: jobId
                }).done(function (response) {
                    if (response && response.success && response.data) {
                        var status = typeof response.data.status === 'string' ? response.data.status : 'queued';

                        if (status === 'completed' && response.data.result) {
                            lastResultData = renderResult($resultContainer, $resultText, $timestampEl, $statusEl, response.data.result);
                            addHistoryEntryFromResult(response.data.result);
                            finalizeRequest();
                        } else if (status === 'failed') {
                            var failureMessage = response.data.message || sitepulseAIInsights.strings.defaultError;
                            showError($errorContainer, $errorText, failureMessage);
                            var hadPrevious = reinstateLastResult($resultContainer, $resultText, $timestampEl, $statusEl, lastResultData);
                            setStatus($statusEl, sitepulseAIInsights.strings.statusFailed);
                            if (!hadPrevious) {
                                $resultContainer.show();
                            }
                            finalizeRequest();
                        } else {
                            var queuedMessage = sitepulseAIInsights.strings.statusGenerating;

                            if (status === 'queued' && sitepulseAIInsights.strings.statusQueued) {
                                queuedMessage = sitepulseAIInsights.strings.statusQueued;
                            }

                            setStatus($statusEl, queuedMessage);
                            $resultContainer.show();
                            scheduleJobPoll(jobId, false);
                        }
                    } else {
                        var unknownMessage = response && response.data && response.data.message
                            ? response.data.message
                            : sitepulseAIInsights.strings.defaultError;
                        showError($errorContainer, $errorText, unknownMessage);
                        var hadResult = reinstateLastResult($resultContainer, $resultText, $timestampEl, $statusEl, lastResultData);
                        setStatus($statusEl, sitepulseAIInsights.strings.statusFailed);
                        if (!hadResult) {
                            $resultContainer.show();
                        }
                        finalizeRequest();
                    }
                }).fail(function (xhr) {
                    var message = sitepulseAIInsights.strings.defaultError;

                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        message = xhr.responseJSON.data.message;
                    }

                    showError($errorContainer, $errorText, message);
                    var hadData = reinstateLastResult($resultContainer, $resultText, $timestampEl, $statusEl, lastResultData);
                    setStatus($statusEl, sitepulseAIInsights.strings.statusFailed);
                    if (!hadData) {
                        $resultContainer.show();
                    }
                    finalizeRequest();
                });
            }, immediate ? 0 : interval);
        }

        $errorContainer.hide();
        $spinner.removeClass('is-active');
        setActionsBusy(false);

        lastResultData = renderResult($resultContainer, $resultText, $timestampEl, $statusEl, {
            text: sitepulseAIInsights.initialInsight,
            html: sitepulseAIInsights.initialInsightHtml,
            timestamp: sitepulseAIInsights.initialTimestamp,
            cached: !!sitepulseAIInsights.initialCached
        });

        initializeHistoryFilters();

        if ($historyEmpty.length && sitepulseAIInsights.strings && sitepulseAIInsights.strings.historyEmpty) {
            $historyEmpty.text(sitepulseAIInsights.strings.historyEmpty);
        }

        renderHistoryEntriesList();

        if ($modelFilter.length) {
            $modelFilter.on('change', function () {
                renderHistoryEntriesList();
            });
        }

        if ($rateFilter.length) {
            $rateFilter.on('change', function () {
                renderHistoryEntriesList();
            });
        }

        if ($historyCopyButton.length) {
            $historyCopyButton.on('click', function (event) {
                event.preventDefault();

                var filtered = getFilteredHistoryEntries();

                if (!filtered.length) {
                    announceHistoryMessage(sitepulseAIInsights.strings.historyNoEntries || '');
                    return;
                }

                copyHistoryToClipboard(filtered);
            });
        }

        if ($historyExportButton.length) {
            $historyExportButton.on('click', function (event) {
                event.preventDefault();

                var filtered = getFilteredHistoryEntries();

                if (!filtered.length) {
                    announceHistoryMessage(sitepulseAIInsights.strings.historyNoEntries || '');
                    return;
                }

                exportHistoryCsv(filtered);
            });
        }

        if ($historyList.length) {
            $historyList.on('change', '.sitepulse-ai-history-note-field', function () {
                var $field = $(this);
                var entryId = $field.data('entryId');

                if (!entryId) {
                    return;
                }

                var value = typeof $field.val() === 'string' ? $field.val() : '';

                value = value.replace(/\r\n?/g, '\n');

                var normalizedValue = value.trim();

                if ($field.data('savedNote') === normalizedValue) {
                    return;
                }

                if (value !== normalizedValue) {
                    $field.val(normalizedValue);
                }

                saveHistoryNote(entryId, normalizedValue, $field);
            });
        }

        $button.on('click', function (event) {
            event.preventDefault();

            if ($button.prop('disabled')) {
                return;
            }

            $errorContainer.hide();
            $errorText.text('');
            $spinner.addClass('is-active');
            $button.prop('disabled', true);
            setActionsBusy(true);
            $resultContainer.show();
            setStatus($statusEl, sitepulseAIInsights.strings.statusGenerating);

            var requestData = {
                action: 'sitepulse_generate_ai_insight',
                nonce: sitepulseAIInsights.nonce
            };

            var forceRefresh = $forceRefreshToggle.length > 0 && $forceRefreshToggle.is(':checked');

            if (forceRefresh) {
                requestData.force_refresh = true;
            }

            $.post(sitepulseAIInsights.ajaxUrl, requestData).done(function (response) {
                if (response && response.success && response.data) {
                    if (response.data.jobId) {
                        activeJobId = response.data.jobId;
                        setStatus($statusEl, sitepulseAIInsights.strings.statusGenerating);
                        scheduleJobPoll(activeJobId, true);
                    } else {
                        lastResultData = renderResult($resultContainer, $resultText, $timestampEl, $statusEl, response.data);
                        if (response.data && !response.data.cached) {
                            addHistoryEntryFromResult(response.data);
                        }
                        finalizeRequest();
                    }
                } else if (response && response.data && response.data.message) {
                    showError($errorContainer, $errorText, response.data.message);
                    reinstateLastResult($resultContainer, $resultText, $timestampEl, $statusEl, lastResultData);
                    finalizeRequest();
                } else {
                    showError($errorContainer, $errorText);
                    reinstateLastResult($resultContainer, $resultText, $timestampEl, $statusEl, lastResultData);
                    finalizeRequest();
                }
            }).fail(function (xhr) {
                var message = sitepulseAIInsights.strings.defaultError;

                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }

                showError($errorContainer, $errorText, message);
                reinstateLastResult($resultContainer, $resultText, $timestampEl, $statusEl, lastResultData);
                finalizeRequest();
            }).always(function () {
                if (!activeJobId) {
                    $spinner.removeClass('is-active');
                    $button.prop('disabled', false);
                    setActionsBusy(false);
                }
            });
        });
    });
})(jQuery);
