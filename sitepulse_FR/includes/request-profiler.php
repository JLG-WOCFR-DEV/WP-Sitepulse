<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sitepulse_request_profiler_get_table_name')) {
    /**
     * Retrieves the fully qualified table name for request traces.
     *
     * @return string
     */
    function sitepulse_request_profiler_get_table_name() {
        if (!defined('SITEPULSE_TABLE_REQUEST_TRACES')) {
            return '';
        }

        global $wpdb;

        if (!($wpdb instanceof wpdb) || !is_string(SITEPULSE_TABLE_REQUEST_TRACES)) {
            return '';
        }

        return $wpdb->prefix . SITEPULSE_TABLE_REQUEST_TRACES;
    }
}

if (!function_exists('sitepulse_request_profiler_table_exists')) {
    /**
     * Checks if the request trace table exists in the database.
     *
     * @param bool $force_refresh When true, forces a cache refresh.
     * @return bool
     */
    function sitepulse_request_profiler_table_exists($force_refresh = false) {
        static $cache = null;

        if ($force_refresh) {
            $cache = null;
        }

        if ($cache !== null) {
            return $cache;
        }

        $table = sitepulse_request_profiler_get_table_name();

        if ($table === '') {
            $cache = false;
            return false;
        }

        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            $cache = false;
            return false;
        }

        $like = $wpdb->esc_like($table);
        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $like);
        $result = $wpdb->get_var($sql);

        $cache = $result !== null;

        return $cache;
    }
}

if (!function_exists('sitepulse_request_profiler_maybe_upgrade_schema')) {
    /**
     * Ensures the request trace table schema is installed and up to date.
     *
     * @return void
     */
    function sitepulse_request_profiler_maybe_upgrade_schema() {
        if (!defined('SITEPULSE_REQUEST_TRACE_SCHEMA_VERSION') || !defined('SITEPULSE_OPTION_REQUEST_TRACE_SCHEMA_VERSION')) {
            return;
        }

        $target_version = (int) SITEPULSE_REQUEST_TRACE_SCHEMA_VERSION;
        $current_version = (int) get_option(SITEPULSE_OPTION_REQUEST_TRACE_SCHEMA_VERSION, 0);

        if ($current_version >= $target_version && sitepulse_request_profiler_table_exists()) {
            return;
        }

        sitepulse_request_profiler_install_table();

        if ($current_version < $target_version) {
            update_option(SITEPULSE_OPTION_REQUEST_TRACE_SCHEMA_VERSION, $target_version);
        }
    }
}

if (!function_exists('sitepulse_request_profiler_install_table')) {
    /**
     * Installs the request trace table via dbDelta.
     *
     * @return void
     */
    function sitepulse_request_profiler_install_table() {
        $table = sitepulse_request_profiler_get_table_name();

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
            recorded_at datetime NOT NULL,
            request_url text NOT NULL,
            request_method varchar(16) NOT NULL DEFAULT 'GET',
            trace_token varchar(64) NOT NULL,
            total_duration decimal(12,6) NOT NULL DEFAULT 0,
            memory_peak bigint(20) unsigned NULL,
            hook_count int unsigned NOT NULL DEFAULT 0,
            query_count int unsigned NOT NULL DEFAULT 0,
            hooks longtext NULL,
            queries longtext NULL,
            context longtext NULL,
            PRIMARY KEY  (id),
            KEY recorded_at (recorded_at),
            KEY trace_token (trace_token)
        ) {$charset_collate};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($sql);

        sitepulse_request_profiler_table_exists(true);
    }
}

if (!function_exists('sitepulse_request_profiler_get_retention_days')) {
    /**
     * Retrieves the retention period (in days) for stored traces.
     *
     * @return int
     */
    function sitepulse_request_profiler_get_retention_days() {
        $default = defined('SITEPULSE_DEFAULT_REQUEST_TRACE_RETENTION_DAYS')
            ? (int) SITEPULSE_DEFAULT_REQUEST_TRACE_RETENTION_DAYS
            : 14;

        if (!defined('SITEPULSE_OPTION_REQUEST_TRACE_RETENTION_DAYS')) {
            return $default;
        }

        $stored = get_option(SITEPULSE_OPTION_REQUEST_TRACE_RETENTION_DAYS, $default);

        if (!is_numeric($stored)) {
            return $default;
        }

        $stored = (int) $stored;

        if ($stored < 1) {
            return $default;
        }

        return min($stored, 365);
    }
}

if (!function_exists('sitepulse_request_profiler_purge_old_entries')) {
    /**
     * Removes traces older than the configured retention period.
     *
     * @return void
     */
    function sitepulse_request_profiler_purge_old_entries() {
        $table = sitepulse_request_profiler_get_table_name();

        if ($table === '') {
            return;
        }

        $retention_days = sitepulse_request_profiler_get_retention_days();

        if ($retention_days < 1) {
            return;
        }

        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS * $retention_days);
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE recorded_at < %s", $cutoff));
    }
}

if (!function_exists('sitepulse_request_profiler_normalize_token')) {
    /**
     * Normalizes a session token for safe storage.
     *
     * @param string $token Raw token.
     * @return string
     */
    function sitepulse_request_profiler_normalize_token($token) {
        $token = is_string($token) ? trim($token) : '';

        if ($token === '') {
            return '';
        }

        return preg_replace('/[^a-zA-Z0-9]/', '', $token);
    }
}

if (!function_exists('sitepulse_request_profiler_transient_key')) {
    /**
     * Builds a transient key for the profiler based on a prefix constant.
     *
     * @param string $suffix
     * @param string $constant_name
     * @return string
     */
    function sitepulse_request_profiler_transient_key($suffix, $constant_name) {
        $prefix = defined($constant_name) ? constant($constant_name) : '';

        if (!is_string($prefix) || $prefix === '') {
            $prefix = $constant_name === 'SITEPULSE_TRANSIENT_REQUEST_TRACE_RESULT_PREFIX'
                ? 'sitepulse_request_trace_result_'
                : 'sitepulse_request_trace_session_';
        }

        return $prefix . $suffix;
    }
}

if (!function_exists('sitepulse_request_profiler_sanitize_target_url')) {
    /**
     * Sanitizes and validates a target URL for profiling.
     *
     * @param string $url Target URL.
     * @return string Sanitized absolute URL or empty string on failure.
     */
    function sitepulse_request_profiler_sanitize_target_url($url) {
        $url = is_string($url) ? trim($url) : '';

        if ($url === '') {
            return '';
        }

        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            if ($url[0] !== '/') {
                $url = '/' . $url;
            }

            $url = home_url($url);
        }

        $url = esc_url_raw($url);

        if ($url === '') {
            return '';
        }

        $target_parts = wp_parse_url($url);
        $site_parts = wp_parse_url(home_url('/'));

        if (!is_array($target_parts) || !is_array($site_parts)) {
            return '';
        }

        $target_host = isset($target_parts['host']) ? strtolower($target_parts['host']) : '';
        $site_host = isset($site_parts['host']) ? strtolower($site_parts['host']) : '';

        if ($target_host !== '' && $site_host !== '' && $target_host !== $site_host) {
            return '';
        }

        return $url;
    }
}

if (!function_exists('sitepulse_request_profiler_create_session')) {
    /**
     * Creates a profiler session for the current user.
     *
     * @param int    $user_id   Current user ID.
     * @param string $target_url URL that will be profiled.
     * @return array{token:string,target:string}|null
     */
    function sitepulse_request_profiler_create_session($user_id, $target_url) {
        $user_id = (int) $user_id;
        $target_url = sitepulse_request_profiler_sanitize_target_url($target_url);

        if ($user_id <= 0 || $target_url === '') {
            return null;
        }

        $token = wp_generate_password(20, false, false);
        $token = sitepulse_request_profiler_normalize_token($token);

        if ($token === '') {
            return null;
        }

        $session = [
            'user_id'   => $user_id,
            'target'    => $target_url,
            'created'   => time(),
            'status'    => 'pending',
        ];

        $session_key = sitepulse_request_profiler_transient_key($token, 'SITEPULSE_TRANSIENT_REQUEST_TRACE_SESSION_PREFIX');
        $result_key = sitepulse_request_profiler_transient_key($token, 'SITEPULSE_TRANSIENT_REQUEST_TRACE_RESULT_PREFIX');

        set_transient($session_key, $session, 10 * MINUTE_IN_SECONDS);
        set_transient($result_key, [
            'user_id' => $user_id,
            'status'  => 'pending',
            'created' => time(),
        ], 10 * MINUTE_IN_SECONDS);

        return [
            'token'  => $token,
            'target' => $target_url,
        ];
    }
}

if (!function_exists('sitepulse_request_profiler_get_session')) {
    /**
     * Retrieves a profiler session by token.
     *
     * @param string $token Session token.
     * @return array|null
     */
    function sitepulse_request_profiler_get_session($token) {
        $token = sitepulse_request_profiler_normalize_token($token);

        if ($token === '') {
            return null;
        }

        $session_key = sitepulse_request_profiler_transient_key($token, 'SITEPULSE_TRANSIENT_REQUEST_TRACE_SESSION_PREFIX');
        $session = get_transient($session_key);

        if (!is_array($session) || empty($session['user_id'])) {
            return null;
        }

        return $session;
    }
}

if (!function_exists('sitepulse_request_profiler_get_result')) {
    /**
     * Retrieves a profiler result placeholder or final data by token.
     *
     * @param string $token
     * @return array|null
     */
    function sitepulse_request_profiler_get_result($token) {
        $token = sitepulse_request_profiler_normalize_token($token);

        if ($token === '') {
            return null;
        }

        $result_key = sitepulse_request_profiler_transient_key($token, 'SITEPULSE_TRANSIENT_REQUEST_TRACE_RESULT_PREFIX');
        $result = get_transient($result_key);

        if (!is_array($result) || empty($result['user_id'])) {
            return null;
        }

        return $result;
    }
}

if (!function_exists('sitepulse_request_profiler_complete_session')) {
    /**
     * Marks a profiler session as completed and stores the resulting trace ID.
     *
     * @param string $token
     * @param int    $user_id
     * @param int    $trace_id
     * @return void
     */
    function sitepulse_request_profiler_complete_session($token, $user_id, $trace_id) {
        $token = sitepulse_request_profiler_normalize_token($token);
        $user_id = (int) $user_id;
        $trace_id = (int) $trace_id;

        if ($token === '' || $user_id <= 0) {
            return;
        }

        $session_key = sitepulse_request_profiler_transient_key($token, 'SITEPULSE_TRANSIENT_REQUEST_TRACE_SESSION_PREFIX');
        delete_transient($session_key);

        $result_key = sitepulse_request_profiler_transient_key($token, 'SITEPULSE_TRANSIENT_REQUEST_TRACE_RESULT_PREFIX');
        $payload = [
            'user_id'  => $user_id,
            'status'   => $trace_id > 0 ? 'completed' : 'failed',
            'trace_id' => $trace_id,
            'created'  => time(),
        ];

        set_transient($result_key, $payload, 10 * MINUTE_IN_SECONDS);
    }
}

if (!function_exists('sitepulse_request_profiler_get_current_token')) {
    /**
     * Retrieves the profiler token present in the current request, if any.
     *
     * @return string
     */
    function sitepulse_request_profiler_get_current_token() {
        if (!isset($_GET['sitepulse_trace'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return '';
        }

        $raw = wp_unslash($_GET['sitepulse_trace']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return sitepulse_request_profiler_normalize_token($raw);
    }
}

if (!function_exists('sitepulse_request_profiler_should_collect')) {
    /**
     * Determines whether the current request should be instrumented.
     *
     * @return bool
     */
    function sitepulse_request_profiler_should_collect() {
        $token = sitepulse_request_profiler_get_current_token();

        if ($token === '') {
            return false;
        }

        if (!isset($_GET['_wpnonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }

        $nonce = wp_unslash($_GET['_wpnonce']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!wp_verify_nonce($nonce, defined('SITEPULSE_NONCE_ACTION_REQUEST_TRACE') ? SITEPULSE_NONCE_ACTION_REQUEST_TRACE : 'sitepulse_request_trace')) {
            return false;
        }

        $session = sitepulse_request_profiler_get_session($token);

        if ($session === null) {
            return false;
        }

        return true;
    }
}

if (!function_exists('sitepulse_request_profiler_bootstrap')) {
    /**
     * Boots the request profiler instrumentation for the current request.
     *
     * @return void
     */
    function sitepulse_request_profiler_bootstrap() {
        if (!sitepulse_request_profiler_should_collect()) {
            return;
        }

        $token = sitepulse_request_profiler_get_current_token();
        $session = sitepulse_request_profiler_get_session($token);

        if ($session === null) {
            return;
        }

        global $sitepulse_request_profiler_state;
        $sitepulse_request_profiler_state = [
            'token'      => $token,
            'user_id'    => isset($session['user_id']) ? (int) $session['user_id'] : 0,
            'started_at' => microtime(true),
            'hooks'      => [],
            'stack'      => [],
        ];

        global $wpdb;

        if ($wpdb instanceof wpdb) {
            $wpdb->save_queries = true;

            if (!is_array($wpdb->queries)) {
                $wpdb->queries = [];
            }
        }

        add_filter('all', 'sitepulse_request_profiler_handle_hook_start', 0);
        add_filter('all', 'sitepulse_request_profiler_handle_hook_stop', PHP_INT_MAX);
        add_action('shutdown', 'sitepulse_request_profiler_finalize', PHP_INT_MAX);
    }
}

if (!function_exists('sitepulse_request_profiler_handle_hook_start')) {
    /**
     * Records the start of a hook execution.
     *
     * @param string $tag Hook identifier.
     * @return void
     */
    function sitepulse_request_profiler_handle_hook_start($tag) {
        global $sitepulse_request_profiler_state;

        if (!is_array($sitepulse_request_profiler_state) || empty($tag) || $tag === 'all') {
            return;
        }

        if (!isset($sitepulse_request_profiler_state['stack'][$tag])) {
            $sitepulse_request_profiler_state['stack'][$tag] = [];
        }

        $sitepulse_request_profiler_state['stack'][$tag][] = microtime(true);
    }
}

if (!function_exists('sitepulse_request_profiler_handle_hook_stop')) {
    /**
     * Records the completion of a hook execution.
     *
     * @param string $tag Hook identifier.
     * @return void
     */
    function sitepulse_request_profiler_handle_hook_stop($tag) {
        global $sitepulse_request_profiler_state;

        if (!is_array($sitepulse_request_profiler_state) || empty($tag) || $tag === 'all') {
            return;
        }

        if (empty($sitepulse_request_profiler_state['stack'][$tag])) {
            return;
        }

        $start = array_pop($sitepulse_request_profiler_state['stack'][$tag]);

        if (!is_numeric($start)) {
            return;
        }

        $duration = microtime(true) - (float) $start;

        if (!isset($sitepulse_request_profiler_state['hooks'][$tag])) {
            $sitepulse_request_profiler_state['hooks'][$tag] = [
                'count'    => 0,
                'total'    => 0.0,
                'max'      => 0.0,
            ];
        }

        $sitepulse_request_profiler_state['hooks'][$tag]['count']++;
        $sitepulse_request_profiler_state['hooks'][$tag]['total'] += $duration;

        if ($duration > $sitepulse_request_profiler_state['hooks'][$tag]['max']) {
            $sitepulse_request_profiler_state['hooks'][$tag]['max'] = $duration;
        }
    }
}

if (!function_exists('sitepulse_request_profiler_finalize')) {
    /**
     * Finalizes the profiling session, persists the trace and cleans up.
     *
     * @return void
     */
    function sitepulse_request_profiler_finalize() {
        global $sitepulse_request_profiler_state, $wpdb;

        if (!is_array($sitepulse_request_profiler_state) || empty($sitepulse_request_profiler_state['token'])) {
            return;
        }

        $duration = microtime(true) - (float) $sitepulse_request_profiler_state['started_at'];
        $token = $sitepulse_request_profiler_state['token'];
        $user_id = isset($sitepulse_request_profiler_state['user_id']) ? (int) $sitepulse_request_profiler_state['user_id'] : 0;

        $hooks = sitepulse_request_profiler_prepare_hooks($sitepulse_request_profiler_state['hooks']);
        $hook_count = 0;

        foreach ($hooks as $hook_entry) {
            $hook_count += isset($hook_entry['count']) ? (int) $hook_entry['count'] : 0;
        }

        $queries = [];
        $query_count = 0;

        if ($wpdb instanceof wpdb && is_array($wpdb->queries)) {
            $queries = sitepulse_request_profiler_prepare_queries($wpdb->queries);

            foreach ($wpdb->queries as $query_row) {
                if (is_array($query_row)) {
                    $query_count++;
                }
            }
        }

        $payload = [
            'recorded_at'    => gmdate('Y-m-d H:i:s'),
            'request_url'    => sitepulse_request_profiler_current_url(),
            'request_method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET',
            'trace_token'    => $token,
            'total_duration' => max(0, $duration),
            'memory_peak'    => function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : null,
            'hook_count'     => $hook_count,
            'query_count'    => $query_count,
            'hooks'          => $hooks,
            'queries'        => $queries,
            'context'        => [
                'user_id' => $user_id,
                'timestamp' => time(),
            ],
        ];

        $trace_id = sitepulse_request_profiler_store_trace($payload);

        sitepulse_request_profiler_complete_session($token, $user_id, $trace_id);
        sitepulse_request_profiler_purge_old_entries();

        $sitepulse_request_profiler_state = null;
    }
}

if (!function_exists('sitepulse_request_profiler_current_url')) {
    /**
     * Builds the absolute URL for the current request.
     *
     * @return string
     */
    function sitepulse_request_profiler_current_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $uri = is_string($uri) ? $uri : '/';

        if ($uri === '') {
            $uri = '/';
        }

        return ($host !== '' ? $scheme . '://' . $host : '') . $uri;
    }
}

if (!function_exists('sitepulse_request_profiler_prepare_hooks')) {
    /**
     * Formats hook metrics for storage.
     *
     * @param array $raw_hooks
     * @return array<int,array<string,mixed>>
     */
    function sitepulse_request_profiler_prepare_hooks(array $raw_hooks) {
        $prepared = [];

        foreach ($raw_hooks as $tag => $metrics) {
            if (!is_string($tag) || $tag === '') {
                continue;
            }

            $count = isset($metrics['count']) ? (int) $metrics['count'] : 0;
            $total = isset($metrics['total']) ? (float) $metrics['total'] : 0.0;
            $max = isset($metrics['max']) ? (float) $metrics['max'] : 0.0;

            if ($count <= 0 || $total <= 0) {
                continue;
            }

            $prepared[] = [
                'hook'        => $tag,
                'count'       => $count,
                'total_time'  => $total,
                'avg_time'    => $total / max(1, $count),
                'max_time'    => $max,
            ];
        }

        usort($prepared, static function ($a, $b) {
            $a_total = isset($a['total_time']) ? (float) $a['total_time'] : 0.0;
            $b_total = isset($b['total_time']) ? (float) $b['total_time'] : 0.0;

            if ($a_total === $b_total) {
                return 0;
            }

            return $a_total > $b_total ? -1 : 1;
        });

        return array_slice($prepared, 0, 50);
    }
}

if (!function_exists('sitepulse_request_profiler_prepare_queries')) {
    /**
     * Formats SQL query metrics for storage.
     *
     * @param array $raw_queries
     * @return array<int,array<string,mixed>>
     */
    function sitepulse_request_profiler_prepare_queries(array $raw_queries) {
        $prepared = [];

        foreach ($raw_queries as $entry) {
            if (!is_array($entry) || empty($entry[0])) {
                continue;
            }

            $sql = (string) $entry[0];
            $time = isset($entry[1]) ? (float) $entry[1] : 0.0;
            $caller = isset($entry[2]) ? (string) $entry[2] : '';

            if ($sql === '' || $time <= 0) {
                continue;
            }

            $hash = md5($sql);

            if (!isset($prepared[$hash])) {
                $prepared[$hash] = [
                    'sql'        => $sql,
                    'total_time' => 0.0,
                    'count'      => 0,
                    'callers'    => [],
                ];
            }

            $prepared[$hash]['total_time'] += $time;
            $prepared[$hash]['count']++;

            if ($caller !== '') {
                $prepared[$hash]['callers'][] = $caller;
            }
        }

        foreach ($prepared as $hash => $metrics) {
            $callers = array_unique($metrics['callers']);
            $prepared[$hash]['callers'] = array_slice($callers, 0, 5);
            $prepared[$hash]['avg_time'] = $metrics['total_time'] / max(1, $metrics['count']);
        }

        uasort($prepared, static function ($a, $b) {
            $a_total = isset($a['total_time']) ? (float) $a['total_time'] : 0.0;
            $b_total = isset($b['total_time']) ? (float) $b['total_time'] : 0.0;

            if ($a_total === $b_total) {
                return 0;
            }

            return $a_total > $b_total ? -1 : 1;
        });

        return array_slice(array_values($prepared), 0, 50);
    }
}

if (!function_exists('sitepulse_request_profiler_store_trace')) {
    /**
     * Persists a trace payload in the database.
     *
     * @param array $payload
     * @return int Inserted trace ID or 0 on failure.
     */
    function sitepulse_request_profiler_store_trace(array $payload) {
        sitepulse_request_profiler_maybe_upgrade_schema();

        $table = sitepulse_request_profiler_get_table_name();

        if ($table === '') {
            return 0;
        }

        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return 0;
        }

        $data = [
            'recorded_at'    => isset($payload['recorded_at']) ? (string) $payload['recorded_at'] : gmdate('Y-m-d H:i:s'),
            'request_url'    => isset($payload['request_url']) ? (string) $payload['request_url'] : '',
            'request_method' => isset($payload['request_method']) ? (string) $payload['request_method'] : 'GET',
            'trace_token'    => isset($payload['trace_token']) ? (string) $payload['trace_token'] : '',
            'total_duration' => isset($payload['total_duration']) ? (float) $payload['total_duration'] : 0.0,
            'memory_peak'    => isset($payload['memory_peak']) ? (int) $payload['memory_peak'] : null,
            'hook_count'     => isset($payload['hook_count']) ? (int) $payload['hook_count'] : 0,
            'query_count'    => isset($payload['query_count']) ? (int) $payload['query_count'] : 0,
            'hooks'          => isset($payload['hooks']) ? wp_json_encode($payload['hooks']) : wp_json_encode([]),
            'queries'        => isset($payload['queries']) ? wp_json_encode($payload['queries']) : wp_json_encode([]),
            'context'        => isset($payload['context']) ? wp_json_encode($payload['context']) : wp_json_encode([]),
        ];

        $formats = ['%s', '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%s', '%s', '%s'];

        $inserted = $wpdb->insert($table, $data, $formats);

        if ($inserted === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }
}

if (!function_exists('sitepulse_request_profiler_get_trace')) {
    /**
     * Retrieves a stored trace by ID.
     *
     * @param int $trace_id
     * @return array|null
     */
    function sitepulse_request_profiler_get_trace($trace_id) {
        $trace_id = (int) $trace_id;

        if ($trace_id <= 0) {
            return null;
        }

        $table = sitepulse_request_profiler_get_table_name();

        if ($table === '') {
            return null;
        }

        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $trace_id), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        $row['hooks'] = isset($row['hooks']) ? json_decode($row['hooks'], true) : [];
        $row['queries'] = isset($row['queries']) ? json_decode($row['queries'], true) : [];
        $row['context'] = isset($row['context']) ? json_decode($row['context'], true) : [];

        if (!is_array($row['hooks'])) {
            $row['hooks'] = [];
        }

        if (!is_array($row['queries'])) {
            $row['queries'] = [];
        }

        if (!is_array($row['context'])) {
            $row['context'] = [];
        }

        return $row;
    }
}

if (!function_exists('sitepulse_request_profiler_is_available')) {
    /**
     * Checks if the profiler can be used in the current context.
     *
     * @return bool
     */
    function sitepulse_request_profiler_is_available() {
        return current_user_can(sitepulse_get_capability());
    }
}
