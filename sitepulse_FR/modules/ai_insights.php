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

    $stored_insight    = get_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);
    $insight_text      = '';
    $insight_timestamp = null;

    if (is_array($stored_insight) && isset($stored_insight['text'])) {
        $insight_text = sanitize_textarea_field($stored_insight['text']);

        if (isset($stored_insight['timestamp'])) {
            $insight_timestamp = (int) $stored_insight['timestamp'];
        }
    } elseif (is_string($stored_insight) && '' !== $stored_insight) {
        $insight_text = sanitize_textarea_field($stored_insight);
    }

    wp_localize_script(
        'sitepulse-ai-insights',
        'sitepulseAIInsights',
        [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT),
            'initialInsight'    => $insight_text,
            'initialTimestamp'  => null !== $insight_timestamp ? $insight_timestamp : null,
            'strings'           => [
                'defaultError' => esc_html__('Une erreur inattendue est survenue. Veuillez réessayer.', 'sitepulse'),
                'cachedPrefix' => esc_html__('Dernière mise à jour :', 'sitepulse'),
            ],
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
                <span class="spinner" id="sitepulse-ai-spinner" style="float: none; margin-top: 0;"></span>
            </div>
        <?php endif; ?>
        <div id="sitepulse-ai-insight-error" class="notice notice-error" style="display: none;"><p></p></div>
        <div id="sitepulse-ai-insight-result" style="display: none; background: #fff; border: 1px solid #ccc; padding: 15px; margin-top: 20px;">
            <h2><?php esc_html_e('Votre Recommandation par IA', 'sitepulse'); ?></h2>
            <p class="sitepulse-ai-insight-text" style="white-space: pre-line;"></p>
            <p class="sitepulse-ai-insight-timestamp" style="display: none;"></p>
        </div>
    </div>
    <?php
}

function sitepulse_generate_ai_insight() {
    $stored_insight = get_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);
    $cached_payload = [];

    if (is_array($stored_insight) && isset($stored_insight['text'])) {
        $cached_text = sanitize_textarea_field($stored_insight['text']);

        if ('' !== $cached_text) {
            $cached_payload['text'] = $cached_text;

            if (isset($stored_insight['timestamp'])) {
                $cached_payload['timestamp'] = (int) $stored_insight['timestamp'];
            }
        }
    } elseif (is_string($stored_insight) && '' !== $stored_insight) {
        $cached_text = sanitize_textarea_field($stored_insight);

        if ('' !== $cached_text) {
            $cached_payload['text'] = $cached_text;
        }
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error([
            'message' => esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse'),
        ], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_AI_INSIGHT)) {
        wp_send_json_error([
            'message' => esc_html__('Échec de la vérification de sécurité. Veuillez recharger la page et réessayer.', 'sitepulse'),
        ], 400);
    }

    if (!empty($cached_payload)) {
        wp_send_json_success($cached_payload);
    }

    $api_key = trim((string) get_option(SITEPULSE_OPTION_GEMINI_API_KEY));

    if ('' === $api_key) {
        wp_send_json_error([
            'message' => esc_html__('Veuillez entrer votre clé API Google Gemini dans les réglages de SitePulse.', 'sitepulse'),
        ], 400);
    }

    $endpoint = add_query_arg(
        'key',
        $api_key,
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent'
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

    $response = wp_remote_post(
        $endpoint,
        [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($request_body),
            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                esc_html__('Erreur lors de la génération de l’analyse IA : %s', 'sitepulse'),
                sanitize_text_field($response->get_error_message())
            ),
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

        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                esc_html__('Erreur lors de la génération de l’analyse IA : %s', 'sitepulse'),
                sanitize_text_field($error_detail)
            ),
        ], 500);
    }

    $decoded_body = json_decode($body, true);

    if (!is_array($decoded_body) || !isset($decoded_body['candidates'][0]['content']['parts']) || !is_array($decoded_body['candidates'][0]['content']['parts'])) {
        wp_send_json_error([
            'message' => esc_html__('Structure de réponse inattendue reçue depuis Gemini.', 'sitepulse'),
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
        wp_send_json_error([
            'message' => esc_html__('La réponse de Gemini ne contient aucun texte exploitable.', 'sitepulse'),
        ], 500);
    }

    $generated_text = sanitize_textarea_field($generated_text);
    $timestamp      = current_time('timestamp');

    set_transient(
        SITEPULSE_TRANSIENT_AI_INSIGHT,
        [
            'text'      => $generated_text,
            'timestamp' => $timestamp,
        ],
        HOUR_IN_SECONDS
    );

    wp_send_json_success([
        'text'      => $generated_text,
        'timestamp' => $timestamp,
    ]);
}
