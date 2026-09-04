<?php
/**
 * SitePulse Plugin Impact directory size cache.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

function sitepulse_get_dir_size_with_cache($dir) {
    $dir = (string) $dir;

    if ($dir === '') {
        return [
            'status' => 'complete',
            'size'   => 0,
            'files'  => null,
            'generated_at' => null,
        ];
    }

    $timestamp = sitepulse_plugin_impact_get_timestamp();

    if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        $size_info = sitepulse_get_dir_size_recursive($dir);

        return [
            'status' => 'complete',
            'size'   => isset($size_info['size']) ? (int) $size_info['size'] : 0,
            'files'  => isset($size_info['files']) ? max(0, (int) $size_info['files']) : null,
            'generated_at' => $timestamp,
        ];
    }

    $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($dir);
    $cached_size = get_transient($transient_key);

    if ($cached_size !== false) {
        if (is_array($cached_size)) {
            $status = isset($cached_size['status']) ? $cached_size['status'] : 'complete';

            if ($status === 'pending') {
                sitepulse_plugin_dir_scan_enqueue($dir);

                return [
                    'status' => 'pending',
                    'size'   => null,
                    'files'  => null,
                    'generated_at' => isset($cached_size['generated_at']) ? (int) $cached_size['generated_at'] : null,
                ];
            }

            return [
                'status' => 'complete',
                'size'   => isset($cached_size['size']) ? (int) $cached_size['size'] : 0,
                'files'  => isset($cached_size['files']) ? max(0, (int) $cached_size['files']) : null,
                'generated_at' => isset($cached_size['generated_at']) ? (int) $cached_size['generated_at'] : null,
            ];
        }

        if (is_numeric($cached_size)) {
            return [
                'status' => 'complete',
                'size'   => (int) $cached_size,
                'files'  => null,
                'generated_at' => null,
            ];
        }

    }

    $threshold = sitepulse_get_plugin_dir_size_threshold($dir);
    $size_info = sitepulse_get_dir_size_recursive(
        $dir,
        [
            'max_bytes'         => isset($threshold['max_bytes']) ? (int) $threshold['max_bytes'] : 0,
            'max_files'         => isset($threshold['max_files']) ? (int) $threshold['max_files'] : 0,
            'stop_on_threshold' => true,
        ]
    );

    $expiration = (int) apply_filters('sitepulse_plugin_dir_size_cache_ttl', 6 * HOUR_IN_SECONDS, $dir);

    if ($expiration <= 0) {
        $expiration = 6 * HOUR_IN_SECONDS;
    }

    if (isset($size_info['exceeded']) && $size_info['exceeded']) {
        $payload = [
            'status' => 'pending',
            'size'   => null,
            'files'  => null,
            'generated_at' => $timestamp,
        ];

        set_transient($transient_key, $payload, $expiration);

        sitepulse_plugin_dir_scan_enqueue($dir);

        return $payload;
    }

    $size = isset($size_info['size']) ? (int) $size_info['size'] : 0;
    $files = isset($size_info['files']) ? max(0, (int) $size_info['files']) : null;

    $payload = [
        'status' => 'complete',
        'size'   => $size,
        'files'  => $files,
        'generated_at' => $timestamp,
    ];

    set_transient($transient_key, $payload, $expiration);

    return $payload;
}

function sitepulse_clear_dir_size_cache($dir) {
    $dir = (string) $dir;

    if ($dir === '' || !defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        return;
    }

    $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($dir);

    delete_transient($transient_key);

    if (function_exists('delete_site_transient')) {
        delete_site_transient($transient_key);
    }

    sitepulse_plugin_dir_scan_remove_from_queue($dir);
}

function sitepulse_get_dir_size_recursive($dir, $args = []) {
    $defaults = [
        'max_bytes'         => 0,
        'max_files'         => 0,
        'stop_on_threshold' => false,
    ];

    if (!is_array($args)) {
        $args = [];
    }

    $args = wp_parse_args($args, $defaults);

    $size = 0;
    $file_count = 0;
    $exceeded = false;

    $dir = (string) $dir;
    $resolved_dir = $dir;

    if (function_exists('realpath')) {
        $realpath = realpath($dir);

        if ($realpath !== false) {
            // Resolve the directory to follow symlinks where possible.
            $resolved_dir = $realpath;
        }
    }

    if (!is_dir($resolved_dir)) {
        return [
            'size'     => $size,
            'files'    => $file_count,
            'exceeded' => $exceeded,
        ];
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved_dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $size += $file->getSize();
            $file_count++;

            if ($args['stop_on_threshold']) {
                $threshold_exceeded = false;

                if ($args['max_bytes'] > 0 && $size > $args['max_bytes']) {
                    $threshold_exceeded = true;
                }

                if ($args['max_files'] > 0 && $file_count > $args['max_files']) {
                    $threshold_exceeded = true;
                }

                if ($threshold_exceeded) {
                    $exceeded = true;

                    break;
                }
            }
        }
    } catch (UnexpectedValueException | RuntimeException $e) {
        return [
            'size'     => $size,
            'files'    => $file_count,
            'exceeded' => $exceeded,
        ];
    }

    return [
        'size'     => $size,
        'files'    => $file_count,
        'exceeded' => $exceeded,
    ];
}

function sitepulse_get_plugin_dir_size_threshold($dir) {
    $default_threshold = [
        'max_bytes' => 100 * MB_IN_BYTES,
        'max_files' => 0,
    ];

    $threshold = apply_filters('sitepulse_plugin_dir_size_threshold', $default_threshold, $dir);

    if (!is_array($threshold)) {
        return $default_threshold;
    }

    $threshold = wp_parse_args($threshold, $default_threshold);

    $threshold['max_bytes'] = isset($threshold['max_bytes']) ? max(0, (int) $threshold['max_bytes']) : 0;
    $threshold['max_files'] = isset($threshold['max_files']) ? max(0, (int) $threshold['max_files']) : 0;

    return $threshold;
}

function sitepulse_plugin_impact_guess_slug($plugin_file, $plugin_data = []) {
    $plugin_file = (string) $plugin_file;

    if ($plugin_file === '') {
        return '';
    }

    if (is_array($plugin_data) && !empty($plugin_data['slug'])) {
        return sanitize_key($plugin_data['slug']);
    }

    $plugin_dir = dirname($plugin_file);

    if ($plugin_dir !== '.' && $plugin_dir !== '' && $plugin_dir !== DIRECTORY_SEPARATOR) {
        return sanitize_title($plugin_dir);
    }

    $plugin_basename = basename($plugin_file, '.php');

    if ($plugin_basename !== '') {
        return sanitize_title($plugin_basename);
    }

    return '';
}
