<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Maintenance Advisor', 'sitepulse'),
        __('Maintenance', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-maintenance',
        'sitepulse_maintenance_advisor_page'
    );
});
function sitepulse_maintenance_advisor_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    require_once ABSPATH . 'wp-admin/includes/update.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/theme.php';

    add_thickbox();

    $core_updates = apply_filters('sitepulse_maintenance_advisor_core_updates', get_core_updates());
    $plugin_updates = apply_filters('sitepulse_maintenance_advisor_plugin_updates', get_plugin_updates());
    $theme_updates = apply_filters('sitepulse_maintenance_advisor_theme_updates', get_theme_updates());

    $plugin_transient = get_site_transient('update_plugins');
    $theme_transient = get_site_transient('update_themes');

    $core_data_available = !is_wp_error($core_updates) && false !== $core_updates;
    $plugin_data_available = false !== $plugin_transient;
    $theme_data_available = false !== $theme_transient;

    $has_any_update_data = $core_data_available || $plugin_data_available || $theme_data_available;

    $core_status = __('Données indisponibles', 'sitepulse');
    if ($core_data_available && is_array($core_updates)) {
        $core_update_entry = isset($core_updates[0]) && is_object($core_updates[0])
            ? $core_updates[0]
            : null;

        $core_status = $core_update_entry !== null
            && property_exists($core_update_entry, 'response')
            && $core_update_entry->response !== 'latest'
            ? __('Mise à jour disponible !', 'sitepulse')
            : __('À jour', 'sitepulse');
    }

    $plugin_updates_count = is_array($plugin_updates) ? count($plugin_updates) : 0;
    $theme_updates_count = is_array($theme_updates) ? count($theme_updates) : 0;

    $auto_update_plugins = (array) get_site_option('auto_update_plugins', array());
    $auto_update_themes = (array) get_site_option('auto_update_themes', array());

    $update_rows = array();

    if (is_array($plugin_updates)) {
        foreach ($plugin_updates as $plugin_file => $plugin_data) {
            if (!isset($plugin_data->update) || !is_object($plugin_data->update)) {
                continue;
            }

            $update_data = $plugin_data->update;
            $installed_version = isset($plugin_data->Version) ? $plugin_data->Version : '';
            $available_version = isset($update_data->new_version) ? $update_data->new_version : '';
            $is_security = false;

            if (property_exists($update_data, 'security')) {
                $is_security = (bool) $update_data->security;
            } elseif (property_exists($update_data, 'update') && is_object($update_data->update) && property_exists($update_data->update, 'security')) {
                $is_security = (bool) $update_data->update->security;
            }

            $auto_update_enabled = in_array($plugin_file, $auto_update_plugins, true);

            $details_url = '';
            if (isset($update_data->slug)) {
                $details_url = self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . $update_data->slug . '&section=changelog');
            } elseif (isset($update_data->url)) {
                $details_url = $update_data->url;
            } elseif (isset($plugin_data->PluginURI)) {
                $details_url = $plugin_data->PluginURI;
            }

            if (!empty($details_url)) {
                $details_url = add_query_arg(
                    array(
                        'TB_iframe' => 'true',
                        'width' => 600,
                        'height' => 800,
                    ),
                    $details_url
                );
            }

            $update_rows[] = array(
                'component' => 'plugin',
                'name' => isset($plugin_data->Name) ? $plugin_data->Name : $plugin_file,
                'installed_version' => $installed_version,
                'available_version' => $available_version,
                'is_security' => $is_security,
                'auto_update_enabled' => $auto_update_enabled,
                'details_url' => $details_url,
                'slug' => isset($update_data->slug) ? $update_data->slug : sanitize_title($plugin_file),
                'identifier' => $plugin_file,
            );
        }
    }

    if (is_array($theme_updates)) {
        foreach ($theme_updates as $stylesheet => $theme) {
            if (!isset($theme->update) || !is_array($theme->update)) {
                continue;
            }

            $update_data = $theme->update;
            $installed_version = $theme->get('Version');
            $available_version = isset($update_data['new_version']) ? $update_data['new_version'] : '';
            $is_security = !empty($update_data['security']);
            $auto_update_enabled = in_array($stylesheet, $auto_update_themes, true);

            $details_url = '';
            if (!empty($update_data['url'])) {
                $details_url = add_query_arg(
                    array(
                        'TB_iframe' => 'true',
                        'width' => 1024,
                        'height' => 800,
                    ),
                    $update_data['url']
                );
            } elseif ($theme->get('ThemeURI')) {
                $details_url = $theme->get('ThemeURI');
            }

            $update_rows[] = array(
                'component' => 'theme',
                'name' => $theme->get('Name'),
                'installed_version' => $installed_version,
                'available_version' => $available_version,
                'is_security' => $is_security,
                'auto_update_enabled' => $auto_update_enabled,
                'details_url' => $details_url,
                'slug' => $theme->get_stylesheet(),
                'identifier' => $stylesheet,
            );
        }
    }

    if (!empty($update_rows)) {
        usort($update_rows, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
    }
    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-maintenance');
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-update"></span> <?php esc_html_e('Conseiller de Maintenance', 'sitepulse'); ?></h1>
        <?php if (!$has_any_update_data) : ?>
            <div class="notice notice-error">
                <p><?php esc_html_e(
                    'Impossible de récupérer les informations de mise à jour. Vérifiez la connexion sortante de votre serveur et réessayez.',
                    'sitepulse'
                ); ?></p>
            </div>
        <?php else : ?>
            <div class="notice notice-info">
                <p><strong><?php esc_html_e('Mises à jour du Coeur WP :', 'sitepulse'); ?></strong> <?php echo esc_html($core_status); ?></p>
                <p><strong><?php esc_html_e('Mises à jour des Plugins :', 'sitepulse'); ?></strong> <?php echo esc_html($plugin_updates_count); ?> <?php esc_html_e('en attente', 'sitepulse'); ?></p>
                <p><strong><?php esc_html_e('Mises à jour des Thèmes :', 'sitepulse'); ?></strong> <?php echo esc_html($theme_updates_count); ?> <?php esc_html_e('en attente', 'sitepulse'); ?></p>
            </div>

            <p class="description"><?php esc_html_e('Recommandations : Faites une sauvegarde avant de mettre à jour, testez sur un site de pré-production.', 'sitepulse'); ?></p>

            <?php if (empty($update_rows)) : ?>
                <p><?php esc_html_e('Aucune mise à jour de plugins ou de thèmes n’est actuellement disponible.', 'sitepulse'); ?></p>
            <?php else : ?>
                <p id="sitepulse-update-table-description" class="description"><?php esc_html_e('Tableau listant les mises à jour disponibles. Cliquez sur l’entête d’une colonne pour trier les éléments.', 'sitepulse'); ?></p>
                <table id="sitepulse-update-table" class="wp-list-table widefat fixed striped" aria-describedby="sitepulse-update-table-description">
                    <caption class="screen-reader-text"><?php esc_html_e('Mises à jour disponibles pour les plugins et les thèmes installés', 'sitepulse'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col" data-sort-index="0" aria-sort="none">
                                <button type="button" class="sitepulse-sort-button" data-sort-announcement="<?php echo esc_attr__('Tri appliqué sur la colonne %s en ordre %order%', 'sitepulse'); ?>" data-sort-asc="<?php echo esc_attr__('croissant', 'sitepulse'); ?>" data-sort-desc="<?php echo esc_attr__('décroissant', 'sitepulse'); ?>"><?php esc_html_e('Nom', 'sitepulse'); ?></button>
                            </th>
                            <th scope="col" data-sort-index="1" aria-sort="none">
                                <button type="button" class="sitepulse-sort-button" data-sort-announcement="<?php echo esc_attr__('Tri appliqué sur la colonne %s en ordre %order%', 'sitepulse'); ?>" data-sort-asc="<?php echo esc_attr__('croissant', 'sitepulse'); ?>" data-sort-desc="<?php echo esc_attr__('décroissant', 'sitepulse'); ?>"><?php esc_html_e('Version installée', 'sitepulse'); ?></button>
                            </th>
                            <th scope="col" data-sort-index="2" aria-sort="none">
                                <button type="button" class="sitepulse-sort-button" data-sort-announcement="<?php echo esc_attr__('Tri appliqué sur la colonne %s en ordre %order%', 'sitepulse'); ?>" data-sort-asc="<?php echo esc_attr__('croissant', 'sitepulse'); ?>" data-sort-desc="<?php echo esc_attr__('décroissant', 'sitepulse'); ?>"><?php esc_html_e('Version disponible', 'sitepulse'); ?></button>
                            </th>
                            <th scope="col" data-sort-index="3" aria-sort="none">
                                <button type="button" class="sitepulse-sort-button" data-sort-announcement="<?php echo esc_attr__('Tri appliqué sur la colonne %s en ordre %order%', 'sitepulse'); ?>" data-sort-asc="<?php echo esc_attr__('croissant', 'sitepulse'); ?>" data-sort-desc="<?php echo esc_attr__('décroissant', 'sitepulse'); ?>"><?php esc_html_e('Type de mise à jour', 'sitepulse'); ?></button>
                            </th>
                            <th scope="col" data-sort-index="4" aria-sort="none">
                                <button type="button" class="sitepulse-sort-button" data-sort-announcement="<?php echo esc_attr__('Tri appliqué sur la colonne %s en ordre %order%', 'sitepulse'); ?>" data-sort-asc="<?php echo esc_attr__('croissant', 'sitepulse'); ?>" data-sort-desc="<?php echo esc_attr__('décroissant', 'sitepulse'); ?>"><?php esc_html_e('Auto-update', 'sitepulse'); ?></button>
                            </th>
                            <th scope="col" aria-sort="none"><?php esc_html_e('Actions', 'sitepulse'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($update_rows as $row) : ?>
                            <?php
                            $component_label = 'plugin' === $row['component']
                                ? __('Plugin', 'sitepulse')
                                : __('Thème', 'sitepulse');
                            $name_for_attr = wp_strip_all_tags($row['name']);
                            $type_label = $row['is_security'] ? __('Sécurité', 'sitepulse') : __('Majeure', 'sitepulse');
                            $type_key = $row['is_security'] ? 'security' : 'major';
                            $auto_update_label = $row['auto_update_enabled'] ? __('Activé', 'sitepulse') : __('Désactivé', 'sitepulse');
                            $actions = array();

                            if (!empty($row['details_url'])) {
                                $actions[] = sprintf(
                                    '<a href="%1$s" class="sitepulse-action-link thickbox" aria-label="%2$s">%3$s</a>',
                                    esc_url($row['details_url']),
                                    esc_attr(sprintf(__('Voir les détails pour %s', 'sitepulse'), $name_for_attr)),
                                    esc_html__('Voir détails', 'sitepulse')
                                );
                            }

                            $can_manage_auto_update = 'plugin' === $row['component']
                                ? current_user_can('update_plugins')
                                : current_user_can('update_themes');

                            if ($can_manage_auto_update && !$row['auto_update_enabled']) {
                                if ('plugin' === $row['component']) {
                                    $toggle_url = wp_nonce_url(
                                        add_query_arg(
                                            array(
                                                'action' => 'enable-auto-update',
                                                'plugin' => $row['identifier'],
                                            ),
                                            admin_url('plugins.php')
                                        ),
                                        'updates'
                                    );
                                } else {
                                    $toggle_url = wp_nonce_url(
                                        add_query_arg(
                                            array(
                                                'action' => 'enable-auto-update',
                                                'theme' => $row['identifier'],
                                            ),
                                            admin_url('themes.php')
                                        ),
                                        'updates'
                                    );
                                }

                                $actions[] = sprintf(
                                    '<a href="%1$s" class="sitepulse-action-link" aria-label="%2$s">%3$s</a>',
                                    esc_url($toggle_url),
                                    esc_attr(sprintf(__('Activer l’auto-update pour %s', 'sitepulse'), $name_for_attr)),
                                    esc_html__('Activer auto-update', 'sitepulse')
                                );
                            }
                            ?>
                            <tr>
                                <td data-sort-value="<?php echo esc_attr($name_for_attr); ?>">
                                    <span class="sitepulse-component-badge"><?php echo esc_html($component_label); ?></span>
                                    <span><?php echo esc_html($row['name']); ?></span>
                                </td>
                                <td data-sort-value="<?php echo esc_attr($row['installed_version']); ?>">
                                    <?php echo esc_html($row['installed_version']); ?>
                                </td>
                                <td data-sort-value="<?php echo esc_attr($row['available_version']); ?>">
                                    <?php echo esc_html($row['available_version']); ?>
                                </td>
                                <td data-sort-value="<?php echo esc_attr($type_key); ?>">
                                    <?php echo esc_html($type_label); ?>
                                </td>
                                <td data-sort-value="<?php echo esc_attr($row['auto_update_enabled'] ? '1' : '0'); ?>">
                                    <?php echo esc_html($auto_update_label); ?>
                                </td>
                                <td>
                                    <?php if (!empty($actions)) : ?>
                                        <div class="sitepulse-action-list">
                                            <?php echo wp_kses_post(implode(' | ', $actions)); ?>
                                        </div>
                                    <?php else : ?>
                                        <span class="description"><?php esc_html_e('Aucune action disponible', 'sitepulse'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div id="sitepulse-update-sort-status" class="screen-reader-text" aria-live="polite"></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <style>
        .sitepulse-update-table .sitepulse-component-badge {
            display: inline-block;
            margin-right: 0.5em;
            padding: 0.1em 0.6em;
            background: #f0f0f1;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .sitepulse-sort-button {
            background: none;
            border: 0;
            color: inherit;
            cursor: pointer;
            font: inherit;
            padding: 0;
        }

        .sitepulse-sort-button:focus {
            outline: 2px solid #2271b1;
            outline-offset: 2px;
        }

        .sitepulse-action-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5em;
            align-items: center;
        }

        .sitepulse-action-link {
            text-decoration: none;
        }

        .sitepulse-action-link:hover,
        .sitepulse-action-link:focus {
            text-decoration: underline;
        }
    </style>
    <script>
        (function () {
            const table = document.getElementById('sitepulse-update-table');
            if (!table) {
                return;
            }

            const headers = table.querySelectorAll('th[data-sort-index]');
            const statusRegion = document.getElementById('sitepulse-update-sort-status');
            const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

            headers.forEach((header) => {
                const button = header.querySelector('.sitepulse-sort-button');
                if (!button) {
                    return;
                }

                button.addEventListener('click', () => {
                    const columnIndex = parseInt(header.getAttribute('data-sort-index'), 10);
                    const currentSort = header.getAttribute('aria-sort');
                    const newSort = currentSort === 'ascending' ? 'descending' : 'ascending';

                    headers.forEach((otherHeader) => {
                        if (otherHeader !== header) {
                            otherHeader.setAttribute('aria-sort', 'none');
                        }
                    });

                    header.setAttribute('aria-sort', newSort);

                    const rows = Array.from(table.tBodies[0].rows);
                    rows.sort((rowA, rowB) => {
                        const cellA = rowA.cells[columnIndex];
                        const cellB = rowB.cells[columnIndex];
                        const valueA = (cellA.getAttribute('data-sort-value') || cellA.textContent).trim();
                        const valueB = (cellB.getAttribute('data-sort-value') || cellB.textContent).trim();
                        const comparison = collator.compare(valueA, valueB);
                        return newSort === 'ascending' ? comparison : comparison * -1;
                    });

                    rows.forEach((row) => {
                        table.tBodies[0].appendChild(row);
                    });

                    if (statusRegion) {
                        const template = button.getAttribute('data-sort-announcement') || '%s %order%';
                        const ascLabel = button.getAttribute('data-sort-asc') || '';
                        const descLabel = button.getAttribute('data-sort-desc') || '';
                        const orderLabel = newSort === 'ascending' ? ascLabel : descLabel;
                        statusRegion.textContent = template
                            .replace('%s', button.textContent.trim())
                            .replace('%order%', orderLabel);
                    }
                });
            });
        })();
    </script>
    <?php
}
