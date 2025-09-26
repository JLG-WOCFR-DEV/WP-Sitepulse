<?php
/**
 * Tests for SitePulse admin cleanup actions.
 */

require_once __DIR__ . '/includes/stubs.php';

if (!function_exists('sitepulse_get_filesystem')) {
    function sitepulse_get_filesystem() {
        return $GLOBALS['sitepulse_test_filesystem'] ?? null;
    }
}

if (!function_exists('sitepulse_get_cron_hooks')) {
    function sitepulse_get_cron_hooks() {
        return $GLOBALS['sitepulse_test_cron_hooks'] ?? [];
    }
}

if (!function_exists('sitepulse_activate_site')) {
    function sitepulse_activate_site() {
        $GLOBALS['sitepulse_activate_site_calls'] = ($GLOBALS['sitepulse_activate_site_calls'] ?? 0) + 1;
    }
}

class Sitepulse_Admin_Settings_Cleanup_Test extends WP_UnitTestCase {
    private static $debug_log_path;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!defined('SITEPULSE_OPTION_ACTIVE_MODULES')) {
            define('SITEPULSE_OPTION_ACTIVE_MODULES', 'sitepulse_active_modules');
        }
        if (!defined('SITEPULSE_OPTION_DEBUG_MODE')) {
            define('SITEPULSE_OPTION_DEBUG_MODE', 'sitepulse_debug_mode');
        }
        if (!defined('SITEPULSE_OPTION_LAST_LOAD_TIME')) {
            define('SITEPULSE_OPTION_LAST_LOAD_TIME', 'sitepulse_last_load_time');
        }
        if (!defined('SITEPULSE_OPTION_CPU_ALERT_THRESHOLD')) {
            define('SITEPULSE_OPTION_CPU_ALERT_THRESHOLD', 'sitepulse_cpu_alert_threshold');
        }
        if (!defined('SITEPULSE_OPTION_ALERT_INTERVAL')) {
            define('SITEPULSE_OPTION_ALERT_INTERVAL', 'sitepulse_alert_interval');
        }
        if (!defined('SITEPULSE_PLUGIN_IMPACT_OPTION')) {
            define('SITEPULSE_PLUGIN_IMPACT_OPTION', 'sitepulse_plugin_impact');
        }
        if (!defined('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS')) {
            define('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS', 'sitepulse_speed_scan_results');
        }
        if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
            define('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX', 'sitepulse_plugin_dir_size_');
        }
        if (!defined('SITEPULSE_NONCE_ACTION_CLEANUP')) {
            define('SITEPULSE_NONCE_ACTION_CLEANUP', 'sitepulse_cleanup');
        }
        if (!defined('SITEPULSE_NONCE_FIELD_CLEANUP')) {
            define('SITEPULSE_NONCE_FIELD_CLEANUP', 'sitepulse_cleanup_nonce');
        }

        self::$debug_log_path = sys_get_temp_dir() . '/sitepulse-test-debug.log';

        if (!defined('SITEPULSE_DEBUG_LOG')) {
            define('SITEPULSE_DEBUG_LOG', self::$debug_log_path);
        }

        require_once dirname(__DIR__, 2) . '/sitepulse_FR/includes/admin-settings.php';
    }

    protected function setUp(): void {
        parent::setUp();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $GLOBALS['sitepulse_test_filesystem'] = null;
        $GLOBALS['sitepulse_test_cron_hooks'] = [];
        $GLOBALS['sitepulse_activate_site_calls'] = 0;

        $_POST = [];

        if (file_exists(self::$debug_log_path)) {
            unlink(self::$debug_log_path);
        }
    }

    protected function tear_down(): void {
        $_POST = [];

        if (file_exists(self::$debug_log_path)) {
            unlink(self::$debug_log_path);
        }

        parent::tear_down();
    }

    public function test_clear_log_action_clears_debug_file_and_outputs_notice(): void {
        file_put_contents(self::$debug_log_path, 'test');
        $this->assertFileExists(self::$debug_log_path);

        $_POST = [
            'sitepulse_clear_log'                  => '1',
            SITEPULSE_NONCE_FIELD_CLEANUP          => wp_create_nonce(SITEPULSE_NONCE_ACTION_CLEANUP),
        ];

        ob_start();
        sitepulse_settings_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('Journal de débogage vidé.', $output);
        $this->assertSame('', file_get_contents(self::$debug_log_path));
    }

    public function test_clear_data_action_deletes_stored_options_and_transients(): void {
        update_option(SITEPULSE_OPTION_UPTIME_LOG, ['foo']);
        set_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS, 'cached');

        $_POST = [
            'sitepulse_clear_data'                 => '1',
            SITEPULSE_NONCE_FIELD_CLEANUP          => wp_create_nonce(SITEPULSE_NONCE_ACTION_CLEANUP),
        ];

        ob_start();
        sitepulse_settings_page();
        $output = ob_get_clean();

        $this->assertFalse(get_option(SITEPULSE_OPTION_UPTIME_LOG));
        $this->assertFalse(get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS));
        $this->assertStringContainsString('Données stockées effacées.', $output);
    }

    public function test_reset_all_action_resets_plugin_state_and_outputs_notice(): void {
        $options_to_seed = [
            SITEPULSE_OPTION_ACTIVE_MODULES         => ['foo'],
            SITEPULSE_OPTION_DEBUG_MODE             => '1',
            SITEPULSE_OPTION_GEMINI_API_KEY         => 'key',
            SITEPULSE_OPTION_UPTIME_LOG             => ['bar'],
            SITEPULSE_OPTION_LAST_LOAD_TIME         => 123,
            SITEPULSE_OPTION_CPU_ALERT_THRESHOLD    => 42,
            SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES => 15,
            SITEPULSE_OPTION_ALERT_INTERVAL         => 10,
            SITEPULSE_OPTION_ALERT_RECIPIENTS       => ['test@example.com'],
            SITEPULSE_PLUGIN_IMPACT_OPTION          => ['payload'],
        ];

        foreach ($options_to_seed as $option => $value) {
            update_option($option, $value);
        }

        set_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS, 'cached');
        set_transient(SITEPULSE_TRANSIENT_AI_INSIGHT, 'ai');
        set_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK, 'lock');
        set_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK, 'lock');

        $prefixed_transient = SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX . 'example';
        set_transient($prefixed_transient, 'size');

        $GLOBALS['sitepulse_test_cron_hooks'] = ['sitepulse_fake_cron'];

        $_POST = [
            'sitepulse_reset_all'                  => '1',
            SITEPULSE_NONCE_FIELD_CLEANUP          => wp_create_nonce(SITEPULSE_NONCE_ACTION_CLEANUP),
        ];

        ob_start();
        sitepulse_settings_page();
        $output = ob_get_clean();

        foreach (array_keys($options_to_seed) as $option) {
            $this->assertFalse(get_option($option));
        }

        $this->assertFalse(get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS));
        $this->assertFalse(get_transient(SITEPULSE_TRANSIENT_AI_INSIGHT));
        $this->assertFalse(get_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_CPU_LOCK));
        $this->assertFalse(get_transient(SITEPULSE_TRANSIENT_ERROR_ALERT_PHP_FATAL_LOCK));
        $this->assertFalse(get_transient($prefixed_transient));
        $this->assertSame(1, $GLOBALS['sitepulse_activate_site_calls']);
        $this->assertStringContainsString('SitePulse a été réinitialisé.', $output);
    }
}
