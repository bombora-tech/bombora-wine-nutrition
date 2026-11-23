<?php
/**
 * Field definitions, enabled state, labels and display settings.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Services;

defined( 'ABSPATH' ) || exit;

final class FieldRegistry {

	public const OPTION_ENABLED  = 'wnm_enabled_fields';
	public const OPTION_CUSTOM   = 'wnm_custom_fields';
	public const OPTION_LABELS   = 'wnm_labels';
	public const OPTION_SETTINGS = 'wnm_settings';
	public const OUTPUT_MODES    = array( 'shortcode', 'description', 'both' );

	/** All numeric DB columns. Whitelist for every dynamic SQL fragment. */
	public const NUMERIC_COLUMNS = array( 'energy_kj', 'energy_kcal', 'fat', 'saturated_fat', 'carbohydrates', 'sugars', 'protein', 'salt' );

	/** Text DB columns editable by users. */
	/** Allowed value qualifiers for numeric fields ("< 0.5"), stored as JSON {column: op}. */
	public const QUALIFIERS = array( '<', '>', '≤', '≥' );

	public const TEXT_COLUMNS = array( 'ingredients', 'allergens', 'qr_url' );

	/**
	 * Standard field groups: key => columns, sub-row flag, EU rounding rule.
	 *
	 * @return array<string,array{columns:string[],sub:bool,rule:string}>
	 */
	public static function standard(): array {
		return array(
			'energy'        => array( 'columns' => array( 'energy_kj', 'energy_kcal' ), 'sub' => false, 'rule' => 'energy' ),
			'fat'           => array( 'columns' => array( 'fat' ), 'sub' => false, 'rule' => 'macro' ),
			'saturated_fat' => array( 'columns' => array( 'saturated_fat' ), 'sub' => true, 'rule' => 'saturates' ),
			'carbohydrates' => array( 'columns' => array( 'carbohydrates' ), 'sub' => false, 'rule' => 'macro' ),
			'sugars'        => array( 'columns' => array( 'sugars' ), 'sub' => true, 'rule' => 'macro' ),
			'protein'       => array( 'columns' => array( 'protein' ), 'sub' => false, 'rule' => 'macro' ),
			'salt'          => array( 'columns' => array( 'salt' ), 'sub' => false, 'rule' => 'salt' ),
		);
	}

	public static function default_labels(): array {
		return array(
			'title'         => 'Nutrition Information',
			'per'           => 'per 100 ml',
			'energy'        => 'Energy',
			'fat'           => 'Fat',
			'saturated_fat' => 'of which saturates',
			'carbohydrates' => 'Carbohydrates',
			'sugars'        => 'of which sugars',
			'protein'       => 'Protein',
			'salt'          => 'Salt',
			'ingredients'   => 'Ingredients',
			'allergens'     => 'Allergens',
			'qr'            => 'Scan QR code',
			'elabel'        => 'Electronic label (e-label)',
		);
	}

	public static function default_settings(): array {
		return array(
			'output_mode'              => 'shortcode',
			'compact_layout'           => 'table',
			'compact_allergens'        => 0,
			'show_qr_image'            => 1,
			'show_elabel_link'         => 1,
			'use_ingredients'          => 1,
			'use_allergens'            => 1,
			'use_qr_url'               => 1,
			'qr_size'                  => 110,
			'eu_rounding'              => 1,
			'eu_negligible'            => 'zero',
			'translate_ingredients'    => 1,
			'emphasize_allergens'      => 1,
			'render_on_product_save'   => 1,
			'log_retention_days'       => 180,
		);
	}

	public static function seed_defaults(): void {
		add_option( self::OPTION_ENABLED, array_keys( self::standard() ), '', false );
		add_option( self::OPTION_CUSTOM, array(), '', false );
		add_option( self::OPTION_LABELS, self::default_labels(), '', false );
		add_option( self::OPTION_SETTINGS, self::default_settings(), '', true );
	}

	/** @return string[] enabled standard group keys, in display order. */
	public function enabled_standard(): array {
		$enabled = get_option( self::OPTION_ENABLED, array_keys( self::standard() ) );
		$enabled = is_array( $enabled ) ? $enabled : array();
		return array_values( array_intersect( array_keys( self::standard() ), $enabled ) );
	}

	/** @return string[] numeric DB columns of enabled groups. */
	public function enabled_columns(): array {
		$cols = array();
		$std  = self::standard();
		foreach ( $this->enabled_standard() as $key ) {
			$cols = array_merge( $cols, $std[ $key ]['columns'] );
		}
		return $cols;
	}

	/**
	 * Custom field definitions.
	 *
	 * @return array<int,array{key:string,label:string,enabled:bool}>
	 */
	public function custom_definitions( bool $only_enabled = true ): array {
		$defs = get_option( self::OPTION_CUSTOM, array() );
		$defs = is_array( $defs ) ? $defs : array();
		$out  = array();
		foreach ( $defs as $def ) {
			if ( empty( $def['key'] ) || ( $only_enabled && empty( $def['enabled'] ) ) ) {
				continue;
			}
			$out[] = array(
				'key'     => sanitize_key( $def['key'] ),
				'label'   => (string) ( $def['label'] ?? $def['key'] ),
				'enabled' => ! empty( $def['enabled'] ),
			);
		}
		return $out;
	}

	/** @return string[] enabled custom field keys. */
	public function custom_keys(): array {
		return array_column( $this->custom_definitions(), 'key' );
	}

	public function labels(): array {
		$saved = get_option( self::OPTION_LABELS, array() );
		$saved = is_array( $saved ) ? array_filter( array_map( 'strval', $saved ), 'strlen' ) : array();
		return array_merge( self::default_labels(), array_intersect_key( $saved, self::default_labels() ) );
	}

	public function settings(): array {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return array_merge( self::default_settings(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * @return mixed
	 */
	public function setting( string $key ) {
		return $this->settings()[ $key ] ?? null;
	}

	/** 'description' | 'shortcode' | 'both' */
	public function output_mode(): string {
		$mode = (string) $this->setting( 'output_mode' );
		return in_array( $mode, self::OUTPUT_MODES, true ) ? $mode : 'shortcode';
	}

	/** Toggleable text fields: column => setting key + admin name. */
	public const TEXT_FIELDS = array(
		'ingredients' => array( 'setting' => 'use_ingredients', 'name' => 'Ingredients' ),
		'allergens'   => array( 'setting' => 'use_allergens', 'name' => 'Allergens' ),
		'qr_url'      => array( 'setting' => 'use_qr_url', 'name' => 'QR / e-label URL' ),
	);

	/**
	 * Text field enabled in Settings › Standard fields. Disabled: hidden in editor/dashboard,
	 * ignored in import/export, not rendered; stored values are kept.
	 */
	public function text_enabled( string $column ): bool {
		$setting = self::TEXT_FIELDS[ $column ]['setting'] ?? null;
		return $setting ? ! empty( $this->setting( $setting ) ) : false;
	}

	public function allergens_enabled(): bool {
		return $this->text_enabled( 'allergens' );
	}

	/** TEXT_COLUMNS minus disabled ones. */
	public function text_columns(): array {
		return array_values( array_filter( self::TEXT_COLUMNS, array( $this, 'text_enabled' ) ) );
	}

	public function writes_description(): bool {
		return 'shortcode' !== $this->output_mode();
	}

	/** Fingerprint of everything that affects rendered HTML. */
	public function render_fingerprint(): string {
		$s = $this->settings();
		unset( $s['log_retention_days'], $s['delete_data_on_uninstall'], $s['render_on_product_save'] ); // delete_data_on_uninstall: legacy 1.0.0 key.
		return md5( wp_json_encode( array( $this->enabled_standard(), $this->custom_definitions(), $this->labels(), $s ) ) );
	}
}
