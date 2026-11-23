<?php
/**
 * Audit log: imports, exports, updates, deletions, renders and errors.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Logging;

use WineNutrition\Database\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Logger {

	public const ACTIONS = array( 'import', 'export', 'update', 'create', 'delete', 'render', 'settings', 'error' );

	public function log( string $action, string $sku = '', string $details = '', string $level = 'info', string $run_id = '' ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::log_table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'user_id'    => get_current_user_id(),
				'action'     => mb_substr( $action, 0, 32 ),
				'level'      => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
				'sku'        => mb_substr( $sku, 0, 191 ),
				'run_id'     => mb_substr( $run_id, 0, 64 ),
				'details'    => mb_substr( $details, 0, 5000 ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @return array{rows:array,total:int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$args     = wp_parse_args(
			$args,
			array(
				'action'   => '',
				'level'    => '',
				'sku'      => '',
				'run_id'   => '',
				'page'     => 1,
				'per_page' => 50,
			)
		);
		$where    = array( '1=1' );
		$params   = array();
		if ( in_array( $args['action'], self::ACTIONS, true ) ) {
			$where[]  = 'action = %s';
			$params[] = $args['action'];
		}
		if ( in_array( $args['level'], array( 'info', 'warning', 'error' ), true ) ) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}
		if ( '' !== $args['sku'] ) {
			$where[]  = 'sku LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['sku'] ) . '%';
		}
		if ( '' !== $args['run_id'] ) {
			$where[]  = 'run_id = %s';
			$params[] = $args['run_id'];
		}
		$where_sql = implode( ' AND ', $where );
		$table     = Schema::log_table();
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where_sql", $params ) )
			: $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) );

		$per_page = max( 1, min( 5000, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$rows     = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ),
			ARRAY_A
		);
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Streams matching log rows (for CSV download) without loading everything.
	 *
	 * @return \Generator<array>
	 */
	public function iterate( array $args ): \Generator {
		$page = 1;
		do {
			$result = $this->query( array_merge( $args, array( 'page' => $page++, 'per_page' => 1000 ) ) );
			foreach ( $result['rows'] as $row ) {
				yield $row;
			}
		} while ( count( $result['rows'] ) === 1000 );
	}

	public function prune( int $days ): void {
		global $wpdb;
		if ( $days < 1 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::log_table() . ' WHERE created_at < %s LIMIT 10000', $cutoff ) );
	}
}
// phpcs:enable
