<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX')) {
    define('SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX', 'sitepulse_dashboard_impact_index');
}

if (!defined('SITEPULSE_OPTION_DASHBOARD_RANGE')) {
    define('SITEPULSE_OPTION_DASHBOARD_RANGE', 'sitepulse_dashboard_range');
}

$GLOBALS['sitepulse_options'] = [];
$GLOBALS['sitepulse_filter_overrides'] = [];
$GLOBALS['sitepulse_fake_time'] = 1_700_000_000;

function add_action(...$args) {}
function add_filter(...$args) {}
function wp_register_style(...$args) {}
function wp_enqueue_style(...$args) {}
function wp_register_script(...$args) {}
function wp_enqueue_script(...$args) {}
function wp_add_inline_script(...$args) {}
function wp_localize_script(...$args) { return true; }
function wp_create_nonce($action = '') { return 'nonce'; }
function admin_url($path = '') { return 'https://example.com/wp-admin/' . ltrim($path, '/'); }
function get_option($name, $default = false) { return $GLOBALS['sitepulse_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = false) { $GLOBALS['sitepulse_options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['sitepulse_options'][$name]); return true; }
function current_time($type, $gmt = 0) { return $GLOBALS['sitepulse_fake_time']; }
function apply_filters($hook, $value, ...$args) {
    if (isset($GLOBALS['sitepulse_filter_overrides'][$hook])) {
        return call_user_func($GLOBALS['sitepulse_filter_overrides'][$hook], $value, ...$args);
    }

    return $value;
}
function wp_parse_url($url) { return parse_url($url); }
function wp_http_validate_url($url) { return filter_var($url, FILTER_VALIDATE_URL) ? $url : false; }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key)); }
function sanitize_text_field($text) { return is_string($text) ? trim($text) : $text; }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }
function wp_json_encode($data) { return json_encode($data); }
function wp_date($format, $timestamp = null) { return date($format, $timestamp ?? time()); }
function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, (int) $decimals, '.', ','); }
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html_e($text, $domain = null) { echo $text; }
function esc_attr($text) { return $text; }
function esc_attr__($text, $domain = null) { return $text; }
function esc_attr_e($text, $domain = null) { echo $text; }
function esc_html($text) { return $text; }
function esc_url($url) { return $url; }
function esc_url_raw($url) { return $url; }
function _n($single, $plural, $number, $domain = null) { return $number === 1 ? $single : $plural; }
function human_time_diff($from, $to) { return ($to - $from) . ' seconds'; }
function admin_body_class($classes) { return $classes; }
function sitepulse_is_module_active($module) { return true; }
function checked($checked, $current = true, $echo = true) {
    $result = $checked == $current ? ' checked="checked"' : '';
    if ($echo) {
        echo $result;
        return;
    }
    return $result;
}
function selected($selected, $current = true, $echo = true) {
    $result = $selected == $current ? ' selected="selected"' : '';
    if ($echo) {
        echo $result;
        return;
    }
    return $result;
}
function disabled($disabled, $current = true, $echo = true) {
    $result = $disabled == $current ? ' disabled="disabled"' : '';
    if ($echo) {
        echo $result;
        return;
    }
    return $result;
}
function wp_normalize_path($path) { return str_replace('\\', '/', (string) $path); }
function wp_die(...$args) { throw new RuntimeException('wp_die called'); }

if (!defined('SITEPULSE_VERSION')) {
    define('SITEPULSE_VERSION', '1.2.3');
}

if (!defined('SITEPULSE_URL')) {
    define('SITEPULSE_URL', 'https://example.com/wp-content/plugins/sitepulse/');
}

require_once dirname(__DIR__) . '/modules/custom_dashboards.php';

function sitepulse_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sitepulse_reset_state() {
    $GLOBALS['sitepulse_options'] = [];
    $GLOBALS['sitepulse_filter_overrides'] = [];
    $GLOBALS['sitepulse_fake_time'] = 1_700_000_000;
}

sitepulse_reset_state();
$GLOBALS['sitepulse_options']['date_format'] = 'Y-m-d';
$GLOBALS['sitepulse_options']['time_format'] = 'H:i';

$modules_status = [
    'uptime_tracker' => true,
    'speed_analyzer' => true,
    'ai_insights'    => true,
];

$range = '7d';
$config = [
    'seconds' => 7 * DAY_IN_SECONDS,
    'days'    => 7,
];

$uptime_metrics = [
    'uptime'     => 98.7,
    'violations' => 2,
    'totals'     => ['total' => 120],
];

$speed_metrics = [
    'average'    => 320.0,
    'trend'      => 15.0,
    'thresholds' => ['warning' => 200, 'critical' => 450],
    'samples'    => 42,
];

$ai_summary = [
    'recent_total'        => 4,
    'recent_pending'      => 2,
    'recent_acknowledged' => 2,
    'stale_pending'       => 1,
];

$impact = sitepulse_custom_dashboard_calculate_transverse_impact_index(
    $range,
    $config,
    $modules_status,
    $uptime_metrics,
    $speed_metrics,
    $ai_summary
);

sitepulse_assert(isset($impact['overall']) && $impact['overall'] > 35.0 && $impact['overall'] < 60.0, 'Overall impact score should reflect mixed severity.');
sitepulse_assert($impact['dominant_module'] === 'speed_analyzer', 'Speed module should be dominant with the highest score.');
sitepulse_assert(isset($impact['modules']['speed_analyzer']), 'Speed analyzer module must be part of the impact snapshot.');
sitepulse_assert($impact['modules']['uptime_tracker']['status'] === sitepulse_custom_dashboard_resolve_score_status($impact['modules']['uptime_tracker']['score']), 'Uptime status should align with the normalized score.');

$stored_snapshot = get_option(SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX, []);
sitepulse_assert(is_array($stored_snapshot) && isset($stored_snapshot['impact']), 'Impact snapshot should be persisted after calculation.');

$card = sitepulse_custom_dashboard_format_impact_card_view($impact, 'Last 7 days');
sitepulse_assert($card['value']['text'] !== 'N/A', 'Impact card should expose a computed value.');
sitepulse_assert(count($card['details']) >= 3, 'Impact card should surface per-module details.');

$export_rows = sitepulse_custom_dashboard_format_impact_export_rows($impact, 'Last 7 days');
sitepulse_assert(count($export_rows) >= 3, 'Impact export should contain summary rows.');
sitepulse_assert($export_rows[0][0] === 'Indice transverse', 'First export row must label the impact section.');

$cached = sitepulse_custom_dashboard_get_cached_impact_index('7d', 3600);
sitepulse_assert(is_array($cached) && isset($cached['modules']), 'Cached impact snapshot should be retrievable.');

echo "All dashboard impact assertions passed." . PHP_EOL;
