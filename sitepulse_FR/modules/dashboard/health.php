<?php
/**
 * SitePulse dashboard health score, playbooks and SLA shortcuts.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the log-analyzer entry used by the transverse impact index.
 *
 * @param array<string,mixed>|null $logs           Debug-log metrics payload.
 * @param array<string,bool>       $modules_status Module activation map.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_build_log_impact_entry($logs, $modules_status) {
    $entry = [
        'label'   => __('Errors', 'sitepulse'),
        'status'  => 'status-warn',
        'score'   => null,
        'active'  => !empty($modules_status['log_analyzer']),
        'details' => [],
        'signal'  => '',
    ];

    if (!$entry['active']) {
        $entry['signal'] = __('Module inactive', 'sitepulse');

        return $entry;
    }

    $card   = (is_array($logs) && isset($logs['card']) && is_array($logs['card'])) ? $logs['card'] : [];
    $counts = isset($card['counts']) && is_array($card['counts']) ? $card['counts'] : [];

    $fatal      = isset($counts['fatal']) ? (int) $counts['fatal'] : 0;
    $warning    = isset($counts['warning']) ? (int) $counts['warning'] : 0;
    $notice     = isset($counts['notice']) ? (int) $counts['notice'] : 0;
    $deprecated = isset($counts['deprecated']) ? (int) $counts['deprecated'] : 0;

    if (!is_array($logs) || empty($counts)) {
        $entry['signal'] = __('Awaiting log data', 'sitepulse');

        return $entry;
    }

    $fatal_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($fatal, 1, 3, 'higher-is-worse');
    $warning_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($warning, 3, 10, 'higher-is-worse');
    $noise_ratio = sitepulse_custom_dashboard_calculate_severity_ratio($notice + $deprecated, 20, 80, 'higher-is-worse');

    $score = (($fatal_ratio * 0.7) + ($warning_ratio * 0.2) + ($noise_ratio * 0.1)) * 100.0;
    $entry['score']  = round($score, 2);
    $entry['status'] = sitepulse_custom_dashboard_resolve_score_status($entry['score']);

    if ($fatal > 0) {
        $entry['signal'] = sprintf(
            _n('%s fatal error', '%s fatal errors', $fatal, 'sitepulse'),
            number_format_i18n($fatal)
        );
    } elseif ($warning > 0) {
        $entry['signal'] = sprintf(
            _n('%s warning', '%s warnings', $warning, 'sitepulse'),
            number_format_i18n($warning)
        );
    } else {
        $entry['signal'] = __('Log clean', 'sitepulse');
    }

    $entry['details'][] = [
        'label' => __('Fatal errors', 'sitepulse'),
        'value' => number_format_i18n($fatal),
    ];
    $entry['details'][] = [
        'label' => __('Warnings', 'sitepulse'),
        'value' => number_format_i18n($warning),
    ];
    $entry['details'][] = [
        'label' => __('Notices', 'sitepulse'),
        'value' => number_format_i18n($notice + $deprecated),
    ];

    return $entry;
}

/**
 * Derives a 0–100 health score (higher is better) from an impact snapshot.
 *
 * @param array<string,mixed>|null $impact Impact payload.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_derive_health_score($impact) {
    $health = [
        'score'           => null,
        'status'          => 'status-warn',
        'dominant_module' => '',
        'modules'         => [],
    ];

    if (!is_array($impact) || empty($impact['modules']) || !is_array($impact['modules'])) {
        return $health;
    }

    if (isset($impact['health']) && is_numeric($impact['health'])) {
        $health['score'] = max(0.0, min(100.0, (float) $impact['health']));
    } elseif (isset($impact['overall']) && is_numeric($impact['overall'])) {
        $health['score'] = max(0.0, min(100.0, 100.0 - (float) $impact['overall']));
    }

    $health['dominant_module'] = isset($impact['dominant_module'])
        ? sanitize_key((string) $impact['dominant_module'])
        : '';

    $urls = [
        'uptime_tracker' => admin_url('admin.php?page=sitepulse-uptime'),
        'speed_analyzer' => admin_url('admin.php?page=sitepulse-speed'),
        'log_analyzer'   => admin_url('admin.php?page=sitepulse-logs'),
        'ai_insights'    => admin_url('admin.php?page=sitepulse-ai'),
    ];

    foreach ($impact['modules'] as $module_key => $module_data) {
        if (!is_array($module_data)) {
            continue;
        }

        $module_id = sanitize_key((string) $module_key);
        $module_health = null;

        if (isset($module_data['score']) && is_numeric($module_data['score'])) {
            $module_health = max(0.0, min(100.0, 100.0 - (float) $module_data['score']));
        }

        $health['modules'][$module_id] = [
            'label'  => isset($module_data['label']) ? (string) $module_data['label'] : $module_id,
            'active' => !empty($module_data['active']),
            'score'  => $module_health,
            'status' => $module_health === null
                ? 'status-warn'
                : sitepulse_custom_dashboard_resolve_health_status($module_health),
            'signal' => isset($module_data['signal']) ? (string) $module_data['signal'] : '',
            'url'    => isset($urls[$module_id]) ? $urls[$module_id] : '',
        ];
    }

    if ($health['score'] !== null) {
        $health['status'] = sitepulse_custom_dashboard_resolve_health_status($health['score']);
    }

    return $health;
}

/**
 * Maps a 0–100 health score to a status class (higher is better).
 *
 * @param float $score Health score.
 * @return string
 */
function sitepulse_custom_dashboard_resolve_health_status($score) {
    if (!is_numeric($score)) {
        return 'status-warn';
    }

    $normalized = (float) $score;

    if ($normalized >= 65.0) {
        return 'status-ok';
    }

    if ($normalized >= 30.0) {
        return 'status-warn';
    }

    return 'status-bad';
}

/**
 * Formats the health hero payload for the dashboard overview.
 *
 * @param array<string,mixed>|null $impact      Impact snapshot.
 * @param string                   $range_label Human-readable range.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_health_view($impact, $range_label) {
    $derived = sitepulse_custom_dashboard_derive_health_score($impact);
    $status  = sitepulse_custom_dashboard_resolve_status_meta($derived['status']);

    $view = [
        'score'           => $derived['score'],
        'status'          => array_merge($status, ['class' => $derived['status']]),
        'label'           => __('Score SitePulse', 'sitepulse'),
        'unit'            => '/100',
        'summary'         => sprintf(__('Aucun score consolidé pour %s.', 'sitepulse'), $range_label),
        'dominant_module' => $derived['dominant_module'],
        'modules'         => array_values(array_filter(
            $derived['modules'],
            static function ($module) {
                return is_array($module) && !empty($module['active']);
            }
        )),
    ];

    if ($derived['score'] === null) {
        return $view;
    }

    $view['summary'] = sprintf(
        __('Synthèse disponibilité, performance et erreurs sur %s.', 'sitepulse'),
        $range_label
    );

    if ($derived['status'] === 'status-ok') {
        $view['summary'] = sprintf(__('Pouls stable sur %s.', 'sitepulse'), $range_label);
    } elseif ($derived['dominant_module'] !== '' && isset($derived['modules'][$derived['dominant_module']])) {
        $dominant = $derived['modules'][$derived['dominant_module']];
        $view['summary'] = sprintf(
            __('Point faible : %s.', 'sitepulse'),
            isset($dominant['label']) ? $dominant['label'] : $derived['dominant_module']
        );
    }

    return $view;
}

/**
 * Builds short, actionable playbooks from the current metric cards.
 *
 * @param array<string,array<string,mixed>> $cards   Formatted cards.
 * @param array<string,mixed>               $payload Raw metrics payload.
 * @return array<int,array<string,mixed>>
 */
function sitepulse_custom_dashboard_build_playbooks($cards, $payload) {
    unset($payload);

    $playbooks = [];
    $cards     = is_array($cards) ? $cards : [];

    $status_of = static function ($key) use ($cards) {
        if (!isset($cards[$key]) || !is_array($cards[$key]) || !empty($cards[$key]['inactive'])) {
            return '';
        }

        return isset($cards[$key]['status']['class']) ? (string) $cards[$key]['status']['class'] : '';
    };

    if (in_array($status_of('uptime'), ['status-bad', 'status-warn'], true)) {
        $playbooks[] = [
            'id'    => 'uptime',
            'tone'  => $status_of('uptime') === 'status-bad' ? 'danger' : 'warning',
            'title' => __('Disponibilité en baisse', 'sitepulse'),
            'steps' => [
                [
                    'label' => __('Ouvrir les incidents uptime', 'sitepulse'),
                    'url'   => admin_url('admin.php?page=sitepulse-uptime'),
                ],
                [
                    'label' => __('Vérifier une fenêtre de maintenance', 'sitepulse'),
                    'url'   => admin_url('admin.php?page=sitepulse-uptime#sitepulse-sla-reports'),
                ],
                [
                    'label' => __('Générer un rapport SLA', 'sitepulse'),
                    'url'   => admin_url('admin.php?page=sitepulse-uptime#sitepulse-sla-reports'),
                ],
            ],
        ];
    }

    if (in_array($status_of('speed'), ['status-bad', 'status-warn'], true)) {
        $playbooks[] = [
            'id'    => 'speed',
            'tone'  => $status_of('speed') === 'status-bad' ? 'danger' : 'warning',
            'title' => __('Temps serveur trop élevé', 'sitepulse'),
            'steps' => [
                [
                    'label' => __('Profiler hooks et SQL', 'sitepulse'),
                    'url'   => admin_url('admin.php?page=sitepulse-speed#sitepulse-speed-profiler'),
                ],
                [
                    'label' => __('Mesurer l’impact des extensions', 'sitepulse'),
                    'url'   => admin_url('admin.php?page=sitepulse-plugins'),
                ],
                [
                    'label' => __('Relancer un test de vitesse', 'sitepulse'),
                    'url'   => admin_url('admin.php?page=sitepulse-speed'),
                ],
            ],
        ];
    }

    if (in_array($status_of('logs'), ['status-bad', 'status-warn'], true)) {
        $playbooks[] = [
            'id'    => 'logs',
            'tone'  => $status_of('logs') === 'status-bad' ? 'danger' : 'warning',
            'title' => __('Erreurs dans debug.log', 'sitepulse'),
            'steps' => [
                [
                    'label' => __('Ouvrir l’analyseur de journaux', 'sitepulse'),
                    'url'   => admin_url('admin.php?page=sitepulse-logs'),
                ],
                [
                    'label' => __('Identifier les fatals PHP', 'sitepulse'),
                    'url'   => admin_url('admin.php?page=sitepulse-logs'),
                ],
            ],
        ];
    }

    if (in_array($status_of('experience'), ['status-bad', 'status-warn'], true)) {
        $playbooks[] = [
            'id'    => 'rum',
            'tone'  => $status_of('experience') === 'status-bad' ? 'danger' : 'warning',
            'title' => __('Web Vitals dégradés', 'sitepulse'),
            'steps' => [
                [
                    'label' => __('Inspecter LCP / CLS', 'sitepulse'),
                    'url'   => admin_url('admin.php?page=sitepulse-speed'),
                ],
                [
                    'label' => __('Vérifier le RUM', 'sitepulse'),
                    'url'   => admin_url('admin.php?page=sitepulse-speed'),
                ],
            ],
        ];
    }

    return $playbooks;
}

/**
 * Builds the SLA shortcut shown on the dashboard.
 *
 * @param array<string,mixed> $payload Metrics payload.
 * @return array<string,mixed>
 */
function sitepulse_custom_dashboard_format_sla_action($payload) {
    unset($payload);

    $action = [
        'label'       => __('Rapport SLA', 'sitepulse'),
        'description' => __('Exporter la disponibilité 7 / 30 jours.', 'sitepulse'),
        'page_url'    => admin_url('admin.php?page=sitepulse-uptime#sitepulse-sla-reports'),
        'csv_url'     => '',
        'pdf_url'     => '',
        'generated'   => '',
        'can_generate'=> current_user_can(function_exists('sitepulse_get_capability') ? sitepulse_get_capability() : 'manage_options')
            && function_exists('sitepulse_uptime_generate_sla_report'),
        'generate_url'=> admin_url('admin-post.php'),
        'nonce'       => wp_create_nonce('sitepulse_generate_uptime_report'),
    ];

    if (!function_exists('sitepulse_uptime_get_sla_reports')) {
        return $action;
    }

    $reports = sitepulse_uptime_get_sla_reports(1);

    if (!is_array($reports) || empty($reports[0]) || !is_array($reports[0])) {
        return $action;
    }

    $latest = $reports[0];
    $action['csv_url'] = isset($latest['files']['csv']['url']) ? (string) $latest['files']['csv']['url'] : '';
    $action['pdf_url'] = isset($latest['files']['pdf']['url']) ? (string) $latest['files']['pdf']['url'] : '';

    if (isset($latest['generated_at']) && (int) $latest['generated_at'] > 0) {
        $action['generated'] = function_exists('wp_date')
            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $latest['generated_at'])
            : date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $latest['generated_at']);
    }

    return $action;
}

/**
 * Renders the health hero.
 *
 * @param array<string,mixed> $health Health view.
 * @return string
 */
function sitepulse_render_dashboard_health_hero($health) {
    if (!is_array($health)) {
        return '';
    }

    $status_class = isset($health['status']['class']) ? sanitize_html_class((string) $health['status']['class']) : 'status-warn';
    $score        = isset($health['score']) && is_numeric($health['score']) ? (float) $health['score'] : null;
    $label        = isset($health['label']) ? (string) $health['label'] : __('Score SitePulse', 'sitepulse');
    $summary      = isset($health['summary']) ? (string) $health['summary'] : '';
    $unit         = isset($health['unit']) ? (string) $health['unit'] : '/100';
    $modules      = isset($health['modules']) && is_array($health['modules']) ? $health['modules'] : [];
    $score_text   = $score === null ? __('N/A', 'sitepulse') : (string) number_format_i18n($score, 0);

    ob_start();
    ?>
    <section class="sitepulse-health-hero sitepulse-health-hero--<?php echo esc_attr($status_class); ?>" data-sitepulse-health role="group" aria-label="<?php echo esc_attr($label); ?>">
        <div class="sitepulse-health-hero__score">
            <span class="sitepulse-health-hero__label" data-sitepulse-health-label><?php echo esc_html($label); ?></span>
            <p class="sitepulse-health-hero__value">
                <span data-sitepulse-health-value><?php echo esc_html($score_text); ?></span>
                <span class="sitepulse-health-hero__unit" data-sitepulse-health-unit<?php echo $score === null ? ' hidden' : ''; ?>><?php echo esc_html($unit); ?></span>
            </p>
            <p class="sitepulse-health-hero__summary" data-sitepulse-health-summary><?php echo esc_html($summary); ?></p>
        </div>
        <ul class="sitepulse-health-hero__modules" data-sitepulse-health-modules<?php echo empty($modules) ? ' hidden' : ''; ?>>
            <?php foreach ($modules as $module) :
                if (!is_array($module)) {
                    continue;
                }

                $module_status = isset($module['status']) ? sanitize_html_class((string) $module['status']) : 'status-warn';
                $module_label  = isset($module['label']) ? (string) $module['label'] : '';
                $module_score  = isset($module['score']) && is_numeric($module['score'])
                    ? number_format_i18n((float) $module['score'], 0)
                    : __('N/A', 'sitepulse');
                $module_signal = isset($module['signal']) ? (string) $module['signal'] : '';
                $module_url    = isset($module['url']) ? (string) $module['url'] : '';
            ?>
                <li class="sitepulse-health-hero__module sitepulse-health-hero__module--<?php echo esc_attr($module_status); ?>">
                    <?php if ($module_url !== '') : ?>
                        <a href="<?php echo esc_url($module_url); ?>">
                            <span class="sitepulse-health-hero__module-label"><?php echo esc_html($module_label); ?></span>
                            <span class="sitepulse-health-hero__module-score"><?php echo esc_html($module_score); ?></span>
                        </a>
                    <?php else : ?>
                        <span class="sitepulse-health-hero__module-label"><?php echo esc_html($module_label); ?></span>
                        <span class="sitepulse-health-hero__module-score"><?php echo esc_html($module_score); ?></span>
                    <?php endif; ?>
                    <?php if ($module_signal !== '') : ?>
                        <span class="sitepulse-health-hero__module-signal"><?php echo esc_html($module_signal); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php

    return (string) ob_get_clean();
}

/**
 * Renders playbook cards.
 *
 * @param array<int,array<string,mixed>> $playbooks Playbooks.
 * @return string
 */
function sitepulse_render_dashboard_playbooks($playbooks) {
    $playbooks = is_array($playbooks) ? array_values(array_filter($playbooks, 'is_array')) : [];

    ob_start();
    ?>
    <div class="sitepulse-playbooks" data-sitepulse-playbooks<?php echo empty($playbooks) ? ' hidden' : ''; ?>>
        <?php foreach ($playbooks as $playbook) :
            $tone  = isset($playbook['tone']) ? sanitize_html_class((string) $playbook['tone']) : 'warning';
            $title = isset($playbook['title']) ? (string) $playbook['title'] : '';
            $steps = isset($playbook['steps']) && is_array($playbook['steps']) ? $playbook['steps'] : [];
        ?>
            <article class="sitepulse-playbook sitepulse-playbook--<?php echo esc_attr($tone); ?>">
                <h2><?php echo esc_html($title); ?></h2>
                <?php if (!empty($steps)) : ?>
                    <ol>
                        <?php foreach ($steps as $step) :
                            if (!is_array($step)) {
                                continue;
                            }
                            $step_label = isset($step['label']) ? (string) $step['label'] : '';
                            $step_url   = isset($step['url']) ? (string) $step['url'] : '';
                            if ($step_label === '') {
                                continue;
                            }
                        ?>
                            <li>
                                <?php if ($step_url !== '') : ?>
                                    <a href="<?php echo esc_url($step_url); ?>"><?php echo esc_html($step_label); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($step_label); ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * Renders the dashboard SLA shortcut.
 *
 * @param array<string,mixed> $sla SLA action payload.
 * @return string
 */
function sitepulse_render_dashboard_sla_action($sla) {
    if (!is_array($sla)) {
        return '';
    }

    $page_url  = isset($sla['page_url']) ? (string) $sla['page_url'] : '';
    $csv_url   = isset($sla['csv_url']) ? (string) $sla['csv_url'] : '';
    $pdf_url   = isset($sla['pdf_url']) ? (string) $sla['pdf_url'] : '';
    $generated = isset($sla['generated']) ? (string) $sla['generated'] : '';
    $label     = isset($sla['label']) ? (string) $sla['label'] : __('Rapport SLA', 'sitepulse');
    $description = isset($sla['description']) ? (string) $sla['description'] : '';

    ob_start();
    ?>
    <aside class="sitepulse-sla-shortcut" data-sitepulse-sla>
        <div class="sitepulse-sla-shortcut__copy">
            <h2><?php echo esc_html($label); ?></h2>
            <?php if ($description !== '') : ?>
                <p><?php echo esc_html($description); ?></p>
            <?php endif; ?>
            <?php if ($generated !== '') : ?>
                <p class="description" data-sitepulse-sla-generated><?php echo esc_html(sprintf(__('Dernier rapport : %s', 'sitepulse'), $generated)); ?></p>
            <?php endif; ?>
        </div>
        <div class="sitepulse-sla-shortcut__actions">
            <?php if ($csv_url !== '') : ?>
                <a class="button button-secondary" href="<?php echo esc_url($csv_url); ?>" data-sitepulse-sla-csv><?php esc_html_e('Télécharger le CSV', 'sitepulse'); ?></a>
            <?php endif; ?>
            <?php if ($pdf_url !== '') : ?>
                <a class="button button-secondary" href="<?php echo esc_url($pdf_url); ?>" data-sitepulse-sla-pdf><?php esc_html_e('Télécharger le PDF', 'sitepulse'); ?></a>
            <?php endif; ?>
            <?php if (!empty($sla['can_generate'])) : ?>
                <form method="post" action="<?php echo esc_url(isset($sla['generate_url']) ? $sla['generate_url'] : admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="sitepulse_generate_uptime_report" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(isset($sla['nonce']) ? $sla['nonce'] : ''); ?>" />
                    <input type="hidden" name="sitepulse_uptime_windows[]" value="7" />
                    <input type="hidden" name="sitepulse_uptime_windows[]" value="30" />
                    <?php submit_button(__('Générer 7 / 30 jours', 'sitepulse'), 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>
            <?php if ($page_url !== '') : ?>
                <a class="button-link" href="<?php echo esc_url($page_url); ?>"><?php esc_html_e('Ouvrir les rapports SLA', 'sitepulse'); ?></a>
            <?php endif; ?>
        </div>
    </aside>
    <?php

    return (string) ob_get_clean();
}
