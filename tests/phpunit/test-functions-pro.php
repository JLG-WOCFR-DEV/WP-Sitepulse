<?php
/**
 * Additional tests covering the pro-level helper improvements.
 */

class Sitepulse_Pro_Functions_Test extends WP_UnitTestCase {
    private $deleted_transient_stats = [];
    private $threshold_corrections   = [];

    public static function wpSetUpBeforeClass($factory) {
        $plugin_root = dirname(__DIR__, 2) . '/sitepulse_FR/';
        require_once $plugin_root . 'includes/functions.php';
    }

    protected function set_up(): void {
        parent::set_up();

        $this->deleted_transient_stats = [];
        $this->threshold_corrections   = [];

        add_action('sitepulse_transient_deletion_completed', [$this, 'capture_transient_stats'], 10, 2);
        add_action('sitepulse_speed_threshold_corrected', [$this, 'capture_speed_threshold_corrections'], 10, 3);
    }

    protected function tear_down(): void {
        remove_action('sitepulse_transient_deletion_completed', [$this, 'capture_transient_stats'], 10);
        remove_action('sitepulse_speed_threshold_corrected', [$this, 'capture_speed_threshold_corrections'], 10);

        delete_option('sitepulse_speed_profiles');

        parent::tear_down();
    }

    public function capture_transient_stats($prefix, $stats) {
        $this->deleted_transient_stats = [
            'prefix' => $prefix,
            'stats'  => $stats,
        ];
    }

    public function capture_speed_threshold_corrections($profile, $thresholds, $corrections) {
        $this->threshold_corrections = [
            'profile'     => $profile,
            'thresholds'  => $thresholds,
            'corrections' => $corrections,
        ];
    }

    public function test_delete_transients_by_prefix_batches_and_announces() {
        set_transient('sitepulse_bulk_demo_one', 'value', 60);
        set_transient('sitepulse_bulk_demo_two', 'value', 60);
        set_transient('sitepulse_bulk_demo_three', 'value', 60);

        sitepulse_delete_transients_by_prefix('sitepulse_bulk_demo_');

        $this->assertFalse(get_transient('sitepulse_bulk_demo_one'));
        $this->assertFalse(get_transient('sitepulse_bulk_demo_two'));
        $this->assertFalse(get_transient('sitepulse_bulk_demo_three'));

        $this->assertSame('sitepulse_bulk_demo_', $this->deleted_transient_stats['prefix']);
        $this->assertSame(3, $this->deleted_transient_stats['stats']['deleted']);
        $this->assertSame(3, count($this->deleted_transient_stats['stats']['unique_keys']));
    }

    public function test_recent_log_lines_metadata_and_truncation() {
        $log_file = tempnam(sys_get_temp_dir(), 'sitepulse-log-');
        $lines    = [];

        for ($i = 0; $i < 40; $i++) {
            $lines[] = 'Line ' . $i;
        }

        file_put_contents($log_file, implode("\n", $lines));

        $result = sitepulse_get_recent_log_lines($log_file, 10, 80, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lines', $result);
        $this->assertCount(10, $result['lines']);
        $this->assertSame('Line 30', $result['lines'][0]);
        $this->assertTrue($result['truncated']);
        $this->assertSame(filesize($log_file), $result['file_size']);

        unlink($log_file);
    }

    public function test_sanitize_alert_interval_expanded_and_smart_mode() {
        $allowed_filter = static function () {
            return [1, 3, 7, 15];
        };

        add_filter('sitepulse_alert_interval_allowed_values', $allowed_filter);

        $this->assertSame(3, sitepulse_sanitize_alert_interval(2));
        $this->assertSame(7, sitepulse_sanitize_alert_interval(6));

        $smart_filter = static function ($default, $allowed) {
            return end($allowed);
        };

        add_filter('sitepulse_alert_interval_smart_value', $smart_filter, 10, 2);

        $this->assertSame(15, sitepulse_sanitize_alert_interval('smart'));

        remove_filter('sitepulse_alert_interval_smart_value', $smart_filter, 10);
        remove_filter('sitepulse_alert_interval_allowed_values', $allowed_filter);
    }

    public function test_speed_threshold_profiles_trigger_corrections() {
        update_option('sitepulse_speed_profiles', [
            'mobile' => [
                'warning'  => 120,
                'critical' => 100,
            ],
        ]);

        $thresholds = sitepulse_get_speed_thresholds('mobile');

        $this->assertSame(120, $thresholds['warning']);
        $this->assertGreaterThan($thresholds['warning'], $thresholds['critical']);
        $this->assertSame('mobile', $this->threshold_corrections['profile']);
        $this->assertContains('critical_adjusted', $this->threshold_corrections['corrections']);
    }
}
