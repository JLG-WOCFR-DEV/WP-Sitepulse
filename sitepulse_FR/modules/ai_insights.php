<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('AI Insights', 'sitepulse'),
        __('AI Insights', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-ai',
        'sitepulse_ai_insights_page'
    );
});
add_action('admin_enqueue_scripts', 'sitepulse_ai_insights_enqueue_assets');
add_action('wp_ajax_sitepulse_generate_ai_insight', 'sitepulse_generate_ai_insight');
add_action('wp_ajax_sitepulse_get_ai_insight_status', 'sitepulse_get_ai_insight_status');
add_action('wp_ajax_sitepulse_run_ai_insight_job', 'sitepulse_ai_handle_async_job_request');
add_action('wp_ajax_nopriv_sitepulse_run_ai_insight_job', 'sitepulse_ai_handle_async_job_request');
add_action('sitepulse_run_ai_insight_job', 'sitepulse_run_ai_insight_job', 10, 2);
add_action('wp_ajax_sitepulse_save_ai_history_note', 'sitepulse_ai_save_history_note');
add_action('admin_notices', 'sitepulse_ai_render_error_notices');
add_action('admin_notices', 'sitepulse_ai_render_alert_notices');
add_action('rest_api_init', 'sitepulse_ai_register_rest_routes');
add_action('sitepulse_ai_job_failed', 'sitepulse_ai_handle_job_failed_alert', 10, 3);
add_action('sitepulse_ai_quota_warning', 'sitepulse_ai_handle_quota_warning_alert', 10, 2);

if (!defined('SITEPULSE_TRANSIENT_AI_INSIGHT_JOB_PREFIX')) {
    define('SITEPULSE_TRANSIENT_AI_INSIGHT_JOB_PREFIX', 'sitepulse_ai_job_');
}

if (!defined('SITEPULSE_OPTION_AI_INSIGHT_ERRORS')) {
    define('SITEPULSE_OPTION_AI_INSIGHT_ERRORS', 'sitepulse_ai_insight_errors');
}

if (!defined('SITEPULSE_OPTION_AI_HISTORY')) {
    define('SITEPULSE_OPTION_AI_HISTORY', 'sitepulse_ai_history');
}

if (!defined('SITEPULSE_OPTION_AI_HISTORY_NOTES')) {
    define('SITEPULSE_OPTION_AI_HISTORY_NOTES', 'sitepulse_ai_history_notes');
}

if (!defined('SITEPULSE_OPTION_AI_JOB_SECRET')) {
    define('SITEPULSE_OPTION_AI_JOB_SECRET', 'sitepulse_ai_job_secret');
}

if (!defined('SITEPULSE_OPTION_AI_RETRY_AFTER')) {
    define('SITEPULSE_OPTION_AI_RETRY_AFTER', 'sitepulse_ai_retry_after');
}

if (!defined('SITEPULSE_OPTION_AI_QUEUE_INDEX')) {
    define('SITEPULSE_OPTION_AI_QUEUE_INDEX', 'sitepulse_ai_queue_index');
}

if (!defined('SITEPULSE_OPTION_AI_JOBS_LOG')) {
    define('SITEPULSE_OPTION_AI_JOBS_LOG', 'sitepulse_ai_jobs_log');
}

if (!defined('SITEPULSE_OPTION_AI_ALERT_NOTICES')) {
    define('SITEPULSE_OPTION_AI_ALERT_NOTICES', 'sitepulse_ai_alert_notices');
}

/**
 * Returns the HTML tags allowed in AI insight content.
 *
 * @return array<string,mixed>
 */
function sitepulse_ai_get_allowed_insight_html_tags() {
    $allowed_tags = [
        'p'          => [],
        'br'         => [],
        'strong'     => [],
        'em'         => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'blockquote' => [],
        'code'       => [],
        'pre'        => [],
        'a'          => [
            'href'   => true,
            'rel'    => true,
            'target' => true,
            'title'  => true,
        ],
    ];

    /**
     * Filters the HTML tags allowed when sanitizing AI insight content.
     *
     * @param array<string,mixed> $allowed_tags Allowed HTML tags.
     */
    return (array) apply_filters('sitepulse_ai_insight_allowed_tags', $allowed_tags);
}

/**
 * Sanitizes AI insight HTML content.
 *
 * @param string $html Raw HTML content.
 *
 * @return string Sanitized HTML.
 */
function sitepulse_ai_sanitize_insight_html($html) {
    $html = (string) $html;

    if ('' === $html) {
        return '';
    }

    $sanitized = wp_kses($html, sitepulse_ai_get_allowed_insight_html_tags());

    return trim($sanitized);
}

/**
 * Sanitizes AI insight plain text content.
 *
 * @param string $text Raw text content.
 *
 * @return string Sanitized plain text.
 */
function sitepulse_ai_sanitize_insight_text($text) {
    $text = (string) $text;

    if ('' === $text) {
        return '';
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = wp_strip_all_tags($text, true);
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

/**
 * Builds sanitized HTML and text variants for AI insights.
 *
 * @param string $text Raw text content.
 * @param string $html Optional raw HTML content.
 *
 * @return array{text:string,html:string}
 */
function sitepulse_ai_prepare_insight_variants($text, $html = '') {
    $raw_text = (string) $text;
    $raw_html = (string) $html;

    if ('' === $raw_html && '' !== $raw_text) {
        $raw_html = wpautop($raw_text);
    }

    $sanitized_html = sitepulse_ai_sanitize_insight_html($raw_html);

    $text_source = '' !== $raw_text ? $raw_text : $sanitized_html;
    $sanitized_text = sitepulse_ai_sanitize_insight_text($text_source);

    if ('' === $sanitized_text && '' !== $sanitized_html) {
        $sanitized_text = sitepulse_ai_sanitize_insight_text($sanitized_html);
    }

    if ('' === $sanitized_html && '' !== $sanitized_text) {
        $sanitized_html = sitepulse_ai_sanitize_insight_html(wpautop($sanitized_text));
    }

    return [
        'text' => $sanitized_text,
        'html' => $sanitized_html,
    ];
}

require_once __DIR__ . '/ai-insights/queue.php';

require_once __DIR__ . '/ai-insights/rest.php';

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command')) {
    /**
     * WP-CLI command for rotating the AI job secret.
     */
    class SitePulse_AI_Secret_CLI_Command extends WP_CLI_Command {
        /**
         * Regenerates the secret used to trigger AI insight jobs.
         *
         * ## EXAMPLES
         *
         *     wp sitepulse ai secret regenerate
         */
        public function regenerate() {
            if (!function_exists('sitepulse_ai_regenerate_job_secret')) {
                WP_CLI::error('La fonction de régénération du secret IA est indisponible.');
            }

            $secret = sitepulse_ai_regenerate_job_secret();

            WP_CLI::success(sprintf('Nouveau secret IA généré : %s', $secret));
        }
    }

    /**
     * WP-CLI command for managing the SitePulse AI queue.
     */
    class SitePulse_AI_Queue_CLI_Command extends WP_CLI_Command {
        /**
         * Lists queued AI jobs.
         *
         * ## EXAMPLES
         *
         *     wp sitepulse ai queue list
         */
        public function list_() {
            $jobs = sitepulse_ai_get_queue_snapshot();

            if (empty($jobs)) {
                WP_CLI::success('La file AI est vide.');

                return;
            }

            $items = [];

            foreach ($jobs as $job) {
                $items[] = [
                    'id'        => isset($job['id']) ? $job['id'] : '',
                    'status'    => isset($job['status_label']) ? $job['status_label'] : (isset($job['status']) ? $job['status'] : ''),
                    'priority'  => isset($job['priority_label']) ? $job['priority_label'] : (isset($job['priority']) ? $job['priority'] : ''),
                    'attempt'   => isset($job['attempt']) ? $job['attempt'] : '',
                    'position'  => isset($job['position']) && isset($job['size']) ? sprintf('%d/%d', $job['position'], $job['size']) : '',
                    'engine'    => isset($job['engine_label']) ? $job['engine_label'] : (isset($job['engine']) ? $job['engine'] : ''),
                    'next_run'  => isset($job['next_attempt_display']) ? $job['next_attempt_display'] : '',
                ];
            }

            WP_CLI\Utils\format_items('table', $items, ['id', 'status', 'priority', 'attempt', 'position', 'engine', 'next_run']);
        }

        /**
         * Retries a failed or pending AI job immediately.
         *
         * ## OPTIONS
         *
         * <job-id>
         * : Identifiant de la tâche.
         */
        public function retry($args) {
            if (empty($args[0])) {
                WP_CLI::error('Veuillez fournir un identifiant de tâche.');
            }

            $job_id = sanitize_key($args[0]);
            $retry  = sitepulse_ai_queue_retry_job($job_id);

            if (is_wp_error($retry)) {
                WP_CLI::error($retry->get_error_message());
            }

            WP_CLI::success(sprintf('Relance planifiée pour la tâche %s.', $job_id));
        }

        /**
         * Purges the AI queue.
         */
        public function purge() {
            $purged = sitepulse_ai_queue_purge();

            WP_CLI::success(sprintf('%d tâche(s) supprimée(s) de la file.', (int) $purged));
        }
    }

    WP_CLI::add_command('sitepulse ai secret', 'SitePulse_AI_Secret_CLI_Command');
    WP_CLI::add_command('sitepulse ai queue', 'SitePulse_AI_Queue_CLI_Command');
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

require_once __DIR__ . '/ai-insights/history.php';
require_once __DIR__ . '/ai-insights/alerts.php';
require_once __DIR__ . '/ai-insights/retry.php';
require_once __DIR__ . '/ai-insights/generate.php';

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

function sitepulse_ai_insights_enqueue_assets($hook_suffix) {
    if ('sitepulse-dashboard_page_sitepulse-ai' !== $hook_suffix) {
        return;
    }

    wp_register_style(
        'sitepulse-ai-insights-styles',
        SITEPULSE_URL . 'modules/css/ai-insights.css',
        [],
        SITEPULSE_VERSION
    );

    wp_register_script(
        'sitepulse-ai-insights',
        SITEPULSE_URL . 'modules/js/sitepulse-ai-insights.js',
        ['jquery'],
        SITEPULSE_VERSION,
        true
    );

    wp_enqueue_style('sitepulse-ai-insights-styles');

    $stored_insight     = sitepulse_ai_get_cached_insight();
    $insight_text       = isset($stored_insight['text']) ? $stored_insight['text'] : '';
    $insight_html       = isset($stored_insight['html']) ? $stored_insight['html'] : '';
    $insight_timestamp  = isset($stored_insight['timestamp']) ? absint($stored_insight['timestamp']) : null;
    $history_entries      = sitepulse_ai_get_history_entries();
    $history_models       = sitepulse_ai_get_history_filter_options($history_entries, 'model');
    $history_rate_limits  = sitepulse_ai_get_history_filter_options($history_entries, 'rate_limit');
    $history_max_entries  = sitepulse_ai_get_history_max_entries();
    $history_export_rows  = sitepulse_ai_prepare_history_export_rows($history_entries);
    $history_page_url     = admin_url('admin.php?page=sitepulse-ai');
    $history_export_name  = sanitize_file_name('sitepulse-ai-historique');
    $site_name            = wp_strip_all_tags(get_bloginfo('name', 'display'));
    $site_url             = home_url('/');
    $queue_snapshot       = sitepulse_ai_get_queue_snapshot();
    $jobs_log_entries     = sitepulse_ai_get_job_log();
    $jobs_log_payload     = sitepulse_ai_prepare_jobs_for_rest($jobs_log_entries);
    $jobs_metrics         = sitepulse_ai_calculate_job_metrics($jobs_log_entries);

    wp_localize_script(
        'sitepulse-ai-insights',
        'sitepulseAIInsights',
        [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce(SITEPULSE_NONCE_ACTION_AI_INSIGHT),
            'initialInsight'    => $insight_text,
            'initialInsightHtml' => $insight_html,
            'initialTimestamp'  => null !== $insight_timestamp ? absint($insight_timestamp) : null,
            'historyEntries'    => $history_entries,
            'historyFilters'    => [
                'models'     => $history_models,
                'rateLimits' => $history_rate_limits,
            ],
            'historyMaxEntries' => $history_max_entries,
            'historyExport'     => [
                'fileName' => $history_export_name,
                'rows'     => $history_export_rows,
                'headers'  => [
                    'timestamp_display' => esc_html__('Date', 'sitepulse'),
                    'model'             => esc_html__('Modèle', 'sitepulse'),
                    'rate_limit'        => esc_html__('Limitation', 'sitepulse'),
                    'text'              => esc_html__('Recommandation', 'sitepulse'),
                    'note'              => esc_html__('Note', 'sitepulse'),
                ],
                'columns' => ['timestamp_display', 'model', 'rate_limit', 'text', 'note'],
            ],
            'historyContext'    => [
                'pageUrl'  => esc_url_raw($history_page_url),
                'siteName' => $site_name,
                'siteUrl'  => esc_url_raw($site_url),
            ],
            'initialQueue'      => $queue_snapshot,
            'jobsLog'           => $jobs_log_payload,
            'jobsMetrics'       => $jobs_metrics,
            'noteAction'        => 'sitepulse_save_ai_history_note',
            'strings'           => [
                'defaultError'    => esc_html__('Une erreur inattendue est survenue. Veuillez réessayer.', 'sitepulse'),
                'cachedPrefix'    => esc_html__('Dernière mise à jour :', 'sitepulse'),
                'statusCached'    => esc_html__('Résultat issu du cache.', 'sitepulse'),
                'statusFresh'     => esc_html__('Nouvelle analyse générée.', 'sitepulse'),
                'statusFreshForced' => esc_html__('Nouvelle analyse générée (rafraîchie manuellement).', 'sitepulse'),
                'statusGenerating' => esc_html__('Génération en cours…', 'sitepulse'),
                'statusQueued'    => esc_html__('Analyse en attente de traitement…', 'sitepulse'),
                'statusPending'   => esc_html__('Nouvelle tentative programmée…', 'sitepulse'),
                'statusFailed'    => esc_html__('La génération a échoué. Veuillez réessayer.', 'sitepulse'),
                'statusQueuedSince' => esc_html__('Analyse en attente depuis %s.', 'sitepulse'),
                'statusRunningSince' => esc_html__('Analyse en cours depuis %s.', 'sitepulse'),
                'statusFinishedAt' => esc_html__('Terminée le %s.', 'sitepulse'),
                'statusFallbackSynchronous' => esc_html__('Analyse exécutée immédiatement (WP-Cron indisponible).', 'sitepulse'),
                'historyEmpty'    => esc_html__('Aucun historique disponible pour le moment.', 'sitepulse'),
                'historyExportCsv' => esc_html__('Exporter en CSV', 'sitepulse'),
                'historyCopy'     => esc_html__('Copier', 'sitepulse'),
                'historyCopied'   => esc_html__('Historique copié dans le presse-papiers.', 'sitepulse'),
                'historyCopyError' => esc_html__('Impossible de copier l’historique. Veuillez réessayer.', 'sitepulse'),
                'historyDownload' => esc_html__('Téléchargement de l’historique démarré.', 'sitepulse'),
                'historyNoEntries' => esc_html__('Aucune recommandation à exporter pour ces filtres.', 'sitepulse'),
                'historyNoteLabel' => esc_html__('Note personnelle', 'sitepulse'),
                'historyNotePlaceholder' => esc_html__('Ajoutez un commentaire ou un plan d’action…', 'sitepulse'),
                'historyNoteSaved' => esc_html__('Note enregistrée.', 'sitepulse'),
                'historyNoteError' => esc_html__('Échec de l’enregistrement de la note.', 'sitepulse'),
                'historyAriaDefault' => esc_html__('Mise à jour de l’historique.', 'sitepulse'),
                'queuePosition'   => esc_html__('Position %1$d sur %2$d', 'sitepulse'),
                'queueNextAttempt'=> esc_html__('Prochain essai : %s', 'sitepulse'),
            ],
            'initialCached'     => '' !== $insight_text,
            'initialForceRefresh' => false,
            'initialFallback'   => '',
            'initialCreatedAt'  => null,
            'initialStartedAt'  => null,
            'initialFinishedAt' => null,
            'statusAction'      => 'sitepulse_get_ai_insight_status',
            'pollInterval'      => 5000,
        ]
    );

    wp_enqueue_script('sitepulse-ai-insights');
}


require_once __DIR__ . '/ai-insights/page.php';

function sitepulse_ai_save_history_note() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_send_json_error([
            'message' => esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse'),
        ], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_AI_INSIGHT)) {
        wp_send_json_error([
            'message' => esc_html__('La sécurité de la requête n’a pas pu être vérifiée.', 'sitepulse'),
        ], 400);
    }

    $entry_id = isset($_POST['entry_id']) ? sanitize_key((string) wp_unslash($_POST['entry_id'])) : '';

    if ('' === $entry_id) {
        wp_send_json_error([
            'message' => esc_html__('Identifiant de recommandation manquant.', 'sitepulse'),
        ], 400);
    }

    $raw_note = isset($_POST['note']) ? wp_unslash($_POST['note']) : '';
    $note     = sanitize_textarea_field((string) $raw_note);

    $notes = sitepulse_ai_get_history_notes();

    if ('' === $note) {
        if (isset($notes[$entry_id])) {
            unset($notes[$entry_id]);
            sitepulse_ai_update_history_notes($notes);
        }
    } else {
        $notes[$entry_id] = $note;
        sitepulse_ai_update_history_notes($notes);
    }

    wp_send_json_success([
        'entryId' => $entry_id,
        'note'    => $note,
    ]);
}

function sitepulse_generate_ai_insight() {
    if (!current_user_can(sitepulse_get_capability())) {
        $error_message = esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_AI_INSIGHT)) {
        $error_message = esc_html__('Échec de la vérification de sécurité. Veuillez recharger la page et réessayer.', 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 400);
    }

    $force_refresh = false;

    if (isset($_POST['force_refresh'])) {
        $force_refresh = filter_var(wp_unslash($_POST['force_refresh']), FILTER_VALIDATE_BOOLEAN);
    }

    $cached_payload = sitepulse_ai_get_cached_insight();

    if (!$force_refresh && !empty($cached_payload)) {
        $history_entries = sitepulse_ai_get_history_entries();

        if (!empty($history_entries)) {
            $latest_entry = $history_entries[0];

            if (isset($latest_entry['model'])) {
                $cached_payload['model'] = $latest_entry['model'];
            }

            if (isset($latest_entry['rate_limit'])) {
                $cached_payload['rate_limit'] = $latest_entry['rate_limit'];
            }

            if (isset($latest_entry['id'])) {
                $cached_payload['id'] = $latest_entry['id'];
            }

            if (isset($latest_entry['note'])) {
                $cached_payload['note'] = $latest_entry['note'];
            }
        }

        $cached_payload['cached'] = true;
        wp_send_json_success($cached_payload);
    }

    $environment = sitepulse_ai_prepare_environment();

    if (is_wp_error($environment)) {
        $status_code = sitepulse_ai_get_error_status_code($environment, 400);

        wp_send_json_error([
            'message' => $environment->get_error_message(),
        ], $status_code);
    }

    $now_utc              = absint(current_time('timestamp', true));
    $retry_after_timestamp = sitepulse_ai_get_retry_after_timestamp();

    if ($retry_after_timestamp > 0) {
        if ($retry_after_timestamp <= $now_utc) {
            sitepulse_ai_set_retry_after_timestamp(0);
        } else {
            $time_remaining = max(0, $retry_after_timestamp - $now_utc);
            $delay_payload  = [
                'retry_after' => $time_remaining,
                'retry_at'    => $retry_after_timestamp,
            ];
            $human_delay    = function_exists('human_time_diff')
                ? human_time_diff($now_utc, $retry_after_timestamp)
                : sprintf('%ds', max(1, $time_remaining));

            if (!empty($cached_payload)) {
                $cached_payload['cached'] = true;
                $cached_payload = array_merge($cached_payload, $delay_payload);

                wp_send_json_success($cached_payload);
            }

            $error_message = sprintf(
                /* translators: %s: human readable delay. */
                esc_html__('Gemini impose une période d’attente. Réessayez dans %s.', 'sitepulse'),
                $human_delay
            );

            wp_send_json_error(array_merge([
                'message' => $error_message,
            ], $delay_payload), 429);
        }
    }

    $rate_limit_value   = sitepulse_ai_get_current_rate_limit_value();
    $rate_limit_window  = sitepulse_ai_get_rate_limit_window_seconds($rate_limit_value);
    $last_run_timestamp = (int) get_option(SITEPULSE_OPTION_AI_LAST_RUN, 0);

    if ($rate_limit_window > 0 && $last_run_timestamp > 0) {
        $next_allowed = $last_run_timestamp + $rate_limit_window;

        if ($next_allowed > $now_utc) {
            $time_remaining = max(0, $next_allowed - $now_utc);
            $delay_payload  = [
                'retry_after' => $time_remaining,
                'retry_at'    => $next_allowed,
            ];

            if (!empty($cached_payload)) {
                $cached_payload['cached'] = true;
                $cached_payload = array_merge($cached_payload, $delay_payload);
                wp_send_json_success($cached_payload);
            }

            $human_delay = human_time_diff($now_utc, $next_allowed);
            $error_message = sprintf(
                /* translators: 1: Human readable delay (e.g. "5 minutes"), 2: rate limit label. */
                esc_html__('La génération par IA est limitée à %2$s. Réessayez dans %1$s.', 'sitepulse'),
                $human_delay,
                sitepulse_ai_get_rate_limit_label($rate_limit_value)
            );

            wp_send_json_error(array_merge([
                'message' => $error_message,
            ], $delay_payload), 429);
        }
    }

    if ($force_refresh) {
        sitepulse_ai_get_cached_insight(true);
    }

    $job_id = sitepulse_ai_schedule_generation_job($force_refresh);

    if (is_wp_error($job_id)) {
        $status_code = sitepulse_ai_get_error_status_code($job_id, 500);
        $error_payload = [
            'message' => $job_id->get_error_message(),
        ];

        $retry_after = sitepulse_ai_get_error_retry_after($job_id);

        if ($retry_after > 0) {
            $error_payload['retry_after'] = $retry_after;

            $retry_at = sitepulse_ai_get_error_retry_at($job_id);

            if ($retry_at > 0) {
                $error_payload['retry_at'] = $retry_at;
            }
        }

        wp_send_json_error($error_payload, $status_code);
    }

    $job_snapshot = sitepulse_ai_get_job_data($job_id);
    $queue_payload = !empty($job_snapshot) ? sitepulse_ai_format_queue_payload($job_id, $job_snapshot) : [];

    wp_send_json_success([
        'jobId'  => $job_id,
        'status' => 'queued',
        'queue'  => $queue_payload,
    ]);
}

/**
 * AJAX handler returning the current status of an AI insight job.
 *
 * @return void
 */
function sitepulse_get_ai_insight_status() {
    if (!current_user_can(sitepulse_get_capability())) {
        $error_message = esc_html__("Vous n'avez pas les permissions nécessaires pour effectuer cette action.", 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, SITEPULSE_NONCE_ACTION_AI_INSIGHT)) {
        $error_message = esc_html__('Échec de la vérification de sécurité. Veuillez recharger la page et réessayer.', 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 400);
    }

    $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';

    if ('' === $job_id) {
        $error_message = esc_html__('Identifiant de tâche manquant.', 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 400);
    }

    $job_data = sitepulse_ai_get_job_data($job_id);

    if (empty($job_data)) {
        $error_message = esc_html__('Tâche introuvable ou expirée. Veuillez relancer une génération.', 'sitepulse');

        wp_send_json_error([
            'message' => $error_message,
        ], 404);
    }

    $status = isset($job_data['status']) ? (string) $job_data['status'] : 'queued';

    $response = [
        'status' => $status,
    ];

    if (isset($job_data['created_at'])) {
        $response['created_at'] = (int) $job_data['created_at'];
    }

    if (isset($job_data['started_at'])) {
        $response['started_at'] = (int) $job_data['started_at'];
    }

    if (isset($job_data['finished'])) {
        $response['finished_at'] = (int) $job_data['finished'];
    }

    if (isset($job_data['force_refresh'])) {
        $response['force_refresh'] = (bool) $job_data['force_refresh'];
    }

    if (isset($job_data['fallback'])) {
        $response['fallback'] = sanitize_text_field((string) $job_data['fallback']);
    }

    $response['queue'] = sitepulse_ai_format_queue_payload($job_id, $job_data);

    if ('completed' === $status && isset($job_data['result']) && is_array($job_data['result'])) {
        $response['result'] = $job_data['result'];
    } elseif ('failed' === $status) {
        $response['message'] = isset($job_data['message']) ? (string) $job_data['message'] : esc_html__('La génération de l’analyse IA a échoué.', 'sitepulse');
        if (isset($job_data['code'])) {
            $response['code'] = (int) $job_data['code'];
        }
        if (isset($job_data['retry_after'])) {
            $response['retry_after'] = (int) $job_data['retry_after'];
        }

        if (isset($job_data['retry_at'])) {
            $response['retry_at'] = (int) $job_data['retry_at'];
        }
    }

    if (in_array($status, ['completed', 'failed'], true)) {
        sitepulse_ai_delete_job_data($job_id);
    }

    wp_send_json_success($response);
}
