<?php
/**
 * WooCommerce > Wine Nutrition: Dashboard, Import / Export, Settings, Logs.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Admin;

use WineNutrition\Import\CsvImporter;
use WineNutrition\Logging\Logger;
use WineNutrition\Plugin;
use WineNutrition\Services\FieldRegistry;
use WineNutrition\WPML\LabelTranslator;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters, all sanitized.
final class AdminPage {

	public const SLUG = 'wine-nutrition';

	private string $hook = '';

	public function __construct(
		private FieldRegistry $fields,
		private CsvImporter $importer,
		private Logger $logger
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu(): void {
		$this->hook = (string) add_submenu_page(
			'woocommerce',
			__( 'Wine Nutrition', 'wine-nutrition-manager' ),
			__( 'Wine Nutrition', 'wine-nutrition-manager' ),
			Plugin::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function assets( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'wnm-admin', WNM_URL . 'assets/admin.css', array(), WNM_VERSION );
		wp_enqueue_script( 'wnm-admin', WNM_URL . 'assets/admin.js', array(), WNM_VERSION, true );

		$std = array();
		foreach ( $this->fields->enabled_standard() as $key ) {
			$std[] = array(
				'key'     => $key,
				'columns' => FieldRegistry::standard()[ $key ]['columns'],
				'label'   => $this->fields->labels()[ $key ],
			);
		}
		wp_localize_script(
			'wnm-admin',
			'WNM',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( AjaxController::NONCE ),
				'fields' => array(
					'standard' => $std,
					'custom'   => $this->fields->custom_definitions(),
					'text'     => $this->fields->text_columns(),
				),
				'i18n'   => array(
					'edit'        => __( 'Edit', 'wine-nutrition-manager' ),
					'close'       => __( 'Close', 'wine-nutrition-manager' ),
					'save'        => __( 'Save', 'wine-nutrition-manager' ),
					'saving'      => __( 'Saving…', 'wine-nutrition-manager' ),
					'saved'       => __( 'Saved', 'wine-nutrition-manager' ),
					'unchanged'   => __( 'No changes', 'wine-nutrition-manager' ),
					'delete'      => __( 'Delete record', 'wine-nutrition-manager' ),
					'confirmDel'  => __( 'Delete the nutrition record and remove the block from all products with this SKU?', 'wine-nutrition-manager' ),
					'complete'    => __( 'Complete', 'wine-nutrition-manager' ),
					'incomplete'  => __( 'Incomplete', 'wine-nutrition-manager' ),
					'missing'     => __( 'Missing', 'wine-nutrition-manager' ),
					'ok'          => __( 'OK', 'wine-nutrition-manager' ),
					'numHint'     => __( 'Number, optionally with < > <= >= (e.g. < 0.5)', 'wine-nutrition-manager' ),
					'noRecord'    => __( 'No record', 'wine-nutrition-manager' ),
					'noResults'   => __( 'No products found.', 'wine-nutrition-manager' ),
					'loading'     => __( 'Loading…', 'wine-nutrition-manager' ),
					'error'       => __( 'Error', 'wine-nutrition-manager' ),
					'ingredients' => $this->fields->allergens_enabled() ? __( 'Ingredients (use <strong> for allergens or list them below)', 'wine-nutrition-manager' ) : __( 'Ingredients (use <strong> for allergens)', 'wine-nutrition-manager' ),
					'allergens'   => __( 'Allergens (comma-separated)', 'wine-nutrition-manager' ),
					'qrUrl'       => __( 'QR / e-label URL', 'wine-nutrition-manager' ),
					'energyKj'    => __( 'Energy kJ', 'wine-nutrition-manager' ),
					'energyKcal'  => __( 'Energy kcal', 'wine-nutrition-manager' ),
					'rendered'    => __( 'description(s) updated', 'wine-nutrition-manager' ),
					'page'        => __( 'Page', 'wine-nutrition-manager' ),
					'of'          => __( 'of', 'wine-nutrition-manager' ),
					'items'       => __( 'items', 'wine-nutrition-manager' ),
					'importing'   => __( 'Importing…', 'wine-nutrition-manager' ),
					'importDone'  => __( 'Import finished.', 'wine-nutrition-manager' ),
					'unsaved'     => __( 'Unsaved changes will be lost.', 'wine-nutrition-manager' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wine-nutrition-manager' ) );
		}
		$tabs = array(
			'dashboard' => __( 'Dashboard', 'wine-nutrition-manager' ),
			'import'    => __( 'Import / Export', 'wine-nutrition-manager' ),
			'settings'  => __( 'Settings', 'wine-nutrition-manager' ),
			'logs'      => __( 'Logs', 'wine-nutrition-manager' ),
		);
		$tab  = sanitize_key( $_GET['tab'] ?? 'dashboard' );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'dashboard';

		echo '<div class="wrap wnm-wrap"><h1>' . esc_html__( 'Wine Nutrition Manager', 'wine-nutrition-manager' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		$notice = get_transient( 'wnm_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'wnm_notice_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( (string) $notice ) . '</p></div>';
		}

		$this->{'tab_' . $tab}();
		echo '</div>';
	}

	// --------------------------------------------------------------- dashboard

	private function tab_dashboard(): void {
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		?>
		<div class="wnm-stats" id="wnm-stats" aria-live="polite">
			<?php
			$cards = array(
				'total'               => __( 'Products (with SKU)', 'wine-nutrition-manager' ),
				'completed'           => __( 'Completed', 'wine-nutrition-manager' ),
				'missing_nutrition'   => __( 'Missing nutrition', 'wine-nutrition-manager' ),
				'missing_ingredients' => __( 'Missing ingredients', 'wine-nutrition-manager' ),
				'missing_qr'          => __( 'Missing QR', 'wine-nutrition-manager' ),
				'without_sku'         => __( 'Products without SKU', 'wine-nutrition-manager' ),
				'orphaned_records'    => __( 'Records without product here', 'wine-nutrition-manager' ),
			);
			if ( ! $this->fields->text_enabled( 'ingredients' ) ) {
				unset( $cards['missing_ingredients'] );
			}
			if ( ! $this->fields->text_enabled( 'qr_url' ) ) {
				unset( $cards['missing_qr'] );
			}
			foreach ( $cards as $key => $label ) {
				printf( '<div class="wnm-card wnm-card--%1$s"><span class="wnm-card__value" data-stat="%1$s">–</span><span class="wnm-card__label">%2$s</span></div>', esc_attr( $key ), esc_html( $label ) );
			}
			?>
		</div>
		<p class="wnm-pending" id="wnm-pending" hidden></p>

		<div class="wnm-toolbar">
			<label class="screen-reader-text" for="wnm-search"><?php esc_html_e( 'Search SKU or product name', 'wine-nutrition-manager' ); ?></label>
			<input type="search" id="wnm-search" placeholder="<?php esc_attr_e( 'Search SKU or product name…', 'wine-nutrition-manager' ); ?>">
			<select id="wnm-category" aria-label="<?php esc_attr_e( 'Category', 'wine-nutrition-manager' ); ?>">
				<option value="0"><?php esc_html_e( 'All categories', 'wine-nutrition-manager' ); ?></option>
				<?php
				if ( is_array( $cats ) ) {
					foreach ( $cats as $cat ) {
						printf( '<option value="%d">%s</option>', (int) $cat->term_id, esc_html( $cat->name ) );
					}
				}
				?>
			</select>
			<select id="wnm-status" aria-label="<?php esc_attr_e( 'Status', 'wine-nutrition-manager' ); ?>">
				<option value=""><?php esc_html_e( 'Any status', 'wine-nutrition-manager' ); ?></option>
				<option value="complete"><?php esc_html_e( 'Complete', 'wine-nutrition-manager' ); ?></option>
				<option value="incomplete"><?php esc_html_e( 'Incomplete', 'wine-nutrition-manager' ); ?></option>
				<option value="no_record"><?php esc_html_e( 'No record', 'wine-nutrition-manager' ); ?></option>
			</select>
			<?php if ( $this->fields->text_enabled( 'qr_url' ) ) : ?>
			<select id="wnm-qr" aria-label="<?php esc_attr_e( 'QR', 'wine-nutrition-manager' ); ?>">
				<option value=""><?php esc_html_e( 'QR: any', 'wine-nutrition-manager' ); ?></option>
				<option value="has"><?php esc_html_e( 'Has QR', 'wine-nutrition-manager' ); ?></option>
				<option value="missing"><?php esc_html_e( 'Missing QR', 'wine-nutrition-manager' ); ?></option>
			</select>
			<?php endif; ?>
			<?php if ( $this->fields->text_enabled( 'ingredients' ) ) : ?>
			<select id="wnm-ingredients" aria-label="<?php esc_attr_e( 'Ingredients', 'wine-nutrition-manager' ); ?>">
				<option value=""><?php esc_html_e( 'Ingredients: any', 'wine-nutrition-manager' ); ?></option>
				<option value="has"><?php esc_html_e( 'Has ingredients', 'wine-nutrition-manager' ); ?></option>
				<option value="missing"><?php esc_html_e( 'Missing ingredients', 'wine-nutrition-manager' ); ?></option>
			</select>
			<?php endif; ?>
			<select id="wnm-perpage" aria-label="<?php esc_attr_e( 'Per page', 'wine-nutrition-manager' ); ?>">
				<option value="25">25</option><option value="50" selected>50</option><option value="100">100</option><option value="200">200</option>
			</select>
		</div>

		<table class="widefat striped wnm-table" id="wnm-table">
			<thead><tr>
				<th class="wnm-sortable" data-orderby="sku"><?php esc_html_e( 'SKU', 'wine-nutrition-manager' ); ?></th>
				<th class="wnm-sortable" data-orderby="title"><?php esc_html_e( 'Product', 'wine-nutrition-manager' ); ?></th>
				<th><?php esc_html_e( 'Category', 'wine-nutrition-manager' ); ?></th>
				<th><?php esc_html_e( 'Nutrition', 'wine-nutrition-manager' ); ?></th>
				<?php if ( $this->fields->text_enabled( 'ingredients' ) ) : ?><th><?php esc_html_e( 'Ingredients', 'wine-nutrition-manager' ); ?></th><?php endif; ?>
				<?php if ( $this->fields->text_enabled( 'qr_url' ) ) : ?><th><?php esc_html_e( 'QR', 'wine-nutrition-manager' ); ?></th><?php endif; ?>
				<th class="wnm-sortable" data-orderby="updated_at"><?php esc_html_e( 'Last updated', 'wine-nutrition-manager' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Edit', 'wine-nutrition-manager' ); ?></span></th>
			</tr></thead>
			<tbody><tr><td colspan="<?php echo (int) ( 6 + (int) $this->fields->text_enabled( 'ingredients' ) + (int) $this->fields->text_enabled( 'qr_url' ) ); ?>"><?php esc_html_e( 'Loading…', 'wine-nutrition-manager' ); ?></td></tr></tbody>
		</table>
		<div class="wnm-pager" id="wnm-pager"></div>
		<?php
	}

	// ------------------------------------------------------------------ import

	private function tab_import(): void {
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		$state = $token ? $this->importer->get_state( $token ) : null;

		if ( isset( $_GET['cancelled'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Import cancelled. No data was changed.', 'wine-nutrition-manager' ) . '</p></div>';
		}

		if ( $state && in_array( $state['status'], array( 'analysed', 'running' ), true ) ) {
			$this->import_preview( $token, $state );
			return;
		}
		?>
		<div class="wnm-columns">
			<section class="wnm-panel">
				<h2><?php esc_html_e( 'Import CSV', 'wine-nutrition-manager' ); ?></h2>
				<p><?php esc_html_e( 'Records are matched by SKU. Only columns present in the file are changed; empty cells leave stored values untouched unless you choose otherwise in the preview.', 'wine-nutrition-manager' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wnm_import_upload' ); ?>
					<input type="hidden" name="action" value="wnm_import_upload">
					<p><input type="file" name="wnm_csv" accept=".csv,text/csv" required></p>
					<p>
						<label for="wnm-encoding"><?php esc_html_e( 'File encoding', 'wine-nutrition-manager' ); ?></label>
						<select name="encoding" id="wnm-encoding">
							<option value="UTF-8">UTF-8</option>
							<option value="Windows-1250"><?php esc_html_e( 'Windows-1250 (Excel CZ/SK/PL)', 'wine-nutrition-manager' ); ?></option>
							<option value="Windows-1252"><?php esc_html_e( 'Windows-1252 (Excel DE/FR/NL)', 'wine-nutrition-manager' ); ?></option>
						</select>
					</p>
					<?php submit_button( __( 'Upload and analyse', 'wine-nutrition-manager' ), 'primary', 'submit', false ); ?>
				</form>
			</section>

			<section class="wnm-panel">
				<h2><?php esc_html_e( 'Export CSV', 'wine-nutrition-manager' ); ?></h2>
				<p><?php esc_html_e( 'Exports nutrition records only (no product data). Columns follow the enabled fields.', 'wine-nutrition-manager' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wnm_export' ); ?>
					<input type="hidden" name="action" value="wnm_export">
					<fieldset>
						<label><input type="radio" name="mode" value="all" checked> <?php esc_html_e( 'Export everything', 'wine-nutrition-manager' ); ?></label><br>
						<label><input type="radio" name="mode" value="since"> <?php esc_html_e( 'Changed since', 'wine-nutrition-manager' ); ?></label>
						<input type="date" name="since" value="<?php echo esc_attr( wp_date( 'Y-m-01' ) ); ?>">
					</fieldset>
					<p>
						<label><?php esc_html_e( 'Delimiter', 'wine-nutrition-manager' ); ?>
							<select name="delimiter"><option value=",">, (comma)</option><option value=";">; (semicolon – Excel CZ/DE)</option></select>
						</label>
						<label><input type="checkbox" name="bom" value="1" checked> <?php esc_html_e( 'UTF-8 BOM (Excel)', 'wine-nutrition-manager' ); ?></label>
					</p>
					<?php submit_button( __( 'Download CSV', 'wine-nutrition-manager' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	private function import_preview( string $token, array $state ): void {
		$a = $state['analysis'];
		$c = $a['counts'];
		?>
		<section class="wnm-panel" id="wnm-import" data-token="<?php echo esc_attr( $token ); ?>">
			<h2><?php /* translators: %s: file name */ printf( esc_html__( 'Import preview: %s', 'wine-nutrition-manager' ), esc_html( $state['name'] ) ); ?></h2>
			<table class="widefat wnm-preview">
				<tbody>
					<tr><th><?php esc_html_e( 'Records in file', 'wine-nutrition-manager' ); ?></th><td><?php echo (int) $a['rows']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Unique SKUs', 'wine-nutrition-manager' ); ?></th><td><?php echo (int) $a['unique_skus']; ?><?php echo $a['duplicates'] ? ' <span class="wnm-warn">(' . (int) $a['duplicates'] . ' ' . esc_html__( 'duplicate rows – last one wins', 'wine-nutrition-manager' ) . ')</span>' : ''; ?></td></tr>
					<tr><th><?php esc_html_e( 'Matching products', 'wine-nutrition-manager' ); ?></th><td><?php echo (int) $a['matched']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Missing products', 'wine-nutrition-manager' ); ?></th><td><?php echo (int) $a['not_found']; ?></td></tr>
					<tr><th><?php esc_html_e( 'New records (matched products)', 'wine-nutrition-manager' ); ?></th><td><?php echo (int) $c['matched_new']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Updates (matched products)', 'wine-nutrition-manager' ); ?></th><td><?php echo (int) $c['matched_update']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Unmatched SKUs: new / existing records', 'wine-nutrition-manager' ); ?></th><td><?php echo (int) $c['unmatched_new'] . ' / ' . (int) $c['unmatched_update']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Rows with errors', 'wine-nutrition-manager' ); ?></th><td><?php echo (int) $a['error_rows']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Plausibility warnings', 'wine-nutrition-manager' ); ?></th><td><?php echo (int) $a['warnings']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Columns imported', 'wine-nutrition-manager' ); ?></th><td><code><?php echo esc_html( implode( ', ', $a['columns'] ) ); ?></code></td></tr>
					<?php if ( $a['ignored_columns'] ) : ?>
						<tr><th><?php esc_html_e( 'Ignored (field disabled)', 'wine-nutrition-manager' ); ?></th><td><code><?php echo esc_html( implode( ', ', $a['ignored_columns'] ) ); ?></code></td></tr>
					<?php endif; ?>
					<?php if ( $a['unknown_columns'] ) : ?>
						<tr><th><?php esc_html_e( 'Unknown columns (ignored)', 'wine-nutrition-manager' ); ?></th><td><code><?php echo esc_html( implode( ', ', $a['unknown_columns'] ) ); ?></code></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $a['missing_sample'] ) : ?>
				<details><summary><?php esc_html_e( 'SKUs without product in this shop', 'wine-nutrition-manager' ); ?></summary><p><code><?php echo esc_html( implode( ', ', $a['missing_sample'] ) ); ?></code></p></details>
			<?php endif; ?>
			<?php if ( $a['errors_sample'] ) : ?>
				<details open><summary><?php esc_html_e( 'Errors (first 25) – these rows will be skipped', 'wine-nutrition-manager' ); ?></summary><ul class="wnm-errors">
					<?php
					foreach ( $a['errors_sample'] as $err ) {
						echo '<li>' . esc_html( $err ) . '</li>';
					}
					?>
				</ul></details>
			<?php endif; ?>

			<fieldset class="wnm-options">
				<label><input type="checkbox" id="wnm-store-unmatched" value="1"> <?php esc_html_e( 'Also store records for SKUs that have no product in this shop (applied automatically when the product is created later)', 'wine-nutrition-manager' ); ?></label><br>
				<label><input type="checkbox" id="wnm-empty-clears" value="1"> <?php esc_html_e( 'Empty cells clear stored values (default: empty cells are ignored)', 'wine-nutrition-manager' ); ?></label>
			</fieldset>

			<div class="wnm-progress" hidden><div class="wnm-progress__bar"></div></div>
			<p class="wnm-import-status" aria-live="polite"></p>
			<div class="wnm-import-report" hidden></div>

			<p class="wnm-import-actions">
				<button type="button" class="button button-primary" id="wnm-import-run"><?php esc_html_e( 'Import', 'wine-nutrition-manager' ); ?></button>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wnm-cancel-form">
				<?php wp_nonce_field( 'wnm_import_cancel' ); ?>
				<input type="hidden" name="action" value="wnm_import_cancel">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Cancel', 'wine-nutrition-manager' ); ?></button>
			</form>
			<template id="wnm-log-url"><?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wnm_download_log' ), 'wnm_download_log' ) ); ?></template>
		</section>
		<?php
	}

	// ---------------------------------------------------------------- settings

	private function tab_settings(): void {
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wine-nutrition-manager' ) . '</p></div>';
		}
		$queued = absint( $_GET['queued'] ?? 0 );
		if ( $queued ) {
			/* translators: %d: number of SKUs */
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( __( '%d SKU(s) queued for background re-rendering of product descriptions.', 'wine-nutrition-manager' ), $queued ) ) . '</p></div>';
		}

		$enabled  = $this->fields->enabled_standard();
		$labels   = $this->fields->labels();
		$defaults = FieldRegistry::default_labels();
		$settings = $this->fields->settings();
		$custom   = $this->fields->custom_definitions( false );
		$names    = array(
			'energy'        => __( 'Energy (kJ / kcal)', 'wine-nutrition-manager' ),
			'fat'           => __( 'Fat', 'wine-nutrition-manager' ),
			'saturated_fat' => __( 'Saturated fat', 'wine-nutrition-manager' ),
			'carbohydrates' => __( 'Carbohydrates', 'wine-nutrition-manager' ),
			'sugars'        => __( 'Sugars', 'wine-nutrition-manager' ),
			'protein'       => __( 'Protein', 'wine-nutrition-manager' ),
			'salt'          => __( 'Salt', 'wine-nutrition-manager' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wnm-settings">
			<?php wp_nonce_field( 'wnm_save_settings' ); ?>
			<input type="hidden" name="action" value="wnm_save_settings">

			<div class="wnm-columns">
				<section class="wnm-panel">
					<h2><?php esc_html_e( 'Standard fields', 'wine-nutrition-manager' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Disabled fields are hidden in the editor, CSV and frontend. Stored values are kept.', 'wine-nutrition-manager' ); ?></p>
					<?php foreach ( $names as $key => $name ) : ?>
						<label class="wnm-check"><input type="checkbox" name="standard[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $enabled, true ) ); ?>> <?php echo esc_html( $name ); ?></label>
					<?php endforeach; ?>
					<?php
					$text_names = array(
						'ingredients' => __( 'Ingredients', 'wine-nutrition-manager' ),
						'allergens'   => __( 'Allergens', 'wine-nutrition-manager' ),
						'qr_url'      => __( 'QR / e-label URL', 'wine-nutrition-manager' ),
					);
					foreach ( FieldRegistry::TEXT_FIELDS as $col => $def ) :
						?>
						<label class="wnm-check"><input type="checkbox" name="settings[<?php echo esc_attr( $def['setting'] ); ?>]" value="1" <?php checked( ! empty( $settings[ $def['setting'] ] ) ); ?>> <?php echo esc_html( $text_names[ $col ] ); ?></label>
					<?php endforeach; ?>

					<h2><?php esc_html_e( 'Custom fields', 'wine-nutrition-manager' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Free-text values (e.g. "2.1 g"). CSV column: cf_{key}. The key cannot be changed after creation.', 'wine-nutrition-manager' ); ?></p>
					<table class="widefat wnm-custom-table">
						<thead><tr><th><?php esc_html_e( 'Enabled', 'wine-nutrition-manager' ); ?></th><th><?php esc_html_e( 'Label', 'wine-nutrition-manager' ); ?></th><th><?php esc_html_e( 'Key', 'wine-nutrition-manager' ); ?></th><th><?php esc_html_e( 'Remove', 'wine-nutrition-manager' ); ?></th></tr></thead>
						<tbody id="wnm-custom-rows">
							<?php foreach ( $custom as $i => $def ) : ?>
								<tr>
									<td><input type="checkbox" name="custom[<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( $def['enabled'] ); ?>></td>
									<td><input type="text" name="custom[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $def['label'] ); ?>" required></td>
									<td><code>cf_<?php echo esc_html( $def['key'] ); ?></code><input type="hidden" name="custom[<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( $def['key'] ); ?>"></td>
									<td><input type="checkbox" name="custom[<?php echo (int) $i; ?>][remove]" value="1" aria-label="<?php esc_attr_e( 'Remove', 'wine-nutrition-manager' ); ?>"></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p><button type="button" class="button" id="wnm-add-custom" data-next="<?php echo (int) count( $custom ); ?>"><?php esc_html_e( 'Add custom field', 'wine-nutrition-manager' ); ?></button></p>
				</section>

				<section class="wnm-panel">
					<h2><?php esc_html_e( 'Frontend labels', 'wine-nutrition-manager' ); ?></h2>
					<p class="description">
						<?php
						echo LabelTranslator::wpml_active()
							? esc_html__( 'Enter labels in the default site language. Translate them in WPML › String Translation (context: "Wine Nutrition Manager").', 'wine-nutrition-manager' )
							: esc_html__( 'Labels used in the rendered nutrition block.', 'wine-nutrition-manager' );
						?>
					</p>
					<?php foreach ( $defaults as $key => $default ) : ?>
						<p class="wnm-label-row">
							<label for="wnm-label-<?php echo esc_attr( $key ); ?>"><code><?php echo esc_html( $key ); ?></code></label>
							<input type="text" id="wnm-label-<?php echo esc_attr( $key ); ?>" name="labels[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $labels[ $key ] ); ?>" placeholder="<?php echo esc_attr( $default ); ?>">
						</p>
					<?php endforeach; ?>

					<h2><?php esc_html_e( 'Display & behaviour', 'wine-nutrition-manager' ); ?></h2>
					<p><label for="wnm-output-mode"><strong><?php esc_html_e( 'Product description block', 'wine-nutrition-manager' ); ?></strong></label><br>
						<select id="wnm-output-mode" name="settings[output_mode]">
							<option value="shortcode" <?php selected( $settings['output_mode'], 'shortcode' ); ?>><?php esc_html_e( 'Off — use product meta / shortcode only (removes stored blocks)', 'wine-nutrition-manager' ); ?></option>
							<option value="description" <?php selected( in_array( $settings['output_mode'], array( 'description', 'both' ), true ) ); ?>><?php esc_html_e( 'On — also write the card into the product description', 'wine-nutrition-manager' ); ?></option>
						</select></p>
					<p class="description"><?php esc_html_e( 'Compact HTML is always stored in product meta wnm_nutrition_html. Bricks: Rich Text element with {cf_wnm_nutrition_html} — keeps working when this plugin is deactivated or deleted. Live alternative (plugin active only): [wine_nutrition sku="" layout="table|inline" title="yes|no" tag="h4" ingredients="yes|no" allergens="yes|no" qr="both|image|link|no" class=""].', 'wine-nutrition-manager' ); ?></p>
					<p><label><?php esc_html_e( 'Compact layout', 'wine-nutrition-manager' ); ?>
						<select name="settings[compact_layout]">
							<option value="table" <?php selected( $settings['compact_layout'], 'table' ); ?>><?php esc_html_e( 'Table (label / value rows)', 'wine-nutrition-manager' ); ?></option>
							<option value="inline" <?php selected( $settings['compact_layout'], 'inline' ); ?>><?php esc_html_e( 'Inline (one line)', 'wine-nutrition-manager' ); ?></option>
						</select></label></p>
					<label class="wnm-check"><input type="checkbox" name="settings[compact_allergens]" value="1" <?php checked( $settings['compact_allergens'] ); ?>> <?php esc_html_e( 'Show allergens line in the compact output (off if allergens are already a product attribute)', 'wine-nutrition-manager' ); ?></label>
					<label class="wnm-check"><input type="checkbox" name="settings[translate_ingredients]" value="1" <?php checked( $settings['translate_ingredients'] ); ?>> <?php esc_html_e( 'Translate ingredients and allergens via WPML String Translation (context "Wine Nutrition Manager - Ingredients"; identical texts are one string)', 'wine-nutrition-manager' ); ?></label>
					<label class="wnm-check"><input type="checkbox" name="settings[eu_rounding]" value="1" <?php checked( $settings['eu_rounding'] ); ?>> <?php esc_html_e( 'Apply EU rounding guidelines on frontend (energy to 1, macros to 0.1 g / 1 g; off = values shown as entered)', 'wine-nutrition-manager' ); ?></label>
					<p style="margin-left:24px"><label><?php esc_html_e( 'Negligible amounts (fat, carbohydrates, sugars, protein < 0.5 g; saturates < 0.1 g; salt ≤ 0.0125 g):', 'wine-nutrition-manager' ); ?>
						<select name="settings[eu_negligible]">
							<option value="zero" <?php selected( $settings['eu_negligible'], 'zero' ); ?>><?php esc_html_e( 'Show as 0 g', 'wine-nutrition-manager' ); ?></option>
							<option value="less_than" <?php selected( $settings['eu_negligible'], 'less_than' ); ?>><?php esc_html_e( 'Show as < 0.5 g / < 0.1 g / < 0.01 g', 'wine-nutrition-manager' ); ?></option>
						</select></label></p>
					<label class="wnm-check"><input type="checkbox" name="settings[emphasize_allergens]" value="1" <?php checked( $settings['emphasize_allergens'] ); ?>> <?php esc_html_e( 'Automatically bold allergens inside the ingredient list', 'wine-nutrition-manager' ); ?></label>
					<label class="wnm-check"><input type="checkbox" name="settings[show_qr_image]" value="1" <?php checked( $settings['show_qr_image'] ); ?>> <?php esc_html_e( 'Show QR image next to the e-label link', 'wine-nutrition-manager' ); ?></label>
					<label class="wnm-check"><input type="checkbox" name="settings[show_elabel_link]" value="1" <?php checked( $settings['show_elabel_link'] ); ?>> <?php esc_html_e( 'Show e-label text link (off = QR image only, linked; both off = no e-label output)', 'wine-nutrition-manager' ); ?></label>
					<p><label><?php esc_html_e( 'QR size (px)', 'wine-nutrition-manager' ); ?> <input type="number" name="settings[qr_size]" min="60" max="300" value="<?php echo (int) $settings['qr_size']; ?>" class="small-text"></label></p>
					<label class="wnm-check"><input type="checkbox" name="settings[render_on_product_save]" value="1" <?php checked( $settings['render_on_product_save'] ); ?>> <?php esc_html_e( 'Re-render nutrition output when a product is saved (keeps new translations and newly created products in sync)', 'wine-nutrition-manager' ); ?></label>
					<p><label><?php esc_html_e( 'Keep logs for (days, 0 = forever)', 'wine-nutrition-manager' ); ?> <input type="number" name="settings[log_retention_days]" min="0" max="3650" value="<?php echo (int) $settings['log_retention_days']; ?>" class="small-text"></label></p>
					<p class="description"><?php esc_html_e( 'Data is kept when the plugin is deactivated or deleted, and is editable again after reactivation. Permanent removal on delete requires define( \'WNM_DELETE_DATA_ON_UNINSTALL\', true ); in wp-config.php.', 'wine-nutrition-manager' ); ?></p>
				</section>
			</div>
			<?php submit_button( __( 'Save settings', 'wine-nutrition-manager' ) ); ?>
		</form>

		<section class="wnm-panel">
			<h2><?php esc_html_e( 'Regenerate product descriptions', 'wine-nutrition-manager' ); ?></h2>
			<p><?php esc_html_e( 'Re-renders the nutrition block for every stored record in the background (Action Scheduler). Runs automatically after saving settings that change the output.', 'wine-nutrition-manager' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wnm_regenerate' ); ?>
				<input type="hidden" name="action" value="wnm_regenerate">
				<?php submit_button( __( 'Regenerate all', 'wine-nutrition-manager' ), 'secondary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}

	// -------------------------------------------------------------------- logs

	private function tab_logs(): void {
		$args   = array(
			'action'   => sanitize_key( $_GET['log_action'] ?? '' ),
			'level'    => sanitize_key( $_GET['level'] ?? '' ),
			'sku'      => sanitize_text_field( wp_unslash( $_GET['sku'] ?? '' ) ),
			'run_id'   => sanitize_text_field( wp_unslash( $_GET['run_id'] ?? '' ) ),
			'page'     => max( 1, absint( $_GET['paged'] ?? 1 ) ),
			'per_page' => 50,
		);
		$result = $this->logger->query( $args );
		$pages  = (int) ceil( $result['total'] / 50 );
		$filter = array_filter( array( 'log_action' => $args['action'], 'level' => $args['level'], 'sku' => $args['sku'], 'run_id' => $args['run_id'] ) );
		$dl_url = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'wnm_download_log' ), $filter ), admin_url( 'admin-post.php' ) ), 'wnm_download_log' );
		?>
		<form method="get" class="wnm-toolbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="tab" value="logs">
			<select name="log_action">
				<option value=""><?php esc_html_e( 'All actions', 'wine-nutrition-manager' ); ?></option>
				<?php foreach ( Logger::ACTIONS as $a ) : ?>
					<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $args['action'], $a ); ?>><?php echo esc_html( $a ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="level">
				<option value=""><?php esc_html_e( 'All levels', 'wine-nutrition-manager' ); ?></option>
				<?php foreach ( array( 'info', 'warning', 'error' ) as $l ) : ?>
					<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $args['level'], $l ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="sku" value="<?php echo esc_attr( $args['sku'] ); ?>" placeholder="SKU">
			<input type="text" name="run_id" value="<?php echo esc_attr( $args['run_id'] ); ?>" placeholder="<?php esc_attr_e( 'Import run ID', 'wine-nutrition-manager' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'wine-nutrition-manager' ); ?></button>
			<a class="button" href="<?php echo esc_url( $dl_url ); ?>"><?php esc_html_e( 'Download CSV', 'wine-nutrition-manager' ); ?></a>
		</form>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Date', 'wine-nutrition-manager' ); ?></th>
				<th><?php esc_html_e( 'User', 'wine-nutrition-manager' ); ?></th>
				<th><?php esc_html_e( 'Action', 'wine-nutrition-manager' ); ?></th>
				<th><?php esc_html_e( 'Level', 'wine-nutrition-manager' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'wine-nutrition-manager' ); ?></th>
				<th><?php esc_html_e( 'Details', 'wine-nutrition-manager' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $result['rows'] ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No log entries.', 'wine-nutrition-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $result['rows'] as $row ) :
				$user = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
				?>
				<tr class="wnm-log-<?php echo esc_attr( $row['level'] ); ?>">
					<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
					<td><?php echo esc_html( $user ? $user->user_login : ( (int) $row['user_id'] ? '#' . $row['user_id'] : 'system' ) ); ?></td>
					<td><?php echo esc_html( $row['action'] ); ?></td>
					<td><?php echo esc_html( $row['level'] ); ?></td>
					<td><?php echo esc_html( $row['sku'] ); ?></td>
					<td>
						<?php echo esc_html( (string) $row['details'] ); ?>
						<?php if ( $row['run_id'] && empty( $args['run_id'] ) ) : ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'logs', 'run_id' => $row['run_id'] ), admin_url( 'admin.php' ) ) ); ?>" class="wnm-run">[<?php echo esc_html( $row['run_id'] ); ?>]</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $args['page'],
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}
}
// phpcs:enable
