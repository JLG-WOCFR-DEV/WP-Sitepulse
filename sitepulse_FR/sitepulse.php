<?php
/**
 * Plugin Name: Sitepulse - JLG
 * Plugin URI: https://your-site.com/sitepulse
 * Description: Monitors website pulse: speed, database, maintenance, server, errors.
 * Version: 1.0
 * Author: Jérôme Le Gousse
 * License: GPL-2.0+
 * Uninstall: uninstall.php
 */

if (!defined('ABSPATH')) exit;

// Define constants
define('SITEPULSE_VERSION', '1.0');
define('SITEPULSE_PATH', plugin_dir_path(__FILE__));
define('SITEPULSE_URL', plugin_dir_url(__FILE__));
$debug_mode = get_option('sitepulse_debug_mode', false);
define('SITEPULSE_DEBUG', (bool) $debug_mode);
define('SITEPULSE_DEBUG_LOG', WP_CONTENT_DIR . '/sitepulse-debug.log');

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
 * Logging function for debugging purposes.
 *
 * @param string $message The message to log.
 * @param string $level   The log level (e.g., INFO, WARNING, ERROR).
 */
function sitepulse_log($message, $level = 'INFO') {
    if (!SITEPULSE_DEBUG) {
        return;
    }

    $timestamp  = date('Y-m-d H:i:s');
    $log_entry  = "[$timestamp] [$level] $message\n";
    $max_size   = 5 * 1024 * 1024; // 5 MB

    if (file_exists(SITEPULSE_DEBUG_LOG) && filesize(SITEPULSE_DEBUG_LOG) > $max_size) {
        $archive = SITEPULSE_DEBUG_LOG . '.' . time();
        @rename(SITEPULSE_DEBUG_LOG, $archive);
    }

    $result = @file_put_contents(SITEPULSE_DEBUG_LOG, $log_entry, FILE_APPEND | LOCK_EX);

    if ($result === false) {
        error_log(sprintf('SitePulse: unable to write to debug log file (%s).', SITEPULSE_DEBUG_LOG));
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
    
    $active_modules = get_option('sitepulse_active_modules', []);
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
    add_option('sitepulse_active_modules', ['custom_dashboards']);
    add_option('sitepulse_debug_mode', false);
    add_option('sitepulse_gemini_api_key', '');
    add_option('sitepulse_cpu_alert_threshold', 5);
    add_option('sitepulse_alert_cooldown_minutes', 60);
});

/**
 * Deactivation hook. Cleans up scheduled tasks.
 */
register_deactivation_hook(__FILE__, function() {
    foreach (sitepulse_get_cron_hooks() as $hook) {
        wp_clear_scheduled_hook($hook);
    }
});
