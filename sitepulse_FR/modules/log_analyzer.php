<?php
if (!defined('ABSPATH')) exit;

// Add the submenu page for the Log Analyzer
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Log Analyzer', 'sitepulse'),
        __('Logs', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-logs',
        'sitepulse_log_analyzer_page'
    );
});

add_action('rest_api_init', 'sitepulse_log_analyzer_register_rest_routes');

/**
 * Returns the metadata describing each log severity section.
 *
 * @return array<string,array<string,string>>
 */
function sitepulse_log_analyzer_get_sections() {
    return [
        'fatal_errors' => [
            'class'       => 'notice notice-error',
            'icon'        => 'dashicons-dismiss',
            'title'       => esc_html__('Erreurs Fatales', 'sitepulse'),
            'description' => esc_html__("Une erreur critique qui casse votre site. Elle empêche votre site de se charger et doit être corrigée immédiatement.", 'sitepulse'),
            'severity'    => 'critical',
        ],
        'errors' => [
            'class'       => 'notice notice-error',
            'icon'        => 'dashicons-dismiss',
            'title'       => esc_html__('Erreurs', 'sitepulse'),
            'description' => esc_html__("Une erreur significative qui peut empêcher une fonctionnalité de marcher. Doit être traitée en priorité.", 'sitepulse'),
            'severity'    => 'error',
        ],
        'warnings' => [
            'class'       => 'notice notice-warning',
            'icon'        => 'dashicons-warning',
            'title'       => esc_html__('Avertissements', 'sitepulse'),
            'description' => esc_html__("Un problème non-critique. Votre site fonctionnera, mais cela indique un problème potentiel qui devrait être corrigé.", 'sitepulse'),
            'severity'    => 'warning',
        ],
        'notices' => [
            'class'       => 'notice notice-info',
            'icon'        => 'dashicons-info',
            'title'       => esc_html__('Notices', 'sitepulse'),
            'description' => esc_html__("Un message d'information pour les développeurs. C'est la plus basse priorité et généralement pas un sujet d'inquiétude.", 'sitepulse'),
            'severity'    => 'notice',
        ],
    ];
}

/**
 * Determines the severity key for a given log line.
 *
 * @param string $line Log entry.
 * @return string One of fatal_errors|errors|warnings|notices.
 */
function sitepulse_log_analyzer_identify_line_severity($line) {
    $candidate = trim((string) $line);

    if ($candidate === '') {
        return 'notices';
    }

    if (
        (function_exists('sitepulse_log_line_contains_fatal_error') && sitepulse_log_line_contains_fatal_error($candidate))
        || stripos($candidate, 'php fatal error') !== false
        || stripos($candidate, 'uncaught') !== false
    ) {
        return 'fatal_errors';
    }

    if (stripos($candidate, 'php parse error') !== false || stripos($candidate, 'php error') !== false) {
        return 'errors';
    }

    if (stripos($candidate, 'php warning') !== false || stripos($candidate, 'warning') === 0) {
        return 'warnings';
    }

    if (stripos($candidate, 'php notice') !== false || stripos($candidate, 'php deprecated') !== false) {
        return 'notices';
    }

    return 'notices';
}

/**
 * Splits recent log lines per severity and keeps track of their original order.
 *
 * @param array<int,string>|null $lines Log entries.
 * @return array{groups:array<string,string[]>,assignments:array<int,string>}
 */
function sitepulse_log_analyzer_categorize_lines($lines) {
    $groups = [
        'fatal_errors' => [],
        'errors'       => [],
        'warnings'     => [],
        'notices'      => [],
    ];

    $assignments = [];

    if (!is_array($lines)) {
        return [
            'groups'      => $groups,
            'assignments' => $assignments,
        ];
    }

    foreach ($lines as $index => $line) {
        if (!is_string($line)) {
            continue;
        }

        $trimmed = trim($line);

        if ($trimmed === '') {
            continue;
        }

        $severity = sitepulse_log_analyzer_identify_line_severity($trimmed);

        $groups[$severity][]   = $line;
        $assignments[$index] = $severity;
    }

    foreach ($groups as $key => $group_lines) {
        $groups[$key] = array_values($group_lines);
    }

    return [
        'groups'      => $groups,
        'assignments' => $assignments,
    ];
}

/**
 * Computes the dominant status based on counts per severity.
 *
 * @param array<string,int> $counts Severity counts.
 * @return string
 */
function sitepulse_log_analyzer_determine_status($counts) {
    if (!is_array($counts) || empty($counts)) {
        return 'ok';
    }

    if (!empty($counts['fatal_errors'])) {
        return 'critical';
    }

    if (!empty($counts['errors'])) {
        return 'error';
    }

    if (!empty($counts['warnings'])) {
        return 'warning';
    }

    if (!empty($counts['notices'])) {
        return 'notice';
    }

    return 'ok';
}

/**
 * Sanitizes the `levels` parameter accepted by the REST endpoint.
 *
 * @param mixed                $value   Raw value.
 * @param WP_REST_Request|null $request Current request instance.
 * @param string               $param   Parameter name.
 * @return array<int,string>
 */
function sitepulse_log_analyzer_sanitize_levels($value, $request = null, $param = '') {
    if (is_string($value)) {
        $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    }

    if (!is_array($value)) {
        return [];
    }

    $map = [
        'fatal'         => 'fatal_errors',
        'fatal_error'   => 'fatal_errors',
        'fatal_errors'  => 'fatal_errors',
        'critical'      => 'fatal_errors',
        'error'         => 'errors',
        'errors'        => 'errors',
        'warning'       => 'warnings',
        'warnings'      => 'warnings',
        'notice'        => 'notices',
        'notices'       => 'notices',
        'info'          => 'notices',
    ];

    $normalized = [];

    foreach ($value as $item) {
        $candidate = strtolower(trim((string) $item));

        if ($candidate === '') {
            continue;
        }

        if (isset($map[$candidate])) {
            $normalized[] = $map[$candidate];
        }
    }

    return array_values(array_unique($normalized));
}

require_once __DIR__ . '/log-analyzer/rest.php';


require_once __DIR__ . '/log-analyzer/page.php';
