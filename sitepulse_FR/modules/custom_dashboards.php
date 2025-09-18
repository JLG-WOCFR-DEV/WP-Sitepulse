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
function sitepulse_custom_dashboards_page() {
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
        <h1><span class="dashicons-before dashicons-dashboard"></span> <?php esc_html_e('SitePulse Dashboard', 'sitepulse'); ?></h1>
        <p><?php esc_html_e("A real-time overview of your site's performance and health.", 'sitepulse'); ?></p>

        <div class="sitepulse-grid">
            <!-- Speed Card -->
            <div class="sitepulse-card">
                <?php
                $results = get_transient(SITEPULSE_TRANSIENT_SPEED_SCAN_RESULTS);
                $ttfb = null;

                if (is_array($results) && isset($results['ttfb']) && is_numeric($results['ttfb'])) {
                    $ttfb = (float) $results['ttfb'];
                }

                $ttfb_status = 'status-ok';

                if ($ttfb === null) {
                    $ttfb_status = 'status-warn';
                } elseif ($ttfb > 500) {
                    $ttfb_status = 'status-bad';
                } elseif ($ttfb > 200) {
                    $ttfb_status = 'status-warn';
                }
                ?>
                <?php $ttfb_display = $ttfb !== null ? round($ttfb) . ' ' . esc_html__('ms', 'sitepulse') : esc_html__('N/A', 'sitepulse'); ?>
                <h2><span class="dashicons dashicons-performance"></span> <?php esc_html_e('Speed', 'sitepulse'); ?></h2>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-speed')); ?>" class="button"><?php esc_html_e('Details', 'sitepulse'); ?></a>
                <p><?php esc_html_e('Server Response (TTFB):', 'sitepulse'); ?> <span class="metric <?php echo esc_attr($ttfb_status); ?>"><?php echo esc_html($ttfb_display); ?></span></p>
                <p class="description"><?php esc_html_e('Time to First Byte measures how quickly your server responds. Under 200ms is excellent.', 'sitepulse'); ?></p>
            </div>

            <!-- Uptime Card -->
            <div class="sitepulse-card">
                 <?php
                $uptime_log = get_option(SITEPULSE_OPTION_UPTIME_LOG, []);
                $total_checks = count($uptime_log);
                $up_checks = count(array_filter($uptime_log));
                $uptime_percentage = $total_checks > 0 ? ($up_checks / $total_checks) * 100 : 100;
                $uptime_status = $uptime_percentage < 99 ? 'status-bad' : ($uptime_percentage < 100 ? 'status-warn' : 'status-ok');
                ?>
                <h2><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e('Uptime', 'sitepulse'); ?></h2>
                 <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-uptime')); ?>" class="button"><?php esc_html_e('Details', 'sitepulse'); ?></a>
                <p><?php esc_html_e('Last 30 Checks:', 'sitepulse'); ?> <span class="metric <?php echo esc_attr($uptime_status); ?>"><?php echo esc_html(round($uptime_percentage, 2)); ?>%</span></p>
                 <p class="description"><?php esc_html_e("Represents your site's availability over the last 30 hours.", 'sitepulse'); ?></p>
            </div>

            <!-- Database Card -->
            <div class="sitepulse-card">
                <?php
                $revisions = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
                $db_status = $revisions > 100 ? 'status-bad' : ($revisions > 50 ? 'status-warn' : 'status-ok');
                ?>
                <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e('Database Health', 'sitepulse'); ?></h2>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-db')); ?>" class="button"><?php esc_html_e('Optimize', 'sitepulse'); ?></a>
                <p><?php esc_html_e('Post Revisions:', 'sitepulse'); ?> <span class="metric <?php echo esc_attr($db_status); ?>"><?php echo esc_html((int)$revisions); ?></span></p>
                <p class="description"><?php esc_html_e("Excessive revisions can slow down your database. It's safe to clean them.", 'sitepulse'); ?></p>
            </div>

             <!-- Log Status Card -->
            <div class="sitepulse-card">
                <?php
                $log_file = WP_CONTENT_DIR . '/debug.log';
                $log_status_class = 'status-ok';
                $log_summary = esc_html__('Log is clean.', 'sitepulse');

                if (!file_exists($log_file)) {
                    $log_status_class = 'status-warn';
                    $log_summary = esc_html__('Log file not found.', 'sitepulse');
                } else {
                    $recent_logs = sitepulse_get_recent_log_lines($log_file, 200, 131072);

                    if ($recent_logs === null) {
                        $log_status_class = 'status-warn';
                        $log_summary = esc_html__('Unable to read log file.', 'sitepulse');
                    } elseif (empty($recent_logs)) {
                        $log_summary = esc_html__('No recent log entries.', 'sitepulse');
                    } else {
                        $log_content = implode("\n", $recent_logs);

                        if (stripos($log_content, 'PHP Fatal error') !== false) {
                            $log_status_class = 'status-bad';
                            $log_summary = esc_html__('Fatal Errors found!', 'sitepulse');
                        } elseif (stripos($log_content, 'PHP Warning') !== false) {
                            $log_status_class = 'status-warn';
                            $log_summary = esc_html__('Warnings present.', 'sitepulse');
                        }
                    }
                }
                ?>
                <h2><span class="dashicons dashicons-hammer"></span> <?php esc_html_e('Error Log', 'sitepulse'); ?></h2>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sitepulse-logs')); ?>" class="button"><?php esc_html_e('Analyze', 'sitepulse'); ?></a>
                <p><?php esc_html_e('Status:', 'sitepulse'); ?> <span class="metric <?php echo esc_attr($log_status_class); ?>"><?php echo esc_html($log_summary); ?></span></p>
                <p class="description"><?php esc_html_e('Checks for critical errors in your WordPress debug log.', 'sitepulse'); ?></p>
            </div>
        </div>
    </div>
    <?php
}
