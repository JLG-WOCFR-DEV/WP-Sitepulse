<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Database Optimizer', 'sitepulse'),
        __('Database', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-db',
        'sitepulse_database_optimizer_page'
    );
});
function sitepulse_database_optimizer_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    global $wpdb;
    if (isset($_POST['db_cleanup_nonce'])) {
        check_admin_referer('db_cleanup', 'db_cleanup_nonce');

        $clean_revisions  = isset($_POST['clean_revisions']) && '1' === wp_unslash($_POST['clean_revisions']);
        $clean_transients = isset($_POST['clean_transients']) && '1' === wp_unslash($_POST['clean_transients']);

        if ($clean_revisions) {
            $batch_size = 500;
            $cleaned = 0;
            $remaining_meta = 0;
            $last_id = 0;
            $previous_cache_invalidation = null;

            if (function_exists('wp_suspend_cache_invalidation')) {
                $previous_cache_invalidation = wp_suspend_cache_invalidation(true);
            }

            $revision_ids = [];

            do {
                $sql = $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND ID > %d ORDER BY ID ASC LIMIT %d",
                    $last_id,
                    $batch_size
                );

                if ($sql === false) {
                    break;
                }

                $revision_ids = array_map('intval', (array) $wpdb->get_col($sql));

                if (empty($revision_ids)) {
                    break;
                }

                $last_id = max($revision_ids);
                $placeholders = implode(',', array_fill(0, count($revision_ids), '%d'));
                $delete_sql = $wpdb->prepare(
                    "DELETE FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
                    $revision_ids
                );

                if ($delete_sql === false) {
                    break;
                }

                $deleted_rows = $wpdb->query($delete_sql);

                if ($deleted_rows === false) {
                    break;
                }

                if ($deleted_rows > 0) {
                    $cleaned += (int) $deleted_rows;

                    if (function_exists('clean_post_cache')) {
                        foreach ($revision_ids as $revision_id) {
                            clean_post_cache((int) $revision_id);
                        }
                    }
                }

                $meta_sql = $wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
                    $revision_ids
                );

                if ($meta_sql === false) {
                    $count_sql = $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
                        $revision_ids
                    );

                    if ($count_sql !== false && $count_sql !== null) {
                        $remaining_meta += (int) $wpdb->get_var($count_sql);
                    }
                } else {
                    $meta_deleted = $wpdb->query($meta_sql);

                    if ($meta_deleted === false) {
                        $count_sql = $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
                            $revision_ids
                        );

                        if ($count_sql !== false && $count_sql !== null) {
                            $remaining_meta += (int) $wpdb->get_var($count_sql);
                        }
                    }
                }
            } while (count($revision_ids) === $batch_size);

            if (function_exists('wp_suspend_cache_invalidation')) {
                wp_suspend_cache_invalidation((bool) $previous_cache_invalidation);
            }

            $notice_class = $cleaned > 0 ? 'notice-success' : 'notice-info';
            $message = sprintf(
                _n(
                    '%s révision d\'article a été supprimée.',
                    '%s révisions d\'articles ont été supprimées.',
                    $cleaned,
                    'sitepulse'
                ),
                number_format_i18n($cleaned)
            );

            printf(
                '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr($notice_class),
                esc_html($message)
            );

            if ($remaining_meta > 0) {
                printf(
                    '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                    sprintf(
                        esc_html__(
                            '%s entrées de métadonnées associées aux révisions n\'ont pas pu être nettoyées automatiquement.',
                            'sitepulse'
                        ),
                        esc_html(number_format_i18n($remaining_meta))
                    )
                );
            }
        }
        if ($clean_transients) {
            $job_scheduled = false;

            if (function_exists('sitepulse_enqueue_async_job')) {
                $job = sitepulse_enqueue_async_job(
                    'transient_cleanup',
                    [
                        'max_batches'  => 4,
                        'prefix_label' => 'expired',
                    ],
                    [
                        'label'        => __('Purge des transients expirés', 'sitepulse'),
                        'requested_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
                    ]
                );

                if (is_array($job)) {
                    $job_scheduled = true;
                }
            }

            if ($job_scheduled) {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html__(
                        'La purge des transients expirés est planifiée. Vous pouvez quitter cette page, le traitement continue en arrière-plan.',
                        'sitepulse'
                    )
                );
            } else {
                $cleaned = sitepulse_delete_expired_transients_fallback($wpdb);

                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html(sitepulse_get_transients_cleanup_message($cleaned))
                );
            }
        }
    }
    $revisions = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
    $transient_like = $wpdb->esc_like('_transient_') . '%';
    $transient_timeout_like = $wpdb->esc_like('_transient_timeout_') . '%';
    $site_transient_like = $wpdb->esc_like('_site_transient_') . '%';
    $site_transient_timeout_like = $wpdb->esc_like('_site_transient_timeout_') . '%';

    $transients = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE (
                 (option_name LIKE %s AND option_name NOT LIKE %s)
                 OR (option_name LIKE %s AND option_name NOT LIKE %s)
             )",
            $transient_like,
            $transient_timeout_like,
            $site_transient_like,
            $site_transient_timeout_like
        )
    );

    if (function_exists('is_multisite') && is_multisite()) {
        $network_transients = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->sitemeta}
                 WHERE meta_key LIKE %s AND meta_key NOT LIKE %s",
                $site_transient_like,
                $site_transient_timeout_like
            )
        );
        $transients += $network_transients;
    }
    $index_suggestions = sitepulse_get_missing_index_suggestions($wpdb);
    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-db');
    }
    ?>
      <div class="wrap">
        <h1><span class="dashicons-before dashicons-database"></span> <?php esc_html_e('Database Optimizer', 'sitepulse'); ?></h1>
        <p><?php esc_html_e('Over time, your database can accumulate data that is no longer necessary. This tool helps you clean it up safely.', 'sitepulse'); ?></p>
        <form method="post">
            <?php wp_nonce_field('db_cleanup', 'db_cleanup_nonce'); ?>
            <div class="card" style="background:#fff; padding:1px 20px 20px; margin-top:20px;">
                <h2>
                    <?php
                    printf(
                        esc_html__('Clean post revisions (%s found)', 'sitepulse'),
                        esc_html(number_format_i18n((int) $revisions))
                    );
                    ?>
                </h2>
                <p>
                    <?php
                    echo wp_kses_post(
                        __('<strong>What is this?</strong> WordPress stores a copy of your posts every time you edit them. These are revisions. While useful, they can bloat your database.', 'sitepulse')
                    );
                    ?>
                </p>
                <p>
                    <?php
                    echo wp_kses_post(
                        __('<strong>Is it risky?</strong> Generally not. This action removes older versions but keeps the published one. It is a common and safe maintenance task.', 'sitepulse')
                    );
                    ?>
                </p>
                <p>
                    <button type="submit" name="clean_revisions" value="1" class="button" <?php disabled($revisions, 0); ?>>
                        <?php esc_html_e('Clean all revisions', 'sitepulse'); ?>
                    </button>
                </p>
            </div>
            <div class="card" style="background:#fff; padding:1px 20px 20px; margin-top:20px;">
                <h2>
                    <?php
                    printf(
                        esc_html__('Clean transients (%s found)', 'sitepulse'),
                        esc_html(number_format_i18n((int) $transients))
                    );
                    ?>
                </h2>
                <p>
                    <?php
                    echo wp_kses_post(
                        __('<strong>What is this?</strong> Transients are a form of temporary cache used by plugins and themes. Sometimes expired transients are not cleaned up properly.', 'sitepulse')
                    );
                    ?>
                </p>
                <p>
                    <?php
                    echo wp_kses_post(
                        __('<strong>Is it risky?</strong> No, this operation only removes expired transients. Your site will regenerate them automatically if needed.', 'sitepulse')
                    );
                    ?>
                </p>
                <p>
                    <button type="submit" name="clean_transients" value="1" class="button" <?php disabled($transients, 0); ?>>
                        <?php esc_html_e('Clean expired transients', 'sitepulse'); ?>
                    </button>
                </p>
            </div>
            <div class="card" style="background:#fff; padding:1px 20px 20px; margin-top:20px;">
                <h2><?php esc_html_e('Index suggestions', 'sitepulse'); ?></h2>
                <p>
                    <?php
                    echo wp_kses_post(
                        __('These recommendations are based on detected usage patterns in WordPress tables. Adding the missing indexes can drastically speed up lookups executed by the admin and by your themes/plugins.', 'sitepulse')
                    );
                    ?>
                </p>
                <?php if (isset($index_suggestions['error'])) : ?>
                    <p><?php echo esc_html($index_suggestions['error']); ?></p>
                <?php elseif (empty($index_suggestions)) : ?>
                    <p><?php esc_html_e('All monitored tables already expose the recommended indexes.', 'sitepulse'); ?></p>
                <?php else : ?>
                    <ul class="ul-disc">
                        <?php foreach ($index_suggestions as $suggestion) : ?>
                            <li>
                                <strong><?php echo esc_html($suggestion['table']); ?></strong>:<br />
                                <?php echo esc_html($suggestion['message']); ?>
                                <?php if (!empty($suggestion['sql'])) : ?>
                                    <pre style="overflow:auto;"><?php echo esc_html($suggestion['sql']); ?></pre>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </form>
      </div>
      <?php
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
