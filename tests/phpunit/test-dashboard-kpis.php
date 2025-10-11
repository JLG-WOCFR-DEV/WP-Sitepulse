<?php
/**
 * Tests for KPI helpers used by the dashboard banner.
 */

require_once __DIR__ . '/includes/stubs.php';

if (!defined('SITEPULSE_OPTION_DASHBOARD_DEBT_HISTORY')) {
    define('SITEPULSE_OPTION_DASHBOARD_DEBT_HISTORY', 'sitepulse_dashboard_debt_history');
}

require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/custom_dashboards.php';

class Sitepulse_Dashboard_Kpis_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();

        delete_option(SITEPULSE_OPTION_DASHBOARD_DEBT_HISTORY);
    }

    public function test_build_kpi_cards_includes_incidents_even_when_empty(): void {
        $payload = [
            'uptime' => [
                'uptime' => 99.95,
                'totals' => ['total' => 12],
                'trend'  => ['uptime' => 0.0],
            ],
            'incidents' => [],
            'ai_summary' => [
                'recent_pending' => 0,
                'stale_pending'  => 0,
            ],
            'remote_queue' => [
                'metrics' => [
                    'queue_length'       => 1,
                    'prioritized_jobs'   => 0,
                    'delayed_jobs'       => 0,
                    'avg_priority'       => 1,
                    'max_wait_seconds'   => 0,
                ],
            ],
        ];

        $cards = sitepulse_custom_dashboard_build_kpi_cards($payload, '24 heures', time());

        $this->assertNotEmpty($cards);

        $keys = array_map(static function ($card) {
            return $card['key'] ?? null;
        }, $cards);

        $this->assertContains('incidents', $keys);
        $this->assertContains('debt', $keys);

        $incidents_card = null;

        foreach ($cards as $card) {
            if (($card['key'] ?? '') === 'incidents') {
                $incidents_card = $card;
                break;
            }
        }

        $this->assertIsArray($incidents_card);
        $this->assertSame('0', $incidents_card['value']);
        $this->assertIsArray($incidents_card['items']);
        $this->assertCount(0, $incidents_card['items']);
        $this->assertNotEmpty($incidents_card['empty_message']);
    }

    public function test_incident_kpi_limits_items_and_formats_since_label(): void {
        $now = time();

        $incidents = [];

        for ($i = 0; $i < 4; $i++) {
            $incidents[] = [
                'agent_label'   => 'Agent ' . $i,
                'severity'      => $i === 0 ? 'critical' : 'warning',
                'incident_start'=> $now - (($i + 1) * HOUR_IN_SECONDS),
                'error'         => '<strong>Erreur ' . $i . '</strong>',
            ];
        }

        $card = sitepulse_custom_dashboard_format_incident_kpi($incidents, '24 heures', $now);

        $this->assertSame('incidents', $card['key']);
        $this->assertCount(3, $card['items']);

        foreach ($card['items'] as $item) {
            $this->assertStringNotContainsString('<strong>', $item['description']);
            $this->assertStringContainsString('depuis', $item['description']);
        }
    }

    public function test_debt_kpi_builds_sparkline_and_trend(): void {
        $base = time() - (7 * DAY_IN_SECONDS);

        for ($i = 0; $i < 7; $i++) {
            sitepulse_custom_dashboard_store_debt_sample(10 + $i, $base + ($i * DAY_IN_SECONDS));
        }

        $snapshot = [
            'score' => 22.5,
            'queue' => [
                'length'       => 5,
                'prioritized'  => 2,
                'delayed'      => 1,
                'max_wait'     => 1800,
            ],
            'ai'    => [
                'pending' => 3,
                'stale'   => 1,
            ],
            'history' => sitepulse_custom_dashboard_get_debt_history(),
        ];

        $card = sitepulse_custom_dashboard_format_debt_kpi($snapshot, '24 heures', time());

        $this->assertSame('debt', $card['key']);
        $this->assertSame('warning', $card['status']);
        $this->assertNotEmpty($card['sparkline']);
        $this->assertLessThanOrEqual(7, count($card['sparkline']));
        $this->assertNotEmpty($card['sparkline_sr']);
        $this->assertIsArray($card['trend']);
        $this->assertArrayHasKey('direction', $card['trend']);
        $this->assertStringContainsString('File d’attente', $card['summary']);
    }
}

