<?php
if (!defined('ABSPATH')) exit;

$sitepulse_uptime_cron_hook = function_exists('sitepulse_get_cron_hook') ? sitepulse_get_cron_hook('uptime_tracker') : 'sitepulse_uptime_tracker_cron';

add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'Uptime Tracker', 'Uptime', 'manage_options', 'sitepulse-uptime', 'sitepulse_uptime_tracker_page'); });

if (!empty($sitepulse_uptime_cron_hook)) {
    add_action('init', function() use ($sitepulse_uptime_cron_hook) {
        if (!wp_next_scheduled($sitepulse_uptime_cron_hook)) {
            $next_run = (int) current_time('timestamp', true);
            wp_schedule_event($next_run, 'hourly', $sitepulse_uptime_cron_hook);
        }
    });
    add_action($sitepulse_uptime_cron_hook, 'sitepulse_run_uptime_check');
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
        $status = false;
        $incident_start = null;

        if (is_array($entry)) {
            if (isset($entry['timestamp']) && is_numeric($entry['timestamp'])) {
                $timestamp = (int) $entry['timestamp'];
            }

            if (array_key_exists('status', $entry)) {
                $status = (bool) $entry['status'];
            } else {
                $status = !empty($entry);
            }

            if (isset($entry['incident_start']) && is_numeric($entry['incident_start'])) {
                $incident_start = (int) $entry['incident_start'];
            }
        } else {
            $status = (bool) (is_int($entry) ? $entry : !empty($entry));
        }

        if (!$status) {
            if (null === $incident_start) {
                if (!empty($normalized)) {
                    $previous = end($normalized);
                    if (!$previous['status'] && isset($previous['incident_start'])) {
                        $incident_start = $previous['incident_start'];
                    }
                }

                if (null === $incident_start) {
                    $incident_start = $timestamp;
                }
            }
        } else {
            $incident_start = null;
        }

        $normalized[] = array_filter([
            'timestamp'      => $timestamp,
            'status'         => $status,
            'incident_start' => $incident_start,
        ], function ($value) {
            return null !== $value;
        });
    }

    usort($normalized, function ($a, $b) {
        return $a['timestamp'] <=> $b['timestamp'];
    });

    return array_values($normalized);
}

function sitepulse_uptime_tracker_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $uptime_log = sitepulse_normalize_uptime_log(get_option(SITEPULSE_OPTION_UPTIME_LOG, []));
    $total_checks = count($uptime_log);
    $up_checks = count(array_filter($uptime_log, function ($entry) {
        return !empty($entry['status']);
    }));
    $uptime_percentage = $total_checks > 0 ? ($up_checks / $total_checks) * 100 : 100;
    $date_format = get_option('date_format');
    $time_format = get_option('time_format');
    $current_incident_duration = '';
    $current_incident_start = null;

    if (!empty($uptime_log)) {
        $last_entry = end($uptime_log);
        if (!$last_entry['status']) {
            $current_incident_start = isset($last_entry['incident_start']) ? (int) $last_entry['incident_start'] : (int) $last_entry['timestamp'];
            $current_timestamp = (int) current_time('timestamp');
            $current_incident_duration = human_time_diff($current_incident_start, $current_timestamp);
        }
        reset($uptime_log);
    }
    ?>
    <style> .uptime-chart { display: flex; gap: 2px; height: 60px; align-items: flex-end; } .uptime-bar { flex-grow: 1; } .uptime-bar.up { background-color: #4CAF50; } .uptime-bar.down { background-color: #F44336; } </style>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-chart-bar"></span> Suivi de Disponibilité</h1>
        <p>Cet outil vérifie la disponibilité de votre site toutes les heures. Voici le statut des <?php echo esc_html($total_checks); ?> dernières vérifications.</p>
        <h2>Disponibilité (<?php echo esc_html($total_checks); ?> dernières heures): <strong style="font-size: 1.4em;"><?php echo esc_html(round($uptime_percentage, 2)); ?>%</strong></h2>
        <div class="uptime-chart">
            <?php if (empty($uptime_log)): ?><p>Aucune donnée de disponibilité. La première vérification aura lieu dans l'heure.</p><?php else: ?>
                <?php foreach ($uptime_log as $index => $entry): ?>
                    <?php
                    $status = !empty($entry['status']);
                    $bar_class = $status ? 'up' : 'down';
                    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                    $check_time = $timestamp > 0
                        ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                        : __('Horodatage inconnu', 'sitepulse');
                    $previous_entry = $index > 0 ? $uptime_log[$index - 1] : null;
                    $next_entry = ($index + 1) < $total_checks ? $uptime_log[$index + 1] : null;

                    if ($status) {
                        $bar_title = sprintf(__('Site OK lors du contrôle du %s.', 'sitepulse'), $check_time);

                        if (!empty($previous_entry) && empty($previous_entry['status'])) {
                            $incident_start = isset($previous_entry['incident_start']) ? (int) $previous_entry['incident_start'] : (isset($previous_entry['timestamp']) ? (int) $previous_entry['timestamp'] : 0);
                            if ($incident_start > 0 && $timestamp >= $incident_start) {
                                $incident_start_formatted = date_i18n($date_format . ' ' . $time_format, $incident_start);
                                $bar_title .= ' ' . sprintf(__('Retour à la normale après un incident débuté le %1$s (durée : %2$s).', 'sitepulse'), $incident_start_formatted, human_time_diff($incident_start, $timestamp));
                            }
                        }
                    } else {
                        $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : $timestamp;
                        $incident_start_formatted = $incident_start > 0
                            ? date_i18n($date_format . ' ' . $time_format, $incident_start)
                            : __('horodatage inconnu', 'sitepulse');
                        $bar_title = sprintf(__('Site KO lors du contrôle du %1$s. Incident commencé le %2$s.', 'sitepulse'), $check_time, $incident_start_formatted);

                        $is_transition = empty($previous_entry) || !empty($previous_entry['status']);

                        if ($index === $total_checks - 1 && !empty($current_incident_duration)) {
                            $bar_title .= ' ' . sprintf(__('Incident en cours depuis %s.', 'sitepulse'), $current_incident_duration);
                        } else {
                            $duration_reference = null;

                            if (!empty($next_entry) && !empty($next_entry['status'])) {
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
    $response_code = wp_remote_retrieve_response_code($response);
    // Considère les réponses HTTP dans la plage 2xx ou 3xx comme un site "up"
    $is_up = !is_wp_error($response) && $response_code >= 200 && $response_code < 400;

    $raw_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);

    if (!is_array($raw_log)) {
        $raw_log = empty($raw_log) ? [] : [$raw_log];
    }

    $log = sitepulse_normalize_uptime_log($raw_log);

    $timestamp = (int) current_time('timestamp');
    $entry = [
        'timestamp' => $timestamp,
        'status'    => $is_up,
    ];

    if (!$is_up) {
        $incident_start = $timestamp;
        if (!empty($log)) {
            $previous = end($log);
            if (!$previous['status']) {
                if (isset($previous['incident_start'])) {
                    $incident_start = (int) $previous['incident_start'];
                } elseif (isset($previous['timestamp'])) {
                    $incident_start = (int) $previous['timestamp'];
                }
            }
        }
        $entry['incident_start'] = $incident_start;
    }

    $log[] = $entry;

    if (count($log) > 30) {
        $log = array_slice($log, -30);
    }

    update_option(SITEPULSE_OPTION_UPTIME_LOG, array_values($log), false);
    if (!$is_up) { sitepulse_log('Uptime check: Down', 'ALERT'); } 
    else { sitepulse_log('Uptime check: Up'); }
}
