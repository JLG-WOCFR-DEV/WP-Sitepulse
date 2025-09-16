<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'Resource Monitor', 'Resources', 'manage_options', 'sitepulse-resources', 'sitepulse_resource_monitor_page'); });
function sitepulse_resource_monitor_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    if (function_exists('sys_getloadavg')) { $load = sys_getloadavg(); } else { $load = ['N/A', 'N/A', 'N/A']; }
    $load_display = implode(' / ', array_map('strval', $load));
    $memory_usage = size_format(memory_get_usage());
    $memory_limit = ini_get('memory_limit');
    $resource_monitor_notices = [];
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
            $resource_monitor_notices[] = [
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
        $resource_monitor_notices[] = [
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
            $resource_monitor_notices[] = [
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
        $resource_monitor_notices[] = [
            'type'    => 'warning',
            'message' => $message,
        ];

        if (function_exists('sitepulse_log')) {
            sitepulse_log('Resource Monitor: ' . $message, 'WARNING');
        }
    }
    ?>
    <div class="wrap">
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
        <p><strong>Charge CPU (1/5/15 min):</strong> <?php echo esc_html($load_display); ?></p>
        <p><strong>Mémoire:</strong> Utilisation <?php echo esc_html($memory_usage); ?> / Limite <?php echo esc_html($memory_limit); ?></p>
        <p><strong>Disque:</strong> Espace Libre <?php echo esc_html($disk_free); ?> / Total <?php echo esc_html($disk_total); ?></p>
    </div>
    <?php
}
