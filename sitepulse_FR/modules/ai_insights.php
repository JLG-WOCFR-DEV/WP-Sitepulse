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
add_action('sitepulse_run_ai_insight_job', 'sitepulse_run_ai_insight_job', 10, 1);
add_action('admin_notices', 'sitepulse_ai_render_error_notices');

if (!defined('SITEPULSE_TRANSIENT_AI_INSIGHT_JOB_PREFIX')) {
    define('SITEPULSE_TRANSIENT_AI_INSIGHT_JOB_PREFIX', 'sitepulse_ai_job_');
}

if (!defined('SITEPULSE_OPTION_AI_INSIGHT_ERRORS')) {
    define('SITEPULSE_OPTION_AI_INSIGHT_ERRORS', 'sitepulse_ai_insight_errors');
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

    return set_transient($key, $job_data, $expiration);
}

/**
 * Records a critical AI Insights error for logging and admin notice purposes.
 *
 * @param string   $message     Error message.
 * @param int|null $status_code Optional HTTP status code or contextual code.
 *
 * @return void
 */
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

        update_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS, $stored);
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
 * Creates a WP_Error instance while logging the associated message.
 *
 * @param string   $code        Error code.
 * @param string   $message     Human readable message.
 * @param int|null $status_code Optional status code for context.
 *
 * @return WP_Error
 */
function sitepulse_ai_create_wp_error($code, $message, $status_code = null) {
    sitepulse_ai_record_critical_error($message, $status_code);

    $data = [];

    if (null !== $status_code) {
        $data['status_code'] = (int) $status_code;
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
 * Validates the AI environment and returns configuration details.
 *
 * @return array{api_key:string,available_models:array<string,mixed>,selected_model:string}|WP_Error
 */
function sitepulse_ai_prepare_environment() {
    $api_key = trim((string) get_option(SITEPULSE_OPTION_GEMINI_API_KEY));

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
 * @return array{text:string,timestamp:int,cached:bool}|WP_Error
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

    if ($status_code < 200 || $status_code >= 300) {
        $error_detail = '';

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

        return sitepulse_ai_create_wp_error('sitepulse_ai_http_error', $error_message, $status_code);
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

    $generated_text = sanitize_textarea_field($generated_text);
    $timestamp      = absint(current_time('timestamp', true));

    set_transient(
        SITEPULSE_TRANSIENT_AI_INSIGHT,
        [
            'text'      => $generated_text,
            'timestamp' => $timestamp,
        ],
        HOUR_IN_SECONDS
    );

    sitepulse_ai_get_cached_insight(true);

    $fresh_payload = sitepulse_ai_get_cached_insight();

    if (empty($fresh_payload)) {
        $fresh_payload = [
            'text'      => $generated_text,
            'timestamp' => $timestamp,
        ];
    }

    return [
        'text'      => isset($fresh_payload['text']) ? $fresh_payload['text'] : $generated_text,
        'timestamp' => isset($fresh_payload['timestamp']) ? $fresh_payload['timestamp'] : $timestamp,
        'cached'    => false,
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

    $job_data = [
        'status'        => 'queued',
        'created_at'    => time(),
        'force_refresh' => (bool) $force_refresh,
    ];

    if (!sitepulse_ai_save_job_data($job_id, $job_data)) {
        $error_message = esc_html__('Impossible de planifier la génération de l’analyse IA. Veuillez réessayer.', 'sitepulse');

        return sitepulse_ai_create_wp_error('sitepulse_ai_job_storage_failed', $error_message, 500);
    }

    $scheduled = wp_schedule_single_event(time(), 'sitepulse_run_ai_insight_job', [$job_id]);

    if (false === $scheduled) {
        $error_message = esc_html__('La planification du traitement IA a échoué. Veuillez réessayer ultérieurement.', 'sitepulse');

        sitepulse_ai_save_job_data($job_id, array_merge($job_data, [
            'status'  => 'failed',
            'message' => $error_message,
        ]));

        return sitepulse_ai_create_wp_error('sitepulse_ai_job_schedule_failed', $error_message, 500);
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
function sitepulse_run_ai_insight_job($job_id) {
    $job_id = (string) $job_id;

    if ('' === $job_id) {
        return;
    }

    $job_data = sitepulse_ai_get_job_data($job_id);

    $job_data['status']     = 'running';
    $job_data['started_at'] = time();

    sitepulse_ai_save_job_data($job_id, $job_data);

    try {
        $environment = sitepulse_ai_prepare_environment();

        if (is_wp_error($environment)) {
            $error_message = $environment->get_error_message();
            $status_code   = sitepulse_ai_get_error_status_code($environment, 500);

            sitepulse_ai_save_job_data($job_id, array_merge($job_data, [
                'status'  => 'failed',
                'message' => $error_message,
                'code'    => $status_code,
            ]));

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

            sitepulse_ai_save_job_data($job_id, array_merge($job_data, [
                'status'   => 'failed',
                'message'  => $error_message,
                'code'     => $status_code,
                'finished' => time(),
            ]));

            return;
        }

        sitepulse_ai_save_job_data($job_id, array_merge($job_data, [
            'status'     => 'completed',
            'result'     => $result,
            'finished'   => time(),
        ]));

        update_option(SITEPULSE_OPTION_AI_LAST_RUN, absint(current_time('timestamp', true)));
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
            'finished' => time(),
        ]));
    }
}

/**
 * Retrieves the cached AI insight payload for the current request.
 *
 * @param bool $force_refresh When true, clears the transient cache and resets the in-request cache.
 *
 * @return array{text?:string,timestamp?:int}
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

    if (is_array($stored_insight) && isset($stored_insight['text'])) {
        $cached_text = sanitize_textarea_field($stored_insight['text']);

        if ('' !== $cached_text) {
            $cached_insight['text'] = $cached_text;

            if (isset($stored_insight['timestamp'])) {
                $cached_insight['timestamp'] = (int) $stored_insight['timestamp'];
            }
        }
    } elseif (is_string($stored_insight) && '' !== $stored_insight) {
        $cached_text = sanitize_textarea_field($stored_insight);

        if ('' !== $cached_text) {
            $cached_insight['text'] = $cached_text;
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

    $stored_insight    = sitepulse_ai_get_cached_insight();
    $insight_text      = isset($stored_insight['text']) ? $stored_insight['text'] : '';
    $insight_timestamp = isset($stored_insight['timestamp']) ? absint($stored_insight['timestamp']) : null;

    wp_localize_script(
        'sitepulse-ai-insights',
        'sitepulseAIInsights',
        [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT),
            'initialInsight'    => $insight_text,
            'initialTimestamp'  => null !== $insight_timestamp ? absint($insight_timestamp) : null,
            'strings'           => [
                'defaultError'    => esc_html__('Une erreur inattendue est survenue. Veuillez réessayer.', 'sitepulse'),
                'cachedPrefix'    => esc_html__('Dernière mise à jour :', 'sitepulse'),
                'statusCached'    => esc_html__('Résultat issu du cache.', 'sitepulse'),
                'statusFresh'     => esc_html__('Nouvelle analyse générée.', 'sitepulse'),
                'statusGenerating' => esc_html__('Génération en cours…', 'sitepulse'),
                'statusQueued'    => esc_html__('Analyse en attente de traitement…', 'sitepulse'),
                'statusFailed'    => esc_html__('La génération a échoué. Veuillez réessayer.', 'sitepulse'),
            ],
            'initialCached'     => '' !== $insight_text,
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

    $api_key = get_option(SITEPULSE_OPTION_GEMINI_API_KEY);
    $available_models = sitepulse_get_ai_models();
    $default_model = sitepulse_get_default_ai_model();
    $selected_model = (string) get_option(SITEPULSE_OPTION_AI_MODEL, $default_model);

    if (!isset($available_models[$selected_model])) {
        $selected_model = $default_model;
    }

    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-superhero"></span> <?php esc_html_e('Analyses par IA', 'sitepulse'); ?></h1>
        <p><?php esc_html_e("Obtenez des recommandations personnalisées pour votre site en analysant ses données de performance avec l'IA Gemini de Google.", 'sitepulse'); ?></p>
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
            <p class="sitepulse-ai-insight-text"></p>
            <p class="sitepulse-ai-insight-timestamp"></p>
        </div>
    </div>
    <?php
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

    $rate_limit_value   = sitepulse_ai_get_current_rate_limit_value();
    $rate_limit_window  = sitepulse_ai_get_rate_limit_window_seconds($rate_limit_value);
    $last_run_timestamp = (int) get_option(SITEPULSE_OPTION_AI_LAST_RUN, 0);

    if ($rate_limit_window > 0 && $last_run_timestamp > 0) {
        $now_utc = absint(current_time('timestamp', true));
        $next_allowed = $last_run_timestamp + $rate_limit_window;

        if ($next_allowed > $now_utc) {
            if (!empty($cached_payload)) {
                $cached_payload['cached'] = true;
                wp_send_json_success($cached_payload);
            }

            $time_remaining = max(0, $next_allowed - $now_utc);
            $human_delay = human_time_diff($now_utc, $next_allowed);
            $error_message = sprintf(
                /* translators: 1: Human readable delay (e.g. "5 minutes"), 2: rate limit label. */
                esc_html__('La génération par IA est limitée à %2$s. Réessayez dans %1$s.', 'sitepulse'),
                $human_delay,
                sitepulse_ai_get_rate_limit_label($rate_limit_value)
            );

            wp_send_json_error([
                'message' => $error_message,
                'retry_after' => $time_remaining,
            ], 429);
        }
    }

    if ($force_refresh) {
        sitepulse_ai_get_cached_insight(true);
    }

    $job_id = sitepulse_ai_schedule_generation_job($force_refresh);

    if (is_wp_error($job_id)) {
        $status_code = sitepulse_ai_get_error_status_code($job_id, 500);

        wp_send_json_error([
            'message' => $job_id->get_error_message(),
        ], $status_code);
    }

    wp_send_json_success([
        'jobId'  => $job_id,
        'status' => 'queued',
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

    if ('completed' === $status && isset($job_data['result']) && is_array($job_data['result'])) {
        $response['result'] = $job_data['result'];
    } elseif ('failed' === $status) {
        $response['message'] = isset($job_data['message']) ? (string) $job_data['message'] : esc_html__('La génération de l’analyse IA a échoué.', 'sitepulse');
        if (isset($job_data['code'])) {
            $response['code'] = (int) $job_data['code'];
        }
    }

    wp_send_json_success($response);
}
