<?php
/**
 * SitePulse Custom Dashboards Module
 *
 * This module creates the main dashboard page for the plugin.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly.

add_action('admin_enqueue_scripts', 'sitepulse_custom_dashboard_enqueue_assets');

/**
 * Registers the assets used by the SitePulse dashboard when the page is loaded.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
function sitepulse_custom_dashboard_enqueue_assets($hook_suffix) {
    if ('toplevel_page_sitepulse-dashboard' !== $hook_suffix) {
        return;
    }

    $default_chartjs_src = SITEPULSE_URL . 'modules/vendor/chart.js/chart.umd.js';
    $chartjs_src = apply_filters('sitepulse_chartjs_src', $default_chartjs_src);

    if ($chartjs_src !== $default_chartjs_src) {
        $original_chartjs_src = $chartjs_src;
        $is_valid_chartjs_src = false;

        if (is_string($chartjs_src) && $chartjs_src !== '') {
            $validated_chartjs_src = wp_http_validate_url($chartjs_src);

            if ($validated_chartjs_src !== false) {
                $parsed_chartjs_src = wp_parse_url($validated_chartjs_src);
                $scheme = isset($parsed_chartjs_src['scheme']) ? strtolower($parsed_chartjs_src['scheme']) : '';
                $is_https = ('https' === $scheme);
                $is_plugin_internal = false;

                $sitepulse_base = wp_parse_url(SITEPULSE_URL);

                if (is_array($parsed_chartjs_src) && is_array($sitepulse_base)) {
                    $source_host = isset($parsed_chartjs_src['host']) ? strtolower($parsed_chartjs_src['host']) : '';
                    $base_host = isset($sitepulse_base['host']) ? strtolower($sitepulse_base['host']) : '';

                    if ($source_host && $base_host && $source_host === $base_host) {
                        $source_path = isset($parsed_chartjs_src['path']) ? $parsed_chartjs_src['path'] : '';
                        $base_path = isset($sitepulse_base['path']) ? $sitepulse_base['path'] : '';

                        if ($base_path === '' || strpos($source_path, $base_path) === 0) {
                            $is_plugin_internal = true;
                        }
                    }
                }

                if ($is_https || $is_plugin_internal) {
                    $chartjs_src = $validated_chartjs_src;
                    $is_valid_chartjs_src = true;
                }
            } elseif (strpos($chartjs_src, SITEPULSE_URL) === 0) {
                // Allow internal plugin URLs even if wp_http_validate_url() returned false.
                $is_valid_chartjs_src = true;
            }
        }

        if (!$is_valid_chartjs_src) {
            if (function_exists('sitepulse_log')) {
                $log_value = '';

                if (is_string($original_chartjs_src)) {
                    $log_value = esc_url_raw($original_chartjs_src);
                } elseif (is_scalar($original_chartjs_src)) {
                    $log_value = (string) $original_chartjs_src;
                } else {
                    $encoded_value = wp_json_encode($original_chartjs_src);
                    $log_value = is_string($encoded_value) ? $encoded_value : '';
                }

                sitepulse_log(
                    sprintf(
                        'SitePulse: invalid Chart.js source override rejected. Value: %s',
                        $log_value
                    ),
                    'DEBUG'
                );
            }

            $chartjs_src = $default_chartjs_src;
        }
    }

    wp_register_script(
        'sitepulse-chartjs',
        $chartjs_src,
        [],
        '4.4.5',
        true
    );

    if ($chartjs_src !== $default_chartjs_src) {
        $fallback_loader = '(function(){if (typeof window.Chart === "undefined") {'
            . 'var script=document.createElement("script");'
            . 'script.src=' . wp_json_encode($default_chartjs_src) . ';'
            . 'script.defer=true;'
            . 'document.head.appendChild(script);'
            . '}})();';

        wp_add_inline_script('sitepulse-chartjs', $fallback_loader, 'after');
    }

    wp_register_script(
        'sitepulse-dashboard-charts',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-charts.js',
        ['sitepulse-chartjs'],
        SITEPULSE_VERSION,
        true
    );
}

/**
 * Renders the HTML for the main SitePulse dashboard page.
 *
 * This page provides a visual overview of the site's key metrics,
 * acting as a central hub for site health information.
 *
 * Note: The menu registration for this page is now handled in 'admin-settings.php'
 * to prevent conflicts and duplicate menus.
 */
function sitepulse_custom_dashboards_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    global $wpdb;

    $active_modules = array_map('strval', (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []));
    $is_speed_enabled = in_array('speed_analyzer', $active_modules, true);
    $is_uptime_enabled = in_array('uptime_tracker', $active_modules, true);
    $is_database_enabled = in_array('database_optimizer', $active_modules, true);
    $is_logs_enabled = in_array('log_analyzer', $active_modules, true);

    if (!wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        sitepulse_custom_dashboard_enqueue_assets('toplevel_page_sitepulse-dashboard');
    }

    if (wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        wp_enqueue_script('sitepulse-chartjs');
        wp_enqueue_script('sitepulse-dashboard-charts');
    }

    $palette = [
        'green'    => '#4CAF50',
        'amber'    => '#FFC107',
        'red'      => '#F44336',
        'deep_red' => '#D32F2F',
        'blue'     => '#2196F3',
        'grey'     => '#E0E0E0',
        'purple'   => '#9C27B0',
    ];

    $charts_payload = [];
    $speed_card = null;

    if ($is_speed_enabled) {
        $results = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        $processing_time = null;

        if (is_array($results)) {
            if (isset($results['server_processing_ms']) && is_numeric($results['server_processing_ms'])) {
                $processing_time = (float) $results['server_processing_ms'];
            } elseif (isset($results['ttfb']) && is_numeric($results['ttfb'])) {
                $processing_time = (float) $results['ttfb'];
            } elseif (isset($results['data']['server_processing_ms']) && is_numeric($results['data']['server_processing_ms'])) {
                $processing_time = (float) $results['data']['server_processing_ms'];
            } elseif (isset($results['data']['ttfb']) && is_numeric($results['data']['ttfb'])) {
                $processing_time = (float) $results['data']['ttfb'];
            }
        }

        $processing_status = 'status-ok';

        if ($processing_time === null) {
            $processing_status = 'status-warn';
        } elseif ($processing_time > 500) {
            $processing_status = 'status-bad';
        } elseif ($processing_time > 200) {
            $processing_status = 'status-warn';
        }

        $processing_display = $processing_time !== null
            ? round($processing_time) . ' ' . esc_html__('ms', 'sitepulse')
            : esc_html__('N/A', 'sitepulse');

        $speed_reference = 1000;
        $speed_chart = [
            'type'     => 'doughnut',
            'labels'   => [],
            'datasets' => [],
            'empty'    => true,
            'status'   => $processing_status,
            'value'    => $processing_time !== null ? round($processing_time, 2) : null,
            'unit'     => __('ms', 'sitepulse'),
        ];

        if ($processing_time !== null) {
            $speed_chart['empty'] = false;
            $speed_color_map = [
                'status-ok'   => $palette['green'],
                'status-warn' => $palette['amber'],
                'status-bad'  => $palette['red'],
            ];
            $speed_primary_color = isset($speed_color_map[$processing_status]) ? $speed_color_map[$processing_status] : $palette['blue'];

            if ($processing_time <= $speed_reference) {
                $speed_chart['labels'] = [
                    __('Measured time', 'sitepulse'),
                    __('Performance budget', 'sitepulse'),
                ];
                $speed_chart['datasets'][] = [
                    'data' => [
                        round($processing_time, 2),
                        round(max(0, $speed_reference - $processing_time), 2),
                    ],
                    'backgroundColor' => [
                        $speed_primary_color,
                        $palette['grey'],
                    ],
                ];
            } else {
                $speed_chart['labels'] = [
                    __('Performance budget', 'sitepulse'),
                    __('Over budget', 'sitepulse'),
                ];
                $speed_chart['datasets'][] = [
                    'data' => [
                        $speed_reference,
                        round($processing_time - $speed_reference, 2),
                    ],
                    'backgroundColor' => [
                        $speed_primary_color,
                        $palette['deep_red'],
                    ],
                ];
            }
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
        $uptime_status = $uptime_percentage < 99 ? 'status-bad' : ($uptime_percentage < 100 ? 'status-warn' : 'status-ok');
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
        $db_status = $revisions > 100 ? 'status-bad' : ($revisions > 50 ? 'status-warn' : 'status-ok');
        $revision_limit = 100;

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
        $log_file = function_exists('sitepulse_get_wp_debug_log_path') ? sitepulse_get_wp_debug_log_path() : null;
        $log_status_class = 'status-ok';
        $log_summary = __('Log is clean.', 'sitepulse');
        $log_counts = [
            'fatal'      => 0,
            'warning'    => 0,
            'notice'     => 0,
            'deprecated' => 0,
        ];
        $log_chart_empty = true;

        if ($log_file === null) {
            $log_status_class = 'status-warn';
            $log_summary = __('Debug log not configured.', 'sitepulse');
        } elseif (!file_exists($log_file)) {
            $log_status_class = 'status-warn';
            $log_summary = sprintf(__('Log file not found (%s).', 'sitepulse'), esc_html($log_file));
        } elseif (!is_readable($log_file)) {
            $log_status_class = 'status-warn';
            $log_summary = sprintf(__('Unable to read log file (%s).', 'sitepulse'), esc_html($log_file));
        } else {
            $recent_logs = sitepulse_get_recent_log_lines($log_file, 200, 131072);

            if ($recent_logs === null) {
                $log_status_class = 'status-warn';
                $log_summary = sprintf(__('Unable to read log file (%s).', 'sitepulse'), esc_html($log_file));
            } elseif (empty($recent_logs)) {
                $log_summary = __('No recent log entries.', 'sitepulse');
            } else {
                $log_chart_empty = false;
                $log_content = implode("\n", $recent_logs);

                $patterns = [
                    'fatal'      => '/PHP (Fatal error|Parse error|Uncaught)/i',
                    'warning'    => '/PHP Warning/i',
                    'notice'     => '/PHP Notice/i',
                    'deprecated' => '/PHP Deprecated/i',
                ];

                foreach ($patterns as $type => $pattern) {
                    $matches = preg_match_all($pattern, $log_content, $ignore_matches);
                    $log_counts[$type] = $matches ? (int) $matches : 0;
                }

                if ($log_counts['fatal'] > 0) {
                    $log_status_class = 'status-bad';
                    $log_summary = __('Fatal errors detected in the debug log.', 'sitepulse');
                } elseif ($log_counts['warning'] > 0 || $log_counts['deprecated'] > 0) {
                    $log_status_class = 'status-warn';
                    $log_summary = __('Warnings present in the debug log.', 'sitepulse');
                } else {
                    $log_summary = __('No critical events detected.', 'sitepulse');
                }
            }
        }

        $log_chart = [
            'type'     => 'doughnut',
            'labels'   => [
                __('Fatal errors', 'sitepulse'),
                __('Warnings', 'sitepulse'),
                __('Notices', 'sitepulse'),
                __('Deprecated notices', 'sitepulse'),
            ],
            'datasets' => $log_chart_empty ? [] : [
                [
                    'data' => array_values($log_counts),
                    'backgroundColor' => [
                        $palette['red'],
                        $palette['amber'],
                        $palette['blue'],
                        $palette['purple'],
                    ],
                ],
            ],
            'empty'   => $log_chart_empty,
            'status'  => $log_status_class,
        ];

        $charts_payload['logs'] = $log_chart;
        $logs_card = [
            'status' => $log_status_class,
            'summary' => $log_summary,
            'counts'  => $log_counts,
        ];
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
            'speedBudgetLabel'    => __('Performance budget', 'sitepulse'),
            'speedOverBudgetLabel'=> __('Over budget', 'sitepulse'),
            'revisionsTooltip'    => __('Revisions', 'sitepulse'),
            'logEventsLabel'      => __('Events', 'sitepulse'),
        ],
    ];

    if (wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        wp_localize_script('sitepulse-dashboard-charts', 'SitePulseDashboardData', $localization_payload);
    }

    ?>
    <style>
        .sitepulse-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-top: 20px; }
        .sitepulse-card { background: #fff; padding: 20px; border: 1px solid #ddd; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 6px; display: flex; flex-direction: column; }
        .sitepulse-card-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 6px; }
        .sitepulse-card h2 { font-size: 16px; margin: 0; display: flex; align-items: center; gap: 8px; }
        .sitepulse-card .button { margin-left: auto; }
        .sitepulse-card-subtitle { margin: 0 0 12px; color: #616161; font-size: 13px; }
        .sitepulse-chart-container { position: relative; height: 220px; margin: 0 0 16px; }
        .sitepulse-chart-container canvas { width: 100% !important; height: 100% !important; }
        .sitepulse-chart-empty { text-align: center; color: #666; padding: 48px 16px; border: 1px dashed #d9d9d9; border-radius: 6px; font-size: 13px; }
        .sitepulse-metric { margin: 0; font-size: 28px; font-weight: 600; }
        .sitepulse-metric-unit { font-size: 12px; text-transform: uppercase; margin-left: 6px; color: #757575; letter-spacing: 0.05em; }
        .sitepulse-card .status-ok { color: <?php echo esc_attr($palette['green']); ?>; }
        .sitepulse-card .status-warn { color: <?php echo esc_attr($palette['amber']); ?>; }
        .sitepulse-card .status-bad { color: <?php echo esc_attr($palette['red']); ?>; }
        .sitepulse-legend { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 6px; font-size: 13px; }
        .sitepulse-legend li { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .sitepulse-legend .label { display: flex; align-items: center; gap: 8px; }
        .sitepulse-legend .badge { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
        .sitepulse-legend .value { font-weight: 600; }
        .sitepulse-card .description { margin-top: 12px; color: #616161; }
    </style>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-dashboard"></span> <?php esc_html_e('SitePulse Dashboard', 'sitepulse'); ?></h1>
        <p><?php esc_html_e("A real-time overview of your site's performance and health.", 'sitepulse'); ?></p>

        <div class="sitepulse-grid">
            <?php if ($speed_card !== null): ?>
                <div class="sitepulse-card">
                    <div class="sitepulse-card-header">
                        <h2><span class="dashicons dashicons-performance"></span> <?php esc_html_e('Speed', 'sitepulse'); ?></h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-speed')); ?>" class="button button-secondary"><?php esc_html_e('Details', 'sitepulse'); ?></a>
                    </div>
                    <p class="sitepulse-card-subtitle"><?php esc_html_e('Backend PHP processing time captured during the latest scan.', 'sitepulse'); ?></p>
                    <div class="sitepulse-chart-container">
                        <canvas id="sitepulse-speed-chart" aria-describedby="sitepulse-speed-description"></canvas>
                    </div>
                    <p class="sitepulse-metric <?php echo esc_attr($speed_card['status']); ?>"><?php echo esc_html($speed_card['display']); ?></p>
                    <p id="sitepulse-speed-description" class="description"><?php esc_html_e('Under 200ms indicates an excellent PHP response. Above 500ms suggests investigating plugins or hosting performance.', 'sitepulse'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($uptime_card !== null): ?>
                <div class="sitepulse-card">
                    <div class="sitepulse-card-header">
                        <h2><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e('Uptime', 'sitepulse'); ?></h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-uptime')); ?>" class="button button-secondary"><?php esc_html_e('Details', 'sitepulse'); ?></a>
                    </div>
                    <p class="sitepulse-card-subtitle"><?php esc_html_e('Availability for the last 30 hourly checks.', 'sitepulse'); ?></p>
                    <div class="sitepulse-chart-container">
                        <canvas id="sitepulse-uptime-chart" aria-describedby="sitepulse-uptime-description"></canvas>
                    </div>
                    <p class="sitepulse-metric <?php echo esc_attr($uptime_card['status']); ?>"><?php echo esc_html(round($uptime_card['percentage'], 2)); ?>%</p>
                    <p id="sitepulse-uptime-description" class="description"><?php esc_html_e('Each bar shows whether the site responded during the scheduled availability probe.', 'sitepulse'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($database_card !== null): ?>
                <div class="sitepulse-card">
                    <div class="sitepulse-card-header">
                        <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e('Database Health', 'sitepulse'); ?></h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-db')); ?>" class="button button-secondary"><?php esc_html_e('Optimize', 'sitepulse'); ?></a>
                    </div>
                    <p class="sitepulse-card-subtitle"><?php esc_html_e('Post revision volume compared to the recommended limit.', 'sitepulse'); ?></p>
                    <div class="sitepulse-chart-container">
                        <canvas id="sitepulse-database-chart" aria-describedby="sitepulse-database-description"></canvas>
                    </div>
                    <p class="sitepulse-metric <?php echo esc_attr($database_card['status']); ?>">
                        <?php echo esc_html(number_format_i18n($database_card['revisions'])); ?>
                        <span class="sitepulse-metric-unit"><?php esc_html_e('revisions', 'sitepulse'); ?></span>
                    </p>
                    <p id="sitepulse-database-description" class="description"><?php printf(esc_html__('Keep revisions under %d to avoid bloating the posts table. Cleaning them is safe and reversible with backups.', 'sitepulse'), (int) $database_card['limit']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($logs_card !== null): ?>
                <div class="sitepulse-card">
                    <div class="sitepulse-card-header">
                        <h2><span class="dashicons dashicons-hammer"></span> <?php esc_html_e('Error Log', 'sitepulse'); ?></h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-logs')); ?>" class="button button-secondary"><?php esc_html_e('Analyze', 'sitepulse'); ?></a>
                    </div>
                    <p class="sitepulse-card-subtitle"><?php esc_html_e('Breakdown of the most recent entries in the WordPress debug log.', 'sitepulse'); ?></p>
                    <div class="sitepulse-chart-container">
                        <canvas id="sitepulse-log-chart" aria-describedby="sitepulse-log-description"></canvas>
                    </div>
                    <p class="sitepulse-metric <?php echo esc_attr($logs_card['status']); ?>"><?php echo esc_html($logs_card['summary']); ?></p>
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
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
