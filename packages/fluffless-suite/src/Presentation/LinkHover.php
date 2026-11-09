<?php
/**
 * Survols de liens : source unique du CSS des quatre effets.
 *
 * Le réglage du site (Palette, « Effet de survol des liens ») et les quatre styles de bloc
 * « survol-* » appellent rules() avec une portée différente : aucune feuille copiée. Le même effet
 * répond au survol et au focus clavier, l'anneau de focus du thème n'est pas touché, les boutons et
 * les liens qui contiennent une image sont exclus, et la transition ne vit que sans mouvement
 * réduit (l'état final s'applique alors d'un coup).
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

defined( 'ABSPATH' ) || exit;

/**
 * Fabrique des règles CSS d'un survol de liens pour une portée donnée.
 */
final class LinkHover {

	public const EFFECTS = array( 'surlignage', 'contraste', 'etire', 'incline' );

	/** Préfixe des slugs de style de bloc : « survol-etire » rend l'effet « etire ». */
	public const STYLE_PREFIX = 'survol-';

	/** Portée du réglage du site : liens du contenu et du bloc Navigation. */
	public const SITE_SCOPE = ':is(.wp-block-post-content,.wp-block-navigation)';

	/**
	 * Trait de surligneur : 40 % d'accent sur le fond. Le plein accent sous le texte courant donnait
	 * moins de 3:1 ; à 40 %, le minimum calculé sur les sept palettes du thème et les cinq fonds de
	 * rôle est 4,97:1 (palette Brut, fond Surface).
	 */
	private const MARKER = 'color-mix(in srgb,var(--wp--preset--color--accent) 40%,transparent)';

	/**
	 * Libellés des effets, dans l'ordre du sélecteur.
	 *
	 * @return array<string, string>
	 */
	public static function effects(): array {
		return array(
			'surlignage' => __( 'Live highlight', 'fluffless-suite' ),
			'contraste'  => __( 'Contrast swap', 'fluffless-suite' ),
			'etire'      => __( 'Stretching underline', 'fluffless-suite' ),
			'incline'    => __( 'Tilt', 'fluffless-suite' ),
		);
	}

	/**
	 * Effet rendu par un slug de style de bloc, ou chaîne vide.
	 *
	 * @param string $slug Slug du style (« survol-etire »).
	 */
	public static function effect_of( string $slug ): string {
		$effect = str_starts_with( $slug, self::STYLE_PREFIX ) ? substr( $slug, strlen( self::STYLE_PREFIX ) ) : '';
		return in_array( $effect, self::EFFECTS, true ) ? $effect : '';
	}

	/**
	 * Règles CSS minifiées d'un effet pour les liens d'une portée. Chaîne vide pour un effet inconnu
	 * ou une portée qui n'est pas un sélecteur simple (elle part dans une balise style).
	 *
	 * @param string $effect Effet.
	 * @param string $scope  Sélecteur de l'ancêtre des liens.
	 */
	public static function rules( string $effect, string $scope ): string {
		if ( ! in_array( $effect, self::EFFECTS, true ) || 1 !== preg_match( '/^[a-z0-9_.:(),-]+$/i', $scope ) ) {
			return '';
		}
		// Le réglage du site laisse les blocs qui portent leur propre style de survol : à spécificité égale, les deux effets se mêlaient (texte de la couleur du fond avec surlignage au site et contraste au bloc).
		$own  = self::SITE_SCOPE === $scope ? ',[class*=-' . self::STYLE_PREFIX . '] *' : '';
		$link = $scope . ' a:not(.wp-element-button,:has(img)' . $own . ')';
		$on   = $link . ':is(:hover,:focus-visible)';
		switch ( $effect ) {
			case 'surlignage':
				$moves = array( 'background-size' );
				$rest  = '--fl-t:' . self::MARKER . ';background:linear-gradient(var(--fl-t),var(--fl-t)) 0 100%/0 100% no-repeat;';
				$end   = 'background-size:100% 100%;color:inherit;text-decoration:none';
				break;
			case 'contraste':
				$moves = array( 'background-color', 'color' );
				$rest  = '';
				$end   = 'background:var(--wp--preset--color--contrast);color:var(--wp--preset--color--base)';
				break;
			case 'etire':
				// Le soulignement du thème reste au repos, pâli : un lien ne se distingue jamais par la couleur seule.
				$moves = array( 'background-size' );
				$rest  = 'text-decoration-color:color-mix(in srgb,currentColor 40%,transparent);background:linear-gradient(currentColor,currentColor) 0 100%/0 .1em no-repeat;';
				$end   = 'background-size:100% .1em;text-decoration-color:transparent';
				break;
			default:
				$moves = array( 'transform' );
				$rest  = 'display:inline-block;';
				$end   = 'transform:skewX(-10deg)';
		}
		// La transition s'écrit une fois, à durée nulle par défaut : la durée réelle n'existe que sous « no-preference », ce qui évite de répéter le sélecteur dans la requête média (réglage du site sous 600 o).
		$transitions = array_map( static fn( string $property ): string => $property . ' var(--fl-d,0s)', $moves );
		return $link . '{' . $rest . 'transition:' . implode( ',', $transitions ) . '}' . $on . '{' . $end . '}'
			. '@media (prefers-reduced-motion:no-preference){:root{--fl-d:var(--wp--custom--transition)}}';
	}
}
