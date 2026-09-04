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

require_once __DIR__ . '/dashboard/api.php';

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
        ['wp-i18n'],
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

/**
 * Returns the identifiers of the dashboard cards that can be customised.
 *
 * @return string[]
 */
function sitepulse_get_dashboard_card_keys() {
    return ['speed', 'experience', 'uptime', 'database', 'logs', 'resource', 'plugins'];
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

    $rum_enabled_flag = isset($speed['rum_enabled']) ? (bool) $speed['rum_enabled'] : false;
    $rum_data = isset($speed['rum']) && is_array($speed['rum']) ? $speed['rum'] : null;
    $rum_detail_rows = [];

    if ($rum_enabled_flag && is_array($rum_data)) {
        $rum_samples = isset($rum_data['sample_count']) ? (int) $rum_data['sample_count'] : 0;
        $rum_summary = isset($rum_data['summary']) && is_array($rum_data['summary']) ? $rum_data['summary'] : [];
        $rum_lcp = isset($rum_summary['LCP']['p75']) ? (float) $rum_summary['LCP']['p75'] : null;
        $rum_fid = isset($rum_summary['FID']['p75']) ? (float) $rum_summary['FID']['p75'] : null;
        $rum_cls = isset($rum_summary['CLS']['p75']) ? (float) $rum_summary['CLS']['p75'] : null;

        if ($rum_samples > 0) {
            $summary_parts[] = sprintf(
                /* translators: %s: number of RUM samples. */
                _n('%s RUM sample', '%s RUM samples', $rum_samples, 'sitepulse'),
                number_format_i18n($rum_samples)
            );
        }

        $rum_detail_rows[] = [
            'label' => __('RUM LCP p75', 'sitepulse'),
            'value' => ($rum_lcp !== null)
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n($rum_lcp, 0))
                : __('N/A', 'sitepulse'),
        ];
        $rum_detail_rows[] = [
            'label' => __('RUM FID p75', 'sitepulse'),
            'value' => ($rum_fid !== null)
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n($rum_fid, 0))
                : __('N/A', 'sitepulse'),
        ];
        $rum_detail_rows[] = [
            'label' => __('RUM CLS p75', 'sitepulse'),
            'value' => ($rum_cls !== null)
                ? number_format_i18n($rum_cls, 3)
                : __('N/A', 'sitepulse'),
        ];
    } elseif ($rum_enabled_flag) {
        $rum_detail_rows[] = [
            'label' => __('RUM', 'sitepulse'),
            'value' => __('No RUM samples recorded for this period.', 'sitepulse'),
        ];
    } else {
        $rum_detail_rows[] = [
            'label' => __('RUM', 'sitepulse'),
            'value' => __('Collection disabled', 'sitepulse'),
        ];
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

    if (!empty($rum_detail_rows)) {
        $card['details'] = array_merge($card['details'], $rum_detail_rows);
    }

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
 * Formats the Real User Monitoring card for the dashboard.
 *
 * @param array<string,mixed>|null $rum          Aggregated RUM metrics.
 * @param bool                     $module_active Whether the Speed module is active.
 * @param bool                     $rum_enabled   Whether RUM collection is currently enabled.
 * @param string                   $range_label   Human readable range label.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_rum_card_view($rum, $module_active, $rum_enabled, $range_label) {
    $status_meta = sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $card = [
        'label'             => __('Real user experience', 'sitepulse'),
        'status'            => array_merge($status_meta, ['class' => 'status-warn']),
        'value'             => ['text' => __('N/A', 'sitepulse'), 'unit' => ''],
        'summary'           => __('Waiting for field data.', 'sitepulse'),
        'trend'             => sitepulse_custom_dashboard_format_trend(null),
        'details'           => [],
        'description'       => __('Activate Web Vitals collection to populate this metric.', 'sitepulse'),
        'inactive'          => !$module_active,
        'inactive_message'  => __('Activate the Speed Analyzer module to unlock RUM insights.', 'sitepulse'),
    ];

    if ($card['inactive']) {
        return $card;
    }

    if (!$rum_enabled) {
        $idle_status = sitepulse_custom_dashboard_resolve_status_meta('status-idle');
        $idle_status['class'] = 'status-idle';
        $card['status'] = $idle_status;
        $card['summary'] = __('Real user monitoring is disabled.', 'sitepulse');
        $card['description'] = __('Enable RUM collection from the Speed Analyzer settings.', 'sitepulse');

        return $card;
    }

    if (!is_array($rum)) {
        $rum = [];
    }

    $samples = isset($rum['window']['samples']) ? (int) $rum['window']['samples'] : 0;
    $metrics = isset($rum['metrics']) && is_array($rum['metrics']) ? $rum['metrics'] : [];
    $pages   = isset($rum['pages']) && is_array($rum['pages']) ? $rum['pages'] : [];

    if ($samples <= 0 || empty($metrics)) {
        $card['summary'] = __('No RUM samples collected for the selected range.', 'sitepulse');
        $card['description'] = __('Once visitors interact with the site, Web Vitals will appear here.', 'sitepulse');

        return $card;
    }

    $labels = [
        'LCP' => __('Largest Contentful Paint', 'sitepulse'),
        'FID' => __('First Input Delay', 'sitepulse'),
        'CLS' => __('Cumulative Layout Shift', 'sitepulse'),
    ];

    $primary_key = isset($metrics['LCP']) ? 'LCP' : (key($metrics) ?: 'LCP');
    $primary = isset($metrics[$primary_key]) && is_array($metrics[$primary_key]) ? $metrics[$primary_key] : [];

    $extract_value = static function ($metric_key, $metric_data, $field) {
        if (!is_array($metric_data)) {
            return null;
        }

        if (isset($metric_data[$field]) && is_numeric($metric_data[$field])) {
            return (float) $metric_data[$field];
        }

        if ($field !== 'average' && isset($metric_data['average']) && is_numeric($metric_data['average'])) {
            return (float) $metric_data['average'];
        }

        return null;
    };

    $format_value = static function ($metric_key, $value) {
        $unit = '';
        $precision = 2;

        if ($value === null) {
            return ['formatted' => __('N/A', 'sitepulse'), 'unit' => '', 'raw' => null];
        }

        if ($metric_key === 'LCP') {
            $unit = 's';
            $value = $value / 1000;
            $precision = 2;
        } elseif ($metric_key === 'FID') {
            $unit = 'ms';
            $precision = $value >= 100 ? 0 : 1;
        } elseif ($metric_key === 'CLS') {
            $unit = '';
            $precision = 3;
        }

        return [
            'formatted' => number_format_i18n($value, $precision),
            'unit'      => $unit,
            'raw'       => $value,
        ];
    };

    $primary_value = $extract_value($primary_key, $primary, 'p75');
    $value_meta = $format_value($primary_key, $primary_value);

    $ratings = isset($primary['ratings']) && is_array($primary['ratings']) ? $primary['ratings'] : [];
    $good_count  = isset($ratings['good']) ? (int) $ratings['good'] : 0;
    $ni_count    = isset($ratings['needs_improvement']) ? (int) $ratings['needs_improvement'] : 0;
    $poor_count  = isset($ratings['poor']) ? (int) $ratings['poor'] : 0;
    $rating_total = max(1, $good_count + $ni_count + $poor_count);

    $good_ratio = $rating_total > 0 ? $good_count / $rating_total : 0.0;
    $ni_ratio   = $rating_total > 0 ? $ni_count / $rating_total : 0.0;
    $poor_ratio = $rating_total > 0 ? $poor_count / $rating_total : 0.0;

    if ($poor_ratio >= 0.3) {
        $status_key = 'status-bad';
    } elseif ($good_ratio < 0.5 || $ni_ratio >= 0.3) {
        $status_key = 'status-warn';
    } else {
        $status_key = 'status-ok';
    }

    $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status_key);
    $status_meta['class'] = $status_key;
    $card['status'] = $status_meta;

    $metric_label = isset($labels[$primary_key]) ? $labels[$primary_key] : $primary_key;
    $card['value'] = [
        'text' => $value_meta['formatted'],
        'unit' => $value_meta['unit'],
    ];

    $card['summary'] = sprintf(
        __('p75 %1$s: %2$s%3$s · %4$s%% good', 'sitepulse'),
        $metric_label,
        $value_meta['formatted'],
        $value_meta['unit'] !== '' ? ' ' . $value_meta['unit'] : '',
        number_format_i18n(round($good_ratio * 100))
    );

    $card['description'] = sprintf(
        __('Based on %1$s samples collected over %2$s.', 'sitepulse'),
        number_format_i18n($samples),
        $range_label
    );

    $details = [];

    foreach (['LCP', 'FID', 'CLS'] as $metric_key) {
        if (!isset($metrics[$metric_key]) || !is_array($metrics[$metric_key])) {
            continue;
        }

        $metric_data = $metrics[$metric_key];
        $p95_value = $extract_value($metric_key, $metric_data, 'p95');
        $detail_value = $format_value($metric_key, $p95_value);
        $metric_ratings = isset($metric_data['ratings']) && is_array($metric_data['ratings']) ? $metric_data['ratings'] : [];
        $metric_good = isset($metric_ratings['good']) ? (int) $metric_ratings['good'] : 0;
        $metric_total = max(1, $metric_good + (isset($metric_ratings['needs_improvement']) ? (int) $metric_ratings['needs_improvement'] : 0) + (isset($metric_ratings['poor']) ? (int) $metric_ratings['poor'] : 0));

        $details[] = [
            'label' => isset($labels[$metric_key]) ? $labels[$metric_key] : $metric_key,
            'value' => sprintf(
                __('p95 %1$s%2$s · %3$s%% good', 'sitepulse'),
                $detail_value['formatted'],
                $detail_value['unit'] !== '' ? ' ' . $detail_value['unit'] : '',
                number_format_i18n(round(($metric_good / $metric_total) * 100))
            ),
        ];
    }

    if (!empty($pages)) {
        $top_page = $pages[0];
        $page_path = isset($top_page['path']) ? (string) $top_page['path'] : '/';
        $page_samples = isset($top_page['samples']) ? (int) $top_page['samples'] : 0;

        $details[] = [
            'label' => __('Top sampled page', 'sitepulse'),
            'value' => sprintf(
                '%1$s · %2$s',
                $page_path,
                sprintf(_n('%s sample', '%s samples', $page_samples, 'sitepulse'), number_format_i18n($page_samples))
            ),
        ];
    }

    $card['details'] = $details;

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
function sitepulse_custom_dashboard_get_debt_history_option_name() {
    if (defined('SITEPULSE_OPTION_DASHBOARD_DEBT_HISTORY')) {
        return SITEPULSE_OPTION_DASHBOARD_DEBT_HISTORY;
    }

    return 'sitepulse_dashboard_debt_history';
}

function sitepulse_custom_dashboard_get_debt_history($max_points = 14) {
    $option_name = sitepulse_custom_dashboard_get_debt_history_option_name();
    $history = get_option($option_name, []);

    if (!is_array($history)) {
        return [];
    }

    $normalized = [];

    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $score     = isset($entry['score']) ? (float) $entry['score'] : null;

        if ($timestamp <= 0 || null === $score || $score < 0) {
            continue;
        }

        $normalized[] = [
            'timestamp' => $timestamp,
            'score'     => round($score, 2),
        ];
    }

    if (empty($normalized)) {
        return [];
    }

    usort($normalized, static function ($a, $b) {
        return $a['timestamp'] <=> $b['timestamp'];
    });

    if ($max_points > 0 && count($normalized) > $max_points) {
        $normalized = array_slice($normalized, -$max_points);
    }

    return array_values($normalized);
}

function sitepulse_custom_dashboard_store_debt_sample($score, $timestamp = null, $max_points = 28) {
    if (!is_numeric($score)) {
        return sitepulse_custom_dashboard_get_debt_history($max_points);
    }

    $timestamp = null === $timestamp ? sitepulse_custom_dashboard_get_current_timestamp() : (int) $timestamp;
    $score     = max(0.0, (float) $score);

    if ($timestamp <= 0) {
        $timestamp = sitepulse_custom_dashboard_get_current_timestamp();
    }

    $history = sitepulse_custom_dashboard_get_debt_history($max_points * 2);

    $history[] = [
        'timestamp' => $timestamp,
        'score'     => round($score, 2),
    ];

    usort($history, static function ($a, $b) {
        return $a['timestamp'] <=> $b['timestamp'];
    });

    $merged = [];
    $minimum_gap = HOUR_IN_SECONDS * 6;

    foreach ($history as $entry) {
        if (empty($merged)) {
            $merged[] = $entry;
            continue;
        }

        $last_index = count($merged) - 1;
        $last_entry = $merged[$last_index];

        if (($entry['timestamp'] - $last_entry['timestamp']) < $minimum_gap) {
            $merged[$last_index] = $entry;
        } else {
            $merged[] = $entry;
        }
    }

    $cutoff = $timestamp - (DAY_IN_SECONDS * 14);

    $merged = array_values(array_filter($merged, static function ($entry) use ($cutoff) {
        return $entry['timestamp'] >= $cutoff;
    }));

    if ($max_points > 0 && count($merged) > $max_points) {
        $merged = array_slice($merged, -$max_points);
    }

    update_option(sitepulse_custom_dashboard_get_debt_history_option_name(), $merged, false);

    return $merged;
}

function sitepulse_custom_dashboard_get_normalized_uptime_log() {
    $raw_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);

    if (function_exists('sitepulse_normalize_uptime_log')) {
        $log = sitepulse_normalize_uptime_log($raw_log);
    } elseif (is_array($raw_log)) {
        $log = [];

        foreach ($raw_log as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $status    = isset($entry['status']) ? $entry['status'] : null;

            if (null === $status) {
                $status = !empty($entry);
            }

            if ('maintenance' === $status) {
                $incident_start = null;
            } else {
                $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : null;
            }

            $log[] = array_filter([
                'timestamp'      => $timestamp,
                'status'         => $status,
                'incident_start' => $incident_start,
                'error'          => isset($entry['error']) ? (string) $entry['error'] : '',
                'agent'          => isset($entry['agent']) ? (string) $entry['agent'] : 'default',
            ], static function ($value) {
                return null !== $value;
            });
        }

        usort($log, static function ($a, $b) {
            return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
        });
    } else {
        $log = [];
    }

    return is_array($log) ? $log : [];
}

function sitepulse_custom_dashboard_collect_open_incidents(array $log, $now) {
    if (empty($log)) {
        return [];
    }

    $now = (int) $now;
    $open = [];

    foreach ($log as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $status    = $entry['status'] ?? null;
        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $agent     = isset($entry['agent']) ? (string) $entry['agent'] : 'default';

        if (function_exists('sitepulse_uptime_normalize_agent_id')) {
            $agent = sitepulse_uptime_normalize_agent_id($agent);
        } else {
            $agent = sanitize_key($agent);
        }

        if (true === $status || 'maintenance' === $status) {
            unset($open[$agent]);
            continue;
        }

        if (false === $status || 'unknown' === $status || null === $status) {
            $severity = (false === $status) ? 'critical' : 'warning';
            $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : null;

            if ($incident_start === null || $incident_start <= 0) {
                $incident_start = $timestamp > 0 ? $timestamp : $now;
            }

            if (!isset($open[$agent])) {
                $open[$agent] = [
                    'agent_id'      => $agent,
                    'severity'      => $severity,
                    'status'        => false === $status ? 'down' : 'unknown',
                    'incident_start'=> $incident_start,
                    'last_seen'     => $timestamp,
                    'checks'        => 1,
                    'error'         => isset($entry['error']) ? (string) $entry['error'] : '',
                ];
            } else {
                $open[$agent]['last_seen'] = max($open[$agent]['last_seen'], $timestamp);
                $open[$agent]['checks']++;

                if ($incident_start < $open[$agent]['incident_start']) {
                    $open[$agent]['incident_start'] = $incident_start;
                }

                if ($open[$agent]['error'] === '' && !empty($entry['error'])) {
                    $open[$agent]['error'] = (string) $entry['error'];
                }

                if ('critical' === $severity) {
                    $open[$agent]['severity'] = 'critical';
                    $open[$agent]['status']   = 'down';
                }
            }

            continue;
        }

        unset($open[$agent]);
    }

    if (empty($open)) {
        return [];
    }

    $agents = function_exists('sitepulse_uptime_get_agents') ? sitepulse_uptime_get_agents() : [];

    foreach ($open as $agent_id => $incident) {
        $agent_label = ucfirst(str_replace('_', ' ', $agent_id));

        if (isset($agents[$agent_id]['label'])) {
            $agent_label = (string) $agents[$agent_id]['label'];
        }

        $open[$agent_id]['agent_label'] = $agent_label;
        $open[$agent_id]['duration']    = max(0, $now - $incident['incident_start']);
    }

    usort($open, static function ($a, $b) {
        $severity_map = ['critical' => 2, 'warning' => 1, 'ok' => 0];
        $a_severity   = $severity_map[$a['severity']] ?? 0;
        $b_severity   = $severity_map[$b['severity']] ?? 0;

        if ($a_severity === $b_severity) {
            return $a['incident_start'] <=> $b['incident_start'];
        }

        return $b_severity <=> $a_severity;
    });

    return array_values($open);
}

function sitepulse_custom_dashboard_format_sla_kpi($uptime, $incidents, $range_label) {
    if (!is_array($uptime) || !isset($uptime['uptime'])) {
        return null;
    }

    $uptime_value = isset($uptime['uptime']) ? (float) $uptime['uptime'] : null;

    if (null === $uptime_value) {
        return null;
    }

    $value_text = number_format_i18n($uptime_value, 2) . ' %';
    $total_checks = isset($uptime['totals']['total']) ? (int) $uptime['totals']['total'] : 0;
    $checks_label = sprintf(
        _n('%s contrôle analysé', '%s contrôles analysés', $total_checks, 'sitepulse'),
        number_format_i18n($total_checks)
    );

    $trend_delta = isset($uptime['trend']['uptime']) ? $uptime['trend']['uptime'] : null;
    $trend = null;

    if (is_numeric($trend_delta)) {
        $direction = 'flat';

        if ($trend_delta > 0.0001) {
            $direction = 'up';
        } elseif ($trend_delta < -0.0001) {
            $direction = 'down';
        }

        $trend_points = number_format_i18n(abs($trend_delta), 2);
        $trend_text = sprintf(
            /* translators: %s: variation in SLA points. */
            __('%1$s%2$s pts vs période précédente', 'sitepulse'),
            $direction === 'down' ? '−' : ($direction === 'up' ? '+' : ''),
            $trend_points
        );

        $trend_sr = sprintf(
            /* translators: %s: variation in SLA points. */
            __('Variation de %s point(s) sur le SLA.', 'sitepulse'),
            $trend_points
        );

        $trend = [
            'text'      => $trend_text,
            'direction' => $direction,
            'sr'        => $trend_sr,
        ];
    }

    $warning_threshold = sitepulse_custom_dashboard_get_uptime_warning_threshold();
    $critical_threshold = max(0.0, $warning_threshold - 1.5);

    $status = 'ok';

    if ($uptime_value < $critical_threshold) {
        $status = 'critical';
    } elseif ($uptime_value < $warning_threshold) {
        $status = 'warning';
    }

    if (!empty($incidents)) {
        foreach ($incidents as $incident) {
            if (isset($incident['severity']) && 'critical' === $incident['severity']) {
                $status = 'critical';
                break;
            }

            $status = 'warning';
        }
    }

    return [
        'key'    => 'sla',
        'status' => $status,
        'icon'   => 'dashicons-shield',
        'label'  => sprintf(__('SLA global (%s)', 'sitepulse'), $range_label),
        'value'  => $value_text,
        'meta'   => $checks_label,
        'trend'  => $trend,
    ];
}

/**
 * Provides a human readable interval description when WordPress helpers are unavailable.
 *
 * @param int|float $seconds Duration in seconds.
 *
 * @return string
 */
function sitepulse_custom_dashboard_format_interval_fallback($seconds) {
    $seconds = (int) round($seconds);

    if ($seconds < 1) {
        $seconds = 1;
    }

    $minute = defined('MINUTE_IN_SECONDS') ? (int) MINUTE_IN_SECONDS : 60;
    $hour   = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
    $day    = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
    $week   = defined('WEEK_IN_SECONDS') ? (int) WEEK_IN_SECONDS : ($day * 7);

    $format_number = static function ($value) {
        if (function_exists('number_format_i18n')) {
            return number_format_i18n($value);
        }

        return number_format((float) $value);
    };

    if ($seconds < $minute) {
        $value = max(1, $seconds);

        return sprintf(
            _n('%s seconde', '%s secondes', $value, 'sitepulse'),
            $format_number($value)
        );
    }

    if ($seconds < $hour) {
        $value = max(1, (int) round($seconds / $minute));

        return sprintf(
            _n('%s minute', '%s minutes', $value, 'sitepulse'),
            $format_number($value)
        );
    }

    if ($seconds < $day) {
        $value = max(1, (int) round($seconds / $hour));

        return sprintf(
            _n('%s heure', '%s heures', $value, 'sitepulse'),
            $format_number($value)
        );
    }

    if ($seconds < $week) {
        $value = max(1, (int) round($seconds / $day));

        return sprintf(
            _n('%s jour', '%s jours', $value, 'sitepulse'),
            $format_number($value)
        );
    }

    $value = max(1, (int) round($seconds / $week));

    return sprintf(
        _n('%s semaine', '%s semaines', $value, 'sitepulse'),
        $format_number($value)
    );
}

function sitepulse_custom_dashboard_format_incident_kpi($incidents, $range_label, $now) {
    $count = is_array($incidents) ? count($incidents) : 0;

    $status = 'ok';

    if ($count > 0) {
        foreach ($incidents as $incident) {
            if (isset($incident['severity']) && 'critical' === $incident['severity']) {
                $status = 'critical';
                break;
            }
        }

        if ('critical' !== $status) {
            $status = 'warning';
        }
    }

    $value = number_format_i18n($count);
    $label = __('Incidents actifs', 'sitepulse');
    $items = [];

    if ($count > 0) {
        $subset = array_slice($incidents, 0, 3);

        foreach ($subset as $incident) {
            $agent_label = isset($incident['agent_label']) ? (string) $incident['agent_label'] : __('Agent inconnu', 'sitepulse');
            $error = isset($incident['error']) ? trim((string) $incident['error']) : '';

            if ($error !== '') {
                $error = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($error) : strip_tags($error);
            }

            if ($error === '') {
                $error = ('unknown' === ($incident['status'] ?? ''))
                    ? __('Diagnostic en attente', 'sitepulse')
                    : __('Réponse HTTP inattendue', 'sitepulse');
            }

            $since_seconds = isset($incident['incident_start']) ? max(0, $now - (int) $incident['incident_start']) : 0;

            if ($since_seconds > 0) {
                $relative = function_exists('human_time_diff')
                    ? human_time_diff($now - $since_seconds, $now)
                    : sitepulse_custom_dashboard_format_interval_fallback($since_seconds);

                $since_label = sprintf(__('depuis %s', 'sitepulse'), $relative);
            } else {
                $since_label = __('début immédiat', 'sitepulse');
            }

            $items[] = [
                'label' => $agent_label,
                'description' => sprintf('%s — %s', $error, $since_label),
                'severity' => isset($incident['severity']) ? $incident['severity'] : $status,
            ];
        }
    }

    $empty_message = __('Aucun incident actif.', 'sitepulse');

    return [
        'key'           => 'incidents',
        'status'        => $status,
        'icon'          => 'dashicons-warning',
        'label'         => $label,
        'value'         => $value,
        'items'         => $items,
        'empty_message' => $empty_message,
        'meta'          => sprintf(__('Fenêtre : %s', 'sitepulse'), $range_label),
    ];
}

function sitepulse_custom_dashboard_calculate_operational_debt_snapshot($queue_overview, $ai_summary, $now) {
    $metrics = [];

    if (is_array($queue_overview) && isset($queue_overview['metrics'])) {
        $metrics = $queue_overview['metrics'];
    }

    $queue_length     = isset($metrics['queue_length']) ? (int) $metrics['queue_length'] : 0;
    $prioritized_jobs = isset($metrics['prioritized_jobs']) ? (int) $metrics['prioritized_jobs'] : 0;
    $delayed_jobs     = isset($metrics['delayed_jobs']) ? (int) $metrics['delayed_jobs'] : 0;
    $avg_priority     = isset($metrics['avg_priority']) ? (int) $metrics['avg_priority'] : 0;
    $max_wait         = isset($metrics['max_wait_seconds']) ? (int) $metrics['max_wait_seconds'] : 0;

    $queue_score = $queue_length;

    if ($prioritized_jobs > 0) {
        $queue_score += $prioritized_jobs * max(2, $avg_priority);
    }

    if ($delayed_jobs > 0) {
        $queue_score += $delayed_jobs * 1.5;
    }

    if ($max_wait > 900) {
        $queue_score += ceil($max_wait / 300);
    }

    $ai_pending = isset($ai_summary['recent_pending']) ? (int) $ai_summary['recent_pending'] : 0;
    $ai_stale   = isset($ai_summary['stale_pending']) ? (int) $ai_summary['stale_pending'] : 0;
    $ai_score   = ($ai_pending * 2) + ($ai_stale * 3);

    $score = round($queue_score + $ai_score, 1);

    $history = sitepulse_custom_dashboard_store_debt_sample($score, $now);

    return [
        'score'   => $score,
        'queue'   => [
            'length'     => $queue_length,
            'prioritized'=> $prioritized_jobs,
            'delayed'    => $delayed_jobs,
            'max_wait'   => $max_wait,
        ],
        'ai'      => [
            'pending' => $ai_pending,
            'stale'   => $ai_stale,
        ],
        'history' => $history,
    ];
}

function sitepulse_custom_dashboard_format_debt_kpi($snapshot, $range_label, $now) {
    if (!is_array($snapshot) || !isset($snapshot['score'])) {
        return null;
    }

    $score = (float) $snapshot['score'];
    $score_text = sprintf(
        _n('%s point', '%s points', round($score), 'sitepulse'),
        number_format_i18n($score, 1)
    );

    $queue = isset($snapshot['queue']) && is_array($snapshot['queue']) ? $snapshot['queue'] : [];
    $ai    = isset($snapshot['ai']) && is_array($snapshot['ai']) ? $snapshot['ai'] : [];

    $summary_parts = [];

    $queue_length = isset($queue['length']) ? (int) $queue['length'] : 0;
    $prioritized  = isset($queue['prioritized']) ? (int) $queue['prioritized'] : 0;
    $ai_pending   = isset($ai['pending']) ? (int) $ai['pending'] : 0;
    $ai_stale     = isset($ai['stale']) ? (int) $ai['stale'] : 0;

    $summary_parts[] = sprintf(
        _n('File d’attente : %s tâche', 'File d’attente : %s tâches', $queue_length, 'sitepulse'),
        number_format_i18n($queue_length)
    );

    if ($prioritized > 0) {
        $summary_parts[] = sprintf(
            _n('%s intervention prioritaire', '%s interventions prioritaires', $prioritized, 'sitepulse'),
            number_format_i18n($prioritized)
        );
    }

    $ai_total = $ai_pending + $ai_stale;

    $summary_parts[] = sprintf(
        _n('IA : %s action en attente', 'IA : %s actions en attente', $ai_total, 'sitepulse'),
        number_format_i18n($ai_total)
    );

    $summary = implode(' • ', $summary_parts);

    $status = 'ok';

    if ($score >= 40) {
        $status = 'critical';
    } elseif ($score >= 15) {
        $status = 'warning';
    }

    $history = isset($snapshot['history']) && is_array($snapshot['history'])
        ? $snapshot['history']
        : sitepulse_custom_dashboard_get_debt_history();

    $history = array_values(array_filter($history, static function ($entry) {
        return isset($entry['timestamp'], $entry['score']);
    }));

    $sparkline = [];
    $sparkline_sr = '';
    $trend = null;

    if (!empty($history)) {
        usort($history, static function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        $window = count($history) > 7 ? array_slice($history, -7) : $history;
        $max_value = max(array_map(static function ($entry) {
            return (float) $entry['score'];
        }, $window));

        if ($max_value <= 0) {
            $max_value = 1;
        }

        $date_format = get_option('date_format');

        foreach ($window as $entry) {
            $label = $entry['timestamp'] > 0
                ? (function_exists('wp_date') ? wp_date($date_format, $entry['timestamp']) : date_i18n($date_format, $entry['timestamp']))
                : '';

            $sparkline[] = [
                'value'    => (float) $entry['score'],
                'relative' => max(0.0, min(1.0, $entry['score'] / $max_value)),
                'label'    => $label,
            ];
        }

        $first = reset($window);
        $last  = end($window);

        if ($first && $last) {
            $baseline = (float) $first['score'];
            $latest   = (float) $last['score'];

            if ($baseline > 0) {
                $percent = (($latest - $baseline) / $baseline) * 100;

                $direction = 'flat';
                if ($percent > 0.5) {
                    $direction = 'up';
                } elseif ($percent < -0.5) {
                    $direction = 'down';
                }

                $trend = [
                    'text'      => sprintf('%s%s %% sur 7 j', $direction === 'down' ? '−' : ($direction === 'up' ? '+' : ''), number_format_i18n(abs($percent), 1)),
                    'direction' => $direction,
                    'sr'        => sprintf(__('Variation de %s %% de la dette sur 7 jours.', 'sitepulse'), number_format_i18n(abs($percent), 1)),
                ];
            } elseif ($latest > 0) {
                $trend = [
                    'text'      => __('Dette apparue', 'sitepulse'),
                    'direction' => 'up',
                    'sr'        => __('Nouvelle dette opérationnelle enregistrée cette semaine.', 'sitepulse'),
                ];
            }

            $sparkline_sr = sprintf(
                __('Dette opérationnelle entre %1$s et %2$s points sur 7 jours.', 'sitepulse'),
                number_format_i18n(min(array_map(static function ($entry) {
                    return (float) $entry['score'];
                }, $window)), 1),
                number_format_i18n($max_value, 1)
            );
        }
    }

    return [
        'key'          => 'debt',
        'status'       => $status,
        'icon'         => 'dashicons-portfolio',
        'label'        => __('Dette opérationnelle', 'sitepulse'),
        'value'        => $score_text,
        'summary'      => $summary,
        'trend'        => $trend,
        'sparkline'    => $sparkline,
        'sparkline_sr' => $sparkline_sr,
        'meta'         => sprintf(__('Fenêtre : %s', 'sitepulse'), $range_label),
    ];
}

function sitepulse_custom_dashboard_build_kpi_cards($payload, $range_label, $now = null) {
    $now = null === $now ? sitepulse_custom_dashboard_get_current_timestamp() : (int) $now;
    $kpis = [];

    $uptime    = isset($payload['uptime']) ? $payload['uptime'] : null;
    $incidents = isset($payload['incidents']) && is_array($payload['incidents']) ? $payload['incidents'] : [];
    $ai_summary = isset($payload['ai_summary']) && is_array($payload['ai_summary']) ? $payload['ai_summary'] : [];
    $queue_overview = isset($payload['remote_queue']) ? $payload['remote_queue'] : null;

    $sla_card = sitepulse_custom_dashboard_format_sla_kpi($uptime, $incidents, $range_label);

    if (is_array($sla_card)) {
        $kpis[] = $sla_card;
    }

    $incidents_card = sitepulse_custom_dashboard_format_incident_kpi($incidents, $range_label, $now);

    if (is_array($incidents_card)) {
        $kpis[] = $incidents_card;
    }

    $debt_snapshot = sitepulse_custom_dashboard_calculate_operational_debt_snapshot($queue_overview, $ai_summary, $now);
    $debt_card = sitepulse_custom_dashboard_format_debt_kpi($debt_snapshot, $range_label, $now);

    if (is_array($debt_card)) {
        $kpis[] = $debt_card;
    }

    return $kpis;
}

/**
 * Builds the contextual status banner based on the current metrics.
 *
 * @param array<string,array<string,mixed>> $cards   Formatted cards indexed by key.
 * @param array<string,mixed>               $payload Raw payload data.
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

    $uptime_card     = isset($cards['uptime']) ? $cards['uptime'] : null;
    $logs_card       = isset($cards['logs']) ? $cards['logs'] : null;
    $speed_card      = isset($cards['speed']) ? $cards['speed'] : null;
    $experience_card = isset($cards['experience']) ? $cards['experience'] : null;

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

    if (is_array($experience_card) && empty($experience_card['inactive'])) {
        $experience_status = isset($experience_card['status']['class']) ? $experience_card['status']['class'] : 'status-ok';

        if ('status-bad' === $experience_status && 'danger' !== $tone) {
            $tone = 'danger';
            $icon = '🚨';
            $message = __('🚨 Real user experience is in the red.', 'sitepulse');
            $sr = __('Real user monitoring indicates a critical degradation.', 'sitepulse');
            $cta = [
                'label' => __('Inspect real user metrics', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-speed'),
                'data'  => 'performance-playbook',
            ];
        } elseif ('status-warn' === $experience_status && 'danger' !== $tone && 'warning' !== $tone) {
            $tone = 'warning';
            $icon = '⚠️';
            $message = __('⚠️ Real user metrics require attention.', 'sitepulse');
            $sr = __('Real user metrics require attention.', 'sitepulse');
            $cta = [
                'label' => __('Review Web Vitals', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-speed'),
                'data'  => 'performance-playbook',
            ];
        }
    }

    $kpis = sitepulse_custom_dashboard_build_kpi_cards($payload, $range_label);

    return [
        'tone'    => $tone,
        'icon'    => $icon,
        'message' => $message,
        'sr'      => $sr,
        'cta'     => $cta,
        'kpis'    => $kpis,
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
        'experience' => sitepulse_custom_dashboard_format_rum_card_view(
            isset($payload['rum']) ? $payload['rum'] : null,
            !empty($modules['speed_analyzer']),
            !empty($modules['rum']),
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

function sitepulse_render_dashboard_banner_kpi($kpi) {
    if (!is_array($kpi)) {
        return '';
    }

    $classes = ['sitepulse-status-banner__kpi'];
    $key     = isset($kpi['key']) ? sanitize_key((string) $kpi['key']) : '';
    $status  = isset($kpi['status']) ? sanitize_html_class((string) $kpi['status']) : 'ok';

    if ($status !== '') {
        $classes[] = 'sitepulse-status-banner__kpi--' . $status;
    }

    $icon_class = isset($kpi['icon']) ? (string) $kpi['icon'] : 'dashicons-chart-area';
    if (strpos($icon_class, 'dashicons') === false) {
        $icon_class = 'dashicons ' . $icon_class;
    }

    $label = isset($kpi['label']) ? (string) $kpi['label'] : '';
    $value = isset($kpi['value']) ? (string) $kpi['value'] : '';
    $meta  = isset($kpi['meta']) ? (string) $kpi['meta'] : '';
    $summary = isset($kpi['summary']) ? (string) $kpi['summary'] : '';

    $trend = isset($kpi['trend']) && is_array($kpi['trend']) ? $kpi['trend'] : null;
    $trend_text = ($trend && isset($trend['text'])) ? (string) $trend['text'] : '';
    $trend_direction = ($trend && isset($trend['direction'])) ? sanitize_html_class((string) $trend['direction']) : 'flat';
    $trend_sr = ($trend && isset($trend['sr'])) ? (string) $trend['sr'] : '';

    $items = isset($kpi['items']) && is_array($kpi['items']) ? $kpi['items'] : [];
    $empty_message = isset($kpi['empty_message']) ? (string) $kpi['empty_message'] : '';

    $sparkline = isset($kpi['sparkline']) && is_array($kpi['sparkline']) ? $kpi['sparkline'] : [];
    $sparkline_sr = isset($kpi['sparkline_sr']) ? (string) $kpi['sparkline_sr'] : '';

    ob_start();
    ?>
    <article class="<?php echo esc_attr(implode(' ', $classes)); ?>"<?php echo $key !== '' ? ' data-sitepulse-banner-kpi="' . esc_attr($key) . '"' : ''; ?>>
        <div class="sitepulse-status-banner__kpi-top">
            <span class="<?php echo esc_attr(trim('sitepulse-status-banner__kpi-icon ' . $icon_class)); ?>" aria-hidden="true"></span>
            <div class="sitepulse-status-banner__kpi-body">
                <span class="sitepulse-status-banner__kpi-label"><?php echo esc_html($label); ?></span>
                <span class="sitepulse-status-banner__kpi-value" data-sitepulse-banner-kpi-value><?php echo esc_html($value); ?></span>
                <?php if ($meta !== '') : ?>
                    <span class="sitepulse-status-banner__kpi-meta" data-sitepulse-banner-kpi-meta><?php echo esc_html($meta); ?></span>
                <?php endif; ?>
                <?php if ($trend_text !== '') : ?>
                    <span class="sitepulse-status-banner__kpi-trend" data-trend="<?php echo esc_attr($trend_direction); ?>" data-sitepulse-banner-kpi-trend>
                        <?php echo esc_html($trend_text); ?>
                    </span>
                    <?php if ($trend_sr !== '') : ?>
                        <span class="screen-reader-text"><?php echo esc_html($trend_sr); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($summary !== '') : ?>
            <p class="sitepulse-status-banner__kpi-summary" data-sitepulse-banner-kpi-summary><?php echo esc_html($summary); ?></p>
        <?php endif; ?>
        <?php if (!empty($items)) : ?>
            <ul class="sitepulse-status-banner__kpi-list" data-sitepulse-banner-kpi-items>
                <?php foreach ($items as $item) :
                    if (!is_array($item)) {
                        continue;
                    }

                    $item_label = isset($item['label']) ? (string) $item['label'] : '';
                    $item_description = isset($item['description']) ? (string) $item['description'] : '';
                    $item_severity = isset($item['severity']) ? sanitize_html_class((string) $item['severity']) : '';
                ?>
                    <li class="sitepulse-status-banner__kpi-item<?php echo $item_severity !== '' ? ' sitepulse-status-banner__kpi-item--' . esc_attr($item_severity) : ''; ?>">
                        <span class="sitepulse-status-banner__kpi-item-label"><?php echo esc_html($item_label); ?></span>
                        <?php if ($item_description !== '') : ?>
                            <span class="sitepulse-status-banner__kpi-item-description"><?php echo esc_html($item_description); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php elseif ($empty_message !== '') : ?>
            <p class="sitepulse-status-banner__kpi-empty" data-sitepulse-banner-kpi-empty><?php echo esc_html($empty_message); ?></p>
        <?php endif; ?>
        <?php if (!empty($sparkline)) :
            $bars = array_filter($sparkline, static function ($point) {
                return is_array($point) && isset($point['relative']);
            });
        ?>
            <div class="sitepulse-status-banner__sparkline" data-sitepulse-banner-kpi-sparkline aria-hidden="true">
                <?php foreach ($bars as $point) :
                    $relative = max(0.0, min(1.0, (float) $point['relative']));
                    $height   = (int) round($relative * 100);
                    $label    = isset($point['label']) ? (string) $point['label'] : '';
                ?>
                    <span class="sitepulse-status-banner__sparkline-bar" style="--sitepulse-sparkline-height: <?php echo esc_attr($height); ?>%"<?php echo $label !== '' ? ' title="' . esc_attr($label) . '"' : ''; ?>></span>
                <?php endforeach; ?>
            </div>
            <?php if ($sparkline_sr !== '') : ?>
                <span class="screen-reader-text" data-sitepulse-banner-kpi-sparkline-sr><?php echo esc_html($sparkline_sr); ?></span>
            <?php endif; ?>
        <?php endif; ?>
    </article>
    <?php

    return (string) ob_get_clean();
}

function sitepulse_render_dashboard_banner_kpis($kpis) {
    $kpis = is_array($kpis) ? array_filter($kpis, 'is_array') : [];

    ob_start();
    ?>
    <div class="sitepulse-status-banner__kpi-grid" data-sitepulse-banner-kpis<?php echo empty($kpis) ? ' hidden' : ''; ?>>
        <?php foreach ($kpis as $kpi) : ?>
            <?php echo sitepulse_render_dashboard_banner_kpi($kpi); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
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
require_once __DIR__ . '/dashboard/metrics.php';

require_once __DIR__ . '/dashboard/page.php';
