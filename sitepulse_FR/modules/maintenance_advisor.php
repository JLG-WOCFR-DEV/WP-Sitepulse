<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'Maintenance Advisor', 'Maintenance', 'manage_options', 'sitepulse-maintenance', 'maintenance_advisor_page'); });
function maintenance_advisor_page() {
    require_once ABSPATH . 'wp-admin/includes/update.php';
    $core_updates = get_core_updates();
    $plugin_updates = get_plugin_updates();
    $core_status = !empty($core_updates) && $core_updates[0]->response !== 'latest' ? 'Mise à jour disponible !' : 'À jour';
    $plugin_updates_count = count($plugin_updates);
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-update"></span> Conseiller de Maintenance</h1>
        <p><strong>Mises à jour du Coeur WP:</strong> <?php echo esc_html($core_status); ?></p>
        <p><strong>Mises à jour des Plugins:</strong> <?php echo esc_html($plugin_updates_count); ?> en attente</p>
        <p class="description">Recommandations : Faites une sauvegarde avant de mettre à jour, testez sur un site de pré-production.</p>
    </div>
    <?php
}
