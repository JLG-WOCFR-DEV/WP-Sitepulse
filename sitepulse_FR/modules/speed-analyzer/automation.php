<?php
/**
 * SitePulse Speed Analyzer automation settings.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
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
            'profile' => 'desktop',
        ],
        'critical' => [
            'label'  => __('Page critique', 'sitepulse'),
            'url'    => home_url('/'),
            'method' => 'GET',
            'profile' => 'mobile',
        ],
        'api' => [
            'label'  => __('API', 'sitepulse'),
            'url'    => home_url('/wp-json/'),
            'method' => 'GET',
            'profile' => 'default',
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

        $profile = isset($preset['profile']) ? $preset['profile'] : (isset($defaults['presets'][$key]['profile']) ? $defaults['presets'][$key]['profile'] : 'default');
        $profile = sitepulse_speed_analyzer_normalize_profile($profile);

        $normalized_presets[$key] = [
            'label'   => $label,
            'url'     => esc_url_raw($url),
            'method'  => $method,
            'profile' => $profile,
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

        $profile = isset($preset['profile']) ? $preset['profile'] : (isset($defaults[$key]['profile']) ? $defaults[$key]['profile'] : 'default');
        $profile = sitepulse_speed_analyzer_normalize_profile($profile);

        $presets[$key] = [
            'label'   => $label,
            'url'     => $url,
            'method'  => $method,
            'profile' => $profile,
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
        static function ($token) use ($presets) {
            $parsed = sitepulse_speed_analyzer_parse_queue_token($token);

            return isset($presets[$parsed['preset']]);
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

                if (isset($entry['profile'])) {
                    $entry['profile'] = sitepulse_speed_analyzer_normalize_profile($entry['profile']);
                }

                if (isset($entry['source'])) {
                    $entry['source'] = sanitize_key($entry['source']);
                }

                if (isset($entry['source_label'])) {
                    $entry['source_label'] = sanitize_text_field((string) $entry['source_label']);
                }

                if (isset($entry['source_type'])) {
                    $entry['source_type'] = sanitize_key($entry['source_type']);
                }

                if (isset($entry['url'])) {
                    $entry['url'] = esc_url_raw((string) $entry['url']);
                }

                if (isset($entry['benchmark_budget']) && is_numeric($entry['benchmark_budget'])) {
                    $entry['benchmark_budget'] = (int) $entry['benchmark_budget'];
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

        $normalized_entry = [
            'timestamp'            => max(0, (int) $entry['timestamp']),
            'server_processing_ms' => max(0.0, (float) $entry['server_processing_ms']),
        ];

        if (isset($entry['profile'])) {
            $normalized_entry['profile'] = sitepulse_speed_analyzer_normalize_profile($entry['profile']);
        }

        if (isset($entry['source'])) {
            $normalized_entry['source'] = sanitize_key($entry['source']);
        }

        if (isset($entry['source_label'])) {
            $normalized_entry['source_label'] = sanitize_text_field((string) $entry['source_label']);
        }

        if (isset($entry['source_type'])) {
            $normalized_entry['source_type'] = sanitize_key($entry['source_type']);
        }

        if (isset($entry['url'])) {
            $normalized_entry['url'] = esc_url_raw((string) $entry['url']);
        }

        if (isset($entry['benchmark_budget']) && is_numeric($entry['benchmark_budget'])) {
            $normalized_entry['benchmark_budget'] = (int) $entry['benchmark_budget'];
        }

        $normalized[] = $normalized_entry;
    }

    return $normalized;
}

/**
 * Builds the automation payload used by the UI and AJAX responses.
 *
 * @param array{warning:int,critical:int}|null $default_thresholds Default thresholds for manual runs.
 *
 * @return array<string,mixed>
 */
function sitepulse_speed_analyzer_build_automation_payload($default_thresholds = null) {
    if ($default_thresholds === null) {
        $default_thresholds = sitepulse_speed_analyzer_get_thresholds();
    }

    $settings = sitepulse_speed_analyzer_get_automation_settings();
    $profiles = sitepulse_speed_analyzer_get_profile_catalog();
    $payload = [
        'frequency'        => $settings['frequency'],
        'presets'          => [],
        'queue'            => sitepulse_speed_analyzer_get_queue(),
        'manualThresholds' => [
            'warning'  => isset($default_thresholds['warning']) ? (int) $default_thresholds['warning'] : 0,
            'critical' => isset($default_thresholds['critical']) ? (int) $default_thresholds['critical'] : 0,
            'profile'  => isset($default_thresholds['profile']) ? sitepulse_speed_analyzer_normalize_profile($default_thresholds['profile']) : 'default',
        ],
    ];

    foreach ($settings['presets'] as $slug => $preset) {
        $history = sitepulse_speed_analyzer_get_automation_history($slug);
        $profile = isset($preset['profile']) ? sitepulse_speed_analyzer_normalize_profile($preset['profile']) : 'default';
        $profile_thresholds = sitepulse_speed_analyzer_get_thresholds($profile);
        $profile_label = isset($profiles[$profile]['label']) ? (string) $profiles[$profile]['label'] : ucfirst($profile);
        $targets = sitepulse_speed_analyzer_resolve_targets_for_preset($slug, $preset);

        $payload['presets'][$slug] = [
            'label'           => isset($preset['label']) ? (string) $preset['label'] : ucfirst($slug),
            'url'             => isset($preset['url']) ? (string) $preset['url'] : '',
            'method'          => isset($preset['method']) ? (string) $preset['method'] : 'GET',
            'history'         => $history,
            'detailedHistory' => sitepulse_speed_analyzer_get_automation_history($slug, true),
            'aggregates'      => sitepulse_speed_analyzer_get_aggregates($history, $profile_thresholds),
            'profile'         => $profile,
            'profileLabel'    => $profile_label,
            'thresholds'      => [
                'warning'  => (int) $profile_thresholds['warning'],
                'critical' => (int) $profile_thresholds['critical'],
                'profile'  => $profile,
            ],
            'sources'         => array_map(
                static function ($target) {
                    $profile = isset($target['profile']) ? sitepulse_speed_analyzer_normalize_profile($target['profile']) : 'default';
                    $budget = sitepulse_speed_analyzer_get_profile_benchmark_budget($profile);

                    return [
                        'key'     => isset($target['key']) ? sanitize_key($target['key']) : 'site',
                        'label'   => isset($target['label']) ? (string) $target['label'] : '',
                        'type'    => isset($target['type']) ? sanitize_key($target['type']) : 'site',
                        'profile' => $profile,
                        'budget'  => $budget !== null ? (int) $budget : null,
                    ];
                },
                $targets
            ),
        ];
    }

    return $payload;
}

/**
 * Retrieves the most recent numeric entry from a preset history.
 *
 * @param array<int,array<string,mixed>> $entries Entries.
 * @param string|null                    $source  Optional source filter.
 *
 * @return array<string,mixed>|null
 */
function sitepulse_speed_analyzer_get_latest_numeric_entry_from_history($entries, $source = null) {
    if (!is_array($entries)) {
        return null;
    }

    $reversed = array_reverse($entries);

    foreach ($reversed as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if ($source !== null) {
            $entry_source = isset($entry['source']) ? sanitize_key($entry['source']) : '';

            if ($entry_source !== sanitize_key($source)) {
                continue;
            }
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

    $source_key = isset($entry['source']) ? sanitize_key($entry['source']) : 'site';
    $previous = sitepulse_speed_analyzer_get_latest_numeric_entry_from_history($history[$preset], $source_key);
    $profile = isset($entry['profile']) ? sitepulse_speed_analyzer_normalize_profile($entry['profile']) : (isset($config['profile']) ? sitepulse_speed_analyzer_normalize_profile($config['profile']) : 'default');

    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : current_time('timestamp');
    $value = null;

    if (isset($entry['server_processing_ms']) && is_numeric($entry['server_processing_ms'])) {
        $value = max(0.0, (float) $entry['server_processing_ms']);
    }

    $stored_entry = [
        'timestamp' => max(0, $timestamp),
        'profile'   => $profile,
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

    if ($source_key !== '') {
        $stored_entry['source'] = $source_key;
    }

    if (isset($entry['source_label'])) {
        $stored_entry['source_label'] = sanitize_text_field((string) $entry['source_label']);
    }

    if (isset($entry['source_type'])) {
        $stored_entry['source_type'] = sanitize_key($entry['source_type']);
    }

    if (isset($entry['url'])) {
        $stored_entry['url'] = esc_url_raw((string) $entry['url']);
    }

    $budget = sitepulse_speed_analyzer_get_profile_benchmark_budget($profile);

    if ($budget !== null) {
        $stored_entry['benchmark_budget'] = (int) $budget;
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
