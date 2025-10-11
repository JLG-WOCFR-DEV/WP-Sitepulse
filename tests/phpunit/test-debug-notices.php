<?php
/**
 * Tests for SitePulse debug notices.
 */

class Sitepulse_Debug_Notices_Test extends WP_UnitTestCase {
    public static function wpSetUpBeforeClass($factory) {
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/debug-notices.php';

        if (!defined('SITEPULSE_DEBUG_LOG')) {
            require_once dirname(__DIR__, 2) . '/sitepulse_FR/sitepulse.php';
        }
    }

    protected function set_up(): void {
        parent::set_up();

        delete_option(SITEPULSE_OPTION_DEBUG_NOTICES);
        sitepulse_debug_notice_registry(null, true);

        if (function_exists('sitepulse_debug_logging_block_state')) {
            sitepulse_debug_logging_block_state(false);
        }

        if (isset($GLOBALS['sitepulse_log_callable'])) {
            unset($GLOBALS['sitepulse_log_callable']);
        }
    }

    protected function tear_down(): void {
        sitepulse_debug_notice_registry(null, true);
        delete_option(SITEPULSE_OPTION_DEBUG_NOTICES);

        if (isset($GLOBALS['sitepulse_log_callable'])) {
            unset($GLOBALS['sitepulse_log_callable']);
        }

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

    private function get_option_autoload_flag(string $option_name): ?string {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
                $option_name
            )
        ) ?: null;
    }

    public function test_debug_notices_queue_and_rendering() {
        $this->assertSame(0, has_action('admin_notices', 'sitepulse_display_queued_debug_notices'));

        set_current_screen('front');
        sitepulse_schedule_debug_admin_notice('Rotation failed', 'error');

        $queued = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);
        $this->assertCount(1, $queued);
        $this->assertSame('Rotation failed', $queued[0]['message']);
        $this->assertSame('error', $queued[0]['level']);
        $this->assertSame('no', $this->get_option_autoload_flag(SITEPULSE_OPTION_DEBUG_NOTICES));

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
        $this->assertNull($this->get_option_autoload_flag(SITEPULSE_OPTION_DEBUG_NOTICES));

        $initial_hook_count = $this->get_admin_notices_callback_count();
        sitepulse_schedule_debug_admin_notice('Immediate notice', 'info');
        $after_hook_count = $this->get_admin_notices_callback_count();
        $this->assertSame($initial_hook_count + 1, $after_hook_count);
        $this->assertSame([], get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []));
        $this->assertNull($this->get_option_autoload_flag(SITEPULSE_OPTION_DEBUG_NOTICES));

        ob_start();
        do_action('admin_notices');
        $immediate_output = ob_get_clean();

        $this->assertStringContainsString(
            '<div class="notice notice-info"><p>Immediate notice</p></div>',
            $immediate_output
        );
    }

    public function test_debug_log_write_blocked_when_relocation_failed(): void {
        if (!defined('SITEPULSE_DEBUG_LOG') || !function_exists('sitepulse_real_log')) {
            $this->markTestSkipped('Debug log implementation is not available.');
        }

        $previous_context = $GLOBALS['sitepulse_debug_log_security_context'] ?? null;

        $log_path = SITEPULSE_DEBUG_LOG;
        $log_dir  = dirname($log_path);

        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        file_put_contents($log_path, "initial\n");

        set_current_screen('front');
        sitepulse_debug_notice_registry(null, true);
        update_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);

        sitepulse_debug_logging_block_state(false);

        $GLOBALS['sitepulse_log_callable'] = 'sitepulse_real_log';

        $GLOBALS['sitepulse_debug_log_security_context'] = [
            'server_support'       => 'unsupported',
            'relocation_attempted' => true,
            'relocation_success'   => false,
            'relocation_failed'    => true,
            'inside_webroot'       => true,
            'directory_created'    => true,
            'target_directory'     => $log_dir,
        ];

        sitepulse_log('Blocked attempt #1');

        $this->assertSame("initial\n", file_get_contents($log_path), 'Log file should remain unchanged.');
        $this->assertTrue(sitepulse_debug_logging_block_state(), 'Security context should block subsequent writes.');

        $queued_notices = get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []);
        $this->assertCount(1, $queued_notices, 'Warning notice should be queued.');
        $this->assertSame('warning', $queued_notices[0]['level']);
        $this->assertStringContainsString('server appears to ignore', $queued_notices[0]['message']);

        sitepulse_log('Blocked attempt #2');

        $this->assertSame("initial\n", file_get_contents($log_path), 'Subsequent writes should be skipped.');
        $this->assertCount(1, get_option(SITEPULSE_OPTION_DEBUG_NOTICES, []), 'No duplicate notices should be stored.');

        set_current_screen('dashboard');

        if ($previous_context === null) {
            unset($GLOBALS['sitepulse_debug_log_security_context']);
        } else {
            $GLOBALS['sitepulse_debug_log_security_context'] = $previous_context;
        }

        sitepulse_debug_logging_block_state(false);
    }
}
