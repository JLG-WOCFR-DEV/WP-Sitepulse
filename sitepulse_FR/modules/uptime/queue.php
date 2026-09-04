<?php
/**
 * SitePulse Uptime remote queue and cron scheduling.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the maximum number of items allowed in the remote queue.
 *
 * @return int
 */
function sitepulse_uptime_get_remote_queue_max_size() {
    $default = defined('SITEPULSE_UPTIME_REMOTE_QUEUE_MAX_SIZE')
        ? (int) SITEPULSE_UPTIME_REMOTE_QUEUE_MAX_SIZE
        : 200;

    /**
     * Filters the maximum number of queued remote uptime requests.
     *
     * @param int $max_size Queue size limit (0 disables the limit).
     */
    $max_size = apply_filters('sitepulse_uptime_remote_queue_max_size', $default);

    return max(0, (int) $max_size);
}

/**
 * Returns the retention duration for remote queue items.
 *
 * @return int
 */
function sitepulse_uptime_get_remote_queue_item_ttl() {
    $default = defined('SITEPULSE_UPTIME_REMOTE_QUEUE_ITEM_TTL')
        ? (int) SITEPULSE_UPTIME_REMOTE_QUEUE_ITEM_TTL
        : DAY_IN_SECONDS;

    /**
     * Filters the retention duration (in seconds) for queued remote requests.
     *
     * @param int $ttl Retention duration (0 disables pruning by age).
     */
    $ttl = apply_filters('sitepulse_uptime_remote_queue_item_ttl', $default);

    return max(0, (int) $ttl);
}

/**
 * Returns the default metrics payload used when instrumenting the remote queue.
 *
 * @param int $now Timestamp used for calculations.
 * @param int $ttl Configured TTL for queue items.
 * @param int $max_size Maximum number of entries allowed in the queue.
 * @return array<string,int|null>
 */
function sitepulse_uptime_get_default_queue_metrics($now, $ttl, $max_size) {
    return [
        'requested'          => 0,
        'retained'           => 0,
        'dropped_invalid'    => 0,
        'dropped_expired'    => 0,
        'dropped_duplicates' => 0,
        'dropped_overflow'   => 0,
        'queue_length'       => 0,
        'delayed_jobs'       => 0,
        'max_wait_seconds'   => 0,
        'avg_wait_seconds'   => 0,
        'max_priority'       => 0,
        'avg_priority'       => 0,
        'prioritized_jobs'   => 0,
        'next_scheduled_at'  => null,
        'oldest_created_at'  => null,
        'limit_ttl'          => (int) $ttl,
        'limit_size'         => (int) $max_size,
        'evaluated_at'       => (int) $now,
    ];
}

/**
 * Stores the latest remote queue metrics and fires an action for observers.
 *
 * @param array<string,int|null> $metrics Metrics payload.
 * @return void
 */
function sitepulse_uptime_record_queue_metrics($metrics) {
    if (!is_array($metrics)) {
        return;
    }

    $metrics = array_merge(sitepulse_uptime_get_default_queue_metrics((int) current_time('timestamp', true), 0, 0), $metrics);

    $payload = [
        'updated_at' => (int) current_time('timestamp', true),
        'metrics'    => $metrics,
    ];

    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS, $payload, false);

    /**
     * Fires once the remote queue metrics have been updated.
     *
     * @param array<string,mixed> $payload Recorded metrics payload.
     */
    do_action('sitepulse_uptime_remote_queue_metrics_recorded', $payload);
}

/**
 * Retrieves the latest stored remote queue metrics.
 *
 * @return array<string,mixed>
 */
function sitepulse_uptime_get_remote_queue_metrics() {
    $payload = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS, []);

    $now = (int) current_time('timestamp', true);
    $defaults = sitepulse_uptime_get_default_queue_metrics($now, sitepulse_uptime_get_remote_queue_item_ttl(), sitepulse_uptime_get_remote_queue_max_size());

    if (!is_array($payload)) {
        return [
            'updated_at' => 0,
            'metrics'    => $defaults,
        ];
    }

    $metrics = isset($payload['metrics']) && is_array($payload['metrics'])
        ? array_merge($defaults, $payload['metrics'])
        : $defaults;

    return [
        'updated_at' => isset($payload['updated_at']) ? (int) $payload['updated_at'] : 0,
        'metrics'    => $metrics,
    ];
}

/**
 * Formats a duration into a translated, human friendly string.
 *
 * @param float|int|null $seconds Duration in seconds.
 * @return string
 */
function sitepulse_uptime_format_duration_i18n($seconds) {
    if (null === $seconds || !is_numeric($seconds) || $seconds < 0) {
        return '—';
    }

    $seconds = (float) $seconds;

    if ($seconds < 1) {
        return __('moins d’une seconde', 'sitepulse');
    }

    if ($seconds < 60) {
        $count = max(1, (int) round($seconds));

        return sprintf(
            _n('%s seconde', '%s secondes', $count, 'sitepulse'),
            number_format_i18n($count)
        );
    }

    $minutes = floor($seconds / 60);

    if ($minutes < 60) {
        return sprintf(
            _n('%s minute', '%s minutes', $minutes, 'sitepulse'),
            number_format_i18n($minutes)
        );
    }

    $hours = floor($minutes / 60);

    if ($hours < 48) {
        return sprintf(
            _n('%s heure', '%s heures', $hours, 'sitepulse'),
            number_format_i18n($hours)
        );
    }

    $days = floor($hours / 24);

    return sprintf(
        _n('%s jour', '%s jours', $days, 'sitepulse'),
        number_format_i18n($days)
    );
}

/**
 * Formats a timestamp relative to another reference timestamp.
 *
 * @param int|null $timestamp         Timestamp to format.
 * @param int      $current_timestamp Reference timestamp.
 * @return string
 */
function sitepulse_uptime_format_relative_time($timestamp, $current_timestamp) {
    if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
        return '';
    }

    $timestamp = (int) $timestamp;
    $current_timestamp = (int) $current_timestamp;

    if ($timestamp >= $current_timestamp) {
        $difference = human_time_diff($current_timestamp, $timestamp);

        return sprintf(
            __('dans %s', 'sitepulse'),
            $difference
        );
    }

    $difference = human_time_diff($timestamp, $current_timestamp);

    return sprintf(
        __('il y a %s', 'sitepulse'),
        $difference
    );
}

/**
 * Aggregates remote queue metrics into a health summary and formatted labels.
 *
 * @param array<string,mixed>|null $payload           Optional metrics payload returned by
 *                                                    sitepulse_uptime_get_remote_queue_metrics().
 * @param int|null                 $current_timestamp Reference timestamp for relative calculations.
 * @return array<string,mixed>
 */
function sitepulse_uptime_analyze_remote_queue($payload = null, $current_timestamp = null) {
    if (null === $current_timestamp) {
        $current_timestamp = (int) current_time('timestamp');
    } else {
        $current_timestamp = (int) $current_timestamp;
    }

    if (null === $payload) {
        $payload = sitepulse_uptime_get_remote_queue_metrics();
    }

    $default_metrics = sitepulse_uptime_get_default_queue_metrics(
        $current_timestamp,
        sitepulse_uptime_get_remote_queue_item_ttl(),
        sitepulse_uptime_get_remote_queue_max_size()
    );

    $raw_metrics = [];

    if (is_array($payload) && isset($payload['metrics']) && is_array($payload['metrics'])) {
        $raw_metrics = $payload['metrics'];
    }

    $metrics = array_merge($default_metrics, $raw_metrics);

    $sanitized = [
        'requested'          => max(0, (int) ($metrics['requested'] ?? 0)),
        'retained'           => max(0, (int) ($metrics['retained'] ?? 0)),
        'dropped_invalid'    => max(0, (int) ($metrics['dropped_invalid'] ?? 0)),
        'dropped_expired'    => max(0, (int) ($metrics['dropped_expired'] ?? 0)),
        'dropped_duplicates' => max(0, (int) ($metrics['dropped_duplicates'] ?? 0)),
        'dropped_overflow'   => max(0, (int) ($metrics['dropped_overflow'] ?? 0)),
        'queue_length'       => max(0, (int) ($metrics['queue_length'] ?? 0)),
        'delayed_jobs'       => max(0, (int) ($metrics['delayed_jobs'] ?? 0)),
        'max_wait_seconds'   => max(0, (int) ($metrics['max_wait_seconds'] ?? 0)),
        'avg_wait_seconds'   => max(0, (int) ($metrics['avg_wait_seconds'] ?? 0)),
        'max_priority'       => isset($metrics['max_priority']) ? (int) $metrics['max_priority'] : 0,
        'avg_priority'       => isset($metrics['avg_priority']) ? (int) $metrics['avg_priority'] : 0,
        'prioritized_jobs'   => max(0, (int) ($metrics['prioritized_jobs'] ?? 0)),
        'next_scheduled_at'  => isset($metrics['next_scheduled_at']) && (int) $metrics['next_scheduled_at'] > 0
            ? (int) $metrics['next_scheduled_at']
            : null,
        'oldest_created_at'  => isset($metrics['oldest_created_at']) && (int) $metrics['oldest_created_at'] > 0
            ? (int) $metrics['oldest_created_at']
            : null,
        'limit_ttl'          => max(0, (int) ($metrics['limit_ttl'] ?? 0)),
        'limit_size'         => max(0, (int) ($metrics['limit_size'] ?? 0)),
    ];

    $sanitized['dropped_total'] = $sanitized['dropped_invalid']
        + $sanitized['dropped_expired']
        + $sanitized['dropped_duplicates']
        + $sanitized['dropped_overflow'];

    $updated_at = 0;

    if (is_array($payload) && isset($payload['updated_at'])) {
        $updated_at = (int) $payload['updated_at'];
    }

    $usage_ratio = null;

    if ($sanitized['limit_size'] > 0) {
        $usage_ratio = $sanitized['queue_length'] / $sanitized['limit_size'];
    }

    $queue_status_priorities = [
        'ok'       => 0,
        'warning'  => 1,
        'critical' => 2,
    ];

    $queue_status = 'ok';
    $alerts = [];

    $queue_status_promote = static function ($level) use (&$queue_status, $queue_status_priorities) {
        if (!isset($queue_status_priorities[$level])) {
            return;
        }

        if ($queue_status_priorities[$level] > $queue_status_priorities[$queue_status]) {
            $queue_status = $level;
        }
    };

    $register_alert = static function ($code, $level, $message) use (&$alerts, $queue_status_promote) {
        $alerts[] = [
            'code'    => $code,
            'level'   => $level,
            'message' => $message,
        ];

        $queue_status_promote($level);
    };

    if (null !== $usage_ratio) {
        if ($usage_ratio >= 1) {
            $register_alert(
                'queue_capacity_exceeded',
                'critical',
                __('La file a atteint sa capacité maximale.', 'sitepulse')
            );
        } elseif ($usage_ratio >= 0.8) {
            $register_alert(
                'queue_capacity_pressure',
                'warning',
                __('La file approche de sa capacité maximale.', 'sitepulse')
            );
        }
    }

    if ($sanitized['delayed_jobs'] > 0) {
        $register_alert(
            'queue_delayed_jobs',
            'warning',
            sprintf(
                _n('%s requête est en retard.', '%s requêtes sont en retard.', $sanitized['delayed_jobs'], 'sitepulse'),
                number_format_i18n($sanitized['delayed_jobs'])
            )
        );

        if ($sanitized['prioritized_jobs'] > 0) {
            $priority_level = $sanitized['max_priority'] >= 5 ? 'critical' : 'warning';
            $register_alert(
                'queue_priority_backlog',
                $priority_level,
                sprintf(
                    _n(
                        '%1$s job prioritaire attend (priorité max %2$s).',
                        '%1$s jobs prioritaires attendent (priorité max %2$s).',
                        $sanitized['prioritized_jobs'],
                        'sitepulse'
                    ),
                    number_format_i18n($sanitized['prioritized_jobs']),
                    number_format_i18n(max(1, $sanitized['max_priority']))
                )
            );
        }
    }

    if ($sanitized['dropped_total'] > 0) {
        $register_alert(
            'queue_rejections_detected',
            'warning',
            sprintf(
                _n(
                    '%s requête a été rejetée (TTL, doublon ou validation).',
                    '%s requêtes ont été rejetées (TTL, doublon ou validation).',
                    $sanitized['dropped_total'],
                    'sitepulse'
                ),
                number_format_i18n($sanitized['dropped_total'])
            )
        );
    }

    if ($sanitized['limit_ttl'] > 0) {
        $wait_warning_threshold = max(60, min((int) round($sanitized['limit_ttl'] * 0.25), 3600));
        $wait_critical_threshold = max($wait_warning_threshold + 60, min((int) round($sanitized['limit_ttl'] * 0.5), 7200));
    } else {
        $wait_warning_threshold = 900;
        $wait_critical_threshold = 1800;
    }

    if ($sanitized['max_wait_seconds'] >= $wait_critical_threshold) {
        $register_alert(
            'queue_wait_time_critical',
            'critical',
            sprintf(
                __('Attente maximale détectée : %s.', 'sitepulse'),
                sitepulse_uptime_format_duration_i18n($sanitized['max_wait_seconds'])
            )
        );
    } elseif ($sanitized['max_wait_seconds'] >= $wait_warning_threshold) {
        $register_alert(
            'queue_wait_time_warning',
            'warning',
            sprintf(
                __('La file enregistre des attentes longues : %s.', 'sitepulse'),
                sitepulse_uptime_format_duration_i18n($sanitized['max_wait_seconds'])
            )
        );
    }

    $day_in_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
    $stale_threshold = $sanitized['limit_ttl'] > 0
        ? max(300, min($sanitized['limit_ttl'], $day_in_seconds))
        : 900;

    $metrics_age = null;

    if ($updated_at > 0) {
        $metrics_age = max(0, $current_timestamp - $updated_at);

        if ($metrics_age > (2 * $stale_threshold)) {
            $register_alert(
                'queue_metrics_expired',
                'critical',
                sprintf(
                    __('Les métriques n’ont pas été actualisées depuis %s.', 'sitepulse'),
                    sitepulse_uptime_format_duration_i18n($metrics_age)
                )
            );
        } elseif ($metrics_age > $stale_threshold) {
            $register_alert(
                'queue_metrics_stale',
                'warning',
                sprintf(
                    __('Dernière actualisation il y a %s.', 'sitepulse'),
                    sitepulse_uptime_format_duration_i18n($metrics_age)
                )
            );
        }
    }

    $queue_status_headlines = [
        'ok'       => __('File d’orchestration nominale', 'sitepulse'),
        'warning'  => __('Points de vigilance détectés', 'sitepulse'),
        'critical' => __('Intervention requise', 'sitepulse'),
    ];

    $queue_status_icons = [
        'ok'       => 'yes-alt',
        'warning'  => 'warning',
        'critical' => 'dismiss',
    ];

    $date_format = (string) get_option('date_format', 'Y-m-d');
    $time_format = (string) get_option('time_format', 'H:i');

    if ($date_format === '') {
        $date_format = 'Y-m-d';
    }

    if ($time_format === '') {
        $time_format = 'H:i';
    }

    $describe_timestamp = static function ($timestamp) use ($current_timestamp, $date_format, $time_format) {
        if (null === $timestamp) {
            return [
                'timestamp' => null,
                'formatted' => null,
                'relative'  => null,
                'label'     => '—',
            ];
        }

        $formatted = date_i18n($date_format . ' ' . $time_format, $timestamp);
        $relative = sitepulse_uptime_format_relative_time($timestamp, $current_timestamp);

        $label = $formatted;

        if ($relative !== '') {
            $label = sprintf('%s (%s)', $formatted, $relative);
        }

        return [
            'timestamp' => (int) $timestamp,
            'formatted' => $formatted,
            'relative'  => $relative,
            'label'     => $label,
        ];
    };

    $schedule_next = $describe_timestamp($sanitized['next_scheduled_at']);
    $schedule_oldest = $describe_timestamp($sanitized['oldest_created_at']);
    $updated_descriptor = $describe_timestamp($updated_at > 0 ? $updated_at : null);

    return [
        'timestamp'  => $current_timestamp,
        'updated_at' => $updated_at,
        'metrics'    => $sanitized,
        'status'     => [
            'level'               => $queue_status,
            'headline'            => $queue_status_headlines[$queue_status],
            'icon'                => $queue_status_icons[$queue_status],
            'alerts'              => $alerts,
            'notes'               => array_column($alerts, 'message'),
            'usage_ratio'         => null === $usage_ratio ? null : (float) $usage_ratio,
            'metrics_age_seconds' => $metrics_age,
        ],
        'schedule'   => [
            'next'   => $schedule_next,
            'oldest' => $schedule_oldest,
        ],
        'metadata'   => [
            'updated' => $updated_descriptor,
        ],
        'thresholds' => [
            'usage_warning_ratio' => 0.8,
            'wait_warning'        => $wait_warning_threshold,
            'wait_critical'       => $wait_critical_threshold,
            'stale_threshold'     => $stale_threshold,
        ],
    ];
}

/**
 * Normalises and prunes a remote worker queue.
 *
 * @param array<int,array<string,mixed>> $queue Existing queue.
 * @param int|null                       $now   Reference timestamp.
 * @return array<int,array<string,mixed>>
 */
function sitepulse_uptime_normalize_remote_queue($queue, $now = null) {
    $now = null === $now ? (int) current_time('timestamp', true) : (int) $now;
    $ttl = sitepulse_uptime_get_remote_queue_item_ttl();
    $max_size = sitepulse_uptime_get_remote_queue_max_size();
    $metrics = sitepulse_uptime_get_default_queue_metrics($now, $ttl, $max_size);

    if (!is_array($queue) || empty($queue)) {
        sitepulse_uptime_record_queue_metrics($metrics);

        return [];
    }
    $encoder = function ($payload) {
        if (!is_array($payload)) {
            return '';
        }

        ksort($payload);

        if (function_exists('wp_json_encode')) {
            return wp_json_encode($payload);
        }

        return json_encode($payload);
    };

    $unique = [];

    foreach ($queue as $item) {
        $metrics['requested']++;

        if (!is_array($item)) {
            $metrics['dropped_invalid']++;
            continue;
        }

        $agent = isset($item['agent']) ? sitepulse_uptime_normalize_agent_id($item['agent']) : 'default';
        $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : [];
        $scheduled_at = isset($item['scheduled_at']) ? (int) $item['scheduled_at'] : $now;
        $created_at = isset($item['created_at']) ? (int) $item['created_at'] : $now;
        $priority = isset($item['priority']) && is_numeric($item['priority']) ? (int) $item['priority'] : 0;

        if ($ttl > 0 && $scheduled_at <= ($now - $ttl)) {
            $metrics['dropped_expired']++;
            continue;
        }

        $key = $agent . '|' . $scheduled_at . '|' . md5($encoder($payload));

        if (isset($unique[$key])) {
            $metrics['dropped_duplicates']++;

            $existing_created = isset($unique[$key]['created_at']) ? (int) $unique[$key]['created_at'] : null;
            $existing_scheduled = isset($unique[$key]['scheduled_at']) ? (int) $unique[$key]['scheduled_at'] : null;
            $existing_priority = isset($unique[$key]['priority']) ? (int) $unique[$key]['priority'] : 0;

            if (null !== $existing_created && ($created_at > 0 && $created_at < $existing_created)) {
                $unique[$key]['created_at'] = $created_at;
            }

            if (null !== $existing_scheduled && ($scheduled_at > 0 && $scheduled_at < $existing_scheduled)) {
                $unique[$key]['scheduled_at'] = $scheduled_at;
            }

            if ($priority > $existing_priority) {
                $unique[$key]['priority'] = $priority;
            }

            continue;
        }

        $unique[$key] = [
            'agent'       => $agent,
            'payload'     => $payload,
            'scheduled_at'=> $scheduled_at,
            'created_at'  => $created_at,
            'priority'    => $priority,
        ];
    }

    if (empty($unique)) {
        sitepulse_uptime_record_queue_metrics($metrics);

        return [];
    }

    $normalized = array_values($unique);

    usort($normalized, function ($a, $b) {
        $a_priority = isset($a['priority']) ? (int) $a['priority'] : 0;
        $b_priority = isset($b['priority']) ? (int) $b['priority'] : 0;

        if ($a_priority !== $b_priority) {
            return $b_priority <=> $a_priority;
        }

        $a_scheduled = isset($a['scheduled_at']) ? (int) $a['scheduled_at'] : 0;
        $b_scheduled = isset($b['scheduled_at']) ? (int) $b['scheduled_at'] : 0;

        if ($a_scheduled === $b_scheduled) {
            $a_created = isset($a['created_at']) ? (int) $a['created_at'] : 0;
            $b_created = isset($b['created_at']) ? (int) $b['created_at'] : 0;

            return $a_created <=> $b_created;
        }

        return $a_scheduled <=> $b_scheduled;
    });

    $original_count = count($normalized);

    if ($max_size > 0 && $original_count > $max_size) {
        $metrics['dropped_overflow'] = $original_count - $max_size;
        $normalized = array_slice($normalized, 0, $max_size);
    }

    $metrics['retained'] = count($normalized);
    $metrics['queue_length'] = $metrics['retained'];

    $next_scheduled_at = null;
    $oldest_created_at = null;
    $delayed_jobs = 0;
    $wait_total = 0;
    $max_wait = 0;
    $priority_total = 0;
    $prioritized_jobs = 0;
    $max_priority_value = null;

    foreach ($normalized as $item) {
        if (isset($item['scheduled_at']) && (int) $item['scheduled_at'] > 0) {
            $timestamp = (int) $item['scheduled_at'];

            if (null === $next_scheduled_at || $timestamp < $next_scheduled_at) {
                $next_scheduled_at = $timestamp;
            }

            $wait = $now - $timestamp;

            if ($wait > 0) {
                $delayed_jobs++;
                $wait_total += $wait;

                if ($wait > $max_wait) {
                    $max_wait = $wait;
                }
            }
        }

        if (isset($item['created_at']) && (int) $item['created_at'] > 0) {
            $created = (int) $item['created_at'];

            if (null === $oldest_created_at || $created < $oldest_created_at) {
                $oldest_created_at = $created;
            }
        }

        $priority_value = isset($item['priority']) ? (int) $item['priority'] : 0;

        if ($priority_value !== 0) {
            $prioritized_jobs++;
            $priority_total += $priority_value;
            $max_priority_value = null === $max_priority_value
                ? $priority_value
                : max($max_priority_value, $priority_value);
        }
    }

    $metrics['delayed_jobs'] = $delayed_jobs;
    $metrics['max_wait_seconds'] = $max_wait > 0 ? (int) $max_wait : 0;
    $metrics['avg_wait_seconds'] = ($delayed_jobs > 0 && $wait_total > 0)
        ? (int) round($wait_total / $delayed_jobs)
        : 0;
    $metrics['next_scheduled_at'] = null !== $next_scheduled_at ? (int) $next_scheduled_at : null;
    $metrics['oldest_created_at'] = null !== $oldest_created_at ? (int) $oldest_created_at : null;

    if ($prioritized_jobs > 0) {
        $metrics['prioritized_jobs'] = $prioritized_jobs;
        $metrics['max_priority'] = (int) $max_priority_value;
        $metrics['avg_priority'] = (int) round($priority_total / $prioritized_jobs);
    }

    sitepulse_uptime_record_queue_metrics($metrics);

    return $normalized;
}

/**
 * Determines the next scheduled timestamp for the provided queue.
 *
 * @param array<int,array<string,mixed>> $queue    Queue entries.
 * @param int|null                       $fallback Fallback timestamp.
 * @return int|null
 */
function sitepulse_uptime_get_queue_next_scheduled_at($queue, $fallback = null) {
    if (!is_array($queue) || empty($queue)) {
        return null === $fallback ? null : (int) $fallback;
    }

    $timestamps = array_map(function ($item) {
        return isset($item['scheduled_at']) ? (int) $item['scheduled_at'] : 0;
    }, $queue);

    $timestamps = array_filter($timestamps, function ($timestamp) {
        return $timestamp > 0;
    });

    if (empty($timestamps)) {
        return null === $fallback ? null : (int) $fallback;
    }

    return min($timestamps);
}

/**
 * High-level helper to enqueue a remote job for an agent.
 *
 * @param string     $agent_id  Agent identifier.
 * @param array      $payload   Optional request overrides.
 * @param int|null   $timestamp Scheduled timestamp (UTC).
 * @param int|null   $priority  Optional priority override.
 * @return bool True when the job was enqueued, false when skipped.
 */
function sitepulse_uptime_enqueue_remote_job($agent_id, $payload = [], $timestamp = null, $priority = null) {
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $agent_config = sitepulse_uptime_get_agent($agent_id);

    if (!sitepulse_uptime_agent_is_active($agent_id, $agent_config)) {
        return false;
    }

    if (!is_array($payload)) {
        $payload = [];
    }

    if (null === $priority) {
        $weight = sitepulse_uptime_get_agent_weight($agent_id, $agent_config);
        $priority = (int) round($weight * 100);
    }

    $job = [
        'agent'     => $agent_id,
        'payload'   => $payload,
        'timestamp' => $timestamp,
        'priority'  => $priority,
    ];

    /**
     * Filters the job payload before it is persisted in the remote queue.
     *
     * Returning false aborts the enqueue operation.
     *
     * @param array<string,mixed>|false $job          Normalized job payload.
     * @param array<string,mixed>       $agent_config Agent configuration.
     */
    $job = apply_filters('sitepulse_uptime_pre_enqueue_job', $job, $agent_config);

    if (false === $job) {
        return false;
    }

    $job_agent = isset($job['agent']) ? sitepulse_uptime_normalize_agent_id($job['agent']) : $agent_id;
    $job_payload = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : $payload;
    $job_timestamp = array_key_exists('timestamp', $job) ? $job['timestamp'] : $timestamp;
    $job_priority = array_key_exists('priority', $job) ? $job['priority'] : $priority;

    $job_priority = is_numeric($job_priority) ? (int) $job_priority : 0;

    if (null !== $job_timestamp) {
        $job_timestamp = (int) $job_timestamp;
    }

    sitepulse_uptime_schedule_internal_request($job_agent, $job_payload, $job_timestamp, $job_priority);

    /**
     * Fires after an uptime job has been enqueued.
     *
     * @param string                    $agent_id     Agent identifier.
     * @param array<string,mixed>       $payload      Job payload.
     * @param int|null                  $timestamp    Scheduled timestamp.
     * @param int                       $priority     Job priority.
     * @param array<string,mixed>       $agent_config Agent configuration.
     */
    do_action('sitepulse_uptime_job_enqueued', $job_agent, $job_payload, $job_timestamp, $job_priority, $agent_config);

    return true;
}

/**
 * Queues a remote worker request so it is executed internally.
 *
 * @param string   $agent_id  Agent identifier.
 * @param array    $payload   Optional overrides for the request.
 * @param int|null $timestamp When the request should be executed.
 * @param int      $priority  Optional priority override (higher values are executed first).
 * @return void
 */
function sitepulse_uptime_schedule_internal_request($agent_id, $payload = [], $timestamp = null, $priority = 0) {
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $timestamp = null === $timestamp ? (int) current_time('timestamp', true) : (int) $timestamp;
    $priority = (int) $priority;

    if (!is_array($payload)) {
        $payload = [];
    }

    $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);

    if (!is_array($queue)) {
        $queue = [];
    }

    $queue[] = [
        'agent'       => $agent_id,
        'payload'     => $payload,
        'scheduled_at'=> $timestamp,
        'created_at'  => (int) current_time('timestamp', true),
        'priority'    => $priority,
    ];

    $queue = sitepulse_uptime_normalize_remote_queue($queue);

    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, $queue, false);

    $next_timestamp = sitepulse_uptime_get_queue_next_scheduled_at($queue, $timestamp);

    if (null !== $next_timestamp) {
        sitepulse_uptime_maybe_schedule_queue_processor($next_timestamp);
    }
}

/**
 * Ensures a cron event exists to process the remote worker queue.
 *
 * @param int $timestamp Desired execution time.
 * @return void
 */
function sitepulse_uptime_maybe_schedule_queue_processor($timestamp) {
    // WP-Cron expects UTC timestamps, so always schedule using GMT to avoid timezone offsets.
    $timestamp = max((int) $timestamp, (int) current_time('timestamp', true));

    $current = wp_next_scheduled('sitepulse_uptime_process_remote_queue');

    if (!$current || $timestamp < $current) {
        if ($current) {
            wp_unschedule_event($current, 'sitepulse_uptime_process_remote_queue');
        }

        wp_schedule_single_event($timestamp, 'sitepulse_uptime_process_remote_queue');
    }
}

/**
 * Processes the remote worker queue and executes pending checks.
 *
 * @return void
 */
function sitepulse_uptime_process_remote_queue() {
    $queue = get_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, []);
    $queue = sitepulse_uptime_normalize_remote_queue($queue);

    if (!is_array($queue) || empty($queue)) {
        update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, [], false);
        return;
    }

    $now = (int) current_time('timestamp', true);
    $remaining = [];

    foreach ($queue as $item) {
        if (!is_array($item)) {
            continue;
        }

        $scheduled_at = isset($item['scheduled_at']) ? (int) $item['scheduled_at'] : $now;

        if ($scheduled_at > $now) {
            $remaining[] = $item;
            continue;
        }

        $agent = isset($item['agent']) ? $item['agent'] : 'default';
        $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : [];

        if (!sitepulse_uptime_agent_is_active($agent)) {
            continue;
        }

        if (isset($payload['task']) && 'uptime_sla_report' === $payload['task']) {
            $windows = isset($payload['windows']) && is_array($payload['windows']) ? $payload['windows'] : [7, 30];
            sitepulse_uptime_generate_sla_report('automation', $windows);

            if (!empty($payload['automation'])) {
                $settings = sitepulse_uptime_get_sla_automation_settings();

                if (!empty($settings['enabled'])) {
                    $settings['next_run'] = (int) current_time('timestamp', true);
                    update_option(SITEPULSE_OPTION_UPTIME_SLA_AUTOMATION, $settings, false);
                    sitepulse_uptime_schedule_automation_job($settings, true);
                }
            }

            continue;
        }

        sitepulse_run_uptime_check($agent, $payload);
    }

    if (!empty($remaining)) {
        $remaining = sitepulse_uptime_normalize_remote_queue($remaining, $now);
        update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, $remaining, false);

        $next_timestamp = sitepulse_uptime_get_queue_next_scheduled_at($remaining, $now);

        if (null !== $next_timestamp) {
            sitepulse_uptime_maybe_schedule_queue_processor($next_timestamp);
        }

        return;
    }

    update_option(SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE, [], false);
}

/**
 * Attempts to resolve the interval (in seconds) for the configured uptime schedule.
 *
 * @param int $default_interval Fallback interval when schedules cannot be resolved.
 * @return int
 */
function sitepulse_uptime_tracker_resolve_schedule_interval($default_interval) {
    if (!function_exists('wp_get_schedules')) {
        return $default_interval;
    }

    $schedules = wp_get_schedules();

    if (!is_array($schedules) || empty($schedules)) {
        return $default_interval;
    }

    $schedule_candidates = array_unique(array_filter([
        sitepulse_uptime_tracker_get_schedule(),
        defined('SITEPULSE_DEFAULT_UPTIME_FREQUENCY') ? SITEPULSE_DEFAULT_UPTIME_FREQUENCY : null,
        'hourly',
    ]));

    foreach ($schedule_candidates as $candidate) {
        if (!isset($schedules[$candidate]) || !is_array($schedules[$candidate])) {
            continue;
        }

        $candidate_interval = isset($schedules[$candidate]['interval']) ? (int) $schedules[$candidate]['interval'] : 0;

        if ($candidate_interval > 0) {
            return $candidate_interval;
        }
    }

    return $default_interval;
}

/**
 * Ensures the uptime tracker cron hook is scheduled and reports failures.
 *
 * @return void
 */
function sitepulse_uptime_tracker_ensure_cron() {
    global $sitepulse_uptime_cron_hook;

    if (empty($sitepulse_uptime_cron_hook)) {
        return;
    }

    $desired_schedule = sitepulse_uptime_tracker_get_schedule();
    $available_schedules = wp_get_schedules();

    if (!isset($available_schedules[$desired_schedule])) {
        $fallback_schedule = defined('SITEPULSE_DEFAULT_UPTIME_FREQUENCY') ? SITEPULSE_DEFAULT_UPTIME_FREQUENCY : 'hourly';
        if (isset($available_schedules[$fallback_schedule])) {
            $desired_schedule = $fallback_schedule;
        } elseif (isset($available_schedules['hourly'])) {
            $desired_schedule = 'hourly';
        }
    }

    $current_schedule = wp_get_schedule($sitepulse_uptime_cron_hook);

    if ($current_schedule && $current_schedule !== $desired_schedule) {
        wp_clear_scheduled_hook($sitepulse_uptime_cron_hook);
    }

    $next_run = wp_next_scheduled($sitepulse_uptime_cron_hook);

    if (!$next_run) {
        $next_run = (int) current_time('timestamp', true);
        $scheduled = wp_schedule_event($next_run, $desired_schedule, $sitepulse_uptime_cron_hook);

        if (false === $scheduled && function_exists('sitepulse_log')) {
            sitepulse_log(sprintf('Unable to schedule uptime tracker cron hook: %s', $sitepulse_uptime_cron_hook), 'ERROR');
        }
    }

    if (!wp_next_scheduled($sitepulse_uptime_cron_hook)) {
        sitepulse_register_cron_warning(
            'uptime_tracker',
            __('SitePulse n’a pas pu planifier la vérification d’uptime. Vérifiez que WP-Cron est actif ou programmez manuellement la tâche.', 'sitepulse')
        );
    } else {
        sitepulse_clear_cron_warning('uptime_tracker');
    }
}
