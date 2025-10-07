<?php
/**
 * Tests for the dashboard metrics REST endpoint and helpers.
 */

require_once __DIR__ . '/includes/stubs.php';

if (!defined('SITEPULSE_OPTION_DASHBOARD_RANGE')) {
    define('SITEPULSE_OPTION_DASHBOARD_RANGE', 'sitepulse_dashboard_range');
}

if (!defined('SITEPULSE_OPTION_SPEED_SCAN_HISTORY')) {
    define('SITEPULSE_OPTION_SPEED_SCAN_HISTORY', 'sitepulse_speed_scan_history');
}

if (!defined('SITEPULSE_OPTION_UPTIME_ARCHIVE')) {
    define('SITEPULSE_OPTION_UPTIME_ARCHIVE', 'sitepulse_uptime_archive');
}

require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/custom_dashboards.php';

class Sitepulse_Dashboard_Metrics_Api_Test extends WP_UnitTestCase {
    /**
     * @var string|null
     */
    private $logFile;

    protected function setUp(): void {
        parent::setUp();

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));

        delete_option(SITEPULSE_OPTION_DASHBOARD_RANGE);
        delete_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY);
        delete_option(SITEPULSE_OPTION_UPTIME_ARCHIVE);

        $this->logFile = tempnam(sys_get_temp_dir(), 'sitepulse-log');

        if ($this->logFile !== false) {
            file_put_contents(
                $this->logFile,
                "[2024-01-01 00:00:00] PHP Warning: Something happened\n"
                . "[2024-01-01 01:00:00] PHP Deprecated: Old function used\n"
            );
            $GLOBALS['sitepulse_test_log_path'] = $this->logFile;
        }
    }

    protected function tearDown(): void {
        unset($GLOBALS['sitepulse_test_log_path']);

        if (is_string($this->logFile) && $this->logFile !== '' && file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        $this->logFile = null;

        parent::tearDown();
    }

    public function test_metrics_endpoint_returns_payload_and_persists_range(): void {
        $now = time();

        update_option(
            SITEPULSE_OPTION_UPTIME_ARCHIVE,
            [
                date('Y-m-d', $now - DAY_IN_SECONDS) => [
                    'total'        => 24,
                    'up'           => 23,
                    'down'         => 1,
                    'unknown'      => 0,
                    'latency_sum'  => 2400,
                    'latency_count'=> 24,
                    'ttfb_sum'     => 600,
                    'ttfb_count'   => 24,
                    'violations'   => 1,
                ],
                date('Y-m-d', $now) => [
                    'total'        => 24,
                    'up'           => 24,
                    'down'         => 0,
                    'unknown'      => 0,
                    'latency_sum'  => 2200,
                    'latency_count'=> 24,
                    'ttfb_sum'     => 500,
                    'ttfb_count'   => 24,
                    'violations'   => 0,
                ],
            ]
        );

        update_option(
            SITEPULSE_OPTION_SPEED_SCAN_HISTORY,
            [
                ['timestamp' => $now - HOUR_IN_SECONDS * 3, 'server_processing_ms' => 210],
                ['timestamp' => $now - HOUR_IN_SECONDS, 'server_processing_ms' => 180],
                ['timestamp' => $now, 'server_processing_ms' => 160],
            ]
        );

        sitepulse_custom_dashboard_analyze_debug_log(true);

        rest_get_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request('GET', '/sitepulse/v1/metrics');
        $request->set_param('range', '24h');

        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();

        $this->assertSame('24h', $data['range']);
        $this->assertArrayHasKey('available_ranges', $data);
        $this->assertArrayHasKey('uptime', $data);
        $this->assertArrayHasKey('logs', $data);
        $this->assertArrayHasKey('speed', $data);

        $this->assertSame('24h', get_option(SITEPULSE_OPTION_DASHBOARD_RANGE));

        $this->assertArrayHasKey('totals', $data['uptime']);
        $this->assertSame(24, $data['uptime']['totals']['total']);

        $this->assertArrayHasKey('card', $data['logs']);
        $this->assertSame('status-warn', $data['logs']['card']['status']);
        $this->assertSame(1, $data['logs']['card']['counts']['warning']);

        $this->assertArrayHasKey('average', $data['speed']);
        $this->assertNotNull($data['speed']['latest']);
    }

    public function test_invalid_range_falls_back_to_stored_preference(): void {
        update_option(SITEPULSE_OPTION_DASHBOARD_RANGE, '7d');

        rest_get_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request('GET', '/sitepulse/v1/metrics');
        $request->set_param('range', 'invalid');

        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();

        $this->assertSame('7d', $data['range']);
        $this->assertSame('7d', get_option(SITEPULSE_OPTION_DASHBOARD_RANGE));
    }
}

