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

    function renderResult($resultContainer, $textEl, $timestampEl, data) {
        var text = data && typeof data.text === 'string' ? data.text.trim() : '';
        var timestamp = data && data.timestamp ? data.timestamp : null;

        if (text.length === 0) {
            $resultContainer.hide();
            $textEl.text('');
            $timestampEl.hide().text('');
            return;
        }

        $textEl.text(text);

        var formattedTimestamp = formatTimestamp(timestamp);

        if (formattedTimestamp) {
            $timestampEl.text(sitepulseAIInsights.strings.cachedPrefix + ' ' + formattedTimestamp).show();
        } else {
            $timestampEl.hide().text('');
        }

        $resultContainer.show();
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
        var $resultText = $resultContainer.find('.sitepulse-ai-insight-text');
        var $timestampEl = $resultContainer.find('.sitepulse-ai-insight-timestamp');

        $errorContainer.hide();
        $spinner.removeClass('is-active');

        renderResult($resultContainer, $resultText, $timestampEl, {
            text: sitepulseAIInsights.initialInsight,
            timestamp: sitepulseAIInsights.initialTimestamp
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

            $.post(sitepulseAIInsights.ajaxUrl, {
                action: 'sitepulse_generate_ai_insight',
                nonce: sitepulseAIInsights.nonce
            }).done(function (response) {
                if (response && response.success && response.data) {
                    renderResult($resultContainer, $resultText, $timestampEl, response.data);
                } else if (response && response.data && response.data.message) {
                    showError($errorContainer, $errorText, response.data.message);
                } else {
                    showError($errorContainer, $errorText);
                }
            }).fail(function (xhr) {
                var message = sitepulseAIInsights.strings.defaultError;

                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }

                showError($errorContainer, $errorText, message);
            }).always(function () {
                $spinner.removeClass('is-active');
                $button.prop('disabled', false);
            });
        });
    });
})(jQuery);
