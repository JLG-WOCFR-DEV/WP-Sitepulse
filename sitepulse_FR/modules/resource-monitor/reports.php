<?php
/**
 * SitePulse Resource Monitor scheduled reports.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ensures a recurring Action Scheduler job generates resource reports.
 *
 * @return void
 */
function sitepulse_resource_monitor_schedule_report_generation() {
    if (!function_exists('as_schedule_recurring_action') || !function_exists('as_has_scheduled_action')) {
        return;
    }

    if (function_exists('sitepulse_is_module_active') && !sitepulse_is_module_active('resource_monitor')) {
        return;
    }

    $hook = SITEPULSE_ACTION_RESOURCE_MONITOR_REPORTS;
    $group = SITEPULSE_AS_GROUP_RESOURCE_MONITOR;
    $default_interval = DAY_IN_SECONDS;

    if (function_exists('apply_filters')) {
        $default_interval = (int) apply_filters('sitepulse_resource_monitor_report_interval', $default_interval);
    }

    $interval = $default_interval > 0 ? $default_interval : DAY_IN_SECONDS;
    $start_delay = function_exists('apply_filters')
        ? (int) apply_filters('sitepulse_resource_monitor_report_start_delay', 10 * MINUTE_IN_SECONDS)
        : 10 * MINUTE_IN_SECONDS;
    $start_timestamp = time() + max(5, $start_delay);

    try {
        if (!as_has_scheduled_action($hook, [], $group)) {
            as_schedule_recurring_action($start_timestamp, $interval, $hook, [], $group);
        }
    } catch (Throwable $throwable) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('Resource monitor report scheduling failed: ' . $throwable->getMessage(), 'WARNING');
        }
    }
}

/**
 * Queues a one-off report generation via Action Scheduler or runs it immediately.
 *
 * @param int $delay_seconds Delay before the action runs.
 * @return bool True if queued, false when executed synchronously.
 */
function sitepulse_resource_monitor_queue_report_generation($delay_seconds = 5) {
    $hook = SITEPULSE_ACTION_RESOURCE_MONITOR_REPORTS;
    $group = SITEPULSE_AS_GROUP_RESOURCE_MONITOR;

    if (!function_exists('as_schedule_single_action') || !function_exists('as_next_scheduled_action')) {
        sitepulse_resource_monitor_run_scheduled_reports();

        return false;
    }

    try {
        $next = as_next_scheduled_action($hook, [], $group);

        if ($next && $next <= (time() + 300)) {
            return true;
        }

        as_schedule_single_action(time() + max(1, (int) $delay_seconds), $hook, [], $group);

        return true;
    } catch (Throwable $throwable) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('Resource monitor report queueing failed: ' . $throwable->getMessage(), 'WARNING');
        }

        sitepulse_resource_monitor_run_scheduled_reports();

        return false;
    }
}

/**
 * Generates and dispatches scheduled resource monitor reports.
 *
 * @return void
 */
function sitepulse_resource_monitor_run_scheduled_reports() {
    if (function_exists('sitepulse_is_module_active') && !sitepulse_is_module_active('resource_monitor')) {
        return;
    }

    $history_query = sitepulse_resource_monitor_get_history([
        'per_page' => 0,
        'order'    => 'ASC',
    ]);

    $entries = isset($history_query['entries']) && is_array($history_query['entries'])
        ? $history_query['entries']
        : [];

    $report = sitepulse_resource_monitor_generate_report_payload($entries);

    $last_report_ttl = function_exists('apply_filters')
        ? (int) apply_filters('sitepulse_resource_monitor_last_report_ttl', DAY_IN_SECONDS)
        : DAY_IN_SECONDS;

    if (function_exists('set_transient')) {
        set_transient(
            SITEPULSE_TRANSIENT_RESOURCE_MONITOR_LAST_REPORT,
            $report,
            $last_report_ttl > 0 ? $last_report_ttl : DAY_IN_SECONDS
        );
    }

    sitepulse_resource_monitor_deliver_report($report);

    if (function_exists('do_action')) {
        do_action('sitepulse_resource_monitor_report_ready', $report);
    }
}

/**
 * Creates a temporary file for email attachments.
 *
 * @param string $contents File contents.
 * @param string $filename Desired filename.
 * @return string|null Path to the temporary file.
 */
function sitepulse_resource_monitor_create_temporary_export($contents, $filename) {
    if (!is_string($contents) || $contents === '') {
        return null;
    }

    if (function_exists('wp_tempnam')) {
        $path = wp_tempnam($filename);
    } else {
        $path = tempnam(sys_get_temp_dir(), 'sitepulse');
    }

    if (!is_string($path) || $path === '') {
        return null;
    }

    $written = file_put_contents($path, $contents);

    if ($written === false) {
        return null;
    }

    return $path;
}

/**
 * Sends the report via email and optional webhooks.
 *
 * @param array<string, mixed> $report Report payload.
 * @return void
 */
function sitepulse_resource_monitor_deliver_report(array $report) {
    $exports = isset($report['exports']) && is_array($report['exports']) ? $report['exports'] : [];
    $csv_export = isset($exports['csv']) ? $exports['csv'] : '';
    $json_export = isset($exports['json']) ? $exports['json'] : '';

    $recipients = [get_option('admin_email')];

    if (function_exists('apply_filters')) {
        $recipients = apply_filters('sitepulse_resource_monitor_report_recipients', $recipients, $report);
    }

    $recipients = array_filter(array_map('sanitize_email', is_array($recipients) ? $recipients : []));

    $attachments = [];

    if (!empty($csv_export)) {
        $csv_path = sitepulse_resource_monitor_create_temporary_export($csv_export, 'sitepulse-resource-report.csv');
        if ($csv_path) {
            $attachments[] = $csv_path;
        }
    }

    if (!empty($json_export)) {
        $json_path = sitepulse_resource_monitor_create_temporary_export($json_export, 'sitepulse-resource-report.json');
        if ($json_path) {
            $attachments[] = $json_path;
        }
    }

    if (!empty($recipients) && function_exists('wp_mail')) {
        $site_name = function_exists('get_bloginfo') ? get_bloginfo('name', 'display') : 'WordPress';
        $subject = sprintf(__('Rapport ressources SitePulse – %s', 'sitepulse'), $site_name);

        $summary_text = isset($report['summary_text']) ? (string) $report['summary_text'] : '';
        $generated_at = isset($report['generated_at']) ? (int) $report['generated_at'] : time();
        $generated_label = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $generated_at);

        $lines = [
            sprintf(__('Rapport généré le %s.', 'sitepulse'), $generated_label),
        ];

        if ($summary_text !== '') {
            $lines[] = $summary_text;
        }

        if (isset($report['metrics']['load_1']['average'])) {
            $lines[] = sprintf(
                __('Charge CPU moyenne (1 min) : %s', 'sitepulse'),
                number_format_i18n((float) $report['metrics']['load_1']['average'], 2)
            );
        }

        if (isset($report['metrics']['memory_percent']['average'])) {
            $lines[] = sprintf(
                __('Mémoire utilisée moyenne : %s %%', 'sitepulse'),
                number_format_i18n((float) $report['metrics']['memory_percent']['average'], 1)
            );
        }

        if (isset($report['metrics']['disk_used']['average'])) {
            $lines[] = sprintf(
                __('Stockage utilisé moyen : %s %%', 'sitepulse'),
                number_format_i18n((float) $report['metrics']['disk_used']['average'], 1)
            );
        }

        $message = implode("\n", $lines);

        wp_mail($recipients, $subject, $message, '', $attachments);
    }

    $webhooks = [];

    if (function_exists('apply_filters')) {
        $webhooks = apply_filters('sitepulse_resource_monitor_report_webhooks', $webhooks, $report);
    }

    if (!empty($webhooks) && function_exists('wp_remote_post') && !empty($json_export)) {
        foreach ((array) $webhooks as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }

            try {
                wp_remote_post($url, [
                    'timeout' => 10,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => $json_export,
                ]);
            } catch (Throwable $throwable) {
                if (function_exists('sitepulse_log')) {
                    sitepulse_log('Resource monitor webhook failed: ' . $throwable->getMessage(), 'WARNING');
                }
            }
        }
    }

    foreach ($attachments as $attachment) {
        if (is_string($attachment) && $attachment !== '' && file_exists($attachment)) {
            @unlink($attachment);
        }
    }
}

/**
 * Handles manual report triggers from the admin UI.
 *
 * @return void
 */
function sitepulse_resource_monitor_handle_report_trigger() {
    if (!function_exists('sitepulse_get_capability') || !current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    check_admin_referer('sitepulse_resource_monitor_trigger_report');

    $queued = sitepulse_resource_monitor_queue_report_generation();

    $status = $queued ? 'queued' : 'executed';

    $redirect = wp_get_referer();
    if (!$redirect) {
        $redirect = admin_url('admin.php?page=sitepulse-resources');
    }

    wp_safe_redirect(add_query_arg('sitepulse_report', $status, $redirect));
    exit;
}
