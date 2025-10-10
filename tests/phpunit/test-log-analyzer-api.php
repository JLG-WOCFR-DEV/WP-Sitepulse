<?php
/**
 * Tests for the log analyzer REST endpoint.
 */

require_once __DIR__ . '/includes/stubs.php';

if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

if (!function_exists('sitepulse_get_capability')) {
    function sitepulse_get_capability() {
        return 'manage_options';
    }
}

require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/log_analyzer.php';

class Sitepulse_Log_Analyzer_Api_Test extends WP_UnitTestCase {
    /**
     * @var string|null
     */
    private $logFile;

    protected function setUp(): void {
        parent::setUp();

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));

        $this->logFile = tempnam(sys_get_temp_dir(), 'sitepulse-log');

        if ($this->logFile !== false) {
            file_put_contents(
                $this->logFile,
                "[2024-01-01 00:00:00] PHP Fatal error: Crash detected in /var/www/html/wp-content/plugins/sample.php on line 20\n"
                . "[2024-01-01 01:00:00] PHP Error: Unexpected value provided\n"
                . "[2024-01-01 02:00:00] PHP Warning: Deprecated usage noticed\n"
                . "[2024-01-01 03:00:00] PHP Notice: Informational message\n"
                . "[2024-01-01 04:00:00] PHP Deprecated: Old function called\n"
            );
            $GLOBALS['sitepulse_test_log_path'] = $this->logFile;
        }
    }

    protected function tearDown(): void {
        unset($GLOBALS['sitepulse_test_log_path']);

        if (is_string($this->logFile) && $this->logFile !== '' && file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        $this->logFile = null;

        parent::tearDown();
    }

    public function test_recent_logs_endpoint_returns_metadata_and_counts(): void {
        rest_get_server();
        do_action('rest_api_init');

        $request  = new WP_REST_Request('GET', '/sitepulse/v1/logs/recent');
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();

        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('lines', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('sections', $data);

        $this->assertSame('critical', $data['status']);
        $this->assertSame(5, $data['meta']['total_lines']);
        $this->assertSame(5, $data['meta']['line_count']);
        $this->assertFalse($data['meta']['truncated']);

        $this->assertSame(1, $data['categories']['counts']['fatal_errors']);
        $this->assertSame(1, $data['categories']['counts']['errors']);
        $this->assertSame(1, $data['categories']['counts']['warnings']);
        $this->assertSame(2, $data['categories']['counts']['notices']);
        $this->assertSame(0, strpos($data['file']['name'], 'sitepulse-log'));
    }

    public function test_recent_logs_endpoint_filters_requested_levels(): void {
        rest_get_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request('GET', '/sitepulse/v1/logs/recent');
        $request->set_param('levels', 'warnings,notices');

        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();

        $this->assertSame(['warnings', 'notices'], array_keys($data['categories']['counts']));
        $this->assertSame(2, $data['meta']['line_count']);
        $this->assertSame(5, $data['meta']['total_lines']);
        $this->assertSame('warning', $data['status']);
        $this->assertSame(1, $data['categories']['counts']['warnings']);
        $this->assertSame(2, $data['categories']['counts']['notices']);
    }

    public function test_recent_logs_endpoint_honors_line_limit(): void {
        rest_get_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request('GET', '/sitepulse/v1/logs/recent');
        $request->set_param('lines', 2);

        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();

        $this->assertSame(2, $data['meta']['line_count']);
        $this->assertSame(2, count($data['lines']));
        $this->assertSame(2, $data['categories']['counts']['notices']);
        $this->assertSame('notice', $data['status']);
        $this->assertSame(2, $data['request']['max_lines']);
    }
}
