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
        $confirm_revisions = isset($_POST['confirm']) && '1' === wp_unslash($_POST['confirm']);

        if ($clean_revisions && $confirm_revisions) {
            if (!function_exists('wp_delete_post_revision') && defined('ABSPATH')) {
                require_once ABSPATH . 'wp-admin/includes/revision.php';
            }

            $batch_size = 500;
            $cleaned = 0;
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

                foreach ($revision_ids as $revision_id) {
                    if ($revision_id <= 0) {
                        continue;
                    }

                    $deleted = function_exists('wp_delete_post_revision')
                        ? wp_delete_post_revision($revision_id)
                        : false;

                    if ($deleted) {
                        $cleaned++;
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
        } elseif ($clean_revisions && !$confirm_revisions) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html__('La suppression des révisions a été annulée. Veuillez confirmer l’action.', 'sitepulse')
            );
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
                    <label for="sitepulse-confirm-revisions">
                        <input type="checkbox" name="confirm" id="sitepulse-confirm-revisions" value="1" <?php disabled($revisions, 0); ?> />
                        <?php esc_html_e('Je confirme la suppression définitive de toutes les révisions.', 'sitepulse'); ?>
                    </label>
                </p>
                <p>
                    <button
                        type="submit"
                        name="clean_revisions"
                        value="1"
                        class="button button-primary"
                        onclick="if (!document.getElementById('sitepulse-confirm-revisions').checked) { window.alert('<?php echo esc_js(__('Cochez la case de confirmation avant de supprimer les révisions.', 'sitepulse')); ?>'); return false; } return window.confirm('<?php echo esc_js(__('Supprimer toutes les révisions ? Cette action est irréversible.', 'sitepulse')); ?>');"
                        <?php disabled($revisions, 0); ?>
                    >
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

require_once __DIR__ . '/database-optimizer/cleanup.php';
