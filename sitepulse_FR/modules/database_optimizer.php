<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Database Optimizer', 'sitepulse'),
        __('Database', 'sitepulse'),
        'manage_options',
        'sitepulse-db',
        'sitepulse_database_optimizer_page'
    );
});
function sitepulse_database_optimizer_page() {
    if (!current_user_can('manage_options')) {
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
            $cleaned = null;
            $generic_success = false;

            if (function_exists('delete_expired_transients')) {
                $result = delete_expired_transients();

                if (is_int($result)) {
                    $cleaned = max(0, $result);
                } elseif ($result === false) {
                    $cleaned = sitepulse_delete_expired_transients_fallback($wpdb);
                } elseif ($result === true) {
                    $generic_success = true;
                }
            } else {
                $cleaned = sitepulse_delete_expired_transients_fallback($wpdb);
            }

            if ($cleaned !== null) {
                $message = sitepulse_get_transients_cleanup_message($cleaned);
            } else {
                $message = __('Les transients expirés ont été supprimés.', 'sitepulse');
            }

            if ($generic_success && $cleaned === null) {
                $message = __('Les transients expirés ont été supprimés.', 'sitepulse');
            }

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($message)
            );
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
        </form>
      </div>
      <?php
  }

function sitepulse_delete_expired_transients_fallback($wpdb) {
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

    foreach ($sources as $source) {
        $cleaned += sitepulse_cleanup_transient_source($wpdb, $source, $current_time);
    }

    return $cleaned;
}

function sitepulse_cleanup_transient_source($wpdb, $source, $current_time) {
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

    do {
        $sql = "SELECT {$key_column} FROM {$table} WHERE {$key_column} LIKE %s AND {$value_column} < %s";
        $params = array($wpdb->esc_like($timeout_prefix) . '%', $current_time);

        if ($table === $wpdb->sitemeta && $site_id !== null) {
            $sql .= ' AND site_id = %d';
            $params[] = $site_id;
        }

        $sql .= " ORDER BY {$value_column} ASC LIMIT %d";
        $params[] = $batch_size;

        $prepared = $wpdb->prepare($sql, $params);

        if ($prepared === false) {
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
    } while (count($expired_timeouts) === $batch_size);

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
