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
     * @return string HTML list.
     */
    function sitepulse_dashboard_preview_render_dataset_summary($chart) {
        if (!is_array($chart)) {
            return '';
        }

        $labels = isset($chart['labels']) && is_array($chart['labels']) ? $chart['labels'] : [];
        $datasets = isset($chart['datasets']) && is_array($chart['datasets']) ? $chart['datasets'] : [];

        if (empty($labels) || empty($datasets)) {
            return '';
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
                    $precision = (floor($numeric_value) === $numeric_value) ? 0 : 2;
                    $values[] = number_format_i18n($numeric_value, $precision);
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
            return '';
        }

        return '<ul class="sitepulse-preview-list">' . implode('', $items) . '</ul>';
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
        $has_chart = is_array($chart) && !empty($chart) && empty($chart['empty']) && !empty($chart['datasets']);

        if ($has_chart) {
            $chart_data = wp_json_encode($chart);
            $chart_attribute = is_string($chart_data) ? $chart_data : '';
            $summary = sitepulse_dashboard_preview_render_dataset_summary($chart);

            return sprintf(
                '<div class="sitepulse-chart-container"><canvas id="%1$s" data-sitepulse-chart="%2$s"></canvas>%3$s</div>',
                esc_attr($canvas_id),
                esc_attr($chart_attribute),
                $summary
            );
        }

        return sprintf(
            '<div class="sitepulse-chart-container"><div class="sitepulse-chart-placeholder">%s</div></div>',
            esc_html__('Pas encore de mesures disponibles pour ce graphique.', 'sitepulse')
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
        if (is_array($status_labels) && isset($status_labels[$status])) {
            return $status_labels[$status];
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
            'showSpeed'    => true,
            'showUptime'   => true,
            'showDatabase' => true,
            'showLogs'     => true,
        ];

        $attributes = is_array($attributes) ? $attributes : [];
        $attributes = wp_parse_args($attributes, $defaults);

        $show_speed = !empty($attributes['showSpeed']);
        $show_uptime = !empty($attributes['showUptime']);
        $show_database = !empty($attributes['showDatabase']);
        $show_logs = !empty($attributes['showLogs']);

        if (!function_exists('sitepulse_get_dashboard_preview_context')) {
            return '<div class="wp-block-sitepulse-dashboard-preview sitepulse-dashboard-preview__empty">'
                . esc_html__('Activez le module « Tableaux de bord personnalisés » pour utiliser ce bloc.', 'sitepulse')
                . '</div>';
        }

        $context = sitepulse_get_dashboard_preview_context();
        $modules = isset($context['modules']) && is_array($context['modules']) ? $context['modules'] : [];
        $status_labels = isset($context['status_labels']) ? $context['status_labels'] : [];

        $cards = [];
        $unique_prefix = sanitize_html_class('sitepulse-preview-' . uniqid());

        // Speed card.
        if ($show_speed && isset($modules['speed']) && !empty($modules['speed']['enabled']) && isset($modules['speed']['card'])) {
            $card = $modules['speed']['card'];
            $chart = isset($modules['speed']['chart']) ? $modules['speed']['chart'] : null;
            $thresholds = isset($modules['speed']['thresholds']) ? $modules['speed']['thresholds'] : [];
            $warning_threshold = isset($thresholds['warning']) ? (int) $thresholds['warning'] : (defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200);
            $critical_threshold = isset($thresholds['critical']) ? (int) $thresholds['critical'] : (defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500);
            $status_meta = sitepulse_dashboard_preview_get_status_meta(isset($card['status']) ? $card['status'] : '', $status_labels);
            $canvas_id = $unique_prefix . '-speed-chart';

            $cards[] = sprintf(
                '<section class="sitepulse-card sitepulse-card--speed"><div class="sitepulse-card-header"><h3>%1$s</h3></div><p class="sitepulse-card-subtitle">%2$s</p>%3$s<p class="sitepulse-metric"><span class="status-badge %4$s" aria-hidden="true"><span class="status-icon">%5$s</span><span class="status-text">%6$s</span></span><span class="screen-reader-text">%7$s</span><span class="sitepulse-metric-value">%8$s</span></p><p class="description">%9$s</p></section>',
                esc_html__('Performance PHP', 'sitepulse'),
                esc_html__('Dernier temps de traitement mesuré lors d’un scan récent.', 'sitepulse'),
                sitepulse_dashboard_preview_render_chart_area($canvas_id, $chart),
                esc_attr(isset($card['status']) ? $card['status'] : ''),
                esc_html($status_meta['icon']),
                esc_html($status_meta['label']),
                esc_html($status_meta['sr']),
                esc_html(isset($card['display']) ? $card['display'] : __('N/A', 'sitepulse')),
                esc_html(sprintf(
                    /* translators: 1: warning threshold in ms, 2: critical threshold in ms */
                    __('En dessous de %1$d ms, tout va bien. Au-delà de %2$d ms, investiguez les plugins ou l’hébergement.', 'sitepulse'),
                    $warning_threshold,
                    $critical_threshold
                ))
            );
        }

        // Uptime card.
        if ($show_uptime && isset($modules['uptime']) && !empty($modules['uptime']['enabled']) && isset($modules['uptime']['card'])) {
            $card = $modules['uptime']['card'];
            $chart = isset($modules['uptime']['chart']) ? $modules['uptime']['chart'] : null;
            $status_meta = sitepulse_dashboard_preview_get_status_meta(isset($card['status']) ? $card['status'] : '', $status_labels);
            $canvas_id = $unique_prefix . '-uptime-chart';
            $percentage = isset($card['percentage']) ? round((float) $card['percentage'], 2) : 0;
            $targets = isset($card['targets']) && is_array($card['targets']) ? $card['targets'] : [];
            $target_items = [];

            foreach ($targets as $target_summary) {
                if (!is_array($target_summary)) {
                    continue;
                }

                $target_status = isset($target_summary['status']) ? $target_summary['status'] : '';
                $target_status_meta = sitepulse_dashboard_preview_get_status_meta($target_status, $status_labels);
                $target_name = isset($target_summary['name']) ? $target_summary['name'] : '';
                $target_percentage = isset($target_summary['percentage']) ? (float) $target_summary['percentage'] : null;
                $incident_label = '';

                if (isset($target_summary['incident']) && is_array($target_summary['incident'])) {
                    $incident = $target_summary['incident'];
                    if (isset($incident['duration'])) {
                        $incident_label = sprintf(__('Incident en cours (%s)', 'sitepulse'), $incident['duration']);
                    }
                }

                $target_items[] = sprintf(
                    '<li><span class="status-badge %1$s" aria-hidden="true"><span class="status-icon">%2$s</span><span class="status-text">%3$s</span></span><span class="sitepulse-uptime-target-name">%4$s</span><span class="sitepulse-uptime-target-percentage">%5$s</span>%6$s</li>',
                    esc_attr($target_status),
                    esc_html($target_status_meta['icon']),
                    esc_html($target_status_meta['label']),
                    esc_html($target_name),
                    $target_percentage !== null ? esc_html(number_format_i18n($target_percentage, 1) . '%') : esc_html__('N/A', 'sitepulse'),
                    $incident_label !== '' ? '<span class="sitepulse-uptime-target-incident">' . esc_html($incident_label) . '</span>' : ''
                );
            }

            $targets_html = !empty($target_items)
                ? '<ul class="sitepulse-uptime-target-list">' . implode('', $target_items) . '</ul>'
                : '<p class="sitepulse-card-note">' . esc_html__('Aucune cible configurée pour le suivi de disponibilité.', 'sitepulse') . '</p>';

            $summary_text = isset($card['summary']) ? $card['summary'] : '';

            $cards[] = sprintf(
                '<section class="sitepulse-card sitepulse-card--uptime"><div class="sitepulse-card-header"><h3>%1$s</h3></div><p class="sitepulse-card-subtitle">%2$s</p>%3$s<p class="sitepulse-metric"><span class="status-badge %4$s" aria-hidden="true"><span class="status-icon">%5$s</span><span class="status-text">%6$s</span></span><span class="screen-reader-text">%7$s</span><span class="sitepulse-metric-value">%8$s<span class="sitepulse-metric-unit">%9$s</span></span></p><p class="description">%10$s</p>%11$s</section>',
                esc_html__('Disponibilité', 'sitepulse'),
                esc_html__('Vue d’ensemble des cibles surveillées.', 'sitepulse'),
                sitepulse_dashboard_preview_render_chart_area($canvas_id, $chart),
                esc_attr(isset($card['status']) ? $card['status'] : ''),
                esc_html($status_meta['icon']),
                esc_html($status_meta['label']),
                esc_html($status_meta['sr']),
                esc_html(number_format_i18n($percentage, 2)),
                esc_html__('%', 'sitepulse'),
                esc_html($summary_text !== '' ? $summary_text : __('Chaque barre représente la disponibilité mesurée pour chaque cible.', 'sitepulse')),
                $targets_html
            );
        }

        // Database card.
        if ($show_database && isset($modules['database']) && !empty($modules['database']['enabled']) && isset($modules['database']['card'])) {
            $card = $modules['database']['card'];
            $chart = isset($modules['database']['chart']) ? $modules['database']['chart'] : null;
            $status_meta = sitepulse_dashboard_preview_get_status_meta(isset($card['status']) ? $card['status'] : '', $status_labels);
            $canvas_id = $unique_prefix . '-database-chart';
            $limit = isset($card['limit']) ? (int) $card['limit'] : 0;
            $revision_count = isset($card['revisions']) ? (int) $card['revisions'] : 0;

            $cards[] = sprintf(
                '<section class="sitepulse-card sitepulse-card--database"><div class="sitepulse-card-header"><h3>%1$s</h3></div><p class="sitepulse-card-subtitle">%2$s</p>%3$s<p class="sitepulse-metric"><span class="status-badge %4$s" aria-hidden="true"><span class="status-icon">%5$s</span><span class="status-text">%6$s</span></span><span class="screen-reader-text">%7$s</span><span class="sitepulse-metric-value">%8$s<span class="sitepulse-metric-unit">%9$s</span></span></p><p class="description">%10$s</p></section>',
                esc_html__('Santé de la base', 'sitepulse'),
                esc_html__('Volume des révisions par rapport au budget défini.', 'sitepulse'),
                sitepulse_dashboard_preview_render_chart_area($canvas_id, $chart),
                esc_attr(isset($card['status']) ? $card['status'] : ''),
                esc_html($status_meta['icon']),
                esc_html($status_meta['label']),
                esc_html($status_meta['sr']),
                esc_html(number_format_i18n($revision_count)),
                esc_html__('révisions', 'sitepulse'),
                esc_html(sprintf(
                    /* translators: %d: recommended revision limit */
                    __('Essayez de rester sous la barre des %d révisions pour éviter de gonfler la table des articles.', 'sitepulse'),
                    $limit
                ))
            );
        }

        // Logs card.
        if ($show_logs && isset($modules['logs']) && !empty($modules['logs']['enabled']) && isset($modules['logs']['card'])) {
            $card = $modules['logs']['card'];
            $chart = isset($modules['logs']['chart']) ? $modules['logs']['chart'] : null;
            $status_meta = sitepulse_dashboard_preview_get_status_meta(isset($card['status']) ? $card['status'] : '', $status_labels);
            $canvas_id = $unique_prefix . '-logs-chart';
            $counts = isset($card['counts']) && is_array($card['counts']) ? $card['counts'] : [];

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

            $legend_html = '<ul class="sitepulse-legend">' . implode('', $legend_html_parts) . '</ul>';

            $cards[] = sprintf(
                '<section class="sitepulse-card sitepulse-card--logs"><div class="sitepulse-card-header"><h3>%1$s</h3></div><p class="sitepulse-card-subtitle">%2$s</p>%3$s<p class="sitepulse-metric"><span class="status-badge %4$s" aria-hidden="true"><span class="status-icon">%5$s</span><span class="status-text">%6$s</span></span><span class="screen-reader-text">%7$s</span><span class="sitepulse-metric-value">%8$s</span></p>%9$s<p class="description">%10$s</p></section>',
                esc_html__('Journal d’erreurs', 'sitepulse'),
                esc_html__('Répartition des évènements récents trouvés dans le fichier debug.log.', 'sitepulse'),
                sitepulse_dashboard_preview_render_chart_area($canvas_id, $chart),
                esc_attr(isset($card['status']) ? $card['status'] : ''),
                esc_html($status_meta['icon']),
                esc_html($status_meta['label']),
                esc_html($status_meta['sr']),
                esc_html(isset($card['summary']) ? $card['summary'] : __('Aucune donnée disponible.', 'sitepulse')),
                $legend_html,
                esc_html__('Analysez le journal complet depuis l’interface SitePulse pour plus de détails.', 'sitepulse')
            );
        }

        if (empty($cards)) {
            return '<div class="wp-block-sitepulse-dashboard-preview sitepulse-dashboard-preview__empty">'
                . esc_html__('Aucune carte à afficher pour le moment. Activez les modules souhaités ou collectez davantage de données.', 'sitepulse')
                . '</div>';
        }

        $header = sprintf(
            '<div class="sitepulse-dashboard-preview__header"><h3>%1$s</h3><p>%2$s</p></div>',
            esc_html__('Aperçu SitePulse', 'sitepulse'),
            esc_html__('Dernières mesures agrégées par vos modules actifs.', 'sitepulse')
        );

        return '<div class="wp-block-sitepulse-dashboard-preview">' . $header
            . '<div class="sitepulse-grid">' . implode('', $cards) . '</div></div>';
    }
}
