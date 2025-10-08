<?php
/**
 * Render callback for the SitePulse dashboard preview block.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sitepulse_render_dashboard_preview_block')) {
    /**
     * Builds a human readable summary list for a chart configuration.
     *
     * @param array|null $chart Chart configuration as assembled by the dashboard module.
     *
     * @return array{
     *     id: string,
     *     html: string,
     * } Summary identifier and markup. Empty strings are returned when no summary can be generated.
     */
    function sitepulse_dashboard_preview_render_dataset_summary($chart) {
        if (!is_array($chart)) {
            return [
                'id'   => '',
                'html' => '',
            ];
        }

        $labels = isset($chart['labels']) && is_array($chart['labels']) ? $chart['labels'] : [];
        $datasets = isset($chart['datasets']) && is_array($chart['datasets']) ? $chart['datasets'] : [];

        if (empty($labels) || empty($datasets)) {
            return [
                'id'   => '',
                'html' => '',
            ];
        }

        $items = [];

        foreach ($labels as $index => $label) {
            $values = [];

            foreach ($datasets as $dataset) {
                if (!is_array($dataset) || !isset($dataset['data']) || !is_array($dataset['data'])) {
                    continue;
                }

                if (!array_key_exists($index, $dataset['data'])) {
                    continue;
                }

                $value = $dataset['data'][$index];

                if (is_numeric($value)) {
                    $numeric_value = (float) $value;

                    if (is_finite($numeric_value)) {
                        $rounded_integer = round($numeric_value);
                        $rounded_two_decimals = round($numeric_value, 2);
                        $is_near_integer = abs($rounded_two_decimals - $rounded_integer) < 0.0005;

                        if ($is_near_integer) {
                            $values[] = number_format_i18n($rounded_integer, 0);
                        } else {
                            $values[] = number_format_i18n($rounded_two_decimals, 2);
                        }
                    } else {
                        $values[] = (string) $value;
                    }
                } elseif (is_scalar($value)) {
                    $values[] = (string) $value;
                }
            }

            if (empty($values)) {
                continue;
            }

            $items[] = sprintf(
                '<li><span class="sitepulse-preview-list__label">%1$s</span><span class="sitepulse-preview-list__value">%2$s</span></li>',
                esc_html(wp_strip_all_tags((string) $label)),
                esc_html(implode(', ', $values))
            );
        }

        if (empty($items)) {
            return [
                'id'   => '',
                'html' => '',
            ];
        }

        $summary_id = function_exists('wp_unique_id')
            ? wp_unique_id('sitepulse-preview-summary-')
            : uniqid('sitepulse-preview-summary-');

        $list_markup = '<ul class="sitepulse-preview-list">' . implode('', $items) . '</ul>';

        return [
            'id'   => $summary_id,
            'html' => sprintf(
                '<div id="%1$s" class="sitepulse-preview-summary">%2$s</div>',
                esc_attr($summary_id),
                $list_markup
            ),
        ];
    }

    /**
     * Renders the chart container for the preview block.
     *
     * @param string     $canvas_id Unique identifier for the canvas element.
     * @param array|null $chart     Chart payload.
     *
     * @return string HTML markup for the chart area.
     */
    function sitepulse_dashboard_preview_render_chart_area($canvas_id, $chart) {
        $summary = sitepulse_dashboard_preview_render_dataset_summary($chart);
        $summary_id = is_array($summary) && isset($summary['id']) ? $summary['id'] : '';
        $summary_html = is_array($summary) && isset($summary['html']) ? $summary['html'] : (string) $summary;

        $has_chart = is_array($chart) && !empty($chart) && empty($chart['empty']) && !empty($chart['datasets']);

        if ($has_chart) {
            $chart_data = wp_json_encode($chart);
            $chart_attribute = is_string($chart_data) ? $chart_data : '';

            $canvas_attributes = [
                'id'                   => esc_attr($canvas_id),
                'data-sitepulse-chart' => esc_attr($chart_attribute),
                'role'                 => 'img',
            ];

            if (!empty($summary_id)) {
                $canvas_attributes['aria-describedby'] = esc_attr($summary_id);
            } else {
                $canvas_attributes['aria-label'] = esc_attr__(
                    'Aperçu du graphique des données SitePulse.',
                    'sitepulse'
                );
            }

            $canvas_attribute_string = '';

            foreach ($canvas_attributes as $attribute => $value) {
                if ($value === '') {
                    continue;
                }

                $canvas_attribute_string .= sprintf(' %s="%s"', $attribute, $value);
            }

            $fallback_markup = sprintf(
                '<span aria-hidden="true">%1$s</span><span class="screen-reader-text">%2$s</span>',
                esc_html__(
                    'Votre navigateur ne prend pas en charge les graphiques. Consultez le résumé textuel ci-dessous.',
                    'sitepulse'
                ),
                esc_html__(
                    'Les données du graphique sont détaillées dans le résumé textuel qui suit.',
                    'sitepulse'
                )
            );

            return sprintf(
                '<div class="sitepulse-chart-container"><canvas%1$s>%2$s</canvas>%3$s</div>',
                $canvas_attribute_string,
                $fallback_markup,
                $summary_html
            );
        }

        return sprintf(
            '<div class="sitepulse-chart-container"><div class="sitepulse-chart-placeholder">%1$s</div>%2$s</div>',
            esc_html__('Pas encore de mesures disponibles pour ce graphique.', 'sitepulse'),
            $summary_html
        );
    }

    /**
     * Retrieves status metadata (label, accessible text, icon) for a given status key.
     *
     * @param string $status        Status key.
     * @param array  $status_labels Map of status labels.
     *
     * @return array
     */
    function sitepulse_dashboard_preview_get_status_meta($status, $status_labels) {
        $status_key = is_string($status) ? trim($status) : '';

        if ($status_key !== '' && is_array($status_labels) && isset($status_labels[$status_key])) {
            return $status_labels[$status_key];
        }

        if (is_array($status_labels) && isset($status_labels['status-warn'])) {
            return $status_labels['status-warn'];
        }

        return [
            'label' => __('Indisponible', 'sitepulse'),
            'sr'    => __('Statut non disponible', 'sitepulse'),
            'icon'  => 'ℹ️',
        ];
    }

    /**
     * Renders a dashboard preview card from a normalized definition.
     *
     * @param array $definition    Card definition (classes, title, subtitle, metric value, etc.).
     * @param array $status_labels Status labels map coming from the block context.
     *
     * @return string
     */
    function sitepulse_dashboard_preview_render_card_section($definition, $status_labels) {
        if (!is_array($definition)) {
            return '';
        }

        $classes = [];

        if (isset($definition['classes'])) {
            if (is_array($definition['classes'])) {
                $classes = $definition['classes'];
            } else {
                $classes = preg_split('/\s+/', (string) $definition['classes']);
            }
        }

        if (empty($classes)) {
            $classes = ['sitepulse-card'];
        }

        $classes = array_filter(array_map('sanitize_html_class', $classes));

        if (!in_array('sitepulse-card', $classes, true)) {
            $classes[] = 'sitepulse-card';
        }

        $class_attribute = implode(' ', array_unique($classes));

        $title = isset($definition['title']) ? (string) $definition['title'] : '';
        $subtitle = isset($definition['subtitle']) ? (string) $definition['subtitle'] : '';
        $description = isset($definition['description']) ? (string) $definition['description'] : '';
        $raw_status = isset($definition['status']) ? (string) $definition['status'] : '';
        $status_key = trim($raw_status);

        $metric_value_html = isset($definition['metric_value_html']) && $definition['metric_value_html'] !== ''
            ? (string) $definition['metric_value_html']
            : sprintf(
                '<span class="sitepulse-metric-value">%s</span>',
                esc_html__('N/A', 'sitepulse')
            );

        $after_metric_html = isset($definition['after_metric_html']) ? (string) $definition['after_metric_html'] : '';

        $chart_id = isset($definition['chart_id']) ? (string) $definition['chart_id'] : '';

        if ($chart_id === '') {
            $chart_id = function_exists('wp_unique_id')
                ? wp_unique_id('sitepulse-preview-chart-')
                : uniqid('sitepulse-preview-chart-');
        }

        $chart_payload = isset($definition['chart']) ? $definition['chart'] : null;
        $chart_html = sitepulse_dashboard_preview_render_chart_area($chart_id, $chart_payload);

        $status_meta = sitepulse_dashboard_preview_get_status_meta($status_key, $status_labels);

        $has_known_status = $status_key !== ''
            && is_array($status_labels)
            && isset($status_labels[$status_key]);

        $status_badge_class = $has_known_status
            ? sanitize_html_class($status_key)
            : 'status-warn';

        if ($status_badge_class === '') {
            $status_badge_class = 'status-warn';
        }

        return sprintf(
            '<section class="%1$s"><div class="sitepulse-card-header"><h3>%2$s</h3></div><p class="sitepulse-card-subtitle">%3$s</p>%4$s<p class="sitepulse-metric"><span class="status-badge %5$s" aria-hidden="true"><span class="status-icon">%6$s</span><span class="status-text">%7$s</span></span><span class="screen-reader-text">%8$s</span>%9$s</p>%10$s<p class="description">%11$s</p></section>',
            esc_attr($class_attribute),
            esc_html($title),
            esc_html($subtitle),
            $chart_html,
            esc_attr($status_badge_class),
            esc_html($status_meta['icon']),
            esc_html($status_meta['label']),
            esc_html($status_meta['sr']),
            $metric_value_html,
            $after_metric_html,
            esc_html($description)
        );
    }

    /**
     * Server-side render callback for the dashboard preview block.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Saved content (unused).
     * @param object $block      Block instance (unused).
     *
     * @return string
     */
    function sitepulse_render_dashboard_preview_block($attributes, $content = '', $block = null) {
        unset($content, $block);

        $defaults = [
            'showSpeed'     => true,
            'showUptime'    => true,
            'showDatabase'  => true,
            'showLogs'      => true,
            'layoutVariant' => 'grid',
            'columns'       => 2,
            'gridDensity'   => 'comfortable',
            'showHeader'    => true,
        ];

        $attributes = is_array($attributes) ? $attributes : [];
        $attributes = wp_parse_args($attributes, $defaults);

        $show_speed = !empty($attributes['showSpeed']);
        $show_uptime = !empty($attributes['showUptime']);
        $show_database = !empty($attributes['showDatabase']);
        $show_logs = !empty($attributes['showLogs']);

        $allowed_layouts = ['grid', 'list'];
        $layout_variant = isset($attributes['layoutVariant']) && is_string($attributes['layoutVariant'])
            ? $attributes['layoutVariant']
            : 'grid';
        $layout_variant = in_array($layout_variant, $allowed_layouts, true) ? $layout_variant : 'grid';
        $layout_variant_slug = sanitize_html_class($layout_variant ?: 'grid');

        $allowed_densities = ['compact', 'comfortable', 'spacious'];
        $grid_density = isset($attributes['gridDensity']) && is_string($attributes['gridDensity'])
            ? $attributes['gridDensity']
            : 'comfortable';
        $grid_density = in_array($grid_density, $allowed_densities, true) ? $grid_density : 'comfortable';
        $grid_density_slug = sanitize_html_class($grid_density ?: 'comfortable');

        $columns = isset($attributes['columns']) ? (int) $attributes['columns'] : 2;
        if ($columns < 1) {
            $columns = 1;
        } elseif ($columns > 4) {
            $columns = 4;
        }

        $show_header = !empty($attributes['showHeader']);

        $base_wrapper_classes = [
            'sitepulse-dashboard-preview',
            'sitepulse-dashboard-preview--layout-' . $layout_variant_slug,
            'sitepulse-dashboard-preview--density-' . $grid_density_slug,
            'sitepulse-dashboard-preview--columns-' . $columns,
        ];

        $base_wrapper_classes[] = $show_header
            ? 'sitepulse-dashboard-preview--has-header'
            : 'sitepulse-dashboard-preview--no-header';

        $grid_classes = [
            'sitepulse-grid',
            'sitepulse-grid--cols-' . $columns,
            'sitepulse-grid--density-' . $grid_density_slug,
            'sitepulse-grid--variant-' . $layout_variant_slug,
        ];

        $wrapper_class_attribute = function ($additional_classes = []) use ($base_wrapper_classes) {
            $classes = array_merge($base_wrapper_classes, $additional_classes);
            $classes = array_map('sanitize_html_class', array_unique(array_filter($classes)));

            return implode(' ', $classes);
        };

        if (!function_exists('sitepulse_get_dashboard_preview_context')) {
            $wrapper_attributes = get_block_wrapper_attributes([
                'class' => $wrapper_class_attribute(['sitepulse-dashboard-preview--is-empty']),
            ]);

            return sprintf(
                '<div %1$s><div class="sitepulse-dashboard-preview__empty">%2$s</div></div>',
                $wrapper_attributes,
                esc_html__('Activez le module « Tableaux de bord personnalisés » pour utiliser ce bloc.', 'sitepulse')
            );
        }

        $context = sitepulse_get_dashboard_preview_context();
        $modules = isset($context['modules']) && is_array($context['modules']) ? $context['modules'] : [];
        $status_labels = isset($context['status_labels']) ? $context['status_labels'] : [];

        $cards = [];
        $unique_prefix = sanitize_html_class('sitepulse-preview-' . uniqid());

        $card_configs = [
            'speed' => [
                'enabled'      => $show_speed,
                'module_key'   => 'speed',
                'chart_suffix' => 'speed',
                'classes'      => ['sitepulse-card', 'sitepulse-card--speed'],
                'title'        => __('Performance PHP', 'sitepulse'),
                'subtitle'     => __('Dernier temps de traitement mesuré lors d’un scan récent.', 'sitepulse'),
                'metric'       => static function ($card_data) {
                    $display = isset($card_data['display']) && $card_data['display'] !== ''
                        ? (string) $card_data['display']
                        : __('N/A', 'sitepulse');

                    return sprintf(
                        '<span class="sitepulse-metric-value">%s</span>',
                        esc_html($display)
                    );
                },
                'description'  => static function ($card_data, $module_data) {
                    unset($card_data);

                    $thresholds = isset($module_data['thresholds']) && is_array($module_data['thresholds'])
                        ? $module_data['thresholds']
                        : [];

                    $warning_threshold = isset($thresholds['warning'])
                        ? (int) $thresholds['warning']
                        : (defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200);
                    $critical_threshold = isset($thresholds['critical'])
                        ? (int) $thresholds['critical']
                        : (defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500);

                    return sprintf(
                        /* translators: 1: warning threshold in ms, 2: critical threshold in ms */
                        __('En dessous de %1$d ms, tout va bien. Au-delà de %2$d ms, investiguez les plugins ou l’hébergement.', 'sitepulse'),
                        $warning_threshold,
                        $critical_threshold
                    );
                },
            ],
            'uptime' => [
                'enabled'      => $show_uptime,
                'module_key'   => 'uptime',
                'chart_suffix' => 'uptime',
                'classes'      => ['sitepulse-card', 'sitepulse-card--uptime'],
                'title'        => __('Disponibilité', 'sitepulse'),
                'subtitle'     => __('Taux de réussite des 30 dernières vérifications horaires.', 'sitepulse'),
                'metric'       => static function ($card_data) {
                    $percentage = isset($card_data['percentage']) ? round((float) $card_data['percentage'], 2) : 0;

                    return sprintf(
                        '<span class="sitepulse-metric-value">%s<span class="sitepulse-metric-unit">%s</span></span>',
                        esc_html(number_format_i18n($percentage, 2)),
                        esc_html__('%', 'sitepulse')
                    );
                },
                'description'  => static function () {
                    return __('Chaque barre représente une vérification de disponibilité programmée.', 'sitepulse');
                },
            ],
            'database' => [
                'enabled'      => $show_database,
                'module_key'   => 'database',
                'chart_suffix' => 'database',
                'classes'      => ['sitepulse-card', 'sitepulse-card--database'],
                'title'        => __('Santé de la base', 'sitepulse'),
                'subtitle'     => __('Volume des révisions par rapport au budget défini.', 'sitepulse'),
                'metric'       => static function ($card_data) {
                    $revision_count = isset($card_data['revisions']) ? (int) $card_data['revisions'] : 0;

                    return sprintf(
                        '<span class="sitepulse-metric-value">%s<span class="sitepulse-metric-unit">%s</span></span>',
                        esc_html(number_format_i18n($revision_count)),
                        esc_html__('révisions', 'sitepulse')
                    );
                },
                'description'  => static function ($card_data) {
                    $limit = isset($card_data['limit']) ? (int) $card_data['limit'] : 0;

                    return sprintf(
                        /* translators: %d: recommended revision limit */
                        __('Essayez de rester sous la barre des %d révisions pour éviter de gonfler la table des articles.', 'sitepulse'),
                        $limit
                    );
                },
            ],
            'logs' => [
                'enabled'      => $show_logs,
                'module_key'   => 'logs',
                'chart_suffix' => 'logs',
                'classes'      => ['sitepulse-card', 'sitepulse-card--logs'],
                'title'        => __('Journal d’erreurs', 'sitepulse'),
                'subtitle'     => __('Répartition des évènements récents trouvés dans le fichier debug.log.', 'sitepulse'),
                'metric'       => static function ($card_data) {
                    $summary = isset($card_data['summary']) && $card_data['summary'] !== ''
                        ? (string) $card_data['summary']
                        : __('Aucune donnée disponible.', 'sitepulse');

                    return sprintf(
                        '<span class="sitepulse-metric-value">%s</span>',
                        esc_html($summary)
                    );
                },
                'after_metric' => static function ($card_data) {
                    $counts = isset($card_data['counts']) && is_array($card_data['counts']) ? $card_data['counts'] : [];

                    $legend_items = [
                        [__('Erreurs fatales', 'sitepulse'), 'fatal', '#a0141e'],
                        [__('Avertissements', 'sitepulse'), 'warning', '#8a6100'],
                        [__('Notices', 'sitepulse'), 'notice', '#2196F3'],
                        [__('Obsolescences', 'sitepulse'), 'deprecated', '#9C27B0'],
                    ];

                    $legend_html_parts = [];

                    foreach ($legend_items as $item) {
                        list($label, $key, $color) = $item;
                        $value = isset($counts[$key]) ? (int) $counts[$key] : 0;

                        $legend_html_parts[] = sprintf(
                            '<li><span class="label"><span class="badge" style="background-color: %1$s;"></span>%2$s</span><span class="value">%3$s</span></li>',
                            esc_attr($color),
                            esc_html($label),
                            esc_html(number_format_i18n($value))
                        );
                    }

                    return '<ul class="sitepulse-legend">' . implode('', $legend_html_parts) . '</ul>';
                },
                'description'  => static function () {
                    return __('Analysez le journal complet depuis l’interface SitePulse pour plus de détails.', 'sitepulse');
                },
            ],
        ];

        foreach ($card_configs as $config) {
            if (empty($config['enabled'])) {
                continue;
            }

            $module_key = isset($config['module_key']) ? $config['module_key'] : '';

            if ($module_key === '' || !isset($modules[$module_key]) || empty($modules[$module_key]['enabled']) || !isset($modules[$module_key]['card'])) {
                continue;
            }

            $module_data = $modules[$module_key];
            $card_data = is_array($module_data['card']) ? $module_data['card'] : [];
            $chart_data = isset($module_data['chart']) ? $module_data['chart'] : null;

            $metric_callback = isset($config['metric']) && is_callable($config['metric']) ? $config['metric'] : null;
            $description_callback = isset($config['description']) && is_callable($config['description']) ? $config['description'] : null;
            $after_metric_callback = isset($config['after_metric']) && is_callable($config['after_metric']) ? $config['after_metric'] : null;

            $metric_value_html = $metric_callback ? (string) $metric_callback($card_data, $module_data) : '';
            $description_text = $description_callback ? (string) $description_callback($card_data, $module_data) : '';
            $after_metric_html = $after_metric_callback ? (string) $after_metric_callback($card_data, $module_data) : '';

            $chart_suffix = isset($config['chart_suffix']) ? (string) $config['chart_suffix'] : $module_key;
            $chart_id = $unique_prefix . '-' . sanitize_key($chart_suffix) . '-chart';

            $definition = [
                'classes'           => isset($config['classes']) ? $config['classes'] : ['sitepulse-card'],
                'title'             => isset($config['title']) ? $config['title'] : '',
                'subtitle'          => isset($config['subtitle']) ? $config['subtitle'] : '',
                'chart'             => $chart_data,
                'chart_id'          => $chart_id,
                'status'            => isset($card_data['status']) ? (string) $card_data['status'] : '',
                'metric_value_html' => $metric_value_html,
                'after_metric_html' => $after_metric_html,
                'description'       => $description_text,
            ];

            $cards[] = sitepulse_dashboard_preview_render_card_section($definition, $status_labels);
        }

        if (empty($cards)) {
            $wrapper_attributes = get_block_wrapper_attributes([
                'class' => $wrapper_class_attribute(['sitepulse-dashboard-preview--is-empty']),
            ]);

            return sprintf(
                '<div %1$s><div class="sitepulse-dashboard-preview__empty">%2$s</div></div>',
                $wrapper_attributes,
                esc_html__('Aucune carte à afficher pour le moment. Activez les modules souhaités ou collectez davantage de données.', 'sitepulse')
            );
        }

        $header = '';

        if ($show_header) {
            $header = sprintf(
                '<div class="sitepulse-dashboard-preview__header"><h3>%1$s</h3><p>%2$s</p></div>',
                esc_html__('Aperçu SitePulse', 'sitepulse'),
                esc_html__('Dernières mesures agrégées par vos modules actifs.', 'sitepulse')
            );
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => $wrapper_class_attribute(),
        ]);

        return sprintf(
            '<div %1$s>%2$s<div class="%3$s">%4$s</div></div>',
            $wrapper_attributes,
            $header,
            esc_attr(implode(' ', array_map('sanitize_html_class', array_unique($grid_classes)))),
            implode('', $cards)
        );
    }
}
