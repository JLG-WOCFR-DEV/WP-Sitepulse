<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'Maintenance Advisor', 'Maintenance', 'manage_options', 'sitepulse-maintenance', 'maintenance_advisor_page'); });
function maintenance_advisor_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    require_once ABSPATH . 'wp-admin/includes/update.php';
    $core_updates = get_core_updates();
    $plugin_updates = get_plugin_updates();
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-update"></span> Conseiller de Maintenance</h1>
        <p><strong>Mises à jour du Coeur WP:</strong> <?php echo !empty($core_updates) && $core_updates[0]->response !== 'latest' ? 'Mise à jour disponible !' : 'À jour'; ?></p>
        <p><strong>Mises à jour des Plugins:</strong> <?php echo count($plugin_updates); ?> en attente</p>
        <p class="description">Recommandations : Faites une sauvegarde avant de mettre à jour, testez sur un site de pré-production.</p>
    </div>
    <?php
}
