<?php
/**
 * SitePulse Plugin Impact directory scan queue.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

function sitepulse_plugin_dir_scan_enqueue($dir) {
    $dir = (string) $dir;

    if ($dir === '') {
        return;
    }

    $queue = get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, []);

    if (!is_array($queue)) {
        $queue = [];
    }

    if (!in_array($dir, $queue, true)) {
        $queue[] = $dir;
        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, $queue, false);
    }

    sitepulse_schedule_plugin_dir_scan();
}

function sitepulse_plugin_dir_scan_remove_from_queue($dir) {
    $dir = (string) $dir;

    if ($dir === '') {
        return;
    }

    $queue = get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, []);

    if (!is_array($queue) || empty($queue)) {
        return;
    }

    $position = array_search($dir, $queue, true);

    if ($position === false) {
        return;
    }

    unset($queue[$position]);

    if (empty($queue)) {
        delete_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION);
    } else {
        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, array_values($queue), false);
    }
}

function sitepulse_schedule_plugin_dir_scan() {
    if (!wp_next_scheduled('sitepulse_queue_plugin_dir_scan')) {
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'sitepulse_queue_plugin_dir_scan');
    }
}

function sitepulse_process_plugin_dir_scan_queue() {
    if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        return;
    }

    $queue = get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, []);

    if (!is_array($queue) || empty($queue)) {
        delete_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION);

        return;
    }

    $dir = array_shift($queue);

    if (empty($queue)) {
        delete_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION);
    } else {
        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, array_values($queue), false);
    }

    $dir = (string) $dir;

    if ($dir === '') {
        sitepulse_schedule_plugin_dir_scan();

        return;
    }

    $size_info = sitepulse_get_dir_size_recursive(
        $dir,
        [
            'max_bytes'         => 0,
            'max_files'         => 0,
            'stop_on_threshold' => false,
        ]
    );

    $size = isset($size_info['size']) ? (int) $size_info['size'] : 0;
    $files = isset($size_info['files']) ? max(0, (int) $size_info['files']) : null;

    $expiration = (int) apply_filters('sitepulse_plugin_dir_size_cache_ttl', 6 * HOUR_IN_SECONDS, $dir);

    if ($expiration <= 0) {
        $expiration = 6 * HOUR_IN_SECONDS;
    }

    $payload = [
        'status' => 'complete',
        'size'   => $size,
        'files'  => $files,
        'generated_at' => sitepulse_plugin_impact_get_timestamp(),
    ];

    $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($dir);

    set_transient($transient_key, $payload, $expiration);

    if (!empty($queue)) {
        sitepulse_schedule_plugin_dir_scan();
    }
}
