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

require_once __DIR__ . '/dashboard/cards.php';
require_once __DIR__ . '/dashboard/kpis.php';
require_once __DIR__ . '/dashboard/render.php';
require_once __DIR__ . '/dashboard/metrics.php';
require_once __DIR__ . '/dashboard/page.php';
