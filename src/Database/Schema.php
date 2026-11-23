<?php
/**
 * Table creation, versioning and migrations.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Database;

use WineNutrition\Services\FieldRegistry;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION_OPTION = 'wnm_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wine_nutrition';
	}

	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wine_nutrition_log';
	}

	public static function lookup_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_product_meta_lookup';
	}

	/**
	 * Activation hook: create/upgrade tables, seed defaults, schedule maintenance.
	 * Never drops or overwrites data.
	 */
	public static function activate(): void {
		self::install();
		FieldRegistry::seed_defaults();
		if ( ! wp_next_scheduled( 'wnm_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wnm_daily_maintenance' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wnm_daily_maintenance' );
	}

	/**
	 * Runs on every load (one autoloaded option comparison). Covers updates that bypass activation.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) !== WNM_DB_VERSION ) {
			self::install();
			FieldRegistry::seed_defaults();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$installed = (string) get_option( self::VERSION_OPTION, '0' );
		$collate   = $wpdb->get_charset_collate();
		$table     = self::table();
		$log       = self::log_table();

		// dbDelta rules: one field per line, two spaces after PRIMARY KEY, KEY instead of INDEX.
		// dbDelta only adds/alters; it never drops columns or rows.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sku varchar(191) NOT NULL,
  energy_kj decimal(10,2) DEFAULT NULL,
  energy_kcal decimal(10,2) DEFAULT NULL,
  fat decimal(10,2) DEFAULT NULL,
  saturated_fat decimal(10,2) DEFAULT NULL,
  carbohydrates decimal(10,2) DEFAULT NULL,
  sugars decimal(10,2) DEFAULT NULL,
  protein decimal(10,2) DEFAULT NULL,
  salt decimal(10,2) DEFAULT NULL,
  qualifiers varchar(255) DEFAULT NULL,
  ingredients longtext NULL,
  allergens longtext NULL,
  qr_url varchar(2048) DEFAULT NULL,
  custom_fields longtext NULL,
  source varchar(255) DEFAULT NULL,
  updated_by bigint(20) unsigned DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY sku (sku),
  KEY updated_at (updated_at)
) {$collate};
CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(32) NOT NULL,
  level varchar(16) NOT NULL DEFAULT 'info',
  sku varchar(191) NOT NULL DEFAULT '',
  run_id varchar(64) NOT NULL DEFAULT '',
  details text NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY action (action),
  KEY sku (sku),
  KEY run_id (run_id)
) {$collate};";

		dbDelta( $sql );

		foreach ( self::migrations() as $version => $callback ) {
			if ( version_compare( $installed, $version, '<' ) && version_compare( WNM_DB_VERSION, $version, '>=' ) ) {
				call_user_func( $callback );
			}
		}

		update_option( self::VERSION_OPTION, WNM_DB_VERSION, true );
	}

	/**
	 * Data migrations dbDelta cannot express (renames, data transforms).
	 * Column/index additions belong in the CREATE TABLE statements above.
	 *
	 * @return array<string,callable> version => callback, ascending.
	 */
	private static function migrations(): array {
		return array(
			// '1.1.0' => array( self::class, 'migrate_1_1_0' ),
		);
	}
}
