<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action(
    'admin_menu',
    function () {
        add_submenu_page(
            'sitepulse-dashboard',
            __('Plugin Impact Scanner', 'sitepulse'),
            __('Plugin Impact', 'sitepulse'),
            sitepulse_get_capability(),
            'sitepulse-plugins',
            'sitepulse_plugin_impact_scanner_page'
        );
    }
);

add_action('admin_enqueue_scripts', 'sitepulse_plugin_impact_enqueue_assets');

/**
 * Enqueues styles for the plugin impact scanner admin screen.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_plugin_impact_enqueue_assets($hook_suffix) {
    if ($hook_suffix !== 'sitepulse-dashboard_page_sitepulse-plugins') {
        return;
    }

    wp_enqueue_style(
        'sitepulse-plugin-impact',
        SITEPULSE_URL . 'modules/css/plugin-impact-scanner.css',
        [],
        SITEPULSE_VERSION
    );

    wp_enqueue_script(
        'sitepulse-plugin-impact',
        SITEPULSE_URL . 'modules/js/plugin-impact-scanner.js',
        [],
        SITEPULSE_VERSION,
        true
    );

    $default_thresholds = function_exists('sitepulse_get_default_plugin_impact_thresholds')
        ? sitepulse_get_default_plugin_impact_thresholds()
        : [
            'impactWarning'  => 30.0,
            'impactCritical' => 60.0,
            'weightWarning'  => 10.0,
            'weightCritical' => 20.0,
            'trendWarning'   => 15.0,
            'trendCritical'  => 40.0,
        ];

    $stored_thresholds = [
        'default' => $default_thresholds,
        'roles'   => [],
    ];

    if (defined('SITEPULSE_OPTION_IMPACT_THRESHOLDS')) {
        $option_value = get_option(
            SITEPULSE_OPTION_IMPACT_THRESHOLDS,
            [
                'default' => $default_thresholds,
                'roles'   => [],
            ]
        );

        if (is_array($option_value)) {
            $stored_thresholds = $option_value;
        }
    }

    if (function_exists('sitepulse_sanitize_impact_thresholds')) {
        $stored_thresholds = sitepulse_sanitize_impact_thresholds($stored_thresholds);
    }

    $effective_thresholds = isset($stored_thresholds['default']) && is_array($stored_thresholds['default'])
        ? $stored_thresholds['default']
        : $default_thresholds;

    if (isset($stored_thresholds['roles']) && is_array($stored_thresholds['roles'])) {
        $current_user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;

        if ($current_user instanceof WP_User) {
            foreach ((array) $current_user->roles as $role) {
                $role_key = sanitize_key($role);

                if ($role_key !== '' && isset($stored_thresholds['roles'][$role_key])) {
                    $effective_thresholds = $stored_thresholds['roles'][$role_key];
                    break;
                }
            }
        }
    }

    $thresholds = apply_filters('sitepulse_plugin_impact_highlight_thresholds', $effective_thresholds);

    if (function_exists('sitepulse_normalize_impact_threshold_set')) {
        $thresholds = sitepulse_normalize_impact_threshold_set($thresholds, $default_thresholds);
    } else {
        if (!is_array($thresholds)) {
            $thresholds = $default_thresholds;
        }

        $thresholds = wp_parse_args($thresholds, $default_thresholds);

        foreach ($thresholds as $key => $value) {
            $thresholds[$key] = is_numeric($value) ? (float) $value : $default_thresholds[$key];
        }
    }

    wp_localize_script(
        'sitepulse-plugin-impact',
        'sitepulsePluginImpactScanner',
        [
            'thresholds' => $thresholds,
            'i18n'       => [
                'sortImpactDesc' => esc_html__('Tri : impact décroissant', 'sitepulse'),
                'sortImpactAsc'  => esc_html__('Tri : impact croissant', 'sitepulse'),
                'sortNameAsc'    => esc_html__('Tri : nom (A → Z)', 'sitepulse'),
                'sortWeightDesc' => esc_html__('Tri : poids décroissant', 'sitepulse'),
                'weightMinLabel' => esc_html__('Poids min (%)', 'sitepulse'),
                'weightMaxLabel' => esc_html__('Poids max (%)', 'sitepulse'),
                'resetFilters'   => esc_html__('Réinitialiser', 'sitepulse'),
                'exportCsv'      => esc_html__('Exporter CSV', 'sitepulse'),
                'noResult'       => esc_html__('Aucun plugin ne correspond aux filtres.', 'sitepulse'),
                'fileName'       => esc_html__('sitepulse-plugin-impact.csv', 'sitepulse'),
            ],
        ]
    );
}

add_action('upgrader_process_complete', 'sitepulse_plugin_impact_clear_dir_cache_on_upgrade', 10, 2);
add_action('sitepulse_queue_plugin_dir_scan', 'sitepulse_process_plugin_dir_scan_queue');

if (!defined('SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION')) {
    define('SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION', 'sitepulse_plugin_dir_scan_queue');
}

function sitepulse_plugin_impact_clear_dir_cache_on_upgrade($upgrader, $hook_extra) {
    if (!is_array($hook_extra) || !isset($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return;
    }

    if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        return;
    }

    $plugin_files = [];

    if (isset($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
        foreach ($hook_extra['plugins'] as $plugin_file) {
            if (is_string($plugin_file) && $plugin_file !== '') {
                $plugin_files[] = $plugin_file;
            }
        }
    } elseif (isset($hook_extra['plugin']) && is_string($hook_extra['plugin']) && $hook_extra['plugin'] !== '') {
        $plugin_files[] = $hook_extra['plugin'];
    }

    if (empty($plugin_files)) {
        if (function_exists('sitepulse_delete_transients_by_prefix')) {
            sitepulse_delete_transients_by_prefix(SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX);
        }

        if (function_exists('sitepulse_delete_site_transients_by_prefix')) {
            sitepulse_delete_site_transients_by_prefix(SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX);
        }

        return;
    }

    $plugin_files = array_unique($plugin_files);

    foreach ($plugin_files as $plugin_file) {
        $plugin_dir = dirname($plugin_file);

        if ($plugin_dir === '.' || $plugin_dir === '' || $plugin_dir === DIRECTORY_SEPARATOR) {
            continue;
        }

        $plugin_dir_path = WP_PLUGIN_DIR . '/' . $plugin_dir;

        sitepulse_clear_dir_size_cache($plugin_dir_path);

        if (is_multisite()) {
            $site_ids = function_exists('get_sites')
                ? get_sites([
                    'fields' => 'ids',
                    'number' => 0,
                    'no_found_rows' => true,
                ])
                : [];

            if (!empty($site_ids) && defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
                $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($plugin_dir_path);

                foreach ($site_ids as $site_id) {
                    $site_id = (int) $site_id;

                    if ($site_id <= 0) {
                        continue;
                    }

                    $switched = switch_to_blog($site_id);

                    if (!$switched) {
                        continue;
                    }

                    delete_transient($transient_key);
                    restore_current_blog();
                }
            }
        }
    }
}


require_once __DIR__ . '/plugin-impact/page.php';
require_once __DIR__ . '/plugin-impact/history.php';
require_once __DIR__ . '/plugin-impact/dir-size.php';
require_once __DIR__ . '/plugin-impact/queue.php';

function sitepulse_plugin_impact_get_timestamp() {
    if (function_exists('current_time')) {
        return (int) current_time('timestamp');
    }

    return time();
}
