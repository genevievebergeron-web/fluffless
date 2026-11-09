<?php
/**
 * Ancre HTML des blocs fluffless/* : l'attribut anchor choisi dans l'éditeur devient l'id du rendu.
 *
 * Le cœur ne pose l'id que par get_block_wrapper_attributes(). La plupart des blocs de la Suite
 * construisent leur enveloppe à la main : l'ancre serait perdue, et un lien « #section » ou une
 * table des matières ne trouverait rien. Un seul filtre de rendu la pose sur la première balise
 * utile de tout bloc fluffless/* qui déclare le support anchor, sans toucher à un id déjà présent.
 *
 * Filtre public : fluffless_suite_anchor_target( ?string $tag_name, array $block ) : nom de la
 * balise (en minuscules) qui doit recevoir l'id quand la première balise n'est pas l'enveloppe ;
 * null (défaut) pour la première balise qui n'est ni style, script, link ni template.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

use WP_Block_Type_Registry;
use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Filtre render_block des blocs fluffless/*.
 */
final class Anchor {

	public const PREFIX = 'fluffless/';

	/**
	 * Balises qui ne peuvent pas servir d'enveloppe : on les saute.
	 */
	private const SKIP = array( 'STYLE', 'SCRIPT', 'LINK', 'TEMPLATE' );

	/**
	 * Ancre validée d'un bloc analysé : non vide, sans espace, support anchor déclaré ; sinon ''.
	 * Même tolérance que le cœur (toute chaîne sans espace), pour qu'une ancre accentuée ou
	 * commençant par un chiffre vaille sur un bloc de la Suite comme sur un bloc du cœur.
	 *
	 * @param array<string, mixed> $block Bloc analysé.
	 */
	public static function of( array $block ): string {
		$name   = $block['blockName'] ?? null;
		$anchor = $block['attrs']['anchor'] ?? null;
		if ( ! is_string( $name ) || ! str_starts_with( $name, self::PREFIX ) || ! is_string( $anchor ) ) {
			return '';
		}
		$anchor = trim( $anchor );
		if ( '' === $anchor || 1 === preg_match( '/\s/u', $anchor ) ) {
			return '';
		}
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( null === $type || ! block_has_support( $type, 'anchor' ) ) {
			return '';
		}
		return $anchor;
	}

	/**
	 * Pose id="<ancre>" sur la balise cible du rendu quand elle n'a pas déjà d'id.
	 *
	 * @param mixed $content Rendu du bloc.
	 * @param mixed $block   Bloc analysé (vérifié : un filtre tiers peut passer n'importe quoi).
	 */
	public static function render( $content, $block ): string {
		$content = (string) $content;
		if ( '' === trim( $content ) || ! is_array( $block ) ) {
			return $content;
		}
		$anchor = self::of( $block );
		if ( '' === $anchor ) {
			return $content;
		}
		/**
		 * Balise qui reçoit l'ancre quand la première balise du rendu n'est pas l'enveloppe.
		 *
		 * @param string|null          $tag_name Nom de balise en minuscules, ou null pour la première balise.
		 * @param array<string, mixed> $block    Bloc analysé.
		 */
		$target = apply_filters( 'fluffless_suite_anchor_target', null, $block );
		$target = is_string( $target ) && '' !== $target ? strtoupper( $target ) : null;
		$tags   = new WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag() ) {
			$tag = (string) $tags->get_tag();
			if ( null === $target ? in_array( $tag, self::SKIP, true ) : $tag !== $target ) {
				continue;
			}
			if ( null !== $tags->get_attribute( 'id' ) ) {
				return $content;
			}
			$tags->set_attribute( 'id', $anchor );
			return $tags->get_updated_html();
		}
		return $content;
	}
}
