<?php
/**
 * SitePulse AI Insights admin AJAX handlers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
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
