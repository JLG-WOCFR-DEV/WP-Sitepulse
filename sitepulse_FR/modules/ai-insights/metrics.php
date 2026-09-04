<?php
/**
 * SitePulse AI Insights metrics helpers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses usage headers returned by the Gemini API.
 *
 * @param array|ArrayAccess|mixed $headers HTTP headers.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_parse_response_usage($headers) {
    $usage = [];

    if (is_object($headers) && method_exists($headers, 'getAll')) {
        $headers = $headers->getAll();
    }

    if (!is_array($headers)) {
        return $usage;
    }

    $map = [
        'x-ratelimit-remaining' => 'remaining',
        'x-ratelimit-limit'     => 'limit',
        'x-ratelimit-reset'     => 'reset',
        'x-ratelimit-usage'     => 'usage',
        'x-ratelimit-cost'      => 'cost',
        'x-usage-tokens'        => 'tokens',
    ];

    foreach ($headers as $name => $value) {
        $key = strtolower((string) $name);

        if (!isset($map[$key])) {
            continue;
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_scalar($value)) {
            $usage[$map[$key]] = $value;
        }
    }

    return sitepulse_ai_normalize_usage_metadata($usage);
}

/**
 * Logs execution metrics for completed AI jobs.
 *
 * @param string               $job_id   Job identifier.
 * @param array<string,mixed>  $job_data Job metadata.
 * @param array<string,mixed>  $usage    Usage metadata.
 *
 * @return void
 */
function sitepulse_ai_log_execution_metrics($job_id, array $job_data, array $usage = []) {
    if (!function_exists('sitepulse_log')) {
        return;
    }

    $queue_context = isset($job_data['queue']) ? sitepulse_ai_normalize_queue_context($job_data['queue'], $job_data) : sitepulse_ai_normalize_queue_context([], $job_data);
    $usage = sitepulse_ai_normalize_usage_metadata($usage);
    $duration = 0;

    if (isset($job_data['started_at'], $job_data['finished'])) {
        $duration = max(0, (int) $job_data['finished'] - (int) $job_data['started_at']);
    }

    $message = sprintf(
        'AI Insights job %s — attempt %d/%d (%s, engine=%s, duration=%ss)',
        $job_id,
        isset($job_data['attempt']) ? (int) $job_data['attempt'] : $queue_context['attempt'],
        sitepulse_ai_get_max_attempts(),
        isset($queue_context['priority']) ? $queue_context['priority'] : 'normal',
        isset($queue_context['engine']) ? $queue_context['engine'] : 'wp_cron',
        $duration
    );

    if (!empty($queue_context['quota']) && isset($queue_context['quota']['label'])) {
        $message .= ' — quota=' . sanitize_text_field((string) $queue_context['quota']['label']);
    }

    if (!empty($usage)) {
        $usage_parts = [];

        foreach ($usage as $key => $value) {
            if (is_scalar($value)) {
                $usage_parts[] = $key . '=' . $value;
            }
        }

        if (!empty($usage_parts)) {
            $message .= ' — usage=' . implode(',', $usage_parts);
        }
    }

    sitepulse_log($message, 'INFO');
}

/**
 * Retrieves the cached AI insight payload for the current request.
 *
 * @param bool $force_refresh When true, clears the transient cache and resets the in-request cache.
 *
 * @return array{text?:string,html?:string,timestamp?:int}
 */
function sitepulse_ai_get_cached_insight($force_refresh = false) {
    static $cached_insight = null;

    if ($force_refresh) {
        $cached_insight = null;

        delete_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);

        return [];
    }

    if ($cached_insight !== null) {
        return $cached_insight;
    }

    $cached_insight = [];
    $stored_insight = get_transient(SITEPULSE_TRANSIENT_AI_INSIGHT);

    $variants = [
        'text' => '',
        'html' => '',
    ];

    if (is_array($stored_insight)) {
        $variants = sitepulse_ai_prepare_insight_variants(
            isset($stored_insight['text']) ? (string) $stored_insight['text'] : '',
            isset($stored_insight['html']) ? (string) $stored_insight['html'] : ''
        );

        if (isset($stored_insight['timestamp'])) {
            $cached_insight['timestamp'] = (int) $stored_insight['timestamp'];
        }
    } elseif (is_string($stored_insight) && '' !== $stored_insight) {
        $variants = sitepulse_ai_prepare_insight_variants($stored_insight);
    }

    if ('' !== $variants['text']) {
        $cached_insight['text'] = $variants['text'];

        if ('' !== $variants['html']) {
            $cached_insight['html'] = $variants['html'];
        }
    }

    return $cached_insight;
}

/**
 * Builds a sanitized summary of the latest collected SitePulse metrics.
 *
 * @return string Sanitized summary or empty string when no metrics are available.
 */
function sitepulse_ai_get_metrics_summary() {
    $summary_parts = [];

    if (defined('SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS')) {
        $speed_results = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
        $ttfb_ms       = null;

        if (is_array($speed_results)) {
            $candidates = [
                ['server_processing_ms'],
                ['ttfb'],
                ['data', 'server_processing_ms'],
                ['data', 'ttfb'],
            ];

            foreach ($candidates as $path) {
                $value = $speed_results;

                foreach ($path as $segment) {
                    if (!is_array($value) || !array_key_exists($segment, $value)) {
                        $value = null;
                        break;
                    }

                    $value = $value[$segment];
                }

                if (is_numeric($value)) {
                    $ttfb_ms = (float) $value;
                    break;
                }
            }
        } elseif (is_numeric($speed_results)) {
            $ttfb_ms = (float) $speed_results;
        }

        if (null !== $ttfb_ms) {
            $summary_parts[] = sprintf(
                /* translators: %s: Average TTFB in milliseconds. */
                __('TTFB moyen observé : %s ms.', 'sitepulse'),
                number_format_i18n(round($ttfb_ms, 2), 2)
            );
        }
    }

    if (defined('SITEPULSE_OPTION_UPTIME_LOG')) {
        $uptime_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);

        if (!is_array($uptime_log)) {
            $uptime_log = [];
        }

        if (function_exists('sitepulse_normalize_uptime_log')) {
            $uptime_log = sitepulse_normalize_uptime_log($uptime_log);
        }

        $boolean_statuses = [];

        foreach ($uptime_log as $entry) {
            if (is_array($entry) && array_key_exists('status', $entry) && is_bool($entry['status'])) {
                $boolean_statuses[] = $entry['status'];
            } elseif (is_bool($entry)) {
                $boolean_statuses[] = $entry;
            } elseif (is_numeric($entry)) {
                $boolean_statuses[] = (bool) $entry;
            }
        }

        if (!empty($boolean_statuses)) {
            $total_checks = count($boolean_statuses);
            $up_checks    = count(array_filter($boolean_statuses));
            $uptime_pct   = $total_checks > 0 ? ($up_checks / $total_checks) * 100 : 0;

            $summary_parts[] = sprintf(
                /* translators: %s: Uptime percentage. */
                __('Disponibilité récemment mesurée : %s%%.', 'sitepulse'),
                number_format_i18n(round($uptime_pct, 2), 2)
            );
        }
    }

    if (defined('SITEPULSE_PLUGIN_IMPACT_OPTION')) {
        $impact_data = get_option(SITEPULSE_PLUGIN_IMPACT_OPTION, []);

        if (!is_array($impact_data)) {
            $impact_data = [];
        }

        $samples = isset($impact_data['samples']) && is_array($impact_data['samples'])
            ? $impact_data['samples']
            : [];

        $top_plugin = null;

        foreach ($samples as $plugin_file => $data) {
            if (!is_array($data)) {
                continue;
            }

            $avg_ms = isset($data['avg_ms']) && is_numeric($data['avg_ms']) ? (float) $data['avg_ms'] : null;

            if (null === $avg_ms) {
                continue;
            }

            $plugin_name = '';

            if (isset($data['name']) && is_scalar($data['name'])) {
                $plugin_name = (string) $data['name'];
            } elseif (isset($data['file']) && is_scalar($data['file'])) {
                $plugin_name = (string) $data['file'];
            } elseif (is_string($plugin_file)) {
                $plugin_name = $plugin_file;
            }

            if (!is_array($top_plugin) || $avg_ms > $top_plugin['avg_ms']) {
                $top_plugin = [
                    'name'   => sanitize_text_field(wp_strip_all_tags($plugin_name)),
                    'avg_ms' => $avg_ms,
                ];
            }
        }

        if (null !== $top_plugin && '' !== $top_plugin['name']) {
            $summary_parts[] = sprintf(
                /* translators: 1: Plugin name, 2: Average execution time in milliseconds. */
                __('Plugin le plus coûteux : %1$s (%2$s ms en moyenne).', 'sitepulse'),
                $top_plugin['name'],
                number_format_i18n(round($top_plugin['avg_ms'], 2), 2)
            );
        }
    }

    if (empty($summary_parts)) {
        return '';
    }

    $summary = implode(' ', $summary_parts);

    return sanitize_textarea_field($summary);
}
