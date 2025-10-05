<?php
if (!defined('ABSPATH')) exit;

if (!defined('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK')) {
    define('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK', 'sitepulse_uptime_failure_streak');
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

if (!defined('SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS')) {
    define('SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS', 'sitepulse_uptime_maintenance_windows');
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
    add_action('sitepulse_uptime_schedule_internal_request', 'sitepulse_uptime_schedule_internal_request', 10, 3);
    add_action('rest_api_init', 'sitepulse_uptime_register_rest_routes');

    if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
        WP_CLI::add_command('sitepulse uptime:queue', function ($args, $assoc_args) {
            $agent = isset($assoc_args['agent']) ? $assoc_args['agent'] : 'default';
            $payload = isset($assoc_args['payload']) ? json_decode($assoc_args['payload'], true) : [];
            $timestamp = isset($assoc_args['timestamp']) ? (int) $assoc_args['timestamp'] : null;

            if (!is_array($payload)) {
                $payload = [];
            }

            sitepulse_uptime_schedule_internal_request($agent, $payload, $timestamp);
            WP_CLI::success(sprintf('Vérification programmée pour %s.', $agent));
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
     * @param bool             $allowed Whether the request is authorised.
     * @param WP_REST_Request  $request REST request instance.
     */
    return (bool) apply_filters('sitepulse_uptime_rest_schedule_permission', false, $request);
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

    if (!is_array($payload)) {
        $payload = [];
    }

    if (null !== $timestamp) {
        $timestamp = (int) $timestamp;
    }

    sitepulse_uptime_schedule_internal_request($agent, $payload, $timestamp);

    $scheduled_timestamp = null === $timestamp
        ? (int) current_time('timestamp')
        : (int) $timestamp;

    return rest_ensure_response([
        'queued'        => true,
        'agent'         => sitepulse_uptime_normalize_agent_id($agent),
        'scheduled_at'  => $scheduled_timestamp,
        'payload'       => empty($payload) ? new stdClass() : $payload,
    ]);
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
 * Returns the configured uptime monitoring agents.
 *
 * @return array<string,array<string,mixed>>
 */
function sitepulse_uptime_get_agents() {
    $agents = get_option(SITEPULSE_OPTION_UPTIME_AGENTS, []);

    if (!is_array($agents)) {
        $agents = [];
    }

    if (empty($agents)) {
        $agents = [
            'default' => [
                'label'  => __('Agent principal', 'sitepulse'),
                'region' => 'global',
                'active' => true,
            ],
        ];
    }

    foreach ($agents as $agent_id => $agent_data) {
        if (!is_array($agent_data)) {
            $agents[$agent_id] = [];
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
        ]);
    }

    return $agents;
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
        ];
    }

    return $agents[$agent_id];
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
 * Returns configured maintenance windows.
 *
 * @return array<int,array<string,mixed>>
 */
function sitepulse_uptime_get_maintenance_windows() {
    $windows = get_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS, []);

    if (!is_array($windows)) {
        return [];
    }

    return array_values(array_filter(array_map(function ($window) {
        if (!is_array($window)) {
            return null;
        }

        $start = isset($window['start']) ? (int) $window['start'] : 0;
        $end = isset($window['end']) ? (int) $window['end'] : 0;

        if ($start <= 0 || $end <= 0 || $start >= $end) {
            return null;
        }

        $agent = isset($window['agent']) ? sitepulse_uptime_normalize_agent_id($window['agent']) : 'all';

        return [
            'agent'      => $agent,
            'start'      => $start,
            'end'        => $end,
            'label'      => isset($window['label']) && is_string($window['label']) ? $window['label'] : '',
            'created_at' => isset($window['created_at']) ? (int) $window['created_at'] : (int) current_time('timestamp'),
        ];
    }, $windows)));
}

/**
 * Determines if the provided agent is inside a maintenance window.
 *
 * @param string   $agent_id  Agent identifier.
 * @param int|null $timestamp Timestamp to evaluate.
 * @return bool
 */
function sitepulse_uptime_is_in_maintenance_window($agent_id, $timestamp = null) {
    $timestamp = null === $timestamp ? (int) current_time('timestamp') : (int) $timestamp;
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $windows = sitepulse_uptime_get_maintenance_windows();

    foreach ($windows as $window) {
        if ($timestamp < $window['start'] || $timestamp > $window['end']) {
            continue;
        }

        if ('all' === $window['agent'] || $window['agent'] === $agent_id) {
            return true;
        }
    }

    return false;
}

/**
 * Queues a remote worker request so it is executed internally.
 *
 * @param string   $agent_id  Agent identifier.
 * @param array    $payload   Optional overrides for the request.
 * @param int|null $timestamp When the request should be executed.
 * @return void
 */
function sitepulse_uptime_schedule_internal_request($agent_id, $payload = [], $timestamp = null) {
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $timestamp = null === $timestamp ? (int) current_time('timestamp') : (int) $timestamp;

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
        'created_at'  => (int) current_time('timestamp'),
    ];

    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, $queue, false);

    sitepulse_uptime_maybe_schedule_queue_processor($timestamp);
}

/**
 * Ensures a cron event exists to process the remote worker queue.
 *
 * @param int $timestamp Desired execution time.
 * @return void
 */
function sitepulse_uptime_maybe_schedule_queue_processor($timestamp) {
    $timestamp = max((int) $timestamp, (int) current_time('timestamp'));

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

    if (!is_array($queue) || empty($queue)) {
        return;
    }

    $now = (int) current_time('timestamp');
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

        sitepulse_run_uptime_check($agent, $payload);
    }

    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, array_values($remaining), false);

    if (!empty($remaining)) {
        $next_timestamp = min(array_map(function ($item) {
            return isset($item['scheduled_at']) ? (int) $item['scheduled_at'] : (int) current_time('timestamp');
        }, $remaining));

        sitepulse_uptime_maybe_schedule_queue_processor($next_timestamp);
    }
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
function sitepulse_normalize_uptime_log($log) {
    if (!is_array($log) || empty($log)) {
        return [];
    }

    $normalized = [];
    $count = count($log);
    $now = (int) current_time('timestamp');

    $default_interval = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
    $interval = sitepulse_uptime_tracker_resolve_schedule_interval($default_interval);

    $approximate_start = $now - max(0, ($count - 1) * $interval);

    foreach (array_values($log) as $index => $entry) {
        $timestamp = $approximate_start + ($index * $interval);
        $status = null;
        $incident_start = null;
        $error_message = null;

        $agent = 'default';

        if (is_array($entry)) {
            if (isset($entry['timestamp']) && is_numeric($entry['timestamp'])) {
                $timestamp = (int) $entry['timestamp'];
            }

            if (array_key_exists('status', $entry)) {
                $status = $entry['status'];
            } else {
                $status = !empty($entry);
            }

            if (isset($entry['incident_start']) && is_numeric($entry['incident_start'])) {
                $incident_start = (int) $entry['incident_start'];
            }

            if (array_key_exists('error', $entry)) {
                $raw_error = $entry['error'];

                if (is_scalar($raw_error)) {
                    $error_message = (string) $raw_error;
                } elseif (null !== $raw_error) {
                    $encoded_error = wp_json_encode($raw_error);

                    if (false !== $encoded_error) {
                        $error_message = $encoded_error;
                    }
                }
            }

            if (isset($entry['agent']) && is_string($entry['agent'])) {
                $agent = sitepulse_uptime_normalize_agent_id($entry['agent']);
            }
        } else {
            $status = (bool) (is_int($entry) ? $entry : !empty($entry));
        }

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
        ], function ($value) {
            return null !== $value;
        });

        $normalized[] = $normalized_entry;
    }

    usort($normalized, function ($a, $b) {
        return $a['timestamp'] <=> $b['timestamp'];
    });

    return array_values($normalized);
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
            'agents'          => [],
        ];
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
            'up'          => 0,
            'down'        => 0,
            'unknown'     => 0,
            'maintenance' => 0,
            'total'       => 0,
        ];
    }

    if (!isset($archive[$day_key]['agents'][$agent][$status_key])) {
        $archive[$day_key]['agents'][$agent][$status_key] = 0;
    }

    $archive[$day_key]['agents'][$agent][$status_key]++;
    $archive[$day_key]['agents'][$agent]['total']++;

    if (count($archive) > 120) {
        $archive = array_slice($archive, -120, null, true);
    }

    update_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, $archive, false);
}

/**
 * Calculates aggregate metrics for the requested archive window.
 *
 * @param array<string,array<string,int>> $archive Archive of daily totals.
 * @param int                             $days    Number of days to include.
 * @return array<string,int|float>
 */
function sitepulse_calculate_uptime_window_metrics($archive, $days) {
    if (!is_array($archive) || empty($archive) || $days < 1) {
        return [
            'days'           => 0,
            'total_checks'   => 0,
            'up_checks'      => 0,
            'down_checks'    => 0,
            'unknown_checks' => 0,
            'uptime'         => 100.0,
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
    ];

    foreach ($window as $entry) {
        $day_total = isset($entry['total']) ? (int) $entry['total'] : 0;
        $maintenance = isset($entry['maintenance']) ? (int) $entry['maintenance'] : 0;
        $effective_total = max(0, $day_total - $maintenance);

        $totals['total_checks'] += $effective_total;
        $totals['up_checks'] += isset($entry['up']) ? (int) $entry['up'] : 0;
        $totals['down_checks'] += isset($entry['down']) ? (int) $entry['down'] : 0;
        $totals['unknown_checks'] += isset($entry['unknown']) ? (int) $entry['unknown'] : 0;
    }

    if ($totals['total_checks'] > 0) {
        $totals['uptime'] = ($totals['up_checks'] / $totals['total_checks']) * 100;
    }

    return $totals;
}

/**
 * Aggregates uptime metrics per agent for the provided window.
 *
 * @param array<string,array<string,mixed>> $archive Archive entries.
 * @param int                               $days    Window size.
 * @return array<string,array<string,mixed>>
 */
function sitepulse_calculate_agent_uptime_metrics($archive, $days) {
    if (!is_array($archive) || empty($archive) || $days < 1) {
        return [];
    }

    $window = array_slice($archive, -$days, null, true);
    $totals = [];

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
                ];
            }

            $totals[$agent_id]['up'] += isset($agent_totals['up']) ? (int) $agent_totals['up'] : 0;
            $totals[$agent_id]['down'] += isset($agent_totals['down']) ? (int) $agent_totals['down'] : 0;
            $totals[$agent_id]['unknown'] += isset($agent_totals['unknown']) ? (int) $agent_totals['unknown'] : 0;
            $totals[$agent_id]['maintenance'] += isset($agent_totals['maintenance']) ? (int) $agent_totals['maintenance'] : 0;
            $totals[$agent_id]['total'] += isset($agent_totals['total']) ? (int) $agent_totals['total'] : 0;
        }
    }

    foreach ($totals as $agent_id => $counts) {
        $effective_total = max(0, (int) $counts['total'] - (int) $counts['maintenance']);
        $uptime = $effective_total > 0 ? ($counts['up'] / $effective_total) * 100 : 100;
        $totals[$agent_id]['uptime'] = max(0, min(100, $uptime));
        $totals[$agent_id]['effective_total'] = $effective_total;
    }

    return $totals;
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
        $region = isset($agent['region']) && is_string($agent['region']) ? sanitize_key($agent['region']) : 'global';

        if (!isset($regions[$region])) {
            $regions[$region] = [
                'up'              => 0,
                'down'            => 0,
                'unknown'         => 0,
                'maintenance'     => 0,
                'effective_total' => 0,
                'agents'          => [],
            ];
        }

        $regions[$region]['up'] += isset($metrics['up']) ? (int) $metrics['up'] : 0;
        $regions[$region]['down'] += isset($metrics['down']) ? (int) $metrics['down'] : 0;
        $regions[$region]['unknown'] += isset($metrics['unknown']) ? (int) $metrics['unknown'] : 0;
        $regions[$region]['maintenance'] += isset($metrics['maintenance']) ? (int) $metrics['maintenance'] : 0;
        $regions[$region]['effective_total'] += isset($metrics['effective_total']) ? (int) $metrics['effective_total'] : 0;
        $regions[$region]['agents'][] = $agent_id;
    }

    foreach ($regions as $region => $region_metrics) {
        $effective_total = max(0, (int) $region_metrics['effective_total']);
        $uptime = $effective_total > 0 ? ($region_metrics['up'] / $effective_total) * 100 : 100;
        $regions[$region]['uptime'] = max(0, min(100, $uptime));
    }

    return $regions;
}

function sitepulse_uptime_tracker_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $uptime_log = sitepulse_normalize_uptime_log(get_option(SITEPULSE_OPTION_UPTIME_LOG, []));
    $uptime_archive = sitepulse_get_uptime_archive();
    $agents = sitepulse_uptime_get_agents();
    $total_checks = count($uptime_log);
    $boolean_checks = array_values(array_filter($uptime_log, function ($entry) {
        return isset($entry['status']) && is_bool($entry['status']);
    }));
    $evaluated_checks = count($boolean_checks);
    $up_checks = count(array_filter($boolean_checks, function ($entry) {
        return isset($entry['status']) && true === $entry['status'];
    }));
    $uptime_percentage = $evaluated_checks > 0 ? ($up_checks / $evaluated_checks) * 100 : 100;
    $date_format = get_option('date_format');
    $time_format = get_option('time_format');
    $current_incident_duration = '';
    $current_incident_start = null;

    if (!empty($uptime_log)) {
        $last_entry = end($uptime_log);
        if (isset($last_entry['status']) && is_bool($last_entry['status']) && false === $last_entry['status']) {
            $current_incident_start = isset($last_entry['incident_start']) ? (int) $last_entry['incident_start'] : (int) $last_entry['timestamp'];
            $current_timestamp = (int) current_time('timestamp');
            $current_incident_duration = human_time_diff($current_incident_start, $current_timestamp);
        }
        reset($uptime_log);
    }
    $trend_entries = array_slice($uptime_archive, -30, null, true);
    $trend_data = [];

    foreach ($trend_entries as $day_key => $daily_entry) {
        $total = isset($daily_entry['total']) ? max(0, (int) $daily_entry['total']) : 0;
        $maintenance = isset($daily_entry['maintenance']) ? max(0, (int) $daily_entry['maintenance']) : 0;
        $effective_total = max(0, $total - $maintenance);
        $up = isset($daily_entry['up']) ? (int) $daily_entry['up'] : 0;
        $uptime_value = $effective_total > 0 ? ($up / $effective_total) * 100 : 100;
        $uptime_value = max(0, min(100, $uptime_value));
        $bar_height = (int) max(4, round($uptime_value));
        $trend_timestamp = isset($daily_entry['last_timestamp']) ? (int) $daily_entry['last_timestamp'] : strtotime($day_key . ' 23:59:59');
        $formatted_day = wp_date($date_format, $trend_timestamp);
        $formatted_value = number_format_i18n($uptime_value, 2);
        $total_label = number_format_i18n($total);
        $bar_class = 'uptime-trend__bar--high';

        if ($uptime_value < 95) {
            $bar_class = 'uptime-trend__bar--low';
        } elseif ($uptime_value < 99) {
            $bar_class = 'uptime-trend__bar--medium';
        }

        $trend_data[] = [
            'height' => $bar_height,
            'class'  => $bar_class,
            'label'  => sprintf(
                /* translators: 1: formatted date, 2: uptime percentage, 3: number of checks. */
                __('Disponibilité du %1$s : %2$s%% (%3$s contrôles)', 'sitepulse'),
                $formatted_day,
                $formatted_value,
                $total_label
            ),
        ];
    }

    $seven_day_metrics = sitepulse_calculate_uptime_window_metrics($uptime_archive, 7);
    $thirty_day_metrics = sitepulse_calculate_uptime_window_metrics($uptime_archive, 30);
    $agent_metrics = sitepulse_calculate_agent_uptime_metrics($uptime_archive, 30);
    $region_metrics = sitepulse_calculate_region_uptime_metrics($agent_metrics, $agents);
    $maintenance_windows = sitepulse_uptime_get_maintenance_windows();

    $last_checks = [];

    foreach ($uptime_log as $entry) {
        if (!isset($entry['agent'])) {
            continue;
        }

        $agent_id = sitepulse_uptime_normalize_agent_id($entry['agent']);

        if (!isset($last_checks[$agent_id]) || $entry['timestamp'] >= $last_checks[$agent_id]['timestamp']) {
            $last_checks[$agent_id] = $entry;
        }
    }

    $current_timestamp = (int) current_time('timestamp');

    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-uptime');
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-chart-bar"></span> <?php esc_html_e('Suivi de Disponibilité', 'sitepulse'); ?></h1>
        <p>
            <?php
            printf(
                /* translators: %s: number of uptime checks. */
                esc_html__('Cet outil vérifie la disponibilité de votre site toutes les heures. Voici le statut des %s dernières vérifications.', 'sitepulse'),
                esc_html(number_format_i18n($total_checks))
            );
            ?>
        </p>
        <h2>
            <?php
            echo wp_kses_post(
                sprintf(
                    /* translators: 1: number of checks, 2: uptime percentage. */
                    __('Disponibilité (%1$s dernières heures) : <strong style="font-size: 1.4em;">%2$s%%</strong>', 'sitepulse'),
                    esc_html(number_format_i18n($total_checks)),
                    esc_html(number_format_i18n($uptime_percentage, 2))
                )
            );
            ?>
        </h2>
        <div class="uptime-summary-grid">
            <div class="uptime-summary-card">
                <h3><?php esc_html_e('Disponibilité 7 derniers jours', 'sitepulse'); ?></h3>
                <p class="uptime-summary-card__value"><?php echo esc_html(number_format_i18n($seven_day_metrics['uptime'], 2)); ?>%</p>
                <p class="uptime-summary-card__meta"><?php
                    printf(
                        /* translators: 1: total checks, 2: incidents */
                        esc_html__('Sur %1$s contrôles (%2$s incidents)', 'sitepulse'),
                        esc_html(number_format_i18n($seven_day_metrics['total_checks'])),
                        esc_html(number_format_i18n($seven_day_metrics['down_checks']))
                    );
                ?></p>
            </div>
            <div class="uptime-summary-card">
                <h3><?php esc_html_e('Disponibilité 30 derniers jours', 'sitepulse'); ?></h3>
                <p class="uptime-summary-card__value"><?php echo esc_html(number_format_i18n($thirty_day_metrics['uptime'], 2)); ?>%</p>
                <p class="uptime-summary-card__meta"><?php
                    printf(
                        /* translators: 1: total checks, 2: incidents */
                        esc_html__('Sur %1$s contrôles (%2$s incidents)', 'sitepulse'),
                        esc_html(number_format_i18n($thirty_day_metrics['total_checks'])),
                        esc_html(number_format_i18n($thirty_day_metrics['down_checks']))
                    );
                ?></p>
            </div>
        </div>
        <h2><?php esc_html_e('Disponibilité par localisation', 'sitepulse'); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Agent', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Région', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Uptime (30 jours)', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Dernier statut', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Contrôle le', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Maintenance', 'sitepulse'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($agents as $agent_id => $agent_data) :
                    $agent_metrics_entry = isset($agent_metrics[$agent_id]) ? $agent_metrics[$agent_id] : [
                        'uptime'          => 100,
                        'effective_total' => 0,
                        'up'              => 0,
                        'down'            => 0,
                        'unknown'         => 0,
                        'maintenance'     => 0,
                    ];
                    $uptime_value = number_format_i18n($agent_metrics_entry['uptime'], 2);
                    $last_entry = isset($last_checks[$agent_id]) ? $last_checks[$agent_id] : null;
                    $status_label = __('Aucun contrôle', 'sitepulse');
                    $status_class = 'status-unknown';
                    $last_check_time = __('Jamais', 'sitepulse');

                    if ($last_entry) {
                        $last_check_time = date_i18n($date_format . ' ' . $time_format, (int) $last_entry['timestamp']);
                        $status_value = isset($last_entry['status']) ? $last_entry['status'] : null;

                        if (true === $status_value) {
                            $status_label = __('Disponible', 'sitepulse');
                            $status_class = 'status-up';
                        } elseif (false === $status_value) {
                            $status_label = __('Incident', 'sitepulse');
                            $status_class = 'status-down';
                        } elseif ('maintenance' === $status_value) {
                            $status_label = __('Maintenance', 'sitepulse');
                            $status_class = 'status-maintenance';
                        } else {
                            $status_label = __('Inconnu', 'sitepulse');
                            $status_class = 'status-unknown';
                        }
                    }

                    $active_maintenance = sitepulse_uptime_is_in_maintenance_window($agent_id, $current_timestamp);
                    $next_window = null;

                    foreach ($maintenance_windows as $window) {
                        if ('all' !== $window['agent'] && $window['agent'] !== $agent_id) {
                            continue;
                        }

                        if ($window['start'] <= $current_timestamp && $window['end'] >= $current_timestamp) {
                            $next_window = $window;
                            break;
                        }

                        if ($window['start'] > $current_timestamp) {
                            if (null === $next_window || $window['start'] < $next_window['start']) {
                                $next_window = $window;
                            }
                        }
                    }

                    $maintenance_label = $active_maintenance
                        ? __('Maintenance active', 'sitepulse')
                        : __('Aucune', 'sitepulse');

                    if ($next_window && !$active_maintenance) {
                        $maintenance_label = sprintf(
                            /* translators: 1: start date, 2: end date. */
                            __('Prochaine : du %1$s au %2$s', 'sitepulse'),
                            date_i18n($date_format . ' ' . $time_format, (int) $next_window['start']),
                            date_i18n($date_format . ' ' . $time_format, (int) $next_window['end'])
                        );
                    } elseif ($next_window && $active_maintenance) {
                        $maintenance_label = sprintf(
                            /* translators: 1: end date. */
                            __('Maintenance en cours jusqu’au %s', 'sitepulse'),
                            date_i18n($date_format . ' ' . $time_format, (int) $next_window['end'])
                        );
                    }
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($agent_data['label']); ?></strong><br />
                        <small><?php echo esc_html($agent_id); ?></small>
                    </td>
                    <td><?php echo esc_html(isset($agent_data['region']) ? strtoupper($agent_data['region']) : 'GLOBAL'); ?></td>
                    <td><?php echo esc_html($uptime_value); ?>%</td>
                    <td><span class="sitepulse-uptime-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                    <td><?php echo esc_html($last_check_time); ?></td>
                    <td><?php echo esc_html($maintenance_label); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($region_metrics)) : ?>
            <h2><?php esc_html_e('Disponibilité par région', 'sitepulse'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Région', 'sitepulse'); ?></th>
                        <th><?php esc_html_e('Agents suivis', 'sitepulse'); ?></th>
                        <th><?php esc_html_e('Uptime (30 jours)', 'sitepulse'); ?></th>
                        <th><?php esc_html_e('Incidents', 'sitepulse'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($region_metrics as $region => $metrics) :
                        $region_label = strtoupper($region);
                        $incident_count = isset($metrics['down']) ? (int) $metrics['down'] : 0;
                    ?>
                    <tr>
                        <td><?php echo esc_html($region_label); ?></td>
                        <td><?php echo esc_html(implode(', ', $metrics['agents'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($metrics['uptime'], 2)); ?>%</td>
                        <td><?php echo esc_html(number_format_i18n($incident_count)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($maintenance_windows)) : ?>
            <h2><?php esc_html_e('Fenêtres de maintenance programmées', 'sitepulse'); ?></h2>
            <ul class="sitepulse-maintenance-list">
                <?php foreach ($maintenance_windows as $window) :
                    $window_agent = 'all' === $window['agent'] ? __('Tous les agents', 'sitepulse') : $window['agent'];
                ?>
                <li>
                    <strong><?php echo esc_html($window_agent); ?></strong> —
                    <?php echo esc_html(date_i18n($date_format . ' ' . $time_format, (int) $window['start'])); ?>
                    →
                    <?php echo esc_html(date_i18n($date_format . ' ' . $time_format, (int) $window['end'])); ?>
                    <?php if (!empty($window['label'])) : ?>
                        <em><?php echo esc_html($window['label']); ?></em>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="uptime-chart">
            <?php if (empty($uptime_log)) : ?>
                <p><?php esc_html_e("Aucune donnée de disponibilité. La première vérification aura lieu dans l'heure.", 'sitepulse'); ?></p>
            <?php else : ?>
                <?php foreach ($uptime_log as $index => $entry): ?>
                    <?php
                    $status = $entry['status'] ?? null;
                    $bar_class = 'unknown';
                    if (true === $status) {
                        $bar_class = 'up';
                    } elseif (false === $status) {
                        $bar_class = 'down';
                    } elseif ('maintenance' === $status) {
                        $bar_class = 'maintenance';
                    }
                    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                    $check_time = $timestamp > 0
                        ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                        : __('Horodatage inconnu', 'sitepulse');
                    $previous_entry = $index > 0 ? $uptime_log[$index - 1] : null;
                    $next_entry = ($index + 1) < $total_checks ? $uptime_log[$index + 1] : null;

                    $status_label = '';
                    $duration_label = '';

                    if (true === $status) {
                        $bar_title = sprintf(__('Site OK lors du contrôle du %s.', 'sitepulse'), $check_time);
                        $status_label = __('Statut : site disponible.', 'sitepulse');

                        if (!empty($previous_entry) && isset($previous_entry['status']) && is_bool($previous_entry['status']) && false === $previous_entry['status']) {
                            $incident_start = isset($previous_entry['incident_start']) ? (int) $previous_entry['incident_start'] : (isset($previous_entry['timestamp']) ? (int) $previous_entry['timestamp'] : 0);
                            if ($incident_start > 0 && $timestamp >= $incident_start) {
                                $incident_start_formatted = date_i18n($date_format . ' ' . $time_format, $incident_start);
                                $bar_title .= ' ' . sprintf(__('Retour à la normale après un incident débuté le %1$s (durée : %2$s).', 'sitepulse'), $incident_start_formatted, human_time_diff($incident_start, $timestamp));
                                $duration_label = sprintf(__('Durée de l’incident résolu : %s.', 'sitepulse'), human_time_diff($incident_start, $timestamp));
                            }
                        }

                        if ('' === $duration_label) {
                            $duration_label = __('Durée : disponibilité confirmée lors de ce contrôle.', 'sitepulse');
                        }
                    } elseif (false === $status) {
                        $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : $timestamp;
                        $incident_start_formatted = $incident_start > 0
                            ? date_i18n($date_format . ' ' . $time_format, $incident_start)
                            : __('horodatage inconnu', 'sitepulse');
                        $bar_title = sprintf(__('Site KO lors du contrôle du %1$s. Incident commencé le %2$s.', 'sitepulse'), $check_time, $incident_start_formatted);
                        $status_label = __('Statut : site indisponible.', 'sitepulse');

                        if (array_key_exists('error', $entry)) {
                            $error_detail = is_scalar($entry['error']) ? (string) $entry['error'] : wp_json_encode($entry['error']);

                            if ('' !== $error_detail && false !== $error_detail) {
                                $bar_title .= ' ' . sprintf(__('Détails : %s.', 'sitepulse'), $error_detail);
                            }
                        }

                        $is_transition = empty($previous_entry) || (isset($previous_entry['status']) && true === $previous_entry['status']);

                        if ($index === $total_checks - 1 && !empty($current_incident_duration)) {
                            $bar_title .= ' ' . sprintf(__('Incident en cours depuis %s.', 'sitepulse'), $current_incident_duration);
                            $duration_label = sprintf(__('Durée de l’incident en cours : %s.', 'sitepulse'), $current_incident_duration);
                        } else {
                            $duration_reference = null;

                            if (!empty($next_entry) && isset($next_entry['status']) && true === $next_entry['status']) {
                                $duration_reference = isset($next_entry['timestamp']) ? (int) $next_entry['timestamp'] : null;
                            } elseif ($timestamp > 0) {
                                $duration_reference = $timestamp;
                            }

                            if ($duration_reference && $incident_start && $duration_reference >= $incident_start) {
                                $duration_text = human_time_diff($incident_start, $duration_reference);
                                $label = $is_transition ? __('Durée estimée : %s.', 'sitepulse') : __('Durée cumulée : %s.', 'sitepulse');
                                $bar_title .= ' ' . sprintf($label, $duration_text);
                                $duration_label = sprintf(__('Durée de l’incident : %s.', 'sitepulse'), $duration_text);
                            }
                        }

                        if ('' === $duration_label) {
                            $duration_label = __('Durée : incident en cours, durée non déterminée.', 'sitepulse');
                        }
                    } elseif ('maintenance' === $status) {
                        $bar_title = sprintf(__('Fenêtre de maintenance lors du contrôle du %s.', 'sitepulse'), $check_time);
                        $status_label = __('Statut : maintenance planifiée.', 'sitepulse');
                        $duration_label = __('Durée : ce contrôle est ignoré pour le calcul de disponibilité.', 'sitepulse');
                    } else {
                        $error_text = isset($entry['error']) ? $entry['error'] : __('Erreur réseau inconnue.', 'sitepulse');
                        $bar_title = sprintf(__('Statut indéterminé lors du contrôle du %1$s : %2$s', 'sitepulse'), $check_time, $error_text);
                        $status_label = __('Statut : indéterminé.', 'sitepulse');
                        $duration_label = __('Durée : impossible à déterminer pour ce contrôle.', 'sitepulse');
                    }
                    $screen_reader_text = implode(' ', array_filter([
                        sprintf(__('Contrôle du %s.', 'sitepulse'), $check_time),
                        $status_label,
                        $duration_label,
                    ]));
                    ?>
                    <div class="uptime-bar <?php echo esc_attr($bar_class); ?>" title="<?php echo esc_attr($bar_title); ?>">
                        <span class="screen-reader-text"><?php echo esc_html($screen_reader_text); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="uptime-timeline__labels"><span><?php echo sprintf(esc_html__('Il y a %d heures', 'sitepulse'), absint($total_checks)); ?></span><span><?php esc_html_e('Maintenant', 'sitepulse'); ?></span></div>
        <?php if (!empty($current_incident_duration) && null !== $current_incident_start): ?>
            <div class="notice notice-error uptime-notice--error">
                <p>
                    <strong><?php esc_html_e('Incident en cours', 'sitepulse'); ?> :</strong>
                    <?php
                    $current_incident_start_formatted = date_i18n($date_format . ' ' . $time_format, $current_incident_start);
                    echo esc_html(
                        sprintf(
                            __('Votre site est signalé comme indisponible depuis le %1$s (%2$s).', 'sitepulse'),
                            $current_incident_start_formatted,
                            $current_incident_duration
                        )
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>
        <?php if (!empty($trend_data)): ?>
            <h2><?php esc_html_e('Tendance de disponibilité (30 jours)', 'sitepulse'); ?></h2>
            <div class="uptime-trend" role="img" aria-label="<?php echo esc_attr(sprintf(__('Disponibilité quotidienne sur %d jours.', 'sitepulse'), count($trend_data))); ?>">
                <?php foreach ($trend_data as $bar): ?>
                    <span class="uptime-trend__bar <?php echo esc_attr($bar['class']); ?>" style="height: <?php echo esc_attr($bar['height']); ?>%;" title="<?php echo esc_attr($bar['label']); ?>"></span>
                <?php endforeach; ?>
            </div>
            <p class="uptime-trend__legend">
                <span class="uptime-trend__legend-item uptime-trend__legend-item--high"><?php esc_html_e('≥ 99% de disponibilité', 'sitepulse'); ?></span>
                <span class="uptime-trend__legend-item uptime-trend__legend-item--medium"><?php esc_html_e('95 – 98% de disponibilité', 'sitepulse'); ?></span>
                <span class="uptime-trend__legend-item uptime-trend__legend-item--low"><?php esc_html_e('< 95% de disponibilité', 'sitepulse'); ?></span>
            </p>
        <?php endif; ?>
        <div class="notice notice-info uptime-notice--info"><p><strong><?php esc_html_e('Comment ça marche :', 'sitepulse'); ?></strong> <?php echo esc_html__('Une barre verte indique que votre site était en ligne. Une barre rouge indique un possible incident où votre site était inaccessible.', 'sitepulse'); ?></p></div>
    </div>
    <?php
}
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
    $timestamp = (int) current_time('timestamp');

    if (sitepulse_uptime_is_in_maintenance_window($request_agent, $timestamp)) {
        $entry = [
            'timestamp' => $timestamp,
            'status'    => 'maintenance',
            'agent'     => $request_agent,
        ];

        $log[] = $entry;

        if (count($log) > 30) {
            $log = array_slice($log, -30);
        }

        update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
        sitepulse_update_uptime_archive($entry);
        sitepulse_log(sprintf('Uptime check skipped for %s due to maintenance window.', $request_agent), 'INFO');

        return;
    }

    $response = wp_remote_request($request_url, $request_args);

    $entry = [
        'timestamp' => $timestamp,
        'agent'     => $request_agent,
    ];

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

        if (count($log) > 30) {
            $log = array_slice($log, -30);
        }

        update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
        sitepulse_update_uptime_archive($entry);

        return;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $is_up = $response_code >= 200 && $response_code < 400;

    if (!empty($expected_codes)) {
        $is_up = in_array((int) $response_code, $expected_codes, true);
    }

    if ((int) get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) !== 0) {
        update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0, false);
    }

    $entry['status'] = $is_up;

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
        $entry['error'] = sprintf('HTTP %d', $response_code);
    }

    $log[] = $entry;

    if (!$is_up) {
        sitepulse_log(sprintf('Uptime check: Down (HTTP %d)', $response_code), 'ALERT');
    } else {
        sitepulse_log('Uptime check: Up');
    }

    if (count($log) > 30) {
        $log = array_slice($log, -30);
    }

    update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
    sitepulse_update_uptime_archive($entry);
}
