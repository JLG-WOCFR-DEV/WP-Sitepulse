<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sitepulse_normalize_alert_message')) {
    /**
     * Normalizes alert messages stored in options to keep Site Health output tidy.
     *
     * @param mixed $message    Raw message value stored in the database.
     * @param int   $max_length Optional. Maximum length of the returned message. Default 200.
     * @return string Normalized message or an empty string when it cannot be sanitized.
     */
    function sitepulse_normalize_alert_message($message, $max_length = 200) {
        if (!is_scalar($message)) {
            return '';
        }

        $message = (string) $message;

        if ($message === '') {
            return '';
        }

        $message = preg_replace('#<(script|style)[^>]*>.*?</\\1>#is', ' ', $message);

        if (!is_string($message)) {
            $message = '';
        }

        if (function_exists('wp_strip_all_tags')) {
            $message = wp_strip_all_tags($message, true);
        } else {
            $message = strip_tags($message);
        }

        $message = trim(preg_replace('/\s+/u', ' ', $message));

        if ($message === '') {
            return '';
        }

        $max_length = (int) $max_length;

        if ($max_length > 0) {
            if (function_exists('mb_strlen')) {
                if (mb_strlen($message, 'UTF-8') > $max_length) {
                    $message = rtrim(mb_substr($message, 0, $max_length - 1, 'UTF-8')) . '…';
                }
            } elseif (strlen($message) > $max_length) {
                $message = rtrim(substr($message, 0, $max_length - 1)) . '…';
            }
        }

        return $message;
    }
}

if (!function_exists('sitepulse_get_site_health_alert_messages')) {
    /**
     * Retrieves SitePulse alerts stored in the WordPress options table.
     *
     * @param bool $force_refresh Optional. Skip the static runtime cache. Default false.
     * @return array{
     *     cron: string[],
     *     ai: string[],
     * }
     */
    function sitepulse_get_site_health_alert_messages($force_refresh = false) {
        static $cache = null;

        if (!$force_refresh && is_array($cache)) {
            return $cache;
        }

        $cron_messages = [];
        $ai_messages   = [];

        $cron_warnings_option = get_option(SITEPULSE_OPTION_CRON_WARNINGS, []);

        if (is_array($cron_warnings_option)) {
            foreach ($cron_warnings_option as $warning) {
                if (!is_array($warning) || !isset($warning['message'])) {
                    continue;
                }

                $normalized = sitepulse_normalize_alert_message($warning['message']);

                if ($normalized !== '') {
                    $cron_messages[] = $normalized;
                }
            }
        }

        $ai_option_name = defined('SITEPULSE_OPTION_AI_INSIGHT_ERRORS')
            ? SITEPULSE_OPTION_AI_INSIGHT_ERRORS
            : 'sitepulse_ai_insight_errors';

        $ai_errors_option = get_option($ai_option_name, []);

        if (is_array($ai_errors_option)) {
            foreach ($ai_errors_option as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $normalized = sitepulse_normalize_alert_message($error['message']);
                } else {
                    $normalized = sitepulse_normalize_alert_message($error);
                }

                if ($normalized !== '') {
                    $ai_messages[] = $normalized;
                }
            }
        } else {
            $normalized = sitepulse_normalize_alert_message($ai_errors_option);

            if ($normalized !== '') {
                $ai_messages[] = $normalized;
            }
        }

        $cron_messages = array_values(array_unique($cron_messages, SORT_STRING));
        $ai_messages   = array_values(array_unique($ai_messages, SORT_STRING));

        $cache = [
            'cron' => $cron_messages,
            'ai'   => $ai_messages,
        ];

        return $cache;
    }
}
