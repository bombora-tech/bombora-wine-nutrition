<?php
/**
 * Builds the self-contained nutrition HTML block (inline styles, no plugin CSS/JS needed)
 * and merges it into product descriptions between fixed markers.
 *
 * @package WineNutrition
 */

namespace WineNutrition\Frontend;

use WineNutrition\Services\FieldRegistry;
use WineNutrition\Services\QrService;
use WineNutrition\Services\RecordSanitizer;
use WineNutrition\WPML\LabelTranslator;

defined( 'ABSPATH' ) || exit;

final class BlockRenderer {

	public const START   = '<!-- WINE_NUTRITION_START -->';
	public const END     = '<!-- WINE_NUTRITION_END -->';
	private const PATTERN = '/<!--\s*WINE_NUTRITION_START\s*-->.*?<!--\s*WINE_NUTRITION_END\s*-->/s';

	public function __construct(
		private FieldRegistry $fields,
		private LabelTranslator $labels,
		private QrService $qr
	) {}

	/**
	 * Display-ready data shared by every layout (description card and shortcode).
	 * Returns null when the record has nothing to show.
	 *
	 * @return array{title:string,per:string,rows:array,ingredients_html:string,allergens:string,qr_url:string,t:callable,settings:array}|null
	 */
	public function collect( array $record, ?string $lang ): ?array {
		$settings = $this->fields->settings();
		$labels   = $this->fields->labels();
		$num_lang = $lang ?? LabelTranslator::site_language();
		$t        = fn( string $key ) => $this->labels->translate( 'label_' . $key, $labels[ $key ], $lang );

		$rows = array();
		foreach ( FieldRegistry::standard() as $key => $def ) {
			if ( ! in_array( $key, $this->fields->enabled_standard(), true ) ) {
				continue;
			}
			if ( 'energy' === $key ) {
				$parts = array();
				if ( null !== ( $record['energy_kj'] ?? null ) ) {
					$parts[] = $this->value( $record, 'energy_kj', 'energy', $num_lang, (bool) $settings['eu_rounding'] ) . ' kJ';
				}
				if ( null !== ( $record['energy_kcal'] ?? null ) ) {
					$parts[] = $this->value( $record, 'energy_kcal', 'energy', $num_lang, (bool) $settings['eu_rounding'] ) . ' kcal';
				}
				if ( $parts ) {
					$rows[] = array( $t( 'energy' ), implode( ' / ', $parts ), false );
				}
				continue;
			}
			$col = $def['columns'][0];
			if ( null === ( $record[ $col ] ?? null ) ) {
				continue;
			}
			$rows[] = array( $t( $key ), $this->value( $record, $col, $def['rule'], $num_lang, (bool) $settings['eu_rounding'] ) . ' g', $def['sub'] );
		}

		foreach ( $this->fields->custom_definitions() as $def ) {
			$value = (string) ( $record['custom_fields'][ $def['key'] ] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			$rows[] = array( $this->labels->translate( 'custom_' . $def['key'], $def['label'], $lang ), $value, false );
		}

		$ingredients = $this->fields->text_enabled( 'ingredients' ) ? trim( (string) ( $record['ingredients'] ?? '' ) ) : '';
		$allergens   = $this->fields->allergens_enabled() ? trim( (string) ( $record['allergens'] ?? '' ) ) : '';
		// Per-language text from WPML String Translation (one record per SKU stays the source).
		$ingredients = $this->labels->translate_content( 'ingredients', $ingredients, $lang );
		$allergens   = $this->labels->translate_content( 'allergens', $allergens, $lang );
		$qr_url      = $this->fields->text_enabled( 'qr_url' ) ? trim( (string) ( $record['qr_url'] ?? '' ) ) : '';

		if ( ! $rows && '' === $ingredients && '' === $allergens && '' === $qr_url ) {
			return null;
		}

		return array(
			'title'            => $t( 'title' ),
			'per'              => $t( 'per' ),
			'rows'             => $rows,
			'ingredients_html' => '' === $ingredients ? '' : $this->ingredients_html( $ingredients, $allergens, (bool) $settings['emphasize_allergens'] ),
			'allergens'        => $allergens,
			'qr_url'           => $qr_url,
			't'                => $t,
			'settings'         => $settings,
		);
	}

	public function qr_image_url( string $url ): ?string {
		$img = $this->qr->image_url( $url );
		return $img ? (string) $img : null;
	}

	/**
	 * Compact layout used by the [wine_nutrition] shortcode and the product meta snapshot.
	 * Inline styles only (works with the plugin deactivated and survives wp_kses_post).
	 * Customise via CSS variables on .wnm-compact or any ancestor:
	 * --wnm-padding-left, --wnm-label-width, --wnm-gap, --wnm-sub-indent, --wnm-qr-size.
	 *
	 * @param array $o layout, title(bool), tag, ingredients(bool), allergens(bool), qr(both|image|link|no), class
	 */
	public function render_compact( array $record, ?string $lang, array $o = array() ): string {
		$data = $this->collect( $record, $lang );
		if ( null === $data ) {
			return '';
		}
		$o = array_merge( $this->compact_defaults(), $o );
		$t = $data['t'];

		$inline  = 'inline' === $o['layout'];
		$tag     = in_array( $o['tag'], array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div' ), true ) ? $o['tag'] : 'h4';
		$classes = array( 'wnm-compact', 'wnm-compact--' . ( $inline ? 'inline' : 'table' ) );
		foreach ( preg_split( '/\s+/', (string) $o['class'] ) as $c ) {
			$c = sanitize_html_class( $c );
			if ( '' !== $c ) {
				$classes[] = $c;
			}
		}

		$html = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" style="margin:1.25em 0 0;padding-left:var(--wnm-padding-left,30px);">';

		if ( $o['title'] && $data['rows'] ) {
			$html .= '<' . $tag . ' class="wnm-compact__title" style="margin:0 0 .5em;font-size:1em;font-weight:700;line-height:1.3;">' . esc_html( $data['title'] )
				. ' <span class="wnm-compact__per" style="font-weight:400;">(' . esc_html( $data['per'] ) . ')</span></' . $tag . '>';
		}

		if ( $data['rows'] ) {
			if ( $inline ) {
				$parts = array();
				foreach ( $data['rows'] as [ $label, $value, $sub ] ) {
					$parts[] = '<span class="wnm-compact__item"><span class="wnm-compact__label" style="font-weight:' . ( $sub ? '400' : '700' ) . ';">' . esc_html( $label ) . '</span> ' . esc_html( $value ) . '</span>';
				}
				$html .= '<p class="wnm-compact__line" style="margin:0;">' . implode( ' · ', $parts ) . '</p>';
			} else {
				$cell  = 'border:0;background:none;padding:var(--wnm-gap,.15em) 0;text-align:left;vertical-align:top;';
				$html .= '<table class="wnm-compact__table" aria-label="' . esc_attr( $data['title'] . ' (' . $data['per'] . ')' ) . '" style="width:100%;border-collapse:collapse;border:0;margin:0;background:none;"><tbody>';
				foreach ( $data['rows'] as [ $label, $value, $sub ] ) {
					$th    = $cell . 'width:var(--wnm-label-width,40%);padding-right:1em;' . ( $sub ? 'padding-left:var(--wnm-sub-indent,.9em);font-weight:400;' : 'font-weight:700;' );
					$html .= '<tr><th scope="row" style="' . esc_attr( $th ) . '">' . esc_html( $label ) . '</th><td style="' . esc_attr( $cell ) . '">' . esc_html( $value ) . '</td></tr>';
				}
				$html .= '</tbody></table>';
			}
		}

		if ( $o['ingredients'] && '' !== $data['ingredients_html'] ) {
			$html .= '<p class="wnm-compact__text" style="margin:.5em 0 0;"><span class="wnm-compact__label" style="font-weight:700;">' . esc_html( $t( 'ingredients' ) ) . ':</span> ' . $data['ingredients_html'] . '</p>';
		}
		if ( $o['allergens'] && '' !== $data['allergens'] ) {
			$html .= '<p class="wnm-compact__text" style="margin:.5em 0 0;"><span class="wnm-compact__label" style="font-weight:700;">' . esc_html( $t( 'allergens' ) ) . ':</span> ' . esc_html( $data['allergens'] ) . '</p>';
		}

		$elabel = $this->elabel_html( $data, (string) $o['qr'], 'wnm-compact', 'margin:.75em 0 0;' );
		if ( '' !== $elabel ) {
			$html .= $elabel;
		}

		return $html . '</div>';
	}

	/** 'both' | 'image' | 'link' | 'no' from the two settings. */
	public function qr_mode( array $s ): string {
		$img  = ! empty( $s['show_qr_image'] );
		$link = ! empty( $s['show_elabel_link'] ?? 1 );
		return $img && $link ? 'both' : ( $img ? 'image' : ( $link ? 'link' : 'no' ) );
	}

	/**
	 * E-label output: one link wrapping the QR image and/or the text. Image-only gets the
	 * label as alt text so the link keeps an accessible name. kses-safe inline CSS only.
	 */
	private function elabel_html( array $data, string $mode, string $base, string $margin ): string {
		if ( '' === $data['qr_url'] || ! in_array( $mode, array( 'both', 'image', 'link' ), true ) ) {
			return '';
		}
		$label = (string) ( $data['t'] )( 'elabel' );
		$img   = 'link' !== $mode ? $this->qr->image_url( $data['qr_url'] ) : null;
		if ( 'image' === $mode && ! $img ) {
			return ''; // image requested but not generated; nothing to show
		}
		$inner = '';
		if ( $img ) {
			$size   = max( 60, min( 300, (int) $data['settings']['qr_size'] ) );
			$alt    = 'image' === $mode ? $label : '';
			$gap    = 'both' === $mode ? '0 .75em 0 0' : '0';
			$inner .= '<img class="' . $base . '__qr" src="' . esc_url( $img ) . '" alt="' . esc_attr( $alt ) . '" width="' . $size . '" height="' . $size . '" loading="lazy" style="vertical-align:middle;width:' . $size . 'px;height:' . $size . 'px;max-width:none;margin:' . $gap . ';padding:4px;background:#fff;border-radius:6px;">';
		}
		if ( 'image' !== $mode ) {
			$inner .= ( $img ? ' ' : '' ) . '<span class="' . $base . '__label-text">' . esc_html( $label ) . '</span>';
		}
		return '<p class="' . $base . '__elabel" style="' . $margin . '"><a class="' . $base . '__link" href="' . esc_url( $data['qr_url'] ) . '" target="_blank" rel="noopener nofollow">' . $inner . '</a></p>';
	}

	/** Shortcode / snapshot defaults, from settings. */
	public function compact_defaults(): array {
		$s = $this->fields->settings();
		return array(
			'layout'      => 'inline' === ( $s['compact_layout'] ?? 'table' ) ? 'inline' : 'table',
			'title'       => true,
			'tag'         => 'h4',
			'ingredients' => true,
			'allergens'   => ! empty( $s['compact_allergens'] ),
			'qr'          => $this->qr_mode( $s ),
			'class'       => '',
		);
	}

	/**
	 * Full block including markers, or '' when the record has nothing to show.
	 */
	public function render( array $record, ?string $lang ): string {
		$data = $this->collect( $record, $lang );
		if ( null === $data ) {
			return '';
		}
		$t           = $data['t'];
		$settings    = $data['settings'];
		$rows        = $data['rows'];
		$allergens   = $data['allergens'];
		$qr_url      = $data['qr_url'];
		$ingredients = $data['ingredients_html'];

		$title = $t( 'title' );
		$html  = '<div class="wnm-nutrition" style="background:#f8f8f8;color:#222;border-radius:12px;padding:20px;margin:20px 0 0;max-width:560px;box-sizing:border-box;font-size:15px;line-height:1.5;">';

		if ( $rows ) {
			$html .= '<h3 class="wnm-nutrition__title" style="margin:0 0 10px;padding:0;font-size:1.1em;line-height:1.3;color:inherit;">'
				. esc_html( $title ) . ' <span style="font-weight:400;">(' . esc_html( $t( 'per' ) ) . ')</span></h3>';
			$html .= '<table class="wnm-nutrition__table" aria-label="' . esc_attr( $title ) . '" style="width:100%;border-collapse:collapse;border:0;margin:0;background:transparent;"><tbody>';
			foreach ( $rows as [ $label, $value, $sub ] ) {
				$th_style = 'text-align:left;border:0;border-bottom:1px solid #e3e3e3;background:transparent;padding:6px 8px 6px ' . ( $sub ? '16px;font-weight:400;' : '0;font-weight:600;' );
				$html    .= '<tr><th scope="row" style="' . esc_attr( $th_style ) . '">' . esc_html( $label ) . '</th>'
					. '<td style="text-align:right;white-space:nowrap;border:0;border-bottom:1px solid #e3e3e3;background:transparent;padding:6px 0;">' . esc_html( $value ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}

		if ( '' !== $ingredients ) {
			$html .= '<p class="wnm-nutrition__ingredients" style="margin:12px 0 0;"><strong>' . esc_html( $t( 'ingredients' ) ) . ':</strong> '
				. $ingredients . '</p>';
		}

		if ( '' !== $allergens ) {
			$html .= '<p class="wnm-nutrition__allergens" style="margin:8px 0 0;"><strong>' . esc_html( $t( 'allergens' ) ) . ':</strong> ' . esc_html( $allergens ) . '</p>';
		}

		$html .= $this->elabel_html( $data, $this->qr_mode( $settings ), 'wnm-nutrition', 'margin:12px 0 0;' );

		$html .= '</div>';

		/**
		 * Filters the rendered block HTML (without markers).
		 *
		 * @param string      $html
		 * @param array       $record
		 * @param string|null $lang
		 */
		$html = (string) apply_filters( 'wnm_block_html', $html, $record, $lang );

		return self::START . "\n" . $html . "\n" . self::END;
	}

	/**
	 * Replace the existing marker block (keeping its position), or append.
	 * Content outside the markers is never modified. Returns null when markers are broken.
	 */
	public static function merge( string $content, string $block ): ?string {
		$has_start = false !== strpos( $content, 'WINE_NUTRITION_START' );
		$has_end   = false !== strpos( $content, 'WINE_NUTRITION_END' );

		if ( preg_match( self::PATTERN, $content ) ) {
			$first   = true;
			$content = preg_replace_callback(
				self::PATTERN,
				static function () use ( &$first, $block ) {
					if ( $first ) {
						$first = false;
						return $block;
					}
					return ''; // Remove accidental duplicates.
				},
				$content
			);
			return '' === $block ? rtrim( (string) $content ) : (string) $content;
		}

		if ( $has_start || $has_end ) {
			return null; // Unpaired marker: refuse to touch the content.
		}

		if ( '' === $block ) {
			return $content;
		}

		return ( '' === trim( $content ) ? '' : rtrim( $content ) . "\n\n" ) . $block;
	}

	/**
	 * EU rounding guidance (European Commission, 2012) with locale decimal separator.
	 */
	/**
	 * Formatted value incl. qualifier ("< 3,2"). Qualified values are shown as entered
	 * (no EU rounding), since rounding a bound could turn "< 0.3" into "< 0".
	 */
	private function value( array $record, string $col, string $rule, string $lang, bool $eu_rounding ): string {
		$op = (string) ( $record['qualifiers'][ $col ] ?? '' );
		if ( ! in_array( $op, FieldRegistry::QUALIFIERS, true ) ) {
			$negligible = (string) ( $this->fields->settings()['eu_negligible'] ?? 'zero' );
			return $this->format( (float) $record[ $col ], $rule, $lang, $eu_rounding, $negligible );
		}
		return $op . "\u{00A0}" . $this->format( (float) $record[ $col ], $rule, $lang, false );
	}

	/** EU guidance thresholds below which an amount is "negligible" (shown as 0 or "< x"). */
	private const NEGLIGIBLE = array(
		'macro'     => array( 0.5, false ),  // value < 0.5
		'saturates' => array( 0.1, false ),  // value < 0.1
		'salt'      => array( 0.0125, true ), // value <= 0.0125, declared as "< 0.01"
	);

	/**
	 * @param string $negligible 'zero' → "0", 'less_than' → "< 0,5" (both allowed by EU guidance).
	 */
	public function format( float $value, string $rule, string $lang, bool $eu_rounding, string $negligible = 'zero' ): string {
		if ( $eu_rounding && 'less_than' === $negligible && $value > 0 && isset( self::NEGLIGIBLE[ $rule ] ) ) {
			[ $limit, $inclusive ] = self::NEGLIGIBLE[ $rule ];
			if ( $inclusive ? $value <= $limit : $value < $limit ) {
				$shown = 'salt' === $rule ? 0.01 : $limit;
				return "<\u{00A0}" . str_replace( '.', $this->decimal_separator( $lang ), (string) $shown );
			}
		}
		if ( $eu_rounding ) {
			$value = match ( $rule ) {
				'energy'    => round( $value ),
				'macro'     => $value >= 10 ? round( $value ) : ( $value < 0.5 ? 0.0 : round( $value, 1 ) ),
				'saturates' => $value >= 10 ? round( $value ) : ( $value < 0.1 ? 0.0 : round( $value, 1 ) ),
				'salt'      => $value >= 1 ? round( $value, 1 ) : ( $value <= 0.0125 ? 0.0 : round( $value, 2 ) ),
				default     => round( $value, 2 ),
			};
		}
		$str = rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
		if ( '' === $str || '-0' === $str ) {
			$str = '0';
		}
		return str_replace( '.', $this->decimal_separator( $lang ), $str );
	}

	private function decimal_separator( string $lang ): string {
		$dot = array( 'en', 'ja', 'zh', 'ko', 'he', 'th' );
		$sep = in_array( strtolower( substr( $lang, 0, 2 ) ), $dot, true ) ? '.' : ',';
		return (string) apply_filters( 'wnm_decimal_separator', $sep, $lang );
	}

	/**
	 * Ingredients with allergens emphasised (bold), as Reg. (EU) 1169/2011 Art. 21 requires.
	 * Manual <strong>/<b> markup takes precedence over automatic emphasis.
	 */
	private function ingredients_html( string $ingredients, string $allergens, bool $auto ): string {
		$html = wp_kses( $ingredients, RecordSanitizer::INGREDIENT_TAGS );
		if ( ! $auto || '' === $allergens || preg_match( '/<(strong|b)>/i', $html ) ) {
			return $html;
		}
		$terms = array_filter( array_map( 'trim', preg_split( '/[,;\n]+/u', $allergens ) ), static fn( $t ) => mb_strlen( $t ) >= 3 );
		usort( $terms, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		foreach ( $terms as $term ) {
			$pattern = '/(?<![\p{L}>])(' . preg_quote( esc_html( $term ), '/' ) . ')(?![\p{L}<])/iu';
			$html    = (string) preg_replace( $pattern, '<strong>$1</strong>', $html );
		}
		return $html;
	}
}
