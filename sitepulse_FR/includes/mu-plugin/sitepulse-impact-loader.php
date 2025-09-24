<?php
/**
 * Plugin Name: SitePulse Impact Bootstrap
 * Description: Ensures SitePulse plugin impact tracking is hooked before standard plugins load.
 */

if (!defined('ABSPATH')) {
    exit;
}

$sitepulse_plugin_directory = WP_PLUGIN_DIR . '/sitepulse_FR';

if (function_exists('trailingslashit')) {
    $sitepulse_plugin_directory = trailingslashit(WP_PLUGIN_DIR) . 'sitepulse_FR';
} else {
    $sitepulse_plugin_directory = rtrim(WP_PLUGIN_DIR, '/\\') . '/sitepulse_FR';
}
$sitepulse_tracker_file = $sitepulse_plugin_directory . '/includes/plugin-impact-tracker.php';

if (file_exists($sitepulse_tracker_file)) {
    require_once $sitepulse_tracker_file;

    if (function_exists('sitepulse_plugin_impact_tracker_bootstrap')) {
        sitepulse_plugin_impact_tracker_bootstrap();
    }
}
