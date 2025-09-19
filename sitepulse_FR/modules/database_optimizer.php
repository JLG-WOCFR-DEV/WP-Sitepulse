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
            $revision_ids = array_map('intval', (array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'"));
            $cleaned = 0;
            $deleted_ids = array();

            foreach ($revision_ids as $revision_id) {
                if ($revision_id <= 0) {
                    continue;
                }

                $deleted = wp_delete_post($revision_id, true);

                if ($deleted && !is_wp_error($deleted)) {
                    $cleaned++;
                    $deleted_ids[] = $revision_id;
                }
            }

            $remaining_meta = 0;

            if (!empty($deleted_ids)) {
                $placeholders = implode(',', array_fill(0, count($deleted_ids), '%d'));
                $prepared = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
                    $deleted_ids
                );

                if ($prepared !== null) {
                    $remaining_meta = (int) $wpdb->get_var($prepared);
                }
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
        if (isset($_POST['clean_transients'])) {
            if (function_exists('delete_expired_transients')) {
                $cleaned = (int) delete_expired_transients();
            } else {
                $cleaned = 0;
                $current_time = time();
                $timeout_prefixes = array('_transient_timeout_', '_site_transient_timeout_');

                foreach ($timeout_prefixes as $prefix) {
                    $expired_timeouts = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %s",
                            $wpdb->esc_like($prefix) . '%',
                            $current_time
                        )
                    );

                    foreach ($expired_timeouts as $timeout_option) {
                        $deleted = false;

                        if ($wpdb->delete($wpdb->options, array('option_name' => $timeout_option), array('%s'))) {
                            $deleted = true;
                        }

                        $value_option = str_replace('_timeout_', '_', $timeout_option);
                        if ($wpdb->delete($wpdb->options, array('option_name' => $value_option), array('%s'))) {
                            $deleted = true;
                        }

                        if ($deleted) {
                            $cleaned++;
                        }
                    }
                }
            }

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s transients expirés ont été supprimés.</p></div>',
                esc_html((string) $cleaned)
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
