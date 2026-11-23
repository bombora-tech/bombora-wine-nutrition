<?php
/**
 * Data access for nutrition records and the SKU <-> product mapping.
 *
 * All dynamic SQL fragments (column names, ORDER BY) come from internal whitelists;
 * every user-supplied value goes through $wpdb->prepare().
 *
 * @package WineNutrition
 */

namespace WineNutrition\Database;

use WineNutrition\Services\FieldRegistry;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class NutritionRepository {

	private const CHUNK = 500;

	public function __construct( private FieldRegistry $fields ) {}

	public function find( string $sku ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE sku = %s', $sku ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * @param string[] $skus
	 * @return array<string,array> keyed by lower-cased SKU.
	 */
	public function find_many( array $skus ): array {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( $skus ) ), self::CHUNK ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . " WHERE sku IN ($in)", $chunk ), ARRAY_A );
			foreach ( $rows as $row ) {
				$out[ mb_strtolower( $row['sku'] ) ] = $this->hydrate( $row );
			}
		}
		return $out;
	}

	/**
	 * SKUs (lower-cased) that exist as WooCommerce products in this shop.
	 *
	 * @param string[] $skus
	 * @return array<string,true>
	 */
	public function skus_with_products( array $skus ): array {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( $skus ) ), self::CHUNK ) as $chunk ) {
			$in    = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$found = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT l.sku FROM ' . Schema::lookup_table() . " l
					INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
					WHERE l.sku IN ($in) AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')",
					$chunk
				)
			);
			foreach ( $found as $sku ) {
				$out[ mb_strtolower( $sku ) ] = true;
			}
		}
		return $out;
	}

	/**
	 * All product posts (every WPML language) carrying this SKU.
	 *
	 * @return int[]
	 */
	public function product_ids_by_sku( string $sku ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT l.product_id FROM ' . Schema::lookup_table() . " l
				INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
				WHERE l.sku = %s AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')",
				$sku
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Insert or update. Only columns present in $data are written; custom fields are merged.
	 *
	 * @return string created|updated|unchanged
	 */
	public function save( string $sku, array $data, string $source, int $user_id ): string {
		global $wpdb;
		$existing = $this->find( $sku );
		$now      = current_time( 'mysql', true );

		if ( isset( $data['custom_fields'] ) ) {
			$merged = array_merge( $existing['custom_fields'] ?? array(), $data['custom_fields'] );
			$merged = array_filter( $merged, static fn( $v ) => '' !== (string) $v );
			ksort( $merged );
			$data['custom_fields'] = $merged ? wp_json_encode( $merged, JSON_UNESCAPED_UNICODE ) : null;
		}

		if ( isset( $data['qualifiers'] ) ) {
			$merged = array_merge( $existing['qualifiers'] ?? array(), $data['qualifiers'] );
			$merged = array_filter( $merged, static fn( $v ) => in_array( $v, FieldRegistry::QUALIFIERS, true ) );
			ksort( $merged );
			$data['qualifiers'] = $merged ? wp_json_encode( $merged, JSON_UNESCAPED_UNICODE ) : null;
		}

		if ( ! $existing ) {
			$row = array_merge(
				array( 'sku' => $sku ),
				$data,
				array(
					'source'     => mb_substr( $source, 0, 255 ),
					'updated_by' => $user_id,
					'updated_at' => $now,
					'created_at' => $now,
				)
			);
			$ok = $wpdb->insert( Schema::table(), $row, $this->formats( $row ) );
			if ( false === $ok ) {
				throw new \RuntimeException( 'DB insert failed: ' . $wpdb->last_error );
			}
			$this->flush();
			return 'created';
		}

		$changes = $this->diff( $existing, $data );
		if ( ! $changes ) {
			return 'unchanged';
		}
		$changes += array(
			'source'     => mb_substr( $source, 0, 255 ),
			'updated_by' => $user_id,
			'updated_at' => $now,
		);
		$ok = $wpdb->update( Schema::table(), $changes, array( 'id' => (int) $existing['id'] ), $this->formats( $changes ), array( '%d' ) );
		if ( false === $ok ) {
			throw new \RuntimeException( 'DB update failed: ' . $wpdb->last_error );
		}
		$this->flush();
		return 'updated';
	}

	public function delete( string $sku ): bool {
		global $wpdb;
		$ok = (bool) $wpdb->delete( Schema::table(), array( 'sku' => $sku ), array( '%s' ) );
		$this->flush();
		return $ok;
	}

	/**
	 * Paginated dashboard listing: WooCommerce products (original language) LEFT JOIN nutrition.
	 *
	 * @return array{rows:array,total:int}
	 */
	public function list_products( array $args ): array {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'search'      => '',
				'category'    => 0,
				'status'      => '',
				'qr'          => '',
				'ingredients' => '',
				'orderby'     => 'title',
				'order'       => 'ASC',
				'page'        => 1,
				'per_page'    => 50,
			)
		);

		[ $from, $where, $params ] = $this->product_scope();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(l.sku LIKE %s OR p.post_title LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( $args['category'] ) {
			$term_ids = array_merge( array( (int) $args['category'] ), array_map( 'intval', (array) get_term_children( (int) $args['category'], 'product_cat' ) ) );
			$in       = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_cat' AND tt.term_id IN ($in))";
			$params   = array_merge( $params, $term_ids );
		}

		$complete = $this->complete_sql();
		$where[]  = match ( $args['status'] ) {
			'complete'   => "($complete)",
			'incomplete' => "NOT ($complete)",
			'no_record'  => 'n.id IS NULL',
			default      => '1=1',
		};
		$where[]  = match ( $args['qr'] ) {
			'has'     => "(n.qr_url IS NOT NULL AND n.qr_url <> '')",
			'missing' => "(n.qr_url IS NULL OR n.qr_url = '')",
			default   => '1=1',
		};
		$where[]  = match ( $args['ingredients'] ) {
			'has'     => "(n.ingredients IS NOT NULL AND n.ingredients <> '')",
			'missing' => "(n.ingredients IS NULL OR n.ingredients = '')",
			default   => '1=1',
		};

		$orderby = match ( $args['orderby'] ) {
			'sku'        => 'l.sku',
			'updated_at' => 'n.updated_at',
			default      => 'p.post_title',
		};
		$order    = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM $from WHERE $where_sql";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$select = 'p.ID AS product_id, p.post_title, p.post_status, l.sku, n.id AS record_id, '
			. implode( ', ', array_map( static fn( $c ) => "n.$c", array_merge( FieldRegistry::NUMERIC_COLUMNS, FieldRegistry::TEXT_COLUMNS ) ) )
			. ', n.qualifiers, n.custom_fields, n.updated_at, n.updated_by';
		$sql    = "SELECT $select FROM $from WHERE $where_sql ORDER BY $orderby $order, p.ID ASC LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );

		foreach ( $rows as &$row ) {
			$row['custom_fields'] = $this->decode_custom( $row['custom_fields'] ?? null );
			$row['qualifiers']    = $this->decode_custom( $row['qualifiers'] ?? null );
		}
		unset( $row );

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Compliance summary. Cached for 10 minutes, flushed on every write.
	 */
	public function stats(): array {
		$cached = get_transient( 'wnm_stats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		[ $from, $where, $params ] = $this->product_scope( false );
		$complete  = $this->complete_sql();
		$nutrition = $this->nutrition_complete_sql();
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT
			SUM(l.sku <> '') AS total,
			SUM(l.sku = '') AS without_sku,
			SUM(l.sku <> '' AND ($complete)) AS completed,
			SUM(l.sku <> '' AND NOT ($nutrition)) AS missing_nutrition,
			SUM(l.sku <> '' AND (n.ingredients IS NULL OR n.ingredients = '')) AS missing_ingredients,
			SUM(l.sku <> '' AND (n.qr_url IS NULL OR n.qr_url = '')) AS missing_qr
			FROM $from WHERE $where_sql";
		$row = $params ? $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_row( $sql, ARRAY_A );

		$stats = array_map( 'intval', (array) $row );

		$stats['records']          = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table() );
		$stats['orphaned_records'] = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . Schema::table() . ' n WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::lookup_table() . ' l WHERE l.sku = n.sku)'
		);

		set_transient( 'wnm_stats', $stats, 10 * MINUTE_IN_SECONDS );
		return $stats;
	}

	/**
	 * Streams records in chunks (keyset pagination, constant memory).
	 *
	 * @param string|null $since_gmt 'Y-m-d H:i:s' GMT or null for all.
	 * @return \Generator<array>
	 */
	public function iterate( ?string $since_gmt = null, int $chunk = 1000 ): \Generator {
		global $wpdb;
		$last_id = 0;
		do {
			if ( $since_gmt ) {
				$sql = $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE id > %d AND updated_at >= %s ORDER BY id ASC LIMIT %d', $last_id, $since_gmt, $chunk );
			} else {
				$sql = $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE id > %d ORDER BY id ASC LIMIT %d', $last_id, $chunk );
			}
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				yield $this->hydrate( $row );
			}
		} while ( count( $rows ) === $chunk );
	}

	/** @return \Generator<string[]> chunks of SKUs. */
	public function sku_chunks( int $size = 25 ): \Generator {
		$buffer = array();
		foreach ( $this->iterate() as $record ) {
			$buffer[] = $record['sku'];
			if ( count( $buffer ) >= $size ) {
				yield $buffer;
				$buffer = array();
			}
		}
		if ( $buffer ) {
			yield $buffer;
		}
	}

	public function is_complete( ?array $record ): bool {
		if ( ! $record ) {
			return false;
		}
		return $this->has_nutrition( $record )
			&& ( ! $this->fields->text_enabled( 'ingredients' ) || '' !== trim( (string) ( $record['ingredients'] ?? '' ) ) );
	}

	public function has_nutrition( ?array $record ): bool {
		if ( ! $record ) {
			return false;
		}
		foreach ( $this->fields->enabled_columns() as $col ) {
			if ( null === ( $record[ $col ] ?? null ) ) {
				return false;
			}
		}
		return true;
	}

	public function flush(): void {
		delete_transient( 'wnm_stats' );
	}

	// ---------------------------------------------------------------------

	/**
	 * FROM clause + base conditions: products with SKU, original WPML language only.
	 *
	 * @return array{0:string,1:string[],2:array}
	 */
	private function product_scope( bool $require_sku = true ): array {
		global $wpdb;
		$from   = "{$wpdb->posts} p INNER JOIN " . Schema::lookup_table() . ' l ON l.product_id = p.ID LEFT JOIN ' . Schema::table() . ' n ON n.sku = l.sku';
		$where  = array( "p.post_type = 'product'", "p.post_status NOT IN ('trash','auto-draft')" );
		$params = array();
		if ( $require_sku ) {
			$where[] = "l.sku <> ''";
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			// One row per translation group: the original (source_language_code IS NULL).
			$from   .= " INNER JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID AND t.element_type = 'post_product'";
			$where[] = 't.source_language_code IS NULL';
		}
		return array( $from, $where, $params );
	}

	private function nutrition_complete_sql(): string {
		$cols = $this->fields->enabled_columns(); // Whitelisted column names.
		if ( ! $cols ) {
			return 'n.id IS NOT NULL';
		}
		return 'n.id IS NOT NULL AND ' . implode( ' AND ', array_map( static fn( $c ) => "n.$c IS NOT NULL", $cols ) );
	}

	private function complete_sql(): string {
		return $this->nutrition_complete_sql()
			. ( $this->fields->text_enabled( 'ingredients' ) ? " AND n.ingredients IS NOT NULL AND n.ingredients <> ''" : '' );
	}

	private function hydrate( array $row ): array {
		$row['custom_fields'] = $this->decode_custom( $row['custom_fields'] ?? null );
		$row['qualifiers']    = $this->decode_custom( $row['qualifiers'] ?? null );
		return $row;
	}

	private function decode_custom( ?string $json ): array {
		if ( ! $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	private function diff( array $existing, array $data ): array {
		$changes = array();
		foreach ( $data as $col => $value ) {
			$old = $existing[ $col ] ?? null;
			if ( 'custom_fields' === $col || 'qualifiers' === $col ) {
				$old = $old ? wp_json_encode( $old, JSON_UNESCAPED_UNICODE ) : null;
			}
			if ( in_array( $col, FieldRegistry::NUMERIC_COLUMNS, true ) ) {
				$same = ( null === $old && null === $value ) || ( null !== $old && null !== $value && abs( (float) $old - (float) $value ) < 0.001 );
			} else {
				$same = (string) $old === (string) $value && ( null === $old ) === ( null === $value );
			}
			if ( ! $same ) {
				$changes[ $col ] = $value;
			}
		}
		return $changes;
	}

	private function formats( array $row ): array {
		$formats = array();
		foreach ( array_keys( $row ) as $col ) {
			$formats[] = match ( true ) {
				in_array( $col, FieldRegistry::NUMERIC_COLUMNS, true ) => '%f',
				'updated_by' === $col => '%d',
				default => '%s',
			};
		}
		return $formats;
	}
}
// phpcs:enable
