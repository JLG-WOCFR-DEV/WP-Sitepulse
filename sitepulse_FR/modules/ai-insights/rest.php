<?php
/**
 * SitePulse AI Insights REST routes.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers REST API routes to manage the AI queue.
 *
 * @return void
 */
function sitepulse_ai_register_rest_routes() {
    if (!function_exists('register_rest_route') || !class_exists('WP_REST_Server')) {
        return;
    }

    register_rest_route(
        'sitepulse/v1',
        '/ai/queue',
        [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => 'sitepulse_ai_rest_list_queue',
                'permission_callback' => 'sitepulse_ai_rest_permission_check',
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => 'sitepulse_ai_rest_purge_queue',
                'permission_callback' => 'sitepulse_ai_rest_permission_check',
            ],
        ]
    );

    register_rest_route(
        'sitepulse/v1',
        '/ai/queue/(?P<job_id>[a-zA-Z0-9_\-]+)',
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'sitepulse_ai_rest_retry_job',
            'permission_callback' => 'sitepulse_ai_rest_permission_check',
            'args'                => [
                'job_id' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]
    );

    register_rest_route(
        'sitepulse/v1',
        '/ai/jobs',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'sitepulse_ai_rest_list_jobs',
            'permission_callback' => 'sitepulse_ai_rest_permission_check',
        ]
    );
}

/**
 * REST permission callback for queue endpoints.
 *
 * @return bool
 */
function sitepulse_ai_rest_permission_check() {
    return current_user_can(sitepulse_get_capability());
}

/**
 * Returns the queued jobs snapshot over REST.
 *
 * @return WP_REST_Response
 */
function sitepulse_ai_rest_list_queue() {
    return new WP_REST_Response([
        'jobs' => sitepulse_ai_get_queue_snapshot(),
    ]);
}

/**
 * Returns the job log entries over REST.
 *
 * @return WP_REST_Response
 */
function sitepulse_ai_rest_list_jobs() {
    $entries = sitepulse_ai_get_job_log();

    return new WP_REST_Response([
        'jobs'    => sitepulse_ai_prepare_jobs_for_rest($entries),
        'metrics' => sitepulse_ai_calculate_job_metrics($entries),
    ]);
}

/**
 * Prepares job log entries for REST responses.
 *
 * @param array<int,array<string,mixed>> $entries Log entries.
 *
 * @return array<int,array<string,mixed>>
 */
function sitepulse_ai_prepare_jobs_for_rest(array $entries) {
    $prepared = [];

    foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry['job_id'])) {
            continue;
        }

        $history = [];

        if (isset($entry['history']) && is_array($entry['history'])) {
            foreach ($entry['history'] as $history_entry) {
                if (!is_array($history_entry) || !isset($history_entry['status'])) {
                    continue;
                }

                $history[] = [
                    'status'    => sanitize_key((string) $history_entry['status']),
                    'attempt'   => isset($history_entry['attempt']) ? (int) $history_entry['attempt'] : 1,
                    'timestamp' => isset($history_entry['timestamp']) ? (int) $history_entry['timestamp'] : 0,
                ];
            }
        }

        $prepared[] = [
            'job_id'         => $entry['job_id'],
            'status'         => isset($entry['status']) ? $entry['status'] : '',
            'status_final'   => isset($entry['status_final']) ? $entry['status_final'] : '',
            'attempt'        => isset($entry['attempt']) ? (int) $entry['attempt'] : 1,
            'model'          => isset($entry['model']) ? $entry['model'] : '',
            'engine'         => isset($entry['engine']) ? $entry['engine'] : '',
            'cost_estimated' => isset($entry['cost_estimated']) ? (float) $entry['cost_estimated'] : 0.0,
            'quota_consumed' => isset($entry['quota_consumed']) ? (float) $entry['quota_consumed'] : 0.0,
            'latency_ms'     => isset($entry['latency_ms']) ? (float) $entry['latency_ms'] : null,
            'timestamp'      => isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0,
            'created_at'     => isset($entry['created_at']) ? (int) $entry['created_at'] : 0,
            'updated_at'     => isset($entry['updated_at']) ? (int) $entry['updated_at'] : 0,
            'message'        => isset($entry['message']) ? $entry['message'] : '',
            'usage'          => isset($entry['usage']) && is_array($entry['usage']) ? $entry['usage'] : [],
            'history'        => $history,
        ];
    }

    return $prepared;
}

/**
 * Retries a queued job via REST.
 *
 * @param WP_REST_Request $request Request instance.
 *
 * @return WP_REST_Response|WP_Error
 */
function sitepulse_ai_rest_retry_job(WP_REST_Request $request) {
    $job_id = $request->get_param('job_id');

    $retry = sitepulse_ai_queue_retry_job($job_id);

    if (is_wp_error($retry)) {
        return $retry;
    }

    return new WP_REST_Response([
        'job' => sitepulse_ai_format_queue_payload($job_id, sitepulse_ai_get_job_data($job_id)),
    ]);
}

/**
 * Purges the queue via REST.
 *
 * @return WP_REST_Response
 */
function sitepulse_ai_rest_purge_queue() {
    $purged = sitepulse_ai_queue_purge();

    return new WP_REST_Response([
        'purged' => $purged,
        'jobs'   => sitepulse_ai_get_queue_snapshot(),
    ]);
}
