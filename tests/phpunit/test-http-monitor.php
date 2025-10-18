<?php
/**
 * Tests for the outbound HTTP monitor helpers.
 */

require_once __DIR__ . '/includes/stubs.php';

class Sitepulse_Http_Monitor_Test extends WP_UnitTestCase {
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        require_once SITEPULSE_PATH . 'modules/resource_monitor.php';
    }

    protected function setUp(): void {
        parent::setUp();

        if (function_exists('sitepulse_http_monitor_maybe_upgrade_schema')) {
            sitepulse_http_monitor_maybe_upgrade_schema();
        }

        if (function_exists('sitepulse_http_monitor_request_context')) {
            sitepulse_http_monitor_request_context('clear');
        }

        if (function_exists('sitepulse_http_monitor_buffer')) {
            sitepulse_http_monitor_buffer('drain');
        }

        $table = function_exists('sitepulse_http_monitor_get_table_name')
            ? sitepulse_http_monitor_get_table_name()
            : '';

        if ($table !== '') {
            global $wpdb;

            if ($wpdb instanceof wpdb) {
                $wpdb->query("TRUNCATE TABLE {$table}");
            }
        }
    }

    public function test_normalize_event_handles_wp_error(): void {
        $payload = [
            'started_at' => microtime(true) - 0.2,
            'method'     => 'GET',
            'url'        => 'https://api.example.com/users',
            'transport'  => 'WP_Http',
        ];

        $response = new WP_Error('timeout', 'Request timed out');

        $event = sitepulse_http_monitor_normalize_event($payload, $response, 'error');

        $this->assertIsArray($event);
        $this->assertSame('GET', $event['method']);
        $this->assertSame('api.example.com', $event['host']);
        $this->assertSame('/users', $event['path']);
        $this->assertSame('timeout', $event['error_code']);
        $this->assertSame('Request timed out', $event['error_message']);
        $this->assertSame(1, $event['is_error']);
        $this->assertNull($event['status_code']);
    }

    public function test_flush_buffer_persists_events(): void {
        $event = [
            'requested_at'   => time(),
            'method'         => 'POST',
            'host'           => 'api.example.com',
            'path'           => '/v1/data',
            'status_code'    => 201,
            'duration_ms'    => 250,
            'transport'      => 'WP_Http',
            'is_error'       => 0,
            'error_code'     => null,
            'error_message'  => null,
            'response_bytes' => 512,
        ];

        sitepulse_http_monitor_buffer('add', $event);
        sitepulse_http_monitor_flush_buffer();

        global $wpdb;
        $table = sitepulse_http_monitor_get_table_name();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $this->assertSame(1, $count);

        $row = $wpdb->get_row("SELECT * FROM {$table} LIMIT 1", ARRAY_A);
        $this->assertSame('POST', $row['method']);
        $this->assertSame('/v1/data', $row['path']);
        $this->assertSame('api.example.com', $row['host']);
        $this->assertSame('WP_Http', $row['transport']);
        $this->assertSame(201, (int) $row['status_code']);
        $this->assertSame(0, (int) $row['is_error']);
    }

    public function test_get_stats_returns_aggregated_data(): void {
        global $wpdb;
        $table = sitepulse_http_monitor_get_table_name();
        $now = time();

        $wpdb->insert(
            $table,
            [
                'requested_at' => $now - 10,
                'method'       => 'GET',
                'host'         => 'api.example.com',
                'path'         => '/v1/data',
                'status_code'  => 200,
                'duration_ms'  => 120,
                'transport'    => 'WP_Http',
                'is_error'     => 0,
                'created_at'   => gmdate('Y-m-d H:i:s', $now - 10),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s']
        );

        $wpdb->insert(
            $table,
            [
                'requested_at' => $now - 5,
                'method'       => 'GET',
                'host'         => 'api.example.com',
                'path'         => '/v1/data',
                'status_code'  => 503,
                'duration_ms'  => 800,
                'transport'    => 'WP_Http',
                'is_error'     => 1,
                'error_code'   => 'service_unavailable',
                'error_message'=> 'Service unavailable',
                'created_at'   => gmdate('Y-m-d H:i:s', $now - 5),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s']
        );

        $stats = sitepulse_http_monitor_get_stats([
            'since' => $now - 60,
            'limit' => 10,
        ]);

        $this->assertSame(2, $stats['summary']['total']);
        $this->assertSame(1, $stats['summary']['errors']);
        $this->assertNotNull($stats['summary']['errorRate']);
        $this->assertNotEmpty($stats['services']);
        $this->assertSame('api.example.com', $stats['services'][0]['host']);
        $this->assertSame('/v1/data', $stats['services'][0]['path']);
        $this->assertNotEmpty($stats['samples']);
        $this->assertCount(2, $stats['samples']);
    }

    public function test_handle_settings_updates_thresholds(): void {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $nonce = wp_create_nonce('sitepulse_http_monitor_settings');

        $_POST = [
            'sitepulse_http_latency_threshold' => '1750',
            'sitepulse_http_error_rate'       => '37.5',
            'sitepulse_http_retention_days'   => '45',
            '_wpnonce'                        => $nonce,
        ];

        $_REQUEST['_wpnonce'] = $nonce;

        $captured_redirect = null;

        $filter = static function ($location) use (&$captured_redirect) {
            $captured_redirect = $location;
            throw new Exception('redirect');
        };

        add_filter('wp_redirect', $filter);

        try {
            sitepulse_http_monitor_handle_settings();
        } catch (Exception $exception) {
            $this->assertSame('redirect', $exception->getMessage());
        }

        remove_filter('wp_redirect', $filter);

        $this->assertSame(1750, (int) get_option(SITEPULSE_OPTION_HTTP_MONITOR_LATENCY_THRESHOLD_MS));
        $this->assertSame(38, (int) get_option(SITEPULSE_OPTION_HTTP_MONITOR_ERROR_RATE_THRESHOLD));
        $this->assertSame(45, (int) get_option(SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS));
        $this->assertNotNull($captured_redirect);
        $this->assertStringContainsString('sitepulse-resources', $captured_redirect);

        $_POST = [];
        $_REQUEST = [];
    }
}
