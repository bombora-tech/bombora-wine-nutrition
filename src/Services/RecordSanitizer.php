<?php
/**
 * Validates and sanitizes nutrition input from the editor and CSV import.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Services;

defined( 'ABSPATH' ) || exit;

final class RecordSanitizer {

	/** Tags allowed in ingredients (allergen emphasis per Reg. (EU) 1169/2011 Art. 21). */
	public const INGREDIENT_TAGS = array(
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
	);

	public const MAX_NUMBER = 99999999.99;

	public function __construct( private FieldRegistry $fields ) {}

	public static function sku( $raw ): string {
		$sku = trim( sanitize_text_field( wp_unslash( (string) $raw ) ) );
		return mb_substr( $sku, 0, 191 );
	}

	/**
	 * Sanitize a set of raw values.
	 *
	 * @param array $raw          Column => raw value (already unslashed).
	 * @param bool  $empty_clears True: empty input sets NULL. False: empty input is skipped (left unchanged).
	 * @return array{data:array,errors:string[],warnings:string[]}
	 */
	public function sanitize( array $raw, bool $empty_clears ): array {
		$data     = array();
		$errors   = array();
		$warnings = array();

		foreach ( $this->fields->enabled_columns() as $col ) {
			if ( ! array_key_exists( $col, $raw ) ) {
				continue;
			}
			$value = $this->strip_csv_guard( (string) $raw[ $col ] );
			if ( '' === trim( $value ) ) {
				if ( $empty_clears ) {
					$data[ $col ]               = null;
					$data['qualifiers'][ $col ] = '';
				}
				continue;
			}
			[ $op, $value ] = self::split_qualifier( $value );
			$number         = self::parse_number( $value );
			if ( null === $number ) {
				/* translators: 1: column name, 2: value */
				$errors[] = sprintf( __( '%1$s: "%2$s" is not a valid non-negative number.', 'wine-nutrition-manager' ), $col, mb_substr( $value, 0, 40 ) );
				continue;
			}
			$data[ $col ]               = $number;
			$data['qualifiers'][ $col ] = $op; // '' clears a previous qualifier; merged by the repository.
		}

		if ( array_key_exists( 'ingredients', $raw ) ) {
			$value = trim( wp_kses( $this->strip_csv_guard( (string) $raw['ingredients'] ), self::INGREDIENT_TAGS ) );
			if ( '' !== $value || $empty_clears ) {
				$data['ingredients'] = '' === $value ? null : $value;
			}
		}

		if ( array_key_exists( 'allergens', $raw ) ) {
			$value = trim( sanitize_textarea_field( $this->strip_csv_guard( (string) $raw['allergens'] ) ) );
			if ( '' !== $value || $empty_clears ) {
				$data['allergens'] = '' === $value ? null : $value;
			}
		}

		if ( array_key_exists( 'qr_url', $raw ) ) {
			$input = trim( (string) $raw['qr_url'] );
			if ( '' === $input ) {
				if ( $empty_clears ) {
					$data['qr_url'] = null;
				}
			} else {
				$url = esc_url_raw( $input, array( 'http', 'https' ) );
				if ( '' === $url || ! wp_http_validate_url( $url ) ) {
					/* translators: %s: URL */
					$errors[] = sprintf( __( 'qr_url: "%s" is not a valid http(s) URL.', 'wine-nutrition-manager' ), mb_substr( $input, 0, 80 ) );
				} else {
					$data['qr_url'] = mb_substr( $url, 0, 2048 );
				}
			}
		}

		if ( isset( $raw['custom_fields'] ) && is_array( $raw['custom_fields'] ) ) {
			$allowed = $this->fields->custom_keys();
			$custom  = array();
			foreach ( $raw['custom_fields'] as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( ! in_array( $key, $allowed, true ) ) {
					continue;
				}
				$value = trim( sanitize_text_field( $this->strip_csv_guard( (string) $value ) ) );
				if ( '' === $value && ! $empty_clears ) {
					continue;
				}
				$custom[ $key ] = mb_substr( $value, 0, 255 );
			}
			if ( $custom ) {
				$data['custom_fields'] = $custom; // Merged with stored JSON by the repository.
			}
		}

		$warnings = $this->plausibility( $data );

		return compact( 'data', 'errors', 'warnings' );
	}

	/**
	 * Splits a leading comparison sign: "< 3.2" → ['<', '3.2'], "<=0,5" → ['≤', '0,5'], "3" → ['', '3'].
	 * Also accepts HTML entities (&lt; &gt;) and the Unicode signs ≤ ≥.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function split_qualifier( string $value ): array {
		$value = trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( preg_match( '/^(<=|>=|=<|=>|≤|≥|<|>)\s*(.*)$/u', $value, $m ) ) {
			$op = strtr( $m[1], array( '<=' => '≤', '=<' => '≤', '>=' => '≥', '=>' => '≥' ) );
			return array( $op, $m[2] );
		}
		return array( '', $value );
	}

	/**
	 * Accepts "3.5", "3,5", " 3 ", rejects negatives and garbage.
	 */
	public static function parse_number( string $value ): ?float {
		$value = str_replace( array( ' ', "\u{00A0}", ',' ), array( '', '', '.' ), trim( $value ) );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$number = round( (float) $value, 2 );
		if ( $number < 0 || $number > self::MAX_NUMBER ) {
			return null;
		}
		return $number;
	}

	/**
	 * Non-blocking data-quality warnings.
	 *
	 * @return string[]
	 */
	private function plausibility( array $data ): array {
		$w = array();
		// Comparisons are meaningless when either side is a bound ("< 3.2").
		$exact = static fn( string $c ) => isset( $data[ $c ] ) && '' === ( $data['qualifiers'][ $c ] ?? '' );
		foreach ( array( 'energy_kj', 'energy_kcal', 'sugars', 'carbohydrates', 'saturated_fat', 'fat' ) as $c ) {
			if ( ! $exact( $c ) ) {
				unset( $data[ $c ] );
			}
		}
		if ( isset( $data['energy_kj'], $data['energy_kcal'] ) && $data['energy_kcal'] > 0 ) {
			$ratio = $data['energy_kj'] / $data['energy_kcal'];
			if ( abs( $ratio - 4.184 ) > 0.25 ) {
				$w[] = __( 'Energy kJ/kcal ratio deviates from 4.184.', 'wine-nutrition-manager' );
			}
		}
		if ( isset( $data['sugars'], $data['carbohydrates'] ) && $data['sugars'] > $data['carbohydrates'] ) {
			$w[] = __( 'Sugars exceed carbohydrates.', 'wine-nutrition-manager' );
		}
		if ( isset( $data['saturated_fat'], $data['fat'] ) && $data['saturated_fat'] > $data['fat'] ) {
			$w[] = __( 'Saturated fat exceeds fat.', 'wine-nutrition-manager' );
		}
		return $w;
	}

	/** Reverses the export-side formula-injection guard (leading apostrophe). */
	private function strip_csv_guard( string $value ): string {
		if ( strlen( $value ) > 1 && "'" === $value[0] && in_array( $value[1], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return substr( $value, 1 );
		}
		return $value;
	}
}
