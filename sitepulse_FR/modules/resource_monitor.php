<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function() {
    add_submenu_page('sitepulse-dashboard', 'Resource Monitor', 'Resources', 'manage_options', 'sitepulse-resources', 'sitepulse_resource_monitor_page');
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
}

/**
 * Formats CPU load values for display.
 *
 * @param mixed $load_values Raw load average values.
 * @return string
 */
function sitepulse_resource_monitor_format_load_display($load_values) {
    if (!is_array($load_values) || empty($load_values)) {
        $load_values = ['N/A', 'N/A', 'N/A'];
    }

    $normalized_values = array_map(
        static function ($value) {
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
                return 'N/A';
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return 'N/A';
        },
        array_slice(array_values((array) $load_values), 0, 3)
    );

    $normalized_values = array_pad($normalized_values, 3, 'N/A');

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
    $load = ['N/A', 'N/A', 'N/A'];
    $load_display = sitepulse_resource_monitor_format_load_display($load);

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
            $load_display = sitepulse_resource_monitor_format_load_display($load);
        } else {
            $message = esc_html__('Indisponible – sys_getloadavg() désactivée par votre hébergeur', 'sitepulse');
            $notices[] = [
                'type'    => 'warning',
                'message' => $message,
            ];

            if (function_exists('sitepulse_log')) {
                sitepulse_log('Resource Monitor: CPU load average unavailable because sys_getloadavg() is disabled by the hosting provider.', 'WARNING');
            }
        }
    } else {
        $message = esc_html__('Indisponible – sys_getloadavg() désactivée par votre hébergeur', 'sitepulse');
        $notices[] = [
            'type'    => 'warning',
            'message' => $message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log('Resource Monitor: sys_getloadavg() is not available on this server.', 'WARNING');
        }
    }

    $memory_usage = size_format(memory_get_usage());
    $memory_limit_ini = ini_get('memory_limit');
    $memory_limit = $memory_limit_ini;

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

    $disk_free = 'N/A';

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
            $disk_free = size_format($free_space);
        } else {
            $message = __('Unable to determine the available disk space for the WordPress root directory.', 'sitepulse');
            $notices[] = [
                'type'    => 'warning',
                'message' => $message,
            ];

            if (function_exists('sitepulse_log')) {
                $log_message = 'Resource Monitor: ' . $message;

                if (is_string($disk_free_error) && $disk_free_error !== '') {
                    $log_message .= ' Error: ' . $disk_free_error;
                }

                sitepulse_log($log_message, 'ERROR');
            }
        }
    } else {
        $message = __('The disk_free_space() function is not available on this server.', 'sitepulse');
        $notices[] = [
            'type'    => 'warning',
            'message' => $message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log('Resource Monitor: ' . $message, 'WARNING');
        }
    }

    $disk_total = 'N/A';

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
            $disk_total = size_format($total_space);
        } else {
            $message = __('Unable to determine the total disk space for the WordPress root directory.', 'sitepulse');
            $notices[] = [
                'type'    => 'warning',
                'message' => $message,
            ];

            if (function_exists('sitepulse_log')) {
                $log_message = 'Resource Monitor: ' . $message;

                if (is_string($disk_total_error) && $disk_total_error !== '') {
                    $log_message .= ' Error: ' . $disk_total_error;
                }

                sitepulse_log($log_message, 'ERROR');
            }
        }
    } else {
        $message = __('The disk_total_space() function is not available on this server.', 'sitepulse');
        $notices[] = [
            'type'    => 'warning',
            'message' => $message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log('Resource Monitor: ' . $message, 'WARNING');
        }
    }

    $snapshot = [
        'load'         => $load,
        'load_display' => $load_display,
        'memory_usage' => $memory_usage,
        'memory_limit' => $memory_limit,
        'disk_free'    => $disk_free,
        'disk_total'   => $disk_total,
        'notices'      => $notices,
        'generated_at' => (int) current_time('timestamp', true),
    ];

    $cache_ttl = (int) apply_filters('sitepulse_resource_monitor_cache_ttl', 5 * MINUTE_IN_SECONDS, $snapshot);

    if ($cache_ttl > 0) {
        set_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT, $snapshot, $cache_ttl);
    }

    return $snapshot;
}

function sitepulse_resource_monitor_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $resource_monitor_notices = [];

    if (isset($_POST['sitepulse_resource_monitor_refresh'])) {
        check_admin_referer('sitepulse_refresh_resource_snapshot');
        delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);

        $resource_monitor_notices[] = [
            'type'    => 'success',
            'message' => __('Les mesures ont été actualisées.', 'sitepulse'),
        ];
    }

    $snapshot = sitepulse_resource_monitor_get_snapshot();

    if (!empty($snapshot['notices']) && is_array($snapshot['notices'])) {
        $resource_monitor_notices = array_merge($resource_monitor_notices, $snapshot['notices']);
    }

    $generated_at = isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;
    $generated_label = $generated_at > 0
        ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $generated_at)
        : __('Inconnue', 'sitepulse');

    $age = '';

    if ($generated_at > 0) {
        $age = human_time_diff($generated_at, (int) current_time('timestamp', true));
    }
    ?>
    <div class="wrap sitepulse-resource-monitor">
        <h1><span class="dashicons-before dashicons-performance"></span> Moniteur de Ressources</h1>
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
                <p class="sitepulse-resource-subvalue"><?php printf(esc_html__('Limite PHP : %s', 'sitepulse'), esc_html((string) $snapshot['memory_limit'])); ?></p>
            </div>
            <div class="sitepulse-resource-card">
                <h2><?php esc_html_e('Stockage disque', 'sitepulse'); ?></h2>
                <p class="sitepulse-resource-value"><?php echo wp_kses_post($snapshot['disk_free']); ?></p>
                <p class="sitepulse-resource-subvalue"><?php printf(esc_html__('Total : %s', 'sitepulse'), esc_html((string) $snapshot['disk_total'])); ?></p>
            </div>
        </div>
        <div class="sitepulse-resource-meta">
            <p>
                <?php
                if ($age !== '') {
                    printf(
                        esc_html__('Mesures relevées le %1$s (%2$s).', 'sitepulse'),
                        esc_html($generated_label),
                        sprintf(esc_html__('il y a %s', 'sitepulse'), esc_html($age))
                    );
                } else {
                    printf(esc_html__('Mesures relevées le %s.', 'sitepulse'), esc_html($generated_label));
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
    </div>
    <?php
}
