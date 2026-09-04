<?php
/**
 * SitePulse Uptime SLA reports and exports.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds aggregated availability windows based on the raw uptime log.
 *
 * @param array<int,array<string,mixed>>|null $log     Optional log entries to aggregate.
 * @param array<int,int>|null                 $windows Optional window sizes in days.
 * @param array<string,array<string,mixed>>|null $agents Optional agent map.
 * @return array<string,mixed>
 */
function sitepulse_uptime_build_sla_windows($log = null, $windows = null, $agents = null) {
    if (null === $log) {
        $raw_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $log = sitepulse_normalize_uptime_log($raw_log);
    } elseif (!empty($log)) {
        $first_entry = reset($log);

        if (!is_array($first_entry) || !array_key_exists('timestamp', $first_entry)) {
            $log = sitepulse_normalize_uptime_log($log);
        }
    }

    $log = sitepulse_trim_uptime_log($log);

    $default_windows = [7, 30];
    $windows = null === $windows ? $default_windows : (array) $windows;
    $windows = array_values(array_filter(array_map('intval', $windows), function ($value) {
        return $value > 0;
    }));

    if (empty($windows)) {
        $windows = $default_windows;
    }

    $now = (int) current_time('timestamp');
    $window_map = [];

    foreach ($windows as $days) {
        $key = $days . 'd';
        $window_map[$key] = [
            'label' => sprintf(_n('%s jour', '%s jours', $days, 'sitepulse'), number_format_i18n($days)),
            'days'  => (int) $days,
            'start' => $now - ($days * DAY_IN_SECONDS),
            'end'   => $now,
        ];
    }

    if (null === $agents) {
        $agents = sitepulse_uptime_get_agents();
    }

    $agents_from_log = [];

    foreach ($log as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $agent = isset($entry['agent']) ? sitepulse_uptime_normalize_agent_id($entry['agent']) : 'default';
        $agents_from_log[$agent] = true;
    }

    $all_agent_ids = array_unique(array_merge(array_keys($agents_from_log), array_keys((array) $agents), ['default']));
    $agent_profiles = [];

    foreach ($all_agent_ids as $agent_id) {
        $agent_config = isset($agents[$agent_id]) ? $agents[$agent_id] : sitepulse_uptime_get_agent($agent_id);

        $agent_profiles[$agent_id] = [
            'id'      => $agent_id,
            'label'   => isset($agent_config['label']) && is_string($agent_config['label']) && '' !== $agent_config['label']
                ? $agent_config['label']
                : ($agent_id === 'default' ? __('Agent principal', 'sitepulse') : $agent_id),
            'region'  => isset($agent_config['region']) ? sanitize_key($agent_config['region']) : 'global',
            'weight'  => sitepulse_uptime_get_agent_weight($agent_id, $agent_config),
            'active'  => sitepulse_uptime_agent_is_active($agent_id, $agent_config),
        ];
    }

    $entries_per_agent = [];

    foreach ($log as $entry) {
        if (!is_array($entry) || !isset($entry['timestamp'])) {
            continue;
        }

        $agent_id = isset($entry['agent']) ? sitepulse_uptime_normalize_agent_id($entry['agent']) : 'default';

        if (!isset($entries_per_agent[$agent_id])) {
            $entries_per_agent[$agent_id] = [];
        }

        $entries_per_agent[$agent_id][] = $entry;
    }

    $windows_payload = [];
    $overall_start = $now;

    foreach ($window_map as $window_key => $window_data) {
        $window_start = (int) $window_data['start'];
        $window_end = (int) $window_data['end'];

        if ($window_start < $overall_start) {
            $overall_start = $window_start;
        }

        $window_agents = [];
        $global_totals = [
            'availability'      => 100.0,
            'total_checks'      => 0,
            'up_checks'         => 0,
            'down_checks'       => 0,
            'unknown_checks'    => 0,
            'maintenance_checks'=> 0,
            'effective_checks'  => 0,
            'downtime_total'    => 0,
            'maintenance_total' => 0,
            'incident_count'    => 0,
            'maintenance_count' => 0,
        ];
        $weighted_total = 0.0;
        $weighted_up = 0.0;

        foreach ($agent_profiles as $agent_id => $profile) {
            if (!$profile['active'] && !isset($entries_per_agent[$agent_id])) {
                continue;
            }

            $agent_entries = isset($entries_per_agent[$agent_id]) ? $entries_per_agent[$agent_id] : [];
            $breakdown = sitepulse_uptime_calculate_agent_window_breakdown($agent_entries, $window_start, $window_end);

            if (empty($agent_entries) && 0 === $breakdown['total_checks'] && 0 === $breakdown['maintenance_checks']) {
                if (!$profile['active']) {
                    continue;
                }
            }

            $window_agents[$agent_id] = $breakdown;

            $global_totals['total_checks'] += $breakdown['total_checks'];
            $global_totals['up_checks'] += $breakdown['up_checks'];
            $global_totals['down_checks'] += $breakdown['down_checks'];
            $global_totals['unknown_checks'] += $breakdown['unknown_checks'];
            $global_totals['maintenance_checks'] += $breakdown['maintenance_checks'];
            $global_totals['effective_checks'] += $breakdown['effective_checks'];
            $global_totals['downtime_total'] += isset($breakdown['downtime']['total_duration'])
                ? (float) $breakdown['downtime']['total_duration']
                : 0.0;
            $global_totals['maintenance_total'] += isset($breakdown['maintenance']['total_duration'])
                ? (float) $breakdown['maintenance']['total_duration']
                : 0.0;
            $global_totals['incident_count'] += isset($breakdown['downtime']['incidents'])
                ? count($breakdown['downtime']['incidents'])
                : 0;
            $global_totals['maintenance_count'] += isset($breakdown['maintenance']['windows'])
                ? count($breakdown['maintenance']['windows'])
                : 0;

            if ($profile['weight'] > 0 && $breakdown['effective_checks'] > 0) {
                $weighted_total += $breakdown['effective_checks'] * $profile['weight'];
                $weighted_up += $breakdown['up_checks'] * $profile['weight'];
            }
        }

        if ($weighted_total > 0) {
            $global_totals['availability'] = ($weighted_up / $weighted_total) * 100;
        } elseif ($global_totals['effective_checks'] > 0) {
            $global_totals['availability'] = ($global_totals['up_checks'] / max(1, $global_totals['effective_checks'])) * 100;
        }

        $windows_payload[$window_key] = array_merge($window_data, [
            'agents' => $window_agents,
            'global' => $global_totals,
        ]);
    }

    return [
        'generated_at' => $now,
        'period'       => [
            'start' => $overall_start,
            'end'   => $now,
        ],
        'windows'      => $windows_payload,
        'agents'       => $agent_profiles,
        'entries'      => count($log),
    ];
}

/**
 * Computes detailed availability statistics for a single agent within a time window.
 *
 * @param array<int,array<string,mixed>> $entries      Agent-specific entries.
 * @param int                            $window_start Window start timestamp.
 * @param int                            $window_end   Window end timestamp.
 * @return array<string,mixed>
 */
function sitepulse_uptime_calculate_agent_window_breakdown($entries, $window_start, $window_end) {
    $window_start = (int) $window_start;
    $window_end = (int) $window_end;

    $result = [
        'start'              => $window_start,
        'end'                => $window_end,
        'availability'       => 100.0,
        'total_checks'       => 0,
        'up_checks'          => 0,
        'down_checks'        => 0,
        'unknown_checks'     => 0,
        'maintenance_checks' => 0,
        'effective_checks'   => 0,
        'downtime'           => [
            'total_duration' => 0.0,
            'incidents'      => [],
        ],
        'maintenance'        => [
            'total_duration' => 0.0,
            'windows'        => [],
        ],
    ];

    if (empty($entries) || $window_end <= $window_start) {
        return $result;
    }

    $entries = array_values($entries);
    $previous_entry = null;

    foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry['timestamp'])) {
            continue;
        }

        $entry_timestamp = (int) $entry['timestamp'];

        if ($entry_timestamp < $window_start) {
            $previous_entry = $entry;
            continue;
        }

        break;
    }

    $current_state = 'unknown';
    $current_incident = null;
    $current_maintenance = null;

    if (null !== $previous_entry) {
        $state = isset($previous_entry['status'])
            ? sitepulse_uptime_normalize_status_value($previous_entry['status'])
            : null;

        if (null === $state) {
            $state = 'unknown';
        }

        $current_state = $state;

        if (false === $state) {
            $incident_start = isset($previous_entry['incident_start'])
                ? (int) $previous_entry['incident_start']
                : (int) $previous_entry['timestamp'];

            if ($incident_start < $window_start) {
                $incident_start = $window_start;
            }

            $current_incident = ['start' => $incident_start];
        } elseif ('maintenance' === $state) {
            $current_maintenance = ['start' => $window_start];
        }
    }

    $previous_timestamp = $window_start;

    foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry['timestamp'])) {
            continue;
        }

        $original_timestamp = (int) $entry['timestamp'];

        if ($original_timestamp < $window_start) {
            continue;
        }

        $timestamp = $original_timestamp;

        if ($timestamp > $window_end) {
            $timestamp = $window_end;
        }

        if ($timestamp < $previous_timestamp) {
            $timestamp = $previous_timestamp;
        }

        $delta = $timestamp - $previous_timestamp;

        if ($delta > 0) {
            if (false === $current_state) {
                $result['downtime']['total_duration'] += $delta;
            } elseif ('maintenance' === $current_state) {
                $result['maintenance']['total_duration'] += $delta;
            }
        }

        if ($original_timestamp > $window_end) {
            $previous_timestamp = $timestamp;
            break;
        }

        $status = isset($entry['status']) ? sitepulse_uptime_normalize_status_value($entry['status']) : null;

        if (null === $status) {
            $status = 'unknown';
        }

        if ($current_incident && false !== $status) {
            $incident_end = $timestamp;

            if ($incident_end < $current_incident['start']) {
                $incident_end = $current_incident['start'];
            }

            $result['downtime']['incidents'][] = [
                'start'    => $current_incident['start'],
                'end'      => $incident_end,
                'duration' => max(0, $incident_end - $current_incident['start']),
            ];

            $current_incident = null;
        }

        if ($current_maintenance && 'maintenance' !== $status) {
            $maintenance_end = $timestamp;

            if ($maintenance_end < $current_maintenance['start']) {
                $maintenance_end = $current_maintenance['start'];
            }

            $result['maintenance']['windows'][] = [
                'start'    => $current_maintenance['start'],
                'end'      => $maintenance_end,
                'duration' => max(0, $maintenance_end - $current_maintenance['start']),
            ];

            $current_maintenance = null;
        }

        if ('maintenance' === $status) {
            $result['maintenance_checks']++;
        } else {
            $result['total_checks']++;

            if (true === $status) {
                $result['up_checks']++;
            } elseif (false === $status) {
                $result['down_checks']++;
            } else {
                $result['unknown_checks']++;
            }
        }

        $previous_timestamp = $timestamp;
        $current_state = $status;

        if (false === $status) {
            $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : $timestamp;

            if ($incident_start < $window_start) {
                $incident_start = $window_start;
            } elseif ($incident_start > $timestamp) {
                $incident_start = $timestamp;
            }

            $current_incident = ['start' => $incident_start];
        } elseif ('maintenance' === $status) {
            $current_maintenance = ['start' => max($window_start, $timestamp)];
        }
    }

    $final_delta = $window_end - $previous_timestamp;

    if ($final_delta > 0) {
        if (false === $current_state) {
            $result['downtime']['total_duration'] += $final_delta;
        } elseif ('maintenance' === $current_state) {
            $result['maintenance']['total_duration'] += $final_delta;
        }
    }

    if ($current_incident) {
        $incident_end = $window_end;

        if ($incident_end < $current_incident['start']) {
            $incident_end = $current_incident['start'];
        }

        $result['downtime']['incidents'][] = [
            'start'    => $current_incident['start'],
            'end'      => $incident_end,
            'duration' => max(0, $incident_end - $current_incident['start']),
        ];
    }

    if ($current_maintenance) {
        $maintenance_end = $window_end;

        if ($maintenance_end < $current_maintenance['start']) {
            $maintenance_end = $current_maintenance['start'];
        }

        $result['maintenance']['windows'][] = [
            'start'    => $current_maintenance['start'],
            'end'      => $maintenance_end,
            'duration' => max(0, $maintenance_end - $current_maintenance['start']),
        ];
    }

    $result['downtime']['incidents'] = array_values($result['downtime']['incidents']);
    $result['maintenance']['windows'] = array_values($result['maintenance']['windows']);
    $result['downtime']['total_duration'] = (float) $result['downtime']['total_duration'];
    $result['maintenance']['total_duration'] = (float) $result['maintenance']['total_duration'];
    $result['effective_checks'] = $result['total_checks'];

    if ($result['effective_checks'] > 0) {
        $result['availability'] = ($result['up_checks'] / max(1, $result['effective_checks'])) * 100;
    }

    return $result;
}

/**
 * Returns the directory used to persist SLA report artifacts.
 *
 * @return array<string,string>|WP_Error
 */
function sitepulse_uptime_get_sla_reports_directory() {
    if (!function_exists('wp_upload_dir')) {
        return new WP_Error('sitepulse_upload_unsupported', __('Le répertoire d’upload est indisponible.', 'sitepulse'));
    }

    $uploads = wp_upload_dir();

    if (!is_array($uploads) || !empty($uploads['error'])) {
        $message = isset($uploads['error']) ? $uploads['error'] : __('Impossible de déterminer le répertoire d’upload.', 'sitepulse');

        return new WP_Error('sitepulse_upload_error', $message);
    }

    $base_dir = trailingslashit($uploads['basedir']);
    $base_url = trailingslashit($uploads['baseurl']);
    $reports_dir = $base_dir . SITEPULSE_UPTIME_SLA_DIRECTORY;
    $reports_url = $base_url . SITEPULSE_UPTIME_SLA_DIRECTORY;

    if (!wp_mkdir_p($reports_dir)) {
        return new WP_Error('sitepulse_upload_permission', __('Impossible de créer le dossier des rapports SLA.', 'sitepulse'));
    }

    return [
        'path' => trailingslashit($reports_dir),
        'url'  => trailingslashit($reports_url),
    ];
}

/**
 * Persists a consolidated SLA report and returns its metadata.
 *
 * @param string $trigger  Report trigger (manual, automation, queue...).
 * @param array<int,int>|null $windows Optional windows in days.
 * @return array<string,mixed>|WP_Error
 */
function sitepulse_uptime_generate_sla_report($trigger = 'manual', $windows = null) {
    $aggregation = sitepulse_uptime_build_sla_windows(null, $windows, sitepulse_uptime_get_agents());
    $directory = sitepulse_uptime_get_sla_reports_directory();

    if (is_wp_error($directory)) {
        return $directory;
    }

    $timestamp_utc = (int) current_time('timestamp', true);
    $report_id = gmdate('Ymd-His', $timestamp_utc);
    $base_filename = sprintf('sitepulse-uptime-sla-%s', $report_id);
    $csv_path = $directory['path'] . $base_filename . '.csv';
    $pdf_path = $directory['path'] . $base_filename . '.pdf';
    $json_path = $directory['path'] . $base_filename . '.json';

    $csv_result = sitepulse_uptime_write_sla_csv($aggregation, $csv_path);

    if (is_wp_error($csv_result)) {
        return $csv_result;
    }

    $pdf_result = sitepulse_uptime_write_sla_pdf($aggregation, $pdf_path);

    if (is_wp_error($pdf_result)) {
        return $pdf_result;
    }

    $json_payload = function_exists('wp_json_encode')
        ? wp_json_encode($aggregation, JSON_PRETTY_PRINT)
        : json_encode($aggregation, JSON_PRETTY_PRINT);

    if (!is_string($json_payload)) {
        $json_payload = '{}';
    }

    if (false === file_put_contents($json_path, $json_payload)) {
        return new WP_Error('sitepulse_report_write_failed', __('Impossible d’écrire les métadonnées du rapport SLA.', 'sitepulse'));
    }

    $agents_included = [];

    foreach ($aggregation['agents'] as $agent_id => $agent_profile) {
        if (!isset($aggregation['windows'])) {
            continue;
        }

        foreach ($aggregation['windows'] as $window_details) {
            if (isset($window_details['agents'][$agent_id])) {
                $agents_included[$agent_id] = $agent_profile['label'];
                break;
            }
        }
    }

    $metadata = [
        'id'           => $report_id,
        'trigger'      => sanitize_key($trigger),
        'generated_at' => (int) $aggregation['generated_at'],
        'period'       => $aggregation['period'],
        'windows'      => array_keys($aggregation['windows']),
        'agents'       => $agents_included,
        'files'        => [
            'csv'  => [
                'path' => $csv_path,
                'url'  => $directory['url'] . basename($csv_path),
            ],
            'pdf'  => [
                'path' => $pdf_path,
                'url'  => $directory['url'] . basename($pdf_path),
            ],
            'json' => [
                'path' => $json_path,
                'url'  => $directory['url'] . basename($json_path),
            ],
        ],
        'summary'      => [],
    ];

    foreach ($aggregation['windows'] as $window_key => $window_details) {
        $metadata['summary'][$window_key] = [
            'availability'   => isset($window_details['global']['availability']) ? (float) $window_details['global']['availability'] : 100.0,
            'downtime'       => isset($window_details['global']['downtime_total']) ? (float) $window_details['global']['downtime_total'] : 0.0,
            'maintenance'    => isset($window_details['global']['maintenance_total']) ? (float) $window_details['global']['maintenance_total'] : 0.0,
            'incident_count' => isset($window_details['global']['incident_count']) ? (int) $window_details['global']['incident_count'] : 0,
        ];
    }

    sitepulse_uptime_store_sla_report_metadata($metadata);

    /**
     * Fires when a SLA report has been generated and stored.
     *
     * @param array<string,mixed> $metadata    Stored metadata.
     * @param array<string,mixed> $aggregation Full aggregation payload.
     */
    do_action('sitepulse_uptime_sla_report_generated', $metadata, $aggregation);

    sitepulse_uptime_send_report_notifications($metadata, $aggregation);

    return $metadata;
}

/**
 * Writes the CSV representation of the SLA report.
 *
 * @param array<string,mixed> $aggregation Aggregated data.
 * @param string              $destination Destination path.
 * @return true|WP_Error
 */
function sitepulse_uptime_write_sla_csv($aggregation, $destination) {
    $handle = fopen($destination, 'wb');

    if (false === $handle) {
        return new WP_Error('sitepulse_report_csv', __('Impossible de créer le fichier CSV du rapport SLA.', 'sitepulse'));
    }

    fwrite($handle, "\xEF\xBB\xBF");

    $date_format = get_option('date_format', 'Y-m-d');
    $time_format = get_option('time_format', 'H:i');
    $generated_at = isset($aggregation['generated_at']) ? (int) $aggregation['generated_at'] : (int) current_time('timestamp');
    $period = isset($aggregation['period']) ? $aggregation['period'] : ['start' => $generated_at, 'end' => $generated_at];
    $generated_label = function_exists('wp_date')
        ? wp_date($date_format . ' ' . $time_format, $generated_at)
        : date($date_format . ' ' . $time_format, $generated_at);
    $period_label = sitepulse_uptime_format_report_period($period, $date_format, $time_format);

    fputcsv($handle, sitepulse_uptime_escape_csv_row(['SitePulse SLA Report']));
    fputcsv($handle, sitepulse_uptime_escape_csv_row([__('Période couverte', 'sitepulse'), $period_label]));
    fputcsv($handle, sitepulse_uptime_escape_csv_row([__('Généré le', 'sitepulse'), $generated_label]));
    fputcsv($handle, sitepulse_uptime_escape_csv_row([]));

    foreach ($aggregation['windows'] as $window_key => $window_details) {
        $window_label = isset($window_details['label']) ? $window_details['label'] : $window_key;
        $availability = isset($window_details['global']['availability'])
            ? number_format_i18n((float) $window_details['global']['availability'], 2)
            : '100.00';
        $downtime = isset($window_details['global']['downtime_total']) ? (float) $window_details['global']['downtime_total'] : 0.0;
        $maintenance = isset($window_details['global']['maintenance_total']) ? (float) $window_details['global']['maintenance_total'] : 0.0;
        $incidents = isset($window_details['global']['incident_count']) ? (int) $window_details['global']['incident_count'] : 0;

        fputcsv($handle, sitepulse_uptime_escape_csv_row([sprintf(__('Fenêtre %s', 'sitepulse'), $window_label)]));
        fputcsv($handle, sitepulse_uptime_escape_csv_row([
            __('Disponibilité moyenne (%)', 'sitepulse'),
            $availability,
            __('Incidents', 'sitepulse'),
            number_format_i18n($incidents),
            __('Durée indisponibilité (s)', 'sitepulse'),
            number_format_i18n($downtime, 2),
            __('Fenêtres de maintenance (s)', 'sitepulse'),
            number_format_i18n($maintenance, 2),
        ]));

        $header = [
            __('Agent', 'sitepulse'),
            __('Région', 'sitepulse'),
            __('Disponibilité (%)', 'sitepulse'),
            __('Contrôles', 'sitepulse'),
            __('Incidents', 'sitepulse'),
            __('Durée incidents (s)', 'sitepulse'),
            __('Maintenance (s)', 'sitepulse'),
        ];
        fputcsv($handle, sitepulse_uptime_escape_csv_row($header));

        foreach ($window_details['agents'] as $agent_id => $agent_breakdown) {
            $profile = isset($aggregation['agents'][$agent_id]) ? $aggregation['agents'][$agent_id] : ['label' => $agent_id, 'region' => 'global'];
            $agent_availability = isset($agent_breakdown['availability']) ? number_format_i18n((float) $agent_breakdown['availability'], 2) : '100.00';
            $incident_count = isset($agent_breakdown['downtime']['incidents']) ? count($agent_breakdown['downtime']['incidents']) : 0;
            $downtime_total = isset($agent_breakdown['downtime']['total_duration']) ? (float) $agent_breakdown['downtime']['total_duration'] : 0.0;
            $maintenance_total = isset($agent_breakdown['maintenance']['total_duration']) ? (float) $agent_breakdown['maintenance']['total_duration'] : 0.0;
            $effective_checks = isset($agent_breakdown['effective_checks']) ? (int) $agent_breakdown['effective_checks'] : 0;

            fputcsv($handle, sitepulse_uptime_escape_csv_row([
                $profile['label'],
                isset($profile['region']) ? $profile['region'] : 'global',
                $agent_availability,
                number_format_i18n($effective_checks),
                number_format_i18n($incident_count),
                number_format_i18n($downtime_total, 2),
                number_format_i18n($maintenance_total, 2),
            ]));
        }

        fputcsv($handle, sitepulse_uptime_escape_csv_row([]));
    }

    fclose($handle);

    return true;
}

/**
 * Writes a minimal PDF report summarising SLA metrics.
 *
 * @param array<string,mixed> $aggregation Aggregated data.
 * @param string              $destination File path.
 * @return true|WP_Error
 */
function sitepulse_uptime_write_sla_pdf($aggregation, $destination) {
    $lines = [];
    $lines[] = 'SitePulse SLA Report';
    $period = isset($aggregation['period']) ? $aggregation['period'] : ['start' => $aggregation['generated_at'], 'end' => $aggregation['generated_at']];
    $lines[] = sprintf(__('Période : %s', 'sitepulse'), sitepulse_uptime_format_report_period($period));
    $lines[] = sprintf(__('Généré le : %s', 'sitepulse'), date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $aggregation['generated_at']));
    $lines[] = '';

    foreach ($aggregation['windows'] as $window_key => $window_details) {
        $window_label = isset($window_details['label']) ? $window_details['label'] : $window_key;
        $lines[] = sprintf(__('Fenêtre %s', 'sitepulse'), $window_label);
        $lines[] = sprintf('  %s: %s%%', __('Disponibilité', 'sitepulse'), number_format_i18n((float) $window_details['global']['availability'], 2));
        $lines[] = sprintf('  %s: %s', __('Incidents', 'sitepulse'), number_format_i18n((int) $window_details['global']['incident_count']));
        $lines[] = sprintf('  %s: %s', __('Indisponibilité', 'sitepulse'), sitepulse_uptime_format_duration_i18n($window_details['global']['downtime_total']));
        $lines[] = sprintf('  %s: %s', __('Maintenance', 'sitepulse'), sitepulse_uptime_format_duration_i18n($window_details['global']['maintenance_total']));

        foreach ($window_details['agents'] as $agent_id => $agent_breakdown) {
            if (!isset($aggregation['agents'][$agent_id])) {
                continue;
            }

            $profile = $aggregation['agents'][$agent_id];
            $lines[] = sprintf('    • %s (%s) — %s%%', $profile['label'], isset($profile['region']) ? $profile['region'] : 'global', number_format_i18n((float) $agent_breakdown['availability'], 2));
        }

        $lines[] = '';
    }

    return sitepulse_uptime_generate_simple_pdf($lines, $destination);
}

/**
 * Generates a minimalist PDF file from text lines.
 *
 * @param array<int,string> $lines Text lines.
 * @param string            $destination File path.
 * @return true|WP_Error
 */
function sitepulse_uptime_generate_simple_pdf($lines, $destination) {
    $content_stream = "BT\n/F1 12 Tf\n1 0 0 1 72 770 Tm\n";
    $first_line = true;

    foreach ($lines as $line) {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $line);

        if (!$first_line) {
            $content_stream .= "0 -16 Td\n";
        }

        $content_stream .= '(' . $escaped . ") Tj\n";
        $first_line = false;
    }

    $content_stream .= "ET\n";
    $content_length = strlen($content_stream);

    $objects = [];
    $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj";
    $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj";
    $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj";
    $objects[] = sprintf("4 0 obj << /Length %d >> stream\n%s\nendstream endobj", $content_length, $content_stream);
    $objects[] = "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj";

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object . "\n";
    }

    $xref_position = strlen($pdf);
    $pdf .= 'xref' . "\n";
    $pdf .= '0 ' . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xref_position . "\n";
    $pdf .= "%%EOF";

    if (false === file_put_contents($destination, $pdf)) {
        return new WP_Error('sitepulse_report_pdf', __('Impossible de générer le PDF du rapport SLA.', 'sitepulse'));
    }

    return true;
}

/**
 * Stores the last generated reports metadata in the dedicated option.
 *
 * @param array<string,mixed> $metadata Report metadata.
 * @return void
 */
function sitepulse_uptime_store_sla_report_metadata($metadata) {
    $existing = get_option(SITEPULSE_OPTION_UPTIME_SLA_REPORTS, []);

    if (!is_array($existing)) {
        $existing = [];
    }

    array_unshift($existing, $metadata);
    $existing = array_slice($existing, 0, 10);

    update_option(SITEPULSE_OPTION_UPTIME_SLA_REPORTS, array_values($existing), false);
}

/**
 * Retrieves the persisted SLA report metadata entries.
 *
 * @param int $limit Maximum entries to return.
 * @return array<int,array<string,mixed>>
 */
function sitepulse_uptime_get_sla_reports($limit = 10) {
    $reports = get_option(SITEPULSE_OPTION_UPTIME_SLA_REPORTS, []);

    if (!is_array($reports) || empty($reports)) {
        return [];
    }

    return array_slice($reports, 0, max(1, (int) $limit));
}

/**
 * Sends notifications (email/webhook) after a report generation.
 *
 * @param array<string,mixed> $metadata    Report metadata.
 * @param array<string,mixed> $aggregation Aggregation payload.
 * @return void
 */
function sitepulse_uptime_send_report_notifications($metadata, $aggregation) {
    $settings = sitepulse_uptime_get_sla_automation_settings();

    if (empty($settings['email_enabled']) && empty($settings['webhook_enabled'])) {
        return;
    }

    $subject = sprintf(__('Rapport SLA SitePulse (%s)', 'sitepulse'), sitepulse_uptime_format_report_period($metadata['period']));
    $body_lines = [];
    $body_lines[] = __('Bonjour,', 'sitepulse');
    $body_lines[] = '';
    $body_lines[] = sprintf(__('Votre rapport SLA vient d’être généré (%s).', 'sitepulse'), sitepulse_uptime_format_report_period($metadata['period']));

    foreach ($metadata['summary'] as $window_key => $window_summary) {
        $body_lines[] = sprintf(
            '- %s : %s%% (%s incidents, %s indisponibilité)',
            $window_key,
            number_format_i18n($window_summary['availability'], 2),
            number_format_i18n($window_summary['incident_count']),
            sitepulse_uptime_format_duration_i18n($window_summary['downtime'])
        );
    }

    $body_lines[] = '';
    $body_lines[] = __('Les rapports CSV et PDF sont disponibles en pièce jointe.', 'sitepulse');
    $body_lines[] = __('— Équipe SitePulse', 'sitepulse');
    $body = implode("\n", $body_lines);

    if (!empty($settings['email_enabled']) && !empty($settings['recipients'])) {
        $attachments = [];

        if (isset($metadata['files']['csv']['path']) && file_exists($metadata['files']['csv']['path'])) {
            $attachments[] = $metadata['files']['csv']['path'];
        }

        if (isset($metadata['files']['pdf']['path']) && file_exists($metadata['files']['pdf']['path'])) {
            $attachments[] = $metadata['files']['pdf']['path'];
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        wp_mail($settings['recipients'], $subject, $body, $headers, $attachments);
    }

    if (!empty($settings['webhook_enabled']) && !empty($settings['webhook_url'])) {
        sitepulse_uptime_dispatch_report_webhook($settings['webhook_url'], $metadata, $aggregation);
    }
}

/**
 * Dispatches a webhook request containing report metadata.
 *
 * @param string $url        Webhook URL.
 * @param array  $metadata   Metadata to send.
 * @param array  $aggregation Aggregated data.
 * @return void
 */
function sitepulse_uptime_dispatch_report_webhook($url, $metadata, $aggregation) {
    if (!function_exists('wp_remote_post')) {
        return;
    }

    $payload = [
        'report'      => $metadata,
        'aggregation' => $aggregation,
        'site'        => get_bloginfo('name'),
        'generated'   => gmdate('c', (int) $metadata['generated_at']),
    ];

    $args = [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload),
        'timeout' => 15,
    ];

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response) && function_exists('sitepulse_log')) {
        sitepulse_log(sprintf('Webhook SLA report failed: %s', $response->get_error_message()), 'WARNING');
    }
}

/**
 * Retrieves and sanitizes SLA automation settings.
 *
 * @return array<string,mixed>
 */
function sitepulse_uptime_get_sla_automation_settings() {
    $defaults = [
        'enabled'        => false,
        'frequency'      => 'monthly',
        'email_enabled'  => false,
        'recipients'     => [],
        'webhook_enabled'=> false,
        'webhook_url'    => '',
        'windows'        => [7, 30],
        'next_run'       => 0,
    ];

    $stored = get_option(SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION, []);

    if (!is_array($stored)) {
        $stored = [];
    }

    $settings = array_merge($defaults, $stored);
    $settings['enabled'] = (bool) $settings['enabled'];
    $settings['email_enabled'] = (bool) $settings['email_enabled'];
    $settings['webhook_enabled'] = (bool) $settings['webhook_enabled'];
    $settings['frequency'] = in_array($settings['frequency'], ['weekly', 'monthly'], true) ? $settings['frequency'] : 'monthly';
    $settings['next_run'] = isset($settings['next_run']) ? (int) $settings['next_run'] : 0;

    if (!is_array($settings['windows']) || empty($settings['windows'])) {
        $settings['windows'] = [7, 30];
    } else {
        $settings['windows'] = array_values(array_filter(array_map('intval', $settings['windows']), function ($value) {
            return $value > 0;
        }));

        if (empty($settings['windows'])) {
            $settings['windows'] = [7, 30];
        }
    }

    if (!is_array($settings['recipients'])) {
        $settings['recipients'] = [];
    }

    $settings['recipients'] = array_values(array_filter(array_map('sanitize_email', $settings['recipients'])));

    if (!$settings['webhook_enabled'] || empty($settings['webhook_url']) || !wp_http_validate_url($settings['webhook_url'])) {
        $settings['webhook_enabled'] = false;
        $settings['webhook_url'] = '';
    }

    if ($settings['email_enabled'] && empty($settings['recipients'])) {
        $settings['email_enabled'] = false;
    }

    return $settings;
}

/**
 * Schedules the next automated SLA report generation through the remote queue.
 *
 * @param array<string,mixed> $settings Automation settings.
 * @param bool                $force    Force recalculation of the next run.
 * @return void
 */
function sitepulse_uptime_schedule_automation_job($settings, $force = false) {
    if (empty($settings['enabled'])) {
        return;
    }

    $interval = sitepulse_uptime_get_automation_interval($settings['frequency']);
    $now = (int) current_time('timestamp', true);
    $next_run = isset($settings['next_run']) ? (int) $settings['next_run'] : 0;

    if ($force || $next_run <= $now) {
        $next_run = $now + $interval;
    }

    $payload = [
        'task'       => 'uptime_sla_report',
        'windows'    => $settings['windows'],
        'automation' => true,
        'frequency'  => $settings['frequency'],
    ];

    $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);

    if (!is_array($queue)) {
        $queue = [];
    }

    $queue[] = [
        'agent'       => 'sitepulse-reports',
        'payload'     => $payload,
        'scheduled_at'=> $next_run,
        'created_at'  => $now,
        'priority'    => 1,
    ];

    $queue = sitepulse_uptime_normalize_remote_queue($queue, $now);
    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, $queue, false);
    sitepulse_uptime_maybe_schedule_queue_processor($next_run);

    $settings['next_run'] = $next_run;
    update_option(SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION, $settings, false);
}

/**
 * Removes pending SLA automation jobs from the remote queue.
 *
 * @return void
 */
function sitepulse_uptime_cancel_automation_job() {
    $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);

    if (!is_array($queue) || empty($queue)) {
        return;
    }

    $filtered = [];

    foreach ($queue as $item) {
        if (!is_array($item)) {
            continue;
        }

        $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : [];

        if (isset($payload['task']) && 'uptime_sla_report' === $payload['task']) {
            continue;
        }

        $filtered[] = $item;
    }

    if (count($filtered) === count($queue)) {
        return;
    }

    $filtered = sitepulse_uptime_normalize_remote_queue($filtered);
    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, $filtered, false);
}

/**
 * Converts an automation frequency into seconds.
 *
 * @param string $frequency Frequency identifier.
 * @return int
 */
function sitepulse_uptime_get_automation_interval($frequency) {
    $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

    if ('weekly' === $frequency) {
        return 7 * $day;
    }

    return 30 * $day;
}

/**
 * Formats the report period as a human readable string.
 *
 * @param array<string,int> $period      Period metadata.
 * @param string            $date_format Optional date format.
 * @param string            $time_format Optional time format.
 * @return string
 */
function sitepulse_uptime_format_report_period($period, $date_format = null, $time_format = null) {
    $date_format = null === $date_format ? get_option('date_format', 'Y-m-d') : $date_format;
    $time_format = null === $time_format ? get_option('time_format', 'H:i') : $time_format;

    $start = isset($period['start']) ? (int) $period['start'] : 0;
    $end = isset($period['end']) ? (int) $period['end'] : 0;

    if ($start <= 0 || $end <= 0) {
        return '—';
    }

    $format = $date_format . ' ' . $time_format;
    $start_label = function_exists('wp_date') ? wp_date($format, $start) : date($format, $start);
    $end_label = function_exists('wp_date') ? wp_date($format, $end) : date($format, $end);

    return sprintf('%s → %s', $start_label, $end_label);
}

/**
 * Retrieves the persisted uptime archive ordered by day.
 *
 * @return array<string,array<string,int>>
 */
function sitepulse_get_uptime_archive() {
    $archive = get_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, []);

    if (!is_array($archive)) {
        return [];
    }

    uksort($archive, function ($a, $b) {
        return strcmp($a, $b);
    });

    return $archive;
}

/**
 * Stores the provided log entry inside the daily uptime archive.
 *
 * @param array $entry Normalized uptime entry.
 * @return void
 */
function sitepulse_update_uptime_archive($entry) {
    if (!is_array($entry) || empty($entry)) {
        return;
    }

    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : (int) current_time('timestamp');
    $day_key = wp_date('Y-m-d', $timestamp);

    $status_key = 'unknown';
    $agent = isset($entry['agent']) ? sitepulse_uptime_normalize_agent_id($entry['agent']) : 'default';

    if (array_key_exists('status', $entry)) {
        if (true === $entry['status']) {
            $status_key = 'up';
        } elseif (false === $entry['status']) {
            $status_key = 'down';
        } elseif (is_string($entry['status']) && 'maintenance' === $entry['status']) {
            $status_key = 'maintenance';
        } elseif (is_string($entry['status']) && 'unknown' === $entry['status']) {
            $status_key = 'unknown';
        }
    }

    $archive = sitepulse_get_uptime_archive();

    if (!isset($archive[$day_key]) || !is_array($archive[$day_key])) {
        $archive[$day_key] = [
            'date'            => $day_key,
            'up'              => 0,
            'down'            => 0,
            'unknown'         => 0,
            'total'           => 0,
            'maintenance'     => 0,
            'first_timestamp' => $timestamp,
            'last_timestamp'  => $timestamp,
            'latency_sum'     => 0.0,
            'latency_count'   => 0,
            'ttfb_sum'        => 0.0,
            'ttfb_count'      => 0,
            'violations'      => 0,
            'violation_types' => [],
            'agents'          => [],
        ];
    }

    foreach (['latency_sum' => 0.0, 'latency_count' => 0, 'ttfb_sum' => 0.0, 'ttfb_count' => 0, 'violations' => 0] as $metric_key => $default_value) {
        if (!isset($archive[$day_key][$metric_key])) {
            $archive[$day_key][$metric_key] = $default_value;
        }
    }

    if (!isset($archive[$day_key]['violation_types']) || !is_array($archive[$day_key]['violation_types'])) {
        $archive[$day_key]['violation_types'] = [];
    }

    if (!isset($archive[$day_key][$status_key])) {
        $archive[$day_key][$status_key] = 0;
    }

    $archive[$day_key][$status_key]++;
    $archive[$day_key]['total']++;

    $archive[$day_key]['first_timestamp'] = isset($archive[$day_key]['first_timestamp'])
        ? min((int) $archive[$day_key]['first_timestamp'], $timestamp)
        : $timestamp;
    $archive[$day_key]['last_timestamp'] = isset($archive[$day_key]['last_timestamp'])
        ? max((int) $archive[$day_key]['last_timestamp'], $timestamp)
        : $timestamp;

    if (!isset($archive[$day_key]['agents'][$agent])) {
        $archive[$day_key]['agents'][$agent] = [
            'up'              => 0,
            'down'            => 0,
            'unknown'         => 0,
            'maintenance'     => 0,
            'total'           => 0,
            'latency_sum'     => 0.0,
            'latency_count'   => 0,
            'ttfb_sum'        => 0.0,
            'ttfb_count'      => 0,
            'violations'      => 0,
            'violation_types' => [],
        ];
    }

    foreach (['latency_sum' => 0.0, 'latency_count' => 0, 'ttfb_sum' => 0.0, 'ttfb_count' => 0, 'violations' => 0] as $metric_key => $default_value) {
        if (!isset($archive[$day_key]['agents'][$agent][$metric_key])) {
            $archive[$day_key]['agents'][$agent][$metric_key] = $default_value;
        }
    }

    if (!isset($archive[$day_key]['agents'][$agent]['violation_types']) || !is_array($archive[$day_key]['agents'][$agent]['violation_types'])) {
        $archive[$day_key]['agents'][$agent]['violation_types'] = [];
    }

    if (!isset($archive[$day_key]['agents'][$agent][$status_key])) {
        $archive[$day_key]['agents'][$agent][$status_key] = 0;
    }

    $archive[$day_key]['agents'][$agent][$status_key]++;
    $archive[$day_key]['agents'][$agent]['total']++;

    $latency_value = isset($entry['latency']) ? (float) $entry['latency'] : null;

    if (null !== $latency_value && $latency_value >= 0) {
        $archive[$day_key]['latency_sum'] += $latency_value;
        $archive[$day_key]['latency_count']++;
        $archive[$day_key]['agents'][$agent]['latency_sum'] += $latency_value;
        $archive[$day_key]['agents'][$agent]['latency_count']++;
    }

    if (isset($entry['ttfb'])) {
        $ttfb_value = (float) $entry['ttfb'];

        if ($ttfb_value >= 0) {
            $archive[$day_key]['ttfb_sum'] += $ttfb_value;
            $archive[$day_key]['ttfb_count']++;
            $archive[$day_key]['agents'][$agent]['ttfb_sum'] += $ttfb_value;
            $archive[$day_key]['agents'][$agent]['ttfb_count']++;
        }
    }

    $entry_violations = [];

    if (isset($entry['violation_types']) && is_array($entry['violation_types'])) {
        $entry_violations = array_values(array_filter(array_map('sanitize_key', $entry['violation_types'])));
    }

    if (!empty($entry_violations)) {
        $archive[$day_key]['violations']++;
        $archive[$day_key]['agents'][$agent]['violations']++;

        foreach ($entry_violations as $violation_type) {
            if (!isset($archive[$day_key]['violation_types'][$violation_type])) {
                $archive[$day_key]['violation_types'][$violation_type] = 0;
            }

            $archive[$day_key]['violation_types'][$violation_type]++;

            if (!isset($archive[$day_key]['agents'][$agent]['violation_types'][$violation_type])) {
                $archive[$day_key]['agents'][$agent]['violation_types'][$violation_type] = 0;
            }

            $archive[$day_key]['agents'][$agent]['violation_types'][$violation_type]++;
        }
    }

    $max_archive_days = sitepulse_get_uptime_history_retention_days();

    if ($max_archive_days > 0 && count($archive) > $max_archive_days) {
        $archive = array_slice($archive, -$max_archive_days, null, true);
    }

    update_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, $archive, false);
}

/**
 * Calculates aggregate metrics for the requested archive window.
 *
 * @param array<string,array<string,int>> $archive Archive of daily totals.
 * @param int                             $days    Number of days to include.
 * @param array<string,array<string,mixed>>|null $agents Optional agent definitions.
 * @return array<string,int|float>
 */
function sitepulse_calculate_uptime_window_metrics($archive, $days, $agents = null) {
    if (!is_array($archive) || empty($archive) || $days < 1) {
        return [
            'days'           => 0,
            'total_checks'   => 0,
            'up_checks'      => 0,
            'down_checks'    => 0,
            'unknown_checks' => 0,
            'uptime'         => 100.0,
            'latency_sum'    => 0.0,
            'latency_count'  => 0,
            'latency_avg'    => null,
            'ttfb_sum'       => 0.0,
            'ttfb_count'     => 0,
            'ttfb_avg'       => null,
            'violations'     => 0,
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
        'latency_sum'    => 0.0,
        'latency_count'  => 0,
        'latency_avg'    => null,
        'ttfb_sum'       => 0.0,
        'ttfb_count'     => 0,
        'ttfb_avg'       => null,
        'violations'     => 0,
    ];

    foreach ($window as $entry) {
        $day_total = isset($entry['total']) ? (int) $entry['total'] : 0;
        $maintenance = isset($entry['maintenance']) ? (int) $entry['maintenance'] : 0;
        $effective_total = max(0, $day_total - $maintenance);

        $totals['total_checks'] += $effective_total;
        $totals['up_checks'] += isset($entry['up']) ? (int) $entry['up'] : 0;
        $totals['down_checks'] += isset($entry['down']) ? (int) $entry['down'] : 0;
        $totals['unknown_checks'] += isset($entry['unknown']) ? (int) $entry['unknown'] : 0;
        $totals['latency_sum'] += isset($entry['latency_sum']) ? (float) $entry['latency_sum'] : 0.0;
        $totals['latency_count'] += isset($entry['latency_count']) ? (int) $entry['latency_count'] : 0;
        $totals['ttfb_sum'] += isset($entry['ttfb_sum']) ? (float) $entry['ttfb_sum'] : 0.0;
        $totals['ttfb_count'] += isset($entry['ttfb_count']) ? (int) $entry['ttfb_count'] : 0;
        $totals['violations'] += isset($entry['violations']) ? (int) $entry['violations'] : 0;
    }

    $agents_for_weights = is_array($agents) ? $agents : sitepulse_uptime_get_agents();
    $agent_metrics = sitepulse_calculate_agent_uptime_metrics($archive, $days, $agents_for_weights);

    $weighted_total = 0.0;
    $weighted_up = 0.0;
    $weighted_down = 0.0;
    $weighted_unknown = 0.0;
    $weighted_latency_sum = 0.0;
    $weighted_latency_count = 0.0;
    $weighted_ttfb_sum = 0.0;
    $weighted_ttfb_count = 0.0;

    foreach ($agent_metrics as $agent_id => $agent_counts) {
        $weight = sitepulse_uptime_get_agent_weight($agent_id, isset($agents_for_weights[$agent_id]) ? $agents_for_weights[$agent_id] : null);

        if ($weight <= 0) {
            continue;
        }

        $weighted_total += (isset($agent_counts['effective_total']) ? (int) $agent_counts['effective_total'] : 0) * $weight;
        $weighted_up += (isset($agent_counts['up']) ? (int) $agent_counts['up'] : 0) * $weight;
        $weighted_down += (isset($agent_counts['down']) ? (int) $agent_counts['down'] : 0) * $weight;
        $weighted_unknown += (isset($agent_counts['unknown']) ? (int) $agent_counts['unknown'] : 0) * $weight;
        $weighted_latency_sum += (isset($agent_counts['latency_sum']) ? (float) $agent_counts['latency_sum'] : 0.0) * $weight;
        $weighted_latency_count += (isset($agent_counts['latency_count']) ? (int) $agent_counts['latency_count'] : 0) * $weight;
        $weighted_ttfb_sum += (isset($agent_counts['ttfb_sum']) ? (float) $agent_counts['ttfb_sum'] : 0.0) * $weight;
        $weighted_ttfb_count += (isset($agent_counts['ttfb_count']) ? (int) $agent_counts['ttfb_count'] : 0) * $weight;
    }

    if ($weighted_total > 0) {
        $totals['uptime'] = ($weighted_up / $weighted_total) * 100;
    } elseif ($totals['total_checks'] > 0) {
        $totals['uptime'] = ($totals['up_checks'] / $totals['total_checks']) * 100;
    }

    if ($weighted_latency_count > 0) {
        $totals['latency_avg'] = $weighted_latency_sum / $weighted_latency_count;
    } elseif ($totals['latency_count'] > 0) {
        $totals['latency_avg'] = $totals['latency_sum'] / $totals['latency_count'];
    }

    if ($weighted_ttfb_count > 0) {
        $totals['ttfb_avg'] = $weighted_ttfb_sum / $weighted_ttfb_count;
    } elseif ($totals['ttfb_count'] > 0) {
        $totals['ttfb_avg'] = $totals['ttfb_sum'] / $totals['ttfb_count'];
    }

    return $totals;
}

/**
 * Aggregates uptime metrics per agent for the provided window.
 *
 * @param array<string,array<string,mixed>> $archive Archive entries.
 * @param int                               $days    Window size.
 * @param array<string,array<string,mixed>>|null $agents Optional agent definitions to filter inactive entries.
 * @return array<string,array<string,mixed>>
 */
function sitepulse_calculate_agent_uptime_metrics($archive, $days, $agents = null) {
    if (!is_array($archive) || empty($archive) || $days < 1) {
        return [];
    }

    $window = array_slice($archive, -$days, null, true);
    $totals = [];
    $active_map = null;

    if (is_array($agents)) {
        $active_map = [];

        foreach ($agents as $agent_id => $agent_config) {
            $active_map[$agent_id] = sitepulse_uptime_agent_is_active($agent_id, $agent_config);
        }
    }

    foreach ($window as $entry) {
        $agents = isset($entry['agents']) && is_array($entry['agents']) ? $entry['agents'] : [];

        if (empty($agents)) {
            $agents = [
                'default' => [
                    'up'          => isset($entry['up']) ? (int) $entry['up'] : 0,
                    'down'        => isset($entry['down']) ? (int) $entry['down'] : 0,
                    'unknown'     => isset($entry['unknown']) ? (int) $entry['unknown'] : 0,
                    'maintenance' => isset($entry['maintenance']) ? (int) $entry['maintenance'] : 0,
                    'total'       => isset($entry['total']) ? (int) $entry['total'] : 0,
                    'latency_sum'     => isset($entry['latency_sum']) ? (float) $entry['latency_sum'] : 0.0,
                    'latency_count'   => isset($entry['latency_count']) ? (int) $entry['latency_count'] : 0,
                    'ttfb_sum'        => isset($entry['ttfb_sum']) ? (float) $entry['ttfb_sum'] : 0.0,
                    'ttfb_count'      => isset($entry['ttfb_count']) ? (int) $entry['ttfb_count'] : 0,
                    'violations'      => isset($entry['violations']) ? (int) $entry['violations'] : 0,
                    'violation_types' => isset($entry['violation_types']) && is_array($entry['violation_types'])
                        ? $entry['violation_types']
                        : [],
                ],
            ];
        }

        foreach ($agents as $agent_id => $agent_totals) {
            if (!isset($totals[$agent_id])) {
                $totals[$agent_id] = [
                    'up'          => 0,
                    'down'        => 0,
                    'unknown'     => 0,
                    'maintenance' => 0,
                    'total'       => 0,
                    'latency_sum'     => 0.0,
                    'latency_count'   => 0,
                    'ttfb_sum'        => 0.0,
                    'ttfb_count'      => 0,
                    'violations'      => 0,
                    'violation_types' => [],
                ];
            }

            $totals[$agent_id]['up'] += isset($agent_totals['up']) ? (int) $agent_totals['up'] : 0;
            $totals[$agent_id]['down'] += isset($agent_totals['down']) ? (int) $agent_totals['down'] : 0;
            $totals[$agent_id]['unknown'] += isset($agent_totals['unknown']) ? (int) $agent_totals['unknown'] : 0;
            $totals[$agent_id]['maintenance'] += isset($agent_totals['maintenance']) ? (int) $agent_totals['maintenance'] : 0;
            $totals[$agent_id]['total'] += isset($agent_totals['total']) ? (int) $agent_totals['total'] : 0;
            $totals[$agent_id]['latency_sum'] += isset($agent_totals['latency_sum']) ? (float) $agent_totals['latency_sum'] : 0.0;
            $totals[$agent_id]['latency_count'] += isset($agent_totals['latency_count']) ? (int) $agent_totals['latency_count'] : 0;
            $totals[$agent_id]['ttfb_sum'] += isset($agent_totals['ttfb_sum']) ? (float) $agent_totals['ttfb_sum'] : 0.0;
            $totals[$agent_id]['ttfb_count'] += isset($agent_totals['ttfb_count']) ? (int) $agent_totals['ttfb_count'] : 0;
            $totals[$agent_id]['violations'] += isset($agent_totals['violations']) ? (int) $agent_totals['violations'] : 0;

            if (isset($agent_totals['violation_types']) && is_array($agent_totals['violation_types'])) {
                foreach ($agent_totals['violation_types'] as $type => $count) {
                    $type_key = sanitize_key($type);

                    if ($type_key === '') {
                        continue;
                    }

                    if (!isset($totals[$agent_id]['violation_types'][$type_key])) {
                        $totals[$agent_id]['violation_types'][$type_key] = 0;
                    }

                    $totals[$agent_id]['violation_types'][$type_key] += (int) $count;
                }
            }
        }
    }

    foreach ($totals as $agent_id => $counts) {
        if (is_array($active_map) && array_key_exists($agent_id, $active_map) && !$active_map[$agent_id]) {
            unset($totals[$agent_id]);
            continue;
        }

        $effective_total = max(0, (int) $counts['total'] - (int) $counts['maintenance']);
        $uptime = $effective_total > 0 ? ($counts['up'] / $effective_total) * 100 : 100;
        $totals[$agent_id]['uptime'] = max(0, min(100, $uptime));
        $totals[$agent_id]['effective_total'] = $effective_total;

        $latency_count = isset($counts['latency_count']) ? (int) $counts['latency_count'] : 0;
        $latency_sum = isset($counts['latency_sum']) ? (float) $counts['latency_sum'] : 0.0;
        $ttfb_count = isset($counts['ttfb_count']) ? (int) $counts['ttfb_count'] : 0;
        $ttfb_sum = isset($counts['ttfb_sum']) ? (float) $counts['ttfb_sum'] : 0.0;

        $totals[$agent_id]['latency_avg'] = $latency_count > 0 ? $latency_sum / $latency_count : null;
        $totals[$agent_id]['ttfb_avg'] = $ttfb_count > 0 ? $ttfb_sum / $ttfb_count : null;
    }

    return $totals;
}

/**
 * Returns the list of archive months available for reporting.
 *
 * @param array<string,array<string,mixed>> $archive Archive entries keyed by Y-m-d.
 * @return array<string,array<string,int|string>>
 */
function sitepulse_uptime_get_archive_months($archive) {
    if (!is_array($archive) || empty($archive)) {
        return [];
    }

    $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $months = [];

    foreach ($archive as $day_key => $entry) {
        if (!is_string($day_key) || $day_key === '') {
            continue;
        }

        $day_date = DateTimeImmutable::createFromFormat('Y-m-d', $day_key, $timezone);

        if (!$day_date) {
            continue;
        }

        $month_key = $day_date->format('Y-m');

        if (!isset($months[$month_key])) {
            $month_start = $day_date->setDate((int) $day_date->format('Y'), (int) $day_date->format('m'), 1)->setTime(0, 0, 0);
            $month_end = $month_start->modify('last day of this month')->setTime(23, 59, 59);
            $label_timestamp = $month_start->getTimestamp();
            $label = function_exists('wp_date') ? wp_date('F Y', $label_timestamp) : $month_start->format('F Y');

            $months[$month_key] = [
                'label' => $label,
                'start' => $month_start->getTimestamp(),
                'end'   => $month_end->getTimestamp(),
                'days'  => 0,
            ];
        }

        $months[$month_key]['days']++;
    }

    if (!empty($months)) {
        krsort($months, SORT_STRING);
    }

    return $months;
}

/**
 * Aggregates uptime metrics for the provided timestamp range.
 *
 * @param array<string,array<string,mixed>> $archive Archive entries keyed by day.
 * @param int                               $start   Start timestamp (inclusive).
 * @param int                               $end     End timestamp (inclusive).
 * @return array<string,mixed>
 */
function sitepulse_uptime_collect_metrics_for_period($archive, $start, $end) {
    if (!is_array($archive) || empty($archive) || $end < $start) {
        return [
            'agents' => [],
            'global' => [
                'days'               => 0,
                'total_checks'       => 0,
                'up_checks'          => 0,
                'down_checks'        => 0,
                'unknown_checks'     => 0,
                'maintenance_checks' => 0,
                'latency_sum'        => 0.0,
                'latency_count'      => 0,
                'ttfb_sum'           => 0.0,
                'ttfb_count'         => 0,
                'violations'         => 0,
            ],
        ];
    }

    $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $agents_totals = [];
    $global = [
        'days'               => 0,
        'total_checks'       => 0,
        'up_checks'          => 0,
        'down_checks'        => 0,
        'unknown_checks'     => 0,
        'maintenance_checks' => 0,
        'latency_sum'        => 0.0,
        'latency_count'      => 0,
        'ttfb_sum'           => 0.0,
        'ttfb_count'         => 0,
        'violations'         => 0,
    ];

    foreach ($archive as $day_key => $entry) {
        if (!is_string($day_key) || $day_key === '') {
            continue;
        }

        $day_date = DateTimeImmutable::createFromFormat('Y-m-d', $day_key, $timezone);

        if (!$day_date) {
            continue;
        }

        $day_timestamp = $day_date->getTimestamp();

        if ($day_timestamp < $start || $day_timestamp > $end) {
            continue;
        }

        $day_total = isset($entry['total']) ? (int) $entry['total'] : 0;
        $maintenance = isset($entry['maintenance']) ? (int) $entry['maintenance'] : 0;
        $effective_total = max(0, $day_total - $maintenance);

        $global['days']++;
        $global['total_checks'] += $effective_total;
        $global['up_checks'] += isset($entry['up']) ? (int) $entry['up'] : 0;
        $global['down_checks'] += isset($entry['down']) ? (int) $entry['down'] : 0;
        $global['unknown_checks'] += isset($entry['unknown']) ? (int) $entry['unknown'] : 0;
        $global['maintenance_checks'] += $maintenance;
        $global['latency_sum'] += isset($entry['latency_sum']) ? (float) $entry['latency_sum'] : 0.0;
        $global['latency_count'] += isset($entry['latency_count']) ? (int) $entry['latency_count'] : 0;
        $global['ttfb_sum'] += isset($entry['ttfb_sum']) ? (float) $entry['ttfb_sum'] : 0.0;
        $global['ttfb_count'] += isset($entry['ttfb_count']) ? (int) $entry['ttfb_count'] : 0;
        $global['violations'] += isset($entry['violations']) ? (int) $entry['violations'] : 0;

        $agents = isset($entry['agents']) && is_array($entry['agents']) ? $entry['agents'] : [];

        if (empty($agents)) {
            $agents = [
                'default' => [
                    'up'              => isset($entry['up']) ? (int) $entry['up'] : 0,
                    'down'            => isset($entry['down']) ? (int) $entry['down'] : 0,
                    'unknown'         => isset($entry['unknown']) ? (int) $entry['unknown'] : 0,
                    'maintenance'     => $maintenance,
                    'total'           => $day_total,
                    'latency_sum'     => isset($entry['latency_sum']) ? (float) $entry['latency_sum'] : 0.0,
                    'latency_count'   => isset($entry['latency_count']) ? (int) $entry['latency_count'] : 0,
                    'ttfb_sum'        => isset($entry['ttfb_sum']) ? (float) $entry['ttfb_sum'] : 0.0,
                    'ttfb_count'      => isset($entry['ttfb_count']) ? (int) $entry['ttfb_count'] : 0,
                    'violations'      => isset($entry['violations']) ? (int) $entry['violations'] : 0,
                    'violation_types' => isset($entry['violation_types']) && is_array($entry['violation_types'])
                        ? $entry['violation_types']
                        : [],
                ],
            ];
        }

        foreach ($agents as $agent_id => $agent_totals) {
            $normalized_id = sitepulse_uptime_normalize_agent_id($agent_id);

            if (!isset($agents_totals[$normalized_id])) {
                $agents_totals[$normalized_id] = [
                    'up'              => 0,
                    'down'            => 0,
                    'unknown'         => 0,
                    'maintenance'     => 0,
                    'total'           => 0,
                    'latency_sum'     => 0.0,
                    'latency_count'   => 0,
                    'ttfb_sum'        => 0.0,
                    'ttfb_count'      => 0,
                    'violations'      => 0,
                    'violation_types' => [],
                ];
            }

            $agents_totals[$normalized_id]['up'] += isset($agent_totals['up']) ? (int) $agent_totals['up'] : 0;
            $agents_totals[$normalized_id]['down'] += isset($agent_totals['down']) ? (int) $agent_totals['down'] : 0;
            $agents_totals[$normalized_id]['unknown'] += isset($agent_totals['unknown']) ? (int) $agent_totals['unknown'] : 0;
            $agents_totals[$normalized_id]['maintenance'] += isset($agent_totals['maintenance']) ? (int) $agent_totals['maintenance'] : 0;
            $agents_totals[$normalized_id]['total'] += isset($agent_totals['total']) ? (int) $agent_totals['total'] : 0;
            $agents_totals[$normalized_id]['latency_sum'] += isset($agent_totals['latency_sum']) ? (float) $agent_totals['latency_sum'] : 0.0;
            $agents_totals[$normalized_id]['latency_count'] += isset($agent_totals['latency_count']) ? (int) $agent_totals['latency_count'] : 0;
            $agents_totals[$normalized_id]['ttfb_sum'] += isset($agent_totals['ttfb_sum']) ? (float) $agent_totals['ttfb_sum'] : 0.0;
            $agents_totals[$normalized_id]['ttfb_count'] += isset($agent_totals['ttfb_count']) ? (int) $agent_totals['ttfb_count'] : 0;
            $agents_totals[$normalized_id]['violations'] += isset($agent_totals['violations']) ? (int) $agent_totals['violations'] : 0;

            if (isset($agent_totals['violation_types']) && is_array($agent_totals['violation_types'])) {
                foreach ($agent_totals['violation_types'] as $type => $count) {
                    $type_key = sanitize_key($type);

                    if ($type_key === '') {
                        continue;
                    }

                    if (!isset($agents_totals[$normalized_id]['violation_types'][$type_key])) {
                        $agents_totals[$normalized_id]['violation_types'][$type_key] = 0;
                    }

                    $agents_totals[$normalized_id]['violation_types'][$type_key] += (int) $count;
                }
            }
        }
    }

    return [
        'agents' => $agents_totals,
        'global' => $global,
    ];
}

/**
 * Handles the SLA CSV export request.
 *
 * @return void
 */
function sitepulse_uptime_handle_sla_export() {
    if (!current_user_can(function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options')) {
        wp_die(__('Vous n’avez pas l’autorisation d’exporter ce rapport.', 'sitepulse'));
    }

    check_admin_referer('sitepulse_export_sla');

    $month_raw = isset($_POST['sitepulse_sla_month']) ? wp_unslash($_POST['sitepulse_sla_month']) : '';
    $month = is_string($month_raw) ? sanitize_text_field($month_raw) : '';

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        sitepulse_uptime_redirect_with_notice('invalid-month');
    }

    $archive = sitepulse_get_uptime_archive();
    $months = sitepulse_uptime_get_archive_months($archive);

    if (!isset($months[$month])) {
        sitepulse_uptime_redirect_with_notice('missing-data', $month);
    }

    $selected_month = $months[$month];
    $metrics = sitepulse_uptime_collect_metrics_for_period($archive, (int) $selected_month['start'], (int) $selected_month['end']);

    if (empty($metrics['agents'])) {
        sitepulse_uptime_redirect_with_notice('empty-period', $month);
    }

    $agents = sitepulse_uptime_get_agents();
    $filename = sprintf('sitepulse-sla-%s.csv', $month);

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');

    if (false === $output) {
        wp_die(__('Impossible de générer le flux CSV.', 'sitepulse'));
    }

    fwrite($output, "\xEF\xBB\xBF");

    $report_period_label = isset($selected_month['label']) ? $selected_month['label'] : $month;
    $generated_label = function_exists('wp_date') ? wp_date('Y-m-d H:i', current_time('timestamp')) : date('Y-m-d H:i');

    fputcsv($output, sitepulse_uptime_escape_csv_row(['SitePulse SLA Report', $report_period_label]));
    fputcsv($output, sitepulse_uptime_escape_csv_row([__('Généré le', 'sitepulse'), $generated_label]));
    fputcsv($output, sitepulse_uptime_escape_csv_row([]));

    $impact_rows = [];

    if (function_exists('sitepulse_custom_dashboard_format_impact_export_rows')) {
        $impact_snapshot = function_exists('sitepulse_custom_dashboard_get_cached_impact_index')
            ? sitepulse_custom_dashboard_get_cached_impact_index('30d', DAY_IN_SECONDS)
            : null;

        $range_definitions = sitepulse_custom_dashboard_get_metric_ranges();
        $range_label = sitepulse_custom_dashboard_resolve_range_label(
            '30d',
            array_values($range_definitions)
        );

        if (null === $impact_snapshot) {
            $dashboard_payload = sitepulse_custom_dashboard_prepare_metrics_payload('30d');

            if (isset($dashboard_payload['impact']) && is_array($dashboard_payload['impact'])) {
                $impact_snapshot = $dashboard_payload['impact'];
            }

            if (isset($dashboard_payload['available_ranges']) && is_array($dashboard_payload['available_ranges'])) {
                $range_label = sitepulse_custom_dashboard_resolve_range_label(
                    isset($impact_snapshot['range']) ? $impact_snapshot['range'] : '30d',
                    $dashboard_payload['available_ranges']
                );
            }
        } elseif (is_array($impact_snapshot)) {
            $range_label = sitepulse_custom_dashboard_resolve_range_label(
                isset($impact_snapshot['range']) ? $impact_snapshot['range'] : '30d',
                array_values($range_definitions)
            );
        }

        if (is_array($impact_snapshot)) {
            $impact_rows = sitepulse_custom_dashboard_format_impact_export_rows($impact_snapshot, $range_label);
        }
    }

    if (!empty($impact_rows)) {
        foreach ($impact_rows as $impact_row) {
            fputcsv($output, sitepulse_uptime_escape_csv_row($impact_row));
        }

        fputcsv($output, sitepulse_uptime_escape_csv_row([]));
    }

    $header = [
        __('Agent', 'sitepulse'),
        __('Région', 'sitepulse'),
        __('Poids', 'sitepulse'),
        __('Disponibilité (%)', 'sitepulse'),
        __('Contrôles évalués', 'sitepulse'),
        __('Incidents détectés', 'sitepulse'),
        __('Fenêtres de maintenance (contrôles)', 'sitepulse'),
        __('TTFB moyen (ms)', 'sitepulse'),
        __('Latence moyenne (ms)', 'sitepulse'),
        __('Violations', 'sitepulse'),
    ];
    fputcsv($output, sitepulse_uptime_escape_csv_row($header));

    foreach ($metrics['agents'] as $agent_id => $agent_totals) {
        $agent = isset($agents[$agent_id]) ? $agents[$agent_id] : sitepulse_uptime_get_agent($agent_id);

        if (!sitepulse_uptime_agent_is_active($agent_id, $agent)) {
            continue;
        }

        $agent_weight = sitepulse_uptime_get_agent_weight($agent_id, $agent);
        $total_checks = isset($agent_totals['total']) ? (int) $agent_totals['total'] : 0;
        $maintenance_checks = isset($agent_totals['maintenance']) ? (int) $agent_totals['maintenance'] : 0;
        $effective_total = max(0, $total_checks - $maintenance_checks);
        $up_checks = isset($agent_totals['up']) ? (int) $agent_totals['up'] : 0;
        $down_checks = isset($agent_totals['down']) ? (int) $agent_totals['down'] : 0;
        $latency_sum = isset($agent_totals['latency_sum']) ? (float) $agent_totals['latency_sum'] : 0.0;
        $latency_count = isset($agent_totals['latency_count']) ? (int) $agent_totals['latency_count'] : 0;
        $ttfb_sum = isset($agent_totals['ttfb_sum']) ? (float) $agent_totals['ttfb_sum'] : 0.0;
        $ttfb_count = isset($agent_totals['ttfb_count']) ? (int) $agent_totals['ttfb_count'] : 0;
        $violations = isset($agent_totals['violations']) ? (int) $agent_totals['violations'] : 0;

        $uptime = $effective_total > 0 ? ($up_checks / $effective_total) * 100 : 100.0;
        $latency_avg_ms = $latency_count > 0 ? ($latency_sum / $latency_count) * 1000 : null;
        $ttfb_avg_ms = $ttfb_count > 0 ? ($ttfb_sum / $ttfb_count) * 1000 : null;

        fputcsv($output, sitepulse_uptime_escape_csv_row([
            isset($agent['label']) ? $agent['label'] : ucfirst(str_replace('_', ' ', $agent_id)),
            isset($agent['region']) ? $agent['region'] : 'global',
            number_format((float) $agent_weight, 2, '.', ''),
            number_format((float) $uptime, 3, '.', ''),
            $effective_total,
            $down_checks,
            $maintenance_checks,
            null === $ttfb_avg_ms ? '' : number_format((float) $ttfb_avg_ms, 1, '.', ''),
            null === $latency_avg_ms ? '' : number_format((float) $latency_avg_ms, 1, '.', ''),
            $violations,
        ]));
    }

    fclose($output);
    exit;
}

/**
 * Handles manual SLA report generation from the admin interface.
 *
 * @return void
 */
function sitepulse_uptime_handle_manual_report_generation() {
    if (!current_user_can(function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options')) {
        wp_die(__('Vous n’avez pas l’autorisation de générer ce rapport.', 'sitepulse'));
    }

    check_admin_referer('sitepulse_generate_uptime_report');

    $windows = isset($_POST['sitepulse_uptime_windows']) ? wp_unslash($_POST['sitepulse_uptime_windows']) : [7, 30];

    if (!is_array($windows)) {
        $windows = [$windows];
    }

    $windows = array_values(array_filter(array_map('intval', $windows), function ($value) {
        return $value > 0;
    }));

    if (empty($windows)) {
        $windows = [7, 30];
    }

    $result = sitepulse_uptime_generate_sla_report('manual', $windows);

    if (is_wp_error($result)) {
        $redirect = add_query_arg([
            'page'                        => 'sitepulse-uptime',
            'sitepulse_sla_report_status' => $result->get_error_code(),
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    $redirect = add_query_arg([
        'page'                        => 'sitepulse-uptime',
        'sitepulse_sla_report_status' => 'success',
        'sitepulse_sla_report_id'     => $result['id'],
    ], admin_url('admin.php'));

    wp_safe_redirect($redirect);
    exit;
}

/**
 * Saves the automation preferences for SLA reports.
 *
 * @return void
 */
function sitepulse_uptime_handle_sla_settings_save() {
    if (!current_user_can(function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options')) {
        wp_die(__('Vous n’avez pas l’autorisation de modifier ces réglages.', 'sitepulse'));
    }

    check_admin_referer('sitepulse_save_sla_settings');

    $settings = sitepulse_uptime_get_sla_automation_settings();

    $settings['enabled'] = isset($_POST['sitepulse_sla_enabled']);
    $settings['frequency'] = isset($_POST['sitepulse_sla_frequency'])
        ? sanitize_key(wp_unslash($_POST['sitepulse_sla_frequency']))
        : 'monthly';
    $settings['frequency'] = in_array($settings['frequency'], ['weekly', 'monthly'], true) ? $settings['frequency'] : 'monthly';

    $windows_input = isset($_POST['sitepulse_sla_windows']) ? wp_unslash($_POST['sitepulse_sla_windows']) : $settings['windows'];

    if (!is_array($windows_input)) {
        $windows_input = [$windows_input];
    }

    $parsed_windows = array_values(array_filter(array_map('intval', $windows_input), function ($value) {
        return $value > 0;
    }));

    if (!empty($parsed_windows)) {
        $settings['windows'] = $parsed_windows;
    }

    $recipients = [];

    if (isset($_POST['sitepulse_sla_recipients'])) {
        $recipients_raw = wp_unslash($_POST['sitepulse_sla_recipients']);
        $recipients_split = preg_split('/[\n,]+/', $recipients_raw);

        if (is_array($recipients_split)) {
            $recipients = array_values(array_filter(array_map('sanitize_email', $recipients_split)));
        }
    }

    $settings['email_enabled'] = isset($_POST['sitepulse_sla_email_enabled']) && !empty($recipients);
    $settings['recipients'] = $settings['email_enabled'] ? $recipients : [];

    $settings['webhook_enabled'] = isset($_POST['sitepulse_sla_webhook_enabled']);
    $settings['webhook_url'] = '';

    if ($settings['webhook_enabled'] && isset($_POST['sitepulse_sla_webhook_url'])) {
        $candidate = esc_url_raw(wp_unslash($_POST['sitepulse_sla_webhook_url']));

        if (wp_http_validate_url($candidate)) {
            $settings['webhook_url'] = $candidate;
        } else {
            $settings['webhook_enabled'] = false;
        }
    }

    if (!$settings['enabled']) {
        $settings['next_run'] = 0;
        update_option(SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION, $settings, false);
        sitepulse_uptime_cancel_automation_job();
    } else {
        update_option(SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION, $settings, false);
        sitepulse_uptime_schedule_automation_job($settings, true);
    }

    $redirect = add_query_arg([
        'page'                   => 'sitepulse-uptime',
        'sitepulse_sla_settings' => 'updated',
    ], admin_url('admin.php'));

    wp_safe_redirect($redirect);
    exit;
}

/**
 * Redirects back to the uptime page with a contextual notice.
 *
 * @param string $code  Error code identifier.
 * @param string $month Month identifier.
 * @return void
 */
function sitepulse_uptime_redirect_with_notice($code, $month = '') {
    $args = [
        'page'                => 'sitepulse-uptime',
        'sitepulse_sla_error' => $code,
    ];

    if ($month !== '') {
        $args['sitepulse_sla_month'] = $month;
    }

    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}
