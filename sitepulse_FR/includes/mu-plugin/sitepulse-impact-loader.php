<?php
/**
 * Plugin Name: SitePulse Impact Bootstrap
 * Description: Ensures SitePulse plugin impact tracking is hooked before standard plugins load.
 */

if (!defined('ABSPATH')) {
    exit;
}

$sitepulse_plugin_basename_option = 'sitepulse_plugin_basename';
$sitepulse_plugin_basename = get_option($sitepulse_plugin_basename_option);

if (!is_string($sitepulse_plugin_basename) || $sitepulse_plugin_basename === '') {
    $sitepulse_plugin_basename = 'sitepulse_FR/sitepulse.php';
}

$sitepulse_plugin_basename = ltrim($sitepulse_plugin_basename, '/\\');
$sitepulse_plugin_file = WP_PLUGIN_DIR . '/' . $sitepulse_plugin_basename;
$sitepulse_plugin_directory = dirname($sitepulse_plugin_file);

if ($sitepulse_plugin_directory === '.' || $sitepulse_plugin_directory === '') {
    $sitepulse_plugin_directory = WP_PLUGIN_DIR;
}

if (function_exists('trailingslashit')) {
    $sitepulse_plugin_directory = trailingslashit($sitepulse_plugin_directory);
} else {
    $sitepulse_plugin_directory = rtrim($sitepulse_plugin_directory, '/\\') . '/';
}

$sitepulse_tracker_file = $sitepulse_plugin_directory . 'includes/plugin-impact-tracker.php';

if (file_exists($sitepulse_tracker_file)) {
    require_once $sitepulse_tracker_file;

    if (function_exists('sitepulse_plugin_impact_tracker_bootstrap')) {
        sitepulse_plugin_impact_tracker_bootstrap();
    }
}
