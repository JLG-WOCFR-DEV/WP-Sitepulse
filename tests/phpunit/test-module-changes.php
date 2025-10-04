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

    public function test_module_cards_render_metrics_when_data_available(): void {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $now = time();

        update_option(SITEPULSE_OPTION_DEBUG_NOTICES, ['Notice en file']);
        update_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY, [
            [
                'timestamp' => $now - 60,
                'load'      => [0.75, null, null],
                'memory'    => [
                    'usage' => 104857600,
                    'limit' => 209715200,
                ],
            ],
        ]);
        update_option(SITEPULSE_PLUGIN_IMPACT_OPTION, [
            'last_updated' => $now - 300,
            'interval'     => 15 * MINUTE_IN_SECONDS,
            'samples'      => [
                'plugin/example.php' => ['avg_ms' => 12.0],
            ],
        ]);
        update_option(SITEPULSE_OPTION_LAST_LOAD_TIME, 150);
        update_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, [
            [
                'timestamp'            => $now - 120,
                'server_processing_ms' => 150,
            ],
        ]);
        update_option(SITEPULSE_OPTION_UPTIME_LOG, [
            [
                'timestamp' => $now - 120,
                'status'    => true,
            ],
            [
                'timestamp' => $now - 60,
                'status'    => true,
            ],
        ]);
        update_option(SITEPULSE_OPTION_AI_LAST_RUN, $now - HOUR_IN_SECONDS);
        update_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, [
            'updated_at' => $now - 180,
            'offset'     => 0,
        ]);

        ob_start();
        sitepulse_settings_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('Alertes', $output);
        $this->assertStringContainsString('1 en attente', $output);
        $this->assertStringContainsString('Temps de réponse', $output);
        $this->assertStringContainsString('150', $output);
        $this->assertStringContainsString('Statut actuel', $output);
        $this->assertStringContainsString('En ligne', $output);
        $this->assertStringContainsString('Dernière analyse', $output);
        $this->assertStringContainsString('Il y a', $output);
        $this->assertStringContainsString('Aucun relevé', $output);
    }
}
