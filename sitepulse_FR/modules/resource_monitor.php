<?php
if (!defined('ABSPATH')) exit;

if (!defined('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY')) {
    define('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY', 'sitepulse_resource_monitor_history');
}

if (!defined('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY_LOCK')) {
    define('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY_LOCK', 'sitepulse_resource_monitor_history_lock');
}

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

/**
 * Ensures the resource monitor datastore is ready.
 *
 * @return void
 */
function sitepulse_resource_monitor_bootstrap_storage() {
    sitepulse_resource_monitor_maybe_upgrade_schema();
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

    $formats = [
        'recorded_at'  => '%d',
        'load_1'       => '%f',
        'load_5'       => '%f',
        'load_15'      => '%f',
        'memory_usage' => '%d',
        'memory_limit' => '%d',
        'disk_free'    => '%d',
        'disk_total'   => '%d',
        'source'       => '%s',
        'created_at'   => '%s',
    ];

    foreach ($data as $column => $value) {
        if ($value === null) {
            unset($data[$column], $formats[$column]);
        }
    }

    $wpdb->insert($table, $data, array_values($formats));

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

    $history_query = sitepulse_resource_monitor_get_history([
        'per_page' => $per_page,
        'page'     => $page,
        'since'    => $since_timestamp,
        'order'    => 'ASC',
    ]);

    $history_entries = isset($history_query['entries']) && is_array($history_query['entries'])
        ? $history_query['entries']
        : [];

    $returned_count = count($history_entries);

    $history_summary = sitepulse_resource_monitor_calculate_history_summary($history_entries);
    $history_summary_text = sitepulse_resource_monitor_format_history_summary($history_summary);

    $history_prepared = sitepulse_resource_monitor_prepare_history_for_rest($history_entries);

    $latest_entry = !empty($history_prepared)
        ? $history_prepared[count($history_prepared) - 1]
        : null;

    $last_cron_overall = sitepulse_resource_monitor_get_last_cron_timestamp();
    $last_cron_included = sitepulse_resource_monitor_get_last_cron_timestamp($history_entries);

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
        ],
        'history'      => [
            'total_available'      => isset($history_query['total']) ? (int) $history_query['total'] : $returned_count,
            'filtered_count'       => isset($history_query['filtered']) ? (int) $history_query['filtered'] : $returned_count,
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

    $export_endpoint = admin_url('admin-post.php');
    $export_csv_url = wp_nonce_url(add_query_arg([
        'action' => 'sitepulse_resource_monitor_export',
        'format' => 'csv',
    ], $export_endpoint), SITEPULSE_NONCE_ACTION_RESOURCE_MONITOR_EXPORT);
    $export_json_url = wp_nonce_url(add_query_arg([
        'action' => 'sitepulse_resource_monitor_export',
        'format' => 'json',
    ], $export_endpoint), SITEPULSE_NONCE_ACTION_RESOURCE_MONITOR_EXPORT);

    wp_localize_script(
        'sitepulse-resource-monitor',
        'SitePulseResourceMonitor',
        [
            'history' => $history_for_js,
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
            'snapshot' => [
                'generatedAt' => isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : null,
                'source'      => isset($snapshot['source']) ? (string) $snapshot['source'] : 'manual',
            ],
            'lastAutomaticTimestamp' => $last_cron_timestamp,
            'locale' => get_user_locale(),
            'dateFormat' => get_option('date_format'),
            'timeFormat' => get_option('time_format'),
            'i18n' => [
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
            ],
            'refreshFeedback' => $refresh_feedback,
            'refreshStatusId' => 'sitepulse-resource-refresh-status',
        ]
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

    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-resources');
    }
    ?>
    <div class="wrap sitepulse-resource-monitor">
        <h1><span class="dashicons-before dashicons-performance"></span> <?php esc_html_e('Moniteur de Ressources', 'sitepulse'); ?></h1>
        <?php if (!empty($resource_monitor_notices)) : ?>
            <?php foreach ($resource_monitor_notices as $notice) : ?>
                <?php
                $type = isset($notice['type']) ? (string) $notice['type'] : 'warning';
                $allowed_types = ['error', 'warning', 'info', 'success'];
                if (!in_array($type, $allowed_types, true)) {
                    $type = 'warning';
                }

                $message = isset($notice['message']) ? $notice['message'] : '';
                if ($message === '') {
                    continue;
                }
                ?>
                <div class="<?php echo esc_attr('notice notice-' . $type); ?>"><p><?php echo esc_html($message); ?></p></div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="sitepulse-resource-grid">
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Charge CPU (1/5/15 min)', 'sitepulse'); ?></h2>
                <?php
                $load_display_output = isset($snapshot['load']) && is_array($snapshot['load'])
                    ? sitepulse_resource_monitor_format_load_display($snapshot['load'])
                    : (string) $snapshot['load_display'];
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
                        echo esc_html((string) $snapshot['memory_usage']);
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
                            esc_html((string) $snapshot['memory_usage']),
                            esc_html($memory_limit_label)
                        );
                    } else {
                        printf(
                            /* translators: %s: memory used. */
                            esc_html__('Utilisation : %s', 'sitepulse'),
                            esc_html((string) $snapshot['memory_usage'])
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
                        echo esc_html((string) $snapshot['disk_used']);
                    }
                    ?>
                </p>
                <p class="sitepulse-resource-subvalue">
                    <?php
                    printf(
                        /* translators: 1: used disk, 2: free disk, 3: total disk. */
                        esc_html__('Utilisé : %1$s — Libre : %2$s (Total : %3$s)', 'sitepulse'),
                        esc_html((string) $snapshot['disk_used']),
                        esc_html((string) $snapshot['disk_free']),
                        esc_html((string) $snapshot['disk_total'])
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
        <div class="sitepulse-resource-history" id="sitepulse-resource-history">
            <h2><?php esc_html_e('Historique des ressources', 'sitepulse'); ?></h2>
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
 * Removes the stored history.
 *
 * @return void
 */
function sitepulse_resource_monitor_clear_history() {
    delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);

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
