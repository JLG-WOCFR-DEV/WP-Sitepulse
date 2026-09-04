<?php
/**
 * SitePulse dashboard theme and status helpers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retrieves the summary element ID for a chart.
 *
 * @param string $chart_id Chart identifier.
 *
 * @return string Summary element identifier.
 */
function sitepulse_get_chart_summary_id($chart_id) {
    $sanitized_id = is_string($chart_id) ? sanitize_html_class($chart_id) : '';

    if ('' === $sanitized_id) {
        $sanitized_id = 'sitepulse-chart';
    }

    return $sanitized_id . '-summary';
}

/**
 * Returns the default status labels used across dashboard cards.
 *
 * @return array<string,array<string,string>>
 */
function sitepulse_custom_dashboard_get_default_status_labels() {
    return [
        'status-ok'   => [
            'label' => __('Bon', 'sitepulse'),
            'sr'    => __('Statut : bon', 'sitepulse'),
            'icon'  => '✔️',
        ],
        'status-warn' => [
            'label' => __('Attention', 'sitepulse'),
            'sr'    => __('Statut : attention', 'sitepulse'),
            'icon'  => '⚠️',
        ],
        'status-bad'  => [
            'label' => __('Critique', 'sitepulse'),
            'sr'    => __('Statut : critique', 'sitepulse'),
            'icon'  => '⛔',
        ],
    ];
}

/**
 * Returns the theme options available for the dashboard interface.
 *
 * @return array<string,array{label:string,description:string}>
 */
function sitepulse_get_dashboard_theme_options() {
    $options = [
        'light' => [
            'label'       => __('Clair', 'sitepulse'),
            'description' => __('Palette lumineuse optimisée pour la lisibilité diurne.', 'sitepulse'),
        ],
    ];

    /**
     * Filters the available dashboard theme options.
     *
     * @param array<string,array{label:string,description:string}> $options Theme definitions.
     */
    return apply_filters('sitepulse_dashboard_theme_options', $options);
}

/**
 * Returns the default dashboard theme identifier.
 *
 * @return string
 */
function sitepulse_get_dashboard_default_theme() {
    $default = 'light';

    /**
     * Filters the default dashboard theme.
     *
     * @param string $default Default theme slug.
     */
    $filtered = apply_filters('sitepulse_dashboard_default_theme', $default);

    return sitepulse_normalize_dashboard_theme($filtered);
}

/**
 * Normalizes an incoming theme value against the allowed options.
 *
 * @param string $theme Theme identifier.
 *
 * @return string
 */
function sitepulse_normalize_dashboard_theme($theme) {
    $theme = sanitize_key((string) $theme);
    $options = sitepulse_get_dashboard_theme_options();

    if ($theme === '' || !array_key_exists($theme, $options)) {
        return 'light';
    }

    return $theme;
}

/**
 * Retrieves the current user theme preference for the dashboard.
 *
 * @param int $user_id Optional user identifier.
 *
 * @return string
 */
function sitepulse_get_dashboard_theme_preference($user_id = 0) {
    if ($user_id <= 0) {
        $user_id = get_current_user_id();
    }

    $preferences = sitepulse_get_dashboard_preferences($user_id);

    if (isset($preferences['theme'])) {
        return sitepulse_normalize_dashboard_theme($preferences['theme']);
    }

    return sitepulse_get_dashboard_default_theme();
}

/**
 * Appends SitePulse theme classes to the admin body when needed.
 *
 * @param string $classes Existing class list.
 *
 * @return string
 */
function sitepulse_custom_dashboard_body_class($classes) {
    $classes = is_string($classes) ? $classes : '';

    if (!function_exists('get_current_screen')) {
        return $classes;
    }

    $screen    = get_current_screen();
    $screen_id = $screen && isset($screen->id) ? (string) $screen->id : '';

    if ($screen_id === '') {
        return $classes;
    }

    $is_dashboard_root   = $screen_id === 'toplevel_page_sitepulse-dashboard';
    $is_module_screen    = strpos($screen_id, 'sitepulse-dashboard_page_sitepulse-') === 0;
    $is_embedded_screen  = strpos($screen_id, 'dashboard_page_sitepulse-') === 0;

    if (!$is_dashboard_root && !$is_module_screen && !$is_embedded_screen) {
        return $classes;
    }

    $theme = sitepulse_get_dashboard_theme_preference(get_current_user_id());
    $theme = sitepulse_normalize_dashboard_theme($theme);
    $theme_class = sanitize_html_class('sitepulse-theme--' . $theme);

    if ($theme_class === '') {
        return $classes;
    }

    return trim($classes . ' sitepulse-theme ' . $theme_class);
}

/**
 * Renders the theme toggle control for the dashboard.
 *
 * @param string                                   $current_theme Active theme identifier.
 * @param array<string,array<string,string>>       $options       Available theme options.
 *
 * @return string
 */
function sitepulse_render_dashboard_theme_toggle($current_theme, $options) {
    if (!is_array($options) || count($options) <= 1) {
        return '';
    }

    $current_theme = sitepulse_normalize_dashboard_theme($current_theme);

    ob_start();
    ?>
    <fieldset class="sitepulse-theme-toggle" data-sitepulse-theme-toggle>
        <legend><?php esc_html_e('Apparence', 'sitepulse'); ?></legend>
        <p class="sitepulse-theme-toggle__hint"><?php esc_html_e('Choisissez la palette appliquée à SitePulse.', 'sitepulse'); ?></p>
        <div class="sitepulse-theme-toggle__options" role="presentation">
            <?php foreach ($options as $theme_key => $theme_definition) :
                $theme_slug = sanitize_key($theme_key);

                if ($theme_slug === '') {
                    continue;
                }

                $label = isset($theme_definition['label']) ? (string) $theme_definition['label'] : ucfirst($theme_slug);
                $description = isset($theme_definition['description']) ? (string) $theme_definition['description'] : '';
                $input_id = 'sitepulse-theme-' . $theme_slug;
                $description_id = $description !== '' ? $input_id . '-description' : '';
                $is_selected = ($theme_slug === $current_theme);
                $description_attr = $description_id !== '' ? ' aria-describedby="' . esc_attr($description_id) . '"' : '';
            ?>
                <label class="sitepulse-theme-toggle__option<?php echo $is_selected ? ' is-selected' : ''; ?>" for="<?php echo esc_attr($input_id); ?>" data-theme="<?php echo esc_attr($theme_slug); ?>">
                    <?php
                    printf(
                        '<input type="radio" id="%1$s" name="sitepulse-theme" value="%2$s"%3$s%4$s data-sitepulse-theme-option />',
                        esc_attr($input_id),
                        esc_attr($theme_slug),
                        checked($is_selected, true, false),
                        $description_attr
                    );
                    ?>
                    <span class="sitepulse-theme-toggle__label"><?php echo esc_html($label); ?></span>
                    <?php if ($description !== '') : ?>
                        <span class="sitepulse-theme-toggle__description" id="<?php echo esc_attr($description_id); ?>"><?php echo esc_html($description); ?></span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
        <span class="screen-reader-text" aria-live="polite" data-sitepulse-theme-announcer></span>
    </fieldset>
    <?php

    return (string) ob_get_clean();
}

/**
 * Resolves the status meta for a given status key.
 *
 * @param string                              $status Status identifier.
 * @param array<string,array<string,string>>  $labels Optional custom labels.
 *
 * @return array<string,string>
 */
function sitepulse_custom_dashboard_resolve_status_meta($status, $labels = []) {
    $defaults = sitepulse_custom_dashboard_get_default_status_labels();

    if (is_array($labels) && !empty($labels)) {
        $labels = array_merge($defaults, $labels);
    } else {
        $labels = $defaults;
    }

    if (isset($labels[$status])) {
        return $labels[$status];
    }

    if (isset($labels['status-warn'])) {
        return $labels['status-warn'];
    }

    $first = reset($labels);

    if (is_array($first)) {
        return $first;
    }

    return [
        'label' => __('Attention', 'sitepulse'),
        'sr'    => __('Statut : attention', 'sitepulse'),
        'icon'  => '⚠️',
    ];
}

/**
 * Resolves a status key based on a normalized score.
 *
 * @param float|int|null $score Severity score in the range 0-100.
 * @return string
 */
function sitepulse_custom_dashboard_resolve_score_status($score) {
    if (!is_numeric($score)) {
        return 'status-warn';
    }

    $normalized = (float) $score;

    if ($normalized >= 70.0) {
        return 'status-bad';
    }

    if ($normalized >= 35.0) {
        return 'status-warn';
    }

    return 'status-ok';
}

/**
 * Normalizes a metric value into a severity ratio between 0 and 1.
 *
 * @param float|int|null $value      Raw metric value.
 * @param float|int      $warning    Threshold where the signal starts to degrade.
 * @param float|int      $critical   Threshold where the signal is considered critical.
 * @param string         $direction  Either 'higher-is-worse' or 'higher-is-better'.
 * @return float Normalized ratio clamped between 0 and 1.
 */
function sitepulse_custom_dashboard_calculate_severity_ratio($value, $warning, $critical, $direction = 'higher-is-worse') {
    if (!is_numeric($value)) {
        return 0.0;
    }

    $metric   = (float) $value;
    $warning  = (float) $warning;
    $critical = (float) $critical;

    if ('higher-is-better' === $direction) {
        if ($critical >= $warning) {
            $critical = $warning - 0.1;
        }

        if ($metric >= $warning) {
            return 0.0;
        }

        if ($metric <= $critical) {
            return 1.0;
        }

        $range = $warning - $critical;

        if ($range <= 0) {
            return $metric <= $warning ? 1.0 : 0.0;
        }

        return min(1.0, max(0.0, ($warning - $metric) / $range));
    }

    if ($critical <= $warning) {
        $critical = $warning + 0.1;
    }

    if ($metric <= $warning) {
        return 0.0;
    }

    if ($metric >= $critical) {
        return 1.0;
    }

    $range = $critical - $warning;

    if ($range <= 0) {
        return $metric >= $warning ? 1.0 : 0.0;
    }

    return min(1.0, max(0.0, ($metric - $warning) / $range));
}

/**
 * Builds an accessible summary list for a chart dataset.
 *
 * @param string $chart_id    Base identifier for the chart.
 * @param array  $chart_data  Chart configuration array containing labels and datasets.
 *
 * @return string Rendered HTML list or an empty string when no data is available.
 */
function sitepulse_render_chart_summary($chart_id, $chart_data) {
    if (!is_string($chart_id) || $chart_id === '' || !is_array($chart_data)) {
        return '';
    }

    $labels = isset($chart_data['labels']) ? (array) $chart_data['labels'] : [];
    $datasets = isset($chart_data['datasets']) && is_array($chart_data['datasets'])
        ? $chart_data['datasets']
        : [];

    if (empty($labels) || empty($datasets)) {
        return '';
    }

    $unit = '';

    if (isset($chart_data['unit']) && is_string($chart_data['unit']) && $chart_data['unit'] !== '') {
        $unit = $chart_data['unit'];
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
                $precision = floor($numeric_value) === $numeric_value ? 0 : 2;
                $formatted_value = number_format_i18n($numeric_value, $precision);
            } elseif (is_scalar($value)) {
                $formatted_value = (string) $value;
            } else {
                continue;
            }

            if ('' !== $unit) {
                $formatted_value .= ' ' . $unit;
            }

            $values[] = $formatted_value;
        }

        if (empty($values)) {
            continue;
        }

        $items[] = sprintf(
            '<li>%1$s: %2$s</li>',
            esc_html(wp_strip_all_tags((string) $label)),
            esc_html(implode(', ', $values))
        );
    }

    if (empty($items)) {
        return '';
    }

    $summary_id = sitepulse_get_chart_summary_id($chart_id);

    return sprintf(
        '<ul id="%1$s" class="sitepulse-chart-summary">%2$s</ul>',
        esc_attr($summary_id),
        implode('', $items)
    );
}
