<?php
if (!defined('ABSPATH')) exit;

if (!defined('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY')) {
    define('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY', 'sitepulse_resource_monitor_history');
}

add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Resource Monitor', 'sitepulse'),
        __('Resources', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-resources',
        'sitepulse_resource_monitor_page'
    );
});

add_action('admin_enqueue_scripts', 'sitepulse_resource_monitor_enqueue_assets');

/**
 * Registers and enqueues the stylesheet used by the resource monitor page.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_resource_monitor_enqueue_assets($hook_suffix) {
    if ($hook_suffix !== 'sitepulse-dashboard_page_sitepulse-resources') {
        return;
    }

    $style_handle = 'sitepulse-resource-monitor';
    $style_src    = SITEPULSE_URL . 'modules/css/resource-monitor.css';

    wp_enqueue_style($style_handle, $style_src, [], SITEPULSE_VERSION);

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

    wp_register_script(
        'sitepulse-resource-monitor',
        SITEPULSE_URL . 'modules/js/resource-monitor.js',
        ['sitepulse-chartjs'],
        SITEPULSE_VERSION,
        true
    );

    wp_enqueue_script('sitepulse-resource-monitor');
}

/**
 * Formats CPU load values for display.
 *
 * @param mixed $load_values Raw load average values.
 * @return string
 */
function sitepulse_resource_monitor_format_load_display($load_values) {
    $not_available_label = esc_html__('N/A', 'sitepulse');

    if (!is_array($load_values) || empty($load_values)) {
        $load_values = [$not_available_label, $not_available_label, $not_available_label];
    }

    $normalized_values = array_map(
        static function ($value) use ($not_available_label) {
            if (is_numeric($value)) {
                return number_format_i18n((float) $value, 2);
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_null($value)) {
                return $not_available_label;
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return $not_available_label;
        },
        array_slice(array_values((array) $load_values), 0, 3)
    );

    $normalized_values = array_pad($normalized_values, 3, $not_available_label);

    return implode(' / ', $normalized_values);
}

/**
 * Returns cached resource metrics or computes a fresh snapshot.
 *
 * @return array{
 *     load: array<int, mixed>,
 *     load_display: string,
 *     memory_usage: string,
 *     memory_limit: string|false,
 *     disk_free: string,
 *     disk_total: string,
 *     notices: array<int, array{type:string,message:string}>,
 *     generated_at: int
 * }
 */
function sitepulse_resource_monitor_get_snapshot() {
    $cached = get_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);

    if (is_array($cached) && isset($cached['generated_at'])) {
        return $cached;
    }

    $notices = [];
    $not_available_label = esc_html__('N/A', 'sitepulse');
    $load = [$not_available_label, $not_available_label, $not_available_label];
    $load_display = sitepulse_resource_monitor_format_load_display($load);
    $load_raw = [null, null, null];

    if (function_exists('sys_getloadavg')) {
        $load_values = sys_getloadavg();
        $load_values_are_numeric = is_array($load_values) && !empty($load_values);

        if ($load_values_are_numeric) {
            foreach ($load_values as $value) {
                if (!is_numeric($value)) {
                    $load_values_are_numeric = false;
                    break;
                }
            }
        }

        if ($load_values_are_numeric) {
            $load = $load_values;
            $load_raw = array_map(static function($value) {
                return is_numeric($value) ? (float) $value : null;
            }, array_slice(array_values((array) $load_values), 0, 3));
            $load_display = sitepulse_resource_monitor_format_load_display($load);
        } else {
            $message = esc_html__('Indisponible – sys_getloadavg() désactivée par votre hébergeur', 'sitepulse');
            $notices[] = [
                'type'    => 'warning',
                'message' => $message,
            ];

            if (function_exists('sitepulse_log')) {
                sitepulse_log(__('Resource Monitor: CPU load average unavailable because sys_getloadavg() is disabled by the hosting provider.', 'sitepulse'), 'WARNING');
            }
        }
    } else {
        $message = esc_html__('Indisponible – sys_getloadavg() désactivée par votre hébergeur', 'sitepulse');
        $notices[] = [
            'type'    => 'warning',
            'message' => $message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log(__('Resource Monitor: sys_getloadavg() is not available on this server.', 'sitepulse'), 'WARNING');
        }
    }

    $memory_usage_bytes = (int) memory_get_usage();
    $memory_usage = size_format($memory_usage_bytes);
    $memory_limit_ini = ini_get('memory_limit');
    $memory_limit = $memory_limit_ini;
    $memory_limit_bytes = sitepulse_resource_monitor_normalize_memory_limit_bytes($memory_limit_ini);

    if ($memory_limit_ini !== false) {
        $memory_limit_value = trim((string) $memory_limit_ini);
        $memory_limit = $memory_limit_value;

        if ($memory_limit_value !== '') {
            $memory_limit_lower = strtolower($memory_limit_value);

            if (
                $memory_limit_lower === '-1'
                || $memory_limit_lower === 'unlimited'
                || (float) $memory_limit_value === -1.0
            ) {
                $memory_limit = __('Illimitée', 'sitepulse');
            }
        }
    }

    $disk_free = $not_available_label;
    $disk_free_bytes = null;

    if (function_exists('disk_free_space')) {
        $disk_free_error = null;
        set_error_handler(function ($errno, $errstr) use (&$disk_free_error) {
            $disk_free_error = $errstr;

            return true;
        });

        try {
            $free_space = disk_free_space(ABSPATH);
        } catch (\Throwable $exception) {
            $disk_free_error = $exception->getMessage();
            $free_space = false;
        } finally {
            restore_error_handler();
        }

        if ($free_space !== false) {
            $disk_free_bytes = is_numeric($free_space) ? (int) $free_space : null;
            $disk_free = size_format($free_space);
        } else {
            $message = esc_html__('Unable to determine the available disk space for the WordPress root directory.', 'sitepulse');
            $notices[] = [
                'type'    => 'warning',
                'message' => $message,
            ];

            if (function_exists('sitepulse_log')) {
                $log_message = sprintf(
                    /* translators: %s: original message. */
                    __('Resource Monitor: %s', 'sitepulse'),
                    $message
                );

                if (is_string($disk_free_error) && $disk_free_error !== '') {
                    $log_message .= ' ' . sprintf(
                        /* translators: %s: error message. */
                        __('Error: %s', 'sitepulse'),
                        $disk_free_error
                    );
                }

                sitepulse_log($log_message, 'ERROR');
            }
        }
    } else {
        $message = esc_html__('The disk_free_space() function is not available on this server.', 'sitepulse');
        $notices[] = [
            'type'    => 'warning',
            'message' => $message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log(
                sprintf(
                    /* translators: %s: original message. */
                    __('Resource Monitor: %s', 'sitepulse'),
                    $message
                ),
                'WARNING'
            );
        }
    }

    $disk_total = $not_available_label;
    $disk_total_bytes = null;

    if (function_exists('disk_total_space')) {
        $disk_total_error = null;
        set_error_handler(function ($errno, $errstr) use (&$disk_total_error) {
            $disk_total_error = $errstr;

            return true;
        });

        try {
            $total_space = disk_total_space(ABSPATH);
        } catch (\Throwable $exception) {
            $disk_total_error = $exception->getMessage();
            $total_space = false;
        } finally {
            restore_error_handler();
        }

        if ($total_space !== false) {
            $disk_total_bytes = is_numeric($total_space) ? (int) $total_space : null;
            $disk_total = size_format($total_space);
        } else {
            $message = esc_html__('Unable to determine the total disk space for the WordPress root directory.', 'sitepulse');
            $notices[] = [
                'type'    => 'warning',
                'message' => $message,
            ];

            if (function_exists('sitepulse_log')) {
                $log_message = sprintf(
                    /* translators: %s: original message. */
                    __('Resource Monitor: %s', 'sitepulse'),
                    $message
                );

                if (is_string($disk_total_error) && $disk_total_error !== '') {
                    $log_message .= ' ' . sprintf(
                        /* translators: %s: error message. */
                        __('Error: %s', 'sitepulse'),
                        $disk_total_error
                    );
                }

                sitepulse_log($log_message, 'ERROR');
            }
        }
    } else {
        $message = esc_html__('The disk_total_space() function is not available on this server.', 'sitepulse');
        $notices[] = [
            'type'    => 'warning',
            'message' => $message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log(
                sprintf(
                    /* translators: %s: original message. */
                    __('Resource Monitor: %s', 'sitepulse'),
                    $message
                ),
                'WARNING'
            );
        }
    }

    $snapshot = [
        'load'         => $load,
        'load_display' => $load_display,
        'memory_usage' => $memory_usage,
        'memory_usage_bytes' => $memory_usage_bytes,
        'memory_limit' => $memory_limit,
        'memory_limit_bytes' => $memory_limit_bytes,
        'disk_free'    => $disk_free,
        'disk_free_bytes' => $disk_free_bytes,
        'disk_total'   => $disk_total,
        'disk_total_bytes' => $disk_total_bytes,
        'notices'      => $notices,
        'generated_at' => (int) current_time('timestamp', true),
    ];

    $history_snapshot = $snapshot;
    $history_snapshot['load_raw'] = $load_raw;
    sitepulse_resource_monitor_append_history($history_snapshot);

    $cache_ttl = (int) apply_filters('sitepulse_resource_monitor_cache_ttl', 5 * MINUTE_IN_SECONDS, $snapshot);

    if ($cache_ttl > 0) {
        set_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT, $snapshot, $cache_ttl);
    }

    return $snapshot;
}

function sitepulse_resource_monitor_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $resource_monitor_notices = [];

    if (isset($_POST['sitepulse_resource_monitor_refresh'])) {
        check_admin_referer('sitepulse_refresh_resource_snapshot');
        delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        sitepulse_resource_monitor_clear_history();

        $resource_monitor_notices[] = [
            'type'    => 'success',
            'message' => esc_html__('Les mesures et l’historique ont été actualisés.', 'sitepulse'),
        ];
    }

    $snapshot = sitepulse_resource_monitor_get_snapshot();

    $history_entries = sitepulse_resource_monitor_get_history();
    $history_summary = sitepulse_resource_monitor_calculate_history_summary($history_entries);
    $history_summary_text = sitepulse_resource_monitor_format_history_summary($history_summary);
    $history_for_js = sitepulse_resource_monitor_prepare_history_for_js($history_entries);

    wp_localize_script(
        'sitepulse-resource-monitor',
        'SitePulseResourceMonitor',
        [
            'history' => $history_for_js,
            'summary' => [
                'count'                 => (int) $history_summary['count'],
                'span'                  => (int) $history_summary['span'],
                'firstTimestamp'        => $history_summary['first_timestamp'],
                'lastTimestamp'         => $history_summary['last_timestamp'],
                'averageLoad'           => $history_summary['average_load'],
                'latestLoad'            => $history_summary['latest_load'],
                'averageMemoryPercent'  => $history_summary['average_memory_percent'],
                'latestMemoryPercent'   => $history_summary['latest_memory_percent'],
                'averageDiskFreePercent'=> $history_summary['average_disk_free_percent'],
                'latestDiskFreePercent' => $history_summary['latest_disk_free_percent'],
            ],
            'snapshot' => [
                'generatedAt' => isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : null,
            ],
            'locale' => get_user_locale(),
            'dateFormat' => get_option('date_format'),
            'timeFormat' => get_option('time_format'),
            'i18n' => [
                'loadLabel'         => esc_html__('Charge CPU (1 min)', 'sitepulse'),
                'memoryLabel'       => esc_html__('Mémoire utilisée (%)', 'sitepulse'),
                'diskLabel'         => esc_html__('Stockage libre (%)', 'sitepulse'),
                'percentAxisLabel'  => esc_html__('% d’utilisation', 'sitepulse'),
                'noHistory'         => esc_html__("Aucun historique disponible pour le moment.", 'sitepulse'),
                'timestamp'         => esc_html__('Horodatage', 'sitepulse'),
                'unavailable'       => esc_html__('N/A', 'sitepulse'),
                'memoryUsage'       => esc_html__('Mémoire utilisée', 'sitepulse'),
                'diskFree'          => esc_html__('Stockage libre', 'sitepulse'),
            ],
        ]
    );

    if (!empty($snapshot['notices']) && is_array($snapshot['notices'])) {
        $resource_monitor_notices = array_merge($resource_monitor_notices, $snapshot['notices']);
    }

    $generated_at = isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;
    $generated_label = $generated_at > 0
        ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $generated_at)
        : esc_html__('Inconnue', 'sitepulse');

    $age = '';

    if ($generated_at > 0) {
        $age = human_time_diff($generated_at, (int) current_time('timestamp', true));
    }
    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-resources');
    }
    ?>
    <div class="wrap sitepulse-resource-monitor">
        <h1><span class="dashicons-before dashicons-performance"></span> <?php esc_html_e('Moniteur de Ressources', 'sitepulse'); ?></h1>
        <?php if (!empty($resource_monitor_notices)) : ?>
            <?php foreach ($resource_monitor_notices as $notice) : ?>
                <?php
                $type = isset($notice['type']) ? (string) $notice['type'] : 'warning';
                $allowed_types = ['error', 'warning', 'info', 'success'];
                if (!in_array($type, $allowed_types, true)) {
                    $type = 'warning';
                }

                $message = isset($notice['message']) ? $notice['message'] : '';
                if ($message === '') {
                    continue;
                }
                ?>
                <div class="<?php echo esc_attr('notice notice-' . $type); ?>"><p><?php echo esc_html($message); ?></p></div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="sitepulse-resource-grid">
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Charge CPU (1/5/15 min)', 'sitepulse'); ?></h2>
                <?php
                $load_display_output = isset($snapshot['load']) && is_array($snapshot['load'])
                    ? sitepulse_resource_monitor_format_load_display($snapshot['load'])
                    : (string) $snapshot['load_display'];
                ?>
                <p class="sitepulse-resource-value"><?php echo esc_html($load_display_output); ?></p>
            </div>
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Mémoire', 'sitepulse'); ?></h2>
                <p class="sitepulse-resource-value"><?php echo wp_kses_post($snapshot['memory_usage']); ?></p>
                <p class="sitepulse-resource-subvalue"><?php
                /* translators: %s: PHP memory limit value. */
                printf(esc_html__('Limite PHP : %s', 'sitepulse'), esc_html((string) $snapshot['memory_limit']));
                ?></p>
            </div>
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Stockage disque', 'sitepulse'); ?></h2>
                <p class="sitepulse-resource-value"><?php echo wp_kses_post($snapshot['disk_free']); ?></p>
                <p class="sitepulse-resource-subvalue"><?php
                /* translators: %s: total disk space. */
                printf(esc_html__('Total : %s', 'sitepulse'), esc_html((string) $snapshot['disk_total']));
                ?></p>
            </div>
        </div>
        <div class="sitepulse-resource-meta">
            <p>
                <?php
                if ($age !== '') {
                    printf(
                        /* translators: 1: formatted date, 2: relative time. */
                        esc_html__('Mesures relevées le %1$s (%2$s).', 'sitepulse'),
                        esc_html($generated_label),
                        sprintf(
                            /* translators: %s: human-readable time difference. */
                            esc_html__('il y a %s', 'sitepulse'),
                            esc_html($age)
                        )
                    );
                } else {
                    printf(
                        /* translators: %s: formatted date. */
                        esc_html__('Mesures relevées le %s.', 'sitepulse'),
                        esc_html($generated_label)
                    );
                }
                ?>
            </p>
            <form method="post">
                <?php wp_nonce_field('sitepulse_refresh_resource_snapshot'); ?>
                <button type="submit" name="sitepulse_resource_monitor_refresh" class="button button-secondary">
                    <?php esc_html_e('Actualiser les mesures', 'sitepulse'); ?>
                </button>
            </form>
        </div>
        <div class="sitepulse-resource-history" id="sitepulse-resource-history">
            <h2><?php esc_html_e('Historique des ressources', 'sitepulse'); ?></h2>
            <div class="sitepulse-resource-history-chart">
                <canvas id="sitepulse-resource-history-chart" aria-describedby="sitepulse-resource-history-summary"></canvas>
            </div>
            <p class="sitepulse-resource-history-empty" data-empty<?php if (!empty($history_entries)) { echo ' hidden'; } ?>>
                <?php esc_html_e("Aucun historique disponible pour le moment.", 'sitepulse'); ?>
            </p>
            <p id="sitepulse-resource-history-summary" class="sitepulse-resource-history-summary">
                <?php echo esc_html($history_summary_text); ?>
            </p>
        </div>
    </div>
    <?php
}

/**
 * Converts a PHP memory limit value to bytes when possible.
 *
 * @param mixed $memory_limit_ini Raw memory_limit configuration value.
 * @return int|null
 */
function sitepulse_resource_monitor_normalize_memory_limit_bytes($memory_limit_ini) {
    if ($memory_limit_ini === false) {
        return null;
    }

    $memory_limit_value = trim((string) $memory_limit_ini);

    if ($memory_limit_value === '') {
        return null;
    }

    $memory_limit_lower = strtolower($memory_limit_value);

    if (
        $memory_limit_lower === '-1'
        || $memory_limit_lower === 'unlimited'
        || (float) $memory_limit_value === -1.0
    ) {
        return null;
    }

    if (function_exists('wp_convert_hr_to_bytes')) {
        $bytes = wp_convert_hr_to_bytes($memory_limit_value);

        if (is_numeric($bytes) && (int) $bytes > 0) {
            return (int) $bytes;
        }
    }

    if (is_numeric($memory_limit_value)) {
        $numeric = (float) $memory_limit_value;

        if ($numeric > 0) {
            return (int) $numeric;
        }
    }

    return null;
}

/**
 * Appends a snapshot entry to the history option.
 *
 * @param array $snapshot Snapshot data enriched with raw metrics.
 * @return void
 */
function sitepulse_resource_monitor_append_history(array $snapshot) {
    $entry = sitepulse_resource_monitor_build_history_entry($snapshot);

    if ($entry === null) {
        return;
    }

    $option_name = SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY;
    $history = get_option($option_name, []);

    if (!is_array($history)) {
        $history = [];
    }

    $history[] = $entry;
    $history = sitepulse_resource_monitor_normalize_history($history);

    update_option($option_name, $history, false);
}

/**
 * Builds a normalized history entry from the snapshot.
 *
 * @param array $snapshot Snapshot data.
 * @return array|null
 */
function sitepulse_resource_monitor_build_history_entry(array $snapshot) {
    $timestamp = isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;

    if ($timestamp <= 0) {
        return null;
    }

    $load_values = [null, null, null];

    if (isset($snapshot['load_raw']) && is_array($snapshot['load_raw'])) {
        foreach (array_slice(array_values($snapshot['load_raw']), 0, 3) as $index => $value) {
            $load_values[$index] = is_numeric($value) ? (float) $value : null;
        }
    } elseif (isset($snapshot['load']) && is_array($snapshot['load'])) {
        foreach (array_slice(array_values($snapshot['load']), 0, 3) as $index => $value) {
            $load_values[$index] = is_numeric($value) ? (float) $value : null;
        }
    }

    $memory_usage_bytes = isset($snapshot['memory_usage_bytes']) && is_numeric($snapshot['memory_usage_bytes'])
        ? max(0, (int) $snapshot['memory_usage_bytes'])
        : null;

    $memory_limit_bytes = isset($snapshot['memory_limit_bytes']) && is_numeric($snapshot['memory_limit_bytes'])
        ? max(0, (int) $snapshot['memory_limit_bytes'])
        : null;

    $disk_free_bytes = isset($snapshot['disk_free_bytes']) && is_numeric($snapshot['disk_free_bytes'])
        ? max(0, (int) $snapshot['disk_free_bytes'])
        : null;

    $disk_total_bytes = isset($snapshot['disk_total_bytes']) && is_numeric($snapshot['disk_total_bytes'])
        ? max(0, (int) $snapshot['disk_total_bytes'])
        : null;

    return [
        'timestamp' => $timestamp,
        'load'      => $load_values,
        'memory'    => [
            'usage' => $memory_usage_bytes,
            'limit' => $memory_limit_bytes,
        ],
        'disk'      => [
            'free'  => $disk_free_bytes,
            'total' => $disk_total_bytes,
        ],
    ];
}

/**
 * Normalizes history entries and applies TTL / max length constraints.
 *
 * @param array $history Raw history entries.
 * @return array<int, array>
 */
function sitepulse_resource_monitor_normalize_history(array $history) {
    $now = (int) current_time('timestamp', true);
    $sanitized = [];

    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

        if ($timestamp <= 0) {
            continue;
        }

        $load = [null, null, null];
        if (isset($entry['load']) && is_array($entry['load'])) {
            foreach (array_slice(array_values($entry['load']), 0, 3) as $index => $value) {
                $load[$index] = is_numeric($value) ? (float) $value : null;
            }
        }

        $memory_usage = null;
        if (isset($entry['memory']['usage']) && is_numeric($entry['memory']['usage'])) {
            $memory_usage = max(0, (int) $entry['memory']['usage']);
        }

        $memory_limit = null;
        if (isset($entry['memory']['limit']) && is_numeric($entry['memory']['limit'])) {
            $memory_limit = max(0, (int) $entry['memory']['limit']);
        }

        $disk_free = null;
        if (isset($entry['disk']['free']) && is_numeric($entry['disk']['free'])) {
            $disk_free = max(0, (int) $entry['disk']['free']);
        }

        $disk_total = null;
        if (isset($entry['disk']['total']) && is_numeric($entry['disk']['total'])) {
            $disk_total = max(0, (int) $entry['disk']['total']);
        }

        $sanitized[$timestamp] = [
            'timestamp' => $timestamp,
            'load'      => $load,
            'memory'    => [
                'usage' => $memory_usage,
                'limit' => $memory_limit,
            ],
            'disk'      => [
                'free'  => $disk_free,
                'total' => $disk_total,
            ],
        ];
    }

    if (empty($sanitized)) {
        return [];
    }

    ksort($sanitized);
    $sanitized = array_values($sanitized);

    $history_ttl = (int) apply_filters('sitepulse_resource_monitor_history_ttl', DAY_IN_SECONDS, $sanitized);

    if ($history_ttl > 0) {
        $cutoff = $now - $history_ttl;
        $sanitized = array_values(array_filter(
            $sanitized,
            static function ($entry) use ($cutoff) {
                return isset($entry['timestamp']) && (int) $entry['timestamp'] >= $cutoff;
            }
        ));
    }

    if (empty($sanitized)) {
        return [];
    }

    $max_entries = (int) apply_filters('sitepulse_resource_monitor_history_max_entries', 288, $sanitized);

    if ($max_entries > 0 && count($sanitized) > $max_entries) {
        $sanitized = array_slice($sanitized, -$max_entries);
    }

    return array_values($sanitized);
}

/**
 * Returns the normalized history entries.
 *
 * @return array<int, array>
 */
function sitepulse_resource_monitor_get_history() {
    $option_name = SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY;
    $history = get_option($option_name, []);

    if (!is_array($history)) {
        $history = [];
    }

    return sitepulse_resource_monitor_normalize_history($history);
}

/**
 * Removes the stored history.
 *
 * @return void
 */
function sitepulse_resource_monitor_clear_history() {
    delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);
}

/**
 * Calculates a percentage based on a value and a total.
 *
 * @param int|float|null $value Usage value.
 * @param int|float|null $total Reference total.
 * @return float|null
 */
function sitepulse_resource_monitor_calculate_percentage($value, $total) {
    if (!is_numeric($value) || !is_numeric($total)) {
        return null;
    }

    $total = (float) $total;

    if ($total <= 0) {
        return null;
    }

    $percentage = ((float) $value / $total) * 100;

    return max(0.0, min(100.0, $percentage));
}

/**
 * Calculates the average of numeric values.
 *
 * @param array<int, float> $values Values to average.
 * @return float|null
 */
function sitepulse_resource_monitor_calculate_average(array $values) {
    $values = array_filter($values, static function ($value) {
        return is_numeric($value);
    });

    if (empty($values)) {
        return null;
    }

    return array_sum($values) / count($values);
}

/**
 * Calculates summary metrics for the history entries.
 *
 * @param array<int, array> $history_entries History entries.
 * @return array
 */
function sitepulse_resource_monitor_calculate_history_summary(array $history_entries) {
    $count = count($history_entries);

    if ($count === 0) {
        return [
            'count' => 0,
            'span' => 0,
            'first_timestamp' => null,
            'last_timestamp' => null,
            'average_load' => null,
            'latest_load' => null,
            'average_memory_percent' => null,
            'latest_memory_percent' => null,
            'average_disk_free_percent' => null,
            'latest_disk_free_percent' => null,
        ];
    }

    $first_timestamp = (int) $history_entries[0]['timestamp'];
    $last_timestamp = (int) $history_entries[$count - 1]['timestamp'];
    $span = max(0, $last_timestamp - $first_timestamp);

    $load_values = [];
    $memory_percentages = [];
    $disk_percentages = [];

    foreach ($history_entries as $entry) {
        if (isset($entry['load'][0]) && is_numeric($entry['load'][0])) {
            $load_values[] = (float) $entry['load'][0];
        }

        $memory_percent = sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null);
        if ($memory_percent !== null) {
            $memory_percentages[] = $memory_percent;
        }

        $disk_percent = sitepulse_resource_monitor_calculate_percentage($entry['disk']['free'] ?? null, $entry['disk']['total'] ?? null);
        if ($disk_percent !== null) {
            $disk_percentages[] = $disk_percent;
        }
    }

    $latest_entry = $history_entries[$count - 1];

    return [
        'count' => $count,
        'span' => $span,
        'first_timestamp' => $first_timestamp,
        'last_timestamp' => $last_timestamp,
        'average_load' => sitepulse_resource_monitor_calculate_average($load_values),
        'latest_load' => isset($latest_entry['load'][0]) && is_numeric($latest_entry['load'][0]) ? (float) $latest_entry['load'][0] : null,
        'average_memory_percent' => sitepulse_resource_monitor_calculate_average($memory_percentages),
        'latest_memory_percent' => sitepulse_resource_monitor_calculate_percentage($latest_entry['memory']['usage'] ?? null, $latest_entry['memory']['limit'] ?? null),
        'average_disk_free_percent' => sitepulse_resource_monitor_calculate_average($disk_percentages),
        'latest_disk_free_percent' => sitepulse_resource_monitor_calculate_percentage($latest_entry['disk']['free'] ?? null, $latest_entry['disk']['total'] ?? null),
    ];
}

/**
 * Creates a localized text summary for history statistics.
 *
 * @param array $summary Summary generated by sitepulse_resource_monitor_calculate_history_summary().
 * @return string
 */
function sitepulse_resource_monitor_format_history_summary(array $summary) {
    if (empty($summary['count'])) {
        return esc_html__("Aucun historique disponible pour le moment.", 'sitepulse');
    }

    $range_label = ($summary['span'] > 0 && $summary['first_timestamp'] && $summary['last_timestamp'])
        ? human_time_diff($summary['first_timestamp'], $summary['last_timestamp'])
        : __('moins d\'une minute', 'sitepulse');

    $range_text = sprintf(
        /* translators: %s: human-readable duration. */
        __('sur %s', 'sitepulse'),
        $range_label
    );

    $sentences = [
        sprintf(
            /* translators: 1: number of entries, 2: duration description. */
            _n('%1$s relevé enregistré %2$s', '%1$s relevés enregistrés %2$s', $summary['count'], 'sitepulse'),
            number_format_i18n($summary['count']),
            $range_text
        ),
    ];

    if ($summary['average_load'] !== null) {
        $sentences[] = sprintf(
            /* translators: %s: average CPU load. */
            __('Charge moyenne (1 min) : %s', 'sitepulse'),
            number_format_i18n($summary['average_load'], 2)
        );
    }

    if ($summary['average_memory_percent'] !== null) {
        $sentences[] = sprintf(
            /* translators: %s: average memory usage percentage. */
            __('Mémoire utilisée : %s %%', 'sitepulse'),
            number_format_i18n($summary['average_memory_percent'], 1)
        );
    }

    if ($summary['average_disk_free_percent'] !== null) {
        $sentences[] = sprintf(
            /* translators: %s: average disk free percentage. */
            __('Stockage libre : %s %%', 'sitepulse'),
            number_format_i18n($summary['average_disk_free_percent'], 1)
        );
    }

    return implode('. ', $sentences) . '.';
}

/**
 * Prepares history entries for JavaScript consumption.
 *
 * @param array<int, array> $history_entries Normalized history entries.
 * @return array<int, array>
 */
function sitepulse_resource_monitor_prepare_history_for_js(array $history_entries) {
    $prepared = [];

    foreach ($history_entries as $entry) {
        $prepared[] = [
            'timestamp' => (int) $entry['timestamp'],
            'load'      => array_map(
                static function ($value) {
                    return is_numeric($value) ? (float) $value : null;
                },
                array_pad(is_array($entry['load']) ? array_values($entry['load']) : [], 3, null)
            ),
            'memory'    => [
                'usage'        => isset($entry['memory']['usage']) && is_numeric($entry['memory']['usage']) ? (int) $entry['memory']['usage'] : null,
                'limit'        => isset($entry['memory']['limit']) && is_numeric($entry['memory']['limit']) ? (int) $entry['memory']['limit'] : null,
                'percentUsage' => sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null),
            ],
            'disk'      => [
                'free'         => isset($entry['disk']['free']) && is_numeric($entry['disk']['free']) ? (int) $entry['disk']['free'] : null,
                'total'        => isset($entry['disk']['total']) && is_numeric($entry['disk']['total']) ? (int) $entry['disk']['total'] : null,
                'percentFree'  => sitepulse_resource_monitor_calculate_percentage($entry['disk']['free'] ?? null, $entry['disk']['total'] ?? null),
            ],
        ];
    }

    return $prepared;
}
