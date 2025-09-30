<?php
/**
 * Tests for SitePulse network activation and deactivation helpers.
 */

class Sitepulse_Network_Hooks_Test extends WP_UnitTestCase {
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!function_exists('sitepulse_run_for_site')) {
            require_once dirname(__DIR__, 2) . '/sitepulse_FR/sitepulse.php';
        }
    }

    public function test_restore_not_called_when_switch_to_blog_fails(): void {
        $target_site_id = 98765;
        $callback_invocations = 0;
        $restores = 0;

        $filter = static function ($pre_switched, $site_id, $context) use ($target_site_id) {
            if ($site_id === $target_site_id && 'activation' === $context) {
                return false;
            }

            return $pre_switched;
        };

        add_filter('sitepulse_pre_switch_to_site', $filter, 10, 3);

        $action = static function ($new_blog_id, $prev_blog_id, $context) use (&$restores) {
            if ('restore' === $context) {
                $restores++;
            }
        };

        add_action('switch_blog', $action, 10, 3);

        $result = sitepulse_run_for_site(
            $target_site_id,
            function () use (&$callback_invocations) {
                $callback_invocations++;
            },
            'activation'
        );

        remove_filter('sitepulse_pre_switch_to_site', $filter, 10);
        remove_action('switch_blog', $action, 10);

        $this->assertFalse($result, 'Helper should report failure when the site switch fails.');
        $this->assertSame(0, $callback_invocations, 'Callback should not run when switch_to_blog fails.');
        $this->assertSame(0, $restores, 'restore_current_blog should not be called when switch_to_blog fails.');
    }

    public function test_partial_multisite_deactivation_preserves_mu_loader(): void {
        if (!is_multisite()) {
            $this->markTestSkipped('Multisite is not enabled.');
        }

        $paths = sitepulse_plugin_impact_get_mu_loader_paths();

        if (!is_dir($paths['dir'])) {
            wp_mkdir_p($paths['dir']);
        }

        $loader_file = $paths['file'];
        $loader_previously_existed = file_exists($loader_file);
        $original_loader_contents  = $loader_previously_existed ? file_get_contents($loader_file) : null;

        if (!$loader_previously_existed) {
            $this->assertNotFalse(
                file_put_contents($loader_file, 'sitepulse-mu-loader'),
                'Failed to prime the MU loader file before running the test.'
            );
        }

        $original_signature = get_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE, false);
        update_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE, 'network-signature');

        $basename = sitepulse_get_stored_plugin_basename();

        $main_site_plugins = get_option('active_plugins', []);

        if (!is_array($main_site_plugins)) {
            $main_site_plugins = [];
        }

        $network_active_plugins = get_site_option('active_sitewide_plugins', []);

        if (!is_array($network_active_plugins)) {
            $network_active_plugins = [];
        }

        update_site_option('active_sitewide_plugins', []);

        $second_site_id = self::factory()->blog->create();
        $second_site_plugins = get_blog_option($second_site_id, 'active_plugins', []);

        if (!is_array($second_site_plugins)) {
            $second_site_plugins = [];
        }

        $original_second_site_plugins = $second_site_plugins;

        if (!in_array($basename, $second_site_plugins, true)) {
            $second_site_plugins[] = $basename;
            update_blog_option($second_site_id, 'active_plugins', $second_site_plugins);
        }

        $main_site_without_plugin = array_values(array_diff($main_site_plugins, [$basename]));
        update_option('active_plugins', $main_site_without_plugin);

        try {
            sitepulse_deactivate_site();

            $this->assertFileExists(
                $loader_file,
                'MU loader should remain when the plugin stays active on another site.'
            );

            $this->assertSame(
                'network-signature',
                get_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE),
                'Signature option should remain untouched during partial deactivation.'
            );
        } finally {
            update_option('active_plugins', $main_site_plugins);
            update_blog_option($second_site_id, 'active_plugins', $original_second_site_plugins);
            update_site_option('active_sitewide_plugins', $network_active_plugins);

            if (function_exists('wpmu_delete_blog')) {
                wpmu_delete_blog($second_site_id, true);
            }

            if ($loader_previously_existed) {
                if ($original_loader_contents !== null) {
                    file_put_contents($loader_file, $original_loader_contents);
                }
            } elseif (file_exists($loader_file)) {
                unlink($loader_file);
            }

            if ($original_signature === false) {
                delete_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE);
            } else {
                update_option(SITEPULSE_OPTION_IMPACT_LOADER_SIGNATURE, $original_signature);
            }
        }
    }
}
