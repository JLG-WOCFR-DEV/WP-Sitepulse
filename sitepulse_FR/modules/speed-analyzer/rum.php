<?php
/**
 * Real User Monitoring utilities for Web Vitals collection.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

sitepulse_rum_init();

/**
 * Boots the hooks used by the RUM feature.
 *
 * @return void
 */
function sitepulse_rum_init() {
    add_action('plugins_loaded', 'sitepulse_rum_bootstrap_storage', 12);
    add_action('rest_api_init', 'sitepulse_rum_register_rest_routes');
    add_action('wp_enqueue_scripts', 'sitepulse_rum_enqueue_assets');
    add_action('admin_post_sitepulse_rum_settings', 'sitepulse_rum_handle_settings_post');
}

/**
 * Ensures the storage required for RUM metrics is ready.
 *
 * @return void
 */
function sitepulse_rum_bootstrap_storage() {
    sitepulse_rum_maybe_upgrade_schema();
}

/**
 * Determines whether RUM collection is enabled.
 *
 * @return bool
 */
function sitepulse_rum_is_enabled() {
    $settings = sitepulse_rum_get_settings();

    return !empty($settings['enabled']);
}

/**
 * Retrieves the RUM settings.
 *
 * @return array<string,mixed>
 */
function sitepulse_rum_get_settings() {
    $defaults = [
        'enabled'          => false,
        'token'            => '',
        'consent_required' => false,
    ];

    if (!defined('SITEPULSE_OPTION_RUM_SETTINGS')) {
        return $defaults;
    }

    $stored = get_option(SITEPULSE_OPTION_RUM_SETTINGS, []);

    if (!is_array($stored)) {
        $stored = [];
    }

    $settings = array_merge($defaults, array_intersect_key($stored, $defaults));

    if ($settings['enabled'] && $settings['token'] === '') {
        $settings['token'] = sitepulse_rum_generate_token();
        update_option(SITEPULSE_OPTION_RUM_SETTINGS, $settings, false);
    }

    return $settings;
}

/**
 * Updates the RUM settings.
 *
 * @param array<string,mixed> $settings Settings payload.
 * @return void
 */
function sitepulse_rum_update_settings(array $settings) {
    if (!defined('SITEPULSE_OPTION_RUM_SETTINGS')) {
        return;
    }

    $current = sitepulse_rum_get_settings();
    $merged  = array_merge($current, array_intersect_key($settings, $current));

    update_option(SITEPULSE_OPTION_RUM_SETTINGS, $merged, false);
}

/**
 * Generates a random ingestion token.
 *
 * @return string
 */
function sitepulse_rum_generate_token() {
    if (function_exists('wp_generate_password')) {
        return wp_generate_password(32, false);
    }

    return bin2hex(random_bytes(16));
}

/**
 * Retrieves the configured ingestion token, optionally generating one.
 *
 * @param bool $create_when_missing Whether a token should be generated when absent.
 * @return string
 */
function sitepulse_rum_get_token($create_when_missing = false) {
    $settings = sitepulse_rum_get_settings();

    if ($settings['token'] !== '') {
        return (string) $settings['token'];
    }

    if (!$create_when_missing) {
        return '';
    }

    $settings['token'] = sitepulse_rum_generate_token();
    update_option(SITEPULSE_OPTION_RUM_SETTINGS, $settings, false);

    return (string) $settings['token'];
}

/**
 * Determines whether the current visitor has granted consent for RUM collection.
 *
 * @return bool
 */
function sitepulse_rum_user_has_consent() {
    $settings = sitepulse_rum_get_settings();

    if (empty($settings['consent_required'])) {
        return true;
    }

    $consent = false;

    if (function_exists('apply_filters')) {
        /**
         * Filters whether the current visitor has granted consent for RUM collection.
         *
         * Return true once the visitor has accepted analytics cookies or an equivalent consent banner.
         *
         * @param bool  $consent  Whether consent was granted.
         * @param array $settings RUM settings.
         */
        $consent = (bool) apply_filters('sitepulse_rum_user_has_consent', $consent, $settings);
    }

    return $consent;
}

/**
 * Registers the REST API routes used by the RUM feature.
 *
 * @return void
 */
function sitepulse_rum_register_rest_routes() {
    register_rest_route(
        'sitepulse/v1',
        '/rum',
        [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => 'sitepulse_rum_rest_ingest_metrics',
            'permission_callback' => '__return_true',
            'args'                => [
                'token'   => [
                    'type'     => 'string',
                    'required' => true,
                ],
                'metrics' => [
                    'type'     => 'array',
                    'required' => true,
                ],
            ],
        ]
    );

    register_rest_route(
        'sitepulse/v1',
        '/rum/aggregates',
        [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => 'sitepulse_rum_rest_get_aggregates',
            'permission_callback' => 'sitepulse_rum_rest_require_capability',
            'args'                => [
                'days' => [
                    'type'              => 'integer',
                    'required'          => false,
                    'validate_callback' => static function ($value) {
                        return is_numeric($value) && (int) $value > 0;
                    },
                ],
            ],
        ]
    );
}

/**
 * REST permission callback ensuring the requester can manage the plugin.
 *
 * @return bool
 */
function sitepulse_rum_rest_require_capability() {
    if (!function_exists('sitepulse_get_capability')) {
        return current_user_can('manage_options');
    }

    return current_user_can(sitepulse_get_capability());
}

/**
 * Handles RUM ingestion requests.
 *
 * @param \WP_REST_Request $request Incoming request.
 * @return \WP_REST_Response|\WP_Error
 */
function sitepulse_rum_rest_ingest_metrics($request) {
    if (!sitepulse_rum_is_enabled()) {
        return new \WP_Error('sitepulse_rum_disabled', __('La collecte RUM est désactivée.', 'sitepulse'), [
            'status' => 403,
        ]);
    }

    $params = $request->get_json_params();

    if (!is_array($params) || empty($params)) {
        $params = $request->get_body_params();
    }

    $token = isset($params['token']) ? sanitize_text_field($params['token']) : '';
    $expected_token = sitepulse_rum_get_token(false);

    if ($expected_token === '' || $token === '' || !hash_equals($expected_token, $token)) {
        return new \WP_Error('sitepulse_rum_invalid_token', __('La vérification du jeton a échoué.', 'sitepulse'), [
            'status' => 403,
        ]);
    }

    $metrics = isset($params['metrics']) ? $params['metrics'] : [];

    if (!is_array($metrics) || empty($metrics)) {
        return new \WP_Error('sitepulse_rum_invalid_payload', __('Aucune mesure valide fournie.', 'sitepulse'), [
            'status' => 400,
        ]);
    }

    $max_batch = 50;

    if (function_exists('apply_filters')) {
        /**
         * Filters the maximum number of metrics accepted per ingestion request.
         *
         * @param int $max_batch Maximum metrics per request.
         */
        $max_batch = (int) apply_filters('sitepulse_rum_max_metrics_per_request', $max_batch);
    }

    $max_batch = max(1, $max_batch);

    $stored = 0;
    $processed = 0;

    foreach ($metrics as $metric) {
        if ($processed >= $max_batch) {
            break;
        }

        $processed++;

        if (!is_array($metric)) {
            continue;
        }

        if (sitepulse_rum_store_metric($metric)) {
            $stored++;
        }
    }

    if ($stored === 0) {
        return new \WP_Error('sitepulse_rum_store_failed', __('Impossible d’enregistrer les mesures.', 'sitepulse'), [
            'status' => 400,
        ]);
    }

    sitepulse_rum_apply_retention();
    sitepulse_rum_clear_cache();

    return rest_ensure_response([
        'stored'    => $stored,
        'processed' => $processed,
    ]);
}

/**
 * Returns aggregated RUM metrics for the requested window.
 *
 * @param \WP_REST_Request $request Incoming request.
 * @return \WP_REST_Response
 */
function sitepulse_rum_rest_get_aggregates($request) {
    $days = (int) $request->get_param('days');

    if ($days <= 0) {
        $days = 7;
    }

    $aggregates = sitepulse_rum_get_aggregates([
        'days' => $days,
    ]);

    return rest_ensure_response($aggregates);
}

/**
 * Handles the admin submission of RUM settings.
 *
 * @return void
 */
function sitepulse_rum_handle_settings_post() {
    if (!sitepulse_rum_rest_require_capability()) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour modifier cette configuration.", 'sitepulse'));
    }

    $nonce_action = defined('SITEPULSE_NONCE_ACTION_RUM_SETTINGS') ? SITEPULSE_NONCE_ACTION_RUM_SETTINGS : 'sitepulse_rum_settings';

    check_admin_referer($nonce_action);

    $enabled = isset($_POST['sitepulse_rum_enabled']);
    $consent_required = isset($_POST['sitepulse_rum_consent_required']);
    $regenerate = isset($_POST['sitepulse_rum_regenerate']);

    $settings = sitepulse_rum_get_settings();
    $settings['enabled'] = (bool) $enabled;
    $settings['consent_required'] = (bool) $consent_required;

    if ($regenerate || ($settings['enabled'] && $settings['token'] === '')) {
        $settings['token'] = sitepulse_rum_generate_token();
    }

    sitepulse_rum_update_settings($settings);
    sitepulse_rum_clear_cache();

    $redirect_url = admin_url('admin.php?page=sitepulse-speed');
    $redirect_url = add_query_arg('sitepulse-rum-updated', $settings['enabled'] ? '1' : '0', $redirect_url);

    if ($regenerate) {
        $redirect_url = add_query_arg('sitepulse-rum-token', 'regenerated', $redirect_url);
    }

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Registers the frontend assets used to capture Web Vitals.
 *
 * @return void
 */
function sitepulse_rum_enqueue_assets() {
    if (is_admin() || !sitepulse_rum_is_enabled()) {
        return;
    }

    $settings = sitepulse_rum_get_settings();

    if (empty($settings['enabled'])) {
        return;
    }

    $token = sitepulse_rum_get_token(true);

    if ($token === '') {
        return;
    }

    $handle = 'sitepulse-rum';
    $version = defined('SITEPULSE_VERSION') ? SITEPULSE_VERSION : false;

    wp_register_script(
        $handle,
        SITEPULSE_URL . 'modules/js/sitepulse-rum.js',
        [],
        $version,
        true
    );

    $config = sitepulse_rum_get_frontend_config($settings);

    wp_localize_script($handle, 'SitePulseRUMConfig', $config);

    wp_enqueue_script($handle);
}

/**
 * Builds the configuration passed to the frontend collector.
 *
 * @param array<string,mixed> $settings Current settings.
 * @return array<string,mixed>
 */
function sitepulse_rum_get_frontend_config(array $settings) {
    $device = 'desktop';

    if (function_exists('wp_is_mobile') && wp_is_mobile()) {
        $device = 'mobile';
    }

    $consent_granted = sitepulse_rum_user_has_consent();

    $config = [
        'enabled'         => true,
        'token'           => sitepulse_rum_get_token(true),
        'restUrl'         => esc_url_raw(rest_url('sitepulse/v1/rum')),
        'device'          => $device,
        'consentRequired' => !empty($settings['consent_required']),
        'consentGranted'  => $consent_granted,
        'flushDelay'      => 4000,
        'batchSize'       => 6,
        'debug'           => defined('SITEPULSE_DEBUG') ? (bool) SITEPULSE_DEBUG : false,
        'locale'          => get_locale(),
    ];

    if (function_exists('apply_filters')) {
        /**
         * Filters the frontend RUM configuration before it is exposed to the collector script.
         *
         * @param array $config   Localized configuration values.
         * @param array $settings Current RUM settings.
         */
        $config = (array) apply_filters('sitepulse_rum_frontend_config', $config, $settings);
    }

    return $config;
}

/**
 * Stores a single metric entry in the database.
 *
 * @param array<string,mixed> $metric Metric payload.
 * @return bool
 */
function sitepulse_rum_store_metric(array $metric) {
    $table = sitepulse_rum_get_table_name();

    if ($table === '' || !sitepulse_rum_table_exists()) {
        return false;
    }

    $name = isset($metric['name']) ? strtoupper((string) $metric['name']) : '';

    if (!in_array($name, ['LCP', 'FID', 'CLS'], true)) {
        return false;
    }

    $value = isset($metric['value']) ? (float) $metric['value'] : null;

    if (!is_finite($value) || $value < 0) {
        return false;
    }

    $timestamp = isset($metric['timestamp']) ? (int) $metric['timestamp'] : 0;

    if ($timestamp > 0 && $timestamp > 2000000000) {
        $timestamp = (int) floor($timestamp / 1000);
    }

    if ($timestamp <= 0) {
        $timestamp = time();
    }

    $page = isset($metric['url']) ? (string) $metric['url'] : '';

    if ($page === '' && isset($metric['path'])) {
        $page = (string) $metric['path'];
    }

    if ($page === '' && isset($metric['page'])) {
        $page = (string) $metric['page'];
    }

    $page_path = sitepulse_rum_normalize_path($page);

    $rating = isset($metric['rating']) ? strtolower((string) $metric['rating']) : '';

    if (!in_array($rating, ['good', 'needs_improvement', 'poor'], true)) {
        $rating = '';
    }

    $device = isset($metric['device']) ? strtolower((string) $metric['device']) : '';

    if (!in_array($device, ['mobile', 'desktop', 'tablet'], true)) {
        $device = '';
    }

    $connection = isset($metric['connection']) ? strtolower((string) $metric['connection']) : '';
    $navigation = isset($metric['navigationType']) ? strtolower((string) $metric['navigationType']) : '';

    $samples = isset($metric['samples']) ? (int) $metric['samples'] : 0;

    if ($samples < 0) {
        $samples = 0;
    }

    $data = [
        'recorded_at' => $timestamp,
        'metric'      => $name,
        'value'       => $value,
        'rating'      => $rating,
        'page_url'    => $page,
        'page_path'   => $page_path,
        'device'      => $device,
        'connection'  => $connection,
        'navigation'  => $navigation,
        'samples'     => $samples,
        'created_at'  => current_time('mysql', true),
    ];

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return false;
    }

    $format = ['%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'];

    $result = $wpdb->insert($table, $data, $format);

    return $result !== false;
}

/**
 * Normalizes a page path for storage and grouping.
 *
 * @param string $raw_path Raw path or URL.
 * @return string
 */
function sitepulse_rum_normalize_path($raw_path) {
    $raw_path = is_string($raw_path) ? trim($raw_path) : '';

    if ($raw_path === '') {
        return '/';
    }

    $parsed = wp_parse_url($raw_path, PHP_URL_PATH);

    if (!is_string($parsed) || $parsed === '') {
        $parsed = $raw_path;
    }

    $parsed = '/' . ltrim($parsed, '/');

    return substr($parsed, 0, 191);
}

/**
 * Applies the retention policy to stored metrics.
 *
 * @return void
 */
function sitepulse_rum_apply_retention() {
    $table = sitepulse_rum_get_table_name();

    if ($table === '' || !sitepulse_rum_table_exists()) {
        return;
    }

    $days = sitepulse_rum_get_retention_days();

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
 * Retrieves the retention window for RUM metrics.
 *
 * @return int
 */
function sitepulse_rum_get_retention_days() {
    $default = defined('SITEPULSE_DEFAULT_RUM_RETENTION_DAYS') ? (int) SITEPULSE_DEFAULT_RUM_RETENTION_DAYS : 30;

    if (!defined('SITEPULSE_OPTION_RUM_RETENTION_DAYS')) {
        return $default;
    }

    $value = get_option(SITEPULSE_OPTION_RUM_RETENTION_DAYS, $default);

    if (!is_numeric($value)) {
        return $default;
    }

    $days = max(1, (int) $value);

    if (function_exists('apply_filters')) {
        /**
         * Filters the number of days to keep RUM metrics.
         *
         * @param int $days Retention window.
         */
        $days = (int) apply_filters('sitepulse_rum_retention_days', $days);
    }

    return $days;
}

/**
 * Returns the name of the RUM metrics table.
 *
 * @return string
 */
function sitepulse_rum_get_table_name() {
    if (!defined('SITEPULSE_TABLE_RUM_METRICS')) {
        return '';
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return '';
    }

    return $wpdb->prefix . SITEPULSE_TABLE_RUM_METRICS;
}

/**
 * Checks whether the RUM table exists, optionally forcing a refresh.
 *
 * @param bool $force_refresh Whether to refresh the cached status.
 * @return bool
 */
function sitepulse_rum_table_exists($force_refresh = false) {
    static $exists = null;

    if ($force_refresh) {
        $exists = null;
    }

    if ($exists !== null) {
        return $exists;
    }

    $table = sitepulse_rum_get_table_name();

    if ($table === '') {
        $exists = false;

        return false;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        $exists = false;

        return false;
    }

    $query = $wpdb->prepare('SHOW TABLES LIKE %s', $table);
    $found = $wpdb->get_var($query);

    $exists = ($found === $table);

    return $exists;
}

/**
 * Ensures the RUM metrics table exists and matches the expected schema.
 *
 * @return void
 */
function sitepulse_rum_maybe_upgrade_schema() {
    if (!defined('SITEPULSE_RUM_SCHEMA_VERSION') || !defined('SITEPULSE_OPTION_RUM_SCHEMA_VERSION')) {
        return;
    }

    $target  = (int) SITEPULSE_RUM_SCHEMA_VERSION;
    $current = (int) get_option(SITEPULSE_OPTION_RUM_SCHEMA_VERSION, 0);

    if ($current >= $target && sitepulse_rum_table_exists()) {
        return;
    }

    sitepulse_rum_install_table();

    if ($current < $target) {
        update_option(SITEPULSE_OPTION_RUM_SCHEMA_VERSION, $target, false);
    }
}

/**
 * Creates the RUM metrics table.
 *
 * @return void
 */
function sitepulse_rum_install_table() {
    $table = sitepulse_rum_get_table_name();

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
        recorded_at int(10) unsigned NOT NULL,
        metric varchar(20) NOT NULL,
        value double NOT NULL,
        rating varchar(20) NOT NULL DEFAULT '',
        page_url text NULL,
        page_path varchar(191) NOT NULL,
        device varchar(20) NOT NULL DEFAULT '',
        connection varchar(50) NOT NULL DEFAULT '',
        navigation varchar(20) NOT NULL DEFAULT '',
        samples smallint(5) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY recorded_at (recorded_at),
        KEY metric (metric),
        KEY page_metric (page_path, metric)
    ) {$charset};";

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta($sql);

    sitepulse_rum_table_exists(true);
}

/**
 * Computes aggregated statistics over the stored metrics.
 *
 * @param array<string,int> $args Aggregation arguments.
 * @return array<string,mixed>
 */
function sitepulse_rum_get_aggregates(array $args = []) {
    $days = isset($args['days']) ? (int) $args['days'] : 7;
    $days = max(1, $days);

    $cache_key = defined('SITEPULSE_TRANSIENT_RUM_AGGREGATES')
        ? SITEPULSE_TRANSIENT_RUM_AGGREGATES . '_' . $days
        : 'sitepulse_rum_aggregates_' . $days;

    $cached = get_transient($cache_key);

    if (is_array($cached)) {
        return $cached;
    }

    $table = sitepulse_rum_get_table_name();

    if ($table === '' || !sitepulse_rum_table_exists()) {
        return [
            'window'  => [
                'days'    => $days,
                'samples' => 0,
            ],
            'metrics' => [],
            'pages'   => [],
        ];
    }

    $limit = isset($args['limit']) ? (int) $args['limit'] : 3000;
    $limit = max(1, $limit);
    $since = time() - ($days * DAY_IN_SECONDS);

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return [
            'window'  => [
                'days'    => $days,
                'samples' => 0,
            ],
            'metrics' => [],
            'pages'   => [],
        ];
    }

    $sql = $wpdb->prepare(
        "SELECT metric, value, rating, page_path, device, connection, navigation, samples, recorded_at
        FROM {$table}
        WHERE recorded_at >= %d
        ORDER BY recorded_at DESC
        LIMIT %d",
        $since,
        $limit
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);

    $totals = [
        'window'  => [
            'days'    => $days,
            'samples' => 0,
            'since'   => $since,
        ],
        'metrics' => [],
        'pages'   => [],
    ];

    if (empty($rows)) {
        set_transient($cache_key, $totals, HOUR_IN_SECONDS);

        return $totals;
    }

    foreach ($rows as $row) {
        $metric = isset($row['metric']) ? strtoupper($row['metric']) : '';
        $value  = isset($row['value']) ? (float) $row['value'] : null;

        if ($metric === '' || !is_finite($value)) {
            continue;
        }

        $totals['window']['samples']++;

        if (!isset($totals['metrics'][$metric])) {
            $totals['metrics'][$metric] = [
                'values'  => [],
                'count'   => 0,
                'ratings' => [
                    'good'             => 0,
                    'needs_improvement'=> 0,
                    'poor'             => 0,
                ],
            ];
        }

        $totals['metrics'][$metric]['values'][] = $value;
        $totals['metrics'][$metric]['count']++;

        $rating = isset($row['rating']) ? strtolower($row['rating']) : '';

        if (isset($totals['metrics'][$metric]['ratings'][$rating])) {
            $totals['metrics'][$metric]['ratings'][$rating]++;
        }

        $page = isset($row['page_path']) && $row['page_path'] !== '' ? $row['page_path'] : '/';

        if (!isset($totals['pages'][$page])) {
            $totals['pages'][$page] = [
                'samples' => 0,
                'metrics' => [],
            ];
        }

        if (!isset($totals['pages'][$page]['metrics'][$metric])) {
            $totals['pages'][$page]['metrics'][$metric] = [
                'values' => [],
                'count'  => 0,
            ];
        }

        $totals['pages'][$page]['metrics'][$metric]['values'][] = $value;
        $totals['pages'][$page]['metrics'][$metric]['count']++;
        $totals['pages'][$page]['samples']++;
    }

    foreach ($totals['metrics'] as $metric => $data) {
        $totals['metrics'][$metric] = sitepulse_rum_summarize_values($data['values'], $data['ratings']);
    }

    $page_summaries = [];

    foreach ($totals['pages'] as $path => $data) {
        $metrics = [];

        foreach ($data['metrics'] as $metric => $metric_data) {
            $metrics[$metric] = sitepulse_rum_summarize_values($metric_data['values']);
        }

        $page_summaries[] = [
            'path'    => $path,
            'samples' => $data['samples'],
            'metrics' => $metrics,
        ];
    }

    usort($page_summaries, static function ($a, $b) {
        return $b['samples'] <=> $a['samples'];
    });

    $page_summaries = array_slice($page_summaries, 0, 10);

    $totals['pages'] = $page_summaries;

    set_transient($cache_key, $totals, HOUR_IN_SECONDS);

    return $totals;
}

/**
 * Summarizes a list of metric values into descriptive statistics.
 *
 * @param array<int,float>      $values  Recorded values.
 * @param array<string,int>|null $ratings Optional rating counters.
 * @return array<string,mixed>
 */
function sitepulse_rum_summarize_values(array $values, $ratings = null) {
    $values = array_values(array_filter(array_map('floatval', $values), static function ($value) {
        return is_finite($value);
    }));

    $count = count($values);

    if ($count === 0) {
        return [
            'count'   => 0,
            'average' => 0,
            'p75'     => 0,
            'p95'     => 0,
            'ratings' => $ratings ?: [],
        ];
    }

    sort($values);

    $average = array_sum($values) / $count;
    $p75 = sitepulse_rum_calculate_percentile($values, 0.75);
    $p95 = sitepulse_rum_calculate_percentile($values, 0.95);

    if (is_array($ratings)) {
        $total_ratings = array_sum($ratings);

        if ($total_ratings > 0) {
            foreach ($ratings as $key => $value) {
                $ratings[$key] = round(($value / $total_ratings) * 100, 2);
            }
        }
    }

    return [
        'count'   => $count,
        'average' => $average,
        'p75'     => $p75,
        'p95'     => $p95,
        'ratings' => is_array($ratings) ? $ratings : [],
    ];
}

/**
 * Calculates a percentile for the provided values.
 *
 * @param array<int,float> $values   Sorted values.
 * @param float            $percent  Percentile to compute between 0 and 1.
 * @return float
 */
function sitepulse_rum_calculate_percentile(array $values, $percent) {
    $count = count($values);

    if ($count === 0) {
        return 0.0;
    }

    $percent = max(0, min(1, (float) $percent));

    $index = ($count - 1) * $percent;
    $lower = (int) floor($index);
    $upper = (int) ceil($index);

    if ($lower === $upper) {
        return (float) $values[$lower];
    }

    $weight = $index - $lower;

    return (float) ($values[$lower] * (1 - $weight) + $values[$upper] * $weight);
}

/**
 * Clears cached aggregate data.
 *
 * @return void
 */
function sitepulse_rum_clear_cache() {
    $base = defined('SITEPULSE_TRANSIENT_RUM_AGGREGATES')
        ? SITEPULSE_TRANSIENT_RUM_AGGREGATES
        : 'sitepulse_rum_aggregates';

    delete_transient($base . '_7');
    delete_transient($base . '_30');
}

/**
 * Retrieves the latest aggregated metrics for display inside the admin UI.
 *
 * @return array<string,mixed>
 */
function sitepulse_rum_get_admin_summary() {
    $summary = sitepulse_rum_get_aggregates([
        'days' => 7,
    ]);

    return is_array($summary) ? $summary : [];
}
