<?php
/**
 * Tests for speed analyzer automation and scheduling helpers.
 */

class Sitepulse_Speed_Automation_Test extends WP_UnitTestCase {
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/speed_analyzer.php';
    }

    protected function setUp(): void {
        parent::setUp();

        remove_all_filters('sitepulse_speed_automation_max_entries');
        remove_all_filters('sitepulse_speed_automation_max_age');
    }

    protected function tearDown(): void {
        delete_option(SITEPULSE_OPTION_SPEED_AUTOMATION_HISTORY);
        delete_option(SITEPULSE_OPTION_SPEED_AUTOMATION_CONFIG);
        delete_option(SITEPULSE_OPTION_SPEED_AUTOMATION_QUEUE);
        delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_LOCK);
        delete_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY);

        parent::tearDown();
    }

    public function test_automation_history_rotates_without_touching_manual_history(): void {
        update_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, [
            [
                'timestamp' => 100,
                'server_processing_ms' => 42,
            ],
        ]);

        $callback = static function () {
            return 2;
        };

        add_filter('sitepulse_speed_automation_max_entries', $callback);

        sitepulse_speed_analyzer_store_automation_measurement(
            'front',
            [
                'timestamp' => 1000,
                'server_processing_ms' => 120,
                'http_code' => 200,
            ],
            ['label' => 'Front']
        );

        sitepulse_speed_analyzer_store_automation_measurement(
            'front',
            [
                'timestamp' => 1100,
                'server_processing_ms' => 180,
                'http_code' => 200,
            ],
            ['label' => 'Front']
        );

        sitepulse_speed_analyzer_store_automation_measurement(
            'front',
            [
                'timestamp' => 1200,
                'server_processing_ms' => 260,
                'http_code' => 503,
                'error' => 'Service unavailable',
            ],
            ['label' => 'Front']
        );

        remove_filter('sitepulse_speed_automation_max_entries', $callback);

        $automation_history = sitepulse_speed_analyzer_get_automation_history('front', true);
        $this->assertCount(2, $automation_history, 'Automation history should be trimmed to the last two entries.');

        $timestamps = array_map(
            static function ($entry) {
                return isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            },
            $automation_history
        );

        $this->assertSame([1100, 1200], $timestamps, 'Only the two most recent automation entries should be preserved.');

        $manual_history = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY);
        $this->assertCount(1, $manual_history, 'Manual history must remain untouched by automation writes.');
        $this->assertSame(42.0, (float) $manual_history[0]['server_processing_ms']);
    }

    public function test_build_automation_payload_contains_presets(): void {
        sitepulse_speed_analyzer_save_automation_settings([
            'frequency' => 'hourly',
            'presets' => [
                'front' => [
                    'label' => 'Front',
                    'url' => 'https://example.com',
                    'method' => 'GET',
                ],
            ],
        ]);

        sitepulse_speed_analyzer_store_automation_measurement(
            'front',
            [
                'timestamp' => 2000,
                'server_processing_ms' => 150,
                'http_code' => 200,
            ],
            ['label' => 'Front', 'url' => 'https://example.com']
        );

        $payload = sitepulse_speed_analyzer_build_automation_payload([
            'warning' => 100,
            'critical' => 250,
        ]);

        $this->assertArrayHasKey('front', $payload['presets']);
        $preset = $payload['presets']['front'];
        $this->assertSame('Front', $preset['label']);
        $this->assertSame('https://example.com', $preset['url']);
        $this->assertNotEmpty($preset['history']);
        $this->assertNotEmpty($preset['detailedHistory']);
    }
}
