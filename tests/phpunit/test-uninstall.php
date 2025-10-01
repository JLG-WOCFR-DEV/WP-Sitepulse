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

    public function test_uninstall_removes_filtered_capability(): void {
        $filter = function() {
            return 'manage_sitepulse';
        };

        add_filter('sitepulse_required_capability', $filter);

        $administrator_role = get_role('administrator');
        $this->assertInstanceOf(WP_Role::class, $administrator_role);

        $administrator_role->add_cap('manage_sitepulse');
        $this->assertTrue($administrator_role->has_cap('manage_sitepulse'));

        $this->run_uninstall();

        $administrator_role = get_role('administrator');
        $this->assertInstanceOf(WP_Role::class, $administrator_role);
        $this->assertFalse($administrator_role->has_cap('manage_sitepulse'));

        remove_filter('sitepulse_required_capability', $filter);
    }

    public function test_uninstall_clears_plugin_dir_scan_queue(): void {
        wp_clear_scheduled_hook('sitepulse_queue_plugin_dir_scan');

        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'sitepulse_queue_plugin_dir_scan');
        $this->assertNotFalse(wp_next_scheduled('sitepulse_queue_plugin_dir_scan'));

        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, ['plugin/plugin.php']);
        $this->assertSame(['plugin/plugin.php'], get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION));

        $this->run_uninstall();

        $this->assertFalse(wp_next_scheduled('sitepulse_queue_plugin_dir_scan'));
        $this->assertFalse(get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION));
    }
}

