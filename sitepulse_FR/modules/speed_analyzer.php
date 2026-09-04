<?php
if (!defined('ABSPATH')) exit;

require_once SITEPULSE_PATH . 'includes/request-profiler.php';
require_once SITEPULSE_PATH . 'modules/speed-analyzer/rum.php';

if (function_exists('sitepulse_request_profiler_bootstrap')) {
    sitepulse_request_profiler_bootstrap();
}

// Add admin submenu
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Speed Analyzer', 'sitepulse'),
        __('Speed', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-speed',
        'sitepulse_speed_analyzer_page'
    );
});

add_action('admin_enqueue_scripts', 'sitepulse_speed_analyzer_enqueue_assets');
add_action('wp_ajax_sitepulse_run_speed_scan', 'sitepulse_ajax_run_speed_scan');
add_action('wp_ajax_sitepulse_start_trace', 'sitepulse_speed_analyzer_ajax_start_trace');
add_action('wp_ajax_sitepulse_get_trace', 'sitepulse_speed_analyzer_ajax_get_trace');
add_action('init', 'sitepulse_speed_analyzer_bootstrap_cron');
add_filter('cron_schedules', 'sitepulse_speed_analyzer_register_cron_schedules');
add_action(sitepulse_speed_analyzer_get_cron_hook(), 'sitepulse_speed_analyzer_run_cron');
add_action(sitepulse_speed_analyzer_get_queue_hook(), 'sitepulse_speed_analyzer_run_queue');
add_action('admin_post_sitepulse_save_speed_schedule', 'sitepulse_speed_analyzer_handle_schedule_post');
add_action('admin_post_sitepulse_save_rum_settings', 'sitepulse_speed_analyzer_handle_rum_settings');

/**
 * Returns the cron hook used for scheduled speed scans.
 *
 * @return string
 */
function sitepulse_speed_analyzer_get_cron_hook() {
    $default = 'sitepulse_speed_analyzer_cron';

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_speed_analyzer_cron_hook', $default);

        if (is_string($filtered) && $filtered !== '') {
            return $filtered;
        }
    }

    return $default;
}

/**
 * Returns the hook used to drain queued scans when rate limits are reached.
 *
 * @return string
 */
function sitepulse_speed_analyzer_get_queue_hook() {
    $default = 'sitepulse_speed_analyzer_queue';

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_speed_analyzer_queue_hook', $default);

        if (is_string($filtered) && $filtered !== '') {
            return $filtered;
        }
    }

    return $default;
}

/**
 * Retrieves the configured rate limit (in seconds) for manual scans.
 *
 * @return int
 */
function sitepulse_speed_analyzer_get_rate_limit() {
    $interval = apply_filters('sitepulse_speed_scan_min_interval', MINUTE_IN_SECONDS);

    if (!is_scalar($interval)) {
        $interval = MINUTE_IN_SECONDS;
    }

    $interval = (int) $interval;

    return max(10, $interval);
}

require_once __DIR__ . '/speed-analyzer/profiles.php';

/**
 * Returns the available status labels for summary badges.
 *
 * @return array<string,array{label:string,sr:string,icon:string}>
 */
function sitepulse_speed_analyzer_get_status_labels() {
    return [
        'status-ok'   => [
            'label' => __('Bon', 'sitepulse'),
            'sr'    => __('Statut : bon', 'sitepulse'),
            'icon'  => '✔️',
        ],
        'status-warn' => [
            'label' => __('Attention', 'sitepulse'),
            'sr'    => __('Statut : attention', 'sitepulse'),
            'icon'  => '⚠️',
        ],
        'status-bad'  => [
            'label' => __('Critique', 'sitepulse'),
            'sr'    => __('Statut : critique', 'sitepulse'),
            'icon'  => '⛔',
        ],
    ];
}

/**
 * Returns the available frequency choices for automated scans.
 *
 * @return array<string,string>
 */
function sitepulse_speed_analyzer_get_frequency_choices() {
    $choices = [
        'disabled'   => __('Désactivé', 'sitepulse'),
        'hourly'     => __('Toutes les heures', 'sitepulse'),
        'twicedaily' => __('Deux fois par jour', 'sitepulse'),
        'daily'      => __('Quotidien', 'sitepulse'),
        'sitepulse_weekly' => __('Hebdomadaire', 'sitepulse'),
    ];

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_speed_analyzer_frequency_choices', $choices);

        if (is_array($filtered)) {
            $choices = [];

            foreach ($filtered as $key => $label) {
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $choices[$key] = (string) $label;
            }
        }
    }

    return $choices;
}

/**
 * Normalizes a frequency slug for storage.
 *
 * @param mixed $frequency Selected value.
 *
 * @return string
 */
function sitepulse_speed_analyzer_sanitize_frequency($frequency) {
    $frequency = is_string($frequency) ? strtolower($frequency) : '';
    $choices = sitepulse_speed_analyzer_get_frequency_choices();

    if ($frequency === '' || !isset($choices[$frequency])) {
        return 'disabled';
    }

    return $frequency;
}

/**
 * Registers the additional cron schedule used by the speed analyzer.
 *
 * @param array<string,array> $schedules Existing schedules.
 *
 * @return array<string,array>
 */
function sitepulse_speed_analyzer_register_cron_schedules($schedules) {
    if (!is_array($schedules)) {
        $schedules = [];
    }

    $schedules['sitepulse_weekly'] = [
        'interval' => WEEK_IN_SECONDS,
        'display'  => __('Toutes les semaines', 'sitepulse'),
    ];

    return $schedules;
}

require_once __DIR__ . '/speed-analyzer/automation.php';
require_once __DIR__ . '/speed-analyzer/queue.php';
require_once __DIR__ . '/speed-analyzer/cron.php';
require_once __DIR__ . '/speed-analyzer/aggregates.php';
require_once __DIR__ . '/speed-analyzer/ajax.php';

/**
 * Enqueues the Speed Analyzer stylesheet on the relevant admin page.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_speed_analyzer_enqueue_assets($hook_suffix) {
    if ($hook_suffix !== 'sitepulse-dashboard_page_sitepulse-speed') {
        return;
    }

    $thresholds = sitepulse_speed_analyzer_get_thresholds();
    $history = sitepulse_speed_analyzer_get_history_data();
    $rate_limit = sitepulse_speed_analyzer_get_rate_limit();
    $last_run = (int) get_option('sitepulse_speed_scan_last_run', 0);
    $aggregates = sitepulse_speed_analyzer_get_aggregates($history, $thresholds);
    $summary_meta = sitepulse_speed_analyzer_get_summary_meta();
    $status_labels = sitepulse_speed_analyzer_get_status_labels();
    $summary_note = sitepulse_speed_analyzer_build_summary_note($aggregates);

    $automation_payload = sitepulse_speed_analyzer_build_automation_payload($thresholds);
    $frequency_choices = sitepulse_speed_analyzer_get_frequency_choices();
    $profiles_catalog = sitepulse_speed_analyzer_get_profile_catalog();
    $profiles_for_js = sitepulse_speed_analyzer_prepare_profiles_for_js($profiles_catalog);

    $manual_profile = isset($thresholds['profile']) ? sitepulse_speed_analyzer_normalize_profile($thresholds['profile']) : 'default';
    $manual_profile_label = isset($profiles_for_js[$manual_profile]['label']) ? $profiles_for_js[$manual_profile]['label'] : ucfirst($manual_profile);
    $manual_profile_description = isset($profiles_for_js[$manual_profile]['description']) ? $profiles_for_js[$manual_profile]['description'] : '';
    $profiler_payload = sitepulse_speed_analyzer_get_profiler_payload();

    $rum_payload = null;

    if (function_exists('sitepulse_rum_get_settings')) {
        $rum_settings = sitepulse_rum_get_settings();
        $rum_range = isset($rum_settings['range_days']) ? (int) $rum_settings['range_days'] : 7;
        $rum_payload = [
            'enabled'    => function_exists('sitepulse_rum_is_enabled') ? sitepulse_rum_is_enabled() : false,
            'rangeDays'  => $rum_range,
            'endpoint'   => rest_url('sitepulse/v1/rum/aggregates'),
            'nonce'      => wp_create_nonce('wp_rest'),
            'aggregates' => function_exists('sitepulse_rum_calculate_aggregates')
                ? sitepulse_rum_calculate_aggregates(['range_days' => $rum_range])
                : null,
        ];
    }

    wp_enqueue_style(
        'sitepulse-speed-analyzer',
        SITEPULSE_URL . 'modules/css/speed-analyzer.css',
        [],
        SITEPULSE_VERSION
    );

    $default_chartjs_src = SITEPULSE_URL . 'modules/vendor/chart.js/chart.umd.js';
    $chartjs_src = apply_filters('sitepulse_chartjs_src', $default_chartjs_src);

    if (!wp_script_is('sitepulse-chartjs', 'registered')) {
        $is_custom_source = $chartjs_src !== $default_chartjs_src;

        wp_register_script(
            'sitepulse-chartjs',
            $chartjs_src,
            [],
            '4.4.5',
            true
        );

        if ($is_custom_source) {
            $fallback_loader = '(function(){if (typeof window.Chart === "undefined") {'
                . 'var script=document.createElement("script");'
                . 'script.src=' . wp_json_encode($default_chartjs_src) . ';'
                . 'script.defer=true;'
                . 'document.head.appendChild(script);'
                . '}})();';

            wp_add_inline_script('sitepulse-chartjs', $fallback_loader, 'after');
        }
    }

    wp_enqueue_script('sitepulse-chartjs');

    wp_enqueue_script(
        'sitepulse-speed-analyzer',
        SITEPULSE_URL . 'modules/js/speed-analyzer.js',
        ['sitepulse-chartjs'],
        SITEPULSE_VERSION,
        true
    );

    wp_localize_script(
        'sitepulse-speed-analyzer',
        'SitePulseSpeedAnalyzer',
        [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('sitepulse_speed_scan'),
            'history'        => $history,
            'thresholds'     => [
                'warning'  => (int) $thresholds['warning'],
                'critical' => (int) $thresholds['critical'],
                'profile'  => $manual_profile,
            ],
            'aggregates'     => $aggregates,
            'summaryMeta'    => $summary_meta,
            'statusLabels'   => $status_labels,
            'rateLimit'      => $rate_limit,
            'lastRun'        => $last_run,
            'recommendations'=> sitepulse_speed_analyzer_build_recommendations(
                sitepulse_speed_analyzer_get_latest_entry($history),
                $thresholds
            ),
            'automation'     => $automation_payload,
            'frequencyChoices'=> $frequency_choices,
            'profiles'       => $profiles_for_js,
            'manualProfile'  => [
                'slug'        => $manual_profile,
                'label'       => $manual_profile_label,
                'description' => $manual_profile_description,
            ],
            'profiler'      => $profiler_payload,
            'rum'           => $rum_payload,
            'i18n'           => [
                'running'        => esc_html__('Analyse en cours…', 'sitepulse'),
                'retry'          => esc_html__('Relancer un test', 'sitepulse'),
                'noHistory'      => esc_html__("Aucun historique disponible pour le moment.", 'sitepulse'),
                'timestamp'      => esc_html__('Horodatage', 'sitepulse'),
                'source'         => esc_html__('Source', 'sitepulse'),
                'duration'       => esc_html__('Temps serveur (ms)', 'sitepulse'),
                'status'         => esc_html__('Statut', 'sitepulse'),
                'chartLabel'     => esc_html__('Temps de traitement du serveur', 'sitepulse'),
                'error'          => esc_html__("Une erreur est survenue pendant le test. Veuillez réessayer.", 'sitepulse'),
                'throttled'      => esc_html__('Test bloqué temporairement par la limite de fréquence.', 'sitepulse'),
                'rateLimitIntro' => esc_html__('Prochain test possible dans', 'sitepulse'),
                'warningThresholdLabel' => esc_html__('Seuil d’alerte', 'sitepulse'),
                'criticalThresholdLabel'=> esc_html__('Seuil critique', 'sitepulse'),
                'manualLabel'    => esc_html__('Tests manuels', 'sitepulse'),
                'automationLabel'=> esc_html__('Planifié – %s', 'sitepulse'),
                'automationEmpty'=> esc_html__('Aucun preset planifié n’est disponible.', 'sitepulse'),
                'queueWarning'   => esc_html__('Certaines mesures automatiques sont en file d’attente.', 'sitepulse'),
                'profileLabel'  => esc_html__('Profil', 'sitepulse'),
                'summaryUnit'   => esc_html__('ms', 'sitepulse'),
                'summaryNoData' => esc_html__('N/A', 'sitepulse'),
                'summarySampleSingular' => esc_html__('Basé sur %d mesure.', 'sitepulse'),
                'summarySamplePlural'   => esc_html__('Basé sur %d mesures.', 'sitepulse'),
                'summaryOutlierSingular'=> esc_html__('%d mesure extrême ignorée lors du calcul des moyennes.', 'sitepulse'),
                'summaryOutlierPlural'  => esc_html__('%d mesures extrêmes ignorées lors du calcul des moyennes.', 'sitepulse'),
                'ownSourceLabel'        => esc_html__('Votre site', 'sitepulse'),
                'competitorSourceLabel' => esc_html__('Concurrent', 'sitepulse'),
                'budgetLabel'           => esc_html__('Budget', 'sitepulse'),
            ],
        ]
    );
}


require_once __DIR__ . '/speed-analyzer/page.php';
