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

    function reinstateLastResult($resultContainer, $resultText, $timestampEl, $statusEl, lastResultData) {
        if (lastResultData && lastResultData.text) {
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
        var lastResultData = null;
        var pollTimer = null;
        var activeJobId = null;

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

        lastResultData = renderResult($resultContainer, $resultText, $timestampEl, $statusEl, {
            text: sitepulseAIInsights.initialInsight,
            timestamp: sitepulseAIInsights.initialTimestamp,
            cached: !!sitepulseAIInsights.initialCached
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
                }
            });
        });
    });
})(jQuery);
