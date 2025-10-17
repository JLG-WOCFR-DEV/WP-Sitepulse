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
            'category'         => 'overview',
            'always_available' => true,
        ],
        'speed_analyzer' => [
            'page'  => 'sitepulse-speed',
            'label' => __('Speed', 'sitepulse'),
            'icon'  => 'dashicons-performance',
            'tags'  => ['performance', 'web vitals', 'ttfb', 'lcp'],
            'category' => 'performance',
        ],
        'uptime_tracker' => [
            'page'  => 'sitepulse-uptime',
            'label' => __('Uptime', 'sitepulse'),
            'icon'  => 'dashicons-chart-bar',
            'tags'  => ['availability', 'incidents', 'sla'],
            'category' => 'observability',
        ],
        'database_optimizer' => [
            'page'  => 'sitepulse-db',
            'label' => __('Database', 'sitepulse'),
            'icon'  => 'dashicons-database',
            'tags'  => ['sql', 'cleanup', 'optimization'],
            'category' => 'maintenance',
        ],
        'log_analyzer' => [
            'page'  => 'sitepulse-logs',
            'label' => __('Logs', 'sitepulse'),
            'icon'  => 'dashicons-hammer',
            'tags'  => ['errors', 'debug', 'php'],
            'category' => 'observability',
        ],
        'resource_monitor' => [
            'page'  => 'sitepulse-resources',
            'label' => __('Resources', 'sitepulse'),
            'icon'  => 'dashicons-chart-area',
            'tags'  => ['infrastructure', 'cpu', 'memory'],
            'category' => 'observability',
        ],
        'plugin_impact_scanner' => [
            'page'  => 'sitepulse-plugins',
            'label' => __('Plugins', 'sitepulse'),
            'icon'  => 'dashicons-admin-plugins',
            'tags'  => ['extensions', 'weight', 'load'],
            'category' => 'performance',
        ],
        'maintenance_advisor' => [
            'page'  => 'sitepulse-maintenance',
            'label' => __('Maintenance', 'sitepulse'),
            'icon'  => 'dashicons-admin-tools',
            'tags'  => ['updates', 'security', 'housekeeping'],
            'category' => 'maintenance',
        ],
        'ai_insights' => [
            'page'  => 'sitepulse-ai',
            'label' => __('AI Insights', 'sitepulse'),
            'icon'  => 'dashicons-lightbulb',
            'tags'  => ['automation', 'recommendations', 'content'],
            'category' => 'automation',
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
 * Returns the available module categories mapped to their translated labels.
 *
 * @return array<string,string>
 */
function sitepulse_get_module_selector_categories() {
    $categories = [
        'overview'      => __('Vue d’ensemble', 'sitepulse'),
        'performance'   => __('Performance', 'sitepulse'),
        'observability' => __('Observabilité', 'sitepulse'),
        'maintenance'   => __('Maintenance', 'sitepulse'),
        'automation'    => __('Automatisation', 'sitepulse'),
        'other'         => __('Autres modules', 'sitepulse'),
    ];

    /**
     * Filters the module selector categories.
     *
     * @param array $categories Associative array of category slugs to labels.
     */
    return apply_filters('sitepulse_module_selector_categories', $categories);
}

/**
 * Returns the preferred order of module categories.
 *
 * @return array<int,string>
 */
function sitepulse_get_module_selector_category_order() {
    $order = ['favorites', 'overview', 'performance', 'observability', 'maintenance', 'automation', 'other'];

    /**
     * Filters the module selector category order.
     *
     * @param array $order Ordered list of category slugs.
     */
    return apply_filters('sitepulse_module_selector_category_order', $order);
}

/**
 * Returns the user meta key used to persist module usage counts.
 *
 * @return string
 */
function sitepulse_get_module_selector_usage_meta_key() {
    return 'sitepulse_module_usage_counts';
}

/**
 * Fetches the module usage counts for the current user.
 *
 * @return array<string,int>
 */
function sitepulse_get_module_selector_usage_counts() {
    if (!function_exists('get_current_user_id') || !function_exists('get_user_meta')) {
        return [];
    }

    $user_id = get_current_user_id();

    if (!$user_id) {
        return [];
    }

    $raw = get_user_meta($user_id, sitepulse_get_module_selector_usage_meta_key(), true);

    if (!is_array($raw)) {
        return [];
    }

    $counts = [];

    foreach ($raw as $slug => $value) {
        if (!is_scalar($slug) || !is_numeric($value)) {
            continue;
        }

        $counts[(string) $slug] = max(0, (int) $value);
    }

    return $counts;
}

/**
 * Records the visit of a module page for the current user.
 *
 * @param string $page_slug Current module page slug.
 * @return void
 */
function sitepulse_module_selector_record_visit($page_slug) {
    if (!function_exists('get_current_user_id') || !function_exists('get_user_meta') || !function_exists('update_user_meta')) {
        return;
    }

    $user_id = get_current_user_id();

    if (!$user_id) {
        return;
    }

    if (function_exists('sanitize_key')) {
        $slug = sanitize_key($page_slug);
    } else {
        $slug = strtolower(preg_replace('/[^a-z0-9_-]+/', '-', (string) $page_slug));
        $slug = trim($slug, '-');
    }

    if ($slug === '') {
        return;
    }

    $meta_key = sitepulse_get_module_selector_usage_meta_key();
    $counts   = sitepulse_get_module_selector_usage_counts();

    $counts[$slug] = isset($counts[$slug]) ? $counts[$slug] + 1 : 1;

    arsort($counts, SORT_NUMERIC);
    $counts = array_slice($counts, 0, 12, true);

    update_user_meta($user_id, $meta_key, $counts);
}

/**
 * Builds the list of enabled module selector items including URLs.
 *
 * @return array<int, array{slug:string,label:string,url:string,icon:string,tags:array<int, string>,category:string }>
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

        $category = isset($definition['category']) ? (string) $definition['category'] : '';

        if ($category === '') {
            $category = 'other';
        }

        $items[] = [
            'slug'  => $page_slug,
            'label' => $label,
            'url'   => admin_url('admin.php?page=' . $page_slug),
            'icon'  => $icon,
            'tags'  => $tags,
            'category' => $category,
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
 * Ensures the module navigation assets are registered and enqueued.
 *
 * @return void
 */
function sitepulse_enqueue_module_navigation_assets() {
    $style_handle  = 'sitepulse-module-navigation';
    $script_handle = 'sitepulse-dashboard-nav';

    if (!wp_style_is('sitepulse-dashboard-theme', 'registered')) {
        wp_register_style(
            'sitepulse-dashboard-theme',
            SITEPULSE_URL . 'modules/css/sitepulse-theme.css',
            [],
            SITEPULSE_VERSION
        );
    }

    wp_enqueue_style('sitepulse-dashboard-theme');

    if (!wp_style_is($style_handle, 'registered')) {
        wp_register_style(
            $style_handle,
            SITEPULSE_URL . 'modules/css/module-navigation.css',
            ['sitepulse-dashboard-theme'],
            SITEPULSE_VERSION
        );
    }

    wp_enqueue_style($style_handle);

    if (!wp_script_is($script_handle, 'registered')) {
        wp_register_script(
            $script_handle,
            SITEPULSE_URL . 'modules/js/sitepulse-dashboard-nav.js',
            ['wp-i18n'],
            SITEPULSE_VERSION,
            true
        );
    }

    wp_enqueue_script($script_handle);
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

    sitepulse_enqueue_module_navigation_assets();
}
add_action('admin_enqueue_scripts', 'sitepulse_module_selector_enqueue_style');

/**
 * Builds the navigation items for the module selector, marking the current page.
 *
 * @param string $current_page Current module page slug (e.g. "sitepulse-speed").
 * @return array<int, array{slug:string,label:string,items:array<int,array<string,mixed>>}>
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

    $categories = sitepulse_get_module_selector_categories();
    $category_order = sitepulse_get_module_selector_category_order();

    foreach ($items as $index => $item) {
        $slug = isset($item['slug']) ? $sanitizer($item['slug']) : '';
        $items[$index]['current'] = ($slug !== '' && $slug === $current_page);

        $category_slug = isset($item['category']) ? (string) $item['category'] : 'other';

        if (!isset($categories[$category_slug])) {
            $category_slug = 'other';
        }

        $items[$index]['category'] = $category_slug;
        $items[$index]['category_label'] = $categories[$category_slug];
    }

    usort(
        $items,
        static function ($a, $b) use ($category_order) {
            $a_category = $a['category'] ?? 'other';
            $b_category = $b['category'] ?? 'other';
            $a_category_position = array_search($a_category, $category_order, true);
            $b_category_position = array_search($b_category, $category_order, true);

            if ($a_category_position === false) {
                $a_category_position = PHP_INT_MAX;
            }

            if ($b_category_position === false) {
                $b_category_position = PHP_INT_MAX;
            }

            if ($a_category_position !== $b_category_position) {
                return $a_category_position < $b_category_position ? -1 : 1;
            }

            $a_label = isset($a['label']) ? (string) $a['label'] : '';
            $b_label = isset($b['label']) ? (string) $b['label'] : '';

            return strcasecmp($a_label, $b_label);
        }
    );

    $grouped = [];

    $buckets = [];

    foreach ($items as $item) {
        $category_slug = $item['category'] ?? 'other';
        $bucket_key = isset($categories[$category_slug]) ? $category_slug : 'other';

        if (!isset($buckets[$bucket_key])) {
            $buckets[$bucket_key] = [
                'slug'  => $bucket_key,
                'label' => $categories[$bucket_key],
                'items' => [],
            ];
        }

        $buckets[$bucket_key]['items'][] = $item;
    }

    $order_map = array_flip($category_order);

    uasort(
        $buckets,
        static function ($a, $b) use ($order_map) {
            $a_pos = isset($order_map[$a['slug']]) ? $order_map[$a['slug']] : PHP_INT_MAX;
            $b_pos = isset($order_map[$b['slug']]) ? $order_map[$b['slug']] : PHP_INT_MAX;

            if ($a_pos !== $b_pos) {
                return $a_pos < $b_pos ? -1 : 1;
            }

            return strcasecmp($a['label'], $b['label']);
        }
    );

    foreach ($buckets as $bucket) {
        if (empty($bucket['items'])) {
            continue;
        }

        $grouped[] = $bucket;
    }

    /**
     * Filters the prepared module navigation groups.
     *
     * @param array  $grouped      Grouped navigation entries.
     * @param string $current_page Current page slug.
     */
    return apply_filters('sitepulse_module_navigation_items', $grouped, $current_page);
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

    sitepulse_enqueue_module_navigation_assets();

    $category_counts  = [];
    $category_labels  = [];
    $ordered_categories = [];
    $current_category = 'all';

    foreach ($items as $group) {
        if (empty($group['items']) || !is_array($group['items'])) {
            continue;
        }

        $group_slug = isset($group['slug']) ? (string) $group['slug'] : '';

        if ($group_slug === '') {
            continue;
        }

        if (!in_array($group_slug, $ordered_categories, true)) {
            $ordered_categories[] = $group_slug;
        }

        $category_counts[$group_slug] = count($group['items']);
        $category_labels[$group_slug] = isset($group['label']) ? (string) $group['label'] : '';

        if ($current_category !== 'all') {
            continue;
        }

        foreach ($group['items'] as $group_item) {
            if (!empty($group_item['current'])) {
                $current_category = $group_slug;
                break;
            }
        }
    }

    if ($current_category === '' || $current_category === null) {
        $current_category = 'all';
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

    $flat_items = [];

    foreach ($items as $group) {
        if (!isset($group['items']) || !is_array($group['items'])) {
            continue;
        }

        foreach ($group['items'] as $item) {
            $flat_items[] = $item;
        }
    }

    $total_items = count($flat_items);

    $nav_default_category = $current_category !== 'all' ? $current_category : 'all';

    ?>
    <nav
        class="sitepulse-module-nav"
        aria-label="<?php esc_attr_e('SitePulse sections', 'sitepulse'); ?>"
        data-sitepulse-nav-default-category="<?php echo esc_attr($nav_default_category); ?>"
    >
        <?php if (!empty($ordered_categories)) : ?>
            <div class="sitepulse-module-nav__categories" role="toolbar" aria-label="<?php esc_attr_e('Filter modules by category', 'sitepulse'); ?>">
                <button
                    type="button"
                    class="sitepulse-module-nav__category-button<?php echo $nav_default_category === 'all' ? ' is-active' : ''; ?>"
                    data-sitepulse-nav-category="all"
                    aria-pressed="<?php echo $nav_default_category === 'all' ? 'true' : 'false'; ?>"
                >
                    <span class="sitepulse-module-nav__category-label"><?php esc_html_e('All modules', 'sitepulse'); ?></span>
                    <span class="sitepulse-module-nav__category-count"><?php echo (int) $total_items; ?></span>
                </button>
                <?php foreach ($ordered_categories as $category_slug) :
                    $category_label = isset($category_labels[$category_slug]) ? $category_labels[$category_slug] : '';
                    $category_count = isset($category_counts[$category_slug]) ? (int) $category_counts[$category_slug] : 0;

                    if ($category_label === '' || $category_count <= 0) {
                        continue;
                    }
                    ?>
                    <button
                        type="button"
                        class="sitepulse-module-nav__category-button<?php echo $nav_default_category === $category_slug ? ' is-active' : ''; ?>"
                        data-sitepulse-nav-category="<?php echo esc_attr($category_slug); ?>"
                        aria-pressed="<?php echo $nav_default_category === $category_slug ? 'true' : 'false'; ?>"
                    >
                        <span class="sitepulse-module-nav__category-label"><?php echo esc_html($category_label); ?></span>
                        <span class="sitepulse-module-nav__category-count"><?php echo (int) $category_count; ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
                    <?php foreach ($items as $group) :
                        if (empty($group['items']) || !is_array($group['items'])) {
                            continue;
                        }

                        $group_label = isset($group['label']) ? (string) $group['label'] : '';
                    ?>
                        <optgroup label="<?php echo esc_attr($group_label); ?>">
                            <?php foreach ($group['items'] as $item) : ?>
                                <option value="<?php echo esc_attr($item['slug']); ?>"<?php selected(!empty($item['current'])); ?>><?php echo esc_html($item['label']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
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
                    <?php foreach ($items as $group) :
                        if (empty($group['items']) || !is_array($group['items'])) {
                            continue;
                        }

                        $group_label = isset($group['label']) ? (string) $group['label'] : '';
                        $group_slug  = isset($group['slug']) ? (string) $group['slug'] : '';
                    ?>
                        <li class="sitepulse-module-nav__group" role="presentation" data-sitepulse-nav-group="<?php echo esc_attr($group_slug); ?>">
                            <span class="sitepulse-module-nav__group-label"><?php echo esc_html($group_label); ?></span>
                            <ul class="sitepulse-module-nav__group-list">
                                <?php foreach ($group['items'] as $item) :
                                    $link_classes = ['sitepulse-module-nav__link'];

                                    if (!empty($item['current'])) {
                                        $link_classes[] = 'is-current';
                                    }

                                    $filter_terms = [];

                                    if (!empty($item['label'])) {
                                        $filter_terms[] = $item['label'];
                                    }

                                    if (!empty($item['category_label'])) {
                                        $filter_terms[] = $item['category_label'];
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
                                        data-category="<?php echo esc_attr($group['slug']); ?>"
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
