<?php
/**
 * Tests for SitePulse debug notices.
 */

class Sitepulse_Debug_Notices_Test extends WP_UnitTestCase {
    public static function wpSetUpBeforeClass($factory) {
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/debug-notices.php';
    }

    protected function set_up(): void {
        parent::set_up();

        update_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);
        sitepulse_debug_notice_registry(null, true);
    }

    protected function tear_down(): void {
        sitepulse_debug_notice_registry(null, true);
        delete_option(SITEPULSE_OPTION_DEBUG_NOTICES);

        parent::tear_down();
    }

    private function get_admin_notices_callback_count(): int {
        global $wp_filter;

        if (!isset($wp_filter['admin_notices']) || !is_object($wp_filter['admin_notices'])) {
            return 0;
        }

        $count = 0;

        foreach ($wp_filter['admin_notices']->callbacks as $callbacks) {
            $count += count($callbacks);
        }

        return $count;
    }

    public function test_debug_notices_queue_and_rendering() {
        $this->assertSame(0, has_action('admin_notices', 'sitepulse_display_queued_debug_notices'));

        set_current_screen('front');
        sitepulse_schedule_debug_admin_notice('Rotation failed', 'error');

        $queued = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);
        $this->assertCount(1, $queued);
        $this->assertSame('Rotation failed', $queued[0]['message']);
        $this->assertSame('error', $queued[0]['level']);

        sitepulse_schedule_debug_admin_notice('Rotation failed', 'error');
        $this->assertCount(1, get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []));

        sitepulse_schedule_debug_admin_notice('Write failure', 'warning');
        $queued = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);
        $this->assertCount(2, $queued);

        sitepulse_debug_notice_registry(null, true);
        set_current_screen('dashboard');
        ob_start();
        sitepulse_display_queued_debug_notices();
        $output = ob_get_clean();

        $expected_output = '<div class="notice notice-error"><p>Rotation failed</p></div>' .
            '<div class="notice notice-warning"><p>Write failure</p></div>';
        $this->assertSame($expected_output, $output);
        $this->assertSame([], get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []));

        $initial_hook_count = $this->get_admin_notices_callback_count();
        sitepulse_schedule_debug_admin_notice('Immediate notice', 'info');
        $after_hook_count = $this->get_admin_notices_callback_count();
        $this->assertSame($initial_hook_count + 1, $after_hook_count);
        $this->assertSame([], get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []));

        ob_start();
        do_action('admin_notices');
        $immediate_output = ob_get_clean();

        $this->assertStringContainsString(
            '<div class="notice notice-info"><p>Immediate notice</p></div>',
            $immediate_output
        );
    }
}
