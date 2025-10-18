<?php
if (!defined('ABSPATH')) {
    exit;
}

return [
    SITEPULSE_OPTION_ACTIVE_MODULES => [
        'value' => ['custom_dashboards'],
    ],
    SITEPULSE_OPTION_DEBUG_MODE => [
        'value' => false,
    ],
    SITEPULSE_OPTION_GEMINI_API_KEY => [
        'value' => '',
        'condition' => static function () {
            return !function_exists('sitepulse_is_gemini_api_key_overridden') || !sitepulse_is_gemini_api_key_overridden();
        },
    ],
    SITEPULSE_OPTION_ALERT_ENABLED_CHANNELS => [
        'value' => ['cpu', 'php_fatal'],
    ],
    SITEPULSE_OPTION_CPU_ALERT_THRESHOLD => [
        'value' => 5,
    ],
    SITEPULSE_OPTION_PHP_FATAL_ALERT_THRESHOLD => [
        'value' => 1,
    ],
    SITEPULSE_OPTION_ALERT_COOLDOWN_MINUTES => [
        'value' => 60,
    ],
    SITEPULSE_OPTION_ALERT_INTERVAL => [
        'value' => 5,
    ],
    SITEPULSE_OPTION_ALERT_RECIPIENTS => [
        'value' => [],
    ],
    SITEPULSE_OPTION_ERROR_ALERT_DELIVERY_CHANNELS => [
        'value' => ['email'],
    ],
    SITEPULSE_OPTION_ERROR_ALERT_WEBHOOKS => [
        'value' => [],
    ],
    SITEPULSE_OPTION_ERROR_ALERT_SEVERITIES => [
        'value' => ['warning', 'critical'],
    ],
    SITEPULSE_OPTION_ERROR_ALERT_LOG_POINTER => [
        'value' => [],
    ],
    SITEPULSE_OPTION_ALERT_ACTIVITY => [
        'value' => [],
    ],
    SITEPULSE_OPTION_REQUEST_PROFILER_HISTORY => [
        'value' => [],
    ],
    SITEPULSE_OPTION_CRON_WARNINGS => [
        'value' => [],
    ],
    SITEPULSE_OPTION_SPEED_WARNING_MS => [
        'value' => SITEPULSE_DEFAULT_SPEED_WARNING_MS,
    ],
    SITEPULSE_OPTION_SPEED_CRITICAL_MS => [
        'value' => SITEPULSE_DEFAULT_SPEED_CRITICAL_MS,
    ],
    SITEPULSE_OPTION_SPEED_BENCHMARKS => [
        'value' => [
            'competitors' => [],
            'budgets'     => [],
        ],
    ],
    SITEPULSE_OPTION_UPTIME_WARNING_PERCENT => [
        'value' => SITEPULSE_DEFAULT_UPTIME_WARNING_PERCENT,
    ],
    SITEPULSE_OPTION_REVISION_LIMIT => [
        'value' => SITEPULSE_DEFAULT_REVISION_LIMIT,
    ],
    SITEPULSE_OPTION_REQUEST_TRACE_RETENTION_DAYS => [
        'value' => SITEPULSE_DEFAULT_REQUEST_TRACE_RETENTION_DAYS,
    ],
    SITEPULSE_OPTION_REQUEST_TRACE_SCHEMA_VERSION => [
        'value' => 0,
    ],
    SITEPULSE_OPTION_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT => [
        'value' => SITEPULSE_DEFAULT_RESOURCE_MONITOR_CPU_THRESHOLD_PERCENT,
    ],
    SITEPULSE_OPTION_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT => [
        'value' => SITEPULSE_DEFAULT_RESOURCE_MONITOR_MEMORY_THRESHOLD_PERCENT,
    ],
    SITEPULSE_OPTION_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT => [
        'value' => SITEPULSE_DEFAULT_RESOURCE_MONITOR_DISK_THRESHOLD_PERCENT,
    ],
    SITEPULSE_OPTION_RESOURCE_MONITOR_RETENTION_DAYS => [
        'value' => SITEPULSE_DEFAULT_RESOURCE_MONITOR_RETENTION_DAYS,
    ],
    SITEPULSE_OPTION_RESOURCE_MONITOR_EXPORT_MAX_ROWS => [
        'value' => SITEPULSE_DEFAULT_RESOURCE_MONITOR_EXPORT_MAX_ROWS,
    ],
    SITEPULSE_OPTION_HTTP_MONITOR_SETTINGS => [
        'value' => [
            'latency_threshold_ms' => SITEPULSE_DEFAULT_HTTP_MONITOR_LATENCY_THRESHOLD_MS,
            'error_rate_percent'  => SITEPULSE_DEFAULT_HTTP_MONITOR_ERROR_RATE,
        ],
    ],
    SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS => [
        'value' => SITEPULSE_DEFAULT_HTTP_MONITOR_RETENTION_DAYS,
    ],
    SITEPULSE_OPTION_RUM_SETTINGS => [
        'value' => [
            'enabled'          => false,
            'token'            => '',
            'consent_required' => false,
        ],
    ],
    SITEPULSE_OPTION_RUM_RETENTION_DAYS => [
        'value' => SITEPULSE_DEFAULT_RUM_RETENTION_DAYS,
    ],
];
