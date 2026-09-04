<?php
/**
 * SitePulse Speed Analyzer cron and queue worker.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Attempts to acquire the automation processing lock.
 *
 * @param int $ttl Lock duration in seconds.
 *
 * @return bool
 */
function sitepulse_speed_analyzer_acquire_lock($ttl = 300) {
    if (!function_exists('get_transient') || !function_exists('set_transient')) {
        return true;
    }

    if (false !== get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_LOCK)) {
        return false;
    }

    set_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_LOCK, time(), max(1, (int) $ttl));

    return true;
}

/**
 * Releases the automation processing lock.
 *
 * @return void
 */
function sitepulse_speed_analyzer_release_lock() {
    if (function_exists('delete_transient')) {
        delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_LOCK);
    }
}

/**
 * Unschedules existing automation events.
 *
 * @return void
 */
function sitepulse_speed_analyzer_unschedule_events() {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
        return;
    }

    $hook = sitepulse_speed_analyzer_get_cron_hook();

    $timestamp = wp_next_scheduled($hook);

    while ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
        $timestamp = wp_next_scheduled($hook);
    }

    $queue_hook = sitepulse_speed_analyzer_get_queue_hook();
    $timestamp = wp_next_scheduled($queue_hook);

    while ($timestamp) {
        wp_unschedule_event($timestamp, $queue_hook);
        $timestamp = wp_next_scheduled($queue_hook);
    }
}

/**
 * Schedules the automation cron event when necessary.
 *
 * @param bool $force Whether to force rescheduling.
 *
 * @return void
 */
function sitepulse_speed_analyzer_bootstrap_cron($force = false) {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }

    $settings = sitepulse_speed_analyzer_get_automation_settings();
    $hook = sitepulse_speed_analyzer_get_cron_hook();
    $frequency = $settings['frequency'];

    $frequency = apply_filters('sitepulse_speed_analyzer_cron_recurrence', $frequency, $settings);
    $frequency = sitepulse_speed_analyzer_sanitize_frequency($frequency);

    if ($frequency === 'disabled' || !is_array($settings['presets']) || $settings['presets'] === []) {
        sitepulse_speed_analyzer_unschedule_events();

        return;
    }

    if (!$force && wp_next_scheduled($hook)) {
        return;
    }

    sitepulse_speed_analyzer_unschedule_events();

    wp_schedule_event(time() + MINUTE_IN_SECONDS, $frequency, $hook);
}

/**
 * Handles the cron hook execution.
 *
 * @return void
 */
function sitepulse_speed_analyzer_run_cron() {
    $settings = sitepulse_speed_analyzer_get_automation_settings();

    if (empty($settings['presets'])) {
        return;
    }

    sitepulse_speed_analyzer_enqueue_presets($settings['presets']);
    sitepulse_speed_analyzer_drain_queue($settings);
}

/**
 * Handles queued runs scheduled via wp_schedule_single_event().
 *
 * @return void
 */
function sitepulse_speed_analyzer_run_queue() {
    $settings = sitepulse_speed_analyzer_get_automation_settings();

    if (empty($settings['presets'])) {
        sitepulse_speed_analyzer_update_queue([]);

        return;
    }

    sitepulse_speed_analyzer_drain_queue($settings, true);
}

/**
 * Processes the automation queue.
 *
 * @param array<string,mixed> $settings Automation settings.
 * @param bool                $is_retry Whether the drain was initiated from a retry event.
 *
 * @return void
 */
function sitepulse_speed_analyzer_drain_queue($settings, $is_retry = false) {
    if (!is_array($settings) || empty($settings['presets'])) {
        return;
    }

    if (!sitepulse_speed_analyzer_acquire_lock()) {
        return;
    }

    $batch_size = apply_filters('sitepulse_speed_analyzer_cron_batch_size', 1, $settings, $is_retry);

    if (!is_numeric($batch_size) || $batch_size < 1) {
        $batch_size = 1;
    }

    $processed = 0;
    $presets = $settings['presets'];

    while ($processed < $batch_size) {
        $token = sitepulse_speed_analyzer_shift_queue();

        if ($token === null) {
            break;
        }

        $parsed = sitepulse_speed_analyzer_parse_queue_token($token);
        $preset_slug = $parsed['preset'];
        $source_key = $parsed['source'];

        if (!isset($presets[$preset_slug])) {
            $processed++;

            continue;
        }

        $config = $presets[$preset_slug];
        $targets = sitepulse_speed_analyzer_resolve_targets_for_preset($preset_slug, $config);
        $target = sitepulse_speed_analyzer_get_target_by_key($targets, $source_key);

        if ($target === null) {
            $processed++;

            continue;
        }

        sitepulse_speed_analyzer_execute_automation_scan($preset_slug, $config, $target);
        $processed++;
    }

    sitepulse_speed_analyzer_release_lock();

    if (sitepulse_speed_analyzer_queue_not_empty()) {
        $delay = apply_filters('sitepulse_speed_analyzer_queue_delay', MINUTE_IN_SECONDS, $settings, $is_retry);

        if (!is_numeric($delay) || $delay < 1) {
            $delay = MINUTE_IN_SECONDS;
        }

        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + (int) $delay, sitepulse_speed_analyzer_get_queue_hook());
        }
    }
}

/**
 * Executes a preset scan.
 *
 * @param string               $preset Preset identifier.
 * @param array<string,string> $config Preset configuration.
 * @param array<string,mixed>  $target Target descriptor.
 *
 * @return void
 */
function sitepulse_speed_analyzer_execute_automation_scan($preset, $config, $target) {
    if (!is_array($config)) {
        $config = [];
    }

    if (!is_array($target)) {
        return;
    }

    $url = isset($target['url']) ? (string) $target['url'] : (isset($config['url']) ? (string) $config['url'] : '');

    if ($url === '') {
        return;
    }

    $method = isset($config['method']) ? strtoupper((string) $config['method']) : 'GET';

    if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
        $method = 'GET';
    }

    $profile = isset($target['profile']) ? sitepulse_speed_analyzer_normalize_profile($target['profile']) : (isset($config['profile']) ? sitepulse_speed_analyzer_normalize_profile($config['profile']) : 'default');
    $source_key = isset($target['key']) ? sanitize_key($target['key']) : 'site';
    $source_label = isset($target['label']) ? (string) $target['label'] : ($source_key === 'site' ? (isset($config['label']) ? (string) $config['label'] : ucfirst($preset)) : $url);
    $source_type = isset($target['type']) ? (string) $target['type'] : 'site';

    if (!function_exists('wp_remote_request')) {
        include_once ABSPATH . WPINC . '/http.php';
    }

    $timeout = isset($config['timeout']) && is_numeric($config['timeout'])
        ? max(1, (int) $config['timeout'])
        : 15;

    $args = [
        'method'  => $method,
        'timeout' => $timeout,
    ];

    if ('POST' === $method && isset($config['body']) && is_array($config['body'])) {
        $args['body'] = $config['body'];
    }

    $start = microtime(true);
    $response = wp_remote_request($url, $args);
    $duration_ms = max(0.0, (microtime(true) - $start) * 1000);
    $timestamp = current_time('timestamp');
    $http_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    $error_message = is_wp_error($response) ? $response->get_error_message() : '';

    $result = sitepulse_speed_analyzer_store_automation_measurement(
        $preset,
        [
            'timestamp'            => $timestamp,
            'server_processing_ms' => $duration_ms,
            'http_code'            => $http_code,
            'error'                => $error_message,
            'source'               => $source_key,
            'source_label'         => $source_label,
            'source_type'          => $source_type,
            'profile'              => $profile,
            'url'                  => $url,
        ],
        array_merge($config, [
            'profile' => $profile,
            'label'   => isset($config['label']) ? $config['label'] : $source_label,
        ])
    );

    sitepulse_speed_analyzer_notify_regression_if_needed($preset, $config, $result['current'], $result['previous']);
}

/**
 * Triggers a notification when a regression is detected.
 *
 * @param string               $preset   Preset identifier.
 * @param array<string,string> $config   Preset configuration.
 * @param array<string,mixed>|null $current Current entry.
 * @param array<string,mixed>|null $previous Previous entry.
 *
 * @return void
 */
function sitepulse_speed_analyzer_notify_regression_if_needed($preset, $config, $current, $previous) {
    if (!is_array($current) || !isset($current['server_processing_ms'])) {
        return;
    }

    if (!is_numeric($current['server_processing_ms'])) {
        return;
    }

    $current_value = max(0.0, (float) $current['server_processing_ms']);
    $previous_value = is_array($previous) && isset($previous['server_processing_ms']) && is_numeric($previous['server_processing_ms'])
        ? max(0.0, (float) $previous['server_processing_ms'])
        : null;

    $source_key = isset($current['source']) ? sanitize_key($current['source']) : 'site';
    $source_type = isset($current['source_type']) ? sanitize_key($current['source_type']) : 'site';

    if ($source_type !== 'site') {
        return;
    }

    $profile = isset($current['profile'])
        ? sitepulse_speed_analyzer_normalize_profile($current['profile'])
        : (isset($config['profile']) ? sitepulse_speed_analyzer_normalize_profile($config['profile']) : 'default');

    $label = isset($config['label']) ? (string) $config['label'] : ucfirst($preset);
    $site_name = function_exists('get_bloginfo') ? get_bloginfo('name') : 'WordPress';

    $alerts = [];
    $messages = [];

    if ($previous_value !== null && $previous_value > 0.0) {
        $threshold = apply_filters('sitepulse_speed_analyzer_regression_threshold', 0.3, $preset, $config);

        if (!is_numeric($threshold) || $threshold <= 0) {
            $threshold = 0.3;
        }

        $min_delta = apply_filters('sitepulse_speed_analyzer_regression_min_delta', 100.0, $preset, $config);

        if (!is_numeric($min_delta) || $min_delta < 0) {
            $min_delta = 0;
        }

        $delta = $current_value - $previous_value;

        if ($delta >= $min_delta && $current_value >= $previous_value * (1 + $threshold)) {
            $alerts[] = 'regression';
            $messages[] = sprintf(
                /* translators: 1: preset label, 2: previous duration, 3: current duration. */
                __('Régression : « %1$s » est passé de %2$.2f ms à %3$.2f ms.', 'sitepulse'),
                $label,
                $previous_value,
                $current_value
            );
        }
    }

    $budget = sitepulse_speed_analyzer_get_profile_benchmark_budget($profile);

    if ($budget !== null && $current_value > $budget) {
        $alerts[] = 'budget';
        $messages[] = sprintf(
            /* translators: 1: profile label, 2: measured value, 3: budget value. */
            __('Budget dépassé : profil %1$s à %2$.2f ms (budget %3$d ms).', 'sitepulse'),
            $profile,
            $current_value,
            (int) $budget
        );
    }

    $benchmark_label = '';
    $benchmark_value = null;
    $history = sitepulse_speed_analyzer_get_raw_automation_history();

    if (isset($history[$preset]) && is_array($history[$preset])) {
        $entries = array_reverse($history[$preset]);

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!isset($entry['source_type']) || sanitize_key($entry['source_type']) !== 'competitor') {
                continue;
            }

            $entry_profile = isset($entry['profile']) ? sitepulse_speed_analyzer_normalize_profile($entry['profile']) : 'default';

            if ($entry_profile !== $profile) {
                continue;
            }

            if (!isset($entry['server_processing_ms']) || !is_numeric($entry['server_processing_ms'])) {
                continue;
            }

            $benchmark_value = max(0.0, (float) $entry['server_processing_ms']);
            $benchmark_label = isset($entry['source_label']) ? (string) $entry['source_label'] : (isset($entry['source']) ? (string) $entry['source'] : '');
            break;
        }
    }

    if ($benchmark_value !== null && $current_value > $benchmark_value) {
        $alerts[] = 'benchmark';
        $messages[] = sprintf(
            /* translators: 1: competitor label, 2: competitor value, 3: own value. */
            __('Benchmark perdu : %1$s mesure %2$.2f ms contre %3$.2f ms.', 'sitepulse'),
            $benchmark_label !== '' ? $benchmark_label : __('le concurrent suivi', 'sitepulse'),
            $benchmark_value,
            $current_value
        );
    }

    if ($messages === []) {
        return;
    }

    $current['alerts'] = $alerts;

    $cooldown = apply_filters('sitepulse_speed_analyzer_regression_cooldown', 3 * HOUR_IN_SECONDS, $preset, $config);

    if (!is_numeric($cooldown) || $cooldown < 60) {
        $cooldown = 3 * HOUR_IN_SECONDS;
    }

    $transient_key = 'sitepulse_speed_regression_' . md5($preset . '|' . $source_key . '|' . implode('-', $alerts));

    if (function_exists('get_transient') && false !== get_transient($transient_key)) {
        return;
    }

    $subject = sprintf(
        /* translators: %1$s: preset label, %2$s: site name. */
        __('Alerte performance pour %1$s sur %2$s', 'sitepulse'),
        $label,
        $site_name
    );

    $message = implode("\n\n", $messages);
    $should_notify = apply_filters('sitepulse_speed_analyzer_send_regression_notification', true, $preset, $config, $current, $previous);

    if ($should_notify && function_exists('wp_mail')) {
        $recipients = apply_filters('sitepulse_speed_analyzer_regression_recipients', [get_option('admin_email')], $preset, $config, $current, $previous);

        if (is_array($recipients)) {
            $recipients = array_values(array_filter(array_map('sanitize_email', $recipients)));
        } else {
            $recipients = [];
        }

        if ($recipients !== []) {
            wp_mail($recipients, $subject, $message);
        }
    }

    if (function_exists('do_action')) {
        if (in_array('regression', $alerts, true)) {
            do_action('sitepulse_speed_analyzer_regression_detected', $preset, $config, $current, $previous);
        }

        if (in_array('benchmark', $alerts, true) || in_array('budget', $alerts, true)) {
            do_action('sitepulse_speed_analyzer_benchmark_alert', $preset, $config, $current, $previous, $alerts);
        }
    }

    if (function_exists('set_transient')) {
        set_transient($transient_key, time(), (int) $cooldown);
    }
}
