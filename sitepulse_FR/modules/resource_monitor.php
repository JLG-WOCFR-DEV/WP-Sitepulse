<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'Resource Monitor', 'Resources', 'manage_options', 'sitepulse-resources', 'resource_monitor_page'); });
function resource_monitor_page() {
    if (function_exists('sys_getloadavg')) { $load = sys_getloadavg(); } else { $load = ['N/A', 'N/A', 'N/A']; }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-performance"></span> Moniteur de Ressources</h1>
        <p><strong>Charge CPU (1/5/15 min):</strong> <?php echo implode(' / ', $load); ?></p>
        <p><strong>Mémoire:</strong> Utilisation <?php echo size_format(memory_get_usage()); ?> / Limite <?php echo ini_get('memory_limit'); ?></p>
        <p><strong>Disque:</strong> Espace Libre <?php echo size_format(disk_free_space(ABSPATH)); ?> / Total <?php echo size_format(disk_total_space(ABSPATH)); ?></p>
    </div>
    <?php
}