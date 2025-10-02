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

        var model = entry.model && typeof entry.model === 'object' ? entry.model : {};
        var rateLimit = entry.rate_limit && typeof entry.rate_limit === 'object' ? entry.rate_limit : {};

        return {
            text: trimmedText,
            html: trimmedHtml,
            timestamp: timestamp,
            model: {
                key: typeof model.key === 'string' ? model.key : '',
                label: typeof model.label === 'string' ? model.label : '',
            },
            rate_limit: {
                key: typeof rateLimit.key === 'string' ? rateLimit.key : '',
                label: typeof rateLimit.label === 'string' ? rateLimit.label : '',
            },
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
        var normalized = {
            text: trimmedText,
            html: trimmedHtml,
            timestamp: timestamp,
            cached: isCached,
            model: model,
            rate_limit: rateLimit
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

        var formattedTimestamp = formatTimestamp(timestamp);

        if (formattedTimestamp) {
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
        var historyEntries = [];
        var historyMaxEntries = parseInt(sitepulseAIInsights.historyMaxEntries, 10);
        var lastResultData = null;
        var pollTimer = null;
        var activeJobId = null;

        if (!isFinite(historyMaxEntries) || historyMaxEntries <= 0) {
            historyMaxEntries = 20;
        }

        if (Array.isArray(sitepulseAIInsights.historyEntries)) {
            sitepulseAIInsights.historyEntries.forEach(function (entry) {
                var normalizedEntry = normalizeHistoryEntry(entry);

                if (normalizedEntry) {
                    historyEntries.push(normalizedEntry);
                }
            });
        }

        if (historyMaxEntries > 0 && historyEntries.length > historyMaxEntries) {
            historyEntries = historyEntries.slice(historyEntries.length - historyMaxEntries);
        }

        function setActionsBusy(isBusy) {
            if ($actionsContainer.length === 0) {
                return;
            }

            $actionsContainer.attr('aria-busy', isBusy ? 'true' : 'false');
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

        function renderHistoryEntriesList(entries) {
            if ($historyList.length === 0) {
                return;
            }

            var selectedModel = $modelFilter.length ? $modelFilter.val() : '';
            var selectedRate = $rateFilter.length ? $rateFilter.val() : '';
            var filtered = [];

            if (Array.isArray(entries)) {
                entries.forEach(function (entry) {
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

        function addHistoryEntryFromResult(result) {
            var entry = normalizeHistoryEntry({
                text: result && typeof result.text === 'string' ? result.text : '',
                html: result && typeof result.html === 'string' ? result.html : '',
                timestamp: result ? result.timestamp : null,
                model: result ? result.model : null,
                rate_limit: result ? result.rate_limit : null,
            });

            if (!entry) {
                return;
            }

            historyEntries.push(entry);

            if (historyMaxEntries > 0 && historyEntries.length > historyMaxEntries) {
                historyEntries = historyEntries.slice(historyEntries.length - historyMaxEntries);
            }

            ensureFilterOption($modelFilter, entry.model);
            ensureFilterOption($rateFilter, entry.rate_limit);
            renderHistoryEntriesList(historyEntries);
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

        renderHistoryEntriesList(historyEntries);

        if ($modelFilter.length) {
            $modelFilter.on('change', function () {
                renderHistoryEntriesList(historyEntries);
            });
        }

        if ($rateFilter.length) {
            $rateFilter.on('change', function () {
                renderHistoryEntriesList(historyEntries);
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
