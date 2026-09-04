<?php
/**
 * SitePulse Speed Analyzer admin page.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the Speed Analyzer page.
 * The analysis is now based on internal WordPress timers for better reliability.
 */
function sitepulse_speed_analyzer_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $rum_notices = [];

    if (isset($_GET['sitepulse-rum-updated'])) {
        $rum_status = sanitize_text_field(wp_unslash($_GET['sitepulse-rum-updated']));

        if ($rum_status === '1') {
            $rum_notices[] = [
                'class'   => 'notice-success',
                'message' => esc_html__('La collecte RUM est désormais active.', 'sitepulse'),
            ];
        } elseif ($rum_status === '0') {
            $rum_notices[] = [
                'class'   => 'notice-info',
                'message' => esc_html__('La collecte RUM a été désactivée.', 'sitepulse'),
            ];
        }
    }

    if (isset($_GET['sitepulse-rum-token'])) {
        $rum_notices[] = [
            'class'   => 'notice-success',
            'message' => esc_html__('Un nouveau jeton RUM a été généré.', 'sitepulse'),
        ];
    }

    $rum_settings = function_exists('sitepulse_rum_get_settings')
        ? sitepulse_rum_get_settings()
        : [
            'enabled'          => false,
            'token'            => '',
            'consent_required' => false,
            'sample_rate'      => 1.0,
            'range_days'       => 7,
        ];

    $rum_range_days = isset($rum_settings['range_days']) ? (int) $rum_settings['range_days'] : 7;
    $rum_sample_percent = isset($rum_settings['sample_rate'])
        ? round((float) $rum_settings['sample_rate'] * 100, 1)
        : 100.0;
    $rum_token = function_exists('sitepulse_rum_get_ingest_token')
        ? sitepulse_rum_get_ingest_token()
        : (isset($rum_settings['token']) ? (string) $rum_settings['token'] : '');

    $rum_summary = (!empty($rum_settings['enabled']) && function_exists('sitepulse_rum_get_admin_summary'))
        ? sitepulse_rum_get_admin_summary()
        : [];

    $rum_retention_days = function_exists('sitepulse_rum_get_retention_days')
        ? sitepulse_rum_get_retention_days()
        : 0;

    $rum_metric_labels = [
        'LCP' => esc_html__('Largest Contentful Paint', 'sitepulse'),
        'FID' => esc_html__('First Input Delay', 'sitepulse'),
        'CLS' => esc_html__('Cumulative Layout Shift', 'sitepulse'),
    ];

    $rum_nonce_action = defined('SITEPULSE_NONCE_ACTION_RUM_SETTINGS')
        ? SITEPULSE_NONCE_ACTION_RUM_SETTINGS
        : 'sitepulse_rum_settings';

    global $wpdb;

    // --- Server Performance Metrics ---

    // 1. Page Generation Time (Backend processing)
    // **FIX:** Replaced timer_stop() with a direct microtime calculation to prevent non-numeric value warnings in specific environments.
    if (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
        $timestart = (float) $_SERVER['REQUEST_TIME_FLOAT'];
    } elseif (isset($GLOBALS['timestart']) && is_numeric($GLOBALS['timestart'])) {
        $timestart = (float) $GLOBALS['timestart'];
    } else {
        $timestart = microtime(true);
    }
    $page_generation_time = (microtime(true) - $timestart) * 1000.0; // in milliseconds

    $thresholds = sitepulse_speed_analyzer_get_thresholds();
    $speed_warning_threshold = $thresholds['warning'];
    $speed_critical_threshold = $thresholds['critical'];
    $rate_limit = sitepulse_speed_analyzer_get_rate_limit();
    $history = sitepulse_speed_analyzer_get_history_data();
    $latest_entry = sitepulse_speed_analyzer_get_latest_entry($history);
    $aggregates = sitepulse_speed_analyzer_get_aggregates($history, $thresholds);
    $summary_note = sitepulse_speed_analyzer_build_summary_note($aggregates);
    $summary_meta = sitepulse_speed_analyzer_get_summary_meta();
    $status_labels = sitepulse_speed_analyzer_get_status_labels();
    $now_timestamp = current_time('timestamp');
    $rate_limit_label = human_time_diff($now_timestamp, $now_timestamp + max(1, $rate_limit));
    $automation_settings = sitepulse_speed_analyzer_get_automation_settings();
    $automation_payload = sitepulse_speed_analyzer_build_automation_payload($thresholds);
    $profiles_catalog = sitepulse_speed_analyzer_get_profile_catalog();
    if (!is_array($profiles_catalog)) {
        $profiles_catalog = [];
    }
    $manual_profile = isset($thresholds['profile']) ? sitepulse_speed_analyzer_normalize_profile($thresholds['profile']) : 'default';
    $manual_profile_label = isset($profiles_catalog[$manual_profile]['label']) ? (string) $profiles_catalog[$manual_profile]['label'] : ucfirst($manual_profile);
    $manual_profile_description = isset($profiles_catalog[$manual_profile]['description']) ? (string) $profiles_catalog[$manual_profile]['description'] : '';
    $frequency_choices = sitepulse_speed_analyzer_get_frequency_choices();
    $selected_frequency = isset($automation_settings['frequency']) ? $automation_settings['frequency'] : 'disabled';
    $default_presets = sitepulse_speed_analyzer_get_default_presets();
    $form_presets = $default_presets;

    $automation_presets = isset($automation_settings['presets']) && is_array($automation_settings['presets'])
        ? $automation_settings['presets']
        : [];

    foreach ($automation_presets as $preset_slug => $preset_config) {
        if (isset($form_presets[$preset_slug])) {
            $form_presets[$preset_slug] = array_merge($form_presets[$preset_slug], $preset_config);
        } else {
            $form_presets[$preset_slug] = $preset_config;
        }
    }

    $automation_queue = isset($automation_payload['queue']) && is_array($automation_payload['queue'])
        ? $automation_payload['queue']
        : [];

    $profiler_history = function_exists('sitepulse_request_profiler_get_history')
        ? sitepulse_request_profiler_get_history()
        : [];
    $profiler_last_trace = null;
    $profiler_trigger_url = '';

    if (function_exists('sitepulse_request_profiler_can_profile') && sitepulse_request_profiler_can_profile()) {
        if (function_exists('sitepulse_request_profiler_get_last_trace_for_user')) {
            $profiler_last_trace = sitepulse_request_profiler_get_last_trace_for_user(get_current_user_id());
        }

        if (function_exists('sitepulse_request_profiler_get_trigger_url')) {
            $profiler_trigger_url = sitepulse_request_profiler_get_trigger_url();
        }
    }

    // 2. Database Query Time & Count
    $db_query_total_time = 0;
    $savequeries_enabled = defined('SAVEQUERIES') && SAVEQUERIES;

    if ($savequeries_enabled && isset($wpdb->queries) && is_array($wpdb->queries)) {
        foreach ($wpdb->queries as $query) {
            // Ensure the query duration is numeric before adding it
            if (isset($query[1]) && is_numeric($query[1])) {
                $db_query_total_time += $query[1];
            }
        }
        $db_query_total_time *= 1000; // convert seconds to milliseconds
    }
    $db_query_count = $wpdb->num_queries;


    // --- Server Configuration Checks ---
    $object_cache_active = wp_using_ext_object_cache();
    $php_version = PHP_VERSION;

    $get_status_meta = static function ($status) use ($status_labels) {
        if (isset($status_labels[$status])) {
            return $status_labels[$status];
        }

        return $status_labels['status-warn'];
    };

    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-speed');
    }
    ?>
    <div class="wrap">
        <?php if (!empty($rum_notices)) : ?>
            <?php foreach ($rum_notices as $notice) : ?>
                <div class="notice <?php echo esc_attr($notice['class']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <h1><span class="dashicons-before dashicons-performance"></span> <?php esc_html_e('Analyseur de Vitesse', 'sitepulse'); ?></h1>
        <p><?php esc_html_e('Cet outil analyse la performance interne de votre serveur et de votre base de données à chaque chargement de page.', 'sitepulse'); ?></p>

        <div class="speed-scan-actions">
            <button type="button" class="button button-primary" id="sitepulse-speed-rescan">
                <?php esc_html_e('Relancer un test', 'sitepulse'); ?>
            </button>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: human readable rate limit duration. */
                    esc_html__('Pour préserver les ressources serveur, un nouveau test manuel est disponible toutes les %s.', 'sitepulse'),
                    esc_html($rate_limit_label)
                );
                ?>
            </p>
            <div id="sitepulse-speed-scan-status" class="sitepulse-speed-status" role="status" aria-live="polite"></div>
        </div>

        <?php if (function_exists('sitepulse_request_profiler_can_profile') && sitepulse_request_profiler_can_profile()) : ?>
            <div class="sitepulse-speed-profiler card">
                <h2><?php esc_html_e('Profilage de requête', 'sitepulse'); ?></h2>
                <p><?php esc_html_e('Capturez le temps serveur, les requêtes SQL et l’empreinte mémoire de cette page pour identifier les goulets d’étranglement.', 'sitepulse'); ?></p>

                <?php if ($profiler_trigger_url !== '') : ?>
                    <p>
                        <a class="button" href="<?php echo esc_url($profiler_trigger_url); ?>">
                            <?php esc_html_e('Recharger et profiler cette requête', 'sitepulse'); ?>
                        </a>
                    </p>
                <?php endif; ?>

                <?php if (!empty($profiler_last_trace)) : ?>
                    <div class="sitepulse-speed-profiler-summary">
                        <h3><?php esc_html_e('Dernier profilage', 'sitepulse'); ?></h3>
                        <p class="description">
                            <?php
                            echo esc_html(
                                wp_date(
                                    get_option('date_format') . ' ' . get_option('time_format'),
                                    (int) $profiler_last_trace['timestamp']
                                )
                            );
                            ?>
                        </p>
                        <ul class="ul-disc">
                            <li>
                                <?php
                                printf(
                                    /* translators: %s: execution time in milliseconds. */
                                    esc_html__('Temps serveur : %s ms', 'sitepulse'),
                                    esc_html(number_format_i18n((float) $profiler_last_trace['duration_ms'], 2))
                                );
                                ?>
                            </li>
                            <li>
                                <?php
                                printf(
                                    /* translators: %s: number of queries. */
                                    esc_html__('Requêtes SQL : %s', 'sitepulse'),
                                    esc_html(number_format_i18n((int) $profiler_last_trace['query_count']))
                                );
                                ?>
                            </li>
                            <li>
                                <?php
                                printf(
                                    /* translators: %s: memory peak in megabytes. */
                                    esc_html__('Pic mémoire : %s Mo', 'sitepulse'),
                                    esc_html(number_format_i18n((float) $profiler_last_trace['memory_peak_mb'], 2))
                                );
                                ?>
                            </li>
                        </ul>

                        <?php if (!empty($profiler_last_trace['slow_queries'])) : ?>
                            <h4><?php esc_html_e('Requêtes les plus lentes', 'sitepulse'); ?></h4>
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th scope="col"><?php esc_html_e('Durée (ms)', 'sitepulse'); ?></th>
                                        <th scope="col"><?php esc_html_e('Requête', 'sitepulse'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($profiler_last_trace['slow_queries'] as $slow_query) : ?>
                                        <tr>
                                            <td><?php echo esc_html(number_format_i18n((float) $slow_query['time_ms'], 2)); ?></td>
                                            <td><code><?php echo esc_html($slow_query['sql']); ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($profiler_history)) : ?>
                    <h3><?php esc_html_e('Historique des profilages', 'sitepulse'); ?></h3>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Horodatage', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Temps serveur (ms)', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Requêtes SQL', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Pic mémoire (Mo)', 'sitepulse'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($profiler_history as $trace) : ?>
                                <tr>
                                    <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $trace['timestamp'])); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((float) $trace['duration_ms'], 2)); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((int) $trace['query_count'])); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((float) $trace['memory_peak_mb'], 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="sitepulse-rum card">
            <h2><?php esc_html_e('Web Vitals réels (RUM)', 'sitepulse'); ?></h2>

            <?php if (empty($rum_settings['enabled'])) : ?>
                <p><?php esc_html_e('Activez la collecte pour suivre les Web Vitals ressentis par vos visiteurs en complément des tests synthétiques.', 'sitepulse'); ?></p>
            <?php else : ?>
                <?php
                $rum_window_days = isset($rum_summary['window']['days']) ? (int) $rum_summary['window']['days'] : 7;
                $rum_total_samples = isset($rum_summary['window']['samples']) ? (int) $rum_summary['window']['samples'] : 0;
                ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: 1: number of days, 2: number of samples. */
                        esc_html__('Fenêtre analysée : %1$s jours – %2$s mesures.', 'sitepulse'),
                        esc_html(number_format_i18n($rum_window_days)),
                        esc_html(number_format_i18n($rum_total_samples))
                    );
                    ?>
                </p>

                <?php if (!empty($rum_summary['metrics'])) : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Métrique', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Moyenne', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('p75', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('p95', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Bon (%)', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Échantillons', 'sitepulse'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rum_summary['metrics'] as $metric_key => $metric_data) :
                                $label = isset($rum_metric_labels[$metric_key]) ? $rum_metric_labels[$metric_key] : $metric_key;
                                $decimals = ($metric_key === 'CLS') ? 3 : 2;
                                $average = isset($metric_data['average']) ? (float) $metric_data['average'] : 0;
                                $p75 = isset($metric_data['p75']) ? (float) $metric_data['p75'] : 0;
                                $p95 = isset($metric_data['p95']) ? (float) $metric_data['p95'] : 0;
                                $count = isset($metric_data['count']) ? (int) $metric_data['count'] : 0;
                                $good_rate = isset($metric_data['ratings']['good']) ? (float) $metric_data['ratings']['good'] : null;
                            ?>
                                <tr>
                                    <td><?php echo esc_html($label); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($average, $decimals)); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($p75, $decimals)); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($p95, $decimals)); ?></td>
                                    <td>
                                        <?php
                                        if ($good_rate !== null) {
                                            echo esc_html(number_format_i18n($good_rate, 2));
                                        } else {
                                            esc_html_e('—', 'sitepulse');
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html(number_format_i18n($count)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e('Aucune donnée RUM collectée pour le moment.', 'sitepulse'); ?></p>
                <?php endif; ?>

                <?php if (!empty($rum_summary['pages'])) : ?>
                    <h3><?php esc_html_e('Pages principales', 'sitepulse'); ?></h3>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Page', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('LCP p75 (ms)', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('FID p75 (ms)', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('CLS p75', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Échantillons', 'sitepulse'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rum_summary['pages'] as $page_summary) :
                                $page_path = isset($page_summary['path']) ? $page_summary['path'] : '/';
                                $page_samples = isset($page_summary['samples']) ? (int) $page_summary['samples'] : 0;
                                $page_metrics = isset($page_summary['metrics']) ? $page_summary['metrics'] : [];

                                $page_lcp = isset($page_metrics['LCP']['p75']) ? (float) $page_metrics['LCP']['p75'] : null;
                                $page_fid = isset($page_metrics['FID']['p75']) ? (float) $page_metrics['FID']['p75'] : null;
                                $page_cls = isset($page_metrics['CLS']['p75']) ? (float) $page_metrics['CLS']['p75'] : null;
                            ?>
                                <tr>
                                    <td><code><?php echo esc_html($page_path); ?></code></td>
                                    <td>
                                        <?php
                                        echo ($page_lcp !== null)
                                            ? esc_html(number_format_i18n($page_lcp, 2))
                                            : esc_html__('—', 'sitepulse');
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        echo ($page_fid !== null)
                                            ? esc_html(number_format_i18n($page_fid, 2))
                                            : esc_html__('—', 'sitepulse');
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        echo ($page_cls !== null)
                                            ? esc_html(number_format_i18n($page_cls, 3))
                                            : esc_html__('—', 'sitepulse');
                                        ?>
                                    </td>
                                    <td><?php echo esc_html(number_format_i18n($page_samples)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sitepulse-rum-settings">
                <?php wp_nonce_field($rum_nonce_action); ?>
                <input type="hidden" name="action" value="sitepulse_rum_settings">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Collecte', 'sitepulse'); ?></th>
                        <td>
                            <label for="sitepulse_rum_enabled">
                                <input type="checkbox" name="sitepulse_rum_enabled" id="sitepulse_rum_enabled" value="1" <?php checked(!empty($rum_settings['enabled'])); ?>>
                                <?php esc_html_e('Activer la collecte RUM', 'sitepulse'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Consentement', 'sitepulse'); ?></th>
                        <td>
                            <label for="sitepulse_rum_consent_required">
                                <input type="checkbox" name="sitepulse_rum_consent_required" id="sitepulse_rum_consent_required" value="1" <?php checked(!empty($rum_settings['consent_required'])); ?>>
                                <?php esc_html_e('Requiert un consentement explicite avant de charger le script', 'sitepulse'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Depuis votre bannière de consentement, appelez SitePulseRUM.grantConsent() après accord utilisateur.', 'sitepulse'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sitepulse_rum_sample_rate"><?php esc_html_e('Échantillonnage', 'sitepulse'); ?></label></th>
                        <td>
                            <input name="sitepulse_rum_sample_rate" type="number" id="sitepulse_rum_sample_rate" class="small-text" min="1" max="100" step="1" value="<?php echo esc_attr($rum_sample_percent); ?>">
                            <p class="description"><?php esc_html_e('Pourcentage de visites instrumentées (100 = toutes les pages).', 'sitepulse'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sitepulse_rum_range_days"><?php esc_html_e('Fenêtre d’analyse', 'sitepulse'); ?></label></th>
                        <td>
                            <input name="sitepulse_rum_range_days" type="number" id="sitepulse_rum_range_days" class="small-text" min="1" max="90" step="1" value="<?php echo esc_attr($rum_range_days); ?>">
                            <span><?php esc_html_e('jours', 'sitepulse'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sitepulse_rum_retention_days"><?php esc_html_e('Rétention', 'sitepulse'); ?></label></th>
                        <td>
                            <input name="sitepulse_rum_retention_days" type="number" id="sitepulse_rum_retention_days" class="small-text" min="7" max="365" step="1" value="<?php echo esc_attr((int) $rum_retention_days); ?>">
                            <span><?php esc_html_e('jours', 'sitepulse'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Jeton d’ingestion', 'sitepulse'); ?></th>
                        <td>
                            <?php if ($rum_token !== '') : ?>
                                <code><?php echo esc_html($rum_token); ?></code>
                            <?php else : ?>
                                <p class="description"><?php esc_html_e('Un jeton unique sera généré automatiquement lors de l’activation.', 'sitepulse'); ?></p>
                            <?php endif; ?>
                            <p>
                                <label for="sitepulse_rum_regenerate">
                                    <input type="checkbox" name="sitepulse_rum_regenerate" id="sitepulse_rum_regenerate" value="1">
                                    <?php esc_html_e('Régénérer le jeton après enregistrement', 'sitepulse'); ?>
                                </label>
                            </p>
                            <p class="description"><strong><?php esc_html_e('Endpoint REST', 'sitepulse'); ?> :</strong> <code><?php echo esc_html(rest_url('sitepulse/v1/rum')); ?></code></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Enregistrer les paramètres RUM', 'sitepulse')); ?>
            </form>

            <?php if ($rum_retention_days > 0) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: number of days. */
                        esc_html__('Les mesures sont conservées pendant %s jours.', 'sitepulse'),
                        esc_html(number_format_i18n($rum_retention_days))
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="speed-history-wrapper">
            <h2><?php esc_html_e('Historique des temps de réponse', 'sitepulse'); ?></h2>
                <div class="speed-history-controls">
                    <label class="screen-reader-text" for="sitepulse-speed-history-source"><?php esc_html_e('Source des mesures', 'sitepulse'); ?></label>
                    <select id="sitepulse-speed-history-source">
                        <option value="manual" selected><?php esc_html_e('Tests manuels', 'sitepulse'); ?></option>
                        <?php if (!empty($automation_payload['presets'])) : ?>
                            <?php foreach ($automation_payload['presets'] as $preset_slug => $preset_data) : ?>
                                <option value="<?php echo esc_attr('automation:' . $preset_slug); ?>">
                                    <?php printf(esc_html__('Planifié – %s', 'sitepulse'), esc_html($preset_data['label'])); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <div class="speed-history-profile" id="sitepulse-speed-history-profile" data-profile="<?php echo esc_attr($manual_profile); ?>" aria-live="polite">
                        <span class="profile-title"><?php esc_html_e('Profil actif :', 'sitepulse'); ?></span>
                        <span class="profile-label"><?php echo esc_html($manual_profile_label); ?></span>
                        <span class="profile-description"<?php echo $manual_profile_description === '' ? ' style="display:none;"' : ''; ?>><?php echo esc_html($manual_profile_description); ?></span>
                    </div>
                    <?php if (!empty($automation_queue)) : ?>
                        <p class="description speed-history-queue-warning"><?php esc_html_e('Certaines mesures automatiques sont en file d’attente.', 'sitepulse'); ?></p>
                    <?php endif; ?>
                </div>
            <div class="speed-history-visual">
                <canvas id="sitepulse-speed-history-chart" aria-describedby="sitepulse-speed-history-summary"></canvas>
            </div>
            <table class="widefat fixed" id="sitepulse-speed-history-table" aria-live="polite">
                <caption id="sitepulse-speed-history-summary" class="screen-reader-text">
                    <?php esc_html_e('Historique des mesures de temps de réponse du serveur.', 'sitepulse'); ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Horodatage', 'sitepulse'); ?></th>
                        <th scope="col"><?php esc_html_e('Source', 'sitepulse'); ?></th>
                        <th scope="col"><?php esc_html_e('Temps serveur (ms)', 'sitepulse'); ?></th>
                        <th scope="col"><?php esc_html_e('Statut', 'sitepulse'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($history)) : ?>
                        <?php foreach ($history as $entry) : ?>
                            <tr>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $entry['timestamp'])); ?></td>
                                <td><?php echo esc_html(!empty($entry['source_label']) ? $entry['source_label'] : __('Votre site', 'sitepulse')); ?></td>
                                <td><?php echo esc_html(number_format_i18n($entry['server_processing_ms'], 2)); ?></td>
                                <td>&mdash;</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('Aucun historique disponible pour le moment.', 'sitepulse'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="sitepulse-speed-recommendations" class="speed-recommendations">
            <h2><?php esc_html_e('Recommandations', 'sitepulse'); ?></h2>
            <ul>
                <?php
                $initial_recommendations = sitepulse_speed_analyzer_build_recommendations($latest_entry, $thresholds);

                foreach ($initial_recommendations as $recommendation) {
                    echo '<li>' . esc_html($recommendation) . '</li>';
                }
                ?>
            </ul>
        </div>

        <div id="sitepulse-speed-summary" class="speed-summary">
            <h2><?php esc_html_e('Résumé', 'sitepulse'); ?></h2>
            <div class="speed-grid summary-grid" id="sitepulse-speed-summary-grid">
                <?php foreach ($summary_meta as $metric_key => $meta) : ?>
                    <?php
                    $metric_data = isset($aggregates['metrics'][$metric_key]) ? $aggregates['metrics'][$metric_key] : null;
                    $metric_status = isset($metric_data['status']) ? $metric_data['status'] : 'status-warn';
                    $status_meta = $get_status_meta($metric_status);
                    $value = isset($metric_data['value']) ? $metric_data['value'] : null;
                    $formatted_value = ($value !== null)
                        ? sprintf(
                            /* translators: %s: duration in milliseconds. */
                            esc_html__('%s ms', 'sitepulse'),
                            esc_html(number_format_i18n((float) $value, 2))
                        )
                        : esc_html__('N/A', 'sitepulse');
                    ?>
                    <div class="speed-card summary-card" data-metric="<?php echo esc_attr($metric_key); ?>">
                        <h3 class="summary-title"><?php echo esc_html($meta['label']); ?></h3>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($metric_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($status_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($status_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text" data-summary-sr><?php echo esc_html($status_meta['sr']); ?></span>
                            <span class="status-reading" data-summary-value><?php echo $formatted_value; ?></span>
                        </span>
                        <p class="description"><?php echo esc_html($meta['description']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="description" id="sitepulse-speed-summary-note" aria-live="polite"><?php echo esc_html($summary_note); ?></p>
        </div>

        <div class="speed-automation" id="sitepulse-speed-automation">
            <h2><?php esc_html_e('Planification automatique', 'sitepulse'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="speed-automation-form">
                <?php wp_nonce_field('sitepulse_speed_schedule'); ?>
                <input type="hidden" name="action" value="sitepulse_save_speed_schedule">
                <div class="speed-automation-field">
                    <label for="sitepulse-speed-frequency"><?php esc_html_e('Fréquence des tests planifiés', 'sitepulse'); ?></label>
                    <select id="sitepulse-speed-frequency" name="sitepulse_speed_frequency">
                        <?php foreach ($frequency_choices as $frequency_slug => $frequency_label) : ?>
                            <option value="<?php echo esc_attr($frequency_slug); ?>" <?php selected($selected_frequency, $frequency_slug); ?>>
                                <?php echo esc_html($frequency_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="speed-automation-presets">
                    <?php foreach ($form_presets as $preset_slug => $preset_config) :
                        $preset_label = isset($preset_config['label']) ? (string) $preset_config['label'] : ucfirst($preset_slug);
                        $preset_url = isset($preset_config['url']) ? (string) $preset_config['url'] : '';
                        $preset_method = isset($preset_config['method']) ? strtoupper((string) $preset_config['method']) : 'GET';
                        $preset_profile = isset($preset_config['profile'])
                            ? sitepulse_speed_analyzer_normalize_profile($preset_config['profile'])
                            : 'default';
                        if (!in_array($preset_method, ['GET', 'POST', 'HEAD'], true)) {
                            $preset_method = 'GET';
                        }
                    ?>
                        <fieldset class="speed-automation-preset">
                            <legend><?php echo esc_html($preset_label); ?></legend>
                            <label for="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-label"><?php esc_html_e('Nom du preset', 'sitepulse'); ?></label>
                            <input type="text" id="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-label" name="sitepulse_speed_presets[<?php echo esc_attr($preset_slug); ?>][label]" value="<?php echo esc_attr($preset_label); ?>">
                            <label for="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-url"><?php esc_html_e('URL à surveiller', 'sitepulse'); ?></label>
                            <input type="url" id="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-url" name="sitepulse_speed_presets[<?php echo esc_attr($preset_slug); ?>][url]" value="<?php echo esc_attr($preset_url); ?>" required>
                            <label for="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-method"><?php esc_html_e('Méthode HTTP', 'sitepulse'); ?></label>
                            <select id="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-method" name="sitepulse_speed_presets[<?php echo esc_attr($preset_slug); ?>][method]">
                                <?php foreach (['GET', 'POST', 'HEAD'] as $method_option) : ?>
                                    <option value="<?php echo esc_attr($method_option); ?>" <?php selected($preset_method, $method_option); ?>><?php echo esc_html($method_option); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-profile"><?php esc_html_e('Profil de mesure', 'sitepulse'); ?></label>
                            <select id="sitepulse-speed-preset-<?php echo esc_attr($preset_slug); ?>-profile" name="sitepulse_speed_presets[<?php echo esc_attr($preset_slug); ?>][profile]">
                                <?php foreach ($profiles_catalog as $profile_slug => $profile_meta) :
                                    $profile_label = isset($profile_meta['label']) ? (string) $profile_meta['label'] : ucfirst($profile_slug);
                                ?>
                                    <option value="<?php echo esc_attr($profile_slug); ?>" <?php selected($preset_profile, $profile_slug); ?>><?php echo esc_html($profile_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($profiles_catalog[$preset_profile]['description']) && $profiles_catalog[$preset_profile]['description'] !== '') : ?>
                                <p class="description"><?php echo esc_html($profiles_catalog[$preset_profile]['description']); ?></p>
                            <?php endif; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
                <p>
                    <button type="submit" class="button button-secondary"><?php esc_html_e('Enregistrer la planification', 'sitepulse'); ?></button>
                </p>
            </form>

            <?php if (!empty($automation_payload['presets'])) : ?>
                <h3><?php esc_html_e('Comparaison des mesures planifiées', 'sitepulse'); ?></h3>
                <table class="widefat fixed sitepulse-automation-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Preset', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Profil', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Moyenne (ms)', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Dernière mesure', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Statut HTTP', 'sitepulse'); ?></th>
                            <th scope="col"><?php esc_html_e('Dernier relevé', 'sitepulse'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($automation_payload['presets'] as $preset_slug => $preset_data) :
                            $aggregated = isset($preset_data['aggregates']) ? $preset_data['aggregates'] : [];
                            $preset_thresholds = isset($preset_data['thresholds']) ? $preset_data['thresholds'] : $thresholds;
                            $profile_label = isset($preset_data['profileLabel']) ? (string) $preset_data['profileLabel'] : '';
                            $mean_metric = isset($aggregated['metrics']['mean']) ? $aggregated['metrics']['mean'] : null;
                            $mean_value = ($mean_metric && isset($mean_metric['value']) && $mean_metric['value'] !== null)
                                ? sprintf(
                                    /* translators: %s: duration in milliseconds. */
                                    esc_html__('%s ms', 'sitepulse'),
                                    esc_html(number_format_i18n((float) $mean_metric['value'], 2))
                                )
                                : esc_html__('N/A', 'sitepulse');
                            $mean_status = $mean_metric && isset($mean_metric['status']) ? $mean_metric['status'] : 'status-warn';
                            $mean_meta = $get_status_meta($mean_status);
                            $history_meta = isset($preset_data['detailedHistory']) && is_array($preset_data['detailedHistory'])
                                ? $preset_data['detailedHistory']
                                : [];
                            $latest_meta = !empty($history_meta) ? end($history_meta) : null;
                            $latest_value = ($latest_meta && isset($latest_meta['server_processing_ms']))
                                ? sprintf(
                                    /* translators: %s: duration in milliseconds. */
                                    esc_html__('%s ms', 'sitepulse'),
                                    esc_html(number_format_i18n((float) $latest_meta['server_processing_ms'], 2))
                                )
                                : esc_html__('N/A', 'sitepulse');
                            $latest_status = ($latest_meta && isset($latest_meta['server_processing_ms']))
                                ? sitepulse_speed_analyzer_resolve_status((float) $latest_meta['server_processing_ms'], $preset_thresholds)
                                : 'status-warn';
                            $latest_meta_info = $get_status_meta($latest_status);
                            $http_status_label = '—';

                            if ($latest_meta) {
                                if (!empty($latest_meta['error'])) {
                                    $http_status_label = (string) $latest_meta['error'];
                                } elseif (isset($latest_meta['http_code']) && (int) $latest_meta['http_code'] > 0) {
                                    $http_status_label = (string) (int) $latest_meta['http_code'];
                                }
                            }

                            $latest_timestamp_label = ($latest_meta && !empty($latest_meta['timestamp']))
                                ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $latest_meta['timestamp'])
                                : esc_html__('Jamais', 'sitepulse');
                        ?>
                            <tr>
                                <td><?php echo esc_html($preset_data['label']); ?></td>
                                <td><?php echo esc_html($profile_label); ?></td>
                                <td>
                                    <span class="status-badge <?php echo esc_attr($mean_status); ?>" aria-hidden="true">
                                        <span class="status-icon"><?php echo esc_html($mean_meta['icon']); ?></span>
                                        <span class="status-text"><?php echo esc_html($mean_meta['label']); ?></span>
                                    </span>
                                    <span class="screen-reader-text"><?php echo esc_html($mean_meta['sr']); ?></span>
                                    <?php echo $mean_value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo esc_attr($latest_status); ?>" aria-hidden="true">
                                        <span class="status-icon"><?php echo esc_html($latest_meta_info['icon']); ?></span>
                                        <span class="status-text"><?php echo esc_html($latest_meta_info['label']); ?></span>
                                    </span>
                                    <span class="screen-reader-text"><?php echo esc_html($latest_meta_info['sr']); ?></span>
                                    <?php echo $latest_value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </td>
                                <td><?php echo esc_html($http_status_label); ?></td>
                                <td><?php echo esc_html($latest_timestamp_label); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Aucune mesure planifiée n’est encore disponible.', 'sitepulse'); ?></p>
            <?php endif; ?>
        </div>

        <div class="speed-grid">
            <!-- Server Processing Card -->
            <div class="speed-card">
                <h3><span class="dashicons dashicons-server"></span> <?php esc_html_e('Performance du Serveur (Backend)', 'sitepulse'); ?></h3>
                <p><?php esc_html_e('Ces métriques mesurent la vitesse à laquelle votre serveur exécute le code PHP et génère la page actuelle.', 'sitepulse'); ?></p>
                <ul class="health-list">
                    <?php
                    if ($page_generation_time >= $speed_critical_threshold) {
                        $gen_time_status = 'status-bad';
                    } elseif ($page_generation_time >= $speed_warning_threshold) {
                        $gen_time_status = 'status-warn';
                    } else {
                        $gen_time_status = 'status-ok';
                    }
                    ?>
                    <li>
                        <span class="metric-name"><?php esc_html_e('Temps de Génération de la Page', 'sitepulse'); ?></span>
                        <?php $gen_time_meta = $get_status_meta($gen_time_status); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($gen_time_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($gen_time_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($gen_time_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($gen_time_meta['sr']); ?></span>
                            <span class="status-reading"><?php
                            /* translators: %d: duration in milliseconds. */
                            printf(esc_html__('%d ms', 'sitepulse'), round($page_generation_time));
                            ?></span>
                        </span>
                        <p class="description"><?php printf(
                            esc_html__("C'est le temps total que met votre serveur pour préparer cette page. Un temps élevé (>%d ms) peut indiquer un hébergement lent ou un plugin qui consomme beaucoup de ressources.", 'sitepulse'),
                            (int) $speed_critical_threshold
                        ); ?></p>
                    </li>
                </ul>
            </div>

            <?php if (function_exists('sitepulse_request_profiler_is_available') && sitepulse_request_profiler_is_available()) : ?>
            <div class="speed-card speed-card--profiler">
                <h3><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Traçage applicatif (hooks & SQL)', 'sitepulse'); ?></h3>
                <p><?php esc_html_e('Lancez un profilage ponctuel pour identifier les hooks et requêtes SQL les plus coûteux sur cette page.', 'sitepulse'); ?></p>
                <button type="button" class="button button-secondary" id="sitepulse-speed-profiler-run"><?php esc_html_e('Lancer un profilage en arrière-plan', 'sitepulse'); ?></button>
                <p class="description"><?php esc_html_e('Une requête secondaire est exécutée en arrière-plan pour collecter les métriques détaillées.', 'sitepulse'); ?></p>
                <div class="sitepulse-speed-profiler__status" id="sitepulse-speed-profiler-status" role="status" aria-live="polite"></div>
                <div class="sitepulse-speed-profiler__results" id="sitepulse-speed-profiler-results" hidden>
                    <h4><?php esc_html_e('Hooks les plus lents', 'sitepulse'); ?></h4>
                    <table class="widefat fixed">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Hook', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Appels', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Total (ms)', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Moyenne (ms)', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Pic (ms)', 'sitepulse'); ?></th>
                            </tr>
                        </thead>
                        <tbody data-role="profiler-hooks"></tbody>
                    </table>
                    <h4><?php esc_html_e('Requêtes SQL les plus coûteuses', 'sitepulse'); ?></h4>
                    <table class="widefat fixed">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Requête', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Appels', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Total (ms)', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Moyenne (ms)', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Origines', 'sitepulse'); ?></th>
                            </tr>
                        </thead>
                        <tbody data-role="profiler-queries"></tbody>
                    </table>
                </div>
                <div class="sitepulse-speed-profiler__history" id="sitepulse-speed-profiler-history">
                    <h4><?php esc_html_e('Profilages récents', 'sitepulse'); ?></h4>
                    <table class="widefat fixed">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Horodatage', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Durée totale (ms)', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Hooks', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('Requêtes', 'sitepulse'); ?></th>
                                <th scope="col"><?php esc_html_e('URL', 'sitepulse'); ?></th>
                            </tr>
                        </thead>
                        <tbody data-role="profiler-history-body"></tbody>
                    </table>
                    <p class="description" data-role="profiler-history-empty"><?php esc_html_e('Aucun profilage récent pour le moment.', 'sitepulse'); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Database Performance Card -->
            <div class="speed-card">
                <h3><span class="dashicons dashicons-database"></span> <?php esc_html_e('Performance de la Base de Données', 'sitepulse'); ?></h3>
                <p><?php esc_html_e('Analyse la communication entre WordPress et votre base de données pour cette page.', 'sitepulse'); ?></p>
                <ul class="health-list">
                    <?php
                    // Database Query Time Analysis
                    if ($savequeries_enabled) {
                        if ($db_query_total_time >= $speed_critical_threshold) {
                            $db_time_status = 'status-bad';
                        } elseif ($db_query_total_time >= $speed_warning_threshold) {
                            $db_time_status = 'status-warn';
                        } else {
                            $db_time_status = 'status-ok';
                        }
                        ?>
                        <li>
                            <span class="metric-name"><?php esc_html_e('Temps Total des Requêtes BDD', 'sitepulse'); ?></span>
                            <?php $db_time_meta = $get_status_meta($db_time_status); ?>
                            <span class="metric-value">
                                <span class="status-badge <?php echo esc_attr($db_time_status); ?>" aria-hidden="true">
                                    <span class="status-icon"><?php echo esc_html($db_time_meta['icon']); ?></span>
                                    <span class="status-text"><?php echo esc_html($db_time_meta['label']); ?></span>
                                </span>
                                <span class="screen-reader-text"><?php echo esc_html($db_time_meta['sr']); ?></span>
                                <span class="status-reading"><?php
                                /* translators: %d: duration in milliseconds. */
                                printf(esc_html__('%d ms', 'sitepulse'), round($db_query_total_time));
                                ?></span>
                            </span>
                            <p class="description"><?php esc_html_e("Le temps total passé à attendre la base de données. S'il est élevé, cela peut indiquer des requêtes complexes ou une base de données surchargée.", 'sitepulse'); ?></p>
                        </li>
                        <?php
                    } else {
                        ?>
                        <li>
                            <span class="metric-name"><?php esc_html_e('Temps Total des Requêtes BDD', 'sitepulse'); ?></span>
                            <?php $db_time_meta = $get_status_meta('status-warn'); ?>
                            <span class="metric-value">
                                <span class="status-badge status-warn" aria-hidden="true">
                                    <span class="status-icon"><?php echo esc_html($db_time_meta['icon']); ?></span>
                                    <span class="status-text"><?php echo esc_html($db_time_meta['label']); ?></span>
                                </span>
                                <span class="screen-reader-text"><?php echo esc_html($db_time_meta['sr']); ?></span>
                                <span class="status-reading"><?php esc_html_e('N/A', 'sitepulse'); ?></span>
                            </span>
                            <p class="description">
                                <?php
                                echo wp_kses(
                                    sprintf(
                                        /* translators: 1: SAVEQUERIES constant, 2: wp-config.php file name. */
                                        __('Pour activer cette mesure, ajoutez <code>%1$s</code> à votre fichier <code>%2$s</code>. <strong>Note :</strong> N\'utilisez ceci que pour le débogage, car cela peut ralentir votre site.', 'sitepulse'),
                                        "define('SAVEQUERIES', true);",
                                        'wp-config.php'
                                    ),
                                    [
                                        'code'   => [],
                                        'strong' => [],
                                    ]
                                );
                                ?>
                            </p>
                        </li>
                        <?php
                    }

                    // Database Query Count Analysis
                    $db_count_status = $db_query_count < 100 ? 'status-ok' : ($db_query_count < 200 ? 'status-warn' : 'status-bad');
                    ?>
                    <li>
                        <span class="metric-name"><?php esc_html_e('Nombre de Requêtes BDD', 'sitepulse'); ?></span>
                        <?php $db_count_meta = $get_status_meta($db_count_status); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($db_count_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($db_count_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($db_count_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($db_count_meta['sr']); ?></span>
                            <span class="status-reading"><?php echo esc_html($db_query_count); ?></span>
                        </span>
                        <p class="description"><?php esc_html_e("Le nombre de fois que WordPress a interrogé la base de données. Un nombre élevé (>100) peut être le signe d'un plugin ou d'un thème mal optimisé.", 'sitepulse'); ?></p>
                    </li>
                </ul>
            </div>
             <!-- Server Configuration Card -->
            <div class="speed-card">
                <h3><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e('Configuration Serveur', 'sitepulse'); ?></h3>
                <p><?php esc_html_e('Des réglages serveur optimaux sont essentiels pour la performance.', 'sitepulse'); ?></p>
                <ul class="health-list">
                    <?php
                    // Object Cache Check
                    $cache_status_class = $object_cache_active ? 'status-ok' : 'status-warn';
                    $cache_text = $object_cache_active ? esc_html__('Actif', 'sitepulse') : esc_html__('Non détecté', 'sitepulse');
                    ?>
                    <li>
                        <span class="metric-name"><?php esc_html_e('Object Cache', 'sitepulse'); ?></span>
                        <?php $cache_meta = $get_status_meta($cache_status_class); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($cache_status_class); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($cache_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($cache_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($cache_meta['sr']); ?></span>
                            <span class="status-reading"><?php echo esc_html($cache_text); ?></span>
                        </span>
                        <p class="description"><?php esc_html_e("Un cache d'objets persistant (ex: Redis, Memcached) accélère énormément les requêtes répétitives. Fortement recommandé.", 'sitepulse'); ?></p>
                    </li>
                    <?php
                    // PHP Version Check
                    $php_status = version_compare($php_version, '8.0', '>=') ? 'status-ok' : 'status-warn';
                    ?>
                    <li>
                        <span class="metric-name"><?php esc_html_e('Version de PHP', 'sitepulse'); ?></span>
                        <?php $php_meta = $get_status_meta($php_status); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($php_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($php_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($php_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($php_meta['sr']); ?></span>
                            <span class="status-reading"><?php echo esc_html($php_version); ?></span>
                        </span>
                        <p class="description"><?php esc_html_e('Les versions modernes de PHP (8.0+) sont beaucoup plus rapides et sécurisées. Demandez à votre hébergeur de mettre à jour si nécessaire.', 'sitepulse'); ?></p>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <?php
}
