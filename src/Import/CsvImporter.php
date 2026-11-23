<?php
/**
 * CSV import: upload validation -> analysis/preview -> batched processing -> report.
 *
 * State is kept in a per-user transient keyed by a random token; the file lives in a
 * non-public, randomly named location and is deleted after completion or cancel.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Import;

use WineNutrition\Database\NutritionRepository;
use WineNutrition\Logging\Logger;
use WineNutrition\Services\FieldRegistry;
use WineNutrition\Services\ProductSync;
use WineNutrition\Services\RecordSanitizer;

defined( 'ABSPATH' ) || exit;

final class CsvImporter {

	public const MAX_BYTES  = 20 * MB_IN_BYTES;
	public const BATCH_ROWS = 200;
	private const DIR       = 'wnm-private';
	private const ENCODINGS = array( 'UTF-8', 'Windows-1250', 'Windows-1252' );

	public function __construct(
		private NutritionRepository $repo,
		private RecordSanitizer $sanitizer,
		private FieldRegistry $fields,
		private ProductSync $sync,
		private Logger $logger
	) {}

	// ------------------------------------------------------------------ upload

	/**
	 * Validates and stores the uploaded file, then analyses it.
	 *
	 * @param array $file     Single $_FILES entry.
	 * @param string $encoding Source encoding.
	 * @return string token
	 * @throws \RuntimeException On invalid input.
	 */
	public function accept_upload( array $file, string $encoding ): string {
		if ( ! isset( $file['error'], $file['tmp_name'], $file['name'], $file['size'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			throw new \RuntimeException( __( 'Upload failed.', 'wine-nutrition-manager' ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw new \RuntimeException( __( 'Invalid upload.', 'wine-nutrition-manager' ) );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > (int) apply_filters( 'wnm_import_max_bytes', self::MAX_BYTES ) ) {
			throw new \RuntimeException( __( 'File is empty or too large.', 'wine-nutrition-manager' ) );
		}

		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			throw new \RuntimeException( __( 'Only .csv files are accepted.', 'wine-nutrition-manager' ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], array( 'csv' => 'text/csv', 'txt' => 'text/plain' ) );
		if ( empty( $check['ext'] ) ) {
			throw new \RuntimeException( __( 'File type not allowed.', 'wine-nutrition-manager' ) );
		}
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = (string) finfo_file( $finfo, $file['tmp_name'] );
			finfo_close( $finfo );
			$allowed = array( 'text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/comma-separated-values' );
			if ( ! in_array( $mime, $allowed, true ) ) {
				/* translators: %s: MIME type */
				throw new \RuntimeException( sprintf( __( 'Unexpected file content type: %s', 'wine-nutrition-manager' ), $mime ) );
			}
		}

		$head = (string) file_get_contents( $file['tmp_name'], false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== strpos( $head, "\0" ) ) {
			throw new \RuntimeException( __( 'Binary content detected. Save the file as CSV.', 'wine-nutrition-manager' ) );
		}

		$encoding = in_array( $encoding, self::ENCODINGS, true ) ? $encoding : 'UTF-8';
		$token    = wp_generate_password( 32, false, false );
		$path     = $this->private_dir() . '/' . $token . '.csv';
		if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) {
			throw new \RuntimeException( __( 'Could not store the uploaded file.', 'wine-nutrition-manager' ) );
		}

		try {
			$analysis = $this->analyse( $path, $encoding );
		} catch ( \RuntimeException $e ) {
			wp_delete_file( $path );
			throw $e;
		}

		$this->save_state(
			$token,
			array(
				'path'     => $path,
				'name'     => sanitize_file_name( (string) $file['name'] ),
				'encoding' => $encoding,
				'analysis' => $analysis,
				'status'   => 'analysed',
			)
		);
		return $token;
	}

	// ---------------------------------------------------------------- analysis

	private function analyse( string $path, string $encoding ): array {
		[ $handle, $delimiter, $map, $ignored, $unknown ] = $this->open( $path, $encoding );

		$rows       = 0;
		$skus       = array();
		$duplicates = 0;
		$errors     = array();
		$error_rows = 0;
		$warnings   = 0;
		$line       = 1;

		while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) {
			++$line;
			if ( array( null ) === $cells ) {
				continue; // Blank line.
			}
			++$rows;
			$raw = $this->row_to_raw( $cells, $map, $encoding );
			$sku = RecordSanitizer::sku( $raw['sku'] ?? '' );
			if ( '' === $sku ) {
				++$error_rows;
				$errors[] = sprintf( 'Line %d: missing SKU', $line );
				continue;
			}
			$key = mb_strtolower( $sku );
			if ( isset( $skus[ $key ] ) ) {
				++$duplicates;
			}
			$skus[ $key ] = $sku;
			$result       = $this->sanitizer->sanitize( $raw, false );
			if ( $result['errors'] ) {
				++$error_rows;
				$errors[] = sprintf( 'Line %d (%s): %s', $line, $sku, implode( ' ', $result['errors'] ) );
			}
			$warnings += count( $result['warnings'] );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( 0 === $rows ) {
			throw new \RuntimeException( __( 'The file contains no data rows.', 'wine-nutrition-manager' ) );
		}

		$existing = $this->repo->find_many( array_values( $skus ) );
		$matched  = $this->repo->skus_with_products( array_values( $skus ) );

		$counts = array(
			'matched_new'      => 0,
			'matched_update'   => 0,
			'unmatched_new'    => 0,
			'unmatched_update' => 0,
		);
		$missing = array();
		foreach ( $skus as $key => $sku ) {
			$is_matched = isset( $matched[ $key ] );
			$exists     = isset( $existing[ $key ] );
			$counts[ ( $is_matched ? 'matched_' : 'unmatched_' ) . ( $exists ? 'update' : 'new' ) ]++;
			if ( ! $is_matched && count( $missing ) < 100 ) {
				$missing[] = $sku;
			}
		}

		return array(
			'rows'            => $rows,
			'unique_skus'     => count( $skus ),
			'duplicates'      => $duplicates,
			'matched'         => count( $matched ),
			'not_found'       => count( $skus ) - count( $matched ),
			'counts'          => $counts,
			'missing_sample'  => $missing,
			'error_rows'      => $error_rows,
			'errors_sample'   => array_slice( $errors, 0, 25 ),
			'warnings'        => $warnings,
			'columns'         => array_values( array_unique( array_map( static fn( $m ) => $m['column'], $map ) ) ),
			'ignored_columns' => $ignored,
			'unknown_columns' => $unknown,
			'delimiter'       => $delimiter,
		);
	}

	// -------------------------------------------------------------- processing

	/**
	 * Processes the next batch. Call repeatedly until 'done' is true.
	 *
	 * @throws \RuntimeException On missing state.
	 */
	public function process_batch( string $token, array $options ): array {
		$state = $this->get_state( $token );
		if ( ! $state || ! is_readable( $state['path'] ) ) {
			throw new \RuntimeException( __( 'Import session expired. Upload the file again.', 'wine-nutrition-manager' ) );
		}

		if ( 'analysed' === $state['status'] ) {
			$state['status']  = 'running';
			$state['offset']  = 0;
			$state['line']    = 1;
			$state['run_id']  = 'imp_' . gmdate( 'Ymd_His' ) . '_' . strtolower( wp_generate_password( 6, false, false ) );
			$state['options'] = array(
				'store_unmatched' => ! empty( $options['store_unmatched'] ),
				'empty_clears'    => ! empty( $options['empty_clears'] ),
			);
			$state['report']  = array(
				'created'   => 0,
				'updated'   => 0,
				'unchanged' => 0,
				'skipped'   => 0,
				'errors'    => 0,
				'rendered'  => 0,
			);
			$this->logger->log( 'import', '', sprintf( 'Import started: %s (%d rows)', $state['name'], $state['analysis']['rows'] ), 'info', $state['run_id'] );
		}

		[ $handle, $delimiter, $map ] = $this->open( $state['path'], $state['encoding'] );
		if ( $state['offset'] > 0 ) {
			fseek( $handle, $state['offset'] );
		}

		$user_id  = get_current_user_id();
		$source   = 'import:' . $state['name'];
		$changed  = array();
		$opts     = $state['options'];
		$report   = &$state['report'];
		$run_id   = $state['run_id'];
		$batch    = array();

		for ( $i = 0; $i < self::BATCH_ROWS && false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ); $i++ ) {
			++$state['line'];
			if ( array( null ) === $cells ) {
				continue;
			}
			$batch[] = array( $state['line'], $this->row_to_raw( $cells, $map, $state['encoding'] ) );
		}
		$state['offset'] = ftell( $handle );
		$done            = feof( $handle ) || 0 === count( $batch ) && $i < self::BATCH_ROWS;
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$skus    = array_filter( array_map( static fn( $r ) => RecordSanitizer::sku( $r[1]['sku'] ?? '' ), $batch ), 'strlen' );
		$matched = $opts['store_unmatched'] ? array() : $this->repo->skus_with_products( $skus );

		foreach ( $batch as [ $line, $raw ] ) {
			$sku = RecordSanitizer::sku( $raw['sku'] ?? '' );
			if ( '' === $sku ) {
				++$report['errors'];
				$this->logger->log( 'error', '', sprintf( 'Line %d: missing SKU', $line ), 'error', $run_id );
				continue;
			}
			if ( ! $opts['store_unmatched'] && ! isset( $matched[ mb_strtolower( $sku ) ] ) ) {
				++$report['skipped'];
				$this->logger->log( 'import', $sku, sprintf( 'Line %d: skipped, no product with this SKU in this shop', $line ), 'warning', $run_id );
				continue;
			}
			$result = $this->sanitizer->sanitize( $raw, $opts['empty_clears'] );
			if ( $result['errors'] ) {
				++$report['errors'];
				$this->logger->log( 'error', $sku, sprintf( 'Line %d: %s', $line, implode( ' ', $result['errors'] ) ), 'error', $run_id );
				continue;
			}
			if ( ! $result['data'] ) {
				++$report['unchanged'];
				continue;
			}
			try {
				$outcome = $this->repo->save( $sku, $result['data'], $source, $user_id );
			} catch ( \RuntimeException $e ) {
				++$report['errors'];
				$this->logger->log( 'error', $sku, sprintf( 'Line %d: %s', $line, $e->getMessage() ), 'error', $run_id );
				continue;
			}
			++$report[ $outcome ];
			if ( 'unchanged' !== $outcome ) {
				$changed[] = $sku;
				$details   = 'created' === $outcome ? 'Record created' : 'Record updated';
				if ( $result['warnings'] ) {
					$details .= ' | Warnings: ' . implode( ' ', $result['warnings'] );
				}
				$this->logger->log( 'import', $sku, sprintf( 'Line %d: %s', $line, $details ), $result['warnings'] ? 'warning' : 'info', $run_id );
			}
		}
		unset( $report );

		$state['report']['rendered'] += $this->sync->queue( $changed );

		if ( $done ) {
			$state['status'] = 'done';
			wp_delete_file( $state['path'] );
			$r = $state['report'];
			$this->logger->log(
				'import',
				'',
				sprintf( 'Import finished: created %d, updated %d, unchanged %d, skipped %d, errors %d', $r['created'], $r['updated'], $r['unchanged'], $r['skipped'], $r['errors'] ),
				$r['errors'] ? 'warning' : 'info',
				$run_id
			);
		}
		$this->save_state( $token, $state );

		return array(
			'done'      => $done,
			'processed' => $state['line'] - 1,
			'total'     => (int) $state['analysis']['rows'],
			'report'    => $state['report'],
			'run_id'    => $run_id,
		);
	}

	public function cancel( string $token ): void {
		$state = $this->get_state( $token );
		if ( $state && ! empty( $state['path'] ) && file_exists( $state['path'] ) ) {
			wp_delete_file( $state['path'] );
		}
		delete_transient( $this->state_key( $token ) );
	}

	public function get_state( string $token ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return null;
		}
		$state = get_transient( $this->state_key( $token ) );
		return is_array( $state ) ? $state : null;
	}

	/** Removes abandoned upload files older than one day. */
	public function cleanup(): void {
		$dir = $this->private_dir();
		foreach ( (array) glob( $dir . '/*.csv' ) as $file ) {
			if ( is_string( $file ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}

	// ----------------------------------------------------------------- helpers

	/**
	 * Opens the file, detects delimiter, maps header columns.
	 *
	 * @return array{0:resource,1:string,2:array,3:string[],4:string[]}
	 * @throws \RuntimeException On unreadable file or missing SKU column.
	 */
	private function open( string $path, string $encoding ): array {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			throw new \RuntimeException( __( 'Cannot read the import file.', 'wine-nutrition-manager' ) );
		}
		$first = (string) fgets( $handle );
		$bom   = 0 === strncmp( $first, "\xEF\xBB\xBF", 3 );
		if ( $bom ) {
			$first = substr( $first, 3 );
		}
		if ( 'UTF-8' === $encoding && ! mb_check_encoding( $first, 'UTF-8' ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new \RuntimeException( __( 'The file is not valid UTF-8. Select the correct encoding (e.g. Windows-1250 for Excel CZ/SK).', 'wine-nutrition-manager' ) );
		}

		$counts = array(
			','  => substr_count( $first, ',' ),
			';'  => substr_count( $first, ';' ),
			"\t" => substr_count( $first, "\t" ),
		);
		arsort( $counts );
		$delimiter = (string) array_key_first( $counts );

		$header = str_getcsv( rtrim( $first, "\r\n" ), $delimiter, '"', '' );
		$known  = array_merge( array( 'sku', 'custom_fields' ), FieldRegistry::NUMERIC_COLUMNS, FieldRegistry::TEXT_COLUMNS );
		$active = array_merge( array( 'sku', 'custom_fields' ), $this->fields->enabled_columns(), $this->fields->text_columns() );
		$custom = $this->fields->custom_keys();
		$all_custom = array_column( $this->fields->custom_definitions( false ), 'key' );

		$map     = array();
		$ignored = array();
		$unknown = array();
		foreach ( $header as $index => $name ) {
			$name = strtolower( trim( $this->convert( (string) $name, $encoding ) ) );
			if ( '' === $name || in_array( $name, array( 'updated_at', 'product_name' ), true ) ) {
				continue;
			}
			if ( str_starts_with( $name, 'cf_' ) ) {
				$key = sanitize_key( substr( $name, 3 ) );
				if ( in_array( $key, $custom, true ) ) {
					$map[ $index ] = array( 'column' => $name, 'custom' => $key );
				} elseif ( in_array( $key, $all_custom, true ) ) {
					$ignored[] = $name;
				} else {
					$unknown[] = $name;
				}
				continue;
			}
			if ( in_array( $name, $active, true ) ) {
				$map[ $index ] = array( 'column' => $name, 'custom' => null );
			} elseif ( in_array( $name, $known, true ) ) {
				$ignored[] = $name; // Disabled standard field.
			} else {
				$unknown[] = $name;
			}
		}

		if ( ! in_array( 'sku', array_column( $map, 'column' ), true ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new \RuntimeException( __( 'Required column "sku" not found in the header row.', 'wine-nutrition-manager' ) );
		}

		return array( $handle, $delimiter, $map, $ignored, $unknown );
	}

	private function row_to_raw( array $cells, array $map, string $encoding ): array {
		$raw = array();
		foreach ( $map as $index => $def ) {
			$value = $this->convert( (string) ( $cells[ $index ] ?? '' ), $encoding );
			if ( null !== $def['custom'] ) {
				$raw['custom_fields'][ $def['custom'] ] = $value;
			} elseif ( 'custom_fields' === $def['column'] ) {
				foreach ( $this->parse_custom_json( $value ) as $key => $val ) {
					$raw['custom_fields'][ $key ] = $raw['custom_fields'][ $key ] ?? $val;
				}
			} else {
				$raw[ $def['column'] ] = $value;
			}
		}
		return $raw;
	}

	/**
	 * Accepts {"fiber":"2.1 g"} or [{"label":"Fiber","value":"2.1 g"}] (labels matched to keys).
	 *
	 * @return array<string,string>
	 */
	private function parse_custom_json( string $json ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$by_label = array();
		foreach ( $this->fields->custom_definitions() as $def ) {
			$by_label[ mb_strtolower( $def['label'] ) ] = $def['key'];
		}
		$out = array();
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) && isset( $v['label'], $v['value'] ) ) {
				$key = $by_label[ mb_strtolower( (string) $v['label'] ) ] ?? sanitize_key( (string) $v['label'] );
				$out[ $key ] = (string) $v['value'];
			} elseif ( is_string( $k ) && is_scalar( $v ) ) {
				$out[ sanitize_key( $k ) ] = (string) $v;
			}
		}
		return $out;
	}

	private function convert( string $value, string $encoding ): string {
		if ( 'UTF-8' === $encoding || '' === $value ) {
			return $value;
		}
		$converted = function_exists( 'iconv' ) ? iconv( $encoding, 'UTF-8//IGNORE', $value ) : mb_convert_encoding( $value, 'UTF-8', $encoding );
		return false === $converted ? '' : $converted;
	}

	private function private_dir(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	private function state_key( string $token ): string {
		return 'wnm_imp_' . get_current_user_id() . '_' . $token;
	}

	private function save_state( string $token, array $state ): void {
		set_transient( $this->state_key( $token ), $state, DAY_IN_SECONDS );
	}
}
