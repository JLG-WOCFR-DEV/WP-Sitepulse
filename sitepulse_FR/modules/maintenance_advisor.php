<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Maintenance Advisor', 'sitepulse'),
        __('Maintenance', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-maintenance',
        'sitepulse_maintenance_advisor_page'
    );
});
function sitepulse_maintenance_advisor_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    require_once ABSPATH . 'wp-admin/includes/update.php';
    $core_updates = apply_filters('sitepulse_maintenance_advisor_core_updates', get_core_updates());
    $plugin_updates = apply_filters('sitepulse_maintenance_advisor_plugin_updates', get_plugin_updates());

    $core_updates_available = !is_wp_error($core_updates) && is_array($core_updates);
    $plugin_updates_available = !is_wp_error($plugin_updates) && is_array($plugin_updates);
    $has_update_data = $core_updates_available && $plugin_updates_available;

    if ($has_update_data) {
        $core_update_entry = $core_updates_available && isset($core_updates[0]) && is_object($core_updates[0])
            ? $core_updates[0]
            : null;

        $core_status = $core_update_entry !== null
            && property_exists($core_update_entry, 'response')
            && $core_update_entry->response !== 'latest'
            ? __('Mise à jour disponible !', 'sitepulse')
            : __('À jour', 'sitepulse');

        $plugin_updates_count = count($plugin_updates);
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-update"></span> <?php esc_html_e('Conseiller de Maintenance', 'sitepulse'); ?></h1>
        <?php if (!$has_update_data) : ?>
            <div class="notice notice-error">
                <p><?php esc_html_e(
                    'Impossible de récupérer les données de mise à jour de WordPress. Le nombre de mises à jour disponibles est inconnu.',
                    'sitepulse'
                ); ?></p>
            </div>
        <?php else : ?>
            <p><strong><?php esc_html_e('Mises à jour du Coeur WP:', 'sitepulse'); ?></strong> <?php echo esc_html($core_status); ?></p>
            <p><strong><?php esc_html_e('Mises à jour des Plugins:', 'sitepulse'); ?></strong> <?php echo esc_html($plugin_updates_count); ?> <?php esc_html_e('en attente', 'sitepulse'); ?></p>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('Recommandations : Faites une sauvegarde avant de mettre à jour, testez sur un site de pré-production.', 'sitepulse'); ?></p>
    </div>
    <?php
}
