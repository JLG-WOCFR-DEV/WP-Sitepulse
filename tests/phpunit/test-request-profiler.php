<?php
/**
 * Tests for the request profiler helpers.
 */

require_once __DIR__ . '/includes/stubs.php';

class Sitepulse_Request_Profiler_Test extends WP_UnitTestCase {
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        require_once SITEPULSE_PATH . 'includes/request-profiler.php';
    }

    protected function setUp(): void {
        parent::setUp();

        if (function_exists('sitepulse_request_profiler_maybe_upgrade_schema')) {
            sitepulse_request_profiler_maybe_upgrade_schema();
        }

        $table = function_exists('sitepulse_request_profiler_get_table_name')
            ? sitepulse_request_profiler_get_table_name()
            : '';

        if ($table !== '') {
            global $wpdb;

            if ($wpdb instanceof wpdb) {
                $wpdb->query("TRUNCATE TABLE {$table}");
            }
        }
    }

    public function test_prepare_hooks_filters_invalid_entries(): void {
        $hooks = [
            'valid_hook' => ['count' => 2, 'total' => 0.5, 'max' => 0.4],
            'ignored_zero_total' => ['count' => 3, 'total' => 0.0, 'max' => 0.1],
            '' => ['count' => 1, 'total' => 1.2, 'max' => 1.2],
            'top_hook' => ['count' => 1, 'total' => 1.8, 'max' => 1.8],
        ];

        $prepared = sitepulse_request_profiler_prepare_hooks($hooks);

        $this->assertCount(2, $prepared);
        $this->assertSame('top_hook', $prepared[0]['hook']);
        $this->assertSame('valid_hook', $prepared[1]['hook']);
        $this->assertEqualsWithDelta(0.25, $prepared[1]['avg_time'], 0.0001);
    }

    public function test_prepare_queries_groups_duplicates(): void {
        $queries = [
            ['SELECT * FROM wp_posts WHERE ID = 1', 0.012, 'get_post'],
            ['SELECT * FROM wp_posts WHERE ID = 1', 0.020, 'get_post'],
            ['SELECT * FROM wp_users WHERE ID = 2', 0.050, 'get_user'],
            ['SELECT * FROM wp_users WHERE ID = 2', 0.010, 'get_user'],
            ['SELECT * FROM wp_options', 0.002, 'alloptions'],
            ['SELECT * FROM wp_options', 0.000, 'alloptions'],
        ];

        $prepared = sitepulse_request_profiler_prepare_queries($queries);

        $this->assertCount(3, $prepared);
        $this->assertSame('SELECT * FROM wp_users WHERE ID = 2', $prepared[0]['sql']);
        $this->assertSame(2, $prepared[0]['count']);
        $this->assertEqualsWithDelta(0.03, $prepared[0]['avg_time'], 0.0001);
        $this->assertSame(['get_user'], $prepared[0]['callers']);
    }

    public function test_store_trace_persists_and_retrieves_payload(): void {
        $payload = [
            'recorded_at'    => gmdate('Y-m-d H:i:s'),
            'request_url'    => 'https://example.com/sample',
            'request_method' => 'POST',
            'trace_token'    => 'abc123',
            'total_duration' => 1.23,
            'memory_peak'    => 512000,
            'hook_count'     => 2,
            'query_count'    => 3,
            'hooks'          => [
                ['hook' => 'init', 'count' => 5, 'total_time' => 0.4, 'avg_time' => 0.08, 'max_time' => 0.2],
            ],
            'queries'        => [
                ['sql' => 'SELECT * FROM wp_posts', 'count' => 2, 'total_time' => 0.03, 'avg_time' => 0.015, 'callers' => ['get_posts']],
            ],
            'context'        => [
                'user' => 1,
                'referrer' => 'admin',
            ],
        ];

        $trace_id = sitepulse_request_profiler_store_trace($payload);

        $this->assertGreaterThan(0, $trace_id);

        $trace = sitepulse_request_profiler_get_trace($trace_id);

        $this->assertIsArray($trace);
        $this->assertSame('https://example.com/sample', $trace['request_url']);
        $this->assertSame(2, (int) $trace['hook_count']);
        $this->assertSame('POST', $trace['request_method']);
        $this->assertSame('abc123', $trace['trace_token']);
        $this->assertIsArray($trace['hooks']);
        $this->assertSame('init', $trace['hooks'][0]['hook']);
        $this->assertIsArray($trace['queries']);
        $this->assertSame('SELECT * FROM wp_posts', $trace['queries'][0]['sql']);
        $this->assertSame('admin', $trace['context']['referrer']);
    }
}
