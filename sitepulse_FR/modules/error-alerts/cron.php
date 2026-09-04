<?php
/**
 * SitePulse Error Alerts cron checks.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the cron schedule used by the error alerts module.
 *
 * @param array $schedules Existing cron schedules.
 *
 * @return array Modified cron schedules.
 */
function sitepulse_error_alerts_register_cron_schedule($schedules) {
    global $sitepulse_error_alerts_schedule;

    $interval_minutes = sitepulse_error_alerts_get_interval_minutes();
    $schedule_slug    = sitepulse_error_alerts_get_schedule_slug($interval_minutes);
    $sitepulse_error_alerts_schedule = $schedule_slug;

    if (!isset($schedules[$schedule_slug])) {
        $minute_in_seconds = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $default_interval  = $interval_minutes * $minute_in_seconds;
        $allowed_minutes   = function_exists('sitepulse_get_alert_interval_choices') ? sitepulse_get_alert_interval_choices('cron') : [5];
        $minimum_minutes   = min($allowed_minutes);
        $minimum_interval  = max(1, $minimum_minutes) * $minute_in_seconds;
        $interval          = (int) apply_filters('sitepulse_error_alerts_cron_interval_seconds', $default_interval);

        if ($interval < $minimum_interval) {
            $interval = $minimum_interval;
        }

        $schedules[$schedule_slug] = [
            'interval' => $interval,
            'display'  => sprintf(__('SitePulse Error Alerts (Every %d Minutes)', 'sitepulse'), $interval_minutes),
        ];
    }

    return $schedules;
}

/**
 * Triggers all error alert checks when the cron event runs.
 *
 * @return void
 */
function sitepulse_error_alerts_run_checks() {
    sitepulse_error_alerts_check_cpu_load();
    sitepulse_error_alerts_check_debug_log();

    if (function_exists('sitepulse_register_alert_activity_check')) {
        sitepulse_register_alert_activity_check();
    }
}

/**
 * Evaluates the server load and sends an alert when the threshold is exceeded.
 *
 * @return void
 */
function sitepulse_error_alerts_check_cpu_load() {
    if (!sitepulse_error_alerts_is_channel_enabled('cpu')) {
        return;
    }

    if (!function_exists('sys_getloadavg')) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('sys_getloadavg is unavailable; CPU alert skipped.', 'WARNING');
        }

        return;
    }

    $load = sys_getloadavg();
    if (has_filter('sitepulse_error_alerts_cpu_load')) {
        $load = apply_filters('sitepulse_error_alerts_cpu_load', $load);
    }

    if (!is_array($load) || !isset($load[0])) {
        return;
    }

    $threshold = sitepulse_error_alert_get_cpu_threshold();
    $core_count = sitepulse_error_alert_get_cpu_core_count();
    $core_count = max(1, (int) $core_count);

    $normalized_load   = (float) $load[0] / $core_count;
    $total_threshold   = $threshold * $core_count;

    if ((float) $load[0] > $total_threshold) {
        $raw_site_name = get_bloginfo('name');
        $site_name     = trim(wp_strip_all_tags((string) $raw_site_name));

        /* translators: %s: Site title. */
        $subject = sprintf(
            __('SitePulse Alert: High Server Load on %s', 'sitepulse'),
            $site_name
        );

        $subject = sanitize_text_field($subject);

        /*
         * translators:
         * %1$s: Site title.
         * %2$s: Current server load.
         * %3$d: Detected CPU cores.
         * %4$s: Total load threshold.
         * %5$s: Load per core.
         * %6$s: Threshold per core.
         */
        $message = sprintf(
            esc_html__('Current server load on %1$s: %2$s (detected cores: %3$d, total threshold: %4$s, load per core: %5$s, threshold per core: %6$s)', 'sitepulse'),
            $site_name,
            number_format_i18n((float) $load[0], 2),
            $core_count,
            number_format_i18n($total_threshold, 2),
            number_format_i18n($normalized_load, 2),
            number_format_i18n($threshold, 2)
        );

        $message = sanitize_textarea_field($message);

        sitepulse_error_alert_send('cpu', $subject, $message, 'warning', [
            'cpu_load'      => (float) $load[0],
            'cpu_threshold' => $total_threshold,
            'cpu_cores'     => $core_count,
        ]);
    }
}

/**
 * Scans the WordPress debug log to detect fatal errors.
 *
 * @return void
 */
function sitepulse_error_alerts_check_debug_log() {
    $fatal_threshold = sitepulse_error_alert_get_php_fatal_threshold();
    $channel_enabled = sitepulse_error_alerts_is_channel_enabled('php_fatal');
    $fatal_count     = 0;

    if (!function_exists('sitepulse_get_wp_debug_log_path')) {
        return;
    }

    $log_file = sitepulse_get_wp_debug_log_path();

    if ($log_file === null) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('WP_DEBUG_LOG est désactivé; analyse du journal ignorée.', 'NOTICE');
        }

        return;
    }

    if (!file_exists($log_file)) {
        return;
    }

    if (!is_readable($log_file)) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log(sprintf('Impossible de lire %s pour l’analyse des erreurs.', $log_file), 'ERROR');
        }

        return;
    }

    $pointer_data = get_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, []);

    if (!is_array($pointer_data)) {
        $pointer_data = [];
    }

    $stored_offset = isset($pointer_data['offset']) ? (int) $pointer_data['offset'] : 0;
    $stored_inode  = isset($pointer_data['inode']) ? (int) $pointer_data['inode'] : null;

    $inode     = function_exists('fileinode') ? @fileinode($log_file) : null;
    $file_size = @filesize($log_file);

    if ($file_size === false) {
        return;
    }

    $offset           = max(0, $stored_offset);
    $offset_adjusted  = false;
    $truncate_partial = false;

    if (is_int($stored_inode) && is_int($inode) && $inode !== $stored_inode) {
        $offset          = 0;
        $offset_adjusted = true;
    }

    if ($offset > $file_size) {
        $offset          = 0;
        $offset_adjusted = true;
    }

    $max_scan_bytes = (int) apply_filters('sitepulse_error_alerts_max_log_scan_bytes', 131072);

    if ($offset === 0 && $file_size > $max_scan_bytes && $max_scan_bytes > 0) {
        $offset          = $file_size - $max_scan_bytes;
        $offset_adjusted = true;
        $truncate_partial = true;
    }

    $handle = fopen($log_file, 'rb');

    if (false === $handle) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log(sprintf('Impossible d’ouvrir %s pour lecture.', $log_file), 'ERROR');
        }

        return;
    }

    if ($offset > 0) {
        fseek($handle, $offset);
    }

    $bytes_to_read = $file_size - $offset;

    if ($max_scan_bytes > 0) {
        $bytes_to_read = min($bytes_to_read, $max_scan_bytes);
    }

    $log_contents = $bytes_to_read > 0 ? stream_get_contents($handle, $bytes_to_read) : '';
    $new_offset   = ftell($handle);

    fclose($handle);

    if ($new_offset === false) {
        $new_offset = $offset + strlen((string) $log_contents);
    }

    $new_pointer_data = [
        'offset'     => (int) $new_offset,
        'inode'      => is_int($inode) ? $inode : null,
        'updated_at' => time(),
    ];

    if (!is_string($log_contents) || $log_contents === '') {
        update_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, $new_pointer_data, false);

        return;
    }

    $log_lines = preg_split('/\r\n|\r|\n/', $log_contents);

    if (!empty($log_lines) && end($log_lines) === '') {
        array_pop($log_lines);
    }

    if (!empty($log_lines) && (($offset_adjusted && $offset > 0) || $truncate_partial)) {
        array_shift($log_lines);
    }

    foreach ($log_lines as $log_line) {
        $has_fatal_error = false;

        if (function_exists('sitepulse_log_line_contains_fatal_error')) {
            $has_fatal_error = sitepulse_log_line_contains_fatal_error($log_line);
        } elseif (stripos($log_line, 'PHP Fatal error') !== false) {
            $has_fatal_error = true;
        }

        if ($has_fatal_error) {
            $fatal_count++;

            if (!$channel_enabled) {
                continue;
            }

            if ($fatal_count < $fatal_threshold) {
                continue;
            }

            $raw_site_name = get_bloginfo('name');
            $site_name     = trim(wp_strip_all_tags((string) $raw_site_name));

            $log_file_for_message = '';

            if (is_string($log_file)) {
                $normalized_log_file = function_exists('wp_normalize_path')
                    ? wp_normalize_path($log_file)
                    : str_replace('\\', '/', $log_file);

                $log_file_for_message = sanitize_textarea_field($normalized_log_file);
            }

            /* translators: %s: Site title. */
            $subject = sprintf(
                __('SitePulse Alert: Fatal Error Detected on %s', 'sitepulse'),
                $site_name
            );

            $subject = sanitize_text_field($subject);

            /* translators: 1: Log file path. 2: Site title. 3: Number of fatal errors detected. */
            $message = sprintf(
                esc_html__('Au moins %3$d nouvelles erreurs fatales ont été détectées dans %1$s pour %2$s. Consultez ce fichier pour plus de détails.', 'sitepulse'),
                $log_file_for_message,
                $site_name,
                (int) $fatal_count
            );

            $message = sanitize_textarea_field($message);

            sitepulse_error_alert_send('php_fatal', $subject, $message, 'critical', [
                'fatal_count' => (int) $fatal_count,
                'log_file'    => $log_file_for_message,
            ]);
            break;
        }
    }

    update_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, $new_pointer_data, false);
}

/**
 * Handles rescheduling when the alert interval option is updated.
 *
 * @param mixed            $old_value Previous value.
 * @param mixed            $value     New value.
 * @param string|int|null  $option    Option name. Unused.
 * @return void
 */
function sitepulse_error_alerts_on_interval_update($old_value, $value, $option = null) {
    global $sitepulse_error_alerts_cron_hook, $sitepulse_error_alerts_schedule;

    if (empty($sitepulse_error_alerts_cron_hook)) {
        return;
    }

    $sitepulse_error_alerts_schedule = sitepulse_error_alerts_get_schedule_slug($value);

    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook($sitepulse_error_alerts_cron_hook);
    } else {
        $timestamp = wp_next_scheduled($sitepulse_error_alerts_cron_hook);

        while ($timestamp) {
            wp_unschedule_event($timestamp, $sitepulse_error_alerts_cron_hook);
            $timestamp = wp_next_scheduled($sitepulse_error_alerts_cron_hook);
        }
    }

    sitepulse_error_alerts_schedule_cron_hook();
}

/**
 * Ensures the error alert cron hook is scheduled and reports failures.
 *
 * @return void
 */
function sitepulse_error_alerts_schedule_cron_hook() {
    global $sitepulse_error_alerts_cron_hook, $sitepulse_error_alerts_schedule;

    if (empty($sitepulse_error_alerts_cron_hook)) {
        return;
    }

    if (!wp_next_scheduled($sitepulse_error_alerts_cron_hook)) {
        $scheduled = wp_schedule_event(time(), $sitepulse_error_alerts_schedule, $sitepulse_error_alerts_cron_hook);

        if (false === $scheduled && function_exists('sitepulse_log')) {
            sitepulse_log(sprintf('Unable to schedule error alert cron hook: %s', $sitepulse_error_alerts_cron_hook), 'ERROR');
        }
    }

    if (!wp_next_scheduled($sitepulse_error_alerts_cron_hook)) {
        sitepulse_register_cron_warning(
            'error_alerts',
            __('SitePulse n’a pas pu programmer les alertes d’erreurs. Vérifiez la configuration de WP-Cron.', 'sitepulse')
        );
    } else {
        sitepulse_clear_cron_warning('error_alerts');
    }
}

/**
 * Initializes the cron schedule during WordPress bootstrap.
 *
 * @return void
 */
function sitepulse_error_alerts_ensure_cron() {
    global $sitepulse_error_alerts_schedule;

    $sitepulse_error_alerts_schedule = sitepulse_error_alerts_get_schedule_slug();

    sitepulse_error_alerts_schedule_cron_hook();
}
