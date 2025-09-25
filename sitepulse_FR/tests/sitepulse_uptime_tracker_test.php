<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('SITEPULSE_OPTION_UPTIME_LOG')) {
    define('SITEPULSE_OPTION_UPTIME_LOG', 'sitepulse_uptime_log');
}

if (!defined('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK')) {
    define('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK', 'sitepulse_uptime_failure_streak');
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

function add_action(...$args) {}
function add_submenu_page(...$args) {}
function sitepulse_get_cron_hook($hook) { return $hook; }
function wp_next_scheduled(...$args) { return false; }
function wp_schedule_event(...$args) { return true; }
function sitepulse_register_cron_warning(...$args) {}
function sitepulse_clear_cron_warning(...$args) {}
function current_user_can(...$args) { return true; }
function wp_die(...$args) { throw new RuntimeException('wp_die called'); }
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html($text) { return $text; }
function esc_attr($text) { return $text; }
function date_i18n($format, $timestamp) { return date($format, $timestamp); }
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
function wp_remote_retrieve_response_code($response) {
    if (is_array($response) && isset($response['response']['code'])) {
        return (int) $response['response']['code'];
    }

    return 0;
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

// Scenario 4: persistent outage with unknown sample keeps original incident start.
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
sitepulse_assert($log[2]['status'] === false, 'Third entry should record ongoing downtime.');
sitepulse_assert($log[2]['incident_start'] === $log[0]['incident_start'], 'Incident start should persist across unknown sample.');

echo "All uptime tracker assertions passed." . PHP_EOL;
