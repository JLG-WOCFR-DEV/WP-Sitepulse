<?php
/**
 * SitePulse Custom Dashboards Module
 *
 * This module creates the main dashboard page for the plugin.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly.

if (!defined('SITEPULSE_TRANSIENT_DEBUG_LOG_SUMMARY')) {
    define('SITEPULSE_TRANSIENT_DEBUG_LOG_SUMMARY', 'sitepulse_dashboard_log_summary');
}

add_action('admin_enqueue_scripts', 'sitepulse_custom_dashboard_enqueue_assets');
add_action('wp_ajax_sitepulse_save_dashboard_preferences', 'sitepulse_save_dashboard_preferences');
add_action('rest_api_init', 'sitepulse_custom_dashboard_register_rest_routes');
add_filter('admin_body_class', 'sitepulse_custom_dashboard_body_class');

require_once __DIR__ . '/dashboard/api.php';

/**
 * Registers the assets used by the SitePulse dashboard when the page is loaded.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
function sitepulse_custom_dashboard_enqueue_assets($hook_suffix) {
    if ('toplevel_page_sitepulse-dashboard' !== $hook_suffix) {
        return;
    }

    $default_chartjs_src = SITEPULSE_URL . 'modules/vendor/chart.js/chart.umd.js';
    $chartjs_src = apply_filters('sitepulse_chartjs_src', $default_chartjs_src);

    if ($chartjs_src !== $default_chartjs_src) {
        $original_chartjs_src = $chartjs_src;
        $is_valid_chartjs_src = false;

        if (is_string($chartjs_src) && $chartjs_src !== '') {
            $validated_chartjs_src = wp_http_validate_url($chartjs_src);

            if ($validated_chartjs_src !== false) {
                $parsed_chartjs_src = wp_parse_url($validated_chartjs_src);
                $scheme = isset($parsed_chartjs_src['scheme']) ? strtolower($parsed_chartjs_src['scheme']) : '';
                $is_https = ('https' === $scheme);
                $is_plugin_internal = false;

                $sitepulse_base = wp_parse_url(SITEPULSE_URL);

                if (is_array($parsed_chartjs_src) && is_array($sitepulse_base)) {
                    $source_host = isset($parsed_chartjs_src['host']) ? strtolower($parsed_chartjs_src['host']) : '';
                    $base_host = isset($sitepulse_base['host']) ? strtolower($sitepulse_base['host']) : '';

                    if ($source_host && $base_host && $source_host === $base_host) {
                        $source_path = isset($parsed_chartjs_src['path']) ? $parsed_chartjs_src['path'] : '';
                        $base_path = isset($sitepulse_base['path']) ? $sitepulse_base['path'] : '';

                        if ($base_path === '' || strpos($source_path, $base_path) === 0) {
                            $is_plugin_internal = true;
                        }
                    }
                }

                if ($is_https || $is_plugin_internal) {
                    $chartjs_src = $validated_chartjs_src;
                    $is_valid_chartjs_src = true;
                }
            } elseif (strpos($chartjs_src, SITEPULSE_URL) === 0) {
                // Allow internal plugin URLs even if wp_http_validate_url() returned false.
                $is_valid_chartjs_src = true;
            }
        }

        if (!$is_valid_chartjs_src) {
            if (function_exists('sitepulse_log')) {
                $log_value = '';

                if (is_string($original_chartjs_src)) {
                    $log_value = esc_url_raw($original_chartjs_src);
                } elseif (is_scalar($original_chartjs_src)) {
                    $log_value = (string) $original_chartjs_src;
                } else {
                    $encoded_value = wp_json_encode($original_chartjs_src);
                    $log_value = is_string($encoded_value) ? $encoded_value : '';
                }

                sitepulse_log(
                    sprintf(
                        'SitePulse: invalid Chart.js source override rejected. Value: %s',
                        $log_value
                    ),
                    'DEBUG'
                );
            }

            $chartjs_src = $default_chartjs_src;
        }
    }

    wp_register_style(
        'sitepulse-dashboard-theme',
        SITEPULSE_URL . 'modules/css/sitepulse-theme.css',
        [],
        SITEPULSE_VERSION
    );

    wp_enqueue_style('sitepulse-dashboard-theme');

    wp_register_style(
        'sitepulse-module-navigation',
        SITEPULSE_URL . 'modules/css/module-navigation.css',
        ['sitepulse-dashboard-theme'],
        SITEPULSE_VERSION
    );

    wp_enqueue_style('sitepulse-module-navigation');

    wp_register_style(
        'sitepulse-custom-dashboard',
        SITEPULSE_URL . 'modules/css/custom-dashboard.css',
        ['sitepulse-module-navigation'],
        SITEPULSE_VERSION
    );

    wp_enqueue_style('sitepulse-custom-dashboard');

    wp_register_script(
        'sitepulse-dashboard-nav',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-nav.js',
        ['wp-i18n'],
        SITEPULSE_VERSION,
        true
    );

    wp_enqueue_script('sitepulse-dashboard-nav');

    wp_register_script(
        'sitepulse-chartjs',
        $chartjs_src,
        [],
        '4.4.5',
        true
    );

    if ($chartjs_src !== $default_chartjs_src) {
        $fallback_loader = '(function(){if (typeof window.Chart === "undefined") {'
            . 'var script=document.createElement("script");'
            . 'script.src=' . wp_json_encode($default_chartjs_src) . ';'
            . 'script.defer=true;'
            . 'document.head.appendChild(script);'
            . '}})();';

        wp_add_inline_script('sitepulse-chartjs', $fallback_loader, 'after');
    }

    wp_register_script(
        'sitepulse-dashboard-charts',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-charts.js',
        ['sitepulse-chartjs'],
        SITEPULSE_VERSION,
        true
    );

    wp_register_script(
        'sitepulse-dashboard-preferences',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-preferences.js',
        ['jquery', 'jquery-ui-sortable'],
        SITEPULSE_VERSION,
        true
    );

    wp_register_script(
        'sitepulse-dashboard-metrics',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-metrics.js',
        ['wp-a11y'],
        SITEPULSE_VERSION,
        true
    );
}

require_once __DIR__ . '/dashboard/theme.php';
require_once __DIR__ . '/dashboard/preferences.php';
require_once __DIR__ . '/dashboard/cards.php';
require_once __DIR__ . '/dashboard/kpis.php';
require_once __DIR__ . '/dashboard/health.php';
require_once __DIR__ . '/dashboard/render.php';
require_once __DIR__ . '/dashboard/metrics.php';
require_once __DIR__ . '/dashboard/page.php';
