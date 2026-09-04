<?php
if (!defined('ABSPATH')) exit;

if (!defined('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY')) {
    define('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY', 'sitepulse_resource_monitor_history');
}

if (!defined('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY_LOCK')) {
    define('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY_LOCK', 'sitepulse_resource_monitor_history_lock');
}

if (!defined('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_HISTORY_CACHE_PREFIX')) {
    define('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_HISTORY_CACHE_PREFIX', 'sitepulse_resource_monitor_rest_history_');
}

if (!defined('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_AGGREGATE_CACHE_PREFIX')) {
    define('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_AGGREGATE_CACHE_PREFIX', 'sitepulse_resource_monitor_aggregates_');
}

if (!defined('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_LAST_REPORT')) {
    define('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_LAST_REPORT', 'sitepulse_resource_monitor_last_report');
}

if (!defined('SITEPULSE_OPTION_RESOURCE_MONITOR_CACHE_KEYS')) {
    define('SITEPULSE_OPTION_RESOURCE_MONITOR_CACHE_KEYS', 'sitepulse_resource_monitor_cache_keys');
}

if (!defined('SITEPULSE_AS_GROUP_RESOURCE_MONITOR')) {
    define('SITEPULSE_AS_GROUP_RESOURCE_MONITOR', 'sitepulse_resource_monitor');
}

if (!defined('SITEPULSE_ACTION_RESOURCE_MONITOR_REPORTS')) {
    define('SITEPULSE_ACTION_RESOURCE_MONITOR_REPORTS', 'sitepulse_resource_monitor_generate_reports');
}

$http_monitor_path = __DIR__ . '/resource-monitor/http-monitor.php';

if (file_exists($http_monitor_path)) {
    require_once $http_monitor_path;
}

add_action('plugins_loaded', 'sitepulse_http_monitor_bootstrap', 12);

add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Resource Monitor', 'sitepulse'),
        __('Resources', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-resources',
        'sitepulse_resource_monitor_page'
    );
});

add_action('admin_enqueue_scripts', 'sitepulse_resource_monitor_enqueue_assets');
add_action('rest_api_init', 'sitepulse_resource_monitor_register_rest_routes');
add_action('plugins_loaded', 'sitepulse_resource_monitor_bootstrap_storage', 9);
add_action('init', 'sitepulse_resource_monitor_schedule_report_generation');
add_action(SITEPULSE_ACTION_RESOURCE_MONITOR_REPORTS, 'sitepulse_resource_monitor_run_scheduled_reports');
add_action('admin_post_sitepulse_resource_monitor_trigger_report', 'sitepulse_resource_monitor_handle_report_trigger');
add_action('admin_post_sitepulse_save_http_monitor_settings', 'sitepulse_http_monitor_handle_settings');

require_once __DIR__ . '/resource-monitor/storage.php';

/**
 * Registers and enqueues the stylesheet used by the resource monitor page.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_resource_monitor_enqueue_assets($hook_suffix) {
    if ($hook_suffix !== 'sitepulse-dashboard_page_sitepulse-resources') {
        return;
    }

    $style_handle = 'sitepulse-resource-monitor';
    $style_src    = SITEPULSE_URL . 'modules/css/resource-monitor.css';

    wp_enqueue_style($style_handle, $style_src, [], SITEPULSE_VERSION);

    $default_chartjs_src = SITEPULSE_URL . 'modules/vendor/chart.js/chart.umd.js';
    $chartjs_src = apply_filters('sitepulse_chartjs_src', $default_chartjs_src);

    if (!wp_script_is('sitepulse-chartjs', 'registered')) {
        $is_custom_source = $chartjs_src !== $default_chartjs_src;

        wp_register_script(
            'sitepulse-chartjs',
            $chartjs_src,
            [],
            '4.4.5',
            true
        );

        if ($is_custom_source) {
            $fallback_loader = '(function(){if (typeof window.Chart === "undefined") {'
                . 'var script=document.createElement("script");'
                . 'script.src=' . wp_json_encode($default_chartjs_src) . ';'
                . 'script.defer=true;'
                . 'document.head.appendChild(script);'
                . '}})();';

            wp_add_inline_script('sitepulse-chartjs', $fallback_loader, 'after');
        }
    }

    wp_enqueue_script('sitepulse-chartjs');

    wp_register_script(
        'sitepulse-resource-monitor',
        SITEPULSE_URL . 'modules/js/resource-monitor.js',
        ['sitepulse-chartjs', 'wp-a11y'],
        SITEPULSE_VERSION,
        true
    );

    wp_enqueue_script('sitepulse-resource-monitor');
}

require_once __DIR__ . '/resource-monitor/rest.php';
require_once __DIR__ . '/resource-monitor/cache.php';
require_once __DIR__ . '/resource-monitor/analytics.php';
require_once __DIR__ . '/resource-monitor/reports.php';
require_once __DIR__ . '/resource-monitor/snapshot.php';
require_once __DIR__ . '/resource-monitor/page.php';
require_once __DIR__ . '/resource-monitor/history.php';
require_once __DIR__ . '/resource-monitor/cron.php';
require_once __DIR__ . '/resource-monitor/export.php';

add_action('admin_post_sitepulse_resource_monitor_export', 'sitepulse_resource_monitor_handle_export');
