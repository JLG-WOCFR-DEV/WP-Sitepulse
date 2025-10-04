<?php
if (!defined('ABSPATH')) exit;

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

/**
 * Retrieves the warning and critical thresholds for speed measurements.
 *
 * @return array{warning:int,critical:int,default_warning:int,default_critical:int}
 */
function sitepulse_speed_analyzer_get_thresholds() {
    $default_speed_warning = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
    $default_speed_critical = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;
    $speed_warning_threshold = $default_speed_warning;
    $speed_critical_threshold = $default_speed_critical;

    if (function_exists('sitepulse_get_speed_thresholds')) {
        $fetched_thresholds = sitepulse_get_speed_thresholds();

        if (is_array($fetched_thresholds)) {
            if (isset($fetched_thresholds['warning']) && is_numeric($fetched_thresholds['warning'])) {
                $speed_warning_threshold = (int) $fetched_thresholds['warning'];
            }

            if (isset($fetched_thresholds['critical']) && is_numeric($fetched_thresholds['critical'])) {
                $speed_critical_threshold = (int) $fetched_thresholds['critical'];
            }
        }
    } else {
        $warning_option_key = defined('SITEPULSE_OPTION_SPEED_WARNING_MS') ? SITEPULSE_OPTION_SPEED_WARNING_MS : 'sitepulse_speed_warning_ms';
        $critical_option_key = defined('SITEPULSE_OPTION_SPEED_CRITICAL_MS') ? SITEPULSE_OPTION_SPEED_CRITICAL_MS : 'sitepulse_speed_critical_ms';

        $stored_warning = get_option($warning_option_key, $default_speed_warning);
        $stored_critical = get_option($critical_option_key, $default_speed_critical);

        if (is_numeric($stored_warning)) {
            $speed_warning_threshold = (int) $stored_warning;
        }

        if (is_numeric($stored_critical)) {
            $speed_critical_threshold = (int) $stored_critical;
        }
    }

    if ($speed_warning_threshold < 1) {
        $speed_warning_threshold = $default_speed_warning;
    }

    if ($speed_critical_threshold <= $speed_warning_threshold) {
        $speed_critical_threshold = max($speed_warning_threshold + 1, $default_speed_critical);
    }

    return [
        'warning'          => $speed_warning_threshold,
        'critical'         => $speed_critical_threshold,
        'default_warning'  => $default_speed_warning,
        'default_critical' => $default_speed_critical,
    ];
}

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
 * Determines the status badge class for a metric value.
 *
 * @param float|null $value      Metric value.
 * @param array      $thresholds Warning and critical thresholds.
 *
 * @return string
 */
function sitepulse_speed_analyzer_resolve_status($value, $thresholds) {
    if (!is_array($thresholds)) {
        $thresholds = [];
    }

    if (!is_numeric($value)) {
        return 'status-warn';
    }

    $warning = isset($thresholds['warning']) ? (int) $thresholds['warning'] : 0;
    $critical = isset($thresholds['critical']) ? (int) $thresholds['critical'] : 0;

    if ($critical > 0 && $value >= $critical) {
        return 'status-bad';
    }

    if ($warning > 0 && $value >= $warning) {
        return 'status-warn';
    }

    return 'status-ok';
}

/**
 * Calculates a percentile using linear interpolation.
 *
 * @param float[] $values     Sorted numeric values.
 * @param float   $percentile Percentile between 0 and 100.
 *
 * @return float|null
 */
function sitepulse_speed_analyzer_calculate_percentile($values, $percentile) {
    if (empty($values)) {
        return null;
    }

    $count = count($values);

    if ($count === 1) {
        return (float) $values[0];
    }

    $percentile = max(0.0, min(100.0, (float) $percentile));
    $index = ($percentile / 100) * ($count - 1);
    $lower = (int) floor($index);
    $upper = (int) ceil($index);

    if ($lower === $upper) {
        return (float) $values[$lower];
    }

    $fraction = $index - $lower;
    $lower_value = (float) $values[$lower];
    $upper_value = (float) $values[$upper];

    return $lower_value + ($upper_value - $lower_value) * $fraction;
}

/**
 * Filters out upper outliers using the interquartile range rule.
 *
 * @param float[] $values Sorted numeric values.
 *
 * @return float[]
 */
function sitepulse_speed_analyzer_filter_outliers($values) {
    $count = count($values);

    if ($count < 4) {
        return $values;
    }

    $q1 = sitepulse_speed_analyzer_calculate_percentile($values, 25);
    $q3 = sitepulse_speed_analyzer_calculate_percentile($values, 75);

    if ($q1 === null || $q3 === null) {
        return $values;
    }

    $iqr = $q3 - $q1;

    if ($iqr <= 0) {
        return $values;
    }

    $upper_bound = $q3 + (1.5 * $iqr);
    $lower_bound = max(0.0, $q1 - (1.5 * $iqr));

    $filtered = array_values(array_filter(
        $values,
        static function ($value) use ($lower_bound, $upper_bound) {
            return $value >= $lower_bound && $value <= $upper_bound;
        }
    ));

    return empty($filtered) ? $values : $filtered;
}

/**
 * Provides the summary metric labels and descriptions.
 *
 * @return array<string,array{label:string,description:string}>
 */
function sitepulse_speed_analyzer_get_summary_meta() {
    return [
        'mean'   => [
            'label'       => __('Moyenne', 'sitepulse'),
            'description' => __('Temps moyen observé sur l’ensemble des relevés.', 'sitepulse'),
        ],
        'median' => [
            'label'       => __('Médiane', 'sitepulse'),
            'description' => __('Valeur centrale qui limite l’impact des variations ponctuelles.', 'sitepulse'),
        ],
        'p95'    => [
            'label'       => __('95e percentile', 'sitepulse'),
            'description' => __('Niveau en dessous duquel se trouvent 95% des mesures.', 'sitepulse'),
        ],
        'best'   => [
            'label'       => __('Meilleure mesure', 'sitepulse'),
            'description' => __('Temps de réponse le plus rapide observé.', 'sitepulse'),
        ],
        'worst'  => [
            'label'       => __('Pire mesure', 'sitepulse'),
            'description' => __('Temps de réponse le plus lent enregistré.', 'sitepulse'),
        ],
    ];
}

/**
 * Calculates aggregated statistics over the history.
 *
 * @param array<int,array{timestamp:int,server_processing_ms:float}>|null $history    History entries.
 * @param array{warning:int,critical:int}|null                            $thresholds Threshold configuration.
 *
 * @return array{
 *     count:int,
 *     filtered_count:int,
 *     excluded_outliers:int,
 *     metrics:array<string,array{value:float|null,status:string}>
 * }
 */
function sitepulse_speed_analyzer_get_aggregates($history = null, $thresholds = null) {
    if ($history === null) {
        $history = sitepulse_speed_analyzer_get_history_data();
    }

    if ($thresholds === null) {
        $thresholds = sitepulse_speed_analyzer_get_thresholds();
    }

    $values = [];

    if (is_array($history)) {
        foreach ($history as $entry) {
            if (!is_array($entry) || !isset($entry['server_processing_ms'])) {
                continue;
            }

            $value = (float) $entry['server_processing_ms'];

            if (!is_finite($value) || $value < 0) {
                continue;
            }

            $values[] = $value;
        }
    }

    sort($values);

    $count = count($values);

    if ($count === 0) {
        return [
            'count'            => 0,
            'filtered_count'   => 0,
            'excluded_outliers'=> 0,
            'metrics'          => [
                'mean'   => ['value' => null, 'status' => 'status-warn'],
                'median' => ['value' => null, 'status' => 'status-warn'],
                'p95'    => ['value' => null, 'status' => 'status-warn'],
                'best'   => ['value' => null, 'status' => 'status-warn'],
                'worst'  => ['value' => null, 'status' => 'status-warn'],
            ],
        ];
    }

    $filtered_values = sitepulse_speed_analyzer_filter_outliers($values);
    $filtered_count = count($filtered_values);

    if ($filtered_count === 0) {
        $filtered_values = $values;
        $filtered_count = $count;
    }

    $mean = $filtered_count > 0 ? array_sum($filtered_values) / $filtered_count : null;
    $median = sitepulse_speed_analyzer_calculate_percentile($filtered_values, 50);
    $p95 = sitepulse_speed_analyzer_calculate_percentile($values, 95);
    $best = min($values);
    $worst = max($values);

    return [
        'count'            => $count,
        'filtered_count'   => $filtered_count,
        'excluded_outliers'=> max(0, $count - $filtered_count),
        'metrics'          => [
            'mean'   => [
                'value'  => $mean,
                'status' => sitepulse_speed_analyzer_resolve_status($mean, $thresholds),
            ],
            'median' => [
                'value'  => $median,
                'status' => sitepulse_speed_analyzer_resolve_status($median, $thresholds),
            ],
            'p95'    => [
                'value'  => $p95,
                'status' => sitepulse_speed_analyzer_resolve_status($p95, $thresholds),
            ],
            'best'   => [
                'value'  => $best,
                'status' => sitepulse_speed_analyzer_resolve_status($best, $thresholds),
            ],
            'worst'  => [
                'value'  => $worst,
                'status' => sitepulse_speed_analyzer_resolve_status($worst, $thresholds),
            ],
        ],
    ];
}

/**
 * Returns the recorded speed history in a normalized format.
 *
 * @return array<int,array{timestamp:int,server_processing_ms:float}>
 */
function sitepulse_speed_analyzer_get_history_data() {
    $history = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

    if (!is_array($history)) {
        return [];
    }

    $normalized = array_values(array_filter(
        array_map(
            static function ($entry) {
                if (!is_array($entry)) {
                    return null;
                }

                if (!isset($entry['timestamp'], $entry['server_processing_ms'])) {
                    return null;
                }

                if (!is_numeric($entry['timestamp']) || !is_numeric($entry['server_processing_ms'])) {
                    return null;
                }

                return [
                    'timestamp'            => max(0, (int) $entry['timestamp']),
                    'server_processing_ms' => max(0.0, (float) $entry['server_processing_ms']),
                ];
            },
            $history
        ),
        static function ($entry) {
            return is_array($entry);
        }
    ));

    usort(
        $normalized,
        static function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        }
    );

    return $normalized;
}

/**
 * Retrieves the latest entry from the history array.
 *
 * @param array<int,array{timestamp:int,server_processing_ms:float}> $history History entries.
 *
 * @return array{timestamp:int,server_processing_ms:float}|null
 */
function sitepulse_speed_analyzer_get_latest_entry($history) {
    if (empty($history) || !is_array($history)) {
        return null;
    }

    $last_index = count($history) - 1;

    if (!isset($history[$last_index]) || !is_array($history[$last_index])) {
        return null;
    }

    return $history[$last_index];
}

/**
 * Generates textual recommendations based on the latest measurement.
 *
 * @param array{timestamp:int,server_processing_ms:float}|null $latest_entry Latest history entry.
 * @param array{warning:int,critical:int}                       $thresholds   Threshold configuration.
 *
 * @return string[]
 */
function sitepulse_speed_analyzer_build_recommendations($latest_entry, $thresholds) {
    $messages = [];

    if (empty($latest_entry)) {
        $messages[] = esc_html__("Nous attendons encore suffisamment de données pour formuler des recommandations. Relancez un test pour commencer l'historique.", 'sitepulse');

        return $messages;
    }

    $duration = isset($latest_entry['server_processing_ms']) ? (float) $latest_entry['server_processing_ms'] : 0.0;
    $warning = isset($thresholds['warning']) ? (int) $thresholds['warning'] : 0;
    $critical = isset($thresholds['critical']) ? (int) $thresholds['critical'] : 0;

    if ($duration >= $critical) {
        $messages[] = esc_html__("Les temps de réponse du serveur sont critiques. Contactez votre hébergeur et désactivez temporairement les extensions lourdes pour identifier le goulot d'étranglement.", 'sitepulse');
    } elseif ($duration >= $warning) {
        $messages[] = esc_html__("Vos performances se dégradent. Vérifiez les dernières extensions installées, optimisez la base de données et activez un cache persistant si possible.", 'sitepulse');
    } else {
        $messages[] = esc_html__("Le serveur répond correctement. Continuez à surveiller l'historique pour repérer les écarts ou planifiez des tests réguliers après les mises à jour.", 'sitepulse');
    }

    if ($duration >= $warning) {
        $messages[] = esc_html__("Pensez à réduire les tâches cron simultanées et à surveiller l'utilisation CPU côté hébergeur pendant les pics.", 'sitepulse');
    } else {
        $messages[] = esc_html__("Aucune action urgente n'est requise, mais gardez un œil sur l'évolution après des déploiements importants.", 'sitepulse');
    }

    return $messages;
}

/**
 * Handles the AJAX request to trigger a fresh speed scan.
 */
function sitepulse_ajax_run_speed_scan() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error([
            'message' => esc_html__("Vous n'avez pas les permissions nécessaires pour réaliser ce test.", 'sitepulse'),
        ], 403);
    }

    check_ajax_referer('sitepulse_speed_scan', 'nonce');

    $rate_limit = sitepulse_speed_analyzer_get_rate_limit();
    $last_run = (int) get_option('sitepulse_speed_scan_last_run', 0);
    $now = current_time('timestamp');
    $thresholds = sitepulse_speed_analyzer_get_thresholds();

    if ($rate_limit > 0 && ($now - $last_run) < $rate_limit) {
        $remaining = max(0, $rate_limit - ($now - $last_run));
        $history = sitepulse_speed_analyzer_get_history_data();
        $latest = sitepulse_speed_analyzer_get_latest_entry($history);
        $aggregates = sitepulse_speed_analyzer_get_aggregates($history, $thresholds);

        wp_send_json_error([
            'message'          => sprintf(
                /* translators: %s: human readable delay before the next scan. */
                esc_html__('Veuillez patienter encore %s avant de relancer un test pour éviter de surcharger le serveur.', 'sitepulse'),
                esc_html(human_time_diff($now, $now + max(1, $remaining)))
            ),
            'status'           => 'throttled',
            'history'          => $history,
            'recommendations'  => sitepulse_speed_analyzer_build_recommendations($latest, $thresholds),
            'latest'           => $latest,
            'aggregates'       => $aggregates,
            'next_available'   => $last_run + $rate_limit,
            'rate_limit'       => $rate_limit,
            'remaining'        => $remaining,
        ], 429);
    }

    global $sitepulse_plugin_impact_tracker_force_persist;

    $previous_force_state = isset($sitepulse_plugin_impact_tracker_force_persist)
        ? (bool) $sitepulse_plugin_impact_tracker_force_persist
        : false;

    $sitepulse_plugin_impact_tracker_force_persist = true;
    sitepulse_plugin_impact_tracker_persist();
    $sitepulse_plugin_impact_tracker_force_persist = $previous_force_state;

    update_option('sitepulse_speed_scan_last_run', $now, false);

    $history = sitepulse_speed_analyzer_get_history_data();
    $latest = sitepulse_speed_analyzer_get_latest_entry($history);
    $aggregates = sitepulse_speed_analyzer_get_aggregates($history, $thresholds);

    wp_send_json_success([
        'message'         => esc_html__('Un nouveau relevé a été ajouté à votre historique.', 'sitepulse'),
        'history'         => $history,
        'latest'          => $latest,
        'recommendations' => sitepulse_speed_analyzer_build_recommendations($latest, $thresholds),
        'aggregates'      => $aggregates,
        'last_run'        => $now,
        'rate_limit'      => $rate_limit,
    ]);
}

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
    $summary_note_parts = [];

    if (!empty($aggregates['count'])) {
        $summary_note_parts[] = sprintf(
            _n('Basé sur %d mesure.', 'Basé sur %d mesures.', (int) $aggregates['count'], 'sitepulse'),
            (int) $aggregates['count']
        );
    }

    if (!empty($aggregates['excluded_outliers'])) {
        $summary_note_parts[] = sprintf(
            _n(
                '%d mesure extrême ignorée lors du calcul des moyennes.',
                '%d mesures extrêmes ignorées lors du calcul des moyennes.',
                (int) $aggregates['excluded_outliers'],
                'sitepulse'
            ),
            (int) $aggregates['excluded_outliers']
        );
    }

    $summary_note = trim(implode(' ', $summary_note_parts));

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
            'i18n'           => [
                'running'        => esc_html__('Analyse en cours…', 'sitepulse'),
                'retry'          => esc_html__('Relancer un test', 'sitepulse'),
                'noHistory'      => esc_html__("Aucun historique disponible pour le moment.", 'sitepulse'),
                'timestamp'      => esc_html__('Horodatage', 'sitepulse'),
                'duration'       => esc_html__('Temps serveur (ms)', 'sitepulse'),
                'chartLabel'     => esc_html__('Temps de traitement du serveur', 'sitepulse'),
                'error'          => esc_html__("Une erreur est survenue pendant le test. Veuillez réessayer.", 'sitepulse'),
                'throttled'      => esc_html__('Test bloqué temporairement par la limite de fréquence.', 'sitepulse'),
                'rateLimitIntro' => esc_html__('Prochain test possible dans', 'sitepulse'),
                'warningThresholdLabel' => esc_html__('Seuil d’alerte', 'sitepulse'),
                'criticalThresholdLabel'=> esc_html__('Seuil critique', 'sitepulse'),
                'summaryUnit'   => esc_html__('ms', 'sitepulse'),
                'summaryNoData' => esc_html__('N/A', 'sitepulse'),
                'summarySampleSingular' => esc_html__('Basé sur %d mesure.', 'sitepulse'),
                'summarySamplePlural'   => esc_html__('Basé sur %d mesures.', 'sitepulse'),
                'summaryOutlierSingular'=> esc_html__('%d mesure extrême ignorée lors du calcul des moyennes.', 'sitepulse'),
                'summaryOutlierPlural'  => esc_html__('%d mesures extrêmes ignorées lors du calcul des moyennes.', 'sitepulse'),
            ],
        ]
    );
}

/**
 * Renders the Speed Analyzer page.
 * The analysis is now based on internal WordPress timers for better reliability.
 */
function sitepulse_speed_analyzer_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    global $wpdb;

    // --- Server Performance Metrics ---

    // 1. Page Generation Time (Backend processing)
    // **FIX:** Replaced timer_stop() with a direct microtime calculation to prevent non-numeric value warnings in specific environments.
    if (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
        $timestart = (float) $_SERVER['REQUEST_TIME_FLOAT'];
    } elseif (isset($GLOBALS['timestart']) && is_numeric($GLOBALS['timestart'])) {
        $timestart = (float) $GLOBALS['timestart'];
    } else {
        $timestart = microtime(true);
    }
    $page_generation_time = (microtime(true) - $timestart) * 1000.0; // in milliseconds

    $thresholds = sitepulse_speed_analyzer_get_thresholds();
    $speed_warning_threshold = $thresholds['warning'];
    $speed_critical_threshold = $thresholds['critical'];
    $rate_limit = sitepulse_speed_analyzer_get_rate_limit();
    $history = sitepulse_speed_analyzer_get_history_data();
    $latest_entry = sitepulse_speed_analyzer_get_latest_entry($history);
    $aggregates = sitepulse_speed_analyzer_get_aggregates($history, $thresholds);
    $summary_meta = sitepulse_speed_analyzer_get_summary_meta();
    $status_labels = sitepulse_speed_analyzer_get_status_labels();
    $now_timestamp = current_time('timestamp');
    $rate_limit_label = human_time_diff($now_timestamp, $now_timestamp + max(1, $rate_limit));

    // 2. Database Query Time & Count
    $db_query_total_time = 0;
    $savequeries_enabled = defined('SAVEQUERIES') && SAVEQUERIES;

    if ($savequeries_enabled && isset($wpdb->queries) && is_array($wpdb->queries)) {
        foreach ($wpdb->queries as $query) {
            // Ensure the query duration is numeric before adding it
            if (isset($query[1]) && is_numeric($query[1])) {
                $db_query_total_time += $query[1];
            }
        }
        $db_query_total_time *= 1000; // convert seconds to milliseconds
    }
    $db_query_count = $wpdb->num_queries;


    // --- Server Configuration Checks ---
    $object_cache_active = wp_using_ext_object_cache();
    $php_version = PHP_VERSION;

    $get_status_meta = static function ($status) use ($status_labels) {
        if (isset($status_labels[$status])) {
            return $status_labels[$status];
        }

        return $status_labels['status-warn'];
    };

    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-speed');
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-performance"></span> <?php esc_html_e('Analyseur de Vitesse', 'sitepulse'); ?></h1>
        <p><?php esc_html_e('Cet outil analyse la performance interne de votre serveur et de votre base de données à chaque chargement de page.', 'sitepulse'); ?></p>

        <div class="speed-scan-actions">
            <button type="button" class="button button-primary" id="sitepulse-speed-rescan">
                <?php esc_html_e('Relancer un test', 'sitepulse'); ?>
            </button>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: human readable rate limit duration. */
                    esc_html__('Pour préserver les ressources serveur, un nouveau test manuel est disponible toutes les %s.', 'sitepulse'),
                    esc_html($rate_limit_label)
                );
                ?>
            </p>
            <div id="sitepulse-speed-scan-status" class="sitepulse-speed-status" role="status" aria-live="polite"></div>
        </div>

        <div class="speed-history-wrapper">
            <h2><?php esc_html_e('Historique des temps de réponse', 'sitepulse'); ?></h2>
            <div class="speed-history-visual">
                <canvas id="sitepulse-speed-history-chart" aria-describedby="sitepulse-speed-history-summary"></canvas>
            </div>
            <table class="widefat fixed" id="sitepulse-speed-history-table" aria-live="polite">
                <caption id="sitepulse-speed-history-summary" class="screen-reader-text">
                    <?php esc_html_e('Historique des mesures de temps de réponse du serveur.', 'sitepulse'); ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Horodatage', 'sitepulse'); ?></th>
                        <th scope="col"><?php esc_html_e('Temps serveur (ms)', 'sitepulse'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($history)) : ?>
                        <?php foreach ($history as $entry) : ?>
                            <tr>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $entry['timestamp'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n($entry['server_processing_ms'], 2)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="2"><?php esc_html_e('Aucun historique disponible pour le moment.', 'sitepulse'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="sitepulse-speed-recommendations" class="speed-recommendations">
            <h2><?php esc_html_e('Recommandations', 'sitepulse'); ?></h2>
            <ul>
                <?php
                $initial_recommendations = sitepulse_speed_analyzer_build_recommendations($latest_entry, $thresholds);

                foreach ($initial_recommendations as $recommendation) {
                    echo '<li>' . esc_html($recommendation) . '</li>';
                }
                ?>
            </ul>
        </div>

        <div id="sitepulse-speed-summary" class="speed-summary">
            <h2><?php esc_html_e('Résumé', 'sitepulse'); ?></h2>
            <div class="speed-grid summary-grid" id="sitepulse-speed-summary-grid">
                <?php foreach ($summary_meta as $metric_key => $meta) : ?>
                    <?php
                    $metric_data = isset($aggregates['metrics'][$metric_key]) ? $aggregates['metrics'][$metric_key] : null;
                    $metric_status = isset($metric_data['status']) ? $metric_data['status'] : 'status-warn';
                    $status_meta = $get_status_meta($metric_status);
                    $value = isset($metric_data['value']) ? $metric_data['value'] : null;
                    $formatted_value = ($value !== null)
                        ? sprintf(
                            /* translators: %s: duration in milliseconds. */
                            esc_html__('%s ms', 'sitepulse'),
                            esc_html(number_format_i18n((float) $value, 2))
                        )
                        : esc_html__('N/A', 'sitepulse');
                    ?>
                    <div class="speed-card summary-card" data-metric="<?php echo esc_attr($metric_key); ?>">
                        <h3 class="summary-title"><?php echo esc_html($meta['label']); ?></h3>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($metric_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($status_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($status_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text" data-summary-sr><?php echo esc_html($status_meta['sr']); ?></span>
                            <span class="status-reading" data-summary-value><?php echo $formatted_value; ?></span>
                        </span>
                        <p class="description"><?php echo esc_html($meta['description']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="description" id="sitepulse-speed-summary-note" aria-live="polite"><?php echo esc_html($summary_note); ?></p>
        </div>

        <div class="speed-grid">
            <!-- Server Processing Card -->
            <div class="speed-card">
                <h3><span class="dashicons dashicons-server"></span> <?php esc_html_e('Performance du Serveur (Backend)', 'sitepulse'); ?></h3>
                <p><?php esc_html_e('Ces métriques mesurent la vitesse à laquelle votre serveur exécute le code PHP et génère la page actuelle.', 'sitepulse'); ?></p>
                <ul class="health-list">
                    <?php
                    if ($page_generation_time >= $speed_critical_threshold) {
                        $gen_time_status = 'status-bad';
                    } elseif ($page_generation_time >= $speed_warning_threshold) {
                        $gen_time_status = 'status-warn';
                    } else {
                        $gen_time_status = 'status-ok';
                    }
                    ?>
                    <li>
                        <span class="metric-name"><?php esc_html_e('Temps de Génération de la Page', 'sitepulse'); ?></span>
                        <?php $gen_time_meta = $get_status_meta($gen_time_status); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($gen_time_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($gen_time_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($gen_time_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($gen_time_meta['sr']); ?></span>
                            <span class="status-reading"><?php
                            /* translators: %d: duration in milliseconds. */
                            printf(esc_html__('%d ms', 'sitepulse'), round($page_generation_time));
                            ?></span>
                        </span>
                        <p class="description"><?php printf(
                            esc_html__("C'est le temps total que met votre serveur pour préparer cette page. Un temps élevé (>%d ms) peut indiquer un hébergement lent ou un plugin qui consomme beaucoup de ressources.", 'sitepulse'),
                            (int) $speed_critical_threshold
                        ); ?></p>
                    </li>
                </ul>
            </div>

            <!-- Database Performance Card -->
            <div class="speed-card">
                <h3><span class="dashicons dashicons-database"></span> <?php esc_html_e('Performance de la Base de Données', 'sitepulse'); ?></h3>
                <p><?php esc_html_e('Analyse la communication entre WordPress et votre base de données pour cette page.', 'sitepulse'); ?></p>
                <ul class="health-list">
                    <?php
                    // Database Query Time Analysis
                    if ($savequeries_enabled) {
                        if ($db_query_total_time >= $speed_critical_threshold) {
                            $db_time_status = 'status-bad';
                        } elseif ($db_query_total_time >= $speed_warning_threshold) {
                            $db_time_status = 'status-warn';
                        } else {
                            $db_time_status = 'status-ok';
                        }
                        ?>
                        <li>
                            <span class="metric-name"><?php esc_html_e('Temps Total des Requêtes BDD', 'sitepulse'); ?></span>
                            <?php $db_time_meta = $get_status_meta($db_time_status); ?>
                            <span class="metric-value">
                                <span class="status-badge <?php echo esc_attr($db_time_status); ?>" aria-hidden="true">
                                    <span class="status-icon"><?php echo esc_html($db_time_meta['icon']); ?></span>
                                    <span class="status-text"><?php echo esc_html($db_time_meta['label']); ?></span>
                                </span>
                                <span class="screen-reader-text"><?php echo esc_html($db_time_meta['sr']); ?></span>
                                <span class="status-reading"><?php
                                /* translators: %d: duration in milliseconds. */
                                printf(esc_html__('%d ms', 'sitepulse'), round($db_query_total_time));
                                ?></span>
                            </span>
                            <p class="description"><?php esc_html_e("Le temps total passé à attendre la base de données. S'il est élevé, cela peut indiquer des requêtes complexes ou une base de données surchargée.", 'sitepulse'); ?></p>
                        </li>
                        <?php
                    } else {
                        ?>
                        <li>
                            <span class="metric-name"><?php esc_html_e('Temps Total des Requêtes BDD', 'sitepulse'); ?></span>
                            <?php $db_time_meta = $get_status_meta('status-warn'); ?>
                            <span class="metric-value">
                                <span class="status-badge status-warn" aria-hidden="true">
                                    <span class="status-icon"><?php echo esc_html($db_time_meta['icon']); ?></span>
                                    <span class="status-text"><?php echo esc_html($db_time_meta['label']); ?></span>
                                </span>
                                <span class="screen-reader-text"><?php echo esc_html($db_time_meta['sr']); ?></span>
                                <span class="status-reading"><?php esc_html_e('N/A', 'sitepulse'); ?></span>
                            </span>
                            <p class="description">
                                <?php
                                echo wp_kses(
                                    sprintf(
                                        /* translators: 1: SAVEQUERIES constant, 2: wp-config.php file name. */
                                        __('Pour activer cette mesure, ajoutez <code>%1$s</code> à votre fichier <code>%2$s</code>. <strong>Note :</strong> N\'utilisez ceci que pour le débogage, car cela peut ralentir votre site.', 'sitepulse'),
                                        "define('SAVEQUERIES', true);",
                                        'wp-config.php'
                                    ),
                                    [
                                        'code'   => [],
                                        'strong' => [],
                                    ]
                                );
                                ?>
                            </p>
                        </li>
                        <?php
                    }

                    // Database Query Count Analysis
                    $db_count_status = $db_query_count < 100 ? 'status-ok' : ($db_query_count < 200 ? 'status-warn' : 'status-bad');
                    ?>
                    <li>
                        <span class="metric-name"><?php esc_html_e('Nombre de Requêtes BDD', 'sitepulse'); ?></span>
                        <?php $db_count_meta = $get_status_meta($db_count_status); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($db_count_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($db_count_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($db_count_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($db_count_meta['sr']); ?></span>
                            <span class="status-reading"><?php echo esc_html($db_query_count); ?></span>
                        </span>
                        <p class="description"><?php esc_html_e("Le nombre de fois que WordPress a interrogé la base de données. Un nombre élevé (>100) peut être le signe d'un plugin ou d'un thème mal optimisé.", 'sitepulse'); ?></p>
                    </li>
                </ul>
            </div>
             <!-- Server Configuration Card -->
            <div class="speed-card">
                <h3><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e('Configuration Serveur', 'sitepulse'); ?></h3>
                <p><?php esc_html_e('Des réglages serveur optimaux sont essentiels pour la performance.', 'sitepulse'); ?></p>
                <ul class="health-list">
                    <?php
                    // Object Cache Check
                    $cache_status_class = $object_cache_active ? 'status-ok' : 'status-warn';
                    $cache_text = $object_cache_active ? esc_html__('Actif', 'sitepulse') : esc_html__('Non détecté', 'sitepulse');
                    ?>
                    <li>
                        <span class="metric-name"><?php esc_html_e('Object Cache', 'sitepulse'); ?></span>
                        <?php $cache_meta = $get_status_meta($cache_status_class); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($cache_status_class); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($cache_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($cache_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($cache_meta['sr']); ?></span>
                            <span class="status-reading"><?php echo esc_html($cache_text); ?></span>
                        </span>
                        <p class="description"><?php esc_html_e("Un cache d'objets persistant (ex: Redis, Memcached) accélère énormément les requêtes répétitives. Fortement recommandé.", 'sitepulse'); ?></p>
                    </li>
                    <?php
                    // PHP Version Check
                    $php_status = version_compare($php_version, '8.0', '>=') ? 'status-ok' : 'status-warn';
                    ?>
                    <li>
                        <span class="metric-name"><?php esc_html_e('Version de PHP', 'sitepulse'); ?></span>
                        <?php $php_meta = $get_status_meta($php_status); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($php_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($php_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($php_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($php_meta['sr']); ?></span>
                            <span class="status-reading"><?php echo esc_html($php_version); ?></span>
                        </span>
                        <p class="description"><?php esc_html_e('Les versions modernes de PHP (8.0+) sont beaucoup plus rapides et sécurisées. Demandez à votre hébergeur de mettre à jour si nécessaire.', 'sitepulse'); ?></p>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <?php
}
