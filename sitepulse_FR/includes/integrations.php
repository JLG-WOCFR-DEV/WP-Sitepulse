<?php
if (!defined('ABSPATH')) exit;

// Query Monitor integration
if (class_exists('QueryMonitor')) {
    class SitePulse_QM_Collector extends QM_Collector {
        public $id = 'sitepulse';
        public function name() { return 'SitePulse'; }
        public function process() {
            $this->data['load_time'] = get_option('sitepulse_last_load_time', 'N/A');
            $this->data['uptime'] = get_option('sitepulse_uptime_log', []);
        }
    }
    add_filter('qm/collectors', function($collectors) {
        $collectors['sitepulse'] = new SitePulse_QM_Collector();
        return $collectors;
    });
    sitepulse_log('Integrated with Query Monitor');
}
