<?php
/**
 * SitePulse dashboard preferences and ranges.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the identifiers of the dashboard cards that can be customised.
 *
 * @return string[]
 */
function sitepulse_get_dashboard_card_keys() {
    return ['speed', 'experience', 'uptime', 'database', 'logs', 'resource', 'plugins'];
}

/**
 * Provides the default dashboard preferences for the supplied cards.
 *
 * @param string[]|null $allowed_cards Optional subset of cards to include.
 *
 * @return array{
 *     order: string[],
 *     visibility: array<string,bool>,
 *     sizes: array<string,string>,
 *     theme: string
 * }
*/
function sitepulse_get_dashboard_default_preferences($allowed_cards = null) {
    $card_keys = sitepulse_get_dashboard_card_keys();

    if (is_array($allowed_cards) && !empty($allowed_cards)) {
        $allowed_cards = array_values(array_filter(array_map('strval', $allowed_cards)));

        if (!empty($allowed_cards)) {
            $card_keys = array_values(array_unique(array_merge(
                array_intersect($card_keys, $allowed_cards),
                $allowed_cards
            )));
        }
    }

    $order = $card_keys;
    $visibility = [];
    $sizes = [];

    foreach ($card_keys as $key) {
        $visibility[$key] = true;
        $sizes[$key] = 'medium';
    }

    return [
        'order'      => $order,
        'visibility' => $visibility,
        'sizes'      => $sizes,
        'theme'      => sitepulse_get_dashboard_default_theme(),
    ];
}

/**
 * Sanitizes a set of dashboard preferences.
 *
 * @param array            $raw_preferences Potentially unsanitized preferences.
 * @param string[]|null    $allowed_cards   Optional subset of cards to accept.
 *
 * @return array{
 *     order: string[],
 *     visibility: array<string,bool>,
 *     sizes: array<string,string>,
 *     theme: string
 * }
 */
function sitepulse_sanitize_dashboard_preferences($raw_preferences, $allowed_cards = null) {
    $defaults = sitepulse_get_dashboard_default_preferences($allowed_cards);
    $allowed_cards = $defaults['order'];
    $allowed_sizes = ['small', 'medium', 'large'];
    $allowed_themes = array_keys(sitepulse_get_dashboard_theme_options());

    $order = [];

    if (isset($raw_preferences['order']) && is_array($raw_preferences['order'])) {
        foreach ($raw_preferences['order'] as $card_key) {
            $card_key = sanitize_key((string) $card_key);

            if ($card_key !== '' && in_array($card_key, $allowed_cards, true) && !in_array($card_key, $order, true)) {
                $order[] = $card_key;
            }
        }
    }

    foreach ($allowed_cards as $card_key) {
        if (!in_array($card_key, $order, true)) {
            $order[] = $card_key;
        }
    }

    $visibility = [];

    if (isset($raw_preferences['visibility']) && is_array($raw_preferences['visibility'])) {
        foreach ($allowed_cards as $card_key) {
            if (array_key_exists($card_key, $raw_preferences['visibility'])) {
                $visibility[$card_key] = filter_var(
                    $raw_preferences['visibility'][$card_key],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );

                if ($visibility[$card_key] === null) {
                    $visibility[$card_key] = $defaults['visibility'][$card_key];
                }

                continue;
            }

            $visibility[$card_key] = $defaults['visibility'][$card_key];
        }
    } else {
        $visibility = $defaults['visibility'];
    }

    $sizes = [];

    if (isset($raw_preferences['sizes']) && is_array($raw_preferences['sizes'])) {
        foreach ($allowed_cards as $card_key) {
            if (array_key_exists($card_key, $raw_preferences['sizes'])) {
                $size_value = strtolower((string) $raw_preferences['sizes'][$card_key]);

                if (!in_array($size_value, $allowed_sizes, true)) {
                    $size_value = $defaults['sizes'][$card_key];
                }

                $sizes[$card_key] = $size_value;
                continue;
            }

            $sizes[$card_key] = $defaults['sizes'][$card_key];
        }
    } else {
        $sizes = $defaults['sizes'];
    }

    $theme = $defaults['theme'];

    if (isset($raw_preferences['theme'])) {
        $candidate = sanitize_key((string) $raw_preferences['theme']);

        if (in_array($candidate, $allowed_themes, true)) {
            $theme = $candidate;
        }
    }

    return [
        'order'      => $order,
        'visibility' => $visibility,
        'sizes'      => $sizes,
        'theme'      => $theme,
    ];
}

/**
 * Returns the saved dashboard preferences for a given user.
 *
 * @param int              $user_id       Optional user identifier.
 * @param string[]|null    $allowed_cards Optional subset of cards to accept.
 *
 * @return array{
 *     order: string[],
 *     visibility: array<string,bool>,
 *     sizes: array<string,string>
 * }
 */
/**
 * Returns the option name used to store the preferred dashboard range.
 *
 * @return string
 */
function sitepulse_custom_dashboard_get_range_option_name() {
    return defined('SITEPULSE_OPTION_DASHBOARD_RANGE')
        ? SITEPULSE_OPTION_DASHBOARD_RANGE
        : 'sitepulse_dashboard_range';
}

/**
 * Retrieves the supported time ranges for dashboard metrics.
 *
 * @return array<string,array<string,int|string>>
 */
function sitepulse_custom_dashboard_get_metric_ranges() {
    $day_in_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

    $ranges = [
        '24h' => [
            'id'      => '24h',
            'label'   => __('Last 24 hours', 'sitepulse'),
            'seconds' => $day_in_seconds,
            'days'    => 1,
        ],
        '7d' => [
            'id'      => '7d',
            'label'   => __('Last 7 days', 'sitepulse'),
            'seconds' => $day_in_seconds * 7,
            'days'    => 7,
        ],
        '30d' => [
            'id'      => '30d',
            'label'   => __('Last 30 days', 'sitepulse'),
            'seconds' => $day_in_seconds * 30,
            'days'    => 30,
        ],
    ];

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_dashboard_metric_ranges', $ranges);

        if (is_array($filtered) && !empty($filtered)) {
            $ranges = $filtered;
        }
    }

    $normalized = [];

    foreach ($ranges as $key => $config) {
        $range_id = '';

        if (is_array($config) && isset($config['id'])) {
            $range_id = sanitize_key($config['id']);
        }

        if ($range_id === '') {
            $range_id = is_string($key) ? sanitize_key($key) : '';
        }

        if ($range_id === '') {
            continue;
        }

        $label = '';

        if (is_array($config) && isset($config['label']) && is_string($config['label'])) {
            $label = $config['label'];
        } else {
            $label = $range_id;
        }

        $seconds = 0;

        if (is_array($config) && isset($config['seconds'])) {
            $seconds = (int) $config['seconds'];
        }

        $days = 0;

        if (is_array($config) && isset($config['days'])) {
            $days = (int) $config['days'];
        }

        if ($seconds <= 0) {
            if ($days > 0) {
                $seconds = $days * $day_in_seconds;
            } elseif ($range_id === '24h') {
                $seconds = $day_in_seconds;
                $days    = 1;
            } elseif ($range_id === '7d') {
                $seconds = $day_in_seconds * 7;
                $days    = 7;
            } elseif ($range_id === '30d') {
                $seconds = $day_in_seconds * 30;
                $days    = 30;
            }
        }

        if ($days <= 0 && $seconds > 0) {
            $days = max(1, (int) round($seconds / $day_in_seconds));
        }

        $normalized[$range_id] = [
            'id'      => $range_id,
            'label'   => $label,
            'seconds' => max(0, $seconds),
            'days'    => max(1, $days),
        ];
    }

    if (empty($normalized)) {
        $normalized = [
            '7d' => [
                'id'      => '7d',
                'label'   => __('Last 7 days', 'sitepulse'),
                'seconds' => $day_in_seconds * 7,
                'days'    => 7,
            ],
        ];
    }

    return $normalized;
}

/**
 * Returns the default range identifier when no preference is stored.
 *
 * @return string
 */
function sitepulse_custom_dashboard_get_default_range() {
    $ranges = sitepulse_custom_dashboard_get_metric_ranges();

    if (isset($ranges['7d'])) {
        return '7d';
    }

    $keys = array_keys($ranges);

    return isset($keys[0]) ? $keys[0] : '7d';
}

/**
 * Sanitizes a range identifier against the supported configuration.
 *
 * @param mixed $value Raw range value.
 * @return string Sanitized range identifier or an empty string if unsupported.
 */
function sitepulse_custom_dashboard_sanitize_range($value) {
    if (!is_string($value) || $value === '') {
        return '';
    }

    $range = sanitize_key($value);
    $ranges = sitepulse_custom_dashboard_get_metric_ranges();

    if ($range !== '' && isset($ranges[$range])) {
        return $range;
    }

    return '';
}

/**
 * Retrieves the persisted dashboard range preference.
 *
 * @return string
 */
function sitepulse_custom_dashboard_get_stored_range() {
    $option_name = sitepulse_custom_dashboard_get_range_option_name();
    $stored      = get_option($option_name, '');
    $sanitized   = sitepulse_custom_dashboard_sanitize_range($stored);

    if ($sanitized !== '') {
        return $sanitized;
    }

    return sitepulse_custom_dashboard_get_default_range();
}

/**
 * Retrieves the current timestamp using WordPress when possible.
 *
 * @return int
 */
function sitepulse_custom_dashboard_get_current_timestamp() {
    if (function_exists('current_time')) {
        return (int) current_time('timestamp');
    }

    return time();
}

/**
 * Retrieves the configured uptime warning threshold.
 *
 * @return float
 */
function sitepulse_custom_dashboard_get_uptime_warning_threshold() {
    $default = defined('SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT')
        ? (float) SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT
        : 99.0;

    if (function_exists('sitepulse_get_uptime_warning_percentage')) {
        $threshold = (float) sitepulse_get_uptime_warning_percentage();
    } else {
        $option_key = defined('SITEPULSE_OPTION_UPTIME_WARNING_PERCENT')
            ? SITEPULSE_OPTION_UPTIME_WARNING_PERCENT
            : 'sitepulse_uptime_warning_percent';

        $stored = get_option($option_key, $default);

        $threshold = is_scalar($stored) ? (float) $stored : $default;
    }

    if ($threshold < 0) {
        $threshold = 0.0;
    } elseif ($threshold > 100) {
        $threshold = 100.0;
    }

    return $threshold;
}

/**
 * Resolves the status string for an uptime percentage.
 *
 * @param float|int|null $uptime_value Uptime percentage.
 *
 * @return string
 */
function sitepulse_custom_dashboard_resolve_uptime_status($uptime_value) {
    if (!is_numeric($uptime_value)) {
        return 'status-warn';
    }

    $threshold = sitepulse_custom_dashboard_get_uptime_warning_threshold();
    $value     = (float) $uptime_value;

    if ($value < $threshold) {
        return 'status-bad';
    }

    if ($value < 100.0) {
        return 'status-warn';
    }

    return 'status-ok';
}

/**
 * Resolves a human-readable label for the provided range identifier.
 *
 * @param string                          $range            Range identifier.
 * @param array<int,array<string,mixed>>  $available_ranges Available range definitions.
 *
 * @return string
 */
function sitepulse_custom_dashboard_resolve_range_label($range, $available_ranges) {
    if (is_array($available_ranges)) {
        foreach ($available_ranges as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $definition_id = isset($definition['id']) ? (string) $definition['id'] : '';

            if ($definition_id === '') {
                continue;
            }

            if ($definition_id === $range) {
                if (isset($definition['label']) && is_string($definition['label']) && $definition['label'] !== '') {
                    return $definition['label'];
                }

                return $definition_id;
            }
        }
    }

    $ranges = sitepulse_custom_dashboard_get_metric_ranges();

    if (isset($ranges[$range]['label']) && is_string($ranges[$range]['label'])) {
        return $ranges[$range]['label'];
    }

    switch ($range) {
        case '24h':
            return __('Last 24 hours', 'sitepulse');
        case '30d':
            return __('Last 30 days', 'sitepulse');
        case '7d':
        default:
            return __('Last 7 days', 'sitepulse');
    }
}
