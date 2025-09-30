<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('AI Insights', 'sitepulse'),
        __('AI Insights', 'sitepulse'),
        'manage_options',
        'sitepulse-ai',
        'sitepulse_ai_insights_page'
    );
});
add_action('admin_enqueue_scripts', 'sitepulse_ai_insights_enqueue_assets');
add_action('wp_ajax_sitepulse_generate_ai_insight', 'sitepulse_generate_ai_insight');
add_action('init', 'sitepulse_ai_insights_schedule');
add_action('sitepulse_ai_insights_cron', 'sitepulse_ai_insights_run_cron');
add_action('update_option_' . SITEPULSE_OPTION_GEMINI_API_KEY, 'sitepulse_ai_insights_schedule', 10, 0);

function sitepulse_ai_insights_schedule() {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }

    $cron_hook = 'sitepulse_ai_insights_cron';
    $api_key   = trim((string) get_option(SITEPULSE_OPTION_GEMINI_API_KEY));

    if ($api_key === '') {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($cron_hook);
        }

        return;
    }

    if (!wp_next_scheduled($cron_hook)) {
        wp_schedule_event(time(), 'daily', $cron_hook);
    }
}

function sitepulse_ai_insights_run_cron() {
    $result = sitepulse_ai_generate_insight_payload([
        'source' => 'cron',
    ]);

    if (is_wp_error($result)) {
        $message = $result->get_error_message();
        $error_data = $result->get_error_data();
        $status_code = null;

        if (is_array($error_data) && isset($error_data['status_code'])) {
            $status_code = (int) $error_data['status_code'];
        }

        if ($message !== '') {
            sitepulse_ai_log_error($message, $status_code);
        }

        return;
    }
}

function sitepulse_ai_log_error($message, $status_code = null) {
    if (!function_exists('sitepulse_log')) {
        return;
    }

    $message = (string) $message;
    $log_message = $status_code !== null
        ? sprintf('AI Insights (%d): %s', (int) $status_code, $message)
        : sprintf('AI Insights: %s', $message);

    sitepulse_log($log_message, 'ERROR');
}

function sitepulse_ai_get_history_limit() {
    $limit = defined('SITEPULSE_AI_INSIGHT_HISTORY_LIMIT') ? (int) SITEPULSE_AI_INSIGHT_HISTORY_LIMIT : 7;
    $limit = (int) apply_filters('sitepulse_ai_insight_history_limit', $limit);

    if ($limit <= 0) {
        $limit = 1;
    }

    return $limit;
}

function sitepulse_ai_normalize_insight_entry($text, $timestamp = 0, $source = 'manual') {
    $text = sanitize_textarea_field((string) $text);

    if ($text === '') {
        return null;
    }

    $timestamp = absint($timestamp);

    if ($timestamp <= 0) {
        $timestamp = absint(current_time('timestamp', true));
    }

    $allowed_sources = ['manual', 'cron'];

    if (!in_array($source, $allowed_sources, true)) {
        $source = 'manual';
    }

    return [
        'text'      => $text,
        'timestamp' => $timestamp,
        'source'    => $source,
    ];
}

function sitepulse_ai_get_insight_storage($force_refresh = false) {
    static $storage = null;

    if ($force_refresh) {
        $storage = null;

        return [
            'entries'           => [],
            'last_auto_refresh' => null,
        ];
    }

    if ($storage !== null) {
        return $storage;
    }

    $storage = [
        'entries'           => [],
        'last_auto_refresh' => null,
    ];

    $option_value = get_option(SITEPULSE_OPTION_AI_INSIGHT_HISTORY, []);

    if (is_array($option_value)) {
        if (isset($option_value['entries']) && is_array($option_value['entries'])) {
            foreach ($option_value['entries'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $normalized = sitepulse_ai_normalize_insight_entry(
                    $entry['text'] ?? '',
                    isset($entry['timestamp']) ? $entry['timestamp'] : 0,
                    isset($entry['source']) ? $entry['source'] : 'manual'
                );

                if ($normalized !== null) {
                    $storage['entries'][] = $normalized;
                }

                if (count($storage['entries']) >= sitepulse_ai_get_history_limit()) {
                    break;
                }
            }
        }

        if (isset($option_value['last_auto_refresh'])) {
            $last_auto_refresh = absint($option_value['last_auto_refresh']);

            if ($last_auto_refresh > 0) {
                $storage['last_auto_refresh'] = $last_auto_refresh;
            }
        }
    }

    if (empty($storage['entries'])) {
        $transient_value = get_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);
        $migrated_entry  = null;

        if (is_array($transient_value) && isset($transient_value['text'])) {
            $migrated_entry = sitepulse_ai_normalize_insight_entry(
                $transient_value['text'],
                isset($transient_value['timestamp']) ? $transient_value['timestamp'] : 0,
                'manual'
            );
        } elseif (is_string($transient_value) && $transient_value !== '') {
            $migrated_entry = sitepulse_ai_normalize_insight_entry($transient_value, 0, 'manual');
        }

        if ($migrated_entry !== null) {
            $storage['entries'][] = $migrated_entry;
            update_option(SITEPULSE_OPTION_AI_INSIGHT_HISTORY, $storage);
            delete_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);
        }
    }

    return $storage;
}

function sitepulse_ai_get_history_entries() {
    $storage = sitepulse_ai_get_insight_storage();

    return $storage['entries'];
}

function sitepulse_ai_get_last_auto_refresh_timestamp() {
    $storage = sitepulse_ai_get_insight_storage();

    return $storage['last_auto_refresh'];
}

function sitepulse_ai_store_insight_entry($text, $timestamp, $source = 'manual') {
    $entry = sitepulse_ai_normalize_insight_entry($text, $timestamp, $source);

    if ($entry === null) {
        return null;
    }

    $storage = sitepulse_ai_get_insight_storage();

    array_unshift($storage['entries'], $entry);
    $storage['entries'] = array_slice($storage['entries'], 0, sitepulse_ai_get_history_limit());

    if ('cron' === $entry['source']) {
        $storage['last_auto_refresh'] = $entry['timestamp'];
    } elseif (!isset($storage['last_auto_refresh'])) {
        $storage['last_auto_refresh'] = null;
    }

    update_option(SITEPULSE_OPTION_AI_INSIGHT_HISTORY, $storage);
    sitepulse_ai_get_insight_storage(true);
    sitepulse_ai_get_cached_insight(true);

    return $entry;
}

function sitepulse_ai_prepare_history_payload() {
    $history = [];

    foreach (sitepulse_ai_get_history_entries() as $entry) {
        $history[] = [
            'text'      => $entry['text'],
            'timestamp' => $entry['timestamp'],
            'source'    => $entry['source'],
        ];
    }

    return $history;
}

function sitepulse_ai_prepare_response_payload($entry, $is_cached) {
    $payload = [
        'text'             => '',
        'timestamp'        => null,
        'source'           => 'manual',
        'cached'           => (bool) $is_cached,
        'history'          => sitepulse_ai_prepare_history_payload(),
        'lastAutoRefresh'  => sitepulse_ai_get_last_auto_refresh_timestamp(),
    ];

    if (is_array($entry)) {
        if (isset($entry['text'])) {
            $payload['text'] = $entry['text'];
        }

        if (isset($entry['timestamp'])) {
            $payload['timestamp'] = $entry['timestamp'];
        }

        if (isset($entry['source'])) {
            $payload['source'] = $entry['source'];
        }
    }

    return $payload;
}

function sitepulse_ai_generate_insight_payload($args = []) {
    $defaults = [
        'source' => 'manual',
    ];

    $args = wp_parse_args($args, $defaults);

    $api_key = trim((string) get_option(SITEPULSE_OPTION_GEMINI_API_KEY));

    if ($api_key === '') {
        return new WP_Error(
            'sitepulse_ai_missing_api_key',
            esc_html__('Veuillez entrer votre clé API Google Gemini dans les réglages de SitePulse.', 'sitepulse')
        );
    }

    $lock_acquired = false;
    $lock_ttl      = MINUTE_IN_SECONDS * 5;

    if ($lock_ttl <= 0) {
        $lock_ttl = MINUTE_IN_SECONDS * 5;
    }

    if (false !== get_transient(SITEPULSE_TRANSIENT_AI_INSIGHT_LOCK)) {
        return new WP_Error(
            'sitepulse_ai_generation_locked',
            esc_html__('Une analyse est déjà en cours. Veuillez patienter quelques instants.', 'sitepulse')
        );
    }

    $lock_acquired = set_transient(SITEPULSE_TRANSIENT_AI_INSIGHT_LOCK, 1, $lock_ttl);

    if (!$lock_acquired) {
        return new WP_Error(
            'sitepulse_ai_lock_failure',
            esc_html__('Impossible de démarrer la génération pour le moment. Réessayez dans quelques instants.', 'sitepulse')
        );
    }

    try {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

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

        if (!empty($site_description)) {
            $prompt_sections[] = sprintf(
                /* translators: %s: site description */
                __('Description du site : %s.', 'sitepulse'),
                $site_description
            );
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

            if ($error_detail === '') {
                $error_detail = esc_html__('erreur JSON inconnue', 'sitepulse');
            }

            $sanitized_detail = sanitize_text_field($error_detail);

            return new WP_Error(
                'sitepulse_ai_json_error',
                sprintf(
                    /* translators: %s: error detail */
                    esc_html__('Impossible de préparer la requête pour Gemini : %s', 'sitepulse'),
                    $sanitized_detail
                )
            );
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

        $response = wp_remote_post($endpoint, $request_args);

        if (is_wp_error($response)) {
            if (
                $response_size_limit > 0
                && 'http_request_failed' === $response->get_error_code()
                && false !== stripos($response->get_error_message(), 'limit')
            ) {
                $formatted_limit = size_format($response_size_limit, 2);
                $sanitized_limit = sanitize_text_field($formatted_limit);

                return new WP_Error(
                    'sitepulse_ai_response_too_large',
                    sprintf(
                        /* translators: %s: formatted size limit */
                        esc_html__('La réponse de Gemini dépasse la taille maximale autorisée (%s). Veuillez réessayer ou augmenter la limite via le filtre sitepulse_ai_response_size_limit.', 'sitepulse'),
                        $sanitized_limit
                    )
                );
            }

            $sanitized_error_message = sanitize_text_field($response->get_error_message());

            return new WP_Error(
                'sitepulse_ai_http_error',
                sprintf(
                    /* translators: %s: error message */
                    esc_html__('Erreur lors de la génération de l’analyse IA : %s', 'sitepulse'),
                    $sanitized_error_message
                )
            );
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

            if ($error_detail === '') {
                $error_detail = sprintf(esc_html__('HTTP %d', 'sitepulse'), $status_code);
            }

            $sanitized_error_detail = sanitize_text_field($error_detail);

            return new WP_Error(
                'sitepulse_ai_http_error_status',
                sprintf(
                    /* translators: %s: error message */
                    esc_html__('Erreur lors de la génération de l’analyse IA : %s', 'sitepulse'),
                    $sanitized_error_detail
                ),
                [
                    'status_code' => $status_code,
                ]
            );
        }

        $decoded_body = json_decode($body, true);

        if (!is_array($decoded_body) || !isset($decoded_body['candidates'][0]['content']['parts']) || !is_array($decoded_body['candidates'][0]['content']['parts'])) {
            return new WP_Error(
                'sitepulse_ai_unexpected_response',
                esc_html__('Structure de réponse inattendue reçue depuis Gemini.', 'sitepulse')
            );
        }

        $generated_text = '';

        foreach ($decoded_body['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text'])) {
                $generated_text .= ' ' . $part['text'];
            }
        }

        $generated_text = trim($generated_text);

        if ($generated_text === '') {
            return new WP_Error(
                'sitepulse_ai_empty_response',
                esc_html__('La réponse de Gemini ne contient aucun texte exploitable.', 'sitepulse')
            );
        }

        $generated_text = sanitize_textarea_field($generated_text);
        $timestamp      = absint(current_time('timestamp', true));

        $stored_entry = sitepulse_ai_store_insight_entry($generated_text, $timestamp, $args['source']);

        if ($stored_entry === null) {
            return new WP_Error(
                'sitepulse_ai_storage_failure',
                esc_html__('Impossible d’enregistrer la recommandation générée.', 'sitepulse')
            );
        }

        return $stored_entry;
    } finally {
        delete_transient(SITEPULSE_TRANSIENT_AI_INSIGHT_LOCK);
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

        sitepulse_ai_get_insight_storage(true);

        return [];
    }

    if ($cached_insight !== null) {
        return $cached_insight;
    }

    $cached_insight = [];
    $history        = sitepulse_ai_get_history_entries();

    if (!empty($history)) {
        $latest_entry = $history[0];

        $cached_insight = [
            'text'      => $latest_entry['text'],
            'timestamp' => $latest_entry['timestamp'],
            'source'    => $latest_entry['source'],
        ];
    }

    return $cached_insight;
}

function sitepulse_ai_insights_enqueue_assets($hook_suffix) {
    if ('sitepulse-dashboard_page_sitepulse-ai' !== $hook_suffix) {
        return;
    }

    wp_register_script(
        'sitepulse-ai-insights',
        SITEPULSE_URL . 'modules/js/sitepulse-ai-insights.js',
        ['jquery'],
        SITEPULSE_VERSION,
        true
    );

    $stored_insight     = sitepulse_ai_get_cached_insight();
    $insight_text       = isset($stored_insight['text']) ? $stored_insight['text'] : '';
    $insight_timestamp  = isset($stored_insight['timestamp']) ? absint($stored_insight['timestamp']) : null;
    $insight_source     = isset($stored_insight['source']) ? $stored_insight['source'] : 'manual';
    $history_entries    = sitepulse_ai_prepare_history_payload();
    $last_auto_refresh  = sitepulse_ai_get_last_auto_refresh_timestamp();
    $history_limit      = sitepulse_ai_get_history_limit();

    wp_localize_script(
        'sitepulse-ai-insights',
        'sitepulseAIInsights',
        [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT),
            'initialInsight'    => $insight_text,
            'initialTimestamp'  => null !== $insight_timestamp ? absint($insight_timestamp) : null,
            'initialSource'     => $insight_source,
            'initialHistory'    => $history_entries,
            'lastAutoRefresh'   => null !== $last_auto_refresh ? absint($last_auto_refresh) : null,
            'historyLimit'      => $history_limit,
            'strings'           => [
                'defaultError'    => esc_html__('Une erreur inattendue est survenue. Veuillez réessayer.', 'sitepulse'),
                'cachedPrefix'    => esc_html__('Dernière mise à jour :', 'sitepulse'),
                'statusCached'    => esc_html__('Résultat issu du cache.', 'sitepulse'),
                'statusFresh'     => esc_html__('Nouvelle analyse générée.', 'sitepulse'),
                'statusGenerating' => esc_html__('Génération en cours…', 'sitepulse'),
                'historyLabel'    => esc_html__('Historique des analyses', 'sitepulse'),
                'historyEmpty'    => esc_html__('Aucune analyse enregistrée pour le moment.', 'sitepulse'),
                'historyManual'   => esc_html__('Génération manuelle', 'sitepulse'),
                'historyAuto'     => esc_html__('Génération automatique', 'sitepulse'),
                'lastAutoRefresh' => esc_html__('Dernier rafraîchissement automatique :', 'sitepulse'),
            ],
            'initialCached'     => '' !== $insight_text,
        ]
    );

    wp_enqueue_script('sitepulse-ai-insights');
}

function sitepulse_ai_insights_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $api_key = get_option(SITEPULSE_OPTION_GEMINI_API_KEY);
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-superhero"></span> <?php esc_html_e('Analyses par IA', 'sitepulse'); ?></h1>
        <p><?php esc_html_e("Obtenez des recommandations personnalisées pour votre site en analysant ses données de performance avec l'IA Gemini de Google.", 'sitepulse'); ?></p>
        <?php if (empty($api_key)) : ?>
            <div class="notice notice-warning"><p><?php echo wp_kses_post(sprintf(__('Veuillez <a href="%s">entrer votre clé API Google Gemini</a> pour utiliser cette fonctionnalité.', 'sitepulse'), esc_url(admin_url('admin.php?page=sitepulse-settings')))); ?></p></div>
        <?php else : ?>
            <div class="sitepulse-ai-insight-actions">
                <button type="button" id="sitepulse-ai-generate" class="button button-primary"><?php esc_html_e('Générer une Analyse', 'sitepulse'); ?></button>
                <label for="sitepulse-ai-force-refresh" class="sitepulse-ai-force-refresh">
                    <input type="checkbox" id="sitepulse-ai-force-refresh" />
                    <?php esc_html_e('Forcer une nouvelle analyse', 'sitepulse'); ?>
                </label>
                <span class="spinner" id="sitepulse-ai-spinner" style="float: none; margin-top: 0;"></span>
            </div>
            <div class="sitepulse-ai-insight-meta" style="margin-top: 15px;">
                <label for="sitepulse-ai-history" class="sitepulse-ai-history-label" style="display: block; margin-bottom: 5px;">
                    <?php esc_html_e('Historique des analyses', 'sitepulse'); ?>
                </label>
                <select id="sitepulse-ai-history" class="sitepulse-ai-history-select" style="min-width: 260px;">
                </select>
                <p id="sitepulse-ai-last-auto" class="description" style="display: none; margin-top: 10px;"></p>
            </div>
        <?php endif; ?>
        <div id="sitepulse-ai-insight-error" class="notice notice-error" style="display: none;"><p></p></div>
        <div id="sitepulse-ai-insight-result" style="display: none; background: #fff; border: 1px solid #ccc; padding: 15px; margin-top: 20px;">
            <h2><?php esc_html_e('Votre Recommandation par IA', 'sitepulse'); ?></h2>
            <p class="sitepulse-ai-insight-status" style="display: none;"></p>
            <p class="sitepulse-ai-insight-text" style="white-space: pre-line;"></p>
            <p class="sitepulse-ai-insight-timestamp" style="display: none;"></p>
        </div>
    </div>
    <?php
}

function sitepulse_generate_ai_insight() {
    if (!current_user_can('manage_options')) {
        $error_message = esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse');

        sitepulse_ai_log_error($error_message, 403);

        wp_send_json_error([
            'message' => $error_message,
        ], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_AI_INSIGHT)) {
        $error_message = esc_html__('Échec de la vérification de sécurité. Veuillez recharger la page et réessayer.', 'sitepulse');

        sitepulse_ai_log_error($error_message, 400);

        wp_send_json_error([
            'message' => $error_message,
        ], 400);
    }

    $force_refresh = false;

    if (isset($_POST['force_refresh'])) {
        $force_refresh = filter_var(wp_unslash($_POST['force_refresh']), FILTER_VALIDATE_BOOLEAN);
    }

    if ($force_refresh) {
        sitepulse_ai_get_cached_insight(true);
    }

    if (!$force_refresh) {
        $cached_entry = sitepulse_ai_get_cached_insight();

        if (!empty($cached_entry)) {
            $payload = sitepulse_ai_prepare_response_payload($cached_entry, true);

            wp_send_json_success($payload);
        }
    }

    $result = sitepulse_ai_generate_insight_payload([
        'source' => 'manual',
    ]);

    if (is_wp_error($result)) {
        $status_code = 500;
        $error_code  = $result->get_error_code();
        $error_data  = $result->get_error_data();

        if (is_array($error_data) && isset($error_data['status_code'])) {
            $status_code = (int) $error_data['status_code'];
        } elseif ('sitepulse_ai_missing_api_key' === $error_code) {
            $status_code = 400;
        } elseif ('sitepulse_ai_generation_locked' === $error_code) {
            $status_code = 409;
        } elseif ('sitepulse_ai_lock_failure' === $error_code) {
            $status_code = 503;
        }

        if ($status_code < 100) {
            $status_code = 500;
        }

        $message = $result->get_error_message();

        sitepulse_ai_log_error($message, $status_code);

        wp_send_json_error([
            'message' => $message,
        ], $status_code);
    }

    $payload = sitepulse_ai_prepare_response_payload($result, false);

    wp_send_json_success($payload);
}
