<?php
/**
 * Tests for the AI Insights AJAX endpoint.
 */

class Sitepulse_AI_Insights_Ajax_Test extends WP_Ajax_UnitTestCase {
    /**
     * Administrator user ID used for authenticated requests.
     *
     * @var int
     */
    private $admin_id;

    /**
     * Tracks the number of mocked Gemini HTTP calls.
     *
     * @var int
     */
    private $http_request_count = 0;

    /**
     * Raw text parts returned by the mocked Gemini response.
     *
     * @var string[]
     */
    private $mock_response_parts = [];

    /**
     * Arguments passed to the most recent mocked HTTP request.
     *
     * @var array|null
     */
    private $last_http_args = null;

    /**
     * Number of times the mocked spawn_cron callable has been requested.
     *
     * @var int
     */
    private $spawn_callable_invocations = 0;

    /**
     * Timestamp provided to the mocked spawn_cron callable.
     *
     * @var int|null
     */
    private $last_spawn_timestamp = null;

    /**
     * Number of asynchronous AI job requests triggered during a test.
     *
     * @var int
     */
    private $async_request_count = 0;

    /**
     * Arguments captured for the most recent asynchronous job request.
     *
     * @var array|null
     */
    private $last_async_request = null;

    public static function wpSetUpBeforeClass($factory) {
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/functions.php';
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/ai_insights.php';
    }

    protected function set_up(): void {
        parent::set_up();

        $this->admin_id = self::factory()->user->create([
            'role' => 'administrator',
        ]);

        wp_set_current_user($this->admin_id);

        $_POST = [];

        $this->http_request_count = 0;
        $this->mock_response_parts = [];
        $this->last_http_args      = null;
        $this->spawn_callable_invocations = 0;
        $this->last_spawn_timestamp = null;
        $this->async_request_count = 0;
        $this->last_async_request  = null;

        delete_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);
        delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, '');
        delete_option(SITEPULSE_PLUGIN_IMPACT_OPTION);
        delete_option(SITEPULSE_OPTION_UPTIME_LOG);
        delete_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS);
        delete_option(SITEPULSE_OPTION_AI_JOB_SECRET);
        $this->reset_cached_insight_static();
    }

    protected function tear_down(): void {
        $this->reset_cached_insight_static();
        delete_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);
        delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        delete_option(SITEPULSE_OPTION_GEMINI_API_KEY);
        delete_option(SITEPULSE_PLUGIN_IMPACT_OPTION);
        delete_option(SITEPULSE_OPTION_UPTIME_LOG);
        delete_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS);
        delete_option(SITEPULSE_OPTION_AI_JOB_SECRET);

        parent::tear_down();
    }

    /**
     * Ensures successful calls sanitize Gemini text, include timestamps, and store the transient cache.
     */
    public function test_successful_request_sanitizes_and_caches_payload() {
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, 'test-api-key');

        $this->mock_response_parts = [
            'Première recommandation.',
            "<script>alert('boom')</script> Dernière ligne.",
        ];

        add_filter('pre_http_request', [$this, 'mock_gemini_success'], 10, 3);

        $_POST['nonce'] = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);
        $_POST['force_refresh'] = 'true';

        try {
            $this->_handleAjax('sitepulse_generate_ai_insight');
        } catch (WPAjaxDieStopException $exception) {
            // Expected - WordPress halts execution after sending the JSON response.
        }

        remove_filter('pre_http_request', [$this, 'mock_gemini_success'], 10);

        $this->assertSame(1, $this->http_request_count, 'Exactly one Gemini request should be dispatched.');
        $this->assertIsArray($this->last_http_args, 'HTTP request arguments should be captured.');
        $this->assertArrayHasKey('limit_response_size', $this->last_http_args, 'A response size limit must be enforced.');
        $this->assertSame(MB_IN_BYTES, $this->last_http_args['limit_response_size']);

        $response = json_decode($this->_last_response, true);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success'], 'Successful requests must return success true.');
        $this->assertArrayHasKey('data', $response);

        $data = $response['data'];

        $expected_text = sanitize_textarea_field(trim(implode(' ', $this->mock_response_parts)));

        $this->assertSame($expected_text, $data['text'], 'Response text should be sanitized.');
        $this->assertStringNotContainsString('<script>', $data['text'], 'Script tags must be stripped.');
        $this->assertFalse($data['cached'], 'Fresh responses should mark cached as false.');
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertIsInt($data['timestamp']);
        $this->assertGreaterThan(0, $data['timestamp']);

        $cached_payload = get_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);

        $this->assertIsArray($cached_payload, 'Insight transient should be stored.');
        $this->assertSame($expected_text, $cached_payload['text']);
        $this->assertSame($data['timestamp'], $cached_payload['timestamp']);
    }

    /**
     * Ensures the admin page exposes the accessibility attributes expected by the JavaScript behaviour.
     */
    public function test_admin_page_contains_accessibility_attributes() {
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, 'test-api-key');

        ob_start();
        sitepulse_ai_insights_page();
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString('sitepulse-ai-insight-actions', $output);

        $internal_errors = libxml_use_internal_errors(true);

        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?>' . $output);

        libxml_use_internal_errors((bool) $internal_errors);

        $xpath = new \DOMXPath($document);

        $spinner = $xpath->query('//*[@id="sitepulse-ai-spinner"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $spinner, 'Spinner element should be present.');
        $this->assertSame('true', $spinner->getAttribute('aria-hidden'));

        $status = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " sitepulse-ai-insight-status ")]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $status, 'Status element should be present.');
        $this->assertSame('status', $status->getAttribute('role'));
        $this->assertSame('polite', $status->getAttribute('aria-live'));
        $this->assertSame('true', $status->getAttribute('aria-hidden'));

        $error = $xpath->query('//*[@id="sitepulse-ai-insight-error"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $error, 'Error container should be present.');
        $this->assertSame('alert', $error->getAttribute('role'));
        $this->assertSame('-1', $error->getAttribute('tabindex'));

        $actions = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " sitepulse-ai-insight-actions ")]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $actions, 'Actions container should be present.');
        $this->assertSame('false', $actions->getAttribute('aria-busy'));
    }

    /**
     * Ensures the admin page warns administrators when WP-Cron is disabled.
     */
    public function test_admin_page_displays_wp_cron_warning() {
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, 'test-api-key');

        add_filter('sitepulse_ai_is_wp_cron_disabled', '__return_true');

        ob_start();
        sitepulse_ai_insights_page();
        $output = ob_get_clean();

        remove_filter('sitepulse_ai_is_wp_cron_disabled', '__return_true');

        $this->assertIsString($output);
        $this->assertStringContainsString('notice-warning', $output);
        $this->assertStringContainsString('WP-Cron est désactivé', $output);
    }

    /**
     * Ensures that requests missing the nonce or API key return the documented JSON error responses.
     */
    public function test_missing_nonce_and_api_key_errors() {
        try {
            $this->_handleAjax('sitepulse_generate_ai_insight');
        } catch (WPAjaxDieStopException $exception) {
            // Expected.
        }

        $response = json_decode($this->_last_response, true);

        $this->assertFalse($response['success']);
        $this->assertSame(
            'Échec de la vérification de sécurité. Veuillez recharger la page et réessayer.',
            $response['data']['message']
        );

        $_POST = [];
        $_POST['nonce'] = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);

        try {
            $this->_handleAjax('sitepulse_generate_ai_insight');
        } catch (WPAjaxDieStopException $exception) {
            // Expected.
        }

        $response = json_decode($this->_last_response, true);

        $this->assertFalse($response['success']);
        $this->assertSame(
            'Veuillez entrer votre clé API Google Gemini dans les réglages de SitePulse.',
            $response['data']['message']
        );
    }

    /**
     * Ensures code-level overrides provide the Gemini API key ahead of the stored option.
     */
    public function test_environment_prefers_filter_override_over_option() {
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, '');

        $callback = static function () {
            return 'override-api-key';
        };

        add_filter('sitepulse_gemini_api_key', $callback);

        $environment = sitepulse_ai_prepare_environment();

        remove_filter('sitepulse_gemini_api_key', $callback);

        $this->assertIsArray($environment);
        $this->assertSame('override-api-key', $environment['api_key']);
    }

    /**
     * Ensures cached payloads short-circuit remote calls when force_refresh is false.
     */
    public function test_cached_payload_short_circuits_without_force_refresh() {
        $cached_text = ' <strong>Réponse mise en cache</strong> avec balises.';
        $cached_timestamp = 1_708_000_000;

        set_transient(
            SITEPULSE_TRANSIENT_AI_INSIGHT,
            [
                'text'      => $cached_text,
                'timestamp' => $cached_timestamp,
            ],
            HOUR_IN_SECONDS
        );

        $this->reset_cached_insight_static();

        add_filter('pre_http_request', [$this, 'count_unexpected_http_request'], 10, 3);

        $_POST['nonce'] = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);

        try {
            $this->_handleAjax('sitepulse_generate_ai_insight');
        } catch (WPAjaxDieStopException $exception) {
            // Expected.
        }

        remove_filter('pre_http_request', [$this, 'count_unexpected_http_request'], 10);

        $this->assertSame(0, $this->http_request_count, 'Cached responses must avoid HTTP requests.');

        $response = json_decode($this->_last_response, true);

        $this->assertTrue($response['success']);

        $data = $response['data'];

        $expected_text = sanitize_textarea_field($cached_text);

        $this->assertTrue($data['cached'], 'Response should indicate the data came from cache.');
        $this->assertSame($expected_text, $data['text']);
        $this->assertSame($cached_timestamp, $data['timestamp']);
    }

    /**
     * Ensures the Gemini prompt includes the collected metric summary when available.
     */
    public function test_prompt_includes_metric_summary_when_available() {
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, 'test-api-key');

        set_transient(
            SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS,
            [
                'server_processing_ms' => 321.987,
                'timestamp'            => current_time('timestamp'),
            ],
            MINUTE_IN_SECONDS
        );

        update_option(
            SITEPULSE_OPTION_UPTIME_LOG,
            [
                ['status' => true],
                ['status' => false],
                ['status' => true],
            ],
            false
        );

        update_option(
            SITEPULSE_PLUGIN_IMPACT_OPTION,
            [
                'samples' => [
                    'plugin-a/plugin.php' => [
                        'name'   => '<em>Plugin A</em>',
                        'avg_ms' => 45.3,
                    ],
                    'plugin-b/plugin.php' => [
                        'name'   => 'Plugin B',
                        'avg_ms' => 125.72,
                    ],
                ],
            ],
            false
        );

        $expected_summary = sitepulse_ai_get_metrics_summary();

        $this->assertNotSame('', $expected_summary, 'Precondition: The summary should not be empty.');

        add_filter('pre_http_request', [$this, 'mock_gemini_success'], 10, 3);

        $_POST['nonce']         = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);
        $_POST['force_refresh'] = 'true';

        try {
            $this->_handleAjax('sitepulse_generate_ai_insight');
        } catch (WPAjaxDieStopException $exception) {
            // Expected - WordPress halts execution after sending the JSON response.
        }

        remove_filter('pre_http_request', [$this, 'mock_gemini_success'], 10);

        $this->assertIsArray($this->last_http_args, 'HTTP request arguments should be captured.');
        $this->assertArrayHasKey('body', $this->last_http_args, 'The prompt should be present in the HTTP body.');

        $request_body = json_decode($this->last_http_args['body'], true);

        $this->assertIsArray($request_body, 'The HTTP body must be valid JSON.');
        $this->assertArrayHasKey('contents', $request_body);
        $this->assertNotEmpty($request_body['contents']);

        $this->assertArrayHasKey('parts', $request_body['contents'][0]);
        $this->assertNotEmpty($request_body['contents'][0]['parts']);
        $this->assertArrayHasKey('text', $request_body['contents'][0]['parts'][0]);

        $prompt_text = $request_body['contents'][0]['parts'][0]['text'];

        $this->assertStringContainsString($expected_summary, $prompt_text, 'The prompt should embed the metric summary.');

        $this->assertStringNotContainsString('<em>', $prompt_text, 'The summary must be sanitized.');
    }

    /**
     * Ensures that forcing a refresh keeps the transient when the remote request errors.
     */
    public function test_force_refresh_error_keeps_existing_transient() {
        $existing_payload = [
            'text'      => 'Ancienne recommandation.',
            'timestamp' => 1_708_123_456,
        ];

        set_transient(
            SITEPULSE_TRANSIENT_AI_INSIGHT,
            $existing_payload,
            HOUR_IN_SECONDS
        );

        $this->reset_cached_insight_static();

        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, 'test-api-key');

        add_filter('pre_http_request', [$this, 'mock_gemini_error'], 10, 3);

        $_POST['nonce'] = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);
        $_POST['force_refresh'] = 'true';

        try {
            $this->_handleAjax('sitepulse_generate_ai_insight');
        } catch (WPAjaxDieStopException $exception) {
            // Expected.
        }

        remove_filter('pre_http_request', [$this, 'mock_gemini_error'], 10);

        $this->assertSame(1, $this->http_request_count, 'Force refresh should trigger an HTTP request.');

        $response = json_decode($this->_last_response, true);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success'], 'Errors should return success false.');

        $cached_payload = get_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);

        $this->assertSame($existing_payload, $cached_payload, 'Existing transient should remain intact.');
    }

    /**
     * Ensures the administrator receives a clear error when Gemini exceeds the response size limit.
     */
    public function test_response_size_limit_error_returns_clear_message() {
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, 'test-api-key');

        add_filter('sitepulse_ai_response_size_limit', [$this, 'filter_custom_response_size_limit']);
        add_filter('pre_http_request', [$this, 'mock_gemini_response_size_error'], 10, 3);

        $_POST['nonce'] = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);
        $_POST['force_refresh'] = 'true';

        try {
            $this->_handleAjax('sitepulse_generate_ai_insight');
        } catch (WPAjaxDieStopException $exception) {
            // Expected.
        }

        remove_filter('pre_http_request', [$this, 'mock_gemini_response_size_error'], 10);
        remove_filter('sitepulse_ai_response_size_limit', [$this, 'filter_custom_response_size_limit']);

        $this->assertSame(1, $this->http_request_count, 'The HTTP request should have been attempted once.');
        $this->assertIsArray($this->last_http_args, 'HTTP request arguments should be captured.');
        $this->assertArrayHasKey('limit_response_size', $this->last_http_args);
        $this->assertSame(2 * MB_IN_BYTES, $this->last_http_args['limit_response_size']);

        $response = json_decode($this->_last_response, true);

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);

        $expected_message = sprintf(
            'La réponse de Gemini dépasse la taille maximale autorisée (%s). Veuillez réessayer ou augmenter la limite via le filtre sitepulse_ai_response_size_limit.',
            sanitize_text_field(size_format(2 * MB_IN_BYTES, 2))
        );

        $this->assertSame($expected_message, $response['data']['message']);
    }

    /**
     * Ensures that when scheduling fails the job falls back to a synchronous execution.
     */
    public function test_schedule_failure_triggers_synchronous_fallback() {
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, 'test-api-key');

        $this->mock_response_parts = ['Analyse générée en mode synchrone.'];

        add_filter('pre_http_request', [$this, 'mock_gemini_success'], 10, 3);
        add_filter('sitepulse_ai_is_wp_cron_disabled', '__return_true');
        add_filter('pre_schedule_event', [$this, 'force_schedule_event_failure'], 10, 2);

        $job_id = sitepulse_ai_schedule_generation_job(true);

        remove_filter('pre_schedule_event', [$this, 'force_schedule_event_failure'], 10);
        remove_filter('sitepulse_ai_is_wp_cron_disabled', '__return_true');
        remove_filter('pre_http_request', [$this, 'mock_gemini_success'], 10);

        $this->assertIsString($job_id, 'The fallback should still provide a job identifier.');

        $job_data = sitepulse_ai_get_job_data($job_id);

        $this->assertSame([], $job_data, 'Job metadata should be cleared once the synchronous fallback responds.');

        $history_entries = sitepulse_ai_get_history_entries();

        $this->assertNotEmpty($history_entries, 'Synchronous fallback should record a history entry.');
        $this->assertSame('Analyse générée en mode synchrone.', $history_entries[0]['text']);

        $this->assertSame(1, $this->http_request_count, 'Synchronous fallback should execute the HTTP request once.');
    }

    /**
     * Ensures that when WP-Cron is spawned immediately we log failures for visibility in Site Health.
     */
    public function test_schedule_attempts_immediate_cron_spawn_and_logs_failures() {
        add_filter('sitepulse_ai_spawn_cron_callable', [$this, 'filter_mock_spawn_callable'], 10, 2);

        $job_id = sitepulse_ai_schedule_generation_job(false);

        remove_filter('sitepulse_ai_spawn_cron_callable', [$this, 'filter_mock_spawn_callable'], 10);

        $this->assertIsString($job_id, 'Scheduling should return a job identifier even if spawn fails.');
        $this->assertSame(1, $this->spawn_callable_invocations, 'An immediate cron spawn should be attempted once.');
        $this->assertIsInt($this->last_spawn_timestamp);
        $this->assertGreaterThan(0, $this->last_spawn_timestamp);

        $logged_errors = get_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS, []);

        $this->assertIsArray($logged_errors, 'Logged errors should be stored as an array.');
        $this->assertNotEmpty($logged_errors, 'A failed spawn attempt should be logged for Site Health.');

        $latest_error = array_pop($logged_errors);

        $this->assertIsArray($latest_error);
        $this->assertArrayHasKey('message', $latest_error);
        $this->assertStringContainsString('WP-Cron', $latest_error['message']);
        $this->assertStringContainsString('No incoming traffic', $latest_error['message']);
    }

    /**
     * Ensures that when spawn_cron() fails we fallback to an authenticated AJAX request.
     */
    public function test_schedule_triggers_ajax_request_when_spawn_cron_fails() {
        $secret = sitepulse_ai_get_job_secret();

        add_filter('sitepulse_ai_spawn_cron_callable', [$this, 'filter_mock_spawn_callable'], 10, 2);
        add_filter('pre_http_request', [$this, 'mock_async_job_request'], 10, 3);

        $job_id = sitepulse_ai_schedule_generation_job(false);

        remove_filter('pre_http_request', [$this, 'mock_async_job_request'], 10);
        remove_filter('sitepulse_ai_spawn_cron_callable', [$this, 'filter_mock_spawn_callable'], 10);

        $this->assertIsString($job_id, 'Scheduling should still provide a job identifier.');
        $this->assertSame(1, $this->async_request_count, 'A single asynchronous request should be dispatched.');
        $this->assertIsArray($this->last_async_request, 'Asynchronous request arguments should be captured.');
        $this->assertArrayHasKey('body', $this->last_async_request);
        $this->assertSame('sitepulse_run_ai_insight_job', $this->last_async_request['body']['action']);
        $this->assertArrayHasKey('job_id', $this->last_async_request['body']);
        $this->assertSame($job_id, $this->last_async_request['body']['job_id']);
        $this->assertArrayHasKey('secret', $this->last_async_request['body']);
        $this->assertSame($secret, $this->last_async_request['body']['secret']);

        $logged_errors = get_option(SITEPULSE_OPTION_AI_INSIGHT_ERRORS, []);

        $this->assertIsArray($logged_errors);
        $this->assertNotEmpty($logged_errors, 'Spawn failure should be visible in Site Health.');
        $this->assertStringContainsString('WP-Cron', end($logged_errors)['message']);
    }

    /**
     * Ensures that HTTP errors trigger the AI insight logger with sanitized output.
     */
    public function test_http_error_triggers_logger() {
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, 'test-api-key');

        add_filter('pre_http_request', [$this, 'mock_gemini_http_error'], 10, 3);

        $_POST['nonce'] = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);
        $_POST['force_refresh'] = 'true';

        $logged_entries = [];

        $GLOBALS['sitepulse_log_callable'] = function ($message, $level) use (&$logged_entries) {
            $logged_entries[] = [
                'message' => $message,
                'level'   => $level,
            ];
        };

        try {
            $this->_handleAjax('sitepulse_generate_ai_insight');
        } catch (WPAjaxDieStopException $exception) {
            // Expected.
        }

        remove_filter('pre_http_request', [$this, 'mock_gemini_http_error'], 10);

        unset($GLOBALS['sitepulse_log_callable']);

        $this->assertNotEmpty($logged_entries, 'An HTTP error should be logged.');
        $this->assertSame('ERROR', $logged_entries[0]['level']);
        $this->assertStringContainsString('AI Insights', $logged_entries[0]['message']);
        $this->assertStringContainsString('Server exploded', $logged_entries[0]['message']);
        $this->assertStringNotContainsString('<b>', $logged_entries[0]['message']);
        $this->assertStringContainsString('500', $logged_entries[0]['message']);

        $response = json_decode($this->_last_response, true);

        $this->assertFalse($response['success']);
    }

    /**
     * Returns a callable used to mock spawn_cron() invocations during tests.
     *
     * @param callable|string $callable  Original callable.
     * @param int             $timestamp Cron timestamp passed to the callable.
     *
     * @return callable
     */
    public function filter_mock_spawn_callable($callable, $timestamp) {
        $this->spawn_callable_invocations++;
        $this->last_spawn_timestamp = $timestamp;

        return [$this, 'mock_spawn_cron_failure'];
    }

    /**
     * Simulates a failing spawn_cron() call for the immediate execution path.
     *
     * @param int $timestamp Cron timestamp.
     *
     * @return WP_Error
     */
    public function mock_spawn_cron_failure($timestamp) {
        return new WP_Error('sitepulse_no_traffic', 'No incoming traffic to trigger WP-Cron.');
    }

    /**
     * Captures asynchronous AI job requests triggered after a spawn failure.
     *
     * @param false|array $preempt Whether to short-circuit the HTTP request.
     * @param array        $args    HTTP request arguments.
     * @param string       $url     Destination URL.
     *
     * @return array
     */
    public function mock_async_job_request($preempt, $args, $url) {
        if (false === strpos($url, 'admin-ajax.php')) {
            return $preempt;
        }

        $this->async_request_count++;
        $this->last_async_request = $args;

        return [
            'headers'  => [],
            'body'     => wp_json_encode(['success' => true]),
            'response' => [
                'code'    => 200,
                'message' => 'OK',
            ],
        ];
    }

    /**
     * Provides a deterministic Gemini response payload.
     *
     * @param false|array $preempt Whether to short-circuit the request.
     * @param array       $args    The request arguments.
     * @param string      $url     The request URL.
     *
     * @return array
     */
    public function mock_gemini_success($preempt, $args, $url) {
        $this->http_request_count++;
        $this->last_http_args = $args;

        $parts = [];

        foreach ($this->mock_response_parts as $part) {
            $parts[] = [
                'text' => $part,
            ];
        }

        if (empty($parts)) {
            $parts[] = [
                'text' => 'Réponse vide',
            ];
        }

        $body = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => $parts,
                    ],
                ],
            ],
        ];

        return [
            'response' => [
                'code'    => 200,
                'message' => 'OK',
            ],
            'body' => wp_json_encode($body),
        ];
    }

    /**
     * Fails the test when an unexpected HTTP request is attempted while cached data exists.
     *
     * @param false|array $preempt Whether to short-circuit the request.
     * @param array       $args    The request arguments.
     * @param string      $url     The request URL.
     *
     * @return WP_Error
     */
    public function count_unexpected_http_request($preempt, $args, $url) {
        $this->http_request_count++;

        return new WP_Error('unexpected_http_request', 'HTTP requests should not occur when cached data is available.');
    }

    /**
     * Returns a WP_Error to simulate a failed Gemini request.
     *
     * @param false|array $preempt Whether to short-circuit the request.
     * @param array       $args    The request arguments.
     * @param string      $url     The request URL.
     *
     * @return WP_Error
     */
    public function mock_gemini_error($preempt, $args, $url) {
        $this->http_request_count++;
        $this->last_http_args = $args;

        return new WP_Error('gemini_error', 'Boom');
    }

    /**
     * Returns a WP_Error mimicking the response size limit being exceeded.
     *
     * @param false|array $preempt Whether to short-circuit the request.
     * @param array       $args    The request arguments.
     * @param string      $url     The request URL.
     *
     * @return WP_Error
     */
    public function mock_gemini_response_size_error($preempt, $args, $url) {
        $this->http_request_count++;
        $this->last_http_args = $args;

        return new WP_Error('http_request_failed', 'Response size limit reached');
    }

    /**
     * Returns an HTTP error-style response to trigger logging.
     *
     * @param false|array $preempt Whether to short-circuit the request.
     * @param array       $args    The request arguments.
     * @param string      $url     The request URL.
     *
     * @return array
     */
    public function mock_gemini_http_error($preempt, $args, $url) {
        $this->http_request_count++;
        $this->last_http_args = $args;

        return [
            'response' => [
                'code'    => 500,
                'message' => 'Internal Server Error',
            ],
            'body' => wp_json_encode([
                'error' => [
                    'message' => '<b>Server exploded</b>',
                ],
            ]),
        ];
    }

    /**
     * Forces a predictable custom response size limit.
     *
     * @return int
     */
    public function filter_custom_response_size_limit() {
        return 2 * MB_IN_BYTES;
    }

    /**
     * Resets the static cache used by sitepulse_ai_get_cached_insight().
     */
    private function reset_cached_insight_static() {
        $reset = \Closure::bind(
            function () {
                static $cached_insight = null;
                $cached_insight = null;
            },
            null,
            'sitepulse_ai_get_cached_insight'
        );

        if ($reset instanceof \Closure) {
            $reset();
        }
    }

    /**
     * Forces wp_schedule_single_event() to return false during tests.
     *
     * @param bool|WP_Error $pre   Pre-schedule value.
     * @param array         $event Event arguments.
     *
     * @return bool
     */
    public function force_schedule_event_failure($pre, $event) {
        return false;
    }
}

