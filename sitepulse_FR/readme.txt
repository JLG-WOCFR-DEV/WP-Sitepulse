=== Sitepulse - JLG ===
Contributors: jeromelegousse
Tags: performance, monitoring, speed, database, server
Requires at least: 5.0
Tested up to: 6.6
Stable tag: 1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Monitors your WordPress site's speed, database, maintenance, server, and errors with modular, toggleable tools.

== Description ==

Sitepulse - JLG takes the pulse of your WordPress site, offering modules for:

* Speed analysis (load times, TTFB)
* Database optimization (clean bloat, suggest indexes)
* Server monitoring (CPU, memory, uptime)
* Error logging and alerts
* Plugin impact analysis
* Maintenance checks and AI insights
* Custom dashboards and multisite support

Toggle modules in the admin panel to keep it lightweight. Includes debug mode and cleanup options.

== Installation ==

1.  Upload `sitepulse-jlg.zip` to your `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Visit 'SitePulse' in your admin menu to configure the modules.

== Changelog ==

= 1.0 =
* Initial release with all core modules.
* Removed the call to `flush_rewrite_rules()` on activation to avoid an unnecessary and costly permalink flush.

== Upgrade Notice ==

= 1.0 =
* First version—full pulse-monitoring suite!
