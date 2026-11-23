<?php
/**
 * Authenticated AJAX endpoints for the dashboard and importer.
 * Every handler: nonce check + capability check before any work.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Admin;

use WineNutrition\Database\NutritionRepository;
use WineNutrition\Import\CsvImporter;
use WineNutrition\Logging\Logger;
use WineNutrition\Plugin;
use WineNutrition\Services\FieldRegistry;
use WineNutrition\Services\ProductSync;
use WineNutrition\Services\RecordSanitizer;

defined( 'ABSPATH' ) || exit;

final class AjaxController {

	public const NONCE = 'wnm_admin';

	public function __construct(
		private NutritionRepository $repo,
		private RecordSanitizer $sanitizer,
		private FieldRegistry $fields,
		private ProductSync $sync,
		private CsvImporter $importer,
		private Logger $logger
	) {}

	public function register(): void {
		foreach ( array( 'list', 'save', 'delete', 'stats', 'import_batch' ) as $action ) {
			add_action( 'wp_ajax_wnm_' . $action, array( $this, 'handle_' . $action ) );
			// No wp_ajax_nopriv_ hooks: anonymous access is impossible by design.
		}
	}

	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wine-nutrition-manager' ) ), 403 );
		}
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().

	public function handle_list(): void {
		$this->guard();
		$args   = array(
			'search'      => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ),
			'category'    => absint( $_POST['category'] ?? 0 ),
			'status'      => sanitize_key( $_POST['status'] ?? '' ),
			'qr'          => sanitize_key( $_POST['qr'] ?? '' ),
			'ingredients' => sanitize_key( $_POST['ingredients'] ?? '' ),
			'orderby'     => sanitize_key( $_POST['orderby'] ?? 'title' ),
			'order'       => sanitize_key( $_POST['order'] ?? 'asc' ),
			'page'        => max( 1, absint( $_POST['page'] ?? 1 ) ),
			'per_page'    => max( 10, min( 200, absint( $_POST['per_page'] ?? 50 ) ) ),
		);
		$result = $this->repo->list_products( $args );

		$ids = array_map( 'intval', array_column( $result['rows'], 'product_id' ) );
		if ( $ids ) {
			update_object_term_cache( $ids, 'product' );
		}
		$rows = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = $this->present( $row );
		}
		wp_send_json_success(
			array(
				'rows'  => $rows,
				'total' => $result['total'],
				'pages' => (int) ceil( $result['total'] / $args['per_page'] ),
				'page'  => $args['page'],
			)
		);
	}

	public function handle_save(): void {
		$this->guard();
		$sku = RecordSanitizer::sku( $_POST['sku'] ?? '' );
		if ( '' === $sku ) {
			wp_send_json_error( array( 'message' => __( 'Missing SKU.', 'wine-nutrition-manager' ) ), 400 );
		}
		$raw    = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by RecordSanitizer.
		$raw    = array_intersect_key( $raw, array_flip( array_merge( $this->fields->enabled_columns(), $this->fields->text_columns(), array( 'custom_fields' ) ) ) );
		$result = $this->sanitizer->sanitize( $raw, true ); // Editor sends full form: empty clears.
		if ( $result['errors'] ) {
			wp_send_json_error( array( 'message' => implode( ' ', $result['errors'] ) ), 422 );
		}
		try {
			$outcome = $this->repo->save( $sku, $result['data'], 'admin', get_current_user_id() );
		} catch ( \RuntimeException $e ) {
			$this->logger->log( 'error', $sku, $e->getMessage(), 'error' );
			wp_send_json_error( array( 'message' => __( 'Database error. See logs.', 'wine-nutrition-manager' ) ), 500 );
		}

		$rendered = 0;
		if ( 'unchanged' !== $outcome ) {
			$this->logger->log( 'created' === $outcome ? 'create' : 'update', $sku, 'Saved in dashboard' . ( $result['warnings'] ? ' | Warnings: ' . implode( ' ', $result['warnings'] ) : '' ) );
			$rendered = $this->sync->render_sku( $sku );
		}

		$record = $this->repo->find( $sku );
		$this->sync->register_strings( $record ); // available in String Translation right away
		wp_send_json_success(
			array(
				'outcome'  => $outcome,
				'rendered' => $rendered,
				'warnings' => $result['warnings'],
				'record'   => $this->present_record( $record ),
			)
		);
	}

	public function handle_delete(): void {
		$this->guard();
		$sku = RecordSanitizer::sku( $_POST['sku'] ?? '' );
		if ( '' === $sku || ! $this->repo->find( $sku ) ) {
			wp_send_json_error( array( 'message' => __( 'Record not found.', 'wine-nutrition-manager' ) ), 404 );
		}
		$this->repo->delete( $sku );
		$removed = $this->sync->render_sku( $sku, null ); // Removes the block from descriptions.
		$this->logger->log( 'delete', $sku, sprintf( 'Record deleted; block removed from %d product(s)', $removed ) );
		wp_send_json_success( array( 'removed' => $removed ) );
	}

	public function handle_stats(): void {
		$this->guard();
		wp_send_json_success( array_merge( $this->repo->stats(), array( 'pending_renders' => $this->sync->pending() ) ) );
	}

	public function handle_import_batch(): void {
		$this->guard();
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		try {
			$result = $this->importer->process_batch(
				$token,
				array(
					'store_unmatched' => ! empty( $_POST['store_unmatched'] ),
					'empty_clears'    => ! empty( $_POST['empty_clears'] ),
				)
			);
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}
		wp_send_json_success( $result );
	}

	// phpcs:enable

	private function present( array $row ): array {
		$terms = get_the_terms( (int) $row['product_id'], 'product_cat' );
		$cats  = is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
		$rec   = $row['record_id'] ? $row : null;

		return array(
			'product_id' => (int) $row['product_id'],
			'title'      => html_entity_decode( (string) $row['post_title'], ENT_QUOTES, 'UTF-8' ),
			'status'     => (string) $row['post_status'],
			'edit_url'   => get_edit_post_link( (int) $row['product_id'], 'raw' ),
			'sku'        => (string) $row['sku'],
			'categories' => html_entity_decode( $cats, ENT_QUOTES, 'UTF-8' ),
			'record'     => $this->present_record( $rec ),
		);
	}

	private function present_record( ?array $rec ): ?array {
		if ( ! $rec ) {
			return null;
		}
		$values = array();
		foreach ( array_merge( FieldRegistry::NUMERIC_COLUMNS, FieldRegistry::TEXT_COLUMNS ) as $col ) {
			$values[ $col ] = $rec[ $col ] ?? null;
			if ( null !== $values[ $col ] && in_array( $col, FieldRegistry::NUMERIC_COLUMNS, true ) ) {
				$op             = (string) ( $rec['qualifiers'][ $col ] ?? '' );
				$values[ $col ] = ( '' === $op ? '' : $op . ' ' ) . rtrim( rtrim( (string) $values[ $col ], '0' ), '.' );
			}
		}
		$values['custom_fields'] = (object) ( $rec['custom_fields'] ?? array() );

		return array(
			'values'          => $values,
			'nutrition_ok'    => $this->repo->has_nutrition( $rec ),
			'ingredients_ok'  => '' !== trim( (string) ( $rec['ingredients'] ?? '' ) ),
			'qr_ok'           => '' !== trim( (string) ( $rec['qr_url'] ?? '' ) ),
			'complete'        => $this->repo->is_complete( $rec ),
			'updated_at'      => $rec['updated_at'] ? get_date_from_gmt( $rec['updated_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
		);
	}
}
