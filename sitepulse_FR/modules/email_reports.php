<?php
/**
 * SitePulse Scheduled Reports Module
 *
 * Generates and delivers periodical health summaries via e-mail.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

$sitepulse_email_reports_cron_hook = function_exists('sitepulse_get_cron_hook') ? sitepulse_get_cron_hook('email_reports') : 'sitepulse_email_reports_cron';

add_action('init', 'sitepulse_email_reports_bootstrap');
add_action($sitepulse_email_reports_cron_hook, 'sitepulse_email_reports_send_scheduled_report');

add_action('update_option_' . SITEPULSE_OPTION_REPORT_FREQUENCY, 'sitepulse_email_reports_schedule_from_option_change', 10, 3);
add_action('update_option_' . SITEPULSE_OPTION_REPORT_TIME, 'sitepulse_email_reports_schedule_from_option_change', 10, 3);
add_action('update_option_' . SITEPULSE_OPTION_REPORT_WEEKDAY, 'sitepulse_email_reports_schedule_from_option_change', 10, 3);
add_action('update_option_' . SITEPULSE_OPTION_REPORT_RECIPIENTS, 'sitepulse_email_reports_schedule_from_option_change', 10, 3);

/**
 * Ensures the cron event is scheduled when the module loads.
 *
 * @return void
 */
function sitepulse_email_reports_bootstrap() {
    sitepulse_email_reports_ensure_schedule();
}

/**
 * Reschedules the cron event when a related setting is modified.
 *
 * @param mixed       $old_value Previous option value.
 * @param mixed       $value     New option value.
 * @param string|null $option    Option name (unused).
 *
 * @return void
 */
function sitepulse_email_reports_schedule_from_option_change($old_value, $value, $option = null) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    sitepulse_email_reports_ensure_schedule(true);
}

/**
 * Returns the sanitized list of recipients for scheduled reports.
 *
 * @return array
 */
function sitepulse_email_reports_get_recipients() {
    $stored = get_option(SITEPULSE_OPTION_REPORT_RECIPIENTS, []);

    if (!function_exists('sitepulse_sanitize_report_recipients')) {
        $recipients = [];
    } else {
        $recipients = sitepulse_sanitize_report_recipients($stored);
    }

    $admin_email = get_option('admin_email');

    if (is_string($admin_email)) {
        $admin_email = sanitize_email($admin_email);
        if ($admin_email !== '' && is_email($admin_email)) {
            $recipients[] = $admin_email;
        }
    }

    $recipients = array_values(array_unique(array_filter($recipients, 'is_email')));

    return $recipients;
}

/**
 * Determines the next time the report should be sent.
 *
 * @return int|null UNIX timestamp of the next run in site timezone, null when disabled.
 */
function sitepulse_email_reports_calculate_next_timestamp() {
    $frequency = get_option(SITEPULSE_OPTION_REPORT_FREQUENCY, 'disabled');

    if (function_exists('sitepulse_sanitize_report_frequency')) {
        $frequency = sitepulse_sanitize_report_frequency($frequency);
    } else {
        $frequency = 'disabled';
    }

    if ($frequency === 'disabled') {
        return null;
    }

    $recipients = sitepulse_email_reports_get_recipients();

    if (empty($recipients)) {
        return null;
    }

    $time_string = get_option(SITEPULSE_OPTION_REPORT_TIME, '08:00');

    if (function_exists('sitepulse_sanitize_report_time')) {
        $time_string = sitepulse_sanitize_report_time($time_string);
    } else {
        $time_string = '08:00';
    }

    list($hours, $minutes) = array_map('intval', explode(':', $time_string));

    $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $now      = new DateTime('now', $timezone);

    if ($frequency === 'weekly') {
        $weekday = get_option(SITEPULSE_OPTION_REPORT_WEEKDAY, 1);
        if (function_exists('sitepulse_sanitize_report_weekday')) {
            $weekday = sitepulse_sanitize_report_weekday($weekday);
        } else {
            $weekday = 1;
        }

        $labels = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $label  = isset($labels[$weekday]) ? $labels[$weekday] : 'monday';

        $target = new DateTime('now', $timezone);
        $target->modify('this ' . $label);
        $target->setTime($hours, $minutes, 0);

        if ($target <= $now) {
            $target->modify('next ' . $label);
            $target->setTime($hours, $minutes, 0);
        }

        return (int) $target->format('U');
    }

    // Monthly schedule: run on the first day of the next applicable month.
    $target = new DateTime('now', $timezone);
    $target->setDate((int) $now->format('Y'), (int) $now->format('n'), 1);
    $target->setTime($hours, $minutes, 0);

    if ($target <= $now) {
        $target->modify('first day of next month');
        $target->setTime($hours, $minutes, 0);
    }

    return (int) $target->format('U');
}

/**
 * Ensures the cron job is scheduled at the correct time.
 *
 * @param bool $force_reset Whether to reset the scheduled hook.
 * @return bool True when an event is scheduled, false otherwise.
 */
function sitepulse_email_reports_ensure_schedule($force_reset = false) {
    global $sitepulse_email_reports_cron_hook;

    $next_timestamp = sitepulse_email_reports_calculate_next_timestamp();
    $scheduled      = wp_next_scheduled($sitepulse_email_reports_cron_hook);

    if ($next_timestamp === null) {
        if ($scheduled) {
            wp_clear_scheduled_hook($sitepulse_email_reports_cron_hook);
        }

        return false;
    }

    if ($force_reset && $scheduled) {
        wp_clear_scheduled_hook($sitepulse_email_reports_cron_hook);
        $scheduled = false;
    }

    $tolerance = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;

    if (!$scheduled || abs($scheduled - $next_timestamp) > $tolerance) {
        wp_schedule_single_event($next_timestamp, $sitepulse_email_reports_cron_hook);
    }

    return true;
}

/**
 * Dispatches the scheduled report and re-schedules the next run.
 *
 * @return void
 */
function sitepulse_email_reports_send_scheduled_report() {
    $recipients = sitepulse_email_reports_get_recipients();

    if (empty($recipients)) {
        sitepulse_email_reports_ensure_schedule(true);
        return;
    }

    $frequency = get_option(SITEPULSE_OPTION_REPORT_FREQUENCY, 'disabled');
    if (function_exists('sitepulse_sanitize_report_frequency')) {
        $frequency = sitepulse_sanitize_report_frequency($frequency);
    }

    $metrics = sitepulse_email_reports_collect_metrics();

    $subject = sprintf(
        /* translators: %s: formatted date. */
        __('SitePulse – Rapport de santé du %s', 'sitepulse'),
        date_i18n(get_option('date_format'), current_time('timestamp'))
    );

    $body    = sitepulse_email_reports_render_email($metrics, $frequency);
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    $sent = wp_mail($recipients, $subject, $body, $headers);

    if (function_exists('sitepulse_log')) {
        $status = $sent ? 'sent' : 'failed';
        sitepulse_log('Scheduled report ' . $status . ' to: ' . implode(', ', $recipients));
    }

    sitepulse_email_reports_ensure_schedule(true);
}

/**
 * Collects the dataset used in the scheduled report.
 *
 * @return array
 */
function sitepulse_email_reports_collect_metrics() {
    return [
        'speed'  => sitepulse_email_reports_get_speed_snapshot(),
        'uptime' => sitepulse_email_reports_get_uptime_snapshot(),
        'logs'   => sitepulse_email_reports_get_log_snapshot(),
    ];
}

/**
 * Returns the most recent server speed measurement.
 *
 * @return array
 */
function sitepulse_email_reports_get_speed_snapshot() {
    $processing_time = null;
    $source          = 'transient';
    $results         = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);

    if (is_array($results)) {
        if (isset($results['server_processing_ms']) && is_numeric($results['server_processing_ms'])) {
            $processing_time = (float) $results['server_processing_ms'];
        } elseif (isset($results['ttfb']) && is_numeric($results['ttfb'])) {
            $processing_time = (float) $results['ttfb'];
            $source          = 'ttfb';
        } elseif (isset($results['data']['server_processing_ms']) && is_numeric($results['data']['server_processing_ms'])) {
            $processing_time = (float) $results['data']['server_processing_ms'];
        } elseif (isset($results['data']['ttfb']) && is_numeric($results['data']['ttfb'])) {
            $processing_time = (float) $results['data']['ttfb'];
            $source          = 'ttfb';
        }
    }

    if ($processing_time === null) {
        $stored = get_option(SITEPULSE_OPTION_LAST_LOAD_TIME);
        if (is_numeric($stored)) {
            $processing_time = (float) $stored;
            $source          = 'option';
        }
    }

    $status = 'unknown';

    if ($processing_time !== null) {
        if ($processing_time <= 200) {
            $status = 'good';
        } elseif ($processing_time <= 500) {
            $status = 'warning';
        } else {
            $status = 'critical';
        }
    }

    return [
        'value'        => $processing_time,
        'status'       => $status,
        'source'       => $source,
        'description'  => __('Temps de traitement du serveur sur la dernière mesure disponible.', 'sitepulse'),
    ];
}

/**
 * Summarizes the uptime log into a digestible snapshot.
 *
 * @return array
 */
function sitepulse_email_reports_get_uptime_snapshot() {
    $log     = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
    $entries = sitepulse_email_reports_normalize_uptime_log($log);

    $total_checks = count($entries);
    $up_checks    = 0;
    $last_downtime_start = null;
    $last_downtime_end   = null;

    foreach ($entries as $entry) {
        $status = !empty($entry['status']);
        if ($status) {
            $up_checks++;
            if ($last_downtime_start !== null && $last_downtime_end === null) {
                $last_downtime_end = isset($entry['timestamp']) ? (int) $entry['timestamp'] : time();
            }
        } else {
            if ($last_downtime_start === null && isset($entry['timestamp'])) {
                $last_downtime_start = (int) $entry['timestamp'];
            }
        }
    }

    $uptime_percentage = $total_checks > 0 ? ($up_checks / $total_checks) * 100 : null;
    $current_entry      = $total_checks > 0 ? $entries[$total_checks - 1] : null;
    $current_status     = ($current_entry && !empty($current_entry['status'])) ? 'good' : 'critical';

    $incident = null;

    if ($current_entry && empty($current_entry['status'])) {
        $start = isset($current_entry['incident_start']) ? (int) $current_entry['incident_start'] : (isset($current_entry['timestamp']) ? (int) $current_entry['timestamp'] : time());
        $incident = [
            'ongoing'  => true,
            'start'    => $start,
            'duration' => human_time_diff($start, current_time('timestamp')),
        ];
    } elseif ($last_downtime_start !== null) {
        $end = $last_downtime_end ?: $last_downtime_start;
        $incident = [
            'ongoing'  => false,
            'start'    => $last_downtime_start,
            'end'      => $end,
            'duration' => human_time_diff($last_downtime_start, $end),
        ];
    }

    return [
        'uptime'   => $uptime_percentage,
        'status'   => $current_status,
        'checks'   => $total_checks,
        'incident' => $incident,
    ];
}

/**
 * Normalizes the uptime log structure for calculations.
 *
 * @param mixed $log Raw log option value.
 * @return array
 */
function sitepulse_email_reports_normalize_uptime_log($log) {
    if (!is_array($log)) {
        return [];
    }

    $normalized = [];

    foreach ($log as $entry) {
        $timestamp = null;
        $status    = false;
        $incident  = null;

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
                $incident = (int) $entry['incident_start'];
            }
        } else {
            $status = (bool) (is_int($entry) ? $entry : !empty($entry));
        }

        if ($timestamp === null) {
            $timestamp = time();
        }

        $normalized[] = array_filter([
            'timestamp'      => $timestamp,
            'status'         => $status,
            'incident_start' => $incident,
        ], static function ($value) {
            return null !== $value;
        });
    }

    usort($normalized, static function ($a, $b) {
        return (int) $a['timestamp'] <=> (int) $b['timestamp'];
    });

    return array_values($normalized);
}

/**
 * Builds a snapshot of the latest log activity.
 *
 * @return array
 */
function sitepulse_email_reports_get_log_snapshot() {
    $path = function_exists('sitepulse_get_wp_debug_log_path') ? sitepulse_get_wp_debug_log_path(true) : null;

    if ($path === null || !is_readable($path)) {
        return [
            'available' => false,
            'counts'    => ['fatal' => 0, 'error' => 0, 'warning' => 0, 'notice' => 0],
            'entries'   => [],
        ];
    }

    $lines = function_exists('sitepulse_get_recent_log_lines')
        ? sitepulse_get_recent_log_lines($path, 20, 131072)
        : file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (!is_array($lines)) {
        $lines = [];
    }

    $counts = ['fatal' => 0, 'error' => 0, 'warning' => 0, 'notice' => 0];

    foreach ($lines as $line) {
        $lower = strtolower($line);
        if (strpos($lower, 'php fatal error') !== false || strpos($lower, 'php parse error') !== false) {
            $counts['fatal']++;
        } elseif (strpos($lower, 'php error') !== false) {
            $counts['error']++;
        } elseif (strpos($lower, 'php warning') !== false) {
            $counts['warning']++;
        } elseif (strpos($lower, 'php notice') !== false || strpos($lower, 'deprecated') !== false) {
            $counts['notice']++;
        }
    }

    $latest = array_slice($lines, -5);

    return [
        'available' => true,
        'counts'    => $counts,
        'entries'   => $latest,
    ];
}

/**
 * Renders the HTML body used in the scheduled e-mail.
 *
 * @param array  $metrics   Collected metrics.
 * @param string $frequency Selected frequency.
 * @return string
 */
function sitepulse_email_reports_render_email($metrics, $frequency) {
    $site_name   = get_bloginfo('name');
    $site_url    = home_url();
    $datetime    = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), current_time('timestamp'));
    $frequency_human = '';

    switch ($frequency) {
        case 'weekly':
            $frequency_human = __('Hebdomadaire', 'sitepulse');
            break;
        case 'monthly':
            $frequency_human = __('Mensuel', 'sitepulse');
            break;
        default:
            $frequency_human = __('Personnalisé', 'sitepulse');
    }

    $speed  = isset($metrics['speed']) ? $metrics['speed'] : [];
    $uptime = isset($metrics['uptime']) ? $metrics['uptime'] : [];
    $logs   = isset($metrics['logs']) ? $metrics['logs'] : [];

    $speed_value = isset($speed['value']) && $speed['value'] !== null
        ? sprintf('%s ms', number_format_i18n((float) $speed['value'], 0))
        : __('Non disponible', 'sitepulse');

    $speed_status = sitepulse_email_reports_status_label(isset($speed['status']) ? $speed['status'] : 'unknown');
    $uptime_percentage = isset($uptime['uptime']) && $uptime['uptime'] !== null
        ? sprintf('%s%%', number_format_i18n($uptime['uptime'], 2))
        : __('Non calculé', 'sitepulse');
    $uptime_status = sitepulse_email_reports_status_label(isset($uptime['status']) ? $uptime['status'] : 'unknown');

    $log_counts = isset($logs['counts']) ? $logs['counts'] : ['fatal' => 0, 'error' => 0, 'warning' => 0, 'notice' => 0];

    ob_start();
    ?>
    <div style="font-family:Arial, Helvetica, sans-serif; color:#1f2933; line-height:1.6;">
        <h1 style="font-size:22px; margin-bottom:10px;">SitePulse – <?php echo esc_html($site_name); ?></h1>
        <p style="margin:0 0 20px;">
            <?php
            printf(
                esc_html__('Rapport généré le %1$s (%2$s).', 'sitepulse'),
                esc_html($datetime),
                esc_html($frequency_human)
            );
            ?>
            <br>
            <a href="<?php echo esc_url($site_url); ?>" style="color:#2563eb; text-decoration:none;"><?php echo esc_html($site_url); ?></a>
        </p>
        <table style="width:100%; border-collapse:collapse; margin-bottom:25px;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;"><?php esc_html_e('Indicateur', 'sitepulse'); ?></th>
                    <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;"><?php esc_html_e('Valeur', 'sitepulse'); ?></th>
                    <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;"><?php esc_html_e('Statut', 'sitepulse'); ?></th>
                    <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="padding:12px 10px; border-bottom:1px solid #f3f4f6;"><strong><?php esc_html_e('Performance du serveur', 'sitepulse'); ?></strong></td>
                    <td style="padding:12px 10px; border-bottom:1px solid #f3f4f6;"><?php echo esc_html($speed_value); ?></td>
                    <td style="padding:12px 10px; border-bottom:1px solid #f3f4f6;">
                        <?php echo esc_html($speed_status); ?>
                    </td>
                    <td style="padding:12px 10px; border-bottom:1px solid #f3f4f6; color:#4b5563;">
                        <?php echo esc_html(isset($speed['description']) ? $speed['description'] : __('Dernière durée de génération de page connue.', 'sitepulse')); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px 10px; border-bottom:1px solid #f3f4f6;"><strong><?php esc_html_e('Disponibilité', 'sitepulse'); ?></strong></td>
                    <td style="padding:12px 10px; border-bottom:1px solid #f3f4f6;"><?php echo esc_html($uptime_percentage); ?></td>
                    <td style="padding:12px 10px; border-bottom:1px solid #f3f4f6;"><?php echo esc_html($uptime_status); ?></td>
                    <td style="padding:12px 10px; border-bottom:1px solid #f3f4f6; color:#4b5563;">
                        <?php
                        if (!empty($uptime['incident'])) {
                            $incident = $uptime['incident'];
                            if (!empty($incident['ongoing'])) {
                                printf(
                                    esc_html__('Incident en cours depuis %s.', 'sitepulse'),
                                    esc_html($incident['duration'])
                                );
                            } else {
                                printf(
                                    esc_html__('Dernier incident : %1$s (durée %2$s).', 'sitepulse'),
                                    esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $incident['start'])),
                                    esc_html($incident['duration'])
                                );
                            }
                        } elseif ($uptime_percentage !== __('Non calculé', 'sitepulse')) {
                            esc_html_e('Aucun incident détecté sur la période de mesure.', 'sitepulse');
                        } else {
                            esc_html_e('Aucune donnée de disponibilité enregistrée pour le moment.', 'sitepulse');
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px 10px;"><strong><?php esc_html_e('Journal PHP', 'sitepulse'); ?></strong></td>
                    <td style="padding:12px 10px;">
                        <?php
                        if (!empty($logs['available'])) {
                            printf(
                                esc_html__('%1$d fatales, %2$d erreurs, %3$d avertissements, %4$d notices', 'sitepulse'),
                                (int) $log_counts['fatal'],
                                (int) $log_counts['error'],
                                (int) $log_counts['warning'],
                                (int) $log_counts['notice']
                            );
                        } else {
                            esc_html_e('Journal non disponible ou désactivé.', 'sitepulse');
                        }
                        ?>
                    </td>
                    <td style="padding:12px 10px;">
                        <?php echo esc_html(sitepulse_email_reports_status_label(sitepulse_email_reports_logs_status($log_counts, $logs))); ?>
                    </td>
                    <td style="padding:12px 10px; color:#4b5563;">
                        <?php
                        if (!empty($logs['available']) && !empty($logs['entries'])) {
                            esc_html_e('Dernières entrées :', 'sitepulse');
                        } elseif (!empty($logs['available'])) {
                            esc_html_e('Aucune entrée récente.', 'sitepulse');
                        } else {
                            esc_html_e('Activez WP_DEBUG_LOG pour collecter des erreurs.', 'sitepulse');
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php if (!empty($logs['available']) && !empty($logs['entries'])) : ?>
            <div style="background:#f9fafb; border:1px solid #e5e7eb; padding:15px; border-radius:6px;">
                <h2 style="margin-top:0; font-size:16px; color:#111827;"><?php esc_html_e('Dernières entrées du journal', 'sitepulse'); ?></h2>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ($logs['entries'] as $line) : ?>
                        <li style="margin-bottom:6px; font-family:Consolas, Monaco, monospace; font-size:13px; color:#1f2933;"><?php echo esc_html($line); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <p style="margin-top:25px; font-size:12px; color:#6b7280;">
            <?php esc_html_e('Rapport envoyé automatiquement par SitePulse. Ajustez la fréquence et les destinataires depuis l’onglet Réglages.', 'sitepulse'); ?>
        </p>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Converts a status slug into a localized label.
 *
 * @param string $status Status slug.
 * @return string
 */
function sitepulse_email_reports_status_label($status) {
    switch ($status) {
        case 'good':
            return __('OK', 'sitepulse');
        case 'warning':
            return __('À surveiller', 'sitepulse');
        case 'critical':
            return __('Critique', 'sitepulse');
        default:
            return __('Inconnu', 'sitepulse');
    }
}

/**
 * Derives a status for the log section based on counts.
 *
 * @param array $counts Severity counters.
 * @param array $logs   Raw log snapshot.
 * @return string
 */
function sitepulse_email_reports_logs_status($counts, $logs) {
    if (empty($logs['available'])) {
        return 'unknown';
    }

    if (!empty($counts['fatal']) || !empty($counts['error'])) {
        return 'critical';
    }

    if (!empty($counts['warning'])) {
        return 'warning';
    }

    return 'good';
}
