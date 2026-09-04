<?php
/**
 * SitePulse Resource Monitor history export.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalises numerical values before exporting them.
 *
 * @param mixed $value Raw value.
 * @param int   $decimals Number of decimals to keep.
 * @return string
 */
function sitepulse_resource_monitor_format_export_number($value, $decimals = 2) {
    if (!is_numeric($value)) {
        return '';
    }

    return number_format((float) $value, $decimals, '.', '');
}

/**
 * Prepares normalized history rows for export.
 *
 * @param array<int, array> $history_entries History entries.
 * @return array<int, array<string, mixed>>
 */
function sitepulse_resource_monitor_prepare_export_rows(array $history_entries) {
    $rows = [];

    foreach ($history_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $load = isset($entry['load']) && is_array($entry['load']) ? array_values($entry['load']) : [];
        $cpu_percent = sitepulse_resource_monitor_calculate_cpu_usage_percent($entry);
        $memory_percent = sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null);
        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage($entry['disk']['free'] ?? null, $entry['disk']['total'] ?? null);
        $disk_percent_used = $disk_percent_free !== null ? max(0, min(100, 100 - $disk_percent_free)) : null;

        $rows[] = [
            'timestamp'           => $timestamp,
            'datetime_utc'        => $timestamp > 0 ? gmdate('c', $timestamp) : '',
            'source'              => isset($entry['source']) ? (string) $entry['source'] : 'manual',
            'load_1'              => isset($load[0]) && is_numeric($load[0]) ? (float) $load[0] : null,
            'load_5'              => isset($load[1]) && is_numeric($load[1]) ? (float) $load[1] : null,
            'load_15'             => isset($load[2]) && is_numeric($load[2]) ? (float) $load[2] : null,
            'cpu_percent'         => $cpu_percent,
            'memory_usage_bytes'  => isset($entry['memory']['usage']) && is_numeric($entry['memory']['usage']) ? (int) $entry['memory']['usage'] : null,
            'memory_limit_bytes'  => isset($entry['memory']['limit']) && is_numeric($entry['memory']['limit']) ? (int) $entry['memory']['limit'] : null,
            'memory_percent'      => $memory_percent,
            'disk_free_bytes'     => isset($entry['disk']['free']) && is_numeric($entry['disk']['free']) ? (int) $entry['disk']['free'] : null,
            'disk_total_bytes'    => isset($entry['disk']['total']) && is_numeric($entry['disk']['total']) ? (int) $entry['disk']['total'] : null,
            'disk_free_percent'   => $disk_percent_free,
            'disk_used_percent'   => $disk_percent_used,
        ];
    }

    return $rows;
}

/**
 * Handles resource history export requests from the admin UI.
 *
 * @return void
 */
function sitepulse_resource_monitor_handle_export() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__('Vous n’avez pas l’autorisation de télécharger cet export.', 'sitepulse'), esc_html__('Accès refusé', 'sitepulse'), ['response' => 403]);
    }

    check_admin_referer(SITEPULSE_NONCE_ACTION_RESOURCE_MONITOR_EXPORT);

    $format = isset($_REQUEST['format']) ? sanitize_key(wp_unslash($_REQUEST['format'])) : 'csv';

    $max_rows = sitepulse_resource_monitor_get_export_max_rows();

    $history_result = sitepulse_resource_monitor_get_history([
        'per_page' => $max_rows > 0 ? $max_rows : 0,
        'page'     => 1,
        'order'    => 'ASC',
    ]);

    $history_entries = isset($history_result['entries']) && is_array($history_result['entries'])
        ? $history_result['entries']
        : [];

    if ($max_rows > 0 && count($history_entries) > $max_rows) {
        $history_entries = array_slice($history_entries, 0, $max_rows);
    }

    $rows = sitepulse_resource_monitor_prepare_export_rows($history_entries);

    if (!function_exists('nocache_headers')) {
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    } else {
        nocache_headers();
    }

    $filename_base = 'sitepulse-resource-monitor-' . gmdate('Y-m-d-H-i-s');

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename_base . '.json');
        echo wp_json_encode($rows);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename_base . '.csv');

    $output = fopen('php://output', 'w');

    if ($output === false) {
        wp_die(esc_html__('Impossible de générer le fichier CSV.', 'sitepulse'));
    }

    $headers = [
        'timestamp',
        'datetime_utc',
        'source',
        'load_1',
        'load_5',
        'load_15',
        'cpu_percent',
        'memory_usage_bytes',
        'memory_limit_bytes',
        'memory_percent',
        'disk_free_bytes',
        'disk_total_bytes',
        'disk_free_percent',
        'disk_used_percent',
    ];

    fputcsv($output, $headers);

    foreach ($rows as $row) {
        $csv_row = [
            $row['timestamp'],
            $row['datetime_utc'],
            $row['source'],
            sitepulse_resource_monitor_format_export_number($row['load_1']),
            sitepulse_resource_monitor_format_export_number($row['load_5']),
            sitepulse_resource_monitor_format_export_number($row['load_15']),
            sitepulse_resource_monitor_format_export_number($row['cpu_percent']),
            isset($row['memory_usage_bytes']) ? $row['memory_usage_bytes'] : '',
            isset($row['memory_limit_bytes']) ? $row['memory_limit_bytes'] : '',
            sitepulse_resource_monitor_format_export_number($row['memory_percent']),
            isset($row['disk_free_bytes']) ? $row['disk_free_bytes'] : '',
            isset($row['disk_total_bytes']) ? $row['disk_total_bytes'] : '',
            sitepulse_resource_monitor_format_export_number($row['disk_free_percent']),
            sitepulse_resource_monitor_format_export_number($row['disk_used_percent']),
        ];

        fputcsv($output, $csv_row);
    }

    fclose($output);
    exit;
}
