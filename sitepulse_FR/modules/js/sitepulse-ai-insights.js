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
        var lastResultData = null;

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

            $.post(sitepulseAIInsights.ajaxUrl, {
                action: 'sitepulse_generate_ai_insight',
                nonce: sitepulseAIInsights.nonce,
                force_refresh: true
            }).done(function (response) {
                if (response && response.success && response.data) {
                    lastResultData = renderResult($resultContainer, $resultText, $timestampEl, $statusEl, response.data);
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
