<?php
/**
 * SitePulse AI Insights rate-limit and retry helpers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retrieves the sanitized AI rate limit option value.
 *
 * @return string
 */
function sitepulse_ai_get_current_rate_limit_value() {
    $default = function_exists('sitepulse_get_default_ai_rate_limit')
        ? sitepulse_get_default_ai_rate_limit()
        : 'week';

    $value = get_option(SITEPULSE_OPTION_AI_RATE_LIMIT, $default);

    if (!is_string($value)) {
        return $default;
    }

    $value = strtolower(trim($value));
    $choices = function_exists('sitepulse_get_ai_rate_limit_choices')
        ? sitepulse_get_ai_rate_limit_choices()
        : [
            'day'       => __('Une fois par jour', 'sitepulse'),
            'week'      => __('Une fois par semaine', 'sitepulse'),
            'month'     => __('Une fois par mois', 'sitepulse'),
            'unlimited' => __('Illimité', 'sitepulse'),
        ];

    if (!isset($choices[$value])) {
        return $default;
    }

    return $value;
}

/**
 * Returns the localized label for the provided AI rate limit key.
 *
 * @param string $rate_limit Rate limit option key.
 * @return string
 */
function sitepulse_ai_get_rate_limit_label($rate_limit) {
    $choices = function_exists('sitepulse_get_ai_rate_limit_choices')
        ? sitepulse_get_ai_rate_limit_choices()
        : [
            'day'       => __('Une fois par jour', 'sitepulse'),
            'week'      => __('Une fois par semaine', 'sitepulse'),
            'month'     => __('Une fois par mois', 'sitepulse'),
            'unlimited' => __('Illimité', 'sitepulse'),
        ];

    if (!isset($choices[$rate_limit])) {
        $default = sitepulse_ai_get_current_rate_limit_value();

        if (isset($choices[$default])) {
            return $choices[$default];
        }

        return (string) reset($choices);
    }

    return $choices[$rate_limit];
}

/**
 * Returns the rate limit window in seconds for a given option key.
 *
 * @param string $rate_limit Rate limit option key.
 * @return int Number of seconds in the rate limit window. 0 means unlimited.
 */
function sitepulse_ai_get_rate_limit_window_seconds($rate_limit) {
    switch ($rate_limit) {
        case 'day':
            return DAY_IN_SECONDS;
        case 'week':
            return WEEK_IN_SECONDS;
        case 'month':
            return MONTH_IN_SECONDS;
        default:
            return 0;
    }
}

/**
 * Returns the timestamp when Gemini requests can resume after a rate limit.
 *
 * @return int UTC timestamp or 0 when no delay applies.
 */
function sitepulse_ai_get_retry_after_timestamp() {
    $timestamp = (int) get_option(SITEPULSE_OPTION_AI_RETRY_AFTER, 0);

    if ($timestamp <= 0) {
        return 0;
    }

    return $timestamp;
}

/**
 * Stores the timestamp when Gemini requests can resume after a rate limit.
 *
 * @param int $timestamp UTC timestamp. Use 0 to clear.
 *
 * @return void
 */
function sitepulse_ai_set_retry_after_timestamp($timestamp) {
    $timestamp = (int) $timestamp;

    if ($timestamp <= 0) {
        delete_option(SITEPULSE_OPTION_AI_RETRY_AFTER);

        return;
    }

    update_option(SITEPULSE_OPTION_AI_RETRY_AFTER, $timestamp, false);
}

/**
 * Converts human readable durations (e.g. "30s", "5m", "PT1M30S") to seconds.
 *
 * @param mixed $duration Duration string or numeric value.
 *
 * @return int Duration in seconds.
 */
function sitepulse_ai_parse_duration_string($duration, $now = null) {
    if (null === $now) {
        $now = absint(current_time('timestamp', true));
    }

    if (is_numeric($duration)) {
        $seconds = (float) $duration;

        return (int) max(0, round($seconds));
    }

    if (!is_string($duration)) {
        return 0;
    }

    $duration = trim($duration);

    if ('' === $duration) {
        return 0;
    }

    if (preg_match('/^P/i', $duration)) {
        try {
            $base     = new \DateTimeImmutable('@0');
            $interval = new \DateInterval($duration);
            $target   = $base->add($interval);

            return (int) max(0, $target->getTimestamp());
        } catch (\Exception $exception) {
            // Fall back to heuristic parsing below.
        }
    }

    if (preg_match('/^(?P<value>\d+(?:\.\d+)?)(?P<unit>[a-z]+)/i', strtolower($duration), $matches)) {
        $value = (float) $matches['value'];
        $unit  = $matches['unit'];

        switch ($unit) {
            case 's':
            case 'sec':
            case 'secs':
            case 'second':
            case 'seconds':
                return (int) round($value);
            case 'm':
            case 'min':
            case 'mins':
            case 'minute':
            case 'minutes':
                return (int) round($value * MINUTE_IN_SECONDS);
            case 'h':
            case 'hr':
            case 'hrs':
            case 'hour':
            case 'hours':
                return (int) round($value * HOUR_IN_SECONDS);
            case 'd':
            case 'day':
            case 'days':
                return (int) round($value * DAY_IN_SECONDS);
        }
    }

    $timestamp = strtotime($duration);

    if (false !== $timestamp && $timestamp > $now) {
        return (int) max(0, $timestamp - $now);
    }

    return 0;
}

/**
 * Calculates the number of seconds until a given timestamp value.
 *
 * @param mixed $value Raw timestamp value (string/number).
 * @param int   $now   Current UTC timestamp.
 *
 * @return int
 */
function sitepulse_ai_seconds_until_timestamp($value, $now) {
    if (is_numeric($value)) {
        $candidate = (float) $value;

        if ($candidate > 1_000_000_000_000) {
            $candidate /= 1_000;
        }

        $candidate = (int) round($candidate);

        if ($candidate > $now) {
            return (int) max(0, $candidate - $now);
        }

        return 0;
    }

    if (!is_string($value)) {
        return 0;
    }

    $value = trim($value);

    if ('' === $value) {
        return 0;
    }

    $timestamp = strtotime($value);

    if (false === $timestamp) {
        return 0;
    }

    if ($timestamp <= $now) {
        return 0;
    }

    return (int) ($timestamp - $now);
}

/**
 * Parses retry-after hints from mixed values.
 *
 * @param mixed $value Raw value to inspect.
 * @param int   $now   Current UTC timestamp.
 *
 * @return int Seconds until retry.
 */
function sitepulse_ai_parse_retry_value($value, $now) {
    if (is_array($value)) {
        return sitepulse_ai_collect_retry_after_seconds($value, $now);
    }

    if (is_numeric($value)) {
        $numeric = (float) $value;

        if ($numeric > 1_000_000_000_000) {
            $numeric /= 1_000;
        }

        if ($numeric > $now + 5) {
            return sitepulse_ai_seconds_until_timestamp($numeric, $now);
        }

        return (int) max(0, round($numeric));
    }

    if (!is_string($value)) {
        return 0;
    }

    $value = trim($value);

    if ('' === $value) {
        return 0;
    }

    if (is_numeric($value)) {
        return sitepulse_ai_parse_retry_value((float) $value, $now);
    }

    $duration = sitepulse_ai_parse_duration_string($value, $now);

    if ($duration > 0) {
        return $duration;
    }

    return sitepulse_ai_seconds_until_timestamp($value, $now);
}

/**
 * Recursively scans decoded JSON payloads for retry-after hints.
 *
 * @param array<string,mixed> $data Decoded JSON payload.
 * @param int                 $now  Current UTC timestamp.
 *
 * @return int Seconds until retry.
 */
function sitepulse_ai_collect_retry_after_seconds($data, $now) {
    $max_delay = 0;

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $max_delay = max($max_delay, sitepulse_ai_collect_retry_after_seconds($value, $now));

            continue;
        }

        if (!is_string($key)) {
            continue;
        }

        $normalized_key = strtolower(str_replace(['-', '_', ' '], '', $key));

        if ('' === $normalized_key) {
            continue;
        }

        $duration_keys = [
            'retryafter',
            'retryafterseconds',
            'retrydelay',
            'retrydelayseconds',
            'retrydelaysec',
            'interval',
            'duration',
            'period',
            'remainingtime',
            'remaining',
            'waittime',
            'seconds',
        ];

        $timestamp_keys = [
            'retryat',
            'resumetime',
            'resumeat',
            'resettime',
            'resetat',
            'reset',
            'resettimestamp',
            'resettimeseconds',
            'retrytimestamp',
            'retrytimestampseconds',
        ];

        if (in_array($normalized_key, $duration_keys, true)) {
            $max_delay = max($max_delay, sitepulse_ai_parse_retry_value($value, $now));

            continue;
        }

        if (in_array($normalized_key, $timestamp_keys, true)) {
            $candidate = sitepulse_ai_seconds_until_timestamp($value, $now);

            if ($candidate <= 0) {
                $candidate = sitepulse_ai_parse_retry_value($value, $now);
            }

            $max_delay = max($max_delay, $candidate);
        }
    }

    return (int) $max_delay;
}

/**
 * Extracts retry-after hints from HTTP headers.
 *
 * @param mixed $headers Response headers.
 * @param int   $now     Current UTC timestamp.
 *
 * @return int Seconds until retry.
 */
function sitepulse_ai_retry_after_from_headers($headers, $now) {
    if ($headers instanceof WP_HTTP_Headers) {
        $headers = $headers->getAll();
    }

    if (!is_array($headers)) {
        return 0;
    }

    foreach ($headers as $name => $value) {
        if (!is_string($name)) {
            continue;
        }

        if (is_array($value)) {
            $value = end($value);
        }

        if (!is_string($value)) {
            continue;
        }

        if ('retry-after' !== strtolower($name)) {
            continue;
        }

        $seconds = sitepulse_ai_parse_retry_value($value, $now);

        if ($seconds > 0) {
            return $seconds;
        }
    }

    return 0;
}

/**
 * Determines the retry-after delay from HTTP headers and JSON payload.
 *
 * @param mixed                       $headers       HTTP headers.
 * @param array<string,mixed>|null    $decoded_error Optional decoded error payload.
 * @param int                         $now           Current UTC timestamp.
 *
 * @return int Seconds until retry.
 */
function sitepulse_ai_extract_retry_after_delay($headers, $decoded_error, $now) {
    $delay = sitepulse_ai_retry_after_from_headers($headers, $now);

    if ($delay > 0) {
        return $delay;
    }

    if (is_array($decoded_error)) {
        $delay = sitepulse_ai_collect_retry_after_seconds($decoded_error, $now);
    }

    return (int) max(0, $delay);
}

/**
 * Creates a WP_Error instance while logging the associated message.
 *
 * @param string   $code        Error code.
 * @param string   $message     Human readable message.
 * @param int|null $status_code Optional status code for context.
 *
 * @return WP_Error
 */
function sitepulse_ai_create_wp_error($code, $message, $status_code = null, array $extra_data = []) {
    sitepulse_ai_record_critical_error($message, $status_code);

    $data = [];

    if (null !== $status_code) {
        $data['status_code'] = (int) $status_code;
    }

    foreach ($extra_data as $key => $value) {
        $data[$key] = $value;
    }

    return new WP_Error($code, $message, $data);
}

/**
 * Retrieves the contextual status code from a WP_Error instance.
 *
 * @param WP_Error   $error          Error object.
 * @param int        $default_code   Fallback status code.
 *
 * @return int
 */
function sitepulse_ai_get_error_status_code(WP_Error $error, $default_code = 500) {
    $data = $error->get_error_data();

    if (is_array($data) && isset($data['status_code'])) {
        return (int) $data['status_code'];
    }

    return (int) $default_code;
}

/**
 * Retrieves the retry-after delay attached to an error when available.
 *
 * @param WP_Error $error Error object.
 *
 * @return int Seconds until retry.
 */
function sitepulse_ai_get_error_retry_after(WP_Error $error) {
    $data = $error->get_error_data();

    if (!is_array($data)) {
        return 0;
    }

    if (isset($data['retry_after'])) {
        return (int) max(0, $data['retry_after']);
    }

    if (isset($data['retry_at'])) {
        $retry_at = (int) $data['retry_at'];

        if ($retry_at > 0) {
            $now = absint(current_time('timestamp', true));

            return (int) max(0, $retry_at - $now);
        }
    }

    return 0;
}

/**
 * Retrieves the retry-at timestamp attached to an error when available.
 *
 * @param WP_Error $error Error object.
 *
 * @return int UTC timestamp or 0 when missing.
 */
function sitepulse_ai_get_error_retry_at(WP_Error $error) {
    $data = $error->get_error_data();

    if (is_array($data) && isset($data['retry_at'])) {
        return (int) max(0, $data['retry_at']);
    }

    if (is_array($data) && isset($data['retry_after'])) {
        $delay = (int) max(0, $data['retry_after']);

        if ($delay > 0) {
            $now = absint(current_time('timestamp', true));

            return $now + $delay;
        }
    }

    return 0;
}
