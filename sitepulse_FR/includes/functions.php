<?php
/**
 * SitePulse shared helper functions.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SITEPULSE_OPTION_TRANSIENT_PURGE_LOG')) {
    define('SITEPULSE_OPTION_TRANSIENT_PURGE_LOG', 'sitepulse_transient_purge_log');
}

if (!function_exists('sitepulse_register_transient_purge_entry')) {
    /**
     * Persists metadata about a transient purge.
     *
     * @param string $scope   Purge scope (transient / site-transient).
     * @param string $prefix  Prefix that was purged.
     * @param array  $metrics Additional metrics (deleted, unique, batches, cache).
     *
     * @return void
     */
    function sitepulse_register_transient_purge_entry($scope, $prefix, $metrics = []) {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $scope  = is_string($scope) ? strtolower(trim($scope)) : 'transient';
        $prefix = is_string($prefix) ? $prefix : '';

        if ($prefix === '') {
            return;
        }

        $deleted = isset($metrics['deleted']) ? (int) $metrics['deleted'] : 0;

        if ($deleted <= 0) {
            return;
        }

        $timestamp = function_exists('current_time') ? (int) current_time('timestamp') : time();

        $entry = [
            'scope'        => $scope === 'site-transient' ? 'site-transient' : 'transient',
            'prefix'       => $prefix,
            'deleted'      => $deleted,
            'unique'       => isset($metrics['unique']) ? max(0, (int) $metrics['unique']) : $deleted,
            'batches'      => isset($metrics['batches']) ? max(0, (int) $metrics['batches']) : 0,
            'object_cache' => !empty($metrics['object_cache']),
            'timestamp'    => $timestamp,
        ];

        $existing_log = get_option(SITEPULSE_OPTION_TRANSIENT_PURGE_LOG, []);

        if (!is_array($existing_log)) {
            $existing_log = [];
        }

        array_unshift($existing_log, $entry);

        $max_entries = 20;

        if (function_exists('apply_filters')) {
            $max_entries = (int) apply_filters('sitepulse_transient_purge_log_limit', $max_entries);
        }

        if ($max_entries > 0 && count($existing_log) > $max_entries) {
            $existing_log = array_slice($existing_log, 0, $max_entries);
        }

        update_option(SITEPULSE_OPTION_TRANSIENT_PURGE_LOG, $existing_log, false);
}
}

if (!function_exists('sitepulse_record_transient_purge_stats')) {
    /**
     * Compatibility wrapper executed when the purge action fires.
     *
     * @param string $prefix Transient prefix.
     * @param array  $stats  Telemetry payload from the purge routine.
     *
     * @return void
     */
    function sitepulse_record_transient_purge_stats($prefix, $stats) {
        if (!is_array($stats) || empty($stats['deleted'])) {
            return;
        }

        if (!empty($stats['already_logged'])) {
            return;
        }

        $scope = isset($stats['scope']) && $stats['scope'] === 'site-transient' ? 'site-transient' : 'transient';

        $metrics = [
            'deleted'      => isset($stats['deleted']) ? (int) $stats['deleted'] : 0,
            'unique'       => isset($stats['unique_keys']) && is_array($stats['unique_keys'])
                ? count($stats['unique_keys'])
                : (isset($stats['unique']) ? (int) $stats['unique'] : (isset($stats['deleted']) ? (int) $stats['deleted'] : 0)),
            'batches'      => isset($stats['batches']) ? (int) $stats['batches'] : 0,
            'object_cache' => !empty($stats['object_cache_hit']),
        ];

        sitepulse_register_transient_purge_entry($scope, $prefix, $metrics);
    }
}

if (!function_exists('sitepulse_get_transient_purge_log')) {
    /**
     * Retrieves the transient purge history.
     *
     * @param int $limit Maximum number of entries to return.
     *
     * @return array<int,array<string,mixed>>
     */
    function sitepulse_get_transient_purge_log($limit = 10) {
        if (!function_exists('get_option')) {
            return [];
        }

        $raw_log = get_option(SITEPULSE_OPTION_TRANSIENT_PURGE_LOG, []);

        if (!is_array($raw_log)) {
            $raw_log = [];
        }

        $sanitized = [];

        foreach ($raw_log as $entry) {
            if (!is_array($entry) || empty($entry['prefix'])) {
                continue;
            }

            $sanitized[] = [
                'scope'        => isset($entry['scope']) && $entry['scope'] === 'site-transient' ? 'site-transient' : 'transient',
                'prefix'       => (string) $entry['prefix'],
                'deleted'      => max(0, (int) ($entry['deleted'] ?? 0)),
                'unique'       => max(0, (int) ($entry['unique'] ?? 0)),
                'batches'      => max(0, (int) ($entry['batches'] ?? 0)),
                'object_cache' => !empty($entry['object_cache']),
                'timestamp'    => isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0,
            ];
        }

        if ($limit > 0 && count($sanitized) > $limit) {
            $sanitized = array_slice($sanitized, 0, $limit);
        }

        return $sanitized;
    }
}

if (!function_exists('sitepulse_calculate_transient_purge_summary')) {
    /**
     * Calculates aggregate statistics for the transient purge history.
     *
     * @param array|null $entries        Optional pre-fetched log entries.
     * @param int|null   $window_seconds Time window for aggregations (defaults to 30 days).
     *
     * @return array{
     *     totals: array{deleted:int,unique:int,batches:int},
     *     latest: array<string,mixed>|null,
     *     top_prefixes: array<int,array{scope:string,prefix:string,deleted:int,unique:int}>
     * }
     */
    function sitepulse_calculate_transient_purge_summary($entries = null, $window_seconds = null) {
        if ($entries === null) {
            $entries = sitepulse_get_transient_purge_log();
        }

        if (!is_array($entries)) {
            $entries = [];
        }

        $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
        $window_seconds = $window_seconds === null
            ? (defined('DAY_IN_SECONDS') ? 30 * DAY_IN_SECONDS : 30 * 86400)
            : max(0, (int) $window_seconds);

        $summary = [
            'totals'       => ['deleted' => 0, 'unique' => 0, 'batches' => 0],
            'latest'       => isset($entries[0]) ? $entries[0] : null,
            'top_prefixes' => [],
        ];

        if (empty($entries)) {
            return $summary;
        }

        $prefix_map = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

            if ($window_seconds > 0 && $timestamp > 0 && $timestamp < ($now - $window_seconds)) {
                continue;
            }

            $deleted = max(0, (int) ($entry['deleted'] ?? 0));
            $unique  = max(0, (int) ($entry['unique'] ?? 0));
            $batches = max(0, (int) ($entry['batches'] ?? 0));

            $summary['totals']['deleted'] += $deleted;
            $summary['totals']['unique']  += $unique;
            $summary['totals']['batches'] += $batches;

            $scope  = isset($entry['scope']) && $entry['scope'] === 'site-transient' ? 'site-transient' : 'transient';
            $prefix = isset($entry['prefix']) ? (string) $entry['prefix'] : '';

            if ($prefix === '') {
                continue;
            }

            $map_key = $scope . '|' . $prefix;

            if (!isset($prefix_map[$map_key])) {
                $prefix_map[$map_key] = [
                    'scope'   => $scope,
                    'prefix'  => $prefix,
                    'deleted' => 0,
                    'unique'  => 0,
                ];
            }

            $prefix_map[$map_key]['deleted'] += $deleted;
            $prefix_map[$map_key]['unique']  += $unique ?: $deleted;
        }

        if (!empty($prefix_map)) {
            $prefixes = array_values($prefix_map);

            usort($prefixes, static function ($a, $b) {
                $deleted_compare = $b['deleted'] <=> $a['deleted'];

                if ($deleted_compare !== 0) {
                    return $deleted_compare;
                }

                return strcmp($a['prefix'], $b['prefix']);
            });

            $summary['top_prefixes'] = array_slice($prefixes, 0, 3);
        }

        return $summary;
    }
}

  if (!function_exists('sitepulse_get_transient_purge_scope_label')) {
    /**
     * Returns a human readable label for a purge scope.
     *
     * @param string $scope Scope identifier.
     *
     * @return string
     */
    function sitepulse_get_transient_purge_scope_label($scope) {
        if ($scope === 'site-transient') {
            return __('Transients réseau', 'sitepulse');
        }

        return __('Transients du site courant', 'sitepulse');
    }
  }

  if (!function_exists('sitepulse_register_transient_purge_dashboard_widget')) {
    /**
     * Registers the dashboard widget summarizing transient purges.
     *
     * @return void
     */
    function sitepulse_register_transient_purge_dashboard_widget() {
        if (!function_exists('wp_add_dashboard_widget') || !function_exists('current_user_can')) {
            return;
        }

        $capability = function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options';

        if (!current_user_can($capability)) {
            return;
        }

        wp_add_dashboard_widget(
            'sitepulse_transient_purge_widget',
            __('SitePulse — Purges de transients', 'sitepulse'),
            'sitepulse_render_transient_purge_dashboard_widget'
        );
    }
  }

  if (!function_exists('sitepulse_render_transient_purge_dashboard_widget')) {
    /**
     * Renders the dashboard widget content.
     *
     * @return void
     */
    function sitepulse_render_transient_purge_dashboard_widget() {
        $log     = sitepulse_get_transient_purge_log(5);
        $summary = sitepulse_calculate_transient_purge_summary($log);

        if (empty($log)) {
            echo '<p>' . esc_html__('Aucune purge de transients enregistrée pour le moment.', 'sitepulse') . '</p>';

            return;
        }

        $latest = $summary['latest'];

        if ($latest) {
            $relative = '';

            if (!empty($latest['timestamp']) && function_exists('human_time_diff')) {
                $diff = human_time_diff($latest['timestamp'], function_exists('current_time') ? current_time('timestamp') : time());
                $relative = sprintf(__('il y a %s', 'sitepulse'), $diff);
            }

            printf(
                '<p><strong>%1$s</strong> — %2$s %3$s</p>',
                esc_html(sitepulse_get_transient_purge_scope_label($latest['scope'])),
                esc_html($latest['prefix']),
                $relative !== '' ? '<span class="sitepulse-transient-purge-ago">' . esc_html($relative) . '</span>' : ''
            );
        }

        if (!empty($summary['top_prefixes'])) {
            echo '<ul class="sitepulse-transient-purge-top">';

            foreach ($summary['top_prefixes'] as $prefix_entry) {
                printf(
                    '<li><span class="sitepulse-transient-purge-prefix">%1$s</span> — %2$s</li>',
                    esc_html($prefix_entry['prefix']),
                    esc_html(sprintf(
                        _n('%s suppression', '%s suppressions', $prefix_entry['deleted'], 'sitepulse'),
                        number_format_i18n($prefix_entry['deleted'])
                    ))
                );
            }

            echo '</ul>';
        }

        printf(
            '<p class="sitepulse-transient-purge-total"><span class="count">%1$s</span> · <span class="unique">%2$s</span></p>',
            esc_html(sprintf(
                _n('%s suppression totale sur 30 jours', '%s suppressions totales sur 30 jours', $summary['totals']['deleted'], 'sitepulse'),
                number_format_i18n($summary['totals']['deleted'])
            )),
            esc_html(sprintf(
                _n('%s clé unique', '%s clés uniques', $summary['totals']['unique'], 'sitepulse'),
                number_format_i18n($summary['totals']['unique'])
            ))
        );
    }
  }

  if (function_exists('add_action')) {
    add_action('sitepulse_transient_deletion_completed', 'sitepulse_record_transient_purge_stats', 10, 2);
    add_action('wp_dashboard_setup', 'sitepulse_register_transient_purge_dashboard_widget');
  }

if (!function_exists('sitepulse_delete_transients_by_prefix')) {
    /**
     * Deletes all transients whose names start with the provided prefix.
     *
     * The deletion is performed in batches to avoid long-running queries on
     * large `wp_options` tables. When an external object cache is active we
     * also invalidate the relevant groups to prevent ghost entries from
     * sticking around in Redis/Memcached.
     *
     * @param string     $prefix Transient prefix to match.
     * @param array|null $args   Optional arguments. Supported keys: `batch_size`.
     * @return void
     */
    function sitepulse_delete_transients_by_prefix($prefix, $args = null) {
        global $wpdb;

        if (!is_string($prefix) || $prefix === '' || !($wpdb instanceof wpdb)) {
            return;
        }

        $defaults = [
            'batch_size'   => defined('SITEPULSE_TRANSIENT_DELETE_BATCH') ? (int) SITEPULSE_TRANSIENT_DELETE_BATCH : 200,
            'max_batches'  => 0,
            'return_stats' => false,
            'skip_logging' => false,
        ];

        $args = is_array($args) ? array_merge($defaults, $args) : $defaults;

        $batch_size = isset($args['batch_size']) ? (int) $args['batch_size'] : 200;
        $batch_size = max(20, $batch_size);

        if (function_exists('apply_filters')) {
            $batch_size = (int) apply_filters('sitepulse_transient_delete_batch_size', $batch_size, $prefix, $args);
        }

        if ($batch_size < 20) {
            $batch_size = 20;
        }

        $max_batches  = isset($args['max_batches']) ? (int) $args['max_batches'] : 0;
        $max_batches  = max(0, $max_batches);
        $return_stats = !empty($args['return_stats']);
        $skip_logging = !empty($args['skip_logging']);

        $like             = $wpdb->esc_like($prefix) . '%';
        $value_prefix     = strlen('_transient_');
        $timeout_prefix   = strlen('_transient_timeout_');
        $last_option_id   = 0;
        $deleted          = 0;
        $batches          = 0;
        $object_cache_hit = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $deleted_keys     = [];
        $has_more         = false;

        do {
            $query = $wpdb->prepare(
                "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_id > %d AND (option_name LIKE %s OR option_name LIKE %s) ORDER BY option_id ASC LIMIT %d",
                $last_option_id,
                '_transient_' . $like,
                '_transient_timeout_' . $like,
                $batch_size
            );

            $rows = $wpdb->get_results($query, ARRAY_A);

            if (empty($rows)) {
                $has_more = false;
                break;
            }

            $batch_keys = [];

            foreach ($rows as $row) {
                $option_name = isset($row['option_name']) ? (string) $row['option_name'] : '';
                $last_option_id = isset($row['option_id']) ? (int) $row['option_id'] : $last_option_id;

                if ($option_name === '') {
                    continue;
                }

                if (strpos($option_name, '_transient_timeout_') === 0) {
                    $transient_key = substr($option_name, $timeout_prefix);
                } else {
                    $transient_key = substr($option_name, $value_prefix);
                }

                if ($transient_key !== '') {
                    $batch_keys[$transient_key] = true;
                }
            }

            if (!empty($batch_keys)) {
                foreach (array_keys($batch_keys) as $transient_key) {
                    delete_transient($transient_key);

                    if ($object_cache_hit && function_exists('wp_cache_delete')) {
                        wp_cache_delete($transient_key, 'transient');
                        wp_cache_delete($transient_key, 'transient_timeout');
                        wp_cache_delete($transient_key, 'site-transient');
                        wp_cache_delete($transient_key, 'site-transient_timeout');
                    }

                    $deleted_keys[$transient_key] = true;
                }

                $deleted += count($batch_keys);
                ++$batches;

                if (function_exists('do_action')) {
                    do_action(
                        'sitepulse_transient_deletion_batch',
                        $prefix,
                        [
                            'deleted'          => count($batch_keys),
                            'batch_size'       => $batch_size,
                            'object_cache_hit' => $object_cache_hit,
                        ]
                    );
                }
            }

            $has_more = count($rows) === $batch_size;

            if ($max_batches > 0 && $batches >= $max_batches) {
                break;
            }
        } while ($has_more);

        if ($deleted > 0 && !$skip_logging && !$has_more) {
            if (function_exists('sitepulse_register_transient_purge_entry')) {
                sitepulse_register_transient_purge_entry(
                    'transient',
                    $prefix,
                    [
                        'deleted'      => $deleted,
                        'unique'       => count($deleted_keys),
                        'batches'      => $batches,
                        'object_cache' => $object_cache_hit,
                    ]
                );
            }

            if (function_exists('do_action')) {
                do_action(
                    'sitepulse_transient_deletion_completed',
                    $prefix,
                    [
                        'deleted'          => $deleted,
                        'unique_keys'      => array_keys($deleted_keys),
                        'batches'          => $batches,
                        'object_cache_hit' => $object_cache_hit,
                        'scope'            => 'transient',
                        'already_logged'   => true,
                    ]
                );
            }
        }

        if ($return_stats) {
            return [
                'deleted'          => $deleted,
                'unique'           => count($deleted_keys),
                'batches'          => $batches,
                'has_more'         => $has_more,
                'object_cache_hit' => $object_cache_hit,
            ];
        }
    }
}

if (!function_exists('sitepulse_get_gemini_api_key')) {
    /**
     * Retrieves the Gemini API key while honoring code-level overrides.
     *
     * The lookup order is:
     * 1. Constant override via SITEPULSE_GEMINI_API_KEY.
     * 2. Filter override via `sitepulse_gemini_api_key`.
     * 3. Stored option fallback.
     *
     * @return string Sanitized Gemini API key.
     */
    function sitepulse_get_gemini_api_key() {
        $api_key = '';

        if (defined('SITEPULSE_GEMINI_API_KEY')) {
            $api_key = (string) SITEPULSE_GEMINI_API_KEY;
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('sitepulse_gemini_api_key', $api_key);

            if (is_string($filtered)) {
                $api_key = $filtered;
            } elseif (is_scalar($filtered)) {
                $api_key = (string) $filtered;
            }
        }

        $api_key = trim($api_key);

        if ($api_key === '') {
            $option_value = get_option(SITEPULSE_OPTION_GEMINI_API_KEY, '');
            $api_key      = is_string($option_value) ? trim($option_value) : '';
        }

        return $api_key;
    }
}

if (!function_exists('sitepulse_is_gemini_api_key_overridden')) {
    /**
     * Determines whether the Gemini API key is overridden via code.
     *
     * @return bool
     */
    function sitepulse_is_gemini_api_key_overridden() {
        if (defined('SITEPULSE_GEMINI_API_KEY') && trim((string) SITEPULSE_GEMINI_API_KEY) !== '') {
            return true;
        }

        if (
            function_exists('has_filter')
            && function_exists('apply_filters')
            && has_filter('sitepulse_gemini_api_key')
        ) {
            $filtered = apply_filters('sitepulse_gemini_api_key', '');

            if (is_string($filtered)) {
                return trim($filtered) !== '';
            }

            if (is_scalar($filtered)) {
                return trim((string) $filtered) !== '';
            }
        }

        return false;
    }
}

if (!function_exists('sitepulse_get_speed_thresholds')) {
    /**
     * Retrieves the configured speed thresholds for warning and critical states.
     *
     * @param string $profile Optional performance profile (default, mobile, desktop...).
     * @return array{warning:int,critical:int}
     */
    function sitepulse_get_speed_thresholds($profile = 'default') {
        $profile = is_string($profile) ? strtolower(trim($profile)) : 'default';

        if ($profile === '') {
            $profile = 'default';
        }

        $default_warning  = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
        $default_critical = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;

        $profiles_option_key = defined('SITEPULSE_OPTION_SPEED_PROFILES') ? SITEPULSE_OPTION_SPEED_PROFILES : 'sitepulse_speed_profiles';
        $option_warning_key  = defined('SITEPULSE_OPTION_SPEED_WARNING_MS') ? SITEPULSE_OPTION_SPEED_WARNING_MS : 'sitepulse_speed_warning_ms';
        $option_critical_key = defined('SITEPULSE_OPTION_SPEED_CRITICAL_MS') ? SITEPULSE_OPTION_SPEED_CRITICAL_MS : 'sitepulse_speed_critical_ms';

        $profiles = get_option($profiles_option_key, []);

        if (!is_array($profiles)) {
            $profiles = [];
        }

        $raw_warning  = null;
        $raw_critical = null;

        if (isset($profiles[$profile]) && is_array($profiles[$profile])) {
            $raw_warning  = $profiles[$profile]['warning'] ?? null;
            $raw_critical = $profiles[$profile]['critical'] ?? null;
        }

        if ($raw_warning === null) {
            $raw_warning = get_option($option_warning_key, $default_warning);
        }

        if ($raw_critical === null) {
            $raw_critical = get_option($option_critical_key, $default_critical);
        }

        $warning_ms  = is_scalar($raw_warning) ? (int) $raw_warning : 0;
        $critical_ms = is_scalar($raw_critical) ? (int) $raw_critical : 0;
        $corrections = [];

        if ($warning_ms <= 0) {
            $warning_ms   = $default_warning;
            $corrections[] = 'warning_default';
        }

        if ($critical_ms <= 0) {
            $critical_ms   = $default_critical;
            $corrections[] = 'critical_default';
        }

        if ($critical_ms <= $warning_ms) {
            $critical_ms   = max($warning_ms + 1, $default_critical);
            $corrections[] = 'critical_adjusted';
        }

        $thresholds = [
            'warning'  => $warning_ms,
            'critical' => $critical_ms,
        ];

        if (function_exists('apply_filters')) {
            $thresholds = apply_filters('sitepulse_speed_thresholds', $thresholds, $profile, $corrections);
        }

        if (!empty($corrections)) {
            if (function_exists('do_action')) {
                do_action('sitepulse_speed_threshold_corrected', $profile, $thresholds, $corrections);
            }

            if (function_exists('sitepulse_log')) {
                sitepulse_log(sprintf('Speed thresholds corrected for profile %s (%s)', $profile, implode(', ', $corrections)), 'WARNING');
            }
        }

        return $thresholds;
    }
}

if (!function_exists('sitepulse_get_speed_warning_threshold')) {
    /**
     * Returns the configured warning speed threshold in milliseconds.
     *
     * @return int
     */
    function sitepulse_get_speed_warning_threshold() {
        $thresholds = sitepulse_get_speed_thresholds();

        return isset($thresholds['warning']) ? (int) $thresholds['warning'] : 0;
    }
}

if (!function_exists('sitepulse_get_speed_critical_threshold')) {
    /**
     * Returns the configured critical speed threshold in milliseconds.
     *
     * @return int
     */
    function sitepulse_get_speed_critical_threshold() {
        $thresholds = sitepulse_get_speed_thresholds();

        return isset($thresholds['critical']) ? (int) $thresholds['critical'] : 0;
    }
}

if (!function_exists('sitepulse_get_uptime_warning_percentage')) {
    /**
     * Returns the uptime warning threshold as a percentage.
     *
     * @return float
     */
    function sitepulse_get_uptime_warning_percentage() {
        $default_percentage = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0;
        $option_key = defined('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT') ? SITEPULSE_OPTION_UPTIME_WARNING_PERCENT : 'sitepulse_uptime_warning_percent';
        $value = get_option($option_key, $default_percentage);

        if (!is_scalar($value)) {
            return $default_percentage;
        }

        $percentage = (float) $value;

        if ($percentage <= 0) {
            return $default_percentage;
        }

        if ($percentage > 100) {
            return 100.0;
        }

        return $percentage;
    }
}

if (!function_exists('sitepulse_get_revision_limit')) {
    /**
     * Returns the configured revision limit used for database health checks.
     *
     * @return int
     */
    function sitepulse_get_revision_limit() {
        $default_limit = defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? (int) SITEPULSE_DEFAULT_REVISION_LIMIT : 100;
        $option_key = defined('SITEPULSE_OPTION_REVISION_LIMIT') ? SITEPULSE_OPTION_REVISION_LIMIT : 'sitepulse_revision_limit';
        $value = get_option($option_key, $default_limit);

        if (!is_scalar($value)) {
            return $default_limit;
        }

        $limit = (int) $value;

        if ($limit < 1) {
            return $default_limit;
        }

        return $limit;
    }
}

if (!function_exists('sitepulse_delete_site_transients_by_prefix')) {
    /**
     * Deletes all site transients whose names start with the provided prefix.
     *
     * @param string $prefix Site transient prefix to match.
     * @return void
     */
    function sitepulse_delete_site_transients_by_prefix($prefix, $args = null) {
        if (!function_exists('delete_site_transient')) {
            return;
        }

        global $wpdb;

        if (!is_string($prefix) || $prefix === '' || !($wpdb instanceof wpdb)) {
            return;
        }

        $defaults = [
            'batch_size'   => defined('SITEPULSE_TRANSIENT_DELETE_BATCH') ? (int) SITEPULSE_TRANSIENT_DELETE_BATCH : 200,
            'max_batches'  => 0,
            'return_stats' => false,
            'skip_logging' => false,
            'state'        => [],
        ];

        $args = is_array($args) ? array_merge($defaults, $args) : $defaults;

        $batch_size = isset($args['batch_size']) ? (int) $args['batch_size'] : 200;
        $batch_size = max(20, $batch_size);
        $max_batches = isset($args['max_batches']) ? (int) $args['max_batches'] : 0;
        $max_batches = max(0, $max_batches);
        $return_stats = !empty($args['return_stats']);
        $skip_logging = !empty($args['skip_logging']);
        $state = is_array($args['state']) ? $args['state'] : [];

        $targets = [];

        if (!empty($wpdb->sitemeta)) {
            $targets['sitemeta'] = [
                'table'      => $wpdb->sitemeta,
                'column'     => 'meta_key',
                'id_column'  => 'meta_id',
                'cache_group'=> 'site-options',
            ];
        }

        if (!empty($wpdb->options)) {
            $targets['options'] = [
                'table'      => $wpdb->options,
                'column'     => 'option_name',
                'id_column'  => 'option_id',
                'cache_group'=> 'options',
            ];
        }

        if (empty($targets)) {
            return;
        }

        $like           = $wpdb->esc_like($prefix) . '%';
        $value_prefix   = strlen('_site_transient_');
        $timeout_prefix = strlen('_site_transient_timeout_');
        $object_cache_hit = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $deleted        = 0;
        $batches        = 0;
        $has_more       = false;
        $next_state     = [];
        $remaining_batches = $max_batches > 0 ? $max_batches : PHP_INT_MAX;

        foreach ($targets as $key => $target) {
            $table      = isset($target['table']) ? (string) $target['table'] : '';
            $column     = isset($target['column']) ? (string) $target['column'] : '';
            $id_column  = isset($target['id_column']) ? (string) $target['id_column'] : '';

            if ($table === '' || $column === '' || $id_column === '') {
                $next_state[$key] = ['last_id' => 0, 'has_more' => false];
                continue;
            }

            $last_id = isset($state[$key]['last_id']) ? (int) $state[$key]['last_id'] : 0;
            $target_has_more = false;

            do {
                $query = $wpdb->prepare(
                    "SELECT {$id_column} AS id, {$column} AS name FROM {$table} WHERE {$id_column} > %d AND ({$column} LIKE %s OR {$column} LIKE %s) ORDER BY {$id_column} ASC LIMIT %d",
                    $last_id,
                    '_site_transient_' . $like,
                    '_site_transient_timeout_' . $like,
                    $batch_size
                );

                $rows = $wpdb->get_results($query, ARRAY_A);

                if (empty($rows)) {
                    $target_has_more = false;
                    break;
                }

                $batch_keys = [];

                foreach ($rows as $row) {
                    $name = isset($row['name']) ? (string) $row['name'] : '';
                    $last_id = isset($row['id']) ? (int) $row['id'] : $last_id;

                    if ($name === '') {
                        continue;
                    }

                    if (strpos($name, '_site_transient_timeout_') === 0) {
                        $transient_key = substr($name, $timeout_prefix);
                    } else {
                        $transient_key = substr($name, $value_prefix);
                    }

                    if ($transient_key !== '') {
                        $batch_keys[$transient_key] = true;
                    }
                }

                if (!empty($batch_keys)) {
                    foreach (array_keys($batch_keys) as $transient_key) {
                        delete_site_transient($transient_key);

                        if ($object_cache_hit && function_exists('wp_cache_delete')) {
                            wp_cache_delete($transient_key, 'site-transient');
                            wp_cache_delete($transient_key, 'site-transient_timeout');
                            wp_cache_delete($transient_key, 'transient');
                            wp_cache_delete($transient_key, 'transient_timeout');
                        }
                    }

                    $deleted += count($batch_keys);
                    ++$batches;

                    if ($remaining_batches !== PHP_INT_MAX) {
                        --$remaining_batches;
                    }

                    if ($remaining_batches === 0) {
                        $target_has_more = true;
                        break;
                    }
                }

                $target_has_more = $target_has_more || count($rows) === $batch_size;

                if ($remaining_batches === 0) {
                    break;
                }
            } while ($target_has_more && $remaining_batches > 0);

            $next_state[$key] = [
                'last_id'  => $last_id,
                'has_more' => $target_has_more,
            ];

            $has_more = $has_more || $target_has_more;

            if ($remaining_batches === 0) {
                break;
            }
        }

        if ($deleted > 0 && !$skip_logging && !$has_more) {
            if (function_exists('sitepulse_register_transient_purge_entry')) {
                sitepulse_register_transient_purge_entry(
                    'site-transient',
                    $prefix,
                    [
                        'deleted'      => $deleted,
                        'unique'       => $deleted,
                        'batches'      => $batches,
                        'object_cache' => $object_cache_hit,
                    ]
                );
            }

            if (function_exists('do_action')) {
                do_action(
                    'sitepulse_transient_deletion_completed',
                    $prefix,
                    [
                        'deleted'          => $deleted,
                        'unique'           => $deleted,
                        'batches'          => $batches,
                        'object_cache_hit' => $object_cache_hit,
                        'scope'            => 'site-transient',
                        'already_logged'   => true,
                    ]
                );
            }
        }

        if ($return_stats) {
            return [
                'deleted'          => $deleted,
                'batches'          => $batches,
                'has_more'         => $has_more,
                'object_cache_hit' => $object_cache_hit,
                'state'            => $next_state,
            ];
        }
    }
}

if (!function_exists('sitepulse_async_format_number')) {
    /**
     * Formats integers using WordPress localisation when available.
     *
     * @param int $value Raw integer value.
     *
     * @return string
     */
    function sitepulse_async_format_number($value) {
        if (function_exists('number_format_i18n')) {
            return number_format_i18n((int) $value);
        }

        return number_format((int) $value);
    }
}

if (!function_exists('sitepulse_get_async_jobs')) {
    /**
     * Retrieves the asynchronous job queue.
     *
     * @return array<string,array<string,mixed>>
     */
    function sitepulse_get_async_jobs() {
        if (!function_exists('get_option')) {
            return [];
        }

        $raw_jobs = get_option(SITEPULSE_OPTION_ASYNC_JOBS, []);

        if (!is_array($raw_jobs)) {
            return [];
        }

        $jobs = [];

        foreach ($raw_jobs as $job) {
            if (!is_array($job) || empty($job['id'])) {
                continue;
            }

            $jobs[(string) $job['id']] = $job;
        }

        return $jobs;
    }
}

if (!function_exists('sitepulse_save_async_jobs')) {
    /**
     * Persists the asynchronous job queue.
     *
     * @param array<string,array<string,mixed>> $jobs Job registry keyed by job identifier.
     *
     * @return void
     */
    function sitepulse_save_async_jobs($jobs) {
        if (!function_exists('update_option')) {
            return;
        }

        if (!is_array($jobs)) {
            $jobs = [];
        }

        update_option(SITEPULSE_OPTION_ASYNC_JOBS, $jobs, false);
    }
}

if (!function_exists('sitepulse_async_job_add_log')) {
    /**
     * Appends a log entry to an asynchronous job payload.
     *
     * @param array<string,mixed> &$job    Mutable job payload.
     * @param string              $message Message to append.
     * @param string              $level   Optional level (info, success, warning, error).
     *
     * @return void
     */
    function sitepulse_async_job_add_log(&$job, $message, $level = 'info') {
        if (!is_array($job)) {
            return;
        }

        if (!isset($job['logs']) || !is_array($job['logs'])) {
            $job['logs'] = [];
        }

        $allowed_levels = ['info', 'success', 'warning', 'error'];
        $normalized_level = is_string($level) ? strtolower(trim($level)) : 'info';

        if (!in_array($normalized_level, $allowed_levels, true)) {
            $normalized_level = 'info';
        }

        $timestamp = function_exists('current_time') ? current_time('timestamp') : time();

        $job['logs'][] = [
            'timestamp' => $timestamp,
            'message'   => is_string($message) ? $message : (string) $message,
            'level'     => $normalized_level,
        ];

        if (count($job['logs']) > 25) {
            $job['logs'] = array_slice($job['logs'], -25);
        }
    }
}

if (!function_exists('sitepulse_async_build_relative_label')) {
    /**
     * Formats a relative timestamp for async job summaries.
     *
     * @param int    $timestamp Unix timestamp.
     * @param string $context   Either "updated" or "log".
     *
     * @return string
     */
    function sitepulse_async_build_relative_label($timestamp, $context = 'updated') {
        $timestamp = (int) $timestamp;

        if ($timestamp <= 0 || !function_exists('human_time_diff')) {
            return '';
        }

        $reference = function_exists('current_time') ? current_time('timestamp') : time();
        $diff = human_time_diff($timestamp, $reference);

        if ($diff === '') {
            return '';
        }

        if ($context === 'log') {
            return sprintf(__('Il y a %s', 'sitepulse'), $diff);
        }

        return sprintf(__('Mis à jour il y a %s', 'sitepulse'), $diff);
    }
}

if (!function_exists('sitepulse_prepare_async_jobs_overview')) {
    /**
     * Normalizes async job payloads for UI consumption.
     *
     * @param array<string,array<string,mixed>>|null $jobs Optional raw jobs payload.
     * @param array<string,mixed>|null               $args Optional arguments (limit, include_logs).
     *
     * @return array<int,array<string,mixed>>
     */
    function sitepulse_prepare_async_jobs_overview($jobs = null, $args = null) {
        if ($jobs === null) {
            $jobs = sitepulse_get_async_jobs();
        }

        if (!is_array($jobs)) {
            $jobs = [];
        }

        $defaults = [
            'limit'        => 4,
            'include_logs' => true,
        ];

        $args = is_array($args) ? array_merge($defaults, $args) : $defaults;

        $limit = isset($args['limit']) ? (int) $args['limit'] : 4;
        $include_logs = !empty($args['include_logs']);

        if (function_exists('apply_filters')) {
            $limit = (int) apply_filters('sitepulse_async_overview_limit', $limit, $jobs, $args);
        }

        if ($limit < 0) {
            $limit = 0;
        }

        $prepared = [];

        foreach ($jobs as $job) {
            if (!is_array($job) || empty($job['type'])) {
                continue;
            }

            $job_type = (string) $job['type'];

            if (!in_array($job_type, ['transient_cleanup', 'plugin_reset'], true)) {
                continue;
            }

            $status = isset($job['status']) ? (string) $job['status'] : 'queued';
            $updated_at = isset($job['updated_at']) ? (int) $job['updated_at'] : 0;
            $progress = isset($job['progress']) ? (float) $job['progress'] : 0.0;
            $progress = max(0.0, min(1.0, $progress));
            $progress_percent = (int) round($progress * 100);

            switch ($job_type) {
                case 'plugin_reset':
                    $label = __('Réinitialisation de SitePulse', 'sitepulse');
                    break;
                case 'transient_cleanup':
                default:
                    $label = __('Purge des transients expirés', 'sitepulse');
                    break;
            }

            switch ($status) {
                case 'completed':
                    $status_label = __('Terminé', 'sitepulse');
                    $badge_class = 'is-success';
                    $container_class = 'is-success';
                    break;
                case 'failed':
                    $status_label = __('Échec', 'sitepulse');
                    $badge_class = 'is-critical';
                    $container_class = 'is-error';
                    break;
                case 'running':
                    $status_label = __('En cours', 'sitepulse');
                    $badge_class = 'is-active';
                    $container_class = 'is-running';
                    break;
                case 'queued':
                default:
                    $status_label = __('En attente', 'sitepulse');
                    $badge_class = 'is-warning';
                    $container_class = 'is-pending';
                    break;
            }

            $message = isset($job['message']) ? (string) $job['message'] : '';

            if ($message !== '') {
                $message = function_exists('wp_strip_all_tags')
                    ? wp_strip_all_tags($message)
                    : strip_tags($message);
            }

            $progress_label = '';

            if ($progress_percent > 0 && $progress_percent < 100) {
                $display_percent = function_exists('number_format_i18n')
                    ? number_format_i18n($progress_percent)
                    : number_format($progress_percent);

                $progress_label = sprintf(__('Progression : %s%%', 'sitepulse'), $display_percent);
            }

            $logs = [];

            if ($include_logs && isset($job['logs']) && is_array($job['logs'])) {
                $log_entries = array_slice($job['logs'], -5);

                usort(
                    $log_entries,
                    static function ($a, $b) {
                        $a_time = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
                        $b_time = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;

                        return $b_time <=> $a_time;
                    }
                );

                foreach ($log_entries as $log_entry) {
                    if (!is_array($log_entry)) {
                        continue;
                    }

                    $log_message = isset($log_entry['message']) ? (string) $log_entry['message'] : '';

                    if ($log_message === '') {
                        continue;
                    }

                    $log_message = function_exists('wp_strip_all_tags')
                        ? wp_strip_all_tags($log_message)
                        : strip_tags($log_message);

                    $log_timestamp = isset($log_entry['timestamp']) ? (int) $log_entry['timestamp'] : 0;
                    $log_level = isset($log_entry['level']) ? (string) $log_entry['level'] : 'info';

                    switch ($log_level) {
                        case 'success':
                            $log_label = __('Succès', 'sitepulse');
                            $log_class = 'is-success';
                            break;
                        case 'warning':
                            $log_label = __('Avertissement', 'sitepulse');
                            $log_class = 'is-warning';
                            break;
                        case 'error':
                            $log_label = __('Erreur', 'sitepulse');
                            $log_class = 'is-error';
                            break;
                        case 'info':
                        default:
                            $log_label = __('Information', 'sitepulse');
                            $log_class = 'is-info';
                            break;
                    }

                    $logs[] = [
                        'message'     => $log_message,
                        'level'       => $log_level,
                        'level_label' => $log_label,
                        'level_class' => $log_class,
                        'timestamp'   => $log_timestamp,
                        'relative'    => sitepulse_async_build_relative_label($log_timestamp, 'log'),
                        'iso'         => $log_timestamp > 0 && function_exists('date_i18n') ? date_i18n('c', $log_timestamp) : '',
                    ];
                }
            }

            $prepared[] = [
                'id'               => isset($job['id']) ? (string) $job['id'] : uniqid('job_', true),
                'type'             => $job_type,
                'label'            => $label,
                'status'           => $status,
                'status_label'     => $status_label,
                'badge_class'      => $badge_class,
                'container_class'  => $container_class,
                'message'          => $message,
                'relative'         => sitepulse_async_build_relative_label($updated_at, 'updated'),
                'updated_at'       => $updated_at,
                'progress'         => $progress,
                'progress_percent' => $progress_percent,
                'progress_label'   => $progress_label,
                'logs'             => $logs,
                'is_active'        => in_array($status, ['queued', 'running'], true),
            ];
        }

        if (!empty($prepared)) {
            usort(
                $prepared,
                static function ($a, $b) {
                    return ($b['updated_at'] <=> $a['updated_at']);
                }
            );
        }

        if ($limit > 0 && count($prepared) > $limit) {
            $prepared = array_slice($prepared, 0, $limit);
        }

        return $prepared;
    }
}

if (!function_exists('sitepulse_schedule_async_runner')) {
    /**
     * Schedules the asynchronous job runner.
     *
     * @param int $delay Number of seconds before the runner executes.
     *
     * @return void
     */
    function sitepulse_schedule_async_runner($delay = 0) {
        if (!function_exists('wp_schedule_single_event') || !function_exists('wp_next_scheduled')) {
            return;
        }

        $hook = defined('SITEPULSE_CRON_ASYNC_JOB_RUNNER') ? SITEPULSE_CRON_ASYNC_JOB_RUNNER : 'sitepulse_run_async_jobs';
        $timestamp = time() + max(0, (int) $delay);

        if (wp_next_scheduled($hook)) {
            return;
        }

        $scheduled = wp_schedule_single_event($timestamp, $hook);

        if ($scheduled && function_exists('spawn_cron')) {
            spawn_cron($timestamp);
        }
    }
}

if (!function_exists('sitepulse_enqueue_async_job')) {
    /**
     * Queues a new asynchronous job.
     *
     * @param string               $type     Job type identifier.
     * @param array<string,mixed>  $payload  Optional payload.
     * @param array<string,mixed>  $metadata Optional metadata such as label or requesting user.
     *
     * @return array<string,mixed>|false
     */
    function sitepulse_enqueue_async_job($type, $payload = [], $metadata = []) {
        if (!is_string($type) || $type === '') {
            return false;
        }

        $jobs = sitepulse_get_async_jobs();

        $job_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('sitepulse_job_', true);
        $timestamp = function_exists('current_time') ? current_time('timestamp') : time();

        $job = [
            'id'         => (string) $job_id,
            'type'       => $type,
            'status'     => 'queued',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'progress'   => 0,
            'message'    => __('En file d’attente pour exécution.', 'sitepulse'),
            'payload'    => is_array($payload) ? $payload : [],
            'logs'       => [],
            'meta'       => [
                'label'        => isset($metadata['label']) ? (string) $metadata['label'] : '',
                'requested_by' => isset($metadata['requested_by']) ? (int) $metadata['requested_by'] : 0,
            ],
        ];

        sitepulse_async_job_add_log(
            $job,
            __('Tâche planifiée et en attente d’exécution.', 'sitepulse'),
            'info'
        );

        $jobs[$job['id']] = $job;

        sitepulse_save_async_jobs($jobs);
        sitepulse_schedule_async_runner();

        return $job;
    }
}

if (!function_exists('sitepulse_process_async_jobs')) {
    /**
     * Cron callback responsible for processing queued jobs.
     *
     * @return void
     */
    function sitepulse_process_async_jobs() {
        $lock_key = defined('SITEPULSE_TRANSIENT_ASYNC_LOCK') ? SITEPULSE_TRANSIENT_ASYNC_LOCK : 'sitepulse_async_jobs_lock';

        if (function_exists('get_transient') && get_transient($lock_key)) {
            return;
        }

        if (function_exists('set_transient')) {
            set_transient($lock_key, 1, MINUTE_IN_SECONDS);
        }

        $jobs = sitepulse_get_async_jobs();
        $updated = false;
        $current_time = function_exists('current_time') ? current_time('timestamp') : time();

        foreach ($jobs as $job_id => $job) {
            $status = isset($job['status']) ? $job['status'] : 'queued';

            if ($status !== 'queued' && $status !== 'running') {
                continue;
            }

            if (!is_array($job)) {
                continue;
            }

            $job['status'] = 'running';
            $job['updated_at'] = $current_time;

            if ($status === 'queued') {
                sitepulse_async_job_add_log(
                    $job,
                    __('Tâche en cours d’exécution.', 'sitepulse'),
                    'info'
                );
            }

            $result = sitepulse_run_async_job($job);

            if (is_array($result)) {
                $jobs[$job_id] = $result;
                $updated = true;
            }

            // Process a single job per cron invocation to avoid timeouts.
            break;
        }

        if ($updated) {
            sitepulse_save_async_jobs($jobs);
        }

        $has_pending = false;

        foreach ($jobs as $job_state) {
            $job_status = isset($job_state['status']) ? $job_state['status'] : '';

            if ($job_status === 'queued' || $job_status === 'running') {
                $has_pending = true;
                break;
            }
        }

        if ($has_pending) {
            sitepulse_schedule_async_runner(30);
        }

        if (function_exists('delete_transient')) {
            delete_transient($lock_key);
        }
    }

    if (function_exists('add_action')) {
        $hook = defined('SITEPULSE_CRON_ASYNC_JOB_RUNNER') ? SITEPULSE_CRON_ASYNC_JOB_RUNNER : 'sitepulse_run_async_jobs';
        add_action($hook, 'sitepulse_process_async_jobs');
    }
}

if (!function_exists('sitepulse_bootstrap_async_runner')) {
    /**
     * Ensures async jobs progress when WP-Cron is disabled.
     *
     * @return void
     */
    function sitepulse_bootstrap_async_runner() {
        static $processing = false;

        if ($processing) {
            return;
        }

        if (!function_exists('sitepulse_get_async_jobs')) {
            return;
        }

        $jobs = sitepulse_get_async_jobs();

        if (empty($jobs)) {
            return;
        }

        $has_pending = false;

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            $status = isset($job['status']) ? (string) $job['status'] : '';

            if ($status === 'queued' || $status === 'running') {
                $has_pending = true;
                break;
            }
        }

        if (!$has_pending) {
            return;
        }

        if (function_exists('wp_next_scheduled')) {
            $hook = defined('SITEPULSE_CRON_ASYNC_JOB_RUNNER') ? SITEPULSE_CRON_ASYNC_JOB_RUNNER : 'sitepulse_run_async_jobs';

            if (wp_next_scheduled($hook)) {
                return;
            }
        }

        if (!function_exists('sitepulse_process_async_jobs')) {
            return;
        }

        $processing = true;
        sitepulse_process_async_jobs();
        $processing = false;
    }

    if (function_exists('add_action')) {
        add_action('admin_init', 'sitepulse_bootstrap_async_runner', 5);
    }
}

if (!function_exists('sitepulse_run_async_job')) {
    /**
     * Dispatches the execution of an asynchronous job based on its type.
     *
     * @param array<string,mixed> $job Job payload.
     *
     * @return array<string,mixed>
     */
    function sitepulse_run_async_job($job) {
        if (!is_array($job) || empty($job['type'])) {
            return $job;
        }

        switch ($job['type']) {
            case 'transient_cleanup':
                return sitepulse_async_job_handle_transient_cleanup($job);
            case 'plugin_reset':
                return sitepulse_async_job_handle_plugin_reset($job);
            default:
                $job['status'] = 'failed';
                $job['progress'] = 1;
                $job['message'] = __('Tâche inconnue : impossible de poursuivre.', 'sitepulse');
                sitepulse_async_job_add_log($job, sprintf(__('Type de tâche inconnu : %s', 'sitepulse'), (string) $job['type']), 'error');

                return $job;
        }
    }
}

if (!function_exists('sitepulse_async_job_handle_transient_cleanup')) {
    /**
     * Processes the background cleanup of expired transients.
     *
     * @param array<string,mixed> $job Job payload.
     *
     * @return array<string,mixed>
     */
    function sitepulse_async_job_handle_transient_cleanup($job) {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            $job['status'] = 'failed';
            $job['progress'] = 1;
            $job['message'] = __('Base de données inaccessible : la purge des transients a échoué.', 'sitepulse');
            sitepulse_async_job_add_log($job, __('Impossible d’exécuter la purge sans accès à $wpdb.', 'sitepulse'), 'error');

            return $job;
        }

        $payload = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : [];
        $payload_defaults = [
            'deleted'      => 0,
            'runs'         => 0,
            'sources'      => [],
            'max_batches'  => 3,
            'prefix_label' => 'expired',
        ];
        $payload = array_merge($payload_defaults, $payload);

        $payload['runs'] = (int) $payload['runs'] + 1;
        $max_batches = (int) $payload['max_batches'];

        if ($max_batches <= 0) {
            $max_batches = 3;
        }

        $result = sitepulse_delete_expired_transients_fallback($wpdb, [
            'max_batches_per_source' => $max_batches,
            'return_stats'           => true,
        ]);

        $deleted_this_run = isset($result['deleted']) ? (int) $result['deleted'] : 0;
        $payload['deleted'] += $deleted_this_run;

        if (!empty($result['sources']) && is_array($result['sources'])) {
            foreach ($result['sources'] as $source_stats) {
                $scope = isset($source_stats['scope']) ? (string) $source_stats['scope'] : 'transient';

                if (!isset($payload['sources'][$scope])) {
                    $payload['sources'][$scope] = [
                        'deleted' => 0,
                        'batches' => 0,
                    ];
                }

                $payload['sources'][$scope]['deleted'] += isset($source_stats['deleted']) ? (int) $source_stats['deleted'] : 0;
                $payload['sources'][$scope]['batches'] += isset($source_stats['batches']) ? (int) $source_stats['batches'] : 0;
            }
        }

        $job['payload'] = $payload;

        if (!empty($result['has_more'])) {
            $job['status'] = 'running';
            $job['progress'] = min(0.95, 0.2 + ($payload['runs'] * 0.1));
            $job['message'] = sprintf(
                __('Purge en arrière-plan… %s transients supprimés.', 'sitepulse'),
                sitepulse_async_format_number($payload['deleted'])
            );
            sitepulse_async_job_add_log(
                $job,
                sprintf(__('Lot traité : %s suppressions supplémentaires.', 'sitepulse'), sitepulse_async_format_number($deleted_this_run))
            );

            return $job;
        }

        $job['status'] = 'completed';
        $job['progress'] = 1;
        $job['message'] = sprintf(
            __('Purge terminée : %s transients supprimés.', 'sitepulse'),
            sitepulse_async_format_number($payload['deleted'])
        );
        sitepulse_async_job_add_log(
            $job,
            sprintf(__('Purge finalisée (%s transients).', 'sitepulse'), sitepulse_async_format_number($payload['deleted'])),
            'success'
        );

        $object_cache_hit = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        if (!empty($payload['sources'])) {
            foreach ($payload['sources'] as $scope => $stats) {
                $deleted_scope = isset($stats['deleted']) ? (int) $stats['deleted'] : 0;
                $batches_scope = isset($stats['batches']) ? (int) $stats['batches'] : 0;

                if ($deleted_scope <= 0) {
                    continue;
                }

                if (function_exists('do_action')) {
                    $prefix = $scope === 'site-transient' ? $payload['prefix_label'] . '-network' : $payload['prefix_label'];

                    do_action(
                        'sitepulse_transient_deletion_completed',
                        $prefix,
                        [
                            'deleted'          => $deleted_scope,
                            'unique'           => $deleted_scope,
                            'batches'          => $batches_scope,
                            'object_cache_hit' => $object_cache_hit,
                            'scope'            => $scope,
                        ]
                    );
                }
            }
        }

        return $job;
    }
}

if (!function_exists('sitepulse_async_job_handle_plugin_reset')) {
    /**
     * Executes the plugin reset routine asynchronously.
     *
     * @param array<string,mixed> $job Job payload.
     *
     * @return array<string,mixed>
     */
    function sitepulse_async_job_handle_plugin_reset($job) {
        $payload = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : [];

        $defaults = [
            'options'            => [],
            'option_index'       => 0,
            'transients'         => [],
            'transient_index'    => 0,
            'prefixes'           => [],
            'prefix_index'       => 0,
            'prefix_summary'     => [],
            'prefix_state'       => [],
            'log_path'           => '',
            'log_deleted'        => false,
            'log_error'          => '',
            'cron_hooks'         => [],
            'cron_index'         => 0,
            'reactivated'        => false,
        ];

        $payload = array_merge($defaults, $payload);
        $job['payload'] = $payload;

        $step_message = '';

        // Step 1: delete stored options in batches.
        $options = is_array($payload['options']) ? $payload['options'] : [];
        $option_count = count($options);

        if ($payload['option_index'] < $option_count) {
            $batch = 0;
            $limit = 20;

            while ($payload['option_index'] < $option_count && $batch < $limit) {
                $option_key = $options[$payload['option_index']];

                if (is_string($option_key) && $option_key !== '' && function_exists('delete_option')) {
                    delete_option($option_key);
                }

                $payload['option_index']++;
                $batch++;
            }

            $job['payload'] = $payload;
            $job['status'] = 'running';
            $job['progress'] = min(0.2, ($payload['option_index'] / max(1, $option_count)) * 0.2);
            $step_message = sprintf(
                __('Réinitialisation : suppression des options (%1$s sur %2$s).', 'sitepulse'),
                sitepulse_async_format_number($payload['option_index']),
                sitepulse_async_format_number($option_count)
            );
            $job['message'] = $step_message;

            return $job;
        }

        // Step 2: delete named transients.
        $transients = is_array($payload['transients']) ? $payload['transients'] : [];
        $transient_count = count($transients);

        if ($payload['transient_index'] < $transient_count) {
            $batch = 0;
            $limit = 25;

            while ($payload['transient_index'] < $transient_count && $batch < $limit) {
                $transient_key = $transients[$payload['transient_index']];

                if (is_string($transient_key) && $transient_key !== '') {
                    if (function_exists('delete_transient')) {
                        delete_transient($transient_key);
                    }

                    if (function_exists('delete_site_transient')) {
                        delete_site_transient($transient_key);
                    }
                }

                $payload['transient_index']++;
                $batch++;
            }

            $job['payload'] = $payload;
            $job['status'] = 'running';
            $job['progress'] = 0.2 + min(0.2, ($payload['transient_index'] / max(1, $transient_count)) * 0.2);
            $job['message'] = sprintf(
                __('Réinitialisation : nettoyage des verrous (%1$s sur %2$s).', 'sitepulse'),
                sitepulse_async_format_number($payload['transient_index']),
                sitepulse_async_format_number($transient_count)
            );

            return $job;
        }

        // Step 3: purge transient prefixes progressively.
        $prefixes = is_array($payload['prefixes']) ? array_values($payload['prefixes']) : [];
        $prefix_count = count($prefixes);

        if ($payload['prefix_index'] < $prefix_count) {
            $current_prefix = $prefixes[$payload['prefix_index']];
            $current_prefix = is_string($current_prefix) ? $current_prefix : '';

            if ($current_prefix !== '') {
                $prefix_stats = isset($payload['prefix_summary'][$current_prefix]) && is_array($payload['prefix_summary'][$current_prefix])
                    ? $payload['prefix_summary'][$current_prefix]
                    : ['transient' => 0, 'site_transient' => 0, 'batches' => 0];

                $transient_result = sitepulse_delete_transients_by_prefix($current_prefix, [
                    'max_batches'  => 2,
                    'return_stats' => true,
                    'skip_logging' => true,
                ]);

                if (is_array($transient_result)) {
                    $prefix_stats['transient'] += isset($transient_result['deleted']) ? (int) $transient_result['deleted'] : 0;
                    $prefix_stats['batches'] += isset($transient_result['batches']) ? (int) $transient_result['batches'] : 0;

                    if (!empty($transient_result['has_more'])) {
                        $payload['prefix_summary'][$current_prefix] = $prefix_stats;
                        $job['payload'] = $payload;
                        $job['status'] = 'running';
                        $job['progress'] = 0.4 + min(0.3, ($payload['prefix_index'] / max(1, $prefix_count)) * 0.3);
                        $job['message'] = sprintf(
                            __('Réinitialisation : purge du préfixe %s en cours…', 'sitepulse'),
                            sanitize_text_field($current_prefix)
                        );

                        return $job;
                    }
                }

                $site_state = isset($payload['prefix_state'][$current_prefix]) ? $payload['prefix_state'][$current_prefix] : [];
                $site_result = sitepulse_delete_site_transients_by_prefix($current_prefix, [
                    'max_batches'  => 1,
                    'return_stats' => true,
                    'skip_logging' => true,
                    'state'        => $site_state,
                ]);

                if (is_array($site_result)) {
                    $prefix_stats['site_transient'] += isset($site_result['deleted']) ? (int) $site_result['deleted'] : 0;
                    $prefix_stats['batches'] += isset($site_result['batches']) ? (int) $site_result['batches'] : 0;
                    $payload['prefix_state'][$current_prefix] = isset($site_result['state']) ? $site_result['state'] : $site_state;

                    if (!empty($site_result['has_more'])) {
                        $payload['prefix_summary'][$current_prefix] = $prefix_stats;
                        $job['payload'] = $payload;
                        $job['status'] = 'running';
                        $job['progress'] = 0.4 + min(0.3, ($payload['prefix_index'] / max(1, $prefix_count)) * 0.3);
                        $job['message'] = sprintf(
                            __('Réinitialisation : purge réseau du préfixe %s…', 'sitepulse'),
                            sanitize_text_field($current_prefix)
                        );

                        return $job;
                    }
                }

                $payload['prefix_summary'][$current_prefix] = $prefix_stats;

                if (function_exists('do_action')) {
                    $total_deleted = (int) $prefix_stats['transient'] + (int) $prefix_stats['site_transient'];

                    if ($total_deleted > 0) {
                        do_action(
                            'sitepulse_transient_deletion_completed',
                            $current_prefix,
                            [
                                'deleted'          => $total_deleted,
                                'unique'           => $total_deleted,
                                'batches'          => (int) $prefix_stats['batches'],
                                'object_cache_hit' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
                                'scope'            => 'transient',
                                'already_logged'   => false,
                            ]
                        );
                    }
                }
            }

            $payload['prefix_index']++;
            $job['payload'] = $payload;
            $job['status'] = 'running';
            $job['progress'] = 0.4 + min(0.3, ($payload['prefix_index'] / max(1, $prefix_count)) * 0.3);
            $job['message'] = sprintf(
                __('Réinitialisation : %1$s/%2$s préfixes purgés.', 'sitepulse'),
                sitepulse_async_format_number($payload['prefix_index']),
                sitepulse_async_format_number($prefix_count)
            );

            return $job;
        }

        // Step 4: remove debug log file.
        if (!$payload['log_deleted'] && $payload['log_path'] !== '') {
            $log_path = $payload['log_path'];
            $deleted  = false;
            $error    = '';

            if (function_exists('wp_delete_file')) {
                $result = wp_delete_file($log_path);

                if ($result === false) {
                    $error = 'wp_delete_file returned false.';
                } elseif ($result instanceof WP_Error) {
                    $error = $result->get_error_message();
                }

                $deleted = file_exists($log_path) ? false : true;
            }

            if (!$deleted && file_exists($log_path)) {
                $deleted = @unlink($log_path);

                if (!$deleted && $error === '') {
                    $error = 'unlink failed.';
                }
            }

            $payload['log_deleted'] = $deleted;
            $payload['log_error'] = $deleted ? '' : $error;
            $job['payload'] = $payload;
            $job['status'] = 'running';
            $job['progress'] = 0.8;
            $job['message'] = $deleted
                ? __('Réinitialisation : journal de débogage supprimé.', 'sitepulse')
                : __('Réinitialisation : impossible de supprimer le journal (permissions).', 'sitepulse');

            if ($deleted) {
                sitepulse_async_job_add_log($job, __('Journal de débogage effacé.', 'sitepulse'), 'success');
            } elseif ($error !== '') {
                sitepulse_async_job_add_log($job, sprintf(__('Journal non supprimé (%s).', 'sitepulse'), $error), 'warning');
            }

            return $job;
        }

        // Step 5: clear scheduled hooks.
        $cron_hooks = is_array($payload['cron_hooks']) ? $payload['cron_hooks'] : [];
        $cron_count = count($cron_hooks);

        if ($payload['cron_index'] < $cron_count) {
            $batch = 0;
            $limit = 10;

            while ($payload['cron_index'] < $cron_count && $batch < $limit) {
                $hook = $cron_hooks[$payload['cron_index']];

                if (is_string($hook) && $hook !== '' && function_exists('wp_clear_scheduled_hook')) {
                    wp_clear_scheduled_hook($hook);
                }

                $payload['cron_index']++;
                $batch++;
            }

            $job['payload'] = $payload;
            $job['status'] = 'running';
            $job['progress'] = 0.9;
            $job['message'] = __('Réinitialisation : purge des tâches planifiées…', 'sitepulse');

            return $job;
        }

        // Step 6: reactivate default plugin state.
        if (!$payload['reactivated']) {
            if (function_exists('sitepulse_activate_site')) {
                sitepulse_activate_site();
            }

            $payload['reactivated'] = true;
            $job['payload'] = $payload;
            $job['status'] = 'running';
            $job['progress'] = 0.98;
            $job['message'] = __('Réinitialisation : configuration par défaut restaurée…', 'sitepulse');

            return $job;
        }

        $job['status'] = 'completed';
        $job['progress'] = 1;
        $job['message'] = __('SitePulse a été réinitialisé avec succès.', 'sitepulse');
        sitepulse_async_job_add_log($job, __('Réinitialisation terminée.', 'sitepulse'), 'success');

        return $job;
    }
}

if (!function_exists('sitepulse_get_ai_models')) {
    /**
     * Returns the list of supported AI models.
     *
     * The catalog is cached per-request and optionally persisted in a transient
     * to avoid running heavy filters on every call.
     *
     * @return array<string, array{label:string,description?:string,prompt_instruction?:string}>
     */
    function sitepulse_get_ai_models() {
        static $runtime_cache = null;

        if (is_array($runtime_cache)) {
            return $runtime_cache;
        }

        $default_models = [
            'gemini-1.5-flash' => [
                'label'              => __('Gemini 1.5 Flash', 'sitepulse'),
                'description'        => __('Réponses rapides et économiques, idéales pour obtenir des recommandations synthétiques à fréquence élevée.', 'sitepulse'),
                'prompt_instruction' => __('Fournis une synthèse claire et actionnable en te concentrant sur les gains rapides.', 'sitepulse'),
            ],
            'gemini-1.5-pro'   => [
                'label'              => __('Gemini 1.5 Pro', 'sitepulse'),
                'description'        => __('Analyse plus approfondie avec davantage de contexte et de détails, adaptée aux audits complets mais plus lente et coûteuse.', 'sitepulse'),
                'prompt_instruction' => __('Apporte une analyse détaillée et justifie chaque recommandation avec les impacts attendus.', 'sitepulse'),
            ],
        ];

        $use_cache    = function_exists('apply_filters') ? (bool) apply_filters('sitepulse_ai_models_enable_cache', true) : true;
        $transient_id = 'sitepulse_ai_models_catalog';

        if ($use_cache && function_exists('get_transient')) {
            $cached_models = get_transient($transient_id);

            if (is_array($cached_models) && !empty($cached_models)) {
                $runtime_cache = $cached_models;

                return $cached_models;
            }
        }

        $sanitized_models = [];
        $filtered_models  = function_exists('apply_filters') ? apply_filters('sitepulse_ai_models', $default_models) : $default_models;

        if (!is_array($filtered_models) || empty($filtered_models)) {
            $filtered_models = $default_models;
        }

        foreach ($filtered_models as $model_key => $model_data) {
            if (!is_string($model_key) || $model_key === '') {
                continue;
            }

            $model_key = trim($model_key);

            if ($model_key === '' || strlen($model_key) > 120) {
                continue;
            }

            if (is_string($model_data)) {
                $model_data = ['label' => $model_data];
            }

            if (!is_array($model_data)) {
                continue;
            }

            $label = isset($model_data['label']) ? (string) $model_data['label'] : '';

            if ($label === '') {
                $label = $model_key;
            }

            $sanitized_models[$model_key] = [
                'label' => $label,
            ];

            if (isset($model_data['description']) && is_string($model_data['description']) && $model_data['description'] !== '') {
                $sanitized_models[$model_key]['description'] = $model_data['description'];
            }

            if (isset($model_data['prompt_instruction']) && is_string($model_data['prompt_instruction']) && $model_data['prompt_instruction'] !== '') {
                $sanitized_models[$model_key]['prompt_instruction'] = $model_data['prompt_instruction'];
            }
        }

        if (empty($sanitized_models)) {
            $sanitized_models = $default_models;
        }

        if ($use_cache && function_exists('set_transient')) {
            $ttl = function_exists('apply_filters') ? (int) apply_filters('sitepulse_ai_models_cache_ttl', HOUR_IN_SECONDS, $sanitized_models) : HOUR_IN_SECONDS;

            if ($ttl > 0) {
                set_transient($transient_id, $sanitized_models, $ttl);
            }
        }

        $runtime_cache = $sanitized_models;

        if (function_exists('apply_filters')) {
            $runtime_cache = apply_filters('sitepulse_ai_models_sanitized', $runtime_cache);
        }

        return $runtime_cache;
    }
}

if (!function_exists('sitepulse_get_default_ai_model')) {
    /**
     * Returns the default AI model identifier.
     *
     * @return string
     */
    function sitepulse_get_default_ai_model() {
        $default = defined('SITEPULSE_DEFAULT_AI_MODEL') ? (string) SITEPULSE_DEFAULT_AI_MODEL : 'gemini-1.5-flash';
        $models  = sitepulse_get_ai_models();

        if (isset($models[$default])) {
            return $default;
        }

        $model_keys = array_keys($models);

        if (!empty($model_keys)) {
            return (string) $model_keys[0];
        }

        return 'gemini-1.5-flash';
    }
}

if (!function_exists('sitepulse_get_recent_log_lines')) {
    /**
     * Reads the last lines from a log file without loading it entirely in memory.
     *
     * The maximum number of bytes read is deliberately capped to avoid memory pressure
     * with very large log files.
     *
     * @param string $file_path       Path to the log file.
     * @param int    $max_lines       Number of lines to return.
     * @param int    $max_bytes       Maximum number of bytes to read from the end of the file.
     * @param bool   $with_metadata   Whether to include metadata (bytes read, truncation flag, etc.).
     * @return array|null Array of recent log lines on success, empty array if the file is empty,
     *                    or null on failure to read the file. When `$with_metadata` is true an
     *                    associative array is returned with the keys `lines`, `bytes_read`,
     *                    `file_size`, `truncated` and `last_modified`.
     */
    function sitepulse_get_recent_log_lines($file_path, $max_lines = 100, $max_bytes = 131072, $with_metadata = false) {
        $max_lines = max(1, (int) $max_lines);
        $max_bytes = max(1024, (int) $max_bytes);

        if (!is_readable($file_path)) {
            return null;
        }

        $fopen_error = null;
        set_error_handler(function ($errno, $errstr) use (&$fopen_error) {
            $fopen_error = $errstr;

            return true;
        });

        try {
            $handle = fopen($file_path, 'rb');
        } catch (\Throwable $exception) {
            $fopen_error = $exception->getMessage();
            $handle = false;
        } finally {
            restore_error_handler();
        }

        if (!$handle) {
            if (function_exists('sitepulse_log')) {
                $message = sprintf('Failed to open log file for reading: %s', $file_path);

                if (is_string($fopen_error) && $fopen_error !== '') {
                    $message .= ' | Error: ' . $fopen_error;
                }

                sitepulse_log($message, 'ERROR');
            }

            return null;
        }

        $locked = false;

        if (function_exists('flock')) {
            $locked = @flock($handle, LOCK_SH);
        }

        $buffer          = '';
        $chunk_size      = 4096;
        $stats           = fstat($handle);
        $file_size       = isset($stats['size']) ? (int) $stats['size'] : 0;
        $bytes_to_read   = min($file_size, $max_bytes);
        $position        = $file_size;
        $bytes_read      = 0;
        $max_iterations  = 500;
        $iterations      = 0;

        while ($bytes_to_read > 0 && $position > 0) {
            if (++$iterations > $max_iterations) {
                break;
            }

            $read_size = (int) min($chunk_size, $bytes_to_read, $position);

            if ($read_size <= 0) {
                break;
            }

            $position     -= $read_size;
            $bytes_to_read -= $read_size;

            if (fseek($handle, $position, SEEK_SET) !== 0) {
                break;
            }

            $chunk = fread($handle, $read_size);

            if ($chunk === false) {
                break;
            }

            $bytes_read += strlen($chunk);
            $buffer      = $chunk . $buffer;

            if (substr_count($buffer, "\n") >= ($max_lines + 1)) {
                break;
            }
        }

        if ($locked) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        if ($buffer === '') {
            return $with_metadata ? [
                'lines'         => [],
                'bytes_read'    => $bytes_read,
                'file_size'     => $file_size,
                'truncated'     => false,
                'last_modified' => isset($stats['mtime']) ? (int) $stats['mtime'] : null,
            ] : [];
        }

        $buffer = str_replace(["\r\n", "\r"], "\n", $buffer);
        $buffer = rtrim($buffer, "\n");

        if ($buffer === '') {
            return $with_metadata ? [
                'lines'         => [],
                'bytes_read'    => $bytes_read,
                'file_size'     => $file_size,
                'truncated'     => false,
                'last_modified' => isset($stats['mtime']) ? (int) $stats['mtime'] : null,
            ] : [];
        }

        $lines    = explode("\n", $buffer);
        $filtered = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $filtered[] = $line;
        }

        if (count($filtered) > $max_lines) {
            $filtered = array_slice($filtered, -$max_lines);
        }

        if (!$with_metadata) {
            return $filtered;
        }

        $truncated = $file_size > $bytes_read;

        return [
            'lines'         => $filtered,
            'bytes_read'    => $bytes_read,
            'file_size'     => $file_size,
            'truncated'     => $truncated,
            'last_modified' => isset($stats['mtime']) ? (int) $stats['mtime'] : null,
        ];
    }
}

if (!function_exists('sitepulse_get_alert_interval_choices')) {
    /**
     * Returns the allowed alert interval values (in minutes).
     *
     * @param mixed $context Optional context, forwarded to the filter hook.
     * @return int[]
     */
    function sitepulse_get_alert_interval_choices($context = null) {
        $allowed_values = [1, 2, 5, 10, 15, 30, 60, 120];

        if (function_exists('apply_filters')) {
            $allowed_values = apply_filters('sitepulse_alert_interval_allowed_values', $allowed_values, $context);
        }

        $allowed_values = array_map('absint', (array) $allowed_values);
        $allowed_values = array_values(array_filter($allowed_values, static function ($value) {
            return $value > 0;
        }));

        sort($allowed_values, SORT_NUMERIC);

        if (empty($allowed_values)) {
            $allowed_values = [5];
        }

        return $allowed_values;
    }
}

if (!function_exists('sitepulse_sanitize_alert_interval')) {
    /**
     * Sanitizes the alert interval (in minutes) used to schedule error checks.
     *
     * Supports extended ranges and an optional "smart" mode that can be
     * interpreted by integrations via the `sitepulse_alert_interval_smart_value`
     * filter.
     *
     * @param mixed $value Raw user input value.
     * @return int Sanitized interval in minutes.
     */
    function sitepulse_sanitize_alert_interval($value) {
        $raw_value      = $value;
        $allowed_values = sitepulse_get_alert_interval_choices($raw_value);
        $default_value  = min($allowed_values);

        if (is_string($value) && !is_numeric($value)) {
            $candidate = strtolower(trim($value));

            if ($candidate === 'smart') {
                $smart_value = $default_value;

                if (function_exists('apply_filters')) {
                    $smart_value = (int) apply_filters('sitepulse_alert_interval_smart_value', $default_value, $allowed_values, $raw_value);
                }

                if (in_array($smart_value, $allowed_values, true)) {
                    $value = $smart_value;
                } else {
                    $value = $default_value;
                }
            } else {
                $value = $default_value;
            }
        } else {
            $value = is_scalar($value) ? absint($value) : 0;
        }

        if ($value <= 0) {
            $value = $default_value;
        }

        if (!in_array($value, $allowed_values, true)) {
            $value = sitepulse_find_closest_allowed_interval($value, $allowed_values, $default_value);
        }

        if (function_exists('apply_filters')) {
            $value = (int) apply_filters('sitepulse_alert_interval_sanitized', $value, $allowed_values, $raw_value);
        }

        if ($value <= 0) {
            $value = $default_value;
        }

        return $value;
    }
}

if (!function_exists('sitepulse_find_closest_allowed_interval')) {
    /**
     * Finds the closest allowed interval to the provided value.
     *
     * @param int   $value          Input value.
     * @param array $allowed_values Sorted array of allowed values.
     * @param int   $default_value  Fallback value.
     * @return int
     */
    function sitepulse_find_closest_allowed_interval($value, $allowed_values, $default_value) {
        if (empty($allowed_values)) {
            return $default_value;
        }

        foreach ($allowed_values as $allowed_value) {
            if ($value <= $allowed_value) {
                return (int) $allowed_value;
            }
        }

        return (int) end($allowed_values);
    }
}
