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

$sitepulse_constants = [
    'SITEPULSE_OPTION_ACTIVE_MODULES'             => 'sitepulse_active_modules',
    'SITEPULSE_OPTION_DEBUG_MODE'                 => 'sitepulse_debug_mode',
    'SITEPULSE_OPTION_GEMINI_API_KEY'             => 'sitepulse_gemini_api_key',
    'SITEPULSE_OPTION_UPTIME_LOG'                 => 'sitepulse_uptime_log',
    'SITEPULSE_OPTION_LAST_LOAD_TIME'             => 'sitepulse_last_load_time',
    'SITEPULSE_OPTION_CPU_ALERT_THRESHOLD'        => 'sitepulse_cpu_alert_threshold',
    'SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES'     => 'sitepulse_alert_cooldown_minutes',
    'SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS'      => 'sitepulse_speed_scan_results',
    'SITEPULSE_TRANSIENT_AI_INSIGHT'              => 'sitepulse_ai_insight',
    'SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX' => 'sitepulse_error_alert_',
    'SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX' => '_lock',
];

foreach ($sitepulse_constants as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

if (!defined('SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK')) {
    define('SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK', SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . 'cpu' . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX);
}

if (!defined('SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK')) {
    define('SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK', SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . 'php_fatal' . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX);
}

$options = [
    SITEPULSE_OPTION_ACTIVE_MODULES,
    SITEPULSE_OPTION_DEBUG_MODE,
    SITEPULSE_OPTION_GEMINI_API_KEY,
    SITEPULSE_OPTION_UPTIME_LOG,
    SITEPULSE_OPTION_LAST_LOAD_TIME,
    SITEPULSE_OPTION_CPU_ALERT_THRESHOLD,
    SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES,
    defined('SITEPULSE_PLUGIN_IMPACT_OPTION') ? SITEPULSE_PLUGIN_IMPACT_OPTION : 'sitepulse_plugin_impact_stats',
];

$transients = [
    SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS,
    SITEPULSE_TRANSIENT_AI_INSIGHT,
    SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK,
    SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK,
];

$transient_prefixes = [
    defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')
        ? SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX
        : 'sitepulse_plugin_dir_size_',
];

$transient_prefixes = array_values(array_unique(array_filter($transient_prefixes, 'strlen')));

if (!function_exists('sitepulse_delete_transients_with_prefix')) {
    /**
     * Removes all transients matching a specific prefix.
     *
     * @param string $prefix Transient key prefix.
     * @return void
     */
    function sitepulse_delete_transients_with_prefix($prefix) {
        global $wpdb;

        if (!is_string($prefix) || $prefix === '' || !($wpdb instanceof wpdb)) {
            return;
        }

        $like = $wpdb->esc_like($prefix) . '%';
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . $like
            )
        );

        if (empty($option_names)) {
            return;
        }

        $transient_prefix_length = strlen('_transient_');

        foreach ($option_names as $option_name) {
            $transient_key = substr($option_name, $transient_prefix_length);

            if ($transient_key !== '') {
                delete_transient($transient_key);
            }
        }
    }
}

if (!function_exists('sitepulse_delete_site_transients_with_prefix')) {
    /**
     * Removes all site transients matching a specific prefix.
     *
     * @param string $prefix Site transient key prefix.
     * @return void
     */
    function sitepulse_delete_site_transients_with_prefix($prefix) {
        if (!is_multisite() || !function_exists('delete_site_transient')) {
            return;
        }

        global $wpdb;

        if (!is_string($prefix) || $prefix === '' || !($wpdb instanceof wpdb)) {
            return;
        }

        $like = $wpdb->esc_like($prefix) . '%';
        $meta_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                '_site_transient_' . $like
            )
        );

        if (empty($meta_keys)) {
            return;
        }

        $site_transient_prefix_length = strlen('_site_transient_');

        foreach ($meta_keys as $meta_key) {
            $transient_key = substr($meta_key, $site_transient_prefix_length);

            if ($transient_key !== '') {
                delete_site_transient($transient_key);
            }
        }
    }
}

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
 * @param array $transient_prefixes List of transient prefixes to delete.
 *
 * @return void
 */
function sitepulse_uninstall_cleanup_blog($options, $transients, $cron_hooks, $transient_prefixes) {
    foreach ($options as $option) {
        delete_option($option);
    }

    foreach ($transients as $transient) {
        delete_transient($transient);
    }

    foreach ($transient_prefixes as $transient_prefix) {
        sitepulse_delete_transients_with_prefix($transient_prefix);
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
            sitepulse_uninstall_cleanup_blog($options, $transients, $cron_hooks, $transient_prefixes);
            restore_current_blog();
        }
    }
} else {
    sitepulse_uninstall_cleanup_blog($options, $transients, $cron_hooks, $transient_prefixes);
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

    foreach ($transient_prefixes as $transient_prefix) {
        sitepulse_delete_site_transients_with_prefix($transient_prefix);
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
        $sitepulse_directory_trimmed = rtrim($sitepulse_directory, '/\\');
        $remaining_files = glob($sitepulse_directory_trimmed . '/*');

        if (!empty($remaining_files)) {
            foreach (['.htaccess', 'web.config'] as $protection_file) {
                $protection_file_path = $sitepulse_directory_trimmed . '/' . $protection_file;

                if (is_file($protection_file_path)) {
                    @unlink($protection_file_path);
                }
            }

            $remaining_files = glob($sitepulse_directory_trimmed . '/*');
        }

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
