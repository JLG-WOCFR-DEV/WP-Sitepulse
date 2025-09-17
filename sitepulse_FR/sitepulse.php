<?php
/**
 * Plugin Name: Sitepulse - JLG
 * Plugin URI: https://your-site.com/sitepulse
 * Description: Monitors website pulse: speed, database, maintenance, server, errors.
 * Version: 1.0
 * Author: Jérôme Le Gousse
 * License: GPL-2.0+
 * Uninstall: uninstall.php
 * Text Domain: sitepulse
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

// Define constants
define('SITEPULSE_VERSION', '1.0');
define('SITEPULSE_PATH', plugin_dir_path(__FILE__));
define('SITEPULSE_URL', plugin_dir_url(__FILE__));

define('SITEPULSE_OPTION_ACTIVE_MODULES', 'sitepulse_active_modules');
define('SITEPULSE_OPTION_DEBUG_MODE', 'sitepulse_debug_mode');
define('SITEPULSE_OPTION_GEMINI_API_KEY', 'sitepulse_gemini_api_key');
define('SITEPULSE_OPTION_UPTIME_LOG', 'sitepulse_uptime_log');
define('SITEPULSE_OPTION_LAST_LOAD_TIME', 'sitepulse_last_load_time');
define('SITEPULSE_OPTION_CPU_ALERT_THRESHOLD', 'sitepulse_cpu_alert_threshold');
define('SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES', 'sitepulse_alert_cooldown_minutes');

define('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS', 'sitepulse_speed_scan_results');
define('SITEPULSE_TRANSIENT_AI_INSIGHT', 'sitepulse_ai_insight');
define('SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX', 'sitepulse_error_alert_');
define('SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX', '_lock');
define('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX', 'sitepulse_plugin_dir_size_');
define('SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK', SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . 'cpu' . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX);
define('SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK', SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . 'php_fatal' . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX);

$debug_mode = get_option(SITEPULSE_OPTION_DEBUG_MODE, false);
define('SITEPULSE_DEBUG', (bool) $debug_mode);

add_action('plugins_loaded', 'sitepulse_load_textdomain');

function sitepulse_load_textdomain() {
    load_plugin_textdomain('sitepulse', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

if (!function_exists('wp_mkdir_p')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

$sitepulse_upload_dir    = wp_upload_dir();
$sitepulse_debug_basedir = WP_CONTENT_DIR;

if (is_array($sitepulse_upload_dir) && empty($sitepulse_upload_dir['error']) && !empty($sitepulse_upload_dir['basedir'])) {
    $sitepulse_debug_basedir = $sitepulse_upload_dir['basedir'];
}

$sitepulse_debug_directory = rtrim($sitepulse_debug_basedir, '/\\') . '/sitepulse';

if (function_exists('wp_mkdir_p') && !is_dir($sitepulse_debug_directory)) {
    wp_mkdir_p($sitepulse_debug_directory);
}

define('SITEPULSE_DEBUG_LOG', rtrim($sitepulse_debug_directory, '/\\') . '/sitepulse-debug.log');

define('SITEPULSE_PLUGIN_IMPACT_OPTION', 'sitepulse_plugin_impact_stats');
define('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL', 15 * MINUTE_IN_SECONDS);

$sitepulse_plugin_impact_tracker_last_tick = microtime(true);
$sitepulse_plugin_impact_tracker_samples = [];
$sitepulse_plugin_impact_tracker_force_persist = false;

add_action('plugin_loaded', 'sitepulse_plugin_impact_tracker_on_plugin_loaded', PHP_INT_MAX, 1);
add_action('shutdown', 'sitepulse_plugin_impact_tracker_persist', PHP_INT_MAX);

/**
 * Records the elapsed time between plugin loading operations.
 *
 * The measurement is taken when the {@see 'plugin_loaded'} action fires for
 * each plugin. The recorded value represents the time elapsed since the last
 * plugin finished loading, which approximates the cost of loading the current
 * plugin.
 *
 * @param string $plugin_file Relative path to the plugin file.
 *
 * @return void
 */
function sitepulse_plugin_impact_tracker_on_plugin_loaded($plugin_file) {
    global $sitepulse_plugin_impact_tracker_last_tick, $sitepulse_plugin_impact_tracker_samples;

    if (!is_string($plugin_file) || $plugin_file === '') {
        return;
    }

    $now = microtime(true);

    if (!is_float($sitepulse_plugin_impact_tracker_last_tick)) {
        $sitepulse_plugin_impact_tracker_last_tick = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : $now;
    }

    $elapsed = max(0.0, $now - $sitepulse_plugin_impact_tracker_last_tick);
    $sitepulse_plugin_impact_tracker_samples[$plugin_file] = $elapsed;
    $sitepulse_plugin_impact_tracker_last_tick = $now;
}

/**
 * Persists the collected plugin load measurements when appropriate.
 *
 * Data is stored at most once per {@see SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL}
 * unless a manual refresh is requested. A moving average is kept for each
 * plugin so that transient spikes have less impact on the reported duration.
 *
 * @return void
 */
function sitepulse_plugin_impact_tracker_persist() {
    global $sitepulse_plugin_impact_tracker_samples, $sitepulse_plugin_impact_tracker_force_persist;

    if (empty($sitepulse_plugin_impact_tracker_samples) || !is_array($sitepulse_plugin_impact_tracker_samples)) {
        return;
    }

    $option_key = SITEPULSE_PLUGIN_IMPACT_OPTION;
    $existing = get_option($option_key, []);
    $now = current_time('timestamp');

    if (!is_array($existing)) {
        $existing = [];
    }

    $interval = apply_filters('sitepulse_plugin_impact_refresh_interval', SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL);
    $last_updated = isset($existing['last_updated']) ? (int) $existing['last_updated'] : 0;

    if (!$sitepulse_plugin_impact_tracker_force_persist && ($now - $last_updated) < $interval) {
        return;
    }

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = function_exists('get_plugins') ? get_plugins() : [];
    $samples = isset($existing['samples']) && is_array($existing['samples']) ? $existing['samples'] : [];

    foreach ($sitepulse_plugin_impact_tracker_samples as $plugin_file => $duration) {
        if (!is_string($plugin_file) || $plugin_file === '') {
            continue;
        }

        $plugin_file = (string) $plugin_file;
        $milliseconds = max(0.0, (float) $duration * 1000);
        $plugin_name = isset($all_plugins[$plugin_file]['Name']) ? $all_plugins[$plugin_file]['Name'] : $plugin_file;

        if (isset($samples[$plugin_file]) && is_array($samples[$plugin_file])) {
            $count = isset($samples[$plugin_file]['samples']) ? max(1, (int) $samples[$plugin_file]['samples']) : 1;
            $average = isset($samples[$plugin_file]['avg_ms']) ? (float) $samples[$plugin_file]['avg_ms'] : $milliseconds;
            $new_count = $count + 1;
            $samples[$plugin_file]['avg_ms'] = ($average * $count + $milliseconds) / $new_count;
            $samples[$plugin_file]['samples'] = $new_count;
            $samples[$plugin_file]['last_ms'] = $milliseconds;
            $samples[$plugin_file]['name'] = $plugin_name;
            $samples[$plugin_file]['last_recorded'] = $now;
        } else {
            $samples[$plugin_file] = [
                'file'          => $plugin_file,
                'name'          => $plugin_name,
                'avg_ms'        => $milliseconds,
                'last_ms'       => $milliseconds,
                'samples'       => 1,
                'last_recorded' => $now,
            ];
        }
    }

    $active_plugins_option = get_option('active_plugins');
    $active_plugins = is_array($active_plugins_option) ? array_map('strval', $active_plugins_option) : [];
    $network_plugins_option = is_multisite() ? get_site_option('active_sitewide_plugins') : false;
    $active_sitewide_plugins = is_array($network_plugins_option) ? array_map('strval', array_keys($network_plugins_option)) : [];
    $all_active_plugins = array_unique(array_merge($active_plugins, $active_sitewide_plugins));

    if (is_array($active_plugins_option) || is_array($network_plugins_option)) {
        $normalized_samples = [];

        foreach ($samples as $plugin_file => $data) {
            $normalized_plugin_file = is_string($plugin_file) ? $plugin_file : (string) $plugin_file;

            if (!in_array($normalized_plugin_file, $all_active_plugins, true)) {
                continue;
            }

            if (!is_array($data)) {
                $data = [];
            }

            $data['file'] = isset($data['file']) ? (string) $data['file'] : $normalized_plugin_file;
            $normalized_samples[$normalized_plugin_file] = $data;
        }

        $samples = $normalized_samples;
    }

    $payload = [
        'last_updated' => $now,
        'interval'     => $interval,
        'samples'      => $samples,
    ];

    update_option($option_key, $payload, false);
}

/**
 * Forces the persistence of fresh measurements at the end of the current request.
 *
 * When $reset_existing is true the stored aggregate is cleared so that the next
 * persistence cycle starts with a clean slate.
 *
 * @param bool $reset_existing Whether to remove previous measurements.
 *
 * @return void
 */
function sitepulse_plugin_impact_force_next_persist($reset_existing = false) {
    global $sitepulse_plugin_impact_tracker_force_persist;

    $sitepulse_plugin_impact_tracker_force_persist = true;

    if ($reset_existing) {
        delete_option(SITEPULSE_PLUGIN_IMPACT_OPTION);
    }
}

/**
 * Returns the list of cron hook identifiers used across SitePulse modules.
 *
 * @return array<string, string> Associative array of module keys to cron hook names.
 */
function sitepulse_get_cron_hooks() {
    static $cron_hooks = null;

    if ($cron_hooks === null) {
        $cron_hooks = require SITEPULSE_PATH . 'includes/cron-hooks.php';

        if (!is_array($cron_hooks)) {
            $cron_hooks = [];
        }
    }

    return $cron_hooks;
}

/**
 * Retrieves the cron hook name for a specific module.
 *
 * @param string $module_key Identifier of the module (e.g. uptime_tracker).
 *
 * @return string|null The cron hook name or null if none exists.
 */
function sitepulse_get_cron_hook($module_key) {
    $cron_hooks = sitepulse_get_cron_hooks();

    return isset($cron_hooks[$module_key]) ? $cron_hooks[$module_key] : null;
}

/**
 * Handles module activation option changes by removing orphaned cron events.
 *
 * The {@see 'update_option_sitepulse_active_modules'} action provides both the
 * old and new module lists. By comparing them we can detect which modules were
 * deactivated and clean up any scheduled events tied to those modules.
 *
 * @param mixed       $old_value Previous option value.
 * @param mixed       $value     New option value.
 * @param string|null $option    Option name (unused).
 *
 * @return void
 */
function sitepulse_handle_module_changes($old_value, $value, $option = null) {
    $old_modules = is_array($old_value) ? array_values(array_unique(array_map('strval', $old_value))) : [];
    $new_modules = is_array($value) ? array_values(array_unique(array_map('strval', $value))) : [];

    if (empty($old_modules)) {
        return;
    }

    $removed_modules = array_diff($old_modules, $new_modules);

    foreach ($removed_modules as $module) {
        $hook = sitepulse_get_cron_hook($module);

        if (is_string($hook) && $hook !== '') {
            wp_clear_scheduled_hook($hook);
        }
    }
}

add_action('update_option_' . SITEPULSE_OPTION_ACTIVE_MODULES, 'sitepulse_handle_module_changes', 10, 3);

/**
 * Schedules an admin notice to report SitePulse debug errors.
 *
 * @param string $message The notice body.
 * @param string $type    The notice type (error, warning, info, success).
 *
 * @return void
 */
function sitepulse_schedule_debug_admin_notice($message, $type = 'error') {
    if (!SITEPULSE_DEBUG || !function_exists('add_action') || !function_exists('is_admin') || !is_admin()) {
        return;
    }

    static $displayed_messages = [];

    if (isset($displayed_messages[$message])) {
        return;
    }

    $displayed_messages[$message] = true;

    $allowed_types = ['error', 'warning', 'info', 'success'];
    $type          = in_array($type, $allowed_types, true) ? $type : 'error';
    $class         = 'notice notice-' . $type;

    add_action('admin_notices', function () use ($message, $class) {
        if (!function_exists('esc_attr') || !function_exists('esc_html')) {
            return;
        }

        printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($message));
    });
}

/**
 * Logging function for debugging purposes.
 *
 * Writes SitePulse debug entries to a dedicated log file when debug mode is enabled.
 *
 * Failure cases:
 * - Returns without writing when the log directory does not exist or lacks write permissions.
 * - Emits a PHP error log entry and schedules an admin notice when rotation or file writes fail.
 *
 * @param string $message The message to log.
 * @param string $level   The log level (e.g., INFO, WARNING, ERROR).
 */
function sitepulse_log($message, $level = 'INFO') {
    if (!SITEPULSE_DEBUG) {
        return;
    }

    $log_dir = dirname(SITEPULSE_DEBUG_LOG);

    if (!is_dir($log_dir)) {
        $error_message = sprintf('SitePulse: debug log directory does not exist (%s).', $log_dir);
        error_log($error_message);
        sitepulse_schedule_debug_admin_notice($error_message);

        return;
    }

    if (!is_writable($log_dir)) {
        $error_message = sprintf('SitePulse: debug log directory is not writable (%s).', $log_dir);
        error_log($error_message);
        sitepulse_schedule_debug_admin_notice($error_message);

        return;
    }

    static $sitepulse_log_protection_initialized = false;

    if (!$sitepulse_log_protection_initialized) {
        $sitepulse_log_protection_initialized = true;
        $normalized_log_dir = rtrim($log_dir, '/\\');
        $protection_targets = [
            $normalized_log_dir . '/.htaccess' => "# Protect SitePulse debug logs\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
            $normalized_log_dir . '/web.config'  => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n    <system.webServer>\n        <security>\n            <authorization>\n                <deny users=\"*\" />\n            </authorization>\n        </security>\n    </system.webServer>\n</configuration>\n",
        ];

        foreach ($protection_targets as $path => $contents) {
            if (file_exists($path)) {
                continue;
            }

            $written = file_put_contents($path, $contents, LOCK_EX);

            if ($written === false) {
                $error_message = sprintf('SitePulse: unable to write protection file (%s).', $path);
                error_log($error_message);
                sitepulse_schedule_debug_admin_notice($error_message);
            }
        }
    }

    $timestamp  = date('Y-m-d H:i:s');
    $log_entry  = "[$timestamp] [$level] $message\n";
    $max_size   = 5 * 1024 * 1024; // 5 MB

    if (file_exists(SITEPULSE_DEBUG_LOG)) {
        if (!is_writable(SITEPULSE_DEBUG_LOG)) {
            $error_message = sprintf('SitePulse: debug log file is not writable (%s).', SITEPULSE_DEBUG_LOG);
            error_log($error_message);
            sitepulse_schedule_debug_admin_notice($error_message);

            return;
        }

        if (filesize(SITEPULSE_DEBUG_LOG) > $max_size) {
            $archive = SITEPULSE_DEBUG_LOG . '.' . time();
            if (!rename(SITEPULSE_DEBUG_LOG, $archive)) {
                $error_message = sprintf('SitePulse: unable to rotate debug log file (%s).', SITEPULSE_DEBUG_LOG);
                error_log($error_message);
                sitepulse_schedule_debug_admin_notice($error_message);
            }
        }
    }

    $result = file_put_contents(SITEPULSE_DEBUG_LOG, $log_entry, FILE_APPEND | LOCK_EX);

    if ($result === false) {
        $error_message = sprintf('SitePulse: unable to write to debug log file (%s).', SITEPULSE_DEBUG_LOG);
        error_log($error_message);
        sitepulse_schedule_debug_admin_notice($error_message);
    }
}
sitepulse_log('SitePulse loaded. Version: ' . SITEPULSE_VERSION);

// Include core files
require_once SITEPULSE_PATH . 'includes/admin-settings.php';
require_once SITEPULSE_PATH . 'includes/integrations.php';

/**
 * Loads all active modules selected in the settings.
 */
function sitepulse_load_modules() {
    $modules = [
        'log_analyzer'          => 'Log Analyzer',
        'resource_monitor'      => 'Resource Monitor',
        'plugin_impact_scanner' => 'Plugin Impact Scanner',
        'speed_analyzer'        => 'Speed Analyzer',
        'database_optimizer'    => 'Database Optimizer',
        'maintenance_advisor'   => 'Maintenance Advisor',
        'uptime_tracker'        => 'Uptime Tracker',
        'ai_insights'           => 'AI-Powered Insights',
        'custom_dashboards'     => 'Custom Dashboards',
        'error_alerts'          => 'Error Alerts',
    ];
    
    $active_modules = get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);
    sitepulse_log('Loading active modules: ' . implode(', ', $active_modules));
    
    foreach ($active_modules as $module_key) {
        if (!array_key_exists($module_key, $modules)) {
            sitepulse_log("Module $module_key not found or invalid", 'WARNING');
            continue;
        }

        $module_path = SITEPULSE_PATH . 'modules/' . $module_key . '.php';

        if (!is_readable($module_path)) {
            sitepulse_log("Module file for $module_key is not readable: $module_path", 'ERROR');
            continue;
        }

        $include_result = include_once $module_path;

        if ($include_result === false) {
            sitepulse_log("Failed to load module $module_key from $module_path", 'ERROR');
        }
    }
}
add_action('plugins_loaded', 'sitepulse_load_modules');

/**
 * Activation hook. Sets default options.
 */
register_activation_hook(__FILE__, function() {
    // **FIX:** Activate the dashboard by default to prevent fatal errors on first load.
    add_option(SITEPULSE_OPTION_ACTIVE_MODULES, ['custom_dashboards']);
    add_option(SITEPULSE_OPTION_DEBUG_MODE, false);
    add_option(SITEPULSE_OPTION_GEMINI_API_KEY, '');
    add_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 5);
    add_option(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES, 60);
});

/**
 * Deactivation hook. Cleans up scheduled tasks.
 */
register_deactivation_hook(__FILE__, function() {
    foreach (sitepulse_get_cron_hooks() as $hook) {
        wp_clear_scheduled_hook($hook);
    }
});
