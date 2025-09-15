<?php
/**
 * SitePulse Admin Settings
 *
 * This file handles the creation of the admin menu and the rendering of settings pages.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) exit;

/**
 * Registers all the SitePulse admin menu and submenu pages.
 */
function sitepulse_admin_menu() {
    add_menu_page(
        'SitePulse Dashboard',
        'Sitepulse - JLG',
        'manage_options',
        'sitepulse-dashboard',
        'custom_dashboards_page',
        'dashicons-chart-area',
        30
    );

    add_submenu_page(
        'sitepulse-dashboard',
        'SitePulse Settings',
        'Settings',
        'manage_options',
        'sitepulse-settings',
        'sitepulse_settings_page'
    );

    if (defined('SITEPULSE_DEBUG') && SITEPULSE_DEBUG) {
        add_submenu_page(
            'sitepulse-dashboard',
            'SitePulse Debug',
            'Debug',
            'manage_options',
            'sitepulse-debug',
            'sitepulse_debug_page'
        );
    }
}
add_action('admin_menu', 'sitepulse_admin_menu');

/**
 * Registers the settings fields.
 */
function sitepulse_register_settings() {
    register_setting('sitepulse_settings', 'sitepulse_active_modules', [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_modules', 'default' => []
    ]);
    register_setting('sitepulse_settings', 'sitepulse_debug_mode', [
        'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false
    ]);
    register_setting('sitepulse_settings', 'sitepulse_gemini_api_key', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''
    ]);
}
add_action('admin_init', 'sitepulse_register_settings');

/**
 * Sanitizes the module selection.
 */
function sitepulse_sanitize_modules($input) {
    $valid_keys = ['log_analyzer', 'resource_monitor', 'plugin_impact_scanner', 'speed_analyzer', 'database_optimizer', 'maintenance_advisor', 'uptime_tracker', 'ai_insights', 'custom_dashboards', 'error_alerts'];
    $sanitized = [];
    if (is_array($input)) {
        foreach ($input as $key) {
            if (in_array($key, $valid_keys)) {
                $sanitized[] = $key;
            }
        }
    }
    return $sanitized;
}

/**
 * Renders the settings page.
 */
function sitepulse_settings_page() {
    $modules = [
        'log_analyzer' => 'Log Analyzer', 'resource_monitor' => 'Resource Monitor', 'plugin_impact_scanner' => 'Plugin Impact Scanner',
        'speed_analyzer' => 'Speed Analyzer', 'database_optimizer' => 'Database Optimizer', 'maintenance_advisor' => 'Maintenance Advisor',
        'uptime_tracker' => 'Uptime Tracker', 'ai_insights' => 'AI-Powered Insights', 'custom_dashboards' => 'Custom Dashboards', 'error_alerts' => 'Error Alerts',
    ];
    $active_modules = get_option('sitepulse_active_modules', []);

    if (isset($_POST['sitepulse_cleanup_nonce']) && wp_verify_nonce($_POST['sitepulse_cleanup_nonce'], 'sitepulse_cleanup')) {
        if (isset($_POST['sitepulse_clear_log']) && defined('SITEPULSE_DEBUG_LOG') && file_exists(SITEPULSE_DEBUG_LOG)) {
            $cleared = @file_put_contents(SITEPULSE_DEBUG_LOG, '');

            if ($cleared === false) {
                error_log(sprintf('SitePulse: unable to clear debug log file (%s).', SITEPULSE_DEBUG_LOG));
                echo '<div class="notice notice-error is-dismissible"><p>Impossible de vider le journal de débogage. Vérifiez les permissions du fichier.</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>Journal de débogage vidé.</p></div>';
            }
        }
        if (isset($_POST['sitepulse_clear_data'])) {
            delete_option('sitepulse_uptime_log');
            delete_transient('sitepulse_speed_scan_results');
            echo '<div class="notice notice-success is-dismissible"><p>Données stockées effacées.</p></div>';
        }
        if (isset($_POST['sitepulse_reset_all'])) {
            delete_option('sitepulse_active_modules');
            delete_option('sitepulse_debug_mode');
            delete_option('sitepulse_gemini_api_key');
            delete_option('sitepulse_uptime_log');
            delete_transient('sitepulse_speed_scan_results');
            if (defined('SITEPULSE_DEBUG_LOG') && file_exists(SITEPULSE_DEBUG_LOG)) { unlink(SITEPULSE_DEBUG_LOG); }
            $cron_hooks = ['log_analyzer_cron', 'resource_monitor_cron', 'speed_analyzer_cron', 'database_optimizer_cron', 'maintenance_advisor_cron', 'uptime_tracker_cron', 'ai_insights_cron'];
            foreach($cron_hooks as $hook) { wp_clear_scheduled_hook($hook); }
            echo '<div class="notice notice-success is-dismissible"><p>SitePulse a été réinitialisé.</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Réglages de SitePulse</h1>
        <form method="post" action="options.php">
            <?php settings_fields('sitepulse_settings'); do_settings_sections('sitepulse_settings'); ?>
            <h2>Paramètres de l'API</h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="sitepulse_gemini_api_key">Clé API Google Gemini</label></th>
                    <td>
                        <input type="password" id="sitepulse_gemini_api_key" name="sitepulse_gemini_api_key" value="<?php echo esc_attr(get_option('sitepulse_gemini_api_key')); ?>" class="regular-text" />
                        <p class="description">Entrez votre clé API pour activer les analyses par IA. Obtenez une clé sur <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>.</p>
                    </td>
                </tr>
            </table>
            <h2>Activer les Modules</h2>
            <p>Sélectionnez les modules de surveillance à activer.</p>
            <table class="form-table">
                <?php foreach ($modules as $key => $name): ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($name); ?></label></th>
                    <td><input type="checkbox" id="<?php echo esc_attr($key); ?>" name="sitepulse_active_modules[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $active_modules, true)); ?>></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row"><label for="sitepulse_debug_mode">Activer le Mode Debug</label></th>
                    <td>
                        <input type="checkbox" id="sitepulse_debug_mode" name="sitepulse_debug_mode" value="1" <?php checked(get_option('sitepulse_debug_mode')); ?>>
                        <p class="description">Active la journalisation détaillée et le tableau de bord de débogage. À n'utiliser que pour le dépannage.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Enregistrer les modifications'); ?>
        </form>
        <hr>
        <h2>Nettoyage & Réinitialisation</h2>
        <p>Gérez les données du plugin.</p>
        <form method="post" action="">
            <?php wp_nonce_field('sitepulse_cleanup', 'sitepulse_cleanup_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label>Vider le journal de debug</label></th>
                    <td><input type="submit" name="sitepulse_clear_log" value="Vider le journal" class="button"><p class="description">Supprime le contenu du fichier de log de débogage.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label>Vider les données stockées</label></th>
                    <td><input type="submit" name="sitepulse_clear_data" value="Vider les données" class="button"><p class="description">Supprime les données stockées comme les journaux de disponibilité et les résultats de scan.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label>Réinitialiser le plugin</label></th>
                    <td><input type="submit" name="sitepulse_reset_all" value="Tout réinitialiser" class="button button-danger" onclick="return confirm('Êtes-vous sûr ?');"><p class="description">Réinitialise SitePulse à son état d'installation initial.</p></td>
                </tr>
            </table>
        </form>
    </div>
    <?php
}

/**
 * Renders the debug page.
 */
function sitepulse_debug_page() {
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-bug"></span> Debug Dashboard</h1>
        <div class="notice notice-info"><p><strong>À quoi sert cette page ?</strong> Le mode Debug active une journalisation détaillée des actions du plugin. Cette page affiche ce journal et d'autres informations techniques pour vous aider, ou aider un développeur, à résoudre des problèmes. Ce menu n'apparaît que si le "Mode Debug" est activé dans les réglages de SitePulse.</p></div>
        <div id="dashboard-widgets-wrap">
            <div id="dashboard-widgets" class="metabox-holder">
                <div class="postbox-container">
                    <div class="postbox">
                        <h2 class="hndle"><span>Détails de l'Environnement</span></h2>
                        <div class="inside">
                            <ul>
                                <li><strong>Version de SitePulse:</strong> <?php echo SITEPULSE_VERSION; ?></li>
                                <li><strong>Version de WordPress:</strong> <?php echo get_bloginfo('version'); ?></li>
                                <li><strong>Version de PHP:</strong> <?php echo PHP_VERSION; ?></li>
                                <li><strong>Modules Actifs:</strong> <?php echo esc_html(implode(', ', get_option('sitepulse_active_modules', []))) ?: 'Aucun'; ?></li>
                                <li><strong>WP Memory Limit:</strong> <?php echo WP_MEMORY_LIMIT; ?></li>
                                <li><strong>Pic d'utilisation mémoire:</strong> <?php echo size_format(memory_get_peak_usage(true)); ?></li>
                            </ul>
                        </div>
                    </div>
                    <div class="postbox">
                        <h2 class="hndle"><span>Tâches Planifiées (Crons)</span></h2>
                        <div class="inside">
                           <ul>
                                <?php 
                                $crons = _get_cron_array();
                                $has_sitepulse_cron = false;
                                if (!empty($crons)) {
                                    foreach ($crons as $timestamp => $cron) {
                                        foreach ($cron as $hook => $events) {
                                            if (strpos($hook, 'sitepulse') !== false) {
                                                $has_sitepulse_cron = true;
                                                echo '<li><strong>' . esc_html($hook) . '</strong> - Prochaine exécution: ' . get_date_from_gmt(date('Y-m-d H:i:s', $timestamp)) . '</li>';
                                            }
                                        }
                                    }
                                }
                                if (!$has_sitepulse_cron) { echo '<li>Aucune tâche planifiée pour SitePulse trouvée.</li>'; }
                                ?>
                           </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <h2>Logs de Débogage Récents</h2>
        <div style="background: #fff; border: 1px solid #ccc; padding: 10px; max-height: 400px; overflow-y: scroll; font-family: monospace; font-size: 13px;">
            <?php
            if (defined('SITEPULSE_DEBUG_LOG') && file_exists(SITEPULSE_DEBUG_LOG)) {
                $log_contents = file_get_contents(SITEPULSE_DEBUG_LOG);
                if (!empty(trim($log_contents))) {
                    $log_lines = file(SITEPULSE_DEBUG_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $recent_logs = array_slice(array_reverse($log_lines), 0, 100);
                    echo '<pre>' . esc_html(implode("\n", $recent_logs)) . '</pre>';
                } else {
                    echo '<p>Le journal de débogage est actuellement vide.</p>';
                }
            } else {
                echo '<p>Fichier de log non trouvé ou illisible.</p>';
            }
            ?>
        </div>
    </div>
    <?php
}
