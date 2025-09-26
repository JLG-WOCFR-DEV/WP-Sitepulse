<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS')) {
    define('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS', 'sitepulse_speed_scan_results');
}

if (!defined('SITEPULSE_OPTION_LAST_LOAD_TIME')) {
    define('SITEPULSE_OPTION_LAST_LOAD_TIME', 'sitepulse_last_load_time');
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', __DIR__ . '/plugins');
}

if (!is_dir(WP_PLUGIN_DIR)) {
    mkdir(WP_PLUGIN_DIR, 0777, true);
}

$GLOBALS['sitepulse_options'] = [];
$GLOBALS['sitepulse_transients'] = [];
$GLOBALS['sitepulse_filter_overrides'] = [];
$GLOBALS['sitepulse_fake_time'] = 1_700_000_000;

function absint($maybeint) {
    return (int) abs((int) $maybeint);
}

function apply_filters($hook, $value, ...$args) {
    if (isset($GLOBALS['sitepulse_filter_overrides'][$hook])) {
        return $GLOBALS['sitepulse_filter_overrides'][$hook]($value, ...$args);
    }

    return $value;
}

function current_time($type, $gmt = 0) {
    return $GLOBALS['sitepulse_fake_time'];
}

function get_option($name, $default = false) {
    return $GLOBALS['sitepulse_options'][$name] ?? $default;
}

function update_option($name, $value, $autoload = false) {
    $GLOBALS['sitepulse_options'][$name] = $value;
    return true;
}

function get_transient($name) {
    return $GLOBALS['sitepulse_transients'][$name] ?? false;
}

function set_transient($name, $value, $expiration) {
    $GLOBALS['sitepulse_transients'][$name] = $value;
    return true;
}

function is_admin() {
    return false;
}

function is_multisite() {
    return false;
}

function get_site_option($name, $default = false) {
    return $default;
}

function wp_doing_cron() {
    return false;
}

function wp_doing_ajax() {
    return false;
}

function trailingslashit($string) {
    return rtrim($string, "\\/ ") . '/';
}

function get_plugin_data($plugin_file, $markup = true, $translate = true) {
    return ['Name' => 'Example Plugin'];
}

require_once dirname(__DIR__) . '/includes/plugin-impact-tracker.php';

function sitepulse_reset_state() {
    global $sitepulse_plugin_impact_tracker_samples, $sitepulse_plugin_impact_tracker_force_persist;

    $GLOBALS['sitepulse_options'] = [];
    $GLOBALS['sitepulse_transients'] = [];
    $GLOBALS['sitepulse_filter_overrides'] = [];
    $GLOBALS['sitepulse_fake_time'] = 1_700_000_000;
    $sitepulse_plugin_impact_tracker_samples = [];
    $sitepulse_plugin_impact_tracker_force_persist = false;
}

function sitepulse_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

sitepulse_reset_state();

$GLOBALS['sitepulse_filter_overrides']['sitepulse_plugin_impact_refresh_interval'] = function () {
    return 'banana';
};

$sitepulse_plugin_impact_tracker_force_persist = true;
$sitepulse_plugin_impact_tracker_samples = [
    'example/plugin.php' => 0.123,
];

sitepulse_plugin_impact_tracker_persist();

$payload = get_option(SITEPULSE_PLUGIN_IMPACT_OPTION, []);

sitepulse_assert(isset($payload['interval']), 'Persisted payload must include interval.');
sitepulse_assert($payload['interval'] === 1, 'Interval should fall back to sanitized minimum when filter returns invalid value.');

// Ensure samples were persisted using sanitized interval.
sitepulse_assert(!empty($payload['samples']), 'Samples should be persisted when available.');

$sitepulse_plugin_impact_tracker_force_persist = false;
$sitepulse_plugin_impact_tracker_samples = [];

// Subsequent call should respect sanitized interval for throttling.
sitepulse_plugin_impact_tracker_persist();
$second_payload = get_option(SITEPULSE_PLUGIN_IMPACT_OPTION, []);

sitepulse_assert($second_payload['interval'] === 1, 'Sanitized interval must persist across subsequent writes.');

echo "All plugin impact tracker assertions passed." . PHP_EOL;
