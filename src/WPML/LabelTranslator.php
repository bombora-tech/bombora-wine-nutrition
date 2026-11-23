<?php
/**
 * Registers frontend labels with WPML String Translation and translates them per language.
 * Nutrition values are never translated; one record serves every language.
 *
 * @package WineNutrition
 */

namespace WineNutrition\WPML;

use WineNutrition\Services\FieldRegistry;

defined( 'ABSPATH' ) || exit;

final class LabelTranslator {

	public const CONTEXT     = 'Wine Nutrition Manager';
	private const HASH_OPTION = 'wnm_labels_hash';

	/** WPML String Translation context for entered values (ingredients, allergens). */
	public const CONTENT_CONTEXT = 'Wine Nutrition Manager - Ingredients';

	/** @var array<string,bool> names registered in this request */
	private array $registered = array();

	public function __construct( private FieldRegistry $fields ) {}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_register_strings' ) );
	}

	public static function wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/** Re-registers strings only when labels or custom fields changed. */
	public function maybe_register_strings(): void {
		if ( ! self::wpml_active() ) {
			return;
		}
		$strings = $this->strings();
		$hash    = md5( wp_json_encode( $strings ) );
		if ( get_option( self::HASH_OPTION ) === $hash ) {
			return;
		}
		foreach ( $strings as $name => $value ) {
			do_action( 'wpml_register_single_string', self::CONTEXT, $name, $value );
		}
		update_option( self::HASH_OPTION, $hash, false );
	}

	/** @return array<string,string> string name => base-language value */
	public function strings(): array {
		$out = array();
		foreach ( $this->fields->labels() as $key => $value ) {
			$out[ 'label_' . $key ] = $value;
		}
		foreach ( $this->fields->custom_definitions( false ) as $def ) {
			$out[ 'custom_' . $def['key'] ] = $def['label'];
		}
		return $out;
	}

	public function translate( string $name, string $value, ?string $lang ): string {
		if ( null === $lang || ! self::wpml_active() ) {
			return $value;
		}
		$translated = apply_filters( 'wpml_translate_single_string', $value, self::CONTEXT, $name, $lang );
		return is_string( $translated ) && '' !== $translated ? $translated : $value;
	}

	/**
	 * String name for an entered value. Hash of the text, so identical texts (the same ingredient
	 * list on many wines) are one string in WPML and translated once.
	 */
	public static function content_name( string $field, string $text ): string {
		return $field . '_' . md5( trim( $text ) );
	}

	public function content_enabled(): bool {
		return self::wpml_active() && ! empty( $this->fields->setting( 'translate_ingredients' ) );
	}

	/** Register an entered value in WPML String Translation (admin / background only). */
	public function register_content( string $field, string $text ): void {
		$text = trim( $text );
		if ( '' === $text || ! $this->content_enabled() ) {
			return;
		}
		$name = self::content_name( $field, $text );
		if ( isset( $this->registered[ $name ] ) ) {
			return;
		}
		$this->registered[ $name ] = true;
		// Source language = site default language (the language values are entered in); otherwise
		// WPML assumes the String Translation default (often English) and the default-language
		// version could not be translated into English.
		$source = apply_filters( 'wpml_default_language', null );
		do_action( 'wpml_register_single_string', self::CONTENT_CONTEXT, $name, $text, false, is_string( $source ) && '' !== $source ? $source : null );
	}

	/** Register ingredients + allergens of a record. */
	public function register_record( ?array $record ): void {
		if ( ! $record ) {
			return;
		}
		foreach ( array( 'ingredients', 'allergens' ) as $field ) {
			if ( $this->fields->text_enabled( $field ) ) {
				$this->register_content( $field, (string) ( $record[ $field ] ?? '' ) );
			}
		}
	}

	/** Entered value in $lang; original when untranslated, WPML inactive or option off. */
	public function translate_content( string $field, string $text, ?string $lang ): string {
		$text = trim( $text );
		if ( '' === $text || null === $lang || ! $this->content_enabled() ) {
			return $text;
		}
		$translated = apply_filters( 'wpml_translate_single_string', $text, self::CONTENT_CONTEXT, self::content_name( $field, $text ), $lang );
		return is_string( $translated ) && '' !== trim( $translated ) ? trim( $translated ) : $text;
	}

	/** Language code of a product post, or null without WPML. */
	public function post_language( int $post_id ): ?string {
		if ( ! self::wpml_active() ) {
			return null;
		}
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );
		return is_array( $details ) && ! empty( $details['language_code'] ) ? (string) $details['language_code'] : null;
	}

	/** Language used for number formatting when WPML is not present. */
	public static function site_language(): string {
		return strtolower( substr( get_locale(), 0, 2 ) );
	}
}
