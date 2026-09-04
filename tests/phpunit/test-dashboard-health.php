<?php
/**
 * Tests for the dashboard health score and playbooks.
 */

require_once __DIR__ . '/includes/stubs.php';

if (!defined('SITEPULSE_OPTION_DASHBOARD_RANGE')) {
    define('SITEPULSE_OPTION_DASHBOARD_RANGE', 'sitepulse_dashboard_range');
}

if (!defined('SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX')) {
    define('SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX', 'sitepulse_dashboard_impact_index');
}

require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/custom_dashboards.php';

class Sitepulse_Dashboard_Health_Test extends WP_UnitTestCase {
    public function test_log_impact_entry_scores_fatal_errors_as_critical(): void {
        $entry = sitepulse_custom_dashboard_build_log_impact_entry(
            [
                'card' => [
                    'counts' => [
                        'fatal'      => 4,
                        'warning'    => 1,
                        'notice'     => 0,
                        'deprecated' => 0,
                    ],
                ],
            ],
            ['log_analyzer' => true]
        );

        $this->assertTrue($entry['active']);
        $this->assertSame('status-bad', $entry['status']);
        $this->assertGreaterThanOrEqual(70.0, $entry['score']);
    }

    public function test_log_impact_entry_is_clean_when_counts_are_zero(): void {
        $entry = sitepulse_custom_dashboard_build_log_impact_entry(
            [
                'card' => [
                    'counts' => [
                        'fatal'      => 0,
                        'warning'    => 0,
                        'notice'     => 0,
                        'deprecated' => 0,
                    ],
                ],
            ],
            ['log_analyzer' => true]
        );

        $this->assertSame('status-ok', $entry['status']);
        $this->assertSame(0.0, $entry['score']);
        $this->assertSame('Log clean', $entry['signal']);
    }

    public function test_health_score_inverts_impact_and_weights_errors(): void {
        $impact = sitepulse_custom_dashboard_calculate_transverse_impact_index(
            '24h',
            ['seconds' => DAY_IN_SECONDS],
            [
                'uptime_tracker' => true,
                'speed_analyzer' => true,
                'log_analyzer'   => true,
                'ai_insights'    => false,
            ],
            [
                'uptime'     => 100.0,
                'violations' => 0,
                'totals'     => ['total' => 24],
            ],
            [
                'average'    => 80.0,
                'trend'      => 0,
                'thresholds' => ['warning' => 200, 'critical' => 500],
                'samples'    => 3,
            ],
            null,
            [
                'card' => [
                    'counts' => [
                        'fatal'      => 0,
                        'warning'    => 0,
                        'notice'     => 0,
                        'deprecated' => 0,
                    ],
                ],
            ]
        );

        $this->assertArrayHasKey('log_analyzer', $impact['modules']);
        $this->assertArrayHasKey('health', $impact);
        $this->assertNotNull($impact['overall']);
        $this->assertEqualsWithDelta(100.0 - $impact['overall'], $impact['health'], 0.01);
        $this->assertGreaterThanOrEqual(90.0, $impact['health']);
    }

    public function test_playbooks_include_speed_when_backend_is_critical(): void {
        $cards = [
            'uptime' => [
                'inactive' => false,
                'status'   => ['class' => 'status-ok'],
            ],
            'speed' => [
                'inactive' => false,
                'status'   => ['class' => 'status-bad'],
            ],
            'logs' => [
                'inactive' => false,
                'status'   => ['class' => 'status-ok'],
            ],
        ];

        $playbooks = sitepulse_custom_dashboard_build_playbooks($cards, []);
        $ids = array_map(static function ($item) {
            return $item['id'] ?? '';
        }, $playbooks);

        $this->assertContains('speed', $ids);
        $this->assertNotContains('uptime', $ids);
    }

    public function test_health_view_exposes_active_modules(): void {
        $view = sitepulse_custom_dashboard_format_health_view(
            [
                'overall' => 20.0,
                'health'  => 80.0,
                'dominant_module' => 'speed_analyzer',
                'modules' => [
                    'uptime_tracker' => [
                        'label'  => 'Availability',
                        'active' => true,
                        'score'  => 10.0,
                        'status' => 'status-ok',
                        'signal' => 'Uptime 99.9%',
                    ],
                    'speed_analyzer' => [
                        'label'  => 'Performance',
                        'active' => true,
                        'score'  => 40.0,
                        'status' => 'status-warn',
                        'signal' => 'Average 250 ms',
                    ],
                    'ai_insights' => [
                        'label'  => 'AI backlog',
                        'active' => false,
                        'score'  => null,
                    ],
                ],
            ],
            '24 heures'
        );

        $this->assertSame(80.0, $view['score']);
        $this->assertSame('status-ok', $view['status']['class']);
        $this->assertCount(2, $view['modules']);
    }
}
