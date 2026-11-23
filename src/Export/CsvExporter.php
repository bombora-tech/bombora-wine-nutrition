<?php
/**
 * Streams nutrition records (never product data) as CSV.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Export;

use WineNutrition\Database\NutritionRepository;
use WineNutrition\Logging\Logger;
use WineNutrition\Services\FieldRegistry;

defined( 'ABSPATH' ) || exit;

final class CsvExporter {

	public function __construct(
		private NutritionRepository $repo,
		private FieldRegistry $fields,
		private Logger $logger
	) {}

	/** @return string[] */
	public function columns(): array {
		return array_merge(
			array( 'sku' ),
			$this->fields->enabled_columns(),
			$this->fields->text_columns(),
			array_map( static fn( $k ) => 'cf_' . $k, $this->fields->custom_keys() ),
			array( 'updated_at' )
		);
	}

	/**
	 * Sends headers and streams the file. Caller must exit afterwards.
	 *
	 * @param string|null $since_local 'Y-m-d' in site timezone, or null for all.
	 */
	public function stream( ?string $since_local, string $delimiter, bool $bom ): void {
		$since_gmt = $since_local ? get_gmt_from_date( $since_local . ' 00:00:00' ) : null;
		$columns   = $this->columns();
		$custom    = $this->fields->custom_keys();
		$host      = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$filename  = sprintf( 'wine-nutrition-%s-%s%s.csv', $host, gmdate( 'Ymd-His' ), $since_local ? '-since-' . $since_local : '' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $bom ) {
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		fputcsv( $out, $columns, $delimiter, '"', '' );

		$count = 0;
		foreach ( $this->repo->iterate( $since_gmt ) as $record ) {
			$line = array();
			foreach ( $columns as $col ) {
				if ( str_starts_with( $col, 'cf_' ) && in_array( substr( $col, 3 ), $custom, true ) ) {
					$value = (string) ( $record['custom_fields'][ substr( $col, 3 ) ] ?? '' );
				} elseif ( 'updated_at' === $col ) {
					$value = $record['updated_at'] ? get_date_from_gmt( $record['updated_at'], 'Y-m-d H:i:s' ) : '';
				} elseif ( in_array( $col, FieldRegistry::NUMERIC_COLUMNS, true ) ) {
					$value = null === $record[ $col ] ? '' : ( $record['qualifiers'][ $col ] ?? '' ) . rtrim( rtrim( (string) $record[ $col ], '0' ), '.' );
				} else {
					$value = (string) ( $record[ $col ] ?? '' );
				}
				$line[] = self::guard( $value );
			}
			fputcsv( $out, $line, $delimiter, '"', '' );
			if ( 0 === ++$count % 500 ) {
				flush();
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$this->logger->log( 'export', '', sprintf( 'Exported %d records%s', $count, $since_local ? ' changed since ' . $since_local : '' ) );
	}

	/** CSV/formula injection guard (OWASP): prefix risky leading characters. */
	public static function guard( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
