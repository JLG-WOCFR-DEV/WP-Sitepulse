<?php
if (!defined('ABSPATH')) exit;

// Add admin submenu
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Speed Analyzer', 'sitepulse'),
        __('Speed', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-speed',
        'sitepulse_speed_analyzer_page'
    );
});

add_action('admin_enqueue_scripts', 'sitepulse_speed_analyzer_enqueue_assets');

/**
 * Enqueues the Speed Analyzer stylesheet on the relevant admin page.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_speed_analyzer_enqueue_assets($hook_suffix) {
    if ($hook_suffix !== 'sitepulse-dashboard_page_sitepulse-speed') {
        return;
    }

    wp_enqueue_style(
        'sitepulse-speed-analyzer',
        SITEPULSE_URL . 'modules/css/speed-analyzer.css',
        [],
        SITEPULSE_VERSION
    );
}

/**
 * Renders the Speed Analyzer page.
 * The analysis is now based on internal WordPress timers for better reliability.
 */
function sitepulse_speed_analyzer_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

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

    $default_speed_warning = defined('SITEPULSE_DEFAULT_SPEED_WARNING_MS') ? (int) SITEPULSE_DEFAULT_SPEED_WARNING_MS : 200;
    $default_speed_critical = defined('SITEPULSE_DEFAULT_SPEED_CRITICAL_MS') ? (int) SITEPULSE_DEFAULT_SPEED_CRITICAL_MS : 500;
    $speed_warning_threshold = $default_speed_warning;
    $speed_critical_threshold = $default_speed_critical;

    if (function_exists('sitepulse_get_speed_thresholds')) {
        $fetched_thresholds = sitepulse_get_speed_thresholds();

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

    $status_labels = [
        'status-ok'   => [
            'label' => __('Bon', 'sitepulse'),
            'sr'    => __('Statut : bon', 'sitepulse'),
            'icon'  => '✔️',
        ],
        'status-warn' => [
            'label' => __('Attention', 'sitepulse'),
            'sr'    => __('Statut : attention', 'sitepulse'),
            'icon'  => '⚠️',
        ],
        'status-bad'  => [
            'label' => __('Critique', 'sitepulse'),
            'sr'    => __('Statut : critique', 'sitepulse'),
            'icon'  => '⛔',
        ],
    ];

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
        <h1><span class="dashicons-before dashicons-performance"></span> <?php esc_html_e('Analyseur de Vitesse', 'sitepulse'); ?></h1>
        <p><?php esc_html_e('Cet outil analyse la performance interne de votre serveur et de votre base de données à chaque chargement de page.', 'sitepulse'); ?></p>

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
