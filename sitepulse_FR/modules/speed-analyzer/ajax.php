<?php
/**
 * SitePulse Speed Analyzer AJAX handlers.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the AJAX request to trigger a fresh speed scan.
 */
function sitepulse_ajax_run_speed_scan() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error([
            'message' => esc_html__("Vous n'avez pas les permissions nécessaires pour réaliser ce test.", 'sitepulse'),
        ], 403);
    }

    check_ajax_referer('sitepulse_speed_scan', 'nonce');

    $rate_limit = sitepulse_speed_analyzer_get_rate_limit();
    $last_run = (int) get_option('sitepulse_speed_scan_last_run', 0);
    $now = current_time('timestamp');
    $thresholds = sitepulse_speed_analyzer_get_thresholds();

    $automation_payload = sitepulse_speed_analyzer_build_automation_payload($thresholds);
    $profiles_catalog = sitepulse_speed_analyzer_get_profile_catalog();
    $profiles_for_js = sitepulse_speed_analyzer_prepare_profiles_for_js($profiles_catalog);
    $manual_profile_slug = isset($thresholds['profile']) ? sitepulse_speed_analyzer_normalize_profile($thresholds['profile']) : 'default';
    $manual_profile_label = isset($profiles_for_js[$manual_profile_slug]['label']) ? $profiles_for_js[$manual_profile_slug]['label'] : ucfirst($manual_profile_slug);
    $manual_profile_description = isset($profiles_for_js[$manual_profile_slug]['description']) ? $profiles_for_js[$manual_profile_slug]['description'] : '';

    if ($rate_limit > 0 && ($now - $last_run) < $rate_limit) {
        $remaining = max(0, $rate_limit - ($now - $last_run));
        $history = sitepulse_speed_analyzer_get_history_data();
        $latest = sitepulse_speed_analyzer_get_latest_entry($history);
        $aggregates = sitepulse_speed_analyzer_get_aggregates($history, $thresholds);

        wp_send_json_error([
            'message'          => sprintf(
                /* translators: %s: human readable delay before the next scan. */
                esc_html__('Veuillez patienter encore %s avant de relancer un test pour éviter de surcharger le serveur.', 'sitepulse'),
                esc_html(human_time_diff($now, $now + max(1, $remaining)))
            ),
            'status'           => 'throttled',
            'history'          => $history,
            'recommendations'  => sitepulse_speed_analyzer_build_recommendations($latest, $thresholds),
            'latest'           => $latest,
            'aggregates'       => $aggregates,
            'next_available'   => $last_run + $rate_limit,
            'rate_limit'       => $rate_limit,
            'remaining'        => $remaining,
            'automation'       => $automation_payload,
            'profiles'         => $profiles_for_js,
            'manualProfile'    => [
                'slug'        => $manual_profile_slug,
                'label'       => $manual_profile_label,
                'description' => $manual_profile_description,
            ],
        ], 429);
    }

    global $sitepulse_plugin_impact_tracker_force_persist;

    $previous_force_state = isset($sitepulse_plugin_impact_tracker_force_persist)
        ? (bool) $sitepulse_plugin_impact_tracker_force_persist
        : false;

    $sitepulse_plugin_impact_tracker_force_persist = true;
    sitepulse_plugin_impact_tracker_persist();
    $sitepulse_plugin_impact_tracker_force_persist = $previous_force_state;

    update_option('sitepulse_speed_scan_last_run', $now, false);

    $history = sitepulse_speed_analyzer_get_history_data();
    $latest = sitepulse_speed_analyzer_get_latest_entry($history);
    $aggregates = sitepulse_speed_analyzer_get_aggregates($history, $thresholds);

    wp_send_json_success([
        'message'         => esc_html__('Un nouveau relevé a été ajouté à votre historique.', 'sitepulse'),
        'history'         => $history,
        'latest'          => $latest,
        'recommendations' => sitepulse_speed_analyzer_build_recommendations($latest, $thresholds),
        'aggregates'      => $aggregates,
        'last_run'        => $now,
        'rate_limit'      => $rate_limit,
        'automation'      => $automation_payload,
        'profiles'        => $profiles_for_js,
        'manualProfile'   => [
            'slug'        => $manual_profile_slug,
            'label'       => $manual_profile_label,
            'description' => $manual_profile_description,
        ],
    ]);
}

/**
 * Builds the profiler payload for the Speed Analyzer script.
 *
 * @return array<string,mixed>
 */
function sitepulse_speed_analyzer_get_profiler_payload() {
    if (!function_exists('sitepulse_request_profiler_is_available') || !sitepulse_request_profiler_is_available()) {
        return ['enabled' => false];
    }

    $nonce_action = defined('SITEPULSE_NONCE_ACTION_REQUEST_TRACE') ? SITEPULSE_NONCE_ACTION_REQUEST_TRACE : 'sitepulse_request_trace';
    $history = [];

    if (function_exists('sitepulse_request_profiler_get_recent_traces')) {
        $history = sitepulse_request_profiler_get_recent_traces([
            'limit'   => 5,
            'user_id' => get_current_user_id(),
        ]);
    }

    return [
        'enabled'      => true,
        'nonce'        => wp_create_nonce($nonce_action),
        'startAction'  => 'sitepulse_start_trace',
        'fetchAction'  => 'sitepulse_get_trace',
        'pollInterval' => 2000,
        'timeout'      => 30000,
        'history'      => $history,
        'i18n'         => [
            'buttonIdle'      => esc_html__('Profiler cette page', 'sitepulse'),
            'buttonRunning'   => esc_html__('Profilage en cours…', 'sitepulse'),
            'buttonRetry'     => esc_html__('Relancer le profilage', 'sitepulse'),
            'statusTrigger'   => esc_html__('Initialisation du profilage…', 'sitepulse'),
            'statusPending'   => esc_html__('Collecte des mesures en cours…', 'sitepulse'),
            'statusCompleted' => esc_html__('Profilage terminé.', 'sitepulse'),
            'statusFailed'    => esc_html__('Profilage impossible. Réessayez dans quelques instants.', 'sitepulse'),
            'errorInvalidUrl' => esc_html__('URL de profilage invalide.', 'sitepulse'),
            'noHooks'         => esc_html__('Aucun hook lent détecté.', 'sitepulse'),
            'noQueries'       => esc_html__('Aucune requête SQL lente détectée.', 'sitepulse'),
            'historyEmpty'    => esc_html__('Aucun profilage récent pour le moment.', 'sitepulse'),
        ],
    ];
}

/**
 * Handles the AJAX request that initializes a profiling session.
 *
 * @return void
 */
function sitepulse_speed_analyzer_ajax_start_trace() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error(['message' => esc_html__('Accès refusé.', 'sitepulse')], 403);
    }

    $nonce_action = defined('SITEPULSE_NONCE_ACTION_REQUEST_TRACE') ? SITEPULSE_NONCE_ACTION_REQUEST_TRACE : 'sitepulse_request_trace';
    check_ajax_referer($nonce_action, 'nonce');

    $target = isset($_POST['target']) ? wp_unslash($_POST['target']) : '';
    $target = sitepulse_request_profiler_sanitize_target_url($target);

    if ($target === '') {
        wp_send_json_error(['message' => esc_html__('URL de profilage invalide.', 'sitepulse')], 400);
    }

    $session = sitepulse_request_profiler_create_session(get_current_user_id(), $target);

    if ($session === null) {
        wp_send_json_error(['message' => esc_html__('Impossible de créer la session de profilage.', 'sitepulse')], 500);
    }

    $trace_url = add_query_arg(
        [
            'sitepulse_trace' => $session['token'],
            '_wpnonce'        => wp_create_nonce($nonce_action),
        ],
        $session['target']
    );

    wp_send_json_success([
        'token' => $session['token'],
        'url'   => esc_url_raw($trace_url),
    ]);
}

/**
 * Handles the AJAX request that fetches the latest profiling result.
 *
 * @return void
 */
function sitepulse_speed_analyzer_ajax_get_trace() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error(['message' => esc_html__('Accès refusé.', 'sitepulse')], 403);
    }

    $nonce_action = defined('SITEPULSE_NONCE_ACTION_REQUEST_TRACE') ? SITEPULSE_NONCE_ACTION_REQUEST_TRACE : 'sitepulse_request_trace';
    check_ajax_referer($nonce_action, 'nonce');

    $token = isset($_POST['token']) ? wp_unslash($_POST['token']) : '';
    $token = sitepulse_request_profiler_normalize_token($token);

    if ($token === '') {
        wp_send_json_error(['message' => esc_html__('Jeton de profilage invalide.', 'sitepulse')], 400);
    }

    $result = sitepulse_request_profiler_get_result($token);

    if ($result === null) {
        wp_send_json_error(['message' => esc_html__('Profilage introuvable.', 'sitepulse')], 404);
    }

    if ((int) $result['user_id'] !== get_current_user_id()) {
        wp_send_json_error(['message' => esc_html__('Ce profilage appartient à un autre utilisateur.', 'sitepulse')], 403);
    }

    $status = isset($result['status']) ? (string) $result['status'] : 'pending';

    if ($status === 'pending') {
        wp_send_json_success(['status' => 'pending']);
    }

    if ($status === 'failed' || empty($result['trace_id'])) {
        wp_send_json_success(['status' => 'failed']);
    }

    $trace = sitepulse_request_profiler_get_trace((int) $result['trace_id']);

    if ($trace === null) {
        wp_send_json_success(['status' => 'failed']);
    }

    $hooks = [];

    foreach ($trace['hooks'] as $entry) {
        if (!is_array($entry) || empty($entry['hook'])) {
            continue;
        }

        $count = isset($entry['count']) ? (int) $entry['count'] : 0;
        $total = isset($entry['total_time']) ? (float) $entry['total_time'] : 0.0;
        $avg = isset($entry['avg_time']) ? (float) $entry['avg_time'] : 0.0;
        $max = isset($entry['max_time']) ? (float) $entry['max_time'] : 0.0;

        $hooks[] = [
            'hook'      => (string) $entry['hook'],
            'count'     => $count,
            'total_ms'  => round($total * 1000, 2),
            'avg_ms'    => round($avg * 1000, 2),
            'max_ms'    => round($max * 1000, 2),
        ];
    }

    $queries = [];

    foreach ($trace['queries'] as $entry) {
        if (!is_array($entry) || empty($entry['sql'])) {
            continue;
        }

        $total = isset($entry['total_time']) ? (float) $entry['total_time'] : 0.0;
        $avg = isset($entry['avg_time']) ? (float) $entry['avg_time'] : 0.0;
        $count = isset($entry['count']) ? (int) $entry['count'] : 0;
        $callers = isset($entry['callers']) && is_array($entry['callers']) ? array_values($entry['callers']) : [];

        $queries[] = [
            'sql'       => (string) $entry['sql'],
            'count'     => $count,
            'total_ms'  => round($total * 1000, 2),
            'avg_ms'    => round($avg * 1000, 2),
            'callers'   => array_slice($callers, 0, 5),
        ];
    }

    $summary = [
        'duration_ms' => round(((float) $trace['total_duration']) * 1000, 2),
        'hook_count'  => (int) $trace['hook_count'],
        'query_count' => (int) $trace['query_count'],
        'recorded_at' => isset($trace['recorded_at']) ? (string) $trace['recorded_at'] : '',
        'method'      => isset($trace['request_method']) ? (string) $trace['request_method'] : 'GET',
        'url'         => isset($trace['request_url']) ? (string) $trace['request_url'] : '',
        'memory_peak' => isset($trace['memory_peak']) ? (int) $trace['memory_peak'] : 0,
    ];

    $history_entry = null;

    if (function_exists('sitepulse_request_profiler_build_history_entry')) {
        $history_entry = sitepulse_request_profiler_build_history_entry(array_merge($trace, [
            'id' => (int) $result['trace_id'],
        ]));

        if (is_array($history_entry)) {
            $summary['trace_id'] = $history_entry['id'];
            $summary['display_date'] = $history_entry['display_date'];
            $summary['timestamp'] = $history_entry['timestamp'];
        }
    } else {
        $summary['trace_id'] = (int) $result['trace_id'];
    }

    wp_send_json_success([
        'status'  => 'completed',
        'summary' => $summary,
        'hooks'   => $hooks,
        'queries' => $queries,
        'history' => $history_entry,
    ]);
}
