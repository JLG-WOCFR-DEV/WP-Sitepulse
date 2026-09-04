<?php
if (!defined('ABSPATH')) {
    exit;
}

$sitepulse_error_alerts_cron_hook = function_exists('sitepulse_get_cron_hook') ? sitepulse_get_cron_hook('error_alerts') : 'sitepulse_error_alerts_cron';
$sitepulse_error_alerts_schedule   = 'sitepulse_error_alerts_five_minutes';

require_once __DIR__ . '/error-alerts/config.php';
require_once __DIR__ . '/error-alerts/delivery.php';
require_once __DIR__ . '/error-alerts/cron.php';

if (!empty($sitepulse_error_alerts_cron_hook)) {
    add_filter('cron_schedules', 'sitepulse_error_alerts_register_cron_schedule');

    add_action('init', 'sitepulse_error_alerts_ensure_cron');

    add_action($sitepulse_error_alerts_cron_hook, 'sitepulse_error_alerts_run_checks');
    add_action('update_option_' . SITEPULSE_OPTION_ALERT_INTERVAL, 'sitepulse_error_alerts_on_interval_update', 10, 3);
}

/**
 * Records alert dispatches for the smart interval heuristics.
 *
 * @param array<string, mixed> $payload  Normalized payload data.
 * @param array<string, bool>  $results  Channel dispatch results.
 * @param string               $type     Alert type identifier.
 * @param string               $severity Alert severity.
 * @return void
 */
function sitepulse_error_alerts_record_activity($payload, $results, $type, $severity) {
    if (!function_exists('sitepulse_register_alert_activity_event')) {
        return;
    }

    $timestamp = isset($payload['timestamp']) ? strtotime((string) $payload['timestamp']) : 0;

    if ($timestamp <= 0) {
        $timestamp = time();
    }

    $channels = [];

    if (is_array($results)) {
        foreach ($results as $channel => $success) {
            if (!is_string($channel)) {
                continue;
            }

            if ($success) {
                $channels[] = $channel;
            }
        }
    }

    $event = [
        'timestamp' => $timestamp,
        'type'      => sanitize_key((string) $type),
        'severity'  => sitepulse_error_alert_normalize_severity($severity),
        'success'   => !empty($channels),
    ];

    if (!empty($channels)) {
        $event['channels'] = $channels;
    }

    if (isset($payload['fatal_count'])) {
        $event['meta']['fatal_count'] = (int) $payload['fatal_count'];
    }

    sitepulse_register_alert_activity_event($event);
}

add_action('sitepulse_error_alert_dispatched', 'sitepulse_error_alerts_record_activity', 15, 4);

require_once __DIR__ . '/error-alerts/admin.php';
add_action('admin_post_sitepulse_send_alert_test', 'sitepulse_error_alerts_handle_test_admin_post');

add_action('wp_ajax_sitepulse_send_alert_test', 'sitepulse_error_alerts_handle_ajax_test');

require_once __DIR__ . '/error-alerts/rest.php';
add_action('rest_api_init', 'sitepulse_error_alerts_register_rest_routes');

