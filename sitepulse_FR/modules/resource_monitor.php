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

require_once __DIR__ . '/resource-monitor/rest.php';

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

require_once __DIR__ . '/resource-monitor/analytics.php';
require_once __DIR__ . '/resource-monitor/reports.php';
require_once __DIR__ . '/resource-monitor/snapshot.php';
require_once __DIR__ . '/resource-monitor/page.php';
require_once __DIR__ . '/resource-monitor/history.php';
require_once __DIR__ . '/resource-monitor/cron.php';
require_once __DIR__ . '/resource-monitor/export.php';

add_action('admin_post_sitepulse_resource_monitor_export', 'sitepulse_resource_monitor_handle_export');
