=== Viewer Counter ===
Contributors: agusandriputra
Tags: visitor counter, analytics, shortcode, widget, statistics
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Viewer Counter tracks unique visitors (daily, weekly, monthly, all-time) and shows stats with shortcode, widget, and admin dashboard charts.

== Description ==

Viewer Counter helps you monitor unique visitor trends directly inside WordPress.

Features:

* Unique visitor counting with daily de-duplication.
* Statistics for daily, weekly, monthly, and all-time visitors.
* Admin dashboard page with chart visualizations.
* Shortcode support: `[viewer_counter]`.
* Widget support for sidebar/footer display.
* Settings page to choose default stats shown.
* Built-in abuse mitigation (rate limiting and strict UUID validation).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the ZIP from **Plugins > Add New > Upload Plugin**.
2. Activate **Viewer Counter** through the **Plugins** menu.
3. Open **Viewer Counter** in the WordPress admin menu to view dashboard and settings.

== Frequently Asked Questions ==

= How do I show the visitor stats on a page or post? =

Use shortcode:

`[viewer_counter]`

= Can I control which stats are shown? =

Yes. You can set defaults in **Viewer Counter > Settings**, or override per shortcode:

`[viewer_counter show="daily,weekly,total"]`

Accepted values: `daily`, `weekly`, `monthly`, `total`.

= Does it count admin visits? =

By default, admin visits can be skipped to reduce skewed data.

== Changelog ==

= 1.2.2 =

* Added dashboard chart views (daily, weekly, monthly) with date filters.
* Added stronger abuse mitigation (strict UUID validation and rate limiting).
* Improved shortcode and widget output controls.

== Upgrade Notice ==

= 1.2.2 =

Recommended update for improved dashboard reporting and abuse mitigation.
