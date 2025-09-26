<?php
/**
 * Tests for the uptime tracker module.
 */

class Sitepulse_Uptime_Tracker_Test extends WP_UnitTestCase {
    /**
     * Queue of mocked HTTP responses.
     *
     * @var array
     */
    private $http_queue = [];

    /**
     * Registers the module under test.
     */
    public static function wpSetUpBeforeClass($factory) {
        $module = dirname(__DIR__, 2) . '/sitepulse_FR/modules/uptime_tracker.php';
        require_once $module;
    }

    protected function set_up(): void {
        parent::set_up();

        $this->http_queue = [];
        $GLOBALS['sitepulse_logger'] = [];

        add_filter('pre_http_request', [$this, 'mock_http_request'], 10, 3);
        add_filter('sitepulse_uptime_consecutive_failures', [$this, 'force_failure_threshold'], 10, 2);

        delete_option(SITEPULSE_OPTION_UPTIME_LOG);
        delete_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK);
    }

    protected function tear_down(): void {
        remove_filter('pre_http_request', [$this, 'mock_http_request'], 10);
        remove_filter('sitepulse_uptime_consecutive_failures', [$this, 'force_failure_threshold'], 10);

        delete_option(SITEPULSE_OPTION_UPTIME_LOG);
        delete_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK);

        parent::tear_down();
    }

    /**
     * Forces the uptime tracker to escalate on the second failure.
     *
     * @param int $default Default threshold.
     * @return int
     */
    public function force_failure_threshold($default, $streak = 0) {
        return 2;
    }

    /**
     * Supplies deterministic HTTP responses to the uptime tracker.
     *
     * @return mixed
     */
    public function mock_http_request($preempt, $args, $url) {
        if (empty($this->http_queue)) {
            $this->fail('Unexpected HTTP request; the queue is empty.');
        }

        return array_shift($this->http_queue);
    }

    /**
     * Adds a mocked HTTP response.
     *
     * @param mixed $response Response to enqueue.
     */
    private function enqueue_response($response) {
        $this->http_queue[] = $response;
    }

    public function test_uptime_tracker_handles_network_errors_and_recovery() {
        $this->enqueue_response(new WP_Error('timeout', 'Request timeout'));
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertCount(1, $log, 'Expected one log entry after first WP_Error.');
        $this->assertSame('unknown', $log[0]['status']);
        $this->assertSame('Request timeout', $log[0]['error']);
        $this->assertArrayNotHasKey('incident_start', $log[0], 'Unknown status should not record an incident start.');
        $this->assertSame(1, get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0));

        $last_log = end($GLOBALS['sitepulse_logger']);
        $this->assertSame('WARNING', $last_log['level']);

        $this->enqueue_response(new WP_Error('timeout', 'Request timeout'));
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertCount(2, $log, 'Expected two log entries after consecutive WP_Error.');
        $this->assertSame(2, get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0));

        $last_log = end($GLOBALS['sitepulse_logger']);
        $this->assertSame('ALERT', $last_log['level']);

        $this->enqueue_response(['response' => ['code' => 200]]);
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertTrue(end($log)['status']);
        $this->assertSame(0, get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0));
    }

    public function test_mixed_outage_preserves_incident_start_across_unknown_samples() {
        $this->enqueue_response(['response' => ['code' => 500]]);
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertFalse($log[0]['status']);
        $this->assertArrayHasKey('incident_start', $log[0]);
        $incident_start = $log[0]['incident_start'];

        $this->enqueue_response(new WP_Error('timeout', 'Temporary glitch'));
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertSame('unknown', $log[1]['status']);
        $this->assertArrayNotHasKey('incident_start', $log[1]);

        $this->enqueue_response(['response' => ['code' => 500]]);
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertFalse($log[2]['status']);
        $this->assertSame($incident_start, $log[2]['incident_start']);
    }
}
