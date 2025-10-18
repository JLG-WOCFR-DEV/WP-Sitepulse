<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTTP monitor instrumentation and persistence helpers.
 */

/**
 * Registers the hooks required for the outbound HTTP monitor.
 *
 * @return void
 */
function sitepulse_http_monitor_bootstrap() {
    if (!sitepulse_http_monitor_is_enabled()) {
        return;
    }

    add_action('http_api_debug', 'sitepulse_http_monitor_capture_event', 10, 5);
    add_action('shutdown', 'sitepulse_http_monitor_flush_buffer');
    add_action('init', 'sitepulse_http_monitor_schedule_cleanup');

    if (defined('SITEPULSE_CRON_HTTP_MONITOR_CLEANUP')) {
        add_action(SITEPULSE_CRON_HTTP_MONITOR_CLEANUP, 'sitepulse_http_monitor_purge_old_entries');
    }
}

/**
 * Ensures the datastore schema is up to date.
 *
 * @return void
 */
function sitepulse_http_monitor_bootstrap_storage() {
    sitepulse_http_monitor_maybe_upgrade_schema();
}

/**
 * Determines whether the HTTP monitor should collect telemetry.
 *
 * @return bool
 */
function sitepulse_http_monitor_is_enabled() {
    if (!function_exists('sitepulse_is_module_active')) {
        return false;
    }

    $enabled = sitepulse_is_module_active('resource_monitor');

    /**
     * Filters the activation state of the HTTP monitor.
     *
     * @param bool $enabled Whether the monitor is enabled.
     */
    return (bool) apply_filters('sitepulse_http_monitor_enabled', $enabled);
}

/**
 * Handles the http_api_debug hook and buffers outbound call metrics.
 *
 * @param mixed       $response Response or WP_Error.
 * @param string      $type     Request lifecycle step (response, request, transport_debug, etc.).
 * @param string      $class    HTTP transport class.
 * @param array       $args     Arguments passed to wp_remote_request().
 * @param string|null $url      Target URL.
 * @return void
 */
function sitepulse_http_monitor_capture_event($response, $type, $class, $args, $url) {
    if (!in_array($type, ['request', 'response', 'error'], true)) {
        return;
    }

    $key = sitepulse_http_monitor_build_request_key($url, $args);

    if ($type === 'request') {
        if (sitepulse_http_monitor_should_ignore($url, $args)) {
            sitepulse_http_monitor_request_context('set', $key, ['ignored' => true]);

            return;
        }

        sitepulse_http_monitor_request_context('set', $key, [
            'started_at' => microtime(true),
            'method'     => isset($args['method']) ? strtoupper((string) $args['method']) : 'GET',
            'url'        => (string) $url,
            'transport'  => (string) $class,
            'args'       => is_array($args) ? $args : [],
        ]);

        return;
    }

    $payload = sitepulse_http_monitor_request_context('get', $key);

    if (!is_array($payload) || !empty($payload['ignored'])) {
        sitepulse_http_monitor_request_context('forget', $key);

        return;
    }

    $event = sitepulse_http_monitor_normalize_event($payload, $response, $type);
    sitepulse_http_monitor_request_context('forget', $key);

    if (empty($event)) {
        return;
    }

    sitepulse_http_monitor_buffer('add', $event);

    if (sitepulse_http_monitor_buffer('count') >= 10) {
        sitepulse_http_monitor_flush_buffer();
    }
}

/**
 * Generates a deterministic cache key for a tracked HTTP request.
 *
 * @param string|null $url  Target URL.
 * @param array       $args Request arguments.
 * @return string
 */
function sitepulse_http_monitor_build_request_key($url, $args) {
    $method = isset($args['method']) ? strtoupper((string) $args['method']) : 'GET';
    $timeout = isset($args['timeout']) ? (string) $args['timeout'] : '';
    $signature = wp_json_encode($args);

    if (!is_string($signature)) {
        $signature = serialize($args);
    }

    return md5($method . '|' . (string) $url . '|' . $timeout . '|' . $signature);
}

/**
 * Stores or retrieves request context metadata.
 *
 * @param string      $action Action type (set, get, forget, clear).
 * @param string|null $key    Context key.
 * @param mixed       $value  Value to store when action is set.
 * @return mixed
 */
function sitepulse_http_monitor_request_context($action, $key = null, $value = null) {
    static $map = [];

    switch ($action) {
        case 'set':
            if ($key !== null) {
                $map[$key] = $value;
            }

            return null;
        case 'get':
            if ($key === null) {
                return null;
            }

            return $map[$key] ?? null;
        case 'forget':
            if ($key === null) {
                return null;
            }

            $existing = $map[$key] ?? null;
            unset($map[$key]);

            return $existing;
        case 'clear':
            $map = [];

            return null;
    }

    return null;
}

/**
 * Buffers events prior to database persistence.
 *
 * @param string     $action Action to execute (add, drain, count).
 * @param array|null $event  Event payload when action is add.
 * @return mixed
 */
function sitepulse_http_monitor_buffer($action, $event = null) {
    static $buffer = [];

    if ($action === 'add' && is_array($event)) {
        $buffer[] = $event;

        return null;
    }

    if ($action === 'drain') {
        $events = $buffer;
        $buffer = [];

        return $events;
    }

    if ($action === 'count') {
        return count($buffer);
    }

    return null;
}

/**
 * Determines whether a specific outbound call should be ignored.
 *
 * @param string|null $url  Target URL.
 * @param array       $args Request arguments.
 * @return bool
 */
function sitepulse_http_monitor_should_ignore($url, $args) {
    if (empty($url) || !is_string($url)) {
        return true;
    }

    if (!is_array($args)) {
        $args = [];
    }

    if (!empty($args['sitepulse_disable_http_monitor'])) {
        return true;
    }

    if (isset($args['headers']) && is_array($args['headers'])) {
        foreach ($args['headers'] as $header_key => $header_value) {
            if (is_string($header_key) && strtolower($header_key) === 'x-sitepulse-ignore') {
                return true;
            }
        }
    }

    $parsed = wp_parse_url($url);

    if (!is_array($parsed) || empty($parsed['host'])) {
        return true;
    }

    $host = strtolower($parsed['host']);
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);

    if (is_string($site_host) && strtolower($site_host) === $host) {
        return true;
    }

    $default_ignored_hosts = ['localhost', '127.0.0.1'];
    $ignored_hosts = (array) apply_filters('sitepulse_http_monitor_ignored_hosts', $default_ignored_hosts, $url, $args);

    if (in_array($host, $ignored_hosts, true)) {
        return true;
    }

    /**
     * Filters whether a specific HTTP request should be ignored by the monitor.
     *
     * @param bool   $ignore Whether to ignore the call.
     * @param string $url    Target URL.
     * @param array  $args   Request arguments.
     */
    $should_ignore = apply_filters('sitepulse_http_monitor_should_ignore', false, $url, $args);

    return (bool) $should_ignore;
}

/**
 * Normalises an outbound HTTP call into a database-ready payload.
 *
 * @param array  $payload  Request metadata captured before execution.
 * @param mixed  $response HTTP API response payload or WP_Error.
 * @param string $type     Lifecycle step that triggered the callback.
 * @return array<string, mixed>
 */
function sitepulse_http_monitor_normalize_event(array $payload, $response, $type) {
    $started_at = isset($payload['started_at']) ? (float) $payload['started_at'] : microtime(true);
    $ended_at = microtime(true);
    $duration_ms = max(0, (int) round(($ended_at - $started_at) * 1000));

    $requested_at = (int) current_time('timestamp', true);
    $method = isset($payload['method']) ? strtoupper((string) $payload['method']) : 'GET';
    $url = isset($payload['url']) ? (string) $payload['url'] : '';
    $transport = isset($payload['transport']) ? (string) $payload['transport'] : '';

    $parsed = wp_parse_url($url);
    $host = isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
    $path = isset($parsed['path']) ? $parsed['path'] : '/';

    if (isset($parsed['query']) && $parsed['query'] !== '') {
        $path .= '?' . $parsed['query'];
    }

    if ($path === '') {
        $path = '/';
    }

    $status_code = null;
    $error_code = null;
    $error_message = null;

    if ($response instanceof WP_Error) {
        $error_code = $response->get_error_code();
        $error_message = $response->get_error_message();
    } elseif (is_array($response)) {
        if (isset($response['response']['code'])) {
            $status_code = (int) $response['response']['code'];
        }

        if (isset($response['response']['message']) && $response['response']['message']) {
            $error_message = (string) $response['response']['message'];
        }

        if ($status_code && $status_code >= 400 && empty($error_message)) {
            $error_message = isset($response['response']['message'])
                ? (string) $response['response']['message']
                : __('Erreur HTTP', 'sitepulse');
        }
    } elseif (is_object($response) && method_exists($response, 'get_status')) {
        $status_code = (int) $response->get_status();
    }

    if ($type === 'error' && $response instanceof WP_Error) {
        $status_code = null;
    }

    $is_error = false;

    if ($response instanceof WP_Error) {
        $is_error = true;
    } elseif (is_int($status_code) && $status_code >= 400) {
        $is_error = true;
    }

    $response_bytes = null;

    if (is_array($response)) {
        if (isset($response['headers']['content-length'])) {
            $response_bytes = (int) $response['headers']['content-length'];
        } elseif (isset($response['body']) && is_string($response['body'])) {
            $response_bytes = strlen($response['body']);
        }
    }

    return [
        'requested_at'   => $requested_at,
        'method'         => $method,
        'host'           => substr($host, 0, 191),
        'path'           => substr($path, 0, 191),
        'status_code'    => $status_code,
        'duration_ms'    => $duration_ms,
        'transport'      => substr($transport, 0, 191),
        'is_error'       => $is_error ? 1 : 0,
        'error_code'     => $error_code ? substr((string) $error_code, 0, 64) : null,
        'error_message'  => $error_message ? substr((string) $error_message, 0, 255) : null,
        'response_bytes' => $response_bytes,
    ];
}

/**
 * Flushes buffered events to the database.
 *
 * @return void
 */
function sitepulse_http_monitor_flush_buffer() {
    $events = sitepulse_http_monitor_buffer('drain');

    if (empty($events) || !is_array($events)) {
        return;
    }

    $table = sitepulse_http_monitor_get_table_name();

    if ($table === '') {
        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $created_at = gmdate('Y-m-d H:i:s');

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $data = [
            'requested_at' => isset($event['requested_at']) ? (int) $event['requested_at'] : (int) current_time('timestamp', true),
            'method'       => isset($event['method']) ? (string) $event['method'] : 'GET',
            'host'         => isset($event['host']) ? (string) $event['host'] : '',
            'path'         => isset($event['path']) ? (string) $event['path'] : '/',
            'duration_ms'  => isset($event['duration_ms']) ? max(0, (int) $event['duration_ms']) : 0,
            'transport'    => isset($event['transport']) ? (string) $event['transport'] : '',
            'is_error'     => !empty($event['is_error']) ? 1 : 0,
            'created_at'   => $created_at,
        ];

        $format = ['%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s'];

        if (isset($event['status_code']) && $event['status_code'] !== null) {
            $data['status_code'] = (int) $event['status_code'];
            $format[] = '%d';
        }

        if (!empty($event['error_code'])) {
            $data['error_code'] = (string) $event['error_code'];
            $format[] = '%s';
        }

        if (!empty($event['error_message'])) {
            $data['error_message'] = (string) $event['error_message'];
            $format[] = '%s';
        }

        if (isset($event['response_bytes']) && $event['response_bytes'] !== null) {
            $data['response_bytes'] = (int) $event['response_bytes'];
            $format[] = '%d';
        }

        $wpdb->insert($table, $data, $format);
    }
}

/**
 * Returns the fully qualified HTTP monitor table name.
 *
 * @return string
 */
function sitepulse_http_monitor_get_table_name() {
    if (!defined('SITEPULSE_TABLE_HTTP_MONITOR_EVENTS')) {
        return '';
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return '';
    }

    return $wpdb->prefix . SITEPULSE_TABLE_HTTP_MONITOR_EVENTS;
}

/**
 * Creates or upgrades the HTTP monitor schema.
 *
 * @return void
 */
function sitepulse_http_monitor_maybe_upgrade_schema() {
    if (!defined('SITEPULSE_HTTP_MONITOR_SCHEMA_VERSION')
        || !defined('SITEPULSE_OPTION_HTTP_MONITOR_SCHEMA_VERSION')) {
        return;
    }

    $target = (int) SITEPULSE_HTTP_MONITOR_SCHEMA_VERSION;
    $current = (int) get_option(SITEPULSE_OPTION_HTTP_MONITOR_SCHEMA_VERSION, 0);

    if ($current >= $target && sitepulse_http_monitor_table_exists()) {
        return;
    }

    sitepulse_http_monitor_install_table();

    if ($current < $target) {
        update_option(SITEPULSE_OPTION_HTTP_MONITOR_SCHEMA_VERSION, $target);
    }
}

/**
 * Checks whether the HTTP monitor table exists.
 *
 * @return bool
 */
function sitepulse_http_monitor_table_exists() {
    $table = sitepulse_http_monitor_get_table_name();

    if ($table === '') {
        return false;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return false;
    }

    $result = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return !empty($result);
}

/**
 * Installs the HTTP monitor events table.
 *
 * @return void
 */
function sitepulse_http_monitor_install_table() {
    $table = sitepulse_http_monitor_get_table_name();

    if ($table === '') {
        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        requested_at int(10) unsigned NOT NULL,
        method varchar(16) NOT NULL DEFAULT 'GET',
        host varchar(191) NOT NULL DEFAULT '',
        path varchar(191) NOT NULL DEFAULT '/',
        status_code smallint(6) NULL,
        duration_ms int(10) unsigned NULL,
        transport varchar(191) NOT NULL DEFAULT '',
        is_error tinyint(1) NOT NULL DEFAULT 0,
        error_code varchar(64) NULL,
        error_message varchar(255) NULL,
        response_bytes bigint(20) unsigned NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY requested_at (requested_at),
        KEY host (host),
        KEY status_code (status_code),
        KEY is_error (is_error)
    ) {$charset};";

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta($sql);
}

/**
 * Schedules the daily cleanup cron event if needed.
 *
 * @return void
 */
function sitepulse_http_monitor_schedule_cleanup() {
    if (!defined('SITEPULSE_CRON_HTTP_MONITOR_CLEANUP')) {
        return;
    }

    if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }

    if (!wp_next_scheduled(SITEPULSE_CRON_HTTP_MONITOR_CLEANUP)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', SITEPULSE_CRON_HTTP_MONITOR_CLEANUP);
    }
}

/**
 * Handles updates to the HTTP monitor threshold and retention settings.
 *
 * @return void
 */
function sitepulse_http_monitor_handle_settings() {
    if (!function_exists('sitepulse_get_capability') || !current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour modifier cette configuration.", 'sitepulse'));
    }

    $nonce_action = defined('SITEPULSE_NONCE_ACTION_HTTP_MONITOR_SETTINGS')
        ? SITEPULSE_NONCE_ACTION_HTTP_MONITOR_SETTINGS
        : 'sitepulse_http_monitor_settings';

    check_admin_referer($nonce_action);

    $latency_raw = isset($_POST['sitepulse_http_latency_threshold'])
        ? wp_unslash($_POST['sitepulse_http_latency_threshold'])
        : '';
    $error_raw = isset($_POST['sitepulse_http_error_rate'])
        ? wp_unslash($_POST['sitepulse_http_error_rate'])
        : '';
    $retention_raw = isset($_POST['sitepulse_http_retention_days'])
        ? wp_unslash($_POST['sitepulse_http_retention_days'])
        : '';

    $latency_threshold = max(0, absint($latency_raw));
    $error_threshold = $error_raw === '' ? 0 : (float) $error_raw;
    $error_threshold = (int) round(min(100, max(0, $error_threshold)));
    $retention_days = absint($retention_raw);

    update_option(SITEPULSE_OPTION_HTTP_MONITOR_LATENCY_THRESHOLD_MS, $latency_threshold, false);
    update_option(SITEPULSE_OPTION_HTTP_MONITOR_ERROR_RATE_THRESHOLD, $error_threshold, false);

    if ($retention_days >= 1) {
        update_option(SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS, $retention_days, false);
    }

    $redirect = admin_url('admin.php?page=sitepulse-resources');
    $redirect = add_query_arg('sitepulse_http_monitor', 'updated', $redirect);

    wp_safe_redirect($redirect);
    exit;
}

/**
 * Deletes HTTP monitor events older than the configured retention window.
 *
 * @return void
 */
function sitepulse_http_monitor_purge_old_entries() {
    if (!defined('SITEPULSE_TRANSIENT_HTTP_MONITOR_CLEANUP_LOCK')) {
        return;
    }

    $lock = get_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_CLEANUP_LOCK);

    if ($lock) {
        return;
    }

    set_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_CLEANUP_LOCK, 1, MINUTE_IN_SECONDS * 5);

    $retention_days = (int) get_option(
        SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS,
        defined('SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS') ? (int) SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS : 14
    );

    if ($retention_days <= 0) {
        delete_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_CLEANUP_LOCK);

        return;
    }

    $threshold = (int) current_time('timestamp', true) - ($retention_days * DAY_IN_SECONDS);
    $table = sitepulse_http_monitor_get_table_name();

    if ($table !== '') {
        global $wpdb;

        if ($wpdb instanceof wpdb) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE requested_at < %d", $threshold));
        }
    }

    delete_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_CLEANUP_LOCK);
}

/**
 * Prepares a SQL query with optional parameters.
 *
 * @param string $sql    SQL statement.
 * @param array  $params Parameters to interpolate.
 * @return string|null
 */
function sitepulse_http_monitor_prepare_sql($sql, array $params) {
    global $wpdb;

    if (empty($params)) {
        return $sql;
    }

    if (!($wpdb instanceof wpdb)) {
        return null;
    }

    return $wpdb->prepare($sql, $params);
}

/**
 * Retrieves aggregated statistics for outbound HTTP calls.
 *
 * @param array $args Query arguments.
 * @return array<string, mixed>
 */
function sitepulse_http_monitor_get_stats(array $args = []) {
    $defaults = [
        'since' => null,
        'limit' => 25,
    ];

    $args = array_merge($defaults, $args);
    $table = sitepulse_http_monitor_get_table_name();

    if ($table === '') {
        return [
            'summary'   => [],
            'services'  => [],
            'samples'   => [],
            'thresholds' => sitepulse_http_monitor_get_threshold_configuration(),
        ];
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return [
            'summary'   => [],
            'services'  => [],
            'samples'   => [],
            'thresholds' => sitepulse_http_monitor_get_threshold_configuration(),
        ];
    }

    $where = '1=1';
    $params = [];

    if (!empty($args['since'])) {
        $since = is_numeric($args['since']) ? (int) $args['since'] : strtotime((string) $args['since']);
        if ($since) {
            $where .= ' AND requested_at >= %d';
            $params[] = $since;
        }
    }

    $summary_sql = "SELECT COUNT(*) as total, SUM(is_error) as errors, AVG(duration_ms) as average_duration, MAX(duration_ms) as max_duration
        FROM {$table} WHERE {$where}";

    $summary_query = sitepulse_http_monitor_prepare_sql($summary_sql, $params);
    $summary = $summary_query ? $wpdb->get_row($summary_query, ARRAY_A) : null;

    if (!is_array($summary)) {
        $summary = ['total' => 0, 'errors' => 0, 'average_duration' => null, 'max_duration' => null];
    }

    $percentile = null;

    if (!empty($summary['total'])) {
        $position = (int) ceil((int) $summary['total'] * 0.95);
        $percentile_sql = "SELECT duration_ms FROM {$table} WHERE {$where} AND duration_ms IS NOT NULL ORDER BY duration_ms ASC LIMIT %d, 1";
        $percentile_params = $params;
        $percentile_params[] = max(0, $position - 1);
        $percentile_query = sitepulse_http_monitor_prepare_sql($percentile_sql, $percentile_params);
        if ($percentile_query) {
            $percentile = $wpdb->get_var($percentile_query);
        }
    }

    $limit = max(1, min(100, (int) $args['limit']));
    $services_sql = "SELECT host, path, method, COUNT(*) as total_requests, SUM(is_error) as error_requests,
        AVG(duration_ms) as average_duration, MAX(duration_ms) as max_duration, MAX(requested_at) as last_seen,
        MAX(status_code) as last_status_code
        FROM {$table}
        WHERE {$where}
        GROUP BY host, path, method
        ORDER BY average_duration DESC
        LIMIT %d";

    $service_params = $params;
    $service_params[] = $limit;
    $services_query = sitepulse_http_monitor_prepare_sql($services_sql, $service_params);
    $services = $services_query ? $wpdb->get_results($services_query, ARRAY_A) : null;

    if (!is_array($services)) {
        $services = [];
    }

    $samples_sql = "SELECT requested_at, host, path, method, status_code, duration_ms, is_error
        FROM {$table}
        WHERE {$where}
        ORDER BY requested_at DESC
        LIMIT %d";

    $sample_params = $params;
    $sample_params[] = min(50, $limit * 2);
    $samples_query = sitepulse_http_monitor_prepare_sql($samples_sql, $sample_params);
    $samples = $samples_query ? $wpdb->get_results($samples_query, ARRAY_A) : null;

    if (!is_array($samples)) {
        $samples = [];
    }

    return [
        'summary' => [
            'total'           => (int) ($summary['total'] ?? 0),
            'errors'          => (int) ($summary['errors'] ?? 0),
            'errorRate'       => sitepulse_http_monitor_calculate_error_rate((int) ($summary['total'] ?? 0), (int) ($summary['errors'] ?? 0)),
            'averageDuration' => isset($summary['average_duration']) ? (float) $summary['average_duration'] : null,
            'maxDuration'     => isset($summary['max_duration']) ? (float) $summary['max_duration'] : null,
            'p95Duration'     => $percentile !== null ? (float) $percentile : null,
        ],
        'services'  => array_map('sitepulse_http_monitor_normalize_service_row', $services),
        'samples'   => array_map('sitepulse_http_monitor_normalize_sample_row', $samples),
        'thresholds' => sitepulse_http_monitor_get_threshold_configuration(),
    ];
}

/**
 * Normalises a service aggregation row.
 *
 * @param array $row Database row.
 * @return array<string, mixed>
 */
function sitepulse_http_monitor_normalize_service_row($row) {
    if (!is_array($row)) {
        return [];
    }

    $total = isset($row['total_requests']) ? (int) $row['total_requests'] : 0;
    $errors = isset($row['error_requests']) ? (int) $row['error_requests'] : 0;

    return [
        'host'          => isset($row['host']) ? (string) $row['host'] : '',
        'path'          => isset($row['path']) ? (string) $row['path'] : '/',
        'method'        => isset($row['method']) ? (string) $row['method'] : 'GET',
        'total'         => $total,
        'errors'        => $errors,
        'errorRate'     => sitepulse_http_monitor_calculate_error_rate($total, $errors),
        'average'       => isset($row['average_duration']) ? (float) $row['average_duration'] : null,
        'max'           => isset($row['max_duration']) ? (float) $row['max_duration'] : null,
        'lastSeen'      => isset($row['last_seen']) ? (int) $row['last_seen'] : null,
        'lastStatus'    => isset($row['last_status_code']) ? (int) $row['last_status_code'] : null,
    ];
}

/**
 * Normalises a single sample row.
 *
 * @param array $row Database row.
 * @return array<string, mixed>
 */
function sitepulse_http_monitor_normalize_sample_row($row) {
    if (!is_array($row)) {
        return [];
    }

    return [
        'timestamp'  => isset($row['requested_at']) ? (int) $row['requested_at'] : null,
        'host'       => isset($row['host']) ? (string) $row['host'] : '',
        'path'       => isset($row['path']) ? (string) $row['path'] : '/',
        'method'     => isset($row['method']) ? (string) $row['method'] : 'GET',
        'status'     => isset($row['status_code']) ? (int) $row['status_code'] : null,
        'duration'   => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
        'isError'    => !empty($row['is_error']),
    ];
}

/**
 * Calculates the error rate percentage.
 *
 * @param int $total  Total requests.
 * @param int $errors Total errors.
 * @return float|null
 */
function sitepulse_http_monitor_calculate_error_rate($total, $errors) {
    if ($total <= 0) {
        return null;
    }

    return round(($errors / $total) * 100, 2);
}

/**
 * Retrieves the configured threshold values for the HTTP monitor.
 *
 * @return array<string, int>
 */
function sitepulse_http_monitor_get_threshold_configuration() {
    $latency = (int) get_option(
        SITEPULSE_OPTION_HTTP_MONITOR_LATENCY_THRESHOLD_MS,
        defined('SITEPULSE_DEFAULT_HTTP_MONITOR_LATENCY_THRESHOLD_MS') ? (int) SITEPULSE_DEFAULT_HTTP_MONITOR_LATENCY_THRESHOLD_MS : 1200
    );

    $error_rate = (int) get_option(
        SITEPULSE_OPTION_HTTP_MONITOR_ERROR_RATE_THRESHOLD,
        defined('SITEPULSE_DEFAULT_HTTP_MONITOR_ERROR_RATE_THRESHOLD') ? (int) SITEPULSE_DEFAULT_HTTP_MONITOR_ERROR_RATE_THRESHOLD : 20
    );

    return [
        'latency'   => $latency,
        'errorRate' => $error_rate,
    ];
}

/**
 * Evaluates thresholds and dispatches alerts if required.
 *
 * @return void
 */
function sitepulse_http_monitor_check_thresholds() {
    if (!function_exists('sitepulse_error_alert_send')) {
        return;
    }

    $stats = sitepulse_http_monitor_get_stats([
        'since' => (int) current_time('timestamp', true) - HOUR_IN_SECONDS,
        'limit' => 10,
    ]);

    if (empty($stats['summary']) || empty($stats['thresholds'])) {
        return;
    }

    $thresholds = $stats['thresholds'];
    $summary = $stats['summary'];

    if (isset($thresholds['latency'], $summary['p95Duration'])
        && $thresholds['latency'] > 0
        && $summary['p95Duration'] !== null
        && $summary['p95Duration'] >= $thresholds['latency']) {
        $subject = __('Latence élevée détectée sur les appels externes', 'sitepulse');
        $message = sprintf(
            /* translators: %s: latency in milliseconds. */
            __('Le 95e percentile de latence a atteint %s ms sur la dernière heure.', 'sitepulse'),
            number_format_i18n((float) $summary['p95Duration'])
        );

        sitepulse_error_alert_send('http_latency', $subject, $message, 'warning', [
            'metric'     => 'latency',
            'threshold'  => (int) $thresholds['latency'],
            'observation'=> (float) $summary['p95Duration'],
        ]);
    }

    if (isset($thresholds['errorRate'], $summary['errorRate'])
        && $thresholds['errorRate'] > 0
        && $summary['errorRate'] !== null
        && $summary['errorRate'] >= $thresholds['errorRate']) {
        $subject = __('Taux d’erreurs élevé sur les appels externes', 'sitepulse');
        $message = sprintf(
            /* translators: %s: error rate percentage. */
            __('Le taux d’erreurs des appels externes a atteint %s%% sur la dernière heure.', 'sitepulse'),
            number_format_i18n((float) $summary['errorRate'], 2)
        );

        sitepulse_error_alert_send('http_errors', $subject, $message, 'error', [
            'metric'     => 'error_rate',
            'threshold'  => (int) $thresholds['errorRate'],
            'observation'=> (float) $summary['errorRate'],
        ]);
    }
}

/**
 * REST API callback returning the HTTP monitor statistics.
 *
 * @param WP_REST_Request $request Current request object.
 * @return WP_REST_Response|array
 */
function sitepulse_http_monitor_rest_stats($request) {
    if (!($request instanceof WP_REST_Request)) {
        return rest_ensure_response(sitepulse_http_monitor_get_stats());
    }

    $limit = $request->get_param('limit');
    $since = $request->get_param('since');

    $stats = sitepulse_http_monitor_get_stats([
        'since' => $since,
        'limit' => is_numeric($limit) ? (int) $limit : 25,
    ]);

    return rest_ensure_response($stats);
}
