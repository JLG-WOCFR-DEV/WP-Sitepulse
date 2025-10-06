<?php
/**
 * Shared test stubs for SitePulse unit tests.
 */

if (!defined('SITEPULSE_DEBUG')) {
    define('SITEPULSE_DEBUG', true);
}

if (!defined('SITEPULSE_OPTION_UPTIME_LOG')) {
    define('SITEPULSE_OPTION_UPTIME_LOG', 'sitepulse_uptime_log');
}

if (!defined('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK')) {
    define('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK', 'sitepulse_uptime_failure_streak');
}

if (!defined('SITEPULSE_OPTION_SPEED_WARNING_MS')) {
    define('SITEPULSE_OPTION_SPEED_WARNING_MS', 'sitepulse_speed_warning_ms');
}

if (!defined('SITEPULSE_OPTION_SPEED_CRITICAL_MS')) {
    define('SITEPULSE_OPTION_SPEED_CRITICAL_MS', 'sitepulse_speed_critical_ms');
}

if (!defined('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT')) {
    define('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT', 'sitepulse_uptime_warning_percent');
}

if (!defined('SITEPULSE_OPTION_REVISION_LIMIT')) {
    define('SITEPULSE_OPTION_REVISION_LIMIT', 'sitepulse_revision_limit');
}

if (!defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS')) {
    define('SITEPULSE_DEFAULT_SPEED_WARNING_MS', 200);
}

if (!defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS')) {
    define('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS', 500);
}

if (!defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT')) {
    define('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT', 99);
}

if (!defined('SITEPULSE_DEFAULT_REVISION_LIMIT')) {
    define('SITEPULSE_DEFAULT_REVISION_LIMIT', 100);
}

if (!defined('SITEPULSE_OPTION_DEBUG_NOTICES')) {
    define('SITEPULSE_OPTION_DEBUG_NOTICES', 'sitepulse_debug_notices');
}

if (!defined('SITEPULSE_OPTION_GEMINI_API_KEY')) {
    define('SITEPULSE_OPTION_GEMINI_API_KEY', 'sitepulse_gemini_api_key');
}

if (!defined('SITEPULSE_OPTION_ALERT_RECIPIENTS')) {
    define('SITEPULSE_OPTION_ALERT_RECIPIENTS', 'sitepulse_alert_recipients');
}

if (!defined('SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS')) {
    define('SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS', 'sitepulse_error_alert_delivery_channels');
}

if (!defined('SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS')) {
    define('SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS', 'sitepulse_error_alert_webhooks');
}

if (!defined('SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES')) {
    define('SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES', 'sitepulse_error_alert_severities');
}

if (!defined('SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES')) {
    define('SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES', 'sitepulse_alert_cooldown_minutes');
}

if (!defined('SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER')) {
    define('SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER', 'sitepulse_error_alert_log_pointer');
}

if (!defined('SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX')) {
    define('SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX', 'sitepulse_error_alert_');
}

if (!defined('SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX')) {
    define('SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX', '_lock');
}

if (!defined('SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK')) {
    define(
        'SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK',
        SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . 'cpu' . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX
    );
}

if (!defined('SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK')) {
    define(
        'SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK',
        SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . 'php_fatal' . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX
    );
}

if (!defined('SITEPULSE_TRANSIENT_AI_INSIGHT')) {
    define('SITEPULSE_TRANSIENT_AI_INSIGHT', 'sitepulse_ai_insight');
}

if (!defined('SITEPULSE_NONCE_ACTION_AI_INSIGHT')) {
    define('SITEPULSE_NONCE_ACTION_AI_INSIGHT', 'sitepulse_get_ai_insight');
}

if (!defined('SITEPULSE_VERSION')) {
    define('SITEPULSE_VERSION', 'test');
}

if (!defined('SITEPULSE_URL')) {
    define('SITEPULSE_URL', 'https://example.com/wp-content/plugins/sitepulse/');
}

if (!function_exists('sitepulse_log')) {
    function sitepulse_log($message, $level = 'INFO') {
        if (isset($GLOBALS['sitepulse_log_callable']) && is_callable($GLOBALS['sitepulse_log_callable'])) {
            return call_user_func($GLOBALS['sitepulse_log_callable'], $message, $level);
        }

        if (!isset($GLOBALS['sitepulse_logger'])) {
            $GLOBALS['sitepulse_logger'] = [];
        }

        $GLOBALS['sitepulse_logger'][] = [
            'message' => (string) $message,
            'level'   => (string) $level,
        ];
    }
}

if (!function_exists('sitepulse_register_cron_warning')) {
    function sitepulse_register_cron_warning($module_key, $message) {
        $GLOBALS['sitepulse_cron_warnings'][$module_key][] = $message;
    }
}

if (!function_exists('sitepulse_clear_cron_warning')) {
    function sitepulse_clear_cron_warning($module_key) {
        $GLOBALS['sitepulse_cleared_cron_warnings'][] = $module_key;
    }
}

if (!function_exists('sitepulse_get_cron_hook')) {
    function sitepulse_get_cron_hook($module) {
        return 'sitepulse_' . $module . '_cron';
    }
}

if (!function_exists('sitepulse_get_wp_debug_log_path')) {
    function sitepulse_get_wp_debug_log_path($require_readable = false) {
        $path = $GLOBALS['sitepulse_test_log_path'] ?? null;

        if ($path === null) {
            return null;
        }

        if ($require_readable && (!file_exists($path) || !is_readable($path))) {
            return null;
        }

        return $path;
    }
}

if (!function_exists('sitepulse_get_recent_log_lines')) {
    function sitepulse_get_recent_log_lines($file_path, $max_lines = 100, $max_bytes = 131072, $with_metadata = false) {
        if (!is_string($file_path) || $file_path === '') {
            return null;
        }

        if (!file_exists($file_path) || !is_readable($file_path)) {
            return null;
        }

        $contents = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($contents === false) {
            return null;
        }

        if ($max_lines > 0 && count($contents) > $max_lines) {
            $contents = array_slice($contents, -$max_lines);
        }

        $lines = array_values($contents);

        if (!$with_metadata) {
            return $lines;
        }

        return [
            'lines'         => $lines,
            'bytes_read'    => strlen(implode("\n", $lines)),
            'file_size'     => filesize($file_path),
            'truncated'     => $max_lines > 0 ? count(file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) > $max_lines : false,
            'last_modified' => filemtime($file_path),
        ];
    }
}
