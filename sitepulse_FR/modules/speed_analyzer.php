<?php
if (!defined('ABSPATH')) exit;

// Add admin submenu
add_action('admin_menu', function() {
    add_submenu_page(
        'sitepulse-dashboard',
        'Speed Analyzer',
        'Speed',
        'manage_options',
        'sitepulse-speed',
        'sitepulse_speed_analyzer_page'
    );
});

/**
 * Renders the Speed Analyzer page.
 * The analysis is now based on internal WordPress timers for better reliability.
 */
function sitepulse_speed_analyzer_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    global $wpdb;

    // --- Server Performance Metrics ---

    // 1. Page Generation Time (Backend processing)
    // **FIX:** Replaced timer_stop() with a direct microtime calculation to prevent non-numeric value warnings in specific environments.
    $page_generation_time = (microtime(true) - $GLOBALS['timestart']) * 1000; // in milliseconds

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

    ?>
    <style>
        .speed-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
        .speed-card { background: #fff; padding: 20px; border: 1px solid #ddd; }
        .speed-card h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .health-list { list-style: none; padding-left: 0; }
        .health-list li { padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .health-list li:last-child { border-bottom: none; }
        .health-list .metric-name { font-weight: bold; display: block; }
        .health-list .metric-value { float: right; font-weight: bold; }
        .status-ok { color: #4CAF50; }
        .status-warn { color: #FFC107; }
        .status-bad { color: #F44336; }
    </style>
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
                        echo "<li>
                                <span class='metric-name'>Temps de Génération de la Page</span>
                                <span class='metric-value $gen_time_status'>" . round($page_generation_time) . " ms</span>
                                <p class='description'>C'est le temps total que met votre serveur pour préparer cette page. Un temps élevé (>1s) peut indiquer un hébergement lent ou un plugin qui consomme beaucoup de ressources.</p>
                              </li>";
                    ?>
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
                            echo "<li>
                                    <span class='metric-name'>Temps Total des Requêtes BDD</span>
                                    <span class='metric-value $db_time_status'>" . round($db_query_total_time) . " ms</span>
                                    <p class='description'>Le temps total passé à attendre la base de données. S'il est élevé, cela peut indiquer des requêtes complexes ou une base de données surchargée.</p>
                                  </li>";
                        } else {
                            echo "<li>
                                    <span class='metric-name'>Temps Total des Requêtes BDD</span>
                                    <span class='metric-value status-warn'>N/A</span>
                                    <p class='description'>Pour activer cette mesure, ajoutez <code>define('SAVEQUERIES', true);</code> à votre fichier <code>wp-config.php</code>. <strong>Note:</strong> N'utilisez ceci que pour le débogage, car cela peut ralentir votre site.</p>
                                  </li>";
                        }

                        // Database Query Count Analysis
                        $db_count_status = $db_query_count < 100 ? 'status-ok' : ($db_query_count < 200 ? 'status-warn' : 'status-bad');
                        echo "<li>
                                <span class='metric-name'>Nombre de Requêtes BDD</span>
                                <span class='metric-value $db_count_status'>$db_query_count</span>
                                <p class='description'>Le nombre de fois que WordPress a interrogé la base de données. Un nombre élevé (>100) peut être le signe d'un plugin ou d'un thème mal optimisé.</p>
                              </li>";
                    ?>
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
                        echo "<li>
                                <span class='metric-name'>Object Cache</span>
                                <span class='metric-value $cache_status_class'>$cache_text</span>
                                <p class='description'>Un cache d'objets persistant (ex: Redis, Memcached) accélère énormément les requêtes répétitives. Fortement recommandé.</p>
                              </li>";

                        // PHP Version Check
                        $php_status = version_compare($php_version, '8.0', '>=') ? 'status-ok' : 'status-warn';
                        echo "<li>
                                <span class='metric-name'>Version de PHP</span>
                                <span class='metric-value $php_status'>$php_version</span>
                                <p class='description'>Les versions modernes de PHP (8.0+) sont beaucoup plus rapides et sécurisées. Demandez à votre hébergeur de mettre à jour si nécessaire.</p>
                              </li>";
                    ?>
                </ul>
            </div>
        </div>
    </div>
    <?php
}
