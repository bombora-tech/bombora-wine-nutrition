<?php
/**
 * Uninstall routine.
 *
 * Nutrition data, logs, settings, labels, custom field definitions, QR images and the
 * wnm_nutrition_html product meta (frontend snapshot used by Bricks) are KEPT
 * when the plugin is deactivated or deleted. Reinstalling/reactivating picks everything up
 * unchanged (dbDelta never drops data, defaults are only added when missing).
 *
 * Permanent removal requires an explicit constant in wp-config.php:
 *     define( 'WNM_DELETE_DATA_ON_UNINSTALL', true );
 *
 * @package WineNutrition
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'wnm_daily_maintenance' );
delete_transient( 'wnm_stats' );

if ( ! defined( 'WNM_DELETE_DATA_ON_UNINSTALL' ) || true !== WNM_DELETE_DATA_ON_UNINSTALL ) {
	return;
}

global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are fixed, no user input.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wine_nutrition" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wine_nutrition_log" );
// phpcs:enable
delete_post_meta_by_key( 'wnm_nutrition_html' );

foreach ( array( 'wnm_db_version', 'wnm_settings', 'wnm_enabled_fields', 'wnm_custom_fields', 'wnm_labels', 'wnm_labels_hash', 'wnm_plugin_version' ) as $wnm_option ) {
	delete_option( $wnm_option );
}
