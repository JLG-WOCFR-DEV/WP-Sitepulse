<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('AI Insights', 'sitepulse'),
        __('AI Insights', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-ai',
        'sitepulse_ai_insights_page'
    );
});
add_action('admin_enqueue_scripts', 'sitepulse_ai_insights_enqueue_assets');
add_action('wp_ajax_sitepulse_generate_ai_insight', 'sitepulse_generate_ai_insight');
add_action('wp_ajax_sitepulse_get_ai_insight_status', 'sitepulse_get_ai_insight_status');
add_action('wp_ajax_sitepulse_run_ai_insight_job', 'sitepulse_ai_handle_async_job_request');
add_action('wp_ajax_nopriv_sitepulse_run_ai_insight_job', 'sitepulse_ai_handle_async_job_request');
add_action('sitepulse_run_ai_insight_job', 'sitepulse_run_ai_insight_job', 10, 2);
add_action('wp_ajax_sitepulse_save_ai_history_note', 'sitepulse_ai_save_history_note');
add_action('admin_notices', 'sitepulse_ai_render_error_notices');
add_action('admin_notices', 'sitepulse_ai_render_alert_notices');
add_action('rest_api_init', 'sitepulse_ai_register_rest_routes');
add_action('sitepulse_ai_job_failed', 'sitepulse_ai_handle_job_failed_alert', 10, 3);
add_action('sitepulse_ai_quota_warning', 'sitepulse_ai_handle_quota_warning_alert', 10, 2);

if (!defined('SITEPULSE_TRANSIENT_AI_INSIGHT_JOB_PREFIX')) {
    define('SITEPULSE_TRANSIENT_AI_INSIGHT_JOB_PREFIX', 'sitepulse_ai_job_');
}

if (!defined('SITEPULSE_OPTION_AI_INSIGHT_ERRORS')) {
    define('SITEPULSE_OPTION_AI_INSIGHT_ERRORS', 'sitepulse_ai_insight_errors');
}

if (!defined('SITEPULSE_OPTION_AI_HISTORY')) {
    define('SITEPULSE_OPTION_AI_HISTORY', 'sitepulse_ai_history');
}

if (!defined('SITEPULSE_OPTION_AI_HISTORY_NOTES')) {
    define('SITEPULSE_OPTION_AI_HISTORY_NOTES', 'sitepulse_ai_history_notes');
}

if (!defined('SITEPULSE_OPTION_AI_JOB_SECRET')) {
    define('SITEPULSE_OPTION_AI_JOB_SECRET', 'sitepulse_ai_job_secret');
}

if (!defined('SITEPULSE_OPTION_AI_RETRY_AFTER')) {
    define('SITEPULSE_OPTION_AI_RETRY_AFTER', 'sitepulse_ai_retry_after');
}

if (!defined('SITEPULSE_OPTION_AI_QUEUE_INDEX')) {
    define('SITEPULSE_OPTION_AI_QUEUE_INDEX', 'sitepulse_ai_queue_index');
}

if (!defined('SITEPULSE_OPTION_AI_JOBS_LOG')) {
    define('SITEPULSE_OPTION_AI_JOBS_LOG', 'sitepulse_ai_jobs_log');
}

if (!defined('SITEPULSE_OPTION_AI_ALERT_NOTICES')) {
    define('SITEPULSE_OPTION_AI_ALERT_NOTICES', 'sitepulse_ai_alert_notices');
}

/**
 * Returns the HTML tags allowed in AI insight content.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_get_allowed_insight_html_tags() {
    $allowed_tags = [
        'p'          => [],
        'br'         => [],
        'strong'     => [],
        'em'         => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'blockquote' => [],
        'code'       => [],
        'pre'        => [],
        'a'          => [
            'href'   => true,
            'rel'    => true,
            'target' => true,
            'title'  => true,
        ],
    ];

    /**
     * Filters the HTML tags allowed when sanitizing AI insight content.
     *
     * @param array<string,mixed> $allowed_tags Allowed HTML tags.
     */
    return (array) apply_filters('sitepulse_ai_insight_allowed_tags', $allowed_tags);
}

/**
 * Sanitizes AI insight HTML content.
 *
 * @param string $html Raw HTML content.
 *
 * @return string Sanitized HTML.
 */
function sitepulse_ai_sanitize_insight_html($html) {
    $html = (string) $html;

    if ('' === $html) {
        return '';
    }

    $sanitized = wp_kses($html, sitepulse_ai_get_allowed_insight_html_tags());

    return trim($sanitized);
}

/**
 * Sanitizes AI insight plain text content.
 *
 * @param string $text Raw text content.
 *
 * @return string Sanitized plain text.
 */
function sitepulse_ai_sanitize_insight_text($text) {
    $text = (string) $text;

    if ('' === $text) {
        return '';
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = wp_strip_all_tags($text, true);
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

/**
 * Builds sanitized HTML and text variants for AI insights.
 *
 * @param string $text Raw text content.
 * @param string $html Optional raw HTML content.
 *
 * @return array{text:string,html:string}
 */
function sitepulse_ai_prepare_insight_variants($text, $html = '') {
    $raw_text = (string) $text;
    $raw_html = (string) $html;

    if ('' === $raw_html && '' !== $raw_text) {
        $raw_html = wpautop($raw_text);
    }

    $sanitized_html = sitepulse_ai_sanitize_insight_html($raw_html);

    $text_source = '' !== $raw_text ? $raw_text : $sanitized_html;
    $sanitized_text = sitepulse_ai_sanitize_insight_text($text_source);

    if ('' === $sanitized_text && '' !== $sanitized_html) {
        $sanitized_text = sitepulse_ai_sanitize_insight_text($sanitized_html);
    }

    if ('' === $sanitized_html && '' !== $sanitized_text) {
        $sanitized_html = sitepulse_ai_sanitize_insight_html(wpautop($sanitized_text));
    }

    return [
        'text' => $sanitized_text,
        'html' => $sanitized_html,
    ];
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

    $request_args = [
        'timeout'  => 5,
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

/**
 * Registers REST API routes to manage the AI queue.
 *
 * @return void
 */
function sitepulse_ai_register_rest_routes() {
    if (!function_exists('register_rest_route') || !class_exists('WP_REST_Server')) {
        return;
    }

    register_rest_route(
        'sitepulse/v1',
        '/ai/queue',
        [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => 'sitepulse_ai_rest_list_queue',
                'permission_callback' => 'sitepulse_ai_rest_permission_check',
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => 'sitepulse_ai_rest_purge_queue',
                'permission_callback' => 'sitepulse_ai_rest_permission_check',
            ],
        ]
    );

    register_rest_route(
        'sitepulse/v1',
        '/ai/queue/(?P<job_id>[a-zA-Z0-9_\-]+)',
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'sitepulse_ai_rest_retry_job',
            'permission_callback' => 'sitepulse_ai_rest_permission_check',
            'args'                => [
                'job_id' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]
    );

    register_rest_route(
        'sitepulse/v1',
        '/ai/jobs',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'sitepulse_ai_rest_list_jobs',
            'permission_callback' => 'sitepulse_ai_rest_permission_check',
        ]
    );
}

/**
 * REST permission callback for queue endpoints.
 *
 * @return bool
 */
function sitepulse_ai_rest_permission_check() {
    return current_user_can(sitepulse_get_capability());
}

/**
 * Returns the queued jobs snapshot over REST.
 *
 * @return WP_REST_Response
 */
function sitepulse_ai_rest_list_queue() {
    return new WP_REST_Response([
        'jobs' => sitepulse_ai_get_queue_snapshot(),
    ]);
}

/**
 * Returns the job log entries over REST.
 *
 * @return WP_REST_Response
 */
function sitepulse_ai_rest_list_jobs() {
    $entries = sitepulse_ai_get_job_log();

    return new WP_REST_Response([
        'jobs'    => sitepulse_ai_prepare_jobs_for_rest($entries),
        'metrics' => sitepulse_ai_calculate_job_metrics($entries),
    ]);
}

/**
 * Prepares job log entries for REST responses.
 *
 * @param array<int,array<string,mixed>> $entries Log entries.
 *
 * @return array<int,array<string,mixed>>
 */
function sitepulse_ai_prepare_jobs_for_rest(array $entries) {
    $prepared = [];

    foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry['job_id'])) {
            continue;
        }

        $history = [];

        if (isset($entry['history']) && is_array($entry['history'])) {
            foreach ($entry['history'] as $history_entry) {
                if (!is_array($history_entry) || !isset($history_entry['status'])) {
                    continue;
                }

                $history[] = [
                    'status'    => sanitize_key((string) $history_entry['status']),
                    'attempt'   => isset($history_entry['attempt']) ? (int) $history_entry['attempt'] : 1,
                    'timestamp' => isset($history_entry['timestamp']) ? (int) $history_entry['timestamp'] : 0,
                ];
            }
        }

        $prepared[] = [
            'job_id'         => $entry['job_id'],
            'status'         => isset($entry['status']) ? $entry['status'] : '',
            'status_final'   => isset($entry['status_final']) ? $entry['status_final'] : '',
            'attempt'        => isset($entry['attempt']) ? (int) $entry['attempt'] : 1,
            'model'          => isset($entry['model']) ? $entry['model'] : '',
            'engine'         => isset($entry['engine']) ? $entry['engine'] : '',
            'cost_estimated' => isset($entry['cost_estimated']) ? (float) $entry['cost_estimated'] : 0.0,
            'quota_consumed' => isset($entry['quota_consumed']) ? (float) $entry['quota_consumed'] : 0.0,
            'latency_ms'     => isset($entry['latency_ms']) ? (float) $entry['latency_ms'] : null,
            'timestamp'      => isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0,
            'created_at'     => isset($entry['created_at']) ? (int) $entry['created_at'] : 0,
            'updated_at'     => isset($entry['updated_at']) ? (int) $entry['updated_at'] : 0,
            'message'        => isset($entry['message']) ? $entry['message'] : '',
            'usage'          => isset($entry['usage']) && is_array($entry['usage']) ? $entry['usage'] : [],
            'history'        => $history,
        ];
    }

    return $prepared;
}

/**
 * Retries a queued job via REST.
 *
 * @param WP_REST_Request $request Request instance.
 *
 * @return WP_REST_Response|WP_Error
 */
function sitepulse_ai_rest_retry_job(WP_REST_Request $request) {
    $job_id = $request->get_param('job_id');

    $retry = sitepulse_ai_queue_retry_job($job_id);

    if (is_wp_error($retry)) {
        return $retry;
    }

    return new WP_REST_Response([
        'job' => sitepulse_ai_format_queue_payload($job_id, sitepulse_ai_get_job_data($job_id)),
    ]);
}

/**
 * Purges the queue via REST.
 *
 * @return WP_REST_Response
 */
function sitepulse_ai_rest_purge_queue() {
    $purged = sitepulse_ai_queue_purge();

    return new WP_REST_Response([
        'purged' => $purged,
        'jobs'   => sitepulse_ai_get_queue_snapshot(),
    ]);
}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command')) {
    /**
     * WP-CLI command for rotating the AI job secret.
     */
    class SitePulse_AI_Secret_CLI_Command extends WP_CLI_Command {
        /**
         * Regenerates the secret used to trigger AI insight jobs.
         *
         * ## EXAMPLES
         *
         *     wp sitepulse ai secret regenerate
         */
        public function regenerate() {
            if (!function_exists('sitepulse_ai_regenerate_job_secret')) {
                WP_CLI::error('La fonction de régénération du secret IA est indisponible.');
            }

            $secret = sitepulse_ai_regenerate_job_secret();

            WP_CLI::success(sprintf('Nouveau secret IA généré : %s', $secret));
        }
    }

    /**
     * WP-CLI command for managing the SitePulse AI queue.
     */
    class SitePulse_AI_Queue_CLI_Command extends WP_CLI_Command {
        /**
         * Lists queued AI jobs.
         *
         * ## EXAMPLES
         *
         *     wp sitepulse ai queue list
         */
        public function list_() {
            $jobs = sitepulse_ai_get_queue_snapshot();

            if (empty($jobs)) {
                WP_CLI::success('La file AI est vide.');

                return;
            }

            $items = [];

            foreach ($jobs as $job) {
                $items[] = [
                    'id'        => isset($job['id']) ? $job['id'] : '',
                    'status'    => isset($job['status_label']) ? $job['status_label'] : (isset($job['status']) ? $job['status'] : ''),
                    'priority'  => isset($job['priority_label']) ? $job['priority_label'] : (isset($job['priority']) ? $job['priority'] : ''),
                    'attempt'   => isset($job['attempt']) ? $job['attempt'] : '',
                    'position'  => isset($job['position']) && isset($job['size']) ? sprintf('%d/%d', $job['position'], $job['size']) : '',
                    'engine'    => isset($job['engine_label']) ? $job['engine_label'] : (isset($job['engine']) ? $job['engine'] : ''),
                    'next_run'  => isset($job['next_attempt_display']) ? $job['next_attempt_display'] : '',
                ];
            }

            WP_CLI\Utils\format_items('table', $items, ['id', 'status', 'priority', 'attempt', 'position', 'engine', 'next_run']);
        }

        /**
         * Retries a failed or pending AI job immediately.
         *
         * ## OPTIONS
         *
         * <job-id>
         * : Identifiant de la tâche.
         */
        public function retry($args) {
            if (empty($args[0])) {
                WP_CLI::error('Veuillez fournir un identifiant de tâche.');
            }

            $job_id = sanitize_key($args[0]);
            $retry  = sitepulse_ai_queue_retry_job($job_id);

            if (is_wp_error($retry)) {
                WP_CLI::error($retry->get_error_message());
            }

            WP_CLI::success(sprintf('Relance planifiée pour la tâche %s.', $job_id));
        }

        /**
         * Purges the AI queue.
         */
        public function purge() {
            $purged = sitepulse_ai_queue_purge();

            WP_CLI::success(sprintf('%d tâche(s) supprimée(s) de la file.', (int) $purged));
        }
    }

    WP_CLI::add_command('sitepulse ai secret', 'SitePulse_AI_Secret_CLI_Command');
    WP_CLI::add_command('sitepulse ai queue', 'SitePulse_AI_Queue_CLI_Command');
}

/**
 * Parses usage headers returned by the Gemini API.
 *
 * @param array|ArrayAccess|mixed $headers HTTP headers.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_parse_response_usage($headers) {
    $usage = [];

    if (is_object($headers) && method_exists($headers, 'getAll')) {
        $headers = $headers->getAll();
    }

    if (!is_array($headers)) {
        return $usage;
    }

    $map = [
        'x-ratelimit-remaining' => 'remaining',
        'x-ratelimit-limit'     => 'limit',
        'x-ratelimit-reset'     => 'reset',
        'x-ratelimit-usage'     => 'usage',
        'x-ratelimit-cost'      => 'cost',
        'x-usage-tokens'        => 'tokens',
    ];

    foreach ($headers as $name => $value) {
        $key = strtolower((string) $name);

        if (!isset($map[$key])) {
            continue;
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_scalar($value)) {
            $usage[$map[$key]] = $value;
        }
    }

    return sitepulse_ai_normalize_usage_metadata($usage);
}

/**
 * Logs execution metrics for completed AI jobs.
 *
 * @param string               $job_id   Job identifier.
 * @param array<string,mixed>  $job_data Job metadata.
 * @param array<string,mixed>  $usage    Usage metadata.
 *
 * @return void
 */
function sitepulse_ai_log_execution_metrics($job_id, array $job_data, array $usage = []) {
    if (!function_exists('sitepulse_log')) {
        return;
    }

    $queue_context = isset($job_data['queue']) ? sitepulse_ai_normalize_queue_context($job_data['queue'], $job_data) : sitepulse_ai_normalize_queue_context([], $job_data);
    $usage = sitepulse_ai_normalize_usage_metadata($usage);
    $duration = 0;

    if (isset($job_data['started_at'], $job_data['finished'])) {
        $duration = max(0, (int) $job_data['finished'] - (int) $job_data['started_at']);
    }

    $message = sprintf(
        'AI Insights job %s — attempt %d/%d (%s, engine=%s, duration=%ss)',
        $job_id,
        isset($job_data['attempt']) ? (int) $job_data['attempt'] : $queue_context['attempt'],
        sitepulse_ai_get_max_attempts(),
        isset($queue_context['priority']) ? $queue_context['priority'] : 'normal',
        isset($queue_context['engine']) ? $queue_context['engine'] : 'wp_cron',
        $duration
    );

    if (!empty($queue_context['quota']) && isset($queue_context['quota']['label'])) {
        $message .= ' — quota=' . sanitize_text_field((string) $queue_context['quota']['label']);
    }

    if (!empty($usage)) {
        $usage_parts = [];

        foreach ($usage as $key => $value) {
            if (is_scalar($value)) {
                $usage_parts[] = $key . '=' . $value;
            }
        }

        if (!empty($usage_parts)) {
            $message .= ' — usage=' . implode(',', $usage_parts);
        }
    }

    sitepulse_log($message, 'INFO');
}

/**
 * Returns the maximum number of AI history entries to keep.
 *
 * @return int
 */
function sitepulse_ai_get_history_max_entries() {
    $max_entries = (int) apply_filters('sitepulse_ai_history_max_entries', 20);

    if ($max_entries <= 0) {
        $max_entries = 20;
    }

    return $max_entries;
}

/**
 * Generates a deterministic identifier for a history entry.
 *
 * @param array<string,mixed> $entry History entry data.
 *
 * @return string
 */
function sitepulse_ai_generate_history_entry_id(array $entry) {
    $parts = [
        isset($entry['timestamp']) ? (string) absint($entry['timestamp']) : '',
        isset($entry['model']) && is_array($entry['model']) && isset($entry['model']['key'])
            ? sanitize_text_field((string) $entry['model']['key'])
            : (isset($entry['model_key']) ? sanitize_text_field((string) $entry['model_key']) : ''),
        isset($entry['rate_limit']) && is_array($entry['rate_limit']) && isset($entry['rate_limit']['key'])
            ? sanitize_text_field((string) $entry['rate_limit']['key'])
            : (isset($entry['rate_limit_key']) ? sanitize_text_field((string) $entry['rate_limit_key']) : ''),
        isset($entry['text']) ? sitepulse_ai_sanitize_insight_text($entry['text']) : '',
    ];

    $hash = md5(implode('|', $parts));

    return substr($hash, 0, 12);
}

/**
 * Retrieves stored notes keyed by history entry identifiers.
 *
 * @return array<string,string>
 */
function sitepulse_ai_get_history_notes() {
    if (!function_exists('get_option')) {
        return [];
    }

    $notes = get_option(SITEPULSE_OPTION_AI_HISTORY_NOTES, []);

    if (!is_array($notes)) {
        return [];
    }

    $sanitized = [];

    foreach ($notes as $entry_id => $note) {
        $key = sanitize_key((string) $entry_id);

        if ('' === $key) {
            continue;
        }

        $sanitized[$key] = sanitize_textarea_field((string) $note);
    }

    return $sanitized;
}

/**
 * Persists the given history notes array.
 *
 * @param array<string,string> $notes Notes keyed by entry identifier.
 *
 * @return void
 */
function sitepulse_ai_update_history_notes(array $notes) {
    if (!function_exists('update_option')) {
        return;
    }

    $normalized = [];

    foreach ($notes as $entry_id => $note) {
        $key = sanitize_key((string) $entry_id);

        if ('' === $key) {
            continue;
        }

        $value = sanitize_textarea_field((string) $note);

        if ('' === $value) {
            continue;
        }

        $normalized[$key] = $value;
    }

    update_option(SITEPULSE_OPTION_AI_HISTORY_NOTES, $normalized, false);
}

/**
 * Removes notes that no longer match existing history entries.
 *
 * @param array<int,array<string,mixed>> $history_entries Stored history entries.
 *
 * @return void
 */
function sitepulse_ai_prune_history_notes(array $history_entries) {
    if (!function_exists('update_option')) {
        return;
    }

    $notes = sitepulse_ai_get_history_notes();

    if (empty($notes)) {
        return;
    }

    $valid_ids = [];

    foreach ($history_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entry_id = '';

        if (isset($entry['id'])) {
            $entry_id = (string) $entry['id'];
        }

        if ('' === $entry_id) {
            $entry_id = sitepulse_ai_generate_history_entry_id($entry);
        }

        $entry_id = sanitize_key($entry_id);

        if ('' === $entry_id) {
            continue;
        }

        $valid_ids[$entry_id] = true;
    }

    $cleaned_notes = array_intersect_key($notes, $valid_ids);

    if ($cleaned_notes !== $notes) {
    update_option(SITEPULSE_OPTION_AI_HISTORY_NOTES, $cleaned_notes, false);
    }
}

/**
 * Prepares export-ready rows from history entries.
 *
 * @param array<int,array<string,mixed>> $entries History entries.
 *
 * @return array<int,array<string,string|int>>
 */
function sitepulse_ai_prepare_history_export_rows(array $entries) {
    $rows = [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $normalized_entry = sitepulse_ai_normalize_history_entry($entry);

        if (null === $normalized_entry) {
            continue;
        }

        if (isset($entry['note'])) {
            $normalized_entry['note'] = sanitize_textarea_field((string) $entry['note']);
        }

        $timestamp = isset($normalized_entry['timestamp']) ? (int) $normalized_entry['timestamp'] : 0;
        $display   = '';
        $iso8601   = '';

        if ($timestamp > 0) {
            if (function_exists('date_i18n')) {
                $display = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
            } else {
                $display = gmdate('Y-m-d H:i:s', $timestamp);
            }

            $iso8601 = gmdate('c', $timestamp);
        }

        $rows[] = [
            'id'                => isset($normalized_entry['id']) ? (string) $normalized_entry['id'] : sitepulse_ai_generate_history_entry_id($normalized_entry),
            'timestamp'         => $timestamp,
            'timestamp_display' => $display,
            'timestamp_iso'     => $iso8601,
            'model'             => isset($normalized_entry['model']['label']) ? (string) $normalized_entry['model']['label'] : '',
            'model_key'         => isset($normalized_entry['model']['key']) ? (string) $normalized_entry['model']['key'] : '',
            'rate_limit'        => isset($normalized_entry['rate_limit']['label']) ? (string) $normalized_entry['rate_limit']['label'] : '',
            'rate_limit_key'    => isset($normalized_entry['rate_limit']['key']) ? (string) $normalized_entry['rate_limit']['key'] : '',
            'text'              => isset($normalized_entry['text']) ? (string) $normalized_entry['text'] : '',
            'note'              => isset($normalized_entry['note']) ? (string) $normalized_entry['note'] : '',
        ];
    }

    return $rows;
}

/**
 * Normalizes a raw AI history entry.
 *
 * @param array<string,mixed> $entry Raw history entry data.
 *
 * @return array{
 *     id:string,
 *     text:string,
 *     html:string,
 *     timestamp:int,
 *     model:array{key:string,label:string},
 *     rate_limit:array{key:string,label:string},
 *     note:string
 * }|null
 */
function sitepulse_ai_normalize_history_entry($entry) {
    if (!is_array($entry)) {
        return null;
    }

    $variants = sitepulse_ai_prepare_insight_variants(
        isset($entry['text']) ? (string) $entry['text'] : '',
        isset($entry['html']) ? (string) $entry['html'] : ''
    );

    if ('' === $variants['text']) {
        return null;
    }

    $timestamp = isset($entry['timestamp']) ? absint($entry['timestamp']) : 0;

    $model_key   = '';
    $model_label = '';

    if (isset($entry['model']) && is_array($entry['model'])) {
        if (isset($entry['model']['key'])) {
            $model_key = sanitize_text_field((string) $entry['model']['key']);
        }

        if (isset($entry['model']['label'])) {
            $model_label = sanitize_text_field((string) $entry['model']['label']);
        }
    } else {
        if (isset($entry['model_key'])) {
            $model_key = sanitize_text_field((string) $entry['model_key']);
        }

        if (isset($entry['model_label'])) {
            $model_label = sanitize_text_field((string) $entry['model_label']);
        }
    }

    if ('' === $model_label) {
        $model_label = $model_key;
    }

    $rate_limit_key   = '';
    $rate_limit_label = '';

    if (isset($entry['rate_limit']) && is_array($entry['rate_limit'])) {
        if (isset($entry['rate_limit']['key'])) {
            $rate_limit_key = sanitize_text_field((string) $entry['rate_limit']['key']);
        }

        if (isset($entry['rate_limit']['label'])) {
            $rate_limit_label = sanitize_text_field((string) $entry['rate_limit']['label']);
        }
    } else {
        if (isset($entry['rate_limit_key'])) {
            $rate_limit_key = sanitize_text_field((string) $entry['rate_limit_key']);
        }

        if (isset($entry['rate_limit_label'])) {
            $rate_limit_label = sanitize_text_field((string) $entry['rate_limit_label']);
        }
    }

    if ('' === $rate_limit_label) {
        $rate_limit_label = $rate_limit_key;
    }

    $normalized = [
        'text'      => $variants['text'],
        'html'      => $variants['html'],
        'timestamp' => $timestamp,
        'model'     => [
            'key'   => $model_key,
            'label' => $model_label,
        ],
        'rate_limit' => [
            'key'   => $rate_limit_key,
            'label' => $rate_limit_label,
        ],
        'note' => '',
    ];

    $normalized['id'] = sitepulse_ai_generate_history_entry_id($normalized);

    return $normalized;
}

/**
 * Appends an AI insight result to the persistent history option.
 *
 * @param array<string,mixed> $entry History entry data.
 *
 * @return void
 */
function sitepulse_ai_record_history_entry(array $entry) {
    if (!function_exists('get_option') || !function_exists('update_option')) {
        return;
    }

    $normalized_entry = sitepulse_ai_normalize_history_entry($entry);

    if (null === $normalized_entry) {
        return;
    }

    $history = get_option(SITEPULSE_OPTION_AI_HISTORY, []);

    if (!is_array($history)) {
        $history = [];
    }

    $history[]    = $normalized_entry;
    $max_entries  = sitepulse_ai_get_history_max_entries();
    $history_size = count($history);

    if ($max_entries > 0 && $history_size > $max_entries) {
        $history = array_slice($history, -$max_entries, $max_entries, true);
    }

    $history = array_values($history);

    update_option(SITEPULSE_OPTION_AI_HISTORY, $history, false);

    sitepulse_ai_prune_history_notes($history);
}

/**
 * Retrieves the stored AI insight history entries ordered from newest to oldest.
 *
 * @return array<int,array{
 *     id:string,
 *     text:string,
 *     html:string,
 *     timestamp:int,
 *     model:array{key:string,label:string},
 *     rate_limit:array{key:string,label:string},
 *     note:string,
 *     timestamp_display:string,
 *     timestamp_iso:string
 * }>
 */
function sitepulse_ai_get_history_entries() {
    $history = function_exists('get_option') ? get_option(SITEPULSE_OPTION_AI_HISTORY, []) : [];

    if (!is_array($history)) {
        $history = [];
    }

    $normalized = [];
    $notes      = sitepulse_ai_get_history_notes();

    foreach ($history as $entry) {
        $normalized_entry = sitepulse_ai_normalize_history_entry($entry);

        if (null === $normalized_entry) {
            continue;
        }

        $entry_id = isset($normalized_entry['id']) ? sanitize_key((string) $normalized_entry['id']) : '';

        if ('' !== $entry_id && isset($notes[$entry_id])) {
            $normalized_entry['note'] = sanitize_textarea_field((string) $notes[$entry_id]);
        }

        $timestamp = isset($normalized_entry['timestamp']) ? (int) $normalized_entry['timestamp'] : 0;
        $display   = '';
        $iso8601   = '';

        if ($timestamp > 0) {
            if (function_exists('date_i18n')) {
                $display = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
            } else {
                $display = gmdate('Y-m-d H:i:s', $timestamp);
            }

            $iso8601 = gmdate('c', $timestamp);
        }

        $normalized_entry['timestamp_display'] = $display;
        $normalized_entry['timestamp_iso']     = $iso8601;

        $normalized[] = $normalized_entry;
    }

    if (!empty($normalized)) {
        usort($normalized, function ($a, $b) {
            $a_time = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
            $b_time = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;

            if ($a_time === $b_time) {
                return 0;
            }

            return ($a_time < $b_time) ? 1 : -1;
        });
    }

    return array_values($normalized);
}

/**
 * Extracts unique filter options from the AI history entries.
 *
 * @param array<int,array<string,mixed>> $entries History entries.
 * @param string                         $key     Nested key to extract (e.g. 'model').
 *
 * @return array<int,array{key:string,label:string}>
 */
function sitepulse_ai_get_history_filter_options(array $entries, $key) {
    $options = [];

    foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry[$key]) || !is_array($entry[$key])) {
            continue;
        }

        $value = isset($entry[$key]['key']) ? (string) $entry[$key]['key'] : '';

        if ('' === $value || isset($options[$value])) {
            continue;
        }

        $label = isset($entry[$key]['label']) ? (string) $entry[$key]['label'] : $value;

        $options[$value] = [
            'key'   => $value,
            'label' => $label,
        ];
    }

    return array_values($options);
}

/**
 * Records a critical AI Insights error for logging and admin notice purposes.
 *
 * @param string   $message     Error message.
 * @param int|null $status_code Optional HTTP status code or contextual code.
 *
 * @return void
 */
function sitepulse_ai_queue_admin_alert_notice($message, $type = 'warning') {
    $message = wp_strip_all_tags((string) $message);

    if ('' === $message) {
        return;
    }

    $type = sanitize_key((string) $type);
    $allowed_types = ['error', 'warning', 'success', 'info'];

    if (!in_array($type, $allowed_types, true)) {
        $type = 'warning';
    }

    $entry = [
        'message'   => $message,
        'type'      => $type,
        'timestamp' => time(),
    ];

    if (!isset($GLOBALS['sitepulse_ai_alert_notices']) || !is_array($GLOBALS['sitepulse_ai_alert_notices'])) {
        $GLOBALS['sitepulse_ai_alert_notices'] = [];
    }

    $GLOBALS['sitepulse_ai_alert_notices'][] = $entry;

    if (function_exists('get_option') && function_exists('update_option')) {
        $stored = get_option(SITEPULSE_OPTION_AI_ALERT_NOTICES, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $stored[] = $entry;

        if (count($stored) > 10) {
            $stored = array_slice($stored, -10);
        }

        update_option(SITEPULSE_OPTION_AI_ALERT_NOTICES, $stored, false);
    }
}

/**
 * Renders queued alert notices in the admin area.
 *
 * @return void
 */
function sitepulse_ai_render_alert_notices() {
    if (!function_exists('current_user_can') || !current_user_can(sitepulse_get_capability())) {
        return;
    }

    $notices = [];

    if (isset($GLOBALS['sitepulse_ai_alert_notices']) && is_array($GLOBALS['sitepulse_ai_alert_notices'])) {
        $notices = array_merge($notices, $GLOBALS['sitepulse_ai_alert_notices']);
        $GLOBALS['sitepulse_ai_alert_notices'] = [];
    }

    if (function_exists('get_option')) {
        $stored = get_option(SITEPULSE_OPTION_AI_ALERT_NOTICES, []);

        if (is_array($stored)) {
            $notices = array_merge($notices, $stored);
        }

        if (!empty($stored) && function_exists('delete_option')) {
            delete_option(SITEPULSE_OPTION_AI_ALERT_NOTICES);
        }
    }

    if (empty($notices)) {
        return;
    }

    $seen_messages = [];

    foreach ($notices as $notice) {
        if (!is_array($notice) || !isset($notice['message'])) {
            continue;
        }

        $message = trim((string) $notice['message']);

        if ('' === $message || isset($seen_messages[$message])) {
            continue;
        }

        $seen_messages[$message] = true;

        $type  = isset($notice['type']) ? sanitize_key((string) $notice['type']) : 'warning';
        $class = 'notice notice-warning';

        switch ($type) {
            case 'error':
                $class = 'notice notice-error';
                break;
            case 'success':
                $class = 'notice notice-success';
                break;
            case 'info':
                $class = 'notice notice-info';
                break;
        }

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
    }
}

function sitepulse_ai_record_critical_error($message, $status_code = null) {
    $normalized_message = trim(wp_strip_all_tags((string) $message));

    if ('' === $normalized_message) {
        return;
    }

    if ($status_code !== null) {
        $normalized_message = sprintf(
            /* translators: 1: Status or error code, 2: error details. */
            esc_html__('Code %1$d — %2$s', 'sitepulse'),
            (int) $status_code,
            $normalized_message
        );
    }

    $log_message = 'AI Insights: ' . $normalized_message;

    if (function_exists('sitepulse_log')) {
        sitepulse_log($log_message, 'ERROR');
    }

    if (function_exists('error_log')) {
        error_log('SitePulse ' . $log_message);
    }

    static $recorded = [];

    if (isset($recorded[$normalized_message])) {
        return;
    }

    $recorded[$normalized_message] = true;

    if (!isset($GLOBALS['sitepulse_ai_runtime_notices']) || !is_array($GLOBALS['sitepulse_ai_runtime_notices'])) {
        $GLOBALS['sitepulse_ai_runtime_notices'] = [];
    }

    $GLOBALS['sitepulse_ai_runtime_notices'][] = $normalized_message;

    if (function_exists('get_option') && function_exists('update_option')) {
        $stored = get_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $stored[] = [
            'message'   => $normalized_message,
            'timestamp' => time(),
        ];

        if (count($stored) > 10) {
            $stored = array_slice($stored, -10, 10, true);
        }

        update_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS, $stored, false);
    }
}

/**
 * Renders stored AI Insights error notices in the admin area.
 *
 * @return void
 */
function sitepulse_ai_render_error_notices() {
    if (!function_exists('current_user_can') || !function_exists('esc_html')) {
        return;
    }

    if (!current_user_can(sitepulse_get_capability())) {
        return;
    }

    $messages = [];

    if (isset($GLOBALS['sitepulse_ai_runtime_notices']) && is_array($GLOBALS['sitepulse_ai_runtime_notices'])) {
        $messages = array_merge($messages, $GLOBALS['sitepulse_ai_runtime_notices']);
    }

    if (function_exists('get_option')) {
        $stored = get_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS, []);

        if (is_array($stored)) {
            foreach ($stored as $entry) {
                if (!is_array($entry) || !isset($entry['message'])) {
                    continue;
                }

                $messages[] = (string) $entry['message'];
            }
        }

        if (function_exists('delete_option') && !empty($stored)) {
            delete_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS);
        }
    }

    $messages = array_values(array_unique(array_filter(array_map('trim', $messages))));

    if (empty($messages)) {
        return;
    }

    foreach ($messages as $message) {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    }
}

/**
 * Retrieves the sanitized AI rate limit option value.
 *
 * @return string
 */
function sitepulse_ai_get_current_rate_limit_value() {
    $default = function_exists('sitepulse_get_default_ai_rate_limit')
        ? sitepulse_get_default_ai_rate_limit()
        : 'week';

    $value = get_option(SITEPULSE_OPTION_AI_RATE_LIMIT, $default);

    if (!is_string($value)) {
        return $default;
    }

    $value = strtolower(trim($value));
    $choices = function_exists('sitepulse_get_ai_rate_limit_choices')
        ? sitepulse_get_ai_rate_limit_choices()
        : [
            'day'       => __('Une fois par jour', 'sitepulse'),
            'week'      => __('Une fois par semaine', 'sitepulse'),
            'month'     => __('Une fois par mois', 'sitepulse'),
            'unlimited' => __('Illimité', 'sitepulse'),
        ];

    if (!isset($choices[$value])) {
        return $default;
    }

    return $value;
}

/**
 * Returns the localized label for the provided AI rate limit key.
 *
 * @param string $rate_limit Rate limit option key.
 * @return string
 */
function sitepulse_ai_get_rate_limit_label($rate_limit) {
    $choices = function_exists('sitepulse_get_ai_rate_limit_choices')
        ? sitepulse_get_ai_rate_limit_choices()
        : [
            'day'       => __('Une fois par jour', 'sitepulse'),
            'week'      => __('Une fois par semaine', 'sitepulse'),
            'month'     => __('Une fois par mois', 'sitepulse'),
            'unlimited' => __('Illimité', 'sitepulse'),
        ];

    if (!isset($choices[$rate_limit])) {
        $default = sitepulse_ai_get_current_rate_limit_value();

        if (isset($choices[$default])) {
            return $choices[$default];
        }

        return (string) reset($choices);
    }

    return $choices[$rate_limit];
}

/**
 * Returns the rate limit window in seconds for a given option key.
 *
 * @param string $rate_limit Rate limit option key.
 * @return int Number of seconds in the rate limit window. 0 means unlimited.
 */
function sitepulse_ai_get_rate_limit_window_seconds($rate_limit) {
    switch ($rate_limit) {
        case 'day':
            return DAY_IN_SECONDS;
        case 'week':
            return WEEK_IN_SECONDS;
        case 'month':
            return MONTH_IN_SECONDS;
        default:
            return 0;
    }
}

/**
 * Returns the timestamp when Gemini requests can resume after a rate limit.
 *
 * @return int UTC timestamp or 0 when no delay applies.
 */
function sitepulse_ai_get_retry_after_timestamp() {
    $timestamp = (int) get_option(SITEPULSE_OPTION_AI_RETRY_AFTER, 0);

    if ($timestamp <= 0) {
        return 0;
    }

    return $timestamp;
}

/**
 * Stores the timestamp when Gemini requests can resume after a rate limit.
 *
 * @param int $timestamp UTC timestamp. Use 0 to clear.
 *
 * @return void
 */
function sitepulse_ai_set_retry_after_timestamp($timestamp) {
    $timestamp = (int) $timestamp;

    if ($timestamp <= 0) {
        delete_option(SITEPULSE_OPTION_AI_RETRY_AFTER);

        return;
    }

    update_option(SITEPULSE_OPTION_AI_RETRY_AFTER, $timestamp, false);
}

/**
 * Converts human readable durations (e.g. "30s", "5m", "PT1M30S") to seconds.
 *
 * @param mixed $duration Duration string or numeric value.
 *
 * @return int Duration in seconds.
 */
function sitepulse_ai_parse_duration_string($duration, $now = null) {
    if (null === $now) {
        $now = absint(current_time('timestamp', true));
    }

    if (is_numeric($duration)) {
        $seconds = (float) $duration;

        return (int) max(0, round($seconds));
    }

    if (!is_string($duration)) {
        return 0;
    }

    $duration = trim($duration);

    if ('' === $duration) {
        return 0;
    }

    if (preg_match('/^P/i', $duration)) {
        try {
            $base     = new \DateTimeImmutable('@0');
            $interval = new \DateInterval($duration);
            $target   = $base->add($interval);

            return (int) max(0, $target->getTimestamp());
        } catch (\Exception $exception) {
            // Fall back to heuristic parsing below.
        }
    }

    if (preg_match('/^(?P<value>\d+(?:\.\d+)?)(?P<unit>[a-z]+)/i', strtolower($duration), $matches)) {
        $value = (float) $matches['value'];
        $unit  = $matches['unit'];

        switch ($unit) {
            case 's':
            case 'sec':
            case 'secs':
            case 'second':
            case 'seconds':
                return (int) round($value);
            case 'm':
            case 'min':
            case 'mins':
            case 'minute':
            case 'minutes':
                return (int) round($value * MINUTE_IN_SECONDS);
            case 'h':
            case 'hr':
            case 'hrs':
            case 'hour':
            case 'hours':
                return (int) round($value * HOUR_IN_SECONDS);
            case 'd':
            case 'day':
            case 'days':
                return (int) round($value * DAY_IN_SECONDS);
        }
    }

    $timestamp = strtotime($duration);

    if (false !== $timestamp && $timestamp > $now) {
        return (int) max(0, $timestamp - $now);
    }

    return 0;
}

/**
 * Calculates the number of seconds until a given timestamp value.
 *
 * @param mixed $value Raw timestamp value (string/number).
 * @param int   $now   Current UTC timestamp.
 *
 * @return int
 */
function sitepulse_ai_seconds_until_timestamp($value, $now) {
    if (is_numeric($value)) {
        $candidate = (float) $value;

        if ($candidate > 1_000_000_000_000) {
            $candidate /= 1_000;
        }

        $candidate = (int) round($candidate);

        if ($candidate > $now) {
            return (int) max(0, $candidate - $now);
        }

        return 0;
    }

    if (!is_string($value)) {
        return 0;
    }

    $value = trim($value);

    if ('' === $value) {
        return 0;
    }

    $timestamp = strtotime($value);

    if (false === $timestamp) {
        return 0;
    }

    if ($timestamp <= $now) {
        return 0;
    }

    return (int) ($timestamp - $now);
}

/**
 * Parses retry-after hints from mixed values.
 *
 * @param mixed $value Raw value to inspect.
 * @param int   $now   Current UTC timestamp.
 *
 * @return int Seconds until retry.
 */
function sitepulse_ai_parse_retry_value($value, $now) {
    if (is_array($value)) {
        return sitepulse_ai_collect_retry_after_seconds($value, $now);
    }

    if (is_numeric($value)) {
        $numeric = (float) $value;

        if ($numeric > 1_000_000_000_000) {
            $numeric /= 1_000;
        }

        if ($numeric > $now + 5) {
            return sitepulse_ai_seconds_until_timestamp($numeric, $now);
        }

        return (int) max(0, round($numeric));
    }

    if (!is_string($value)) {
        return 0;
    }

    $value = trim($value);

    if ('' === $value) {
        return 0;
    }

    if (is_numeric($value)) {
        return sitepulse_ai_parse_retry_value((float) $value, $now);
    }

    $duration = sitepulse_ai_parse_duration_string($value, $now);

    if ($duration > 0) {
        return $duration;
    }

    return sitepulse_ai_seconds_until_timestamp($value, $now);
}

/**
 * Recursively scans decoded JSON payloads for retry-after hints.
 *
 * @param array<string,mixed> $data Decoded JSON payload.
 * @param int                 $now  Current UTC timestamp.
 *
 * @return int Seconds until retry.
 */
function sitepulse_ai_collect_retry_after_seconds($data, $now) {
    $max_delay = 0;

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $max_delay = max($max_delay, sitepulse_ai_collect_retry_after_seconds($value, $now));

            continue;
        }

        if (!is_string($key)) {
            continue;
        }

        $normalized_key = strtolower(str_replace(['-', '_', ' '], '', $key));

        if ('' === $normalized_key) {
            continue;
        }

        $duration_keys = [
            'retryafter',
            'retryafterseconds',
            'retrydelay',
            'retrydelayseconds',
            'retrydelaysec',
            'interval',
            'duration',
            'period',
            'remainingtime',
            'remaining',
            'waittime',
            'seconds',
        ];

        $timestamp_keys = [
            'retryat',
            'resumetime',
            'resumeat',
            'resettime',
            'resetat',
            'reset',
            'resettimestamp',
            'resettimeseconds',
            'retrytimestamp',
            'retrytimestampseconds',
        ];

        if (in_array($normalized_key, $duration_keys, true)) {
            $max_delay = max($max_delay, sitepulse_ai_parse_retry_value($value, $now));

            continue;
        }

        if (in_array($normalized_key, $timestamp_keys, true)) {
            $candidate = sitepulse_ai_seconds_until_timestamp($value, $now);

            if ($candidate <= 0) {
                $candidate = sitepulse_ai_parse_retry_value($value, $now);
            }

            $max_delay = max($max_delay, $candidate);
        }
    }

    return (int) $max_delay;
}

/**
 * Extracts retry-after hints from HTTP headers.
 *
 * @param mixed $headers Response headers.
 * @param int   $now     Current UTC timestamp.
 *
 * @return int Seconds until retry.
 */
function sitepulse_ai_retry_after_from_headers($headers, $now) {
    if ($headers instanceof WP_HTTP_Headers) {
        $headers = $headers->getAll();
    }

    if (!is_array($headers)) {
        return 0;
    }

    foreach ($headers as $name => $value) {
        if (!is_string($name)) {
            continue;
        }

        if (is_array($value)) {
            $value = end($value);
        }

        if (!is_string($value)) {
            continue;
        }

        if ('retry-after' !== strtolower($name)) {
            continue;
        }

        $seconds = sitepulse_ai_parse_retry_value($value, $now);

        if ($seconds > 0) {
            return $seconds;
        }
    }

    return 0;
}

/**
 * Determines the retry-after delay from HTTP headers and JSON payload.
 *
 * @param mixed                       $headers       HTTP headers.
 * @param array<string,mixed>|null    $decoded_error Optional decoded error payload.
 * @param int                         $now           Current UTC timestamp.
 *
 * @return int Seconds until retry.
 */
function sitepulse_ai_extract_retry_after_delay($headers, $decoded_error, $now) {
    $delay = sitepulse_ai_retry_after_from_headers($headers, $now);

    if ($delay > 0) {
        return $delay;
    }

    if (is_array($decoded_error)) {
        $delay = sitepulse_ai_collect_retry_after_seconds($decoded_error, $now);
    }

    return (int) max(0, $delay);
}

/**
 * Creates a WP_Error instance while logging the associated message.
 *
 * @param string   $code        Error code.
 * @param string   $message     Human readable message.
 * @param int|null $status_code Optional status code for context.
 *
 * @return WP_Error
 */
function sitepulse_ai_create_wp_error($code, $message, $status_code = null, array $extra_data = []) {
    sitepulse_ai_record_critical_error($message, $status_code);

    $data = [];

    if (null !== $status_code) {
        $data['status_code'] = (int) $status_code;
    }

    foreach ($extra_data as $key => $value) {
        $data[$key] = $value;
    }

    return new WP_Error($code, $message, $data);
}

/**
 * Retrieves the contextual status code from a WP_Error instance.
 *
 * @param WP_Error   $error          Error object.
 * @param int        $default_code   Fallback status code.
 *
 * @return int
 */
function sitepulse_ai_get_error_status_code(WP_Error $error, $default_code = 500) {
    $data = $error->get_error_data();

    if (is_array($data) && isset($data['status_code'])) {
        return (int) $data['status_code'];
    }

    return (int) $default_code;
}

/**
 * Retrieves the retry-after delay attached to an error when available.
 *
 * @param WP_Error $error Error object.
 *
 * @return int Seconds until retry.
 */
function sitepulse_ai_get_error_retry_after(WP_Error $error) {
    $data = $error->get_error_data();

    if (!is_array($data)) {
        return 0;
    }

    if (isset($data['retry_after'])) {
        return (int) max(0, $data['retry_after']);
    }

    if (isset($data['retry_at'])) {
        $retry_at = (int) $data['retry_at'];

        if ($retry_at > 0) {
            $now = absint(current_time('timestamp', true));

            return (int) max(0, $retry_at - $now);
        }
    }

    return 0;
}

/**
 * Retrieves the retry-at timestamp attached to an error when available.
 *
 * @param WP_Error $error Error object.
 *
 * @return int UTC timestamp or 0 when missing.
 */
function sitepulse_ai_get_error_retry_at(WP_Error $error) {
    $data = $error->get_error_data();

    if (is_array($data) && isset($data['retry_at'])) {
        return (int) max(0, $data['retry_at']);
    }

    if (is_array($data) && isset($data['retry_after'])) {
        $delay = (int) max(0, $data['retry_after']);

        if ($delay > 0) {
            $now = absint(current_time('timestamp', true));

            return $now + $delay;
        }
    }

    return 0;
}

/**
 * Validates the AI environment and returns configuration details.
 *
 * @return array{api_key:string,available_models:array<string,mixed>,selected_model:string}|WP_Error
 */
function sitepulse_ai_prepare_environment() {
    $api_key = sitepulse_get_gemini_api_key();

    if ('' === $api_key) {
        $error_message = esc_html__('Veuillez entrer votre clé API Google Gemini dans les réglages de SitePulse.', 'sitepulse');

        return sitepulse_ai_create_wp_error('sitepulse_ai_missing_key', $error_message, 400);
    }

    $available_models = sitepulse_get_ai_models();
    $default_model    = sitepulse_get_default_ai_model();
    $selected_model   = (string) get_option(SITEPULSE_OPTION_AI_MODEL, $default_model);

    if (!isset($available_models[$selected_model])) {
        $selected_model = $default_model;
    }

    return [
        'api_key'          => $api_key,
        'available_models' => $available_models,
        'selected_model'   => $selected_model,
    ];
}

/**
 * Performs the remote Gemini request and returns the generated insight.
 *
 * @param string               $api_key          Gemini API key.
 * @param string               $selected_model   Selected model identifier.
 * @param array<string,mixed>  $available_models Available model metadata.
 *
 * @return array{text:string,html:string,timestamp:int,cached:bool}|WP_Error
 */
function sitepulse_ai_execute_generation($api_key, $selected_model, array $available_models) {
    $endpoint = sprintf(
        'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
        rawurlencode($selected_model)
    );

    $site_name        = wp_strip_all_tags(get_bloginfo('name'));
    $site_url         = esc_url_raw(home_url());
    $site_description = wp_strip_all_tags(get_bloginfo('description'));

    $prompt_sections = [
        __('Tu es un expert en optimisation de sites WordPress.', 'sitepulse'),
        sprintf(
            /* translators: %1$s: Site name, %2$s: Site URL */
            __('Analyse les performances du site "%1$s" disponible à l\'adresse %2$s.', 'sitepulse'),
            $site_name,
            $site_url
        ),
        __('Fournis trois recommandations concrètes pour améliorer la vitesse, le référencement et la conversion. Réponds en français.', 'sitepulse'),
    ];

    $metrics_summary = sitepulse_ai_get_metrics_summary();

    if ('' !== $metrics_summary) {
        $prompt_sections[] = $metrics_summary;
    }

    if (!empty($site_description)) {
        $prompt_sections[] = sprintf(
            /* translators: %s: site description */
            __('Description du site : %s.', 'sitepulse'),
            $site_description
        );
    }

    if (isset($available_models[$selected_model]['prompt_instruction'])) {
        $prompt_sections[] = (string) $available_models[$selected_model]['prompt_instruction'];
    }

    $request_body = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => implode(' ', array_filter($prompt_sections)),
                    ],
                ],
            ],
        ],
    ];

    $json_body = wp_json_encode($request_body);

    if (false === $json_body) {
        $error_detail = function_exists('json_last_error_msg') ? json_last_error_msg() : '';

        if ('' === $error_detail) {
            $error_detail = esc_html__('erreur JSON inconnue', 'sitepulse');
        }

        $sanitized_detail = sanitize_text_field($error_detail);
        $error_message    = sprintf(
            /* translators: %s: error detail */
            esc_html__('Impossible de préparer la requête pour Gemini : %s', 'sitepulse'),
            $sanitized_detail
        );

        return sitepulse_ai_create_wp_error('sitepulse_ai_json_error', $error_message, 500);
    }

    $response_size_limit = (int) apply_filters('sitepulse_ai_response_size_limit', defined('MB_IN_BYTES') ? MB_IN_BYTES : 1_048_576);

    $request_args = [
        'headers' => [
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $api_key,
        ],
        'body'    => $json_body,
        'timeout' => 30,
    ];

    if ($response_size_limit > 0) {
        $request_args['limit_response_size'] = $response_size_limit;
    }

    $response = wp_remote_post(
        $endpoint,
        $request_args
    );

    if (is_wp_error($response)) {
        if (
            $response_size_limit > 0
            && 'http_request_failed' === $response->get_error_code()
            && false !== stripos($response->get_error_message(), 'limit')
        ) {
            $formatted_limit = size_format($response_size_limit, 2);
            $sanitized_limit = sanitize_text_field($formatted_limit);
            $error_message   = sprintf(
                /* translators: %s: formatted size limit */
                esc_html__('La réponse de Gemini dépasse la taille maximale autorisée (%s). Veuillez réessayer ou augmenter la limite via le filtre sitepulse_ai_response_size_limit.', 'sitepulse'),
                $sanitized_limit
            );

            return sitepulse_ai_create_wp_error('sitepulse_ai_response_too_large', $error_message, 500);
        }

        $sanitized_error_message = sanitize_text_field($response->get_error_message());
        $error_message           = sprintf(
            /* translators: %s: error message */
            esc_html__('Erreur lors de la génération de l’analyse IA : %s', 'sitepulse'),
            $sanitized_error_message
        );

        return sitepulse_ai_create_wp_error('sitepulse_ai_request_failed', $error_message, 500);
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $body        = wp_remote_retrieve_body($response);
    $headers     = wp_remote_retrieve_headers($response);

    if ($status_code < 200 || $status_code >= 300) {
        $error_detail = '';
        $decoded_error = null;

        if (!empty($body)) {
            $decoded_error = json_decode($body, true);

            if (is_array($decoded_error) && isset($decoded_error['error']['message'])) {
                $error_detail = $decoded_error['error']['message'];
            } else {
                $error_detail = $body;
            }
        }

        if ('' === $error_detail) {
            $error_detail = sprintf(esc_html__('HTTP %d', 'sitepulse'), $status_code);
        }

        $sanitized_error_detail = sanitize_text_field($error_detail);
        $error_message          = sprintf(
            /* translators: %s: error message */
            esc_html__('Erreur lors de la génération de l’analyse IA : %s', 'sitepulse'),
            $sanitized_error_detail
        );
        $extra_data = [];

        if (in_array($status_code, [429, 503], true)) {
            $now_utc             = absint(current_time('timestamp', true));
            $retry_after_seconds = sitepulse_ai_extract_retry_after_delay($headers, is_array($decoded_error) ? $decoded_error : null, $now_utc);

            if ($retry_after_seconds > 0) {
                $retry_at = $now_utc + $retry_after_seconds;

                sitepulse_ai_set_retry_after_timestamp($retry_at);

                $human_delay = function_exists('human_time_diff')
                    ? human_time_diff($now_utc, $retry_at)
                    : sprintf('%ds', max(1, (int) $retry_after_seconds));

                $error_message = sprintf(
                    /* translators: 1: error message, 2: human readable delay. */
                    esc_html__('Erreur lors de la génération de l’analyse IA : %1$s. Réessayez dans %2$s.', 'sitepulse'),
                    $sanitized_error_detail,
                    $human_delay
                );

                $extra_data = [
                    'retry_after' => (int) $retry_after_seconds,
                    'retry_at'    => (int) $retry_at,
                ];
            }
        }

        return sitepulse_ai_create_wp_error('sitepulse_ai_http_error', $error_message, $status_code, $extra_data);
    }

    $decoded_body = json_decode($body, true);

    if (!is_array($decoded_body) || !isset($decoded_body['candidates'][0]['content']['parts']) || !is_array($decoded_body['candidates'][0]['content']['parts'])) {
        $error_message = esc_html__('Structure de réponse inattendue reçue depuis Gemini.', 'sitepulse');

        return sitepulse_ai_create_wp_error('sitepulse_ai_invalid_response', $error_message, 500);
    }

    $generated_text = '';

    foreach ($decoded_body['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['text'])) {
            $generated_text .= ' ' . $part['text'];
        }
    }

    $generated_text = trim($generated_text);

    if ('' === $generated_text) {
        $error_message = esc_html__('La réponse de Gemini ne contient aucun texte exploitable.', 'sitepulse');

        return sitepulse_ai_create_wp_error('sitepulse_ai_empty_response', $error_message, 500);
    }

    $variants = sitepulse_ai_prepare_insight_variants($generated_text);

    if ('' === $variants['text']) {
        $error_message = esc_html__('La réponse de Gemini ne contient aucun texte exploitable.', 'sitepulse');

        return sitepulse_ai_create_wp_error('sitepulse_ai_empty_response', $error_message, 500);
    }

    $timestamp = absint(current_time('timestamp', true));

    set_transient(
        SITEPULSE_TRANSIENT_AI_INSIGHT,
        [
            'text'      => $variants['text'],
            'html'      => $variants['html'],
            'timestamp' => $timestamp,
        ],
        HOUR_IN_SECONDS
    );

    sitepulse_ai_get_cached_insight(true);

    $fresh_payload = sitepulse_ai_get_cached_insight();

    if (empty($fresh_payload)) {
        $fresh_payload = [
            'text'      => $variants['text'],
            'html'      => $variants['html'],
            'timestamp' => $timestamp,
        ];
    }

    $usage = sitepulse_ai_parse_response_usage($headers);

    return [
        'text'      => isset($fresh_payload['text']) ? $fresh_payload['text'] : $variants['text'],
        'html'      => isset($fresh_payload['html']) ? $fresh_payload['html'] : $variants['html'],
        'timestamp' => isset($fresh_payload['timestamp']) ? $fresh_payload['timestamp'] : $timestamp,
        'cached'    => false,
        'usage'     => $usage,
    ];
}

/**
 * Schedules an asynchronous job that will generate a fresh AI insight.
 *
 * @param bool $force_refresh Whether the user explicitly requested a refresh.
 *
 * @return string|WP_Error Job identifier or error on failure.
 */
function sitepulse_ai_schedule_generation_job($force_refresh) {
    $job_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('sitepulse_ai_', true);
    $now    = time();
    $priority = $force_refresh ? 'high' : 'normal';

    $queue_context = sitepulse_ai_normalize_queue_context([
        'priority'      => $priority,
        'attempt'       => 1,
        'engine'        => function_exists('as_enqueue_async_action') ? 'action_scheduler' : 'wp_cron',
        'group'         => sitepulse_ai_get_queue_group(),
        'force_refresh' => (bool) $force_refresh,
        'quota'         => sitepulse_ai_capture_quota_snapshot(),
        'scheduled_at'  => $now,
        'model'         => sitepulse_ai_get_selected_model_key(),
    ]);

    $job_data = [
        'status'        => 'queued',
        'created_at'    => $now,
        'force_refresh' => (bool) $force_refresh,
        'attempt'       => 1,
        'priority'      => $priority,
        'queue'         => $queue_context,
        'model'         => $queue_context['model'],
    ];

    if (!sitepulse_ai_save_job_data($job_id, $job_data)) {
        $error_message = esc_html__('Impossible de planifier la génération de l’analyse IA. Veuillez réessayer.', 'sitepulse');

        return sitepulse_ai_create_wp_error('sitepulse_ai_job_storage_failed', $error_message, 500);
    }

    $schedule = sitepulse_ai_queue_schedule_action($job_id, $queue_context, 0);

    if (is_wp_error($schedule)) {
        $guidance_message = esc_html__('WP-Cron semble désactivé sur ce site. L’analyse IA sera exécutée immédiatement, mais pensez à réactiver WP-Cron (retirez DISABLE_WP_CRON de wp-config.php ou planifiez une tâche serveur).', 'sitepulse');
        $fallback_message = esc_html__('La planification du traitement IA a échoué. L’analyse est exécutée immédiatement.', 'sitepulse');

        $job_data['fallback']       = 'synchronous';
        $job_data['queue']['engine'] = 'immediate';
        $job_data['queue']['message'] = $fallback_message;

        sitepulse_ai_save_job_data($job_id, $job_data);

        if (sitepulse_ai_is_wp_cron_disabled()) {
            sitepulse_ai_record_critical_error($guidance_message);
        } else {
            sitepulse_ai_record_critical_error($fallback_message);
        }

        sitepulse_run_ai_insight_job($job_id, $job_data['queue']);

        $job_state = sitepulse_ai_get_job_data($job_id);

        if (!is_array($job_state) || !isset($job_state['status'])) {
            sitepulse_ai_delete_job_data($job_id);

            return sitepulse_ai_create_wp_error('sitepulse_ai_job_schedule_failed', $fallback_message, 500);
        }

        if ('completed' === $job_state['status']) {
            return $job_id;
        }

        $error_message = isset($job_state['message']) ? (string) $job_state['message'] : $fallback_message;
        $status_code   = isset($job_state['code']) ? (int) $job_state['code'] : 500;
        $extra_data    = [];

        if (isset($job_state['retry_after'])) {
            $extra_data['retry_after'] = (int) $job_state['retry_after'];
        }

        if (isset($job_state['retry_at'])) {
            $extra_data['retry_at'] = (int) $job_state['retry_at'];
        }

        sitepulse_ai_delete_job_data($job_id);

        return sitepulse_ai_create_wp_error('sitepulse_ai_job_schedule_failed', $error_message, $status_code, $extra_data);
    }

    if (isset($schedule['context'])) {
        $job_data['queue'] = sitepulse_ai_normalize_queue_context($schedule['context'], $job_data);
        sitepulse_ai_save_job_data($job_id, $job_data);
    }

    if (isset($schedule['engine']) && 'wp_cron' === $schedule['engine']) {
        $spawn_result = sitepulse_ai_spawn_cron(isset($schedule['timestamp']) ? (int) $schedule['timestamp'] : time());
        $spawn_failed = false;

        if (is_wp_error($spawn_result)) {
            $spawn_failed        = true;
            $spawn_error_message = $spawn_result->get_error_message();

            if ('' !== $spawn_error_message) {
                $spawn_message = sprintf(
                    /* translators: %s: Error details. */
                    esc_html__('Échec du déclenchement immédiat de WP-Cron pour l’analyse IA : %s', 'sitepulse'),
                    $spawn_error_message
                );
            } else {
                $spawn_message = esc_html__('Échec du déclenchement immédiat de WP-Cron pour l’analyse IA.', 'sitepulse');
            }

            sitepulse_ai_record_critical_error($spawn_message);
        } elseif (false === $spawn_result) {
            $spawn_failed = true;
            sitepulse_ai_record_critical_error(esc_html__('Échec du déclenchement immédiat de WP-Cron pour l’analyse IA.', 'sitepulse'));
        }

        if ($spawn_failed) {
            $async_response       = sitepulse_ai_trigger_async_job_request($job_id, $job_data['queue']);
            $async_error_log      = '';
            $async_error_details  = '';
            $async_error_code     = 500;

            if (is_wp_error($async_response)) {
                $async_error_details = $async_response->get_error_message();
                $async_error_code    = sitepulse_ai_get_error_status_code($async_response, 500);

                if ('' !== $async_error_details) {
                    $async_error_log = sprintf(
                        /* translators: %s: Error details. */
                        esc_html__('Échec du déclenchement immédiat de l’analyse IA via AJAX : %s', 'sitepulse'),
                        $async_error_details
                    );
                } else {
                    $async_error_log = esc_html__('Échec du déclenchement immédiat de l’analyse IA via AJAX.', 'sitepulse');
                }
            } else {
                $response_code = (int) wp_remote_retrieve_response_code($async_response);

                if ($response_code >= 400) {
                    $async_error_code = $response_code;
                    $async_error_log  = sprintf(
                        /* translators: %d: HTTP status code. */
                        esc_html__('Échec du déclenchement immédiat de l’analyse IA via AJAX (code HTTP %d).', 'sitepulse'),
                        $response_code
                    );
                    $async_error_details = (string) wp_remote_retrieve_response_message($async_response);
                }
            }

            if ('' !== $async_error_log) {
                sitepulse_ai_record_critical_error($async_error_log);

                $user_message = esc_html__('Impossible de déclencher immédiatement l’analyse IA. Réessayez dans quelques instants.', 'sitepulse');

                if ('' !== $async_error_details) {
                    $user_message = sprintf(
                        /* translators: %s: Error details. */
                        esc_html__('Impossible de déclencher immédiatement l’analyse IA : %s', 'sitepulse'),
                        wp_strip_all_tags($async_error_details)
                    );
                }

                sitepulse_ai_queue_clear_scheduled_action($job_id, $job_data['queue']);
                sitepulse_ai_delete_job_data($job_id);

                sitepulse_ai_record_job_log($job_id, [
                    'status'       => 'abandoned',
                    'status_final' => 'abandoned',
                    'attempt'      => isset($job_data['attempt']) ? (int) $job_data['attempt'] : 1,
                    'model'        => isset($job_data['model']) ? $job_data['model'] : (isset($job_data['queue']['model']) ? $job_data['queue']['model'] : ''),
                    'engine'       => isset($job_data['queue']['engine']) ? $job_data['queue']['engine'] : '',
                    'timestamp'    => time(),
                    'message'      => $user_message,
                ]);

                return sitepulse_ai_create_wp_error(
                    'sitepulse_ai_job_async_trigger_failed',
                    $user_message,
                    $async_error_code,
                    [
                        'details' => wp_strip_all_tags($async_error_details),
                    ]
                );
            }
        }
    }

    return $job_id;
}

/**
 * Cron/async handler responsible for generating the AI insight.
 *
 * @param string $job_id Job identifier.
 *
 * @return void
 */
function sitepulse_run_ai_insight_job($job_id, $queue_context = []) {
    $job_id = (string) $job_id;

    if ('' === $job_id) {
        return;
    }

    $job_data = sitepulse_ai_get_job_data($job_id);

    if (!is_array($job_data)) {
        $job_data = [];
    }

    $queue_context = sitepulse_ai_normalize_queue_context(is_array($queue_context) ? $queue_context : [], $job_data);

    $attempt = isset($job_data['attempt']) ? max((int) $job_data['attempt'], $queue_context['attempt']) : $queue_context['attempt'];
    $queue_context['attempt'] = $attempt;

    $job_data['status']     = 'running';
    $job_data['started_at'] = time();
    $job_data['attempt']    = $attempt;
    $job_data['queue']      = $queue_context;

    unset($job_data['message'], $job_data['code']);

    sitepulse_ai_save_job_data($job_id, $job_data);

    sitepulse_ai_record_job_log($job_id, [
        'status'    => 'running',
        'attempt'   => $attempt,
        'model'     => isset($queue_context['model']) ? $queue_context['model'] : '',
        'engine'    => isset($queue_context['engine']) ? $queue_context['engine'] : '',
        'timestamp' => $job_data['started_at'],
    ]);

    try {
        $environment = sitepulse_ai_prepare_environment();

        if (is_wp_error($environment)) {
            $error_message = $environment->get_error_message();
            $status_code   = sitepulse_ai_get_error_status_code($environment, 500);

            sitepulse_ai_save_job_data($job_id, array_merge($job_data, [
                'status'  => 'failed',
                'final_status' => 'failed',
                'message' => $error_message,
                'code'    => $status_code,
                'finished'=> time(),
            ]));

            sitepulse_ai_record_job_log($job_id, [
                'status'       => 'failed',
                'status_final' => 'failed',
                'attempt'      => $attempt,
                'model'        => isset($queue_context['model']) ? $queue_context['model'] : '',
                'engine'       => isset($queue_context['engine']) ? $queue_context['engine'] : '',
                'timestamp'    => time(),
                'message'      => $error_message,
            ]);

            return;
        }

        $result = sitepulse_ai_execute_generation(
            $environment['api_key'],
            $environment['selected_model'],
            $environment['available_models']
        );

        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            $status_code   = sitepulse_ai_get_error_status_code($result, 500);
            $retry_after   = sitepulse_ai_get_error_retry_after($result);
            $retry_at      = sitepulse_ai_get_error_retry_at($result);
            $now_utc       = absint(current_time('timestamp', true));

            if ($retry_at > 0) {
                sitepulse_ai_set_retry_after_timestamp($retry_at);
            } elseif ($retry_after > 0) {
                $calculated_retry_at = $now_utc + $retry_after;
                sitepulse_ai_set_retry_after_timestamp($calculated_retry_at);
                $retry_at = $calculated_retry_at;
            }

        $job_failure = array_merge($job_data, [
            'status'       => 'failed',
            'final_status' => 'failed',
            'message'      => $error_message,
            'code'         => $status_code,
            'finished'     => time(),
        ]);

            if ($retry_after > 0) {
                $job_failure['retry_after'] = (int) $retry_after;
            }

            if ($retry_at > 0) {
                $job_failure['retry_at'] = (int) $retry_at;
            }

            $max_attempts = sitepulse_ai_get_max_attempts();

            if ($attempt < $max_attempts) {
                $delay_seconds = 0;

                if ($retry_at > $now_utc) {
                    $delay_seconds = max(0, $retry_at - $now_utc);
                } elseif ($retry_after > 0) {
                    $delay_seconds = (int) $retry_after;
                } else {
                    $delay_seconds = sitepulse_ai_calculate_retry_delay($attempt + 1);
                }

                $next_attempt_at = $now_utc + $delay_seconds;
                $retry_context   = $queue_context;
                $retry_context['attempt']        = $attempt + 1;
                $retry_context['next_attempt_at'] = $next_attempt_at;
                $retry_context['message']        = $error_message;

                $schedule_retry = sitepulse_ai_queue_schedule_action($job_id, $retry_context, $delay_seconds);

                if (!is_wp_error($schedule_retry) && isset($schedule_retry['context'])) {
                    $job_failure['status'] = 'pending';
                    unset($job_failure['final_status']);
                    $job_failure['queue']  = $schedule_retry['context'];
                    $job_failure['queue']['next_attempt_at'] = isset($schedule_retry['timestamp']) ? (int) $schedule_retry['timestamp'] : $next_attempt_at;

                    sitepulse_ai_save_job_data($job_id, $job_failure);

                    sitepulse_ai_record_job_log($job_id, [
                        'status'    => 'retrying',
                        'attempt'   => $attempt,
                        'model'     => isset($queue_context['model']) ? $queue_context['model'] : '',
                        'engine'    => isset($queue_context['engine']) ? $queue_context['engine'] : '',
                        'timestamp' => time(),
                        'message'   => $error_message,
                    ]);

                    return;
                }

                if (is_wp_error($schedule_retry)) {
                    sitepulse_ai_record_critical_error($schedule_retry->get_error_message(), sitepulse_ai_get_error_status_code($schedule_retry, 500));
                    $job_failure['message'] = $schedule_retry->get_error_message();
                }
            }

            sitepulse_ai_save_job_data($job_id, $job_failure);

            sitepulse_ai_record_job_log($job_id, [
                'status'       => 'failed',
                'status_final' => 'failed',
                'attempt'      => $attempt,
                'model'        => isset($queue_context['model']) ? $queue_context['model'] : '',
                'engine'       => isset($queue_context['engine']) ? $queue_context['engine'] : '',
                'timestamp'    => time(),
                'message'      => $job_failure['message'],
            ]);

            return;
        }

        $selected_model = isset($environment['selected_model']) ? (string) $environment['selected_model'] : '';
        $model_label    = '';

        if (
            $selected_model !== ''
            && isset($environment['available_models'][$selected_model]['label'])
            && is_scalar($environment['available_models'][$selected_model]['label'])
        ) {
            $model_label = (string) $environment['available_models'][$selected_model]['label'];
        }

        $rate_limit_value = sitepulse_ai_get_current_rate_limit_value();
        $history_entry    = [
            'text'       => isset($result['text']) ? $result['text'] : '',
            'html'       => isset($result['html']) ? $result['html'] : '',
            'timestamp'  => isset($result['timestamp']) ? (int) $result['timestamp'] : absint(current_time('timestamp', true)),
            'model'      => [
                'key'   => $selected_model,
                'label' => $model_label,
            ],
            'rate_limit' => [
                'key'   => $rate_limit_value,
                'label' => sitepulse_ai_get_rate_limit_label($rate_limit_value),
            ],
        ];

        $history_entry_id = sitepulse_ai_generate_history_entry_id($history_entry);
        $history_entry['id'] = $history_entry_id;

        $result_with_context = array_merge($result, [
            'model'      => $history_entry['model'],
            'rate_limit' => $history_entry['rate_limit'],
            'id'         => $history_entry_id,
            'note'       => '',
        ]);

        $job_data['queue']['next_attempt_at'] = 0;
        $usage_data = isset($result['usage']) && is_array($result['usage'])
            ? sitepulse_ai_normalize_usage_metadata($result['usage'])
            : [];

        $job_data['queue']['usage'] = $usage_data;

        $finished_time = time();

        sitepulse_ai_save_job_data($job_id, array_merge($job_data, [
            'status'       => 'completed',
            'final_status' => 'success',
            'result'       => $result_with_context,
            'finished'     => $finished_time,
            'queue'        => $job_data['queue'],
        ]));

        sitepulse_ai_record_history_entry($history_entry);

        update_option(SITEPULSE_OPTION_AI_LAST_RUN, absint(current_time('timestamp', true)));
        sitepulse_ai_set_retry_after_timestamp(0);

        sitepulse_ai_log_execution_metrics($job_id, array_merge($job_data, [
            'result'   => $result_with_context,
            'finished' => $finished_time,
        ]), $usage_data);

        sitepulse_ai_record_job_log($job_id, [
            'status'         => 'success',
            'status_final'   => 'success',
            'attempt'        => $attempt,
            'model'          => isset($queue_context['model']) ? $queue_context['model'] : (isset($history_entry['model']['key']) ? $history_entry['model']['key'] : ''),
            'engine'         => isset($queue_context['engine']) ? $queue_context['engine'] : '',
            'timestamp'      => $finished_time,
            'usage'          => $usage_data,
            'cost_estimated' => sitepulse_ai_extract_usage_cost($usage_data),
            'latency_ms'     => isset($job_data['started_at']) ? max(0, ($finished_time - (int) $job_data['started_at']) * 1000) : null,
        ]);
    } catch (Throwable $throwable) {
        $message = sprintf(
            /* translators: %s: error message */
            esc_html__('Une erreur inattendue est survenue lors de la génération de l’analyse IA : %s', 'sitepulse'),
            $throwable->getMessage()
        );

        sitepulse_ai_record_critical_error($message, $throwable->getCode());

        sitepulse_ai_save_job_data($job_id, array_merge($job_data, [
            'status'   => 'failed',
            'message'  => $message,
            'code'     => (int) $throwable->getCode(),
            'final_status' => 'failed',
            'finished' => time(),
        ]));

        sitepulse_ai_record_job_log($job_id, [
            'status'       => 'failed',
            'status_final' => 'failed',
            'attempt'      => $attempt,
            'model'        => isset($queue_context['model']) ? $queue_context['model'] : '',
            'engine'       => isset($queue_context['engine']) ? $queue_context['engine'] : '',
            'timestamp'    => time(),
            'message'      => $message,
        ]);
    }
}

/**
 * Retrieves the cached AI insight payload for the current request.
 *
 * @param bool $force_refresh When true, clears the transient cache and resets the in-request cache.
 *
 * @return array{text?:string,html?:string,timestamp?:int}
 */
function sitepulse_ai_get_cached_insight($force_refresh = false) {
    static $cached_insight = null;

    if ($force_refresh) {
        $cached_insight = null;

        return [];
    }

    if ($cached_insight !== null) {
        return $cached_insight;
    }

    $cached_insight = [];
    $stored_insight = get_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);

    $variants = [
        'text' => '',
        'html' => '',
    ];

    if (is_array($stored_insight)) {
        $variants = sitepulse_ai_prepare_insight_variants(
            isset($stored_insight['text']) ? (string) $stored_insight['text'] : '',
            isset($stored_insight['html']) ? (string) $stored_insight['html'] : ''
        );

        if (isset($stored_insight['timestamp'])) {
            $cached_insight['timestamp'] = (int) $stored_insight['timestamp'];
        }
    } elseif (is_string($stored_insight) && '' !== $stored_insight) {
        $variants = sitepulse_ai_prepare_insight_variants($stored_insight);
    }

    if ('' !== $variants['text']) {
        $cached_insight['text'] = $variants['text'];

        if ('' !== $variants['html']) {
            $cached_insight['html'] = $variants['html'];
        }
    }

    return $cached_insight;
}

/**
 * Builds a sanitized summary of the latest collected SitePulse metrics.
 *
 * @return string Sanitized summary or empty string when no metrics are available.
 */
function sitepulse_ai_get_metrics_summary() {
    $summary_parts = [];

    if (defined('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS')) {
        $speed_results = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        $ttfb_ms       = null;

        if (is_array($speed_results)) {
            $candidates = [
                ['server_processing_ms'],
                ['ttfb'],
                ['data', 'server_processing_ms'],
                ['data', 'ttfb'],
            ];

            foreach ($candidates as $path) {
                $value = $speed_results;

                foreach ($path as $segment) {
                    if (!is_array($value) || !array_key_exists($segment, $value)) {
                        $value = null;
                        break;
                    }

                    $value = $value[$segment];
                }

                if (is_numeric($value)) {
                    $ttfb_ms = (float) $value;
                    break;
                }
            }
        } elseif (is_numeric($speed_results)) {
            $ttfb_ms = (float) $speed_results;
        }

        if (null !== $ttfb_ms) {
            $summary_parts[] = sprintf(
                /* translators: %s: Average TTFB in milliseconds. */
                __('TTFB moyen observé : %s ms.', 'sitepulse'),
                number_format_i18n(round($ttfb_ms, 2), 2)
            );
        }
    }

    if (defined('SITEPULSE_OPTION_UPTIME_LOG')) {
        $uptime_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);

        if (!is_array($uptime_log)) {
            $uptime_log = [];
        }

        if (function_exists('sitepulse_normalize_uptime_log')) {
            $uptime_log = sitepulse_normalize_uptime_log($uptime_log);
        }

        $boolean_statuses = [];

        foreach ($uptime_log as $entry) {
            if (is_array($entry) && array_key_exists('status', $entry) && is_bool($entry['status'])) {
                $boolean_statuses[] = $entry['status'];
            } elseif (is_bool($entry)) {
                $boolean_statuses[] = $entry;
            } elseif (is_numeric($entry)) {
                $boolean_statuses[] = (bool) $entry;
            }
        }

        if (!empty($boolean_statuses)) {
            $total_checks = count($boolean_statuses);
            $up_checks    = count(array_filter($boolean_statuses));
            $uptime_pct   = $total_checks > 0 ? ($up_checks / $total_checks) * 100 : 0;

            $summary_parts[] = sprintf(
                /* translators: %s: Uptime percentage. */
                __('Disponibilité récemment mesurée : %s%%.', 'sitepulse'),
                number_format_i18n(round($uptime_pct, 2), 2)
            );
        }
    }

    if (defined('SITEPULSE_PLUGIN_IMPACT_OPTION')) {
        $impact_data = get_option(SITEPULSE_PLUGIN_IMPACT_OPTION, []);

        if (!is_array($impact_data)) {
            $impact_data = [];
        }

        $samples = isset($impact_data['samples']) && is_array($impact_data['samples'])
            ? $impact_data['samples']
            : [];

        $top_plugin = null;

        foreach ($samples as $plugin_file => $data) {
            if (!is_array($data)) {
                continue;
            }

            $avg_ms = isset($data['avg_ms']) && is_numeric($data['avg_ms']) ? (float) $data['avg_ms'] : null;

            if (null === $avg_ms) {
                continue;
            }

            $plugin_name = '';

            if (isset($data['name']) && is_scalar($data['name'])) {
                $plugin_name = (string) $data['name'];
            } elseif (isset($data['file']) && is_scalar($data['file'])) {
                $plugin_name = (string) $data['file'];
            } elseif (is_string($plugin_file)) {
                $plugin_name = $plugin_file;
            }

            if (!is_array($top_plugin) || $avg_ms > $top_plugin['avg_ms']) {
                $top_plugin = [
                    'name'   => sanitize_text_field(wp_strip_all_tags($plugin_name)),
                    'avg_ms' => $avg_ms,
                ];
            }
        }

        if (null !== $top_plugin && '' !== $top_plugin['name']) {
            $summary_parts[] = sprintf(
                /* translators: 1: Plugin name, 2: Average execution time in milliseconds. */
                __('Plugin le plus coûteux : %1$s (%2$s ms en moyenne).', 'sitepulse'),
                $top_plugin['name'],
                number_format_i18n(round($top_plugin['avg_ms'], 2), 2)
            );
        }
    }

    if (empty($summary_parts)) {
        return '';
    }

    $summary = implode(' ', $summary_parts);

    return sanitize_textarea_field($summary);
}

function sitepulse_ai_insights_enqueue_assets($hook_suffix) {
    if ('sitepulse-dashboard_page_sitepulse-ai' !== $hook_suffix) {
        return;
    }

    wp_register_style(
        'sitepulse-ai-insights-styles',
        SITEPULSE_URL . 'modules/css/ai-insights.css',
        [],
        SITEPULSE_VERSION
    );

    wp_register_script(
        'sitepulse-ai-insights',
        SITEPULSE_URL . 'modules/js/sitepulse-ai-insights.js',
        ['jquery'],
        SITEPULSE_VERSION,
        true
    );

    wp_enqueue_style('sitepulse-ai-insights-styles');

    $stored_insight     = sitepulse_ai_get_cached_insight();
    $insight_text       = isset($stored_insight['text']) ? $stored_insight['text'] : '';
    $insight_html       = isset($stored_insight['html']) ? $stored_insight['html'] : '';
    $insight_timestamp  = isset($stored_insight['timestamp']) ? absint($stored_insight['timestamp']) : null;
    $history_entries      = sitepulse_ai_get_history_entries();
    $history_models       = sitepulse_ai_get_history_filter_options($history_entries, 'model');
    $history_rate_limits  = sitepulse_ai_get_history_filter_options($history_entries, 'rate_limit');
    $history_max_entries  = sitepulse_ai_get_history_max_entries();
    $history_export_rows  = sitepulse_ai_prepare_history_export_rows($history_entries);
    $history_page_url     = admin_url('admin.php?page=sitepulse-ai');
    $history_export_name  = sanitize_file_name('sitepulse-ai-historique');
    $site_name            = wp_strip_all_tags(get_bloginfo('name', 'display'));
    $site_url             = home_url('/');
    $queue_snapshot       = sitepulse_ai_get_queue_snapshot();
    $jobs_log_entries     = sitepulse_ai_get_job_log();
    $jobs_log_payload     = sitepulse_ai_prepare_jobs_for_rest($jobs_log_entries);
    $jobs_metrics         = sitepulse_ai_calculate_job_metrics($jobs_log_entries);

    wp_localize_script(
        'sitepulse-ai-insights',
        'sitepulseAIInsights',
        [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT),
            'initialInsight'    => $insight_text,
            'initialInsightHtml' => $insight_html,
            'initialTimestamp'  => null !== $insight_timestamp ? absint($insight_timestamp) : null,
            'historyEntries'    => $history_entries,
            'historyFilters'    => [
                'models'     => $history_models,
                'rateLimits' => $history_rate_limits,
            ],
            'historyMaxEntries' => $history_max_entries,
            'historyExport'     => [
                'fileName' => $history_export_name,
                'rows'     => $history_export_rows,
                'headers'  => [
                    'timestamp_display' => esc_html__('Date', 'sitepulse'),
                    'model'             => esc_html__('Modèle', 'sitepulse'),
                    'rate_limit'        => esc_html__('Limitation', 'sitepulse'),
                    'text'              => esc_html__('Recommandation', 'sitepulse'),
                    'note'              => esc_html__('Note', 'sitepulse'),
                ],
                'columns' => ['timestamp_display', 'model', 'rate_limit', 'text', 'note'],
            ],
            'historyContext'    => [
                'pageUrl'  => esc_url_raw($history_page_url),
                'siteName' => $site_name,
                'siteUrl'  => esc_url_raw($site_url),
            ],
            'initialQueue'      => $queue_snapshot,
            'jobsLog'           => $jobs_log_payload,
            'jobsMetrics'       => $jobs_metrics,
            'noteAction'        => 'sitepulse_save_ai_history_note',
            'strings'           => [
                'defaultError'    => esc_html__('Une erreur inattendue est survenue. Veuillez réessayer.', 'sitepulse'),
                'cachedPrefix'    => esc_html__('Dernière mise à jour :', 'sitepulse'),
                'statusCached'    => esc_html__('Résultat issu du cache.', 'sitepulse'),
                'statusFresh'     => esc_html__('Nouvelle analyse générée.', 'sitepulse'),
                'statusFreshForced' => esc_html__('Nouvelle analyse générée (rafraîchie manuellement).', 'sitepulse'),
                'statusGenerating' => esc_html__('Génération en cours…', 'sitepulse'),
                'statusQueued'    => esc_html__('Analyse en attente de traitement…', 'sitepulse'),
                'statusPending'   => esc_html__('Nouvelle tentative programmée…', 'sitepulse'),
                'statusFailed'    => esc_html__('La génération a échoué. Veuillez réessayer.', 'sitepulse'),
                'statusQueuedSince' => esc_html__('Analyse en attente depuis %s.', 'sitepulse'),
                'statusRunningSince' => esc_html__('Analyse en cours depuis %s.', 'sitepulse'),
                'statusFinishedAt' => esc_html__('Terminée le %s.', 'sitepulse'),
                'statusFallbackSynchronous' => esc_html__('Analyse exécutée immédiatement (WP-Cron indisponible).', 'sitepulse'),
                'historyEmpty'    => esc_html__('Aucun historique disponible pour le moment.', 'sitepulse'),
                'historyExportCsv' => esc_html__('Exporter en CSV', 'sitepulse'),
                'historyCopy'     => esc_html__('Copier', 'sitepulse'),
                'historyCopied'   => esc_html__('Historique copié dans le presse-papiers.', 'sitepulse'),
                'historyCopyError' => esc_html__('Impossible de copier l’historique. Veuillez réessayer.', 'sitepulse'),
                'historyDownload' => esc_html__('Téléchargement de l’historique démarré.', 'sitepulse'),
                'historyNoEntries' => esc_html__('Aucune recommandation à exporter pour ces filtres.', 'sitepulse'),
                'historyNoteLabel' => esc_html__('Note personnelle', 'sitepulse'),
                'historyNotePlaceholder' => esc_html__('Ajoutez un commentaire ou un plan d’action…', 'sitepulse'),
                'historyNoteSaved' => esc_html__('Note enregistrée.', 'sitepulse'),
                'historyNoteError' => esc_html__('Échec de l’enregistrement de la note.', 'sitepulse'),
                'historyAriaDefault' => esc_html__('Mise à jour de l’historique.', 'sitepulse'),
                'queuePosition'   => esc_html__('Position %1$d sur %2$d', 'sitepulse'),
                'queueNextAttempt'=> esc_html__('Prochain essai : %s', 'sitepulse'),
            ],
            'initialCached'     => '' !== $insight_text,
            'initialForceRefresh' => false,
            'initialFallback'   => '',
            'initialCreatedAt'  => null,
            'initialStartedAt'  => null,
            'initialFinishedAt' => null,
            'statusAction'      => 'sitepulse_get_ai_insight_status',
            'pollInterval'      => 5000,
        ]
    );

    wp_enqueue_script('sitepulse-ai-insights');
}

function sitepulse_ai_insights_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $wp_cron_disabled = sitepulse_ai_is_wp_cron_disabled();

    $api_key = sitepulse_get_gemini_api_key();
    $available_models = sitepulse_get_ai_models();
    $default_model = sitepulse_get_default_ai_model();
    $selected_model = (string) get_option(SITEPULSE_OPTION_AI_MODEL, $default_model);
    $history_entries = sitepulse_ai_get_history_entries();
    $history_model_filters = sitepulse_ai_get_history_filter_options($history_entries, 'model');
    $history_rate_filters = sitepulse_ai_get_history_filter_options($history_entries, 'rate_limit');

    if (!isset($available_models[$selected_model])) {
        $selected_model = $default_model;
    }

    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-ai');
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-superhero"></span> <?php esc_html_e('Analyses par IA', 'sitepulse'); ?></h1>
        <p><?php esc_html_e("Obtenez des recommandations personnalisées pour votre site en analysant ses données de performance avec l'IA Gemini de Google.", 'sitepulse'); ?></p>
        <?php if ($wp_cron_disabled) : ?>
            <div class="notice notice-warning">
                <p><?php echo wp_kses(
                    __('WP-Cron est désactivé. SitePulse exécutera les analyses à la demande, mais réactivez-le pour automatiser les traitements (retirez la constante <code>DISABLE_WP_CRON</code> de wp-config.php ou configurez une tâche cron serveur).', 'sitepulse'),
                    ['code' => []]
                ); ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($available_models)) : ?>
            <div class="notice notice-info sitepulse-ai-info-notice">
                <h2><?php esc_html_e('Choix du modèle IA', 'sitepulse'); ?></h2>
                <p><?php echo wp_kses(
                    sprintf(
                        /* translators: %s: URL to the SitePulse settings page. */
                        __('Le modèle sélectionné dans les réglages (<a href="%s">Réglages &gt; IA</a>) influence la granularité des recommandations et le temps de génération.', 'sitepulse'),
                        esc_url(admin_url('admin.php?page=sitepulse-settings#sitepulse_ai_model'))
                    ),
                    ['a' => ['href' => true]]
                ); ?></p>
                <ul>
                    <?php foreach ($available_models as $model_key => $model_data) :
                        $label = isset($model_data['label']) ? $model_data['label'] : $model_key;
                        $description = isset($model_data['description']) ? $model_data['description'] : '';
                    ?>
                        <li>
                            <strong><?php echo esc_html($label); ?></strong>
                            <?php if ($selected_model === $model_key) : ?>
                                <em><?php esc_html_e(' (actuellement utilisé)', 'sitepulse'); ?></em>
                            <?php endif; ?>
                            <?php if ($description !== '') : ?> — <?php echo esc_html($description); ?><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (empty($api_key)) : ?>
            <div class="notice notice-warning"><p><?php echo wp_kses_post(sprintf(__('Veuillez <a href="%s">entrer votre clé API Google Gemini</a> pour utiliser cette fonctionnalité.', 'sitepulse'), esc_url(admin_url('admin.php?page=sitepulse-settings')))); ?></p></div>
        <?php else : ?>
            <div class="sitepulse-ai-insight-actions" aria-busy="false">
                <button type="button" id="sitepulse-ai-generate" class="button button-primary"><?php esc_html_e('Générer une Analyse', 'sitepulse'); ?></button>
                <label for="sitepulse-ai-force-refresh" class="sitepulse-ai-force-refresh">
                    <input type="checkbox" id="sitepulse-ai-force-refresh" />
                    <?php esc_html_e('Forcer une nouvelle analyse', 'sitepulse'); ?>
                </label>
                <span class="spinner sitepulse-ai-spinner" id="sitepulse-ai-spinner" aria-hidden="true"></span>
            </div>
        <?php endif; ?>
        <div id="sitepulse-ai-insight-error" class="notice notice-error sitepulse-ai-error" role="alert" tabindex="-1"><p></p></div>
        <div id="sitepulse-ai-insight-result" class="sitepulse-ai-result">
            <h2><?php esc_html_e('Votre Recommandation par IA', 'sitepulse'); ?></h2>
            <p class="sitepulse-ai-insight-status" role="status" aria-live="polite" aria-hidden="true"></p>
            <div id="sitepulse-ai-queue-state" class="sitepulse-ai-queue-state" aria-live="polite" aria-atomic="true">
                <p class="sitepulse-ai-queue-summary"></p>
                <p class="sitepulse-ai-queue-next"></p>
                <p class="sitepulse-ai-queue-usage"></p>
            </div>
            <div class="sitepulse-ai-insight-text"></div>
            <p class="sitepulse-ai-insight-timestamp"></p>
        </div>
        <div class="sitepulse-ai-observability-card">
            <?php sitepulse_ai_render_observability_widget(); ?>
        </div>
        <div id="sitepulse-ai-history" class="sitepulse-ai-history">
            <h2><?php esc_html_e('Historique des recommandations', 'sitepulse'); ?></h2>
            <div class="sitepulse-ai-history-filters">
                <label for="sitepulse-ai-history-filter-model">
                    <?php esc_html_e('Modèle', 'sitepulse'); ?>
                    <select id="sitepulse-ai-history-filter-model">
                        <option value=""><?php esc_html_e('Tous les modèles', 'sitepulse'); ?></option>
                        <?php foreach ($history_model_filters as $filter_option) :
                            $option_value = isset($filter_option['key']) ? (string) $filter_option['key'] : '';
                            $option_label = isset($filter_option['label']) ? (string) $filter_option['label'] : $option_value;
                            if ('' === $option_value) {
                                continue;
                            }
                        ?>
                            <option value="<?php echo esc_attr($option_value); ?>"><?php echo esc_html($option_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label for="sitepulse-ai-history-filter-rate">
                    <?php esc_html_e('Limitation', 'sitepulse'); ?>
                    <select id="sitepulse-ai-history-filter-rate">
                        <option value=""><?php esc_html_e('Toutes les limitations', 'sitepulse'); ?></option>
                        <?php foreach ($history_rate_filters as $filter_option) :
                            $option_value = isset($filter_option['key']) ? (string) $filter_option['key'] : '';
                            $option_label = isset($filter_option['label']) ? (string) $filter_option['label'] : $option_value;
                            if ('' === $option_value) {
                                continue;
                            }
                        ?>
                            <option value="<?php echo esc_attr($option_value); ?>"><?php echo esc_html($option_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="sitepulse-ai-history-toolbar" role="region" aria-label="<?php echo esc_attr__('Actions d’historique', 'sitepulse'); ?>">
                <button type="button" id="sitepulse-ai-history-export-csv" class="button button-secondary">
                    <?php esc_html_e('Exporter en CSV', 'sitepulse'); ?>
                </button>
                <button type="button" id="sitepulse-ai-history-copy" class="button">
                    <?php esc_html_e('Copier', 'sitepulse'); ?>
                </button>
            </div>
            <p id="sitepulse-ai-history-feedback" class="screen-reader-text" aria-live="polite" aria-atomic="true"></p>
            <p id="sitepulse-ai-history-empty" class="sitepulse-ai-history-empty"<?php if (!empty($history_entries)) : ?> style="display:none;"<?php endif; ?>>
                <?php esc_html_e('Aucun historique disponible pour le moment.', 'sitepulse'); ?>
            </p>
            <ul id="sitepulse-ai-history-list" class="sitepulse-ai-history-list">
                <?php foreach ($history_entries as $entry) :
                    $entry_id = isset($entry['id']) ? (string) $entry['id'] : '';
                    $model_key = isset($entry['model']['key']) ? (string) $entry['model']['key'] : '';
                    $model_label = isset($entry['model']['label']) ? (string) $entry['model']['label'] : '';
                    $rate_key = isset($entry['rate_limit']['key']) ? (string) $entry['rate_limit']['key'] : '';
                    $rate_label = isset($entry['rate_limit']['label']) ? (string) $entry['rate_limit']['label'] : '';
                    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                    $meta_parts = [];

                    if ($timestamp > 0) {
                        if (function_exists('date_i18n')) {
                            $meta_parts[] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
                        } else {
                            $meta_parts[] = gmdate('Y-m-d H:i:s', $timestamp);
                        }
                    }

                    if ('' !== $model_label) {
                        $meta_parts[] = $model_label;
                    }

                    if ('' !== $rate_label) {
                        $meta_parts[] = $rate_label;
                    }

                    $meta_parts = array_filter(array_map('trim', $meta_parts), 'strlen');
                ?>
                    <li class="sitepulse-ai-history-item" data-entry-id="<?php echo esc_attr($entry_id); ?>" data-model="<?php echo esc_attr($model_key); ?>" data-rate-limit="<?php echo esc_attr($rate_key); ?>">
                        <?php if (!empty($meta_parts)) : ?>
                            <p class="sitepulse-ai-history-meta"><?php echo esc_html(implode(' • ', $meta_parts)); ?></p>
                        <?php endif; ?>
                        <div class="sitepulse-ai-history-text">
                            <?php
                            if (!empty($entry['html'])) {
                                echo wp_kses_post($entry['html']);
                            } else {
                                echo esc_html($entry['text']);
                            }
                            ?>
                        </div>
                        <div class="sitepulse-ai-history-note">
                            <label for="sitepulse-ai-history-note-<?php echo esc_attr($entry_id); ?>"><?php esc_html_e('Note personnelle', 'sitepulse'); ?></label>
                            <textarea
                                id="sitepulse-ai-history-note-<?php echo esc_attr($entry_id); ?>"
                                class="sitepulse-ai-history-note-field"
                                data-entry-id="<?php echo esc_attr($entry_id); ?>"
                                rows="2"
                                placeholder="<?php echo esc_attr__('Ajoutez un commentaire ou un plan d’action…', 'sitepulse'); ?>"
                            ><?php echo isset($entry['note']) ? esc_textarea($entry['note']) : ''; ?></textarea>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}

function sitepulse_ai_save_history_note() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error([
            'message' => esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse'),
        ], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_AI_INSIGHT)) {
        wp_send_json_error([
            'message' => esc_html__('La sécurité de la requête n’a pas pu être vérifiée.', 'sitepulse'),
        ], 400);
    }

    $entry_id = isset($_POST['entry_id']) ? sanitize_key((string) wp_unslash($_POST['entry_id'])) : '';

    if ('' === $entry_id) {
        wp_send_json_error([
            'message' => esc_html__('Identifiant de recommandation manquant.', 'sitepulse'),
        ], 400);
    }

    $raw_note = isset($_POST['note']) ? wp_unslash($_POST['note']) : '';
    $note     = sanitize_textarea_field((string) $raw_note);

    $notes = sitepulse_ai_get_history_notes();

    if ('' === $note) {
        if (isset($notes[$entry_id])) {
            unset($notes[$entry_id]);
            sitepulse_ai_update_history_notes($notes);
        }
    } else {
        $notes[$entry_id] = $note;
        sitepulse_ai_update_history_notes($notes);
    }

    wp_send_json_success([
        'entryId' => $entry_id,
        'note'    => $note,
    ]);
}

function sitepulse_generate_ai_insight() {
    if (!current_user_can(sitepulse_get_capability())) {
        $error_message = esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_AI_INSIGHT)) {
        $error_message = esc_html__('Échec de la vérification de sécurité. Veuillez recharger la page et réessayer.', 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 400);
    }

    $force_refresh = false;

    if (isset($_POST['force_refresh'])) {
        $force_refresh = filter_var(wp_unslash($_POST['force_refresh']), FILTER_VALIDATE_BOOLEAN);
    }

    $cached_payload = sitepulse_ai_get_cached_insight();

    if (!$force_refresh && !empty($cached_payload)) {
        $history_entries = sitepulse_ai_get_history_entries();

        if (!empty($history_entries)) {
            $latest_entry = $history_entries[0];

            if (isset($latest_entry['model'])) {
                $cached_payload['model'] = $latest_entry['model'];
            }

            if (isset($latest_entry['rate_limit'])) {
                $cached_payload['rate_limit'] = $latest_entry['rate_limit'];
            }

            if (isset($latest_entry['id'])) {
                $cached_payload['id'] = $latest_entry['id'];
            }

            if (isset($latest_entry['note'])) {
                $cached_payload['note'] = $latest_entry['note'];
            }
        }

        $cached_payload['cached'] = true;
        wp_send_json_success($cached_payload);
    }

    $environment = sitepulse_ai_prepare_environment();

    if (is_wp_error($environment)) {
        $status_code = sitepulse_ai_get_error_status_code($environment, 400);

        wp_send_json_error([
            'message' => $environment->get_error_message(),
        ], $status_code);
    }

    $now_utc              = absint(current_time('timestamp', true));
    $retry_after_timestamp = sitepulse_ai_get_retry_after_timestamp();

    if ($retry_after_timestamp > 0) {
        if ($retry_after_timestamp <= $now_utc) {
            sitepulse_ai_set_retry_after_timestamp(0);
        } else {
            $time_remaining = max(0, $retry_after_timestamp - $now_utc);
            $delay_payload  = [
                'retry_after' => $time_remaining,
                'retry_at'    => $retry_after_timestamp,
            ];
            $human_delay    = function_exists('human_time_diff')
                ? human_time_diff($now_utc, $retry_after_timestamp)
                : sprintf('%ds', max(1, $time_remaining));

            if (!empty($cached_payload)) {
                $cached_payload['cached'] = true;
                $cached_payload = array_merge($cached_payload, $delay_payload);

                wp_send_json_success($cached_payload);
            }

            $error_message = sprintf(
                /* translators: %s: human readable delay. */
                esc_html__('Gemini impose une période d’attente. Réessayez dans %s.', 'sitepulse'),
                $human_delay
            );

            wp_send_json_error(array_merge([
                'message' => $error_message,
            ], $delay_payload), 429);
        }
    }

    $rate_limit_value   = sitepulse_ai_get_current_rate_limit_value();
    $rate_limit_window  = sitepulse_ai_get_rate_limit_window_seconds($rate_limit_value);
    $last_run_timestamp = (int) get_option(SITEPULSE_OPTION_AI_LAST_RUN, 0);

    if ($rate_limit_window > 0 && $last_run_timestamp > 0) {
        $next_allowed = $last_run_timestamp + $rate_limit_window;

        if ($next_allowed > $now_utc) {
            $time_remaining = max(0, $next_allowed - $now_utc);
            $delay_payload  = [
                'retry_after' => $time_remaining,
                'retry_at'    => $next_allowed,
            ];

            if (!empty($cached_payload)) {
                $cached_payload['cached'] = true;
                $cached_payload = array_merge($cached_payload, $delay_payload);
                wp_send_json_success($cached_payload);
            }

            $human_delay = human_time_diff($now_utc, $next_allowed);
            $error_message = sprintf(
                /* translators: 1: Human readable delay (e.g. "5 minutes"), 2: rate limit label. */
                esc_html__('La génération par IA est limitée à %2$s. Réessayez dans %1$s.', 'sitepulse'),
                $human_delay,
                sitepulse_ai_get_rate_limit_label($rate_limit_value)
            );

            wp_send_json_error(array_merge([
                'message' => $error_message,
            ], $delay_payload), 429);
        }
    }

    if ($force_refresh) {
        sitepulse_ai_get_cached_insight(true);
    }

    $job_id = sitepulse_ai_schedule_generation_job($force_refresh);

    if (is_wp_error($job_id)) {
        $status_code = sitepulse_ai_get_error_status_code($job_id, 500);
        $error_payload = [
            'message' => $job_id->get_error_message(),
        ];

        $retry_after = sitepulse_ai_get_error_retry_after($job_id);

        if ($retry_after > 0) {
            $error_payload['retry_after'] = $retry_after;

            $retry_at = sitepulse_ai_get_error_retry_at($job_id);

            if ($retry_at > 0) {
                $error_payload['retry_at'] = $retry_at;
            }
        }

        wp_send_json_error($error_payload, $status_code);
    }

    $job_snapshot = sitepulse_ai_get_job_data($job_id);
    $queue_payload = !empty($job_snapshot) ? sitepulse_ai_format_queue_payload($job_id, $job_snapshot) : [];

    wp_send_json_success([
        'jobId'  => $job_id,
        'status' => 'queued',
        'queue'  => $queue_payload,
    ]);
}

/**
 * AJAX handler returning the current status of an AI insight job.
 *
 * @return void
 */
function sitepulse_get_ai_insight_status() {
    if (!current_user_can(sitepulse_get_capability())) {
        $error_message = esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_AI_INSIGHT)) {
        $error_message = esc_html__('Échec de la vérification de sécurité. Veuillez recharger la page et réessayer.', 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 400);
    }

    $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';

    if ('' === $job_id) {
        $error_message = esc_html__('Identifiant de tâche manquant.', 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 400);
    }

    $job_data = sitepulse_ai_get_job_data($job_id);

    if (empty($job_data)) {
        $error_message = esc_html__('Tâche introuvable ou expirée. Veuillez relancer une génération.', 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 404);
    }

    $status = isset($job_data['status']) ? (string) $job_data['status'] : 'queued';

    $response = [
        'status' => $status,
    ];

    if (isset($job_data['created_at'])) {
        $response['created_at'] = (int) $job_data['created_at'];
    }

    if (isset($job_data['started_at'])) {
        $response['started_at'] = (int) $job_data['started_at'];
    }

    if (isset($job_data['finished'])) {
        $response['finished_at'] = (int) $job_data['finished'];
    }

    if (isset($job_data['force_refresh'])) {
        $response['force_refresh'] = (bool) $job_data['force_refresh'];
    }

    if (isset($job_data['fallback'])) {
        $response['fallback'] = sanitize_text_field((string) $job_data['fallback']);
    }

    $response['queue'] = sitepulse_ai_format_queue_payload($job_id, $job_data);

    if ('completed' === $status && isset($job_data['result']) && is_array($job_data['result'])) {
        $response['result'] = $job_data['result'];
    } elseif ('failed' === $status) {
        $response['message'] = isset($job_data['message']) ? (string) $job_data['message'] : esc_html__('La génération de l’analyse IA a échoué.', 'sitepulse');
        if (isset($job_data['code'])) {
            $response['code'] = (int) $job_data['code'];
        }
        if (isset($job_data['retry_after'])) {
            $response['retry_after'] = (int) $job_data['retry_after'];
        }

        if (isset($job_data['retry_at'])) {
            $response['retry_at'] = (int) $job_data['retry_at'];
        }
    }

    if (in_array($status, ['completed', 'failed'], true)) {
        sitepulse_ai_delete_job_data($job_id);
    }

    wp_send_json_success($response);
}
