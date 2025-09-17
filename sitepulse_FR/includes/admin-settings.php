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
 * Wrapper for the main SitePulse dashboard page.
 *
 * Ensures that the menu callback registered via {@see add_menu_page()} is always
 * available, even when the Custom Dashboards module is disabled. When the module
 * is active the actual module output is rendered, otherwise an informative
 * notice is displayed with guidance on how to enable the feature.
 */
function sitepulse_render_dashboard_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    if (function_exists('sitepulse_custom_dashboards_page')) {
        sitepulse_custom_dashboards_page();
        return;
    }

    $active_modules = (array) get_option('sitepulse_active_modules', []);
    $is_dashboard_enabled = in_array('custom_dashboards', $active_modules, true);
    $settings_url = admin_url('admin.php?page=sitepulse-settings');

    if ($is_dashboard_enabled) {
        $notice = __('Le module de tableau de bord est activé mais son rendu est indisponible. Vérifiez les fichiers du plugin ou les journaux d’erreurs.', 'sitepulse');
    } else {
        $notice = sprintf(
            __('Le module de tableau de bord est désactivé. Activez-le depuis les <a href="%s">réglages de SitePulse</a>.', 'sitepulse'),
            esc_url($settings_url)
        );
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('SitePulse Dashboard', 'sitepulse') . '</h1>';
    echo '<div class="notice notice-warning"><p>' . wp_kses_post($notice) . '</p></div>';
    echo '</div>';
}

/**
 * Registers all the SitePulse admin menu and submenu pages.
 */
function sitepulse_admin_menu() {
    add_menu_page(
        'SitePulse Dashboard',
        'Sitepulse - JLG',
        'manage_options',
        'sitepulse-dashboard',
        'sitepulse_render_dashboard_page',
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
    register_setting('sitepulse_settings', 'sitepulse_cpu_alert_threshold', [
        'type' => 'number', 'sanitize_callback' => 'sitepulse_sanitize_cpu_threshold', 'default' => 5
    ]);
    register_setting('sitepulse_settings', 'sitepulse_alert_cooldown_minutes', [
        'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_cooldown_minutes', 'default' => 60
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
            if (in_array($key, $valid_keys, true)) {
                $sanitized[] = $key;
            }
        }
    }
    return $sanitized;
}

/**
 * Sanitizes the CPU threshold value for alerts.
 *
 * @param mixed $value Raw user input value.
 * @return float Sanitized CPU threshold.
 */
function sitepulse_sanitize_cpu_threshold($value) {
    $value = is_scalar($value) ? (float) $value : 0.0;
    if ($value <= 0) {
        $value = 5.0;
    }
    return $value;
}

/**
 * Sanitizes the cooldown window (in minutes) used for alert throttling.
 *
 * @param mixed $value Raw user input value.
 * @return int Sanitized cooldown length in minutes.
 */
function sitepulse_sanitize_cooldown_minutes($value) {
    $value = is_scalar($value) ? absint($value) : 0;
    if ($value < 1) {
        $value = 60;
    }
    return $value;
}

if (!function_exists('sitepulse_delete_transients_by_prefix')) {
    /**
     * Deletes all transients whose names start with the provided prefix.
     *
     * @param string $prefix Transient prefix to match.
     * @return void
     */
    function sitepulse_delete_transients_by_prefix($prefix) {
        global $wpdb;

        if (!is_string($prefix) || $prefix === '' || !($wpdb instanceof wpdb)) {
            return;
        }

        $like = $wpdb->esc_like($prefix) . '%';
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . $like
            )
        );

        if (empty($option_names)) {
            return;
        }

        $transient_prefix = strlen('_transient_');

        foreach ($option_names as $option_name) {
            $transient_key = substr($option_name, $transient_prefix);

            if ($transient_key !== '') {
                delete_transient($transient_key);
            }
        }
    }
}

if (!function_exists('sitepulse_delete_site_transients_by_prefix')) {
    /**
     * Deletes all site transients whose names start with the provided prefix.
     *
     * @param string $prefix Site transient prefix to match.
     * @return void
     */
    function sitepulse_delete_site_transients_by_prefix($prefix) {
        if (!is_multisite() || !function_exists('delete_site_transient')) {
            return;
        }

        global $wpdb;

        if (!is_string($prefix) || $prefix === '' || !($wpdb instanceof wpdb)) {
            return;
        }

        $like = $wpdb->esc_like($prefix) . '%';
        $meta_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                '_site_transient_' . $like
            )
        );

        if (empty($meta_keys)) {
            return;
        }

        $site_transient_prefix = strlen('_site_transient_');

        foreach ($meta_keys as $meta_key) {
            $transient_key = substr($meta_key, $site_transient_prefix);

            if ($transient_key !== '') {
                delete_site_transient($transient_key);
            }
        }
    }
}

/**
 * Reads the last lines from a log file without loading it entirely in memory.
 *
 * The maximum number of bytes read is deliberately capped to avoid memory pressure
 * with very large log files.
 *
 * @param string $file_path  Path to the log file.
 * @param int    $max_lines  Number of lines to return.
 * @param int    $max_bytes  Maximum number of bytes to read from the end of the file.
 * @return array|null Array of recent log lines on success, empty array if the file is empty,
 *                    or null on failure to read the file.
 */
function sitepulse_get_recent_log_lines($file_path, $max_lines = 100, $max_bytes = 131072) {
    $max_lines = max(1, (int) $max_lines);
    $max_bytes = max(1024, (int) $max_bytes);

    if (!is_readable($file_path)) {
        return null;
    }

    $fopen_error = null;
    set_error_handler(function ($errno, $errstr) use (&$fopen_error) {
        $fopen_error = $errstr;

        return true;
    });

    try {
        $handle = fopen($file_path, 'rb');
    } catch (\Throwable $exception) {
        $fopen_error = $exception->getMessage();
        $handle = false;
    } finally {
        restore_error_handler();
    }

    if (!$handle) {
        if (function_exists('sitepulse_log')) {
            $message = sprintf('Failed to open log file for reading: %s', $file_path);

            if (is_string($fopen_error) && $fopen_error !== '') {
                $message .= ' | Error: ' . $fopen_error;
            }

            sitepulse_log($message, 'ERROR');
        }

        return null;
    }

    $buffer = '';
    $chunk_size = 4096;
    $stats = fstat($handle);
    $file_size = isset($stats['size']) ? (int) $stats['size'] : 0;
    $bytes_to_read = min($file_size, $max_bytes);
    $position = $file_size;

    while ($bytes_to_read > 0 && $position > 0) {
        $read_size = (int) min($chunk_size, $bytes_to_read, $position);
        if ($read_size <= 0) {
            break;
        }

        $position -= $read_size;
        $bytes_to_read -= $read_size;

        if (fseek($handle, $position, SEEK_SET) !== 0) {
            break;
        }

        $chunk = fread($handle, $read_size);
        if ($chunk === false) {
            break;
        }

        $buffer = $chunk . $buffer;

        if (substr_count($buffer, "\n") >= ($max_lines + 1)) {
            break;
        }
    }

    fclose($handle);

    if ($buffer === '') {
        return [];
    }

    $buffer = str_replace(["\r\n", "\r"], "\n", $buffer);
    $buffer = rtrim($buffer, "\n");

    if ($buffer === '') {
        return [];
    }

    $lines = explode("\n", $buffer);
    $filtered = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $filtered[] = $line;
    }

    if (count($filtered) > $max_lines) {
        $filtered = array_slice($filtered, -$max_lines);
    }

    return $filtered;
}

/**
 * Renders the settings page.
 */
function sitepulse_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $modules = [
        'log_analyzer' => 'Log Analyzer', 'resource_monitor' => 'Resource Monitor', 'plugin_impact_scanner' => 'Plugin Impact Scanner',
        'speed_analyzer' => 'Speed Analyzer', 'database_optimizer' => 'Database Optimizer', 'maintenance_advisor' => 'Maintenance Advisor',
        'uptime_tracker' => 'Uptime Tracker', 'ai_insights' => 'AI-Powered Insights', 'custom_dashboards' => 'Custom Dashboards', 'error_alerts' => 'Error Alerts',
    ];
    $active_modules = get_option('sitepulse_active_modules', []);
    $debug_mode_option = get_option('sitepulse_debug_mode');
    $is_debug_mode_enabled = rest_sanitize_boolean($debug_mode_option);

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
            $plugin_impact_option = defined('SITEPULSE_PLUGIN_IMPACT_OPTION')
                ? SITEPULSE_PLUGIN_IMPACT_OPTION
                : 'sitepulse_plugin_impact_stats';

            $options_to_delete = [
                'sitepulse_active_modules',
                'sitepulse_debug_mode',
                'sitepulse_gemini_api_key',
                'sitepulse_uptime_log',
                'sitepulse_last_load_time',
                'sitepulse_cpu_alert_threshold',
                'sitepulse_alert_cooldown_minutes',
                $plugin_impact_option,
            ];

            foreach ($options_to_delete as $option_key) {
                delete_option($option_key);
            }

            $transients_to_delete = [
                'sitepulse_speed_scan_results',
                'sitepulse_ai_insight',
                'sitepulse_error_alert_cpu_lock',
                'sitepulse_error_alert_php_fatal_lock',
            ];

            $transient_prefixes_to_delete = [
                'sitepulse_plugin_dir_size_',
            ];

            foreach ($transients_to_delete as $transient_key) {
                delete_transient($transient_key);

                if (function_exists('delete_site_transient')) {
                    delete_site_transient($transient_key);
                }
            }

            foreach ($transient_prefixes_to_delete as $transient_prefix) {
                sitepulse_delete_transients_by_prefix($transient_prefix);
                sitepulse_delete_site_transients_by_prefix($transient_prefix);
            }
            if (defined('SITEPULSE_DEBUG_LOG') && file_exists(SITEPULSE_DEBUG_LOG)) { unlink(SITEPULSE_DEBUG_LOG); }
            $cron_hooks = function_exists('sitepulse_get_cron_hooks') ? sitepulse_get_cron_hooks() : [];
            foreach ($cron_hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }
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
                        <input type="hidden" name="sitepulse_debug_mode" value="0">
                        <input type="checkbox" id="sitepulse_debug_mode" name="sitepulse_debug_mode" value="1" <?php checked($is_debug_mode_enabled); ?>>
                        <p class="description">Active la journalisation détaillée et le tableau de bord de débogage. À n'utiliser que pour le dépannage.</p>
                    </td>
                </tr>
            </table>
            <h2>Alertes</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="sitepulse_cpu_alert_threshold">Seuil d'alerte de charge CPU</label></th>
                    <td>
                        <input type="number" step="0.1" min="0" id="sitepulse_cpu_alert_threshold" name="sitepulse_cpu_alert_threshold" value="<?php echo esc_attr(get_option('sitepulse_cpu_alert_threshold', 5)); ?>" class="small-text">
                        <p class="description">Une alerte e-mail est envoyée lorsque la charge moyenne sur 1 minute dépasse ce seuil.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sitepulse_alert_cooldown_minutes">Fenêtre anti-spam (minutes)</label></th>
                    <td>
                        <input type="number" min="1" id="sitepulse_alert_cooldown_minutes" name="sitepulse_alert_cooldown_minutes" value="<?php echo esc_attr(get_option('sitepulse_alert_cooldown_minutes', 60)); ?>" class="small-text">
                        <p class="description">Empêche l'envoi de plusieurs e-mails identiques pendant la durée spécifiée.</p>
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
                    <td>
                        <input type="submit" name="sitepulse_reset_all" value="Tout réinitialiser" class="button button-danger" onclick="return confirm('Êtes-vous sûr ?');">
                        <p class="description">Réinitialise SitePulse à son état d'installation initial.</p>
                    </td>
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
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $log_max_lines = 100;
    $log_max_bytes = 131072;

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
                            <?php $active_modules_list = implode(', ', get_option('sitepulse_active_modules', [])); ?>
                            <ul>
                                <li><strong>Version de SitePulse:</strong> <?php echo esc_html(SITEPULSE_VERSION); ?></li>
                                <li><strong>Version de WordPress:</strong> <?php echo esc_html(get_bloginfo('version')); ?></li>
                                <li><strong>Version de PHP:</strong> <?php echo esc_html(PHP_VERSION); ?></li>
                                <li><strong>Modules Actifs:</strong> <?php echo $active_modules_list ? esc_html($active_modules_list) : esc_html('Aucun'); ?></li>
                                <li><strong>WP Memory Limit:</strong> <?php echo esc_html(WP_MEMORY_LIMIT); ?></li>
                                <li><strong>Pic d'utilisation mémoire:</strong> <?php echo esc_html(size_format(memory_get_peak_usage(true))); ?></li>
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
                                                $next_run = get_date_from_gmt(date('Y-m-d H:i:s', $timestamp));
                                                echo '<li><strong>' . esc_html($hook) . '</strong> - Prochaine exécution: ' . esc_html($next_run) . '</li>';
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
        <p class="description">
            <?php
            printf(
                esc_html__('Seules les %1$d dernières lignes du journal (limitées à %2$s) sont chargées pour éviter toute surcharge mémoire.', 'sitepulse'),
                (int) $log_max_lines,
                esc_html(size_format($log_max_bytes))
            );
            ?>
        </p>
        <div style="background: #fff; border: 1px solid #ccc; padding: 10px; max-height: 400px; overflow-y: scroll; font-family: monospace; font-size: 13px;">
            <?php
            if (defined('SITEPULSE_DEBUG_LOG') && is_readable(SITEPULSE_DEBUG_LOG)) {
                $recent_logs = sitepulse_get_recent_log_lines(SITEPULSE_DEBUG_LOG, $log_max_lines, $log_max_bytes);

                if (is_array($recent_logs) && !empty($recent_logs)) {
                    echo '<pre>' . esc_html(implode("\n", $recent_logs)) . '</pre>';
                } elseif (is_array($recent_logs)) {
                    echo '<p>Le journal de débogage est actuellement vide.</p>';
                } else {
                    echo '<p>Fichier de log non trouvé ou illisible.</p>';
                }
            } else {
                echo '<p>Fichier de log non trouvé ou illisible.</p>';
            }
            ?>
        </div>
    </div>
    <?php
}
