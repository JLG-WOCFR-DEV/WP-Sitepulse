<?php
/**
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the debug page.
 */
function sitepulse_debug_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $log_max_lines = 100;
    $log_max_bytes = 131072;

    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-bug"></span> <?php esc_html_e('Debug Dashboard', 'sitepulse'); ?></h1>
        <div class="notice notice-info"><p><strong><?php esc_html_e('À quoi sert cette page ?', 'sitepulse'); ?></strong> <?php esc_html_e('Le mode Debug active une journalisation détaillée des actions du plugin. Cette page affiche ce journal et d\'autres informations techniques pour vous aider, ou aider un développeur, à résoudre des problèmes. Ce menu n\'apparaît que si le "Mode Debug" est activé dans les réglages de SitePulse.', 'sitepulse'); ?></p></div>
        <div id="dashboard-widgets-wrap">
            <div id="dashboard-widgets" class="metabox-holder">
                <div class="postbox-container">
                    <div class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e('Détails de l\'Environnement', 'sitepulse'); ?></span></h2>
                        <div class="inside">
                            <?php
                            $active_modules_option = get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);
                            $active_modules = array_values(array_filter(array_map('strval', (array) $active_modules_option), static function ($module) {
                                return $module !== '';
                            }));
                            $active_modules_list = implode(', ', $active_modules);
                            ?>
                            <ul>
                                <li><strong><?php esc_html_e('Version de SitePulse:', 'sitepulse'); ?></strong> <?php echo esc_html(SITEPULSE_VERSION); ?></li>
                                <li><strong><?php esc_html_e('Version de WordPress:', 'sitepulse'); ?></strong> <?php echo esc_html(get_bloginfo('version')); ?></li>
                                <li><strong><?php esc_html_e('Version de PHP:', 'sitepulse'); ?></strong> <?php echo esc_html(PHP_VERSION); ?></li>
                                <li><strong><?php esc_html_e('Modules Actifs:', 'sitepulse'); ?></strong> <?php echo $active_modules_list ? esc_html($active_modules_list) : esc_html__('Aucun', 'sitepulse'); ?></li>
                                <li><strong><?php esc_html_e('WP Memory Limit:', 'sitepulse'); ?></strong> <?php echo esc_html(WP_MEMORY_LIMIT); ?></li>
                                <li><strong><?php esc_html_e('Pic d\'utilisation mémoire:', 'sitepulse'); ?></strong> <?php echo wp_kses_post(size_format(memory_get_peak_usage(true))); ?></li>
                            </ul>
                        </div>
                    </div>
                    <div class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e('Tâches Planifiées (Crons)', 'sitepulse'); ?></span></h2>
                        <div class="inside">
                           <ul>
                                <?php
                                $crons = get_option('cron');
                                $has_sitepulse_cron = false;

                                if (is_array($crons)) {
                                    foreach ($crons as $timestamp => $cron) {
                                        if (!is_numeric($timestamp) || !is_array($cron)) {
                                            continue;
                                        }

                                        foreach ($cron as $hook => $events) {
                                            if (strpos((string) $hook, 'sitepulse') === false) {
                                                continue;
                                            }

                                            $has_sitepulse_cron = true;
                                            $next_run = wp_date('Y-m-d H:i:s', (int) $timestamp);
                                            echo '<li><strong>' . esc_html($hook) . '</strong> - ' . esc_html__('Prochaine exécution:', 'sitepulse') . ' ' . esc_html($next_run) . '</li>';
                                        }
                                    }
                                }
                                if (!$has_sitepulse_cron) { echo '<li>' . esc_html__('Aucune tâche planifiée pour SitePulse trouvée.', 'sitepulse') . '</li>'; }
                                ?>
                           </ul>
                        </div>
                    </div>
                    <div class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e('Diaporama des images d’article', 'sitepulse'); ?></span></h2>
                        <div class="inside">
                            <?php
                            $slideshow_enabled   = sitepulse_is_article_slideshow_enabled();
                            $slideshow_selectors = sitepulse_get_article_slideshow_selectors();
                            ?>
                            <p><?php esc_html_e('Ce panneau résume le statut du diaporama frontend activé par SitePulse.', 'sitepulse'); ?></p>
                            <ul>
                                <li><strong><?php esc_html_e('Activation automatique', 'sitepulse'); ?> :</strong> <?php echo $slideshow_enabled ? esc_html__('Oui', 'sitepulse') : esc_html__('Non', 'sitepulse'); ?></li>
                                <li><strong><?php esc_html_e('Mode debug', 'sitepulse'); ?> :</strong> <?php echo (defined('SITEPULSE_DEBUG') && SITEPULSE_DEBUG) ? esc_html__('Actif', 'sitepulse') : esc_html__('Désactivé', 'sitepulse'); ?></li>
                                <li><strong><?php esc_html_e('Identifiant du script', 'sitepulse'); ?> :</strong> <code>sitepulse-article-slideshow</code></li>
                            </ul>
                            <?php if (!empty($slideshow_selectors)) : ?>
                                <p><strong><?php esc_html_e('Sélecteurs surveillés', 'sitepulse'); ?> :</strong></p>
                                <ul class="sitepulse-slideshow-selector-list">
                                    <?php foreach ($slideshow_selectors as $selector) : ?>
                                        <li><code><?php echo esc_html($selector); ?></code></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if (defined('SITEPULSE_DEBUG') && SITEPULSE_DEBUG) : ?>
                                <p class="description"><?php esc_html_e('Ouvrez un article côté frontend : la visionneuse affiche un panneau d’inspection (index, texte alternatif, légendes).', 'sitepulse'); ?></p>
                            <?php else : ?>
                                <p class="description"><?php esc_html_e('Activez le mode debug dans les réglages SitePulse pour afficher le panneau d’inspection directement dans le diaporama.', 'sitepulse'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <h2><?php esc_html_e('Logs de Débogage Récents', 'sitepulse'); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: 1: number of log lines kept, 2: formatted size limit. */
                esc_html__('Seules les %1$d dernières lignes du journal (limitées à %2$s) sont chargées pour éviter toute surcharge mémoire.', 'sitepulse'),
                (int) $log_max_lines,
                wp_kses_post(size_format($log_max_bytes))
            );
            ?>
        </p>
        <div style="background: #fff; border: 1px solid #ccc; padding: 10px; max-height: 400px; overflow-y: scroll; font-family: monospace; font-size: 13px;">
            <?php
            if (defined('SITEPULSE_DEBUG_LOG') && is_readable(SITEPULSE_DEBUG_LOG)) {
                $recent_logs_data = sitepulse_get_recent_log_lines(SITEPULSE_DEBUG_LOG, $log_max_lines, $log_max_bytes, true);

                if (is_array($recent_logs_data) && array_key_exists('lines', $recent_logs_data)) {
                    if (!empty($recent_logs_data['lines'])) {
                        echo '<pre>' . esc_html(implode("\n", $recent_logs_data['lines'])) . '</pre>';

                        if (!empty($recent_logs_data['truncated'])) {
                            echo '<p class="description">' . esc_html__('Affichage tronqué pour limiter la consommation mémoire.', 'sitepulse') . '</p>';
                        }
                    } else {
                        echo '<p>' . esc_html__('Le journal de débogage est actuellement vide.', 'sitepulse') . '</p>';
                    }
                } else {
                    echo '<p>' . esc_html__('Fichier de log non trouvé ou illisible.', 'sitepulse') . '</p>';
                }
            } else {
                echo '<p>' . esc_html__('Fichier de log non trouvé ou illisible.', 'sitepulse') . '</p>';
            }
            ?>
        </div>
    </div>
    <?php
}
