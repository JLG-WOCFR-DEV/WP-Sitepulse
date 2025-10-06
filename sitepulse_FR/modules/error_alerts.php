<?php
if (!defined('ABSPATH')) {
    exit;
}

$sitepulse_error_alerts_cron_hook = function_exists('sitepulse_get_cron_hook') ? sitepulse_get_cron_hook('error_alerts') : 'sitepulse_error_alerts_cron';
$sitepulse_error_alerts_schedule   = 'sitepulse_error_alerts_five_minutes';

/**
 * Retrieves the interval (in minutes) configured for the alert checks.
 *
 * Uses the shared sitepulse_sanitize_alert_interval() helper to normalize the
 * stored value to one of the supported schedules.
 *
 * @return int
 */
function sitepulse_error_alerts_get_interval_minutes() {
    $stored_value = get_option(SITEPULSE_OPTION_ALERT_INTERVAL, 5);

    return sitepulse_sanitize_alert_interval($stored_value);
}

/**
 * Builds the cron schedule slug based on the configured interval.
 *
 * The optional override is sanitized through sitepulse_sanitize_alert_interval()
 * to ensure a consistent and valid schedule name.
 *
 * @param int|null $minutes Interval override (optional).
 * @return string
 */
function sitepulse_error_alerts_get_schedule_slug($minutes = null) {
    $minutes = $minutes === null
        ? sitepulse_error_alerts_get_interval_minutes()
        : sitepulse_sanitize_alert_interval($minutes);

    return 'sitepulse_error_alerts_' . $minutes . '_minutes';
}

$sitepulse_error_alerts_schedule = sitepulse_error_alerts_get_schedule_slug();

/**
 * Returns the human readable labels for alert channels.
 *
 * @return array<string, string>
 */
function sitepulse_error_alerts_get_channel_labels() {
    return [
        'cpu'       => __('Charge CPU', 'sitepulse'),
        'php_fatal' => __('Erreurs PHP fatales', 'sitepulse'),
    ];
}

/**
 * Returns the list of enabled alert channels.
 *
 * @return string[] List of channel identifiers.
 */
function sitepulse_error_alerts_get_enabled_channels() {
    $stored_channels = get_option(SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS, array_keys(sitepulse_error_alerts_get_channel_labels()));

    if (!is_array($stored_channels)) {
        $stored_channels = array_keys(sitepulse_error_alerts_get_channel_labels());
    }

    $allowed_channels = array_keys(sitepulse_error_alerts_get_channel_labels());
    $normalized       = [];

    foreach ($stored_channels as $channel) {
        if (!is_string($channel)) {
            continue;
        }

        $channel = sanitize_key($channel);

        if ($channel === '' || !in_array($channel, $allowed_channels, true)) {
            continue;
        }

        if (!in_array($channel, $normalized, true)) {
            $normalized[] = $channel;
        }
    }

    return $normalized;
}

/**
 * Determines if a specific alert channel is enabled.
 *
 * @param string $channel Channel identifier.
 * @return bool Whether the channel is enabled.
 */
function sitepulse_error_alerts_is_channel_enabled($channel) {
    if (!is_string($channel) || $channel === '') {
        return false;
    }

    return in_array($channel, sitepulse_error_alerts_get_enabled_channels(), true);
}

/**
 * Returns the configured CPU load threshold for alerting.
 *
 * @return float
 */
function sitepulse_error_alert_get_cpu_threshold() {
    $threshold = get_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 5);
    if (!is_numeric($threshold)) {
        $threshold = 5;
    }

    $threshold = (float) $threshold;
    if ($threshold <= 0) {
        $threshold = 5;
    }

    return $threshold;
}

/**
 * Returns the configured PHP fatal error threshold.
 *
 * @return int
 */
function sitepulse_error_alert_get_php_fatal_threshold() {
    $threshold = get_option(SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD, 1);

    if (!is_numeric($threshold)) {
        $threshold = 1;
    }

    $threshold = (int) $threshold;

    if ($threshold < 1) {
        $threshold = 1;
    }

    return $threshold;
}

/**
 * Attempts to determine the number of CPU cores available.
 *
 * The detection tries several strategies so it keeps working on a wide range
 * of hosting environments, and falls back to a sane default when no reliable
 * information is available.
 *
 * @return int Number of CPU cores (minimum of 1).
 */
function sitepulse_error_alert_get_cpu_core_count() {
    static $cached_core_count = null;

    if ($cached_core_count !== null) {
        return $cached_core_count;
    }

    $core_count = 0;

    // Allow site owners to provide their own value up-front.
    $filtered_initial = apply_filters('sitepulse_error_alert_cpu_core_count', null);
    if (is_numeric($filtered_initial) && (int) $filtered_initial > 0) {
        $core_count = (int) $filtered_initial;
    }

    if ($core_count < 1 && function_exists('shell_exec')) {
        $disabled = explode(',', (string) ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        if (!in_array('shell_exec', $disabled, true)) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if (is_string($nproc)) {
                $nproc = (int) trim($nproc);
                if ($nproc > 0) {
                    $core_count = $nproc;
                }
            }

            if ($core_count < 1) {
                $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
                if (is_string($sysctl)) {
                    $sysctl = (int) trim($sysctl);
                    if ($sysctl > 0) {
                        $core_count = $sysctl;
                    }
                }
            }
        }
    }

    if ($core_count < 1) {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo) && $cpuinfo !== '') {
            if (preg_match_all('/^processor\s*:/m', $cpuinfo, $matches)) {
                $cpuinfo_cores = count($matches[0]);
                if ($cpuinfo_cores > 0) {
                    $core_count = $cpuinfo_cores;
                }
            }
        }
    }

    if ($core_count < 1 && function_exists('getenv')) {
        $env_cores = getenv('NUMBER_OF_PROCESSORS');
        if ($env_cores !== false && is_numeric($env_cores) && (int) $env_cores > 0) {
            $core_count = (int) $env_cores;
        }
    }

    if ($core_count < 1) {
        $core_count = 1;
    }

    $core_count = (int) apply_filters('sitepulse_error_alert_detected_cpu_core_count', $core_count);

    if ($core_count < 1) {
        $core_count = 1;
    }

    $cached_core_count = $core_count;

    return $cached_core_count;
}

/**
 * Returns the throttling window (in seconds) for alert e-mails.
 *
 * @return int
 */
function sitepulse_error_alert_get_cooldown() {
    $cooldown_minutes = get_option(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES, 60);
    if (!is_numeric($cooldown_minutes)) {
        $cooldown_minutes = 60;
    }

    $cooldown_minutes = (int) $cooldown_minutes;
    if ($cooldown_minutes < 1) {
        $cooldown_minutes = 60;
    }

    $minute_in_seconds = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;

    return $cooldown_minutes * $minute_in_seconds;
}

/**
 * Retrieves the list of e-mail recipients for error alerts.
 *
 * @return string[] Sanitized list of e-mail addresses.
 */
function sitepulse_error_alert_get_recipients() {
    $stored_recipients = get_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, []);

    if (!is_array($stored_recipients)) {
        if (is_string($stored_recipients) && $stored_recipients !== '') {
            $stored_recipients = preg_split('/[\r\n,]+/', $stored_recipients);
        } else {
            $stored_recipients = [];
        }
    }

    $admin_email = get_option('admin_email');

    if (is_email($admin_email)) {
        $stored_recipients[] = $admin_email;
    }

    $normalized = [];

    foreach ((array) $stored_recipients as $email) {
        if (!is_string($email)) {
            continue;
        }

        $email = trim($email);
        if ($email === '') {
            continue;
        }

        $sanitized = sanitize_email($email);
        if ($sanitized !== '' && is_email($sanitized)) {
            $normalized[] = $sanitized;
        }
    }

    $normalized = array_values(array_unique($normalized));

    $filtered = apply_filters('sitepulse_error_alert_recipients', $normalized);

    if (!is_array($filtered)) {
        $filtered = is_string($filtered) && $filtered !== '' ? [$filtered] : [];
    }

    $final_recipients = [];

    foreach ($filtered as $email) {
        if (!is_string($email)) {
            continue;
        }

        $email = trim($email);
        if ($email === '') {
            continue;
        }

        $sanitized = sanitize_email($email);
        if ($sanitized !== '' && is_email($sanitized)) {
            $final_recipients[] = $sanitized;
        }
    }

    return array_values(array_unique($final_recipients));
}

/**
 * Returns labels for the available delivery channels.
 *
 * @return array<string, string>
 */
function sitepulse_error_alert_get_delivery_channel_labels() {
    return [
        'email'   => __('E-mail', 'sitepulse'),
        'webhook' => __('Webhook', 'sitepulse'),
    ];
}

/**
 * Normalizes a list of delivery channels to a whitelist.
 *
 * @param mixed $channels Raw channel list.
 * @return array<string> Sanitized channel identifiers.
 */
function sitepulse_error_alert_normalize_delivery_channels($channels) {
    if (is_string($channels)) {
        $channels = [$channels];
    } elseif (!is_array($channels)) {
        $channels = [];
    }

    $allowed   = array_keys(sitepulse_error_alert_get_delivery_channel_labels());
    $sanitized = [];

    foreach ($channels as $channel) {
        if (!is_string($channel)) {
            continue;
        }

        $channel = sanitize_key($channel);

        if ($channel === '' || !in_array($channel, $allowed, true)) {
            continue;
        }

        if (!in_array($channel, $sanitized, true)) {
            $sanitized[] = $channel;
        }
    }

    if (empty($sanitized)) {
        $sanitized[] = 'email';
    }

    return $sanitized;
}

/**
 * Retrieves the enabled delivery channels for alerts.
 *
 * @return array<string>
 */
function sitepulse_error_alert_get_delivery_channels() {
    $stored = get_option(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS, ['email']);

    return sitepulse_error_alert_normalize_delivery_channels($stored);
}

/**
 * Normalizes a list of webhook URLs.
 *
 * @param mixed $webhooks Raw webhook list.
 * @return array<string> Sanitized URLs.
 */
function sitepulse_error_alert_normalize_webhook_urls($webhooks) {
    if (is_string($webhooks)) {
        $webhooks = preg_split('/[\r\n]+/', $webhooks);
    } elseif (!is_array($webhooks)) {
        $webhooks = [];
    }

    $sanitized = [];

    foreach ($webhooks as $url) {
        if (!is_string($url)) {
            continue;
        }

        $url = trim($url);

        if ($url === '') {
            continue;
        }

        $normalized = esc_url_raw($url);

        if ($normalized === '') {
            continue;
        }

        if (function_exists('wp_http_validate_url') && !wp_http_validate_url($normalized)) {
            continue;
        }

        if (!in_array($normalized, $sanitized, true)) {
            $sanitized[] = $normalized;
        }
    }

    return $sanitized;
}

/**
 * Retrieves the configured webhook endpoints.
 *
 * @return array<string> List of webhook URLs.
 */
function sitepulse_error_alert_get_webhook_urls() {
    $stored = get_option(SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS, []);

    $urls = sitepulse_error_alert_normalize_webhook_urls($stored);

    /**
     * Filters the list of webhook endpoints that should receive alert payloads.
     *
     * @param array  $urls    List of webhook URLs.
     * @param string $context Context of the call.
     */
    $filtered = apply_filters('sitepulse_error_alert_webhook_urls', $urls, 'option');

    return sitepulse_error_alert_normalize_webhook_urls($filtered);
}

/**
 * Returns available severity labels.
 *
 * @return array<string, string>
 */
function sitepulse_error_alert_get_severity_labels() {
    return [
        'info'     => __('Information', 'sitepulse'),
        'warning'  => __('Avertissement', 'sitepulse'),
        'critical' => __('Critique', 'sitepulse'),
    ];
}

/**
 * Normalizes the severity identifier.
 *
 * @param mixed $severity Raw severity value.
 * @return string Valid severity key.
 */
function sitepulse_error_alert_normalize_severity($severity) {
    if (!is_string($severity)) {
        $severity = '';
    }

    $severity = sanitize_key($severity);
    $allowed  = array_keys(sitepulse_error_alert_get_severity_labels());

    if (!in_array($severity, $allowed, true)) {
        $severity = 'warning';
    }

    return $severity;
}

/**
 * Retrieves the severities that should trigger notifications.
 *
 * @return array<string>
 */
function sitepulse_error_alert_get_enabled_severities() {
    $stored = get_option(SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES, ['warning', 'critical']);

    if (is_string($stored)) {
        $stored = [$stored];
    } elseif (!is_array($stored)) {
        $stored = [];
    }

    $normalized = [];

    foreach ($stored as $severity) {
        $normalized[] = sitepulse_error_alert_normalize_severity($severity);
    }

    $normalized = array_values(array_unique(array_filter($normalized, 'strlen')));

    if (empty($normalized)) {
        $normalized = ['warning', 'critical'];
    }

    return $normalized;
}

/**
 * Determines whether a given severity level is enabled.
 *
 * @param string $severity Severity identifier.
 * @return bool
 */
function sitepulse_error_alert_is_severity_enabled($severity) {
    $severity = sitepulse_error_alert_normalize_severity($severity);

    return in_array($severity, sitepulse_error_alert_get_enabled_severities(), true);
}

/**
 * Builds a normalized payload for the provided alert content.
 *
 * @param string $type     Alert type identifier.
 * @param string $subject  Alert subject.
 * @param string $message  Alert message body.
 * @param string $severity Severity level.
 * @param array  $extra    Optional additional context.
 * @return array<string, mixed> Normalized payload array.
 */
function sitepulse_error_alert_build_payload($type, $subject, $message, $severity = 'warning', $extra = []) {
    $type     = sanitize_key($type);
    $severity = sitepulse_error_alert_normalize_severity($severity);

    if ($type === '') {
        $type = 'general';
    }

    $subject = sanitize_text_field((string) $subject);
    $message = sanitize_textarea_field((string) $message);

    $site_name = get_bloginfo('name');
    $site_name = is_string($site_name) ? trim($site_name) : '';

    if ($site_name === '') {
        $site_name = home_url('/');
    }

    $payload = [
        'type'      => $type,
        'subject'   => $subject,
        'message'   => $message,
        'severity'  => $severity,
        'site_name' => $site_name,
        'site_url'  => home_url('/'),
        'timestamp' => current_time('mysql', true),
    ];

    if (is_array($extra) && !empty($extra)) {
        $payload = array_merge($payload, $extra);
    }

    /**
     * Filters the normalized payload before it is dispatched.
     *
     * @param array  $payload  Prepared payload.
     * @param string $type     Alert type.
     * @param string $severity Severity key.
     * @param array  $extra    Extra context provided to the builder.
     */
    $filtered = apply_filters('sitepulse_error_alert_payload', $payload, $type, $severity, $extra);

    if (!is_array($filtered)) {
        return $payload;
    }

    foreach (['type', 'subject', 'message', 'severity'] as $required_key) {
        if (!isset($filtered[$required_key])) {
            $filtered[$required_key] = $payload[$required_key];
        }
    }

    $filtered['type'] = sanitize_key((string) $filtered['type']);
    if ($filtered['type'] === '') {
        $filtered['type'] = $payload['type'];
    }

    $filtered['subject']  = sanitize_text_field((string) $filtered['subject']);
    $filtered['message']  = sanitize_textarea_field((string) $filtered['message']);
    $filtered['severity'] = sitepulse_error_alert_normalize_severity($filtered['severity']);

    if (!isset($filtered['timestamp'])) {
        $filtered['timestamp'] = $payload['timestamp'];
    }

    if (!isset($filtered['site_name'])) {
        $filtered['site_name'] = $payload['site_name'];
    }

    if (!isset($filtered['site_url'])) {
        $filtered['site_url'] = $payload['site_url'];
    }

    return $filtered;
}

/**
 * Dispatches the prepared payload via e-mail.
 *
 * @param array<string, mixed> $payload Normalized payload array.
 * @return bool True on success.
 */
function sitepulse_error_alert_dispatch_email($payload) {
    $recipients = sitepulse_error_alert_get_recipients();

    if (empty($recipients)) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log("Alert '{$payload['type']}' skipped e-mail dispatch: no recipients.", 'ERROR');
        }

        return false;
    }

    /**
     * Filters the e-mail payload before sending.
     *
     * @param array  $payload    Prepared payload.
     * @param array  $recipients List of recipients.
     */
    $email_payload = apply_filters('sitepulse_error_alert_email_payload', $payload, $recipients);

    if (!is_array($email_payload)) {
        $email_payload = $payload;
    }

    $subject = isset($email_payload['subject']) ? (string) $email_payload['subject'] : $payload['subject'];
    $message = isset($email_payload['message']) ? (string) $email_payload['message'] : $payload['message'];

    $sent = wp_mail($recipients, $subject, $message);

    if (function_exists('sitepulse_log')) {
        if ($sent) {
            sitepulse_log("Alert '{$payload['type']}' e-mail dispatched to " . count($recipients) . ' recipients.');
        } else {
            sitepulse_log("Alert '{$payload['type']}' e-mail failed to send.", 'ERROR');
        }
    }

    return (bool) $sent;
}

/**
 * Dispatches the prepared payload to webhook endpoints.
 *
 * @param array<string, mixed> $payload Normalized payload array.
 * @return array<string, bool> Map of URL => success state.
 */
function sitepulse_error_alert_dispatch_webhooks($payload) {
    $webhooks = sitepulse_error_alert_get_webhook_urls();

    if (empty($webhooks)) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log("Alert '{$payload['type']}' skipped webhook dispatch: no endpoints configured.", 'WARNING');
        }

        return [];
    }

    $body = [
        'type'      => $payload['type'],
        'subject'   => $payload['subject'],
        'message'   => $payload['message'],
        'severity'  => $payload['severity'],
        'site_name' => isset($payload['site_name']) ? $payload['site_name'] : '',
        'site_url'  => isset($payload['site_url']) ? $payload['site_url'] : home_url('/'),
        'timestamp' => isset($payload['timestamp']) ? $payload['timestamp'] : current_time('mysql', true),
    ];

    /**
     * Filters the webhook payload body before encoding to JSON.
     *
     * @param array $body    Default payload body.
     * @param array $payload Normalized alert payload.
     */
    $body = apply_filters('sitepulse_error_alert_webhook_body', $body, $payload);

    if (!is_array($body)) {
        $body = [
            'type'     => $payload['type'],
            'subject'  => $payload['subject'],
            'message'  => $payload['message'],
            'severity' => $payload['severity'],
        ];
    }

    $encoded_body = wp_json_encode($body);

    $results = [];

    foreach ($webhooks as $url) {
        $args = [
            'method'      => 'POST',
            'timeout'     => 5,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => $encoded_body,
            'data_format' => 'body',
        ];

        /**
         * Filters the request arguments used to call webhook endpoints.
         *
         * @param array  $args     Request arguments.
         * @param string $url      Webhook URL.
         * @param array  $payload  Normalized alert payload.
         */
        $request_args = apply_filters('sitepulse_error_alert_webhook_request_args', $args, $url, $payload);

        if (!is_array($request_args)) {
            $request_args = $args;
        }

        $response = wp_remote_post($url, $request_args);

        $success = false;

        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            $success = $code >= 200 && $code < 300;
        }

        $results[$url] = $success;

        if (function_exists('sitepulse_log')) {
            if ($success) {
                sitepulse_log("Alert '{$payload['type']}' webhook delivered to {$url}.");
            } else {
                $error_message = is_wp_error($response) ? $response->get_error_message() : ('HTTP ' . wp_remote_retrieve_response_code($response));
                sitepulse_log("Alert '{$payload['type']}' webhook failed for {$url}: {$error_message}", 'ERROR');
            }
        }
    }

    return $results;
}

/**
 * Attempts to send an alert message while respecting the cooldown lock.
 *
 * @param string               $type     Unique identifier of the alert type.
 * @param string               $subject  Mail subject.
 * @param string               $message  Mail body.
 * @param string               $severity Severity associated with the alert.
 * @param array<string, mixed> $extra    Optional extra payload data.
 * @return bool True if at least one channel succeeded, false otherwise.
 */
function sitepulse_error_alert_send($type, $subject, $message, $severity = 'warning', $extra = []) {
    $severity = sitepulse_error_alert_normalize_severity($severity);

    if (!sitepulse_error_alert_is_severity_enabled($severity)) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log("Alert '$type' skipped because severity '{$severity}' is disabled.", 'WARNING');
        }

        return false;
    }

    $lock_key = SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . sanitize_key($type) . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX;

    if (false !== get_transient($lock_key)) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log("Alert '$type' skipped due to active cooldown.");
        }
        return false;
    }

    $payload = sitepulse_error_alert_build_payload($type, $subject, $message, $severity, $extra);

    $channels = sitepulse_error_alert_get_delivery_channels();
    $channels = apply_filters('sitepulse_error_alert_delivery_channels', $channels, $payload, $type, $severity, $extra);
    $channels = sitepulse_error_alert_normalize_delivery_channels($channels);

    if (empty($channels)) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log("Alert '{$payload['type']}' skipped because no delivery channel is enabled.", 'WARNING');
        }

        return false;
    }

    $results = [
        'email'   => null,
        'webhook' => [],
    ];
    $success = false;

    if (in_array('email', $channels, true)) {
        $results['email'] = sitepulse_error_alert_dispatch_email($payload);
        $success = $success || $results['email'];
    }

    if (in_array('webhook', $channels, true)) {
        $results['webhook'] = sitepulse_error_alert_dispatch_webhooks($payload);
        if (!$success) {
            $success = !empty(array_filter($results['webhook']));
        }
    }

    /**
     * Fires after an alert payload has been dispatched to all channels.
     *
     * @param array  $payload Normalized payload array.
     * @param array  $results Map of channel => result information.
     * @param string $type    Alert type.
     * @param string $severity Alert severity.
     * @param array  $channels Channels that were attempted.
     */
    do_action('sitepulse_error_alert_dispatched', $payload, $results, $type, $severity, $channels);

    if ($success) {
        set_transient($lock_key, time(), sitepulse_error_alert_get_cooldown());

        if (function_exists('sitepulse_log')) {
            $labels = sitepulse_error_alert_get_delivery_channel_labels();
            $label_list = array_map(static function ($channel) use ($labels) {
                return isset($labels[$channel]) ? $labels[$channel] : $channel;
            }, $channels);

            sitepulse_log("Alert '{$payload['type']}' dispatched via " . implode(', ', $label_list) . ' and cooldown applied.');
        }
    } elseif (function_exists('sitepulse_log')) {
        sitepulse_log("Alert '{$payload['type']}' failed to dispatch on all channels.", 'ERROR');
    }

    return $success;
}

/**
 * Sends a test alert message without applying cooldown locks.
 *
 * @param string $channel Delivery channel to test (email, webhook, all).
 * @return true|\WP_Error True on success, WP_Error on failure.
 */
function sitepulse_error_alerts_send_test_message($channel = 'email') {
    $channel = is_string($channel) ? sanitize_key($channel) : '';

    $available_channels = sitepulse_error_alert_get_delivery_channels();
    $channels_to_test   = [];

    if ($channel === '' || $channel === 'all') {
        $channels_to_test = $available_channels;
    } else {
        $channels_to_test = sitepulse_error_alert_normalize_delivery_channels([$channel]);
    }

    if (empty($channels_to_test)) {
        return new WP_Error('sitepulse_no_delivery_channels', __('Aucun canal de diffusion n’est actif.', 'sitepulse'));
    }

    $raw_site_name = get_bloginfo('name');
    $site_name     = trim(wp_strip_all_tags((string) $raw_site_name));

    if ($site_name === '') {
        $site_name = home_url('/');
    }

    $channel_labels = sitepulse_error_alerts_get_channel_labels();
    $enabled        = sitepulse_error_alerts_get_enabled_channels();

    $enabled_labels = [];

    foreach ($enabled as $channel_key) {
        if (isset($channel_labels[$channel_key])) {
            $enabled_labels[] = $channel_labels[$channel_key];
        }
    }

    if (empty($enabled_labels)) {
        $enabled_labels[] = __('aucun canal actif', 'sitepulse');
    }

    /* translators: %s: Site title. */
    $subject = sprintf(__('SitePulse : test de notification pour %s', 'sitepulse'), $site_name);
    $subject = sanitize_text_field($subject);

    /* translators: 1: Site title. 2: Comma-separated list of enabled alert channels. */
    $message = sprintf(
        esc_html__('Ce message confirme la configuration des alertes SitePulse pour %1$s. Canaux d’alerte actifs : %2$s.', 'sitepulse'),
        $site_name,
        implode(', ', $enabled_labels)
    );

    $message = sanitize_textarea_field($message);

    $payload = sitepulse_error_alert_build_payload('test_alert', $subject, $message, 'info', [
        'test_channels' => $channels_to_test,
    ]);

    $has_success = false;

    foreach ($channels_to_test as $channel_key) {
        if ($channel_key === 'email') {
            $recipients = sitepulse_error_alert_get_recipients();

            if (empty($recipients)) {
                return new WP_Error('sitepulse_no_alert_recipients', __('Aucun destinataire valide pour les alertes.', 'sitepulse'));
            }

            $result = sitepulse_error_alert_dispatch_email($payload);
            $has_success = $has_success || $result;
        } elseif ($channel_key === 'webhook') {
            $webhooks = sitepulse_error_alert_get_webhook_urls();

            if (empty($webhooks)) {
                return new WP_Error('sitepulse_no_webhooks', __('Aucune URL de webhook configurée.', 'sitepulse'));
            }

            $results = sitepulse_error_alert_dispatch_webhooks($payload);
            if (!$has_success) {
                $has_success = !empty(array_filter($results));
            }
        } else {
            /**
             * Allows third-party integrations to handle custom test channels.
             *
             * @param string $channel_key Channel identifier.
             * @param array  $payload     Prepared payload.
             */
            do_action('sitepulse_error_alert_test_channel', $channel_key, $payload);
        }
    }

    if (!$has_success) {
        return new WP_Error('sitepulse_test_channel_failed', __('Le test n’a pas pu être envoyé via le canal sélectionné.', 'sitepulse'));
    }

    if (function_exists('sitepulse_log')) {
        sitepulse_log('Test de notification SitePulse déclenché.', 'INFO');
    }

    return true;
}

/**
 * Registers the cron schedule used by the error alerts module.
 *
 * @param array $schedules Existing cron schedules.
 *
 * @return array Modified cron schedules.
 */
function sitepulse_error_alerts_register_cron_schedule($schedules) {
    global $sitepulse_error_alerts_schedule;

    $interval_minutes = sitepulse_error_alerts_get_interval_minutes();
    $schedule_slug    = sitepulse_error_alerts_get_schedule_slug($interval_minutes);
    $sitepulse_error_alerts_schedule = $schedule_slug;

    if (!isset($schedules[$schedule_slug])) {
        $minute_in_seconds = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $default_interval  = $interval_minutes * $minute_in_seconds;
        $allowed_minutes   = function_exists('sitepulse_get_alert_interval_choices') ? sitepulse_get_alert_interval_choices('cron') : [5];
        $minimum_minutes   = min($allowed_minutes);
        $minimum_interval  = max(1, $minimum_minutes) * $minute_in_seconds;
        $interval          = (int) apply_filters('sitepulse_error_alerts_cron_interval_seconds', $default_interval);

        if ($interval < $minimum_interval) {
            $interval = $minimum_interval;
        }

        $schedules[$schedule_slug] = [
            'interval' => $interval,
            'display'  => sprintf(__('SitePulse Error Alerts (Every %d Minutes)', 'sitepulse'), $interval_minutes),
        ];
    }

    return $schedules;
}

/**
 * Triggers all error alert checks when the cron event runs.
 *
 * @return void
 */
function sitepulse_error_alerts_run_checks() {
    sitepulse_error_alerts_check_cpu_load();
    sitepulse_error_alerts_check_debug_log();
}

/**
 * Evaluates the server load and sends an alert when the threshold is exceeded.
 *
 * @return void
 */
function sitepulse_error_alerts_check_cpu_load() {
    if (!sitepulse_error_alerts_is_channel_enabled('cpu')) {
        return;
    }

    if (!function_exists('sys_getloadavg')) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('sys_getloadavg is unavailable; CPU alert skipped.', 'WARNING');
        }

        return;
    }

    $load = sys_getloadavg();
    if (has_filter('sitepulse_error_alerts_cpu_load')) {
        $load = apply_filters('sitepulse_error_alerts_cpu_load', $load);
    }

    if (!is_array($load) || !isset($load[0])) {
        return;
    }

    $threshold = sitepulse_error_alert_get_cpu_threshold();
    $core_count = sitepulse_error_alert_get_cpu_core_count();
    $core_count = max(1, (int) $core_count);

    $normalized_load   = (float) $load[0] / $core_count;
    $total_threshold   = $threshold * $core_count;

    if ((float) $load[0] > $total_threshold) {
        $raw_site_name = get_bloginfo('name');
        $site_name     = trim(wp_strip_all_tags((string) $raw_site_name));

        /* translators: %s: Site title. */
        $subject = sprintf(
            __('SitePulse Alert: High Server Load on %s', 'sitepulse'),
            $site_name
        );

        $subject = sanitize_text_field($subject);

        /*
         * translators:
         * %1$s: Site title.
         * %2$s: Current server load.
         * %3$d: Detected CPU cores.
         * %4$s: Total load threshold.
         * %5$s: Load per core.
         * %6$s: Threshold per core.
         */
        $message = sprintf(
            esc_html__('Current server load on %1$s: %2$s (detected cores: %3$d, total threshold: %4$s, load per core: %5$s, threshold per core: %6$s)', 'sitepulse'),
            $site_name,
            number_format_i18n((float) $load[0], 2),
            $core_count,
            number_format_i18n($total_threshold, 2),
            number_format_i18n($normalized_load, 2),
            number_format_i18n($threshold, 2)
        );

        $message = sanitize_textarea_field($message);

        sitepulse_error_alert_send('cpu', $subject, $message, 'warning', [
            'cpu_load'      => (float) $load[0],
            'cpu_threshold' => $total_threshold,
            'cpu_cores'     => $core_count,
        ]);
    }
}

/**
 * Scans the WordPress debug log to detect fatal errors.
 *
 * @return void
 */
function sitepulse_error_alerts_check_debug_log() {
    $fatal_threshold = sitepulse_error_alert_get_php_fatal_threshold();
    $channel_enabled = sitepulse_error_alerts_is_channel_enabled('php_fatal');
    $fatal_count     = 0;

    if (!function_exists('sitepulse_get_wp_debug_log_path')) {
        return;
    }

    $log_file = sitepulse_get_wp_debug_log_path();

    if ($log_file === null) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log('WP_DEBUG_LOG est désactivé; analyse du journal ignorée.', 'NOTICE');
        }

        return;
    }

    if (!file_exists($log_file)) {
        return;
    }

    if (!is_readable($log_file)) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log(sprintf('Impossible de lire %s pour l’analyse des erreurs.', $log_file), 'ERROR');
        }

        return;
    }

    $pointer_data = get_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, []);

    if (!is_array($pointer_data)) {
        $pointer_data = [];
    }

    $stored_offset = isset($pointer_data['offset']) ? (int) $pointer_data['offset'] : 0;
    $stored_inode  = isset($pointer_data['inode']) ? (int) $pointer_data['inode'] : null;

    $inode     = function_exists('fileinode') ? @fileinode($log_file) : null;
    $file_size = @filesize($log_file);

    if ($file_size === false) {
        return;
    }

    $offset           = max(0, $stored_offset);
    $offset_adjusted  = false;
    $truncate_partial = false;

    if (is_int($stored_inode) && is_int($inode) && $inode !== $stored_inode) {
        $offset          = 0;
        $offset_adjusted = true;
    }

    if ($offset > $file_size) {
        $offset          = 0;
        $offset_adjusted = true;
    }

    $max_scan_bytes = (int) apply_filters('sitepulse_error_alerts_max_log_scan_bytes', 131072);

    if ($offset === 0 && $file_size > $max_scan_bytes && $max_scan_bytes > 0) {
        $offset          = $file_size - $max_scan_bytes;
        $offset_adjusted = true;
        $truncate_partial = true;
    }

    $handle = fopen($log_file, 'rb');

    if (false === $handle) {
        if (function_exists('sitepulse_log')) {
            sitepulse_log(sprintf('Impossible d’ouvrir %s pour lecture.', $log_file), 'ERROR');
        }

        return;
    }

    if ($offset > 0) {
        fseek($handle, $offset);
    }

    $bytes_to_read = $file_size - $offset;

    if ($max_scan_bytes > 0) {
        $bytes_to_read = min($bytes_to_read, $max_scan_bytes);
    }

    $log_contents = $bytes_to_read > 0 ? stream_get_contents($handle, $bytes_to_read) : '';
    $new_offset   = ftell($handle);

    fclose($handle);

    if ($new_offset === false) {
        $new_offset = $offset + strlen((string) $log_contents);
    }

    $new_pointer_data = [
        'offset'     => (int) $new_offset,
        'inode'      => is_int($inode) ? $inode : null,
        'updated_at' => time(),
    ];

    if (!is_string($log_contents) || $log_contents === '') {
        update_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, $new_pointer_data, false);

        return;
    }

    $log_lines = preg_split('/\r\n|\r|\n/', $log_contents);

    if (!empty($log_lines) && end($log_lines) === '') {
        array_pop($log_lines);
    }

    if (!empty($log_lines) && (($offset_adjusted && $offset > 0) || $truncate_partial)) {
        array_shift($log_lines);
    }

    foreach ($log_lines as $log_line) {
        $has_fatal_error = false;

        if (function_exists('sitepulse_log_line_contains_fatal_error')) {
            $has_fatal_error = sitepulse_log_line_contains_fatal_error($log_line);
        } elseif (stripos($log_line, 'PHP Fatal error') !== false) {
            $has_fatal_error = true;
        }

        if ($has_fatal_error) {
            $fatal_count++;

            if (!$channel_enabled) {
                continue;
            }

            if ($fatal_count < $fatal_threshold) {
                continue;
            }

            $raw_site_name = get_bloginfo('name');
            $site_name     = trim(wp_strip_all_tags((string) $raw_site_name));

            $log_file_for_message = '';

            if (is_string($log_file)) {
                $normalized_log_file = function_exists('wp_normalize_path')
                    ? wp_normalize_path($log_file)
                    : str_replace('\\', '/', $log_file);

                $log_file_for_message = sanitize_textarea_field($normalized_log_file);
            }

            /* translators: %s: Site title. */
            $subject = sprintf(
                __('SitePulse Alert: Fatal Error Detected on %s', 'sitepulse'),
                $site_name
            );

            $subject = sanitize_text_field($subject);

            /* translators: 1: Log file path. 2: Site title. 3: Number of fatal errors detected. */
            $message = sprintf(
                esc_html__('Au moins %3$d nouvelles erreurs fatales ont été détectées dans %1$s pour %2$s. Consultez ce fichier pour plus de détails.', 'sitepulse'),
                $log_file_for_message,
                $site_name,
                (int) $fatal_count
            );

            $message = sanitize_textarea_field($message);

            sitepulse_error_alert_send('php_fatal', $subject, $message, 'critical', [
                'fatal_count' => (int) $fatal_count,
                'log_file'    => $log_file_for_message,
            ]);
            break;
        }
    }

    update_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, $new_pointer_data, false);
}

/**
 * Handles rescheduling when the alert interval option is updated.
 *
 * @param mixed            $old_value Previous value.
 * @param mixed            $value     New value.
 * @param string|int|null  $option    Option name. Unused.
 * @return void
 */
function sitepulse_error_alerts_on_interval_update($old_value, $value, $option = null) {
    global $sitepulse_error_alerts_cron_hook, $sitepulse_error_alerts_schedule;

    if (empty($sitepulse_error_alerts_cron_hook)) {
        return;
    }

    $sitepulse_error_alerts_schedule = sitepulse_error_alerts_get_schedule_slug($value);

    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook($sitepulse_error_alerts_cron_hook);
    } else {
        $timestamp = wp_next_scheduled($sitepulse_error_alerts_cron_hook);

        while ($timestamp) {
            wp_unschedule_event($timestamp, $sitepulse_error_alerts_cron_hook);
            $timestamp = wp_next_scheduled($sitepulse_error_alerts_cron_hook);
        }
    }

    sitepulse_error_alerts_schedule_cron_hook();
}

/**
 * Ensures the error alert cron hook is scheduled and reports failures.
 *
 * @return void
 */
function sitepulse_error_alerts_schedule_cron_hook() {
    global $sitepulse_error_alerts_cron_hook, $sitepulse_error_alerts_schedule;

    if (empty($sitepulse_error_alerts_cron_hook)) {
        return;
    }

    if (!wp_next_scheduled($sitepulse_error_alerts_cron_hook)) {
        $scheduled = wp_schedule_event(time(), $sitepulse_error_alerts_schedule, $sitepulse_error_alerts_cron_hook);

        if (false === $scheduled && function_exists('sitepulse_log')) {
            sitepulse_log(sprintf('Unable to schedule error alert cron hook: %s', $sitepulse_error_alerts_cron_hook), 'ERROR');
        }
    }

    if (!wp_next_scheduled($sitepulse_error_alerts_cron_hook)) {
        sitepulse_register_cron_warning(
            'error_alerts',
            __('SitePulse n’a pas pu programmer les alertes d’erreurs. Vérifiez la configuration de WP-Cron.', 'sitepulse')
        );
    } else {
        sitepulse_clear_cron_warning('error_alerts');
    }
}

/**
 * Initializes the cron schedule during WordPress bootstrap.
 *
 * @return void
 */
function sitepulse_error_alerts_ensure_cron() {
    global $sitepulse_error_alerts_schedule;

    $sitepulse_error_alerts_schedule = sitepulse_error_alerts_get_schedule_slug();

    sitepulse_error_alerts_schedule_cron_hook();
}

if (!empty($sitepulse_error_alerts_cron_hook)) {
    add_filter('cron_schedules', 'sitepulse_error_alerts_register_cron_schedule');

    add_action('init', 'sitepulse_error_alerts_ensure_cron');

    add_action($sitepulse_error_alerts_cron_hook, 'sitepulse_error_alerts_run_checks');
    add_action('update_option_' . SITEPULSE_OPTION_ALERT_INTERVAL, 'sitepulse_error_alerts_on_interval_update', 10, 3);
}

/**
 * Handles the admin-post request triggered from the settings screen.
 *
 * @return void
 */
function sitepulse_error_alerts_handle_test_admin_post() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse'));
    }

    $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_ALERT_TEST)) {
        wp_die(esc_html__('Échec de la vérification de sécurité pour l’envoi de test.', 'sitepulse'));
    }

    $channel = isset($_REQUEST['channel']) ? sanitize_key(wp_unslash($_REQUEST['channel'])) : 'email';

    $result = sitepulse_error_alerts_send_test_message($channel);
    $status = 'success';

    if (is_wp_error($result)) {
        switch ($result->get_error_code()) {
            case 'sitepulse_no_alert_recipients':
                $status = 'no_recipients';
                break;
            case 'sitepulse_no_webhooks':
                $status = 'no_webhooks';
                break;
            case 'sitepulse_no_delivery_channels':
                $status = 'no_channels';
                break;
            default:
                $status = 'error';
        }
    }

    $redirect_url = add_query_arg(
        [
            'sitepulse_alert_test'     => $status,
            'sitepulse_alert_channel'  => $channel,
        ],
        admin_url('admin.php?page=sitepulse-settings#sitepulse-section-alerts')
    );

    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_sitepulse_send_alert_test', 'sitepulse_error_alerts_handle_test_admin_post');

/**
 * Handles AJAX test requests.
 *
 * @return void
 */
function sitepulse_error_alerts_handle_ajax_test() {
    check_ajax_referer(SITEPULSE_NONCE_ACTION_ALERT_TEST, 'nonce');

    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error([
            'message' => esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse'),
        ], 403);
    }

    $channel = isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : 'email';

    $result = sitepulse_error_alerts_send_test_message($channel);

    if (is_wp_error($result)) {
        switch ($result->get_error_code()) {
            case 'sitepulse_no_alert_recipients':
                $status_code = 400;
                break;
            case 'sitepulse_no_webhooks':
            case 'sitepulse_no_delivery_channels':
                $status_code = 400;
                break;
            default:
                $status_code = 500;
        }

        wp_send_json_error([
            'message' => esc_html($result->get_error_message()),
        ], $status_code);
    }

    $success_message = $channel === 'webhook'
        ? esc_html__('Webhook de test déclenché avec succès.', 'sitepulse')
        : esc_html__('E-mail de test envoyé.', 'sitepulse');

    wp_send_json_success([
        'message' => $success_message,
    ]);
}
add_action('wp_ajax_sitepulse_send_alert_test', 'sitepulse_error_alerts_handle_ajax_test');

/**
 * Registers the REST API endpoint for sending test alerts.
 *
 * @return void
 */
function sitepulse_error_alerts_register_rest_routes() {
    register_rest_route(
        'sitepulse/v1',
        '/alerts/test',
        [
            'methods'             => 'POST',
            'callback'            => 'sitepulse_error_alerts_handle_rest_test',
            'permission_callback' => 'sitepulse_error_alerts_rest_permissions',
        ]
    );
}
add_action('rest_api_init', 'sitepulse_error_alerts_register_rest_routes');

/**
 * Permission callback for the REST endpoint.
 *
 * @return bool
 */
function sitepulse_error_alerts_rest_permissions() {
    return current_user_can(sitepulse_get_capability());
}

/**
 * Handles REST API test alert requests.
 *
 * @param \WP_REST_Request $request The REST request instance.
 * @return \WP_REST_Response|\WP_Error
 */
function sitepulse_error_alerts_handle_rest_test($request) {
    $nonce = $request->get_param('_wpnonce');

    if ($nonce && !wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_ALERT_TEST)) {
        return new WP_Error('sitepulse_invalid_nonce', __('Échec de la vérification de sécurité pour l’envoi de test.', 'sitepulse'), ['status' => 403]);
    }

    $channel = $request->get_param('channel');
    $channel = is_string($channel) ? sanitize_key($channel) : 'email';

    $result = sitepulse_error_alerts_send_test_message($channel);

    if (is_wp_error($result)) {
        switch ($result->get_error_code()) {
            case 'sitepulse_no_alert_recipients':
                $status = 400;
                break;
            case 'sitepulse_no_webhooks':
            case 'sitepulse_no_delivery_channels':
                $status = 400;
                break;
            default:
                $status = 500;
        }

        return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => $status]);
    }

    $message = $channel === 'webhook'
        ? esc_html__('Webhook de test déclenché avec succès.', 'sitepulse')
        : esc_html__('E-mail de test envoyé.', 'sitepulse');

    return rest_ensure_response([
        'success' => true,
        'message' => $message,
    ]);
}
