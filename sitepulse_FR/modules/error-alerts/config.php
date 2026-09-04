<?php
/**
 * SitePulse Error Alerts configuration helpers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

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
 * Returns default severity indicators (emoji and color) used for webhook formatting.
 *
 * @return array<string, array<string, string>>
 */
function sitepulse_error_alert_get_severity_indicators() {
    $indicators = [
        'info' => [
            'emoji' => ':information_source:',
            'color' => '#2563EB',
        ],
        'warning' => [
            'emoji' => ':warning:',
            'color' => '#F59E0B',
        ],
        'critical' => [
            'emoji' => ':rotating_light:',
            'color' => '#DC2626',
        ],
    ];

    /**
     * Filters the severity indicators used when formatting webhook payloads.
     *
     * @param array<string, array<string, string>> $indicators Severity indicators keyed by severity slug.
     */
    return (array) apply_filters('sitepulse_error_alert_severity_indicators', $indicators);
}

/**
 * Retrieves a specific severity indicator value.
 *
 * @param string $severity Severity identifier.
 * @param string $key      Indicator key to retrieve (emoji or color).
 * @param string $default  Optional default value.
 * @return string
 */
function sitepulse_error_alert_get_severity_indicator_value($severity, $key, $default = '') {
    $severity = sitepulse_error_alert_normalize_severity($severity);
    $indicators = sitepulse_error_alert_get_severity_indicators();

    if (isset($indicators[$severity][$key]) && is_string($indicators[$severity][$key])) {
        return $indicators[$severity][$key];
    }

    return $default;
}

/**
 * Retrieves the emoji representing the provided severity.
 *
 * @param string $severity Severity identifier.
 * @return string Emoji string.
 */
function sitepulse_error_alert_get_severity_emoji($severity) {
    return sitepulse_error_alert_get_severity_indicator_value($severity, 'emoji', ':warning:');
}

/**
 * Retrieves the hex color associated with the provided severity.
 *
 * @param string $severity Severity identifier.
 * @return string Hex color string (including leading #).
 */
function sitepulse_error_alert_get_severity_color($severity) {
    $color = sitepulse_error_alert_get_severity_indicator_value($severity, 'color', '#F59E0B');

    if (strpos($color, '#') !== 0) {
        $color = '#' . ltrim($color, '#');
    }

    return strtoupper($color);
}
