<?php
/**
 * Tests for rendering the SitePulse custom dashboard page.
 */

require_once __DIR__ . '/includes/stubs.php';

if (!defined('SITEPULSE_OPTION_ACTIVE_MODULES')) {
    define('SITEPULSE_OPTION_ACTIVE_MODULES', 'sitepulse_active_modules');
}

if (!defined('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS')) {
    define('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS', 'sitepulse_speed_scan_results');
}

if (!defined('SITEPULSE_OPTION_SPEED_SCAN_HISTORY')) {
    define('SITEPULSE_OPTION_SPEED_SCAN_HISTORY', 'sitepulse_speed_scan_history');
}

if (!defined('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT')) {
    define('SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT', 'sitepulse_resource_monitor_snapshot');
}

if (!defined('SITEPULSE_OPTION_IMPACT_THRESHOLDS')) {
    define('SITEPULSE_OPTION_IMPACT_THRESHOLDS', 'sitepulse_impact_thresholds');
}

if (!defined('SITEPULSE_PLUGIN_IMPACT_OPTION')) {
    define('SITEPULSE_PLUGIN_IMPACT_OPTION', 'sitepulse_plugin_impact_stats');
}

if (!defined('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX')) {
    define('SITEPULSE_TRANSIENT_PLUGIN_DIR_SIZE_PREFIX', 'sitepulse_plugin_dir_size_');
}

if (!function_exists('sitepulse_get_capability')) {
    function sitepulse_get_capability() {
        return 'manage_options';
    }
}

require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/custom_dashboards.php';
require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/resource_monitor.php';
require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/plugin_impact_scanner.php';

class Sitepulse_Custom_Dashboard_Render_Test extends WP_UnitTestCase {
    /**
     * @var string|null
     */
    private $logFile;

    protected function setUp(): void {
        parent::setUp();

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
        delete_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        delete_option(SITEPULSE_OPTION_UPTIME_LOG);
        delete_option(SITEPULSE_OPTION_ACTIVE_MODULES);
        delete_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY);
        delete_option(SITEPULSE_OPTION_SPEED_WARNING_MS);
        delete_option(SITEPULSE_OPTION_SPEED_CRITICAL_MS);
        delete_option(SITEPULSE_OPTION_UPTIME_WARNING_PERCENT);
        delete_option(SITEPULSE_OPTION_REVISION_LIMIT);
        delete_transient(SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT);
        delete_option(SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY);
        delete_option(SITEPULSE_PLUGIN_IMPACT_OPTION);
        delete_option(SITEPULSE_OPTION_IMPACT_THRESHOLDS);

        $scripts = wp_scripts();
        $scripts->remove('sitepulse-chartjs');
        $scripts->remove('sitepulse-dashboard-charts');

        $this->logFile = tempnam(sys_get_temp_dir(), 'sitepulse-log');
        $GLOBALS['sitepulse_test_log_path'] = $this->logFile;
    }

    protected function tearDown(): void {
        unset($GLOBALS['sitepulse_test_log_path']);

        if (is_string($this->logFile) && $this->logFile !== '' && file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        $this->logFile = null;

        parent::tearDown();
    }

    private function seedModuleData(): void {
        set_transient(
            SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS,
            [
                'server_processing_ms' => 150,
                'timestamp'            => time(),
            ]
        );

        update_option(
            SITEPULSE_OPTION_SPEED_SCAN_HISTORY,
            [
                [
                    'timestamp'            => time() - HOUR_IN_SECONDS * 3,
                    'server_processing_ms' => 220,
                ],
                [
                    'timestamp'            => time() - HOUR_IN_SECONDS * 2,
                    'server_processing_ms' => 180,
                ],
                [
                    'timestamp'            => time() - HOUR_IN_SECONDS,
                    'server_processing_ms' => 150,
                ],
            ]
        );

        update_option(
            SITEPULSE_OPTION_UPTIME_LOG,
            [
                [
                    'timestamp' => time(),
                    'status'    => true,
                ],
                [
                    'timestamp' => time() - HOUR_IN_SECONDS,
                    'status'    => false,
                ],
            ]
        );

        if (is_string($this->logFile)) {
            file_put_contents(
                $this->logFile,
                "[2024-01-01 00:00:00] PHP Warning: Something happened\n" .
                "[2024-01-01 00:05:00] PHP Deprecated: Old function used\n"
            );
        }

        $post_id = $this->factory->post->create([
            'post_title'   => 'Seeded Post',
            'post_content' => 'Initial content',
            'post_status'  => 'publish',
        ]);

        if ($post_id && !is_wp_error($post_id)) {
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => 'Updated content to create revision',
            ]);
        }

        $resource_snapshot = [
            'load'                 => [1.2, 0.9, 0.6],
            'load_display'         => '1.20 / 0.90 / 0.60',
            'memory_usage'         => '128 MB',
            'memory_usage_bytes'   => 128 * MB_IN_BYTES,
            'memory_limit'         => '256M',
            'memory_limit_bytes'   => 256 * MB_IN_BYTES,
            'disk_free'            => '20 GB',
            'disk_free_bytes'      => 20 * GB_IN_BYTES,
            'disk_total'           => '40 GB',
            'disk_total_bytes'     => 40 * GB_IN_BYTES,
            'notices'              => [],
            'generated_at'         => time(),
        ];

        set_transient(
            SITEPULSE_TRANSIENT_RESOURCE_MONITOR_SNAPSHOT,
            $resource_snapshot,
            MINUTE_IN_SECONDS
        );

        update_option(
            SITEPULSE_OPTION_RESOURCE_MONITOR_HISTORY,
            [
                [
                    'timestamp' => time() - HOUR_IN_SECONDS,
                    'load'      => [1.1, 0.8, 0.5],
                    'memory'    => [
                        'usage' => 120 * MB_IN_BYTES,
                        'limit' => 256 * MB_IN_BYTES,
                    ],
                    'disk'      => [
                        'free'  => 22 * GB_IN_BYTES,
                        'total' => 40 * GB_IN_BYTES,
                    ],
                ],
                [
                    'timestamp' => time(),
                    'load'      => [1.2, 0.9, 0.6],
                    'memory'    => [
                        'usage' => 128 * MB_IN_BYTES,
                        'limit' => 256 * MB_IN_BYTES,
                    ],
                    'disk'      => [
                        'free'  => 20 * GB_IN_BYTES,
                        'total' => 40 * GB_IN_BYTES,
                    ],
                ],
            ]
        );

        update_option(
            SITEPULSE_PLUGIN_IMPACT_OPTION,
            [
                'last_updated' => time() - MINUTE_IN_SECONDS,
                'interval'     => 15 * MINUTE_IN_SECONDS,
                'samples'      => [
                    'plugin-one/plugin-one.php' => [
                        'avg_ms'        => 45.5,
                        'last_ms'       => 50.0,
                        'samples'       => 12,
                        'last_recorded' => time() - MINUTE_IN_SECONDS,
                    ],
                    'plugin-two/plugin-two.php' => [
                        'avg_ms'        => 18.2,
                        'last_ms'       => 16.0,
                        'samples'       => 8,
                        'last_recorded' => time() - (2 * MINUTE_IN_SECONDS),
                    ],
                ],
            ]
        );
    }

    private function getModuleExpectations(): array {
        return [
            'speed_analyzer'     => [
                'chart_id'  => 'sitepulse-speed-chart',
                'link'      => 'admin.php?page=sitepulse-speed',
                'chart_key' => 'speed',
                'summary_id' => sitepulse_get_chart_summary_id('sitepulse-speed-chart'),
            ],
            'uptime_tracker'     => [
                'chart_id'  => 'sitepulse-uptime-chart',
                'link'      => 'admin.php?page=sitepulse-uptime',
                'chart_key' => 'uptime',
                'summary_id' => sitepulse_get_chart_summary_id('sitepulse-uptime-chart'),
            ],
            'database_optimizer' => [
                'chart_id'  => 'sitepulse-database-chart',
                'link'      => 'admin.php?page=sitepulse-db',
                'chart_key' => 'database',
                'summary_id' => sitepulse_get_chart_summary_id('sitepulse-database-chart'),
            ],
            'log_analyzer'       => [
                'chart_id'  => 'sitepulse-log-chart',
                'link'      => 'admin.php?page=sitepulse-logs',
                'chart_key' => 'logs',
                'summary_id' => null,
            ],
            'resource_monitor'   => [
                'chart_id'  => 'sitepulse-resource-chart',
                'link'      => 'admin.php?page=sitepulse-resources',
                'chart_key' => 'resource',
                'summary_id' => sitepulse_get_chart_summary_id('sitepulse-resource-chart'),
            ],
            'plugin_impact_scanner' => [
                'chart_id'  => 'sitepulse-plugins-chart',
                'link'      => 'admin.php?page=sitepulse-plugins',
                'chart_key' => 'plugins',
                'summary_id' => sitepulse_get_chart_summary_id('sitepulse-plugins-chart'),
            ],
        ];
    }

    private function getLocalizedCharts(): array {
        $scripts = wp_scripts();
        $data = $scripts->get_data('sitepulse-dashboard-charts', 'data');

        $this->assertIsString($data);
        $this->assertSame(1, preg_match('/var SitePulseDashboardData = (.*);/', $data, $matches));

        $payload = json_decode($matches[1], true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('charts', $payload);
        $this->assertIsArray($payload['charts']);

        return $payload['charts'];
    }

    public function test_disabling_modules_hides_cards_and_data(): void {
        $this->seedModuleData();
        update_option(SITEPULSE_OPTION_ACTIVE_MODULES, ['custom_dashboards']);

        sitepulse_custom_dashboard_enqueue_assets('toplevel_page_sitepulse-dashboard');

        ob_start();
        sitepulse_custom_dashboards_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('sitepulse-speed-chart', $output);
        $this->assertStringNotContainsString('admin.php?page=sitepulse-speed', $output);
        $this->assertStringNotContainsString(sitepulse_get_chart_summary_id('sitepulse-speed-chart'), $output);
        $this->assertStringNotContainsString('sitepulse-uptime-chart', $output);
        $this->assertStringNotContainsString('admin.php?page=sitepulse-uptime', $output);
        $this->assertStringNotContainsString(sitepulse_get_chart_summary_id('sitepulse-uptime-chart'), $output);
        $this->assertStringNotContainsString('sitepulse-database-chart', $output);
        $this->assertStringNotContainsString('admin.php?page=sitepulse-db', $output);
        $this->assertStringNotContainsString(sitepulse_get_chart_summary_id('sitepulse-database-chart'), $output);
        $this->assertStringNotContainsString('sitepulse-log-chart', $output);
        $this->assertStringNotContainsString('admin.php?page=sitepulse-logs', $output);
        $this->assertStringNotContainsString('sitepulse-resource-chart', $output);
        $this->assertStringNotContainsString('admin.php?page=sitepulse-resources', $output);
        $this->assertStringNotContainsString(sitepulse_get_chart_summary_id('sitepulse-resource-chart'), $output);
        $this->assertStringNotContainsString('sitepulse-plugins-chart', $output);
        $this->assertStringNotContainsString('admin.php?page=sitepulse-plugins', $output);
        $this->assertStringNotContainsString(sitepulse_get_chart_summary_id('sitepulse-plugins-chart'), $output);

        $charts = $this->getLocalizedCharts();
        $this->assertArrayNotHasKey('speed', $charts);
        $this->assertArrayNotHasKey('uptime', $charts);
        $this->assertArrayNotHasKey('database', $charts);
        $this->assertArrayNotHasKey('logs', $charts);
        $this->assertArrayNotHasKey('resource', $charts);
        $this->assertArrayNotHasKey('plugins', $charts);
    }

    /**
     * @return array<string, array{module: string}>
     */
    public function moduleDisablingDataProvider(): array {
        return [
            'speed analyzer disabled' => ['module' => 'speed_analyzer'],
            'uptime tracker disabled' => ['module' => 'uptime_tracker'],
            'database optimizer disabled' => ['module' => 'database_optimizer'],
            'log analyzer disabled' => ['module' => 'log_analyzer'],
            'resource monitor disabled' => ['module' => 'resource_monitor'],
            'plugin impact scanner disabled' => ['module' => 'plugin_impact_scanner'],
        ];
    }

    /**
     * @dataProvider moduleDisablingDataProvider
     */
    public function test_disabling_single_module_hides_card_and_localized_data(array $config): void {
        $this->seedModuleData();

        $module_expectations = $this->getModuleExpectations();
        $active_modules = array_keys($module_expectations);
        $active_modules[] = 'custom_dashboards';
        $active_modules = array_values(array_filter($active_modules, function ($module) use ($config) {
            return $module !== $config['module'];
        }));

        update_option(SITEPULSE_OPTION_ACTIVE_MODULES, $active_modules);

        sitepulse_custom_dashboard_enqueue_assets('toplevel_page_sitepulse-dashboard');

        ob_start();
        sitepulse_custom_dashboards_page();
        $output = ob_get_clean();

        $disabled = $module_expectations[$config['module']];
        $this->assertStringNotContainsString($disabled['chart_id'], $output);
        $this->assertStringNotContainsString($disabled['link'], $output);
        if (!empty($disabled['summary_id'])) {
            $this->assertStringNotContainsString($disabled['summary_id'], $output);
        }

        foreach ($module_expectations as $module_key => $details) {
            if ($module_key === $config['module']) {
                continue;
            }

            $this->assertStringContainsString($details['chart_id'], $output);
            $this->assertStringContainsString($details['link'], $output);
            if (!empty($details['summary_id'])) {
                $this->assertStringContainsString($details['summary_id'], $output);
            }
        }

        $charts = $this->getLocalizedCharts();

        $this->assertArrayNotHasKey($disabled['chart_key'], $charts);

        foreach ($module_expectations as $module_key => $details) {
            if ($module_key === $config['module']) {
                continue;
            }

            $this->assertArrayHasKey($details['chart_key'], $charts);
        }
    }
}
