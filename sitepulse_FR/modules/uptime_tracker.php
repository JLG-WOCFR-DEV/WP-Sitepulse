<?php
if (!defined('ABSPATH')) exit;

$sitepulse_uptime_cron_hook = function_exists('sitepulse_get_cron_hook') ? sitepulse_get_cron_hook('uptime_tracker') : 'sitepulse_uptime_tracker_cron';

add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'Uptime Tracker', 'Uptime', 'manage_options', 'sitepulse-uptime', 'sitepulse_uptime_tracker_page'); });

if (!empty($sitepulse_uptime_cron_hook)) {
    add_action('init', function() use ($sitepulse_uptime_cron_hook) {
        if (!wp_next_scheduled($sitepulse_uptime_cron_hook)) {
            wp_schedule_event(time(), 'hourly', $sitepulse_uptime_cron_hook);
        }
    });
    add_action($sitepulse_uptime_cron_hook, 'sitepulse_run_uptime_check');
}
function sitepulse_uptime_tracker_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $uptime_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
    $total_checks = count($uptime_log);
    $up_checks = count(array_filter($uptime_log));
    $uptime_percentage = $total_checks > 0 ? ($up_checks / $total_checks) * 100 : 100;
    ?>
    <style> .uptime-chart { display: flex; gap: 2px; height: 60px; align-items: flex-end; } .uptime-bar { flex-grow: 1; } .uptime-bar.up { background-color: #4CAF50; } .uptime-bar.down { background-color: #F44336; } </style>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-chart-bar"></span> Suivi de Disponibilité</h1>
        <p>Cet outil vérifie la disponibilité de votre site toutes les heures. Voici le statut des <?php echo esc_html($total_checks); ?> dernières vérifications.</p>
        <h2>Disponibilité (<?php echo esc_html($total_checks); ?> dernières heures): <strong style="font-size: 1.4em;"><?php echo esc_html(round($uptime_percentage, 2)); ?>%</strong></h2>
        <div class="uptime-chart">
            <?php if (empty($uptime_log)): ?><p>Aucune donnée de disponibilité. La première vérification aura lieu dans l'heure.</p><?php else: ?>
                <?php foreach ($uptime_log as $index => $status): ?>
                    <?php
                    $bar_class = $status ? 'up' : 'down';
                    $bar_title = ($status ? 'Site OK' : 'Site KO') . ' lors du check #' . ($index + 1);
                    ?>
                    <div class="uptime-bar <?php echo esc_attr($bar_class); ?>" title="<?php echo esc_attr($bar_title); ?>"></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 0.9em; color: #555;"><span>Il y a <?php echo esc_html($total_checks); ?> heures</span><span>Maintenant</span></div>
        <div class="notice notice-info" style="margin-top: 20px;"><p><strong>Comment ça marche :</strong> Une barre verte indique que votre site était en ligne. Une barre rouge indique un possible incident où votre site était inaccessible.</p></div>
    </div>
    <?php
}
function sitepulse_run_uptime_check() {
    $response = wp_remote_get(home_url(), ['timeout' => 10]);
    $response_code = wp_remote_retrieve_response_code($response);
    // Considère les réponses HTTP dans la plage 2xx ou 3xx comme un site "up"
    $is_up = !is_wp_error($response) && $response_code >= 200 && $response_code < 400;
    $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
    $log[] = (int)$is_up;
    if (count($log) > 30) { array_shift($log); }
    update_option(SITEPULSE_OPTION_UPTIME_LOG, $log);
    if (!$is_up) { sitepulse_log('Uptime check: Down', 'ALERT'); } 
    else { sitepulse_log('Uptime check: Up'); }
}