<?php
/**
 * Tests for the plugin impact tracker persistence logic.
 */

require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/plugin-impact-tracker.php';

class Sitepulse_Plugin_Impact_Tracker_Test extends WP_UnitTestCase {
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!defined('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS')) {
            define('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS', 'sitepulse_speed_scan_results');
        }

        if (!defined('SITEPULSE_OPTION_LAST_LOAD_TIME')) {
            define('SITEPULSE_OPTION_LAST_LOAD_TIME', 'sitepulse_last_load_time');
        }

        if (!defined('SITEPULSE_OPTION_SPEED_SCAN_HISTORY')) {
            define('SITEPULSE_OPTION_SPEED_SCAN_HISTORY', 'sitepulse_speed_scan_history');
        }
    }

    protected function setUp(): void {
        parent::setUp();

        global $sitepulse_plugin_impact_tracker_samples, $sitepulse_plugin_impact_tracker_force_persist;

        $sitepulse_plugin_impact_tracker_samples = [];
        $sitepulse_plugin_impact_tracker_force_persist = false;

        delete_option(SITEPULSE_PLUGIN_IMPACT_OPTION);
        delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        delete_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY);
    }

    public function test_persist_sanitizes_interval_from_filter(): void {
        global $sitepulse_plugin_impact_tracker_samples, $sitepulse_plugin_impact_tracker_force_persist;

        $sitepulse_plugin_impact_tracker_force_persist = true;
        $sitepulse_plugin_impact_tracker_samples = [
            'example/plugin.php' => 0.123,
        ];

        $callback = function () {
            return ['invalid'];
        };

        add_filter('sitepulse_plugin_impact_refresh_interval', $callback);

        $errors = [];
        set_error_handler(
            function ($errno, $errstr) use (&$errors) {
                $errors[] = $errstr;
                return false;
            },
            E_WARNING | E_NOTICE
        );

        sitepulse_plugin_impact_tracker_persist();

        restore_error_handler();

        remove_filter('sitepulse_plugin_impact_refresh_interval', $callback);

        $this->assertSame([], $errors, 'Persistence should not trigger PHP warnings when filter returns invalid data.');

        $payload = get_option(SITEPULSE_PLUGIN_IMPACT_OPTION, []);

        $this->assertIsArray($payload, 'Plugin impact tracker option should persist an array payload.');
        $this->assertArrayHasKey('interval', $payload, 'Persisted payload must include sanitized interval.');
        $this->assertSame(1, $payload['interval'], 'Interval should fall back to the sanitized minimum when filter is invalid.');
    }

    public function test_speed_scan_persists_when_time_goes_backwards(): void {
        global $sitepulse_plugin_impact_tracker_samples, $sitepulse_plugin_impact_tracker_force_persist;

        $sitepulse_plugin_impact_tracker_force_persist = true;
        $sitepulse_plugin_impact_tracker_samples = [];

        $existing_timestamp = 1_000;

        set_transient(
            SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS,
            [
                'server_processing_ms' => 5,
                'ttfb'                 => 5,
                'timestamp'            => $existing_timestamp,
            ],
            MINUTE_IN_SECONDS
        );

        $older_time_callback = static function ($timestamp, $type, $gmt) use ($existing_timestamp) {
            if ($type === 'timestamp') {
                return $existing_timestamp - 30;
            }

            return $timestamp;
        };

        add_filter('current_time', $older_time_callback, 10, 3);

        try {
            sitepulse_plugin_impact_tracker_persist();
        } finally {
            remove_filter('current_time', $older_time_callback, 10);
        }

        $results = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);

        $this->assertIsArray($results, 'Speed scan transient should persist an array payload.');
        $this->assertSame(
            $existing_timestamp - 30,
            $results['timestamp'],
            'A backwards time change should still result in refreshed speed scan results.'
        );
    }

    public function test_speed_scan_history_trims_old_entries(): void {
        $current_timestamp = 1_700_000_000;
        $stale_timestamp = $current_timestamp - (int) SITEPULSE_SPEED_SCAN_HISTORY_TTL - 60;

        update_option(
            SITEPULSE_OPTION_SPEED_SCAN_HISTORY,
            [
                [
                    'timestamp' => $stale_timestamp,
                    'server_processing_ms' => 100,
                ],
            ]
        );

        sitepulse_plugin_impact_append_speed_scan_history($current_timestamp, 200);

        $history = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

        $this->assertIsArray($history);
        $this->assertCount(1, $history, 'Stale entries should be removed when appending new measurements.');
        $this->assertSame($current_timestamp, $history[0]['timestamp']);
        $this->assertSame(200.0, $history[0]['server_processing_ms']);
    }

    public function test_speed_scan_history_respects_max_entries(): void {
        global $sitepulse_plugin_impact_tracker_samples, $sitepulse_plugin_impact_tracker_force_persist;

        $sitepulse_plugin_impact_tracker_force_persist = true;
        $sitepulse_plugin_impact_tracker_samples = ['example/plugin.php' => 0.1];

        $base_time = 1_700_100_000;
        $call_count = 0;

        $callback = static function ($timestamp, $type, $gmt) use (&$call_count, $base_time) {
            if ($type === 'timestamp') {
                return $base_time + ($call_count * 120);
            }

            return $timestamp;
        };

        add_filter('current_time', $callback, 10, 3);

        try {
            for ($i = 0; $i < SITEPULSE_SPEED_SCAN_HISTORY_MAX_ENTRIES + 5; $i++) {
                $call_count = $i;
                $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 0.2;

                sitepulse_plugin_impact_tracker_persist();

                // Ensure next iteration is not blocked by cached transient age.
                delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
            }
        } finally {
            remove_filter('current_time', $callback, 10);
            unset($_SERVER['REQUEST_TIME_FLOAT']);
        }

        $history = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

        $this->assertIsArray($history);
        $this->assertLessThanOrEqual(
            SITEPULSE_SPEED_SCAN_HISTORY_MAX_ENTRIES,
            count($history),
            'History should be trimmed to the configured maximum length.'
        );

        if (!empty($history)) {
            $timestamps = array_map('intval', array_column($history, 'timestamp'));
            $sorted = $timestamps;
            sort($sorted);
            $this->assertSame($sorted, $timestamps, 'History should remain sorted chronologically.');

            $latest_timestamp = end($timestamps);
            $this->assertGreaterThanOrEqual(
                $base_time + (count($history) - 1) * 120,
                $latest_timestamp,
                'Latest timestamp should reflect the most recent measurement.'
            );
        }
    }
}

