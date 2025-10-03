<?php
if (!defined('ABSPATH')) exit;

// Query Monitor integration
add_action('plugins_loaded', function () {
    if (!class_exists('QM_Collector')) {
        return;
    }

    if (!class_exists('SitePulse_QM_Collector', false)) {
        class SitePulse_QM_Collector extends QM_Collector {
            public $id = 'sitepulse';

            public function name() { return 'SitePulse'; }

            public function process() {
                $this->data['load_time'] = get_option(SITEPULSE_OPTION_LAST_LOAD_TIME, 'N/A');
                if (function_exists('sitepulse_get_uptime_log_store')) {
                    $this->data['uptime'] = sitepulse_get_uptime_log_store();
                } else {
                    $this->data['uptime'] = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
                }
            }
        }
    }

    add_filter('qm/collectors', function ($collectors) {
        $collectors['sitepulse'] = new SitePulse_QM_Collector();
        return $collectors;
    });

    sitepulse_log('Integrated with Query Monitor');
}, 100);
