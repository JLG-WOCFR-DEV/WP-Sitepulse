<?php
/**
 * Tests for the SitePulse request profiler helpers.
 */

class Sitepulse_Request_Profiler_Test extends WP_UnitTestCase {
    public static function wpSetUpBeforeClass($factory) {
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/functions.php';
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/request-profiler.php';
    }

    protected function set_up(): void {
        parent::set_up();

        update_option(SITEPULSE_OPTION_REQUEST_PROFILER_HISTORY, []);
    }

    protected function tear_down(): void {
        delete_option(SITEPULSE_OPTION_REQUEST_PROFILER_HISTORY);
        parent::tear_down();
    }

    public function test_store_trace_limits_history_length() {
        $limit = SITEPULSE_DEFAULT_REQUEST_PROFILER_HISTORY_LIMIT;

        for ($i = 0; $i < $limit + 2; $i++) {
            sitepulse_request_profiler_store_trace([
                'timestamp'      => $i,
                'url'            => 'https://example.com/?id=' . $i,
                'duration_ms'    => 100 + $i,
                'memory_peak_mb' => 50,
                'query_count'    => 10 + $i,
                'slow_queries'   => [],
                'user_id'        => 1,
            ]);
        }

        $history = get_option(SITEPULSE_OPTION_REQUEST_PROFILER_HISTORY, []);

        $this->assertCount($limit, $history, 'The history should keep the most recent entries only.');
        $this->assertSame($limit + 1, $history[0]['timestamp'], 'New traces must be prepended.');
    }

    public function test_get_last_trace_for_user_prioritises_transient() {
        $user_id = self::factory()->user->create(['role' => 'administrator']);

        $trace = [
            'timestamp'      => time(),
            'url'            => 'https://example.com/admin.php',
            'duration_ms'    => 250.5,
            'memory_peak_mb' => 64.0,
            'query_count'    => 42,
            'slow_queries'   => [
                ['sql' => 'SELECT 1', 'time_ms' => 12.3],
            ],
            'user_id'        => $user_id,
        ];

        sitepulse_request_profiler_cache_last_trace($trace);

        $fetched = sitepulse_request_profiler_get_last_trace_for_user($user_id);

        $this->assertNotNull($fetched, 'A cached trace should be returned.');
        $this->assertSame($trace['query_count'], $fetched['query_count']);
        $this->assertSame($trace['slow_queries'][0]['sql'], $fetched['slow_queries'][0]['sql']);
    }
}
