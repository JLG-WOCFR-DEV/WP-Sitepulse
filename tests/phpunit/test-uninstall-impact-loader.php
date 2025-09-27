<?php
/**
 * Tests for uninstall routines related to the plugin impact MU loader.
 */

class Sitepulse_Uninstall_Impact_Loader_Test extends WP_UnitTestCase {
    private $mu_loader_dir;
    private $mu_loader_file;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!function_exists('sitepulse_plugin_impact_get_mu_loader_paths')) {
            require_once dirname(__DIR__, 2) . '/sitepulse_FR/sitepulse.php';
        }
    }

    protected function setUp(): void {
        parent::setUp();

        $paths = sitepulse_plugin_impact_get_mu_loader_paths();
        $this->mu_loader_dir  = $paths['dir'];
        $this->mu_loader_file = $paths['file'];

        if (!is_dir($this->mu_loader_dir)) {
            wp_mkdir_p($this->mu_loader_dir);
        }

        if (file_exists($this->mu_loader_file)) {
            unlink($this->mu_loader_file);
        }

        delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
    }

    protected function tearDown(): void {
        if (file_exists($this->mu_loader_file)) {
            unlink($this->mu_loader_file);
        }

        parent::tearDown();
    }

    public function test_uninstall_removes_mu_loader_and_signature_option(): void {
        $this->assertTrue(
            (bool) file_put_contents($this->mu_loader_file, 'dummy loader'),
            'Failed to create the fake MU loader file before running uninstall.'
        );

        update_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE, 'dummy-signature');

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }

        require dirname(__DIR__, 2) . '/sitepulse_FR/uninstall.php';

        $this->assertFileDoesNotExist(
            $this->mu_loader_file,
            'Uninstall routine should remove the MU loader file.'
        );

        $this->assertFalse(
            get_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE, false),
            'Signature option should be deleted during uninstall.'
        );
    }
}
