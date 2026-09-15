<?php
/**
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the capability required to manage SitePulse settings.
 *
 * @return string Filterable capability name.
 */
function sitepulse_get_capability() {
    $default_capability = 'manage_options';

    if (function_exists('apply_filters')) {
        $filtered_capability = apply_filters('sitepulse_required_capability', $default_capability);

        if (is_string($filtered_capability) && $filtered_capability !== '') {
            return $filtered_capability;
        }
    }

    return $default_capability;
}

/**
 * Wrapper for the main SitePulse dashboard page.
 *
 * Ensures that the menu callback registered via {@see add_menu_page()} is always
 * available, even when the Custom Dashboards module is disabled. When the module
 * is active the actual module output is rendered, otherwise an informative
 * notice is displayed with guidance on how to enable the feature.
 */
function sitepulse_render_dashboard_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    if (function_exists('sitepulse_custom_dashboards_page')) {
        sitepulse_custom_dashboards_page();
        return;
    }

    $active_modules = (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []);
    $is_dashboard_enabled = in_array('custom_dashboards', $active_modules, true);
    $settings_url = admin_url('admin.php?page=sitepulse-settings');

    if ($is_dashboard_enabled) {
        $notice = __('Le module de tableau de bord est activé mais son rendu est indisponible. Vérifiez les fichiers du plugin ou les journaux d’erreurs.', 'sitepulse');
    } else {
        $notice = sprintf(
            /* translators: %s is the URL to the SitePulse settings page. */
            __('Le module de tableau de bord est désactivé. Activez-le depuis les <a href="%s">réglages de SitePulse</a>.', 'sitepulse'),
            esc_url($settings_url)
        );
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Tableau de bord SitePulse', 'sitepulse') . '</h1>';
    echo '<div class="notice notice-warning"><p>' . wp_kses_post($notice) . '</p></div>';
    echo '</div>';
}

/**
 * Registers all the SitePulse admin menu and submenu pages.
 */
function sitepulse_admin_menu() {
    add_menu_page(
        __('Tableau de bord SitePulse', 'sitepulse'),
        __('Sitepulse - JLG', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-dashboard',
        'sitepulse_render_dashboard_page',
        'dashicons-chart-area',
        30
    );

    add_submenu_page(
        'sitepulse-dashboard',
        __('Réglages SitePulse', 'sitepulse'),
        __('Réglages', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-settings',
        'sitepulse_settings_page'
    );

    if (defined('SITEPULSE_DEBUG') && SITEPULSE_DEBUG) {
        add_submenu_page(
            'sitepulse-dashboard',
            __('Débogage SitePulse', 'sitepulse'),
            __('Débogage', 'sitepulse'),
            sitepulse_get_capability(),
            'sitepulse-debug',
            'sitepulse_debug_page'
        );
    }
}
add_action('admin_menu', 'sitepulse_admin_menu');

/**
 * Hides module submenu items from the admin sidebar while keeping the pages registered.
 *
 * Dashboard, Settings, and Debug remain visible. Module screens stay reachable via
 * admin.php?page=sitepulse-* and the in-page nav-tab selector.
 *
 * Do not unregister hidden module slugs with remove_submenu_page.
 * WordPress 6.3+/7.1 then returns 403 for admin.php?page=sitepulse-speed and siblings.
 * Empty the sidebar label and add the core `hidden` class instead.
 *
 * @return void
 */
function sitepulse_hide_module_admin_submenus() {
    global $submenu;

    if (!isset($submenu['sitepulse-dashboard']) || !is_array($submenu['sitepulse-dashboard'])) {
        return;
    }

    $hidden_slugs = [
        'sitepulse-uptime',
        'sitepulse-speed',
        'sitepulse-resources',
        'sitepulse-plugins',
        'sitepulse-maintenance',
        'sitepulse-logs',
        'sitepulse-db',
        'sitepulse-ai',
    ];

    if (function_exists('apply_filters')) {
        /**
         * Filters the SitePulse submenu slugs hidden from the admin sidebar.
         *
         * @param array<int,string> $hidden_slugs Submenu slugs to hide.
         */
        $hidden_slugs = apply_filters('sitepulse_hidden_admin_submenu_slugs', $hidden_slugs);
    }

    if (!is_array($hidden_slugs)) {
        return;
    }

    $hidden_lookup = [];

    foreach ($hidden_slugs as $slug) {
        $slug = is_string($slug) ? (function_exists('sanitize_key') ? sanitize_key($slug) : $slug) : '';

        if ($slug !== '') {
            $hidden_lookup[$slug] = true;
        }
    }

    $visible_slugs = [
        'sitepulse-dashboard' => true,
        'sitepulse-settings'  => true,
    ];

    if (defined('SITEPULSE_DEBUG') && SITEPULSE_DEBUG) {
        $visible_slugs['sitepulse-debug'] = true;
    }

    foreach ($submenu['sitepulse-dashboard'] as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $item_slug = isset($item[2]) && is_string($item[2]) ? $item[2] : '';

        if ($item_slug === '' || isset($visible_slugs[$item_slug]) || !isset($hidden_lookup[$item_slug])) {
            continue;
        }

        $submenu['sitepulse-dashboard'][$index][0] = '';
        $classes = isset($item[4]) && is_string($item[4]) ? $item[4] : '';
        $tokens  = preg_split('/\s+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($tokens)) {
            $tokens = [];
        }

        if (!in_array('hidden', $tokens, true)) {
            $tokens[] = 'hidden';
        }

        $submenu['sitepulse-dashboard'][$index][4] = implode(' ', $tokens);
    }
}
add_action('admin_menu', 'sitepulse_hide_module_admin_submenus', 999);
