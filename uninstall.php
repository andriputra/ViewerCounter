<?php
/**
 * Remove the visits table and plugin options on uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'viewer_counter_visits';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted prefix.
$wpdb->query("DROP TABLE IF EXISTS {$table}");
delete_option('viewer_counter_settings');
