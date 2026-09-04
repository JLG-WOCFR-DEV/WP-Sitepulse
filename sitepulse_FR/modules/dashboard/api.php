<?php
/**
 * SitePulse dashboard module fragment.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the REST API routes powering the dashboard metrics feed.
 *
 * @return void
 */
function sitepulse_custom_dashboard_register_rest_routes() {
    if (!function_exists('register_rest_route')) {
        return;
    }

    register_rest_route(
        'sitepulse/v1',
        '/metrics',
        [
            'methods'             => defined('WP_REST_Server::READABLE') ? WP_REST_Server::READABLE : 'GET',
            'callback'            => 'sitepulse_custom_dashboard_rest_metrics',
            'permission_callback' => 'sitepulse_custom_dashboard_rest_permission_check',
            'args'                => [
                'range' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]
    );

    register_rest_route(
        'sitepulse/v1',
        '/dashboard/kpi',
        [
            'methods'             => defined('WP_REST_Server::READABLE') ? WP_REST_Server::READABLE : 'GET',
            'callback'            => 'sitepulse_custom_dashboard_rest_kpis',
            'permission_callback' => 'sitepulse_custom_dashboard_rest_permission_check',
            'args'                => [
                'range' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]
    );
}

/**
 * Determines whether the current request can access the metrics endpoint.
 *
 * @return bool
 */
function sitepulse_custom_dashboard_rest_permission_check() {
    $capability = function_exists('sitepulse_get_capability')
        ? sitepulse_get_capability()
        : 'manage_options';

    return current_user_can($capability);
}

/**
 * Builds the payload returned by the metrics endpoint.
 *
 * @param string $range Range identifier to compute.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_prepare_metrics_payload($range) {
    $ranges = sitepulse_custom_dashboard_get_metric_ranges();

    if (!isset($ranges[$range])) {
        $range = sitepulse_custom_dashboard_get_default_range();
    }

    $config           = $ranges[$range];
    $available_ranges = array_values($ranges);

    $current_timestamp = sitepulse_custom_dashboard_get_current_timestamp();

    $uptime = sitepulse_custom_dashboard_calculate_uptime_metrics($range, $config);
    $logs   = sitepulse_custom_dashboard_analyze_debug_log();
    $speed  = sitepulse_custom_dashboard_calculate_speed_metrics($range, $config);
    $rum    = function_exists('sitepulse_rum_get_admin_summary')
        ? sitepulse_rum_get_admin_summary()
        : [];

    if (!is_array($rum)) {
        $rum = [];
    }

    $rum_enabled = function_exists('sitepulse_rum_is_enabled') ? sitepulse_rum_is_enabled() : false;
    $rum['enabled'] = $rum_enabled;

    if (is_array($logs)) {
        $logs['enabled'] = function_exists('sitepulse_is_module_active')
            ? sitepulse_is_module_active('log_analyzer')
            : true;
    }

    if (is_array($speed)) {
        $rum_range = isset($config['days']) ? (int) $config['days'] : 7;
        $speed['rum_enabled'] = function_exists('sitepulse_rum_is_enabled') ? sitepulse_rum_is_enabled() : false;

        if (function_exists('sitepulse_rum_calculate_aggregates')) {
            $speed['rum'] = sitepulse_rum_calculate_aggregates([
                'range_days' => $rum_range,
            ]);
        }
    }

    $modules_status = [
        'uptime_tracker'   => function_exists('sitepulse_is_module_active')
            ? sitepulse_is_module_active('uptime_tracker')
            : true,
        'log_analyzer'     => function_exists('sitepulse_is_module_active')
            ? sitepulse_is_module_active('log_analyzer')
            : true,
        'speed_analyzer'   => function_exists('sitepulse_is_module_active')
            ? sitepulse_is_module_active('speed_analyzer')
            : true,
        'rum'            => $rum_enabled,
        'ai_insights'    => function_exists('sitepulse_is_module_active')
            ? sitepulse_is_module_active('ai_insights')
            : function_exists('sitepulse_ai_get_history_entries'),
    ];

    $ai_summary = sitepulse_custom_dashboard_collect_ai_window_stats(
        isset($config['seconds']) ? (int) $config['seconds'] : 0,
        $current_timestamp
    );

    $uptime_log = sitepulse_custom_dashboard_get_normalized_uptime_log();
    $incidents = sitepulse_custom_dashboard_collect_open_incidents($uptime_log, $current_timestamp);

    $remote_queue = function_exists('sitepulse_uptime_analyze_remote_queue')
        ? sitepulse_uptime_analyze_remote_queue(null, $current_timestamp)
        : null;

    $impact = sitepulse_custom_dashboard_calculate_transverse_impact_index(
        $range,
        $config,
        $modules_status,
        $uptime,
        $speed,
        $ai_summary,
        $logs
    );

    $resource = sitepulse_custom_dashboard_calculate_resource_metrics();

    $payload = [
        'range'            => $range,
        'available_ranges' => $available_ranges,
        'generated_at'     => $current_timestamp,
        'uptime'           => $uptime,
        'logs'             => $logs,
        'speed'            => $speed,
        'rum'              => $rum,
        'modules'          => $modules_status,
        'ai_summary'       => $ai_summary,
        'incidents'        => $incidents,
        'remote_queue'     => $remote_queue,
    ];

    if (is_array($impact)) {
        $payload['impact'] = $impact;
    }

    $payload['view'] = sitepulse_custom_dashboard_format_metrics_view($payload);

    return $payload;
}

/**
 * Builds the payload returned by the KPI endpoint.
 *
 * @param string $range Range identifier to compute.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_prepare_kpi_payload($range) {
    $metrics_payload = sitepulse_custom_dashboard_prepare_metrics_payload($range);

    $view = isset($metrics_payload['view']) && is_array($metrics_payload['view'])
        ? $metrics_payload['view']
        : sitepulse_custom_dashboard_format_metrics_view($metrics_payload);

    $range_id = isset($view['range']) ? (string) $view['range'] : (string) ($metrics_payload['range'] ?? sitepulse_custom_dashboard_get_default_range());

    $available_ranges = isset($metrics_payload['available_ranges']) && is_array($metrics_payload['available_ranges'])
        ? array_values($metrics_payload['available_ranges'])
        : array_values(sitepulse_custom_dashboard_get_metric_ranges());

    $range_label = isset($view['range_label'])
        ? (string) $view['range_label']
        : sitepulse_custom_dashboard_resolve_range_label($range_id, $available_ranges);

    $generated_at = isset($view['generated_at'])
        ? absint($view['generated_at'])
        : (isset($metrics_payload['generated_at']) ? absint($metrics_payload['generated_at']) : sitepulse_custom_dashboard_get_current_timestamp());

    if ($generated_at <= 0) {
        $generated_at = sitepulse_custom_dashboard_get_current_timestamp();
    }

    $banner = isset($view['banner']) && is_array($view['banner']) ? $view['banner'] : [];
    $kpis = isset($banner['kpis']) && is_array($banner['kpis'])
        ? array_values(array_filter($banner['kpis'], 'is_array'))
        : [];

    if (empty($kpis)) {
        $kpis = sitepulse_custom_dashboard_build_kpi_cards($metrics_payload, $range_label, $generated_at);
    }

    $ai_summary = isset($metrics_payload['ai_summary']) && is_array($metrics_payload['ai_summary'])
        ? $metrics_payload['ai_summary']
        : [];

    $debt_snapshot = sitepulse_custom_dashboard_calculate_operational_debt_snapshot(
        isset($metrics_payload['remote_queue']) ? $metrics_payload['remote_queue'] : null,
        $ai_summary,
        $generated_at
    );

    return [
        'range'           => $range_id,
        'range_label'     => $range_label,
        'generated_at'    => $generated_at,
        'generated_label' => isset($view['generated_label']) ? (string) $view['generated_label'] : '',
        'generated_text'  => isset($view['generated_text']) ? (string) $view['generated_text'] : '',
        'kpis'            => $kpis,
        'metrics'         => [
            'uptime'    => isset($metrics_payload['uptime']) && is_array($metrics_payload['uptime']) ? $metrics_payload['uptime'] : [],
            'incidents' => isset($metrics_payload['incidents']) && is_array($metrics_payload['incidents']) ? array_values($metrics_payload['incidents']) : [],
            'debt'      => $debt_snapshot,
        ],
        'ranges'          => $available_ranges,
    ];
}

/**
 * REST API callback returning dashboard metrics.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return WP_REST_Response
 */
function sitepulse_custom_dashboard_rest_metrics($request) {
    $provided = $request instanceof WP_REST_Request ? $request->get_param('range') : null;
    $sanitized = sitepulse_custom_dashboard_sanitize_range($provided);

    if ($sanitized !== '') {
        update_option(sitepulse_custom_dashboard_get_range_option_name(), $sanitized, false);
        $range = $sanitized;
    } else {
        $range = sitepulse_custom_dashboard_get_stored_range();
    }

    $payload = sitepulse_custom_dashboard_prepare_metrics_payload($range);

    return rest_ensure_response($payload);
}

/**
 * REST API callback returning dashboard KPI cards.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return WP_REST_Response
 */
function sitepulse_custom_dashboard_rest_kpis($request) {
    $provided = $request instanceof WP_REST_Request ? $request->get_param('range') : null;
    $sanitized = sitepulse_custom_dashboard_sanitize_range($provided);

    if ($sanitized !== '') {
        update_option(sitepulse_custom_dashboard_get_range_option_name(), $sanitized, false);
        $range = $sanitized;
    } else {
        $range = sitepulse_custom_dashboard_get_stored_range();
    }

    $payload = sitepulse_custom_dashboard_prepare_kpi_payload($range);

    return rest_ensure_response($payload);
}
