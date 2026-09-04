<?php
/**
 * SitePulse dashboard metric calculations.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Calculates uptime metrics for the selected window.
 *
 * @param string               $range  Range identifier.
 * @param array<string,mixed>  $config Range configuration.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_calculate_uptime_metrics($range, $config) {
    $days = isset($config['days']) ? (int) $config['days'] : 0;

    if ($days < 1) {
        $days = 1;
    }

    $option_key = defined('SITEPULSE_OPTION_UPTIME_ARCHIVE')
        ? SITEPULSE_OPTION_UPTIME_ARCHIVE
        : 'sitepulse_uptime_archive';

    $archive = get_option($option_key, []);

    if (!is_array($archive)) {
        $archive = [];
    }

    $current_metrics = function_exists('sitepulse_calculate_uptime_window_metrics')
        ? sitepulse_calculate_uptime_window_metrics($archive, $days)
        : sitepulse_custom_dashboard_calculate_uptime_window_metrics($archive, $days);

    $previous_metrics = [];

    if ($days > 0 && count($archive) > $days) {
        $previous_archive = array_slice($archive, 0, count($archive) - $days, true);
        $previous_metrics = function_exists('sitepulse_calculate_uptime_window_metrics')
            ? sitepulse_calculate_uptime_window_metrics($previous_archive, $days)
            : sitepulse_custom_dashboard_calculate_uptime_window_metrics($previous_archive, $days);
    }

    if (!is_array($previous_metrics)) {
        $previous_metrics = [];
    }

    $uptime_value   = isset($current_metrics['uptime']) ? (float) $current_metrics['uptime'] : null;
    $latency_avg    = isset($current_metrics['latency_avg']) ? $current_metrics['latency_avg'] : null;
    $ttfb_avg       = isset($current_metrics['ttfb_avg']) ? $current_metrics['ttfb_avg'] : null;
    $violation_count = isset($current_metrics['violations']) ? (int) $current_metrics['violations'] : 0;

    return [
        'range'  => $range,
        'days'   => isset($current_metrics['days']) ? (int) $current_metrics['days'] : 0,
        'totals' => [
            'total'   => isset($current_metrics['total_checks']) ? (int) $current_metrics['total_checks'] : 0,
            'up'      => isset($current_metrics['up_checks']) ? (int) $current_metrics['up_checks'] : 0,
            'down'    => isset($current_metrics['down_checks']) ? (int) $current_metrics['down_checks'] : 0,
            'unknown' => isset($current_metrics['unknown_checks']) ? (int) $current_metrics['unknown_checks'] : 0,
        ],
        'uptime'       => $uptime_value !== null ? round($uptime_value, 4) : null,
        'latency_avg'  => ($latency_avg !== null && is_numeric($latency_avg)) ? round((float) $latency_avg, 2) : null,
        'ttfb_avg'     => ($ttfb_avg !== null && is_numeric($ttfb_avg)) ? round((float) $ttfb_avg, 2) : null,
        'violations'   => $violation_count,
        'trend'        => [
            'uptime'      => sitepulse_custom_dashboard_calculate_trend($uptime_value, $previous_metrics['uptime'] ?? null, 4),
            'latency_avg' => sitepulse_custom_dashboard_calculate_trend($latency_avg, $previous_metrics['latency_avg'] ?? null, 2),
            'ttfb_avg'    => sitepulse_custom_dashboard_calculate_trend($ttfb_avg, $previous_metrics['ttfb_avg'] ?? null, 2),
            'violations'  => sitepulse_custom_dashboard_calculate_trend($violation_count, $previous_metrics['violations'] ?? null, 0),
        ],
    ];
}

/**
 * Fallback calculation for uptime metrics when the Uptime module is unavailable.
 *
 * @param array<int|string,mixed> $archive Archive entries.
 * @param int                     $days    Window size in days.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_calculate_uptime_window_metrics($archive, $days) {
    if (!is_array($archive) || empty($archive) || $days < 1) {
        return [
            'days'           => 0,
            'total_checks'   => 0,
            'up_checks'      => 0,
            'down_checks'    => 0,
            'unknown_checks' => 0,
            'uptime'         => 100.0,
            'latency_sum'    => 0.0,
            'latency_count'  => 0,
            'latency_avg'    => null,
            'ttfb_sum'       => 0.0,
            'ttfb_count'     => 0,
            'ttfb_avg'       => null,
            'violations'     => 0,
        ];
    }

    $window = array_slice($archive, -$days, null, true);

    $totals = [
        'days'           => count($window),
        'total_checks'   => 0,
        'up_checks'      => 0,
        'down_checks'    => 0,
        'unknown_checks' => 0,
        'uptime'         => 100.0,
        'latency_sum'    => 0.0,
        'latency_count'  => 0,
        'latency_avg'    => null,
        'ttfb_sum'       => 0.0,
        'ttfb_count'     => 0,
        'ttfb_avg'       => null,
        'violations'     => 0,
    ];

    foreach ($window as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $day_total   = isset($entry['total']) ? (int) $entry['total'] : 0;
        $maintenance = isset($entry['maintenance']) ? (int) $entry['maintenance'] : 0;
        $effective   = max(0, $day_total - $maintenance);

        $totals['total_checks']   += $effective;
        $totals['up_checks']      += isset($entry['up']) ? (int) $entry['up'] : 0;
        $totals['down_checks']    += isset($entry['down']) ? (int) $entry['down'] : 0;
        $totals['unknown_checks'] += isset($entry['unknown']) ? (int) $entry['unknown'] : 0;
        $totals['latency_sum']    += isset($entry['latency_sum']) ? (float) $entry['latency_sum'] : 0.0;
        $totals['latency_count']  += isset($entry['latency_count']) ? (int) $entry['latency_count'] : 0;
        $totals['ttfb_sum']       += isset($entry['ttfb_sum']) ? (float) $entry['ttfb_sum'] : 0.0;
        $totals['ttfb_count']     += isset($entry['ttfb_count']) ? (int) $entry['ttfb_count'] : 0;
        $totals['violations']     += isset($entry['violations']) ? (int) $entry['violations'] : 0;
    }

    if ($totals['total_checks'] > 0) {
        $totals['uptime'] = ($totals['up_checks'] / $totals['total_checks']) * 100;
    }

    if ($totals['latency_count'] > 0) {
        $totals['latency_avg'] = $totals['latency_sum'] / $totals['latency_count'];
    }

    if ($totals['ttfb_count'] > 0) {
        $totals['ttfb_avg'] = $totals['ttfb_sum'] / $totals['ttfb_count'];
    }

    return $totals;
}

/**
 * Computes a numeric trend between the current and previous values.
 *
 * @param mixed $current   Current measurement.
 * @param mixed $previous  Previous measurement.
 * @param int   $precision Number of decimals to keep. Zero forces an integer delta.
 * @return float|int|null
 */
function sitepulse_custom_dashboard_calculate_trend($current, $previous, $precision = 2) {
    if (!is_numeric($current) || !is_numeric($previous)) {
        return null;
    }

    $delta = (float) $current - (float) $previous;

    if ($precision <= 0) {
        return (int) round($delta);
    }

    return round($delta, $precision);
}

/**
 * Summarises AI insight entries within a time window.
 *
 * @param array<int,array<string,mixed>> $entries        History entries.
 * @param int                            $window_seconds Window size in seconds.
 * @param int                            $now            Reference timestamp.
 * @return array<string,int>
 */
function sitepulse_custom_dashboard_summarize_ai_entries($entries, $window_seconds, $now) {
    $summary = [
        'recent_total'        => 0,
        'recent_pending'      => 0,
        'recent_acknowledged' => 0,
        'stale_pending'       => 0,
        'latest_timestamp'    => 0,
    ];

    if (!is_array($entries) || empty($entries)) {
        return $summary;
    }

    $window_seconds = max(0, (int) $window_seconds);
    $now            = (int) $now;
    $window_start   = $window_seconds > 0 ? $now - $window_seconds : 0;

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $note      = isset($entry['note']) ? trim((string) $entry['note']) : '';
        $has_note  = $note !== '';

        if ($timestamp > $summary['latest_timestamp']) {
            $summary['latest_timestamp'] = $timestamp;
        }

        if ($window_seconds > 0 && $timestamp >= $window_start) {
            $summary['recent_total']++;

            if ($has_note) {
                $summary['recent_acknowledged']++;
            } else {
                $summary['recent_pending']++;
            }

            continue;
        }

        if (!$has_note && $timestamp > 0) {
            $summary['stale_pending']++;
        }
    }

    return $summary;
}

/**
 * Collects AI insight statistics for the provided window.
 *
 * @param int $window_seconds Window length in seconds.
 * @param int $now            Reference timestamp.
 * @return array<string,int>
 */
function sitepulse_custom_dashboard_collect_ai_window_stats($window_seconds, $now) {
    $entries = [];

    if (function_exists('sitepulse_ai_get_history_entries')) {
        $entries = sitepulse_ai_get_history_entries();

        if (!is_array($entries)) {
            $entries = [];
        }
    }

    return sitepulse_custom_dashboard_summarize_ai_entries($entries, $window_seconds, $now);
}

/**
 * Normalizes an impact snapshot for persistence.
 *
 * @param array<string,mixed> $impact Raw impact snapshot.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_normalize_impact_index($impact) {
    $now = sitepulse_custom_dashboard_get_current_timestamp();

    $normalized = [
        'range'           => isset($impact['range']) ? sanitize_key((string) $impact['range']) : '',
        'updated_at'      => isset($impact['updated_at']) ? (int) $impact['updated_at'] : $now,
        'overall'         => null,
        'health'          => null,
        'dominant_module' => isset($impact['dominant_module']) ? sanitize_key((string) $impact['dominant_module']) : '',
        'modules'         => [],
    ];

    if (isset($impact['overall']) && is_numeric($impact['overall'])) {
        $normalized['overall'] = round((float) $impact['overall'], 2);
        $normalized['health']  = round(max(0.0, min(100.0, 100.0 - (float) $impact['overall'])), 2);
    }

    if (isset($impact['health']) && is_numeric($impact['health'])) {
        $normalized['health'] = round(max(0.0, min(100.0, (float) $impact['health'])), 2);
    }

    if (isset($impact['modules']) && is_array($impact['modules'])) {
        foreach ($impact['modules'] as $module_key => $module_data) {
            $module_id = sanitize_key((string) $module_key);

            if ($module_id === '') {
                continue;
            }

            $module_normalized = [
                'label'  => isset($module_data['label']) ? sanitize_text_field((string) $module_data['label']) : $module_id,
                'status' => isset($module_data['status']) ? sanitize_key((string) $module_data['status']) : 'status-warn',
                'active' => !empty($module_data['active']),
                'score'  => null,
            ];

            if (isset($module_data['score']) && is_numeric($module_data['score'])) {
                $module_normalized['score'] = round((float) $module_data['score'], 2);
            }

            if (isset($module_data['signal'])) {
                $module_normalized['signal'] = sanitize_text_field((string) $module_data['signal']);
            }

            if (isset($module_data['details']) && is_array($module_data['details'])) {
                $module_normalized['details'] = [];

                foreach ($module_data['details'] as $detail) {
                    if (!is_array($detail)) {
                        continue;
                    }

                    $detail_label = isset($detail['label']) ? sanitize_text_field((string) $detail['label']) : '';
                    $detail_value = isset($detail['value']) ? sanitize_text_field((string) $detail['value']) : '';

                    if ($detail_label === '' && $detail_value === '') {
                        continue;
                    }

                    $module_normalized['details'][] = [
                        'label' => $detail_label,
                        'value' => $detail_value,
                    ];
                }
            }

            $normalized['modules'][$module_id] = $module_normalized;
        }
    }

    return $normalized;
}

/**
 * Stores the latest impact snapshot.
 *
 * @param array<string,mixed> $impact Impact payload.
 * @return void
 */
function sitepulse_custom_dashboard_store_impact_index($impact) {
    if (!defined('SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX') || !function_exists('update_option')) {
        return;
    }

    $normalized = sitepulse_custom_dashboard_normalize_impact_index($impact);

    $payload = [
        'range'      => $normalized['range'],
        'updated_at' => $normalized['updated_at'],
        'impact'     => $normalized,
    ];

    update_option(SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX, $payload, false);
}

/**
 * Retrieves a cached impact snapshot when available.
 *
 * @param string $range   Requested range identifier.
 * @param int    $max_age Maximum age in seconds before the cache is considered stale.
 * @return array<string,mixed>|null
 */
function sitepulse_custom_dashboard_get_cached_impact_index($range, $max_age = 900) {
    if (!defined('SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX') || !function_exists('get_option')) {
        return null;
    }

    $stored = get_option(SITEPULSE_OPTION_DASHBOARD_IMPACT_INDEX, []);

    if (!is_array($stored) || empty($stored['impact']) || !is_array($stored['impact'])) {
        return null;
    }

    $impact = $stored['impact'];
    $requested_range = sanitize_key((string) $range);
    $impact_range    = isset($impact['range']) ? sanitize_key((string) $impact['range']) : '';

    if ($requested_range !== '' && $impact_range !== '' && $impact_range !== $requested_range) {
        return null;
    }

    $updated_at = isset($impact['updated_at']) ? (int) $impact['updated_at'] : (isset($stored['updated_at']) ? (int) $stored['updated_at'] : 0);

    if ($max_age > 0 && $updated_at > 0) {
        $now = sitepulse_custom_dashboard_get_current_timestamp();

        if (($now - $updated_at) > $max_age) {
            return null;
        }
    }

    if (!isset($impact['modules']) || !is_array($impact['modules'])) {
        return null;
    }

    return $impact;
}

/**
 * Calculates the transverse impact index using module metrics.
 *
 * @param string               $range          Range identifier.
 * @param array<string,mixed>  $config         Range configuration.
 * @param array<string,bool>   $modules_status Module activation map.
 * @param array<string,mixed>  $uptime         Uptime metrics.
 * @param array<string,mixed>  $speed          Speed metrics.
 * @param array<string,int>|null $ai_summary   AI insight summary.
 * @param array<string,mixed>|null $logs       Debug log metrics.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_calculate_transverse_impact_index($range, $config, $modules_status, $uptime, $speed, $ai_summary = null, $logs = null) {
    $range_id = sanitize_key((string) $range);

    if ($range_id === '') {
        $range_id = sitepulse_custom_dashboard_get_default_range();
    }

    $now            = sitepulse_custom_dashboard_get_current_timestamp();
    $window_seconds = isset($config['seconds']) ? (int) $config['seconds'] : 0;

    if ($window_seconds < 0) {
        $window_seconds = 0;
    }

    if (!is_array($modules_status)) {
        $modules_status = [];
    }

    if (null === $ai_summary) {
        $ai_summary = sitepulse_custom_dashboard_collect_ai_window_stats($window_seconds, $now);
    } elseif (!is_array($ai_summary)) {
        $ai_summary = [];
    }

    $module_labels = [
        'uptime_tracker' => __('Availability', 'sitepulse'),
        'speed_analyzer' => __('Performance', 'sitepulse'),
        'log_analyzer'   => __('Errors', 'sitepulse'),
        'ai_insights'    => __('AI backlog', 'sitepulse'),
    ];

    $weights = [
        'uptime_tracker' => 0.4,
        'speed_analyzer' => 0.35,
        'log_analyzer'   => 0.25,
        'ai_insights'    => 0.0,
    ];

    $weights = apply_filters('sitepulse_transverse_impact_weights', $weights, $range_id, $modules_status, $uptime, $speed, $ai_summary);

    if (!is_array($weights)) {
        $weights = [
            'uptime_tracker' => 0.4,
            'speed_analyzer' => 0.35,
            'log_analyzer'   => 0.25,
            'ai_insights'    => 0.0,
        ];
    }

    $modules_output  = [];
    $active_weights  = [];
    $dominant_module = '';
    $dominant_score  = -1.0;

    // Uptime module scoring.
    $uptime_entry = [
        'label'  => $module_labels['uptime_tracker'],
        'status' => 'status-warn',
        'score'  => null,
        'active' => !empty($modules_status['uptime_tracker']),
        'details'=> [],
        'signal' => '',
    ];

    $uptime_value = null;

    if (is_array($uptime) && isset($uptime['uptime']) && is_numeric($uptime['uptime'])) {
        $uptime_value = (float) $uptime['uptime'];
    }

    $violations = isset($uptime['violations']) ? (int) $uptime['violations'] : 0;

    if ($uptime_entry['active'] && $uptime_value !== null) {
        $uptime_warning = apply_filters('sitepulse_transverse_impact_uptime_warning', sitepulse_custom_dashboard_get_uptime_warning_threshold(), $range_id, $uptime);

        if (!is_numeric($uptime_warning)) {
            $uptime_warning = sitepulse_custom_dashboard_get_uptime_warning_threshold();
        }

        $uptime_warning  = (float) $uptime_warning;
        $uptime_critical = apply_filters('sitepulse_transverse_impact_uptime_critical', max(0.0, $uptime_warning - 1.0), $range_id, $uptime);

        if (!is_numeric($uptime_critical)) {
            $uptime_critical = max(0.0, $uptime_warning - 1.0);
        }

        $uptime_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($uptime_value, $uptime_warning, $uptime_critical, 'higher-is-better');

        $violations_warning = apply_filters('sitepulse_transverse_impact_uptime_violations_warning', 1, $range_id, $uptime);
        $violations_warning = is_numeric($violations_warning) ? max(0.0, (float) $violations_warning) : 1.0;

        $violations_critical = apply_filters('sitepulse_transverse_impact_uptime_violations_critical', 3, $range_id, $uptime);
        $violations_critical = is_numeric($violations_critical) ? max($violations_warning + 1.0, (float) $violations_critical) : ($violations_warning + 2.0);

        $violations_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($violations, $violations_warning, $violations_critical, 'higher-is-worse');

        $uptime_score = (($uptime_ratio * 0.7) + ($violations_ratio * 0.3)) * 100.0;
        $uptime_entry['score'] = round($uptime_score, 2);
        $uptime_entry['status'] = sitepulse_custom_dashboard_resolve_score_status($uptime_entry['score']);

        $signal_parts = [];
        $signal_parts[] = sprintf(__('Uptime %s%%', 'sitepulse'), number_format_i18n($uptime_value, 2));

        if ($violations > 0) {
            $signal_parts[] = sprintf(
                _n('%s incident', '%s incidents', $violations, 'sitepulse'),
                number_format_i18n($violations)
            );
        } else {
            $signal_parts[] = __('No incidents', 'sitepulse');
        }

        $uptime_entry['signal'] = implode(' • ', $signal_parts);

        $uptime_entry['details'][] = [
            'label' => __('Uptime', 'sitepulse'),
            'value' => sprintf('%s%%', number_format_i18n($uptime_value, 2)),
        ];

        $uptime_entry['details'][] = [
            'label' => __('Incidents', 'sitepulse'),
            'value' => number_format_i18n($violations),
        ];

        if (isset($uptime['totals']) && is_array($uptime['totals']) && isset($uptime['totals']['total'])) {
            $uptime_entry['details'][] = [
                'label' => __('Checks', 'sitepulse'),
                'value' => number_format_i18n((int) $uptime['totals']['total']),
            ];
        }

        $weight_value = isset($weights['uptime_tracker']) ? max(0.0, (float) $weights['uptime_tracker']) : 0.0;

        if ($weight_value > 0) {
            $active_weights['uptime_tracker'] = $weight_value;
        }

        if ($uptime_entry['score'] > $dominant_score) {
            $dominant_score  = $uptime_entry['score'];
            $dominant_module = 'uptime_tracker';
        }
    } elseif (!$uptime_entry['active']) {
        $uptime_entry['signal'] = __('Module inactive', 'sitepulse');
    } else {
        $uptime_entry['signal'] = __('Awaiting uptime data', 'sitepulse');
    }

    $modules_output['uptime_tracker'] = $uptime_entry;

    // Speed module scoring.
    $speed_entry = [
        'label'  => $module_labels['speed_analyzer'],
        'status' => 'status-warn',
        'score'  => null,
        'active' => !empty($modules_status['speed_analyzer']),
        'details'=> [],
        'signal' => '',
    ];

    $average = null;

    if (is_array($speed) && isset($speed['average']) && is_numeric($speed['average'])) {
        $average = (float) $speed['average'];
    }

    $thresholds = isset($speed['thresholds']) && is_array($speed['thresholds'])
        ? $speed['thresholds']
        : sitepulse_custom_dashboard_get_speed_thresholds_for_dashboard();

    $warning_ms  = isset($thresholds['warning']) ? (float) $thresholds['warning'] : 200.0;
    $critical_ms = isset($thresholds['critical']) ? (float) $thresholds['critical'] : max($warning_ms + 1.0, 500.0);

    if ($speed_entry['active'] && $average !== null) {
        $speed_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($average, $warning_ms, $critical_ms, 'higher-is-worse');

        $trend_value = isset($speed['trend']) && is_numeric($speed['trend']) ? (float) $speed['trend'] : 0.0;

        $trend_warning = apply_filters('sitepulse_transverse_impact_speed_trend_warning', 10.0, $range_id, $speed);
        $trend_warning = is_numeric($trend_warning) ? max(0.0, (float) $trend_warning) : 10.0;

        $trend_critical = apply_filters('sitepulse_transverse_impact_speed_trend_critical', 30.0, $range_id, $speed);
        $trend_critical = is_numeric($trend_critical) ? max($trend_warning + 1.0, (float) $trend_critical) : ($trend_warning + 20.0);

        $trend_ratio = 0.0;

        if ($trend_value > 0) {
            $trend_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($trend_value, $trend_warning, $trend_critical, 'higher-is-worse');
        }

        $speed_score = (($speed_ratio * 0.8) + ($trend_ratio * 0.2)) * 100.0;
        $speed_entry['score'] = round($speed_score, 2);
        $speed_entry['status'] = sitepulse_custom_dashboard_resolve_score_status($speed_entry['score']);

        $signal_parts = [];
        $signal_parts[] = sprintf(__('Average %s ms', 'sitepulse'), number_format_i18n($average, 1));

        if ($trend_value > 0) {
            $signal_parts[] = sprintf(__('Slower +%s ms', 'sitepulse'), number_format_i18n($trend_value, 1));
        } elseif ($trend_value < 0) {
            $signal_parts[] = sprintf(__('Faster %s ms', 'sitepulse'), number_format_i18n($trend_value, 1));
        }

        $speed_entry['signal'] = implode(' • ', $signal_parts);

        $speed_entry['details'][] = [
            'label' => __('Average', 'sitepulse'),
            'value' => sprintf('%s ms', number_format_i18n($average, 1)),
        ];

        if (isset($speed['samples'])) {
            $speed_entry['details'][] = [
                'label' => __('Samples', 'sitepulse'),
                'value' => number_format_i18n((int) $speed['samples']),
            ];
        }

        $weight_value = isset($weights['speed_analyzer']) ? max(0.0, (float) $weights['speed_analyzer']) : 0.0;

        if ($weight_value > 0) {
            $active_weights['speed_analyzer'] = $weight_value;
        }

        if ($speed_entry['score'] > $dominant_score) {
            $dominant_score  = $speed_entry['score'];
            $dominant_module = 'speed_analyzer';
        }
    } elseif (!$speed_entry['active']) {
        $speed_entry['signal'] = __('Module inactive', 'sitepulse');
    } else {
        $speed_entry['signal'] = __('Awaiting speed data', 'sitepulse');
    }

    $modules_output['speed_analyzer'] = $speed_entry;

    $log_entry = function_exists('sitepulse_custom_dashboard_build_log_impact_entry')
        ? sitepulse_custom_dashboard_build_log_impact_entry($logs, $modules_status)
        : [
            'label'   => $module_labels['log_analyzer'],
            'status'  => 'status-warn',
            'score'   => null,
            'active'  => !empty($modules_status['log_analyzer']),
            'details' => [],
            'signal'  => '',
        ];

    if (isset($log_entry['score']) && is_numeric($log_entry['score'])) {
        $weight_value = isset($weights['log_analyzer']) ? max(0.0, (float) $weights['log_analyzer']) : 0.0;

        if ($weight_value > 0) {
            $active_weights['log_analyzer'] = $weight_value;
        }

        if ((float) $log_entry['score'] > $dominant_score) {
            $dominant_score  = (float) $log_entry['score'];
            $dominant_module = 'log_analyzer';
        }
    }

    $modules_output['log_analyzer'] = $log_entry;

    // AI insights scoring.
    $ai_entry = [
        'label'  => $module_labels['ai_insights'],
        'status' => 'status-warn',
        'score'  => null,
        'active' => !empty($modules_status['ai_insights']),
        'details'=> [],
        'signal' => '',
    ];

    $recent_total   = isset($ai_summary['recent_total']) ? (int) $ai_summary['recent_total'] : 0;
    $recent_pending = isset($ai_summary['recent_pending']) ? (int) $ai_summary['recent_pending'] : 0;
    $recent_ack     = isset($ai_summary['recent_acknowledged']) ? (int) $ai_summary['recent_acknowledged'] : 0;
    $stale_pending  = isset($ai_summary['stale_pending']) ? (int) $ai_summary['stale_pending'] : 0;

    if ($ai_entry['active']) {
        $pending_warning = apply_filters('sitepulse_transverse_impact_ai_pending_warning', 1, $range_id, $ai_summary);
        $pending_warning = is_numeric($pending_warning) ? max(0.0, (float) $pending_warning) : 1.0;

        $pending_critical = apply_filters('sitepulse_transverse_impact_ai_pending_critical', 3, $range_id, $ai_summary);
        $pending_critical = is_numeric($pending_critical) ? max($pending_warning + 1.0, (float) $pending_critical) : ($pending_warning + 2.0);

        $pending_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($recent_pending, $pending_warning, $pending_critical, 'higher-is-worse');

        $backlog_critical = apply_filters('sitepulse_transverse_impact_ai_backlog_critical', 5, $range_id, $ai_summary);
        $backlog_critical = is_numeric($backlog_critical) ? max(1.0, (float) $backlog_critical) : 5.0;

        $backlog_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($stale_pending, 0.0, $backlog_critical, 'higher-is-worse');

        $ai_score = (($pending_ratio * 0.75) + ($backlog_ratio * 0.25)) * 100.0;
        $ai_entry['score'] = round($ai_score, 2);
        $ai_entry['status'] = sitepulse_custom_dashboard_resolve_score_status($ai_entry['score']);

        $signal_parts = [];

        if ($recent_pending > 0) {
            $signal_parts[] = sprintf(
                _n('%s pending insight', '%s pending insights', $recent_pending, 'sitepulse'),
                number_format_i18n($recent_pending)
            );
        } elseif ($recent_total > 0) {
            $signal_parts[] = __('Backlog cleared', 'sitepulse');
        }

        if ($recent_total > 0) {
            $signal_parts[] = sprintf(
                _n('%s insight generated', '%s insights generated', $recent_total, 'sitepulse'),
                number_format_i18n($recent_total)
            );
        }

        if ($stale_pending > 0) {
            $signal_parts[] = sprintf(
                _n('%s legacy pending', '%s legacy pending', $stale_pending, 'sitepulse'),
                number_format_i18n($stale_pending)
            );
        }

        $ai_entry['signal'] = implode(' • ', array_filter($signal_parts));

        $ai_entry['details'][] = [
            'label' => __('New insights', 'sitepulse'),
            'value' => number_format_i18n($recent_total),
        ];

        $ai_entry['details'][] = [
            'label' => __('Pending', 'sitepulse'),
            'value' => number_format_i18n($recent_pending),
        ];

        if ($recent_ack > 0) {
            $ai_entry['details'][] = [
                'label' => __('Acknowledged', 'sitepulse'),
                'value' => number_format_i18n($recent_ack),
            ];
        }

        if ($stale_pending > 0) {
            $ai_entry['details'][] = [
                'label' => __('Legacy backlog', 'sitepulse'),
                'value' => number_format_i18n($stale_pending),
            ];
        }

        $weight_value = isset($weights['ai_insights']) ? max(0.0, (float) $weights['ai_insights']) : 0.0;

        if ($weight_value > 0) {
            $active_weights['ai_insights'] = $weight_value;
        }

        if ($ai_entry['score'] > $dominant_score) {
            $dominant_score  = $ai_entry['score'];
            $dominant_module = 'ai_insights';
        }
    } elseif (!$ai_entry['active']) {
        $ai_entry['signal'] = __('Module inactive', 'sitepulse');
    } else {
        $ai_entry['signal'] = __('Awaiting AI insights', 'sitepulse');
    }

    $modules_output['ai_insights'] = $ai_entry;

    $impact = [
        'range'           => $range_id,
        'updated_at'      => $now,
        'window_seconds'  => $window_seconds,
        'modules'         => $modules_output,
        'dominant_module' => $dominant_module,
        'overall'         => null,
        'active_modules'  => 0,
    ];

    if (!empty($active_weights)) {
        $total_weight = array_sum($active_weights);

        if ($total_weight > 0) {
            $weighted_sum = 0.0;

            foreach ($active_weights as $module_key => $weight) {
                if (!isset($modules_output[$module_key]['score']) || !is_numeric($modules_output[$module_key]['score'])) {
                    continue;
                }

                $weighted_sum += (float) $modules_output[$module_key]['score'] * ($weight / $total_weight);
            }

            $impact['overall'] = round($weighted_sum, 2);
            $impact['health']  = round(max(0.0, min(100.0, 100.0 - $impact['overall'])), 2);
        }
    }

    foreach ($modules_output as $module_data) {
        if (!empty($module_data['active']) && isset($module_data['score']) && is_numeric($module_data['score'])) {
            $impact['active_modules']++;
        }
    }

    sitepulse_custom_dashboard_store_impact_index($impact);

    return $impact;
}

/**
 * Formats the impact card view for the dashboard KPI grid.
 *
 * @param array<string,mixed>|null $impact      Impact payload.
 * @param string                   $range_label Human-readable range label.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_impact_card_view($impact, $range_label) {
    $status_meta = sitepulse_custom_dashboard_resolve_status_meta('status-warn');

    $card = [
        'label'            => __('Impact index', 'sitepulse'),
        'status'           => array_merge($status_meta, ['class' => 'status-warn']),
        'value'            => ['text' => __('N/A', 'sitepulse'), 'unit' => ''],
        'summary'          => __('No impact data available for this window.', 'sitepulse'),
        'trend'            => sitepulse_custom_dashboard_format_trend(null),
        'details'          => [],
        'description'      => '',
        'inactive'         => false,
        'inactive_message' => '',
    ];

    if (!is_array($impact) || empty($impact['modules']) || !is_array($impact['modules'])) {
        $card['inactive'] = true;
        $card['inactive_message'] = __('Activate the monitoring modules to compute the impact score.', 'sitepulse');
        $card['description'] = sprintf(__('No impact score could be generated for %s.', 'sitepulse'), $range_label);

        return $card;
    }

    $modules = $impact['modules'];
    $overall = isset($impact['overall']) && is_numeric($impact['overall']) ? (float) $impact['overall'] : null;
    $dominant = isset($impact['dominant_module']) ? (string) $impact['dominant_module'] : '';

    if ($overall !== null) {
        $status_key  = sitepulse_custom_dashboard_resolve_score_status($overall);
        $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status_key);

        $card['status'] = array_merge($status_meta, ['class' => $status_key]);
        $card['value']  = ['text' => number_format_i18n($overall, 1), 'unit' => ''];
    }

    $dominant_label = '';
    $dominant_score = null;

    if ($dominant !== '' && isset($modules[$dominant]) && is_array($modules[$dominant])) {
        $dominant_label = isset($modules[$dominant]['label']) ? $modules[$dominant]['label'] : $dominant;
        $dominant_score = isset($modules[$dominant]['score']) && is_numeric($modules[$dominant]['score'])
            ? (float) $modules[$dominant]['score']
            : null;
    }

    if ($dominant_label === '') {
        foreach ($modules as $module_data) {
            if (!is_array($module_data)) {
                continue;
            }

            if (isset($module_data['score']) && is_numeric($module_data['score'])) {
                $dominant_label = isset($module_data['label']) ? $module_data['label'] : '';
                $dominant_score = (float) $module_data['score'];
                break;
            }
        }
    }

    if ($overall !== null && $overall < 35.0) {
        $card['summary'] = sprintf(__('Signals nominal across monitored modules for %s.', 'sitepulse'), $range_label);
    } elseif ($dominant_label !== '' && $dominant_score !== null) {
        if ($dominant_score >= 70.0) {
            $card['summary'] = sprintf(__('Critical pressure from %s.', 'sitepulse'), $dominant_label);
        } else {
            $card['summary'] = sprintf(__('Attention needed on %s.', 'sitepulse'), $dominant_label);
        }
    } else {
        $card['summary'] = sprintf(__('Impact score partially available for %s.', 'sitepulse'), $range_label);
    }

    $card['description'] = sprintf(__('Weighted synthesis of uptime, speed and AI insights over %s.', 'sitepulse'), $range_label);

    $has_active_score = false;

    foreach ($modules as $module_key => $module_data) {
        if (!is_array($module_data)) {
            continue;
        }

        $label = isset($module_data['label']) ? $module_data['label'] : ucfirst(str_replace('_', ' ', (string) $module_key));
        $score_value = isset($module_data['score']) && is_numeric($module_data['score'])
            ? number_format_i18n((float) $module_data['score'], 1)
            : __('N/A', 'sitepulse');
        $status_key  = isset($module_data['status']) ? (string) $module_data['status'] : 'status-warn';
        $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status_key);
        $signal      = isset($module_data['signal']) ? $module_data['signal'] : '';

        $detail_value = $score_value;

        if (!empty($status_meta['label'])) {
            $detail_value .= ' • ' . $status_meta['label'];
        }

        if ($signal !== '') {
            $detail_value .= ' — ' . $signal;
        }

        $card['details'][] = [
            'label' => $label,
            'value' => $detail_value,
        ];

        if (!empty($module_data['active']) && isset($module_data['score']) && is_numeric($module_data['score'])) {
            $has_active_score = true;
        }
    }

    if (!$has_active_score) {
        $card['inactive'] = true;
        $card['inactive_message'] = __('No active module provided enough data to compute the impact index.', 'sitepulse');
    }

    return $card;
}

/**
 * Formats rows describing the impact index for CSV exports.
 *
 * @param array<string,mixed> $impact      Impact payload.
 * @param string              $range_label Range label used in the export.
 * @return array<int,array<int,string>>
 */
function sitepulse_custom_dashboard_format_impact_export_rows($impact, $range_label) {
    if (!is_array($impact) || empty($impact['modules']) || !is_array($impact['modules'])) {
        return [];
    }

    $rows = [];
    $rows[] = [__('Indice transverse', 'sitepulse'), $range_label];

    if (isset($impact['overall']) && is_numeric($impact['overall'])) {
        $overall    = (float) $impact['overall'];
        $status_key = sitepulse_custom_dashboard_resolve_score_status($overall);
        $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status_key);

        $rows[] = [
            __('Score global', 'sitepulse'),
            number_format_i18n($overall, 1),
            $status_meta['label'],
        ];
    }

    $rows[] = [
        __('Module', 'sitepulse'),
        __('Score', 'sitepulse'),
        __('Statut', 'sitepulse'),
        __('Signal clé', 'sitepulse'),
    ];

    foreach ($impact['modules'] as $module_data) {
        if (!is_array($module_data)) {
            continue;
        }

        $label = isset($module_data['label']) ? $module_data['label'] : '';
        $score_text = isset($module_data['score']) && is_numeric($module_data['score'])
            ? number_format_i18n((float) $module_data['score'], 1)
            : __('N/A', 'sitepulse');
        $status_key = isset($module_data['status']) ? (string) $module_data['status'] : 'status-warn';
        $status_meta = sitepulse_custom_dashboard_resolve_status_meta($status_key);
        $signal = isset($module_data['signal']) ? $module_data['signal'] : '';

        $rows[] = [
            $label,
            $score_text,
            $status_meta['label'],
            $signal,
        ];
    }

    if (function_exists('sitepulse_uptime_escape_csv_field')) {
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index] = array_map('sitepulse_uptime_escape_csv_field', $row);
        }
    }

    return $rows;
}

/**
 * Reads and summarises the WordPress debug log for dashboard usage.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_analyze_debug_log($force_refresh = false) {
    static $cached = null;

    if (!$force_refresh && $cached !== null) {
        return $cached;
    }

    $transient_key = defined('SITEPULSE_TRANSIENT_DEBUG_LOG_SUMMARY')
        ? SITEPULSE_TRANSIENT_DEBUG_LOG_SUMMARY
        : 'sitepulse_dashboard_log_summary';

    if ($force_refresh && function_exists('delete_transient')) {
        delete_transient($transient_key);
    }

    $log_file = function_exists('sitepulse_get_wp_debug_log_path')
        ? sitepulse_get_wp_debug_log_path()
        : null;

    $cache_signature = 'unavailable:' . md5((string) $log_file);

    if ($log_file !== null && file_exists($log_file)) {
        $mtime = @filemtime($log_file);
        $size  = @filesize($log_file);

        if ($mtime !== false || $size !== false) {
            $cache_signature = sprintf(
                '%s:%s:%s',
                md5($log_file),
                $mtime !== false ? (int) $mtime : 0,
                $size !== false ? (int) $size : 0
            );
        } else {
            $cache_signature = 'stat:' . md5((string) $log_file);
        }
    }

    if (!$force_refresh && function_exists('get_transient')) {
        $transient_value = get_transient($transient_key);

        if (
            is_array($transient_value)
            && isset($transient_value['signature'], $transient_value['data'])
            && $transient_value['signature'] === $cache_signature
        ) {
            $cached = $transient_value['data'];

            if (is_array($cached)) {
                return $cached;
            }
        }
    }

    $counts = [
        'fatal'      => 0,
        'warning'    => 0,
        'notice'     => 0,
        'deprecated' => 0,
    ];

    $status   = 'status-ok';
    $summary  = __('Log is clean.', 'sitepulse');
    $metadata = null;
    $truncated = false;
    $readable  = false;

    if ($log_file === null) {
        $status  = 'status-warn';
        $summary = __('Debug log not configured.', 'sitepulse');
    } elseif (!file_exists($log_file)) {
        $status  = 'status-warn';
        $summary = sprintf(__('Log file not found (%s).', 'sitepulse'), $log_file);
    } elseif (!is_readable($log_file)) {
        $status  = 'status-warn';
        $summary = sprintf(__('Unable to read log file (%s).', 'sitepulse'), $log_file);
    } else {
        $readable  = true;
        $log_lines = sitepulse_get_recent_log_lines($log_file, 200, 131072, true);

        if ($log_lines === null) {
            $status  = 'status-warn';
            $summary = sprintf(__('Unable to read log file (%s).', 'sitepulse'), $log_file);
        } else {
            $lines = [];

            if (is_array($log_lines) && isset($log_lines['lines'])) {
                $lines     = (array) $log_lines['lines'];
                $metadata  = $log_lines;
                $truncated = !empty($log_lines['truncated']);
            } else {
                $lines = (array) $log_lines;
            }

            if (empty($lines)) {
                $summary = __('No recent log entries.', 'sitepulse');

                if (is_array($metadata)) {
                    $metadata['lines'] = [];
                }
            } else {
                $content = implode("\n", $lines);

                $patterns = [
                    'fatal'      => '/PHP (Fatal error|Parse error|Uncaught)/i',
                    'warning'    => '/PHP Warning/i',
                    'notice'     => '/PHP Notice/i',
                    'deprecated' => '/PHP Deprecated/i',
                ];

                foreach ($patterns as $type => $pattern) {
                    $matches        = preg_match_all($pattern, $content, $ignore_matches);
                    $counts[$type]  = $matches ? (int) $matches : 0;
                }

                if ($counts['fatal'] > 0) {
                    $status  = 'status-bad';
                    $summary = __('Fatal errors detected in the debug log.', 'sitepulse');
                } elseif ($counts['warning'] > 0 || $counts['deprecated'] > 0) {
                    $status  = 'status-warn';
                    $summary = __('Warnings present in the debug log.', 'sitepulse');
                } else {
                    $summary = __('No critical events detected.', 'sitepulse');

                    if ($truncated) {
                        $summary .= ' ' . __('(Only the tail of the log is displayed.)', 'sitepulse');
                    }
                }
            }
        }
    }

    $chart = [
        'type'      => 'doughnut',
        'labels'    => [
            __('Fatal errors', 'sitepulse'),
            __('Warnings', 'sitepulse'),
            __('Notices', 'sitepulse'),
            __('Deprecated notices', 'sitepulse'),
        ],
        'datasets'  => array_sum($counts) > 0
            ? [[
                'data'            => array_values($counts),
                'backgroundColor' => ['#ff3b30', '#ff9500', '#007bff', '#af52de'],
                'borderWidth'     => 0,
            ]]
            : [],
        'empty'     => array_sum($counts) === 0,
        'status'    => $status,
        'truncated' => $truncated,
    ];

    $detailed_metadata = [
        'path'       => $log_file,
        'available'  => $log_file !== null,
        'readable'   => $readable,
        'truncated'  => $truncated,
        'bytes_read' => is_array($metadata) && isset($metadata['bytes_read']) ? (int) $metadata['bytes_read'] : null,
        'file_size'  => is_array($metadata) && isset($metadata['file_size']) ? (int) $metadata['file_size'] : null,
        'last_modified' => is_array($metadata) && isset($metadata['last_modified'])
            ? (int) $metadata['last_modified']
            : null,
    ];

    if (is_array($metadata) && isset($metadata['lines'])) {
        $detailed_metadata['lines'] = (array) $metadata['lines'];
    }

    $cached = [
        'card' => [
            'status'  => $status,
            'summary' => $summary,
            'counts'  => $counts,
            'meta'    => $metadata,
        ],
        'chart'     => $chart,
        'metadata'  => $detailed_metadata,
    ];

    if (function_exists('set_transient')) {
        $ttl = (int) apply_filters('sitepulse_dashboard_debug_log_cache_ttl', 5 * MINUTE_IN_SECONDS, $cached, $cache_signature);

        if ($ttl > 0) {
            set_transient($transient_key, [
                'signature' => $cache_signature,
                'data'      => $cached,
            ], $ttl);
        }
    }

    return $cached;
}

/**
 * Normalises the stored speed history when the Speed Analyzer module is inactive.
 *
 * @return array<int,array{timestamp:int,server_processing_ms:float}>
 */
function sitepulse_custom_dashboard_get_speed_history() {
    if (function_exists('sitepulse_speed_analyzer_get_history_data')) {
        return sitepulse_speed_analyzer_get_history_data();
    }

    $option_key = defined('SITEPULSE_OPTION_SPEED_SCAN_HISTORY')
        ? SITEPULSE_OPTION_SPEED_SCAN_HISTORY
        : 'sitepulse_speed_scan_history';

    $history = get_option($option_key, []);

    if (!is_array($history)) {
        return [];
    }

    $normalized = [];

    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (!isset($entry['timestamp'], $entry['server_processing_ms'])) {
            continue;
        }

        if (!is_numeric($entry['timestamp']) || !is_numeric($entry['server_processing_ms'])) {
            continue;
        }

        $normalized[] = [
            'timestamp'            => max(0, (int) $entry['timestamp']),
            'server_processing_ms' => max(0.0, (float) $entry['server_processing_ms']),
        ];
    }

    usort(
        $normalized,
        static function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        }
    );

    return $normalized;
}

/**
 * Resolves the status of a speed measurement against the configured thresholds.
 *
 * @param float|int|null        $value      Measurement in milliseconds.
 * @param array<string,int>     $thresholds Warning and critical thresholds.
 * @return string
 */
function sitepulse_custom_dashboard_resolve_speed_status($value, $thresholds) {
    if (function_exists('sitepulse_speed_analyzer_resolve_status')) {
        return sitepulse_speed_analyzer_resolve_status($value, $thresholds);
    }

    if (!is_numeric($value)) {
        return 'status-warn';
    }

    $warning  = isset($thresholds['warning']) ? (int) $thresholds['warning'] : 0;
    $critical = isset($thresholds['critical']) ? (int) $thresholds['critical'] : 0;
    $value    = (float) $value;

    if ($critical > 0 && $value >= $critical) {
        return 'status-bad';
    }

    if ($warning > 0 && $value >= $warning) {
        return 'status-warn';
    }

    return 'status-ok';
}

/**
 * Retrieves the configured speed thresholds without requiring the Speed module.
 *
 * @return array<string,int>
 */
function sitepulse_custom_dashboard_get_speed_thresholds_for_dashboard() {
    if (function_exists('sitepulse_get_speed_thresholds')) {
        $thresholds = sitepulse_get_speed_thresholds();

        if (is_array($thresholds)) {
            return $thresholds;
        }
    }

    $default_warning  = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
    $default_critical = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;

    $warning_option  = defined('SITEPULSE_OPTION_SPEED_WARNING_MS') ? SITEPULSE_OPTION_SPEED_WARNING_MS : 'sitepulse_speed_warning_ms';
    $critical_option = defined('SITEPULSE_OPTION_SPEED_CRITICAL_MS') ? SITEPULSE_OPTION_SPEED_CRITICAL_MS : 'sitepulse_speed_critical_ms';

    $warning  = (int) get_option($warning_option, $default_warning);
    $critical = (int) get_option($critical_option, $default_critical);

    if ($warning <= 0) {
        $warning = $default_warning;
    }

    if ($critical <= 0 || $critical <= $warning) {
        $critical = max($warning + 1, $default_critical);
    }

    return [
        'warning'  => $warning,
        'critical' => $critical,
    ];
}

/**
 * Calculates the arithmetic mean of speed measurements.
 *
 * @param array<int,array<string,float|int>> $entries History entries.
 * @return float|null
 */
function sitepulse_custom_dashboard_average_measurements($entries) {
    if (empty($entries) || !is_array($entries)) {
        return null;
    }

    $sum   = 0.0;
    $count = 0;

    foreach ($entries as $entry) {
        if (!is_array($entry) || !isset($entry['server_processing_ms'])) {
            continue;
        }

        if (!is_numeric($entry['server_processing_ms'])) {
            continue;
        }

        $sum   += (float) $entry['server_processing_ms'];
        $count++;
    }

    if ($count === 0) {
        return null;
    }

    return $sum / $count;
}

/**
 * Aggregates speed metrics for the requested window.
 *
 * @param string              $range  Range identifier.
 * @param array<string,mixed> $config Range configuration.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_calculate_speed_metrics($range, $config) {
    $history = sitepulse_custom_dashboard_get_speed_history();

    $window_seconds = isset($config['seconds']) ? (int) $config['seconds'] : 0;

    if ($window_seconds < 0) {
        $window_seconds = 0;
    }

    $now          = sitepulse_custom_dashboard_get_current_timestamp();
    $window_start = $window_seconds > 0 ? $now - $window_seconds : 0;

    $current_entries  = [];
    $previous_entries = [];

    if (!empty($history)) {
        foreach ($history as $entry) {
            if (!is_array($entry) || !isset($entry['timestamp'])) {
                continue;
            }

            $timestamp = (int) $entry['timestamp'];

            if ($window_seconds <= 0) {
                $current_entries[] = $entry;
                continue;
            }

            if ($timestamp >= $window_start) {
                $current_entries[] = $entry;
            } elseif ($timestamp >= ($window_start - $window_seconds)) {
                $previous_entries[] = $entry;
            }
        }
    }

    $current_avg  = sitepulse_custom_dashboard_average_measurements($current_entries);
    $previous_avg = sitepulse_custom_dashboard_average_measurements($previous_entries);

    $thresholds = sitepulse_custom_dashboard_get_speed_thresholds_for_dashboard();

    $latest_entry = null;

    if (!empty($current_entries)) {
        $latest_entry = $current_entries[count($current_entries) - 1];
    } elseif (!empty($history)) {
        $latest_entry = $history[count($history) - 1];
    }

    $latest_payload = null;

    if (is_array($latest_entry)) {
        $latest_payload = [
            'timestamp'            => isset($latest_entry['timestamp']) ? (int) $latest_entry['timestamp'] : 0,
            'server_processing_ms' => isset($latest_entry['server_processing_ms'])
                ? round((float) $latest_entry['server_processing_ms'], 2)
                : null,
            'status'              => sitepulse_custom_dashboard_resolve_speed_status(
                $latest_entry['server_processing_ms'] ?? null,
                $thresholds
            ),
        ];
    }

    return [
        'range'             => $range,
        'window_seconds'    => $window_seconds,
        'samples'           => count($current_entries),
        'previous_samples'  => count($previous_entries),
        'average'           => $current_avg !== null ? round($current_avg, 2) : null,
        'previous_average'  => $previous_avg !== null ? round($previous_avg, 2) : null,
        'trend'             => sitepulse_custom_dashboard_calculate_trend($current_avg, $previous_avg, 2),
        'latest'            => $latest_payload,
        'thresholds'        => $thresholds,
        'history_available' => !empty($history),
    ];
}

/**
 * Collects resource monitor metrics for the dashboard payload.
 *
 * @return array<string,mixed>|null
 */
function sitepulse_custom_dashboard_calculate_resource_metrics() {
    $enabled = function_exists('sitepulse_is_module_active')
        ? sitepulse_is_module_active('resource_monitor')
        : true;

    $snapshot = function_exists('sitepulse_resource_monitor_get_snapshot')
        ? sitepulse_resource_monitor_get_snapshot()
        : null;

    $http_stats = function_exists('sitepulse_http_monitor_get_stats')
        ? sitepulse_http_monitor_get_stats([
            'since' => (int) current_time('timestamp', true) - DAY_IN_SECONDS,
            'limit' => 10,
        ])
        : null;

    if (!$enabled && $snapshot === null && $http_stats === null) {
        return null;
    }

    return [
        'enabled'  => $enabled,
        'snapshot' => $snapshot,
        'http'     => $http_stats,
    ];
}

function sitepulse_get_dashboard_preferences($user_id = 0, $allowed_cards = null) {
    if (!is_int($user_id) || $user_id <= 0) {
        $user_id = get_current_user_id();
    }

    $stored_preferences = [];

    if ($user_id > 0) {
        $stored_preferences = get_user_meta($user_id, 'sitepulse_dashboard_preferences', true);

        if (!is_array($stored_preferences)) {
            $stored_preferences = [];
        }
    }

    return sitepulse_sanitize_dashboard_preferences($stored_preferences, $allowed_cards);
}

/**
 * Persists dashboard preferences for the supplied user.
 *
 * @param int              $user_id       User identifier.
 * @param array            $preferences   Preferences to store.
 * @param string[]|null    $allowed_cards Optional subset of cards to accept.
 *
 * @return bool True on success, false otherwise.
 */
function sitepulse_update_dashboard_preferences($user_id, $preferences, $allowed_cards = null) {
    $user_id = (int) $user_id;

    if ($user_id <= 0) {
        return false;
    }

    $sanitized = sitepulse_sanitize_dashboard_preferences($preferences, $allowed_cards);

    return (bool) update_user_meta($user_id, 'sitepulse_dashboard_preferences', $sanitized);
}

/**
 * Handles AJAX requests to store dashboard preferences for the current user.
 */
function sitepulse_save_dashboard_preferences() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error(['message' => __('Vous n’avez pas les permissions nécessaires pour modifier ces préférences.', 'sitepulse')], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field((string) wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, 'sitepulse_dashboard_preferences')) {
        wp_send_json_error(['message' => __('Jeton de sécurité invalide. Merci de recharger la page.', 'sitepulse')], 400);
    }

    $raw_preferences = [
        'order'      => isset($_POST['order']) ? (array) wp_unslash($_POST['order']) : [],
        'visibility' => isset($_POST['visibility']) ? (array) wp_unslash($_POST['visibility']) : [],
        'sizes'      => isset($_POST['sizes']) ? (array) wp_unslash($_POST['sizes']) : [],
        'theme'      => isset($_POST['theme']) ? (string) wp_unslash($_POST['theme']) : '',
    ];

    $allowed_cards = sitepulse_get_dashboard_card_keys();
    $preferences = sitepulse_sanitize_dashboard_preferences($raw_preferences, $allowed_cards);
    $user_id = get_current_user_id();

    if (!sitepulse_update_dashboard_preferences($user_id, $preferences, $allowed_cards)) {
        wp_send_json_error(['message' => __('Impossible d’enregistrer les préférences pour le moment.', 'sitepulse')], 500);
    }

    wp_send_json_success(['preferences' => $preferences]);
}

/**
 * Builds a reusable context describing the dashboard cards and charts.
 *
 * @return array
 */
function sitepulse_get_dashboard_preview_context() {
    static $context = null;

    if (null !== $context) {
        return $context;
    }

    $active_modules = array_map('strval', (array) get_option(SITEPULSE_OPTION_ACTIVE_MODULES, []));
    $active_modules = array_values(array_filter($active_modules, static function ($module) {
        return $module !== '';
    }));

    global $wpdb;

    $is_speed_enabled = in_array('speed_analyzer', $active_modules, true);
    $is_uptime_enabled = in_array('uptime_tracker', $active_modules, true);
    $is_database_enabled = in_array('database_optimizer', $active_modules, true);
    $is_logs_enabled = in_array('log_analyzer', $active_modules, true);

    $palette = [
        'green'    => '#0b6d2a',
        'amber'    => '#8a6100',
        'red'      => '#a0141e',
        'deep_red' => '#7f1018',
        'blue'     => '#2196F3',
        'grey'     => '#E0E0E0',
        'purple'   => '#9C27B0',
    ];

    $status_labels = [
        'status-ok'   => [
            'label' => __('Bon', 'sitepulse'),
            'sr'    => __('Statut : bon', 'sitepulse'),
            'icon'  => '✔️',
        ],
        'status-warn' => [
            'label' => __('Attention', 'sitepulse'),
            'sr'    => __('Statut : attention', 'sitepulse'),
            'icon'  => '⚠️',
        ],
        'status-bad'  => [
            'label' => __('Critique', 'sitepulse'),
            'sr'    => __('Statut : critique', 'sitepulse'),
            'icon'  => '⛔',
        ],
    ];

    $default_status_labels = $status_labels;

    $context = [
        'active_modules' => $active_modules,
        'palette'        => $palette,
        'status_labels'  => $status_labels,
        'modules'        => [
            'speed' => [
                'enabled'     => $is_speed_enabled,
                'card'        => null,
                'chart'       => null,
                'thresholds'  => [
                    'warning'  => defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200,
                    'critical' => defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500,
                ],
            ],
            'uptime' => [
                'enabled' => $is_uptime_enabled,
                'card'    => null,
                'chart'   => null,
            ],
            'database' => [
                'enabled' => $is_database_enabled,
                'card'    => null,
                'chart'   => null,
            ],
            'logs' => [
                'enabled' => $is_logs_enabled,
                'card'    => null,
                'chart'   => null,
            ],
        ],
        'charts_payload' => [],
    ];

    $charts_payload = [];

    if ($is_speed_enabled) {
        $results = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        $raw_processing_time = null;

        if (is_array($results)) {
            if (isset($results['server_processing_ms']) && is_numeric($results['server_processing_ms'])) {
                $raw_processing_time = (float) $results['server_processing_ms'];
            } elseif (isset($results['ttfb']) && is_numeric($results['ttfb'])) {
                $raw_processing_time = (float) $results['ttfb'];
            } elseif (isset($results['data']['server_processing_ms']) && is_numeric($results['data']['server_processing_ms'])) {
                $raw_processing_time = (float) $results['data']['server_processing_ms'];
            } elseif (isset($results['data']['ttfb']) && is_numeric($results['data']['ttfb'])) {
                $raw_processing_time = (float) $results['data']['ttfb'];
            }
        }

        $history_entries = get_option(SITEPULSE_OPTION_SPEED_SCAN_HISTORY, []);

        if (!is_array($history_entries)) {
            $history_entries = [];
        }

        $history_entries = array_values(array_filter(
            $history_entries,
            static function ($entry) {
                if (!is_array($entry)) {
                    return false;
                }

                if (!isset($entry['server_processing_ms']) || !is_numeric($entry['server_processing_ms'])) {
                    return false;
                }

                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

                return $timestamp > 0;
            }
        ));

        if (!empty($history_entries)) {
            usort(
                $history_entries,
                static function ($a, $b) {
                    $a_timestamp = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
                    $b_timestamp = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;

                    if ($a_timestamp === $b_timestamp) {
                        return 0;
                    }

                    return ($a_timestamp < $b_timestamp) ? -1 : 1;
                }
            );
        }

        $history_point_limit = apply_filters('sitepulse_speed_history_chart_points', 30);

        if (!is_scalar($history_point_limit)) {
            $history_point_limit = 30;
        }

        $history_point_limit = max(1, (int) $history_point_limit);

        if (count($history_entries) > $history_point_limit) {
            $history_entries = array_slice($history_entries, -$history_point_limit);
        }

        if (empty($history_entries) && $raw_processing_time !== null) {
            $fallback_timestamp = null;

            if (isset($results['timestamp']) && is_numeric($results['timestamp'])) {
                $fallback_timestamp = (int) $results['timestamp'];
            } elseif (isset($results['data']['timestamp']) && is_numeric($results['data']['timestamp'])) {
                $fallback_timestamp = (int) $results['data']['timestamp'];
            }

            if ($fallback_timestamp === null || $fallback_timestamp <= 0) {
                $fallback_timestamp = current_time('timestamp');
            }

            $history_entries[] = [
                'timestamp'            => $fallback_timestamp,
                'server_processing_ms' => (float) $raw_processing_time,
            ];
        }

        $latest_entry = !empty($history_entries)
            ? $history_entries[count($history_entries) - 1]
            : null;

        $processing_time = $raw_processing_time;

        if (is_array($latest_entry) && isset($latest_entry['server_processing_ms']) && is_numeric($latest_entry['server_processing_ms'])) {
            $processing_time = (float) $latest_entry['server_processing_ms'];
        }

        $default_speed_thresholds = [
            'warning'  => defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200,
            'critical' => defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500,
        ];

        $speed_thresholds = function_exists('sitepulse_get_speed_thresholds')
            ? sitepulse_get_speed_thresholds()
            : $default_speed_thresholds;

        $speed_warning_threshold = isset($speed_thresholds['warning']) ? (int) $speed_thresholds['warning'] : $default_speed_thresholds['warning'];
        $speed_critical_threshold = isset($speed_thresholds['critical']) ? (int) $speed_thresholds['critical'] : $default_speed_thresholds['critical'];

        if ($speed_warning_threshold < 1) {
            $speed_warning_threshold = $default_speed_thresholds['warning'];
        }

        if ($speed_critical_threshold <= $speed_warning_threshold) {
            $speed_critical_threshold = max($speed_warning_threshold + 1, $default_speed_thresholds['critical']);
        }

        $processing_status = 'status-ok';

        if ($processing_time === null) {
            $processing_status = 'status-warn';
        } elseif ($processing_time >= $speed_critical_threshold) {
            $processing_status = 'status-bad';
        } elseif ($processing_time >= $speed_warning_threshold) {
            $processing_status = 'status-warn';
        }

        $processing_display = $processing_time !== null
            ? round($processing_time) . ' ' . esc_html__('ms', 'sitepulse')
            : esc_html__('N/A', 'sitepulse');

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $speed_labels = [];
        $speed_values = [];

        foreach ($history_entries as $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $value = isset($entry['server_processing_ms']) ? (float) $entry['server_processing_ms'] : null;

            if ($value === null) {
                continue;
            }

            $label = $timestamp > 0
                ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                : __('Unknown', 'sitepulse');

            $speed_labels[] = $label;
            $speed_values[] = max(0.0, (float) $value);
        }

        $speed_values = array_map(
            static function ($value) {
                return round((float) $value, 2);
            },
            $speed_values
        );

        $speed_reference = max(1.0, (float) $speed_warning_threshold);
        $speed_chart = [
            'type'     => 'line',
            'labels'   => $speed_labels,
            'datasets' => [],
            'empty'    => empty($speed_labels),
            'status'   => $processing_status,
            'value'    => $processing_time !== null ? round($processing_time, 2) : null,
            'unit'     => __('ms', 'sitepulse'),
            'reference'=> (float) $speed_reference,
        ];

        if (!empty($speed_labels)) {
            $speed_color_map = [
                'status-ok'   => $palette['green'],
                'status-warn' => $palette['amber'],
                'status-bad'  => $palette['red'],
            ];
            $speed_primary_color = isset($speed_color_map[$processing_status]) ? $speed_color_map[$processing_status] : $palette['blue'];

            $speed_chart['datasets'][] = [
                'label'               => __('Processing time', 'sitepulse'),
                'data'                => $speed_values,
                'borderColor'         => $speed_primary_color,
                'pointBackgroundColor'=> $speed_primary_color,
                'pointRadius'         => 3,
                'tension'             => 0.3,
                'fill'                => false,
            ];

            $budget_values = array_fill(0, count($speed_labels), (float) $speed_reference);

            $speed_chart['datasets'][] = [
                'label'       => __('Performance budget', 'sitepulse'),
                'data'        => $budget_values,
                'borderColor' => $palette['amber'],
                'borderWidth' => 2,
                'borderDash'  => [6, 6],
                'pointRadius' => 0,
                'fill'        => false,
            ];
        }

        $charts_payload['speed'] = $speed_chart;
        $context['modules']['speed']['card'] = [
            'status'  => $processing_status,
            'display' => $processing_display,
        ];
        $context['modules']['speed']['chart'] = $speed_chart;
        $context['modules']['speed']['thresholds'] = [
            'warning'  => $speed_warning_threshold,
            'critical' => $speed_critical_threshold,
        ];
    }

    if ($is_uptime_enabled) {
        $raw_uptime_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
        $uptime_log = function_exists('sitepulse_normalize_uptime_log')
            ? sitepulse_normalize_uptime_log($raw_uptime_log)
            : (array) $raw_uptime_log;
        $boolean_checks = array_values(array_filter($uptime_log, function ($entry) {
            return is_array($entry) && array_key_exists('status', $entry) && is_bool($entry['status']);
        }));
        $evaluated_checks = count($boolean_checks);
        $up_checks = count(array_filter($boolean_checks, function ($entry) {
            return isset($entry['status']) && true === $entry['status'];
        }));
        $uptime_percentage = $evaluated_checks > 0 ? ($up_checks / $evaluated_checks) * 100 : 100;
        $default_uptime_warning = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT') ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT : 99.0;

        if (function_exists('sitepulse_get_uptime_warning_percentage')) {
            $uptime_warning_threshold = (float) sitepulse_get_uptime_warning_percentage();
        } else {
            $uptime_warning_key = defined('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT') ? SITEPULSE_OPTION_UPTIME_WARNING_PERCENT : 'sitepulse_uptime_warning_percent';
            $stored_threshold = get_option($uptime_warning_key, $default_uptime_warning);
            $uptime_warning_threshold = is_scalar($stored_threshold) ? (float) $stored_threshold : $default_uptime_warning;
        }

        if ($uptime_warning_threshold < 0) {
            $uptime_warning_threshold = 0.0;
        } elseif ($uptime_warning_threshold > 100) {
            $uptime_warning_threshold = 100.0;
        }

        if ($uptime_percentage < $uptime_warning_threshold) {
            $uptime_status = 'status-bad';
        } elseif ($uptime_percentage < 100) {
            $uptime_status = 'status-warn';
        } else {
            $uptime_status = 'status-ok';
        }

        $uptime_entries = array_slice($uptime_log, -30);

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $uptime_labels = [];
        $uptime_values = [];
        $uptime_colors = [];

        foreach ($uptime_entries as $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $label = $timestamp > 0
                ? date_i18n($date_format . ' ' . $time_format, $timestamp)
                : __('Unknown', 'sitepulse');
            $status = is_array($entry) && array_key_exists('status', $entry) ? $entry['status'] : (!empty($entry));

            $uptime_labels[] = $label;

            if ($status === false) {
                $uptime_values[] = 0;
                $uptime_colors[] = $palette['red'];
            } elseif ($status === true) {
                $uptime_values[] = 100;
                $uptime_colors[] = $palette['green'];
            } else {
                $uptime_values[] = 50;
                $uptime_colors[] = $palette['grey'];
            }
        }

        $uptime_chart = [
            'type'     => 'bar',
            'labels'   => $uptime_labels,
            'datasets' => [
                [
                    'data'            => $uptime_values,
                    'backgroundColor' => $uptime_colors,
                    'borderWidth'     => 0,
                    'borderRadius'    => 6,
                ],
            ],
            'empty'    => empty($uptime_labels),
            'status'   => $uptime_status,
            'unit'     => __('%', 'sitepulse'),
        ];

        $charts_payload['uptime'] = $uptime_chart;
        $context['modules']['uptime']['card'] = [
            'status'     => $uptime_status,
            'percentage' => $uptime_percentage,
        ];
        $context['modules']['uptime']['chart'] = $uptime_chart;
    }

    if ($is_database_enabled) {
        $revisions = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
        $default_revision_limit = defined('SITEPULSE_DEFAULT_REVISION_LIMIT') ? (int) SITEPULSE_DEFAULT_REVISION_LIMIT : 100;

        if (function_exists('sitepulse_get_revision_limit')) {
            $revision_limit = (int) sitepulse_get_revision_limit();
        } else {
            $revision_option_key = defined('SITEPULSE_OPTION_REVISION_LIMIT') ? SITEPULSE_OPTION_REVISION_LIMIT : 'sitepulse_revision_limit';
            $stored_limit = get_option($revision_option_key, $default_revision_limit);
            $revision_limit = is_scalar($stored_limit) ? (int) $stored_limit : $default_revision_limit;
        }

        if ($revision_limit < 1) {
            $revision_limit = $default_revision_limit;
        }

        $revision_warn_threshold = (int) floor($revision_limit * 0.5);
        if ($revision_warn_threshold < 1) {
            $revision_warn_threshold = 1;
        }

        if ($revision_warn_threshold >= $revision_limit) {
            $revision_warn_threshold = max(1, $revision_limit - 1);
        }

        if ($revisions > $revision_limit) {
            $db_status = 'status-bad';
        } elseif ($revisions > $revision_warn_threshold) {
            $db_status = 'status-warn';
        } else {
            $db_status = 'status-ok';
        }

        $database_chart = [
            'type'     => 'doughnut',
            'labels'   => [],
            'datasets' => [],
            'empty'    => false,
            'status'   => $db_status,
            'value'    => $revisions,
            'limit'    => $revision_limit,
        ];

        if ($revisions <= $revision_limit) {
            $database_chart['labels'] = [
                __('Stored revisions', 'sitepulse'),
                __('Remaining before cleanup', 'sitepulse'),
            ];
            $database_chart['datasets'][] = [
                'data' => [
                    $revisions,
                    max(0, $revision_limit - $revisions),
                ],
                'backgroundColor' => [
                    $palette['blue'],
                    $palette['grey'],
                ],
                'borderWidth' => 0,
            ];
        } else {
            $database_chart['labels'] = [
                __('Recommended maximum', 'sitepulse'),
                __('Excess revisions', 'sitepulse'),
            ];
            $database_chart['datasets'][] = [
                'data' => [
                    $revision_limit,
                    $revisions - $revision_limit,
                ],
                'backgroundColor' => [
                    $palette['amber'],
                    $palette['red'],
                ],
                'borderWidth' => 0,
            ];
        }

        $charts_payload['database'] = $database_chart;
        $context['modules']['database']['card'] = [
            'status'    => $db_status,
            'revisions' => $revisions,
            'limit'     => $revision_limit,
        ];
        $context['modules']['database']['chart'] = $database_chart;
    }

    if ($is_logs_enabled) {
        $log_snapshot = sitepulse_custom_dashboard_analyze_debug_log();
        $log_chart    = isset($log_snapshot['chart']) ? $log_snapshot['chart'] : [];

        if (!empty($log_chart['datasets']) && isset($log_chart['datasets'][0]) && is_array($log_chart['datasets'][0])) {
            $log_chart['datasets'][0]['backgroundColor'] = [
                $palette['red'],
                $palette['amber'],
                $palette['blue'],
                $palette['purple'],
            ];
        }

        $charts_payload['logs'] = $log_chart;
        $context['modules']['logs']['card']  = isset($log_snapshot['card']) ? $log_snapshot['card'] : null;
        $context['modules']['logs']['chart'] = $log_chart;
    }

    $get_status_meta = static function ($status) use ($status_labels, $default_status_labels) {
        if (isset($status_labels[$status])) {
            return $status_labels[$status];
        }

        if (isset($status_labels['status-warn'])) {
            return $status_labels['status-warn'];
        }

        return $default_status_labels['status-warn'];
    };

    $module_chart_keys = [
        'speed_analyzer'     => 'speed',
        'uptime_tracker'     => 'uptime',
        'database_optimizer' => 'database',
        'log_analyzer'       => 'logs',
    ];

    foreach ($module_chart_keys as $module_key => $chart_key) {
        if (!in_array($module_key, $active_modules, true) || !isset($charts_payload[$chart_key])) {
            unset($charts_payload[$chart_key]);
        }
    }

    $context['charts_payload'] = $charts_payload;

    return $context;
}
