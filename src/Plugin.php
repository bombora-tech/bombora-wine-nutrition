<?php
/**
 * Composition root: builds services once and wires hooks (constructor injection throughout).
 *
 * @package WineNutrition
 */

namespace WineNutrition;

use WineNutrition\Admin\AdminPage;
use WineNutrition\Admin\AjaxController;
use WineNutrition\Admin\FormHandler;
use WineNutrition\Database\NutritionRepository;
use WineNutrition\Database\Schema;
use WineNutrition\Export\CsvExporter;
use WineNutrition\Frontend\BlockRenderer;
use WineNutrition\Frontend\Shortcode;
use WineNutrition\Import\CsvImporter;
use WineNutrition\Logging\Logger;
use WineNutrition\Services\FieldRegistry;
use WineNutrition\Services\ProductSync;
use WineNutrition\Services\QrService;
use WineNutrition\Services\RecordSanitizer;
use WineNutrition\WPML\LabelTranslator;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;
	private bool $booted             = false;

	public FieldRegistry $fields;
	public Logger $logger;
	public NutritionRepository $repo;
	public RecordSanitizer $sanitizer;
	public LabelTranslator $labels;
	public BlockRenderer $renderer;
	public ProductSync $sync;
	public CsvImporter $importer;
	public CsvExporter $exporter;
	public Shortcode $shortcode;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** Capability required for every admin action. Filterable, never weaker than a logged-in check. */
	public static function capability(): string {
		$cap = (string) apply_filters( 'wnm_capability', 'manage_woocommerce' );
		return '' === $cap ? 'manage_woocommerce' : $cap;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Schema::maybe_upgrade();

		$this->fields    = new FieldRegistry();
		$this->logger    = new Logger();
		$this->repo      = new NutritionRepository( $this->fields );
		$this->sanitizer = new RecordSanitizer( $this->fields );
		$this->labels    = new LabelTranslator( $this->fields );
		$this->renderer  = new BlockRenderer( $this->fields, $this->labels, new QrService() );
		$this->sync      = new ProductSync( $this->repo, $this->renderer, $this->labels, $this->fields, $this->logger );
		$this->importer  = new CsvImporter( $this->repo, $this->sanitizer, $this->fields, $this->sync, $this->logger );
		$this->exporter  = new CsvExporter( $this->repo, $this->fields, $this->logger );

		$this->shortcode = new Shortcode( $this->repo, $this->renderer, $this->labels );

		$this->sync->register();
		$this->shortcode->set_sync( $this->sync );
		$this->shortcode->register();
		$this->labels->register();
		add_action( 'wnm_daily_maintenance', array( $this, 'maintenance' ) );
		add_action( 'init', array( $this, 'maybe_backfill' ), 20 );

		if ( is_admin() ) {
			( new AdminPage( $this->fields, $this->importer, $this->logger ) )->register();
			( new AjaxController( $this->repo, $this->sanitizer, $this->fields, $this->sync, $this->importer, $this->logger ) )->register();
			( new FormHandler( $this->fields, $this->importer, $this->exporter, $this->sync, $this->logger ) )->register();
			add_filter( 'plugin_action_links_' . plugin_basename( WNM_FILE ), array( $this, 'action_links' ) );
		}
	}

	/** After a plugin update, re-render everything once (e.g. 1.1.0 adds the product meta snapshot). */
	public function maybe_backfill(): void {
		if ( get_option( 'wnm_plugin_version' ) === WNM_VERSION ) {
			return;
		}
		update_option( 'wnm_plugin_version', WNM_VERSION, true );
		$queued = $this->sync->queue_all();
		$this->logger->log( 'settings', '', sprintf( 'Updated to %s; %d SKU(s) queued for re-render', WNM_VERSION, $queued ) );
	}

	public function maintenance(): void {
		$this->logger->prune( (int) $this->fields->setting( 'log_retention_days' ) );
		$this->importer->cleanup();
	}

	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . AdminPage::SLUG ) ) . '">' . esc_html__( 'Dashboard', 'wine-nutrition-manager' ) . '</a>' );
		return $links;
	}
}
