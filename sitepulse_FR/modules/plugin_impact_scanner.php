<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'Plugin Impact Scanner', 'Plugin Impact', 'manage_options', 'sitepulse-plugins', 'plugin_impact_scanner_page'); });
function plugin_impact_scanner_page() {
    if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
    $all_plugins = get_plugins();
    $active_plugin_files = get_option('active_plugins');
    $impacts = []; $total_impact = 0;
    foreach ($active_plugin_files as $plugin_file) {
        $impact_ms = rand(5, 150);
        $impacts[$plugin_file] = [ 'name' => $all_plugins[$plugin_file]['Name'], 'impact' => $impact_ms, 'disk_space' => sitepulse_get_dir_size_recursive(WP_PLUGIN_DIR . '/' . dirname($plugin_file)) ];
        $total_impact += $impact_ms;
    }
    uasort($impacts, function($a, $b) { return $b['impact'] <=> $a['impact']; });
    ?>
    <style> .impact-bar-bg { background: #eee; border-radius: 3px; overflow: hidden; width: 100%; } .impact-bar { height: 18px; border-radius: 3px; background-color: #FFC107; text-align: right; color: white; padding-right: 5px; white-space: nowrap; font-size: 12px; line-height: 18px; } </style>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-filter"></span> Analyseur d'Impact des Plugins</h1>
        <p>Cet outil fournit une <strong>simulation</strong> de la contribution de chaque plugin actif au temps de chargement et à l'utilisation des ressources.</p>
        <table class="wp-list-table widefat striped">
            <thead><tr><th scope="col" style="width: 25%;">Plugin</th><th scope="col">Impact (Simulé)</th><th scope="col">Espace Disque</th><th scope="col" style="width: 35%;">Poids Relatif</th></tr></thead>
            <tbody>
                <?php if (empty($impacts)): ?><tr><td colspan="4">Aucun plugin actif à scanner.</td></tr><?php else: ?>
                    <?php foreach ($impacts as $data): $weight = $total_impact > 0 ? ($data['impact'] / $total_impact) * 100 : 0; $weight_color = $weight > 20 ? '#F44336' : ($weight > 10 ? '#FFC107' : '#4CAF50'); ?>
                    <tr>
                        <td><strong><?php echo esc_html($data['name']); ?></strong></td>
                        <td><?php echo esc_html($data['impact']); ?> ms</td>
                        <td><?php echo esc_html(size_format($data['disk_space'], 2)); ?></td>
                        <td><div class="impact-bar-bg"><div class="impact-bar" style="width: <?php echo esc_attr($weight); ?>%; background-color: <?php echo esc_attr($weight_color); ?>;"><?php echo esc_html(round($weight, 1)); ?>%</div></div></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
function sitepulse_get_dir_size_recursive($dir) {
    $size = 0;
    if (!is_dir($dir)) return $size;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) { $size += $file->getSize(); }
    return $size;
}
