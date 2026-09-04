<?php
if (!defined('ABSPATH')) exit;

if (!defined('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK')) {
    define('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK', 'sitepulse_uptime_failure_streak');
}

/**
 * Escapes a CSV field to prevent spreadsheet formula injection.
 *
 * @param mixed $value Field value.
 * @return mixed Escaped field when relevant.
 */
function sitepulse_uptime_escape_csv_field($value) {
    if (is_string($value) && $value !== '' && preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }

    return $value;
}

/**
 * Escapes all textual values within a CSV row.
 *
 * @param array<int|string,mixed> $row CSV row.
 * @return array<int|string,mixed> Escaped row.
 */
function sitepulse_uptime_escape_csv_row($row) {
    if (!is_array($row)) {
        return $row;
    }

    return array_map('sitepulse_uptime_escape_csv_field', $row);
}

if (!defined('SITEPULSE_OPTION_UPTIME_ARCHIVE')) {
    define('SITEPULSE_OPTION_UPTIME_ARCHIVE', 'sitepulse_uptime_archive');
}

if (!defined('SITEPULSE_OPTION_UPTIME_AGENTS')) {
    define('SITEPULSE_OPTION_UPTIME_AGENTS', 'sitepulse_uptime_agents');
}

if (!defined('SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE')) {
    define('SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE', 'sitepulse_uptime_remote_queue');
}

if (!defined('SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS')) {
    define('SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS', 'sitepulse_uptime_remote_queue_metrics');
}

if (!defined('SITEPULSE_UPTIME_REMOTE_QUEUE_MAX_SIZE')) {
    define('SITEPULSE_UPTIME_REMOTE_QUEUE_MAX_SIZE', 200);
}

if (!defined('SITEPULSE_UPTIME_REMOTE_QUEUE_ITEM_TTL')) {
    define('SITEPULSE_UPTIME_REMOTE_QUEUE_ITEM_TTL', DAY_IN_SECONDS);
}

if (!defined('SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS')) {
    define('SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS', 'sitepulse_uptime_maintenance_windows');
}

if (!defined('SITEPULSE_OPTION_UPTIME_MAINTENANCE_NOTICES')) {
    define('SITEPULSE_OPTION_UPTIME_MAINTENANCE_NOTICES', 'sitepulse_uptime_maintenance_notices');
}

if (!defined('SITEPULSE_OPTION_UPTIME_SLA_REPORTS')) {
    define('SITEPULSE_OPTION_UPTIME_SLA_REPORTS', 'sitepulse_uptime_sla_reports');
}

if (!defined('SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION')) {
    define('SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION', 'sitepulse_uptime_sla_automation');
}

if (!defined('SITEPULSE_UPTIME_SLA_DIRECTORY')) {
    define('SITEPULSE_UPTIME_SLA_DIRECTORY', 'sitepulse-uptime-reports');
}

if (!defined('SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS')) {
    define('SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS', 'sitepulse_uptime_history_retention_days');
}

if (!defined('SITEPULSE_DEFAULT_UPTIME_HISTORY_RETENTION_DAYS')) {
    define('SITEPULSE_DEFAULT_UPTIME_HISTORY_RETENTION_DAYS', 90);
}

$sitepulse_uptime_cron_hook = function_exists('sitepulse_get_cron_hook') ? sitepulse_get_cron_hook('uptime_tracker') : 'sitepulse_uptime_tracker_cron';

add_filter('cron_schedules', 'sitepulse_uptime_tracker_register_cron_schedules');

add_action('admin_menu', function() {
    add_submenu_page('sitepulse-dashboard', __('Uptime Tracker', 'sitepulse'), __('Uptime', 'sitepulse'), sitepulse_get_capability(), 'sitepulse-uptime', 'sitepulse_uptime_tracker_page');
});

add_action('admin_enqueue_scripts', 'sitepulse_uptime_tracker_enqueue_assets');

/**
 * Enqueues the stylesheet required for the uptime tracker admin page.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_uptime_tracker_enqueue_assets($hook_suffix) {
    if ($hook_suffix !== 'sitepulse-dashboard_page_sitepulse-uptime') {
        return;
    }

    wp_enqueue_style(
        'sitepulse-uptime-tracker',
        SITEPULSE_URL . 'modules/css/uptime-tracker.css',
        [],
        SITEPULSE_VERSION
    );
}

if (!empty($sitepulse_uptime_cron_hook)) {
    add_action('init', 'sitepulse_uptime_tracker_ensure_cron');
    add_action($sitepulse_uptime_cron_hook, 'sitepulse_run_uptime_check');
}

add_action('init', 'sitepulse_uptime_register_remote_worker_hooks');
add_action('admin_post_sitepulse_export_sla', 'sitepulse_uptime_handle_sla_export');
add_action('admin_post_sitepulse_generate_uptime_report', 'sitepulse_uptime_handle_manual_report_generation');
add_action('admin_post_sitepulse_save_sla_settings', 'sitepulse_uptime_handle_sla_settings_save');

/**
 * Registers custom cron schedules used by the uptime tracker.
 *
 * @param array $schedules Existing schedules.
 * @return array Modified schedules including SitePulse intervals.
 */
function sitepulse_uptime_tracker_register_cron_schedules($schedules) {
    if (!is_array($schedules)) {
        $schedules = [];
    }

    $frequency_choices = function_exists('sitepulse_get_uptime_frequency_choices')
        ? sitepulse_get_uptime_frequency_choices()
        : [];

    foreach ($frequency_choices as $frequency_key => $frequency_data) {
        if (in_array($frequency_key, ['hourly', 'twicedaily', 'daily'], true)) {
            continue;
        }

        if (!is_array($frequency_data) || !isset($frequency_data['interval'])) {
            continue;
        }

        $interval = (int) $frequency_data['interval'];

        if ($interval < 1) {
            continue;
        }

        $display = isset($frequency_data['label']) && is_string($frequency_data['label'])
            ? $frequency_data['label']
            : ucfirst(str_replace('_', ' ', $frequency_key));

        $schedules[$frequency_key] = [
            'interval' => $interval,
            'display'  => $display,
        ];
    }

    return $schedules;
}

/**
 * Registers hooks used for remote worker orchestration.
 *
 * @return void
 */
function sitepulse_uptime_register_remote_worker_hooks() {
    add_action('sitepulse_uptime_process_remote_queue', 'sitepulse_uptime_process_remote_queue');
    add_action('sitepulse_uptime_schedule_internal_request', 'sitepulse_uptime_schedule_internal_request', 10, 4);
    add_action('rest_api_init', 'sitepulse_uptime_register_rest_routes');

    if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
        WP_CLI::add_command('sitepulse uptime:queue', function ($args, $assoc_args) {
            $agent = isset($assoc_args['agent']) ? $assoc_args['agent'] : 'default';
            $payload = isset($assoc_args['payload']) ? json_decode($assoc_args['payload'], true) : [];
            $timestamp = isset($assoc_args['timestamp']) ? (int) $assoc_args['timestamp'] : null;
            $priority = isset($assoc_args['priority']) ? (int) $assoc_args['priority'] : 0;

            if (!is_array($payload)) {
                $payload = [];
            }

            if (sitepulse_uptime_enqueue_remote_job($agent, $payload, $timestamp, $priority)) {
                WP_CLI::success(sprintf('Vérification programmée pour %s (priorité %d).', $agent, $priority));
            } else {
                WP_CLI::warning(sprintf('Agent %s ignoré (inactif ou filtré).', $agent));
            }
        });
    }
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

/**
 * Retrieves the configured cron schedule for uptime checks.
 *
 * @return string
 */
function sitepulse_uptime_tracker_get_schedule() {
    $default = defined('SITEPULSE_DEFAULT_UPTIME_FREQUENCY') ? SITEPULSE_DEFAULT_UPTIME_FREQUENCY : 'hourly';
    $option  = get_option(SITEPULSE_OPTION_UPTIME_FREQUENCY, $default);

    if (function_exists('sitepulse_sanitize_uptime_frequency')) {
        $option = sitepulse_sanitize_uptime_frequency($option);
    } elseif (!is_string($option) || $option === '') {
        $option = $default;
    }

    $choices = function_exists('sitepulse_get_uptime_frequency_choices') ? sitepulse_get_uptime_frequency_choices() : [];

    if (!isset($choices[$option])) {
        $option = $default;
    }

    return $option;
}

/**
 * Sanitizes the uptime agent definitions before storage.
 *
 * @param mixed $value Raw agent configuration.
 * @return array<string,array<string,mixed>>
 */
function sitepulse_uptime_sanitize_agents($value) {
    $existing = get_option(SITEPULSE_OPTION_UPTIME_AGENTS, []);

    if (!is_array($existing)) {
        $existing = [];
    }

    if (!is_array($value)) {
        $value = [];
    }

    $sanitized = [];
    $generated_index = 0;

    foreach ($value as $raw_agent) {
        if (!is_array($raw_agent)) {
            continue;
        }

        $label = isset($raw_agent['label']) ? sanitize_text_field($raw_agent['label']) : '';
        $region = isset($raw_agent['region']) ? sanitize_key($raw_agent['region']) : '';
        $identifier = isset($raw_agent['id']) ? sanitize_key($raw_agent['id']) : '';

        if ($identifier === '' && isset($raw_agent['slug'])) {
            $identifier = sanitize_key($raw_agent['slug']);
        }

        if ($identifier === '' && $label !== '') {
            $identifier = sanitize_key($label);
        }

        if ($identifier === '') {
            if ($label === '') {
                continue;
            }

            $generated_index++;
            $identifier = sanitize_key('agent_' . $generated_index);
        }

        if ($identifier === '' || isset($sanitized[$identifier])) {
            continue;
        }

        $url = '';

        if (isset($raw_agent['url']) && is_string($raw_agent['url'])) {
            $candidate_url = trim($raw_agent['url']);

            if ($candidate_url !== '') {
                $validated_url = wp_http_validate_url($candidate_url);

                if ($validated_url) {
                    $url = esc_url_raw($validated_url);
                }
            }
        }

        $timeout = null;

        if (isset($raw_agent['timeout']) && $raw_agent['timeout'] !== '') {
            $timeout_candidate = is_numeric($raw_agent['timeout']) ? (int) $raw_agent['timeout'] : null;

            if (null !== $timeout_candidate && $timeout_candidate > 0) {
                $timeout = $timeout_candidate;
            }
        }

        $weight = isset($raw_agent['weight']) && is_numeric($raw_agent['weight'])
            ? (float) $raw_agent['weight']
            : 1.0;

        if ($weight <= 0) {
            $weight = 1.0;
        }

        $active = !empty($raw_agent['active']);

        $existing_agent = isset($existing[$identifier]) && is_array($existing[$identifier])
            ? $existing[$identifier]
            : [];

        $headers = isset($existing_agent['headers']) && is_array($existing_agent['headers'])
            ? $existing_agent['headers']
            : [];

        if (!empty($raw_agent['headers']) && is_array($raw_agent['headers'])) {
            $headers = $raw_agent['headers'];
        }

        if (function_exists('sitepulse_sanitize_uptime_http_headers')) {
            $headers = sitepulse_sanitize_uptime_http_headers($headers);
        }

        $expected_codes = isset($existing_agent['expected_codes']) && is_array($existing_agent['expected_codes'])
            ? $existing_agent['expected_codes']
            : [];

        if (!empty($raw_agent['expected_codes']) && is_array($raw_agent['expected_codes'])) {
            $expected_codes = $raw_agent['expected_codes'];
        }

        if (function_exists('sitepulse_sanitize_uptime_expected_codes')) {
            $expected_codes = sitepulse_sanitize_uptime_expected_codes($expected_codes);
        }

        $agent = [
            'label'          => $label !== '' ? $label : ucfirst(str_replace('_', ' ', $identifier)),
            'region'         => $region !== '' ? $region : 'global',
            'url'            => $url,
            'timeout'        => null === $timeout ? null : max(1, (int) $timeout),
            'method'         => isset($existing_agent['method']) ? $existing_agent['method'] : null,
            'headers'        => $headers,
            'expected_codes' => $expected_codes,
            'active'         => $active,
            'weight'         => (float) $weight,
        ];

        if (isset($existing_agent['metadata']) && is_array($existing_agent['metadata'])) {
            $agent['metadata'] = $existing_agent['metadata'];
        }

        $sanitized[$identifier] = $agent;
    }

    if (empty($sanitized)) {
        return [];
    }

    /**
     * Filters the sanitized agent configuration prior to persistence.
     *
     * @param array<string,array<string,mixed>> $sanitized Sanitized agents.
     * @param array<mixed>                       $raw       Raw submitted payload.
     * @param array<string,array<string,mixed>>  $existing  Previously saved agents.
     */
    $sanitized = apply_filters('sitepulse_uptime_sanitized_agents', $sanitized, $value, $existing);

    /**
     * Fires after the agent configuration has been sanitized.
     *
     * @param array<string,array<string,mixed>> $sanitized Sanitized agents.
     * @param array<string,array<string,mixed>> $existing  Previously saved agents.
     * @param array<mixed>                       $raw       Raw submitted payload.
     */
    do_action('sitepulse_uptime_agents_prepared', $sanitized, $existing, $value);

    return $sanitized;
}

/**
 * Returns the configured uptime monitoring agents.
 *
 * @return array<string,array<string,mixed>>
 */
function sitepulse_uptime_get_agents() {
    $agents = get_option(SITEPULSE_OPTION_UPTIME_AGENTS, []);

    if (!is_array($agents) || empty($agents)) {
        $agents = [
            'default' => [
                'label'  => __('Agent principal', 'sitepulse'),
                'region' => 'global',
                'active' => true,
                'weight' => 1.0,
            ],
        ];
    }

    foreach ($agents as $agent_id => $agent_data) {
        if (!is_array($agent_data)) {
            $agent_data = [];
        }

        $agents[$agent_id] = wp_parse_args($agent_data, [
            'label'          => ucfirst(str_replace('_', ' ', $agent_id)),
            'region'         => 'global',
            'url'            => '',
            'timeout'        => null,
            'method'         => null,
            'headers'        => [],
            'expected_codes' => [],
            'active'         => true,
            'weight'         => 1.0,
        ]);

        $agents[$agent_id]['region'] = sanitize_key($agents[$agent_id]['region']);
        $agents[$agent_id]['weight'] = (float) max(0.0, $agents[$agent_id]['weight']);
    }

    /**
     * Filters the agent definitions returned by SitePulse.
     *
     * @param array<string,array<string,mixed>> $agents Agent configuration keyed by identifier.
     */
    return apply_filters('sitepulse_uptime_agents', $agents);
}

/**
 * Retrieves a single agent definition.
 *
 * @param string $agent_id Agent identifier.
 * @return array<string,mixed>
 */
function sitepulse_uptime_get_agent($agent_id) {
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $agents = sitepulse_uptime_get_agents();

    if (!isset($agents[$agent_id])) {
        return [
            'label'          => __('Agent principal', 'sitepulse'),
            'region'         => 'global',
            'url'            => '',
            'timeout'        => null,
            'method'         => null,
            'headers'        => [],
            'expected_codes' => [],
            'active'         => true,
            'weight'         => 1.0,
        ];
    }

    return $agents[$agent_id];
}

/**
 * Determines whether an agent is active.
 *
 * @param string                          $agent_id     Agent identifier.
 * @param array<string,mixed>|null        $agent_config Optional configuration override.
 * @return bool
 */
function sitepulse_uptime_agent_is_active($agent_id, $agent_config = null) {
    if (null === $agent_config) {
        $agent_config = sitepulse_uptime_get_agent($agent_id);
    }

    $is_active = !isset($agent_config['active']) || (bool) $agent_config['active'];

    /**
     * Filters whether a given agent should be considered active.
     *
     * @param bool                           $is_active     Whether the agent is active.
     * @param string                         $agent_id      Agent identifier.
     * @param array<string,mixed>|null       $agent_config Agent configuration.
     */
    return (bool) apply_filters('sitepulse_uptime_agent_is_active', $is_active, $agent_id, $agent_config);
}

/**
 * Returns the normalized weight for an agent.
 *
 * @param string                          $agent_id     Agent identifier.
 * @param array<string,mixed>|null        $agent_config Optional configuration override.
 * @return float
 */
function sitepulse_uptime_get_agent_weight($agent_id, $agent_config = null) {
    if (null === $agent_config) {
        $agent_config = sitepulse_uptime_get_agent($agent_id);
    }

    $weight = isset($agent_config['weight']) && is_numeric($agent_config['weight'])
        ? (float) $agent_config['weight']
        : 1.0;

    if ($weight < 0) {
        $weight = 0.0;
    }

    /**
     * Filters the weight applied to an agent.
     *
     * @param float                          $weight        Agent weight.
     * @param string                         $agent_id      Agent identifier.
     * @param array<string,mixed>|null       $agent_config Agent configuration.
     */
    $weight = apply_filters('sitepulse_uptime_agent_weight', $weight, $agent_id, $agent_config);

    return (float) max(0.0, $weight);
}

/**
 * Normalises an agent identifier.
 *
 * @param string $agent_id Raw identifier.
 * @return string
 */
function sitepulse_uptime_normalize_agent_id($agent_id) {
    if (!is_string($agent_id) || $agent_id === '') {
        return 'default';
    }

    $agent_id = sanitize_key($agent_id);

    if ($agent_id === '') {
        return 'default';
    }

    return $agent_id;
}

/**
 * Retrieves the raw maintenance window definitions.
 *
 * @return array<int,array<string,mixed>>
 */
function sitepulse_uptime_get_maintenance_window_definitions() {
    $windows = get_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS, []);

    if (!is_array($windows)) {
        $windows = [];
    }

    if (function_exists('sitepulse_sanitize_uptime_maintenance_windows')) {
        $windows = sitepulse_sanitize_uptime_maintenance_windows($windows);
    }

    return array_values(array_map(function ($window) {
        if (!is_array($window)) {
            return [];
        }

        $agent = isset($window['agent']) ? sitepulse_uptime_normalize_agent_id($window['agent']) : 'all';

        if ($agent === '') {
            $agent = 'all';
        }

        $label = isset($window['label']) && is_string($window['label']) ? $window['label'] : '';
        $recurrence = isset($window['recurrence']) ? sanitize_key($window['recurrence']) : 'weekly';

        if (!in_array($recurrence, ['daily', 'weekly', 'one_off'], true)) {
            $recurrence = 'weekly';
        }

        $time = isset($window['time']) ? trim((string) $window['time']) : '00:00';

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $time = '00:00';
        }

        $duration = isset($window['duration']) ? (int) $window['duration'] : 0;

        if ($duration < 1) {
            $duration = 60;
        }

        $day = isset($window['day']) ? (int) $window['day'] : 1;

        if ($day < 1 || $day > 7) {
            $day = 1;
        }

        $date = isset($window['date']) ? trim((string) $window['date']) : '';

        return [
            'agent'      => $agent,
            'label'      => $label,
            'recurrence' => $recurrence,
            'day'        => $day,
            'time'       => $time,
            'duration'   => $duration,
            'date'       => $date,
        ];
    }, $windows));
}

/**
 * Retrieves the stored maintenance skip notices.
 *
 * @return array<int,array<string,mixed>>
 */
function sitepulse_uptime_get_maintenance_notice_log() {
    $notices = get_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_NOTICES, []);

    if (!is_array($notices)) {
        return [];
    }

    return array_values(array_filter(array_map(function ($notice) {
        if (!is_array($notice) || !isset($notice['message'])) {
            return null;
        }

        $message = trim((string) $notice['message']);

        if ($message === '') {
            return null;
        }

        return [
            'message'   => $message,
            'timestamp' => isset($notice['timestamp']) ? (int) $notice['timestamp'] : 0,
        ];
    }, $notices)));
}

/**
 * Records an uptime maintenance notice for later display.
 *
 * @param string $message   Notice message.
 * @param int    $timestamp Event timestamp.
 * @return void
 */
function sitepulse_uptime_record_maintenance_notice($message, $timestamp) {
    $notices = get_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_NOTICES, []);

    if (!is_array($notices)) {
        $notices = [];
    }

    $notices[] = [
        'message'   => (string) $message,
        'timestamp' => (int) $timestamp,
    ];

    if (count($notices) > 20) {
        $notices = array_slice($notices, -20);
    }

    update_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_NOTICES, array_values($notices), false);
}

/**
 * Resolves a maintenance window occurrence for a given timestamp.
 *
 * @param array<string,mixed> $definition Window definition.
 * @param int                 $timestamp  Reference timestamp.
 * @param string              $mode       Mode: "current" or "next".
 * @return array<string,mixed>|null
 */
function sitepulse_uptime_resolve_window_occurrence($definition, $timestamp, $mode = 'current') {
    if (!is_array($definition)) {
        return null;
    }

    $timestamp = (int) $timestamp;
    $mode = $mode === 'next' ? 'next' : 'current';
    $duration_minutes = isset($definition['duration']) ? (int) $definition['duration'] : 0;

    if ($duration_minutes < 1) {
        return null;
    }

    $time_string = isset($definition['time']) ? (string) $definition['time'] : '00:00';

    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time_string)) {
        return null;
    }

    list($hour, $minute) = array_map('intval', explode(':', $time_string));
    $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $now = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    $recurrence = isset($definition['recurrence']) ? $definition['recurrence'] : 'weekly';

    if (!in_array($recurrence, ['daily', 'weekly', 'one_off'], true)) {
        $recurrence = 'weekly';
    }

    if ('one_off' === $recurrence) {
        $date_value = isset($definition['date']) ? trim((string) $definition['date']) : '';

        if ($date_value === '') {
            return null;
        }

        try {
            $start_datetime = new DateTimeImmutable($date_value . ' ' . $time_string, $timezone);
        } catch (Exception $e) {
            return null;
        }
    } elseif ('daily' === $recurrence) {
        $start_datetime = $now->setTime($hour, $minute, 0);

        if ('current' === $mode && $now < $start_datetime) {
            $start_datetime = $start_datetime->modify('-1 day');
        } elseif ('next' === $mode && $now >= $start_datetime) {
            $start_datetime = $start_datetime->modify('+1 day');
        }
    } else {
        $day = isset($definition['day']) ? (int) $definition['day'] : 1;

        if ($day < 1 || $day > 7) {
            $day = 1;
        }

        $iso_year = (int) $now->format('o');
        $iso_week = (int) $now->format('W');
        $start_datetime = $now->setISODate($iso_year, $iso_week, $day)->setTime($hour, $minute, 0);

        if ('current' === $mode && $now < $start_datetime) {
            $start_datetime = $start_datetime->modify('-1 week');
        } elseif ('next' === $mode && $now >= $start_datetime) {
            $start_datetime = $start_datetime->modify('+1 week');
        }
    }

    $end_datetime = $start_datetime->modify('+' . $duration_minutes . ' minutes');
    $start_timestamp = $start_datetime->getTimestamp();
    $end_timestamp = $end_datetime->getTimestamp();

    if ('current' === $mode) {
        if ($timestamp < $start_timestamp || $timestamp > $end_timestamp) {
            return null;
        }
    } elseif ($start_timestamp <= $timestamp) {
        // No future occurrence for one-off schedules.
        if ('one_off' === $recurrence) {
            return null;
        }

        if ($timestamp >= $end_timestamp) {
            return null;
        }
    }

    return [
        'agent'      => isset($definition['agent']) ? $definition['agent'] : 'all',
        'label'      => isset($definition['label']) ? (string) $definition['label'] : '',
        'recurrence' => $recurrence,
        'day'        => isset($definition['day']) ? (int) $definition['day'] : 1,
        'time'       => $time_string,
        'duration'   => $duration_minutes,
        'date'       => isset($definition['date']) ? (string) $definition['date'] : '',
        'start'      => $start_timestamp,
        'end'        => $end_timestamp,
        'is_active'  => 'current' === $mode,
    ];
}

/**
 * Retrieves resolved maintenance windows (active and upcoming).
 *
 * @param int|null $timestamp Reference timestamp.
 * @return array<int,array<string,mixed>>
 */
function sitepulse_uptime_get_maintenance_windows($timestamp = null) {
    $timestamp = null === $timestamp ? (int) current_time('timestamp') : (int) $timestamp;
    $definitions = sitepulse_uptime_get_maintenance_window_definitions();
    $windows = [];

    foreach ($definitions as $definition) {
        $active_window = sitepulse_uptime_resolve_window_occurrence($definition, $timestamp, 'current');

        if ($active_window) {
            $windows[] = $active_window;
        }

        $next_window = sitepulse_uptime_resolve_window_occurrence($definition, $timestamp, 'next');

        if ($next_window) {
            $duplicate = false;

            foreach ($windows as $existing_window) {
                if ($existing_window['start'] === $next_window['start'] && $existing_window['agent'] === $next_window['agent']) {
                    $duplicate = true;
                    break;
                }
            }

            if (!$duplicate) {
                $windows[] = $next_window;
            }
        }
    }

    if (empty($windows)) {
        return [];
    }

    usort($windows, function ($a, $b) {
        if (!is_array($a) || !is_array($b)) {
            return 0;
        }

        if ($a['start'] === $b['start']) {
            return strcmp((string) $a['agent'], (string) $b['agent']);
        }

        return $a['start'] <=> $b['start'];
    });

    return $windows;
}

/**
 * Retrieves the active maintenance window for an agent, if any.
 *
 * @param string   $agent_id  Agent identifier.
 * @param int|null $timestamp Evaluation timestamp.
 * @return array<string,mixed>|null
 */
function sitepulse_uptime_find_active_maintenance_window($agent_id, $timestamp = null) {
    $timestamp = null === $timestamp ? (int) current_time('timestamp') : (int) $timestamp;
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $definitions = sitepulse_uptime_get_maintenance_window_definitions();

    foreach ($definitions as $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $target_agent = isset($definition['agent']) ? $definition['agent'] : 'all';

        if ('all' !== $target_agent && sitepulse_uptime_normalize_agent_id($target_agent) !== $agent_id) {
            continue;
        }

        $window = sitepulse_uptime_resolve_window_occurrence($definition, $timestamp, 'current');

        if ($window) {
            return $window;
        }
    }

    return null;
}

/**
 * Determines if the provided agent is inside a maintenance window.
 *
 * @param string   $agent_id  Agent identifier.
 * @param int|null $timestamp Timestamp to evaluate.
 * @return bool
 */
function sitepulse_uptime_is_in_maintenance_window($agent_id, $timestamp = null) {
    return null !== sitepulse_uptime_find_active_maintenance_window($agent_id, $timestamp);
}

/**
 * Returns the maximum number of items allowed in the remote queue.
 *
 * @return int
 */
function sitepulse_uptime_get_remote_queue_max_size() {
    $default = defined('SITEPULSE_UPTIME_REMOTE_QUEUE_MAX_SIZE')
        ? (int) SITEPULSE_UPTIME_REMOTE_QUEUE_MAX_SIZE
        : 200;

    /**
     * Filters the maximum number of queued remote uptime requests.
     *
     * @param int $max_size Queue size limit (0 disables the limit).
     */
    $max_size = apply_filters('sitepulse_uptime_remote_queue_max_size', $default);

    return max(0, (int) $max_size);
}

/**
 * Returns the retention duration for remote queue items.
 *
 * @return int
 */
function sitepulse_uptime_get_remote_queue_item_ttl() {
    $default = defined('SITEPULSE_UPTIME_REMOTE_QUEUE_ITEM_TTL')
        ? (int) SITEPULSE_UPTIME_REMOTE_QUEUE_ITEM_TTL
        : DAY_IN_SECONDS;

    /**
     * Filters the retention duration (in seconds) for queued remote requests.
     *
     * @param int $ttl Retention duration (0 disables pruning by age).
     */
    $ttl = apply_filters('sitepulse_uptime_remote_queue_item_ttl', $default);

    return max(0, (int) $ttl);
}

/**
 * Returns the default metrics payload used when instrumenting the remote queue.
 *
 * @param int $now Timestamp used for calculations.
 * @param int $ttl Configured TTL for queue items.
 * @param int $max_size Maximum number of entries allowed in the queue.
 * @return array<string,int|null>
 */
function sitepulse_uptime_get_default_queue_metrics($now, $ttl, $max_size) {
    return [
        'requested'          => 0,
        'retained'           => 0,
        'dropped_invalid'    => 0,
        'dropped_expired'    => 0,
        'dropped_duplicates' => 0,
        'dropped_overflow'   => 0,
        'queue_length'       => 0,
        'delayed_jobs'       => 0,
        'max_wait_seconds'   => 0,
        'avg_wait_seconds'   => 0,
        'max_priority'       => 0,
        'avg_priority'       => 0,
        'prioritized_jobs'   => 0,
        'next_scheduled_at'  => null,
        'oldest_created_at'  => null,
        'limit_ttl'          => (int) $ttl,
        'limit_size'         => (int) $max_size,
        'evaluated_at'       => (int) $now,
    ];
}

/**
 * Stores the latest remote queue metrics and fires an action for observers.
 *
 * @param array<string,int|null> $metrics Metrics payload.
 * @return void
 */
function sitepulse_uptime_record_queue_metrics($metrics) {
    if (!is_array($metrics)) {
        return;
    }

    $metrics = array_merge(sitepulse_uptime_get_default_queue_metrics((int) current_time('timestamp', true), 0, 0), $metrics);

    $payload = [
        'updated_at' => (int) current_time('timestamp', true),
        'metrics'    => $metrics,
    ];

    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS, $payload, false);

    /**
     * Fires once the remote queue metrics have been updated.
     *
     * @param array<string,mixed> $payload Recorded metrics payload.
     */
    do_action('sitepulse_uptime_remote_queue_metrics_recorded', $payload);
}

/**
 * Retrieves the latest stored remote queue metrics.
 *
 * @return array<string,mixed>
 */
function sitepulse_uptime_get_remote_queue_metrics() {
    $payload = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS, []);

    $now = (int) current_time('timestamp', true);
    $defaults = sitepulse_uptime_get_default_queue_metrics($now, sitepulse_uptime_get_remote_queue_item_ttl(), sitepulse_uptime_get_remote_queue_max_size());

    if (!is_array($payload)) {
        return [
            'updated_at' => 0,
            'metrics'    => $defaults,
        ];
    }

    $metrics = isset($payload['metrics']) && is_array($payload['metrics'])
        ? array_merge($defaults, $payload['metrics'])
        : $defaults;

    return [
        'updated_at' => isset($payload['updated_at']) ? (int) $payload['updated_at'] : 0,
        'metrics'    => $metrics,
    ];
}

/**
 * Formats a duration into a translated, human friendly string.
 *
 * @param float|int|null $seconds Duration in seconds.
 * @return string
 */
function sitepulse_uptime_format_duration_i18n($seconds) {
    if (null === $seconds || !is_numeric($seconds) || $seconds < 0) {
        return '—';
    }

    $seconds = (float) $seconds;

    if ($seconds < 1) {
        return __('moins d’une seconde', 'sitepulse');
    }

    if ($seconds < 60) {
        $count = max(1, (int) round($seconds));

        return sprintf(
            _n('%s seconde', '%s secondes', $count, 'sitepulse'),
            number_format_i18n($count)
        );
    }

    $minutes = floor($seconds / 60);

    if ($minutes < 60) {
        return sprintf(
            _n('%s minute', '%s minutes', $minutes, 'sitepulse'),
            number_format_i18n($minutes)
        );
    }

    $hours = floor($minutes / 60);

    if ($hours < 48) {
        return sprintf(
            _n('%s heure', '%s heures', $hours, 'sitepulse'),
            number_format_i18n($hours)
        );
    }

    $days = floor($hours / 24);

    return sprintf(
        _n('%s jour', '%s jours', $days, 'sitepulse'),
        number_format_i18n($days)
    );
}

/**
 * Formats a timestamp relative to another reference timestamp.
 *
 * @param int|null $timestamp         Timestamp to format.
 * @param int      $current_timestamp Reference timestamp.
 * @return string
 */
function sitepulse_uptime_format_relative_time($timestamp, $current_timestamp) {
    if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
        return '';
    }

    $timestamp = (int) $timestamp;
    $current_timestamp = (int) $current_timestamp;

    if ($timestamp >= $current_timestamp) {
        $difference = human_time_diff($current_timestamp, $timestamp);

        return sprintf(
            __('dans %s', 'sitepulse'),
            $difference
        );
    }

    $difference = human_time_diff($timestamp, $current_timestamp);

    return sprintf(
        __('il y a %s', 'sitepulse'),
        $difference
    );
}

/**
 * Aggregates remote queue metrics into a health summary and formatted labels.
 *
 * @param array<string,mixed>|null $payload           Optional metrics payload returned by
 *                                                    sitepulse_uptime_get_remote_queue_metrics().
 * @param int|null                 $current_timestamp Reference timestamp for relative calculations.
 * @return array<string,mixed>
 */
function sitepulse_uptime_analyze_remote_queue($payload = null, $current_timestamp = null) {
    if (null === $current_timestamp) {
        $current_timestamp = (int) current_time('timestamp');
    } else {
        $current_timestamp = (int) $current_timestamp;
    }

    if (null === $payload) {
        $payload = sitepulse_uptime_get_remote_queue_metrics();
    }

    $default_metrics = sitepulse_uptime_get_default_queue_metrics(
        $current_timestamp,
        sitepulse_uptime_get_remote_queue_item_ttl(),
        sitepulse_uptime_get_remote_queue_max_size()
    );

    $raw_metrics = [];

    if (is_array($payload) && isset($payload['metrics']) && is_array($payload['metrics'])) {
        $raw_metrics = $payload['metrics'];
    }

    $metrics = array_merge($default_metrics, $raw_metrics);

    $sanitized = [
        'requested'          => max(0, (int) ($metrics['requested'] ?? 0)),
        'retained'           => max(0, (int) ($metrics['retained'] ?? 0)),
        'dropped_invalid'    => max(0, (int) ($metrics['dropped_invalid'] ?? 0)),
        'dropped_expired'    => max(0, (int) ($metrics['dropped_expired'] ?? 0)),
        'dropped_duplicates' => max(0, (int) ($metrics['dropped_duplicates'] ?? 0)),
        'dropped_overflow'   => max(0, (int) ($metrics['dropped_overflow'] ?? 0)),
        'queue_length'       => max(0, (int) ($metrics['queue_length'] ?? 0)),
        'delayed_jobs'       => max(0, (int) ($metrics['delayed_jobs'] ?? 0)),
        'max_wait_seconds'   => max(0, (int) ($metrics['max_wait_seconds'] ?? 0)),
        'avg_wait_seconds'   => max(0, (int) ($metrics['avg_wait_seconds'] ?? 0)),
        'max_priority'       => isset($metrics['max_priority']) ? (int) $metrics['max_priority'] : 0,
        'avg_priority'       => isset($metrics['avg_priority']) ? (int) $metrics['avg_priority'] : 0,
        'prioritized_jobs'   => max(0, (int) ($metrics['prioritized_jobs'] ?? 0)),
        'next_scheduled_at'  => isset($metrics['next_scheduled_at']) && (int) $metrics['next_scheduled_at'] > 0
            ? (int) $metrics['next_scheduled_at']
            : null,
        'oldest_created_at'  => isset($metrics['oldest_created_at']) && (int) $metrics['oldest_created_at'] > 0
            ? (int) $metrics['oldest_created_at']
            : null,
        'limit_ttl'          => max(0, (int) ($metrics['limit_ttl'] ?? 0)),
        'limit_size'         => max(0, (int) ($metrics['limit_size'] ?? 0)),
    ];

    $sanitized['dropped_total'] = $sanitized['dropped_invalid']
        + $sanitized['dropped_expired']
        + $sanitized['dropped_duplicates']
        + $sanitized['dropped_overflow'];

    $updated_at = 0;

    if (is_array($payload) && isset($payload['updated_at'])) {
        $updated_at = (int) $payload['updated_at'];
    }

    $usage_ratio = null;

    if ($sanitized['limit_size'] > 0) {
        $usage_ratio = $sanitized['queue_length'] / $sanitized['limit_size'];
    }

    $queue_status_priorities = [
        'ok'       => 0,
        'warning'  => 1,
        'critical' => 2,
    ];

    $queue_status = 'ok';
    $alerts = [];

    $queue_status_promote = static function ($level) use (&$queue_status, $queue_status_priorities) {
        if (!isset($queue_status_priorities[$level])) {
            return;
        }

        if ($queue_status_priorities[$level] > $queue_status_priorities[$queue_status]) {
            $queue_status = $level;
        }
    };

    $register_alert = static function ($code, $level, $message) use (&$alerts, $queue_status_promote) {
        $alerts[] = [
            'code'    => $code,
            'level'   => $level,
            'message' => $message,
        ];

        $queue_status_promote($level);
    };

    if (null !== $usage_ratio) {
        if ($usage_ratio >= 1) {
            $register_alert(
                'queue_capacity_exceeded',
                'critical',
                __('La file a atteint sa capacité maximale.', 'sitepulse')
            );
        } elseif ($usage_ratio >= 0.8) {
            $register_alert(
                'queue_capacity_pressure',
                'warning',
                __('La file approche de sa capacité maximale.', 'sitepulse')
            );
        }
    }

    if ($sanitized['delayed_jobs'] > 0) {
        $register_alert(
            'queue_delayed_jobs',
            'warning',
            sprintf(
                _n('%s requête est en retard.', '%s requêtes sont en retard.', $sanitized['delayed_jobs'], 'sitepulse'),
                number_format_i18n($sanitized['delayed_jobs'])
            )
        );

        if ($sanitized['prioritized_jobs'] > 0) {
            $priority_level = $sanitized['max_priority'] >= 5 ? 'critical' : 'warning';
            $register_alert(
                'queue_priority_backlog',
                $priority_level,
                sprintf(
                    _n(
                        '%1$s job prioritaire attend (priorité max %2$s).',
                        '%1$s jobs prioritaires attendent (priorité max %2$s).',
                        $sanitized['prioritized_jobs'],
                        'sitepulse'
                    ),
                    number_format_i18n($sanitized['prioritized_jobs']),
                    number_format_i18n(max(1, $sanitized['max_priority']))
                )
            );
        }
    }

    if ($sanitized['dropped_total'] > 0) {
        $register_alert(
            'queue_rejections_detected',
            'warning',
            sprintf(
                _n(
                    '%s requête a été rejetée (TTL, doublon ou validation).',
                    '%s requêtes ont été rejetées (TTL, doublon ou validation).',
                    $sanitized['dropped_total'],
                    'sitepulse'
                ),
                number_format_i18n($sanitized['dropped_total'])
            )
        );
    }

    if ($sanitized['limit_ttl'] > 0) {
        $wait_warning_threshold = max(60, min((int) round($sanitized['limit_ttl'] * 0.25), 3600));
        $wait_critical_threshold = max($wait_warning_threshold + 60, min((int) round($sanitized['limit_ttl'] * 0.5), 7200));
    } else {
        $wait_warning_threshold = 900;
        $wait_critical_threshold = 1800;
    }

    if ($sanitized['max_wait_seconds'] >= $wait_critical_threshold) {
        $register_alert(
            'queue_wait_time_critical',
            'critical',
            sprintf(
                __('Attente maximale détectée : %s.', 'sitepulse'),
                sitepulse_uptime_format_duration_i18n($sanitized['max_wait_seconds'])
            )
        );
    } elseif ($sanitized['max_wait_seconds'] >= $wait_warning_threshold) {
        $register_alert(
            'queue_wait_time_warning',
            'warning',
            sprintf(
                __('La file enregistre des attentes longues : %s.', 'sitepulse'),
                sitepulse_uptime_format_duration_i18n($sanitized['max_wait_seconds'])
            )
        );
    }

    $day_in_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
    $stale_threshold = $sanitized['limit_ttl'] > 0
        ? max(300, min($sanitized['limit_ttl'], $day_in_seconds))
        : 900;

    $metrics_age = null;

    if ($updated_at > 0) {
        $metrics_age = max(0, $current_timestamp - $updated_at);

        if ($metrics_age > (2 * $stale_threshold)) {
            $register_alert(
                'queue_metrics_expired',
                'critical',
                sprintf(
                    __('Les métriques n’ont pas été actualisées depuis %s.', 'sitepulse'),
                    sitepulse_uptime_format_duration_i18n($metrics_age)
                )
            );
        } elseif ($metrics_age > $stale_threshold) {
            $register_alert(
                'queue_metrics_stale',
                'warning',
                sprintf(
                    __('Dernière actualisation il y a %s.', 'sitepulse'),
                    sitepulse_uptime_format_duration_i18n($metrics_age)
                )
            );
        }
    }

    $queue_status_headlines = [
        'ok'       => __('File d’orchestration nominale', 'sitepulse'),
        'warning'  => __('Points de vigilance détectés', 'sitepulse'),
        'critical' => __('Intervention requise', 'sitepulse'),
    ];

    $queue_status_icons = [
        'ok'       => 'yes-alt',
        'warning'  => 'warning',
        'critical' => 'dismiss',
    ];

    $date_format = (string) get_option('date_format', 'Y-m-d');
    $time_format = (string) get_option('time_format', 'H:i');

    if ($date_format === '') {
        $date_format = 'Y-m-d';
    }

    if ($time_format === '') {
        $time_format = 'H:i';
    }

    $describe_timestamp = static function ($timestamp) use ($current_timestamp, $date_format, $time_format) {
        if (null === $timestamp) {
            return [
                'timestamp' => null,
                'formatted' => null,
                'relative'  => null,
                'label'     => '—',
            ];
        }

        $formatted = date_i18n($date_format . ' ' . $time_format, $timestamp);
        $relative = sitepulse_uptime_format_relative_time($timestamp, $current_timestamp);

        $label = $formatted;

        if ($relative !== '') {
            $label = sprintf('%s (%s)', $formatted, $relative);
        }

        return [
            'timestamp' => (int) $timestamp,
            'formatted' => $formatted,
            'relative'  => $relative,
            'label'     => $label,
        ];
    };

    $schedule_next = $describe_timestamp($sanitized['next_scheduled_at']);
    $schedule_oldest = $describe_timestamp($sanitized['oldest_created_at']);
    $updated_descriptor = $describe_timestamp($updated_at > 0 ? $updated_at : null);

    return [
        'timestamp'  => $current_timestamp,
        'updated_at' => $updated_at,
        'metrics'    => $sanitized,
        'status'     => [
            'level'               => $queue_status,
            'headline'            => $queue_status_headlines[$queue_status],
            'icon'                => $queue_status_icons[$queue_status],
            'alerts'              => $alerts,
            'notes'               => array_column($alerts, 'message'),
            'usage_ratio'         => null === $usage_ratio ? null : (float) $usage_ratio,
            'metrics_age_seconds' => $metrics_age,
        ],
        'schedule'   => [
            'next'   => $schedule_next,
            'oldest' => $schedule_oldest,
        ],
        'metadata'   => [
            'updated' => $updated_descriptor,
        ],
        'thresholds' => [
            'usage_warning_ratio' => 0.8,
            'wait_warning'        => $wait_warning_threshold,
            'wait_critical'       => $wait_critical_threshold,
            'stale_threshold'     => $stale_threshold,
        ],
    ];
}

/**
 * Normalises and prunes a remote worker queue.
 *
 * @param array<int,array<string,mixed>> $queue Existing queue.
 * @param int|null                       $now   Reference timestamp.
 * @return array<int,array<string,mixed>>
 */
function sitepulse_uptime_normalize_remote_queue($queue, $now = null) {
    $now = null === $now ? (int) current_time('timestamp', true) : (int) $now;
    $ttl = sitepulse_uptime_get_remote_queue_item_ttl();
    $max_size = sitepulse_uptime_get_remote_queue_max_size();
    $metrics = sitepulse_uptime_get_default_queue_metrics($now, $ttl, $max_size);

    if (!is_array($queue) || empty($queue)) {
        sitepulse_uptime_record_queue_metrics($metrics);

        return [];
    }
    $encoder = function ($payload) {
        if (!is_array($payload)) {
            return '';
        }

        ksort($payload);

        if (function_exists('wp_json_encode')) {
            return wp_json_encode($payload);
        }

        return json_encode($payload);
    };

    $unique = [];

    foreach ($queue as $item) {
        $metrics['requested']++;

        if (!is_array($item)) {
            $metrics['dropped_invalid']++;
            continue;
        }

        $agent = isset($item['agent']) ? sitepulse_uptime_normalize_agent_id($item['agent']) : 'default';
        $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : [];
        $scheduled_at = isset($item['scheduled_at']) ? (int) $item['scheduled_at'] : $now;
        $created_at = isset($item['created_at']) ? (int) $item['created_at'] : $now;
        $priority = isset($item['priority']) && is_numeric($item['priority']) ? (int) $item['priority'] : 0;

        if ($ttl > 0 && $scheduled_at <= ($now - $ttl)) {
            $metrics['dropped_expired']++;
            continue;
        }

        $key = $agent . '|' . $scheduled_at . '|' . md5($encoder($payload));

        if (isset($unique[$key])) {
            $metrics['dropped_duplicates']++;

            $existing_created = isset($unique[$key]['created_at']) ? (int) $unique[$key]['created_at'] : null;
            $existing_scheduled = isset($unique[$key]['scheduled_at']) ? (int) $unique[$key]['scheduled_at'] : null;
            $existing_priority = isset($unique[$key]['priority']) ? (int) $unique[$key]['priority'] : 0;

            if (null !== $existing_created && ($created_at > 0 && $created_at < $existing_created)) {
                $unique[$key]['created_at'] = $created_at;
            }

            if (null !== $existing_scheduled && ($scheduled_at > 0 && $scheduled_at < $existing_scheduled)) {
                $unique[$key]['scheduled_at'] = $scheduled_at;
            }

            if ($priority > $existing_priority) {
                $unique[$key]['priority'] = $priority;
            }

            continue;
        }

        $unique[$key] = [
            'agent'       => $agent,
            'payload'     => $payload,
            'scheduled_at'=> $scheduled_at,
            'created_at'  => $created_at,
            'priority'    => $priority,
        ];
    }

    if (empty($unique)) {
        sitepulse_uptime_record_queue_metrics($metrics);

        return [];
    }

    $normalized = array_values($unique);

    usort($normalized, function ($a, $b) {
        $a_priority = isset($a['priority']) ? (int) $a['priority'] : 0;
        $b_priority = isset($b['priority']) ? (int) $b['priority'] : 0;

        if ($a_priority !== $b_priority) {
            return $b_priority <=> $a_priority;
        }

        $a_scheduled = isset($a['scheduled_at']) ? (int) $a['scheduled_at'] : 0;
        $b_scheduled = isset($b['scheduled_at']) ? (int) $b['scheduled_at'] : 0;

        if ($a_scheduled === $b_scheduled) {
            $a_created = isset($a['created_at']) ? (int) $a['created_at'] : 0;
            $b_created = isset($b['created_at']) ? (int) $b['created_at'] : 0;

            return $a_created <=> $b_created;
        }

        return $a_scheduled <=> $b_scheduled;
    });

    $original_count = count($normalized);

    if ($max_size > 0 && $original_count > $max_size) {
        $metrics['dropped_overflow'] = $original_count - $max_size;
        $normalized = array_slice($normalized, 0, $max_size);
    }

    $metrics['retained'] = count($normalized);
    $metrics['queue_length'] = $metrics['retained'];

    $next_scheduled_at = null;
    $oldest_created_at = null;
    $delayed_jobs = 0;
    $wait_total = 0;
    $max_wait = 0;
    $priority_total = 0;
    $prioritized_jobs = 0;
    $max_priority_value = null;

    foreach ($normalized as $item) {
        if (isset($item['scheduled_at']) && (int) $item['scheduled_at'] > 0) {
            $timestamp = (int) $item['scheduled_at'];

            if (null === $next_scheduled_at || $timestamp < $next_scheduled_at) {
                $next_scheduled_at = $timestamp;
            }

            $wait = $now - $timestamp;

            if ($wait > 0) {
                $delayed_jobs++;
                $wait_total += $wait;

                if ($wait > $max_wait) {
                    $max_wait = $wait;
                }
            }
        }

        if (isset($item['created_at']) && (int) $item['created_at'] > 0) {
            $created = (int) $item['created_at'];

            if (null === $oldest_created_at || $created < $oldest_created_at) {
                $oldest_created_at = $created;
            }
        }

        $priority_value = isset($item['priority']) ? (int) $item['priority'] : 0;

        if ($priority_value !== 0) {
            $prioritized_jobs++;
            $priority_total += $priority_value;
            $max_priority_value = null === $max_priority_value
                ? $priority_value
                : max($max_priority_value, $priority_value);
        }
    }

    $metrics['delayed_jobs'] = $delayed_jobs;
    $metrics['max_wait_seconds'] = $max_wait > 0 ? (int) $max_wait : 0;
    $metrics['avg_wait_seconds'] = ($delayed_jobs > 0 && $wait_total > 0)
        ? (int) round($wait_total / $delayed_jobs)
        : 0;
    $metrics['next_scheduled_at'] = null !== $next_scheduled_at ? (int) $next_scheduled_at : null;
    $metrics['oldest_created_at'] = null !== $oldest_created_at ? (int) $oldest_created_at : null;

    if ($prioritized_jobs > 0) {
        $metrics['prioritized_jobs'] = $prioritized_jobs;
        $metrics['max_priority'] = (int) $max_priority_value;
        $metrics['avg_priority'] = (int) round($priority_total / $prioritized_jobs);
    }

    sitepulse_uptime_record_queue_metrics($metrics);

    return $normalized;
}

/**
 * Determines the next scheduled timestamp for the provided queue.
 *
 * @param array<int,array<string,mixed>> $queue    Queue entries.
 * @param int|null                       $fallback Fallback timestamp.
 * @return int|null
 */
function sitepulse_uptime_get_queue_next_scheduled_at($queue, $fallback = null) {
    if (!is_array($queue) || empty($queue)) {
        return null === $fallback ? null : (int) $fallback;
    }

    $timestamps = array_map(function ($item) {
        return isset($item['scheduled_at']) ? (int) $item['scheduled_at'] : 0;
    }, $queue);

    $timestamps = array_filter($timestamps, function ($timestamp) {
        return $timestamp > 0;
    });

    if (empty($timestamps)) {
        return null === $fallback ? null : (int) $fallback;
    }

    return min($timestamps);
}

/**
 * High-level helper to enqueue a remote job for an agent.
 *
 * @param string     $agent_id  Agent identifier.
 * @param array      $payload   Optional request overrides.
 * @param int|null   $timestamp Scheduled timestamp (UTC).
 * @param int|null   $priority  Optional priority override.
 * @return bool True when the job was enqueued, false when skipped.
 */
function sitepulse_uptime_enqueue_remote_job($agent_id, $payload = [], $timestamp = null, $priority = null) {
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $agent_config = sitepulse_uptime_get_agent($agent_id);

    if (!sitepulse_uptime_agent_is_active($agent_id, $agent_config)) {
        return false;
    }

    if (!is_array($payload)) {
        $payload = [];
    }

    if (null === $priority) {
        $weight = sitepulse_uptime_get_agent_weight($agent_id, $agent_config);
        $priority = (int) round($weight * 100);
    }

    $job = [
        'agent'     => $agent_id,
        'payload'   => $payload,
        'timestamp' => $timestamp,
        'priority'  => $priority,
    ];

    /**
     * Filters the job payload before it is persisted in the remote queue.
     *
     * Returning false aborts the enqueue operation.
     *
     * @param array<string,mixed>|false $job          Normalized job payload.
     * @param array<string,mixed>       $agent_config Agent configuration.
     */
    $job = apply_filters('sitepulse_uptime_pre_enqueue_job', $job, $agent_config);

    if (false === $job) {
        return false;
    }

    $job_agent = isset($job['agent']) ? sitepulse_uptime_normalize_agent_id($job['agent']) : $agent_id;
    $job_payload = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : $payload;
    $job_timestamp = array_key_exists('timestamp', $job) ? $job['timestamp'] : $timestamp;
    $job_priority = array_key_exists('priority', $job) ? $job['priority'] : $priority;

    $job_priority = is_numeric($job_priority) ? (int) $job_priority : 0;

    if (null !== $job_timestamp) {
        $job_timestamp = (int) $job_timestamp;
    }

    sitepulse_uptime_schedule_internal_request($job_agent, $job_payload, $job_timestamp, $job_priority);

    /**
     * Fires after an uptime job has been enqueued.
     *
     * @param string                    $agent_id     Agent identifier.
     * @param array<string,mixed>       $payload      Job payload.
     * @param int|null                  $timestamp    Scheduled timestamp.
     * @param int                       $priority     Job priority.
     * @param array<string,mixed>       $agent_config Agent configuration.
     */
    do_action('sitepulse_uptime_job_enqueued', $job_agent, $job_payload, $job_timestamp, $job_priority, $agent_config);

    return true;
}

/**
 * Queues a remote worker request so it is executed internally.
 *
 * @param string   $agent_id  Agent identifier.
 * @param array    $payload   Optional overrides for the request.
 * @param int|null $timestamp When the request should be executed.
 * @param int      $priority  Optional priority override (higher values are executed first).
 * @return void
 */
function sitepulse_uptime_schedule_internal_request($agent_id, $payload = [], $timestamp = null, $priority = 0) {
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $timestamp = null === $timestamp ? (int) current_time('timestamp', true) : (int) $timestamp;
    $priority = (int) $priority;

    if (!is_array($payload)) {
        $payload = [];
    }

    $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);

    if (!is_array($queue)) {
        $queue = [];
    }

    $queue[] = [
        'agent'       => $agent_id,
        'payload'     => $payload,
        'scheduled_at'=> $timestamp,
        'created_at'  => (int) current_time('timestamp', true),
        'priority'    => $priority,
    ];

    $queue = sitepulse_uptime_normalize_remote_queue($queue);

    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, $queue, false);

    $next_timestamp = sitepulse_uptime_get_queue_next_scheduled_at($queue, $timestamp);

    if (null !== $next_timestamp) {
        sitepulse_uptime_maybe_schedule_queue_processor($next_timestamp);
    }
}

/**
 * Ensures a cron event exists to process the remote worker queue.
 *
 * @param int $timestamp Desired execution time.
 * @return void
 */
function sitepulse_uptime_maybe_schedule_queue_processor($timestamp) {
    // WP-Cron expects UTC timestamps, so always schedule using GMT to avoid timezone offsets.
    $timestamp = max((int) $timestamp, (int) current_time('timestamp', true));

    $current = wp_next_scheduled('sitepulse_uptime_process_remote_queue');

    if (!$current || $timestamp < $current) {
        if ($current) {
            wp_unschedule_event($current, 'sitepulse_uptime_process_remote_queue');
        }

        wp_schedule_single_event($timestamp, 'sitepulse_uptime_process_remote_queue');
    }
}

/**
 * Processes the remote worker queue and executes pending checks.
 *
 * @return void
 */
function sitepulse_uptime_process_remote_queue() {
    $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);
    $queue = sitepulse_uptime_normalize_remote_queue($queue);

    if (!is_array($queue) || empty($queue)) {
        update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, [], false);
        return;
    }

    $now = (int) current_time('timestamp', true);
    $remaining = [];

    foreach ($queue as $item) {
        if (!is_array($item)) {
            continue;
        }

        $scheduled_at = isset($item['scheduled_at']) ? (int) $item['scheduled_at'] : $now;

        if ($scheduled_at > $now) {
            $remaining[] = $item;
            continue;
        }

        $agent = isset($item['agent']) ? $item['agent'] : 'default';
        $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : [];

        if (!sitepulse_uptime_agent_is_active($agent)) {
            continue;
        }

        if (isset($payload['task']) && 'uptime_sla_report' === $payload['task']) {
            $windows = isset($payload['windows']) && is_array($payload['windows']) ? $payload['windows'] : [7, 30];
            sitepulse_uptime_generate_sla_report('automation', $windows);

            if (!empty($payload['automation'])) {
                $settings = sitepulse_uptime_get_sla_automation_settings();

                if (!empty($settings['enabled'])) {
                    $settings['next_run'] = (int) current_time('timestamp', true);
                    update_option(SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION, $settings, false);
                    sitepulse_uptime_schedule_automation_job($settings, true);
                }
            }

            continue;
        }

        sitepulse_run_uptime_check($agent, $payload);
    }

    if (!empty($remaining)) {
        $remaining = sitepulse_uptime_normalize_remote_queue($remaining, $now);
        update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, $remaining, false);

        $next_timestamp = sitepulse_uptime_get_queue_next_scheduled_at($remaining, $now);

        if (null !== $next_timestamp) {
            sitepulse_uptime_maybe_schedule_queue_processor($next_timestamp);
        }

        return;
    }

    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, [], false);
}

/**
 * Attempts to resolve the interval (in seconds) for the configured uptime schedule.
 *
 * @param int $default_interval Fallback interval when schedules cannot be resolved.
 * @return int
 */
function sitepulse_uptime_tracker_resolve_schedule_interval($default_interval) {
    if (!function_exists('wp_get_schedules')) {
        return $default_interval;
    }

    $schedules = wp_get_schedules();

    if (!is_array($schedules) || empty($schedules)) {
        return $default_interval;
    }

    $schedule_candidates = array_unique(array_filter([
        sitepulse_uptime_tracker_get_schedule(),
        defined('SITEPULSE_DEFAULT_UPTIME_FREQUENCY') ? SITEPULSE_DEFAULT_UPTIME_FREQUENCY : null,
        'hourly',
    ]));

    foreach ($schedule_candidates as $candidate) {
        if (!isset($schedules[$candidate]) || !is_array($schedules[$candidate])) {
            continue;
        }

        $candidate_interval = isset($schedules[$candidate]['interval']) ? (int) $schedules[$candidate]['interval'] : 0;

        if ($candidate_interval > 0) {
            return $candidate_interval;
        }
    }

    return $default_interval;
}

/**
 * Ensures the uptime tracker cron hook is scheduled and reports failures.
 *
 * @return void
 */
function sitepulse_uptime_tracker_ensure_cron() {
    global $sitepulse_uptime_cron_hook;

    if (empty($sitepulse_uptime_cron_hook)) {
        return;
    }

    $desired_schedule = sitepulse_uptime_tracker_get_schedule();
    $available_schedules = wp_get_schedules();

    if (!isset($available_schedules[$desired_schedule])) {
        $fallback_schedule = defined('SITEPULSE_DEFAULT_UPTIME_FREQUENCY') ? SITEPULSE_DEFAULT_UPTIME_FREQUENCY : 'hourly';
        if (isset($available_schedules[$fallback_schedule])) {
            $desired_schedule = $fallback_schedule;
        } elseif (isset($available_schedules['hourly'])) {
            $desired_schedule = 'hourly';
        }
    }

    $current_schedule = wp_get_schedule($sitepulse_uptime_cron_hook);

    if ($current_schedule && $current_schedule !== $desired_schedule) {
        wp_clear_scheduled_hook($sitepulse_uptime_cron_hook);
    }

    $next_run = wp_next_scheduled($sitepulse_uptime_cron_hook);

    if (!$next_run) {
        $next_run = (int) current_time('timestamp', true);
        $scheduled = wp_schedule_event($next_run, $desired_schedule, $sitepulse_uptime_cron_hook);

        if (false === $scheduled && function_exists('sitepulse_log')) {
            sitepulse_log(sprintf('Unable to schedule uptime tracker cron hook: %s', $sitepulse_uptime_cron_hook), 'ERROR');
        }
    }

    if (!wp_next_scheduled($sitepulse_uptime_cron_hook)) {
        sitepulse_register_cron_warning(
            'uptime_tracker',
            __('SitePulse n’a pas pu planifier la vérification d’uptime. Vérifiez que WP-Cron est actif ou programmez manuellement la tâche.', 'sitepulse')
        );
    } else {
        sitepulse_clear_cron_warning('uptime_tracker');
    }
}
/**
 * Normalizes a raw uptime status value into canonical form.
 *
 * @param mixed $status Raw status field from the log entry.
 * @return bool|string|null Returns true/false for up/down, 'maintenance', 'unknown' or null when indeterminate.
 */
function sitepulse_uptime_normalize_status_value($status) {
    if (is_bool($status)) {
        return $status;
    }

    if (is_int($status) || is_float($status)) {
        return (int) $status !== 0;
    }

    if (is_string($status)) {
        $normalized = strtolower(trim($status));

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'ok', 'up', 'online', 'success'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off', 'down', 'offline', 'failed', 'failure', 'error'], true)) {
            return false;
        }

        if (in_array($normalized, ['maintenance', 'paused', 'snoozed'], true)) {
            return 'maintenance';
        }

        if (in_array($normalized, ['unknown', 'n/a', 'na', 'indeterminate'], true)) {
            return 'unknown';
        }
    }

    return null;
}

/**
 * Converts an arbitrary error payload into a string message.
 *
 * @param mixed $error Raw error payload.
 * @return string|null
 */
function sitepulse_uptime_normalize_error_message($error) {
    if (null === $error || '' === $error) {
        return null;
    }

    if (is_wp_error($error)) {
        $messages = $error->get_error_messages();

        if (empty($messages)) {
            $messages = [$error->get_error_code()];
        }

        return implode('; ', array_filter(array_map('strval', $messages)));
    }

    if (is_scalar($error)) {
        return (string) $error;
    }

    $encoded_error = wp_json_encode($error);

    if (false !== $encoded_error) {
        return $encoded_error;
    }

    return null;
}

function sitepulse_normalize_uptime_log($log) {
    if (!is_array($log) || empty($log)) {
        return [];
    }

    $count = count($log);
    $now = (int) current_time('timestamp');

    $default_interval = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
    $interval = sitepulse_uptime_tracker_resolve_schedule_interval($default_interval);

    $approximate_start = $now - max(0, ($count - 1) * $interval);

    $prepared = [];

    foreach (array_values($log) as $index => $entry) {
        $timestamp = $approximate_start + ($index * $interval);

        if (is_array($entry) && isset($entry['timestamp']) && is_numeric($entry['timestamp'])) {
            $timestamp = (int) $entry['timestamp'];
        }

        $prepared[] = [
            'entry'     => $entry,
            'timestamp' => $timestamp,
            'order'     => $index,
        ];
    }

    usort($prepared, function ($a, $b) {
        if ($a['timestamp'] === $b['timestamp']) {
            return $a['order'] <=> $b['order'];
        }

        return $a['timestamp'] <=> $b['timestamp'];
    });

    $normalized = [];

    foreach ($prepared as $item) {
        $entry = $item['entry'];
        $timestamp = $item['timestamp'];
        $status = null;
        $raw_status_value = null;
        $incident_start = null;
        $error_message = null;
        $agent = 'default';

        if (is_array($entry)) {
            if (array_key_exists('status', $entry)) {
                $status = $entry['status'];
                $raw_status_value = $entry['status'];
            } else {
                $status = !empty($entry);
                $raw_status_value = $status;
            }

            if (isset($entry['incident_start']) && is_numeric($entry['incident_start'])) {
                $incident_start = (int) $entry['incident_start'];
            }

            if (array_key_exists('error', $entry)) {
                $error_message = sitepulse_uptime_normalize_error_message($entry['error']);
            }

            if (isset($entry['agent']) && is_string($entry['agent'])) {
                $agent = sitepulse_uptime_normalize_agent_id($entry['agent']);
            }
        } else {
            $raw_status_value = $entry;
            $status = (bool) (is_int($entry) ? $entry : !empty($entry));
        }

        $normalized_status = sitepulse_uptime_normalize_status_value($status);

        if (null === $normalized_status) {
            $normalized_status = 'unknown';
        }

        $status = $normalized_status;

        if ('maintenance' === $status) {
            $incident_start = null;
        } elseif (is_bool($status)) {
            if (false === $status) {
                if (null === $incident_start) {
                    $previous_boolean_entry = null;

                    for ($i = count($normalized) - 1; $i >= 0; $i--) {
                        if (array_key_exists('status', $normalized[$i]) && is_bool($normalized[$i]['status'])) {
                            $previous_boolean_entry = $normalized[$i];
                            break;
                        }
                    }

                    if (null !== $previous_boolean_entry && false === $previous_boolean_entry['status'] && isset($previous_boolean_entry['incident_start'])) {
                        $incident_start = (int) $previous_boolean_entry['incident_start'];
                    }

                    if (null === $incident_start) {
                        $incident_start = $timestamp;
                    }
                }
            } else {
                $incident_start = null;
            }
        } else {
            $incident_start = null;
        }

        $normalized_entry = array_filter([
            'timestamp'      => $timestamp,
            'status'         => $status,
            'incident_start' => $incident_start,
            'error'          => $error_message,
            'agent'          => $agent,
            'raw_status'     => $raw_status_value,
        ], function ($value) {
            return null !== $value;
        });

        if (array_key_exists('raw_status', $normalized_entry)) {
            if ($normalized_entry['raw_status'] === $status) {
                unset($normalized_entry['raw_status']);
            } elseif (is_bool($status) && is_bool($normalized_entry['raw_status'])) {
                if ($normalized_entry['raw_status'] === $status) {
                    unset($normalized_entry['raw_status']);
                }
            }
        }

        $normalized[] = $normalized_entry;
    }

    return array_values($normalized);
}

/**
 * Returns the configured history retention (in days) for uptime measurements.
 *
 * @return int
 */
function sitepulse_get_uptime_history_retention_days() {
    $default = defined('SITEPULSE_DEFAULT_UPTIME_HISTORY_RETENTION_DAYS')
        ? (int) SITEPULSE_DEFAULT_UPTIME_HISTORY_RETENTION_DAYS
        : 90;

    $option_value = get_option(SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS, $default);

    if (!is_numeric($option_value)) {
        $option_value = $default;
    }

    $retention_days = (int) $option_value;

    if ($retention_days < 30) {
        $retention_days = 30;
    } elseif ($retention_days > 365) {
        $retention_days = 365;
    }

    if (function_exists('apply_filters')) {
        $retention_days = (int) apply_filters('sitepulse_uptime_history_retention_days', $retention_days);
    }

    return max(30, min(365, $retention_days));
}

/**
 * Trims the uptime log according to the configured retention period.
 *
 * @param array $log Normalized uptime log entries.
 * @return array<int,array<string,mixed>>
 */
function sitepulse_trim_uptime_log($log) {
    if (!is_array($log) || empty($log)) {
        return [];
    }

    $retention_days = sitepulse_get_uptime_history_retention_days();
    $day_in_seconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
    $retention_seconds = max(1, $retention_days) * $day_in_seconds;
    $cutoff_timestamp = (int) current_time('timestamp') - $retention_seconds;

    $filtered = [];

    foreach ($log as $entry) {
        if (!is_array($entry)) {
            $filtered[] = $entry;
            continue;
        }

        if (!isset($entry['timestamp'])) {
            $filtered[] = $entry;
            continue;
        }

        if ((int) $entry['timestamp'] >= $cutoff_timestamp) {
            $filtered[] = $entry;
        }
    }

    $default_interval = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
    $interval = max(1, sitepulse_uptime_tracker_resolve_schedule_interval($default_interval));
    $max_entries = (int) ceil($retention_seconds / $interval);

    // Provide a safety margin to avoid trimming legitimate data when the schedule changes.
    $max_entries = max($max_entries, $retention_days);

    if (empty($filtered)) {
        $filtered = array_slice(array_values($log), -$max_entries);
    }

    if (count($filtered) > $max_entries) {
        $filtered = array_slice($filtered, -$max_entries);
    }

    return array_values($filtered);
}

/**
 * Builds aggregated availability windows based on the raw uptime log.
 *
 * @param array<int,array<string,mixed>>|null $log     Optional log entries to aggregate.
 * @param array<int,int>|null                 $windows Optional window sizes in days.
 * @param array<string,array<string,mixed>>|null $agents Optional agent map.
 * @return array<string,mixed>
 */
function sitepulse_uptime_build_sla_windows($log = null, $windows = null, $agents = null) {
    if (null === $log) {
        $raw_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $log = sitepulse_normalize_uptime_log($raw_log);
    } elseif (!empty($log)) {
        $first_entry = reset($log);

        if (!is_array($first_entry) || !array_key_exists('timestamp', $first_entry)) {
            $log = sitepulse_normalize_uptime_log($log);
        }
    }

    $log = sitepulse_trim_uptime_log($log);

    $default_windows = [7, 30];
    $windows = null === $windows ? $default_windows : (array) $windows;
    $windows = array_values(array_filter(array_map('intval', $windows), function ($value) {
        return $value > 0;
    }));

    if (empty($windows)) {
        $windows = $default_windows;
    }

    $now = (int) current_time('timestamp');
    $window_map = [];

    foreach ($windows as $days) {
        $key = $days . 'd';
        $window_map[$key] = [
            'label' => sprintf(_n('%s jour', '%s jours', $days, 'sitepulse'), number_format_i18n($days)),
            'days'  => (int) $days,
            'start' => $now - ($days * DAY_IN_SECONDS),
            'end'   => $now,
        ];
    }

    if (null === $agents) {
        $agents = sitepulse_uptime_get_agents();
    }

    $agents_from_log = [];

    foreach ($log as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $agent = isset($entry['agent']) ? sitepulse_uptime_normalize_agent_id($entry['agent']) : 'default';
        $agents_from_log[$agent] = true;
    }

    $all_agent_ids = array_unique(array_merge(array_keys($agents_from_log), array_keys((array) $agents), ['default']));
    $agent_profiles = [];

    foreach ($all_agent_ids as $agent_id) {
        $agent_config = isset($agents[$agent_id]) ? $agents[$agent_id] : sitepulse_uptime_get_agent($agent_id);

        $agent_profiles[$agent_id] = [
            'id'      => $agent_id,
            'label'   => isset($agent_config['label']) && is_string($agent_config['label']) && '' !== $agent_config['label']
                ? $agent_config['label']
                : ($agent_id === 'default' ? __('Agent principal', 'sitepulse') : $agent_id),
            'region'  => isset($agent_config['region']) ? sanitize_key($agent_config['region']) : 'global',
            'weight'  => sitepulse_uptime_get_agent_weight($agent_id, $agent_config),
            'active'  => sitepulse_uptime_agent_is_active($agent_id, $agent_config),
        ];
    }

    $entries_per_agent = [];

    foreach ($log as $entry) {
        if (!is_array($entry) || !isset($entry['timestamp'])) {
            continue;
        }

        $agent_id = isset($entry['agent']) ? sitepulse_uptime_normalize_agent_id($entry['agent']) : 'default';

        if (!isset($entries_per_agent[$agent_id])) {
            $entries_per_agent[$agent_id] = [];
        }

        $entries_per_agent[$agent_id][] = $entry;
    }

    $windows_payload = [];
    $overall_start = $now;

    foreach ($window_map as $window_key => $window_data) {
        $window_start = (int) $window_data['start'];
        $window_end = (int) $window_data['end'];

        if ($window_start < $overall_start) {
            $overall_start = $window_start;
        }

        $window_agents = [];
        $global_totals = [
            'availability'      => 100.0,
            'total_checks'      => 0,
            'up_checks'         => 0,
            'down_checks'       => 0,
            'unknown_checks'    => 0,
            'maintenance_checks'=> 0,
            'effective_checks'  => 0,
            'downtime_total'    => 0,
            'maintenance_total' => 0,
            'incident_count'    => 0,
            'maintenance_count' => 0,
        ];
        $weighted_total = 0.0;
        $weighted_up = 0.0;

        foreach ($agent_profiles as $agent_id => $profile) {
            if (!$profile['active'] && !isset($entries_per_agent[$agent_id])) {
                continue;
            }

            $agent_entries = isset($entries_per_agent[$agent_id]) ? $entries_per_agent[$agent_id] : [];
            $breakdown = sitepulse_uptime_calculate_agent_window_breakdown($agent_entries, $window_start, $window_end);

            if (empty($agent_entries) && 0 === $breakdown['total_checks'] && 0 === $breakdown['maintenance_checks']) {
                if (!$profile['active']) {
                    continue;
                }
            }

            $window_agents[$agent_id] = $breakdown;

            $global_totals['total_checks'] += $breakdown['total_checks'];
            $global_totals['up_checks'] += $breakdown['up_checks'];
            $global_totals['down_checks'] += $breakdown['down_checks'];
            $global_totals['unknown_checks'] += $breakdown['unknown_checks'];
            $global_totals['maintenance_checks'] += $breakdown['maintenance_checks'];
            $global_totals['effective_checks'] += $breakdown['effective_checks'];
            $global_totals['downtime_total'] += isset($breakdown['downtime']['total_duration'])
                ? (float) $breakdown['downtime']['total_duration']
                : 0.0;
            $global_totals['maintenance_total'] += isset($breakdown['maintenance']['total_duration'])
                ? (float) $breakdown['maintenance']['total_duration']
                : 0.0;
            $global_totals['incident_count'] += isset($breakdown['downtime']['incidents'])
                ? count($breakdown['downtime']['incidents'])
                : 0;
            $global_totals['maintenance_count'] += isset($breakdown['maintenance']['windows'])
                ? count($breakdown['maintenance']['windows'])
                : 0;

            if ($profile['weight'] > 0 && $breakdown['effective_checks'] > 0) {
                $weighted_total += $breakdown['effective_checks'] * $profile['weight'];
                $weighted_up += $breakdown['up_checks'] * $profile['weight'];
            }
        }

        if ($weighted_total > 0) {
            $global_totals['availability'] = ($weighted_up / $weighted_total) * 100;
        } elseif ($global_totals['effective_checks'] > 0) {
            $global_totals['availability'] = ($global_totals['up_checks'] / max(1, $global_totals['effective_checks'])) * 100;
        }

        $windows_payload[$window_key] = array_merge($window_data, [
            'agents' => $window_agents,
            'global' => $global_totals,
        ]);
    }

    return [
        'generated_at' => $now,
        'period'       => [
            'start' => $overall_start,
            'end'   => $now,
        ],
        'windows'      => $windows_payload,
        'agents'       => $agent_profiles,
        'entries'      => count($log),
    ];
}

/**
 * Computes detailed availability statistics for a single agent within a time window.
 *
 * @param array<int,array<string,mixed>> $entries      Agent-specific entries.
 * @param int                            $window_start Window start timestamp.
 * @param int                            $window_end   Window end timestamp.
 * @return array<string,mixed>
 */
function sitepulse_uptime_calculate_agent_window_breakdown($entries, $window_start, $window_end) {
    $window_start = (int) $window_start;
    $window_end = (int) $window_end;

    $result = [
        'start'              => $window_start,
        'end'                => $window_end,
        'availability'       => 100.0,
        'total_checks'       => 0,
        'up_checks'          => 0,
        'down_checks'        => 0,
        'unknown_checks'     => 0,
        'maintenance_checks' => 0,
        'effective_checks'   => 0,
        'downtime'           => [
            'total_duration' => 0.0,
            'incidents'      => [],
        ],
        'maintenance'        => [
            'total_duration' => 0.0,
            'windows'        => [],
        ],
    ];

    if (empty($entries) || $window_end <= $window_start) {
        return $result;
    }

    $entries = array_values($entries);
    $previous_entry = null;

    foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry['timestamp'])) {
            continue;
        }

        $entry_timestamp = (int) $entry['timestamp'];

        if ($entry_timestamp < $window_start) {
            $previous_entry = $entry;
            continue;
        }

        break;
    }

    $current_state = 'unknown';
    $current_incident = null;
    $current_maintenance = null;

    if (null !== $previous_entry) {
        $state = isset($previous_entry['status'])
            ? sitepulse_uptime_normalize_status_value($previous_entry['status'])
            : null;

        if (null === $state) {
            $state = 'unknown';
        }

        $current_state = $state;

        if (false === $state) {
            $incident_start = isset($previous_entry['incident_start'])
                ? (int) $previous_entry['incident_start']
                : (int) $previous_entry['timestamp'];

            if ($incident_start < $window_start) {
                $incident_start = $window_start;
            }

            $current_incident = ['start' => $incident_start];
        } elseif ('maintenance' === $state) {
            $current_maintenance = ['start' => $window_start];
        }
    }

    $previous_timestamp = $window_start;

    foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry['timestamp'])) {
            continue;
        }

        $original_timestamp = (int) $entry['timestamp'];

        if ($original_timestamp < $window_start) {
            continue;
        }

        $timestamp = $original_timestamp;

        if ($timestamp > $window_end) {
            $timestamp = $window_end;
        }

        if ($timestamp < $previous_timestamp) {
            $timestamp = $previous_timestamp;
        }

        $delta = $timestamp - $previous_timestamp;

        if ($delta > 0) {
            if (false === $current_state) {
                $result['downtime']['total_duration'] += $delta;
            } elseif ('maintenance' === $current_state) {
                $result['maintenance']['total_duration'] += $delta;
            }
        }

        if ($original_timestamp > $window_end) {
            $previous_timestamp = $timestamp;
            break;
        }

        $status = isset($entry['status']) ? sitepulse_uptime_normalize_status_value($entry['status']) : null;

        if (null === $status) {
            $status = 'unknown';
        }

        if ($current_incident && false !== $status) {
            $incident_end = $timestamp;

            if ($incident_end < $current_incident['start']) {
                $incident_end = $current_incident['start'];
            }

            $result['downtime']['incidents'][] = [
                'start'    => $current_incident['start'],
                'end'      => $incident_end,
                'duration' => max(0, $incident_end - $current_incident['start']),
            ];

            $current_incident = null;
        }

        if ($current_maintenance && 'maintenance' !== $status) {
            $maintenance_end = $timestamp;

            if ($maintenance_end < $current_maintenance['start']) {
                $maintenance_end = $current_maintenance['start'];
            }

            $result['maintenance']['windows'][] = [
                'start'    => $current_maintenance['start'],
                'end'      => $maintenance_end,
                'duration' => max(0, $maintenance_end - $current_maintenance['start']),
            ];

            $current_maintenance = null;
        }

        if ('maintenance' === $status) {
            $result['maintenance_checks']++;
        } else {
            $result['total_checks']++;

            if (true === $status) {
                $result['up_checks']++;
            } elseif (false === $status) {
                $result['down_checks']++;
            } else {
                $result['unknown_checks']++;
            }
        }

        $previous_timestamp = $timestamp;
        $current_state = $status;

        if (false === $status) {
            $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : $timestamp;

            if ($incident_start < $window_start) {
                $incident_start = $window_start;
            } elseif ($incident_start > $timestamp) {
                $incident_start = $timestamp;
            }

            $current_incident = ['start' => $incident_start];
        } elseif ('maintenance' === $status) {
            $current_maintenance = ['start' => max($window_start, $timestamp)];
        }
    }

    $final_delta = $window_end - $previous_timestamp;

    if ($final_delta > 0) {
        if (false === $current_state) {
            $result['downtime']['total_duration'] += $final_delta;
        } elseif ('maintenance' === $current_state) {
            $result['maintenance']['total_duration'] += $final_delta;
        }
    }

    if ($current_incident) {
        $incident_end = $window_end;

        if ($incident_end < $current_incident['start']) {
            $incident_end = $current_incident['start'];
        }

        $result['downtime']['incidents'][] = [
            'start'    => $current_incident['start'],
            'end'      => $incident_end,
            'duration' => max(0, $incident_end - $current_incident['start']),
        ];
    }

    if ($current_maintenance) {
        $maintenance_end = $window_end;

        if ($maintenance_end < $current_maintenance['start']) {
            $maintenance_end = $current_maintenance['start'];
        }

        $result['maintenance']['windows'][] = [
            'start'    => $current_maintenance['start'],
            'end'      => $maintenance_end,
            'duration' => max(0, $maintenance_end - $current_maintenance['start']),
        ];
    }

    $result['downtime']['incidents'] = array_values($result['downtime']['incidents']);
    $result['maintenance']['windows'] = array_values($result['maintenance']['windows']);
    $result['downtime']['total_duration'] = (float) $result['downtime']['total_duration'];
    $result['maintenance']['total_duration'] = (float) $result['maintenance']['total_duration'];
    $result['effective_checks'] = $result['total_checks'];

    if ($result['effective_checks'] > 0) {
        $result['availability'] = ($result['up_checks'] / max(1, $result['effective_checks'])) * 100;
    }

    return $result;
}

/**
 * Returns the directory used to persist SLA report artifacts.
 *
 * @return array<string,string>|WP_Error
 */
function sitepulse_uptime_get_sla_reports_directory() {
    if (!function_exists('wp_upload_dir')) {
        return new WP_Error('sitepulse_upload_unsupported', __('Le répertoire d’upload est indisponible.', 'sitepulse'));
    }

    $uploads = wp_upload_dir();

    if (!is_array($uploads) || !empty($uploads['error'])) {
        $message = isset($uploads['error']) ? $uploads['error'] : __('Impossible de déterminer le répertoire d’upload.', 'sitepulse');

        return new WP_Error('sitepulse_upload_error', $message);
    }

    $base_dir = trailingslashit($uploads['basedir']);
    $base_url = trailingslashit($uploads['baseurl']);
    $reports_dir = $base_dir . SITEPULSE_UPTIME_SLA_DIRECTORY;
    $reports_url = $base_url . SITEPULSE_UPTIME_SLA_DIRECTORY;

    if (!wp_mkdir_p($reports_dir)) {
        return new WP_Error('sitepulse_upload_permission', __('Impossible de créer le dossier des rapports SLA.', 'sitepulse'));
    }

    return [
        'path' => trailingslashit($reports_dir),
        'url'  => trailingslashit($reports_url),
    ];
}

/**
 * Persists a consolidated SLA report and returns its metadata.
 *
 * @param string $trigger  Report trigger (manual, automation, queue...).
 * @param array<int,int>|null $windows Optional windows in days.
 * @return array<string,mixed>|WP_Error
 */
function sitepulse_uptime_generate_sla_report($trigger = 'manual', $windows = null) {
    $aggregation = sitepulse_uptime_build_sla_windows(null, $windows, sitepulse_uptime_get_agents());
    $directory = sitepulse_uptime_get_sla_reports_directory();

    if (is_wp_error($directory)) {
        return $directory;
    }

    $timestamp_utc = (int) current_time('timestamp', true);
    $report_id = gmdate('Ymd-His', $timestamp_utc);
    $base_filename = sprintf('sitepulse-uptime-sla-%s', $report_id);
    $csv_path = $directory['path'] . $base_filename . '.csv';
    $pdf_path = $directory['path'] . $base_filename . '.pdf';
    $json_path = $directory['path'] . $base_filename . '.json';

    $csv_result = sitepulse_uptime_write_sla_csv($aggregation, $csv_path);

    if (is_wp_error($csv_result)) {
        return $csv_result;
    }

    $pdf_result = sitepulse_uptime_write_sla_pdf($aggregation, $pdf_path);

    if (is_wp_error($pdf_result)) {
        return $pdf_result;
    }

    $json_payload = function_exists('wp_json_encode')
        ? wp_json_encode($aggregation, JSON_PRETTY_PRINT)
        : json_encode($aggregation, JSON_PRETTY_PRINT);

    if (!is_string($json_payload)) {
        $json_payload = '{}';
    }

    if (false === file_put_contents($json_path, $json_payload)) {
        return new WP_Error('sitepulse_report_write_failed', __('Impossible d’écrire les métadonnées du rapport SLA.', 'sitepulse'));
    }

    $agents_included = [];

    foreach ($aggregation['agents'] as $agent_id => $agent_profile) {
        if (!isset($aggregation['windows'])) {
            continue;
        }

        foreach ($aggregation['windows'] as $window_details) {
            if (isset($window_details['agents'][$agent_id])) {
                $agents_included[$agent_id] = $agent_profile['label'];
                break;
            }
        }
    }

    $metadata = [
        'id'           => $report_id,
        'trigger'      => sanitize_key($trigger),
        'generated_at' => (int) $aggregation['generated_at'],
        'period'       => $aggregation['period'],
        'windows'      => array_keys($aggregation['windows']),
        'agents'       => $agents_included,
        'files'        => [
            'csv'  => [
                'path' => $csv_path,
                'url'  => $directory['url'] . basename($csv_path),
            ],
            'pdf'  => [
                'path' => $pdf_path,
                'url'  => $directory['url'] . basename($pdf_path),
            ],
            'json' => [
                'path' => $json_path,
                'url'  => $directory['url'] . basename($json_path),
            ],
        ],
        'summary'      => [],
    ];

    foreach ($aggregation['windows'] as $window_key => $window_details) {
        $metadata['summary'][$window_key] = [
            'availability'   => isset($window_details['global']['availability']) ? (float) $window_details['global']['availability'] : 100.0,
            'downtime'       => isset($window_details['global']['downtime_total']) ? (float) $window_details['global']['downtime_total'] : 0.0,
            'maintenance'    => isset($window_details['global']['maintenance_total']) ? (float) $window_details['global']['maintenance_total'] : 0.0,
            'incident_count' => isset($window_details['global']['incident_count']) ? (int) $window_details['global']['incident_count'] : 0,
        ];
    }

    sitepulse_uptime_store_sla_report_metadata($metadata);

    /**
     * Fires when a SLA report has been generated and stored.
     *
     * @param array<string,mixed> $metadata    Stored metadata.
     * @param array<string,mixed> $aggregation Full aggregation payload.
     */
    do_action('sitepulse_uptime_sla_report_generated', $metadata, $aggregation);

    sitepulse_uptime_send_report_notifications($metadata, $aggregation);

    return $metadata;
}

/**
 * Writes the CSV representation of the SLA report.
 *
 * @param array<string,mixed> $aggregation Aggregated data.
 * @param string              $destination Destination path.
 * @return true|WP_Error
 */
function sitepulse_uptime_write_sla_csv($aggregation, $destination) {
    $handle = fopen($destination, 'wb');

    if (false === $handle) {
        return new WP_Error('sitepulse_report_csv', __('Impossible de créer le fichier CSV du rapport SLA.', 'sitepulse'));
    }

    fwrite($handle, "\xEF\xBB\xBF");

    $date_format = get_option('date_format', 'Y-m-d');
    $time_format = get_option('time_format', 'H:i');
    $generated_at = isset($aggregation['generated_at']) ? (int) $aggregation['generated_at'] : (int) current_time('timestamp');
    $period = isset($aggregation['period']) ? $aggregation['period'] : ['start' => $generated_at, 'end' => $generated_at];
    $generated_label = function_exists('wp_date')
        ? wp_date($date_format . ' ' . $time_format, $generated_at)
        : date($date_format . ' ' . $time_format, $generated_at);
    $period_label = sitepulse_uptime_format_report_period($period, $date_format, $time_format);

    fputcsv($handle, sitepulse_uptime_escape_csv_row(['SitePulse SLA Report']));
    fputcsv($handle, sitepulse_uptime_escape_csv_row([__('Période couverte', 'sitepulse'), $period_label]));
    fputcsv($handle, sitepulse_uptime_escape_csv_row([__('Généré le', 'sitepulse'), $generated_label]));
    fputcsv($handle, sitepulse_uptime_escape_csv_row([]));

    foreach ($aggregation['windows'] as $window_key => $window_details) {
        $window_label = isset($window_details['label']) ? $window_details['label'] : $window_key;
        $availability = isset($window_details['global']['availability'])
            ? number_format_i18n((float) $window_details['global']['availability'], 2)
            : '100.00';
        $downtime = isset($window_details['global']['downtime_total']) ? (float) $window_details['global']['downtime_total'] : 0.0;
        $maintenance = isset($window_details['global']['maintenance_total']) ? (float) $window_details['global']['maintenance_total'] : 0.0;
        $incidents = isset($window_details['global']['incident_count']) ? (int) $window_details['global']['incident_count'] : 0;

        fputcsv($handle, sitepulse_uptime_escape_csv_row([sprintf(__('Fenêtre %s', 'sitepulse'), $window_label)]));
        fputcsv($handle, sitepulse_uptime_escape_csv_row([
            __('Disponibilité moyenne (%)', 'sitepulse'),
            $availability,
            __('Incidents', 'sitepulse'),
            number_format_i18n($incidents),
            __('Durée indisponibilité (s)', 'sitepulse'),
            number_format_i18n($downtime, 2),
            __('Fenêtres de maintenance (s)', 'sitepulse'),
            number_format_i18n($maintenance, 2),
        ]));

        $header = [
            __('Agent', 'sitepulse'),
            __('Région', 'sitepulse'),
            __('Disponibilité (%)', 'sitepulse'),
            __('Contrôles', 'sitepulse'),
            __('Incidents', 'sitepulse'),
            __('Durée incidents (s)', 'sitepulse'),
            __('Maintenance (s)', 'sitepulse'),
        ];
        fputcsv($handle, sitepulse_uptime_escape_csv_row($header));

        foreach ($window_details['agents'] as $agent_id => $agent_breakdown) {
            $profile = isset($aggregation['agents'][$agent_id]) ? $aggregation['agents'][$agent_id] : ['label' => $agent_id, 'region' => 'global'];
            $agent_availability = isset($agent_breakdown['availability']) ? number_format_i18n((float) $agent_breakdown['availability'], 2) : '100.00';
            $incident_count = isset($agent_breakdown['downtime']['incidents']) ? count($agent_breakdown['downtime']['incidents']) : 0;
            $downtime_total = isset($agent_breakdown['downtime']['total_duration']) ? (float) $agent_breakdown['downtime']['total_duration'] : 0.0;
            $maintenance_total = isset($agent_breakdown['maintenance']['total_duration']) ? (float) $agent_breakdown['maintenance']['total_duration'] : 0.0;
            $effective_checks = isset($agent_breakdown['effective_checks']) ? (int) $agent_breakdown['effective_checks'] : 0;

            fputcsv($handle, sitepulse_uptime_escape_csv_row([
                $profile['label'],
                isset($profile['region']) ? $profile['region'] : 'global',
                $agent_availability,
                number_format_i18n($effective_checks),
                number_format_i18n($incident_count),
                number_format_i18n($downtime_total, 2),
                number_format_i18n($maintenance_total, 2),
            ]));
        }

        fputcsv($handle, sitepulse_uptime_escape_csv_row([]));
    }

    fclose($handle);

    return true;
}

/**
 * Writes a minimal PDF report summarising SLA metrics.
 *
 * @param array<string,mixed> $aggregation Aggregated data.
 * @param string              $destination File path.
 * @return true|WP_Error
 */
function sitepulse_uptime_write_sla_pdf($aggregation, $destination) {
    $lines = [];
    $lines[] = 'SitePulse SLA Report';
    $period = isset($aggregation['period']) ? $aggregation['period'] : ['start' => $aggregation['generated_at'], 'end' => $aggregation['generated_at']];
    $lines[] = sprintf(__('Période : %s', 'sitepulse'), sitepulse_uptime_format_report_period($period));
    $lines[] = sprintf(__('Généré le : %s', 'sitepulse'), date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $aggregation['generated_at']));
    $lines[] = '';

    foreach ($aggregation['windows'] as $window_key => $window_details) {
        $window_label = isset($window_details['label']) ? $window_details['label'] : $window_key;
        $lines[] = sprintf(__('Fenêtre %s', 'sitepulse'), $window_label);
        $lines[] = sprintf('  %s: %s%%', __('Disponibilité', 'sitepulse'), number_format_i18n((float) $window_details['global']['availability'], 2));
        $lines[] = sprintf('  %s: %s', __('Incidents', 'sitepulse'), number_format_i18n((int) $window_details['global']['incident_count']));
        $lines[] = sprintf('  %s: %s', __('Indisponibilité', 'sitepulse'), sitepulse_uptime_format_duration_i18n($window_details['global']['downtime_total']));
        $lines[] = sprintf('  %s: %s', __('Maintenance', 'sitepulse'), sitepulse_uptime_format_duration_i18n($window_details['global']['maintenance_total']));

        foreach ($window_details['agents'] as $agent_id => $agent_breakdown) {
            if (!isset($aggregation['agents'][$agent_id])) {
                continue;
            }

            $profile = $aggregation['agents'][$agent_id];
            $lines[] = sprintf('    • %s (%s) — %s%%', $profile['label'], isset($profile['region']) ? $profile['region'] : 'global', number_format_i18n((float) $agent_breakdown['availability'], 2));
        }

        $lines[] = '';
    }

    return sitepulse_uptime_generate_simple_pdf($lines, $destination);
}

/**
 * Generates a minimalist PDF file from text lines.
 *
 * @param array<int,string> $lines Text lines.
 * @param string            $destination File path.
 * @return true|WP_Error
 */
function sitepulse_uptime_generate_simple_pdf($lines, $destination) {
    $content_stream = "BT\n/F1 12 Tf\n1 0 0 1 72 770 Tm\n";
    $first_line = true;

    foreach ($lines as $line) {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $line);

        if (!$first_line) {
            $content_stream .= "0 -16 Td\n";
        }

        $content_stream .= '(' . $escaped . ") Tj\n";
        $first_line = false;
    }

    $content_stream .= "ET\n";
    $content_length = strlen($content_stream);

    $objects = [];
    $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj";
    $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj";
    $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj";
    $objects[] = sprintf("4 0 obj << /Length %d >> stream\n%s\nendstream endobj", $content_length, $content_stream);
    $objects[] = "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj";

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object . "\n";
    }

    $xref_position = strlen($pdf);
    $pdf .= 'xref' . "\n";
    $pdf .= '0 ' . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xref_position . "\n";
    $pdf .= "%%EOF";

    if (false === file_put_contents($destination, $pdf)) {
        return new WP_Error('sitepulse_report_pdf', __('Impossible de générer le PDF du rapport SLA.', 'sitepulse'));
    }

    return true;
}

/**
 * Stores the last generated reports metadata in the dedicated option.
 *
 * @param array<string,mixed> $metadata Report metadata.
 * @return void
 */
function sitepulse_uptime_store_sla_report_metadata($metadata) {
    $existing = get_option(SITEPULSE_OPTION_UPTIME_SLA_REPORTS, []);

    if (!is_array($existing)) {
        $existing = [];
    }

    array_unshift($existing, $metadata);
    $existing = array_slice($existing, 0, 10);

    update_option(SITEPULSE_OPTION_UPTIME_SLA_REPORTS, array_values($existing), false);
}

/**
 * Retrieves the persisted SLA report metadata entries.
 *
 * @param int $limit Maximum entries to return.
 * @return array<int,array<string,mixed>>
 */
function sitepulse_uptime_get_sla_reports($limit = 10) {
    $reports = get_option(SITEPULSE_OPTION_UPTIME_SLA_REPORTS, []);

    if (!is_array($reports) || empty($reports)) {
        return [];
    }

    return array_slice($reports, 0, max(1, (int) $limit));
}

/**
 * Sends notifications (email/webhook) after a report generation.
 *
 * @param array<string,mixed> $metadata    Report metadata.
 * @param array<string,mixed> $aggregation Aggregation payload.
 * @return void
 */
function sitepulse_uptime_send_report_notifications($metadata, $aggregation) {
    $settings = sitepulse_uptime_get_sla_automation_settings();

    if (empty($settings['email_enabled']) && empty($settings['webhook_enabled'])) {
        return;
    }

    $subject = sprintf(__('Rapport SLA SitePulse (%s)', 'sitepulse'), sitepulse_uptime_format_report_period($metadata['period']));
    $body_lines = [];
    $body_lines[] = __('Bonjour,', 'sitepulse');
    $body_lines[] = '';
    $body_lines[] = sprintf(__('Votre rapport SLA vient d’être généré (%s).', 'sitepulse'), sitepulse_uptime_format_report_period($metadata['period']));

    foreach ($metadata['summary'] as $window_key => $window_summary) {
        $body_lines[] = sprintf(
            '- %s : %s%% (%s incidents, %s indisponibilité)',
            $window_key,
            number_format_i18n($window_summary['availability'], 2),
            number_format_i18n($window_summary['incident_count']),
            sitepulse_uptime_format_duration_i18n($window_summary['downtime'])
        );
    }

    $body_lines[] = '';
    $body_lines[] = __('Les rapports CSV et PDF sont disponibles en pièce jointe.', 'sitepulse');
    $body_lines[] = __('— Équipe SitePulse', 'sitepulse');
    $body = implode("\n", $body_lines);

    if (!empty($settings['email_enabled']) && !empty($settings['recipients'])) {
        $attachments = [];

        if (isset($metadata['files']['csv']['path']) && file_exists($metadata['files']['csv']['path'])) {
            $attachments[] = $metadata['files']['csv']['path'];
        }

        if (isset($metadata['files']['pdf']['path']) && file_exists($metadata['files']['pdf']['path'])) {
            $attachments[] = $metadata['files']['pdf']['path'];
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        wp_mail($settings['recipients'], $subject, $body, $headers, $attachments);
    }

    if (!empty($settings['webhook_enabled']) && !empty($settings['webhook_url'])) {
        sitepulse_uptime_dispatch_report_webhook($settings['webhook_url'], $metadata, $aggregation);
    }
}

/**
 * Dispatches a webhook request containing report metadata.
 *
 * @param string $url        Webhook URL.
 * @param array  $metadata   Metadata to send.
 * @param array  $aggregation Aggregated data.
 * @return void
 */
function sitepulse_uptime_dispatch_report_webhook($url, $metadata, $aggregation) {
    if (!function_exists('wp_remote_post')) {
        return;
    }

    $payload = [
        'report'      => $metadata,
        'aggregation' => $aggregation,
        'site'        => get_bloginfo('name'),
        'generated'   => gmdate('c', (int) $metadata['generated_at']),
    ];

    $args = [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload),
        'timeout' => 15,
    ];

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response) && function_exists('sitepulse_log')) {
        sitepulse_log(sprintf('Webhook SLA report failed: %s', $response->get_error_message()), 'WARNING');
    }
}

/**
 * Retrieves and sanitizes SLA automation settings.
 *
 * @return array<string,mixed>
 */
function sitepulse_uptime_get_sla_automation_settings() {
    $defaults = [
        'enabled'        => false,
        'frequency'      => 'monthly',
        'email_enabled'  => false,
        'recipients'     => [],
        'webhook_enabled'=> false,
        'webhook_url'    => '',
        'windows'        => [7, 30],
        'next_run'       => 0,
    ];

    $stored = get_option(SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION, []);

    if (!is_array($stored)) {
        $stored = [];
    }

    $settings = array_merge($defaults, $stored);
    $settings['enabled'] = (bool) $settings['enabled'];
    $settings['email_enabled'] = (bool) $settings['email_enabled'];
    $settings['webhook_enabled'] = (bool) $settings['webhook_enabled'];
    $settings['frequency'] = in_array($settings['frequency'], ['weekly', 'monthly'], true) ? $settings['frequency'] : 'monthly';
    $settings['next_run'] = isset($settings['next_run']) ? (int) $settings['next_run'] : 0;

    if (!is_array($settings['windows']) || empty($settings['windows'])) {
        $settings['windows'] = [7, 30];
    } else {
        $settings['windows'] = array_values(array_filter(array_map('intval', $settings['windows']), function ($value) {
            return $value > 0;
        }));

        if (empty($settings['windows'])) {
            $settings['windows'] = [7, 30];
        }
    }

    if (!is_array($settings['recipients'])) {
        $settings['recipients'] = [];
    }

    $settings['recipients'] = array_values(array_filter(array_map('sanitize_email', $settings['recipients'])));

    if (!$settings['webhook_enabled'] || empty($settings['webhook_url']) || !wp_http_validate_url($settings['webhook_url'])) {
        $settings['webhook_enabled'] = false;
        $settings['webhook_url'] = '';
    }

    if ($settings['email_enabled'] && empty($settings['recipients'])) {
        $settings['email_enabled'] = false;
    }

    return $settings;
}

/**
 * Schedules the next automated SLA report generation through the remote queue.
 *
 * @param array<string,mixed> $settings Automation settings.
 * @param bool                $force    Force recalculation of the next run.
 * @return void
 */
function sitepulse_uptime_schedule_automation_job($settings, $force = false) {
    if (empty($settings['enabled'])) {
        return;
    }

    $interval = sitepulse_uptime_get_automation_interval($settings['frequency']);
    $now = (int) current_time('timestamp', true);
    $next_run = isset($settings['next_run']) ? (int) $settings['next_run'] : 0;

    if ($force || $next_run <= $now) {
        $next_run = $now + $interval;
    }

    $payload = [
        'task'       => 'uptime_sla_report',
        'windows'    => $settings['windows'],
        'automation' => true,
        'frequency'  => $settings['frequency'],
    ];

    $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);

    if (!is_array($queue)) {
        $queue = [];
    }

    $queue[] = [
        'agent'       => 'sitepulse-reports',
        'payload'     => $payload,
        'scheduled_at'=> $next_run,
        'created_at'  => $now,
        'priority'    => 1,
    ];

    $queue = sitepulse_uptime_normalize_remote_queue($queue, $now);
    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, $queue, false);
    sitepulse_uptime_maybe_schedule_queue_processor($next_run);

    $settings['next_run'] = $next_run;
    update_option(SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION, $settings, false);
}

/**
 * Removes pending SLA automation jobs from the remote queue.
 *
 * @return void
 */
function sitepulse_uptime_cancel_automation_job() {
    $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);

    if (!is_array($queue) || empty($queue)) {
        return;
    }

    $filtered = [];

    foreach ($queue as $item) {
        if (!is_array($item)) {
            continue;
        }

        $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : [];

        if (isset($payload['task']) && 'uptime_sla_report' === $payload['task']) {
            continue;
        }

        $filtered[] = $item;
    }

    if (count($filtered) === count($queue)) {
        return;
    }

    $filtered = sitepulse_uptime_normalize_remote_queue($filtered);
    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, $filtered, false);
}

/**
 * Converts an automation frequency into seconds.
 *
 * @param string $frequency Frequency identifier.
 * @return int
 */
function sitepulse_uptime_get_automation_interval($frequency) {
    $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

    if ('weekly' === $frequency) {
        return 7 * $day;
    }

    return 30 * $day;
}

/**
 * Formats the report period as a human readable string.
 *
 * @param array<string,int> $period      Period metadata.
 * @param string            $date_format Optional date format.
 * @param string            $time_format Optional time format.
 * @return string
 */
function sitepulse_uptime_format_report_period($period, $date_format = null, $time_format = null) {
    $date_format = null === $date_format ? get_option('date_format', 'Y-m-d') : $date_format;
    $time_format = null === $time_format ? get_option('time_format', 'H:i') : $time_format;

    $start = isset($period['start']) ? (int) $period['start'] : 0;
    $end = isset($period['end']) ? (int) $period['end'] : 0;

    if ($start <= 0 || $end <= 0) {
        return '—';
    }

    $format = $date_format . ' ' . $time_format;
    $start_label = function_exists('wp_date') ? wp_date($format, $start) : date($format, $start);
    $end_label = function_exists('wp_date') ? wp_date($format, $end) : date($format, $end);

    return sprintf('%s → %s', $start_label, $end_label);
}

/**
 * Retrieves the persisted uptime archive ordered by day.
 *
 * @return array<string,array<string,int>>
 */
function sitepulse_get_uptime_archive() {
    $archive = get_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, []);

    if (!is_array($archive)) {
        return [];
    }

    uksort($archive, function ($a, $b) {
        return strcmp($a, $b);
    });

    return $archive;
}

/**
 * Stores the provided log entry inside the daily uptime archive.
 *
 * @param array $entry Normalized uptime entry.
 * @return void
 */
function sitepulse_update_uptime_archive($entry) {
    if (!is_array($entry) || empty($entry)) {
        return;
    }

    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : (int) current_time('timestamp');
    $day_key = wp_date('Y-m-d', $timestamp);

    $status_key = 'unknown';
    $agent = isset($entry['agent']) ? sitepulse_uptime_normalize_agent_id($entry['agent']) : 'default';

    if (array_key_exists('status', $entry)) {
        if (true === $entry['status']) {
            $status_key = 'up';
        } elseif (false === $entry['status']) {
            $status_key = 'down';
        } elseif (is_string($entry['status']) && 'maintenance' === $entry['status']) {
            $status_key = 'maintenance';
        } elseif (is_string($entry['status']) && 'unknown' === $entry['status']) {
            $status_key = 'unknown';
        }
    }

    $archive = sitepulse_get_uptime_archive();

    if (!isset($archive[$day_key]) || !is_array($archive[$day_key])) {
        $archive[$day_key] = [
            'date'            => $day_key,
            'up'              => 0,
            'down'            => 0,
            'unknown'         => 0,
            'total'           => 0,
            'maintenance'     => 0,
            'first_timestamp' => $timestamp,
            'last_timestamp'  => $timestamp,
            'latency_sum'     => 0.0,
            'latency_count'   => 0,
            'ttfb_sum'        => 0.0,
            'ttfb_count'      => 0,
            'violations'      => 0,
            'violation_types' => [],
            'agents'          => [],
        ];
    }

    foreach (['latency_sum' => 0.0, 'latency_count' => 0, 'ttfb_sum' => 0.0, 'ttfb_count' => 0, 'violations' => 0] as $metric_key => $default_value) {
        if (!isset($archive[$day_key][$metric_key])) {
            $archive[$day_key][$metric_key] = $default_value;
        }
    }

    if (!isset($archive[$day_key]['violation_types']) || !is_array($archive[$day_key]['violation_types'])) {
        $archive[$day_key]['violation_types'] = [];
    }

    if (!isset($archive[$day_key][$status_key])) {
        $archive[$day_key][$status_key] = 0;
    }

    $archive[$day_key][$status_key]++;
    $archive[$day_key]['total']++;

    $archive[$day_key]['first_timestamp'] = isset($archive[$day_key]['first_timestamp'])
        ? min((int) $archive[$day_key]['first_timestamp'], $timestamp)
        : $timestamp;
    $archive[$day_key]['last_timestamp'] = isset($archive[$day_key]['last_timestamp'])
        ? max((int) $archive[$day_key]['last_timestamp'], $timestamp)
        : $timestamp;

    if (!isset($archive[$day_key]['agents'][$agent])) {
        $archive[$day_key]['agents'][$agent] = [
            'up'              => 0,
            'down'            => 0,
            'unknown'         => 0,
            'maintenance'     => 0,
            'total'           => 0,
            'latency_sum'     => 0.0,
            'latency_count'   => 0,
            'ttfb_sum'        => 0.0,
            'ttfb_count'      => 0,
            'violations'      => 0,
            'violation_types' => [],
        ];
    }

    foreach (['latency_sum' => 0.0, 'latency_count' => 0, 'ttfb_sum' => 0.0, 'ttfb_count' => 0, 'violations' => 0] as $metric_key => $default_value) {
        if (!isset($archive[$day_key]['agents'][$agent][$metric_key])) {
            $archive[$day_key]['agents'][$agent][$metric_key] = $default_value;
        }
    }

    if (!isset($archive[$day_key]['agents'][$agent]['violation_types']) || !is_array($archive[$day_key]['agents'][$agent]['violation_types'])) {
        $archive[$day_key]['agents'][$agent]['violation_types'] = [];
    }

    if (!isset($archive[$day_key]['agents'][$agent][$status_key])) {
        $archive[$day_key]['agents'][$agent][$status_key] = 0;
    }

    $archive[$day_key]['agents'][$agent][$status_key]++;
    $archive[$day_key]['agents'][$agent]['total']++;

    $latency_value = isset($entry['latency']) ? (float) $entry['latency'] : null;

    if (null !== $latency_value && $latency_value >= 0) {
        $archive[$day_key]['latency_sum'] += $latency_value;
        $archive[$day_key]['latency_count']++;
        $archive[$day_key]['agents'][$agent]['latency_sum'] += $latency_value;
        $archive[$day_key]['agents'][$agent]['latency_count']++;
    }

    if (isset($entry['ttfb'])) {
        $ttfb_value = (float) $entry['ttfb'];

        if ($ttfb_value >= 0) {
            $archive[$day_key]['ttfb_sum'] += $ttfb_value;
            $archive[$day_key]['ttfb_count']++;
            $archive[$day_key]['agents'][$agent]['ttfb_sum'] += $ttfb_value;
            $archive[$day_key]['agents'][$agent]['ttfb_count']++;
        }
    }

    $entry_violations = [];

    if (isset($entry['violation_types']) && is_array($entry['violation_types'])) {
        $entry_violations = array_values(array_filter(array_map('sanitize_key', $entry['violation_types'])));
    }

    if (!empty($entry_violations)) {
        $archive[$day_key]['violations']++;
        $archive[$day_key]['agents'][$agent]['violations']++;

        foreach ($entry_violations as $violation_type) {
            if (!isset($archive[$day_key]['violation_types'][$violation_type])) {
                $archive[$day_key]['violation_types'][$violation_type] = 0;
            }

            $archive[$day_key]['violation_types'][$violation_type]++;

            if (!isset($archive[$day_key]['agents'][$agent]['violation_types'][$violation_type])) {
                $archive[$day_key]['agents'][$agent]['violation_types'][$violation_type] = 0;
            }

            $archive[$day_key]['agents'][$agent]['violation_types'][$violation_type]++;
        }
    }

    $max_archive_days = sitepulse_get_uptime_history_retention_days();

    if ($max_archive_days > 0 && count($archive) > $max_archive_days) {
        $archive = array_slice($archive, -$max_archive_days, null, true);
    }

    update_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, $archive, false);
}

/**
 * Calculates aggregate metrics for the requested archive window.
 *
 * @param array<string,array<string,int>> $archive Archive of daily totals.
 * @param int                             $days    Number of days to include.
 * @param array<string,array<string,mixed>>|null $agents Optional agent definitions.
 * @return array<string,int|float>
 */
function sitepulse_calculate_uptime_window_metrics($archive, $days, $agents = null) {
    if (!is_array($archive) || empty($archive) || $days < 1) {
        return [
            'days'           => 0,
            'total_checks'   => 0,
            'up_checks'      => 0,
            'down_checks'    => 0,
            'unknown_checks' => 0,
            'uptime'         => 100.0,
            'latency_sum'    => 0.0,
            'latency_count'  => 0,
            'latency_avg'    => null,
            'ttfb_sum'       => 0.0,
            'ttfb_count'     => 0,
            'ttfb_avg'       => null,
            'violations'     => 0,
        ];
    }

    $window = array_slice($archive, -$days, null, true);

    $totals = [
        'days'           => count($window),
        'total_checks'   => 0,
        'up_checks'      => 0,
        'down_checks'    => 0,
        'unknown_checks' => 0,
        'uptime'         => 100.0,
        'latency_sum'    => 0.0,
        'latency_count'  => 0,
        'latency_avg'    => null,
        'ttfb_sum'       => 0.0,
        'ttfb_count'     => 0,
        'ttfb_avg'       => null,
        'violations'     => 0,
    ];

    foreach ($window as $entry) {
        $day_total = isset($entry['total']) ? (int) $entry['total'] : 0;
        $maintenance = isset($entry['maintenance']) ? (int) $entry['maintenance'] : 0;
        $effective_total = max(0, $day_total - $maintenance);

        $totals['total_checks'] += $effective_total;
        $totals['up_checks'] += isset($entry['up']) ? (int) $entry['up'] : 0;
        $totals['down_checks'] += isset($entry['down']) ? (int) $entry['down'] : 0;
        $totals['unknown_checks'] += isset($entry['unknown']) ? (int) $entry['unknown'] : 0;
        $totals['latency_sum'] += isset($entry['latency_sum']) ? (float) $entry['latency_sum'] : 0.0;
        $totals['latency_count'] += isset($entry['latency_count']) ? (int) $entry['latency_count'] : 0;
        $totals['ttfb_sum'] += isset($entry['ttfb_sum']) ? (float) $entry['ttfb_sum'] : 0.0;
        $totals['ttfb_count'] += isset($entry['ttfb_count']) ? (int) $entry['ttfb_count'] : 0;
        $totals['violations'] += isset($entry['violations']) ? (int) $entry['violations'] : 0;
    }

    $agents_for_weights = is_array($agents) ? $agents : sitepulse_uptime_get_agents();
    $agent_metrics = sitepulse_calculate_agent_uptime_metrics($archive, $days, $agents_for_weights);

    $weighted_total = 0.0;
    $weighted_up = 0.0;
    $weighted_down = 0.0;
    $weighted_unknown = 0.0;
    $weighted_latency_sum = 0.0;
    $weighted_latency_count = 0.0;
    $weighted_ttfb_sum = 0.0;
    $weighted_ttfb_count = 0.0;

    foreach ($agent_metrics as $agent_id => $agent_counts) {
        $weight = sitepulse_uptime_get_agent_weight($agent_id, isset($agents_for_weights[$agent_id]) ? $agents_for_weights[$agent_id] : null);

        if ($weight <= 0) {
            continue;
        }

        $weighted_total += (isset($agent_counts['effective_total']) ? (int) $agent_counts['effective_total'] : 0) * $weight;
        $weighted_up += (isset($agent_counts['up']) ? (int) $agent_counts['up'] : 0) * $weight;
        $weighted_down += (isset($agent_counts['down']) ? (int) $agent_counts['down'] : 0) * $weight;
        $weighted_unknown += (isset($agent_counts['unknown']) ? (int) $agent_counts['unknown'] : 0) * $weight;
        $weighted_latency_sum += (isset($agent_counts['latency_sum']) ? (float) $agent_counts['latency_sum'] : 0.0) * $weight;
        $weighted_latency_count += (isset($agent_counts['latency_count']) ? (int) $agent_counts['latency_count'] : 0) * $weight;
        $weighted_ttfb_sum += (isset($agent_counts['ttfb_sum']) ? (float) $agent_counts['ttfb_sum'] : 0.0) * $weight;
        $weighted_ttfb_count += (isset($agent_counts['ttfb_count']) ? (int) $agent_counts['ttfb_count'] : 0) * $weight;
    }

    if ($weighted_total > 0) {
        $totals['uptime'] = ($weighted_up / $weighted_total) * 100;
    } elseif ($totals['total_checks'] > 0) {
        $totals['uptime'] = ($totals['up_checks'] / $totals['total_checks']) * 100;
    }

    if ($weighted_latency_count > 0) {
        $totals['latency_avg'] = $weighted_latency_sum / $weighted_latency_count;
    } elseif ($totals['latency_count'] > 0) {
        $totals['latency_avg'] = $totals['latency_sum'] / $totals['latency_count'];
    }

    if ($weighted_ttfb_count > 0) {
        $totals['ttfb_avg'] = $weighted_ttfb_sum / $weighted_ttfb_count;
    } elseif ($totals['ttfb_count'] > 0) {
        $totals['ttfb_avg'] = $totals['ttfb_sum'] / $totals['ttfb_count'];
    }

    return $totals;
}

/**
 * Aggregates uptime metrics per agent for the provided window.
 *
 * @param array<string,array<string,mixed>> $archive Archive entries.
 * @param int                               $days    Window size.
 * @param array<string,array<string,mixed>>|null $agents Optional agent definitions to filter inactive entries.
 * @return array<string,array<string,mixed>>
 */
function sitepulse_calculate_agent_uptime_metrics($archive, $days, $agents = null) {
    if (!is_array($archive) || empty($archive) || $days < 1) {
        return [];
    }

    $window = array_slice($archive, -$days, null, true);
    $totals = [];
    $active_map = null;

    if (is_array($agents)) {
        $active_map = [];

        foreach ($agents as $agent_id => $agent_config) {
            $active_map[$agent_id] = sitepulse_uptime_agent_is_active($agent_id, $agent_config);
        }
    }

    foreach ($window as $entry) {
        $agents = isset($entry['agents']) && is_array($entry['agents']) ? $entry['agents'] : [];

        if (empty($agents)) {
            $agents = [
                'default' => [
                    'up'          => isset($entry['up']) ? (int) $entry['up'] : 0,
                    'down'        => isset($entry['down']) ? (int) $entry['down'] : 0,
                    'unknown'     => isset($entry['unknown']) ? (int) $entry['unknown'] : 0,
                    'maintenance' => isset($entry['maintenance']) ? (int) $entry['maintenance'] : 0,
                    'total'       => isset($entry['total']) ? (int) $entry['total'] : 0,
                    'latency_sum'     => isset($entry['latency_sum']) ? (float) $entry['latency_sum'] : 0.0,
                    'latency_count'   => isset($entry['latency_count']) ? (int) $entry['latency_count'] : 0,
                    'ttfb_sum'        => isset($entry['ttfb_sum']) ? (float) $entry['ttfb_sum'] : 0.0,
                    'ttfb_count'      => isset($entry['ttfb_count']) ? (int) $entry['ttfb_count'] : 0,
                    'violations'      => isset($entry['violations']) ? (int) $entry['violations'] : 0,
                    'violation_types' => isset($entry['violation_types']) && is_array($entry['violation_types'])
                        ? $entry['violation_types']
                        : [],
                ],
            ];
        }

        foreach ($agents as $agent_id => $agent_totals) {
            if (!isset($totals[$agent_id])) {
                $totals[$agent_id] = [
                    'up'          => 0,
                    'down'        => 0,
                    'unknown'     => 0,
                    'maintenance' => 0,
                    'total'       => 0,
                    'latency_sum'     => 0.0,
                    'latency_count'   => 0,
                    'ttfb_sum'        => 0.0,
                    'ttfb_count'      => 0,
                    'violations'      => 0,
                    'violation_types' => [],
                ];
            }

            $totals[$agent_id]['up'] += isset($agent_totals['up']) ? (int) $agent_totals['up'] : 0;
            $totals[$agent_id]['down'] += isset($agent_totals['down']) ? (int) $agent_totals['down'] : 0;
            $totals[$agent_id]['unknown'] += isset($agent_totals['unknown']) ? (int) $agent_totals['unknown'] : 0;
            $totals[$agent_id]['maintenance'] += isset($agent_totals['maintenance']) ? (int) $agent_totals['maintenance'] : 0;
            $totals[$agent_id]['total'] += isset($agent_totals['total']) ? (int) $agent_totals['total'] : 0;
            $totals[$agent_id]['latency_sum'] += isset($agent_totals['latency_sum']) ? (float) $agent_totals['latency_sum'] : 0.0;
            $totals[$agent_id]['latency_count'] += isset($agent_totals['latency_count']) ? (int) $agent_totals['latency_count'] : 0;
            $totals[$agent_id]['ttfb_sum'] += isset($agent_totals['ttfb_sum']) ? (float) $agent_totals['ttfb_sum'] : 0.0;
            $totals[$agent_id]['ttfb_count'] += isset($agent_totals['ttfb_count']) ? (int) $agent_totals['ttfb_count'] : 0;
            $totals[$agent_id]['violations'] += isset($agent_totals['violations']) ? (int) $agent_totals['violations'] : 0;

            if (isset($agent_totals['violation_types']) && is_array($agent_totals['violation_types'])) {
                foreach ($agent_totals['violation_types'] as $type => $count) {
                    $type_key = sanitize_key($type);

                    if ($type_key === '') {
                        continue;
                    }

                    if (!isset($totals[$agent_id]['violation_types'][$type_key])) {
                        $totals[$agent_id]['violation_types'][$type_key] = 0;
                    }

                    $totals[$agent_id]['violation_types'][$type_key] += (int) $count;
                }
            }
        }
    }

    foreach ($totals as $agent_id => $counts) {
        if (is_array($active_map) && array_key_exists($agent_id, $active_map) && !$active_map[$agent_id]) {
            unset($totals[$agent_id]);
            continue;
        }

        $effective_total = max(0, (int) $counts['total'] - (int) $counts['maintenance']);
        $uptime = $effective_total > 0 ? ($counts['up'] / $effective_total) * 100 : 100;
        $totals[$agent_id]['uptime'] = max(0, min(100, $uptime));
        $totals[$agent_id]['effective_total'] = $effective_total;

        $latency_count = isset($counts['latency_count']) ? (int) $counts['latency_count'] : 0;
        $latency_sum = isset($counts['latency_sum']) ? (float) $counts['latency_sum'] : 0.0;
        $ttfb_count = isset($counts['ttfb_count']) ? (int) $counts['ttfb_count'] : 0;
        $ttfb_sum = isset($counts['ttfb_sum']) ? (float) $counts['ttfb_sum'] : 0.0;

        $totals[$agent_id]['latency_avg'] = $latency_count > 0 ? $latency_sum / $latency_count : null;
        $totals[$agent_id]['ttfb_avg'] = $ttfb_count > 0 ? $ttfb_sum / $ttfb_count : null;
    }

    return $totals;
}

/**
 * Returns the list of archive months available for reporting.
 *
 * @param array<string,array<string,mixed>> $archive Archive entries keyed by Y-m-d.
 * @return array<string,array<string,int|string>>
 */
function sitepulse_uptime_get_archive_months($archive) {
    if (!is_array($archive) || empty($archive)) {
        return [];
    }

    $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $months = [];

    foreach ($archive as $day_key => $entry) {
        if (!is_string($day_key) || $day_key === '') {
            continue;
        }

        $day_date = DateTimeImmutable::createFromFormat('Y-m-d', $day_key, $timezone);

        if (!$day_date) {
            continue;
        }

        $month_key = $day_date->format('Y-m');

        if (!isset($months[$month_key])) {
            $month_start = $day_date->setDate((int) $day_date->format('Y'), (int) $day_date->format('m'), 1)->setTime(0, 0, 0);
            $month_end = $month_start->modify('last day of this month')->setTime(23, 59, 59);
            $label_timestamp = $month_start->getTimestamp();
            $label = function_exists('wp_date') ? wp_date('F Y', $label_timestamp) : $month_start->format('F Y');

            $months[$month_key] = [
                'label' => $label,
                'start' => $month_start->getTimestamp(),
                'end'   => $month_end->getTimestamp(),
                'days'  => 0,
            ];
        }

        $months[$month_key]['days']++;
    }

    if (!empty($months)) {
        krsort($months, SORT_STRING);
    }

    return $months;
}

/**
 * Aggregates uptime metrics for the provided timestamp range.
 *
 * @param array<string,array<string,mixed>> $archive Archive entries keyed by day.
 * @param int                               $start   Start timestamp (inclusive).
 * @param int                               $end     End timestamp (inclusive).
 * @return array<string,mixed>
 */
function sitepulse_uptime_collect_metrics_for_period($archive, $start, $end) {
    if (!is_array($archive) || empty($archive) || $end < $start) {
        return [
            'agents' => [],
            'global' => [
                'days'               => 0,
                'total_checks'       => 0,
                'up_checks'          => 0,
                'down_checks'        => 0,
                'unknown_checks'     => 0,
                'maintenance_checks' => 0,
                'latency_sum'        => 0.0,
                'latency_count'      => 0,
                'ttfb_sum'           => 0.0,
                'ttfb_count'         => 0,
                'violations'         => 0,
            ],
        ];
    }

    $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $agents_totals = [];
    $global = [
        'days'               => 0,
        'total_checks'       => 0,
        'up_checks'          => 0,
        'down_checks'        => 0,
        'unknown_checks'     => 0,
        'maintenance_checks' => 0,
        'latency_sum'        => 0.0,
        'latency_count'      => 0,
        'ttfb_sum'           => 0.0,
        'ttfb_count'         => 0,
        'violations'         => 0,
    ];

    foreach ($archive as $day_key => $entry) {
        if (!is_string($day_key) || $day_key === '') {
            continue;
        }

        $day_date = DateTimeImmutable::createFromFormat('Y-m-d', $day_key, $timezone);

        if (!$day_date) {
            continue;
        }

        $day_timestamp = $day_date->getTimestamp();

        if ($day_timestamp < $start || $day_timestamp > $end) {
            continue;
        }

        $day_total = isset($entry['total']) ? (int) $entry['total'] : 0;
        $maintenance = isset($entry['maintenance']) ? (int) $entry['maintenance'] : 0;
        $effective_total = max(0, $day_total - $maintenance);

        $global['days']++;
        $global['total_checks'] += $effective_total;
        $global['up_checks'] += isset($entry['up']) ? (int) $entry['up'] : 0;
        $global['down_checks'] += isset($entry['down']) ? (int) $entry['down'] : 0;
        $global['unknown_checks'] += isset($entry['unknown']) ? (int) $entry['unknown'] : 0;
        $global['maintenance_checks'] += $maintenance;
        $global['latency_sum'] += isset($entry['latency_sum']) ? (float) $entry['latency_sum'] : 0.0;
        $global['latency_count'] += isset($entry['latency_count']) ? (int) $entry['latency_count'] : 0;
        $global['ttfb_sum'] += isset($entry['ttfb_sum']) ? (float) $entry['ttfb_sum'] : 0.0;
        $global['ttfb_count'] += isset($entry['ttfb_count']) ? (int) $entry['ttfb_count'] : 0;
        $global['violations'] += isset($entry['violations']) ? (int) $entry['violations'] : 0;

        $agents = isset($entry['agents']) && is_array($entry['agents']) ? $entry['agents'] : [];

        if (empty($agents)) {
            $agents = [
                'default' => [
                    'up'              => isset($entry['up']) ? (int) $entry['up'] : 0,
                    'down'            => isset($entry['down']) ? (int) $entry['down'] : 0,
                    'unknown'         => isset($entry['unknown']) ? (int) $entry['unknown'] : 0,
                    'maintenance'     => $maintenance,
                    'total'           => $day_total,
                    'latency_sum'     => isset($entry['latency_sum']) ? (float) $entry['latency_sum'] : 0.0,
                    'latency_count'   => isset($entry['latency_count']) ? (int) $entry['latency_count'] : 0,
                    'ttfb_sum'        => isset($entry['ttfb_sum']) ? (float) $entry['ttfb_sum'] : 0.0,
                    'ttfb_count'      => isset($entry['ttfb_count']) ? (int) $entry['ttfb_count'] : 0,
                    'violations'      => isset($entry['violations']) ? (int) $entry['violations'] : 0,
                    'violation_types' => isset($entry['violation_types']) && is_array($entry['violation_types'])
                        ? $entry['violation_types']
                        : [],
                ],
            ];
        }

        foreach ($agents as $agent_id => $agent_totals) {
            $normalized_id = sitepulse_uptime_normalize_agent_id($agent_id);

            if (!isset($agents_totals[$normalized_id])) {
                $agents_totals[$normalized_id] = [
                    'up'              => 0,
                    'down'            => 0,
                    'unknown'         => 0,
                    'maintenance'     => 0,
                    'total'           => 0,
                    'latency_sum'     => 0.0,
                    'latency_count'   => 0,
                    'ttfb_sum'        => 0.0,
                    'ttfb_count'      => 0,
                    'violations'      => 0,
                    'violation_types' => [],
                ];
            }

            $agents_totals[$normalized_id]['up'] += isset($agent_totals['up']) ? (int) $agent_totals['up'] : 0;
            $agents_totals[$normalized_id]['down'] += isset($agent_totals['down']) ? (int) $agent_totals['down'] : 0;
            $agents_totals[$normalized_id]['unknown'] += isset($agent_totals['unknown']) ? (int) $agent_totals['unknown'] : 0;
            $agents_totals[$normalized_id]['maintenance'] += isset($agent_totals['maintenance']) ? (int) $agent_totals['maintenance'] : 0;
            $agents_totals[$normalized_id]['total'] += isset($agent_totals['total']) ? (int) $agent_totals['total'] : 0;
            $agents_totals[$normalized_id]['latency_sum'] += isset($agent_totals['latency_sum']) ? (float) $agent_totals['latency_sum'] : 0.0;
            $agents_totals[$normalized_id]['latency_count'] += isset($agent_totals['latency_count']) ? (int) $agent_totals['latency_count'] : 0;
            $agents_totals[$normalized_id]['ttfb_sum'] += isset($agent_totals['ttfb_sum']) ? (float) $agent_totals['ttfb_sum'] : 0.0;
            $agents_totals[$normalized_id]['ttfb_count'] += isset($agent_totals['ttfb_count']) ? (int) $agent_totals['ttfb_count'] : 0;
            $agents_totals[$normalized_id]['violations'] += isset($agent_totals['violations']) ? (int) $agent_totals['violations'] : 0;

            if (isset($agent_totals['violation_types']) && is_array($agent_totals['violation_types'])) {
                foreach ($agent_totals['violation_types'] as $type => $count) {
                    $type_key = sanitize_key($type);

                    if ($type_key === '') {
                        continue;
                    }

                    if (!isset($agents_totals[$normalized_id]['violation_types'][$type_key])) {
                        $agents_totals[$normalized_id]['violation_types'][$type_key] = 0;
                    }

                    $agents_totals[$normalized_id]['violation_types'][$type_key] += (int) $count;
                }
            }
        }
    }

    return [
        'agents' => $agents_totals,
        'global' => $global,
    ];
}

/**
 * Handles the SLA CSV export request.
 *
 * @return void
 */
function sitepulse_uptime_handle_sla_export() {
    if (!current_user_can(function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options')) {
        wp_die(__('Vous n’avez pas l’autorisation d’exporter ce rapport.', 'sitepulse'));
    }

    check_admin_referer('sitepulse_export_sla');

    $month_raw = isset($_POST['sitepulse_sla_month']) ? wp_unslash($_POST['sitepulse_sla_month']) : '';
    $month = is_string($month_raw) ? sanitize_text_field($month_raw) : '';

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        sitepulse_uptime_redirect_with_notice('invalid-month');
    }

    $archive = sitepulse_get_uptime_archive();
    $months = sitepulse_uptime_get_archive_months($archive);

    if (!isset($months[$month])) {
        sitepulse_uptime_redirect_with_notice('missing-data', $month);
    }

    $selected_month = $months[$month];
    $metrics = sitepulse_uptime_collect_metrics_for_period($archive, (int) $selected_month['start'], (int) $selected_month['end']);

    if (empty($metrics['agents'])) {
        sitepulse_uptime_redirect_with_notice('empty-period', $month);
    }

    $agents = sitepulse_uptime_get_agents();
    $filename = sprintf('sitepulse-sla-%s.csv', $month);

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');

    if (false === $output) {
        wp_die(__('Impossible de générer le flux CSV.', 'sitepulse'));
    }

    fwrite($output, "\xEF\xBB\xBF");

    $report_period_label = isset($selected_month['label']) ? $selected_month['label'] : $month;
    $generated_label = function_exists('wp_date') ? wp_date('Y-m-d H:i', current_time('timestamp')) : date('Y-m-d H:i');

    fputcsv($output, sitepulse_uptime_escape_csv_row(['SitePulse SLA Report', $report_period_label]));
    fputcsv($output, sitepulse_uptime_escape_csv_row([__('Généré le', 'sitepulse'), $generated_label]));
    fputcsv($output, sitepulse_uptime_escape_csv_row([]));

    $impact_rows = [];

    if (function_exists('sitepulse_custom_dashboard_format_impact_export_rows')) {
        $impact_snapshot = function_exists('sitepulse_custom_dashboard_get_cached_impact_index')
            ? sitepulse_custom_dashboard_get_cached_impact_index('30d', DAY_IN_SECONDS)
            : null;

        $range_definitions = sitepulse_custom_dashboard_get_metric_ranges();
        $range_label = sitepulse_custom_dashboard_resolve_range_label(
            '30d',
            array_values($range_definitions)
        );

        if (null === $impact_snapshot) {
            $dashboard_payload = sitepulse_custom_dashboard_prepare_metrics_payload('30d');

            if (isset($dashboard_payload['impact']) && is_array($dashboard_payload['impact'])) {
                $impact_snapshot = $dashboard_payload['impact'];
            }

            if (isset($dashboard_payload['available_ranges']) && is_array($dashboard_payload['available_ranges'])) {
                $range_label = sitepulse_custom_dashboard_resolve_range_label(
                    isset($impact_snapshot['range']) ? $impact_snapshot['range'] : '30d',
                    $dashboard_payload['available_ranges']
                );
            }
        } elseif (is_array($impact_snapshot)) {
            $range_label = sitepulse_custom_dashboard_resolve_range_label(
                isset($impact_snapshot['range']) ? $impact_snapshot['range'] : '30d',
                array_values($range_definitions)
            );
        }

        if (is_array($impact_snapshot)) {
            $impact_rows = sitepulse_custom_dashboard_format_impact_export_rows($impact_snapshot, $range_label);
        }
    }

    if (!empty($impact_rows)) {
        foreach ($impact_rows as $impact_row) {
            fputcsv($output, sitepulse_uptime_escape_csv_row($impact_row));
        }

        fputcsv($output, sitepulse_uptime_escape_csv_row([]));
    }

    $header = [
        __('Agent', 'sitepulse'),
        __('Région', 'sitepulse'),
        __('Poids', 'sitepulse'),
        __('Disponibilité (%)', 'sitepulse'),
        __('Contrôles évalués', 'sitepulse'),
        __('Incidents détectés', 'sitepulse'),
        __('Fenêtres de maintenance (contrôles)', 'sitepulse'),
        __('TTFB moyen (ms)', 'sitepulse'),
        __('Latence moyenne (ms)', 'sitepulse'),
        __('Violations', 'sitepulse'),
    ];
    fputcsv($output, sitepulse_uptime_escape_csv_row($header));

    foreach ($metrics['agents'] as $agent_id => $agent_totals) {
        $agent = isset($agents[$agent_id]) ? $agents[$agent_id] : sitepulse_uptime_get_agent($agent_id);

        if (!sitepulse_uptime_agent_is_active($agent_id, $agent)) {
            continue;
        }

        $agent_weight = sitepulse_uptime_get_agent_weight($agent_id, $agent);
        $total_checks = isset($agent_totals['total']) ? (int) $agent_totals['total'] : 0;
        $maintenance_checks = isset($agent_totals['maintenance']) ? (int) $agent_totals['maintenance'] : 0;
        $effective_total = max(0, $total_checks - $maintenance_checks);
        $up_checks = isset($agent_totals['up']) ? (int) $agent_totals['up'] : 0;
        $down_checks = isset($agent_totals['down']) ? (int) $agent_totals['down'] : 0;
        $latency_sum = isset($agent_totals['latency_sum']) ? (float) $agent_totals['latency_sum'] : 0.0;
        $latency_count = isset($agent_totals['latency_count']) ? (int) $agent_totals['latency_count'] : 0;
        $ttfb_sum = isset($agent_totals['ttfb_sum']) ? (float) $agent_totals['ttfb_sum'] : 0.0;
        $ttfb_count = isset($agent_totals['ttfb_count']) ? (int) $agent_totals['ttfb_count'] : 0;
        $violations = isset($agent_totals['violations']) ? (int) $agent_totals['violations'] : 0;

        $uptime = $effective_total > 0 ? ($up_checks / $effective_total) * 100 : 100.0;
        $latency_avg_ms = $latency_count > 0 ? ($latency_sum / $latency_count) * 1000 : null;
        $ttfb_avg_ms = $ttfb_count > 0 ? ($ttfb_sum / $ttfb_count) * 1000 : null;

        fputcsv($output, sitepulse_uptime_escape_csv_row([
            isset($agent['label']) ? $agent['label'] : ucfirst(str_replace('_', ' ', $agent_id)),
            isset($agent['region']) ? $agent['region'] : 'global',
            number_format((float) $agent_weight, 2, '.', ''),
            number_format((float) $uptime, 3, '.', ''),
            $effective_total,
            $down_checks,
            $maintenance_checks,
            null === $ttfb_avg_ms ? '' : number_format((float) $ttfb_avg_ms, 1, '.', ''),
            null === $latency_avg_ms ? '' : number_format((float) $latency_avg_ms, 1, '.', ''),
            $violations,
        ]));
    }

    fclose($output);
    exit;
}

/**
 * Handles manual SLA report generation from the admin interface.
 *
 * @return void
 */
function sitepulse_uptime_handle_manual_report_generation() {
    if (!current_user_can(function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options')) {
        wp_die(__('Vous n’avez pas l’autorisation de générer ce rapport.', 'sitepulse'));
    }

    check_admin_referer('sitepulse_generate_uptime_report');

    $windows = isset($_POST['sitepulse_uptime_windows']) ? wp_unslash($_POST['sitepulse_uptime_windows']) : [7, 30];

    if (!is_array($windows)) {
        $windows = [$windows];
    }

    $windows = array_values(array_filter(array_map('intval', $windows), function ($value) {
        return $value > 0;
    }));

    if (empty($windows)) {
        $windows = [7, 30];
    }

    $result = sitepulse_uptime_generate_sla_report('manual', $windows);

    if (is_wp_error($result)) {
        $redirect = add_query_arg([
            'page'                        => 'sitepulse-uptime',
            'sitepulse_sla_report_status' => $result->get_error_code(),
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    $redirect = add_query_arg([
        'page'                        => 'sitepulse-uptime',
        'sitepulse_sla_report_status' => 'success',
        'sitepulse_sla_report_id'     => $result['id'],
    ], admin_url('admin.php'));

    wp_safe_redirect($redirect);
    exit;
}

/**
 * Saves the automation preferences for SLA reports.
 *
 * @return void
 */
function sitepulse_uptime_handle_sla_settings_save() {
    if (!current_user_can(function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options')) {
        wp_die(__('Vous n’avez pas l’autorisation de modifier ces réglages.', 'sitepulse'));
    }

    check_admin_referer('sitepulse_save_sla_settings');

    $settings = sitepulse_uptime_get_sla_automation_settings();

    $settings['enabled'] = isset($_POST['sitepulse_sla_enabled']);
    $settings['frequency'] = isset($_POST['sitepulse_sla_frequency'])
        ? sanitize_key(wp_unslash($_POST['sitepulse_sla_frequency']))
        : 'monthly';
    $settings['frequency'] = in_array($settings['frequency'], ['weekly', 'monthly'], true) ? $settings['frequency'] : 'monthly';

    $windows_input = isset($_POST['sitepulse_sla_windows']) ? wp_unslash($_POST['sitepulse_sla_windows']) : $settings['windows'];

    if (!is_array($windows_input)) {
        $windows_input = [$windows_input];
    }

    $parsed_windows = array_values(array_filter(array_map('intval', $windows_input), function ($value) {
        return $value > 0;
    }));

    if (!empty($parsed_windows)) {
        $settings['windows'] = $parsed_windows;
    }

    $recipients = [];

    if (isset($_POST['sitepulse_sla_recipients'])) {
        $recipients_raw = wp_unslash($_POST['sitepulse_sla_recipients']);
        $recipients_split = preg_split('/[\n,]+/', $recipients_raw);

        if (is_array($recipients_split)) {
            $recipients = array_values(array_filter(array_map('sanitize_email', $recipients_split)));
        }
    }

    $settings['email_enabled'] = isset($_POST['sitepulse_sla_email_enabled']) && !empty($recipients);
    $settings['recipients'] = $settings['email_enabled'] ? $recipients : [];

    $settings['webhook_enabled'] = isset($_POST['sitepulse_sla_webhook_enabled']);
    $settings['webhook_url'] = '';

    if ($settings['webhook_enabled'] && isset($_POST['sitepulse_sla_webhook_url'])) {
        $candidate = esc_url_raw(wp_unslash($_POST['sitepulse_sla_webhook_url']));

        if (wp_http_validate_url($candidate)) {
            $settings['webhook_url'] = $candidate;
        } else {
            $settings['webhook_enabled'] = false;
        }
    }

    if (!$settings['enabled']) {
        $settings['next_run'] = 0;
        update_option(SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION, $settings, false);
        sitepulse_uptime_cancel_automation_job();
    } else {
        update_option(SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION, $settings, false);
        sitepulse_uptime_schedule_automation_job($settings, true);
    }

    $redirect = add_query_arg([
        'page'                   => 'sitepulse-uptime',
        'sitepulse_sla_settings' => 'updated',
    ], admin_url('admin.php'));

    wp_safe_redirect($redirect);
    exit;
}

/**
 * Redirects back to the uptime page with a contextual notice.
 *
 * @param string $code  Error code identifier.
 * @param string $month Month identifier.
 * @return void
 */
function sitepulse_uptime_redirect_with_notice($code, $month = '') {
    $args = [
        'page'                => 'sitepulse-uptime',
        'sitepulse_sla_error' => $code,
    ];

    if ($month !== '') {
        $args['sitepulse_sla_month'] = $month;
    }

    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

/**
 * Aggregates uptime metrics per region based on agent configuration.
 *
 * @param array<string,array<string,mixed>> $agent_metrics Metrics per agent.
 * @param array<string,array<string,mixed>> $agents        Agent definitions.
 * @return array<string,array<string,mixed>>
 */
function sitepulse_calculate_region_uptime_metrics($agent_metrics, $agents) {
    $regions = [];

    foreach ($agent_metrics as $agent_id => $metrics) {
        $agent = isset($agents[$agent_id]) ? $agents[$agent_id] : ['region' => 'global'];

        if (!sitepulse_uptime_agent_is_active($agent_id, $agent)) {
            continue;
        }

        $region = isset($agent['region']) && is_string($agent['region']) ? sanitize_key($agent['region']) : 'global';
        $weight = sitepulse_uptime_get_agent_weight($agent_id, $agent);

        if (!isset($regions[$region])) {
            $regions[$region] = [
                'up'              => 0,
                'down'            => 0,
                'unknown'         => 0,
                'maintenance'     => 0,
                'effective_total' => 0,
                'latency_sum'     => 0.0,
                'latency_count'   => 0,
                'ttfb_sum'        => 0.0,
                'ttfb_count'      => 0,
                'violations'      => 0,
                'violation_types' => [],
                'agents'          => [],
                'weighted'        => [
                    'effective_total' => 0.0,
                    'up'              => 0.0,
                    'down'            => 0.0,
                    'unknown'         => 0.0,
                    'latency_sum'     => 0.0,
                    'latency_count'   => 0.0,
                    'ttfb_sum'        => 0.0,
                    'ttfb_count'      => 0.0,
                ],
            ];
        }

        $regions[$region]['up'] += isset($metrics['up']) ? (int) $metrics['up'] : 0;
        $regions[$region]['down'] += isset($metrics['down']) ? (int) $metrics['down'] : 0;
        $regions[$region]['unknown'] += isset($metrics['unknown']) ? (int) $metrics['unknown'] : 0;
        $regions[$region]['maintenance'] += isset($metrics['maintenance']) ? (int) $metrics['maintenance'] : 0;
        $regions[$region]['effective_total'] += isset($metrics['effective_total']) ? (int) $metrics['effective_total'] : 0;
        $regions[$region]['latency_sum'] += isset($metrics['latency_sum']) ? (float) $metrics['latency_sum'] : 0.0;
        $regions[$region]['latency_count'] += isset($metrics['latency_count']) ? (int) $metrics['latency_count'] : 0;
        $regions[$region]['ttfb_sum'] += isset($metrics['ttfb_sum']) ? (float) $metrics['ttfb_sum'] : 0.0;
        $regions[$region]['ttfb_count'] += isset($metrics['ttfb_count']) ? (int) $metrics['ttfb_count'] : 0;
        $regions[$region]['violations'] += isset($metrics['violations']) ? (int) $metrics['violations'] : 0;

        $regions[$region]['agents'][] = $agent_id;

        if ($weight > 0) {
            $regions[$region]['weighted']['effective_total'] += (isset($metrics['effective_total']) ? (int) $metrics['effective_total'] : 0) * $weight;
            $regions[$region]['weighted']['up'] += (isset($metrics['up']) ? (int) $metrics['up'] : 0) * $weight;
            $regions[$region]['weighted']['down'] += (isset($metrics['down']) ? (int) $metrics['down'] : 0) * $weight;
            $regions[$region]['weighted']['unknown'] += (isset($metrics['unknown']) ? (int) $metrics['unknown'] : 0) * $weight;
            $regions[$region]['weighted']['latency_sum'] += (isset($metrics['latency_sum']) ? (float) $metrics['latency_sum'] : 0.0) * $weight;
            $regions[$region]['weighted']['latency_count'] += (isset($metrics['latency_count']) ? (int) $metrics['latency_count'] : 0) * $weight;
            $regions[$region]['weighted']['ttfb_sum'] += (isset($metrics['ttfb_sum']) ? (float) $metrics['ttfb_sum'] : 0.0) * $weight;
            $regions[$region]['weighted']['ttfb_count'] += (isset($metrics['ttfb_count']) ? (int) $metrics['ttfb_count'] : 0) * $weight;
        }

        if (isset($metrics['violation_types']) && is_array($metrics['violation_types'])) {
            foreach ($metrics['violation_types'] as $type => $count) {
                $type_key = sanitize_key($type);

                if ($type_key === '') {
                    continue;
                }

                if (!isset($regions[$region]['violation_types'][$type_key])) {
                    $regions[$region]['violation_types'][$type_key] = 0;
                }

                $regions[$region]['violation_types'][$type_key] += (int) $count;
            }
        }
    }

    foreach ($regions as $region => $region_metrics) {
        $effective_total = max(0, (int) $region_metrics['effective_total']);
        $weighted_effective_total = isset($region_metrics['weighted']['effective_total'])
            ? (float) $region_metrics['weighted']['effective_total']
            : 0.0;
        $weighted_up = isset($region_metrics['weighted']['up']) ? (float) $region_metrics['weighted']['up'] : 0.0;

        if ($weighted_effective_total > 0) {
            $uptime = ($weighted_up / $weighted_effective_total) * 100;
        } else {
            $uptime = $effective_total > 0 ? ($region_metrics['up'] / $effective_total) * 100 : 100;
        }

        $regions[$region]['uptime'] = max(0, min(100, $uptime));

        $latency_count = isset($region_metrics['latency_count']) ? (int) $region_metrics['latency_count'] : 0;
        $latency_sum = isset($region_metrics['latency_sum']) ? (float) $region_metrics['latency_sum'] : 0.0;
        $ttfb_count = isset($region_metrics['ttfb_count']) ? (int) $region_metrics['ttfb_count'] : 0;
        $ttfb_sum = isset($region_metrics['ttfb_sum']) ? (float) $region_metrics['ttfb_sum'] : 0.0;

        $weighted_latency_count = isset($region_metrics['weighted']['latency_count'])
            ? (float) $region_metrics['weighted']['latency_count']
            : 0.0;
        $weighted_latency_sum = isset($region_metrics['weighted']['latency_sum'])
            ? (float) $region_metrics['weighted']['latency_sum']
            : 0.0;
        $weighted_ttfb_count = isset($region_metrics['weighted']['ttfb_count'])
            ? (float) $region_metrics['weighted']['ttfb_count']
            : 0.0;
        $weighted_ttfb_sum = isset($region_metrics['weighted']['ttfb_sum'])
            ? (float) $region_metrics['weighted']['ttfb_sum']
            : 0.0;

        if ($weighted_latency_count > 0) {
            $regions[$region]['latency_avg'] = $weighted_latency_sum / $weighted_latency_count;
        } elseif ($latency_count > 0) {
            $regions[$region]['latency_avg'] = $latency_sum / $latency_count;
        } else {
            $regions[$region]['latency_avg'] = null;
        }

        if ($weighted_ttfb_count > 0) {
            $regions[$region]['ttfb_avg'] = $weighted_ttfb_sum / $weighted_ttfb_count;
        } elseif ($ttfb_count > 0) {
            $regions[$region]['ttfb_avg'] = $ttfb_sum / $ttfb_count;
        } else {
            $regions[$region]['ttfb_avg'] = null;
        }

        unset($regions[$region]['weighted']);
    }

    return $regions;
}


require_once __DIR__ . '/uptime/page.php';

function sitepulse_run_uptime_check($agent_id = 'default', $override_args = []) {
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $agent_config = sitepulse_uptime_get_agent($agent_id);

    if (isset($agent_config['active']) && false === (bool) $agent_config['active']) {
        return;
    }

    $default_timeout = defined('SITEPULSE_DEFAULT_UPTIME_TIMEOUT') ? (int) SITEPULSE_DEFAULT_UPTIME_TIMEOUT : 10;
    $timeout_option = get_option(SITEPULSE_OPTION_UPTIME_TIMEOUT, $default_timeout);
    $timeout = $default_timeout;
    $default_method = defined('SITEPULSE_DEFAULT_UPTIME_HTTP_METHOD') ? SITEPULSE_DEFAULT_UPTIME_HTTP_METHOD : 'GET';
    $method_option = get_option(SITEPULSE_OPTION_UPTIME_HTTP_METHOD, $default_method);
    $http_method = function_exists('sitepulse_sanitize_uptime_http_method')
        ? sitepulse_sanitize_uptime_http_method($method_option)
        : (is_string($method_option) && $method_option !== '' ? strtoupper($method_option) : $default_method);
    $headers_option = get_option(SITEPULSE_OPTION_UPTIME_HTTP_HEADERS, []);
    $custom_headers = function_exists('sitepulse_sanitize_uptime_http_headers')
        ? sitepulse_sanitize_uptime_http_headers($headers_option)
        : (is_array($headers_option) ? $headers_option : []);
    $expected_codes_option = get_option(SITEPULSE_OPTION_UPTIME_EXPECTED_CODES, []);
    $expected_codes = function_exists('sitepulse_sanitize_uptime_expected_codes')
        ? sitepulse_sanitize_uptime_expected_codes($expected_codes_option)
        : [];
    $latency_threshold_option = get_option(
        SITEPULSE_OPTION_UPTIME_LATENCY_THRESHOLD,
        defined('SITEPULSE_DEFAULT_UPTIME_LATENCY_THRESHOLD') ? SITEPULSE_DEFAULT_UPTIME_LATENCY_THRESHOLD : 0
    );
    $latency_threshold = function_exists('sitepulse_sanitize_uptime_latency_threshold')
        ? sitepulse_sanitize_uptime_latency_threshold($latency_threshold_option)
        : (is_numeric($latency_threshold_option) ? (float) $latency_threshold_option : 0.0);
    $expected_keyword_option = get_option(SITEPULSE_OPTION_UPTIME_KEYWORD, '');
    $expected_keyword = function_exists('sitepulse_sanitize_uptime_keyword')
        ? sitepulse_sanitize_uptime_keyword($expected_keyword_option)
        : (is_string($expected_keyword_option) ? sanitize_text_field($expected_keyword_option) : '');

    if (is_numeric($timeout_option)) {
        $timeout = (int) $timeout_option;
    }

    if ($timeout < 1) {
        $timeout = $default_timeout;
    }

    $configured_url = get_option(SITEPULSE_OPTION_UPTIME_URL, '');
    $custom_url = '';

    if (is_string($configured_url)) {
        $configured_url = trim($configured_url);

        if ($configured_url !== '') {
            $validated_url = wp_http_validate_url($configured_url);

            if ($validated_url) {
                $custom_url = $validated_url;
            }
        }
    }

    $default_url = home_url();
    $request_url_default = $custom_url !== '' ? $custom_url : $default_url;

    if (isset($agent_config['timeout']) && is_numeric($agent_config['timeout'])) {
        $timeout = max(1, (int) $agent_config['timeout']);
    }

    if (isset($agent_config['method']) && is_string($agent_config['method']) && $agent_config['method'] !== '') {
        $http_method = strtoupper($agent_config['method']);
    }

    if (isset($agent_config['headers']) && is_array($agent_config['headers'])) {
        $custom_headers = wp_parse_args($agent_config['headers'], $custom_headers);
    }

    if (isset($agent_config['expected_codes']) && is_array($agent_config['expected_codes'])) {
        $agent_expected = array_map('intval', $agent_config['expected_codes']);
        $expected_codes = array_values(array_unique(array_merge($expected_codes, $agent_expected)));
    }

    if (isset($agent_config['url']) && is_string($agent_config['url']) && '' !== trim($agent_config['url'])) {
        $candidate_url = wp_http_validate_url($agent_config['url']);
        if ($candidate_url) {
            $request_url_default = $candidate_url;
        }
    }

    $defaults = [
        'timeout'   => $timeout,
        'sslverify' => true,
        'url'       => $request_url_default,
        'method'    => $http_method,
        'headers'   => $custom_headers,
        'agent'     => $agent_id,
    ];

    /**
     * Filtre les arguments passés à la requête de vérification d'uptime.
     *
     * Permet de désactiver la vérification SSL, d'ajuster le timeout ou de pointer
     * vers une URL spécifique pour les environnements de test.
     *
     * @since 1.0
     *
     * @param array $request_args Arguments transmis à wp_remote_request(). Le paramètre
     *                            "url" peut être fourni pour cibler une adresse
     *                            différente.
     */
    $request_args = apply_filters('sitepulse_uptime_request_args', $defaults);

    if (!is_array($request_args)) {
        $request_args = $defaults;
    }

    if (is_array($override_args) && !empty($override_args)) {
        $request_args = array_merge($request_args, $override_args);
    }

    $request_agent = isset($request_args['agent']) ? sitepulse_uptime_normalize_agent_id($request_args['agent']) : $agent_id;
    unset($request_args['agent']);

    $request_url = isset($request_args['url']) ? $request_args['url'] : $defaults['url'];

    if (!is_string($request_url) || $request_url === '') {
        $request_url = $defaults['url'];
    } else {
        $validated_request_url = wp_http_validate_url($request_url);

        if ($validated_request_url) {
            $request_url = $validated_request_url;
        } else {
            $request_url = $defaults['url'];
        }
    }

    unset($request_args['url']);

    if (isset($request_args['expected_codes'])) {
        $expected_codes_candidate = $request_args['expected_codes'];

        if (function_exists('sitepulse_sanitize_uptime_expected_codes')) {
            $expected_codes = sitepulse_sanitize_uptime_expected_codes($expected_codes_candidate);
        }

        unset($request_args['expected_codes']);
    }

    if (isset($request_args['method'])) {
        $method_candidate = $request_args['method'];
        $request_args['method'] = function_exists('sitepulse_sanitize_uptime_http_method')
            ? sitepulse_sanitize_uptime_http_method($method_candidate)
            : (is_string($method_candidate) && $method_candidate !== '' ? strtoupper($method_candidate) : $http_method);
    } else {
        $request_args['method'] = $http_method;
    }

    if (isset($request_args['headers'])) {
        $request_args['headers'] = function_exists('sitepulse_sanitize_uptime_http_headers')
            ? sitepulse_sanitize_uptime_http_headers($request_args['headers'])
            : (is_array($request_args['headers']) ? $request_args['headers'] : []);
    } else {
        $request_args['headers'] = $custom_headers;
    }

    if (empty($request_args['headers'])) {
        unset($request_args['headers']);
    }

    $raw_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);

    if (!is_array($raw_log)) {
        $raw_log = empty($raw_log) ? [] : [$raw_log];
    }

    $log = sitepulse_normalize_uptime_log($raw_log);
    $log = sitepulse_trim_uptime_log($log);
    $timestamp = (int) current_time('timestamp');

    $active_window = sitepulse_uptime_find_active_maintenance_window($request_agent, $timestamp);

    if ($active_window) {
        $entry = [
            'timestamp'         => $timestamp,
            'status'            => 'maintenance',
            'agent'             => $request_agent,
            'maintenance_start' => $active_window['start'],
            'maintenance_end'   => $active_window['end'],
        ];

        if (!empty($active_window['label'])) {
            $entry['maintenance_label'] = $active_window['label'];
        }

        $log[] = $entry;
        $log = sitepulse_trim_uptime_log($log);

        update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
        sitepulse_update_uptime_archive($entry);

        $log_label = isset($entry['maintenance_label']) ? ' - ' . $entry['maintenance_label'] : '';
        $log_message = sprintf(
            'Uptime check skipped for %1$s due to maintenance window (%2$s → %3$s)%4$s.',
            $request_agent,
            gmdate('c', $active_window['start']),
            gmdate('c', $active_window['end']),
            $log_label
        );
        sitepulse_log($log_message, 'INFO');

        $date_format = get_option('date_format', 'Y-m-d');
        $time_format = get_option('time_format', 'H:i');
        $agent_label = isset($agent_config['label']) && is_string($agent_config['label']) && $agent_config['label'] !== ''
            ? $agent_config['label']
            : $request_agent;
        $window_label = isset($entry['maintenance_label']) && $entry['maintenance_label'] !== ''
            ? $entry['maintenance_label']
            : __('Fenêtre de maintenance planifiée', 'sitepulse');
        $formatted_start = date_i18n($date_format . ' ' . $time_format, $active_window['start']);
        $formatted_end = date_i18n($date_format . ' ' . $time_format, $active_window['end']);
        $notice_message = sprintf(
            __('Contrôle d’uptime ignoré pour %1$s : %2$s (%3$s → %4$s). Aucune alerte envoyée pendant la maintenance.', 'sitepulse'),
            $agent_label,
            $window_label,
            $formatted_start,
            $formatted_end
        );

        if (function_exists('sitepulse_schedule_debug_admin_notice')) {
            sitepulse_schedule_debug_admin_notice($notice_message, 'info');
        }

        sitepulse_uptime_record_maintenance_notice($notice_message, $timestamp);

        return;
    }

    $request_start = microtime(true);
    $response = wp_remote_request($request_url, $request_args);
    $request_end = microtime(true);

    $raw_duration = max(0, (float) ($request_end - $request_start));

    $entry = [
        'timestamp' => $timestamp,
        'agent'     => $request_agent,
        'latency'   => round($raw_duration, 4),
    ];
    $ttfb = null;

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        $entry['status'] = 'unknown';

        if (!empty($error_message)) {
            $entry['error'] = $error_message;
        }

        $failure_streak = (int) get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) + 1;
        update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, $failure_streak, false);

        $default_threshold = 3;
        $threshold = (int) apply_filters('sitepulse_uptime_consecutive_failures', $default_threshold, $failure_streak, $response, $request_url, $request_args);
        $threshold = max(1, $threshold);

        $log[] = $entry;

        $level = $failure_streak >= $threshold ? 'ALERT' : 'WARNING';
        $log_message = sprintf('Uptime check: network error (%1$d/%2$d)%3$s', $failure_streak, $threshold, !empty($error_message) ? ' - ' . $error_message : '');
        sitepulse_log($log_message, $level);

        $log = sitepulse_trim_uptime_log($log);

        update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
        sitepulse_update_uptime_archive($entry);

        return;
    }

    if (is_array($response) && isset($response['http_response']) && is_object($response['http_response'])) {
        $http_response = $response['http_response'];

        if (method_exists($http_response, 'get_response_object')) {
            $requests_response = $http_response->get_response_object();

            if (is_object($requests_response) && isset($requests_response->info) && is_array($requests_response->info)) {
                if (isset($requests_response->info['total_time'])) {
                    $total_time = (float) $requests_response->info['total_time'];

                    if ($total_time >= 0) {
                        $entry['latency'] = round($total_time, 4);
                    }
                }

                if (isset($requests_response->info['starttransfer_time'])) {
                    $start_transfer = (float) $requests_response->info['starttransfer_time'];

                    if ($start_transfer >= 0) {
                        $ttfb = $start_transfer;
                    }
                }
            }
        }
    }

    if (null !== $ttfb) {
        $entry['ttfb'] = round($ttfb, 4);
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $is_up = $response_code >= 200 && $response_code < 400;

    if (!empty($expected_codes)) {
        $is_up = in_array((int) $response_code, $expected_codes, true);
    }

    $entry['status'] = $is_up;

    $response_body = wp_remote_retrieve_body($response);
    $body_as_string = is_string($response_body) ? $response_body : '';
    $violation_types = [];
    $violation_messages = [];
    $latency_value = isset($entry['latency']) ? (float) $entry['latency'] : 0.0;

    if ($latency_threshold > 0 && $latency_value > $latency_threshold) {
        $violation_types[] = 'latency';
        $violation_messages[] = sprintf(
            /* translators: 1: measured latency, 2: configured threshold. */
            __('Temps de réponse %.3fs supérieur au seuil de %.3fs.', 'sitepulse'),
            $latency_value,
            $latency_threshold
        );
    }

    if ($expected_keyword !== '') {
        $body_to_search = $body_as_string;

        if ($body_to_search === '' || false === stripos($body_to_search, $expected_keyword)) {
            $violation_types[] = 'keyword';
            $violation_messages[] = sprintf(
                /* translators: %s is the expected keyword. */
                __('Mot-clé attendu introuvable dans la réponse : %s.', 'sitepulse'),
                $expected_keyword
            );
        }
    }

    if (!empty($violation_types)) {
        $is_up = false;
        $entry['status'] = false;
        $entry['violation_types'] = array_values(array_unique(array_map('sanitize_key', $violation_types)));
        $entry['validation_messages'] = $violation_messages;

        if (!isset($entry['error'])) {
            $entry['error'] = implode(' ', $violation_messages);
        }

        if (function_exists('sitepulse_error_alert_send')) {
            $subject = sprintf(
                __('Surveillance de disponibilité : alerte pour %s', 'sitepulse'),
                $request_agent
            );
            $message_lines = array_merge($violation_messages, [
                sprintf(__('Code HTTP : %d', 'sitepulse'), $response_code),
                sprintf(__('URL : %s', 'sitepulse'), $request_url),
            ]);

            sitepulse_error_alert_send(
                'uptime_violation',
                $subject,
                implode("\n", $message_lines),
                'warning',
                [
                    'agent'           => $request_agent,
                    'url'             => $request_url,
                    'response_code'   => $response_code,
                    'latency'         => $latency_value,
                    'latency_threshold' => $latency_threshold,
                    'expected_keyword' => $expected_keyword,
                    'violation_types' => $entry['violation_types'],
                ]
            );
        }
    }

    if (!$is_up) {
        $incident_start = $timestamp;

        if (!empty($log)) {
            for ($i = count($log) - 1; $i >= 0; $i--) {
                if (!isset($log[$i]['status']) || !is_bool($log[$i]['status'])) {
                    continue;
                }

                if (false === $log[$i]['status']) {
                    if (isset($log[$i]['incident_start'])) {
                        $incident_start = (int) $log[$i]['incident_start'];
                    } elseif (isset($log[$i]['timestamp'])) {
                        $incident_start = (int) $log[$i]['timestamp'];
                    }
                }

                break;
            }
        }

        $entry['incident_start'] = $incident_start;

        if (!isset($entry['error'])) {
            $entry['error'] = sprintf('HTTP %d', $response_code);
        }
    }

    $log[] = $entry;

    if ($is_up) {
        if ((int) get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) !== 0) {
            update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0, false);
        }
    } else {
        $failure_streak = (int) get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) + 1;
        update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, $failure_streak, false);
    }

    if (!$is_up) {
        sitepulse_log(sprintf('Uptime check: Down (HTTP %d)', $response_code), 'ALERT');
    } else {
        sitepulse_log('Uptime check: Up');
    }

    $log = sitepulse_trim_uptime_log($log);

    update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
    sitepulse_update_uptime_archive($entry);
}
