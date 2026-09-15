<?php
/**
 * Phase 2: WordPress 7.1 headers and wp-admin charter (static checks).
 *
 * @package SitePulse
 */

class Sitepulse_Phase2_Wp71_Charter_Test extends PHPUnit\Framework\TestCase {
    private function plugin_root() {
        return dirname(__DIR__, 2) . '/sitepulse_FR';
    }

    private function read($relative) {
        $path = $this->plugin_root() . '/' . ltrim($relative, '/');
        $this->assertFileExists($path, $relative . ' should exist.');

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, $relative . ' should be readable.');

        return $contents;
    }

    public function test_plugin_headers_declare_wordpress_71() {
        $header = $this->read('sitepulse.php');

        $this->assertMatchesRegularExpression(
            '/^\s*\*\s*Requires at least:\s*5\.0/m',
            $header,
            'The plugin header must declare Requires at least: 5.0.'
        );
        $this->assertMatchesRegularExpression(
            '/^\s*\*\s*Tested up to:\s*7\.1/m',
            $header,
            'The plugin header must declare Tested up to: 7.1.'
        );
        $this->assertMatchesRegularExpression(
            '/^\s*\*\s*Requires PHP:\s*7\.1/m',
            $header,
            'The plugin header must keep Requires PHP: 7.1.'
        );
    }

    public function test_readme_files_declare_wordpress_71() {
        foreach (['readme.txt', 'README.md'] as $file) {
            $contents = $this->read($file);
            $this->assertStringContainsString(
                'Tested up to: 7.1',
                $contents,
                $file . ' must declare Tested up to: 7.1.'
            );
            $this->assertStringContainsString(
                'Requires at least: 5.0',
                $contents,
                $file . ' must declare Requires at least: 5.0.'
            );
        }
    }

    public function test_dashboard_preview_block_is_api_version_3() {
        $json = $this->read('blocks/dashboard-preview/block.json');
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertSame(3, (int) $data['apiVersion']);
    }

    public function test_settings_page_uses_wp_admin_charter_markup() {
        $settings = $this->read('includes/admin-settings-page.php');

        $this->assertStringContainsString('class="wrap', $settings);
        $this->assertStringContainsString('<h1>', $settings);
        $this->assertStringContainsString('nav-tab-wrapper', $settings);
        $this->assertStringContainsString('nav-tab', $settings);
        $this->assertStringContainsString('settings_fields(\'sitepulse_settings\')', $settings);
        $this->assertStringContainsString('class="form-table"', $settings);
        $this->assertStringContainsString('submit_button(', $settings);
        $this->assertStringContainsString('notice notice-', $settings);
        $this->assertStringContainsString('button-primary', $settings);
    }

    public function test_settings_are_registered_with_settings_api() {
        $admin = $this->read('includes/admin-settings.php');

        $this->assertStringContainsString('function sitepulse_register_settings()', $admin);
        $this->assertStringContainsString('register_setting(\'sitepulse_settings\'', $admin);
        $this->assertStringContainsString("add_action('admin_init', 'sitepulse_register_settings')", $admin);
    }

    public function test_module_navigation_uses_native_nav_tabs() {
        $nav = $this->read('includes/module-selector.php');

        $this->assertStringContainsString('nav-tab-wrapper', $nav);
        $this->assertStringContainsString('nav-tab-active', $nav);
        $this->assertStringContainsString("__('Tableau de bord'", $nav);
        $this->assertStringContainsString("__('Réglages'", $nav);
    }

    public function test_admin_menu_labels_are_french() {
        $menu = $this->read('includes/admin-menu.php');

        $this->assertStringContainsString("__('Tableau de bord SitePulse'", $menu);
        $this->assertStringContainsString("__('Réglages'", $menu);
        $this->assertStringNotContainsString("__('Settings'", $menu);
        $this->assertStringNotContainsString("__('SitePulse Dashboard'", $menu);
    }

    public function test_admin_css_does_not_restyle_wp_chrome() {
        $theme = $this->read('modules/css/sitepulse-theme.css');
        $presets = $this->read('modules/css/appearance-presets.css');
        $settings = $this->read('modules/css/admin-settings.css');

        $this->assertStringNotContainsString('#wpbody-content', $theme);
        $this->assertStringNotContainsString('#wpcontent', $theme);
        $this->assertDoesNotMatchRegularExpression(
            '/\.sitepulse-theme\s+\.button-primary\s*\{/',
            $theme,
            'Theme CSS must not restyle core .button-primary.'
        );

        $this->assertStringNotContainsString('--wp-admin-theme-color:', $presets);
        $this->assertStringNotContainsString('.sitepulse-card .button-primary', $presets);

        $this->assertDoesNotMatchRegularExpression(
            '/\.sitepulse-guided-checklist__action\.button-primary\s*\{/',
            $settings,
            'Settings CSS must not restyle core primary buttons.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.sitepulse-settings-actions__buttons\s+\.button\.button-primary\s*\{/',
            $settings,
            'Settings CSS must not restyle the primary submit button.'
        );
    }

    public function test_appearance_presets_do_not_hook_the_wp_dashboard() {
        $presets = $this->read('includes/appearance-presets.php');

        $this->assertStringNotContainsString("\$screen_id === 'dashboard'", $presets);
    }

    public function test_dashboard_and_debug_pages_use_wrap_and_h1() {
        $dashboard = $this->read('modules/dashboard/page.php');
        $debug = $this->read('includes/admin-debug-page.php');

        $this->assertStringContainsString('<div class="wrap">', $dashboard);
        $this->assertStringContainsString('<h1>', $dashboard);
        $this->assertStringContainsString("esc_html_e('Tableau de bord SitePulse'", $dashboard);
        $this->assertStringContainsString('sitepulse_render_module_navigation', $dashboard);

        $this->assertStringContainsString('class="wrap"', $debug);
        $this->assertStringContainsString('<h1>', $debug);
        $this->assertStringContainsString('notice notice-info', $debug);
        $this->assertStringContainsString("esc_html_e('Débogage SitePulse'", $debug);
    }

    public function test_module_pages_render_nav_inside_wrap() {
        $pages = [
            'modules/log-analyzer/page.php',
            'modules/speed-analyzer/page.php',
            'modules/uptime/page.php',
            'modules/plugin-impact/page.php',
            'modules/resource-monitor/page.php',
            'modules/maintenance_advisor.php',
            'modules/database_optimizer.php',
            'modules/ai-insights/page.php',
        ];

        foreach ($pages as $relative) {
            $contents = $this->read($relative);
            $wrap_pos = strpos($contents, 'class="wrap');
            $nav_pos  = strpos($contents, 'sitepulse_render_module_selector');

            $this->assertNotFalse($wrap_pos, $relative . ' must output .wrap.');
            $this->assertNotFalse($nav_pos, $relative . ' must render the module selector.');
            $this->assertGreaterThan(
                $wrap_pos,
                $nav_pos,
                $relative . ' must render nav-tab navigation inside .wrap, after the wrap opens.'
            );
        }
    }
}
