<?php
/**
 * Tests for the uptime tracker module.
 */

class Sitepulse_Uptime_Tracker_Test extends WP_UnitTestCase {
    /**
     * Queue of mocked HTTP responses.
     *
     * @var array
     */
    private $http_queue = [];

    /**
     * Last captured HTTP arguments.
     *
     * @var array
     */
    private $last_http_args = [];

    /**
     * Last captured HTTP URL.
     *
     * @var string
     */
    private $last_http_url = '';

    /**
     * Registers the module under test.
     */
    public static function wpSetUpBeforeClass($factory) {
        $module = dirname(__DIR__, 2) . '/sitepulse_FR/modules/uptime_tracker.php';
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/functions.php';
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/admin-settings.php';
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/debug-notices.php';
        require_once $module;
    }

    protected function set_up(): void {
        parent::set_up();

        $this->http_queue = [];
        $this->last_http_args = [];
        $this->last_http_url = '';
        $GLOBALS['sitepulse_logger'] = [];

        add_filter('pre_http_request', [$this, 'mock_http_request'], 10, 3);
        add_filter('sitepulse_uptime_consecutive_failures', [$this, 'force_failure_threshold'], 10, 2);

        delete_option(SITEPULSE_OPTION_UPTIME_LOG);
        delete_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK);
        delete_option(SITEPULSE_OPTION_UPTIME_ARCHIVE);
        delete_option(SITEPULSE_OPTION_UPTIME_URL);
        delete_option(SITEPULSE_OPTION_UPTIME_TIMEOUT);
        delete_option(SITEPULSE_OPTION_UPTIME_FREQUENCY);
        delete_option(SITEPULSE_OPTION_UPTIME_AGENTS);
        delete_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE);
        delete_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS);
        delete_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_NOTICES);
    }

    protected function tear_down(): void {
        remove_filter('pre_http_request', [$this, 'mock_http_request'], 10);
        remove_filter('sitepulse_uptime_consecutive_failures', [$this, 'force_failure_threshold'], 10);

        delete_option(SITEPULSE_OPTION_UPTIME_LOG);
        delete_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK);
        delete_option(SITEPULSE_OPTION_UPTIME_ARCHIVE);
        delete_option(SITEPULSE_OPTION_UPTIME_URL);
        delete_option(SITEPULSE_OPTION_UPTIME_TIMEOUT);
        delete_option(SITEPULSE_OPTION_UPTIME_FREQUENCY);
        delete_option(SITEPULSE_OPTION_UPTIME_AGENTS);
        delete_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE);
        delete_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS);
        delete_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_NOTICES);

        parent::tear_down();
    }

    /**
     * Forces the uptime tracker to escalate on the second failure.
     *
     * @param int $default Default threshold.
     * @return int
     */
    public function force_failure_threshold($default, $streak = 0) {
        return 2;
    }

    /**
     * Supplies deterministic HTTP responses to the uptime tracker.
     *
     * @return mixed
     */
    public function mock_http_request($preempt, $args, $url) {
        if (empty($this->http_queue)) {
            $this->fail('Unexpected HTTP request; the queue is empty.');
        }

        $this->last_http_args = $args;
        $this->last_http_url = $url;

        return array_shift($this->http_queue);
    }

    /**
     * Adds a mocked HTTP response.
     *
     * @param mixed $response Response to enqueue.
     */
    private function enqueue_response($response) {
        $this->http_queue[] = $response;
    }

    public function test_uptime_tracker_handles_network_errors_and_recovery() {
        $this->enqueue_response(new WP_Error('timeout', 'Request timeout'));
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertCount(1, $log, 'Expected one log entry after first WP_Error.');
        $this->assertSame('unknown', $log[0]['status']);
        $this->assertSame('Request timeout', $log[0]['error']);
        $this->assertSame('default', $log[0]['agent']);
        $this->assertArrayNotHasKey('incident_start', $log[0], 'Unknown status should not record an incident start.');
        $this->assertSame(1, get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0));

        $last_log = end($GLOBALS['sitepulse_logger']);
        $this->assertSame('WARNING', $last_log['level']);

        $this->enqueue_response(new WP_Error('timeout', 'Request timeout'));
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertCount(2, $log, 'Expected two log entries after consecutive WP_Error.');
        $this->assertSame(2, get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0));
        $this->assertSame('default', $log[1]['agent']);

        $last_log = end($GLOBALS['sitepulse_logger']);
        $this->assertSame('ALERT', $last_log['level']);

        $this->enqueue_response(['response' => ['code' => 200]]);
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertTrue(end($log)['status']);
        $this->assertSame('default', end($log)['agent']);
        $this->assertSame(0, get_option(SITEPULSE_OPTION_UPTIME_FAILURE_STREAK, 0));
    }

    public function test_mixed_outage_preserves_incident_start_across_unknown_samples() {
        $this->enqueue_response(['response' => ['code' => 500]]);
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertFalse($log[0]['status']);
        $this->assertArrayHasKey('incident_start', $log[0]);
        $incident_start = $log[0]['incident_start'];
        $this->assertSame('default', $log[0]['agent']);

        $this->enqueue_response(new WP_Error('timeout', 'Temporary glitch'));
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertSame('unknown', $log[1]['status']);
        $this->assertArrayNotHasKey('incident_start', $log[1]);
        $this->assertSame('default', $log[1]['agent']);

        $this->enqueue_response(['response' => ['code' => 500]]);
        sitepulse_run_uptime_check();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertFalse($log[2]['status']);
        $this->assertSame($incident_start, $log[2]['incident_start']);
        $this->assertSame('default', $log[2]['agent']);
    }

    public function test_custom_uptime_settings_are_used_when_defined() {
        update_option(SITEPULSE_OPTION_UPTIME_URL, 'https://status.example.test/ping');
        update_option(SITEPULSE_OPTION_UPTIME_TIMEOUT, 25);

        $this->enqueue_response(['response' => ['code' => 200]]);
        sitepulse_run_uptime_check();

        $this->assertSame('https://status.example.test/ping', $this->last_http_url);
        $this->assertIsArray($this->last_http_args);
        $this->assertArrayHasKey('timeout', $this->last_http_args);
        $this->assertSame(25, $this->last_http_args['timeout']);
        $this->assertArrayHasKey('sslverify', $this->last_http_args);

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertSame('default', end($log)['agent']);
    }

    public function test_run_updates_daily_archive() {
        $this->enqueue_response(['response' => ['code' => 200]]);
        sitepulse_run_uptime_check();

        $archive = get_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, []);
        $this->assertNotEmpty($archive, 'Archive should contain the first successful check.');
        $day_key = array_key_last($archive);
        $this->assertSame(1, $archive[$day_key]['up']);
        $this->assertSame(1, $archive[$day_key]['total']);

        $this->enqueue_response(['response' => ['code' => 500]]);
        sitepulse_run_uptime_check();

        $archive = get_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, []);
        $this->assertSame(1, $archive[$day_key]['down']);
        $this->assertSame(2, $archive[$day_key]['total']);
    }

    public function test_normalize_log_uses_five_minute_schedule_interval() {
        update_option(SITEPULSE_OPTION_UPTIME_FREQUENCY, 'sitepulse_uptime_five_minutes');
        wp_get_schedules();

        $log = [
            ['status' => true],
            ['status' => false],
            ['status' => true],
        ];

        $normalized = sitepulse_normalize_uptime_log($log);

        $this->assertCount(3, $normalized);
        $this->assertArrayHasKey('timestamp', $normalized[0]);
        $this->assertArrayHasKey('timestamp', $normalized[1]);
        $this->assertArrayHasKey('timestamp', $normalized[2]);
        $this->assertSame(5 * MINUTE_IN_SECONDS, $normalized[1]['timestamp'] - $normalized[0]['timestamp']);
        $this->assertSame(5 * MINUTE_IN_SECONDS, $normalized[2]['timestamp'] - $normalized[1]['timestamp']);
    }

    public function test_normalize_log_uses_fifteen_minute_schedule_interval() {
        update_option(SITEPULSE_OPTION_UPTIME_FREQUENCY, 'sitepulse_uptime_fifteen_minutes');
        wp_get_schedules();

        $log = [
            ['status' => false],
            ['status' => true],
            ['status' => false],
            ['status' => true],
        ];

        $normalized = sitepulse_normalize_uptime_log($log);

        $this->assertCount(4, $normalized);
        $this->assertArrayHasKey('timestamp', $normalized[0]);
        $this->assertArrayHasKey('timestamp', $normalized[1]);
        $this->assertArrayHasKey('timestamp', $normalized[2]);
        $this->assertArrayHasKey('timestamp', $normalized[3]);
        $this->assertSame(15 * MINUTE_IN_SECONDS, $normalized[1]['timestamp'] - $normalized[0]['timestamp']);
        $this->assertSame(15 * MINUTE_IN_SECONDS, $normalized[2]['timestamp'] - $normalized[1]['timestamp']);
        $this->assertSame(15 * MINUTE_IN_SECONDS, $normalized[3]['timestamp'] - $normalized[2]['timestamp']);
    }

    public function test_normalize_log_falls_back_to_default_interval_when_schedule_missing() {
        update_option(SITEPULSE_OPTION_UPTIME_FREQUENCY, 'sitepulse_uptime_unknown');

        $filter = static function () {
            return 'not-an-array';
        };

        add_filter('cron_schedules', $filter, PHP_INT_MAX);

        $log = [
            ['status' => true],
            ['status' => false],
            ['status' => true],
        ];

        $normalized = sitepulse_normalize_uptime_log($log);

        remove_filter('cron_schedules', $filter, PHP_INT_MAX);

        $expected_interval = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;

        $this->assertCount(3, $normalized);
        $this->assertArrayHasKey('timestamp', $normalized[0]);
        $this->assertArrayHasKey('timestamp', $normalized[1]);
        $this->assertArrayHasKey('timestamp', $normalized[2]);
        $this->assertSame($expected_interval, $normalized[1]['timestamp'] - $normalized[0]['timestamp']);
        $this->assertSame($expected_interval, $normalized[2]['timestamp'] - $normalized[1]['timestamp']);
    }

    public function test_uptime_tracker_page_includes_extended_metrics() {
        $now = time();
        $archive = [];

        for ($i = 9; $i >= 0; $i--) {
            $day_timestamp = $now - ($i * DAY_IN_SECONDS);
            $day_key = gmdate('Y-m-d', $day_timestamp);
            $archive[$day_key] = [
                'date'            => $day_key,
                'up'              => 20,
                'down'            => 4,
                'unknown'         => 0,
                'total'           => 24,
                'first_timestamp' => $day_timestamp,
                'last_timestamp'  => $day_timestamp,
            ];
        }

        update_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, $archive, false);
        update_option(SITEPULSE_OPTION_UPTIME_LOG, [
            [
                'timestamp' => $now,
                'status'    => true,
            ],
        ], false);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        ob_start();
        sitepulse_uptime_tracker_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('Disponibilité 7 derniers jours', $output);
        $this->assertStringContainsString('Disponibilité 30 derniers jours', $output);
        $this->assertStringContainsString('Tendance de disponibilité (30 jours)', $output);
        $this->assertStringContainsString('uptime-trend__bar', $output);

        wp_set_current_user(0);
    }

    public function test_remote_queue_processes_multiple_agents() {
        update_option(SITEPULSE_OPTION_UPTIME_AGENTS, [
            'default'        => [
                'label'  => 'Paris',
                'region' => 'eu',
            ],
            'north_america'  => [
                'label'          => 'New York',
                'region'         => 'us',
                'expected_codes' => [200, 503],
            ],
        ]);

        $this->enqueue_response(['response' => ['code' => 200]]);
        $this->enqueue_response(['response' => ['code' => 503]]);

        sitepulse_uptime_schedule_internal_request('default');
        sitepulse_uptime_schedule_internal_request('north_america');

        sitepulse_uptime_process_remote_queue();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertCount(2, $log);
        $entries_by_agent = [];

        foreach ($log as $entry) {
            $entries_by_agent[$entry['agent']] = $entry;
        }

        $this->assertTrue($entries_by_agent['default']['status']);
        $this->assertFalse($entries_by_agent['north_america']['status']);

        $archive = get_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, []);
        $this->assertNotEmpty($archive);
        $day_key = array_key_last($archive);
        $this->assertSame(1, $archive[$day_key]['agents']['default']['up']);
        $this->assertSame(1, $archive[$day_key]['agents']['north_america']['down']);

        $agent_metrics = sitepulse_calculate_agent_uptime_metrics($archive, 7);
        $this->assertArrayHasKey('default', $agent_metrics);
        $this->assertArrayHasKey('north_america', $agent_metrics);
        $this->assertSame(100.0, $agent_metrics['default']['uptime']);
        $this->assertSame(0.0, $agent_metrics['north_america']['uptime']);

        $region_metrics = sitepulse_calculate_region_uptime_metrics($agent_metrics, sitepulse_uptime_get_agents());
        $this->assertArrayHasKey('eu', $region_metrics);
        $this->assertArrayHasKey('us', $region_metrics);
        $this->assertSame(['default'], $region_metrics['eu']['agents']);
        $this->assertSame(['north_america'], $region_metrics['us']['agents']);
        $this->assertSame(100.0, $region_metrics['eu']['uptime']);
        $this->assertSame(0.0, $region_metrics['us']['uptime']);
    }

    public function test_internal_queue_uses_utc_timestamps() {
        update_option('timezone_string', '');
        update_option('gmt_offset', 5);

        sitepulse_uptime_schedule_internal_request('default');

        $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);
        $this->assertNotEmpty($queue, 'Expected one queued request.');

        $entry = $queue[0];
        $this->assertArrayHasKey('scheduled_at', $entry);
        $this->assertArrayHasKey('created_at', $entry);

        $expected_gmt = current_time('timestamp', true);
        $this->assertEqualsWithDelta($expected_gmt, $entry['scheduled_at'], 2, 'Scheduled timestamp should use UTC.');
        $this->assertEqualsWithDelta($expected_gmt, $entry['created_at'], 2, 'Creation timestamp should use UTC.');

        $local_now = current_time('timestamp');
        $this->assertGreaterThan(3000, abs($local_now - $entry['scheduled_at']), 'Timestamps should not use the site offset.');

        update_option('gmt_offset', 0);
        update_option('timezone_string', '');
    }

    public function test_remote_queue_respects_size_limit() {
        $limit_filter = function () {
            return 3;
        };

        add_filter('sitepulse_uptime_remote_queue_max_size', $limit_filter);

        $base = current_time('timestamp', true);

        for ($i = 0; $i < 5; $i++) {
            sitepulse_uptime_schedule_internal_request('agent_' . $i, [], $base + $i);
        }

        $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);
        $this->assertCount(3, $queue, 'Queue should be trimmed to the configured limit.');

        $agents = array_map(function ($item) {
            return $item['agent'];
        }, $queue);

        $this->assertSame(['agent_0', 'agent_1', 'agent_2'], $agents, 'Oldest items should be preserved when trimming.');

        remove_filter('sitepulse_uptime_remote_queue_max_size', $limit_filter);
    }

    public function test_remote_queue_prunes_expired_entries() {
        $ttl_filter = function () {
            return MINUTE_IN_SECONDS;
        };

        add_filter('sitepulse_uptime_remote_queue_item_ttl', $ttl_filter);

        $base = current_time('timestamp', true);

        update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, [[
            'agent'       => 'legacy',
            'payload'     => [],
            'scheduled_at'=> $base - HOUR_IN_SECONDS,
            'created_at'  => $base - HOUR_IN_SECONDS,
        ]]);

        sitepulse_uptime_schedule_internal_request('fresh', [], $base);

        $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);

        $this->assertCount(1, $queue, 'Expired entries should be pruned when scheduling.');
        $this->assertSame('fresh', $queue[0]['agent']);

        remove_filter('sitepulse_uptime_remote_queue_item_ttl', $ttl_filter);
    }

    public function test_remote_queue_deduplicates_identical_requests() {
        $base = current_time('timestamp', true);

        sitepulse_uptime_schedule_internal_request('default', ['timeout' => 10], $base);
        sitepulse_uptime_schedule_internal_request('default', ['timeout' => 20], $base);

        $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);

        $this->assertCount(1, $queue, 'Duplicate requests should be collapsed.');
        $this->assertSame(['timeout' => 20], $queue[0]['payload']);
    }

    public function test_maintenance_window_skips_remote_requests() {
        $now = current_time('timestamp');

        update_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS, [
            [
                'agent' => 'default',
                'start' => $now - MINUTE_IN_SECONDS,
                'end'   => $now + MINUTE_IN_SECONDS,
                'label' => 'Upgrade',
            ],
        ]);

        sitepulse_uptime_schedule_internal_request('default');
        sitepulse_uptime_process_remote_queue();

        $log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $this->assertCount(1, $log);
        $this->assertSame('maintenance', $log[0]['status']);
        $this->assertSame('default', $log[0]['agent']);
        $this->assertArrayHasKey('maintenance_start', $log[0]);
        $this->assertArrayHasKey('maintenance_end', $log[0]);

        $archive = get_option(SITEPULSE_OPTION_UPTIME_ARCHIVE, []);
        $day_key = array_key_last($archive);
        $this->assertSame(1, $archive[$day_key]['maintenance']);
        $this->assertSame(1, $archive[$day_key]['agents']['default']['maintenance']);

        $maintenance_notices = sitepulse_uptime_get_maintenance_notice_log();
        $this->assertNotEmpty($maintenance_notices);
        $this->assertStringContainsString('Maintenance', $maintenance_notices[0]['message']);
    }

    public function test_weekly_maintenance_window_detection() {
        $timezone = wp_timezone();
        $now = current_time('timestamp');
        $start_datetime = (new DateTimeImmutable('@' . $now))->setTimezone($timezone)->modify('-2 minutes');
        $day = (int) $start_datetime->format('N');
        $time = $start_datetime->format('H:i');

        $definitions = sitepulse_sanitize_uptime_maintenance_windows([
            [
                'agent'      => 'default',
                'recurrence' => 'weekly',
                'day'        => $day,
                'time'       => $time,
                'duration'   => 5,
                'label'      => 'Weekly maintenance',
            ],
        ]);

        update_option(SITEPULSE_OPTION_UPTIME_MAINTENANCE_WINDOWS, $definitions);

        $timestamp_in_window = $start_datetime->modify('+1 minutes')->getTimestamp();
        $this->assertTrue(sitepulse_uptime_is_in_maintenance_window('default', $timestamp_in_window));

        $resolved_windows = sitepulse_uptime_get_maintenance_windows($timestamp_in_window);
        $this->assertNotEmpty($resolved_windows);

        $active_windows = array_filter($resolved_windows, function ($window) {
            return !empty($window['is_active']);
        });

        $this->assertNotEmpty($active_windows);
    }

    public function test_rest_endpoint_allows_remote_workers_to_queue_checks() {
        add_filter('sitepulse_uptime_rest_schedule_permission', '__return_true');

        $request = new WP_REST_Request('POST', '/sitepulse/v1/uptime/schedule');
        $request->set_param('agent', 'default');
        $request->set_param('timestamp', current_time('timestamp'));
        $request->set_param('payload', ['timeout' => 15]);

        $response = sitepulse_uptime_rest_schedule_callback($request);
        $data = $response->get_data();

        $this->assertTrue($data['queued']);
        $this->assertSame('default', $data['agent']);

        $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);
        $this->assertNotEmpty($queue);
        $this->assertSame('default', $queue[0]['agent']);
        $this->assertSame(['timeout' => 15], $queue[0]['payload']);

        remove_filter('sitepulse_uptime_rest_schedule_permission', '__return_true');
    }
}
