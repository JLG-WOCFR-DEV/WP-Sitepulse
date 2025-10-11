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
        delete_option(SITEPULSE_OPTION_AI_RETRY_AFTER);
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
        delete_option(SITEPULSE_OPTION_AI_RETRY_AFTER);

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
     * @covers ::sitepulse_ai_prepare_insight_variants
     */
    public function test_prepare_insight_variants_generates_html_from_plain_text() {
        $plain_text = "First line\n\nSecond line";

        $variants = sitepulse_ai_prepare_insight_variants($plain_text, '');

        $this->assertSame($plain_text, $variants['text']);
        $this->assertNotSame('', $variants['html']);
        $this->assertStringContainsString('<p>First line</p>', $variants['html']);
        $this->assertStringContainsString('<p>Second line</p>', $variants['html']);
    }

    /**
     * @covers ::sitepulse_ai_prepare_insight_variants
     */
    public function test_prepare_insight_variants_filters_disallowed_markup() {
        $raw_text = "Keep <script>alert('bad')</script> text";
        $raw_html = "<p><em>Allowed</em> markup</p><script>alert('bad')</script>";

        $variants = sitepulse_ai_prepare_insight_variants($raw_text, $raw_html);

        $this->assertSame(sitepulse_ai_sanitize_insight_text($raw_text), $variants['text']);
        $this->assertStringNotContainsString('<script>', $variants['html']);
        $this->assertStringContainsString('<p><em>Allowed</em> markup</p>', $variants['html']);
    }

    /**
     * @covers ::sitepulse_ai_prepare_insight_variants
     */
    public function test_prepare_insight_variants_recovers_html_when_markup_removed() {
        $raw_text = 'Keep <strong>content</strong>';
        $raw_html = '<script>alert("bad")</script>';

        $variants = sitepulse_ai_prepare_insight_variants($raw_text, $raw_html);

        $this->assertSame('Keep content', $variants['text']);
        $this->assertStringContainsString('<p>Keep content</p>', $variants['html']);
    }

    /**
     * @covers ::sitepulse_ai_get_job_secret
     */
    public function test_job_secret_is_persistent_and_filterable() {
        delete_option(SITEPULSE_OPTION_AI_JOB_SECRET);

        $stored_secret = sitepulse_ai_get_job_secret();

        $this->assertIsString($stored_secret);
        $this->assertSame(64, strlen($stored_secret));

        $filter = static function () {
            return 'filtered-secret-value';
        };

        add_filter('sitepulse_ai_job_secret', $filter);

        $filtered_secret = sitepulse_ai_get_job_secret();

        remove_filter('sitepulse_ai_job_secret', $filter);

        $this->assertSame('filtered-secret-value', $filtered_secret);
        $this->assertSame($stored_secret, sitepulse_ai_get_job_secret());
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

        $this->assertNotEmpty($job_data, 'Job metadata should persist so the UI can retrieve the result.');
        $this->assertArrayHasKey('status', $job_data);
        $this->assertSame('completed', $job_data['status'], 'Synchronous fallback should mark the job as completed.');
        $this->assertArrayHasKey('result', $job_data, 'Job metadata should expose the generated result.');
        $this->assertSame('Analyse générée en mode synchrone.', $job_data['result']['text']);

        $history_entries = sitepulse_ai_get_history_entries();

        $this->assertNotEmpty($history_entries, 'Synchronous fallback should record a history entry.');
        $this->assertSame('Analyse générée en mode synchrone.', $history_entries[0]['text']);

        $this->assertSame(1, $this->http_request_count, 'Synchronous fallback should execute the HTTP request once.');

        sitepulse_ai_delete_job_data($job_id);
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
     * Ensures that a failing AJAX trigger surfaces a WP_Error instead of leaving the UI polling indefinitely.
     */
    public function test_schedule_returns_error_when_ajax_trigger_fails() {
        add_filter('sitepulse_ai_spawn_cron_callable', [$this, 'filter_mock_spawn_callable'], 10, 2);
        add_filter('pre_http_request', [$this, 'mock_async_job_request_failure'], 10, 3);

        $result = sitepulse_ai_schedule_generation_job(false);

        remove_filter('pre_http_request', [$this, 'mock_async_job_request_failure'], 10);
        remove_filter('sitepulse_ai_spawn_cron_callable', [$this, 'filter_mock_spawn_callable'], 10);

        $this->assertInstanceOf('WP_Error', $result, 'Scheduling should surface a WP_Error when the AJAX trigger fails.');
        $this->assertSame('sitepulse_ai_job_async_trigger_failed', $result->get_error_code());
        $this->assertStringContainsString(
            'Impossible de déclencher immédiatement l’analyse IA',
            $result->get_error_message(),
            'Error message should inform the user that the immediate trigger failed.'
        );

        $this->assertSame(1, $this->async_request_count, 'A single asynchronous request should be attempted.');
        $this->assertNotEmpty($this->last_async_request, 'Request arguments should be captured for debugging.');

        if (isset($this->last_async_request['body']['job_id'])) {
            $job_id = $this->last_async_request['body']['job_id'];

            $this->assertSame([], sitepulse_ai_get_job_data($job_id), 'Job metadata should be cleared after a failed async trigger.');

            if (function_exists('wp_next_scheduled')) {
                $this->assertFalse(
                    wp_next_scheduled('sitepulse_run_ai_insight_job', [$job_id]),
                    'The failed job should be unscheduled to avoid unexpected executions.'
                );
            }
        }
    }

    /**
     * Ensures the status endpoint exposes the completed payload and clears metadata once consumed.
     */
    public function test_status_returns_completed_payload_and_cleans_job_metadata() {
        $job_id      = 'job-completed';
        $created_at  = time() - 5;
        $started_at  = $created_at + 2;
        $finished_at = time();
        $result      = [
            'text'       => 'Analyse prête.',
            'html'       => '<p>Analyse prête.</p>',
            'timestamp'  => absint(current_time('timestamp', true)),
            'cached'     => false,
            'model'      => [
                'key'   => 'gemini-1.5-pro',
                'label' => 'Gemini 1.5 Pro',
            ],
            'rate_limit' => [
                'key'   => 'week',
                'label' => 'Une fois par semaine',
            ],
            'id'   => 'ai-history-entry',
            'note' => '',
        ];

        $saved = sitepulse_ai_save_job_data($job_id, [
            'status'        => 'completed',
            'result'        => $result,
            'created_at'    => $created_at,
            'started_at'    => $started_at,
            'finished'      => $finished_at,
            'force_refresh' => true,
            'fallback'      => ' <b>synchronous</b> ',
        ]);

        $this->assertTrue($saved, 'Job metadata should be stored for the status endpoint.');

        $_POST['nonce']  = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);
        $_POST['job_id'] = $job_id;

        try {
            $this->_handleAjax('sitepulse_get_ai_insight_status');
        } catch (WPAjaxDieStopException $exception) {
            // Expected.
        }

        $response = json_decode($this->_last_response, true);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success'], 'Completed jobs should resolve successfully.');
        $this->assertArrayHasKey('data', $response);

        $data = $response['data'];

        $this->assertSame('completed', $data['status']);
        $this->assertArrayHasKey('result', $data);
        $this->assertSame($result, $data['result'], 'The stored result should be returned as-is.');
        $this->assertArrayHasKey('created_at', $data);
        $this->assertSame($created_at, $data['created_at']);
        $this->assertArrayHasKey('started_at', $data);
        $this->assertSame($started_at, $data['started_at']);
        $this->assertArrayHasKey('finished_at', $data);
        $this->assertSame($finished_at, $data['finished_at']);
        $this->assertArrayHasKey('force_refresh', $data);
        $this->assertTrue($data['force_refresh']);
        $this->assertArrayHasKey('fallback', $data);
        $this->assertSame('synchronous', $data['fallback']);

        $this->assertSame([], sitepulse_ai_get_job_data($job_id), 'Job metadata should be removed after retrieval.');

        $_POST = [];
    }

    /**
     * Ensures the status endpoint propagates failure metadata and cleans up stored state.
     */
    public function test_status_returns_failure_payload_with_retry_information() {
        $job_id      = 'job-failed';
        $created_at  = time() - 10;
        $finished_at = time();
        $retry_at    = $finished_at + 120;

        $saved = sitepulse_ai_save_job_data($job_id, [
            'status'        => 'failed',
            'message'       => 'Analyse indisponible.',
            'code'          => 503,
            'retry_after'   => 120,
            'retry_at'      => $retry_at,
            'created_at'    => $created_at,
            'started_at'    => $created_at + 1,
            'finished'      => $finished_at,
            'force_refresh' => false,
        ]);

        $this->assertTrue($saved, 'Failed job metadata should be persisted.');

        $_POST['nonce']  = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);
        $_POST['job_id'] = $job_id;

        try {
            $this->_handleAjax('sitepulse_get_ai_insight_status');
        } catch (WPAjaxDieStopException $exception) {
            // Expected.
        }

        $response = json_decode($this->_last_response, true);

        $this->assertIsArray($response);
        $this->assertTrue($response['success'], 'Failed jobs still return a successful transport payload.');
        $this->assertArrayHasKey('data', $response);

        $data = $response['data'];

        $this->assertSame('failed', $data['status']);
        $this->assertArrayHasKey('message', $data);
        $this->assertSame('Analyse indisponible.', $data['message']);
        $this->assertArrayHasKey('code', $data);
        $this->assertSame(503, $data['code']);
        $this->assertArrayHasKey('retry_after', $data);
        $this->assertSame(120, $data['retry_after']);
        $this->assertArrayHasKey('retry_at', $data);
        $this->assertSame($retry_at, $data['retry_at']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertSame($created_at, $data['created_at']);
        $this->assertArrayHasKey('started_at', $data);
        $this->assertSame($created_at + 1, $data['started_at']);
        $this->assertArrayHasKey('finished_at', $data);
        $this->assertSame($finished_at, $data['finished_at']);
        $this->assertArrayHasKey('force_refresh', $data);
        $this->assertFalse($data['force_refresh']);

        $this->assertSame([], sitepulse_ai_get_job_data($job_id), 'Failed job metadata should be deleted after retrieval.');

        $_POST = [];
    }

    /**
     * Ensures running jobs expose timing metadata without clearing stored state.
     */
    public function test_status_returns_running_metadata_without_clearing_state() {
        $job_id     = 'job-running';
        $created_at = time() - 10;
        $started_at = time() - 3;

        $saved = sitepulse_ai_save_job_data($job_id, [
            'status'        => 'running',
            'created_at'    => $created_at,
            'started_at'    => $started_at,
            'force_refresh' => false,
        ]);

        $this->assertTrue($saved, 'Running job metadata should be persisted.');

        $_POST['nonce']  = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);
        $_POST['job_id'] = $job_id;

        try {
            $this->_handleAjax('sitepulse_get_ai_insight_status');
        } catch (WPAjaxDieStopException $exception) {
            // Expected.
        }

        $response = json_decode($this->_last_response, true);

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);

        $data = $response['data'];

        $this->assertSame('running', $data['status']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertSame($created_at, $data['created_at']);
        $this->assertArrayHasKey('started_at', $data);
        $this->assertSame($started_at, $data['started_at']);
        $this->assertArrayNotHasKey('finished_at', $data);

        $stored_job = sitepulse_ai_get_job_data($job_id);

        $this->assertIsArray($stored_job, 'Running job metadata should remain stored.');
        $this->assertArrayHasKey('status', $stored_job);
        $this->assertSame('running', $stored_job['status']);

        $_POST = [];
    }

    /**
     * Ensures the status endpoint reports an error when metadata is missing or expired.
     */
    public function test_status_returns_error_when_job_metadata_is_missing() {
        $_POST['nonce']  = wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT);
        $_POST['job_id'] = 'missing-job';

        try {
            $this->_handleAjax('sitepulse_get_ai_insight_status');
        } catch (WPAjaxDieStopException $exception) {
            // Expected.
        }

        $response = json_decode($this->_last_response, true);

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('message', $response['data']);
        $this->assertNotEmpty($response['data']['message']);

        if (property_exists($this, '_last_response_code')) {
            $this->assertSame(404, $this->_last_response_code);
        }

        $_POST = [];
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
     * Ensures retry-after hints propagate to job data and scheduling options.
     */
    public function test_rate_limit_response_updates_retry_after_option() {
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, 'test-api-key');

        add_filter('pre_http_request', [$this, 'mock_gemini_rate_limited'], 10, 3);

        $job_id = 'sitepulse-rate-limit-test';

        sitepulse_ai_save_job_data($job_id, [
            'status'     => 'queued',
            'created_at' => time(),
        ]);

        try {
            sitepulse_run_ai_insight_job($job_id);
        } finally {
            remove_filter('pre_http_request', [$this, 'mock_gemini_rate_limited'], 10);
        }

        $job_state = sitepulse_ai_get_job_data($job_id);

        $this->assertIsArray($job_state, 'Job state should be recorded.');
        $this->assertArrayHasKey('status', $job_state);
        $this->assertSame('failed', $job_state['status'], 'Rate limit responses should mark the job as failed.');

        $this->assertArrayHasKey('retry_after', $job_state);
        $this->assertSame(60, $job_state['retry_after'], 'Retry delay should match the Retry-After header.');

        $this->assertArrayHasKey('retry_at', $job_state);
        $now = absint(current_time('timestamp', true));
        $this->assertGreaterThan($now, $job_state['retry_at'], 'Retry timestamp must be in the future.');
        $this->assertLessThanOrEqual($now + 60, $job_state['retry_at'], 'Retry timestamp should align with the declared delay.');

        $option_value = (int) get_option(SITEPULSE_OPTION_AI_RETRY_AFTER, 0);
        $this->assertSame($job_state['retry_at'], $option_value, 'Retry option should mirror the stored job timestamp.');

        sitepulse_ai_delete_job_data($job_id);
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
     * Simulates an asynchronous request failure (loopback disabled or remote error).
     *
     * @param false|array $preempt Whether to short-circuit the HTTP request.
     * @param array        $args    HTTP request arguments.
     * @param string       $url     Destination URL.
     *
     * @return WP_Error|false
     */
    public function mock_async_job_request_failure($preempt, $args, $url) {
        if (false === strpos($url, 'admin-ajax.php')) {
            return $preempt;
        }

        $this->async_request_count++;
        $this->last_async_request = $args;

        return new WP_Error('http_request_failed', 'Loopback request blocked.');
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
     * Simulates a rate-limited Gemini response including Retry-After hints.
     *
     * @param false|array $preempt Whether to short-circuit the request.
     * @param array       $args    The request arguments.
     * @param string      $url     The request URL.
     *
     * @return array
     */
    public function mock_gemini_rate_limited($preempt, $args, $url) {
        $this->http_request_count++;
        $this->last_http_args = $args;

        return [
            'headers'  => [
                'retry-after' => '60',
            ],
            'response' => [
                'code'    => 429,
                'message' => 'Too Many Requests',
            ],
            'body'     => wp_json_encode([
                'error' => [
                    'message'   => 'Quota exceeded',
                    'rateLimit' => [
                        'retryDelay' => '60s',
                    ],
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

