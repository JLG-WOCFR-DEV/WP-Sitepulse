<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the definitions for SitePulse module pages that should appear in the selector.
 *
 * @return array<string, array{page:string,label:string}>
 */
function sitepulse_get_module_selector_definitions() {
    $definitions = [
        'speed_analyzer' => [
            'page'  => 'sitepulse-speed',
            'label' => __('Speed Analyzer', 'sitepulse'),
        ],
        'uptime_tracker' => [
            'page'  => 'sitepulse-uptime',
            'label' => __('Uptime Tracker', 'sitepulse'),
        ],
        'plugin_impact_scanner' => [
            'page'  => 'sitepulse-plugins',
            'label' => __('Plugin Impact Scanner', 'sitepulse'),
        ],
        'resource_monitor' => [
            'page'  => 'sitepulse-resources',
            'label' => __('Resource Monitor', 'sitepulse'),
        ],
        'database_optimizer' => [
            'page'  => 'sitepulse-db',
            'label' => __('Database Optimizer', 'sitepulse'),
        ],
        'maintenance_advisor' => [
            'page'  => 'sitepulse-maintenance',
            'label' => __('Maintenance Advisor', 'sitepulse'),
        ],
        'log_analyzer' => [
            'page'  => 'sitepulse-logs',
            'label' => __('Log Analyzer', 'sitepulse'),
        ],
        'ai_insights' => [
            'page'  => 'sitepulse-ai',
            'label' => __('AI Insights', 'sitepulse'),
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
 * @return array<int, array{slug:string,label:string,url:string}>
 */
function sitepulse_get_module_selector_items() {
    $active_modules = array_map('strval', (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []));
    $definitions    = sitepulse_get_module_selector_definitions();
    $items          = [];

    foreach ($active_modules as $module_key) {
        if (!isset($definitions[$module_key])) {
            continue;
        }

        $definition = $definitions[$module_key];
        $page_slug  = isset($definition['page']) ? (string) $definition['page'] : '';
        $label      = isset($definition['label']) ? $definition['label'] : '';

        if ($page_slug === '' || $label === '') {
            continue;
        }

        $items[] = [
            'slug'  => $page_slug,
            'label' => $label,
            'url'   => admin_url('admin.php?page=' . $page_slug),
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

    wp_enqueue_style(
        'sitepulse-module-selector',
        SITEPULSE_URL . 'modules/css/module-selector.css',
        [],
        SITEPULSE_VERSION
    );
}
add_action('admin_enqueue_scripts', 'sitepulse_module_selector_enqueue_style');

/**
 * Outputs the module selector navigation component.
 *
 * @param string $current_page Current module page slug (e.g. "sitepulse-speed").
 * @return void
 */
function sitepulse_render_module_selector($current_page = '') {
    $items = sitepulse_get_module_selector_items();

    if (empty($items)) {
        return;
    }

    $current_page = is_string($current_page) ? $current_page : '';

    static $instance = 0;
    $instance++;

    $select_id = 'sitepulse-module-selector-' . $instance;
    $label_id  = $select_id . '-label';
    $action    = admin_url('admin.php');
    ?>
    <nav class="sitepulse-module-selector" aria-label="<?php echo esc_attr__('SitePulse module navigation', 'sitepulse'); ?>">
        <form class="sitepulse-module-selector__form" action="<?php echo esc_url($action); ?>" method="get">
            <label id="<?php echo esc_attr($label_id); ?>" for="<?php echo esc_attr($select_id); ?>">
                <?php esc_html_e('Jump to module', 'sitepulse'); ?>
            </label>
            <div class="sitepulse-module-selector__controls">
                <select
                    name="page"
                    id="<?php echo esc_attr($select_id); ?>"
                    class="sitepulse-module-selector__select"
                    aria-describedby="<?php echo esc_attr($label_id); ?>"
                    onchange="if(this.value){this.form.submit();}"
                >
                    <option value="">
                        <?php esc_html_e('Select a module', 'sitepulse'); ?>
                    </option>
                    <?php foreach ($items as $item) : ?>
                        <option value="<?php echo esc_attr($item['slug']); ?>" <?php selected($current_page, $item['slug']); ?>>
                            <?php echo esc_html($item['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="sitepulse-module-selector__submit">
                    <?php esc_html_e('Open module', 'sitepulse'); ?>
                </button>
            </div>
        </form>
    </nav>
    <?php
}
