<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SITEPULSE_OPTION_DEBUG_NOTICES')) {
    define('SITEPULSE_OPTION_DEBUG_NOTICES', 'sitepulse_debug_notices');
}

if (!defined('SITEPULSE_DEBUG')) {
    define('SITEPULSE_DEBUG', false);
}

/**
 * Tracks notices scheduled during the current request to prevent duplicates.
 *
 * @param string|null $notice_key Unique notice identifier. When null the
 *                                registry state is inspected or reset.
 * @param bool        $mark       Whether to mark (or reset when key is null)
 *                                the notice as scheduled.
 *
 * @return bool True when the notice was already recorded, false otherwise.
 */
function sitepulse_debug_notice_registry($notice_key = null, $mark = false)
{
    static $registry = [];

    if ($notice_key === null) {
        if ($mark) {
            $registry = [];
        }

        return !empty($registry);
    }

    $notice_key = (string) $notice_key;

    if ($mark) {
        $registry[$notice_key] = true;
    }

    return isset($registry[$notice_key]);
}

/**
 * Schedules an admin notice to report SitePulse debug errors.
 *
 * When invoked outside of the admin area the notice is queued for later
 * display. The queue is flushed on the next admin page load to ensure that
 * administrators are notified even when issues happen on the frontend.
 *
 * @param string $message The notice body.
 * @param string $type    The notice type (error, warning, info, success).
 *
 * @return void
 */
function sitepulse_schedule_debug_admin_notice($message, $type = 'error')
{
    if (!SITEPULSE_DEBUG) {
        return;
    }

    $allowed_types = ['error', 'warning', 'info', 'success'];
    $type          = in_array($type, $allowed_types, true) ? $type : 'error';
    $message       = (string) $message;
    $notice_key    = $type . '|' . $message;

    if (sitepulse_debug_notice_registry($notice_key)) {
        return;
    }

    sitepulse_debug_notice_registry($notice_key, true);

    $is_admin_context = function_exists('is_admin') && is_admin();

    if (!$is_admin_context) {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $queued_notices = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);

        if (!is_array($queued_notices)) {
            $queued_notices = [];
        }

        foreach ($queued_notices as $queued_notice) {
            if (!is_array($queued_notice)) {
                continue;
            }

            $queued_message = isset($queued_notice['message']) ? (string) $queued_notice['message'] : '';
            $queued_type    = isset($queued_notice['type']) ? (string) $queued_notice['type'] : 'error';

            if ($queued_message === $message && $queued_type === $type) {
                return;
            }
        }

        $queued_notices[] = [
            'message' => $message,
            'level'   => $type,
        ];

        update_option(SITEPULSE_OPTION_DEBUG_NOTICES, $queued_notices);

        return;
    }

    if (!function_exists('add_action')) {
        return;
    }

    $class = 'notice notice-' . $type;

    add_action('admin_notices', function () use ($message, $class) {
        if (!function_exists('esc_attr') || !function_exists('esc_html')) {
            return;
        }

        printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($message));
    });
}

/**
 * Outputs queued debug notices during admin requests.
 *
 * @return void
 */
function sitepulse_display_queued_debug_notices()
{
    if (!SITEPULSE_DEBUG || !function_exists('get_option')) {
        return;
    }

    $queued_notices = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);

    if (!is_array($queued_notices) || $queued_notices === []) {
        return;
    }

    if (!function_exists('esc_attr') || !function_exists('esc_html')) {
        return;
    }

    $allowed_types = ['error', 'warning', 'info', 'success'];

    foreach ($queued_notices as $notice) {
        if (!is_array($notice) || !isset($notice['message'])) {
            continue;
        }

        $message = (string) $notice['message'];
        $type    = 'error';

        if (isset($notice['level'])) {
            $type = (string) $notice['level'];
        } elseif (isset($notice['type'])) {
            $type = (string) $notice['type'];
        }

        if (!in_array($type, $allowed_types, true)) {
            $type = 'error';
        }

        $class = 'notice notice-' . $type;

        $notice_key = $type . '|' . $message;

        if (sitepulse_debug_notice_registry($notice_key)) {
            continue;
        }

        sitepulse_debug_notice_registry($notice_key, true);

        printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($message));
    }

    if (function_exists('delete_option')) {
        delete_option(SITEPULSE_OPTION_DEBUG_NOTICES);
    } elseif (function_exists('update_option')) {
        update_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);
    }
}

if (function_exists('add_action')) {
    add_action('admin_notices', 'sitepulse_display_queued_debug_notices', 0);
}
