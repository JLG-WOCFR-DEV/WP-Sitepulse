<?php
/**
 * SitePulse Resource Monitor cron runner.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Processes the most recent snapshots recorded by the cron job and triggers alerts when needed.
 *
 * @return void
 */
function sitepulse_resource_monitor_run_cron() {
    if (!function_exists('sitepulse_is_module_active') || !sitepulse_is_module_active('resource_monitor')) {
        return;
    }

    $snapshot = sitepulse_resource_monitor_get_snapshot('cron');
    $required = sitepulse_resource_monitor_get_required_consecutive_snapshots();
    $fetch_count = max($required * 2, 50);

    $history_result = sitepulse_resource_monitor_get_history([
        'per_page' => $fetch_count,
        'page'     => 1,
        'order'    => 'DESC',
    ]);

    $history_entries = isset($history_result['entries']) && is_array($history_result['entries'])
        ? array_reverse($history_result['entries'])
        : [];
    $thresholds = sitepulse_resource_monitor_get_threshold_configuration();

    sitepulse_resource_monitor_check_thresholds($history_entries, $thresholds, $snapshot);
}
