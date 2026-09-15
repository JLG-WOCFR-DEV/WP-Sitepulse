<?php
if (!defined('ABSPATH')) {
    exit;
}

return [
    'log_analyzer' => [
        'label' => 'Analyseur de journaux',
        'path' => SITEPULSE_PATH . 'modules/log_analyzer.php',
    ],
    'resource_monitor' => [
        'label' => 'Moniteur de ressources',
        'path' => SITEPULSE_PATH . 'modules/resource_monitor.php',
    ],
    'plugin_impact_scanner' => [
        'label' => 'Analyseur d’impact des extensions',
        'path' => SITEPULSE_PATH . 'modules/plugin_impact_scanner.php',
    ],
    'speed_analyzer' => [
        'label' => 'Analyseur de vitesse',
        'path' => SITEPULSE_PATH . 'modules/speed_analyzer.php',
    ],
    'database_optimizer' => [
        'label' => 'Optimiseur de base de données',
        'path' => SITEPULSE_PATH . 'modules/database_optimizer.php',
    ],
    'maintenance_advisor' => [
        'label' => 'Conseiller de maintenance',
        'path' => SITEPULSE_PATH . 'modules/maintenance_advisor.php',
    ],
    'uptime_tracker' => [
        'label' => 'Suivi de disponibilité',
        'path' => SITEPULSE_PATH . 'modules/uptime_tracker.php',
    ],
    'ai_insights' => [
        'label' => 'Analyses IA',
        'path' => SITEPULSE_PATH . 'modules/ai_insights.php',
    ],
    'custom_dashboards' => [
        'label' => 'Tableaux de bord',
        'path' => SITEPULSE_PATH . 'modules/custom_dashboards.php',
    ],
    'error_alerts' => [
        'label' => 'Alertes d’erreurs',
        'path' => SITEPULSE_PATH . 'modules/error_alerts.php',
    ],
];
