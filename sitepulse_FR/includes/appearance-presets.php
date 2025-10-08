<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the catalog of CSS appearance presets for SitePulse.
 *
 * @return array<string, array{label:string,description:string}>
 */
function sitepulse_get_css_presets_catalog() {
    return [
        'default' => [
            'label'       => __('Présentation WordPress', 'sitepulse'),
            'description' => __('Palette claire par défaut alignée sur les styles admin classiques.', 'sitepulse'),
        ],
        'soft-mint' => [
            'label'       => __('Soft Mint', 'sitepulse'),
            'description' => __('Surfaces pastel, accent bleu et badges arrondis pour une lecture apaisée.', 'sitepulse'),
        ],
        'midnight' => [
            'label'       => __('Midnight', 'sitepulse'),
            'description' => __('Thème sombre modernisé inspiré des consoles d’observabilité.', 'sitepulse'),
        ],
        'contrast' => [
            'label'       => __('Contrast Pro', 'sitepulse'),
            'description' => __('Palette très contrastée pensée pour l’accessibilité et les environnements lumineux.', 'sitepulse'),
        ],
    ];
}

/**
 * Returns the active CSS preset slug.
 *
 * @return string
 */
function sitepulse_get_active_css_preset() {
    $catalog = sitepulse_get_css_presets_catalog();
    $default = 'default';

    $preset = apply_filters('sitepulse_active_css_preset', $default, $catalog);

    if (!is_string($preset) || !isset($catalog[$preset])) {
        return $default;
    }

    return $preset;
}

/**
 * Determines whether the current admin screen should receive the appearance preset class.
 *
 * @param WP_Screen|null $screen Screen object.
 *
 * @return bool
 */
function sitepulse_should_apply_css_preset($screen) {
    if (!$screen) {
        return false;
    }

    $screen_id = isset($screen->id) ? (string) $screen->id : '';

    if ($screen_id === '') {
        return false;
    }

    if ($screen_id === 'dashboard') {
        return true;
    }

    if ($screen_id === 'toplevel_page_sitepulse-dashboard') {
        return true;
    }

    if (strpos($screen_id, 'sitepulse-dashboard_page_sitepulse-') === 0) {
        return true;
    }

    return false;
}

/**
 * Registers the stylesheet containing the appearance presets.
 *
 * @return void
 */
function sitepulse_register_appearance_presets_style() {
    if (wp_style_is('sitepulse-appearance-presets', 'registered')) {
        return;
    }

    wp_register_style(
        'sitepulse-appearance-presets',
        SITEPULSE_URL . 'modules/css/appearance-presets.css',
        [],
        SITEPULSE_VERSION
    );
}

/**
 * Adds the active preset class to the admin body when relevant.
 *
 * @param string $classes Body class string.
 *
 * @return string
 */
function sitepulse_admin_body_class_with_preset($classes) {
    if (!is_string($classes)) {
        $classes = '';
    }

    if (!function_exists('get_current_screen')) {
        return $classes;
    }

    $screen = get_current_screen();

    if (!sitepulse_should_apply_css_preset($screen)) {
        return $classes;
    }

    $preset = sitepulse_get_active_css_preset();

    if ($preset === 'default') {
        return $classes;
    }

    $class = 'sitepulse-appearance--' . sanitize_html_class($preset);

    if ($class === '') {
        return $classes;
    }

    if ($classes !== '') {
        $classes .= ' ';
    }

    return $classes . $class;
}
add_filter('admin_body_class', 'sitepulse_admin_body_class_with_preset');

/**
 * Enqueues the appearance preset stylesheet on relevant admin screens.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 *
 * @return void
 */
function sitepulse_enqueue_appearance_presets_assets($hook_suffix) {
    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();

    if (!sitepulse_should_apply_css_preset($screen)) {
        return;
    }

    $preset = sitepulse_get_active_css_preset();

    if ($preset === 'default') {
        return;
    }

    sitepulse_register_appearance_presets_style();

    wp_enqueue_style('sitepulse-appearance-presets');
}
add_action('admin_enqueue_scripts', 'sitepulse_enqueue_appearance_presets_assets');
