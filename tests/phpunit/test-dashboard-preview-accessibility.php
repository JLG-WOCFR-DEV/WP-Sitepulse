<?php
/**
 * Accessibility-focused tests for the dashboard preview block rendering.
 */

require_once dirname(__DIR__, 2) . '/sitepulse_FR/blocks/dashboard-preview/render.php';

class Sitepulse_Dashboard_Preview_Accessibility_Test extends WP_UnitTestCase {
    private $previous_use_internal_errors = false;

    protected function setUp(): void {
        parent::setUp();

        $this->previous_use_internal_errors = libxml_use_internal_errors(true);
    }

    protected function tearDown(): void {
        libxml_use_internal_errors((bool) $this->previous_use_internal_errors);

        parent::tearDown();
    }

    public function test_chart_area_links_canvas_with_text_summary(): void {
        $chart = [
            'labels'   => ['Disponibilité'],
            'datasets' => [
                [
                    'data'  => [99.8],
                    'label' => 'Uptime',
                ],
            ],
        ];

        $html = sitepulse_dashboard_preview_render_chart_area('sitepulse-preview-canvas', $chart);

        $this->assertStringContainsString('aria-describedby="', $html);

        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        $canvas = $document->getElementsByTagName('canvas')->item(0);
        $this->assertNotNull($canvas, 'A canvas element should be rendered when chart data is available.');

        $summary_id = $canvas->getAttribute('aria-describedby');
        $this->assertNotSame('', $summary_id, 'The canvas should reference the textual summary via aria-describedby.');

        $summary = $document->getElementById($summary_id);
        $this->assertNotNull($summary, 'The referenced textual summary should exist in the markup.');
        $this->assertSame('div', $summary->tagName);
    }

    public function test_chart_canvas_contains_hidden_fallback_and_screen_reader_copy(): void {
        $chart = [
            'labels'   => ['Disponibilité'],
            'datasets' => [
                [
                    'data'  => [99.8],
                    'label' => 'Uptime',
                ],
            ],
        ];

        $html = sitepulse_dashboard_preview_render_chart_area('sitepulse-preview-canvas', $chart);

        $this->assertStringContainsString('<span aria-hidden="true">', $html);
        $this->assertStringContainsString('class="screen-reader-text"', $html);
        $this->assertStringContainsString('Votre navigateur ne prend pas en charge les graphiques.', $html);
        $this->assertStringContainsString('Les données du graphique sont détaillées dans le résumé textuel qui suit.', $html);
    }

    public function test_chart_area_falls_back_to_aria_label_when_no_summary(): void {
        $chart = [
            'labels'   => [],
            'datasets' => [
                [
                    'data'  => [50],
                    'label' => 'Taux de réussite',
                ],
            ],
        ];

        $html = sitepulse_dashboard_preview_render_chart_area('sitepulse-preview-label', $chart);

        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('aria-label="Aperçu du graphique des données SitePulse."', $html);
        $this->assertStringNotContainsString('aria-describedby="', $html);
    }

    public function test_empty_chart_outputs_placeholder_with_summary_container(): void {
        $chart = [
            'empty'    => true,
            'labels'   => ['Latence'],
            'datasets' => [],
        ];

        $html = sitepulse_dashboard_preview_render_chart_area('sitepulse-preview-empty', $chart);

        $this->assertStringContainsString('sitepulse-chart-placeholder', $html);
        $this->assertStringContainsString('Pas encore de mesures disponibles pour ce graphique.', $html);
    }
}
