<?php
/**
 * SitePulse AI Insights job queue.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Attempts to spawn WP-Cron so the scheduled AI insight job runs immediately.
 *
 * @param int $timestamp Cron timestamp used to trigger the spawn.
 *
 * @return mixed
 */
function sitepulse_ai_spawn_cron($timestamp) {
    $timestamp = (int) $timestamp;

    if ($timestamp <= 0) {
        $timestamp = time();
    }

    $callable = 'spawn_cron';

    if (function_exists('apply_filters')) {
        $filtered_callable = apply_filters('sitepulse_ai_spawn_cron_callable', $callable, $timestamp);

        if (null !== $filtered_callable) {
            $callable = $filtered_callable;
        }
    }

    if (is_callable($callable)) {
        return call_user_func($callable, $timestamp);
    }

    if (class_exists('WP_Error')) {
        /* translators: %s: callable name. */
        return new WP_Error('sitepulse_ai_spawn_unavailable', sprintf(esc_html__('La fonction %s n’est pas disponible.', 'sitepulse'), (string) $callable));
    }

    return false;
}

/**
 * Returns the shared secret used to trigger AI insight jobs via AJAX.
 *
 * @param bool $force_regenerate Optional. Whether to regenerate a new secret.
 *
 * @return string
 */
function sitepulse_ai_get_job_secret($force_regenerate = false) {
    $secret = get_option(SITEPULSE_OPTION_AI_JOB_SECRET, '');

    if ($force_regenerate || !is_string($secret) || '' === $secret) {
        $secret = sitepulse_ai_regenerate_job_secret();
    }

    /**
     * Filters the secret used when dispatching asynchronous AI insight jobs.
     *
     * @param string $secret Secret stored in the database.
     */
    return (string) apply_filters('sitepulse_ai_job_secret', $secret);
}

/**
 * Regenerates the shared secret used to trigger AI insight jobs.
 *
 * @return string Newly generated secret stored in the database.
 */
function sitepulse_ai_regenerate_job_secret() {
    $secret = wp_generate_password(64, false, false);

    update_option(SITEPULSE_OPTION_AI_JOB_SECRET, $secret, false);

    return $secret;
}

/**
 * Attempts to trigger the AI insight job immediately via admin-ajax.php.
 *
 * @param string $job_id Job identifier.
 *
 * @return array|WP_Error HTTP response or error on failure.
 */
function sitepulse_ai_trigger_async_job_request($job_id, array $queue_context = []) {
    $job_id = (string) $job_id;

    if ('' === $job_id) {
        if (class_exists('WP_Error')) {
            return new WP_Error('sitepulse_ai_missing_job_id', esc_html__('Identifiant de tâche manquant pour le déclenchement immédiat.', 'sitepulse'));
        }

        return false;
    }

    $encoded_context = wp_json_encode($queue_context);

    if (false === $encoded_context) {
        $encoded_context = '';
    }

    $timeout = 30;

    if (function_exists('apply_filters')) {
        /**
         * Filters the timeout used when triggering the AI insight job via AJAX.
         *
         * @param int    $timeout       Timeout in seconds.
         * @param string $job_id        Job identifier.
         * @param array  $queue_context Normalized queue context.
         */
        $timeout = (int) apply_filters('sitepulse_ai_async_request_timeout', $timeout, $job_id, $queue_context);
    }

    if ($timeout <= 0) {
        $timeout = 30;
    }

    $request_args = [
        'timeout'  => $timeout,
        'blocking' => true,
        'body'     => [
            'action' => 'sitepulse_run_ai_insight_job',
            'job_id' => $job_id,
            'secret' => sitepulse_ai_get_job_secret(),
            'context'=> $encoded_context,
        ],
    ];

    if (function_exists('apply_filters')) {
        /**
         * Filters the HTTP arguments used to trigger the AI insight job via AJAX.
         *
         * @param array  $request_args HTTP request arguments.
         * @param string $job_id       Job identifier.
         */
        $request_args = (array) apply_filters('sitepulse_ai_async_request_args', $request_args, $job_id);
    }

    return wp_remote_post(admin_url('admin-ajax.php'), $request_args);
}

/**
 * Handles AJAX requests used to trigger the AI insight job immediately.
 *
 * @return void
 */
function sitepulse_ai_handle_async_job_request() {
    $provided_secret = isset($_REQUEST['secret']) ? (string) wp_unslash($_REQUEST['secret']) : '';
    $expected_secret = sitepulse_ai_get_job_secret();

    if (!hash_equals($expected_secret, $provided_secret)) {
        wp_send_json_error([
            'message' => esc_html__('Secret invalide pour l’exécution de l’analyse IA.', 'sitepulse'),
        ], 403);
    }

    $job_id = isset($_REQUEST['job_id']) ? sanitize_text_field((string) wp_unslash($_REQUEST['job_id'])) : '';

    if ('' === $job_id) {
        wp_send_json_error([
            'message' => esc_html__('Identifiant de tâche manquant.', 'sitepulse'),
        ], 400);
    }

    $context = [];

    if (isset($_REQUEST['context'])) {
        $decoded = json_decode((string) wp_unslash($_REQUEST['context']), true);

        if (is_array($decoded)) {
            $context = $decoded;
        }
    }

    sitepulse_run_ai_insight_job($job_id, $context);

    wp_send_json_success([
        'job_id' => $job_id,
    ]);
}

/**
 * Determines whether WP-Cron is disabled for the current installation.
 *
 * @return bool
 */
function sitepulse_ai_is_wp_cron_disabled() {
    $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

    /**
     * Filters the WP-Cron disabled detection used by SitePulse AI Insights.
     *
     * This allows hosting environments or tests to override the automatic
     * detection of the DISABLE_WP_CRON constant.
     *
     * @param bool $disabled Whether WP-Cron is considered disabled.
     */
    return (bool) apply_filters('sitepulse_ai_is_wp_cron_disabled', $disabled);
}

/**
 * Returns the transient key used to store AI insight job metadata.
 *
 * @param string $job_id Job identifier.
 *
 * @return string
 */
function sitepulse_ai_job_transient_key($job_id) {
    $sanitized = sanitize_key((string) $job_id);

    if ('' === $sanitized) {
        $sanitized = md5((string) $job_id);
    }

    return SITEPULSE_TRANSIENT_AI_INSIGHT_JOB_PREFIX . $sanitized;
}

/**
 * Retrieves job metadata from the transient cache.
 *
 * @param string $job_id Job identifier.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_get_job_data($job_id) {
    if ('' === $job_id) {
        return [];
    }

    $stored = get_transient(sitepulse_ai_job_transient_key($job_id));

    return is_array($stored) ? $stored : [];
}

/**
 * Deletes job metadata from the transient cache.
 *
 * @param string $job_id Job identifier.
 *
 * @return void
 */
function sitepulse_ai_delete_job_data($job_id) {
    if ('' === $job_id) {
        return;
    }

    delete_transient(sitepulse_ai_job_transient_key($job_id));
    sitepulse_ai_remove_job_from_queue_index($job_id);
}

/**
 * Persists job metadata in the transient cache.
 *
 * @param string               $job_id     Job identifier.
 * @param array<string,mixed>  $job_data   Data to store.
 * @param int|null             $expiration Optional. Expiration in seconds.
 *
 * @return bool Whether the transient was set.
 */
function sitepulse_ai_save_job_data($job_id, array $job_data, $expiration = null) {
    if ('' === $job_id) {
        return false;
    }

    $key        = sitepulse_ai_job_transient_key($job_id);
    $expiration = null === $expiration ? HOUR_IN_SECONDS : (int) $expiration;

    $saved = set_transient($key, $job_data, $expiration);

    if ($saved) {
        sitepulse_ai_sync_queue_index($job_id, $job_data);
    }

    return $saved;
}

/**
 * Removes job metadata from the queue index and transient store.
 *
 * @param string $job_id Job identifier.
 *
 * @return void
 */
function sitepulse_ai_remove_job_from_queue_index($job_id) {
    if (!function_exists('get_option') || !function_exists('update_option')) {
        return;
    }

    $job_id = sanitize_key((string) $job_id);

    if ('' === $job_id) {
        return;
    }

    $index = get_option(SITEPULSE_OPTION_AI_QUEUE_INDEX, []);

    if (!is_array($index) || empty($index)) {
        return;
    }

    $changed = false;

    foreach ($index as $position => $entry) {
        if (!is_array($entry) || !isset($entry['id'])) {
            continue;
        }

        if (sanitize_key((string) $entry['id']) === $job_id) {
            unset($index[$position]);
            $changed = true;
        }
    }

    if ($changed) {
        $index = array_values($index);
        update_option(SITEPULSE_OPTION_AI_QUEUE_INDEX, $index, false);
    }
}

/**
 * Returns the queue group identifier shared with Action Scheduler.
 *
 * @return string
 */
function sitepulse_ai_get_queue_group() {
    if (defined('SITEPULSE_AI_QUEUE_GROUP')) {
        return SITEPULSE_AI_QUEUE_GROUP;
    }

    return 'sitepulse_ai';
}

/**
 * Captures the current quota configuration for tracking purposes.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_capture_quota_snapshot() {
    $rate_limit_value = sitepulse_ai_get_current_rate_limit_value();

    return [
        'value'  => $rate_limit_value,
        'label'  => sitepulse_ai_get_rate_limit_label($rate_limit_value),
        'window' => sitepulse_ai_get_rate_limit_window_seconds($rate_limit_value),
    ];
}

/**
 * Returns the currently selected AI model key.
 *
 * @return string
 */
function sitepulse_ai_get_selected_model_key() {
    if (!function_exists('get_option')) {
        return '';
    }

    $default_model  = function_exists('sitepulse_get_default_ai_model') ? sitepulse_get_default_ai_model() : '';
    $selected_model = (string) get_option(SITEPULSE_OPTION_AI_MODEL, $default_model);

    if ('' === $selected_model && '' !== $default_model) {
        $selected_model = $default_model;
    }

    return sanitize_text_field($selected_model);
}

/**
 * Returns the maximum number of job log entries to retain.
 *
 * @return int
 */
function sitepulse_ai_get_job_log_max_entries() {
    $max_entries = (int) apply_filters('sitepulse_ai_job_log_max_entries', 50);

    if ($max_entries <= 0) {
        $max_entries = 50;
    }

    return $max_entries;
}

/**
 * Returns the rolling window size used when calculating failure rates.
 *
 * @return int
 */
function sitepulse_ai_get_failure_rate_window() {
    $window = (int) apply_filters('sitepulse_ai_failure_rate_window', 10);

    if ($window <= 0) {
        $window = 10;
    }

    return $window;
}

/**
 * Returns the failure rate threshold used for alerts.
 *
 * @return float
 */
function sitepulse_ai_get_failure_rate_threshold() {
    $threshold = (float) apply_filters('sitepulse_ai_failure_rate_threshold', 0.5);

    if ($threshold < 0) {
        $threshold = 0.0;
    }

    if ($threshold > 1) {
        $threshold = 1.0;
    }

    return $threshold;
}

/**
 * Returns the maximum cumulative cost allowed before triggering a warning.
 *
 * @return float
 */
function sitepulse_ai_get_cost_threshold() {
    $threshold = (float) apply_filters('sitepulse_ai_cost_threshold', 10.0);

    if ($threshold < 0) {
        $threshold = 0.0;
    }

    return $threshold;
}

/**
 * Returns the time window (in seconds) used for cost aggregation.
 *
 * @return int
 */
function sitepulse_ai_get_cost_window_seconds() {
    $default_window = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86_400;
    $window         = (int) apply_filters('sitepulse_ai_cost_window_seconds', $default_window);

    if ($window < 0) {
        $window = $default_window;
    }

    return $window;
}

/**
 * Normalizes a single job log entry.
 *
 * @param array<string,mixed> $entry Raw entry.
 *
 * @return array<string,mixed>|null
 */
function sitepulse_ai_normalize_job_log_entry($entry) {
    if (!is_array($entry) || !isset($entry['job_id'])) {
        return null;
    }

    $job_id = sanitize_key((string) $entry['job_id']);

    if ('' === $job_id) {
        return null;
    }

    $status = isset($entry['status']) ? sanitize_key((string) $entry['status']) : 'queued';
    $attempt = isset($entry['attempt']) ? max(1, (int) $entry['attempt']) : 1;
    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : time();
    $created_at = isset($entry['created_at']) ? (int) $entry['created_at'] : $timestamp;
    $updated_at = isset($entry['updated_at']) ? (int) $entry['updated_at'] : $timestamp;
    $latency_ms = null;

    if (isset($entry['latency_ms'])) {
        $latency_ms = max(0.0, (float) $entry['latency_ms']);
    }

    $history = [];

    if (isset($entry['history']) && is_array($entry['history'])) {
        foreach ($entry['history'] as $history_entry) {
            if (!is_array($history_entry)) {
                continue;
            }

            $history[] = [
                'status'    => isset($history_entry['status']) ? sanitize_key((string) $history_entry['status']) : $status,
                'attempt'   => isset($history_entry['attempt']) ? max(1, (int) $history_entry['attempt']) : $attempt,
                'timestamp' => isset($history_entry['timestamp']) ? (int) $history_entry['timestamp'] : $timestamp,
            ];
        }
    }

    if (empty($history)) {
        $history[] = [
            'status'    => $status,
            'attempt'   => $attempt,
            'timestamp' => $timestamp,
        ];
    }

    $usage = [];

    if (isset($entry['usage']) && is_array($entry['usage'])) {
        $usage = sitepulse_ai_normalize_usage_metadata($entry['usage']);
    }

    $normalized = [
        'job_id'         => $job_id,
        'status'         => $status,
        'status_final'   => isset($entry['status_final']) ? sanitize_key((string) $entry['status_final']) : (in_array($status, ['success', 'failed', 'abandoned'], true) ? $status : ''),
        'attempt'        => $attempt,
        'model'          => isset($entry['model']) ? sanitize_text_field((string) $entry['model']) : '',
        'engine'         => isset($entry['engine']) ? sanitize_key((string) $entry['engine']) : '',
        'cost_estimated' => isset($entry['cost_estimated']) ? (float) $entry['cost_estimated'] : 0.0,
        'quota_consumed' => isset($entry['quota_consumed']) ? (float) $entry['quota_consumed'] : (isset($entry['cost_estimated']) ? (float) $entry['cost_estimated'] : 0.0),
        'latency_ms'     => $latency_ms,
        'usage'          => $usage,
        'message'        => isset($entry['message']) ? sanitize_text_field((string) $entry['message']) : '',
        'timestamp'      => $timestamp,
        'created_at'     => $created_at,
        'updated_at'     => $updated_at,
        'history'        => array_slice($history, -10),
    ];

    return $normalized;
}

/**
 * Retrieves the persisted job log entries.
 *
 * @return array<int,array<string,mixed>>
 */
function sitepulse_ai_get_job_log() {
    if (!function_exists('get_option')) {
        return [];
    }

    $stored = get_option(SITEPULSE_OPTION_AI_JOBS_LOG, []);

    if (!is_array($stored)) {
        return [];
    }

    $normalized = [];

    foreach ($stored as $entry) {
        $normalized_entry = sitepulse_ai_normalize_job_log_entry($entry);

        if (null !== $normalized_entry) {
            $normalized[] = $normalized_entry;
        }
    }

    return $normalized;
}

/**
 * Persists the provided job log entries.
 *
 * @param array<int,array<string,mixed>> $entries Normalized entries.
 *
 * @return void
 */
function sitepulse_ai_store_job_log(array $entries) {
    if (!function_exists('update_option')) {
        return;
    }

    update_option(SITEPULSE_OPTION_AI_JOBS_LOG, array_values($entries), false);
}

/**
 * Extracts a cost value from a usage array when available.
 *
 * @param array<string,mixed> $usage Usage metadata.
 *
 * @return float
 */
function sitepulse_ai_extract_usage_cost(array $usage) {
    if (isset($usage['cost']) && is_numeric($usage['cost'])) {
        return (float) $usage['cost'];
    }

    if (isset($usage['usage']) && is_numeric($usage['usage'])) {
        return (float) $usage['usage'];
    }

    if (isset($usage['tokens']) && is_numeric($usage['tokens'])) {
        return (float) $usage['tokens'];
    }

    return 0.0;
}

/**
 * Estimates the cost of a job from its queue context.
 *
 * @param array<string,mixed> $context Queue context.
 *
 * @return float
 */
function sitepulse_ai_estimate_job_cost(array $context) {
    $estimated = 0.0;

    if (isset($context['usage']) && is_array($context['usage'])) {
        $estimated = sitepulse_ai_extract_usage_cost($context['usage']);
    }

    /**
     * Filters the estimated cost recorded for a job.
     *
     * @param float $estimated Estimated cost value.
     * @param array $context   Queue context.
     */
    $estimated = (float) apply_filters('sitepulse_ai_estimated_job_cost', $estimated, $context);

    if ($estimated < 0) {
        $estimated = 0.0;
    }

    return $estimated;
}

/**
 * Records or updates a job log entry.
 *
 * @param string              $job_id Job identifier.
 * @param array<string,mixed> $data   Entry payload.
 *
 * @return void
 */
function sitepulse_ai_record_job_log($job_id, array $data) {
    $job_id = sanitize_key((string) $job_id);

    if ('' === $job_id) {
        return;
    }

    $timestamp = isset($data['timestamp']) ? (int) $data['timestamp'] : time();
    $status    = isset($data['status']) ? sanitize_key((string) $data['status']) : 'queued';
    $attempt   = isset($data['attempt']) ? max(1, (int) $data['attempt']) : 1;
    $model     = isset($data['model']) ? sanitize_text_field((string) $data['model']) : '';
    $engine    = isset($data['engine']) ? sanitize_key((string) $data['engine']) : '';
    $message   = isset($data['message']) ? wp_strip_all_tags((string) $data['message']) : '';
    $usage     = [];

    if (isset($data['usage']) && is_array($data['usage'])) {
        $usage = sitepulse_ai_normalize_usage_metadata($data['usage']);
    }
    $latency   = null;

    if (isset($data['latency_ms'])) {
        $latency = max(0.0, (float) $data['latency_ms']);
    }

    $cost_estimated = isset($data['cost_estimated']) ? (float) $data['cost_estimated'] : sitepulse_ai_extract_usage_cost($usage);
    $quota_consumed = isset($data['quota_consumed']) ? (float) $data['quota_consumed'] : $cost_estimated;
    $final_status   = '';

    if (isset($data['status_final'])) {
        $final_status = sanitize_key((string) $data['status_final']);
    } elseif (in_array($status, ['success', 'failed', 'abandoned'], true)) {
        $final_status = $status;
    }

    $entries      = sitepulse_ai_get_job_log();
    $updated      = false;
    $latest_entry = null;

    foreach ($entries as $index => $entry) {
        if (!isset($entry['job_id']) || $entry['job_id'] !== $job_id) {
            continue;
        }

        $history = isset($entry['history']) && is_array($entry['history']) ? $entry['history'] : [];
        $last_history = end($history);

        if (!is_array($last_history) || $last_history['status'] !== $status || (int) $last_history['attempt'] !== $attempt) {
            $history[] = [
                'status'    => $status,
                'attempt'   => $attempt,
                'timestamp' => $timestamp,
            ];
        }

        $entry['status']         = $status;
        $entry['attempt']        = $attempt;
        $entry['model']          = '' !== $model ? $model : (isset($entry['model']) ? $entry['model'] : '');
        $entry['engine']         = '' !== $engine ? $engine : (isset($entry['engine']) ? $entry['engine'] : '');
        $entry['cost_estimated'] = $cost_estimated;
        $entry['quota_consumed'] = $quota_consumed;
        $entry['latency_ms']     = null !== $latency ? $latency : (isset($entry['latency_ms']) ? $entry['latency_ms'] : null);
        $entry['usage']          = !empty($usage) ? $usage : (isset($entry['usage']) ? $entry['usage'] : []);
        $entry['message']        = '' !== $message ? $message : (isset($entry['message']) ? $entry['message'] : '');
        $entry['timestamp']      = $timestamp;
        $entry['updated_at']     = $timestamp;
        $entry['history']        = array_slice($history, -10);

        if ('' !== $final_status) {
            $entry['status_final'] = $final_status;
        } elseif (isset($entry['status_final']) && in_array($entry['status_final'], ['success', 'failed', 'abandoned'], true)) {
            // Keep previous final status.
        } elseif (in_array($status, ['success', 'failed', 'abandoned'], true)) {
            $entry['status_final'] = $status;
        }

        $entries[$index] = $entry;
        $updated         = true;
        $latest_entry    = $entry;
        break;
    }

    if (!$updated) {
        $latest_entry = [
            'job_id'         => $job_id,
            'status'         => $status,
            'status_final'   => $final_status,
            'attempt'        => $attempt,
            'model'          => $model,
            'engine'         => $engine,
            'cost_estimated' => $cost_estimated,
            'quota_consumed' => $quota_consumed,
            'latency_ms'     => $latency,
            'usage'          => $usage,
            'message'        => $message,
            'timestamp'      => $timestamp,
            'created_at'     => $timestamp,
            'updated_at'     => $timestamp,
            'history'        => [
                [
                    'status'    => $status,
                    'attempt'   => $attempt,
                    'timestamp' => $timestamp,
                ],
            ],
        ];

        if ('' === $latest_entry['status_final'] && in_array($status, ['success', 'failed', 'abandoned'], true)) {
            $latest_entry['status_final'] = $status;
        }

        $entries[] = $latest_entry;
    }

    usort($entries, function($a, $b) {
        $a_created = isset($a['created_at']) ? (int) $a['created_at'] : (isset($a['timestamp']) ? (int) $a['timestamp'] : 0);
        $b_created = isset($b['created_at']) ? (int) $b['created_at'] : (isset($b['timestamp']) ? (int) $b['timestamp'] : 0);

        if ($a_created === $b_created) {
            return 0;
        }

        return ($a_created < $b_created) ? -1 : 1;
    });

    $max_entries = sitepulse_ai_get_job_log_max_entries();

    if (count($entries) > $max_entries) {
        $entries = array_slice($entries, -1 * $max_entries);
    }

    sitepulse_ai_store_job_log($entries);

    if (null !== $latest_entry) {
        sitepulse_ai_maybe_trigger_job_alerts($entries, $latest_entry);
    }
}

/**
 * Calculates aggregate metrics for the recorded job entries.
 *
 * @param array<int,array<string,mixed>> $entries Job log entries.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_calculate_job_metrics(array $entries) {
    $failure_window    = sitepulse_ai_get_failure_rate_window();
    $failure_threshold = sitepulse_ai_get_failure_rate_threshold();

    $final_entries = array_values(array_filter($entries, function($entry) {
        return isset($entry['status_final']) && '' !== $entry['status_final'];
    }));

    usort($final_entries, function($a, $b) {
        $a_time = isset($a['updated_at']) ? (int) $a['updated_at'] : (isset($a['timestamp']) ? (int) $a['timestamp'] : 0);
        $b_time = isset($b['updated_at']) ? (int) $b['updated_at'] : (isset($b['timestamp']) ? (int) $b['timestamp'] : 0);

        if ($a_time === $b_time) {
            return 0;
        }

        return ($a_time < $b_time) ? -1 : 1;
    });

    $window_entries   = $failure_window > 0 ? array_slice($final_entries, -1 * $failure_window) : $final_entries;
    $total_considered = count($window_entries);
    $failure_count    = 0;

    foreach ($window_entries as $entry) {
        if (isset($entry['status_final']) && 'failed' === $entry['status_final']) {
            $failure_count++;
        }
    }

    $failure_rate = $total_considered > 0 ? $failure_count / $total_considered : 0.0;

    $cost_window     = sitepulse_ai_get_cost_window_seconds();
    $cost_threshold  = sitepulse_ai_get_cost_threshold();
    $now             = time();
    $aggregated_cost = 0.0;

    foreach ($entries as $entry) {
        $updated_at = isset($entry['updated_at']) ? (int) $entry['updated_at'] : (isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0);

        if ($cost_window > 0 && $updated_at < ($now - $cost_window)) {
            continue;
        }

        $aggregated_cost += isset($entry['cost_estimated']) ? (float) $entry['cost_estimated'] : 0.0;
    }

    $latencies = [];

    foreach ($final_entries as $entry) {
        if (isset($entry['status_final']) && 'success' === $entry['status_final'] && isset($entry['latency_ms']) && null !== $entry['latency_ms']) {
            $latencies[] = (float) $entry['latency_ms'];
        }
    }

    $average_latency = 0.0;

    if (!empty($latencies)) {
        $average_latency = array_sum($latencies) / count($latencies);
    }

    $metrics = [
        'failure_rate' => [
            'value'     => $failure_rate,
            'count'     => $failure_count,
            'total'     => $total_considered,
            'threshold' => $failure_threshold,
            'window'    => $failure_window,
            'breached'  => $total_considered > 0 && $failure_rate >= $failure_threshold,
        ],
        'cost' => [
            'value'          => $aggregated_cost,
            'threshold'      => $cost_threshold,
            'window_seconds' => $cost_window,
            'breached'       => $cost_threshold > 0 && $aggregated_cost >= $cost_threshold,
        ],
        'latency' => [
            'average_ms' => $average_latency,
        ],
        'totals' => [
            'success'   => count(array_filter($final_entries, function($entry) {
                return isset($entry['status_final']) && 'success' === $entry['status_final'];
            })),
            'failed'    => count(array_filter($final_entries, function($entry) {
                return isset($entry['status_final']) && 'failed' === $entry['status_final'];
            })),
            'abandoned' => count(array_filter($final_entries, function($entry) {
                return isset($entry['status_final']) && 'abandoned' === $entry['status_final'];
            })),
        ],
    ];

    return $metrics;
}

/**
 * Dispatches alert hooks after the job log has been updated.
 *
 * @param array<int,array<string,mixed>> $entries       All entries.
 * @param array<string,mixed>            $latest_entry Latest entry.
 *
 * @return void
 */
function sitepulse_ai_maybe_trigger_job_alerts(array $entries, array $latest_entry) {
    $metrics = sitepulse_ai_calculate_job_metrics($entries);

    if (isset($latest_entry['status_final']) && 'failed' === $latest_entry['status_final']) {
        /** @var array<string,mixed> $metrics */
        do_action('sitepulse_ai_job_failed', $latest_entry['job_id'], $latest_entry, $metrics);
    }

    if (isset($metrics['cost']['breached']) && $metrics['cost']['breached']) {
        do_action('sitepulse_ai_quota_warning', $metrics, $latest_entry);
    }
}

/**
 * Handles job failure alerts by surfacing notices and webhooks.
 *
 * @param string                     $job_id  Job identifier.
 * @param array<string,mixed>        $entry   Latest entry data.
 * @param array<string,mixed>|mixed  $metrics Aggregated metrics.
 *
 * @return void
 */
function sitepulse_ai_handle_job_failed_alert($job_id, $entry, $metrics = []) {
    $job_id  = sanitize_key((string) $job_id);
    $attempt = isset($entry['attempt']) ? (int) $entry['attempt'] : 1;
    $message = sprintf(
        /* translators: 1: job identifier, 2: attempt number */
        esc_html__('Échec du job IA %1$s (tentative %2$d). Consultez l’onglet Observabilité pour plus de détails.', 'sitepulse'),
        $job_id,
        max(1, $attempt)
    );

    if (isset($entry['message']) && '' !== trim((string) $entry['message'])) {
        $message .= ' ' . sanitize_text_field((string) $entry['message']);
    }

    sitepulse_ai_queue_admin_alert_notice($message, 'error');

    $failure_rate     = isset($metrics['failure_rate']['value']) ? (float) $metrics['failure_rate']['value'] : 0.0;
    $failure_threshold = isset($metrics['failure_rate']['threshold']) ? (float) $metrics['failure_rate']['threshold'] : sitepulse_ai_get_failure_rate_threshold();
    $breached         = isset($metrics['failure_rate']['breached']) ? (bool) $metrics['failure_rate']['breached'] : ($failure_rate >= $failure_threshold);

    if (!$breached || !function_exists('sitepulse_error_alert_dispatch_webhooks')) {
        return;
    }

    $cooldown_key = 'sitepulse_ai_failure_alert_cooldown';

    if (function_exists('get_transient') && false !== get_transient($cooldown_key)) {
        return;
    }

    if (function_exists('set_transient')) {
        set_transient($cooldown_key, time(), 10 * MINUTE_IN_SECONDS);
    }

    $rate_percent      = number_format_i18n($failure_rate * 100, 1);
    $threshold_percent = number_format_i18n($failure_threshold * 100, 1);
    $subject = sprintf(__('Alerte SitePulse : taux d’échec IA %s%%', 'sitepulse'), $rate_percent);
    $body    = sprintf(
        /* translators: 1: failure rate, 2: threshold, 3: job id, 4: attempt */
        __('Le taux d’échec des jobs IA atteint %1$s%% (seuil %2$s%%). Dernier job : %3$s (tentative %4$d).', 'sitepulse'),
        $rate_percent,
        $threshold_percent,
        $job_id,
        max(1, $attempt)
    );

    sitepulse_error_alert_dispatch_webhooks([
        'type'      => 'ai_job_failed',
        'subject'   => $subject,
        'message'   => $body,
        'severity'  => 'critical',
        'timestamp' => current_time('mysql', true),
        'site_name' => function_exists('get_bloginfo') ? wp_strip_all_tags(get_bloginfo('name')) : '',
        'site_url'  => function_exists('home_url') ? home_url('/') : '',
    ]);
}

/**
 * Handles quota warnings by dispatching notices and optional webhooks.
 *
 * @param array<string,mixed> $metrics Aggregated metrics.
 * @param array<string,mixed> $entry   Latest job entry.
 *
 * @return void
 */
function sitepulse_ai_handle_quota_warning_alert($metrics, $entry = []) {
    if (!isset($metrics['cost']['breached']) || !$metrics['cost']['breached']) {
        return;
    }

    $cost_value    = isset($metrics['cost']['value']) ? (float) $metrics['cost']['value'] : 0.0;
    $cost_threshold = isset($metrics['cost']['threshold']) ? (float) $metrics['cost']['threshold'] : sitepulse_ai_get_cost_threshold();
    $window_seconds = isset($metrics['cost']['window_seconds']) ? (int) $metrics['cost']['window_seconds'] : sitepulse_ai_get_cost_window_seconds();

    $window_display = $window_seconds > 0 && function_exists('human_time_diff')
        ? sanitize_text_field(human_time_diff(time() - $window_seconds, time()))
        : sprintf('%ds', max(1, $window_seconds));

    $message = sprintf(
        /* translators: 1: cost value, 2: window display, 3: threshold */
        esc_html__('Consommation IA de %1$s crédits sur %2$s (seuil %3$s).', 'sitepulse'),
        number_format_i18n($cost_value, 2),
        esc_html($window_display),
        number_format_i18n($cost_threshold, 2)
    );

    sitepulse_ai_queue_admin_alert_notice($message, 'warning');

    if (!function_exists('sitepulse_error_alert_dispatch_webhooks')) {
        return;
    }

    $cooldown_key = 'sitepulse_ai_quota_alert_cooldown';

    if (function_exists('get_transient') && false !== get_transient($cooldown_key)) {
        return;
    }

    if (function_exists('set_transient')) {
        set_transient($cooldown_key, time(), 10 * MINUTE_IN_SECONDS);
    }

    $subject = __('Alerte SitePulse : quota IA élevé', 'sitepulse');
    $body    = sprintf(
        /* translators: 1: cost value, 2: threshold, 3: window */
        __('La consommation IA atteint %1$s crédits (seuil %2$s) sur %3$s.', 'sitepulse'),
        number_format_i18n($cost_value, 2),
        number_format_i18n($cost_threshold, 2),
        $window_display
    );

    sitepulse_error_alert_dispatch_webhooks([
        'type'      => 'ai_quota_warning',
        'subject'   => $subject,
        'message'   => $body,
        'severity'  => 'warning',
        'timestamp' => current_time('mysql', true),
        'site_name' => function_exists('get_bloginfo') ? wp_strip_all_tags(get_bloginfo('name')) : '',
        'site_url'  => function_exists('home_url') ? home_url('/') : '',
    ]);
}

/**
 * Normalizes quota metadata by sanitizing scalar values.
 *
 * @param array<string,mixed> $quota Raw quota context.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_normalize_quota_metadata(array $quota) {
    $normalized = [];

    foreach ($quota as $key => $value) {
        if (is_array($value)) {
            $normalized[$key] = sitepulse_ai_normalize_quota_metadata($value);

            continue;
        }

        if ('label' === $key) {
            $normalized[$key] = sanitize_text_field((string) $value);

            continue;
        }

        if ('value' === $key) {
            $normalized[$key] = (float) $value;

            continue;
        }

        if (in_array($key, ['window', 'window_seconds', 'retry_after', 'retry_window'], true)) {
            $normalized[$key] = (int) $value;

            continue;
        }

        if (is_bool($value)) {
            $normalized[$key] = (bool) $value;
        } elseif (is_numeric($value)) {
            $normalized[$key] = 0 + $value;
        } elseif (is_scalar($value)) {
            $normalized[$key] = sanitize_text_field((string) $value);
        }
    }

    return $normalized;
}

/**
 * Normalizes usage metadata by sanitizing scalar values.
 *
 * @param array<string,mixed> $usage Raw usage context.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_normalize_usage_metadata(array $usage) {
    $normalized = [];

    foreach ($usage as $key => $value) {
        if (is_array($value)) {
            $normalized[$key] = sitepulse_ai_normalize_usage_metadata($value);

            continue;
        }

        if (is_bool($value)) {
            $normalized[$key] = (bool) $value;
        } elseif (is_numeric($value)) {
            $normalized[$key] = 0 + $value;
        } elseif (is_scalar($value)) {
            $normalized[$key] = sanitize_text_field((string) $value);
        }
    }

    return $normalized;
}

/**
 * Returns a normalized queue context array without recursive data.
 *
 * @param array<string,mixed> $context Raw context.
 * @param array<string,mixed> $job_data Optional job metadata.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_normalize_queue_context(array $context, array $job_data = []) {
    $normalized = [];

    $normalized['priority'] = isset($context['priority'])
        ? sanitize_key((string) $context['priority'])
        : (isset($job_data['priority']) ? sanitize_key((string) $job_data['priority']) : 'normal');

    $normalized['attempt'] = isset($context['attempt'])
        ? max(1, (int) $context['attempt'])
        : (isset($job_data['attempt']) ? max(1, (int) $job_data['attempt']) : 1);

    $normalized['group'] = isset($context['group'])
        ? sanitize_key((string) $context['group'])
        : sitepulse_ai_get_queue_group();

    $normalized['engine'] = isset($context['engine'])
        ? sanitize_key((string) $context['engine'])
        : (isset($job_data['queue']['engine']) ? sanitize_key((string) $job_data['queue']['engine']) : 'wp_cron');

    $normalized['model'] = isset($context['model'])
        ? sanitize_text_field((string) $context['model'])
        : (isset($job_data['queue']['model'])
            ? sanitize_text_field((string) $job_data['queue']['model'])
            : sitepulse_ai_get_selected_model_key());

    $normalized['scheduled_at'] = isset($context['scheduled_at'])
        ? (int) $context['scheduled_at']
        : (isset($job_data['scheduled_at']) ? (int) $job_data['scheduled_at'] : time());

    $normalized['next_attempt_at'] = isset($context['next_attempt_at'])
        ? (int) $context['next_attempt_at']
        : (isset($job_data['next_attempt_at']) ? (int) $job_data['next_attempt_at'] : 0);

    if (isset($context['action_id'])) {
        $normalized['action_id'] = (int) $context['action_id'];
    } elseif (isset($job_data['queue']['action_id'])) {
        $normalized['action_id'] = (int) $job_data['queue']['action_id'];
    }

    if (isset($context['message'])) {
        $normalized['message'] = sanitize_text_field((string) $context['message']);
    } elseif (isset($job_data['message'])) {
        $normalized['message'] = sanitize_text_field((string) $job_data['message']);
    }

    $quota_source = null;

    if (isset($context['quota']) && is_array($context['quota'])) {
        $quota_source = $context['quota'];
    } elseif (isset($job_data['queue']['quota']) && is_array($job_data['queue']['quota'])) {
        $quota_source = $job_data['queue']['quota'];
    } else {
        $quota_source = sitepulse_ai_capture_quota_snapshot();
    }

    $normalized['quota'] = sitepulse_ai_normalize_quota_metadata(is_array($quota_source) ? $quota_source : []);

    $usage_source = null;

    if (isset($context['usage']) && is_array($context['usage'])) {
        $usage_source = $context['usage'];
    } elseif (isset($job_data['queue']['usage']) && is_array($job_data['queue']['usage'])) {
        $usage_source = $job_data['queue']['usage'];
    }

    if (is_array($usage_source)) {
        $normalized['usage'] = sitepulse_ai_normalize_usage_metadata($usage_source);
    }

    if (isset($context['args']) && is_array($context['args'])) {
        $normalized['args'] = $context['args'];
    } elseif (isset($job_data['queue']['args']) && is_array($job_data['queue']['args'])) {
        $normalized['args'] = $job_data['queue']['args'];
    }

    if (isset($context['force_refresh'])) {
        $normalized['force_refresh'] = (bool) $context['force_refresh'];
    } elseif (isset($job_data['force_refresh'])) {
        $normalized['force_refresh'] = (bool) $job_data['force_refresh'];
    }

    return $normalized;
}

/**
 * Retrieves the stored queue index.
 *
 * @return array<int,array<string,mixed>>
 */
function sitepulse_ai_get_queue_index() {
    if (!function_exists('get_option')) {
        return [];
    }

    $index = get_option(SITEPULSE_OPTION_AI_QUEUE_INDEX, []);

    if (!is_array($index)) {
        return [];
    }

    $normalized = [];

    foreach ($index as $entry) {
        if (!is_array($entry) || !isset($entry['id'])) {
            continue;
        }

        $normalized[] = [
            'id'             => sanitize_key((string) $entry['id']),
            'status'         => isset($entry['status']) ? sanitize_key((string) $entry['status']) : 'queued',
            'priority'       => isset($entry['priority']) ? sanitize_key((string) $entry['priority']) : 'normal',
            'attempt'        => isset($entry['attempt']) ? max(1, (int) $entry['attempt']) : 1,
            'created_at'     => isset($entry['created_at']) ? (int) $entry['created_at'] : time(),
            'updated_at'     => isset($entry['updated_at']) ? (int) $entry['updated_at'] : time(),
            'next_attempt_at'=> isset($entry['next_attempt_at']) ? (int) $entry['next_attempt_at'] : 0,
            'message'        => isset($entry['message']) ? sanitize_text_field((string) $entry['message']) : '',
            'engine'         => isset($entry['engine']) ? sanitize_key((string) $entry['engine']) : 'wp_cron',
            'quota'          => isset($entry['quota']) && is_array($entry['quota'])
                ? sitepulse_ai_normalize_quota_metadata($entry['quota'])
                : sitepulse_ai_normalize_quota_metadata(sitepulse_ai_capture_quota_snapshot()),
        ];
    }

    return array_values($normalized);
}

/**
 * Persists the queue index in the options table.
 *
 * @param array<int,array<string,mixed>> $index Queue index data.
 *
 * @return void
 */
function sitepulse_ai_set_queue_index(array $index) {
    if (!function_exists('update_option')) {
        return;
    }

    update_option(SITEPULSE_OPTION_AI_QUEUE_INDEX, array_values($index), false);
}

/**
 * Synchronizes an entry in the queue index with the provided job metadata.
 *
 * @param string               $job_id   Job identifier.
 * @param array<string,mixed>  $job_data Job metadata.
 *
 * @return void
 */
function sitepulse_ai_sync_queue_index($job_id, array $job_data) {
    if (!function_exists('update_option')) {
        return;
    }

    $job_id = sanitize_key((string) $job_id);

    if ('' === $job_id) {
        return;
    }

    $index  = sitepulse_ai_get_queue_index();
    $status = isset($job_data['status']) ? sanitize_key((string) $job_data['status']) : 'queued';

    $queue_context = isset($job_data['queue']) && is_array($job_data['queue'])
        ? sitepulse_ai_normalize_queue_context($job_data['queue'], $job_data)
        : sitepulse_ai_normalize_queue_context([], $job_data);

    $entry = [
        'id'             => $job_id,
        'status'         => $status,
        'priority'       => $queue_context['priority'],
        'attempt'        => isset($job_data['attempt']) ? max(1, (int) $job_data['attempt']) : $queue_context['attempt'],
        'created_at'     => isset($job_data['created_at']) ? (int) $job_data['created_at'] : time(),
        'updated_at'     => time(),
        'next_attempt_at'=> $queue_context['next_attempt_at'],
        'message'        => isset($job_data['message']) ? sanitize_text_field((string) $job_data['message']) : (isset($queue_context['message']) ? $queue_context['message'] : ''),
        'engine'         => $queue_context['engine'],
        'quota'          => $queue_context['quota'],
    ];

    $found = false;

    foreach ($index as $position => $stored_entry) {
        if ($stored_entry['id'] === $job_id) {
            $index[$position] = array_merge($stored_entry, $entry);
            $found            = true;
            break;
        }
    }

    if (!$found && in_array($status, ['queued', 'pending', 'running'], true)) {
        $index[] = $entry;
    } elseif ($found && in_array($status, ['completed'], true)) {
        // Keep completed entries briefly for inspection, they will be purged when metadata is deleted.
        $index[$position]['status'] = $status;
    }

    sitepulse_ai_set_queue_index($index);
}

/**
 * Calculates the current position of a job in the queue.
 *
 * @param string $job_id Job identifier.
 *
 * @return array{position:int,total:int}
 */
function sitepulse_ai_calculate_queue_position($job_id) {
    $index = sitepulse_ai_get_queue_index();
    $total = count($index);
    $position = 0;

    foreach ($index as $offset => $entry) {
        if ($entry['id'] === sanitize_key((string) $job_id)) {
            $position = $offset + 1;
            break;
        }
    }

    return [
        'position' => $position,
        'total'    => $total,
    ];
}

/**
 * Returns a snapshot of all queued jobs.
 *
 * @return array<int,array<string,mixed>>
 */
function sitepulse_ai_get_queue_snapshot() {
    $index    = sitepulse_ai_get_queue_index();
    $snapshot = [];

    foreach ($index as $entry) {
        $job_id   = $entry['id'];
        $job_data = sitepulse_ai_get_job_data($job_id);

        if (empty($job_data)) {
            $job_data = $entry;
        }

        $snapshot[] = sitepulse_ai_format_queue_payload($job_id, $job_data);
    }

    return $snapshot;
}

/**
 * Renders the observability widget summarizing job executions.
 *
 * @return void
 */
function sitepulse_ai_render_observability_widget() {
    $jobs_log       = sitepulse_ai_get_job_log();
    $metrics        = sitepulse_ai_calculate_job_metrics($jobs_log);
    $queue_snapshot = sitepulse_ai_get_queue_snapshot();
    $recent_jobs    = array_slice(array_reverse($jobs_log), 0, 5);

    $queue_count     = count($queue_snapshot);
    $failure_window  = isset($metrics['failure_rate']['window']) ? (int) $metrics['failure_rate']['window'] : 0;
    $failure_percent = isset($metrics['failure_rate']['value']) ? number_format_i18n($metrics['failure_rate']['value'] * 100, 1) : '0';
    $cost_value      = isset($metrics['cost']['value']) ? number_format_i18n((float) $metrics['cost']['value'], 2) : number_format_i18n(0, 2);
    $cost_threshold  = isset($metrics['cost']['threshold']) ? number_format_i18n((float) $metrics['cost']['threshold'], 2) : number_format_i18n(0, 2);
    $window_seconds  = isset($metrics['cost']['window_seconds']) ? (int) $metrics['cost']['window_seconds'] : 0;
    $avg_latency_ms  = isset($metrics['latency']['average_ms']) ? (float) $metrics['latency']['average_ms'] : 0.0;
    $avg_latency     = $avg_latency_ms > 0
        ? sprintf(esc_html__('%s ms', 'sitepulse'), number_format_i18n($avg_latency_ms, 0))
        : esc_html__('n/a', 'sitepulse');
    $window_label    = $window_seconds > 0 && function_exists('human_time_diff')
        ? sanitize_text_field(human_time_diff(time() - $window_seconds, time()))
        : esc_html__('fenêtre continue', 'sitepulse');

    $status_labels = [
        'queued'    => esc_html__('En attente', 'sitepulse'),
        'running'   => esc_html__('En cours', 'sitepulse'),
        'pending'   => esc_html__('En pause', 'sitepulse'),
        'retrying'  => esc_html__('Nouvelle tentative', 'sitepulse'),
        'success'   => esc_html__('Succès', 'sitepulse'),
        'failed'    => esc_html__('Échec', 'sitepulse'),
        'abandoned' => esc_html__('Abandonné', 'sitepulse'),
    ];

    echo '<section class="sitepulse-ai-observability">';
    echo '<h2>' . esc_html__('Observabilité des jobs IA', 'sitepulse') . '</h2>';
    echo '<ul class="sitepulse-ai-observability-summary">';
    echo '<li>' . sprintf(esc_html__('Jobs en file : %d', 'sitepulse'), $queue_count) . '</li>';
    echo '<li>' . sprintf(esc_html__('Taux d’échec (sur %d jobs) : %s%%', 'sitepulse'), $failure_window, $failure_percent) . '</li>';
    echo '<li>' . sprintf(esc_html__('Coût estimé : %1$s (seuil %2$s, %3$s)', 'sitepulse'), $cost_value, $cost_threshold, esc_html($window_label)) . '</li>';
    echo '<li>' . sprintf(esc_html__('Latence moyenne : %s', 'sitepulse'), esc_html($avg_latency)) . '</li>';
    echo '</ul>';

    if (empty($recent_jobs)) {
        echo '<p>' . esc_html__('Aucun job IA enregistré pour le moment.', 'sitepulse') . '</p>';
        echo '</section>';

        return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th scope="col">' . esc_html__('Job', 'sitepulse') . '</th>';
    echo '<th scope="col">' . esc_html__('Statut', 'sitepulse') . '</th>';
    echo '<th scope="col">' . esc_html__('Tentative', 'sitepulse') . '</th>';
    echo '<th scope="col">' . esc_html__('Modèle', 'sitepulse') . '</th>';
    echo '<th scope="col">' . esc_html__('Coût', 'sitepulse') . '</th>';
    echo '<th scope="col">' . esc_html__('Mis à jour', 'sitepulse') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($recent_jobs as $job) {
        $job_id        = isset($job['job_id']) ? (string) $job['job_id'] : '';
        $status        = isset($job['status']) ? (string) $job['status'] : '';
        $status_final  = isset($job['status_final']) ? (string) $job['status_final'] : '';
        $attempt       = isset($job['attempt']) ? (int) $job['attempt'] : 1;
        $model         = isset($job['model']) ? (string) $job['model'] : '';
        $cost          = isset($job['cost_estimated']) ? (float) $job['cost_estimated'] : 0.0;
        $updated_at    = isset($job['updated_at']) ? (int) $job['updated_at'] : (isset($job['timestamp']) ? (int) $job['timestamp'] : 0);
        $status_key    = '' !== $status_final ? $status_final : $status;
        $status_label  = isset($status_labels[$status_key]) ? $status_labels[$status_key] : ucfirst($status_key);
        $updated_value = $updated_at > 0 && function_exists('human_time_diff')
            ? sanitize_text_field(human_time_diff($updated_at, time()))
            : '';
        $updated_label = '' !== $updated_value
            ? sprintf(esc_html__('il y a %s', 'sitepulse'), $updated_value)
            : esc_html__('n/a', 'sitepulse');

        echo '<tr>';
        echo '<td>' . esc_html($job_id) . '</td>';
        echo '<td>' . esc_html($status_label) . '</td>';
        echo '<td>' . esc_html(number_format_i18n($attempt)) . '</td>';
        echo '<td>' . esc_html($model) . '</td>';
        echo '<td>' . esc_html(number_format_i18n($cost, 2)) . '</td>';
        echo '<td>' . esc_html($updated_label) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</section>';
}

/**
 * Formats queue metadata for responses and UI consumption.
 *
 * @param string                      $job_id   Job identifier.
 * @param array<string,mixed>|null    $job_data Job metadata.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_format_queue_payload($job_id, $job_data = null) {
    $job_id    = sanitize_key((string) $job_id);
    $job_data  = is_array($job_data) ? $job_data : [];
    $queue_ctx = isset($job_data['queue']) && is_array($job_data['queue'])
        ? sitepulse_ai_normalize_queue_context($job_data['queue'], $job_data)
        : sitepulse_ai_normalize_queue_context([], $job_data);
    $status    = isset($job_data['status']) ? sanitize_key((string) $job_data['status']) : (isset($job_data['status']) ? $job_data['status'] : 'queued');
    $position  = sitepulse_ai_calculate_queue_position($job_id);
    $max_attempts = sitepulse_ai_get_max_attempts();

    $priority_labels = [
        'high'   => esc_html__('Haute', 'sitepulse'),
        'normal' => esc_html__('Normale', 'sitepulse'),
        'low'    => esc_html__('Basse', 'sitepulse'),
    ];

    $status_labels = [
        'queued'    => esc_html__('En attente', 'sitepulse'),
        'pending'   => esc_html__('En pause', 'sitepulse'),
        'running'   => esc_html__('En cours', 'sitepulse'),
        'failed'    => esc_html__('Échec', 'sitepulse'),
        'completed' => esc_html__('Terminé', 'sitepulse'),
        'success'   => esc_html__('Succès', 'sitepulse'),
        'abandoned' => esc_html__('Abandonné', 'sitepulse'),
        'retrying'  => esc_html__('Nouvelle tentative', 'sitepulse'),
    ];

    $engine_labels = [
        'action_scheduler' => esc_html__('Action Scheduler', 'sitepulse'),
        'wp_cron'          => esc_html__('WP-Cron', 'sitepulse'),
        'immediate'        => esc_html__('Exécution immédiate', 'sitepulse'),
    ];

    $model_key   = isset($queue_ctx['model']) ? (string) $queue_ctx['model'] : '';
    $model_label = '';

    if (function_exists('sitepulse_get_ai_models')) {
        $models = sitepulse_get_ai_models();

        if (isset($models[$model_key]['label'])) {
            $model_label = (string) $models[$model_key]['label'];
        }
    }

    $next_attempt_at = isset($queue_ctx['next_attempt_at']) ? (int) $queue_ctx['next_attempt_at'] : 0;
    $next_attempt_display = '';

    if ($next_attempt_at > 0) {
        if (function_exists('date_i18n')) {
            $next_attempt_display = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_attempt_at);
        } else {
            $next_attempt_display = gmdate('Y-m-d H:i:s', $next_attempt_at);
        }
    }

    $created_at = isset($job_data['created_at']) ? (int) $job_data['created_at'] : time();
    $started_at = isset($job_data['started_at']) ? (int) $job_data['started_at'] : 0;
    $finished_at = isset($job_data['finished']) ? (int) $job_data['finished'] : 0;

    $quota_data = isset($queue_ctx['quota']) && is_array($queue_ctx['quota'])
        ? sitepulse_ai_normalize_quota_metadata($queue_ctx['quota'])
        : [];

    $quota_label = '';

    if (!empty($quota_data)) {
        $quota_value = isset($quota_data['label']) ? (string) $quota_data['label'] : '';

        if ('' === $quota_value && isset($quota_data['value'])) {
            $quota_value = (string) $quota_data['value'];
        }

        if ('' !== $quota_value) {
            $quota_label = sprintf(
                /* translators: %s: quota label */
                esc_html__('Quota : %s', 'sitepulse'),
                esc_html($quota_value)
            );
        }
    }

    $usage_data = isset($queue_ctx['usage']) && is_array($queue_ctx['usage'])
        ? sitepulse_ai_normalize_usage_metadata($queue_ctx['usage'])
        : [];

    $usage_label = '';

    if (!empty($usage_data)) {
        $usage_parts = [];

        foreach ($usage_data as $key => $value) {
            if (is_scalar($value) && '' !== (string) $value) {
                $key_label = is_string($key) ? sanitize_key($key) : (string) $key;

                if ('' === $key_label) {
                    $key_label = sanitize_text_field((string) $key);
                }

                $usage_parts[] = sanitize_text_field(sprintf('%s=%s', $key_label, (string) $value));
            }
        }

        if (!empty($usage_parts)) {
            $usage_label = sprintf(
                /* translators: %s: usage metrics */
                esc_html__('Consommation : %s', 'sitepulse'),
                esc_html(implode(', ', $usage_parts))
            );
        }
    }

    return [
        'id'                   => $job_id,
        'status'               => $status,
        'status_label'         => isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status),
        'position'             => $position['position'],
        'size'                 => $position['total'],
        'priority'             => $queue_ctx['priority'],
        'priority_label'       => isset($priority_labels[$queue_ctx['priority']]) ? $priority_labels[$queue_ctx['priority']] : $queue_ctx['priority'],
        'attempt'              => isset($job_data['attempt']) ? (int) $job_data['attempt'] : $queue_ctx['attempt'],
        'attempt_label'        => sprintf(
            /* translators: 1: attempt, 2: max attempts */
            esc_html__('Tentative %1$d sur %2$d', 'sitepulse'),
            isset($job_data['attempt']) ? (int) $job_data['attempt'] : $queue_ctx['attempt'],
            $max_attempts
        ),
        'max_attempts'         => $max_attempts,
        'engine'               => $queue_ctx['engine'],
        'engine_label'         => isset($engine_labels[$queue_ctx['engine']]) ? $engine_labels[$queue_ctx['engine']] : $queue_ctx['engine'],
        'model'                => $model_key,
        'model_label'          => $model_label,
        'next_attempt_at'      => $next_attempt_at,
        'next_attempt_display' => $next_attempt_display,
        'created_at'           => $created_at,
        'started_at'           => $started_at,
        'finished_at'          => $finished_at,
        'message'              => isset($job_data['message']) ? (string) $job_data['message'] : (isset($queue_ctx['message']) ? $queue_ctx['message'] : ''),
        'quota'                => $quota_data,
        'quota_label'          => $quota_label,
        'usage'                => $usage_data,
        'usage_label'          => $usage_label,
        'force_refresh'        => isset($queue_ctx['force_refresh']) ? (bool) $queue_ctx['force_refresh'] : (isset($job_data['force_refresh']) ? (bool) $job_data['force_refresh'] : false),
    ];
}

/**
 * Determines the maximum number of retry attempts.
 *
 * @return int
 */
function sitepulse_ai_get_max_attempts() {
    $max_attempts = (int) apply_filters('sitepulse_ai_queue_max_attempts', 5);

    if ($max_attempts <= 0) {
        $max_attempts = 1;
    }

    return $max_attempts;
}

/**
 * Calculates the exponential backoff delay for the provided attempt.
 *
 * @param int $attempt Attempt number (1-indexed).
 *
 * @return int Delay in seconds.
 */
function sitepulse_ai_calculate_retry_delay($attempt) {
    $attempt = max(1, (int) $attempt);
    $base    = (int) apply_filters('sitepulse_ai_queue_retry_base_delay', 60);

    if ($base <= 0) {
        $base = 60;
    }

    $max_delay = (int) apply_filters('sitepulse_ai_queue_retry_max_delay', 30 * MINUTE_IN_SECONDS);

    if ($max_delay <= 0) {
        $max_delay = 30 * MINUTE_IN_SECONDS;
    }

    $delay = (int) ($base * pow(2, $attempt - 1));

    if ($delay > $max_delay) {
        $delay = $max_delay;
    }

    return $delay;
}

/**
 * Schedules a job execution using the available queue engine.
 *
 * @param string               $job_id   Job identifier.
 * @param array<string,mixed>  $context  Queue context.
 * @param int                  $delay    Optional delay before execution.
 *
 * @return array<string,mixed>|WP_Error
 */
function sitepulse_ai_queue_schedule_action($job_id, array $context, $delay = 0) {
    $job_id = (string) $job_id;
    $delay  = max(0, (int) $delay);
    $timestamp = time() + $delay;
    $group = sitepulse_ai_get_queue_group();

    $context = sitepulse_ai_normalize_queue_context(array_merge($context, [
        'group'        => $group,
        'scheduled_at' => $timestamp,
    ]));

    sitepulse_ai_record_job_log($job_id, [
        'status'         => $delay > 0 ? 'scheduled' : 'queued',
        'attempt'        => isset($context['attempt']) ? $context['attempt'] : 1,
        'model'          => isset($context['model']) ? $context['model'] : '',
        'engine'         => isset($context['engine']) ? $context['engine'] : '',
        'timestamp'      => $timestamp,
        'cost_estimated' => sitepulse_ai_estimate_job_cost($context),
    ]);

    $args = [$job_id, $context];
    $context['args'] = $args;

    if (function_exists('as_enqueue_async_action')) {
        if ($delay > 0 && function_exists('as_schedule_single_action')) {
            $action_id = as_schedule_single_action($timestamp, 'sitepulse_run_ai_insight_job', $args, $group);
        } else {
            $action_id = as_enqueue_async_action('sitepulse_run_ai_insight_job', $args, $group);
        }

        if (is_wp_error($action_id) || empty($action_id)) {
            $error_message = is_wp_error($action_id) ? $action_id->get_error_message() : esc_html__('Impossible de planifier la tâche IA via Action Scheduler.', 'sitepulse');

            sitepulse_ai_record_job_log($job_id, [
                'status'       => 'abandoned',
                'status_final' => 'abandoned',
                'attempt'      => isset($context['attempt']) ? $context['attempt'] : 1,
                'model'        => isset($context['model']) ? $context['model'] : '',
                'engine'       => 'action_scheduler',
                'timestamp'    => time(),
                'message'      => $error_message,
            ]);

            return is_wp_error($action_id)
                ? $action_id
                : sitepulse_ai_create_wp_error(
                    'sitepulse_ai_queue_schedule_failed',
                    esc_html__('Impossible de planifier la tâche IA via Action Scheduler.', 'sitepulse'),
                    500
                );
        }

        $context['engine']    = 'action_scheduler';
        $context['action_id'] = (int) $action_id;

        return [
            'engine'  => 'action_scheduler',
            'action_id' => (int) $action_id,
            'context' => $context,
            'timestamp' => $timestamp,
        ];
    }

    $scheduled = wp_schedule_single_event($timestamp, 'sitepulse_run_ai_insight_job', $args);

    if (false === $scheduled) {
        sitepulse_ai_record_job_log($job_id, [
            'status'       => 'abandoned',
            'status_final' => 'abandoned',
            'attempt'      => isset($context['attempt']) ? $context['attempt'] : 1,
            'model'        => isset($context['model']) ? $context['model'] : '',
            'engine'       => 'wp_cron',
            'timestamp'    => time(),
            'message'      => esc_html__('Impossible de planifier la tâche IA via WP-Cron.', 'sitepulse'),
        ]);

        return sitepulse_ai_create_wp_error(
            'sitepulse_ai_queue_schedule_failed',
            esc_html__('Impossible de planifier la tâche IA via WP-Cron.', 'sitepulse'),
            500
        );
    }

    $context['engine'] = 'wp_cron';

    return [
        'engine'    => 'wp_cron',
        'context'   => $context,
        'timestamp' => $timestamp,
    ];
}

/**
 * Attempts to cancel a scheduled job when purging.
 *
 * @param string              $job_id Job identifier.
 * @param array<string,mixed> $context Queue context.
 *
 * @return void
 */
function sitepulse_ai_queue_clear_scheduled_action($job_id, array $context) {
    $args = isset($context['args']) && is_array($context['args'])
        ? $context['args']
        : [$job_id, $context];

    if (function_exists('as_unschedule_action')) {
        as_unschedule_action('sitepulse_run_ai_insight_job', $args, sitepulse_ai_get_queue_group());
    }

    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('sitepulse_run_ai_insight_job', $args);
    }
}

/**
 * Retries a queued job immediately.
 *
 * @param string $job_id Job identifier.
 *
 * @return true|WP_Error
 */
function sitepulse_ai_queue_retry_job($job_id) {
    $job_id = (string) $job_id;
    $job_data = sitepulse_ai_get_job_data($job_id);

    if (empty($job_data)) {
        return sitepulse_ai_create_wp_error('sitepulse_ai_missing_job', esc_html__('Tâche introuvable dans la file.', 'sitepulse'), 404);
    }

    $attempt = isset($job_data['attempt']) ? max(1, (int) $job_data['attempt']) + 1 : 1;
    $context = sitepulse_ai_normalize_queue_context(isset($job_data['queue']) ? $job_data['queue'] : [], $job_data);

    $context['attempt']      = $attempt;
    $context['next_attempt_at'] = 0;

    $job_data['status']      = 'queued';
    $job_data['attempt']     = $attempt;
    unset($job_data['message'], $job_data['retry_after'], $job_data['retry_at']);
    unset($job_data['final_status']);

    $schedule = sitepulse_ai_queue_schedule_action($job_id, $context, 0);

    if (is_wp_error($schedule)) {
        return $schedule;
    }

    if (isset($schedule['context'])) {
        $job_data['queue'] = sitepulse_ai_normalize_queue_context($schedule['context'], $job_data);
    }

    sitepulse_ai_save_job_data($job_id, $job_data);

    return true;
}

/**
 * Purges queued jobs matching the provided statuses.
 *
 * @param array<int,string>|null $statuses Optional statuses to purge. Null purges all queued jobs.
 *
 * @return int Number of purged jobs.
 */
function sitepulse_ai_queue_purge($statuses = null) {
    $index = sitepulse_ai_get_queue_index();
    $purged = 0;

    foreach ($index as $entry) {
        $status = isset($entry['status']) ? $entry['status'] : 'queued';

        if (is_array($statuses) && !in_array($status, $statuses, true)) {
            continue;
        }

        $job_id = $entry['id'];
        $job_data = sitepulse_ai_get_job_data($job_id);

        if (!empty($job_data)) {
            $context = isset($job_data['queue']) ? $job_data['queue'] : [];
            sitepulse_ai_queue_clear_scheduled_action($job_id, sitepulse_ai_normalize_queue_context($context, $job_data));
            sitepulse_ai_delete_job_data($job_id);
        } else {
            sitepulse_ai_remove_job_from_queue_index($job_id);
        }

        $purged++;
    }

    return $purged;
}
