<?php
/**
 * Tests for the SitePulse custom dashboard asset registration.
 */

require_once __DIR__ . '/includes/stubs.php';

require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/custom_dashboards.php';

class Sitepulse_Custom_Dashboard_Assets_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['sitepulse_logger'] = [];
        remove_all_filters('sitepulse_chartjs_src');

        $scripts = wp_scripts();
        $scripts->remove('sitepulse-chartjs');
        $scripts->remove('sitepulse-dashboard-charts');
    }

    public function test_invalid_chartjs_url_is_rejected(): void {
        add_filter('sitepulse_chartjs_src', static function () {
            return 'http://malicious.example.com/chart.js';
        });

        sitepulse_custom_dashboard_enqueue_assets('toplevel_page_sitepulse-dashboard');

        $scripts = wp_scripts();
        $this->assertArrayHasKey('sitepulse-chartjs', $scripts->registered);
        $this->assertSame(
            SITEPULSE_URL . 'modules/vendor/chart.js/chart.umd.js',
            $scripts->registered['sitepulse-chartjs']->src
        );

        $this->assertNotEmpty($GLOBALS['sitepulse_logger']);
        $this->assertSame('DEBUG', $GLOBALS['sitepulse_logger'][0]['level']);
        $this->assertStringContainsString('invalid Chart.js source override rejected', $GLOBALS['sitepulse_logger'][0]['message']);
    }

    public function test_https_chartjs_url_is_accepted(): void {
        $custom_src = 'https://cdn.example.com/chart.js';

        add_filter('sitepulse_chartjs_src', static function () use ($custom_src) {
            return $custom_src;
        });

        sitepulse_custom_dashboard_enqueue_assets('toplevel_page_sitepulse-dashboard');

        $scripts = wp_scripts();
        $this->assertArrayHasKey('sitepulse-chartjs', $scripts->registered);
        $this->assertSame($custom_src, $scripts->registered['sitepulse-chartjs']->src);
        $this->assertEmpty($GLOBALS['sitepulse_logger']);
    }
}
