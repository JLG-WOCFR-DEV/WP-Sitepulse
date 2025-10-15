<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);

$GLOBALS['sitepulse_hooks'] = [];
$GLOBALS['sitepulse_options'] = [];
$GLOBALS['sitepulse_is_admin'] = false;

if (!defined('SITEPULSE_DEBUG')) {
    define('SITEPULSE_DEBUG', true);
}

if (!defined('SITEPULSE_DEBUG_NOTICE_QUEUE_LIMIT')) {
    define('SITEPULSE_DEBUG_NOTICE_QUEUE_LIMIT', 2);
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['sitepulse_hooks'][$hook][] = $callback;
    }
}

if (!function_exists('is_admin')) {
    function is_admin()
    {
        return !empty($GLOBALS['sitepulse_is_admin']);
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $GLOBALS['sitepulse_options'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['sitepulse_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        unset($GLOBALS['sitepulse_options'][$name]);

        return true;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return $text;
    }
}

require_once dirname(__DIR__) . '/includes/debug-notices.php';

function sitepulse_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Ensure the queue display hook is registered.
sitepulse_assert(isset($GLOBALS['sitepulse_hooks']['admin_notices'][0]), 'Queue display hook must be registered.');
sitepulse_assert($GLOBALS['sitepulse_hooks']['admin_notices'][0] === 'sitepulse_display_queued_debug_notices', 'Queue display hook should point to sitepulse_display_queued_debug_notices.');

// Scenario 1: Notices scheduled on the frontend are queued.
$GLOBALS['sitepulse_is_admin'] = false;
sitepulse_schedule_debug_admin_notice('Rotation failed', 'error');
$queued = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);
sitepulse_assert(count($queued) === 1, 'Frontend scheduling should queue a notice.');
sitepulse_assert($queued[0]['message'] === 'Rotation failed', 'Queued message must be preserved.');
sitepulse_assert($queued[0]['level'] === 'error', 'Queued level must be normalized.');

// Duplicate message should not be added twice.
sitepulse_schedule_debug_admin_notice('Rotation failed', 'error');
$queued = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);
sitepulse_assert(count($queued) === 1, 'Duplicate frontend notice should be ignored.');

// Different message should be added.
sitepulse_schedule_debug_admin_notice('Write failure', 'warning');
$queued = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);
sitepulse_assert(count($queued) === 2, 'Second unique frontend notice should be queued.');

// Queue should drop the oldest entry when exceeding the limit.
sitepulse_schedule_debug_admin_notice('Cache saturated', 'info');
$queued = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);
sitepulse_assert(count($queued) === SITEPULSE_DEBUG_NOTICE_QUEUE_LIMIT, 'Queue should respect the configured limit.');
sitepulse_assert($queued[0]['message'] === 'Write failure', 'Queue should retain the most recent entries.');
sitepulse_assert($queued[1]['message'] === 'Cache saturated', 'Newest notice should be kept when trimming the queue.');

// Scenario 2: Visiting admin displays and clears queued notices.
sitepulse_debug_notice_registry(null, true);
$GLOBALS['sitepulse_is_admin'] = true;
ob_start();
sitepulse_display_queued_debug_notices();
$output = ob_get_clean();
$expected_output = '<div class="notice notice-warning" role="alert" aria-live="assertive" aria-atomic="true"><p>Write failure</p></div>'
    . '<div class="notice notice-info" role="status" aria-live="polite" aria-atomic="true"><p>Cache saturated</p></div>';
sitepulse_assert($output === $expected_output, 'Queued notices should render once in admin.');
sitepulse_assert(get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []) === [], 'Queued notices should be cleared after rendering.');

// Scenario 3: Admin scheduling renders immediately without queuing.
$initial_hook_count = count($GLOBALS['sitepulse_hooks']['admin_notices']);
sitepulse_schedule_debug_admin_notice('Immediate notice', 'info');
$after_hook_count = count($GLOBALS['sitepulse_hooks']['admin_notices']);
sitepulse_assert($after_hook_count === $initial_hook_count + 1, 'Admin scheduling should register a rendering callback.');
sitepulse_assert(get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []) === [], 'Admin scheduling should not queue notices.');

$callback = end($GLOBALS['sitepulse_hooks']['admin_notices']);
ob_start();
call_user_func($callback);
$immediate_output = ob_get_clean();
sitepulse_assert(
    $immediate_output === '<div class="notice notice-info" role="status" aria-live="polite" aria-atomic="true"><p>Immediate notice</p></div>',
    'Admin scheduling should render immediately.'
);

echo "All debug notice assertions passed." . PHP_EOL;
