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
add_action('wp_ajax_sitepulse_save_dashboard_preferences', 'sitepulse_save_dashboard_preferences');

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
        'sitepulse-dashboard-nav',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-nav.js',
        [],
        SITEPULSE_VERSION,
        true
    );

    wp_enqueue_script('sitepulse-dashboard-nav');

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

    wp_register_script(
        'sitepulse-dashboard-preferences',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-preferences.js',
        ['jquery', 'jquery-ui-sortable'],
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
 * Returns the identifiers of the dashboard cards that can be customised.
 *
 * @return string[]
 */
function sitepulse_get_dashboard_card_keys() {
    return ['speed', 'uptime', 'database', 'logs'];
}

/**
 * Provides the default dashboard preferences for the supplied cards.
 *
 * @param string[]|null $allowed_cards Optional subset of cards to include.
 *
 * @return array{
 *     order: string[],
 *     visibility: array<string,bool>,
 *     sizes: array<string,string>
 * }
 */
function sitepulse_get_dashboard_default_preferences($allowed_cards = null) {
    $card_keys = sitepulse_get_dashboard_card_keys();

    if (is_array($allowed_cards) && !empty($allowed_cards)) {
        $allowed_cards = array_values(array_filter(array_map('strval', $allowed_cards)));

        if (!empty($allowed_cards)) {
            $card_keys = array_values(array_unique(array_merge(
                array_intersect($card_keys, $allowed_cards),
                $allowed_cards
            )));
        }
    }

    $order = $card_keys;
    $visibility = [];
    $sizes = [];

    foreach ($card_keys as $key) {
        $visibility[$key] = true;
        $sizes[$key] = 'medium';
    }

    return [
        'order'      => $order,
        'visibility' => $visibility,
        'sizes'      => $sizes,
    ];
}

/**
 * Sanitizes a set of dashboard preferences.
 *
 * @param array            $raw_preferences Potentially unsanitized preferences.
 * @param string[]|null    $allowed_cards   Optional subset of cards to accept.
 *
 * @return array{
 *     order: string[],
 *     visibility: array<string,bool>,
 *     sizes: array<string,string>
 * }
 */
function sitepulse_sanitize_dashboard_preferences($raw_preferences, $allowed_cards = null) {
    $defaults = sitepulse_get_dashboard_default_preferences($allowed_cards);
    $allowed_cards = $defaults['order'];
    $allowed_sizes = ['small', 'medium', 'large'];

    $order = [];

    if (isset($raw_preferences['order']) && is_array($raw_preferences['order'])) {
        foreach ($raw_preferences['order'] as $card_key) {
            $card_key = sanitize_key((string) $card_key);

            if ($card_key !== '' && in_array($card_key, $allowed_cards, true) && !in_array($card_key, $order, true)) {
                $order[] = $card_key;
            }
        }
    }

    foreach ($allowed_cards as $card_key) {
        if (!in_array($card_key, $order, true)) {
            $order[] = $card_key;
        }
    }

    $visibility = [];

    if (isset($raw_preferences['visibility']) && is_array($raw_preferences['visibility'])) {
        foreach ($allowed_cards as $card_key) {
            if (array_key_exists($card_key, $raw_preferences['visibility'])) {
                $visibility[$card_key] = filter_var(
                    $raw_preferences['visibility'][$card_key],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );

                if ($visibility[$card_key] === null) {
                    $visibility[$card_key] = $defaults['visibility'][$card_key];
                }

                continue;
            }

            $visibility[$card_key] = $defaults['visibility'][$card_key];
        }
    } else {
        $visibility = $defaults['visibility'];
    }

    $sizes = [];

    if (isset($raw_preferences['sizes']) && is_array($raw_preferences['sizes'])) {
        foreach ($allowed_cards as $card_key) {
            if (array_key_exists($card_key, $raw_preferences['sizes'])) {
                $size_value = strtolower((string) $raw_preferences['sizes'][$card_key]);

                if (!in_array($size_value, $allowed_sizes, true)) {
                    $size_value = $defaults['sizes'][$card_key];
                }

                $sizes[$card_key] = $size_value;
                continue;
            }

            $sizes[$card_key] = $defaults['sizes'][$card_key];
        }
    } else {
        $sizes = $defaults['sizes'];
    }

    return [
        'order'      => $order,
        'visibility' => $visibility,
        'sizes'      => $sizes,
    ];
}

/**
 * Returns the saved dashboard preferences for a given user.
 *
 * @param int              $user_id       Optional user identifier.
 * @param string[]|null    $allowed_cards Optional subset of cards to accept.
 *
 * @return array{
 *     order: string[],
 *     visibility: array<string,bool>,
 *     sizes: array<string,string>
 * }
 */
function sitepulse_get_dashboard_preferences($user_id = 0, $allowed_cards = null) {
    if (!is_int($user_id) || $user_id <= 0) {
        $user_id = get_current_user_id();
    }

    $stored_preferences = [];

    if ($user_id > 0) {
        $stored_preferences = get_user_meta($user_id, 'sitepulse_dashboard_preferences', true);

        if (!is_array($stored_preferences)) {
            $stored_preferences = [];
        }
    }

    return sitepulse_sanitize_dashboard_preferences($stored_preferences, $allowed_cards);
}

/**
 * Persists dashboard preferences for the supplied user.
 *
 * @param int              $user_id       User identifier.
 * @param array            $preferences   Preferences to store.
 * @param string[]|null    $allowed_cards Optional subset of cards to accept.
 *
 * @return bool True on success, false otherwise.
 */
function sitepulse_update_dashboard_preferences($user_id, $preferences, $allowed_cards = null) {
    $user_id = (int) $user_id;

    if ($user_id <= 0) {
        return false;
    }

    $sanitized = sitepulse_sanitize_dashboard_preferences($preferences, $allowed_cards);

    return (bool) update_user_meta($user_id, 'sitepulse_dashboard_preferences', $sanitized);
}

/**
 * Handles AJAX requests to store dashboard preferences for the current user.
 */
function sitepulse_save_dashboard_preferences() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error(['message' => __('Vous n’avez pas les permissions nécessaires pour modifier ces préférences.', 'sitepulse')], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field((string) wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, 'sitepulse_dashboard_preferences')) {
        wp_send_json_error(['message' => __('Jeton de sécurité invalide. Merci de recharger la page.', 'sitepulse')], 400);
    }

    $raw_preferences = [
        'order'      => isset($_POST['order']) ? (array) wp_unslash($_POST['order']) : [],
        'visibility' => isset($_POST['visibility']) ? (array) wp_unslash($_POST['visibility']) : [],
        'sizes'      => isset($_POST['sizes']) ? (array) wp_unslash($_POST['sizes']) : [],
    ];

    $allowed_cards = sitepulse_get_dashboard_card_keys();
    $preferences = sitepulse_sanitize_dashboard_preferences($raw_preferences, $allowed_cards);
    $user_id = get_current_user_id();

    if (!sitepulse_update_dashboard_preferences($user_id, $preferences, $allowed_cards)) {
        wp_send_json_error(['message' => __('Impossible d’enregistrer les préférences pour le moment.', 'sitepulse')], 500);
    }

    wp_send_json_success(['preferences' => $preferences]);
}

/**
 * Builds a reusable context describing the dashboard cards and charts.
 *
 * @return array
 */
function sitepulse_get_dashboard_preview_context() {
    static $context = null;

    if (null !== $context) {
        return $context;
    }

    $active_modules = array_map('strval', (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []));
    $active_modules = array_values(array_filter($active_modules, static function ($module) {
        return $module !== '';
    }));

    global $wpdb;

    $is_speed_enabled = in_array('speed_analyzer', $active_modules, true);
    $is_uptime_enabled = in_array('uptime_tracker', $active_modules, true);
    $is_database_enabled = in_array('database_optimizer', $active_modules, true);
    $is_logs_enabled = in_array('log_analyzer', $active_modules, true);

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

    $context = [
        'active_modules' => $active_modules,
        'palette'        => $palette,
        'status_labels'  => $status_labels,
        'modules'        => [
            'speed' => [
                'enabled'     => $is_speed_enabled,
                'card'        => null,
                'chart'       => null,
                'thresholds'  => [
                    'warning'  => defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200,
                    'critical' => defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500,
                ],
            ],
            'uptime' => [
                'enabled' => $is_uptime_enabled,
                'card'    => null,
                'chart'   => null,
            ],
            'database' => [
                'enabled' => $is_database_enabled,
                'card'    => null,
                'chart'   => null,
            ],
            'logs' => [
                'enabled' => $is_logs_enabled,
                'card'    => null,
                'chart'   => null,
            ],
        ],
        'charts_payload' => [],
    ];

    $charts_payload = [];

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
        $context['modules']['speed']['card'] = [
            'status'  => $processing_status,
            'display' => $processing_display,
        ];
        $context['modules']['speed']['chart'] = $speed_chart;
        $context['modules']['speed']['thresholds'] = [
            'warning'  => $speed_warning_threshold,
            'critical' => $speed_critical_threshold,
        ];
    }

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
        $context['modules']['uptime']['card'] = [
            'status'     => $uptime_status,
            'percentage' => $uptime_percentage,
        ];
        $context['modules']['uptime']['chart'] = $uptime_chart;
    }

    if ($is_database_enabled) {
        $revisions = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
        $default_revision_limit = defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? (int) SITEPULSE_DEFAULT_REVISION_LIMIT : 100;

        if (function_exists('sitepulse_get_revision_limit')) {
            $revision_limit = (int) sitepulse_get_revision_limit();
        } else {
            $revision_option_key = defined('SITEPULSE_OPTION_REVISION_LIMIT') ? SITEPULSE_OPTION_REVISION_LIMIT : 'sitepulse_revision_limit';
            $stored_limit = get_option($revision_option_key, $default_revision_limit);
            $revision_limit = is_scalar($stored_limit) ? (int) $stored_limit : $default_revision_limit;
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
        $context['modules']['database']['card'] = [
            'status'    => $db_status,
            'revisions' => $revisions,
            'limit'     => $revision_limit,
        ];
        $context['modules']['database']['chart'] = $database_chart;
    }

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
                    'borderWidth' => 0,
                ],
            ],
            'empty'   => $log_chart_empty,
            'status'  => $log_status_class,
        ];

        $charts_payload['logs'] = $log_chart;
        $context['modules']['logs']['card'] = [
            'status'  => $log_status_class,
            'summary' => $log_summary,
            'counts'  => $log_counts,
        ];
        $context['modules']['logs']['chart'] = $log_chart;
    }

    $get_status_meta = static function ($status) use ($status_labels, $default_status_labels) {
        if (isset($status_labels[$status])) {
            return $status_labels[$status];
        }

        if (isset($status_labels['status-warn'])) {
            return $status_labels['status-warn'];
        }

        return $default_status_labels['status-warn'];
    };

    $module_chart_keys = [
        'speed_analyzer'     => 'speed',
        'uptime_tracker'     => 'uptime',
        'database_optimizer' => 'database',
        'log_analyzer'       => 'logs',
    ];

    foreach ($module_chart_keys as $module_key => $chart_key) {
        if (!in_array($module_key, $active_modules, true) || !isset($charts_payload[$chart_key])) {
            unset($charts_payload[$chart_key]);
        }
    }

    $context['charts_payload'] = $charts_payload;

    return $context;
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

    if (!wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        sitepulse_custom_dashboard_enqueue_assets('toplevel_page_sitepulse-dashboard');
    }

    if (wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        wp_enqueue_script('sitepulse-chartjs');
        wp_enqueue_script('sitepulse-dashboard-charts');
    }

    $default_palette = [
        'green'    => '#0b6d2a',
        'amber'    => '#8a6100',
        'red'      => '#a0141e',
        'deep_red' => '#7f1018',
        'blue'     => '#2196F3',
        'grey'     => '#E0E0E0',
        'purple'   => '#9C27B0',
    ];

    $default_status_labels = [
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
    $speed_warning_threshold = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
    $speed_critical_threshold = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;
    $is_speed_enabled = false;
    $is_uptime_enabled = false;
    $is_database_enabled = false;
    $is_logs_enabled = false;
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

        $is_speed_enabled = !empty($speed_data['enabled']);
        $is_uptime_enabled = !empty($uptime_data['enabled']);
        $is_database_enabled = !empty($database_data['enabled']);
        $is_logs_enabled = !empty($logs_data['enabled']);

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
                    'borderWidth' => 0,
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

    }

    $module_chart_keys = [
        'speed_analyzer'     => 'speed',
        'uptime_tracker'     => 'uptime',
        'database_optimizer' => 'database',
        'log_analyzer'       => 'logs',
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
        ],
    ];

    if (wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        wp_localize_script('sitepulse-dashboard-charts', 'SitePulseDashboardData', $localization_payload);
    }

    $module_page_definitions = [
        'custom_dashboards'     => [
            'label' => __('Dashboard', 'sitepulse'),
            'page'  => 'sitepulse-dashboard',
            'icon'  => 'dashicons-dashboard',
        ],
        'speed_analyzer'        => [
            'label' => __('Speed', 'sitepulse'),
            'page'  => 'sitepulse-speed',
            'icon'  => 'dashicons-performance',
        ],
        'uptime_tracker'        => [
            'label' => __('Uptime', 'sitepulse'),
            'page'  => 'sitepulse-uptime',
            'icon'  => 'dashicons-chart-bar',
        ],
        'database_optimizer'    => [
            'label' => __('Database', 'sitepulse'),
            'page'  => 'sitepulse-db',
            'icon'  => 'dashicons-database',
        ],
        'log_analyzer'          => [
            'label' => __('Logs', 'sitepulse'),
            'page'  => 'sitepulse-logs',
            'icon'  => 'dashicons-hammer',
        ],
        'resource_monitor'      => [
            'label' => __('Resources', 'sitepulse'),
            'page'  => 'sitepulse-resources',
            'icon'  => 'dashicons-chart-area',
        ],
        'plugin_impact_scanner' => [
            'label' => __('Plugins', 'sitepulse'),
            'page'  => 'sitepulse-plugins',
            'icon'  => 'dashicons-admin-plugins',
        ],
        'maintenance_advisor'   => [
            'label' => __('Maintenance', 'sitepulse'),
            'page'  => 'sitepulse-maintenance',
            'icon'  => 'dashicons-admin-tools',
        ],
        'ai_insights'           => [
            'label' => __('AI Insights', 'sitepulse'),
            'page'  => 'sitepulse-ai',
            'icon'  => 'dashicons-lightbulb',
        ],
    ];

    $current_page = isset($_GET['page']) ? sanitize_title((string) wp_unslash($_GET['page'])) : 'sitepulse-dashboard';

    if ($current_page === '') {
        $current_page = 'sitepulse-dashboard';
    }

    $user_can_manage_modules = current_user_can(sitepulse_get_capability());
    $module_navigation = [];

    foreach ($module_page_definitions as $module_key => $definition) {
        $page_slug = isset($definition['page']) ? sanitize_title((string) $definition['page']) : '';

        if ($page_slug === '') {
            continue;
        }

        $is_module_active = ('custom_dashboards' === $module_key)
            ? true
            : in_array($module_key, $active_modules, true);

        if (!$is_module_active || !$user_can_manage_modules) {
            continue;
        }

        $module_navigation[] = [
            'label'   => isset($definition['label']) ? $definition['label'] : '',
            'icon'    => isset($definition['icon']) ? $definition['icon'] : '',
            'url'     => admin_url('admin.php?page=' . $page_slug),
            'slug'    => $page_slug,
            'current' => ($current_page === $page_slug),
        ];
    }

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
            'strings'      => [
                'panelDescription' => __('Réorganisez les cartes en les faisant glisser et choisissez celles à afficher.', 'sitepulse'),
                'toggleLabel'      => __('Afficher', 'sitepulse'),
                'sizeLabel'        => __('Taille', 'sitepulse'),
                'saveSuccess'      => __('Préférences enregistrées.', 'sitepulse'),
                'saveError'        => __('Impossible d’enregistrer les préférences.', 'sitepulse'),
                'moduleDisabled'   => __('Module requis pour afficher cette carte.', 'sitepulse'),
                'changesSaved'     => __('Les préférences du tableau de bord ont été mises à jour.', 'sitepulse'),
            ],
        ]);
        wp_enqueue_script('sitepulse-dashboard-preferences');
    }

    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-dashboard"></span> <?php esc_html_e('SitePulse Dashboard', 'sitepulse'); ?></h1>
        <p><?php esc_html_e("A real-time overview of your site's performance and health.", 'sitepulse'); ?></p>

        <?php if (!empty($module_navigation)) : ?>
            <?php
            $nav_list_id = function_exists('wp_unique_id')
                ? wp_unique_id('sitepulse-module-nav-list-')
                : 'sitepulse-module-nav-list-' . uniqid();

            $nav_select_id = function_exists('wp_unique_id')
                ? wp_unique_id('sitepulse-module-nav-select-')
                : 'sitepulse-module-nav-select-' . uniqid();
            ?>
            <nav class="sitepulse-module-nav" aria-label="<?php esc_attr_e('SitePulse sections', 'sitepulse'); ?>">
                <form class="sitepulse-module-nav__mobile-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                    <label class="sitepulse-module-nav__mobile-label" for="<?php echo esc_attr($nav_select_id); ?>"><?php esc_html_e('Go to section', 'sitepulse'); ?></label>
                    <div class="sitepulse-module-nav__mobile-controls">
                        <select
                            class="sitepulse-module-nav__select"
                            id="<?php echo esc_attr($nav_select_id); ?>"
                            name="page"
                            data-sitepulse-nav-select
                        >
                            <?php foreach ($module_navigation as $item) : ?>
                                <option value="<?php echo esc_attr($item['slug']); ?>"<?php selected(!empty($item['current'])); ?>><?php echo esc_html($item['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button sitepulse-module-nav__select-submit"><?php esc_html_e('View', 'sitepulse'); ?></button>
                    </div>
                </form>
                <div class="sitepulse-module-nav__scroll">
                    <button
                        type="button"
                        class="sitepulse-module-nav__scroll-button sitepulse-module-nav__scroll-button--prev"
                        data-sitepulse-nav-scroll="prev"
                        aria-controls="<?php echo esc_attr($nav_list_id); ?>"
                        aria-label="<?php esc_attr_e('Scroll navigation left', 'sitepulse'); ?>"
                        disabled
                    >
                        <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                    </button>
                    <div class="sitepulse-module-nav__scroll-viewport" data-sitepulse-nav-viewport>
                        <ul class="sitepulse-module-nav__list" id="<?php echo esc_attr($nav_list_id); ?>">
                            <?php foreach ($module_navigation as $item) :
                                $link_classes = ['sitepulse-module-nav__link'];

                                if (!empty($item['current'])) {
                                    $link_classes[] = 'is-current';
                                }
                            ?>
                                <li class="sitepulse-module-nav__item">
                                    <a class="<?php echo esc_attr(implode(' ', $link_classes)); ?>" href="<?php echo esc_url($item['url']); ?>"<?php echo !empty($item['current']) ? ' aria-current="page"' : ''; ?>>
                                        <?php if (!empty($item['icon'])) : ?>
                                            <span class="sitepulse-module-nav__icon dashicons <?php echo esc_attr($item['icon']); ?>" aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <span class="sitepulse-module-nav__label"><?php echo esc_html($item['label']); ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <button
                        type="button"
                        class="sitepulse-module-nav__scroll-button sitepulse-module-nav__scroll-button--next"
                        data-sitepulse-nav-scroll="next"
                        aria-controls="<?php echo esc_attr($nav_list_id); ?>"
                        aria-label="<?php esc_attr_e('Scroll navigation right', 'sitepulse'); ?>"
                        disabled
                    >
                        <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </button>
                </div>
            </nav>
        <?php endif; ?>

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
