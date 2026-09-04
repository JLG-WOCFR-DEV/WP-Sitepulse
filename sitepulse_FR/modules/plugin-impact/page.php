<?php
/**
 * SitePulse Plugin Impact admin page.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

function sitepulse_plugin_impact_scanner_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();
    $active_plugin_files = get_option('active_plugins', []);
    $network_active_plugins = get_site_option('active_sitewide_plugins', []);

    if (!is_array($active_plugin_files)) {
        $active_plugin_files = [];
    }

    if (!is_array($network_active_plugins)) {
        $network_active_plugins = [];
    }

    $network_plugin_files = array_keys($network_active_plugins);

    $active_plugin_files = array_values(
        array_unique(
            array_merge($active_plugin_files, $network_plugin_files)
        )
    );

    $measurements = sitepulse_plugin_impact_get_measurements();

    if (!empty($_POST[SITEPULSE_ACTION_PLUGIN_IMPACT_REFRESH])) {
        check_admin_referer(SITEPULSE_ACTION_PLUGIN_IMPACT_REFRESH);

        if (function_exists('sitepulse_plugin_impact_force_next_persist')) {
            sitepulse_plugin_impact_force_next_persist(true);
        } else {
            if (defined('SITEPULSE_PLUGIN_IMPACT_OPTION')) {
                delete_option(SITEPULSE_PLUGIN_IMPACT_OPTION);
            }
        }

        add_settings_error(
            'sitepulse_plugin_impact',
            'sitepulse_plugin_impact_refreshed',
            esc_html__("Une nouvelle série de mesures sera enregistrée à la fin de cette requête.", 'sitepulse'),
            'updated'
        );
    }

    $samples = isset($measurements['samples']) && is_array($measurements['samples']) ? $measurements['samples'] : [];
    $default_interval = defined('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL') ? SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL : 15 * MINUTE_IN_SECONDS;
    $last_updated = isset($measurements['last_updated']) ? (int) $measurements['last_updated'] : 0;
    $interval = isset($measurements['interval']) ? max(1, (int) $measurements['interval']) : $default_interval;

    $current_time = current_time('timestamp');
    $next_refresh = $last_updated > 0 ? $last_updated + $interval : 0;

    $impacts = [];
    $total_impact = 0.0;
    $measured_count = 0;

    foreach ($active_plugin_files as $plugin_file) {
        $plugin_data = isset($all_plugins[$plugin_file]) && is_array($all_plugins[$plugin_file])
            ? $all_plugins[$plugin_file]
            : null;

        if ($plugin_data === null) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

            if (is_readable($plugin_path)) {
                $plugin_data = get_plugin_data($plugin_path, false, false);
            } else {
                $plugin_data = [];
            }
        }

        $plugin_name = isset($plugin_data['Name']) && $plugin_data['Name'] !== '' ? $plugin_data['Name'] : $plugin_file;

        $plugin_dir = dirname($plugin_file);

        $disk_space_status = 'complete';

        if ($plugin_dir === '.' || $plugin_dir === '') {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            $disk_space = is_file($plugin_path) && is_readable($plugin_path) ? filesize($plugin_path) : 0;
            $disk_space_files = null;
            $disk_space_generated_at = null;
        } else {
            $dir_size = sitepulse_get_dir_size_with_cache(WP_PLUGIN_DIR . '/' . $plugin_dir);
            $disk_space = isset($dir_size['size']) ? (int) $dir_size['size'] : 0;
            $disk_space_status = isset($dir_size['status']) ? $dir_size['status'] : 'complete';
            $disk_space_files = isset($dir_size['files']) && is_numeric($dir_size['files'])
                ? max(0, (int) $dir_size['files'])
                : null;
            $disk_space_generated_at = isset($dir_size['generated_at']) ? (int) $dir_size['generated_at'] : null;
        }

        $is_network_active = function_exists('is_plugin_active_for_network')
            ? is_plugin_active_for_network($plugin_file)
            : false;
        $is_site_active = function_exists('is_plugin_active') ? is_plugin_active($plugin_file) : true;

        $impact_data = [
            'file'              => $plugin_file,
            'name'              => $plugin_name,
            'impact'            => null,
            'last_ms'           => null,
            'samples'           => 0,
            'last_recorded'     => null,
            'disk_space'        => $disk_space,
            'disk_space_status' => $disk_space_status,
            'disk_space_files'  => $disk_space_files,
            'disk_space_recorded' => $disk_space_generated_at,
            'is_active'         => ($is_network_active || $is_site_active),
            'is_network_active' => $is_network_active,
            'plugin_uri'        => isset($plugin_data['PluginURI']) ? $plugin_data['PluginURI'] : '',
            'slug'              => sitepulse_plugin_impact_guess_slug($plugin_file, $plugin_data),
        ];

        if (isset($samples[$plugin_file]) && is_array($samples[$plugin_file])) {
            $sample = $samples[$plugin_file];

            if (isset($sample['avg_ms'])) {
                $impact_data['impact'] = max(0.0, (float) $sample['avg_ms']);
            }

            if (isset($sample['last_ms'])) {
                $impact_data['last_ms'] = max(0.0, (float) $sample['last_ms']);
            }

            if (isset($sample['samples'])) {
                $impact_data['samples'] = max(0, (int) $sample['samples']);
            }

            if (isset($sample['last_recorded'])) {
                $impact_data['last_recorded'] = (int) $sample['last_recorded'];
            }
        }

        if ($impact_data['impact'] !== null) {
            $total_impact += $impact_data['impact'];
            $measured_count++;
        }

        $impacts[$plugin_file] = $impact_data;
    }

    $history = sitepulse_plugin_impact_get_history();
    $history_plugins = isset($history['plugins']) && is_array($history['plugins']) ? $history['plugins'] : [];

    foreach ($impacts as $plugin_file => &$impact_data) {
        $history_entries = isset($history_plugins[$plugin_file]) && is_array($history_plugins[$plugin_file])
            ? $history_plugins[$plugin_file]
            : [];

        $trend = sitepulse_plugin_impact_calculate_trend($history_entries, $impact_data['impact'], $current_time);
        $impact_data['trend'] = $trend;
        $impact_data['trend_label'] = sitepulse_plugin_impact_format_trend_label($trend);
        $impact_data['average_7d'] = isset($trend['average_7d']) ? $trend['average_7d'] : null;
        $impact_data['average_30d'] = isset($trend['average_30d']) ? $trend['average_30d'] : null;
    }
    unset($impact_data);

    uasort(
        $impacts,
        function ($a, $b) {
            $a_measured = $a['impact'] !== null;
            $b_measured = $b['impact'] !== null;

            if ($a_measured && $b_measured) {
                if ($a['impact'] === $b['impact']) {
                    return strcasecmp($a['name'], $b['name']);
                }

                return $b['impact'] <=> $a['impact'];
            }

            if ($a_measured) {
                return -1;
            }

            if ($b_measured) {
                return 1;
            }

            return strcasecmp($a['name'], $b['name']);
        }
    );

    $total_plugins = count($impacts);

    $coverage_text = sprintf(
        /* translators: 1: measured plugins count, 2: total active plugins count. */
        __('Plugins chronométrés : %1$d sur %2$d.', 'sitepulse'),
        (int) $measured_count,
        (int) $total_plugins
    );

    if ($last_updated > 0) {
        $display_timestamp = sitepulse_plugin_impact_normalize_timestamp_for_display($last_updated);
        $format = get_option('date_format') . ' ' . get_option('time_format');
        $formatted_date = function_exists('wp_date')
            ? wp_date($format, $display_timestamp)
            : date_i18n($format, $display_timestamp, true);

        $relative_date = sprintf(
            /* translators: %s: human time diff. */
            __('il y a %s', 'sitepulse'),
            human_time_diff($last_updated, $current_time)
        );

        $last_updated_text = sprintf(
            /* translators: 1: formatted datetime, 2: human readable diff. */
            __('Dernière actualisation : %1$s (%2$s).', 'sitepulse'),
            $formatted_date,
            $relative_date
        );
    } else {
        $last_updated_text = __('Dernière actualisation : aucune donnée collectée pour le moment.', 'sitepulse');
    }

    if ($next_refresh > $current_time) {
        $refresh_text = sprintf(
            __('Prochain échantillonnage automatique possible dans %s.', 'sitepulse'),
            human_time_diff($current_time, $next_refresh)
        );
    } else {
        $refresh_text = __('Les mesures seront mises à jour à la fin du prochain chargement de page.', 'sitepulse');
    }

    $interval_text = sprintf(
        __('Intervalle de rafraîchissement : %s maximum.', 'sitepulse'),
        sitepulse_plugin_impact_format_interval($interval)
    );
    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-plugins');
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-filter"></span> <?php esc_html_e("Analyseur d'Impact des Plugins", 'sitepulse'); ?></h1>

        <?php settings_errors('sitepulse_plugin_impact'); ?>

        <p><?php esc_html_e('Les temps affichés ci-dessous proviennent du chronométrage réel du chargement de chaque plugin actif.', 'sitepulse'); ?></p>

        <div class="notice notice-info sitepulse-impact-meta">
            <p><?php echo esc_html($last_updated_text); ?></p>
            <p><?php echo esc_html($interval_text); ?></p>
            <p><?php echo esc_html($refresh_text); ?></p>
            <p><?php echo esc_html($coverage_text); ?></p>
        </div>

        <p><?php esc_html_e('Limitations connues :', 'sitepulse'); ?></p>
        <ul class="sitepulse-impact-limitations">
            <li><?php esc_html_e('les mesures correspondent au temps écoulé entre le chargement de deux plugins consécutifs via le hook « plugin_loaded » ; elles reflètent donc l’impact relatif sur la phase de bootstrap.', 'sitepulse'); ?></li>
            <li><?php esc_html_e('les plugins chargés avant SitePulse ne peuvent pas être chronométrés directement et apparaissent comme « non mesurés » tant que leur ordre de chargement n’est pas modifié.', 'sitepulse'); ?></li>
            <li><?php esc_html_e('les valeurs sont moyennées pour lisser les variations ponctuelles ; les caches d’opcode peuvent réduire artificiellement certaines durées.', 'sitepulse'); ?></li>
        </ul>

        <form method="post" class="sitepulse-impact-refresh">
            <?php wp_nonce_field(SITEPULSE_ACTION_PLUGIN_IMPACT_REFRESH); ?>
            <?php submit_button(__('Forcer un nouvel échantillon maintenant', 'sitepulse'), 'secondary', SITEPULSE_ACTION_PLUGIN_IMPACT_REFRESH, false); ?>
        </form>

        <div class="sitepulse-impact-table-wrapper">
            <div class="sitepulse-impact-controls" data-sitepulse-impact-controls>
                <div class="sitepulse-impact-controls__group">
                    <label for="sitepulse-impact-sort" class="screen-reader-text"><?php esc_html_e('Choisir un tri', 'sitepulse'); ?></label>
                    <select id="sitepulse-impact-sort" class="sitepulse-impact-controls__select" data-sitepulse-impact-sort>
                        <option value="impact-desc"><?php esc_html_e('Tri : impact décroissant', 'sitepulse'); ?></option>
                        <option value="impact-asc"><?php esc_html_e('Tri : impact croissant', 'sitepulse'); ?></option>
                        <option value="weight-desc"><?php esc_html_e('Tri : poids décroissant', 'sitepulse'); ?></option>
                        <option value="name-asc"><?php esc_html_e('Tri : nom (A → Z)', 'sitepulse'); ?></option>
                    </select>
                </div>
                <div class="sitepulse-impact-controls__group">
                    <label for="sitepulse-impact-weight-min"><?php esc_html_e('Poids min (%)', 'sitepulse'); ?></label>
                    <input type="number" id="sitepulse-impact-weight-min" class="sitepulse-impact-controls__input" min="0" max="100" step="0.1" data-sitepulse-impact-weight-min />
                </div>
                <div class="sitepulse-impact-controls__group">
                    <label for="sitepulse-impact-weight-max"><?php esc_html_e('Poids max (%)', 'sitepulse'); ?></label>
                    <input type="number" id="sitepulse-impact-weight-max" class="sitepulse-impact-controls__input" min="0" max="100" step="0.1" data-sitepulse-impact-weight-max />
                </div>
                <div class="sitepulse-impact-controls__group sitepulse-impact-controls__group--buttons">
                    <button type="button" class="button" data-sitepulse-impact-reset><?php esc_html_e('Réinitialiser', 'sitepulse'); ?></button>
                    <button type="button" class="button button-primary" data-sitepulse-impact-export><?php esc_html_e('Exporter CSV', 'sitepulse'); ?></button>
                </div>
            </div>
            <table class="wp-list-table widefat striped" data-sitepulse-impact-table>
                <thead>
                    <tr>
                        <th scope="col" style="width: 25%;"><?php esc_html_e('Plugin', 'sitepulse'); ?></th>
                        <th scope="col"><?php esc_html_e('Durée mesurée', 'sitepulse'); ?></th>
                        <th scope="col"><?php esc_html_e('Espace disque', 'sitepulse'); ?></th>
                        <th scope="col" style="width: 35%;"><?php esc_html_e('Poids relatif', 'sitepulse'); ?></th>
                        <th scope="col" class="column-actions"><?php esc_html_e('Actions rapides', 'sitepulse'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($impacts)) : ?>
                        <tr><td colspan="5"><?php esc_html_e('Aucun plugin actif à analyser.', 'sitepulse'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($impacts as $data) :
                        $weight = ($total_impact > 0 && $data['impact'] !== null) ? ($data['impact'] / $total_impact) * 100 : null;
                        $weight_color = '#81C784';

                        if (is_numeric($weight)) {
                            if ($weight > 20) {
                                $weight_color = '#E57373';
                            } elseif ($weight > 10) {
                                $weight_color = '#FAD768';
                            }
                        }

                        $impact_lines = [];

                        $trend_label = isset($data['trend_label']) ? (string) $data['trend_label'] : '';
                        $average_7d = isset($data['average_7d']) && is_numeric($data['average_7d']) ? (float) $data['average_7d'] : null;
                        $average_30d = isset($data['average_30d']) && is_numeric($data['average_30d']) ? (float) $data['average_30d'] : null;

                        if ($data['impact'] !== null) {
                            $impact_lines[] = sprintf(
                                /* translators: %s: duration in milliseconds */
                                __('Moyenne glissante : %s ms', 'sitepulse'),
                                number_format_i18n($data['impact'], 2)
                            );

                            $last_value = $data['last_ms'] !== null ? $data['last_ms'] : $data['impact'];
                            $impact_lines[] = sprintf(
                                __('Dernière mesure : %s ms', 'sitepulse'),
                                number_format_i18n($last_value, 2)
                            );

                            if ($data['last_recorded']) {
                                $impact_lines[] = sprintf(
                                    __('Enregistré il y a %s', 'sitepulse'),
                                    human_time_diff($data['last_recorded'], $current_time)
                                );
                            }

                            $impact_lines[] = sprintf(
                                __('Nombre d’échantillons : %d', 'sitepulse'),
                                max(1, (int) $data['samples'])
                            );

                            if ($trend_label !== '') {
                                $impact_lines[] = $trend_label;
                            }

                            if ($average_7d !== null) {
                                $impact_lines[] = sprintf(
                                    __('Moyenne 7 jours : %s ms', 'sitepulse'),
                                    number_format_i18n($average_7d, 2)
                                );
                            }

                            if ($average_30d !== null) {
                                $impact_lines[] = sprintf(
                                    __('Moyenne 30 jours : %s ms', 'sitepulse'),
                                    number_format_i18n($average_30d, 2)
                                );
                            }
                        } else {
                            $impact_lines[] = __('Non mesuré pour le moment.', 'sitepulse');
                        }

                        $impact_output = implode('<br />', array_map('esc_html', $impact_lines));

                        $weight_value = $weight !== null ? number_format((float) $weight, 4, '.', '') : '';
                        $impact_value = $data['impact'] !== null ? number_format((float) $data['impact'], 4, '.', '') : '';
                        $last_value = $data['last_ms'] !== null ? number_format((float) $data['last_ms'], 4, '.', '') : '';
                        $samples_value = number_format((float) max(0, (int) $data['samples']), 0, '.', '');
                        $disk_space_value = number_format((float) $data['disk_space'], 0, '.', '');
                        $last_recorded_value = $data['last_recorded'] ? (int) $data['last_recorded'] : '';
                        $trend_direction = isset($data['trend']['direction']) ? (string) $data['trend']['direction'] : 'none';
                        $trend_delta_ms = isset($data['trend']['change_ms']) && is_numeric($data['trend']['change_ms'])
                            ? number_format((float) $data['trend']['change_ms'], 4, '.', '')
                            : '';
                        $trend_delta_pct = isset($data['trend']['change_pct']) && is_numeric($data['trend']['change_pct'])
                            ? number_format((float) $data['trend']['change_pct'], 4, '.', '')
                            : '';
                        $average_7d_value = $average_7d !== null ? number_format($average_7d, 4, '.', '') : '';
                        $average_30d_value = $average_30d !== null ? number_format($average_30d, 4, '.', '') : '';
                        $disk_space_files = isset($data['disk_space_files']) ? $data['disk_space_files'] : null;
                        $disk_space_recorded = isset($data['disk_space_recorded']) ? (int) $data['disk_space_recorded'] : 0;
                        $plugin_slug = $data['slug'];
                        $plugin_uri = $data['plugin_uri'];
                        $is_active = !empty($data['is_active']);
                        $is_network_active = !empty($data['is_network_active']);

                        $deactivate_url = '';
                        $deactivate_label = $is_network_active
                            ? __('Désactiver sur le réseau', 'sitepulse')
                            : __('Désactiver', 'sitepulse');

                        if ($is_active) {
                            $base_url = $is_network_active ? network_admin_url('plugins.php') : admin_url('plugins.php');
                            $deactivate_args = [
                                'action'        => 'deactivate',
                                'plugin'        => $data['file'],
                                'plugin_status' => 'all',
                            ];

                            if ($is_network_active) {
                                $deactivate_args['networkwide'] = 1;
                            }

                            $deactivate_url = add_query_arg($deactivate_args, $base_url);
                            $deactivate_url = wp_nonce_url($deactivate_url, 'deactivate-plugin_' . $data['file']);
                        }

                        $plugin_card_url = '';

                        if ($plugin_slug !== '') {
                            $plugin_card_url = add_query_arg(
                                [
                                    'tab'         => 'plugin-information',
                                    'plugin'      => $plugin_slug,
                                    'TB_iframe'   => 'true',
                                    'width'       => 600,
                                    'height'      => 550,
                                ],
                                self_admin_url('plugin-install.php')
                            );
                        }
                    ?>
                        <tr
                            data-plugin-file="<?php echo esc_attr($data['file']); ?>"
                            data-plugin-name="<?php echo esc_attr($data['name']); ?>"
                            data-impact="<?php echo esc_attr($impact_value); ?>"
                            data-last-ms="<?php echo esc_attr($last_value); ?>"
                            data-weight="<?php echo esc_attr($weight_value); ?>"
                            data-samples="<?php echo esc_attr($samples_value); ?>"
                            data-disk-space="<?php echo esc_attr($disk_space_value); ?>"
                            data-disk-files="<?php echo esc_attr($disk_space_files !== null ? (int) $disk_space_files : ''); ?>"
                            data-disk-recorded="<?php echo esc_attr($disk_space_recorded ? $disk_space_recorded : ''); ?>"
                            data-last-recorded="<?php echo esc_attr($last_recorded_value); ?>"
                            data-is-measured="<?php echo $data['impact'] !== null ? '1' : '0'; ?>"
                            data-trend-direction="<?php echo esc_attr($trend_direction); ?>"
                            data-trend-delta-ms="<?php echo esc_attr($trend_delta_ms); ?>"
                            data-trend-delta-pct="<?php echo esc_attr($trend_delta_pct); ?>"
                            data-average-7d="<?php echo esc_attr($average_7d_value); ?>"
                            data-average-30d="<?php echo esc_attr($average_30d_value); ?>"
                        >
                            <td data-colname="<?php echo esc_attr__('Plugin', 'sitepulse'); ?>"><strong><?php echo esc_html($data['name']); ?></strong></td>
                            <td data-colname="<?php echo esc_attr__('Durée mesurée', 'sitepulse'); ?>"><?php echo $impact_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td data-colname="<?php echo esc_attr__('Espace disque', 'sitepulse'); ?>">
                                <?php
                                if (isset($data['disk_space_status']) && $data['disk_space_status'] === 'pending') {
                                    echo esc_html__('en cours…', 'sitepulse');
                                } else {
                                    $disk_space_lines = [wp_kses_post(size_format((float) $data['disk_space'], 2))];

                                    if ($disk_space_files !== null) {
                                        $disk_space_lines[] = esc_html(
                                            sprintf(
                                                _n('%s fichier', '%s fichiers', $disk_space_files, 'sitepulse'),
                                                number_format_i18n($disk_space_files)
                                            )
                                        );
                                    }

                                    if ($disk_space_recorded > 0) {
                                        $disk_space_lines[] = esc_html(
                                            sprintf(
                                                /* translators: %s: human time difference */
                                                __('Mesuré il y a %s', 'sitepulse'),
                                                human_time_diff($disk_space_recorded, $current_time)
                                            )
                                        );
                                    }

                                    echo implode('<br />', $disk_space_lines); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                }
                                ?>
                            </td>
                            <td data-colname="<?php echo esc_attr__('Poids relatif', 'sitepulse'); ?>">
                                <?php if ($weight !== null) : ?>
                                    <div class="impact-bar-bg">
                                        <div class="impact-bar" style="width: <?php echo esc_attr(min(100, $weight)); ?>%; background-color: <?php echo esc_attr($weight_color); ?>;">
                                            <?php echo esc_html(number_format_i18n($weight, 1)); ?>%
                                        </div>
                                    </div>
                                <?php else : ?>
                                    <em><?php esc_html_e('n/d', 'sitepulse'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td data-colname="<?php echo esc_attr__('Actions rapides', 'sitepulse'); ?>">
                                <div class="sitepulse-impact-actions">
                                    <?php if ($deactivate_url !== '') : ?>
                                        <a class="button button-small" href="<?php echo esc_url($deactivate_url); ?>" data-action="deactivate">
                                            <?php echo esc_html($deactivate_label); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($plugin_card_url !== '') : ?>
                                        <a class="button button-small" href="<?php echo esc_url($plugin_card_url); ?>" data-action="details" target="_blank" rel="noopener noreferrer">
                                            <?php esc_html_e('Fiche plugin', 'sitepulse'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($plugin_uri)) : ?>
                                        <a class="button button-small" href="<?php echo esc_url($plugin_uri); ?>" data-action="docs" target="_blank" rel="noopener noreferrer">
                                            <?php esc_html_e('Documentation', 'sitepulse'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
