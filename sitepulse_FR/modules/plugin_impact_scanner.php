<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action(
    'admin_menu',
    function () {
        add_submenu_page(
            'sitepulse-dashboard',
            'Plugin Impact Scanner',
            'Plugin Impact',
            'manage_options',
            'sitepulse-plugins',
            'sitepulse_plugin_impact_scanner_page'
        );
    }
);

function sitepulse_plugin_impact_scanner_page() {
    if (!current_user_can('manage_options')) {
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

        if ($plugin_dir === '.' || $plugin_dir === '') {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            $disk_space = is_file($plugin_path) && is_readable($plugin_path) ? filesize($plugin_path) : 0;
        } else {
            $disk_space = sitepulse_get_dir_size_with_cache(WP_PLUGIN_DIR . '/' . $plugin_dir);
        }

        $impact_data = [
            'file'          => $plugin_file,
            'name'          => $plugin_name,
            'impact'        => null,
            'last_ms'       => null,
            'samples'       => 0,
            'last_recorded' => null,
            'disk_space'    => $disk_space,
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
    <style>
        .impact-bar-bg { background: #eee; border-radius: 3px; overflow: hidden; width: 100%; }
        .impact-bar { height: 18px; border-radius: 3px; background-color: #FFC107; text-align: right; color: white; padding-right: 5px; white-space: nowrap; font-size: 12px; line-height: 18px; }
        .sitepulse-impact-meta { margin-bottom: 1em; }
        .sitepulse-impact-meta p { margin: 0.2em 0; }
        .sitepulse-impact-limitations { list-style: disc; margin-left: 1.5em; }
    </style>
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

        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 25%;"><?php esc_html_e('Plugin', 'sitepulse'); ?></th>
                    <th scope="col"><?php esc_html_e('Durée mesurée', 'sitepulse'); ?></th>
                    <th scope="col"><?php esc_html_e('Espace disque', 'sitepulse'); ?></th>
                    <th scope="col" style="width: 35%;"><?php esc_html_e('Poids relatif', 'sitepulse'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($impacts)) : ?>
                    <tr><td colspan="4"><?php esc_html_e('Aucun plugin actif à analyser.', 'sitepulse'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($impacts as $data) :
                        $weight = ($total_impact > 0 && $data['impact'] !== null) ? ($data['impact'] / $total_impact) * 100 : null;
                        $weight_color = '#4CAF50';

                        if (is_numeric($weight)) {
                            if ($weight > 20) {
                                $weight_color = '#F44336';
                            } elseif ($weight > 10) {
                                $weight_color = '#FFC107';
                            }
                        }

                        $impact_lines = [];

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
                        } else {
                            $impact_lines[] = __('Non mesuré pour le moment.', 'sitepulse');
                        }

                        $impact_output = implode('<br />', array_map('esc_html', $impact_lines));
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($data['name']); ?></strong></td>
                        <td><?php echo $impact_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        <td><?php echo esc_html(size_format($data['disk_space'], 2)); ?></td>
                        <td>
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
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
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
        return 0;
    }

    if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
        return sitepulse_get_dir_size_recursive($dir);
    }

    $transient_key = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . md5($dir);
    $cached_size = get_transient($transient_key);

    if ($cached_size !== false && is_numeric($cached_size)) {
        return (int) $cached_size;
    }

    $size = sitepulse_get_dir_size_recursive($dir);
    $expiration = (int) apply_filters('sitepulse_plugin_dir_size_cache_ttl', 6 * HOUR_IN_SECONDS, $dir);

    if ($expiration <= 0) {
        $expiration = 6 * HOUR_IN_SECONDS;
    }

    set_transient($transient_key, (int) $size, $expiration);

    return (int) $size;
}

function sitepulse_get_dir_size_recursive($dir) {
    $size = 0;

    if (!is_dir($dir)) {
        return $size;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $size += $file->getSize();
        }
    } catch (UnexpectedValueException | RuntimeException $e) {
        return $size;
    }

    return $size;
}
