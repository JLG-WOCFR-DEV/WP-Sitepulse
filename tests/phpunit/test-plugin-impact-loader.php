<?php
/**
 * Tests for the plugin impact MU loader installation warnings.
 */

class Sitepulse_Plugin_Impact_Loader_Test extends WP_UnitTestCase {
    protected $mu_loader_dir;
    protected $mu_loader_file;

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

        $paths = sitepulse_plugin_impact_get_mu_loader_paths();
        $this->mu_loader_dir  = $paths['dir'];
        $this->mu_loader_file = $paths['file'];

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

        parent::tearDown();
    }

    public function test_registers_warning_when_mu_directory_is_unwritable(): void {
        $original_dir = $this->mu_loader_dir;
        $backup_dir   = null;

        if (is_dir($original_dir)) {
            $backup_dir = $original_dir . '-backup-' . uniqid('', true);

            if (!@rename($original_dir, $backup_dir)) {
                $backup_dir = null;
            }
        }

        file_put_contents($original_dir, '');

        try {
            sitepulse_plugin_impact_install_mu_loader();

            $warnings = get_option(SITEPULSE_OPTION_CRON_WARNINGS, []);

            $this->assertIsArray($warnings, 'Cron warnings option should store an array.');
            $this->assertArrayHasKey('plugin_impact', $warnings, 'Plugin impact warning should be registered on failure.');
            $this->assertNotEmpty(
                $warnings['plugin_impact']['message'] ?? '',
                'Registered warning should include a user-facing message.'
            );
        } finally {
            if (file_exists($original_dir) && !is_dir($original_dir)) {
                unlink($original_dir);
            }

            if ($backup_dir !== null && is_dir($backup_dir)) {
                @rename($backup_dir, $original_dir);
            } elseif (!is_dir($original_dir)) {
                wp_mkdir_p($original_dir);
            }
        }
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
}
