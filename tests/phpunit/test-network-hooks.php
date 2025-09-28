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
}
