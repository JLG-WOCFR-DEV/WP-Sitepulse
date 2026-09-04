<?php
/**
 * SitePulse Resource Monitor snapshot collection.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formats CPU load values for display.
 *
 * @param mixed $load_values Raw load average values.
 * @return string
 */
function sitepulse_resource_monitor_format_load_display($load_values) {
    $not_available_label = esc_html__('N/A', 'sitepulse');

    if (!is_array($load_values) || empty($load_values)) {
        $load_values = [$not_available_label, $not_available_label, $not_available_label];
    }

    $normalized_values = array_map(
        static function ($value) use ($not_available_label) {
            if (is_numeric($value)) {
                return number_format_i18n((float) $value, 2);
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_null($value)) {
                return $not_available_label;
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return $not_available_label;
        },
        array_slice(array_values((array) $load_values), 0, 3)
    );

    $normalized_values = array_pad($normalized_values, 3, $not_available_label);

    return implode(' / ', $normalized_values);
}

/**
 * Resolves CPU load average values using multiple strategies.
 *
 * @param string $not_available_label Translated fallback label.
 * @return array{
 *     display: array<int, mixed>,
 *     raw: array<int, float|null>,
 *     notices: array<int, array{type:string,message:string}>
 * }
 */
function sitepulse_resource_monitor_resolve_load_average($not_available_label) {
    $display = [$not_available_label, $not_available_label, $not_available_label];
    $raw = [null, null, null];
    $notices = [];
    $source = null;

    $load_values = null;

    if (function_exists('sys_getloadavg')) {
        $load_values = sitepulse_resource_monitor_sanitize_load_values(sys_getloadavg());

        if ($load_values !== null) {
            $source = 'sys_getloadavg';
        } else {
            $message = esc_html__('Indisponible – sys_getloadavg() désactivée par votre hébergeur', 'sitepulse');
            $notices[] = [
                'type'    => 'warning',
                'message' => $message,
            ];

            if (function_exists('sitepulse_log')) {
                sitepulse_log(__('Resource Monitor: CPU load average unavailable because sys_getloadavg() is disabled by the hosting provider.', 'sitepulse'), 'WARNING');
            }
        }
    } else {
        $message = esc_html__('Indisponible – sys_getloadavg() désactivée par votre hébergeur', 'sitepulse');
        $notices[] = [
            'type'    => 'warning',
            'message' => $message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log(__('Resource Monitor: sys_getloadavg() is not available on this server.', 'sitepulse'), 'WARNING');
        }
    }

    if ($load_values === null) {
        $proc_values = sitepulse_resource_monitor_read_proc_loadavg();

        if ($proc_values !== null) {
            $load_values = $proc_values;
            $source = 'proc_loadavg';

            if (function_exists('sitepulse_log')) {
                sitepulse_log(__('Resource Monitor: CPU load average resolved from /proc/loadavg fallback.', 'sitepulse'), 'INFO');
            }
        } elseif (!function_exists('sys_getloadavg')) {
            $message = esc_html__('CPU load average is unavailable because /proc/loadavg could not be read.', 'sitepulse');
            $notices[] = [
                'type'    => 'warning',
                'message' => $message,
            ];

            if (function_exists('sitepulse_log')) {
                sitepulse_log(__('Resource Monitor: /proc/loadavg could not be read to determine CPU load average.', 'sitepulse'), 'WARNING');
            }
        }
    }

    $filter_context = [
        'source'            => $source,
        'fallback_attempted'=> $source !== null && $source !== 'sys_getloadavg',
    ];

    /**
     * Filters the raw load averages before they are formatted for display.
     *
     * @param array<int, float>|null $load_values Raw load averages.
     * @param array{source:?string,fallback_attempted:bool} $filter_context Contextual metadata.
     */
    $filtered_values = apply_filters('sitepulse_resource_monitor_load_average', $load_values, $filter_context);

    if ($filtered_values !== $load_values) {
        $sanitized = sitepulse_resource_monitor_sanitize_load_values($filtered_values);

        if ($sanitized !== null) {
            $load_values = $sanitized;
            $source = 'filter';
        }
    }

    if ($load_values !== null) {
        $display = $load_values;
        $raw = array_map(static function($value) {
            return is_numeric($value) ? (float) $value : null;
        }, array_pad(array_values($load_values), 3, null));
    }

    return [
        'display' => array_pad(array_values((array) $display), 3, $not_available_label),
        'raw'     => array_pad($raw, 3, null),
        'notices' => $notices,
    ];
}

/**
 * Validates and sanitizes load average values.
 *
 * @param mixed $values Raw values.
 * @return array<int, float>|null
 */
function sitepulse_resource_monitor_sanitize_load_values($values) {
    if (!is_array($values) || empty($values)) {
        return null;
    }

    $values = array_slice(array_values($values), 0, 3);

    if (empty($values)) {
        return null;
    }

    foreach ($values as $value) {
        if (!is_numeric($value)) {
            return null;
        }
    }

    return array_map(static function($value) {
        return (float) $value;
    }, $values);
}

/**
 * Attempts to read load averages from /proc/loadavg.
 *
 * @return array<int, float>|null
 */
function sitepulse_resource_monitor_read_proc_loadavg() {
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $path = '/proc/loadavg';

    if (!@is_readable($path)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $cached = null;

        return null;
    }

    $error_message = null;

    set_error_handler(static function($errno, $errstr) use (&$error_message) {
        $error_message = $errstr;

        return true;
    });

    try {
        $contents = file_get_contents($path);
    } catch (\Throwable $exception) {
        $error_message = $exception->getMessage();
        $contents = false;
    } finally {
        restore_error_handler();
    }

    if ($contents === false || $contents === '') {
        $cached = null;

        return null;
    }

    $parts = preg_split('/\s+/', trim((string) $contents));

    if (!is_array($parts) || empty($parts)) {
        $cached = null;

        return null;
    }

    $values = array_slice($parts, 0, 3);

    $sanitized = sitepulse_resource_monitor_sanitize_load_values($values);

    if ($sanitized === null) {
        $cached = null;

        return null;
    }

    $cached = $sanitized;

    return $cached;
}

/**
 * Retrieves disk usage metrics with shared caching and robust error handling.
 *
 * @param string $type Either "free" or "total".
 * @param string $path Filesystem path to evaluate.
 * @return array{display:string,bytes:int|null,notices:array<int,array{type:string,message:string}>}
 */
function sitepulse_resource_monitor_measure_disk_space($type, $path) {
    $not_available_label = esc_html__('N/A', 'sitepulse');
    $result = [
        'display' => $not_available_label,
        'bytes'   => null,
        'notices' => [],
    ];

    $type = $type === 'total' ? 'total' : 'free';

    static $cache = [];
    $cache_key = $type . '|' . $path;

    /**
     * Filters whether the disk usage measurement should be cached during the current request.
     *
     * @param bool   $enabled Default caching behaviour.
     * @param string $type    Requested metric type (free|total).
     * @param string $path    Filesystem path being inspected.
     */
    $enable_cache = (bool) apply_filters('sitepulse_resource_monitor_enable_disk_cache', true, $type, $path);

    if ($enable_cache && isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $function = $type === 'total' ? 'disk_total_space' : 'disk_free_space';

    $failure_message = $type === 'total'
        ? esc_html__('Unable to determine the total disk space for the WordPress root directory.', 'sitepulse')
        : esc_html__('Unable to determine the available disk space for the WordPress root directory.', 'sitepulse');

    $missing_function_message = $type === 'total'
        ? esc_html__('The disk_total_space() function is not available on this server.', 'sitepulse')
        : esc_html__('The disk_free_space() function is not available on this server.', 'sitepulse');

    if (!function_exists($function)) {
        $result['notices'][] = [
            'type'    => 'warning',
            'message' => $missing_function_message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log(
                sprintf(
                    /* translators: %s: original message. */
                    __('Resource Monitor: %s', 'sitepulse'),
                    $missing_function_message
                ),
                'WARNING'
            );
        }

        if ($enable_cache) {
            $cache[$cache_key] = $result;
        }

        return $result;
    }

    $error_message = null;
    set_error_handler(static function($errno, $errstr) use (&$error_message) {
        $error_message = $errstr;

        return true;
    });

    try {
        $value = $function($path);
    } catch (\Throwable $exception) {
        $error_message = $exception->getMessage();
        $value = false;
    } finally {
        restore_error_handler();
    }

    if ($value !== false) {
        if (is_numeric($value)) {
            $bytes = (int) $value;
            $result['bytes'] = $bytes;
            $result['display'] = size_format($bytes);
        }

        if ($enable_cache) {
            $cache[$cache_key] = $result;
        }

        return $result;
    }

    $result['notices'][] = [
        'type'    => 'warning',
        'message' => $failure_message,
    ];

    if (function_exists('sitepulse_log')) {
        $log_message = sprintf(
            /* translators: %s: original message. */
            __('Resource Monitor: %s', 'sitepulse'),
            $failure_message
        );

        if (is_string($error_message) && $error_message !== '') {
            $log_message .= ' ' . sprintf(
                /* translators: %s: error message. */
                __('Error: %s', 'sitepulse'),
                $error_message
            );
        }

        sitepulse_log($log_message, 'ERROR');
    }

    if ($enable_cache) {
        $cache[$cache_key] = $result;
    }

    return $result;
}

/**
 * Returns cached resource metrics or computes a fresh snapshot.
 *
 * @return array{
 *     load: array<int, mixed>,
 *     load_display: string,
 *     memory_usage: string,
 *     memory_limit: string|false,
 *     disk_free: string,
 *     disk_total: string,
 *     notices: array<int, array{type:string,message:string}>,
 *     generated_at: int
 * }
 */
function sitepulse_resource_monitor_get_snapshot($context = 'manual') {
    $context = is_string($context) ? sanitize_key($context) : 'manual';

    if ($context === '') {
        $context = 'manual';
    }

    $bypass_cache = ($context === 'cron');
    $cached = $bypass_cache ? null : get_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);

    if (is_array($cached) && isset($cached['generated_at'])) {
        if (!isset($cached['source'])) {
            $cached['source'] = 'manual';
        }

        return $cached;
    }

    $notices = [];
    $not_available_label = esc_html__('N/A', 'sitepulse');
    $load_result = sitepulse_resource_monitor_resolve_load_average($not_available_label);
    $load = $load_result['display'];
    $load_display = sitepulse_resource_monitor_format_load_display($load);
    $load_raw = $load_result['raw'];
    if (!empty($load_result['notices'])) {
        $notices = array_merge($notices, $load_result['notices']);
    }

    $memory_usage_bytes = (int) memory_get_usage();
    $memory_usage = size_format($memory_usage_bytes);
    $memory_limit_ini = ini_get('memory_limit');
    $memory_limit = $memory_limit_ini;
    $memory_limit_bytes = sitepulse_resource_monitor_normalize_memory_limit_bytes($memory_limit_ini);
    $memory_usage_percent = sitepulse_resource_monitor_calculate_percentage($memory_usage_bytes, $memory_limit_bytes);

    if ($memory_limit_ini !== false) {
        $memory_limit_value = trim((string) $memory_limit_ini);
        $memory_limit = $memory_limit_value;

        if ($memory_limit_value !== '') {
            $memory_limit_lower = strtolower($memory_limit_value);

            if (
                $memory_limit_lower === '-1'
                || $memory_limit_lower === 'unlimited'
                || (float) $memory_limit_value === -1.0
            ) {
                $memory_limit = __('Illimitée', 'sitepulse');
            }
        }
    }

    $disk_free = $not_available_label;
    $disk_free_bytes = null;

    $disk_free_result = sitepulse_resource_monitor_measure_disk_space('free', ABSPATH);
    $disk_free = $disk_free_result['display'];
    $disk_free_bytes = $disk_free_result['bytes'];
    if (!empty($disk_free_result['notices'])) {
        $notices = array_merge($notices, $disk_free_result['notices']);
    }

    $disk_total = $not_available_label;
    $disk_total_bytes = null;

    $disk_total_result = sitepulse_resource_monitor_measure_disk_space('total', ABSPATH);
    $disk_total = $disk_total_result['display'];
    $disk_total_bytes = $disk_total_result['bytes'];
    if (!empty($disk_total_result['notices'])) {
        $notices = array_merge($notices, $disk_total_result['notices']);
    }

    $disk_free_percent = sitepulse_resource_monitor_calculate_percentage($disk_free_bytes, $disk_total_bytes);
    $disk_used_bytes = null;
    $disk_used = $not_available_label;
    $disk_used_percent = null;

    if ($disk_total_bytes !== null && $disk_free_bytes !== null && $disk_total_bytes > 0) {
        $disk_used_bytes = max(0, $disk_total_bytes - $disk_free_bytes);
        $disk_used = size_format($disk_used_bytes);
    }

    if ($disk_free_percent !== null) {
        $disk_used_percent = max(0.0, min(100.0, 100.0 - $disk_free_percent));
    }

    $snapshot = [
        'load'         => $load,
        'load_display' => $load_display,
        'memory_usage' => $memory_usage,
        'memory_usage_bytes' => $memory_usage_bytes,
        'memory_limit' => $memory_limit,
        'memory_limit_bytes' => $memory_limit_bytes,
        'memory_usage_percent' => $memory_usage_percent,
        'disk_free'    => $disk_free,
        'disk_free_bytes' => $disk_free_bytes,
        'disk_free_percent' => $disk_free_percent,
        'disk_used'    => $disk_used,
        'disk_used_bytes' => $disk_used_bytes,
        'disk_used_percent' => $disk_used_percent,
        'disk_total'   => $disk_total,
        'disk_total_bytes' => $disk_total_bytes,
        'notices'      => $notices,
        'generated_at' => (int) current_time('timestamp', true),
        'source'       => $context,
    ];

    $history_snapshot = $snapshot;
    $history_snapshot['load_raw'] = $load_raw;
    sitepulse_resource_monitor_append_history($history_snapshot);

    $cache_ttl = (int) apply_filters('sitepulse_resource_monitor_cache_ttl', 5 * MINUTE_IN_SECONDS, $snapshot);

    if ($cache_ttl > 0) {
        set_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT, $snapshot, $cache_ttl);
    }

    return $snapshot;
}

/**
 * Converts a PHP memory_limit ini value into bytes.
 *
 * @param mixed $memory_limit_ini Raw ini value.
 * @return int|null
 */
function sitepulse_resource_monitor_normalize_memory_limit_bytes($memory_limit_ini) {
    if ($memory_limit_ini === false) {
        return null;
    }

    $memory_limit_value = trim((string) $memory_limit_ini);

    if ($memory_limit_value === '') {
        return null;
    }

    $memory_limit_lower = strtolower($memory_limit_value);

    if (
        $memory_limit_lower === '-1'
        || $memory_limit_lower === 'unlimited'
        || (float) $memory_limit_value === -1.0
    ) {
        return null;
    }

    if (function_exists('wp_convert_hr_to_bytes')) {
        $bytes = wp_convert_hr_to_bytes($memory_limit_value);

        if (is_numeric($bytes) && (int) $bytes > 0) {
            return (int) $bytes;
        }
    }

    if (is_numeric($memory_limit_value)) {
        $numeric = (float) $memory_limit_value;

        if ($numeric > 0) {
            return (int) $numeric;
        }
    }

    return null;
}
