<?php
if (!defined('ABSPATH')) exit;

if (!defined('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK')) {
    define('SITEPULSE_OPTION_UPTIME_FAILURE_STREAK', 'sitepulse_uptime_failure_streak');
}

$sitepulse_uptime_cron_hook = function_exists('sitepulse_get_cron_hook') ? sitepulse_get_cron_hook('uptime_tracker') : 'sitepulse_uptime_tracker_cron';

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

if (!empty($sitepulse_uptime_cron_hook)) {
    add_action('init', 'sitepulse_uptime_tracker_ensure_cron');
    add_action($sitepulse_uptime_cron_hook, 'sitepulse_run_uptime_check');
}

/**
 * Ensures the uptime tracker cron hook is scheduled and reports failures.
 *
 * @return void
 */
function sitepulse_uptime_tracker_ensure_cron() {
    global $sitepulse_uptime_cron_hook;

    if (empty($sitepulse_uptime_cron_hook)) {
        return;
    }

    $next_run = wp_next_scheduled($sitepulse_uptime_cron_hook);

    if (!$next_run) {
        $next_run = (int) current_time('timestamp', true);
        $scheduled = wp_schedule_event($next_run, 'hourly', $sitepulse_uptime_cron_hook);

        if (false === $scheduled && function_exists('sitepulse_log')) {
            sitepulse_log(sprintf('Unable to schedule uptime tracker cron hook: %s', $sitepulse_uptime_cron_hook), 'ERROR');
        }
    }

    if (!wp_next_scheduled($sitepulse_uptime_cron_hook)) {
        sitepulse_register_cron_warning(
            'uptime_tracker',
            __('SitePulse n’a pas pu planifier la vérification d’uptime. Vérifiez que WP-Cron est actif ou programmez manuellement la tâche.', 'sitepulse')
        );
    } else {
        sitepulse_clear_cron_warning('uptime_tracker');
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

function sitepulse_uptime_tracker_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $uptime_log = sitepulse_normalize_uptime_log(get_option(SITEPULSE_OPTION_UPTIME_LOG, []));
    $total_checks = count($uptime_log);
    $boolean_checks = array_values(array_filter($uptime_log, function ($entry) {
        return isset($entry['status']) && is_bool($entry['status']);
    }));
    $evaluated_checks = count($boolean_checks);
    $up_checks = count(array_filter($boolean_checks, function ($entry) {
        return isset($entry['status']) && true === $entry['status'];
    }));
    $uptime_percentage = $evaluated_checks > 0 ? ($up_checks / $evaluated_checks) * 100 : 100;
    $date_format = get_option('date_format');
    $time_format = get_option('time_format');
    $current_incident_duration = '';
    $current_incident_start = null;

    if (!empty($uptime_log)) {
        $last_entry = end($uptime_log);
        if (isset($last_entry['status']) && is_bool($last_entry['status']) && false === $last_entry['status']) {
            $current_incident_start = isset($last_entry['incident_start']) ? (int) $last_entry['incident_start'] : (int) $last_entry['timestamp'];
            $current_timestamp = (int) current_time('timestamp');
            $current_incident_duration = human_time_diff($current_incident_start, $current_timestamp);
        }
        reset($uptime_log);
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-chart-bar"></span> Suivi de Disponibilité</h1>
        <p>Cet outil vérifie la disponibilité de votre site toutes les heures. Voici le statut des <?php echo esc_html($total_checks); ?> dernières vérifications.</p>
        <h2>Disponibilité (<?php echo esc_html($total_checks); ?> dernières heures): <strong style="font-size: 1.4em;"><?php echo esc_html(round($uptime_percentage, 2)); ?>%</strong></h2>
        <div class="uptime-chart">
            <?php if (empty($uptime_log)): ?><p>Aucune donnée de disponibilité. La première vérification aura lieu dans l'heure.</p><?php else: ?>
                <?php foreach ($uptime_log as $index => $entry): ?>
                    <?php
                    $status = $entry['status'] ?? null;
                    $bar_class = 'unknown';
                    if (true === $status) {
                        $bar_class = 'up';
                    } elseif (false === $status) {
                        $bar_class = 'down';
                    }
                    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                    $check_time = $timestamp > 0
                        ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                        : __('Horodatage inconnu', 'sitepulse');
                    $previous_entry = $index > 0 ? $uptime_log[$index - 1] : null;
                    $next_entry = ($index + 1) < $total_checks ? $uptime_log[$index + 1] : null;

                    if (true === $status) {
                        $bar_title = sprintf(__('Site OK lors du contrôle du %s.', 'sitepulse'), $check_time);

                        if (!empty($previous_entry) && isset($previous_entry['status']) && is_bool($previous_entry['status']) && false === $previous_entry['status']) {
                            $incident_start = isset($previous_entry['incident_start']) ? (int) $previous_entry['incident_start'] : (isset($previous_entry['timestamp']) ? (int) $previous_entry['timestamp'] : 0);
                            if ($incident_start > 0 && $timestamp >= $incident_start) {
                                $incident_start_formatted = date_i18n($date_format . ' ' . $time_format, $incident_start);
                                $bar_title .= ' ' . sprintf(__('Retour à la normale après un incident débuté le %1$s (durée : %2$s).', 'sitepulse'), $incident_start_formatted, human_time_diff($incident_start, $timestamp));
                            }
                        }
                    } elseif (false === $status) {
                        $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : $timestamp;
                        $incident_start_formatted = $incident_start > 0
                            ? date_i18n($date_format . ' ' . $time_format, $incident_start)
                            : __('horodatage inconnu', 'sitepulse');
                        $bar_title = sprintf(__('Site KO lors du contrôle du %1$s. Incident commencé le %2$s.', 'sitepulse'), $check_time, $incident_start_formatted);

                        if (array_key_exists('error', $entry)) {
                            $error_detail = is_scalar($entry['error']) ? (string) $entry['error'] : wp_json_encode($entry['error']);

                            if ('' !== $error_detail && false !== $error_detail) {
                                $bar_title .= ' ' . sprintf(__('Détails : %s.', 'sitepulse'), $error_detail);
                            }
                        }

                        $is_transition = empty($previous_entry) || (isset($previous_entry['status']) && true === $previous_entry['status']);

                        if ($index === $total_checks - 1 && !empty($current_incident_duration)) {
                            $bar_title .= ' ' . sprintf(__('Incident en cours depuis %s.', 'sitepulse'), $current_incident_duration);
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
                            }
                        }
                    } else {
                        $error_text = isset($entry['error']) ? $entry['error'] : __('Erreur réseau inconnue.', 'sitepulse');
                        $bar_title = sprintf(__('Statut indéterminé lors du contrôle du %1$s : %2$s', 'sitepulse'), $check_time, $error_text);
                    }
                    ?>
                    <div class="uptime-bar <?php echo esc_attr($bar_class); ?>" title="<?php echo esc_attr($bar_title); ?>"></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 0.9em; color: #555;"><span>Il y a <?php echo esc_html($total_checks); ?> heures</span><span>Maintenant</span></div>
        <?php if (!empty($current_incident_duration) && null !== $current_incident_start): ?>
            <div class="notice notice-error" style="margin-top: 15px;">
                <p>
                    <strong><?php esc_html_e('Incident en cours', 'sitepulse'); ?> :</strong>
                    <?php
                    $current_incident_start_formatted = date_i18n($date_format . ' ' . $time_format, $current_incident_start);
                    echo esc_html(
                        sprintf(
                            __('Votre site est signalé comme indisponible depuis le %1$s (%2$s).', 'sitepulse'),
                            $current_incident_start_formatted,
                            $current_incident_duration
                        )
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>
        <div class="notice notice-info" style="margin-top: 20px;"><p><strong>Comment ça marche :</strong> Une barre verte indique que votre site était en ligne. Une barre rouge indique un possible incident où votre site était inaccessible.</p></div>
    </div>
    <?php
}
function sitepulse_run_uptime_check() {
    $defaults = [
        'timeout'   => 10,
        'sslverify' => true,
        'url'       => home_url(),
    ];

    /**
     * Filtre les arguments passés à la requête de vérification d'uptime.
     *
     * Permet de désactiver la vérification SSL, d'ajuster le timeout ou de pointer
     * vers une URL spécifique pour les environnements de test.
     *
     * @since 1.0
     *
     * @param array $request_args Arguments transmis à wp_remote_get(). Le paramètre
     *                            "url" peut être fourni pour cibler une adresse
     *                            différente.
     */
    $request_args = apply_filters('sitepulse_uptime_request_args', $defaults);

    if (!is_array($request_args)) {
        $request_args = $defaults;
    }

    $request_url = isset($request_args['url']) ? $request_args['url'] : $defaults['url'];
    unset($request_args['url']);

    $response = wp_remote_get($request_url, $request_args);

    $raw_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);

    if (!is_array($raw_log)) {
        $raw_log = empty($raw_log) ? [] : [$raw_log];
    }

    $log = sitepulse_normalize_uptime_log($raw_log);

    $timestamp = (int) current_time('timestamp');
    $entry = [
        'timestamp' => $timestamp,
    ];

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        $entry['status'] = 'unknown';

        if (!empty($error_message)) {
            $entry['error'] = $error_message;
        }

        $failure_streak = (int) get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) + 1;
        update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, $failure_streak, false);

        $default_threshold = 3;
        $threshold = (int) apply_filters('sitepulse_uptime_consecutive_failures', $default_threshold, $failure_streak, $response, $request_url, $request_args);
        $threshold = max(1, $threshold);

        $log[] = $entry;

        $level = $failure_streak >= $threshold ? 'ALERT' : 'WARNING';
        $log_message = sprintf('Uptime check: network error (%1$d/%2$d)%3$s', $failure_streak, $threshold, !empty($error_message) ? ' - ' . $error_message : '');
        sitepulse_log($log_message, $level);

        if (count($log) > 30) {
            $log = array_slice($log, -30);
        }

        update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);

        return;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $is_up = $response_code >= 200 && $response_code < 400;

    if ((int) get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0) !== 0) {
        update_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0, false);
    }

    $entry['status'] = $is_up;

    if (!$is_up) {
        $incident_start = $timestamp;

        if (!empty($log)) {
            for ($i = count($log) - 1; $i >= 0; $i--) {
                if (!isset($log[$i]['status']) || !is_bool($log[$i]['status'])) {
                    continue;
                }

                if (false === $log[$i]['status']) {
                    if (isset($log[$i]['incident_start'])) {
                        $incident_start = (int) $log[$i]['incident_start'];
                    } elseif (isset($log[$i]['timestamp'])) {
                        $incident_start = (int) $log[$i]['timestamp'];
                    }
                }

                break;
            }
        }

        $entry['incident_start'] = $incident_start;
        $entry['error'] = sprintf('HTTP %d', $response_code);
    }

    $log[] = $entry;

    if (!$is_up) {
        sitepulse_log(sprintf('Uptime check: Down (HTTP %d)', $response_code), 'ALERT');
    } else {
        sitepulse_log('Uptime check: Up');
    }

    if (count($log) > 30) {
        $log = array_slice($log, -30);
    }

    update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
}
