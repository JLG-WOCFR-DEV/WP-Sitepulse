<?php
/**
 * SitePulse Custom Dashboards Module
 *
 * This module creates the main dashboard page for the plugin.
 *
 * @package SitePulse
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly.

/**
 * Renders the HTML for the main SitePulse dashboard page.
 *
 * This page provides a visual overview of the site's key metrics,
 * acting as a central hub for site health information.
 *
 * Note: The menu registration for this page is now handled in 'admin-settings.php'
 * to prevent conflicts and duplicate menus.
 */
function custom_dashboards_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    global $wpdb;
    ?>
    <style>
        .sitepulse-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .sitepulse-card { background: #fff; padding: 1px 20px 20px; border: 1px solid #ddd; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
        .sitepulse-card h2 { font-size: 16px; padding-bottom: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 8px; }
        .sitepulse-card .metric { font-size: 2em; font-weight: bold; }
        .sitepulse-card .status-ok { color: #4CAF50; }
        .sitepulse-card .status-warn { color: #FFC107; }
        .sitepulse-card .status-bad { color: #F44336; }
        .sitepulse-card a.button { float: right; margin-top: -45px; }
    </style>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-dashboard"></span> SitePulse Dashboard</h1>
        <p>A real-time overview of your site's performance and health.</p>

        <div class="sitepulse-grid">
            <!-- Speed Card -->
            <div class="sitepulse-card">
                <?php
                $ttfb = get_transient('sitepulse_speed_scan_results')['ttfb'] ?? 0;
                $ttfb_status = $ttfb > 500 ? 'status-bad' : ($ttfb > 200 ? 'status-warn' : 'status-ok');
                ?>
                <?php $ttfb_display = $ttfb ? round($ttfb) . ' ms' : 'N/A'; ?>
                <h2><span class="dashicons dashicons-performance"></span> Speed</h2>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-speed')); ?>" class="button">Details</a>
                <p>Server Response (TTFB): <span class="metric <?php echo esc_attr($ttfb_status); ?>"><?php echo esc_html($ttfb_display); ?></span></p>
                <p class="description">Time to First Byte measures how quickly your server responds. Under 200ms is excellent.</p>
            </div>

            <!-- Uptime Card -->
            <div class="sitepulse-card">
                 <?php
                $uptime_log = get_option('sitepulse_uptime_log', []);
                $total_checks = count($uptime_log);
                $up_checks = count(array_filter($uptime_log));
                $uptime_percentage = $total_checks > 0 ? ($up_checks / $total_checks) * 100 : 100;
                $uptime_status = $uptime_percentage < 99 ? 'status-bad' : ($uptime_percentage < 100 ? 'status-warn' : 'status-ok');
                ?>
                <h2><span class="dashicons dashicons-chart-bar"></span> Uptime</h2>
                 <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-uptime')); ?>" class="button">Details</a>
                <p>Last 30 Checks: <span class="metric <?php echo esc_attr($uptime_status); ?>"><?php echo esc_html(round($uptime_percentage, 2)); ?>%</span></p>
                 <p class="description">Represents your site's availability over the last 30 hours.</p>
            </div>

            <!-- Database Card -->
            <div class="sitepulse-card">
                <?php
                $revisions = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
                $db_status = $revisions > 100 ? 'status-bad' : ($revisions > 50 ? 'status-warn' : 'status-ok');
                ?>
                <h2><span class="dashicons dashicons-database"></span> Database Health</h2>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-db')); ?>" class="button">Optimize</a>
                <p>Post Revisions: <span class="metric <?php echo esc_attr($db_status); ?>"><?php echo esc_html((int)$revisions); ?></span></p>
                <p class="description">Excessive revisions can slow down your database. It's safe to clean them.</p>
            </div>

             <!-- Log Status Card -->
            <div class="sitepulse-card">
                <?php
                $log_file = WP_CONTENT_DIR . '/debug.log';
                $log_status_class = 'status-ok';
                $log_summary = 'Log is clean.';
                if (file_exists($log_file) && filesize($log_file) > 0) {
                    $logs = file_get_contents($log_file);
                    if (stripos($logs, 'PHP Fatal error') !== false) {
                        $log_status_class = 'status-bad';
                        $log_summary = 'Fatal Errors found!';
                    } elseif (stripos($logs, 'PHP Warning') !== false) {
                         $log_status_class = 'status-warn';
                         $log_summary = 'Warnings present.';
                    }
                }
                ?>
                <h2><span class="dashicons dashicons-hammer"></span> Error Log</h2>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-logs')); ?>" class="button">Analyze</a>
                <p>Status: <span class="metric <?php echo esc_attr($log_status_class); ?>"><?php echo esc_html($log_summary); ?></span></p>
                <p class="description">Checks for critical errors in your WordPress debug log.</p>
            </div>
        </div>
    </div>
    <?php
}
