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

    public static function wpSetUpBeforeClass($factory) {
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

        delete_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);
        update_option(SITEPULSE_OPTION_GEMINI_API_KEY, '');
        $this->reset_cached_insight_static();
    }

    protected function tear_down(): void {
        $this->reset_cached_insight_static();
        delete_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);
        delete_option(SITEPULSE_OPTION_GEMINI_API_KEY);

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
}

