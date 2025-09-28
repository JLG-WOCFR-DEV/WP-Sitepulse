<?php
/**
 * Tests for handling module activation option changes.
 */

class Sitepulse_Module_Changes_Test extends WP_UnitTestCase {
    /** @var string */
    protected $module = 'uptime_tracker';

    /** @var string */
    protected $hook;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!function_exists('sitepulse_handle_module_changes')) {
            require_once dirname(__DIR__, 2) . '/sitepulse_FR/sitepulse.php';
        }
    }

    protected function setUp(): void {
        parent::setUp();

        $this->hook = sitepulse_get_cron_hook($this->module);

        delete_option(SITEPULSE_OPTION_CRON_WARNINGS);
        delete_option(SITEPULSE_OPTION_ACTIVE_MODULES);

        if (is_string($this->hook) && $this->hook !== '') {
            wp_clear_scheduled_hook($this->hook);
        }
    }

    protected function tearDown(): void {
        if (is_string($this->hook) && $this->hook !== '') {
            wp_clear_scheduled_hook($this->hook);
        }

        parent::tearDown();
    }

    public function test_deactivated_module_clears_cron_warning(): void {
        update_option(
            SITEPULSE_OPTION_CRON_WARNINGS,
            [
                $this->module => ['message' => 'Existing warning'],
                'other'       => ['message' => 'Keep me'],
            ],
            false
        );

        update_option(
            SITEPULSE_OPTION_ACTIVE_MODULES,
            [$this->module, 'other'],
            false
        );

        if (is_string($this->hook) && $this->hook !== '') {
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, $this->hook);
        }

        sitepulse_handle_module_changes([
            $this->module,
            'other',
        ], [
            'other',
        ]);

        if (is_string($this->hook) && $this->hook !== '') {
            $this->assertFalse(
                wp_next_scheduled($this->hook),
                'Deactivated module should remove its scheduled cron hook.'
            );
        }

        $warnings = get_option(SITEPULSE_OPTION_CRON_WARNINGS, []);

        $this->assertIsArray($warnings, 'Cron warnings option should remain an array.');
        $this->assertArrayNotHasKey(
            $this->module,
            $warnings,
            'Deactivating a module should clear its stored cron warning.'
        );
        $this->assertArrayHasKey(
            'other',
            $warnings,
            'Warnings for unrelated modules should be preserved.'
        );
    }
}
