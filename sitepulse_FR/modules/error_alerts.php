<?php
if (!defined('ABSPATH')) exit;
add_action('sitepulse_resource_monitor_cron', function() {
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if ($load[0] > 5) { wp_mail(get_option('admin_email'), 'Alerte SitePulse: Charge Serveur Élevée', 'La charge actuelle est de : ' . $load[0]); }
    }
});
add_action('sitepulse_log_analyzer_cron', function() {
    $log_file = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($log_file)) {
        if (stripos(file_get_contents($log_file), 'PHP Fatal error') !== false) {
            wp_mail(get_option('admin_email'), 'Alerte SitePulse: Erreur Fatale Détectée', 'Vérifiez le fichier debug.log pour les détails.');
        }
    }
});