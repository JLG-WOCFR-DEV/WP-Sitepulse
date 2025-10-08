<?php
/**
 * Tests for the dashboard preview block summaries.
 */

require_once dirname(__DIR__, 2) . '/sitepulse_FR/blocks/dashboard-preview/render.php';

class Sitepulse_Dashboard_Preview_Summary_Test extends WP_UnitTestCase {
    public function test_near_integer_values_are_rendered_without_decimals(): void {
        $chart = [
            'labels'   => ['Disponibilité'],
            'datasets' => [
                [
                    'data' => [99.999],
                ],
            ],
        ];

        $summary = sitepulse_dashboard_preview_render_dataset_summary($chart);

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('html', $summary);
        $this->assertMatchesRegularExpression(
            '/<span class="sitepulse-preview-list__value">100<\/span>/',
            $summary['html']
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<span class="sitepulse-preview-list__value">100(?:\.|,)00<\/span>/',
            $summary['html']
        );
    }

    public function test_decimal_values_keep_two_decimals(): void {
        $chart = [
            'labels'   => ['Temps de réponse'],
            'datasets' => [
                [
                    'data' => [54.125],
                ],
            ],
        ];

        $summary = sitepulse_dashboard_preview_render_dataset_summary($chart);

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('html', $summary);
        $this->assertMatchesRegularExpression(
            '/<span class="sitepulse-preview-list__value">54(?:\.|,)13<\/span>/',
            $summary['html']
        );
    }

    public function test_status_badge_falls_back_to_warning_when_status_missing(): void {
        $definition = [
            'title'       => 'Test card',
            'subtitle'    => 'Subtitle',
            'description' => 'Description',
            'chart'       => [
                'labels'   => ['foo'],
                'datasets' => [
                    [
                        'data' => [1],
                    ],
                ],
            ],
        ];

        $status_labels = [
            'status-warn' => [
                'label' => 'Warning',
                'sr'    => 'Statut avertissement',
                'icon'  => '!',
            ],
        ];

        $markup = sitepulse_dashboard_preview_render_card_section($definition, $status_labels);

        $this->assertStringContainsString('class="status-badge status-warn"', $markup);
    }
}

