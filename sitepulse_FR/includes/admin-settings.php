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

    $active_modules = (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);
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
    register_setting('sitepulse_settings', SITEPULSE_OPTION_ACTIVE_MODULES, [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_modules', 'default' => []
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_DEBUG_MODE, [
        'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_GEMINI_API_KEY, [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, [
        'type' => 'number', 'sanitize_callback' => 'sitepulse_sanitize_cpu_threshold', 'default' => 5
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES, [
        'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_cooldown_minutes', 'default' => 60
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_ALERT_INTERVAL, [
        'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_alert_interval', 'default' => 5
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_ALERT_RECIPIENTS, [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_alert_recipients', 'default' => []
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

/**
 * Sanitizes the alert interval (in minutes) used to schedule error checks.
 *
 * @param mixed $value Raw user input value.
 * @return int Sanitized interval in minutes.
 */
function sitepulse_sanitize_alert_interval($value) {
    $allowed_values = [5, 10, 15, 30];
    $value = is_scalar($value) ? absint($value) : 0;

    if ($value < 5) {
        $value = 5;
    } elseif ($value > 30) {
        $value = 30;
    }

    if (!in_array($value, $allowed_values, true)) {
        $value = 5;
    }

    return $value;
}

/**
 * Sanitizes the list of e-mail recipients for alerts.
 *
 * @param mixed $value Raw user input value.
 * @return array Sanitized list of e-mail addresses.
 */
function sitepulse_sanitize_alert_recipients($value) {
    if (is_string($value)) {
        $value = preg_split('/[\r\n,]+/', $value);
    } elseif (!is_array($value)) {
        $value = [];
    }

    $sanitized = [];

    foreach ($value as $email) {
        if (!is_string($email)) {
            continue;
        }

        $email = trim($email);
        if ($email === '') {
            continue;
        }

        $normalized = sanitize_email($email);
        if ($normalized !== '' && is_email($normalized)) {
            $sanitized[] = $normalized;
        }
    }

    return array_values(array_unique($sanitized));
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
    $active_modules = get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);
    $debug_mode_option = get_option(SITEPULSE_OPTION_DEBUG_MODE);
    $is_debug_mode_enabled = rest_sanitize_boolean($debug_mode_option);

    if (isset($_POST[SITEPULSE_NONCE_FIELD_CLEANUP]) && wp_verify_nonce($_POST[SITEPULSE_NONCE_FIELD_CLEANUP], SITEPULSE_NONCE_ACTION_CLEANUP)) {
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
            delete_option(SITEPULSE_OPTION_UPTIME_LOG);
            delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
            echo '<div class="notice notice-success is-dismissible"><p>Données stockées effacées.</p></div>';
        }
        if (isset($_POST['sitepulse_reset_all'])) {
            $reset_success = true;
            $log_deletion_failed = false;
            $options_to_delete = [
                SITEPULSE_OPTION_ACTIVE_MODULES,
                SITEPULSE_OPTION_DEBUG_MODE,
                SITEPULSE_OPTION_GEMINI_API_KEY,
                SITEPULSE_OPTION_UPTIME_LOG,
                SITEPULSE_OPTION_LAST_LOAD_TIME,
                SITEPULSE_OPTION_CPU_ALERT_THRESHOLD,
                SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES,
                SITEPULSE_PLUGIN_IMPACT_OPTION,
            ];

            foreach ($options_to_delete as $option_key) {
                delete_option($option_key);
            }

            $transients_to_delete = [
                SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS,
                SITEPULSE_TRANSIENT_AI_INSIGHT,
                SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK,
                SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK,
            ];

            $transient_prefixes_to_delete = [SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX];

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

            if (defined('SITEPULSE_DEBUG_LOG') && file_exists(SITEPULSE_DEBUG_LOG)) {
                $log_deleted = false;
                $delete_error_message = '';

                if (function_exists('wp_delete_file')) {
                    $delete_result = wp_delete_file(SITEPULSE_DEBUG_LOG);

                    if (function_exists('is_wp_error') && is_wp_error($delete_result)) {
                        $delete_error_message = $delete_result->get_error_message();
                    } elseif ($delete_result === false) {
                        $delete_error_message = 'wp_delete_file returned false.';
                    }

                    if (!file_exists(SITEPULSE_DEBUG_LOG)) {
                        $log_deleted = true;
                    }
                }

                if (!$log_deleted) {
                    if (@unlink(SITEPULSE_DEBUG_LOG)) {
                        $log_deleted = true;
                    } elseif ($delete_error_message === '') {
                        $delete_error_message = 'unlink failed.';
                    }
                }

                if (!$log_deleted) {
                    $reset_success = false;
                    $log_deletion_failed = true;
                    $log_message = sprintf('SitePulse: impossible de supprimer le journal de débogage (%s). %s', SITEPULSE_DEBUG_LOG, $delete_error_message);

                    if (function_exists('sitepulse_log')) {
                        sitepulse_log($log_message, 'ERROR');
                    } else {
                        error_log($log_message);
                    }
                }
            }
            $cron_hooks = function_exists('sitepulse_get_cron_hooks') ? sitepulse_get_cron_hooks() : [];
            foreach ($cron_hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }
            if ($reset_success && function_exists('sitepulse_activate_site')) {
                sitepulse_activate_site();
            }

            if ($reset_success) {
                echo '<div class="notice notice-success is-dismissible"><p>SitePulse a été réinitialisé.</p></div>';
            } elseif ($log_deletion_failed) {
                echo '<div class="notice notice-error is-dismissible"><p>Impossible de supprimer le journal de débogage. Vérifiez les permissions du fichier.</p></div>';
            }
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
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_GEMINI_API_KEY); ?>">Clé API Google Gemini</label></th>
                    <td>
                        <input type="password" id="<?php echo esc_attr(SITEPULSE_OPTION_GEMINI_API_KEY); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_GEMINI_API_KEY); ?>" value="<?php echo esc_attr(get_option(SITEPULSE_OPTION_GEMINI_API_KEY)); ?>" class="regular-text" />
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
                    <td><input type="checkbox" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ACTIVE_MODULES); ?>[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $active_modules, true)); ?>></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>">Activer le Mode Debug</label></th>
                    <td>
                        <input type="hidden" name="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>" value="0">
                        <input type="checkbox" id="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>" value="1" <?php checked($is_debug_mode_enabled); ?>>
                        <p class="description">Active la journalisation détaillée et le tableau de bord de débogage. À n'utiliser que pour le dépannage.</p>
                    </td>
                </tr>
            </table>
            <h2>Alertes</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>">Destinataires des alertes</label></th>
                    <td>
                        <?php
                        $alert_recipients = (array) get_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, []);
                        $recipients_value = implode("\n", $alert_recipients);
                        ?>
                        <textarea id="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>" rows="4" cols="50" class="large-text code"><?php echo esc_textarea($recipients_value); ?></textarea>
                        <p class="description">Entrez une adresse par ligne (ou séparées par des virgules). L'adresse e-mail de l'administrateur sera toujours incluse si elle est valide.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>">Seuil d'alerte de charge CPU</label></th>
                    <td>
                        <input type="number" step="0.1" min="0" id="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>" value="<?php echo esc_attr(get_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 5)); ?>" class="small-text">
                        <p class="description">Une alerte e-mail est envoyée lorsque la charge moyenne sur 1 minute dépasse ce seuil.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES); ?>">Fenêtre anti-spam (minutes)</label></th>
                    <td>
                        <input type="number" min="1" id="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES); ?>" value="<?php echo esc_attr(get_option(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES, 60)); ?>" class="small-text">
                        <p class="description">Empêche l'envoi de plusieurs e-mails identiques pendant la durée spécifiée.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_INTERVAL); ?>">Fréquence des vérifications</label></th>
                    <td>
                        <?php
                        $interval_value   = (int) get_option(SITEPULSE_OPTION_ALERT_INTERVAL, 5);
                        $interval_choices = [
                            5  => __('Toutes les 5 minutes', 'sitepulse'),
                            10 => __('Toutes les 10 minutes', 'sitepulse'),
                            15 => __('Toutes les 15 minutes', 'sitepulse'),
                            30 => __('Toutes les 30 minutes', 'sitepulse'),
                        ];
                        ?>
                        <select id="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_INTERVAL); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_INTERVAL); ?>">
                            <?php foreach ($interval_choices as $minutes => $label) : ?>
                                <option value="<?php echo esc_attr($minutes); ?>" <?php selected($interval_value, $minutes); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Détermine la fréquence des vérifications automatisées pour les alertes.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Enregistrer les modifications'); ?>
        </form>
        <hr>
        <h2>Nettoyage & Réinitialisation</h2>
        <p>Gérez les données du plugin.</p>
        <form method="post" action="">
            <?php wp_nonce_field(SITEPULSE_NONCE_ACTION_CLEANUP, SITEPULSE_NONCE_FIELD_CLEANUP); ?>
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
                            <?php $active_modules_list = implode(', ', get_option(SITEPULSE_OPTION_ACTIVE_MODULES, [])); ?>
                            <ul>
                                <li><strong>Version de SitePulse:</strong> <?php echo esc_html(SITEPULSE_VERSION); ?></li>
                                <li><strong>Version de WordPress:</strong> <?php echo esc_html(get_bloginfo('version')); ?></li>
                                <li><strong>Version de PHP:</strong> <?php echo esc_html(PHP_VERSION); ?></li>
                                <li><strong>Modules Actifs:</strong> <?php echo $active_modules_list ? esc_html($active_modules_list) : esc_html('Aucun'); ?></li>
                                <li><strong>WP Memory Limit:</strong> <?php echo esc_html(WP_MEMORY_LIMIT); ?></li>
                                <li><strong>Pic d'utilisation mémoire:</strong> <?php echo wp_kses_post(size_format(memory_get_peak_usage(true))); ?></li>
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
                wp_kses_post(size_format($log_max_bytes))
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
