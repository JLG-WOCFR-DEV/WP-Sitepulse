<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action(
    'admin_menu',
    function () {
        add_submenu_page(
            'sitepulse-dashboard',
            __('Plugin Impact Scanner', 'sitepulse'),
            __('Plugin Impact', 'sitepulse'),
            sitepulse_get_capability(),
            'sitepulse-plugins',
            'sitepulse_plugin_impact_scanner_page'
        );
    }
);

add_action('admin_enqueue_scripts', 'sitepulse_plugin_impact_enqueue_assets');

/**
 * Enqueues styles for the plugin impact scanner admin screen.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_plugin_impact_enqueue_assets($hook_suffix) {
    if ($hook_suffix !== 'sitepulse-dashboard_page_sitepulse-plugins') {
        return;
    }

    wp_enqueue_style(
        'sitepulse-plugin-impact',
        SITEPULSE_URL . 'modules/css/plugin-impact-scanner.css',
        [],
        SITEPULSE_VERSION
    );

    wp_enqueue_script(
        'sitepulse-plugin-impact',
        SITEPULSE_URL . 'modules/js/plugin-impact-scanner.js',
        [],
        SITEPULSE_VERSION,
        true
    );

    $default_thresholds = function_exists('sitepulse_get_default_plugin_impact_thresholds')
        ? sitepulse_get_default_plugin_impact_thresholds()
        : [
            'impactWarning'  => 30.0,
            'impactCritical' => 60.0,
            'weightWarning'  => 10.0,
            'weightCritical' => 20.0,
            'trendWarning'   => 15.0,
            'trendCritical'  => 40.0,
        ];

    $stored_thresholds = [
        'default' => $default_thresholds,
        'roles'   => [],
    ];

    if (defined('SITEPULSE_OPTION_IMPACT_THRESHOLDS')) {
        $option_value = get_option(
            SITEPULSE_OPTION_IMPACT_THRESHOLDS,
            [
                'default' => $default_thresholds,
                'roles'   => [],
            ]
        );

        if (is_array($option_value)) {
            $stored_thresholds = $option_value;
        }
    }

    if (function_exists('sitepulse_sanitize_impact_thresholds')) {
        $stored_thresholds = sitepulse_sanitize_impact_thresholds($stored_thresholds);
    }

    $effective_thresholds = isset($stored_thresholds['default']) && is_array($stored_thresholds['default'])
        ? $stored_thresholds['default']
        : $default_thresholds;

    if (isset($stored_thresholds['roles']) && is_array($stored_thresholds['roles'])) {
        $current_user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;

        if ($current_user instanceof WP_User) {
            foreach ((array) $current_user->roles as $role) {
                $role_key = sanitize_key($role);

                if ($role_key !== '' && isset($stored_thresholds['roles'][$role_key])) {
                    $effective_thresholds = $stored_thresholds['roles'][$role_key];
                    break;
                }
            }
        }
    }

    $thresholds = apply_filters('sitepulse_plugin_impact_highlight_thresholds', $effective_thresholds);

    if (function_exists('sitepulse_normalize_impact_threshold_set')) {
        $thresholds = sitepulse_normalize_impact_threshold_set($thresholds, $default_thresholds);
    } else {
        if (!is_array($thresholds)) {
            $thresholds = $default_thresholds;
        }

        $thresholds = wp_parse_args($thresholds, $default_thresholds);

        foreach ($thresholds as $key => $value) {
            $thresholds[$key] = is_numeric($value) ? (float) $value : $default_thresholds[$key];
        }
    }

    wp_localize_script(
        'sitepulse-plugin-impact',
        'sitepulsePluginImpactScanner',
        [
            'thresholds' => $thresholds,
            'i18n'       => [
                'sortImpactDesc' => esc_html__('Tri : impact décroissant', 'sitepulse'),
                'sortImpactAsc'  => esc_html__('Tri : impact croissant', 'sitepulse'),
                'sortNameAsc'    => esc_html__('Tri : nom (A → Z)', 'sitepulse'),
                'sortWeightDesc' => esc_html__('Tri : poids décroissant', 'sitepulse'),
                'weightMinLabel' => esc_html__('Poids min (%)', 'sitepulse'),
                'weightMaxLabel' => esc_html__('Poids max (%)', 'sitepulse'),
                'resetFilters'   => esc_html__('Réinitialiser', 'sitepulse'),
                'exportCsv'      => esc_html__('Exporter CSV', 'sitepulse'),
                'noResult'       => esc_html__('Aucun plugin ne correspond aux filtres.', 'sitepulse'),
                'fileName'       => esc_html__('sitepulse-plugin-impact.csv', 'sitepulse'),
            ],
        ]
    );
}

add_action('upgrader_process_complete', 'sitepulse_plugin_impact_clear_dir_cache_on_upgrade', 10, 2);
add_action('sitepulse_queue_plugin_dir_scan', 'sitepulse_process_plugin_dir_scan_queue');

if (!defined('SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION')) {
    define('SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION', 'sitepulse_plugin_dir_scan_queue');
}

function sitepulse_plugin_impact_clear_dir_cache_on_upgrade($upgrader, $hook_extra) {
    if (!is_array($hook_extra) || !isset($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return;
    }

    if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        return;
    }

    $plugin_files = [];

    if (isset($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
        foreach ($hook_extra['plugins'] as $plugin_file) {
            if (is_string($plugin_file) && $plugin_file !== '') {
                $plugin_files[] = $plugin_file;
            }
        }
    } elseif (isset($hook_extra['plugin']) && is_string($hook_extra['plugin']) && $hook_extra['plugin'] !== '') {
        $plugin_files[] = $hook_extra['plugin'];
    }

    if (empty($plugin_files)) {
        if (function_exists('sitepulse_delete_transients_by_prefix')) {
            sitepulse_delete_transients_by_prefix(SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX);
        }

        if (function_exists('sitepulse_delete_site_transients_by_prefix')) {
            sitepulse_delete_site_transients_by_prefix(SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX);
        }

        return;
    }

    $plugin_files = array_unique($plugin_files);

    foreach ($plugin_files as $plugin_file) {
        $plugin_dir = dirname($plugin_file);

        if ($plugin_dir === '.' || $plugin_dir === '' || $plugin_dir === DIRECTORY_SEPARATOR) {
            continue;
        }

        $plugin_dir_path = WP_PLUGIN_DIR . '/' . $plugin_dir;

        sitepulse_clear_dir_size_cache($plugin_dir_path);

        if (is_multisite()) {
            $site_ids = function_exists('get_sites')
                ? get_sites([
                    'fields' => 'ids',
                    'number' => 0,
                    'no_found_rows' => true,
                ])
                : [];

            if (!empty($site_ids) && defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
                $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($plugin_dir_path);

                foreach ($site_ids as $site_id) {
                    $site_id = (int) $site_id;

                    if ($site_id <= 0) {
                        continue;
                    }

                    $switched = switch_to_blog($site_id);

                    if (!$switched) {
                        continue;
                    }

                    delete_transient($transient_key);
                    restore_current_blog();
                }
            }
        }
    }
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

function sitepulse_plugin_impact_get_measurements() {
    if (!defined('SITEPULSE_PLUGIN_IMPACT_OPTION')) {
        return [
            'last_updated' => 0,
            'interval'     => defined('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL') ? SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL : 15 * MINUTE_IN_SECONDS,
            'samples'      => [],
        ];
    }

    $data = get_option(SITEPULSE_PLUGIN_IMPACT_OPTION, []);

    if (!is_array($data)) {
        $data = [];
    }

    $default_interval = defined('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL') ? SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL : 15 * MINUTE_IN_SECONDS;

    return [
        'last_updated' => isset($data['last_updated']) ? (int) $data['last_updated'] : 0,
        'interval'     => isset($data['interval']) ? max(1, (int) $data['interval']) : $default_interval,
        'samples'      => isset($data['samples']) && is_array($data['samples']) ? $data['samples'] : [],
    ];
}

/**
 * Retrieves the persisted plugin impact history.
 *
 * @return array<string,mixed>
 */
function sitepulse_plugin_impact_get_history() {
    if (!defined('SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY')) {
        return [
            'updated_at' => 0,
            'plugins'    => [],
        ];
    }

    $stored = get_option(SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY, []);

    if (!is_array($stored)) {
        $stored = [];
    }

    $updated_at = isset($stored['updated_at']) ? (int) $stored['updated_at'] : 0;
    $plugins = [];

    if (isset($stored['plugins']) && is_array($stored['plugins'])) {
        foreach ($stored['plugins'] as $plugin_file => $entries) {
            if (!is_string($plugin_file) || $plugin_file === '' || !is_array($entries)) {
                continue;
            }

            $normalized = [];

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                $average = isset($entry['avg_ms']) ? (float) $entry['avg_ms'] : null;

                if ($timestamp <= 0 || $average === null || !is_numeric($average)) {
                    continue;
                }

                $normalized[$timestamp] = [
                    'timestamp' => $timestamp,
                    'avg_ms'    => max(0.0, (float) $average),
                ];

                if (isset($entry['samples']) && is_numeric($entry['samples'])) {
                    $normalized[$timestamp]['samples'] = max(0, (int) $entry['samples']);
                }

                if (isset($entry['weight']) && is_numeric($entry['weight'])) {
                    $normalized[$timestamp]['weight'] = max(0.0, (float) $entry['weight']);
                }

                if (isset($entry['last_ms']) && is_numeric($entry['last_ms'])) {
                    $normalized[$timestamp]['last_ms'] = max(0.0, (float) $entry['last_ms']);
                }
            }

            if (empty($normalized)) {
                continue;
            }

            ksort($normalized);

            $plugins[$plugin_file] = array_values($normalized);
        }
    }

    return [
        'updated_at' => max(0, $updated_at),
        'plugins'    => $plugins,
    ];
}

/**
 * Calculates trend data for a plugin using history entries.
 *
 * @param array<int,array<string,float|int>> $history_entries Sorted history entries.
 * @param float|null                         $current_average Latest average in milliseconds.
 * @param int                                $current_time    Current timestamp.
 *
 * @return array<string,mixed>
 */
function sitepulse_plugin_impact_calculate_trend(array $history_entries, $current_average, $current_time) {
    $entry_count = count($history_entries);

    if (0 === $entry_count) {
        return [
            'direction'   => 'none',
            'change_ms'   => null,
            'change_pct'  => null,
            'previous'    => null,
            'average_7d'  => null,
            'average_30d' => null,
        ];
    }

    $latest = $history_entries[$entry_count - 1];
    $previous = $entry_count > 1 ? $history_entries[$entry_count - 2] : null;

    $latest_avg = isset($latest['avg_ms']) ? (float) $latest['avg_ms'] : null;
    $previous_avg = ($previous !== null && isset($previous['avg_ms'])) ? (float) $previous['avg_ms'] : null;

    if ($current_average !== null && is_numeric($current_average)) {
        $latest_avg = (float) $current_average;
    }

    $change_ms = null;
    $change_pct = null;
    $direction = 'none';

    if ($latest_avg !== null && $previous_avg !== null) {
        $change_ms = $latest_avg - $previous_avg;

        if (abs($change_ms) < 0.01) {
            $change_ms = 0.0;
        }

        if (abs($previous_avg) > 0.0001) {
            $change_pct = ($change_ms / $previous_avg) * 100;
        }

        if ($change_ms > 0.0) {
            $direction = 'up';
        } elseif ($change_ms < 0.0) {
            $direction = 'down';
        } else {
            $direction = 'flat';
        }
    }

    $seven_days_ago = $current_time - (7 * DAY_IN_SECONDS);
    $thirty_days_ago = $current_time - (30 * DAY_IN_SECONDS);

    $average_7d = sitepulse_plugin_impact_average_window($history_entries, $seven_days_ago);
    $average_30d = sitepulse_plugin_impact_average_window($history_entries, $thirty_days_ago);

    return [
        'direction'   => $direction,
        'change_ms'   => $change_ms,
        'change_pct'  => $change_pct,
        'previous'    => $previous_avg,
        'average_7d'  => $average_7d,
        'average_30d' => $average_30d,
    ];
}

/**
 * Computes the rolling average of the provided history entries after a cutoff.
 *
 * @param array<int,array<string,float|int>> $history_entries History entries.
 * @param int                                $cutoff          Minimum timestamp to include.
 *
 * @return float|null
 */
function sitepulse_plugin_impact_average_window(array $history_entries, $cutoff) {
    $cutoff = (int) $cutoff;

    $sum = 0.0;
    $count = 0;

    foreach ($history_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

        if ($timestamp <= 0 || $timestamp < $cutoff) {
            continue;
        }

        if (!isset($entry['avg_ms']) || !is_numeric($entry['avg_ms'])) {
            continue;
        }

        $sum += max(0.0, (float) $entry['avg_ms']);
        $count++;
    }

    if (0 === $count) {
        return null;
    }

    return $sum / $count;
}

/**
 * Formats the trend change for display.
 *
 * @param array<string,mixed> $trend Trend payload returned by {@see sitepulse_plugin_impact_calculate_trend()}.
 *
 * @return string
 */
function sitepulse_plugin_impact_format_trend_label($trend) {
    if (!is_array($trend)) {
        return '';
    }

    $direction = isset($trend['direction']) ? (string) $trend['direction'] : 'none';
    $change_ms = isset($trend['change_ms']) && is_numeric($trend['change_ms']) ? (float) $trend['change_ms'] : null;
    $change_pct = isset($trend['change_pct']) && is_numeric($trend['change_pct']) ? (float) $trend['change_pct'] : null;

    if ($change_ms === null || $direction === 'none') {
        return '';
    }

    $arrow = '→';

    if ($direction === 'up') {
        $arrow = '↑';
    } elseif ($direction === 'down') {
        $arrow = '↓';
    }

    $formatted_ms = number_format_i18n(abs($change_ms), 2);

    if ($change_pct !== null) {
        $formatted_pct = number_format_i18n(abs($change_pct), 1);

        return sprintf(
            /* translators: 1: arrow indicator, 2: delta in milliseconds, 3: delta percentage. */
            __('Variation vs précédente mesure : %1$s %2$s ms (%3$s %%).', 'sitepulse'),
            $arrow,
            $formatted_ms,
            $formatted_pct
        );
    }

    return sprintf(
        /* translators: 1: arrow indicator, 2: delta in milliseconds. */
        __('Variation vs précédente mesure : %1$s %2$s ms.', 'sitepulse'),
        $arrow,
        $formatted_ms
    );
}

function sitepulse_plugin_impact_normalize_timestamp_for_display($timestamp) {
    $timestamp = (int) $timestamp;

    if ($timestamp <= 0) {
        return 0;
    }

    $mysql_datetime = gmdate('Y-m-d H:i:s', $timestamp);

    if (function_exists('wp_timezone')) {
        $timezone = wp_timezone();

        if ($timezone instanceof DateTimeZone) {
            $date = date_create_from_format('Y-m-d H:i:s', $mysql_datetime, $timezone);

            if ($date instanceof DateTimeInterface) {
                return $date->getTimestamp();
            }
        }
    }

    $offset = (float) get_option('gmt_offset', 0);

    return $timestamp - (int) ($offset * HOUR_IN_SECONDS);
}

function sitepulse_plugin_impact_format_interval($seconds) {
    $seconds = (int) $seconds;

    if ($seconds <= 0) {
        return __('immédiatement', 'sitepulse');
    }

    if ($seconds < MINUTE_IN_SECONDS) {
        $value = max(1, $seconds);

        return sprintf(
            _n('%s seconde', '%s secondes', $value, 'sitepulse'),
            number_format_i18n($value)
        );
    }

    if ($seconds < HOUR_IN_SECONDS) {
        $minutes = max(1, (int) round($seconds / MINUTE_IN_SECONDS));

        return sprintf(
            _n('%s minute', '%s minutes', $minutes, 'sitepulse'),
            number_format_i18n($minutes)
        );
    }

    if ($seconds < DAY_IN_SECONDS) {
        $hours = max(1, (int) round($seconds / HOUR_IN_SECONDS));

        return sprintf(
            _n('%s heure', '%s heures', $hours, 'sitepulse'),
            number_format_i18n($hours)
        );
    }

    $days = max(1, (int) round($seconds / DAY_IN_SECONDS));

    return sprintf(
        _n('%s jour', '%s jours', $days, 'sitepulse'),
        number_format_i18n($days)
    );
}

function sitepulse_get_dir_size_with_cache($dir) {
    $dir = (string) $dir;

    if ($dir === '') {
        return [
            'status' => 'complete',
            'size'   => 0,
            'files'  => null,
            'generated_at' => null,
        ];
    }

    $timestamp = sitepulse_plugin_impact_get_timestamp();

    if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        $size_info = sitepulse_get_dir_size_recursive($dir);

        return [
            'status' => 'complete',
            'size'   => isset($size_info['size']) ? (int) $size_info['size'] : 0,
            'files'  => isset($size_info['files']) ? max(0, (int) $size_info['files']) : null,
            'generated_at' => $timestamp,
        ];
    }

    $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($dir);
    $cached_size = get_transient($transient_key);

    if ($cached_size !== false) {
        if (is_array($cached_size)) {
            $status = isset($cached_size['status']) ? $cached_size['status'] : 'complete';

            if ($status === 'pending') {
                sitepulse_plugin_dir_scan_enqueue($dir);

                return [
                    'status' => 'pending',
                    'size'   => null,
                    'files'  => null,
                    'generated_at' => isset($cached_size['generated_at']) ? (int) $cached_size['generated_at'] : null,
                ];
            }

            return [
                'status' => 'complete',
                'size'   => isset($cached_size['size']) ? (int) $cached_size['size'] : 0,
                'files'  => isset($cached_size['files']) ? max(0, (int) $cached_size['files']) : null,
                'generated_at' => isset($cached_size['generated_at']) ? (int) $cached_size['generated_at'] : null,
            ];
        }

        if (is_numeric($cached_size)) {
            return [
                'status' => 'complete',
                'size'   => (int) $cached_size,
                'files'  => null,
                'generated_at' => null,
            ];
        }

    }

    $threshold = sitepulse_get_plugin_dir_size_threshold($dir);
    $size_info = sitepulse_get_dir_size_recursive(
        $dir,
        [
            'max_bytes'         => isset($threshold['max_bytes']) ? (int) $threshold['max_bytes'] : 0,
            'max_files'         => isset($threshold['max_files']) ? (int) $threshold['max_files'] : 0,
            'stop_on_threshold' => true,
        ]
    );

    $expiration = (int) apply_filters('sitepulse_plugin_dir_size_cache_ttl', 6 * HOUR_IN_SECONDS, $dir);

    if ($expiration <= 0) {
        $expiration = 6 * HOUR_IN_SECONDS;
    }

    if (isset($size_info['exceeded']) && $size_info['exceeded']) {
        $payload = [
            'status' => 'pending',
            'size'   => null,
            'files'  => null,
            'generated_at' => $timestamp,
        ];

        set_transient($transient_key, $payload, $expiration);

        sitepulse_plugin_dir_scan_enqueue($dir);

        return $payload;
    }

    $size = isset($size_info['size']) ? (int) $size_info['size'] : 0;
    $files = isset($size_info['files']) ? max(0, (int) $size_info['files']) : null;

    $payload = [
        'status' => 'complete',
        'size'   => $size,
        'files'  => $files,
        'generated_at' => $timestamp,
    ];

    set_transient($transient_key, $payload, $expiration);

    return $payload;
}

function sitepulse_clear_dir_size_cache($dir) {
    $dir = (string) $dir;

    if ($dir === '' || !defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        return;
    }

    $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($dir);

    delete_transient($transient_key);

    if (function_exists('delete_site_transient')) {
        delete_site_transient($transient_key);
    }

    sitepulse_plugin_dir_scan_remove_from_queue($dir);
}

function sitepulse_get_dir_size_recursive($dir, $args = []) {
    $defaults = [
        'max_bytes'         => 0,
        'max_files'         => 0,
        'stop_on_threshold' => false,
    ];

    if (!is_array($args)) {
        $args = [];
    }

    $args = wp_parse_args($args, $defaults);

    $size = 0;
    $file_count = 0;
    $exceeded = false;

    $dir = (string) $dir;
    $resolved_dir = $dir;

    if (function_exists('realpath')) {
        $realpath = realpath($dir);

        if ($realpath !== false) {
            // Resolve the directory to follow symlinks where possible.
            $resolved_dir = $realpath;
        }
    }

    if (!is_dir($resolved_dir)) {
        return [
            'size'     => $size,
            'files'    => $file_count,
            'exceeded' => $exceeded,
        ];
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved_dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $size += $file->getSize();
            $file_count++;

            if ($args['stop_on_threshold']) {
                $threshold_exceeded = false;

                if ($args['max_bytes'] > 0 && $size > $args['max_bytes']) {
                    $threshold_exceeded = true;
                }

                if ($args['max_files'] > 0 && $file_count > $args['max_files']) {
                    $threshold_exceeded = true;
                }

                if ($threshold_exceeded) {
                    $exceeded = true;

                    break;
                }
            }
        }
    } catch (UnexpectedValueException | RuntimeException $e) {
        return [
            'size'     => $size,
            'files'    => $file_count,
            'exceeded' => $exceeded,
        ];
    }

    return [
        'size'     => $size,
        'files'    => $file_count,
        'exceeded' => $exceeded,
    ];
}

function sitepulse_get_plugin_dir_size_threshold($dir) {
    $default_threshold = [
        'max_bytes' => 100 * MB_IN_BYTES,
        'max_files' => 0,
    ];

    $threshold = apply_filters('sitepulse_plugin_dir_size_threshold', $default_threshold, $dir);

    if (!is_array($threshold)) {
        return $default_threshold;
    }

    $threshold = wp_parse_args($threshold, $default_threshold);

    $threshold['max_bytes'] = isset($threshold['max_bytes']) ? max(0, (int) $threshold['max_bytes']) : 0;
    $threshold['max_files'] = isset($threshold['max_files']) ? max(0, (int) $threshold['max_files']) : 0;

    return $threshold;
}

function sitepulse_plugin_impact_guess_slug($plugin_file, $plugin_data = []) {
    $plugin_file = (string) $plugin_file;

    if ($plugin_file === '') {
        return '';
    }

    if (is_array($plugin_data) && !empty($plugin_data['slug'])) {
        return sanitize_key($plugin_data['slug']);
    }

    $plugin_dir = dirname($plugin_file);

    if ($plugin_dir !== '.' && $plugin_dir !== '' && $plugin_dir !== DIRECTORY_SEPARATOR) {
        return sanitize_title($plugin_dir);
    }

    $plugin_basename = basename($plugin_file, '.php');

    if ($plugin_basename !== '') {
        return sanitize_title($plugin_basename);
    }

    return '';
}

function sitepulse_plugin_dir_scan_enqueue($dir) {
    $dir = (string) $dir;

    if ($dir === '') {
        return;
    }

    $queue = get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, []);

    if (!is_array($queue)) {
        $queue = [];
    }

    if (!in_array($dir, $queue, true)) {
        $queue[] = $dir;
        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, $queue, false);
    }

    sitepulse_schedule_plugin_dir_scan();
}

function sitepulse_plugin_dir_scan_remove_from_queue($dir) {
    $dir = (string) $dir;

    if ($dir === '') {
        return;
    }

    $queue = get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, []);

    if (!is_array($queue) || empty($queue)) {
        return;
    }

    $position = array_search($dir, $queue, true);

    if ($position === false) {
        return;
    }

    unset($queue[$position]);

    if (empty($queue)) {
        delete_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION);
    } else {
        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, array_values($queue), false);
    }
}

function sitepulse_schedule_plugin_dir_scan() {
    if (!wp_next_scheduled('sitepulse_queue_plugin_dir_scan')) {
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'sitepulse_queue_plugin_dir_scan');
    }
}

function sitepulse_process_plugin_dir_scan_queue() {
    if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        return;
    }

    $queue = get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, []);

    if (!is_array($queue) || empty($queue)) {
        delete_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION);

        return;
    }

    $dir = array_shift($queue);

    if (empty($queue)) {
        delete_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION);
    } else {
        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, array_values($queue), false);
    }

    $dir = (string) $dir;

    if ($dir === '') {
        sitepulse_schedule_plugin_dir_scan();

        return;
    }

    $size_info = sitepulse_get_dir_size_recursive(
        $dir,
        [
            'max_bytes'         => 0,
            'max_files'         => 0,
            'stop_on_threshold' => false,
        ]
    );

    $size = isset($size_info['size']) ? (int) $size_info['size'] : 0;
    $files = isset($size_info['files']) ? max(0, (int) $size_info['files']) : null;

    $expiration = (int) apply_filters('sitepulse_plugin_dir_size_cache_ttl', 6 * HOUR_IN_SECONDS, $dir);

    if ($expiration <= 0) {
        $expiration = 6 * HOUR_IN_SECONDS;
    }

    $payload = [
        'status' => 'complete',
        'size'   => $size,
        'files'  => $files,
        'generated_at' => sitepulse_plugin_impact_get_timestamp(),
    ];

    $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($dir);

    set_transient($transient_key, $payload, $expiration);

    if (!empty($queue)) {
        sitepulse_schedule_plugin_dir_scan();
    }
}

function sitepulse_plugin_impact_get_timestamp() {
    if (function_exists('current_time')) {
        return (int) current_time('timestamp');
    }

    return time();
}
