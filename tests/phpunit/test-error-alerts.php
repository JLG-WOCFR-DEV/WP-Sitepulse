<?php
/**
 * Tests for error alert log scanning and cooldown behavior.
 */

class Sitepulse_Error_Alerts_Test extends WP_UnitTestCase {
    /**
     * Captured calls to wp_mail.
     *
     * @var array
     */
    private $sent_mail = [];

    public static function wpSetUpBeforeClass($factory) {
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/error_alerts.php';
    }

    protected function set_up(): void {
        parent::set_up();

        $this->sent_mail = [];
        $GLOBALS['sitepulse_logger'] = [];
        $GLOBALS['sitepulse_test_log_path'] = null;

        add_filter('pre_wp_mail', [$this, 'intercept_mail'], 10, 2);

        delete_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER);
        delete_option(SITEPULSE_OPTION_ALERT_RECIPIENTS);
        delete_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK);
    }

    protected function tear_down(): void {
        remove_filter('pre_wp_mail', [$this, 'intercept_mail'], 10);

        delete_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER);
        delete_option(SITEPULSE_OPTION_ALERT_RECIPIENTS);
        delete_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK);

        if (!empty($GLOBALS['sitepulse_test_log_path']) && file_exists($GLOBALS['sitepulse_test_log_path'])) {
            unlink($GLOBALS['sitepulse_test_log_path']);
        }

        $GLOBALS['sitepulse_test_log_path'] = null;

        parent::tear_down();
    }

    public function intercept_mail($short_circuit, $atts) {
        $this->sent_mail[] = $atts;

        return true;
    }

    private function create_log_file($contents) {
        $path = tempnam(sys_get_temp_dir(), 'sitepulse-log-');
        file_put_contents($path, $contents);
        $GLOBALS['sitepulse_test_log_path'] = $path;

        return $path;
    }

    public function limit_log_scan_bytes($default) {
        return 64;
    }

    public function test_log_pointer_resets_when_file_shrinks() {
        $log_path = $this->create_log_file("Info line
Another line
");

        update_option(
            SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER,
            [
                'offset' => 999,
                'inode'  => 123,
            ]
        );

        sitepulse_error_alerts_check_debug_log();

        $pointer = get_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, []);
        $this->assertArrayHasKey('offset', $pointer);
        $this->assertArrayHasKey('inode', $pointer);
        $this->assertSame(filesize($log_path), $pointer['offset']);
        $this->assertSame(@fileinode($log_path), $pointer['inode']);
        $this->assertEmpty($this->sent_mail, 'No fatal lines should be reported.');
    }

    public function test_detects_fatal_error_and_enforces_cooldown() {
        $log_path = $this->create_log_file("PHP Fatal error: Boom
");
        update_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, ['alerts@example.com']);

        sitepulse_error_alerts_check_debug_log();

        $this->assertCount(1, $this->sent_mail, 'Fatal error should trigger one alert e-mail.');

        $lock_key = SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_PREFIX . 'php_fatal' . SITEPULSE_TRANSIENT_ERROR_ALERT_LOCK_SUFFIX;
        $this->assertNotFalse(get_transient($lock_key), 'Cooldown lock should be created after sending.');

        file_put_contents($log_path, "PHP Fatal error: Another crash
", FILE_APPEND);
        sitepulse_error_alerts_check_debug_log();

        $this->assertCount(1, $this->sent_mail, 'Cooldown lock must prevent duplicate alerts.');
        $this->assertNotEmpty($GLOBALS['sitepulse_logger'], 'Cooldown should log a skipped notification.');
        $this->assertSame(
            "Alert 'php_fatal' skipped due to active cooldown.",
            end($GLOBALS['sitepulse_logger'])['message']
        );
        $this->assertSame(filesize($log_path), get_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER)['offset']);
    }

    public function test_truncated_scan_skips_partial_line_and_still_detects_fatal() {
        $log_lines = [
            str_repeat('WordPress debug filler ', 15),
            'Notice: Something minor happened',
            'PHP Fatal error: Final crash',
        ];

        $log_path = $this->create_log_file(implode(PHP_EOL, $log_lines) . PHP_EOL);

        update_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, ['alerts@example.com']);

        add_filter('sitepulse_error_alerts_max_log_scan_bytes', [$this, 'limit_log_scan_bytes']);

        try {
            sitepulse_error_alerts_check_debug_log();
        } finally {
            remove_filter('sitepulse_error_alerts_max_log_scan_bytes', [$this, 'limit_log_scan_bytes']);
        }

        $this->assertCount(1, $this->sent_mail, 'Fatal error within truncated scan window should send an alert.');

        $pointer = get_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER, []);
        $this->assertSame(filesize($log_path), $pointer['offset'], 'Pointer should advance to end of truncated file.');
        $this->assertArrayHasKey('inode', $pointer, 'Pointer data should persist inode information.');
    }
}
