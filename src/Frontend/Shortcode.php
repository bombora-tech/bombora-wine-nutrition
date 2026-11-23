<?php
/**
 * [wine_nutrition] shortcode: live compact output (same markup as the product meta snapshot).
 *
 * Attributes (defaults come from Settings): sku, id, layout=table|inline, title=yes|no,
 * tag=h2–h6|p|div, ingredients=yes|no, allergens=yes|no, qr=both|image|link|no, class.
 *
 * Note: shortcodes don't run while the plugin is deactivated. For output that always works,
 * use the meta snapshot {cf_wnm_nutrition_html} in Bricks (see docs/BRICKS.md).
 *
 * @package WineNutrition
 */

namespace WineNutrition\Frontend;

use WineNutrition\Database\NutritionRepository;
use WineNutrition\WPML\LabelTranslator;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public const TAG = 'wine_nutrition';

	/** When true, the wnm_nutrition_html meta is read from the DB as stored (no live override). */
	public static bool $raw_meta = false;

	private ?\WineNutrition\Services\ProductSync $sync = null;

	/** @var array<int,string> per-request live meta cache */
	private array $live = array();

	/** @var array<string,array|false> per-request record cache keyed by SKU */
	private array $cache = array();

	public function __construct(
		private NutritionRepository $repo,
		private BlockRenderer $renderer,
		private LabelTranslator $labels
	) {}

	public function set_sync( \WineNutrition\Services\ProductSync $sync ): void {
		$this->sync = $sync;
	}

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		// While the plugin is active, {cf_wnm_nutrition_html} always returns the current rendering
		// (settings changes apply instantly, no wait for background jobs). The stored value is
		// refreshed when it differs, so it's current if the plugin is later deactivated.
		add_filter( 'get_post_metadata', array( $this, 'live_meta' ), 10, 4 );
		add_filter(
			'bricks/code/echo_function_names',
			static fn( $names ) => array_merge( (array) $names, array( 'wnm_has_nutrition' ) )
		);
	}

	/**
	 * @param array|string $atts
	 */
	public function render( $atts ): string {
		$d = $this->renderer->compact_defaults();
		$a = shortcode_atts(
			array(
				'sku'         => '',
				'id'          => 0,
				'layout'      => $d['layout'],
				'title'       => $d['title'] ? 'yes' : 'no',
				'tag'         => $d['tag'],
				'ingredients' => $d['ingredients'] ? 'yes' : 'no',
				'allergens'   => $d['allergens'] ? 'yes' : 'no',
				'qr'          => $d['qr'],
				'class'       => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		[ $sku, $product_id ] = $this->resolve( (string) $a['sku'], (int) $a['id'] );
		$record               = '' === $sku ? null : $this->record( $sku );
		if ( ! $record ) {
			return '';
		}

		$lang = $product_id ? $this->labels->post_language( $product_id ) : null;
		$lang = $lang ?? ( LabelTranslator::wpml_active() ? apply_filters( 'wpml_current_language', null ) : null );
		$yes  = static fn( $v ) => in_array( strtolower( (string) $v ), array( '1', 'yes', 'true', 'on' ), true );

		$html = $this->renderer->render_compact(
			$record,
			is_string( $lang ) ? $lang : null,
			array(
				'layout'      => (string) $a['layout'],
				'title'       => $yes( $a['title'] ),
				'tag'         => (string) $a['tag'],
				'ingredients' => $yes( $a['ingredients'] ),
				'allergens'   => $yes( $a['allergens'] ),
				'qr'          => in_array( $a['qr'], array( 'both', 'image', 'link', 'no' ), true ) ? $a['qr'] : $d['qr'],
				'class'       => (string) $a['class'],
			)
		);

		/**
		 * Filters the shortcode HTML.
		 *
		 * @param string $html
		 * @param array  $record
		 * @param array  $atts
		 */
		return (string) apply_filters( 'wnm_shortcode_html', $html, $record, $a );
	}

	/**
	 * @param mixed  $value
	 * @param int    $object_id
	 * @param string $meta_key
	 * @param bool   $single
	 * @return mixed
	 */
	public function live_meta( $value, $object_id, $meta_key, $single ) {
		if ( self::$raw_meta || null !== $value || ! $this->sync || \WineNutrition\Services\ProductSync::META_KEY !== $meta_key ) {
			return $value;
		}
		$id = (int) $object_id;
		if ( ! isset( $this->live[ $id ] ) ) {
			if ( 'product' !== get_post_type( $id ) ) {
				return $value;
			}
			[ $sku ] = $this->resolve( '', $id );
			$record  = '' === $sku ? null : $this->record( $sku );
			$lang    = $this->labels->post_language( $id );
			$html    = $this->sync->snapshot_html( $id, $record, $lang );

			self::$raw_meta = true;
			$stored         = (string) get_post_meta( $id, \WineNutrition\Services\ProductSync::META_KEY, true );
			self::$raw_meta = false;
			if ( $stored !== $html ) {
				$this->sync->write_snapshot( $id, $record, $lang ); // self-heal stale snapshot
			}
			$this->live[ $id ] = $html;
		}
		return $single ? array( $this->live[ $id ] ) : ( '' === $this->live[ $id ] ? array() : array( $this->live[ $id ] ) );
	}

	/** True when the current (or given) product has nutrition data to show. */
	public function has( int $product_id = 0 ): bool {
		[ $sku ] = $this->resolve( '', $product_id );
		return '' !== $sku && (bool) $this->record( $sku );
	}

	/**
	 * @return array{0:string,1:int} [sku, product_id]
	 */
	private function resolve( string $sku, int $id ): array {
		$sku = trim( sanitize_text_field( $sku ) );
		if ( '' !== $sku ) {
			return array( $sku, 0 );
		}
		if ( ! $id ) {
			global $product;
			$id = $product instanceof \WC_Product ? $product->get_id() : (int) get_the_ID();
		}
		$p = $id ? wc_get_product( $id ) : null;
		if ( ! $p ) {
			return array( '', 0 );
		}
		if ( $p->is_type( 'variation' ) ) {
			$id = $p->get_parent_id();
			$p  = wc_get_product( $id );
		}
		return array( $p ? (string) $p->get_sku( 'edit' ) : '', (int) $id );
	}

	private function record( string $sku ): ?array {
		if ( ! array_key_exists( $sku, $this->cache ) ) {
			$this->cache[ $sku ] = $this->repo->find( $sku ) ?? false;
		}
		return $this->cache[ $sku ] ?: null;
	}
}
