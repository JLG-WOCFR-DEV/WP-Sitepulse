<?php
/**
 * SitePulse Resource Monitor REST routes.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
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
