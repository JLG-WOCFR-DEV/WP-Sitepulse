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

if (!defined('SITEPULSE_OPTION_AI_HISTORY')) {
    define('SITEPULSE_OPTION_AI_HISTORY', 'sitepulse_ai_history');
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
 * Normalizes a raw AI history entry.
 *
 * @param array<string,mixed> $entry Raw history entry data.
 *
 * @return array{
 *     text:string,
 *     html:string,
 *     timestamp:int,
 *     model:array{key:string,label:string},
 *     rate_limit:array{key:string,label:string}
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

    return [
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
    ];
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

    update_option(SITEPULSE_OPTION_AI_HISTORY, array_values($history));
}

/**
 * Retrieves the stored AI insight history entries ordered from newest to oldest.
 *
 * @return array<int,array{
 *     text:string,
 *     html:string,
 *     timestamp:int,
 *     model:array{key:string,label:string},
 *     rate_limit:array{key:string,label:string}
 * }>
 */
function sitepulse_ai_get_history_entries() {
    $history = function_exists('get_option') ? get_option(SITEPULSE_OPTION_AI_HISTORY, []) : [];

    if (!is_array($history)) {
        $history = [];
    }

    $normalized = [];

    foreach ($history as $entry) {
        $normalized_entry = sitepulse_ai_normalize_history_entry($entry);

        if (null === $normalized_entry) {
            continue;
        }

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

    return [
        'text'      => isset($fresh_payload['text']) ? $fresh_payload['text'] : $variants['text'],
        'html'      => isset($fresh_payload['html']) ? $fresh_payload['html'] : $variants['html'],
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
        $guidance_message = esc_html__('WP-Cron semble désactivé sur ce site. L’analyse IA sera exécutée immédiatement, mais pensez à réactiver WP-Cron (retirez DISABLE_WP_CRON de wp-config.php ou planifiez une tâche serveur).', 'sitepulse');
        $fallback_message = esc_html__('La planification du traitement IA a échoué. L’analyse est exécutée immédiatement.', 'sitepulse');

        sitepulse_ai_save_job_data($job_id, array_merge($job_data, [
            'fallback' => 'synchronous',
        ]));

        if (sitepulse_ai_is_wp_cron_disabled()) {
            sitepulse_ai_record_critical_error($guidance_message);
        } else {
            sitepulse_ai_record_critical_error($fallback_message);
        }

        sitepulse_run_ai_insight_job($job_id);

        $job_state = sitepulse_ai_get_job_data($job_id);

        if (!is_array($job_state) || !isset($job_state['status'])) {
            return sitepulse_ai_create_wp_error('sitepulse_ai_job_schedule_failed', $fallback_message, 500);
        }

        if ('completed' === $job_state['status']) {
            return $job_id;
        }

        $error_message = isset($job_state['message']) ? (string) $job_state['message'] : $fallback_message;
        $status_code   = isset($job_state['code']) ? (int) $job_state['code'] : 500;

        return sitepulse_ai_create_wp_error('sitepulse_ai_job_schedule_failed', $error_message, $status_code);
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

        $result_with_context = array_merge($result, [
            'model'      => $history_entry['model'],
            'rate_limit' => $history_entry['rate_limit'],
        ]);

        sitepulse_ai_save_job_data($job_id, array_merge($job_data, [
            'status'     => 'completed',
            'result'     => $result_with_context,
            'finished'   => time(),
        ]));

        sitepulse_ai_record_history_entry($history_entry);

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
    $history_entries    = sitepulse_ai_get_history_entries();
    $history_models     = sitepulse_ai_get_history_filter_options($history_entries, 'model');
    $history_rate_limits = sitepulse_ai_get_history_filter_options($history_entries, 'rate_limit');
    $history_max_entries = sitepulse_ai_get_history_max_entries();

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
            'strings'           => [
                'defaultError'    => esc_html__('Une erreur inattendue est survenue. Veuillez réessayer.', 'sitepulse'),
                'cachedPrefix'    => esc_html__('Dernière mise à jour :', 'sitepulse'),
                'statusCached'    => esc_html__('Résultat issu du cache.', 'sitepulse'),
                'statusFresh'     => esc_html__('Nouvelle analyse générée.', 'sitepulse'),
                'statusGenerating' => esc_html__('Génération en cours…', 'sitepulse'),
                'statusQueued'    => esc_html__('Analyse en attente de traitement…', 'sitepulse'),
                'statusFailed'    => esc_html__('La génération a échoué. Veuillez réessayer.', 'sitepulse'),
                'historyEmpty'    => esc_html__('Aucun historique disponible pour le moment.', 'sitepulse'),
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
            <div class="sitepulse-ai-insight-text"></div>
            <p class="sitepulse-ai-insight-timestamp"></p>
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
            <p id="sitepulse-ai-history-empty" class="sitepulse-ai-history-empty"<?php if (!empty($history_entries)) : ?> style="display:none;"<?php endif; ?>>
                <?php esc_html_e('Aucun historique disponible pour le moment.', 'sitepulse'); ?>
            </p>
            <ul id="sitepulse-ai-history-list" class="sitepulse-ai-history-list">
                <?php foreach ($history_entries as $entry) :
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
                    <li class="sitepulse-ai-history-item" data-model="<?php echo esc_attr($model_key); ?>" data-rate-limit="<?php echo esc_attr($rate_key); ?>">
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
                    </li>
                <?php endforeach; ?>
            </ul>
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
        $history_entries = sitepulse_ai_get_history_entries();

        if (!empty($history_entries)) {
            $latest_entry = $history_entries[0];

            if (isset($latest_entry['model'])) {
                $cached_payload['model'] = $latest_entry['model'];
            }

            if (isset($latest_entry['rate_limit'])) {
                $cached_payload['rate_limit'] = $latest_entry['rate_limit'];
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
