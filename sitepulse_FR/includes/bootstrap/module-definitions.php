<?php
if (!defined('ABSPATH')) {
    exit;
}

return [
    'log_analyzer' => [
        'label' => 'Log Analyzer',
        'path' => SITEPULSE_PATH . 'modules/log_analyzer.php',
    ],
    'resource_monitor' => [
        'label' => 'Resource Monitor',
        'path' => SITEPULSE_PATH . 'modules/resource_monitor.php',
    ],
    'plugin_impact_scanner' => [
        'label' => 'Plugin Impact Scanner',
        'path' => SITEPULSE_PATH . 'modules/plugin_impact_scanner.php',
    ],
    'speed_analyzer' => [
        'label' => 'Speed Analyzer',
        'path' => SITEPULSE_PATH . 'modules/speed_analyzer.php',
    ],
    'database_optimizer' => [
        'label' => 'Database Optimizer',
        'path' => SITEPULSE_PATH . 'modules/database_optimizer.php',
    ],
    'maintenance_advisor' => [
        'label' => 'Maintenance Advisor',
        'path' => SITEPULSE_PATH . 'modules/maintenance_advisor.php',
    ],
    'uptime_tracker' => [
        'label' => 'Uptime Tracker',
        'path' => SITEPULSE_PATH . 'modules/uptime_tracker.php',
    ],
    'ai_insights' => [
        'label' => 'AI-Powered Insights',
        'path' => SITEPULSE_PATH . 'modules/ai_insights.php',
    ],
    'custom_dashboards' => [
        'label' => 'Custom Dashboards',
        'path' => SITEPULSE_PATH . 'modules/custom_dashboards.php',
    ],
    'error_alerts' => [
        'label' => 'Error Alerts',
        'path' => SITEPULSE_PATH . 'modules/error_alerts.php',
    ],
];
