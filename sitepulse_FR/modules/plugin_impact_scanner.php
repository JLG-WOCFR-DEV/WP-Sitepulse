<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action(
    'admin_menu',
    function () {
        add_submenu_page(
            'sitepulse-dashboard',
            __('Plugin Impact Scanner', 'sitepulse'),
            __('Plugin Impact', 'sitepulse'),
            sitepulse_get_capability(),
            'sitepulse-plugins',
            'sitepulse_plugin_impact_scanner_page'
        );
    }
);

add_action('admin_enqueue_scripts', 'sitepulse_plugin_impact_enqueue_assets');

/**
 * Enqueues styles for the plugin impact scanner admin screen.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_plugin_impact_enqueue_assets($hook_suffix) {
    if ($hook_suffix !== 'sitepulse-dashboard_page_sitepulse-plugins') {
        return;
    }

    wp_enqueue_style(
        'sitepulse-plugin-impact',
        SITEPULSE_URL . 'modules/css/plugin-impact-scanner.css',
        [],
        SITEPULSE_VERSION
    );

    wp_enqueue_script(
        'sitepulse-plugin-impact',
        SITEPULSE_URL . 'modules/js/plugin-impact-scanner.js',
        [],
        SITEPULSE_VERSION,
        true
    );

    $default_thresholds = function_exists('sitepulse_get_default_plugin_impact_thresholds')
        ? sitepulse_get_default_plugin_impact_thresholds()
        : [
            'impactWarning'  => 30.0,
            'impactCritical' => 60.0,
            'weightWarning'  => 10.0,
            'weightCritical' => 20.0,
            'trendWarning'   => 15.0,
            'trendCritical'  => 40.0,
        ];

    $stored_thresholds = [
        'default' => $default_thresholds,
        'roles'   => [],
    ];

    if (defined('SITEPULSE_OPTION_IMPACT_THRESHOLDS')) {
        $option_value = get_option(
            SITEPULSE_OPTION_IMPACT_THRESHOLDS,
            [
                'default' => $default_thresholds,
                'roles'   => [],
            ]
        );

        if (is_array($option_value)) {
            $stored_thresholds = $option_value;
        }
    }

    if (function_exists('sitepulse_sanitize_impact_thresholds')) {
        $stored_thresholds = sitepulse_sanitize_impact_thresholds($stored_thresholds);
    }

    $effective_thresholds = isset($stored_thresholds['default']) && is_array($stored_thresholds['default'])
        ? $stored_thresholds['default']
        : $default_thresholds;

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

    $thresholds = apply_filters('sitepulse_plugin_impact_highlight_thresholds', $effective_thresholds);

    if (function_exists('sitepulse_normalize_impact_threshold_set')) {
        $thresholds = sitepulse_normalize_impact_threshold_set($thresholds, $default_thresholds);
    } else {
        if (!is_array($thresholds)) {
            $thresholds = $default_thresholds;
        }

        $thresholds = wp_parse_args($thresholds, $default_thresholds);

        foreach ($thresholds as $key => $value) {
            $thresholds[$key] = is_numeric($value) ? (float) $value : $default_thresholds[$key];
        }
    }

    wp_localize_script(
        'sitepulse-plugin-impact',
        'sitepulsePluginImpactScanner',
        [
            'thresholds' => $thresholds,
            'i18n'       => [
                'sortImpactDesc' => esc_html__('Tri : impact décroissant', 'sitepulse'),
                'sortImpactAsc'  => esc_html__('Tri : impact croissant', 'sitepulse'),
                'sortNameAsc'    => esc_html__('Tri : nom (A → Z)', 'sitepulse'),
                'sortWeightDesc' => esc_html__('Tri : poids décroissant', 'sitepulse'),
                'weightMinLabel' => esc_html__('Poids min (%)', 'sitepulse'),
                'weightMaxLabel' => esc_html__('Poids max (%)', 'sitepulse'),
                'resetFilters'   => esc_html__('Réinitialiser', 'sitepulse'),
                'exportCsv'      => esc_html__('Exporter CSV', 'sitepulse'),
                'noResult'       => esc_html__('Aucun plugin ne correspond aux filtres.', 'sitepulse'),
                'fileName'       => esc_html__('sitepulse-plugin-impact.csv', 'sitepulse'),
            ],
        ]
    );
}

add_action('upgrader_process_complete', 'sitepulse_plugin_impact_clear_dir_cache_on_upgrade', 10, 2);
add_action('sitepulse_queue_plugin_dir_scan', 'sitepulse_process_plugin_dir_scan_queue');

if (!defined('SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION')) {
    define('SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION', 'sitepulse_plugin_dir_scan_queue');
}

function sitepulse_plugin_impact_clear_dir_cache_on_upgrade($upgrader, $hook_extra) {
    if (!is_array($hook_extra) || !isset($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return;
    }

    if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        return;
    }

    $plugin_files = [];

    if (isset($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
        foreach ($hook_extra['plugins'] as $plugin_file) {
            if (is_string($plugin_file) && $plugin_file !== '') {
                $plugin_files[] = $plugin_file;
            }
        }
    } elseif (isset($hook_extra['plugin']) && is_string($hook_extra['plugin']) && $hook_extra['plugin'] !== '') {
        $plugin_files[] = $hook_extra['plugin'];
    }

    if (empty($plugin_files)) {
        if (function_exists('sitepulse_delete_transients_by_prefix')) {
            sitepulse_delete_transients_by_prefix(SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX);
        }

        if (function_exists('sitepulse_delete_site_transients_by_prefix')) {
            sitepulse_delete_site_transients_by_prefix(SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX);
        }

        return;
    }

    $plugin_files = array_unique($plugin_files);

    foreach ($plugin_files as $plugin_file) {
        $plugin_dir = dirname($plugin_file);

        if ($plugin_dir === '.' || $plugin_dir === '' || $plugin_dir === DIRECTORY_SEPARATOR) {
            continue;
        }

        $plugin_dir_path = WP_PLUGIN_DIR . '/' . $plugin_dir;

        sitepulse_clear_dir_size_cache($plugin_dir_path);

        if (is_multisite()) {
            $site_ids = function_exists('get_sites')
                ? get_sites([
                    'fields' => 'ids',
                    'number' => 0,
                    'no_found_rows' => true,
                ])
                : [];

            if (!empty($site_ids) && defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
                $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($plugin_dir_path);

                foreach ($site_ids as $site_id) {
                    $site_id = (int) $site_id;

                    if ($site_id <= 0) {
                        continue;
                    }

                    $switched = switch_to_blog($site_id);

                    if (!$switched) {
                        continue;
                    }

                    delete_transient($transient_key);
                    restore_current_blog();
                }
            }
        }
    }
}


require_once __DIR__ . '/plugin-impact/page.php';

function sitepulse_plugin_impact_get_measurements() {
    if (!defined('SITEPULSE_PLUGIN_IMPACT_OPTION')) {
        return [
            'last_updated' => 0,
            'interval'     => defined('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL') ? SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL : 15 * MINUTE_IN_SECONDS,
            'samples'      => [],
        ];
    }

    $data = get_option(SITEPULSE_PLUGIN_IMPACT_OPTION, []);

    if (!is_array($data)) {
        $data = [];
    }

    $default_interval = defined('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL') ? SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL : 15 * MINUTE_IN_SECONDS;

    return [
        'last_updated' => isset($data['last_updated']) ? (int) $data['last_updated'] : 0,
        'interval'     => isset($data['interval']) ? max(1, (int) $data['interval']) : $default_interval,
        'samples'      => isset($data['samples']) && is_array($data['samples']) ? $data['samples'] : [],
    ];
}

/**
 * Retrieves the persisted plugin impact history.
 *
 * @return array<string,mixed>
 */
function sitepulse_plugin_impact_get_history() {
    if (!defined('SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY')) {
        return [
            'updated_at' => 0,
            'plugins'    => [],
        ];
    }

    $stored = get_option(SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY, []);

    if (!is_array($stored)) {
        $stored = [];
    }

    $updated_at = isset($stored['updated_at']) ? (int) $stored['updated_at'] : 0;
    $plugins = [];

    if (isset($stored['plugins']) && is_array($stored['plugins'])) {
        foreach ($stored['plugins'] as $plugin_file => $entries) {
            if (!is_string($plugin_file) || $plugin_file === '' || !is_array($entries)) {
                continue;
            }

            $normalized = [];

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                $average = isset($entry['avg_ms']) ? (float) $entry['avg_ms'] : null;

                if ($timestamp <= 0 || $average === null || !is_numeric($average)) {
                    continue;
                }

                $normalized[$timestamp] = [
                    'timestamp' => $timestamp,
                    'avg_ms'    => max(0.0, (float) $average),
                ];

                if (isset($entry['samples']) && is_numeric($entry['samples'])) {
                    $normalized[$timestamp]['samples'] = max(0, (int) $entry['samples']);
                }

                if (isset($entry['weight']) && is_numeric($entry['weight'])) {
                    $normalized[$timestamp]['weight'] = max(0.0, (float) $entry['weight']);
                }

                if (isset($entry['last_ms']) && is_numeric($entry['last_ms'])) {
                    $normalized[$timestamp]['last_ms'] = max(0.0, (float) $entry['last_ms']);
                }
            }

            if (empty($normalized)) {
                continue;
            }

            ksort($normalized);

            $plugins[$plugin_file] = array_values($normalized);
        }
    }

    return [
        'updated_at' => max(0, $updated_at),
        'plugins'    => $plugins,
    ];
}

/**
 * Calculates trend data for a plugin using history entries.
 *
 * @param array<int,array<string,float|int>> $history_entries Sorted history entries.
 * @param float|null                         $current_average Latest average in milliseconds.
 * @param int                                $current_time    Current timestamp.
 *
 * @return array<string,mixed>
 */
function sitepulse_plugin_impact_calculate_trend(array $history_entries, $current_average, $current_time) {
    $entry_count = count($history_entries);

    if (0 === $entry_count) {
        return [
            'direction'   => 'none',
            'change_ms'   => null,
            'change_pct'  => null,
            'previous'    => null,
            'average_7d'  => null,
            'average_30d' => null,
        ];
    }

    $latest = $history_entries[$entry_count - 1];
    $previous = $entry_count > 1 ? $history_entries[$entry_count - 2] : null;

    $latest_avg = isset($latest['avg_ms']) ? (float) $latest['avg_ms'] : null;
    $previous_avg = ($previous !== null && isset($previous['avg_ms'])) ? (float) $previous['avg_ms'] : null;

    if ($current_average !== null && is_numeric($current_average)) {
        $latest_avg = (float) $current_average;
    }

    $change_ms = null;
    $change_pct = null;
    $direction = 'none';

    if ($latest_avg !== null && $previous_avg !== null) {
        $change_ms = $latest_avg - $previous_avg;

        if (abs($change_ms) < 0.01) {
            $change_ms = 0.0;
        }

        if (abs($previous_avg) > 0.0001) {
            $change_pct = ($change_ms / $previous_avg) * 100;
        }

        if ($change_ms > 0.0) {
            $direction = 'up';
        } elseif ($change_ms < 0.0) {
            $direction = 'down';
        } else {
            $direction = 'flat';
        }
    }

    $seven_days_ago = $current_time - (7 * DAY_IN_SECONDS);
    $thirty_days_ago = $current_time - (30 * DAY_IN_SECONDS);

    $average_7d = sitepulse_plugin_impact_average_window($history_entries, $seven_days_ago);
    $average_30d = sitepulse_plugin_impact_average_window($history_entries, $thirty_days_ago);

    return [
        'direction'   => $direction,
        'change_ms'   => $change_ms,
        'change_pct'  => $change_pct,
        'previous'    => $previous_avg,
        'average_7d'  => $average_7d,
        'average_30d' => $average_30d,
    ];
}

/**
 * Computes the rolling average of the provided history entries after a cutoff.
 *
 * @param array<int,array<string,float|int>> $history_entries History entries.
 * @param int                                $cutoff          Minimum timestamp to include.
 *
 * @return float|null
 */
function sitepulse_plugin_impact_average_window(array $history_entries, $cutoff) {
    $cutoff = (int) $cutoff;

    $sum = 0.0;
    $count = 0;

    foreach ($history_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

        if ($timestamp <= 0 || $timestamp < $cutoff) {
            continue;
        }

        if (!isset($entry['avg_ms']) || !is_numeric($entry['avg_ms'])) {
            continue;
        }

        $sum += max(0.0, (float) $entry['avg_ms']);
        $count++;
    }

    if (0 === $count) {
        return null;
    }

    return $sum / $count;
}

/**
 * Formats the trend change for display.
 *
 * @param array<string,mixed> $trend Trend payload returned by {@see sitepulse_plugin_impact_calculate_trend()}.
 *
 * @return string
 */
function sitepulse_plugin_impact_format_trend_label($trend) {
    if (!is_array($trend)) {
        return '';
    }

    $direction = isset($trend['direction']) ? (string) $trend['direction'] : 'none';
    $change_ms = isset($trend['change_ms']) && is_numeric($trend['change_ms']) ? (float) $trend['change_ms'] : null;
    $change_pct = isset($trend['change_pct']) && is_numeric($trend['change_pct']) ? (float) $trend['change_pct'] : null;

    if ($change_ms === null || $direction === 'none') {
        return '';
    }

    $arrow = '→';

    if ($direction === 'up') {
        $arrow = '↑';
    } elseif ($direction === 'down') {
        $arrow = '↓';
    }

    $formatted_ms = number_format_i18n(abs($change_ms), 2);

    if ($change_pct !== null) {
        $formatted_pct = number_format_i18n(abs($change_pct), 1);

        return sprintf(
            /* translators: 1: arrow indicator, 2: delta in milliseconds, 3: delta percentage. */
            __('Variation vs précédente mesure : %1$s %2$s ms (%3$s %%).', 'sitepulse'),
            $arrow,
            $formatted_ms,
            $formatted_pct
        );
    }

    return sprintf(
        /* translators: 1: arrow indicator, 2: delta in milliseconds. */
        __('Variation vs précédente mesure : %1$s %2$s ms.', 'sitepulse'),
        $arrow,
        $formatted_ms
    );
}

function sitepulse_plugin_impact_normalize_timestamp_for_display($timestamp) {
    $timestamp = (int) $timestamp;

    if ($timestamp <= 0) {
        return 0;
    }

    $mysql_datetime = gmdate('Y-m-d H:i:s', $timestamp);

    if (function_exists('wp_timezone')) {
        $timezone = wp_timezone();

        if ($timezone instanceof DateTimeZone) {
            $date = date_create_from_format('Y-m-d H:i:s', $mysql_datetime, $timezone);

            if ($date instanceof DateTimeInterface) {
                return $date->getTimestamp();
            }
        }
    }

    $offset = (float) get_option('gmt_offset', 0);

    return $timestamp - (int) ($offset * HOUR_IN_SECONDS);
}

function sitepulse_plugin_impact_format_interval($seconds) {
    $seconds = (int) $seconds;

    if ($seconds <= 0) {
        return __('immédiatement', 'sitepulse');
    }

    if ($seconds < MINUTE_IN_SECONDS) {
        $value = max(1, $seconds);

        return sprintf(
            _n('%s seconde', '%s secondes', $value, 'sitepulse'),
            number_format_i18n($value)
        );
    }

    if ($seconds < HOUR_IN_SECONDS) {
        $minutes = max(1, (int) round($seconds / MINUTE_IN_SECONDS));

        return sprintf(
            _n('%s minute', '%s minutes', $minutes, 'sitepulse'),
            number_format_i18n($minutes)
        );
    }

    if ($seconds < DAY_IN_SECONDS) {
        $hours = max(1, (int) round($seconds / HOUR_IN_SECONDS));

        return sprintf(
            _n('%s heure', '%s heures', $hours, 'sitepulse'),
            number_format_i18n($hours)
        );
    }

    $days = max(1, (int) round($seconds / DAY_IN_SECONDS));

    return sprintf(
        _n('%s jour', '%s jours', $days, 'sitepulse'),
        number_format_i18n($days)
    );
}

function sitepulse_get_dir_size_with_cache($dir) {
    $dir = (string) $dir;

    if ($dir === '') {
        return [
            'status' => 'complete',
            'size'   => 0,
            'files'  => null,
            'generated_at' => null,
        ];
    }

    $timestamp = sitepulse_plugin_impact_get_timestamp();

    if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        $size_info = sitepulse_get_dir_size_recursive($dir);

        return [
            'status' => 'complete',
            'size'   => isset($size_info['size']) ? (int) $size_info['size'] : 0,
            'files'  => isset($size_info['files']) ? max(0, (int) $size_info['files']) : null,
            'generated_at' => $timestamp,
        ];
    }

    $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($dir);
    $cached_size = get_transient($transient_key);

    if ($cached_size !== false) {
        if (is_array($cached_size)) {
            $status = isset($cached_size['status']) ? $cached_size['status'] : 'complete';

            if ($status === 'pending') {
                sitepulse_plugin_dir_scan_enqueue($dir);

                return [
                    'status' => 'pending',
                    'size'   => null,
                    'files'  => null,
                    'generated_at' => isset($cached_size['generated_at']) ? (int) $cached_size['generated_at'] : null,
                ];
            }

            return [
                'status' => 'complete',
                'size'   => isset($cached_size['size']) ? (int) $cached_size['size'] : 0,
                'files'  => isset($cached_size['files']) ? max(0, (int) $cached_size['files']) : null,
                'generated_at' => isset($cached_size['generated_at']) ? (int) $cached_size['generated_at'] : null,
            ];
        }

        if (is_numeric($cached_size)) {
            return [
                'status' => 'complete',
                'size'   => (int) $cached_size,
                'files'  => null,
                'generated_at' => null,
            ];
        }

    }

    $threshold = sitepulse_get_plugin_dir_size_threshold($dir);
    $size_info = sitepulse_get_dir_size_recursive(
        $dir,
        [
            'max_bytes'         => isset($threshold['max_bytes']) ? (int) $threshold['max_bytes'] : 0,
            'max_files'         => isset($threshold['max_files']) ? (int) $threshold['max_files'] : 0,
            'stop_on_threshold' => true,
        ]
    );

    $expiration = (int) apply_filters('sitepulse_plugin_dir_size_cache_ttl', 6 * HOUR_IN_SECONDS, $dir);

    if ($expiration <= 0) {
        $expiration = 6 * HOUR_IN_SECONDS;
    }

    if (isset($size_info['exceeded']) && $size_info['exceeded']) {
        $payload = [
            'status' => 'pending',
            'size'   => null,
            'files'  => null,
            'generated_at' => $timestamp,
        ];

        set_transient($transient_key, $payload, $expiration);

        sitepulse_plugin_dir_scan_enqueue($dir);

        return $payload;
    }

    $size = isset($size_info['size']) ? (int) $size_info['size'] : 0;
    $files = isset($size_info['files']) ? max(0, (int) $size_info['files']) : null;

    $payload = [
        'status' => 'complete',
        'size'   => $size,
        'files'  => $files,
        'generated_at' => $timestamp,
    ];

    set_transient($transient_key, $payload, $expiration);

    return $payload;
}

function sitepulse_clear_dir_size_cache($dir) {
    $dir = (string) $dir;

    if ($dir === '' || !defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        return;
    }

    $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($dir);

    delete_transient($transient_key);

    if (function_exists('delete_site_transient')) {
        delete_site_transient($transient_key);
    }

    sitepulse_plugin_dir_scan_remove_from_queue($dir);
}

function sitepulse_get_dir_size_recursive($dir, $args = []) {
    $defaults = [
        'max_bytes'         => 0,
        'max_files'         => 0,
        'stop_on_threshold' => false,
    ];

    if (!is_array($args)) {
        $args = [];
    }

    $args = wp_parse_args($args, $defaults);

    $size = 0;
    $file_count = 0;
    $exceeded = false;

    $dir = (string) $dir;
    $resolved_dir = $dir;

    if (function_exists('realpath')) {
        $realpath = realpath($dir);

        if ($realpath !== false) {
            // Resolve the directory to follow symlinks where possible.
            $resolved_dir = $realpath;
        }
    }

    if (!is_dir($resolved_dir)) {
        return [
            'size'     => $size,
            'files'    => $file_count,
            'exceeded' => $exceeded,
        ];
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved_dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $size += $file->getSize();
            $file_count++;

            if ($args['stop_on_threshold']) {
                $threshold_exceeded = false;

                if ($args['max_bytes'] > 0 && $size > $args['max_bytes']) {
                    $threshold_exceeded = true;
                }

                if ($args['max_files'] > 0 && $file_count > $args['max_files']) {
                    $threshold_exceeded = true;
                }

                if ($threshold_exceeded) {
                    $exceeded = true;

                    break;
                }
            }
        }
    } catch (UnexpectedValueException | RuntimeException $e) {
        return [
            'size'     => $size,
            'files'    => $file_count,
            'exceeded' => $exceeded,
        ];
    }

    return [
        'size'     => $size,
        'files'    => $file_count,
        'exceeded' => $exceeded,
    ];
}

function sitepulse_get_plugin_dir_size_threshold($dir) {
    $default_threshold = [
        'max_bytes' => 100 * MB_IN_BYTES,
        'max_files' => 0,
    ];

    $threshold = apply_filters('sitepulse_plugin_dir_size_threshold', $default_threshold, $dir);

    if (!is_array($threshold)) {
        return $default_threshold;
    }

    $threshold = wp_parse_args($threshold, $default_threshold);

    $threshold['max_bytes'] = isset($threshold['max_bytes']) ? max(0, (int) $threshold['max_bytes']) : 0;
    $threshold['max_files'] = isset($threshold['max_files']) ? max(0, (int) $threshold['max_files']) : 0;

    return $threshold;
}

function sitepulse_plugin_impact_guess_slug($plugin_file, $plugin_data = []) {
    $plugin_file = (string) $plugin_file;

    if ($plugin_file === '') {
        return '';
    }

    if (is_array($plugin_data) && !empty($plugin_data['slug'])) {
        return sanitize_key($plugin_data['slug']);
    }

    $plugin_dir = dirname($plugin_file);

    if ($plugin_dir !== '.' && $plugin_dir !== '' && $plugin_dir !== DIRECTORY_SEPARATOR) {
        return sanitize_title($plugin_dir);
    }

    $plugin_basename = basename($plugin_file, '.php');

    if ($plugin_basename !== '') {
        return sanitize_title($plugin_basename);
    }

    return '';
}

function sitepulse_plugin_dir_scan_enqueue($dir) {
    $dir = (string) $dir;

    if ($dir === '') {
        return;
    }

    $queue = get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, []);

    if (!is_array($queue)) {
        $queue = [];
    }

    if (!in_array($dir, $queue, true)) {
        $queue[] = $dir;
        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, $queue, false);
    }

    sitepulse_schedule_plugin_dir_scan();
}

function sitepulse_plugin_dir_scan_remove_from_queue($dir) {
    $dir = (string) $dir;

    if ($dir === '') {
        return;
    }

    $queue = get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, []);

    if (!is_array($queue) || empty($queue)) {
        return;
    }

    $position = array_search($dir, $queue, true);

    if ($position === false) {
        return;
    }

    unset($queue[$position]);

    if (empty($queue)) {
        delete_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION);
    } else {
        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, array_values($queue), false);
    }
}

function sitepulse_schedule_plugin_dir_scan() {
    if (!wp_next_scheduled('sitepulse_queue_plugin_dir_scan')) {
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'sitepulse_queue_plugin_dir_scan');
    }
}

function sitepulse_process_plugin_dir_scan_queue() {
    if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        return;
    }

    $queue = get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, []);

    if (!is_array($queue) || empty($queue)) {
        delete_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION);

        return;
    }

    $dir = array_shift($queue);

    if (empty($queue)) {
        delete_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION);
    } else {
        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, array_values($queue), false);
    }

    $dir = (string) $dir;

    if ($dir === '') {
        sitepulse_schedule_plugin_dir_scan();

        return;
    }

    $size_info = sitepulse_get_dir_size_recursive(
        $dir,
        [
            'max_bytes'         => 0,
            'max_files'         => 0,
            'stop_on_threshold' => false,
        ]
    );

    $size = isset($size_info['size']) ? (int) $size_info['size'] : 0;
    $files = isset($size_info['files']) ? max(0, (int) $size_info['files']) : null;

    $expiration = (int) apply_filters('sitepulse_plugin_dir_size_cache_ttl', 6 * HOUR_IN_SECONDS, $dir);

    if ($expiration <= 0) {
        $expiration = 6 * HOUR_IN_SECONDS;
    }

    $payload = [
        'status' => 'complete',
        'size'   => $size,
        'files'  => $files,
        'generated_at' => sitepulse_plugin_impact_get_timestamp(),
    ];

    $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($dir);

    set_transient($transient_key, $payload, $expiration);

    if (!empty($queue)) {
        sitepulse_schedule_plugin_dir_scan();
    }
}

function sitepulse_plugin_impact_get_timestamp() {
    if (function_exists('current_time')) {
        return (int) current_time('timestamp');
    }

    return time();
}
