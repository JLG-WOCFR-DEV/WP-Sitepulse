<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);

if (!defined('SITEPULSE_OPTION_CRON_WARNINGS')) {
    define('SITEPULSE_OPTION_CRON_WARNINGS', 'sitepulse_cron_warnings');
}

if (!defined('SITEPULSE_OPTION_AI_INSIGHT_ERRORS')) {
    define('SITEPULSE_OPTION_AI_INSIGHT_ERRORS', 'sitepulse_ai_insight_errors');
}

$GLOBALS['sitepulse_options'] = [];

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $GLOBALS['sitepulse_options'][$name] ?? $default;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return strip_tags((string) $text);
    }
}

require_once dirname(__DIR__) . '/includes/site-health-alerts.php';

function sitepulse_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Empty options should return empty arrays.
$alerts = sitepulse_get_site_health_alert_messages(true);
sitepulse_assert($alerts['cron'] === [] && $alerts['ai'] === [], 'Empty options should produce empty alert buckets.');

// Populate options with mixed formats and unsafe HTML.
$GLOBALS['sitepulse_options'][SITEPULSE_OPTION_CRON_WARNINGS] = [
    'resource_monitor' => ['message' => "\t   <strong>CPU usage critical</strong>\n"],
    'malformed'        => ['message' => ['nested' => 'ignored']],
    'duplicate'        => ['message' => '<em>CPU usage critical</em>'],
];

$GLOBALS['sitepulse_options'][SITEPULSE_OPTION_AI_INSIGHT_ERRORS] = [
    ['message' => '<script>alert(1)</script>Data breach risk detected.'],
    '   Gemini quota exceeded   ',
    ['message' => ''],
];

$alerts = sitepulse_get_site_health_alert_messages(true);

sitepulse_assert(count($alerts['cron']) === 1, 'Duplicate cron warnings should be deduplicated.');
sitepulse_assert($alerts['cron'][0] === 'CPU usage critical', 'Cron warning should be sanitized and trimmed.');
sitepulse_assert(in_array('Data breach risk detected.', $alerts['ai'], true), 'AI alert should be sanitized from HTML.');
sitepulse_assert(in_array('Gemini quota exceeded', $alerts['ai'], true), 'AI alert should trim whitespace.');

// Ensure runtime caching prevents extra reads until forced refresh.
$GLOBALS['sitepulse_options'][SITEPULSE_OPTION_CRON_WARNINGS]['resource_monitor']['message'] = 'Changed message';
$cached_alerts = sitepulse_get_site_health_alert_messages();
sitepulse_assert($cached_alerts['cron'][0] === 'CPU usage critical', 'Cached alerts should not change without refresh.');

$refreshed_alerts = sitepulse_get_site_health_alert_messages(true);
sitepulse_assert($refreshed_alerts['cron'][0] === 'Changed message', 'Force refresh should return the updated message.');

echo "All Site Health alert message assertions passed." . PHP_EOL;
