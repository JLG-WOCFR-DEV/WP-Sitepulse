<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Maintenance Advisor', 'sitepulse'),
        __('Maintenance', 'sitepulse'),
        'manage_options',
        'sitepulse-maintenance',
        'sitepulse_maintenance_advisor_page'
    );
});
function sitepulse_maintenance_advisor_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    require_once ABSPATH . 'wp-admin/includes/update.php';
    $core_updates = get_core_updates();
    $plugin_updates = get_plugin_updates();
    $core_status = !empty($core_updates) && $core_updates[0]->response !== 'latest'
        ? __('Mise à jour disponible !', 'sitepulse')
        : __('À jour', 'sitepulse');
    $plugin_updates_count = count($plugin_updates);
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-update"></span> <?php esc_html_e('Conseiller de Maintenance', 'sitepulse'); ?></h1>
        <p><strong><?php esc_html_e('Mises à jour du Coeur WP:', 'sitepulse'); ?></strong> <?php echo esc_html($core_status); ?></p>
        <p><strong><?php esc_html_e('Mises à jour des Plugins:', 'sitepulse'); ?></strong> <?php echo esc_html($plugin_updates_count); ?> <?php esc_html_e('en attente', 'sitepulse'); ?></p>
        <p class="description"><?php esc_html_e('Recommandations : Faites une sauvegarde avant de mettre à jour, testez sur un site de pré-production.', 'sitepulse'); ?></p>
    </div>
    <?php
}
