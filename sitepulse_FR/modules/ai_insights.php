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

require_once __DIR__ . '/ai-insights/sanitize.php';
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

require_once __DIR__ . '/ai-insights/metrics.php';
require_once __DIR__ . '/ai-insights/history.php';
require_once __DIR__ . '/ai-insights/alerts.php';
require_once __DIR__ . '/ai-insights/retry.php';
require_once __DIR__ . '/ai-insights/generate.php';

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
require_once __DIR__ . '/ai-insights/admin.php';
