<?php
/**
 * Tests for the plugin impact MU loader installation warnings.
 */

require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

if (!class_exists('Sitepulse_Unwritable_Filesystem')) {
    class Sitepulse_Unwritable_Filesystem extends WP_Filesystem_Direct {
        public function __construct() {
            parent::__construct([]);
            $this->method = 'sitepulse-test';
        }

        public function is_dir($path) {
            return false;
        }

        public function mkdir($path, $chmod = false, $chown = false, $chgrp = false) {
            return false;
        }

        public function is_writable($path) {
            return false;
        }

        public function exists($path) {
            return false;
        }
    }
}

class Sitepulse_Plugin_Impact_Loader_Test extends WP_UnitTestCase {
    protected $mu_loader_dir;
    protected $mu_loader_file;
    protected $renamed_plugin_dir;
    protected $renamed_tracker_file;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!function_exists('sitepulse_plugin_impact_install_mu_loader')) {
            require_once dirname(__DIR__, 2) . '/sitepulse_FR/sitepulse.php';
        }
    }

    protected function setUp(): void {
        parent::setUp();

        delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
        delete_option(SITEPULSE_OPTION_CRON_WARNINGS);
        delete_option(SITEPULSE_OPTION_PLUGIN_BASENAME);

        $GLOBALS['sitepulse_filesystem_initialized'] = false;
        $GLOBALS['sitepulse_filesystem_instance']    = null;
        remove_all_filters('sitepulse_pre_get_filesystem');

        $paths = sitepulse_plugin_impact_get_mu_loader_paths();
        $this->mu_loader_dir  = $paths['dir'];
        $this->mu_loader_file = $paths['file'];

        $plugins_root = function_exists('trailingslashit')
            ? trailingslashit(WP_PLUGIN_DIR)
            : rtrim(WP_PLUGIN_DIR, '/\\') . '/';

        $this->renamed_plugin_dir   = $plugins_root . 'sitepulse-renamed';
        $this->renamed_tracker_file = $this->renamed_plugin_dir . '/includes/plugin-impact-tracker.php';

        $this->removeTestDirectory($this->renamed_plugin_dir);

        if (file_exists($this->mu_loader_file)) {
            unlink($this->mu_loader_file);
        }

        if (file_exists($this->mu_loader_dir) && !is_dir($this->mu_loader_dir)) {
            unlink($this->mu_loader_dir);
        }

        if (!is_dir($this->mu_loader_dir)) {
            wp_mkdir_p($this->mu_loader_dir);
        }
    }

    protected function tearDown(): void {
        remove_all_filters('sitepulse_pre_get_filesystem');
        $GLOBALS['sitepulse_filesystem_initialized'] = false;
        $GLOBALS['sitepulse_filesystem_instance']    = null;

        if (file_exists($this->mu_loader_file)) {
            unlink($this->mu_loader_file);
        }

        if (!is_dir($this->mu_loader_dir)) {
            if (file_exists($this->mu_loader_dir)) {
                unlink($this->mu_loader_dir);
            }

            wp_mkdir_p($this->mu_loader_dir);
        } else {
            @chmod($this->mu_loader_dir, 0755);
        }

        $this->removeTestDirectory($this->renamed_plugin_dir);

        parent::tearDown();
    }

    protected function removeTestDirectory($path): void {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removeTestDirectory($path . DIRECTORY_SEPARATOR . $item);
        }

        @rmdir($path);
    }

    public function filter_sitepulse_plugin_basename($basename, $current = null) {
        return 'sitepulse-renamed/sitepulse.php';
    }

    public function test_registers_warning_when_mu_directory_is_unwritable(): void {
        add_filter(
            'sitepulse_pre_get_filesystem',
            static function () {
                return new Sitepulse_Unwritable_Filesystem();
            }
        );

        sitepulse_plugin_impact_install_mu_loader();

        $warnings = get_option(SITEPULSE_OPTION_CRON_WARNINGS, []);

        $this->assertIsArray($warnings, 'Cron warnings option should store an array.');
        $this->assertArrayHasKey('plugin_impact', $warnings, 'Plugin impact warning should be registered on failure.');
        $this->assertNotEmpty(
            $warnings['plugin_impact']['message'] ?? '',
            'Registered warning should include a user-facing message.'
        );
    }

    public function test_successful_installation_clears_warning(): void {
        update_option(
            SITEPULSE_OPTION_CRON_WARNINGS,
            [
                'plugin_impact' => ['message' => 'Existing warning'],
                'other'         => ['message' => 'Keep me'],
            ],
            false
        );

        sitepulse_plugin_impact_install_mu_loader();

        $warnings = get_option(SITEPULSE_OPTION_CRON_WARNINGS, []);

        $this->assertIsArray($warnings, 'Cron warnings option should remain an array after clearing entries.');
        $this->assertArrayNotHasKey('plugin_impact', $warnings, 'Successful installation should clear the plugin impact warning.');
        $this->assertArrayHasKey('other', $warnings, 'Other cron warnings should remain untouched.');
    }

    public function test_mu_loader_bootstraps_tracker_with_renamed_directory(): void {
        wp_mkdir_p(dirname($this->renamed_tracker_file));

        file_put_contents(
            $this->renamed_tracker_file,
            "<?php\n\$GLOBALS['sitepulse_tracker_stub_loaded'] = true;\n"
        );

        remove_action('plugin_loaded', 'sitepulse_plugin_impact_tracker_on_plugin_loaded', PHP_INT_MAX);
        remove_action('shutdown', 'sitepulse_plugin_impact_tracker_persist', PHP_INT_MAX);

        add_filter('sitepulse_plugin_basename', [$this, 'filter_sitepulse_plugin_basename'], 10, 2);

        sitepulse_plugin_impact_install_mu_loader();

        remove_filter('sitepulse_plugin_basename', [$this, 'filter_sitepulse_plugin_basename'], 10);

        $this->assertFileExists($this->mu_loader_file, 'MU loader should be created.');
        $this->assertSame(
            'sitepulse-renamed/sitepulse.php',
            get_option(SITEPULSE_OPTION_PLUGIN_BASENAME),
            'Renamed plugin basename should be stored for the MU loader.'
        );

        unset($GLOBALS['sitepulse_tracker_stub_loaded']);

        require $this->mu_loader_file;

        $this->assertTrue(
            !empty($GLOBALS['sitepulse_tracker_stub_loaded']),
            'MU loader should include the tracker file from the renamed directory.'
        );
        $this->assertSame(
            PHP_INT_MAX,
            has_action('plugin_loaded', 'sitepulse_plugin_impact_tracker_on_plugin_loaded'),
            'Tracker bootstrap should be re-registered after requiring the MU loader.'
        );
        $this->assertSame(
            PHP_INT_MAX,
            has_action('shutdown', 'sitepulse_plugin_impact_tracker_persist'),
            'Shutdown persistence hook should be re-registered after requiring the MU loader.'
        );
    }
}
