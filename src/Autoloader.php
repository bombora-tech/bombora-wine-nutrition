<?php
/**
 * PSR-4 style autoloader for plugin classes and bundled vendor libraries.
 *
 * @package WineNutrition
 */

namespace WineNutrition;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	/**
	 * Namespace prefix => base directory.
	 *
	 * @var array<string,string>
	 */
	private static array $map = array();

	public static function register(): void {
		self::$map = array(
			'WineNutrition\\'        => WNM_DIR . 'src/',
			'chillerlan\\QRCode\\'   => WNM_DIR . 'vendor/chillerlan/php-qrcode/src/',
			'chillerlan\\Settings\\' => WNM_DIR . 'vendor/chillerlan/php-settings-container/src/',
		);
		spl_autoload_register( array( self::class, 'load' ) );
	}

	public static function load( string $class_name ): void {
		foreach ( self::$map as $prefix => $base ) {
			$len = strlen( $prefix );
			if ( 0 !== strncmp( $class_name, $prefix, $len ) ) {
				continue;
			}
			$file = $base . str_replace( '\\', '/', substr( $class_name, $len ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
			return;
		}
	}
}
