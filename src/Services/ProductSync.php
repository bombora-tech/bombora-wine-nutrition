<?php
/**
 * Writes rendered blocks into product descriptions (all WPML translations sharing the SKU).
 * Bulk work runs through Action Scheduler (bundled with WooCommerce).
 *
 * @package WineNutrition
 */

namespace WineNutrition\Services;

use WineNutrition\Database\NutritionRepository;
use WineNutrition\Frontend\BlockRenderer;
use WineNutrition\Logging\Logger;
use WineNutrition\WPML\LabelTranslator;

defined( 'ABSPATH' ) || exit;

final class ProductSync {

	public const AS_HOOK  = 'wnm_render_skus';
	public const AS_GROUP = 'wine-nutrition-manager';
	public const BATCH    = 25;
	public const META_KEY = 'wnm_nutrition_html';

	public function __construct(
		private NutritionRepository $repo,
		private BlockRenderer $renderer,
		private LabelTranslator $labels,
		private FieldRegistry $fields,
		private Logger $logger
	) {}

	public function register(): void {
		add_action( self::AS_HOOK, array( $this, 'render_skus' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_save' ), 50, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_save' ), 50, 1 );
	}

	/** Keeps the block in sync when a product (or its translation) is saved or gets a SKU. */
	public function on_product_save( $product_id ): void {
		if ( ! $this->fields->setting( 'render_on_product_save' ) ) {
			return;
		}
		$product = wc_get_product( (int) $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			return;
		}
		$sku = (string) $product->get_sku( 'edit' );
		if ( '' === $sku ) {
			return;
		}
		$record = $this->repo->find( $sku );
		if ( $record ) {
			$this->render_product( (int) $product_id, $record );
		}
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param mixed $skus
	 */
	public function render_skus( $skus ): void {
		foreach ( (array) $skus as $sku ) {
			$this->render_sku( (string) $sku );
		}
	}

	/**
	 * Render (or remove, when the record is gone) the block on every product with this SKU.
	 *
	 * @return int number of products changed
	 */
	public function render_sku( string $sku, ?array $record = null ): int {
		$record  = $record ?? $this->repo->find( $sku );
		$changed = 0;
		foreach ( $this->repo->product_ids_by_sku( $sku ) as $id ) {
			if ( $this->render_product( $id, $record ) ) {
				++$changed;
			}
		}
		return $changed;
	}

	/**
	 * Direct post_content write: no save_post recursion, no revisions, no kses stripping
	 * of our (already escaped) markup.
	 */
	public function render_product( int $product_id, ?array $record ): bool {
		$post = get_post( $product_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return false;
		}
		$lang = $this->labels->post_language( $product_id );
		$this->labels->register_record( $record ); // WPML String Translation (deduped per request)

		// 1) Product meta snapshot: rendered HTML stored on the product, readable by Bricks
		//    ({cf_wnm_nutrition_html}) even when this plugin is deactivated or deleted.
		$meta_changed = $this->write_snapshot( $product_id, $record, $lang );

		// 2) Optional description block.
		$block   = ( $record && $this->fields->writes_description() ) ? $this->renderer->render( $record, $lang ) : '';
		$content = BlockRenderer::merge( (string) $post->post_content, $block );

		if ( null !== $content && $content === $post->post_content ) {
			if ( $meta_changed ) {
				clean_post_cache( $product_id );
				/** This action is documented below. */
				do_action( 'wnm_product_rendered', $product_id, $record );
			}
			return $meta_changed;
		}

		if ( null === $content ) {
			$this->logger->log( 'error', (string) ( $record['sku'] ?? '' ), sprintf( 'Product #%d: unpaired WINE_NUTRITION marker in description; not modified.', $product_id ), 'error' );
			return false;
		}
		if ( $content === $post->post_content ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$wpdb->posts,
			array(
				'post_content'      => $content,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			),
			array( 'ID' => $product_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			$this->logger->log( 'error', (string) ( $record['sku'] ?? '' ), sprintf( 'Product #%d: DB write failed: %s', $product_id, $wpdb->last_error ), 'error' );
			return false;
		}

		clean_post_cache( $product_id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		/**
		 * Fires after a product description was rewritten. Hook page-cache purges here.
		 *
		 * @param int        $product_id
		 * @param array|null $record
		 */
		do_action( 'wnm_product_rendered', $product_id, $record );
		return true;
	}

	/** Register ingredients/allergens of a record in WPML String Translation. */
	public function register_strings( ?array $record ): void {
		$this->labels->register_record( $record );
	}

	/** Compact HTML exactly as stored in / served for the wnm_nutrition_html meta. */
	public function snapshot_html( int $product_id, ?array $record, ?string $lang ): string {
		$html = $record ? $this->renderer->render_compact( $record, $lang ) : '';
		/**
		 * Filters the HTML stored in the wnm_nutrition_html product meta.
		 *
		 * @param string     $html
		 * @param int        $product_id
		 * @param array|null $record
		 */
		return (string) apply_filters( 'wnm_snapshot_html', $html, $product_id, $record );
	}

	/**
	 * Store (or remove) the compact HTML snapshot in product meta.
	 *
	 * @return bool true when the stored value changed
	 */
	/**
	 * Store (or remove) the compact HTML snapshot in product meta.
	 * Raw mode: update_metadata() compares against get_metadata_raw(), which would
	 * otherwise return the live value from Shortcode::live_meta() and skip the write.
	 *
	 * @return bool true when the stored value changed
	 */
	public function write_snapshot( int $product_id, ?array $record, ?string $lang ): bool {
		$html = $this->snapshot_html( $product_id, $record, $lang );
		\WineNutrition\Frontend\Shortcode::$raw_meta = true;
		try {
			$old = (string) get_post_meta( $product_id, self::META_KEY, true );
			if ( $html === $old ) {
				return false;
			}
			if ( '' === $html ) {
				delete_post_meta( $product_id, self::META_KEY );
			} else {
				// wp_slash: update_post_meta unslashes; markup contains quotes.
				update_post_meta( $product_id, self::META_KEY, wp_slash( $html ) );
			}
			return true;
		} finally {
			\WineNutrition\Frontend\Shortcode::$raw_meta = false;
		}
	}

	/**
	 * Queue SKUs for background rendering (falls back to synchronous).
	 *
	 * @param string[] $skus
	 */
	public function queue( array $skus ): int {
		$skus = array_values( array_unique( array_filter( $skus, 'strlen' ) ) );
		if ( ! $skus ) {
			return 0;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->render_skus( $skus );
			return count( $skus );
		}
		foreach ( array_chunk( $skus, self::BATCH ) as $chunk ) {
			as_enqueue_async_action( self::AS_HOOK, array( $chunk ), self::AS_GROUP );
		}
		return count( $skus );
	}

	/** Queue every stored record (after settings/label changes). */
	public function queue_all(): int {
		$total = 0;
		foreach ( $this->repo->sku_chunks( 500 ) as $chunk ) {
			$total += $this->queue( $chunk );
		}
		return $total;
	}

	public function pending(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::AS_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1000,
			),
			'ids'
		);
		return count( $ids ) * self::BATCH;
	}
}
