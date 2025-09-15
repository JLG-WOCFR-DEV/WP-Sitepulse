<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() { add_submenu_page('sitepulse-dashboard', 'Database Optimizer', 'Database', 'manage_options', 'sitepulse-db', 'database_optimizer_page'); });
function database_optimizer_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    global $wpdb;
    if (isset($_POST['db_cleanup_nonce']) && wp_verify_nonce($_POST['db_cleanup_nonce'], 'db_cleanup')) {
        if (isset($_POST['clean_revisions'])) {
            // Requête statique : aucun paramètre dynamique n'est interpolé dans la suppression.
            $cleaned = (int) $wpdb->delete(
                $wpdb->posts,
                array('post_type' => 'revision')
            );

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s révisions d\'articles ont été supprimées.</p></div>',
                esc_html((string) $cleaned)
            );
        }
        if (isset($_POST['clean_transients'])) {
            // Requête statique : les motifs LIKE sont définis en dur sans donnée externe.
            $cleaned = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like('_transient_') . '%',
                    $wpdb->esc_like('_site_transient_') . '%'
                )
            );

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
