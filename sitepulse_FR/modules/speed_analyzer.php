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
add_action('init', 'sitepulse_speed_analyzer_bootstrap_cron');
add_filter('cron_schedules', 'sitepulse_speed_analyzer_register_cron_schedules');
add_action(sitepulse_speed_analyzer_get_cron_hook(), 'sitepulse_speed_analyzer_run_cron');
add_action(sitepulse_speed_analyzer_get_queue_hook(), 'sitepulse_speed_analyzer_run_queue');
add_action('admin_post_sitepulse_save_speed_schedule', 'sitepulse_speed_analyzer_handle_schedule_post');

/**
 * Returns the cron hook used for scheduled speed scans.
 *
 * @return string
 */
function sitepulse_speed_analyzer_get_cron_hook() {
    $default = 'sitepulse_speed_analyzer_cron';

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_speed_analyzer_cron_hook', $default);

        if (is_string($filtered) && $filtered !== '') {
            return $filtered;
        }
    }

    return $default;
}

/**
 * Returns the hook used to drain queued scans when rate limits are reached.
 *
 * @return string
 */
function sitepulse_speed_analyzer_get_queue_hook() {
    $default = 'sitepulse_speed_analyzer_queue';

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_speed_analyzer_queue_hook', $default);

        if (is_string($filtered) && $filtered !== '') {
            return $filtered;
        }
    }

    return $default;
}

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
 * Returns the available frequency choices for automated scans.
 *
 * @return array<string,string>
 */
function sitepulse_speed_analyzer_get_frequency_choices() {
    $choices = [
        'disabled'   => __('Désactivé', 'sitepulse'),
        'hourly'     => __('Toutes les heures', 'sitepulse'),
        'twicedaily' => __('Deux fois par jour', 'sitepulse'),
        'daily'      => __('Quotidien', 'sitepulse'),
        'sitepulse_weekly' => __('Hebdomadaire', 'sitepulse'),
    ];

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_speed_analyzer_frequency_choices', $choices);

        if (is_array($filtered)) {
            $choices = [];

            foreach ($filtered as $key => $label) {
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $choices[$key] = (string) $label;
            }
        }
    }

    return $choices;
}

/**
 * Normalizes a frequency slug for storage.
 *
 * @param mixed $frequency Selected value.
 *
 * @return string
 */
function sitepulse_speed_analyzer_sanitize_frequency($frequency) {
    $frequency = is_string($frequency) ? strtolower($frequency) : '';
    $choices = sitepulse_speed_analyzer_get_frequency_choices();

    if ($frequency === '' || !isset($choices[$frequency])) {
        return 'disabled';
    }

    return $frequency;
}

/**
 * Registers the additional cron schedule used by the speed analyzer.
 *
 * @param array<string,array> $schedules Existing schedules.
 *
 * @return array<string,array>
 */
function sitepulse_speed_analyzer_register_cron_schedules($schedules) {
    if (!is_array($schedules)) {
        $schedules = [];
    }

    $schedules['sitepulse_weekly'] = [
        'interval' => WEEK_IN_SECONDS,
        'display'  => __('Toutes les semaines', 'sitepulse'),
    ];

    return $schedules;
}

/**
 * Retrieves the default automation presets.
 *
 * @return array<string,array<string,string>>
 */
function sitepulse_speed_analyzer_get_default_presets() {
    $defaults = [
        'front' => [
            'label'  => __('Front-office', 'sitepulse'),
            'url'    => home_url('/'),
            'method' => 'GET',
        ],
        'critical' => [
            'label'  => __('Page critique', 'sitepulse'),
            'url'    => home_url('/'),
            'method' => 'GET',
        ],
        'api' => [
            'label'  => __('API', 'sitepulse'),
            'url'    => home_url('/wp-json/'),
            'method' => 'GET',
        ],
    ];

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_speed_analyzer_default_presets', $defaults);

        if (is_array($filtered)) {
            $defaults = [];

            foreach ($filtered as $key => $preset) {
                if (!is_string($key) || $key === '') {
                    continue;
                }

                if (!is_array($preset)) {
                    continue;
                }

                $defaults[$key] = $preset;
            }
        }
    }

    return $defaults;
}

/**
 * Retrieves the automation configuration.
 *
 * @return array{frequency:string,presets:array<string,array<string,string>>}
 */
function sitepulse_speed_analyzer_get_automation_settings() {
    $stored = get_option(SITEPULSE_OPTION_SPEED_AUTOMATION_CONFIG, []);
    $defaults = [
        'frequency' => 'disabled',
        'presets'   => sitepulse_speed_analyzer_get_default_presets(),
    ];

    if (!is_array($stored)) {
        $stored = [];
    }

    $frequency = isset($stored['frequency']) ? sitepulse_speed_analyzer_sanitize_frequency($stored['frequency']) : 'disabled';
    $presets = isset($stored['presets']) && is_array($stored['presets']) ? $stored['presets'] : [];

    $normalized_presets = [];

    foreach ($presets as $key => $preset) {
        if (!is_string($key) || $key === '') {
            continue;
        }

        if (!is_array($preset)) {
            continue;
        }

        $label = isset($preset['label']) ? (string) $preset['label'] : '';
        $url = isset($preset['url']) ? (string) $preset['url'] : '';
        $method = isset($preset['method']) ? strtoupper((string) $preset['method']) : 'GET';

        if ($url === '') {
            continue;
        }

        if ($label === '') {
            $label = isset($defaults['presets'][$key]['label']) ? (string) $defaults['presets'][$key]['label'] : ucfirst($key);
        }

        if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
            $method = 'GET';
        }

        $normalized_presets[$key] = [
            'label'  => $label,
            'url'    => esc_url_raw($url),
            'method' => $method,
        ];
    }

    if ($normalized_presets === []) {
        $normalized_presets = sitepulse_speed_analyzer_get_default_presets();
    }

    return [
        'frequency' => $frequency,
        'presets'   => $normalized_presets,
    ];
}

/**
 * Saves the automation configuration.
 *
 * @param array{frequency:mixed,presets:mixed} $settings Raw settings.
 *
 * @return void
 */
function sitepulse_speed_analyzer_save_automation_settings($settings) {
    if (!is_array($settings)) {
        $settings = [];
    }

    $frequency = isset($settings['frequency']) ? sitepulse_speed_analyzer_sanitize_frequency($settings['frequency']) : 'disabled';
    $presets_input = isset($settings['presets']) && is_array($settings['presets']) ? $settings['presets'] : [];
    $defaults = sitepulse_speed_analyzer_get_default_presets();
    $presets = [];

    foreach ($presets_input as $key => $preset) {
        if (!is_string($key) || $key === '') {
            continue;
        }

        if (!is_array($preset)) {
            continue;
        }

        $label = isset($preset['label']) ? sanitize_text_field((string) $preset['label']) : '';
        $url = isset($preset['url']) ? esc_url_raw((string) $preset['url']) : '';
        $method = isset($preset['method']) ? strtoupper((string) $preset['method']) : 'GET';

        if ($url === '') {
            continue;
        }

        if ($label === '') {
            $label = isset($defaults[$key]['label']) ? (string) $defaults[$key]['label'] : ucfirst($key);
        }

        if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
            $method = 'GET';
        }

        $presets[$key] = [
            'label'  => $label,
            'url'    => $url,
            'method' => $method,
        ];
    }

    if ($presets === []) {
        $presets = sitepulse_speed_analyzer_get_default_presets();
    }

    update_option(
        SITEPULSE_OPTION_SPEED_AUTOMATION_CONFIG,
        [
            'frequency' => $frequency,
            'presets'   => $presets,
        ]
    );

    $queue = sitepulse_speed_analyzer_get_queue();
    $queue = array_values(array_filter(
        $queue,
        static function ($slug) use ($presets) {
            return isset($presets[$slug]);
        }
    ));
    sitepulse_speed_analyzer_update_queue($queue);

    if ($frequency === 'disabled') {
        sitepulse_speed_analyzer_unschedule_events();
    } else {
        sitepulse_speed_analyzer_bootstrap_cron(true);
    }
}

/**
 * Retrieves the stored automation history for all presets.
 *
 * @return array<string,array<int,array<string,mixed>>>
 */
function sitepulse_speed_analyzer_get_raw_automation_history() {
    $history = get_option(SITEPULSE_OPTION_SPEED_AUTOMATION_HISTORY, []);

    if (!is_array($history)) {
        return [];
    }

    return $history;
}

/**
 * Returns the automation history for a preset.
 *
 * @param string $preset       Preset identifier.
 * @param bool   $include_meta Whether to keep meta fields.
 *
 * @return array<int,array<string,mixed>>
 */
function sitepulse_speed_analyzer_get_automation_history($preset, $include_meta = false) {
    $history = sitepulse_speed_analyzer_get_raw_automation_history();

    if (!isset($history[$preset]) || !is_array($history[$preset])) {
        return [];
    }

    $entries = array_values(array_filter(
        $history[$preset],
        static function ($entry) {
            return is_array($entry) && isset($entry['timestamp']);
        }
    ));

    usort(
        $entries,
        static function ($a, $b) {
            $a_time = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
            $b_time = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;

            return $a_time <=> $b_time;
        }
    );

    if ($include_meta) {
        return array_map(
            static function ($entry) {
                $entry['timestamp'] = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

                if (isset($entry['server_processing_ms']) && is_numeric($entry['server_processing_ms'])) {
                    $entry['server_processing_ms'] = (float) $entry['server_processing_ms'];
                } else {
                    unset($entry['server_processing_ms']);
                }

                if (isset($entry['http_code'])) {
                    $entry['http_code'] = (int) $entry['http_code'];
                }

                if (isset($entry['error'])) {
                    $entry['error'] = (string) $entry['error'];
                }

                return $entry;
            },
            $entries
        );
    }

    $normalized = [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (!isset($entry['timestamp'], $entry['server_processing_ms'])) {
            continue;
        }

        if (!is_numeric($entry['timestamp']) || !is_numeric($entry['server_processing_ms'])) {
            continue;
        }

        $normalized[] = [
            'timestamp'            => max(0, (int) $entry['timestamp']),
            'server_processing_ms' => max(0.0, (float) $entry['server_processing_ms']),
        ];
    }

    return $normalized;
}

/**
 * Builds the automation payload used by the UI and AJAX responses.
 *
 * @param array{warning:int,critical:int}|null $thresholds Thresholds.
 *
 * @return array<string,mixed>
 */
function sitepulse_speed_analyzer_build_automation_payload($thresholds = null) {
    if ($thresholds === null) {
        $thresholds = sitepulse_speed_analyzer_get_thresholds();
    }

    $settings = sitepulse_speed_analyzer_get_automation_settings();
    $payload = [
        'frequency' => $settings['frequency'],
        'presets'   => [],
        'queue'     => sitepulse_speed_analyzer_get_queue(),
    ];

    foreach ($settings['presets'] as $slug => $preset) {
        $history = sitepulse_speed_analyzer_get_automation_history($slug);

        $payload['presets'][$slug] = [
            'label'           => isset($preset['label']) ? (string) $preset['label'] : ucfirst($slug),
            'url'             => isset($preset['url']) ? (string) $preset['url'] : '',
            'method'          => isset($preset['method']) ? (string) $preset['method'] : 'GET',
            'history'         => $history,
            'detailedHistory' => sitepulse_speed_analyzer_get_automation_history($slug, true),
            'aggregates'      => sitepulse_speed_analyzer_get_aggregates($history, $thresholds),
        ];
    }

    return $payload;
}

/**
 * Retrieves the most recent numeric entry from a preset history.
 *
 * @param array<int,array<string,mixed>> $entries Entries.
 *
 * @return array<string,mixed>|null
 */
function sitepulse_speed_analyzer_get_latest_numeric_entry_from_history($entries) {
    if (!is_array($entries)) {
        return null;
    }

    $reversed = array_reverse($entries);

    foreach ($reversed as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (!isset($entry['server_processing_ms']) || !is_numeric($entry['server_processing_ms'])) {
            continue;
        }

        $entry['timestamp'] = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $entry['server_processing_ms'] = max(0.0, (float) $entry['server_processing_ms']);

        return $entry;
    }

    return null;
}

/**
 * Stores a measurement in the automation history.
 *
 * @param string                      $preset Preset identifier.
 * @param array<string,mixed>         $entry  Entry to store.
 * @param array<string,string>|string $config Preset configuration or label.
 *
 * @return array{current:array<string,mixed>|null,previous:array<string,mixed>|null}
 */
function sitepulse_speed_analyzer_store_automation_measurement($preset, array $entry, $config = []) {
    $history = sitepulse_speed_analyzer_get_raw_automation_history();

    if (!isset($history[$preset]) || !is_array($history[$preset])) {
        $history[$preset] = [];
    }

    $previous = sitepulse_speed_analyzer_get_latest_numeric_entry_from_history($history[$preset]);

    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : current_time('timestamp');
    $value = null;

    if (isset($entry['server_processing_ms']) && is_numeric($entry['server_processing_ms'])) {
        $value = max(0.0, (float) $entry['server_processing_ms']);
    }

    $stored_entry = [
        'timestamp' => max(0, $timestamp),
    ];

    if ($value !== null) {
        $stored_entry['server_processing_ms'] = $value;
    }

    if (isset($entry['http_code']) && is_numeric($entry['http_code'])) {
        $stored_entry['http_code'] = (int) $entry['http_code'];
    }

    if (!empty($entry['error'])) {
        $stored_entry['error'] = (string) $entry['error'];
    }

    $history[$preset][] = $stored_entry;

    $max_age = apply_filters('sitepulse_speed_automation_max_age', DAY_IN_SECONDS * 14, $preset, $config);

    if (!is_scalar($max_age)) {
        $max_age = 0;
    }

    $max_age = (int) $max_age;

    if ($max_age > 0) {
        $cutoff = $timestamp - $max_age;
        $history[$preset] = array_values(array_filter(
            $history[$preset],
            static function ($item) use ($cutoff) {
                if (!is_array($item) || !isset($item['timestamp'])) {
                    return false;
                }

                return (int) $item['timestamp'] >= $cutoff;
            }
        ));
    }

    $max_entries = apply_filters('sitepulse_speed_automation_max_entries', 100, $preset, $config);

    if (!is_scalar($max_entries)) {
        $max_entries = 0;
    }

    $max_entries = (int) $max_entries;

    if ($max_entries > 0 && count($history[$preset]) > $max_entries) {
        $history[$preset] = array_slice($history[$preset], -$max_entries);
    }

    update_option(SITEPULSE_OPTION_SPEED_AUTOMATION_HISTORY, $history, false);

    return [
        'current'  => $stored_entry,
        'previous' => $previous,
    ];
}

/**
 * Retrieves the current automation queue.
 *
 * @return string[]
 */
function sitepulse_speed_analyzer_get_queue() {
    $queue = get_option(SITEPULSE_OPTION_SPEED_AUTOMATION_QUEUE, []);

    if (!is_array($queue)) {
        return [];
    }

    $normalized = [];

    foreach ($queue as $entry) {
        if (!is_string($entry) || $entry === '') {
            continue;
        }

        $normalized[] = sanitize_key($entry);
    }

    return array_values(array_unique($normalized));
}

/**
 * Updates the automation queue.
 *
 * @param string[] $queue Queue entries.
 *
 * @return void
 */
function sitepulse_speed_analyzer_update_queue($queue) {
    if (!is_array($queue)) {
        $queue = [];
    }

    update_option(SITEPULSE_OPTION_SPEED_AUTOMATION_QUEUE, array_values($queue), false);
}

/**
 * Adds presets to the automation queue.
 *
 * @param string[] $presets Preset slugs.
 *
 * @return void
 */
function sitepulse_speed_analyzer_enqueue_presets(array $presets) {
    $queue = sitepulse_speed_analyzer_get_queue();

    foreach ($presets as $preset) {
        if (!is_string($preset) || $preset === '') {
            continue;
        }

        $slug = sanitize_key($preset);

        if (!in_array($slug, $queue, true)) {
            $queue[] = $slug;
        }
    }

    sitepulse_speed_analyzer_update_queue($queue);
}

/**
 * Retrieves and removes the next preset from the queue.
 *
 * @return string|null
 */
function sitepulse_speed_analyzer_shift_queue() {
    $queue = sitepulse_speed_analyzer_get_queue();

    if ($queue === []) {
        return null;
    }

    $next = array_shift($queue);
    sitepulse_speed_analyzer_update_queue($queue);

    return $next;
}

/**
 * Determines whether the queue contains entries.
 *
 * @return bool
 */
function sitepulse_speed_analyzer_queue_not_empty() {
    return sitepulse_speed_analyzer_get_queue() !== [];
}

/**
 * Attempts to acquire the automation processing lock.
 *
 * @param int $ttl Lock duration in seconds.
 *
 * @return bool
 */
function sitepulse_speed_analyzer_acquire_lock($ttl = 300) {
    if (!function_exists('get_transient') || !function_exists('set_transient')) {
        return true;
    }

    if (false !== get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_LOCK)) {
        return false;
    }

    set_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_LOCK, time(), max(1, (int) $ttl));

    return true;
}

/**
 * Releases the automation processing lock.
 *
 * @return void
 */
function sitepulse_speed_analyzer_release_lock() {
    if (function_exists('delete_transient')) {
        delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_LOCK);
    }
}

/**
 * Unschedules existing automation events.
 *
 * @return void
 */
function sitepulse_speed_analyzer_unschedule_events() {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
        return;
    }

    $hook = sitepulse_speed_analyzer_get_cron_hook();

    $timestamp = wp_next_scheduled($hook);

    while ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
        $timestamp = wp_next_scheduled($hook);
    }

    $queue_hook = sitepulse_speed_analyzer_get_queue_hook();
    $timestamp = wp_next_scheduled($queue_hook);

    while ($timestamp) {
        wp_unschedule_event($timestamp, $queue_hook);
        $timestamp = wp_next_scheduled($queue_hook);
    }
}

/**
 * Schedules the automation cron event when necessary.
 *
 * @param bool $force Whether to force rescheduling.
 *
 * @return void
 */
function sitepulse_speed_analyzer_bootstrap_cron($force = false) {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }

    $settings = sitepulse_speed_analyzer_get_automation_settings();
    $hook = sitepulse_speed_analyzer_get_cron_hook();
    $frequency = $settings['frequency'];

    $frequency = apply_filters('sitepulse_speed_analyzer_cron_recurrence', $frequency, $settings);
    $frequency = sitepulse_speed_analyzer_sanitize_frequency($frequency);

    if ($frequency === 'disabled' || !is_array($settings['presets']) || $settings['presets'] === []) {
        sitepulse_speed_analyzer_unschedule_events();

        return;
    }

    if (!$force && wp_next_scheduled($hook)) {
        return;
    }

    sitepulse_speed_analyzer_unschedule_events();

    wp_schedule_event(time() + MINUTE_IN_SECONDS, $frequency, $hook);
}

/**
 * Handles the cron hook execution.
 *
 * @return void
 */
function sitepulse_speed_analyzer_run_cron() {
    $settings = sitepulse_speed_analyzer_get_automation_settings();

    if (empty($settings['presets'])) {
        return;
    }

    sitepulse_speed_analyzer_enqueue_presets(array_keys($settings['presets']));
    sitepulse_speed_analyzer_drain_queue($settings);
}

/**
 * Handles queued runs scheduled via wp_schedule_single_event().
 *
 * @return void
 */
function sitepulse_speed_analyzer_run_queue() {
    $settings = sitepulse_speed_analyzer_get_automation_settings();

    if (empty($settings['presets'])) {
        sitepulse_speed_analyzer_update_queue([]);

        return;
    }

    sitepulse_speed_analyzer_drain_queue($settings, true);
}

/**
 * Processes the automation queue.
 *
 * @param array<string,mixed> $settings Automation settings.
 * @param bool                $is_retry Whether the drain was initiated from a retry event.
 *
 * @return void
 */
function sitepulse_speed_analyzer_drain_queue($settings, $is_retry = false) {
    if (!is_array($settings) || empty($settings['presets'])) {
        return;
    }

    if (!sitepulse_speed_analyzer_acquire_lock()) {
        return;
    }

    $batch_size = apply_filters('sitepulse_speed_analyzer_cron_batch_size', 1, $settings, $is_retry);

    if (!is_numeric($batch_size) || $batch_size < 1) {
        $batch_size = 1;
    }

    $processed = 0;
    $presets = $settings['presets'];

    while ($processed < $batch_size) {
        $preset = sitepulse_speed_analyzer_shift_queue();

        if ($preset === null) {
            break;
        }

        if (!isset($presets[$preset])) {
            $processed++;
            continue;
        }

        sitepulse_speed_analyzer_execute_automation_scan($preset, $presets[$preset]);
        $processed++;
    }

    sitepulse_speed_analyzer_release_lock();

    if (sitepulse_speed_analyzer_queue_not_empty()) {
        $delay = apply_filters('sitepulse_speed_analyzer_queue_delay', MINUTE_IN_SECONDS, $settings, $is_retry);

        if (!is_numeric($delay) || $delay < 1) {
            $delay = MINUTE_IN_SECONDS;
        }

        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + (int) $delay, sitepulse_speed_analyzer_get_queue_hook());
        }
    }
}

/**
 * Executes a preset scan.
 *
 * @param string               $preset Preset identifier.
 * @param array<string,string> $config Preset configuration.
 *
 * @return void
 */
function sitepulse_speed_analyzer_execute_automation_scan($preset, $config) {
    if (!is_array($config)) {
        return;
    }

    $url = isset($config['url']) ? (string) $config['url'] : '';

    if ($url === '') {
        return;
    }

    $method = isset($config['method']) ? strtoupper((string) $config['method']) : 'GET';

    if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
        $method = 'GET';
    }

    if (!function_exists('wp_remote_request')) {
        include_once ABSPATH . WPINC . '/http.php';
    }

    $timeout = isset($config['timeout']) && is_numeric($config['timeout'])
        ? max(1, (int) $config['timeout'])
        : 15;

    $args = [
        'method'  => $method,
        'timeout' => $timeout,
    ];

    if ('POST' === $method && isset($config['body']) && is_array($config['body'])) {
        $args['body'] = $config['body'];
    }

    $start = microtime(true);
    $response = wp_remote_request($url, $args);
    $duration_ms = max(0.0, (microtime(true) - $start) * 1000);
    $timestamp = current_time('timestamp');
    $http_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    $error_message = is_wp_error($response) ? $response->get_error_message() : '';

    $result = sitepulse_speed_analyzer_store_automation_measurement(
        $preset,
        [
            'timestamp'            => $timestamp,
            'server_processing_ms' => $duration_ms,
            'http_code'            => $http_code,
            'error'                => $error_message,
        ],
        $config
    );

    sitepulse_speed_analyzer_notify_regression_if_needed($preset, $config, $result['current'], $result['previous']);
}

/**
 * Triggers a notification when a regression is detected.
 *
 * @param string               $preset   Preset identifier.
 * @param array<string,string> $config   Preset configuration.
 * @param array<string,mixed>|null $current Current entry.
 * @param array<string,mixed>|null $previous Previous entry.
 *
 * @return void
 */
function sitepulse_speed_analyzer_notify_regression_if_needed($preset, $config, $current, $previous) {
    if (!is_array($current) || !isset($current['server_processing_ms'])) {
        return;
    }

    if (!is_numeric($current['server_processing_ms'])) {
        return;
    }

    if (!is_array($previous) || !isset($previous['server_processing_ms']) || !is_numeric($previous['server_processing_ms'])) {
        return;
    }

    $current_value = max(0.0, (float) $current['server_processing_ms']);
    $previous_value = max(0.0, (float) $previous['server_processing_ms']);

    if ($previous_value <= 0.0) {
        return;
    }

    $threshold = apply_filters('sitepulse_speed_analyzer_regression_threshold', 0.3, $preset, $config);

    if (!is_numeric($threshold) || $threshold <= 0) {
        $threshold = 0.3;
    }

    $min_delta = apply_filters('sitepulse_speed_analyzer_regression_min_delta', 100.0, $preset, $config);

    if (!is_numeric($min_delta) || $min_delta < 0) {
        $min_delta = 0;
    }

    $delta = $current_value - $previous_value;

    if ($delta < $min_delta || $current_value < $previous_value * (1 + $threshold)) {
        return;
    }

    $cooldown = apply_filters('sitepulse_speed_analyzer_regression_cooldown', 3 * HOUR_IN_SECONDS, $preset, $config);

    if (!is_numeric($cooldown) || $cooldown < 60) {
        $cooldown = 3 * HOUR_IN_SECONDS;
    }

    $transient_key = 'sitepulse_speed_regression_' . md5($preset);

    if (function_exists('get_transient') && false !== get_transient($transient_key)) {
        return;
    }

    $label = isset($config['label']) ? (string) $config['label'] : ucfirst($preset);
    $site_name = function_exists('get_bloginfo') ? get_bloginfo('name') : 'WordPress';
    $subject = sprintf(
        /* translators: %1$s: preset label, %2$s: site name. */
        __('Régression de performance détectée pour %1$s sur %2$s', 'sitepulse'),
        $label,
        $site_name
    );

    $message = sprintf(
        /* translators: 1: preset label, 2: previous duration, 3: current duration. */
        __('Le preset « %1$s » est passé de %2$.2f ms à %3$.2f ms. Vérifiez l’URL surveillée : %4$s', 'sitepulse'),
        $label,
        $previous_value,
        $current_value,
        isset($config['url']) ? $config['url'] : ''
    );

    $should_notify = apply_filters('sitepulse_speed_analyzer_send_regression_notification', true, $preset, $config, $current, $previous);

    if ($should_notify && function_exists('wp_mail')) {
        $recipients = apply_filters('sitepulse_speed_analyzer_regression_recipients', [get_option('admin_email')], $preset, $config, $current, $previous);

        if (is_array($recipients)) {
            $recipients = array_values(array_filter(array_map('sanitize_email', $recipients)));
        } else {
            $recipients = [];
        }

        if ($recipients !== []) {
            wp_mail($recipients, $subject, $message);
        }
    }

    if (function_exists('do_action')) {
        do_action('sitepulse_speed_analyzer_regression_detected', $preset, $config, $current, $previous);
    }

    if (function_exists('set_transient')) {
        set_transient($transient_key, time(), (int) $cooldown);
    }
}

/**
 * Handles the automation settings submission.
 *
 * @return void
 */
function sitepulse_speed_analyzer_handle_schedule_post() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour modifier cette configuration.", 'sitepulse'));
    }

    check_admin_referer('sitepulse_speed_schedule');

    $settings = [
        'frequency' => isset($_POST['sitepulse_speed_frequency']) ? wp_unslash($_POST['sitepulse_speed_frequency']) : 'disabled',
        'presets'   => isset($_POST['sitepulse_speed_presets']) ? wp_unslash($_POST['sitepulse_speed_presets']) : [],
    ];

    sitepulse_speed_analyzer_save_automation_settings($settings);

    wp_safe_redirect(
        add_query_arg(
            ['page' => 'sitepulse-speed', 'updated' => 'true'],
            admin_url('admin.php')
        )
    );

    exit;
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

    $automation_payload = sitepulse_speed_analyzer_build_automation_payload($thresholds);

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
            'automation'       => $automation_payload,
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
        'automation'      => $automation_payload,
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

    $automation_payload = sitepulse_speed_analyzer_build_automation_payload($thresholds);
    $frequency_choices = sitepulse_speed_analyzer_get_frequency_choices();

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
            'automation'     => $automation_payload,
            'frequencyChoices'=> $frequency_choices,
            'i18n'           => [
                'running'        => esc_html__('Analyse en cours…', 'sitepulse'),
                'retry'          => esc_html__('Relancer un test', 'sitepulse'),
                'noHistory'      => esc_html__("Aucun historique disponible pour le moment.", 'sitepulse'),
                'timestamp'      => esc_html__('Horodatage', 'sitepulse'),
                'duration'       => esc_html__('Temps serveur (ms)', 'sitepulse'),
                'status'         => esc_html__('Statut', 'sitepulse'),
                'chartLabel'     => esc_html__('Temps de traitement du serveur', 'sitepulse'),
                'error'          => esc_html__("Une erreur est survenue pendant le test. Veuillez réessayer.", 'sitepulse'),
                'throttled'      => esc_html__('Test bloqué temporairement par la limite de fréquence.', 'sitepulse'),
                'rateLimitIntro' => esc_html__('Prochain test possible dans', 'sitepulse'),
                'warningThresholdLabel' => esc_html__('Seuil d’alerte', 'sitepulse'),
                'criticalThresholdLabel'=> esc_html__('Seuil critique', 'sitepulse'),
                'manualLabel'    => esc_html__('Tests manuels', 'sitepulse'),
                'automationLabel'=> esc_html__('Planifié – %s', 'sitepulse'),
                'automationEmpty'=> esc_html__('Aucun preset planifié n’est disponible.', 'sitepulse'),
                'queueWarning'   => esc_html__('Certaines mesures automatiques sont en file d’attente.', 'sitepulse'),
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
    $automation_settings = sitepulse_speed_analyzer_get_automation_settings();
    $automation_payload = sitepulse_speed_analyzer_build_automation_payload($thresholds);
    $frequency_choices = sitepulse_speed_analyzer_get_frequency_choices();
    $selected_frequency = isset($automation_settings['frequency']) ? $automation_settings['frequency'] : 'disabled';
    $default_presets = sitepulse_speed_analyzer_get_default_presets();
    $form_presets = $default_presets;

    foreach ($automation_settings['presets'] as $preset_slug => $preset_config) {
        if (isset($form_presets[$preset_slug])) {
            $form_presets[$preset_slug] = array_merge($form_presets[$preset_slug], $preset_config);
        } else {
            $form_presets[$preset_slug] = $preset_config;
        }
    }

    $automation_queue = isset($automation_payload['queue']) && is_array($automation_payload['queue'])
        ? $automation_payload['queue']
        : [];

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
            <div class="speed-history-controls">
                <label class="screen-reader-text" for="sitepulse-speed-history-source"><?php esc_html_e('Source des mesures', 'sitepulse'); ?></label>
                <select id="sitepulse-speed-history-source">
                    <option value="manual" selected><?php esc_html_e('Tests manuels', 'sitepulse'); ?></option>
                    <?php if (!empty($automation_payload['presets'])) : ?>
                        <?php foreach ($automation_payload['presets'] as $preset_slug => $preset_data) : ?>
                            <option value="<?php echo esc_attr('automation:' . $preset_slug); ?>">
                                <?php printf(esc_html__('Planifié – %s', 'sitepulse'), esc_html($preset_data['label'])); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if (!empty($automation_queue)) : ?>
                    <p class="description speed-history-queue-warning"><?php esc_html_e('Certaines mesures automatiques sont en file d’attente.', 'sitepulse'); ?></p>
                <?php endif; ?>
            </div>
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
                        <th scope="col"><?php esc_html_e('Statut', 'sitepulse'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($history)) : ?>
                        <?php foreach ($history as $entry) : ?>
                            <tr>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $entry['timestamp'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n($entry['server_processing_ms'], 2)); ?></td>
                                <td>&mdash;</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="3"><?php esc_html_e('Aucun historique disponible pour le moment.', 'sitepulse'); ?></td>
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

        <div class="speed-automation" id="sitepulse-speed-automation">
            <h2><?php esc_html_e('Planification automatique', 'sitepulse'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="speed-automation-form">
                <?php wp_nonce_field('sitepulse_speed_schedule'); ?>
                <input type="hidden" name="action" value="sitepulse_save_speed_schedule">
                <div class="speed-automation-field">
                    <label for="sitepulse-speed-frequency"><?php esc_html_e('Fréquence des tests planifiés', 'sitepulse'); ?></label>
                    <select id="sitepulse-speed-frequency" name="sitepulse_speed_frequency">
                        <?php foreach ($frequency_choices as $frequency_slug => $frequency_label) : ?>
                            <option value="<?php echo esc_attr($frequency_slug); ?>" <?php selected($selected_frequency, $frequency_slug); ?>>
                                <?php echo esc_html($frequency_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="speed-automation-presets">
                    <?php foreach ($form_presets as $preset_slug => $preset_config) :
                        $preset_label = isset($preset_config['label']) ? (string) $preset_config['label'] : ucfirst($preset_slug);
                        $preset_url = isset($preset_config['url']) ? (string) $preset_config['url'] : '';
                        $preset_method = isset($preset_config['method']) ? strtoupper((string) $preset_config['method']) : 'GET';
                        if (!in_array($preset_method, ['GET', 'POST', 'HEAD'], true)) {
                            $preset_method = 'GET';
                        }
                    ?>
                        <fieldset class="speed-automation-preset">
                            <legend><?php echo esc_html($preset_label); ?></legend>
                            <label for="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-label"><?php esc_html_e('Nom du preset', 'sitepulse'); ?></label>
                            <input type="text" id="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-label" name="sitepulse_speed_presets[<?php echo esc_attr($preset_slug); ?>][label]" value="<?php echo esc_attr($preset_label); ?>">
                            <label for="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-url"><?php esc_html_e('URL à surveiller', 'sitepulse'); ?></label>
                            <input type="url" id="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-url" name="sitepulse_speed_presets[<?php echo esc_attr($preset_slug); ?>][url]" value="<?php echo esc_attr($preset_url); ?>" required>
                            <label for="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-method"><?php esc_html_e('Méthode HTTP', 'sitepulse'); ?></label>
                            <select id="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-method" name="sitepulse_speed_presets[<?php echo esc_attr($preset_slug); ?>][method]">
                                <?php foreach (['GET', 'POST', 'HEAD'] as $method_option) : ?>
                                    <option value="<?php echo esc_attr($method_option); ?>" <?php selected($preset_method, $method_option); ?>><?php echo esc_html($method_option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
                <p>
                    <button type="submit" class="button button-secondary"><?php esc_html_e('Enregistrer la planification', 'sitepulse'); ?></button>
                </p>
            </form>

            <?php if (!empty($automation_payload['presets'])) : ?>
                <h3><?php esc_html_e('Comparaison des mesures planifiées', 'sitepulse'); ?></h3>
                <table class="widefat fixed sitepulse-automation-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Preset', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Moyenne (ms)', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Dernière mesure', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Statut HTTP', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Dernier relevé', 'sitepulse'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($automation_payload['presets'] as $preset_slug => $preset_data) :
                            $aggregated = isset($preset_data['aggregates']) ? $preset_data['aggregates'] : [];
                            $mean_metric = isset($aggregated['metrics']['mean']) ? $aggregated['metrics']['mean'] : null;
                            $mean_value = ($mean_metric && isset($mean_metric['value']) && $mean_metric['value'] !== null)
                                ? sprintf(
                                    /* translators: %s: duration in milliseconds. */
                                    esc_html__('%s ms', 'sitepulse'),
                                    esc_html(number_format_i18n((float) $mean_metric['value'], 2))
                                )
                                : esc_html__('N/A', 'sitepulse');
                            $mean_status = $mean_metric && isset($mean_metric['status']) ? $mean_metric['status'] : 'status-warn';
                            $mean_meta = $get_status_meta($mean_status);
                            $history_meta = isset($preset_data['detailedHistory']) && is_array($preset_data['detailedHistory'])
                                ? $preset_data['detailedHistory']
                                : [];
                            $latest_meta = !empty($history_meta) ? end($history_meta) : null;
                            $latest_value = ($latest_meta && isset($latest_meta['server_processing_ms']))
                                ? sprintf(
                                    /* translators: %s: duration in milliseconds. */
                                    esc_html__('%s ms', 'sitepulse'),
                                    esc_html(number_format_i18n((float) $latest_meta['server_processing_ms'], 2))
                                )
                                : esc_html__('N/A', 'sitepulse');
                            $latest_status = ($latest_meta && isset($latest_meta['server_processing_ms']))
                                ? sitepulse_speed_analyzer_resolve_status((float) $latest_meta['server_processing_ms'], $thresholds)
                                : 'status-warn';
                            $latest_meta_info = $get_status_meta($latest_status);
                            $http_status_label = '—';

                            if ($latest_meta) {
                                if (!empty($latest_meta['error'])) {
                                    $http_status_label = (string) $latest_meta['error'];
                                } elseif (isset($latest_meta['http_code']) && (int) $latest_meta['http_code'] > 0) {
                                    $http_status_label = (string) (int) $latest_meta['http_code'];
                                }
                            }

                            $latest_timestamp_label = ($latest_meta && !empty($latest_meta['timestamp']))
                                ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $latest_meta['timestamp'])
                                : esc_html__('Jamais', 'sitepulse');
                        ?>
                            <tr>
                                <td><?php echo esc_html($preset_data['label']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo esc_attr($mean_status); ?>" aria-hidden="true">
                                        <span class="status-icon"><?php echo esc_html($mean_meta['icon']); ?></span>
                                        <span class="status-text"><?php echo esc_html($mean_meta['label']); ?></span>
                                    </span>
                                    <span class="screen-reader-text"><?php echo esc_html($mean_meta['sr']); ?></span>
                                    <?php echo $mean_value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo esc_attr($latest_status); ?>" aria-hidden="true">
                                        <span class="status-icon"><?php echo esc_html($latest_meta_info['icon']); ?></span>
                                        <span class="status-text"><?php echo esc_html($latest_meta_info['label']); ?></span>
                                    </span>
                                    <span class="screen-reader-text"><?php echo esc_html($latest_meta_info['sr']); ?></span>
                                    <?php echo $latest_value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </td>
                                <td><?php echo esc_html($http_status_label); ?></td>
                                <td><?php echo esc_html($latest_timestamp_label); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Aucune mesure planifiée n’est encore disponible.', 'sitepulse'); ?></p>
            <?php endif; ?>
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
