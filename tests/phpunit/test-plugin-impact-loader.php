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

        $GLOBALS['sitepulse_filesystem_initialized'] = false;
        $GLOBALS['sitepulse_filesystem_instance']    = null;
        remove_all_filters('sitepulse_pre_get_filesystem');

        $paths = sitepulse_plugin_impact_get_mu_loader_paths();
        $this->mu_loader_dir  = $paths['dir'];
        $this->mu_loader_file = $paths['file'];

        $this->renamed_plugin_dir = trailingslashit(WP_PLUGIN_DIR) . 'sitepulse-renamed';

        if (file_exists($this->mu_loader_file)) {
            unlink($this->mu_loader_file);
        }

        if (file_exists($this->mu_loader_dir) && !is_dir($this->mu_loader_dir)) {
            unlink($this->mu_loader_dir);
        }

        if (!is_dir($this->mu_loader_dir)) {
            wp_mkdir_p($this->mu_loader_dir);
        }

        $this->remove_directory($this->renamed_plugin_dir);
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

        $this->remove_directory($this->renamed_plugin_dir);
        sitepulse_update_plugin_basename_option();

        unset($GLOBALS['sitepulse_mu_loader_stub_includes']);

        parent::tearDown();
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

    public function test_loader_includes_tracker_for_renamed_plugin_directory(): void {
        $includes_dir = trailingslashit($this->renamed_plugin_dir) . 'includes';

        wp_mkdir_p($includes_dir);

        $stub_tracker = $includes_dir . '/plugin-impact-tracker.php';

        $stub_contents = <<<'PHP'
<?php
$GLOBALS['sitepulse_mu_loader_stub_includes'][] = __FILE__;
PHP;

        $this->assertNotFalse(
            file_put_contents($stub_tracker, $stub_contents),
            'Failed to create the stub tracker file for the renamed plugin.'
        );

        $filter = static function ($basename) {
            return 'sitepulse-renamed/sitepulse.php';
        };

        add_filter('sitepulse_plugin_basename', $filter);

        try {
            sitepulse_plugin_impact_install_mu_loader();
        } finally {
            remove_filter('sitepulse_plugin_basename', $filter);
        }

        $this->assertFileExists(
            $this->mu_loader_file,
            'MU loader installation should create the loader file.'
        );

        unset($GLOBALS['sitepulse_mu_loader_stub_includes']);

        include $this->mu_loader_file;

        $this->assertSame(
            [$stub_tracker],
            $GLOBALS['sitepulse_mu_loader_stub_includes'] ?? [],
            'MU loader should include the tracker from the renamed plugin directory.'
        );
    }

    private function remove_directory($directory): void {
        if (!is_string($directory) || $directory === '' || !file_exists($directory)) {
            return;
        }

        if (!is_dir($directory)) {
            unlink($directory);

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
