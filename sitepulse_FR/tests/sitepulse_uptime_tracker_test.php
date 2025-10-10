<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('SITEPULSE_OPTION_UPTIME_LOG')) {
    define('SITEPULSE_OPTION_UPTIME_LOG', 'sitepulse_uptime_log');
}

if (!defined('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK')) {
    define('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK', 'sitepulse_uptime_failure_streak');
}

if (!defined('SITEPULSE_OPTION_UPTIME_TIMEOUT')) {
    define('SITEPULSE_OPTION_UPTIME_TIMEOUT', 'sitepulse_uptime_timeout');
}

if (!defined('SITEPULSE_OPTION_UPTIME_FREQUENCY')) {
    define('SITEPULSE_OPTION_UPTIME_FREQUENCY', 'sitepulse_uptime_frequency');
}

if (!defined('SITEPULSE_OPTION_UPTIME_HTTP_METHOD')) {
    define('SITEPULSE_OPTION_UPTIME_HTTP_METHOD', 'sitepulse_uptime_http_method');
}

if (!defined('SITEPULSE_OPTION_UPTIME_HTTP_HEADERS')) {
    define('SITEPULSE_OPTION_UPTIME_HTTP_HEADERS', 'sitepulse_uptime_http_headers');
}

if (!defined('SITEPULSE_OPTION_UPTIME_EXPECTED_CODES')) {
    define('SITEPULSE_OPTION_UPTIME_EXPECTED_CODES', 'sitepulse_uptime_expected_codes');
}

if (!defined('SITEPULSE_OPTION_UPTIME_LATENCY_THRESHOLD')) {
    define('SITEPULSE_OPTION_UPTIME_LATENCY_THRESHOLD', 'sitepulse_uptime_latency_threshold');
}

if (!defined('SITEPULSE_OPTION_UPTIME_KEYWORD')) {
    define('SITEPULSE_OPTION_UPTIME_KEYWORD', 'sitepulse_uptime_keyword');
}

if (!defined('SITEPULSE_OPTION_UPTIME_URL')) {
    define('SITEPULSE_OPTION_UPTIME_URL', 'sitepulse_uptime_url');
}

$GLOBALS['sitepulse_options'] = [];
$GLOBALS['sitepulse_logger'] = [];
$GLOBALS['sitepulse_remote_queue'] = [];
$GLOBALS['sitepulse_filter_overrides'] = [];
$GLOBALS['sitepulse_fake_time'] = 1_700_000_000;

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;

        public function __construct($code = '', $message = '')
        {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $params;

        public function __construct(array $params = [])
        {
            $this->params = $params;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }
    }
}

function add_action(...$args) {}
function add_filter(...$args) {}
function add_submenu_page(...$args) {}
function sitepulse_get_cron_hook($hook) { return $hook; }
function wp_next_scheduled(...$args) { return false; }
function wp_schedule_event(...$args) { return true; }
function do_action(...$args) {}
function sitepulse_register_cron_warning(...$args) {}
function sitepulse_clear_cron_warning(...$args) {}
function current_user_can(...$args) { return true; }
function wp_die(...$args) { throw new RuntimeException('wp_die called'); }
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html($text) { return $text; }
function esc_attr($text) { return $text; }
function _n($single, $plural, $number, $domain = null) { return $number === 1 ? $single : $plural; }
function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, (int) $decimals, '.', ','); }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key)); }
function sanitize_text_field($text) { return is_string($text) ? trim($text) : $text; }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }
function wp_http_validate_url($url) { return filter_var($url, FILTER_VALIDATE_URL) ? $url : false; }
function date_i18n($format, $timestamp) { return date($format, $timestamp); }
function wp_date($format, $timestamp = null) { return date($format, $timestamp ?? time()); }
function human_time_diff($from, $to) { return ($to - $from) . ' seconds'; }
function get_option($name, $default = false) {
    return $GLOBALS['sitepulse_options'][$name] ?? $default;
}
function update_option($name, $value, $autoload = false) {
    $GLOBALS['sitepulse_options'][$name] = $value;
    return true;
}
function home_url($path = '') {
    return 'https://example.com' . $path;
}
function current_time($type, $gmt = 0) {
    return $GLOBALS['sitepulse_fake_time'];
}
function apply_filters($hook, $value, ...$args) {
    if (isset($GLOBALS['sitepulse_filter_overrides'][$hook])) {
        return call_user_func($GLOBALS['sitepulse_filter_overrides'][$hook], $value, ...$args);
    }

    return $value;
}
function wp_remote_get($url, $args = []) {
    if (empty($GLOBALS['sitepulse_remote_queue'])) {
        throw new RuntimeException('wp_remote_get queue is empty');
    }

    return array_shift($GLOBALS['sitepulse_remote_queue']);
}
function wp_remote_request($url, $args = []) { return wp_remote_get($url, $args); }
function wp_remote_retrieve_response_code($response) {
    if (is_array($response) && isset($response['response']['code'])) {
        return (int) $response['response']['code'];
    }

    return 0;
}
function wp_remote_retrieve_body($response) {
    if (is_array($response) && isset($response['body'])) {
        return (string) $response['body'];
    }

    return '';
}
function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}
function sitepulse_log($message, $level = 'INFO') {
    $GLOBALS['sitepulse_logger'][] = [
        'message' => $message,
        'level'   => $level,
    ];
}

require_once dirname(__DIR__) . '/modules/uptime_tracker.php';

function sitepulse_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sitepulse_reset_state()
{
    $GLOBALS['sitepulse_options'] = [];
    $GLOBALS['sitepulse_logger'] = [];
    $GLOBALS['sitepulse_remote_queue'] = [];
    $GLOBALS['sitepulse_filter_overrides'] = [];
    $GLOBALS['sitepulse_fake_time'] = 1_700_000_000;
}

// Scenario 1: single WP_Error should record an unknown status and warning.
sitepulse_reset_state();
$GLOBALS['sitepulse_filter_overrides']['sitepulse_uptime_consecutive_failures'] = function ($default, $streak) {
    return 2;
};
$GLOBALS['sitepulse_remote_queue'][] = new WP_Error('timeout', 'Request timeout');
sitepulse_run_uptime_check();

$log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
sitepulse_assert(count($log) === 1, 'Expected one log entry after first WP_Error.');
sitepulse_assert($log[0]['status'] === 'unknown', 'First entry must be marked as unknown.');
sitepulse_assert($log[0]['error'] === 'Request timeout', 'Error message must be preserved.');
sitepulse_assert(!isset($log[0]['incident_start']), 'Unknown status should not record an incident start.');
sitepulse_assert(get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) === 1, 'Failure streak should increment to 1.');
$last_log = end($GLOBALS['sitepulse_logger']);
sitepulse_assert($last_log['level'] === 'WARNING', 'First network error should log a warning.');

// Scenario 2: consecutive WP_Error reaches threshold and escalates to alert.
$GLOBALS['sitepulse_fake_time'] += HOUR_IN_SECONDS;
$GLOBALS['sitepulse_remote_queue'][] = new WP_Error('timeout', 'Request timeout');
sitepulse_run_uptime_check();

$log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
sitepulse_assert(count($log) === 2, 'Expected two log entries after consecutive WP_Error.');
sitepulse_assert(get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) === 2, 'Failure streak should increment to 2.');
$last_log = end($GLOBALS['sitepulse_logger']);
sitepulse_assert($last_log['level'] === 'ALERT', 'Second consecutive network error should trigger an alert.');

// Scenario 3: recovery resets streak and records uptime.
$GLOBALS['sitepulse_fake_time'] += HOUR_IN_SECONDS;
$GLOBALS['sitepulse_remote_queue'][] = ['response' => ['code' => 200]];
sitepulse_run_uptime_check();

$log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
sitepulse_assert(end($log)['status'] === true, 'Successful check should be marked as up.');
sitepulse_assert(get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) === 0, 'Failure streak should reset after success.');

// Scenario 4: queue instrumentation records pruning statistics and backlog metrics.
sitepulse_reset_state();

$GLOBALS['sitepulse_filter_overrides']['sitepulse_uptime_remote_queue_max_size'] = function () {
    return 2;
};

$now = $GLOBALS['sitepulse_fake_time'];

$raw_queue = [
    [
        'agent'       => 'agent-a',
        'payload'     => ['url' => 'https://example.com'],
        'scheduled_at'=> $now - 400,
        'created_at'  => $now - 500,
    ],
    [
        'agent'       => 'agent-a',
        'payload'     => ['url' => 'https://example.com'],
        'scheduled_at'=> $now - 400,
        'created_at'  => $now - 100,
    ],
    [
        'agent'       => 'agent-b',
        'payload'     => ['url' => 'https://example.net'],
        'scheduled_at'=> $now - (DAY_IN_SECONDS + 100),
        'created_at'  => $now - (DAY_IN_SECONDS + 50),
    ],
    [
        'agent'       => 'agent-c',
        'payload'     => ['url' => 'https://example.org'],
        'scheduled_at'=> $now + 600,
        'created_at'  => $now - 50,
    ],
    [
        'agent'       => 'agent-d',
        'payload'     => ['url' => 'https://example.net/ping'],
        'scheduled_at'=> $now - 10,
        'created_at'  => $now - 20,
    ],
    'invalid-item',
];

$normalized = sitepulse_uptime_normalize_remote_queue($raw_queue, $now);

sitepulse_assert(count($normalized) === 2, 'Normalized queue should honour remote queue max size.');

$metrics_payload = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS, []);
sitepulse_assert(is_array($metrics_payload), 'Metrics payload must be stored as an array.');
sitepulse_assert(isset($metrics_payload['metrics']), 'Metrics payload should include collected metrics.');

$metrics = $metrics_payload['metrics'];

sitepulse_assert($metrics['requested'] === 6, 'Expected six items to be processed for instrumentation.');
sitepulse_assert($metrics['retained'] === 2, 'Two entries should remain after normalization.');
sitepulse_assert($metrics['dropped_duplicates'] === 1, 'Duplicate entries should be counted.');
sitepulse_assert($metrics['dropped_expired'] === 1, 'Expired entries should be pruned.');
sitepulse_assert($metrics['dropped_invalid'] === 1, 'Invalid entries should be reported.');
sitepulse_assert($metrics['dropped_overflow'] === 1, 'Overflow entries should be tracked when the limit is reached.');
sitepulse_assert($metrics['delayed_jobs'] === 2, 'Both retained entries are already due and must be counted as delayed.');
sitepulse_assert($metrics['max_wait_seconds'] === 400, 'Max wait should reflect the oldest scheduled timestamp.');
sitepulse_assert($metrics['avg_wait_seconds'] === 205, 'Average wait should be rounded to the nearest second.');
sitepulse_assert($metrics['next_scheduled_at'] === $now - 400, 'Next scheduled timestamp should match the oldest entry.');
sitepulse_assert($metrics['oldest_created_at'] === $now - 500, 'Oldest created timestamp should track the earliest queue entry.');

// Scenario 4b: queue analysis summarises alerts and exposes formatted labels.
$analysis_payload = [
    'updated_at' => $now - 1_800,
    'metrics'    => [
        'requested'          => 12,
        'retained'           => 4,
        'queue_length'       => 4,
        'delayed_jobs'       => 3,
        'max_wait_seconds'   => 2_100,
        'avg_wait_seconds'   => 650,
        'next_scheduled_at'  => $now + 120,
        'oldest_created_at'  => $now - 3_600,
        'limit_ttl'          => 3_600,
        'limit_size'         => 4,
        'dropped_invalid'    => 1,
        'dropped_expired'    => 1,
        'dropped_duplicates' => 0,
        'dropped_overflow'   => 0,
    ],
];

$analysis = sitepulse_uptime_analyze_remote_queue($analysis_payload, $now);
$alerts = $analysis['status']['alerts'];
$capacity_alert = null;

foreach ($alerts as $alert) {
    if (isset($alert['code']) && $alert['code'] === 'queue_capacity_exceeded') {
        $capacity_alert = $alert;
        break;
    }
}

sitepulse_assert($analysis['status']['level'] === 'critical', 'Combined capacity pressure and wait time should escalate to critical.');
sitepulse_assert($analysis['metrics']['dropped_total'] === 2, 'Dropped total should include invalid and expired entries.');
sitepulse_assert($capacity_alert !== null, 'Capacity alert should be raised when the queue reaches its maximum size.');
sitepulse_assert(isset($analysis['schedule']['next']['label']) && $analysis['schedule']['next']['label'] !== '—', 'Next schedule label should provide formatted output.');

// Scenario 5: persistent outage with unknown sample keeps original incident start.
sitepulse_reset_state();
$base_time = 1_700_000_000;

$GLOBALS['sitepulse_fake_time'] = $base_time;
$GLOBALS['sitepulse_remote_queue'][] = ['response' => ['code' => 500]];
sitepulse_run_uptime_check();

$GLOBALS['sitepulse_fake_time'] = $base_time + HOUR_IN_SECONDS;
$GLOBALS['sitepulse_remote_queue'][] = new WP_Error('timeout', 'Temporary glitch');
sitepulse_run_uptime_check();

$GLOBALS['sitepulse_fake_time'] = $base_time + (2 * HOUR_IN_SECONDS);
$GLOBALS['sitepulse_remote_queue'][] = ['response' => ['code' => 500]];
sitepulse_run_uptime_check();

$log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
sitepulse_assert(count($log) === 3, 'Expected three log entries in mixed outage scenario.');
sitepulse_assert($log[0]['status'] === false, 'First entry should be a downtime event.');
sitepulse_assert(isset($log[0]['incident_start']), 'First downtime should define incident start.');
sitepulse_assert($log[1]['status'] === 'unknown', 'Second entry should remain unknown.');
sitepulse_assert(!isset($log[1]['incident_start']), 'Unknown sample should not have incident start.');
sitepulse_assert($log[2]['status'] === false, 'Third entry should record ongoing downtime.');
sitepulse_assert($log[2]['incident_start'] === $log[0]['incident_start'], 'Incident start should persist across unknown sample.');

// Scenario 6: REST endpoint exposes sanitised queue health.
sitepulse_reset_state();
$now = $GLOBALS['sitepulse_fake_time'];

update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS, [
    'updated_at' => $now - 900,
    'metrics'    => [
        'requested'        => 8,
        'retained'         => 2,
        'queue_length'     => 2,
        'delayed_jobs'     => 1,
        'max_wait_seconds' => 700,
        'avg_wait_seconds' => 350,
        'next_scheduled_at'=> $now + 300,
        'oldest_created_at'=> $now - 1_200,
        'limit_ttl'        => 3_600,
        'limit_size'       => 4,
    ],
]);

$rest_payload = sitepulse_uptime_rest_remote_queue_callback(new WP_REST_Request(['context' => 'view']));

sitepulse_assert(is_array($rest_payload), 'REST callback should return an array when rest_ensure_response is unavailable.');
sitepulse_assert(isset($rest_payload['metrics']['queue_length']) && $rest_payload['metrics']['queue_length'] === 2, 'REST payload should expose queue length.');
sitepulse_assert(isset($rest_payload['status']['level']) && $rest_payload['status']['level'] === 'warning', 'Delayed job should trigger a warning status over REST.');
sitepulse_assert(isset($rest_payload['metadata']['updated']['formatted']) && $rest_payload['metadata']['updated']['formatted'] !== null, 'REST metadata must include formatted update labels.');

echo "All uptime tracker assertions passed." . PHP_EOL;
