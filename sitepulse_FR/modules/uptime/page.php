<?php
/**
 * SitePulse uptime tracker admin page.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

function sitepulse_uptime_tracker_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $uptime_log = sitepulse_normalize_uptime_log(get_option(SITEPULSE_OPTION_UPTIME_LOG, []));
    $uptime_log = sitepulse_trim_uptime_log($uptime_log);
    $uptime_archive = sitepulse_get_uptime_archive();
    $agents = sitepulse_uptime_get_agents();
    $sla_reports = sitepulse_uptime_get_sla_reports();
    $sla_settings = sitepulse_uptime_get_sla_automation_settings();

    if (!is_array($sla_reports)) {
        $sla_reports = [];
    }

    if (!is_array($sla_settings)) {
        $sla_settings = [];
    }
    $available_months = sitepulse_uptime_get_archive_months($uptime_archive);
    $requested_month = '';

    if (isset($_GET['sitepulse_sla_month'])) {
        $requested_month_raw = wp_unslash($_GET['sitepulse_sla_month']);
        $requested_month = is_string($requested_month_raw) ? sanitize_text_field($requested_month_raw) : '';
    }

    $selected_month_key = '';

    if (!empty($available_months)) {
        $month_keys = array_keys($available_months);
        $selected_month_key = isset($month_keys[0]) ? $month_keys[0] : '';

        if ($requested_month !== '' && isset($available_months[$requested_month])) {
            $selected_month_key = $requested_month;
        }
    }

    $sla_error_code = '';

    if (isset($_GET['sitepulse_sla_error'])) {
        $sla_error_raw = wp_unslash($_GET['sitepulse_sla_error']);
        $sla_error_code = is_string($sla_error_raw) ? sanitize_key($sla_error_raw) : '';
    }

    $sla_error_messages = [
        'invalid-month' => __('La période demandée est invalide.', 'sitepulse'),
        'missing-data'  => __('Aucune archive ne correspond à cette période.', 'sitepulse'),
        'empty-period'  => __('Aucune donnée exploitable pour cette période.', 'sitepulse'),
    ];

    $sla_report_status = isset($_GET['sitepulse_sla_report_status'])
        ? sanitize_key(wp_unslash($_GET['sitepulse_sla_report_status']))
        : '';
    $sla_report_id = isset($_GET['sitepulse_sla_report_id'])
        ? sanitize_text_field(wp_unslash($_GET['sitepulse_sla_report_id']))
        : '';
    $sla_settings_status = isset($_GET['sitepulse_sla_settings'])
        ? sanitize_key(wp_unslash($_GET['sitepulse_sla_settings']))
        : '';
    $sla_report_error_messages = [
        'sitepulse_upload_unsupported'  => __('Impossible de générer le rapport : répertoire d’upload indisponible.', 'sitepulse'),
        'sitepulse_upload_error'        => __('Impossible de générer le rapport : vérifiez les permissions du dossier d’upload.', 'sitepulse'),
        'sitepulse_upload_permission'   => __('Impossible de créer le dossier de rapports SLA.', 'sitepulse'),
        'sitepulse_report_csv'          => __('Impossible de générer le fichier CSV du rapport.', 'sitepulse'),
        'sitepulse_report_pdf'          => __('Impossible de générer le PDF du rapport.', 'sitepulse'),
        'sitepulse_report_write_failed' => __('Impossible d’enregistrer les métadonnées du rapport.', 'sitepulse'),
    ];
    $sla_report_notice_message = '';

    if ($sla_report_status && 'success' !== $sla_report_status) {
        $sla_report_notice_message = isset($sla_report_error_messages[$sla_report_status])
            ? $sla_report_error_messages[$sla_report_status]
            : sprintf(__('Impossible de générer le rapport SLA (%s).', 'sitepulse'), $sla_report_status);
    }

    $latest_generated_report = null;

    if (!empty($sla_reports)) {
        foreach ($sla_reports as $report_entry) {
            if (isset($report_entry['id']) && $report_entry['id'] === $sla_report_id) {
                $latest_generated_report = $report_entry;
                break;
            }
        }

        if (null === $latest_generated_report) {
            $latest_generated_report = $sla_reports[0];
        }
    }

    $sla_next_run_label = '';
    $sla_next_run_relative = '';

    if (!empty($sla_settings['enabled']) && !empty($sla_settings['next_run'])) {
        $next_run_timestamp = (int) $sla_settings['next_run'];
        $sla_next_run_label = function_exists('wp_date')
            ? wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $next_run_timestamp)
            : date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $next_run_timestamp);
        $sla_next_run_relative = sitepulse_uptime_format_relative_time($next_run_timestamp, (int) current_time('timestamp'));
    }

    $preview_metrics = [
        'global' => [
            'total_checks'       => 0,
            'up_checks'          => 0,
            'down_checks'        => 0,
            'maintenance_checks' => 0,
            'latency_sum'        => 0.0,
            'latency_count'      => 0,
            'ttfb_sum'           => 0.0,
            'ttfb_count'         => 0,
        ],
    ];

    if ($selected_month_key !== '' && isset($available_months[$selected_month_key])) {
        $period = $available_months[$selected_month_key];
        $preview_metrics = sitepulse_uptime_collect_metrics_for_period(
            $uptime_archive,
            (int) $period['start'],
            (int) $period['end']
        );
    }

    $preview_global = isset($preview_metrics['global']) && is_array($preview_metrics['global'])
        ? $preview_metrics['global']
        : [
            'total_checks'       => 0,
            'up_checks'          => 0,
            'down_checks'        => 0,
            'maintenance_checks' => 0,
            'latency_sum'        => 0.0,
            'latency_count'      => 0,
            'ttfb_sum'           => 0.0,
            'ttfb_count'         => 0,
        ];
    $preview_effective_total = isset($preview_global['total_checks']) ? (int) $preview_global['total_checks'] : 0;
    $preview_weighted_total = 0.0;
    $preview_weighted_up = 0.0;
    $preview_weighted_latency_sum = 0.0;
    $preview_weighted_latency_count = 0.0;
    $preview_weighted_ttfb_sum = 0.0;
    $preview_weighted_ttfb_count = 0.0;

    if (isset($preview_metrics['agents']) && is_array($preview_metrics['agents'])) {
        foreach ($preview_metrics['agents'] as $agent_id => $agent_totals) {
            $agent_config = isset($agents[$agent_id]) ? $agents[$agent_id] : null;

            if (!sitepulse_uptime_agent_is_active($agent_id, $agent_config)) {
                continue;
            }

            $weight = sitepulse_uptime_get_agent_weight($agent_id, $agent_config);

            if ($weight <= 0) {
                continue;
            }

            $agent_effective_total = isset($agent_totals['total'], $agent_totals['maintenance'])
                ? max(0, (int) $agent_totals['total'] - (int) $agent_totals['maintenance'])
                : 0;

            $preview_weighted_total += $agent_effective_total * $weight;
            $preview_weighted_up += (isset($agent_totals['up']) ? (int) $agent_totals['up'] : 0) * $weight;
            $preview_weighted_latency_sum += (isset($agent_totals['latency_sum']) ? (float) $agent_totals['latency_sum'] : 0.0) * $weight;
            $preview_weighted_latency_count += (isset($agent_totals['latency_count']) ? (int) $agent_totals['latency_count'] : 0) * $weight;
            $preview_weighted_ttfb_sum += (isset($agent_totals['ttfb_sum']) ? (float) $agent_totals['ttfb_sum'] : 0.0) * $weight;
            $preview_weighted_ttfb_count += (isset($agent_totals['ttfb_count']) ? (int) $agent_totals['ttfb_count'] : 0) * $weight;
        }
    }

    if ($preview_weighted_total > 0) {
        $preview_uptime = ($preview_weighted_up / $preview_weighted_total) * 100;
    } else {
        $preview_uptime = $preview_effective_total > 0
            ? ($preview_global['up_checks'] / max(1, $preview_effective_total)) * 100
            : 100.0;
    }
    $preview_incidents = isset($preview_global['down_checks']) ? (int) $preview_global['down_checks'] : 0;
    $preview_maintenance = isset($preview_global['maintenance_checks']) ? (int) $preview_global['maintenance_checks'] : 0;
    if ($preview_weighted_ttfb_count > 0) {
        $preview_ttfb_avg = ($preview_weighted_ttfb_sum / $preview_weighted_ttfb_count) * 1000;
    } elseif (isset($preview_global['ttfb_sum'], $preview_global['ttfb_count']) && $preview_global['ttfb_count'] > 0) {
        $preview_ttfb_avg = ($preview_global['ttfb_sum'] / $preview_global['ttfb_count']) * 1000;
    } else {
        $preview_ttfb_avg = null;
    }

    if ($preview_weighted_latency_count > 0) {
        $preview_latency_avg = ($preview_weighted_latency_sum / $preview_weighted_latency_count) * 1000;
    } elseif (isset($preview_global['latency_sum'], $preview_global['latency_count']) && $preview_global['latency_count'] > 0) {
        $preview_latency_avg = ($preview_global['latency_sum'] / $preview_global['latency_count']) * 1000;
    } else {
        $preview_latency_avg = null;
    }
    $preview_ttfb_count = isset($preview_global['ttfb_count']) ? (int) $preview_global['ttfb_count'] : 0;
    $preview_latency_count = isset($preview_global['latency_count']) ? (int) $preview_global['latency_count'] : 0;
    $preview_month_label = ($selected_month_key !== '' && isset($available_months[$selected_month_key]['label']))
        ? $available_months[$selected_month_key]['label']
        : '';
    $sla_snapshot = sitepulse_uptime_build_sla_windows($uptime_log, [7, 30], $agents);

    if (!is_array($sla_snapshot)) {
        $sla_snapshot = [
            'summary' => [],
            'windows' => [],
        ];
    } else {
        $sla_snapshot = wp_parse_args(
            $sla_snapshot,
            [
                'summary' => [],
                'windows' => [],
            ]
        );

        if (!is_array($sla_snapshot['summary'])) {
            $sla_snapshot['summary'] = [];
        }

        if (!is_array($sla_snapshot['windows'])) {
            $sla_snapshot['windows'] = [];
        }
    }
    $total_checks = count($uptime_log);
    $boolean_checks = array_values(array_filter($uptime_log, function ($entry) {
        return isset($entry['status']) && is_bool($entry['status']);
    }));
    $evaluated_checks = count($boolean_checks);
    $up_checks = count(array_filter($boolean_checks, function ($entry) {
        return isset($entry['status']) && true === $entry['status'];
    }));
    $uptime_percentage = $evaluated_checks > 0 ? ($up_checks / $evaluated_checks) * 100 : 100;
    $date_format = get_option('date_format');
    $time_format = get_option('time_format');
    $current_incident_duration = '';
    $current_incident_start = null;

    if (!empty($uptime_log)) {
        $last_entry = end($uptime_log);
        if (isset($last_entry['status']) && is_bool($last_entry['status']) && false === $last_entry['status']) {
            $current_incident_start = isset($last_entry['incident_start']) ? (int) $last_entry['incident_start'] : (int) $last_entry['timestamp'];
            $current_timestamp = (int) current_time('timestamp');
            $current_incident_duration = human_time_diff($current_incident_start, $current_timestamp);
        }
        reset($uptime_log);
    }
    $trend_entries = array_slice($uptime_archive, -30, null, true);
    $trend_data = [];

    foreach ($trend_entries as $day_key => $daily_entry) {
        $total = isset($daily_entry['total']) ? max(0, (int) $daily_entry['total']) : 0;
        $maintenance = isset($daily_entry['maintenance']) ? max(0, (int) $daily_entry['maintenance']) : 0;
        $effective_total = max(0, $total - $maintenance);
        $up = isset($daily_entry['up']) ? (int) $daily_entry['up'] : 0;
        $uptime_value = $effective_total > 0 ? ($up / $effective_total) * 100 : 100;
        $uptime_value = max(0, min(100, $uptime_value));
        $bar_height = (int) max(4, round($uptime_value));
        $trend_timestamp = isset($daily_entry['last_timestamp']) ? (int) $daily_entry['last_timestamp'] : strtotime($day_key . ' 23:59:59');
        $formatted_day = wp_date($date_format, $trend_timestamp);
        $formatted_value = number_format_i18n($uptime_value, 2);
        $total_label = number_format_i18n($total);
        $bar_class = 'uptime-trend__bar--high';

        if ($uptime_value < 95) {
            $bar_class = 'uptime-trend__bar--low';
        } elseif ($uptime_value < 99) {
            $bar_class = 'uptime-trend__bar--medium';
        }

        $trend_data[] = [
            'height' => $bar_height,
            'class'  => $bar_class,
            'label'  => sprintf(
                /* translators: 1: formatted date, 2: uptime percentage, 3: number of checks. */
                __('Disponibilité du %1$s : %2$s%% (%3$s contrôles)', 'sitepulse'),
                $formatted_day,
                $formatted_value,
                $total_label
            ),
        ];
    }

    $seven_day_metrics = sitepulse_calculate_uptime_window_metrics($uptime_archive, 7, $agents);
    $thirty_day_metrics = sitepulse_calculate_uptime_window_metrics($uptime_archive, 30, $agents);
    $agent_metrics = sitepulse_calculate_agent_uptime_metrics($uptime_archive, 30, $agents);
    $region_metrics = sitepulse_calculate_region_uptime_metrics($agent_metrics, $agents);
    $maintenance_windows = sitepulse_uptime_get_maintenance_windows();
    $maintenance_notice_log = sitepulse_uptime_get_maintenance_notice_log();
    $latency_threshold_option = get_option(
        SITEPULSE_OPTION_UPTIME_LATENCY_THRESHOLD,
        defined('SITEPULSE_DEFAULT_UPTIME_LATENCY_THRESHOLD') ? SITEPULSE_DEFAULT_UPTIME_LATENCY_THRESHOLD : 0
    );
    $latency_threshold = function_exists('sitepulse_sanitize_uptime_latency_threshold')
        ? sitepulse_sanitize_uptime_latency_threshold($latency_threshold_option)
        : (is_numeric($latency_threshold_option) ? (float) $latency_threshold_option : 0.0);
    $format_latency_ms = static function ($seconds) {
        if (null === $seconds || !is_numeric($seconds) || $seconds < 0) {
            return '—';
        }

        $milliseconds = (float) $seconds * 1000;
        $precision = $milliseconds >= 100 ? 0 : 1;

        return number_format_i18n($milliseconds, $precision) . ' ms';
    };
    $violation_type_labels = [
        'latency' => __('Latence', 'sitepulse'),
        'keyword' => __('Mot-clé', 'sitepulse'),
    ];
    $ttfb_30_avg = isset($thirty_day_metrics['ttfb_avg']) ? $thirty_day_metrics['ttfb_avg'] : null;
    $ttfb_30_count = isset($thirty_day_metrics['ttfb_count']) ? (int) $thirty_day_metrics['ttfb_count'] : 0;
    $latency_30_avg = isset($thirty_day_metrics['latency_avg']) ? $thirty_day_metrics['latency_avg'] : null;
    $latency_30_count = isset($thirty_day_metrics['latency_count']) ? (int) $thirty_day_metrics['latency_count'] : 0;

    $last_checks = [];

    foreach ($uptime_log as $entry) {
        if (!isset($entry['agent'])) {
            continue;
        }

        $agent_id = sitepulse_uptime_normalize_agent_id($entry['agent']);

        if (!isset($last_checks[$agent_id]) || $entry['timestamp'] >= $last_checks[$agent_id]['timestamp']) {
            $last_checks[$agent_id] = $entry;
        }
    }

    $current_timestamp = (int) current_time('timestamp');
    $remote_queue_payload = sitepulse_uptime_get_remote_queue_metrics();
    $remote_queue_overview = sitepulse_uptime_analyze_remote_queue($remote_queue_payload, $current_timestamp);
    $remote_queue_metrics = isset($remote_queue_overview['metrics']) && is_array($remote_queue_overview['metrics'])
        ? $remote_queue_overview['metrics']
        : [];
    $remote_queue_status = isset($remote_queue_overview['status']) && is_array($remote_queue_overview['status'])
        ? $remote_queue_overview['status']
        : [];
    $remote_queue_metadata = isset($remote_queue_overview['metadata']) && is_array($remote_queue_overview['metadata'])
        ? $remote_queue_overview['metadata']
        : [];
    $remote_queue_schedule = isset($remote_queue_overview['schedule']) && is_array($remote_queue_overview['schedule'])
        ? $remote_queue_overview['schedule']
        : [];

    $remote_queue_updated_at = isset($remote_queue_overview['updated_at']) ? (int) $remote_queue_overview['updated_at'] : 0;
    $remote_queue_requested = isset($remote_queue_metrics['requested']) ? (int) $remote_queue_metrics['requested'] : 0;
    $remote_queue_retained = isset($remote_queue_metrics['retained']) ? (int) $remote_queue_metrics['retained'] : 0;
    $remote_queue_queue_length = isset($remote_queue_metrics['queue_length']) ? (int) $remote_queue_metrics['queue_length'] : 0;
    $remote_queue_delayed_jobs = isset($remote_queue_metrics['delayed_jobs']) ? (int) $remote_queue_metrics['delayed_jobs'] : 0;
    $remote_queue_max_wait = isset($remote_queue_metrics['max_wait_seconds']) ? (int) $remote_queue_metrics['max_wait_seconds'] : 0;
    $remote_queue_avg_wait = isset($remote_queue_metrics['avg_wait_seconds']) ? (int) $remote_queue_metrics['avg_wait_seconds'] : 0;
    $remote_queue_limit_ttl = isset($remote_queue_metrics['limit_ttl']) ? (int) $remote_queue_metrics['limit_ttl'] : 0;
    $remote_queue_limit_size = isset($remote_queue_metrics['limit_size']) ? (int) $remote_queue_metrics['limit_size'] : 0;
    $remote_queue_dropped_total = isset($remote_queue_metrics['dropped_total']) ? (int) $remote_queue_metrics['dropped_total'] : 0;
    $remote_queue_prioritized_jobs = isset($remote_queue_metrics['prioritized_jobs']) ? (int) $remote_queue_metrics['prioritized_jobs'] : 0;
    $remote_queue_max_priority = isset($remote_queue_metrics['max_priority']) ? (int) $remote_queue_metrics['max_priority'] : 0;
    $remote_queue_avg_priority = isset($remote_queue_metrics['avg_priority']) ? (int) $remote_queue_metrics['avg_priority'] : 0;

    $queue_status = isset($remote_queue_status['level']) ? (string) $remote_queue_status['level'] : 'ok';
    $queue_status_headline = isset($remote_queue_status['headline']) ? (string) $remote_queue_status['headline'] : __('File d’orchestration nominale', 'sitepulse');
    $queue_status_icon = isset($remote_queue_status['icon']) ? (string) $remote_queue_status['icon'] : 'yes-alt';
    $queue_status_notes = isset($remote_queue_status['notes']) && is_array($remote_queue_status['notes'])
        ? array_values(array_filter(array_map('strval', $remote_queue_status['notes'])))
        : [];

    $schedule_next = isset($remote_queue_schedule['next']) && is_array($remote_queue_schedule['next'])
        ? $remote_queue_schedule['next']
        : ['label' => '—'];
    $schedule_oldest = isset($remote_queue_schedule['oldest']) && is_array($remote_queue_schedule['oldest'])
        ? $remote_queue_schedule['oldest']
        : ['label' => '—'];

    $next_schedule_label = isset($schedule_next['label']) && $schedule_next['label'] !== ''
        ? (string) $schedule_next['label']
        : '—';
    $oldest_created_label = isset($schedule_oldest['label']) && $schedule_oldest['label'] !== ''
        ? (string) $schedule_oldest['label']
        : '—';

    $updated_descriptor = isset($remote_queue_metadata['updated']) && is_array($remote_queue_metadata['updated'])
        ? $remote_queue_metadata['updated']
        : ['formatted' => null, 'relative' => null];

    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-uptime');
    }
    ?>
    <div class="wrap">
        <?php if ('success' === $sla_report_status && $latest_generated_report) :
            $csv_url = isset($latest_generated_report['files']['csv']['url']) ? $latest_generated_report['files']['csv']['url'] : '';
            $pdf_url = isset($latest_generated_report['files']['pdf']['url']) ? $latest_generated_report['files']['pdf']['url'] : '';
            $report_label = isset($latest_generated_report['period']) ? sitepulse_uptime_format_report_period($latest_generated_report['period']) : '';
        ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    printf(
                        esc_html__('Rapport SLA généré avec succès (%s).', 'sitepulse'),
                        esc_html($report_label !== '' ? $report_label : $latest_generated_report['id'])
                    );
                    ?>
                    <?php if ($csv_url || $pdf_url) : ?>
                        <?php esc_html_e('Téléchargements :', 'sitepulse'); ?>
                        <?php if ($csv_url) : ?>
                            <a href="<?php echo esc_url($csv_url); ?>" class="button-link">CSV</a>
                        <?php endif; ?>
                        <?php if ($pdf_url) : ?>
                            <a href="<?php echo esc_url($pdf_url); ?>" class="button-link">PDF</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php elseif ($sla_report_notice_message !== '') : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($sla_report_notice_message); ?></p>
            </div>
        <?php endif; ?>
        <?php if ('updated' === $sla_settings_status) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Les préférences d’automatisation des rapports ont été mises à jour.', 'sitepulse'); ?></p>
            </div>
        <?php endif; ?>
        <h1><span class="dashicons-before dashicons-chart-bar"></span> <?php esc_html_e('Suivi de Disponibilité', 'sitepulse'); ?></h1>
        <p>
            <?php
            printf(
                /* translators: %s: number of uptime checks. */
                esc_html__('Cet outil vérifie la disponibilité de votre site toutes les heures. Voici le statut des %s dernières vérifications.', 'sitepulse'),
                esc_html(number_format_i18n($total_checks))
            );
            ?>
        </p>
        <?php if (!empty($maintenance_notice_log)) :
            $recent_maintenance_notices = array_slice(array_reverse($maintenance_notice_log), 0, 5);
        ?>
            <div class="notice notice-info sitepulse-maintenance-history">
                <p><strong><?php esc_html_e('Contrôles récemment ignorés pour maintenance', 'sitepulse'); ?></strong></p>
                <ul>
                    <?php foreach ($recent_maintenance_notices as $notice_entry) :
                        $notice_message = isset($notice_entry['message']) ? (string) $notice_entry['message'] : '';
                        $notice_timestamp = isset($notice_entry['timestamp']) ? (int) $notice_entry['timestamp'] : 0;
                        $notice_time = $notice_timestamp > 0
                            ? date_i18n($date_format . ' ' . $time_format, $notice_timestamp)
                            : '';
                        if ($notice_message === '') {
                            continue;
                        }
                    ?>
                    <li>
                        <?php if ('' !== $notice_time) : ?>
                            <strong><?php echo esc_html($notice_time); ?></strong>
                            <span aria-hidden="true">—</span>
                        <?php endif; ?>
                        <?php echo esc_html($notice_message); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p><?php esc_html_e('Ces événements sont consignés pour assurer une traçabilité des suspensions automatiques d’alertes.', 'sitepulse'); ?></p>
            </div>
        <?php endif; ?>
        <h2>
            <?php
            echo wp_kses_post(
                sprintf(
                    /* translators: 1: number of checks, 2: uptime percentage. */
                    __('Disponibilité (%1$s dernières heures) : <strong style="font-size: 1.4em;">%2$s%%</strong>', 'sitepulse'),
                    esc_html(number_format_i18n($total_checks)),
                    esc_html(number_format_i18n($uptime_percentage, 2))
                )
            );
            ?>
        </h2>
        <div class="uptime-summary-grid">
            <div class="uptime-summary-card">
                <h3><?php esc_html_e('Disponibilité 7 derniers jours', 'sitepulse'); ?></h3>
                <p class="uptime-summary-card__value"><?php echo esc_html(number_format_i18n($seven_day_metrics['uptime'], 2)); ?>%</p>
                <p class="uptime-summary-card__meta"><?php
                    printf(
                        /* translators: 1: total checks, 2: incidents */
                        esc_html__('Sur %1$s contrôles (%2$s incidents)', 'sitepulse'),
                        esc_html(number_format_i18n($seven_day_metrics['total_checks'])),
                        esc_html(number_format_i18n($seven_day_metrics['down_checks']))
                    );
                ?></p>
            </div>
            <div class="uptime-summary-card">
                <h3><?php esc_html_e('Disponibilité 30 derniers jours', 'sitepulse'); ?></h3>
                <p class="uptime-summary-card__value"><?php echo esc_html(number_format_i18n($thirty_day_metrics['uptime'], 2)); ?>%</p>
                <p class="uptime-summary-card__meta"><?php
                    printf(
                        /* translators: 1: total checks, 2: incidents */
                        esc_html__('Sur %1$s contrôles (%2$s incidents)', 'sitepulse'),
                        esc_html(number_format_i18n($thirty_day_metrics['total_checks'])),
                        esc_html(number_format_i18n($thirty_day_metrics['down_checks']))
                    );
                ?></p>
            </div>
            <div class="uptime-summary-card">
                <h3><?php esc_html_e('TTFB moyen (30 jours)', 'sitepulse'); ?></h3>
                <p class="uptime-summary-card__value"><?php echo esc_html($format_latency_ms($ttfb_30_avg)); ?></p>
                <p class="uptime-summary-card__meta"><?php
                    $ttfb_measurements_text = $ttfb_30_count > 0
                        ? sprintf(
                            /* translators: %s: number of samples. */
                            _n('%s mesure analysée', '%s mesures analysées', $ttfb_30_count, 'sitepulse'),
                            number_format_i18n($ttfb_30_count)
                        )
                        : __('Aucune mesure disponible', 'sitepulse');
                    echo esc_html($ttfb_measurements_text);
                ?></p>
            </div>
            <div class="uptime-summary-card">
                <h3><?php esc_html_e('Latence moyenne (30 jours)', 'sitepulse'); ?></h3>
                <p class="uptime-summary-card__value"><?php echo esc_html($format_latency_ms($latency_30_avg)); ?></p>
                <p class="uptime-summary-card__meta"><?php
                    $latency_threshold_text = $latency_threshold > 0
                        ? sprintf(
                            /* translators: %s: latency threshold. */
                            __('Seuil : %s s', 'sitepulse'),
                            number_format_i18n($latency_threshold, 2)
                        )
                        : __('Seuil : non défini', 'sitepulse');
                    $latency_measurements_text = $latency_30_count > 0
                        ? sprintf(
                            /* translators: %s: number of samples. */
                            _n('%s mesure analysée', '%s mesures analysées', $latency_30_count, 'sitepulse'),
                            number_format_i18n($latency_30_count)
                        )
                        : __('Aucune mesure disponible', 'sitepulse');
                    echo esc_html($latency_threshold_text . ' • ' . $latency_measurements_text);
                ?></p>
            </div>
        </div>
        <div class="card" id="sitepulse-sla-reports">
            <h2><?php esc_html_e('Rapports SLA consolidés', 'sitepulse'); ?></h2>
            <div class="sitepulse-sla-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sitepulse-sla-actions__form">
                    <?php wp_nonce_field('sitepulse_generate_uptime_report'); ?>
                    <input type="hidden" name="action" value="sitepulse_generate_uptime_report" />
                    <p><?php esc_html_e('Générez un rapport SLA multi-agents sur les fenêtres suivantes :', 'sitepulse'); ?></p>
                    <ul class="sitepulse-sla-actions__windows">
                        <?php foreach ($sla_snapshot['windows'] as $window_key => $window_details) :
                            $days = isset($window_details['days']) ? (int) $window_details['days'] : 0;
                            $availability = isset($window_details['global']['availability']) ? (float) $window_details['global']['availability'] : 100.0;
                            $downtime = isset($window_details['global']['downtime_total']) ? (float) $window_details['global']['downtime_total'] : 0.0;
                        ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="sitepulse_uptime_windows[]" value="<?php echo esc_attr($days); ?>" checked />
                                    <?php
                                    printf(
                                        esc_html__('%1$s — %2$s%% de disponibilité (%3$s d’indisponibilité)', 'sitepulse'),
                                        esc_html(isset($window_details['label']) ? $window_details['label'] : $window_key),
                                        esc_html(number_format_i18n($availability, 2)),
                                        esc_html(sitepulse_uptime_format_duration_i18n($downtime))
                                    );
                                    ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php submit_button(__('Générer le rapport', 'sitepulse'), 'primary', 'submit', false); ?>
                </form>
                <div class="sitepulse-sla-actions__reports">
                    <h3><?php esc_html_e('Derniers rapports disponibles', 'sitepulse'); ?></h3>
                    <?php if (!empty($sla_reports)) : ?>
                        <ul>
                            <?php foreach ($sla_reports as $report_entry) :
                                $report_period = isset($report_entry['period']) ? sitepulse_uptime_format_report_period($report_entry['period']) : '';
                                $csv_url = isset($report_entry['files']['csv']['url']) ? $report_entry['files']['csv']['url'] : '';
                                $pdf_url = isset($report_entry['files']['pdf']['url']) ? $report_entry['files']['pdf']['url'] : '';
                                $generated_on = isset($report_entry['generated_at']) ? date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) $report_entry['generated_at']) : '';
                            ?>
                                <li>
                                    <strong><?php echo esc_html($report_period !== '' ? $report_period : $report_entry['id']); ?></strong>
                                    <?php if ($generated_on !== '') : ?>
                                        <span class="description">— <?php echo esc_html($generated_on); ?></span>
                                    <?php endif; ?>
                                    <?php if ($csv_url) : ?>
                                        <a href="<?php echo esc_url($csv_url); ?>" class="button-link"><?php esc_html_e('CSV', 'sitepulse'); ?></a>
                                    <?php endif; ?>
                                    <?php if ($pdf_url) : ?>
                                        <a href="<?php echo esc_url($pdf_url); ?>" class="button-link"><?php esc_html_e('PDF', 'sitepulse'); ?></a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p><?php esc_html_e('Aucun rapport généré pour le moment.', 'sitepulse'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card">
            <h2><?php esc_html_e('Automatisation des rapports SLA', 'sitepulse'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sitepulse-sla-settings">
                <?php wp_nonce_field('sitepulse_save_sla_settings'); ?>
                <input type="hidden" name="action" value="sitepulse_save_sla_settings" />
                <p>
                    <label>
                        <input type="checkbox" name="sitepulse_sla_enabled" value="1" <?php checked(!empty($sla_settings['enabled'])); ?> />
                        <?php esc_html_e('Activer la génération automatique', 'sitepulse'); ?>
                    </label>
                </p>
                <p>
                    <label for="sitepulse-sla-frequency"><?php esc_html_e('Fréquence', 'sitepulse'); ?></label>
                    <select id="sitepulse-sla-frequency" name="sitepulse_sla_frequency">
                        <option value="weekly" <?php selected(isset($sla_settings['frequency']) ? $sla_settings['frequency'] : '', 'weekly'); ?>><?php esc_html_e('Hebdomadaire', 'sitepulse'); ?></option>
                        <option value="monthly" <?php selected(isset($sla_settings['frequency']) ? $sla_settings['frequency'] : 'monthly', 'monthly'); ?>><?php esc_html_e('Mensuelle', 'sitepulse'); ?></option>
                    </select>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="sitepulse_sla_email_enabled" value="1" <?php checked(!empty($sla_settings['email_enabled'])); ?> />
                        <?php esc_html_e('Envoyer un email avec le rapport (CSV & PDF)', 'sitepulse'); ?>
                    </label>
                </p>
                <p>
                    <label for="sitepulse-sla-recipients"><?php esc_html_e('Destinataires (séparés par une virgule ou un retour à la ligne)', 'sitepulse'); ?></label>
                    <textarea id="sitepulse-sla-recipients" name="sitepulse_sla_recipients" rows="3" class="large-text"><?php echo esc_textarea(implode("\n", (array) $sla_settings['recipients'])); ?></textarea>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="sitepulse_sla_webhook_enabled" value="1" <?php checked(!empty($sla_settings['webhook_enabled'])); ?> />
                        <?php esc_html_e('Notifier un webhook (JSON)', 'sitepulse'); ?>
                    </label>
                </p>
                <p>
                    <label for="sitepulse-sla-webhook-url"><?php esc_html_e('URL du webhook', 'sitepulse'); ?></label>
                    <input type="url" id="sitepulse-sla-webhook-url" name="sitepulse_sla_webhook_url" class="large-text" value="<?php echo esc_attr(isset($sla_settings['webhook_url']) ? $sla_settings['webhook_url'] : ''); ?>" />
                </p>
                <?php if (!empty($sla_settings['enabled']) && $sla_next_run_label !== '') : ?>
                    <p class="description">
                        <?php
                        printf(
                            esc_html__('Prochaine exécution planifiée : %1$s (%2$s).', 'sitepulse'),
                            esc_html($sla_next_run_label),
                            esc_html($sla_next_run_relative)
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <?php submit_button(__('Enregistrer les préférences', 'sitepulse')); ?>
            </form>
        </div>
        <section class="sitepulse-uptime-remote-metrics" aria-labelledby="sitepulse-uptime-remote-metrics-title">
            <div class="sitepulse-uptime-remote-metrics__header">
                <h2 id="sitepulse-uptime-remote-metrics-title"><?php esc_html_e('Orchestration des agents distants', 'sitepulse'); ?></h2>
                <p class="sitepulse-uptime-remote-metrics__meta">
                    <?php if ($remote_queue_updated_at > 0 && isset($updated_descriptor['formatted']) && null !== $updated_descriptor['formatted']) :
                        $updated_relative = isset($updated_descriptor['relative']) ? (string) $updated_descriptor['relative'] : '';
                        $updated_formatted = (string) $updated_descriptor['formatted'];
                        ?>
                        <?php if ($updated_relative !== '') : ?>
                            <?php
                            printf(
                                esc_html__('Dernière mise à jour : %1$s (%2$s).', 'sitepulse'),
                                esc_html($updated_formatted),
                                esc_html($updated_relative)
                            );
                            ?>
                        <?php else : ?>
                            <?php
                            printf(
                                esc_html__('Dernière mise à jour : %s.', 'sitepulse'),
                                esc_html($updated_formatted)
                            );
                            ?>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php esc_html_e('Aucune métrique historisée pour le moment.', 'sitepulse'); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="sitepulse-uptime-remote-metrics__status sitepulse-uptime-remote-metrics__status--<?php echo esc_attr($queue_status); ?>">
                <span class="dashicons dashicons-<?php echo esc_attr($queue_status_icon); ?>" aria-hidden="true"></span>
                <div class="sitepulse-uptime-remote-metrics__status-content">
                    <p class="sitepulse-uptime-remote-metrics__status-headline"><?php echo esc_html($queue_status_headline); ?></p>
                    <?php if (!empty($queue_status_notes)) : ?>
                        <ul class="sitepulse-uptime-remote-metrics__status-list">
                            <?php foreach ($queue_status_notes as $note) : ?>
                                <li><?php echo esc_html($note); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p class="sitepulse-uptime-remote-metrics__status-text"><?php esc_html_e('Les agents distants traitent les vérifications dans les délais configurés.', 'sitepulse'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sitepulse-uptime-remote-metrics__grid">
                <div class="sitepulse-uptime-remote-metrics__card">
                    <h3><?php esc_html_e('Charge de la file', 'sitepulse'); ?></h3>
                    <p class="sitepulse-uptime-remote-metrics__value"><?php echo esc_html(number_format_i18n($remote_queue_queue_length)); ?></p>
                    <p class="sitepulse-uptime-remote-metrics__meta">
                        <?php
                        $queue_limit_display = $remote_queue_limit_size > 0
                            ? number_format_i18n($remote_queue_limit_size)
                            : __('non défini', 'sitepulse');
                        printf(
                            esc_html__('Limite : %1$s jobs • Requêtes retenues : %2$s', 'sitepulse'),
                            esc_html($queue_limit_display),
                            esc_html(number_format_i18n($remote_queue_retained))
                        );
                        ?>
                    </p>
                    <p class="sitepulse-uptime-remote-metrics__meta">
                        <?php
                        printf(
                            esc_html__('Requêtes reçues : %s', 'sitepulse'),
                            esc_html(number_format_i18n($remote_queue_requested))
                        );
                        ?>
                    </p>
                    <p class="sitepulse-uptime-remote-metrics__meta">
                        <?php if ($remote_queue_prioritized_jobs > 0) : ?>
                            <?php
                            printf(
                                esc_html__('Priorité max : %1$s • Moyenne : %2$s • Jobs prioritaires : %3$s', 'sitepulse'),
                                esc_html(number_format_i18n($remote_queue_max_priority)),
                                esc_html(number_format_i18n($remote_queue_avg_priority)),
                                esc_html(number_format_i18n($remote_queue_prioritized_jobs))
                            );
                            ?>
                        <?php else : ?>
                            <?php esc_html_e('Aucun job prioritaire en file.', 'sitepulse'); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="sitepulse-uptime-remote-metrics__card">
                    <h3><?php esc_html_e('Retards et rejets', 'sitepulse'); ?></h3>
                    <p class="sitepulse-uptime-remote-metrics__value"><?php echo esc_html(number_format_i18n($remote_queue_delayed_jobs)); ?></p>
                    <p class="sitepulse-uptime-remote-metrics__meta">
                        <?php
                        printf(
                            esc_html__('Rejets cumulés : %s', 'sitepulse'),
                            esc_html(number_format_i18n($remote_queue_dropped_total))
                        );
                        ?>
                    </p>
                    <p class="sitepulse-uptime-remote-metrics__meta">
                        <?php
                        printf(
                            esc_html__('Capacité restante : %s', 'sitepulse'),
                            esc_html($remote_queue_limit_size > 0 ? number_format_i18n(max($remote_queue_limit_size - $remote_queue_queue_length, 0)) : __('n/a', 'sitepulse'))
                        );
                        ?>
                    </p>
                </div>
                <div class="sitepulse-uptime-remote-metrics__card">
                    <h3><?php esc_html_e('Attente maximale observée', 'sitepulse'); ?></h3>
                    <p class="sitepulse-uptime-remote-metrics__value"><?php echo esc_html(sitepulse_uptime_format_duration_i18n($remote_queue_max_wait)); ?></p>
                    <p class="sitepulse-uptime-remote-metrics__meta">
                        <?php
                        printf(
                            esc_html__('Attente moyenne : %s', 'sitepulse'),
                            esc_html(sitepulse_uptime_format_duration_i18n($remote_queue_avg_wait))
                        );
                        ?>
                    </p>
                    <p class="sitepulse-uptime-remote-metrics__meta">
                        <?php
                        printf(
                            esc_html__('Fenêtre de rétention : %s', 'sitepulse'),
                            esc_html(sitepulse_uptime_format_duration_i18n($remote_queue_limit_ttl))
                        );
                        ?>
                    </p>
                </div>
                <div class="sitepulse-uptime-remote-metrics__card">
                    <h3><?php esc_html_e('Prochain déclenchement', 'sitepulse'); ?></h3>
                    <p class="sitepulse-uptime-remote-metrics__value"><?php echo esc_html($next_schedule_label); ?></p>
                    <p class="sitepulse-uptime-remote-metrics__meta">
                        <?php
                        printf(
                            esc_html__('Job le plus ancien : %s', 'sitepulse'),
                            esc_html($oldest_created_label)
                        );
                        ?>
                    </p>
                </div>
            </div>
        </section>
        <?php if (!empty($available_months)) : ?>
        <section class="sitepulse-uptime-sla">
            <h2><?php esc_html_e('Rapports SLA mensuels', 'sitepulse'); ?></h2>
            <p class="sitepulse-uptime-sla__description">
                <?php
                if ($preview_month_label !== '') {
                    printf(
                        /* translators: %s: month label. */
                        esc_html__('Synthèse de la période %s et export CSV des agents actifs.', 'sitepulse'),
                        esc_html($preview_month_label)
                    );
                } else {
                    esc_html_e('Générez un rapport CSV consolidé par agent pour documenter vos engagements SLA.', 'sitepulse');
                }
                ?>
            </p>
            <?php if ($sla_error_code !== '') :
                $message = isset($sla_error_messages[$sla_error_code]) ? $sla_error_messages[$sla_error_code] : '';
                if ($message !== '') :
            ?>
            <div class="notice notice-error sitepulse-uptime-sla__notice"><p><?php echo esc_html($message); ?></p></div>
            <?php
                endif;
            endif;
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sitepulse-uptime-sla__form">
                <?php wp_nonce_field('sitepulse_export_sla'); ?>
                <input type="hidden" name="action" value="sitepulse_export_sla" />
                <label for="sitepulse-sla-month" class="screen-reader-text"><?php esc_html_e('Sélectionnez le mois à exporter', 'sitepulse'); ?></label>
                <select id="sitepulse-sla-month" name="sitepulse_sla_month" class="sitepulse-uptime-sla__select">
                    <?php foreach ($available_months as $month_key => $month_data) : ?>
                        <option value="<?php echo esc_attr($month_key); ?>" <?php selected($selected_month_key, $month_key); ?>>
                            <?php echo esc_html($month_data['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Exporter le rapport CSV', 'sitepulse'); ?>
                </button>
            </form>
            <div class="sitepulse-uptime-sla__insights" role="list">
                <div class="sitepulse-uptime-sla__card" role="listitem">
                    <span class="sitepulse-uptime-sla__card-label"><?php esc_html_e('Uptime global', 'sitepulse'); ?></span>
                    <span class="sitepulse-uptime-sla__card-value"><?php echo esc_html(number_format_i18n($preview_uptime, 3)); ?>%</span>
                    <?php if ($preview_month_label !== '') : ?>
                        <span class="sitepulse-uptime-sla__card-meta"><?php echo esc_html($preview_month_label); ?></span>
                    <?php endif; ?>
                </div>
                <div class="sitepulse-uptime-sla__card" role="listitem">
                    <span class="sitepulse-uptime-sla__card-label"><?php esc_html_e('Incidents détectés', 'sitepulse'); ?></span>
                    <span class="sitepulse-uptime-sla__card-value"><?php echo esc_html(number_format_i18n($preview_incidents)); ?></span>
                    <span class="sitepulse-uptime-sla__card-meta">
                        <?php
                        $maintenance_text = _n(
                            'Maintenance programmée : %s contrôle',
                            'Maintenance programmée : %s contrôles',
                            $preview_maintenance,
                            'sitepulse'
                        );
                        printf(
                            esc_html($maintenance_text),
                            esc_html(number_format_i18n($preview_maintenance))
                        );
                        ?>
                    </span>
                </div>
                <div class="sitepulse-uptime-sla__card" role="listitem">
                    <span class="sitepulse-uptime-sla__card-label"><?php esc_html_e('TTFB moyen', 'sitepulse'); ?></span>
                    <span class="sitepulse-uptime-sla__card-value">
                        <?php
                        if (null === $preview_ttfb_avg) {
                            echo '—';
                        } else {
                            printf(
                                esc_html(_x('%s ms', 'milliseconds unit', 'sitepulse')),
                                esc_html(number_format_i18n($preview_ttfb_avg, 1))
                            );
                        }
                        ?>
                    </span>
                    <span class="sitepulse-uptime-sla__card-meta">
                        <?php
                        $ttfb_text = _n('Basé sur %s mesure.', 'Basé sur %s mesures.', $preview_ttfb_count, 'sitepulse');
                        printf(
                            esc_html($ttfb_text),
                            esc_html(number_format_i18n($preview_ttfb_count))
                        );
                        ?>
                    </span>
                </div>
                <div class="sitepulse-uptime-sla__card" role="listitem">
                    <span class="sitepulse-uptime-sla__card-label"><?php esc_html_e('Latence moyenne', 'sitepulse'); ?></span>
                    <span class="sitepulse-uptime-sla__card-value">
                        <?php
                        if (null === $preview_latency_avg) {
                            echo '—';
                        } else {
                            printf(
                                esc_html(_x('%s ms', 'milliseconds unit', 'sitepulse')),
                                esc_html(number_format_i18n($preview_latency_avg, 1))
                            );
                        }
                        ?>
                    </span>
                    <span class="sitepulse-uptime-sla__card-meta">
                        <?php
                        $latency_text = _n('Mesure de latence : %s.', 'Mesures de latence : %s.', $preview_latency_count, 'sitepulse');
                        printf(
                            esc_html($latency_text),
                            esc_html(number_format_i18n($preview_latency_count))
                        );
                        ?>
                    </span>
                </div>
            </div>
        </section>
        <?php elseif ($sla_error_code !== '') :
            $message = isset($sla_error_messages[$sla_error_code]) ? $sla_error_messages[$sla_error_code] : '';
            if ($message !== '') :
        ?>
        <div class="notice notice-error sitepulse-uptime-sla__notice"><p><?php echo esc_html($message); ?></p></div>
        <?php
            endif;
        endif;
        ?>
        <h2><?php esc_html_e('Disponibilité par localisation', 'sitepulse'); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Agent', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Région', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Uptime (30 jours)', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('TTFB moyen (30 jours)', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Latence moyenne (30 jours)', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Violations (30 jours)', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Dernier statut', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Contrôle le', 'sitepulse'); ?></th>
                    <th><?php esc_html_e('Maintenance', 'sitepulse'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($agents as $agent_id => $agent_data) :
                    $agent_metrics_entry = isset($agent_metrics[$agent_id]) ? $agent_metrics[$agent_id] : [
                        'uptime'          => 100,
                        'effective_total' => 0,
                        'up'              => 0,
                        'down'            => 0,
                        'unknown'         => 0,
                        'maintenance'     => 0,
                    ];
                    $agent_is_active = sitepulse_uptime_agent_is_active($agent_id, $agent_data);
                    $uptime_value = number_format_i18n($agent_metrics_entry['uptime'], 2);
                    $ttfb_avg_value = isset($agent_metrics_entry['ttfb_avg']) ? $agent_metrics_entry['ttfb_avg'] : null;
                    $latency_avg_value = isset($agent_metrics_entry['latency_avg']) ? $agent_metrics_entry['latency_avg'] : null;
                    $ttfb_display = $format_latency_ms($ttfb_avg_value);
                    $latency_display = $format_latency_ms($latency_avg_value);
                    $ttfb_class = null !== $ttfb_avg_value
                        ? 'sitepulse-uptime-metric sitepulse-uptime-metric--ok'
                        : 'sitepulse-uptime-metric sitepulse-uptime-metric--neutral';
                    $latency_class = 'sitepulse-uptime-metric sitepulse-uptime-metric--neutral';

                    if (null !== $latency_avg_value) {
                        $latency_class = 'sitepulse-uptime-metric sitepulse-uptime-metric--ok';

                        if ($latency_threshold > 0) {
                            if ($latency_avg_value > $latency_threshold) {
                                $latency_class = 'sitepulse-uptime-metric sitepulse-uptime-metric--critical';
                            } elseif ($latency_avg_value > ($latency_threshold * 0.75)) {
                                $latency_class = 'sitepulse-uptime-metric sitepulse-uptime-metric--warning';
                            }
                        }
                    }

                    $violation_count = isset($agent_metrics_entry['violations']) ? (int) $agent_metrics_entry['violations'] : 0;
                    $violation_class = $violation_count > 0
                        ? 'sitepulse-uptime-metric sitepulse-uptime-metric--critical'
                        : 'sitepulse-uptime-metric sitepulse-uptime-metric--ok';
                    $violation_details = [];

                    if (isset($agent_metrics_entry['violation_types']) && is_array($agent_metrics_entry['violation_types'])) {
                        foreach ($agent_metrics_entry['violation_types'] as $type => $count) {
                            $type_key = sanitize_key($type);

                            if ($type_key === '') {
                                continue;
                            }

                            $label = isset($violation_type_labels[$type_key]) ? $violation_type_labels[$type_key] : ucfirst($type_key);
                            $violation_details[] = sprintf(
                                /* translators: 1: violation label, 2: count. */
                                __('%1$s : %2$s', 'sitepulse'),
                                $label,
                                number_format_i18n((int) $count)
                            );
                        }
                    }

                    $violation_title = !empty($violation_details) ? implode(', ', $violation_details) : '';
                    $last_entry = isset($last_checks[$agent_id]) ? $last_checks[$agent_id] : null;
                    $status_label = __('Aucun contrôle', 'sitepulse');
                    $status_class = 'status-unknown';
                    $last_check_time = __('Jamais', 'sitepulse');

                    if ($last_entry) {
                        $last_check_time = date_i18n($date_format . ' ' . $time_format, (int) $last_entry['timestamp']);
                        $status_value = isset($last_entry['status']) ? $last_entry['status'] : null;

                        if (true === $status_value) {
                            $status_label = __('Disponible', 'sitepulse');
                            $status_class = 'status-up';
                        } elseif (false === $status_value) {
                            $status_label = __('Incident', 'sitepulse');
                            $status_class = 'status-down';
                        } elseif ('maintenance' === $status_value) {
                            $status_label = __('Maintenance', 'sitepulse');
                            $status_class = 'status-maintenance';
                        } else {
                            $status_label = __('Inconnu', 'sitepulse');
                            $status_class = 'status-unknown';
                        }
                    }

                    if (!$agent_is_active) {
                        $status_label = __('Inactif', 'sitepulse');
                        $status_class = 'status-unknown';
                    }

                    $active_window = sitepulse_uptime_find_active_maintenance_window($agent_id, $current_timestamp);
                    $upcoming_window = null;

                    foreach ($maintenance_windows as $window) {
                        if ('all' !== $window['agent'] && $window['agent'] !== $agent_id) {
                            continue;
                        }

                        if (!empty($window['is_active'])) {
                            $active_window = $window;
                            continue;
                        }

                        if ($window['start'] > $current_timestamp) {
                            if (null === $upcoming_window || $window['start'] < $upcoming_window['start']) {
                                $upcoming_window = $window;
                            }
                        }
                    }

                    if ($active_window) {
                        $window_name = !empty($active_window['label'])
                            ? $active_window['label']
                            : __('Maintenance planifiée', 'sitepulse');
                        $maintenance_label = sprintf(
                            /* translators: 1: window name, 2: formatted end date. */
                            __('%1$s en cours jusqu’au %2$s. Aucune alerte envoyée.', 'sitepulse'),
                            $window_name,
                            date_i18n($date_format . ' ' . $time_format, (int) $active_window['end'])
                        );
                    } elseif ($upcoming_window) {
                        $window_name = !empty($upcoming_window['label'])
                            ? $upcoming_window['label']
                            : __('Maintenance planifiée', 'sitepulse');
                        $maintenance_label = sprintf(
                            /* translators: 1: window name, 2: formatted start date, 3: relative time. */
                            __('%1$s le %2$s (dans %3$s). Les alertes seront suspendues.', 'sitepulse'),
                            $window_name,
                            date_i18n($date_format . ' ' . $time_format, (int) $upcoming_window['start']),
                            human_time_diff($current_timestamp, (int) $upcoming_window['start'])
                        );
                    } else {
                        $maintenance_label = __('Aucune maintenance programmée', 'sitepulse');
                    }
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($agent_data['label']); ?></strong>
                        <?php if (!$agent_is_active) : ?>
                            <span class="description"><?php esc_html_e('Inactif', 'sitepulse'); ?></span>
                        <?php endif; ?><br />
                        <small><?php echo esc_html($agent_id); ?></small>
                    </td>
                    <td><?php echo esc_html(isset($agent_data['region']) ? strtoupper($agent_data['region']) : 'GLOBAL'); ?></td>
                    <td><?php echo esc_html($uptime_value); ?>%</td>
                    <td><span class="<?php echo esc_attr($ttfb_class); ?>"><?php echo esc_html($ttfb_display); ?></span></td>
                    <td><span class="<?php echo esc_attr($latency_class); ?>"><?php echo esc_html($latency_display); ?></span></td>
                    <td>
                        <span class="<?php echo esc_attr($violation_class); ?>"<?php echo $violation_title !== '' ? ' title="' . esc_attr($violation_title) . '"' : ''; ?>>
                            <?php echo esc_html(number_format_i18n($violation_count)); ?>
                        </span>
                    </td>
                    <td><span class="sitepulse-uptime-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                    <td><?php echo esc_html($last_check_time); ?></td>
                    <td><?php echo esc_html($maintenance_label); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($region_metrics)) : ?>
            <h2><?php esc_html_e('Disponibilité par région', 'sitepulse'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Région', 'sitepulse'); ?></th>
                        <th><?php esc_html_e('Agents suivis', 'sitepulse'); ?></th>
                        <th><?php esc_html_e('Uptime (30 jours)', 'sitepulse'); ?></th>
                        <th><?php esc_html_e('Incidents', 'sitepulse'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($region_metrics as $region => $metrics) :
                        $region_label = strtoupper($region);
                        $incident_count = isset($metrics['down']) ? (int) $metrics['down'] : 0;
                    ?>
                    <tr>
                        <td><?php echo esc_html($region_label); ?></td>
                        <td><?php echo esc_html(implode(', ', $metrics['agents'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($metrics['uptime'], 2)); ?>%</td>
                        <td><?php echo esc_html(number_format_i18n($incident_count)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($maintenance_windows)) : ?>
            <h2><?php esc_html_e('Fenêtres de maintenance programmées', 'sitepulse'); ?></h2>
            <ul class="sitepulse-maintenance-list">
                <?php foreach ($maintenance_windows as $window) :
                    $window_agent = 'all' === $window['agent'] ? __('Tous les agents', 'sitepulse') : $window['agent'];
                    $window_name = !empty($window['label']) ? $window['label'] : __('Maintenance planifiée', 'sitepulse');
                    $status_badge = !empty($window['is_active']) ? __('En cours', 'sitepulse') : __('À venir', 'sitepulse');
                    $badge_class = !empty($window['is_active']) ? 'is-active' : 'is-scheduled';
                    $recurrence_label = __('Hebdomadaire', 'sitepulse');

                    if (isset($window['recurrence'])) {
                        if ('daily' === $window['recurrence']) {
                            $recurrence_label = __('Quotidienne', 'sitepulse');
                        } elseif ('one_off' === $window['recurrence']) {
                            $recurrence_label = __('Ponctuelle', 'sitepulse');
                        }
                    }

                    $duration_text = human_time_diff((int) $window['start'], (int) $window['end']);
                    $starts_in = '';

                    if (empty($window['is_active']) && $window['start'] > $current_timestamp) {
                        $starts_in = sprintf(
                            /* translators: %s: human readable time difference. */
                            __('Débute dans %s.', 'sitepulse'),
                            human_time_diff($current_timestamp, (int) $window['start'])
                        );
                    }
                ?>
                <li class="sitepulse-maintenance-list__item">
                    <div class="sitepulse-maintenance-list__header">
                        <strong><?php echo esc_html($window_agent); ?></strong>
                        <span class="sitepulse-maintenance-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($status_badge); ?></span>
                    </div>
                    <div class="sitepulse-maintenance-list__body">
                        <p><em><?php echo esc_html($window_name); ?></em></p>
                        <p>
                            <?php echo esc_html(date_i18n($date_format . ' ' . $time_format, (int) $window['start'])); ?>
                            →
                            <?php echo esc_html(date_i18n($date_format . ' ' . $time_format, (int) $window['end'])); ?>
                        </p>
                        <p><?php printf(esc_html__('Durée : %s.', 'sitepulse'), esc_html($duration_text)); ?></p>
                        <p><?php printf(esc_html__('Récurrence : %s.', 'sitepulse'), esc_html($recurrence_label)); ?></p>
                        <?php if ('' !== $starts_in) : ?>
                            <p><?php echo esc_html($starts_in); ?></p>
                        <?php endif; ?>
                        <p><?php esc_html_e('Aucune alerte n’est envoyée pendant cette fenêtre.', 'sitepulse'); ?></p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="uptime-chart">
            <?php if (empty($uptime_log)) : ?>
                <p><?php esc_html_e("Aucune donnée de disponibilité. La première vérification aura lieu dans l'heure.", 'sitepulse'); ?></p>
            <?php else : ?>
                <?php foreach ($uptime_log as $index => $entry): ?>
                    <?php
                    $status = $entry['status'] ?? null;
                    $bar_class = 'unknown';
                    if (true === $status) {
                        $bar_class = 'up';
                    } elseif (false === $status) {
                        $bar_class = 'down';
                    } elseif ('maintenance' === $status) {
                        $bar_class = 'maintenance';
                    }
                    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                    $check_time = $timestamp > 0
                        ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                        : __('Horodatage inconnu', 'sitepulse');
                    $previous_entry = $index > 0 ? $uptime_log[$index - 1] : null;
                    $next_entry = ($index + 1) < $total_checks ? $uptime_log[$index + 1] : null;

                    $status_label = '';
                    $duration_label = '';

                    if (true === $status) {
                        $bar_title = sprintf(__('Site OK lors du contrôle du %s.', 'sitepulse'), $check_time);
                        $status_label = __('Statut : site disponible.', 'sitepulse');

                        if (!empty($previous_entry) && isset($previous_entry['status']) && is_bool($previous_entry['status']) && false === $previous_entry['status']) {
                            $incident_start = isset($previous_entry['incident_start']) ? (int) $previous_entry['incident_start'] : (isset($previous_entry['timestamp']) ? (int) $previous_entry['timestamp'] : 0);
                            if ($incident_start > 0 && $timestamp >= $incident_start) {
                                $incident_start_formatted = date_i18n($date_format . ' ' . $time_format, $incident_start);
                                $bar_title .= ' ' . sprintf(__('Retour à la normale après un incident débuté le %1$s (durée : %2$s).', 'sitepulse'), $incident_start_formatted, human_time_diff($incident_start, $timestamp));
                                $duration_label = sprintf(__('Durée de l’incident résolu : %s.', 'sitepulse'), human_time_diff($incident_start, $timestamp));
                            }
                        }

                        if ('' === $duration_label) {
                            $duration_label = __('Durée : disponibilité confirmée lors de ce contrôle.', 'sitepulse');
                        }
                    } elseif (false === $status) {
                        $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : $timestamp;
                        $incident_start_formatted = $incident_start > 0
                            ? date_i18n($date_format . ' ' . $time_format, $incident_start)
                            : __('horodatage inconnu', 'sitepulse');
                        $bar_title = sprintf(__('Site KO lors du contrôle du %1$s. Incident commencé le %2$s.', 'sitepulse'), $check_time, $incident_start_formatted);
                        $status_label = __('Statut : site indisponible.', 'sitepulse');

                        if (array_key_exists('error', $entry)) {
                            $error_detail = is_scalar($entry['error']) ? (string) $entry['error'] : wp_json_encode($entry['error']);

                            if ('' !== $error_detail && false !== $error_detail) {
                                $bar_title .= ' ' . sprintf(__('Détails : %s.', 'sitepulse'), $error_detail);
                            }
                        }

                        $is_transition = empty($previous_entry) || (isset($previous_entry['status']) && true === $previous_entry['status']);

                        if ($index === $total_checks - 1 && !empty($current_incident_duration)) {
                            $bar_title .= ' ' . sprintf(__('Incident en cours depuis %s.', 'sitepulse'), $current_incident_duration);
                            $duration_label = sprintf(__('Durée de l’incident en cours : %s.', 'sitepulse'), $current_incident_duration);
                        } else {
                            $duration_reference = null;

                            if (!empty($next_entry) && isset($next_entry['status']) && true === $next_entry['status']) {
                                $duration_reference = isset($next_entry['timestamp']) ? (int) $next_entry['timestamp'] : null;
                            } elseif ($timestamp > 0) {
                                $duration_reference = $timestamp;
                            }

                            if ($duration_reference && $incident_start && $duration_reference >= $incident_start) {
                                $duration_text = human_time_diff($incident_start, $duration_reference);
                                $label = $is_transition ? __('Durée estimée : %s.', 'sitepulse') : __('Durée cumulée : %s.', 'sitepulse');
                                $bar_title .= ' ' . sprintf($label, $duration_text);
                                $duration_label = sprintf(__('Durée de l’incident : %s.', 'sitepulse'), $duration_text);
                            }
                        }

                        if ('' === $duration_label) {
                            $duration_label = __('Durée : incident en cours, durée non déterminée.', 'sitepulse');
                        }
                    } elseif ('maintenance' === $status) {
                        $bar_title = sprintf(__('Fenêtre de maintenance lors du contrôle du %s.', 'sitepulse'), $check_time);
                        $status_label = __('Statut : maintenance planifiée.', 'sitepulse');
                        $duration_label = __('Durée : ce contrôle est ignoré pour le calcul de disponibilité et aucune alerte n’est envoyée.', 'sitepulse');
                    } else {
                        $error_text = isset($entry['error']) ? $entry['error'] : __('Erreur réseau inconnue.', 'sitepulse');
                        $bar_title = sprintf(__('Statut indéterminé lors du contrôle du %1$s : %2$s', 'sitepulse'), $check_time, $error_text);
                        $status_label = __('Statut : indéterminé.', 'sitepulse');
                        $duration_label = __('Durée : impossible à déterminer pour ce contrôle.', 'sitepulse');
                    }
                    $screen_reader_text = implode(' ', array_filter([
                        sprintf(__('Contrôle du %s.', 'sitepulse'), $check_time),
                        $status_label,
                        $duration_label,
                    ]));
                    ?>
                    <div class="uptime-bar <?php echo esc_attr($bar_class); ?>" title="<?php echo esc_attr($bar_title); ?>">
                        <span class="screen-reader-text"><?php echo esc_html($screen_reader_text); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="uptime-timeline__labels"><span><?php echo sprintf(esc_html__('Il y a %d heures', 'sitepulse'), absint($total_checks)); ?></span><span><?php esc_html_e('Maintenant', 'sitepulse'); ?></span></div>
        <?php if (!empty($current_incident_duration) && null !== $current_incident_start): ?>
            <div class="notice notice-error uptime-notice--error">
                <p>
                    <strong><?php esc_html_e('Incident en cours', 'sitepulse'); ?> :</strong>
                    <?php
                    $current_incident_start_formatted = date_i18n($date_format . ' ' . $time_format, $current_incident_start);
                    echo esc_html(
                        sprintf(
                            __('Votre site est signalé comme indisponible depuis le %1$s (%2$s).', 'sitepulse'),
                            $current_incident_start_formatted,
                            $current_incident_duration
                        )
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>
        <?php if (!empty($trend_data)): ?>
            <h2><?php esc_html_e('Tendance de disponibilité (30 jours)', 'sitepulse'); ?></h2>
            <div class="uptime-trend" role="img" aria-label="<?php echo esc_attr(sprintf(__('Disponibilité quotidienne sur %d jours.', 'sitepulse'), count($trend_data))); ?>">
                <?php foreach ($trend_data as $bar): ?>
                    <span class="uptime-trend__bar <?php echo esc_attr($bar['class']); ?>" style="height: <?php echo esc_attr($bar['height']); ?>%;" title="<?php echo esc_attr($bar['label']); ?>"></span>
                <?php endforeach; ?>
            </div>
            <p class="uptime-trend__legend">
                <span class="uptime-trend__legend-item uptime-trend__legend-item--high"><?php esc_html_e('≥ 99% de disponibilité', 'sitepulse'); ?></span>
                <span class="uptime-trend__legend-item uptime-trend__legend-item--medium"><?php esc_html_e('95 – 98% de disponibilité', 'sitepulse'); ?></span>
                <span class="uptime-trend__legend-item uptime-trend__legend-item--low"><?php esc_html_e('< 95% de disponibilité', 'sitepulse'); ?></span>
            </p>
        <?php endif; ?>
        <div class="notice notice-info uptime-notice--info"><p><strong><?php esc_html_e('Comment ça marche :', 'sitepulse'); ?></strong> <?php echo esc_html__('Une barre verte indique que votre site était en ligne. Une barre rouge indique un possible incident où votre site était inaccessible.', 'sitepulse'); ?></p></div>
    </div>
    <?php
}
