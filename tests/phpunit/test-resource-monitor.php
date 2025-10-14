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
        sitepulse_resource_monitor_clear_history();
        $GLOBALS['sitepulse_logger'] = [];

        add_filter('sitepulse_resource_monitor_enable_disk_cache', '__return_false');
    }

    protected function tear_down(): void {
        delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        sitepulse_resource_monitor_clear_history();
        $GLOBALS['sitepulse_logger'] = [];

        remove_filter('sitepulse_resource_monitor_enable_disk_cache', '__return_false');

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

        $history = sitepulse_resource_monitor_get_history();
        $this->assertArrayHasKey('entries', $history, 'History response should expose entries key.');
        $this->assertSame([], $history['entries'], 'History should remain empty when serving cached data.');
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

        $start_generated_at = function_exists('current_time')
            ? (int) current_time('timestamp', true)
            : time();

        $start = time();
        $snapshot = sitepulse_resource_monitor_get_snapshot();

        $end_generated_at = function_exists('current_time')
            ? (int) current_time('timestamp', true)
            : time();

        remove_filter('sitepulse_resource_monitor_cache_ttl', $callback, 10);

        $this->assertSame(1, $filter_calls, 'Cache TTL filter should run once when generating a snapshot.');
        $this->assertSame($snapshot, $captured_snapshot, 'Filter should receive the freshly generated snapshot.');

        $this->assertArrayHasKey('generated_at', $snapshot, 'Snapshot should include a generated_at timestamp.');
        $this->assertIsInt($snapshot['generated_at'], 'Snapshot timestamp should be stored as an integer.');
        $this->assertGreaterThanOrEqual(
            $start_generated_at,
            $snapshot['generated_at'],
            'Snapshot timestamp should not be earlier than the pre-call UTC timestamp.'
        );
        $this->assertLessThanOrEqual(
            $end_generated_at,
            $snapshot['generated_at'],
            'Snapshot timestamp should not be later than the post-call UTC timestamp.'
        );

        $this->assertArrayHasKey('memory_usage_bytes', $snapshot, 'Snapshot should expose raw memory usage in bytes.');
        $this->assertArrayHasKey('disk_free_bytes', $snapshot, 'Snapshot should expose raw disk space in bytes.');

        $cached_snapshot = get_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        $this->assertSame($snapshot, $cached_snapshot, 'Snapshot should be cached for subsequent calls.');

        $timeout_option = get_option('_transient_timeout_' . SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        $this->assertIsInt($timeout_option, 'Transient timeout option should be stored.');
        $this->assertGreaterThanOrEqual($start + $custom_ttl, $timeout_option, 'Timeout should be at least start time plus TTL.');
        $this->assertEqualsWithDelta($custom_ttl, $timeout_option - time(), 3, 'Stored TTL should respect the filter override.');

        $history = sitepulse_resource_monitor_get_history();
        $this->assertIsArray($history, 'History response should be an array.');
        $this->assertArrayHasKey('entries', $history, 'History should expose an entries collection.');
        $this->assertIsArray($history['entries'], 'History entries should be an array.');
        $this->assertNotEmpty($history['entries'], 'History entries should include the newly generated snapshot.');

        $entries = $history['entries'];
        $latest_entry = end($entries);
        $this->assertSame($snapshot['generated_at'], $latest_entry['timestamp'], 'Latest history entry should match snapshot timestamp.');
    }

    public function test_unlimited_memory_limit_is_normalized_for_display() {
        $original_memory_limit = ini_get('memory_limit');
        $result = ini_set('memory_limit', '-1');

        if ($result === false) {
            $this->markTestSkipped('Unable to set memory_limit to -1 for this environment.');
        }

        try {
            delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);

            $snapshot = sitepulse_resource_monitor_get_snapshot();
            $this->assertSame(__('Illimitée', 'sitepulse'), $snapshot['memory_limit'], 'Unlimited memory should be displayed as a translated label.');

            $administrator_id = self::factory()->user->create(['role' => 'administrator']);
            wp_set_current_user($administrator_id);

            try {
                sitepulse_resource_monitor_enqueue_assets('sitepulse-dashboard_page_sitepulse-resources');
                ob_start();
                sitepulse_resource_monitor_page();
                $output = ob_get_clean();
            } finally {
                wp_set_current_user(0);
            }

            $this->assertStringContainsString('Limite PHP : Illimitée', $output, 'Page output should show the translated unlimited label.');
        } finally {
            if ($original_memory_limit === false) {
                if (function_exists('ini_restore')) {
                    ini_restore('memory_limit');
                }
            } else {
                ini_set('memory_limit', (string) $original_memory_limit);
            }
        }
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

        $this->assertSame(__('N/A', 'sitepulse'), $snapshot['disk_free'], 'Disk free display should remain N/A after failure.');
        $this->assertSame(__('N/A', 'sitepulse'), $snapshot['disk_total'], 'Disk total display should remain N/A after failure.');

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

    public function test_snapshot_timestamp_is_rendered_in_site_timezone() {
        $original_gmt_offset      = get_option('gmt_offset');
        $original_timezone_string = get_option('timezone_string');
        $original_date_format     = get_option('date_format');
        $original_time_format     = get_option('time_format');

        update_option('timezone_string', '');
        update_option('gmt_offset', 2);
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');

        $generated_at = gmmktime(12, 0, 0, 1, 1, 2024);

        $not_available_label = __('N/A', 'sitepulse');
        $load_placeholder = [$not_available_label, $not_available_label, $not_available_label];

        $snapshot = [
            'load'         => $load_placeholder,
            'load_display' => sitepulse_resource_monitor_format_load_display($load_placeholder),
            'memory_usage' => '0 MB',
            'memory_limit' => '128M',
            'disk_free'    => $not_available_label,
            'disk_total'   => $not_available_label,
            'notices'      => [],
            'generated_at' => $generated_at,
        ];

        set_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT, $snapshot, MINUTE_IN_SECONDS);

        $administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator_id);

        sitepulse_resource_monitor_enqueue_assets('sitepulse-dashboard_page_sitepulse-resources');
        ob_start();
        sitepulse_resource_monitor_page();
        $output = ob_get_clean();

        $expected_time = wp_date('Y-m-d H:i', $generated_at);

        $this->assertStringContainsString(
            sprintf('Mesures relevées le %s', $expected_time),
            $output,
            'Snapshot timestamp should be converted from UTC to the configured site timezone.'
        );

        wp_set_current_user(0);

        if ($original_timezone_string === false) {
            delete_option('timezone_string');
        } else {
            update_option('timezone_string', $original_timezone_string);
        }

        if ($original_gmt_offset === false) {
            delete_option('gmt_offset');
        } else {
            update_option('gmt_offset', $original_gmt_offset);
        }

        if ($original_date_format === false) {
            delete_option('date_format');
        } else {
            update_option('date_format', $original_date_format);
        }

        if ($original_time_format === false) {
            delete_option('time_format');
        } else {
            update_option('time_format', $original_time_format);
        }
    }

    public function test_history_normalization_applies_ttl_and_max_entries() {
        $now = current_time('timestamp', true);

        $history = [];

        for ($i = 0; $i < 5; $i++) {
            $history[] = [
                'timestamp' => $now - ($i * MINUTE_IN_SECONDS),
                'load'      => [0.1 + ($i * 0.01), null, null],
                'memory'    => [
                    'usage' => 1000000 + ($i * 1000),
                    'limit' => 2000000,
                ],
                'disk'      => [
                    'free'  => 1500000,
                    'total' => 2000000,
                ],
            ];
        }

        $ttl_calls = 0;
        $max_calls = 0;

        $ttl_callback = function($default, $entries) use (&$ttl_calls) {
            $ttl_calls++;
            $this->assertIsArray($entries, 'TTL filter should receive normalized entries.');

            return 2 * MINUTE_IN_SECONDS;
        };

        $max_callback = function($default, $entries) use (&$max_calls) {
            $max_calls++;
            $this->assertIsArray($entries, 'Max entries filter should receive normalized entries.');

            return 2;
        };

        add_filter('sitepulse_resource_monitor_history_ttl', $ttl_callback, 10, 2);
        add_filter('sitepulse_resource_monitor_history_max_entries', $max_callback, 10, 2);

        $normalized = sitepulse_resource_monitor_normalize_history($history);

        remove_filter('sitepulse_resource_monitor_history_ttl', $ttl_callback, 10);
        remove_filter('sitepulse_resource_monitor_history_max_entries', $max_callback, 10);

        $this->assertSame(1, $ttl_calls, 'TTL filter should run once.');
        $this->assertSame(1, $max_calls, 'Max entries filter should run once.');
        $this->assertCount(2, $normalized, 'History should be trimmed to the requested entry count.');

        $first_entry = $normalized[0];
        $second_entry = $normalized[1];

        $this->assertSame($now - MINUTE_IN_SECONDS, $first_entry['timestamp'], 'Only the two most recent entries should remain.');
        $this->assertSame($now, $second_entry['timestamp'], 'Newest history entry should retain the latest timestamp.');
        $this->assertLessThan($first_entry['timestamp'], $second_entry['timestamp'], 'Entries should remain ordered chronologically.');
    }

    public function test_refresh_action_clears_history_and_rebuilds_snapshot() {
        sitepulse_resource_monitor_clear_history();
        delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);

        sitepulse_resource_monitor_get_snapshot();
        delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        sitepulse_resource_monitor_get_snapshot();

        $history_before = sitepulse_resource_monitor_get_history();
        $this->assertArrayHasKey('entries', $history_before, 'History response should expose entries key.');
        $this->assertGreaterThanOrEqual(2, count($history_before['entries']), 'History should contain multiple entries before refresh.');

        $administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator_id);

        $_POST['sitepulse_resource_monitor_refresh'] = '1';
        $_POST['_wpnonce'] = wp_create_nonce('sitepulse_refresh_resource_snapshot');

        sitepulse_resource_monitor_enqueue_assets('sitepulse-dashboard_page_sitepulse-resources');
        ob_start();
        sitepulse_resource_monitor_page();
        $output = ob_get_clean();

        $history_after = sitepulse_resource_monitor_get_history();
        $this->assertArrayHasKey('entries', $history_after, 'History response should expose entries key.');
        $this->assertCount(1, $history_after['entries'], 'History should be rebuilt from a single fresh snapshot after refresh.');

        $history_before_entries = $history_before['entries'];
        $latest_before = end($history_before_entries);
        $this->assertIsArray($latest_before, 'History should return array entries.');

        $latest_after_entries = $history_after['entries'];
        $latest_after = $latest_after_entries[0];

        $this->assertGreaterThan($latest_before['timestamp'], $latest_after['timestamp'], 'Refreshed snapshot should have a newer timestamp.');
        $this->assertStringContainsString(esc_html__('Les mesures et l’historique ont été actualisés.', 'sitepulse'), $output, 'Success notice should confirm history reset.');

        wp_set_current_user(0);
        $_POST = [];
    }

    public function test_load_average_filter_can_override_values() {
        $override = [1.23, 4.56, 7.89];
        $contexts = [];

        $callback = static function($values, $context) use (&$contexts, $override) {
            $contexts[] = $context;

            return $override;
        };

        add_filter('sitepulse_resource_monitor_load_average', $callback, 10, 2);

        $snapshot = sitepulse_resource_monitor_get_snapshot();

        remove_filter('sitepulse_resource_monitor_load_average', $callback, 10);

        $this->assertNotEmpty($contexts, 'Filter should receive the current load average context.');
        $this->assertArrayHasKey('fallback_attempted', $contexts[0], 'Context should expose the fallback status.');

        $this->assertEqualsWithDelta($override[0], $snapshot['load'][0], 0.001, 'Filter override should replace first load value.');
        $this->assertEqualsWithDelta($override[1], $snapshot['load'][1], 0.001, 'Filter override should replace second load value.');
        $this->assertEqualsWithDelta($override[2], $snapshot['load'][2], 0.001, 'Filter override should replace third load value.');

        $expected_display = sitepulse_resource_monitor_format_load_display($override);
        $this->assertSame($expected_display, $snapshot['load_display'], 'Load display should reflect the overridden values.');
    }
}
