<?php

/**
 * @covers ::sitepulse_deactivate_site
 */
class Sitepulse_Deactivation_Capability_Test extends WP_UnitTestCase {
    /**
     * Ensures the filtered capability is removed from administrators on deactivation.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_deactivate_site_removes_filtered_capability(): void {
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/sitepulse.php';

        $filter = function() {
            return 'manage_sitepulse';
        };

        add_filter('sitepulse_required_capability', $filter);

        $administrator_role = get_role('administrator');
        $this->assertInstanceOf(WP_Role::class, $administrator_role);

        $administrator_role->add_cap('manage_sitepulse');
        $this->assertTrue($administrator_role->has_cap('manage_sitepulse'));

        sitepulse_deactivate_site();

        $administrator_role = get_role('administrator');
        $this->assertInstanceOf(WP_Role::class, $administrator_role);
        $this->assertFalse($administrator_role->has_cap('manage_sitepulse'));

        remove_filter('sitepulse_required_capability', $filter);
    }

    /**
     * Ensures the plugin directory scan queue is cleared on deactivation.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_deactivate_site_clears_plugin_dir_scan_queue(): void {
        require_once dirname(__DIR__, 2) . '/sitepulse_FR/sitepulse.php';

        $hook = 'sitepulse_queue_plugin_dir_scan';
        wp_clear_scheduled_hook($hook);

        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, $hook);
        $this->assertNotFalse(wp_next_scheduled($hook));

        update_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION, ['plugin/plugin.php']);
        $this->assertSame(['plugin/plugin.php'], get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION));

        sitepulse_deactivate_site();

        $this->assertFalse(wp_next_scheduled($hook));
        $this->assertFalse(get_option(SITEPULSE_PLUGIN_DIR_SCAN_QUEUE_OPTION));
    }
}
