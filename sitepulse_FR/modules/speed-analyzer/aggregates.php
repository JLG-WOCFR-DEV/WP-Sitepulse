<?php
/**
 * SitePulse Speed Analyzer aggregates and recommendations.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Determines the status badge class for a metric value.
 *
 * @param float|null $value      Metric value.
 * @param array      $thresholds Warning and critical thresholds.
 *
 * @return string
 */
function sitepulse_speed_analyzer_resolve_status($value, $thresholds) {
    if (!is_array($thresholds)) {
        $thresholds = [];
    }

    if (!is_numeric($value)) {
        return 'status-warn';
    }

    $warning = isset($thresholds['warning']) ? (int) $thresholds['warning'] : 0;
    $critical = isset($thresholds['critical']) ? (int) $thresholds['critical'] : 0;

    if ($critical > 0 && $value >= $critical) {
        return 'status-bad';
    }

    if ($warning > 0 && $value >= $warning) {
        return 'status-warn';
    }

    return 'status-ok';
}

/**
 * Calculates a percentile using linear interpolation.
 *
 * @param float[] $values     Sorted numeric values.
 * @param float   $percentile Percentile between 0 and 100.
 *
 * @return float|null
 */
function sitepulse_speed_analyzer_calculate_percentile($values, $percentile) {
    if (empty($values)) {
        return null;
    }

    $count = count($values);

    if ($count === 1) {
        return (float) $values[0];
    }

    $percentile = max(0.0, min(100.0, (float) $percentile));
    $index = ($percentile / 100) * ($count - 1);
    $lower = (int) floor($index);
    $upper = (int) ceil($index);

    if ($lower === $upper) {
        return (float) $values[$lower];
    }

    $fraction = $index - $lower;
    $lower_value = (float) $values[$lower];
    $upper_value = (float) $values[$upper];

    return $lower_value + ($upper_value - $lower_value) * $fraction;
}

/**
 * Filters out upper outliers using the interquartile range rule.
 *
 * @param float[] $values Sorted numeric values.
 *
 * @return float[]
 */
function sitepulse_speed_analyzer_filter_outliers($values) {
    $count = count($values);

    if ($count < 4) {
        return $values;
    }

    $q1 = sitepulse_speed_analyzer_calculate_percentile($values, 25);
    $q3 = sitepulse_speed_analyzer_calculate_percentile($values, 75);

    if ($q1 === null || $q3 === null) {
        return $values;
    }

    $iqr = $q3 - $q1;

    if ($iqr <= 0) {
        return $values;
    }

    $upper_bound = $q3 + (1.5 * $iqr);
    $lower_bound = max(0.0, $q1 - (1.5 * $iqr));

    $filtered = array_values(array_filter(
        $values,
        static function ($value) use ($lower_bound, $upper_bound) {
            return $value >= $lower_bound && $value <= $upper_bound;
        }
    ));

    return empty($filtered) ? $values : $filtered;
}

/**
 * Provides the summary metric labels and descriptions.
 *
 * @return array<string,array{label:string,description:string}>
 */
function sitepulse_speed_analyzer_get_summary_meta() {
    return [
        'mean'   => [
            'label'       => __('Moyenne', 'sitepulse'),
            'description' => __('Temps moyen observé sur l’ensemble des relevés.', 'sitepulse'),
        ],
        'median' => [
            'label'       => __('Médiane', 'sitepulse'),
            'description' => __('Valeur centrale qui limite l’impact des variations ponctuelles.', 'sitepulse'),
        ],
        'p95'    => [
            'label'       => __('95e percentile', 'sitepulse'),
            'description' => __('Niveau en dessous duquel se trouvent 95% des mesures.', 'sitepulse'),
        ],
        'best'   => [
            'label'       => __('Meilleure mesure', 'sitepulse'),
            'description' => __('Temps de réponse le plus rapide observé.', 'sitepulse'),
        ],
        'worst'  => [
            'label'       => __('Pire mesure', 'sitepulse'),
            'description' => __('Temps de réponse le plus lent enregistré.', 'sitepulse'),
        ],
    ];
}

/**
 * Builds the textual summary note for the aggregate metrics section.
 *
 * @param array{count?:int,excluded_outliers?:int}|null $aggregates Aggregate metrics.
 *
 * @return string
 */
function sitepulse_speed_analyzer_build_summary_note($aggregates) {
    if (!is_array($aggregates)) {
        return '';
    }

    $summary_note_parts = [];

    if (!empty($aggregates['count'])) {
        $summary_note_parts[] = sprintf(
            _n('Basé sur %d mesure.', 'Basé sur %d mesures.', (int) $aggregates['count'], 'sitepulse'),
            (int) $aggregates['count']
        );
    }

    if (!empty($aggregates['excluded_outliers'])) {
        $summary_note_parts[] = sprintf(
            _n(
                '%d mesure extrême ignorée lors du calcul des moyennes.',
                '%d mesures extrêmes ignorées lors du calcul des moyennes.',
                (int) $aggregates['excluded_outliers'],
                'sitepulse'
            ),
            (int) $aggregates['excluded_outliers']
        );
    }

    return trim(implode(' ', $summary_note_parts));
}

/**
 * Calculates aggregated statistics over the history.
 *
 * @param array<int,array{timestamp:int,server_processing_ms:float}>|null $history    History entries.
 * @param array{warning:int,critical:int}|null                            $thresholds Threshold configuration.
 *
 * @return array{
 *     count:int,
 *     filtered_count:int,
 *     excluded_outliers:int,
 *     metrics:array<string,array{value:float|null,status:string}>
 * }
 */
function sitepulse_speed_analyzer_get_aggregates($history = null, $thresholds = null) {
    if ($history === null) {
        $history = sitepulse_speed_analyzer_get_history_data();
    }

    if ($thresholds === null) {
        $thresholds = sitepulse_speed_analyzer_get_thresholds();
    }

    $values = [];

    if (is_array($history)) {
        foreach ($history as $entry) {
            if (!is_array($entry) || !isset($entry['server_processing_ms'])) {
                continue;
            }

            if (isset($entry['source_type']) && sanitize_key($entry['source_type']) !== 'site') {
                continue;
            }

            $value = (float) $entry['server_processing_ms'];

            if (!is_finite($value) || $value < 0) {
                continue;
            }

            $values[] = $value;
        }
    }

    sort($values);

    $count = count($values);

    if ($count === 0) {
        return [
            'count'            => 0,
            'filtered_count'   => 0,
            'excluded_outliers'=> 0,
            'metrics'          => [
                'mean'   => ['value' => null, 'status' => 'status-warn'],
                'median' => ['value' => null, 'status' => 'status-warn'],
                'p95'    => ['value' => null, 'status' => 'status-warn'],
                'best'   => ['value' => null, 'status' => 'status-warn'],
                'worst'  => ['value' => null, 'status' => 'status-warn'],
            ],
        ];
    }

    $filtered_values = sitepulse_speed_analyzer_filter_outliers($values);
    $filtered_count = count($filtered_values);

    if ($filtered_count === 0) {
        $filtered_values = $values;
        $filtered_count = $count;
    }

    $mean = $filtered_count > 0 ? array_sum($filtered_values) / $filtered_count : null;
    $median = sitepulse_speed_analyzer_calculate_percentile($filtered_values, 50);
    $p95 = sitepulse_speed_analyzer_calculate_percentile($values, 95);
    $best = min($values);
    $worst = max($values);

    return [
        'count'            => $count,
        'filtered_count'   => $filtered_count,
        'excluded_outliers'=> max(0, $count - $filtered_count),
        'metrics'          => [
            'mean'   => [
                'value'  => $mean,
                'status' => sitepulse_speed_analyzer_resolve_status($mean, $thresholds),
            ],
            'median' => [
                'value'  => $median,
                'status' => sitepulse_speed_analyzer_resolve_status($median, $thresholds),
            ],
            'p95'    => [
                'value'  => $p95,
                'status' => sitepulse_speed_analyzer_resolve_status($p95, $thresholds),
            ],
            'best'   => [
                'value'  => $best,
                'status' => sitepulse_speed_analyzer_resolve_status($best, $thresholds),
            ],
            'worst'  => [
                'value'  => $worst,
                'status' => sitepulse_speed_analyzer_resolve_status($worst, $thresholds),
            ],
        ],
    ];
}

/**
 * Returns the recorded speed history in a normalized format.
 *
 * @return array<int,array{timestamp:int,server_processing_ms:float}>
 */
function sitepulse_speed_analyzer_get_history_data() {
    $history = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

    if (!is_array($history)) {
        return [];
    }

    $normalized = array_values(array_filter(
        array_map(
            static function ($entry) {
                if (!is_array($entry)) {
                    return null;
                }

                if (!isset($entry['timestamp'], $entry['server_processing_ms'])) {
                    return null;
                }

                if (!is_numeric($entry['timestamp']) || !is_numeric($entry['server_processing_ms'])) {
                    return null;
                }

                return [
                    'timestamp'            => max(0, (int) $entry['timestamp']),
                    'server_processing_ms' => max(0.0, (float) $entry['server_processing_ms']),
                ];
            },
            $history
        ),
        static function ($entry) {
            return is_array($entry);
        }
    ));

    usort(
        $normalized,
        static function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        }
    );

    return $normalized;
}

/**
 * Retrieves the latest entry from the history array.
 *
 * @param array<int,array{timestamp:int,server_processing_ms:float}> $history History entries.
 *
 * @return array{timestamp:int,server_processing_ms:float}|null
 */
function sitepulse_speed_analyzer_get_latest_entry($history) {
    if (empty($history) || !is_array($history)) {
        return null;
    }

    $last_index = count($history) - 1;

    if (!isset($history[$last_index]) || !is_array($history[$last_index])) {
        return null;
    }

    return $history[$last_index];
}

/**
 * Generates textual recommendations based on the latest measurement.
 *
 * @param array{timestamp:int,server_processing_ms:float}|null $latest_entry Latest history entry.
 * @param array{warning:int,critical:int}                       $thresholds   Threshold configuration.
 *
 * @return string[]
 */
function sitepulse_speed_analyzer_build_recommendations($latest_entry, $thresholds) {
    $messages = [];

    if (empty($latest_entry)) {
        $messages[] = esc_html__("Nous attendons encore suffisamment de données pour formuler des recommandations. Relancez un test pour commencer l'historique.", 'sitepulse');

        return $messages;
    }

    $duration = isset($latest_entry['server_processing_ms']) ? (float) $latest_entry['server_processing_ms'] : 0.0;
    $warning = isset($thresholds['warning']) ? (int) $thresholds['warning'] : 0;
    $critical = isset($thresholds['critical']) ? (int) $thresholds['critical'] : 0;

    if ($duration >= $critical) {
        $messages[] = esc_html__("Les temps de réponse du serveur sont critiques. Contactez votre hébergeur et désactivez temporairement les extensions lourdes pour identifier le goulot d'étranglement.", 'sitepulse');
    } elseif ($duration >= $warning) {
        $messages[] = esc_html__("Vos performances se dégradent. Vérifiez les dernières extensions installées, optimisez la base de données et activez un cache persistant si possible.", 'sitepulse');
    } else {
        $messages[] = esc_html__("Le serveur répond correctement. Continuez à surveiller l'historique pour repérer les écarts ou planifiez des tests réguliers après les mises à jour.", 'sitepulse');
    }

    if ($duration >= $warning) {
        $messages[] = esc_html__("Pensez à réduire les tâches cron simultanées et à surveiller l'utilisation CPU côté hébergeur pendant les pics.", 'sitepulse');
    } else {
        $messages[] = esc_html__("Aucune action urgente n'est requise, mais gardez un œil sur l'évolution après des déploiements importants.", 'sitepulse');
    }

    return $messages;
}
