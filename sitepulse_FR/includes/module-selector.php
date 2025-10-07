<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the definitions for SitePulse module pages that should appear in the selector.
 *
 * @return array<string, array{page:string,label:string,icon:string,always_available?:bool}>
 */
function sitepulse_get_module_selector_definitions() {
    $definitions = [
        'custom_dashboards' => [
            'page'             => 'sitepulse-dashboard',
            'label'            => __('Dashboard', 'sitepulse'),
            'icon'             => 'dashicons-dashboard',
            'tags'             => ['overview', 'summary', 'executive'],
            'always_available' => true,
        ],
        'speed_analyzer' => [
            'page'  => 'sitepulse-speed',
            'label' => __('Speed', 'sitepulse'),
            'icon'  => 'dashicons-performance',
            'tags'  => ['performance', 'web vitals', 'ttfb', 'lcp'],
        ],
        'uptime_tracker' => [
            'page'  => 'sitepulse-uptime',
            'label' => __('Uptime', 'sitepulse'),
            'icon'  => 'dashicons-chart-bar',
            'tags'  => ['availability', 'incidents', 'sla'],
        ],
        'database_optimizer' => [
            'page'  => 'sitepulse-db',
            'label' => __('Database', 'sitepulse'),
            'icon'  => 'dashicons-database',
            'tags'  => ['sql', 'cleanup', 'optimization'],
        ],
        'log_analyzer' => [
            'page'  => 'sitepulse-logs',
            'label' => __('Logs', 'sitepulse'),
            'icon'  => 'dashicons-hammer',
            'tags'  => ['errors', 'debug', 'php'],
        ],
        'resource_monitor' => [
            'page'  => 'sitepulse-resources',
            'label' => __('Resources', 'sitepulse'),
            'icon'  => 'dashicons-chart-area',
            'tags'  => ['infrastructure', 'cpu', 'memory'],
        ],
        'plugin_impact_scanner' => [
            'page'  => 'sitepulse-plugins',
            'label' => __('Plugins', 'sitepulse'),
            'icon'  => 'dashicons-admin-plugins',
            'tags'  => ['extensions', 'weight', 'load'],
        ],
        'maintenance_advisor' => [
            'page'  => 'sitepulse-maintenance',
            'label' => __('Maintenance', 'sitepulse'),
            'icon'  => 'dashicons-admin-tools',
            'tags'  => ['updates', 'security', 'housekeeping'],
        ],
        'ai_insights' => [
            'page'  => 'sitepulse-ai',
            'label' => __('AI Insights', 'sitepulse'),
            'icon'  => 'dashicons-lightbulb',
            'tags'  => ['automation', 'recommendations', 'content'],
        ],
    ];

    /**
     * Filters the module selector definitions.
     *
     * @param array $definitions The list of module definitions keyed by module slug.
     */
    return apply_filters('sitepulse_module_selector_definitions', $definitions);
}

/**
 * Builds the list of enabled module selector items including URLs.
 *
 * @return array<int, array{slug:string,label:string,url:string,icon:string,tags:array<int, string> }>
 */
function sitepulse_get_module_selector_items() {
    $active_modules = array_map('strval', (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []));
    $definitions    = sitepulse_get_module_selector_definitions();
    $items          = [];

    foreach ($definitions as $module_key => $definition) {
        $page_slug = isset($definition['page']) ? (string) $definition['page'] : '';
        $label     = isset($definition['label']) ? $definition['label'] : '';
        $icon      = isset($definition['icon']) ? (string) $definition['icon'] : '';

        if ($page_slug === '' || $label === '') {
            continue;
        }

        $requires_activation = empty($definition['always_available']);

        if ($requires_activation && !in_array($module_key, $active_modules, true)) {
            continue;
        }

        $tags = [];

        if (isset($definition['tags'])) {
            $tags = array_filter(array_map('strval', (array) $definition['tags']));
        }

        $items[] = [
            'slug'  => $page_slug,
            'label' => $label,
            'url'   => admin_url('admin.php?page=' . $page_slug),
            'icon'  => $icon,
            'tags'  => $tags,
        ];
    }

    /**
     * Filters the rendered selector items.
     *
     * @param array $items          Prepared selector entries.
     * @param array $active_modules Active module identifiers from the settings option.
     * @param array $definitions    Module selector definitions keyed by module slug.
     */
    return apply_filters('sitepulse_module_selector_items', $items, $active_modules, $definitions);
}

/**
 * Enqueues the shared module selector stylesheet when viewing a SitePulse module screen.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_module_selector_enqueue_style($hook_suffix) {
    $definitions = sitepulse_get_module_selector_definitions();
    $valid_hooks = [];

    foreach ($definitions as $definition) {
        if (!isset($definition['page'])) {
            continue;
        }

        $page_slug = (string) $definition['page'];

        if ($page_slug === '') {
            continue;
        }

        $valid_hooks[] = 'sitepulse-dashboard_page_' . $page_slug;
    }

    if (!in_array($hook_suffix, $valid_hooks, true)) {
        return;
    }

    wp_register_style(
        'sitepulse-module-navigation',
        SITEPULSE_URL . 'modules/css/module-navigation.css',
        [],
        SITEPULSE_VERSION
    );

    wp_enqueue_style('sitepulse-module-navigation');

    wp_register_script(
        'sitepulse-dashboard-nav',
        SITEPULSE_URL . 'modules/js/sitepulse-dashboard-nav.js',
        [],
        SITEPULSE_VERSION,
        true
    );

    wp_enqueue_script('sitepulse-dashboard-nav');
}
add_action('admin_enqueue_scripts', 'sitepulse_module_selector_enqueue_style');

/**
 * Builds the navigation items for the module selector, marking the current page.
 *
 * @param string $current_page Current module page slug (e.g. "sitepulse-speed").
 * @return array<int, array{slug:string,label:string,url:string,icon:string,tags:array<int, string>,current:bool}>
 */
function sitepulse_get_module_navigation_items($current_page = '') {
    $sanitizer = function_exists('sanitize_title')
        ? static function ($value) {
            return sanitize_title((string) $value);
        }
        : static function ($value) {
            $value = is_string($value) ? $value : '';
            $value = strtolower(trim($value));
            $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);

            return trim((string) $value, '-');
        };

    $current_page = $sanitizer($current_page);

    if (function_exists('current_user_can') && function_exists('sitepulse_get_capability')) {
        if (!current_user_can(sitepulse_get_capability())) {
            return [];
        }
    }

    $items = sitepulse_get_module_selector_items();

    foreach ($items as $index => $item) {
        $slug = isset($item['slug']) ? $sanitizer($item['slug']) : '';
        $items[$index]['current'] = ($slug !== '' && $slug === $current_page);
    }

    /**
     * Filters the prepared module navigation items.
     *
     * @param array  $items        Navigation entries with current flags.
     * @param string $current_page Current page slug.
     */
    return apply_filters('sitepulse_module_navigation_items', $items, $current_page);
}

/**
 * Outputs the module navigation component with icons.
 *
 * @param string $current_page Current module page slug (e.g. "sitepulse-speed").
 * @param array  $items        Optional pre-built navigation items.
 * @return void
 */
function sitepulse_render_module_navigation($current_page = '', $items = null) {
    if (!is_array($items)) {
        $items = sitepulse_get_module_navigation_items($current_page);
    }

    if (empty($items)) {
        return;
    }

    $nav_list_id = function_exists('wp_unique_id')
        ? wp_unique_id('sitepulse-module-nav-list-')
        : 'sitepulse-module-nav-list-' . uniqid('', true);

    $nav_select_id = function_exists('wp_unique_id')
        ? wp_unique_id('sitepulse-module-nav-select-')
        : 'sitepulse-module-nav-select-' . uniqid('', true);

    $nav_search_id = function_exists('wp_unique_id')
        ? wp_unique_id('sitepulse-module-nav-search-')
        : 'sitepulse-module-nav-search-' . uniqid('', true);

    $total_items = count($items);

    ?>
    <nav class="sitepulse-module-nav" aria-label="<?php esc_attr_e('SitePulse sections', 'sitepulse'); ?>">
        <div class="sitepulse-module-nav__search" role="search">
            <label class="sitepulse-module-nav__search-label" for="<?php echo esc_attr($nav_search_id); ?>"><?php esc_html_e('Search modules', 'sitepulse'); ?></label>
            <div class="sitepulse-module-nav__search-field">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <input
                    type="search"
                    class="sitepulse-module-nav__search-input"
                    id="<?php echo esc_attr($nav_search_id); ?>"
                    name="sitepulse-nav-search"
                    placeholder="<?php esc_attr_e('Filter by name, capability or focus…', 'sitepulse'); ?>"
                    autocomplete="off"
                    spellcheck="false"
                    data-sitepulse-nav-search
                    aria-describedby="<?php echo esc_attr($nav_search_id); ?>-help"
                />
                <button
                    type="button"
                    class="button-link sitepulse-module-nav__search-clear"
                    data-sitepulse-nav-clear
                    hidden
                >
                    <span class="screen-reader-text"><?php esc_html_e('Clear search', 'sitepulse'); ?></span>
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <p class="sitepulse-module-nav__search-help" id="<?php echo esc_attr($nav_search_id); ?>-help">
                <?php esc_html_e('Start typing to narrow down the available SitePulse modules.', 'sitepulse'); ?>
            </p>
        </div>
        <div class="sitepulse-module-nav__search-meta">
            <span
                class="sitepulse-module-nav__results"
                role="status"
                aria-live="polite"
                data-sitepulse-nav-results
                data-total="<?php echo (int) $total_items; ?>"
                data-empty="<?php esc_attr_e('No modules match your filters.', 'sitepulse'); ?>"
                data-singular="<?php esc_attr_e('1 module displayed', 'sitepulse'); ?>"
                data-plural="<?php esc_attr_e('%d modules displayed', 'sitepulse'); ?>"
            >
                <?php printf(esc_html__('%d modules displayed', 'sitepulse'), (int) $total_items); ?>
            </span>
            <p class="sitepulse-module-nav__empty" data-sitepulse-nav-empty hidden>
                <?php esc_html_e('Try adjusting your search or enable additional modules from the settings screen.', 'sitepulse'); ?>
            </p>
        </div>
        <form class="sitepulse-module-nav__mobile-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <label class="sitepulse-module-nav__mobile-label" for="<?php echo esc_attr($nav_select_id); ?>"><?php esc_html_e('Go to section', 'sitepulse'); ?></label>
            <div class="sitepulse-module-nav__mobile-controls">
                <select
                    class="sitepulse-module-nav__select"
                    id="<?php echo esc_attr($nav_select_id); ?>"
                    name="page"
                    data-sitepulse-nav-select
                >
                    <?php foreach ($items as $item) : ?>
                        <option value="<?php echo esc_attr($item['slug']); ?>"<?php selected(!empty($item['current'])); ?>><?php echo esc_html($item['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button sitepulse-module-nav__select-submit"><?php esc_html_e('View', 'sitepulse'); ?></button>
            </div>
        </form>
        <div class="sitepulse-module-nav__scroll">
            <button
                type="button"
                class="sitepulse-module-nav__scroll-button sitepulse-module-nav__scroll-button--prev"
                data-sitepulse-nav-scroll="prev"
                aria-controls="<?php echo esc_attr($nav_list_id); ?>"
                aria-label="<?php esc_attr_e('Scroll navigation left', 'sitepulse'); ?>"
                disabled
            >
                <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
            </button>
            <div class="sitepulse-module-nav__scroll-viewport" data-sitepulse-nav-viewport>
                <ul class="sitepulse-module-nav__list" id="<?php echo esc_attr($nav_list_id); ?>">
                    <?php foreach ($items as $item) :
                        $link_classes = ['sitepulse-module-nav__link'];

                        if (!empty($item['current'])) {
                            $link_classes[] = 'is-current';
                        }

                        $filter_terms = [];

                        if (!empty($item['label'])) {
                            $filter_terms[] = $item['label'];
                        }

                        if (!empty($item['tags']) && is_array($item['tags'])) {
                            foreach ($item['tags'] as $tag) {
                                if (!is_scalar($tag)) {
                                    continue;
                                }

                                $filter_terms[] = (string) $tag;
                            }
                        }

                        $filter_text = trim(implode(' ', array_filter($filter_terms)));

                        if (function_exists('remove_accents')) {
                            $filter_text = remove_accents($filter_text);
                        }

                        $filter_text = strtolower($filter_text);
                    ?>
                        <li
                            class="sitepulse-module-nav__item"
                            data-sitepulse-nav-item
                            data-filter-text="<?php echo esc_attr($filter_text); ?>"
                        >
                            <a class="<?php echo esc_attr(implode(' ', $link_classes)); ?>" href="<?php echo esc_url($item['url']); ?>"<?php echo !empty($item['current']) ? ' aria-current="page"' : ''; ?>>
                                <?php if (!empty($item['icon'])) : ?>
                                    <span class="sitepulse-module-nav__icon dashicons <?php echo esc_attr($item['icon']); ?>" aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="sitepulse-module-nav__label"><?php echo esc_html($item['label']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button
                type="button"
                class="sitepulse-module-nav__scroll-button sitepulse-module-nav__scroll-button--next"
                data-sitepulse-nav-scroll="next"
                aria-controls="<?php echo esc_attr($nav_list_id); ?>"
                aria-label="<?php esc_attr_e('Scroll navigation right', 'sitepulse'); ?>"
                disabled
            >
                <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
            </button>
        </div>
    </nav>
    <?php
}

/**
 * Legacy wrapper for backwards compatibility with older templates.
 *
 * @param string $current_page Current module page slug (e.g. "sitepulse-speed").
 * @return void
 */
function sitepulse_render_module_selector($current_page = '') {
    sitepulse_render_module_navigation($current_page);
}
