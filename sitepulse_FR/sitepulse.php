<?php
/**
 * Plugin Name: Sitepulse - JLG
 * Plugin URI: https://your-site.com/sitepulse
 * Description: Monitors website pulse: speed, database, maintenance, server, errors.
 * Version: 1.0
 * Author: Jérôme Le Gousse
 * Requires PHP: 7.1
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
define('SITEPULSE_OPTION_ALERT_INTERVAL', 'sitepulse_alert_interval');
define('SITEPULSE_OPTION_ALERT_RECIPIENTS', 'sitepulse_alert_recipients');
define('SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE', 'sitepulse_impact_loader_signature');
define('SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER', 'sitepulse_error_alert_log_pointer');
define('SITEPULSE_OPTION_CRON_WARNINGS', 'sitepulse_cron_warnings');
define('SITEPULSE_OPTION_DEBUG_NOTICES', 'sitepulse_debug_notices');

define('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS', 'sitepulse_speed_scan_results');
define('SITEPULSE_TRANSIENT_AI_INSIGHT', 'sitepulse_ai_insight');
define('SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX', 'sitepulse_error_alert_');
define('SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX', '_lock');
define('SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK', SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . 'cpu' . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX);
define('SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK', SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . 'php_fatal' . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX);
define('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX', 'sitepulse_plugin_dir_size_');
define('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT', 'sitepulse_resource_monitor_snapshot');

define('SITEPULSE_NONCE_ACTION_AI_INSIGHT', 'sitepulse_get_ai_insight');
define('SITEPULSE_NONCE_ACTION_CLEANUP', 'sitepulse_cleanup');
define('SITEPULSE_NONCE_FIELD_CLEANUP', 'sitepulse_cleanup_nonce');
define('SITEPULSE_ACTION_PLUGIN_IMPACT_REFRESH', 'sitepulse_plugin_impact_refresh');

/**
 * Retrieves the absolute path to the WordPress debug log file.
 *
 * @param bool $require_readable Optional. When true, only returns the path if the
 *                               file exists and is readable. Default false.
 *
 * @return string|null Normalized file path when available, null otherwise.
 */
function sitepulse_get_wp_debug_log_path($require_readable = false) {
    if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
        return null;
    }

    $path = null;

    if (is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
        $path = WP_DEBUG_LOG;
    } elseif (true === WP_DEBUG_LOG) {
        $path = WP_CONTENT_DIR . '/debug.log';
    }

    if ($path === null) {
        return null;
    }

    if (function_exists('wp_normalize_path')) {
        $path = wp_normalize_path($path);
    } else {
        $path = str_replace('\\', '/', $path);
    }

    if ($require_readable && (!file_exists($path) || !is_readable($path))) {
        return null;
    }

    return $path;
}

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

require_once SITEPULSE_PATH . 'includes/debug-notices.php';
require_once SITEPULSE_PATH . 'includes/plugin-impact-tracker.php';
sitepulse_plugin_impact_tracker_bootstrap();

/**
 * Returns the absolute path to the SitePulse MU loader file.
 *
 * @return array{dir:string,file:string}
 */
function sitepulse_plugin_impact_get_mu_loader_paths() {
    $mu_dir = trailingslashit(WP_CONTENT_DIR) . 'mu-plugins';

    return [
        'dir'  => $mu_dir,
        'file' => trailingslashit($mu_dir) . 'sitepulse-impact-loader.php',
    ];
}

/**
 * Returns the checksum of the bundled MU loader file.
 *
 * @return string|null
 */
function sitepulse_plugin_impact_get_loader_signature() {
    $source = SITEPULSE_PATH . 'includes/mu-plugin/sitepulse-impact-loader.php';

    if (!file_exists($source) || !is_readable($source)) {
        return null;
    }

    $signature = hash_file('sha256', $source);

    return $signature !== false ? $signature : null;
}

/**
 * Timing safe string comparison with graceful fallback.
 *
 * @param string $known_string Known string (reference).
 * @param string $user_string  User-provided string.
 * @return bool
 */
function sitepulse_hash_equals($known_string, $user_string) {
    if (!is_string($known_string) || !is_string($user_string)) {
        return false;
    }

    if (function_exists('hash_equals')) {
        return hash_equals($known_string, $user_string);
    }

    $known_length = strlen($known_string);

    if ($known_length !== strlen($user_string)) {
        return false;
    }

    $result = 0;

    for ($i = 0; $i < $known_length; $i++) {
        $result |= ord($known_string[$i]) ^ ord($user_string[$i]);
    }

    return $result === 0;
}

/**
 * Checks whether a log line contains a fatal PHP error.
 *
 * @param string $log_line Log line to inspect.
 * @return bool
 */
function sitepulse_log_line_contains_fatal_error($log_line) {
    if (!is_string($log_line) || $log_line === '') {
        return false;
    }

    $fatal_patterns = [
        '/PHP Fatal error/i',
        '/PHP Parse error/i',
        '/PHP Compile error/i',
        '/PHP Core error/i',
        '/PHP Recoverable fatal error/i',
        '/Uncaught\s+(?:Error|Exception)/i',
        '/\bE_(?:ERROR|PARSE|COMPILE_ERROR|CORE_ERROR|RECOVERABLE_ERROR)\b/',
    ];

    foreach ($fatal_patterns as $pattern) {
        if (preg_match($pattern, $log_line)) {
            return true;
        }
    }

    return false;
}

/**
 * Stores a warning indicating that a cron hook could not be scheduled.
 *
 * @param string $module_key Module identifier.
 * @param string $message    Warning message displayed to administrators.
 * @return void
 */
function sitepulse_register_cron_warning($module_key, $message) {
    if (!is_string($module_key) || $module_key === '' || !is_string($message) || $message === '') {
        return;
    }

    $warnings = get_option(SITEPULSE_OPTION_CRON_WARNINGS, []);

    if (!is_array($warnings)) {
        $warnings = [];
    }

    $current = isset($warnings[$module_key]) ? $warnings[$module_key] : null;

    if (is_array($current) && isset($current['message']) && $current['message'] === $message) {
        return;
    }

    $warnings[$module_key] = [
        'message' => $message,
    ];

    update_option(SITEPULSE_OPTION_CRON_WARNINGS, $warnings, false);
}

/**
 * Clears a stored cron warning for a module.
 *
 * @param string $module_key Module identifier.
 * @return void
 */
function sitepulse_clear_cron_warning($module_key) {
    if (!is_string($module_key) || $module_key === '') {
        return;
    }

    $warnings = get_option(SITEPULSE_OPTION_CRON_WARNINGS, []);

    if (!is_array($warnings) || !array_key_exists($module_key, $warnings)) {
        return;
    }

    unset($warnings[$module_key]);

    update_option(SITEPULSE_OPTION_CRON_WARNINGS, $warnings, false);
}

/**
 * Displays stored cron scheduling warnings in the WordPress administration.
 *
 * @return void
 */
function sitepulse_render_cron_warnings() {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    $warnings = get_option(SITEPULSE_OPTION_CRON_WARNINGS, []);

    if (!is_array($warnings) || empty($warnings)) {
        return;
    }

    foreach ($warnings as $warning) {
        if (!is_array($warning) || empty($warning['message'])) {
            continue;
        }

        $message = (string) $warning['message'];

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html($message)
        );
    }
}
add_action('admin_notices', 'sitepulse_render_cron_warnings');

/**
 * Attempts to bootstrap the WordPress filesystem abstraction layer.
 *
 * @return WP_Filesystem_Base|null
 */
function sitepulse_get_filesystem() {
    global $sitepulse_filesystem_initialized, $sitepulse_filesystem_instance;

    if (!is_bool($sitepulse_filesystem_initialized ?? null)) {
        $sitepulse_filesystem_initialized = false;
        $sitepulse_filesystem_instance    = null;
    }

    if (function_exists('apply_filters')) {
        $override = apply_filters(
            'sitepulse_pre_get_filesystem',
            null,
            $sitepulse_filesystem_initialized,
            $sitepulse_filesystem_instance
        );

        if ($override !== null) {
            $sitepulse_filesystem_initialized = true;
            $sitepulse_filesystem_instance    = $override instanceof WP_Filesystem_Base ? $override : null;

            return $sitepulse_filesystem_instance;
        }
    }

    if ($sitepulse_filesystem_initialized) {
        return $sitepulse_filesystem_instance;
    }

    $sitepulse_filesystem_initialized = true;

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    if (!function_exists('WP_Filesystem')) {
        return null;
    }

    global $wp_filesystem;

    if (WP_Filesystem() && $wp_filesystem instanceof WP_Filesystem_Base) {
        $sitepulse_filesystem_instance = $wp_filesystem;
    } else {
        $sitepulse_filesystem_instance = null;
    }

    return $sitepulse_filesystem_instance;
}

/**
 * Installs or refreshes the MU loader responsible for early instrumentation.
 *
 * @return void
 */
function sitepulse_plugin_impact_install_mu_loader() {
    $paths = sitepulse_plugin_impact_get_mu_loader_paths();
    $source = SITEPULSE_PATH . 'includes/mu-plugin/sitepulse-impact-loader.php';
    $target_dir = $paths['dir'];
    $target_file = $paths['file'];

    $signature = sitepulse_plugin_impact_get_loader_signature();

    if ($signature === null) {
        return;
    }

    $filesystem = sitepulse_get_filesystem();
    $stored_signature = get_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
    $warning_message = sprintf(
        __(
            'SitePulse n’a pas pu installer le chargeur MU du suivi d’impact (%s). Vérifiez les permissions du dossier mu-plugins.',
            'sitepulse'
        ),
        $target_dir
    );

    if ($filesystem instanceof WP_Filesystem_Base) {
        if (!$filesystem->is_dir($target_dir)) {
            $filesystem->mkdir($target_dir, defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : false);
        }

        if (!$filesystem->is_dir($target_dir) || !$filesystem->is_writable($target_dir)) {
            sitepulse_log(sprintf('SitePulse impact loader directory not writable via WP_Filesystem (%s).', $target_dir), 'ERROR');
            delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
            sitepulse_register_cron_warning('plugin_impact', $warning_message);

            return;
        }
    } else {
        if (!is_dir($target_dir) && function_exists('wp_mkdir_p')) {
            wp_mkdir_p($target_dir);
        }

        if (!is_dir($target_dir) || !is_writable($target_dir)) {
            sitepulse_log(sprintf('SitePulse impact loader directory not writable (%s).', $target_dir), 'ERROR');
            delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
            sitepulse_register_cron_warning('plugin_impact', $warning_message);

            return;
        }
    }

    $has_valid_loader = false;
    $needs_copy = true;

    if (is_string($stored_signature) && sitepulse_hash_equals($signature, $stored_signature)) {
        if ($filesystem instanceof WP_Filesystem_Base) {
            if ($filesystem->exists($target_file)) {
                $has_valid_loader = true;
                $needs_copy      = false;
            }
        } elseif (file_exists($target_file)) {
            $has_valid_loader = true;
            $needs_copy      = false;
        }
    }

    if ($needs_copy) {
        if ($filesystem instanceof WP_Filesystem_Base && $filesystem->exists($target_file)) {
            $contents = $filesystem->get_contents($target_file);

            if (is_string($contents)) {
                $existing_signature = hash('sha256', $contents);

                if ($existing_signature !== false && sitepulse_hash_equals($signature, $existing_signature)) {
                    $has_valid_loader = true;
                    $needs_copy      = false;
                }
            }
        } elseif (file_exists($target_file)) {
            $existing_signature = hash_file('sha256', $target_file);

            if ($existing_signature !== false && sitepulse_hash_equals($signature, $existing_signature)) {
                $has_valid_loader = true;
                $needs_copy      = false;
            }
        }
    }

    if ($needs_copy) {
        $copied = false;

        if ($filesystem instanceof WP_Filesystem_Base) {
            $copied = $filesystem->copy(
                $source,
                $target_file,
                true,
                defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : false
            );
        }

        if (!$copied) {
            $copied = copy($source, $target_file);

            if ($copied && function_exists('chmod')) {
                @chmod($target_file, 0644);
            }
        }

        if ($copied) {
            if ($filesystem instanceof WP_Filesystem_Base) {
                $contents = $filesystem->get_contents($target_file);
                if (is_string($contents)) {
                    $existing_signature = hash('sha256', $contents);

                    if ($existing_signature !== false && sitepulse_hash_equals($signature, $existing_signature)) {
                        $has_valid_loader = true;
                    }
                }
            }

            if (!$has_valid_loader && file_exists($target_file)) {
                $existing_signature = hash_file('sha256', $target_file);
                $has_valid_loader   = $existing_signature !== false && sitepulse_hash_equals($signature, $existing_signature);
            }
        }
    }

    if ($has_valid_loader) {
        update_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE, $signature, false);
        sitepulse_clear_cron_warning('plugin_impact');

        return;
    }

    delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
    sitepulse_log(sprintf('SitePulse impact loader installation failed for %s.', $target_file), 'ERROR');
    sitepulse_register_cron_warning('plugin_impact', $warning_message);
}

/**
 * Removes the SitePulse MU loader.
 *
 * @return void
 */
function sitepulse_plugin_impact_remove_mu_loader() {
    $paths = sitepulse_plugin_impact_get_mu_loader_paths();
    $target_file = $paths['file'];
    $filesystem = sitepulse_get_filesystem();
    $deleted = false;

    if ($filesystem instanceof WP_Filesystem_Base && $filesystem->exists($target_file)) {
        $deleted = $filesystem->delete($target_file);
    }

    if (!$deleted && file_exists($target_file)) {
        $deleted = @unlink($target_file);
    }

    if (!$deleted && file_exists($target_file)) {
        sitepulse_log(sprintf('SitePulse impact loader removal failed for %s.', $target_file), 'ERROR');
    }

    delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
}

/**
 * Ensures the MU loader is present and up to date.
 *
 * @return void
 */
function sitepulse_plugin_impact_maybe_refresh_mu_loader() {
    $signature = sitepulse_plugin_impact_get_loader_signature();

    if ($signature === null) {
        return;
    }

    $paths = sitepulse_plugin_impact_get_mu_loader_paths();
    $target_file = $paths['file'];
    $stored_signature = get_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);

    if (!file_exists($target_file) || $stored_signature !== $signature) {
        sitepulse_plugin_impact_install_mu_loader();
    }
}

sitepulse_plugin_impact_maybe_refresh_mu_loader();

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
    $filesystem = sitepulse_get_filesystem();

    if (!is_dir($log_dir)) {
        $error_message = sprintf('SitePulse: debug log directory does not exist (%s).', $log_dir);
        error_log($error_message);
        sitepulse_schedule_debug_admin_notice($error_message);

        return;
    }

    $is_directory_writable = is_writable($log_dir);

    if (!$is_directory_writable && $filesystem instanceof WP_Filesystem_Base) {
        $is_directory_writable = $filesystem->is_writable($log_dir);
    }

    if (!$is_directory_writable) {
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
            $protection_exists = file_exists($path);

            if (!$protection_exists && $filesystem instanceof WP_Filesystem_Base) {
                $protection_exists = $filesystem->exists($path);
            }

            if ($protection_exists) {
                continue;
            }

            $written = false;

            if ($filesystem instanceof WP_Filesystem_Base) {
                $written = $filesystem->put_contents($path, $contents, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : false);
            }

            if (!$written) {
                $result = file_put_contents($path, $contents, LOCK_EX);

                if ($result !== false) {
                    $written = true;

                    if (function_exists('chmod')) {
                        @chmod($path, 0644);
                    }
                }
            }

            if (!$written) {
                $error_message = sprintf('SitePulse: unable to write protection file (%s).', $path);
                error_log($error_message);
                sitepulse_schedule_debug_admin_notice($error_message);
            }
        }
    }

    if (function_exists('wp_date')) {
        $timestamp = wp_date('Y-m-d H:i:s');
    } elseif (function_exists('current_time')) {
        $timestamp = current_time('mysql');
    } else {
        $timestamp = date('Y-m-d H:i:s');
    }
    $log_entry  = "[$timestamp] [$level] $message\n";
    $max_size   = 5 * 1024 * 1024; // 5 MB

    if (file_exists(SITEPULSE_DEBUG_LOG)) {
        $is_log_writable = is_writable(SITEPULSE_DEBUG_LOG);

        if (!$is_log_writable && $filesystem instanceof WP_Filesystem_Base) {
            $is_log_writable = $filesystem->is_writable(SITEPULSE_DEBUG_LOG);
        }

        if (!$is_log_writable) {
            $error_message = sprintf('SitePulse: debug log file is not writable (%s).', SITEPULSE_DEBUG_LOG);
            error_log($error_message);
            sitepulse_schedule_debug_admin_notice($error_message);

            return;
        }

        if (filesize(SITEPULSE_DEBUG_LOG) > $max_size) {
            $archive = SITEPULSE_DEBUG_LOG . '.' . time();
            $rotated = false;

            if ($filesystem instanceof WP_Filesystem_Base && 'direct' !== $filesystem->method) {
                $rotated = $filesystem->move(SITEPULSE_DEBUG_LOG, $archive, true);
            }

            if (!$rotated && !@rename(SITEPULSE_DEBUG_LOG, $archive)) {
                $error_message = sprintf('SitePulse: unable to rotate debug log file (%s).', SITEPULSE_DEBUG_LOG);
                error_log($error_message);
                sitepulse_schedule_debug_admin_notice($error_message);
            }
        }
    }

    $write_succeeded = false;

    if ($filesystem instanceof WP_Filesystem_Base && 'direct' !== $filesystem->method) {
        $existing_contents = '';

        if ($filesystem->exists(SITEPULSE_DEBUG_LOG)) {
            $existing = $filesystem->get_contents(SITEPULSE_DEBUG_LOG);
            if (is_string($existing)) {
                $existing_contents = $existing;
            }
        }

        $write_succeeded = $filesystem->put_contents(
            SITEPULSE_DEBUG_LOG,
            $existing_contents . $log_entry,
            defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : false
        );
    }

    if (!$write_succeeded) {
        $result = file_put_contents(SITEPULSE_DEBUG_LOG, $log_entry, FILE_APPEND | LOCK_EX);
        $write_succeeded = ($result !== false);
    }

    if (!$write_succeeded) {
        $error_message = sprintf('SitePulse: unable to write to debug log file (%s).', SITEPULSE_DEBUG_LOG);
        error_log($error_message);
        sitepulse_schedule_debug_admin_notice($error_message);
    }
}
sitepulse_log('SitePulse loaded. Version: ' . SITEPULSE_VERSION);

// Include core files
require_once SITEPULSE_PATH . 'includes/functions.php';
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
    
    $active_modules_option = get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);
    $active_modules = array_values(array_filter(array_map('strval', (array) $active_modules_option), static function ($module) {
        return $module !== '';
    }));
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
 * Sets default options for a given site.
 *
 * @return void
 */
function sitepulse_activate_site() {
    // **FIX:** Activate the dashboard by default to prevent fatal errors on first load.
    add_option(SITEPULSE_OPTION_ACTIVE_MODULES, ['custom_dashboards'], '', false);
    add_option(SITEPULSE_OPTION_DEBUG_MODE, false, '', false);
    add_option(SITEPULSE_OPTION_GEMINI_API_KEY, '', '', false);
    add_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 5, '', false);
    add_option(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES, 60, '', false);
    add_option(SITEPULSE_OPTION_ALERT_INTERVAL, 5, '', false);
    add_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, [], '', false);
    add_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, [], '', false);
    add_option(SITEPULSE_OPTION_CRON_WARNINGS, [], '', false);

    sitepulse_plugin_impact_install_mu_loader();
}

/**
 * Ensures SitePulse defaults are applied to a newly created site.
 *
 * @param int $site_id Site identifier.
 *
 * @return void
 */
function sitepulse_initialize_new_site($site_id) {
    static $initialized = [];

    $site_id = (int) $site_id;

    if ($site_id <= 0 || isset($initialized[$site_id])) {
        return;
    }

    $initialized[$site_id] = true;

    $switched = false;

    if (is_multisite() && function_exists('switch_to_blog')) {
        $switched = switch_to_blog($site_id);
    }

    sitepulse_activate_site();

    $active_modules = get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);

    if (!is_array($active_modules)) {
        $active_modules = [];
    }

    if (!in_array('custom_dashboards', $active_modules, true)) {
        $active_modules[] = 'custom_dashboards';
        update_option(SITEPULSE_OPTION_ACTIVE_MODULES, $active_modules);
    }

    if ($switched && function_exists('restore_current_blog')) {
        restore_current_blog();
    }
}

add_action('wp_initialize_site', function($new_site, $args) {
    unset($args);

    if (!($new_site instanceof \WP_Site)) {
        return;
    }

    sitepulse_initialize_new_site($new_site->blog_id);
}, 10, 2);

add_action('wpmu_new_blog', function($site_id, $user_id, $domain, $path, $network_id, $meta) {
    unset($user_id, $domain, $path, $network_id, $meta);

    sitepulse_initialize_new_site($site_id);
}, 10, 6);

/**
 * Clears scheduled tasks for a given site.
 *
 * @return void
 */
function sitepulse_deactivate_site() {
    foreach (sitepulse_get_cron_hooks() as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    sitepulse_plugin_impact_remove_mu_loader();
}

/**
 * Activation hook. Sets default options.
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 */
register_activation_hook(__FILE__, function($network_wide) {
    if (is_multisite() && $network_wide) {
        $site_ids = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);

        foreach ($site_ids as $site_id) {
            $site_id = (int) $site_id;

            if ($site_id <= 0) {
                continue;
            }

            switch_to_blog($site_id);
            sitepulse_activate_site();
            restore_current_blog();
        }

        return;
    }

    sitepulse_activate_site();
});

/**
 * Deactivation hook. Cleans up scheduled tasks.
 *
 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
 */
register_deactivation_hook(__FILE__, function($network_wide) {
    if (is_multisite() && $network_wide) {
        $site_ids = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);

        foreach ($site_ids as $site_id) {
            $site_id = (int) $site_id;

            if ($site_id <= 0) {
                continue;
            }

            switch_to_blog($site_id);
            sitepulse_deactivate_site();
            restore_current_blog();
        }

        return;
    }

    sitepulse_deactivate_site();
});
