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
