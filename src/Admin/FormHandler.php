<?php
/**
 * admin-post.php handlers: settings, upload, cancel, export, log download, regenerate.
 * Each handler verifies capability and nonce (check_admin_referer) first.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Admin;

use WineNutrition\Export\CsvExporter;
use WineNutrition\Import\CsvImporter;
use WineNutrition\Logging\Logger;
use WineNutrition\Plugin;
use WineNutrition\Services\FieldRegistry;
use WineNutrition\Services\ProductSync;

defined( 'ABSPATH' ) || exit;

final class FormHandler {

	public function __construct(
		private FieldRegistry $fields,
		private CsvImporter $importer,
		private CsvExporter $exporter,
		private ProductSync $sync,
		private Logger $logger
	) {}

	public function register(): void {
		foreach ( array( 'save_settings', 'import_upload', 'import_cancel', 'export', 'download_log', 'regenerate' ) as $action ) {
			add_action( 'admin_post_wnm_' . $action, array( $this, $action ) );
		}
	}

	private function authorize( string $nonce_action ): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wine-nutrition-manager' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private function back( string $tab, array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => AdminPage::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- verified in authorize().

	public function save_settings(): void {
		$this->authorize( 'wnm_save_settings' );
		$before = $this->fields->render_fingerprint();

		$standard = array_map( 'sanitize_key', (array) wp_unslash( $_POST['standard'] ?? array() ) );
		update_option( FieldRegistry::OPTION_ENABLED, array_values( array_intersect( array_keys( FieldRegistry::standard() ), $standard ) ), false );

		$defs = array();
		$seen = array();
		foreach ( (array) wp_unslash( $_POST['custom'] ?? array() ) as $def ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
			if ( ! is_array( $def ) || ! empty( $def['remove'] ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $def['label'] ?? '' ) );
			$key   = sanitize_key( (string) ( $def['key'] ?? '' ) );
			if ( '' === $key ) {
				$key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );
			}
			if ( '' === $label || '' === $key || isset( $seen[ $key ] ) || in_array( $key, FieldRegistry::NUMERIC_COLUMNS, true ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$defs[]       = array(
				'key'     => mb_substr( $key, 0, 40 ),
				'label'   => mb_substr( $label, 0, 100 ),
				'enabled' => ! empty( $def['enabled'] ),
			);
		}
		update_option( FieldRegistry::OPTION_CUSTOM, $defs, false );

		$labels = array();
		foreach ( FieldRegistry::default_labels() as $key => $default ) {
			$value          = sanitize_text_field( wp_unslash( $_POST['labels'][ $key ] ?? '' ) );
			$labels[ $key ] = '' === $value ? $default : mb_substr( $value, 0, 150 );
		}
		update_option( FieldRegistry::OPTION_LABELS, $labels, false );

		$mode     = sanitize_key( wp_unslash( $_POST['settings']['output_mode'] ?? 'shortcode' ) );
		$settings = array(
			'output_mode'              => in_array( $mode, FieldRegistry::OUTPUT_MODES, true ) ? $mode : 'shortcode',
			'compact_layout'           => 'inline' === sanitize_key( wp_unslash( $_POST['settings']['compact_layout'] ?? '' ) ) ? 'inline' : 'table',
			'compact_allergens'        => empty( $_POST['settings']['compact_allergens'] ) ? 0 : 1,
			'show_elabel_link'         => empty( $_POST['settings']['show_elabel_link'] ) ? 0 : 1,
			'use_ingredients'          => empty( $_POST['settings']['use_ingredients'] ) ? 0 : 1,
			'use_allergens'            => empty( $_POST['settings']['use_allergens'] ) ? 0 : 1,
			'use_qr_url'               => empty( $_POST['settings']['use_qr_url'] ) ? 0 : 1,
			'show_qr_image'            => empty( $_POST['settings']['show_qr_image'] ) ? 0 : 1,
			'qr_size'                  => max( 60, min( 300, absint( $_POST['settings']['qr_size'] ?? 110 ) ) ),
			'translate_ingredients'    => empty( $_POST['settings']['translate_ingredients'] ) ? 0 : 1,
			'eu_rounding'              => empty( $_POST['settings']['eu_rounding'] ) ? 0 : 1,
			'eu_negligible'            => 'less_than' === sanitize_key( wp_unslash( $_POST['settings']['eu_negligible'] ?? '' ) ) ? 'less_than' : 'zero',
			'emphasize_allergens'      => empty( $_POST['settings']['emphasize_allergens'] ) ? 0 : 1,
			'render_on_product_save'   => empty( $_POST['settings']['render_on_product_save'] ) ? 0 : 1,
			'log_retention_days'       => max( 0, min( 3650, absint( $_POST['settings']['log_retention_days'] ?? 180 ) ) ),
		);
		update_option( FieldRegistry::OPTION_SETTINGS, $settings, true );

		delete_transient( 'wnm_stats' );
		$queued = 0;
		if ( $before !== $this->fields->render_fingerprint() ) {
			$queued = $this->sync->queue_all();
		}
		$this->logger->log( 'settings', '', 'Settings saved' . ( $queued ? sprintf( '; %d SKU(s) queued for re-render', $queued ) : '' ) );
		$this->back( 'settings', array( 'saved' => 1, 'queued' => $queued ) );
	}

	public function import_upload(): void {
		$this->authorize( 'wnm_import_upload' );
		$encoding = sanitize_text_field( wp_unslash( $_POST['encoding'] ?? 'UTF-8' ) );
		try {
			$file  = isset( $_FILES['wnm_csv'] ) && is_array( $_FILES['wnm_csv'] ) ? $_FILES['wnm_csv'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in CsvImporter::accept_upload().
			$token = $this->importer->accept_upload( $file, $encoding );
		} catch ( \RuntimeException $e ) {
			$this->logger->log( 'error', '', 'Import upload rejected: ' . $e->getMessage(), 'error' );
			set_transient( 'wnm_notice_' . get_current_user_id(), $e->getMessage(), 60 );
			$this->back( 'import', array( 'error' => 1 ) );
		}
		$this->back( 'import', array( 'token' => $token ) );
	}

	public function import_cancel(): void {
		$this->authorize( 'wnm_import_cancel' );
		$this->importer->cancel( sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) );
		$this->back( 'import', array( 'cancelled' => 1 ) );
	}

	public function export(): void {
		$this->authorize( 'wnm_export' );
		$since = null;
		if ( 'since' === sanitize_key( $_POST['mode'] ?? 'all' ) ) {
			$date = sanitize_text_field( wp_unslash( $_POST['since'] ?? '' ) );
			$dt   = \DateTime::createFromFormat( '!Y-m-d', $date );
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
				set_transient( 'wnm_notice_' . get_current_user_id(), __( 'Invalid date.', 'wine-nutrition-manager' ), 60 );
				$this->back( 'import', array( 'error' => 1 ) );
			}
			$since = $date;
		}
		$delimiter = ';' === ( $_POST['delimiter'] ?? ',' ) ? ';' : ',';
		$this->exporter->stream( $since, $delimiter, ! empty( $_POST['bom'] ) );
		exit;
	}

	public function download_log(): void {
		$this->authorize( 'wnm_download_log' );
		$args = array(
			'action' => sanitize_key( $_REQUEST['log_action'] ?? '' ),
			'level'  => sanitize_key( $_REQUEST['level'] ?? '' ),
			'sku'    => sanitize_text_field( wp_unslash( $_REQUEST['sku'] ?? '' ) ),
			'run_id' => sanitize_text_field( wp_unslash( $_REQUEST['run_id'] ?? '' ) ),
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wine-nutrition-log-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, array( 'date', 'user', 'action', 'level', 'sku', 'run_id', 'details' ), ',', '"', '' );
		$users = array();
		foreach ( $this->logger->iterate( $args ) as $row ) {
			$uid = (int) $row['user_id'];
			if ( ! isset( $users[ $uid ] ) ) {
				$user          = $uid ? get_userdata( $uid ) : null;
				$users[ $uid ] = $user ? $user->user_login : ( $uid ? '#' . $uid : 'system' );
			}
			fputcsv(
				$out,
				array_map(
					array( CsvExporter::class, 'guard' ),
					array( get_date_from_gmt( $row['created_at'], 'Y-m-d H:i:s' ), $users[ $uid ], $row['action'], $row['level'], $row['sku'], $row['run_id'], (string) $row['details'] )
				),
				',',
				'"',
				''
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public function regenerate(): void {
		$this->authorize( 'wnm_regenerate' );
		$queued = $this->sync->queue_all();
		$this->logger->log( 'render', '', sprintf( 'Manual regenerate: %d SKU(s) queued', $queued ) );
		$this->back( 'settings', array( 'queued' => $queued ) );
	}

	// phpcs:enable
}
