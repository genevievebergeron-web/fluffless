<?php
/**
 * Permutation du libellé : copie le texte d'un lien dans l'attribut data-fl-label.
 *
 * Le style de bloc « Permutation » fait glisser le libellé vers le haut pendant qu'une copie prend
 * sa place. La copie est affichée par la feuille (content: attr(data-fl-label) / ""), avec un texte
 * de remplacement vide : un lecteur d'écran lit le libellé réel une seule fois. Rien n'est posé
 * sans le style, ni sur un lien qui contient une image, ni sur un libellé vide ou trop long.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

defined( 'ABSPATH' ) || exit;

/**
 * Filtres de rendu de core/button et de core/navigation.
 */
final class LabelSwap {

	public const STYLE = 'permutation';

	public const ATTRIBUTE = 'data-fl-label';

	/** Longueur maximale, en caractères, d'un libellé copié. */
	public const MAX_TEXT = 120;

	/** Nombre maximal de liens traités dans un bloc. */
	public const MAX_LINKS = 60;

	/** Seuls un lien ou un bouton reçoivent la copie : le <summary> d'un panneau de menu porte déjà sa flèche en ::after. */
	private const HOLDERS = array( 'A', 'BUTTON' );

	/** Balises dont la présence dans un lien écarte la copie : le libellé n'est pas que du texte. */
	private const NOT_TEXT = array( 'IMG', 'SVG', 'PICTURE', 'BR', 'VIDEO' );

	/**
	 * Bouton du cœur qui porte le style.
	 *
	 * @param string|mixed $content HTML rendu du bloc.
	 * @param array|mixed  $block   Bloc analysé.
	 */
	public static function button( $content, $block ): string {
		return self::has_style( $block ) ? self::label_links( (string) $content, 'wp-block-button__link' ) : (string) $content;
	}

	/**
	 * Navigation du cœur qui porte le style : tous ses liens, sous-menus et liste de pages compris.
	 *
	 * @param string|mixed $content HTML rendu du bloc.
	 * @param array|mixed  $block   Bloc analysé.
	 */
	public static function navigation( $content, $block ): string {
		return self::has_style( $block ) ? self::label_links( (string) $content, 'wp-block-navigation-item__content' ) : (string) $content;
	}

	/**
	 * Pose data-fl-label sur chaque élément de la classe donnée dont le contenu est du texte.
	 * Deux passes sans retour en arrière : lire les libellés dans l'ordre, puis les poser dans le même ordre.
	 *
	 * @param string $html       HTML rendu.
	 * @param string $class_name Classe des liens à traiter.
	 */
	public static function label_links( string $html, string $class_name ): string {
		$labels = self::labels( $html, $class_name );
		if ( array() === array_filter( $labels, static fn( string $label ): bool => '' !== $label ) ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		foreach ( $labels as $label ) {
			if ( ! $tags->next_tag( array( 'class_name' => $class_name ) ) ) {
				break;
			}
			if ( '' !== $label ) {
				$tags->set_attribute( self::ATTRIBUTE, $label );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * Libellé à plat de chaque élément de la classe, dans l'ordre du document ; chaîne vide pour
	 * un élément à laisser tel quel (image, libellé vide ou trop long, balise jamais fermée).
	 *
	 * @param string $html       HTML rendu.
	 * @param string $class_name Classe des liens.
	 * @return array<int, string>
	 */
	private static function labels( string $html, string $class_name ): array {
		$labels = array();
		$found  = 0;
		$tags   = new \WP_HTML_Tag_Processor( $html );
		while ( $found < self::MAX_LINKS && $tags->next_tag( array( 'class_name' => $class_name ) ) ) {
			++$found;
			$holder = $tags->get_tag();
			$text   = '';
			$plain  = true;
			$closed = false;
			while ( $tags->next_token() ) {
				if ( '#text' === $tags->get_token_name() ) {
					$text .= $tags->get_modifiable_text();
					continue;
				}
				if ( $tags->is_tag_closer() && $tags->get_tag() === $holder ) {
					$closed = true;
					break;
				}
				// Un lien de la classe dans un autre : l'ordre des deux passes ne tiendrait plus, rien n'est posé.
				if ( true === $tags->has_class( $class_name ) ) {
					return array();
				}
				if ( in_array( $tags->get_tag(), self::NOT_TEXT, true ) ) {
					$plain = false;
				}
			}
			$text     = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
			$labels[] = $closed && $plain && in_array( $holder, self::HOLDERS, true ) && mb_strlen( $text ) <= self::MAX_TEXT ? $text : '';
		}
		return $labels;
	}

	/**
	 * Le bloc porte-t-il le style ?
	 *
	 * @param array|mixed $block Bloc analysé.
	 */
	private static function has_style( $block ): bool {
		$class = is_array( $block ) && is_string( $block['attrs']['className'] ?? null ) ? $block['attrs']['className'] : '';
		return in_array( 'is-style-' . self::STYLE, (array) preg_split( '/\s+/', $class ), true );
	}
}
