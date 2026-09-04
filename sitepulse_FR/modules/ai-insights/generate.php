<?php
/**
 * SitePulse AI Insights generation runner.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
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

            sitepulse_ai_queue_clear_scheduled_action($job_id, $job_data['queue']);

            $job_data['fallback'] = 'ajax';
            $job_data['queue']['engine']   = 'ajax';
            $job_data['queue']['message']  = esc_html__('WP-Cron n’a pas pu être déclenché. L’analyse IA est exécutée immédiatement via AJAX.', 'sitepulse');

            sitepulse_ai_save_job_data($job_id, $job_data);
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
