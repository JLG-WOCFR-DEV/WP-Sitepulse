<?php
/**
 * SitePulse Resource Monitor analytics helpers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculates percentiles for a numeric dataset.
 *
 * @param array<int, float> $values Numeric values.
 * @param array<int, float> $percentiles Percentile thresholds (0-100).
 * @return array<string, float|null>
 */
function sitepulse_resource_monitor_calculate_percentiles(array $values, array $percentiles) {
    $values = array_values(array_filter($values, static function ($value) {
        return is_numeric($value);
    }));

    sort($values);

    $results = [];

    if (empty($values)) {
        foreach ($percentiles as $percentile) {
            $key = 'p' . (int) round($percentile);
            $results[$key] = null;
        }

        return $results;
    }

    $count = count($values);

    foreach ($percentiles as $percentile) {
        $percentile = max(0.0, min(100.0, (float) $percentile));
        $rank = ($percentile / 100) * ($count - 1);
        $lower_index = (int) floor($rank);
        $upper_index = (int) ceil($rank);
        $weight = $rank - $lower_index;

        $lower_value = $values[$lower_index];
        $upper_value = $values[$upper_index] ?? $lower_value;

        $interpolated = $lower_value + ($upper_value - $lower_value) * $weight;
        $key = 'p' . (int) round($percentile);

        $results[$key] = (float) $interpolated;
    }

    return $results;
}

/**
 * Calculates the trend of a metric using linear regression.
 *
 * @param array<int, array> $entries History entries.
 * @param callable          $value_callback Callback returning the metric value for an entry.
 * @return array<string, mixed>
 */
function sitepulse_resource_monitor_calculate_metric_trend(array $entries, callable $value_callback) {
    $points = [];

    foreach ($entries as $entry) {
        if (!isset($entry['timestamp'])) {
            continue;
        }

        $value = $value_callback($entry);

        if ($value === null) {
            continue;
        }

        $points[] = [
            'timestamp' => (int) $entry['timestamp'],
            'value'     => (float) $value,
        ];
    }

    $count = count($points);

    if ($count < 2) {
        return [
            'direction'      => 'flat',
            'slope_per_hour' => 0.0,
            'absolute_change'=> 0.0,
            'percent_change' => null,
            'start'          => $count === 1 ? $points[0] : null,
            'end'            => $count === 1 ? $points[0] : null,
            'sample_size'    => $count,
        ];
    }

    $origin = $points[0]['timestamp'];
    $sum_x = 0.0;
    $sum_y = 0.0;
    $sum_xy = 0.0;
    $sum_x2 = 0.0;

    foreach ($points as $point) {
        $x = ($point['timestamp'] - $origin) / 60.0; // minutes to limit floating errors.
        $y = $point['value'];

        $sum_x += $x;
        $sum_y += $y;
        $sum_xy += $x * $y;
        $sum_x2 += $x * $x;
    }

    $denominator = ($count * $sum_x2) - ($sum_x * $sum_x);
    $slope_per_minute = $denominator !== 0.0
        ? (($count * $sum_xy) - ($sum_x * $sum_y)) / $denominator
        : 0.0;

    $slope_per_hour = $slope_per_minute * 60.0;

    $first = $points[0];
    $last = $points[$count - 1];
    $absolute_change = $last['value'] - $first['value'];
    $percent_change = $first['value'] != 0.0
        ? ($absolute_change / $first['value']) * 100.0
        : null;

    $direction = 'flat';

    if ($slope_per_hour > 0.01) {
        $direction = 'up';
    } elseif ($slope_per_hour < -0.01) {
        $direction = 'down';
    }

    return [
        'direction'       => $direction,
        'slope_per_hour'  => $slope_per_hour,
        'absolute_change' => $absolute_change,
        'percent_change'  => $percent_change,
        'start'           => $first,
        'end'             => $last,
        'sample_size'     => $count,
    ];
}

/**
 * Retrieves the most recent numeric value for a given metric.
 *
 * @param array<int, array> $entries History entries.
 * @param callable          $value_callback Callback returning the metric value.
 * @return float|null Latest value or null.
 */
function sitepulse_resource_monitor_get_latest_metric_value(array $entries, callable $value_callback) {
    for ($index = count($entries) - 1; $index >= 0; $index--) {
        $value = $value_callback($entries[$index]);

        if ($value !== null) {
            return (float) $value;
        }
    }

    return null;
}

/**
 * Calculates aggregated metrics (averages, percentiles, trends) for key indicators.
 *
 * @param array<int, array> $entries History entries.
 * @return array<string, array<string, mixed>>
 */
function sitepulse_resource_monitor_calculate_aggregate_metrics(array $entries) {
    $series = [
        'load_1'         => [],
        'load_5'         => [],
        'load_15'        => [],
        'memory_percent' => [],
        'disk_used'      => [],
    ];

    foreach ($entries as $entry) {
        if (isset($entry['load'][0]) && is_numeric($entry['load'][0])) {
            $series['load_1'][] = (float) $entry['load'][0];
        }

        if (isset($entry['load'][1]) && is_numeric($entry['load'][1])) {
            $series['load_5'][] = (float) $entry['load'][1];
        }

        if (isset($entry['load'][2]) && is_numeric($entry['load'][2])) {
            $series['load_15'][] = (float) $entry['load'][2];
        }

        $memory_percent = sitepulse_resource_monitor_calculate_percentage(
            $entry['memory']['usage'] ?? null,
            $entry['memory']['limit'] ?? null
        );

        if ($memory_percent !== null) {
            $series['memory_percent'][] = (float) $memory_percent;
        }

        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage(
            $entry['disk']['free'] ?? null,
            $entry['disk']['total'] ?? null
        );

        if ($disk_percent_free !== null) {
            $series['disk_used'][] = max(0.0, min(100.0, 100.0 - $disk_percent_free));
        }
    }

    $percentile_thresholds = [50, 90, 95, 99];

    $metric_map = [
        'load_1' => function ($entry) {
            return isset($entry['load'][0]) && is_numeric($entry['load'][0]) ? (float) $entry['load'][0] : null;
        },
        'load_5' => function ($entry) {
            return isset($entry['load'][1]) && is_numeric($entry['load'][1]) ? (float) $entry['load'][1] : null;
        },
        'load_15' => function ($entry) {
            return isset($entry['load'][2]) && is_numeric($entry['load'][2]) ? (float) $entry['load'][2] : null;
        },
        'memory_percent' => function ($entry) {
            return sitepulse_resource_monitor_calculate_percentage(
                $entry['memory']['usage'] ?? null,
                $entry['memory']['limit'] ?? null
            );
        },
        'disk_used' => function ($entry) {
            $disk_percent_free = sitepulse_resource_monitor_calculate_percentage(
                $entry['disk']['free'] ?? null,
                $entry['disk']['total'] ?? null
            );

            if ($disk_percent_free === null) {
                return null;
            }

            return max(0.0, min(100.0, 100.0 - $disk_percent_free));
        },
    ];

    $results = [];

    foreach ($series as $key => $values) {
        $average = sitepulse_resource_monitor_calculate_average($values);
        $latest = sitepulse_resource_monitor_get_latest_metric_value($entries, $metric_map[$key]);
        $max = !empty($values) ? max($values) : null;
        $percentiles = sitepulse_resource_monitor_calculate_percentiles($values, $percentile_thresholds);
        $trend = sitepulse_resource_monitor_calculate_metric_trend($entries, $metric_map[$key]);

        $results[$key] = [
            'average'     => $average !== null ? (float) $average : null,
            'latest'      => $latest,
            'max'         => $max !== null ? (float) $max : null,
            'percentiles' => $percentiles,
            'trend'       => $trend,
            'samples'     => count($values),
        ];
    }

    return $results;
}

/**
 * Builds a heatmap dataset (date/hour buckets) from history entries.
 *
 * @param array<int, array> $entries History entries.
 * @return array<int, array<string, mixed>>
 */
function sitepulse_resource_monitor_build_heatmap_data(array $entries) {
    $buckets = [];

    foreach ($entries as $entry) {
        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

        if ($timestamp <= 0) {
            continue;
        }

        $day = gmdate('Y-m-d', $timestamp);
        $hour = (int) gmdate('G', $timestamp);

        if (!isset($buckets[$day])) {
            $buckets[$day] = [];
        }

        if (!isset($buckets[$day][$hour])) {
            $buckets[$day][$hour] = [
                'samples'          => 0,
                'load_1'           => [],
                'memory_percent'   => [],
                'disk_used_percent'=> [],
            ];
        }

        if (isset($entry['load'][0]) && is_numeric($entry['load'][0])) {
            $buckets[$day][$hour]['load_1'][] = (float) $entry['load'][0];
        }

        $memory_percent = sitepulse_resource_monitor_calculate_percentage(
            $entry['memory']['usage'] ?? null,
            $entry['memory']['limit'] ?? null
        );
        if ($memory_percent !== null) {
            $buckets[$day][$hour]['memory_percent'][] = (float) $memory_percent;
        }

        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage(
            $entry['disk']['free'] ?? null,
            $entry['disk']['total'] ?? null
        );
        if ($disk_percent_free !== null) {
            $buckets[$day][$hour]['disk_used_percent'][] = max(0.0, min(100.0, 100.0 - $disk_percent_free));
        }

        $buckets[$day][$hour]['samples']++;
    }

    if (empty($buckets)) {
        return [];
    }

    ksort($buckets);

    $heatmap = [];

    foreach ($buckets as $day => $hours) {
        ksort($hours);

        $hour_rows = [];

        foreach ($hours as $hour => $bucket) {
            $hour_rows[] = [
                'hour'              => (int) $hour,
                'load_1'            => !empty($bucket['load_1']) ? (float) sitepulse_resource_monitor_calculate_average($bucket['load_1']) : null,
                'memory_percent'    => !empty($bucket['memory_percent']) ? (float) sitepulse_resource_monitor_calculate_average($bucket['memory_percent']) : null,
                'disk_used_percent' => !empty($bucket['disk_used_percent']) ? (float) sitepulse_resource_monitor_calculate_average($bucket['disk_used_percent']) : null,
                'samples'           => (int) $bucket['samples'],
            ];
        }

        $heatmap[] = [
            'date'  => $day,
            'hours' => $hour_rows,
        ];
    }

    return $heatmap;
}

/**
 * Extracts drift information from the aggregated metrics.
 *
 * @param array<string, array<string, mixed>> $metrics Aggregated metrics including trend data.
 * @return array<string, array<string, mixed>>
 */
function sitepulse_resource_monitor_calculate_drift_summary(array $metrics) {
    $drift = [];

    foreach ($metrics as $key => $metric) {
        $trend = isset($metric['trend']) && is_array($metric['trend']) ? $metric['trend'] : [];

        $drift[$key] = [
            'direction'       => $trend['direction'] ?? 'flat',
            'absolute_change' => isset($trend['absolute_change']) ? (float) $trend['absolute_change'] : 0.0,
            'percent_change'  => isset($trend['percent_change']) ? (float) $trend['percent_change'] : null,
            'slope_per_hour'  => isset($trend['slope_per_hour']) ? (float) $trend['slope_per_hour'] : 0.0,
            'start'           => isset($trend['start']) ? $trend['start'] : null,
            'end'             => isset($trend['end']) ? $trend['end'] : null,
        ];
    }

    return $drift;
}

/**
 * Builds CSV and JSON exports for scheduled reports.
 *
 * @param array<string, mixed> $report Report payload (generated_at, metrics, heatmap, drift, summary).
 * @return array<string, string>
 */
function sitepulse_resource_monitor_prepare_report_exports(array $report) {
    $generated_at = isset($report['generated_at']) ? (int) $report['generated_at'] : (function_exists('current_time') ? (int) current_time('timestamp', true) : time());
    $heatmap = isset($report['heatmap']) && is_array($report['heatmap']) ? $report['heatmap'] : [];
    $drift = isset($report['drift']) && is_array($report['drift']) ? $report['drift'] : [];
    $metrics = isset($report['metrics']) && is_array($report['metrics']) ? $report['metrics'] : [];
    $summary = isset($report['summary']) && is_array($report['summary']) ? $report['summary'] : [];

    $csv_stream = fopen('php://temp', 'r+');

    if (is_resource($csv_stream)) {
        fputcsv($csv_stream, [
            __('Date', 'sitepulse'),
            __('Heure', 'sitepulse'),
            __('Charge (1 min)', 'sitepulse'),
            __('Mémoire (%)', 'sitepulse'),
            __('Disque utilisé (%)', 'sitepulse'),
            __('Échantillons', 'sitepulse'),
        ], ';');

        foreach ($heatmap as $day_bucket) {
            $date = isset($day_bucket['date']) ? (string) $day_bucket['date'] : '';
            $hours = isset($day_bucket['hours']) && is_array($day_bucket['hours']) ? $day_bucket['hours'] : [];

            foreach ($hours as $hour_row) {
                $hour_label = isset($hour_row['hour']) ? sprintf('%02d:00', (int) $hour_row['hour']) : '';
                $load_value = isset($hour_row['load_1']) && is_numeric($hour_row['load_1']) ? number_format_i18n((float) $hour_row['load_1'], 2) : '';
                $memory_value = isset($hour_row['memory_percent']) && is_numeric($hour_row['memory_percent']) ? number_format_i18n((float) $hour_row['memory_percent'], 1) : '';
                $disk_value = isset($hour_row['disk_used_percent']) && is_numeric($hour_row['disk_used_percent']) ? number_format_i18n((float) $hour_row['disk_used_percent'], 1) : '';
                $samples_value = isset($hour_row['samples']) ? (int) $hour_row['samples'] : 0;

                fputcsv($csv_stream, [$date, $hour_label, $load_value, $memory_value, $disk_value, $samples_value], ';');
            }
        }
    }

    $csv = '';

    if (is_resource($csv_stream)) {
        rewind($csv_stream);
        $csv_contents = stream_get_contents($csv_stream);
        if (is_string($csv_contents)) {
            $csv = $csv_contents;
        }
        fclose($csv_stream);
    }

    $json_payload = [
        'generated_at' => $generated_at,
        'metrics'      => $metrics,
        'heatmap'      => $heatmap,
        'drift'        => $drift,
        'summary'      => $summary,
    ];

    $json = function_exists('wp_json_encode')
        ? wp_json_encode($json_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : json_encode($json_payload, JSON_PRETTY_PRINT);

    if (!is_string($json)) {
        $json = '{}';
    }

    return [
        'csv'  => $csv,
        'json' => $json,
    ];
}

/**
 * Generates a comprehensive report payload from history entries.
 *
 * @param array<int, array> $entries History entries.
 * @return array<string, mixed>
 */
function sitepulse_resource_monitor_generate_report_payload(array $entries) {
    $generated_at = function_exists('current_time')
        ? (int) current_time('timestamp', true)
        : time();

    $metrics = sitepulse_resource_monitor_calculate_aggregate_metrics($entries);
    $heatmap = sitepulse_resource_monitor_build_heatmap_data($entries);
    $drift = sitepulse_resource_monitor_calculate_drift_summary($metrics);
    $summary = sitepulse_resource_monitor_calculate_history_summary($entries);
    $summary_text = sitepulse_resource_monitor_format_history_summary($summary);

    $samples_count = count($entries);
    $first_timestamp = $samples_count > 0 ? (int) $entries[0]['timestamp'] : null;
    $last_timestamp = $samples_count > 0 ? (int) $entries[$samples_count - 1]['timestamp'] : null;

    $report = [
        'generated_at' => $generated_at,
        'metrics'      => $metrics,
        'heatmap'      => $heatmap,
        'drift'        => $drift,
        'summary'      => $summary,
        'summary_text' => $summary_text,
        'samples'      => [
            'count'           => $samples_count,
            'first_timestamp' => $first_timestamp,
            'last_timestamp'  => $last_timestamp,
        ],
    ];

    $report['exports'] = sitepulse_resource_monitor_prepare_report_exports($report);

    return $report;
}

/**
 * Calculates a percentage based on a value and a total.
 *
 * @param int|float|null $value Usage value.
 * @param int|float|null $total Reference total.
 * @return float|null
 */
function sitepulse_resource_monitor_calculate_percentage($value, $total) {
    if (!is_numeric($value) || !is_numeric($total)) {
        return null;
    }

    $total = (float) $total;

    if ($total <= 0) {
        return null;
    }

    $percentage = ((float) $value / $total) * 100;

    return max(0.0, min(100.0, $percentage));
}

/**
 * Calculates the average of numeric values.
 *
 * @param array<int, float> $values Values to average.
 * @return float|null
 */
function sitepulse_resource_monitor_calculate_average(array $values) {
    $values = array_filter($values, static function ($value) {
        return is_numeric($value);
    });

    if (empty($values)) {
        return null;
    }

    return array_sum($values) / count($values);
}

/**
 * Calculates summary metrics for the history entries.
 *
 * @param array<int, array> $history_entries History entries.
 * @return array
 */
function sitepulse_resource_monitor_calculate_history_summary(array $history_entries) {
    $count = count($history_entries);

    if ($count === 0) {
        return [
            'count' => 0,
            'span' => 0,
            'first_timestamp' => null,
            'last_timestamp' => null,
            'average_load' => null,
            'latest_load' => null,
            'average_memory_percent' => null,
            'latest_memory_percent' => null,
            'average_disk_used_percent' => null,
            'latest_disk_used_percent' => null,
        ];
    }

    $first_timestamp = (int) $history_entries[0]['timestamp'];
    $last_timestamp = (int) $history_entries[$count - 1]['timestamp'];
    $span = max(0, $last_timestamp - $first_timestamp);

    $load_values = [];
    $memory_percentages = [];
    $disk_percentages = [];

    foreach ($history_entries as $entry) {
        if (isset($entry['load'][0]) && is_numeric($entry['load'][0])) {
            $load_values[] = (float) $entry['load'][0];
        }

        $memory_percent = sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null);
        if ($memory_percent !== null) {
            $memory_percentages[] = $memory_percent;
        }

        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage($entry['disk']['free'] ?? null, $entry['disk']['total'] ?? null);
        if ($disk_percent_free !== null) {
            $disk_percentages[] = max(0.0, min(100.0, 100.0 - $disk_percent_free));
        }
    }

    $latest_entry = $history_entries[$count - 1];
    $latest_disk_free_percent = sitepulse_resource_monitor_calculate_percentage($latest_entry['disk']['free'] ?? null, $latest_entry['disk']['total'] ?? null);
    $latest_disk_used_percent = $latest_disk_free_percent !== null ? max(0.0, min(100.0, 100.0 - $latest_disk_free_percent)) : null;

    return [
        'count' => $count,
        'span' => $span,
        'first_timestamp' => $first_timestamp,
        'last_timestamp' => $last_timestamp,
        'average_load' => sitepulse_resource_monitor_calculate_average($load_values),
        'latest_load' => isset($latest_entry['load'][0]) && is_numeric($latest_entry['load'][0]) ? (float) $latest_entry['load'][0] : null,
        'average_memory_percent' => sitepulse_resource_monitor_calculate_average($memory_percentages),
        'latest_memory_percent' => sitepulse_resource_monitor_calculate_percentage($latest_entry['memory']['usage'] ?? null, $latest_entry['memory']['limit'] ?? null),
        'average_disk_used_percent' => sitepulse_resource_monitor_calculate_average($disk_percentages),
        'latest_disk_used_percent' => $latest_disk_used_percent,
    ];
}

/**
 * Creates a localized text summary for history statistics.
 *
 * @param array $summary Summary generated by sitepulse_resource_monitor_calculate_history_summary().
 * @return string
 */
function sitepulse_resource_monitor_format_history_summary(array $summary) {
    if (empty($summary['count'])) {
        return esc_html__("Aucun historique disponible pour le moment.", 'sitepulse');
    }

    $range_label = ($summary['span'] > 0 && $summary['first_timestamp'] && $summary['last_timestamp'])
        ? human_time_diff($summary['first_timestamp'], $summary['last_timestamp'])
        : __('moins d\'une minute', 'sitepulse');

    $range_text = sprintf(
        /* translators: %s: human-readable duration. */
        __('sur %s', 'sitepulse'),
        $range_label
    );

    $sentences = [
        sprintf(
            /* translators: 1: number of entries, 2: duration description. */
            _n('%1$s relevé enregistré %2$s', '%1$s relevés enregistrés %2$s', $summary['count'], 'sitepulse'),
            number_format_i18n($summary['count']),
            $range_text
        ),
    ];

    if ($summary['average_load'] !== null) {
        $sentences[] = sprintf(
            /* translators: %s: average CPU load. */
            __('Charge moyenne (1 min) : %s', 'sitepulse'),
            number_format_i18n($summary['average_load'], 2)
        );
    }

    if ($summary['average_memory_percent'] !== null) {
        $sentences[] = sprintf(
            /* translators: %s: average memory usage percentage. */
            __('Mémoire utilisée : %s %%', 'sitepulse'),
            number_format_i18n($summary['average_memory_percent'], 1)
        );
    }

    if ($summary['average_disk_used_percent'] !== null) {
        $sentences[] = sprintf(
            /* translators: %s: average disk usage percentage. */
            __('Stockage utilisé : %s %%', 'sitepulse'),
            number_format_i18n($summary['average_disk_used_percent'], 1)
        );
    }

    return implode('. ', $sentences) . '.';
}

/**
 * Prepares history entries for JavaScript consumption.
 *
 * @param array<int, array> $history_entries Normalized history entries.
 * @return array<int, array>
 */
function sitepulse_resource_monitor_prepare_history_for_js(array $history_entries) {
    $prepared = [];

    foreach ($history_entries as $entry) {
        $memory_percent_usage = sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null);
        $disk_percent_free = sitepulse_resource_monitor_calculate_percentage($entry['disk']['free'] ?? null, $entry['disk']['total'] ?? null);
        $disk_percent_used = null;

        if ($disk_percent_free !== null) {
            $disk_percent_used = max(0, min(100, 100 - $disk_percent_free));
        }

        $source = isset($entry['source']) ? (string) $entry['source'] : 'manual';

        $prepared[] = [
            'timestamp' => (int) $entry['timestamp'],
            'source'    => $source,
            'isCron'    => ($source === 'cron'),
            'load'      => array_map(
                static function ($value) {
                    return is_numeric($value) ? (float) $value : null;
                },
                array_pad(is_array($entry['load']) ? array_values($entry['load']) : [], 3, null)
            ),
            'memory'    => [
                'usage'        => isset($entry['memory']['usage']) && is_numeric($entry['memory']['usage']) ? (int) $entry['memory']['usage'] : null,
                'limit'        => isset($entry['memory']['limit']) && is_numeric($entry['memory']['limit']) ? (int) $entry['memory']['limit'] : null,
                'percentUsage' => $memory_percent_usage,
            ],
            'disk'      => [
                'free'         => isset($entry['disk']['free']) && is_numeric($entry['disk']['free']) ? (int) $entry['disk']['free'] : null,
                'total'        => isset($entry['disk']['total']) && is_numeric($entry['disk']['total']) ? (int) $entry['disk']['total'] : null,
                'percentFree'  => $disk_percent_free,
                'percentUsed'  => $disk_percent_used,
            ],
        ];
    }

    return $prepared;
}

/**
 * Returns the configured thresholds for automatic alerts.
 *
 * @return array{cpu:int,memory:int,disk:int}
 */
function sitepulse_resource_monitor_get_threshold_configuration() {
    $defaults = [
        'cpu'    => defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT') ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT : 85,
        'memory' => defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT') ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT : 90,
        'disk'   => defined('SITEPULSE_DEFAULT_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT') ? (int) SITEPULSE_DEFAULT_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT : 85,
    ];

    $thresholds = [
        'cpu'    => (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT, $defaults['cpu']),
        'memory' => (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT, $defaults['memory']),
        'disk'   => (int) get_option(SITEPULSE_OPTION_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT, $defaults['disk']),
    ];

    foreach ($thresholds as $key => $value) {
        if (!is_numeric($value)) {
            $thresholds[$key] = $defaults[$key];
            continue;
        }

        $value = (int) $value;

        if ($value < 0) {
            $value = 0;
        }

        if ($value > 100) {
            $value = 100;
        }

        $thresholds[$key] = $value;
    }

    if (function_exists('apply_filters')) {
        $thresholds = apply_filters('sitepulse_resource_monitor_thresholds', $thresholds);
    }

    return $thresholds;
}

/**
 * Returns the number of consecutive cron snapshots required before alerting.
 *
 * @return int
 */
function sitepulse_resource_monitor_get_required_consecutive_snapshots() {
    $required = 3;

    if (function_exists('apply_filters')) {
        $required = (int) apply_filters('sitepulse_resource_monitor_required_consecutive_snapshots', $required);
    }

    if ($required < 1) {
        $required = 1;
    }

    return $required;
}

/**
 * Attempts to determine the number of CPU cores available for usage calculations.
 *
 * @return int
 */
function sitepulse_resource_monitor_get_cpu_core_count() {
    static $core_count = null;

    if ($core_count !== null) {
        return $core_count;
    }

    if (function_exists('sitepulse_error_alert_get_cpu_core_count')) {
        $detected = sitepulse_error_alert_get_cpu_core_count();
        $core_count = max(1, (int) $detected);

        return $core_count;
    }

    $core_count = 0;

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_resource_monitor_cpu_core_count', null);

        if (is_numeric($filtered) && (int) $filtered > 0) {
            $core_count = (int) $filtered;
        }
    }

    if ($core_count < 1 && function_exists('shell_exec')) {
        $disabled = explode(',', (string) ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        if (!in_array('shell_exec', $disabled, true)) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if (is_string($nproc)) {
                $nproc = (int) trim($nproc);
                if ($nproc > 0) {
                    $core_count = $nproc;
                }
            }

            if ($core_count < 1) {
                $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
                if (is_string($sysctl)) {
                    $sysctl = (int) trim($sysctl);
                    if ($sysctl > 0) {
                        $core_count = $sysctl;
                    }
                }
            }
        }
    }

    if ($core_count < 1) {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo) && $cpuinfo !== '') {
            if (preg_match_all('/^processor\s*:/m', $cpuinfo, $matches)) {
                $count = count($matches[0]);
                if ($count > 0) {
                    $core_count = $count;
                }
            }
        }
    }

    if ($core_count < 1 && function_exists('getenv')) {
        $env_cores = getenv('NUMBER_OF_PROCESSORS');
        if ($env_cores !== false && is_numeric($env_cores) && (int) $env_cores > 0) {
            $core_count = (int) $env_cores;
        }
    }

    if ($core_count < 1) {
        $core_count = 1;
    }

    if (function_exists('apply_filters')) {
        $core_count = (int) apply_filters('sitepulse_resource_monitor_detected_cpu_core_count', $core_count);
    }

    if ($core_count < 1) {
        $core_count = 1;
    }

    return $core_count;
}

/**
 * Calculates the CPU usage percentage based on a history entry.
 *
 * @param array $entry History entry.
 * @return float|null
 */
function sitepulse_resource_monitor_calculate_cpu_usage_percent(array $entry) {
    if (!isset($entry['load']) || !is_array($entry['load'])) {
        return null;
    }

    $load = array_values($entry['load']);

    if (!isset($load[0]) || !is_numeric($load[0])) {
        return null;
    }

    $core_count = sitepulse_resource_monitor_get_cpu_core_count();

    if ($core_count < 1) {
        $core_count = 1;
    }

    return ((float) $load[0] / $core_count) * 100;
}

/**
 * Calculates the disk usage percentage based on a history entry.
 *
 * @param array $entry History entry.
 * @return float|null
 */
function sitepulse_resource_monitor_calculate_disk_usage_percent(array $entry) {
    $percent_free = sitepulse_resource_monitor_calculate_percentage($entry['disk']['free'] ?? null, $entry['disk']['total'] ?? null);

    if ($percent_free === null) {
        return null;
    }

    return max(0, min(100, 100 - $percent_free));
}

/**
 * Evaluates the recent cron history and triggers alerts if thresholds are exceeded.
 *
 * @param array<int, array> $history_entries Normalised history entries.
 * @param array             $thresholds      Threshold configuration.
 * @param array             $snapshot        Latest snapshot data.
 * @return void
 */
function sitepulse_resource_monitor_check_thresholds(array $history_entries, array $thresholds, array $snapshot) {
    if (empty($history_entries)) {
        return;
    }

    $required = sitepulse_resource_monitor_get_required_consecutive_snapshots();

    $latest_entry = $history_entries[count($history_entries) - 1];
    $latest_cpu_percent = sitepulse_resource_monitor_calculate_cpu_usage_percent($latest_entry);
    $latest_memory_percent = sitepulse_resource_monitor_calculate_percentage($latest_entry['memory']['usage'] ?? null, $latest_entry['memory']['limit'] ?? null);
    $latest_disk_percent = sitepulse_resource_monitor_calculate_disk_usage_percent($latest_entry);

    $cpu_streak = 0;
    $memory_streak = 0;
    $disk_streak = 0;
    $checked = 0;

    for ($index = count($history_entries) - 1; $index >= 0; $index--) {
        $entry = $history_entries[$index];

        if (!is_array($entry) || ($entry['source'] ?? 'manual') !== 'cron') {
            break;
        }

        $checked++;

        $cpu_percent = sitepulse_resource_monitor_calculate_cpu_usage_percent($entry);
        if (!empty($thresholds['cpu']) && $cpu_percent !== null && $cpu_percent >= $thresholds['cpu']) {
            $cpu_streak++;
        } else {
            $cpu_streak = 0;
        }

        $memory_percent = sitepulse_resource_monitor_calculate_percentage($entry['memory']['usage'] ?? null, $entry['memory']['limit'] ?? null);
        if (!empty($thresholds['memory']) && $memory_percent !== null && $memory_percent >= $thresholds['memory']) {
            $memory_streak++;
        } else {
            $memory_streak = 0;
        }

        $disk_percent_used = sitepulse_resource_monitor_calculate_disk_usage_percent($entry);
        if (!empty($thresholds['disk']) && $disk_percent_used !== null && $disk_percent_used >= $thresholds['disk']) {
            $disk_streak++;
        } else {
            $disk_streak = 0;
        }

        if ($checked >= $required) {
            break;
        }
    }

    if ($checked < $required) {
        return;
    }

    if (!empty($thresholds['cpu']) && $cpu_streak >= $required && $latest_cpu_percent !== null) {
        sitepulse_resource_monitor_dispatch_threshold_alert('cpu', $thresholds['cpu'], $latest_cpu_percent, $required, $snapshot);
    }

    if (!empty($thresholds['memory']) && $memory_streak >= $required && $latest_memory_percent !== null) {
        sitepulse_resource_monitor_dispatch_threshold_alert('memory', $thresholds['memory'], $latest_memory_percent, $required, $snapshot);
    }

    if (!empty($thresholds['disk']) && $disk_streak >= $required && $latest_disk_percent !== null) {
        sitepulse_resource_monitor_dispatch_threshold_alert('disk', $thresholds['disk'], $latest_disk_percent, $required, $snapshot);
    }

    if (function_exists('sitepulse_http_monitor_check_thresholds')) {
        sitepulse_http_monitor_check_thresholds();
    }
}

/**
 * Sends an alert via the configured channels and fires integration hooks.
 *
 * @param string $metric           Metric identifier (cpu, memory, disk).
 * @param int    $threshold        Configured threshold percentage.
 * @param float  $current_percent  Latest measured percentage.
 * @param int    $streak           Number of consecutive snapshots considered.
 * @param array  $snapshot         Snapshot payload captured by the cron.
 * @return void
 */
function sitepulse_resource_monitor_dispatch_threshold_alert($metric, $threshold, $current_percent, $streak, array $snapshot) {
    $metric_labels = [
        'cpu'    => __('charge CPU', 'sitepulse'),
        'memory' => __('mémoire utilisée', 'sitepulse'),
        'disk'   => __('stockage utilisé', 'sitepulse'),
    ];

    $metric_key = isset($metric_labels[$metric]) ? $metric_labels[$metric] : $metric;

    $site_name = get_bloginfo('name');
    $site_name = trim(wp_strip_all_tags((string) $site_name));

    if ($site_name === '') {
        $site_name = home_url('/');
    }

    $subject = sprintf(
        __('SitePulse : %1$s au-delà du seuil sur %2$s', 'sitepulse'),
        $metric_key,
        $site_name
    );

    $formatted_percent = number_format_i18n((float) $current_percent, 1);
    $message = sprintf(
        esc_html__('Les %1$d derniers relevés automatiques affichent %2$s ≥ %3$d %% (dernier relevé : %4$s %%).', 'sitepulse'),
        (int) $streak,
        $metric_key,
        (int) $threshold,
        $formatted_percent
    );

    $extra = [
        'metric'          => $metric,
        'threshold'       => (int) $threshold,
        'current_percent' => (float) $current_percent,
        'streak'          => (int) $streak,
        'snapshot'        => $snapshot,
    ];

    if (function_exists('sitepulse_error_alert_send')) {
        sitepulse_error_alert_send('resource_monitor_' . $metric, $subject, $message, 'warning', $extra);
    }

    if (function_exists('do_action')) {
        do_action('sitepulse_resource_monitor_threshold_exceeded', $metric, $threshold, $current_percent, $streak, $snapshot, $extra);
    }
}
