<?php
/**
 * Generates static SVG QR images in uploads, so they keep working when the plugin is disabled.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

defined( 'ABSPATH' ) || exit;

final class QrService {

	private const DIR = 'wine-nutrition-qr';

	/** Public URL of the QR SVG for $target, generating it if needed. Null on failure. */
	public function image_url( string $target ): ?string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$dir  = trailingslashit( $uploads['basedir'] ) . self::DIR;
		$file = md5( $target ) . '.svg';
		$path = $dir . '/' . $file;

		if ( ! file_exists( $path ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}
			$svg = $this->svg( $target );
			if ( null === $svg || false === file_put_contents( $path, $svg ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return null;
			}
		}
		return trailingslashit( $uploads['baseurl'] ) . self::DIR . '/' . $file;
	}

	private function svg( string $target ): ?string {
		if ( ! class_exists( QRCode::class ) || ! extension_loaded( 'mbstring' ) ) {
			return null;
		}
		try {
			$options = new QROptions(
				array(
					'outputInterface'      => QRMarkupSVG::class,
					'outputBase64'         => false,
					'eccLevel'             => EccLevel::M,
					'addQuietzone'         => true,
					'svgAddXmlHeader'      => true,
					'drawLightModules'     => false,
					'connectPaths'         => true,
					'svgUseFillAttributes' => true,
					'markupDark'           => '#000000',
				)
			);
			$svg = ( new QRCode( $options ) )->render( $target );
			return is_string( $svg ) ? $svg : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
