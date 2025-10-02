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
     * @param string $prefix Transient prefix to match.
     * @return void
     */
    function sitepulse_delete_transients_by_prefix($prefix) {
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

        $transient_prefix = strlen('_transient_');

        foreach ($option_names as $option_name) {
            $transient_key = substr($option_name, $transient_prefix);

            if ($transient_key !== '') {
                delete_transient($transient_key);
            }
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
     * @return array{warning:int,critical:int}
     */
    function sitepulse_get_speed_thresholds() {
        $default_warning = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
        $default_critical = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;

        $option_warning_key = defined('SITEPULSE_OPTION_SPEED_WARNING_MS') ? SITEPULSE_OPTION_SPEED_WARNING_MS : 'sitepulse_speed_warning_ms';
        $option_critical_key = defined('SITEPULSE_OPTION_SPEED_CRITICAL_MS') ? SITEPULSE_OPTION_SPEED_CRITICAL_MS : 'sitepulse_speed_critical_ms';

        $warning_value = get_option($option_warning_key, $default_warning);
        $critical_value = get_option($option_critical_key, $default_critical);

        $warning_ms = is_scalar($warning_value) ? (int) $warning_value : 0;
        $critical_ms = is_scalar($critical_value) ? (int) $critical_value : 0;

        if ($warning_ms <= 0) {
            $warning_ms = $default_warning;
        }

        if ($critical_ms <= 0) {
            $critical_ms = $default_critical;
        }

        if ($critical_ms <= $warning_ms) {
            $critical_ms = max($warning_ms + 1, $default_critical);
        }

        return [
            'warning'  => $warning_ms,
            'critical' => $critical_ms,
        ];
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
                "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                '_site_transient_' . $like
            )
        );

        if (empty($meta_keys)) {
            return;
        }

        $site_transient_prefix = strlen('_site_transient_');

        foreach ($meta_keys as $meta_key) {
            $transient_key = substr($meta_key, $site_transient_prefix);

            if ($transient_key !== '') {
                delete_site_transient($transient_key);
            }
        }
    }
}

if (!function_exists('sitepulse_get_ai_models')) {
    /**
     * Returns the list of supported AI models.
     *
     * @return array<string, array{label:string,description?:string,prompt_instruction?:string}>
     */
    function sitepulse_get_ai_models() {
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

        if (function_exists('apply_filters')) {
            $filtered_models = apply_filters('sitepulse_ai_models', $default_models);

            if (is_array($filtered_models) && !empty($filtered_models)) {
                $sanitized_models = [];

                foreach ($filtered_models as $model_key => $model_data) {
                    if (!is_string($model_key) || $model_key === '') {
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

                if (!empty($sanitized_models)) {
                    return $sanitized_models;
                }
            }
        }

        return $default_models;
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
     * @param string $file_path  Path to the log file.
     * @param int    $max_lines  Number of lines to return.
     * @param int    $max_bytes  Maximum number of bytes to read from the end of the file.
     * @return array|null Array of recent log lines on success, empty array if the file is empty,
     *                    or null on failure to read the file.
     */
    function sitepulse_get_recent_log_lines($file_path, $max_lines = 100, $max_bytes = 131072) {
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

        $buffer = '';
        $chunk_size = 4096;
        $stats = fstat($handle);
        $file_size = isset($stats['size']) ? (int) $stats['size'] : 0;
        $bytes_to_read = min($file_size, $max_bytes);
        $position = $file_size;

        while ($bytes_to_read > 0 && $position > 0) {
            $read_size = (int) min($chunk_size, $bytes_to_read, $position);
            if ($read_size <= 0) {
                break;
            }

            $position -= $read_size;
            $bytes_to_read -= $read_size;

            if (fseek($handle, $position, SEEK_SET) !== 0) {
                break;
            }

            $chunk = fread($handle, $read_size);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;

            if (substr_count($buffer, "\n") >= ($max_lines + 1)) {
                break;
            }
        }

        fclose($handle);

        if ($buffer === '') {
            return [];
        }

        $buffer = str_replace(["\r\n", "\r"], "\n", $buffer);
        $buffer = rtrim($buffer, "\n");

        if ($buffer === '') {
            return [];
        }

        $lines = explode("\n", $buffer);
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

        return $filtered;
    }
}

if (!function_exists('sitepulse_sanitize_alert_interval')) {
    /**
     * Sanitizes the alert interval (in minutes) used to schedule error checks.
     *
     * @param mixed $value Raw user input value.
     * @return int Sanitized interval in minutes.
     */
    function sitepulse_sanitize_alert_interval($value) {
        $allowed_values = [5, 10, 15, 30];
        $value = is_scalar($value) ? absint($value) : 0;

        if ($value < 5) {
            $value = 5;
        } elseif ($value > 30) {
            $value = 30;
        }

        if (!in_array($value, $allowed_values, true)) {
            $value = 5;
        }

        return $value;
    }
}
