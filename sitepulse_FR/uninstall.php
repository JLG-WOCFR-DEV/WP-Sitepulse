<?php
/**
 * Uninstall routines for SitePulse.
 *
 * Fired when the plugin is deleted from the WordPress dashboard.
 *
 * @package SitePulse
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    'sitepulse_active_modules',
    'sitepulse_debug_mode',
    'sitepulse_gemini_api_key',
    'sitepulse_uptime_log',
    'sitepulse_last_load_time',
];

$transients = [
    'sitepulse_speed_scan_results',
    'sitepulse_ai_insight',
];

$cron_hooks = [
    'sitepulse_uptime_tracker_cron',
    'sitepulse_resource_monitor_cron',
    'sitepulse_log_analyzer_cron',
];

/**
 * Removes plugin data for a single site.
 *
 * @param array $options   List of option names to delete.
 * @param array $transients List of transient names to delete.
 * @param array $cron_hooks List of cron hooks to clear.
 *
 * @return void
 */
function sitepulse_uninstall_cleanup_blog($options, $transients, $cron_hooks) {
    foreach ($options as $option) {
        delete_option($option);
    }

    foreach ($transients as $transient) {
        delete_transient($transient);
    }

    foreach ($cron_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

if (is_multisite()) {
    global $wpdb;

    $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");

    foreach ($blog_ids as $blog_id) {
        if (switch_to_blog((int) $blog_id)) {
            sitepulse_uninstall_cleanup_blog($options, $transients, $cron_hooks);
            restore_current_blog();
        }
    }
} else {
    sitepulse_uninstall_cleanup_blog($options, $transients, $cron_hooks);
}

if (is_multisite()) {
    foreach ($options as $option) {
        delete_site_option($option);
    }

    foreach ($transients as $transient) {
        if (function_exists('delete_site_transient')) {
            delete_site_transient($transient);
        }
    }
}

$log_files = glob(WP_CONTENT_DIR . '/sitepulse-debug.log*');
if (!empty($log_files)) {
    foreach ($log_files as $log_file) {
        if (is_file($log_file)) {
            @unlink($log_file);
        }
    }
}
