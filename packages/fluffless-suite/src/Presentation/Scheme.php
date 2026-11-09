<?php
/**
 * Mode clair ou sombre selon l'appareil : la jumelle de la palette en place, calculée à
 * l'affichage (jamais enregistrée), et la feuille qui sert l'une ou l'autre avec light-dark().
 * Règles du générateur (assets/admin/palette-core.js), rigueur maximale. Voir
 * docs/mode-clair-sombre-2026-10.md.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

use Fluffless\Suite\Modules\Palette;

defined( 'ABSPATH' ) || exit;

/**
 * Fonctions pures de couleur, plus le réglage gardé dans l'option du module Palette.
 */
final class Scheme {

	/** Clé du réglage dans l'option du module Palette. */
	public const KEY = 'scheme';

	/** Luminance sous laquelle une palette est sombre (même seuil que le générateur). */
	public const DARK_BELOW = 0.18;

	/** Texte/fond au moins à ce ratio : palette à contraste élevé, gardée aux bornes. */
	public const HIGH_CONTRAST = 15.0;

	/** Paires de la bordure des champs, à 3:1 (les mêmes que tools/check-contrast.mjs). */
	public const DIVIDER_PAIRS = array(
		array( 'divider', 'base' ),
		array( 'divider', 'surface' ),
	);

	/** Luminosité visée sur fond sombre (milieu de STRICT.max) ; le miroir 100 − L vers le clair. */
	private const LIGHTNESS = array(
		'base'      => 9,
		'surface'   => 15,
		'contrast'  => 90,
		'muted'     => 46,
		'accent'    => 50,
		'accent-2'  => 44,
		'alert'     => 50,
		'button-bg' => 50,
	);

	/** Rôles de texte, dans l'ordre du calcul. */
	private const TEXT_ROLES = array( 'contrast', 'muted', 'accent', 'accent-2', 'alert', 'button-bg' );

	/** Bornes de luminosité : jamais de noir ni de blanc purs. */
	private const MIN_L = 6;
	private const MAX_L = 94;

	private const AA = 4.5;
	private const UI = 3.0;

	/**
	 * Résultats déjà calculés dans la requête, par palette et style de bouton.
	 *
	 * @var array<string, array{twin: array<string, string>|null, failures: list<string>}>
	 */
	private static array $memo = array();

	/** Vide la mémoire de la requête (tests). */
	public static function reset(): void {
		self::$memo = array();
	}

	/* ---------- Réglage ---------- */

	/** La case « Suivre le réglage clair ou sombre de l'appareil » est-elle cochée ? */
	public static function enabled(): bool {
		$stored = get_option( Palette::OPTION );
		return is_array( $stored ) && true === ( $stored[ self::KEY ] ?? false );
	}

	/**
	 * Coche ou décoche ; décoché, rien n'est gardé dans l'option.
	 *
	 * @param bool $on Nouvel état.
	 */
	public static function save( bool $on ): void {
		$stored = get_option( Palette::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		if ( $on ) {
			$stored[ self::KEY ] = true;
		} else {
			unset( $stored[ self::KEY ] );
		}
		if ( array() === $stored ) {
			delete_option( Palette::OPTION );
			return;
		}
		update_option( Palette::OPTION, $stored, false );
	}

	/** Servi aux visiteurs : case cochée et jumelle de la palette en place sans paire en échec. */
	public static function active(): bool {
		return self::enabled() && null !== self::current_twin();
	}

	/**
	 * Jumelle de la palette en place, ou null si elle échoue au contraste.
	 *
	 * @return array<string, string>|null
	 */
	public static function current_twin(): ?array {
		return self::twin( Palette::current(), Palette::button() );
	}

	/* ---------- Calcul ---------- */

	/**
	 * La palette est-elle sombre ?
	 *
	 * @param array<string, string> $palette Palette par rôle.
	 */
	public static function is_dark( array $palette ): bool {
		return self::luminance( $palette['base'] ?? '#ffffff' ) < self::DARK_BELOW;
	}

	/**
	 * Jumelle de sens inverse : sombre pour une palette claire, claire pour une palette sombre.
	 * Null si un rôle manque, si une couleur n'est pas en #rrggbb ou si une paire échoue.
	 * Même entrée, même sortie.
	 *
	 * @param array<string, string>                   $palette Palette par rôle (Palette::current()).
	 * @param array{border: string, large: bool}|null $button  Style des boutons ; aucun contour par défaut.
	 * @return array<string, string>|null
	 */
	public static function twin( array $palette, ?array $button = null ): ?array {
		$result = self::check( $palette, $button );
		return array() === $result['failures'] ? $result['twin'] : null;
	}

	/**
	 * Jumelle calculée et paires en échec (format « premier plan/fond »). Une palette incomplète
	 * ou écrite autrement qu'en #rrggbb donne twin null et l'échec « incomplete ».
	 *
	 * @param array<string, string>                   $palette Palette par rôle.
	 * @param array{border: string, large: bool}|null $button  Style des boutons ; aucun contour par défaut.
	 * @return array{twin: array<string, string>|null, failures: list<string>}
	 */
	public static function check( array $palette, ?array $button = null ): array {
		$button = $button ?? array(
			'border' => '',
			'large'  => false,
		);
		$key    = md5( (string) wp_json_encode( array( $palette, $button ) ) );
		if ( ! isset( self::$memo[ $key ] ) ) {
			$twin               = self::compute( $palette );
			self::$memo[ $key ] = array(
				'twin'     => $twin,
				'failures' => null === $twin ? array( 'incomplete' ) : self::failures( $twin, $button ),
			);
		}
		return self::$memo[ $key ];
	}

	/**
	 * Paires de la jumelle sous leur seuil : celles de Palette::failures(), plus la bordure des
	 * champs à 3:1 sur le fond et la surface.
	 *
	 * @param array<string, string>              $twin   Jumelle.
	 * @param array{border: string, large: bool} $button Style des boutons.
	 * @return list<string>
	 */
	public static function failures( array $twin, array $button ): array {
		$bad = Palette::failures( $twin, $button );
		foreach ( self::DIVIDER_PAIRS as $pair ) {
			if ( isset( $twin[ $pair[0] ], $twin[ $pair[1] ] ) && Palette::ratio( $twin[ $pair[0] ], $twin[ $pair[1] ] ) < self::UI ) {
				$bad[] = $pair[0] . '/' . $pair[1];
			}
		}
		return $bad;
	}

	/**
	 * Jumelle calculée, avant contrôle ; null si un rôle manque ou n'est pas en #rrggbb.
	 *
	 * @param array<string, string> $palette Palette par rôle.
	 * @return array<string, string>|null
	 */
	private static function compute( array $palette ): ?array {
		foreach ( Palette::ROLES as $role ) {
			if ( ! isset( $palette[ $role ] ) || 1 !== preg_match( '/^#[0-9a-f]{6}$/', $palette[ $role ] ) ) {
				return null;
			}
		}
		$has_button = isset( $palette['button-bg'] ) && 1 === preg_match( '/^#[0-9a-f]{6}$/', $palette['button-bg'] );
		$to_dark    = ! self::is_dark( $palette );
		$high       = Palette::ratio( $palette['contrast'], $palette['base'] ) >= self::HIGH_CONTRAST;
		$twin       = array();

		$hsl          = self::hsl( $palette['base'] );
		$twin['base'] = self::swatch( $hsl[0], $hsl[1], $high ? ( $to_dark ? self::MIN_L : self::MAX_L ) : self::target( 'base', $to_dark ) );
		if ( $to_dark ) {
			$hsl             = self::hsl( $palette['surface'] );
			$twin['surface'] = self::swatch( $hsl[0], $hsl[1], self::LIGHTNESS['surface'] );
		} else {
			$twin['surface'] = self::mix( $twin['base'], '#000000', 0.06 );
		}
		$grounds = array( $twin['base'], $twin['surface'] );

		foreach ( self::TEXT_ROLES as $role ) {
			if ( 'button-bg' === $role && ! $has_button ) {
				continue;
			}
			$original = $palette[ $role ];
			// Couleur de marque qui se lit déjà sur les fonds de la jumelle : gardée telle quelle.
			if ( self::reads_on( $original, $grounds, self::AA ) ) {
				$twin[ $role ] = $original;
				continue;
			}
			$hsl   = self::hsl( $original );
			$start = 'contrast' === $role && $high ? ( $to_dark ? self::MAX_L : self::MIN_L ) : self::target( $role, $to_dark );
			// Le texte éclaircit sur un fond sombre et fonce sur un fond clair.
			$twin[ $role ] = self::readable( $hsl[0], $hsl[1], $start, $grounds, $to_dark );
		}

		// Texte sur l'accent : le fond ou le texte, celui dont le pire contraste est le meilleur.
		$best = '';
		$top  = -1.0;
		foreach ( array( $twin['base'], $twin['contrast'] ) as $candidate ) {
			$worst = Palette::ratio( $candidate, $twin['accent'] );
			if ( isset( $twin['button-bg'] ) ) {
				$worst = min( $worst, Palette::ratio( $candidate, $twin['button-bg'] ) );
			}
			if ( $worst > $top ) {
				$top  = $worst;
				$best = $candidate;
			}
		}
		$twin['on-accent'] = $best;

		// Bordure des champs : la couleur d'origine si elle tient 3:1, sinon le mélange fond et
		// discret, poussé vers le discret jusqu'à 3:1 (le discret tient 4,5:1, la boucle finit).
		if ( self::reads_on( $palette['divider'], $grounds, self::UI ) ) {
			$twin['divider'] = $palette['divider'];
		} else {
			for ( $step = 6; $step <= 20; $step++ ) {
				$twin['divider'] = self::mix( $twin['base'], $twin['muted'], $step / 20 );
				if ( self::reads_on( $twin['divider'], $grounds, self::UI ) ) {
					break;
				}
			}
		}

		$out = array();
		foreach ( array_merge( Palette::ROLES, Palette::OPTIONAL_ROLES ) as $role ) {
			if ( isset( $twin[ $role ] ) ) {
				$out[ $role ] = $twin[ $role ];
			}
		}
		return $out;
	}

	/**
	 * Luminosité de départ d'un rôle dans le sens voulu.
	 *
	 * @param string $role    Rôle.
	 * @param bool   $to_dark Jumelle sombre.
	 */
	private static function target( string $role, bool $to_dark ): int {
		$l = self::LIGHTNESS[ $role ];
		return $to_dark ? $l : 100 - $l;
	}

	/**
	 * Vrai si la couleur atteint le ratio sur chaque fond.
	 *
	 * @param string   $color   Couleur.
	 * @param string[] $grounds Fonds.
	 * @param float    $min     Ratio minimal.
	 */
	private static function reads_on( string $color, array $grounds, float $min ): bool {
		foreach ( $grounds as $ground ) {
			if ( Palette::ratio( $color, $ground ) < $min ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Éclaircit (up) ou fonce par pas de 2 jusqu'à 4,5:1 sur chaque fond, entre les bornes.
	 *
	 * @param float    $h       Teinte.
	 * @param float    $s       Saturation.
	 * @param int      $l       Luminosité de départ.
	 * @param string[] $grounds Fonds.
	 * @param bool     $up      Éclaircir.
	 */
	private static function readable( float $h, float $s, int $l, array $grounds, bool $up ): string {
		$color = self::swatch( $h, $s, $l );
		while ( ! self::reads_on( $color, $grounds, self::AA ) && $l > self::MIN_L && $l < self::MAX_L ) {
			$l    += $up ? 2 : -2;
			$color = self::swatch( $h, $s, $l );
		}
		return $color;
	}

	/**
	 * Couleur HSL, bleus (200 à 270°) bridés comme dans le générateur : le bleu coûte plus.
	 *
	 * @param float $h Teinte.
	 * @param float $s Saturation, 0 à 100.
	 * @param float $l Luminosité, 0 à 100.
	 */
	private static function swatch( float $h, float $s, float $l ): string {
		$h    = fmod( fmod( $h, 360 ) + 360, 360 );
		$blue = $h >= 200 && $h <= 270;
		return self::hex( self::rgb_of( $h, $blue ? min( $s, 42 ) : $s, max( 0, min( 100, $blue ? $l - 4 : $l ) ) ) );
	}

	/**
	 * HSL vers RVB (même calcul que palette-core.js).
	 *
	 * @param float $h Teinte.
	 * @param float $s Saturation, 0 à 100.
	 * @param float $l Luminosité, 0 à 100.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function rgb_of( float $h, float $s, float $l ): array {
		$s  /= 100;
		$l  /= 100;
		$c   = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x   = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m   = $l - $c / 2;
		$set = array( array( $c, $x, 0 ), array( $x, $c, 0 ), array( 0, $c, $x ), array( 0, $x, $c ), array( $x, 0, $c ), array( $c, 0, $x ) );
		$rgb = $set[ ( (int) floor( $h / 60 ) ) % 6 ];
		return array( (int) round( ( $rgb[0] + $m ) * 255 ), (int) round( ( $rgb[1] + $m ) * 255 ), (int) round( ( $rgb[2] + $m ) * 255 ) );
	}

	/**
	 * Teinte (0 à 360) et saturation (0 à 100) d'une couleur.
	 *
	 * @param string $hex Couleur #rrggbb.
	 * @return array{0: float, 1: float}
	 */
	private static function hsl( string $hex ): array {
		$c   = array_map( static fn( int $v ): float => $v / 255, self::channels( $hex ) );
		$max = max( $c );
		$min = min( $c );
		$d   = $max - $min;
		if ( 0.0 === $d ) {
			return array( 0.0, 0.0 );
		}
		$l = ( $max + $min ) / 2;
		$s = $d / ( 1 - abs( 2 * $l - 1 ) );
		if ( $max === $c[0] ) {
			$h = fmod( ( $c[1] - $c[2] ) / $d + 6, 6 );
		} elseif ( $max === $c[1] ) {
			$h = ( $c[2] - $c[0] ) / $d + 2;
		} else {
			$h = ( $c[0] - $c[1] ) / $d + 4;
		}
		return array( $h * 60, min( 100.0, $s * 100 ) );
	}

	/**
	 * Mélange linéaire en RVB : $t = part de $b.
	 *
	 * @param string $a Couleur.
	 * @param string $b Couleur.
	 * @param float  $t Part de $b, 0 à 1.
	 */
	private static function mix( string $a, string $b, float $t ): string {
		$x = self::channels( $a );
		$y = self::channels( $b );
		return self::hex( array( (int) round( $x[0] * ( 1 - $t ) + $y[0] * $t ), (int) round( $x[1] * ( 1 - $t ) + $y[1] * $t ), (int) round( $x[2] * ( 1 - $t ) + $y[2] * $t ) ) );
	}

	/**
	 * Canaux 0 à 255.
	 *
	 * @param string $hex Couleur #rrggbb.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function channels( string $hex ): array {
		return array( (int) hexdec( substr( $hex, 1, 2 ) ), (int) hexdec( substr( $hex, 3, 2 ) ), (int) hexdec( substr( $hex, 5, 2 ) ) );
	}

	/**
	 * Canaux vers #rrggbb.
	 *
	 * @param array{0: int, 1: int, 2: int} $rgb Canaux.
	 */
	private static function hex( array $rgb ): string {
		return sprintf( '#%02x%02x%02x', max( 0, min( 255, $rgb[0] ) ), max( 0, min( 255, $rgb[1] ) ), max( 0, min( 255, $rgb[2] ) ) );
	}

	/**
	 * Luminance relative WCAG.
	 *
	 * @param string $hex Couleur #rrggbb.
	 */
	private static function luminance( string $hex ): float {
		$c = array_map(
			static function ( int $v ): float {
				$v /= 255;
				return $v <= 0.04045 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
			},
			self::channels( $hex )
		);
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	/* ---------- Feuille ---------- */

	/**
	 * Feuille du visiteur : chaque rôle en light-dark(clair, sombre), le choix du visiteur posé
	 * sur la racine, et le bouton montré seulement quand le script l'a permis. Sous @supports :
	 * un navigateur sans light-dark() garde la palette en place. :root:root l'emporte sur le :root
	 * des styles globaux même quand une extension d'optimisation change l'ordre des feuilles.
	 *
	 * @param array<string, string> $palette Palette en place.
	 * @param array<string, string> $twin    Jumelle.
	 */
	public static function css( array $palette, array $twin ): string {
		$dark  = self::is_dark( $palette );
		$lines = '';
		foreach ( $twin as $role => $color ) {
			if ( ! isset( $palette[ $role ] ) ) {
				continue;
			}
			$light  = $dark ? $color : $palette[ $role ];
			$night  = $dark ? $palette[ $role ] : $color;
			$lines .= '--wp--preset--color--' . $role . ':' . ( $light === $night ? $light : 'light-dark(' . $light . ',' . $night . ')' ) . ';';
		}
		return '@supports (color:light-dark(#000,#fff)){'
			. ':root:root{color-scheme:light dark;' . $lines . '}'
			. ':root[data-fluffless-scheme=light]{color-scheme:light}'
			. ':root[data-fluffless-scheme=dark]{color-scheme:dark}'
			. ':root[data-fluffless-toggle] .wp-block-fluffless-mode-couleur{display:flex}'
			. '}'
			. '.wp-block-fluffless-mode-couleur{display:none;align-items:center}'
			. '.fl-scheme-toggle{display:inline-grid;place-items:center;box-sizing:border-box;min-width:44px;min-height:44px;margin:0;padding:0;border:1px solid var(--wp--preset--color--divider);border-radius:50%;background:transparent;color:inherit;font:inherit;cursor:pointer}'
			. '.fl-scheme-toggle:focus-visible{outline:2px solid currentColor;outline-offset:2px}'
			. '.fl-scheme-toggle svg{width:1.25rem;height:1.25rem;fill:none;stroke:currentColor;stroke-width:2}'
			. '.fl-scheme-toggle[aria-pressed=true] svg{fill:currentColor}'
			. '.fl-scheme-toggle span{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}'
			. '@media print{.wp-block-fluffless-mode-couleur{display:none!important}}';
	}
}
