<?php
if (!defined('ABSPATH')) exit;

if (!defined('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK')) {
    define('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK', 'sitepulse_uptime_failure_streak');
}

if (!defined('SITEPULSE_OPTION_UPTIME_ARCHIVE')) {
    define('SITEPULSE_OPTION_UPTIME_ARCHIVE', 'sitepulse_uptime_archive');
}

add_action('init', 'sitepulse_uptime_tracker_boot');

add_filter('cron_schedules', 'sitepulse_uptime_tracker_register_cron_schedules');

add_action('admin_menu', function() {
    add_submenu_page('sitepulse-dashboard', 'Uptime Tracker', 'Uptime', sitepulse_get_capability(), 'sitepulse-uptime', 'sitepulse_uptime_tracker_page');
});

add_action('admin_enqueue_scripts', 'sitepulse_uptime_tracker_enqueue_assets');

/**
 * Enqueues the stylesheet required for the uptime tracker admin page.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_uptime_tracker_enqueue_assets($hook_suffix) {
    if ($hook_suffix !== 'sitepulse-dashboard_page_sitepulse-uptime') {
        return;
    }

    wp_enqueue_style(
        'sitepulse-uptime-tracker',
        SITEPULSE_URL . 'modules/css/uptime-tracker.css',
        [],
        SITEPULSE_VERSION
    );
}

/**
 * Registers custom cron schedules used by the uptime tracker.
 *
 * @param array $schedules Existing schedules.
 * @return array Modified schedules including SitePulse intervals.
 */
function sitepulse_uptime_tracker_register_cron_schedules($schedules) {
    if (!is_array($schedules)) {
        $schedules = [];
    }

    $frequency_choices = function_exists('sitepulse_get_uptime_frequency_choices')
        ? sitepulse_get_uptime_frequency_choices()
        : [];

    foreach ($frequency_choices as $frequency_key => $frequency_data) {
        if (in_array($frequency_key, ['hourly', 'twicedaily', 'daily'], true)) {
            continue;
        }

        if (!is_array($frequency_data) || !isset($frequency_data['interval'])) {
            continue;
        }

        $interval = (int) $frequency_data['interval'];

        if ($interval < 1) {
            continue;
        }

        $display = isset($frequency_data['label']) && is_string($frequency_data['label'])
            ? $frequency_data['label']
            : ucfirst(str_replace('_', ' ', $frequency_key));

        $schedules[$frequency_key] = [
            'interval' => $interval,
            'display'  => $display,
        ];
    }

    return $schedules;
}

/**
 * Returns the cron hook name associated with a frequency identifier.
 *
 * @param string $frequency Frequency identifier.
 * @return string
 */
function sitepulse_get_uptime_cron_hook_for_frequency($frequency) {
    static $hooks = [];

    $frequency = sanitize_key((string) $frequency);

    if ($frequency === '') {
        $frequency = 'hourly';
    }

    if (!isset($hooks[$frequency])) {
        if (function_exists('sitepulse_get_cron_hook')) {
            $hooks[$frequency] = sitepulse_get_cron_hook('uptime_tracker_' . $frequency);
        }

        if (empty($hooks[$frequency])) {
            $hooks[$frequency] = 'sitepulse_uptime_tracker_cron_' . $frequency;
        }
    }

    return $hooks[$frequency];
}

/**
 * Registers cron hooks for each available frequency and ensures scheduling.
 *
 * @return void
 */
function sitepulse_uptime_tracker_boot() {
    static $booted = false;

    if ($booted) {
        return;
    }

    $booted = true;

    $frequency_choices = function_exists('sitepulse_get_uptime_frequency_choices')
        ? sitepulse_get_uptime_frequency_choices()
        : [];

    foreach (array_keys($frequency_choices) as $frequency_key) {
        $hook = sitepulse_get_uptime_cron_hook_for_frequency($frequency_key);

        add_action($hook, function () use ($frequency_key) {
            sitepulse_run_uptime_checks_for_frequency($frequency_key);
        });
    }

    sitepulse_uptime_tracker_ensure_cron();
}

/**
 * Ensures the uptime tracker cron hook is scheduled and reports failures.
 *
 * @return void
 */
function sitepulse_uptime_tracker_ensure_cron() {
    $frequency_choices = function_exists('sitepulse_get_uptime_frequency_choices')
        ? sitepulse_get_uptime_frequency_choices()
        : [];

    $targets = function_exists('sitepulse_get_uptime_targets')
        ? sitepulse_get_uptime_targets()
        : [];

    $frequencies_in_use = [];

    foreach ($targets as $target) {
        if (!is_array($target)) {
            continue;
        }

        $raw_frequency = isset($target['frequency']) ? $target['frequency'] : '';
        $frequency = function_exists('sitepulse_sanitize_uptime_frequency')
            ? sitepulse_sanitize_uptime_frequency($raw_frequency)
            : (is_string($raw_frequency) && $raw_frequency !== '' ? $raw_frequency : (defined('SITEPULSE_DEFAULT_UPTIME_FREQUENCY') ? SITEPULSE_DEFAULT_UPTIME_FREQUENCY : 'hourly'));

        $frequencies_in_use[$frequency] = true;
    }

    $all_frequency_keys = array_keys($frequency_choices);
    $available_schedules = wp_get_schedules();

    if (empty($frequencies_in_use)) {
        foreach ($all_frequency_keys as $frequency_key) {
            $hook = sitepulse_get_uptime_cron_hook_for_frequency($frequency_key);
            wp_clear_scheduled_hook($hook);
        }

        sitepulse_clear_cron_warning('uptime_tracker');

        return;
    }

    $scheduled_any = false;

    foreach ($frequencies_in_use as $frequency => $_unused) {
        $hook = sitepulse_get_uptime_cron_hook_for_frequency($frequency);
        $schedule_key = $frequency;

        if (!isset($available_schedules[$schedule_key])) {
            $schedule_key = defined('SITEPULSE_DEFAULT_UPTIME_FREQUENCY') ? SITEPULSE_DEFAULT_UPTIME_FREQUENCY : 'hourly';
        }

        if (!isset($available_schedules[$schedule_key]) && isset($available_schedules['hourly'])) {
            $schedule_key = 'hourly';
        }

        $current_schedule = wp_get_schedule($hook);

        if ($current_schedule && $current_schedule !== $schedule_key) {
            wp_clear_scheduled_hook($hook);
            $current_schedule = false;
        }

        if (!$current_schedule) {
            $next_run = (int) current_time('timestamp', true);
            $scheduled = wp_schedule_event($next_run, $schedule_key, $hook);

            if (false === $scheduled && function_exists('sitepulse_log')) {
                sitepulse_log(sprintf('Unable to schedule uptime tracker cron hook: %s', $hook), 'ERROR');
            }
        }

        if (wp_next_scheduled($hook)) {
            $scheduled_any = true;
        }
    }

    foreach ($all_frequency_keys as $frequency_key) {
        if (isset($frequencies_in_use[$frequency_key])) {
            continue;
        }

        $hook = sitepulse_get_uptime_cron_hook_for_frequency($frequency_key);
        wp_clear_scheduled_hook($hook);
    }

    if ($scheduled_any) {
        sitepulse_clear_cron_warning('uptime_tracker');
    } else {
        sitepulse_register_cron_warning(
            'uptime_tracker',
            __('SitePulse n’a pas pu planifier la vérification d’uptime. Vérifiez que WP-Cron est actif ou programmez manuellement la tâche.', 'sitepulse')
        );
    }
}
function sitepulse_normalize_uptime_log($log) {
    if (!is_array($log) || empty($log)) {
        return [];
    }

    $normalized = [];
    $count = count($log);
    $now = (int) current_time('timestamp');
    $approximate_start = $now - max(0, ($count - 1) * HOUR_IN_SECONDS);

    foreach (array_values($log) as $index => $entry) {
        $timestamp = $approximate_start + ($index * HOUR_IN_SECONDS);
        $status = null;
        $incident_start = null;
        $error_message = null;

        if (is_array($entry)) {
            if (isset($entry['timestamp']) && is_numeric($entry['timestamp'])) {
                $timestamp = (int) $entry['timestamp'];
            }

            if (array_key_exists('status', $entry)) {
                $status = $entry['status'];
            } else {
                $status = !empty($entry);
            }

            if (isset($entry['incident_start']) && is_numeric($entry['incident_start'])) {
                $incident_start = (int) $entry['incident_start'];
            }

            if (array_key_exists('error', $entry)) {
                $raw_error = $entry['error'];

                if (is_scalar($raw_error)) {
                    $error_message = (string) $raw_error;
                } elseif (null !== $raw_error) {
                    $encoded_error = wp_json_encode($raw_error);

                    if (false !== $encoded_error) {
                        $error_message = $encoded_error;
                    }
                }
            }
        } else {
            $status = (bool) (is_int($entry) ? $entry : !empty($entry));
        }

        if (is_bool($status)) {
            if (false === $status) {
                if (null === $incident_start) {
                    $previous_boolean_entry = null;

                    for ($i = count($normalized) - 1; $i >= 0; $i--) {
                        if (array_key_exists('status', $normalized[$i]) && is_bool($normalized[$i]['status'])) {
                            $previous_boolean_entry = $normalized[$i];
                            break;
                        }
                    }

                    if (null !== $previous_boolean_entry && false === $previous_boolean_entry['status'] && isset($previous_boolean_entry['incident_start'])) {
                        $incident_start = (int) $previous_boolean_entry['incident_start'];
                    }

                    if (null === $incident_start) {
                        $incident_start = $timestamp;
                    }
                }
            } else {
                $incident_start = null;
            }
        } else {
            $incident_start = null;
        }

        $normalized_entry = array_filter([
            'timestamp'      => $timestamp,
            'status'         => $status,
            'incident_start' => $incident_start,
            'error'          => $error_message,
        ], function ($value) {
            return null !== $value;
        });

        $normalized[] = $normalized_entry;
    }

    usort($normalized, function ($a, $b) {
        return $a['timestamp'] <=> $b['timestamp'];
    });

    return array_values($normalized);
}

/**
 * Retrieves the uptime log store, upgrading legacy formats to multi-target when required.
 *
 * @return array<string,array<int,array<string,mixed>>>
 */
function sitepulse_get_uptime_log_store() {
    $raw = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);

    if (!is_array($raw)) {
        $raw = empty($raw) ? [] : [$raw];
    }

    $is_multi_target = false;

    foreach ($raw as $key => $value) {
        if (!is_int($key)) {
            $is_multi_target = true;
            break;
        }
    }

    if ($is_multi_target) {
        $store = [];

        foreach ($raw as $target_id => $entries) {
            if (!is_array($entries)) {
                continue;
            }

            $store[$target_id] = array_values($entries);
        }

        return $store;
    }

    if (empty($raw)) {
        return [];
    }

    $targets = function_exists('sitepulse_get_uptime_targets') ? sitepulse_get_uptime_targets() : [];
    $target_id = !empty($targets) && isset($targets[0]['id']) ? $targets[0]['id'] : 'target_legacy';
    $store = [$target_id => array_values($raw)];
    update_option(SITEPULSE_OPTION_UPTIME_LOG, $store, false);

    return $store;
}

/**
 * Persists the uptime log store.
 *
 * @param array<string,array<int,array<string,mixed>>> $store Normalized log store.
 * @return void
 */
function sitepulse_save_uptime_log_store($store) {
    if (!is_array($store)) {
        $store = [];
    }

    update_option(SITEPULSE_OPTION_UPTIME_LOG, $store, false);
}

/**
 * Retrieves the normalized log entries for a specific target.
 *
 * @param string $target_id Target identifier.
 * @return array<int,array<string,mixed>>
 */
function sitepulse_get_uptime_log_for_target($target_id) {
    $target_id = sanitize_key((string) $target_id);

    if ($target_id === '') {
        return [];
    }

    $store = sitepulse_get_uptime_log_store();

    if (!isset($store[$target_id])) {
        return [];
    }

    return sitepulse_normalize_uptime_log($store[$target_id]);
}

/**
 * Appends an uptime entry to the log store for the provided target.
 *
 * @param string $target_id Target identifier.
 * @param array  $entry     Log entry.
 * @return void
 */
function sitepulse_append_uptime_log_entry($target_id, $entry) {
    $target_id = sanitize_key((string) $target_id);

    if ($target_id === '' || !is_array($entry)) {
        return;
    }

    $store = sitepulse_get_uptime_log_store();

    if (!isset($store[$target_id]) || !is_array($store[$target_id])) {
        $store[$target_id] = [];
    }

    $store[$target_id][] = $entry;

    if (count($store[$target_id]) > 30) {
        $store[$target_id] = array_slice($store[$target_id], -30);
    }

    sitepulse_save_uptime_log_store($store);
}

/**
 * Retrieves the failure streak store for uptime targets.
 *
 * @return array<string,int>
 */
function sitepulse_get_uptime_failure_streaks() {
    $raw = get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, []);

    if (is_array($raw)) {
        $streaks = [];

        foreach ($raw as $target_id => $value) {
            $target_id = sanitize_key((string) $target_id);

            if ($target_id === '') {
                continue;
            }

            $streaks[$target_id] = max(0, (int) $value);
        }

        return $streaks;
    }

    $value = (int) $raw;

    if ($value <= 0) {
        return [];
    }

    $targets = function_exists('sitepulse_get_uptime_targets') ? sitepulse_get_uptime_targets() : [];
    $target_id = !empty($targets) && isset($targets[0]['id']) ? $targets[0]['id'] : 'target_legacy';
    $streaks = [$target_id => $value];
    update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, $streaks, false);

    return $streaks;
}

/**
 * Persists the map of failure streaks.
 *
 * @param array<string,int> $streaks Map of target IDs to failure streak counts.
 * @return void
 */
function sitepulse_save_uptime_failure_streaks($streaks) {
    if (!is_array($streaks)) {
        $streaks = [];
    }

    update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, $streaks, false);
}

/**
 * Retrieves the current failure streak for a target.
 *
 * @param string $target_id Target identifier.
 * @return int
 */
function sitepulse_get_uptime_failure_streak($target_id) {
    $target_id = sanitize_key((string) $target_id);

    if ($target_id === '') {
        return 0;
    }

    $streaks = sitepulse_get_uptime_failure_streaks();

    return isset($streaks[$target_id]) ? (int) $streaks[$target_id] : 0;
}

/**
 * Increments and returns the failure streak count for a target.
 *
 * @param string $target_id Target identifier.
 * @return int Updated streak value.
 */
function sitepulse_increment_uptime_failure_streak($target_id) {
    $target_id = sanitize_key((string) $target_id);

    if ($target_id === '') {
        return 0;
    }

    $streaks = sitepulse_get_uptime_failure_streaks();
    $streaks[$target_id] = isset($streaks[$target_id]) ? (int) $streaks[$target_id] + 1 : 1;
    sitepulse_save_uptime_failure_streaks($streaks);

    return (int) $streaks[$target_id];
}

/**
 * Resets the failure streak counter for a target.
 *
 * @param string $target_id Target identifier.
 * @return void
 */
function sitepulse_reset_uptime_failure_streak($target_id) {
    $target_id = sanitize_key((string) $target_id);

    if ($target_id === '') {
        return;
    }

    $streaks = sitepulse_get_uptime_failure_streaks();

    if (isset($streaks[$target_id])) {
        unset($streaks[$target_id]);
        sitepulse_save_uptime_failure_streaks($streaks);
    }
}

/**
 * Retrieves the persisted uptime archive ordered by day for all targets.
 *
 * @return array<string,array<string,array<string,int>>>
 */
function sitepulse_get_uptime_archive() {
    $raw = get_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, []);

    if (!is_array($raw)) {
        return [];
    }

    $is_multi_target = false;

    foreach ($raw as $key => $value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $key)) {
            $is_multi_target = true;
            break;
        }
    }

    if ($is_multi_target) {
        $archive = [];

        foreach ($raw as $target_id => $days) {
            if (!is_array($days)) {
                continue;
            }

            ksort($days);
            $archive[$target_id] = $days;
        }

        return $archive;
    }

    $targets = function_exists('sitepulse_get_uptime_targets') ? sitepulse_get_uptime_targets() : [];
    $target_id = !empty($targets) && isset($targets[0]['id']) ? $targets[0]['id'] : 'target_legacy';
    $converted = [$target_id => []];

    foreach ($raw as $day_key => $payload) {
        if (is_array($payload)) {
            $converted[$target_id][$day_key] = $payload;
        }
    }

    ksort($converted[$target_id]);
    update_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, $converted, false);

    return $converted;
}

/**
 * Retrieves the daily archive for a specific target.
 *
 * @param string $target_id Target identifier.
 * @return array<string,array<string,int>>
 */
function sitepulse_get_uptime_archive_for_target($target_id) {
    $target_id = sanitize_key((string) $target_id);

    if ($target_id === '') {
        return [];
    }

    $archive = sitepulse_get_uptime_archive();

    if (!isset($archive[$target_id]) || !is_array($archive[$target_id])) {
        return [];
    }

    ksort($archive[$target_id]);

    return $archive[$target_id];
}

/**
 * Stores the provided log entry inside the daily uptime archive.
 *
 * @param string $target_id Target identifier.
 * @param array  $entry     Normalized uptime entry.
 * @return void
 */
function sitepulse_update_uptime_archive($target_id, $entry = null) {
    if (is_array($target_id) && null === $entry) {
        $entry = $target_id;
        $targets = function_exists('sitepulse_get_uptime_targets') ? sitepulse_get_uptime_targets() : [];
        $target_id = !empty($targets) && isset($targets[0]['id']) ? $targets[0]['id'] : 'target_legacy';
    }

    $target_id = sanitize_key((string) $target_id);

    if ($target_id === '' || !is_array($entry) || empty($entry)) {
        return;
    }

    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : (int) current_time('timestamp');
    $day_key = wp_date('Y-m-d', $timestamp);

    $status_key = 'unknown';

    if (array_key_exists('status', $entry)) {
        if (true === $entry['status']) {
            $status_key = 'up';
        } elseif (false === $entry['status']) {
            $status_key = 'down';
        } elseif (is_string($entry['status']) && 'unknown' === $entry['status']) {
            $status_key = 'unknown';
        }
    }

    $archive = sitepulse_get_uptime_archive();

    if (!isset($archive[$target_id]) || !is_array($archive[$target_id])) {
        $archive[$target_id] = [];
    }

    if (!isset($archive[$target_id][$day_key]) || !is_array($archive[$target_id][$day_key])) {
        $archive[$target_id][$day_key] = [
            'date'            => $day_key,
            'up'              => 0,
            'down'            => 0,
            'unknown'         => 0,
            'total'           => 0,
            'first_timestamp' => $timestamp,
            'last_timestamp'  => $timestamp,
        ];
    }

    if (!isset($archive[$target_id][$day_key][$status_key])) {
        $archive[$target_id][$day_key][$status_key] = 0;
    }

    $archive[$target_id][$day_key][$status_key]++;
    $archive[$target_id][$day_key]['total']++;

    $archive[$target_id][$day_key]['first_timestamp'] = isset($archive[$target_id][$day_key]['first_timestamp'])
        ? min((int) $archive[$target_id][$day_key]['first_timestamp'], $timestamp)
        : $timestamp;
    $archive[$target_id][$day_key]['last_timestamp'] = isset($archive[$target_id][$day_key]['last_timestamp'])
        ? max((int) $archive[$target_id][$day_key]['last_timestamp'], $timestamp)
        : $timestamp;

    if (count($archive[$target_id]) > 120) {
        $archive[$target_id] = array_slice($archive[$target_id], -120, null, true);
    }

    update_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, $archive, false);
}

/**
 * Calculates aggregate metrics for the requested archive window.
 *
 * @param array<string,array<string,int>> $archive Archive of daily totals.
 * @param int                             $days    Number of days to include.
 * @return array<string,int|float>
 */
function sitepulse_calculate_uptime_window_metrics($archive, $days) {
    if (!is_array($archive) || empty($archive) || $days < 1) {
        return [
            'days'           => 0,
            'total_checks'   => 0,
            'up_checks'      => 0,
            'down_checks'    => 0,
            'unknown_checks' => 0,
            'uptime'         => 100.0,
        ];
    }

    $window = array_slice($archive, -$days, null, true);

    $totals = [
        'days'           => count($window),
        'total_checks'   => 0,
        'up_checks'      => 0,
        'down_checks'    => 0,
        'unknown_checks' => 0,
        'uptime'         => 100.0,
    ];

    foreach ($window as $entry) {
        $totals['total_checks'] += isset($entry['total']) ? (int) $entry['total'] : 0;
        $totals['up_checks'] += isset($entry['up']) ? (int) $entry['up'] : 0;
        $totals['down_checks'] += isset($entry['down']) ? (int) $entry['down'] : 0;
        $totals['unknown_checks'] += isset($entry['unknown']) ? (int) $entry['unknown'] : 0;
    }

    if ($totals['total_checks'] > 0) {
        $totals['uptime'] = ($totals['up_checks'] / $totals['total_checks']) * 100;
    }

    return $totals;
}

function sitepulse_uptime_tracker_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $targets = function_exists('sitepulse_get_uptime_targets') ? sitepulse_get_uptime_targets() : [];
    $frequency_choices = sitepulse_get_uptime_frequency_choices();
    $date_format = get_option('date_format');
    $time_format = get_option('time_format');
    $now = (int) current_time('timestamp');

    $default_uptime_warning = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0;

    if (function_exists('sitepulse_get_uptime_warning_percentage')) {
        $uptime_warning_threshold = (float) sitepulse_get_uptime_warning_percentage();
    } else {
        $uptime_warning_option = get_option(SITEPULSE_OPTION_UPTIME_WARNING_PERCENT, $default_uptime_warning);
        $uptime_warning_threshold = is_scalar($uptime_warning_option) ? (float) $uptime_warning_option : $default_uptime_warning;
    }

    if ($uptime_warning_threshold < 0) {
        $uptime_warning_threshold = 0.0;
    } elseif ($uptime_warning_threshold > 100) {
        $uptime_warning_threshold = 100.0;
    }

    $status_labels = [
        'status-ok'      => __('Opérationnel', 'sitepulse'),
        'status-warn'    => __('Dégradé', 'sitepulse'),
        'status-bad'     => __('Incident', 'sitepulse'),
        'status-unknown' => __('Inconnu', 'sitepulse'),
    ];

    $target_reports = [];

    foreach ($targets as $target) {
        if (!is_array($target)) {
            continue;
        }

        $target_id = isset($target['id']) ? sanitize_key((string) $target['id']) : '';

        if ($target_id === '') {
            $target_id = sanitize_key(sitepulse_generate_uptime_target_id(microtime(true)));
        }

        $target_url = isset($target['url']) ? (string) $target['url'] : '';
        $display_url = $target_url !== '' ? esc_url($target_url) : esc_url(home_url('/'));
        $target_label = isset($target['label']) && is_string($target['label']) && $target['label'] !== '' ? $target['label'] : $display_url;
        $alerts_enabled = !empty($target['alerts']);
        $frequency_key = isset($target['frequency']) ? $target['frequency'] : '';
        $frequency_key = function_exists('sitepulse_sanitize_uptime_frequency') ? sitepulse_sanitize_uptime_frequency($frequency_key) : $frequency_key;
        $frequency_label = isset($frequency_choices[$frequency_key]['label']) ? $frequency_choices[$frequency_key]['label'] : $frequency_key;

        $log = sitepulse_get_uptime_log_for_target($target_id);
        $recent_log = array_slice($log, -30);
        $total_checks = count($log);
        $recent_total = count($recent_log);
        $boolean_checks = array_values(array_filter($log, function ($entry) {
            return isset($entry['status']) && is_bool($entry['status']);
        }));
        $evaluated_checks = count($boolean_checks);
        $up_checks = count(array_filter($boolean_checks, function ($entry) {
            return isset($entry['status']) && true === $entry['status'];
        }));
        $uptime_percentage = $evaluated_checks > 0 ? ($up_checks / max(1, $evaluated_checks)) * 100 : 100;

        $status = 'status-ok';
        $current_incident_start = null;
        $current_incident_duration = '';
        $last_check_time = null;
        $last_status = null;

        if (!empty($log)) {
            $last_entry = end($log);
            $last_status = $last_entry['status'] ?? null;
            $last_check_time = isset($last_entry['timestamp']) ? (int) $last_entry['timestamp'] : null;

            if (isset($last_entry['status']) && is_bool($last_entry['status']) && false === $last_entry['status']) {
                $current_incident_start = isset($last_entry['incident_start']) ? (int) $last_entry['incident_start'] : (isset($last_entry['timestamp']) ? (int) $last_entry['timestamp'] : $now);
                $current_incident_duration = human_time_diff($current_incident_start, $now);
            }

            reset($log);
        }

        if ($last_status === false) {
            $status = 'status-bad';
        } elseif ($uptime_percentage < $uptime_warning_threshold) {
            $status = 'status-bad';
        } elseif ($uptime_percentage < 100) {
            $status = 'status-warn';
        } elseif ($total_checks === 0) {
            $status = 'status-unknown';
        }

        $archive = sitepulse_get_uptime_archive_for_target($target_id);
        $trend_entries = array_slice($archive, -30, null, true);
        $trend_data = [];

        foreach ($trend_entries as $day_key => $daily_entry) {
            $total = isset($daily_entry['total']) ? max(0, (int) $daily_entry['total']) : 0;
            $up = isset($daily_entry['up']) ? (int) $daily_entry['up'] : 0;
            $uptime_value = $total > 0 ? ($up / $total) * 100 : 100;
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

        $seven_day_metrics = sitepulse_calculate_uptime_window_metrics($archive, 7);
        $thirty_day_metrics = sitepulse_calculate_uptime_window_metrics($archive, 30);

        $target_reports[] = [
            'id'                        => $target_id,
            'target'                    => $target,
            'display_url'               => $display_url,
            'display_name'              => $target_label,
            'frequency_label'           => $frequency_label,
            'alerts_enabled'            => $alerts_enabled,
            'log'                       => $recent_log,
            'total_checks'              => $total_checks,
            'recent_total'              => $recent_total,
            'uptime_percentage'         => $uptime_percentage,
            'status'                    => $status,
            'status_label'              => isset($status_labels[$status]) ? $status_labels[$status] : $status,
            'current_incident_start'    => $current_incident_start,
            'current_incident_duration' => $current_incident_duration,
            'trend_data'                => $trend_data,
            'seven_day_metrics'         => $seven_day_metrics,
            'thirty_day_metrics'        => $thirty_day_metrics,
            'last_check_time'           => $last_check_time,
            'last_status'               => $last_status,
        ];
    }

    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-uptime');
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-chart-bar"></span> Suivi de Disponibilité</h1>
        <?php if (empty($target_reports)) : ?>
            <p><?php esc_html_e('Aucune cible n’est configurée pour le suivi de disponibilité. Ajoutez des URL depuis les réglages de SitePulse.', 'sitepulse'); ?></p>
        <?php else : ?>
            <p><?php esc_html_e('Cet outil vérifie la disponibilité de chaque cible selon la fréquence choisie. Consultez ci-dessous le statut de vos endpoints.', 'sitepulse'); ?></p>
            <ul class="sitepulse-uptime-status-list">
                <?php foreach ($target_reports as $report) : ?>
                    <li>
                        <span class="status-badge <?php echo esc_attr($report['status']); ?>">
                            <span class="status-text"><?php echo esc_html($report['status_label']); ?></span>
                        </span>
                        <strong><?php echo esc_html($report['display_name']); ?></strong>
                        <span class="sitepulse-uptime-status-meta">
                            <?php echo esc_html(sprintf(__('Fréquence : %s', 'sitepulse'), $report['frequency_label'])); ?> ·
                            <?php echo esc_html($report['alerts_enabled'] ? __('Alertes activées', 'sitepulse') : __('Alertes désactivées', 'sitepulse')); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php foreach ($target_reports as $report) :
                $log = $report['log'];
                $recent_total = $report['recent_total'];
                $total_checks = $report['total_checks'];
                ?>
                <section class="sitepulse-uptime-target-report" id="sitepulse-uptime-<?php echo esc_attr($report['id']); ?>">
                    <h2>
                        <span class="status-badge <?php echo esc_attr($report['status']); ?>">
                            <span class="status-text"><?php echo esc_html($report['status_label']); ?></span>
                        </span>
                        <?php echo esc_html($report['display_name']); ?>
                    </h2>
                    <p class="sitepulse-uptime-target-meta">
                        <strong><?php esc_html_e('URL', 'sitepulse'); ?>:</strong> <a href="<?php echo esc_url($report['display_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($report['display_url']); ?></a><br>
                        <strong><?php esc_html_e('Fréquence', 'sitepulse'); ?>:</strong> <?php echo esc_html($report['frequency_label']); ?><br>
                        <strong><?php esc_html_e('Alertes e-mail', 'sitepulse'); ?>:</strong> <?php echo esc_html($report['alerts_enabled'] ? __('activées', 'sitepulse') : __('désactivées', 'sitepulse')); ?>
                    </p>
                    <div class="uptime-summary-grid">
                        <div class="uptime-summary-card">
                            <h3><?php esc_html_e('Disponibilité 7 derniers jours', 'sitepulse'); ?></h3>
                            <p class="uptime-summary-card__value"><?php echo esc_html(number_format_i18n($report['seven_day_metrics']['uptime'], 2)); ?>%</p>
                            <p class="uptime-summary-card__meta"><?php
                                printf(
                                    /* translators: 1: total checks, 2: incidents */
                                    esc_html__('Sur %1$s contrôles (%2$s incidents)', 'sitepulse'),
                                    esc_html(number_format_i18n($report['seven_day_metrics']['total_checks'])),
                                    esc_html(number_format_i18n($report['seven_day_metrics']['down_checks']))
                                );
                            ?></p>
                        </div>
                        <div class="uptime-summary-card">
                            <h3><?php esc_html_e('Disponibilité 30 derniers jours', 'sitepulse'); ?></h3>
                            <p class="uptime-summary-card__value"><?php echo esc_html(number_format_i18n($report['thirty_day_metrics']['uptime'], 2)); ?>%</p>
                            <p class="uptime-summary-card__meta"><?php
                                printf(
                                    /* translators: 1: total checks, 2: incidents */
                                    esc_html__('Sur %1$s contrôles (%2$s incidents)', 'sitepulse'),
                                    esc_html(number_format_i18n($report['thirty_day_metrics']['total_checks'])),
                                    esc_html(number_format_i18n($report['thirty_day_metrics']['down_checks']))
                                );
                            ?></p>
                        </div>
                    </div>
                    <div class="uptime-chart">
                        <?php if (empty($log)) : ?>
                            <p><?php esc_html_e('Aucune donnée de disponibilité pour cette cible. La première vérification sera effectuée prochainement.', 'sitepulse'); ?></p>
                        <?php else : ?>
                            <?php foreach ($log as $index => $entry) :
                                $status = $entry['status'] ?? null;
                                $bar_class = 'unknown';

                                if (true === $status) {
                                    $bar_class = 'up';
                                } elseif (false === $status) {
                                    $bar_class = 'down';
                                }

                                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                                $check_time = $timestamp > 0 ? date_i18n($date_format . ' ' . $time_format, $timestamp) : __('Horodatage inconnu', 'sitepulse');
                                $previous_entry = $index > 0 ? $log[$index - 1] : null;
                                $next_entry = ($index + 1) < $recent_total ? $log[$index + 1] : null;

                                $status_label = '';
                                $duration_label = '';
                                $bar_title = '';

                                if (true === $status) {
                                    $bar_title = sprintf(__('Cible disponible lors du contrôle du %s.', 'sitepulse'), $check_time);
                                    $status_label = __('Statut : disponible.', 'sitepulse');

                                    if (!empty($previous_entry) && isset($previous_entry['status']) && is_bool($previous_entry['status']) && false === $previous_entry['status']) {
                                        $incident_start = isset($previous_entry['incident_start']) ? (int) $previous_entry['incident_start'] : (isset($previous_entry['timestamp']) ? (int) $previous_entry['timestamp'] : 0);

                                        if ($incident_start > 0 && $timestamp >= $incident_start) {
                                            $incident_start_formatted = date_i18n($date_format . ' ' . $time_format, $incident_start);
                                            $incident_duration = human_time_diff($incident_start, $timestamp);
                                            $bar_title .= ' ' . sprintf(__('Retour à la normale après un incident débuté le %1$s (durée : %2$s).', 'sitepulse'), $incident_start_formatted, $incident_duration);
                                            $duration_label = sprintf(__('Durée de l’incident résolu : %s.', 'sitepulse'), $incident_duration);
                                        }
                                    }

                                    if ('' === $duration_label) {
                                        $duration_label = __('Durée : disponibilité confirmée lors de ce contrôle.', 'sitepulse');
                                    }
                                } elseif (false === $status) {
                                    $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : $timestamp;
                                    $incident_start_formatted = $incident_start > 0 ? date_i18n($date_format . ' ' . $time_format, $incident_start) : __('horodatage inconnu', 'sitepulse');
                                    $bar_title = sprintf(__('Cible indisponible lors du contrôle du %1$s. Incident commencé le %2$s.', 'sitepulse'), $check_time, $incident_start_formatted);
                                    $status_label = __('Statut : indisponible.', 'sitepulse');

                                    if (array_key_exists('error', $entry)) {
                                        $error_detail = is_scalar($entry['error']) ? (string) $entry['error'] : wp_json_encode($entry['error']);

                                        if (!empty($error_detail)) {
                                            $bar_title .= ' ' . sprintf(__('Détails : %s.', 'sitepulse'), $error_detail);
                                        }
                                    }

                                    $is_transition = empty($previous_entry) || (isset($previous_entry['status']) && true === $previous_entry['status']);

                                    if ($index === $recent_total - 1 && !empty($report['current_incident_duration']) && null !== $report['current_incident_start']) {
                                        $bar_title .= ' ' . sprintf(__('Incident en cours depuis %s.', 'sitepulse'), $report['current_incident_duration']);
                                        $duration_label = sprintf(__('Durée de l’incident en cours : %s.', 'sitepulse'), $report['current_incident_duration']);
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
                    <div class="uptime-timeline__labels"><span><?php echo esc_html(sprintf(__('Il y a %d contrôles', 'sitepulse'), absint($recent_total))); ?></span><span><?php esc_html_e('Maintenant', 'sitepulse'); ?></span></div>

                    <?php if (!empty($report['current_incident_duration']) && null !== $report['current_incident_start']) : ?>
                        <div class="notice notice-error uptime-notice--error">
                            <p>
                                <strong><?php esc_html_e('Incident en cours', 'sitepulse'); ?> :</strong>
                                <?php
                                $incident_start_formatted = date_i18n($date_format . ' ' . $time_format, $report['current_incident_start']);
                                echo esc_html(
                                    sprintf(
                                        __('Cette cible est indisponible depuis le %1$s (%2$s).', 'sitepulse'),
                                        $incident_start_formatted,
                                        $report['current_incident_duration']
                                    )
                                );
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($report['trend_data'])) : ?>
                        <h3><?php esc_html_e('Tendance de disponibilité (30 jours)', 'sitepulse'); ?></h3>
                        <div class="uptime-trend" role="img" aria-label="<?php echo esc_attr(sprintf(__('Disponibilité quotidienne sur %d jours.', 'sitepulse'), count($report['trend_data']))); ?>">
                            <?php foreach ($report['trend_data'] as $bar) : ?>
                                <span class="uptime-trend__bar <?php echo esc_attr($bar['class']); ?>" style="height: <?php echo esc_attr($bar['height']); ?>%;" title="<?php echo esc_attr($bar['label']); ?>"></span>
                            <?php endforeach; ?>
                        </div>
                        <p class="uptime-trend__legend">
                            <span class="uptime-trend__legend-item uptime-trend__legend-item--high"><?php esc_html_e('≥ 99% de disponibilité', 'sitepulse'); ?></span>
                            <span class="uptime-trend__legend-item uptime-trend__legend-item--medium"><?php esc_html_e('95 – 98% de disponibilité', 'sitepulse'); ?></span>
                            <span class="uptime-trend__legend-item uptime-trend__legend-item--low"><?php esc_html_e('< 95% de disponibilité', 'sitepulse'); ?></span>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="notice notice-info uptime-notice--info"><p><strong><?php esc_html_e('Comment ça marche :', 'sitepulse'); ?></strong> <?php echo esc_html__('Une barre verte indique que la cible était en ligne. Une barre rouge indique un incident détecté.', 'sitepulse'); ?></p></div>
    </div>
    <?php
}
function sitepulse_prepare_uptime_request_config() {
    $default_timeout = defined('SITEPULSE_DEFAULT_UPTIME_TIMEOUT') ? (int) SITEPULSE_DEFAULT_UPTIME_TIMEOUT : 10;
    $timeout_option = get_option(SITEPULSE_OPTION_UPTIME_TIMEOUT, $default_timeout);
    $timeout = (is_numeric($timeout_option) ? (int) $timeout_option : $default_timeout);

    if ($timeout < 1) {
        $timeout = $default_timeout;
    }

    $default_method = defined('SITEPULSE_DEFAULT_UPTIME_HTTP_METHOD') ? SITEPULSE_DEFAULT_UPTIME_HTTP_METHOD : 'GET';
    $method_option = get_option(SITEPULSE_OPTION_UPTIME_HTTP_METHOD, $default_method);
    $http_method = function_exists('sitepulse_sanitize_uptime_http_method')
        ? sitepulse_sanitize_uptime_http_method($method_option)
        : (is_string($method_option) && $method_option !== '' ? strtoupper($method_option) : $default_method);

    $headers_option = get_option(SITEPULSE_OPTION_UPTIME_HTTP_HEADERS, []);
    $custom_headers = function_exists('sitepulse_sanitize_uptime_http_headers')
        ? sitepulse_sanitize_uptime_http_headers($headers_option)
        : (is_array($headers_option) ? $headers_option : []);

    $expected_codes_option = get_option(SITEPULSE_OPTION_UPTIME_EXPECTED_CODES, []);
    $expected_codes = function_exists('sitepulse_sanitize_uptime_expected_codes')
        ? sitepulse_sanitize_uptime_expected_codes($expected_codes_option)
        : [];

    return [
        'timeout'        => $timeout,
        'http_method'    => $http_method,
        'custom_headers' => $custom_headers,
        'expected_codes' => $expected_codes,
    ];
}

function sitepulse_run_uptime_checks_for_frequency($frequency) {
    $targets = function_exists('sitepulse_get_uptime_targets') ? sitepulse_get_uptime_targets() : [];

    if (empty($targets)) {
        return;
    }

    $config = sitepulse_prepare_uptime_request_config();

    foreach ($targets as $target) {
        if (!is_array($target)) {
            continue;
        }

        $target_frequency = isset($target['frequency']) ? $target['frequency'] : '';
        $target_frequency = function_exists('sitepulse_sanitize_uptime_frequency')
            ? sitepulse_sanitize_uptime_frequency($target_frequency)
            : (is_string($target_frequency) && $target_frequency !== '' ? $target_frequency : (defined('SITEPULSE_DEFAULT_UPTIME_FREQUENCY') ? SITEPULSE_DEFAULT_UPTIME_FREQUENCY : 'hourly'));

        if ($target_frequency !== $frequency) {
            continue;
        }

        sitepulse_execute_uptime_check($target, $config);
    }
}

function sitepulse_run_uptime_check($target = null) {
    $config = sitepulse_prepare_uptime_request_config();

    if (is_array($target) && !empty($target)) {
        sitepulse_execute_uptime_check($target, $config);
        return;
    }

    $targets = function_exists('sitepulse_get_uptime_targets') ? sitepulse_get_uptime_targets() : [];

    foreach ($targets as $target_entry) {
        if (!is_array($target_entry)) {
            continue;
        }

        sitepulse_execute_uptime_check($target_entry, $config);
    }
}

/**
 * Executes the uptime check for a specific target.
 *
 * @param array $target Target configuration.
 * @param array $config Shared request configuration.
 * @return void
 */
function sitepulse_execute_uptime_check($target, $config) {
    $target = is_array($target) ? $target : [];
    $target_id = isset($target['id']) ? sanitize_key((string) $target['id']) : '';

    if ($target_id === '') {
        $seed = isset($target['url']) ? (string) $target['url'] : microtime(true);
        $target_id = sanitize_key(sitepulse_generate_uptime_target_id($seed));
    }

    $alerts_enabled = !empty($target['alerts']);
    $target_label = isset($target['label']) && is_string($target['label']) ? trim($target['label']) : '';

    $default_url = home_url('/');
    $target_url = isset($target['url']) ? trim((string) $target['url']) : '';
    $validated_target_url = $target_url !== '' ? wp_http_validate_url($target_url) : false;
    $request_url_default = $validated_target_url ? $validated_target_url : $default_url;

    $timeout = isset($config['timeout']) ? max(1, (int) $config['timeout']) : 10;
    $http_method = isset($config['http_method']) ? $config['http_method'] : 'GET';
    $custom_headers = isset($config['custom_headers']) && is_array($config['custom_headers']) ? $config['custom_headers'] : [];
    $expected_codes = isset($config['expected_codes']) ? (array) $config['expected_codes'] : [];

    $defaults = [
        'timeout'   => $timeout,
        'sslverify' => true,
        'url'       => $request_url_default,
        'method'    => $http_method,
        'headers'   => $custom_headers,
    ];

    $request_args = apply_filters('sitepulse_uptime_request_args', $defaults, $target);

    if (!is_array($request_args)) {
        $request_args = $defaults;
    }

    $request_url = isset($request_args['url']) ? $request_args['url'] : $defaults['url'];

    if (!is_string($request_url) || $request_url === '') {
        $request_url = $defaults['url'];
    } else {
        $validated_request_url = wp_http_validate_url($request_url);

        if ($validated_request_url) {
            $request_url = $validated_request_url;
        } else {
            $request_url = $defaults['url'];
        }
    }

    unset($request_args['url']);

    if (isset($request_args['expected_codes'])) {
        $candidate_codes = $request_args['expected_codes'];

        if (function_exists('sitepulse_sanitize_uptime_expected_codes')) {
            $expected_codes = sitepulse_sanitize_uptime_expected_codes($candidate_codes);
        } elseif (is_array($candidate_codes)) {
            $expected_codes = array_map('intval', $candidate_codes);
        }

        unset($request_args['expected_codes']);
    }

    if (isset($request_args['method'])) {
        $request_args['method'] = function_exists('sitepulse_sanitize_uptime_http_method')
            ? sitepulse_sanitize_uptime_http_method($request_args['method'])
            : (is_string($request_args['method']) && $request_args['method'] !== '' ? strtoupper($request_args['method']) : $http_method);
    } else {
        $request_args['method'] = $http_method;
    }

    if (isset($request_args['headers'])) {
        $request_args['headers'] = function_exists('sitepulse_sanitize_uptime_http_headers')
            ? sitepulse_sanitize_uptime_http_headers($request_args['headers'])
            : (is_array($request_args['headers']) ? $request_args['headers'] : []);
    } else {
        $request_args['headers'] = $custom_headers;
    }

    if (empty($request_args['headers'])) {
        unset($request_args['headers']);
    }

    $response = wp_remote_request($request_url, $request_args);
    $timestamp = (int) current_time('timestamp');

    $entry = [
        'timestamp' => $timestamp,
        'target_id' => $target_id,
        'url'       => esc_url_raw($request_url),
    ];

    if ($target_label !== '') {
        $entry['label'] = $target_label;
    }

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        $entry['status'] = 'unknown';

        if (!empty($error_message)) {
            $entry['error'] = $error_message;
        }

        $failure_streak = sitepulse_increment_uptime_failure_streak($target_id);
        $default_threshold = 3;
        $threshold = (int) apply_filters('sitepulse_uptime_consecutive_failures', $default_threshold, $failure_streak, $response, $request_url, $request_args, $target);
        $threshold = max(1, $threshold);

        $log_level = $failure_streak >= $threshold ? 'ALERT' : 'WARNING';

        if (!$alerts_enabled && 'ALERT' === $log_level) {
            $log_level = 'WARNING';
        }

        $log_message = sprintf('Uptime check [%1$s]: network error (%2$d/%3$d)%4$s',
            $request_url,
            $failure_streak,
            $threshold,
            !empty($error_message) ? ' - ' . $error_message : ''
        );

        sitepulse_append_uptime_log_entry($target_id, $entry);
        sitepulse_update_uptime_archive($target_id, $entry);
        sitepulse_log($log_message, $log_level);

        return;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $is_up = $response_code >= 200 && $response_code < 400;

    if (!empty($expected_codes)) {
        $is_up = in_array((int) $response_code, $expected_codes, true);
    }

    $existing_log = sitepulse_get_uptime_log_for_target($target_id);

    if ($is_up) {
        sitepulse_reset_uptime_failure_streak($target_id);
    } else {
        sitepulse_increment_uptime_failure_streak($target_id);
    }

    $entry['status'] = $is_up;

    if (!$is_up) {
        $incident_start = $timestamp;

        if (!empty($existing_log)) {
            for ($i = count($existing_log) - 1; $i >= 0; $i--) {
                if (!isset($existing_log[$i]['status']) || !is_bool($existing_log[$i]['status'])) {
                    continue;
                }

                if (false === $existing_log[$i]['status']) {
                    if (isset($existing_log[$i]['incident_start'])) {
                        $incident_start = (int) $existing_log[$i]['incident_start'];
                    } elseif (isset($existing_log[$i]['timestamp'])) {
                        $incident_start = (int) $existing_log[$i]['timestamp'];
                    }
                }

                break;
            }
        }

        $entry['incident_start'] = $incident_start;
        $entry['error'] = sprintf('HTTP %d', $response_code);
    }

    $log_message = $is_up
        ? sprintf('Uptime check [%s]: Up', $request_url)
        : sprintf('Uptime check [%s]: Down (HTTP %d)', $request_url, $response_code);

    $log_level = $is_up ? 'INFO' : 'ALERT';

    if (!$alerts_enabled && 'ALERT' === $log_level) {
        $log_level = 'WARNING';
    }

    sitepulse_append_uptime_log_entry($target_id, $entry);
    sitepulse_update_uptime_archive($target_id, $entry);
    sitepulse_log($log_message, $log_level);
}
