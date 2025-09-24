<?php
/**
 * SitePulse Admin Settings
 *
 * This file handles the creation of the admin menu and the rendering of settings pages.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) exit;

if (!defined('SITEPULSE_SETTINGS_NOTICE_TRANSIENT')) {
    define('SITEPULSE_SETTINGS_NOTICE_TRANSIENT', 'sitepulse_settings_notices');
}

/**
 * Stores an admin notice to be displayed on the SitePulse settings screen.
 *
 * @param string $message Human readable message.
 * @param string $type    Type of notice: success (default), warning or error.
 *
 * @return void
 */
function sitepulse_add_settings_notice($message, $type = 'success') {
    $notices = get_transient(SITEPULSE_SETTINGS_NOTICE_TRANSIENT);

    if (!is_array($notices)) {
        $notices = [];
    }

    $notices[] = [
        'message' => (string) $message,
        'type'    => in_array($type, ['success', 'warning', 'error'], true) ? $type : 'success',
    ];

    set_transient(SITEPULSE_SETTINGS_NOTICE_TRANSIENT, $notices, MINUTE_IN_SECONDS);
}

/**
 * Outputs any stored admin notices for the SitePulse settings screen.
 *
 * @return void
 */
function sitepulse_render_settings_notices() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'sitepulse-settings') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    $notices = get_transient(SITEPULSE_SETTINGS_NOTICE_TRANSIENT);

    if (empty($notices) || !is_array($notices)) {
        return;
    }

    delete_transient(SITEPULSE_SETTINGS_NOTICE_TRANSIENT);

    foreach ($notices as $notice) {
        $type    = isset($notice['type']) ? $notice['type'] : 'success';
        $message = isset($notice['message']) ? $notice['message'] : '';

        if ($message === '') {
            continue;
        }

        $classes = ['notice'];

        switch ($type) {
            case 'error':
                $classes[] = 'notice-error';
                break;
            case 'warning':
                $classes[] = 'notice-warning';
                break;
            default:
                $classes[] = 'notice-success';
        }

        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr(implode(' ', $classes)),
            wp_kses_post($message)
        );
    }
}
add_action('admin_notices', 'sitepulse_render_settings_notices');

/**
 * Returns the list of plugin options exported/imported via settings tools.
 *
 * @return string[]
 */
function sitepulse_get_managed_settings_keys() {
    return [
        SITEPULSE_OPTION_ACTIVE_MODULES,
        SITEPULSE_OPTION_DEBUG_MODE,
        SITEPULSE_OPTION_GEMINI_API_KEY,
        SITEPULSE_OPTION_CPU_ALERT_THRESHOLD,
        SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES,
        SITEPULSE_OPTION_ALERT_INTERVAL,
        SITEPULSE_OPTION_ALERT_RECIPIENTS,
    ];
}

/**
 * Builds the export payload for the current SitePulse configuration.
 *
 * @return array<string,mixed>
 */
function sitepulse_build_settings_export_payload() {
    $settings = [];

    foreach (sitepulse_get_managed_settings_keys() as $option_key) {
        $settings[$option_key] = get_option($option_key);
    }

    return [
        'plugin'       => 'sitepulse',
        'version'      => defined('SITEPULSE_VERSION') ? SITEPULSE_VERSION : null,
        'generated_at' => gmdate('c'),
        'settings'     => $settings,
    ];
}

/**
 * Handles the export request for SitePulse settings.
 *
 * @return void
 */
function sitepulse_handle_settings_export() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse'));
    }

    check_admin_referer('sitepulse_export_settings');

    $payload = sitepulse_build_settings_export_payload();
    $json    = wp_json_encode($payload, JSON_PRETTY_PRINT);

    if ($json === false) {
        sitepulse_add_settings_notice(__('Impossible de générer le fichier d’export. Réessayez plus tard.', 'sitepulse'), 'error');
        wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
        exit;
    }

    nocache_headers();

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="sitepulse-settings-' . gmdate('Ymd-His') . '.json"');

    echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}
add_action('admin_post_sitepulse_export_settings', 'sitepulse_handle_settings_export');

/**
 * Sanitizes and persists settings imported from a JSON payload.
 *
 * @param array<string,mixed> $settings Imported settings array.
 * @return int Number of options updated.
 */
function sitepulse_import_settings_from_array($settings) {
    $updated = 0;

    $settings = is_array($settings) ? $settings : [];

    foreach (sitepulse_get_managed_settings_keys() as $option_key) {
        if (!array_key_exists($option_key, $settings)) {
            continue;
        }

        $value = $settings[$option_key];

        switch ($option_key) {
            case SITEPULSE_OPTION_ACTIVE_MODULES:
                $value = sitepulse_sanitize_modules($value);
                break;
            case SITEPULSE_OPTION_DEBUG_MODE:
                $value = rest_sanitize_boolean($value);
                break;
            case SITEPULSE_OPTION_GEMINI_API_KEY:
                $value = sanitize_text_field(is_scalar($value) ? $value : '');
                break;
            case SITEPULSE_OPTION_CPU_ALERT_THRESHOLD:
                $value = sitepulse_sanitize_cpu_threshold($value);
                break;
            case SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES:
                $value = sitepulse_sanitize_cooldown_minutes($value);
                break;
            case SITEPULSE_OPTION_ALERT_INTERVAL:
                $value = sitepulse_sanitize_alert_interval($value);
                break;
            case SITEPULSE_OPTION_ALERT_RECIPIENTS:
                $value = sitepulse_sanitize_alert_recipients($value);
                break;
            default:
                continue 2;
        }

        update_option($option_key, $value);
        $updated++;
    }

    return $updated;
}

/**
 * Handles the import request for SitePulse settings.
 *
 * @return void
 */
function sitepulse_handle_settings_import() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse'));
    }

    check_admin_referer('sitepulse_import_settings');

    if (!isset($_FILES['sitepulse_settings_file'])) {
        sitepulse_add_settings_notice(__('Aucun fichier reçu pour l’import.', 'sitepulse'), 'error');
        wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
        exit;
    }

    $file = $_FILES['sitepulse_settings_file'];

    if (!is_array($file) || !isset($file['tmp_name'], $file['error'])) {
        sitepulse_add_settings_notice(__('Le fichier d’import est invalide.', 'sitepulse'), 'error');
        wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
        exit;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        sitepulse_add_settings_notice(__('Échec du téléversement du fichier. Réessayez.', 'sitepulse'), 'error');
        wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
        exit;
    }

    if (!empty($file['size']) && (int) $file['size'] > 524288) {
        sitepulse_add_settings_notice(__('Le fichier importé est trop volumineux. Limitez-vous à 512 Ko.', 'sitepulse'), 'error');
        wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
        exit;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        sitepulse_add_settings_notice(__('Le fichier importé n’a pas été téléversé correctement.', 'sitepulse'), 'error');
        wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
        exit;
    }

    $file_contents = file_get_contents($file['tmp_name']);

    if ($file_contents === false) {
        sitepulse_add_settings_notice(__('Impossible de lire le fichier importé.', 'sitepulse'), 'error');
        wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
        exit;
    }

    $data = json_decode($file_contents, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        sitepulse_add_settings_notice(__('Le fichier importé n’est pas un JSON valide.', 'sitepulse'), 'error');
        wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
        exit;
    }

    if (isset($data['plugin']) && $data['plugin'] !== 'sitepulse') {
        sitepulse_add_settings_notice(__('Le fichier importé ne correspond pas au plugin SitePulse.', 'sitepulse'), 'error');
        wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
        exit;
    }

    if (!isset($data['settings']) || !is_array($data['settings'])) {
        sitepulse_add_settings_notice(__('Les réglages sont manquants dans le fichier importé.', 'sitepulse'), 'error');
        wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
        exit;
    }

    $updated_count = sitepulse_import_settings_from_array($data['settings']);

    if ($updated_count === 0) {
        sitepulse_add_settings_notice(__('Aucun réglage n’a été importé. Vérifiez le contenu du fichier.', 'sitepulse'), 'warning');
    } else {
        sitepulse_add_settings_notice(__('Réglages importés avec succès.', 'sitepulse'), 'success');
    }

    wp_safe_redirect(admin_url('admin.php?page=sitepulse-settings'));
    exit;
}
add_action('admin_post_sitepulse_import_settings', 'sitepulse_handle_settings_import');

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
        'log_analyzer'          => __('Log Analyzer', 'sitepulse'),
        'resource_monitor'      => __('Resource Monitor', 'sitepulse'),
        'plugin_impact_scanner' => __('Plugin Impact Scanner', 'sitepulse'),
        'speed_analyzer'        => __('Speed Analyzer', 'sitepulse'),
        'database_optimizer'    => __('Database Optimizer', 'sitepulse'),
        'maintenance_advisor'   => __('Maintenance Advisor', 'sitepulse'),
        'uptime_tracker'        => __('Uptime Tracker', 'sitepulse'),
        'ai_insights'           => __('AI-Powered Insights', 'sitepulse'),
        'custom_dashboards'     => __('Custom Dashboards', 'sitepulse'),
        'error_alerts'          => __('Error Alerts', 'sitepulse'),
    ];
    $active_modules = get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);
    $debug_mode_option = get_option(SITEPULSE_OPTION_DEBUG_MODE);
    $is_debug_mode_enabled = rest_sanitize_boolean($debug_mode_option);

    if (isset($_POST[SITEPULSE_NONCE_FIELD_CLEANUP]) && wp_verify_nonce($_POST[SITEPULSE_NONCE_FIELD_CLEANUP], SITEPULSE_NONCE_ACTION_CLEANUP)) {
        if (isset($_POST['sitepulse_clear_log']) && defined('SITEPULSE_DEBUG_LOG') && file_exists(SITEPULSE_DEBUG_LOG)) {
            $cleared = @file_put_contents(SITEPULSE_DEBUG_LOG, '');

            if ($cleared === false) {
                error_log(sprintf('SitePulse: unable to clear debug log file (%s).', SITEPULSE_DEBUG_LOG));
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Impossible de vider le journal de débogage. Vérifiez les permissions du fichier.', 'sitepulse') . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Journal de débogage vidé.', 'sitepulse') . '</p></div>';
            }
        }
        if (isset($_POST['sitepulse_clear_data'])) {
            delete_option(SITEPULSE_OPTION_UPTIME_LOG);
            delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Données stockées effacées.', 'sitepulse') . '</p></div>';
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
                SITEPULSE_OPTION_ALERT_INTERVAL,
                SITEPULSE_OPTION_ALERT_RECIPIENTS,
                SITEPULSE_PLUGIN_IMPACT_OPTION,
            ];

            // Clear stored alert recipients so the default (empty) list is restored on activation.
            $options_to_delete[] = SITEPULSE_OPTION_ALERT_RECIPIENTS;

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
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('SitePulse a été réinitialisé.', 'sitepulse') . '</p></div>';
            } elseif ($log_deletion_failed) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Impossible de supprimer le journal de débogage. Vérifiez les permissions du fichier.', 'sitepulse') . '</p></div>';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Réglages de SitePulse', 'sitepulse'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('sitepulse_settings'); do_settings_sections('sitepulse_settings'); ?>
            <h2><?php esc_html_e("Paramètres de l'API", 'sitepulse'); ?></h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_GEMINI_API_KEY); ?>"><?php esc_html_e('Clé API Google Gemini', 'sitepulse'); ?></label></th>
                    <td>
                        <input type="password" id="<?php echo esc_attr(SITEPULSE_OPTION_GEMINI_API_KEY); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_GEMINI_API_KEY); ?>" value="<?php echo esc_attr(get_option(SITEPULSE_OPTION_GEMINI_API_KEY)); ?>" class="regular-text" />
                        <p class="description"><?php printf(
                            wp_kses(
                                /* translators: %s: URL to Google AI Studio. */
                                __('Entrez votre clé API pour activer les analyses par IA. Obtenez une clé sur <a href="%s" target="_blank">Google AI Studio</a>.', 'sitepulse'),
                                ['a' => ['href' => true, 'target' => true]]
                            ),
                            esc_url('https://aistudio.google.com/app/apikey')
                        ); ?></p>
                    </td>
                </tr>
            </table>
            <h2><?php esc_html_e('Activer les Modules', 'sitepulse'); ?></h2>
            <p><?php esc_html_e('Sélectionnez les modules de surveillance à activer.', 'sitepulse'); ?></p>
            <table class="form-table">
                <?php foreach ($modules as $key => $name): ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($name); ?></label></th>
                    <td><input type="checkbox" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ACTIVE_MODULES); ?>[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $active_modules, true)); ?>></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>"><?php esc_html_e('Activer le Mode Debug', 'sitepulse'); ?></label></th>
                    <td>
                        <input type="hidden" name="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>" value="0">
                        <input type="checkbox" id="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>" value="1" <?php checked($is_debug_mode_enabled); ?>>
                        <p class="description"><?php esc_html_e("Active la journalisation détaillée et le tableau de bord de débogage. À n'utiliser que pour le dépannage.", 'sitepulse'); ?></p>
                    </td>
                </tr>
            </table>
            <h2><?php esc_html_e('Alertes', 'sitepulse'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>"><?php esc_html_e('Destinataires des alertes', 'sitepulse'); ?></label></th>
                    <td>
                        <?php
                        $alert_recipients = (array) get_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, []);
                        $recipients_value = implode("\n", $alert_recipients);
                        ?>
                        <textarea id="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>" rows="4" cols="50" class="large-text code"><?php echo esc_textarea($recipients_value); ?></textarea>
                        <p class="description"><?php esc_html_e("Entrez une adresse par ligne (ou séparées par des virgules). L'adresse e-mail de l'administrateur sera toujours incluse si elle est valide.", 'sitepulse'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>"><?php esc_html_e("Seuil d'alerte de charge CPU", 'sitepulse'); ?></label></th>
                    <td>
                        <input type="number" step="0.1" min="0" id="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>" value="<?php echo esc_attr(get_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 5)); ?>" class="small-text">
                        <p class="description"><?php esc_html_e('Une alerte e-mail est envoyée lorsque la charge moyenne sur 1 minute dépasse ce seuil.', 'sitepulse'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES); ?>"><?php esc_html_e('Fenêtre anti-spam (minutes)', 'sitepulse'); ?></label></th>
                    <td>
                        <input type="number" min="1" id="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES); ?>" value="<?php echo esc_attr(get_option(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES, 60)); ?>" class="small-text">
                        <p class="description"><?php esc_html_e("Empêche l'envoi de plusieurs e-mails identiques pendant la durée spécifiée.", 'sitepulse'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_INTERVAL); ?>"><?php esc_html_e('Fréquence des vérifications', 'sitepulse'); ?></label></th>
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
                        <p class="description"><?php esc_html_e('Détermine la fréquence des vérifications automatisées pour les alertes.', 'sitepulse'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(esc_html__('Enregistrer les modifications', 'sitepulse')); ?>
        </form>
        <hr>
        <h2><?php esc_html_e('Export / Import des réglages', 'sitepulse'); ?></h2>
        <p><?php esc_html_e('Sauvegardez votre configuration ou importez-la depuis un autre site.', 'sitepulse'); ?></p>
        <div class="sitepulse-settings-tools">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sitepulse-settings-tool">
                <?php wp_nonce_field('sitepulse_export_settings'); ?>
                <input type="hidden" name="action" value="sitepulse_export_settings">
                <?php submit_button(esc_html__('Exporter les réglages', 'sitepulse'), 'secondary', 'sitepulse-export-settings', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="sitepulse-settings-tool">
                <?php wp_nonce_field('sitepulse_import_settings'); ?>
                <input type="hidden" name="action" value="sitepulse_import_settings">
                <label for="sitepulse_settings_file" class="screen-reader-text"><?php esc_html_e('Fichier de réglages SitePulse', 'sitepulse'); ?></label>
                <input type="file" id="sitepulse_settings_file" name="sitepulse_settings_file" accept="application/json,.json" required>
                <?php submit_button(esc_html__('Importer les réglages', 'sitepulse'), 'secondary', 'sitepulse-import-settings', false); ?>
            </form>
        </div>
        <hr>
        <h2><?php esc_html_e('Nettoyage & Réinitialisation', 'sitepulse'); ?></h2>
        <p><?php esc_html_e('Gérez les données du plugin.', 'sitepulse'); ?></p>
        <form method="post" action="">
            <?php wp_nonce_field(SITEPULSE_NONCE_ACTION_CLEANUP, SITEPULSE_NONCE_FIELD_CLEANUP); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label><?php esc_html_e('Vider le journal de debug', 'sitepulse'); ?></label></th>
                    <td><input type="submit" name="sitepulse_clear_log" value="<?php echo esc_attr__('Vider le journal', 'sitepulse'); ?>" class="button"><p class="description"><?php esc_html_e('Supprime le contenu du fichier de log de débogage.', 'sitepulse'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e('Vider les données stockées', 'sitepulse'); ?></label></th>
                    <td><input type="submit" name="sitepulse_clear_data" value="<?php echo esc_attr__('Vider les données', 'sitepulse'); ?>" class="button"><p class="description"><?php esc_html_e('Supprime les données stockées comme les journaux de disponibilité et les résultats de scan.', 'sitepulse'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e('Réinitialiser le plugin', 'sitepulse'); ?></label></th>
                    <td>
                        <input type="submit" name="sitepulse_reset_all" value="<?php echo esc_attr__('Tout réinitialiser', 'sitepulse'); ?>" class="button button-danger" onclick="return confirm('<?php echo esc_js(__('Êtes-vous sûr ?', 'sitepulse')); ?>');">
                        <p class="description"><?php esc_html_e("Réinitialise SitePulse à son état d'installation initial.", 'sitepulse'); ?></p>
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
                            <?php
                            $active_modules_option = get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);
                            $active_modules = array_values(array_filter(array_map('strval', (array) $active_modules_option), static function ($module) {
                                return $module !== '';
                            }));
                            $active_modules_list = implode(', ', $active_modules);
                            ?>
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
                                                $next_run = wp_date('Y-m-d H:i:s', $timestamp);
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
