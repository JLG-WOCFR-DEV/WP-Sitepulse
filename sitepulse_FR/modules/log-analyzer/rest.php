<?php
/**
 * SitePulse Log Analyzer REST routes.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the REST API routes for the Log Analyzer module.
 *
 * @return void
 */
function sitepulse_log_analyzer_register_rest_routes() {
    if (!function_exists('register_rest_route')) {
        return;
    }

    register_rest_route(
        'sitepulse/v1',
        '/logs/recent',
        [
            'methods'             => defined('WP_REST_Server::READABLE') ? WP_REST_Server::READABLE : 'GET',
            'callback'            => 'sitepulse_log_analyzer_rest_recent_logs',
            'permission_callback' => 'sitepulse_log_analyzer_rest_permission_check',
            'args'                => [
                'lines' => [
                    'type'              => 'integer',
                    'required'          => false,
                    'default'           => 100,
                    'sanitize_callback' => 'absint',
                    'minimum'           => 1,
                    'maximum'           => 500,
                ],
                'bytes' => [
                    'type'              => 'integer',
                    'required'          => false,
                    'default'           => 131072,
                    'sanitize_callback' => 'absint',
                    'minimum'           => 1024,
                    'maximum'           => 5242880,
                ],
                'levels' => [
                    'type'              => 'array',
                    'required'          => false,
                    'default'           => [],
                    'sanitize_callback' => 'sitepulse_log_analyzer_sanitize_levels',
                    'items'             => [
                        'type' => 'string',
                    ],
                ],
            ],
        ]
    );
}

/**
 * Checks whether the current user can access the log analyzer REST routes.
 *
 * @return bool
 */
function sitepulse_log_analyzer_rest_permission_check() {
    $capability = function_exists('sitepulse_get_capability')
        ? sitepulse_get_capability()
        : 'manage_options';

    return current_user_can($capability);
}

/**
 * Returns the recent log lines and metadata for external tools.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function sitepulse_log_analyzer_rest_recent_logs($request) {
    $max_lines = (int) $request->get_param('lines');
    $max_bytes = (int) $request->get_param('bytes');
    $levels    = $request->get_param('levels');

    if ($max_lines <= 0) {
        $max_lines = 100;
    }

    $max_lines = min(500, max(1, $max_lines));

    if ($max_bytes <= 0) {
        $max_bytes = 131072;
    }

    $max_bytes = min(5242880, max(1024, $max_bytes));

    $levels = is_array($levels) ? array_values($levels) : [];

    $module_active = function_exists('sitepulse_is_module_active')
        ? sitepulse_is_module_active('log_analyzer')
        : true;

    if (!$module_active) {
        return new WP_Error(
            'sitepulse_log_module_inactive',
            __('Le module Log Analyzer est désactivé.', 'sitepulse'),
            ['status' => 404]
        );
    }

    $debug_enabled = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;

    if (!$debug_enabled) {
        return new WP_Error(
            'sitepulse_debug_log_disabled',
            __('WP_DEBUG_LOG n’est pas activé pour ce site.', 'sitepulse'),
            ['status' => 409]
        );
    }

    $log_file = function_exists('sitepulse_get_wp_debug_log_path')
        ? sitepulse_get_wp_debug_log_path(true)
        : null;

    if (!is_string($log_file) || $log_file === '') {
        return new WP_Error(
            'sitepulse_log_unavailable',
            __('Impossible de localiser ou de lire le fichier debug.log.', 'sitepulse'),
            ['status' => 404]
        );
    }

    $log_data = sitepulse_get_recent_log_lines($log_file, $max_lines, $max_bytes, true);

    if ($log_data === null) {
        return new WP_Error(
            'sitepulse_log_unreadable',
            __('Impossible de lire les dernières lignes du journal de débogage.', 'sitepulse'),
            ['status' => 500]
        );
    }

    if (!is_array($log_data) || !array_key_exists('lines', $log_data)) {
        $lines    = is_array($log_data) ? $log_data : [];
        $log_data = [
            'lines'         => $lines,
            'bytes_read'    => null,
            'file_size'     => null,
            'truncated'     => null,
            'last_modified' => null,
        ];
    }

    $lines = isset($log_data['lines']) && is_array($log_data['lines'])
        ? array_map('strval', $log_data['lines'])
        : [];

    $categorization = sitepulse_log_analyzer_categorize_lines($lines);
    $groups         = isset($categorization['groups']) ? $categorization['groups'] : [];
    $assignments    = isset($categorization['assignments']) ? $categorization['assignments'] : [];

    $sections         = sitepulse_log_analyzer_get_sections();
    $available_levels = array_keys($sections);

    $totals = [];

    foreach ($groups as $key => $group_lines) {
        $totals[$key] = count($group_lines);
    }

    $levels = array_values(array_intersect($levels, $available_levels));

    if (!empty($levels)) {
        $filtered_groups = array_intersect_key($groups, array_flip($levels));
        $filtered_counts = array_intersect_key($totals, array_flip($levels));

        $filtered_lines = [];

        foreach ($lines as $index => $line) {
            $severity = $assignments[$index] ?? null;

            if ($severity !== null && in_array($severity, $levels, true)) {
                $filtered_lines[] = $line;
            }
        }
    } else {
        $filtered_groups = $groups;
        $filtered_counts = $totals;
        $filtered_lines  = $lines;
    }

    foreach ($filtered_groups as $key => $group_lines) {
        $filtered_groups[$key] = array_values($group_lines);
    }

    $response_data = [
        'generated_at' => function_exists('current_time')
            ? (int) current_time('timestamp', true)
            : time(),
        'status'       => sitepulse_log_analyzer_determine_status($filtered_counts ?: $totals),
        'request'      => [
            'max_lines' => $max_lines,
            'max_bytes' => $max_bytes,
            'levels'    => $levels,
        ],
        'debug'        => [
            'enabled'       => $debug_enabled,
            'module_active' => $module_active,
        ],
        'file'         => [
            'name'          => basename($log_file),
            'path'          => $log_file,
            'size'          => isset($log_data['file_size']) ? (int) $log_data['file_size'] : null,
            'last_modified' => isset($log_data['last_modified']) ? (int) $log_data['last_modified'] : null,
        ],
        'meta' => [
            'bytes_read'  => isset($log_data['bytes_read']) ? (int) $log_data['bytes_read'] : null,
            'truncated'   => !empty($log_data['truncated']),
            'total_lines' => count($lines),
            'line_count'  => count($filtered_lines),
        ],
        'lines'      => array_values($filtered_lines),
        'categories' => [
            'available' => $available_levels,
            'totals'    => $totals,
            'counts'    => $filtered_counts,
            'items'     => $filtered_groups,
        ],
        'sections' => $sections,
    ];

    if (function_exists('apply_filters')) {
        $response_data = apply_filters('sitepulse_log_analyzer_rest_response', $response_data, $request, $log_data, $groups);
    }

    return rest_ensure_response($response_data);
}
