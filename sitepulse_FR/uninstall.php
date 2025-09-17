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
    'sitepulse_cpu_alert_threshold',
    'sitepulse_alert_cooldown_minutes',
];

$transients = [
    'sitepulse_speed_scan_results',
    'sitepulse_ai_insight',
    'sitepulse_error_alert_cpu_lock',
    'sitepulse_error_alert_php_fatal_lock',
];

$cron_hooks = require __DIR__ . '/includes/cron-hooks.php';
if (!is_array($cron_hooks)) {
    $cron_hooks = [];
}

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

$sitepulse_directories = [];

if (function_exists('wp_upload_dir')) {
    $sitepulse_upload_dir = wp_upload_dir();

    if (
        is_array($sitepulse_upload_dir)
        && empty($sitepulse_upload_dir['error'])
        && !empty($sitepulse_upload_dir['basedir'])
    ) {
        $sitepulse_directories[] = rtrim((string) $sitepulse_upload_dir['basedir'], '/\\') . '/sitepulse';
    }
}

$sitepulse_directories[] = rtrim(WP_CONTENT_DIR, '/\\') . '/sitepulse';
$sitepulse_directories = array_values(array_unique(array_filter($sitepulse_directories)));

foreach ($sitepulse_directories as $sitepulse_directory) {
    $log_files = glob(rtrim($sitepulse_directory, '/\\') . '/sitepulse-debug.log*');

    if (!empty($log_files)) {
        foreach ($log_files as $log_file) {
            if (is_file($log_file)) {
                @unlink($log_file);
            }
        }
    }

    if (is_dir($sitepulse_directory)) {
        $remaining_files = glob(rtrim($sitepulse_directory, '/\\') . '/*');

        if (empty($remaining_files)) {
            @rmdir($sitepulse_directory);
        }
    }
}

$legacy_log_files = glob(rtrim(WP_CONTENT_DIR, '/\\') . '/sitepulse-debug.log*');

if (!empty($legacy_log_files)) {
    foreach ($legacy_log_files as $legacy_log_file) {
        if (is_file($legacy_log_file)) {
            @unlink($legacy_log_file);
        }
    }
}
