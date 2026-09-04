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

require_once __DIR__ . '/uptime/rest.php';

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

require_once __DIR__ . '/uptime/queue.php';
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

require_once __DIR__ . '/uptime/sla.php';

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

require_once __DIR__ . '/uptime/checker.php';
