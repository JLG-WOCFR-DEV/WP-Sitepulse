<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight Real User Monitoring (Web Vitals) helpers.
 */

/**
 * Boots the RUM services (REST API, storage, frontend script).
 *
 * @return void
 */
function sitepulse_rum_bootstrap() {
    add_action('init', 'sitepulse_rum_maybe_upgrade_schema');
    add_action('rest_api_init', 'sitepulse_rum_register_rest_routes');
    add_action('init', 'sitepulse_rum_schedule_cleanup');
    add_action('wp_enqueue_scripts', 'sitepulse_rum_enqueue_frontend_assets');

    if (defined('SITEPULSE_CRON_RUM_CLEANUP')) {
        add_action(SITEPULSE_CRON_RUM_CLEANUP, 'sitepulse_rum_purge_old_entries');
    }
}

/**
 * Retrieves and normalizes the stored RUM settings.
 *
 * @return array<string,mixed>
 */
function sitepulse_rum_get_settings() {
    $defaults = [
        'enabled'         => false,
        'require_consent' => false,
        'sample_rate'     => 1.0,
        'range_days'      => 7,
    ];

    $option_key = defined('SITEPULSE_OPTION_RUM_SETTINGS') ? SITEPULSE_OPTION_RUM_SETTINGS : 'sitepulse_rum_settings';
    $raw = get_option($option_key, []);

    if (!is_array($raw)) {
        $raw = [];
    }

    $normalized = $defaults;

    if (isset($raw['enabled'])) {
        $normalized['enabled'] = rest_sanitize_boolean($raw['enabled']);
    }

    if (isset($raw['require_consent'])) {
        $normalized['require_consent'] = rest_sanitize_boolean($raw['require_consent']);
    }

    if (isset($raw['sample_rate']) && is_numeric($raw['sample_rate'])) {
        $normalized['sample_rate'] = min(1.0, max(0.0, (float) $raw['sample_rate']));
    }

    if (isset($raw['range_days']) && is_numeric($raw['range_days'])) {
        $normalized['range_days'] = (int) $raw['range_days'];
    }

    if ($normalized['range_days'] < 1) {
        $normalized['range_days'] = 7;
    }

    /**
     * Filters the RUM settings used by SitePulse.
     *
     * @param array<string,mixed> $normalized Normalized settings.
     * @param array<string,mixed> $raw        Raw option value.
     */
    return apply_filters('sitepulse_rum_settings', $normalized, $raw);
}

/**
 * Determines whether RUM collection is enabled.
 *
 * @return bool
 */
function sitepulse_rum_is_enabled() {
    if (!function_exists('sitepulse_is_module_active') || !sitepulse_is_module_active('speed_analyzer')) {
        return false;
    }

    $settings = sitepulse_rum_get_settings();

    $enabled = !empty($settings['enabled']);

    /**
     * Filters the activation flag of the RUM collector.
     *
     * @param bool                  $enabled  Whether RUM is enabled.
     * @param array<string,mixed>   $settings Current settings.
     */
    return (bool) apply_filters('sitepulse_rum_enabled', $enabled, $settings);
}

/**
 * Determines whether the current visitor granted consent when required.
 *
 * @param array<string,mixed>|null $settings Optional. Preloaded settings.
 *
 * @return bool
 */
function sitepulse_rum_has_consent($settings = null) {
    if ($settings === null) {
        $settings = sitepulse_rum_get_settings();
    }

    $require_consent = !empty($settings['require_consent']);
    $has_consent = true;

    if ($require_consent) {
        $has_consent = isset($_COOKIE['sitepulse_rum_consent']) && $_COOKIE['sitepulse_rum_consent'] === '1';
    }

    /**
     * Filters whether the current visitor granted consent to collect RUM metrics.
     *
     * @param bool                  $has_consent Whether consent has been provided.
     * @param array<string,mixed>   $settings    Current settings.
     */
    return (bool) apply_filters('sitepulse_rum_has_consent', $has_consent, $settings);
}

/**
 * Retrieves or creates the ingest token shared with the frontend script.
 *
 * @param bool $force_refresh Optional. When true, regenerates the token.
 *
 * @return string
 */
function sitepulse_rum_get_ingest_token($force_refresh = false) {
    $option_key = defined('SITEPULSE_OPTION_RUM_INGEST_TOKEN') ? SITEPULSE_OPTION_RUM_INGEST_TOKEN : 'sitepulse_rum_ingest_token';
    $token = get_option($option_key, '');

    if (!is_string($token)) {
        $token = '';
    }

    if ($token === '' || $force_refresh) {
        $token = wp_generate_password(48, false, false);
        update_option($option_key, $token, false);
    }

    return $token;
}

/**
 * Enqueues the frontend script that captures Web Vitals when enabled.
 *
 * @return void
 */
function sitepulse_rum_enqueue_frontend_assets() {
    if (is_admin()) {
        return;
    }

    if (!sitepulse_rum_is_enabled()) {
        return;
    }

    $settings = sitepulse_rum_get_settings();

    if (!sitepulse_rum_has_consent($settings)) {
        return;
    }

    $handle = 'sitepulse-rum-web-vitals';
    $src = SITEPULSE_URL . 'modules/js/sitepulse-rum-web-vitals.js';
    $version = defined('SITEPULSE_VERSION') ? SITEPULSE_VERSION : false;

    if (!wp_script_is($handle, 'registered')) {
        wp_register_script($handle, $src, [], $version, true);
    }

    $config = sitepulse_rum_get_frontend_payload($settings);
    $config_json = wp_json_encode($config);

    if (false === $config_json) {
        $config_json = '{}';
    }

    wp_add_inline_script($handle, 'window.SitePulseRum=' . $config_json . ';', 'before');
    wp_enqueue_script($handle);
}

/**
 * Builds the configuration passed to the frontend collector.
 *
 * @param array<string,mixed>|null $settings Optional. Preloaded settings.
 *
 * @return array<string,mixed>
 */
function sitepulse_rum_get_frontend_payload($settings = null) {
    if ($settings === null) {
        $settings = sitepulse_rum_get_settings();
    }

    $payload = [
        'enabled'         => sitepulse_rum_is_enabled(),
        'endpoint'        => rest_url('sitepulse/v1/rum'),
        'token'           => sitepulse_rum_get_ingest_token(),
        'sampleRate'      => isset($settings['sample_rate']) ? (float) $settings['sample_rate'] : 1.0,
        'rangeDays'       => isset($settings['range_days']) ? (int) $settings['range_days'] : 7,
        'requiresConsent' => !empty($settings['require_consent']),
        'deviceHint'      => function_exists('wp_is_mobile') && wp_is_mobile() ? 'mobile' : 'desktop',
        'site'            => [
            'url'   => home_url('/'),
            'title' => get_bloginfo('name'),
        ],
    ];

    /**
     * Filters the frontend payload consumed by the Web Vitals collector.
     *
     * @param array<string,mixed> $payload  Payload passed to JS.
     * @param array<string,mixed> $settings Current settings.
     */
    return apply_filters('sitepulse_rum_frontend_payload', $payload, $settings);
}

/**
 * Registers the REST API endpoints used by the RUM module.
 *
 * @return void
 */
function sitepulse_rum_register_rest_routes() {
    register_rest_route(
        'sitepulse/v1',
        '/rum',
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'sitepulse_rum_rest_ingest',
            'permission_callback' => '__return_true',
            'args'                => [
                'token'   => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'samples' => [
                    'required'          => true,
                ],
            ],
        ]
    );

    register_rest_route(
        'sitepulse/v1',
        '/rum/aggregates',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'sitepulse_rum_rest_get_aggregates',
            'permission_callback' => 'sitepulse_rum_rest_permission_check',
            'args'                => [
                'range' => [
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                ],
                'path' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'device' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]
    );
}

/**
 * Ensures only privileged users can access the aggregate endpoint.
 *
 * @return bool
 */
function sitepulse_rum_rest_permission_check() {
    return current_user_can(sitepulse_get_capability());
}

/**
 * Handles ingestion of Web Vitals samples from the frontend collector.
 *
 * @param WP_REST_Request $request Request instance.
 *
 * @return WP_REST_Response|WP_Error
 */
function sitepulse_rum_rest_ingest($request) {
    if (!sitepulse_rum_is_enabled()) {
        return new WP_Error('sitepulse_rum_disabled', __('La collecte RUM est désactivée.', 'sitepulse'), ['status' => 403]);
    }

    $token = (string) $request->get_param('token');
    $expected_token = sitepulse_rum_get_ingest_token();

    if ($expected_token === '' || !hash_equals($expected_token, $token)) {
        return new WP_Error('sitepulse_rum_invalid_token', __('Jeton de collecte invalide.', 'sitepulse'), ['status' => 401]);
    }

    $raw_samples = $request->get_param('samples');

    if (!is_array($raw_samples)) {
        return new WP_Error('sitepulse_rum_invalid_payload', __('Format de données invalide.', 'sitepulse'), ['status' => 400]);
    }

    $max_samples = 50;
    $normalized = [];

    foreach ($raw_samples as $sample) {
        if (count($normalized) >= $max_samples) {
            break;
        }

        if (!is_array($sample)) {
            continue;
        }

        $normalized_sample = sitepulse_rum_build_sample($sample, 'rest');

        if ($normalized_sample !== null) {
            $normalized[] = $normalized_sample;
        }
    }

    if (empty($normalized)) {
        return new WP_Error('sitepulse_rum_empty_batch', __('Aucune mesure valide à enregistrer.', 'sitepulse'), ['status' => 400]);
    }

    $stored = sitepulse_rum_store_samples($normalized);

    if ($stored === 0) {
        return new WP_Error('sitepulse_rum_storage_failed', __('Impossible d’enregistrer les mesures.', 'sitepulse'), ['status' => 500]);
    }

    $response = [
        'stored'       => $stored,
        'received'     => count($normalized),
        'retentionDays'=> sitepulse_rum_get_retention_days(),
        'timestamp'    => current_time('timestamp'),
    ];

    return rest_ensure_response($response);
}

/**
 * Returns aggregated RUM statistics.
 *
 * @param WP_REST_Request $request Request instance.
 *
 * @return WP_REST_Response
 */
function sitepulse_rum_rest_get_aggregates($request) {
    $range_days = (int) $request->get_param('range');
    $path = (string) $request->get_param('path');
    $device = (string) $request->get_param('device');

    $aggregates = sitepulse_rum_calculate_aggregates([
        'range_days' => $range_days > 0 ? $range_days : null,
        'path'       => $path,
        'device'     => $device,
    ]);

    return rest_ensure_response($aggregates);
}

/**
 * Persists a batch of sanitized samples.
 *
 * @param array<int,array<string,mixed>> $samples Normalized samples.
 *
 * @return int Number of stored rows.
 */
function sitepulse_rum_store_samples(array $samples) {
    $table = sitepulse_rum_get_table_name();

    if ($table === '' || empty($samples)) {
        return 0;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return 0;
    }

    $inserted = 0;
    $created_at = current_time('mysql', true);

    foreach ($samples as $sample) {
        $data = [
            'recorded_at'     => isset($sample['recorded_at']) ? (int) $sample['recorded_at'] : time(),
            'metric'          => isset($sample['metric']) ? (string) $sample['metric'] : '',
            'value'           => isset($sample['value']) ? (float) $sample['value'] : 0.0,
            'rating'          => isset($sample['rating']) ? (string) $sample['rating'] : 'unknown',
            'path'            => isset($sample['path']) ? (string) $sample['path'] : '/',
            'path_hash'       => isset($sample['path_hash']) ? (string) $sample['path_hash'] : md5('/'),
            'device'          => isset($sample['device']) ? (string) $sample['device'] : 'unknown',
            'navigation_type' => isset($sample['navigation_type']) ? (string) $sample['navigation_type'] : 'navigate',
            'created_at'      => $created_at,
        ];

        $formats = ['%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s'];

        $result = $wpdb->insert($table, $data, $formats);

        if ($result) {
            $inserted++;
        }
    }

    return $inserted;
}

/**
 * Normalizes a raw sample payload.
 *
 * @param array<string,mixed> $sample  Raw sample data.
 * @param string              $context Optional. Context identifier.
 *
 * @return array<string,mixed>|null
 */
function sitepulse_rum_build_sample(array $sample, $context = 'rest') {
    $metric = isset($sample['metric']) ? strtoupper((string) $sample['metric']) : '';

    if (!in_array($metric, ['LCP', 'FID', 'CLS'], true)) {
        return null;
    }

    $value = null;

    if (isset($sample['value']) && is_numeric($sample['value'])) {
        $value = (float) $sample['value'];
    }

    if ($value === null || $value < 0) {
        return null;
    }

    $path = '/';

    if (!empty($sample['path']) && is_string($sample['path'])) {
        $path = '/' . ltrim($sample['path'], '/');
    } elseif (!empty($sample['url']) && is_string($sample['url'])) {
        $parsed = wp_parse_url($sample['url']);
        $path = isset($parsed['path']) ? (string) $parsed['path'] : '/';
        if (isset($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }
    }

    if ($path === '') {
        $path = '/';
    }

    $path = esc_url_raw($path);
    $path_hash = md5($path);

    $device = 'unknown';

    if (!empty($sample['device']) && is_string($sample['device'])) {
        $device_candidate = sanitize_key($sample['device']);
        if ($device_candidate !== '') {
            $device = $device_candidate;
        }
    }

    $navigation_type = 'navigate';

    if (!empty($sample['navigationType']) && is_string($sample['navigationType'])) {
        $navigation_type = sanitize_key($sample['navigationType']);
    }

    $rating = null;

    if (!empty($sample['rating']) && is_string($sample['rating'])) {
        $candidate = sanitize_key(str_replace([' ', '-'], '_', strtolower($sample['rating'])));
        if (in_array($candidate, ['good', 'needs_improvement', 'poor'], true)) {
            $rating = $candidate;
        }
    }

    if ($rating === null) {
        $rating = sitepulse_rum_grade_metric($metric, $value);
    }

    $timestamp = null;

    if (isset($sample['timestamp']) && is_numeric($sample['timestamp'])) {
        $timestamp = (int) $sample['timestamp'];
    } elseif (isset($sample['recorded_at']) && is_numeric($sample['recorded_at'])) {
        $timestamp = (int) $sample['recorded_at'];
    }

    if ($timestamp === null || $timestamp <= 0) {
        $timestamp = current_time('timestamp');
    }

    return [
        'metric'          => $metric,
        'value'           => $value,
        'rating'          => $rating,
        'path'            => $path,
        'path_hash'       => $path_hash,
        'device'          => $device,
        'navigation_type' => $navigation_type,
        'recorded_at'     => $timestamp,
    ];
}

/**
 * Computes the rating bucket for a given metric value.
 *
 * @param string $metric Metric slug.
 * @param float  $value  Observed value.
 *
 * @return string
 */
function sitepulse_rum_grade_metric($metric, $value) {
    $metric = strtoupper((string) $metric);
    $value = (float) $value;

    switch ($metric) {
        case 'LCP':
            if ($value <= 2500.0) {
                return 'good';
            }

            if ($value <= 4000.0) {
                return 'needs_improvement';
            }

            return 'poor';
        case 'FID':
            if ($value <= 100.0) {
                return 'good';
            }

            if ($value <= 300.0) {
                return 'needs_improvement';
            }

            return 'poor';
        case 'CLS':
            if ($value <= 0.1) {
                return 'good';
            }

            if ($value <= 0.25) {
                return 'needs_improvement';
            }

            return 'poor';
        default:
            return 'unknown';
    }
}

/**
 * Returns the fully qualified table name for RUM samples.
 *
 * @return string
 */
function sitepulse_rum_get_table_name() {
    if (!defined('SITEPULSE_TABLE_RUM_EVENTS')) {
        return '';
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return '';
    }

    return $wpdb->prefix . SITEPULSE_TABLE_RUM_EVENTS;
}

/**
 * Checks whether the RUM table exists.
 *
 * @param bool $force Optional. When true, bypasses the cached state.
 *
 * @return bool
 */
function sitepulse_rum_table_exists($force = false) {
    static $exists = null;

    if ($force) {
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

    $exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return $exists;
}

/**
 * Creates or upgrades the RUM datastore schema.
 *
 * @return void
 */
function sitepulse_rum_maybe_upgrade_schema() {
    if (!defined('SITEPULSE_RUM_SCHEMA_VERSION') || !defined('SITEPULSE_OPTION_RUM_SCHEMA_VERSION')) {
        return;
    }

    $target = (int) SITEPULSE_RUM_SCHEMA_VERSION;
    $option_key = SITEPULSE_OPTION_RUM_SCHEMA_VERSION;
    $current = (int) get_option($option_key, 0);

    if ($current >= $target && sitepulse_rum_table_exists()) {
        return;
    }

    sitepulse_rum_install_table();

    if ($current < $target) {
        update_option($option_key, $target, false);
    }
}

/**
 * Installs the RUM events table.
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

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        recorded_at int(10) unsigned NOT NULL,
        metric varchar(16) NOT NULL,
        value float NOT NULL,
        rating varchar(32) NOT NULL,
        path varchar(191) NOT NULL,
        path_hash char(32) NOT NULL,
        device varchar(32) NOT NULL,
        navigation_type varchar(32) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        KEY recorded_at (recorded_at),
        KEY metric (metric),
        KEY path_hash (path_hash),
        KEY device (device)
    ) {$charset_collate};";

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta($sql);

    sitepulse_rum_table_exists(true);
}

/**
 * Schedules the cleanup event used to purge old samples.
 *
 * @return void
 */
function sitepulse_rum_schedule_cleanup() {
    if (!defined('SITEPULSE_CRON_RUM_CLEANUP')) {
        return;
    }

    if (wp_next_scheduled(SITEPULSE_CRON_RUM_CLEANUP)) {
        return;
    }

    wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', SITEPULSE_CRON_RUM_CLEANUP);
}

/**
 * Purges RUM samples older than the configured retention.
 *
 * @return void
 */
function sitepulse_rum_purge_old_entries() {
    $table = sitepulse_rum_get_table_name();

    if ($table === '') {
        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $retention_days = sitepulse_rum_get_retention_days();

    if ($retention_days <= 0) {
        return;
    }

    $threshold = time() - ($retention_days * DAY_IN_SECONDS);
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE recorded_at < %d", $threshold));
}

/**
 * Retrieves the number of days samples should be retained.
 *
 * @return int
 */
function sitepulse_rum_get_retention_days() {
    $default = defined('SITEPULSE_DEFAULT_RUM_RETENTION_DAYS') ? (int) SITEPULSE_DEFAULT_RUM_RETENTION_DAYS : 30;
    $option_key = defined('SITEPULSE_OPTION_RUM_RETENTION_DAYS') ? SITEPULSE_OPTION_RUM_RETENTION_DAYS : 'sitepulse_rum_retention_days';
    $stored = get_option($option_key, $default);

    if (!is_numeric($stored)) {
        return $default;
    }

    $value = (int) $stored;

    if ($value < 1) {
        return $default;
    }

    return $value;
}

/**
 * Computes aggregated statistics for the supplied filters.
 *
 * @param array<string,mixed> $args Optional. Query arguments.
 *
 * @return array<string,mixed>
 */
function sitepulse_rum_calculate_aggregates($args = []) {
    $defaults = [
        'range_days' => null,
        'path'       => '',
        'device'     => '',
        'max_rows'   => 5000,
    ];

    $args = wp_parse_args($args, $defaults);
    $table = sitepulse_rum_get_table_name();

    if ($table === '' || !sitepulse_rum_table_exists()) {
        return [
            'range'         => ['days' => (int) $args['range_days'] ?: 7, 'since' => null, 'until' => null],
            'sample_count'  => 0,
            'page_count'    => 0,
            'last_sample_at'=> null,
            'summary'       => [],
            'pages'         => [],
        ];
    }

    $range_days = isset($args['range_days']) && is_numeric($args['range_days']) ? (int) $args['range_days'] : null;

    if ($range_days === null || $range_days <= 0) {
        $settings = sitepulse_rum_get_settings();
        $range_days = isset($settings['range_days']) ? (int) $settings['range_days'] : 7;
    }

    $range_days = max(1, min(90, $range_days));
    $since = time() - ($range_days * DAY_IN_SECONDS);

    $conditions = ['recorded_at >= %d'];
    $params = [$since];

    $path = is_string($args['path']) ? trim($args['path']) : '';
    $path_hash = '';

    if ($path !== '') {
        $path_hash = md5(esc_url_raw($path));
        $conditions[] = 'path_hash = %s';
        $params[] = $path_hash;
    }

    $device = is_string($args['device']) ? sanitize_key($args['device']) : '';

    if ($device !== '' && $device !== 'all') {
        $conditions[] = 'device = %s';
        $params[] = $device;
    }

    $where_sql = implode(' AND ', $conditions);
    $limit = isset($args['max_rows']) && is_numeric($args['max_rows']) ? (int) $args['max_rows'] : 5000;

    if ($limit < 1) {
        $limit = 5000;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return [
            'range'         => ['days' => $range_days, 'since' => null, 'until' => null],
            'sample_count'  => 0,
            'page_count'    => 0,
            'last_sample_at'=> null,
            'summary'       => [],
            'pages'         => [],
        ];
    }

    $sql = "SELECT metric, value, rating, path, path_hash, device, navigation_type, recorded_at FROM {$table} WHERE {$where_sql} ORDER BY recorded_at DESC LIMIT {$limit}";
    $query = $wpdb->prepare($sql, $params);
    $rows = $wpdb->get_results($query, ARRAY_A);

    $summary_values = [];
    $summary_ratings = [];
    $pages = [];
    $page_index = [];
    $last_sample_at = null;

    foreach ($rows as $row) {
        $metric = isset($row['metric']) ? strtoupper((string) $row['metric']) : '';

        if (!in_array($metric, ['LCP', 'FID', 'CLS'], true)) {
            continue;
        }

        $value = isset($row['value']) ? (float) $row['value'] : null;

        if ($value === null) {
            continue;
        }

        $rating = isset($row['rating']) ? sanitize_key((string) $row['rating']) : 'unknown';
        $path_value = isset($row['path']) ? (string) $row['path'] : '/';
        $hash = isset($row['path_hash']) ? (string) $row['path_hash'] : md5($path_value);
        $device_value = isset($row['device']) ? sanitize_key((string) $row['device']) : 'unknown';
        $recorded_at = isset($row['recorded_at']) ? (int) $row['recorded_at'] : 0;

        $summary_values[$metric][] = $value;
        $summary_ratings[$metric][$rating] = isset($summary_ratings[$metric][$rating])
            ? $summary_ratings[$metric][$rating] + 1
            : 1;

        $page_key = $hash . '|' . $device_value;

        if (!isset($page_index[$page_key])) {
            $page_index[$page_key] = count($pages);
            $pages[] = [
                'path'         => $path_value,
                'path_hash'    => $hash,
                'device'       => $device_value,
                'samples'      => 0,
                'latest_at'    => $recorded_at,
                'metrics'      => [],
            ];
        }

        $page_pos = $page_index[$page_key];
        $pages[$page_pos]['samples']++;
        if ($recorded_at > $pages[$page_pos]['latest_at']) {
            $pages[$page_pos]['latest_at'] = $recorded_at;
        }

        if (!isset($pages[$page_pos]['metrics'][$metric])) {
            $pages[$page_pos]['metrics'][$metric] = [
                'values'  => [],
                'ratings' => [],
            ];
        }

        $pages[$page_pos]['metrics'][$metric]['values'][] = $value;
        $pages[$page_pos]['metrics'][$metric]['ratings'][$rating] = isset($pages[$page_pos]['metrics'][$metric]['ratings'][$rating])
            ? $pages[$page_pos]['metrics'][$metric]['ratings'][$rating] + 1
            : 1;

        if ($last_sample_at === null || $recorded_at > $last_sample_at) {
            $last_sample_at = $recorded_at;
        }
    }

    $summary = [];

    foreach ($summary_values as $metric => $values) {
        sort($values);
        $count = count($values);
        $average = $count > 0 ? array_sum($values) / $count : null;
        $percentiles = sitepulse_rum_calculate_percentiles($values, [0.5, 0.75, 0.95]);

        $summary[$metric] = [
            'count'   => $count,
            'average' => $average,
            'p50'     => isset($percentiles[0.5]) ? $percentiles[0.5] : null,
            'p75'     => isset($percentiles[0.75]) ? $percentiles[0.75] : null,
            'p95'     => isset($percentiles[0.95]) ? $percentiles[0.95] : null,
            'ratings' => sitepulse_rum_normalize_rating_counts(isset($summary_ratings[$metric]) ? $summary_ratings[$metric] : []),
            'status'  => sitepulse_rum_determine_status($metric, isset($summary_ratings[$metric]) ? $summary_ratings[$metric] : [], isset($percentiles[0.75]) ? $percentiles[0.75] : null),
            'unit'    => $metric === 'CLS' ? '' : 'ms',
        ];
    }

    foreach ($pages as &$page) {
        foreach ($page['metrics'] as $metric => $metric_data) {
            $values = $metric_data['values'];
            sort($values);
            $count = count($values);
            $average = $count > 0 ? array_sum($values) / $count : null;
            $percentiles = sitepulse_rum_calculate_percentiles($values, [0.5, 0.75, 0.95]);

            $page['metrics'][$metric] = [
                'count'   => $count,
                'average' => $average,
                'p50'     => isset($percentiles[0.5]) ? $percentiles[0.5] : null,
                'p75'     => isset($percentiles[0.75]) ? $percentiles[0.75] : null,
                'p95'     => isset($percentiles[0.95]) ? $percentiles[0.95] : null,
                'ratings' => sitepulse_rum_normalize_rating_counts($metric_data['ratings']),
                'status'  => sitepulse_rum_determine_status($metric, $metric_data['ratings'], isset($percentiles[0.75]) ? $percentiles[0.75] : null),
            ];
        }
    }
    unset($page);

    usort($pages, static function ($a, $b) {
        if ($a['samples'] === $b['samples']) {
            return $b['latest_at'] <=> $a['latest_at'];
        }

        return $b['samples'] <=> $a['samples'];
    });

    $max_pages = apply_filters('sitepulse_rum_max_page_breakdown', 10, $args);

    if (is_numeric($max_pages) && $max_pages > 0) {
        $pages = array_slice($pages, 0, (int) $max_pages);
    }

    return [
        'range' => [
            'days'  => $range_days,
            'since' => $since,
            'until' => time(),
        ],
        'sample_count'   => array_sum(array_map('count', $summary_values)),
        'page_count'     => count($pages),
        'last_sample_at' => $last_sample_at,
        'summary'        => $summary,
        'pages'          => $pages,
    ];
}

/**
 * Normalizes rating counters to include all buckets.
 *
 * @param array<string,int> $ratings Raw rating counts.
 *
 * @return array<string,int>
 */
function sitepulse_rum_normalize_rating_counts($ratings) {
    $defaults = [
        'good'             => 0,
        'needs_improvement'=> 0,
        'poor'             => 0,
    ];

    if (!is_array($ratings)) {
        return $defaults;
    }

    foreach ($defaults as $bucket => $count) {
        if (isset($ratings[$bucket]) && is_numeric($ratings[$bucket])) {
            $defaults[$bucket] = (int) $ratings[$bucket];
        }
    }

    return $defaults;
}

/**
 * Calculates the requested percentiles for an ordered dataset.
 *
 * @param float[] $values     Sorted list of values.
 * @param float[] $percentile_targets Targets expressed as fractions (0-1).
 *
 * @return array<float,float>
 */
function sitepulse_rum_calculate_percentiles(array $values, array $percentile_targets) {
    $result = [];
    $count = count($values);

    if ($count === 0) {
        return $result;
    }

    sort($values);

    foreach ($percentile_targets as $target) {
        $target = (float) $target;

        if ($target < 0 || $target > 1) {
            continue;
        }

        $index = $target * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            $result[$target] = $values[$lower];
            continue;
        }

        $weight = $index - $lower;
        $result[$target] = $values[$lower] * (1 - $weight) + $values[$upper] * $weight;
    }

    return $result;
}

/**
 * Derives a status badge from rating distribution or percentile.
 *
 * @param string          $metric      Metric slug.
 * @param array<string,int> $ratings   Rating distribution.
 * @param float|null      $p75         75th percentile value.
 *
 * @return string Status identifier.
 */
function sitepulse_rum_determine_status($metric, $ratings, $p75) {
    $ratings = sitepulse_rum_normalize_rating_counts($ratings);
    $total = array_sum($ratings);

    if ($total === 0 || $p75 === null) {
        return 'status-warn';
    }

    $dominant = 'needs_improvement';
    $max_count = -1;

    foreach ($ratings as $bucket => $count) {
        if ($count > $max_count) {
            $dominant = $bucket;
            $max_count = $count;
        }
    }

    if ($dominant === 'good') {
        return 'status-ok';
    }

    if ($dominant === 'poor') {
        return 'status-bad';
    }

    return 'status-warn';
}

