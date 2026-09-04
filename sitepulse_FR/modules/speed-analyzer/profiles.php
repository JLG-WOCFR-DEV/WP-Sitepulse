<?php
/**
 * SitePulse Speed Analyzer profiles and thresholds.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retrieves the warning and critical thresholds for speed measurements.
 *
 * @param string $profile Optional profile identifier (desktop, mobile…).
 * @return array{warning:int,critical:int,default_warning:int,default_critical:int,profile:string}
 */
function sitepulse_speed_analyzer_get_thresholds($profile = 'default') {
    $profile = sitepulse_speed_analyzer_normalize_profile($profile);

    $default_speed_warning = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
    $default_speed_critical = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;
    $speed_warning_threshold = $default_speed_warning;
    $speed_critical_threshold = $default_speed_critical;

    if (function_exists('sitepulse_get_speed_thresholds')) {
        $fetched_thresholds = sitepulse_get_speed_thresholds($profile);

        if (!is_array($fetched_thresholds) && $profile !== 'default') {
            $fetched_thresholds = sitepulse_get_speed_thresholds('default');
        }

        if (is_array($fetched_thresholds)) {
            if (isset($fetched_thresholds['warning']) && is_numeric($fetched_thresholds['warning'])) {
                $speed_warning_threshold = (int) $fetched_thresholds['warning'];
            }

            if (isset($fetched_thresholds['critical']) && is_numeric($fetched_thresholds['critical'])) {
                $speed_critical_threshold = (int) $fetched_thresholds['critical'];
            }
        }
    } else {
        $warning_option_key = defined('SITEPULSE_OPTION_SPEED_WARNING_MS') ? SITEPULSE_OPTION_SPEED_WARNING_MS : 'sitepulse_speed_warning_ms';
        $critical_option_key = defined('SITEPULSE_OPTION_SPEED_CRITICAL_MS') ? SITEPULSE_OPTION_SPEED_CRITICAL_MS : 'sitepulse_speed_critical_ms';

        $stored_warning = get_option($warning_option_key, $default_speed_warning);
        $stored_critical = get_option($critical_option_key, $default_speed_critical);

        if (is_numeric($stored_warning)) {
            $speed_warning_threshold = (int) $stored_warning;
        }

        if (is_numeric($stored_critical)) {
            $speed_critical_threshold = (int) $stored_critical;
        }
    }

    if ($speed_warning_threshold < 1) {
        $speed_warning_threshold = $default_speed_warning;
    }

    if ($speed_critical_threshold <= $speed_warning_threshold) {
        $speed_critical_threshold = max($speed_warning_threshold + 1, $default_speed_critical);
    }

    return [
        'warning'          => $speed_warning_threshold,
        'critical'         => $speed_critical_threshold,
        'default_warning'  => $default_speed_warning,
        'default_critical' => $default_speed_critical,
        'profile'          => $profile,
    ];
}

/**
 * Returns the available performance profiles.
 *
 * @return array<string,array{label:string,description:string}>
 */
function sitepulse_speed_analyzer_get_profile_catalog() {
    $profiles = [
        'default' => [
            'label'       => __('Standard', 'sitepulse'),
            'description' => __('Profil générique aligné sur les seuils globaux du site.', 'sitepulse'),
        ],
        'desktop' => [
            'label'       => __('Desktop – Core Web Vitals', 'sitepulse'),
            'description' => __('Référence bureau inspirée des budgets de performance PageSpeed (connexion rapide).', 'sitepulse'),
        ],
        'mobile' => [
            'label'       => __('Mobile – Core Web Vitals', 'sitepulse'),
            'description' => __('Budget mobile plus strict pour simuler un smartphone 4G.', 'sitepulse'),
        ],
    ];

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('sitepulse_speed_analyzer_profiles', $profiles);

        if (is_array($filtered)) {
            $profiles = [];

            foreach ($filtered as $slug => $profile) {
                if (!is_string($slug) || $slug === '') {
                    continue;
                }

                if (!is_array($profile)) {
                    continue;
                }

                $label = isset($profile['label']) ? (string) $profile['label'] : ucfirst($slug);
                $description = isset($profile['description']) ? (string) $profile['description'] : '';

                $profiles[sanitize_key($slug)] = [
                    'label'       => $label,
                    'description' => $description,
                ];
            }
        }
    }

    if (!isset($profiles['default'])) {
        $profiles['default'] = [
            'label'       => __('Standard', 'sitepulse'),
            'description' => __('Profil générique aligné sur les seuils globaux du site.', 'sitepulse'),
        ];
    }

    return $profiles;
}

/**
 * Normalizes a profile identifier against the catalog.
 *
 * @param mixed $profile Raw profile value.
 *
 * @return string
 */
function sitepulse_speed_analyzer_normalize_profile($profile) {
    $profile = is_string($profile) ? sanitize_key($profile) : '';

    if ($profile === '') {
        $profile = 'default';
    }

    $catalog = sitepulse_speed_analyzer_get_profile_catalog();

    if (!isset($catalog[$profile])) {
        return 'default';
    }

    return $profile;
}

/**
 * Prepares the profile catalog for client-side usage.
 *
 * @param array<string,array{label:string,description:string}>|null $catalog Optional catalog override.
 *
 * @return array<string,array{label:string,description:string}>
 */
function sitepulse_speed_analyzer_prepare_profiles_for_js($catalog = null) {
    if ($catalog === null) {
        $catalog = sitepulse_speed_analyzer_get_profile_catalog();
    }

    $prepared = [];

    if (!is_array($catalog)) {
        return $prepared;
    }

    foreach ($catalog as $slug => $profile) {
        if (!is_string($slug) || $slug === '') {
            continue;
        }

        $prepared[$slug] = [
            'label'       => isset($profile['label']) ? (string) $profile['label'] : ucfirst($slug),
            'description' => isset($profile['description']) ? (string) $profile['description'] : '',
        ];
    }

    return $prepared;
}

/**
 * Retrieves the benchmark configuration (competitors and budgets).
 *
 * @return array{competitors:array<int,array{slug:string,label:string,url:string,type:string}>,budgets:array<string,int>}
 */
function sitepulse_speed_analyzer_get_benchmark_settings() {
    $defaults = [
        'competitors' => [],
        'budgets'     => [],
    ];

    $stored = get_option(
        defined('SITEPULSE_OPTION_SPEED_BENCHMARKS') ? SITEPULSE_OPTION_SPEED_BENCHMARKS : 'sitepulse_speed_benchmarks',
        $defaults
    );

    if (!is_array($stored)) {
        $stored = $defaults;
    }

    $competitors = [];
    $raw_competitors = isset($stored['competitors']) ? $stored['competitors'] : [];

    if (is_string($raw_competitors)) {
        $raw_competitors = preg_split('/[\r\n]+/', $raw_competitors) ?: [];
    }

    if (is_array($raw_competitors)) {
        foreach ($raw_competitors as $entry) {
            if (is_string($entry)) {
                $entry = ['url' => $entry];
            }

            if (!is_array($entry)) {
                continue;
            }

            $url = isset($entry['url']) ? esc_url_raw((string) $entry['url']) : '';

            if ($url === '') {
                continue;
            }

            $host = wp_parse_url($url, PHP_URL_HOST);
            $label = isset($entry['label']) && $entry['label'] !== '' ? (string) $entry['label'] : ($host ? $host : $url);
            $slug_source = isset($entry['slug']) && $entry['slug'] !== '' ? (string) $entry['slug'] : ($host ? $host : md5($url));
            $slug = sanitize_key($slug_source);

            if ($slug === '') {
                $slug = substr(md5($slug_source), 0, 12);
            }

            $competitors[] = [
                'slug'  => $slug,
                'label' => $label,
                'url'   => $url,
                'type'  => 'competitor',
            ];
        }
    }

    $budgets = [];
    $raw_budgets = isset($stored['budgets']) && is_array($stored['budgets']) ? $stored['budgets'] : [];

    foreach ($raw_budgets as $profile => $value) {
        if (!is_scalar($profile)) {
            continue;
        }

        $profile_slug = sitepulse_speed_analyzer_normalize_profile($profile);
        $budget_value = is_numeric($value) ? (int) $value : 0;

        if ($budget_value > 0) {
            $budgets[$profile_slug] = $budget_value;
        }
    }

    return [
        'competitors' => $competitors,
        'budgets'     => $budgets,
    ];
}

/**
 * Retrieves the configured benchmark budget for a profile.
 *
 * @param string $profile Profile slug.
 *
 * @return int|null
 */
function sitepulse_speed_analyzer_get_profile_benchmark_budget($profile) {
    $settings = sitepulse_speed_analyzer_get_benchmark_settings();
    $profile = sitepulse_speed_analyzer_normalize_profile($profile);

    if (isset($settings['budgets'][$profile])) {
        return (int) $settings['budgets'][$profile];
    }

    return null;
}

/**
 * Builds a queue token for a preset/source pair.
 *
 * @param string $preset Preset identifier.
 * @param string $source Source identifier.
 *
 * @return string
 */
function sitepulse_speed_analyzer_build_queue_token($preset, $source) {
    $preset = sanitize_key($preset);
    $source = sanitize_key($source);

    if ($preset === '') {
        $preset = 'default';
    }

    if ($source === '') {
        $source = 'site';
    }

    return $preset . '|' . $source;
}

/**
 * Parses a queue token.
 *
 * @param mixed $token Queue entry.
 *
 * @return array{preset:string,source:string}
 */
function sitepulse_speed_analyzer_parse_queue_token($token) {
    $preset = '';
    $source = 'site';

    if (is_string($token)) {
        $parts = explode('|', $token, 2);
        $preset = isset($parts[0]) ? sanitize_key($parts[0]) : '';

        if (isset($parts[1])) {
            $source_candidate = sanitize_key($parts[1]);

            if ($source_candidate !== '') {
                $source = $source_candidate;
            }
        }
    } elseif (is_array($token)) {
        $preset = isset($token['preset']) ? sanitize_key($token['preset']) : '';
        $source_candidate = isset($token['source']) ? sanitize_key($token['source']) : '';

        if ($source_candidate !== '') {
            $source = $source_candidate;
        }
    }

    if ($preset === '') {
        $preset = 'default';
    }

    return [
        'preset' => $preset,
        'source' => $source,
    ];
}

/**
 * Resolves the targets that should be tested for a preset.
 *
 * @param string               $preset Preset slug.
 * @param array<string,string> $config Preset configuration.
 *
 * @return array<int,array{key:string,label:string,url:string,type:string,profile:string}>
 */
function sitepulse_speed_analyzer_resolve_targets_for_preset($preset, $config) {
    $targets = [];

    if (!is_array($config)) {
        $config = [];
    }

    $profile = isset($config['profile']) ? sitepulse_speed_analyzer_normalize_profile($config['profile']) : 'default';
    $url = isset($config['url']) ? esc_url_raw((string) $config['url']) : '';
    $label = isset($config['label']) ? (string) $config['label'] : ucfirst($preset);

    if ($url !== '') {
        $targets[] = [
            'key'     => 'site',
            'label'   => $label,
            'url'     => $url,
            'type'    => 'site',
            'profile' => $profile,
        ];
    }

    $benchmarks = sitepulse_speed_analyzer_get_benchmark_settings();

    foreach ($benchmarks['competitors'] as $competitor) {
        if (!isset($competitor['url']) || $competitor['url'] === '') {
            continue;
        }

        $targets[] = [
            'key'     => isset($competitor['slug']) ? (string) $competitor['slug'] : 'competitor',
            'label'   => isset($competitor['label']) ? (string) $competitor['label'] : $competitor['url'],
            'url'     => (string) $competitor['url'],
            'type'    => 'competitor',
            'profile' => $profile,
        ];
    }

    return $targets;
}

/**
 * Finds a resolved target matching the provided key.
 *
 * @param array<int,array<string,mixed>> $targets Target descriptors.
 * @param string                         $key     Target key.
 *
 * @return array<string,mixed>|null
 */
function sitepulse_speed_analyzer_get_target_by_key($targets, $key) {
    if (!is_array($targets)) {
        return null;
    }

    foreach ($targets as $target) {
        if (!is_array($target)) {
            continue;
        }

        if (!isset($target['key'])) {
            continue;
        }

        if (sanitize_key($target['key']) === sanitize_key($key)) {
            return $target;
        }
    }

    return null;
}
