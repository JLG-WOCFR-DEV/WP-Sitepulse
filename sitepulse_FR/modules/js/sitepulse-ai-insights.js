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
        } else {
            $statusEl.hide().text('');
        }
    }

    function renderResult($resultContainer, $textEl, $timestampEl, $statusEl, data) {
        var text = data && typeof data.text === 'string' ? data.text.trim() : '';
        var timestamp = data && data.timestamp ? data.timestamp : null;
        var isCached = data && typeof data.cached !== 'undefined' ? !!data.cached : false;
        var normalized = {
            text: text,
            timestamp: timestamp,
            cached: isCached
        };

        if (text.length === 0) {
            $resultContainer.hide();
            $textEl.text('');
            $timestampEl.hide().text('');
            setStatus($statusEl, '');
            return normalized;
        }

        $textEl.text(text);

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
    }

    function getHistoryLimit() {
        var limit = parseInt(sitepulseAIInsights.historyLimit, 10);

        if (!isFinite(limit) || limit <= 0) {
            limit = 7;
        }

        return limit;
    }

    function sanitizeHistoryEntry(entry) {
        if (!entry || typeof entry.text !== 'string') {
            return null;
        }

        var text = entry.text.trim();

        if (text === '') {
            return null;
        }

        var timestamp = null;

        if (typeof entry.timestamp !== 'undefined' && entry.timestamp !== null) {
            var parsed = parseInt(entry.timestamp, 10);

            if (isFinite(parsed) && parsed > 0) {
                timestamp = parsed;
            }
        }

        var source = typeof entry.source === 'string' ? entry.source : 'manual';

        if (source !== 'cron') {
            source = 'manual';
        }

        var cached = typeof entry.cached !== 'undefined' ? !!entry.cached : true;

        return {
            text: text,
            timestamp: timestamp,
            source: source,
            cached: cached
        };
    }

    function normalizeHistory(entries) {
        var normalized = [];
        var limit = getHistoryLimit();

        if (!Array.isArray(entries)) {
            return normalized;
        }

        for (var i = 0; i < entries.length; i++) {
            var sanitized = sanitizeHistoryEntry(entries[i]);

            if (sanitized) {
                normalized.push(sanitized);
            }

            if (normalized.length >= limit) {
                break;
            }
        }

        return normalized;
    }

    function getSourceLabel(source) {
        return source === 'cron'
            ? sitepulseAIInsights.strings.historyAuto
            : sitepulseAIInsights.strings.historyManual;
    }

    function buildHistoryOptionLabel(entry) {
        var formattedTimestamp = formatTimestamp(entry.timestamp);
        var parts = [];

        if (formattedTimestamp) {
            parts.push(formattedTimestamp);
        }

        parts.push(getSourceLabel(entry.source));

        return parts.join(' – ');
    }

    function populateHistorySelect($select, $label, history) {
        $label.text(sitepulseAIInsights.strings.historyLabel);
        $select.empty();

        if (!history.length) {
            $select.append($('<option></option>').text(sitepulseAIInsights.strings.historyEmpty));
            $select.prop('disabled', true);
            return;
        }

        $select.prop('disabled', false);

        for (var i = 0; i < history.length; i++) {
            var option = $('<option></option>')
                .val(String(i))
                .text(buildHistoryOptionLabel(history[i]));

            if (i === 0) {
                option.prop('selected', true);
            }

            $select.append(option);
        }
    }

    function updateLastAutoRefresh($element, timestamp) {
        var formatted = formatTimestamp(timestamp);

        if (formatted) {
            $element.text(sitepulseAIInsights.strings.lastAutoRefresh + ' ' + formatted).show();
        } else {
            $element.hide().text('');
        }
    }

    function findHistoryIndex(history, entry) {
        if (!entry) {
            return -1;
        }

        for (var i = 0; i < history.length; i++) {
            if (history[i].text === entry.text && history[i].timestamp === entry.timestamp) {
                return i;
            }
        }

        return -1;
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
        var $historySelect = $('#sitepulse-ai-history');
        var $historyLabel = $('.sitepulse-ai-history-label');
        var $lastAuto = $('#sitepulse-ai-last-auto');
        var history = normalizeHistory(sitepulseAIInsights.initialHistory);
        var limit = getHistoryLimit();
        var lastResultData = null;

        if (!history.length && typeof sitepulseAIInsights.initialInsight === 'string') {
            var seeded = sanitizeHistoryEntry({
                text: sitepulseAIInsights.initialInsight,
                timestamp: sitepulseAIInsights.initialTimestamp,
                source: sitepulseAIInsights.initialSource,
                cached: !!sitepulseAIInsights.initialCached
            });

            if (seeded) {
                history.push(seeded);
            }
        }

        if (history.length > limit) {
            history = history.slice(0, limit);
        }

        populateHistorySelect($historySelect, $historyLabel, history);
        updateLastAutoRefresh($lastAuto, sitepulseAIInsights.lastAutoRefresh);

        $errorContainer.hide();
        $spinner.removeClass('is-active');

        var initialEntry = history.length ? history[0] : sanitizeHistoryEntry({
            text: sitepulseAIInsights.initialInsight,
            timestamp: sitepulseAIInsights.initialTimestamp,
            cached: !!sitepulseAIInsights.initialCached
        });

        lastResultData = renderResult($resultContainer, $resultText, $timestampEl, $statusEl, initialEntry || {});

        $historySelect.on('change', function () {
            var value = parseInt($(this).val(), 10);

            if (!isFinite(value) || value < 0 || value >= history.length) {
                return;
            }

            var selectedEntry = history[value];
            var isCached = value === 0 ? !!selectedEntry.cached : true;

            lastResultData = renderResult($resultContainer, $resultText, $timestampEl, $statusEl, {
                text: selectedEntry.text,
                timestamp: selectedEntry.timestamp,
                cached: isCached
            });
        });

        $button.on('click', function (event) {
            event.preventDefault();

            if ($button.prop('disabled')) {
                return;
            }

            $errorContainer.hide();
            $errorText.text('');
            $spinner.addClass('is-active');
            $button.prop('disabled', true);
            $resultContainer.show();
            setStatus($statusEl, sitepulseAIInsights.strings.statusGenerating);

            var previousSelection = $historySelect.val();
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
                    var responseHistory = normalizeHistory(response.data.history);
                    var responseEntry = sanitizeHistoryEntry({
                        text: response.data.text,
                        timestamp: response.data.timestamp,
                        source: response.data.source,
                        cached: response.data.cached
                    });

                    if (!responseHistory.length && responseEntry) {
                        responseHistory.push(responseEntry);
                    } else if (responseHistory.length && responseEntry) {
                        responseHistory[0].text = responseEntry.text;
                        responseHistory[0].timestamp = responseEntry.timestamp;
                        responseHistory[0].source = responseEntry.source;
                        responseHistory[0].cached = !!response.data.cached;
                    }

                    history = responseHistory;
                    populateHistorySelect($historySelect, $historyLabel, history);

                    var selectedIndex = findHistoryIndex(history, responseEntry);

                    if (selectedIndex < 0) {
                        if (typeof previousSelection === 'string' && history.length) {
                            var previousIndex = parseInt(previousSelection, 10);

                            if (isFinite(previousIndex) && previousIndex >= 0 && previousIndex < history.length) {
                                selectedIndex = previousIndex;
                            } else {
                                selectedIndex = 0;
                            }
                        } else {
                            selectedIndex = history.length ? 0 : -1;
                        }
                    }

                    if (selectedIndex >= 0) {
                        $historySelect.val(String(selectedIndex));
                    }

                    updateLastAutoRefresh($lastAuto, response.data.lastAutoRefresh);

                    var displayEntry = selectedIndex >= 0 ? history[selectedIndex] : responseEntry;

                    lastResultData = renderResult($resultContainer, $resultText, $timestampEl, $statusEl, {
                        text: displayEntry ? displayEntry.text : '',
                        timestamp: displayEntry ? displayEntry.timestamp : null,
                        cached: displayEntry ? !!displayEntry.cached : !!response.data.cached
                    });

                    if (history.length && selectedIndex >= 0) {
                        history[selectedIndex].cached = lastResultData.cached;
                    }
                } else if (response && response.data && response.data.message) {
                    showError($errorContainer, $errorText, response.data.message);

                    if (lastResultData && lastResultData.text) {
                        setStatus(
                            $statusEl,
                            lastResultData.cached ? sitepulseAIInsights.strings.statusCached : sitepulseAIInsights.strings.statusFresh
                        );
                    } else {
                        setStatus($statusEl, '');
                        $resultContainer.hide();
                    }
                } else {
                    showError($errorContainer, $errorText);

                    if (lastResultData && lastResultData.text) {
                        setStatus(
                            $statusEl,
                            lastResultData.cached ? sitepulseAIInsights.strings.statusCached : sitepulseAIInsights.strings.statusFresh
                        );
                    } else {
                        setStatus($statusEl, '');
                        $resultContainer.hide();
                    }
                }
            }).fail(function (xhr) {
                var message = sitepulseAIInsights.strings.defaultError;

                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }

                showError($errorContainer, $errorText, message);

                if (lastResultData && lastResultData.text) {
                    setStatus(
                        $statusEl,
                        lastResultData.cached ? sitepulseAIInsights.strings.statusCached : sitepulseAIInsights.strings.statusFresh
                    );
                } else {
                    setStatus($statusEl, '');
                    $resultContainer.hide();
                }
            }).always(function () {
                $spinner.removeClass('is-active');
                $button.prop('disabled', false);
            });
        });
    });
})(jQuery);
