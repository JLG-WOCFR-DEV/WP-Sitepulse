<?php
if (!defined('ABSPATH')) {
    exit;
}

$sitepulse_error_alerts_cron_hook = function_exists('sitepulse_get_cron_hook') ? sitepulse_get_cron_hook('error_alerts') : 'sitepulse_error_alerts_cron';
$sitepulse_error_alerts_schedule   = 'sitepulse_error_alerts_five_minutes';

/**
 * Normalizes the alert interval to one of the supported values.
 *
 * @param mixed $value Raw value to sanitize.
 * @return int Sanitized interval in minutes.
 */
function sitepulse_error_alerts_sanitize_interval($value) {
    $allowed = [5, 10, 15, 30];
    $value   = is_scalar($value) ? absint($value) : 0;

    if ($value < 5) {
        $value = 5;
    } elseif ($value > 30) {
        $value = 30;
    }

    if (!in_array($value, $allowed, true)) {
        $value = 5;
    }

    return $value;
}

/**
 * Retrieves the interval (in minutes) configured for the alert checks.
 *
 * @return int
 */
function sitepulse_error_alerts_get_interval_minutes() {
    $stored_value = get_option(SITEPULSE_OPTION_ALERT_INTERVAL, 5);

    if (function_exists('sitepulse_sanitize_alert_interval')) {
        return sitepulse_sanitize_alert_interval($stored_value);
    }

    return sitepulse_error_alerts_sanitize_interval($stored_value);
}

/**
 * Builds the cron schedule slug based on the configured interval.
 *
 * @param int|null $minutes Interval override (optional).
 * @return string
 */
function sitepulse_error_alerts_get_schedule_slug($minutes = null) {
    $minutes = $minutes === null
        ? sitepulse_error_alerts_get_interval_minutes()
        : sitepulse_error_alerts_sanitize_interval($minutes);

    return 'sitepulse_error_alerts_' . $minutes . '_minutes';
}

$sitepulse_error_alerts_schedule = sitepulse_error_alerts_get_schedule_slug();

/**
 * Returns the configured CPU load threshold for alerting.
 *
 * @return float
 */
function sitepulse_error_alert_get_cpu_threshold() {
    $threshold = get_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 5);
    if (!is_numeric($threshold)) {
        $threshold = 5;
    }

    $threshold = (float) $threshold;
    if ($threshold <= 0) {
        $threshold = 5;
    }

    return $threshold;
}

/**
 * Returns the throttling window (in seconds) for alert e-mails.
 *
 * @return int
 */
function sitepulse_error_alert_get_cooldown() {
    $cooldown_minutes = get_option(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES, 60);
    if (!is_numeric($cooldown_minutes)) {
        $cooldown_minutes = 60;
    }

    $cooldown_minutes = (int) $cooldown_minutes;
    if ($cooldown_minutes < 1) {
        $cooldown_minutes = 60;
    }

    $minute_in_seconds = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;

    return $cooldown_minutes * $minute_in_seconds;
}

/**
 * Retrieves the list of e-mail recipients for error alerts.
 *
 * @return string[] Sanitized list of e-mail addresses.
 */
function sitepulse_error_alert_get_recipients() {
    $stored_recipients = get_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, []);

    if (!is_array($stored_recipients)) {
        if (is_string($stored_recipients) && $stored_recipients !== '') {
            $stored_recipients = preg_split('/[\r\n,]+/', $stored_recipients);
        } else {
            $stored_recipients = [];
        }
    }

    $admin_email = get_option('admin_email');

    if (is_email($admin_email)) {
        $stored_recipients[] = $admin_email;
    }

    $normalized = [];

    foreach ((array) $stored_recipients as $email) {
        if (!is_string($email)) {
            continue;
        }

        $email = trim($email);
        if ($email === '') {
            continue;
        }

        $sanitized = sanitize_email($email);
        if ($sanitized !== '' && is_email($sanitized)) {
            $normalized[] = $sanitized;
        }
    }

    $normalized = array_values(array_unique($normalized));

    $filtered = apply_filters('sitepulse_error_alert_recipients', $normalized);

    if (!is_array($filtered)) {
        $filtered = is_string($filtered) && $filtered !== '' ? [$filtered] : [];
    }

    $final_recipients = [];

    foreach ($filtered as $email) {
        if (!is_string($email)) {
            continue;
        }

        $email = trim($email);
        if ($email === '') {
            continue;
        }

        $sanitized = sanitize_email($email);
        if ($sanitized !== '' && is_email($sanitized)) {
            $final_recipients[] = $sanitized;
        }
    }

    return array_values(array_unique($final_recipients));
}

/**
 * Attempts to send an alert message while respecting the cooldown lock.
 *
 * @param string $type    Unique identifier of the alert type.
 * @param string $subject Mail subject.
 * @param string $message Mail body.
 * @return bool True if the e-mail was dispatched, false otherwise.
 */
function sitepulse_error_alert_send($type, $subject, $message) {
    $lock_key = SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . sanitize_key($type) . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX;

    if (false !== get_transient($lock_key)) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log("Alert '$type' skipped due to active cooldown.");
        }
        return false;
    }

    $recipients = sitepulse_error_alert_get_recipients();

    if (empty($recipients)) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log("Alert '$type' skipped because no valid recipients were found.", 'ERROR');
        }

        return false;
    }

    $sent = wp_mail($recipients, $subject, $message);

    if ($sent) {
        set_transient($lock_key, time(), sitepulse_error_alert_get_cooldown());
        if (function_exists('sitepulse_log')) {
            sitepulse_log("Alert '$type' e-mail sent and cooldown applied.");
        }
    } elseif (function_exists('sitepulse_log')) {
        sitepulse_log("Alert '$type' e-mail failed to send.", 'ERROR');
    }

    return $sent;
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
        $minimum_interval  = 5 * $minute_in_seconds;
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
}

/**
 * Evaluates the server load and sends an alert when the threshold is exceeded.
 *
 * @return void
 */
function sitepulse_error_alerts_check_cpu_load() {
    if (!function_exists('sys_getloadavg')) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('sys_getloadavg is unavailable; CPU alert skipped.', 'WARNING');
        }

        return;
    }

    $load = sys_getloadavg();
    if (!is_array($load) || !isset($load[0])) {
        return;
    }

    $threshold = sitepulse_error_alert_get_cpu_threshold();

    if ((float) $load[0] > $threshold) {
        $message = "La charge actuelle est de : " . $load[0] . " (seuil : " . $threshold . ")";
        sitepulse_error_alert_send('cpu', 'Alerte SitePulse: Charge Serveur Élevée', $message);
    }
}

/**
 * Scans the WordPress debug log to detect fatal errors.
 *
 * @return void
 */
function sitepulse_error_alerts_check_debug_log() {
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

    if (!function_exists('sitepulse_get_recent_log_lines')) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('sitepulse_get_recent_log_lines() is unavailable; log scan skipped.', 'ERROR');
        }

        return;
    }

    $recent_log_lines = sitepulse_get_recent_log_lines($log_file, 250, 65536);

    if ($recent_log_lines === null) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log(sprintf('Impossible de lire %s pour l’analyse des erreurs.', $log_file), 'ERROR');
        }

        return;
    }

    foreach ($recent_log_lines as $log_line) {
        if (stripos($log_line, 'PHP Fatal error') !== false) {
            sitepulse_error_alert_send(
                'php_fatal',
                'Alerte SitePulse: Erreur Fatale Détectée',
                sprintf('Vérifiez le fichier %s pour les détails.', $log_file)
            );
            break;
        }
    }
}

/**
 * Handles rescheduling when the alert interval option is updated.
 *
 * @param mixed $old_value Previous value.
 * @param mixed $value     New value.
 * @return void
 */
function sitepulse_error_alerts_on_interval_update($old_value, $value) {
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

    if (!wp_next_scheduled($sitepulse_error_alerts_cron_hook)) {
        wp_schedule_event(time(), $sitepulse_error_alerts_schedule, $sitepulse_error_alerts_cron_hook);
    }
}

if (!empty($sitepulse_error_alerts_cron_hook)) {
    add_filter('cron_schedules', 'sitepulse_error_alerts_register_cron_schedule');

    add_action('init', function () use ($sitepulse_error_alerts_cron_hook) {
        global $sitepulse_error_alerts_schedule;

        $sitepulse_error_alerts_schedule = sitepulse_error_alerts_get_schedule_slug();

        if (!wp_next_scheduled($sitepulse_error_alerts_cron_hook)) {
            wp_schedule_event(time(), $sitepulse_error_alerts_schedule, $sitepulse_error_alerts_cron_hook);
        }
    });

    add_action($sitepulse_error_alerts_cron_hook, 'sitepulse_error_alerts_run_checks');
    add_action('update_option_' . SITEPULSE_OPTION_ALERT_INTERVAL, 'sitepulse_error_alerts_on_interval_update', 10, 3);
}
