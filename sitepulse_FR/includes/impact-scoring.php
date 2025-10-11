<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SITEPULSE_OPTION_PLUGIN_IMPACT_SCORES')) {
    define('SITEPULSE_OPTION_PLUGIN_IMPACT_SCORES', 'sitepulse_plugin_impact_scores');
}

/**
 * Returns the thresholds used to evaluate plugin impact scores.
 *
 * @return array<string,float>
 */
function sitepulse_plugin_impact_get_scoring_thresholds() {
    if (function_exists('sitepulse_get_default_plugin_impact_thresholds')) {
        $defaults = sitepulse_get_default_plugin_impact_thresholds();
    } else {
        $defaults = [
            'impactWarning'  => 30.0,
            'impactCritical' => 60.0,
            'weightWarning'  => 10.0,
            'weightCritical' => 20.0,
            'trendWarning'   => 15.0,
            'trendCritical'  => 40.0,
        ];
    }

    $thresholds = $defaults;

    if (defined('SITEPULSE_OPTION_IMPACT_THRESHOLDS')) {
        $option_value = get_option(
            SITEPULSE_OPTION_IMPACT_THRESHOLDS,
            [
                'default' => $defaults,
                'roles'   => [],
            ]
        );

        if (is_array($option_value) && isset($option_value['default']) && is_array($option_value['default'])) {
            $thresholds = $option_value['default'];
        }
    }

    if (function_exists('sitepulse_normalize_impact_threshold_set')) {
        $thresholds = sitepulse_normalize_impact_threshold_set($thresholds, $defaults);
    } else {
        if (function_exists('wp_parse_args')) {
            $thresholds = wp_parse_args(is_array($thresholds) ? $thresholds : [], $defaults);
        } else {
            $thresholds = array_merge($defaults, is_array($thresholds) ? $thresholds : []);
        }

        foreach ($thresholds as $key => $value) {
            $thresholds[$key] = is_numeric($value) ? (float) $value : (isset($defaults[$key]) ? (float) $defaults[$key] : 0.0);
        }
    }

    return $thresholds;
}

/**
 * Converts a raw metric into a severity ratio between 0 and 1 using configured thresholds.
 *
 * @param float $value        Raw metric value.
 * @param float $warning      Warning threshold.
 * @param float $critical     Critical threshold.
 * @param bool  $allow_below  Whether to scale values below the warning threshold.
 * @return float
 */
function sitepulse_plugin_impact_normalize_ratio($value, $warning, $critical, $allow_below = true) {
    $value = (float) max($value, 0.0);
    $warning = max(0.01, (float) $warning);
    $critical = max($warning + 0.01, (float) $critical);

    if ($value >= $critical) {
        return 1.0;
    }

    if ($value <= $warning) {
        if (!$allow_below) {
            return 0.0;
        }

        return min(1.0, $value / $warning);
    }

    return min(1.0, ($value - $warning) / ($critical - $warning));
}

/**
 * Calculates the weighted impact scores for the provided plugin history.
 *
 * @param array<string,array<int,array<string,float|int>>> $history Plugin history keyed by plugin file.
 * @param int|null                                         $now     Reference timestamp.
 * @return array<string,mixed>
 */
function sitepulse_plugin_impact_calculate_scores(array $history, $now = null) {
    if (null === $now) {
        $now = function_exists('current_time') ? (int) current_time('timestamp', true) : time();
    } else {
        $now = (int) $now;
    }

    $thresholds = sitepulse_plugin_impact_get_scoring_thresholds();
    $decay_window = apply_filters('sitepulse_plugin_impact_scoring_decay_window', 7 * DAY_IN_SECONDS, $history);
    $decay_window = max(1, (int) $decay_window);

    $scores = [];
    $aggregate_score = 0.0;

    foreach ($history as $plugin_file => $entries) {
        if (!is_string($plugin_file) || $plugin_file === '' || !is_array($entries) || empty($entries)) {
            continue;
        }

        $plugin_score = 0.0;
        $total_weight = 0.0;
        $latest_timestamp = 0;
        $latest_avg = null;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $avg_ms = isset($entry['avg_ms']) && is_numeric($entry['avg_ms']) ? max(0.0, (float) $entry['avg_ms']) : null;
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

            if (null === $avg_ms || $timestamp <= 0) {
                continue;
            }

            $severity_ratio = sitepulse_plugin_impact_normalize_ratio(
                $avg_ms,
                $thresholds['impactWarning'],
                $thresholds['impactCritical']
            );

            $weight_ratio = 1.0;

            if (isset($entry['weight']) && is_numeric($entry['weight'])) {
                $weight_ratio = sitepulse_plugin_impact_normalize_ratio(
                    (float) $entry['weight'],
                    $thresholds['weightWarning'],
                    $thresholds['weightCritical']
                );
            }

            $age_seconds = max(0, $now - $timestamp);
            $recency_ratio = exp(-$age_seconds / $decay_window);

            $entry_score = $severity_ratio * (0.6 + (0.4 * $weight_ratio)) * $recency_ratio * 100;

            $plugin_score += $entry_score;
            $total_weight += $recency_ratio;

            if ($timestamp > $latest_timestamp) {
                $latest_timestamp = $timestamp;
                $latest_avg = $avg_ms;
            }
        }

        if ($total_weight <= 0) {
            continue;
        }

        $normalized_score = min(100.0, $plugin_score / $total_weight);

        $scores[$plugin_file] = [
            'score'          => round($normalized_score, 2),
            'entries'        => count($entries),
            'last_sample_at' => $latest_timestamp,
            'last_avg_ms'    => null === $latest_avg ? null : round($latest_avg, 2),
        ];

        $aggregate_score += $normalized_score;
    }

    ksort($scores);

    return [
        'updated_at'    => $now,
        'decay_window'  => $decay_window,
        'thresholds'    => $thresholds,
        'plugins'       => $scores,
        'average_score' => empty($scores) ? 0.0 : round($aggregate_score / count($scores), 2),
    ];
}

/**
 * Persists the latest impact score calculations.
 *
 * @param array<string,array<int,array<string,float|int>>> $history Plugin history entries keyed by plugin file.
 * @param int|null                                         $now     Reference timestamp.
 * @return void
 */
function sitepulse_plugin_impact_persist_scores(array $history, $now = null) {
    if (!defined('SITEPULSE_OPTION_PLUGIN_IMPACT_SCORES')) {
        return;
    }

    $payload = sitepulse_plugin_impact_calculate_scores($history, $now);

    update_option(SITEPULSE_OPTION_PLUGIN_IMPACT_SCORES, $payload, false);
}
