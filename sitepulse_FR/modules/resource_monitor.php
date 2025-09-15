<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'Resource Monitor', 'Resources', 'manage_options', 'sitepulse-resources', 'resource_monitor_page'); });
function resource_monitor_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    if (function_exists('sys_getloadavg')) { $load = sys_getloadavg(); } else { $load = ['N/A', 'N/A', 'N/A']; }
    $load_display = implode(' / ', array_map('strval', $load));
    $memory_usage = size_format(memory_get_usage());
    $memory_limit = ini_get('memory_limit');
    $disk_free = size_format(disk_free_space(ABSPATH));
    $disk_total = size_format(disk_total_space(ABSPATH));
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-performance"></span> Moniteur de Ressources</h1>
        <p><strong>Charge CPU (1/5/15 min):</strong> <?php echo esc_html($load_display); ?></p>
        <p><strong>Mémoire:</strong> Utilisation <?php echo esc_html($memory_usage); ?> / Limite <?php echo esc_html($memory_limit); ?></p>
        <p><strong>Disque:</strong> Espace Libre <?php echo esc_html($disk_free); ?> / Total <?php echo esc_html($disk_total); ?></p>
    </div>
    <?php
}