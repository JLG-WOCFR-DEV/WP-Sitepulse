<?php
/**
 * SitePulse Error Alerts delivery channels.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Determines whether the provided webhook URL targets Slack.
 *
 * @param string $url Webhook URL.
 * @return bool
 */
function sitepulse_error_alert_is_slack_webhook($url) {
    $url = strtolower((string) $url);

    return $url !== '' && strpos($url, 'hooks.slack.com/') !== false;
}

/**
 * Determines whether the provided webhook URL targets Discord.
 *
 * @param string $url Webhook URL.
 * @return bool
 */
function sitepulse_error_alert_is_discord_webhook($url) {
    $url = strtolower((string) $url);

    return $url !== ''
        && (strpos($url, 'discord.com/api/webhooks/') !== false || strpos($url, 'discordapp.com/api/webhooks/') !== false);
}

/**
 * Determines whether the provided webhook URL targets Microsoft Teams.
 *
 * @param string $url Webhook URL.
 * @return bool
 */
function sitepulse_error_alert_is_teams_webhook($url) {
    $url = strtolower((string) $url);

    return $url !== ''
        && (strpos($url, 'outlook.office.com/webhook/') !== false
            || strpos($url, 'office.com/webhook/') !== false
            || strpos($url, 'office365.com/webhook/') !== false);
}

/**
 * Determines whether the provided webhook body uses the default payload structure.
 *
 * @param mixed $body Webhook body payload.
 * @return bool
 */
function sitepulse_error_alert_is_default_webhook_body($body) {
    if (!is_array($body)) {
        return false;
    }

    foreach (['type', 'subject', 'message', 'severity'] as $required_key) {
        if (!array_key_exists($required_key, $body)) {
            return false;
        }
    }

    return true;
}

/**
 * Encodes a payload as JSON while guaranteeing a string result.
 *
 * @param mixed $data Data to encode.
 * @return string JSON encoded string.
 */
function sitepulse_error_alert_encode_json($data) {
    $encoded = wp_json_encode($data);

    if ($encoded === false || $encoded === null) {
        $encoded = json_encode($data);
    }

    if (!is_string($encoded) || $encoded === '') {
        $encoded = '{}';
    }

    return $encoded;
}

/**
 * Builds a Slack-compatible payload from the normalized alert payload.
 *
 * @param array<string, mixed> $payload Normalized alert payload.
 * @return array<string, mixed>
 */
function sitepulse_error_alert_build_slack_payload(array $payload) {
    $subject  = isset($payload['subject']) ? (string) $payload['subject'] : '';
    $message  = isset($payload['message']) ? (string) $payload['message'] : '';
    $site_url = isset($payload['site_url']) ? esc_url_raw((string) $payload['site_url']) : '';
    $site_name = isset($payload['site_name']) ? sanitize_text_field((string) $payload['site_name']) : '';
    $severity = isset($payload['severity']) ? (string) $payload['severity'] : 'warning';
    $emoji    = sitepulse_error_alert_get_severity_emoji($severity);

    $context_elements = [
        [
            'type' => 'mrkdwn',
            'text' => sprintf('*%s*', strtoupper($severity)),
        ],
    ];

    if ($site_url !== '' && $site_name !== '') {
        $context_elements[] = [
            'type' => 'mrkdwn',
            'text' => sprintf('<%s|%s>', $site_url, $site_name),
        ];
    } elseif ($site_name !== '') {
        $context_elements[] = [
            'type' => 'mrkdwn',
            'text' => $site_name,
        ];
    }

    $blocks = [
        [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => sprintf("*%s*\n%s", $subject, $message),
            ],
        ],
        [
            'type'     => 'context',
            'elements' => $context_elements,
        ],
    ];

    $slack_payload = [
        'text'   => trim($emoji . ' ' . $subject),
        'blocks' => $blocks,
    ];

    /**
     * Filters the Slack payload dispatched to webhook endpoints.
     *
     * @param array<string, mixed> $slack_payload Prepared Slack payload.
     * @param array<string, mixed> $payload       Normalized alert payload.
     */
    return (array) apply_filters('sitepulse_error_alert_slack_payload', $slack_payload, $payload);
}

/**
 * Builds a Discord-compatible payload from the normalized alert payload.
 *
 * @param array<string, mixed> $payload Normalized alert payload.
 * @return array<string, mixed>
 */
function sitepulse_error_alert_build_discord_payload(array $payload) {
    $subject  = isset($payload['subject']) ? (string) $payload['subject'] : '';
    $message  = isset($payload['message']) ? (string) $payload['message'] : '';
    $site_url = isset($payload['site_url']) ? esc_url_raw((string) $payload['site_url']) : '';
    $site_name = isset($payload['site_name']) ? sanitize_text_field((string) $payload['site_name']) : '';
    $severity = isset($payload['severity']) ? strtoupper((string) $payload['severity']) : 'WARNING';
    $emoji    = sitepulse_error_alert_get_severity_emoji(isset($payload['severity']) ? $payload['severity'] : 'warning');

    $lines = array_filter([
        trim(sprintf('**%s** %s', $subject, $emoji)),
        $message,
        trim($severity . ($site_name !== '' ? ' • ' . $site_name : '')),
        $site_url,
    ]);

    $discord_payload = [
        'content'          => implode("\n", $lines),
        'allowed_mentions' => ['parse' => []],
    ];

    /**
     * Filters the Discord payload dispatched to webhook endpoints.
     *
     * @param array<string, mixed> $discord_payload Prepared Discord payload.
     * @param array<string, mixed> $payload         Normalized alert payload.
     */
    return (array) apply_filters('sitepulse_error_alert_discord_payload', $discord_payload, $payload);
}

/**
 * Builds a Microsoft Teams compatible payload from the normalized alert payload.
 *
 * @param array<string, mixed> $payload Normalized alert payload.
 * @return array<string, mixed>
 */
function sitepulse_error_alert_build_teams_payload(array $payload) {
    $subject   = isset($payload['subject']) ? (string) $payload['subject'] : '';
    $message   = isset($payload['message']) ? (string) $payload['message'] : '';
    $site_url  = isset($payload['site_url']) ? esc_url_raw((string) $payload['site_url']) : '';
    $site_name = isset($payload['site_name']) ? sanitize_text_field((string) $payload['site_name']) : '';
    $severity  = isset($payload['severity']) ? (string) $payload['severity'] : 'warning';
    $type      = isset($payload['type']) ? (string) $payload['type'] : 'general';
    $color     = ltrim(sitepulse_error_alert_get_severity_color($severity), '#');

    $facts = [
        [
            'name'  => esc_html__('Gravité', 'sitepulse'),
            'value' => ucfirst($severity),
        ],
        [
            'name'  => esc_html__('Type', 'sitepulse'),
            'value' => $type,
        ],
    ];

    $teams_payload = [
        '@type'    => 'MessageCard',
        '@context' => 'http://schema.org/extensions',
        'themeColor' => $color,
        'summary'    => $subject,
        'title'      => $subject,
        'sections'   => [
            [
                'activityTitle'    => $site_name,
                'activitySubtitle' => $site_url,
                'text'             => $message,
                'facts'            => $facts,
            ],
        ],
    ];

    /**
     * Filters the Microsoft Teams payload dispatched to webhook endpoints.
     *
     * @param array<string, mixed> $teams_payload Prepared Teams payload.
     * @param array<string, mixed> $payload       Normalized alert payload.
     */
    return (array) apply_filters('sitepulse_error_alert_teams_payload', $teams_payload, $payload);
}

/**
 * Builds base HTTP request arguments for a webhook endpoint.
 *
 * @param string               $url     Webhook URL.
 * @param array<string, mixed> $payload Normalized alert payload.
 * @param array<string, mixed> $body    Base webhook body prior to provider specific adjustments.
 * @return array<string, mixed> Request arguments.
 */
function sitepulse_error_alert_prepare_webhook_request_args($url, array $payload, array $body) {
    $args = [
        'method'      => 'POST',
        'timeout'     => 5,
        'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
        'data_format' => 'body',
        'body'        => sitepulse_error_alert_encode_json($body),
    ];

    if (sitepulse_error_alert_is_default_webhook_body($body)) {
        if (sitepulse_error_alert_is_slack_webhook($url)) {
            $args['body'] = sitepulse_error_alert_encode_json(sitepulse_error_alert_build_slack_payload($payload));
        } elseif (sitepulse_error_alert_is_teams_webhook($url)) {
            $args['body'] = sitepulse_error_alert_encode_json(sitepulse_error_alert_build_teams_payload($payload));
        } elseif (sitepulse_error_alert_is_discord_webhook($url)) {
            $args['body'] = sitepulse_error_alert_encode_json(sitepulse_error_alert_build_discord_payload($payload));
        }
    }

    /**
     * Filters the prepared webhook request arguments before dispatch.
     *
     * @param array<string, mixed> $args    Prepared request arguments.
     * @param string               $url     Webhook URL.
     * @param array<string, mixed> $payload Normalized alert payload.
     * @param array<string, mixed> $body    Base webhook body.
     */
    return (array) apply_filters('sitepulse_error_alert_prepared_webhook_request_args', $args, $url, $payload, $body);
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

    $results = [];

    foreach ($webhooks as $url) {
        $prepared_args = sitepulse_error_alert_prepare_webhook_request_args($url, $payload, $body);

        /**
         * Filters the request arguments used to call webhook endpoints.
         *
         * @param array  $prepared_args Request arguments.
         * @param string $url           Webhook URL.
         * @param array  $payload       Normalized alert payload.
         */
        $request_args = apply_filters('sitepulse_error_alert_webhook_request_args', $prepared_args, $url, $payload);

        if (!is_array($request_args)) {
            $request_args = $prepared_args;
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
