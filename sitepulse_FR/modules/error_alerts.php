<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the configured CPU load threshold for alerting.
 *
 * @return float
 */
function sitepulse_error_alert_get_cpu_threshold() {
    $threshold = get_option('sitepulse_cpu_alert_threshold', 5);
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
    $cooldown_minutes = get_option('sitepulse_alert_cooldown_minutes', 60);
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
 * Attempts to send an alert message while respecting the cooldown lock.
 *
 * @param string $type    Unique identifier of the alert type.
 * @param string $subject Mail subject.
 * @param string $message Mail body.
 * @return bool True if the e-mail was dispatched, false otherwise.
 */
function sitepulse_error_alert_send($type, $subject, $message) {
    $lock_key = 'sitepulse_error_alert_' . sanitize_key($type) . '_lock';

    if (false !== get_transient($lock_key)) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log("Alert '$type' skipped due to active cooldown.");
        }
        return false;
    }

    $sent = wp_mail(get_option('admin_email'), $subject, $message);

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

add_action('sitepulse_resource_monitor_cron', function() {
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
        $message  = "La charge actuelle est de : " . $load[0] . " (seuil : " . $threshold . ")";
        sitepulse_error_alert_send('cpu', 'Alerte SitePulse: Charge Serveur Élevée', $message);
    }
});

add_action('sitepulse_log_analyzer_cron', function() {
    $log_file = WP_CONTENT_DIR . '/debug.log';
    if (!file_exists($log_file) || !is_readable($log_file)) {
        return;
    }

    $contents = file_get_contents($log_file);
    if ($contents === false) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('Impossible de lire debug.log pour l’analyse des erreurs.', 'ERROR');
        }
        return;
    }

    if (stripos($contents, 'PHP Fatal error') !== false) {
        sitepulse_error_alert_send('php_fatal', 'Alerte SitePulse: Erreur Fatale Détectée', 'Vérifiez le fichier debug.log pour les détails.');
    }
});
