<?php
/**
 * SitePulse Resource Monitor storage schema.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ensures the resource monitor datastore is ready.
 *
 * @return void
 */
function sitepulse_resource_monitor_bootstrap_storage() {
    sitepulse_resource_monitor_maybe_upgrade_schema();
    if (function_exists('sitepulse_http_monitor_bootstrap_storage')) {
        sitepulse_http_monitor_bootstrap_storage();
    }
}

/**
 * Retrieves the fully qualified name of the resource monitor history table.
 *
 * @return string
 */
function sitepulse_resource_monitor_get_table_name() {
    if (!defined('SITEPULSE_TABLE_RESOURCE_MONITOR_HISTORY')) {
        return '';
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return '';
    }

    $suffix = SITEPULSE_TABLE_RESOURCE_MONITOR_HISTORY;

    return $wpdb->prefix . $suffix;
}

/**
 * Determines whether the resource monitor history table exists.
 *
 * @param bool $force_refresh Optional. When true, bypasses the cached result.
 * @return bool
 */
function sitepulse_resource_monitor_table_exists($force_refresh = false) {
    static $exists = null;

    if ($force_refresh) {
        $exists = null;
    }

    if ($exists !== null) {
        return $exists;
    }

    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '') {
        $exists = false;

        return $exists;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        $exists = false;

        return $exists;
    }

    $exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return $exists;
}

/**
 * Creates or upgrades the resource monitor history table.
 *
 * @return void
 */
function sitepulse_resource_monitor_maybe_upgrade_schema() {
    if (!defined('SITEPULSE_RESOURCE_MONITOR_SCHEMA_VERSION')
        || !defined('SITEPULSE_OPTION_RESOURCE_MONITOR_SCHEMA_VERSION')) {
        return;
    }

    $target_version = (int) SITEPULSE_RESOURCE_MONITOR_SCHEMA_VERSION;
    $current_version = (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_SCHEMA_VERSION, 0);

    if ($current_version >= $target_version && sitepulse_resource_monitor_table_exists()) {
        return;
    }

    sitepulse_resource_monitor_install_table();

    if ($current_version < $target_version) {
        sitepulse_resource_monitor_migrate_legacy_history();
        update_option(SITEPULSE_OPTION_RESOURCE_MONITOR_SCHEMA_VERSION, $target_version);
    }
}

/**
 * Installs the resource monitor history table.
 *
 * @return void
 */
function sitepulse_resource_monitor_install_table() {
    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '') {
        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        recorded_at int(10) unsigned NOT NULL,
        load_1 float NULL,
        load_5 float NULL,
        load_15 float NULL,
        memory_usage bigint(20) unsigned NULL,
        memory_limit bigint(20) unsigned NULL,
        disk_free bigint(20) unsigned NULL,
        disk_total bigint(20) unsigned NULL,
        source varchar(32) NOT NULL DEFAULT 'manual',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY recorded_at (recorded_at),
        KEY source (source)
    ) {$charset_collate};";

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta($sql);

    sitepulse_resource_monitor_table_exists(true);
}

/**
 * Migrates legacy option-based history into the dedicated table.
 *
 * @return void
 */
function sitepulse_resource_monitor_migrate_legacy_history() {
    if (!defined('SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY')) {
        return;
    }

    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '' || !sitepulse_resource_monitor_table_exists()) {
        return;
    }

    $legacy_history = get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY, []);

    if (!is_array($legacy_history) || empty($legacy_history)) {
        delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);

        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $existing_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

    if ($existing_rows > 0) {
        delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);

        return;
    }

    $normalized_entries = sitepulse_resource_monitor_normalize_history($legacy_history);

    if (empty($normalized_entries)) {
        delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);

        return;
    }

    foreach ($normalized_entries as $entry) {
        sitepulse_resource_monitor_insert_history_entry($entry, false);
    }

    delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);
}

/**
 * Inserts a normalized history entry into the datastore.
 *
 * @param array $entry            Normalized history entry.
 * @param bool  $apply_retention Optional. Whether to enforce retention after inserting.
 * @return void
 */
function sitepulse_resource_monitor_insert_history_entry(array $entry, $apply_retention = true) {
    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '' || !sitepulse_resource_monitor_table_exists()) {
        return;
    }

    $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

    if ($timestamp <= 0) {
        return;
    }

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $load = isset($entry['load']) && is_array($entry['load']) ? array_values($entry['load']) : [null, null, null];
    $memory = isset($entry['memory']) && is_array($entry['memory']) ? $entry['memory'] : [];
    $disk = isset($entry['disk']) && is_array($entry['disk']) ? $entry['disk'] : [];
    $source = isset($entry['source']) ? (string) $entry['source'] : 'manual';

    $data = [
        'recorded_at'   => $timestamp,
        'load_1'        => isset($load[0]) && is_numeric($load[0]) ? (float) $load[0] : null,
        'load_5'        => isset($load[1]) && is_numeric($load[1]) ? (float) $load[1] : null,
        'load_15'       => isset($load[2]) && is_numeric($load[2]) ? (float) $load[2] : null,
        'memory_usage'  => isset($memory['usage']) && is_numeric($memory['usage']) ? max(0, (int) $memory['usage']) : null,
        'memory_limit'  => isset($memory['limit']) && is_numeric($memory['limit']) ? max(0, (int) $memory['limit']) : null,
        'disk_free'     => isset($disk['free']) && is_numeric($disk['free']) ? max(0, (int) $disk['free']) : null,
        'disk_total'    => isset($disk['total']) && is_numeric($disk['total']) ? max(0, (int) $disk['total']) : null,
        'source'        => $source !== '' ? $source : 'manual',
        'created_at'    => gmdate('Y-m-d H:i:s'),
    ];

    $formats = ['%d', '%f', '%f', '%f', '%d', '%d', '%d', '%d', '%s', '%s'];

    $wpdb->insert($table, $data, $formats);

    if ($apply_retention) {
        sitepulse_resource_monitor_apply_retention();
    }
}

/**
 * Retrieves the configured retention duration in days.
 *
 * @return int
 */
function sitepulse_resource_monitor_get_retention_days() {
    $default = defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_RETENTION_DAYS')
        ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_RETENTION_DAYS
        : 180;

    $retention = (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_RETENTION_DAYS, $default);

    $allowed_values = apply_filters('sitepulse_resource_monitor_allowed_retention_days', [90, 180, 365]);

    if (is_array($allowed_values) && !empty($allowed_values)) {
        $allowed_values = array_map('intval', $allowed_values);
        sort($allowed_values);

        if (in_array($retention, $allowed_values, true)) {
            return max(0, $retention);
        }

        $closest = $allowed_values[0];
        $min_diff = abs($retention - $closest);

        foreach ($allowed_values as $value) {
            $diff = abs($retention - $value);

            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest = $value;
            }
        }

        return max(0, (int) $closest);
    }

    return max(0, $retention);
}

/**
 * Applies the retention policy by removing outdated entries.
 *
 * @return void
 */
function sitepulse_resource_monitor_apply_retention() {
    $table = sitepulse_resource_monitor_get_table_name();

    if ($table === '' || !sitepulse_resource_monitor_table_exists()) {
        return;
    }

    $retention_days = (int) apply_filters(
        'sitepulse_resource_monitor_history_retention_days',
        sitepulse_resource_monitor_get_retention_days()
    );

    if ($retention_days <= 0) {
        return;
    }

    $cutoff = (int) current_time('timestamp', true) - ($retention_days * DAY_IN_SECONDS);

    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE recorded_at < %d", $cutoff));
}

/**
 * Retrieves the maximum number of rows allowed in an export.
 *
 * @return int
 */
function sitepulse_resource_monitor_get_export_max_rows() {
    $default = defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_EXPORT_MAX_ROWS')
        ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_EXPORT_MAX_ROWS
        : 2000;

    $max_rows = (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_EXPORT_MAX_ROWS, $default);

    if ($max_rows < 0) {
        $max_rows = $default;
    }

    return (int) apply_filters('sitepulse_resource_monitor_export_max_rows', $max_rows);
}
