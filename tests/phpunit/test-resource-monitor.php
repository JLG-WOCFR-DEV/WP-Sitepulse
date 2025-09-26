<?php
/**
 * Tests for the resource monitor snapshot logic.
 */

class Sitepulse_Resource_Monitor_Test extends WP_UnitTestCase {
    public static function wpSetUpBeforeClass($factory) {
        if (!defined('SITEPULSE_VERSION')) {
            define('SITEPULSE_VERSION', '1.0-test');
        }

        if (!defined('SITEPULSE_URL')) {
            define('SITEPULSE_URL', 'https://example.com/');
        }

        if (!defined('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT')) {
            define('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT', 'sitepulse_resource_monitor_snapshot');
        }

        require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/resource_monitor.php';
    }

    protected function set_up(): void {
        parent::set_up();

        delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        $GLOBALS['sitepulse_logger'] = [];
    }

    protected function tear_down(): void {
        delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        $GLOBALS['sitepulse_logger'] = [];

        parent::tear_down();
    }

    public function test_returns_cached_snapshot_when_transient_exists() {
        $cached_snapshot = [
            'load'         => [0.1, 0.2, 0.3],
            'load_display' => '0.1 / 0.2 / 0.3',
            'memory_usage' => '10 MB',
            'memory_limit' => '128M',
            'disk_free'    => '5 GB',
            'disk_total'   => '10 GB',
            'notices'      => [],
            'generated_at' => time(),
        ];

        set_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT, $cached_snapshot, MINUTE_IN_SECONDS);

        $filter_calls = 0;
        $callback = function($default_ttl, $snapshot) use (&$filter_calls) {
            $filter_calls++;

            return $default_ttl;
        };

        add_filter('sitepulse_resource_monitor_cache_ttl', $callback, 10, 2);

        $result = sitepulse_resource_monitor_get_snapshot();

        remove_filter('sitepulse_resource_monitor_cache_ttl', $callback, 10);

        $this->assertSame($cached_snapshot, $result, 'Cached snapshot should be returned untouched.');
        $this->assertSame(0, $filter_calls, 'Cache TTL filter should not run when cached data is returned.');
    }

    public function test_generates_snapshot_with_custom_ttl_and_caches() {
        $custom_ttl = 123;
        $captured_snapshot = null;
        $filter_calls = 0;

        $callback = function($default_ttl, $snapshot) use (&$captured_snapshot, &$filter_calls, $custom_ttl) {
            $filter_calls++;
            $captured_snapshot = $snapshot;
            $this->assertSame(5 * MINUTE_IN_SECONDS, $default_ttl, 'Default TTL should be passed to the filter.');

            return $custom_ttl;
        };

        add_filter('sitepulse_resource_monitor_cache_ttl', $callback, 10, 2);

        $start = time();
        $snapshot = sitepulse_resource_monitor_get_snapshot();

        remove_filter('sitepulse_resource_monitor_cache_ttl', $callback, 10);

        $this->assertSame(1, $filter_calls, 'Cache TTL filter should run once when generating a snapshot.');
        $this->assertSame($snapshot, $captured_snapshot, 'Filter should receive the freshly generated snapshot.');

        $cached_snapshot = get_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        $this->assertSame($snapshot, $cached_snapshot, 'Snapshot should be cached for subsequent calls.');

        $timeout_option = get_option('_transient_timeout_' . SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        $this->assertIsInt($timeout_option, 'Transient timeout option should be stored.');
        $this->assertGreaterThanOrEqual($start + $custom_ttl, $timeout_option, 'Timeout should be at least start time plus TTL.');
        $this->assertEqualsWithDelta($custom_ttl, $timeout_option - time(), 3, 'Stored TTL should respect the filter override.');
    }

    public function test_records_notices_when_disk_space_queries_fail() {
        $original_open_basedir = ini_get('open_basedir');
        $restore_value = $original_open_basedir ? (string) $original_open_basedir : '';

        $result = ini_set('open_basedir', '/nonexistent-directory');

        if ($result === false) {
            $this->markTestSkipped('Unable to adjust open_basedir for disk space failure simulation.');
        }

        try {
            $snapshot = sitepulse_resource_monitor_get_snapshot();
        } finally {
            ini_set('open_basedir', $restore_value);
        }

        $this->assertIsArray($snapshot['notices'], 'Snapshot should include notices array.');
        $this->assertNotEmpty($snapshot['notices'], 'Disk failures should append warning notices.');

        $messages = wp_list_pluck($snapshot['notices'], 'message');

        $this->assertContains(
            'Unable to determine the available disk space for the WordPress root directory.',
            $messages,
            'Failure to calculate free disk space should add a warning notice.'
        );

        $this->assertContains(
            'Unable to determine the total disk space for the WordPress root directory.',
            $messages,
            'Failure to calculate total disk space should add a warning notice.'
        );

        $this->assertSame('N/A', $snapshot['disk_free'], 'Disk free display should remain N/A after failure.');
        $this->assertSame('N/A', $snapshot['disk_total'], 'Disk total display should remain N/A after failure.');

        $this->assertNotEmpty($GLOBALS['sitepulse_logger'], 'Failures should be logged as notices.');
        $logged_messages = wp_list_pluck($GLOBALS['sitepulse_logger'], 'message');
        $this->assertNotEmpty(
            array_filter(
                $logged_messages,
                static function($message) {
                    return str_contains($message, 'Resource Monitor: Unable to determine the available disk space');
                }
            ),
            'A log entry should be recorded for disk free failures.'
        );
        $this->assertNotEmpty(
            array_filter(
                $logged_messages,
                static function($message) {
                    return str_contains($message, 'Resource Monitor: Unable to determine the total disk space');
                }
            ),
            'A log entry should be recorded for disk total failures.'
        );
    }
}
