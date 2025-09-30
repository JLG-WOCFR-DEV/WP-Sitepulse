<?php
/**
 * Tests for rendering the SitePulse custom dashboard page.
 */

require_once __DIR__ . '/includes/stubs.php';

if (!defined('SITEPULSE_OPTION_ACTIVE_MODULES')) {
    define('SITEPULSE_OPTION_ACTIVE_MODULES', 'sitepulse_active_modules');
}

if (!defined('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS')) {
    define('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS', 'sitepulse_speed_scan_results');
}

if (!function_exists('sitepulse_get_capability')) {
    function sitepulse_get_capability() {
        return 'manage_options';
    }
}

require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/custom_dashboards.php';

class Sitepulse_Custom_Dashboard_Render_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
        delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        delete_option(SITEPULSE_OPTION_UPTIME_LOG);
        delete_option(SITEPULSE_OPTION_ACTIVE_MODULES);

        $scripts = wp_scripts();
        $scripts->remove('sitepulse-chartjs');
        $scripts->remove('sitepulse-dashboard-charts');
    }

    public function test_disabling_modules_hides_cards_and_data(): void {
        update_option(SITEPULSE_OPTION_ACTIVE_MODULES, ['custom_dashboards']);

        sitepulse_custom_dashboard_enqueue_assets('toplevel_page_sitepulse-dashboard');

        ob_start();
        sitepulse_custom_dashboards_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('sitepulse-speed-chart', $output);
        $this->assertStringNotContainsString('admin.php?page=sitepulse-speed', $output);
        $this->assertStringNotContainsString('sitepulse-uptime-chart', $output);
        $this->assertStringNotContainsString('admin.php?page=sitepulse-uptime', $output);
        $this->assertStringNotContainsString('sitepulse-database-chart', $output);
        $this->assertStringNotContainsString('admin.php?page=sitepulse-db', $output);
        $this->assertStringNotContainsString('sitepulse-log-chart', $output);
        $this->assertStringNotContainsString('admin.php?page=sitepulse-logs', $output);

        $scripts = wp_scripts();
        $data = $scripts->get_data('sitepulse-dashboard-charts', 'data');
        $this->assertIsString($data);
        $this->assertSame(1, preg_match('/var SitePulseDashboardData = (.*);/', $data, $matches));

        $payload = json_decode($matches[1], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('charts', $payload);
        $this->assertIsArray($payload['charts']);
        $this->assertArrayNotHasKey('speed', $payload['charts']);
        $this->assertArrayNotHasKey('uptime', $payload['charts']);
        $this->assertArrayNotHasKey('database', $payload['charts']);
        $this->assertArrayNotHasKey('logs', $payload['charts']);
    }
}
