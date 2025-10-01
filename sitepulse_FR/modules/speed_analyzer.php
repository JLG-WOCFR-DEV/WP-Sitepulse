<?php
if (!defined('ABSPATH')) exit;

// Add admin submenu
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        'Speed Analyzer',
        'Speed',
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
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-performance"></span> Analyseur de Vitesse</h1>
        <p>Cet outil analyse la performance interne de votre serveur et de votre base de données à chaque chargement de page.</p>

        <div class="speed-grid">
            <!-- Server Processing Card -->
            <div class="speed-card">
                <h3><span class="dashicons dashicons-server"></span> Performance du Serveur (Backend)</h3>
                <p>Ces métriques mesurent la vitesse à laquelle votre serveur exécute le code PHP et génère la page actuelle.</p>
                <ul class="health-list">
                    <?php
                    $gen_time_status = $page_generation_time < 1000 ? 'status-ok' : ($page_generation_time < 2000 ? 'status-warn' : 'status-bad');
                    ?>
                    <li>
                        <span class="metric-name">Temps de Génération de la Page</span>
                        <?php $gen_time_meta = $get_status_meta($gen_time_status); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($gen_time_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($gen_time_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($gen_time_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($gen_time_meta['sr']); ?></span>
                            <span class="status-reading"><?php echo esc_html(round($page_generation_time) . ' ms'); ?></span>
                        </span>
                        <p class="description">C'est le temps total que met votre serveur pour préparer cette page. Un temps élevé (&gt;1s) peut indiquer un hébergement lent ou un plugin qui consomme beaucoup de ressources.</p>
                    </li>
                </ul>
            </div>

            <!-- Database Performance Card -->
            <div class="speed-card">
                <h3><span class="dashicons dashicons-database"></span> Performance de la Base de Données</h3>
                <p>Analyse la communication entre WordPress et votre base de données pour cette page.</p>
                <ul class="health-list">
                    <?php
                    // Database Query Time Analysis
                    if ($savequeries_enabled) {
                        $db_time_status = $db_query_total_time < 500 ? 'status-ok' : 'status-bad';
                        ?>
                        <li>
                            <span class="metric-name">Temps Total des Requêtes BDD</span>
                            <?php $db_time_meta = $get_status_meta($db_time_status); ?>
                            <span class="metric-value">
                                <span class="status-badge <?php echo esc_attr($db_time_status); ?>" aria-hidden="true">
                                    <span class="status-icon"><?php echo esc_html($db_time_meta['icon']); ?></span>
                                    <span class="status-text"><?php echo esc_html($db_time_meta['label']); ?></span>
                                </span>
                                <span class="screen-reader-text"><?php echo esc_html($db_time_meta['sr']); ?></span>
                                <span class="status-reading"><?php echo esc_html(round($db_query_total_time) . ' ms'); ?></span>
                            </span>
                            <p class="description">Le temps total passé à attendre la base de données. S'il est élevé, cela peut indiquer des requêtes complexes ou une base de données surchargée.</p>
                        </li>
                        <?php
                    } else {
                        ?>
                        <li>
                            <span class="metric-name">Temps Total des Requêtes BDD</span>
                            <?php $db_time_meta = $get_status_meta('status-warn'); ?>
                            <span class="metric-value">
                                <span class="status-badge status-warn" aria-hidden="true">
                                    <span class="status-icon"><?php echo esc_html($db_time_meta['icon']); ?></span>
                                    <span class="status-text"><?php echo esc_html($db_time_meta['label']); ?></span>
                                </span>
                                <span class="screen-reader-text"><?php echo esc_html($db_time_meta['sr']); ?></span>
                                <span class="status-reading">N/A</span>
                            </span>
                            <p class="description">Pour activer cette mesure, ajoutez <code>define('SAVEQUERIES', true);</code> à votre fichier <code>wp-config.php</code>. <strong>Note:</strong> N'utilisez ceci que pour le débogage, car cela peut ralentir votre site.</p>
                        </li>
                        <?php
                    }

                    // Database Query Count Analysis
                    $db_count_status = $db_query_count < 100 ? 'status-ok' : ($db_query_count < 200 ? 'status-warn' : 'status-bad');
                    ?>
                    <li>
                        <span class="metric-name">Nombre de Requêtes BDD</span>
                        <?php $db_count_meta = $get_status_meta($db_count_status); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($db_count_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($db_count_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($db_count_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($db_count_meta['sr']); ?></span>
                            <span class="status-reading"><?php echo esc_html($db_query_count); ?></span>
                        </span>
                        <p class="description">Le nombre de fois que WordPress a interrogé la base de données. Un nombre élevé (&gt;100) peut être le signe d'un plugin ou d'un thème mal optimisé.</p>
                    </li>
                </ul>
            </div>
             <!-- Server Configuration Card -->
            <div class="speed-card">
                <h3><span class="dashicons dashicons-admin-settings"></span> Configuration Serveur</h3>
                <p>Des réglages serveur optimaux sont essentiels pour la performance.</p>
                <ul class="health-list">
                    <?php
                    // Object Cache Check
                    $cache_status_class = $object_cache_active ? 'status-ok' : 'status-warn';
                    $cache_text = $object_cache_active ? 'Actif' : 'Non Détecté';
                    ?>
                    <li>
                        <span class="metric-name">Object Cache</span>
                        <?php $cache_meta = $get_status_meta($cache_status_class); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($cache_status_class); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($cache_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($cache_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($cache_meta['sr']); ?></span>
                            <span class="status-reading"><?php echo esc_html($cache_text); ?></span>
                        </span>
                        <p class="description">Un cache d'objets persistant (ex: Redis, Memcached) accélère énormément les requêtes répétitives. Fortement recommandé.</p>
                    </li>
                    <?php
                    // PHP Version Check
                    $php_status = version_compare($php_version, '8.0', '>=') ? 'status-ok' : 'status-warn';
                    ?>
                    <li>
                        <span class="metric-name">Version de PHP</span>
                        <?php $php_meta = $get_status_meta($php_status); ?>
                        <span class="metric-value">
                            <span class="status-badge <?php echo esc_attr($php_status); ?>" aria-hidden="true">
                                <span class="status-icon"><?php echo esc_html($php_meta['icon']); ?></span>
                                <span class="status-text"><?php echo esc_html($php_meta['label']); ?></span>
                            </span>
                            <span class="screen-reader-text"><?php echo esc_html($php_meta['sr']); ?></span>
                            <span class="status-reading"><?php echo esc_html($php_version); ?></span>
                        </span>
                        <p class="description">Les versions modernes de PHP (8.0+) sont beaucoup plus rapides et sécurisées. Demandez à votre hébergeur de mettre à jour si nécessaire.</p>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <?php
}
