<?php
/**
 * SitePulse Resource Monitor admin page.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

function sitepulse_resource_monitor_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $resource_monitor_notices = [];
    $refresh_feedback = '';

    if (isset($_POST['sitepulse_resource_monitor_refresh'])) {
        check_admin_referer('sitepulse_refresh_resource_snapshot');
        delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        sitepulse_resource_monitor_clear_history();

        $resource_monitor_notices[] = [
            'type'    => 'success',
            'message' => esc_html__('Les mesures et l’historique ont été actualisés.', 'sitepulse'),
        ];

        $refresh_feedback = esc_html__('Les mesures et l’historique ont été actualisés.', 'sitepulse');
    }

    if (isset($_GET['sitepulse_report'])) {
        $report_status = sanitize_key((string) $_GET['sitepulse_report']);

        if ($report_status === 'queued') {
            $resource_monitor_notices[] = [
                'type'    => 'success',
                'message' => esc_html__('Le rapport a été planifié et sera envoyé sous peu.', 'sitepulse'),
            ];
        } elseif ($report_status === 'executed') {
            $resource_monitor_notices[] = [
                'type'    => 'success',
                'message' => esc_html__('Le rapport a été généré avec succès.', 'sitepulse'),
            ];
        } elseif ($report_status === 'failed') {
            $resource_monitor_notices[] = [
                'type'    => 'error',
                'message' => esc_html__('Le rapport n’a pas pu être généré. Consultez les journaux pour plus de détails.', 'sitepulse'),
            ];
        }
    }

    if (isset($_GET['sitepulse_http_monitor'])) {
        $http_status = sanitize_key((string) $_GET['sitepulse_http_monitor']);

        if ($http_status === 'updated') {
            $resource_monitor_notices[] = [
                'type'    => 'success',
                'message' => esc_html__('Les seuils du moniteur HTTP ont été enregistrés.', 'sitepulse'),
            ];
        }
    }

    $snapshot = sitepulse_resource_monitor_get_snapshot();

    $history_result = sitepulse_resource_monitor_get_history([
        'per_page' => 288,
        'page'     => 1,
        'order'    => 'DESC',
    ]);

    $history_entries = isset($history_result['entries']) && is_array($history_result['entries'])
        ? array_reverse($history_result['entries'])
        : [];

    $history_summary = sitepulse_resource_monitor_calculate_history_summary($history_entries);
    $history_summary_text = sitepulse_resource_monitor_format_history_summary($history_summary);
    $history_for_js = sitepulse_resource_monitor_prepare_history_for_js($history_entries);
    $last_cron_timestamp = sitepulse_resource_monitor_get_last_cron_timestamp($history_entries);

    $aggregated_metrics = sitepulse_resource_monitor_calculate_aggregate_metrics($history_entries);

    $granularity_choices = [
        ['value' => 'raw', 'label' => __('Données brutes (5 min)', 'sitepulse')],
        ['value' => '15m', 'label' => __('Moyenne 15 minutes', 'sitepulse')],
        ['value' => '1h', 'label' => __('Moyenne horaire', 'sitepulse')],
        ['value' => '1d', 'label' => __('Moyenne quotidienne', 'sitepulse')],
    ];

    $history_initial = [
        'entries' => $history_for_js,
        'summary' => [
            'count'                 => (int) $history_summary['count'],
            'span'                  => (int) $history_summary['span'],
            'firstTimestamp'        => $history_summary['first_timestamp'],
            'lastTimestamp'         => $history_summary['last_timestamp'],
            'averageLoad'           => $history_summary['average_load'],
            'latestLoad'            => $history_summary['latest_load'],
            'averageMemoryPercent'  => $history_summary['average_memory_percent'],
            'latestMemoryPercent'   => $history_summary['latest_memory_percent'],
            'averageDiskUsedPercent'=> $history_summary['average_disk_used_percent'],
            'latestDiskUsedPercent' => $history_summary['latest_disk_used_percent'],
        ],
        'summaryText' => $history_summary_text,
        'granularity' => 'raw',
    ];

    $snapshot_meta = [
        'generatedAt' => isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : null,
        'source'      => isset($snapshot['source']) ? (string) $snapshot['source'] : 'manual',
    ];

    $rest_history_endpoint = function_exists('rest_url') ? rest_url('sitepulse/v1/resources/history') : '';
    $rest_aggregates_endpoint = function_exists('rest_url') ? rest_url('sitepulse/v1/resources/aggregates') : '';
    $rest_http_endpoint = function_exists('rest_url') ? rest_url('sitepulse/v1/resources/http') : '';
    $rest_nonce = wp_create_nonce('wp_rest');

    $http_stats = function_exists('sitepulse_http_monitor_get_stats')
        ? sitepulse_http_monitor_get_stats([
            'since' => (int) current_time('timestamp', true) - DAY_IN_SECONDS,
            'limit' => 25,
        ])
        : [
            'summary'    => [],
            'services'   => [],
            'samples'    => [],
            'thresholds' => [],
        ];

    $http_thresholds = function_exists('sitepulse_http_monitor_get_threshold_configuration')
        ? sitepulse_http_monitor_get_threshold_configuration()
        : ['latency' => 0, 'errorRate' => 0];

    $http_retention_days = (int) get_option(
        SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS,
        defined('SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS') ? (int) SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS : 14
    );

    if ($http_retention_days < 1) {
        $http_retention_days = defined('SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS')
            ? (int) SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS
            : 14;
    }

    $http_latency_value = isset($http_thresholds['latency']) ? (int) $http_thresholds['latency'] : 0;
    $http_error_value = isset($http_thresholds['errorRate']) ? (int) $http_thresholds['errorRate'] : 0;
    $http_settings_nonce = defined('SITEPULSE_NONCE_ACTION_HTTP_MONITOR_SETTINGS')
        ? SITEPULSE_NONCE_ACTION_HTTP_MONITOR_SETTINGS
        : 'sitepulse_http_monitor_settings';

    $last_report_raw = get_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_LAST_REPORT);
    $last_report_for_js = null;
    $last_report_display = null;

    if (is_array($last_report_raw)) {
        $last_generated_at = isset($last_report_raw['generated_at']) ? (int) $last_report_raw['generated_at'] : 0;
        $last_summary_text = isset($last_report_raw['summary_text']) ? (string) $last_report_raw['summary_text'] : '';
        $last_samples = isset($last_report_raw['samples']) && is_array($last_report_raw['samples']) ? $last_report_raw['samples'] : [];

        $last_report_for_js = [
            'generated_at' => $last_generated_at,
            'summary_text' => $last_summary_text,
            'samples'      => $last_samples,
        ];

        if (isset($last_report_raw['metrics']) && is_array($last_report_raw['metrics'])) {
            $last_report_for_js['metrics'] = $last_report_raw['metrics'];
        }

        $last_label = $last_generated_at > 0
            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_generated_at)
            : '';

        $last_report_display = [
            'label'   => $last_label,
            'summary' => $last_summary_text,
        ];
    }

    $sitepulse_localized = [
        'initialHistory' => $history_initial,
        'snapshot'       => $snapshot_meta,
        'lastAutomaticTimestamp' => $last_cron_timestamp,
        'locale'         => get_user_locale(),
        'dateFormat'     => get_option('date_format'),
        'timeFormat'     => get_option('time_format'),
        'rest'           => [
            'history'    => esc_url_raw($rest_history_endpoint),
            'aggregates' => esc_url_raw($rest_aggregates_endpoint),
            'http'       => esc_url_raw($rest_http_endpoint),
            'nonce'      => $rest_nonce,
        ],
        'granularity'    => [
            'default' => 'raw',
            'choices' => $granularity_choices,
        ],
        'aggregates'     => [
            'metrics'      => $aggregated_metrics,
            'summary'      => $history_summary,
            'summaryText'  => $history_summary_text,
        ],
        'httpMonitor'    => [
            'initial'       => $http_stats,
            'windowSeconds' => DAY_IN_SECONDS,
            'limit'         => 25,
        ],
        'reporting'      => [
            'lastReport' => $last_report_for_js,
        ],
        'request'        => [
            'perPage' => 288,
            'since'   => null,
        ],
        'i18n'           => [
            'loadLabel'         => esc_html__('Charge CPU (1 min)', 'sitepulse'),
            'memoryLabel'       => esc_html__('Mémoire utilisée (%)', 'sitepulse'),
            'diskLabel'         => esc_html__('Stockage utilisé (%)', 'sitepulse'),
            'percentAxisLabel'  => esc_html__('% d’utilisation', 'sitepulse'),
            'noHistory'         => esc_html__("Aucun historique disponible pour le moment.", 'sitepulse'),
            'timestamp'         => esc_html__('Horodatage', 'sitepulse'),
            'unavailable'       => esc_html__('N/A', 'sitepulse'),
            'memoryUsage'       => esc_html__('Mémoire utilisée', 'sitepulse'),
            'diskUsage'         => esc_html__('Stockage utilisé', 'sitepulse'),
            'diskFree'          => esc_html__('Stockage libre', 'sitepulse'),
            'cronPoint'         => esc_html__('Collecte automatique', 'sitepulse'),
            'manualPoint'       => esc_html__('Collecte manuelle', 'sitepulse'),
            'granularityLabel'  => esc_html__('Agrégation', 'sitepulse'),
            'aggregatesTitle'   => esc_html__('Statistiques avancées', 'sitepulse'),
            'aggregatesEmpty'   => esc_html__('Aucune donnée agrégée disponible pour cette sélection.', 'sitepulse'),
            'averageLabel'      => esc_html__('Moyenne', 'sitepulse'),
            'maxLabel'          => esc_html__('Max', 'sitepulse'),
            'p95Label'          => esc_html__('P95', 'sitepulse'),
            'trendLabel'        => esc_html__('Tendance (par heure)', 'sitepulse'),
            'trendUp'           => esc_html__('Hausse', 'sitepulse'),
            'trendDown'         => esc_html__('Baisse', 'sitepulse'),
            'trendFlat'         => esc_html__('Stable', 'sitepulse'),
            'reportQueued'      => esc_html__('Le rapport a été planifié et sera envoyé sous peu.', 'sitepulse'),
            'reportExecuted'    => esc_html__('Le rapport a été généré avec succès.', 'sitepulse'),
            'httpMonitorTitle'  => esc_html__('Services externes', 'sitepulse'),
            'httpMonitorSummary'=> esc_html__('Synthèse des appels sortants (24 h)', 'sitepulse'),
            'httpMonitorEmpty'  => esc_html__('Aucun appel externe enregistré sur la période.', 'sitepulse'),
            'httpMonitorLatency'=> esc_html__('Latence (moy./max/p95)', 'sitepulse'),
            'httpMonitorErrors' => esc_html__('Taux d’erreurs', 'sitepulse'),
            'httpMonitorRequests' => esc_html__('Requêtes', 'sitepulse'),
            'httpMonitorLastSeen'=> esc_html__('Dernière occurrence', 'sitepulse'),
            'httpMonitorSamples'=> esc_html__('Derniers appels', 'sitepulse'),
            'httpMonitorStatus' => esc_html__('Statut', 'sitepulse'),
            'httpMonitorDuration' => esc_html__('Durée', 'sitepulse'),
            'httpMonitorMethod' => esc_html__('Méthode', 'sitepulse'),
            'httpMonitorHost'   => esc_html__('Hôte', 'sitepulse'),
            'httpMonitorPath'   => esc_html__('Chemin', 'sitepulse'),
            'httpMonitorThresholdLatency' => esc_html__('Seuil latence (p95)', 'sitepulse'),
            'httpMonitorThresholdErrors'  => esc_html__('Seuil taux d’erreurs', 'sitepulse'),
            'httpMonitorRefresh' => esc_html__('Actualiser les statistiques', 'sitepulse'),
            'httpMonitorLoading' => esc_html__('Récupération des métriques des appels externes…', 'sitepulse'),
            'httpMonitorError'   => esc_html__('Impossible de récupérer les métriques des appels externes.', 'sitepulse'),
        ],
        'refreshFeedback' => $refresh_feedback,
        'refreshStatusId' => 'sitepulse-resource-refresh-status',
    ];

    wp_localize_script(
        'sitepulse-resource-monitor',
        'SitePulseResourceMonitor',
        $sitepulse_localized
    );

    if (!empty($snapshot['notices']) && is_array($snapshot['notices'])) {
        $resource_monitor_notices = array_merge($resource_monitor_notices, $snapshot['notices']);
    }

    $generated_at = isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;
    $generated_label = $generated_at > 0
        ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $generated_at)
        : esc_html__('Inconnue', 'sitepulse');

    $age = '';

    if ($generated_at > 0) {
        $age = human_time_diff($generated_at, (int) current_time('timestamp', true));
    }

    $last_automatic_notice = '';
    $now_utc = (int) current_time('timestamp', true);

    if ($last_cron_timestamp) {
        $last_auto_label = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_cron_timestamp);
        $last_auto_age = human_time_diff($last_cron_timestamp, $now_utc);
        $last_automatic_notice = sprintf(
            /* translators: 1: formatted date, 2: relative time. */
            esc_html__('Dernière collecte automatique : %1$s (%2$s).', 'sitepulse'),
            esc_html($last_auto_label),
            sprintf(
                /* translators: %s: human readable duration. */
                esc_html__('il y a %s', 'sitepulse'),
                esc_html($last_auto_age)
            )
        );
    } else {
        $last_automatic_notice = esc_html__('Aucune collecte automatique enregistrée pour le moment.', 'sitepulse');
    }

    $export_endpoint = admin_url('admin-post.php');
    $export_csv_url = wp_nonce_url(add_query_arg([
        'action' => 'sitepulse_resource_monitor_export',
        'format' => 'csv',
    ], $export_endpoint), SITEPULSE_NONCE_ACTION_RESOURCE_MONITOR_EXPORT);
    $export_json_url = wp_nonce_url(add_query_arg([
        'action' => 'sitepulse_resource_monitor_export',
        'format' => 'json',
    ], $export_endpoint), SITEPULSE_NONCE_ACTION_RESOURCE_MONITOR_EXPORT);

    $report_action_url = admin_url('admin-post.php');
    $unavailable_label = __('N/A', 'sitepulse');

    $metric_cards = [
        'load_1' => [
            'label'    => __('Charge CPU (1 min)', 'sitepulse'),
            'metric'   => $aggregated_metrics['load_1'] ?? [],
            'decimals' => 2,
            'suffix'   => '',
        ],
        'memory_percent' => [
            'label'    => __('Mémoire utilisée (%)', 'sitepulse'),
            'metric'   => $aggregated_metrics['memory_percent'] ?? [],
            'decimals' => 1,
            'suffix'   => '%',
        ],
        'disk_used' => [
            'label'    => __('Stockage utilisé (%)', 'sitepulse'),
            'metric'   => $aggregated_metrics['disk_used'] ?? [],
            'decimals' => 1,
            'suffix'   => '%',
        ],
    ];

    $metric_display = [];

    foreach ($metric_cards as $key => $card) {
        $metric = $card['metric'];
        $average_display = $unavailable_label;
        $max_display = $unavailable_label;
        $p95_display = $unavailable_label;
        $trend_display = $unavailable_label;
        $trend_direction = 'flat';

        if (isset($metric['average']) && $metric['average'] !== null) {
            $average_display = number_format_i18n((float) $metric['average'], $card['decimals']) . $card['suffix'];
        }

        if (isset($metric['max']) && $metric['max'] !== null) {
            $max_display = number_format_i18n((float) $metric['max'], $card['decimals']) . $card['suffix'];
        }

        if (isset($metric['percentiles']['p95']) && $metric['percentiles']['p95'] !== null) {
            $p95_display = number_format_i18n((float) $metric['percentiles']['p95'], $card['decimals']) . $card['suffix'];
        }

        if (isset($metric['trend']) && is_array($metric['trend'])) {
            $trend = $metric['trend'];
            $trend_direction = isset($trend['direction']) ? (string) $trend['direction'] : 'flat';
            if (isset($trend['slope_per_hour']) && is_numeric($trend['slope_per_hour'])) {
                $slope_value = number_format_i18n((float) $trend['slope_per_hour'], $card['decimals']) . $card['suffix'];
                $symbol = '→';
                if ($trend_direction === 'up') {
                    $symbol = '↑';
                } elseif ($trend_direction === 'down') {
                    $symbol = '↓';
                }
                $trend_display = sprintf('%s %s/h', $symbol, $slope_value);
            }
        }

        $metric_display[$key] = [
            'average'         => $average_display,
            'max'             => $max_display,
            'p95'             => $p95_display,
            'trend_value'     => $trend_display,
            'trend_direction' => $trend_direction,
        ];
    }
    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-resources');
    }
    ?>
    <div class="wrap sitepulse-resource-monitor">
        <h1><span class="dashicons-before dashicons-performance"></span> <?php esc_html_e('Moniteur de Ressources', 'sitepulse'); ?></h1>
        <?php if (!empty($resource_monitor_notices)) : ?>
            <div class="sitepulse-notices">
                <?php foreach ($resource_monitor_notices as $notice) : ?>
                    <div class="notice notice-<?php echo esc_attr($notice['type']); ?>">
                        <p><?php echo esc_html($notice['message']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="sitepulse-resource-grid">
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Charge CPU (1/5/15 min)', 'sitepulse'); ?></h2>
                <?php
                $load_display_output = isset($snapshot['load']) && is_array($snapshot['load'])
                    ? sitepulse_resource_monitor_format_load_display($snapshot['load'])
                    : (string) ($snapshot['load_display'] ?? '');
                ?>
                <p class="sitepulse-resource-value"><?php echo esc_html($load_display_output); ?></p>
            </div>
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Mémoire', 'sitepulse'); ?></h2>
                <p class="sitepulse-resource-value">
                    <?php
                    $memory_percent_display = isset($snapshot['memory_usage_percent']) && is_numeric($snapshot['memory_usage_percent'])
                        ? number_format_i18n((float) $snapshot['memory_usage_percent'], 1)
                        : null;

                    if ($memory_percent_display !== null) {
                        printf(esc_html__('%s %% utilisés', 'sitepulse'), esc_html($memory_percent_display));
                    } else {
                        echo esc_html((string) ($snapshot['memory_usage'] ?? ''));
                    }
                    ?>
                </p>
                <p class="sitepulse-resource-subvalue">
                    <?php
                    $memory_limit_label = isset($snapshot['memory_limit']) ? (string) $snapshot['memory_limit'] : '';

                    if ($memory_limit_label !== '') {
                        printf(
                            /* translators: 1: memory used, 2: memory limit. */
                            esc_html__('Utilisation : %1$s / Limite : %2$s', 'sitepulse'),
                            esc_html((string) ($snapshot['memory_usage'] ?? '')),
                            esc_html($memory_limit_label)
                        );
                    } else {
                        printf(
                            /* translators: %s: memory used. */
                            esc_html__('Utilisation : %s', 'sitepulse'),
                            esc_html((string) ($snapshot['memory_usage'] ?? ''))
                        );
                    }
                    ?>
                </p>
            </div>
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Stockage disque', 'sitepulse'); ?></h2>
                <p class="sitepulse-resource-value">
                    <?php
                    $disk_used_percent_display = isset($snapshot['disk_used_percent']) && is_numeric($snapshot['disk_used_percent'])
                        ? number_format_i18n((float) $snapshot['disk_used_percent'], 1)
                        : null;

                    if ($disk_used_percent_display !== null) {
                        printf(esc_html__('%s %% utilisés', 'sitepulse'), esc_html($disk_used_percent_display));
                    } else {
                        echo esc_html((string) ($snapshot['disk_used'] ?? ''));
                    }
                    ?>
                </p>
                <p class="sitepulse-resource-subvalue">
                    <?php
                    printf(
                        /* translators: 1: used disk, 2: free disk, 3: total disk. */
                        esc_html__('Utilisé : %1$s — Libre : %2$s (Total : %3$s)', 'sitepulse'),
                        esc_html((string) ($snapshot['disk_used'] ?? '')),
                        esc_html((string) ($snapshot['disk_free'] ?? '')),
                        esc_html((string) ($snapshot['disk_total'] ?? ''))
                    );
                    ?>
                </p>
            </div>
        </div>
        <div class="sitepulse-resource-meta">
            <p>
                <?php
                if ($age !== '') {
                    printf(
                        /* translators: 1: formatted date, 2: relative time. */
                        esc_html__('Mesures relevées le %1$s (%2$s).', 'sitepulse'),
                        esc_html($generated_label),
                        sprintf(
                            /* translators: %s: human-readable time difference. */
                            esc_html__('il y a %s', 'sitepulse'),
                            esc_html($age)
                        )
                    );
                } else {
                    printf(
                        /* translators: %s: formatted date. */
                        esc_html__('Mesures relevées le %s.', 'sitepulse'),
                        esc_html($generated_label)
                    );
                }
                ?>
            </p>
            <p><?php echo $last_automatic_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
            <div id="sitepulse-resource-refresh-status" class="screen-reader-text" role="status" aria-live="polite"></div>
            <form method="post">
                <?php wp_nonce_field('sitepulse_refresh_resource_snapshot'); ?>
                <p class="description"><?php esc_html_e('Cette action prend un nouvel instantané et réinitialise l’historique affiché.', 'sitepulse'); ?></p>
                <button type="submit" name="sitepulse_resource_monitor_refresh" class="button button-secondary">
                    <?php esc_html_e('Actualiser les mesures', 'sitepulse'); ?>
                </button>
            </form>
        </div>
        <section class="sitepulse-http-monitor" data-http-monitor>
            <div class="sitepulse-http-monitor-header">
                <h2><?php esc_html_e('Services externes', 'sitepulse'); ?></h2>
                <button type="button" class="button button-secondary" data-http-monitor-refresh>
                    <?php esc_html_e('Actualiser les statistiques', 'sitepulse'); ?>
                </button>
            </div>
            <p class="description" data-http-monitor-description></p>
            <div class="sitepulse-http-monitor-thresholds">
                <span data-http-monitor-threshold-latency></span>
                <span data-http-monitor-threshold-errors></span>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sitepulse-http-monitor-settings">
                <?php wp_nonce_field($http_settings_nonce); ?>
                <input type="hidden" name="action" value="sitepulse_save_http_monitor_settings">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sitepulse_http_latency_threshold"><?php esc_html_e('Seuil latence p95 (ms)', 'sitepulse'); ?></label></th>
                        <td>
                            <input name="sitepulse_http_latency_threshold" type="number" id="sitepulse_http_latency_threshold" class="small-text" min="0" step="10" value="<?php echo esc_attr($http_latency_value); ?>">
                            <p class="description"><?php esc_html_e('Définissez 0 pour désactiver les alertes sur la latence.', 'sitepulse'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sitepulse_http_error_rate"><?php esc_html_e('Seuil taux d’erreurs (%)', 'sitepulse'); ?></label></th>
                        <td>
                            <input name="sitepulse_http_error_rate" type="number" id="sitepulse_http_error_rate" class="small-text" min="0" max="100" step="1" value="<?php echo esc_attr($http_error_value); ?>">
                            <p class="description"><?php esc_html_e('Pourcentage maximal d’appels en erreur avant déclenchement d’une alerte.', 'sitepulse'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sitepulse_http_retention_days"><?php esc_html_e('Rétention des données (jours)', 'sitepulse'); ?></label></th>
                        <td>
                            <input name="sitepulse_http_retention_days" type="number" id="sitepulse_http_retention_days" class="small-text" min="1" max="365" step="1" value="<?php echo esc_attr($http_retention_days); ?>">
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Enregistrer les seuils', 'sitepulse'), 'secondary'); ?>
            </form>
            <div class="sitepulse-http-monitor-summary" data-http-monitor-summary></div>
            <div class="sitepulse-http-monitor-table-wrapper">
                <table class="widefat striped" data-http-monitor-table>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Hôte', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Chemin', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Méthode', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Requêtes', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Latence (moy./max)', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Taux d’erreurs', 'sitepulse'); ?></th>
                        </tr>
                    </thead>
                    <tbody data-http-monitor-table-body>
                        <tr data-empty>
                            <td colspan="6"><?php esc_html_e('Aucun appel externe enregistré sur la période.', 'sitepulse'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="sitepulse-http-monitor-samples" data-http-monitor-samples>
                <h3><?php esc_html_e('Derniers appels', 'sitepulse'); ?></h3>
                <ul data-http-monitor-sample-list>
                    <li data-empty><?php esc_html_e('Aucun appel externe enregistré sur la période.', 'sitepulse'); ?></li>
                </ul>
            </div>
        </section>
        <div class="sitepulse-resource-history" id="sitepulse-resource-history">
            <div class="sitepulse-resource-history-header">
                <h2><?php esc_html_e('Historique des ressources', 'sitepulse'); ?></h2>
                <div class="sitepulse-resource-history-controls">
                    <label for="sitepulse-resource-history-granularity"><?php esc_html_e('Agrégation', 'sitepulse'); ?></label>
                    <select id="sitepulse-resource-history-granularity">
                        <?php foreach ($granularity_choices as $choice) : ?>
                            <option value="<?php echo esc_attr($choice['value']); ?>"><?php echo esc_html($choice['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="sitepulse-resource-history-chart">
                <canvas id="sitepulse-resource-history-chart" aria-describedby="sitepulse-resource-history-summary"></canvas>
            </div>
            <p class="sitepulse-resource-history-empty" role="status" aria-live="polite" data-empty<?php if (!empty($history_entries)) { echo ' hidden'; } ?>>
                <?php esc_html_e("Aucun historique disponible pour le moment.", 'sitepulse'); ?>
            </p>
            <p id="sitepulse-resource-history-summary" class="sitepulse-resource-history-summary" role="status" aria-live="polite">
                <?php echo esc_html($history_summary_text); ?>
            </p>
            <div class="sitepulse-resource-history-actions">
                <a class="button button-secondary" href="<?php echo esc_url($export_csv_url); ?>"><?php esc_html_e('Exporter en CSV', 'sitepulse'); ?></a>
                <a class="button button-secondary" href="<?php echo esc_url($export_json_url); ?>"><?php esc_html_e('Exporter en JSON', 'sitepulse'); ?></a>
            </div>
        </div>
<div class="sitepulse-resource-aggregates" id="sitepulse-resource-aggregates">
            <h2><?php esc_html_e('Statistiques avancées', 'sitepulse'); ?></h2>
            <p id="sitepulse-resource-aggregates-summary" class="sitepulse-resource-aggregates-summary">
                <?php echo esc_html($history_summary_text); ?>
            </p>
            <div class="sitepulse-resource-aggregate-grid" data-aggregates>
                <?php foreach ($metric_cards as $key => $card) :
                    $display = $metric_display[$key];
                    $direction = $display['trend_direction'];
                    ?>
                    <div class="sitepulse-resource-aggregate-card is-<?php echo esc_attr($direction); ?>" data-metric="<?php echo esc_attr($key); ?>">
                        <h3><?php echo esc_html($card['label']); ?></h3>
                        <p class="sitepulse-resource-aggregate-line"><strong><?php esc_html_e('Moyenne', 'sitepulse'); ?> :</strong> <span data-metric-average><?php echo esc_html($display['average']); ?></span></p>
                        <p class="sitepulse-resource-aggregate-line"><strong><?php esc_html_e('Max', 'sitepulse'); ?> :</strong> <span data-metric-max><?php echo esc_html($display['max']); ?></span></p>
                        <p class="sitepulse-resource-aggregate-line"><strong><?php esc_html_e('P95', 'sitepulse'); ?> :</strong> <span data-metric-percentiles><?php echo esc_html($display['p95']); ?></span></p>
                        <p class="sitepulse-resource-aggregate-line"><strong><?php esc_html_e('Tendance (par heure)', 'sitepulse'); ?> :</strong> <span data-metric-trend><?php echo esc_html($display['trend_value']); ?></span></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="sitepulse-resource-report-actions">
            <h2><?php esc_html_e('Rapports programmés', 'sitepulse'); ?></h2>
            <form method="post" action="<?php echo esc_url($report_action_url); ?>" class="sitepulse-resource-report-form">
                <?php wp_nonce_field('sitepulse_resource_monitor_trigger_report'); ?>
                <input type="hidden" name="action" value="sitepulse_resource_monitor_trigger_report">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Générer un rapport maintenant', 'sitepulse'); ?>
                </button>
            </form>
            <?php if ($last_report_display) : ?>
                <p class="sitepulse-resource-report-meta">
                    <?php if ($last_report_display['label']) : ?>
                        <?php printf(/* translators: %s: report generated date. */ esc_html__('Dernier rapport généré le %s.', 'sitepulse'), esc_html($last_report_display['label'])); ?>
                    <?php endif; ?>
                    <?php if ($last_report_display['summary']) : ?>
                        <span><?php echo esc_html($last_report_display['summary']); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Converts a PHP memory limit value to bytes when possible.
 *
 * @param mixed $memory_limit_ini Raw memory_limit configuration value.
 * @return int|null
 */
