<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'Database Optimizer', 'Database', 'manage_options', 'sitepulse-db', 'sitepulse_database_optimizer_page'); });
function sitepulse_database_optimizer_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    global $wpdb;
    if (isset($_POST['db_cleanup_nonce']) && wp_verify_nonce($_POST['db_cleanup_nonce'], 'db_cleanup')) {
        if (isset($_POST['clean_revisions'])) {
            $batch_size = 500;
            $cleaned = 0;
            $remaining_meta = 0;
            $last_id = 0;

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
                $deleted_ids_chunk = array();

                foreach ($revision_ids as $revision_id) {
                    if ($revision_id <= 0) {
                        continue;
                    }

                    $deleted = wp_delete_post($revision_id, true);

                    if ($deleted && !is_wp_error($deleted)) {
                        $cleaned++;
                        $deleted_ids_chunk[] = $revision_id;
                    }
                }

                if (!empty($deleted_ids_chunk)) {
                    $placeholders = implode(',', array_fill(0, count($deleted_ids_chunk), '%d'));
                    $meta_sql = $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
                        $deleted_ids_chunk
                    );

                    if ($meta_sql !== false && $meta_sql !== null) {
                        $remaining_meta += (int) $wpdb->get_var($meta_sql);
                    }
                }
            } while (count($revision_ids) === $batch_size);

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
        if (isset($_POST['clean_transients'])) {
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
        <h1><span class="dashicons-before dashicons-database"></span> Optimiseur de Base de Données</h1>
        <p>Avec le temps, votre base de données peut accumuler des données qui ne sont plus nécessaires. Cet outil vous aide à la nettoyer en toute sécurité.</p>
        <form method="post">
            <?php wp_nonce_field('db_cleanup', 'db_cleanup_nonce'); ?>
            <div class="card" style="background:#fff; padding:1px 20px 20px; margin-top:20px;">
                <h2>Nettoyer les révisions d'articles (<?php echo esc_html((int)$revisions); ?> trouvées)</h2>
                <p><strong>Qu'est-ce que c'est ?</strong> WordPress sauvegarde une copie de vos articles à chaque modification. Ce sont les révisions. Bien qu'utiles, elles peuvent alourdir votre base de données.</p>
                <p><strong>Est-ce dangereux ?</strong> Généralement, non. Cette action supprime les anciennes versions mais conserve la version publiée. C'est une tâche de maintenance courante et sûre.</p>
                <p><input type="submit" name="clean_revisions" value="Nettoyer toutes les révisions" class="button" <?php disabled($revisions, 0); ?>></p>
            </div>
            <div class="card" style="background:#fff; padding:1px 20px 20px; margin-top:20px;">
                <h2>Nettoyer les Transients (<?php echo esc_html((int)$transients); ?> trouvés)</h2>
                <p><strong>Qu'est-ce que c'est ?</strong> Les transients sont une forme de cache temporaire utilisé par les plugins et thèmes. Parfois, les transients expirés ne sont pas supprimés correctement.</p>
                <p><strong>Est-ce dangereux ?</strong> Non, c'est une opération très sûre. Cet outil ne supprime que les transients expirés. Votre site les régénérera automatiquement si besoin.</p>
                <p><input type="submit" name="clean_transients" value="Nettoyer les Transients Expirés" class="button" <?php disabled($transients, 0); ?>></p>
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

    $sql = "SELECT {$key_column} FROM {$table} WHERE {$key_column} LIKE %s AND {$value_column} < %s";
    $params = array($wpdb->esc_like($timeout_prefix) . '%', $current_time);

    if ($table === $wpdb->sitemeta && $site_id !== null) {
        $sql .= ' AND site_id = %d';
        $params[] = $site_id;
    }

    $prepared = $wpdb->prepare($sql, $params);

    if ($prepared === false) {
        return 0;
    }

    $expired_timeouts = (array) $wpdb->get_col($prepared);

    if (empty($expired_timeouts)) {
        return 0;
    }

    $purged = 0;

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
