<?php
/**
 * Tests for the Maintenance Advisor module fallbacks.
 */

require_once __DIR__ . '/includes/stubs.php';

if (!function_exists('sitepulse_get_capability')) {
    function sitepulse_get_capability() {
        return 'manage_options';
    }
}

require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/maintenance_advisor.php';

class Sitepulse_Maintenance_Advisor_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
    }

    public function test_displays_warning_notice_when_update_data_missing(): void {
        add_filter('sitepulse_maintenance_advisor_core_updates', '__return_false');
        add_filter('sitepulse_maintenance_advisor_plugin_updates', [$this, 'filter_return_wp_error']);

        $warnings = [];
        $handler = function($errno, $errstr) use (&$warnings) {
            if ($errno === E_WARNING) {
                $warnings[] = $errstr;
            }

            return false;
        };

        set_error_handler($handler);

        try {
            ob_start();
            sitepulse_maintenance_advisor_page();
            $output = ob_get_clean();
        } finally {
            restore_error_handler();
            remove_filter('sitepulse_maintenance_advisor_core_updates', '__return_false');
            remove_filter('sitepulse_maintenance_advisor_plugin_updates', [$this, 'filter_return_wp_error']);
        }

        $this->assertStringContainsString('notice notice-error', $output);
        $this->assertStringContainsString(
            'Impossible de récupérer les données de mise à jour de WordPress',
            $output,
            'The Maintenance Advisor should explain when update counts are unavailable.'
        );
        $this->assertEmpty(
            $warnings,
            'The Maintenance Advisor fallback must avoid emitting PHP warnings when update data is missing.'
        );
    }

    public function filter_return_wp_error($value) {
        return new WP_Error('sitepulse-tests', 'Mocked failure');
    }
}
