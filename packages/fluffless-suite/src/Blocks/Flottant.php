<?php
/**
 * Bloc fluffless/flottant : un élément fixé dans un coin de l'écran (bouton, sceau qui tourne
 * ou pastille d'état), rendu en PHP, CSS en ligne, aucun script sur le site.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Blocks;

use Fluffless\Suite\Modules\Expression;
use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Presentation\Ring;

defined( 'ABSPATH' ) || exit;

/**
 * Attributs bornés, rendu et feuille de l'élément flottant. Enregistré par le module Expressivité.
 */
final class Flottant {

	public const NAME  = 'fluffless/flottant';
	public const STYLE = 'fluffless-flottant';

	public const MODES   = array( 'bouton', 'sceau', 'pastille' );
	public const CORNERS = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );

	/** Liste courte d'icônes : clé de l'attribut => nom dans la liste curée du bloc Icône. */
	public const ICONS = array(
		'calendar' => 'calendar',
		'phone'    => 'phone',
		'bubble'   => 'message-circle',
		'arrow'    => 'arrow-right',
	);

	public const COLOUR   = 'accent';
	public const MAX_TEXT = 80;

	/** Adresses permises, en plus des liens relatifs et des ancres. */
	private const PROTOCOLS = array( 'http', 'https', 'mailto', 'tel' );

	/** Rôles de texte essayés quand la paire prévue par Palette ne tient pas 4,5:1. */
	private const TEXT_ROLES = array( 'contrast', 'base', 'on-accent' );

	/** Côté du viewBox du sceau et corps du texte en part du côté : 14 px pour un sceau de 8 rem. */
	private const SEAL_SIZE       = 100;
	private const SEAL_FONT_RATIO = 0.11;

	/**
	 * Vrai dès qu'un élément flottant a été rendu dans la requête : les suivants sont ignorés.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/** Remet le compteur à zéro (tests). */
	public static function reset(): void {
		self::$rendered = false;
	}

	/**
	 * Attributs bornés. Toute valeur hors liste reprend son défaut, une adresse refusée devient ''.
	 *
	 * @param array<mixed> $attributes Attributs bruts.
	 * @return array{mode: string, text: string, url: string, newTab: bool, icon: string, mobileBar: bool, corner: string, colour: string, className: string}
	 */
	public static function attributes( array $attributes ): array {
		$mode   = $attributes['mode'] ?? null;
		$corner = $attributes['corner'] ?? null;
		$colour = $attributes['colour'] ?? null;
		$icon   = $attributes['icon'] ?? null;
		$text   = is_string( $attributes['text'] ?? null ) ? trim( sanitize_text_field( $attributes['text'] ) ) : '';
		$url    = is_string( $attributes['url'] ?? null ) ? esc_url_raw( trim( $attributes['url'] ), self::PROTOCOLS ) : '';
		return array(
			'mode'      => in_array( $mode, self::MODES, true ) ? (string) $mode : self::MODES[0],
			'text'      => '' === $text ? __( 'Book a call', 'fluffless-suite' ) : mb_substr( $text, 0, self::MAX_TEXT ),
			'url'       => $url,
			'newTab'    => true === ( $attributes['newTab'] ?? false ),
			'icon'      => is_string( $icon ) && isset( self::ICONS[ $icon ] ) ? $icon : '',
			'mobileBar' => true === ( $attributes['mobileBar'] ?? false ),
			'corner'    => in_array( $corner, self::CORNERS, true ) ? (string) $corner : self::CORNERS[0],
			'colour'    => is_string( $colour ) && in_array( $colour, Expression::palette_slugs(), true ) ? $colour : self::COLOUR,
			'className' => Expression::class_list( $attributes['className'] ?? null ),
		);
	}

	/**
	 * Couleurs #rrggbb de la palette active, par identifiant. Une couleur écrite autrement
	 * (variable, dégradé, rgb) est laissée de côté : son contraste ne se calcule pas ici.
	 *
	 * @return array<string, string>
	 */
	public static function palette_hex(): array {
		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		$hex     = array();
		foreach ( is_array( $palette ) ? $palette : array() as $origin ) {
			foreach ( is_array( $origin ) ? $origin : array() as $entry ) {
				$slug   = is_array( $entry ) && is_string( $entry['slug'] ?? null ) ? $entry['slug'] : '';
				$colour = is_array( $entry ) && is_string( $entry['color'] ?? null ) ? strtolower( $entry['color'] ) : '';
				if ( '' !== $slug && 1 === preg_match( '/^#[0-9a-f]{6}$/', $colour ) ) {
					$hex[ $slug ] = $colour;
				}
			}
		}
		return $hex;
	}

	/**
	 * Rôle de texte lisible sur un fond : la paire prévue par Palette si elle tient 4,5:1 sur la
	 * palette active, sinon le meilleur des rôles de texte. Sans couleur calculable : contrast.
	 *
	 * @param string $background Identifiant du fond.
	 */
	public static function text_slug( string $background ): string {
		$hex    = self::palette_hex();
		$paired = Palette::ALTERNATE_ROLES[ $background ] ?? '';
		if ( ! isset( $hex[ $background ] ) ) {
			return '' !== $paired ? $paired : 'contrast';
		}
		if ( isset( $hex[ $paired ] ) && Palette::ratio( $hex[ $background ], $hex[ $paired ] ) >= 4.5 ) {
			return $paired;
		}
		$best  = 'contrast';
		$ratio = 0.0;
		// Les rôles de texte d'abord ; le reste de la palette seulement si aucun ne tient 4,5:1.
		foreach ( array( self::TEXT_ROLES, array_keys( $hex ) ) as $candidates ) {
			foreach ( $candidates as $role ) {
				$found = isset( $hex[ $role ] ) ? Palette::ratio( $hex[ $background ], $hex[ $role ] ) : 0.0;
				if ( $found > $ratio ) {
					$best  = (string) $role;
					$ratio = $found;
				}
			}
			if ( $ratio >= 4.5 ) {
				break;
			}
		}
		return $best;
	}

	/**
	 * Rendu du bloc. Rien si un élément flottant est déjà sur la page, dans un extrait, ou si le
	 * bouton n'a pas d'adresse.
	 *
	 * @param array<mixed>|mixed $attributes Attributs du bloc.
	 */
	public static function render( $attributes ): string {
		$a = self::attributes( is_array( $attributes ) ? $attributes : array() );
		if ( self::$rendered || doing_filter( 'get_the_excerpt' ) || ( 'bouton' === $a['mode'] && '' === $a['url'] ) ) {
			return '';
		}
		self::$rendered = true;

		$icon = '' === $a['icon'] ? '' : '<span class="fl-flottant__icon">' . Icone::svg( self::ICONS[ $a['icon'] ] ) . '</span>';
		if ( 'sceau' === $a['mode'] ) {
			$inner = '<span class="fl-flottant__sr">' . esc_html( $a['text'] ) . '</span>' . Ring::svg( $a['text'], self::SEAL_SIZE, self::SEAL_FONT_RATIO ) . $icon;
		} elseif ( 'pastille' === $a['mode'] ) {
			$inner = '<span class="fl-flottant__dot" aria-hidden="true"></span><span>' . esc_html( $a['text'] ) . '</span>';
		} else {
			$inner = $icon . '<span>' . esc_html( $a['text'] ) . '</span>';
		}

		if ( '' === $a['url'] ) {
			$item = '<span class="fl-flottant__link">' . $inner . '</span>';
		} else {
			$item = '<a class="fl-flottant__link" href="' . esc_url( $a['url'], self::PROTOCOLS ) . '"' . ( $a['newTab'] ? ' target="_blank" rel="noopener"' : '' ) . '>' . $inner . '</a>';
		}

		$classes = array_filter(
			array(
				'fl-flottant',
				'fl-flottant--' . $a['mode'],
				'is-' . $a['corner'],
				$a['mobileBar'] && 'bouton' === $a['mode'] ? 'has-bar' : '',
				$a['className'],
			)
		);
		Expression::enqueue_inline( self::STYLE, self::css( $a['mode'], $a['mobileBar'] ) );
		return sprintf(
			'<div class="%1$s" style="--fl-f-bg:var(--wp--preset--color--%2$s);--fl-f-fg:var(--wp--preset--color--%3$s)">%4$s</div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $a['colour'] ),
			esc_attr( self::text_slug( $a['colour'] ) ),
			$item
		);
	}

	/**
	 * Feuille du bloc, réduite à ce que la page montre : la base, puis la part du mode et celle de
	 * la barre mobile seulement si elles servent (un seul élément par page). Sans mode : tout, pour
	 * l'éditeur. Le calque fixe couvre l'écran sans capter les clics (pointer-events none), le coin
	 * vient de l'alignement flex et les marges tiennent compte des zones sûres. z-index 10 : sous le
	 * panneau de menu du thème (20), son entête collant (30) et le bandeau de Consent (100000).
	 * max-width none, sélecteur doublé : une mise en page contrainte bornerait le calque à la colonne
	 * de contenu, et la marge de bloc du flux décalerait un coin du haut (vus dans Firefox). L'anneau de focus est à l'intérieur, dans la couleur du texte : son contraste avec le fond est
	 * celui du texte, quel que soit le fond de la page. Tout ce qui bouge est sous no-preference.
	 *
	 * @param string $mode Mode rendu, ou '' pour toutes les parts.
	 * @param bool   $bar  Barre mobile demandée.
	 */
	public static function css( string $mode = '', bool $bar = true ): string {
		$css = '.fl-flottant.fl-flottant{position:fixed;inset:0;max-width:none;margin:0;z-index:10;display:flex;align-items:flex-start;pointer-events:none;'
			. 'padding:max(1rem,env(safe-area-inset-top)) max(1rem,env(safe-area-inset-right)) max(1rem,env(safe-area-inset-bottom)) max(1rem,env(safe-area-inset-left))}'
			. '.fl-flottant.is-bottom-right,.fl-flottant.is-bottom-left{align-items:flex-end}'
			. '.fl-flottant.is-bottom-right,.fl-flottant.is-top-right{justify-content:flex-end}'
			. '.fl-flottant__link{pointer-events:auto;position:relative;display:flex;align-items:center;justify-content:center;gap:.5em;box-sizing:border-box;min-width:44px;min-height:44px;padding:.75rem 1.25rem;border-radius:99px;'
			. 'background:var(--fl-f-bg);color:var(--fl-f-fg);font-size:.9375rem;font-weight:600;line-height:1.2;text-decoration:none;box-shadow:0 2px 8px rgb(0 0 0/.2)}'
			. 'a.fl-flottant__link:hover{text-decoration:underline}'
			. '.fl-flottant__link:focus-visible{outline:2px solid;outline-offset:-5px}'
			. '.fl-flottant__icon{display:flex;font-size:1.25em}'
			. '@media print{.fl-flottant{display:none}}';
		if ( in_array( $mode, array( '', 'sceau' ), true ) ) {
			$css .= '.fl-flottant__sr{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}'
				. '.fl-flottant--sceau .fl-flottant__link{width:8rem;height:8rem;padding:.25rem;border-radius:50%}'
				. '.fl-flottant .fl-ring{width:100%;height:100%;fill:currentColor}'
				. '.fl-flottant--sceau .fl-flottant__icon{position:absolute;font-size:2rem}'
				. '@media (prefers-reduced-motion:no-preference){.fl-flottant .fl-ring{animation:fl-f-turn 30s linear infinite}@keyframes fl-f-turn{to{transform:rotate(1turn)}}}';
		}
		if ( in_array( $mode, array( '', 'pastille' ), true ) ) {
			$css .= '.fl-flottant__dot,.fl-flottant__dot::after{flex:none;width:.625rem;height:.625rem;border-radius:50%;background:currentColor}'
				. '.fl-flottant__dot::after{content:"";position:absolute}'
				. '@media (prefers-reduced-motion:no-preference){.fl-flottant__dot::after{animation:fl-f-pulse 2s ease-out infinite}@keyframes fl-f-pulse{to{transform:scale(3);opacity:0}}}';
		}
		if ( $bar && in_array( $mode, array( '', 'bouton' ), true ) ) {
			$css .= '@media (max-width:599px){.fl-flottant.has-bar{padding:0;align-items:flex-end}'
				. '.has-bar .fl-flottant__link{width:100%;border-radius:0;padding-bottom:max(.75rem,env(safe-area-inset-bottom))}'
				. 'html:has(.fl-flottant.has-bar){scroll-padding-bottom:4rem}body:has(.fl-flottant.has-bar){padding-bottom:3.5rem}}';
		}
		return $css;
	}

	/** Dans l'éditeur : l'élément reste à sa place dans le canevas, sans calque fixe. */
	public static function editor_css(): string {
		return '.editor-styles-wrapper .fl-flottant{position:static;padding:0}'
			. '.editor-styles-wrapper .fl-flottant--sceau .fl-flottant__link{flex-direction:column;font-size:.75rem;text-align:center}'
			. '.editor-styles-wrapper .fl-flottant--sceau .fl-flottant__icon{position:static}';
	}

	/**
	 * Données du script d'éditeur : icônes de la liste courte et texte lisible pour chaque fond.
	 *
	 * @return array{icons: array<string, string>, text: array<string, string>, colour: string, maxText: int}
	 */
	public static function editor_data(): array {
		$text = array();
		foreach ( Expression::palette_slugs() as $slug ) {
			$text[ $slug ] = self::text_slug( $slug );
		}
		return array(
			'icons'   => array_map( array( Icone::class, 'svg' ), self::ICONS ),
			'text'    => $text,
			'colour'  => self::COLOUR,
			'maxText' => self::MAX_TEXT,
		);
	}
}
