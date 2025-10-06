<?php
/**
 * SitePulse shared helper functions.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sitepulse_delete_transients_by_prefix')) {
    /**
     * Deletes all transients whose names start with the provided prefix.
     *
     * The deletion is performed in batches to avoid long-running queries on
     * large `wp_options` tables. When an external object cache is active we
     * also invalidate the relevant groups to prevent ghost entries from
     * sticking around in Redis/Memcached.
     *
     * @param string     $prefix Transient prefix to match.
     * @param array|null $args   Optional arguments. Supported keys: `batch_size`.
     * @return void
     */
    function sitepulse_delete_transients_by_prefix($prefix, $args = null) {
        global $wpdb;

        if (!is_string($prefix) || $prefix === '' || !($wpdb instanceof wpdb)) {
            return;
        }

        $defaults = [
            'batch_size' => defined('SITEPULSE_TRANSIENT_DELETE_BATCH') ? (int) SITEPULSE_TRANSIENT_DELETE_BATCH : 200,
        ];

        $args = is_array($args) ? array_merge($defaults, $args) : $defaults;

        $batch_size = isset($args['batch_size']) ? (int) $args['batch_size'] : 200;
        $batch_size = max(20, $batch_size);

        if (function_exists('apply_filters')) {
            $batch_size = (int) apply_filters('sitepulse_transient_delete_batch_size', $batch_size, $prefix, $args);
        }

        if ($batch_size < 20) {
            $batch_size = 20;
        }

        $like             = $wpdb->esc_like($prefix) . '%';
        $value_prefix     = strlen('_transient_');
        $timeout_prefix   = strlen('_transient_timeout_');
        $last_option_id   = 0;
        $deleted          = 0;
        $batches          = 0;
        $object_cache_hit = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $deleted_keys     = [];

        do {
            $query = $wpdb->prepare(
                "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_id > %d AND (option_name LIKE %s OR option_name LIKE %s) ORDER BY option_id ASC LIMIT %d",
                $last_option_id,
                '_transient_' . $like,
                '_transient_timeout_' . $like,
                $batch_size
            );

            $rows = $wpdb->get_results($query, ARRAY_A);

            if (empty($rows)) {
                break;
            }

            $batch_keys = [];

            foreach ($rows as $row) {
                $option_name = isset($row['option_name']) ? (string) $row['option_name'] : '';
                $last_option_id = isset($row['option_id']) ? (int) $row['option_id'] : $last_option_id;

                if ($option_name === '') {
                    continue;
                }

                if (strpos($option_name, '_transient_timeout_') === 0) {
                    $transient_key = substr($option_name, $timeout_prefix);
                } else {
                    $transient_key = substr($option_name, $value_prefix);
                }

                if ($transient_key !== '') {
                    $batch_keys[$transient_key] = true;
                }
            }

            if (!empty($batch_keys)) {
                foreach (array_keys($batch_keys) as $transient_key) {
                    delete_transient($transient_key);

                    if ($object_cache_hit && function_exists('wp_cache_delete')) {
                        wp_cache_delete($transient_key, 'transient');
                        wp_cache_delete($transient_key, 'transient_timeout');
                        wp_cache_delete($transient_key, 'site-transient');
                        wp_cache_delete($transient_key, 'site-transient_timeout');
                    }

                    $deleted_keys[$transient_key] = true;
                }

                $deleted += count($batch_keys);
                ++$batches;

                if (function_exists('do_action')) {
                    do_action(
                        'sitepulse_transient_deletion_batch',
                        $prefix,
                        [
                            'deleted'          => count($batch_keys),
                            'batch_size'       => $batch_size,
                            'object_cache_hit' => $object_cache_hit,
                        ]
                    );
                }
            }
        } while (count($rows) === $batch_size);

        if ($deleted > 0 && function_exists('do_action')) {
            do_action(
                'sitepulse_transient_deletion_completed',
                $prefix,
                [
                    'deleted'          => $deleted,
                    'unique_keys'      => array_keys($deleted_keys),
                    'batches'          => $batches,
                    'object_cache_hit' => $object_cache_hit,
                ]
            );
        }
    }
}

if (!function_exists('sitepulse_get_gemini_api_key')) {
    /**
     * Retrieves the Gemini API key while honoring code-level overrides.
     *
     * The lookup order is:
     * 1. Constant override via SITEPULSE_GEMINI_API_KEY.
     * 2. Filter override via `sitepulse_gemini_api_key`.
     * 3. Stored option fallback.
     *
     * @return string Sanitized Gemini API key.
     */
    function sitepulse_get_gemini_api_key() {
        $api_key = '';

        if (defined('SITEPULSE_GEMINI_API_KEY')) {
            $api_key = (string) SITEPULSE_GEMINI_API_KEY;
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('sitepulse_gemini_api_key', $api_key);

            if (is_string($filtered)) {
                $api_key = $filtered;
            } elseif (is_scalar($filtered)) {
                $api_key = (string) $filtered;
            }
        }

        $api_key = trim($api_key);

        if ($api_key === '') {
            $option_value = get_option(SITEPULSE_OPTION_GEMINI_API_KEY, '');
            $api_key      = is_string($option_value) ? trim($option_value) : '';
        }

        return $api_key;
    }
}

if (!function_exists('sitepulse_is_gemini_api_key_overridden')) {
    /**
     * Determines whether the Gemini API key is overridden via code.
     *
     * @return bool
     */
    function sitepulse_is_gemini_api_key_overridden() {
        if (defined('SITEPULSE_GEMINI_API_KEY') && trim((string) SITEPULSE_GEMINI_API_KEY) !== '') {
            return true;
        }

        if (
            function_exists('has_filter')
            && function_exists('apply_filters')
            && has_filter('sitepulse_gemini_api_key')
        ) {
            $filtered = apply_filters('sitepulse_gemini_api_key', '');

            if (is_string($filtered)) {
                return trim($filtered) !== '';
            }

            if (is_scalar($filtered)) {
                return trim((string) $filtered) !== '';
            }
        }

        return false;
    }
}

if (!function_exists('sitepulse_get_speed_thresholds')) {
    /**
     * Retrieves the configured speed thresholds for warning and critical states.
     *
     * @param string $profile Optional performance profile (default, mobile, desktop...).
     * @return array{warning:int,critical:int}
     */
    function sitepulse_get_speed_thresholds($profile = 'default') {
        $profile = is_string($profile) ? strtolower(trim($profile)) : 'default';

        if ($profile === '') {
            $profile = 'default';
        }

        $default_warning  = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
        $default_critical = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;

        $profiles_option_key = defined('SITEPULSE_OPTION_SPEED_PROFILES') ? SITEPULSE_OPTION_SPEED_PROFILES : 'sitepulse_speed_profiles';
        $option_warning_key  = defined('SITEPULSE_OPTION_SPEED_WARNING_MS') ? SITEPULSE_OPTION_SPEED_WARNING_MS : 'sitepulse_speed_warning_ms';
        $option_critical_key = defined('SITEPULSE_OPTION_SPEED_CRITICAL_MS') ? SITEPULSE_OPTION_SPEED_CRITICAL_MS : 'sitepulse_speed_critical_ms';

        $profiles = get_option($profiles_option_key, []);

        if (!is_array($profiles)) {
            $profiles = [];
        }

        $raw_warning  = null;
        $raw_critical = null;

        if (isset($profiles[$profile]) && is_array($profiles[$profile])) {
            $raw_warning  = $profiles[$profile]['warning'] ?? null;
            $raw_critical = $profiles[$profile]['critical'] ?? null;
        }

        if ($raw_warning === null) {
            $raw_warning = get_option($option_warning_key, $default_warning);
        }

        if ($raw_critical === null) {
            $raw_critical = get_option($option_critical_key, $default_critical);
        }

        $warning_ms  = is_scalar($raw_warning) ? (int) $raw_warning : 0;
        $critical_ms = is_scalar($raw_critical) ? (int) $raw_critical : 0;
        $corrections = [];

        if ($warning_ms <= 0) {
            $warning_ms   = $default_warning;
            $corrections[] = 'warning_default';
        }

        if ($critical_ms <= 0) {
            $critical_ms   = $default_critical;
            $corrections[] = 'critical_default';
        }

        if ($critical_ms <= $warning_ms) {
            $critical_ms   = max($warning_ms + 1, $default_critical);
            $corrections[] = 'critical_adjusted';
        }

        $thresholds = [
            'warning'  => $warning_ms,
            'critical' => $critical_ms,
        ];

        if (function_exists('apply_filters')) {
            $thresholds = apply_filters('sitepulse_speed_thresholds', $thresholds, $profile, $corrections);
        }

        if (!empty($corrections)) {
            if (function_exists('do_action')) {
                do_action('sitepulse_speed_threshold_corrected', $profile, $thresholds, $corrections);
            }

            if (function_exists('sitepulse_log')) {
                sitepulse_log(sprintf('Speed thresholds corrected for profile %s (%s)', $profile, implode(', ', $corrections)), 'WARNING');
            }
        }

        return $thresholds;
    }
}

if (!function_exists('sitepulse_get_speed_warning_threshold')) {
    /**
     * Returns the configured warning speed threshold in milliseconds.
     *
     * @return int
     */
    function sitepulse_get_speed_warning_threshold() {
        $thresholds = sitepulse_get_speed_thresholds();

        return isset($thresholds['warning']) ? (int) $thresholds['warning'] : 0;
    }
}

if (!function_exists('sitepulse_get_speed_critical_threshold')) {
    /**
     * Returns the configured critical speed threshold in milliseconds.
     *
     * @return int
     */
    function sitepulse_get_speed_critical_threshold() {
        $thresholds = sitepulse_get_speed_thresholds();

        return isset($thresholds['critical']) ? (int) $thresholds['critical'] : 0;
    }
}

if (!function_exists('sitepulse_get_uptime_warning_percentage')) {
    /**
     * Returns the uptime warning threshold as a percentage.
     *
     * @return float
     */
    function sitepulse_get_uptime_warning_percentage() {
        $default_percentage = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0;
        $option_key = defined('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT') ? SITEPULSE_OPTION_UPTIME_WARNING_PERCENT : 'sitepulse_uptime_warning_percent';
        $value = get_option($option_key, $default_percentage);

        if (!is_scalar($value)) {
            return $default_percentage;
        }

        $percentage = (float) $value;

        if ($percentage <= 0) {
            return $default_percentage;
        }

        if ($percentage > 100) {
            return 100.0;
        }

        return $percentage;
    }
}

if (!function_exists('sitepulse_get_revision_limit')) {
    /**
     * Returns the configured revision limit used for database health checks.
     *
     * @return int
     */
    function sitepulse_get_revision_limit() {
        $default_limit = defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? (int) SITEPULSE_DEFAULT_REVISION_LIMIT : 100;
        $option_key = defined('SITEPULSE_OPTION_REVISION_LIMIT') ? SITEPULSE_OPTION_REVISION_LIMIT : 'sitepulse_revision_limit';
        $value = get_option($option_key, $default_limit);

        if (!is_scalar($value)) {
            return $default_limit;
        }

        $limit = (int) $value;

        if ($limit < 1) {
            return $default_limit;
        }

        return $limit;
    }
}

if (!function_exists('sitepulse_delete_site_transients_by_prefix')) {
    /**
     * Deletes all site transients whose names start with the provided prefix.
     *
     * @param string $prefix Site transient prefix to match.
     * @return void
     */
    function sitepulse_delete_site_transients_by_prefix($prefix) {
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
                "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
                '_site_transient_' . $like,
                '_site_transient_timeout_' . $like
            )
        );

        if (empty($meta_keys)) {
            return;
        }

        $transient_keys = [];
        $value_prefix   = strlen('_site_transient_');
        $timeout_prefix = strlen('_site_transient_timeout_');

        foreach ($meta_keys as $meta_key) {
            if (strpos($meta_key, '_site_transient_timeout_') === 0) {
                $transient_key = substr($meta_key, $timeout_prefix);
            } else {
                $transient_key = substr($meta_key, $value_prefix);
            }

            if ($transient_key !== '') {
                $transient_keys[$transient_key] = true;
            }
        }

        foreach (array_keys($transient_keys) as $transient_key) {
            delete_site_transient($transient_key);
        }
    }
}

if (!function_exists('sitepulse_get_ai_models')) {
    /**
     * Returns the list of supported AI models.
     *
     * The catalog is cached per-request and optionally persisted in a transient
     * to avoid running heavy filters on every call.
     *
     * @return array<string, array{label:string,description?:string,prompt_instruction?:string}>
     */
    function sitepulse_get_ai_models() {
        static $runtime_cache = null;

        if (is_array($runtime_cache)) {
            return $runtime_cache;
        }

        $default_models = [
            'gemini-1.5-flash' => [
                'label'              => __('Gemini 1.5 Flash', 'sitepulse'),
                'description'        => __('Réponses rapides et économiques, idéales pour obtenir des recommandations synthétiques à fréquence élevée.', 'sitepulse'),
                'prompt_instruction' => __('Fournis une synthèse claire et actionnable en te concentrant sur les gains rapides.', 'sitepulse'),
            ],
            'gemini-1.5-pro'   => [
                'label'              => __('Gemini 1.5 Pro', 'sitepulse'),
                'description'        => __('Analyse plus approfondie avec davantage de contexte et de détails, adaptée aux audits complets mais plus lente et coûteuse.', 'sitepulse'),
                'prompt_instruction' => __('Apporte une analyse détaillée et justifie chaque recommandation avec les impacts attendus.', 'sitepulse'),
            ],
        ];

        $use_cache    = function_exists('apply_filters') ? (bool) apply_filters('sitepulse_ai_models_enable_cache', true) : true;
        $transient_id = 'sitepulse_ai_models_catalog';

        if ($use_cache && function_exists('get_transient')) {
            $cached_models = get_transient($transient_id);

            if (is_array($cached_models) && !empty($cached_models)) {
                $runtime_cache = $cached_models;

                return $cached_models;
            }
        }

        $sanitized_models = [];
        $filtered_models  = function_exists('apply_filters') ? apply_filters('sitepulse_ai_models', $default_models) : $default_models;

        if (!is_array($filtered_models) || empty($filtered_models)) {
            $filtered_models = $default_models;
        }

        foreach ($filtered_models as $model_key => $model_data) {
            if (!is_string($model_key) || $model_key === '') {
                continue;
            }

            $model_key = trim($model_key);

            if ($model_key === '' || strlen($model_key) > 120) {
                continue;
            }

            if (is_string($model_data)) {
                $model_data = ['label' => $model_data];
            }

            if (!is_array($model_data)) {
                continue;
            }

            $label = isset($model_data['label']) ? (string) $model_data['label'] : '';

            if ($label === '') {
                $label = $model_key;
            }

            $sanitized_models[$model_key] = [
                'label' => $label,
            ];

            if (isset($model_data['description']) && is_string($model_data['description']) && $model_data['description'] !== '') {
                $sanitized_models[$model_key]['description'] = $model_data['description'];
            }

            if (isset($model_data['prompt_instruction']) && is_string($model_data['prompt_instruction']) && $model_data['prompt_instruction'] !== '') {
                $sanitized_models[$model_key]['prompt_instruction'] = $model_data['prompt_instruction'];
            }
        }

        if (empty($sanitized_models)) {
            $sanitized_models = $default_models;
        }

        if ($use_cache && function_exists('set_transient')) {
            $ttl = function_exists('apply_filters') ? (int) apply_filters('sitepulse_ai_models_cache_ttl', HOUR_IN_SECONDS, $sanitized_models) : HOUR_IN_SECONDS;

            if ($ttl > 0) {
                set_transient($transient_id, $sanitized_models, $ttl);
            }
        }

        $runtime_cache = $sanitized_models;

        if (function_exists('apply_filters')) {
            $runtime_cache = apply_filters('sitepulse_ai_models_sanitized', $runtime_cache);
        }

        return $runtime_cache;
    }
}

if (!function_exists('sitepulse_get_default_ai_model')) {
    /**
     * Returns the default AI model identifier.
     *
     * @return string
     */
    function sitepulse_get_default_ai_model() {
        $default = defined('SITEPULSE_DEFAULT_AI_MODEL') ? (string) SITEPULSE_DEFAULT_AI_MODEL : 'gemini-1.5-flash';
        $models  = sitepulse_get_ai_models();

        if (isset($models[$default])) {
            return $default;
        }

        $model_keys = array_keys($models);

        if (!empty($model_keys)) {
            return (string) $model_keys[0];
        }

        return 'gemini-1.5-flash';
    }
}

if (!function_exists('sitepulse_get_recent_log_lines')) {
    /**
     * Reads the last lines from a log file without loading it entirely in memory.
     *
     * The maximum number of bytes read is deliberately capped to avoid memory pressure
     * with very large log files.
     *
     * @param string $file_path       Path to the log file.
     * @param int    $max_lines       Number of lines to return.
     * @param int    $max_bytes       Maximum number of bytes to read from the end of the file.
     * @param bool   $with_metadata   Whether to include metadata (bytes read, truncation flag, etc.).
     * @return array|null Array of recent log lines on success, empty array if the file is empty,
     *                    or null on failure to read the file. When `$with_metadata` is true an
     *                    associative array is returned with the keys `lines`, `bytes_read`,
     *                    `file_size`, `truncated` and `last_modified`.
     */
    function sitepulse_get_recent_log_lines($file_path, $max_lines = 100, $max_bytes = 131072, $with_metadata = false) {
        $max_lines = max(1, (int) $max_lines);
        $max_bytes = max(1024, (int) $max_bytes);

        if (!is_readable($file_path)) {
            return null;
        }

        $fopen_error = null;
        set_error_handler(function ($errno, $errstr) use (&$fopen_error) {
            $fopen_error = $errstr;

            return true;
        });

        try {
            $handle = fopen($file_path, 'rb');
        } catch (\Throwable $exception) {
            $fopen_error = $exception->getMessage();
            $handle = false;
        } finally {
            restore_error_handler();
        }

        if (!$handle) {
            if (function_exists('sitepulse_log')) {
                $message = sprintf('Failed to open log file for reading: %s', $file_path);

                if (is_string($fopen_error) && $fopen_error !== '') {
                    $message .= ' | Error: ' . $fopen_error;
                }

                sitepulse_log($message, 'ERROR');
            }

            return null;
        }

        $locked = false;

        if (function_exists('flock')) {
            $locked = @flock($handle, LOCK_SH);
        }

        $buffer          = '';
        $chunk_size      = 4096;
        $stats           = fstat($handle);
        $file_size       = isset($stats['size']) ? (int) $stats['size'] : 0;
        $bytes_to_read   = min($file_size, $max_bytes);
        $position        = $file_size;
        $bytes_read      = 0;
        $max_iterations  = 500;
        $iterations      = 0;

        while ($bytes_to_read > 0 && $position > 0) {
            if (++$iterations > $max_iterations) {
                break;
            }

            $read_size = (int) min($chunk_size, $bytes_to_read, $position);

            if ($read_size <= 0) {
                break;
            }

            $position     -= $read_size;
            $bytes_to_read -= $read_size;

            if (fseek($handle, $position, SEEK_SET) !== 0) {
                break;
            }

            $chunk = fread($handle, $read_size);

            if ($chunk === false) {
                break;
            }

            $bytes_read += strlen($chunk);
            $buffer      = $chunk . $buffer;

            if (substr_count($buffer, "\n") >= ($max_lines + 1)) {
                break;
            }
        }

        if ($locked) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        if ($buffer === '') {
            return $with_metadata ? [
                'lines'         => [],
                'bytes_read'    => $bytes_read,
                'file_size'     => $file_size,
                'truncated'     => false,
                'last_modified' => isset($stats['mtime']) ? (int) $stats['mtime'] : null,
            ] : [];
        }

        $buffer = str_replace(["\r\n", "\r"], "\n", $buffer);
        $buffer = rtrim($buffer, "\n");

        if ($buffer === '') {
            return $with_metadata ? [
                'lines'         => [],
                'bytes_read'    => $bytes_read,
                'file_size'     => $file_size,
                'truncated'     => false,
                'last_modified' => isset($stats['mtime']) ? (int) $stats['mtime'] : null,
            ] : [];
        }

        $lines    = explode("\n", $buffer);
        $filtered = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $filtered[] = $line;
        }

        if (count($filtered) > $max_lines) {
            $filtered = array_slice($filtered, -$max_lines);
        }

        if (!$with_metadata) {
            return $filtered;
        }

        $truncated = $file_size > $bytes_read;

        return [
            'lines'         => $filtered,
            'bytes_read'    => $bytes_read,
            'file_size'     => $file_size,
            'truncated'     => $truncated,
            'last_modified' => isset($stats['mtime']) ? (int) $stats['mtime'] : null,
        ];
    }
}

if (!function_exists('sitepulse_get_alert_interval_choices')) {
    /**
     * Returns the allowed alert interval values (in minutes).
     *
     * @param mixed $context Optional context, forwarded to the filter hook.
     * @return int[]
     */
    function sitepulse_get_alert_interval_choices($context = null) {
        $allowed_values = [1, 2, 5, 10, 15, 30, 60, 120];

        if (function_exists('apply_filters')) {
            $allowed_values = apply_filters('sitepulse_alert_interval_allowed_values', $allowed_values, $context);
        }

        $allowed_values = array_map('absint', (array) $allowed_values);
        $allowed_values = array_values(array_filter($allowed_values, static function ($value) {
            return $value > 0;
        }));

        sort($allowed_values, SORT_NUMERIC);

        if (empty($allowed_values)) {
            $allowed_values = [5];
        }

        return $allowed_values;
    }
}

if (!function_exists('sitepulse_sanitize_alert_interval')) {
    /**
     * Sanitizes the alert interval (in minutes) used to schedule error checks.
     *
     * Supports extended ranges and an optional "smart" mode that can be
     * interpreted by integrations via the `sitepulse_alert_interval_smart_value`
     * filter.
     *
     * @param mixed $value Raw user input value.
     * @return int Sanitized interval in minutes.
     */
    function sitepulse_sanitize_alert_interval($value) {
        $raw_value      = $value;
        $allowed_values = sitepulse_get_alert_interval_choices($raw_value);
        $default_value  = min($allowed_values);

        if (is_string($value) && !is_numeric($value)) {
            $candidate = strtolower(trim($value));

            if ($candidate === 'smart') {
                $smart_value = $default_value;

                if (function_exists('apply_filters')) {
                    $smart_value = (int) apply_filters('sitepulse_alert_interval_smart_value', $default_value, $allowed_values, $raw_value);
                }

                if (in_array($smart_value, $allowed_values, true)) {
                    $value = $smart_value;
                } else {
                    $value = $default_value;
                }
            } else {
                $value = $default_value;
            }
        } else {
            $value = is_scalar($value) ? absint($value) : 0;
        }

        if ($value <= 0) {
            $value = $default_value;
        }

        if (!in_array($value, $allowed_values, true)) {
            $value = sitepulse_find_closest_allowed_interval($value, $allowed_values, $default_value);
        }

        if (function_exists('apply_filters')) {
            $value = (int) apply_filters('sitepulse_alert_interval_sanitized', $value, $allowed_values, $raw_value);
        }

        if ($value <= 0) {
            $value = $default_value;
        }

        return $value;
    }
}

if (!function_exists('sitepulse_find_closest_allowed_interval')) {
    /**
     * Finds the closest allowed interval to the provided value.
     *
     * @param int   $value          Input value.
     * @param array $allowed_values Sorted array of allowed values.
     * @param int   $default_value  Fallback value.
     * @return int
     */
    function sitepulse_find_closest_allowed_interval($value, $allowed_values, $default_value) {
        if (empty($allowed_values)) {
            return $default_value;
        }

        foreach ($allowed_values as $allowed_value) {
            if ($value <= $allowed_value) {
                return (int) $allowed_value;
            }
        }

        return (int) end($allowed_values);
    }
}
