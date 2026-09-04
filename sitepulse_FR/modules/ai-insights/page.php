<?php
/**
 * SitePulse AI Insights admin page.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
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
                        esc_url(admin_url('admin.php?page=sitepulse-settings&sitepulse-settings-active-tab=sitepulse-tab-ai#sitepulse_ai_model'))
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
