<?php
/**
 * SitePulse dashboard module fragment.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the HTML for the main SitePulse dashboard page.
 *
 * This page provides a visual overview of the site's key metrics,
 * acting as a central hub for site health information.
 *
 * Note: The menu registration for this page is handled in includes/admin-menu.php.
 */
function sitepulse_custom_dashboards_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    if (!wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        sitepulse_custom_dashboard_enqueue_assets('toplevel_page_sitepulse-dashboard');
    }

    if (wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        wp_enqueue_script('sitepulse-chartjs');
        wp_enqueue_script('sitepulse-dashboard-charts');
    }

    $selected_range = sitepulse_custom_dashboard_get_stored_range();
    $metrics_payload = sitepulse_custom_dashboard_prepare_metrics_payload($selected_range);
    $metrics_view = isset($metrics_payload['view']) && is_array($metrics_payload['view'])
        ? $metrics_payload['view']
        : sitepulse_custom_dashboard_format_metrics_view($metrics_payload);
    $range_options = isset($metrics_payload['available_ranges']) && is_array($metrics_payload['available_ranges'])
        ? array_values($metrics_payload['available_ranges'])
        : array_values(sitepulse_custom_dashboard_get_metric_ranges());

    if (wp_script_is('sitepulse-dashboard-metrics', 'registered')) {
        wp_localize_script('sitepulse-dashboard-metrics', 'SitePulseMetricsData', [
            'restUrl' => esc_url_raw(rest_url('sitepulse/v1/metrics')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'view'    => $metrics_view,
            'ranges'  => $range_options,
            'strings' => [
                'loading'      => __('Refreshing metrics…', 'sitepulse'),
                'error'        => __('Unable to refresh metrics. Please try again.', 'sitepulse'),
                'announcement' => __('Dashboard metrics updated for %s.', 'sitepulse'),
            ],
        ]);
        wp_enqueue_script('sitepulse-dashboard-metrics');
    }

    $metrics_cards = isset($metrics_view['cards']) && is_array($metrics_view['cards']) ? $metrics_view['cards'] : [];
    $health_view = isset($metrics_view['health']) && is_array($metrics_view['health']) ? $metrics_view['health'] : [];
    $playbooks_view = isset($metrics_view['playbooks']) && is_array($metrics_view['playbooks']) ? $metrics_view['playbooks'] : [];
    $sla_view = isset($metrics_view['sla']) && is_array($metrics_view['sla']) ? $metrics_view['sla'] : [];
    $banner_view = isset($metrics_view['banner']) && is_array($metrics_view['banner']) ? $metrics_view['banner'] : [];
    $banner_tone = isset($banner_view['tone']) ? sanitize_html_class($banner_view['tone']) : 'ok';
    $banner_icon = isset($banner_view['icon']) ? $banner_view['icon'] : '✅';
    $banner_message = isset($banner_view['message']) ? $banner_view['message'] : '';
    $banner_sr = isset($banner_view['sr']) ? $banner_view['sr'] : '';
    $banner_cta = isset($banner_view['cta']) && is_array($banner_view['cta']) ? $banner_view['cta'] : [];
    $generated_text = isset($metrics_view['generated_text']) ? $metrics_view['generated_text'] : '';
    $range_label = isset($metrics_view['range_label']) ? $metrics_view['range_label'] : '';
    $current_range = isset($metrics_payload['range']) ? $metrics_payload['range'] : sitepulse_custom_dashboard_get_default_range();

    $default_palette = [
        'green'    => '#0b6d2a',
        'amber'    => '#8a6100',
        'red'      => '#a0141e',
        'deep_red' => '#7f1018',
        'blue'     => '#2196F3',
        'grey'     => '#E0E0E0',
        'purple'   => '#9C27B0',
    ];

    $default_status_labels = sitepulse_custom_dashboard_get_default_status_labels();

    $context = sitepulse_get_dashboard_preview_context();

    $palette = $default_palette;
    $status_labels = $default_status_labels;
    $get_status_meta = static function ($status) use (&$status_labels, $default_status_labels) {
        if (isset($status_labels[$status])) {
            return $status_labels[$status];
        }

        if (isset($status_labels['status-warn'])) {
            return $status_labels['status-warn'];
        }

        return $default_status_labels['status-warn'];
    };
    $charts_payload = [];
    $speed_card = null;
    $speed_chart = null;
    $uptime_card = null;
    $uptime_chart = null;
    $database_card = null;
    $database_chart = null;
    $logs_card = null;
    $log_chart = null;
    $resource_card = null;
    $resource_chart = null;
    $plugins_card = null;
    $plugins_chart = null;
    $speed_warning_threshold = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
    $speed_critical_threshold = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;
    $is_speed_enabled = false;
    $is_uptime_enabled = false;
    $is_database_enabled = false;
    $is_logs_enabled = false;
    $is_resource_enabled = false;
    $is_plugins_enabled = false;
    $active_modules = [];

    if (is_array($context) && !empty($context)) {
        if (isset($context['palette']) && is_array($context['palette'])) {
            $palette = array_merge($default_palette, $context['palette']);
        }

        if (isset($context['status_labels']) && is_array($context['status_labels'])) {
            $status_labels = array_merge($default_status_labels, $context['status_labels']);
        }

        $active_modules = isset($context['active_modules']) && is_array($context['active_modules']) ? $context['active_modules'] : [];
        $modules = isset($context['modules']) && is_array($context['modules']) ? $context['modules'] : [];

        $speed_data = isset($modules['speed']) && is_array($modules['speed']) ? $modules['speed'] : [];
        $uptime_data = isset($modules['uptime']) && is_array($modules['uptime']) ? $modules['uptime'] : [];
        $database_data = isset($modules['database']) && is_array($modules['database']) ? $modules['database'] : [];
        $logs_data = isset($modules['logs']) && is_array($modules['logs']) ? $modules['logs'] : [];
        $resource_data = isset($modules['resource']) && is_array($modules['resource']) ? $modules['resource'] : [];
        $plugins_data = isset($modules['plugins']) && is_array($modules['plugins']) ? $modules['plugins'] : [];

        $is_speed_enabled = !empty($speed_data['enabled']);
        $is_uptime_enabled = !empty($uptime_data['enabled']);
        $is_database_enabled = !empty($database_data['enabled']);
        $is_logs_enabled = !empty($logs_data['enabled']);
        $is_resource_enabled = !empty($resource_data['enabled']);
        $is_plugins_enabled = !empty($plugins_data['enabled']);

        $speed_card = isset($speed_data['card']) && is_array($speed_data['card']) ? $speed_data['card'] : null;
        $speed_chart = isset($speed_data['chart']) && is_array($speed_data['chart']) ? $speed_data['chart'] : null;
        $speed_thresholds = isset($speed_data['thresholds']) && is_array($speed_data['thresholds']) ? $speed_data['thresholds'] : [];

        if (isset($speed_thresholds['warning'])) {
            $speed_warning_threshold = (int) $speed_thresholds['warning'];
        }

        if (isset($speed_thresholds['critical'])) {
            $speed_critical_threshold = (int) $speed_thresholds['critical'];
        }

        $uptime_card = isset($uptime_data['card']) && is_array($uptime_data['card']) ? $uptime_data['card'] : null;
        $uptime_chart = isset($uptime_data['chart']) && is_array($uptime_data['chart']) ? $uptime_data['chart'] : null;

        $database_card = isset($database_data['card']) && is_array($database_data['card']) ? $database_data['card'] : null;
        $database_chart = isset($database_data['chart']) && is_array($database_data['chart']) ? $database_data['chart'] : null;

        $logs_card = isset($logs_data['card']) && is_array($logs_data['card']) ? $logs_data['card'] : null;
        $log_chart = isset($logs_data['chart']) && is_array($logs_data['chart']) ? $logs_data['chart'] : null;

        $resource_card = isset($resource_data['card']) && is_array($resource_data['card']) ? $resource_data['card'] : null;
        $resource_chart = isset($resource_data['chart']) && is_array($resource_data['chart']) ? $resource_data['chart'] : null;

        $plugins_card = isset($plugins_data['card']) && is_array($plugins_data['card']) ? $plugins_data['card'] : null;
        $plugins_chart = isset($plugins_data['chart']) && is_array($plugins_data['chart']) ? $plugins_data['chart'] : null;

        $charts_payload = isset($context['charts_payload']) && is_array($context['charts_payload'])
            ? $context['charts_payload']
            : [];
    } else {
        $active_modules = array_map('strval', (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []));
        global $wpdb;
        $is_speed_enabled = in_array('speed_analyzer', $active_modules, true);
        $is_uptime_enabled = in_array('uptime_tracker', $active_modules, true);
        $is_database_enabled = in_array('database_optimizer', $active_modules, true);
        $is_logs_enabled = in_array('log_analyzer', $active_modules, true);
        $is_resource_enabled = in_array('resource_monitor', $active_modules, true);
        $is_plugins_enabled = in_array('plugin_impact_scanner', $active_modules, true);

    $palette = $default_palette;
    $status_labels = $default_status_labels;

    $charts_payload = [];
    $speed_card = null;

    if ($is_speed_enabled) {
        $results = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        $raw_processing_time = null;

        if (is_array($results)) {
            if (isset($results['server_processing_ms']) && is_numeric($results['server_processing_ms'])) {
                $raw_processing_time = (float) $results['server_processing_ms'];
            } elseif (isset($results['ttfb']) && is_numeric($results['ttfb'])) {
                $raw_processing_time = (float) $results['ttfb'];
            } elseif (isset($results['data']['server_processing_ms']) && is_numeric($results['data']['server_processing_ms'])) {
                $raw_processing_time = (float) $results['data']['server_processing_ms'];
            } elseif (isset($results['data']['ttfb']) && is_numeric($results['data']['ttfb'])) {
                $raw_processing_time = (float) $results['data']['ttfb'];
            }
        }

        $history_entries = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

        if (!is_array($history_entries)) {
            $history_entries = [];
        }

        $history_entries = array_values(array_filter(
            $history_entries,
            static function ($entry) {
                if (!is_array($entry)) {
                    return false;
                }

                if (!isset($entry['server_processing_ms']) || !is_numeric($entry['server_processing_ms'])) {
                    return false;
                }

                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

                return $timestamp > 0;
            }
        ));

        if (!empty($history_entries)) {
            usort(
                $history_entries,
                static function ($a, $b) {
                    $a_timestamp = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
                    $b_timestamp = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;

                    if ($a_timestamp === $b_timestamp) {
                        return 0;
                    }

                    return ($a_timestamp < $b_timestamp) ? -1 : 1;
                }
            );
        }

        $history_point_limit = apply_filters('sitepulse_speed_history_chart_points', 30);

        if (!is_scalar($history_point_limit)) {
            $history_point_limit = 30;
        }

        $history_point_limit = max(1, (int) $history_point_limit);

        if (count($history_entries) > $history_point_limit) {
            $history_entries = array_slice($history_entries, -$history_point_limit);
        }

        if (empty($history_entries) && $raw_processing_time !== null) {
            $fallback_timestamp = null;

            if (isset($results['timestamp']) && is_numeric($results['timestamp'])) {
                $fallback_timestamp = (int) $results['timestamp'];
            } elseif (isset($results['data']['timestamp']) && is_numeric($results['data']['timestamp'])) {
                $fallback_timestamp = (int) $results['data']['timestamp'];
            }

            if ($fallback_timestamp === null || $fallback_timestamp <= 0) {
                $fallback_timestamp = current_time('timestamp');
            }

            $history_entries[] = [
                'timestamp'            => $fallback_timestamp,
                'server_processing_ms' => (float) $raw_processing_time,
            ];
        }

        $latest_entry = !empty($history_entries)
            ? $history_entries[count($history_entries) - 1]
            : null;

        $processing_time = $raw_processing_time;

        if (is_array($latest_entry) && isset($latest_entry['server_processing_ms']) && is_numeric($latest_entry['server_processing_ms'])) {
            $processing_time = (float) $latest_entry['server_processing_ms'];
        }

        $default_speed_thresholds = [
            'warning'  => defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200,
            'critical' => defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500,
        ];

        $speed_warning_threshold = $default_speed_thresholds['warning'];
        $speed_critical_threshold = $default_speed_thresholds['critical'];

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

            $stored_warning = get_option($warning_option_key, $default_speed_thresholds['warning']);
            $stored_critical = get_option($critical_option_key, $default_speed_thresholds['critical']);

            if (is_numeric($stored_warning)) {
                $speed_warning_threshold = (int) $stored_warning;
            }

            if (is_numeric($stored_critical)) {
                $speed_critical_threshold = (int) $stored_critical;
            }
        }

        if ($speed_warning_threshold < 1) {
            $speed_warning_threshold = $default_speed_thresholds['warning'];
        }

        if ($speed_critical_threshold <= $speed_warning_threshold) {
            $speed_critical_threshold = max($speed_warning_threshold + 1, $default_speed_thresholds['critical']);
        }

        $processing_status = 'status-ok';

        if ($processing_time === null) {
            $processing_status = 'status-warn';
        } elseif ($processing_time >= $speed_critical_threshold) {
            $processing_status = 'status-bad';
        } elseif ($processing_time >= $speed_warning_threshold) {
            $processing_status = 'status-warn';
        }

        $processing_display = $processing_time !== null
            ? round($processing_time) . ' ' . esc_html__('ms', 'sitepulse')
            : esc_html__('N/A', 'sitepulse');

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $speed_labels = [];
        $speed_values = [];

        foreach ($history_entries as $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $value = isset($entry['server_processing_ms']) ? (float) $entry['server_processing_ms'] : null;

            if ($value === null) {
                continue;
            }

            $label = $timestamp > 0
                ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                : __('Unknown', 'sitepulse');

            $speed_labels[] = $label;
            $speed_values[] = max(0.0, (float) $value);
        }

        $speed_values = array_map(
            static function ($value) {
                return round((float) $value, 2);
            },
            $speed_values
        );

        $speed_reference = max(1.0, (float) $speed_warning_threshold);
        $speed_chart = [
            'type'     => 'line',
            'labels'   => $speed_labels,
            'datasets' => [],
            'empty'    => empty($speed_labels),
            'status'   => $processing_status,
            'value'    => $processing_time !== null ? round($processing_time, 2) : null,
            'unit'     => __('ms', 'sitepulse'),
            'reference'=> (float) $speed_reference,
        ];

        if (!empty($speed_labels)) {
            $speed_color_map = [
                'status-ok'   => $palette['green'],
                'status-warn' => $palette['amber'],
                'status-bad'  => $palette['red'],
            ];
            $speed_primary_color = isset($speed_color_map[$processing_status]) ? $speed_color_map[$processing_status] : $palette['blue'];

            $speed_chart['datasets'][] = [
                'label'               => __('Processing time', 'sitepulse'),
                'data'                => $speed_values,
                'borderColor'         => $speed_primary_color,
                'pointBackgroundColor'=> $speed_primary_color,
                'pointRadius'         => 3,
                'tension'             => 0.3,
                'fill'                => false,
            ];

            $budget_values = array_fill(0, count($speed_labels), (float) $speed_reference);

            $speed_chart['datasets'][] = [
                'label'       => __('Performance budget', 'sitepulse'),
                'data'        => $budget_values,
                'borderColor' => $palette['amber'],
                'borderWidth' => 2,
                'borderDash'  => [6, 6],
                'pointRadius' => 0,
                'fill'        => false,
            ];
        }

        $charts_payload['speed'] = $speed_chart;
        $speed_card = [
            'status'  => $processing_status,
            'display' => $processing_display,
        ];
    }

    $uptime_card = null;

    if ($is_uptime_enabled) {
        $raw_uptime_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $uptime_log = function_exists('sitepulse_normalize_uptime_log')
            ? sitepulse_normalize_uptime_log($raw_uptime_log)
            : (array) $raw_uptime_log;
        $boolean_checks = array_values(array_filter($uptime_log, function ($entry) {
            return is_array($entry) && array_key_exists('status', $entry) && is_bool($entry['status']);
        }));
        $evaluated_checks = count($boolean_checks);
        $up_checks = count(array_filter($boolean_checks, function ($entry) {
            return isset($entry['status']) && true === $entry['status'];
        }));
        $uptime_percentage = $evaluated_checks > 0 ? ($up_checks / $evaluated_checks) * 100 : 100;
        $default_uptime_warning = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0;
        $uptime_warning_threshold = $default_uptime_warning;

        if (function_exists('sitepulse_get_uptime_warning_percentage')) {
            $uptime_warning_threshold = (float) sitepulse_get_uptime_warning_percentage();
        } else {
            $uptime_warning_key = defined('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT') ? SITEPULSE_OPTION_UPTIME_WARNING_PERCENT : 'sitepulse_uptime_warning_percent';
            $stored_threshold = get_option($uptime_warning_key, $default_uptime_warning);

            if (is_scalar($stored_threshold)) {
                $uptime_warning_threshold = (float) $stored_threshold;
            }
        }

        if ($uptime_warning_threshold < 0) {
            $uptime_warning_threshold = 0.0;
        } elseif ($uptime_warning_threshold > 100) {
            $uptime_warning_threshold = 100.0;
        }

        if ($uptime_percentage < $uptime_warning_threshold) {
            $uptime_status = 'status-bad';
        } elseif ($uptime_percentage < 100) {
            $uptime_status = 'status-warn';
        } else {
            $uptime_status = 'status-ok';
        }
        $uptime_entries = array_slice($uptime_log, -30);

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $uptime_labels = [];
        $uptime_values = [];
        $uptime_colors = [];

        foreach ($uptime_entries as $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $label = $timestamp > 0
                ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                : __('Unknown', 'sitepulse');
            $status = is_array($entry) && array_key_exists('status', $entry) ? $entry['status'] : (!empty($entry));

            $uptime_labels[] = $label;
            if ($status === false) {
                $uptime_values[] = 0;
                $uptime_colors[] = $palette['red'];
            } elseif ($status === true) {
                $uptime_values[] = 100;
                $uptime_colors[] = $palette['green'];
            } else {
                $uptime_values[] = 50;
                $uptime_colors[] = $palette['grey'];
            }
        }

        $uptime_chart = [
            'type'     => 'bar',
            'labels'   => $uptime_labels,
            'datasets' => [
                [
                    'data'            => $uptime_values,
                    'backgroundColor' => $uptime_colors,
                    'borderWidth'     => 0,
                    'borderRadius'    => 6,
                ],
            ],
            'empty'    => empty($uptime_labels),
            'status'   => $uptime_status,
            'unit'     => __('%', 'sitepulse'),
        ];

        $charts_payload['uptime'] = $uptime_chart;
        $uptime_card = [
            'status'      => $uptime_status,
            'percentage'  => $uptime_percentage,
        ];
    }

    $database_card = null;

    if ($is_database_enabled) {
        $revisions = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
        $default_revision_limit = defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? (int) SITEPULSE_DEFAULT_REVISION_LIMIT : 100;
        $revision_limit = $default_revision_limit;

        if (function_exists('sitepulse_get_revision_limit')) {
            $revision_limit = (int) sitepulse_get_revision_limit();
        } else {
            $revision_option_key = defined('SITEPULSE_OPTION_REVISION_LIMIT') ? SITEPULSE_OPTION_REVISION_LIMIT : 'sitepulse_revision_limit';
            $stored_limit = get_option($revision_option_key, $default_revision_limit);

            if (is_scalar($stored_limit)) {
                $revision_limit = (int) $stored_limit;
            }
        }

        if ($revision_limit < 1) {
            $revision_limit = $default_revision_limit;
        }

        $revision_warn_threshold = (int) floor($revision_limit * 0.5);
        if ($revision_warn_threshold < 1) {
            $revision_warn_threshold = 1;
        }

        if ($revision_warn_threshold >= $revision_limit) {
            $revision_warn_threshold = max(1, $revision_limit - 1);
        }

        if ($revisions > $revision_limit) {
            $db_status = 'status-bad';
        } elseif ($revisions > $revision_warn_threshold) {
            $db_status = 'status-warn';
        } else {
            $db_status = 'status-ok';
        }

        $database_chart = [
            'type'     => 'doughnut',
            'labels'   => [],
            'datasets' => [],
            'empty'    => false,
            'status'   => $db_status,
            'value'    => $revisions,
            'limit'    => $revision_limit,
        ];

        if ($revisions <= $revision_limit) {
            $database_chart['labels'] = [
                __('Stored revisions', 'sitepulse'),
                __('Remaining before cleanup', 'sitepulse'),
            ];
            $database_chart['datasets'][] = [
                'data' => [
                    $revisions,
                    max(0, $revision_limit - $revisions),
                ],
                'backgroundColor' => [
                    $palette['blue'],
                    $palette['grey'],
                ],
                'borderWidth' => 0,
            ];
        } else {
            $database_chart['labels'] = [
                __('Recommended maximum', 'sitepulse'),
                __('Excess revisions', 'sitepulse'),
            ];
            $database_chart['datasets'][] = [
                'data' => [
                    $revision_limit,
                    $revisions - $revision_limit,
                ],
                'backgroundColor' => [
                    $palette['amber'],
                    $palette['red'],
                ],
                'borderWidth' => 0,
            ];
        }

        $charts_payload['database'] = $database_chart;
        $database_card = [
            'status'   => $db_status,
            'revisions'=> $revisions,
            'limit'    => $revision_limit,
        ];
    }

    $logs_card = null;

    if ($is_logs_enabled) {
        $log_snapshot = sitepulse_custom_dashboard_analyze_debug_log();
        $log_chart    = isset($log_snapshot['chart']) ? $log_snapshot['chart'] : [];

        if (!empty($log_chart['datasets']) && isset($log_chart['datasets'][0]) && is_array($log_chart['datasets'][0])) {
            $log_chart['datasets'][0]['backgroundColor'] = [
                $palette['red'],
                $palette['amber'],
                $palette['blue'],
                $palette['purple'],
            ];
        }

        $charts_payload['logs'] = $log_chart;
        $logs_card = isset($log_snapshot['card']) ? $log_snapshot['card'] : null;
    }

    $resource_card = null;

    if ($is_resource_enabled && function_exists('sitepulse_resource_monitor_get_snapshot')) {
        $snapshot = sitepulse_resource_monitor_get_snapshot();
        $load_display = '';
        $load_values = [null, null, null];

        if (is_array($snapshot)) {
            $raw_load_values = [];

            if (isset($snapshot['load_raw']) && is_array($snapshot['load_raw'])) {
                $raw_load_values = $snapshot['load_raw'];
            } elseif (isset($snapshot['load']) && is_array($snapshot['load'])) {
                $raw_load_values = $snapshot['load'];
            }

            foreach (array_slice(array_values((array) $raw_load_values), 0, 3) as $index => $value) {
                if (is_numeric($value)) {
                    $load_values[$index] = (float) $value;
                }
            }

            if (function_exists('sitepulse_resource_monitor_format_load_display')) {
                $load_display = sitepulse_resource_monitor_format_load_display(isset($snapshot['load']) ? $snapshot['load'] : $load_values);
            } else {
                $load_display = implode(' / ', array_map(static function ($value) {
                    if ($value === null) {
                        return __('N/A', 'sitepulse');
                    }

                    return number_format_i18n((float) $value, 2);
                }, $load_values));
            }
        }

        $memory_usage = isset($snapshot['memory_usage']) ? (string) $snapshot['memory_usage'] : '';
        $memory_limit = isset($snapshot['memory_limit']) && $snapshot['memory_limit'] !== false
            ? (string) $snapshot['memory_limit']
            : '';
        $memory_usage_bytes = isset($snapshot['memory_usage_bytes']) ? (float) $snapshot['memory_usage_bytes'] : 0.0;
        $memory_limit_bytes = isset($snapshot['memory_limit_bytes']) ? (float) $snapshot['memory_limit_bytes'] : 0.0;

        $memory_percent = null;

        if (function_exists('sitepulse_resource_monitor_calculate_percentage')) {
            $memory_percent = sitepulse_resource_monitor_calculate_percentage(
                $snapshot['memory_usage_bytes'] ?? null,
                $snapshot['memory_limit_bytes'] ?? null
            );
        } elseif ($memory_limit_bytes > 0) {
            $memory_percent = min(100.0, max(0.0, ($memory_usage_bytes / $memory_limit_bytes) * 100));
        }

        $disk_free = isset($snapshot['disk_free']) ? (string) $snapshot['disk_free'] : '';
        $disk_total = isset($snapshot['disk_total']) ? (string) $snapshot['disk_total'] : '';
        $disk_free_bytes = isset($snapshot['disk_free_bytes']) ? (float) $snapshot['disk_free_bytes'] : 0.0;
        $disk_total_bytes = isset($snapshot['disk_total_bytes']) ? (float) $snapshot['disk_total_bytes'] : 0.0;

        $disk_free_percent = null;

        if (function_exists('sitepulse_resource_monitor_calculate_percentage')) {
            $disk_free_percent = sitepulse_resource_monitor_calculate_percentage(
                $snapshot['disk_free_bytes'] ?? null,
                $snapshot['disk_total_bytes'] ?? null
            );
        } elseif ($disk_total_bytes > 0) {
            $disk_free_percent = min(100.0, max(0.0, ($disk_free_bytes / $disk_total_bytes) * 100));
        }

        $http_stats = null;

        if (isset($resource_data) && is_array($resource_data) && isset($resource_data['http']) && is_array($resource_data['http'])) {
            $http_stats = $resource_data['http'];
        } elseif (function_exists('sitepulse_http_monitor_get_stats')) {
            $http_stats = sitepulse_http_monitor_get_stats([
                'since' => (int) current_time('timestamp', true) - DAY_IN_SECONDS,
                'limit' => 10,
            ]);
        }

        $http_summary_text = '';
        $http_detail_lines = [];
        $http_top_line = '';
        $http_has_data = false;
        $http_status = 'status-ok';
        $http_empty_message = __('No outbound traffic recorded in the last 24 hours.', 'sitepulse');

        if (is_array($http_stats)) {
            $http_summary = isset($http_stats['summary']) && is_array($http_stats['summary']) ? $http_stats['summary'] : [];
            $http_thresholds = isset($http_stats['thresholds']) && is_array($http_stats['thresholds']) ? $http_stats['thresholds'] : [];
            $http_services = isset($http_stats['services']) && is_array($http_stats['services']) ? $http_stats['services'] : [];

            $http_total = isset($http_summary['total']) ? (int) $http_summary['total'] : 0;
            $http_error_rate = isset($http_summary['errorRate']) && $http_summary['errorRate'] !== null
                ? (float) $http_summary['errorRate']
                : null;
            $http_p95 = isset($http_summary['p95Duration']) && $http_summary['p95Duration'] !== null
                ? (float) $http_summary['p95Duration']
                : null;

            $summary_parts = [];

            if ($http_total > 0) {
                $http_has_data = true;
                $summary_parts[] = sprintf(
                    /* translators: %s: number of HTTP requests. */
                    _n('%s request', '%s requests', $http_total, 'sitepulse'),
                    number_format_i18n($http_total)
                );
            }

            if ($http_p95 !== null) {
                $summary_parts[] = sprintf(
                    /* translators: %s: latency in milliseconds. */
                    __('p95 %s ms', 'sitepulse'),
                    number_format_i18n($http_p95, 0)
                );
            }

            if ($http_error_rate !== null) {
                $summary_parts[] = sprintf(
                    /* translators: %s: error rate percentage. */
                    __('errors %s%%', 'sitepulse'),
                    number_format_i18n($http_error_rate, 2)
                );
            }

            if (!empty($summary_parts)) {
                $http_summary_text = array_shift($summary_parts);
                $http_detail_lines = $summary_parts;
            }

            if (!empty($http_services)) {
                $top_service = $http_services[0];
                $service_host = isset($top_service['host']) ? (string) $top_service['host'] : '';
                $service_path = isset($top_service['path']) ? (string) $top_service['path'] : '';
                $service_method = isset($top_service['method']) ? strtoupper((string) $top_service['method']) : 'GET';
                $service_avg = isset($top_service['average']) && $top_service['average'] !== null
                    ? (float) $top_service['average']
                    : null;
                $service_error_rate = isset($top_service['errorRate']) && $top_service['errorRate'] !== null
                    ? (float) $top_service['errorRate']
                    : null;

                $service_label = trim($service_host . $service_path);

                if ($service_label === '') {
                    $service_label = '/';
                }

                $detail_parts = [];

                if ($service_avg !== null) {
                    $detail_parts[] = sprintf(
                        /* translators: %s: latency in milliseconds. */
                        __('avg %s ms', 'sitepulse'),
                        number_format_i18n($service_avg, 0)
                    );
                }

                if ($service_error_rate !== null) {
                    $detail_parts[] = sprintf(
                        /* translators: %s: error rate percentage. */
                        __('%s%% errors', 'sitepulse'),
                        number_format_i18n($service_error_rate, 2)
                    );
                }

                $http_top_line = sprintf(
                    /* translators: 1: HTTP method. 2: Service host/path. */
                    __('Top: %1$s %2$s', 'sitepulse'),
                    $service_method,
                    $service_label
                );

                if (!empty($detail_parts)) {
                    $http_top_line .= ' — ' . implode(' · ', $detail_parts);
                }
            }

            $latency_threshold = isset($http_thresholds['latency']) ? (int) $http_thresholds['latency'] : 0;
            $error_threshold = isset($http_thresholds['errorRate']) ? (float) $http_thresholds['errorRate'] : 0.0;

            if ($http_p95 !== null && $latency_threshold > 0) {
                if ($http_p95 >= ($latency_threshold * 1.5)) {
                    $http_status = 'status-bad';
                } elseif ($http_p95 >= $latency_threshold) {
                    $http_status = 'status-warn';
                }
            }

            if ($http_error_rate !== null && $error_threshold > 0) {
                if ($http_error_rate >= $error_threshold) {
                    $http_status = 'status-bad';
                } elseif ($http_error_rate >= max(1.0, $error_threshold * 0.5)) {
                    if ($http_status !== 'status-bad') {
                        $http_status = 'status-warn';
                    }
                }
            }

            if (!$http_has_data) {
                $http_summary_text = '';
            }
        }

        $status_order = [
            'status-ok'   => 0,
            'status-warn' => 1,
            'status-bad'  => 2,
        ];

        $resource_status = 'status-ok';

        $adjust_status = static function ($current, $candidate) use ($status_order) {
            if (!isset($status_order[$candidate])) {
                return $current;
            }

            if (!isset($status_order[$current]) || $status_order[$candidate] > $status_order[$current]) {
                return $candidate;
            }

            return $current;
        };

        if ($load_values[0] !== null) {
            if ($load_values[0] >= 4.0) {
                $resource_status = $adjust_status($resource_status, 'status-bad');
            } elseif ($load_values[0] >= 2.0) {
                $resource_status = $adjust_status($resource_status, 'status-warn');
            }
        }

        if ($memory_percent !== null) {
            if ($memory_percent >= 90.0) {
                $resource_status = $adjust_status($resource_status, 'status-bad');
            } elseif ($memory_percent >= 75.0) {
                $resource_status = $adjust_status($resource_status, 'status-warn');
            }
        }

        if ($disk_free_percent !== null) {
            if ($disk_free_percent <= 10.0) {
                $resource_status = $adjust_status($resource_status, 'status-bad');
            } elseif ($disk_free_percent <= 20.0) {
                $resource_status = $adjust_status($resource_status, 'status-warn');
            }
        }

        if ($http_has_data && $http_status !== 'status-ok') {
            $resource_status = $adjust_status($resource_status, $http_status);
        }

        $resource_card = [
            'status'             => $resource_status,
            'load_display'       => $load_display,
            'memory_usage'       => $memory_usage,
            'memory_limit'       => $memory_limit,
            'memory_percent'     => $memory_percent,
            'disk_free'          => $disk_free,
            'disk_total'         => $disk_total,
            'disk_free_percent'  => $disk_free_percent,
            'generated_at'       => isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0,
            'http'               => [
                'status'        => $http_status,
                'summary'       => $http_summary_text,
                'details'       => $http_detail_lines,
                'top_service'   => $http_top_line,
                'has_data'      => $http_has_data,
                'empty_message' => $http_empty_message,
            ],
        ];

        $memory_dataset = [];
        $memory_chart_empty = true;

        if ($memory_limit_bytes > 0 && $memory_usage_bytes >= 0 && $memory_usage_bytes <= $memory_limit_bytes) {
            $memory_used_mb = $memory_usage_bytes / MB_IN_BYTES;
            $memory_free_mb = ($memory_limit_bytes - $memory_usage_bytes) / MB_IN_BYTES;
            $memory_chart_empty = false;

            $memory_dataset[] = [
                'data' => [
                    round($memory_used_mb, 2),
                    max(0, round($memory_free_mb, 2)),
                ],
                'backgroundColor' => [
                    $palette['amber'],
                    $palette['green'],
                ],
                'borderWidth' => 0,
            ];
        }

        $resource_chart = [
            'type'     => 'doughnut',
            'labels'   => [
                __('Memory used', 'sitepulse'),
                __('Memory available', 'sitepulse'),
            ],
            'datasets' => $memory_dataset,
            'unit'     => __('MB', 'sitepulse'),
            'empty'    => $memory_chart_empty,
            'status'   => $resource_status,
        ];

        $charts_payload['resource'] = $resource_chart;
    }

    $plugins_card = null;

    if ($is_plugins_enabled && function_exists('sitepulse_plugin_impact_get_measurements')) {
        $measurements = sitepulse_plugin_impact_get_measurements();
        $samples = isset($measurements['samples']) && is_array($measurements['samples']) ? $measurements['samples'] : [];
        $plugin_entries = [];
        $total_impact = 0.0;

        foreach ($samples as $plugin_file => $sample) {
            if (!is_array($sample) || !isset($sample['avg_ms']) || !is_numeric($sample['avg_ms'])) {
                continue;
            }

            $avg_ms = max(0.0, (float) $sample['avg_ms']);
            $last_ms = isset($sample['last_ms']) && is_numeric($sample['last_ms']) ? max(0.0, (float) $sample['last_ms']) : null;
            $count = isset($sample['samples']) ? max(0, (int) $sample['samples']) : 0;
            $last_recorded = isset($sample['last_recorded']) ? (int) $sample['last_recorded'] : 0;

            $label = $plugin_file;

            if (function_exists('sitepulse_plugin_impact_guess_slug')) {
                $slug = sitepulse_plugin_impact_guess_slug($plugin_file, []);
                if (is_string($slug) && $slug !== '') {
                    $label = ucwords(str_replace('-', ' ', str_replace('_', ' ', $slug)));
                }
            }

            $total_impact += $avg_ms;

            $plugin_entries[] = [
                'file'          => (string) $plugin_file,
                'label'         => (string) $label,
                'impact'        => $avg_ms,
                'last_ms'       => $last_ms,
                'samples'       => $count,
                'last_recorded' => $last_recorded,
            ];
        }

        if (!empty($plugin_entries)) {
            usort($plugin_entries, static function ($a, $b) {
                if ($a['impact'] === $b['impact']) {
                    return strcmp($a['label'], $b['label']);
                }

                return ($a['impact'] < $b['impact']) ? 1 : -1;
            });
        }

        $weights = [];
        $top_labels = [];
        $top_entries = array_slice($plugin_entries, 0, 5);

        foreach ($top_entries as $index => $entry) {
            $weight = null;

            if ($total_impact > 0) {
                $weight = ($entry['impact'] / $total_impact) * 100;
            }

            $weights[$index] = $weight !== null ? round($weight, 2) : null;
            $top_labels[$index] = $entry['label'];
        }

        $palette_cycle = [$palette['blue'], $palette['amber'], $palette['purple'], $palette['green'], $palette['red']];
        $dataset_colors = [];

        foreach ($top_entries as $i => $entry) {
            $dataset_colors[] = $palette_cycle[$i % count($palette_cycle)];
        }

        $plugins_chart = [
            'type'     => 'bar',
            'labels'   => array_values($top_labels),
            'datasets' => empty($top_entries) ? [] : [
                [
                    'data'            => array_values(array_map(static function ($value) {
                        return $value === null ? null : (float) $value;
                    }, $weights)),
                    'backgroundColor' => $dataset_colors,
                    'borderWidth'     => 0,
                ],
            ],
            'unit'     => __('%', 'sitepulse'),
            'empty'    => empty($top_entries),
            'status'   => 'status-ok',
            'options'  => [
                'indexAxis' => 'y',
            ],
            'meta'     => [
                'impacts' => array_values(array_map(static function ($entry) {
                    return isset($entry['impact']) ? (float) $entry['impact'] : null;
                }, $top_entries)),
            ],
        ];

        $threshold_defaults = function_exists('sitepulse_get_default_plugin_impact_thresholds')
            ? sitepulse_get_default_plugin_impact_thresholds()
            : [
                'impactWarning'  => 30.0,
                'impactCritical' => 60.0,
                'weightWarning'  => 10.0,
                'weightCritical' => 20.0,
                'trendWarning'   => 15.0,
                'trendCritical'  => 40.0,
            ];

        $thresholds = $threshold_defaults;

        if (defined('SITEPULSE_OPTION_IMPACT_THRESHOLDS')) {
            $stored_thresholds = get_option(
                SITEPULSE_OPTION_IMPACT_THRESHOLDS,
                [
                    'default' => $threshold_defaults,
                    'roles'   => [],
                ]
            );

            if (function_exists('sitepulse_sanitize_impact_thresholds')) {
                $stored_thresholds = sitepulse_sanitize_impact_thresholds($stored_thresholds);
            }

            if (is_array($stored_thresholds)) {
                $effective_thresholds = isset($stored_thresholds['default']) && is_array($stored_thresholds['default'])
                    ? $stored_thresholds['default']
                    : $threshold_defaults;

                if (isset($stored_thresholds['roles']) && is_array($stored_thresholds['roles'])) {
                    $current_user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;

                    if ($current_user instanceof WP_User) {
                        foreach ((array) $current_user->roles as $role) {
                            $role_key = sanitize_key($role);

                            if ($role_key !== '' && isset($stored_thresholds['roles'][$role_key])) {
                                $effective_thresholds = $stored_thresholds['roles'][$role_key];
                                break;
                            }
                        }
                    }
                }

                if (function_exists('sitepulse_normalize_impact_threshold_set')) {
                    $thresholds = sitepulse_normalize_impact_threshold_set($effective_thresholds, $threshold_defaults);
                } else {
                    $thresholds = wp_parse_args(is_array($effective_thresholds) ? $effective_thresholds : [], $threshold_defaults);
                }
            }
        }

        $top_plugin = isset($plugin_entries[0]) ? $plugin_entries[0] : null;
        $top_weight = null;

        if ($top_plugin !== null && $total_impact > 0) {
            $top_weight = ($top_plugin['impact'] / $total_impact) * 100;
        }

        $plugins_status = 'status-ok';

        if ($top_plugin !== null) {
            if (($top_plugin['impact'] >= $thresholds['impactCritical']) || ($top_weight !== null && $top_weight >= $thresholds['weightCritical'])) {
                $plugins_status = 'status-bad';
            } elseif (($top_plugin['impact'] >= $thresholds['impactWarning']) || ($top_weight !== null && $top_weight >= $thresholds['weightWarning'])) {
                $plugins_status = 'status-warn';
            }
        } elseif (empty($plugin_entries)) {
            $plugins_status = 'status-warn';
        }

        $plugins_chart['status'] = $plugins_status;

        $last_updated = isset($measurements['last_updated']) ? (int) $measurements['last_updated'] : 0;
        $interval = isset($measurements['interval']) ? (int) $measurements['interval'] : 0;
        $interval_label = '';

        if (function_exists('sitepulse_plugin_impact_format_interval')) {
            $interval_label = sitepulse_plugin_impact_format_interval($interval);
        }

        $last_updated_label = '';

        if ($last_updated > 0) {
            $display_timestamp = $last_updated;

            if (function_exists('sitepulse_plugin_impact_normalize_timestamp_for_display')) {
                $display_timestamp = sitepulse_plugin_impact_normalize_timestamp_for_display($last_updated);
            }

            $last_updated_label = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $display_timestamp);
        }

        $plugins_card = [
            'status'         => $plugins_status,
            'top_plugin'     => $top_plugin,
            'top_weight'     => $top_weight,
            'total_impact'   => $total_impact,
            'entries'        => $plugin_entries,
            'measured_count' => count($plugin_entries),
            'interval'       => $interval_label,
            'last_updated'   => $last_updated_label,
        ];

        $charts_payload['plugins'] = $plugins_chart;
    }

    }

    $module_chart_keys = [
        'speed_analyzer'     => 'speed',
        'uptime_tracker'     => 'uptime',
        'database_optimizer' => 'database',
        'log_analyzer'       => 'logs',
        'resource_monitor'   => 'resource',
        'plugin_impact_scanner' => 'plugins',
    ];

    foreach ($module_chart_keys as $module_key => $chart_key) {
        if (!in_array($module_key, $active_modules, true)) {
            unset($charts_payload[$chart_key]);
        }
    }

    $charts_for_localization = empty($charts_payload) ? new stdClass() : $charts_payload;

    $localization_payload = [
        'charts'  => $charts_for_localization,
        'strings' => [
            'noData'              => __('Not enough data to render this chart yet.', 'sitepulse'),
            'uptimeTooltipUp'     => __('Site operational', 'sitepulse'),
            'uptimeTooltipDown'   => __('Site unavailable', 'sitepulse'),
            'uptimeAxisLabel'     => __('Availability (%)', 'sitepulse'),
            'speedTooltipLabel'   => __('Measured time', 'sitepulse'),
            'speedTrendLabel'     => __('Processing time', 'sitepulse'),
            'speedAxisLabel'      => __('Processing time (ms)', 'sitepulse'),
            'speedBudgetLabel'    => __('Performance budget', 'sitepulse'),
            'speedOverBudgetLabel'=> __('Over budget', 'sitepulse'),
            'revisionsTooltip'    => __('Revisions', 'sitepulse'),
            'logEventsLabel'      => __('Events', 'sitepulse'),
            'pluginsImpactLabel'  => __('Impact', 'sitepulse'),
            'pluginsShareLabel'   => __('Share', 'sitepulse'),
            'pluginsImpactUnit'   => __('ms', 'sitepulse'),
        ],
    ];

    if (wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        wp_localize_script('sitepulse-dashboard-charts', 'SitePulseDashboardData', $localization_payload);
    }

    $current_page = isset($_GET['page']) ? sanitize_title((string) wp_unslash($_GET['page'])) : 'sitepulse-dashboard';

    if ($current_page === '') {
        $current_page = 'sitepulse-dashboard';
    }

    $module_navigation = function_exists('sitepulse_get_module_navigation_items')
        ? sitepulse_get_module_navigation_items($current_page)
        : [];

    $allowed_card_keys = sitepulse_get_dashboard_card_keys();
    $dashboard_preferences = sitepulse_get_dashboard_preferences(get_current_user_id(), $allowed_card_keys);
    $card_definitions = [
        'speed' => [
            'label'        => __('Speed', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_speed_enabled && $speed_card !== null),
            'content'      => '',
        ],
        'uptime' => [
            'label'        => __('Uptime', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_uptime_enabled && $uptime_card !== null),
            'content'      => '',
        ],
        'database' => [
            'label'        => __('Database Health', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_database_enabled && $database_card !== null),
            'content'      => '',
        ],
        'logs' => [
            'label'        => __('Error Log', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_logs_enabled && $logs_card !== null),
            'content'      => '',
        ],
        'resource' => [
            'label'        => __('Resources', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_resource_enabled && $resource_card !== null),
            'content'      => '',
        ],
        'plugins' => [
            'label'        => __('Plugin Impact', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_plugins_enabled && $plugins_card !== null),
            'content'      => '',
        ],
    ];

    if (!empty($card_definitions['speed']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-performance"></span> <?php esc_html_e('Speed', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-speed')); ?>" class="button button-secondary"><?php esc_html_e('Details', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Backend PHP processing time captured during recent scans.', 'sitepulse'); ?></p>
        <?php
            $speed_summary_html = sitepulse_render_chart_summary('sitepulse-speed-chart', $speed_chart);
            $speed_summary_id = sitepulse_get_chart_summary_id('sitepulse-speed-chart');
            $speed_canvas_describedby = ['sitepulse-speed-description'];

            if ('' !== $speed_summary_html) {
                $speed_canvas_describedby[] = $speed_summary_id;
            }
        ?>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-speed-chart" aria-describedby="<?php echo esc_attr(implode(' ', $speed_canvas_describedby)); ?>"></canvas>
            <?php echo $speed_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php $speed_status_meta = $get_status_meta($speed_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($speed_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($speed_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($speed_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($speed_status_meta['sr']); ?></span>
            <span class="sitepulse-metric-value"><?php echo esc_html($speed_card['display']); ?></span>
        </p>
        <p id="sitepulse-speed-description" class="description"><?php printf(
            esc_html__('Des temps inférieurs à %1$d ms indiquent une excellente réponse PHP. Au-delà de %2$d ms, envisagez d’auditer vos plugins ou votre hébergement.', 'sitepulse'),
            (int) $speed_warning_threshold,
            (int) $speed_critical_threshold
        ); ?></p>
        <?php
        $card_definitions['speed']['content'] = ob_get_clean();
    }

    if (!empty($card_definitions['uptime']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e('Uptime', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-uptime')); ?>" class="button button-secondary"><?php esc_html_e('Details', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Availability for the last 30 hourly checks.', 'sitepulse'); ?></p>
        <?php
            $uptime_summary_html = sitepulse_render_chart_summary('sitepulse-uptime-chart', $uptime_chart);
            $uptime_summary_id = sitepulse_get_chart_summary_id('sitepulse-uptime-chart');
            $uptime_canvas_describedby = ['sitepulse-uptime-description'];

            if ('' !== $uptime_summary_html) {
                $uptime_canvas_describedby[] = $uptime_summary_id;
            }
        ?>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-uptime-chart" aria-describedby="<?php echo esc_attr(implode(' ', $uptime_canvas_describedby)); ?>"></canvas>
            <?php echo $uptime_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php $uptime_status_meta = $get_status_meta($uptime_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($uptime_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($uptime_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($uptime_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($uptime_status_meta['sr']); ?></span>
            <span class="sitepulse-metric-value"><?php echo esc_html(round($uptime_card['percentage'], 2)); ?><span class="sitepulse-metric-unit"><?php esc_html_e('%', 'sitepulse'); ?></span></span>
        </p>
        <p id="sitepulse-uptime-description" class="description"><?php esc_html_e('Each bar shows whether the site responded during the scheduled availability probe.', 'sitepulse'); ?></p>
        <?php
        $card_definitions['uptime']['content'] = ob_get_clean();
    }

    if (!empty($card_definitions['database']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e('Database Health', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-db')); ?>" class="button button-secondary"><?php esc_html_e('Optimize', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Post revision volume compared to the recommended limit.', 'sitepulse'); ?></p>
        <?php
            $database_summary_html = sitepulse_render_chart_summary('sitepulse-database-chart', $database_chart);
            $database_summary_id = sitepulse_get_chart_summary_id('sitepulse-database-chart');
            $database_canvas_describedby = ['sitepulse-database-description'];

            if ('' !== $database_summary_html) {
                $database_canvas_describedby[] = $database_summary_id;
            }
        ?>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-database-chart" aria-describedby="<?php echo esc_attr(implode(' ', $database_canvas_describedby)); ?>"></canvas>
            <?php echo $database_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php $database_status_meta = $get_status_meta($database_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($database_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($database_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($database_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($database_status_meta['sr']); ?></span>
            <span class="sitepulse-metric-value">
                <?php echo esc_html(number_format_i18n($database_card['revisions'])); ?>
                <span class="sitepulse-metric-unit"><?php esc_html_e('revisions', 'sitepulse'); ?></span>
            </span>
        </p>
        <p id="sitepulse-database-description" class="description"><?php printf(esc_html__('Keep revisions under %d to avoid bloating the posts table. Cleaning them is safe and reversible with backups.', 'sitepulse'), (int) $database_card['limit']); ?></p>
        <?php
        $card_definitions['database']['content'] = ob_get_clean();
    }

    if (!empty($card_definitions['logs']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-hammer"></span> <?php esc_html_e('Error Log', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-logs')); ?>" class="button button-secondary"><?php esc_html_e('Analyze', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Breakdown of the most recent entries in the WordPress debug log.', 'sitepulse'); ?></p>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-log-chart" aria-describedby="sitepulse-log-description"></canvas>
        </div>
        <?php $logs_status_meta = $get_status_meta($logs_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($logs_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($logs_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($logs_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($logs_status_meta['sr']); ?></span>
            <span class="sitepulse-metric-value"><?php echo esc_html($logs_card['summary']); ?></span>
        </p>
        <ul class="sitepulse-legend">
            <li>
                <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['red']); ?>;"></span><?php esc_html_e('Fatal errors', 'sitepulse'); ?></span>
                <span class="value"><?php echo esc_html(number_format_i18n($logs_card['counts']['fatal'])); ?></span>
            </li>
            <li>
                <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['amber']); ?>;"></span><?php esc_html_e('Warnings', 'sitepulse'); ?></span>
                <span class="value"><?php echo esc_html(number_format_i18n($logs_card['counts']['warning'])); ?></span>
            </li>
            <li>
                <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['blue']); ?>;"></span><?php esc_html_e('Notices', 'sitepulse'); ?></span>
                <span class="value"><?php echo esc_html(number_format_i18n($logs_card['counts']['notice'])); ?></span>
            </li>
            <li>
                <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['purple']); ?>;"></span><?php esc_html_e('Deprecated notices', 'sitepulse'); ?></span>
                <span class="value"><?php echo esc_html(number_format_i18n($logs_card['counts']['deprecated'])); ?></span>
            </li>
        </ul>
        <p id="sitepulse-log-description" class="description"><?php esc_html_e('Use the analyzer to inspect full stack traces and silence recurring issues.', 'sitepulse'); ?></p>
        <?php
        $card_definitions['logs']['content'] = ob_get_clean();
    }

    if (!empty($card_definitions['resource']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-chart-area"></span> <?php esc_html_e('Resources', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-resources')); ?>" class="button button-secondary"><?php esc_html_e('Details', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Server load, memory, and disk headroom at a glance.', 'sitepulse'); ?></p>
        <?php
            $resource_summary_html = sitepulse_render_chart_summary('sitepulse-resource-chart', $resource_chart);
            $resource_summary_id = sitepulse_get_chart_summary_id('sitepulse-resource-chart');
            $resource_canvas_describedby = ['sitepulse-resource-description'];

            if ('' !== $resource_summary_html) {
                $resource_canvas_describedby[] = $resource_summary_id;
            }
        ?>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-resource-chart" aria-describedby="<?php echo esc_attr(implode(' ', $resource_canvas_describedby)); ?>"></canvas>
            <?php echo $resource_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php $resource_status_meta = $get_status_meta($resource_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($resource_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($resource_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($resource_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($resource_status_meta['sr']); ?></span>
            <span class="sitepulse-metric-value"><?php echo esc_html($resource_card['load_display']); ?></span>
            <span class="sitepulse-metric-unit"><?php esc_html_e('CPU (1/5/15)', 'sitepulse'); ?></span>
        </p>
        <ul class="sitepulse-legend">
            <li>
                <span class="label"><?php esc_html_e('Memory', 'sitepulse'); ?></span>
                <span class="value">
                    <?php echo esc_html($resource_card['memory_usage']); ?>
                    <?php if ($resource_card['memory_limit'] !== '') : ?>
                        <span class="sitepulse-metric-unit"><?php printf(esc_html__('of %s limit', 'sitepulse'), esc_html($resource_card['memory_limit'])); ?></span>
                    <?php endif; ?>
                    <?php if ($resource_card['memory_percent'] !== null) : ?>
                        <span class="sitepulse-metric-unit"><?php printf(esc_html__('(%s%% used)', 'sitepulse'), esc_html(number_format_i18n($resource_card['memory_percent'], 0))); ?></span>
                    <?php endif; ?>
                </span>
            </li>
            <li>
                <span class="label"><?php esc_html_e('Disk free', 'sitepulse'); ?></span>
                <span class="value">
                    <?php echo esc_html($resource_card['disk_free']); ?>
                    <?php if ($resource_card['disk_total'] !== '') : ?>
                        <span class="sitepulse-metric-unit"><?php printf(esc_html__('of %s total', 'sitepulse'), esc_html($resource_card['disk_total'])); ?></span>
                    <?php endif; ?>
                    <?php if ($resource_card['disk_free_percent'] !== null) : ?>
                        <span class="sitepulse-metric-unit"><?php printf(esc_html__('(%s%% free)', 'sitepulse'), esc_html(number_format_i18n($resource_card['disk_free_percent'], 0))); ?></span>
                    <?php endif; ?>
                </span>
            </li>
            <li>
                <span class="label"><?php esc_html_e('External calls (24h)', 'sitepulse'); ?></span>
                <span class="value">
                    <?php if (!empty($resource_card['http']['has_data'])) : ?>
                        <?php if ($resource_card['http']['summary'] !== '') : ?>
                            <?php echo esc_html($resource_card['http']['summary']); ?>
                        <?php endif; ?>
                        <?php foreach ((array) $resource_card['http']['details'] as $detail) : ?>
                            <span class="sitepulse-metric-unit"><?php echo esc_html($detail); ?></span>
                        <?php endforeach; ?>
                        <?php if (!empty($resource_card['http']['top_service'])) : ?>
                            <span class="sitepulse-metric-unit"><?php echo esc_html($resource_card['http']['top_service']); ?></span>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php echo esc_html($resource_card['http']['empty_message']); ?>
                    <?php endif; ?>
                </span>
            </li>
        </ul>
        <p id="sitepulse-resource-description" class="description">
            <?php
            if (!empty($resource_card['generated_at'])) {
                printf(
                    esc_html__('Snapshot generated on %s.', 'sitepulse'),
                    esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $resource_card['generated_at']))
                );
            } else {
                esc_html_e('Snapshot timing unavailable.', 'sitepulse');
            }
            ?>
        </p>
        <?php
        $card_definitions['resource']['content'] = ob_get_clean();
    }

    if (!empty($card_definitions['plugins']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e('Plugin Impact', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-plugins')); ?>" class="button button-secondary"><?php esc_html_e('Inspect', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Average load time added by the most expensive plugins.', 'sitepulse'); ?></p>
        <?php
            $plugins_summary_html = sitepulse_render_chart_summary('sitepulse-plugins-chart', $plugins_chart);
            $plugins_summary_id = sitepulse_get_chart_summary_id('sitepulse-plugins-chart');
            $plugins_canvas_describedby = ['sitepulse-plugins-description'];

            if ('' !== $plugins_summary_html) {
                $plugins_canvas_describedby[] = $plugins_summary_id;
            }
        ?>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-plugins-chart" aria-describedby="<?php echo esc_attr(implode(' ', $plugins_canvas_describedby)); ?>"></canvas>
            <?php echo $plugins_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php $plugins_status_meta = $get_status_meta($plugins_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($plugins_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($plugins_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($plugins_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($plugins_status_meta['sr']); ?></span>
            <?php if (!empty($plugins_card['top_plugin'])) : ?>
                <span class="sitepulse-metric-value"><?php echo esc_html(number_format_i18n($plugins_card['top_plugin']['impact'], 2)); ?><span class="sitepulse-metric-unit"><?php esc_html_e('ms', 'sitepulse'); ?></span></span>
                <span class="sitepulse-metric-unit"><?php printf(esc_html__('Top: %s', 'sitepulse'), esc_html($plugins_card['top_plugin']['label'])); ?></span>
                <?php if ($plugins_card['top_weight'] !== null) : ?>
                    <span class="sitepulse-metric-unit"><?php printf(esc_html__('(%s%% share)', 'sitepulse'), esc_html(number_format_i18n($plugins_card['top_weight'], 1))); ?></span>
                <?php endif; ?>
            <?php else : ?>
                <span class="sitepulse-metric-value"><?php esc_html_e('No measurements yet', 'sitepulse'); ?></span>
            <?php endif; ?>
        </p>
        <?php $top_display_entries = array_slice($plugins_card['entries'], 0, 3); ?>
        <?php if (!empty($top_display_entries)) : ?>
            <ul class="sitepulse-legend">
                <?php foreach ($top_display_entries as $entry) :
                    $share = ($plugins_card['total_impact'] > 0)
                        ? ($entry['impact'] / $plugins_card['total_impact']) * 100
                        : null;
                ?>
                    <li>
                        <span class="label"><?php echo esc_html($entry['label']); ?></span>
                        <span class="value">
                            <?php echo esc_html(number_format_i18n($entry['impact'], 2)); ?>
                            <span class="sitepulse-metric-unit"><?php esc_html_e('ms', 'sitepulse'); ?></span>
                            <?php if ($share !== null) : ?>
                                <span class="sitepulse-metric-unit"><?php printf(esc_html__('(%s%% share)', 'sitepulse'), esc_html(number_format_i18n($share, 1))); ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p id="sitepulse-plugins-description" class="description">
            <?php if (!empty($plugins_card['last_updated'])) : ?>
                <?php if ($plugins_card['interval'] !== '') : ?>
                    <?php printf(esc_html__('Sampled %1$s (refresh interval: %2$s).', 'sitepulse'), esc_html($plugins_card['last_updated']), esc_html($plugins_card['interval'])); ?>
                <?php else : ?>
                    <?php printf(esc_html__('Sampled %s.', 'sitepulse'), esc_html($plugins_card['last_updated'])); ?>
                <?php endif; ?>
            <?php elseif ($plugins_card['interval'] !== '') : ?>
                <?php printf(esc_html__('Next measurement expected every %s.', 'sitepulse'), esc_html($plugins_card['interval'])); ?>
            <?php else : ?>
                <?php esc_html_e('Measurements will appear after the scanner collects data.', 'sitepulse'); ?>
            <?php endif; ?>
        </p>
        <?php
        $card_definitions['plugins']['content'] = ob_get_clean();
    }

    $render_order = array_values(array_unique(array_merge(
        isset($dashboard_preferences['order']) && is_array($dashboard_preferences['order']) ? $dashboard_preferences['order'] : [],
        array_keys($card_definitions)
    )));

    $rendered_cards = [];
    $preferences_panel_items = [];
    $cards_for_localization = [];
    $visible_cards_count = 0;
    $allowed_sizes = ['small', 'medium', 'large'];

    foreach ($render_order as $card_key) {
        if (!isset($card_definitions[$card_key])) {
            continue;
        }

        $definition = $card_definitions[$card_key];
        $is_available = !empty($definition['available']);
        $size = isset($dashboard_preferences['sizes'][$card_key]) ? strtolower((string) $dashboard_preferences['sizes'][$card_key]) : $definition['default_size'];

        if (!in_array($size, $allowed_sizes, true)) {
            $size = $definition['default_size'];
        }

        $is_visible = isset($dashboard_preferences['visibility'][$card_key])
            ? (bool) $dashboard_preferences['visibility'][$card_key]
            : true;

        if (!$is_available) {
            $is_visible = false;
        }

        $should_render = $is_available && $definition['content'] !== '';

        if ($should_render && $is_visible) {
            $visible_cards_count++;
        }

        $rendered_cards[$card_key] = [
            'key'           => $card_key,
            'content'       => $definition['content'],
            'size'          => $size,
            'visible'       => $is_visible,
            'should_render' => $should_render,
            'available'     => $is_available,
            'label'         => $definition['label'],
        ];

        $preferences_panel_items[$card_key] = [
            'label'     => $definition['label'],
            'available' => $is_available,
            'visible'   => $is_visible,
            'size'      => $size,
        ];

        $cards_for_localization[$card_key] = [
            'label'       => $definition['label'],
            'available'   => $is_available,
            'defaultSize' => $definition['default_size'],
        ];
    }

    $theme_options = sitepulse_get_dashboard_theme_options();
    $theme_labels = [];
    $theme_choices = [];
    $current_theme = isset($dashboard_preferences['theme'])
        ? sitepulse_normalize_dashboard_theme($dashboard_preferences['theme'])
        : sitepulse_get_dashboard_default_theme();

    foreach ($theme_options as $theme_key => $theme_definition) {
        $theme_choices[] = $theme_key;
        $theme_labels[$theme_key] = isset($theme_definition['label'])
            ? wp_strip_all_tags((string) $theme_definition['label'])
            : $theme_key;
    }

    if (wp_script_is('sitepulse-dashboard-preferences', 'registered')) {
        wp_localize_script('sitepulse-dashboard-preferences', 'SitePulsePreferencesData', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('sitepulse_dashboard_preferences'),
            'preferences'  => $dashboard_preferences,
            'cards'        => $cards_for_localization,
            'sizes'        => [
                'small'  => __('Compacte', 'sitepulse'),
                'medium' => __('Standard', 'sitepulse'),
                'large'  => __('Étendue', 'sitepulse'),
            ],
            'themeOptions' => $theme_choices,
            'themeLabels'  => $theme_labels,
            'defaultTheme' => sitepulse_get_dashboard_default_theme(),
            'strings'      => [
                'panelDescription' => __('Réorganisez les cartes en les faisant glisser et choisissez celles à afficher.', 'sitepulse'),
                'toggleLabel'      => __('Afficher', 'sitepulse'),
                'sizeLabel'        => __('Taille', 'sitepulse'),
                'saveSuccess'      => __('Préférences enregistrées.', 'sitepulse'),
                'saveError'        => __('Impossible d’enregistrer les préférences.', 'sitepulse'),
                'moduleDisabled'   => __('Module requis pour afficher cette carte.', 'sitepulse'),
                'changesSaved'     => __('Les préférences du tableau de bord ont été mises à jour.', 'sitepulse'),
                'themeAnnouncement'=> __('Apparence définie sur %s.', 'sitepulse'),
                'themeSpoken'      => __('Thème mis à jour sur %s.', 'sitepulse'),
            ],
        ]);
        wp_enqueue_script('sitepulse-dashboard-preferences');
    }

    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-dashboard"></span> <?php esc_html_e('SitePulse Dashboard', 'sitepulse'); ?></h1>
        <p><?php esc_html_e("A real-time overview of your site's performance and health.", 'sitepulse'); ?></p>

        <?php if (!empty($module_navigation)) : ?>
            <?php sitepulse_render_module_navigation($current_page, $module_navigation); ?>
        <?php endif; ?>

        <?php
        $banner_cta_label = isset($banner_cta['label']) ? $banner_cta['label'] : '';
        $banner_cta_url   = isset($banner_cta['url']) ? $banner_cta['url'] : '';
        $banner_cta_data  = isset($banner_cta['data']) ? $banner_cta['data'] : '';
        ?>

        <div class="sitepulse-overview" data-sitepulse-metrics data-loading="false" aria-busy="false">
            <div class="sitepulse-overview__controls">
                <fieldset class="sitepulse-range-picker" data-sitepulse-range>
                    <legend><?php esc_html_e('Select timeframe', 'sitepulse'); ?></legend>
                    <div class="sitepulse-range-picker__options">
                        <?php foreach ($range_options as $option) :
                            $option_id = isset($option['id']) ? sanitize_key($option['id']) : '';
                            if ($option_id === '') {
                                continue;
                            }
                            $option_label = isset($option['label']) && is_string($option['label']) ? $option['label'] : $option_id;
                            $input_id = 'sitepulse-metrics-range-' . $option_id;
                        ?>
                            <label class="sitepulse-range-picker__option<?php echo ($option_id === $current_range) ? ' is-selected' : ''; ?>" for="<?php echo esc_attr($input_id); ?>">
                                <input type="radio" id="<?php echo esc_attr($input_id); ?>" name="sitepulse-metrics-range" value="<?php echo esc_attr($option_id); ?>" <?php checked($option_id === $current_range); ?> data-sitepulse-range-option />
                                <span><?php echo esc_html($option_label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="sitepulse-range-picker__select">
                        <span class="screen-reader-text"><?php esc_html_e('Select timeframe', 'sitepulse'); ?></span>
                        <select data-sitepulse-range-select>
                            <?php foreach ($range_options as $option) :
                                $option_id = isset($option['id']) ? sanitize_key($option['id']) : '';
                                if ($option_id === '') {
                                    continue;
                                }
                                $option_label = isset($option['label']) && is_string($option['label']) ? $option['label'] : $option_id;
                            ?>
                                <option value="<?php echo esc_attr($option_id); ?>" <?php selected($option_id, $current_range); ?>><?php echo esc_html($option_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </fieldset>
                <div class="sitepulse-overview__info">
                    <div class="sitepulse-overview__meta">
                        <p class="sitepulse-overview__range">
                            <span class="sitepulse-overview__meta-label"><?php esc_html_e('Active window:', 'sitepulse'); ?></span>
                            <span data-sitepulse-range-label><?php echo esc_html($range_label); ?></span>
                        </p>
                        <p class="sitepulse-overview__generated" data-sitepulse-generated><?php echo esc_html($generated_text); ?></p>
                    </div>
                    <?php echo sitepulse_render_dashboard_theme_toggle($current_theme, $theme_options); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>

            <?php echo sitepulse_render_dashboard_health_hero($health_view); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="sitepulse-status-banner sitepulse-status-banner--<?php echo esc_attr($banner_tone); ?>" data-sitepulse-banner role="status" aria-live="polite">
            <div class="sitepulse-status-banner__content">
                <span class="sitepulse-status-banner__icon" aria-hidden="true" data-sitepulse-banner-icon><?php echo esc_html($banner_icon); ?></span>
                <p class="sitepulse-status-banner__message" data-sitepulse-banner-message><?php echo esc_html($banner_message); ?></p>
                <span class="screen-reader-text" data-sitepulse-banner-sr><?php echo esc_html($banner_sr); ?></span>
            </div>
            <?php echo sitepulse_render_dashboard_banner_kpis(isset($banner_view['kpis']) ? $banner_view['kpis'] : []); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php if ($banner_cta_label !== '' && $banner_cta_url !== '') : ?>
                <a href="<?php echo esc_url($banner_cta_url); ?>" class="button button-primary sitepulse-status-banner__cta" data-sitepulse-banner-cta<?php echo $banner_cta_data !== '' ? ' data-cta="' . esc_attr($banner_cta_data) . '"' : ''; ?>><?php echo esc_html($banner_cta_label); ?></a>
            <?php else : ?>
                <span class="sitepulse-status-banner__cta" data-sitepulse-banner-cta hidden></span>
            <?php endif; ?>
            </div>

            <?php echo sitepulse_render_dashboard_playbooks($playbooks_view); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo sitepulse_render_dashboard_sla_action($sla_view); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="sitepulse-kpi-grid" data-sitepulse-metrics-grid>
                <?php foreach ($metrics_cards as $card_key => $card_data) : ?>
                    <?php echo sitepulse_render_dashboard_metric_card($card_key, $card_data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endforeach; ?>
            </div>

            <div class="sitepulse-metrics__error notice notice-error" role="alert" hidden data-sitepulse-metrics-error></div>
            <span class="screen-reader-text" aria-live="polite" data-sitepulse-metrics-announcer></span>
        </div>

        <div class="sitepulse-dashboard-preferences">
            <button type="button" class="button button-secondary sitepulse-preferences__toggle" aria-expanded="false" aria-controls="sitepulse-preferences-panel">
                <?php esc_html_e('Personnaliser l\'affichage', 'sitepulse'); ?>
            </button>
            <div id="sitepulse-preferences-panel" class="sitepulse-preferences__panel" hidden tabindex="-1">
                <p class="sitepulse-preferences__description"><?php esc_html_e('Réorganisez les cartes en les faisant glisser et choisissez celles à afficher.', 'sitepulse'); ?></p>
                <ul class="sitepulse-preferences__list" data-sitepulse-preferences-list>
                    <?php foreach ($render_order as $card_key) :
                        if (!isset($preferences_panel_items[$card_key])) {
                            continue;
                        }

                        $item = $preferences_panel_items[$card_key];
                    ?>
                        <li class="sitepulse-preferences__item<?php echo !$item['available'] ? ' is-disabled' : ''; ?>" data-card-key="<?php echo esc_attr($card_key); ?>" data-card-enabled="<?php echo $item['available'] ? '1' : '0'; ?>">
                            <span class="sitepulse-preferences__drag-handle" aria-hidden="true"></span>
                            <div class="sitepulse-preferences__details">
                                <span class="sitepulse-preferences__label"><?php echo esc_html($item['label']); ?></span>
                                <?php if (!$item['available']) : ?>
                                    <span class="sitepulse-preferences__status"><?php esc_html_e('Module requis pour afficher cette carte.', 'sitepulse'); ?></span>
                                <?php endif; ?>
                                <div class="sitepulse-preferences__controls">
                                    <label class="sitepulse-preferences__control">
                                        <input type="checkbox" class="sitepulse-preferences__visibility" <?php checked(!empty($item['visible'])); ?> <?php disabled(!$item['available']); ?> />
                                        <span><?php esc_html_e('Afficher', 'sitepulse'); ?></span>
                                    </label>
                                    <label class="sitepulse-preferences__control sitepulse-preferences__control--size">
                                        <span class="sitepulse-preferences__control-label"><?php esc_html_e('Taille', 'sitepulse'); ?></span>
                                        <span class="screen-reader-text"><?php printf(esc_html__('Taille de la carte %s', 'sitepulse'), $item['label']); ?></span>
                                        <select class="sitepulse-preferences__size" <?php disabled(!$item['available']); ?>>
                                            <option value="small" <?php selected($item['size'], 'small'); ?>><?php esc_html_e('Compacte', 'sitepulse'); ?></option>
                                            <option value="medium" <?php selected($item['size'], 'medium'); ?>><?php esc_html_e('Standard', 'sitepulse'); ?></option>
                                            <option value="large" <?php selected($item['size'], 'large'); ?>><?php esc_html_e('Étendue', 'sitepulse'); ?></option>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="sitepulse-preferences__notice is-hidden" role="status" aria-live="polite"></div>
                <div class="sitepulse-preferences__actions">
                    <button type="button" class="button button-primary sitepulse-preferences__save"><?php esc_html_e('Enregistrer', 'sitepulse'); ?></button>
                    <button type="button" class="button sitepulse-preferences__cancel"><?php esc_html_e('Annuler', 'sitepulse'); ?></button>
                </div>
            </div>
        </div>

        <div class="sitepulse-grid" data-sitepulse-card-grid>
            <?php foreach ($render_order as $card_key) :
                if (!isset($rendered_cards[$card_key])) {
                    continue;
                }

                $card = $rendered_cards[$card_key];

                if (!$card['should_render']) {
                    continue;
                }

                $card_classes = ['sitepulse-card', 'sitepulse-card--' . $card['size']];

                if (!$card['visible']) {
                    $card_classes[] = 'sitepulse-card--is-hidden';
                }
            ?>
                <div class="<?php echo esc_attr(implode(' ', $card_classes)); ?>"
                    data-card-key="<?php echo esc_attr($card['key']); ?>"
                    data-card-size="<?php echo esc_attr($card['size']); ?>"
                    data-card-enabled="<?php echo $card['available'] ? '1' : '0'; ?>"<?php if (!$card['visible']) { echo ' hidden aria-hidden="true"'; } ?>>
                    <?php echo $card['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="sitepulse-empty-state" data-sitepulse-empty-state <?php echo ($visible_cards_count === 0) ? '' : 'hidden'; ?>>
            <h2><?php esc_html_e('Votre tableau de bord est vide', 'sitepulse'); ?></h2>
            <p><?php esc_html_e('Utilisez le bouton « Personnaliser l’affichage » pour sélectionner des cartes.', 'sitepulse'); ?></p>
        </div>
    </div>
    <?php
}
