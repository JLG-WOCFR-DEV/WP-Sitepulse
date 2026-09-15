<?php
/**
 * Phase 2: module slugs stay reachable without extra sidebar children.
 *
 * @package SitePulse
 */

class Sitepulse_Phase2_Admin_Menu_Access_Test extends PHPUnit\Framework\TestCase {
    private function plugin_root() {
        return dirname(__DIR__, 2) . '/sitepulse_FR';
    }

    public function test_admin_menu_does_not_call_remove_submenu_page() {
        $path = $this->plugin_root() . '/includes/admin-menu.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*remove_submenu_page\s*\(/m',
            $contents,
            'Calling remove_submenu_page unregisters the slug and returns 403 on WP 7.1.'
        );
    }

    public function test_speed_analyzer_page_stays_registered() {
        $path = $this->plugin_root() . '/modules/speed_analyzer.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString("add_submenu_page(", $contents);
        $this->assertStringContainsString("'sitepulse-speed'", $contents);
        $this->assertStringContainsString("'sitepulse-dashboard'", $contents);
    }

    public function test_sitepulse_speed_is_allowed_for_admin_and_hidden_from_visible_submenu() {
        $this->load_admin_menu();

        global $submenu;

        $previous = $submenu ?? null;

        $submenu['sitepulse-dashboard'] = [
            ['Tableau de bord SitePulse', 'manage_options', 'sitepulse-dashboard'],
            ['Réglages', 'manage_options', 'sitepulse-settings'],
            ['Uptime', 'manage_options', 'sitepulse-uptime'],
            ['Speed', 'manage_options', 'sitepulse-speed'],
            ['Resources', 'manage_options', 'sitepulse-resources'],
            ['Plugins', 'manage_options', 'sitepulse-plugins'],
            ['Maintenance', 'manage_options', 'sitepulse-maintenance'],
            ['Logs', 'manage_options', 'sitepulse-logs'],
            ['Database', 'manage_options', 'sitepulse-db'],
            ['AI Insights', 'manage_options', 'sitepulse-ai'],
            ['Débogage', 'manage_options', 'sitepulse-debug'],
        ];

        sitepulse_hide_module_admin_submenus();

        $items = $submenu['sitepulse-dashboard'];
        $speed = $this->find_item($items, 'sitepulse-speed');

        $this->assertNotNull($speed, 'sitepulse-speed must stay registered so admin.php?page=sitepulse-speed is allowed.');
        $this->assertSame(
            'manage_options',
            $speed[1],
            'sitepulse-speed must keep the admin capability.'
        );

        $visible_slugs = $this->visible_slugs($items);

        $this->assertNotContains(
            'sitepulse-speed',
            $visible_slugs,
            'sitepulse-speed must not appear as a visible extra sidebar child.'
        );
        $this->assertContains('sitepulse-dashboard', $visible_slugs);
        $this->assertContains('sitepulse-settings', $visible_slugs);
        $this->assertContains('sitepulse-debug', $visible_slugs);
        $this->assertNotContains('sitepulse-uptime', $visible_slugs);
        $this->assertNotContains('sitepulse-ai', $visible_slugs);

        $submenu = $previous;
    }

    /**
     * @param array<int, array<int, mixed>> $items
     * @return array<int, mixed>|null
     */
    private function find_item(array $items, $slug) {
        foreach ($items as $item) {
            if (is_array($item) && isset($item[2]) && $item[2] === $slug) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<int, mixed>> $items
     * @return array<int, string>
     */
    private function visible_slugs(array $items) {
        $visible = [];

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item[2]) || !is_string($item[2]) || $item[2] === '') {
                continue;
            }

            $title = isset($item[0]) ? trim(wp_strip_all_tags((string) $item[0])) : '';
            $class = isset($item[4]) ? (string) $item[4] : '';

            if ($title === '') {
                continue;
            }

            if (preg_match('/(?:^|\s)hidden(?:\s|$)/', $class)) {
                continue;
            }

            $visible[] = $item[2];
        }

        return $visible;
    }

    private function load_admin_menu() {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }

        if (!defined('SITEPULSE_DEBUG')) {
            define('SITEPULSE_DEBUG', true);
        }

        if (!function_exists('__')) {
            function __($text, $domain = null) {
                return $text;
            }
        }

        if (!function_exists('add_action')) {
            function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
            }
        }

        if (!function_exists('apply_filters')) {
            function apply_filters($hook, $value) {
                return $value;
            }
        }

        if (!function_exists('sanitize_key')) {
            function sanitize_key($key) {
                $key = strtolower((string) $key);

                return preg_replace('/[^a-z0-9_\-]/', '', $key);
            }
        }

        if (!function_exists('wp_strip_all_tags')) {
            function wp_strip_all_tags($string) {
                return trim(strip_tags((string) $string));
            }
        }

        require_once $this->plugin_root() . '/includes/admin-menu.php';
    }
}
