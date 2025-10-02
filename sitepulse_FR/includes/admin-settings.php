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
 * Returns the capability required to manage SitePulse settings.
 *
 * @return string Filterable capability name.
 */
function sitepulse_get_capability() {
    $default_capability = 'manage_options';

    if (function_exists('apply_filters')) {
        $filtered_capability = apply_filters('sitepulse_required_capability', $default_capability);

        if (is_string($filtered_capability) && $filtered_capability !== '') {
            return $filtered_capability;
        }
    }

    return $default_capability;
}

/**
 * Wrapper for the main SitePulse dashboard page.
 *
 * Ensures that the menu callback registered via {@see add_menu_page()} is always
 * available, even when the Custom Dashboards module is disabled. When the module
 * is active the actual module output is rendered, otherwise an informative
 * notice is displayed with guidance on how to enable the feature.
 */
function sitepulse_render_dashboard_page() {
    if (!current_user_can(sitepulse_get_capability())) {
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
            /* translators: %s is the URL to the SitePulse settings page. */
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
        __('SitePulse Dashboard', 'sitepulse'),
        __('Sitepulse - JLG', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-dashboard',
        'sitepulse_render_dashboard_page',
        'dashicons-chart-area',
        30
    );

    add_submenu_page(
        'sitepulse-dashboard',
        __('SitePulse Settings', 'sitepulse'),
        __('Settings', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-settings',
        'sitepulse_settings_page'
    );

    if (defined('SITEPULSE_DEBUG') && SITEPULSE_DEBUG) {
        add_submenu_page(
            'sitepulse-dashboard',
            __('SitePulse Debug', 'sitepulse'),
            __('Debug', 'sitepulse'),
            sitepulse_get_capability(),
            'sitepulse-debug',
            'sitepulse_debug_page'
        );
    }
}
add_action('admin_menu', 'sitepulse_admin_menu');

/**
 * Registers the assets used on the SitePulse settings screen.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_admin_settings_enqueue_assets($hook_suffix) {
    $allowed_hooks = [
        'toplevel_page_sitepulse-settings',
        'sitepulse-dashboard_page_sitepulse-settings',
    ];

    if (!in_array($hook_suffix, $allowed_hooks, true)) {
        return;
    }

    $style_handle = 'sitepulse-admin-settings';
    $style_src    = SITEPULSE_URL . 'modules/css/admin-settings.css';
    $style_deps   = [];
    $style_ver    = defined('SITEPULSE_VERSION') ? SITEPULSE_VERSION : false;

    if (!wp_style_is($style_handle, 'registered')) {
        wp_register_style($style_handle, $style_src, $style_deps, $style_ver);
    }

    wp_enqueue_style($style_handle);
}
add_action('admin_enqueue_scripts', 'sitepulse_admin_settings_enqueue_assets');

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
        'type' => 'string', 'sanitize_callback' => 'sitepulse_sanitize_gemini_api_key', 'default' => ''
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_AI_MODEL, [
        'type' => 'string', 'sanitize_callback' => 'sitepulse_sanitize_ai_model', 'default' => sitepulse_get_default_ai_model()
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_AI_RATE_LIMIT, [
        'type' => 'string', 'sanitize_callback' => 'sitepulse_sanitize_ai_rate_limit', 'default' => sitepulse_get_default_ai_rate_limit()
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
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_URL, [
        'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_TIMEOUT, [
        'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_uptime_timeout', 'default' => SITEPULSE_DEFAULT_UPTIME_TIMEOUT
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_SPEED_WARNING_MS, [
        'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_speed_warning_threshold', 'default' => SITEPULSE_DEFAULT_SPEED_WARNING_MS
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_SPEED_CRITICAL_MS, [
        'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_speed_critical_threshold', 'default' => SITEPULSE_DEFAULT_SPEED_CRITICAL_MS
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_IMPACT_THRESHOLDS, [
        'type'              => 'array',
        'sanitize_callback' => 'sitepulse_sanitize_impact_thresholds',
        'default'           => [
            'default' => sitepulse_get_default_plugin_impact_thresholds(),
            'roles'   => [],
        ],
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_WARNING_PERCENT, [
        'type' => 'number', 'sanitize_callback' => 'sitepulse_sanitize_uptime_warning_percent', 'default' => SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_REVISION_LIMIT, [
        'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_revision_limit', 'default' => SITEPULSE_DEFAULT_REVISION_LIMIT
    ]);
}
add_action('admin_init', 'sitepulse_register_settings');

/**
 * Sanitizes the Gemini API key option.
 *
 * The existing key is preserved when the field is submitted empty so that the
 * user is not forced to re-enter their credentials when saving other settings.
 * The key can be explicitly deleted through the dedicated checkbox rendered in
 * the settings form.
 *
 * @param mixed $value Raw user input value.
 * @return string Sanitized API key or an empty string when deletion is requested.
 */
function sitepulse_sanitize_gemini_api_key($value) {
    $current_value = (string) get_option(SITEPULSE_OPTION_GEMINI_API_KEY, '');

    if (function_exists('sitepulse_is_gemini_api_key_overridden') && sitepulse_is_gemini_api_key_overridden()) {
        return $current_value;
    }

    $should_delete = !empty($_POST['sitepulse_delete_gemini_api_key']);
    if ($should_delete) {
        return '';
    }

    if (!is_string($value)) {
        return $current_value;
    }

    $value = trim($value);
    if ($value === '') {
        return $current_value;
    }

    $sanitized = sanitize_text_field($value);

    return $sanitized !== '' ? $sanitized : $current_value;
}

/**
 * Sanitizes the selected AI model.
 *
 * @param mixed $value Raw user input value.
 * @return string Validated AI model identifier.
 */
function sitepulse_sanitize_ai_model($value) {
    $default_model = sitepulse_get_default_ai_model();

    if (!is_string($value)) {
        return $default_model;
    }

    $value = trim($value);

    if ($value === '') {
        return $default_model;
    }

    $available_models = sitepulse_get_ai_models();

    if (!isset($available_models[$value])) {
        return $default_model;
    }

    return $value;
}

/**
 * Returns the available AI rate limit choices.
 *
 * @return array<string,string>
 */
function sitepulse_get_ai_rate_limit_choices() {
    return [
        'day'        => __('Une fois par jour', 'sitepulse'),
        'week'       => __('Une fois par semaine', 'sitepulse'),
        'month'      => __('Une fois par mois', 'sitepulse'),
        'unlimited'  => __('Illimité', 'sitepulse'),
    ];
}

/**
 * Returns the default AI rate limit option key.
 *
 * @return string
 */
function sitepulse_get_default_ai_rate_limit() {
    return 'week';
}

/**
 * Sanitizes the AI rate limit option value.
 *
 * @param mixed $value Raw user input value.
 * @return string Validated option key.
 */
function sitepulse_sanitize_ai_rate_limit($value) {
    $default = sitepulse_get_default_ai_rate_limit();

    if (!is_string($value)) {
        return $default;
    }

    $value = strtolower(trim($value));
    $choices = sitepulse_get_ai_rate_limit_choices();

    if (!isset($choices[$value])) {
        return $default;
    }

    return $value;
}

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

/**
 * Sanitizes the timeout (in seconds) used for uptime checks.
 *
 * @param mixed $value Raw user input value.
 * @return int Validated timeout value.
 */
function sitepulse_sanitize_uptime_timeout($value) {
    $default = defined('SITEPULSE_DEFAULT_UPTIME_TIMEOUT') ? (int) SITEPULSE_DEFAULT_UPTIME_TIMEOUT : 10;

    if (!is_scalar($value)) {
        return $default;
    }

    $value = (int) $value;

    if ($value < 1) {
        return $default;
    }

    return $value;
}

/**
 * Sanitizes the configured warning threshold for speed (in milliseconds).
 *
 * @param mixed $value Raw user input value.
 * @return int
 */
function sitepulse_sanitize_speed_warning_threshold($value) {
    $default = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;

    if (!is_scalar($value)) {
        return $default;
    }

    $sanitized = absint($value);

    if ($sanitized < 1) {
        return $default;
    }

    return $sanitized;
}

/**
 * Sanitizes the configured critical threshold for speed (in milliseconds).
 *
 * @param mixed $value Raw user input value.
 * @return int
 */
function sitepulse_sanitize_speed_critical_threshold($value) {
    $default = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;
    $minimum_warning = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;

    if (!is_scalar($value)) {
        $value = $default;
    }

    $sanitized = absint($value);

    if ($sanitized < 1) {
        $sanitized = $default;
    }

    $warning_value = $minimum_warning;
    $warning_field_key = defined('SITEPULSE_OPTION_SPEED_WARNING_MS') ? SITEPULSE_OPTION_SPEED_WARNING_MS : 'sitepulse_speed_warning_ms';

    if (isset($_POST[$warning_field_key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $warning_value = sitepulse_sanitize_speed_warning_threshold($_POST[$warning_field_key]); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    } else {
        $stored_warning = get_option($warning_field_key, $minimum_warning);
        if (is_scalar($stored_warning)) {
            $warning_value = max($minimum_warning, absint($stored_warning));
        }
    }

    if ($sanitized <= $warning_value) {
        $sanitized = max($warning_value + 1, $default);
    }

    return $sanitized;
}

/**
 * Returns the default thresholds used for plugin impact highlighting.
 *
 * @return array<string,float>
 */
function sitepulse_get_default_plugin_impact_thresholds() {
    return [
        'impactWarning'  => 30.0,
        'impactCritical' => 60.0,
        'weightWarning'  => 10.0,
        'weightCritical' => 20.0,
    ];
}

/**
 * Normalizes a single set of impact thresholds.
 *
 * @param mixed $thresholds Raw user input.
 * @param array $fallback   Fallback values when entries are missing.
 *
 * @return array<string,float>
 */
function sitepulse_normalize_impact_threshold_set($thresholds, $fallback = []) {
    $defaults = sitepulse_get_default_plugin_impact_thresholds();
    $fallback = wp_parse_args(is_array($fallback) ? $fallback : [], $defaults);
    $thresholds = is_array($thresholds) ? $thresholds : [];

    $impact_warning = sitepulse_sanitize_impact_threshold_number(
        array_key_exists('impactWarning', $thresholds) ? $thresholds['impactWarning'] : $fallback['impactWarning'],
        $fallback['impactWarning'],
        $defaults['impactWarning']
    );

    $impact_critical = sitepulse_sanitize_impact_threshold_number(
        array_key_exists('impactCritical', $thresholds) ? $thresholds['impactCritical'] : $fallback['impactCritical'],
        $fallback['impactCritical'],
        $defaults['impactCritical']
    );

    if ($impact_critical <= $impact_warning) {
        $impact_critical = max($impact_warning + 0.1, $fallback['impactCritical'], $defaults['impactCritical']);
        $impact_critical = round($impact_critical, 2);
    }

    $weight_warning = sitepulse_sanitize_impact_threshold_number(
        array_key_exists('weightWarning', $thresholds) ? $thresholds['weightWarning'] : $fallback['weightWarning'],
        $fallback['weightWarning'],
        $defaults['weightWarning']
    );

    $weight_critical = sitepulse_sanitize_impact_threshold_number(
        array_key_exists('weightCritical', $thresholds) ? $thresholds['weightCritical'] : $fallback['weightCritical'],
        $fallback['weightCritical'],
        $defaults['weightCritical']
    );

    if ($weight_critical <= $weight_warning) {
        $weight_critical = max($weight_warning + 0.1, $fallback['weightCritical'], $defaults['weightCritical']);
        $weight_critical = round($weight_critical, 2);
    }

    $normalized = [
        'impactWarning'  => (float) min(max($impact_warning, 0.0), 100.0),
        'impactCritical' => (float) min(max($impact_critical, 0.0), 100.0),
        'weightWarning'  => (float) min(max($weight_warning, 0.0), 100.0),
        'weightCritical' => (float) min(max($weight_critical, 0.0), 100.0),
    ];

    if ($normalized['impactCritical'] <= $normalized['impactWarning']) {
        $normalized['impactCritical'] = min(100.0, round($normalized['impactWarning'] + 0.1, 2));
    }

    if ($normalized['weightCritical'] <= $normalized['weightWarning']) {
        $normalized['weightCritical'] = min(100.0, round($normalized['weightWarning'] + 0.1, 2));
    }

    return $normalized;
}

/**
 * Sanitizes an individual impact threshold value.
 *
 * @param mixed $value    Raw input.
 * @param float $fallback Fallback value when input is invalid.
 * @param float $default  Hard default value.
 * @param float $minimum  Minimum accepted value.
 * @param float $maximum  Maximum accepted value.
 *
 * @return float
 */
function sitepulse_sanitize_impact_threshold_number($value, $fallback, $default, $minimum = 0.0, $maximum = 100.0) {
    if (!is_scalar($value) || $value === '') {
        $value = $fallback;
    }

    if (!is_scalar($value) || $value === '') {
        $value = $default;
    }

    $number = (float) $value;

    if (!is_finite($number)) {
        $number = (float) $default;
    }

    if ($number < $minimum) {
        $number = max($minimum, (float) $default, (float) $fallback);
    }

    if ($maximum !== null && $number > $maximum) {
        $number = (float) $maximum;
    }

    return (float) round($number, 2);
}

/**
 * Sanitizes the per-role impact thresholds option.
 *
 * @param mixed $value Raw user input value.
 *
 * @return array<string,mixed>
 */
function sitepulse_sanitize_impact_thresholds($value) {
    $defaults = sitepulse_get_default_plugin_impact_thresholds();
    $value = is_array($value) ? $value : [];

    $sanitized_default = sitepulse_normalize_impact_threshold_set(
        array_key_exists('default', $value) ? $value['default'] : [],
        $defaults
    );

    $sanitized = [
        'default' => $sanitized_default,
        'roles'   => [],
    ];

    if (isset($value['roles']) && is_array($value['roles'])) {
        foreach ($value['roles'] as $role => $thresholds) {
            $role_key = sanitize_key($role);

            if ($role_key === '') {
                continue;
            }

            $role_thresholds = sitepulse_normalize_impact_threshold_set($thresholds, $sanitized_default);

            if ($role_thresholds === $sanitized_default) {
                continue;
            }

            $sanitized['roles'][$role_key] = $role_thresholds;
        }
    }

    return $sanitized;
}

/**
 * Sanitizes the uptime warning threshold percentage.
 *
 * @param mixed $value Raw user input value.
 * @return float
 */
function sitepulse_sanitize_uptime_warning_percent($value) {
    $default = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0;

    if (!is_scalar($value)) {
        return $default;
    }

    $sanitized = (float) $value;

    if ($sanitized <= 0) {
        return $default;
    }

    if ($sanitized > 100) {
        return 100.0;
    }

    return $sanitized;
}

/**
 * Sanitizes the revision limit used in database health checks.
 *
 * @param mixed $value Raw user input value.
 * @return int
 */
function sitepulse_sanitize_revision_limit($value) {
    $default = defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? (int) SITEPULSE_DEFAULT_REVISION_LIMIT : 100;

    if (!is_scalar($value)) {
        return $default;
    }

    $sanitized = absint($value);

    if ($sanitized < 1) {
        return $default;
    }

    return $sanitized;
}

/**
 * Renders the settings page.
 */
function sitepulse_settings_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $modules = [
        'log_analyzer'          => [
            'label'       => __('Log Analyzer', 'sitepulse'),
            'description' => __('Analyse les journaux WordPress et met en évidence les erreurs critiques détectées.', 'sitepulse'),
            'page'        => 'sitepulse-logs',
        ],
        'resource_monitor'      => [
            'label'       => __('Resource Monitor', 'sitepulse'),
            'description' => __('Surveille l’utilisation des ressources serveur pour repérer les pics de charge.', 'sitepulse'),
            'page'        => 'sitepulse-resources',
        ],
        'plugin_impact_scanner' => [
            'label'       => __('Plugin Impact Scanner', 'sitepulse'),
            'description' => __('Évalue l’impact de chaque extension sur les performances et la stabilité.', 'sitepulse'),
            'page'        => 'sitepulse-plugins',
        ],
        'speed_analyzer'        => [
            'label'       => __('Speed Analyzer', 'sitepulse'),
            'description' => __('Mesure la vitesse de chargement pour identifier les ralentissements critiques.', 'sitepulse'),
            'page'        => 'sitepulse-speed',
        ],
        'database_optimizer'    => [
            'label'       => __('Database Optimizer', 'sitepulse'),
            'description' => __('Suggère des actions de nettoyage et d’optimisation de la base de données.', 'sitepulse'),
            'page'        => 'sitepulse-db',
        ],
        'maintenance_advisor'   => [
            'label'       => __('Maintenance Advisor', 'sitepulse'),
            'description' => __('Suit les mises à jour WordPress, extensions et thèmes pour garder le site à jour.', 'sitepulse'),
            'page'        => 'sitepulse-maintenance',
        ],
        'uptime_tracker'        => [
            'label'       => __('Uptime Tracker', 'sitepulse'),
            'description' => __('Vérifie régulièrement la disponibilité du site et alerte en cas d’incident.', 'sitepulse'),
            'page'        => 'sitepulse-uptime',
        ],
        'ai_insights'           => [
            'label'       => __('AI-Powered Insights', 'sitepulse'),
            'description' => __('Génère automatiquement des recommandations basées sur l’intelligence artificielle.', 'sitepulse'),
            'page'        => 'sitepulse-ai',
        ],
        'custom_dashboards'     => [
            'label'       => __('Custom Dashboards', 'sitepulse'),
            'description' => __('Propose une vue d’ensemble personnalisable de vos indicateurs clés.', 'sitepulse'),
            'page'        => 'sitepulse-dashboard',
        ],
        'error_alerts'          => [
            'label'       => __('Error Alerts', 'sitepulse'),
            'description' => __('Surveille les erreurs critiques et déclenche des notifications ciblées.', 'sitepulse'),
            'page'        => '#sitepulse-section-alerts',
        ],
    ];
    $stored_gemini_api_key = (string) get_option(SITEPULSE_OPTION_GEMINI_API_KEY, '');
    $has_stored_gemini_api_key = $stored_gemini_api_key !== '';
    $effective_gemini_api_key = function_exists('sitepulse_get_gemini_api_key') ? sitepulse_get_gemini_api_key() : $stored_gemini_api_key;
    $has_effective_gemini_api_key = $effective_gemini_api_key !== '';
    $is_gemini_api_key_constant = defined('SITEPULSE_GEMINI_API_KEY') && trim((string) SITEPULSE_GEMINI_API_KEY) !== '';
    $available_ai_models = sitepulse_get_ai_models();
    $default_ai_model = sitepulse_get_default_ai_model();
    $selected_ai_model = (string) get_option(SITEPULSE_OPTION_AI_MODEL, $default_ai_model);

    if (!isset($available_ai_models[$selected_ai_model])) {
        $selected_ai_model = $default_ai_model;
    }
    $ai_rate_limit_choices = sitepulse_get_ai_rate_limit_choices();
    $default_ai_rate_limit = sitepulse_get_default_ai_rate_limit();
    $selected_ai_rate_limit = (string) get_option(SITEPULSE_OPTION_AI_RATE_LIMIT, $default_ai_rate_limit);

    if (!isset($ai_rate_limit_choices[$selected_ai_rate_limit])) {
        $selected_ai_rate_limit = $default_ai_rate_limit;
    }
    $active_modules = get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);
    $debug_mode_option = get_option(SITEPULSE_OPTION_DEBUG_MODE);
    $is_debug_mode_enabled = rest_sanitize_boolean($debug_mode_option);
    $uptime_url_option = get_option(SITEPULSE_OPTION_UPTIME_URL, '');
    $uptime_url = '';

    if (is_string($uptime_url_option)) {
        $uptime_url = trim($uptime_url_option);
    }

    $uptime_timeout_option = get_option(SITEPULSE_OPTION_UPTIME_TIMEOUT, SITEPULSE_DEFAULT_UPTIME_TIMEOUT);
    $uptime_timeout = sitepulse_sanitize_uptime_timeout($uptime_timeout_option);

    $default_speed_thresholds = [
        'warning'  => defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200,
        'critical' => defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500,
    ];

    $speed_thresholds = function_exists('sitepulse_get_speed_thresholds')
        ? sitepulse_get_speed_thresholds()
        : $default_speed_thresholds;

    $speed_warning_threshold = isset($speed_thresholds['warning']) ? (int) $speed_thresholds['warning'] : $default_speed_thresholds['warning'];
    $speed_critical_threshold = isset($speed_thresholds['critical']) ? (int) $speed_thresholds['critical'] : $default_speed_thresholds['critical'];

    if ($speed_warning_threshold < 1) {
        $speed_warning_threshold = $default_speed_thresholds['warning'];
    }

    if ($speed_critical_threshold <= $speed_warning_threshold) {
        $speed_critical_threshold = max($speed_warning_threshold + 1, $default_speed_thresholds['critical']);
    }

    $default_plugin_impact_thresholds = sitepulse_get_default_plugin_impact_thresholds();
    $stored_plugin_impact_thresholds = get_option(
        SITEPULSE_OPTION_IMPACT_THRESHOLDS,
        [
            'default' => $default_plugin_impact_thresholds,
            'roles'   => [],
        ]
    );
    $plugin_impact_threshold_settings = sitepulse_sanitize_impact_thresholds($stored_plugin_impact_thresholds);
    $plugin_impact_default_thresholds = isset($plugin_impact_threshold_settings['default'])
        ? $plugin_impact_threshold_settings['default']
        : $default_plugin_impact_thresholds;
    $plugin_impact_role_thresholds = isset($plugin_impact_threshold_settings['roles']) && is_array($plugin_impact_threshold_settings['roles'])
        ? $plugin_impact_threshold_settings['roles']
        : [];

    $wp_roles = function_exists('wp_roles') ? wp_roles() : null;
    $available_roles = [];

    if ($wp_roles instanceof WP_Roles) {
        $available_roles = $wp_roles->roles;
    } elseif (function_exists('get_editable_roles')) {
        $available_roles = get_editable_roles();
    }

    $plugin_impact_threshold_rows = [
        [
            'key'        => 'default',
            'label'      => esc_html__('Administrateur (valeurs par défaut)', 'sitepulse'),
            'thresholds' => $plugin_impact_default_thresholds,
            'is_default' => true,
        ],
    ];

    foreach ($available_roles as $role_key => $role_data) {
        $role_key = sanitize_key($role_key);

        if ($role_key === '' || $role_key === 'administrator') {
            continue;
        }

        $role_label = isset($role_data['name']) ? translate_user_role($role_data['name']) : $role_key;

        $plugin_impact_threshold_rows[] = [
            'key'        => $role_key,
            'label'      => $role_label,
            'thresholds' => isset($plugin_impact_role_thresholds[$role_key]) ? $plugin_impact_role_thresholds[$role_key] : $plugin_impact_default_thresholds,
            'is_default' => false,
        ];
    }

    $uptime_warning_percent = function_exists('sitepulse_get_uptime_warning_percentage')
        ? (float) sitepulse_get_uptime_warning_percentage()
        : (float) (defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0);

    if ($uptime_warning_percent < 0) {
        $uptime_warning_percent = 0.0;
    } elseif ($uptime_warning_percent > 100) {
        $uptime_warning_percent = 100.0;
    }

    $revision_limit = function_exists('sitepulse_get_revision_limit')
        ? (int) sitepulse_get_revision_limit()
        : (int) (defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? SITEPULSE_DEFAULT_REVISION_LIMIT : 100);

    if ($revision_limit < 1) {
        $revision_limit = (int) (defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? SITEPULSE_DEFAULT_REVISION_LIMIT : 100);
    }

    if (isset($_POST[SITEPULSE_NONCE_FIELD_CLEANUP]) && wp_verify_nonce($_POST[SITEPULSE_NONCE_FIELD_CLEANUP], SITEPULSE_NONCE_ACTION_CLEANUP)) {
        $filesystem = sitepulse_get_filesystem();
        $log_exists = defined('SITEPULSE_DEBUG_LOG') && (
            file_exists(SITEPULSE_DEBUG_LOG) ||
            ($filesystem instanceof WP_Filesystem_Base && $filesystem->exists(SITEPULSE_DEBUG_LOG))
        );

        if (isset($_POST['sitepulse_clear_log']) && $log_exists) {
            $cleared = false;

            if ($filesystem instanceof WP_Filesystem_Base) {
                $cleared = $filesystem->put_contents(
                    SITEPULSE_DEBUG_LOG,
                    '',
                    defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : false
                );
            }

            if (!$cleared) {
                $cleared = @file_put_contents(SITEPULSE_DEBUG_LOG, '');
            }

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
                SITEPULSE_OPTION_UPTIME_URL,
                SITEPULSE_OPTION_UPTIME_TIMEOUT,
                SITEPULSE_OPTION_LAST_LOAD_TIME,
                SITEPULSE_OPTION_CPU_ALERT_THRESHOLD,
                SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES,
                SITEPULSE_OPTION_ALERT_INTERVAL,
                // Clear stored alert recipients so the default (empty) list is restored on activation.
                SITEPULSE_OPTION_ALERT_RECIPIENTS,
                SITEPULSE_OPTION_SPEED_WARNING_MS,
                SITEPULSE_OPTION_SPEED_CRITICAL_MS,
                SITEPULSE_OPTION_IMPACT_THRESHOLDS,
                SITEPULSE_OPTION_UPTIME_WARNING_PERCENT,
                SITEPULSE_OPTION_REVISION_LIMIT,
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
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('SitePulse a été réinitialisé.', 'sitepulse') . '</p></div>';
            } elseif ($log_deletion_failed) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Impossible de supprimer le journal de débogage. Vérifiez les permissions du fichier.', 'sitepulse') . '</p></div>';
            }
        }
    }
    ?>
    <div class="wrap sitepulse-settings-wrap">
        <h1><?php esc_html_e('Réglages de SitePulse', 'sitepulse'); ?></h1>
        <p class="sitepulse-settings-intro"><?php esc_html_e('Activez les modules qui vous intéressent et ajustez les seuils clés pour votre surveillance.', 'sitepulse'); ?></p>
        <form method="post" action="options.php" class="sitepulse-settings-form">
            <?php settings_fields('sitepulse_settings'); do_settings_sections('sitepulse_settings'); ?>
            <div class="sitepulse-settings-section" id="sitepulse-section-api">
                <h2><?php esc_html_e("Paramètres de l'API", 'sitepulse'); ?></h2>
                <div class="sitepulse-settings-grid">
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Connexion à Google Gemini', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_GEMINI_API_KEY); ?>"><?php esc_html_e('Clé API Google Gemini', 'sitepulse'); ?></label>
                            <input type="password" id="<?php echo esc_attr(SITEPULSE_OPTION_GEMINI_API_KEY); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_GEMINI_API_KEY); ?>" value="" class="regular-text" autocomplete="new-password"<?php echo $has_effective_gemini_api_key && !$is_gemini_api_key_constant ? ' placeholder="' . esc_attr__('Une clé API est enregistrée', 'sitepulse') . '"' : ''; ?> <?php disabled($is_gemini_api_key_constant); ?> />
                            <?php if ($is_gemini_api_key_constant): ?>
                                <p class="sitepulse-card-description"><?php esc_html_e('Cette clé est définie dans wp-config.php via la constante SITEPULSE_GEMINI_API_KEY. Mettez à jour ce fichier pour la modifier.', 'sitepulse'); ?></p>
                            <?php elseif ($has_effective_gemini_api_key): ?>
                                <p class="sitepulse-card-description"><?php esc_html_e('Une clé API est déjà enregistrée. Laissez le champ vide pour la conserver ou cochez la case ci-dessous pour l’effacer.', 'sitepulse'); ?></p>
                                <?php if ($has_stored_gemini_api_key): ?>
                                    <label class="sitepulse-card-checkbox" for="sitepulse_delete_gemini_api_key">
                                        <input type="checkbox" id="sitepulse_delete_gemini_api_key" name="sitepulse_delete_gemini_api_key" value="1">
                                        <span><?php esc_html_e('Effacer la clé API enregistrée', 'sitepulse'); ?></span>
                                    </label>
                                <?php endif; ?>
                            <?php endif; ?>
                            <p class="sitepulse-card-description"><?php printf(
                                wp_kses(
                                    /* translators: %s: URL to Google AI Studio. */
                                    __('Entrez votre clé API pour activer les analyses par IA. Obtenez une clé sur <a href="%s" target="_blank">Google AI Studio</a>.', 'sitepulse'),
                                    ['a' => ['href' => true, 'target' => true]]
                                ),
                                esc_url('https://aistudio.google.com/app/apikey')
                            ); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sitepulse-settings-section" id="sitepulse-section-ai">
                <h2><?php esc_html_e('IA', 'sitepulse'); ?></h2>
                <div class="sitepulse-settings-grid">
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Modèle IA', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_AI_MODEL); ?>"><?php esc_html_e('Modèle IA', 'sitepulse'); ?></label>
                            <select id="<?php echo esc_attr(SITEPULSE_OPTION_AI_MODEL); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_AI_MODEL); ?>">
                                <?php foreach ($available_ai_models as $model_key => $model_data) : ?>
                                    <option value="<?php echo esc_attr($model_key); ?>" <?php selected($selected_ai_model, $model_key); ?>><?php echo esc_html(isset($model_data['label']) ? $model_data['label'] : $model_key); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="sitepulse-card-description"><?php esc_html_e("Choisissez le modèle utilisé pour générer les recommandations. Les modèles diffèrent en termes de profondeur d'analyse, de coût et de temps de réponse.", 'sitepulse'); ?></p>
                            <?php if (!empty($available_ai_models)) : ?>
                                <ul class="sitepulse-card-list">
                                    <?php foreach ($available_ai_models as $model_key => $model_data) :
                                        $label = isset($model_data['label']) ? $model_data['label'] : $model_key;
                                        $description = isset($model_data['description']) ? $model_data['description'] : '';
                                    ?>
                                        <li>
                                            <strong><?php echo esc_html($label); ?></strong>
                                            <?php if ($selected_ai_model === $model_key) : ?>
                                                <span class="sitepulse-card-badge"><?php esc_html_e('Modèle actuel', 'sitepulse'); ?></span>
                                            <?php endif; ?>
                                            <?php if ($description !== '') : ?>
                                                <span class="sitepulse-card-note"><?php echo esc_html($description); ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Fréquence des analyses IA', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_AI_RATE_LIMIT); ?>"><?php esc_html_e('Fréquence maximale des analyses IA', 'sitepulse'); ?></label>
                            <select id="<?php echo esc_attr(SITEPULSE_OPTION_AI_RATE_LIMIT); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_AI_RATE_LIMIT); ?>">
                                <?php foreach ($ai_rate_limit_choices as $option_value => $option_label) : ?>
                                    <option value="<?php echo esc_attr($option_value); ?>" <?php selected($selected_ai_rate_limit, $option_value); ?>><?php echo esc_html($option_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="sitepulse-card-description"><?php esc_html_e('Définissez la fréquence maximale des nouvelles recommandations générées automatiquement.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sitepulse-settings-section" id="sitepulse-section-performance">
                <h2><?php esc_html_e('Seuils de performance', 'sitepulse'); ?></h2>
                <div class="sitepulse-settings-grid">
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Seuil d’avertissement (ms)', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $speed_warning_description_id = 'sitepulse-speed-warning-description'; ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_SPEED_WARNING_MS); ?>"><?php esc_html_e('Valeur d’avertissement', 'sitepulse'); ?></label>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                id="<?php echo esc_attr(SITEPULSE_OPTION_SPEED_WARNING_MS); ?>"
                                name="<?php echo esc_attr(SITEPULSE_OPTION_SPEED_WARNING_MS); ?>"
                                value="<?php echo esc_attr($speed_warning_threshold); ?>"
                                class="small-text"
                                aria-describedby="<?php echo esc_attr($speed_warning_description_id); ?>"
                            >
                            <p class="sitepulse-card-description" id="<?php echo esc_attr($speed_warning_description_id); ?>"><?php printf(
                                esc_html__('Temps de traitement au-delà duquel un statut « attention » est affiché pour la vitesse. Valeur par défaut : %d ms.', 'sitepulse'),
                                (int) (defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200)
                            ); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Seuil critique (ms)', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $speed_critical_description_id = 'sitepulse-speed-critical-description'; ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_SPEED_CRITICAL_MS); ?>"><?php esc_html_e('Valeur critique', 'sitepulse'); ?></label>
                            <input
                                type="number"
                                min="<?php echo esc_attr($speed_warning_threshold + 1); ?>"
                                step="1"
                                id="<?php echo esc_attr(SITEPULSE_OPTION_SPEED_CRITICAL_MS); ?>"
                                name="<?php echo esc_attr(SITEPULSE_OPTION_SPEED_CRITICAL_MS); ?>"
                                value="<?php echo esc_attr($speed_critical_threshold); ?>"
                                class="small-text"
                                aria-describedby="<?php echo esc_attr($speed_critical_description_id); ?>"
                            >
                            <p class="sitepulse-card-description" id="<?php echo esc_attr($speed_critical_description_id); ?>"><?php printf(
                                esc_html__('Temps de traitement à partir duquel les cartes passent en statut critique. Valeur par défaut : %d ms.', 'sitepulse'),
                                (int) (defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500)
                            ); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Seuils d’impact des plugins', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description"><?php esc_html_e('Définissez les seuils d’avertissement et critiques utilisés pour mettre en évidence les plugins selon le rôle de l’utilisateur connecté.', 'sitepulse'); ?></p>
                            <?php
                            $impact_threshold_field_definitions = [
                                'impactWarning' => [
                                    'label'       => __('Impact – avertissement (%)', 'sitepulse'),
                                    'description' => __('Pourcentage d’impact à partir duquel un plugin passe en statut « attention ».', 'sitepulse'),
                                ],
                                'impactCritical' => [
                                    'label'       => __('Impact – critique (%)', 'sitepulse'),
                                    'description' => __('Pourcentage d’impact à partir duquel un plugin est marqué critique.', 'sitepulse'),
                                ],
                                'weightWarning' => [
                                    'label'       => __('Poids – avertissement (%)', 'sitepulse'),
                                    'description' => __('Part du poids total à partir de laquelle un plugin est signalé en attention.', 'sitepulse'),
                                ],
                                'weightCritical' => [
                                    'label'       => __('Poids – critique (%)', 'sitepulse'),
                                    'description' => __('Part du poids total à partir de laquelle un plugin est signalé critique.', 'sitepulse'),
                                ],
                            ];
                            ?>
                            <?php foreach ($plugin_impact_threshold_rows as $threshold_row) :
                                $scope_key = $threshold_row['key'];
                                $thresholds = $threshold_row['thresholds'];
                                $fieldset_id = 'sitepulse-impact-thresholds-' . $scope_key;
                            ?>
                                <fieldset class="sitepulse-impact-threshold-group" id="<?php echo esc_attr($fieldset_id); ?>">
                                    <legend><?php echo esc_html($threshold_row['label']); ?></legend>
                                    <?php if (!empty($threshold_row['is_default'])) : ?>
                                        <p class="sitepulse-card-note"><?php esc_html_e('Ces valeurs servent de référence et s’appliquent à tous les rôles ne disposant pas d’override.', 'sitepulse'); ?></p>
                                    <?php else : ?>
                                        <p class="sitepulse-card-note"><?php esc_html_e('Modifiez ces valeurs pour personnaliser les surlignages pour ce rôle. Si elles correspondent aux valeurs par défaut, aucun override n’est conservé.', 'sitepulse'); ?></p>
                                    <?php endif; ?>
                                    <?php foreach ($impact_threshold_field_definitions as $field_key => $field_definition) :
                                        $field_name = SITEPULSE_OPTION_IMPACT_THRESHOLDS . ($scope_key === 'default' ? '[default]' : '[roles][' . $scope_key . ']') . '[' . $field_key . ']';
                                        $input_id = SITEPULSE_OPTION_IMPACT_THRESHOLDS . '-' . $scope_key . '-' . $field_key;
                                        $slider_id = $input_id . '-slider';
                                        $value = isset($thresholds[$field_key]) ? $thresholds[$field_key] : $plugin_impact_default_thresholds[$field_key];
                                    ?>
                                        <div class="sitepulse-impact-threshold-field">
                                            <label class="sitepulse-field-label" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($field_definition['label']); ?></label>
                                            <div class="sitepulse-impact-threshold-inputs">
                                                <input
                                                    type="range"
                                                    class="sitepulse-impact-threshold-slider"
                                                    id="<?php echo esc_attr($slider_id); ?>"
                                                    min="0"
                                                    max="100"
                                                    step="0.1"
                                                    value="<?php echo esc_attr($value); ?>"
                                                    oninput="document.getElementById('<?php echo esc_js($input_id); ?>').value = this.value;"
                                                >
                                                <input
                                                    type="number"
                                                    class="small-text"
                                                    id="<?php echo esc_attr($input_id); ?>"
                                                    name="<?php echo esc_attr($field_name); ?>"
                                                    min="0"
                                                    max="100"
                                                    step="0.1"
                                                    value="<?php echo esc_attr($value); ?>"
                                                    oninput="document.getElementById('<?php echo esc_js($slider_id); ?>').value = this.value;"
                                                >
                                            </div>
                                            <?php if (!empty($field_definition['description'])) : ?>
                                                <p class="sitepulse-card-description"><?php echo esc_html($field_definition['description']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Seuil de disponibilité (%)', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $uptime_warning_description_id = 'sitepulse-uptime-warning-description'; ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_WARNING_PERCENT); ?>"><?php esc_html_e('Pourcentage minimal', 'sitepulse'); ?></label>
                            <input
                                type="number"
                                min="0"
                                max="100"
                                step="0.1"
                                id="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_WARNING_PERCENT); ?>"
                                name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_WARNING_PERCENT); ?>"
                                value="<?php echo esc_attr($uptime_warning_percent); ?>"
                                class="small-text"
                                aria-describedby="<?php echo esc_attr($uptime_warning_description_id); ?>"
                            >
                            <p class="sitepulse-card-description" id="<?php echo esc_attr($uptime_warning_description_id); ?>"><?php printf(
                                esc_html__('Pourcentage minimal de disponibilité avant de signaler une alerte. Valeur par défaut : %s %%.', 'sitepulse'),
                                number_format_i18n(defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99)
                            ); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Limite de révisions', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $revision_limit_description_id = 'sitepulse-revision-limit-description'; ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_REVISION_LIMIT); ?>"><?php esc_html_e('Nombre recommandé', 'sitepulse'); ?></label>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                id="<?php echo esc_attr(SITEPULSE_OPTION_REVISION_LIMIT); ?>"
                                name="<?php echo esc_attr(SITEPULSE_OPTION_REVISION_LIMIT); ?>"
                                value="<?php echo esc_attr($revision_limit); ?>"
                                class="small-text"
                                aria-describedby="<?php echo esc_attr($revision_limit_description_id); ?>"
                            >
                            <p class="sitepulse-card-description" id="<?php echo esc_attr($revision_limit_description_id); ?>"><?php printf(
                                esc_html__('Nombre maximal de révisions recommandé avant de proposer un nettoyage. Valeur par défaut : %d.', 'sitepulse'),
                                (int) (defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? SITEPULSE_DEFAULT_REVISION_LIMIT : 100)
                            ); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sitepulse-settings-section" id="sitepulse-section-modules">
                <h2><?php esc_html_e('Activer les Modules', 'sitepulse'); ?></h2>
                <p class="sitepulse-section-intro"><?php esc_html_e('Sélectionnez les modules de surveillance à activer.', 'sitepulse'); ?></p>
                <div class="sitepulse-settings-grid">
                    <?php foreach ($modules as $module_key => $module_data) :
                        $module_label = isset($module_data['label']) ? $module_data['label'] : $module_key;
                        $module_description = isset($module_data['description']) ? $module_data['description'] : '';
                        $module_page = isset($module_data['page']) ? $module_data['page'] : '';
                        $module_url = '';

                        if ($module_page !== '') {
                            $module_url = strpos($module_page, '#') === 0 ? $module_page : admin_url('admin.php?page=' . $module_page);
                        }

                        $checkbox_id = 'sitepulse-module-' . $module_key;
                        $description_id = $checkbox_id . '-description';
                        $is_active = in_array($module_key, (array) $active_modules, true);
                        $status_class = $is_active ? 'is-active' : 'is-inactive';
                        $status_label = $is_active ? esc_html__('Activé', 'sitepulse') : esc_html__('Désactivé', 'sitepulse');
                    ?>
                    <div class="sitepulse-module-card" data-module="<?php echo esc_attr($module_key); ?>">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php echo esc_html($module_label); ?></h3>
                            <span class="sitepulse-status <?php echo esc_attr($status_class); ?>"><?php echo $status_label; ?></span>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php if ($module_description !== '') : ?>
                                <p class="sitepulse-card-description" id="<?php echo esc_attr($description_id); ?>"><?php echo esc_html($module_description); ?></p>
                            <?php endif; ?>
                            <div class="sitepulse-card-footer">
                                <label class="sitepulse-toggle" for="<?php echo esc_attr($checkbox_id); ?>">
                                    <input type="checkbox" id="<?php echo esc_attr($checkbox_id); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ACTIVE_MODULES); ?>[]" value="<?php echo esc_attr($module_key); ?>" <?php checked($is_active); ?><?php if ($module_description !== '') : ?> aria-describedby="<?php echo esc_attr($description_id); ?>"<?php endif; ?>>
                                    <span><?php esc_html_e('Activer ce module', 'sitepulse'); ?></span>
                                </label>
                                <?php if ($module_url !== '') : ?>
                                    <a class="sitepulse-card-link" href="<?php echo esc_url($module_url); ?>">
                                        <?php esc_html_e('Ouvrir le module', 'sitepulse'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="sitepulse-module-card sitepulse-module-card--setting" id="sitepulse-debug-card">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Mode Debug', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description"><?php esc_html_e("Active la journalisation détaillée et le tableau de bord de débogage. À n'utiliser que pour le dépannage.", 'sitepulse'); ?></p>
                            <input type="hidden" name="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>" value="0">
                            <div class="sitepulse-card-footer">
                                <label class="sitepulse-toggle" for="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>">
                                    <input type="checkbox" id="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>" value="1" <?php checked($is_debug_mode_enabled); ?>>
                                    <span><?php esc_html_e('Activer le Mode Debug', 'sitepulse'); ?></span>
                                </label>
                            </div>
                            <p class="sitepulse-card-description"><?php printf(esc_html__('Sur Nginx (ou tout serveur qui ignore .htaccess / web.config), déplacez le journal via le filtre %s ou bloquez-le côté serveur.', 'sitepulse'), 'sitepulse_debug_log_base_dir'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sitepulse-settings-section" id="sitepulse-section-alerts">
                <h2><?php esc_html_e('Alertes', 'sitepulse'); ?></h2>
                <div class="sitepulse-settings-grid">
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Destinataires des alertes', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php
                            $alert_recipients = (array) get_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, []);
                            $recipients_value = implode("\n", $alert_recipients);
                            ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>"><?php esc_html_e('Adresses e-mail', 'sitepulse'); ?></label>
                            <textarea id="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>" rows="4" class="large-text code sitepulse-textarea"><?php echo esc_textarea($recipients_value); ?></textarea>
                            <p class="sitepulse-card-description"><?php esc_html_e("Entrez une adresse par ligne (ou séparées par des virgules). L'adresse e-mail de l'administrateur sera toujours incluse si elle est valide.", 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e("Seuil d'alerte de charge CPU", 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>"><?php esc_html_e('Valeur déclenchant une alerte', 'sitepulse'); ?></label>
                            <input type="number" step="0.1" min="0" id="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>" value="<?php echo esc_attr(get_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 5)); ?>" class="small-text">
                            <p class="sitepulse-card-description"><?php esc_html_e('Une alerte e-mail est envoyée lorsque la charge moyenne sur 1 minute dépasse ce seuil multiplié par le nombre de cœurs détectés.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Fenêtre anti-spam (minutes)', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES); ?>"><?php esc_html_e('Durée minimale entre deux alertes identiques', 'sitepulse'); ?></label>
                            <input type="number" min="1" id="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES); ?>" value="<?php echo esc_attr(get_option(SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES, 60)); ?>" class="small-text">
                            <p class="sitepulse-card-description"><?php esc_html_e("Empêche l'envoi de plusieurs e-mails identiques pendant la durée spécifiée.", 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Fréquence des vérifications', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php
                            $interval_value   = (int) get_option(SITEPULSE_OPTION_ALERT_INTERVAL, 5);
                            $interval_choices = [
                                5  => __('Toutes les 5 minutes', 'sitepulse'),
                                10 => __('Toutes les 10 minutes', 'sitepulse'),
                                15 => __('Toutes les 15 minutes', 'sitepulse'),
                                30 => __('Toutes les 30 minutes', 'sitepulse'),
                            ];
                            ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_INTERVAL); ?>"><?php esc_html_e('Intervalle choisi', 'sitepulse'); ?></label>
                            <select id="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_INTERVAL); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_INTERVAL); ?>">
                                <?php foreach ($interval_choices as $minutes => $label) : ?>
                                    <option value="<?php echo esc_attr($minutes); ?>" <?php selected($interval_value, $minutes); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="sitepulse-card-description"><?php esc_html_e('Détermine la fréquence des vérifications automatisées pour les alertes.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sitepulse-settings-section" id="sitepulse-section-uptime">
                <h2><?php esc_html_e('Disponibilité', 'sitepulse'); ?></h2>
                <div class="sitepulse-settings-grid">
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('URL à surveiller', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $uptime_url_description_id = 'sitepulse-uptime-url-description'; ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_URL); ?>"><?php esc_html_e('Adresse du site', 'sitepulse'); ?></label>
                            <input type="url" id="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_URL); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_URL); ?>" value="<?php echo esc_attr($uptime_url); ?>" class="regular-text" aria-describedby="<?php echo esc_attr($uptime_url_description_id); ?>">
                            <p class="sitepulse-card-description" id="<?php echo esc_attr($uptime_url_description_id); ?>"><?php esc_html_e('Laisser vide pour utiliser automatiquement l’URL principale du site.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Délai d’attente (secondes)', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $uptime_timeout_description_id = 'sitepulse-uptime-timeout-description'; ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_TIMEOUT); ?>"><?php esc_html_e('Temps maximal avant échec', 'sitepulse'); ?></label>
                            <input type="number" min="1" id="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_TIMEOUT); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_TIMEOUT); ?>" value="<?php echo esc_attr($uptime_timeout); ?>" class="small-text" aria-describedby="<?php echo esc_attr($uptime_timeout_description_id); ?>">
                            <p class="sitepulse-card-description" id="<?php echo esc_attr($uptime_timeout_description_id); ?>"><?php esc_html_e('Nombre de secondes avant de considérer la requête comme échouée.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sitepulse-settings-actions">
                <?php submit_button(esc_html__('Enregistrer les modifications', 'sitepulse')); ?>
            </div>
        </form>
        <hr class="sitepulse-settings-separator">
        <div class="sitepulse-settings-section" id="sitepulse-section-maintenance">
            <h2><?php esc_html_e('Nettoyage & Réinitialisation', 'sitepulse'); ?></h2>
            <p class="sitepulse-section-intro"><?php esc_html_e('Gérez les données du plugin.', 'sitepulse'); ?></p>
            <form method="post" action="" class="sitepulse-settings-form sitepulse-settings-form--secondary">
                <?php wp_nonce_field(SITEPULSE_NONCE_ACTION_CLEANUP, SITEPULSE_NONCE_FIELD_CLEANUP); ?>
                <div class="sitepulse-settings-grid">
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Vider le journal de debug', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description"><?php esc_html_e('Supprime le contenu du fichier de log de débogage.', 'sitepulse'); ?></p>
                            <div class="sitepulse-card-footer">
                                <button type="submit" name="sitepulse_clear_log" class="button"><?php echo esc_html__('Vider le journal', 'sitepulse'); ?></button>
                            </div>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Vider les données stockées', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description"><?php esc_html_e('Supprime les données stockées comme les journaux de disponibilité et les résultats de scan.', 'sitepulse'); ?></p>
                            <div class="sitepulse-card-footer">
                                <button type="submit" name="sitepulse_clear_data" class="button"><?php echo esc_html__('Vider les données', 'sitepulse'); ?></button>
                            </div>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--danger">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Réinitialiser le plugin', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description"><?php esc_html_e("Réinitialise SitePulse à son état d'installation initial.", 'sitepulse'); ?></p>
                            <div class="sitepulse-card-footer">
                                <button type="submit" name="sitepulse_reset_all" class="button button-danger" onclick="return confirm('<?php echo esc_js(__('Êtes-vous sûr ?', 'sitepulse')); ?>');"><?php echo esc_html__('Tout réinitialiser', 'sitepulse'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
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
                                            echo '<li><strong>' . esc_html($hook) . '</strong> - Prochaine exécution: ' . esc_html($next_run) . '</li>';
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
