<?php
/**
 * SitePulse Uptime log normalisation.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes a raw uptime status value into canonical form.
 *
 * @param mixed $status Raw status field from the log entry.
 * @return bool|string|null Returns true/false for up/down, 'maintenance', 'unknown' or null when indeterminate.
 */
function sitepulse_uptime_normalize_status_value($status) {
    if (is_bool($status)) {
        return $status;
    }

    if (is_int($status) || is_float($status)) {
        return (int) $status !== 0;
    }

    if (is_string($status)) {
        $normalized = strtolower(trim($status));

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'ok', 'up', 'online', 'success'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off', 'down', 'offline', 'failed', 'failure', 'error'], true)) {
            return false;
        }

        if (in_array($normalized, ['maintenance', 'paused', 'snoozed'], true)) {
            return 'maintenance';
        }

        if (in_array($normalized, ['unknown', 'n/a', 'na', 'indeterminate'], true)) {
            return 'unknown';
        }
    }

    return null;
}

/**
 * Converts an arbitrary error payload into a string message.
 *
 * @param mixed $error Raw error payload.
 * @return string|null
 */
function sitepulse_uptime_normalize_error_message($error) {
    if (null === $error || '' === $error) {
        return null;
    }

    if (is_wp_error($error)) {
        $messages = $error->get_error_messages();

        if (empty($messages)) {
            $messages = [$error->get_error_code()];
        }

        return implode('; ', array_filter(array_map('strval', $messages)));
    }

    if (is_scalar($error)) {
        return (string) $error;
    }

    $encoded_error = wp_json_encode($error);

    if (false !== $encoded_error) {
        return $encoded_error;
    }

    return null;
}

function sitepulse_normalize_uptime_log($log) {
    if (!is_array($log) || empty($log)) {
        return [];
    }

    $count = count($log);
    $now = (int) current_time('timestamp');

    $default_interval = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
    $interval = sitepulse_uptime_tracker_resolve_schedule_interval($default_interval);

    $approximate_start = $now - max(0, ($count - 1) * $interval);

    $prepared = [];

    foreach (array_values($log) as $index => $entry) {
        $timestamp = $approximate_start + ($index * $interval);

        if (is_array($entry) && isset($entry['timestamp']) && is_numeric($entry['timestamp'])) {
            $timestamp = (int) $entry['timestamp'];
        }

        $prepared[] = [
            'entry'     => $entry,
            'timestamp' => $timestamp,
            'order'     => $index,
        ];
    }

    usort($prepared, function ($a, $b) {
        if ($a['timestamp'] === $b['timestamp']) {
            return $a['order'] <=> $b['order'];
        }

        return $a['timestamp'] <=> $b['timestamp'];
    });

    $normalized = [];

    foreach ($prepared as $item) {
        $entry = $item['entry'];
        $timestamp = $item['timestamp'];
        $status = null;
        $raw_status_value = null;
        $incident_start = null;
        $error_message = null;
        $agent = 'default';

        if (is_array($entry)) {
            if (array_key_exists('status', $entry)) {
                $status = $entry['status'];
                $raw_status_value = $entry['status'];
            } else {
                $status = !empty($entry);
                $raw_status_value = $status;
            }

            if (isset($entry['incident_start']) && is_numeric($entry['incident_start'])) {
                $incident_start = (int) $entry['incident_start'];
            }

            if (array_key_exists('error', $entry)) {
                $error_message = sitepulse_uptime_normalize_error_message($entry['error']);
            }

            if (isset($entry['agent']) && is_string($entry['agent'])) {
                $agent = sitepulse_uptime_normalize_agent_id($entry['agent']);
            }
        } else {
            $raw_status_value = $entry;
            $status = (bool) (is_int($entry) ? $entry : !empty($entry));
        }

        $normalized_status = sitepulse_uptime_normalize_status_value($status);

        if (null === $normalized_status) {
            $normalized_status = 'unknown';
        }

        $status = $normalized_status;

        if ('maintenance' === $status) {
            $incident_start = null;
        } elseif (is_bool($status)) {
            if (false === $status) {
                if (null === $incident_start) {
                    $previous_boolean_entry = null;

                    for ($i = count($normalized) - 1; $i >= 0; $i--) {
                        if (array_key_exists('status', $normalized[$i]) && is_bool($normalized[$i]['status'])) {
                            $previous_boolean_entry = $normalized[$i];
                            break;
                        }
                    }

                    if (null !== $previous_boolean_entry && false === $previous_boolean_entry['status'] && isset($previous_boolean_entry['incident_start'])) {
                        $incident_start = (int) $previous_boolean_entry['incident_start'];
                    }

                    if (null === $incident_start) {
                        $incident_start = $timestamp;
                    }
                }
            } else {
                $incident_start = null;
            }
        } else {
            $incident_start = null;
        }

        $normalized_entry = array_filter([
            'timestamp'      => $timestamp,
            'status'         => $status,
            'incident_start' => $incident_start,
            'error'          => $error_message,
            'agent'          => $agent,
            'raw_status'     => $raw_status_value,
        ], function ($value) {
            return null !== $value;
        });

        if (array_key_exists('raw_status', $normalized_entry)) {
            if ($normalized_entry['raw_status'] === $status) {
                unset($normalized_entry['raw_status']);
            } elseif (is_bool($status) && is_bool($normalized_entry['raw_status'])) {
                if ($normalized_entry['raw_status'] === $status) {
                    unset($normalized_entry['raw_status']);
                }
            }
        }

        $normalized[] = $normalized_entry;
    }

    return array_values($normalized);
}

/**
 * Returns the configured history retention (in days) for uptime measurements.
 *
 * @return int
 */
function sitepulse_get_uptime_history_retention_days() {
    $default = defined('SITEPULSE_DEFAULT_UPTIME_HISTORY_RETENTION_DAYS')
        ? (int) SITEPULSE_DEFAULT_UPTIME_HISTORY_RETENTION_DAYS
        : 90;

    $option_value = get_option(SITEPULSE_OPTION_UPTIME_HISTORY_RETENTION_DAYS, $default);

    if (!is_numeric($option_value)) {
        $option_value = $default;
    }

    $retention_days = (int) $option_value;

    if ($retention_days < 30) {
        $retention_days = 30;
    } elseif ($retention_days > 365) {
        $retention_days = 365;
    }

    if (function_exists('apply_filters')) {
        $retention_days = (int) apply_filters('sitepulse_uptime_history_retention_days', $retention_days);
    }

    return max(30, min(365, $retention_days));
}

/**
 * Trims the uptime log according to the configured retention period.
 *
 * @param array $log Normalized uptime log entries.
 * @return array<int,array<string,mixed>>
 */
function sitepulse_trim_uptime_log($log) {
    if (!is_array($log) || empty($log)) {
        return [];
    }

    $retention_days = sitepulse_get_uptime_history_retention_days();
    $day_in_seconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
    $retention_seconds = max(1, $retention_days) * $day_in_seconds;
    $cutoff_timestamp = (int) current_time('timestamp') - $retention_seconds;

    $filtered = [];

    foreach ($log as $entry) {
        if (!is_array($entry)) {
            $filtered[] = $entry;
            continue;
        }

        if (!isset($entry['timestamp'])) {
            $filtered[] = $entry;
            continue;
        }

        if ((int) $entry['timestamp'] >= $cutoff_timestamp) {
            $filtered[] = $entry;
        }
    }

    $default_interval = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
    $interval = max(1, sitepulse_uptime_tracker_resolve_schedule_interval($default_interval));
    $max_entries = (int) ceil($retention_seconds / $interval);

    // Provide a safety margin to avoid trimming legitimate data when the schedule changes.
    $max_entries = max($max_entries, $retention_days);

    if (empty($filtered)) {
        $filtered = array_slice(array_values($log), -$max_entries);
    }

    if (count($filtered) > $max_entries) {
        $filtered = array_slice($filtered, -$max_entries);
    }

    return array_values($filtered);
}
