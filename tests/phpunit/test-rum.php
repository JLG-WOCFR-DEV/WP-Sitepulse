<?php
/**
 * Tests for the RUM (Web Vitals) helper functions.
 */

require_once __DIR__ . '/includes/stubs.php';

class Sitepulse_Rum_Test extends WP_UnitTestCase {
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/speed_analyzer.php';
    }

    protected function setUp(): void {
        parent::setUp();

        update_option('sitepulse_active_modules', ['speed_analyzer']);

        if (function_exists('sitepulse_rum_maybe_upgrade_schema')) {
            sitepulse_rum_maybe_upgrade_schema();
        }

        $table = function_exists('sitepulse_rum_get_table_name') ? sitepulse_rum_get_table_name() : '';

        if ($table !== '') {
            global $wpdb;

            if ($wpdb instanceof wpdb) {
                $wpdb->query("TRUNCATE TABLE {$table}");
            }
        }

        if (function_exists('sitepulse_rum_flush_cache')) {
            sitepulse_rum_flush_cache();
        } else {
            delete_option('sitepulse_rum_cache_keys');
        }
    }

    public function test_grade_metric_boundaries(): void {
        $this->assertSame('good', sitepulse_rum_grade_metric('LCP', 2400));
        $this->assertSame('needs_improvement', sitepulse_rum_grade_metric('LCP', 3500));
        $this->assertSame('poor', sitepulse_rum_grade_metric('LCP', 4100));

        $this->assertSame('good', sitepulse_rum_grade_metric('FID', 90));
        $this->assertSame('needs_improvement', sitepulse_rum_grade_metric('FID', 250));
        $this->assertSame('poor', sitepulse_rum_grade_metric('FID', 400));

        $this->assertSame('good', sitepulse_rum_grade_metric('CLS', 0.09));
        $this->assertSame('needs_improvement', sitepulse_rum_grade_metric('CLS', 0.2));
        $this->assertSame('poor', sitepulse_rum_grade_metric('CLS', 0.4));
    }

    public function test_aggregate_computes_percentiles(): void {
        $now = time();

        $samples = [
            ['metric' => 'LCP', 'value' => 1800, 'rating' => 'good', 'path' => '/', 'device' => 'desktop', 'navigation_type' => 'navigate', 'recorded_at' => $now - 30],
            ['metric' => 'LCP', 'value' => 3200, 'rating' => 'needs_improvement', 'path' => '/', 'device' => 'desktop', 'navigation_type' => 'navigate', 'recorded_at' => $now - 20],
            ['metric' => 'FID', 'value' => 120, 'rating' => 'needs_improvement', 'path' => '/', 'device' => 'desktop', 'navigation_type' => 'navigate', 'recorded_at' => $now - 15],
            ['metric' => 'CLS', 'value' => 0.05, 'rating' => 'good', 'path' => '/blog', 'device' => 'mobile', 'navigation_type' => 'navigate', 'recorded_at' => $now - 10],
            ['metric' => 'CLS', 'value' => 0.3, 'rating' => 'poor', 'path' => '/blog', 'device' => 'mobile', 'navigation_type' => 'navigate', 'recorded_at' => $now - 5],
        ];

        $stored = sitepulse_rum_store_samples($samples);
        $this->assertSame(5, $stored);

        $aggregates = sitepulse_rum_calculate_aggregates(['range_days' => 7]);

        $this->assertSame(5, $aggregates['sample_count']);
        $this->assertArrayHasKey('LCP', $aggregates['summary']);
        $this->assertArrayHasKey('CLS', $aggregates['summary']);

        $lcp_summary = $aggregates['summary']['LCP'];
        $this->assertEqualsWithDelta(3130.0, $lcp_summary['p95'], 0.1);
        $this->assertEqualsWithDelta(2850.0, $lcp_summary['p75'], 0.1);
        $this->assertSame(2, $lcp_summary['count']);

        $cls_summary = $aggregates['summary']['CLS'];
        $this->assertEqualsWithDelta(0.175, $cls_summary['average'], 0.001);
        $this->assertSame(2, $cls_summary['count']);

        $this->assertNotEmpty($aggregates['pages']);
        $first_page = $aggregates['pages'][0];
        $this->assertArrayHasKey('metrics', $first_page);
    }

    public function test_rest_ingest_requires_enabled_module(): void {
        update_option('sitepulse_rum_settings', ['enabled' => false]);

        $request = new WP_REST_Request('POST', '/sitepulse/v1/rum');
        $request->set_param('token', 'nope');
        $request->set_param('samples', []);

        $response = sitepulse_rum_rest_ingest($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('sitepulse_rum_disabled', $response->get_error_code());
    }

    public function test_rest_ingest_validates_token_and_payload(): void {
        update_option('sitepulse_rum_settings', ['enabled' => true]);

        $valid_token = sitepulse_rum_get_ingest_token(true);

        $request = new WP_REST_Request('POST', '/sitepulse/v1/rum');
        $request->set_param('token', 'invalid');
        $request->set_param('samples', []);

        $invalid_token_response = sitepulse_rum_rest_ingest($request);
        $this->assertInstanceOf(WP_Error::class, $invalid_token_response);
        $this->assertSame('sitepulse_rum_invalid_token', $invalid_token_response->get_error_code());

        $request->set_param('token', $valid_token);
        $request->set_param('samples', 'not-an-array');

        $invalid_payload_response = sitepulse_rum_rest_ingest($request);
        $this->assertInstanceOf(WP_Error::class, $invalid_payload_response);
        $this->assertSame('sitepulse_rum_invalid_payload', $invalid_payload_response->get_error_code());

        $request->set_param('samples', [['metric' => 'LCP', 'value' => -1]]);

        $empty_batch_response = sitepulse_rum_rest_ingest($request);
        $this->assertInstanceOf(WP_Error::class, $empty_batch_response);
        $this->assertSame('sitepulse_rum_empty_batch', $empty_batch_response->get_error_code());
    }

    public function test_rest_ingest_accepts_valid_samples(): void {
        update_option('sitepulse_rum_settings', ['enabled' => true]);

        $token = sitepulse_rum_get_ingest_token(true);

        $request = new WP_REST_Request('POST', '/sitepulse/v1/rum');
        $request->set_param('token', $token);
        $request->set_param('samples', [
            [
                'metric'  => 'LCP',
                'value'   => 1900,
                'path'    => '/',
                'device'  => 'desktop',
                'rating'  => 'good',
                'timestamp' => time(),
            ],
        ]);

        $response = sitepulse_rum_rest_ingest($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);

        $data = $response->get_data();

        $this->assertSame(1, $data['stored']);
        $this->assertSame(1, $data['received']);
        $this->assertArrayHasKey('retentionDays', $data);

        $table = sitepulse_rum_get_table_name();

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $this->assertSame(1, $count);
    }

    public function test_rest_get_aggregates_returns_data(): void {
        update_option('sitepulse_rum_settings', ['enabled' => true]);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        sitepulse_rum_store_samples([
            [
                'metric'          => 'LCP',
                'value'           => 2100,
                'rating'          => 'good',
                'path'            => '/',
                'path_hash'       => md5('/'),
                'device'          => 'desktop',
                'navigation_type' => 'navigate',
                'recorded_at'     => time(),
            ],
        ]);

        $request = new WP_REST_Request('GET', '/sitepulse/v1/rum/aggregates');
        $response = sitepulse_rum_rest_get_aggregates($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);

        $data = $response->get_data();

        $this->assertSame(1, $data['sample_count']);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('LCP', $data['summary']);
    }

    public function test_cache_is_flushed_when_samples_are_stored(): void {
        update_option('sitepulse_rum_settings', ['enabled' => true]);

        $now = time();

        sitepulse_rum_store_samples([
            [
                'metric'          => 'LCP',
                'value'           => 2000,
                'rating'          => 'good',
                'path'            => '/',
                'path_hash'       => md5('/'),
                'device'          => 'desktop',
                'navigation_type' => 'navigate',
                'recorded_at'     => $now,
            ],
        ]);

        sitepulse_rum_calculate_aggregates(['range_days' => 7]);

        $option_key = function_exists('sitepulse_rum_get_cache_registry_option_key')
            ? sitepulse_rum_get_cache_registry_option_key()
            : 'sitepulse_rum_cache_keys';

        $registry = get_option($option_key, []);

        $this->assertIsArray($registry);
        $this->assertNotEmpty($registry);

        $cache_key = (string) reset($registry);
        $this->assertNotSame('', $cache_key);
        $this->assertNotFalse(get_transient($cache_key));

        sitepulse_rum_store_samples([
            [
                'metric'          => 'FID',
                'value'           => 150,
                'rating'          => 'needs_improvement',
                'path'            => '/',
                'path_hash'       => md5('/'),
                'device'          => 'desktop',
                'navigation_type' => 'navigate',
                'recorded_at'     => $now + 10,
            ],
        ]);

        $this->assertFalse(get_transient($cache_key));
    }
}
