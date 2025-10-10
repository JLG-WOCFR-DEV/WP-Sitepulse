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

        delete_option('sitepulse_transient_purge_log');

        add_action('sitepulse_transient_deletion_completed', [$this, 'capture_transient_stats'], 10, 2);
        add_action('sitepulse_speed_threshold_corrected', [$this, 'capture_speed_threshold_corrections'], 10, 3);
    }

    protected function tear_down(): void {
        remove_action('sitepulse_transient_deletion_completed', [$this, 'capture_transient_stats'], 10);
        remove_action('sitepulse_speed_threshold_corrected', [$this, 'capture_speed_threshold_corrections'], 10);

        delete_option('sitepulse_speed_profiles');
        delete_option('sitepulse_transient_purge_log');

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

    public function test_smart_alert_interval_uses_activity_snapshot() {
        delete_option(SITEPULSE_OPTION_ALERT_ACTIVITY);

        $allowed_filter = static function () {
            return [1, 5, 15, 30];
        };

        add_filter('sitepulse_alert_interval_allowed_values', $allowed_filter);

        $this->assertSame(5, sitepulse_sanitize_alert_interval('smart'));

        sitepulse_register_alert_activity_event([
            'timestamp' => time(),
            'type'      => 'php_fatal',
            'severity'  => 'critical',
            'success'   => true,
            'channels'  => ['email'],
        ]);

        $this->assertSame(1, sitepulse_sanitize_alert_interval('smart'));

        sitepulse_save_alert_activity_state([
            'events'         => [],
            'window_seconds' => 86400,
            'last_check'     => time(),
            'updated_at'     => time(),
            'last_event'     => time() - 86400,
            'quiet_checks'   => 72,
        ]);

        $this->assertSame(30, sitepulse_sanitize_alert_interval('smart'));

        remove_filter('sitepulse_alert_interval_allowed_values', $allowed_filter);
        delete_option(SITEPULSE_OPTION_ALERT_ACTIVITY);
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

    public function test_transient_purge_log_records_standard_transients() {
        set_transient('sitepulse_widget_tmp_one', 'value', 60);
        set_transient('sitepulse_widget_tmp_two', 'value', 60);

        sitepulse_delete_transients_by_prefix('sitepulse_widget_tmp_');

        $log = sitepulse_get_transient_purge_log();

        $this->assertNotEmpty($log);
        $entry = $log[0];

        $this->assertSame('transient', $entry['scope']);
        $this->assertSame('sitepulse_widget_tmp_', $entry['prefix']);
        $this->assertSame(2, $entry['deleted']);
        $this->assertSame(2, $entry['unique']);
    }

    public function test_transient_purge_log_records_site_transients() {
        set_site_transient('sitepulse_network_cache_a', 'a', 60);
        set_site_transient('sitepulse_network_cache_b', 'b', 60);

        sitepulse_delete_site_transients_by_prefix('sitepulse_network_cache_');

        $log = sitepulse_get_transient_purge_log();

        $this->assertNotEmpty($log);
        $entry = $log[0];

        $this->assertSame('site-transient', $entry['scope']);
        $this->assertSame('sitepulse_network_cache_', $entry['prefix']);
        $this->assertSame(2, $entry['deleted']);
        $this->assertSame(1, $entry['batches']);
    }

    public function test_transient_purge_summary_groups_by_prefix() {
        $now = function_exists('current_time') ? current_time('timestamp') : time();

        update_option('sitepulse_transient_purge_log', [
            [
                'scope'     => 'transient',
                'prefix'    => 'sitepulse_alpha_',
                'deleted'   => 3,
                'unique'    => 3,
                'batches'   => 1,
                'timestamp' => $now,
            ],
            [
                'scope'     => 'site-transient',
                'prefix'    => 'sitepulse_beta_',
                'deleted'   => 5,
                'unique'    => 4,
                'batches'   => 2,
                'timestamp' => $now,
            ],
        ]);

        $summary = sitepulse_calculate_transient_purge_summary();

        $this->assertSame(8, $summary['totals']['deleted']);
        $this->assertSame(7, $summary['totals']['unique']);
        $this->assertNotEmpty($summary['top_prefixes']);
        $this->assertSame('sitepulse_beta_', $summary['top_prefixes'][0]['prefix']);
        $this->assertSame('site-transient', $summary['top_prefixes'][0]['scope']);
    }

    public function test_record_transient_purge_stats_honors_scope_from_stats() {
        delete_option('sitepulse_transient_purge_log');

        sitepulse_record_transient_purge_stats('sitepulse_scope_demo_', [
            'deleted'          => 3,
            'unique_keys'      => ['one', 'two', 'three'],
            'batches'          => 2,
            'object_cache_hit' => false,
            'scope'            => 'site-transient',
        ]);

        $log = sitepulse_get_transient_purge_log();

        $this->assertNotEmpty($log);

        $entry = $log[0];

        $this->assertSame('site-transient', $entry['scope']);
        $this->assertSame(3, $entry['deleted']);
        $this->assertSame(3, $entry['unique']);
        $this->assertSame(2, $entry['batches']);
    }
}
