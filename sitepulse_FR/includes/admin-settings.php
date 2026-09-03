<?php
/**
 * SitePulse Admin Settings
 *
 * This file handles the creation of the admin menu and the rendering of settings pages.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) exit;

if (!defined('SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS')) {
    define('SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS', 'sitepulse_uptime_history_retention_days');
}

if (!defined('SITEPULSE_OPTION_SETTINGS_VIEW_MODE')) {
    define('SITEPULSE_OPTION_SETTINGS_VIEW_MODE', 'sitepulse_settings_view_mode');
}

if (!defined('SITEPULSE_DEFAULT_SETTINGS_VIEW_MODE')) {
    define('SITEPULSE_DEFAULT_SETTINGS_VIEW_MODE', 'simple');
}

if (!defined('SITEPULSE_DEFAULT_UPTIME_HISTORY_RETENTION_DAYS')) {
    define('SITEPULSE_DEFAULT_UPTIME_HISTORY_RETENTION_DAYS', 90);
}

require_once __DIR__ . '/admin-menu.php';

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

    $script_handle = 'sitepulse-admin-settings-tabs';
    $script_src    = SITEPULSE_URL . 'modules/js/admin-settings-tabs.js';
    $script_deps   = ['wp-a11y'];
    $script_ver    = defined('SITEPULSE_VERSION') ? SITEPULSE_VERSION : false;

    if (!wp_style_is($style_handle, 'registered')) {
        wp_register_style($style_handle, $style_src, $style_deps, $style_ver);
    }

    wp_enqueue_style($style_handle);

    if (!wp_script_is($script_handle, 'registered')) {
        wp_register_script($script_handle, $script_src, $script_deps, $script_ver, true);
    }

    wp_enqueue_script($script_handle);

    if (function_exists('admin_url')) {
        $poll_interval = 45000;

        if (function_exists('apply_filters')) {
            $poll_interval = (int) apply_filters('sitepulse_async_status_poll_interval', $poll_interval);
        }

        if ($poll_interval < 15000) {
            $poll_interval = 15000;
        }

        $ajax_nonce = function_exists('wp_create_nonce') ? wp_create_nonce('sitepulse_async_jobs') : '';

        $localization = [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'asyncJobsNonce'    => $ajax_nonce,
            'asyncPollInterval' => $poll_interval,
            'i18n'              => [
                'asyncEmpty'      => __('Aucun traitement en arrière-plan pour le moment.', 'sitepulse'),
                'asyncError'      => __('Impossible de rafraîchir le statut des traitements. Réessayez plus tard.', 'sitepulse'),
                'asyncUpdated'    => __('Statut des traitements en arrière-plan mis à jour.', 'sitepulse'),
                'asyncLogSummary' => __('Journal des opérations', 'sitepulse'),
                'asyncLogToggle'  => __('Afficher ou masquer le journal détaillé', 'sitepulse'),
            ],
        ];

        wp_localize_script($script_handle, 'sitepulseAdminSettingsData', $localization);
    }
}
add_action('admin_enqueue_scripts', 'sitepulse_admin_settings_enqueue_assets');

/**
 * Handles the regeneration of the AI job secret from the settings screen.
 *
 * @return void
 */
function sitepulse_admin_handle_ai_secret_regeneration() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse'));
    }

    check_admin_referer('sitepulse_regenerate_ai_secret');

    if (function_exists('sitepulse_ai_regenerate_job_secret')) {
        sitepulse_ai_regenerate_job_secret();
    }

    $redirect_url = add_query_arg(
        [
            'page'                            => 'sitepulse-settings',
            'sitepulse_ai_secret_regenerated' => 1,
            'sitepulse-settings-active-tab'   => 'sitepulse-tab-ai',
        ],
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect_url . '#sitepulse-tab-ai');
    exit;
}

add_action('admin_post_sitepulse_regenerate_ai_secret', 'sitepulse_admin_handle_ai_secret_regeneration');

if (!function_exists('sitepulse_ajax_async_jobs_overview')) {
    /**
     * Ajax handler returning the latest async job summaries.
     *
     * @return void
     */
    function sitepulse_ajax_async_jobs_overview() {
        if (!current_user_can(sitepulse_get_capability())) {
            wp_send_json_error(
                ['message' => __('Permission refusée pour cette action.', 'sitepulse')],
                403
            );
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'sitepulse_async_jobs')) {
            wp_send_json_error(
                ['message' => __('Nonce invalide : impossible de mettre à jour le statut.', 'sitepulse')],
                400
            );
        }

        $jobs = function_exists('sitepulse_prepare_async_jobs_overview')
            ? sitepulse_prepare_async_jobs_overview(null, ['include_logs' => true])
            : [];

        $state = 'idle';

        foreach ($jobs as $job) {
            if (!empty($job['is_active'])) {
                $state = 'busy';
                break;
            }
        }

        wp_send_json_success([
            'jobs'        => $jobs,
            'state'       => $state,
            'generated_at'=> function_exists('current_time') ? current_time('timestamp') : time(),
        ]);
    }

    add_action('wp_ajax_sitepulse_async_jobs_overview', 'sitepulse_ajax_async_jobs_overview');
}

/**
 * Registers the settings fields.
 */
function sitepulse_register_settings() {
    register_setting('sitepulse_settings', SITEPULSE_OPTION_ACTIVE_MODULES, [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_modules', 'default' => []
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_SETTINGS_VIEW_MODE, [
        'type' => 'string',
        'sanitize_callback' => 'sitepulse_sanitize_settings_view_mode',
        'default' => SITEPULSE_DEFAULT_SETTINGS_VIEW_MODE,
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
    register_setting('sitepulse_settings', SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS, [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_alert_channels', 'default' => ['cpu', 'php_fatal']
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, [
        'type' => 'number', 'sanitize_callback' => 'sitepulse_sanitize_cpu_threshold', 'default' => 5
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT, [
        'type' => 'number',
        'sanitize_callback' => 'sitepulse_sanitize_resource_monitor_cpu_threshold',
        'default' => SITEPULSE_DEFAULT_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT,
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT, [
        'type' => 'number',
        'sanitize_callback' => 'sitepulse_sanitize_resource_monitor_memory_threshold',
        'default' => SITEPULSE_DEFAULT_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT,
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT, [
        'type' => 'number',
        'sanitize_callback' => 'sitepulse_sanitize_resource_monitor_disk_threshold',
        'default' => SITEPULSE_DEFAULT_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT,
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_RESOURCE_MONITOR_RETENTION_DAYS, [
        'type'              => 'integer',
        'sanitize_callback' => 'sitepulse_sanitize_resource_monitor_retention_days',
        'default'           => SITEPULSE_DEFAULT_RESOURCE_MONITOR_RETENTION_DAYS,
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_RESOURCE_MONITOR_EXPORT_MAX_ROWS, [
        'type'              => 'integer',
        'sanitize_callback' => 'sitepulse_sanitize_resource_monitor_export_rows',
        'default'           => SITEPULSE_DEFAULT_RESOURCE_MONITOR_EXPORT_MAX_ROWS,
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD, [
        'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_php_fatal_threshold', 'default' => 1
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
    register_setting('sitepulse_settings', SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS, [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_error_alert_delivery_channels', 'default' => ['email']
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS, [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_error_alert_webhooks', 'default' => []
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES, [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_error_alert_severities', 'default' => ['warning', 'critical']
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_URL, [
        'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_TIMEOUT, [
        'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_uptime_timeout', 'default' => SITEPULSE_DEFAULT_UPTIME_TIMEOUT
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_FREQUENCY, [
        'type' => 'string', 'sanitize_callback' => 'sitepulse_sanitize_uptime_frequency', 'default' => SITEPULSE_DEFAULT_UPTIME_FREQUENCY
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_HTTP_METHOD, [
        'type' => 'string', 'sanitize_callback' => 'sitepulse_sanitize_uptime_http_method', 'default' => SITEPULSE_DEFAULT_UPTIME_HTTP_METHOD
    ]);
    $uptime_agents_sanitize_callback = function_exists('sitepulse_uptime_sanitize_agents')
        ? 'sitepulse_uptime_sanitize_agents'
        : static function ($value) {
            if (!is_array($value)) {
                return [];
            }

            $sanitized = [];

            foreach ($value as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';
                $region = isset($row['region']) ? sanitize_key($row['region']) : '';
                $key = isset($row['id']) ? sanitize_key($row['id']) : '';

                if ($label === '' && $region === '' && $key === '') {
                    continue;
                }

                if ($key === '') {
                    $key = sanitize_key($label);
                }

                if ($key === '') {
                    $key = uniqid('agent_', false);
                }

                if (isset($sanitized[$key])) {
                    continue;
                }

                $sanitized[$key] = [
                    'label'  => $label,
                    'region' => $region !== '' ? $region : 'global',
                    'active' => !empty($row['active']),
                    'url'    => isset($row['url']) ? esc_url_raw(trim((string) $row['url'])) : '',
                    'timeout'=> isset($row['timeout']) && is_numeric($row['timeout']) ? max(1, (int) $row['timeout']) : null,
                    'weight' => isset($row['weight']) && is_numeric($row['weight']) ? max(0, (float) $row['weight']) : 1.0,
                ];
            }

            return $sanitized;
        };

    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_HTTP_HEADERS, [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_uptime_http_headers', 'default' => []
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_EXPECTED_CODES, [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_uptime_expected_codes', 'default' => []
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_AGENTS, [
        'type' => 'array', 'sanitize_callback' => $uptime_agents_sanitize_callback, 'default' => []
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_LATENCY_THRESHOLD, [
        'type' => 'number', 'sanitize_callback' => 'sitepulse_sanitize_uptime_latency_threshold', 'default' => SITEPULSE_DEFAULT_UPTIME_LATENCY_THRESHOLD
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_KEYWORD, [
        'type' => 'string', 'sanitize_callback' => 'sitepulse_sanitize_uptime_keyword', 'default' => ''
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS, [
        'type' => 'integer',
        'sanitize_callback' => 'sitepulse_sanitize_uptime_history_retention',
        'default' => sitepulse_get_default_uptime_history_retention_days(),
    ]);
    register_setting('sitepulse_settings', SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS, [
        'type' => 'array', 'sanitize_callback' => 'sitepulse_sanitize_uptime_maintenance_windows', 'default' => []
    ]);
register_setting('sitepulse_settings', SITEPULSE_OPTION_SPEED_WARNING_MS, [
    'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_speed_warning_threshold', 'default' => SITEPULSE_DEFAULT_SPEED_WARNING_MS
]);
register_setting('sitepulse_settings', SITEPULSE_OPTION_SPEED_CRITICAL_MS, [
    'type' => 'integer', 'sanitize_callback' => 'sitepulse_sanitize_speed_critical_threshold', 'default' => SITEPULSE_DEFAULT_SPEED_CRITICAL_MS
]);
register_setting('sitepulse_settings', SITEPULSE_OPTION_SPEED_BENCHMARKS, [
    'type' => 'array',
    'sanitize_callback' => 'sitepulse_sanitize_speed_benchmarks',
    'default' => [
        'competitors' => [],
        'budgets'     => [],
    ],
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
function sitepulse_sanitize_settings_view_mode($value) {
    $default = defined('SITEPULSE_DEFAULT_SETTINGS_VIEW_MODE') ? SITEPULSE_DEFAULT_SETTINGS_VIEW_MODE : 'simple';

    if (!is_string($value)) {
        return $default;
    }

    $normalized = strtolower(trim($value));

    if ($normalized !== 'simple' && $normalized !== 'expert') {
        return $default;
    }

    return $normalized;
}

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
 * Sanitizes the list of enabled alert channels.
 *
 * @param mixed $input Raw user input value.
 * @return array List of allowed channel identifiers.
 */
function sitepulse_sanitize_alert_channels($input) {
    $valid_channels = ['cpu', 'php_fatal'];
    $sanitized      = [];

    if (is_array($input)) {
        foreach ($input as $channel) {
            if (in_array($channel, $valid_channels, true)) {
                $sanitized[] = $channel;
            }
        }
    }

    return array_values(array_unique($sanitized));
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
 * Normalises percentage thresholds ensuring they stay within 0-100.
 *
 * @param mixed $value   Raw value provided by the user.
 * @param int   $default Default fallback value.
 * @return int
 */
function sitepulse_sanitize_percentage_threshold($value, $default) {
    if (!is_numeric($default)) {
        $default = 0;
    }

    $value = is_scalar($value) ? (float) $value : $default;

    if ($value < 0) {
        $value = 0;
    }

    if ($value > 100) {
        $value = 100;
    }

    if ($value === 0 && $default > 0) {
        $value = (float) $default;
    }

    return (int) round($value);
}

/**
 * Sanitizes the CPU usage threshold for the resource monitor cron alerts.
 *
 * @param mixed $value Raw value provided by the user.
 * @return int
 */
function sitepulse_sanitize_resource_monitor_cpu_threshold($value) {
    return sitepulse_sanitize_percentage_threshold($value, SITEPULSE_DEFAULT_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT);
}

/**
 * Sanitizes the memory usage threshold for the resource monitor cron alerts.
 *
 * @param mixed $value Raw value provided by the user.
 * @return int
 */
function sitepulse_sanitize_resource_monitor_memory_threshold($value) {
    return sitepulse_sanitize_percentage_threshold($value, SITEPULSE_DEFAULT_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT);
}

/**
 * Sanitizes the disk usage threshold for the resource monitor cron alerts.
 *
 * @param mixed $value Raw value provided by the user.
 * @return int
 */
function sitepulse_sanitize_resource_monitor_disk_threshold($value) {
    return sitepulse_sanitize_percentage_threshold($value, SITEPULSE_DEFAULT_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT);
}

/**
 * Sanitizes the retention duration for the resource monitor history (in days).
 *
 * @param mixed $value Raw value provided by the user.
 * @return int
 */
function sitepulse_sanitize_resource_monitor_retention_days($value) {
    $default = defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_RETENTION_DAYS')
        ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_RETENTION_DAYS
        : 180;

    if (!is_numeric($value)) {
        $value = $default;
    }

    $value = (int) $value;

    if ($value < 0) {
        $value = $default;
    }

    $allowed_values = apply_filters('sitepulse_resource_monitor_allowed_retention_days', [90, 180, 365]);

    if (!is_array($allowed_values) || empty($allowed_values)) {
        return max(0, $value);
    }

    $allowed_values = array_map('intval', $allowed_values);
    $allowed_values = array_values(array_filter($allowed_values, static function ($candidate) {
        return $candidate >= 0;
    }));

    if (empty($allowed_values)) {
        return max(0, $value);
    }

    sort($allowed_values);

    if (in_array($value, $allowed_values, true)) {
        return max(0, $value);
    }

    $closest = $allowed_values[0];
    $min_diff = abs($value - $closest);

    foreach ($allowed_values as $candidate) {
        $diff = abs($value - $candidate);

        if ($diff < $min_diff) {
            $min_diff = $diff;
            $closest = $candidate;
        }
    }

    return max(0, (int) $closest);
}

/**
 * Sanitizes the maximum number of rows allowed in resource monitor exports.
 *
 * @param mixed $value Raw value provided by the user.
 * @return int
 */
function sitepulse_sanitize_resource_monitor_export_rows($value) {
    $default = defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_EXPORT_MAX_ROWS')
        ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_EXPORT_MAX_ROWS
        : 2000;

    if (!is_numeric($value)) {
        return $default;
    }

    $value = (int) $value;

    if ($value < 0) {
        return $default;
    }

    if ($value === 0) {
        return 0;
    }

    $ceiling = (int) apply_filters('sitepulse_resource_monitor_export_rows_ceiling', 50000);

    if ($ceiling > 0 && $value > $ceiling) {
        $value = $ceiling;
    }

    if ($value <= 0) {
        return $default;
    }

    return $value;
}

/**
 * Sanitizes the PHP fatal error alert threshold.
 *
 * @param mixed $value Raw user input value.
 * @return int Number of fatal entries required to send an alert.
 */
function sitepulse_sanitize_php_fatal_threshold($value) {
    $value = is_scalar($value) ? absint($value) : 0;

    if ($value < 1) {
        $value = 1;
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
 * Returns the available delivery channel labels for error alerts.
 *
 * @return array<string, string> Associative array of channel => label.
 */
function sitepulse_get_error_alert_delivery_channel_choices() {
    return [
        'email'   => __('E-mail', 'sitepulse'),
        'webhook' => __('Webhooks', 'sitepulse'),
    ];
}

/**
 * Sanitizes the delivery channels enabled for error alerts.
 *
 * @param mixed $value Raw user input value.
 * @return array List of allowed delivery channels.
 */
function sitepulse_sanitize_error_alert_delivery_channels($value) {
    if (is_string($value)) {
        $value = [$value];
    } elseif (!is_array($value)) {
        $value = [];
    }

    $choices   = sitepulse_get_error_alert_delivery_channel_choices();
    $sanitized = [];

    foreach ($value as $channel) {
        if (!is_string($channel)) {
            continue;
        }

        $channel = sanitize_key($channel);

        if ($channel === '' || !isset($choices[$channel])) {
            continue;
        }

        if (!in_array($channel, $sanitized, true)) {
            $sanitized[] = $channel;
        }
    }

    if (empty($sanitized)) {
        $sanitized[] = 'email';
    }

    return $sanitized;
}

/**
 * Sanitizes the list of webhook URLs used for error alert delivery.
 *
 * @param mixed $value Raw user input value.
 * @return array List of validated webhook URLs.
 */
function sitepulse_sanitize_error_alert_webhooks($value) {
    if (is_string($value)) {
        $value = preg_split('/[\r\n]+/', $value);
    } elseif (!is_array($value)) {
        $value = [];
    }

    $sanitized = [];

    foreach ($value as $url) {
        if (!is_string($url)) {
            continue;
        }

        $url = trim($url);

        if ($url === '') {
            continue;
        }

        $normalized = esc_url_raw($url);

        if ($normalized === '') {
            continue;
        }

        if (function_exists('wp_http_validate_url') && !wp_http_validate_url($normalized)) {
            continue;
        }

        if (!in_array($normalized, $sanitized, true)) {
            $sanitized[] = $normalized;
        }
    }

    return $sanitized;
}

/**
 * Returns the severity labels available for error alerts.
 *
 * @return array<string, string> Associative array of severity => label.
 */
function sitepulse_get_error_alert_severity_choices() {
    return [
        'info'     => __('Information', 'sitepulse'),
        'warning'  => __('Avertissement', 'sitepulse'),
        'critical' => __('Critique', 'sitepulse'),
    ];
}

/**
 * Sanitizes the list of severities that should trigger notifications.
 *
 * @param mixed $value Raw user input value.
 * @return array List of allowed severity identifiers.
 */
function sitepulse_sanitize_error_alert_severities($value) {
    if (is_string($value)) {
        $value = [$value];
    } elseif (!is_array($value)) {
        $value = [];
    }

    $choices   = sitepulse_get_error_alert_severity_choices();
    $sanitized = [];

    foreach ($value as $severity) {
        if (!is_string($severity)) {
            continue;
        }

        $severity = sanitize_key($severity);

        if ($severity === '' || !isset($choices[$severity])) {
            continue;
        }

        if (!in_array($severity, $sanitized, true)) {
            $sanitized[] = $severity;
        }
    }

    if (empty($sanitized)) {
        $sanitized = ['warning', 'critical'];
    }

    return $sanitized;
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
 * Sanitizes the latency threshold (in seconds) for uptime validation.
 *
 * @param mixed $value Raw user input value.
 * @return float Normalized latency threshold. Returns 0 to disable validation.
 */
function sitepulse_sanitize_uptime_latency_threshold($value) {
    $default = defined('SITEPULSE_DEFAULT_UPTIME_LATENCY_THRESHOLD') ? (float) SITEPULSE_DEFAULT_UPTIME_LATENCY_THRESHOLD : 0.0;

    if (is_string($value)) {
        $value = str_replace(',', '.', $value);
    }

    if (!is_scalar($value) || !is_numeric($value)) {
        return $default;
    }

    $value = (float) $value;

    if ($value <= 0) {
        return 0.0;
    }

    return round($value, 4);
}

/**
 * Returns the default retention window for uptime history in days.
 *
 * @return int
 */
function sitepulse_get_default_uptime_history_retention_days() {
    return defined('SITEPULSE_DEFAULT_UPTIME_HISTORY_RETENTION_DAYS')
        ? (int) SITEPULSE_DEFAULT_UPTIME_HISTORY_RETENTION_DAYS
        : 90;
}

/**
 * Provides the selectable retention durations for uptime history.
 *
 * @return array<int,string>
 */
function sitepulse_get_uptime_history_retention_choices() {
    return [
        30  => __('30 derniers jours', 'sitepulse'),
        90  => __('90 derniers jours', 'sitepulse'),
        180 => __('6 derniers mois', 'sitepulse'),
        365 => __('12 derniers mois', 'sitepulse'),
    ];
}

/**
 * Sanitizes the retention duration for uptime history.
 *
 * @param mixed $value Raw user input value.
 * @return int
 */
function sitepulse_sanitize_uptime_history_retention($value) {
    $default = sitepulse_get_default_uptime_history_retention_days();

    if (!is_scalar($value) || !is_numeric($value)) {
        return $default;
    }

    $value = (int) $value;
    $choices = array_keys(sitepulse_get_uptime_history_retention_choices());

    if (!in_array($value, $choices, true)) {
        return $default;
    }

    return $value;
}

/**
 * Returns the available frequency choices for uptime checks.
 *
 * @return array<string,array<string,mixed>> List of frequency configurations.
 */
function sitepulse_get_uptime_frequency_choices() {
    $minute = defined('MINUTE_IN_SECONDS') ? (int) MINUTE_IN_SECONDS : 60;
    $hour   = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
    $day    = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;

    return [
        'sitepulse_uptime_five_minutes'   => [
            'label'    => __('Toutes les 5 minutes', 'sitepulse'),
            'interval' => 5 * $minute,
        ],
        'sitepulse_uptime_ten_minutes'    => [
            'label'    => __('Toutes les 10 minutes', 'sitepulse'),
            'interval' => 10 * $minute,
        ],
        'sitepulse_uptime_fifteen_minutes' => [
            'label'    => __('Toutes les 15 minutes', 'sitepulse'),
            'interval' => 15 * $minute,
        ],
        'sitepulse_uptime_thirty_minutes' => [
            'label'    => __('Toutes les 30 minutes', 'sitepulse'),
            'interval' => 30 * $minute,
        ],
        'hourly'                          => [
            'label'    => __('Toutes les heures', 'sitepulse'),
            'interval' => $hour,
        ],
        'twicedaily'                      => [
            'label'    => __('Deux fois par jour', 'sitepulse'),
            'interval' => 12 * $hour,
        ],
        'daily'                           => [
            'label'    => __('Quotidien', 'sitepulse'),
            'interval' => $day,
        ],
    ];
}

/**
 * Retrieves the default frequency identifier for uptime checks.
 *
 * @return string
 */
function sitepulse_get_default_uptime_frequency() {
    return defined('SITEPULSE_DEFAULT_UPTIME_FREQUENCY') ? SITEPULSE_DEFAULT_UPTIME_FREQUENCY : 'hourly';
}

/**
 * Returns the supported HTTP method choices for uptime requests.
 *
 * @return array<string,string>
 */
function sitepulse_get_uptime_http_method_choices() {
    return [
        'GET'  => __('GET', 'sitepulse'),
        'HEAD' => __('HEAD', 'sitepulse'),
        'POST' => __('POST', 'sitepulse'),
    ];
}

/**
 * Sanitizes the configured frequency identifier for uptime checks.
 *
 * @param mixed $value Raw user input value.
 * @return string Validated frequency identifier.
 */
function sitepulse_sanitize_uptime_frequency($value) {
    $default = sitepulse_get_default_uptime_frequency();

    if (!is_string($value) || $value === '') {
        return $default;
    }

    $value = trim($value);
    $choices = sitepulse_get_uptime_frequency_choices();

    if (!array_key_exists($value, $choices)) {
        return $default;
    }

    return $value;
}

/**
 * Sanitizes the configured HTTP method used for uptime checks.
 *
 * @param mixed $value Raw user input value.
 * @return string Validated HTTP method.
 */
function sitepulse_sanitize_uptime_http_method($value) {
    $default = defined('SITEPULSE_DEFAULT_UPTIME_HTTP_METHOD') ? SITEPULSE_DEFAULT_UPTIME_HTTP_METHOD : 'GET';

    if (!is_string($value) || $value === '') {
        return $default;
    }

    $value = strtoupper(trim($value));
    $choices = sitepulse_get_uptime_http_method_choices();

    if (!array_key_exists($value, $choices)) {
        return $default;
    }

    return $value;
}

/**
 * Sanitizes the custom HTTP headers configured for uptime checks.
 *
 * @param mixed $value Raw user input value.
 * @return array<string,string> Associative array of header names and values.
 */
function sitepulse_sanitize_uptime_http_headers($value) {
    $headers = [];

    if (is_string($value)) {
        $value = preg_split('/\r\n|\r|\n/', $value);
    }

    if (!is_array($value)) {
        return $headers;
    }

    foreach ($value as $key => $entry) {
        if (is_string($key) && $key !== '' && is_scalar($entry)) {
            $header_name  = trim($key);
            $header_value = trim((string) $entry);
        } elseif (is_string($entry)) {
            $parts = explode(':', $entry, 2);
            $header_name = trim($parts[0]);
            $header_value = isset($parts[1]) ? trim($parts[1]) : '';
        } else {
            continue;
        }

        if ($header_name === '') {
            continue;
        }

        if (!preg_match('/^[A-Za-z0-9\-]+$/', $header_name)) {
            continue;
        }

        $headers[$header_name] = $header_value;
    }

    return $headers;
}

/**
 * Sanitizes the expected HTTP status codes configured for uptime checks.
 *
 * @param mixed $value Raw user input value.
 * @return int[] List of expected status codes.
 */
function sitepulse_sanitize_uptime_expected_codes($value) {
    $codes = [];
    $entries = [];

    if (is_string($value)) {
        $entries = preg_split('/[\s,]+/', $value);
    } elseif (is_array($value)) {
        foreach ($value as $key => $item) {
            if (is_scalar($item)) {
                $entries[] = (string) $item;
            } elseif (is_scalar($key) && !is_int($key)) {
                $entries[] = (string) $key;
            }
        }
    }

    if (empty($entries)) {
        return $codes;
    }

    foreach ($entries as $entry) {
        $entry = trim((string) $entry);

        if ($entry === '') {
            continue;
        }

        if (strpos($entry, '-') !== false) {
            $range_parts = explode('-', $entry, 2);

            if (count($range_parts) === 2) {
                $start = absint($range_parts[0]);
                $end   = absint($range_parts[1]);

                if ($start >= 100 && $start <= 599 && $end >= $start) {
                    $end = min($end, 599);
                    for ($code = $start; $code <= $end; $code++) {
                        $codes[] = $code;
                    }
                    continue;
                }
            }
        }

        $code = absint($entry);

        if ($code >= 100 && $code <= 599) {
            $codes[] = $code;
        }
    }

    if (empty($codes)) {
        return [];
    }

    $codes = array_values(array_unique($codes));
    sort($codes, SORT_NUMERIC);

    return $codes;
}

/**
 * Sanitizes the expected keyword used to validate uptime responses.
 *
 * @param mixed $value Raw user input value.
 * @return string Clean keyword or empty string when disabled.
 */
function sitepulse_sanitize_uptime_keyword($value) {
    if (is_array($value)) {
        $value = implode(' ', $value);
    }

    if (!is_scalar($value)) {
        return '';
    }

    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    return sanitize_text_field($value);
}

/**
 * Sanitizes the recurring uptime maintenance windows configuration.
 *
 * @param mixed $value Raw user input value.
 * @return array<int,array<string,mixed>>
 */
function sitepulse_sanitize_uptime_maintenance_windows($value) {
    if (!is_array($value)) {
        return [];
    }

    $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $sanitized = [];

    foreach ($value as $window) {
        if (!is_array($window)) {
            continue;
        }

        if (isset($window['start'], $window['end'])) {
            $start = isset($window['start']) ? (int) $window['start'] : 0;
            $end   = isset($window['end']) ? (int) $window['end'] : 0;

            if ($start > 0 && $end > $start) {
                $duration = max(1, (int) round(($end - $start) / MINUTE_IN_SECONDS));
                $date     = function_exists('wp_date') ? wp_date('Y-m-d', $start) : gmdate('Y-m-d', $start);
                $time     = function_exists('wp_date') ? wp_date('H:i', $start) : gmdate('H:i', $start);
                $day      = (int) ((new DateTimeImmutable('@' . $start))->setTimezone($timezone)->format('N'));

                $window = [
                    'agent'      => isset($window['agent']) ? $window['agent'] : 'all',
                    'label'      => isset($window['label']) ? $window['label'] : '',
                    'recurrence' => 'one_off',
                    'day'        => $day,
                    'time'       => $time,
                    'duration'   => $duration,
                    'date'       => $date,
                ];
            }
        }

        $agent = isset($window['agent']) ? sanitize_key($window['agent']) : 'all';

        if ($agent === '') {
            $agent = 'all';
        }

        $label = isset($window['label']) ? sanitize_text_field($window['label']) : '';

        $recurrence = isset($window['recurrence']) ? sanitize_key($window['recurrence']) : 'weekly';
        $allowed_recurrences = ['daily', 'weekly', 'one_off'];

        if (!in_array($recurrence, $allowed_recurrences, true)) {
            $recurrence = 'weekly';
        }

        $time = isset($window['time']) ? trim((string) $window['time']) : '';

        if ($time === '' || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            continue;
        }

        $duration = isset($window['duration']) ? (int) $window['duration'] : 0;

        if ($duration < 1) {
            continue;
        }

        $day = isset($window['day']) ? (int) $window['day'] : 0;
        $date_value = '';

        if ('one_off' === $recurrence) {
            $date_candidate = isset($window['date']) ? trim((string) $window['date']) : '';

            if ($date_candidate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_candidate)) {
                continue;
            }

            try {
                $date_object = new DateTimeImmutable($date_candidate, $timezone);
            } catch (Exception $e) {
                continue;
            }

            $date_value = $date_object->format('Y-m-d');
            $day = (int) $date_object->format('N');
        } else {
            if ($day < 1 || $day > 7) {
                continue;
            }
        }

        $sanitized[] = [
            'agent'      => $agent,
            'label'      => $label,
            'recurrence' => $recurrence,
            'day'        => $day,
            'time'       => $time,
            'duration'   => $duration,
            'date'       => $date_value,
        ];
    }

    return $sanitized;
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
 * Sanitizes the competitor benchmark configuration for the speed analyzer.
 *
 * @param array<string,mixed>|string $value Raw option payload.
 *
 * @return array{competitors:array<int,array{slug:string,label:string,url:string}>,budgets:array<string,int>}
 */
function sitepulse_sanitize_speed_benchmarks($value) {
    if (!is_array($value)) {
        $value = [];
    }

    $raw_competitors = '';

    if (isset($value['competitors']) && is_string($value['competitors'])) {
        $raw_competitors = $value['competitors'];
    } elseif (isset($value['competitors_raw']) && is_string($value['competitors_raw'])) {
        $raw_competitors = $value['competitors_raw'];
    }

    $competitors = [];
    $lines = preg_split('/[\r\n]+/', (string) $raw_competitors) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $url = esc_url_raw($line);

        if ($url === '') {
            continue;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        $label = $host ? $host : $url;
        $slug_source = $host ? $host : md5($url);
        $slug = sanitize_key($slug_source);

        if ($slug === '') {
            $slug = substr(md5($slug_source), 0, 12);
        }

        $competitors[] = [
            'slug'  => $slug,
            'label' => $label,
            'url'   => $url,
        ];
    }

    $profiles = [];

    if (function_exists('sitepulse_speed_analyzer_get_profile_catalog')) {
        $profiles = sitepulse_speed_analyzer_get_profile_catalog();
    }

    if (!is_array($profiles) || $profiles === []) {
        $profiles = [
            'default' => [
                'label' => __('Standard', 'sitepulse'),
            ],
        ];
    }

    $budgets_input = isset($value['budgets']) && is_array($value['budgets']) ? $value['budgets'] : [];
    $budgets = [];

    foreach ($profiles as $slug => $profile) {
        $raw_budget = isset($budgets_input[$slug]) ? $budgets_input[$slug] : null;
        $budget_value = is_numeric($raw_budget) ? (int) $raw_budget : 0;

        if ($budget_value > 0) {
            $budgets[$slug] = $budget_value;
        }
    }

    return [
        'competitors' => $competitors,
        'budgets'     => $budgets,
    ];
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
        'trendWarning'   => 15.0,
        'trendCritical'  => 40.0,
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

    $trend_warning = sitepulse_sanitize_impact_threshold_number(
        array_key_exists('trendWarning', $thresholds) ? $thresholds['trendWarning'] : $fallback['trendWarning'],
        $fallback['trendWarning'],
        $defaults['trendWarning']
    );

    $trend_critical = sitepulse_sanitize_impact_threshold_number(
        array_key_exists('trendCritical', $thresholds) ? $thresholds['trendCritical'] : $fallback['trendCritical'],
        $fallback['trendCritical'],
        $defaults['trendCritical']
    );

    if ($trend_critical <= $trend_warning) {
        $trend_critical = max($trend_warning + 0.1, $fallback['trendCritical'], $defaults['trendCritical']);
        $trend_critical = round($trend_critical, 2);
    }

    $normalized = [
        'impactWarning'  => (float) min(max($impact_warning, 0.0), 100.0),
        'impactCritical' => (float) min(max($impact_critical, 0.0), 100.0),
        'weightWarning'  => (float) min(max($weight_warning, 0.0), 100.0),
        'weightCritical' => (float) min(max($weight_critical, 0.0), 100.0),
        'trendWarning'   => (float) min(max($trend_warning, 0.0), 100.0),
        'trendCritical'  => (float) min(max($trend_critical, 0.0), 100.0),
    ];

    if ($normalized['impactCritical'] <= $normalized['impactWarning']) {
        $normalized['impactCritical'] = min(100.0, round($normalized['impactWarning'] + 0.1, 2));
    }

    if ($normalized['weightCritical'] <= $normalized['weightWarning']) {
        $normalized['weightCritical'] = min(100.0, round($normalized['weightWarning'] + 0.1, 2));
    }

    if ($normalized['trendCritical'] <= $normalized['trendWarning']) {
        $normalized['trendCritical'] = min(100.0, round($normalized['trendWarning'] + 0.1, 2));
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
 * Builds status summaries for each module card using stored measurements.
 *
 * @return array<string,array<int,array<string,string>>> Module summaries keyed by module slug.
 */
function sitepulse_get_module_status_summaries() {
    $summaries = [];
    $now_local = function_exists('current_time') ? (int) current_time('timestamp') : time();
    $now_utc   = function_exists('current_time') ? (int) current_time('timestamp', true) : time();

    // Log Analyzer – queued debug notices.
    $debug_notices = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);

    if (!is_array($debug_notices)) {
        $debug_notices = [];
    }

    $notice_count = count($debug_notices);
    $summaries['log_analyzer'][] = [
        'label'  => __('Alertes', 'sitepulse'),
        'value'  => $notice_count > 0
            ? sprintf(_n('%s en attente', '%s en attente', $notice_count, 'sitepulse'), number_format_i18n($notice_count))
            : __('Aucune alerte', 'sitepulse'),
        'status' => $notice_count > 0 ? 'is-critical' : 'is-success',
    ];

    // Resource Monitor – last recorded load and memory usage.
    $latest_resource_entry = null;

    if (function_exists('sitepulse_resource_monitor_get_history')) {
        $history_snapshot = sitepulse_resource_monitor_get_history([
            'per_page' => 1,
            'page'     => 1,
            'order'    => 'DESC',
        ]);

        if (isset($history_snapshot['entries'][0]) && is_array($history_snapshot['entries'][0])) {
            $latest_resource_entry = $history_snapshot['entries'][0];
        }
    }

    if ($latest_resource_entry === null) {
        $legacy_history = get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY, []);

        if (is_array($legacy_history) && !empty($legacy_history)) {
            $latest_resource_entry = end($legacy_history);
        }
    }

    if (is_array($latest_resource_entry)) {
        $load_value = null;

        if (isset($latest_resource_entry['load']) && is_array($latest_resource_entry['load'])) {
            if (isset($latest_resource_entry['load'][0]) && is_numeric($latest_resource_entry['load'][0])) {
                $load_value = (float) $latest_resource_entry['load'][0];
            } else {
                $first_load = reset($latest_resource_entry['load']);

                if ($first_load !== false && is_numeric($first_load)) {
                    $load_value = (float) $first_load;
                }
            }
        }

        if ($load_value !== null) {
            $load_status = 'is-success';

            if ($load_value >= 2) {
                $load_status = 'is-critical';
            } elseif ($load_value >= 1) {
                $load_status = 'is-warning';
            }

            $summaries['resource_monitor'][] = [
                'label'  => __('Charge serveur', 'sitepulse'),
                'value'  => sprintf(__('%s (1 min)', 'sitepulse'), number_format_i18n($load_value, 2)),
                'status' => $load_status,
            ];
        }

        $memory_usage = isset($latest_resource_entry['memory']['usage']) && is_numeric($latest_resource_entry['memory']['usage'])
            ? (int) $latest_resource_entry['memory']['usage']
            : null;
        $memory_limit = isset($latest_resource_entry['memory']['limit']) && is_numeric($latest_resource_entry['memory']['limit'])
            ? (int) $latest_resource_entry['memory']['limit']
            : null;

        if ($memory_usage !== null && $memory_limit !== null && $memory_limit > 0) {
            $memory_percent = ($memory_usage / $memory_limit) * 100;
            $memory_status  = 'is-success';

            if ($memory_percent >= 90) {
                $memory_status = 'is-critical';
            } elseif ($memory_percent >= 75) {
                $memory_status = 'is-warning';
            }

            $summaries['resource_monitor'][] = [
                'label'  => __('Mémoire', 'sitepulse'),
                'value'  => sprintf(__('%s %% utilisés', 'sitepulse'), number_format_i18n($memory_percent, 0)),
                'status' => $memory_status,
            ];
        }
    }

    // Plugin Impact Scanner – last refresh and sample count.
    $impact_data = get_option(SITEPULSE_PLUGIN_IMPACT_OPTION, []);

    if (is_array($impact_data)) {
        $default_interval = defined('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL')
            ? (int) SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL
            : 15 * MINUTE_IN_SECONDS;
        $interval     = isset($impact_data['interval']) && is_numeric($impact_data['interval'])
            ? max(1, (int) $impact_data['interval'])
            : $default_interval;
        $last_updated = isset($impact_data['last_updated']) ? (int) $impact_data['last_updated'] : 0;

        if ($last_updated > 0) {
            $age_seconds = max(0, $now_local - $last_updated);
            $status      = 'is-success';

            if ($interval > 0) {
                if ($age_seconds > ($interval * 2)) {
                    $status = 'is-critical';
                } elseif ($age_seconds > $interval) {
                    $status = 'is-warning';
                }
            }

            $summaries['plugin_impact_scanner'][] = [
                'label'  => __('Dernière analyse', 'sitepulse'),
                'value'  => sprintf(__('Il y a %s', 'sitepulse'), human_time_diff($last_updated, $now_local)),
                'status' => $status,
            ];
        }

        $samples = isset($impact_data['samples']) && is_array($impact_data['samples'])
            ? array_filter($impact_data['samples'], 'is_array')
            : [];
        $sample_count = count($samples);

        if ($sample_count > 0) {
            $summaries['plugin_impact_scanner'][] = [
                'label'  => __('Extensions suivies', 'sitepulse'),
                'value'  => number_format_i18n($sample_count),
                'status' => 'is-success',
            ];
        }
    }

    // Speed Analyzer – last response time and last scan.
    $thresholds = function_exists('sitepulse_get_speed_thresholds')
        ? sitepulse_get_speed_thresholds()
        : [
            'warning'  => defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200,
            'critical' => defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500,
        ];
    $warning_ms  = isset($thresholds['warning']) ? (int) $thresholds['warning'] : 200;
    $critical_ms = isset($thresholds['critical']) ? (int) $thresholds['critical'] : max($warning_ms + 1, 500);

    $last_load_time = get_option(SITEPULSE_OPTION_LAST_LOAD_TIME, 0);
    $last_load_time = is_numeric($last_load_time) ? max(0.0, (float) $last_load_time) : 0.0;

    if ($last_load_time > 0) {
        $speed_status = 'is-success';

        if ($last_load_time >= $critical_ms) {
            $speed_status = 'is-critical';
        } elseif ($last_load_time >= $warning_ms) {
            $speed_status = 'is-warning';
        }

        $summaries['speed_analyzer'][] = [
            'label'  => __('Temps de réponse', 'sitepulse'),
            'value'  => sprintf(__('%s ms', 'sitepulse'), number_format_i18n($last_load_time, 0)),
            'status' => $speed_status,
        ];
    }

    $speed_history = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

    if (is_array($speed_history) && !empty($speed_history)) {
        $last_scan = end($speed_history);

        if (is_array($last_scan) && isset($last_scan['timestamp'])) {
            $scan_timestamp = (int) $last_scan['timestamp'];

            if ($scan_timestamp > 0) {
                $age_seconds = max(0, $now_local - $scan_timestamp);
                $status      = 'is-success';

                if ($age_seconds > (2 * DAY_IN_SECONDS)) {
                    $status = 'is-critical';
                } elseif ($age_seconds > DAY_IN_SECONDS) {
                    $status = 'is-warning';
                }

                $summaries['speed_analyzer'][] = [
                    'label'  => __('Dernier scan', 'sitepulse'),
                    'value'  => sprintf(__('Il y a %s', 'sitepulse'), human_time_diff($scan_timestamp, $now_local)),
                    'status' => $status,
                ];
            }
        }
    }

    // Uptime Tracker – latest status and availability ratio.
    $raw_uptime_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
    $uptime_entries = [];

    if (function_exists('sitepulse_normalize_uptime_log')) {
        $uptime_entries = sitepulse_normalize_uptime_log($raw_uptime_log);
    } elseif (is_array($raw_uptime_log)) {
        foreach ($raw_uptime_log as $entry) {
            if (is_array($entry)) {
                $uptime_entries[] = $entry;
                continue;
            }

            $uptime_entries[] = [
                'status' => (bool) $entry,
            ];
        }
    }

    if (!empty($uptime_entries)) {
        usort($uptime_entries, static function ($a, $b) {
            $a_time = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
            $b_time = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;

            return $a_time <=> $b_time;
        });

        $last_entry = end($uptime_entries);

        if (is_array($last_entry) && array_key_exists('status', $last_entry)) {
            $status_value = $last_entry['status'];
            $status_label = __('Indéterminé', 'sitepulse');
            $status_class = 'is-warning';

            if ($status_value === true) {
                $status_label = __('En ligne', 'sitepulse');
                $status_class = 'is-success';
            } elseif ($status_value === false) {
                $status_label = __('Hors ligne', 'sitepulse');
                $status_class = 'is-critical';
            }

            $summaries['uptime_tracker'][] = [
                'label'  => __('Statut actuel', 'sitepulse'),
                'value'  => $status_label,
                'status' => $status_class,
            ];
        }

        $bool_entries = array_filter($uptime_entries, static function ($entry) {
            return isset($entry['status']) && is_bool($entry['status']);
        });
        $total_entries = count($bool_entries);

        if ($total_entries > 0) {
            $up_entries = count(array_filter($bool_entries, static function ($entry) {
                return !empty($entry['status']);
            }));
            $uptime_percent = ($up_entries / $total_entries) * 100;
            $warning_threshold = function_exists('sitepulse_get_uptime_warning_percentage')
                ? (float) sitepulse_get_uptime_warning_percentage()
                : (float) (defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0);
            $status_class = 'is-success';

            if ($uptime_percent < max(0.0, $warning_threshold - 5)) {
                $status_class = 'is-critical';
            } elseif ($uptime_percent < $warning_threshold) {
                $status_class = 'is-warning';
            }

            $summaries['uptime_tracker'][] = [
                'label'  => __('Taux de disponibilité', 'sitepulse'),
                'value'  => sprintf(__('%s %% (24h)', 'sitepulse'), number_format_i18n($uptime_percent, 1)),
                'status' => $status_class,
            ];
        }
    }

    // AI Insights – last generation run.
    $last_ai_run = (int) get_option(SITEPULSE_OPTION_AI_LAST_RUN, 0);

    if ($last_ai_run > 0) {
        $rate_limit_value = get_option(SITEPULSE_OPTION_AI_RATE_LIMIT, 'week');

        if (!is_string($rate_limit_value) || $rate_limit_value === '') {
            $rate_limit_value = 'week';
        }

        switch ($rate_limit_value) {
            case 'day':
                $rate_limit_window = DAY_IN_SECONDS;
                break;
            case 'month':
                $rate_limit_window = MONTH_IN_SECONDS;
                break;
            case 'week':
                $rate_limit_window = WEEK_IN_SECONDS;
                break;
            default:
                $rate_limit_window = 0;
        }

        $age_seconds = max(0, $now_utc - $last_ai_run);
        $status      = 'is-success';

        if ($rate_limit_window > 0) {
            if ($age_seconds > ($rate_limit_window * 2)) {
                $status = 'is-critical';
            } elseif ($age_seconds > $rate_limit_window) {
                $status = 'is-warning';
            }
        }

        $summaries['ai_insights'][] = [
            'label'  => __('Dernière exécution', 'sitepulse'),
            'value'  => sprintf(__('Il y a %s', 'sitepulse'), human_time_diff($last_ai_run, $now_utc)),
            'status' => $status,
        ];
    }

    // Error Alerts – cron warnings and last log scan.
    $cron_warnings = get_option(SITEPULSE_OPTION_CRON_WARNINGS, []);

    if (is_array($cron_warnings) && isset($cron_warnings['error_alerts'])) {
        $summaries['error_alerts'][] = [
            'label'  => __('Planification', 'sitepulse'),
            'value'  => __('Avertissement détecté', 'sitepulse'),
            'status' => 'is-warning',
        ];
    }

    $log_pointer = get_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, []);

    if (is_array($log_pointer) && isset($log_pointer['updated_at'])) {
        $pointer_time = (int) $log_pointer['updated_at'];

        if ($pointer_time > 0) {
            $age_seconds = max(0, time() - $pointer_time);
            $status      = $age_seconds > DAY_IN_SECONDS ? 'is-warning' : 'is-success';

            $summaries['error_alerts'][] = [
                'label'  => __('Dernière analyse', 'sitepulse'),
                'value'  => sprintf(__('Il y a %s', 'sitepulse'), human_time_diff($pointer_time, time())),
                'status' => $status,
            ];
        }
    }

    return $summaries;
}

require_once __DIR__ . '/admin-settings-page.php';
require_once __DIR__ . '/admin-debug-page.php';
