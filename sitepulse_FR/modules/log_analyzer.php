<?php
if (!defined('ABSPATH')) exit;

// Add the submenu page for the Log Analyzer
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        'Log Analyzer',
        'Logs',
        sitepulse_get_capability(),
        'sitepulse-logs',
        'sitepulse_log_analyzer_page'
    );
});

/**
 * Renders the Log Analyzer page with improved logic and explanations.
 */
function sitepulse_log_analyzer_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $log_file = function_exists('sitepulse_get_wp_debug_log_path') ? sitepulse_get_wp_debug_log_path() : null;
    $debug_log_enabled = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
    $log_file_exists = $log_file !== null && file_exists($log_file);
    $log_file_readable = $log_file_exists && is_readable($log_file);
    $log_file_size = $log_file_readable ? filesize($log_file) : 0;
    $recent_log_lines = null;
    $log_file_display = $log_file !== null ? '<code>' . esc_html($log_file) . '</code>' : '<code>debug.log</code>';
    if (function_exists('wp_kses_post')) {
        $log_file_display = wp_kses_post($log_file_display);
    }

    if ($debug_log_enabled && $log_file_readable) {
        $recent_log_lines = sitepulse_get_recent_log_lines($log_file, 100, 131072);
    }
    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-logs');
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-hammer"></span> Analyseur de Logs</h1>
        <p><?php printf(esc_html__('Cet outil scanne le fichier %s de WordPress pour vous aider à trouver et corriger les problèmes sur votre site.', 'sitepulse'), $log_file_display); ?></p>

        <?php
        // **FIX:** Rewrote the conditional logic using standard brace syntax to prevent parse errors.

        // Case 1: Debug log is enabled in wp-config.php
        if ($debug_log_enabled) {

            if ($log_file === null) {
            ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Impossible de déterminer le fichier de journal.', 'sitepulse'); ?></strong> <?php esc_html_e('Vérifiez la valeur de la constante WP_DEBUG_LOG.', 'sitepulse'); ?></p>
                </div>
            <?php
            }
            // Subcase 1.1: The log file exists and has content
            elseif ($log_file_readable && $log_file_size > 0 && is_array($recent_log_lines) && !empty($recent_log_lines)) {
                $categorized = ['fatal_errors' => [], 'errors' => [], 'warnings' => [], 'notices' => []];

                foreach ($recent_log_lines as $line) {
                    if (empty(trim($line))) continue;
                    if ((function_exists('sitepulse_log_line_contains_fatal_error') && sitepulse_log_line_contains_fatal_error($line)) || stripos($line, 'PHP Fatal error') !== false) { $categorized['fatal_errors'][] = $line; }
                    elseif (stripos($line, 'PHP Parse error') !== false || stripos($line, 'PHP Error') !== false) { $categorized['errors'][] = $line; }
                    elseif (stripos($line, 'PHP Warning') !== false) { $categorized['warnings'][] = $line; }
                    elseif (stripos($line, 'PHP Notice') !== false || stripos($line, 'PHP Deprecated') !== false) { $categorized['notices'][] = $line; }
                }

                $log_sections = [
                    'fatal_errors' => [
                        'class'       => 'notice notice-error',
                        'icon'        => 'dashicons-dismiss',
                        'title'       => esc_html__('Erreurs Fatales', 'sitepulse'),
                        'description' => esc_html__("Une erreur critique qui casse votre site. Elle empêche votre site de se charger et doit être corrigée immédiatement.", 'sitepulse'),
                    ],
                    'errors' => [
                        'class'       => 'notice notice-error',
                        'icon'        => 'dashicons-dismiss',
                        'title'       => esc_html__('Erreurs', 'sitepulse'),
                        'description' => esc_html__("Une erreur significative qui peut empêcher une fonctionnalité de marcher. Doit être traitée en priorité.", 'sitepulse'),
                    ],
                    'warnings' => [
                        'class'       => 'notice notice-warning',
                        'icon'        => 'dashicons-warning',
                        'title'       => esc_html__('Avertissements', 'sitepulse'),
                        'description' => esc_html__("Un problème non-critique. Votre site fonctionnera, mais cela indique un problème potentiel qui devrait être corrigé.", 'sitepulse'),
                    ],
                    'notices' => [
                        'class'       => 'notice notice-info',
                        'icon'        => 'dashicons-info',
                        'title'       => esc_html__('Notices', 'sitepulse'),
                        'description' => esc_html__("Un message d'information pour les développeurs. C'est la plus basse priorité et généralement pas un sujet d'inquiétude.", 'sitepulse'),
                    ],
                ];

                foreach ($log_sections as $key => $section) {
                    if (empty($categorized[$key])) {
                        continue;
                    }

                    $count        = esc_html((string) count($categorized[$key]));
                    $recent_lines = esc_html(implode("\n", array_slice($categorized[$key], -10)));

                    printf(
                        '<div class="%1$s"><h2><span class="dashicons %2$s"></span> %3$s (%4$s)</h2><p><strong>%5$s</strong> %6$s</p><pre>%7$s</pre></div>',
                        esc_attr($section['class']),
                        esc_attr($section['icon']),
                        $section['title'],
                        $count,
                        esc_html__("Ce que c'est :", 'sitepulse'),
                        $section['description'],
                        $recent_lines
                    );
                }

            }
            // Subcase 1.2: The log file exists but is empty
            elseif ($log_file_readable && $log_file_size === 0) {
            ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e('Votre journal de débogage est actif et vide.', 'sitepulse'); ?></strong> <?php esc_html_e('Excellent travail, aucune erreur à signaler !', 'sitepulse'); ?></p>
                </div>
            <?php
            }
            elseif ($log_file_readable && $recent_log_lines === null) {
            ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Impossible de lire les dernières lignes du journal.', 'sitepulse'); ?></strong> <?php printf(esc_html__('Veuillez vérifier les permissions du fichier %s.', 'sitepulse'), $log_file_display); ?></p>
                </div>
            <?php
            }
            elseif ($log_file_exists && !$log_file_readable) {
            ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Le fichier de journal n’est pas lisible.', 'sitepulse'); ?></strong> <?php printf(esc_html__('Vérifiez les permissions de %s.', 'sitepulse'), $log_file_display); ?></p>
                </div>
            <?php
            }
            // Subcase 1.3: The log file does not exist yet
            else {
            ?>
                <div class="notice notice-info">
                    <p><strong><?php esc_html_e('Votre configuration est correcte !', 'sitepulse'); ?></strong> <?php esc_html_e('Le journal de débogage est bien activé dans votre fichier wp-config.php.', 'sitepulse'); ?></p>
                    <p><?php printf(esc_html__('Le fichier %s n’a pas encore été créé car aucune erreur ne s’est produite. Il apparaîtra automatiquement dès que WordPress aura quelque chose à y écrire.', 'sitepulse'), $log_file_display); ?></p>
                </div>
            <?php
            }

        }
        // Case 2: Debug log is NOT enabled in wp-config.php
        else {
        ?>
            <div class="notice notice-warning" style="padding-bottom: 10px;">
                <h2><span class="dashicons dashicons-info-outline" style="padding-top: 4px;"></span> <?php esc_html_e('Journal de débogage non activé', 'sitepulse'); ?></h2>
                <p><?php echo wp_kses_post(sprintf(__('Pour que cet outil fonctionne, WordPress doit être configuré pour enregistrer les erreurs dans un fichier. Cela se fait en modifiant votre fichier <code>%s</code>.', 'sitepulse'), 'wp-config.php')); ?></p>

                <h4><?php esc_html_e('Comment activer le journal de débogage :', 'sitepulse'); ?></h4>
                <ol>
                    <li><?php esc_html_e('Connectez-vous à votre site via FTP ou le gestionnaire de fichiers de votre hébergeur.', 'sitepulse'); ?></li>
                    <li><?php echo wp_kses_post(sprintf(__('Trouvez le fichier <code>%s</code> à la racine de votre installation WordPress.', 'sitepulse'), 'wp-config.php')); ?></li>
                    <li><?php echo wp_kses_post(sprintf(__('Ouvrez ce fichier et cherchez la ligne : <br><code>%s</code>', 'sitepulse'), '/* C’est tout, ne touchez pas à ce qui suit ! Joyeuses publications. */')); ?></li>
                    <li><?php echo wp_kses_post(__('<strong>Juste avant</strong> cette ligne, ajoutez le code suivant :', 'sitepulse')); ?></li>
                </ol>
                <pre style="background: #f7f7f7; padding: 15px; border-radius: 4px;"><?php echo esc_html__("define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );", 'sitepulse'); ?></pre>
                <p><?php echo wp_kses_post(sprintf(__('<strong>Important :</strong> Une fois que vous avez résolu les problèmes, il est recommandé de repasser <code>%1$s</code> à <code>%2$s</code> sur un site en production.', 'sitepulse'), 'WP_DEBUG', 'false')); ?></p>
            </div>
        <?php
        }
        ?>
    </div>
    <?php
}
