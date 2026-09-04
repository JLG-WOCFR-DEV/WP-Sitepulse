<?php
/**
 * SitePulse Resource Monitor history storage.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
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

    sitepulse_resource_monitor_maybe_upgrade_schema();

    $lock_token = sitepulse_resource_monitor_acquire_history_lock();

    if ($lock_token === false) {
        return;
    }

    try {
        if (sitepulse_resource_monitor_table_exists()) {
            sitepulse_resource_monitor_insert_history_entry($entry);
        } else {
            $option_name = SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY;
            $history = get_option($option_name, []);

            if (!is_array($history)) {
                $history = [];
            }

            $history[] = $entry;
            $history = sitepulse_resource_monitor_normalize_history($history);

            update_option($option_name, $history, false);
        }

        sitepulse_resource_monitor_invalidate_analytics_cache();
    } finally {
        sitepulse_resource_monitor_release_history_lock($lock_token);
    }
}

/**
 * Attempts to acquire a short-lived lock around the resource history option.
 *
 * @param int $timeout_seconds Maximum number of seconds to wait for the lock.
 * @return string|false Lock token on success, false otherwise.
 */
function sitepulse_resource_monitor_acquire_history_lock($timeout_seconds = 5) {
    if (!function_exists('add_option') || !function_exists('get_option') || !function_exists('delete_option')) {
        return false;
    }

    $lock_key = SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY_LOCK;
    $timeout_seconds = max(1, (int) $timeout_seconds);
    $wait_microseconds = (int) apply_filters('sitepulse_resource_monitor_lock_wait_interval', 200000);
    $expiry_seconds = (int) apply_filters('sitepulse_resource_monitor_lock_expiry', 10);
    $start = microtime(true);

    do {
        if (add_option($lock_key, time(), '', 'no')) {
            return $lock_key;
        }

        $lock_timestamp = get_option($lock_key);

        if (is_numeric($lock_timestamp) && (time() - (int) $lock_timestamp) > $expiry_seconds) {
            delete_option($lock_key);
            continue;
        }

        if ($wait_microseconds > 0) {
            usleep($wait_microseconds);
        }
    } while ((microtime(true) - $start) < $timeout_seconds);

    return false;
}

/**
 * Releases the lock obtained for the resource history option.
 *
 * @param string|false $lock_token Lock token to release.
 * @return void
 */
function sitepulse_resource_monitor_release_history_lock($lock_token) {
    if (!is_string($lock_token) || $lock_token === '' || !function_exists('delete_option')) {
        return;
    }

    delete_option($lock_token);
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

    $source = isset($snapshot['source']) ? (string) $snapshot['source'] : 'manual';

    if (function_exists('sanitize_key')) {
        $source = sanitize_key($source);
    } else {
        $source = strtolower(preg_replace('/[^a-z0-9_\-]/', '', $source));
    }

    if ($source === '') {
        $source = 'manual';
    }

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
        'source'    => $source,
    ];
}

/**
 * Normalizes history entries and applies TTL / max length constraints.
 *
 * @param array $history Raw history entries.
 * @return array<int, array>
 */
function sitepulse_resource_monitor_normalize_history(array $history) {
    $sanitized = [];

    foreach ($history as $entry) {
        $normalized = sitepulse_resource_monitor_normalize_single_history_entry($entry);

        if ($normalized === null) {
            continue;
        }

        $timestamp = $normalized['timestamp'];

        $sanitized[$timestamp] = $normalized;
    }

    if (empty($sanitized)) {
        return [];
    }

    ksort($sanitized, SORT_NUMERIC);

    return array_values($sanitized);
}

/**
 * Normalizes a raw history entry regardless of its source.
 *
 * @param mixed $entry Raw entry structure.
 * @return array|null
 */
function sitepulse_resource_monitor_normalize_single_history_entry($entry) {
    if (!is_array($entry)) {
        return null;
    }

    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

    if ($timestamp <= 0 && isset($entry['recorded_at'])) {
        $timestamp = (int) $entry['recorded_at'];
    }

    if ($timestamp <= 0) {
        return null;
    }

    $load = [null, null, null];

    if (isset($entry['load']) && is_array($entry['load'])) {
        foreach (array_slice(array_values($entry['load']), 0, 3) as $index => $value) {
            $load[$index] = is_numeric($value) ? (float) $value : null;
        }
    } else {
        if (isset($entry['load_1']) && is_numeric($entry['load_1'])) {
            $load[0] = (float) $entry['load_1'];
        }

        if (isset($entry['load_5']) && is_numeric($entry['load_5'])) {
            $load[1] = (float) $entry['load_5'];
        }

        if (isset($entry['load_15']) && is_numeric($entry['load_15'])) {
            $load[2] = (float) $entry['load_15'];
        }
    }

    $memory_usage = null;
    $memory_limit = null;

    if (isset($entry['memory']) && is_array($entry['memory'])) {
        if (isset($entry['memory']['usage']) && is_numeric($entry['memory']['usage'])) {
            $memory_usage = max(0, (int) $entry['memory']['usage']);
        }

        if (isset($entry['memory']['limit']) && is_numeric($entry['memory']['limit'])) {
            $memory_limit = max(0, (int) $entry['memory']['limit']);
        }
    } else {
        if (isset($entry['memory_usage']) && is_numeric($entry['memory_usage'])) {
            $memory_usage = max(0, (int) $entry['memory_usage']);
        }

        if (isset($entry['memory_limit']) && is_numeric($entry['memory_limit'])) {
            $memory_limit = max(0, (int) $entry['memory_limit']);
        }
    }

    $disk_free = null;
    $disk_total = null;

    if (isset($entry['disk']) && is_array($entry['disk'])) {
        if (isset($entry['disk']['free']) && is_numeric($entry['disk']['free'])) {
            $disk_free = max(0, (int) $entry['disk']['free']);
        }

        if (isset($entry['disk']['total']) && is_numeric($entry['disk']['total'])) {
            $disk_total = max(0, (int) $entry['disk']['total']);
        }
    } else {
        if (isset($entry['disk_free']) && is_numeric($entry['disk_free'])) {
            $disk_free = max(0, (int) $entry['disk_free']);
        }

        if (isset($entry['disk_total']) && is_numeric($entry['disk_total'])) {
            $disk_total = max(0, (int) $entry['disk_total']);
        }
    }

    $source = 'manual';

    if (isset($entry['source'])) {
        $entry_source = (string) $entry['source'];

        if (function_exists('sanitize_key')) {
            $entry_source = sanitize_key($entry_source);
        } else {
            $entry_source = strtolower(preg_replace('/[^a-z0-9_\-]/', '', $entry_source));
        }

        if ($entry_source !== '') {
            $source = $entry_source;
        }
    }

    return [
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
        'source'    => $source,
    ];
}

/**
 * Returns the normalized history entries.
 *
 * @return array<int, array>
 */
function sitepulse_resource_monitor_get_history($args = []) {
    $defaults = [
        'per_page' => 0,
        'page'     => 1,
        'since'    => null,
        'order'    => 'ASC',
    ];

    if (function_exists('wp_parse_args')) {
        $args = wp_parse_args($args, $defaults);
    } else {
        $args = array_merge($defaults, is_array($args) ? $args : []);
    }

    $per_page = (int) $args['per_page'];
    $page = (int) $args['page'];
    $page = $page > 0 ? $page : 1;
    $since = $args['since'];
    $order = strtoupper((string) $args['order']) === 'DESC' ? 'DESC' : 'ASC';

    if ($since !== null) {
        $since = is_numeric($since) ? (int) $since : null;

        if ($since !== null && $since <= 0) {
            $since = null;
        }
    }

    sitepulse_resource_monitor_maybe_upgrade_schema();

    $table_exists = sitepulse_resource_monitor_table_exists();
    $total = 0;
    $filtered_total = 0;
    $entries = [];
    $pages = 0;

    if ($table_exists) {
        $table = sitepulse_resource_monitor_get_table_name();

        global $wpdb;

        if ($table !== '' && $wpdb instanceof wpdb) {
            $where_clauses = [];
            $where_params = [];

            if ($since !== null) {
                $where_clauses[] = 'recorded_at >= %d';
                $where_params[] = $since;
            }

            $where_sql = '';

            if (!empty($where_clauses)) {
                $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
            }

            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $filtered_total = $total;

            if ($since !== null) {
                $filtered_total = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where_sql}", $where_params)
                );
            }

            if ($per_page > 0) {
                $pages = $filtered_total > 0 ? (int) ceil($filtered_total / $per_page) : 0;

                if ($pages > 0) {
                    $page = max(1, min($page, $pages));
                } else {
                    $page = 1;
                }

                $offset = max(0, ($page - 1) * $per_page);
                $limit_sql = $wpdb->prepare(' LIMIT %d OFFSET %d', $per_page, $offset);
            } else {
                $limit_sql = '';
                $page = 1;
            }

            $query = "SELECT recorded_at, load_1, load_5, load_15, memory_usage, memory_limit, disk_free, disk_total, source FROM {$table} {$where_sql} ORDER BY recorded_at {$order}{$limit_sql}";

            if (!empty($where_params)) {
                $query = $wpdb->prepare($query, $where_params);
            }

            $rows = $wpdb->get_results($query, ARRAY_A);

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $normalized = sitepulse_resource_monitor_normalize_single_history_entry($row);

                    if ($normalized !== null) {
                        $entries[] = $normalized;
                    }
                }
            }
        }
    } else {
        $option_name = SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY;
        $history = get_option($option_name, []);

        if (!is_array($history)) {
            $history = [];
        }

        $history = sitepulse_resource_monitor_normalize_history($history);
        $total = count($history);
        $filtered_entries = $history;

        if ($since !== null) {
            $filtered_entries = array_values(array_filter(
                $filtered_entries,
                static function ($entry) use ($since) {
                    return isset($entry['timestamp']) && (int) $entry['timestamp'] >= $since;
                }
            ));
        }

        if ($order === 'DESC') {
            $filtered_entries = array_reverse($filtered_entries);
        }

        $filtered_total = count($filtered_entries);

        if ($per_page > 0) {
            $pages = $filtered_total > 0 ? (int) ceil($filtered_total / $per_page) : 0;

            if ($pages > 0) {
                $page = max(1, min($page, $pages));
                $entries = array_slice($filtered_entries, ($page - 1) * $per_page, $per_page);
            } else {
                $page = 1;
                $entries = [];
            }
        } else {
            $entries = $filtered_entries;
            $page = 1;
        }
    }

    if ($per_page <= 0) {
        $pages = $filtered_total > 0 ? 1 : 0;
    }

    return [
        'entries'  => $entries,
        'total'    => $total,
        'filtered' => $filtered_total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => $pages,
        'order'    => $order,
    ];
}

/**
 * Retrieves the timestamp of the most recent cron-generated snapshot.
 *
 * @param array<int, array>|null $history_entries Optional pre-fetched history entries.
 * @return int|null
 */
function sitepulse_resource_monitor_get_last_cron_timestamp($history_entries = null) {
    if (is_array($history_entries)) {
        if (isset($history_entries['entries']) && is_array($history_entries['entries'])) {
            $history_entries = $history_entries['entries'];
        }

        if (is_array($history_entries)) {
            for ($index = count($history_entries) - 1; $index >= 0; $index--) {
                $entry = $history_entries[$index];

                if (!is_array($entry)) {
                    continue;
                }

                if (isset($entry['source']) && $entry['source'] === 'cron') {
                    return isset($entry['timestamp']) ? (int) $entry['timestamp'] : null;
                }
            }
        }
    }

    sitepulse_resource_monitor_maybe_upgrade_schema();

    if (sitepulse_resource_monitor_table_exists()) {
        $table = sitepulse_resource_monitor_get_table_name();

        global $wpdb;

        if ($table !== '' && $wpdb instanceof wpdb) {
            $timestamp = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT recorded_at FROM {$table} WHERE source = %s ORDER BY recorded_at DESC LIMIT 1",
                    'cron'
                )
            );

            if ($timestamp !== null) {
                return (int) $timestamp;
            }
        }
    }

    $option_name = SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY;
    $history = get_option($option_name, []);

    if (!is_array($history) || empty($history)) {
        return null;
    }

    $history = sitepulse_resource_monitor_normalize_history($history);

    for ($index = count($history) - 1; $index >= 0; $index--) {
        $entry = $history[$index];

        if (!is_array($entry)) {
            continue;
        }

        if (isset($entry['source']) && $entry['source'] === 'cron') {
            return isset($entry['timestamp']) ? (int) $entry['timestamp'] : null;
        }
    }

    return null;
}

/**
 * Retrieves the most recent cron timestamp optionally limited to a lower bound.
 *
 * @param int|null $since Minimum timestamp to consider.
 * @return int|null
 */
function sitepulse_resource_monitor_get_last_cron_timestamp_since($since = null) {
    $since_timestamp = null;

    if ($since !== null) {
        $since_timestamp = is_numeric($since) ? (int) $since : null;

        if ($since_timestamp !== null && $since_timestamp <= 0) {
            $since_timestamp = null;
        }
    }

    sitepulse_resource_monitor_maybe_upgrade_schema();

    if (sitepulse_resource_monitor_table_exists()) {
        $table = sitepulse_resource_monitor_get_table_name();

        global $wpdb;

        if ($table !== '' && $wpdb instanceof wpdb) {
            $params = ['cron'];
            $sql = "SELECT recorded_at FROM {$table} WHERE source = %s";

            if ($since_timestamp !== null) {
                $sql .= ' AND recorded_at >= %d';
                $params[] = $since_timestamp;
            }

            $sql .= ' ORDER BY recorded_at DESC LIMIT 1';

            $prepared = $wpdb->prepare($sql, $params);
            $timestamp = $wpdb->get_var($prepared);

            if ($timestamp !== null) {
                return (int) $timestamp;
            }
        }
    }

    $history = get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY, []);

    if (!is_array($history) || empty($history)) {
        return null;
    }

    $history = sitepulse_resource_monitor_normalize_history($history);

    for ($index = count($history) - 1; $index >= 0; $index--) {
        $entry = $history[$index];

        if (!is_array($entry) || !isset($entry['source']) || $entry['source'] !== 'cron') {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : null;

        if ($timestamp === null) {
            continue;
        }

        if ($since_timestamp === null || $timestamp >= $since_timestamp) {
            return $timestamp;
        }
    }

    return null;
}

/**
 * Removes the stored history.
 *
 * @return void
 */
function sitepulse_resource_monitor_clear_history() {
    delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);

    sitepulse_resource_monitor_invalidate_analytics_cache();

    sitepulse_resource_monitor_maybe_upgrade_schema();

    if (!sitepulse_resource_monitor_table_exists()) {
        return;
    }

    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '') {
        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $wpdb->query("DELETE FROM {$table}");
}
