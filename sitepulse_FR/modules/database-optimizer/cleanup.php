<?php
/**
 * SitePulse Database Optimizer cleanup helpers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

function sitepulse_delete_expired_transients_fallback($wpdb, $args = null) {
    $defaults = array(
        'max_batches_per_source' => 0,
        'return_stats'           => false,
    );

    $args = is_array($args) ? array_merge($defaults, $args) : $defaults;

    $cleaned = 0;
    $current_time = time();
    $is_multisite = function_exists('is_multisite') && is_multisite();
    $network_id = null;

    if ($is_multisite) {
        if (function_exists('get_current_network_id')) {
            $network_id = (int) get_current_network_id();
        } elseif (isset($wpdb->siteid)) {
            $network_id = (int) $wpdb->siteid;
        } elseif (defined('SITE_ID_CURRENT_SITE')) {
            $network_id = (int) SITE_ID_CURRENT_SITE;
        }
    }

    $sources = array(
        array(
            'timeout_prefix' => '_transient_timeout_',
            'value_prefix' => '_transient_',
            'table' => $wpdb->options,
            'key_column' => 'option_name',
            'value_column' => 'option_value',
            'cache_group' => 'options',
        ),
    );

    if ($is_multisite) {
        $sources[] = array(
            'timeout_prefix' => '_site_transient_timeout_',
            'value_prefix' => '_site_transient_',
            'table' => $wpdb->sitemeta,
            'key_column' => 'meta_key',
            'value_column' => 'meta_value',
            'cache_group' => 'site-options',
            'site_id' => $network_id,
        );
    } else {
        $sources[] = array(
            'timeout_prefix' => '_site_transient_timeout_',
            'value_prefix' => '_site_transient_',
            'table' => $wpdb->options,
            'key_column' => 'option_name',
            'value_column' => 'option_value',
            'cache_group' => 'options',
        );
    }

    $sources_stats = array();
    $has_more = false;
    $max_batches = isset($args['max_batches_per_source']) ? (int) $args['max_batches_per_source'] : 0;
    $max_batches = max(0, $max_batches);
    $return_stats = !empty($args['return_stats']);

    foreach ($sources as $source) {
        $scope = isset($source['value_prefix']) && strpos($source['value_prefix'], '_site_transient_') === 0 ? 'site-transient' : 'transient';
        $result = sitepulse_cleanup_transient_source(
            $wpdb,
            $source,
            $current_time,
            array(
                'max_batches'  => $max_batches,
                'return_stats' => $return_stats,
            )
        );

        if ($return_stats && is_array($result)) {
            $cleaned += isset($result['deleted']) ? (int) $result['deleted'] : 0;
            $has_more = $has_more || !empty($result['has_more']);
            $sources_stats[] = array(
                'scope'    => $scope,
                'deleted'  => isset($result['deleted']) ? (int) $result['deleted'] : 0,
                'batches'  => isset($result['batches']) ? (int) $result['batches'] : 0,
                'has_more' => !empty($result['has_more']),
            );
        } else {
            $cleaned += (int) $result;
        }
    }

    if ($return_stats) {
        return array(
            'deleted' => $cleaned,
            'has_more' => $has_more,
            'sources' => $sources_stats,
        );
    }

    return (int) $cleaned;
}

function sitepulse_cleanup_transient_source($wpdb, $source, $current_time, $args = null) {
    $table = $source['table'];
    $key_column = $source['key_column'];
    $value_column = $source['value_column'];
    $site_id = isset($source['site_id']) ? $source['site_id'] : null;
    $timeout_prefix = $source['timeout_prefix'];
    $value_prefix = $source['value_prefix'];

    $batch_size = (int) apply_filters('sitepulse_transient_cleanup_batch_size', 100, $source);

    if ($batch_size <= 0) {
        $batch_size = 100;
    }

    $purged = 0;

    $expired_timeouts = [];
    $max_batches = 0;
    $return_stats = false;

    if (is_array($args)) {
        if (isset($args['max_batches'])) {
            $max_batches = max(0, (int) $args['max_batches']);
        }

        if (!empty($args['return_stats'])) {
            $return_stats = true;
        }
    }

    $processed_batches = 0;
    $has_more = false;

    do {
        $sql = "SELECT {$key_column} FROM {$table} WHERE {$key_column} LIKE %s AND CAST({$value_column} AS UNSIGNED) < %d";
        $params = array($wpdb->esc_like($timeout_prefix) . '%', (int) $current_time);

        if ($table === $wpdb->sitemeta && $site_id !== null) {
            $sql .= ' AND site_id = %d';
            $params[] = (int) $site_id;
        }

        $sql .= " ORDER BY {$value_column} ASC LIMIT %d";
        $params[] = $batch_size;

        $prepared = $wpdb->prepare($sql, $params);

        if ($prepared === false || !is_string($prepared)) {
            break;
        }

        $expired_timeouts = (array) $wpdb->get_col($prepared);

        if (empty($expired_timeouts)) {
            break;
        }

        foreach ($expired_timeouts as $timeout_option) {
            if (!is_string($timeout_option) || $timeout_option === '') {
                continue;
            }

            if (strpos($timeout_option, $timeout_prefix) !== 0) {
                continue;
            }

            $transient_key = substr($timeout_option, strlen($timeout_prefix));

            if ($transient_key === '') {
                continue;
            }

            $value_option = $value_prefix . $transient_key;
            $deleted_timeout = sitepulse_delete_transient_option($wpdb, $source, $timeout_option, $site_id);
            $deleted_value = sitepulse_delete_transient_option($wpdb, $source, $value_option, $site_id);

            if ($deleted_timeout || $deleted_value) {
                $purged++;
            }
        }
        $processed_batches++;
        $has_more = count($expired_timeouts) === $batch_size;

        if ($max_batches > 0 && $processed_batches >= $max_batches) {
            break;
        }
    } while ($has_more);

    if ($return_stats) {
        return array(
            'deleted'  => $purged,
            'batches'  => $processed_batches,
            'has_more' => $has_more,
        );
    }

    return $purged;
}

function sitepulse_delete_transient_option($wpdb, $source, $option_name, $site_id) {
    $table = $source['table'];
    $key_column = $source['key_column'];
    $where = array($key_column => $option_name);
    $where_format = array('%s');

    if ($table === $wpdb->sitemeta && $site_id !== null) {
        $where['site_id'] = $site_id;
        $where_format[] = '%d';
    }

    $deleted = (bool) $wpdb->delete($table, $where, $where_format);

    if ($deleted) {
        sitepulse_flush_transient_cache($source, $option_name, $site_id);
    }

    return $deleted;
}

function sitepulse_flush_transient_cache($source, $option_name, $site_id) {
    if (!function_exists('wp_cache_delete')) {
        return;
    }

    $group = isset($source['cache_group']) ? $source['cache_group'] : 'options';

    if ($group === 'site-options' && $site_id !== null) {
        $cache_key = $site_id . ':' . $option_name;
    } else {
        $cache_key = $option_name;
    }

    wp_cache_delete($cache_key, $group);
}

function sitepulse_get_transients_cleanup_message($count) {
    if ($count <= 0) {
        return __('Aucun transient expiré n\'a été supprimé.', 'sitepulse');
    }

    return sprintf(
        _n(
            '%s transient expiré a été supprimé.',
            '%s transients expirés ont été supprimés.',
            $count,
            'sitepulse'
        ),
        number_format_i18n($count)
    );
}

function sitepulse_get_missing_index_suggestions($wpdb) {
    if (!isset($wpdb->dbname) || !is_string($wpdb->dbname) || $wpdb->dbname === '') {
        return array(
            'error' => __('Unable to read the database schema name. Index checks cannot run.', 'sitepulse'),
        );
    }

    $schema = $wpdb->dbname;
    $tables = array(
        $wpdb->postmeta => array(
            array(
                'columns' => array('post_id', 'meta_key'),
                'type'    => 'INDEX',
                'message' => __('Missing composite index on (post_id, meta_key). It is used for post meta queries and speeds up editing screens.', 'sitepulse'),
                'sql'     => sprintf('CREATE INDEX %1$s ON %2$s (post_id, meta_key(191));', 'sitepulse_postmeta_post_id_meta_key', $wpdb->postmeta),
            ),
            array(
                'columns' => array('meta_key'),
                'type'    => 'INDEX',
                'message' => __('Missing index on meta_key. WordPress core relies on it when filtering posts by custom fields.', 'sitepulse'),
                'sql'     => sprintf('CREATE INDEX %1$s ON %2$s (meta_key(191));', 'sitepulse_postmeta_meta_key', $wpdb->postmeta),
            ),
        ),
        $wpdb->commentmeta => array(
            array(
                'columns' => array('comment_id', 'meta_key'),
                'type'    => 'INDEX',
                'message' => __('Missing composite index on (comment_id, meta_key). It keeps discussions fast when comments store metadata.', 'sitepulse'),
                'sql'     => sprintf('CREATE INDEX %1$s ON %2$s (comment_id, meta_key(191));', 'sitepulse_commentmeta_comment_id_meta_key', $wpdb->commentmeta),
            ),
            array(
                'columns' => array('meta_key'),
                'type'    => 'INDEX',
                'message' => __('Missing index on meta_key. It is required for efficient lookups when plugins use comment meta.', 'sitepulse'),
                'sql'     => sprintf('CREATE INDEX %1$s ON %2$s (meta_key(191));', 'sitepulse_commentmeta_meta_key', $wpdb->commentmeta),
            ),
        ),
        $wpdb->termmeta => array(
            array(
                'columns' => array('term_id', 'meta_key'),
                'type'    => 'INDEX',
                'message' => __('Missing composite index on (term_id, meta_key). It avoids slow taxonomy screens when term metadata grows.', 'sitepulse'),
                'sql'     => sprintf('CREATE INDEX %1$s ON %2$s (term_id, meta_key(191));', 'sitepulse_termmeta_term_id_meta_key', $wpdb->termmeta),
            ),
            array(
                'columns' => array('meta_key'),
                'type'    => 'INDEX',
                'message' => __('Missing index on meta_key. This is heavily used when plugins filter taxonomy meta.', 'sitepulse'),
                'sql'     => sprintf('CREATE INDEX %1$s ON %2$s (meta_key(191));', 'sitepulse_termmeta_meta_key', $wpdb->termmeta),
            ),
        ),
        $wpdb->usermeta => array(
            array(
                'columns' => array('user_id', 'meta_key'),
                'type'    => 'INDEX',
                'message' => __('Missing composite index on (user_id, meta_key). It is essential for sites with many users and plugins storing profile data.', 'sitepulse'),
                'sql'     => sprintf('CREATE INDEX %1$s ON %2$s (user_id, meta_key(191));', 'sitepulse_usermeta_user_id_meta_key', $wpdb->usermeta),
            ),
            array(
                'columns' => array('meta_key'),
                'type'    => 'INDEX',
                'message' => __('Missing index on meta_key. It accelerates queries filtering users by metadata.', 'sitepulse'),
                'sql'     => sprintf('CREATE INDEX %1$s ON %2$s (meta_key(191));', 'sitepulse_usermeta_meta_key', $wpdb->usermeta),
            ),
        ),
    );

    $suggestions = array();

    foreach ($tables as $table => $checks) {
        if (empty($table)) {
            continue;
        }

        $existing_indexes = sitepulse_get_table_indexes($wpdb, $schema, $table);

        if ($existing_indexes === null) {
            return array(
                'error' => __('Unable to inspect database indexes. Your MySQL user might be missing the SELECT privilege on INFORMATION_SCHEMA.', 'sitepulse'),
            );
        }

        foreach ($checks as $check) {
            if (!sitepulse_index_exists($existing_indexes, $check['columns'], $check['type'])) {
                $suggestions[] = array(
                    'table'   => $table,
                    'message' => $check['message'],
                    'sql'     => $check['sql'],
                );
            }
        }
    }

    return $suggestions;
}

function sitepulse_get_table_indexes($wpdb, $schema, $table) {
    $sql = $wpdb->prepare(
        "SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME"
        . " FROM INFORMATION_SCHEMA.STATISTICS"
        . " WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s"
        . " ORDER BY INDEX_NAME, SEQ_IN_INDEX",
        $schema,
        $table
    );

    if ($sql === false) {
        return null;
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    if (!is_array($rows)) {
        return null;
    }

    $indexes = array();

    foreach ($rows as $row) {
        if (!isset($row['INDEX_NAME'], $row['COLUMN_NAME'])) {
            continue;
        }

        $index_name = $row['INDEX_NAME'];

        if (!isset($indexes[$index_name])) {
            $indexes[$index_name] = array(
                'columns'    => array(),
                'type'       => ((int) $row['NON_UNIQUE']) === 0 ? 'UNIQUE' : 'INDEX',
                'is_primary' => $index_name === 'PRIMARY',
            );
        }

        $indexes[$index_name]['columns'][] = $row['COLUMN_NAME'];
    }

    return $indexes;
}

function sitepulse_index_exists($indexes, $columns, $type) {
    foreach ($indexes as $index) {
        if (!isset($index['columns'], $index['type'])) {
            continue;
        }

        if ($index['type'] !== $type && empty($index['is_primary'])) {
            continue;
        }

        $normalized_existing = array_values($index['columns']);
        $normalized_requested = array_values($columns);

        if (count($normalized_existing) !== count($normalized_requested)) {
            continue;
        }

        $matched = true;

        foreach ($normalized_existing as $i => $column) {
            if (!isset($normalized_requested[$i]) || $normalized_requested[$i] !== $column) {
                $matched = false;
                break;
            }
        }

        if ($matched) {
            return true;
        }
    }

    return false;
}
