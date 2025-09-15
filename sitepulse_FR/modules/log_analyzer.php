<?php
if (!defined('ABSPATH')) exit;

// Add the submenu page for the Log Analyzer
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        'Log Analyzer',
        'Logs',
        'manage_options',
        'sitepulse-logs',
        'sitepulse_log_analyzer_page'
    );
});

/**
 * Renders the Log Analyzer page with improved logic and explanations.
 */
function sitepulse_log_analyzer_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $log_file = WP_CONTENT_DIR . '/debug.log';
    $log_file_exists = file_exists($log_file) && is_readable($log_file);
    $debug_log_enabled = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-hammer"></span> Analyseur de Logs</h1>
        <p>Cet outil scanne le fichier <code>debug.log</code> de WordPress pour vous aider à trouver et corriger les problèmes sur votre site.</p>

        <?php
        // **FIX:** Rewrote the conditional logic using standard brace syntax to prevent parse errors.
        
        // Case 1: Debug log is enabled in wp-config.php
        if ($debug_log_enabled) {

            // Subcase 1.1: The log file exists and has content
            if ($log_file_exists && filesize($log_file) > 0) {
                $logs = file_get_contents($log_file);
                $lines = explode("\n", $logs);
                $categorized = ['fatal_errors' => [], 'errors' => [], 'warnings' => [], 'notices' => []];

                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;
                    if (stripos($line, 'PHP Fatal error') !== false) { $categorized['fatal_errors'][] = $line; } 
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
            elseif ($log_file_exists && filesize($log_file) === 0) {
            ?>
                <div class="notice notice-success">
                    <p><strong>Votre journal de débogage est actif et vide.</strong> Excellent travail, aucune erreur à signaler !</p>
                </div>
            <?php
            }
            // Subcase 1.3: The log file does not exist yet
            else {
            ?>
                <div class="notice notice-info">
                    <p><strong>Votre configuration est correcte !</strong> Le journal de débogage est bien activé dans votre fichier <code>wp-config.php</code>.</p>
                    <p>Le fichier <code>debug.log</code> n'a pas encore été créé car aucune erreur ne s'est encore produite. Il apparaîtra automatiquement dans le dossier <code>/wp-content/</code> dès que WordPress aura quelque chose à y écrire.</p>
                </div>
            <?php
            }

        }
        // Case 2: Debug log is NOT enabled in wp-config.php
        else {
        ?>
            <div class="notice notice-warning" style="padding-bottom: 10px;">
                <h2><span class="dashicons dashicons-info-outline" style="padding-top: 4px;"></span> Journal de débogage non activé</h2>
                <p>Pour que cet outil fonctionne, WordPress doit être configuré pour enregistrer les erreurs dans un fichier. Cela se fait en modifiant votre fichier <code>wp-config.php</code>.</p>
                
                <h4>Comment activer le journal de débogage :</h4>
                <ol>
                    <li>Connectez-vous à votre site via FTP ou le gestionnaire de fichiers de votre hébergeur.</li>
                    <li>Trouvez le fichier <code>wp-config.php</code> à la racine de votre installation WordPress.</li>
                    <li>Ouvrez ce fichier et cherchez la ligne : <br><code>/* C’est tout, ne touchez pas à ce qui suit ! Joyeuses publications. */</code></li>
                    <li><strong>Juste avant</strong> cette ligne, ajoutez le code suivant :</li>
                </ol>
                <pre style="background: #f7f7f7; padding: 15px; border-radius: 4px;">define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );</pre>
                <p><strong>Important :</strong> Une fois que vous avez résolu les problèmes, il est recommandé de repasser <code>WP_DEBUG</code> à <code>false</code> sur un site en production.</p>
            </div>
        <?php
        }
        ?>
    </div>
    <?php
}
