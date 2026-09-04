<?php
/**
 * SitePulse Plugin Impact history and trends.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

function sitepulse_plugin_impact_get_measurements() {
    if (!defined('SITEPULSE_PLUGIN_IMPACT_OPTION')) {
        return [
            'last_updated' => 0,
            'interval'     => defined('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL') ? SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL : 15 * MINUTE_IN_SECONDS,
            'samples'      => [],
        ];
    }

    $data = get_option(SITEPULSE_PLUGIN_IMPACT_OPTION, []);

    if (!is_array($data)) {
        $data = [];
    }

    $default_interval = defined('SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL') ? SITEPULSE_PLUGIN_IMPACT_REFRESH_INTERVAL : 15 * MINUTE_IN_SECONDS;

    return [
        'last_updated' => isset($data['last_updated']) ? (int) $data['last_updated'] : 0,
        'interval'     => isset($data['interval']) ? max(1, (int) $data['interval']) : $default_interval,
        'samples'      => isset($data['samples']) && is_array($data['samples']) ? $data['samples'] : [],
    ];
}

/**
 * Retrieves the persisted plugin impact history.
 *
 * @return array<string,mixed>
 */
function sitepulse_plugin_impact_get_history() {
    if (!defined('SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY')) {
        return [
            'updated_at' => 0,
            'plugins'    => [],
        ];
    }

    $stored = get_option(SITEPULSE_OPTION_PLUGIN_IMPACT_HISTORY, []);

    if (!is_array($stored)) {
        $stored = [];
    }

    $updated_at = isset($stored['updated_at']) ? (int) $stored['updated_at'] : 0;
    $plugins = [];

    if (isset($stored['plugins']) && is_array($stored['plugins'])) {
        foreach ($stored['plugins'] as $plugin_file => $entries) {
            if (!is_string($plugin_file) || $plugin_file === '' || !is_array($entries)) {
                continue;
            }

            $normalized = [];

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                $average = isset($entry['avg_ms']) ? (float) $entry['avg_ms'] : null;

                if ($timestamp <= 0 || $average === null || !is_numeric($average)) {
                    continue;
                }

                $normalized[$timestamp] = [
                    'timestamp' => $timestamp,
                    'avg_ms'    => max(0.0, (float) $average),
                ];

                if (isset($entry['samples']) && is_numeric($entry['samples'])) {
                    $normalized[$timestamp]['samples'] = max(0, (int) $entry['samples']);
                }

                if (isset($entry['weight']) && is_numeric($entry['weight'])) {
                    $normalized[$timestamp]['weight'] = max(0.0, (float) $entry['weight']);
                }

                if (isset($entry['last_ms']) && is_numeric($entry['last_ms'])) {
                    $normalized[$timestamp]['last_ms'] = max(0.0, (float) $entry['last_ms']);
                }
            }

            if (empty($normalized)) {
                continue;
            }

            ksort($normalized);

            $plugins[$plugin_file] = array_values($normalized);
        }
    }

    return [
        'updated_at' => max(0, $updated_at),
        'plugins'    => $plugins,
    ];
}

/**
 * Calculates trend data for a plugin using history entries.
 *
 * @param array<int,array<string,float|int>> $history_entries Sorted history entries.
 * @param float|null                         $current_average Latest average in milliseconds.
 * @param int                                $current_time    Current timestamp.
 *
 * @return array<string,mixed>
 */
function sitepulse_plugin_impact_calculate_trend(array $history_entries, $current_average, $current_time) {
    $entry_count = count($history_entries);

    if (0 === $entry_count) {
        return [
            'direction'   => 'none',
            'change_ms'   => null,
            'change_pct'  => null,
            'previous'    => null,
            'average_7d'  => null,
            'average_30d' => null,
        ];
    }

    $latest = $history_entries[$entry_count - 1];
    $previous = $entry_count > 1 ? $history_entries[$entry_count - 2] : null;

    $latest_avg = isset($latest['avg_ms']) ? (float) $latest['avg_ms'] : null;
    $previous_avg = ($previous !== null && isset($previous['avg_ms'])) ? (float) $previous['avg_ms'] : null;

    if ($current_average !== null && is_numeric($current_average)) {
        $latest_avg = (float) $current_average;
    }

    $change_ms = null;
    $change_pct = null;
    $direction = 'none';

    if ($latest_avg !== null && $previous_avg !== null) {
        $change_ms = $latest_avg - $previous_avg;

        if (abs($change_ms) < 0.01) {
            $change_ms = 0.0;
        }

        if (abs($previous_avg) > 0.0001) {
            $change_pct = ($change_ms / $previous_avg) * 100;
        }

        if ($change_ms > 0.0) {
            $direction = 'up';
        } elseif ($change_ms < 0.0) {
            $direction = 'down';
        } else {
            $direction = 'flat';
        }
    }

    $seven_days_ago = $current_time - (7 * DAY_IN_SECONDS);
    $thirty_days_ago = $current_time - (30 * DAY_IN_SECONDS);

    $average_7d = sitepulse_plugin_impact_average_window($history_entries, $seven_days_ago);
    $average_30d = sitepulse_plugin_impact_average_window($history_entries, $thirty_days_ago);

    return [
        'direction'   => $direction,
        'change_ms'   => $change_ms,
        'change_pct'  => $change_pct,
        'previous'    => $previous_avg,
        'average_7d'  => $average_7d,
        'average_30d' => $average_30d,
    ];
}

/**
 * Computes the rolling average of the provided history entries after a cutoff.
 *
 * @param array<int,array<string,float|int>> $history_entries History entries.
 * @param int                                $cutoff          Minimum timestamp to include.
 *
 * @return float|null
 */
function sitepulse_plugin_impact_average_window(array $history_entries, $cutoff) {
    $cutoff = (int) $cutoff;

    $sum = 0.0;
    $count = 0;

    foreach ($history_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;

        if ($timestamp <= 0 || $timestamp < $cutoff) {
            continue;
        }

        if (!isset($entry['avg_ms']) || !is_numeric($entry['avg_ms'])) {
            continue;
        }

        $sum += max(0.0, (float) $entry['avg_ms']);
        $count++;
    }

    if (0 === $count) {
        return null;
    }

    return $sum / $count;
}

/**
 * Formats the trend change for display.
 *
 * @param array<string,mixed> $trend Trend payload returned by {@see sitepulse_plugin_impact_calculate_trend()}.
 *
 * @return string
 */
function sitepulse_plugin_impact_format_trend_label($trend) {
    if (!is_array($trend)) {
        return '';
    }

    $direction = isset($trend['direction']) ? (string) $trend['direction'] : 'none';
    $change_ms = isset($trend['change_ms']) && is_numeric($trend['change_ms']) ? (float) $trend['change_ms'] : null;
    $change_pct = isset($trend['change_pct']) && is_numeric($trend['change_pct']) ? (float) $trend['change_pct'] : null;

    if ($change_ms === null || $direction === 'none') {
        return '';
    }

    $arrow = '→';

    if ($direction === 'up') {
        $arrow = '↑';
    } elseif ($direction === 'down') {
        $arrow = '↓';
    }

    $formatted_ms = number_format_i18n(abs($change_ms), 2);

    if ($change_pct !== null) {
        $formatted_pct = number_format_i18n(abs($change_pct), 1);

        return sprintf(
            /* translators: 1: arrow indicator, 2: delta in milliseconds, 3: delta percentage. */
            __('Variation vs précédente mesure : %1$s %2$s ms (%3$s %%).', 'sitepulse'),
            $arrow,
            $formatted_ms,
            $formatted_pct
        );
    }

    return sprintf(
        /* translators: 1: arrow indicator, 2: delta in milliseconds. */
        __('Variation vs précédente mesure : %1$s %2$s ms.', 'sitepulse'),
        $arrow,
        $formatted_ms
    );
}

function sitepulse_plugin_impact_normalize_timestamp_for_display($timestamp) {
    $timestamp = (int) $timestamp;

    if ($timestamp <= 0) {
        return 0;
    }

    $mysql_datetime = gmdate('Y-m-d H:i:s', $timestamp);

    if (function_exists('wp_timezone')) {
        $timezone = wp_timezone();

        if ($timezone instanceof DateTimeZone) {
            $date = date_create_from_format('Y-m-d H:i:s', $mysql_datetime, $timezone);

            if ($date instanceof DateTimeInterface) {
                return $date->getTimestamp();
            }
        }
    }

    $offset = (float) get_option('gmt_offset', 0);

    return $timestamp - (int) ($offset * HOUR_IN_SECONDS);
}

function sitepulse_plugin_impact_format_interval($seconds) {
    $seconds = (int) $seconds;

    if ($seconds <= 0) {
        return __('immédiatement', 'sitepulse');
    }

    if ($seconds < MINUTE_IN_SECONDS) {
        $value = max(1, $seconds);

        return sprintf(
            _n('%s seconde', '%s secondes', $value, 'sitepulse'),
            number_format_i18n($value)
        );
    }

    if ($seconds < HOUR_IN_SECONDS) {
        $minutes = max(1, (int) round($seconds / MINUTE_IN_SECONDS));

        return sprintf(
            _n('%s minute', '%s minutes', $minutes, 'sitepulse'),
            number_format_i18n($minutes)
        );
    }

    if ($seconds < DAY_IN_SECONDS) {
        $hours = max(1, (int) round($seconds / HOUR_IN_SECONDS));

        return sprintf(
            _n('%s heure', '%s heures', $hours, 'sitepulse'),
            number_format_i18n($hours)
        );
    }

    $days = max(1, (int) round($seconds / DAY_IN_SECONDS));

    return sprintf(
        _n('%s jour', '%s jours', $days, 'sitepulse'),
        number_format_i18n($days)
    );
}
