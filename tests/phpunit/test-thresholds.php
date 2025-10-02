<?php
/**
 * Tests for SitePulse threshold helpers and defaults.
 */

require_once __DIR__ . '/includes/stubs.php';

class Sitepulse_Thresholds_Test extends WP_UnitTestCase {
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/functions.php';
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/admin-settings.php';
    }

    protected function tearDown(): void {
        delete_option(SITEPULSE_OPTION_SPEED_WARNING_MS);
        delete_option(SITEPULSE_OPTION_SPEED_CRITICAL_MS);
        delete_option(SITEPULSE_OPTION_UPTIME_WARNING_PERCENT);
        delete_option(SITEPULSE_OPTION_REVISION_LIMIT);

        parent::tearDown();
    }

    public function test_speed_thresholds_fall_back_to_defaults_when_missing(): void {
        delete_option(SITEPULSE_OPTION_SPEED_WARNING_MS);
        delete_option(SITEPULSE_OPTION_SPEED_CRITICAL_MS);

        $thresholds = sitepulse_get_speed_thresholds();

        $this->assertSame(SITEPULSE_DEFAULT_SPEED_WARNING_MS, $thresholds['warning']);
        $this->assertSame(SITEPULSE_DEFAULT_SPEED_CRITICAL_MS, $thresholds['critical']);
    }

    public function test_speed_thresholds_use_configured_values(): void {
        update_option(SITEPULSE_OPTION_SPEED_WARNING_MS, 325);
        update_option(SITEPULSE_OPTION_SPEED_CRITICAL_MS, 780);

        $thresholds = sitepulse_get_speed_thresholds();

        $this->assertSame(325, $thresholds['warning']);
        $this->assertSame(780, $thresholds['critical']);
    }

    public function test_speed_thresholds_enforce_relationship_and_defaults(): void {
        update_option(SITEPULSE_OPTION_SPEED_WARNING_MS, 0);
        update_option(SITEPULSE_OPTION_SPEED_CRITICAL_MS, 10);

        $thresholds = sitepulse_get_speed_thresholds();

        $this->assertSame(SITEPULSE_DEFAULT_SPEED_WARNING_MS, $thresholds['warning']);
        $this->assertGreaterThan($thresholds['warning'], $thresholds['critical']);
    }

    public function test_uptime_warning_percentage_is_bounded(): void {
        update_option(SITEPULSE_OPTION_UPTIME_WARNING_PERCENT, 150);
        $this->assertSame(100.0, sitepulse_get_uptime_warning_percentage());

        update_option(SITEPULSE_OPTION_UPTIME_WARNING_PERCENT, -10);
        $this->assertSame(
            (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT,
            sitepulse_get_uptime_warning_percentage()
        );
    }

    public function test_revision_limit_respects_minimum_and_defaults(): void {
        update_option(SITEPULSE_OPTION_REVISION_LIMIT, 0);
        $this->assertSame(SITEPULSE_DEFAULT_REVISION_LIMIT, sitepulse_get_revision_limit());

        update_option(SITEPULSE_OPTION_REVISION_LIMIT, 250);
        $this->assertSame(250, sitepulse_get_revision_limit());
    }
}
