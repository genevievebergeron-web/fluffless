<?php
/**
 * Anneau de texte : un SVG en ligne dont le texte suit un cercle (textPath).
 *
 * Sert au style de bloc « Photo cerclée » (la légende de l'image tourne autour de la photo) et se
 * réutilise tel quel pour tout sceau rond : svg() ne connaît ni le bloc ni la feuille de style.
 * Le SVG est décoratif (aria-hidden) : le texte lisible reste celui de la page, ici la figcaption.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

defined( 'ABSPATH' ) || exit;

/**
 * Fabrique de l'anneau et filtre de rendu de core/image.
 */
final class Ring {

	public const STYLE = 'photo-cerclee';

	/** Classe posée sur la figure quand l'anneau est injecté : la feuille masque alors la légende à l'œil. */
	public const MARK = 'has-fl-ring';

	/** Longueur maximale, en caractères, du texte lu dans une légende. */
	public const MAX_TEXT = 200;

	/** Répétitions maximales d'un texte court sur le tour. */
	public const MAX_REPEATS = 12;

	/** Taille du viewBox de la photo cerclée, en unités SVG. */
	private const IMAGE_SIZE = 100;

	/**
	 * Corps du texte en fraction de la taille, et avance d'un caractère en fraction du corps : 0,6 em
	 * mesuré dans Firefox pour la pile mono système, plus une marge pour que la fin ne soit jamais coupée.
	 */
	private const FONT_RATIO    = 0.055;
	private const ADVANCE_RATIO = 0.62;

	private const SEPARATOR = ' • ';

	/**
	 * SVG de l'anneau : le texte, échappé, répété pour remplir le tour ou tronqué s'il le dépasse.
	 *
	 * @param string $text Texte brut (jamais du HTML) : il est échappé ici.
	 * @param int    $size Côté du viewBox, en unités SVG ; le SVG s'étire ensuite par CSS.
	 * @param float  $font_ratio Corps du texte en part du côté : celui de la photo par défaut, plus
	 *                           grand pour un petit sceau qui doit rester lisible. Borné de 0,03 à 0,2.
	 * @return string '' sans texte ou sans taille positive.
	 */
	public static function svg( string $text, int $size, float $font_ratio = self::FONT_RATIO ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text || $size <= 0 ) {
			return '';
		}
		$font     = $size * max( 0.03, min( 0.2, $font_ratio ) );
		$radius   = $size / 2 - $font;
		$turn     = 2 * M_PI * $radius;
		$capacity = max( 1, (int) floor( $turn / ( $font * self::ADVANCE_RATIO ) ) );
		if ( mb_strlen( $text ) + mb_strlen( self::SEPARATOR ) > $capacity ) {
			$text = rtrim( mb_substr( $text, 0, max( 1, $capacity - 2 ) ) ) . '… ';
			$line = $text;
		} else {
			$unit = $text . self::SEPARATOR;
			$line = str_repeat( $unit, min( self::MAX_REPEATS, max( 1, intdiv( $capacity, mb_strlen( $unit ) ) ) ) );
		}
		$id     = Ids::stable( 'fl-ring-', $text . '|' . $size . '|' . $font_ratio );
		$center = self::number( $size / 2 );
		$r      = self::number( $radius );
		$across = self::number( 2 * $radius );
		return '<svg class="fl-ring" viewBox="0 0 ' . $size . ' ' . $size . '" aria-hidden="true" focusable="false">'
			. '<path id="' . esc_attr( $id ) . '" fill="none" d="M' . $center . ' ' . self::number( $font ) . 'a' . $r . ' ' . $r . ' 0 1 1 0 ' . $across . 'a' . $r . ' ' . $r . ' 0 1 1 0 -' . $across . '"/>'
			. '<text font-size="' . self::number( $font ) . '" textLength="' . self::number( floor( $turn ) ) . '"><textPath href="#' . esc_attr( $id ) . '">' . esc_html( $line ) . '</textPath></text>'
			. '</svg>';
	}

	/**
	 * Filtre render_block_core/image : injecte l'anneau quand l'image porte le style et une légende.
	 *
	 * La lecture passe par WP_HTML_Tag_Processor (classe de la figure, texte de la figcaption). Le
	 * processeur ne sait pas insérer de balise : le SVG se glisse devant la dernière fermeture de
	 * figure, qui termine toujours le rendu de core/image, par position et non par expression régulière.
	 *
	 * @param string|mixed $content Rendu du bloc.
	 */
	public static function image( $content ): string {
		$content = (string) $content;
		$close   = strrpos( $content, '</figure>' );
		$tags    = new \WP_HTML_Tag_Processor( $content );
		if ( false === $close || ! $tags->next_tag( array( 'tag_name' => 'figure' ) ) || true !== $tags->has_class( 'is-style-' . self::STYLE ) ) {
			return $content;
		}
		$tags->add_class( self::MARK );
		$svg = self::svg( self::caption( $tags ), self::IMAGE_SIZE );
		if ( '' === $svg ) {
			return $content;
		}
		$marked = $tags->get_updated_html();
		$close  = (int) strrpos( $marked, '</figure>' );
		return substr( $marked, 0, $close ) . $svg . substr( $marked, $close );
	}

	/**
	 * Texte brut de la première figcaption après la position courante, borné à MAX_TEXT caractères.
	 *
	 * @param \WP_HTML_Tag_Processor $tags Processeur placé sur la figure.
	 */
	private static function caption( \WP_HTML_Tag_Processor $tags ): string {
		if ( ! $tags->next_tag( array( 'tag_name' => 'figcaption' ) ) ) {
			return '';
		}
		$text = '';
		while ( mb_strlen( $text ) < self::MAX_TEXT && $tags->next_token() ) {
			if ( 'FIGCAPTION' === $tags->get_tag() && $tags->is_tag_closer() ) {
				break;
			}
			if ( '#text' === $tags->get_token_type() ) {
				$text .= $tags->get_modifiable_text();
			}
		}
		return mb_substr( $text, 0, self::MAX_TEXT );
	}

	/**
	 * Nombre SVG court : deux décimales au plus, sans zéros de fin.
	 *
	 * @param float|int $value Valeur.
	 */
	private static function number( $value ): string {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
	}
}
