<?php
/**
 * Plugin Name:       Bombora Wine Nutrition
 * Plugin URI:        https://github.com/bombora-tech/bombora-wine-nutrition
 * Update URI:        https://github.com/bombora-tech/bombora-wine-nutrition
 * Description:       Centralized, SKU-based management of wine nutrition declarations, ingredients, allergens and e-label QR codes for WooCommerce. Renders a persistent nutrition block into product descriptions.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Bombora group s.r.o.
 * Text Domain:       wine-nutrition-manager
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * WC requires at least: 8.0
 *
 * @package WineNutrition
 */

defined( 'ABSPATH' ) || exit;

// Pojistka: jiná kopie pluginu (např. stará složka wine-nutrition-manager) už běží – nic znovu nedeklarovat.
if ( defined( 'WNM_VERSION' ) ) {
	return;
}

define( 'WNM_VERSION', '2.0.0' );
define( 'WNM_DB_VERSION', '1.1.0' );
define( 'WNM_FILE', __FILE__ );
define( 'WNM_DIR', plugin_dir_path( __FILE__ ) );
define( 'WNM_URL', plugin_dir_url( __FILE__ ) );

require_once WNM_DIR . 'src/Autoloader.php';
\WineNutrition\Autoloader::register();

register_activation_hook( __FILE__, array( \WineNutrition\Database\Schema::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WineNutrition\Database\Schema::class, 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WNM_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'wine-nutrition-manager', false, dirname( plugin_basename( WNM_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Wine Nutrition Manager requires WooCommerce to be active.', 'wine-nutrition-manager' ) . '</p></div>';
				}
			);
			return;
		}

		\WineNutrition\Plugin::instance()->boot();
	},
	20
);

if ( ! function_exists( 'wnm_has_nutrition' ) ) {
	/**
	 * 1 when the current (or given) product has nutrition data, else 0.
	 * Bricks: element condition  {echo:wnm_has_nutrition} == 1
	 *
	 * @param int|string $product_id
	 */
	function wnm_has_nutrition( $product_id = 0 ): int {
		if ( ! class_exists( '\WineNutrition\Plugin' ) || ! did_action( 'plugins_loaded' ) || ! isset( \WineNutrition\Plugin::instance()->shortcode ) ) {
			return 0;
		}
		return \WineNutrition\Plugin::instance()->shortcode->has( (int) $product_id ) ? 1 : 0;
	}
}
