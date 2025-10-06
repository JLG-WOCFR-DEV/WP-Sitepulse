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

    /**
     * Captured webhook requests.
     *
     * @var array
     */
    private $webhook_requests = [];

    /**
     * Next webhook response override.
     *
     * @var mixed
     */
    private $next_webhook_response = null;

    public static function wpSetUpBeforeClass($factory) {
        $plugin_root = dirname(__DIR__, 2) . '/sitepulse_FR/';

        require_once $plugin_root . 'includes/functions.php';

        if (!defined('SITEPULSE_OPTION_ALERT_INTERVAL')) {
            define('SITEPULSE_OPTION_ALERT_INTERVAL', 'sitepulse_alert_interval');
        }

        if (!defined('SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS')) {
            define('SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS', 'sitepulse_alert_enabled_channels');
        }

        if (!defined('SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD')) {
            define('SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD', 'sitepulse_php_fatal_alert_threshold');
        }

        require_once $plugin_root . 'modules/error_alerts.php';
    }

    protected function set_up(): void {
        parent::set_up();

        $this->sent_mail = [];
        $this->webhook_requests = [];
        $this->next_webhook_response = null;
        $GLOBALS['sitepulse_logger'] = [];
        $GLOBALS['sitepulse_test_log_path'] = null;

        add_filter('pre_wp_mail', [$this, 'intercept_mail'], 10, 2);
        add_filter('pre_http_request', [$this, 'intercept_http_request'], 10, 3);

        delete_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER);
        delete_option(SITEPULSE_OPTION_ALERT_RECIPIENTS);
        delete_option(SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS);
        delete_option(SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD);
        delete_option(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS);
        delete_option(SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS);
        delete_option(SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES);
        delete_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK);
        delete_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK);
    }

    protected function tear_down(): void {
        remove_filter('pre_wp_mail', [$this, 'intercept_mail'], 10);
        remove_filter('pre_http_request', [$this, 'intercept_http_request'], 10);

        delete_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER);
        delete_option(SITEPULSE_OPTION_ALERT_RECIPIENTS);
        delete_option(SITEPULSE_OPTION_ALERT_INTERVAL);
        delete_option(SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS);
        delete_option(SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD);
        delete_option(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS);
        delete_option(SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS);
        delete_option(SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES);
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

    public function intercept_http_request($preempt, $args, $url) {
        $this->webhook_requests[] = [
            'url'  => $url,
            'args' => $args,
        ];

        if ($this->next_webhook_response instanceof WP_Error || is_array($this->next_webhook_response)) {
            $response = $this->next_webhook_response;
        } else {
            $response = [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => '',
                'headers'  => [],
            ];
        }

        $this->next_webhook_response = null;

        return $response;
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

    public function test_cpu_alert_respects_disabled_channel() {
        update_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, ['alerts@example.com']);
        update_option(SITEPULSE_OPTION_CPU_ALERT_THRESHOLD, 1);
        update_option(SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS, ['php_fatal']);

        $core_filter = static function () {
            return 2;
        };

        $load_filter = static function () {
            return [4.0, 0.0, 0.0];
        };

        add_filter('sitepulse_error_alert_cpu_core_count', $core_filter, 10, 1);
        add_filter('sitepulse_error_alerts_cpu_load', $load_filter, 10, 1);

        try {
            sitepulse_error_alerts_check_cpu_load();
        } finally {
            remove_filter('sitepulse_error_alert_cpu_core_count', $core_filter, 10);
            remove_filter('sitepulse_error_alerts_cpu_load', $load_filter, 10);
        }

        $this->assertCount(0, $this->sent_mail, 'CPU alert should be ignored when the channel is disabled.');
    }

    public function test_php_fatal_threshold_requires_multiple_entries() {
        update_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, ['alerts@example.com']);
        update_option(SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD, 2);

        $log_path = $this->create_log_file("PHP Fatal error: First crash\n");

        sitepulse_error_alerts_check_debug_log();

        $this->assertCount(0, $this->sent_mail, 'Single fatal entry should not trigger when threshold is higher.');

        $this->sent_mail = [];

        file_put_contents($log_path, "PHP Fatal error: First crash\nPHP Fatal error: Second crash\n");
        delete_option(SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER);

        sitepulse_error_alerts_check_debug_log();

        $this->assertCount(1, $this->sent_mail, 'Multiple fatal entries should trigger once threshold is met.');
    }

    public function test_send_test_message_requires_recipients() {
        $result = sitepulse_error_alerts_send_test_message();

        $this->assertInstanceOf(WP_Error::class, $result, 'Test message without recipients should return an error.');
        $this->assertSame('sitepulse_no_alert_recipients', $result->get_error_code());
    }

    public function test_send_test_message_lists_channels() {
        update_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, ['alerts@example.com']);
        update_option(SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS, ['cpu']);

        $result = sitepulse_error_alerts_send_test_message();

        $this->assertTrue($result, 'Test message should be sent successfully with recipients.');
        $this->assertNotEmpty($this->sent_mail, 'Test message should go through wp_mail.');

        $mail = $this->sent_mail[0];
        $this->assertStringContainsString('Charge CPU', $mail['message'], 'Active channel label should be listed in the test message.');
    }

    public function test_send_test_message_requires_webhook_configuration() {
        $result = sitepulse_error_alerts_send_test_message('webhook');

        $this->assertInstanceOf(WP_Error::class, $result, 'Webhook test should fail when no endpoint is configured.');
        $this->assertSame('sitepulse_no_webhooks', $result->get_error_code());
    }

    public function test_send_test_message_triggers_webhook() {
        update_option(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS, ['webhook']);
        update_option(SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS, ['https://example.com/webhook']);

        $result = sitepulse_error_alerts_send_test_message('webhook');

        $this->assertTrue($result, 'Webhook test should succeed with a configured endpoint.');
        $this->assertNotEmpty($this->webhook_requests, 'Webhook test should perform an HTTP request.');
        $request = $this->webhook_requests[0];
        $this->assertSame('https://example.com/webhook', $request['url']);
        $payload = json_decode($request['args']['body'], true);
        $this->assertSame('test_alert', $payload['type']);
    }

    public function test_alert_skipped_when_severity_disabled() {
        update_option(SITEPULSE_OPTION_ALERT_RECIPIENTS, ['alerts@example.com']);
        update_option(SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES, ['critical']);

        $result = sitepulse_error_alert_send('cpu', 'Subject', 'Message', 'warning');

        $this->assertFalse($result, 'Alert should be skipped when severity is disabled.');
        $this->assertEmpty($this->sent_mail, 'No e-mail should be sent if severity is disabled.');
        $this->assertNotEmpty($GLOBALS['sitepulse_logger'], 'A log entry should be added for skipped severity.');
        $this->assertSame("Alert 'cpu' skipped because severity 'warning' is disabled.", end($GLOBALS['sitepulse_logger'])['message']);
    }

    public function test_webhook_dispatch_occurs_when_configured() {
        update_option(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS, ['webhook']);
        update_option(SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS, ['https://example.com/hook']);

        $result = sitepulse_error_alert_send('cpu', 'Subject', 'Message', 'warning');

        $this->assertTrue($result, 'Webhook dispatch should be considered a success.');
        $this->assertEmpty($this->sent_mail, 'E-mail channel should not be used when disabled.');
        $this->assertCount(1, $this->webhook_requests, 'A single webhook request should be recorded.');
        $request = $this->webhook_requests[0];
        $this->assertSame('https://example.com/hook', $request['url']);
        $payload = json_decode($request['args']['body'], true);
        $this->assertIsArray($payload, 'Webhook payload should be JSON encoded.');
        $this->assertSame('cpu', $payload['type']);
        $this->assertSame('warning', $payload['severity']);
    }

    public function test_slack_webhook_payload_uses_blocks() {
        update_option(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS, ['webhook']);
        update_option(SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS, ['https://hooks.slack.com/services/T000/B000/XXX']);

        sitepulse_error_alert_send('php_fatal', 'Slack Subject', 'Slack message body', 'critical');

        $this->assertCount(1, $this->webhook_requests, 'Slack webhook should trigger exactly one request.');
        $request = $this->webhook_requests[0];
        $this->assertSame('https://hooks.slack.com/services/T000/B000/XXX', $request['url']);
        $this->assertArrayHasKey('headers', $request['args']);
        $this->assertSame('application/json; charset=utf-8', $request['args']['headers']['Content-Type']);

        $payload = json_decode($request['args']['body'], true);
        $this->assertIsArray($payload, 'Slack payload should be JSON.');
        $this->assertArrayHasKey('blocks', $payload, 'Slack payload should include Block Kit data.');
        $this->assertArrayHasKey('text', $payload, 'Slack payload should include a headline.');
        $this->assertStringStartsWith(':rotating_light:', $payload['text']);
        $this->assertSame('section', $payload['blocks'][0]['type']);
        $this->assertStringContainsString('Slack Subject', $payload['blocks'][0]['text']['text']);
    }

    public function test_teams_webhook_payload_uses_message_card() {
        update_option(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS, ['webhook']);
        update_option(SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS, ['https://outlook.office.com/webhook/XYZ']);

        sitepulse_error_alert_send('cpu', 'Teams Subject', 'Teams body', 'warning');

        $this->assertCount(1, $this->webhook_requests, 'Teams webhook should trigger exactly one request.');
        $request = $this->webhook_requests[0];
        $payload = json_decode($request['args']['body'], true);
        $this->assertIsArray($payload, 'Teams payload should be JSON.');
        $this->assertSame('MessageCard', $payload['@type']);
        $this->assertSame('Teams Subject', $payload['summary']);
        $this->assertSame('Teams body', $payload['sections'][0]['text']);
        $this->assertSame('warning', strtolower($payload['sections'][0]['facts'][0]['value']));
    }

    public function test_discord_webhook_payload_disables_mentions() {
        update_option(SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS, ['webhook']);
        update_option(SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS, ['https://discord.com/api/webhooks/123/abc']);

        sitepulse_error_alert_send('cpu', 'Discord Subject', 'Discord body', 'info');

        $this->assertCount(1, $this->webhook_requests, 'Discord webhook should trigger exactly one request.');
        $request = $this->webhook_requests[0];
        $payload = json_decode($request['args']['body'], true);
        $this->assertIsArray($payload, 'Discord payload should be JSON.');
        $this->assertArrayHasKey('content', $payload);
        $this->assertStringContainsString('Discord Subject', $payload['content']);
        $this->assertArrayHasKey('allowed_mentions', $payload);
        $this->assertSame([], $payload['allowed_mentions']['parse']);
    }
}
