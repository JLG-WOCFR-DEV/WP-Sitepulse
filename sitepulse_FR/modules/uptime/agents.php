<?php
/**
 * SitePulse Uptime agent configuration.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retrieves the configured cron schedule for uptime checks.
 *
 * @return string
 */
function sitepulse_uptime_tracker_get_schedule() {
    $default = defined('SITEPULSE_DEFAULT_UPTIME_FREQUENCY') ? SITEPULSE_DEFAULT_UPTIME_FREQUENCY : 'hourly';
    $option  = get_option(SITEPULSE_OPTION_UPTIME_FREQUENCY, $default);

    if (function_exists('sitepulse_sanitize_uptime_frequency')) {
        $option = sitepulse_sanitize_uptime_frequency($option);
    } elseif (!is_string($option) || $option === '') {
        $option = $default;
    }

    $choices = function_exists('sitepulse_get_uptime_frequency_choices') ? sitepulse_get_uptime_frequency_choices() : [];

    if (!isset($choices[$option])) {
        $option = $default;
    }

    return $option;
}

/**
 * Sanitizes the uptime agent definitions before storage.
 *
 * @param mixed $value Raw agent configuration.
 * @return array<string,array<string,mixed>>
 */
function sitepulse_uptime_sanitize_agents($value) {
    $existing = get_option(SITEPULSE_OPTION_UPTIME_AGENTS, []);

    if (!is_array($existing)) {
        $existing = [];
    }

    if (!is_array($value)) {
        $value = [];
    }

    $sanitized = [];
    $generated_index = 0;

    foreach ($value as $raw_agent) {
        if (!is_array($raw_agent)) {
            continue;
        }

        $label = isset($raw_agent['label']) ? sanitize_text_field($raw_agent['label']) : '';
        $region = isset($raw_agent['region']) ? sanitize_key($raw_agent['region']) : '';
        $identifier = isset($raw_agent['id']) ? sanitize_key($raw_agent['id']) : '';

        if ($identifier === '' && isset($raw_agent['slug'])) {
            $identifier = sanitize_key($raw_agent['slug']);
        }

        if ($identifier === '' && $label !== '') {
            $identifier = sanitize_key($label);
        }

        if ($identifier === '') {
            if ($label === '') {
                continue;
            }

            $generated_index++;
            $identifier = sanitize_key('agent_' . $generated_index);
        }

        if ($identifier === '' || isset($sanitized[$identifier])) {
            continue;
        }

        $url = '';

        if (isset($raw_agent['url']) && is_string($raw_agent['url'])) {
            $candidate_url = trim($raw_agent['url']);

            if ($candidate_url !== '') {
                $validated_url = wp_http_validate_url($candidate_url);

                if ($validated_url) {
                    $url = esc_url_raw($validated_url);
                }
            }
        }

        $timeout = null;

        if (isset($raw_agent['timeout']) && $raw_agent['timeout'] !== '') {
            $timeout_candidate = is_numeric($raw_agent['timeout']) ? (int) $raw_agent['timeout'] : null;

            if (null !== $timeout_candidate && $timeout_candidate > 0) {
                $timeout = $timeout_candidate;
            }
        }

        $weight = isset($raw_agent['weight']) && is_numeric($raw_agent['weight'])
            ? (float) $raw_agent['weight']
            : 1.0;

        if ($weight <= 0) {
            $weight = 1.0;
        }

        $active = !empty($raw_agent['active']);

        $existing_agent = isset($existing[$identifier]) && is_array($existing[$identifier])
            ? $existing[$identifier]
            : [];

        $headers = isset($existing_agent['headers']) && is_array($existing_agent['headers'])
            ? $existing_agent['headers']
            : [];

        if (!empty($raw_agent['headers']) && is_array($raw_agent['headers'])) {
            $headers = $raw_agent['headers'];
        }

        if (function_exists('sitepulse_sanitize_uptime_http_headers')) {
            $headers = sitepulse_sanitize_uptime_http_headers($headers);
        }

        $expected_codes = isset($existing_agent['expected_codes']) && is_array($existing_agent['expected_codes'])
            ? $existing_agent['expected_codes']
            : [];

        if (!empty($raw_agent['expected_codes']) && is_array($raw_agent['expected_codes'])) {
            $expected_codes = $raw_agent['expected_codes'];
        }

        if (function_exists('sitepulse_sanitize_uptime_expected_codes')) {
            $expected_codes = sitepulse_sanitize_uptime_expected_codes($expected_codes);
        }

        $agent = [
            'label'          => $label !== '' ? $label : ucfirst(str_replace('_', ' ', $identifier)),
            'region'         => $region !== '' ? $region : 'global',
            'url'            => $url,
            'timeout'        => null === $timeout ? null : max(1, (int) $timeout),
            'method'         => isset($existing_agent['method']) ? $existing_agent['method'] : null,
            'headers'        => $headers,
            'expected_codes' => $expected_codes,
            'active'         => $active,
            'weight'         => (float) $weight,
        ];

        if (isset($existing_agent['metadata']) && is_array($existing_agent['metadata'])) {
            $agent['metadata'] = $existing_agent['metadata'];
        }

        $sanitized[$identifier] = $agent;
    }

    if (empty($sanitized)) {
        return [];
    }

    /**
     * Filters the sanitized agent configuration prior to persistence.
     *
     * @param array<string,array<string,mixed>> $sanitized Sanitized agents.
     * @param array<mixed>                       $raw       Raw submitted payload.
     * @param array<string,array<string,mixed>>  $existing  Previously saved agents.
     */
    $sanitized = apply_filters('sitepulse_uptime_sanitized_agents', $sanitized, $value, $existing);

    /**
     * Fires after the agent configuration has been sanitized.
     *
     * @param array<string,array<string,mixed>> $sanitized Sanitized agents.
     * @param array<string,array<string,mixed>> $existing  Previously saved agents.
     * @param array<mixed>                       $raw       Raw submitted payload.
     */
    do_action('sitepulse_uptime_agents_prepared', $sanitized, $existing, $value);

    return $sanitized;
}

/**
 * Returns the configured uptime monitoring agents.
 *
 * @return array<string,array<string,mixed>>
 */
function sitepulse_uptime_get_agents() {
    $agents = get_option(SITEPULSE_OPTION_UPTIME_AGENTS, []);

    if (!is_array($agents) || empty($agents)) {
        $agents = [
            'default' => [
                'label'  => __('Agent principal', 'sitepulse'),
                'region' => 'global',
                'active' => true,
                'weight' => 1.0,
            ],
        ];
    }

    foreach ($agents as $agent_id => $agent_data) {
        if (!is_array($agent_data)) {
            $agent_data = [];
        }

        $agents[$agent_id] = wp_parse_args($agent_data, [
            'label'          => ucfirst(str_replace('_', ' ', $agent_id)),
            'region'         => 'global',
            'url'            => '',
            'timeout'        => null,
            'method'         => null,
            'headers'        => [],
            'expected_codes' => [],
            'active'         => true,
            'weight'         => 1.0,
        ]);

        $agents[$agent_id]['region'] = sanitize_key($agents[$agent_id]['region']);
        $agents[$agent_id]['weight'] = (float) max(0.0, $agents[$agent_id]['weight']);
    }

    /**
     * Filters the agent definitions returned by SitePulse.
     *
     * @param array<string,array<string,mixed>> $agents Agent configuration keyed by identifier.
     */
    return apply_filters('sitepulse_uptime_agents', $agents);
}

/**
 * Retrieves a single agent definition.
 *
 * @param string $agent_id Agent identifier.
 * @return array<string,mixed>
 */
function sitepulse_uptime_get_agent($agent_id) {
    $agent_id = sitepulse_uptime_normalize_agent_id($agent_id);
    $agents = sitepulse_uptime_get_agents();

    if (!isset($agents[$agent_id])) {
        return [
            'label'          => __('Agent principal', 'sitepulse'),
            'region'         => 'global',
            'url'            => '',
            'timeout'        => null,
            'method'         => null,
            'headers'        => [],
            'expected_codes' => [],
            'active'         => true,
            'weight'         => 1.0,
        ];
    }

    return $agents[$agent_id];
}

/**
 * Determines whether an agent is active.
 *
 * @param string                          $agent_id     Agent identifier.
 * @param array<string,mixed>|null        $agent_config Optional configuration override.
 * @return bool
 */
function sitepulse_uptime_agent_is_active($agent_id, $agent_config = null) {
    if (null === $agent_config) {
        $agent_config = sitepulse_uptime_get_agent($agent_id);
    }

    $is_active = !isset($agent_config['active']) || (bool) $agent_config['active'];

    /**
     * Filters whether a given agent should be considered active.
     *
     * @param bool                           $is_active     Whether the agent is active.
     * @param string                         $agent_id      Agent identifier.
     * @param array<string,mixed>|null       $agent_config Agent configuration.
     */
    return (bool) apply_filters('sitepulse_uptime_agent_is_active', $is_active, $agent_id, $agent_config);
}

/**
 * Returns the normalized weight for an agent.
 *
 * @param string                          $agent_id     Agent identifier.
 * @param array<string,mixed>|null        $agent_config Optional configuration override.
 * @return float
 */
function sitepulse_uptime_get_agent_weight($agent_id, $agent_config = null) {
    if (null === $agent_config) {
        $agent_config = sitepulse_uptime_get_agent($agent_id);
    }

    $weight = isset($agent_config['weight']) && is_numeric($agent_config['weight'])
        ? (float) $agent_config['weight']
        : 1.0;

    if ($weight < 0) {
        $weight = 0.0;
    }

    /**
     * Filters the weight applied to an agent.
     *
     * @param float                          $weight        Agent weight.
     * @param string                         $agent_id      Agent identifier.
     * @param array<string,mixed>|null       $agent_config Agent configuration.
     */
    $weight = apply_filters('sitepulse_uptime_agent_weight', $weight, $agent_id, $agent_config);

    return (float) max(0.0, $weight);
}

/**
 * Normalises an agent identifier.
 *
 * @param string $agent_id Raw identifier.
 * @return string
 */
function sitepulse_uptime_normalize_agent_id($agent_id) {
    if (!is_string($agent_id) || $agent_id === '') {
        return 'default';
    }

    $agent_id = sanitize_key($agent_id);

    if ($agent_id === '') {
        return 'default';
    }

    return $agent_id;
}
