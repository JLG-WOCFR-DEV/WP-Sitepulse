<?php
/**
 * SitePulse dashboard KPI and debt formatters.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the option name used to persist operational-debt history.
 *
 * @return string
 */
function sitepulse_custom_dashboard_get_debt_history_option_name() {
    if (defined('SITEPULSE_OPTION_DASHBOARD_DEBT_HISTORY')) {
        return SITEPULSE_OPTION_DASHBOARD_DEBT_HISTORY;
    }

    return 'sitepulse_dashboard_debt_history';
}

function sitepulse_custom_dashboard_get_debt_history($max_points = 14) {
    $option_name = sitepulse_custom_dashboard_get_debt_history_option_name();
    $history = get_option($option_name, []);

    if (!is_array($history)) {
        return [];
    }

    $normalized = [];

    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $score     = isset($entry['score']) ? (float) $entry['score'] : null;

        if ($timestamp <= 0 || null === $score || $score < 0) {
            continue;
        }

        $normalized[] = [
            'timestamp' => $timestamp,
            'score'     => round($score, 2),
        ];
    }

    if (empty($normalized)) {
        return [];
    }

    usort($normalized, static function ($a, $b) {
        return $a['timestamp'] <=> $b['timestamp'];
    });

    if ($max_points > 0 && count($normalized) > $max_points) {
        $normalized = array_slice($normalized, -$max_points);
    }

    return array_values($normalized);
}

function sitepulse_custom_dashboard_store_debt_sample($score, $timestamp = null, $max_points = 28) {
    if (!is_numeric($score)) {
        return sitepulse_custom_dashboard_get_debt_history($max_points);
    }

    $timestamp = null === $timestamp ? sitepulse_custom_dashboard_get_current_timestamp() : (int) $timestamp;
    $score     = max(0.0, (float) $score);

    if ($timestamp <= 0) {
        $timestamp = sitepulse_custom_dashboard_get_current_timestamp();
    }

    $history = sitepulse_custom_dashboard_get_debt_history($max_points * 2);

    $history[] = [
        'timestamp' => $timestamp,
        'score'     => round($score, 2),
    ];

    usort($history, static function ($a, $b) {
        return $a['timestamp'] <=> $b['timestamp'];
    });

    $merged = [];
    $minimum_gap = HOUR_IN_SECONDS * 6;

    foreach ($history as $entry) {
        if (empty($merged)) {
            $merged[] = $entry;
            continue;
        }

        $last_index = count($merged) - 1;
        $last_entry = $merged[$last_index];

        if (($entry['timestamp'] - $last_entry['timestamp']) < $minimum_gap) {
            $merged[$last_index] = $entry;
        } else {
            $merged[] = $entry;
        }
    }

    $cutoff = $timestamp - (DAY_IN_SECONDS * 14);

    $merged = array_values(array_filter($merged, static function ($entry) use ($cutoff) {
        return $entry['timestamp'] >= $cutoff;
    }));

    if ($max_points > 0 && count($merged) > $max_points) {
        $merged = array_slice($merged, -$max_points);
    }

    update_option(sitepulse_custom_dashboard_get_debt_history_option_name(), $merged, false);

    return $merged;
}

function sitepulse_custom_dashboard_get_normalized_uptime_log() {
    $raw_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);

    if (function_exists('sitepulse_normalize_uptime_log')) {
        $log = sitepulse_normalize_uptime_log($raw_log);
    } elseif (is_array($raw_log)) {
        $log = [];

        foreach ($raw_log as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $status    = isset($entry['status']) ? $entry['status'] : null;

            if (null === $status) {
                $status = !empty($entry);
            }

            if ('maintenance' === $status) {
                $incident_start = null;
            } else {
                $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : null;
            }

            $log[] = array_filter([
                'timestamp'      => $timestamp,
                'status'         => $status,
                'incident_start' => $incident_start,
                'error'          => isset($entry['error']) ? (string) $entry['error'] : '',
                'agent'          => isset($entry['agent']) ? (string) $entry['agent'] : 'default',
            ], static function ($value) {
                return null !== $value;
            });
        }

        usort($log, static function ($a, $b) {
            return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
        });
    } else {
        $log = [];
    }

    return is_array($log) ? $log : [];
}

function sitepulse_custom_dashboard_collect_open_incidents(array $log, $now) {
    if (empty($log)) {
        return [];
    }

    $now = (int) $now;
    $open = [];

    foreach ($log as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $status    = $entry['status'] ?? null;
        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $agent     = isset($entry['agent']) ? (string) $entry['agent'] : 'default';

        if (function_exists('sitepulse_uptime_normalize_agent_id')) {
            $agent = sitepulse_uptime_normalize_agent_id($agent);
        } else {
            $agent = sanitize_key($agent);
        }

        if (true === $status || 'maintenance' === $status) {
            unset($open[$agent]);
            continue;
        }

        if (false === $status || 'unknown' === $status || null === $status) {
            $severity = (false === $status) ? 'critical' : 'warning';
            $incident_start = isset($entry['incident_start']) ? (int) $entry['incident_start'] : null;

            if ($incident_start === null || $incident_start <= 0) {
                $incident_start = $timestamp > 0 ? $timestamp : $now;
            }

            if (!isset($open[$agent])) {
                $open[$agent] = [
                    'agent_id'      => $agent,
                    'severity'      => $severity,
                    'status'        => false === $status ? 'down' : 'unknown',
                    'incident_start'=> $incident_start,
                    'last_seen'     => $timestamp,
                    'checks'        => 1,
                    'error'         => isset($entry['error']) ? (string) $entry['error'] : '',
                ];
            } else {
                $open[$agent]['last_seen'] = max($open[$agent]['last_seen'], $timestamp);
                $open[$agent]['checks']++;

                if ($incident_start < $open[$agent]['incident_start']) {
                    $open[$agent]['incident_start'] = $incident_start;
                }

                if ($open[$agent]['error'] === '' && !empty($entry['error'])) {
                    $open[$agent]['error'] = (string) $entry['error'];
                }

                if ('critical' === $severity) {
                    $open[$agent]['severity'] = 'critical';
                    $open[$agent]['status']   = 'down';
                }
            }

            continue;
        }

        unset($open[$agent]);
    }

    if (empty($open)) {
        return [];
    }

    $agents = function_exists('sitepulse_uptime_get_agents') ? sitepulse_uptime_get_agents() : [];

    foreach ($open as $agent_id => $incident) {
        $agent_label = ucfirst(str_replace('_', ' ', $agent_id));

        if (isset($agents[$agent_id]['label'])) {
            $agent_label = (string) $agents[$agent_id]['label'];
        }

        $open[$agent_id]['agent_label'] = $agent_label;
        $open[$agent_id]['duration']    = max(0, $now - $incident['incident_start']);
    }

    usort($open, static function ($a, $b) {
        $severity_map = ['critical' => 2, 'warning' => 1, 'ok' => 0];
        $a_severity   = $severity_map[$a['severity']] ?? 0;
        $b_severity   = $severity_map[$b['severity']] ?? 0;

        if ($a_severity === $b_severity) {
            return $a['incident_start'] <=> $b['incident_start'];
        }

        return $b_severity <=> $a_severity;
    });

    return array_values($open);
}

function sitepulse_custom_dashboard_format_sla_kpi($uptime, $incidents, $range_label) {
    if (!is_array($uptime) || !isset($uptime['uptime'])) {
        return null;
    }

    $uptime_value = isset($uptime['uptime']) ? (float) $uptime['uptime'] : null;

    if (null === $uptime_value) {
        return null;
    }

    $value_text = number_format_i18n($uptime_value, 2) . ' %';
    $total_checks = isset($uptime['totals']['total']) ? (int) $uptime['totals']['total'] : 0;
    $checks_label = sprintf(
        _n('%s contrôle analysé', '%s contrôles analysés', $total_checks, 'sitepulse'),
        number_format_i18n($total_checks)
    );

    $trend_delta = isset($uptime['trend']['uptime']) ? $uptime['trend']['uptime'] : null;
    $trend = null;

    if (is_numeric($trend_delta)) {
        $direction = 'flat';

        if ($trend_delta > 0.0001) {
            $direction = 'up';
        } elseif ($trend_delta < -0.0001) {
            $direction = 'down';
        }

        $trend_points = number_format_i18n(abs($trend_delta), 2);
        $trend_text = sprintf(
            /* translators: %s: variation in SLA points. */
            __('%1$s%2$s pts vs période précédente', 'sitepulse'),
            $direction === 'down' ? '−' : ($direction === 'up' ? '+' : ''),
            $trend_points
        );

        $trend_sr = sprintf(
            /* translators: %s: variation in SLA points. */
            __('Variation de %s point(s) sur le SLA.', 'sitepulse'),
            $trend_points
        );

        $trend = [
            'text'      => $trend_text,
            'direction' => $direction,
            'sr'        => $trend_sr,
        ];
    }

    $warning_threshold = sitepulse_custom_dashboard_get_uptime_warning_threshold();
    $critical_threshold = max(0.0, $warning_threshold - 1.5);

    $status = 'ok';

    if ($uptime_value < $critical_threshold) {
        $status = 'critical';
    } elseif ($uptime_value < $warning_threshold) {
        $status = 'warning';
    }

    if (!empty($incidents)) {
        foreach ($incidents as $incident) {
            if (isset($incident['severity']) && 'critical' === $incident['severity']) {
                $status = 'critical';
                break;
            }

            $status = 'warning';
        }
    }

    return [
        'key'    => 'sla',
        'status' => $status,
        'icon'   => 'dashicons-shield',
        'label'  => sprintf(__('SLA global (%s)', 'sitepulse'), $range_label),
        'value'  => $value_text,
        'meta'   => $checks_label,
        'trend'  => $trend,
    ];
}

/**
 * Provides a human readable interval description when WordPress helpers are unavailable.
 *
 * @param int|float $seconds Duration in seconds.
 *
 * @return string
 */
function sitepulse_custom_dashboard_format_interval_fallback($seconds) {
    $seconds = (int) round($seconds);

    if ($seconds < 1) {
        $seconds = 1;
    }

    $minute = defined('MINUTE_IN_SECONDS') ? (int) MINUTE_IN_SECONDS : 60;
    $hour   = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
    $day    = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
    $week   = defined('WEEK_IN_SECONDS') ? (int) WEEK_IN_SECONDS : ($day * 7);

    $format_number = static function ($value) {
        if (function_exists('number_format_i18n')) {
            return number_format_i18n($value);
        }

        return number_format((float) $value);
    };

    if ($seconds < $minute) {
        $value = max(1, $seconds);

        return sprintf(
            _n('%s seconde', '%s secondes', $value, 'sitepulse'),
            $format_number($value)
        );
    }

    if ($seconds < $hour) {
        $value = max(1, (int) round($seconds / $minute));

        return sprintf(
            _n('%s minute', '%s minutes', $value, 'sitepulse'),
            $format_number($value)
        );
    }

    if ($seconds < $day) {
        $value = max(1, (int) round($seconds / $hour));

        return sprintf(
            _n('%s heure', '%s heures', $value, 'sitepulse'),
            $format_number($value)
        );
    }

    if ($seconds < $week) {
        $value = max(1, (int) round($seconds / $day));

        return sprintf(
            _n('%s jour', '%s jours', $value, 'sitepulse'),
            $format_number($value)
        );
    }

    $value = max(1, (int) round($seconds / $week));

    return sprintf(
        _n('%s semaine', '%s semaines', $value, 'sitepulse'),
        $format_number($value)
    );
}

function sitepulse_custom_dashboard_format_incident_kpi($incidents, $range_label, $now) {
    $count = is_array($incidents) ? count($incidents) : 0;

    $status = 'ok';

    if ($count > 0) {
        foreach ($incidents as $incident) {
            if (isset($incident['severity']) && 'critical' === $incident['severity']) {
                $status = 'critical';
                break;
            }
        }

        if ('critical' !== $status) {
            $status = 'warning';
        }
    }

    $value = number_format_i18n($count);
    $label = __('Incidents actifs', 'sitepulse');
    $items = [];

    if ($count > 0) {
        $subset = array_slice($incidents, 0, 3);

        foreach ($subset as $incident) {
            $agent_label = isset($incident['agent_label']) ? (string) $incident['agent_label'] : __('Agent inconnu', 'sitepulse');
            $error = isset($incident['error']) ? trim((string) $incident['error']) : '';

            if ($error !== '') {
                $error = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($error) : strip_tags($error);
            }

            if ($error === '') {
                $error = ('unknown' === ($incident['status'] ?? ''))
                    ? __('Diagnostic en attente', 'sitepulse')
                    : __('Réponse HTTP inattendue', 'sitepulse');
            }

            $since_seconds = isset($incident['incident_start']) ? max(0, $now - (int) $incident['incident_start']) : 0;

            if ($since_seconds > 0) {
                $relative = function_exists('human_time_diff')
                    ? human_time_diff($now - $since_seconds, $now)
                    : sitepulse_custom_dashboard_format_interval_fallback($since_seconds);

                $since_label = sprintf(__('depuis %s', 'sitepulse'), $relative);
            } else {
                $since_label = __('début immédiat', 'sitepulse');
            }

            $items[] = [
                'label' => $agent_label,
                'description' => sprintf('%s — %s', $error, $since_label),
                'severity' => isset($incident['severity']) ? $incident['severity'] : $status,
            ];
        }
    }

    $empty_message = __('Aucun incident actif.', 'sitepulse');

    return [
        'key'           => 'incidents',
        'status'        => $status,
        'icon'          => 'dashicons-warning',
        'label'         => $label,
        'value'         => $value,
        'items'         => $items,
        'empty_message' => $empty_message,
        'meta'          => sprintf(__('Fenêtre : %s', 'sitepulse'), $range_label),
    ];
}

function sitepulse_custom_dashboard_calculate_operational_debt_snapshot($queue_overview, $ai_summary, $now) {
    $metrics = [];

    if (is_array($queue_overview) && isset($queue_overview['metrics'])) {
        $metrics = $queue_overview['metrics'];
    }

    $queue_length     = isset($metrics['queue_length']) ? (int) $metrics['queue_length'] : 0;
    $prioritized_jobs = isset($metrics['prioritized_jobs']) ? (int) $metrics['prioritized_jobs'] : 0;
    $delayed_jobs     = isset($metrics['delayed_jobs']) ? (int) $metrics['delayed_jobs'] : 0;
    $avg_priority     = isset($metrics['avg_priority']) ? (int) $metrics['avg_priority'] : 0;
    $max_wait         = isset($metrics['max_wait_seconds']) ? (int) $metrics['max_wait_seconds'] : 0;

    $queue_score = $queue_length;

    if ($prioritized_jobs > 0) {
        $queue_score += $prioritized_jobs * max(2, $avg_priority);
    }

    if ($delayed_jobs > 0) {
        $queue_score += $delayed_jobs * 1.5;
    }

    if ($max_wait > 900) {
        $queue_score += ceil($max_wait / 300);
    }

    $ai_pending = isset($ai_summary['recent_pending']) ? (int) $ai_summary['recent_pending'] : 0;
    $ai_stale   = isset($ai_summary['stale_pending']) ? (int) $ai_summary['stale_pending'] : 0;
    $ai_score   = ($ai_pending * 2) + ($ai_stale * 3);

    $score = round($queue_score + $ai_score, 1);

    $history = sitepulse_custom_dashboard_store_debt_sample($score, $now);

    return [
        'score'   => $score,
        'queue'   => [
            'length'     => $queue_length,
            'prioritized'=> $prioritized_jobs,
            'delayed'    => $delayed_jobs,
            'max_wait'   => $max_wait,
        ],
        'ai'      => [
            'pending' => $ai_pending,
            'stale'   => $ai_stale,
        ],
        'history' => $history,
    ];
}

function sitepulse_custom_dashboard_format_debt_kpi($snapshot, $range_label, $now) {
    if (!is_array($snapshot) || !isset($snapshot['score'])) {
        return null;
    }

    $score = (float) $snapshot['score'];
    $score_text = sprintf(
        _n('%s point', '%s points', round($score), 'sitepulse'),
        number_format_i18n($score, 1)
    );

    $queue = isset($snapshot['queue']) && is_array($snapshot['queue']) ? $snapshot['queue'] : [];
    $ai    = isset($snapshot['ai']) && is_array($snapshot['ai']) ? $snapshot['ai'] : [];

    $summary_parts = [];

    $queue_length = isset($queue['length']) ? (int) $queue['length'] : 0;
    $prioritized  = isset($queue['prioritized']) ? (int) $queue['prioritized'] : 0;
    $ai_pending   = isset($ai['pending']) ? (int) $ai['pending'] : 0;
    $ai_stale     = isset($ai['stale']) ? (int) $ai['stale'] : 0;

    $summary_parts[] = sprintf(
        _n('File d’attente : %s tâche', 'File d’attente : %s tâches', $queue_length, 'sitepulse'),
        number_format_i18n($queue_length)
    );

    if ($prioritized > 0) {
        $summary_parts[] = sprintf(
            _n('%s intervention prioritaire', '%s interventions prioritaires', $prioritized, 'sitepulse'),
            number_format_i18n($prioritized)
        );
    }

    $ai_total = $ai_pending + $ai_stale;

    $summary_parts[] = sprintf(
        _n('IA : %s action en attente', 'IA : %s actions en attente', $ai_total, 'sitepulse'),
        number_format_i18n($ai_total)
    );

    $summary = implode(' • ', $summary_parts);

    $status = 'ok';

    if ($score >= 40) {
        $status = 'critical';
    } elseif ($score >= 15) {
        $status = 'warning';
    }

    $history = isset($snapshot['history']) && is_array($snapshot['history'])
        ? $snapshot['history']
        : sitepulse_custom_dashboard_get_debt_history();

    $history = array_values(array_filter($history, static function ($entry) {
        return isset($entry['timestamp'], $entry['score']);
    }));

    $sparkline = [];
    $sparkline_sr = '';
    $trend = null;

    if (!empty($history)) {
        usort($history, static function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        $window = count($history) > 7 ? array_slice($history, -7) : $history;
        $max_value = max(array_map(static function ($entry) {
            return (float) $entry['score'];
        }, $window));

        if ($max_value <= 0) {
            $max_value = 1;
        }

        $date_format = get_option('date_format');

        foreach ($window as $entry) {
            $label = $entry['timestamp'] > 0
                ? (function_exists('wp_date') ? wp_date($date_format, $entry['timestamp']) : date_i18n($date_format, $entry['timestamp']))
                : '';

            $sparkline[] = [
                'value'    => (float) $entry['score'],
                'relative' => max(0.0, min(1.0, $entry['score'] / $max_value)),
                'label'    => $label,
            ];
        }

        $first = reset($window);
        $last  = end($window);

        if ($first && $last) {
            $baseline = (float) $first['score'];
            $latest   = (float) $last['score'];

            if ($baseline > 0) {
                $percent = (($latest - $baseline) / $baseline) * 100;

                $direction = 'flat';
                if ($percent > 0.5) {
                    $direction = 'up';
                } elseif ($percent < -0.5) {
                    $direction = 'down';
                }

                $trend = [
                    'text'      => sprintf('%s%s %% sur 7 j', $direction === 'down' ? '−' : ($direction === 'up' ? '+' : ''), number_format_i18n(abs($percent), 1)),
                    'direction' => $direction,
                    'sr'        => sprintf(__('Variation de %s %% de la dette sur 7 jours.', 'sitepulse'), number_format_i18n(abs($percent), 1)),
                ];
            } elseif ($latest > 0) {
                $trend = [
                    'text'      => __('Dette apparue', 'sitepulse'),
                    'direction' => 'up',
                    'sr'        => __('Nouvelle dette opérationnelle enregistrée cette semaine.', 'sitepulse'),
                ];
            }

            $sparkline_sr = sprintf(
                __('Dette opérationnelle entre %1$s et %2$s points sur 7 jours.', 'sitepulse'),
                number_format_i18n(min(array_map(static function ($entry) {
                    return (float) $entry['score'];
                }, $window)), 1),
                number_format_i18n($max_value, 1)
            );
        }
    }

    return [
        'key'          => 'debt',
        'status'       => $status,
        'icon'         => 'dashicons-portfolio',
        'label'        => __('Dette opérationnelle', 'sitepulse'),
        'value'        => $score_text,
        'summary'      => $summary,
        'trend'        => $trend,
        'sparkline'    => $sparkline,
        'sparkline_sr' => $sparkline_sr,
        'meta'         => sprintf(__('Fenêtre : %s', 'sitepulse'), $range_label),
    ];
}

function sitepulse_custom_dashboard_build_kpi_cards($payload, $range_label, $now = null) {
    $now = null === $now ? sitepulse_custom_dashboard_get_current_timestamp() : (int) $now;
    $kpis = [];

    $uptime    = isset($payload['uptime']) ? $payload['uptime'] : null;
    $incidents = isset($payload['incidents']) && is_array($payload['incidents']) ? $payload['incidents'] : [];
    $ai_summary = isset($payload['ai_summary']) && is_array($payload['ai_summary']) ? $payload['ai_summary'] : [];
    $queue_overview = isset($payload['remote_queue']) ? $payload['remote_queue'] : null;

    $sla_card = sitepulse_custom_dashboard_format_sla_kpi($uptime, $incidents, $range_label);

    if (is_array($sla_card)) {
        $kpis[] = $sla_card;
    }

    $incidents_card = sitepulse_custom_dashboard_format_incident_kpi($incidents, $range_label, $now);

    if (is_array($incidents_card)) {
        $kpis[] = $incidents_card;
    }

    $debt_snapshot = sitepulse_custom_dashboard_calculate_operational_debt_snapshot($queue_overview, $ai_summary, $now);
    $debt_card = sitepulse_custom_dashboard_format_debt_kpi($debt_snapshot, $range_label, $now);

    if (is_array($debt_card)) {
        $kpis[] = $debt_card;
    }

    return $kpis;
}

/**
 * Builds the contextual status banner based on the current metrics.
 *
 * @param array<string,array<string,mixed>> $cards   Formatted cards indexed by key.
 * @param array<string,mixed>               $payload Raw payload data.
 * @param string                            $range_label Human-readable range label.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_status_banner($cards, $payload, $range_label) {
    $tone    = 'ok';
    $icon    = '✅';
    $message = sprintf(__('All systems operational for %s.', 'sitepulse'), $range_label);
    $sr      = $message;
    $cta     = [
        'label' => '',
        'url'   => '',
        'data'  => '',
    ];

    $uptime_card     = isset($cards['uptime']) ? $cards['uptime'] : null;
    $logs_card       = isset($cards['logs']) ? $cards['logs'] : null;
    $speed_card      = isset($cards['speed']) ? $cards['speed'] : null;
    $experience_card = isset($cards['experience']) ? $cards['experience'] : null;

    if (is_array($uptime_card) && empty($uptime_card['inactive'])) {
        $uptime_status = isset($uptime_card['status']['class']) ? $uptime_card['status']['class'] : 'status-ok';

        if ('status-bad' === $uptime_status) {
            $tone = 'danger';
            $icon = '🚨';
            $violations = isset($payload['uptime']['violations']) ? (int) $payload['uptime']['violations'] : 0;
            $down_checks = isset($payload['uptime']['totals']['down']) ? (int) $payload['uptime']['totals']['down'] : 0;
            $incident_count = $violations > 0 ? $violations : $down_checks;

            if ($incident_count > 0) {
                $message = sprintf(
                    _n('🚨 %1$d incident detected over %2$s.', '🚨 %1$d incidents detected over %2$s.', $incident_count, 'sitepulse'),
                    $incident_count,
                    $range_label
                );
                $sr = sprintf(
                    _n('%1$d incident detected during the selected window of %2$s.', '%1$d incidents detected during the selected window of %2$s.', $incident_count, 'sitepulse'),
                    $incident_count,
                    $range_label
                );
            } else {
                $message = sprintf(__('🚨 Availability is below target for %s.', 'sitepulse'), $range_label);
                $sr = sprintf(__('Availability is below target for %s.', 'sitepulse'), $range_label);
            }

            $cta = [
                'label' => __('Review uptime incidents', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-uptime'),
                'data'  => 'incident-playbook',
            ];
        } elseif ('status-warn' === $uptime_status) {
            $tone = 'warning';
            $icon = '⚠️';
            $message = sprintf(__('⚠️ Availability dipped during %s.', 'sitepulse'), $range_label);
            $sr = sprintf(__('Availability dipped during %s.', 'sitepulse'), $range_label);
            $cta = [
                'label' => __('Open uptime details', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-uptime'),
                'data'  => 'incident-playbook',
            ];
        }
    }

    if (is_array($logs_card) && empty($logs_card['inactive'])) {
        $logs_status = isset($logs_card['status']['class']) ? $logs_card['status']['class'] : 'status-ok';

        if ('status-bad' === $logs_status) {
            $tone = 'danger';
            $icon = '🚨';
            $message = __('🚨 Fatal errors detected in debug.log.', 'sitepulse');
            $sr = __('Fatal errors detected in the debug log. Immediate attention required.', 'sitepulse');
            $cta = [
                'label' => __('Inspect the error log', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-logs'),
                'data'  => 'incident-playbook',
            ];
        } elseif ('status-warn' === $logs_status && 'danger' !== $tone) {
            $tone = 'warning';
            $icon = '⚠️';
            $message = __('⚠️ Warnings present in the debug log.', 'sitepulse');
            $sr = __('Warnings present in the debug log.', 'sitepulse');
            $cta = [
                'label' => __('Review log warnings', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-logs'),
                'data'  => 'incident-playbook',
            ];
        }
    }

    if (is_array($speed_card) && empty($speed_card['inactive'])) {
        $speed_status = isset($speed_card['status']['class']) ? $speed_card['status']['class'] : 'status-ok';

        if ('status-bad' === $speed_status && 'danger' !== $tone) {
            $tone = 'danger';
            $icon = '🚨';
            $message = __('🚨 Backend processing time exceeds the critical threshold.', 'sitepulse');
            $sr = __('Backend processing time exceeds the critical threshold.', 'sitepulse');
            $cta = [
                'label' => __('Investigate speed scans', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-speed'),
                'data'  => 'performance-playbook',
            ];
        } elseif ('status-warn' === $speed_status && 'danger' !== $tone && 'warning' !== $tone) {
            $tone = 'warning';
            $icon = '⚠️';
            $message = __('⚠️ Backend speed is approaching the warning threshold.', 'sitepulse');
            $sr = __('Backend speed is approaching the warning threshold.', 'sitepulse');
            $cta = [
                'label' => __('Open speed analyzer', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-speed'),
                'data'  => 'performance-playbook',
            ];
        }
    }

    if (is_array($experience_card) && empty($experience_card['inactive'])) {
        $experience_status = isset($experience_card['status']['class']) ? $experience_card['status']['class'] : 'status-ok';

        if ('status-bad' === $experience_status && 'danger' !== $tone) {
            $tone = 'danger';
            $icon = '🚨';
            $message = __('🚨 Real user experience is in the red.', 'sitepulse');
            $sr = __('Real user monitoring indicates a critical degradation.', 'sitepulse');
            $cta = [
                'label' => __('Inspect real user metrics', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-speed'),
                'data'  => 'performance-playbook',
            ];
        } elseif ('status-warn' === $experience_status && 'danger' !== $tone && 'warning' !== $tone) {
            $tone = 'warning';
            $icon = '⚠️';
            $message = __('⚠️ Real user metrics require attention.', 'sitepulse');
            $sr = __('Real user metrics require attention.', 'sitepulse');
            $cta = [
                'label' => __('Review Web Vitals', 'sitepulse'),
                'url'   => admin_url('admin.php?page=sitepulse-speed'),
                'data'  => 'performance-playbook',
            ];
        }
    }

    $kpis = sitepulse_custom_dashboard_build_kpi_cards($payload, $range_label);

    return [
        'tone'    => $tone,
        'icon'    => $icon,
        'message' => $message,
        'sr'      => $sr,
        'cta'     => $cta,
        'kpis'    => $kpis,
    ];
}

/**
 * Builds the formatted representation of the metrics payload for UI rendering.
 *
 * @param array<string,mixed> $payload Raw payload data.
 *
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_metrics_view($payload) {
    $range = isset($payload['range']) ? (string) $payload['range'] : sitepulse_custom_dashboard_get_default_range();
    $available_ranges = isset($payload['available_ranges']) && is_array($payload['available_ranges'])
        ? $payload['available_ranges']
        : array_values(sitepulse_custom_dashboard_get_metric_ranges());
    $modules = isset($payload['modules']) && is_array($payload['modules']) ? $payload['modules'] : [];

    $range_label = sitepulse_custom_dashboard_resolve_range_label($range, $available_ranges);
    $generated_at = isset($payload['generated_at']) ? (int) $payload['generated_at'] : sitepulse_custom_dashboard_get_current_timestamp();

    if ($generated_at <= 0) {
        $generated_at = sitepulse_custom_dashboard_get_current_timestamp();
    }

    $date_format = get_option('date_format');
    $time_format = get_option('time_format');
    $generated_label = function_exists('wp_date')
        ? wp_date($date_format . ' ' . $time_format, $generated_at)
        : date_i18n($date_format . ' ' . $time_format, $generated_at);

    $cards = [
        'impact' => sitepulse_custom_dashboard_format_impact_card_view(
            isset($payload['impact']) ? $payload['impact'] : null,
            $range_label
        ),
        'uptime' => sitepulse_custom_dashboard_format_uptime_card_view(
            isset($payload['uptime']) ? $payload['uptime'] : null,
            !empty($modules['uptime_tracker']),
            $range_label
        ),
        'logs'   => sitepulse_custom_dashboard_format_log_card_view(
            isset($payload['logs']) ? $payload['logs'] : null,
            !empty($modules['log_analyzer'])
        ),
        'speed'  => sitepulse_custom_dashboard_format_speed_card_view(
            isset($payload['speed']) ? $payload['speed'] : null,
            !empty($modules['speed_analyzer']),
            $range_label
        ),
        'experience' => sitepulse_custom_dashboard_format_rum_card_view(
            isset($payload['rum']) ? $payload['rum'] : null,
            !empty($modules['speed_analyzer']),
            !empty($modules['rum']),
            $range_label
        ),
    ];

    $cards = array_filter($cards, 'is_array');

    $banner = sitepulse_custom_dashboard_format_status_banner($cards, $payload, $range_label);

    $health = function_exists('sitepulse_custom_dashboard_format_health_view')
        ? sitepulse_custom_dashboard_format_health_view(
            isset($payload['impact']) ? $payload['impact'] : null,
            $range_label
        )
        : null;

    $playbooks = function_exists('sitepulse_custom_dashboard_build_playbooks')
        ? sitepulse_custom_dashboard_build_playbooks($cards, $payload)
        : [];

    $sla = function_exists('sitepulse_custom_dashboard_format_sla_action')
        ? sitepulse_custom_dashboard_format_sla_action($payload)
        : null;

    return [
        'range'           => $range,
        'range_label'     => $range_label,
        'generated_at'    => $generated_at,
        'generated_label' => $generated_label,
        'generated_text'  => $generated_label !== ''
            ? sprintf(__('Updated %s.', 'sitepulse'), $generated_label)
            : __('Updated just now.', 'sitepulse'),
        'cards'           => $cards,
        'banner'          => $banner,
        'health'          => $health,
        'playbooks'       => $playbooks,
        'sla'             => $sla,
        'modules'         => $modules,
    ];
}
