<?php
/**
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the settings page.
 */
function sitepulse_settings_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $test_notice       = '';
    $test_notice_class = 'updated';

    if (isset($_GET['sitepulse_alert_test'])) {
        $test_status  = sanitize_key(wp_unslash($_GET['sitepulse_alert_test']));
        $test_channel = isset($_GET['sitepulse_alert_channel']) ? sanitize_key(wp_unslash($_GET['sitepulse_alert_channel'])) : '';

        $channel_labels = [
            'email'   => esc_html__('e-mail', 'sitepulse'),
            'webhook' => esc_html__('webhook', 'sitepulse'),
        ];

        $channel_label = $test_channel !== '' && isset($channel_labels[$test_channel])
            ? $channel_labels[$test_channel]
            : esc_html__('canal', 'sitepulse');

        if ($test_status !== '') {
            if ($test_status === 'success') {
                if ($test_channel === 'email') {
                    $test_notice = esc_html__('E-mail de test envoyé. Vérifiez votre boîte de réception.', 'sitepulse');
                } elseif ($test_channel === 'webhook') {
                    $test_notice = esc_html__('Webhook de test déclenché avec succès.', 'sitepulse');
                } else {
                    $test_notice = esc_html__('Test de canal exécuté avec succès.', 'sitepulse');
                }
                $test_notice_class = 'updated';
            } elseif ($test_status === 'no_recipients') {
                $test_notice       = esc_html__('Impossible d’envoyer le test : aucun destinataire valide.', 'sitepulse');
                $test_notice_class = 'error';
            } elseif ($test_status === 'no_webhooks') {
                $test_notice       = esc_html__('Aucune URL de webhook valide n’a été trouvée pour le test.', 'sitepulse');
                $test_notice_class = 'error';
            } elseif ($test_status === 'no_channels') {
                $test_notice       = esc_html__('Impossible d’exécuter le test : aucun canal de diffusion n’est actif.', 'sitepulse');
                $test_notice_class = 'error';
            } elseif ($test_status === 'error') {
                /* translators: %s is the channel label (e-mail or webhook). */
                $test_notice       = sprintf(esc_html__('L’envoi de test pour le canal %s a échoué. Consultez les journaux ou réessayez.', 'sitepulse'), $channel_label);
                $test_notice_class = 'error';
            }
        }
    }

    $ai_secret_notice = '';

    if (isset($_GET['sitepulse_ai_secret_regenerated'])) {
        $ai_secret_notice = esc_html__('Le secret utilisé pour les analyses IA a été régénéré.', 'sitepulse');
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
    $stored_view_mode_option = get_option(
        SITEPULSE_OPTION_SETTINGS_VIEW_MODE,
        SITEPULSE_DEFAULT_SETTINGS_VIEW_MODE
    );
    $settings_view_mode = sitepulse_sanitize_settings_view_mode($stored_view_mode_option);
    $module_summaries = sitepulse_get_module_status_summaries();
    $active_modules_option = get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);
    $active_modules = array_values(array_filter(array_map('strval', (array) $active_modules_option), static function ($module) {
        return $module !== '';
    }));
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
    $essential_module_keys = ['resource_monitor', 'uptime_tracker', 'error_alerts'];
    $essential_modules_overview = [];

    foreach ($essential_module_keys as $essential_key) {
        $module_label = isset($modules_info[$essential_key]['label']) ? $modules_info[$essential_key]['label'] : ucfirst(str_replace('_', ' ', $essential_key));
        $module_page = isset($modules_info[$essential_key]['page']) ? $modules_info[$essential_key]['page'] : '';
        $module_url = '';

        if ($module_page !== '') {
            $module_url = strpos($module_page, '#') === 0 ? $module_page : admin_url('admin.php?page=' . $module_page);
        }

        $is_active = in_array($essential_key, $active_modules, true);
        $status_class = $is_active ? 'is-success' : 'is-warning';
        $status_label = $is_active ? esc_html__('Activé', 'sitepulse') : esc_html__('À activer', 'sitepulse');

        $essential_modules_overview[] = [
            'key'          => $essential_key,
            'label'        => $module_label,
            'is_active'    => $is_active,
            'status_class' => $status_class,
            'status_label' => $status_label,
            'url'          => $module_url,
        ];
    }

    $all_essential_modules_active = !empty($essential_modules_overview) && !array_filter($essential_modules_overview, static function ($module) {
        return empty($module['is_active']);
    });

    $alert_recipients_option = (array) get_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, []);
    $alert_recipients_clean = sitepulse_sanitize_alert_recipients($alert_recipients_option);
    $alert_recipients_value = implode("\n", $alert_recipients_clean);
    $enabled_alert_channels_option = (array) get_option(SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS, ['cpu', 'php_fatal']);
    $enabled_alert_channels = array_values(array_filter(array_map('strval', $enabled_alert_channels_option), static function ($channel) {
        return $channel !== '';
    }));
    $has_alert_channels = !empty($enabled_alert_channels);
    $delivery_channel_choices = sitepulse_get_error_alert_delivery_channel_choices();
    $configured_delivery_channels = sitepulse_sanitize_error_alert_delivery_channels(
        (array) get_option(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS, ['email'])
    );
    $email_delivery_enabled = in_array('email', $configured_delivery_channels, true);
    $webhook_delivery_enabled = in_array('webhook', $configured_delivery_channels, true);
    $webhook_urls_clean = sitepulse_sanitize_error_alert_webhooks(
        (array) get_option(SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS, [])
    );
    $webhook_urls_value = implode("\n", $webhook_urls_clean);
    $severity_choices = sitepulse_get_error_alert_severity_choices();
    $enabled_severities = sitepulse_sanitize_error_alert_severities(
        (array) get_option(SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES, ['warning', 'critical'])
    );
    $has_alert_recipients = !$email_delivery_enabled || !empty($alert_recipients_clean);
    $has_webhook_targets = !$webhook_delivery_enabled || !empty($webhook_urls_clean);
    $has_alerts_configured = $has_alert_channels && $has_alert_recipients && $has_webhook_targets;

    $next_steps_overview = [
        [
            'key'          => 'modules',
            'label'        => esc_html__('Activer les modules essentiels', 'sitepulse'),
            'description'  => esc_html__('Activez Resource Monitor, Uptime Tracker et Error Alerts pour bénéficier du socle de surveillance.', 'sitepulse'),
            'is_complete'  => $all_essential_modules_active,
            'target'       => 'sitepulse-tab-modules',
            'href'         => '#sitepulse-section-modules',
        ],
        [
            'key'          => 'alerts',
            'label'        => esc_html__('Configurer les alertes critiques', 'sitepulse'),
            'description'  => esc_html__('Sélectionnez les canaux et configurez les destinataires (e-mails ou webhooks) pour recevoir les notifications.', 'sitepulse'),
            'is_complete'  => $has_alerts_configured,
            'target'       => 'sitepulse-tab-alerts',
            'href'         => '#sitepulse-section-alerts',
        ],
        [
            'key'          => 'ai',
            'label'        => esc_html__('Ajouter la clé IA Gemini', 'sitepulse'),
            'description'  => esc_html__('Renseignez votre clé API pour débloquer les recommandations générées par l’IA.', 'sitepulse'),
            'is_complete'  => $has_effective_gemini_api_key,
            'target'       => 'sitepulse-tab-ai',
            'href'         => '#sitepulse-section-api',
        ],
    ];

    $transient_purge_entries = function_exists('sitepulse_get_transient_purge_log')
        ? sitepulse_get_transient_purge_log(5)
        : [];

    $transient_purge_summary = function_exists('sitepulse_calculate_transient_purge_summary')
        ? sitepulse_calculate_transient_purge_summary($transient_purge_entries)
        : [
            'totals'       => ['deleted' => 0, 'unique' => 0, 'batches' => 0],
            'latest'       => null,
            'top_prefixes' => [],
        ];

    $async_jobs_overview = function_exists('sitepulse_prepare_async_jobs_overview')
        ? sitepulse_prepare_async_jobs_overview(null, ['include_logs' => true, 'limit' => 4])
        : [];

    $async_jobs_state = 'idle';

    foreach ($async_jobs_overview as $overview_entry) {
        if (!empty($overview_entry['is_active'])) {
            $async_jobs_state = 'busy';
            break;
        }
    }

    $async_jobs_initial_json = '';

    $encoded_async_jobs = function_exists('wp_json_encode')
        ? wp_json_encode($async_jobs_overview)
        : json_encode($async_jobs_overview);

    if (is_string($encoded_async_jobs)) {
        $async_jobs_initial_json = $encoded_async_jobs;
    }

    $debug_mode_option = get_option(SITEPULSE_OPTION_DEBUG_MODE);
    $is_debug_mode_enabled = rest_sanitize_boolean($debug_mode_option);
    $uptime_url_option = get_option(SITEPULSE_OPTION_UPTIME_URL, '');
    $uptime_url = '';

    if (is_string($uptime_url_option)) {
        $uptime_url = trim($uptime_url_option);
    }

    $uptime_timeout_option = get_option(SITEPULSE_OPTION_UPTIME_TIMEOUT, SITEPULSE_DEFAULT_UPTIME_TIMEOUT);
    $uptime_timeout = sitepulse_sanitize_uptime_timeout($uptime_timeout_option);
    $uptime_frequency_option = get_option(SITEPULSE_OPTION_UPTIME_FREQUENCY, sitepulse_get_default_uptime_frequency());
    $uptime_frequency = sitepulse_sanitize_uptime_frequency($uptime_frequency_option);
    $uptime_http_method_option = get_option(SITEPULSE_OPTION_UPTIME_HTTP_METHOD, SITEPULSE_DEFAULT_UPTIME_HTTP_METHOD);
    $uptime_http_method = sitepulse_sanitize_uptime_http_method($uptime_http_method_option);
    $uptime_headers_option = get_option(SITEPULSE_OPTION_UPTIME_HTTP_HEADERS, []);
    $uptime_headers = sitepulse_sanitize_uptime_http_headers($uptime_headers_option);
    $uptime_headers_lines = [];

    foreach ($uptime_headers as $header_name => $header_value) {
        $uptime_headers_lines[] = $header_value === ''
            ? $header_name
            : $header_name . ': ' . $header_value;
    }

    $uptime_headers_text = implode("\n", $uptime_headers_lines);
    $uptime_expected_codes_option = get_option(SITEPULSE_OPTION_UPTIME_EXPECTED_CODES, []);
    $uptime_expected_codes = sitepulse_sanitize_uptime_expected_codes($uptime_expected_codes_option);
    $uptime_expected_codes_text = implode(', ', $uptime_expected_codes);
    $uptime_latency_threshold_option = get_option(
        SITEPULSE_OPTION_UPTIME_LATENCY_THRESHOLD,
        SITEPULSE_DEFAULT_UPTIME_LATENCY_THRESHOLD
    );
    $uptime_latency_threshold = sitepulse_sanitize_uptime_latency_threshold($uptime_latency_threshold_option);
    $uptime_keyword_option = get_option(SITEPULSE_OPTION_UPTIME_KEYWORD, '');
    $uptime_keyword = sitepulse_sanitize_uptime_keyword($uptime_keyword_option);
    $uptime_history_retention_option = get_option(
        SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS,
        sitepulse_get_default_uptime_history_retention_days()
    );
    $uptime_history_retention = sitepulse_sanitize_uptime_history_retention($uptime_history_retention_option);
    $uptime_maintenance_windows_option = get_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS, []);
    $uptime_maintenance_windows = sitepulse_sanitize_uptime_maintenance_windows($uptime_maintenance_windows_option);
    $uptime_maintenance_rows_to_render = max(count($uptime_maintenance_windows) + 1, 1);
    $uptime_maintenance_rows_to_render = min($uptime_maintenance_rows_to_render, 6);
    $uptime_agents_config = function_exists('sitepulse_uptime_get_agents')
        ? sitepulse_uptime_get_agents()
        : [];

    if (!is_array($uptime_agents_config)) {
        $uptime_agents_config = [];
    }

    $uptime_agents_choices = ['all' => __('Tous les agents', 'sitepulse')];
    $uptime_agents_rows = [];

    foreach ($uptime_agents_config as $agent_id => $agent_data) {
        $agent_key = sanitize_key($agent_id);

        if ($agent_key === '') {
            continue;
        }

        $agent_label = isset($agent_data['label']) && is_string($agent_data['label'])
            ? $agent_data['label']
            : ucfirst(str_replace('_', ' ', $agent_key));

        $uptime_agents_choices[$agent_key] = $agent_label;

        $uptime_agents_rows[] = [
            'id'      => $agent_id,
            'label'   => $agent_label,
            'region'  => isset($agent_data['region']) ? sanitize_key($agent_data['region']) : 'global',
            'url'     => isset($agent_data['url']) ? (string) $agent_data['url'] : '',
            'timeout' => isset($agent_data['timeout']) && is_numeric($agent_data['timeout'])
                ? (int) $agent_data['timeout']
                : '',
            'weight'  => isset($agent_data['weight']) && is_numeric($agent_data['weight'])
                ? (float) $agent_data['weight']
                : 1.0,
            'active'  => !isset($agent_data['active']) || (bool) $agent_data['active'],
        ];
    }

    $uptime_agents_rows_to_render = count($uptime_agents_rows) + 1;
    $uptime_agents_rows_to_render = max(1, min($uptime_agents_rows_to_render, 12));

    $uptime_day_choices = [
        1 => __('Lundi', 'sitepulse'),
        2 => __('Mardi', 'sitepulse'),
        3 => __('Mercredi', 'sitepulse'),
        4 => __('Jeudi', 'sitepulse'),
        5 => __('Vendredi', 'sitepulse'),
        6 => __('Samedi', 'sitepulse'),
        7 => __('Dimanche', 'sitepulse'),
    ];

    $uptime_recurrence_choices = [
        'weekly'  => __('Hebdomadaire', 'sitepulse'),
        'daily'   => __('Quotidienne', 'sitepulse'),
        'one_off' => __('Ponctuelle', 'sitepulse'),
    ];

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

    $raw_speed_benchmarks = get_option(
        SITEPULSE_OPTION_SPEED_BENCHMARKS,
        [
            'competitors' => [],
            'budgets'     => [],
        ]
    );

    $speed_benchmark_settings = sitepulse_sanitize_speed_benchmarks($raw_speed_benchmarks);
    $speed_benchmark_competitors = $speed_benchmark_settings['competitors'];
    $speed_benchmark_budgets = $speed_benchmark_settings['budgets'];
    $speed_benchmark_competitors_value = '';

    if (!empty($speed_benchmark_competitors)) {
        $speed_benchmark_competitors_value = implode(
            "\n",
            array_map(
                static function ($competitor) {
                    return isset($competitor['url']) ? (string) $competitor['url'] : '';
                },
                $speed_benchmark_competitors
            )
        );
    }

    $speed_profiles_catalog = function_exists('sitepulse_speed_analyzer_get_profile_catalog')
        ? sitepulse_speed_analyzer_get_profile_catalog()
        : ['default' => ['label' => __('Standard', 'sitepulse')]];

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
            delete_option(SITEPULSE_OPTION_UPTIME_HTTP_HEADERS);
            delete_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_NOTICES);
            delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Données stockées effacées.', 'sitepulse') . '</p></div>';
        }
        if (isset($_POST['sitepulse_reset_all'])) {
            $reset_confirmed = isset($_POST['sitepulse_confirm_reset']) && (string) wp_unslash($_POST['sitepulse_confirm_reset']) === '1';

            if (!$reset_confirmed) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('La réinitialisation a été annulée. Cochez la case de confirmation pour continuer.', 'sitepulse') . '</p></div>';
            } else {
            $options_to_delete = [
                SITEPULSE_OPTION_ACTIVE_MODULES,
                SITEPULSE_OPTION_DEBUG_MODE,
                SITEPULSE_OPTION_GEMINI_API_KEY,
                SITEPULSE_OPTION_UPTIME_LOG,
                SITEPULSE_OPTION_UPTIME_URL,
                SITEPULSE_OPTION_UPTIME_TIMEOUT,
                SITEPULSE_OPTION_UPTIME_FREQUENCY,
                SITEPULSE_OPTION_UPTIME_HTTP_METHOD,
                SITEPULSE_OPTION_UPTIME_HTTP_HEADERS,
                SITEPULSE_OPTION_UPTIME_MAINTENANCE_NOTICES,
                SITEPULSE_OPTION_UPTIME_EXPECTED_CODES,
                SITEPULSE_OPTION_LAST_LOAD_TIME,
                SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS,
                SITEPULSE_OPTION_CPU_ALERT_THRESHOLD,
                SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD,
                SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES,
                SITEPULSE_OPTION_ALERT_INTERVAL,
                SITEPULSE_OPTION_ALERT_RECIPIENTS,
                SITEPULSE_OPTION_SPEED_WARNING_MS,
                SITEPULSE_OPTION_SPEED_CRITICAL_MS,
                SITEPULSE_OPTION_IMPACT_THRESHOLDS,
                SITEPULSE_OPTION_UPTIME_WARNING_PERCENT,
                SITEPULSE_OPTION_REVISION_LIMIT,
                SITEPULSE_PLUGIN_IMPACT_OPTION,
            ];

            $transients_to_delete = [
                SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS,
                SITEPULSE_TRANSIENT_AI_INSIGHT,
                SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK,
                SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK,
            ];

            $transient_prefixes_to_delete = [SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX];
            $cron_hooks = function_exists('sitepulse_get_cron_hooks') ? sitepulse_get_cron_hooks() : [];
            $log_path = defined('SITEPULSE_DEBUG_LOG') ? SITEPULSE_DEBUG_LOG : '';

            $job_scheduled = false;

            if (function_exists('sitepulse_enqueue_async_job')) {
                $job = sitepulse_enqueue_async_job(
                    'plugin_reset',
                    [
                        'options'    => $options_to_delete,
                        'transients' => $transients_to_delete,
                        'prefixes'   => $transient_prefixes_to_delete,
                        'cron_hooks' => $cron_hooks,
                        'log_path'   => $log_path,
                    ],
                    [
                        'label'        => __('Réinitialisation de SitePulse', 'sitepulse'),
                        'requested_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
                    ]
                );

                if (is_array($job)) {
                    $job_scheduled = true;
                }
            }

            if ($job_scheduled) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Réinitialisation planifiée. Le nettoyage complet s’exécute en tâche de fond et restaurera la configuration par défaut.', 'sitepulse') . '</p></div>';
            } else {
                $reset_success = true;
                $log_deletion_failed = false;

                foreach ($options_to_delete as $option_key) {
                    delete_option($option_key);
                }

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

                if ($log_path !== '' && file_exists($log_path)) {
                    $log_deleted = false;
                    $delete_error_message = '';

                    if (function_exists('wp_delete_file')) {
                        $delete_result = wp_delete_file($log_path);

                        if (function_exists('is_wp_error') && is_wp_error($delete_result)) {
                            $delete_error_message = $delete_result->get_error_message();
                        } elseif ($delete_result === false) {
                            $delete_error_message = 'wp_delete_file returned false.';
                        }

                        if (!file_exists($log_path)) {
                            $log_deleted = true;
                        }
                    }

                    if (!$log_deleted) {
                        if (@unlink($log_path)) {
                            $log_deleted = true;
                        } elseif ($delete_error_message === '') {
                            $delete_error_message = 'unlink failed.';
                        }
                    }

                    if (!$log_deleted) {
                        $reset_success = false;
                        $log_deletion_failed = true;
                        $log_message = sprintf('SitePulse: impossible de supprimer le journal de débogage (%s). %s', $log_path, $delete_error_message);

                        if (function_exists('sitepulse_log')) {
                            sitepulse_log($log_message, 'ERROR');
                        } else {
                            error_log($log_message);
                        }
                    }
                }

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
        }
    }

    if ($test_notice !== '') {
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($test_notice_class), $test_notice);
    }

    if ($ai_secret_notice !== '') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($ai_secret_notice) . '</p></div>';
    }
    ?>
    <div
        class="wrap sitepulse-settings-wrap"
        data-sitepulse-settings-wrap
        data-sitepulse-view-mode="<?php echo esc_attr($settings_view_mode); ?>"
    >
        <h1><?php esc_html_e('Réglages de SitePulse', 'sitepulse'); ?></h1>
        <p class="sitepulse-settings-intro"><?php esc_html_e('Activez les modules qui vous intéressent et ajustez les seuils clés pour votre surveillance.', 'sitepulse'); ?></p>
        <div class="sitepulse-settings-live-region" aria-live="polite" aria-atomic="true" data-sitepulse-live-region="polite"></div>
        <div class="sitepulse-settings-live-region sitepulse-settings-live-region--assertive" aria-live="assertive" aria-atomic="true" data-sitepulse-live-region="assertive"></div>
        <section
            class="sitepulse-settings-mode-toggle"
            aria-label="<?php esc_attr_e('Choisir le mode d’affichage des réglages', 'sitepulse'); ?>"
            data-sitepulse-view-announce-simple="<?php esc_attr_e('Mode guidé activé : seules les options essentielles sont visibles.', 'sitepulse'); ?>"
            data-sitepulse-view-announce-expert="<?php esc_attr_e('Mode expert activé : toutes les options sont affichées.', 'sitepulse'); ?>"
        >
            <div class="sitepulse-settings-mode-toggle__content">
                <div class="sitepulse-settings-mode-toggle__intro">
                    <h2 class="sitepulse-settings-mode-toggle__title"><?php esc_html_e('Mode d’affichage', 'sitepulse'); ?></h2>
                    <p class="sitepulse-settings-mode-toggle__description">
                        <?php esc_html_e('Passez du mode guidé au mode expert pour révéler l’ensemble des réglages disponibles.', 'sitepulse'); ?>
                    </p>
                </div>
                <fieldset class="sitepulse-view-mode-fieldset" data-sitepulse-view-controls>
                    <legend class="sitepulse-view-mode-fieldset__legend"><?php esc_html_e('Afficher', 'sitepulse'); ?></legend>
                    <div class="sitepulse-view-mode-fieldset__options">
                        <label class="sitepulse-view-mode-option">
                            <input
                                type="radio"
                                name="<?php echo esc_attr(SITEPULSE_OPTION_SETTINGS_VIEW_MODE); ?>"
                                value="simple"
                                <?php checked('simple' === $settings_view_mode); ?>
                                class="screen-reader-text sitepulse-view-mode-option__input"
                                data-sitepulse-view-control
                            >
                            <span class="sitepulse-view-mode-option__label"><?php esc_html_e('Mode guidé', 'sitepulse'); ?></span>
                            <span class="sitepulse-view-mode-option__hint"><?php esc_html_e('Idéal pour activer l’essentiel en quelques clics.', 'sitepulse'); ?></span>
                        </label>
                        <label class="sitepulse-view-mode-option">
                            <input
                                type="radio"
                                name="<?php echo esc_attr(SITEPULSE_OPTION_SETTINGS_VIEW_MODE); ?>"
                                value="expert"
                                <?php checked('expert' === $settings_view_mode); ?>
                                class="screen-reader-text sitepulse-view-mode-option__input"
                                data-sitepulse-view-control
                            >
                            <span class="sitepulse-view-mode-option__label"><?php esc_html_e('Mode expert', 'sitepulse'); ?></span>
                            <span class="sitepulse-view-mode-option__hint"><?php esc_html_e('Affiche tous les paramètres avancés et les réglages fins.', 'sitepulse'); ?></span>
                        </label>
                    </div>
                </fieldset>
            </div>
        </section>
        <div class="sitepulse-settings-layout">
            <nav class="sitepulse-settings-toc" aria-label="<?php esc_attr_e('Sommaire des réglages', 'sitepulse'); ?>">
                <h2 class="screen-reader-text"><?php esc_html_e('Sommaire des réglages', 'sitepulse'); ?></h2>
                <ul role="tablist">
                    <li><a class="sitepulse-toc-link sitepulse-tab-trigger" data-tab-target="sitepulse-tab-overview" href="#sitepulse-section-overview" aria-current="page" tabindex="0"><?php esc_html_e('Vue d’ensemble', 'sitepulse'); ?></a></li>
                    <li><a class="sitepulse-toc-link sitepulse-tab-trigger" data-tab-target="sitepulse-tab-ai" href="#sitepulse-section-api" aria-current="false" tabindex="-1"><?php esc_html_e('Connexion IA', 'sitepulse'); ?></a></li>
                    <li><a class="sitepulse-toc-link sitepulse-tab-trigger" data-tab-target="sitepulse-tab-ai" href="#sitepulse-section-ai" aria-current="false" tabindex="-1"><?php esc_html_e('Réglages IA', 'sitepulse'); ?></a></li>
                    <li><a class="sitepulse-toc-link sitepulse-tab-trigger" data-tab-target="sitepulse-tab-performance" href="#sitepulse-section-performance" aria-current="false" tabindex="-1"><?php esc_html_e('Performances', 'sitepulse'); ?></a></li>
                    <li><a class="sitepulse-toc-link sitepulse-tab-trigger" data-tab-target="sitepulse-tab-modules" href="#sitepulse-section-modules" aria-current="false" tabindex="-1"><?php esc_html_e('Modules', 'sitepulse'); ?></a></li>
                    <li><a class="sitepulse-toc-link sitepulse-tab-trigger" data-tab-target="sitepulse-tab-alerts" href="#sitepulse-section-alerts" aria-current="false" tabindex="-1"><?php esc_html_e('Alertes', 'sitepulse'); ?></a></li>
                    <li><a class="sitepulse-toc-link sitepulse-tab-trigger" data-tab-target="sitepulse-tab-uptime" href="#sitepulse-section-uptime" aria-current="false" tabindex="-1"><?php esc_html_e('Disponibilité', 'sitepulse'); ?></a></li>
                    <li><a class="sitepulse-toc-link sitepulse-tab-trigger" data-tab-target="sitepulse-tab-maintenance" href="#sitepulse-section-maintenance" aria-current="false" tabindex="-1"><?php esc_html_e('Maintenance', 'sitepulse'); ?></a></li>
                </ul>
            </nav>
            <div class="sitepulse-settings-content">
                <section class="sitepulse-overview-callout" aria-label="<?php esc_attr_e('Vue d’ensemble des réglages SitePulse', 'sitepulse'); ?>">
                    <div class="sitepulse-overview-callout__icon" aria-hidden="true">
                        <span class="dashicons dashicons-visibility"></span>
                    </div>
                    <div class="sitepulse-overview-callout__content">
                        <header class="sitepulse-overview-callout__header">
                            <h2 class="sitepulse-overview-callout__title"><?php esc_html_e('Vue d’ensemble rapide', 'sitepulse'); ?></h2>
                            <?php if ($all_essential_modules_active && $has_alerts_configured && $has_effective_gemini_api_key) : ?>
                                <span class="sitepulse-overview-callout__badge is-complete"><?php esc_html_e('Configuration prête', 'sitepulse'); ?></span>
                            <?php else : ?>
                                <span class="sitepulse-overview-callout__badge is-progress"><?php esc_html_e('Actions recommandées', 'sitepulse'); ?></span>
                            <?php endif; ?>
                        </header>
                        <div class="sitepulse-overview-callout__body">
                            <div class="sitepulse-overview-callout__section">
                                <h3 class="sitepulse-overview-callout__section-title"><?php esc_html_e('Modules essentiels', 'sitepulse'); ?></h3>
                                <ul class="sitepulse-overview-callout__status-list">
                                    <?php foreach ($essential_modules_overview as $essential_module) : ?>
                                        <li class="sitepulse-overview-callout__status-item">
                                            <span class="sitepulse-status <?php echo esc_attr($essential_module['status_class']); ?>"><?php echo esc_html($essential_module['status_label']); ?></span>
                                            <span class="sitepulse-overview-callout__status-label"><?php echo esc_html($essential_module['label']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="sitepulse-overview-callout__section">
                                <h3 class="sitepulse-overview-callout__section-title"><?php esc_html_e('Prochaines étapes', 'sitepulse'); ?></h3>
                                <ul class="sitepulse-overview-callout__status-list">
                                    <?php foreach ($next_steps_overview as $step) :
                                        $step_status_class = $step['is_complete'] ? 'is-success' : 'is-warning';
                                        $step_status_label = $step['is_complete'] ? esc_html__('Terminé', 'sitepulse') : esc_html__('À faire', 'sitepulse');
                                    ?>
                                        <li class="sitepulse-overview-callout__status-item">
                                            <span class="sitepulse-status <?php echo esc_attr($step_status_class); ?>"><?php echo esc_html($step_status_label); ?></span>
                                            <span class="sitepulse-overview-callout__status-label"><?php echo esc_html($step['label']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <footer class="sitepulse-overview-callout__footer">
                            <?php foreach ($next_steps_overview as $step) :
                                $link_classes = ['sitepulse-overview-callout__action', 'sitepulse-tab-trigger', 'button'];
                                if ($step['is_complete']) {
                                    $link_classes[] = 'button-secondary';
                                } else {
                                    $link_classes[] = 'button-primary';
                                }
                            ?>
                                <a class="<?php echo esc_attr(implode(' ', $link_classes)); ?>" data-tab-target="<?php echo esc_attr($step['target']); ?>" href="<?php echo esc_url($step['href']); ?>"><?php echo esc_html($step['label']); ?></a>
                            <?php endforeach; ?>
                        </footer>
                    </div>
                </section>
                <section class="sitepulse-guided-checklist" data-sitepulse-view="simple">
                    <h2 class="sitepulse-guided-checklist__title"><?php esc_html_e('Configuration guidée', 'sitepulse'); ?></h2>
                    <p class="sitepulse-guided-checklist__intro"><?php esc_html_e('Suivez ces étapes prioritaires pour activer la supervision de base avant d’affiner les réglages.', 'sitepulse'); ?></p>
                    <ol class="sitepulse-guided-checklist__list">
                        <?php foreach ($next_steps_overview as $step) :
                            $item_status      = $step['is_complete'] ? 'done' : 'todo';
                            $status_label     = $step['is_complete'] ? esc_html__('Terminé', 'sitepulse') : esc_html__('À faire', 'sitepulse');
                            $action_label     = $step['is_complete'] ? esc_html__('Revoir', 'sitepulse') : esc_html__('Configurer', 'sitepulse');
                            $item_classes     = ['sitepulse-guided-checklist__item', 'sitepulse-guided-checklist__item--' . $item_status];
                            $status_classes   = ['sitepulse-guided-checklist__status', 'sitepulse-guided-checklist__status--' . $item_status];
                        ?>
                            <li class="<?php echo esc_attr(implode(' ', $item_classes)); ?>">
                                <div class="sitepulse-guided-checklist__item-header">
                                    <span class="<?php echo esc_attr(implode(' ', $status_classes)); ?>"><?php echo esc_html($status_label); ?></span>
                                    <span class="sitepulse-guided-checklist__label"><?php echo esc_html($step['label']); ?></span>
                                </div>
                                <p class="sitepulse-guided-checklist__description"><?php echo esc_html($step['description']); ?></p>
                                <div class="sitepulse-guided-checklist__actions">
                                    <?php
                                    $action_classes = ['button', 'sitepulse-guided-checklist__action', 'sitepulse-tab-trigger'];

                                    if ($step['is_complete']) {
                                        $action_classes[] = 'button-secondary';
                                    } else {
                                        $action_classes[] = 'button-primary';
                                    }
                                    ?>
                                    <a
                                        class="<?php echo esc_attr(implode(' ', $action_classes)); ?>"
                                        data-tab-target="<?php echo esc_attr($step['target']); ?>"
                                        href="<?php echo esc_url($step['href']); ?>"
                                        data-sitepulse-guided-link
                                    ><?php echo esc_html($action_label); ?></a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
                <div class="sitepulse-settings-tabs-container">
                    <h2 class="nav-tab-wrapper sitepulse-settings-tabs" role="tablist">
                        <a id="sitepulse-tab-overview-label" class="nav-tab sitepulse-tab-link nav-tab-active" href="#sitepulse-tab-overview" data-tab-target="sitepulse-tab-overview" role="tab" aria-controls="sitepulse-tab-overview" aria-selected="true" tabindex="0"><?php esc_html_e('Vue d’ensemble', 'sitepulse'); ?></a>
                        <a id="sitepulse-tab-ai-label" class="nav-tab sitepulse-tab-link" href="#sitepulse-tab-ai" data-tab-target="sitepulse-tab-ai" role="tab" aria-controls="sitepulse-tab-ai" aria-selected="false" tabindex="-1"><?php esc_html_e('IA', 'sitepulse'); ?></a>
                        <a id="sitepulse-tab-performance-label" class="nav-tab sitepulse-tab-link" href="#sitepulse-tab-performance" data-tab-target="sitepulse-tab-performance" role="tab" aria-controls="sitepulse-tab-performance" aria-selected="false" tabindex="-1"><?php esc_html_e('Performances', 'sitepulse'); ?></a>
                        <a id="sitepulse-tab-modules-label" class="nav-tab sitepulse-tab-link" href="#sitepulse-tab-modules" data-tab-target="sitepulse-tab-modules" role="tab" aria-controls="sitepulse-tab-modules" aria-selected="false" tabindex="-1"><?php esc_html_e('Modules', 'sitepulse'); ?></a>
                        <a id="sitepulse-tab-alerts-label" class="nav-tab sitepulse-tab-link" href="#sitepulse-tab-alerts" data-tab-target="sitepulse-tab-alerts" role="tab" aria-controls="sitepulse-tab-alerts" aria-selected="false" tabindex="-1"><?php esc_html_e('Alertes', 'sitepulse'); ?></a>
                        <a id="sitepulse-tab-uptime-label" class="nav-tab sitepulse-tab-link" href="#sitepulse-tab-uptime" data-tab-target="sitepulse-tab-uptime" role="tab" aria-controls="sitepulse-tab-uptime" aria-selected="false" tabindex="-1"><?php esc_html_e('Disponibilité', 'sitepulse'); ?></a>
                        <a id="sitepulse-tab-maintenance-label" class="nav-tab sitepulse-tab-link" href="#sitepulse-tab-maintenance" data-tab-target="sitepulse-tab-maintenance" role="tab" aria-controls="sitepulse-tab-maintenance" aria-selected="false" tabindex="-1"><?php esc_html_e('Maintenance', 'sitepulse'); ?></a>
                    </h2>
                    <div class="sitepulse-tab-panels">
                        <form id="sitepulse-ai-secret-regen-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="screen-reader-text">
                            <input type="hidden" name="action" value="sitepulse_regenerate_ai_secret">
                            <?php wp_nonce_field('sitepulse_regenerate_ai_secret', 'sitepulse_regenerate_ai_secret_nonce'); ?>
                        </form>
                        <form method="post" action="options.php" class="sitepulse-settings-form">
                            <?php settings_fields('sitepulse_settings'); do_settings_sections('sitepulse_settings'); ?>
            <div class="sitepulse-tab-panel" id="sitepulse-tab-overview" role="tabpanel" aria-labelledby="sitepulse-tab-overview-label" tabindex="0">
                <div class="sitepulse-settings-section" id="sitepulse-section-overview">
                    <h2><?php esc_html_e('Vue d’ensemble', 'sitepulse'); ?></h2>
                    <p class="sitepulse-section-intro"><?php esc_html_e('Prenez en main SitePulse en suivant les étapes clés ci-dessous.', 'sitepulse'); ?></p>
                    <div class="sitepulse-settings-grid">
                        <div class="sitepulse-module-card">
                            <div class="sitepulse-card-header">
                                <h3 class="sitepulse-card-title"><?php esc_html_e('Modules essentiels', 'sitepulse'); ?></h3>
                            </div>
                            <div class="sitepulse-card-body">
                                <ul class="sitepulse-overview-callout__status-list">
                                    <?php foreach ($essential_modules_overview as $essential_module) : ?>
                                        <li class="sitepulse-overview-callout__status-item">
                                            <span class="sitepulse-status <?php echo esc_attr($essential_module['status_class']); ?>"><?php echo esc_html($essential_module['status_label']); ?></span>
                                            <span class="sitepulse-overview-callout__status-label"><?php echo esc_html($essential_module['label']); ?></span>
                                            <?php if ($essential_module['url'] !== '') : ?>
                                                <a class="sitepulse-overview-callout__inline-link sitepulse-tab-trigger" data-tab-target="sitepulse-tab-modules" href="<?php echo esc_url($essential_module['url']); ?>"><?php esc_html_e('Afficher', 'sitepulse'); ?></a>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="sitepulse-module-card sitepulse-module-card--setting">
                            <div class="sitepulse-card-header">
                                <h3 class="sitepulse-card-title"><?php esc_html_e('Plan d’action', 'sitepulse'); ?></h3>
                            </div>
                            <div class="sitepulse-card-body">
                                <ul class="sitepulse-overview-callout__status-list">
                                    <?php foreach ($next_steps_overview as $step) :
                                        $step_status_class = $step['is_complete'] ? 'is-success' : 'is-warning';
                                        $step_status_label = $step['is_complete'] ? esc_html__('Terminé', 'sitepulse') : esc_html__('À faire', 'sitepulse');
                                    ?>
                                        <li class="sitepulse-overview-callout__status-item">
                                            <span class="sitepulse-status <?php echo esc_attr($step_status_class); ?>"><?php echo esc_html($step_status_label); ?></span>
                                            <div class="sitepulse-overview-callout__status-content">
                                                <span class="sitepulse-overview-callout__status-label"><?php echo esc_html($step['label']); ?></span>
                                                <p class="sitepulse-overview-callout__description"><?php echo esc_html($step['description']); ?></p>
                                                <a class="sitepulse-overview-callout__inline-link sitepulse-tab-trigger" data-tab-target="<?php echo esc_attr($step['target']); ?>" href="<?php echo esc_url($step['href']); ?>"><?php esc_html_e('Passer à l’action', 'sitepulse'); ?></a>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sitepulse-tab-panel" id="sitepulse-tab-ai" role="tabpanel" aria-labelledby="sitepulse-tab-ai-label" tabindex="0">
                <div class="sitepulse-settings-section" id="sitepulse-section-api">
                <h2><?php esc_html_e("Paramètres de l'API", 'sitepulse'); ?></h2>
                <div class="sitepulse-settings-grid">
                    <div class="sitepulse-module-card sitepulse-module-card--setting" data-sitepulse-view="expert">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Connexion à Google Gemini', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_GEMINI_API_KEY); ?>"><?php esc_html_e('Clé API Google Gemini', 'sitepulse'); ?></label>
                            <label class="screen-reader-text" for="sitepulse_gemini_username"><?php esc_html_e("Nom d’utilisateur associé", 'sitepulse'); ?></label>
                            <input type="text" id="sitepulse_gemini_username" name="sitepulse_gemini_username" value="" class="sitepulse-hidden-username-field" autocomplete="username" tabindex="-1">
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
                    <div class="sitepulse-module-card sitepulse-module-card--setting" data-sitepulse-view="expert">
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
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Agents de surveillance', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description"><?php esc_html_e('Déclarez les points de présence utilisés pour contrôler la disponibilité. Les agents inactifs ne seront plus planifiés et sont exclus des calculs globaux.', 'sitepulse'); ?></p>
                            <table class="widefat striped sitepulse-uptime-agents-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><?php esc_html_e('Actif', 'sitepulse'); ?></th>
                                        <th scope="col"><?php esc_html_e('Libellé', 'sitepulse'); ?></th>
                                        <th scope="col"><?php esc_html_e('Région', 'sitepulse'); ?></th>
                                        <th scope="col"><?php esc_html_e('URL spécifique', 'sitepulse'); ?></th>
                                        <th scope="col"><?php esc_html_e('Délai dédié (s)', 'sitepulse'); ?></th>
                                        <th scope="col"><?php esc_html_e('Poids', 'sitepulse'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($index = 0; $index < $uptime_agents_rows_to_render; $index++) :
                                        $agent_row = isset($uptime_agents_rows[$index]) ? $uptime_agents_rows[$index] : [
                                            'id'      => '',
                                            'label'   => '',
                                            'region'  => 'global',
                                            'url'     => '',
                                            'timeout' => '',
                                            'weight'  => 1.0,
                                            'active'  => true,
                                        ];
                                        $row_prefix = SITEPULSE_OPTION_UPTIME_AGENTS . '[' . $index . ']';
                                        $timeout_value = '' !== $agent_row['timeout'] ? (int) $agent_row['timeout'] : '';
                                        $weight_value = is_numeric($agent_row['weight']) ? (float) $agent_row['weight'] : 1.0;
                                    ?>
                                        <tr>
                                            <td class="sitepulse-uptime-agent-active">
                                                <label class="screen-reader-text" for="<?php echo esc_attr($row_prefix . '[active]'); ?>">
                                                    <?php esc_html_e('Activer l’agent', 'sitepulse'); ?>
                                                </label>
                                                <input type="checkbox" id="<?php echo esc_attr($row_prefix . '[active]'); ?>" name="<?php echo esc_attr($row_prefix . '[active]'); ?>" value="1" <?php checked(!empty($agent_row['active'])); ?>>
                                                <input type="hidden" name="<?php echo esc_attr($row_prefix . '[id]'); ?>" value="<?php echo esc_attr($agent_row['id']); ?>">
                                            </td>
                                            <td>
                                                <label class="screen-reader-text" for="<?php echo esc_attr($row_prefix . '[label]'); ?>"><?php esc_html_e('Libellé de l’agent', 'sitepulse'); ?></label>
                                                <input type="text" id="<?php echo esc_attr($row_prefix . '[label]'); ?>" name="<?php echo esc_attr($row_prefix . '[label]'); ?>" value="<?php echo esc_attr($agent_row['label']); ?>" class="regular-text" placeholder="<?php esc_attr_e('Ex. Paris (FR)', 'sitepulse'); ?>">
                                                <?php if (!empty($agent_row['id'])) : ?>
                                                    <p class="description"><?php printf(esc_html__('Identifiant : %s', 'sitepulse'), esc_html($agent_row['id'])); ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <label class="screen-reader-text" for="<?php echo esc_attr($row_prefix . '[region]'); ?>"><?php esc_html_e('Région de l’agent', 'sitepulse'); ?></label>
                                                <input type="text" id="<?php echo esc_attr($row_prefix . '[region]'); ?>" name="<?php echo esc_attr($row_prefix . '[region]'); ?>" value="<?php echo esc_attr($agent_row['region']); ?>" class="regular-text" maxlength="32" placeholder="<?php esc_attr_e('ex. eu-west', 'sitepulse'); ?>">
                                            </td>
                                            <td>
                                                <label class="screen-reader-text" for="<?php echo esc_attr($row_prefix . '[url]'); ?>"><?php esc_html_e('URL spécifique pour cet agent', 'sitepulse'); ?></label>
                                                <input type="url" id="<?php echo esc_attr($row_prefix . '[url]'); ?>" name="<?php echo esc_attr($row_prefix . '[url]'); ?>" value="<?php echo esc_attr($agent_row['url']); ?>" class="regular-text" placeholder="<?php echo esc_attr__('https://example.com/status', 'sitepulse'); ?>">
                                            </td>
                                            <td>
                                                <label class="screen-reader-text" for="<?php echo esc_attr($row_prefix . '[timeout]'); ?>"><?php esc_html_e('Délai maximum pour cet agent', 'sitepulse'); ?></label>
                                                <input type="number" min="1" id="<?php echo esc_attr($row_prefix . '[timeout]'); ?>" name="<?php echo esc_attr($row_prefix . '[timeout]'); ?>" value="<?php echo '' === $timeout_value ? '' : esc_attr($timeout_value); ?>" class="small-text" placeholder="—">
                                            </td>
                                            <td>
                                                <label class="screen-reader-text" for="<?php echo esc_attr($row_prefix . '[weight]'); ?>"><?php esc_html_e('Poids de l’agent', 'sitepulse'); ?></label>
                                                <input type="number" min="0" step="0.1" id="<?php echo esc_attr($row_prefix . '[weight]'); ?>" name="<?php echo esc_attr($row_prefix . '[weight]'); ?>" value="<?php echo esc_attr($weight_value); ?>" class="small-text">
                                            </td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                            <p class="description"><?php esc_html_e('Laissez une ligne vide pour supprimer un agent. Les poids permettent de prioriser certaines régions dans le calcul du SLA.', 'sitepulse'); ?></p>
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
                    <div class="sitepulse-module-card sitepulse-module-card--setting" data-sitepulse-view="expert">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Secret des tâches IA', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description"><?php esc_html_e('Ce secret sécurise les exécutions déclenchées via AJAX ou WP-CLI. Régénérez-le si vous suspectez une fuite ou après avoir partagé un accès temporaire.', 'sitepulse'); ?></p>
                            <p class="sitepulse-card-description"><?php esc_html_e('La nouvelle valeur est stockée immédiatement et devra être mise à jour dans les scripts externes qui invoquent les analyses.', 'sitepulse'); ?></p>
                            <button type="submit" form="sitepulse-ai-secret-regen-form" class="button button-secondary">
                                <?php esc_html_e('Régénérer le secret', 'sitepulse'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                </div>
            </div>
            <div class="sitepulse-tab-panel" id="sitepulse-tab-performance" role="tabpanel" aria-labelledby="sitepulse-tab-performance-label" tabindex="0">
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
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Benchmarks et concurrents', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $competitor_field_id = SITEPULSE_OPTION_SPEED_BENCHMARKS . '-competitors'; ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr($competitor_field_id); ?>"><?php esc_html_e('URLs concurrentes', 'sitepulse'); ?></label>
                            <textarea
                                id="<?php echo esc_attr($competitor_field_id); ?>"
                                name="<?php echo esc_attr(SITEPULSE_OPTION_SPEED_BENCHMARKS); ?>[competitors]"
                                rows="4"
                                class="large-text"
                                placeholder="<?php echo esc_attr__('https://exemple.com\nhttps://autre-competiteur.com', 'sitepulse'); ?>"
                            ><?php echo esc_textarea($speed_benchmark_competitors_value); ?></textarea>
                            <p class="sitepulse-card-description"><?php esc_html_e('Indiquez une URL par ligne pour suivre des sites de référence. Les hôtes servent à nommer les séries de comparaison.', 'sitepulse'); ?></p>
                            <div class="sitepulse-benchmark-grid">
                                <?php foreach ($speed_profiles_catalog as $profile_slug => $profile_meta) :
                                    $profile_label = isset($profile_meta['label']) ? $profile_meta['label'] : ucfirst($profile_slug);
                                    $profile_description = isset($profile_meta['description']) ? $profile_meta['description'] : '';
                                    $budget_field_name = SITEPULSE_OPTION_SPEED_BENCHMARKS . '[budgets][' . $profile_slug . ']';
                                    $budget_field_id = SITEPULSE_OPTION_SPEED_BENCHMARKS . '-budget-' . $profile_slug;
                                    $budget_value = isset($speed_benchmark_budgets[$profile_slug]) ? (int) $speed_benchmark_budgets[$profile_slug] : '';
                                ?>
                                    <div class="sitepulse-benchmark-field">
                                        <label class="sitepulse-field-label" for="<?php echo esc_attr($budget_field_id); ?>">
                                            <?php printf(esc_html__('Budget %s (ms)', 'sitepulse'), esc_html($profile_label)); ?>
                                        </label>
                                        <input
                                            type="number"
                                            min="1"
                                            step="1"
                                            id="<?php echo esc_attr($budget_field_id); ?>"
                                            name="<?php echo esc_attr($budget_field_name); ?>"
                                            value="<?php echo '' === $budget_value ? '' : esc_attr($budget_value); ?>"
                                            class="small-text"
                                        >
                                        <?php if (!empty($profile_description)) : ?>
                                            <p class="sitepulse-card-note"><?php echo esc_html($profile_description); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="sitepulse-card-description"><?php esc_html_e('Ces budgets alimentent les alertes ainsi que les comparaisons dans le module Speed Analyzer.', 'sitepulse'); ?></p>
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
                                'trendWarning' => [
                                    'label'       => __('Variation – avertissement (%)', 'sitepulse'),
                                    'description' => __('Augmentation relative entre deux mesures successives déclenchant un avertissement.', 'sitepulse'),
                                ],
                                'trendCritical' => [
                                    'label'       => __('Variation – critique (%)', 'sitepulse'),
                                    'description' => __('Augmentation relative entre deux mesures successives déclenchant un statut critique.', 'sitepulse'),
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
            </div>
            <div class="sitepulse-tab-panel" id="sitepulse-tab-modules" role="tabpanel" aria-labelledby="sitepulse-tab-modules-label" tabindex="0">
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
                        $status_id = $checkbox_id . '-status';
                        $toggle_label_id = $checkbox_id . '-toggle-label';
                        $is_active = in_array($module_key, (array) $active_modules, true);
                        $status_class = $is_active ? 'is-active' : 'is-inactive';
                        $status_label = $is_active ? esc_html__('Activé', 'sitepulse') : esc_html__('Désactivé', 'sitepulse');
                    ?>
                    <div class="sitepulse-module-card" data-module="<?php echo esc_attr($module_key); ?>">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title" id="<?php echo esc_attr($checkbox_id); ?>-title"><?php echo esc_html($module_label); ?></h3>
                            <span
                                class="sitepulse-status <?php echo esc_attr($status_class); ?>"
                                id="<?php echo esc_attr($status_id); ?>"
                                role="status"
                                aria-live="polite"
                                aria-atomic="true"
                                data-sitepulse-status-on="<?php esc_attr_e('Activé', 'sitepulse'); ?>"
                                data-sitepulse-status-off="<?php esc_attr_e('Désactivé', 'sitepulse'); ?>"
                            ><?php echo $status_label; ?></span>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php
                            $raw_metrics = isset($module_summaries[$module_key]) && is_array($module_summaries[$module_key])
                                ? $module_summaries[$module_key]
                                : [];
                            ?>
                            <?php if ($module_description !== '') : ?>
                                <p class="sitepulse-card-description" id="<?php echo esc_attr($description_id); ?>"><?php echo esc_html($module_description); ?></p>
                            <?php endif; ?>
                            <?php
                            $prepared_metrics = [];

                            foreach ($raw_metrics as $metric) {
                                if (!is_array($metric)) {
                                    continue;
                                }

                                $metric_label = isset($metric['label']) ? (string) $metric['label'] : '';
                                $metric_value = isset($metric['value']) ? (string) $metric['value'] : '';
                                $metric_status = isset($metric['status']) ? (string) $metric['status'] : '';

                                if ($metric_label === '' && $metric_value === '') {
                                    continue;
                                }

                                if ($metric_status === '') {
                                    $metric_status = 'is-success';
                                }

                                $prepared_metrics[] = [
                                    'label'  => $metric_label,
                                    'value'  => $metric_value,
                                    'status' => $metric_status,
                                ];
                            }
                            ?>
                            <?php if (!empty($prepared_metrics)) : ?>
                                <ul class="sitepulse-module-metrics">
                                    <?php foreach ($prepared_metrics as $metric) : ?>
                                        <li class="sitepulse-module-metric">
                                            <?php if ($metric['label'] !== '') : ?>
                                                <span class="sitepulse-module-metric-label"><?php echo esc_html($metric['label']); ?></span>
                                            <?php endif; ?>
                                            <span class="sitepulse-status <?php echo esc_attr($metric['status']); ?>">
                                                <?php echo esc_html($metric['value'] !== '' ? $metric['value'] : __('Aucun relevé', 'sitepulse')); ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <p class="sitepulse-card-placeholder"><?php esc_html_e('Aucun relevé', 'sitepulse'); ?></p>
                            <?php endif; ?>
                            <div class="sitepulse-card-footer">
                                <label class="sitepulse-toggle" for="<?php echo esc_attr($checkbox_id); ?>">
                                    <input
                                        type="checkbox"
                                        id="<?php echo esc_attr($checkbox_id); ?>"
                                        name="<?php echo esc_attr(SITEPULSE_OPTION_ACTIVE_MODULES); ?>[]"
                                        value="<?php echo esc_attr($module_key); ?>"
                                        <?php checked($is_active); ?>
                                        <?php if ($module_description !== '') : ?>aria-describedby="<?php echo esc_attr($description_id); ?>"<?php endif; ?>
                                        data-sitepulse-toggle="module"
                                        data-sitepulse-toggle-label="<?php echo esc_attr($module_label); ?>"
                                        data-sitepulse-toggle-status-target="<?php echo esc_attr($status_id); ?>"
                                        data-sitepulse-toggle-on="<?php echo esc_attr_x('activé', 'toggle state', 'sitepulse'); ?>"
                                        data-sitepulse-toggle-off="<?php echo esc_attr_x('désactivé', 'toggle state', 'sitepulse'); ?>"
                                    >
                                    <span id="<?php echo esc_attr($toggle_label_id); ?>"><?php printf(esc_html__('Activer le module %s', 'sitepulse'), esc_html($module_label)); ?></span>
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
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Seuils du moniteur de ressources', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description sitepulse-card-description--compact"><?php esc_html_e('Définissez les pourcentages maximum autorisés avant d’envoyer des alertes automatiques.', 'sitepulse'); ?></p>
                            <?php
                            $resource_cpu_threshold = (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT, SITEPULSE_DEFAULT_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT);
                            $resource_memory_threshold = (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT, SITEPULSE_DEFAULT_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT);
                            $resource_disk_threshold = (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT, SITEPULSE_DEFAULT_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT);
                            ?>
                            <div class="sitepulse-resource-thresholds" role="group" aria-label="<?php esc_attr_e('Seuils d’alerte automatiques', 'sitepulse'); ?>">
                                <?php $cpu_description_id = SITEPULSE_OPTION_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT . '-help'; ?>
                                <div class="sitepulse-resource-thresholds__field">
                                    <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT); ?>"><?php esc_html_e('CPU (1 min)', 'sitepulse'); ?></label>
                                    <div class="sitepulse-resource-thresholds__input">
                                        <input type="number" min="0" max="100" id="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT); ?>" value="<?php echo esc_attr($resource_cpu_threshold); ?>" class="small-text" aria-describedby="<?php echo esc_attr($cpu_description_id); ?>">
                                        <span aria-hidden="true">%</span>
                                    </div>
                                    <p class="sitepulse-resource-thresholds__hint" id="<?php echo esc_attr($cpu_description_id); ?>"><?php esc_html_e('Alerte lorsque la moyenne dépasse ce seuil sur plusieurs relevés.', 'sitepulse'); ?></p>
                                </div>
                                <?php $memory_description_id = SITEPULSE_OPTION_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT . '-help'; ?>
                                <div class="sitepulse-resource-thresholds__field">
                                    <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT); ?>"><?php esc_html_e('Mémoire utilisée', 'sitepulse'); ?></label>
                                    <div class="sitepulse-resource-thresholds__input">
                                        <input type="number" min="0" max="100" id="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT); ?>" value="<?php echo esc_attr($resource_memory_threshold); ?>" class="small-text" aria-describedby="<?php echo esc_attr($memory_description_id); ?>">
                                        <span aria-hidden="true">%</span>
                                    </div>
                                    <p class="sitepulse-resource-thresholds__hint" id="<?php echo esc_attr($memory_description_id); ?>"><?php esc_html_e('Pourcentage de mémoire occupée avant notification.', 'sitepulse'); ?></p>
                                </div>
                                <?php $disk_description_id = SITEPULSE_OPTION_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT . '-help'; ?>
                                <div class="sitepulse-resource-thresholds__field">
                                    <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT); ?>"><?php esc_html_e('Stockage utilisé', 'sitepulse'); ?></label>
                                    <div class="sitepulse-resource-thresholds__input">
                                        <input type="number" min="0" max="100" id="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT); ?>" value="<?php echo esc_attr($resource_disk_threshold); ?>" class="small-text" aria-describedby="<?php echo esc_attr($disk_description_id); ?>">
                                        <span aria-hidden="true">%</span>
                                    </div>
                                    <p class="sitepulse-resource-thresholds__hint" id="<?php echo esc_attr($disk_description_id); ?>"><?php esc_html_e('100 % correspond à un disque plein. Harmonisé avec le tableau de bord.', 'sitepulse'); ?></p>
                                </div>
                            </div>
                            <?php
                            $retention_days = (int) get_option(
                                SITEPULSE_OPTION_RESOURCE_MONITOR_RETENTION_DAYS,
                                SITEPULSE_DEFAULT_RESOURCE_MONITOR_RETENTION_DAYS
                            );
                            $export_limit = (int) get_option(
                                SITEPULSE_OPTION_RESOURCE_MONITOR_EXPORT_MAX_ROWS,
                                SITEPULSE_DEFAULT_RESOURCE_MONITOR_EXPORT_MAX_ROWS
                            );
                            $retention_choices = apply_filters('sitepulse_resource_monitor_allowed_retention_days', [90, 180, 365]);

                            if (!is_array($retention_choices) || empty($retention_choices)) {
                                $retention_choices = [90, 180, 365];
                            }

                            $retention_choices = array_values(array_unique(array_map('intval', $retention_choices)));
                            $retention_choices = array_filter($retention_choices, static function ($candidate) {
                                return $candidate >= 0;
                            });
                            sort($retention_choices);

                            $retention_description_id = SITEPULSE_OPTION_RESOURCE_MONITOR_RETENTION_DAYS . '-help';
                            $export_description_id = SITEPULSE_OPTION_RESOURCE_MONITOR_EXPORT_MAX_ROWS . '-help';
                            ?>
                            <hr class="sitepulse-card-divider">
                            <div class="sitepulse-resource-history-settings" role="group" aria-label="<?php esc_attr_e('Historique conservé et exports', 'sitepulse'); ?>">
                                <div class="sitepulse-resource-thresholds__field">
                                    <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_RETENTION_DAYS); ?>"><?php esc_html_e('Durée de conservation', 'sitepulse'); ?></label>
                                    <div class="sitepulse-resource-thresholds__input">
                                        <select id="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_RETENTION_DAYS); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_RETENTION_DAYS); ?>" aria-describedby="<?php echo esc_attr($retention_description_id); ?>">
                                            <?php foreach ($retention_choices as $choice_days) : ?>
                                                <option value="<?php echo esc_attr($choice_days); ?>" <?php selected($retention_days, $choice_days); ?>>
                                                    <?php
                                                    $label = sprintf(
                                                        /* translators: %d: number of days. */
                                                        _n('%d jour', '%d jours', $choice_days, 'sitepulse'),
                                                        absint($choice_days)
                                                    );
                                                    echo esc_html($label);
                                                    ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <p class="sitepulse-resource-thresholds__hint" id="<?php echo esc_attr($retention_description_id); ?>"><?php esc_html_e('Détermine combien de jours de mesures sont conservés avant purge automatique.', 'sitepulse'); ?></p>
                                </div>
                                <div class="sitepulse-resource-thresholds__field">
                                    <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_EXPORT_MAX_ROWS); ?>"><?php esc_html_e('Lignes exportées', 'sitepulse'); ?></label>
                                    <div class="sitepulse-resource-thresholds__input">
                                        <input type="number" min="0" step="1" id="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_EXPORT_MAX_ROWS); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_RESOURCE_MONITOR_EXPORT_MAX_ROWS); ?>" value="<?php echo esc_attr($export_limit); ?>" class="small-text" aria-describedby="<?php echo esc_attr($export_description_id); ?>">
                                    </div>
                                    <p class="sitepulse-resource-thresholds__hint" id="<?php echo esc_attr($export_description_id); ?>"><?php esc_html_e('Nombre maximum de lignes incluses dans un export CSV/JSON (0 pour illimité).', 'sitepulse'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting" id="sitepulse-debug-card">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Mode Debug', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description"><?php esc_html_e("Active la journalisation détaillée et le tableau de bord de débogage. À n'utiliser que pour le dépannage.", 'sitepulse'); ?></p>
                            <input type="hidden" name="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>" value="0">
                            <div class="sitepulse-card-footer">
                                <label class="sitepulse-toggle" for="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>">
                                    <input
                                        type="checkbox"
                                        id="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>"
                                        name="<?php echo esc_attr(SITEPULSE_OPTION_DEBUG_MODE); ?>"
                                        value="1"
                                        <?php checked($is_debug_mode_enabled); ?>
                                        aria-describedby="sitepulse-debug-card"
                                        data-sitepulse-toggle="setting"
                                        data-sitepulse-toggle-label="<?php esc_attr_e('Mode Debug', 'sitepulse'); ?>"
                                        data-sitepulse-toggle-on="<?php echo esc_attr_x('activé', 'toggle state', 'sitepulse'); ?>"
                                        data-sitepulse-toggle-off="<?php echo esc_attr_x('désactivé', 'toggle state', 'sitepulse'); ?>"
                                    >
                                    <span><?php esc_html_e('Activer le Mode Debug', 'sitepulse'); ?></span>
                                </label>
                            </div>
                            <p class="sitepulse-card-description"><?php printf(esc_html__('Sur Nginx (ou tout serveur qui ignore .htaccess / web.config), déplacez le journal via le filtre %s ou bloquez-le côté serveur.', 'sitepulse'), 'sitepulse_debug_log_base_dir'); ?></p>
                        </div>
                    </div>
                </div>
                </div>
            </div>
            <div class="sitepulse-tab-panel" id="sitepulse-tab-alerts" role="tabpanel" aria-labelledby="sitepulse-tab-alerts-label" tabindex="0">
                <div class="sitepulse-settings-section" id="sitepulse-section-alerts">
                <h2><?php esc_html_e('Alertes', 'sitepulse'); ?></h2>
                <div class="sitepulse-settings-grid">
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Destinataires des alertes', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $recipients_value = $alert_recipients_value; ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>"><?php esc_html_e('Adresses e-mail', 'sitepulse'); ?></label>
                            <textarea id="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_RECIPIENTS); ?>" rows="4" class="large-text code sitepulse-textarea"><?php echo esc_textarea($recipients_value); ?></textarea>
                            <p class="sitepulse-card-description"><?php esc_html_e("Entrez une adresse par ligne (ou séparées par des virgules). L'adresse e-mail de l'administrateur sera toujours incluse si elle est valide.", 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Canaux de diffusion', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <input type="hidden" name="<?php echo esc_attr(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS); ?>[]" value="">
                            <?php foreach ($delivery_channel_choices as $channel_key => $channel_label) :
                                $channel_id = 'sitepulse-alert-delivery-' . $channel_key;
                                $is_checked = in_array($channel_key, $configured_delivery_channels, true);
                            ?>
                                <label class="sitepulse-toggle" for="<?php echo esc_attr($channel_id); ?>">
                                    <input
                                        type="checkbox"
                                        id="<?php echo esc_attr($channel_id); ?>"
                                        name="<?php echo esc_attr(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS); ?>[]"
                                        value="<?php echo esc_attr($channel_key); ?>"
                                        <?php checked($is_checked); ?>
                                        data-sitepulse-toggle="setting"
                                        data-sitepulse-toggle-label="<?php echo esc_attr(sprintf(esc_html__('Canal %s', 'sitepulse'), $channel_label)); ?>"
                                        data-sitepulse-toggle-on="<?php echo esc_attr_x('activé', 'toggle state', 'sitepulse'); ?>"
                                        data-sitepulse-toggle-off="<?php echo esc_attr_x('désactivé', 'toggle state', 'sitepulse'); ?>"
                                    >
                                    <span><?php echo esc_html($channel_label); ?></span>
                                </label>
                            <?php endforeach; ?>
                            <p class="sitepulse-card-description"><?php esc_html_e('Activez au moins un canal pour recevoir les alertes critiques.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Webhooks sortants', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <label class="sitepulse-field-label" for="sitepulse-alert-webhooks"><?php esc_html_e('URL de webhook', 'sitepulse'); ?></label>
                            <textarea id="sitepulse-alert-webhooks" name="<?php echo esc_attr(SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS); ?>" rows="4" class="large-text code sitepulse-textarea" placeholder="https://example.com/webhook"><?php echo esc_textarea($webhook_urls_value); ?></textarea>
                            <p class="sitepulse-card-description"><?php esc_html_e('Indiquez une URL par ligne. Les requêtes contiendront un corps JSON avec le type, le sujet, le message et la sévérité.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Niveaux de sévérité suivis', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <input type="hidden" name="<?php echo esc_attr(SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES); ?>[]" value="">
                            <?php foreach ($severity_choices as $severity_key => $severity_label) :
                                $severity_id = 'sitepulse-alert-severity-' . $severity_key;
                                $is_selected = in_array($severity_key, $enabled_severities, true);
                            ?>
                                <label class="sitepulse-toggle" for="<?php echo esc_attr($severity_id); ?>">
                                    <input
                                        type="checkbox"
                                        id="<?php echo esc_attr($severity_id); ?>"
                                        name="<?php echo esc_attr(SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES); ?>[]"
                                        value="<?php echo esc_attr($severity_key); ?>"
                                        <?php checked($is_selected); ?>
                                        data-sitepulse-toggle="setting"
                                        data-sitepulse-toggle-label="<?php echo esc_attr(sprintf(esc_html__('Niveau %s', 'sitepulse'), $severity_label)); ?>"
                                        data-sitepulse-toggle-on="<?php echo esc_attr_x('activé', 'toggle state', 'sitepulse'); ?>"
                                        data-sitepulse-toggle-off="<?php echo esc_attr_x('désactivé', 'toggle state', 'sitepulse'); ?>"
                                    >
                                    <span><?php echo esc_html($severity_label); ?></span>
                                </label>
                            <?php endforeach; ?>
                            <p class="sitepulse-card-description"><?php esc_html_e('Les alertes dont la sévérité est désactivée sont ignorées mais restent visibles dans les journaux.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <?php
                    $cpu_enabled = in_array('cpu', $enabled_alert_channels, true);
                    $php_enabled = in_array('php_fatal', $enabled_alert_channels, true);
                    ?>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e("Seuil d'alerte de charge CPU", 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <input type="hidden" name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS); ?>[]" value="">
                            <label class="sitepulse-toggle" for="sitepulse-alert-channel-cpu">
                                <input
                                    type="checkbox"
                                    id="sitepulse-alert-channel-cpu"
                                    name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS); ?>[]"
                                    value="cpu"
                                    <?php checked($cpu_enabled); ?>
                                    data-sitepulse-toggle="setting"
                                    data-sitepulse-toggle-label="<?php esc_attr_e('Alertes de charge CPU', 'sitepulse'); ?>"
                                    data-sitepulse-toggle-on="<?php echo esc_attr_x('activées', 'toggle state feminine', 'sitepulse'); ?>"
                                    data-sitepulse-toggle-off="<?php echo esc_attr_x('désactivées', 'toggle state feminine', 'sitepulse'); ?>"
                                >
                                <span><?php esc_html_e('Activer les alertes de charge CPU', 'sitepulse'); ?></span>
                            </label>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>"><?php esc_html_e('Valeur déclenchant une alerte', 'sitepulse'); ?></label>
                            <input type="number" step="0.1" min="0" id="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD); ?>" value="<?php echo esc_attr(get_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 5)); ?>" class="small-text">
                            <p class="sitepulse-card-description"><?php esc_html_e('Une alerte e-mail est envoyée lorsque la charge moyenne sur 1 minute dépasse ce seuil multiplié par le nombre de cœurs détectés.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Alertes sur les erreurs PHP', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <label class="sitepulse-toggle" for="sitepulse-alert-channel-php">
                                <input
                                    type="checkbox"
                                    id="sitepulse-alert-channel-php"
                                    name="<?php echo esc_attr(SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS); ?>[]"
                                    value="php_fatal"
                                    <?php checked($php_enabled); ?>
                                    data-sitepulse-toggle="setting"
                                    data-sitepulse-toggle-label="<?php esc_attr_e('Alertes sur les erreurs fatales PHP', 'sitepulse'); ?>"
                                    data-sitepulse-toggle-on="<?php echo esc_attr_x('activées', 'toggle state feminine', 'sitepulse'); ?>"
                                    data-sitepulse-toggle-off="<?php echo esc_attr_x('désactivées', 'toggle state feminine', 'sitepulse'); ?>"
                                >
                                <span><?php esc_html_e('Activer les alertes sur les erreurs fatales', 'sitepulse'); ?></span>
                            </label>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD); ?>"><?php esc_html_e('Nombre de lignes fatales avant alerte', 'sitepulse'); ?></label>
                            <input type="number" min="1" id="<?php echo esc_attr(SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD); ?>" value="<?php echo esc_attr(get_option(SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD, 1)); ?>" class="small-text">
                            <p class="sitepulse-card-description"><?php esc_html_e('Déclenche une alerte lorsqu’au moins ce nombre de nouvelles entrées fatales est trouvé dans le journal.', 'sitepulse'); ?></p>
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
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Tester la configuration', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description"><?php esc_html_e('Déclenchez un test pour chaque canal configuré. Les résultats sont consignés dans les journaux SitePulse.', 'sitepulse'); ?></p>
                        </div>
                        <div class="sitepulse-card-footer">
                            <?php
                            $test_base_args = [
                                'action'   => 'sitepulse_send_alert_test',
                                '_wpnonce' => wp_create_nonce(SITEPULSE_NONCE_ACTION_ALERT_TEST),
                            ];
                            $email_test_url = add_query_arg(array_merge($test_base_args, ['channel' => 'email']), admin_url('admin-post.php'));
                            $webhook_test_url = add_query_arg(array_merge($test_base_args, ['channel' => 'webhook']), admin_url('admin-post.php'));
                            ?>
                            <button type="submit" class="button button-secondary" formaction="<?php echo esc_url($email_test_url); ?>" formmethod="post" <?php disabled(!$email_delivery_enabled); ?>><?php esc_html_e('Tester l’e-mail', 'sitepulse'); ?></button>
                            <button type="submit" class="button" formaction="<?php echo esc_url($webhook_test_url); ?>" formmethod="post" <?php disabled(!$webhook_delivery_enabled); ?>><?php esc_html_e('Tester le webhook', 'sitepulse'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
            </div>
            <div class="sitepulse-tab-panel" id="sitepulse-tab-uptime" role="tabpanel" aria-labelledby="sitepulse-tab-uptime-label" tabindex="0">
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
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Fréquence des contrôles', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $uptime_frequency_choices = sitepulse_get_uptime_frequency_choices(); ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_FREQUENCY); ?>"><?php esc_html_e('Intervalle entre deux vérifications', 'sitepulse'); ?></label>
                            <select id="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_FREQUENCY); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_FREQUENCY); ?>">
                                <?php foreach ($uptime_frequency_choices as $frequency_key => $frequency_data) :
                                    if (!is_array($frequency_data) || !isset($frequency_data['label'])) {
                                        continue;
                                    }

                                    $label = $frequency_data['label'];
                                    ?>
                                    <option value="<?php echo esc_attr($frequency_key); ?>" <?php selected($uptime_frequency, $frequency_key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="sitepulse-card-description"><?php esc_html_e('Sélectionnez la fréquence d’exécution de la tâche de surveillance.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Durée de conservation', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $uptime_history_choices = sitepulse_get_uptime_history_retention_choices(); ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS); ?>"><?php esc_html_e('Historique conservé', 'sitepulse'); ?></label>
                            <select id="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS); ?>">
                                <?php foreach ($uptime_history_choices as $history_days => $history_label) : ?>
                                    <option value="<?php echo esc_attr($history_days); ?>" <?php selected($uptime_history_retention, $history_days); ?>><?php echo esc_html($history_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="sitepulse-card-description"><?php esc_html_e('Détermine la période maximale conservée pour les journaux et rapports de disponibilité.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Seuil de latence', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $uptime_latency_description_id = 'sitepulse-uptime-latency-description'; ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_LATENCY_THRESHOLD); ?>"><?php esc_html_e('Temps maximal toléré (secondes)', 'sitepulse'); ?></label>
                            <input type="number" min="0" step="0.1" id="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_LATENCY_THRESHOLD); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_LATENCY_THRESHOLD); ?>" value="<?php echo esc_attr($uptime_latency_threshold); ?>" class="small-text" aria-describedby="<?php echo esc_attr($uptime_latency_description_id); ?>">
                            <p class="sitepulse-card-description" id="<?php echo esc_attr($uptime_latency_description_id); ?>"><?php esc_html_e('Déclenche un incident si la réponse dépasse cette durée. Utilisez 0 pour désactiver.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Mot-clé attendu', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $uptime_keyword_description_id = 'sitepulse-uptime-keyword-description'; ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_KEYWORD); ?>"><?php esc_html_e('Chaîne à rechercher dans la réponse', 'sitepulse'); ?></label>
                            <input type="text" id="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_KEYWORD); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_KEYWORD); ?>" value="<?php echo esc_attr($uptime_keyword); ?>" class="regular-text" aria-describedby="<?php echo esc_attr($uptime_keyword_description_id); ?>">
                            <p class="sitepulse-card-description" id="<?php echo esc_attr($uptime_keyword_description_id); ?>"><?php esc_html_e('Laisser vide pour ignorer cette vérification. La recherche n’est pas sensible à la casse.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Méthode HTTP', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <?php $uptime_method_choices = sitepulse_get_uptime_http_method_choices(); ?>
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_HTTP_METHOD); ?>"><?php esc_html_e('Méthode utilisée pour la requête', 'sitepulse'); ?></label>
                            <select id="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_HTTP_METHOD); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_HTTP_METHOD); ?>">
                                <?php foreach ($uptime_method_choices as $method_key => $method_label) : ?>
                                    <option value="<?php echo esc_attr($method_key); ?>" <?php selected($uptime_http_method, $method_key); ?>><?php echo esc_html($method_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="sitepulse-card-description"><?php esc_html_e('Choisissez la méthode HTTP employée pour vérifier la disponibilité.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('En-têtes personnalisés', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_HTTP_HEADERS); ?>"><?php esc_html_e('En-têtes additionnels', 'sitepulse'); ?></label>
                            <textarea id="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_HTTP_HEADERS); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_HTTP_HEADERS); ?>" rows="4" class="large-text code sitepulse-textarea" placeholder="<?php echo esc_attr__('Header-Name: valeur', 'sitepulse'); ?>"><?php echo esc_textarea($uptime_headers_text); ?></textarea>
                            <p class="sitepulse-card-description"><?php esc_html_e('Indiquez un en-tête par ligne au format « Nom: valeur ». Laissez vide pour n’ajouter aucun en-tête.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Codes HTTP attendus', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <label class="sitepulse-field-label" for="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_EXPECTED_CODES); ?>"><?php esc_html_e('Codes considérés comme succès', 'sitepulse'); ?></label>
                            <input type="text" id="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_EXPECTED_CODES); ?>" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_EXPECTED_CODES); ?>" value="<?php echo esc_attr($uptime_expected_codes_text); ?>" class="regular-text" placeholder="200, 201-204">
                            <p class="sitepulse-card-description"><?php esc_html_e('Liste de codes séparés par des virgules ou plages (ex. 200-204). Laissez vide pour accepter toute réponse 2xx ou 3xx.', 'sitepulse'); ?></p>
                        </div>
                    </div>
                    <div class="sitepulse-module-card sitepulse-module-card--setting">
                        <div class="sitepulse-card-header">
                            <h3 class="sitepulse-card-title"><?php esc_html_e('Fenêtres de maintenance', 'sitepulse'); ?></h3>
                        </div>
                        <div class="sitepulse-card-body">
                            <p class="sitepulse-card-description"><?php esc_html_e('Planifiez des créneaux récurrents pendant lesquels les contrôles d’uptime sont ignorés et aucune alerte n’est envoyée.', 'sitepulse'); ?></p>
                            <?php for ($index = 0; $index < $uptime_maintenance_rows_to_render; $index++) :
                                $window = isset($uptime_maintenance_windows[$index]) ? $uptime_maintenance_windows[$index] : [];
                                $agent_value = isset($window['agent']) ? $window['agent'] : 'all';
                                $day_value = isset($window['day']) ? (int) $window['day'] : 1;
                                $time_value = isset($window['time']) ? $window['time'] : '';
                                $duration_value = isset($window['duration']) ? (int) $window['duration'] : '';
                                $recurrence_value = isset($window['recurrence']) ? $window['recurrence'] : 'weekly';
                                $label_value = isset($window['label']) ? $window['label'] : '';
                                $date_value = isset($window['date']) ? $window['date'] : '';
                            ?>
                                <fieldset class="sitepulse-maintenance-window">
                                    <legend><?php printf(esc_html__('Fenêtre %d', 'sitepulse'), $index + 1); ?></legend>
                                    <div class="sitepulse-maintenance-window__grid">
                                        <label>
                                            <span class="sitepulse-field-label"><?php esc_html_e('Agent concerné', 'sitepulse'); ?></span>
                                            <select name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS); ?>[<?php echo esc_attr($index); ?>][agent]">
                                                <?php foreach ($uptime_agents_choices as $agent_key => $agent_label) : ?>
                                                    <option value="<?php echo esc_attr($agent_key); ?>" <?php selected($agent_value, $agent_key); ?>><?php echo esc_html($agent_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span class="sitepulse-field-label"><?php esc_html_e('Jour de démarrage', 'sitepulse'); ?></span>
                                            <select name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS); ?>[<?php echo esc_attr($index); ?>][day]">
                                                <?php foreach ($uptime_day_choices as $day_key => $day_label) : ?>
                                                    <option value="<?php echo esc_attr($day_key); ?>" <?php selected($day_value, $day_key); ?>><?php echo esc_html($day_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span class="sitepulse-field-label"><?php esc_html_e('Heure (HH:MM)', 'sitepulse'); ?></span>
                                            <input type="time" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS); ?>[<?php echo esc_attr($index); ?>][time]" value="<?php echo esc_attr($time_value); ?>">
                                        </label>
                                        <label>
                                            <span class="sitepulse-field-label"><?php esc_html_e('Durée (minutes)', 'sitepulse'); ?></span>
                                            <input type="number" min="1" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS); ?>[<?php echo esc_attr($index); ?>][duration]" value="<?php echo esc_attr($duration_value); ?>">
                                        </label>
                                        <label>
                                            <span class="sitepulse-field-label"><?php esc_html_e('Récurrence', 'sitepulse'); ?></span>
                                            <select name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS); ?>[<?php echo esc_attr($index); ?>][recurrence]">
                                                <?php foreach ($uptime_recurrence_choices as $recurrence_key => $recurrence_label) : ?>
                                                    <option value="<?php echo esc_attr($recurrence_key); ?>" <?php selected($recurrence_value, $recurrence_key); ?>><?php echo esc_html($recurrence_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span class="sitepulse-field-label"><?php esc_html_e('Date (ponctuel)', 'sitepulse'); ?></span>
                                            <input type="date" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS); ?>[<?php echo esc_attr($index); ?>][date]" value="<?php echo esc_attr($date_value); ?>">
                                        </label>
                                        <label>
                                            <span class="sitepulse-field-label"><?php esc_html_e('Intitulé', 'sitepulse'); ?></span>
                                            <input type="text" name="<?php echo esc_attr(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS); ?>[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr($label_value); ?>" class="regular-text">
                                        </label>
                                    </div>
                                    <p class="description"><?php esc_html_e('Laissez une ligne vide pour supprimer la fenêtre correspondante. Les créneaux ponctuels ignorent le champ « Jour de démarrage ».', 'sitepulse'); ?></p>
                                </fieldset>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                </div>
            </div>
            <div class="sitepulse-settings-actions" data-sitepulse-sticky-actions>
                <div class="sitepulse-settings-actions__inner">
                    <div class="sitepulse-settings-actions__meta">
                        <span class="dashicons dashicons-saved" aria-hidden="true"></span>
                        <span><?php esc_html_e('Les modifications ne sont appliquées qu’après enregistrement.', 'sitepulse'); ?></span>
                    </div>
                    <div class="sitepulse-settings-actions__buttons">
                        <?php
                        submit_button(
                            esc_html__('Enregistrer les modifications', 'sitepulse'),
                            'primary sitepulse-settings-actions__submit',
                            'submit',
                            false
                        );
                        ?>
                        <button type="button" class="button button-secondary" data-sitepulse-scroll-top>
                            <span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
                            <span class="screen-reader-text"><?php esc_html_e('Remonter en haut de la page', 'sitepulse'); ?></span>
                            <?php esc_html_e('Haut de page', 'sitepulse'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
                        <div class="sitepulse-tab-panel" id="sitepulse-tab-maintenance" role="tabpanel" aria-labelledby="sitepulse-tab-maintenance-label" tabindex="0">
                            <div class="sitepulse-settings-section" id="sitepulse-section-maintenance">
                            <h2><?php esc_html_e('Nettoyage & Réinitialisation', 'sitepulse'); ?></h2>
                            <p class="sitepulse-section-intro"><?php esc_html_e('Gérez les données du plugin.', 'sitepulse'); ?></p>
                            <form method="post" action="" class="sitepulse-settings-form sitepulse-settings-form--secondary">
                                <?php wp_nonce_field(SITEPULSE_NONCE_ACTION_CLEANUP, SITEPULSE_NONCE_FIELD_CLEANUP); ?>
                                <div class="sitepulse-settings-grid">
                                    <div class="sitepulse-module-card sitepulse-module-card--setting sitepulse-module-card--async" data-sitepulse-async-card data-sitepulse-async-state="<?php echo esc_attr($async_jobs_state); ?>" data-sitepulse-view="expert">
                                        <div class="sitepulse-card-header">
                                            <h3 class="sitepulse-card-title"><?php esc_html_e('Traitements en arrière-plan', 'sitepulse'); ?></h3>
                                        </div>
                                        <div class="sitepulse-card-body">
                                            <p class="sitepulse-card-description" id="sitepulse-async-jobs-description"><?php esc_html_e('Ces opérations se poursuivent même si vous quittez la page. Leur état est mis à jour automatiquement lors de l’actualisation.', 'sitepulse'); ?></p>
                                            <ul
                                                class="sitepulse-async-job-list"
                                                role="status"
                                                aria-live="polite"
                                                aria-describedby="sitepulse-async-jobs-description"
                                                data-sitepulse-async-jobs-list
                                                data-sitepulse-async-initial="<?php echo esc_attr($async_jobs_initial_json); ?>"
                                                data-sitepulse-async-empty-message="<?php echo esc_attr__('Aucun traitement en arrière-plan pour le moment.', 'sitepulse'); ?>"
                                            >
                                                <?php if (!empty($async_jobs_overview)) : ?>
                                                    <?php foreach ($async_jobs_overview as $async_job) : ?>
                                                        <li class="sitepulse-async-job sitepulse-async-job--<?php echo esc_attr($async_job['container_class']); ?>" data-sitepulse-async-id="<?php echo esc_attr($async_job['id']); ?>">
                                                            <div class="sitepulse-async-job__header">
                                                                <span class="sitepulse-status <?php echo esc_attr($async_job['badge_class']); ?>"><?php echo esc_html($async_job['status_label']); ?></span>
                                                                <span class="sitepulse-async-job__label"><?php echo esc_html($async_job['label']); ?></span>
                                                            </div>
                                                            <?php if (!empty($async_job['message'])) : ?>
                                                                <p class="sitepulse-async-job__message"><?php echo esc_html($async_job['message']); ?></p>
                                                            <?php endif; ?>
                                                            <?php if (!empty($async_job['progress_label']) || !empty($async_job['relative']) || ($async_job['progress_percent'] > 0 && $async_job['progress_percent'] < 100)) : ?>
                                                                <p class="sitepulse-async-job__meta">
                                                                    <?php if (!empty($async_job['progress_label'])) : ?>
                                                                        <span><?php echo esc_html($async_job['progress_label']); ?></span>
                                                                    <?php elseif ($async_job['progress_percent'] > 0 && $async_job['progress_percent'] < 100) : ?>
                                                                        <span><?php printf(esc_html__('Progression : %s%%', 'sitepulse'), esc_html(number_format_i18n($async_job['progress_percent']))); ?></span>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($async_job['relative'])) : ?>
                                                                        <span><?php echo esc_html($async_job['relative']); ?></span>
                                                                    <?php endif; ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            <?php if (!empty($async_job['logs'])) : ?>
                                                                <details class="sitepulse-async-job__logs" <?php if (!empty($async_job['is_active'])) : ?>open<?php endif; ?>>
                                                                    <summary class="sitepulse-async-job__logs-summary">
                                                                        <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                                                                        <span class="sitepulse-async-job__logs-summary-text"><?php esc_html_e('Journal des opérations', 'sitepulse'); ?></span>
                                                                        <span class="screen-reader-text"><?php esc_html_e('Afficher ou masquer le journal détaillé', 'sitepulse'); ?></span>
                                                                    </summary>
                                                                    <ul class="sitepulse-async-job__log-list">
                                                                        <?php foreach ($async_job['logs'] as $log_entry) : ?>
                                                                            <li class="sitepulse-async-job__log sitepulse-async-job__log--<?php echo esc_attr($log_entry['level_class']); ?>">
                                                                                <span class="sitepulse-async-job__log-label"><?php echo esc_html($log_entry['level_label']); ?></span>
                                                                                <span class="sitepulse-async-job__log-message"><?php echo esc_html($log_entry['message']); ?></span>
                                                                                <?php if (!empty($log_entry['relative'])) : ?>
                                                                                    <time class="sitepulse-async-job__log-time" <?php if (!empty($log_entry['iso'])) : ?>datetime="<?php echo esc_attr($log_entry['iso']); ?>"<?php endif; ?>><?php echo esc_html($log_entry['relative']); ?></time>
                                                                                <?php endif; ?>
                                                                            </li>
                                                                        <?php endforeach; ?>
                                                                    </ul>
                                                                </details>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                <?php else : ?>
                                                    <li class="sitepulse-async-job sitepulse-async-job--empty"><?php esc_html_e('Aucun traitement en arrière-plan pour le moment.', 'sitepulse'); ?></li>
                                                <?php endif; ?>
                                            </ul>
                                            <p class="sitepulse-async-job__error" data-sitepulse-async-error hidden></p>
                                        </div>
                                    </div>
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
                                            <p>
                                                <label for="sitepulse_confirm_reset">
                                                    <input type="checkbox" name="sitepulse_confirm_reset" id="sitepulse_confirm_reset" value="1">
                                                    <?php esc_html_e('Je comprends que cette action est irréversible.', 'sitepulse'); ?>
                                                </label>
                                            </p>
                                            <div class="sitepulse-card-footer">
                                                <button type="submit" name="sitepulse_reset_all" class="button button-danger" onclick="if (!document.getElementById('sitepulse_confirm_reset').checked) { window.alert('<?php echo esc_js(__('Cochez la case de confirmation avant de réinitialiser SitePulse.', 'sitepulse')); ?>'); return false; } return window.confirm('<?php echo esc_js(__('Réinitialiser SitePulse ? Cette action est irréversible.', 'sitepulse')); ?>');"><?php echo esc_html__('Tout réinitialiser', 'sitepulse'); ?></button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="sitepulse-module-card sitepulse-module-card--metric sitepulse-module-card--analytics">
                                        <div class="sitepulse-card-header">
                                            <h3 class="sitepulse-card-title"><?php esc_html_e('Historique des purges de transients', 'sitepulse'); ?></h3>
                                        </div>
                                        <div class="sitepulse-card-body">
                                            <?php
                                            $transient_purge_latest = isset($transient_purge_summary['latest']) && is_array($transient_purge_summary['latest'])
                                                ? $transient_purge_summary['latest']
                                                : null;
                                            $transient_purge_totals = isset($transient_purge_summary['totals']) && is_array($transient_purge_summary['totals'])
                                                ? $transient_purge_summary['totals']
                                                : ['deleted' => 0, 'unique' => 0, 'batches' => 0];
                                            $transient_purge_top    = isset($transient_purge_summary['top_prefixes']) && is_array($transient_purge_summary['top_prefixes'])
                                                ? $transient_purge_summary['top_prefixes']
                                                : [];
                                            ?>
                                            <?php if (!empty($transient_purge_entries)) : ?>
                                                <?php
                                                $relative_label = '';

                                                if ($transient_purge_latest && !empty($transient_purge_latest['timestamp']) && function_exists('human_time_diff')) {
                                                    $diff = human_time_diff(
                                                        (int) $transient_purge_latest['timestamp'],
                                                        function_exists('current_time') ? current_time('timestamp') : time()
                                                    );

                                                    if (!empty($diff)) {
                                                        $relative_label = sprintf(esc_html__('il y a %s', 'sitepulse'), $diff);
                                                    }
                                                }
                                                ?>
                                                <p class="sitepulse-card-description">
                                                    <?php printf(
                                                        esc_html__('Dernière purge : %1$s (%2$s) — %3$s transients supprimés.', 'sitepulse'),
                                                        $transient_purge_latest ? esc_html($transient_purge_latest['prefix']) : esc_html__('N/A', 'sitepulse'),
                                                        $transient_purge_latest ? esc_html(sitepulse_get_transient_purge_scope_label($transient_purge_latest['scope'])) : esc_html__('Scope inconnu', 'sitepulse'),
                                                        $transient_purge_latest ? esc_html(number_format_i18n((int) $transient_purge_latest['deleted'])) : '0'
                                                    ); ?>
                                                    <?php if ($relative_label !== '') : ?>
                                                        <span class="sitepulse-transient-purge-relative"><?php echo esc_html($relative_label); ?></span>
                                                    <?php endif; ?>
                                                </p>
                                                <p class="sitepulse-transient-purge-total">
                                                    <?php printf(
                                                        esc_html__('%1$s suppressions · %2$s clés uniques · %3$s lots sur 30 jours.', 'sitepulse'),
                                                        esc_html(number_format_i18n((int) $transient_purge_totals['deleted'])),
                                                        esc_html(number_format_i18n((int) $transient_purge_totals['unique'])),
                                                        esc_html(number_format_i18n((int) $transient_purge_totals['batches']))
                                                    ); ?>
                                                </p>
                                                <?php if (!empty($transient_purge_top)) : ?>
                                                    <ul class="sitepulse-transient-purge-list">
                                                        <?php foreach ($transient_purge_top as $prefix_entry) : ?>
                                                            <li>
                                                                <span class="sitepulse-transient-purge-prefix"><?php echo esc_html($prefix_entry['prefix']); ?></span>
                                                                <span class="sitepulse-transient-purge-count">
                                                                    <?php printf(
                                                                        esc_html(_n('%s suppression', '%s suppressions', (int) $prefix_entry['deleted'], 'sitepulse')),
                                                                        esc_html(number_format_i18n((int) $prefix_entry['deleted']))
                                                                    ); ?>
                                                                </span>
                                                                <span class="sitepulse-transient-purge-scope"><?php echo esc_html(sitepulse_get_transient_purge_scope_label($prefix_entry['scope'])); ?></span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <p class="sitepulse-card-description"><?php esc_html_e('Aucune purge de transients n’a encore été enregistrée.', 'sitepulse'); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
