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
    $transients = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'");
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

    $timeout_sources = array(
        array(
            'prefix' => '_transient_timeout_',
            'table' => $wpdb->options,
            'key_column' => 'option_name',
            'value_column' => 'option_value',
        ),
    );

    if ($is_multisite) {
        $timeout_sources[] = array(
            'prefix' => '_site_transient_timeout_',
            'table' => $wpdb->sitemeta,
            'key_column' => 'meta_key',
            'value_column' => 'meta_value',
            'site_id' => $network_id,
        );
    } else {
        $timeout_sources[] = array(
            'prefix' => '_site_transient_timeout_',
            'table' => $wpdb->options,
            'key_column' => 'option_name',
            'value_column' => 'option_value',
        );
    }

    foreach ($timeout_sources as $source) {
        $prefix = $source['prefix'];
        $table = $source['table'];
        $key_column = $source['key_column'];
        $value_column = $source['value_column'];
        $site_id = isset($source['site_id']) ? $source['site_id'] : null;

        $sql = "SELECT {$key_column} FROM {$table} WHERE {$key_column} LIKE %s AND {$value_column} < %s";
        $params = array($wpdb->esc_like($prefix) . '%', $current_time);

        if ($table === $wpdb->sitemeta && $site_id !== null) {
            $sql .= ' AND site_id = %d';
            $params[] = $site_id;
        }

        $prepared = $wpdb->prepare($sql, $params);

        if ($prepared === false) {
            continue;
        }

        $expired_timeouts = $wpdb->get_col($prepared);

        foreach ($expired_timeouts as $timeout_option) {
            $deleted = false;
            $where = array($key_column => $timeout_option);
            $where_format = array('%s');

            if ($table === $wpdb->sitemeta && $site_id !== null) {
                $where['site_id'] = $site_id;
                $where_format[] = '%d';
            }

            if ($wpdb->delete($table, $where, $where_format)) {
                $deleted = true;
            }

            $value_option = str_replace('_timeout_', '_', $timeout_option);
            $value_where = array($key_column => $value_option);
            $value_where_format = array('%s');

            if ($table === $wpdb->sitemeta && $site_id !== null) {
                $value_where['site_id'] = $site_id;
                $value_where_format[] = '%d';
            }

            if ($wpdb->delete($table, $value_where, $value_where_format)) {
                $deleted = true;
            }

            if ($deleted) {
                $cleaned++;
            }
        }
    }

    return $cleaned;
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
