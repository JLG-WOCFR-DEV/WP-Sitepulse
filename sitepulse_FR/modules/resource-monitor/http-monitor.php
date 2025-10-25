<?php
/**
 * Outbound HTTP monitoring utilities.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

sitepulse_http_monitor_init();

/**
 * Legacy bootstrapper for the HTTP monitor.
 *
 * @return void
 */
function sitepulse_http_monitor_bootstrap() {
    sitepulse_http_monitor_init();
}

/**
 * Bootstraps the HTTP monitor hooks.
 *
 * @return void
 */
function sitepulse_http_monitor_init() {
    static $initialized = false;

    if ($initialized) {
        return;
    }

    if (!function_exists('add_action')) {
        return;
    }

    $initialized = true;

    add_action('plugins_loaded', 'sitepulse_http_monitor_bootstrap_storage', 11);
    add_action('http_api_debug', 'sitepulse_http_monitor_handle_http_api_debug', 10, 5);
    add_action('rest_api_init', 'sitepulse_http_monitor_register_rest_routes');
}

/**
 * Ensures the HTTP monitor table exists.
 *
 * @return void
 */
function sitepulse_http_monitor_bootstrap_storage() {
    sitepulse_http_monitor_maybe_upgrade_schema();
}

/**
 * Determines whether the HTTP monitor should capture outbound requests.
 *
 * @return bool
 */
function sitepulse_http_monitor_is_enabled() {
    if (function_exists('sitepulse_is_module_active')) {
        return sitepulse_is_module_active('resource_monitor');
    }

    return true;
}

/**
 * Handles the debug hook fired before and after WordPress performs an HTTP request.
 *
 * @param mixed       $response Response or error from the HTTP API.
 * @param string      $type     Debug context (request|response|transport).
 * @param object|null $class    Transport instance.
 * @param array       $args     Request arguments.
 * @param string      $url      Target URL.
 *
 * @return void
 */
function sitepulse_http_monitor_handle_http_api_debug($response, $type, $class, $args, $url) {
    static $pending = [];

    $key = sitepulse_http_monitor_get_request_key($class, $url);

    if ($type === 'request') {
        $pending[$key] = [
            'started_at' => microtime(true),
            'args'       => is_array($args) ? $args : [],
        ];

        return;
    }

    if ($type !== 'response') {
        return;
    }

    $context = isset($pending[$key]) ? $pending[$key] : null;
    unset($pending[$key]);

    if (!sitepulse_http_monitor_is_enabled()) {
        return;
    }

    if (!is_string($url) || $url === '') {
        return;
    }

    $request_args = is_array($args) ? $args : [];

    if ($context !== null && isset($context['args']) && is_array($context['args'])) {
        $request_args = $context['args'];
    }

    if (sitepulse_http_monitor_should_ignore($url, $request_args)) {
        return;
    }

    $entry = sitepulse_http_monitor_prepare_entry($response, $context, $request_args, $url);

    if (empty($entry)) {
        return;
    }

    sitepulse_http_monitor_store_entry($entry);
}

/**
 * Builds a stable key used to link the request and response debug events.
 *
 * @param object|null $class Transport instance.
 * @param string      $url   Requested URL.
 *
 * @return string
 */
function sitepulse_http_monitor_get_request_key($class, $url) {
    if (is_object($class)) {
        return spl_object_hash($class);
    }

    if (is_string($url) && $url !== '') {
        return md5($url);
    }

    return uniqid('sitepulse_http_', true);
}

/**
 * Determines whether the outbound request should be ignored.
 *
 * @param string $url  Target URL.
 * @param array  $args Request arguments.
 *
 * @return bool
 */
function sitepulse_http_monitor_should_ignore($url, array $args = []) {
    $should_ignore = false;

    if (isset($args['sitepulse_skip_monitor']) && $args['sitepulse_skip_monitor']) {
        $should_ignore = true;
    }

    if (function_exists('apply_filters')) {
        /**
         * Filters whether an outbound HTTP request should be ignored by SitePulse.
         *
         * @param bool   $should_ignore Whether the request should be ignored.
         * @param string $url           Target URL.
         * @param array  $args          Request arguments.
         */
        $should_ignore = (bool) apply_filters('sitepulse_http_monitor_should_ignore', $should_ignore, $url, $args);
    }

    return $should_ignore;
}

/**
 * Prepares a normalized HTTP entry from the raw debug context.
 *
 * @param mixed      $response Response or error from the HTTP API.
 * @param array|null $context  Timing context captured before the request.
 * @param array      $args     Request arguments.
 * @param string     $url      Target URL.
 *
 * @return array<string,mixed>
 */
function sitepulse_http_monitor_prepare_entry($response, $context, array $args, $url) {
    $host = '';

    if (function_exists('wp_parse_url')) {
        $parsed_host = wp_parse_url($url, PHP_URL_HOST);
        $host        = is_string($parsed_host) ? strtolower($parsed_host) : '';
    }

    if ($host === '' && function_exists('parse_url')) {
        $parsed_host = parse_url($url, PHP_URL_HOST);
        $host        = is_string($parsed_host) ? strtolower($parsed_host) : '';
    }

    $method = isset($args['method']) && is_string($args['method']) ? strtoupper($args['method']) : 'GET';
    $method = substr($method, 0, 10);

    $duration = null;

    if (is_array($context) && isset($context['started_at'])) {
        $duration = max(0, (microtime(true) - (float) $context['started_at']) * 1000);
    }

    $entry = [
        'recorded_at'   => time(),
        'url'           => sitepulse_http_monitor_sanitize_url($url),
        'host'          => sitepulse_http_monitor_truncate($host, 191),
        'method'        => sitepulse_http_monitor_truncate($method, 10),
        'response_code' => null,
        'duration_ms'   => $duration,
        'bytes'         => null,
        'error_code'    => '',
        'error_message' => '',
        'is_error'      => 0,
    ];

    if (is_wp_error($response)) {
        $entry['is_error']     = 1;
        $entry['error_code']   = sitepulse_http_monitor_truncate($response->get_error_code(), 191);
        $messages               = $response->get_error_messages();
        $message_text           = implode('; ', array_map('strval', $messages));
        $entry['error_message'] = sitepulse_http_monitor_truncate(sitepulse_http_monitor_sanitize_text($message_text), 800);
    } elseif (is_array($response)) {
        if (isset($response['response']['code'])) {
            $entry['response_code'] = (int) $response['response']['code'];
        }

        if (isset($response['body'])) {
            $entry['bytes'] = strlen((string) $response['body']);
        }

        if (isset($response['headers']) && is_array($response['headers'])) {
            $content_length = null;

            if (isset($response['headers']['content-length'])) {
                $content_length = $response['headers']['content-length'];
            } elseif (isset($response['headers']['Content-Length'])) {
                $content_length = $response['headers']['Content-Length'];
            }

            if ($content_length !== null && is_numeric($content_length)) {
                $entry['bytes'] = max(0, (int) $content_length);
            }
        }
    } elseif ($response instanceof WP_HTTP_Response) {
        $entry['response_code'] = (int) $response->get_status();

        $headers = $response->get_headers();
        $content_length = null;

        if (class_exists('WP_HTTP_Headers') && $headers instanceof WP_HTTP_Headers) {
            $content_length = $headers->get('content-length');
        } elseif (is_array($headers)) {
            if (isset($headers['content-length'])) {
                $content_length = $headers['content-length'];
            } elseif (isset($headers['Content-Length'])) {
                $content_length = $headers['Content-Length'];
            }
        }

        if ($content_length !== null && is_numeric($content_length)) {
            $entry['bytes'] = max(0, (int) $content_length);
        } else {
            $body = $response->get_body();

            if (!is_string($body)) {
                $data = $response->get_data();

                if (is_string($data)) {
                    $body = $data;
                } elseif (is_scalar($data)) {
                    $body = (string) $data;
                } elseif (is_array($data) || is_object($data)) {
                    $encoded = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
                    $body = is_string($encoded) ? $encoded : '';
                } else {
                    $body = '';
                }
            }

            if (is_string($body)) {
                $entry['bytes'] = strlen($body);
            }
        }
    }

    if ($entry['response_code'] !== null && $entry['response_code'] >= 400) {
        $entry['is_error'] = 1;
    }

    if ($entry['is_error'] && $entry['error_code'] === '' && $entry['response_code'] !== null) {
        $entry['error_code'] = 'http_' . $entry['response_code'];
    }

    return $entry;
}

/**
 * Normalizes a URL for storage.
 *
 * @param string $url Raw URL.
 *
 * @return string
 */
function sitepulse_http_monitor_sanitize_url($url) {
    $sanitized = is_string($url) ? $url : '';

    if (function_exists('esc_url_raw')) {
        $sanitized = esc_url_raw($sanitized);
    }

    return sitepulse_http_monitor_truncate($sanitized, 2048);
}

/**
 * Sanitizes arbitrary text before storage.
 *
 * @param string $text Input text.
 *
 * @return string
 */
function sitepulse_http_monitor_sanitize_text($text) {
    $normalized = is_string($text) ? $text : '';

    if (function_exists('wp_strip_all_tags')) {
        $normalized = wp_strip_all_tags($normalized);
    }

    return trim($normalized);
}

/**
 * Truncates a string to a fixed length using multibyte support when available.
 *
 * @param string $value  Input string.
 * @param int    $length Maximum length.
 *
 * @return string
 */
function sitepulse_http_monitor_truncate($value, $length) {
    $string = is_string($value) ? $value : '';

    if ($length <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($string, 0, $length);
    }

    return substr($string, 0, $length);
}

/**
 * Persists an HTTP entry into the datastore.
 *
 * @param array<string,mixed> $entry Normalized entry fields.
 *
 * @return void
 */
function sitepulse_http_monitor_store_entry(array $entry) {
    $table = sitepulse_http_monitor_get_table_name();

    if ($table === '' || !sitepulse_http_monitor_table_exists()) {
        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $data = [
        'recorded_at'   => isset($entry['recorded_at']) ? (int) $entry['recorded_at'] : time(),
        'url'           => isset($entry['url']) ? $entry['url'] : '',
        'host'          => isset($entry['host']) ? $entry['host'] : '',
        'method'        => isset($entry['method']) ? $entry['method'] : 'GET',
        'response_code' => isset($entry['response_code']) ? $entry['response_code'] : null,
        'duration_ms'   => isset($entry['duration_ms']) ? (float) $entry['duration_ms'] : null,
        'bytes'         => isset($entry['bytes']) ? max(0, (int) $entry['bytes']) : null,
        'error_code'    => isset($entry['error_code']) ? $entry['error_code'] : '',
        'error_message' => isset($entry['error_message']) ? $entry['error_message'] : '',
        'is_error'      => !empty($entry['is_error']) ? 1 : 0,
        'created_at'    => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
    ];

    $formats = ['%d', '%s', '%s', '%s', '%d', '%f', '%d', '%s', '%s', '%d', '%s'];

    $wpdb->insert($table, $data, $formats);

    sitepulse_http_monitor_apply_retention();
    sitepulse_http_monitor_clear_caches();
}

/**
 * Clears cached aggregates for the HTTP monitor.
 *
 * @return void
 */
function sitepulse_http_monitor_clear_caches() {
    if (defined('SITEPULSE_TRANSIENT_HTTP_MONITOR_AGGREGATES')) {
        delete_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_AGGREGATES);
    }

    if (defined('SITEPULSE_TRANSIENT_HTTP_MONITOR_RECENT')) {
        delete_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_RECENT);
    }
}
/**
 * Retrieves the HTTP monitor table name.
 *
 * @return string
 */
function sitepulse_http_monitor_get_table_name() {
    if (!defined('SITEPULSE_TABLE_HTTP_MONITOR')) {
        return '';
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return '';
    }

    return $wpdb->prefix . SITEPULSE_TABLE_HTTP_MONITOR;
}

/**
 * Checks whether the HTTP monitor table exists.
 *
 * @param bool $force_refresh Optional. Bypass the cached result.
 *
 * @return bool
 */
function sitepulse_http_monitor_table_exists($force_refresh = false) {
    static $exists = null;

    if ($force_refresh) {
        $exists = null;
    }

    if ($exists !== null) {
        return $exists;
    }

    $table = sitepulse_http_monitor_get_table_name();

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
 * Creates or upgrades the HTTP monitor table.
 *
 * @return void
 */
function sitepulse_http_monitor_maybe_upgrade_schema() {
    if (!defined('SITEPULSE_HTTP_MONITOR_SCHEMA_VERSION') || !defined('SITEPULSE_OPTION_HTTP_MONITOR_SCHEMA_VERSION')) {
        return;
    }

    $target  = (int) SITEPULSE_HTTP_MONITOR_SCHEMA_VERSION;
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
 * Installs the HTTP monitor table.
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

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        recorded_at int(10) unsigned NOT NULL,
        url text NOT NULL,
        host varchar(191) NOT NULL,
        method varchar(10) NOT NULL,
        response_code smallint(6) NULL,
        duration_ms float NULL,
        bytes bigint(20) unsigned NULL,
        error_code varchar(191) NULL,
        error_message text NULL,
        is_error tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY recorded_at (recorded_at),
        KEY host (host),
        KEY response_code (response_code)
    ) {$charset_collate};";

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta($sql);

    sitepulse_http_monitor_table_exists(true);
}

/**
 * Applies the configured retention policy to the HTTP logs.
 *
 * @return void
 */
function sitepulse_http_monitor_apply_retention() {
    $table = sitepulse_http_monitor_get_table_name();

    if ($table === '' || !sitepulse_http_monitor_table_exists()) {
        return;
    }

    $days = sitepulse_http_monitor_get_retention_days();

    if ($days <= 0) {
        return;
    }

    $threshold = time() - ($days * DAY_IN_SECONDS);

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE recorded_at < %d", $threshold));
}

/**
 * Retrieves the configured retention window in days.
 *
 * @return int
 */
function sitepulse_http_monitor_get_retention_days() {
    $default = defined('SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS') ? (int) SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS : 14;

    if (!defined('SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS')) {
        return $default;
    }

    $value = get_option(SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS, $default);

    if (!is_numeric($value)) {
        return $default;
    }

    $days = max(1, (int) $value);

    if (function_exists('apply_filters')) {
        /**
         * Filters the number of days HTTP monitor entries should be retained.
         *
         * @param int $days Retention window in days.
         */
        $days = (int) apply_filters('sitepulse_http_monitor_retention_days', $days);
    }

    return $days;
}

/**
 * Returns the HTTP monitor thresholds and settings.
 *
 * @return array<string,float>
 */
function sitepulse_http_monitor_get_settings() {
    $defaults = [
        'latency_threshold_ms' => defined('SITEPULSE_DEFAULT_HTTP_MONITOR_LATENCY_THRESHOLD_MS')
            ? (float) SITEPULSE_DEFAULT_HTTP_MONITOR_LATENCY_THRESHOLD_MS
            : 1000.0,
        'error_rate_percent'  => defined('SITEPULSE_DEFAULT_HTTP_MONITOR_ERROR_RATE')
            ? (float) SITEPULSE_DEFAULT_HTTP_MONITOR_ERROR_RATE
            : 5.0,
    ];

    if (!defined('SITEPULSE_OPTION_HTTP_MONITOR_SETTINGS')) {
        return $defaults;
    }

    $option = get_option(SITEPULSE_OPTION_HTTP_MONITOR_SETTINGS, []);

    if (!is_array($option)) {
        return $defaults;
    }

    $settings = $defaults;

    if (isset($option['latency_threshold_ms']) && is_numeric($option['latency_threshold_ms'])) {
        $settings['latency_threshold_ms'] = max(0, (float) $option['latency_threshold_ms']);
    }

    if (isset($option['error_rate_percent']) && is_numeric($option['error_rate_percent'])) {
        $settings['error_rate_percent'] = max(0, min(100, (float) $option['error_rate_percent']));
    }

    return function_exists('apply_filters')
        ? (array) apply_filters('sitepulse_http_monitor_settings', $settings)
        : $settings;
}

/**
 * Handles the submission of the HTTP monitor settings form.
 *
 * @return void
 */
function sitepulse_http_monitor_handle_settings() {
    $capability = function_exists('sitepulse_get_capability')
        ? sitepulse_get_capability()
        : 'manage_options';

    if (!current_user_can($capability)) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour modifier cette configuration.", 'sitepulse'));
    }

    $nonce_action = defined('SITEPULSE_NONCE_ACTION_HTTP_MONITOR_SETTINGS')
        ? SITEPULSE_NONCE_ACTION_HTTP_MONITOR_SETTINGS
        : 'sitepulse_http_monitor_settings';

    check_admin_referer($nonce_action);

    $current_settings = sitepulse_http_monitor_get_settings();

    $latency_input = isset($_POST['sitepulse_http_latency_threshold'])
        ? wp_unslash($_POST['sitepulse_http_latency_threshold'])
        : $current_settings['latency_threshold_ms'];

    $latency_threshold = is_numeric($latency_input)
        ? max(0, (int) round((float) $latency_input))
        : (int) round((float) $current_settings['latency_threshold_ms']);

    $error_rate_input = isset($_POST['sitepulse_http_error_rate'])
        ? wp_unslash($_POST['sitepulse_http_error_rate'])
        : $current_settings['error_rate_percent'];

    $error_rate_threshold = is_numeric($error_rate_input)
        ? (int) round((float) $error_rate_input)
        : (int) round((float) $current_settings['error_rate_percent']);

    $error_rate_threshold = max(0, min(100, $error_rate_threshold));

    $retention_default = sitepulse_http_monitor_get_retention_days();

    $retention_input = isset($_POST['sitepulse_http_retention_days'])
        ? wp_unslash($_POST['sitepulse_http_retention_days'])
        : $retention_default;

    $retention_days = is_numeric($retention_input)
        ? max(1, (int) $retention_input)
        : $retention_default;

    if (defined('SITEPULSE_OPTION_HTTP_MONITOR_SETTINGS')) {
        update_option(
            SITEPULSE_OPTION_HTTP_MONITOR_SETTINGS,
            [
                'latency_threshold_ms' => $latency_threshold,
                'error_rate_percent'  => $error_rate_threshold,
            ]
        );
    }

    if (defined('SITEPULSE_OPTION_HTTP_MONITOR_LATENCY_THRESHOLD_MS')) {
        update_option(SITEPULSE_OPTION_HTTP_MONITOR_LATENCY_THRESHOLD_MS, $latency_threshold);
    }

    if (defined('SITEPULSE_OPTION_HTTP_MONITOR_ERROR_RATE_THRESHOLD')) {
        update_option(SITEPULSE_OPTION_HTTP_MONITOR_ERROR_RATE_THRESHOLD, $error_rate_threshold);
    }

    if (defined('SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS')) {
        update_option(SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS, $retention_days);
    }

    if (defined('SITEPULSE_TRANSIENT_HTTP_MONITOR_AGGREGATES')) {
        delete_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_AGGREGATES);
    }

    if (defined('SITEPULSE_TRANSIENT_HTTP_MONITOR_RECENT')) {
        delete_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_RECENT);
    }

    $redirect_url = admin_url('admin.php?page=sitepulse-resources');
    $redirect_url = add_query_arg('sitepulse-http-settings', 'updated', $redirect_url);

    wp_safe_redirect($redirect_url);
    exit;
}
/**
 * Registers REST endpoints exposing outbound HTTP statistics.
 *
 * @return void
 */
function sitepulse_http_monitor_register_rest_routes() {
    if (!function_exists('register_rest_route')) {
        return;
    }

    register_rest_route(
        'sitepulse/v1',
        '/resources/http',
        [
            'methods'             => defined('WP_REST_Server::READABLE') ? WP_REST_Server::READABLE : 'GET',
            'callback'            => 'sitepulse_http_monitor_rest_stats',
            'permission_callback' => 'sitepulse_http_monitor_rest_permission_check',
            'args'                => [
                'since' => [
                    'description' => __('Filtrer les requêtes à partir d’un horodatage Unix ou d’une date lisible.', 'sitepulse'),
                    'type'        => 'string',
                    'required'    => false,
                ],
                'top_limit' => [
                    'description' => __('Nombre maximum de services externes à retourner.', 'sitepulse'),
                    'type'        => 'integer',
                    'required'    => false,
                    'default'     => 10,
                ],
                'sample_limit' => [
                    'description' => __('Nombre maximum d’appels récents à inclure.', 'sitepulse'),
                    'type'        => 'integer',
                    'required'    => false,
                    'default'     => 20,
                ],
            ],
        ]
    );
}

/**
 * Permission callback for the HTTP monitor REST endpoints.
 *
 * @return bool
 */
function sitepulse_http_monitor_rest_permission_check() {
    if (function_exists('sitepulse_resource_monitor_rest_permission_check')) {
        return sitepulse_resource_monitor_rest_permission_check();
    }

    $capability = function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options';

    return current_user_can($capability);
}

/**
 * Handles the HTTP monitor REST endpoint.
 *
 * @param WP_REST_Request $request Incoming request.
 *
 * @return WP_REST_Response|WP_Error
 */
function sitepulse_http_monitor_rest_stats($request) {
    if (!sitepulse_http_monitor_is_enabled()) {
        return new WP_Error(
            'sitepulse_http_monitor_inactive',
            __('Le module Resource Monitor est désactivé.', 'sitepulse'),
            ['status' => 400]
        );
    }

    $since_param  = $request->get_param('since');
    $top_limit    = sitepulse_http_monitor_normalize_limit($request->get_param('top_limit'), 10, 1, 50);
    $sample_limit = sitepulse_http_monitor_normalize_limit($request->get_param('sample_limit'), 20, 1, 100);

    $since_timestamp = null;

    if ($since_param !== null && $since_param !== '') {
        if (function_exists('sitepulse_resource_monitor_rest_parse_since_param')) {
            $parsed = sitepulse_resource_monitor_rest_parse_since_param($since_param);

            if (isset($parsed['error'])) {
                return new WP_Error('sitepulse_http_monitor_invalid_since', $parsed['error'], ['status' => 400]);
            }

            $since_timestamp = isset($parsed['timestamp']) ? $parsed['timestamp'] : null;
        } else {
            if (is_numeric($since_param)) {
                $since_timestamp = (int) $since_param;
            } else {
                $parsed = strtotime((string) $since_param);

                if ($parsed === false) {
                    return new WP_Error(
                        'sitepulse_http_monitor_invalid_since',
                        __('Impossible d’interpréter la valeur fournie pour le paramètre since.', 'sitepulse'),
                        ['status' => 400]
                    );
                }

                $since_timestamp = $parsed;
            }
        }
    }

    $settings = sitepulse_http_monitor_get_settings();

    $should_cache   = ($since_timestamp === null && $top_limit === 10 && $sample_limit === 20);
    $cached_summary = false;
    $cached_recent  = false;

    if ($should_cache && defined('SITEPULSE_TRANSIENT_HTTP_MONITOR_AGGREGATES')) {
        $cached_summary = get_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_AGGREGATES);
    }

    if ($should_cache && defined('SITEPULSE_TRANSIENT_HTTP_MONITOR_RECENT')) {
        $cached_recent = get_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_RECENT);
    }

    if (is_array($cached_summary) && isset($cached_summary['summary'], $cached_summary['top_hosts'])) {
        $summary_data = $cached_summary;
    } else {
        $summary_data = sitepulse_http_monitor_build_summary($since_timestamp, $settings, $top_limit);

        if ($should_cache && defined('SITEPULSE_TRANSIENT_HTTP_MONITOR_AGGREGATES')) {
            set_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_AGGREGATES, $summary_data, MINUTE_IN_SECONDS * 5);
        }
    }

    if (is_array($cached_recent)) {
        $recent_entries = $cached_recent;
    } else {
        $recent_entries = sitepulse_http_monitor_get_recent_entries($sample_limit, $since_timestamp);

        if ($should_cache && defined('SITEPULSE_TRANSIENT_HTTP_MONITOR_RECENT')) {
            set_transient(SITEPULSE_TRANSIENT_HTTP_MONITOR_RECENT, $recent_entries, MINUTE_IN_SECONDS * 5);
        }
    }

    $response = [
        'thresholds' => [
            'latency_ms'         => $settings['latency_threshold_ms'],
            'error_rate_percent' => $settings['error_rate_percent'],
        ],
        'summary'   => $summary_data['summary'],
        'top_hosts' => $summary_data['top_hosts'],
        'recent'    => $recent_entries,
        'since'     => $since_timestamp,
    ];

    return rest_ensure_response($response);
}

/**
 * Ensures a numeric limit falls within an expected range.
 *
 * @param mixed $value   Raw value.
 * @param int   $default Default value.
 * @param int   $min     Minimum allowed.
 * @param int   $max     Maximum allowed.
 *
 * @return int
 */
function sitepulse_http_monitor_normalize_limit($value, $default, $min, $max) {
    if (!is_numeric($value)) {
        return $default;
    }

    $value = (int) $value;

    if ($value < $min) {
        return $min;
    }

    if ($value > $max) {
        return $max;
    }

    return $value;
}

/**
 * Builds aggregate statistics for the HTTP monitor REST response.
 *
 * @param int|null            $since_timestamp Optional start timestamp.
 * @param array<string,mixed> $settings        Monitor settings.
 * @param int                 $limit          Maximum number of hosts to return.
 *
 * @return array{summary:array<string,mixed>,top_hosts:array<int,array<string,mixed>>}
 */
function sitepulse_http_monitor_build_summary($since_timestamp, array $settings, $limit) {
    $table = sitepulse_http_monitor_get_table_name();

    if ($table === '' || !sitepulse_http_monitor_table_exists()) {
        return [
            'summary'   => [
                'total_requests'      => 0,
                'error_count'         => 0,
                'slow_count'          => 0,
                'average_duration_ms' => 0.0,
                'max_duration_ms'     => 0.0,
                'error_rate_percent'  => 0.0,
            ],
            'top_hosts' => [],
        ];
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return [
            'summary'   => [
                'total_requests'      => 0,
                'error_count'         => 0,
                'slow_count'          => 0,
                'average_duration_ms' => 0.0,
                'max_duration_ms'     => 0.0,
                'error_rate_percent'  => 0.0,
            ],
            'top_hosts' => [],
        ];
    }

    $values = [];
    $sql    = "SELECT COUNT(*) AS total_requests,
        SUM(CASE WHEN is_error = 1 THEN 1 ELSE 0 END) AS error_count,
        AVG(duration_ms) AS avg_duration,
        MAX(duration_ms) AS max_duration,
        SUM(CASE WHEN duration_ms IS NOT NULL AND duration_ms >= %f THEN 1 ELSE 0 END) AS slow_count
        FROM {$table}";

    $values[] = isset($settings['latency_threshold_ms']) ? (float) $settings['latency_threshold_ms'] : 0.0;

    if ($since_timestamp !== null) {
        $sql     .= ' WHERE recorded_at >= %d';
        $values[] = (int) $since_timestamp;
    }

    $summary_row = $wpdb->get_row($wpdb->prepare($sql, $values), ARRAY_A);

    if (!is_array($summary_row)) {
        $summary_row = [
            'total_requests' => 0,
            'error_count'    => 0,
            'avg_duration'   => 0,
            'max_duration'   => 0,
            'slow_count'     => 0,
        ];
    }

    $total_requests = isset($summary_row['total_requests']) ? (int) $summary_row['total_requests'] : 0;
    $error_count    = isset($summary_row['error_count']) ? (int) $summary_row['error_count'] : 0;
    $slow_count     = isset($summary_row['slow_count']) ? (int) $summary_row['slow_count'] : 0;
    $avg_duration   = isset($summary_row['avg_duration']) ? (float) $summary_row['avg_duration'] : 0.0;
    $max_duration   = isset($summary_row['max_duration']) ? (float) $summary_row['max_duration'] : 0.0;

    $error_rate = $total_requests > 0 ? ($error_count / $total_requests) * 100 : 0.0;

    $hosts_sql = "SELECT host,
            COUNT(*) AS total_requests,
            SUM(CASE WHEN is_error = 1 THEN 1 ELSE 0 END) AS error_count,
            AVG(duration_ms) AS avg_duration,
            MAX(duration_ms) AS max_duration
        FROM {$table}
        WHERE host <> ''";

    $host_values = [];

    if ($since_timestamp !== null) {
        $hosts_sql   .= ' AND recorded_at >= %d';
        $host_values[] = (int) $since_timestamp;
    }

    $hosts_sql   .= ' GROUP BY host ORDER BY avg_duration DESC LIMIT %d';
    $host_values[] = max(1, (int) $limit);

    $host_results = $wpdb->get_results($wpdb->prepare($hosts_sql, $host_values), ARRAY_A);
    $top_hosts    = [];

    if (is_array($host_results)) {
        foreach ($host_results as $row) {
            if (!is_array($row) || empty($row['host'])) {
                continue;
            }

            $host_total  = isset($row['total_requests']) ? (int) $row['total_requests'] : 0;
            $host_errors = isset($row['error_count']) ? (int) $row['error_count'] : 0;
            $host_avg    = isset($row['avg_duration']) ? (float) $row['avg_duration'] : 0.0;
            $host_max    = isset($row['max_duration']) ? (float) $row['max_duration'] : 0.0;

            $top_hosts[] = [
                'host'                => (string) $row['host'],
                'total_requests'      => $host_total,
                'error_count'         => $host_errors,
                'error_rate_percent'  => $host_total > 0 ? ($host_errors / $host_total) * 100 : 0.0,
                'average_duration_ms' => $host_avg,
                'max_duration_ms'     => $host_max,
            ];
        }
    }

    return [
        'summary' => [
            'total_requests'      => $total_requests,
            'error_count'         => $error_count,
            'slow_count'          => $slow_count,
            'average_duration_ms' => $avg_duration,
            'max_duration_ms'     => $max_duration,
            'error_rate_percent'  => $error_rate,
        ],
        'top_hosts' => $top_hosts,
    ];
}

/**
 * Retrieves recent HTTP samples for display.
 *
 * @param int      $limit           Maximum number of entries.
 * @param int|null $since_timestamp Optional start timestamp.
 *
 * @return array<int,array<string,mixed>>
 */
function sitepulse_http_monitor_get_recent_entries($limit, $since_timestamp = null) {
    $table = sitepulse_http_monitor_get_table_name();

    if ($table === '' || !sitepulse_http_monitor_table_exists()) {
        return [];
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return [];
    }

    $sql    = "SELECT recorded_at, host, method, response_code, duration_ms, bytes, is_error, url, error_code, error_message
        FROM {$table}";
    $values = [];

    if ($since_timestamp !== null) {
        $sql     .= ' WHERE recorded_at >= %d';
        $values[] = (int) $since_timestamp;
    }

    $sql     .= ' ORDER BY recorded_at DESC LIMIT %d';
    $values[] = max(1, (int) $limit);

    $results = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

    if (!is_array($results)) {
        return [];
    }

    $entries = [];

    foreach ($results as $row) {
        if (!is_array($row)) {
            continue;
        }

        $entries[] = [
            'recorded_at'   => isset($row['recorded_at']) ? (int) $row['recorded_at'] : 0,
            'host'          => isset($row['host']) ? (string) $row['host'] : '',
            'method'        => isset($row['method']) ? (string) $row['method'] : '',
            'response_code' => isset($row['response_code']) ? (int) $row['response_code'] : null,
            'duration_ms'   => isset($row['duration_ms']) ? (float) $row['duration_ms'] : null,
            'bytes'         => isset($row['bytes']) ? (int) $row['bytes'] : null,
            'is_error'      => !empty($row['is_error']),
            'url'           => isset($row['url']) ? (string) $row['url'] : '',
            'error_code'    => isset($row['error_code']) ? (string) $row['error_code'] : '',
            'error_message' => isset($row['error_message']) ? (string) $row['error_message'] : '',
        ];
    }

    return $entries;
}
