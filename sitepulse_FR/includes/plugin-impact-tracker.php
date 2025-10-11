<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/impact-scoring.php';

if (!defined('SITEPULSE_PLUGIN_IMPACT_OPTION')) {
    define('SITEPULSE_PLUGIN_IMPACT_OPTION', 'sitepulse_plugin_impact_stats');
}

if (!defined('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL')) {
    define('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL', 15 * MINUTE_IN_SECONDS);
}

if (!defined('SITEPULSE_OPTION_SPEED_SCAN_HISTORY')) {
    define('SITEPULSE_OPTION_SPEED_SCAN_HISTORY', 'sitepulse_speed_scan_history');
}

if (!defined('SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY')) {
    define('SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY', 'sitepulse_plugin_impact_history');
}

if (!isset($sitepulse_plugin_impact_tracker_last_tick) || !is_float($sitepulse_plugin_impact_tracker_last_tick)) {
    $sitepulse_plugin_impact_tracker_last_tick = microtime(true);
}

if (!isset($sitepulse_plugin_impact_tracker_samples) || !is_array($sitepulse_plugin_impact_tracker_samples)) {
    $sitepulse_plugin_impact_tracker_samples = [];
}

if (!isset($sitepulse_plugin_impact_tracker_force_persist)) {
    $sitepulse_plugin_impact_tracker_force_persist = false;
}

/**
 * Ensures the plugin impact tracker hooks are registered.
 *
 * When executed from a Must-Use loader this allows SitePulse to observe the
 * loading time of plugins that would normally be initialized before SitePulse
 * itself. The function is idempotent and can be safely called multiple times
 * (for instance once from the MU loader and once from the main plugin file).
 *
 * @return void
 */
function sitepulse_plugin_impact_tracker_bootstrap() {
    static $sitepulse_plugin_impact_tracker_bootstrapped = false;

    if ($sitepulse_plugin_impact_tracker_bootstrapped) {
        return;
    }

    $sitepulse_plugin_impact_tracker_bootstrapped = true;

    add_action('plugin_loaded', 'sitepulse_plugin_impact_tracker_on_plugin_loaded', PHP_INT_MAX, 1);
    add_action('shutdown', 'sitepulse_plugin_impact_tracker_persist', PHP_INT_MAX);
}

/**
 * Normalizes and persists the plugin impact history entries.
 *
 * @param array<string,array> $samples  Latest plugin measurements keyed by plugin file.
 * @param int                 $now      Current timestamp.
 *
 * @return void
 */
function sitepulse_plugin_impact_tracker_update_history(array $samples, $now) {
    if (!defined('SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY')) {
        return;
    }

    $stored = get_option(SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY, []);

    if (!is_array($stored)) {
        $stored = [];
    }

    $plugins_history = isset($stored['plugins']) && is_array($stored['plugins']) ? $stored['plugins'] : [];

    $total_impact = 0.0;

    foreach ($samples as $sample) {
        if (!is_array($sample) || !isset($sample['avg_ms']) || !is_numeric($sample['avg_ms'])) {
            continue;
        }

        $total_impact += max(0.0, (float) $sample['avg_ms']);
    }

    $retention_days = apply_filters('sitepulse_plugin_impact_history_retention_days', 30, $samples);
    $retention_days = is_numeric($retention_days) ? (int) $retention_days : 30;
    $retention_days = max(-1, $retention_days);
    $cutoff = $retention_days > 0 ? $now - ($retention_days * DAY_IN_SECONDS) : null;

    $default_max_entries = apply_filters('sitepulse_plugin_impact_history_max_entries', 90, $samples);
    $default_max_entries = is_numeric($default_max_entries) ? (int) $default_max_entries : 90;
    $default_max_entries = max(0, $default_max_entries);

    foreach ($samples as $plugin_file => $sample) {
        if (!is_string($plugin_file) || $plugin_file === '') {
            continue;
        }

        if (!is_array($sample) || !isset($sample['avg_ms']) || !is_numeric($sample['avg_ms'])) {
            continue;
        }

        $avg_ms = max(0.0, (float) $sample['avg_ms']);
        $entry = [
            'timestamp' => $now,
            'avg_ms'    => $avg_ms,
        ];

        if (isset($sample['samples']) && is_numeric($sample['samples'])) {
            $entry['samples'] = max(0, (int) $sample['samples']);
        }

        if (isset($sample['last_ms']) && is_numeric($sample['last_ms'])) {
            $entry['last_ms'] = max(0.0, (float) $sample['last_ms']);
        }

        if ($total_impact > 0) {
            $entry['weight'] = ($avg_ms / $total_impact) * 100;
        }

        $existing_history = isset($plugins_history[$plugin_file]) && is_array($plugins_history[$plugin_file])
            ? $plugins_history[$plugin_file]
            : [];

        $normalized_entries = [];

        foreach ($existing_history as $historical_entry) {
            if (!is_array($historical_entry)) {
                continue;
            }

            $timestamp = isset($historical_entry['timestamp']) ? (int) $historical_entry['timestamp'] : 0;
            $average = isset($historical_entry['avg_ms']) ? (float) $historical_entry['avg_ms'] : null;

            if ($timestamp <= 0 || $average === null || !is_numeric($average)) {
                continue;
            }

            if (null !== $cutoff && $timestamp < $cutoff) {
                continue;
            }

            $normalized_entries[$timestamp] = [
                'timestamp' => $timestamp,
                'avg_ms'    => max(0.0, (float) $average),
            ];

            if (isset($historical_entry['samples']) && is_numeric($historical_entry['samples'])) {
                $normalized_entries[$timestamp]['samples'] = max(0, (int) $historical_entry['samples']);
            }

            if (isset($historical_entry['last_ms']) && is_numeric($historical_entry['last_ms'])) {
                $normalized_entries[$timestamp]['last_ms'] = max(0.0, (float) $historical_entry['last_ms']);
            }

            if (isset($historical_entry['weight']) && is_numeric($historical_entry['weight'])) {
                $normalized_entries[$timestamp]['weight'] = max(0.0, (float) $historical_entry['weight']);
            }
        }

        $normalized_entries[$now] = $entry;

        ksort($normalized_entries);

        $entries = array_values($normalized_entries);

        $max_entries_for_plugin = apply_filters('sitepulse_plugin_impact_history_max_entries_for_plugin', $default_max_entries, $plugin_file, $samples);
        $max_entries_for_plugin = is_numeric($max_entries_for_plugin) ? (int) $max_entries_for_plugin : $default_max_entries;
        $max_entries_for_plugin = max(0, $max_entries_for_plugin);

        if ($max_entries_for_plugin > 0 && count($entries) > $max_entries_for_plugin) {
            $entries = array_slice($entries, -$max_entries_for_plugin);
        }

        $plugins_history[$plugin_file] = $entries;
    }

    $stored_plugins = [];

    foreach ($plugins_history as $plugin_file => $history_entries) {
        if (!is_string($plugin_file) || $plugin_file === '') {
            continue;
        }

        if (empty($history_entries) || !is_array($history_entries)) {
            continue;
        }

        $stored_plugins[$plugin_file] = array_values($history_entries);
    }

    $payload = [
        'updated_at'   => $now,
        'total_impact' => $total_impact,
        'plugins'      => $stored_plugins,
    ];

    update_option(SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY, $payload, false);

    if (!empty($stored_plugins)) {
        sitepulse_plugin_impact_persist_scores($stored_plugins, $now);
    } else {
        sitepulse_plugin_impact_persist_scores([], $now);
    }
}

/**
 * Records the elapsed time between plugin loading operations.
 *
 * @param string $plugin_file Relative path to the plugin file.
 *
 * @return void
 */
function sitepulse_plugin_impact_tracker_on_plugin_loaded($plugin_file) {
    global $sitepulse_plugin_impact_tracker_last_tick, $sitepulse_plugin_impact_tracker_samples;

    if (!is_string($plugin_file) || $plugin_file === '') {
        return;
    }

    $now = microtime(true);

    if (!is_float($sitepulse_plugin_impact_tracker_last_tick)) {
        $sitepulse_plugin_impact_tracker_last_tick = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : $now;
    }

    $elapsed = max(0.0, $now - $sitepulse_plugin_impact_tracker_last_tick);
    $sitepulse_plugin_impact_tracker_samples[$plugin_file] = $elapsed;
    $sitepulse_plugin_impact_tracker_last_tick = $now;
}

/**
 * Returns a human readable name for a plugin file.
 *
 * Previously this information was retrieved by scanning every plugin installed
 * via {@see get_plugins()}, which can be particularly expensive on large
 * installations. The helper reuses the last persisted name when available and
 * only falls back to reading the specific plugin header when needed.
 *
 * @param string $plugin_file      Plugin file relative path.
 * @param array  $existing_samples Previously stored samples keyed by plugin file.
 *
 * @return string
 */
function sitepulse_plugin_impact_get_plugin_name($plugin_file, array $existing_samples) {
    static $sitepulse_plugin_impact_name_cache = [];

    $plugin_file = (string) $plugin_file;

    if (isset($sitepulse_plugin_impact_name_cache[$plugin_file])) {
        return $sitepulse_plugin_impact_name_cache[$plugin_file];
    }

    if (isset($existing_samples[$plugin_file]['name']) && is_string($existing_samples[$plugin_file]['name'])) {
        $name = $existing_samples[$plugin_file]['name'];
    } else {
        $name = $plugin_file;

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (function_exists('get_plugin_data')) {
            $plugin_path = trailingslashit(WP_PLUGIN_DIR) . $plugin_file;

            if (file_exists($plugin_path) && is_readable($plugin_path)) {
                $data = get_plugin_data($plugin_path, false, false);

                if (!empty($data['Name'])) {
                    $name = (string) $data['Name'];
                }
            }
        }
    }

    $sitepulse_plugin_impact_name_cache[$plugin_file] = $name;

    return $name;
}

/**
 * Persists the collected plugin load measurements when appropriate.
 *
 * @return void
 */
function sitepulse_plugin_impact_tracker_persist() {
    global $sitepulse_plugin_impact_tracker_samples, $sitepulse_plugin_impact_tracker_force_persist;

    $force_persist = !empty($sitepulse_plugin_impact_tracker_force_persist);

    if (!$force_persist && ((function_exists('wp_doing_cron') && wp_doing_cron())
        || (function_exists('wp_doing_ajax') && wp_doing_ajax())
        || (defined('REST_REQUEST') && REST_REQUEST))
    ) {
        return;
    }

    $track_admin_requests = apply_filters('sitepulse_track_admin_requests', false);

    if (
        function_exists('is_admin')
        && is_admin()
        && !$force_persist
        && !$track_admin_requests
    ) {
        return;
    }

    $request_start = null;

    $non_representative_context = false;

    if (!$force_persist) {
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            $non_representative_context = true;
        } elseif (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            $non_representative_context = true;
        } elseif (defined('REST_REQUEST') && REST_REQUEST) {
            $non_representative_context = true;
        } elseif (defined('WP_CLI') && WP_CLI) {
            $non_representative_context = true;
        } elseif (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            $non_representative_context = true;
        }
    }

    $should_measure_request = apply_filters(
        'sitepulse_plugin_impact_should_measure_request',
        !$non_representative_context
        && (
            !function_exists('is_admin')
            || !is_admin()
            || $force_persist
        )
    );

    if (!$force_persist && ($non_representative_context || !$should_measure_request)) {
        return;
    }

    if (isset($GLOBALS['timestart']) && is_numeric($GLOBALS['timestart'])) {
        $request_start = (float) $GLOBALS['timestart'];
    } elseif (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
        $request_start = (float) $_SERVER['REQUEST_TIME_FLOAT'];
    }

    $request_duration_ms = 0.0;

    if ($request_start !== null) {
        $request_duration_ms = max(0.0, (microtime(true) - $request_start) * 1000);
    }

    if (!defined('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS')) {
        return;
    }

    $existing_results = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
    $current_timestamp = current_time('timestamp');
    $should_persist_speed_scan = true;

    if (!$force_persist && is_array($existing_results)) {
        $existing_timestamp = isset($existing_results['timestamp'])
            ? (int) $existing_results['timestamp']
            : null;

        if ($existing_timestamp !== null && $existing_timestamp > 0) {
            $raw_measurement_age = $current_timestamp - $existing_timestamp;
            $measurement_age = max(0, $raw_measurement_age);

            // When the current timestamp appears older than the stored value (e.g. due to clock
            // drift or mocked time in tests) we treat the difference as zero so that the stale
            // measurement still triggers a refresh.
            if ($raw_measurement_age >= 0 && $measurement_age < 60) {
                $should_persist_speed_scan = false;
            }
        }
    }

    if ($force_persist) {
        $should_persist_speed_scan = true;
    }

    if ($should_persist_speed_scan) {
        update_option(SITEPULSE_OPTION_LAST_LOAD_TIME, $request_duration_ms, false);
        set_transient(
            SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS,
            [
                'server_processing_ms' => $request_duration_ms,
                'ttfb'                 => $request_duration_ms, // Back-compat for earlier dashboard versions.
                'timestamp'            => $current_timestamp,
            ],
            MINUTE_IN_SECONDS * 10
        );

        $history = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'timestamp'            => $current_timestamp,
            'server_processing_ms' => $request_duration_ms,
        ];

        $max_age = apply_filters('sitepulse_speed_history_max_age', DAY_IN_SECONDS * 7);

        if (!is_scalar($max_age)) {
            $max_age = 0;
        }

        $max_age = (int) $max_age;

        if ($max_age > 0) {
            $cutoff = $current_timestamp - $max_age;
            $history = array_values(array_filter(
                $history,
                static function ($entry) use ($cutoff) {
                    if (!is_array($entry)) {
                        return false;
                    }

                    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

                    return $timestamp >= $cutoff;
                }
            ));
        }

        $max_entries = apply_filters('sitepulse_speed_history_max_entries', 50);

        if (!is_scalar($max_entries)) {
            $max_entries = 0;
        }

        $max_entries = (int) $max_entries;

        if ($max_entries > 0 && count($history) > $max_entries) {
            $history = array_slice($history, -$max_entries);
        }

        $history = array_values(array_map(
            static function ($entry) {
                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                $value = isset($entry['server_processing_ms']) ? (float) $entry['server_processing_ms'] : 0.0;

                return [
                    'timestamp'            => max(0, $timestamp),
                    'server_processing_ms' => max(0.0, $value),
                ];
            },
            array_filter(
                $history,
                static function ($entry) {
                    if (!is_array($entry)) {
                        return false;
                    }

                    if (!isset($entry['timestamp'], $entry['server_processing_ms'])) {
                        return false;
                    }

                    return is_numeric($entry['timestamp']) && is_numeric($entry['server_processing_ms']);
                }
            )
        ));

        update_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, $history, false);
    }

    if (empty($sitepulse_plugin_impact_tracker_samples) || !is_array($sitepulse_plugin_impact_tracker_samples)) {
        return;
    }

    $option_key = SITEPULSE_PLUGIN_IMPACT_OPTION;
    $existing = get_option($option_key, []);
    $now = current_time('timestamp');

    if (!is_array($existing)) {
        $existing = [];
    }

    $interval = apply_filters('sitepulse_plugin_impact_refresh_interval', SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL);

    if (!is_scalar($interval)) {
        $interval = 0;
    }

    $interval = absint($interval);
    $interval = max(1, $interval);
    $last_updated = isset($existing['last_updated']) ? (int) $existing['last_updated'] : 0;

    if (!$sitepulse_plugin_impact_tracker_force_persist && ($now - $last_updated) < $interval) {
        return;
    }

    $samples = isset($existing['samples']) && is_array($existing['samples']) ? $existing['samples'] : [];

    foreach ($sitepulse_plugin_impact_tracker_samples as $plugin_file => $duration) {
        if (!is_string($plugin_file) || $plugin_file === '') {
            continue;
        }

        $plugin_file = (string) $plugin_file;
        $milliseconds = max(0.0, (float) $duration * 1000);
        $plugin_name = sitepulse_plugin_impact_get_plugin_name($plugin_file, $samples);

        if (isset($samples[$plugin_file]) && is_array($samples[$plugin_file])) {
            $count = isset($samples[$plugin_file]['samples']) ? max(1, (int) $samples[$plugin_file]['samples']) : 1;
            $average = isset($samples[$plugin_file]['avg_ms']) ? (float) $samples[$plugin_file]['avg_ms'] : $milliseconds;
            $new_count = $count + 1;
            $samples[$plugin_file]['avg_ms'] = ($average * $count + $milliseconds) / $new_count;
            $samples[$plugin_file]['samples'] = $new_count;
            $samples[$plugin_file]['last_ms'] = $milliseconds;
            $samples[$plugin_file]['name'] = $plugin_name;
            $samples[$plugin_file]['last_recorded'] = $now;
        } else {
            $samples[$plugin_file] = [
                'file'          => $plugin_file,
                'name'          => $plugin_name,
                'avg_ms'        => $milliseconds,
                'last_ms'       => $milliseconds,
                'samples'       => 1,
                'last_recorded' => $now,
            ];
        }
    }

    $active_plugins_option = get_option('active_plugins');
    $active_plugins = is_array($active_plugins_option) ? array_map('strval', $active_plugins_option) : [];
    $network_plugins_option = is_multisite() ? get_site_option('active_sitewide_plugins') : false;
    $active_sitewide_plugins = is_array($network_plugins_option) ? array_map('strval', array_keys($network_plugins_option)) : [];
    $all_active_plugins = array_unique(array_merge($active_plugins, $active_sitewide_plugins));

    if (is_array($active_plugins_option) || is_array($network_plugins_option)) {
        $normalized_samples = [];

        foreach ($samples as $plugin_file => $data) {
            $normalized_plugin_file = is_string($plugin_file) ? $plugin_file : (string) $plugin_file;

            if (!in_array($normalized_plugin_file, $all_active_plugins, true)) {
                continue;
            }

            if (!is_array($data)) {
                $data = [];
            }

            $data['file'] = isset($data['file']) ? (string) $data['file'] : $normalized_plugin_file;
            $normalized_samples[$normalized_plugin_file] = $data;
        }

        $samples = $normalized_samples;
    }

    $payload = [
        'last_updated' => $now,
        'interval'     => $interval,
        'samples'      => $samples,
    ];

    sitepulse_plugin_impact_tracker_update_history($samples, $now);

    update_option($option_key, $payload, false);
}

/**
 * Forces the persistence of fresh measurements at the end of the current request.
 *
 * @param bool $reset_existing Whether to remove previous measurements.
 *
 * @return void
 */
function sitepulse_plugin_impact_force_next_persist($reset_existing = false) {
    global $sitepulse_plugin_impact_tracker_force_persist;

    $sitepulse_plugin_impact_tracker_force_persist = true;

    if ($reset_existing) {
        delete_option(SITEPULSE_PLUGIN_IMPACT_OPTION);
    }
}
