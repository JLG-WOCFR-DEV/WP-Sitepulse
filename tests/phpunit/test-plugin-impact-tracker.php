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
    }

    protected function setUp(): void {
        parent::setUp();

        global $sitepulse_plugin_impact_tracker_samples, $sitepulse_plugin_impact_tracker_force_persist;

        $sitepulse_plugin_impact_tracker_samples = [];
        $sitepulse_plugin_impact_tracker_force_persist = false;

        delete_option(SITEPULSE_PLUGIN_IMPACT_OPTION);
        delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
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
}

