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
            <div class="notice notice-info" style="padding-bottom: 0;">
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
            <div class="sitepulse-ai-insight-actions">
                <button type="button" id="sitepulse-ai-generate" class="button button-primary"><?php esc_html_e('Générer une Analyse', 'sitepulse'); ?></button>
                <label for="sitepulse-ai-force-refresh" class="sitepulse-ai-force-refresh">
                    <input type="checkbox" id="sitepulse-ai-force-refresh" />
                    <?php esc_html_e('Forcer une nouvelle analyse', 'sitepulse'); ?>
                </label>
                <span class="spinner" id="sitepulse-ai-spinner" style="float: none; margin-top: 0;"></span>
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
    $log_ai_error = static function ($message, $status_code = null) {
        if (!function_exists('sitepulse_log')) {
            return;
        }

        $message = (string) $message;
        $log_message = null !== $status_code
            ? sprintf('AI Insights (%d): %s', (int) $status_code, $message)
            : sprintf('AI Insights: %s', $message);

        sitepulse_log($log_message, 'ERROR');
    };

    if (!current_user_can('manage_options')) {
        $error_message = esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse');

        $log_ai_error($error_message, 403);

        wp_send_json_error([
            'message' => $error_message,
        ], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_AI_INSIGHT)) {
        $error_message = esc_html__('Échec de la vérification de sécurité. Veuillez recharger la page et réessayer.', 'sitepulse');

        $log_ai_error($error_message, 400);

        wp_send_json_error([
            'message' => $error_message,
        ], 400);
    }

    $force_refresh = false;

    if (isset($_POST['force_refresh'])) {
        $force_refresh = filter_var(wp_unslash($_POST['force_refresh']), FILTER_VALIDATE_BOOLEAN);
    }

    $cached_payload = $force_refresh
        ? sitepulse_ai_get_cached_insight(true)
        : sitepulse_ai_get_cached_insight();

    if (!$force_refresh && !empty($cached_payload)) {
        $cached_payload['cached'] = true;
        wp_send_json_success($cached_payload);
    }

    $api_key = trim((string) get_option(SITEPULSE_OPTION_GEMINI_API_KEY));

    if ('' === $api_key) {
        $error_message = esc_html__('Veuillez entrer votre clé API Google Gemini dans les réglages de SitePulse.', 'sitepulse');

        $log_ai_error($error_message, 400);

        wp_send_json_error([
            'message' => $error_message,
        ], 400);
    }

    $available_models = sitepulse_get_ai_models();
    $default_model = sitepulse_get_default_ai_model();
    $selected_model = (string) get_option(SITEPULSE_OPTION_AI_MODEL, $default_model);

    if (!isset($available_models[$selected_model])) {
        $selected_model = $default_model;
    }

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

        $log_ai_error($error_message, 500);

        wp_send_json_error([
            'message' => $error_message,
        ], 500);
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

            $log_ai_error($error_message, 500);

            wp_send_json_error([
                'message' => $error_message,
            ], 500);
        }

        $sanitized_error_message = sanitize_text_field($response->get_error_message());
        $error_message           = sprintf(
            /* translators: %s: error message */
            esc_html__('Erreur lors de la génération de l’analyse IA : %s', 'sitepulse'),
            $sanitized_error_message
        );

        $log_ai_error($error_message, 500);

        wp_send_json_error([
            'message' => $error_message,
        ], 500);
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

        $log_ai_error($error_message, $status_code);

        wp_send_json_error([
            'message' => $error_message,
        ], 500);
    }

    $decoded_body = json_decode($body, true);

    if (!is_array($decoded_body) || !isset($decoded_body['candidates'][0]['content']['parts']) || !is_array($decoded_body['candidates'][0]['content']['parts'])) {
        $error_message = esc_html__('Structure de réponse inattendue reçue depuis Gemini.', 'sitepulse');

        $log_ai_error($error_message, 500);

        wp_send_json_error([
            'message' => $error_message,
        ], 500);
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

        $log_ai_error($error_message, 500);

        wp_send_json_error([
            'message' => $error_message,
        ], 500);
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

    wp_send_json_success([
        'text'      => isset($fresh_payload['text']) ? $fresh_payload['text'] : $generated_text,
        'timestamp' => isset($fresh_payload['timestamp']) ? $fresh_payload['timestamp'] : $timestamp,
        'cached'    => false,
    ]);
}
