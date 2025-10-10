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

/**
 * Registers the REST API routes for the Log Analyzer module.
 *
 * @return void
 */
function sitepulse_log_analyzer_register_rest_routes() {
    if (!function_exists('register_rest_route')) {
        return;
    }

    register_rest_route(
        'sitepulse/v1',
        '/logs/recent',
        [
            'methods'             => defined('WP_REST_Server::READABLE') ? WP_REST_Server::READABLE : 'GET',
            'callback'            => 'sitepulse_log_analyzer_rest_recent_logs',
            'permission_callback' => 'sitepulse_log_analyzer_rest_permission_check',
            'args'                => [
                'lines' => [
                    'type'              => 'integer',
                    'required'          => false,
                    'default'           => 100,
                    'sanitize_callback' => 'absint',
                    'minimum'           => 1,
                    'maximum'           => 500,
                ],
                'bytes' => [
                    'type'              => 'integer',
                    'required'          => false,
                    'default'           => 131072,
                    'sanitize_callback' => 'absint',
                    'minimum'           => 1024,
                    'maximum'           => 5242880,
                ],
                'levels' => [
                    'type'              => 'array',
                    'required'          => false,
                    'default'           => [],
                    'sanitize_callback' => 'sitepulse_log_analyzer_sanitize_levels',
                    'items'             => [
                        'type' => 'string',
                    ],
                ],
            ],
        ]
    );
}

/**
 * Checks whether the current user can access the log analyzer REST routes.
 *
 * @return bool
 */
function sitepulse_log_analyzer_rest_permission_check() {
    $capability = function_exists('sitepulse_get_capability')
        ? sitepulse_get_capability()
        : 'manage_options';

    return current_user_can($capability);
}

/**
 * Returns the recent log lines and metadata for external tools.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function sitepulse_log_analyzer_rest_recent_logs($request) {
    $max_lines = (int) $request->get_param('lines');
    $max_bytes = (int) $request->get_param('bytes');
    $levels    = $request->get_param('levels');

    if ($max_lines <= 0) {
        $max_lines = 100;
    }

    $max_lines = min(500, max(1, $max_lines));

    if ($max_bytes <= 0) {
        $max_bytes = 131072;
    }

    $max_bytes = min(5242880, max(1024, $max_bytes));

    $levels = is_array($levels) ? array_values($levels) : [];

    $module_active = function_exists('sitepulse_is_module_active')
        ? sitepulse_is_module_active('log_analyzer')
        : true;

    if (!$module_active) {
        return new WP_Error(
            'sitepulse_log_module_inactive',
            __('Le module Log Analyzer est désactivé.', 'sitepulse'),
            ['status' => 404]
        );
    }

    $debug_enabled = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;

    if (!$debug_enabled) {
        return new WP_Error(
            'sitepulse_debug_log_disabled',
            __('WP_DEBUG_LOG n’est pas activé pour ce site.', 'sitepulse'),
            ['status' => 409]
        );
    }

    $log_file = function_exists('sitepulse_get_wp_debug_log_path')
        ? sitepulse_get_wp_debug_log_path(true)
        : null;

    if (!is_string($log_file) || $log_file === '') {
        return new WP_Error(
            'sitepulse_log_unavailable',
            __('Impossible de localiser ou de lire le fichier debug.log.', 'sitepulse'),
            ['status' => 404]
        );
    }

    $log_data = sitepulse_get_recent_log_lines($log_file, $max_lines, $max_bytes, true);

    if ($log_data === null) {
        return new WP_Error(
            'sitepulse_log_unreadable',
            __('Impossible de lire les dernières lignes du journal de débogage.', 'sitepulse'),
            ['status' => 500]
        );
    }

    if (!is_array($log_data) || !array_key_exists('lines', $log_data)) {
        $lines    = is_array($log_data) ? $log_data : [];
        $log_data = [
            'lines'         => $lines,
            'bytes_read'    => null,
            'file_size'     => null,
            'truncated'     => null,
            'last_modified' => null,
        ];
    }

    $lines = isset($log_data['lines']) && is_array($log_data['lines'])
        ? array_map('strval', $log_data['lines'])
        : [];

    $categorization = sitepulse_log_analyzer_categorize_lines($lines);
    $groups         = isset($categorization['groups']) ? $categorization['groups'] : [];
    $assignments    = isset($categorization['assignments']) ? $categorization['assignments'] : [];

    $sections         = sitepulse_log_analyzer_get_sections();
    $available_levels = array_keys($sections);

    $totals = [];

    foreach ($groups as $key => $group_lines) {
        $totals[$key] = count($group_lines);
    }

    $levels = array_values(array_intersect($levels, $available_levels));

    if (!empty($levels)) {
        $filtered_groups = array_intersect_key($groups, array_flip($levels));
        $filtered_counts = array_intersect_key($totals, array_flip($levels));

        $filtered_lines = [];

        foreach ($lines as $index => $line) {
            $severity = $assignments[$index] ?? null;

            if ($severity !== null && in_array($severity, $levels, true)) {
                $filtered_lines[] = $line;
            }
        }
    } else {
        $filtered_groups = $groups;
        $filtered_counts = $totals;
        $filtered_lines  = $lines;
    }

    foreach ($filtered_groups as $key => $group_lines) {
        $filtered_groups[$key] = array_values($group_lines);
    }

    $response_data = [
        'generated_at' => time(),
        'status'       => sitepulse_log_analyzer_determine_status($filtered_counts ?: $totals),
        'request'      => [
            'max_lines' => $max_lines,
            'max_bytes' => $max_bytes,
            'levels'    => $levels,
        ],
        'debug'        => [
            'enabled'       => $debug_enabled,
            'module_active' => $module_active,
        ],
        'file'         => [
            'name'          => basename($log_file),
            'path'          => $log_file,
            'size'          => isset($log_data['file_size']) ? (int) $log_data['file_size'] : null,
            'last_modified' => isset($log_data['last_modified']) ? (int) $log_data['last_modified'] : null,
        ],
        'meta' => [
            'bytes_read'  => isset($log_data['bytes_read']) ? (int) $log_data['bytes_read'] : null,
            'truncated'   => !empty($log_data['truncated']),
            'total_lines' => count($lines),
            'line_count'  => count($filtered_lines),
        ],
        'lines'      => array_values($filtered_lines),
        'categories' => [
            'available' => $available_levels,
            'totals'    => $totals,
            'counts'    => $filtered_counts,
            'items'     => $filtered_groups,
        ],
        'sections' => $sections,
    ];

    if (function_exists('apply_filters')) {
        $response_data = apply_filters('sitepulse_log_analyzer_rest_response', $response_data, $request, $log_data, $groups);
    }

    return rest_ensure_response($response_data);
}

/**
 * Renders the Log Analyzer page with improved logic and explanations.
 */
function sitepulse_log_analyzer_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $log_file = function_exists('sitepulse_get_wp_debug_log_path') ? sitepulse_get_wp_debug_log_path() : null;
    $debug_log_enabled = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
    $log_file_exists = $log_file !== null && file_exists($log_file);
    $log_file_readable = $log_file_exists && is_readable($log_file);
    $log_file_size = $log_file_readable ? filesize($log_file) : 0;
    $recent_log_lines = null;
    $recent_log_meta  = null;
    $log_file_display = $log_file !== null ? '<code>' . esc_html($log_file) . '</code>' : '<code>debug.log</code>';
    if (function_exists('wp_kses_post')) {
        $log_file_display = wp_kses_post($log_file_display);
    }

    if ($debug_log_enabled && $log_file_readable) {
        $recent_log_data = sitepulse_get_recent_log_lines($log_file, 100, 131072, true);

        if (is_array($recent_log_data) && array_key_exists('lines', $recent_log_data)) {
            $recent_log_meta  = $recent_log_data;
            $recent_log_lines = $recent_log_data['lines'];
        } else {
            $recent_log_lines = $recent_log_data;
        }
    }
    ?>
    <?php
    if (function_exists('sitepulse_render_module_selector')) {
        sitepulse_render_module_selector('sitepulse-logs');
    }
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-hammer"></span> <?php echo esc_html__('Log Analyzer', 'sitepulse'); ?></h1>
        <p><?php printf(esc_html__('Cet outil scanne le fichier %s de WordPress pour vous aider à trouver et corriger les problèmes sur votre site.', 'sitepulse'), $log_file_display); ?></p>

        <?php
        // **FIX:** Rewrote the conditional logic using standard brace syntax to prevent parse errors.

        // Case 1: Debug log is enabled in wp-config.php
        if ($debug_log_enabled) {

            if ($log_file === null) {
            ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Impossible de déterminer le fichier de journal.', 'sitepulse'); ?></strong> <?php esc_html_e('Vérifiez la valeur de la constante WP_DEBUG_LOG.', 'sitepulse'); ?></p>
                </div>
            <?php
            }
            // Subcase 1.1: The log file exists and has content
            elseif ($log_file_readable && $log_file_size > 0 && is_array($recent_log_lines) && !empty($recent_log_lines)) {
                $categorized_data = sitepulse_log_analyzer_categorize_lines($recent_log_lines);
                $categorized      = isset($categorized_data['groups']) ? $categorized_data['groups'] : [];
                $log_sections     = sitepulse_log_analyzer_get_sections();

                if (is_array($recent_log_meta) && !empty($recent_log_meta['truncated'])) {
                    ?>
                    <div class="notice notice-warning">
                        <p><strong><?php esc_html_e('Journal tronqué pour accélérer l’affichage.', 'sitepulse'); ?></strong> <?php esc_html_e('Seules les dernières entrées sont chargées afin de préserver les performances.', 'sitepulse'); ?></p>
                    </div>
                    <?php
                }

                foreach ($log_sections as $key => $section) {
                    if (!isset($categorized[$key]) || empty($categorized[$key])) {
                        continue;
                    }

                    $count        = esc_html((string) count($categorized[$key]));
                    $recent_lines = esc_html(implode("\n", array_slice($categorized[$key], -10)));

                    printf(
                        '<div class="%1$s"><h2><span class="dashicons %2$s"></span> %3$s (%4$s)</h2><p><strong>%5$s</strong> %6$s</p><pre>%7$s</pre></div>',
                        esc_attr($section['class']),
                        esc_attr($section['icon']),
                        $section['title'],
                        $count,
                        esc_html__("Ce que c'est :", 'sitepulse'),
                        $section['description'],
                        $recent_lines
                    );
                }

            }
            // Subcase 1.2: The log file exists but is empty
            elseif ($log_file_readable && $log_file_size === 0) {
            ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e('Votre journal de débogage est actif et vide.', 'sitepulse'); ?></strong> <?php esc_html_e('Excellent travail, aucune erreur à signaler !', 'sitepulse'); ?></p>
                </div>
            <?php
            }
            elseif ($log_file_readable && $recent_log_lines === null) {
            ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Impossible de lire les dernières lignes du journal.', 'sitepulse'); ?></strong> <?php printf(esc_html__('Veuillez vérifier les permissions du fichier %s.', 'sitepulse'), $log_file_display); ?></p>
                </div>
            <?php
            }
            elseif (is_array($recent_log_meta) && isset($recent_log_meta['lines']) && empty($recent_log_meta['lines'])) {
            ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e('Votre journal de débogage est accessible.', 'sitepulse'); ?></strong> <?php esc_html_e('Aucune entrée récente n’a été trouvée dans la plage de lecture configurée.', 'sitepulse'); ?></p>
                </div>
            <?php
            }
            elseif ($log_file_exists && !$log_file_readable) {
            ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Le fichier de journal n’est pas lisible.', 'sitepulse'); ?></strong> <?php printf(esc_html__('Vérifiez les permissions de %s.', 'sitepulse'), $log_file_display); ?></p>
                </div>
            <?php
            }
            // Subcase 1.3: The log file does not exist yet
            else {
            ?>
                <div class="notice notice-info">
                    <p><strong><?php esc_html_e('Votre configuration est correcte !', 'sitepulse'); ?></strong> <?php esc_html_e('Le journal de débogage est bien activé dans votre fichier wp-config.php.', 'sitepulse'); ?></p>
                    <p><?php printf(esc_html__('Le fichier %s n’a pas encore été créé car aucune erreur ne s’est produite. Il apparaîtra automatiquement dès que WordPress aura quelque chose à y écrire.', 'sitepulse'), $log_file_display); ?></p>
                </div>
            <?php
            }

        }
        // Case 2: Debug log is NOT enabled in wp-config.php
        else {
        ?>
            <div class="notice notice-warning" style="padding-bottom: 10px;">
                <h2><span class="dashicons dashicons-info-outline" style="padding-top: 4px;"></span> <?php esc_html_e('Journal de débogage non activé', 'sitepulse'); ?></h2>
                <p><?php echo wp_kses_post(sprintf(__('Pour que cet outil fonctionne, WordPress doit être configuré pour enregistrer les erreurs dans un fichier. Cela se fait en modifiant votre fichier <code>%s</code>.', 'sitepulse'), 'wp-config.php')); ?></p>

                <h4><?php esc_html_e('Comment activer le journal de débogage :', 'sitepulse'); ?></h4>
                <ol>
                    <li><?php esc_html_e('Connectez-vous à votre site via FTP ou le gestionnaire de fichiers de votre hébergeur.', 'sitepulse'); ?></li>
                    <li><?php echo wp_kses_post(sprintf(__('Trouvez le fichier <code>%s</code> à la racine de votre installation WordPress.', 'sitepulse'), 'wp-config.php')); ?></li>
                    <li><?php echo wp_kses_post(sprintf(__('Ouvrez ce fichier et cherchez la ligne : <br><code>%s</code>', 'sitepulse'), '/* C’est tout, ne touchez pas à ce qui suit ! Joyeuses publications. */')); ?></li>
                    <li><?php echo wp_kses_post(__('<strong>Juste avant</strong> cette ligne, ajoutez le code suivant :', 'sitepulse')); ?></li>
                </ol>
                <pre style="background: #f7f7f7; padding: 15px; border-radius: 4px;"><?php echo esc_html__("define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );", 'sitepulse'); ?></pre>
                <p><?php echo wp_kses_post(sprintf(__('<strong>Important :</strong> Une fois que vous avez résolu les problèmes, il est recommandé de repasser <code>%1$s</code> à <code>%2$s</code> sur un site en production.', 'sitepulse'), 'WP_DEBUG', 'false')); ?></p>
            </div>
        <?php
        }
        ?>
    </div>
    <?php
}
