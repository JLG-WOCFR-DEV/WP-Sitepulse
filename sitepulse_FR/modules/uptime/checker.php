<?php
/**
 * SitePulse Uptime HTTP checker.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

function sitepulse_run_uptime_check($agent_id = 'default', $override_args = []) {
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $agent_config = sitepulse_uptime_get_agent($agent_id);

    if (isset($agent_config['active']) && false === (bool) $agent_config['active']) {
        return;
    }

    $default_timeout = defined('SITEPULSE_DEFAULT_UPTIME_TIMEOUT') ? (int) SITEPULSE_DEFAULT_UPTIME_TIMEOUT : 10;
    $timeout_option = get_option(SITEPULSE_OPTION_UPTIME_TIMEOUT, $default_timeout);
    $timeout = $default_timeout;
    $default_method = defined('SITEPULSE_DEFAULT_UPTIME_HTTP_METHOD') ? SITEPULSE_DEFAULT_UPTIME_HTTP_METHOD : 'GET';
    $method_option = get_option(SITEPULSE_OPTION_UPTIME_HTTP_METHOD, $default_method);
    $http_method = function_exists('sitepulse_sanitize_uptime_http_method')
        ? sitepulse_sanitize_uptime_http_method($method_option)
        : (is_string($method_option) && $method_option !== '' ? strtoupper($method_option) : $default_method);
    $headers_option = get_option(SITEPULSE_OPTION_UPTIME_HTTP_HEADERS, []);
    $custom_headers = function_exists('sitepulse_sanitize_uptime_http_headers')
        ? sitepulse_sanitize_uptime_http_headers($headers_option)
        : (is_array($headers_option) ? $headers_option : []);
    $expected_codes_option = get_option(SITEPULSE_OPTION_UPTIME_EXPECTED_CODES, []);
    $expected_codes = function_exists('sitepulse_sanitize_uptime_expected_codes')
        ? sitepulse_sanitize_uptime_expected_codes($expected_codes_option)
        : [];
    $latency_threshold_option = get_option(
        SITEPULSE_OPTION_UPTIME_LATENCY_THRESHOLD,
        defined('SITEPULSE_DEFAULT_UPTIME_LATENCY_THRESHOLD') ? SITEPULSE_DEFAULT_UPTIME_LATENCY_THRESHOLD : 0
    );
    $latency_threshold = function_exists('sitepulse_sanitize_uptime_latency_threshold')
        ? sitepulse_sanitize_uptime_latency_threshold($latency_threshold_option)
        : (is_numeric($latency_threshold_option) ? (float) $latency_threshold_option : 0.0);
    $expected_keyword_option = get_option(SITEPULSE_OPTION_UPTIME_KEYWORD, '');
    $expected_keyword = function_exists('sitepulse_sanitize_uptime_keyword')
        ? sitepulse_sanitize_uptime_keyword($expected_keyword_option)
        : (is_string($expected_keyword_option) ? sanitize_text_field($expected_keyword_option) : '');

    if (is_numeric($timeout_option)) {
        $timeout = (int) $timeout_option;
    }

    if ($timeout < 1) {
        $timeout = $default_timeout;
    }

    $configured_url = get_option(SITEPULSE_OPTION_UPTIME_URL, '');
    $custom_url = '';

    if (is_string($configured_url)) {
        $configured_url = trim($configured_url);

        if ($configured_url !== '') {
            $validated_url = wp_http_validate_url($configured_url);

            if ($validated_url) {
                $custom_url = $validated_url;
            }
        }
    }

    $default_url = home_url();
    $request_url_default = $custom_url !== '' ? $custom_url : $default_url;

    if (isset($agent_config['timeout']) && is_numeric($agent_config['timeout'])) {
        $timeout = max(1, (int) $agent_config['timeout']);
    }

    if (isset($agent_config['method']) && is_string($agent_config['method']) && $agent_config['method'] !== '') {
        $http_method = strtoupper($agent_config['method']);
    }

    if (isset($agent_config['headers']) && is_array($agent_config['headers'])) {
        $custom_headers = wp_parse_args($agent_config['headers'], $custom_headers);
    }

    if (isset($agent_config['expected_codes']) && is_array($agent_config['expected_codes'])) {
        $agent_expected = array_map('intval', $agent_config['expected_codes']);
        $expected_codes = array_values(array_unique(array_merge($expected_codes, $agent_expected)));
    }

    if (isset($agent_config['url']) && is_string($agent_config['url']) && '' !== trim($agent_config['url'])) {
        $candidate_url = wp_http_validate_url($agent_config['url']);
        if ($candidate_url) {
            $request_url_default = $candidate_url;
        }
    }

    $defaults = [
        'timeout'   => $timeout,
        'sslverify' => true,
        'url'       => $request_url_default,
        'method'    => $http_method,
        'headers'   => $custom_headers,
        'agent'     => $agent_id,
    ];

    /**
     * Filtre les arguments passés à la requête de vérification d'uptime.
     *
     * Permet de désactiver la vérification SSL, d'ajuster le timeout ou de pointer
     * vers une URL spécifique pour les environnements de test.
     *
     * @since 1.0
     *
     * @param array $request_args Arguments transmis à wp_remote_request(). Le paramètre
     *                            "url" peut être fourni pour cibler une adresse
     *                            différente.
     */
    $request_args = apply_filters('sitepulse_uptime_request_args', $defaults);

    if (!is_array($request_args)) {
        $request_args = $defaults;
    }

    if (is_array($override_args) && !empty($override_args)) {
        $request_args = array_merge($request_args, $override_args);
    }

    $request_agent = isset($request_args['agent']) ? sitepulse_uptime_normalize_agent_id($request_args['agent']) : $agent_id;
    unset($request_args['agent']);

    $request_url = isset($request_args['url']) ? $request_args['url'] : $defaults['url'];

    if (!is_string($request_url) || $request_url === '') {
        $request_url = $defaults['url'];
    } else {
        $validated_request_url = wp_http_validate_url($request_url);

        if ($validated_request_url) {
            $request_url = $validated_request_url;
        } else {
            $request_url = $defaults['url'];
        }
    }

    unset($request_args['url']);

    if (isset($request_args['expected_codes'])) {
        $expected_codes_candidate = $request_args['expected_codes'];

        if (function_exists('sitepulse_sanitize_uptime_expected_codes')) {
            $expected_codes = sitepulse_sanitize_uptime_expected_codes($expected_codes_candidate);
        }

        unset($request_args['expected_codes']);
    }

    if (isset($request_args['method'])) {
        $method_candidate = $request_args['method'];
        $request_args['method'] = function_exists('sitepulse_sanitize_uptime_http_method')
            ? sitepulse_sanitize_uptime_http_method($method_candidate)
            : (is_string($method_candidate) && $method_candidate !== '' ? strtoupper($method_candidate) : $http_method);
    } else {
        $request_args['method'] = $http_method;
    }

    if (isset($request_args['headers'])) {
        $request_args['headers'] = function_exists('sitepulse_sanitize_uptime_http_headers')
            ? sitepulse_sanitize_uptime_http_headers($request_args['headers'])
            : (is_array($request_args['headers']) ? $request_args['headers'] : []);
    } else {
        $request_args['headers'] = $custom_headers;
    }

    if (empty($request_args['headers'])) {
        unset($request_args['headers']);
    }

    $raw_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);

    if (!is_array($raw_log)) {
        $raw_log = empty($raw_log) ? [] : [$raw_log];
    }

    $log = sitepulse_normalize_uptime_log($raw_log);
    $log = sitepulse_trim_uptime_log($log);
    $timestamp = (int) current_time('timestamp');

    $active_window = sitepulse_uptime_find_active_maintenance_window($request_agent, $timestamp);

    if ($active_window) {
        $entry = [
            'timestamp'         => $timestamp,
            'status'            => 'maintenance',
            'agent'             => $request_agent,
            'maintenance_start' => $active_window['start'],
            'maintenance_end'   => $active_window['end'],
        ];

        if (!empty($active_window['label'])) {
            $entry['maintenance_label'] = $active_window['label'];
        }

        $log[] = $entry;
        $log = sitepulse_trim_uptime_log($log);

        update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
        sitepulse_update_uptime_archive($entry);

        $log_label = isset($entry['maintenance_label']) ? ' - ' . $entry['maintenance_label'] : '';
        $log_message = sprintf(
            'Uptime check skipped for %1$s due to maintenance window (%2$s → %3$s)%4$s.',
            $request_agent,
            gmdate('c', $active_window['start']),
            gmdate('c', $active_window['end']),
            $log_label
        );
        sitepulse_log($log_message, 'INFO');

        $date_format = get_option('date_format', 'Y-m-d');
        $time_format = get_option('time_format', 'H:i');
        $agent_label = isset($agent_config['label']) && is_string($agent_config['label']) && $agent_config['label'] !== ''
            ? $agent_config['label']
            : $request_agent;
        $window_label = isset($entry['maintenance_label']) && $entry['maintenance_label'] !== ''
            ? $entry['maintenance_label']
            : __('Fenêtre de maintenance planifiée', 'sitepulse');
        $formatted_start = date_i18n($date_format . ' ' . $time_format, $active_window['start']);
        $formatted_end = date_i18n($date_format . ' ' . $time_format, $active_window['end']);
        $notice_message = sprintf(
            __('Contrôle d’uptime ignoré pour %1$s : %2$s (%3$s → %4$s). Aucune alerte envoyée pendant la maintenance.', 'sitepulse'),
            $agent_label,
            $window_label,
            $formatted_start,
            $formatted_end
        );

        if (function_exists('sitepulse_schedule_debug_admin_notice')) {
            sitepulse_schedule_debug_admin_notice($notice_message, 'info');
        }

        sitepulse_uptime_record_maintenance_notice($notice_message, $timestamp);

        return;
    }

    $request_start = microtime(true);
    $response = wp_remote_request($request_url, $request_args);
    $request_end = microtime(true);

    $raw_duration = max(0, (float) ($request_end - $request_start));

    $entry = [
        'timestamp' => $timestamp,
        'agent'     => $request_agent,
        'latency'   => round($raw_duration, 4),
    ];
    $ttfb = null;

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        $entry['status'] = 'unknown';

        if (!empty($error_message)) {
            $entry['error'] = $error_message;
        }

        $failure_streak = (int) get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) + 1;
        update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, $failure_streak, false);

        $default_threshold = 3;
        $threshold = (int) apply_filters('sitepulse_uptime_consecutive_failures', $default_threshold, $failure_streak, $response, $request_url, $request_args);
        $threshold = max(1, $threshold);

        $log[] = $entry;

        $level = $failure_streak >= $threshold ? 'ALERT' : 'WARNING';
        $log_message = sprintf('Uptime check: network error (%1$d/%2$d)%3$s', $failure_streak, $threshold, !empty($error_message) ? ' - ' . $error_message : '');
        sitepulse_log($log_message, $level);

        $log = sitepulse_trim_uptime_log($log);

        update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
        sitepulse_update_uptime_archive($entry);

        return;
    }

    if (is_array($response) && isset($response['http_response']) && is_object($response['http_response'])) {
        $http_response = $response['http_response'];

        if (method_exists($http_response, 'get_response_object')) {
            $requests_response = $http_response->get_response_object();

            if (is_object($requests_response) && isset($requests_response->info) && is_array($requests_response->info)) {
                if (isset($requests_response->info['total_time'])) {
                    $total_time = (float) $requests_response->info['total_time'];

                    if ($total_time >= 0) {
                        $entry['latency'] = round($total_time, 4);
                    }
                }

                if (isset($requests_response->info['starttransfer_time'])) {
                    $start_transfer = (float) $requests_response->info['starttransfer_time'];

                    if ($start_transfer >= 0) {
                        $ttfb = $start_transfer;
                    }
                }
            }
        }
    }

    if (null !== $ttfb) {
        $entry['ttfb'] = round($ttfb, 4);
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $is_up = $response_code >= 200 && $response_code < 400;

    if (!empty($expected_codes)) {
        $is_up = in_array((int) $response_code, $expected_codes, true);
    }

    $entry['status'] = $is_up;

    $response_body = wp_remote_retrieve_body($response);
    $body_as_string = is_string($response_body) ? $response_body : '';
    $violation_types = [];
    $violation_messages = [];
    $latency_value = isset($entry['latency']) ? (float) $entry['latency'] : 0.0;

    if ($latency_threshold > 0 && $latency_value > $latency_threshold) {
        $violation_types[] = 'latency';
        $violation_messages[] = sprintf(
            /* translators: 1: measured latency, 2: configured threshold. */
            __('Temps de réponse %.3fs supérieur au seuil de %.3fs.', 'sitepulse'),
            $latency_value,
            $latency_threshold
        );
    }

    if ($expected_keyword !== '') {
        $body_to_search = $body_as_string;

        if ($body_to_search === '' || false === stripos($body_to_search, $expected_keyword)) {
            $violation_types[] = 'keyword';
            $violation_messages[] = sprintf(
                /* translators: %s is the expected keyword. */
                __('Mot-clé attendu introuvable dans la réponse : %s.', 'sitepulse'),
                $expected_keyword
            );
        }
    }

    if (!empty($violation_types)) {
        $is_up = false;
        $entry['status'] = false;
        $entry['violation_types'] = array_values(array_unique(array_map('sanitize_key', $violation_types)));
        $entry['validation_messages'] = $violation_messages;

        if (!isset($entry['error'])) {
            $entry['error'] = implode(' ', $violation_messages);
        }

        if (function_exists('sitepulse_error_alert_send')) {
            $subject = sprintf(
                __('Surveillance de disponibilité : alerte pour %s', 'sitepulse'),
                $request_agent
            );
            $message_lines = array_merge($violation_messages, [
                sprintf(__('Code HTTP : %d', 'sitepulse'), $response_code),
                sprintf(__('URL : %s', 'sitepulse'), $request_url),
            ]);

            sitepulse_error_alert_send(
                'uptime_violation',
                $subject,
                implode("\n", $message_lines),
                'warning',
                [
                    'agent'           => $request_agent,
                    'url'             => $request_url,
                    'response_code'   => $response_code,
                    'latency'         => $latency_value,
                    'latency_threshold' => $latency_threshold,
                    'expected_keyword' => $expected_keyword,
                    'violation_types' => $entry['violation_types'],
                ]
            );
        }
    }

    if (!$is_up) {
        $incident_start = $timestamp;

        if (!empty($log)) {
            for ($i = count($log) - 1; $i >= 0; $i--) {
                if (!isset($log[$i]['status']) || !is_bool($log[$i]['status'])) {
                    continue;
                }

                if (false === $log[$i]['status']) {
                    if (isset($log[$i]['incident_start'])) {
                        $incident_start = (int) $log[$i]['incident_start'];
                    } elseif (isset($log[$i]['timestamp'])) {
                        $incident_start = (int) $log[$i]['timestamp'];
                    }
                }

                break;
            }
        }

        $entry['incident_start'] = $incident_start;

        if (!isset($entry['error'])) {
            $entry['error'] = sprintf('HTTP %d', $response_code);
        }
    }

    $log[] = $entry;

    if ($is_up) {
        if ((int) get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) !== 0) {
            update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0, false);
        }
    } else {
        $failure_streak = (int) get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) + 1;
        update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, $failure_streak, false);
    }

    if (!$is_up) {
        sitepulse_log(sprintf('Uptime check: Down (HTTP %d)', $response_code), 'ALERT');
    } else {
        sitepulse_log('Uptime check: Up');
    }

    $log = sitepulse_trim_uptime_log($log);

    update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
    sitepulse_update_uptime_archive($entry);
}
