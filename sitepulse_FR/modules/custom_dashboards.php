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

    wp_register_style(
        'sitepulse-custom-dashboard',
        SITEPULSE_URL . 'modules/css/custom-dashboard.css',
        [],
        SITEPULSE_VERSION
    );

    wp_enqueue_style('sitepulse-custom-dashboard');

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
 * Retrieves the summary element ID for a chart.
 *
 * @param string $chart_id Chart identifier.
 *
 * @return string Summary element identifier.
 */
function sitepulse_get_chart_summary_id($chart_id) {
    $sanitized_id = is_string($chart_id) ? sanitize_html_class($chart_id) : '';

    if ('' === $sanitized_id) {
        $sanitized_id = 'sitepulse-chart';
    }

    return $sanitized_id . '-summary';
}

/**
 * Builds an accessible summary list for a chart dataset.
 *
 * @param string $chart_id    Base identifier for the chart.
 * @param array  $chart_data  Chart configuration array containing labels and datasets.
 *
 * @return string Rendered HTML list or an empty string when no data is available.
 */
function sitepulse_render_chart_summary($chart_id, $chart_data) {
    if (!is_string($chart_id) || $chart_id === '' || !is_array($chart_data)) {
        return '';
    }

    $labels = isset($chart_data['labels']) ? (array) $chart_data['labels'] : [];
    $datasets = isset($chart_data['datasets']) && is_array($chart_data['datasets'])
        ? $chart_data['datasets']
        : [];

    if (empty($labels) || empty($datasets)) {
        return '';
    }

    $unit = '';

    if (isset($chart_data['unit']) && is_string($chart_data['unit']) && $chart_data['unit'] !== '') {
        $unit = $chart_data['unit'];
    }

    $items = [];

    foreach ($labels as $index => $label) {
        $values = [];

        foreach ($datasets as $dataset) {
            if (!is_array($dataset) || !isset($dataset['data']) || !is_array($dataset['data'])) {
                continue;
            }

            if (!array_key_exists($index, $dataset['data'])) {
                continue;
            }

            $value = $dataset['data'][$index];

            if (is_numeric($value)) {
                $numeric_value = (float) $value;
                $precision = floor($numeric_value) === $numeric_value ? 0 : 2;
                $formatted_value = number_format_i18n($numeric_value, $precision);
            } elseif (is_scalar($value)) {
                $formatted_value = (string) $value;
            } else {
                continue;
            }

            if ('' !== $unit) {
                $formatted_value .= ' ' . $unit;
            }

            $values[] = $formatted_value;
        }

        if (empty($values)) {
            continue;
        }

        $items[] = sprintf(
            '<li>%1$s: %2$s</li>',
            esc_html(wp_strip_all_tags((string) $label)),
            esc_html(implode(', ', $values))
        );
    }

    if (empty($items)) {
        return '';
    }

    $summary_id = sitepulse_get_chart_summary_id($chart_id);

    return sprintf(
        '<ul id="%1$s" class="sitepulse-chart-summary">%2$s</ul>',
        esc_attr($summary_id),
        implode('', $items)
    );
}

/**
 * Builds the datasets and card metadata for the SitePulse dashboard.
 *
 * @param array $args {
 *     Optional. Arguments controlling the data extraction.
 *
 *     @type int   $period  Number of days to include in historical datasets. Default 30.
 *     @type array $modules Specific module keys to include. Default empty (all active modules).
 * }
 *
 * @return array{
 *     charts: array,
 *     cards: array,
 *     palette: array,
 *     status_labels: array,
 *     active_modules: array,
 *     modules: array,
 *     period: int
 * }
 */
function sitepulse_get_dashboard_data($args = []) {
    $defaults = [
        'period'  => 30,
        'modules' => [],
    ];

    $args = wp_parse_args($args, $defaults);

    $period_days = isset($args['period']) ? (int) $args['period'] : 30;

    if ($period_days < 1) {
        $period_days = 30;
    }

    $requested_modules = [];

    if (isset($args['modules'])) {
        $raw_modules = is_array($args['modules']) ? $args['modules'] : explode(',', (string) $args['modules']);

        foreach ($raw_modules as $module_key) {
            $sanitized = sanitize_key((string) $module_key);

            if ($sanitized !== '') {
                $requested_modules[] = $sanitized;
            }
        }
    }

    $active_modules = array_map('strval', (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []));

    if (!empty($requested_modules)) {
        $filtered_requested = array_values(array_intersect($active_modules, $requested_modules));
    } else {
        $filtered_requested = array_values($active_modules);
    }

    $palette = [
        'green'    => '#0b6d2a',
        'amber'    => '#8a6100',
        'red'      => '#a0141e',
        'deep_red' => '#7f1018',
        'blue'     => '#2196F3',
        'grey'     => '#E0E0E0',
        'purple'   => '#9C27B0',
    ];

    $status_labels = [
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

    $charts_payload = [];
    $cards_payload = [];

    $include_speed = in_array('speed_analyzer', $filtered_requested, true);
    $include_uptime = in_array('uptime_tracker', $filtered_requested, true);
    $include_database = in_array('database_optimizer', $filtered_requested, true);
    $include_logs = in_array('log_analyzer', $filtered_requested, true);

    $period_threshold = $period_days * DAY_IN_SECONDS;

    if ($include_speed) {
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

        $current_timestamp = current_time('timestamp');

        $history_entries = array_values(array_filter(
            $history_entries,
            static function ($entry) use ($period_threshold, $current_timestamp) {
                if (!is_array($entry)) {
                    return false;
                }

                if (!isset($entry['server_processing_ms']) || !is_numeric($entry['server_processing_ms'])) {
                    return false;
                }

                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

                if ($timestamp <= 0) {
                    return false;
                }

                if ($period_threshold <= 0) {
                    return true;
                }

                $cutoff = $current_timestamp - $period_threshold;

                return $timestamp >= $cutoff;
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
            'type'      => 'line',
            'labels'    => $speed_labels,
            'datasets'  => [],
            'empty'     => empty($speed_labels),
            'status'    => $processing_status,
            'value'     => $processing_time !== null ? round($processing_time, 2) : null,
            'unit'      => __('ms', 'sitepulse'),
            'reference' => (float) $speed_reference,
        ];

        if (!empty($speed_labels)) {
            $speed_color_map = [
                'status-ok'   => $palette['green'],
                'status-warn' => $palette['amber'],
                'status-bad'  => $palette['red'],
            ];
            $speed_primary_color = isset($speed_color_map[$processing_status]) ? $speed_color_map[$processing_status] : $palette['blue'];

            $speed_chart['datasets'][] = [
                'label'                => __('Processing time', 'sitepulse'),
                'data'                 => $speed_values,
                'borderColor'          => $speed_primary_color,
                'pointBackgroundColor' => $speed_primary_color,
                'pointRadius'          => 3,
                'tension'              => 0.3,
                'fill'                 => false,
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
        $cards_payload['speed'] = [
            'status'     => $processing_status,
            'display'    => $processing_display,
            'value'      => $processing_time !== null ? round($processing_time, 2) : null,
            'thresholds' => [
                'warning'  => $speed_warning_threshold,
                'critical' => $speed_critical_threshold,
            ],
        ];
    }

    if ($include_uptime) {
        $raw_uptime_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $uptime_log = function_exists('sitepulse_normalize_uptime_log')
            ? sitepulse_normalize_uptime_log($raw_uptime_log)
            : (array) $raw_uptime_log;

        $boolean_checks = array_values(array_filter($uptime_log, function ($entry) {
            return is_array($entry) && array_key_exists('status', $entry) && is_bool($entry['status']);
        }));
        $evaluated_checks = count($boolean_checks);
        $up_checks = count(array_filter($boolean_checks, function ($entry) {
            return !empty($entry['status']);
        }));
        $uptime_percentage = $evaluated_checks > 0 ? ($up_checks / $evaluated_checks) * 100 : 100;
        $default_uptime_warning = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0;

        if (function_exists('sitepulse_get_uptime_warning_percentage')) {
            $uptime_warning_threshold = (float) sitepulse_get_uptime_warning_percentage();
        } else {
            $uptime_warning_key = defined('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT') ? SITEPULSE_OPTION_UPTIME_WARNING_PERCENT : 'sitepulse_uptime_warning_percent';
            $stored_threshold = get_option($uptime_warning_key, $default_uptime_warning);
            $uptime_warning_threshold = is_scalar($stored_threshold) ? (float) $stored_threshold : $default_uptime_warning;
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

        $uptime_entries_limit = $period_days * 24;

        if ($uptime_entries_limit < 1) {
            $uptime_entries_limit = 24;
        }

        $uptime_entries = array_slice($uptime_log, -$uptime_entries_limit);

        $uptime_labels = [];
        $uptime_values = [];
        $uptime_colors = [];

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');

        foreach ($uptime_entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $label = $timestamp > 0
                ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                : __('Unknown', 'sitepulse');

            $uptime_labels[] = $label;

            if (isset($entry['status']) && $entry['status'] === true) {
                $uptime_values[] = 100;
                $uptime_colors[] = $palette['green'];
            } elseif (isset($entry['status']) && $entry['status'] === false) {
                $uptime_values[] = 0;
                $uptime_colors[] = $palette['red'];
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
                ],
            ],
            'empty'   => empty($uptime_labels),
            'status'  => $uptime_status,
            'unit'    => '%',
        ];

        $charts_payload['uptime'] = $uptime_chart;
        $cards_payload['uptime'] = [
            'status'     => $uptime_status,
            'percentage' => $uptime_percentage,
        ];
    }

    if ($include_database) {
        global $wpdb;

        $revision_limit = defined('SITEPULSE_REVISIONS_LIMIT') ? (int) SITEPULSE_REVISIONS_LIMIT : 1000;
        $revision_limit = max(1, $revision_limit);

        $revision_count = 0;

        if ($wpdb instanceof wpdb) {
            $revision_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
            );
        }

        $database_status = 'status-ok';

        if ($revision_count > $revision_limit * 1.5) {
            $database_status = 'status-bad';
        } elseif ($revision_count > $revision_limit) {
            $database_status = 'status-warn';
        }

        $database_chart = [
            'type'     => 'doughnut',
            'labels'   => [
                __('Revisions', 'sitepulse'),
                __('Recommended limit', 'sitepulse'),
            ],
            'datasets' => [
                [
                    'data'            => [
                        max(0, (int) $revision_count),
                        max(0, (int) $revision_limit),
                    ],
                    'backgroundColor' => [
                        $palette['blue'],
                        $palette['grey'],
                    ],
                ],
            ],
            'empty'   => false,
            'status'  => $database_status,
        ];

        $charts_payload['database'] = $database_chart;
        $cards_payload['database'] = [
            'status'    => $database_status,
            'revisions' => $revision_count,
            'limit'     => $revision_limit,
        ];
    }

    if ($include_logs) {
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
                    'data'            => array_values($log_counts),
                    'backgroundColor' => [
                        $palette['red'],
                        $palette['amber'],
                        $palette['blue'],
                        $palette['purple'],
                    ],
                    'borderWidth' => 0,
                ],
            ],
            'empty'   => $log_chart_empty,
            'status'  => $log_status_class,
        ];

        $charts_payload['logs'] = $log_chart;
        $cards_payload['logs'] = [
            'status'  => $log_status_class,
            'summary' => $log_summary,
            'counts'  => $log_counts,
        ];
    }

    $module_chart_keys = [
        'speed_analyzer'     => 'speed',
        'uptime_tracker'     => 'uptime',
        'database_optimizer' => 'database',
        'log_analyzer'       => 'logs',
    ];

    foreach ($module_chart_keys as $module_key => $chart_key) {
        if (!in_array($module_key, $filtered_requested, true)) {
            unset($charts_payload[$chart_key]);
            unset($cards_payload[$chart_key]);
        }
    }

    return [
        'charts'         => $charts_payload,
        'cards'          => $cards_payload,
        'palette'        => $palette,
        'status_labels'  => $status_labels,
        'active_modules' => $active_modules,
        'modules'        => $filtered_requested,
        'period'         => $period_days,
    ];
}

add_action('rest_api_init', 'sitepulse_register_dashboard_rest_routes');

/**
 * Registers REST API routes for the SitePulse dashboard.
 */
function sitepulse_register_dashboard_rest_routes() {
    register_rest_route(
        'sitepulse/v1',
        '/dashboard-data',
        [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => 'sitepulse_handle_dashboard_data_request',
            'permission_callback' => static function () {
                return current_user_can(sitepulse_get_capability());
            },
            'args'                => [
                'period'  => [
                    'validate_callback' => static function ($value) {
                        return is_numeric($value) || $value === null;
                    },
                ],
                'modules' => [
                    'validate_callback' => static function ($value) {
                        return is_array($value) || is_string($value) || $value === null;
                    },
                ],
            ],
        ]
    );
}

/**
 * Handles REST requests for dashboard data.
 *
 * @param WP_REST_Request $request Request instance.
 *
 * @return WP_REST_Response
 */
function sitepulse_handle_dashboard_data_request(WP_REST_Request $request) {
    $period_param = $request->get_param('period');
    if (is_array($period_param)) {
        $period_param = reset($period_param);
    }

    $period = (int) $period_param;

    if ($period < 1) {
        $period = 30;
    }

    $modules_param = $request->get_param('modules');
    $modules = [];

    if (is_array($modules_param)) {
        $modules = $modules_param;
    } elseif (is_string($modules_param) && $modules_param !== '') {
        $modules = explode(',', $modules_param);
    }

    $data = sitepulse_get_dashboard_data([
        'period'  => $period,
        'modules' => $modules,
    ]);

    if (!is_array($data)) {
        $data = [];
    }

    $response = [
        'charts'        => isset($data['charts']) && is_array($data['charts']) ? $data['charts'] : [],
        'cards'         => isset($data['cards']) && is_array($data['cards']) ? $data['cards'] : [],
        'modules'       => isset($data['modules']) && is_array($data['modules']) ? array_values($data['modules']) : [],
        'period'        => isset($data['period']) ? (int) $data['period'] : $period,
        'statusLabels'  => isset($data['status_labels']) && is_array($data['status_labels']) ? $data['status_labels'] : [],
    ];

    return rest_ensure_response($response);
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

    $dashboard_data = sitepulse_get_dashboard_data([
        'period'  => 30,
        'modules' => $active_modules,
    ]);

    $charts_payload = isset($dashboard_data['charts']) && is_array($dashboard_data['charts']) ? $dashboard_data['charts'] : [];
    $cards_payload = isset($dashboard_data['cards']) && is_array($dashboard_data['cards']) ? $dashboard_data['cards'] : [];
    $palette = isset($dashboard_data['palette']) && is_array($dashboard_data['palette']) ? $dashboard_data['palette'] : [
        'green'    => '#0b6d2a',
        'amber'    => '#8a6100',
        'red'      => '#a0141e',
        'deep_red' => '#7f1018',
        'blue'     => '#2196F3',
        'grey'     => '#E0E0E0',
        'purple'   => '#9C27B0',
    ];
    $status_labels = isset($dashboard_data['status_labels']) && is_array($dashboard_data['status_labels']) ? $dashboard_data['status_labels'] : [
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

    $get_status_meta = static function ($status) use ($status_labels) {
        if (isset($status_labels[$status])) {
            return $status_labels[$status];
        }

        return $status_labels['status-warn'];
    };

    $module_chart_keys = [
        'speed_analyzer'     => 'speed',
        'uptime_tracker'     => 'uptime',
        'database_optimizer' => 'database',
        'log_analyzer'       => 'logs',
    ];

    $module_labels = [
        'speed_analyzer'     => __('Speed', 'sitepulse'),
        'uptime_tracker'     => __('Uptime', 'sitepulse'),
        'database_optimizer' => __('Database', 'sitepulse'),
        'log_analyzer'       => __('Error log', 'sitepulse'),
    ];

    $speed_chart = isset($charts_payload['speed']) ? $charts_payload['speed'] : null;
    $uptime_chart = isset($charts_payload['uptime']) ? $charts_payload['uptime'] : null;
    $database_chart = isset($charts_payload['database']) ? $charts_payload['database'] : null;
    $logs_chart = isset($charts_payload['logs']) ? $charts_payload['logs'] : null;

    $speed_card = isset($cards_payload['speed']) ? $cards_payload['speed'] : null;
    $uptime_card = isset($cards_payload['uptime']) ? $cards_payload['uptime'] : null;
    $database_card = isset($cards_payload['database']) ? $cards_payload['database'] : null;
    $logs_card = isset($cards_payload['logs']) ? $cards_payload['logs'] : null;

    $current_period = isset($dashboard_data['period']) ? (int) $dashboard_data['period'] : 30;
    $selected_modules = isset($dashboard_data['modules']) && is_array($dashboard_data['modules']) ? $dashboard_data['modules'] : $active_modules;

    $speed_warning_threshold = isset($speed_card['thresholds']['warning']) ? (int) $speed_card['thresholds']['warning'] : 200;
    $speed_critical_threshold = isset($speed_card['thresholds']['critical']) ? (int) $speed_card['thresholds']['critical'] : 500;

    $charts_for_localization = empty($charts_payload) ? new stdClass() : $charts_payload;
    $cards_for_localization = empty($cards_payload) ? new stdClass() : $cards_payload;

    $active_module_map = array_fill_keys($active_modules, true);
    $localized_module_labels = array_intersect_key($module_labels, $active_module_map);

    $localization_payload = [
        'charts'        => $charts_for_localization,
        'cards'         => $cards_for_localization,
        'strings'       => [
            'noData'               => __('Not enough data to render this chart yet.', 'sitepulse'),
            'uptimeTooltipUp'      => __('Site operational', 'sitepulse'),
            'uptimeTooltipDown'    => __('Site unavailable', 'sitepulse'),
            'uptimeAxisLabel'      => __('Availability (%)', 'sitepulse'),
            'speedTooltipLabel'    => __('Measured time', 'sitepulse'),
            'speedTrendLabel'      => __('Processing time', 'sitepulse'),
            'speedAxisLabel'       => __('Processing time (ms)', 'sitepulse'),
            'speedBudgetLabel'     => __('Performance budget', 'sitepulse'),
            'speedOverBudgetLabel' => __('Over budget', 'sitepulse'),
            'speedDescriptionTemplate' => __('Des temps inférieurs à %1$d ms indiquent une excellente réponse PHP. Au-delà de %2$d ms, envisagez d’auditer vos plugins ou votre hébergement.', 'sitepulse'),
            'revisionsTooltip'     => __('Revisions', 'sitepulse'),
            'logEventsLabel'       => __('Events', 'sitepulse'),
            'refreshError'         => __('Unable to refresh dashboard data. Please try again.', 'sitepulse'),
            'moduleFilterMinimum'  => __('At least one module must remain selected.', 'sitepulse'),
        ],
        'restUrl'       => esc_url_raw(rest_url('sitepulse/v1/dashboard-data')),
        'restNonce'     => wp_create_nonce('wp_rest'),
        'initialState'  => [
            'period'  => $current_period,
            'modules' => array_values($selected_modules),
        ],
        'moduleLabels'  => $localized_module_labels,
        'moduleChartMap'=> $module_chart_keys,
        'statusLabels'  => $status_labels,
        'chartIds'      => [
            'speed'    => 'sitepulse-speed-chart',
            'uptime'   => 'sitepulse-uptime-chart',
            'database' => 'sitepulse-database-chart',
            'logs'     => 'sitepulse-log-chart',
        ],
        'activeModules' => array_values($active_modules),
    ];

    if (wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        wp_localize_script('sitepulse-dashboard-charts', 'SitePulseDashboardData', $localization_payload);
    }

    $loading_text = __('Loading dashboard data…', 'sitepulse');

    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-dashboard"></span> <?php esc_html_e('SitePulse Dashboard', 'sitepulse'); ?></h1>
        <p><?php esc_html_e("A real-time overview of your site's performance and health.", 'sitepulse'); ?></p>

        <div class="sitepulse-dashboard-actions" data-current-period="<?php echo esc_attr($current_period); ?>">
            <div class="sitepulse-action-group">
                <button type="button" class="button button-secondary sitepulse-refresh-button">
                    <?php esc_html_e('Refresh', 'sitepulse'); ?>
                </button>
                <span class="sitepulse-loading-spinner" aria-hidden="true"></span>
                <span class="screen-reader-text sitepulse-loading-text" hidden><?php echo esc_html($loading_text); ?></span>
            </div>
            <div class="sitepulse-action-group" role="group" aria-label="<?php esc_attr_e('Select period', 'sitepulse'); ?>">
                <span class="sitepulse-action-label"><?php esc_html_e('Period', 'sitepulse'); ?></span>
                <div class="sitepulse-period-buttons">
                    <button type="button" class="button sitepulse-period-button<?php echo 7 === $current_period ? ' is-active' : ''; ?>" data-period="7"><?php esc_html_e('7 days', 'sitepulse'); ?></button>
                    <button type="button" class="button sitepulse-period-button<?php echo 7 !== $current_period ? ' is-active' : ''; ?>" data-period="30"><?php esc_html_e('30 days', 'sitepulse'); ?></button>
                </div>
            </div>
            <div class="sitepulse-action-group sitepulse-action-group--modules">
                <span class="sitepulse-action-label"><?php esc_html_e('Modules', 'sitepulse'); ?></span>
                <div class="sitepulse-module-filters">
                    <?php foreach ($localized_module_labels as $module_key => $module_label) : ?>
                        <label class="sitepulse-module-filter">
                            <input type="checkbox" value="<?php echo esc_attr($module_key); ?>" <?php checked(in_array($module_key, $selected_modules, true)); ?> />
                            <span><?php echo esc_html($module_label); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="sitepulse-grid">
            <?php if ($is_speed_enabled && $speed_card !== null && is_array($speed_card)): ?>
                <?php
                    $speed_summary_html = is_array($speed_chart) ? sitepulse_render_chart_summary('sitepulse-speed-chart', $speed_chart) : '';
                    $speed_summary_id = sitepulse_get_chart_summary_id('sitepulse-speed-chart');
                    $speed_canvas_describedby = ['sitepulse-speed-description'];

                    if ('' !== $speed_summary_html) {
                        $speed_canvas_describedby[] = $speed_summary_id;
                    }

                    $speed_status_meta = $get_status_meta($speed_card['status']);
                ?>
                <div class="sitepulse-card" data-card="speed" data-module="speed_analyzer">
                    <div class="sitepulse-card-header">
                        <h2><span class="dashicons dashicons-performance"></span> <?php esc_html_e('Speed', 'sitepulse'); ?></h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-speed')); ?>" class="button button-secondary"><?php esc_html_e('Details', 'sitepulse'); ?></a>
                    </div>
                    <p class="sitepulse-card-subtitle"><?php esc_html_e('Backend PHP processing time captured during recent scans.', 'sitepulse'); ?></p>
                    <div class="sitepulse-chart-container">
                        <canvas id="sitepulse-speed-chart" aria-describedby="<?php echo esc_attr(implode(' ', $speed_canvas_describedby)); ?>"></canvas>
                        <?php echo $speed_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <p class="sitepulse-metric">
                        <span class="status-badge <?php echo esc_attr($speed_card['status']); ?> js-sitepulse-status-badge" aria-hidden="true">
                            <span class="status-icon js-sitepulse-status-icon"><?php echo esc_html($speed_status_meta['icon']); ?></span>
                            <span class="status-text js-sitepulse-status-text"><?php echo esc_html($speed_status_meta['label']); ?></span>
                        </span>
                        <span class="screen-reader-text js-sitepulse-status-sr"><?php echo esc_html($speed_status_meta['sr']); ?></span>
                        <span class="sitepulse-metric-value">
                            <span class="js-sitepulse-metric-value"><?php echo esc_html($speed_card['display']); ?></span>
                        </span>
                    </p>
                    <p id="sitepulse-speed-description" class="description"><?php printf(
                        esc_html__('Des temps inférieurs à %1$d ms indiquent une excellente réponse PHP. Au-delà de %2$d ms, envisagez d’auditer vos plugins ou votre hébergement.', 'sitepulse'),
                        (int) $speed_warning_threshold,
                        (int) $speed_critical_threshold
                    ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($is_uptime_enabled && $uptime_card !== null && is_array($uptime_card)): ?>
                <?php
                    $uptime_summary_html = is_array($uptime_chart) ? sitepulse_render_chart_summary('sitepulse-uptime-chart', $uptime_chart) : '';
                    $uptime_summary_id = sitepulse_get_chart_summary_id('sitepulse-uptime-chart');
                    $uptime_canvas_describedby = ['sitepulse-uptime-description'];

                    if ('' !== $uptime_summary_html) {
                        $uptime_canvas_describedby[] = $uptime_summary_id;
                    }

                    $uptime_status_meta = $get_status_meta($uptime_card['status']);
                ?>
                <div class="sitepulse-card" data-card="uptime" data-module="uptime_tracker">
                    <div class="sitepulse-card-header">
                        <h2><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e('Uptime', 'sitepulse'); ?></h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-uptime')); ?>" class="button button-secondary"><?php esc_html_e('Details', 'sitepulse'); ?></a>
                    </div>
                    <p class="sitepulse-card-subtitle"><?php esc_html_e('Availability for the selected monitoring window.', 'sitepulse'); ?></p>
                    <div class="sitepulse-chart-container">
                        <canvas id="sitepulse-uptime-chart" aria-describedby="<?php echo esc_attr(implode(' ', $uptime_canvas_describedby)); ?>"></canvas>
                        <?php echo $uptime_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <p class="sitepulse-metric">
                        <span class="status-badge <?php echo esc_attr($uptime_card['status']); ?> js-sitepulse-status-badge" aria-hidden="true">
                            <span class="status-icon js-sitepulse-status-icon"><?php echo esc_html($uptime_status_meta['icon']); ?></span>
                            <span class="status-text js-sitepulse-status-text"><?php echo esc_html($uptime_status_meta['label']); ?></span>
                        </span>
                        <span class="screen-reader-text js-sitepulse-status-sr"><?php echo esc_html($uptime_status_meta['sr']); ?></span>
                        <span class="sitepulse-metric-value">
                            <span class="js-sitepulse-metric-value"><?php echo esc_html(round($uptime_card['percentage'], 2)); ?></span>
                            <span class="sitepulse-metric-unit"><?php esc_html_e('%', 'sitepulse'); ?></span>
                        </span>
                    </p>
                    <p id="sitepulse-uptime-description" class="description"><?php esc_html_e('Each bar shows whether the site responded during the scheduled availability probe.', 'sitepulse'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($is_database_enabled && $database_card !== null && is_array($database_card)): ?>
                <?php
                    $database_summary_html = is_array($database_chart) ? sitepulse_render_chart_summary('sitepulse-database-chart', $database_chart) : '';
                    $database_summary_id = sitepulse_get_chart_summary_id('sitepulse-database-chart');
                    $database_canvas_describedby = ['sitepulse-database-description'];

                    if ('' !== $database_summary_html) {
                        $database_canvas_describedby[] = $database_summary_id;
                    }

                    $database_status_meta = $get_status_meta($database_card['status']);
                ?>
                <div class="sitepulse-card" data-card="database" data-module="database_optimizer">
                    <div class="sitepulse-card-header">
                        <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e('Database Health', 'sitepulse'); ?></h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-db')); ?>" class="button button-secondary"><?php esc_html_e('Optimize', 'sitepulse'); ?></a>
                    </div>
                    <p class="sitepulse-card-subtitle"><?php esc_html_e('Post revision volume compared to the recommended limit.', 'sitepulse'); ?></p>
                    <div class="sitepulse-chart-container">
                        <canvas id="sitepulse-database-chart" aria-describedby="<?php echo esc_attr(implode(' ', $database_canvas_describedby)); ?>"></canvas>
                        <?php echo $database_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <p class="sitepulse-metric">
                        <span class="status-badge <?php echo esc_attr($database_card['status']); ?> js-sitepulse-status-badge" aria-hidden="true">
                            <span class="status-icon js-sitepulse-status-icon"><?php echo esc_html($database_status_meta['icon']); ?></span>
                            <span class="status-text js-sitepulse-status-text"><?php echo esc_html($database_status_meta['label']); ?></span>
                        </span>
                        <span class="screen-reader-text js-sitepulse-status-sr"><?php echo esc_html($database_status_meta['sr']); ?></span>
                        <span class="sitepulse-metric-value">
                            <span class="js-sitepulse-metric-value"><?php echo esc_html(number_format_i18n($database_card['revisions'])); ?></span>
                            <span class="sitepulse-metric-unit"><?php esc_html_e('revisions', 'sitepulse'); ?></span>
                        </span>
                    </p>
                    <p id="sitepulse-database-description" class="description"><?php printf(esc_html__('Keep revisions under %d to avoid bloating the posts table. Cleaning them is safe and reversible with backups.', 'sitepulse'), (int) $database_card['limit']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($is_logs_enabled && $logs_card !== null && is_array($logs_card)): ?>
                <?php $logs_status_meta = $get_status_meta($logs_card['status']); ?>
                <div class="sitepulse-card" data-card="logs" data-module="log_analyzer">
                    <div class="sitepulse-card-header">
                        <h2><span class="dashicons dashicons-hammer"></span> <?php esc_html_e('Error Log', 'sitepulse'); ?></h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-logs')); ?>" class="button button-secondary"><?php esc_html_e('Analyze', 'sitepulse'); ?></a>
                    </div>
                    <p class="sitepulse-card-subtitle"><?php esc_html_e('Breakdown of the most recent entries in the WordPress debug log.', 'sitepulse'); ?></p>
                    <div class="sitepulse-chart-container">
                        <canvas id="sitepulse-log-chart" aria-describedby="sitepulse-log-description"></canvas>
                    </div>
                    <p class="sitepulse-metric">
                        <span class="status-badge <?php echo esc_attr($logs_card['status']); ?> js-sitepulse-status-badge" aria-hidden="true">
                            <span class="status-icon js-sitepulse-status-icon"><?php echo esc_html($logs_status_meta['icon']); ?></span>
                            <span class="status-text js-sitepulse-status-text"><?php echo esc_html($logs_status_meta['label']); ?></span>
                        </span>
                        <span class="screen-reader-text js-sitepulse-status-sr"><?php echo esc_html($logs_status_meta['sr']); ?></span>
                        <span class="sitepulse-metric-value js-sitepulse-log-summary"><?php echo esc_html($logs_card['summary']); ?></span>
                    </p>
                    <ul class="sitepulse-legend">
                        <li>
                            <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['red']); ?>;"></span><?php esc_html_e('Fatal errors', 'sitepulse'); ?></span>
                            <span class="value js-sitepulse-log-count" data-log-type="fatal"><?php echo esc_html(number_format_i18n($logs_card['counts']['fatal'])); ?></span>
                        </li>
                        <li>
                            <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['amber']); ?>;"></span><?php esc_html_e('Warnings', 'sitepulse'); ?></span>
                            <span class="value js-sitepulse-log-count" data-log-type="warning"><?php echo esc_html(number_format_i18n($logs_card['counts']['warning'])); ?></span>
                        </li>
                        <li>
                            <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['blue']); ?>;"></span><?php esc_html_e('Notices', 'sitepulse'); ?></span>
                            <span class="value js-sitepulse-log-count" data-log-type="notice"><?php echo esc_html(number_format_i18n($logs_card['counts']['notice'])); ?></span>
                        </li>
                        <li>
                            <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['purple']); ?>;"></span><?php esc_html_e('Deprecated notices', 'sitepulse'); ?></span>
                            <span class="value js-sitepulse-log-count" data-log-type="deprecated"><?php echo esc_html(number_format_i18n($logs_card['counts']['deprecated'])); ?></span>
                        </li>
                    </ul>
                    <p id="sitepulse-log-description" class="description"><?php esc_html_e('Use the analyzer to inspect full stack traces and silence recurring issues.', 'sitepulse'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

