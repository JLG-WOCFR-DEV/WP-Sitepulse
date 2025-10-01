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

    public function test_speed_scan_history_respects_window_constraints(): void {
        global $sitepulse_plugin_impact_tracker_samples, $sitepulse_plugin_impact_tracker_force_persist;

        $sitepulse_plugin_impact_tracker_force_persist = true;
        $sitepulse_plugin_impact_tracker_samples = [];

        $current_timestamp = 5_000;
        $initial_history = [
            [
                'timestamp'            => $current_timestamp - 400,
                'server_processing_ms' => 250,
            ],
            [
                'timestamp'            => $current_timestamp - 50,
                'server_processing_ms' => 180,
            ],
        ];

        update_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, $initial_history);

        $age_filter = static function () {
            return 300;
        };

        $entries_filter = static function () {
            return 2;
        };

        add_filter('sitepulse_speed_history_max_age', $age_filter);
        add_filter('sitepulse_speed_history_max_entries', $entries_filter);

        $current_time_filter = static function ($timestamp, $type, $gmt) use ($current_timestamp) {
            if ($type === 'timestamp') {
                return $current_timestamp;
            }

            return $timestamp;
        };

        add_filter('current_time', $current_time_filter, 10, 3);

        try {
            sitepulse_plugin_impact_tracker_persist();
        } finally {
            remove_filter('current_time', $current_time_filter, 10);
            remove_filter('sitepulse_speed_history_max_age', $age_filter);
            remove_filter('sitepulse_speed_history_max_entries', $entries_filter);
        }

        $history = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

        $this->assertIsArray($history, 'Speed scan history should persist as an array.');
        $this->assertCount(2, $history, 'Speed scan history should respect the configured maximum length.');

        $timestamps = wp_list_pluck($history, 'timestamp');

        sort($timestamps);

        $this->assertSame($current_timestamp - 50, $timestamps[0], 'Entries older than the configured TTL should be purged.');
        $this->assertSame($current_timestamp, $timestamps[1], 'The latest measurement should be appended to the history.');
    }
}

