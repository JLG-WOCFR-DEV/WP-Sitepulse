<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'AI Insights', 'AI Insights', 'manage_options', 'sitepulse-ai', 'sitepulse_ai_insights_page'); });
function sitepulse_ai_insights_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $api_key = get_option(SITEPULSE_OPTION_GEMINI_API_KEY);
    $stored_insight = get_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);
    $insight_result = '';
    if (is_string($stored_insight) && '' !== $stored_insight) {
        $insight_result = sanitize_textarea_field($stored_insight);
    }
    $error_notice = '';
    if (isset($_POST['get_ai_insight']) && check_admin_referer('sitepulse_get_ai_insight')) {
        if (empty($api_key)) {
            echo '<div class="notice notice-error"><p>Veuillez entrer votre clé API Google Gemini dans les réglages de SitePulse.</p></div>';
        } else {
            $endpoint = add_query_arg(
                'key',
                trim($api_key),
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent'
            );
            $site_name = wp_strip_all_tags(get_bloginfo('name'));
            $site_url = esc_url_raw(home_url());
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
                $error_notice = sprintf(
                    /* translators: %s: error message */
                    __('Erreur lors de la génération de l’analyse IA : %s', 'sitepulse'),
                    sanitize_text_field($response->get_error_message())
                );
            } else {
                $status_code = (int) wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                if ($status_code >= 200 && $status_code < 300) {
                    $decoded_body = json_decode($body, true);
                    if (
                        is_array($decoded_body)
                        && isset($decoded_body['candidates'][0]['content']['parts'])
                        && is_array($decoded_body['candidates'][0]['content']['parts'])
                    ) {
                        $generated_text = '';
                        foreach ($decoded_body['candidates'][0]['content']['parts'] as $part) {
                            if (isset($part['text'])) {
                                $generated_text .= ' ' . $part['text'];
                            }
                        }
                        $generated_text = trim($generated_text);
                        if ('' !== $generated_text) {
                            $generated_text = sanitize_textarea_field($generated_text);
                            set_transient(SITEPULSE_TRANSIENT_AI_INSIGHT, $generated_text, HOUR_IN_SECONDS);
                            $insight_result = $generated_text;
                        } else {
                            $error_notice = __('La réponse de Gemini ne contient aucun texte exploitable.', 'sitepulse');
                        }
                    } else {
                        $error_notice = __('Structure de réponse inattendue reçue depuis Gemini.', 'sitepulse');
                    }
                } else {
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
                        $error_detail = sprintf(__('HTTP %d', 'sitepulse'), $status_code);
                    }
                    $error_notice = sprintf(
                        /* translators: %s: error message */
                        __('Erreur lors de la génération de l’analyse IA : %s', 'sitepulse'),
                        sanitize_text_field($error_detail)
                    );
                }
            }
        }
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-superhero"></span> Analyses par IA</h1>
        <p>Obtenez des recommandations personnalisées pour votre site en analysant ses données de performance avec l'IA Gemini de Google.</p>
        <?php if (empty($api_key)): ?>
            <div class="notice notice-warning"><p>Veuillez <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-settings')); ?>">entrer votre clé API Google Gemini</a> pour utiliser cette fonctionnalité.</p></div>
        <?php else: ?>
            <form method="post" action="">
                <?php wp_nonce_field('sitepulse_get_ai_insight'); ?>
                <button type="submit" name="get_ai_insight" class="button button-primary">Générer une Analyse</button>
            </form>
        <?php endif; ?>
        <?php if (!empty($error_notice)): ?>
            <div class="notice notice-error"><p><?php echo esc_html($error_notice); ?></p></div>
        <?php endif; ?>
        <?php if ($insight_result): ?>
            <div id="ai-insight-response" style="background: #fff; border: 1px solid #ccc; padding: 15px; margin-top: 20px;"><h2>Votre Recommandation par IA</h2><p><?php echo nl2br(esc_html($insight_result)); ?></p></div>
        <?php endif; ?>
    </div>
    <?php
}
