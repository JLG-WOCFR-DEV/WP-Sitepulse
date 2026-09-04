<?php
/**
 * SitePulse Uptime REST routes.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the REST API routes used to orchestrate remote uptime workers.
 *
 * @return void
 */
function sitepulse_uptime_register_rest_routes() {
    if (!function_exists('register_rest_route')) {
        return;
    }

    register_rest_route(
        'sitepulse/v1',
        '/uptime/schedule',
        [
            'methods'             => defined('WP_REST_Server::CREATABLE') ? WP_REST_Server::CREATABLE : 'POST',
            'permission_callback' => 'sitepulse_uptime_rest_schedule_permission_check',
            'callback'            => 'sitepulse_uptime_rest_schedule_callback',
            'args'                => [
                'agent'     => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                    'default'           => 'default',
                ],
                'timestamp' => [
                    'type'              => 'integer',
                    'required'          => false,
                ],
                'payload'   => [
                    'type'              => 'array',
                    'required'          => false,
                    'default'           => [],
                ],
                'priority'  => [
                    'type'              => 'integer',
                    'required'          => false,
                    'default'           => 0,
                ],
            ],
        ]
    );

    register_rest_route(
        'sitepulse/v1',
        '/uptime/remote-queue',
        [
            'methods'             => defined('WP_REST_Server::READABLE') ? WP_REST_Server::READABLE : 'GET',
            'permission_callback' => 'sitepulse_uptime_rest_remote_queue_permission_check',
            'callback'            => 'sitepulse_uptime_rest_remote_queue_callback',
            'args'                => [
                'context' => [
                    'type'    => 'string',
                    'default' => 'view',
                ],
            ],
        ]
    );
}

/**
 * Determines whether the current request is allowed to schedule uptime checks.
 *
 * @param WP_REST_Request $request Request instance.
 * @return bool
 */
function sitepulse_uptime_rest_schedule_permission_check($request) {
    $required_capability = function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options';

    if (current_user_can($required_capability)) {
        return true;
    }

    /**
     * Filters the permission evaluation for the uptime scheduling REST endpoint.
     *
     * This allows third-party authentication strategies (application passwords,
     * signed tokens, etc.) to authorise remote workers without granting the full
     * SitePulse capability.
     *
     * @param bool|WP_Error|WP_HTTP_Response|WP_REST_Response|array $allowed Whether the request is authorised.
     * @param WP_REST_Request                                       $request REST request instance.
     */
    $permission = apply_filters('sitepulse_uptime_rest_schedule_permission', false, $request);

    if ($permission instanceof WP_REST_Response || $permission instanceof WP_HTTP_Response) {
        return $permission;
    }

    if (is_wp_error($permission)) {
        return $permission;
    }

    if (is_array($permission) && array_key_exists('allowed', $permission)) {
        $allowed = $permission['allowed'];
        $error   = isset($permission['error']) && is_wp_error($permission['error']) ? $permission['error'] : null;

        if ($error instanceof WP_Error) {
            return $error;
        }

        $permission = (bool) $allowed;
    }

    if (true === $permission) {
        return true;
    }

    if (false === $permission || null === $permission) {
        return new WP_Error(
            'sitepulse_uptime_forbidden',
            __('Vous n’avez pas l’autorisation de planifier des vérifications d’uptime via l’API REST.', 'sitepulse'),
            [
                'status' => rest_authorization_required_code(),
            ]
        );
    }

    if (is_bool($permission)) {
        return $permission
            ? true
            : new WP_Error(
                'sitepulse_uptime_forbidden',
                __('Vous n’avez pas l’autorisation de planifier des vérifications d’uptime via l’API REST.', 'sitepulse'),
                [
                    'status' => rest_authorization_required_code(),
                ]
            );
    }

    if (is_scalar($permission)) {
        return (bool) $permission
            ? true
            : new WP_Error(
                'sitepulse_uptime_forbidden',
                __('Vous n’avez pas l’autorisation de planifier des vérifications d’uptime via l’API REST.', 'sitepulse'),
                [
                    'status' => rest_authorization_required_code(),
                ]
            );
    }

    return new WP_Error(
        'sitepulse_uptime_forbidden',
        __('Vous n’avez pas l’autorisation de planifier des vérifications d’uptime via l’API REST.', 'sitepulse'),
        [
            'status' => rest_authorization_required_code(),
        ]
    );
}

/**
 * Handles REST API requests to queue internal uptime checks.
 *
 * @param WP_REST_Request $request Request instance.
 * @return WP_REST_Response
 */
function sitepulse_uptime_rest_schedule_callback($request) {
    $agent = $request->get_param('agent');
    $payload = $request->get_param('payload');
    $timestamp = $request->get_param('timestamp');
    $priority = $request->get_param('priority');

    if (!is_array($payload)) {
        $payload = [];
    }

    if (null !== $timestamp) {
        $timestamp = (int) $timestamp;
    }

    if (!is_numeric($priority)) {
        $priority = 0;
    }

    $priority = (int) $priority;

    if (!sitepulse_uptime_enqueue_remote_job($agent, $payload, $timestamp, $priority)) {
        return new WP_Error(
            'sitepulse_uptime_agent_inactive',
            __('Impossible de planifier cette vérification : l’agent est inactif ou interdit par un filtre.', 'sitepulse'),
            [
                'status' => 409,
            ]
        );
    }

    $scheduled_timestamp = null === $timestamp
        ? (int) current_time('timestamp')
        : (int) $timestamp;

    return rest_ensure_response([
        'queued'        => true,
        'agent'         => sitepulse_uptime_normalize_agent_id($agent),
        'scheduled_at'  => $scheduled_timestamp,
        'payload'       => empty($payload) ? new stdClass() : $payload,
        'priority'      => $priority,
    ]);
}

/**
 * Determines whether the current request is allowed to read remote queue metrics.
 *
 * @param WP_REST_Request $request Request instance.
 * @return bool|WP_Error|WP_HTTP_Response|WP_REST_Response
 */
function sitepulse_uptime_rest_remote_queue_permission_check($request) {
    $required_capability = function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options';

    if (current_user_can($required_capability)) {
        return true;
    }

    /**
     * Filters the permission evaluation for the remote queue metrics REST endpoint.
     *
     * This allows alternative authentication strategies to expose queue health to
     * observability stacks without granting full SitePulse capabilities.
     *
     * @param bool|WP_Error|WP_HTTP_Response|WP_REST_Response|array $allowed Whether the request is authorised.
     * @param WP_REST_Request                                       $request REST request instance.
     */
    $permission = apply_filters('sitepulse_uptime_rest_remote_queue_permission', false, $request);

    if ($permission instanceof WP_REST_Response || $permission instanceof WP_HTTP_Response) {
        return $permission;
    }

    if (is_wp_error($permission)) {
        return $permission;
    }

    if (is_array($permission) && array_key_exists('allowed', $permission)) {
        $allowed = $permission['allowed'];
        $error   = isset($permission['error']) && is_wp_error($permission['error']) ? $permission['error'] : null;

        if ($error instanceof WP_Error) {
            return $error;
        }

        $permission = (bool) $allowed;
    }

    if (true === $permission) {
        return true;
    }

    $error_code = function_exists('rest_authorization_required_code')
        ? rest_authorization_required_code()
        : 401;

    $error_message = __('Vous n’avez pas l’autorisation de consulter les métriques de file via l’API REST.', 'sitepulse');

    if (false === $permission || null === $permission) {
        return new WP_Error(
            'sitepulse_uptime_forbidden',
            $error_message,
            [
                'status' => $error_code,
            ]
        );
    }

    if (is_bool($permission)) {
        return $permission
            ? true
            : new WP_Error(
                'sitepulse_uptime_forbidden',
                $error_message,
                [
                    'status' => $error_code,
                ]
            );
    }

    if (is_scalar($permission)) {
        return (bool) $permission
            ? true
            : new WP_Error(
                'sitepulse_uptime_forbidden',
                $error_message,
                [
                    'status' => $error_code,
                ]
            );
    }

    return new WP_Error(
        'sitepulse_uptime_forbidden',
        $error_message,
        [
            'status' => $error_code,
        ]
    );
}

/**
 * Returns the latest remote queue metrics and health indicators.
 *
 * @param WP_REST_Request $request Request instance.
 * @return array|WP_REST_Response
 */
function sitepulse_uptime_rest_remote_queue_callback($request) {
    $analysis = sitepulse_uptime_analyze_remote_queue();

    $payload = [
        'timestamp'  => isset($analysis['timestamp']) ? (int) $analysis['timestamp'] : (int) current_time('timestamp'),
        'updated_at' => isset($analysis['updated_at']) ? (int) $analysis['updated_at'] : 0,
        'metrics'    => isset($analysis['metrics']) && is_array($analysis['metrics']) ? $analysis['metrics'] : [],
        'status'     => isset($analysis['status']) && is_array($analysis['status']) ? $analysis['status'] : [],
        'schedule'   => isset($analysis['schedule']) && is_array($analysis['schedule']) ? $analysis['schedule'] : [],
        'metadata'   => isset($analysis['metadata']) && is_array($analysis['metadata']) ? $analysis['metadata'] : [],
        'thresholds' => isset($analysis['thresholds']) && is_array($analysis['thresholds']) ? $analysis['thresholds'] : [],
    ];

    if (!isset($payload['status']['alerts']) || !is_array($payload['status']['alerts'])) {
        $payload['status']['alerts'] = [];
    } else {
        $payload['status']['alerts'] = array_values(array_map(static function ($alert) {
            return [
                'code'    => isset($alert['code']) ? (string) $alert['code'] : '',
                'level'   => isset($alert['level']) ? (string) $alert['level'] : '',
                'message' => isset($alert['message']) ? (string) $alert['message'] : '',
            ];
        }, array_filter($payload['status']['alerts'], 'is_array')));
    }

    if (!isset($payload['status']['notes']) || !is_array($payload['status']['notes'])) {
        $payload['status']['notes'] = [];
    } else {
        $payload['status']['notes'] = array_values(array_map('strval', $payload['status']['notes']));
    }

    $agent_definitions = sitepulse_uptime_get_agents();
    $agent_metrics = sitepulse_calculate_agent_uptime_metrics(sitepulse_get_uptime_archive(), 30, $agent_definitions);

    $payload['agents'] = [
        'window_days'  => 30,
        'definitions'  => array_map(static function ($config) {
            return [
                'label'  => isset($config['label']) ? (string) $config['label'] : '',
                'region' => isset($config['region']) ? sanitize_key($config['region']) : 'global',
                'active' => !isset($config['active']) || (bool) $config['active'],
                'weight' => isset($config['weight']) && is_numeric($config['weight']) ? (float) $config['weight'] : 1.0,
            ];
        }, $agent_definitions),
        'metrics'      => $agent_metrics,
    ];

    return function_exists('rest_ensure_response')
        ? rest_ensure_response($payload)
        : $payload;
}
