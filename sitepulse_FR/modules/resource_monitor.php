<?php
if (!defined('ABSPATH')) exit;

if (!defined('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY')) {
    define('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY', 'sitepulse_resource_monitor_history');
}

if (defined('SITEPULSE_PATH')) {
    require_once SITEPULSE_PATH . 'modules/resource-monitor/http-monitor.php';
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

/**
 * Ensures the resource monitor datastore is ready.
 *
 * @return void
 */
function sitepulse_resource_monitor_bootstrap_storage() {
    sitepulse_resource_monitor_maybe_upgrade_schema();
    if (function_exists('sitepulse_http_monitor_bootstrap_storage')) {
        sitepulse_http_monitor_bootstrap_storage();
    }
}

/**
 * Retrieves the fully qualified name of the resource monitor history table.
 *
 * @return string
 */
function sitepulse_resource_monitor_get_table_name() {
    if (!defined('SITEPULSE_TABLE_RESOURCE_MONITOR_HISTORY')) {
        return '';
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return '';
    }

    $suffix = SITEPULSE_TABLE_RESOURCE_MONITOR_HISTORY;

    return $wpdb->prefix . $suffix;
}

/**
 * Determines whether the resource monitor history table exists.
 *
 * @param bool $force_refresh Optional. When true, bypasses the cached result.
 * @return bool
 */
function sitepulse_resource_monitor_table_exists($force_refresh = false) {
    static $exists = null;

    if ($force_refresh) {
        $exists = null;
    }

    if ($exists !== null) {
        return $exists;
    }

    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '') {
        $exists = false;

        return $exists;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        $exists = false;

        return $exists;
    }

    $exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return $exists;
}

/**
 * Creates or upgrades the resource monitor history table.
 *
 * @return void
 */
function sitepulse_resource_monitor_maybe_upgrade_schema() {
    if (!defined('SITEPULSE_RESOURCE_MONITOR_SCHEMA_VERSION')
        || !defined('SITEPULSE_OPTION_RESOURCE_MONITOR_SCHEMA_VERSION')) {
        return;
    }

    $target_version = (int) SITEPULSE_RESOURCE_MONITOR_SCHEMA_VERSION;
    $current_version = (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_SCHEMA_VERSION, 0);

    if ($current_version >= $target_version && sitepulse_resource_monitor_table_exists()) {
        return;
    }

    sitepulse_resource_monitor_install_table();

    if ($current_version < $target_version) {
        sitepulse_resource_monitor_migrate_legacy_history();
        update_option(SITEPULSE_OPTION_RESOURCE_MONITOR_SCHEMA_VERSION, $target_version);
    }
}

/**
 * Installs the resource monitor history table.
 *
 * @return void
 */
function sitepulse_resource_monitor_install_table() {
    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '') {
        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        recorded_at int(10) unsigned NOT NULL,
        load_1 float NULL,
        load_5 float NULL,
        load_15 float NULL,
        memory_usage bigint(20) unsigned NULL,
        memory_limit bigint(20) unsigned NULL,
        disk_free bigint(20) unsigned NULL,
        disk_total bigint(20) unsigned NULL,
        source varchar(32) NOT NULL DEFAULT 'manual',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY recorded_at (recorded_at),
        KEY source (source)
    ) {$charset_collate};";

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta($sql);

    sitepulse_resource_monitor_table_exists(true);
}

/**
 * Migrates legacy option-based history into the dedicated table.
 *
 * @return void
 */
function sitepulse_resource_monitor_migrate_legacy_history() {
    if (!defined('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY')) {
        return;
    }

    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '' || !sitepulse_resource_monitor_table_exists()) {
        return;
    }

    $legacy_history = get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY, []);

    if (!is_array($legacy_history) || empty($legacy_history)) {
        delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);

        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $existing_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

    if ($existing_rows > 0) {
        delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);

        return;
    }

    $normalized_entries = sitepulse_resource_monitor_normalize_history($legacy_history);

    if (empty($normalized_entries)) {
        delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);

        return;
    }

    foreach ($normalized_entries as $entry) {
        sitepulse_resource_monitor_insert_history_entry($entry, false);
    }

    delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);
}

/**
 * Inserts a normalized history entry into the datastore.
 *
 * @param array $entry            Normalized history entry.
 * @param bool  $apply_retention Optional. Whether to enforce retention after inserting.
 * @return void
 */
function sitepulse_resource_monitor_insert_history_entry(array $entry, $apply_retention = true) {
    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '' || !sitepulse_resource_monitor_table_exists()) {
        return;
    }

    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

    if ($timestamp <= 0) {
        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $load = isset($entry['load']) && is_array($entry['load']) ? array_values($entry['load']) : [null, null, null];
    $memory = isset($entry['memory']) && is_array($entry['memory']) ? $entry['memory'] : [];
    $disk = isset($entry['disk']) && is_array($entry['disk']) ? $entry['disk'] : [];
    $source = isset($entry['source']) ? (string) $entry['source'] : 'manual';

    $data = [
        'recorded_at'   => $timestamp,
        'load_1'        => isset($load[0]) && is_numeric($load[0]) ? (float) $load[0] : null,
        'load_5'        => isset($load[1]) && is_numeric($load[1]) ? (float) $load[1] : null,
        'load_15'       => isset($load[2]) && is_numeric($load[2]) ? (float) $load[2] : null,
        'memory_usage'  => isset($memory['usage']) && is_numeric($memory['usage']) ? max(0, (int) $memory['usage']) : null,
        'memory_limit'  => isset($memory['limit']) && is_numeric($memory['limit']) ? max(0, (int) $memory['limit']) : null,
        'disk_free'     => isset($disk['free']) && is_numeric($disk['free']) ? max(0, (int) $disk['free']) : null,
        'disk_total'    => isset($disk['total']) && is_numeric($disk['total']) ? max(0, (int) $disk['total']) : null,
        'source'        => $source !== '' ? $source : 'manual',
        'created_at'    => gmdate('Y-m-d H:i:s'),
    ];

    $formats = ['%d', '%f', '%f', '%f', '%d', '%d', '%d', '%d', '%s', '%s'];

    $wpdb->insert($table, $data, $formats);

    if ($apply_retention) {
        sitepulse_resource_monitor_apply_retention();
    }
}

/**
 * Retrieves the configured retention duration in days.
 *
 * @return int
 */
function sitepulse_resource_monitor_get_retention_days() {
    $default = defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_RETENTION_DAYS')
        ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_RETENTION_DAYS
        : 180;

    $retention = (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_RETENTION_DAYS, $default);

    $allowed_values = apply_filters('sitepulse_resource_monitor_allowed_retention_days', [90, 180, 365]);

    if (is_array($allowed_values) && !empty($allowed_values)) {
        $allowed_values = array_map('intval', $allowed_values);
        sort($allowed_values);

        if (in_array($retention, $allowed_values, true)) {
            return max(0, $retention);
        }

        $closest = $allowed_values[0];
        $min_diff = abs($retention - $closest);

        foreach ($allowed_values as $value) {
            $diff = abs($retention - $value);

            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest = $value;
            }
        }

        return max(0, (int) $closest);
    }

    return max(0, $retention);
}

/**
 * Applies the retention policy by removing outdated entries.
 *
 * @return void
 */
function sitepulse_resource_monitor_apply_retention() {
    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '' || !sitepulse_resource_monitor_table_exists()) {
        return;
    }

    $retention_days = (int) apply_filters(
        'sitepulse_resource_monitor_history_retention_days',
        sitepulse_resource_monitor_get_retention_days()
    );

    if ($retention_days <= 0) {
        return;
    }

    $cutoff = (int) current_time('timestamp', true) - ($retention_days * DAY_IN_SECONDS);

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE recorded_at < %d", $cutoff));
}

/**
 * Retrieves the maximum number of rows allowed in an export.
 *
 * @return int
 */
function sitepulse_resource_monitor_get_export_max_rows() {
    $default = defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_EXPORT_MAX_ROWS')
        ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_EXPORT_MAX_ROWS
        : 2000;

    $max_rows = (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_EXPORT_MAX_ROWS, $default);

    if ($max_rows < 0) {
        $max_rows = $default;
    }

    return (int) apply_filters('sitepulse_resource_monitor_export_max_rows', $max_rows);
}

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

/**
 * Registers the REST API routes that expose resource monitor insights.
 *
 * @return void
 */
function sitepulse_resource_monitor_register_rest_routes() {
    if (!function_exists('register_rest_route')) {
        return;
    }

    register_rest_route(
        'sitepulse/v1',
        '/resources/history',
        [
            'methods'             => defined('WP_REST_Server::READABLE') ? WP_REST_Server::READABLE : 'GET',
            'callback'            => 'sitepulse_resource_monitor_rest_history',
            'permission_callback' => 'sitepulse_resource_monitor_rest_permission_check',
            'args'                => [
                'per_page' => [
                    'description' => __('Nombre maximum d’entrées d’historique à retourner.', 'sitepulse'),
                    'type'        => 'integer',
                    'required'    => false,
                ],
                'page' => [
                    'description' => __('Numéro de page à retourner.', 'sitepulse'),
                    'type'        => 'integer',
                    'required'    => false,
                    'default'     => 1,
                ],
                'since' => [
                    'description' => __('Filtrer les entrées depuis un horodatage (Unix) ou une date ISO 8601.', 'sitepulse'),
                    'type'        => 'string',
                    'required'    => false,
                ],
                'include_snapshot' => [
                    'description' => __('Inclure le dernier instantané s’il est disponible en cache.', 'sitepulse'),
                    'type'        => 'boolean',
                    'required'    => false,
                    'default'     => false,
                ],
                'granularity' => [
                    'description' => __('Agrégation des points (raw, 15m, 1h, 1d).', 'sitepulse'),
                    'type'        => 'string',
                    'required'    => false,
                    'default'     => 'raw',
                ],
            ],
        ]
    );

    register_rest_route(
        'sitepulse/v1',
        '/resources/aggregates',
        [
            'methods'             => defined('WP_REST_Server::READABLE') ? WP_REST_Server::READABLE : 'GET',
            'callback'            => 'sitepulse_resource_monitor_rest_aggregates',
            'permission_callback' => 'sitepulse_resource_monitor_rest_permission_check',
            'args'                => [
                'since' => [
                    'description' => __('Filtrer les relevés depuis un horodatage ou une date ISO 8601.', 'sitepulse'),
                    'type'        => 'string',
                    'required'    => false,
                ],
                'granularity' => [
                    'description' => __('Granularité des agrégations (raw, 15m, 1h, 1d).', 'sitepulse'),
                    'type'        => 'string',
                    'required'    => false,
                    'default'     => 'raw',
                ],
            ],
        ]
    );

}

/**
 * Checks whether the current user can query the resource monitor REST endpoints.
 *
 * @return bool
 */
function sitepulse_resource_monitor_rest_permission_check() {
    $capability = function_exists('sitepulse_get_capability')
        ? sitepulse_get_capability()
        : 'manage_options';

    return current_user_can($capability);
}

/**
 * Handles the REST request returning resource history metrics.
 *
 * @param WP_REST_Request $request Incoming request instance.
 * @return WP_REST_Response|WP_Error
 */
function sitepulse_resource_monitor_rest_history($request) {
    $module_active = function_exists('sitepulse_is_module_active')
        ? sitepulse_is_module_active('resource_monitor')
        : true;

    if (!$module_active) {
        return new WP_Error(
            'sitepulse_resource_monitor_inactive',
            __('Le module Resource Monitor est désactivé.', 'sitepulse'),
            ['status' => 404]
        );
    }

    $per_page = absint($request->get_param('per_page'));

    if ($per_page <= 0) {
        $per_page = 288;
    }

    $max_per_page = (int) apply_filters('sitepulse_resource_monitor_rest_max_per_page', 1000);
    if ($max_per_page <= 0) {
        $max_per_page = 1000;
    }

    $per_page = max(1, min($max_per_page, $per_page));

    $page = absint($request->get_param('page'));

    if ($page <= 0) {
        $page = 1;
    }

    $raw_since = $request->get_param('since');
    $since_parse = sitepulse_resource_monitor_rest_parse_since_param($raw_since);

    if (isset($since_parse['error'])) {
        return new WP_Error(
            'sitepulse_resource_monitor_invalid_since',
            $since_parse['error'],
            ['status' => 400]
        );
    }

    $since_timestamp = isset($since_parse['timestamp']) ? (int) $since_parse['timestamp'] : null;

    $include_snapshot = $request->get_param('include_snapshot');
    if ($include_snapshot !== null) {
        if (function_exists('rest_sanitize_boolean')) {
            $include_snapshot = rest_sanitize_boolean($include_snapshot);
        } else {
            $include_snapshot = (bool) $include_snapshot;
        }
    } else {
        $include_snapshot = false;
    }

    $granularity = sitepulse_resource_monitor_rest_normalize_granularity($request->get_param('granularity'));

    $cache_args = [
        'per_page'         => $per_page,
        'page'             => $page,
        'since'            => $since_timestamp,
        'include_snapshot' => (bool) $include_snapshot,
        'granularity'      => $granularity,
    ];

    $cached_response = sitepulse_resource_monitor_get_cached_rest_response('rest_history', $cache_args);
    if ($cached_response !== null) {
        return rest_ensure_response($cached_response);
    }

    $history_entries = [];
    $history_query = [];
    $history_total_available = 0;
    $filtered_total = 0;
    $granularity_seconds = sitepulse_resource_monitor_get_granularity_seconds($granularity);
    $aggregated_source_count = 0;
    $last_cron_included = null;

    if ($granularity === 'raw') {
        $history_query = sitepulse_resource_monitor_get_history([
            'per_page' => $per_page,
            'page'     => $page,
            'since'    => $since_timestamp,
            'order'    => 'ASC',
        ]);

        $history_entries = isset($history_query['entries']) && is_array($history_query['entries'])
            ? $history_query['entries']
            : [];

        $history_total_available = isset($history_query['total']) ? (int) $history_query['total'] : count($history_entries);
        $filtered_total = isset($history_query['filtered']) ? (int) $history_query['filtered'] : count($history_entries);
        $last_cron_included = sitepulse_resource_monitor_get_last_cron_timestamp($history_entries);
    } else {
        $grouped_history = sitepulse_resource_monitor_get_grouped_history($granularity, [
            'per_page' => $per_page,
            'page'     => $page,
            'since'    => $since_timestamp,
            'order'    => 'ASC',
        ]);

        $history_entries = isset($grouped_history['entries']) && is_array($grouped_history['entries'])
            ? $grouped_history['entries']
            : [];

        $history_total_available = isset($grouped_history['total_raw'])
            ? (int) $grouped_history['total_raw']
            : count($history_entries);

        $filtered_total = isset($grouped_history['filtered_buckets'])
            ? (int) $grouped_history['filtered_buckets']
            : count($history_entries);

        $history_page = isset($grouped_history['page']) ? (int) $grouped_history['page'] : $page;
        $history_per_page = isset($grouped_history['per_page']) ? (int) $grouped_history['per_page'] : $per_page;
        $history_pages = isset($grouped_history['pages']) ? (int) $grouped_history['pages'] : ($filtered_total > 0 ? 1 : 0);
        $history_order = isset($grouped_history['order']) ? (string) $grouped_history['order'] : 'ASC';

        $history_query = [
            'entries'  => $history_entries,
            'total'    => $history_total_available,
            'filtered' => $filtered_total,
            'page'     => $history_page,
            'per_page' => $history_per_page,
            'pages'    => $history_pages,
            'order'    => $history_order,
        ];

        $page = $history_page;
        $per_page = $history_per_page;

        $aggregated_source_count = isset($grouped_history['aggregated_source_count'])
            ? (int) $grouped_history['aggregated_source_count']
            : $filtered_total;

        $last_cron_included = sitepulse_resource_monitor_get_last_cron_timestamp_since($since_timestamp);
    }

    $returned_count = count($history_entries);

    $history_summary = sitepulse_resource_monitor_calculate_history_summary($history_entries);
    $history_summary_text = sitepulse_resource_monitor_format_history_summary($history_summary);

    $history_prepared = sitepulse_resource_monitor_prepare_history_for_rest($history_entries);

    $latest_entry = !empty($history_prepared)
        ? $history_prepared[count($history_prepared) - 1]
        : null;

    $last_cron_overall = sitepulse_resource_monitor_get_last_cron_timestamp();

    if ($last_cron_included === null) {
        $last_cron_included = sitepulse_resource_monitor_get_last_cron_timestamp($history_entries);
    }

    $required_consecutive = sitepulse_resource_monitor_get_required_consecutive_snapshots();

    $response = [
        'generated_at' => function_exists('current_time')
            ? (int) current_time('timestamp', true)
            : time(),
        'request'      => [
            'per_page'         => $per_page,
            'page'             => isset($history_query['page']) ? (int) $history_query['page'] : $page,
            'since'            => $since_timestamp,
            'include_snapshot' => (bool) $include_snapshot,
            'granularity'      => $granularity,
        ],
        'history'      => [
            'total_available'      => $history_total_available,
            'filtered_count'       => $filtered_total,
            'returned_count'       => $returned_count,
            'page'                 => isset($history_query['page']) ? (int) $history_query['page'] : $page,
            'per_page'             => isset($history_query['per_page']) ? (int) $history_query['per_page'] : $per_page,
            'total_pages'          => isset($history_query['pages']) ? (int) $history_query['pages'] : 0,
            'order'                => isset($history_query['order']) ? $history_query['order'] : 'ASC',
            'last_cron_timestamp'  => $last_cron_overall,
            'last_cron_included'   => $last_cron_included,
            'required_consecutive' => $required_consecutive,
            'summary'              => $history_summary,
            'summary_text'         => $history_summary_text,
            'entries'              => $history_prepared,
            'latest_entry'         => $latest_entry,
            'granularity'          => $granularity,
            'granularity_seconds'  => $granularity_seconds,
            'aggregated_source_count' => $granularity === 'raw'
                ? $returned_count
                : $aggregated_source_count,
        ],
        'thresholds'   => sitepulse_resource_monitor_get_threshold_configuration(),
    ];

    if ($since_timestamp !== null) {
        $response['request']['since_iso'] = gmdate('c', $since_timestamp);
    }

    if ($include_snapshot) {
        $cached_snapshot = get_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);

        if (is_array($cached_snapshot) && isset($cached_snapshot['generated_at'])) {
            $response['snapshot'] = sitepulse_resource_monitor_rest_prepare_snapshot($cached_snapshot);
        } else {
            $response['snapshot'] = null;
        }
    }

    if (function_exists('apply_filters') && has_filter('sitepulse_resource_monitor_rest_response')) {
        $history_all_entries = sitepulse_resource_monitor_get_history([
            'per_page' => 0,
            'order'    => 'ASC',
        ]);

        $all_entries = isset($history_all_entries['entries']) && is_array($history_all_entries['entries'])
            ? $history_all_entries['entries']
            : [];

        $response = apply_filters(
            'sitepulse_resource_monitor_rest_response',
            $response,
            $request,
            $history_entries,
            $all_entries
        );
    }

    sitepulse_resource_monitor_cache_rest_response('rest_history', $cache_args, $response);

    return rest_ensure_response($response);
}

/**
 * Handles the REST request returning aggregated resource metrics.
 *
 * @param WP_REST_Request $request Incoming request instance.
 * @return WP_REST_Response|WP_Error
 */
function sitepulse_resource_monitor_rest_aggregates($request) {
    $module_active = function_exists('sitepulse_is_module_active')
        ? sitepulse_is_module_active('resource_monitor')
        : true;

    if (!$module_active) {
        return new WP_Error(
            'sitepulse_resource_monitor_inactive',
            __('Le module Resource Monitor est désactivé.', 'sitepulse'),
            ['status' => 404]
        );
    }

    $raw_since = $request->get_param('since');
    $since_parse = sitepulse_resource_monitor_rest_parse_since_param($raw_since);

    if (isset($since_parse['error'])) {
        return new WP_Error(
            'sitepulse_resource_monitor_invalid_since',
            $since_parse['error'],
            ['status' => 400]
        );
    }

    $since_timestamp = isset($since_parse['timestamp']) ? (int) $since_parse['timestamp'] : null;
    $granularity = sitepulse_resource_monitor_rest_normalize_granularity($request->get_param('granularity'));

    $cache_args = [
        'since'       => $since_timestamp,
        'granularity' => $granularity,
    ];

    $cached = sitepulse_resource_monitor_get_cached_rest_response('aggregates', $cache_args);
    if ($cached !== null) {
        return rest_ensure_response($cached);
    }

    $history_query = sitepulse_resource_monitor_get_history([
        'per_page' => 0,
        'page'     => 1,
        'since'    => $since_timestamp,
        'order'    => 'ASC',
    ]);

    $raw_entries = isset($history_query['entries']) && is_array($history_query['entries'])
        ? $history_query['entries']
        : [];

    $entries = $granularity === 'raw'
        ? $raw_entries
        : sitepulse_resource_monitor_group_history_entries($raw_entries, $granularity);

    $granularity_seconds = sitepulse_resource_monitor_get_granularity_seconds($granularity);
    $samples_count = count($entries);
    $raw_count = count($raw_entries);

    $first_timestamp = null;
    $last_timestamp = null;
    $span = 0;

    if ($samples_count > 0) {
        $first_timestamp = (int) $entries[0]['timestamp'];
        $last_timestamp = (int) $entries[$samples_count - 1]['timestamp'];
        $span = max(0, $last_timestamp - $first_timestamp);
    }

    $metrics = sitepulse_resource_monitor_calculate_aggregate_metrics($entries);
    $summary = sitepulse_resource_monitor_calculate_history_summary($entries);
    $summary_text = sitepulse_resource_monitor_format_history_summary($summary);

    $source_counts = [];
    foreach ($raw_entries as $entry) {
        $source = isset($entry['source']) ? (string) $entry['source'] : 'manual';
        if ($source === '') {
            $source = 'manual';
        }
        if (!isset($source_counts[$source])) {
            $source_counts[$source] = 0;
        }
        $source_counts[$source]++;
    }
    ksort($source_counts);

    $latest_entry = null;
    if (!empty($entries)) {
        $prepared_latest = sitepulse_resource_monitor_prepare_history_for_rest([
            $entries[$samples_count - 1],
        ]);
        $latest_entry = !empty($prepared_latest) ? $prepared_latest[0] : null;
    }

    $response = [
        'generated_at' => function_exists('current_time')
            ? (int) current_time('timestamp', true)
            : time(),
        'request'      => [
            'since'       => $since_timestamp,
            'granularity' => $granularity,
        ],
        'samples'      => [
            'count'               => $samples_count,
            'raw_count'           => $raw_count,
            'span'                => $span,
            'first_timestamp'     => $first_timestamp,
            'last_timestamp'      => $last_timestamp,
            'granularity_seconds' => $granularity_seconds,
            'sources'             => $source_counts,
        ],
        'metrics'      => $metrics,
        'summary'      => $summary,
        'summary_text' => $summary_text,
        'latest_entry' => $latest_entry,
    ];

    if ($since_timestamp !== null) {
        $response['request']['since_iso'] = gmdate('c', $since_timestamp);
    }

    sitepulse_resource_monitor_cache_rest_response('aggregates', $cache_args, $response);

    return rest_ensure_response($response);
}

/**
 * Parses the `since` parameter accepted by the REST route.
 *
 * @param mixed $value Raw parameter value.
 * @return array<string,mixed>
 */
function sitepulse_resource_monitor_rest_parse_since_param($value) {
    if ($value === null || $value === '') {
        return ['timestamp' => null];
    }

    if (is_int($value) || (is_numeric($value) && (string) (int) $value === (string) trim((string) $value))) {
        $timestamp = (int) $value;

        if ($timestamp <= 0) {
            return [
                'error' => __('Le paramètre since doit être un horodatage Unix positif ou une date valide.', 'sitepulse'),
            ];
        }

        return ['timestamp' => $timestamp];
    }

    if (is_string($value)) {
        $candidate = trim($value);

        if ($candidate === '') {
            return ['timestamp' => null];
        }

        $parsed = strtotime($candidate);

        if ($parsed === false) {
            return [
                'error' => __('Impossible d’interpréter la valeur fournie pour le paramètre since.', 'sitepulse'),
            ];
        }

        return ['timestamp' => $parsed];
    }

    return [
        'error' => __('Le paramètre since doit être un horodatage Unix positif ou une date valide.', 'sitepulse'),
    ];
}

/**
 * Normalizes the `granularity` REST parameter.
 *
 * @param string|null $value Raw granularity value.
 * @return string One of 'raw', '15m', '1h', '1d'.
 */
function sitepulse_resource_monitor_rest_normalize_granularity($value) {
    $default = 'raw';

    if (!is_string($value) || $value === '') {
        return $default;
    }

    $candidate = strtolower(trim($value));

    $aliases = [
        'raw'  => ['raw', 'none', 'brut'],
        '15m'  => ['15m', '15min', '15 minutes', 'quarter'],
        '1h'   => ['1h', '60m', 'hour', '1 hour'],
        '1d'   => ['1d', '24h', 'day', '1 day'],
    ];

    foreach ($aliases as $normalized => $list) {
        if (in_array($candidate, $list, true)) {
            return $normalized;
        }
    }

    return $default;
}

/**
 * Retrieves the number of seconds represented by a granularity slug.
 *
 * @param string $granularity Granularity identifier.
 * @return int|null Number of seconds or null when using raw data.
 */
function sitepulse_resource_monitor_get_granularity_seconds($granularity) {
    switch ($granularity) {
        case '15m':
            return 15 * MINUTE_IN_SECONDS;
        case '1h':
            return HOUR_IN_SECONDS;
        case '1d':
            return DAY_IN_SECONDS;
        default:
            return null;
    }
}

/**
 * Retrieves grouped history entries for a given granularity without loading the entire history.
 *
 * @param string               $granularity Granularity identifier.
 * @param array<string, mixed> $args        Query arguments.
 * @return array<string, mixed>
 */
function sitepulse_resource_monitor_get_grouped_history($granularity, array $args) {
    $seconds = sitepulse_resource_monitor_get_granularity_seconds($granularity);

    $defaults = [
        'per_page' => 0,
        'page'     => 1,
        'since'    => null,
        'order'    => 'ASC',
    ];

    if (function_exists('wp_parse_args')) {
        $args = wp_parse_args($args, $defaults);
    } else {
        $args = array_merge($defaults, is_array($args) ? $args : []);
    }

    $per_page = (int) $args['per_page'];
    $per_page = $per_page >= 0 ? $per_page : 0;
    $page = (int) $args['page'];
    $page = $page > 0 ? $page : 1;
    $since = $args['since'];
    $order = strtoupper((string) $args['order']) === 'DESC' ? 'DESC' : 'ASC';

    if ($seconds === null || $seconds <= 0) {
        $raw_history = sitepulse_resource_monitor_get_history([
            'per_page' => $per_page,
            'page'     => $page,
            'since'    => $since,
            'order'    => $order,
        ]);

        return [
            'entries'                 => isset($raw_history['entries']) && is_array($raw_history['entries']) ? $raw_history['entries'] : [],
            'total_raw'               => isset($raw_history['total']) ? (int) $raw_history['total'] : 0,
            'filtered_raw'            => isset($raw_history['filtered']) ? (int) $raw_history['filtered'] : 0,
            'filtered_buckets'        => isset($raw_history['filtered']) ? (int) $raw_history['filtered'] : 0,
            'page'                    => isset($raw_history['page']) ? (int) $raw_history['page'] : $page,
            'per_page'                => isset($raw_history['per_page']) ? (int) $raw_history['per_page'] : $per_page,
            'pages'                   => isset($raw_history['pages']) ? (int) $raw_history['pages'] : 0,
            'order'                   => isset($raw_history['order']) ? (string) $raw_history['order'] : $order,
            'aggregated_source_count' => isset($raw_history['filtered']) ? (int) $raw_history['filtered'] : 0,
        ];
    }

    $since_timestamp = null;
    if ($since !== null) {
        $since_timestamp = is_numeric($since) ? (int) $since : null;

        if ($since_timestamp !== null && $since_timestamp <= 0) {
            $since_timestamp = null;
        }
    }

    sitepulse_resource_monitor_maybe_upgrade_schema();

    if (!sitepulse_resource_monitor_table_exists()) {
        $history = sitepulse_resource_monitor_get_history([
            'per_page' => 0,
            'since'    => $since_timestamp,
            'order'    => $order,
        ]);

        $entries = isset($history['entries']) && is_array($history['entries']) ? $history['entries'] : [];
        $grouped_entries = sitepulse_resource_monitor_group_history_entries($entries, $granularity);

        $filtered_buckets = count($grouped_entries);
        $total_raw = isset($history['total']) ? (int) $history['total'] : 0;
        $filtered_raw = isset($history['filtered']) ? (int) $history['filtered'] : count($entries);

        if ($per_page > 0) {
            $pages = $filtered_buckets > 0 ? (int) ceil($filtered_buckets / $per_page) : 0;

            if ($pages > 0) {
                $page = max(1, min($page, $pages));
            } else {
                $page = 1;
            }

            $entries_page = array_slice($grouped_entries, ($page - 1) * $per_page, $per_page);
        } else {
            $entries_page = $grouped_entries;
            $pages = $filtered_buckets > 0 ? 1 : 0;
            $page = 1;
        }

        $aggregated_source_count = $filtered_raw;

        return [
            'entries'                 => $entries_page,
            'total_raw'               => $total_raw,
            'filtered_raw'            => $filtered_raw,
            'filtered_buckets'        => $filtered_buckets,
            'page'                    => $page,
            'per_page'                => $per_page,
            'pages'                   => $pages,
            'order'                   => $order,
            'aggregated_source_count' => $aggregated_source_count,
        ];
    }

    global $wpdb;

    $entries = [];
    $total_raw = 0;
    $filtered_raw = 0;
    $filtered_buckets = 0;
    $pages = 0;
    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '' || !($wpdb instanceof wpdb)) {
        return [
            'entries'                 => $entries,
            'total_raw'               => $total_raw,
            'filtered_raw'            => $filtered_raw,
            'filtered_buckets'        => $filtered_buckets,
            'page'                    => $page,
            'per_page'                => $per_page,
            'pages'                   => $pages,
            'order'                   => $order,
            'aggregated_source_count' => $aggregated_source_count,
        ];
    }

    $where_clauses = [];
    $where_params = [];

    if ($since_timestamp !== null) {
        $where_clauses[] = 'recorded_at >= %d';
        $where_params[] = $since_timestamp;
    }

    $where_sql = '';

    if (!empty($where_clauses)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    }

    $total_raw = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

    if ($since_timestamp !== null) {
        $filtered_raw_query = "SELECT COUNT(*) FROM {$table} {$where_sql}";

        if (!empty($where_params)) {
            $filtered_raw_query = $wpdb->prepare($filtered_raw_query, $where_params);
        }

        $filtered_raw = (int) $wpdb->get_var($filtered_raw_query);
    } else {
        $filtered_raw = $total_raw;
    }

    $bucket_expression = "FLOOR(recorded_at / {$seconds}) * {$seconds}";
    $bucket_count_query = "SELECT COUNT(*) FROM (SELECT 1 FROM {$table} {$where_sql} GROUP BY {$bucket_expression}) AS bucket_counts";

    if (!empty($where_params)) {
        $bucket_count_query = $wpdb->prepare($bucket_count_query, $where_params);
    }

    $filtered_buckets = (int) $wpdb->get_var($bucket_count_query);

    if ($per_page > 0) {
        $pages = $filtered_buckets > 0 ? (int) ceil($filtered_buckets / $per_page) : 0;

        if ($pages > 0) {
            $page = max(1, min($page, $pages));
        } else {
            $page = 1;
        }

        $offset = max(0, ($page - 1) * $per_page);
        $limit_sql = $wpdb->prepare(' LIMIT %d OFFSET %d', $per_page, $offset);
    } else {
        $limit_sql = '';
        $pages = $filtered_buckets > 0 ? 1 : 0;
        $page = 1;
    }

    $select_query = "SELECT {$bucket_expression} AS bucket,
        AVG(load_1) AS avg_load_1,
        AVG(load_5) AS avg_load_5,
        AVG(load_15) AS avg_load_15,
        AVG(memory_usage) AS avg_memory_usage,
        AVG(memory_limit) AS avg_memory_limit,
        AVG(disk_free) AS avg_disk_free,
        AVG(disk_total) AS avg_disk_total,
        GROUP_CONCAT(DISTINCT source ORDER BY source SEPARATOR ',') AS sources,
        COUNT(*) AS aggregated_from
        FROM {$table} {$where_sql}
        GROUP BY bucket
        ORDER BY bucket {$order}{$limit_sql}";

    if (!empty($where_params)) {
        $select_query = $wpdb->prepare($select_query, $where_params);
    }

    $rows = $wpdb->get_results($select_query, ARRAY_A);

    if (is_array($rows)) {
        foreach ($rows as $row) {
            $timestamp = isset($row['bucket']) ? (int) $row['bucket'] : 0;

            if ($timestamp <= 0) {
                continue;
            }

            $sources = [];

            if (isset($row['sources']) && is_string($row['sources']) && $row['sources'] !== '') {
                $sources_list = array_map('trim', explode(',', $row['sources']));
                $sources = array_values(array_unique(array_filter(
                    $sources_list,
                    static function ($value) {
                        return $value !== '';
                    }
                )));
            }

            $aggregated_from = isset($row['aggregated_from']) ? (int) $row['aggregated_from'] : 0;

            $entries[] = [
                'timestamp'        => $timestamp,
                'load'             => [
                    isset($row['avg_load_1']) ? (float) $row['avg_load_1'] : null,
                    isset($row['avg_load_5']) ? (float) $row['avg_load_5'] : null,
                    isset($row['avg_load_15']) ? (float) $row['avg_load_15'] : null,
                ],
                'memory'           => [
                    'usage' => isset($row['avg_memory_usage']) && $row['avg_memory_usage'] !== null ? (int) round((float) $row['avg_memory_usage']) : null,
                    'limit' => isset($row['avg_memory_limit']) && $row['avg_memory_limit'] !== null ? (int) round((float) $row['avg_memory_limit']) : null,
                ],
                'disk'             => [
                    'free'  => isset($row['avg_disk_free']) && $row['avg_disk_free'] !== null ? (int) round((float) $row['avg_disk_free']) : null,
                    'total' => isset($row['avg_disk_total']) && $row['avg_disk_total'] !== null ? (int) round((float) $row['avg_disk_total']) : null,
                ],
                'source'           => 'aggregate',
                'aggregated_from'  => $aggregated_from,
                'granularity'      => $granularity,
                'sources'          => $sources,
            ];

        }
    }

    return [
        'entries'                 => $entries,
        'total_raw'               => $total_raw,
        'filtered_raw'            => $filtered_raw,
        'filtered_buckets'        => $filtered_buckets,
        'page'                    => $page,
        'per_page'                => $per_page,
        'pages'                   => $pages,
        'order'                   => $order,
        'aggregated_source_count' => $filtered_raw,
    ];
}

/**
 * Groups history entries according to the requested granularity.
 *
 * @param array<int, array> $entries History entries sorted chronologically.
 * @param string            $granularity Requested granularity slug.
 * @return array<int, array> Aggregated entries.
 */
function sitepulse_resource_monitor_group_history_entries(array $entries, $granularity) {
    $seconds = sitepulse_resource_monitor_get_granularity_seconds($granularity);

    if ($seconds === null || $seconds <= 0) {
        return $entries;
    }

    $buckets = [];

    foreach ($entries as $entry) {
        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

        if ($timestamp <= 0) {
            continue;
        }

        $bucket_key = (int) floor($timestamp / $seconds) * $seconds;

        if (!isset($buckets[$bucket_key])) {
            $buckets[$bucket_key] = [
                'count'        => 0,
                'load_1'       => [],
                'load_5'       => [],
                'load_15'      => [],
                'memory_usage' => [],
                'memory_limit' => [],
                'disk_free'    => [],
                'disk_total'   => [],
                'sources'      => [],
            ];
        }

        $buckets[$bucket_key]['count']++;

        if (isset($entry['load'][0]) && is_numeric($entry['load'][0])) {
            $buckets[$bucket_key]['load_1'][] = (float) $entry['load'][0];
        }

        if (isset($entry['load'][1]) && is_numeric($entry['load'][1])) {
            $buckets[$bucket_key]['load_5'][] = (float) $entry['load'][1];
        }

        if (isset($entry['load'][2]) && is_numeric($entry['load'][2])) {
            $buckets[$bucket_key]['load_15'][] = (float) $entry['load'][2];
        }

        if (isset($entry['memory']['usage']) && is_numeric($entry['memory']['usage'])) {
            $buckets[$bucket_key]['memory_usage'][] = (float) $entry['memory']['usage'];
        }

        if (isset($entry['memory']['limit']) && is_numeric($entry['memory']['limit'])) {
            $buckets[$bucket_key]['memory_limit'][] = (float) $entry['memory']['limit'];
        }

        if (isset($entry['disk']['free']) && is_numeric($entry['disk']['free'])) {
            $buckets[$bucket_key]['disk_free'][] = (float) $entry['disk']['free'];
        }

        if (isset($entry['disk']['total']) && is_numeric($entry['disk']['total'])) {
            $buckets[$bucket_key]['disk_total'][] = (float) $entry['disk']['total'];
        }

        $source = isset($entry['source']) ? (string) $entry['source'] : 'manual';
        if ($source === '') {
            $source = 'manual';
        }
        $buckets[$bucket_key]['sources'][$source] = true;
    }

    if (empty($buckets)) {
        return [];
    }

    ksort($buckets);

    $aggregated = [];

    foreach ($buckets as $bucket_timestamp => $bucket) {
        $load_1 = sitepulse_resource_monitor_calculate_average($bucket['load_1']);
        $load_5 = sitepulse_resource_monitor_calculate_average($bucket['load_5']);
        $load_15 = sitepulse_resource_monitor_calculate_average($bucket['load_15']);

        $memory_usage = sitepulse_resource_monitor_calculate_average($bucket['memory_usage']);
        $memory_limit = sitepulse_resource_monitor_calculate_average($bucket['memory_limit']);
        $disk_free = sitepulse_resource_monitor_calculate_average($bucket['disk_free']);
        $disk_total = sitepulse_resource_monitor_calculate_average($bucket['disk_total']);

        $aggregated[] = [
            'timestamp'        => $bucket_timestamp,
            'load'             => [
                $load_1 !== null ? (float) $load_1 : null,
                $load_5 !== null ? (float) $load_5 : null,
                $load_15 !== null ? (float) $load_15 : null,
            ],
            'memory'           => [
                'usage' => $memory_usage !== null ? (int) round($memory_usage) : null,
                'limit' => $memory_limit !== null ? (int) round($memory_limit) : null,
            ],
            'disk'             => [
                'free'  => $disk_free !== null ? (int) round($disk_free) : null,
                'total' => $disk_total !== null ? (int) round($disk_total) : null,
            ],
            'source'           => 'aggregate',
            'aggregated_from'  => (int) $bucket['count'],
            'granularity'      => $granularity,
            'sources'          => array_keys($bucket['sources']),
        ];
    }

    return $aggregated;
}

/**
 * Generates a cache key for a given cache group and arguments.
 *
 * @param string               $group Cache group identifier.
 * @param array<string, mixed> $args  Arguments influencing the cache entry.
 * @return string|null Cache key or null on failure.
 */
function sitepulse_resource_monitor_build_cache_key($group, array $args) {
    switch ($group) {
        case 'rest_history':
            $prefix = defined('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_HISTORY_CACHE_PREFIX')
                ? SITEPULSE_TRANSIENT_RESOURCE_MONITOR_HISTORY_CACHE_PREFIX
                : 'sitepulse_resource_monitor_rest_history_';
            break;
        case 'aggregates':
            $prefix = defined('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_AGGREGATE_CACHE_PREFIX')
                ? SITEPULSE_TRANSIENT_RESOURCE_MONITOR_AGGREGATE_CACHE_PREFIX
                : 'sitepulse_resource_monitor_aggregates_';
            break;
        default:
            return null;
    }

    if ($prefix === '') {
        return null;
    }

    $encoded = function_exists('wp_json_encode') ? wp_json_encode($args) : json_encode($args);

    if (!is_string($encoded) || $encoded === '') {
        return null;
    }

    return $prefix . md5($encoded);
}

/**
 * Retrieves the cache registry option used to invalidate analytics caches.
 *
 * @return array<string, array<int, string>>
 */
function sitepulse_resource_monitor_get_cache_registry() {
    $registry = function_exists('get_option')
        ? get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_CACHE_KEYS, [])
        : [];

    return is_array($registry) ? $registry : [];
}

/**
 * Stores the cache registry option.
 *
 * @param array<string, array<int, string>> $registry Cache registry map.
 * @return void
 */
function sitepulse_resource_monitor_set_cache_registry(array $registry) {
    if (!function_exists('update_option')) {
        return;
    }

    update_option(SITEPULSE_OPTION_RESOURCE_MONITOR_CACHE_KEYS, $registry, false);
}

/**
 * Tracks a cache key under the specified group for later invalidation.
 *
 * @param string $group Cache group identifier.
 * @param string $key   Cache key to track.
 * @return void
 */
function sitepulse_resource_monitor_register_cache_key($group, $key) {
    if ($key === '') {
        return;
    }

    $registry = sitepulse_resource_monitor_get_cache_registry();

    if (!isset($registry[$group]) || !is_array($registry[$group])) {
        $registry[$group] = [];
    }

    if (!in_array($key, $registry[$group], true)) {
        $registry[$group][] = $key;
        sitepulse_resource_monitor_set_cache_registry($registry);
    }
}

/**
 * Deletes the cached entries for the provided cache group.
 *
 * @param string|null $group Cache group to invalidate. When null, flushes all tracked groups.
 * @return void
 */
function sitepulse_resource_monitor_clear_cache_group($group = null) {
    if (!function_exists('delete_transient')) {
        return;
    }

    $registry = sitepulse_resource_monitor_get_cache_registry();

    $groups = $group !== null ? [$group] : array_keys($registry);

    foreach ($groups as $group_key) {
        if (!isset($registry[$group_key]) || !is_array($registry[$group_key])) {
            continue;
        }

        foreach ($registry[$group_key] as $cache_key) {
            delete_transient($cache_key);
        }

        $registry[$group_key] = [];
    }

    sitepulse_resource_monitor_set_cache_registry($registry);
}

/**
 * Retrieves a cached REST response when available.
 *
 * @param string               $group Cache group identifier.
 * @param array<string, mixed> $args  Cache arguments.
 * @return array|null Cached response or null.
 */
function sitepulse_resource_monitor_get_cached_rest_response($group, array $args) {
    if (!function_exists('get_transient')) {
        return null;
    }

    $key = sitepulse_resource_monitor_build_cache_key($group, $args);

    if ($key === null) {
        return null;
    }

    $cached = get_transient($key);

    if ($cached === false || !is_array($cached)) {
        return null;
    }

    return $cached;
}

/**
 * Stores a REST response in the transient cache and registers the key.
 *
 * @param string               $group    Cache group identifier.
 * @param array<string, mixed> $args     Cache arguments.
 * @param array<string, mixed> $response Response payload.
 * @return void
 */
function sitepulse_resource_monitor_cache_rest_response($group, array $args, array $response) {
    if (!function_exists('set_transient')) {
        return;
    }

    $key = sitepulse_resource_monitor_build_cache_key($group, $args);

    if ($key === null) {
        return;
    }

    $default_ttl = $group === 'rest_history' ? 60 : 120;

    if (function_exists('apply_filters')) {
        $filter = $group === 'rest_history'
            ? 'sitepulse_resource_monitor_rest_history_cache_ttl'
            : 'sitepulse_resource_monitor_rest_aggregates_cache_ttl';

        $default_ttl = (int) apply_filters($filter, $default_ttl, $args, $response);
    }

    $ttl = $default_ttl > 0 ? $default_ttl : 60;

    set_transient($key, $response, $ttl);
    sitepulse_resource_monitor_register_cache_key($group, $key);
}

/**
 * Clears all caches related to REST analytics endpoints.
 *
 * @return void
 */
function sitepulse_resource_monitor_invalidate_analytics_cache() {
    sitepulse_resource_monitor_clear_cache_group('rest_history');
    sitepulse_resource_monitor_clear_cache_group('aggregates');
}

/**
 * Calculates percentiles for a numeric dataset.
 *
 * @param array<int, float> $values Numeric values.
 * @param array<int, float> $percentiles Percentile thresholds (0-100).
 * @return array<string, float|null>
 */
function sitepulse_resource_monitor_calculate_percentiles(array $values, array $percentiles) {
    $values = array_values(array_filter($values, static function ($value) {
        return is_numeric($value);
    }));

    sort($values);

    $results = [];

    if (empty($values)) {
        foreach ($percentiles as $percentile) {
            $key = 'p' . (int) round($percentile);
            $results[$key] = null;
        }

        return $results;
    }

    $count = count($values);

    foreach ($percentiles as $percentile) {
        $percentile = max(0.0, min(100.0, (float) $percentile));
        $rank = ($percentile / 100) * ($count - 1);
        $lower_index = (int) floor($rank);
        $upper_index = (int) ceil($rank);
        $weight = $rank - $lower_index;

        $lower_value = $values[$lower_index];
        $upper_value = $values[$upper_index] ?? $lower_value;

        $interpolated = $lower_value + ($upper_value - $lower_value) * $weight;
        $key = 'p' . (int) round($percentile);

        $results[$key] = (float) $interpolated;
    }

    return $results;
}

/**
 * Calculates the trend of a metric using linear regression.
 *
 * @param array<int, array> $entries History entries.
 * @param callable          $value_callback Callback returning the metric value for an entry.
 * @return array<string, mixed>
 */
function sitepulse_resource_monitor_calculate_metric_trend(array $entries, callable $value_callback) {
    $points = [];

    foreach ($entries as $entry) {
        if (!isset($entry['timestamp'])) {
            continue;
        }

        $value = $value_callback($entry);

        if ($value === null) {
            continue;
        }

        $points[] = [
            'timestamp' => (int) $entry['timestamp'],
            'value'     => (float) $value,
        ];
    }

    $count = count($points);

    if ($count < 2) {
        return [
            'direction'      => 'flat',
            'slope_per_hour' => 0.0,
            'absolute_change'=> 0.0,
            'percent_change' => null,
            'start'          => $count === 1 ? $points[0] : null,
            'end'            => $count === 1 ? $points[0] : null,
            'sample_size'    => $count,
        ];
    }

    $origin = $points[0]['timestamp'];
    $sum_x = 0.0;
    $sum_y = 0.0;
    $sum_xy = 0.0;
    $sum_x2 = 0.0;

    foreach ($points as $point) {
        $x = ($point['timestamp'] - $origin) / 60.0; // minutes to limit floating errors.
        $y = $point['value'];

        $sum_x += $x;
        $sum_y += $y;
        $sum_xy += $x * $y;
        $sum_x2 += $x * $x;
    }

    $denominator = ($count * $sum_x2) - ($sum_x * $sum_x);
    $slope_per_minute = $denominator !== 0.0
        ? (($count * $sum_xy) - ($sum_x * $sum_y)) / $denominator
        : 0.0;

    $slope_per_hour = $slope_per_minute * 60.0;

    $first = $points[0];
    $last = $points[$count - 1];
    $absolute_change = $last['value'] - $first['value'];
    $percent_change = $first['value'] != 0.0
        ? ($absolute_change / $first['value']) * 100.0
        : null;

    $direction = 'flat';

    if ($slope_per_hour > 0.01) {
        $direction = 'up';
    } elseif ($slope_per_hour < -0.01) {
        $direction = 'down';
    }

    return [
        'direction'       => $direction,
        'slope_per_hour'  => $slope_per_hour,
        'absolute_change' => $absolute_change,
        'percent_change'  => $percent_change,
        'start'           => $first,
        'end'             => $last,
        'sample_size'     => $count,
    ];
}

/**
 * Retrieves the most recent numeric value for a given metric.
 *
 * @param array<int, array> $entries History entries.
 * @param callable          $value_callback Callback returning the metric value.
 * @return float|null Latest value or null.
 */
function sitepulse_resource_monitor_get_latest_metric_value(array $entries, callable $value_callback) {
    for ($index = count($entries) - 1; $index >= 0; $index--) {
        $value = $value_callback($entries[$index]);

        if ($value !== null) {
            return (float) $value;
        }
    }

    return null;
}

/**
 * Calculates aggregated metrics (averages, percentiles, trends) for key indicators.
 *
 * @param array<int, array> $entries History entries.
 * @return array<string, array<string, mixed>>
 */
function sitepulse_resource_monitor_calculate_aggregate_metrics(array $entries) {
    $series = [
        'load_1'         => [],
        'load_5'         => [],
        'load_15'        => [],
        'memory_percent' => [],
        'disk_used'      => [],
    ];

    foreach ($entries as $entry) {
        if (isset($entry['load'][0]) && is_numeric($entry['load'][0])) {
            $series['load_1'][] = (float) $entry['load'][0];
        }

        if (isset($entry['load'][1]) && is_numeric($entry['load'][1])) {
            $series['load_5'][] = (float) $entry['load'][1];
        }

        if (isset($entry['load'][2]) && is_numeric($entry['load'][2])) {
            $series['load_15'][] = (float) $entry['load'][2];
        }

        $memory_percent = sitepulse_resource_monitor_calculate_percentage(
            $entry['memory']['usage'] ?? null,
            $entry['memory']['limit'] ?? null
        );

        if ($memory_percent !== null) {
            $series['memory_percent'][] = (float) $memory_percent;
        }

        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage(
            $entry['disk']['free'] ?? null,
            $entry['disk']['total'] ?? null
        );

        if ($disk_percent_free !== null) {
            $series['disk_used'][] = max(0.0, min(100.0, 100.0 - $disk_percent_free));
        }
    }

    $percentile_thresholds = [50, 90, 95, 99];

    $metric_map = [
        'load_1' => function ($entry) {
            return isset($entry['load'][0]) && is_numeric($entry['load'][0]) ? (float) $entry['load'][0] : null;
        },
        'load_5' => function ($entry) {
            return isset($entry['load'][1]) && is_numeric($entry['load'][1]) ? (float) $entry['load'][1] : null;
        },
        'load_15' => function ($entry) {
            return isset($entry['load'][2]) && is_numeric($entry['load'][2]) ? (float) $entry['load'][2] : null;
        },
        'memory_percent' => function ($entry) {
            return sitepulse_resource_monitor_calculate_percentage(
                $entry['memory']['usage'] ?? null,
                $entry['memory']['limit'] ?? null
            );
        },
        'disk_used' => function ($entry) {
            $disk_percent_free = sitepulse_resource_monitor_calculate_percentage(
                $entry['disk']['free'] ?? null,
                $entry['disk']['total'] ?? null
            );

            if ($disk_percent_free === null) {
                return null;
            }

            return max(0.0, min(100.0, 100.0 - $disk_percent_free));
        },
    ];

    $results = [];

    foreach ($series as $key => $values) {
        $average = sitepulse_resource_monitor_calculate_average($values);
        $latest = sitepulse_resource_monitor_get_latest_metric_value($entries, $metric_map[$key]);
        $max = !empty($values) ? max($values) : null;
        $percentiles = sitepulse_resource_monitor_calculate_percentiles($values, $percentile_thresholds);
        $trend = sitepulse_resource_monitor_calculate_metric_trend($entries, $metric_map[$key]);

        $results[$key] = [
            'average'     => $average !== null ? (float) $average : null,
            'latest'      => $latest,
            'max'         => $max !== null ? (float) $max : null,
            'percentiles' => $percentiles,
            'trend'       => $trend,
            'samples'     => count($values),
        ];
    }

    return $results;
}

/**
 * Builds a heatmap dataset (date/hour buckets) from history entries.
 *
 * @param array<int, array> $entries History entries.
 * @return array<int, array<string, mixed>>
 */
function sitepulse_resource_monitor_build_heatmap_data(array $entries) {
    $buckets = [];

    foreach ($entries as $entry) {
        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

        if ($timestamp <= 0) {
            continue;
        }

        $day = gmdate('Y-m-d', $timestamp);
        $hour = (int) gmdate('G', $timestamp);

        if (!isset($buckets[$day])) {
            $buckets[$day] = [];
        }

        if (!isset($buckets[$day][$hour])) {
            $buckets[$day][$hour] = [
                'samples'          => 0,
                'load_1'           => [],
                'memory_percent'   => [],
                'disk_used_percent'=> [],
            ];
        }

        if (isset($entry['load'][0]) && is_numeric($entry['load'][0])) {
            $buckets[$day][$hour]['load_1'][] = (float) $entry['load'][0];
        }

        $memory_percent = sitepulse_resource_monitor_calculate_percentage(
            $entry['memory']['usage'] ?? null,
            $entry['memory']['limit'] ?? null
        );
        if ($memory_percent !== null) {
            $buckets[$day][$hour]['memory_percent'][] = (float) $memory_percent;
        }

        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage(
            $entry['disk']['free'] ?? null,
            $entry['disk']['total'] ?? null
        );
        if ($disk_percent_free !== null) {
            $buckets[$day][$hour]['disk_used_percent'][] = max(0.0, min(100.0, 100.0 - $disk_percent_free));
        }

        $buckets[$day][$hour]['samples']++;
    }

    if (empty($buckets)) {
        return [];
    }

    ksort($buckets);

    $heatmap = [];

    foreach ($buckets as $day => $hours) {
        ksort($hours);

        $hour_rows = [];

        foreach ($hours as $hour => $bucket) {
            $hour_rows[] = [
                'hour'              => (int) $hour,
                'load_1'            => !empty($bucket['load_1']) ? (float) sitepulse_resource_monitor_calculate_average($bucket['load_1']) : null,
                'memory_percent'    => !empty($bucket['memory_percent']) ? (float) sitepulse_resource_monitor_calculate_average($bucket['memory_percent']) : null,
                'disk_used_percent' => !empty($bucket['disk_used_percent']) ? (float) sitepulse_resource_monitor_calculate_average($bucket['disk_used_percent']) : null,
                'samples'           => (int) $bucket['samples'],
            ];
        }

        $heatmap[] = [
            'date'  => $day,
            'hours' => $hour_rows,
        ];
    }

    return $heatmap;
}

/**
 * Extracts drift information from the aggregated metrics.
 *
 * @param array<string, array<string, mixed>> $metrics Aggregated metrics including trend data.
 * @return array<string, array<string, mixed>>
 */
function sitepulse_resource_monitor_calculate_drift_summary(array $metrics) {
    $drift = [];

    foreach ($metrics as $key => $metric) {
        $trend = isset($metric['trend']) && is_array($metric['trend']) ? $metric['trend'] : [];

        $drift[$key] = [
            'direction'       => $trend['direction'] ?? 'flat',
            'absolute_change' => isset($trend['absolute_change']) ? (float) $trend['absolute_change'] : 0.0,
            'percent_change'  => isset($trend['percent_change']) ? (float) $trend['percent_change'] : null,
            'slope_per_hour'  => isset($trend['slope_per_hour']) ? (float) $trend['slope_per_hour'] : 0.0,
            'start'           => isset($trend['start']) ? $trend['start'] : null,
            'end'             => isset($trend['end']) ? $trend['end'] : null,
        ];
    }

    return $drift;
}

/**
 * Builds CSV and JSON exports for scheduled reports.
 *
 * @param array<string, mixed> $report Report payload (generated_at, metrics, heatmap, drift, summary).
 * @return array<string, string>
 */
function sitepulse_resource_monitor_prepare_report_exports(array $report) {
    $generated_at = isset($report['generated_at']) ? (int) $report['generated_at'] : (function_exists('current_time') ? (int) current_time('timestamp', true) : time());
    $heatmap = isset($report['heatmap']) && is_array($report['heatmap']) ? $report['heatmap'] : [];
    $drift = isset($report['drift']) && is_array($report['drift']) ? $report['drift'] : [];
    $metrics = isset($report['metrics']) && is_array($report['metrics']) ? $report['metrics'] : [];
    $summary = isset($report['summary']) && is_array($report['summary']) ? $report['summary'] : [];

    $csv_stream = fopen('php://temp', 'r+');

    if (is_resource($csv_stream)) {
        fputcsv($csv_stream, [
            __('Date', 'sitepulse'),
            __('Heure', 'sitepulse'),
            __('Charge (1 min)', 'sitepulse'),
            __('Mémoire (%)', 'sitepulse'),
            __('Disque utilisé (%)', 'sitepulse'),
            __('Échantillons', 'sitepulse'),
        ], ';');

        foreach ($heatmap as $day_bucket) {
            $date = isset($day_bucket['date']) ? (string) $day_bucket['date'] : '';
            $hours = isset($day_bucket['hours']) && is_array($day_bucket['hours']) ? $day_bucket['hours'] : [];

            foreach ($hours as $hour_row) {
                $hour_label = isset($hour_row['hour']) ? sprintf('%02d:00', (int) $hour_row['hour']) : '';
                $load_value = isset($hour_row['load_1']) && is_numeric($hour_row['load_1']) ? number_format_i18n((float) $hour_row['load_1'], 2) : '';
                $memory_value = isset($hour_row['memory_percent']) && is_numeric($hour_row['memory_percent']) ? number_format_i18n((float) $hour_row['memory_percent'], 1) : '';
                $disk_value = isset($hour_row['disk_used_percent']) && is_numeric($hour_row['disk_used_percent']) ? number_format_i18n((float) $hour_row['disk_used_percent'], 1) : '';
                $samples_value = isset($hour_row['samples']) ? (int) $hour_row['samples'] : 0;

                fputcsv($csv_stream, [$date, $hour_label, $load_value, $memory_value, $disk_value, $samples_value], ';');
            }
        }
    }

    $csv = '';

    if (is_resource($csv_stream)) {
        rewind($csv_stream);
        $csv_contents = stream_get_contents($csv_stream);
        if (is_string($csv_contents)) {
            $csv = $csv_contents;
        }
        fclose($csv_stream);
    }

    $json_payload = [
        'generated_at' => $generated_at,
        'metrics'      => $metrics,
        'heatmap'      => $heatmap,
        'drift'        => $drift,
        'summary'      => $summary,
    ];

    $json = function_exists('wp_json_encode')
        ? wp_json_encode($json_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : json_encode($json_payload, JSON_PRETTY_PRINT);

    if (!is_string($json)) {
        $json = '{}';
    }

    return [
        'csv'  => $csv,
        'json' => $json,
    ];
}

/**
 * Generates a comprehensive report payload from history entries.
 *
 * @param array<int, array> $entries History entries.
 * @return array<string, mixed>
 */
function sitepulse_resource_monitor_generate_report_payload(array $entries) {
    $generated_at = function_exists('current_time')
        ? (int) current_time('timestamp', true)
        : time();

    $metrics = sitepulse_resource_monitor_calculate_aggregate_metrics($entries);
    $heatmap = sitepulse_resource_monitor_build_heatmap_data($entries);
    $drift = sitepulse_resource_monitor_calculate_drift_summary($metrics);
    $summary = sitepulse_resource_monitor_calculate_history_summary($entries);
    $summary_text = sitepulse_resource_monitor_format_history_summary($summary);

    $samples_count = count($entries);
    $first_timestamp = $samples_count > 0 ? (int) $entries[0]['timestamp'] : null;
    $last_timestamp = $samples_count > 0 ? (int) $entries[$samples_count - 1]['timestamp'] : null;

    $report = [
        'generated_at' => $generated_at,
        'metrics'      => $metrics,
        'heatmap'      => $heatmap,
        'drift'        => $drift,
        'summary'      => $summary,
        'summary_text' => $summary_text,
        'samples'      => [
            'count'           => $samples_count,
            'first_timestamp' => $first_timestamp,
            'last_timestamp'  => $last_timestamp,
        ],
    ];

    $report['exports'] = sitepulse_resource_monitor_prepare_report_exports($report);

    return $report;
}

/**
 * Ensures a recurring Action Scheduler job generates resource reports.
 *
 * @return void
 */
function sitepulse_resource_monitor_schedule_report_generation() {
    if (!function_exists('as_schedule_recurring_action') || !function_exists('as_has_scheduled_action')) {
        return;
    }

    if (function_exists('sitepulse_is_module_active') && !sitepulse_is_module_active('resource_monitor')) {
        return;
    }

    $hook = SITEPULSE_ACTION_RESOURCE_MONITOR_REPORTS;
    $group = SITEPULSE_AS_GROUP_RESOURCE_MONITOR;
    $default_interval = DAY_IN_SECONDS;

    if (function_exists('apply_filters')) {
        $default_interval = (int) apply_filters('sitepulse_resource_monitor_report_interval', $default_interval);
    }

    $interval = $default_interval > 0 ? $default_interval : DAY_IN_SECONDS;
    $start_delay = function_exists('apply_filters')
        ? (int) apply_filters('sitepulse_resource_monitor_report_start_delay', 10 * MINUTE_IN_SECONDS)
        : 10 * MINUTE_IN_SECONDS;
    $start_timestamp = time() + max(5, $start_delay);

    try {
        if (!as_has_scheduled_action($hook, [], $group)) {
            as_schedule_recurring_action($start_timestamp, $interval, $hook, [], $group);
        }
    } catch (Throwable $throwable) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('Resource monitor report scheduling failed: ' . $throwable->getMessage(), 'WARNING');
        }
    }
}

/**
 * Queues a one-off report generation via Action Scheduler or runs it immediately.
 *
 * @param int $delay_seconds Delay before the action runs.
 * @return bool True if queued, false when executed synchronously.
 */
function sitepulse_resource_monitor_queue_report_generation($delay_seconds = 5) {
    $hook = SITEPULSE_ACTION_RESOURCE_MONITOR_REPORTS;
    $group = SITEPULSE_AS_GROUP_RESOURCE_MONITOR;

    if (!function_exists('as_schedule_single_action') || !function_exists('as_next_scheduled_action')) {
        sitepulse_resource_monitor_run_scheduled_reports();

        return false;
    }

    try {
        $next = as_next_scheduled_action($hook, [], $group);

        if ($next && $next <= (time() + 300)) {
            return true;
        }

        as_schedule_single_action(time() + max(1, (int) $delay_seconds), $hook, [], $group);

        return true;
    } catch (Throwable $throwable) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('Resource monitor report queueing failed: ' . $throwable->getMessage(), 'WARNING');
        }

        sitepulse_resource_monitor_run_scheduled_reports();

        return false;
    }
}

/**
 * Generates and dispatches scheduled resource monitor reports.
 *
 * @return void
 */
function sitepulse_resource_monitor_run_scheduled_reports() {
    if (function_exists('sitepulse_is_module_active') && !sitepulse_is_module_active('resource_monitor')) {
        return;
    }

    $history_query = sitepulse_resource_monitor_get_history([
        'per_page' => 0,
        'order'    => 'ASC',
    ]);

    $entries = isset($history_query['entries']) && is_array($history_query['entries'])
        ? $history_query['entries']
        : [];

    $report = sitepulse_resource_monitor_generate_report_payload($entries);

    $last_report_ttl = function_exists('apply_filters')
        ? (int) apply_filters('sitepulse_resource_monitor_last_report_ttl', DAY_IN_SECONDS)
        : DAY_IN_SECONDS;

    if (function_exists('set_transient')) {
        set_transient(
            SITEPULSE_TRANSIENT_RESOURCE_MONITOR_LAST_REPORT,
            $report,
            $last_report_ttl > 0 ? $last_report_ttl : DAY_IN_SECONDS
        );
    }

    sitepulse_resource_monitor_deliver_report($report);

    if (function_exists('do_action')) {
        do_action('sitepulse_resource_monitor_report_ready', $report);
    }
}

/**
 * Creates a temporary file for email attachments.
 *
 * @param string $contents File contents.
 * @param string $filename Desired filename.
 * @return string|null Path to the temporary file.
 */
function sitepulse_resource_monitor_create_temporary_export($contents, $filename) {
    if (!is_string($contents) || $contents === '') {
        return null;
    }

    if (function_exists('wp_tempnam')) {
        $path = wp_tempnam($filename);
    } else {
        $path = tempnam(sys_get_temp_dir(), 'sitepulse');
    }

    if (!is_string($path) || $path === '') {
        return null;
    }

    $written = file_put_contents($path, $contents);

    if ($written === false) {
        return null;
    }

    return $path;
}

/**
 * Sends the report via email and optional webhooks.
 *
 * @param array<string, mixed> $report Report payload.
 * @return void
 */
function sitepulse_resource_monitor_deliver_report(array $report) {
    $exports = isset($report['exports']) && is_array($report['exports']) ? $report['exports'] : [];
    $csv_export = isset($exports['csv']) ? $exports['csv'] : '';
    $json_export = isset($exports['json']) ? $exports['json'] : '';

    $recipients = [get_option('admin_email')];

    if (function_exists('apply_filters')) {
        $recipients = apply_filters('sitepulse_resource_monitor_report_recipients', $recipients, $report);
    }

    $recipients = array_filter(array_map('sanitize_email', is_array($recipients) ? $recipients : []));

    $attachments = [];

    if (!empty($csv_export)) {
        $csv_path = sitepulse_resource_monitor_create_temporary_export($csv_export, 'sitepulse-resource-report.csv');
        if ($csv_path) {
            $attachments[] = $csv_path;
        }
    }

    if (!empty($json_export)) {
        $json_path = sitepulse_resource_monitor_create_temporary_export($json_export, 'sitepulse-resource-report.json');
        if ($json_path) {
            $attachments[] = $json_path;
        }
    }

    if (!empty($recipients) && function_exists('wp_mail')) {
        $site_name = function_exists('get_bloginfo') ? get_bloginfo('name', 'display') : 'WordPress';
        $subject = sprintf(__('Rapport ressources SitePulse – %s', 'sitepulse'), $site_name);

        $summary_text = isset($report['summary_text']) ? (string) $report['summary_text'] : '';
        $generated_at = isset($report['generated_at']) ? (int) $report['generated_at'] : time();
        $generated_label = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $generated_at);

        $lines = [
            sprintf(__('Rapport généré le %s.', 'sitepulse'), $generated_label),
        ];

        if ($summary_text !== '') {
            $lines[] = $summary_text;
        }

        if (isset($report['metrics']['load_1']['average'])) {
            $lines[] = sprintf(
                __('Charge CPU moyenne (1 min) : %s', 'sitepulse'),
                number_format_i18n((float) $report['metrics']['load_1']['average'], 2)
            );
        }

        if (isset($report['metrics']['memory_percent']['average'])) {
            $lines[] = sprintf(
                __('Mémoire utilisée moyenne : %s %%', 'sitepulse'),
                number_format_i18n((float) $report['metrics']['memory_percent']['average'], 1)
            );
        }

        if (isset($report['metrics']['disk_used']['average'])) {
            $lines[] = sprintf(
                __('Stockage utilisé moyen : %s %%', 'sitepulse'),
                number_format_i18n((float) $report['metrics']['disk_used']['average'], 1)
            );
        }

        $message = implode("\n", $lines);

        wp_mail($recipients, $subject, $message, '', $attachments);
    }

    $webhooks = [];

    if (function_exists('apply_filters')) {
        $webhooks = apply_filters('sitepulse_resource_monitor_report_webhooks', $webhooks, $report);
    }

    if (!empty($webhooks) && function_exists('wp_remote_post') && !empty($json_export)) {
        foreach ((array) $webhooks as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }

            try {
                wp_remote_post($url, [
                    'timeout' => 10,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => $json_export,
                ]);
            } catch (Throwable $throwable) {
                if (function_exists('sitepulse_log')) {
                    sitepulse_log('Resource monitor webhook failed: ' . $throwable->getMessage(), 'WARNING');
                }
            }
        }
    }

    foreach ($attachments as $attachment) {
        if (is_string($attachment) && $attachment !== '' && file_exists($attachment)) {
            @unlink($attachment);
        }
    }
}

/**
 * Handles manual report triggers from the admin UI.
 *
 * @return void
 */
function sitepulse_resource_monitor_handle_report_trigger() {
    if (!function_exists('sitepulse_get_capability') || !current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    check_admin_referer('sitepulse_resource_monitor_trigger_report');

    $queued = sitepulse_resource_monitor_queue_report_generation();

    $status = $queued ? 'queued' : 'executed';

    $redirect = wp_get_referer();
    if (!$redirect) {
        $redirect = admin_url('admin.php?page=sitepulse-resources');
    }

    wp_safe_redirect(add_query_arg('sitepulse_report', $status, $redirect));
    exit;
}

/**
 * Prepares normalized history entries for REST responses.
 *
 * @param array<int, array> $history_entries Normalized history entries.
 * @return array<int, array<string,mixed>>
 */
function sitepulse_resource_monitor_prepare_history_for_rest(array $history_entries) {
    $prepared = [];

    foreach ($history_entries as $entry) {
        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : null;
        $source = isset($entry['source']) ? (string) $entry['source'] : 'manual';

        if (function_exists('sanitize_key')) {
            $sanitized_source = sanitize_key($source);

            if ($sanitized_source !== '') {
                $source = $sanitized_source;
            }
        } else {
            $source = strtolower(preg_replace('/[^a-z0-9_\-]/', '', $source));
        }

        if ($source === '') {
            $source = 'manual';
        }

        $load_values = isset($entry['load']) && is_array($entry['load'])
            ? array_values($entry['load'])
            : [null, null, null];

        $load_values = array_map(
            static function ($value) {
                return is_numeric($value) ? (float) $value : null;
            },
            array_pad($load_values, 3, null)
        );

        $load_display = sitepulse_resource_monitor_format_load_display($load_values);
        $cpu_percent = sitepulse_resource_monitor_calculate_cpu_usage_percent($entry);

        $memory_usage_bytes = isset($entry['memory']['usage']) && is_numeric($entry['memory']['usage'])
            ? (int) $entry['memory']['usage']
            : null;
        $memory_limit_bytes = isset($entry['memory']['limit']) && is_numeric($entry['memory']['limit'])
            ? (int) $entry['memory']['limit']
            : null;
        $memory_percent = sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null);

        $disk_free_bytes = isset($entry['disk']['free']) && is_numeric($entry['disk']['free'])
            ? (int) $entry['disk']['free']
            : null;
        $disk_total_bytes = isset($entry['disk']['total']) && is_numeric($entry['disk']['total'])
            ? (int) $entry['disk']['total']
            : null;

        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage($disk_free_bytes, $disk_total_bytes);
        $disk_percent_used = $disk_percent_free !== null ? max(0.0, min(100.0, 100.0 - $disk_percent_free)) : null;

        $disk_used_bytes = null;
        if ($disk_total_bytes !== null && $disk_free_bytes !== null) {
            $disk_used_bytes = max(0, $disk_total_bytes - $disk_free_bytes);
        }

        $prepared[] = [
            'timestamp'     => $timestamp,
            'source'        => $source,
            'load_averages' => $load_values,
            'load_display'  => $load_display,
            'cpu_percent'   => $cpu_percent,
            'memory'        => [
                'usage_bytes'      => $memory_usage_bytes,
                'usage_formatted'  => ($memory_usage_bytes !== null && function_exists('size_format')) ? size_format($memory_usage_bytes) : null,
                'limit_bytes'      => $memory_limit_bytes,
                'limit_formatted'  => ($memory_limit_bytes !== null && function_exists('size_format')) ? size_format($memory_limit_bytes) : null,
                'percent'          => $memory_percent,
            ],
            'disk'          => [
                'free_bytes'       => $disk_free_bytes,
                'free_formatted'   => ($disk_free_bytes !== null && function_exists('size_format')) ? size_format($disk_free_bytes) : null,
                'total_bytes'      => $disk_total_bytes,
                'total_formatted'  => ($disk_total_bytes !== null && function_exists('size_format')) ? size_format($disk_total_bytes) : null,
                'used_bytes'       => $disk_used_bytes,
                'used_formatted'   => ($disk_used_bytes !== null && function_exists('size_format')) ? size_format($disk_used_bytes) : null,
                'percent_free'     => $disk_percent_free,
                'percent_used'     => $disk_percent_used,
            ],
        ];
    }

    return $prepared;
}

/**
 * Normalizes a snapshot array for REST responses.
 *
 * @param array $snapshot Snapshot generated by sitepulse_resource_monitor_get_snapshot().
 * @return array<string,mixed>
 */
function sitepulse_resource_monitor_rest_prepare_snapshot(array $snapshot) {
    $load = isset($snapshot['load']) && is_array($snapshot['load']) ? $snapshot['load'] : [];
    $load = array_map(
        static function ($value) {
            return is_numeric($value) ? (float) $value : null;
        },
        array_pad(array_values($load), 3, null)
    );

    $memory_percent = isset($snapshot['memory_usage_percent']) && is_numeric($snapshot['memory_usage_percent'])
        ? (float) $snapshot['memory_usage_percent']
        : null;

    $disk_free_percent = isset($snapshot['disk_free_percent']) && is_numeric($snapshot['disk_free_percent'])
        ? (float) $snapshot['disk_free_percent']
        : null;
    $disk_used_percent = isset($snapshot['disk_used_percent']) && is_numeric($snapshot['disk_used_percent'])
        ? (float) $snapshot['disk_used_percent']
        : null;

    $notices = [];
    if (!empty($snapshot['notices']) && is_array($snapshot['notices'])) {
        foreach ($snapshot['notices'] as $notice) {
            $type = isset($notice['type']) ? (string) $notice['type'] : 'info';
            if (function_exists('sanitize_key')) {
                $type = sanitize_key($type);
            }

            $message = isset($notice['message']) ? (string) $notice['message'] : '';
            if (function_exists('wp_strip_all_tags')) {
                $message = wp_strip_all_tags($message);
            }

            $notices[] = [
                'type'    => $type,
                'message' => $message,
            ];
        }
    }

    return [
        'generated_at'       => isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : null,
        'source'             => isset($snapshot['source']) ? (string) $snapshot['source'] : 'manual',
        'load_averages'      => $load,
        'load_display'       => isset($snapshot['load_display']) ? (string) $snapshot['load_display'] : null,
        'memory_usage_bytes' => isset($snapshot['memory_usage_bytes']) ? (int) $snapshot['memory_usage_bytes'] : null,
        'memory_usage'       => isset($snapshot['memory_usage']) ? (string) $snapshot['memory_usage'] : null,
        'memory_limit_bytes' => isset($snapshot['memory_limit_bytes']) ? (int) $snapshot['memory_limit_bytes'] : null,
        'memory_limit'       => isset($snapshot['memory_limit']) ? (string) $snapshot['memory_limit'] : null,
        'memory_percent'     => $memory_percent,
        'disk_free_bytes'    => isset($snapshot['disk_free_bytes']) ? (int) $snapshot['disk_free_bytes'] : null,
        'disk_free'          => isset($snapshot['disk_free']) ? (string) $snapshot['disk_free'] : null,
        'disk_total_bytes'   => isset($snapshot['disk_total_bytes']) ? (int) $snapshot['disk_total_bytes'] : null,
        'disk_total'         => isset($snapshot['disk_total']) ? (string) $snapshot['disk_total'] : null,
        'disk_used_bytes'    => isset($snapshot['disk_used_bytes']) ? (int) $snapshot['disk_used_bytes'] : null,
        'disk_used'          => isset($snapshot['disk_used']) ? (string) $snapshot['disk_used'] : null,
        'disk_free_percent'  => $disk_free_percent,
        'disk_used_percent'  => $disk_used_percent,
        'notices'            => $notices,
    ];
}

/**
 * Formats CPU load values for display.
 *
 * @param mixed $load_values Raw load average values.
 * @return string
 */
function sitepulse_resource_monitor_format_load_display($load_values) {
    $not_available_label = esc_html__('N/A', 'sitepulse');

    if (!is_array($load_values) || empty($load_values)) {
        $load_values = [$not_available_label, $not_available_label, $not_available_label];
    }

    $normalized_values = array_map(
        static function ($value) use ($not_available_label) {
            if (is_numeric($value)) {
                return number_format_i18n((float) $value, 2);
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_null($value)) {
                return $not_available_label;
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return $not_available_label;
        },
        array_slice(array_values((array) $load_values), 0, 3)
    );

    $normalized_values = array_pad($normalized_values, 3, $not_available_label);

    return implode(' / ', $normalized_values);
}

/**
 * Resolves CPU load average values using multiple strategies.
 *
 * @param string $not_available_label Translated fallback label.
 * @return array{
 *     display: array<int, mixed>,
 *     raw: array<int, float|null>,
 *     notices: array<int, array{type:string,message:string}>
 * }
 */
function sitepulse_resource_monitor_resolve_load_average($not_available_label) {
    $display = [$not_available_label, $not_available_label, $not_available_label];
    $raw = [null, null, null];
    $notices = [];
    $source = null;

    $load_values = null;

    if (function_exists('sys_getloadavg')) {
        $load_values = sitepulse_resource_monitor_sanitize_load_values(sys_getloadavg());

        if ($load_values !== null) {
            $source = 'sys_getloadavg';
        } else {
            $message = esc_html__('Indisponible – sys_getloadavg() désactivée par votre hébergeur', 'sitepulse');
            $notices[] = [
                'type'    => 'warning',
                'message' => $message,
            ];

            if (function_exists('sitepulse_log')) {
                sitepulse_log(__('Resource Monitor: CPU load average unavailable because sys_getloadavg() is disabled by the hosting provider.', 'sitepulse'), 'WARNING');
            }
        }
    } else {
        $message = esc_html__('Indisponible – sys_getloadavg() désactivée par votre hébergeur', 'sitepulse');
        $notices[] = [
            'type'    => 'warning',
            'message' => $message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log(__('Resource Monitor: sys_getloadavg() is not available on this server.', 'sitepulse'), 'WARNING');
        }
    }

    if ($load_values === null) {
        $proc_values = sitepulse_resource_monitor_read_proc_loadavg();

        if ($proc_values !== null) {
            $load_values = $proc_values;
            $source = 'proc_loadavg';

            if (function_exists('sitepulse_log')) {
                sitepulse_log(__('Resource Monitor: CPU load average resolved from /proc/loadavg fallback.', 'sitepulse'), 'INFO');
            }
        } elseif (!function_exists('sys_getloadavg')) {
            $message = esc_html__('CPU load average is unavailable because /proc/loadavg could not be read.', 'sitepulse');
            $notices[] = [
                'type'    => 'warning',
                'message' => $message,
            ];

            if (function_exists('sitepulse_log')) {
                sitepulse_log(__('Resource Monitor: /proc/loadavg could not be read to determine CPU load average.', 'sitepulse'), 'WARNING');
            }
        }
    }

    $filter_context = [
        'source'            => $source,
        'fallback_attempted'=> $source !== null && $source !== 'sys_getloadavg',
    ];

    /**
     * Filters the raw load averages before they are formatted for display.
     *
     * @param array<int, float>|null $load_values Raw load averages.
     * @param array{source:?string,fallback_attempted:bool} $filter_context Contextual metadata.
     */
    $filtered_values = apply_filters('sitepulse_resource_monitor_load_average', $load_values, $filter_context);

    if ($filtered_values !== $load_values) {
        $sanitized = sitepulse_resource_monitor_sanitize_load_values($filtered_values);

        if ($sanitized !== null) {
            $load_values = $sanitized;
            $source = 'filter';
        }
    }

    if ($load_values !== null) {
        $display = $load_values;
        $raw = array_map(static function($value) {
            return is_numeric($value) ? (float) $value : null;
        }, array_pad(array_values($load_values), 3, null));
    }

    return [
        'display' => array_pad(array_values((array) $display), 3, $not_available_label),
        'raw'     => array_pad($raw, 3, null),
        'notices' => $notices,
    ];
}

/**
 * Validates and sanitizes load average values.
 *
 * @param mixed $values Raw values.
 * @return array<int, float>|null
 */
function sitepulse_resource_monitor_sanitize_load_values($values) {
    if (!is_array($values) || empty($values)) {
        return null;
    }

    $values = array_slice(array_values($values), 0, 3);

    if (empty($values)) {
        return null;
    }

    foreach ($values as $value) {
        if (!is_numeric($value)) {
            return null;
        }
    }

    return array_map(static function($value) {
        return (float) $value;
    }, $values);
}

/**
 * Attempts to read load averages from /proc/loadavg.
 *
 * @return array<int, float>|null
 */
function sitepulse_resource_monitor_read_proc_loadavg() {
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $path = '/proc/loadavg';

    if (!@is_readable($path)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $cached = null;

        return null;
    }

    $error_message = null;

    set_error_handler(static function($errno, $errstr) use (&$error_message) {
        $error_message = $errstr;

        return true;
    });

    try {
        $contents = file_get_contents($path);
    } catch (\Throwable $exception) {
        $error_message = $exception->getMessage();
        $contents = false;
    } finally {
        restore_error_handler();
    }

    if ($contents === false || $contents === '') {
        $cached = null;

        return null;
    }

    $parts = preg_split('/\s+/', trim((string) $contents));

    if (!is_array($parts) || empty($parts)) {
        $cached = null;

        return null;
    }

    $values = array_slice($parts, 0, 3);

    $sanitized = sitepulse_resource_monitor_sanitize_load_values($values);

    if ($sanitized === null) {
        $cached = null;

        return null;
    }

    $cached = $sanitized;

    return $cached;
}

/**
 * Retrieves disk usage metrics with shared caching and robust error handling.
 *
 * @param string $type Either "free" or "total".
 * @param string $path Filesystem path to evaluate.
 * @return array{display:string,bytes:int|null,notices:array<int,array{type:string,message:string}>}
 */
function sitepulse_resource_monitor_measure_disk_space($type, $path) {
    $not_available_label = esc_html__('N/A', 'sitepulse');
    $result = [
        'display' => $not_available_label,
        'bytes'   => null,
        'notices' => [],
    ];

    $type = $type === 'total' ? 'total' : 'free';

    static $cache = [];
    $cache_key = $type . '|' . $path;

    /**
     * Filters whether the disk usage measurement should be cached during the current request.
     *
     * @param bool   $enabled Default caching behaviour.
     * @param string $type    Requested metric type (free|total).
     * @param string $path    Filesystem path being inspected.
     */
    $enable_cache = (bool) apply_filters('sitepulse_resource_monitor_enable_disk_cache', true, $type, $path);

    if ($enable_cache && isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $function = $type === 'total' ? 'disk_total_space' : 'disk_free_space';

    $failure_message = $type === 'total'
        ? esc_html__('Unable to determine the total disk space for the WordPress root directory.', 'sitepulse')
        : esc_html__('Unable to determine the available disk space for the WordPress root directory.', 'sitepulse');

    $missing_function_message = $type === 'total'
        ? esc_html__('The disk_total_space() function is not available on this server.', 'sitepulse')
        : esc_html__('The disk_free_space() function is not available on this server.', 'sitepulse');

    if (!function_exists($function)) {
        $result['notices'][] = [
            'type'    => 'warning',
            'message' => $missing_function_message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log(
                sprintf(
                    /* translators: %s: original message. */
                    __('Resource Monitor: %s', 'sitepulse'),
                    $missing_function_message
                ),
                'WARNING'
            );
        }

        if ($enable_cache) {
            $cache[$cache_key] = $result;
        }

        return $result;
    }

    $error_message = null;
    set_error_handler(static function($errno, $errstr) use (&$error_message) {
        $error_message = $errstr;

        return true;
    });

    try {
        $value = $function($path);
    } catch (\Throwable $exception) {
        $error_message = $exception->getMessage();
        $value = false;
    } finally {
        restore_error_handler();
    }

    if ($value !== false) {
        if (is_numeric($value)) {
            $bytes = (int) $value;
            $result['bytes'] = $bytes;
            $result['display'] = size_format($bytes);
        }

        if ($enable_cache) {
            $cache[$cache_key] = $result;
        }

        return $result;
    }

    $result['notices'][] = [
        'type'    => 'warning',
        'message' => $failure_message,
    ];

    if (function_exists('sitepulse_log')) {
        $log_message = sprintf(
            /* translators: %s: original message. */
            __('Resource Monitor: %s', 'sitepulse'),
            $failure_message
        );

        if (is_string($error_message) && $error_message !== '') {
            $log_message .= ' ' . sprintf(
                /* translators: %s: error message. */
                __('Error: %s', 'sitepulse'),
                $error_message
            );
        }

        sitepulse_log($log_message, 'ERROR');
    }

    if ($enable_cache) {
        $cache[$cache_key] = $result;
    }

    return $result;
}

/**
 * Returns cached resource metrics or computes a fresh snapshot.
 *
 * @return array{
 *     load: array<int, mixed>,
 *     load_display: string,
 *     memory_usage: string,
 *     memory_limit: string|false,
 *     disk_free: string,
 *     disk_total: string,
 *     notices: array<int, array{type:string,message:string}>,
 *     generated_at: int
 * }
 */
function sitepulse_resource_monitor_get_snapshot($context = 'manual') {
    $context = is_string($context) ? sanitize_key($context) : 'manual';

    if ($context === '') {
        $context = 'manual';
    }

    $bypass_cache = ($context === 'cron');
    $cached = $bypass_cache ? null : get_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);

    if (is_array($cached) && isset($cached['generated_at'])) {
        if (!isset($cached['source'])) {
            $cached['source'] = 'manual';
        }

        return $cached;
    }

    $notices = [];
    $not_available_label = esc_html__('N/A', 'sitepulse');
    $load_result = sitepulse_resource_monitor_resolve_load_average($not_available_label);
    $load = $load_result['display'];
    $load_display = sitepulse_resource_monitor_format_load_display($load);
    $load_raw = $load_result['raw'];
    if (!empty($load_result['notices'])) {
        $notices = array_merge($notices, $load_result['notices']);
    }

    $memory_usage_bytes = (int) memory_get_usage();
    $memory_usage = size_format($memory_usage_bytes);
    $memory_limit_ini = ini_get('memory_limit');
    $memory_limit = $memory_limit_ini;
    $memory_limit_bytes = sitepulse_resource_monitor_normalize_memory_limit_bytes($memory_limit_ini);
    $memory_usage_percent = sitepulse_resource_monitor_calculate_percentage($memory_usage_bytes, $memory_limit_bytes);

    if ($memory_limit_ini !== false) {
        $memory_limit_value = trim((string) $memory_limit_ini);
        $memory_limit = $memory_limit_value;

        if ($memory_limit_value !== '') {
            $memory_limit_lower = strtolower($memory_limit_value);

            if (
                $memory_limit_lower === '-1'
                || $memory_limit_lower === 'unlimited'
                || (float) $memory_limit_value === -1.0
            ) {
                $memory_limit = __('Illimitée', 'sitepulse');
            }
        }
    }

    $disk_free = $not_available_label;
    $disk_free_bytes = null;

    $disk_free_result = sitepulse_resource_monitor_measure_disk_space('free', ABSPATH);
    $disk_free = $disk_free_result['display'];
    $disk_free_bytes = $disk_free_result['bytes'];
    if (!empty($disk_free_result['notices'])) {
        $notices = array_merge($notices, $disk_free_result['notices']);
    }

    $disk_total = $not_available_label;
    $disk_total_bytes = null;

    $disk_total_result = sitepulse_resource_monitor_measure_disk_space('total', ABSPATH);
    $disk_total = $disk_total_result['display'];
    $disk_total_bytes = $disk_total_result['bytes'];
    if (!empty($disk_total_result['notices'])) {
        $notices = array_merge($notices, $disk_total_result['notices']);
    }

    $disk_free_percent = sitepulse_resource_monitor_calculate_percentage($disk_free_bytes, $disk_total_bytes);
    $disk_used_bytes = null;
    $disk_used = $not_available_label;
    $disk_used_percent = null;

    if ($disk_total_bytes !== null && $disk_free_bytes !== null && $disk_total_bytes > 0) {
        $disk_used_bytes = max(0, $disk_total_bytes - $disk_free_bytes);
        $disk_used = size_format($disk_used_bytes);
    }

    if ($disk_free_percent !== null) {
        $disk_used_percent = max(0.0, min(100.0, 100.0 - $disk_free_percent));
    }

    $snapshot = [
        'load'         => $load,
        'load_display' => $load_display,
        'memory_usage' => $memory_usage,
        'memory_usage_bytes' => $memory_usage_bytes,
        'memory_limit' => $memory_limit,
        'memory_limit_bytes' => $memory_limit_bytes,
        'memory_usage_percent' => $memory_usage_percent,
        'disk_free'    => $disk_free,
        'disk_free_bytes' => $disk_free_bytes,
        'disk_free_percent' => $disk_free_percent,
        'disk_used'    => $disk_used,
        'disk_used_bytes' => $disk_used_bytes,
        'disk_used_percent' => $disk_used_percent,
        'disk_total'   => $disk_total,
        'disk_total_bytes' => $disk_total_bytes,
        'notices'      => $notices,
        'generated_at' => (int) current_time('timestamp', true),
        'source'       => $context,
    ];

    $history_snapshot = $snapshot;
    $history_snapshot['load_raw'] = $load_raw;
    sitepulse_resource_monitor_append_history($history_snapshot);

    $cache_ttl = (int) apply_filters('sitepulse_resource_monitor_cache_ttl', 5 * MINUTE_IN_SECONDS, $snapshot);

    if ($cache_ttl > 0) {
        set_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT, $snapshot, $cache_ttl);
    }

    return $snapshot;
}

function sitepulse_resource_monitor_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $resource_monitor_notices = [];
    $refresh_feedback = '';

    if (isset($_POST['sitepulse_resource_monitor_refresh'])) {
        check_admin_referer('sitepulse_refresh_resource_snapshot');
        delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        sitepulse_resource_monitor_clear_history();

        $resource_monitor_notices[] = [
            'type'    => 'success',
            'message' => esc_html__('Les mesures et l’historique ont été actualisés.', 'sitepulse'),
        ];

        $refresh_feedback = esc_html__('Les mesures et l’historique ont été actualisés.', 'sitepulse');
    }

    if (isset($_GET['sitepulse_report'])) {
        $report_status = sanitize_key((string) $_GET['sitepulse_report']);

        if ($report_status === 'queued') {
            $resource_monitor_notices[] = [
                'type'    => 'success',
                'message' => esc_html__('Le rapport a été planifié et sera envoyé sous peu.', 'sitepulse'),
            ];
        } elseif ($report_status === 'executed') {
            $resource_monitor_notices[] = [
                'type'    => 'success',
                'message' => esc_html__('Le rapport a été généré avec succès.', 'sitepulse'),
            ];
        } elseif ($report_status === 'failed') {
            $resource_monitor_notices[] = [
                'type'    => 'error',
                'message' => esc_html__('Le rapport n’a pas pu être généré. Consultez les journaux pour plus de détails.', 'sitepulse'),
            ];
        }
    }

    if (isset($_GET['sitepulse_http_monitor'])) {
        $http_status = sanitize_key((string) $_GET['sitepulse_http_monitor']);

        if ($http_status === 'updated') {
            $resource_monitor_notices[] = [
                'type'    => 'success',
                'message' => esc_html__('Les seuils du moniteur HTTP ont été enregistrés.', 'sitepulse'),
            ];
        }
    }

    $snapshot = sitepulse_resource_monitor_get_snapshot();

    $history_result = sitepulse_resource_monitor_get_history([
        'per_page' => 288,
        'page'     => 1,
        'order'    => 'DESC',
    ]);

    $history_entries = isset($history_result['entries']) && is_array($history_result['entries'])
        ? array_reverse($history_result['entries'])
        : [];

    $history_summary = sitepulse_resource_monitor_calculate_history_summary($history_entries);
    $history_summary_text = sitepulse_resource_monitor_format_history_summary($history_summary);
    $history_for_js = sitepulse_resource_monitor_prepare_history_for_js($history_entries);
    $last_cron_timestamp = sitepulse_resource_monitor_get_last_cron_timestamp($history_entries);

    $aggregated_metrics = sitepulse_resource_monitor_calculate_aggregate_metrics($history_entries);

    $granularity_choices = [
        ['value' => 'raw', 'label' => __('Données brutes (5 min)', 'sitepulse')],
        ['value' => '15m', 'label' => __('Moyenne 15 minutes', 'sitepulse')],
        ['value' => '1h', 'label' => __('Moyenne horaire', 'sitepulse')],
        ['value' => '1d', 'label' => __('Moyenne quotidienne', 'sitepulse')],
    ];

    $history_initial = [
        'entries' => $history_for_js,
        'summary' => [
            'count'                 => (int) $history_summary['count'],
            'span'                  => (int) $history_summary['span'],
            'firstTimestamp'        => $history_summary['first_timestamp'],
            'lastTimestamp'         => $history_summary['last_timestamp'],
            'averageLoad'           => $history_summary['average_load'],
            'latestLoad'            => $history_summary['latest_load'],
            'averageMemoryPercent'  => $history_summary['average_memory_percent'],
            'latestMemoryPercent'   => $history_summary['latest_memory_percent'],
            'averageDiskUsedPercent'=> $history_summary['average_disk_used_percent'],
            'latestDiskUsedPercent' => $history_summary['latest_disk_used_percent'],
        ],
        'summaryText' => $history_summary_text,
        'granularity' => 'raw',
    ];

    $snapshot_meta = [
        'generatedAt' => isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : null,
        'source'      => isset($snapshot['source']) ? (string) $snapshot['source'] : 'manual',
    ];

    $rest_history_endpoint = function_exists('rest_url') ? rest_url('sitepulse/v1/resources/history') : '';
    $rest_aggregates_endpoint = function_exists('rest_url') ? rest_url('sitepulse/v1/resources/aggregates') : '';
    $rest_http_endpoint = function_exists('rest_url') ? rest_url('sitepulse/v1/resources/http') : '';
    $rest_nonce = wp_create_nonce('wp_rest');

    $http_stats = function_exists('sitepulse_http_monitor_get_stats')
        ? sitepulse_http_monitor_get_stats([
            'since' => (int) current_time('timestamp', true) - DAY_IN_SECONDS,
            'limit' => 25,
        ])
        : [
            'summary'    => [],
            'services'   => [],
            'samples'    => [],
            'thresholds' => [],
        ];

    $http_thresholds = function_exists('sitepulse_http_monitor_get_threshold_configuration')
        ? sitepulse_http_monitor_get_threshold_configuration()
        : ['latency' => 0, 'errorRate' => 0];

    $http_retention_days = (int) get_option(
        SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS,
        defined('SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS') ? (int) SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS : 14
    );

    if ($http_retention_days < 1) {
        $http_retention_days = defined('SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS')
            ? (int) SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS
            : 14;
    }

    $http_latency_value = isset($http_thresholds['latency']) ? (int) $http_thresholds['latency'] : 0;
    $http_error_value = isset($http_thresholds['errorRate']) ? (int) $http_thresholds['errorRate'] : 0;
    $http_settings_nonce = defined('SITEPULSE_NONCE_ACTION_HTTP_MONITOR_SETTINGS')
        ? SITEPULSE_NONCE_ACTION_HTTP_MONITOR_SETTINGS
        : 'sitepulse_http_monitor_settings';

    $last_report_raw = get_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_LAST_REPORT);
    $last_report_for_js = null;
    $last_report_display = null;

    if (is_array($last_report_raw)) {
        $last_generated_at = isset($last_report_raw['generated_at']) ? (int) $last_report_raw['generated_at'] : 0;
        $last_summary_text = isset($last_report_raw['summary_text']) ? (string) $last_report_raw['summary_text'] : '';
        $last_samples = isset($last_report_raw['samples']) && is_array($last_report_raw['samples']) ? $last_report_raw['samples'] : [];

        $last_report_for_js = [
            'generated_at' => $last_generated_at,
            'summary_text' => $last_summary_text,
            'samples'      => $last_samples,
        ];

        if (isset($last_report_raw['metrics']) && is_array($last_report_raw['metrics'])) {
            $last_report_for_js['metrics'] = $last_report_raw['metrics'];
        }

        $last_label = $last_generated_at > 0
            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_generated_at)
            : '';

        $last_report_display = [
            'label'   => $last_label,
            'summary' => $last_summary_text,
        ];
    }

    $sitepulse_localized = [
        'initialHistory' => $history_initial,
        'snapshot'       => $snapshot_meta,
        'lastAutomaticTimestamp' => $last_cron_timestamp,
        'locale'         => get_user_locale(),
        'dateFormat'     => get_option('date_format'),
        'timeFormat'     => get_option('time_format'),
        'rest'           => [
            'history'    => esc_url_raw($rest_history_endpoint),
            'aggregates' => esc_url_raw($rest_aggregates_endpoint),
            'http'       => esc_url_raw($rest_http_endpoint),
            'nonce'      => $rest_nonce,
        ],
        'granularity'    => [
            'default' => 'raw',
            'choices' => $granularity_choices,
        ],
        'aggregates'     => [
            'metrics'      => $aggregated_metrics,
            'summary'      => $history_summary,
            'summaryText'  => $history_summary_text,
        ],
        'httpMonitor'    => [
            'initial'       => $http_stats,
            'windowSeconds' => DAY_IN_SECONDS,
            'limit'         => 25,
        ],
        'reporting'      => [
            'lastReport' => $last_report_for_js,
        ],
        'request'        => [
            'perPage' => 288,
            'since'   => null,
        ],
        'i18n'           => [
            'loadLabel'         => esc_html__('Charge CPU (1 min)', 'sitepulse'),
            'memoryLabel'       => esc_html__('Mémoire utilisée (%)', 'sitepulse'),
            'diskLabel'         => esc_html__('Stockage utilisé (%)', 'sitepulse'),
            'percentAxisLabel'  => esc_html__('% d’utilisation', 'sitepulse'),
            'noHistory'         => esc_html__("Aucun historique disponible pour le moment.", 'sitepulse'),
            'timestamp'         => esc_html__('Horodatage', 'sitepulse'),
            'unavailable'       => esc_html__('N/A', 'sitepulse'),
            'memoryUsage'       => esc_html__('Mémoire utilisée', 'sitepulse'),
            'diskUsage'         => esc_html__('Stockage utilisé', 'sitepulse'),
            'diskFree'          => esc_html__('Stockage libre', 'sitepulse'),
            'cronPoint'         => esc_html__('Collecte automatique', 'sitepulse'),
            'manualPoint'       => esc_html__('Collecte manuelle', 'sitepulse'),
            'granularityLabel'  => esc_html__('Agrégation', 'sitepulse'),
            'aggregatesTitle'   => esc_html__('Statistiques avancées', 'sitepulse'),
            'aggregatesEmpty'   => esc_html__('Aucune donnée agrégée disponible pour cette sélection.', 'sitepulse'),
            'averageLabel'      => esc_html__('Moyenne', 'sitepulse'),
            'maxLabel'          => esc_html__('Max', 'sitepulse'),
            'p95Label'          => esc_html__('P95', 'sitepulse'),
            'trendLabel'        => esc_html__('Tendance (par heure)', 'sitepulse'),
            'trendUp'           => esc_html__('Hausse', 'sitepulse'),
            'trendDown'         => esc_html__('Baisse', 'sitepulse'),
            'trendFlat'         => esc_html__('Stable', 'sitepulse'),
            'reportQueued'      => esc_html__('Le rapport a été planifié et sera envoyé sous peu.', 'sitepulse'),
            'reportExecuted'    => esc_html__('Le rapport a été généré avec succès.', 'sitepulse'),
            'httpMonitorTitle'  => esc_html__('Services externes', 'sitepulse'),
            'httpMonitorSummary'=> esc_html__('Synthèse des appels sortants (24 h)', 'sitepulse'),
            'httpMonitorEmpty'  => esc_html__('Aucun appel externe enregistré sur la période.', 'sitepulse'),
            'httpMonitorLatency'=> esc_html__('Latence (moy./max/p95)', 'sitepulse'),
            'httpMonitorErrors' => esc_html__('Taux d’erreurs', 'sitepulse'),
            'httpMonitorRequests' => esc_html__('Requêtes', 'sitepulse'),
            'httpMonitorLastSeen'=> esc_html__('Dernière occurrence', 'sitepulse'),
            'httpMonitorSamples'=> esc_html__('Derniers appels', 'sitepulse'),
            'httpMonitorStatus' => esc_html__('Statut', 'sitepulse'),
            'httpMonitorDuration' => esc_html__('Durée', 'sitepulse'),
            'httpMonitorMethod' => esc_html__('Méthode', 'sitepulse'),
            'httpMonitorHost'   => esc_html__('Hôte', 'sitepulse'),
            'httpMonitorPath'   => esc_html__('Chemin', 'sitepulse'),
            'httpMonitorThresholdLatency' => esc_html__('Seuil latence (p95)', 'sitepulse'),
            'httpMonitorThresholdErrors'  => esc_html__('Seuil taux d’erreurs', 'sitepulse'),
            'httpMonitorRefresh' => esc_html__('Actualiser les statistiques', 'sitepulse'),
            'httpMonitorLoading' => esc_html__('Récupération des métriques des appels externes…', 'sitepulse'),
            'httpMonitorError'   => esc_html__('Impossible de récupérer les métriques des appels externes.', 'sitepulse'),
        ],
        'refreshFeedback' => $refresh_feedback,
        'refreshStatusId' => 'sitepulse-resource-refresh-status',
    ];

    wp_localize_script(
        'sitepulse-resource-monitor',
        'SitePulseResourceMonitor',
        $sitepulse_localized
    );

    if (!empty($snapshot['notices']) && is_array($snapshot['notices'])) {
        $resource_monitor_notices = array_merge($resource_monitor_notices, $snapshot['notices']);
    }

    $generated_at = isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;
    $generated_label = $generated_at > 0
        ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $generated_at)
        : esc_html__('Inconnue', 'sitepulse');

    $age = '';

    if ($generated_at > 0) {
        $age = human_time_diff($generated_at, (int) current_time('timestamp', true));
    }

    $last_automatic_notice = '';
    $now_utc = (int) current_time('timestamp', true);

    if ($last_cron_timestamp) {
        $last_auto_label = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_cron_timestamp);
        $last_auto_age = human_time_diff($last_cron_timestamp, $now_utc);
        $last_automatic_notice = sprintf(
            /* translators: 1: formatted date, 2: relative time. */
            esc_html__('Dernière collecte automatique : %1$s (%2$s).', 'sitepulse'),
            esc_html($last_auto_label),
            sprintf(
                /* translators: %s: human readable duration. */
                esc_html__('il y a %s', 'sitepulse'),
                esc_html($last_auto_age)
            )
        );
    } else {
        $last_automatic_notice = esc_html__('Aucune collecte automatique enregistrée pour le moment.', 'sitepulse');
    }

    $export_endpoint = admin_url('admin-post.php');
    $export_csv_url = wp_nonce_url(add_query_arg([
        'action' => 'sitepulse_resource_monitor_export',
        'format' => 'csv',
    ], $export_endpoint), SITEPULSE_NONCE_ACTION_RESOURCE_MONITOR_EXPORT);
    $export_json_url = wp_nonce_url(add_query_arg([
        'action' => 'sitepulse_resource_monitor_export',
        'format' => 'json',
    ], $export_endpoint), SITEPULSE_NONCE_ACTION_RESOURCE_MONITOR_EXPORT);

    $report_action_url = admin_url('admin-post.php');
    $unavailable_label = __('N/A', 'sitepulse');

    $metric_cards = [
        'load_1' => [
            'label'    => __('Charge CPU (1 min)', 'sitepulse'),
            'metric'   => $aggregated_metrics['load_1'] ?? [],
            'decimals' => 2,
            'suffix'   => '',
        ],
        'memory_percent' => [
            'label'    => __('Mémoire utilisée (%)', 'sitepulse'),
            'metric'   => $aggregated_metrics['memory_percent'] ?? [],
            'decimals' => 1,
            'suffix'   => '%',
        ],
        'disk_used' => [
            'label'    => __('Stockage utilisé (%)', 'sitepulse'),
            'metric'   => $aggregated_metrics['disk_used'] ?? [],
            'decimals' => 1,
            'suffix'   => '%',
        ],
    ];

    $metric_display = [];

    foreach ($metric_cards as $key => $card) {
        $metric = $card['metric'];
        $average_display = $unavailable_label;
        $max_display = $unavailable_label;
        $p95_display = $unavailable_label;
        $trend_display = $unavailable_label;
        $trend_direction = 'flat';

        if (isset($metric['average']) && $metric['average'] !== null) {
            $average_display = number_format_i18n((float) $metric['average'], $card['decimals']) . $card['suffix'];
        }

        if (isset($metric['max']) && $metric['max'] !== null) {
            $max_display = number_format_i18n((float) $metric['max'], $card['decimals']) . $card['suffix'];
        }

        if (isset($metric['percentiles']['p95']) && $metric['percentiles']['p95'] !== null) {
            $p95_display = number_format_i18n((float) $metric['percentiles']['p95'], $card['decimals']) . $card['suffix'];
        }

        if (isset($metric['trend']) && is_array($metric['trend'])) {
            $trend = $metric['trend'];
            $trend_direction = isset($trend['direction']) ? (string) $trend['direction'] : 'flat';
            if (isset($trend['slope_per_hour']) && is_numeric($trend['slope_per_hour'])) {
                $slope_value = number_format_i18n((float) $trend['slope_per_hour'], $card['decimals']) . $card['suffix'];
                $symbol = '→';
                if ($trend_direction === 'up') {
                    $symbol = '↑';
                } elseif ($trend_direction === 'down') {
                    $symbol = '↓';
                }
                $trend_display = sprintf('%s %s/h', $symbol, $slope_value);
            }
        }

        $metric_display[$key] = [
            'average'         => $average_display,
            'max'             => $max_display,
            'p95'             => $p95_display,
            'trend_value'     => $trend_display,
            'trend_direction' => $trend_direction,
        ];
    }
    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-resources');
    }
    ?>
    <div class="wrap sitepulse-resource-monitor">
        <h1><span class="dashicons-before dashicons-performance"></span> <?php esc_html_e('Moniteur de Ressources', 'sitepulse'); ?></h1>
        <?php if (!empty($resource_monitor_notices)) : ?>
            <div class="sitepulse-notices">
                <?php foreach ($resource_monitor_notices as $notice) : ?>
                    <div class="notice notice-<?php echo esc_attr($notice['type']); ?>">
                        <p><?php echo esc_html($notice['message']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="sitepulse-resource-grid">
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Charge CPU (1/5/15 min)', 'sitepulse'); ?></h2>
                <?php
                $load_display_output = isset($snapshot['load']) && is_array($snapshot['load'])
                    ? sitepulse_resource_monitor_format_load_display($snapshot['load'])
                    : (string) ($snapshot['load_display'] ?? '');
                ?>
                <p class="sitepulse-resource-value"><?php echo esc_html($load_display_output); ?></p>
            </div>
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Mémoire', 'sitepulse'); ?></h2>
                <p class="sitepulse-resource-value">
                    <?php
                    $memory_percent_display = isset($snapshot['memory_usage_percent']) && is_numeric($snapshot['memory_usage_percent'])
                        ? number_format_i18n((float) $snapshot['memory_usage_percent'], 1)
                        : null;

                    if ($memory_percent_display !== null) {
                        printf(esc_html__('%s %% utilisés', 'sitepulse'), esc_html($memory_percent_display));
                    } else {
                        echo esc_html((string) ($snapshot['memory_usage'] ?? ''));
                    }
                    ?>
                </p>
                <p class="sitepulse-resource-subvalue">
                    <?php
                    $memory_limit_label = isset($snapshot['memory_limit']) ? (string) $snapshot['memory_limit'] : '';

                    if ($memory_limit_label !== '') {
                        printf(
                            /* translators: 1: memory used, 2: memory limit. */
                            esc_html__('Utilisation : %1$s / Limite : %2$s', 'sitepulse'),
                            esc_html((string) ($snapshot['memory_usage'] ?? '')),
                            esc_html($memory_limit_label)
                        );
                    } else {
                        printf(
                            /* translators: %s: memory used. */
                            esc_html__('Utilisation : %s', 'sitepulse'),
                            esc_html((string) ($snapshot['memory_usage'] ?? ''))
                        );
                    }
                    ?>
                </p>
            </div>
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Stockage disque', 'sitepulse'); ?></h2>
                <p class="sitepulse-resource-value">
                    <?php
                    $disk_used_percent_display = isset($snapshot['disk_used_percent']) && is_numeric($snapshot['disk_used_percent'])
                        ? number_format_i18n((float) $snapshot['disk_used_percent'], 1)
                        : null;

                    if ($disk_used_percent_display !== null) {
                        printf(esc_html__('%s %% utilisés', 'sitepulse'), esc_html($disk_used_percent_display));
                    } else {
                        echo esc_html((string) ($snapshot['disk_used'] ?? ''));
                    }
                    ?>
                </p>
                <p class="sitepulse-resource-subvalue">
                    <?php
                    printf(
                        /* translators: 1: used disk, 2: free disk, 3: total disk. */
                        esc_html__('Utilisé : %1$s — Libre : %2$s (Total : %3$s)', 'sitepulse'),
                        esc_html((string) ($snapshot['disk_used'] ?? '')),
                        esc_html((string) ($snapshot['disk_free'] ?? '')),
                        esc_html((string) ($snapshot['disk_total'] ?? ''))
                    );
                    ?>
                </p>
            </div>
        </div>
        <div class="sitepulse-resource-meta">
            <p>
                <?php
                if ($age !== '') {
                    printf(
                        /* translators: 1: formatted date, 2: relative time. */
                        esc_html__('Mesures relevées le %1$s (%2$s).', 'sitepulse'),
                        esc_html($generated_label),
                        sprintf(
                            /* translators: %s: human-readable time difference. */
                            esc_html__('il y a %s', 'sitepulse'),
                            esc_html($age)
                        )
                    );
                } else {
                    printf(
                        /* translators: %s: formatted date. */
                        esc_html__('Mesures relevées le %s.', 'sitepulse'),
                        esc_html($generated_label)
                    );
                }
                ?>
            </p>
            <p><?php echo $last_automatic_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
            <div id="sitepulse-resource-refresh-status" class="screen-reader-text" role="status" aria-live="polite"></div>
            <form method="post">
                <?php wp_nonce_field('sitepulse_refresh_resource_snapshot'); ?>
                <button type="submit" name="sitepulse_resource_monitor_refresh" class="button button-secondary">
                    <?php esc_html_e('Actualiser les mesures', 'sitepulse'); ?>
                </button>
            </form>
        </div>
        <section class="sitepulse-http-monitor" data-http-monitor>
            <div class="sitepulse-http-monitor-header">
                <h2><?php esc_html_e('Services externes', 'sitepulse'); ?></h2>
                <button type="button" class="button button-secondary" data-http-monitor-refresh>
                    <?php esc_html_e('Actualiser les statistiques', 'sitepulse'); ?>
                </button>
            </div>
            <p class="description" data-http-monitor-description></p>
            <div class="sitepulse-http-monitor-thresholds">
                <span data-http-monitor-threshold-latency></span>
                <span data-http-monitor-threshold-errors></span>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sitepulse-http-monitor-settings">
                <?php wp_nonce_field($http_settings_nonce); ?>
                <input type="hidden" name="action" value="sitepulse_save_http_monitor_settings">
                <div class="sitepulse-http-monitor-fields">
                    <label>
                        <span><?php esc_html_e('Seuil latence p95 (ms)', 'sitepulse'); ?></span>
                        <input type="number" name="sitepulse_http_latency_threshold" min="0" step="10" value="<?php echo esc_attr($http_latency_value); ?>" />
                        <span class="description"><?php esc_html_e('Définissez 0 pour désactiver les alertes sur la latence.', 'sitepulse'); ?></span>
                    </label>
                    <label>
                        <span><?php esc_html_e('Seuil taux d’erreurs (%)', 'sitepulse'); ?></span>
                        <input type="number" name="sitepulse_http_error_rate" min="0" max="100" step="1" value="<?php echo esc_attr($http_error_value); ?>" />
                        <span class="description"><?php esc_html_e('Pourcentage maximal d’appels en erreur avant déclenchement d’une alerte.', 'sitepulse'); ?></span>
                    </label>
                    <label>
                        <span><?php esc_html_e('Rétention des données (jours)', 'sitepulse'); ?></span>
                        <input type="number" name="sitepulse_http_retention_days" min="1" max="365" step="1" value="<?php echo esc_attr($http_retention_days); ?>" />
                    </label>
                </div>
                <p class="submit">
                    <button type="submit" class="button button-secondary"><?php esc_html_e('Enregistrer les seuils', 'sitepulse'); ?></button>
                </p>
            </form>
            <div class="sitepulse-http-monitor-summary" data-http-monitor-summary></div>
            <div class="sitepulse-http-monitor-table-wrapper">
                <table class="widefat striped" data-http-monitor-table>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Hôte', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Chemin', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Méthode', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Requêtes', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Latence (moy./max)', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Taux d’erreurs', 'sitepulse'); ?></th>
                        </tr>
                    </thead>
                    <tbody data-http-monitor-table-body>
                        <tr data-empty>
                            <td colspan="6"><?php esc_html_e('Aucun appel externe enregistré sur la période.', 'sitepulse'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="sitepulse-http-monitor-samples" data-http-monitor-samples>
                <h3><?php esc_html_e('Derniers appels', 'sitepulse'); ?></h3>
                <ul data-http-monitor-sample-list>
                    <li data-empty><?php esc_html_e('Aucun appel externe enregistré sur la période.', 'sitepulse'); ?></li>
                </ul>
            </div>
        </section>
        <div class="sitepulse-resource-history" id="sitepulse-resource-history">
            <div class="sitepulse-resource-history-header">
                <h2><?php esc_html_e('Historique des ressources', 'sitepulse'); ?></h2>
                <div class="sitepulse-resource-history-controls">
                    <label for="sitepulse-resource-history-granularity"><?php esc_html_e('Agrégation', 'sitepulse'); ?></label>
                    <select id="sitepulse-resource-history-granularity">
                        <?php foreach ($granularity_choices as $choice) : ?>
                            <option value="<?php echo esc_attr($choice['value']); ?>"><?php echo esc_html($choice['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="sitepulse-resource-history-chart">
                <canvas id="sitepulse-resource-history-chart" aria-describedby="sitepulse-resource-history-summary"></canvas>
            </div>
            <p class="sitepulse-resource-history-empty" role="status" aria-live="polite" data-empty<?php if (!empty($history_entries)) { echo ' hidden'; } ?>>
                <?php esc_html_e("Aucun historique disponible pour le moment.", 'sitepulse'); ?>
            </p>
            <p id="sitepulse-resource-history-summary" class="sitepulse-resource-history-summary" role="status" aria-live="polite">
                <?php echo esc_html($history_summary_text); ?>
            </p>
            <div class="sitepulse-resource-history-actions">
                <a class="button button-secondary" href="<?php echo esc_url($export_csv_url); ?>"><?php esc_html_e('Exporter en CSV', 'sitepulse'); ?></a>
                <a class="button button-secondary" href="<?php echo esc_url($export_json_url); ?>"><?php esc_html_e('Exporter en JSON', 'sitepulse'); ?></a>
            </div>
        </div>
<div class="sitepulse-resource-aggregates" id="sitepulse-resource-aggregates">
            <h2><?php esc_html_e('Statistiques avancées', 'sitepulse'); ?></h2>
            <p id="sitepulse-resource-aggregates-summary" class="sitepulse-resource-aggregates-summary">
                <?php echo esc_html($history_summary_text); ?>
            </p>
            <div class="sitepulse-resource-aggregate-grid" data-aggregates>
                <?php foreach ($metric_cards as $key => $card) :
                    $display = $metric_display[$key];
                    $direction = $display['trend_direction'];
                    ?>
                    <div class="sitepulse-resource-aggregate-card is-<?php echo esc_attr($direction); ?>" data-metric="<?php echo esc_attr($key); ?>">
                        <h3><?php echo esc_html($card['label']); ?></h3>
                        <p class="sitepulse-resource-aggregate-line"><strong><?php esc_html_e('Moyenne', 'sitepulse'); ?> :</strong> <span data-metric-average><?php echo esc_html($display['average']); ?></span></p>
                        <p class="sitepulse-resource-aggregate-line"><strong><?php esc_html_e('Max', 'sitepulse'); ?> :</strong> <span data-metric-max><?php echo esc_html($display['max']); ?></span></p>
                        <p class="sitepulse-resource-aggregate-line"><strong><?php esc_html_e('P95', 'sitepulse'); ?> :</strong> <span data-metric-percentiles><?php echo esc_html($display['p95']); ?></span></p>
                        <p class="sitepulse-resource-aggregate-line"><strong><?php esc_html_e('Tendance (par heure)', 'sitepulse'); ?> :</strong> <span data-metric-trend><?php echo esc_html($display['trend_value']); ?></span></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="sitepulse-resource-report-actions">
            <h2><?php esc_html_e('Rapports programmés', 'sitepulse'); ?></h2>
            <form method="post" action="<?php echo esc_url($report_action_url); ?>" class="sitepulse-resource-report-form">
                <?php wp_nonce_field('sitepulse_resource_monitor_trigger_report'); ?>
                <input type="hidden" name="action" value="sitepulse_resource_monitor_trigger_report">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Générer un rapport maintenant', 'sitepulse'); ?>
                </button>
            </form>
            <?php if ($last_report_display) : ?>
                <p class="sitepulse-resource-report-meta">
                    <?php if ($last_report_display['label']) : ?>
                        <?php printf(/* translators: %s: report generated date. */ esc_html__('Dernier rapport généré le %s.', 'sitepulse'), esc_html($last_report_display['label'])); ?>
                    <?php endif; ?>
                    <?php if ($last_report_display['summary']) : ?>
                        <span><?php echo esc_html($last_report_display['summary']); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Converts a PHP memory limit value to bytes when possible.
 *
 * @param mixed $memory_limit_ini Raw memory_limit configuration value.
 * @return int|null
 */
function sitepulse_resource_monitor_normalize_memory_limit_bytes($memory_limit_ini) {
    if ($memory_limit_ini === false) {
        return null;
    }

    $memory_limit_value = trim((string) $memory_limit_ini);

    if ($memory_limit_value === '') {
        return null;
    }

    $memory_limit_lower = strtolower($memory_limit_value);

    if (
        $memory_limit_lower === '-1'
        || $memory_limit_lower === 'unlimited'
        || (float) $memory_limit_value === -1.0
    ) {
        return null;
    }

    if (function_exists('wp_convert_hr_to_bytes')) {
        $bytes = wp_convert_hr_to_bytes($memory_limit_value);

        if (is_numeric($bytes) && (int) $bytes > 0) {
            return (int) $bytes;
        }
    }

    if (is_numeric($memory_limit_value)) {
        $numeric = (float) $memory_limit_value;

        if ($numeric > 0) {
            return (int) $numeric;
        }
    }

    return null;
}

/**
 * Appends a snapshot entry to the history option.
 *
 * @param array $snapshot Snapshot data enriched with raw metrics.
 * @return void
 */
function sitepulse_resource_monitor_append_history(array $snapshot) {
    $entry = sitepulse_resource_monitor_build_history_entry($snapshot);

    if ($entry === null) {
        return;
    }

    sitepulse_resource_monitor_maybe_upgrade_schema();

    $lock_token = sitepulse_resource_monitor_acquire_history_lock();

    if ($lock_token === false) {
        return;
    }

    try {
        if (sitepulse_resource_monitor_table_exists()) {
            sitepulse_resource_monitor_insert_history_entry($entry);
        } else {
            $option_name = SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY;
            $history = get_option($option_name, []);

            if (!is_array($history)) {
                $history = [];
            }

            $history[] = $entry;
            $history = sitepulse_resource_monitor_normalize_history($history);

            update_option($option_name, $history, false);
        }

        sitepulse_resource_monitor_invalidate_analytics_cache();
    } finally {
        sitepulse_resource_monitor_release_history_lock($lock_token);
    }
}

/**
 * Attempts to acquire a short-lived lock around the resource history option.
 *
 * @param int $timeout_seconds Maximum number of seconds to wait for the lock.
 * @return string|false Lock token on success, false otherwise.
 */
function sitepulse_resource_monitor_acquire_history_lock($timeout_seconds = 5) {
    if (!function_exists('add_option') || !function_exists('get_option') || !function_exists('delete_option')) {
        return false;
    }

    $lock_key = SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY_LOCK;
    $timeout_seconds = max(1, (int) $timeout_seconds);
    $wait_microseconds = (int) apply_filters('sitepulse_resource_monitor_lock_wait_interval', 200000);
    $expiry_seconds = (int) apply_filters('sitepulse_resource_monitor_lock_expiry', 10);
    $start = microtime(true);

    do {
        if (add_option($lock_key, time(), '', 'no')) {
            return $lock_key;
        }

        $lock_timestamp = get_option($lock_key);

        if (is_numeric($lock_timestamp) && (time() - (int) $lock_timestamp) > $expiry_seconds) {
            delete_option($lock_key);
            continue;
        }

        if ($wait_microseconds > 0) {
            usleep($wait_microseconds);
        }
    } while ((microtime(true) - $start) < $timeout_seconds);

    return false;
}

/**
 * Releases the lock obtained for the resource history option.
 *
 * @param string|false $lock_token Lock token to release.
 * @return void
 */
function sitepulse_resource_monitor_release_history_lock($lock_token) {
    if (!is_string($lock_token) || $lock_token === '' || !function_exists('delete_option')) {
        return;
    }

    delete_option($lock_token);
}

/**
 * Builds a normalized history entry from the snapshot.
 *
 * @param array $snapshot Snapshot data.
 * @return array|null
 */
function sitepulse_resource_monitor_build_history_entry(array $snapshot) {
    $timestamp = isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;

    if ($timestamp <= 0) {
        return null;
    }

    $load_values = [null, null, null];

    if (isset($snapshot['load_raw']) && is_array($snapshot['load_raw'])) {
        foreach (array_slice(array_values($snapshot['load_raw']), 0, 3) as $index => $value) {
            $load_values[$index] = is_numeric($value) ? (float) $value : null;
        }
    } elseif (isset($snapshot['load']) && is_array($snapshot['load'])) {
        foreach (array_slice(array_values($snapshot['load']), 0, 3) as $index => $value) {
            $load_values[$index] = is_numeric($value) ? (float) $value : null;
        }
    }

    $memory_usage_bytes = isset($snapshot['memory_usage_bytes']) && is_numeric($snapshot['memory_usage_bytes'])
        ? max(0, (int) $snapshot['memory_usage_bytes'])
        : null;

    $memory_limit_bytes = isset($snapshot['memory_limit_bytes']) && is_numeric($snapshot['memory_limit_bytes'])
        ? max(0, (int) $snapshot['memory_limit_bytes'])
        : null;

    $disk_free_bytes = isset($snapshot['disk_free_bytes']) && is_numeric($snapshot['disk_free_bytes'])
        ? max(0, (int) $snapshot['disk_free_bytes'])
        : null;

    $disk_total_bytes = isset($snapshot['disk_total_bytes']) && is_numeric($snapshot['disk_total_bytes'])
        ? max(0, (int) $snapshot['disk_total_bytes'])
        : null;

    $source = isset($snapshot['source']) ? (string) $snapshot['source'] : 'manual';

    if (function_exists('sanitize_key')) {
        $source = sanitize_key($source);
    } else {
        $source = strtolower(preg_replace('/[^a-z0-9_\-]/', '', $source));
    }

    if ($source === '') {
        $source = 'manual';
    }

    return [
        'timestamp' => $timestamp,
        'load'      => $load_values,
        'memory'    => [
            'usage' => $memory_usage_bytes,
            'limit' => $memory_limit_bytes,
        ],
        'disk'      => [
            'free'  => $disk_free_bytes,
            'total' => $disk_total_bytes,
        ],
        'source'    => $source,
    ];
}

/**
 * Normalizes history entries and applies TTL / max length constraints.
 *
 * @param array $history Raw history entries.
 * @return array<int, array>
 */
function sitepulse_resource_monitor_normalize_history(array $history) {
    $sanitized = [];

    foreach ($history as $entry) {
        $normalized = sitepulse_resource_monitor_normalize_single_history_entry($entry);

        if ($normalized === null) {
            continue;
        }

        $timestamp = $normalized['timestamp'];

        $sanitized[$timestamp] = $normalized;
    }

    if (empty($sanitized)) {
        return [];
    }

    ksort($sanitized, SORT_NUMERIC);

    return array_values($sanitized);
}

/**
 * Normalizes a raw history entry regardless of its source.
 *
 * @param mixed $entry Raw entry structure.
 * @return array|null
 */
function sitepulse_resource_monitor_normalize_single_history_entry($entry) {
    if (!is_array($entry)) {
        return null;
    }

    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

    if ($timestamp <= 0 && isset($entry['recorded_at'])) {
        $timestamp = (int) $entry['recorded_at'];
    }

    if ($timestamp <= 0) {
        return null;
    }

    $load = [null, null, null];

    if (isset($entry['load']) && is_array($entry['load'])) {
        foreach (array_slice(array_values($entry['load']), 0, 3) as $index => $value) {
            $load[$index] = is_numeric($value) ? (float) $value : null;
        }
    } else {
        if (isset($entry['load_1']) && is_numeric($entry['load_1'])) {
            $load[0] = (float) $entry['load_1'];
        }

        if (isset($entry['load_5']) && is_numeric($entry['load_5'])) {
            $load[1] = (float) $entry['load_5'];
        }

        if (isset($entry['load_15']) && is_numeric($entry['load_15'])) {
            $load[2] = (float) $entry['load_15'];
        }
    }

    $memory_usage = null;
    $memory_limit = null;

    if (isset($entry['memory']) && is_array($entry['memory'])) {
        if (isset($entry['memory']['usage']) && is_numeric($entry['memory']['usage'])) {
            $memory_usage = max(0, (int) $entry['memory']['usage']);
        }

        if (isset($entry['memory']['limit']) && is_numeric($entry['memory']['limit'])) {
            $memory_limit = max(0, (int) $entry['memory']['limit']);
        }
    } else {
        if (isset($entry['memory_usage']) && is_numeric($entry['memory_usage'])) {
            $memory_usage = max(0, (int) $entry['memory_usage']);
        }

        if (isset($entry['memory_limit']) && is_numeric($entry['memory_limit'])) {
            $memory_limit = max(0, (int) $entry['memory_limit']);
        }
    }

    $disk_free = null;
    $disk_total = null;

    if (isset($entry['disk']) && is_array($entry['disk'])) {
        if (isset($entry['disk']['free']) && is_numeric($entry['disk']['free'])) {
            $disk_free = max(0, (int) $entry['disk']['free']);
        }

        if (isset($entry['disk']['total']) && is_numeric($entry['disk']['total'])) {
            $disk_total = max(0, (int) $entry['disk']['total']);
        }
    } else {
        if (isset($entry['disk_free']) && is_numeric($entry['disk_free'])) {
            $disk_free = max(0, (int) $entry['disk_free']);
        }

        if (isset($entry['disk_total']) && is_numeric($entry['disk_total'])) {
            $disk_total = max(0, (int) $entry['disk_total']);
        }
    }

    $source = 'manual';

    if (isset($entry['source'])) {
        $entry_source = (string) $entry['source'];

        if (function_exists('sanitize_key')) {
            $entry_source = sanitize_key($entry_source);
        } else {
            $entry_source = strtolower(preg_replace('/[^a-z0-9_\-]/', '', $entry_source));
        }

        if ($entry_source !== '') {
            $source = $entry_source;
        }
    }

    return [
        'timestamp' => $timestamp,
        'load'      => $load,
        'memory'    => [
            'usage' => $memory_usage,
            'limit' => $memory_limit,
        ],
        'disk'      => [
            'free'  => $disk_free,
            'total' => $disk_total,
        ],
        'source'    => $source,
    ];
}

/**
 * Returns the normalized history entries.
 *
 * @return array<int, array>
 */
function sitepulse_resource_monitor_get_history($args = []) {
    $defaults = [
        'per_page' => 0,
        'page'     => 1,
        'since'    => null,
        'order'    => 'ASC',
    ];

    if (function_exists('wp_parse_args')) {
        $args = wp_parse_args($args, $defaults);
    } else {
        $args = array_merge($defaults, is_array($args) ? $args : []);
    }

    $per_page = (int) $args['per_page'];
    $page = (int) $args['page'];
    $page = $page > 0 ? $page : 1;
    $since = $args['since'];
    $order = strtoupper((string) $args['order']) === 'DESC' ? 'DESC' : 'ASC';

    if ($since !== null) {
        $since = is_numeric($since) ? (int) $since : null;

        if ($since !== null && $since <= 0) {
            $since = null;
        }
    }

    sitepulse_resource_monitor_maybe_upgrade_schema();

    $table_exists = sitepulse_resource_monitor_table_exists();
    $total = 0;
    $filtered_total = 0;
    $entries = [];
    $pages = 0;

    if ($table_exists) {
        $table = sitepulse_resource_monitor_get_table_name();

        global $wpdb;

        if ($table !== '' && $wpdb instanceof wpdb) {
            $where_clauses = [];
            $where_params = [];

            if ($since !== null) {
                $where_clauses[] = 'recorded_at >= %d';
                $where_params[] = $since;
            }

            $where_sql = '';

            if (!empty($where_clauses)) {
                $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
            }

            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $filtered_total = $total;

            if ($since !== null) {
                $filtered_total = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where_sql}", $where_params)
                );
            }

            if ($per_page > 0) {
                $pages = $filtered_total > 0 ? (int) ceil($filtered_total / $per_page) : 0;

                if ($pages > 0) {
                    $page = max(1, min($page, $pages));
                } else {
                    $page = 1;
                }

                $offset = max(0, ($page - 1) * $per_page);
                $limit_sql = $wpdb->prepare(' LIMIT %d OFFSET %d', $per_page, $offset);
            } else {
                $limit_sql = '';
                $page = 1;
            }

            $query = "SELECT recorded_at, load_1, load_5, load_15, memory_usage, memory_limit, disk_free, disk_total, source FROM {$table} {$where_sql} ORDER BY recorded_at {$order}{$limit_sql}";

            if (!empty($where_params)) {
                $query = $wpdb->prepare($query, $where_params);
            }

            $rows = $wpdb->get_results($query, ARRAY_A);

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $normalized = sitepulse_resource_monitor_normalize_single_history_entry($row);

                    if ($normalized !== null) {
                        $entries[] = $normalized;
                    }
                }
            }
        }
    } else {
        $option_name = SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY;
        $history = get_option($option_name, []);

        if (!is_array($history)) {
            $history = [];
        }

        $history = sitepulse_resource_monitor_normalize_history($history);
        $total = count($history);
        $filtered_entries = $history;

        if ($since !== null) {
            $filtered_entries = array_values(array_filter(
                $filtered_entries,
                static function ($entry) use ($since) {
                    return isset($entry['timestamp']) && (int) $entry['timestamp'] >= $since;
                }
            ));
        }

        if ($order === 'DESC') {
            $filtered_entries = array_reverse($filtered_entries);
        }

        $filtered_total = count($filtered_entries);

        if ($per_page > 0) {
            $pages = $filtered_total > 0 ? (int) ceil($filtered_total / $per_page) : 0;

            if ($pages > 0) {
                $page = max(1, min($page, $pages));
                $entries = array_slice($filtered_entries, ($page - 1) * $per_page, $per_page);
            } else {
                $page = 1;
                $entries = [];
            }
        } else {
            $entries = $filtered_entries;
            $page = 1;
        }
    }

    if ($per_page <= 0) {
        $pages = $filtered_total > 0 ? 1 : 0;
    }

    return [
        'entries'  => $entries,
        'total'    => $total,
        'filtered' => $filtered_total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => $pages,
        'order'    => $order,
    ];
}

/**
 * Retrieves the timestamp of the most recent cron-generated snapshot.
 *
 * @param array<int, array>|null $history_entries Optional pre-fetched history entries.
 * @return int|null
 */
function sitepulse_resource_monitor_get_last_cron_timestamp($history_entries = null) {
    if (is_array($history_entries)) {
        if (isset($history_entries['entries']) && is_array($history_entries['entries'])) {
            $history_entries = $history_entries['entries'];
        }

        if (is_array($history_entries)) {
            for ($index = count($history_entries) - 1; $index >= 0; $index--) {
                $entry = $history_entries[$index];

                if (!is_array($entry)) {
                    continue;
                }

                if (isset($entry['source']) && $entry['source'] === 'cron') {
                    return isset($entry['timestamp']) ? (int) $entry['timestamp'] : null;
                }
            }
        }
    }

    sitepulse_resource_monitor_maybe_upgrade_schema();

    if (sitepulse_resource_monitor_table_exists()) {
        $table = sitepulse_resource_monitor_get_table_name();

        global $wpdb;

        if ($table !== '' && $wpdb instanceof wpdb) {
            $timestamp = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT recorded_at FROM {$table} WHERE source = %s ORDER BY recorded_at DESC LIMIT 1",
                    'cron'
                )
            );

            if ($timestamp !== null) {
                return (int) $timestamp;
            }
        }
    }

    $option_name = SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY;
    $history = get_option($option_name, []);

    if (!is_array($history) || empty($history)) {
        return null;
    }

    $history = sitepulse_resource_monitor_normalize_history($history);

    for ($index = count($history) - 1; $index >= 0; $index--) {
        $entry = $history[$index];

        if (!is_array($entry)) {
            continue;
        }

        if (isset($entry['source']) && $entry['source'] === 'cron') {
            return isset($entry['timestamp']) ? (int) $entry['timestamp'] : null;
        }
    }

    return null;
}

/**
 * Retrieves the most recent cron timestamp optionally limited to a lower bound.
 *
 * @param int|null $since Minimum timestamp to consider.
 * @return int|null
 */
function sitepulse_resource_monitor_get_last_cron_timestamp_since($since = null) {
    $since_timestamp = null;

    if ($since !== null) {
        $since_timestamp = is_numeric($since) ? (int) $since : null;

        if ($since_timestamp !== null && $since_timestamp <= 0) {
            $since_timestamp = null;
        }
    }

    sitepulse_resource_monitor_maybe_upgrade_schema();

    if (sitepulse_resource_monitor_table_exists()) {
        $table = sitepulse_resource_monitor_get_table_name();

        global $wpdb;

        if ($table !== '' && $wpdb instanceof wpdb) {
            $params = ['cron'];
            $sql = "SELECT recorded_at FROM {$table} WHERE source = %s";

            if ($since_timestamp !== null) {
                $sql .= ' AND recorded_at >= %d';
                $params[] = $since_timestamp;
            }

            $sql .= ' ORDER BY recorded_at DESC LIMIT 1';

            $prepared = $wpdb->prepare($sql, $params);
            $timestamp = $wpdb->get_var($prepared);

            if ($timestamp !== null) {
                return (int) $timestamp;
            }
        }
    }

    $history = get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY, []);

    if (!is_array($history) || empty($history)) {
        return null;
    }

    $history = sitepulse_resource_monitor_normalize_history($history);

    for ($index = count($history) - 1; $index >= 0; $index--) {
        $entry = $history[$index];

        if (!is_array($entry) || !isset($entry['source']) || $entry['source'] !== 'cron') {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : null;

        if ($timestamp === null) {
            continue;
        }

        if ($since_timestamp === null || $timestamp >= $since_timestamp) {
            return $timestamp;
        }
    }

    return null;
}

/**
 * Removes the stored history.
 *
 * @return void
 */
function sitepulse_resource_monitor_clear_history() {
    delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);

    sitepulse_resource_monitor_invalidate_analytics_cache();

    sitepulse_resource_monitor_maybe_upgrade_schema();

    if (!sitepulse_resource_monitor_table_exists()) {
        return;
    }

    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '') {
        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $wpdb->query("DELETE FROM {$table}");
}

/**
 * Calculates a percentage based on a value and a total.
 *
 * @param int|float|null $value Usage value.
 * @param int|float|null $total Reference total.
 * @return float|null
 */
function sitepulse_resource_monitor_calculate_percentage($value, $total) {
    if (!is_numeric($value) || !is_numeric($total)) {
        return null;
    }

    $total = (float) $total;

    if ($total <= 0) {
        return null;
    }

    $percentage = ((float) $value / $total) * 100;

    return max(0.0, min(100.0, $percentage));
}

/**
 * Calculates the average of numeric values.
 *
 * @param array<int, float> $values Values to average.
 * @return float|null
 */
function sitepulse_resource_monitor_calculate_average(array $values) {
    $values = array_filter($values, static function ($value) {
        return is_numeric($value);
    });

    if (empty($values)) {
        return null;
    }

    return array_sum($values) / count($values);
}

/**
 * Calculates summary metrics for the history entries.
 *
 * @param array<int, array> $history_entries History entries.
 * @return array
 */
function sitepulse_resource_monitor_calculate_history_summary(array $history_entries) {
    $count = count($history_entries);

    if ($count === 0) {
        return [
            'count' => 0,
            'span' => 0,
            'first_timestamp' => null,
            'last_timestamp' => null,
            'average_load' => null,
            'latest_load' => null,
            'average_memory_percent' => null,
            'latest_memory_percent' => null,
            'average_disk_used_percent' => null,
            'latest_disk_used_percent' => null,
        ];
    }

    $first_timestamp = (int) $history_entries[0]['timestamp'];
    $last_timestamp = (int) $history_entries[$count - 1]['timestamp'];
    $span = max(0, $last_timestamp - $first_timestamp);

    $load_values = [];
    $memory_percentages = [];
    $disk_percentages = [];

    foreach ($history_entries as $entry) {
        if (isset($entry['load'][0]) && is_numeric($entry['load'][0])) {
            $load_values[] = (float) $entry['load'][0];
        }

        $memory_percent = sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null);
        if ($memory_percent !== null) {
            $memory_percentages[] = $memory_percent;
        }

        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage($entry['disk']['free'] ?? null, $entry['disk']['total'] ?? null);
        if ($disk_percent_free !== null) {
            $disk_percentages[] = max(0.0, min(100.0, 100.0 - $disk_percent_free));
        }
    }

    $latest_entry = $history_entries[$count - 1];
    $latest_disk_free_percent = sitepulse_resource_monitor_calculate_percentage($latest_entry['disk']['free'] ?? null, $latest_entry['disk']['total'] ?? null);
    $latest_disk_used_percent = $latest_disk_free_percent !== null ? max(0.0, min(100.0, 100.0 - $latest_disk_free_percent)) : null;

    return [
        'count' => $count,
        'span' => $span,
        'first_timestamp' => $first_timestamp,
        'last_timestamp' => $last_timestamp,
        'average_load' => sitepulse_resource_monitor_calculate_average($load_values),
        'latest_load' => isset($latest_entry['load'][0]) && is_numeric($latest_entry['load'][0]) ? (float) $latest_entry['load'][0] : null,
        'average_memory_percent' => sitepulse_resource_monitor_calculate_average($memory_percentages),
        'latest_memory_percent' => sitepulse_resource_monitor_calculate_percentage($latest_entry['memory']['usage'] ?? null, $latest_entry['memory']['limit'] ?? null),
        'average_disk_used_percent' => sitepulse_resource_monitor_calculate_average($disk_percentages),
        'latest_disk_used_percent' => $latest_disk_used_percent,
    ];
}

/**
 * Creates a localized text summary for history statistics.
 *
 * @param array $summary Summary generated by sitepulse_resource_monitor_calculate_history_summary().
 * @return string
 */
function sitepulse_resource_monitor_format_history_summary(array $summary) {
    if (empty($summary['count'])) {
        return esc_html__("Aucun historique disponible pour le moment.", 'sitepulse');
    }

    $range_label = ($summary['span'] > 0 && $summary['first_timestamp'] && $summary['last_timestamp'])
        ? human_time_diff($summary['first_timestamp'], $summary['last_timestamp'])
        : __('moins d\'une minute', 'sitepulse');

    $range_text = sprintf(
        /* translators: %s: human-readable duration. */
        __('sur %s', 'sitepulse'),
        $range_label
    );

    $sentences = [
        sprintf(
            /* translators: 1: number of entries, 2: duration description. */
            _n('%1$s relevé enregistré %2$s', '%1$s relevés enregistrés %2$s', $summary['count'], 'sitepulse'),
            number_format_i18n($summary['count']),
            $range_text
        ),
    ];

    if ($summary['average_load'] !== null) {
        $sentences[] = sprintf(
            /* translators: %s: average CPU load. */
            __('Charge moyenne (1 min) : %s', 'sitepulse'),
            number_format_i18n($summary['average_load'], 2)
        );
    }

    if ($summary['average_memory_percent'] !== null) {
        $sentences[] = sprintf(
            /* translators: %s: average memory usage percentage. */
            __('Mémoire utilisée : %s %%', 'sitepulse'),
            number_format_i18n($summary['average_memory_percent'], 1)
        );
    }

    if ($summary['average_disk_used_percent'] !== null) {
        $sentences[] = sprintf(
            /* translators: %s: average disk usage percentage. */
            __('Stockage utilisé : %s %%', 'sitepulse'),
            number_format_i18n($summary['average_disk_used_percent'], 1)
        );
    }

    return implode('. ', $sentences) . '.';
}

/**
 * Prepares history entries for JavaScript consumption.
 *
 * @param array<int, array> $history_entries Normalized history entries.
 * @return array<int, array>
 */
function sitepulse_resource_monitor_prepare_history_for_js(array $history_entries) {
    $prepared = [];

    foreach ($history_entries as $entry) {
        $memory_percent_usage = sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null);
        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage($entry['disk']['free'] ?? null, $entry['disk']['total'] ?? null);
        $disk_percent_used = null;

        if ($disk_percent_free !== null) {
            $disk_percent_used = max(0, min(100, 100 - $disk_percent_free));
        }

        $source = isset($entry['source']) ? (string) $entry['source'] : 'manual';

        $prepared[] = [
            'timestamp' => (int) $entry['timestamp'],
            'source'    => $source,
            'isCron'    => ($source === 'cron'),
            'load'      => array_map(
                static function ($value) {
                    return is_numeric($value) ? (float) $value : null;
                },
                array_pad(is_array($entry['load']) ? array_values($entry['load']) : [], 3, null)
            ),
            'memory'    => [
                'usage'        => isset($entry['memory']['usage']) && is_numeric($entry['memory']['usage']) ? (int) $entry['memory']['usage'] : null,
                'limit'        => isset($entry['memory']['limit']) && is_numeric($entry['memory']['limit']) ? (int) $entry['memory']['limit'] : null,
                'percentUsage' => $memory_percent_usage,
            ],
            'disk'      => [
                'free'         => isset($entry['disk']['free']) && is_numeric($entry['disk']['free']) ? (int) $entry['disk']['free'] : null,
                'total'        => isset($entry['disk']['total']) && is_numeric($entry['disk']['total']) ? (int) $entry['disk']['total'] : null,
                'percentFree'  => $disk_percent_free,
                'percentUsed'  => $disk_percent_used,
            ],
        ];
    }

    return $prepared;
}

/**
 * Returns the configured thresholds for automatic alerts.
 *
 * @return array{cpu:int,memory:int,disk:int}
 */
function sitepulse_resource_monitor_get_threshold_configuration() {
    $defaults = [
        'cpu'    => defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT') ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT : 85,
        'memory' => defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT') ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT : 90,
        'disk'   => defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT') ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT : 85,
    ];

    $thresholds = [
        'cpu'    => (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT, $defaults['cpu']),
        'memory' => (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT, $defaults['memory']),
        'disk'   => (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT, $defaults['disk']),
    ];

    foreach ($thresholds as $key => $value) {
        if (!is_numeric($value)) {
            $thresholds[$key] = $defaults[$key];
            continue;
        }

        $value = (int) $value;

        if ($value < 0) {
            $value = 0;
        }

        if ($value > 100) {
            $value = 100;
        }

        $thresholds[$key] = $value;
    }

    if (function_exists('apply_filters')) {
        $thresholds = apply_filters('sitepulse_resource_monitor_thresholds', $thresholds);
    }

    return $thresholds;
}

/**
 * Returns the number of consecutive cron snapshots required before alerting.
 *
 * @return int
 */
function sitepulse_resource_monitor_get_required_consecutive_snapshots() {
    $required = 3;

    if (function_exists('apply_filters')) {
        $required = (int) apply_filters('sitepulse_resource_monitor_required_consecutive_snapshots', $required);
    }

    if ($required < 1) {
        $required = 1;
    }

    return $required;
}

/**
 * Attempts to determine the number of CPU cores available for usage calculations.
 *
 * @return int
 */
function sitepulse_resource_monitor_get_cpu_core_count() {
    static $core_count = null;

    if ($core_count !== null) {
        return $core_count;
    }

    if (function_exists('sitepulse_error_alert_get_cpu_core_count')) {
        $detected = sitepulse_error_alert_get_cpu_core_count();
        $core_count = max(1, (int) $detected);

        return $core_count;
    }

    $core_count = 0;

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_resource_monitor_cpu_core_count', null);

        if (is_numeric($filtered) && (int) $filtered > 0) {
            $core_count = (int) $filtered;
        }
    }

    if ($core_count < 1 && function_exists('shell_exec')) {
        $disabled = explode(',', (string) ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        if (!in_array('shell_exec', $disabled, true)) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if (is_string($nproc)) {
                $nproc = (int) trim($nproc);
                if ($nproc > 0) {
                    $core_count = $nproc;
                }
            }

            if ($core_count < 1) {
                $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
                if (is_string($sysctl)) {
                    $sysctl = (int) trim($sysctl);
                    if ($sysctl > 0) {
                        $core_count = $sysctl;
                    }
                }
            }
        }
    }

    if ($core_count < 1) {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo) && $cpuinfo !== '') {
            if (preg_match_all('/^processor\s*:/m', $cpuinfo, $matches)) {
                $count = count($matches[0]);
                if ($count > 0) {
                    $core_count = $count;
                }
            }
        }
    }

    if ($core_count < 1 && function_exists('getenv')) {
        $env_cores = getenv('NUMBER_OF_PROCESSORS');
        if ($env_cores !== false && is_numeric($env_cores) && (int) $env_cores > 0) {
            $core_count = (int) $env_cores;
        }
    }

    if ($core_count < 1) {
        $core_count = 1;
    }

    if (function_exists('apply_filters')) {
        $core_count = (int) apply_filters('sitepulse_resource_monitor_detected_cpu_core_count', $core_count);
    }

    if ($core_count < 1) {
        $core_count = 1;
    }

    return $core_count;
}

/**
 * Calculates the CPU usage percentage based on a history entry.
 *
 * @param array $entry History entry.
 * @return float|null
 */
function sitepulse_resource_monitor_calculate_cpu_usage_percent(array $entry) {
    if (!isset($entry['load']) || !is_array($entry['load'])) {
        return null;
    }

    $load = array_values($entry['load']);

    if (!isset($load[0]) || !is_numeric($load[0])) {
        return null;
    }

    $core_count = sitepulse_resource_monitor_get_cpu_core_count();

    if ($core_count < 1) {
        $core_count = 1;
    }

    return ((float) $load[0] / $core_count) * 100;
}

/**
 * Calculates the disk usage percentage based on a history entry.
 *
 * @param array $entry History entry.
 * @return float|null
 */
function sitepulse_resource_monitor_calculate_disk_usage_percent(array $entry) {
    $percent_free = sitepulse_resource_monitor_calculate_percentage($entry['disk']['free'] ?? null, $entry['disk']['total'] ?? null);

    if ($percent_free === null) {
        return null;
    }

    return max(0, min(100, 100 - $percent_free));
}

/**
 * Processes the most recent snapshots recorded by the cron job and triggers alerts when needed.
 *
 * @return void
 */
function sitepulse_resource_monitor_run_cron() {
    if (!function_exists('sitepulse_is_module_active') || !sitepulse_is_module_active('resource_monitor')) {
        return;
    }

    $snapshot = sitepulse_resource_monitor_get_snapshot('cron');
    $required = sitepulse_resource_monitor_get_required_consecutive_snapshots();
    $fetch_count = max($required * 2, 50);

    $history_result = sitepulse_resource_monitor_get_history([
        'per_page' => $fetch_count,
        'page'     => 1,
        'order'    => 'DESC',
    ]);

    $history_entries = isset($history_result['entries']) && is_array($history_result['entries'])
        ? array_reverse($history_result['entries'])
        : [];
    $thresholds = sitepulse_resource_monitor_get_threshold_configuration();

    sitepulse_resource_monitor_check_thresholds($history_entries, $thresholds, $snapshot);
}

/**
 * Evaluates the recent cron history and triggers alerts if thresholds are exceeded.
 *
 * @param array<int, array> $history_entries Normalised history entries.
 * @param array             $thresholds      Threshold configuration.
 * @param array             $snapshot        Latest snapshot data.
 * @return void
 */
function sitepulse_resource_monitor_check_thresholds(array $history_entries, array $thresholds, array $snapshot) {
    if (empty($history_entries)) {
        return;
    }

    $required = sitepulse_resource_monitor_get_required_consecutive_snapshots();

    $latest_entry = $history_entries[count($history_entries) - 1];
    $latest_cpu_percent = sitepulse_resource_monitor_calculate_cpu_usage_percent($latest_entry);
    $latest_memory_percent = sitepulse_resource_monitor_calculate_percentage($latest_entry['memory']['usage'] ?? null, $latest_entry['memory']['limit'] ?? null);
    $latest_disk_percent = sitepulse_resource_monitor_calculate_disk_usage_percent($latest_entry);

    $cpu_streak = 0;
    $memory_streak = 0;
    $disk_streak = 0;
    $checked = 0;

    for ($index = count($history_entries) - 1; $index >= 0; $index--) {
        $entry = $history_entries[$index];

        if (!is_array($entry) || ($entry['source'] ?? 'manual') !== 'cron') {
            break;
        }

        $checked++;

        $cpu_percent = sitepulse_resource_monitor_calculate_cpu_usage_percent($entry);
        if (!empty($thresholds['cpu']) && $cpu_percent !== null && $cpu_percent >= $thresholds['cpu']) {
            $cpu_streak++;
        } else {
            $cpu_streak = 0;
        }

        $memory_percent = sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null);
        if (!empty($thresholds['memory']) && $memory_percent !== null && $memory_percent >= $thresholds['memory']) {
            $memory_streak++;
        } else {
            $memory_streak = 0;
        }

        $disk_percent_used = sitepulse_resource_monitor_calculate_disk_usage_percent($entry);
        if (!empty($thresholds['disk']) && $disk_percent_used !== null && $disk_percent_used >= $thresholds['disk']) {
            $disk_streak++;
        } else {
            $disk_streak = 0;
        }

        if ($checked >= $required) {
            break;
        }
    }

    if ($checked < $required) {
        return;
    }

    if (!empty($thresholds['cpu']) && $cpu_streak >= $required && $latest_cpu_percent !== null) {
        sitepulse_resource_monitor_dispatch_threshold_alert('cpu', $thresholds['cpu'], $latest_cpu_percent, $required, $snapshot);
    }

    if (!empty($thresholds['memory']) && $memory_streak >= $required && $latest_memory_percent !== null) {
        sitepulse_resource_monitor_dispatch_threshold_alert('memory', $thresholds['memory'], $latest_memory_percent, $required, $snapshot);
    }

    if (!empty($thresholds['disk']) && $disk_streak >= $required && $latest_disk_percent !== null) {
        sitepulse_resource_monitor_dispatch_threshold_alert('disk', $thresholds['disk'], $latest_disk_percent, $required, $snapshot);
    }

    if (function_exists('sitepulse_http_monitor_check_thresholds')) {
        sitepulse_http_monitor_check_thresholds();
    }
}

/**
 * Sends an alert via the configured channels and fires integration hooks.
 *
 * @param string $metric           Metric identifier (cpu, memory, disk).
 * @param int    $threshold        Configured threshold percentage.
 * @param float  $current_percent  Latest measured percentage.
 * @param int    $streak           Number of consecutive snapshots considered.
 * @param array  $snapshot         Snapshot payload captured by the cron.
 * @return void
 */
function sitepulse_resource_monitor_dispatch_threshold_alert($metric, $threshold, $current_percent, $streak, array $snapshot) {
    $metric_labels = [
        'cpu'    => __('charge CPU', 'sitepulse'),
        'memory' => __('mémoire utilisée', 'sitepulse'),
        'disk'   => __('stockage utilisé', 'sitepulse'),
    ];

    $metric_key = isset($metric_labels[$metric]) ? $metric_labels[$metric] : $metric;

    $site_name = get_bloginfo('name');
    $site_name = trim(wp_strip_all_tags((string) $site_name));

    if ($site_name === '') {
        $site_name = home_url('/');
    }

    $subject = sprintf(
        __('SitePulse : %1$s au-delà du seuil sur %2$s', 'sitepulse'),
        $metric_key,
        $site_name
    );

    $formatted_percent = number_format_i18n((float) $current_percent, 1);
    $message = sprintf(
        esc_html__('Les %1$d derniers relevés automatiques affichent %2$s ≥ %3$d %% (dernier relevé : %4$s %%).', 'sitepulse'),
        (int) $streak,
        $metric_key,
        (int) $threshold,
        $formatted_percent
    );

    $extra = [
        'metric'          => $metric,
        'threshold'       => (int) $threshold,
        'current_percent' => (float) $current_percent,
        'streak'          => (int) $streak,
        'snapshot'        => $snapshot,
    ];

    if (function_exists('sitepulse_error_alert_send')) {
        sitepulse_error_alert_send('resource_monitor_' . $metric, $subject, $message, 'warning', $extra);
    }

    if (function_exists('do_action')) {
        do_action('sitepulse_resource_monitor_threshold_exceeded', $metric, $threshold, $current_percent, $streak, $snapshot, $extra);
    }
}

/**
 * Normalises numerical values before exporting them.
 *
 * @param mixed $value Raw value.
 * @param int   $decimals Number of decimals to keep.
 * @return string
 */
function sitepulse_resource_monitor_format_export_number($value, $decimals = 2) {
    if (!is_numeric($value)) {
        return '';
    }

    return number_format((float) $value, $decimals, '.', '');
}

/**
 * Prepares normalized history rows for export.
 *
 * @param array<int, array> $history_entries History entries.
 * @return array<int, array<string, mixed>>
 */
function sitepulse_resource_monitor_prepare_export_rows(array $history_entries) {
    $rows = [];

    foreach ($history_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $load = isset($entry['load']) && is_array($entry['load']) ? array_values($entry['load']) : [];
        $cpu_percent = sitepulse_resource_monitor_calculate_cpu_usage_percent($entry);
        $memory_percent = sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null);
        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage($entry['disk']['free'] ?? null, $entry['disk']['total'] ?? null);
        $disk_percent_used = $disk_percent_free !== null ? max(0, min(100, 100 - $disk_percent_free)) : null;

        $rows[] = [
            'timestamp'           => $timestamp,
            'datetime_utc'        => $timestamp > 0 ? gmdate('c', $timestamp) : '',
            'source'              => isset($entry['source']) ? (string) $entry['source'] : 'manual',
            'load_1'              => isset($load[0]) && is_numeric($load[0]) ? (float) $load[0] : null,
            'load_5'              => isset($load[1]) && is_numeric($load[1]) ? (float) $load[1] : null,
            'load_15'             => isset($load[2]) && is_numeric($load[2]) ? (float) $load[2] : null,
            'cpu_percent'         => $cpu_percent,
            'memory_usage_bytes'  => isset($entry['memory']['usage']) && is_numeric($entry['memory']['usage']) ? (int) $entry['memory']['usage'] : null,
            'memory_limit_bytes'  => isset($entry['memory']['limit']) && is_numeric($entry['memory']['limit']) ? (int) $entry['memory']['limit'] : null,
            'memory_percent'      => $memory_percent,
            'disk_free_bytes'     => isset($entry['disk']['free']) && is_numeric($entry['disk']['free']) ? (int) $entry['disk']['free'] : null,
            'disk_total_bytes'    => isset($entry['disk']['total']) && is_numeric($entry['disk']['total']) ? (int) $entry['disk']['total'] : null,
            'disk_free_percent'   => $disk_percent_free,
            'disk_used_percent'   => $disk_percent_used,
        ];
    }

    return $rows;
}

/**
 * Handles resource history export requests from the admin UI.
 *
 * @return void
 */
function sitepulse_resource_monitor_handle_export() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__('Vous n’avez pas l’autorisation de télécharger cet export.', 'sitepulse'), esc_html__('Accès refusé', 'sitepulse'), ['response' => 403]);
    }

    check_admin_referer(SITEPULSE_NONCE_ACTION_RESOURCE_MONITOR_EXPORT);

    $format = isset($_REQUEST['format']) ? sanitize_key(wp_unslash($_REQUEST['format'])) : 'csv';

    $max_rows = sitepulse_resource_monitor_get_export_max_rows();

    $history_result = sitepulse_resource_monitor_get_history([
        'per_page' => $max_rows > 0 ? $max_rows : 0,
        'page'     => 1,
        'order'    => 'ASC',
    ]);

    $history_entries = isset($history_result['entries']) && is_array($history_result['entries'])
        ? $history_result['entries']
        : [];

    if ($max_rows > 0 && count($history_entries) > $max_rows) {
        $history_entries = array_slice($history_entries, 0, $max_rows);
    }

    $rows = sitepulse_resource_monitor_prepare_export_rows($history_entries);

    if (!function_exists('nocache_headers')) {
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    } else {
        nocache_headers();
    }

    $filename_base = 'sitepulse-resource-monitor-' . gmdate('Y-m-d-H-i-s');

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename_base . '.json');
        echo wp_json_encode($rows);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename_base . '.csv');

    $output = fopen('php://output', 'w');

    if ($output === false) {
        wp_die(esc_html__('Impossible de générer le fichier CSV.', 'sitepulse'));
    }

    $headers = [
        'timestamp',
        'datetime_utc',
        'source',
        'load_1',
        'load_5',
        'load_15',
        'cpu_percent',
        'memory_usage_bytes',
        'memory_limit_bytes',
        'memory_percent',
        'disk_free_bytes',
        'disk_total_bytes',
        'disk_free_percent',
        'disk_used_percent',
    ];

    fputcsv($output, $headers);

    foreach ($rows as $row) {
        $csv_row = [
            $row['timestamp'],
            $row['datetime_utc'],
            $row['source'],
            sitepulse_resource_monitor_format_export_number($row['load_1']),
            sitepulse_resource_monitor_format_export_number($row['load_5']),
            sitepulse_resource_monitor_format_export_number($row['load_15']),
            sitepulse_resource_monitor_format_export_number($row['cpu_percent']),
            isset($row['memory_usage_bytes']) ? $row['memory_usage_bytes'] : '',
            isset($row['memory_limit_bytes']) ? $row['memory_limit_bytes'] : '',
            sitepulse_resource_monitor_format_export_number($row['memory_percent']),
            isset($row['disk_free_bytes']) ? $row['disk_free_bytes'] : '',
            isset($row['disk_total_bytes']) ? $row['disk_total_bytes'] : '',
            sitepulse_resource_monitor_format_export_number($row['disk_free_percent']),
            sitepulse_resource_monitor_format_export_number($row['disk_used_percent']),
        ];

        fputcsv($output, $csv_row);
    }

    fclose($output);
    exit;
}

add_action('admin_post_sitepulse_resource_monitor_export', 'sitepulse_resource_monitor_handle_export');
