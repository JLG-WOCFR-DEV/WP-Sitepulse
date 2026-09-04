<?php
/**
 * SitePulse AI Insights admin alerts.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Records a critical AI Insights error for logging and admin notice purposes.
 *
 * @param string   $message     Error message.
 * @param int|null $status_code Optional HTTP status code or contextual code.
 *
 * @return void
 */
function sitepulse_ai_queue_admin_alert_notice($message, $type = 'warning') {
    $message = wp_strip_all_tags((string) $message);

    if ('' === $message) {
        return;
    }

    $type = sanitize_key((string) $type);
    $allowed_types = ['error', 'warning', 'success', 'info'];

    if (!in_array($type, $allowed_types, true)) {
        $type = 'warning';
    }

    $entry = [
        'message'   => $message,
        'type'      => $type,
        'timestamp' => time(),
    ];

    if (!isset($GLOBALS['sitepulse_ai_alert_notices']) || !is_array($GLOBALS['sitepulse_ai_alert_notices'])) {
        $GLOBALS['sitepulse_ai_alert_notices'] = [];
    }

    $GLOBALS['sitepulse_ai_alert_notices'][] = $entry;

    if (function_exists('get_option') && function_exists('update_option')) {
        $stored = get_option(SITEPULSE_OPTION_AI_ALERT_NOTICES, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $stored[] = $entry;

        if (count($stored) > 10) {
            $stored = array_slice($stored, -10);
        }

        update_option(SITEPULSE_OPTION_AI_ALERT_NOTICES, $stored, false);
    }
}

/**
 * Renders queued alert notices in the admin area.
 *
 * @return void
 */
function sitepulse_ai_render_alert_notices() {
    if (!function_exists('current_user_can') || !current_user_can(sitepulse_get_capability())) {
        return;
    }

    $notices = [];

    if (isset($GLOBALS['sitepulse_ai_alert_notices']) && is_array($GLOBALS['sitepulse_ai_alert_notices'])) {
        $notices = array_merge($notices, $GLOBALS['sitepulse_ai_alert_notices']);
        $GLOBALS['sitepulse_ai_alert_notices'] = [];
    }

    if (function_exists('get_option')) {
        $stored = get_option(SITEPULSE_OPTION_AI_ALERT_NOTICES, []);

        if (is_array($stored)) {
            $notices = array_merge($notices, $stored);
        }

        if (!empty($stored) && function_exists('delete_option')) {
            delete_option(SITEPULSE_OPTION_AI_ALERT_NOTICES);
        }
    }

    if (empty($notices)) {
        return;
    }

    $seen_messages = [];

    foreach ($notices as $notice) {
        if (!is_array($notice) || !isset($notice['message'])) {
            continue;
        }

        $message = trim((string) $notice['message']);

        if ('' === $message || isset($seen_messages[$message])) {
            continue;
        }

        $seen_messages[$message] = true;

        $type  = isset($notice['type']) ? sanitize_key((string) $notice['type']) : 'warning';
        $class = 'notice notice-warning';

        switch ($type) {
            case 'error':
                $class = 'notice notice-error';
                break;
            case 'success':
                $class = 'notice notice-success';
                break;
            case 'info':
                $class = 'notice notice-info';
                break;
        }

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
    }
}

function sitepulse_ai_record_critical_error($message, $status_code = null) {
    $normalized_message = trim(wp_strip_all_tags((string) $message));

    if ('' === $normalized_message) {
        return;
    }

    if ($status_code !== null) {
        $normalized_message = sprintf(
            /* translators: 1: Status or error code, 2: error details. */
            esc_html__('Code %1$d — %2$s', 'sitepulse'),
            (int) $status_code,
            $normalized_message
        );
    }

    $log_message = 'AI Insights: ' . $normalized_message;

    if (function_exists('sitepulse_log')) {
        sitepulse_log($log_message, 'ERROR');
    }

    if (function_exists('error_log')) {
        error_log('SitePulse ' . $log_message);
    }

    static $recorded = [];

    if (isset($recorded[$normalized_message])) {
        return;
    }

    $recorded[$normalized_message] = true;

    if (!isset($GLOBALS['sitepulse_ai_runtime_notices']) || !is_array($GLOBALS['sitepulse_ai_runtime_notices'])) {
        $GLOBALS['sitepulse_ai_runtime_notices'] = [];
    }

    $GLOBALS['sitepulse_ai_runtime_notices'][] = $normalized_message;

    if (function_exists('get_option') && function_exists('update_option')) {
        $stored = get_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $stored[] = [
            'message'   => $normalized_message,
            'timestamp' => time(),
        ];

        if (count($stored) > 10) {
            $stored = array_slice($stored, -10, 10, true);
        }

        update_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS, $stored, false);
    }
}

/**
 * Renders stored AI Insights error notices in the admin area.
 *
 * @return void
 */
function sitepulse_ai_render_error_notices() {
    if (!function_exists('current_user_can') || !function_exists('esc_html')) {
        return;
    }

    if (!current_user_can(sitepulse_get_capability())) {
        return;
    }

    $messages = [];

    if (isset($GLOBALS['sitepulse_ai_runtime_notices']) && is_array($GLOBALS['sitepulse_ai_runtime_notices'])) {
        $messages = array_merge($messages, $GLOBALS['sitepulse_ai_runtime_notices']);
    }

    if (function_exists('get_option')) {
        $stored = get_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS, []);

        if (is_array($stored)) {
            foreach ($stored as $entry) {
                if (!is_array($entry) || !isset($entry['message'])) {
                    continue;
                }

                $messages[] = (string) $entry['message'];
            }
        }

        if (function_exists('delete_option') && !empty($stored)) {
            delete_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS);
        }
    }

    $messages = array_values(array_unique(array_filter(array_map('trim', $messages))));

    if (empty($messages)) {
        return;
    }

    foreach ($messages as $message) {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    }
}
