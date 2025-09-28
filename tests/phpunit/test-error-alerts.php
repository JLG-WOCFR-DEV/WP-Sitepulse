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
        $plugin_root = dirname(__DIR__, 2) . '/sitepulse_FR/';

        require_once $plugin_root . 'includes/functions.php';

        if (!defined('SITEPULSE_OPTION_ALERT_INTERVAL')) {
            define('SITEPULSE_OPTION_ALERT_INTERVAL', 'sitepulse_alert_interval');
        }

        require_once $plugin_root . 'modules/error_alerts.php';
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
        delete_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK);
    }

    protected function tear_down(): void {
        remove_filter('pre_wp_mail', [$this, 'intercept_mail'], 10);

        delete_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER);
        delete_option(SITEPULSE_OPTION_ALERT_RECIPIENTS);
        delete_option(SITEPULSE_OPTION_ALERT_INTERVAL);
        delete_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK);
        delete_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK);

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

    public function test_interval_uses_shared_sanitizer() {
        $this->assertTrue(function_exists('sitepulse_sanitize_alert_interval'));

        update_option(SITEPULSE_OPTION_ALERT_INTERVAL, 27);

        $expected = sitepulse_sanitize_alert_interval(27);

        $this->assertSame($expected, sitepulse_error_alerts_get_interval_minutes());
    }

    public function test_schedule_slug_sanitizes_override_with_helper() {
        $override_minutes = 2;
        $expected_minutes = sitepulse_sanitize_alert_interval($override_minutes);

        $this->assertSame(
            'sitepulse_error_alerts_' . $expected_minutes . '_minutes',
            sitepulse_error_alerts_get_schedule_slug($override_minutes)
        );
    }

    public function test_schedule_slug_uses_sanitized_option_value() {
        update_option(SITEPULSE_OPTION_ALERT_INTERVAL, 13);

        $expected_minutes = sitepulse_sanitize_alert_interval(13);

        $this->assertSame(
            'sitepulse_error_alerts_' . $expected_minutes . '_minutes',
            sitepulse_error_alerts_get_schedule_slug()
        );
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

    public function test_windows_style_log_path_is_normalized_and_preserves_separators() {
        update_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, ['alerts@example.com']);

        $windows_path = 'C:\\logs\\debug.log';

        if (file_exists($windows_path)) {
            unlink($windows_path);
        }

        file_put_contents($windows_path, "PHP Fatal error: Crash\n");

        $GLOBALS['sitepulse_test_log_path'] = $windows_path;

        sitepulse_error_alerts_check_debug_log();

        $this->assertCount(1, $this->sent_mail, 'Windows-style path should still trigger fatal alert e-mail.');

        $mail = $this->sent_mail[0];
        $this->assertStringContainsString('C:/logs/debug.log', $mail['message'], 'Normalized path should use forward slashes.');
        $this->assertStringNotContainsString('C:logsdebug.log', $mail['message'], 'Sanitization must preserve directory separators.');
    }

    public function test_cpu_alert_scales_threshold_with_core_count() {
        update_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 2);
        update_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, ['alerts@example.com']);

        $core_filter = static function ($value = null) {
            return 4;
        };

        $load_filter = static function ($load) {
            return [9.5, 0.0, 0.0];
        };

        add_filter('sitepulse_error_alert_cpu_core_count', $core_filter, 10, 1);
        add_filter('sitepulse_error_alerts_cpu_load', $load_filter, 10, 1);

        try {
            sitepulse_error_alerts_check_cpu_load();
        } finally {
            remove_filter('sitepulse_error_alert_cpu_core_count', $core_filter, 10);
            remove_filter('sitepulse_error_alerts_cpu_load', $load_filter, 10);
        }

        $this->assertCount(1, $this->sent_mail, 'High load should trigger one alert e-mail.');

        $mail = $this->sent_mail[0];
        $this->assertStringContainsString('Test Blog', $mail['subject']);
        $this->assertMatchesRegularExpression('/total threshold:\s*8[,.]00/', $mail['message']);
        $this->assertMatchesRegularExpression('/2[,.]38/', $mail['message']);
    }

    public function test_cpu_alert_ignores_load_below_scaled_threshold() {
        update_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 2);
        update_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, ['alerts@example.com']);

        $core_filter = static function ($value = null) {
            return 4;
        };

        $load_filter = static function ($load) {
            return [7.0, 0.0, 0.0];
        };

        add_filter('sitepulse_error_alert_cpu_core_count', $core_filter, 10, 1);
        add_filter('sitepulse_error_alerts_cpu_load', $load_filter, 10, 1);

        try {
            sitepulse_error_alerts_check_cpu_load();
        } finally {
            remove_filter('sitepulse_error_alert_cpu_core_count', $core_filter, 10);
            remove_filter('sitepulse_error_alerts_cpu_load', $load_filter, 10);
        }

        $this->assertCount(0, $this->sent_mail, 'Load below scaled threshold must not trigger an alert.');
        $this->assertFalse(get_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK), 'Cooldown lock should not be created when below the threshold.');
    }

    public function test_alert_messages_strip_control_characters() {
        update_option('blogname', "Control\x07 Site\nName");
        update_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, ['alerts@example.com']);

        $core_filter = static function ($value = null) {
            return 2;
        };

        $load_filter = static function ($load) {
            return [9.5, 0.0, 0.0];
        };

        add_filter('sitepulse_error_alert_cpu_core_count', $core_filter, 10, 1);
        add_filter('sitepulse_error_alerts_cpu_load', $load_filter, 10, 1);

        try {
            sitepulse_error_alerts_check_cpu_load();
        } finally {
            remove_filter('sitepulse_error_alert_cpu_core_count', $core_filter, 10);
            remove_filter('sitepulse_error_alerts_cpu_load', $load_filter, 10);
        }

        $this->assertCount(1, $this->sent_mail, 'CPU alert should send e-mail with sanitized content.');

        $mail = $this->sent_mail[0];
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $mail['subject'], 'Subject must not contain control characters.');
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $mail['message'], 'Message must not contain control characters.');

        $this->sent_mail = [];

        $log_path = $this->create_log_file("PHP Fatal error: Boom\n");
        $weird_path = $log_path . "\x07control.log";
        $this->assertTrue(rename($log_path, $weird_path), 'Renaming log file to include control characters should succeed.');
        $GLOBALS['sitepulse_test_log_path'] = $weird_path;

        sitepulse_error_alerts_check_debug_log();

        $this->assertCount(1, $this->sent_mail, 'Fatal error alert should send sanitized e-mail.');

        $mail = $this->sent_mail[0];
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $mail['subject'], 'Fatal alert subject must not contain control characters.');
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $mail['message'], 'Fatal alert message must not contain control characters.');

        update_option('blogname', 'Test Blog');
    }
}
