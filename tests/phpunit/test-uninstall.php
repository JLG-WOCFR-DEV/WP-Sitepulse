<?php

class SitePulse_Uninstall_Test extends WP_UnitTestCase {
    /**
     * Executes the plugin uninstall routine.
     */
    protected function run_uninstall() {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }

        require dirname(__DIR__, 2) . '/sitepulse_FR/uninstall.php';
    }

    public function test_uninstall_deletes_debug_notices_option_on_single_site() {
        update_option(SITEPULSE_OPTION_DEBUG_NOTICES, ['notice']);

        $this->assertSame(['notice'], get_option(SITEPULSE_OPTION_DEBUG_NOTICES));

        $this->run_uninstall();

        $this->assertFalse(get_option(SITEPULSE_OPTION_DEBUG_NOTICES, false));
    }

    public function test_uninstall_deletes_debug_notices_option_on_multisite() {
        if (!is_multisite()) {
            $this->markTestSkipped('Multisite is not enabled.');
        }

        update_site_option(SITEPULSE_OPTION_DEBUG_NOTICES, ['network_notice']);

        $this->assertSame(['network_notice'], get_site_option(SITEPULSE_OPTION_DEBUG_NOTICES));

        $this->run_uninstall();

        $this->assertFalse(get_site_option(SITEPULSE_OPTION_DEBUG_NOTICES, false));
    }
}

