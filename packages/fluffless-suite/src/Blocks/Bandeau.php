<?php
/**
 * Bloc fluffless/bandeau : une ligne de mots-clés qui défile, rendue en PHP, CSS en ligne, aucun
 * script sur le site. La vraie liste est lue une fois ; sa copie, qui ferme la boucle, est masquée
 * aux lecteurs d'écran.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Blocks;

use Fluffless\Suite\Modules\Expression;
use Fluffless\Suite\Presentation\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Attributs bornés, rendu et feuilles du bandeau de mots-clés. Enregistré par le module Expressivité.
 */
final class Bandeau {

	public const NAME  = 'fluffless/bandeau';
	public const STYLE = 'fluffless-bandeau';

	public const MIN_ITEMS = 2;
	public const MAX_ITEMS = 12;
	public const MAX_ITEM  = 60;

	/** Séparateurs : clé de l'attribut => caractère, toujours décoratif (aria-hidden). */
	public const SEPARATORS = array(
		'dot'   => '•',
		'star'  => '✦',
		'slash' => '/',
		'arrow' => '→',
	);

	/** Vitesses : secondes par caractère, pour une allure constante quelle que soit la longueur. */
	public const SPEEDS = array(
		'slow'   => 0.5,
		'medium' => 0.3,
		'fast'   => 0.18,
	);

	public const DIRECTIONS = array( 'left', 'right' );
	public const LOOKS      = array( 'accent', 'outline', 'stroke' );
	public const SIZES      = array( 'medium', 'large', 'giant' );

	/** Corps du texte par taille, posé dans --fl-b-s. medium est le défaut de la feuille. */
	public const FONT_SIZES = array(
		'large' => 'clamp(2rem,5vw,3.5rem)',
		'giant' => 'clamp(3rem,10vw,7rem)',
	);

	/** Bornes de la durée d'un tour, en secondes. */
	public const MIN_DURATION = 10;
	public const MAX_DURATION = 180;

	/** Caractères comptés pour chaque séparateur et ses marges dans le calcul de la durée. */
	private const SEPARATOR_WEIGHT = 4;

	private const SPEED = 'medium';

	/**
	 * Mots montrés tant que le bloc en a moins de MIN_ITEMS.
	 *
	 * @return list<string>
	 */
	private static function defaults(): array {
		return array(
			__( 'Strategy', 'fluffless-suite' ),
			__( 'Design', 'fluffless-suite' ),
			__( 'Web', 'fluffless-suite' ),
		);
	}

	/**
	 * Attributs bornés. Toute valeur hors liste reprend son défaut ; une couleur hors palette
	 * devient '' (automatique : accent pour l'allure accent, couleur du texte pour les autres).
	 *
	 * @param array<mixed> $attributes Attributs bruts.
	 * @return array{items: list<string>, separator: string, speed: string, direction: string, look: string, size: string, colour: string, align: string, className: string}
	 */
	public static function attributes( array $attributes ): array {
		$items = array();
		foreach ( is_array( $attributes['items'] ?? null ) ? $attributes['items'] : array() as $item ) {
			$word = is_string( $item ) ? trim( sanitize_text_field( $item ) ) : '';
			if ( '' !== $word ) {
				$items[] = mb_substr( $word, 0, self::MAX_ITEM );
			}
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
		}
		$closed = static function ( string $key, array $allowed ) use ( $attributes ): string {
			$value = $attributes[ $key ] ?? null;
			return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : (string) $allowed[0];
		};
		$colour = $attributes['colour'] ?? null;
		return array(
			'items'     => count( $items ) >= self::MIN_ITEMS ? $items : self::defaults(),
			'separator' => $closed( 'separator', array_keys( self::SEPARATORS ) ),
			'speed'     => is_string( $attributes['speed'] ?? null ) && isset( self::SPEEDS[ $attributes['speed'] ] ) ? $attributes['speed'] : self::SPEED,
			'direction' => $closed( 'direction', self::DIRECTIONS ),
			'look'      => $closed( 'look', self::LOOKS ),
			'size'      => $closed( 'size', self::SIZES ),
			'colour'    => is_string( $colour ) && in_array( $colour, Expression::palette_slugs(), true ) ? $colour : '',
			'align'     => Expression::align( $attributes['align'] ?? null ),
			'className' => Expression::class_list( $attributes['className'] ?? null ),
		);
	}

	/**
	 * Durée d'un tour en secondes entières : proportionnelle au texte, bornée.
	 *
	 * @param array<int, string> $items Mots du bandeau.
	 * @param string             $speed Clé de SPEEDS ; inconnue : medium.
	 */
	public static function duration( array $items, string $speed ): int {
		$length = 0;
		foreach ( $items as $item ) {
			$length += mb_strlen( $item ) + self::SEPARATOR_WEIGHT;
		}
		$seconds = (int) round( $length * ( self::SPEEDS[ $speed ] ?? self::SPEEDS[ self::SPEED ] ) );
		return max( self::MIN_DURATION, min( self::MAX_DURATION, $seconds ) );
	}

	/**
	 * Rendu du bloc. Dans un extrait ou un flux : la liste seule, sans copie ni bouton.
	 *
	 * @param array<mixed>|mixed $attributes Attributs du bloc.
	 */
	public static function render( $attributes ): string {
		$a   = self::attributes( is_array( $attributes ) ? $attributes : array() );
		$sep = '<span class="fl-bd-sep" aria-hidden="true">' . self::SEPARATORS[ $a['separator'] ] . '</span>';
		$lis = '';
		foreach ( $a['items'] as $item ) {
			$lis .= '<li>' . esc_html( $item ) . $sep . '</li>';
		}
		$list = '<ul class="fl-bd-list">' . $lis . '</ul>';
		if ( doing_filter( 'get_the_excerpt' ) || is_feed() ) {
			return $list;
		}

		// Valeurs sorties de constantes ou de calculs entiers, jamais d'une saisie.
		$style  = '--fl-b-d:' . self::duration( $a['items'], $a['speed'] ) . 's';
		$style .= isset( self::FONT_SIZES[ $a['size'] ] ) ? ';--fl-b-s:' . self::FONT_SIZES[ $a['size'] ] : '';
		$style .= 'right' === $a['direction'] ? ';--fl-b-r:reverse' : '';
		if ( 'accent' === $a['look'] ) {
			$background = '' === $a['colour'] ? 'accent' : $a['colour'];
			$style     .= ';--fl-b-bg:var(--wp--preset--color--' . $background . ');--fl-b-fg:var(--wp--preset--color--' . Flottant::text_slug( $background ) . ')';
		} elseif ( '' !== $a['colour'] ) {
			$style .= ';--fl-b-fg:var(--wp--preset--color--' . $a['colour'] . ')';
		}

		$classes = array_filter(
			array(
				'fl-bandeau',
				'is-look-' . $a['look'],
				'' === $a['align'] ? '' : 'align' . $a['align'],
				$a['className'],
			)
		);
		$id      = Ids::stable( 'fl-bandeau-', (string) wp_json_encode( $a ) );

		Expression::enqueue_inline( self::STYLE, self::base_css( true ) );
		Expression::enqueue_inline( self::STYLE . '-' . $a['look'], self::look_css( $a['look'] ) );
		return sprintf(
			'<div class="%1$s" style="%2$s"><input type="checkbox" id="%3$s" class="fl-bd-box"><div class="fl-bd-band"><div class="fl-bd-track">%5$s%6$s</div></div><label for="%3$s" class="fl-bd-pause">%4$s</label></div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $style ),
			esc_attr( $id ),
			esc_html__( 'Pause', 'fluffless-suite' ),
			$list,
			'<ul class="fl-bd-list" aria-hidden="true">' . $lis . '</ul>'
		);
	}

	/**
	 * Feuille complète d'une allure (ou de toutes, pour l'éditeur), avec ou sans le bouton Pause.
	 * Le site charge la base et chaque allure sous sa propre poignée : deux bandeaux d'allures
	 * différentes sur une page ne répètent pas la base.
	 *
	 * @param string $look  Allure, ou '' pour toutes.
	 * @param bool   $pause Avec la part du bouton Pause.
	 */
	public static function css( string $look = '', bool $pause = true ): string {
		$css = self::base_css( $pause );
		foreach ( self::LOOKS as $candidate ) {
			$css .= in_array( $look, array( '', $candidate ), true ) ? self::look_css( $candidate ) : '';
		}
		return $css;
	}

	/**
	 * Base. L'état de départ est immobile : une liste qui se replie, copie masquée. C'est lui que
	 * reçoivent l'impression et le mouvement réduit. Le défilement n'est posé qu'à l'écran et sous
	 * no-preference : la piste fait deux listes de même largeur (au moins la bande chacune) et
	 * glisse de la moitié, la seconde prend donc exactement la place de la première.
	 * list-style vide et non none : Safari retire le rôle de liste avec none.
	 *
	 * @param bool $pause Avec la part du bouton Pause.
	 */
	private static function base_css( bool $pause ): string {
		return '.fl-bandeau{font-size:var(--fl-b-s,1.25rem);font-weight:700;line-height:1.1}'
			. '.fl-bd-band{overflow:hidden;padding:.6em 0;color:var(--fl-b-fg)}'
			. '.fl-bd-list{display:flex;flex-wrap:wrap;justify-content:space-between;row-gap:.25em;margin:0;padding:0 1rem;list-style:"";white-space:nowrap}'
			. '.fl-bd-sep{padding:0 .75em}'
			. ':where(li:last-child)>.fl-bd-sep{display:none}'
			. '.fl-bd-list+.fl-bd-list,.fl-bd-box,.fl-bd-pause{display:none}'
			. '@media screen and (prefers-reduced-motion:no-preference){'
			. '.fl-bd-track{display:flex;width:max-content;min-width:200%;animation:fl-b var(--fl-b-d,40s) linear infinite var(--fl-b-r,normal)}'
			. '.fl-bd-list.fl-bd-list{display:flex;flex:1 0 auto;flex-wrap:nowrap;justify-content:space-around;padding:0}'
			. '.fl-bd-sep{display:inline}'
			. '.fl-bandeau:is(:hover,:focus-within) .fl-bd-track' . ( $pause ? ',:checked~.fl-bd-band>*' : '' ) . '{animation-play-state:paused}'
			. '@keyframes fl-b{to{transform:translateX(-50%)}}'
			. ( $pause ? self::pause_css() : '' )
			. '}';
	}

	/**
	 * Bouton Pause sans script (WCAG 2.2.2), dans le bloc no-preference de la base : montré seulement
	 * quand ça bouge. Une case à cocher gardée focalisable mais invisible (en absolu sans décalage,
	 * elle reste à sa place), placée avant la bande pour la viser par ~, son libellé en bouton sous
	 * la bande, et la piste en pause tant que la case est cochée (sélecteur joint à celui du survol, dans la base). Le carré du libellé se remplit alors.
	 */
	private static function pause_css(): string {
		return '.fl-bd-box{display:block;position:absolute;opacity:0}'
			. '.fl-bd-pause{display:flex;align-items:center;gap:.5em;width:fit-content;min-height:44px;margin:0 1rem 0 auto;font-size:.8rem}'
			. '.fl-bd-pause::before{content:"";padding:.3em;border:2px solid}'
			. ':checked~.fl-bd-pause::before{background:currentColor}'
			. ':focus-visible~.fl-bd-pause{outline:2px solid}';
	}

	/**
	 * Part d'une allure. accent : bande pleine, fond retiré à l'impression (le texte clair y serait
	 * invisible). outline : filets haut et bas. stroke : lettres en contour comme le style « Texte
	 * contour » (remplissage de la couleur du fond, contour en couleur courante peint dessous),
	 * seulement à l'écran, hors couleurs forcées et avec prise en charge : sinon texte plein. La règle
	 * de prise en charge teste paint-order seul : tout moteur qui le connaît connaît aussi
	 * -webkit-text-stroke et -webkit-text-fill-color, arrivés avant lui. La couleur du texte vient
	 * de la base ; sans --fl-b-fg, color est invalide et hérite du contexte.
	 *
	 * @param string $look Allure de LOOKS.
	 */
	private static function look_css( string $look ): string {
		if ( 'accent' === $look ) {
			return '.is-look-accent>.fl-bd-band{background:var(--fl-b-bg)}'
				. '@media print{.is-look-accent>.fl-bd-band{background:none;color:inherit}}';
		}
		if ( 'outline' === $look ) {
			return '.is-look-outline>.fl-bd-band{border-block:2px solid}';
		}
		return '@supports (paint-order:stroke){@media screen and (forced-colors:none){'
			. '.is-look-stroke ul{-webkit-text-fill-color:var(--fl-contour-fond,var(--wp--preset--color--base));-webkit-text-stroke:max(2px,.05em);paint-order:stroke}}}';
	}

	/** Dans l'éditeur : une seule liste, immobile et repliée, sans bouton Pause. */
	public static function editor_css(): string {
		return '.editor-styles-wrapper .fl-bd-track{animation:none;width:auto;min-width:0}'
			. '.editor-styles-wrapper .fl-bd-list{flex-wrap:wrap;justify-content:flex-start}';
	}

	/**
	 * Données du script d'éditeur : mêmes bornes, mêmes séparateurs et mêmes mots par défaut que le
	 * rendu, et le texte lisible pour chaque fond de l'allure accent.
	 *
	 * @return array{separators: array<string, string>, sizes: array<string, string>, defaults: list<string>, text: array<string, string>, minItems: int, maxItems: int, maxItem: int}
	 */
	public static function editor_data(): array {
		$text = array();
		foreach ( Expression::palette_slugs() as $slug ) {
			$text[ $slug ] = Flottant::text_slug( $slug );
		}
		return array(
			'separators' => self::SEPARATORS,
			'sizes'      => self::FONT_SIZES,
			'defaults'   => self::defaults(),
			'text'       => $text,
			'minItems'   => self::MIN_ITEMS,
			'maxItems'   => self::MAX_ITEMS,
			'maxItem'    => self::MAX_ITEM,
		);
	}
}
