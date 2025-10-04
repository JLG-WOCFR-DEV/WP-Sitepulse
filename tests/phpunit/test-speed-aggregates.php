<?php
/**
 * Tests for the speed analyzer aggregate helpers.
 */

require_once __DIR__ . '/includes/stubs.php';

class Sitepulse_Speed_Aggregates_Test extends WP_UnitTestCase {
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__, 2) . '/sitepulse_FR/modules/speed_analyzer.php';
    }

    public function test_returns_empty_aggregates_when_history_missing(): void {
        $aggregates = sitepulse_speed_analyzer_get_aggregates([], [
            'warning'  => 200,
            'critical' => 500,
        ]);

        $this->assertSame(0, $aggregates['count']);
        $this->assertSame(0, $aggregates['filtered_count']);
        $this->assertSame(0, $aggregates['excluded_outliers']);

        foreach (['mean', 'median', 'p95', 'best', 'worst'] as $metric) {
            $this->assertArrayHasKey($metric, $aggregates['metrics']);
            $this->assertNull($aggregates['metrics'][$metric]['value']);
            $this->assertSame('status-warn', $aggregates['metrics'][$metric]['status']);
        }
    }

    public function test_computes_statistics_and_filters_outliers(): void {
        $history = [
            ['timestamp' => 1, 'server_processing_ms' => 100.0],
            ['timestamp' => 2, 'server_processing_ms' => 105.0],
            ['timestamp' => 3, 'server_processing_ms' => 110.0],
            ['timestamp' => 4, 'server_processing_ms' => 120.0],
            ['timestamp' => 5, 'server_processing_ms' => 600.0],
            ['timestamp' => 6, 'server_processing_ms' => 8000.0],
        ];

        $thresholds = [
            'warning'  => 150,
            'critical' => 400,
        ];

        $aggregates = sitepulse_speed_analyzer_get_aggregates($history, $thresholds);

        $this->assertSame(6, $aggregates['count']);
        $this->assertSame(5, $aggregates['filtered_count']);
        $this->assertSame(1, $aggregates['excluded_outliers']);

        $this->assertEqualsWithDelta(207.0, $aggregates['metrics']['mean']['value'], 0.001);
        $this->assertEqualsWithDelta(110.0, $aggregates['metrics']['median']['value'], 0.001);
        $this->assertEqualsWithDelta(6150.0, $aggregates['metrics']['p95']['value'], 0.001);
        $this->assertEqualsWithDelta(100.0, $aggregates['metrics']['best']['value'], 0.001);
        $this->assertEqualsWithDelta(8000.0, $aggregates['metrics']['worst']['value'], 0.001);

        $this->assertSame('status-warn', $aggregates['metrics']['mean']['status']);
        $this->assertSame('status-ok', $aggregates['metrics']['median']['status']);
        $this->assertSame('status-bad', $aggregates['metrics']['p95']['status']);
        $this->assertSame('status-ok', $aggregates['metrics']['best']['status']);
        $this->assertSame('status-bad', $aggregates['metrics']['worst']['status']);
    }
}
