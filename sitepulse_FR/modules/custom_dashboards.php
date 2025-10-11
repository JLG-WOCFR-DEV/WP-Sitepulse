<?php
/**
 * SitePulse Custom Dashboards Module
 *
 * This module creates the main dashboard page for the plugin.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly.

if (!defined('SITEPULSE_TRANSIENT_DEBUG_LOG_SUMMARY')) {
    define('SITEPULSE_TRANSIENT_DEBUG_LOG_SUMMARY', 'sitepulse_dashboard_log_summary');
}

add_action('admin_enqueue_scripts', 'sitepulse_custom_dashboard_enqueue_assets');
add_action('wp_ajax_sitepulse_save_dashboard_preferences', 'sitepulse_save_dashboard_preferences');
add_action('rest_api_init', 'sitepulse_custom_dashboard_register_rest_routes');
add_filter('admin_body_class', 'sitepulse_custom_dashboard_body_class');

/**
 * Registers the assets used by the SitePulse dashboard when the page is loaded.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
function sitepulse_custom_dashboard_enqueue_assets($hook_suffix) {
    if ('toplevel_page_sitepulse-dashboard' !== $hook_suffix) {
        return;
    }

    $default_chartjs_src = SITEPULSE_URL . 'modules/vendor/chart.js/chart.umd.js';
    $chartjs_src = apply_filters('sitepulse_chartjs_src', $default_chartjs_src);

    if ($chartjs_src !== $default_chartjs_src) {
        $original_chartjs_src = $chartjs_src;
        $is_valid_chartjs_src = false;

        if (is_string($chartjs_src) && $chartjs_src !== '') {
            $validated_chartjs_src = wp_http_validate_url($chartjs_src);

            if ($validated_chartjs_src !== false) {
                $parsed_chartjs_src = wp_parse_url($validated_chartjs_src);
                $scheme = isset($parsed_chartjs_src['scheme']) ? strtolower($parsed_chartjs_src['scheme']) : '';
                $is_https = ('https' === $scheme);
                $is_plugin_internal = false;

                $sitepulse_base = wp_parse_url(SITEPULSE_URL);

                if (is_array($parsed_chartjs_src) && is_array($sitepulse_base)) {
                    $source_host = isset($parsed_chartjs_src['host']) ? strtolower($parsed_chartjs_src['host']) : '';
                    $base_host = isset($sitepulse_base['host']) ? strtolower($sitepulse_base['host']) : '';

                    if ($source_host && $base_host && $source_host === $base_host) {
                        $source_path = isset($parsed_chartjs_src['path']) ? $parsed_chartjs_src['path'] : '';
                        $base_path = isset($sitepulse_base['path']) ? $sitepulse_base['path'] : '';

                        if ($base_path === '' || strpos($source_path, $base_path) === 0) {
                            $is_plugin_internal = true;
                        }
                    }
                }

                if ($is_https || $is_plugin_internal) {
                    $chartjs_src = $validated_chartjs_src;
                    $is_valid_chartjs_src = true;
                }
            } elseif (strpos($chartjs_src, SITEPULSE_URL) === 0) {
                // Allow internal plugin URLs even if wp_http_validate_url() returned false.
                $is_valid_chartjs_src = true;
            }
        }

        if (!$is_valid_chartjs_src) {
            if (function_exists('sitepulse_log')) {
                $log_value = '';

                if (is_string($original_chartjs_src)) {
                    $log_value = esc_url_raw($original_chartjs_src);
                } elseif (is_scalar($original_chartjs_src)) {
                    $log_value = (string) $original_chartjs_src;
                } else {
                    $encoded_value = wp_json_encode($original_chartjs_src);
                    $log_value = is_string($encoded_value) ? $encoded_value : '';
                }

                sitepulse_log(
                    sprintf(
                        'SitePulse: invalid Chart.js source override rejected. Value: %s',
                        $log_value
                    ),
                    'DEBUG'
                );
            }

            $chartjs_src = $default_chartjs_src;
        }
    }

    wp_register_style(
        'sitepulse-dashboard-theme',
        SITEPULSE_URL . 'modules/css/sitepulse-theme.css',
        [],
        SITEPULSE_VERSION
    );

    wp_enqueue_style('sitepulse-dashboard-theme');

    wp_register_style(
        'sitepulse-module-navigation',
        SITEPULSE_URL . 'modules/css/module-navigation.css',
        ['sitepulse-dashboard-theme'],
        SITEPULSE_VERSION
    );

    wp_enqueue_style('sitepulse-module-navigation');

    wp_register_style(
        'sitepulse-custom-dashboard',
        SITEPULSE_URL . 'modules/css/custom-dashboard.css',
        ['sitepulse-module-navigation'],
        SITEPULSE_VERSION
    );

    wp_enqueue_style('sitepulse-custom-dashboard');

    wp_register_script(
        'sitepulse-dashboard-nav',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-nav.js',
        [],
        SITEPULSE_VERSION,
        true
    );

    wp_enqueue_script('sitepulse-dashboard-nav');

    wp_register_script(
        'sitepulse-chartjs',
        $chartjs_src,
        [],
        '4.4.5',
        true
    );

    if ($chartjs_src !== $default_chartjs_src) {
        $fallback_loader = '(function(){if (typeof window.Chart === "undefined") {'
            . 'var script=document.createElement("script");'
            . 'script.src=' . wp_json_encode($default_chartjs_src) . ';'
            . 'script.defer=true;'
            . 'document.head.appendChild(script);'
            . '}})();';

        wp_add_inline_script('sitepulse-chartjs', $fallback_loader, 'after');
    }

    wp_register_script(
        'sitepulse-dashboard-charts',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-charts.js',
        ['sitepulse-chartjs'],
        SITEPULSE_VERSION,
        true
    );

    wp_register_script(
        'sitepulse-dashboard-preferences',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-preferences.js',
        ['jquery', 'jquery-ui-sortable'],
        SITEPULSE_VERSION,
        true
    );

    wp_register_script(
        'sitepulse-dashboard-metrics',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-metrics.js',
        ['wp-a11y'],
        SITEPULSE_VERSION,
        true
    );
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
        'auto'  => [
            'label'       => __('Automatique', 'sitepulse'),
            'description' => __('Suit le thème défini par votre système.', 'sitepulse'),
        ],
        'light' => [
            'label'       => __('Clair', 'sitepulse'),
            'description' => __('Palette lumineuse optimisée pour la lisibilité diurne.', 'sitepulse'),
        ],
        'dark'  => [
            'label'       => __('Sombre', 'sitepulse'),
            'description' => __('Palette sombre pour réduire la fatigue visuelle.', 'sitepulse'),
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
    $default = 'auto';

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
        return 'auto';
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
    if (!is_array($options) || empty($options)) {
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

/**
 * Returns the identifiers of the dashboard cards that can be customised.
 *
 * @return string[]
 */
function sitepulse_get_dashboard_card_keys() {
    return ['speed', 'uptime', 'database', 'logs', 'resource', 'plugins'];
}

/**
 * Provides the default dashboard preferences for the supplied cards.
 *
 * @param string[]|null $allowed_cards Optional subset of cards to include.
 *
 * @return array{
 *     order: string[],
 *     visibility: array<string,bool>,
 *     sizes: array<string,string>,
 *     theme: string
 * }
*/
function sitepulse_get_dashboard_default_preferences($allowed_cards = null) {
    $card_keys = sitepulse_get_dashboard_card_keys();

    if (is_array($allowed_cards) && !empty($allowed_cards)) {
        $allowed_cards = array_values(array_filter(array_map('strval', $allowed_cards)));

        if (!empty($allowed_cards)) {
            $card_keys = array_values(array_unique(array_merge(
                array_intersect($card_keys, $allowed_cards),
                $allowed_cards
            )));
        }
    }

    $order = $card_keys;
    $visibility = [];
    $sizes = [];

    foreach ($card_keys as $key) {
        $visibility[$key] = true;
        $sizes[$key] = 'medium';
    }

    return [
        'order'      => $order,
        'visibility' => $visibility,
        'sizes'      => $sizes,
        'theme'      => sitepulse_get_dashboard_default_theme(),
    ];
}

/**
 * Sanitizes a set of dashboard preferences.
 *
 * @param array            $raw_preferences Potentially unsanitized preferences.
 * @param string[]|null    $allowed_cards   Optional subset of cards to accept.
 *
 * @return array{
 *     order: string[],
 *     visibility: array<string,bool>,
 *     sizes: array<string,string>,
 *     theme: string
 * }
 */
function sitepulse_sanitize_dashboard_preferences($raw_preferences, $allowed_cards = null) {
    $defaults = sitepulse_get_dashboard_default_preferences($allowed_cards);
    $allowed_cards = $defaults['order'];
    $allowed_sizes = ['small', 'medium', 'large'];
    $allowed_themes = array_keys(sitepulse_get_dashboard_theme_options());

    $order = [];

    if (isset($raw_preferences['order']) && is_array($raw_preferences['order'])) {
        foreach ($raw_preferences['order'] as $card_key) {
            $card_key = sanitize_key((string) $card_key);

            if ($card_key !== '' && in_array($card_key, $allowed_cards, true) && !in_array($card_key, $order, true)) {
                $order[] = $card_key;
            }
        }
    }

    foreach ($allowed_cards as $card_key) {
        if (!in_array($card_key, $order, true)) {
            $order[] = $card_key;
        }
    }

    $visibility = [];

    if (isset($raw_preferences['visibility']) && is_array($raw_preferences['visibility'])) {
        foreach ($allowed_cards as $card_key) {
            if (array_key_exists($card_key, $raw_preferences['visibility'])) {
                $visibility[$card_key] = filter_var(
                    $raw_preferences['visibility'][$card_key],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );

                if ($visibility[$card_key] === null) {
                    $visibility[$card_key] = $defaults['visibility'][$card_key];
                }

                continue;
            }

            $visibility[$card_key] = $defaults['visibility'][$card_key];
        }
    } else {
        $visibility = $defaults['visibility'];
    }

    $sizes = [];

    if (isset($raw_preferences['sizes']) && is_array($raw_preferences['sizes'])) {
        foreach ($allowed_cards as $card_key) {
            if (array_key_exists($card_key, $raw_preferences['sizes'])) {
                $size_value = strtolower((string) $raw_preferences['sizes'][$card_key]);

                if (!in_array($size_value, $allowed_sizes, true)) {
                    $size_value = $defaults['sizes'][$card_key];
                }

                $sizes[$card_key] = $size_value;
                continue;
            }

            $sizes[$card_key] = $defaults['sizes'][$card_key];
        }
    } else {
        $sizes = $defaults['sizes'];
    }

    $theme = $defaults['theme'];

    if (isset($raw_preferences['theme'])) {
        $candidate = sanitize_key((string) $raw_preferences['theme']);

        if (in_array($candidate, $allowed_themes, true)) {
            $theme = $candidate;
        }
    }

    return [
        'order'      => $order,
        'visibility' => $visibility,
        'sizes'      => $sizes,
        'theme'      => $theme,
    ];
}

/**
 * Returns the saved dashboard preferences for a given user.
 *
 * @param int              $user_id       Optional user identifier.
 * @param string[]|null    $allowed_cards Optional subset of cards to accept.
 *
 * @return array{
 *     order: string[],
 *     visibility: array<string,bool>,
 *     sizes: array<string,string>
 * }
 */
/**
 * Returns the option name used to store the preferred dashboard range.
 *
 * @return string
 */
function sitepulse_custom_dashboard_get_range_option_name() {
    return defined('SITEPULSE_OPTION_DASHBOARD_RANGE')
        ? SITEPULSE_OPTION_DASHBOARD_RANGE
        : 'sitepulse_dashboard_range';
}

/**
 * Retrieves the supported time ranges for dashboard metrics.
 *
 * @return array<string,array<string,int|string>>
 */
function sitepulse_custom_dashboard_get_metric_ranges() {
    $day_in_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

    $ranges = [
        '24h' => [
            'id'      => '24h',
            'label'   => __('Last 24 hours', 'sitepulse'),
            'seconds' => $day_in_seconds,
            'days'    => 1,
        ],
        '7d' => [
            'id'      => '7d',
            'label'   => __('Last 7 days', 'sitepulse'),
            'seconds' => $day_in_seconds * 7,
            'days'    => 7,
        ],
        '30d' => [
            'id'      => '30d',
            'label'   => __('Last 30 days', 'sitepulse'),
            'seconds' => $day_in_seconds * 30,
            'days'    => 30,
        ],
    ];

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_dashboard_metric_ranges', $ranges);

        if (is_array($filtered) && !empty($filtered)) {
            $ranges = $filtered;
        }
    }

    $normalized = [];

    foreach ($ranges as $key => $config) {
        $range_id = '';

        if (is_array($config) && isset($config['id'])) {
            $range_id = sanitize_key($config['id']);
        }

        if ($range_id === '') {
            $range_id = is_string($key) ? sanitize_key($key) : '';
        }

        if ($range_id === '') {
            continue;
        }

        $label = '';

        if (is_array($config) && isset($config['label']) && is_string($config['label'])) {
            $label = $config['label'];
        } else {
            $label = $range_id;
        }

        $seconds = 0;

        if (is_array($config) && isset($config['seconds'])) {
            $seconds = (int) $config['seconds'];
        }

        $days = 0;

        if (is_array($config) && isset($config['days'])) {
            $days = (int) $config['days'];
        }

        if ($seconds <= 0) {
            if ($days > 0) {
                $seconds = $days * $day_in_seconds;
            } elseif ($range_id === '24h') {
                $seconds = $day_in_seconds;
                $days    = 1;
            } elseif ($range_id === '7d') {
                $seconds = $day_in_seconds * 7;
                $days    = 7;
            } elseif ($range_id === '30d') {
                $seconds = $day_in_seconds * 30;
                $days    = 30;
            }
        }

        if ($days <= 0 && $seconds > 0) {
            $days = max(1, (int) round($seconds / $day_in_seconds));
        }

        $normalized[$range_id] = [
            'id'      => $range_id,
            'label'   => $label,
            'seconds' => max(0, $seconds),
            'days'    => max(1, $days),
        ];
    }

    if (empty($normalized)) {
        $normalized = [
            '7d' => [
                'id'      => '7d',
                'label'   => __('Last 7 days', 'sitepulse'),
                'seconds' => $day_in_seconds * 7,
                'days'    => 7,
            ],
        ];
    }

    return $normalized;
}

/**
 * Returns the default range identifier when no preference is stored.
 *
 * @return string
 */
function sitepulse_custom_dashboard_get_default_range() {
    $ranges = sitepulse_custom_dashboard_get_metric_ranges();

    if (isset($ranges['7d'])) {
        return '7d';
    }

    $keys = array_keys($ranges);

    return isset($keys[0]) ? $keys[0] : '7d';
}

/**
 * Sanitizes a range identifier against the supported configuration.
 *
 * @param mixed $value Raw range value.
 * @return string Sanitized range identifier or an empty string if unsupported.
 */
function sitepulse_custom_dashboard_sanitize_range($value) {
    if (!is_string($value) || $value === '') {
        return '';
    }

    $range = sanitize_key($value);
    $ranges = sitepulse_custom_dashboard_get_metric_ranges();

    if ($range !== '' && isset($ranges[$range])) {
        return $range;
    }

    return '';
}

/**
 * Retrieves the persisted dashboard range preference.
 *
 * @return string
 */
function sitepulse_custom_dashboard_get_stored_range() {
    $option_name = sitepulse_custom_dashboard_get_range_option_name();
    $stored      = get_option($option_name, '');
    $sanitized   = sitepulse_custom_dashboard_sanitize_range($stored);

    if ($sanitized !== '') {
        return $sanitized;
    }

    return sitepulse_custom_dashboard_get_default_range();
}

/**
 * Retrieves the current timestamp using WordPress when possible.
 *
 * @return int
 */
function sitepulse_custom_dashboard_get_current_timestamp() {
    if (function_exists('current_time')) {
        return (int) current_time('timestamp');
    }

    return time();
}

/**
 * Retrieves the configured uptime warning threshold.
 *
 * @return float
 */
function sitepulse_custom_dashboard_get_uptime_warning_threshold() {
    $default = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT')
        ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT
        : 99.0;

    if (function_exists('sitepulse_get_uptime_warning_percentage')) {
        $threshold = (float) sitepulse_get_uptime_warning_percentage();
    } else {
        $option_key = defined('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT')
            ? SITEPULSE_OPTION_UPTIME_WARNING_PERCENT
            : 'sitepulse_uptime_warning_percent';

        $stored = get_option($option_key, $default);

        $threshold = is_scalar($stored) ? (float) $stored : $default;
    }

    if ($threshold < 0) {
        $threshold = 0.0;
    } elseif ($threshold > 100) {
        $threshold = 100.0;
    }

    return $threshold;
}

/**
 * Resolves the status string for an uptime percentage.
 *
 * @param float|int|null $uptime_value Uptime percentage.
 *
 * @return string
 */
function sitepulse_custom_dashboard_resolve_uptime_status($uptime_value) {
    if (!is_numeric($uptime_value)) {
        return 'status-warn';
    }

    $threshold = sitepulse_custom_dashboard_get_uptime_warning_threshold();
    $value     = (float) $uptime_value;

    if ($value < $threshold) {
        return 'status-bad';
    }

    if ($value < 100.0) {
        return 'status-warn';
    }

    return 'status-ok';
}

/**
 * Resolves a human-readable label for the provided range identifier.
 *
 * @param string                          $range            Range identifier.
 * @param array<int,array<string,mixed>>  $available_ranges Available range definitions.
 *
 * @return string
 */
function sitepulse_custom_dashboard_resolve_range_label($range, $available_ranges) {
    if (is_array($available_ranges)) {
        foreach ($available_ranges as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $definition_id = isset($definition['id']) ? (string) $definition['id'] : '';

            if ($definition_id === '') {
                continue;
            }

            if ($definition_id === $range) {
                if (isset($definition['label']) && is_string($definition['label']) && $definition['label'] !== '') {
                    return $definition['label'];
                }

                return $definition_id;
            }
        }
    }

    $ranges = sitepulse_custom_dashboard_get_metric_ranges();

    if (isset($ranges[$range]['label']) && is_string($ranges[$range]['label'])) {
        return $ranges[$range]['label'];
    }

    switch ($range) {
        case '24h':
            return __('Last 24 hours', 'sitepulse');
        case '30d':
            return __('Last 30 days', 'sitepulse');
        case '7d':
        default:
            return __('Last 7 days', 'sitepulse');
    }
}

/**
 * Formats a delta value into a trend descriptor.
 *
 * @param float|int|null $delta Numeric delta compared to previous window.
 * @param array<string,mixed> $args Optional configuration.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_trend($delta, $args = []) {
    $defaults = [
        'tolerance'         => 0.01,
        'unit'              => '',
        'precision'         => 2,
        'increase_good'     => true,
        'positive_template' => __('Improved by %s%s', 'sitepulse'),
        'negative_template' => __('Regressed by %s%s', 'sitepulse'),
        'positive_sr'       => __('Metric improved by %s%s compared to the previous window.', 'sitepulse'),
        'negative_sr'       => __('Metric regressed by %s%s compared to the previous window.', 'sitepulse'),
        'stable_template'   => __('Stable compared to the previous window.', 'sitepulse'),
        'stable_sr'         => __('Metric is stable compared to the previous window.', 'sitepulse'),
        'missing_template'  => __('No comparison available for this metric yet.', 'sitepulse'),
        'missing_sr'        => __('Comparison data is not available for this metric.', 'sitepulse'),
    ];

    $config = array_merge($defaults, is_array($args) ? $args : []);

    if (!is_numeric($delta)) {
        $text = $config['missing_template'];

        return [
            'direction' => 'flat',
            'text'      => $text,
            'sr'        => $config['missing_sr'],
            'value'     => null,
        ];
    }

    $numeric_delta = (float) $delta;
    $absolute       = abs($numeric_delta);

    if ($absolute < (float) $config['tolerance']) {
        return [
            'direction' => 'flat',
            'text'      => $config['stable_template'],
            'sr'        => $config['stable_sr'],
            'value'     => round($numeric_delta, (int) $config['precision']),
        ];
    }

    $precision = (int) $config['precision'];
    $formatted = number_format_i18n($absolute, $precision);
    $unit      = is_string($config['unit']) ? $config['unit'] : '';

    if ($unit !== '' && !preg_match('/^\s/u', $unit)) {
        $unit = ' ' . $unit;
    }

    $is_positive = $numeric_delta > 0;
    $is_improvement = $config['increase_good'] ? $is_positive : !$is_positive;
    $template = $is_improvement ? $config['positive_template'] : $config['negative_template'];
    $sr_template = $is_improvement ? $config['positive_sr'] : $config['negative_sr'];
    $direction = $is_improvement ? 'up' : 'down';

    $text = sprintf($template, $formatted, $unit);
    $sr   = sprintf($sr_template, $formatted, $unit);

    return [
        'direction' => $direction,
        'text'      => $text,
        'sr'        => $sr,
        'value'     => round($numeric_delta, $precision),
    ];
}

/**
 * Formats uptime metrics for display in the KPI grid.
 *
 * @param array<string,mixed>|null $uptime      Raw uptime metrics.
 * @param bool                     $is_active   Whether the module is active.
 * @param string                   $range_label Human-readable range label.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_uptime_card_view($uptime, $is_active, $range_label) {
    $status_meta = sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $card = [
        'label'             => __('Availability', 'sitepulse'),
        'status'            => array_merge($status_meta, ['class' => 'status-warn']),
        'value'             => ['text' => __('N/A', 'sitepulse'), 'unit' => ''],
        'summary'           => __('No uptime data collected yet.', 'sitepulse'),
        'trend'             => sitepulse_custom_dashboard_format_trend(null),
        'details'           => [],
        'description'       => __('Once checks run, uptime results will appear here.', 'sitepulse'),
        'inactive'          => !$is_active,
        'inactive_message'  => __('Activate the Uptime Tracker module to populate this metric.', 'sitepulse'),
    ];

    if ($card['inactive']) {
        return $card;
    }

    if (!is_array($uptime) || empty($uptime)) {
        return $card;
    }

    $uptime_value = isset($uptime['uptime']) ? $uptime['uptime'] : null;
    $status       = sitepulse_custom_dashboard_resolve_uptime_status($uptime_value);
    $status_meta  = sitepulse_custom_dashboard_resolve_status_meta($status);
    $status_meta['class'] = $status;

    $card['status'] = $status_meta;

    if (is_numeric($uptime_value)) {
        $card['value'] = [
            'text' => number_format_i18n((float) $uptime_value, 2),
            'unit' => '%',
        ];
    }

    $totals = isset($uptime['totals']) && is_array($uptime['totals']) ? $uptime['totals'] : [];
    $up      = isset($totals['up']) ? (int) $totals['up'] : 0;
    $down    = isset($totals['down']) ? (int) $totals['down'] : 0;
    $unknown = isset($totals['unknown']) ? (int) $totals['unknown'] : 0;
    $total   = isset($totals['total']) ? (int) $totals['total'] : ($up + $down + $unknown);

    $card['summary'] = sprintf(
        __('%1$s up · %2$s down · %3$s unknown', 'sitepulse'),
        number_format_i18n($up),
        number_format_i18n($down),
        number_format_i18n($unknown)
    );

    $latency_avg = isset($uptime['latency_avg']) && is_numeric($uptime['latency_avg'])
        ? (float) $uptime['latency_avg']
        : null;
    $ttfb_avg = isset($uptime['ttfb_avg']) && is_numeric($uptime['ttfb_avg'])
        ? (float) $uptime['ttfb_avg']
        : null;
    $violations = isset($uptime['violations']) ? (int) $uptime['violations'] : 0;

    $card['details'] = [
        [
            'label' => __('Average latency', 'sitepulse'),
            'value' => $latency_avg !== null
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n($latency_avg, 2))
                : __('N/A', 'sitepulse'),
        ],
        [
            'label' => __('Average TTFB', 'sitepulse'),
            'value' => $ttfb_avg !== null
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n($ttfb_avg, 2))
                : __('N/A', 'sitepulse'),
        ],
        [
            'label' => __('Downtime events', 'sitepulse'),
            'value' => number_format_i18n($violations),
        ],
    ];

    $card['trend'] = sitepulse_custom_dashboard_format_trend(
        isset($uptime['trend']['uptime']) ? $uptime['trend']['uptime'] : null,
        [
            'tolerance'         => 0.05,
            'precision'         => 2,
            'unit'              => __(' pts', 'sitepulse'),
            'increase_good'     => true,
            'positive_template' => __('Uptime improved by %s%s', 'sitepulse'),
            'negative_template' => __('Uptime decreased by %s%s', 'sitepulse'),
            'positive_sr'       => __('Availability improved by %s%s compared to the previous window.', 'sitepulse'),
            'negative_sr'       => __('Availability decreased by %s%s compared to the previous window.', 'sitepulse'),
        ]
    );

    if ($total > 0) {
        $card['description'] = sprintf(
            __('Based on %1$s checks over %2$s.', 'sitepulse'),
            number_format_i18n($total),
            $range_label
        );
    } else {
        $card['description'] = __('No uptime checks recorded during this window.', 'sitepulse');
    }

    return $card;
}

/**
 * Formats debug log metrics for display in the KPI grid.
 *
 * @param array<string,mixed>|null $logs      Raw log metrics.
 * @param bool                     $is_active Whether the module is active.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_log_card_view($logs, $is_active) {
    $status_meta = sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $card = [
        'label'             => __('Error log', 'sitepulse'),
        'status'            => array_merge($status_meta, ['class' => 'status-warn']),
        'value'             => ['text' => __('Unavailable', 'sitepulse'), 'unit' => ''],
        'summary'           => __('No log metrics available.', 'sitepulse'),
        'trend'             => [
            'direction' => 'flat',
            'text'      => __('Monitoring for new events.', 'sitepulse'),
            'sr'        => __('Awaiting new log activity.', 'sitepulse'),
            'value'     => null,
        ],
        'details'           => [],
        'description'       => __('Once the analyzer scans debug.log, results will appear here.', 'sitepulse'),
        'inactive'          => !$is_active,
        'inactive_message'  => __('Activate the Error Alerts module to monitor the debug log.', 'sitepulse'),
    ];

    if ($card['inactive']) {
        return $card;
    }

    if (!is_array($logs) || empty($logs)) {
        return $card;
    }

    $card_payload = isset($logs['card']) && is_array($logs['card']) ? $logs['card'] : [];
    $counts       = isset($card_payload['counts']) && is_array($card_payload['counts'])
        ? $card_payload['counts']
        : [];

    $fatal      = isset($counts['fatal']) ? (int) $counts['fatal'] : 0;
    $warning    = isset($counts['warning']) ? (int) $counts['warning'] : 0;
    $notice     = isset($counts['notice']) ? (int) $counts['notice'] : 0;
    $deprecated = isset($counts['deprecated']) ? (int) $counts['deprecated'] : 0;

    if ($fatal > 0) {
        $status = 'status-bad';
        $value_text = sprintf(
            _n('%s fatal error', '%s fatal errors', $fatal, 'sitepulse'),
            number_format_i18n($fatal)
        );
    } elseif ($warning > 0) {
        $status = 'status-warn';
        $value_text = sprintf(
            _n('%s warning', '%s warnings', $warning, 'sitepulse'),
            number_format_i18n($warning)
        );
    } elseif ($deprecated > 0) {
        $status = 'status-warn';
        $value_text = sprintf(
            _n('%s deprecated notice', '%s deprecated notices', $deprecated, 'sitepulse'),
            number_format_i18n($deprecated)
        );
    } elseif ($notice > 0) {
        $status = 'status-warn';
        $value_text = sprintf(
            _n('%s notice', '%s notices', $notice, 'sitepulse'),
            number_format_i18n($notice)
        );
    } else {
        $status = 'status-ok';
        $value_text = __('Log clean', 'sitepulse');
    }

    $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status);
    $status_meta['class'] = $status;
    $card['status'] = $status_meta;
    $card['value']  = ['text' => $value_text, 'unit' => ''];

    if (isset($card_payload['summary']) && is_string($card_payload['summary'])) {
        $card['summary'] = $card_payload['summary'];
    }

    $card['details'] = [
        ['label' => __('Fatal errors', 'sitepulse'), 'value' => number_format_i18n($fatal)],
        ['label' => __('Warnings', 'sitepulse'), 'value' => number_format_i18n($warning)],
        ['label' => __('Deprecated', 'sitepulse'), 'value' => number_format_i18n($deprecated)],
        ['label' => __('Notices', 'sitepulse'), 'value' => number_format_i18n($notice)],
    ];

    $metadata = isset($logs['metadata']) && is_array($logs['metadata']) ? $logs['metadata'] : [];

    if (!empty($metadata['truncated'])) {
        $card['details'][] = [
            'label' => __('Snapshot', 'sitepulse'),
            'value' => __('Tail of log displayed', 'sitepulse'),
        ];
    }

    $last_modified = isset($metadata['last_modified']) ? (int) $metadata['last_modified'] : 0;

    if ($last_modified > 0 && function_exists('human_time_diff')) {
        $ago = human_time_diff($last_modified, sitepulse_custom_dashboard_get_current_timestamp());
        $card['description'] = sprintf(__('Last updated %s ago.', 'sitepulse'), $ago);
    } elseif (isset($metadata['path']) && is_string($metadata['path']) && $metadata['path'] !== '') {
        $card['description'] = sprintf(__('Log file: %s', 'sitepulse'), $metadata['path']);
    }

    return $card;
}

/**
 * Formats speed metrics for display in the KPI grid.
 *
 * @param array<string,mixed>|null $speed       Raw speed metrics.
 * @param bool                     $is_active   Whether the module is active.
 * @param string                   $range_label Range label.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_speed_card_view($speed, $is_active, $range_label) {
    $status_meta = sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $card = [
        'label'             => __('Backend speed', 'sitepulse'),
        'status'            => array_merge($status_meta, ['class' => 'status-warn']),
        'value'             => ['text' => __('N/A', 'sitepulse'), 'unit' => ''],
        'summary'           => __('No scans recorded during this window.', 'sitepulse'),
        'trend'             => sitepulse_custom_dashboard_format_trend(null),
        'details'           => [],
        'description'       => __('Run a speed scan to populate this metric.', 'sitepulse'),
        'inactive'          => !$is_active,
        'inactive_message'  => __('Activate the Speed Analyzer module to track processing times.', 'sitepulse'),
    ];

    if ($card['inactive']) {
        return $card;
    }

    if (!is_array($speed) || empty($speed)) {
        return $card;
    }

    $average = isset($speed['average']) && is_numeric($speed['average']) ? (float) $speed['average'] : null;
    $latest  = isset($speed['latest']) && is_array($speed['latest']) ? $speed['latest'] : [];
    $latest_status = isset($latest['status']) ? (string) $latest['status'] : '';

    if ($latest_status === '') {
        $latest_status = sitepulse_custom_dashboard_resolve_speed_status($average, isset($speed['thresholds']) ? $speed['thresholds'] : []);
    }

    $status_meta = sitepulse_custom_dashboard_resolve_status_meta($latest_status);
    $status_meta['class'] = $latest_status;
    $card['status'] = $status_meta;

    if ($average !== null) {
        $card['value'] = [
            'text' => number_format_i18n($average, 2),
            'unit' => 'ms',
        ];
    }

    $samples = isset($speed['samples']) ? (int) $speed['samples'] : 0;

    $summary_parts = [];

    if (isset($latest['server_processing_ms']) && is_numeric($latest['server_processing_ms'])) {
        $summary_parts[] = sprintf(
            __('Latest: %s ms', 'sitepulse'),
            number_format_i18n((float) $latest['server_processing_ms'], 2)
        );
    }

    if ($samples > 0) {
        $summary_parts[] = sprintf(
            _n('%s sample', '%s samples', $samples, 'sitepulse'),
            number_format_i18n($samples)
        );
    }

    if (!empty($summary_parts)) {
        $card['summary'] = implode(' · ', $summary_parts);
    }

    $thresholds = isset($speed['thresholds']) && is_array($speed['thresholds']) ? $speed['thresholds'] : [];

    $card['details'] = [
        [
            'label' => __('Warning threshold', 'sitepulse'),
            'value' => isset($thresholds['warning'])
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n((int) $thresholds['warning']))
                : __('N/A', 'sitepulse'),
        ],
        [
            'label' => __('Critical threshold', 'sitepulse'),
            'value' => isset($thresholds['critical'])
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n((int) $thresholds['critical']))
                : __('N/A', 'sitepulse'),
        ],
    ];

    $card['trend'] = sitepulse_custom_dashboard_format_trend(
        isset($speed['trend']) ? $speed['trend'] : null,
        [
            'tolerance'         => 0.5,
            'precision'         => 1,
            'unit'              => __(' ms', 'sitepulse'),
            'increase_good'     => false,
            'positive_template' => __('Slower by %s%s', 'sitepulse'),
            'negative_template' => __('Faster by %s%s', 'sitepulse'),
            'positive_sr'       => __('Backend processing time increased by %s%s compared to the previous window.', 'sitepulse'),
            'negative_sr'       => __('Backend processing time improved by %s%s compared to the previous window.', 'sitepulse'),
            'stable_template'   => __('Speed is stable compared to the previous window.', 'sitepulse'),
            'stable_sr'         => __('Backend processing time is stable compared to the previous window.', 'sitepulse'),
        ]
    );

    if ($samples > 0) {
        $card['description'] = sprintf(
            __('Average across %1$s samples collected during %2$s.', 'sitepulse'),
            number_format_i18n($samples),
            $range_label
        );
    }

    return $card;
}

/**
 * Builds the contextual status banner based on the current metrics.
 *
 * @param array<string,array<string,mixed>> $cards    Formatted cards indexed by key.
 * @param array<string,mixed>               $payload  Raw payload data.
 * @param string                            $range_label Human-readable range label.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_status_banner($cards, $payload, $range_label) {
    $tone    = 'ok';
    $icon    = '✅';
    $message = sprintf(__('All systems operational for %s.', 'sitepulse'), $range_label);
    $sr      = $message;
    $cta     = [
        'label' => '',
        'url'   => '',
        'data'  => '',
    ];

    $uptime_card = isset($cards['uptime']) ? $cards['uptime'] : null;
    $logs_card   = isset($cards['logs']) ? $cards['logs'] : null;
    $speed_card  = isset($cards['speed']) ? $cards['speed'] : null;

    if (is_array($uptime_card) && empty($uptime_card['inactive'])) {
        $uptime_status = isset($uptime_card['status']['class']) ? $uptime_card['status']['class'] : 'status-ok';

        if ('status-bad' === $uptime_status) {
            $tone = 'danger';
            $icon = '🚨';
            $violations = isset($payload['uptime']['violations']) ? (int) $payload['uptime']['violations'] : 0;
            $down_checks = isset($payload['uptime']['totals']['down']) ? (int) $payload['uptime']['totals']['down'] : 0;
            $incident_count = $violations > 0 ? $violations : $down_checks;

            if ($incident_count > 0) {
                $message = sprintf(
                    _n('🚨 %1$d incident detected over %2$s.', '🚨 %1$d incidents detected over %2$s.', $incident_count, 'sitepulse'),
                    $incident_count,
                    $range_label
                );
                $sr = sprintf(
                    _n('%1$d incident detected during the selected window of %2$s.', '%1$d incidents detected during the selected window of %2$s.', $incident_count, 'sitepulse'),
                    $incident_count,
                    $range_label
                );
            } else {
                $message = sprintf(__('🚨 Availability is below target for %s.', 'sitepulse'), $range_label);
                $sr = sprintf(__('Availability is below target for %s.', 'sitepulse'), $range_label);
            }

            $cta = [
                'label' => __('Review uptime incidents', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-uptime'),
                'data'  => 'incident-playbook',
            ];
        } elseif ('status-warn' === $uptime_status) {
            $tone = 'warning';
            $icon = '⚠️';
            $message = sprintf(__('⚠️ Availability dipped during %s.', 'sitepulse'), $range_label);
            $sr = sprintf(__('Availability dipped during %s.', 'sitepulse'), $range_label);
            $cta = [
                'label' => __('Open uptime details', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-uptime'),
                'data'  => 'incident-playbook',
            ];
        }
    }

    if (is_array($logs_card) && empty($logs_card['inactive'])) {
        $logs_status = isset($logs_card['status']['class']) ? $logs_card['status']['class'] : 'status-ok';

        if ('status-bad' === $logs_status) {
            $tone = 'danger';
            $icon = '🚨';
            $message = __('🚨 Fatal errors detected in debug.log.', 'sitepulse');
            $sr = __('Fatal errors detected in the debug log. Immediate attention required.', 'sitepulse');
            $cta = [
                'label' => __('Inspect the error log', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-logs'),
                'data'  => 'incident-playbook',
            ];
        } elseif ('status-warn' === $logs_status && 'danger' !== $tone) {
            $tone = 'warning';
            $icon = '⚠️';
            $message = __('⚠️ Warnings present in the debug log.', 'sitepulse');
            $sr = __('Warnings present in the debug log.', 'sitepulse');
            $cta = [
                'label' => __('Review log warnings', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-logs'),
                'data'  => 'incident-playbook',
            ];
        }
    }

    if (is_array($speed_card) && empty($speed_card['inactive'])) {
        $speed_status = isset($speed_card['status']['class']) ? $speed_card['status']['class'] : 'status-ok';

        if ('status-bad' === $speed_status && 'danger' !== $tone) {
            $tone = 'danger';
            $icon = '🚨';
            $message = __('🚨 Backend processing time exceeds the critical threshold.', 'sitepulse');
            $sr = __('Backend processing time exceeds the critical threshold.', 'sitepulse');
            $cta = [
                'label' => __('Investigate speed scans', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-speed'),
                'data'  => 'performance-playbook',
            ];
        } elseif ('status-warn' === $speed_status && 'danger' !== $tone && 'warning' !== $tone) {
            $tone = 'warning';
            $icon = '⚠️';
            $message = __('⚠️ Backend speed is approaching the warning threshold.', 'sitepulse');
            $sr = __('Backend speed is approaching the warning threshold.', 'sitepulse');
            $cta = [
                'label' => __('Open speed analyzer', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-speed'),
                'data'  => 'performance-playbook',
            ];
        }
    }

    return [
        'tone'    => $tone,
        'icon'    => $icon,
        'message' => $message,
        'sr'      => $sr,
        'cta'     => $cta,
    ];
}

/**
 * Builds the formatted representation of the metrics payload for UI rendering.
 *
 * @param array<string,mixed> $payload Raw payload data.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_metrics_view($payload) {
    $range = isset($payload['range']) ? (string) $payload['range'] : sitepulse_custom_dashboard_get_default_range();
    $available_ranges = isset($payload['available_ranges']) && is_array($payload['available_ranges'])
        ? $payload['available_ranges']
        : array_values(sitepulse_custom_dashboard_get_metric_ranges());
    $modules = isset($payload['modules']) && is_array($payload['modules']) ? $payload['modules'] : [];

    $range_label = sitepulse_custom_dashboard_resolve_range_label($range, $available_ranges);
    $generated_at = isset($payload['generated_at']) ? (int) $payload['generated_at'] : sitepulse_custom_dashboard_get_current_timestamp();

    if ($generated_at <= 0) {
        $generated_at = sitepulse_custom_dashboard_get_current_timestamp();
    }

    $date_format = get_option('date_format');
    $time_format = get_option('time_format');
    $generated_label = function_exists('wp_date')
        ? wp_date($date_format . ' ' . $time_format, $generated_at)
        : date_i18n($date_format . ' ' . $time_format, $generated_at);

    $cards = [
        'impact' => sitepulse_custom_dashboard_format_impact_card_view(
            isset($payload['impact']) ? $payload['impact'] : null,
            $range_label
        ),
        'uptime' => sitepulse_custom_dashboard_format_uptime_card_view(
            isset($payload['uptime']) ? $payload['uptime'] : null,
            !empty($modules['uptime_tracker']),
            $range_label
        ),
        'logs'   => sitepulse_custom_dashboard_format_log_card_view(
            isset($payload['logs']) ? $payload['logs'] : null,
            !empty($modules['log_analyzer'])
        ),
        'speed'  => sitepulse_custom_dashboard_format_speed_card_view(
            isset($payload['speed']) ? $payload['speed'] : null,
            !empty($modules['speed_analyzer']),
            $range_label
        ),
    ];

    $cards = array_filter($cards, 'is_array');

    $banner = sitepulse_custom_dashboard_format_status_banner($cards, $payload, $range_label);

    return [
        'range'           => $range,
        'range_label'     => $range_label,
        'generated_at'    => $generated_at,
        'generated_label' => $generated_label,
        'generated_text'  => $generated_label !== ''
            ? sprintf(__('Updated %s.', 'sitepulse'), $generated_label)
            : __('Updated just now.', 'sitepulse'),
        'cards'           => $cards,
        'banner'          => $banner,
        'modules'         => $modules,
    ];
}

function sitepulse_render_dashboard_metric_card($card_key, $card_view) {
    if (!is_array($card_view)) {
        return '';
    }

    $classes = ['sitepulse-kpi-card'];
    $status_class = isset($card_view['status']['class']) ? sanitize_html_class((string) $card_view['status']['class']) : '';

    if ($status_class !== '') {
        $classes[] = 'sitepulse-kpi-card--' . $status_class;
    }

    if (!empty($card_view['inactive'])) {
        $classes[] = 'sitepulse-kpi-card--inactive';
    }

    $status_meta = isset($card_view['status']) && is_array($card_view['status'])
        ? $card_view['status']
        : sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $status_label = isset($status_meta['label']) ? $status_meta['label'] : __('Status unknown', 'sitepulse');
    $status_icon  = isset($status_meta['icon']) ? $status_meta['icon'] : '⚠️';
    $status_sr    = isset($status_meta['sr']) ? $status_meta['sr'] : __('Status: unknown', 'sitepulse');

    $value_text = isset($card_view['value']['text']) ? (string) $card_view['value']['text'] : __('N/A', 'sitepulse');
    $value_unit = isset($card_view['value']['unit']) ? (string) $card_view['value']['unit'] : '';
    $summary    = isset($card_view['summary']) ? (string) $card_view['summary'] : '';

    $trend   = isset($card_view['trend']) && is_array($card_view['trend']) ? $card_view['trend'] : [];
    $trend_text = isset($trend['text']) ? (string) $trend['text'] : '';
    $trend_direction = isset($trend['direction']) ? sanitize_html_class((string) $trend['direction']) : 'flat';
    $trend_sr = isset($trend['sr']) ? (string) $trend['sr'] : '';

    $description = isset($card_view['description']) ? (string) $card_view['description'] : '';
    $inactive_message = isset($card_view['inactive_message'])
        ? (string) $card_view['inactive_message']
        : __('Enable the related module to view this metric.', 'sitepulse');

    ob_start();
    ?>
    <article class="<?php echo esc_attr(implode(' ', $classes)); ?>" data-sitepulse-metric-card="<?php echo esc_attr($card_key); ?>" data-status="<?php echo esc_attr($status_class); ?>"<?php echo !empty($card_view['inactive']) ? ' data-inactive=\"true\"' : ''; ?>>
        <header class="sitepulse-kpi-card__header">
            <h2 class="sitepulse-kpi-card__title" data-sitepulse-metric-label><?php echo esc_html(isset($card_view['label']) ? $card_view['label'] : ucfirst($card_key)); ?></h2>
            <span class="status-badge <?php echo esc_attr($status_class); ?>" data-sitepulse-metric-status-badge>
                <span class="status-icon" data-sitepulse-metric-status-icon><?php echo esc_html($status_icon); ?></span>
                <span class="status-text" data-sitepulse-metric-status-label><?php echo esc_html($status_label); ?></span>
            </span>
            <span class="screen-reader-text" data-sitepulse-metric-status-sr><?php echo esc_html($status_sr); ?></span>
        </header>
        <p class="sitepulse-kpi-card__value">
            <span class="sitepulse-kpi-card__value-number" data-sitepulse-metric-value><?php echo esc_html($value_text); ?></span>
            <span class="sitepulse-kpi-card__value-unit" data-sitepulse-metric-unit<?php echo $value_unit === '' ? ' hidden' : ''; ?>><?php echo esc_html($value_unit); ?></span>
        </p>
        <p class="sitepulse-kpi-card__summary" data-sitepulse-metric-summary<?php echo $summary === '' ? ' hidden' : ''; ?>><?php echo esc_html($summary); ?></p>
        <p class="sitepulse-kpi-card__trend" data-sitepulse-metric-trend data-trend="<?php echo esc_attr($trend_direction); ?>"<?php echo $trend_text === '' ? ' hidden' : ''; ?>>
            <span aria-hidden="true" data-sitepulse-metric-trend-text><?php echo esc_html($trend_text); ?></span>
            <span class="screen-reader-text" data-sitepulse-metric-trend-sr><?php echo esc_html($trend_sr); ?></span>
        </p>
        <?php
        $details = isset($card_view['details']) && is_array($card_view['details']) ? $card_view['details'] : [];
        ?>
        <dl class="sitepulse-kpi-card__details" data-sitepulse-metric-details<?php echo empty($details) ? ' hidden' : ''; ?>>
            <?php foreach ($details as $detail) :
                $detail_label = isset($detail['label']) ? (string) $detail['label'] : '';
                $detail_value = isset($detail['value']) ? (string) $detail['value'] : '';
                if ($detail_label === '' && $detail_value === '') {
                    continue;
                }
            ?>
                <div class="sitepulse-kpi-card__detail">
                    <dt><?php echo esc_html($detail_label); ?></dt>
                    <dd><?php echo esc_html($detail_value); ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <p class="sitepulse-kpi-card__description" data-sitepulse-metric-description<?php echo $description === '' ? ' hidden' : ''; ?>><?php echo esc_html($description); ?></p>
        <p class="sitepulse-kpi-card__inactive" data-sitepulse-metric-inactive<?php echo empty($card_view['inactive']) ? ' hidden' : ''; ?>><?php echo esc_html($inactive_message); ?></p>
    </article>
    <?php

    return (string) ob_get_clean();
}

/**
 * Registers the REST API routes powering the dashboard metrics feed.
 *
 * @return void
 */
function sitepulse_custom_dashboard_register_rest_routes() {
    if (!function_exists('register_rest_route')) {
        return;
    }

    register_rest_route(
        'sitepulse/v1',
        '/metrics',
        [
            'methods'             => defined('WP_REST_Server::READABLE') ? WP_REST_Server::READABLE : 'GET',
            'callback'            => 'sitepulse_custom_dashboard_rest_metrics',
            'permission_callback' => 'sitepulse_custom_dashboard_rest_permission_check',
            'args'                => [
                'range' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]
    );
}

/**
 * Determines whether the current request can access the metrics endpoint.
 *
 * @return bool
 */
function sitepulse_custom_dashboard_rest_permission_check() {
    $capability = function_exists('sitepulse_get_capability')
        ? sitepulse_get_capability()
        : 'manage_options';

    return current_user_can($capability);
}

/**
 * Builds the payload returned by the metrics endpoint.
 *
 * @param string $range Range identifier to compute.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_prepare_metrics_payload($range) {
    $ranges = sitepulse_custom_dashboard_get_metric_ranges();

    if (!isset($ranges[$range])) {
        $range = sitepulse_custom_dashboard_get_default_range();
    }

    $config           = $ranges[$range];
    $available_ranges = array_values($ranges);

    $current_timestamp = sitepulse_custom_dashboard_get_current_timestamp();

    $uptime = sitepulse_custom_dashboard_calculate_uptime_metrics($range, $config);
    $logs   = sitepulse_custom_dashboard_analyze_debug_log();
    $speed  = sitepulse_custom_dashboard_calculate_speed_metrics($range, $config);

    if (is_array($logs)) {
        $logs['enabled'] = function_exists('sitepulse_is_module_active')
            ? sitepulse_is_module_active('log_analyzer')
            : true;
    }

    $modules_status = [
        'uptime_tracker' => function_exists('sitepulse_is_module_active')
            ? sitepulse_is_module_active('uptime_tracker')
            : true,
        'log_analyzer'   => function_exists('sitepulse_is_module_active')
            ? sitepulse_is_module_active('log_analyzer')
            : true,
        'speed_analyzer' => function_exists('sitepulse_is_module_active')
            ? sitepulse_is_module_active('speed_analyzer')
            : true,
        'ai_insights'    => function_exists('sitepulse_is_module_active')
            ? sitepulse_is_module_active('ai_insights')
            : function_exists('sitepulse_ai_get_history_entries'),
    ];

    $ai_summary = sitepulse_custom_dashboard_collect_ai_window_stats(
        isset($config['seconds']) ? (int) $config['seconds'] : 0,
        $current_timestamp
    );

    $impact = sitepulse_custom_dashboard_calculate_transverse_impact_index(
        $range,
        $config,
        $modules_status,
        $uptime,
        $speed,
        $ai_summary
    );

    $payload = [
        'range'            => $range,
        'available_ranges' => $available_ranges,
        'generated_at'     => $current_timestamp,
        'uptime'           => $uptime,
        'logs'             => $logs,
        'speed'            => $speed,
        'modules'          => $modules_status,
    ];

    if (is_array($impact)) {
        $payload['impact'] = $impact;
    }

    $payload['view'] = sitepulse_custom_dashboard_format_metrics_view($payload);

    return $payload;
}

/**
 * REST API callback returning dashboard metrics.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return WP_REST_Response
 */
function sitepulse_custom_dashboard_rest_metrics($request) {
    $provided = $request instanceof WP_REST_Request ? $request->get_param('range') : null;
    $sanitized = sitepulse_custom_dashboard_sanitize_range($provided);

    if ($sanitized !== '') {
        update_option(sitepulse_custom_dashboard_get_range_option_name(), $sanitized, false);
        $range = $sanitized;
    } else {
        $range = sitepulse_custom_dashboard_get_stored_range();
    }

    $payload = sitepulse_custom_dashboard_prepare_metrics_payload($range);

    return rest_ensure_response($payload);
}

/**
 * Calculates uptime metrics for the selected window.
 *
 * @param string               $range  Range identifier.
 * @param array<string,mixed>  $config Range configuration.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_calculate_uptime_metrics($range, $config) {
    $days = isset($config['days']) ? (int) $config['days'] : 0;

    if ($days < 1) {
        $days = 1;
    }

    $option_key = defined('SITEPULSE_OPTION_UPTIME_ARCHIVE')
        ? SITEPULSE_OPTION_UPTIME_ARCHIVE
        : 'sitepulse_uptime_archive';

    $archive = get_option($option_key, []);

    if (!is_array($archive)) {
        $archive = [];
    }

    $current_metrics = function_exists('sitepulse_calculate_uptime_window_metrics')
        ? sitepulse_calculate_uptime_window_metrics($archive, $days)
        : sitepulse_custom_dashboard_calculate_uptime_window_metrics($archive, $days);

    $previous_metrics = [];

    if ($days > 0 && count($archive) > $days) {
        $previous_archive = array_slice($archive, 0, count($archive) - $days, true);
        $previous_metrics = function_exists('sitepulse_calculate_uptime_window_metrics')
            ? sitepulse_calculate_uptime_window_metrics($previous_archive, $days)
            : sitepulse_custom_dashboard_calculate_uptime_window_metrics($previous_archive, $days);
    }

    if (!is_array($previous_metrics)) {
        $previous_metrics = [];
    }

    $uptime_value   = isset($current_metrics['uptime']) ? (float) $current_metrics['uptime'] : null;
    $latency_avg    = isset($current_metrics['latency_avg']) ? $current_metrics['latency_avg'] : null;
    $ttfb_avg       = isset($current_metrics['ttfb_avg']) ? $current_metrics['ttfb_avg'] : null;
    $violation_count = isset($current_metrics['violations']) ? (int) $current_metrics['violations'] : 0;

    return [
        'range'  => $range,
        'days'   => isset($current_metrics['days']) ? (int) $current_metrics['days'] : 0,
        'totals' => [
            'total'   => isset($current_metrics['total_checks']) ? (int) $current_metrics['total_checks'] : 0,
            'up'      => isset($current_metrics['up_checks']) ? (int) $current_metrics['up_checks'] : 0,
            'down'    => isset($current_metrics['down_checks']) ? (int) $current_metrics['down_checks'] : 0,
            'unknown' => isset($current_metrics['unknown_checks']) ? (int) $current_metrics['unknown_checks'] : 0,
        ],
        'uptime'       => $uptime_value !== null ? round($uptime_value, 4) : null,
        'latency_avg'  => ($latency_avg !== null && is_numeric($latency_avg)) ? round((float) $latency_avg, 2) : null,
        'ttfb_avg'     => ($ttfb_avg !== null && is_numeric($ttfb_avg)) ? round((float) $ttfb_avg, 2) : null,
        'violations'   => $violation_count,
        'trend'        => [
            'uptime'      => sitepulse_custom_dashboard_calculate_trend($uptime_value, $previous_metrics['uptime'] ?? null, 4),
            'latency_avg' => sitepulse_custom_dashboard_calculate_trend($latency_avg, $previous_metrics['latency_avg'] ?? null, 2),
            'ttfb_avg'    => sitepulse_custom_dashboard_calculate_trend($ttfb_avg, $previous_metrics['ttfb_avg'] ?? null, 2),
            'violations'  => sitepulse_custom_dashboard_calculate_trend($violation_count, $previous_metrics['violations'] ?? null, 0),
        ],
    ];
}

/**
 * Fallback calculation for uptime metrics when the Uptime module is unavailable.
 *
 * @param array<int|string,mixed> $archive Archive entries.
 * @param int                     $days    Window size in days.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_calculate_uptime_window_metrics($archive, $days) {
    if (!is_array($archive) || empty($archive) || $days < 1) {
        return [
            'days'           => 0,
            'total_checks'   => 0,
            'up_checks'      => 0,
            'down_checks'    => 0,
            'unknown_checks' => 0,
            'uptime'         => 100.0,
            'latency_sum'    => 0.0,
            'latency_count'  => 0,
            'latency_avg'    => null,
            'ttfb_sum'       => 0.0,
            'ttfb_count'     => 0,
            'ttfb_avg'       => null,
            'violations'     => 0,
        ];
    }

    $window = array_slice($archive, -$days, null, true);

    $totals = [
        'days'           => count($window),
        'total_checks'   => 0,
        'up_checks'      => 0,
        'down_checks'    => 0,
        'unknown_checks' => 0,
        'uptime'         => 100.0,
        'latency_sum'    => 0.0,
        'latency_count'  => 0,
        'latency_avg'    => null,
        'ttfb_sum'       => 0.0,
        'ttfb_count'     => 0,
        'ttfb_avg'       => null,
        'violations'     => 0,
    ];

    foreach ($window as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $day_total   = isset($entry['total']) ? (int) $entry['total'] : 0;
        $maintenance = isset($entry['maintenance']) ? (int) $entry['maintenance'] : 0;
        $effective   = max(0, $day_total - $maintenance);

        $totals['total_checks']   += $effective;
        $totals['up_checks']      += isset($entry['up']) ? (int) $entry['up'] : 0;
        $totals['down_checks']    += isset($entry['down']) ? (int) $entry['down'] : 0;
        $totals['unknown_checks'] += isset($entry['unknown']) ? (int) $entry['unknown'] : 0;
        $totals['latency_sum']    += isset($entry['latency_sum']) ? (float) $entry['latency_sum'] : 0.0;
        $totals['latency_count']  += isset($entry['latency_count']) ? (int) $entry['latency_count'] : 0;
        $totals['ttfb_sum']       += isset($entry['ttfb_sum']) ? (float) $entry['ttfb_sum'] : 0.0;
        $totals['ttfb_count']     += isset($entry['ttfb_count']) ? (int) $entry['ttfb_count'] : 0;
        $totals['violations']     += isset($entry['violations']) ? (int) $entry['violations'] : 0;
    }

    if ($totals['total_checks'] > 0) {
        $totals['uptime'] = ($totals['up_checks'] / $totals['total_checks']) * 100;
    }

    if ($totals['latency_count'] > 0) {
        $totals['latency_avg'] = $totals['latency_sum'] / $totals['latency_count'];
    }

    if ($totals['ttfb_count'] > 0) {
        $totals['ttfb_avg'] = $totals['ttfb_sum'] / $totals['ttfb_count'];
    }

    return $totals;
}

/**
 * Computes a numeric trend between the current and previous values.
 *
 * @param mixed $current   Current measurement.
 * @param mixed $previous  Previous measurement.
 * @param int   $precision Number of decimals to keep. Zero forces an integer delta.
 * @return float|int|null
 */
function sitepulse_custom_dashboard_calculate_trend($current, $previous, $precision = 2) {
    if (!is_numeric($current) || !is_numeric($previous)) {
        return null;
    }

    $delta = (float) $current - (float) $previous;

    if ($precision <= 0) {
        return (int) round($delta);
    }

    return round($delta, $precision);
}

/**
 * Summarises AI insight entries within a time window.
 *
 * @param array<int,array<string,mixed>> $entries        History entries.
 * @param int                            $window_seconds Window size in seconds.
 * @param int                            $now            Reference timestamp.
 * @return array<string,int>
 */
function sitepulse_custom_dashboard_summarize_ai_entries($entries, $window_seconds, $now) {
    $summary = [
        'recent_total'        => 0,
        'recent_pending'      => 0,
        'recent_acknowledged' => 0,
        'stale_pending'       => 0,
        'latest_timestamp'    => 0,
    ];

    if (!is_array($entries) || empty($entries)) {
        return $summary;
    }

    $window_seconds = max(0, (int) $window_seconds);
    $now            = (int) $now;
    $window_start   = $window_seconds > 0 ? $now - $window_seconds : 0;

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $note      = isset($entry['note']) ? trim((string) $entry['note']) : '';
        $has_note  = $note !== '';

        if ($timestamp > $summary['latest_timestamp']) {
            $summary['latest_timestamp'] = $timestamp;
        }

        if ($window_seconds > 0 && $timestamp >= $window_start) {
            $summary['recent_total']++;

            if ($has_note) {
                $summary['recent_acknowledged']++;
            } else {
                $summary['recent_pending']++;
            }

            continue;
        }

        if (!$has_note && $timestamp > 0) {
            $summary['stale_pending']++;
        }
    }

    return $summary;
}

/**
 * Collects AI insight statistics for the provided window.
 *
 * @param int $window_seconds Window length in seconds.
 * @param int $now            Reference timestamp.
 * @return array<string,int>
 */
function sitepulse_custom_dashboard_collect_ai_window_stats($window_seconds, $now) {
    $entries = [];

    if (function_exists('sitepulse_ai_get_history_entries')) {
        $entries = sitepulse_ai_get_history_entries();

        if (!is_array($entries)) {
            $entries = [];
        }
    }

    return sitepulse_custom_dashboard_summarize_ai_entries($entries, $window_seconds, $now);
}

/**
 * Normalizes an impact snapshot for persistence.
 *
 * @param array<string,mixed> $impact Raw impact snapshot.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_normalize_impact_index($impact) {
    $now = sitepulse_custom_dashboard_get_current_timestamp();

    $normalized = [
        'range'           => isset($impact['range']) ? sanitize_key((string) $impact['range']) : '',
        'updated_at'      => isset($impact['updated_at']) ? (int) $impact['updated_at'] : $now,
        'overall'         => null,
        'dominant_module' => isset($impact['dominant_module']) ? sanitize_key((string) $impact['dominant_module']) : '',
        'modules'         => [],
    ];

    if (isset($impact['overall']) && is_numeric($impact['overall'])) {
        $normalized['overall'] = round((float) $impact['overall'], 2);
    }

    if (isset($impact['modules']) && is_array($impact['modules'])) {
        foreach ($impact['modules'] as $module_key => $module_data) {
            $module_id = sanitize_key((string) $module_key);

            if ($module_id === '') {
                continue;
            }

            $module_normalized = [
                'label'  => isset($module_data['label']) ? sanitize_text_field((string) $module_data['label']) : $module_id,
                'status' => isset($module_data['status']) ? sanitize_key((string) $module_data['status']) : 'status-warn',
                'active' => !empty($module_data['active']),
                'score'  => null,
            ];

            if (isset($module_data['score']) && is_numeric($module_data['score'])) {
                $module_normalized['score'] = round((float) $module_data['score'], 2);
            }

            if (isset($module_data['signal'])) {
                $module_normalized['signal'] = sanitize_text_field((string) $module_data['signal']);
            }

            if (isset($module_data['details']) && is_array($module_data['details'])) {
                $module_normalized['details'] = [];

                foreach ($module_data['details'] as $detail) {
                    if (!is_array($detail)) {
                        continue;
                    }

                    $detail_label = isset($detail['label']) ? sanitize_text_field((string) $detail['label']) : '';
                    $detail_value = isset($detail['value']) ? sanitize_text_field((string) $detail['value']) : '';

                    if ($detail_label === '' && $detail_value === '') {
                        continue;
                    }

                    $module_normalized['details'][] = [
                        'label' => $detail_label,
                        'value' => $detail_value,
                    ];
                }
            }

            $normalized['modules'][$module_id] = $module_normalized;
        }
    }

    return $normalized;
}

/**
 * Stores the latest impact snapshot.
 *
 * @param array<string,mixed> $impact Impact payload.
 * @return void
 */
function sitepulse_custom_dashboard_store_impact_index($impact) {
    if (!defined('SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX') || !function_exists('update_option')) {
        return;
    }

    $normalized = sitepulse_custom_dashboard_normalize_impact_index($impact);

    $payload = [
        'range'      => $normalized['range'],
        'updated_at' => $normalized['updated_at'],
        'impact'     => $normalized,
    ];

    update_option(SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX, $payload, false);
}

/**
 * Retrieves a cached impact snapshot when available.
 *
 * @param string $range   Requested range identifier.
 * @param int    $max_age Maximum age in seconds before the cache is considered stale.
 * @return array<string,mixed>|null
 */
function sitepulse_custom_dashboard_get_cached_impact_index($range, $max_age = 900) {
    if (!defined('SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX') || !function_exists('get_option')) {
        return null;
    }

    $stored = get_option(SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX, []);

    if (!is_array($stored) || empty($stored['impact']) || !is_array($stored['impact'])) {
        return null;
    }

    $impact = $stored['impact'];
    $requested_range = sanitize_key((string) $range);
    $impact_range    = isset($impact['range']) ? sanitize_key((string) $impact['range']) : '';

    if ($requested_range !== '' && $impact_range !== '' && $impact_range !== $requested_range) {
        return null;
    }

    $updated_at = isset($impact['updated_at']) ? (int) $impact['updated_at'] : (isset($stored['updated_at']) ? (int) $stored['updated_at'] : 0);

    if ($max_age > 0 && $updated_at > 0) {
        $now = sitepulse_custom_dashboard_get_current_timestamp();

        if (($now - $updated_at) > $max_age) {
            return null;
        }
    }

    if (!isset($impact['modules']) || !is_array($impact['modules'])) {
        return null;
    }

    return $impact;
}

/**
 * Calculates the transverse impact index using module metrics.
 *
 * @param string               $range          Range identifier.
 * @param array<string,mixed>  $config         Range configuration.
 * @param array<string,bool>   $modules_status Module activation map.
 * @param array<string,mixed>  $uptime         Uptime metrics.
 * @param array<string,mixed>  $speed          Speed metrics.
 * @param array<string,int>|null $ai_summary   AI insight summary.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_calculate_transverse_impact_index($range, $config, $modules_status, $uptime, $speed, $ai_summary = null) {
    $range_id = sanitize_key((string) $range);

    if ($range_id === '') {
        $range_id = sitepulse_custom_dashboard_get_default_range();
    }

    $now            = sitepulse_custom_dashboard_get_current_timestamp();
    $window_seconds = isset($config['seconds']) ? (int) $config['seconds'] : 0;

    if ($window_seconds < 0) {
        $window_seconds = 0;
    }

    if (!is_array($modules_status)) {
        $modules_status = [];
    }

    if (null === $ai_summary) {
        $ai_summary = sitepulse_custom_dashboard_collect_ai_window_stats($window_seconds, $now);
    } elseif (!is_array($ai_summary)) {
        $ai_summary = [];
    }

    $module_labels = [
        'uptime_tracker' => __('Availability', 'sitepulse'),
        'speed_analyzer' => __('Performance', 'sitepulse'),
        'ai_insights'    => __('AI backlog', 'sitepulse'),
    ];

    $weights = [
        'uptime_tracker' => 0.4,
        'speed_analyzer' => 0.35,
        'ai_insights'    => 0.25,
    ];

    $weights = apply_filters('sitepulse_transverse_impact_weights', $weights, $range_id, $modules_status, $uptime, $speed, $ai_summary);

    if (!is_array($weights)) {
        $weights = [
            'uptime_tracker' => 0.4,
            'speed_analyzer' => 0.35,
            'ai_insights'    => 0.25,
        ];
    }

    $modules_output  = [];
    $active_weights  = [];
    $dominant_module = '';
    $dominant_score  = -1.0;

    // Uptime module scoring.
    $uptime_entry = [
        'label'  => $module_labels['uptime_tracker'],
        'status' => 'status-warn',
        'score'  => null,
        'active' => !empty($modules_status['uptime_tracker']),
        'details'=> [],
        'signal' => '',
    ];

    $uptime_value = null;

    if (is_array($uptime) && isset($uptime['uptime']) && is_numeric($uptime['uptime'])) {
        $uptime_value = (float) $uptime['uptime'];
    }

    $violations = isset($uptime['violations']) ? (int) $uptime['violations'] : 0;

    if ($uptime_entry['active'] && $uptime_value !== null) {
        $uptime_warning = apply_filters('sitepulse_transverse_impact_uptime_warning', sitepulse_custom_dashboard_get_uptime_warning_threshold(), $range_id, $uptime);

        if (!is_numeric($uptime_warning)) {
            $uptime_warning = sitepulse_custom_dashboard_get_uptime_warning_threshold();
        }

        $uptime_warning  = (float) $uptime_warning;
        $uptime_critical = apply_filters('sitepulse_transverse_impact_uptime_critical', max(0.0, $uptime_warning - 1.0), $range_id, $uptime);

        if (!is_numeric($uptime_critical)) {
            $uptime_critical = max(0.0, $uptime_warning - 1.0);
        }

        $uptime_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($uptime_value, $uptime_warning, $uptime_critical, 'higher-is-better');

        $violations_warning = apply_filters('sitepulse_transverse_impact_uptime_violations_warning', 1, $range_id, $uptime);
        $violations_warning = is_numeric($violations_warning) ? max(0.0, (float) $violations_warning) : 1.0;

        $violations_critical = apply_filters('sitepulse_transverse_impact_uptime_violations_critical', 3, $range_id, $uptime);
        $violations_critical = is_numeric($violations_critical) ? max($violations_warning + 1.0, (float) $violations_critical) : ($violations_warning + 2.0);

        $violations_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($violations, $violations_warning, $violations_critical, 'higher-is-worse');

        $uptime_score = (($uptime_ratio * 0.7) + ($violations_ratio * 0.3)) * 100.0;
        $uptime_entry['score'] = round($uptime_score, 2);
        $uptime_entry['status'] = sitepulse_custom_dashboard_resolve_score_status($uptime_entry['score']);

        $signal_parts = [];
        $signal_parts[] = sprintf(__('Uptime %s%%', 'sitepulse'), number_format_i18n($uptime_value, 2));

        if ($violations > 0) {
            $signal_parts[] = sprintf(
                _n('%s incident', '%s incidents', $violations, 'sitepulse'),
                number_format_i18n($violations)
            );
        } else {
            $signal_parts[] = __('No incidents', 'sitepulse');
        }

        $uptime_entry['signal'] = implode(' • ', $signal_parts);

        $uptime_entry['details'][] = [
            'label' => __('Uptime', 'sitepulse'),
            'value' => sprintf('%s%%', number_format_i18n($uptime_value, 2)),
        ];

        $uptime_entry['details'][] = [
            'label' => __('Incidents', 'sitepulse'),
            'value' => number_format_i18n($violations),
        ];

        if (isset($uptime['totals']) && is_array($uptime['totals']) && isset($uptime['totals']['total'])) {
            $uptime_entry['details'][] = [
                'label' => __('Checks', 'sitepulse'),
                'value' => number_format_i18n((int) $uptime['totals']['total']),
            ];
        }

        $weight_value = isset($weights['uptime_tracker']) ? max(0.0, (float) $weights['uptime_tracker']) : 0.0;

        if ($weight_value > 0) {
            $active_weights['uptime_tracker'] = $weight_value;
        }

        if ($uptime_entry['score'] > $dominant_score) {
            $dominant_score  = $uptime_entry['score'];
            $dominant_module = 'uptime_tracker';
        }
    } elseif (!$uptime_entry['active']) {
        $uptime_entry['signal'] = __('Module inactive', 'sitepulse');
    } else {
        $uptime_entry['signal'] = __('Awaiting uptime data', 'sitepulse');
    }

    $modules_output['uptime_tracker'] = $uptime_entry;

    // Speed module scoring.
    $speed_entry = [
        'label'  => $module_labels['speed_analyzer'],
        'status' => 'status-warn',
        'score'  => null,
        'active' => !empty($modules_status['speed_analyzer']),
        'details'=> [],
        'signal' => '',
    ];

    $average = null;

    if (is_array($speed) && isset($speed['average']) && is_numeric($speed['average'])) {
        $average = (float) $speed['average'];
    }

    $thresholds = isset($speed['thresholds']) && is_array($speed['thresholds'])
        ? $speed['thresholds']
        : sitepulse_custom_dashboard_get_speed_thresholds_for_dashboard();

    $warning_ms  = isset($thresholds['warning']) ? (float) $thresholds['warning'] : 200.0;
    $critical_ms = isset($thresholds['critical']) ? (float) $thresholds['critical'] : max($warning_ms + 1.0, 500.0);

    if ($speed_entry['active'] && $average !== null) {
        $speed_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($average, $warning_ms, $critical_ms, 'higher-is-worse');

        $trend_value = isset($speed['trend']) && is_numeric($speed['trend']) ? (float) $speed['trend'] : 0.0;

        $trend_warning = apply_filters('sitepulse_transverse_impact_speed_trend_warning', 10.0, $range_id, $speed);
        $trend_warning = is_numeric($trend_warning) ? max(0.0, (float) $trend_warning) : 10.0;

        $trend_critical = apply_filters('sitepulse_transverse_impact_speed_trend_critical', 30.0, $range_id, $speed);
        $trend_critical = is_numeric($trend_critical) ? max($trend_warning + 1.0, (float) $trend_critical) : ($trend_warning + 20.0);

        $trend_ratio = 0.0;

        if ($trend_value > 0) {
            $trend_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($trend_value, $trend_warning, $trend_critical, 'higher-is-worse');
        }

        $speed_score = (($speed_ratio * 0.8) + ($trend_ratio * 0.2)) * 100.0;
        $speed_entry['score'] = round($speed_score, 2);
        $speed_entry['status'] = sitepulse_custom_dashboard_resolve_score_status($speed_entry['score']);

        $signal_parts = [];
        $signal_parts[] = sprintf(__('Average %s ms', 'sitepulse'), number_format_i18n($average, 1));

        if ($trend_value > 0) {
            $signal_parts[] = sprintf(__('Slower +%s ms', 'sitepulse'), number_format_i18n($trend_value, 1));
        } elseif ($trend_value < 0) {
            $signal_parts[] = sprintf(__('Faster %s ms', 'sitepulse'), number_format_i18n($trend_value, 1));
        }

        $speed_entry['signal'] = implode(' • ', $signal_parts);

        $speed_entry['details'][] = [
            'label' => __('Average', 'sitepulse'),
            'value' => sprintf('%s ms', number_format_i18n($average, 1)),
        ];

        if (isset($speed['samples'])) {
            $speed_entry['details'][] = [
                'label' => __('Samples', 'sitepulse'),
                'value' => number_format_i18n((int) $speed['samples']),
            ];
        }

        $weight_value = isset($weights['speed_analyzer']) ? max(0.0, (float) $weights['speed_analyzer']) : 0.0;

        if ($weight_value > 0) {
            $active_weights['speed_analyzer'] = $weight_value;
        }

        if ($speed_entry['score'] > $dominant_score) {
            $dominant_score  = $speed_entry['score'];
            $dominant_module = 'speed_analyzer';
        }
    } elseif (!$speed_entry['active']) {
        $speed_entry['signal'] = __('Module inactive', 'sitepulse');
    } else {
        $speed_entry['signal'] = __('Awaiting speed data', 'sitepulse');
    }

    $modules_output['speed_analyzer'] = $speed_entry;

    // AI insights scoring.
    $ai_entry = [
        'label'  => $module_labels['ai_insights'],
        'status' => 'status-warn',
        'score'  => null,
        'active' => !empty($modules_status['ai_insights']),
        'details'=> [],
        'signal' => '',
    ];

    $recent_total   = isset($ai_summary['recent_total']) ? (int) $ai_summary['recent_total'] : 0;
    $recent_pending = isset($ai_summary['recent_pending']) ? (int) $ai_summary['recent_pending'] : 0;
    $recent_ack     = isset($ai_summary['recent_acknowledged']) ? (int) $ai_summary['recent_acknowledged'] : 0;
    $stale_pending  = isset($ai_summary['stale_pending']) ? (int) $ai_summary['stale_pending'] : 0;

    if ($ai_entry['active']) {
        $pending_warning = apply_filters('sitepulse_transverse_impact_ai_pending_warning', 1, $range_id, $ai_summary);
        $pending_warning = is_numeric($pending_warning) ? max(0.0, (float) $pending_warning) : 1.0;

        $pending_critical = apply_filters('sitepulse_transverse_impact_ai_pending_critical', 3, $range_id, $ai_summary);
        $pending_critical = is_numeric($pending_critical) ? max($pending_warning + 1.0, (float) $pending_critical) : ($pending_warning + 2.0);

        $pending_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($recent_pending, $pending_warning, $pending_critical, 'higher-is-worse');

        $backlog_critical = apply_filters('sitepulse_transverse_impact_ai_backlog_critical', 5, $range_id, $ai_summary);
        $backlog_critical = is_numeric($backlog_critical) ? max(1.0, (float) $backlog_critical) : 5.0;

        $backlog_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($stale_pending, 0.0, $backlog_critical, 'higher-is-worse');

        $ai_score = (($pending_ratio * 0.75) + ($backlog_ratio * 0.25)) * 100.0;
        $ai_entry['score'] = round($ai_score, 2);
        $ai_entry['status'] = sitepulse_custom_dashboard_resolve_score_status($ai_entry['score']);

        $signal_parts = [];

        if ($recent_pending > 0) {
            $signal_parts[] = sprintf(
                _n('%s pending insight', '%s pending insights', $recent_pending, 'sitepulse'),
                number_format_i18n($recent_pending)
            );
        } elseif ($recent_total > 0) {
            $signal_parts[] = __('Backlog cleared', 'sitepulse');
        }

        if ($recent_total > 0) {
            $signal_parts[] = sprintf(
                _n('%s insight generated', '%s insights generated', $recent_total, 'sitepulse'),
                number_format_i18n($recent_total)
            );
        }

        if ($stale_pending > 0) {
            $signal_parts[] = sprintf(
                _n('%s legacy pending', '%s legacy pending', $stale_pending, 'sitepulse'),
                number_format_i18n($stale_pending)
            );
        }

        $ai_entry['signal'] = implode(' • ', array_filter($signal_parts));

        $ai_entry['details'][] = [
            'label' => __('New insights', 'sitepulse'),
            'value' => number_format_i18n($recent_total),
        ];

        $ai_entry['details'][] = [
            'label' => __('Pending', 'sitepulse'),
            'value' => number_format_i18n($recent_pending),
        ];

        if ($recent_ack > 0) {
            $ai_entry['details'][] = [
                'label' => __('Acknowledged', 'sitepulse'),
                'value' => number_format_i18n($recent_ack),
            ];
        }

        if ($stale_pending > 0) {
            $ai_entry['details'][] = [
                'label' => __('Legacy backlog', 'sitepulse'),
                'value' => number_format_i18n($stale_pending),
            ];
        }

        $weight_value = isset($weights['ai_insights']) ? max(0.0, (float) $weights['ai_insights']) : 0.0;

        if ($weight_value > 0) {
            $active_weights['ai_insights'] = $weight_value;
        }

        if ($ai_entry['score'] > $dominant_score) {
            $dominant_score  = $ai_entry['score'];
            $dominant_module = 'ai_insights';
        }
    } elseif (!$ai_entry['active']) {
        $ai_entry['signal'] = __('Module inactive', 'sitepulse');
    } else {
        $ai_entry['signal'] = __('Awaiting AI insights', 'sitepulse');
    }

    $modules_output['ai_insights'] = $ai_entry;

    $impact = [
        'range'           => $range_id,
        'updated_at'      => $now,
        'window_seconds'  => $window_seconds,
        'modules'         => $modules_output,
        'dominant_module' => $dominant_module,
        'overall'         => null,
        'active_modules'  => 0,
    ];

    if (!empty($active_weights)) {
        $total_weight = array_sum($active_weights);

        if ($total_weight > 0) {
            $weighted_sum = 0.0;

            foreach ($active_weights as $module_key => $weight) {
                if (!isset($modules_output[$module_key]['score']) || !is_numeric($modules_output[$module_key]['score'])) {
                    continue;
                }

                $weighted_sum += (float) $modules_output[$module_key]['score'] * ($weight / $total_weight);
            }

            $impact['overall'] = round($weighted_sum, 2);
        }
    }

    foreach ($modules_output as $module_data) {
        if (!empty($module_data['active']) && isset($module_data['score']) && is_numeric($module_data['score'])) {
            $impact['active_modules']++;
        }
    }

    sitepulse_custom_dashboard_store_impact_index($impact);

    return $impact;
}

/**
 * Formats the impact card view for the dashboard KPI grid.
 *
 * @param array<string,mixed>|null $impact      Impact payload.
 * @param string                   $range_label Human-readable range label.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_impact_card_view($impact, $range_label) {
    $status_meta = sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $card = [
        'label'            => __('Impact index', 'sitepulse'),
        'status'           => array_merge($status_meta, ['class' => 'status-warn']),
        'value'            => ['text' => __('N/A', 'sitepulse'), 'unit' => ''],
        'summary'          => __('No impact data available for this window.', 'sitepulse'),
        'trend'            => sitepulse_custom_dashboard_format_trend(null),
        'details'          => [],
        'description'      => '',
        'inactive'         => false,
        'inactive_message' => '',
    ];

    if (!is_array($impact) || empty($impact['modules']) || !is_array($impact['modules'])) {
        $card['inactive'] = true;
        $card['inactive_message'] = __('Activate the monitoring modules to compute the impact score.', 'sitepulse');
        $card['description'] = sprintf(__('No impact score could be generated for %s.', 'sitepulse'), $range_label);

        return $card;
    }

    $modules = $impact['modules'];
    $overall = isset($impact['overall']) && is_numeric($impact['overall']) ? (float) $impact['overall'] : null;
    $dominant = isset($impact['dominant_module']) ? (string) $impact['dominant_module'] : '';

    if ($overall !== null) {
        $status_key  = sitepulse_custom_dashboard_resolve_score_status($overall);
        $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status_key);

        $card['status'] = array_merge($status_meta, ['class' => $status_key]);
        $card['value']  = ['text' => number_format_i18n($overall, 1), 'unit' => ''];
    }

    $dominant_label = '';
    $dominant_score = null;

    if ($dominant !== '' && isset($modules[$dominant]) && is_array($modules[$dominant])) {
        $dominant_label = isset($modules[$dominant]['label']) ? $modules[$dominant]['label'] : $dominant;
        $dominant_score = isset($modules[$dominant]['score']) && is_numeric($modules[$dominant]['score'])
            ? (float) $modules[$dominant]['score']
            : null;
    }

    if ($dominant_label === '') {
        foreach ($modules as $module_data) {
            if (!is_array($module_data)) {
                continue;
            }

            if (isset($module_data['score']) && is_numeric($module_data['score'])) {
                $dominant_label = isset($module_data['label']) ? $module_data['label'] : '';
                $dominant_score = (float) $module_data['score'];
                break;
            }
        }
    }

    if ($overall !== null && $overall < 35.0) {
        $card['summary'] = sprintf(__('Signals nominal across monitored modules for %s.', 'sitepulse'), $range_label);
    } elseif ($dominant_label !== '' && $dominant_score !== null) {
        if ($dominant_score >= 70.0) {
            $card['summary'] = sprintf(__('Critical pressure from %s.', 'sitepulse'), $dominant_label);
        } else {
            $card['summary'] = sprintf(__('Attention needed on %s.', 'sitepulse'), $dominant_label);
        }
    } else {
        $card['summary'] = sprintf(__('Impact score partially available for %s.', 'sitepulse'), $range_label);
    }

    $card['description'] = sprintf(__('Weighted synthesis of uptime, speed and AI insights over %s.', 'sitepulse'), $range_label);

    $has_active_score = false;

    foreach ($modules as $module_key => $module_data) {
        if (!is_array($module_data)) {
            continue;
        }

        $label = isset($module_data['label']) ? $module_data['label'] : ucfirst(str_replace('_', ' ', (string) $module_key));
        $score_value = isset($module_data['score']) && is_numeric($module_data['score'])
            ? number_format_i18n((float) $module_data['score'], 1)
            : __('N/A', 'sitepulse');
        $status_key  = isset($module_data['status']) ? (string) $module_data['status'] : 'status-warn';
        $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status_key);
        $signal      = isset($module_data['signal']) ? $module_data['signal'] : '';

        $detail_value = $score_value;

        if (!empty($status_meta['label'])) {
            $detail_value .= ' • ' . $status_meta['label'];
        }

        if ($signal !== '') {
            $detail_value .= ' — ' . $signal;
        }

        $card['details'][] = [
            'label' => $label,
            'value' => $detail_value,
        ];

        if (!empty($module_data['active']) && isset($module_data['score']) && is_numeric($module_data['score'])) {
            $has_active_score = true;
        }
    }

    if (!$has_active_score) {
        $card['inactive'] = true;
        $card['inactive_message'] = __('No active module provided enough data to compute the impact index.', 'sitepulse');
    }

    return $card;
}

/**
 * Formats rows describing the impact index for CSV exports.
 *
 * @param array<string,mixed> $impact      Impact payload.
 * @param string              $range_label Range label used in the export.
 * @return array<int,array<int,string>>
 */
function sitepulse_custom_dashboard_format_impact_export_rows($impact, $range_label) {
    if (!is_array($impact) || empty($impact['modules']) || !is_array($impact['modules'])) {
        return [];
    }

    $rows = [];
    $rows[] = [__('Indice transverse', 'sitepulse'), $range_label];

    if (isset($impact['overall']) && is_numeric($impact['overall'])) {
        $overall    = (float) $impact['overall'];
        $status_key = sitepulse_custom_dashboard_resolve_score_status($overall);
        $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status_key);

        $rows[] = [
            __('Score global', 'sitepulse'),
            number_format_i18n($overall, 1),
            $status_meta['label'],
        ];
    }

    $rows[] = [
        __('Module', 'sitepulse'),
        __('Score', 'sitepulse'),
        __('Statut', 'sitepulse'),
        __('Signal clé', 'sitepulse'),
    ];

    foreach ($impact['modules'] as $module_data) {
        if (!is_array($module_data)) {
            continue;
        }

        $label = isset($module_data['label']) ? $module_data['label'] : '';
        $score_text = isset($module_data['score']) && is_numeric($module_data['score'])
            ? number_format_i18n((float) $module_data['score'], 1)
            : __('N/A', 'sitepulse');
        $status_key = isset($module_data['status']) ? (string) $module_data['status'] : 'status-warn';
        $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status_key);
        $signal = isset($module_data['signal']) ? $module_data['signal'] : '';

        $rows[] = [
            $label,
            $score_text,
            $status_meta['label'],
            $signal,
        ];
    }

    return $rows;
}

/**
 * Reads and summarises the WordPress debug log for dashboard usage.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_analyze_debug_log($force_refresh = false) {
    static $cached = null;

    if (!$force_refresh && $cached !== null) {
        return $cached;
    }

    $transient_key = defined('SITEPULSE_TRANSIENT_DEBUG_LOG_SUMMARY')
        ? SITEPULSE_TRANSIENT_DEBUG_LOG_SUMMARY
        : 'sitepulse_dashboard_log_summary';

    if ($force_refresh && function_exists('delete_transient')) {
        delete_transient($transient_key);
    }

    $log_file = function_exists('sitepulse_get_wp_debug_log_path')
        ? sitepulse_get_wp_debug_log_path()
        : null;

    $cache_signature = 'unavailable:' . md5((string) $log_file);

    if ($log_file !== null && file_exists($log_file)) {
        $mtime = @filemtime($log_file);
        $size  = @filesize($log_file);

        if ($mtime !== false || $size !== false) {
            $cache_signature = sprintf(
                '%s:%s:%s',
                md5($log_file),
                $mtime !== false ? (int) $mtime : 0,
                $size !== false ? (int) $size : 0
            );
        } else {
            $cache_signature = 'stat:' . md5((string) $log_file);
        }
    }

    if (!$force_refresh && function_exists('get_transient')) {
        $transient_value = get_transient($transient_key);

        if (
            is_array($transient_value)
            && isset($transient_value['signature'], $transient_value['data'])
            && $transient_value['signature'] === $cache_signature
        ) {
            $cached = $transient_value['data'];

            if (is_array($cached)) {
                return $cached;
            }
        }
    }

    $counts = [
        'fatal'      => 0,
        'warning'    => 0,
        'notice'     => 0,
        'deprecated' => 0,
    ];

    $status   = 'status-ok';
    $summary  = __('Log is clean.', 'sitepulse');
    $metadata = null;
    $truncated = false;
    $readable  = false;

    if ($log_file === null) {
        $status  = 'status-warn';
        $summary = __('Debug log not configured.', 'sitepulse');
    } elseif (!file_exists($log_file)) {
        $status  = 'status-warn';
        $summary = sprintf(__('Log file not found (%s).', 'sitepulse'), $log_file);
    } elseif (!is_readable($log_file)) {
        $status  = 'status-warn';
        $summary = sprintf(__('Unable to read log file (%s).', 'sitepulse'), $log_file);
    } else {
        $readable  = true;
        $log_lines = sitepulse_get_recent_log_lines($log_file, 200, 131072, true);

        if ($log_lines === null) {
            $status  = 'status-warn';
            $summary = sprintf(__('Unable to read log file (%s).', 'sitepulse'), $log_file);
        } else {
            $lines = [];

            if (is_array($log_lines) && isset($log_lines['lines'])) {
                $lines     = (array) $log_lines['lines'];
                $metadata  = $log_lines;
                $truncated = !empty($log_lines['truncated']);
            } else {
                $lines = (array) $log_lines;
            }

            if (empty($lines)) {
                $summary = __('No recent log entries.', 'sitepulse');

                if (is_array($metadata)) {
                    $metadata['lines'] = [];
                }
            } else {
                $content = implode("\n", $lines);

                $patterns = [
                    'fatal'      => '/PHP (Fatal error|Parse error|Uncaught)/i',
                    'warning'    => '/PHP Warning/i',
                    'notice'     => '/PHP Notice/i',
                    'deprecated' => '/PHP Deprecated/i',
                ];

                foreach ($patterns as $type => $pattern) {
                    $matches        = preg_match_all($pattern, $content, $ignore_matches);
                    $counts[$type]  = $matches ? (int) $matches : 0;
                }

                if ($counts['fatal'] > 0) {
                    $status  = 'status-bad';
                    $summary = __('Fatal errors detected in the debug log.', 'sitepulse');
                } elseif ($counts['warning'] > 0 || $counts['deprecated'] > 0) {
                    $status  = 'status-warn';
                    $summary = __('Warnings present in the debug log.', 'sitepulse');
                } else {
                    $summary = __('No critical events detected.', 'sitepulse');

                    if ($truncated) {
                        $summary .= ' ' . __('(Only the tail of the log is displayed.)', 'sitepulse');
                    }
                }
            }
        }
    }

    $chart = [
        'type'      => 'doughnut',
        'labels'    => [
            __('Fatal errors', 'sitepulse'),
            __('Warnings', 'sitepulse'),
            __('Notices', 'sitepulse'),
            __('Deprecated notices', 'sitepulse'),
        ],
        'datasets'  => array_sum($counts) > 0
            ? [[
                'data'            => array_values($counts),
                'backgroundColor' => ['#ff3b30', '#ff9500', '#007bff', '#af52de'],
                'borderWidth'     => 0,
            ]]
            : [],
        'empty'     => array_sum($counts) === 0,
        'status'    => $status,
        'truncated' => $truncated,
    ];

    $detailed_metadata = [
        'path'       => $log_file,
        'available'  => $log_file !== null,
        'readable'   => $readable,
        'truncated'  => $truncated,
        'bytes_read' => is_array($metadata) && isset($metadata['bytes_read']) ? (int) $metadata['bytes_read'] : null,
        'file_size'  => is_array($metadata) && isset($metadata['file_size']) ? (int) $metadata['file_size'] : null,
        'last_modified' => is_array($metadata) && isset($metadata['last_modified'])
            ? (int) $metadata['last_modified']
            : null,
    ];

    if (is_array($metadata) && isset($metadata['lines'])) {
        $detailed_metadata['lines'] = (array) $metadata['lines'];
    }

    $cached = [
        'card' => [
            'status'  => $status,
            'summary' => $summary,
            'counts'  => $counts,
            'meta'    => $metadata,
        ],
        'chart'     => $chart,
        'metadata'  => $detailed_metadata,
    ];

    if (function_exists('set_transient')) {
        $ttl = (int) apply_filters('sitepulse_dashboard_debug_log_cache_ttl', 5 * MINUTE_IN_SECONDS, $cached, $cache_signature);

        if ($ttl > 0) {
            set_transient($transient_key, [
                'signature' => $cache_signature,
                'data'      => $cached,
            ], $ttl);
        }
    }

    return $cached;
}

/**
 * Normalises the stored speed history when the Speed Analyzer module is inactive.
 *
 * @return array<int,array{timestamp:int,server_processing_ms:float}>
 */
function sitepulse_custom_dashboard_get_speed_history() {
    if (function_exists('sitepulse_speed_analyzer_get_history_data')) {
        return sitepulse_speed_analyzer_get_history_data();
    }

    $option_key = defined('SITEPULSE_OPTION_SPEED_SCAN_HISTORY')
        ? SITEPULSE_OPTION_SPEED_SCAN_HISTORY
        : 'sitepulse_speed_scan_history';

    $history = get_option($option_key, []);

    if (!is_array($history)) {
        return [];
    }

    $normalized = [];

    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (!isset($entry['timestamp'], $entry['server_processing_ms'])) {
            continue;
        }

        if (!is_numeric($entry['timestamp']) || !is_numeric($entry['server_processing_ms'])) {
            continue;
        }

        $normalized[] = [
            'timestamp'            => max(0, (int) $entry['timestamp']),
            'server_processing_ms' => max(0.0, (float) $entry['server_processing_ms']),
        ];
    }

    usort(
        $normalized,
        static function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        }
    );

    return $normalized;
}

/**
 * Resolves the status of a speed measurement against the configured thresholds.
 *
 * @param float|int|null        $value      Measurement in milliseconds.
 * @param array<string,int>     $thresholds Warning and critical thresholds.
 * @return string
 */
function sitepulse_custom_dashboard_resolve_speed_status($value, $thresholds) {
    if (function_exists('sitepulse_speed_analyzer_resolve_status')) {
        return sitepulse_speed_analyzer_resolve_status($value, $thresholds);
    }

    if (!is_numeric($value)) {
        return 'status-warn';
    }

    $warning  = isset($thresholds['warning']) ? (int) $thresholds['warning'] : 0;
    $critical = isset($thresholds['critical']) ? (int) $thresholds['critical'] : 0;
    $value    = (float) $value;

    if ($critical > 0 && $value >= $critical) {
        return 'status-bad';
    }

    if ($warning > 0 && $value >= $warning) {
        return 'status-warn';
    }

    return 'status-ok';
}

/**
 * Retrieves the configured speed thresholds without requiring the Speed module.
 *
 * @return array<string,int>
 */
function sitepulse_custom_dashboard_get_speed_thresholds_for_dashboard() {
    if (function_exists('sitepulse_get_speed_thresholds')) {
        $thresholds = sitepulse_get_speed_thresholds();

        if (is_array($thresholds)) {
            return $thresholds;
        }
    }

    $default_warning  = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
    $default_critical = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;

    $warning_option  = defined('SITEPULSE_OPTION_SPEED_WARNING_MS') ? SITEPULSE_OPTION_SPEED_WARNING_MS : 'sitepulse_speed_warning_ms';
    $critical_option = defined('SITEPULSE_OPTION_SPEED_CRITICAL_MS') ? SITEPULSE_OPTION_SPEED_CRITICAL_MS : 'sitepulse_speed_critical_ms';

    $warning  = (int) get_option($warning_option, $default_warning);
    $critical = (int) get_option($critical_option, $default_critical);

    if ($warning <= 0) {
        $warning = $default_warning;
    }

    if ($critical <= 0 || $critical <= $warning) {
        $critical = max($warning + 1, $default_critical);
    }

    return [
        'warning'  => $warning,
        'critical' => $critical,
    ];
}

/**
 * Calculates the arithmetic mean of speed measurements.
 *
 * @param array<int,array<string,float|int>> $entries History entries.
 * @return float|null
 */
function sitepulse_custom_dashboard_average_measurements($entries) {
    if (empty($entries) || !is_array($entries)) {
        return null;
    }

    $sum   = 0.0;
    $count = 0;

    foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry['server_processing_ms'])) {
            continue;
        }

        if (!is_numeric($entry['server_processing_ms'])) {
            continue;
        }

        $sum   += (float) $entry['server_processing_ms'];
        $count++;
    }

    if ($count === 0) {
        return null;
    }

    return $sum / $count;
}

/**
 * Aggregates speed metrics for the requested window.
 *
 * @param string              $range  Range identifier.
 * @param array<string,mixed> $config Range configuration.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_calculate_speed_metrics($range, $config) {
    $history = sitepulse_custom_dashboard_get_speed_history();

    $window_seconds = isset($config['seconds']) ? (int) $config['seconds'] : 0;

    if ($window_seconds < 0) {
        $window_seconds = 0;
    }

    $now          = sitepulse_custom_dashboard_get_current_timestamp();
    $window_start = $window_seconds > 0 ? $now - $window_seconds : 0;

    $current_entries  = [];
    $previous_entries = [];

    if (!empty($history)) {
        foreach ($history as $entry) {
            if (!is_array($entry) || !isset($entry['timestamp'])) {
                continue;
            }

            $timestamp = (int) $entry['timestamp'];

            if ($window_seconds <= 0) {
                $current_entries[] = $entry;
                continue;
            }

            if ($timestamp >= $window_start) {
                $current_entries[] = $entry;
            } elseif ($timestamp >= ($window_start - $window_seconds)) {
                $previous_entries[] = $entry;
            }
        }
    }

    $current_avg  = sitepulse_custom_dashboard_average_measurements($current_entries);
    $previous_avg = sitepulse_custom_dashboard_average_measurements($previous_entries);

    $thresholds = sitepulse_custom_dashboard_get_speed_thresholds_for_dashboard();

    $latest_entry = null;

    if (!empty($current_entries)) {
        $latest_entry = $current_entries[count($current_entries) - 1];
    } elseif (!empty($history)) {
        $latest_entry = $history[count($history) - 1];
    }

    $latest_payload = null;

    if (is_array($latest_entry)) {
        $latest_payload = [
            'timestamp'            => isset($latest_entry['timestamp']) ? (int) $latest_entry['timestamp'] : 0,
            'server_processing_ms' => isset($latest_entry['server_processing_ms'])
                ? round((float) $latest_entry['server_processing_ms'], 2)
                : null,
            'status'              => sitepulse_custom_dashboard_resolve_speed_status(
                $latest_entry['server_processing_ms'] ?? null,
                $thresholds
            ),
        ];
    }

    return [
        'range'             => $range,
        'window_seconds'    => $window_seconds,
        'samples'           => count($current_entries),
        'previous_samples'  => count($previous_entries),
        'average'           => $current_avg !== null ? round($current_avg, 2) : null,
        'previous_average'  => $previous_avg !== null ? round($previous_avg, 2) : null,
        'trend'             => sitepulse_custom_dashboard_calculate_trend($current_avg, $previous_avg, 2),
        'latest'            => $latest_payload,
        'thresholds'        => $thresholds,
        'history_available' => !empty($history),
    ];
}

function sitepulse_get_dashboard_preferences($user_id = 0, $allowed_cards = null) {
    if (!is_int($user_id) || $user_id <= 0) {
        $user_id = get_current_user_id();
    }

    $stored_preferences = [];

    if ($user_id > 0) {
        $stored_preferences = get_user_meta($user_id, 'sitepulse_dashboard_preferences', true);

        if (!is_array($stored_preferences)) {
            $stored_preferences = [];
        }
    }

    return sitepulse_sanitize_dashboard_preferences($stored_preferences, $allowed_cards);
}

/**
 * Persists dashboard preferences for the supplied user.
 *
 * @param int              $user_id       User identifier.
 * @param array            $preferences   Preferences to store.
 * @param string[]|null    $allowed_cards Optional subset of cards to accept.
 *
 * @return bool True on success, false otherwise.
 */
function sitepulse_update_dashboard_preferences($user_id, $preferences, $allowed_cards = null) {
    $user_id = (int) $user_id;

    if ($user_id <= 0) {
        return false;
    }

    $sanitized = sitepulse_sanitize_dashboard_preferences($preferences, $allowed_cards);

    return (bool) update_user_meta($user_id, 'sitepulse_dashboard_preferences', $sanitized);
}

/**
 * Handles AJAX requests to store dashboard preferences for the current user.
 */
function sitepulse_save_dashboard_preferences() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error(['message' => __('Vous n’avez pas les permissions nécessaires pour modifier ces préférences.', 'sitepulse')], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field((string) wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, 'sitepulse_dashboard_preferences')) {
        wp_send_json_error(['message' => __('Jeton de sécurité invalide. Merci de recharger la page.', 'sitepulse')], 400);
    }

    $raw_preferences = [
        'order'      => isset($_POST['order']) ? (array) wp_unslash($_POST['order']) : [],
        'visibility' => isset($_POST['visibility']) ? (array) wp_unslash($_POST['visibility']) : [],
        'sizes'      => isset($_POST['sizes']) ? (array) wp_unslash($_POST['sizes']) : [],
        'theme'      => isset($_POST['theme']) ? (string) wp_unslash($_POST['theme']) : '',
    ];

    $allowed_cards = sitepulse_get_dashboard_card_keys();
    $preferences = sitepulse_sanitize_dashboard_preferences($raw_preferences, $allowed_cards);
    $user_id = get_current_user_id();

    if (!sitepulse_update_dashboard_preferences($user_id, $preferences, $allowed_cards)) {
        wp_send_json_error(['message' => __('Impossible d’enregistrer les préférences pour le moment.', 'sitepulse')], 500);
    }

    wp_send_json_success(['preferences' => $preferences]);
}

/**
 * Builds a reusable context describing the dashboard cards and charts.
 *
 * @return array
 */
function sitepulse_get_dashboard_preview_context() {
    static $context = null;

    if (null !== $context) {
        return $context;
    }

    $active_modules = array_map('strval', (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []));
    $active_modules = array_values(array_filter($active_modules, static function ($module) {
        return $module !== '';
    }));

    global $wpdb;

    $is_speed_enabled = in_array('speed_analyzer', $active_modules, true);
    $is_uptime_enabled = in_array('uptime_tracker', $active_modules, true);
    $is_database_enabled = in_array('database_optimizer', $active_modules, true);
    $is_logs_enabled = in_array('log_analyzer', $active_modules, true);

    $palette = [
        'green'    => '#0b6d2a',
        'amber'    => '#8a6100',
        'red'      => '#a0141e',
        'deep_red' => '#7f1018',
        'blue'     => '#2196F3',
        'grey'     => '#E0E0E0',
        'purple'   => '#9C27B0',
    ];

    $status_labels = [
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

    $default_status_labels = $status_labels;

    $context = [
        'active_modules' => $active_modules,
        'palette'        => $palette,
        'status_labels'  => $status_labels,
        'modules'        => [
            'speed' => [
                'enabled'     => $is_speed_enabled,
                'card'        => null,
                'chart'       => null,
                'thresholds'  => [
                    'warning'  => defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200,
                    'critical' => defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500,
                ],
            ],
            'uptime' => [
                'enabled' => $is_uptime_enabled,
                'card'    => null,
                'chart'   => null,
            ],
            'database' => [
                'enabled' => $is_database_enabled,
                'card'    => null,
                'chart'   => null,
            ],
            'logs' => [
                'enabled' => $is_logs_enabled,
                'card'    => null,
                'chart'   => null,
            ],
        ],
        'charts_payload' => [],
    ];

    $charts_payload = [];

    if ($is_speed_enabled) {
        $results = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        $raw_processing_time = null;

        if (is_array($results)) {
            if (isset($results['server_processing_ms']) && is_numeric($results['server_processing_ms'])) {
                $raw_processing_time = (float) $results['server_processing_ms'];
            } elseif (isset($results['ttfb']) && is_numeric($results['ttfb'])) {
                $raw_processing_time = (float) $results['ttfb'];
            } elseif (isset($results['data']['server_processing_ms']) && is_numeric($results['data']['server_processing_ms'])) {
                $raw_processing_time = (float) $results['data']['server_processing_ms'];
            } elseif (isset($results['data']['ttfb']) && is_numeric($results['data']['ttfb'])) {
                $raw_processing_time = (float) $results['data']['ttfb'];
            }
        }

        $history_entries = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

        if (!is_array($history_entries)) {
            $history_entries = [];
        }

        $history_entries = array_values(array_filter(
            $history_entries,
            static function ($entry) {
                if (!is_array($entry)) {
                    return false;
                }

                if (!isset($entry['server_processing_ms']) || !is_numeric($entry['server_processing_ms'])) {
                    return false;
                }

                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

                return $timestamp > 0;
            }
        ));

        if (!empty($history_entries)) {
            usort(
                $history_entries,
                static function ($a, $b) {
                    $a_timestamp = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
                    $b_timestamp = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;

                    if ($a_timestamp === $b_timestamp) {
                        return 0;
                    }

                    return ($a_timestamp < $b_timestamp) ? -1 : 1;
                }
            );
        }

        $history_point_limit = apply_filters('sitepulse_speed_history_chart_points', 30);

        if (!is_scalar($history_point_limit)) {
            $history_point_limit = 30;
        }

        $history_point_limit = max(1, (int) $history_point_limit);

        if (count($history_entries) > $history_point_limit) {
            $history_entries = array_slice($history_entries, -$history_point_limit);
        }

        if (empty($history_entries) && $raw_processing_time !== null) {
            $fallback_timestamp = null;

            if (isset($results['timestamp']) && is_numeric($results['timestamp'])) {
                $fallback_timestamp = (int) $results['timestamp'];
            } elseif (isset($results['data']['timestamp']) && is_numeric($results['data']['timestamp'])) {
                $fallback_timestamp = (int) $results['data']['timestamp'];
            }

            if ($fallback_timestamp === null || $fallback_timestamp <= 0) {
                $fallback_timestamp = current_time('timestamp');
            }

            $history_entries[] = [
                'timestamp'            => $fallback_timestamp,
                'server_processing_ms' => (float) $raw_processing_time,
            ];
        }

        $latest_entry = !empty($history_entries)
            ? $history_entries[count($history_entries) - 1]
            : null;

        $processing_time = $raw_processing_time;

        if (is_array($latest_entry) && isset($latest_entry['server_processing_ms']) && is_numeric($latest_entry['server_processing_ms'])) {
            $processing_time = (float) $latest_entry['server_processing_ms'];
        }

        $default_speed_thresholds = [
            'warning'  => defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200,
            'critical' => defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500,
        ];

        $speed_thresholds = function_exists('sitepulse_get_speed_thresholds')
            ? sitepulse_get_speed_thresholds()
            : $default_speed_thresholds;

        $speed_warning_threshold = isset($speed_thresholds['warning']) ? (int) $speed_thresholds['warning'] : $default_speed_thresholds['warning'];
        $speed_critical_threshold = isset($speed_thresholds['critical']) ? (int) $speed_thresholds['critical'] : $default_speed_thresholds['critical'];

        if ($speed_warning_threshold < 1) {
            $speed_warning_threshold = $default_speed_thresholds['warning'];
        }

        if ($speed_critical_threshold <= $speed_warning_threshold) {
            $speed_critical_threshold = max($speed_warning_threshold + 1, $default_speed_thresholds['critical']);
        }

        $processing_status = 'status-ok';

        if ($processing_time === null) {
            $processing_status = 'status-warn';
        } elseif ($processing_time >= $speed_critical_threshold) {
            $processing_status = 'status-bad';
        } elseif ($processing_time >= $speed_warning_threshold) {
            $processing_status = 'status-warn';
        }

        $processing_display = $processing_time !== null
            ? round($processing_time) . ' ' . esc_html__('ms', 'sitepulse')
            : esc_html__('N/A', 'sitepulse');

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $speed_labels = [];
        $speed_values = [];

        foreach ($history_entries as $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $value = isset($entry['server_processing_ms']) ? (float) $entry['server_processing_ms'] : null;

            if ($value === null) {
                continue;
            }

            $label = $timestamp > 0
                ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                : __('Unknown', 'sitepulse');

            $speed_labels[] = $label;
            $speed_values[] = max(0.0, (float) $value);
        }

        $speed_values = array_map(
            static function ($value) {
                return round((float) $value, 2);
            },
            $speed_values
        );

        $speed_reference = max(1.0, (float) $speed_warning_threshold);
        $speed_chart = [
            'type'     => 'line',
            'labels'   => $speed_labels,
            'datasets' => [],
            'empty'    => empty($speed_labels),
            'status'   => $processing_status,
            'value'    => $processing_time !== null ? round($processing_time, 2) : null,
            'unit'     => __('ms', 'sitepulse'),
            'reference'=> (float) $speed_reference,
        ];

        if (!empty($speed_labels)) {
            $speed_color_map = [
                'status-ok'   => $palette['green'],
                'status-warn' => $palette['amber'],
                'status-bad'  => $palette['red'],
            ];
            $speed_primary_color = isset($speed_color_map[$processing_status]) ? $speed_color_map[$processing_status] : $palette['blue'];

            $speed_chart['datasets'][] = [
                'label'               => __('Processing time', 'sitepulse'),
                'data'                => $speed_values,
                'borderColor'         => $speed_primary_color,
                'pointBackgroundColor'=> $speed_primary_color,
                'pointRadius'         => 3,
                'tension'             => 0.3,
                'fill'                => false,
            ];

            $budget_values = array_fill(0, count($speed_labels), (float) $speed_reference);

            $speed_chart['datasets'][] = [
                'label'       => __('Performance budget', 'sitepulse'),
                'data'        => $budget_values,
                'borderColor' => $palette['amber'],
                'borderWidth' => 2,
                'borderDash'  => [6, 6],
                'pointRadius' => 0,
                'fill'        => false,
            ];
        }

        $charts_payload['speed'] = $speed_chart;
        $context['modules']['speed']['card'] = [
            'status'  => $processing_status,
            'display' => $processing_display,
        ];
        $context['modules']['speed']['chart'] = $speed_chart;
        $context['modules']['speed']['thresholds'] = [
            'warning'  => $speed_warning_threshold,
            'critical' => $speed_critical_threshold,
        ];
    }

    if ($is_uptime_enabled) {
        $raw_uptime_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $uptime_log = function_exists('sitepulse_normalize_uptime_log')
            ? sitepulse_normalize_uptime_log($raw_uptime_log)
            : (array) $raw_uptime_log;
        $boolean_checks = array_values(array_filter($uptime_log, function ($entry) {
            return is_array($entry) && array_key_exists('status', $entry) && is_bool($entry['status']);
        }));
        $evaluated_checks = count($boolean_checks);
        $up_checks = count(array_filter($boolean_checks, function ($entry) {
            return isset($entry['status']) && true === $entry['status'];
        }));
        $uptime_percentage = $evaluated_checks > 0 ? ($up_checks / $evaluated_checks) * 100 : 100;
        $default_uptime_warning = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0;

        if (function_exists('sitepulse_get_uptime_warning_percentage')) {
            $uptime_warning_threshold = (float) sitepulse_get_uptime_warning_percentage();
        } else {
            $uptime_warning_key = defined('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT') ? SITEPULSE_OPTION_UPTIME_WARNING_PERCENT : 'sitepulse_uptime_warning_percent';
            $stored_threshold = get_option($uptime_warning_key, $default_uptime_warning);
            $uptime_warning_threshold = is_scalar($stored_threshold) ? (float) $stored_threshold : $default_uptime_warning;
        }

        if ($uptime_warning_threshold < 0) {
            $uptime_warning_threshold = 0.0;
        } elseif ($uptime_warning_threshold > 100) {
            $uptime_warning_threshold = 100.0;
        }

        if ($uptime_percentage < $uptime_warning_threshold) {
            $uptime_status = 'status-bad';
        } elseif ($uptime_percentage < 100) {
            $uptime_status = 'status-warn';
        } else {
            $uptime_status = 'status-ok';
        }

        $uptime_entries = array_slice($uptime_log, -30);

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $uptime_labels = [];
        $uptime_values = [];
        $uptime_colors = [];

        foreach ($uptime_entries as $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $label = $timestamp > 0
                ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                : __('Unknown', 'sitepulse');
            $status = is_array($entry) && array_key_exists('status', $entry) ? $entry['status'] : (!empty($entry));

            $uptime_labels[] = $label;

            if ($status === false) {
                $uptime_values[] = 0;
                $uptime_colors[] = $palette['red'];
            } elseif ($status === true) {
                $uptime_values[] = 100;
                $uptime_colors[] = $palette['green'];
            } else {
                $uptime_values[] = 50;
                $uptime_colors[] = $palette['grey'];
            }
        }

        $uptime_chart = [
            'type'     => 'bar',
            'labels'   => $uptime_labels,
            'datasets' => [
                [
                    'data'            => $uptime_values,
                    'backgroundColor' => $uptime_colors,
                    'borderWidth'     => 0,
                    'borderRadius'    => 6,
                ],
            ],
            'empty'    => empty($uptime_labels),
            'status'   => $uptime_status,
            'unit'     => __('%', 'sitepulse'),
        ];

        $charts_payload['uptime'] = $uptime_chart;
        $context['modules']['uptime']['card'] = [
            'status'     => $uptime_status,
            'percentage' => $uptime_percentage,
        ];
        $context['modules']['uptime']['chart'] = $uptime_chart;
    }

    if ($is_database_enabled) {
        $revisions = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
        $default_revision_limit = defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? (int) SITEPULSE_DEFAULT_REVISION_LIMIT : 100;

        if (function_exists('sitepulse_get_revision_limit')) {
            $revision_limit = (int) sitepulse_get_revision_limit();
        } else {
            $revision_option_key = defined('SITEPULSE_OPTION_REVISION_LIMIT') ? SITEPULSE_OPTION_REVISION_LIMIT : 'sitepulse_revision_limit';
            $stored_limit = get_option($revision_option_key, $default_revision_limit);
            $revision_limit = is_scalar($stored_limit) ? (int) $stored_limit : $default_revision_limit;
        }

        if ($revision_limit < 1) {
            $revision_limit = $default_revision_limit;
        }

        $revision_warn_threshold = (int) floor($revision_limit * 0.5);
        if ($revision_warn_threshold < 1) {
            $revision_warn_threshold = 1;
        }

        if ($revision_warn_threshold >= $revision_limit) {
            $revision_warn_threshold = max(1, $revision_limit - 1);
        }

        if ($revisions > $revision_limit) {
            $db_status = 'status-bad';
        } elseif ($revisions > $revision_warn_threshold) {
            $db_status = 'status-warn';
        } else {
            $db_status = 'status-ok';
        }

        $database_chart = [
            'type'     => 'doughnut',
            'labels'   => [],
            'datasets' => [],
            'empty'    => false,
            'status'   => $db_status,
            'value'    => $revisions,
            'limit'    => $revision_limit,
        ];

        if ($revisions <= $revision_limit) {
            $database_chart['labels'] = [
                __('Stored revisions', 'sitepulse'),
                __('Remaining before cleanup', 'sitepulse'),
            ];
            $database_chart['datasets'][] = [
                'data' => [
                    $revisions,
                    max(0, $revision_limit - $revisions),
                ],
                'backgroundColor' => [
                    $palette['blue'],
                    $palette['grey'],
                ],
                'borderWidth' => 0,
            ];
        } else {
            $database_chart['labels'] = [
                __('Recommended maximum', 'sitepulse'),
                __('Excess revisions', 'sitepulse'),
            ];
            $database_chart['datasets'][] = [
                'data' => [
                    $revision_limit,
                    $revisions - $revision_limit,
                ],
                'backgroundColor' => [
                    $palette['amber'],
                    $palette['red'],
                ],
                'borderWidth' => 0,
            ];
        }

        $charts_payload['database'] = $database_chart;
        $context['modules']['database']['card'] = [
            'status'    => $db_status,
            'revisions' => $revisions,
            'limit'     => $revision_limit,
        ];
        $context['modules']['database']['chart'] = $database_chart;
    }

    if ($is_logs_enabled) {
        $log_snapshot = sitepulse_custom_dashboard_analyze_debug_log();
        $log_chart    = isset($log_snapshot['chart']) ? $log_snapshot['chart'] : [];

        if (!empty($log_chart['datasets']) && isset($log_chart['datasets'][0]) && is_array($log_chart['datasets'][0])) {
            $log_chart['datasets'][0]['backgroundColor'] = [
                $palette['red'],
                $palette['amber'],
                $palette['blue'],
                $palette['purple'],
            ];
        }

        $charts_payload['logs'] = $log_chart;
        $context['modules']['logs']['card']  = isset($log_snapshot['card']) ? $log_snapshot['card'] : null;
        $context['modules']['logs']['chart'] = $log_chart;
    }

    $get_status_meta = static function ($status) use ($status_labels, $default_status_labels) {
        if (isset($status_labels[$status])) {
            return $status_labels[$status];
        }

        if (isset($status_labels['status-warn'])) {
            return $status_labels['status-warn'];
        }

        return $default_status_labels['status-warn'];
    };

    $module_chart_keys = [
        'speed_analyzer'     => 'speed',
        'uptime_tracker'     => 'uptime',
        'database_optimizer' => 'database',
        'log_analyzer'       => 'logs',
    ];

    foreach ($module_chart_keys as $module_key => $chart_key) {
        if (!in_array($module_key, $active_modules, true) || !isset($charts_payload[$chart_key])) {
            unset($charts_payload[$chart_key]);
        }
    }

    $context['charts_payload'] = $charts_payload;

    return $context;
}

/**
 * Renders the HTML for the main SitePulse dashboard page.
 *
 * This page provides a visual overview of the site's key metrics,
 * acting as a central hub for site health information.
 *
 * Note: The menu registration for this page is now handled in 'admin-settings.php'
 * to prevent conflicts and duplicate menus.
 */
function sitepulse_custom_dashboards_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    if (!wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        sitepulse_custom_dashboard_enqueue_assets('toplevel_page_sitepulse-dashboard');
    }

    if (wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        wp_enqueue_script('sitepulse-chartjs');
        wp_enqueue_script('sitepulse-dashboard-charts');
    }

    $selected_range = sitepulse_custom_dashboard_get_stored_range();
    $metrics_payload = sitepulse_custom_dashboard_prepare_metrics_payload($selected_range);
    $metrics_view = isset($metrics_payload['view']) && is_array($metrics_payload['view'])
        ? $metrics_payload['view']
        : sitepulse_custom_dashboard_format_metrics_view($metrics_payload);
    $range_options = isset($metrics_payload['available_ranges']) && is_array($metrics_payload['available_ranges'])
        ? array_values($metrics_payload['available_ranges'])
        : array_values(sitepulse_custom_dashboard_get_metric_ranges());

    if (wp_script_is('sitepulse-dashboard-metrics', 'registered')) {
        wp_localize_script('sitepulse-dashboard-metrics', 'SitePulseMetricsData', [
            'restUrl' => esc_url_raw(rest_url('sitepulse/v1/metrics')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'view'    => $metrics_view,
            'ranges'  => $range_options,
            'strings' => [
                'loading'      => __('Refreshing metrics…', 'sitepulse'),
                'error'        => __('Unable to refresh metrics. Please try again.', 'sitepulse'),
                'announcement' => __('Dashboard metrics updated for %s.', 'sitepulse'),
            ],
        ]);
        wp_enqueue_script('sitepulse-dashboard-metrics');
    }

    $metrics_cards = isset($metrics_view['cards']) && is_array($metrics_view['cards']) ? $metrics_view['cards'] : [];
    $banner_view = isset($metrics_view['banner']) && is_array($metrics_view['banner']) ? $metrics_view['banner'] : [];
    $banner_tone = isset($banner_view['tone']) ? sanitize_html_class($banner_view['tone']) : 'ok';
    $banner_icon = isset($banner_view['icon']) ? $banner_view['icon'] : '✅';
    $banner_message = isset($banner_view['message']) ? $banner_view['message'] : '';
    $banner_sr = isset($banner_view['sr']) ? $banner_view['sr'] : '';
    $banner_cta = isset($banner_view['cta']) && is_array($banner_view['cta']) ? $banner_view['cta'] : [];
    $generated_text = isset($metrics_view['generated_text']) ? $metrics_view['generated_text'] : '';
    $range_label = isset($metrics_view['range_label']) ? $metrics_view['range_label'] : '';
    $current_range = isset($metrics_payload['range']) ? $metrics_payload['range'] : sitepulse_custom_dashboard_get_default_range();

    $default_palette = [
        'green'    => '#0b6d2a',
        'amber'    => '#8a6100',
        'red'      => '#a0141e',
        'deep_red' => '#7f1018',
        'blue'     => '#2196F3',
        'grey'     => '#E0E0E0',
        'purple'   => '#9C27B0',
    ];

    $default_status_labels = sitepulse_custom_dashboard_get_default_status_labels();

    $context = sitepulse_get_dashboard_preview_context();

    $palette = $default_palette;
    $status_labels = $default_status_labels;
    $get_status_meta = static function ($status) use (&$status_labels, $default_status_labels) {
        if (isset($status_labels[$status])) {
            return $status_labels[$status];
        }

        if (isset($status_labels['status-warn'])) {
            return $status_labels['status-warn'];
        }

        return $default_status_labels['status-warn'];
    };
    $charts_payload = [];
    $speed_card = null;
    $speed_chart = null;
    $uptime_card = null;
    $uptime_chart = null;
    $database_card = null;
    $database_chart = null;
    $logs_card = null;
    $log_chart = null;
    $resource_card = null;
    $resource_chart = null;
    $plugins_card = null;
    $plugins_chart = null;
    $speed_warning_threshold = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
    $speed_critical_threshold = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;
    $is_speed_enabled = false;
    $is_uptime_enabled = false;
    $is_database_enabled = false;
    $is_logs_enabled = false;
    $is_resource_enabled = false;
    $is_plugins_enabled = false;
    $active_modules = [];

    if (is_array($context) && !empty($context)) {
        if (isset($context['palette']) && is_array($context['palette'])) {
            $palette = array_merge($default_palette, $context['palette']);
        }

        if (isset($context['status_labels']) && is_array($context['status_labels'])) {
            $status_labels = array_merge($default_status_labels, $context['status_labels']);
        }

        $active_modules = isset($context['active_modules']) && is_array($context['active_modules']) ? $context['active_modules'] : [];
        $modules = isset($context['modules']) && is_array($context['modules']) ? $context['modules'] : [];

        $speed_data = isset($modules['speed']) && is_array($modules['speed']) ? $modules['speed'] : [];
        $uptime_data = isset($modules['uptime']) && is_array($modules['uptime']) ? $modules['uptime'] : [];
        $database_data = isset($modules['database']) && is_array($modules['database']) ? $modules['database'] : [];
        $logs_data = isset($modules['logs']) && is_array($modules['logs']) ? $modules['logs'] : [];
        $resource_data = isset($modules['resource']) && is_array($modules['resource']) ? $modules['resource'] : [];
        $plugins_data = isset($modules['plugins']) && is_array($modules['plugins']) ? $modules['plugins'] : [];

        $is_speed_enabled = !empty($speed_data['enabled']);
        $is_uptime_enabled = !empty($uptime_data['enabled']);
        $is_database_enabled = !empty($database_data['enabled']);
        $is_logs_enabled = !empty($logs_data['enabled']);
        $is_resource_enabled = !empty($resource_data['enabled']);
        $is_plugins_enabled = !empty($plugins_data['enabled']);

        $speed_card = isset($speed_data['card']) && is_array($speed_data['card']) ? $speed_data['card'] : null;
        $speed_chart = isset($speed_data['chart']) && is_array($speed_data['chart']) ? $speed_data['chart'] : null;
        $speed_thresholds = isset($speed_data['thresholds']) && is_array($speed_data['thresholds']) ? $speed_data['thresholds'] : [];

        if (isset($speed_thresholds['warning'])) {
            $speed_warning_threshold = (int) $speed_thresholds['warning'];
        }

        if (isset($speed_thresholds['critical'])) {
            $speed_critical_threshold = (int) $speed_thresholds['critical'];
        }

        $uptime_card = isset($uptime_data['card']) && is_array($uptime_data['card']) ? $uptime_data['card'] : null;
        $uptime_chart = isset($uptime_data['chart']) && is_array($uptime_data['chart']) ? $uptime_data['chart'] : null;

        $database_card = isset($database_data['card']) && is_array($database_data['card']) ? $database_data['card'] : null;
        $database_chart = isset($database_data['chart']) && is_array($database_data['chart']) ? $database_data['chart'] : null;

        $logs_card = isset($logs_data['card']) && is_array($logs_data['card']) ? $logs_data['card'] : null;
        $log_chart = isset($logs_data['chart']) && is_array($logs_data['chart']) ? $logs_data['chart'] : null;

        $resource_card = isset($resource_data['card']) && is_array($resource_data['card']) ? $resource_data['card'] : null;
        $resource_chart = isset($resource_data['chart']) && is_array($resource_data['chart']) ? $resource_data['chart'] : null;

        $plugins_card = isset($plugins_data['card']) && is_array($plugins_data['card']) ? $plugins_data['card'] : null;
        $plugins_chart = isset($plugins_data['chart']) && is_array($plugins_data['chart']) ? $plugins_data['chart'] : null;

        $charts_payload = isset($context['charts_payload']) && is_array($context['charts_payload'])
            ? $context['charts_payload']
            : [];
    } else {
        $active_modules = array_map('strval', (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []));
        global $wpdb;
        $is_speed_enabled = in_array('speed_analyzer', $active_modules, true);
        $is_uptime_enabled = in_array('uptime_tracker', $active_modules, true);
        $is_database_enabled = in_array('database_optimizer', $active_modules, true);
        $is_logs_enabled = in_array('log_analyzer', $active_modules, true);
        $is_resource_enabled = in_array('resource_monitor', $active_modules, true);
        $is_plugins_enabled = in_array('plugin_impact_scanner', $active_modules, true);

    $palette = $default_palette;
    $status_labels = $default_status_labels;

    $charts_payload = [];
    $speed_card = null;

    if ($is_speed_enabled) {
        $results = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        $raw_processing_time = null;

        if (is_array($results)) {
            if (isset($results['server_processing_ms']) && is_numeric($results['server_processing_ms'])) {
                $raw_processing_time = (float) $results['server_processing_ms'];
            } elseif (isset($results['ttfb']) && is_numeric($results['ttfb'])) {
                $raw_processing_time = (float) $results['ttfb'];
            } elseif (isset($results['data']['server_processing_ms']) && is_numeric($results['data']['server_processing_ms'])) {
                $raw_processing_time = (float) $results['data']['server_processing_ms'];
            } elseif (isset($results['data']['ttfb']) && is_numeric($results['data']['ttfb'])) {
                $raw_processing_time = (float) $results['data']['ttfb'];
            }
        }

        $history_entries = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

        if (!is_array($history_entries)) {
            $history_entries = [];
        }

        $history_entries = array_values(array_filter(
            $history_entries,
            static function ($entry) {
                if (!is_array($entry)) {
                    return false;
                }

                if (!isset($entry['server_processing_ms']) || !is_numeric($entry['server_processing_ms'])) {
                    return false;
                }

                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

                return $timestamp > 0;
            }
        ));

        if (!empty($history_entries)) {
            usort(
                $history_entries,
                static function ($a, $b) {
                    $a_timestamp = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
                    $b_timestamp = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;

                    if ($a_timestamp === $b_timestamp) {
                        return 0;
                    }

                    return ($a_timestamp < $b_timestamp) ? -1 : 1;
                }
            );
        }

        $history_point_limit = apply_filters('sitepulse_speed_history_chart_points', 30);

        if (!is_scalar($history_point_limit)) {
            $history_point_limit = 30;
        }

        $history_point_limit = max(1, (int) $history_point_limit);

        if (count($history_entries) > $history_point_limit) {
            $history_entries = array_slice($history_entries, -$history_point_limit);
        }

        if (empty($history_entries) && $raw_processing_time !== null) {
            $fallback_timestamp = null;

            if (isset($results['timestamp']) && is_numeric($results['timestamp'])) {
                $fallback_timestamp = (int) $results['timestamp'];
            } elseif (isset($results['data']['timestamp']) && is_numeric($results['data']['timestamp'])) {
                $fallback_timestamp = (int) $results['data']['timestamp'];
            }

            if ($fallback_timestamp === null || $fallback_timestamp <= 0) {
                $fallback_timestamp = current_time('timestamp');
            }

            $history_entries[] = [
                'timestamp'            => $fallback_timestamp,
                'server_processing_ms' => (float) $raw_processing_time,
            ];
        }

        $latest_entry = !empty($history_entries)
            ? $history_entries[count($history_entries) - 1]
            : null;

        $processing_time = $raw_processing_time;

        if (is_array($latest_entry) && isset($latest_entry['server_processing_ms']) && is_numeric($latest_entry['server_processing_ms'])) {
            $processing_time = (float) $latest_entry['server_processing_ms'];
        }

        $default_speed_thresholds = [
            'warning'  => defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200,
            'critical' => defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500,
        ];

        $speed_warning_threshold = $default_speed_thresholds['warning'];
        $speed_critical_threshold = $default_speed_thresholds['critical'];

        if (function_exists('sitepulse_get_speed_thresholds')) {
            $fetched_thresholds = sitepulse_get_speed_thresholds();

            if (is_array($fetched_thresholds)) {
                if (isset($fetched_thresholds['warning']) && is_numeric($fetched_thresholds['warning'])) {
                    $speed_warning_threshold = (int) $fetched_thresholds['warning'];
                }

                if (isset($fetched_thresholds['critical']) && is_numeric($fetched_thresholds['critical'])) {
                    $speed_critical_threshold = (int) $fetched_thresholds['critical'];
                }
            }
        } else {
            $warning_option_key = defined('SITEPULSE_OPTION_SPEED_WARNING_MS') ? SITEPULSE_OPTION_SPEED_WARNING_MS : 'sitepulse_speed_warning_ms';
            $critical_option_key = defined('SITEPULSE_OPTION_SPEED_CRITICAL_MS') ? SITEPULSE_OPTION_SPEED_CRITICAL_MS : 'sitepulse_speed_critical_ms';

            $stored_warning = get_option($warning_option_key, $default_speed_thresholds['warning']);
            $stored_critical = get_option($critical_option_key, $default_speed_thresholds['critical']);

            if (is_numeric($stored_warning)) {
                $speed_warning_threshold = (int) $stored_warning;
            }

            if (is_numeric($stored_critical)) {
                $speed_critical_threshold = (int) $stored_critical;
            }
        }

        if ($speed_warning_threshold < 1) {
            $speed_warning_threshold = $default_speed_thresholds['warning'];
        }

        if ($speed_critical_threshold <= $speed_warning_threshold) {
            $speed_critical_threshold = max($speed_warning_threshold + 1, $default_speed_thresholds['critical']);
        }

        $processing_status = 'status-ok';

        if ($processing_time === null) {
            $processing_status = 'status-warn';
        } elseif ($processing_time >= $speed_critical_threshold) {
            $processing_status = 'status-bad';
        } elseif ($processing_time >= $speed_warning_threshold) {
            $processing_status = 'status-warn';
        }

        $processing_display = $processing_time !== null
            ? round($processing_time) . ' ' . esc_html__('ms', 'sitepulse')
            : esc_html__('N/A', 'sitepulse');

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $speed_labels = [];
        $speed_values = [];

        foreach ($history_entries as $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $value = isset($entry['server_processing_ms']) ? (float) $entry['server_processing_ms'] : null;

            if ($value === null) {
                continue;
            }

            $label = $timestamp > 0
                ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                : __('Unknown', 'sitepulse');

            $speed_labels[] = $label;
            $speed_values[] = max(0.0, (float) $value);
        }

        $speed_values = array_map(
            static function ($value) {
                return round((float) $value, 2);
            },
            $speed_values
        );

        $speed_reference = max(1.0, (float) $speed_warning_threshold);
        $speed_chart = [
            'type'     => 'line',
            'labels'   => $speed_labels,
            'datasets' => [],
            'empty'    => empty($speed_labels),
            'status'   => $processing_status,
            'value'    => $processing_time !== null ? round($processing_time, 2) : null,
            'unit'     => __('ms', 'sitepulse'),
            'reference'=> (float) $speed_reference,
        ];

        if (!empty($speed_labels)) {
            $speed_color_map = [
                'status-ok'   => $palette['green'],
                'status-warn' => $palette['amber'],
                'status-bad'  => $palette['red'],
            ];
            $speed_primary_color = isset($speed_color_map[$processing_status]) ? $speed_color_map[$processing_status] : $palette['blue'];

            $speed_chart['datasets'][] = [
                'label'               => __('Processing time', 'sitepulse'),
                'data'                => $speed_values,
                'borderColor'         => $speed_primary_color,
                'pointBackgroundColor'=> $speed_primary_color,
                'pointRadius'         => 3,
                'tension'             => 0.3,
                'fill'                => false,
            ];

            $budget_values = array_fill(0, count($speed_labels), (float) $speed_reference);

            $speed_chart['datasets'][] = [
                'label'       => __('Performance budget', 'sitepulse'),
                'data'        => $budget_values,
                'borderColor' => $palette['amber'],
                'borderWidth' => 2,
                'borderDash'  => [6, 6],
                'pointRadius' => 0,
                'fill'        => false,
            ];
        }

        $charts_payload['speed'] = $speed_chart;
        $speed_card = [
            'status'  => $processing_status,
            'display' => $processing_display,
        ];
    }

    $uptime_card = null;

    if ($is_uptime_enabled) {
        $raw_uptime_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $uptime_log = function_exists('sitepulse_normalize_uptime_log')
            ? sitepulse_normalize_uptime_log($raw_uptime_log)
            : (array) $raw_uptime_log;
        $boolean_checks = array_values(array_filter($uptime_log, function ($entry) {
            return is_array($entry) && array_key_exists('status', $entry) && is_bool($entry['status']);
        }));
        $evaluated_checks = count($boolean_checks);
        $up_checks = count(array_filter($boolean_checks, function ($entry) {
            return isset($entry['status']) && true === $entry['status'];
        }));
        $uptime_percentage = $evaluated_checks > 0 ? ($up_checks / $evaluated_checks) * 100 : 100;
        $default_uptime_warning = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0;
        $uptime_warning_threshold = $default_uptime_warning;

        if (function_exists('sitepulse_get_uptime_warning_percentage')) {
            $uptime_warning_threshold = (float) sitepulse_get_uptime_warning_percentage();
        } else {
            $uptime_warning_key = defined('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT') ? SITEPULSE_OPTION_UPTIME_WARNING_PERCENT : 'sitepulse_uptime_warning_percent';
            $stored_threshold = get_option($uptime_warning_key, $default_uptime_warning);

            if (is_scalar($stored_threshold)) {
                $uptime_warning_threshold = (float) $stored_threshold;
            }
        }

        if ($uptime_warning_threshold < 0) {
            $uptime_warning_threshold = 0.0;
        } elseif ($uptime_warning_threshold > 100) {
            $uptime_warning_threshold = 100.0;
        }

        if ($uptime_percentage < $uptime_warning_threshold) {
            $uptime_status = 'status-bad';
        } elseif ($uptime_percentage < 100) {
            $uptime_status = 'status-warn';
        } else {
            $uptime_status = 'status-ok';
        }
        $uptime_entries = array_slice($uptime_log, -30);

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $uptime_labels = [];
        $uptime_values = [];
        $uptime_colors = [];

        foreach ($uptime_entries as $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $label = $timestamp > 0
                ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                : __('Unknown', 'sitepulse');
            $status = is_array($entry) && array_key_exists('status', $entry) ? $entry['status'] : (!empty($entry));

            $uptime_labels[] = $label;
            if ($status === false) {
                $uptime_values[] = 0;
                $uptime_colors[] = $palette['red'];
            } elseif ($status === true) {
                $uptime_values[] = 100;
                $uptime_colors[] = $palette['green'];
            } else {
                $uptime_values[] = 50;
                $uptime_colors[] = $palette['grey'];
            }
        }

        $uptime_chart = [
            'type'     => 'bar',
            'labels'   => $uptime_labels,
            'datasets' => [
                [
                    'data'            => $uptime_values,
                    'backgroundColor' => $uptime_colors,
                    'borderWidth'     => 0,
                    'borderRadius'    => 6,
                ],
            ],
            'empty'    => empty($uptime_labels),
            'status'   => $uptime_status,
            'unit'     => __('%', 'sitepulse'),
        ];

        $charts_payload['uptime'] = $uptime_chart;
        $uptime_card = [
            'status'      => $uptime_status,
            'percentage'  => $uptime_percentage,
        ];
    }

    $database_card = null;

    if ($is_database_enabled) {
        $revisions = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
        $default_revision_limit = defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? (int) SITEPULSE_DEFAULT_REVISION_LIMIT : 100;
        $revision_limit = $default_revision_limit;

        if (function_exists('sitepulse_get_revision_limit')) {
            $revision_limit = (int) sitepulse_get_revision_limit();
        } else {
            $revision_option_key = defined('SITEPULSE_OPTION_REVISION_LIMIT') ? SITEPULSE_OPTION_REVISION_LIMIT : 'sitepulse_revision_limit';
            $stored_limit = get_option($revision_option_key, $default_revision_limit);

            if (is_scalar($stored_limit)) {
                $revision_limit = (int) $stored_limit;
            }
        }

        if ($revision_limit < 1) {
            $revision_limit = $default_revision_limit;
        }

        $revision_warn_threshold = (int) floor($revision_limit * 0.5);
        if ($revision_warn_threshold < 1) {
            $revision_warn_threshold = 1;
        }

        if ($revision_warn_threshold >= $revision_limit) {
            $revision_warn_threshold = max(1, $revision_limit - 1);
        }

        if ($revisions > $revision_limit) {
            $db_status = 'status-bad';
        } elseif ($revisions > $revision_warn_threshold) {
            $db_status = 'status-warn';
        } else {
            $db_status = 'status-ok';
        }

        $database_chart = [
            'type'     => 'doughnut',
            'labels'   => [],
            'datasets' => [],
            'empty'    => false,
            'status'   => $db_status,
            'value'    => $revisions,
            'limit'    => $revision_limit,
        ];

        if ($revisions <= $revision_limit) {
            $database_chart['labels'] = [
                __('Stored revisions', 'sitepulse'),
                __('Remaining before cleanup', 'sitepulse'),
            ];
            $database_chart['datasets'][] = [
                'data' => [
                    $revisions,
                    max(0, $revision_limit - $revisions),
                ],
                'backgroundColor' => [
                    $palette['blue'],
                    $palette['grey'],
                ],
                'borderWidth' => 0,
            ];
        } else {
            $database_chart['labels'] = [
                __('Recommended maximum', 'sitepulse'),
                __('Excess revisions', 'sitepulse'),
            ];
            $database_chart['datasets'][] = [
                'data' => [
                    $revision_limit,
                    $revisions - $revision_limit,
                ],
                'backgroundColor' => [
                    $palette['amber'],
                    $palette['red'],
                ],
                'borderWidth' => 0,
            ];
        }

        $charts_payload['database'] = $database_chart;
        $database_card = [
            'status'   => $db_status,
            'revisions'=> $revisions,
            'limit'    => $revision_limit,
        ];
    }

    $logs_card = null;

    if ($is_logs_enabled) {
        $log_snapshot = sitepulse_custom_dashboard_analyze_debug_log();
        $log_chart    = isset($log_snapshot['chart']) ? $log_snapshot['chart'] : [];

        if (!empty($log_chart['datasets']) && isset($log_chart['datasets'][0]) && is_array($log_chart['datasets'][0])) {
            $log_chart['datasets'][0]['backgroundColor'] = [
                $palette['red'],
                $palette['amber'],
                $palette['blue'],
                $palette['purple'],
            ];
        }

        $charts_payload['logs'] = $log_chart;
        $logs_card = isset($log_snapshot['card']) ? $log_snapshot['card'] : null;
    }

    $resource_card = null;

    if ($is_resource_enabled && function_exists('sitepulse_resource_monitor_get_snapshot')) {
        $snapshot = sitepulse_resource_monitor_get_snapshot();
        $load_display = '';
        $load_values = [null, null, null];

        if (is_array($snapshot)) {
            $raw_load_values = [];

            if (isset($snapshot['load_raw']) && is_array($snapshot['load_raw'])) {
                $raw_load_values = $snapshot['load_raw'];
            } elseif (isset($snapshot['load']) && is_array($snapshot['load'])) {
                $raw_load_values = $snapshot['load'];
            }

            foreach (array_slice(array_values((array) $raw_load_values), 0, 3) as $index => $value) {
                if (is_numeric($value)) {
                    $load_values[$index] = (float) $value;
                }
            }

            if (function_exists('sitepulse_resource_monitor_format_load_display')) {
                $load_display = sitepulse_resource_monitor_format_load_display(isset($snapshot['load']) ? $snapshot['load'] : $load_values);
            } else {
                $load_display = implode(' / ', array_map(static function ($value) {
                    if ($value === null) {
                        return __('N/A', 'sitepulse');
                    }

                    return number_format_i18n((float) $value, 2);
                }, $load_values));
            }
        }

        $memory_usage = isset($snapshot['memory_usage']) ? (string) $snapshot['memory_usage'] : '';
        $memory_limit = isset($snapshot['memory_limit']) && $snapshot['memory_limit'] !== false
            ? (string) $snapshot['memory_limit']
            : '';
        $memory_usage_bytes = isset($snapshot['memory_usage_bytes']) ? (float) $snapshot['memory_usage_bytes'] : 0.0;
        $memory_limit_bytes = isset($snapshot['memory_limit_bytes']) ? (float) $snapshot['memory_limit_bytes'] : 0.0;

        $memory_percent = null;

        if (function_exists('sitepulse_resource_monitor_calculate_percentage')) {
            $memory_percent = sitepulse_resource_monitor_calculate_percentage(
                $snapshot['memory_usage_bytes'] ?? null,
                $snapshot['memory_limit_bytes'] ?? null
            );
        } elseif ($memory_limit_bytes > 0) {
            $memory_percent = min(100.0, max(0.0, ($memory_usage_bytes / $memory_limit_bytes) * 100));
        }

        $disk_free = isset($snapshot['disk_free']) ? (string) $snapshot['disk_free'] : '';
        $disk_total = isset($snapshot['disk_total']) ? (string) $snapshot['disk_total'] : '';
        $disk_free_bytes = isset($snapshot['disk_free_bytes']) ? (float) $snapshot['disk_free_bytes'] : 0.0;
        $disk_total_bytes = isset($snapshot['disk_total_bytes']) ? (float) $snapshot['disk_total_bytes'] : 0.0;

        $disk_free_percent = null;

        if (function_exists('sitepulse_resource_monitor_calculate_percentage')) {
            $disk_free_percent = sitepulse_resource_monitor_calculate_percentage(
                $snapshot['disk_free_bytes'] ?? null,
                $snapshot['disk_total_bytes'] ?? null
            );
        } elseif ($disk_total_bytes > 0) {
            $disk_free_percent = min(100.0, max(0.0, ($disk_free_bytes / $disk_total_bytes) * 100));
        }

        $status_order = [
            'status-ok'   => 0,
            'status-warn' => 1,
            'status-bad'  => 2,
        ];

        $resource_status = 'status-ok';

        $adjust_status = static function ($current, $candidate) use ($status_order) {
            if (!isset($status_order[$candidate])) {
                return $current;
            }

            if (!isset($status_order[$current]) || $status_order[$candidate] > $status_order[$current]) {
                return $candidate;
            }

            return $current;
        };

        if ($load_values[0] !== null) {
            if ($load_values[0] >= 4.0) {
                $resource_status = $adjust_status($resource_status, 'status-bad');
            } elseif ($load_values[0] >= 2.0) {
                $resource_status = $adjust_status($resource_status, 'status-warn');
            }
        }

        if ($memory_percent !== null) {
            if ($memory_percent >= 90.0) {
                $resource_status = $adjust_status($resource_status, 'status-bad');
            } elseif ($memory_percent >= 75.0) {
                $resource_status = $adjust_status($resource_status, 'status-warn');
            }
        }

        if ($disk_free_percent !== null) {
            if ($disk_free_percent <= 10.0) {
                $resource_status = $adjust_status($resource_status, 'status-bad');
            } elseif ($disk_free_percent <= 20.0) {
                $resource_status = $adjust_status($resource_status, 'status-warn');
            }
        }

        $resource_card = [
            'status'             => $resource_status,
            'load_display'       => $load_display,
            'memory_usage'       => $memory_usage,
            'memory_limit'       => $memory_limit,
            'memory_percent'     => $memory_percent,
            'disk_free'          => $disk_free,
            'disk_total'         => $disk_total,
            'disk_free_percent'  => $disk_free_percent,
            'generated_at'       => isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0,
        ];

        $memory_dataset = [];
        $memory_chart_empty = true;

        if ($memory_limit_bytes > 0 && $memory_usage_bytes >= 0 && $memory_usage_bytes <= $memory_limit_bytes) {
            $memory_used_mb = $memory_usage_bytes / MB_IN_BYTES;
            $memory_free_mb = ($memory_limit_bytes - $memory_usage_bytes) / MB_IN_BYTES;
            $memory_chart_empty = false;

            $memory_dataset[] = [
                'data' => [
                    round($memory_used_mb, 2),
                    max(0, round($memory_free_mb, 2)),
                ],
                'backgroundColor' => [
                    $palette['amber'],
                    $palette['green'],
                ],
                'borderWidth' => 0,
            ];
        }

        $resource_chart = [
            'type'     => 'doughnut',
            'labels'   => [
                __('Memory used', 'sitepulse'),
                __('Memory available', 'sitepulse'),
            ],
            'datasets' => $memory_dataset,
            'unit'     => __('MB', 'sitepulse'),
            'empty'    => $memory_chart_empty,
            'status'   => $resource_status,
        ];

        $charts_payload['resource'] = $resource_chart;
    }

    $plugins_card = null;

    if ($is_plugins_enabled && function_exists('sitepulse_plugin_impact_get_measurements')) {
        $measurements = sitepulse_plugin_impact_get_measurements();
        $samples = isset($measurements['samples']) && is_array($measurements['samples']) ? $measurements['samples'] : [];
        $plugin_entries = [];
        $total_impact = 0.0;

        foreach ($samples as $plugin_file => $sample) {
            if (!is_array($sample) || !isset($sample['avg_ms']) || !is_numeric($sample['avg_ms'])) {
                continue;
            }

            $avg_ms = max(0.0, (float) $sample['avg_ms']);
            $last_ms = isset($sample['last_ms']) && is_numeric($sample['last_ms']) ? max(0.0, (float) $sample['last_ms']) : null;
            $count = isset($sample['samples']) ? max(0, (int) $sample['samples']) : 0;
            $last_recorded = isset($sample['last_recorded']) ? (int) $sample['last_recorded'] : 0;

            $label = $plugin_file;

            if (function_exists('sitepulse_plugin_impact_guess_slug')) {
                $slug = sitepulse_plugin_impact_guess_slug($plugin_file, []);
                if (is_string($slug) && $slug !== '') {
                    $label = ucwords(str_replace('-', ' ', str_replace('_', ' ', $slug)));
                }
            }

            $total_impact += $avg_ms;

            $plugin_entries[] = [
                'file'          => (string) $plugin_file,
                'label'         => (string) $label,
                'impact'        => $avg_ms,
                'last_ms'       => $last_ms,
                'samples'       => $count,
                'last_recorded' => $last_recorded,
            ];
        }

        if (!empty($plugin_entries)) {
            usort($plugin_entries, static function ($a, $b) {
                if ($a['impact'] === $b['impact']) {
                    return strcmp($a['label'], $b['label']);
                }

                return ($a['impact'] < $b['impact']) ? 1 : -1;
            });
        }

        $weights = [];
        $top_labels = [];
        $top_entries = array_slice($plugin_entries, 0, 5);

        foreach ($top_entries as $index => $entry) {
            $weight = null;

            if ($total_impact > 0) {
                $weight = ($entry['impact'] / $total_impact) * 100;
            }

            $weights[$index] = $weight !== null ? round($weight, 2) : null;
            $top_labels[$index] = $entry['label'];
        }

        $palette_cycle = [$palette['blue'], $palette['amber'], $palette['purple'], $palette['green'], $palette['red']];
        $dataset_colors = [];

        foreach ($top_entries as $i => $entry) {
            $dataset_colors[] = $palette_cycle[$i % count($palette_cycle)];
        }

        $plugins_chart = [
            'type'     => 'bar',
            'labels'   => array_values($top_labels),
            'datasets' => empty($top_entries) ? [] : [
                [
                    'data'            => array_values(array_map(static function ($value) {
                        return $value === null ? null : (float) $value;
                    }, $weights)),
                    'backgroundColor' => $dataset_colors,
                    'borderWidth'     => 0,
                ],
            ],
            'unit'     => __('%', 'sitepulse'),
            'empty'    => empty($top_entries),
            'status'   => 'status-ok',
            'options'  => [
                'indexAxis' => 'y',
            ],
            'meta'     => [
                'impacts' => array_values(array_map(static function ($entry) {
                    return isset($entry['impact']) ? (float) $entry['impact'] : null;
                }, $top_entries)),
            ],
        ];

        $threshold_defaults = function_exists('sitepulse_get_default_plugin_impact_thresholds')
            ? sitepulse_get_default_plugin_impact_thresholds()
            : [
                'impactWarning'  => 30.0,
                'impactCritical' => 60.0,
                'weightWarning'  => 10.0,
                'weightCritical' => 20.0,
                'trendWarning'   => 15.0,
                'trendCritical'  => 40.0,
            ];

        $thresholds = $threshold_defaults;

        if (defined('SITEPULSE_OPTION_IMPACT_THRESHOLDS')) {
            $stored_thresholds = get_option(
                SITEPULSE_OPTION_IMPACT_THRESHOLDS,
                [
                    'default' => $threshold_defaults,
                    'roles'   => [],
                ]
            );

            if (function_exists('sitepulse_sanitize_impact_thresholds')) {
                $stored_thresholds = sitepulse_sanitize_impact_thresholds($stored_thresholds);
            }

            if (is_array($stored_thresholds)) {
                $effective_thresholds = isset($stored_thresholds['default']) && is_array($stored_thresholds['default'])
                    ? $stored_thresholds['default']
                    : $threshold_defaults;

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

                if (function_exists('sitepulse_normalize_impact_threshold_set')) {
                    $thresholds = sitepulse_normalize_impact_threshold_set($effective_thresholds, $threshold_defaults);
                } else {
                    $thresholds = wp_parse_args(is_array($effective_thresholds) ? $effective_thresholds : [], $threshold_defaults);
                }
            }
        }

        $top_plugin = isset($plugin_entries[0]) ? $plugin_entries[0] : null;
        $top_weight = null;

        if ($top_plugin !== null && $total_impact > 0) {
            $top_weight = ($top_plugin['impact'] / $total_impact) * 100;
        }

        $plugins_status = 'status-ok';

        if ($top_plugin !== null) {
            if (($top_plugin['impact'] >= $thresholds['impactCritical']) || ($top_weight !== null && $top_weight >= $thresholds['weightCritical'])) {
                $plugins_status = 'status-bad';
            } elseif (($top_plugin['impact'] >= $thresholds['impactWarning']) || ($top_weight !== null && $top_weight >= $thresholds['weightWarning'])) {
                $plugins_status = 'status-warn';
            }
        } elseif (empty($plugin_entries)) {
            $plugins_status = 'status-warn';
        }

        $plugins_chart['status'] = $plugins_status;

        $last_updated = isset($measurements['last_updated']) ? (int) $measurements['last_updated'] : 0;
        $interval = isset($measurements['interval']) ? (int) $measurements['interval'] : 0;
        $interval_label = '';

        if (function_exists('sitepulse_plugin_impact_format_interval')) {
            $interval_label = sitepulse_plugin_impact_format_interval($interval);
        }

        $last_updated_label = '';

        if ($last_updated > 0) {
            $display_timestamp = $last_updated;

            if (function_exists('sitepulse_plugin_impact_normalize_timestamp_for_display')) {
                $display_timestamp = sitepulse_plugin_impact_normalize_timestamp_for_display($last_updated);
            }

            $last_updated_label = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $display_timestamp);
        }

        $plugins_card = [
            'status'         => $plugins_status,
            'top_plugin'     => $top_plugin,
            'top_weight'     => $top_weight,
            'total_impact'   => $total_impact,
            'entries'        => $plugin_entries,
            'measured_count' => count($plugin_entries),
            'interval'       => $interval_label,
            'last_updated'   => $last_updated_label,
        ];

        $charts_payload['plugins'] = $plugins_chart;
    }

    }

    $module_chart_keys = [
        'speed_analyzer'     => 'speed',
        'uptime_tracker'     => 'uptime',
        'database_optimizer' => 'database',
        'log_analyzer'       => 'logs',
        'resource_monitor'   => 'resource',
        'plugin_impact_scanner' => 'plugins',
    ];

    foreach ($module_chart_keys as $module_key => $chart_key) {
        if (!in_array($module_key, $active_modules, true)) {
            unset($charts_payload[$chart_key]);
        }
    }

    $charts_for_localization = empty($charts_payload) ? new stdClass() : $charts_payload;

    $localization_payload = [
        'charts'  => $charts_for_localization,
        'strings' => [
            'noData'              => __('Not enough data to render this chart yet.', 'sitepulse'),
            'uptimeTooltipUp'     => __('Site operational', 'sitepulse'),
            'uptimeTooltipDown'   => __('Site unavailable', 'sitepulse'),
            'uptimeAxisLabel'     => __('Availability (%)', 'sitepulse'),
            'speedTooltipLabel'   => __('Measured time', 'sitepulse'),
            'speedTrendLabel'     => __('Processing time', 'sitepulse'),
            'speedAxisLabel'      => __('Processing time (ms)', 'sitepulse'),
            'speedBudgetLabel'    => __('Performance budget', 'sitepulse'),
            'speedOverBudgetLabel'=> __('Over budget', 'sitepulse'),
            'revisionsTooltip'    => __('Revisions', 'sitepulse'),
            'logEventsLabel'      => __('Events', 'sitepulse'),
            'pluginsImpactLabel'  => __('Impact', 'sitepulse'),
            'pluginsShareLabel'   => __('Share', 'sitepulse'),
            'pluginsImpactUnit'   => __('ms', 'sitepulse'),
        ],
    ];

    if (wp_script_is('sitepulse-dashboard-charts', 'registered')) {
        wp_localize_script('sitepulse-dashboard-charts', 'SitePulseDashboardData', $localization_payload);
    }

    $current_page = isset($_GET['page']) ? sanitize_title((string) wp_unslash($_GET['page'])) : 'sitepulse-dashboard';

    if ($current_page === '') {
        $current_page = 'sitepulse-dashboard';
    }

    $module_navigation = function_exists('sitepulse_get_module_navigation_items')
        ? sitepulse_get_module_navigation_items($current_page)
        : [];

    $allowed_card_keys = sitepulse_get_dashboard_card_keys();
    $dashboard_preferences = sitepulse_get_dashboard_preferences(get_current_user_id(), $allowed_card_keys);
    $card_definitions = [
        'speed' => [
            'label'        => __('Speed', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_speed_enabled && $speed_card !== null),
            'content'      => '',
        ],
        'uptime' => [
            'label'        => __('Uptime', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_uptime_enabled && $uptime_card !== null),
            'content'      => '',
        ],
        'database' => [
            'label'        => __('Database Health', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_database_enabled && $database_card !== null),
            'content'      => '',
        ],
        'logs' => [
            'label'        => __('Error Log', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_logs_enabled && $logs_card !== null),
            'content'      => '',
        ],
        'resource' => [
            'label'        => __('Resources', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_resource_enabled && $resource_card !== null),
            'content'      => '',
        ],
        'plugins' => [
            'label'        => __('Plugin Impact', 'sitepulse'),
            'default_size' => 'medium',
            'available'    => ($is_plugins_enabled && $plugins_card !== null),
            'content'      => '',
        ],
    ];

    if (!empty($card_definitions['speed']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-performance"></span> <?php esc_html_e('Speed', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-speed')); ?>" class="button button-secondary"><?php esc_html_e('Details', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Backend PHP processing time captured during recent scans.', 'sitepulse'); ?></p>
        <?php
            $speed_summary_html = sitepulse_render_chart_summary('sitepulse-speed-chart', $speed_chart);
            $speed_summary_id = sitepulse_get_chart_summary_id('sitepulse-speed-chart');
            $speed_canvas_describedby = ['sitepulse-speed-description'];

            if ('' !== $speed_summary_html) {
                $speed_canvas_describedby[] = $speed_summary_id;
            }
        ?>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-speed-chart" aria-describedby="<?php echo esc_attr(implode(' ', $speed_canvas_describedby)); ?>"></canvas>
            <?php echo $speed_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php $speed_status_meta = $get_status_meta($speed_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($speed_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($speed_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($speed_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($speed_status_meta['sr']); ?></span>
            <span class="sitepulse-metric-value"><?php echo esc_html($speed_card['display']); ?></span>
        </p>
        <p id="sitepulse-speed-description" class="description"><?php printf(
            esc_html__('Des temps inférieurs à %1$d ms indiquent une excellente réponse PHP. Au-delà de %2$d ms, envisagez d’auditer vos plugins ou votre hébergement.', 'sitepulse'),
            (int) $speed_warning_threshold,
            (int) $speed_critical_threshold
        ); ?></p>
        <?php
        $card_definitions['speed']['content'] = ob_get_clean();
    }

    if (!empty($card_definitions['uptime']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e('Uptime', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-uptime')); ?>" class="button button-secondary"><?php esc_html_e('Details', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Availability for the last 30 hourly checks.', 'sitepulse'); ?></p>
        <?php
            $uptime_summary_html = sitepulse_render_chart_summary('sitepulse-uptime-chart', $uptime_chart);
            $uptime_summary_id = sitepulse_get_chart_summary_id('sitepulse-uptime-chart');
            $uptime_canvas_describedby = ['sitepulse-uptime-description'];

            if ('' !== $uptime_summary_html) {
                $uptime_canvas_describedby[] = $uptime_summary_id;
            }
        ?>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-uptime-chart" aria-describedby="<?php echo esc_attr(implode(' ', $uptime_canvas_describedby)); ?>"></canvas>
            <?php echo $uptime_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php $uptime_status_meta = $get_status_meta($uptime_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($uptime_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($uptime_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($uptime_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($uptime_status_meta['sr']); ?></span>
            <span class="sitepulse-metric-value"><?php echo esc_html(round($uptime_card['percentage'], 2)); ?><span class="sitepulse-metric-unit"><?php esc_html_e('%', 'sitepulse'); ?></span></span>
        </p>
        <p id="sitepulse-uptime-description" class="description"><?php esc_html_e('Each bar shows whether the site responded during the scheduled availability probe.', 'sitepulse'); ?></p>
        <?php
        $card_definitions['uptime']['content'] = ob_get_clean();
    }

    if (!empty($card_definitions['database']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e('Database Health', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-db')); ?>" class="button button-secondary"><?php esc_html_e('Optimize', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Post revision volume compared to the recommended limit.', 'sitepulse'); ?></p>
        <?php
            $database_summary_html = sitepulse_render_chart_summary('sitepulse-database-chart', $database_chart);
            $database_summary_id = sitepulse_get_chart_summary_id('sitepulse-database-chart');
            $database_canvas_describedby = ['sitepulse-database-description'];

            if ('' !== $database_summary_html) {
                $database_canvas_describedby[] = $database_summary_id;
            }
        ?>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-database-chart" aria-describedby="<?php echo esc_attr(implode(' ', $database_canvas_describedby)); ?>"></canvas>
            <?php echo $database_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php $database_status_meta = $get_status_meta($database_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($database_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($database_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($database_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($database_status_meta['sr']); ?></span>
            <span class="sitepulse-metric-value">
                <?php echo esc_html(number_format_i18n($database_card['revisions'])); ?>
                <span class="sitepulse-metric-unit"><?php esc_html_e('revisions', 'sitepulse'); ?></span>
            </span>
        </p>
        <p id="sitepulse-database-description" class="description"><?php printf(esc_html__('Keep revisions under %d to avoid bloating the posts table. Cleaning them is safe and reversible with backups.', 'sitepulse'), (int) $database_card['limit']); ?></p>
        <?php
        $card_definitions['database']['content'] = ob_get_clean();
    }

    if (!empty($card_definitions['logs']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-hammer"></span> <?php esc_html_e('Error Log', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-logs')); ?>" class="button button-secondary"><?php esc_html_e('Analyze', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Breakdown of the most recent entries in the WordPress debug log.', 'sitepulse'); ?></p>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-log-chart" aria-describedby="sitepulse-log-description"></canvas>
        </div>
        <?php $logs_status_meta = $get_status_meta($logs_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($logs_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($logs_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($logs_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($logs_status_meta['sr']); ?></span>
            <span class="sitepulse-metric-value"><?php echo esc_html($logs_card['summary']); ?></span>
        </p>
        <ul class="sitepulse-legend">
            <li>
                <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['red']); ?>;"></span><?php esc_html_e('Fatal errors', 'sitepulse'); ?></span>
                <span class="value"><?php echo esc_html(number_format_i18n($logs_card['counts']['fatal'])); ?></span>
            </li>
            <li>
                <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['amber']); ?>;"></span><?php esc_html_e('Warnings', 'sitepulse'); ?></span>
                <span class="value"><?php echo esc_html(number_format_i18n($logs_card['counts']['warning'])); ?></span>
            </li>
            <li>
                <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['blue']); ?>;"></span><?php esc_html_e('Notices', 'sitepulse'); ?></span>
                <span class="value"><?php echo esc_html(number_format_i18n($logs_card['counts']['notice'])); ?></span>
            </li>
            <li>
                <span class="label"><span class="badge" style="background-color: <?php echo esc_attr($palette['purple']); ?>;"></span><?php esc_html_e('Deprecated notices', 'sitepulse'); ?></span>
                <span class="value"><?php echo esc_html(number_format_i18n($logs_card['counts']['deprecated'])); ?></span>
            </li>
        </ul>
        <p id="sitepulse-log-description" class="description"><?php esc_html_e('Use the analyzer to inspect full stack traces and silence recurring issues.', 'sitepulse'); ?></p>
        <?php
        $card_definitions['logs']['content'] = ob_get_clean();
    }

    if (!empty($card_definitions['resource']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-chart-area"></span> <?php esc_html_e('Resources', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-resources')); ?>" class="button button-secondary"><?php esc_html_e('Details', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Server load, memory, and disk headroom at a glance.', 'sitepulse'); ?></p>
        <?php
            $resource_summary_html = sitepulse_render_chart_summary('sitepulse-resource-chart', $resource_chart);
            $resource_summary_id = sitepulse_get_chart_summary_id('sitepulse-resource-chart');
            $resource_canvas_describedby = ['sitepulse-resource-description'];

            if ('' !== $resource_summary_html) {
                $resource_canvas_describedby[] = $resource_summary_id;
            }
        ?>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-resource-chart" aria-describedby="<?php echo esc_attr(implode(' ', $resource_canvas_describedby)); ?>"></canvas>
            <?php echo $resource_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php $resource_status_meta = $get_status_meta($resource_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($resource_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($resource_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($resource_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($resource_status_meta['sr']); ?></span>
            <span class="sitepulse-metric-value"><?php echo esc_html($resource_card['load_display']); ?></span>
            <span class="sitepulse-metric-unit"><?php esc_html_e('CPU (1/5/15)', 'sitepulse'); ?></span>
        </p>
        <ul class="sitepulse-legend">
            <li>
                <span class="label"><?php esc_html_e('Memory', 'sitepulse'); ?></span>
                <span class="value">
                    <?php echo esc_html($resource_card['memory_usage']); ?>
                    <?php if ($resource_card['memory_limit'] !== '') : ?>
                        <span class="sitepulse-metric-unit"><?php printf(esc_html__('of %s limit', 'sitepulse'), esc_html($resource_card['memory_limit'])); ?></span>
                    <?php endif; ?>
                    <?php if ($resource_card['memory_percent'] !== null) : ?>
                        <span class="sitepulse-metric-unit"><?php printf(esc_html__('(%s%% used)', 'sitepulse'), esc_html(number_format_i18n($resource_card['memory_percent'], 0))); ?></span>
                    <?php endif; ?>
                </span>
            </li>
            <li>
                <span class="label"><?php esc_html_e('Disk free', 'sitepulse'); ?></span>
                <span class="value">
                    <?php echo esc_html($resource_card['disk_free']); ?>
                    <?php if ($resource_card['disk_total'] !== '') : ?>
                        <span class="sitepulse-metric-unit"><?php printf(esc_html__('of %s total', 'sitepulse'), esc_html($resource_card['disk_total'])); ?></span>
                    <?php endif; ?>
                    <?php if ($resource_card['disk_free_percent'] !== null) : ?>
                        <span class="sitepulse-metric-unit"><?php printf(esc_html__('(%s%% free)', 'sitepulse'), esc_html(number_format_i18n($resource_card['disk_free_percent'], 0))); ?></span>
                    <?php endif; ?>
                </span>
            </li>
        </ul>
        <p id="sitepulse-resource-description" class="description">
            <?php
            if (!empty($resource_card['generated_at'])) {
                printf(
                    esc_html__('Snapshot generated on %s.', 'sitepulse'),
                    esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $resource_card['generated_at']))
                );
            } else {
                esc_html_e('Snapshot timing unavailable.', 'sitepulse');
            }
            ?>
        </p>
        <?php
        $card_definitions['resource']['content'] = ob_get_clean();
    }

    if (!empty($card_definitions['plugins']['available'])) {
        ob_start();
        ?>
        <div class="sitepulse-card-header">
            <h2><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e('Plugin Impact', 'sitepulse'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-plugins')); ?>" class="button button-secondary"><?php esc_html_e('Inspect', 'sitepulse'); ?></a>
        </div>
        <p class="sitepulse-card-subtitle"><?php esc_html_e('Average load time added by the most expensive plugins.', 'sitepulse'); ?></p>
        <?php
            $plugins_summary_html = sitepulse_render_chart_summary('sitepulse-plugins-chart', $plugins_chart);
            $plugins_summary_id = sitepulse_get_chart_summary_id('sitepulse-plugins-chart');
            $plugins_canvas_describedby = ['sitepulse-plugins-description'];

            if ('' !== $plugins_summary_html) {
                $plugins_canvas_describedby[] = $plugins_summary_id;
            }
        ?>
        <div class="sitepulse-chart-container">
            <canvas id="sitepulse-plugins-chart" aria-describedby="<?php echo esc_attr(implode(' ', $plugins_canvas_describedby)); ?>"></canvas>
            <?php echo $plugins_summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php $plugins_status_meta = $get_status_meta($plugins_card['status']); ?>
        <p class="sitepulse-metric">
            <span class="status-badge <?php echo esc_attr($plugins_card['status']); ?>" aria-hidden="true">
                <span class="status-icon"><?php echo esc_html($plugins_status_meta['icon']); ?></span>
                <span class="status-text"><?php echo esc_html($plugins_status_meta['label']); ?></span>
            </span>
            <span class="screen-reader-text"><?php echo esc_html($plugins_status_meta['sr']); ?></span>
            <?php if (!empty($plugins_card['top_plugin'])) : ?>
                <span class="sitepulse-metric-value"><?php echo esc_html(number_format_i18n($plugins_card['top_plugin']['impact'], 2)); ?><span class="sitepulse-metric-unit"><?php esc_html_e('ms', 'sitepulse'); ?></span></span>
                <span class="sitepulse-metric-unit"><?php printf(esc_html__('Top: %s', 'sitepulse'), esc_html($plugins_card['top_plugin']['label'])); ?></span>
                <?php if ($plugins_card['top_weight'] !== null) : ?>
                    <span class="sitepulse-metric-unit"><?php printf(esc_html__('(%s%% share)', 'sitepulse'), esc_html(number_format_i18n($plugins_card['top_weight'], 1))); ?></span>
                <?php endif; ?>
            <?php else : ?>
                <span class="sitepulse-metric-value"><?php esc_html_e('No measurements yet', 'sitepulse'); ?></span>
            <?php endif; ?>
        </p>
        <?php $top_display_entries = array_slice($plugins_card['entries'], 0, 3); ?>
        <?php if (!empty($top_display_entries)) : ?>
            <ul class="sitepulse-legend">
                <?php foreach ($top_display_entries as $entry) :
                    $share = ($plugins_card['total_impact'] > 0)
                        ? ($entry['impact'] / $plugins_card['total_impact']) * 100
                        : null;
                ?>
                    <li>
                        <span class="label"><?php echo esc_html($entry['label']); ?></span>
                        <span class="value">
                            <?php echo esc_html(number_format_i18n($entry['impact'], 2)); ?>
                            <span class="sitepulse-metric-unit"><?php esc_html_e('ms', 'sitepulse'); ?></span>
                            <?php if ($share !== null) : ?>
                                <span class="sitepulse-metric-unit"><?php printf(esc_html__('(%s%% share)', 'sitepulse'), esc_html(number_format_i18n($share, 1))); ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p id="sitepulse-plugins-description" class="description">
            <?php if (!empty($plugins_card['last_updated'])) : ?>
                <?php if ($plugins_card['interval'] !== '') : ?>
                    <?php printf(esc_html__('Sampled %1$s (refresh interval: %2$s).', 'sitepulse'), esc_html($plugins_card['last_updated']), esc_html($plugins_card['interval'])); ?>
                <?php else : ?>
                    <?php printf(esc_html__('Sampled %s.', 'sitepulse'), esc_html($plugins_card['last_updated'])); ?>
                <?php endif; ?>
            <?php elseif ($plugins_card['interval'] !== '') : ?>
                <?php printf(esc_html__('Next measurement expected every %s.', 'sitepulse'), esc_html($plugins_card['interval'])); ?>
            <?php else : ?>
                <?php esc_html_e('Measurements will appear after the scanner collects data.', 'sitepulse'); ?>
            <?php endif; ?>
        </p>
        <?php
        $card_definitions['plugins']['content'] = ob_get_clean();
    }

    $render_order = array_values(array_unique(array_merge(
        isset($dashboard_preferences['order']) && is_array($dashboard_preferences['order']) ? $dashboard_preferences['order'] : [],
        array_keys($card_definitions)
    )));

    $rendered_cards = [];
    $preferences_panel_items = [];
    $cards_for_localization = [];
    $visible_cards_count = 0;
    $allowed_sizes = ['small', 'medium', 'large'];

    foreach ($render_order as $card_key) {
        if (!isset($card_definitions[$card_key])) {
            continue;
        }

        $definition = $card_definitions[$card_key];
        $is_available = !empty($definition['available']);
        $size = isset($dashboard_preferences['sizes'][$card_key]) ? strtolower((string) $dashboard_preferences['sizes'][$card_key]) : $definition['default_size'];

        if (!in_array($size, $allowed_sizes, true)) {
            $size = $definition['default_size'];
        }

        $is_visible = isset($dashboard_preferences['visibility'][$card_key])
            ? (bool) $dashboard_preferences['visibility'][$card_key]
            : true;

        if (!$is_available) {
            $is_visible = false;
        }

        $should_render = $is_available && $definition['content'] !== '';

        if ($should_render && $is_visible) {
            $visible_cards_count++;
        }

        $rendered_cards[$card_key] = [
            'key'           => $card_key,
            'content'       => $definition['content'],
            'size'          => $size,
            'visible'       => $is_visible,
            'should_render' => $should_render,
            'available'     => $is_available,
            'label'         => $definition['label'],
        ];

        $preferences_panel_items[$card_key] = [
            'label'     => $definition['label'],
            'available' => $is_available,
            'visible'   => $is_visible,
            'size'      => $size,
        ];

        $cards_for_localization[$card_key] = [
            'label'       => $definition['label'],
            'available'   => $is_available,
            'defaultSize' => $definition['default_size'],
        ];
    }

    $theme_options = sitepulse_get_dashboard_theme_options();
    $theme_labels = [];
    $theme_choices = [];
    $current_theme = isset($dashboard_preferences['theme'])
        ? sitepulse_normalize_dashboard_theme($dashboard_preferences['theme'])
        : sitepulse_get_dashboard_default_theme();

    foreach ($theme_options as $theme_key => $theme_definition) {
        $theme_choices[] = $theme_key;
        $theme_labels[$theme_key] = isset($theme_definition['label'])
            ? wp_strip_all_tags((string) $theme_definition['label'])
            : $theme_key;
    }

    if (wp_script_is('sitepulse-dashboard-preferences', 'registered')) {
        wp_localize_script('sitepulse-dashboard-preferences', 'SitePulsePreferencesData', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('sitepulse_dashboard_preferences'),
            'preferences'  => $dashboard_preferences,
            'cards'        => $cards_for_localization,
            'sizes'        => [
                'small'  => __('Compacte', 'sitepulse'),
                'medium' => __('Standard', 'sitepulse'),
                'large'  => __('Étendue', 'sitepulse'),
            ],
            'themeOptions' => $theme_choices,
            'themeLabels'  => $theme_labels,
            'defaultTheme' => sitepulse_get_dashboard_default_theme(),
            'strings'      => [
                'panelDescription' => __('Réorganisez les cartes en les faisant glisser et choisissez celles à afficher.', 'sitepulse'),
                'toggleLabel'      => __('Afficher', 'sitepulse'),
                'sizeLabel'        => __('Taille', 'sitepulse'),
                'saveSuccess'      => __('Préférences enregistrées.', 'sitepulse'),
                'saveError'        => __('Impossible d’enregistrer les préférences.', 'sitepulse'),
                'moduleDisabled'   => __('Module requis pour afficher cette carte.', 'sitepulse'),
                'changesSaved'     => __('Les préférences du tableau de bord ont été mises à jour.', 'sitepulse'),
                'themeAnnouncement'=> __('Apparence définie sur %s.', 'sitepulse'),
                'themeSpoken'      => __('Thème mis à jour sur %s.', 'sitepulse'),
            ],
        ]);
        wp_enqueue_script('sitepulse-dashboard-preferences');
    }

    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-dashboard"></span> <?php esc_html_e('SitePulse Dashboard', 'sitepulse'); ?></h1>
        <p><?php esc_html_e("A real-time overview of your site's performance and health.", 'sitepulse'); ?></p>

        <?php if (!empty($module_navigation)) : ?>
            <?php sitepulse_render_module_navigation($current_page, $module_navigation); ?>
        <?php endif; ?>

        <?php
        $banner_cta_label = isset($banner_cta['label']) ? $banner_cta['label'] : '';
        $banner_cta_url   = isset($banner_cta['url']) ? $banner_cta['url'] : '';
        $banner_cta_data  = isset($banner_cta['data']) ? $banner_cta['data'] : '';
        ?>

        <div class="sitepulse-overview" data-sitepulse-metrics data-loading="false" aria-busy="false">
            <div class="sitepulse-overview__controls">
                <fieldset class="sitepulse-range-picker" data-sitepulse-range>
                    <legend><?php esc_html_e('Select timeframe', 'sitepulse'); ?></legend>
                    <div class="sitepulse-range-picker__options">
                        <?php foreach ($range_options as $option) :
                            $option_id = isset($option['id']) ? sanitize_key($option['id']) : '';
                            if ($option_id === '') {
                                continue;
                            }
                            $option_label = isset($option['label']) && is_string($option['label']) ? $option['label'] : $option_id;
                            $input_id = 'sitepulse-metrics-range-' . $option_id;
                        ?>
                            <label class="sitepulse-range-picker__option<?php echo ($option_id === $current_range) ? ' is-selected' : ''; ?>" for="<?php echo esc_attr($input_id); ?>">
                                <input type="radio" id="<?php echo esc_attr($input_id); ?>" name="sitepulse-metrics-range" value="<?php echo esc_attr($option_id); ?>" <?php checked($option_id === $current_range); ?> data-sitepulse-range-option />
                                <span><?php echo esc_html($option_label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="sitepulse-range-picker__select">
                        <span class="screen-reader-text"><?php esc_html_e('Select timeframe', 'sitepulse'); ?></span>
                        <select data-sitepulse-range-select>
                            <?php foreach ($range_options as $option) :
                                $option_id = isset($option['id']) ? sanitize_key($option['id']) : '';
                                if ($option_id === '') {
                                    continue;
                                }
                                $option_label = isset($option['label']) && is_string($option['label']) ? $option['label'] : $option_id;
                            ?>
                                <option value="<?php echo esc_attr($option_id); ?>" <?php selected($option_id, $current_range); ?>><?php echo esc_html($option_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </fieldset>
                <div class="sitepulse-overview__info">
                    <div class="sitepulse-overview__meta">
                        <p class="sitepulse-overview__range">
                            <span class="sitepulse-overview__meta-label"><?php esc_html_e('Active window:', 'sitepulse'); ?></span>
                            <span data-sitepulse-range-label><?php echo esc_html($range_label); ?></span>
                        </p>
                        <p class="sitepulse-overview__generated" data-sitepulse-generated><?php echo esc_html($generated_text); ?></p>
                    </div>
                    <?php echo sitepulse_render_dashboard_theme_toggle($current_theme, $theme_options); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>

            <div class="sitepulse-status-banner sitepulse-status-banner--<?php echo esc_attr($banner_tone); ?>" data-sitepulse-banner role="status" aria-live="polite">
                <div class="sitepulse-status-banner__content">
                    <span class="sitepulse-status-banner__icon" aria-hidden="true" data-sitepulse-banner-icon><?php echo esc_html($banner_icon); ?></span>
                    <p class="sitepulse-status-banner__message" data-sitepulse-banner-message><?php echo esc_html($banner_message); ?></p>
                    <span class="screen-reader-text" data-sitepulse-banner-sr><?php echo esc_html($banner_sr); ?></span>
                </div>
                <?php if ($banner_cta_label !== '' && $banner_cta_url !== '') : ?>
                    <a href="<?php echo esc_url($banner_cta_url); ?>" class="button button-primary sitepulse-status-banner__cta" data-sitepulse-banner-cta<?php echo $banner_cta_data !== '' ? ' data-cta="' . esc_attr($banner_cta_data) . '"' : ''; ?>><?php echo esc_html($banner_cta_label); ?></a>
                <?php else : ?>
                    <span class="sitepulse-status-banner__cta" data-sitepulse-banner-cta hidden></span>
                <?php endif; ?>
            </div>

            <div class="sitepulse-kpi-grid" data-sitepulse-metrics-grid>
                <?php foreach ($metrics_cards as $card_key => $card_data) : ?>
                    <?php echo sitepulse_render_dashboard_metric_card($card_key, $card_data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endforeach; ?>
            </div>

            <div class="sitepulse-metrics__error notice notice-error" role="alert" hidden data-sitepulse-metrics-error></div>
            <span class="screen-reader-text" aria-live="polite" data-sitepulse-metrics-announcer></span>
        </div>

        <div class="sitepulse-dashboard-preferences">
            <button type="button" class="button button-secondary sitepulse-preferences__toggle" aria-expanded="false" aria-controls="sitepulse-preferences-panel">
                <?php esc_html_e('Personnaliser l\'affichage', 'sitepulse'); ?>
            </button>
            <div id="sitepulse-preferences-panel" class="sitepulse-preferences__panel" hidden tabindex="-1">
                <p class="sitepulse-preferences__description"><?php esc_html_e('Réorganisez les cartes en les faisant glisser et choisissez celles à afficher.', 'sitepulse'); ?></p>
                <ul class="sitepulse-preferences__list" data-sitepulse-preferences-list>
                    <?php foreach ($render_order as $card_key) :
                        if (!isset($preferences_panel_items[$card_key])) {
                            continue;
                        }

                        $item = $preferences_panel_items[$card_key];
                    ?>
                        <li class="sitepulse-preferences__item<?php echo !$item['available'] ? ' is-disabled' : ''; ?>" data-card-key="<?php echo esc_attr($card_key); ?>" data-card-enabled="<?php echo $item['available'] ? '1' : '0'; ?>">
                            <span class="sitepulse-preferences__drag-handle" aria-hidden="true"></span>
                            <div class="sitepulse-preferences__details">
                                <span class="sitepulse-preferences__label"><?php echo esc_html($item['label']); ?></span>
                                <?php if (!$item['available']) : ?>
                                    <span class="sitepulse-preferences__status"><?php esc_html_e('Module requis pour afficher cette carte.', 'sitepulse'); ?></span>
                                <?php endif; ?>
                                <div class="sitepulse-preferences__controls">
                                    <label class="sitepulse-preferences__control">
                                        <input type="checkbox" class="sitepulse-preferences__visibility" <?php checked(!empty($item['visible'])); ?> <?php disabled(!$item['available']); ?> />
                                        <span><?php esc_html_e('Afficher', 'sitepulse'); ?></span>
                                    </label>
                                    <label class="sitepulse-preferences__control sitepulse-preferences__control--size">
                                        <span class="sitepulse-preferences__control-label"><?php esc_html_e('Taille', 'sitepulse'); ?></span>
                                        <span class="screen-reader-text"><?php printf(esc_html__('Taille de la carte %s', 'sitepulse'), $item['label']); ?></span>
                                        <select class="sitepulse-preferences__size" <?php disabled(!$item['available']); ?>>
                                            <option value="small" <?php selected($item['size'], 'small'); ?>><?php esc_html_e('Compacte', 'sitepulse'); ?></option>
                                            <option value="medium" <?php selected($item['size'], 'medium'); ?>><?php esc_html_e('Standard', 'sitepulse'); ?></option>
                                            <option value="large" <?php selected($item['size'], 'large'); ?>><?php esc_html_e('Étendue', 'sitepulse'); ?></option>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="sitepulse-preferences__notice is-hidden" role="status" aria-live="polite"></div>
                <div class="sitepulse-preferences__actions">
                    <button type="button" class="button button-primary sitepulse-preferences__save"><?php esc_html_e('Enregistrer', 'sitepulse'); ?></button>
                    <button type="button" class="button sitepulse-preferences__cancel"><?php esc_html_e('Annuler', 'sitepulse'); ?></button>
                </div>
            </div>
        </div>

        <div class="sitepulse-grid" data-sitepulse-card-grid>
            <?php foreach ($render_order as $card_key) :
                if (!isset($rendered_cards[$card_key])) {
                    continue;
                }

                $card = $rendered_cards[$card_key];

                if (!$card['should_render']) {
                    continue;
                }

                $card_classes = ['sitepulse-card', 'sitepulse-card--' . $card['size']];

                if (!$card['visible']) {
                    $card_classes[] = 'sitepulse-card--is-hidden';
                }
            ?>
                <div class="<?php echo esc_attr(implode(' ', $card_classes)); ?>"
                    data-card-key="<?php echo esc_attr($card['key']); ?>"
                    data-card-size="<?php echo esc_attr($card['size']); ?>"
                    data-card-enabled="<?php echo $card['available'] ? '1' : '0'; ?>"<?php if (!$card['visible']) { echo ' hidden aria-hidden="true"'; } ?>>
                    <?php echo $card['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="sitepulse-empty-state" data-sitepulse-empty-state <?php echo ($visible_cards_count === 0) ? '' : 'hidden'; ?>>
            <h2><?php esc_html_e('Votre tableau de bord est vide', 'sitepulse'); ?></h2>
            <p><?php esc_html_e('Utilisez le bouton « Personnaliser l’affichage » pour sélectionner des cartes.', 'sitepulse'); ?></p>
        </div>
    </div>
    <?php
}
