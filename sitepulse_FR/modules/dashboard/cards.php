<?php
/**
 * SitePulse dashboard metric card formatters.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formats a delta value into a trend descriptor.
 *
 * @param float|int|null $delta Numeric delta compared to previous window.
 * @param array<string,mixed> $args Optional configuration.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_trend($delta, $args = []) {
    $defaults = [
        'tolerance'         => 0.01,
        'unit'              => '',
        'precision'         => 2,
        'increase_good'     => true,
        'positive_template' => __('Improved by %s%s', 'sitepulse'),
        'negative_template' => __('Regressed by %s%s', 'sitepulse'),
        'positive_sr'       => __('Metric improved by %s%s compared to the previous window.', 'sitepulse'),
        'negative_sr'       => __('Metric regressed by %s%s compared to the previous window.', 'sitepulse'),
        'stable_template'   => __('Stable compared to the previous window.', 'sitepulse'),
        'stable_sr'         => __('Metric is stable compared to the previous window.', 'sitepulse'),
        'missing_template'  => __('No comparison available for this metric yet.', 'sitepulse'),
        'missing_sr'        => __('Comparison data is not available for this metric.', 'sitepulse'),
    ];

    $config = array_merge($defaults, is_array($args) ? $args : []);

    if (!is_numeric($delta)) {
        $text = $config['missing_template'];

        return [
            'direction' => 'flat',
            'text'      => $text,
            'sr'        => $config['missing_sr'],
            'value'     => null,
        ];
    }

    $numeric_delta = (float) $delta;
    $absolute       = abs($numeric_delta);

    if ($absolute < (float) $config['tolerance']) {
        return [
            'direction' => 'flat',
            'text'      => $config['stable_template'],
            'sr'        => $config['stable_sr'],
            'value'     => round($numeric_delta, (int) $config['precision']),
        ];
    }

    $precision = (int) $config['precision'];
    $formatted = number_format_i18n($absolute, $precision);
    $unit      = is_string($config['unit']) ? $config['unit'] : '';

    if ($unit !== '' && !preg_match('/^\s/u', $unit)) {
        $unit = ' ' . $unit;
    }

    $is_positive = $numeric_delta > 0;
    $is_improvement = $config['increase_good'] ? $is_positive : !$is_positive;
    $template = $is_improvement ? $config['positive_template'] : $config['negative_template'];
    $sr_template = $is_improvement ? $config['positive_sr'] : $config['negative_sr'];
    $direction = $is_improvement ? 'up' : 'down';

    $text = sprintf($template, $formatted, $unit);
    $sr   = sprintf($sr_template, $formatted, $unit);

    return [
        'direction' => $direction,
        'text'      => $text,
        'sr'        => $sr,
        'value'     => round($numeric_delta, $precision),
    ];
}

/**
 * Formats uptime metrics for display in the KPI grid.
 *
 * @param array<string,mixed>|null $uptime      Raw uptime metrics.
 * @param bool                     $is_active   Whether the module is active.
 * @param string                   $range_label Human-readable range label.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_uptime_card_view($uptime, $is_active, $range_label) {
    $status_meta = sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $card = [
        'label'             => __('Availability', 'sitepulse'),
        'status'            => array_merge($status_meta, ['class' => 'status-warn']),
        'value'             => ['text' => __('N/A', 'sitepulse'), 'unit' => ''],
        'summary'           => __('No uptime data collected yet.', 'sitepulse'),
        'trend'             => sitepulse_custom_dashboard_format_trend(null),
        'details'           => [],
        'description'       => __('Once checks run, uptime results will appear here.', 'sitepulse'),
        'inactive'          => !$is_active,
        'inactive_message'  => __('Activate the Uptime Tracker module to populate this metric.', 'sitepulse'),
    ];

    if ($card['inactive']) {
        return $card;
    }

    if (!is_array($uptime) || empty($uptime)) {
        return $card;
    }

    $uptime_value = isset($uptime['uptime']) ? $uptime['uptime'] : null;
    $status       = sitepulse_custom_dashboard_resolve_uptime_status($uptime_value);
    $status_meta  = sitepulse_custom_dashboard_resolve_status_meta($status);
    $status_meta['class'] = $status;

    $card['status'] = $status_meta;

    if (is_numeric($uptime_value)) {
        $card['value'] = [
            'text' => number_format_i18n((float) $uptime_value, 2),
            'unit' => '%',
        ];
    }

    $totals = isset($uptime['totals']) && is_array($uptime['totals']) ? $uptime['totals'] : [];
    $up      = isset($totals['up']) ? (int) $totals['up'] : 0;
    $down    = isset($totals['down']) ? (int) $totals['down'] : 0;
    $unknown = isset($totals['unknown']) ? (int) $totals['unknown'] : 0;
    $total   = isset($totals['total']) ? (int) $totals['total'] : ($up + $down + $unknown);

    $card['summary'] = sprintf(
        __('%1$s up · %2$s down · %3$s unknown', 'sitepulse'),
        number_format_i18n($up),
        number_format_i18n($down),
        number_format_i18n($unknown)
    );

    $latency_avg = isset($uptime['latency_avg']) && is_numeric($uptime['latency_avg'])
        ? (float) $uptime['latency_avg']
        : null;
    $ttfb_avg = isset($uptime['ttfb_avg']) && is_numeric($uptime['ttfb_avg'])
        ? (float) $uptime['ttfb_avg']
        : null;
    $violations = isset($uptime['violations']) ? (int) $uptime['violations'] : 0;

    $card['details'] = [
        [
            'label' => __('Average latency', 'sitepulse'),
            'value' => $latency_avg !== null
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n($latency_avg, 2))
                : __('N/A', 'sitepulse'),
        ],
        [
            'label' => __('Average TTFB', 'sitepulse'),
            'value' => $ttfb_avg !== null
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n($ttfb_avg, 2))
                : __('N/A', 'sitepulse'),
        ],
        [
            'label' => __('Downtime events', 'sitepulse'),
            'value' => number_format_i18n($violations),
        ],
    ];

    $card['trend'] = sitepulse_custom_dashboard_format_trend(
        isset($uptime['trend']['uptime']) ? $uptime['trend']['uptime'] : null,
        [
            'tolerance'         => 0.05,
            'precision'         => 2,
            'unit'              => __(' pts', 'sitepulse'),
            'increase_good'     => true,
            'positive_template' => __('Uptime improved by %s%s', 'sitepulse'),
            'negative_template' => __('Uptime decreased by %s%s', 'sitepulse'),
            'positive_sr'       => __('Availability improved by %s%s compared to the previous window.', 'sitepulse'),
            'negative_sr'       => __('Availability decreased by %s%s compared to the previous window.', 'sitepulse'),
        ]
    );

    if ($total > 0) {
        $card['description'] = sprintf(
            __('Based on %1$s checks over %2$s.', 'sitepulse'),
            number_format_i18n($total),
            $range_label
        );
    } else {
        $card['description'] = __('No uptime checks recorded during this window.', 'sitepulse');
    }

    return $card;
}

/**
 * Formats debug log metrics for display in the KPI grid.
 *
 * @param array<string,mixed>|null $logs      Raw log metrics.
 * @param bool                     $is_active Whether the module is active.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_log_card_view($logs, $is_active) {
    $status_meta = sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $card = [
        'label'             => __('Error log', 'sitepulse'),
        'status'            => array_merge($status_meta, ['class' => 'status-warn']),
        'value'             => ['text' => __('Unavailable', 'sitepulse'), 'unit' => ''],
        'summary'           => __('No log metrics available.', 'sitepulse'),
        'trend'             => [
            'direction' => 'flat',
            'text'      => __('Monitoring for new events.', 'sitepulse'),
            'sr'        => __('Awaiting new log activity.', 'sitepulse'),
            'value'     => null,
        ],
        'details'           => [],
        'description'       => __('Once the analyzer scans debug.log, results will appear here.', 'sitepulse'),
        'inactive'          => !$is_active,
        'inactive_message'  => __('Activate the Error Alerts module to monitor the debug log.', 'sitepulse'),
    ];

    if ($card['inactive']) {
        return $card;
    }

    if (!is_array($logs) || empty($logs)) {
        return $card;
    }

    $card_payload = isset($logs['card']) && is_array($logs['card']) ? $logs['card'] : [];
    $counts       = isset($card_payload['counts']) && is_array($card_payload['counts'])
        ? $card_payload['counts']
        : [];

    $fatal      = isset($counts['fatal']) ? (int) $counts['fatal'] : 0;
    $warning    = isset($counts['warning']) ? (int) $counts['warning'] : 0;
    $notice     = isset($counts['notice']) ? (int) $counts['notice'] : 0;
    $deprecated = isset($counts['deprecated']) ? (int) $counts['deprecated'] : 0;

    if ($fatal > 0) {
        $status = 'status-bad';
        $value_text = sprintf(
            _n('%s fatal error', '%s fatal errors', $fatal, 'sitepulse'),
            number_format_i18n($fatal)
        );
    } elseif ($warning > 0) {
        $status = 'status-warn';
        $value_text = sprintf(
            _n('%s warning', '%s warnings', $warning, 'sitepulse'),
            number_format_i18n($warning)
        );
    } elseif ($deprecated > 0) {
        $status = 'status-warn';
        $value_text = sprintf(
            _n('%s deprecated notice', '%s deprecated notices', $deprecated, 'sitepulse'),
            number_format_i18n($deprecated)
        );
    } elseif ($notice > 0) {
        $status = 'status-warn';
        $value_text = sprintf(
            _n('%s notice', '%s notices', $notice, 'sitepulse'),
            number_format_i18n($notice)
        );
    } else {
        $status = 'status-ok';
        $value_text = __('Log clean', 'sitepulse');
    }

    $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status);
    $status_meta['class'] = $status;
    $card['status'] = $status_meta;
    $card['value']  = ['text' => $value_text, 'unit' => ''];

    if (isset($card_payload['summary']) && is_string($card_payload['summary'])) {
        $card['summary'] = $card_payload['summary'];
    }

    $card['details'] = [
        ['label' => __('Fatal errors', 'sitepulse'), 'value' => number_format_i18n($fatal)],
        ['label' => __('Warnings', 'sitepulse'), 'value' => number_format_i18n($warning)],
        ['label' => __('Deprecated', 'sitepulse'), 'value' => number_format_i18n($deprecated)],
        ['label' => __('Notices', 'sitepulse'), 'value' => number_format_i18n($notice)],
    ];

    $metadata = isset($logs['metadata']) && is_array($logs['metadata']) ? $logs['metadata'] : [];

    if (!empty($metadata['truncated'])) {
        $card['details'][] = [
            'label' => __('Snapshot', 'sitepulse'),
            'value' => __('Tail of log displayed', 'sitepulse'),
        ];
    }

    $last_modified = isset($metadata['last_modified']) ? (int) $metadata['last_modified'] : 0;

    if ($last_modified > 0 && function_exists('human_time_diff')) {
        $ago = human_time_diff($last_modified, sitepulse_custom_dashboard_get_current_timestamp());
        $card['description'] = sprintf(__('Last updated %s ago.', 'sitepulse'), $ago);
    } elseif (isset($metadata['path']) && is_string($metadata['path']) && $metadata['path'] !== '') {
        $card['description'] = sprintf(__('Log file: %s', 'sitepulse'), $metadata['path']);
    }

    return $card;
}

/**
 * Formats speed metrics for display in the KPI grid.
 *
 * @param array<string,mixed>|null $speed       Raw speed metrics.
 * @param bool                     $is_active   Whether the module is active.
 * @param string                   $range_label Range label.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_speed_card_view($speed, $is_active, $range_label) {
    $status_meta = sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $card = [
        'label'             => __('Backend speed', 'sitepulse'),
        'status'            => array_merge($status_meta, ['class' => 'status-warn']),
        'value'             => ['text' => __('N/A', 'sitepulse'), 'unit' => ''],
        'summary'           => __('No scans recorded during this window.', 'sitepulse'),
        'trend'             => sitepulse_custom_dashboard_format_trend(null),
        'details'           => [],
        'description'       => __('Run a speed scan to populate this metric.', 'sitepulse'),
        'inactive'          => !$is_active,
        'inactive_message'  => __('Activate the Speed Analyzer module to track processing times.', 'sitepulse'),
    ];

    if ($card['inactive']) {
        return $card;
    }

    if (!is_array($speed) || empty($speed)) {
        return $card;
    }

    $average = isset($speed['average']) && is_numeric($speed['average']) ? (float) $speed['average'] : null;
    $latest  = isset($speed['latest']) && is_array($speed['latest']) ? $speed['latest'] : [];
    $latest_status = isset($latest['status']) ? (string) $latest['status'] : '';

    if ($latest_status === '') {
        $latest_status = sitepulse_custom_dashboard_resolve_speed_status($average, isset($speed['thresholds']) ? $speed['thresholds'] : []);
    }

    $status_meta = sitepulse_custom_dashboard_resolve_status_meta($latest_status);
    $status_meta['class'] = $latest_status;
    $card['status'] = $status_meta;

    if ($average !== null) {
        $card['value'] = [
            'text' => number_format_i18n($average, 2),
            'unit' => 'ms',
        ];
    }

    $samples = isset($speed['samples']) ? (int) $speed['samples'] : 0;

    $summary_parts = [];

    if (isset($latest['server_processing_ms']) && is_numeric($latest['server_processing_ms'])) {
        $summary_parts[] = sprintf(
            __('Latest: %s ms', 'sitepulse'),
            number_format_i18n((float) $latest['server_processing_ms'], 2)
        );
    }

    if ($samples > 0) {
        $summary_parts[] = sprintf(
            _n('%s sample', '%s samples', $samples, 'sitepulse'),
            number_format_i18n($samples)
        );
    }

    $rum_enabled_flag = isset($speed['rum_enabled']) ? (bool) $speed['rum_enabled'] : false;
    $rum_data = isset($speed['rum']) && is_array($speed['rum']) ? $speed['rum'] : null;
    $rum_detail_rows = [];

    if ($rum_enabled_flag && is_array($rum_data)) {
        $rum_samples = isset($rum_data['sample_count']) ? (int) $rum_data['sample_count'] : 0;
        $rum_summary = isset($rum_data['summary']) && is_array($rum_data['summary']) ? $rum_data['summary'] : [];
        $rum_lcp = isset($rum_summary['LCP']['p75']) ? (float) $rum_summary['LCP']['p75'] : null;
        $rum_fid = isset($rum_summary['FID']['p75']) ? (float) $rum_summary['FID']['p75'] : null;
        $rum_cls = isset($rum_summary['CLS']['p75']) ? (float) $rum_summary['CLS']['p75'] : null;

        if ($rum_samples > 0) {
            $summary_parts[] = sprintf(
                /* translators: %s: number of RUM samples. */
                _n('%s RUM sample', '%s RUM samples', $rum_samples, 'sitepulse'),
                number_format_i18n($rum_samples)
            );
        }

        $rum_detail_rows[] = [
            'label' => __('RUM LCP p75', 'sitepulse'),
            'value' => ($rum_lcp !== null)
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n($rum_lcp, 0))
                : __('N/A', 'sitepulse'),
        ];
        $rum_detail_rows[] = [
            'label' => __('RUM FID p75', 'sitepulse'),
            'value' => ($rum_fid !== null)
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n($rum_fid, 0))
                : __('N/A', 'sitepulse'),
        ];
        $rum_detail_rows[] = [
            'label' => __('RUM CLS p75', 'sitepulse'),
            'value' => ($rum_cls !== null)
                ? number_format_i18n($rum_cls, 3)
                : __('N/A', 'sitepulse'),
        ];
    } elseif ($rum_enabled_flag) {
        $rum_detail_rows[] = [
            'label' => __('RUM', 'sitepulse'),
            'value' => __('No RUM samples recorded for this period.', 'sitepulse'),
        ];
    } else {
        $rum_detail_rows[] = [
            'label' => __('RUM', 'sitepulse'),
            'value' => __('Collection disabled', 'sitepulse'),
        ];
    }

    if (!empty($summary_parts)) {
        $card['summary'] = implode(' · ', $summary_parts);
    }

    $thresholds = isset($speed['thresholds']) && is_array($speed['thresholds']) ? $speed['thresholds'] : [];

    $card['details'] = [
        [
            'label' => __('Warning threshold', 'sitepulse'),
            'value' => isset($thresholds['warning'])
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n((int) $thresholds['warning']))
                : __('N/A', 'sitepulse'),
        ],
        [
            'label' => __('Critical threshold', 'sitepulse'),
            'value' => isset($thresholds['critical'])
                ? sprintf(__('%s ms', 'sitepulse'), number_format_i18n((int) $thresholds['critical']))
                : __('N/A', 'sitepulse'),
        ],
    ];

    if (!empty($rum_detail_rows)) {
        $card['details'] = array_merge($card['details'], $rum_detail_rows);
    }

    $card['trend'] = sitepulse_custom_dashboard_format_trend(
        isset($speed['trend']) ? $speed['trend'] : null,
        [
            'tolerance'         => 0.5,
            'precision'         => 1,
            'unit'              => __(' ms', 'sitepulse'),
            'increase_good'     => false,
            'positive_template' => __('Slower by %s%s', 'sitepulse'),
            'negative_template' => __('Faster by %s%s', 'sitepulse'),
            'positive_sr'       => __('Backend processing time increased by %s%s compared to the previous window.', 'sitepulse'),
            'negative_sr'       => __('Backend processing time improved by %s%s compared to the previous window.', 'sitepulse'),
            'stable_template'   => __('Speed is stable compared to the previous window.', 'sitepulse'),
            'stable_sr'         => __('Backend processing time is stable compared to the previous window.', 'sitepulse'),
        ]
    );

    if ($samples > 0) {
        $card['description'] = sprintf(
            __('Average across %1$s samples collected during %2$s.', 'sitepulse'),
            number_format_i18n($samples),
            $range_label
        );
    }

    return $card;
}

/**
 * Formats the Real User Monitoring card for the dashboard.
 *
 * @param array<string,mixed>|null $rum          Aggregated RUM metrics.
 * @param bool                     $module_active Whether the Speed module is active.
 * @param bool                     $rum_enabled   Whether RUM collection is currently enabled.
 * @param string                   $range_label   Human readable range label.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_rum_card_view($rum, $module_active, $rum_enabled, $range_label) {
    $status_meta = sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $card = [
        'label'             => __('Real user experience', 'sitepulse'),
        'status'            => array_merge($status_meta, ['class' => 'status-warn']),
        'value'             => ['text' => __('N/A', 'sitepulse'), 'unit' => ''],
        'summary'           => __('Waiting for field data.', 'sitepulse'),
        'trend'             => sitepulse_custom_dashboard_format_trend(null),
        'details'           => [],
        'description'       => __('Activate Web Vitals collection to populate this metric.', 'sitepulse'),
        'inactive'          => !$module_active,
        'inactive_message'  => __('Activate the Speed Analyzer module to unlock RUM insights.', 'sitepulse'),
    ];

    if ($card['inactive']) {
        return $card;
    }

    if (!$rum_enabled) {
        $idle_status = sitepulse_custom_dashboard_resolve_status_meta('status-idle');
        $idle_status['class'] = 'status-idle';
        $card['status'] = $idle_status;
        $card['summary'] = __('Real user monitoring is disabled.', 'sitepulse');
        $card['description'] = __('Enable RUM collection from the Speed Analyzer settings.', 'sitepulse');

        return $card;
    }

    if (!is_array($rum)) {
        $rum = [];
    }

    $samples = isset($rum['window']['samples']) ? (int) $rum['window']['samples'] : 0;
    $metrics = isset($rum['metrics']) && is_array($rum['metrics']) ? $rum['metrics'] : [];
    $pages   = isset($rum['pages']) && is_array($rum['pages']) ? $rum['pages'] : [];

    if ($samples <= 0 || empty($metrics)) {
        $card['summary'] = __('No RUM samples collected for the selected range.', 'sitepulse');
        $card['description'] = __('Once visitors interact with the site, Web Vitals will appear here.', 'sitepulse');

        return $card;
    }

    $labels = [
        'LCP' => __('Largest Contentful Paint', 'sitepulse'),
        'FID' => __('First Input Delay', 'sitepulse'),
        'CLS' => __('Cumulative Layout Shift', 'sitepulse'),
    ];

    $primary_key = isset($metrics['LCP']) ? 'LCP' : (key($metrics) ?: 'LCP');
    $primary = isset($metrics[$primary_key]) && is_array($metrics[$primary_key]) ? $metrics[$primary_key] : [];

    $extract_value = static function ($metric_key, $metric_data, $field) {
        if (!is_array($metric_data)) {
            return null;
        }

        if (isset($metric_data[$field]) && is_numeric($metric_data[$field])) {
            return (float) $metric_data[$field];
        }

        if ($field !== 'average' && isset($metric_data['average']) && is_numeric($metric_data['average'])) {
            return (float) $metric_data['average'];
        }

        return null;
    };

    $format_value = static function ($metric_key, $value) {
        $unit = '';
        $precision = 2;

        if ($value === null) {
            return ['formatted' => __('N/A', 'sitepulse'), 'unit' => '', 'raw' => null];
        }

        if ($metric_key === 'LCP') {
            $unit = 's';
            $value = $value / 1000;
            $precision = 2;
        } elseif ($metric_key === 'FID') {
            $unit = 'ms';
            $precision = $value >= 100 ? 0 : 1;
        } elseif ($metric_key === 'CLS') {
            $unit = '';
            $precision = 3;
        }

        return [
            'formatted' => number_format_i18n($value, $precision),
            'unit'      => $unit,
            'raw'       => $value,
        ];
    };

    $primary_value = $extract_value($primary_key, $primary, 'p75');
    $value_meta = $format_value($primary_key, $primary_value);

    $ratings = isset($primary['ratings']) && is_array($primary['ratings']) ? $primary['ratings'] : [];
    $good_count  = isset($ratings['good']) ? (int) $ratings['good'] : 0;
    $ni_count    = isset($ratings['needs_improvement']) ? (int) $ratings['needs_improvement'] : 0;
    $poor_count  = isset($ratings['poor']) ? (int) $ratings['poor'] : 0;
    $rating_total = max(1, $good_count + $ni_count + $poor_count);

    $good_ratio = $rating_total > 0 ? $good_count / $rating_total : 0.0;
    $ni_ratio   = $rating_total > 0 ? $ni_count / $rating_total : 0.0;
    $poor_ratio = $rating_total > 0 ? $poor_count / $rating_total : 0.0;

    if ($poor_ratio >= 0.3) {
        $status_key = 'status-bad';
    } elseif ($good_ratio < 0.5 || $ni_ratio >= 0.3) {
        $status_key = 'status-warn';
    } else {
        $status_key = 'status-ok';
    }

    $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status_key);
    $status_meta['class'] = $status_key;
    $card['status'] = $status_meta;

    $metric_label = isset($labels[$primary_key]) ? $labels[$primary_key] : $primary_key;
    $card['value'] = [
        'text' => $value_meta['formatted'],
        'unit' => $value_meta['unit'],
    ];

    $card['summary'] = sprintf(
        __('p75 %1$s: %2$s%3$s · %4$s%% good', 'sitepulse'),
        $metric_label,
        $value_meta['formatted'],
        $value_meta['unit'] !== '' ? ' ' . $value_meta['unit'] : '',
        number_format_i18n(round($good_ratio * 100))
    );

    $card['description'] = sprintf(
        __('Based on %1$s samples collected over %2$s.', 'sitepulse'),
        number_format_i18n($samples),
        $range_label
    );

    $details = [];

    foreach (['LCP', 'FID', 'CLS'] as $metric_key) {
        if (!isset($metrics[$metric_key]) || !is_array($metrics[$metric_key])) {
            continue;
        }

        $metric_data = $metrics[$metric_key];
        $p95_value = $extract_value($metric_key, $metric_data, 'p95');
        $detail_value = $format_value($metric_key, $p95_value);
        $metric_ratings = isset($metric_data['ratings']) && is_array($metric_data['ratings']) ? $metric_data['ratings'] : [];
        $metric_good = isset($metric_ratings['good']) ? (int) $metric_ratings['good'] : 0;
        $metric_total = max(1, $metric_good + (isset($metric_ratings['needs_improvement']) ? (int) $metric_ratings['needs_improvement'] : 0) + (isset($metric_ratings['poor']) ? (int) $metric_ratings['poor'] : 0));

        $details[] = [
            'label' => isset($labels[$metric_key]) ? $labels[$metric_key] : $metric_key,
            'value' => sprintf(
                __('p95 %1$s%2$s · %3$s%% good', 'sitepulse'),
                $detail_value['formatted'],
                $detail_value['unit'] !== '' ? ' ' . $detail_value['unit'] : '',
                number_format_i18n(round(($metric_good / $metric_total) * 100))
            ),
        ];
    }

    if (!empty($pages)) {
        $top_page = $pages[0];
        $page_path = isset($top_page['path']) ? (string) $top_page['path'] : '/';
        $page_samples = isset($top_page['samples']) ? (int) $top_page['samples'] : 0;

        $details[] = [
            'label' => __('Top sampled page', 'sitepulse'),
            'value' => sprintf(
                '%1$s · %2$s',
                $page_path,
                sprintf(_n('%s sample', '%s samples', $page_samples, 'sitepulse'), number_format_i18n($page_samples))
            ),
        ];
    }

    $card['details'] = $details;

    return $card;
}
