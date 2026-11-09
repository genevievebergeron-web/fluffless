<?php
/**
 * Réglages globaux relus d'un constructeur : une palette candidate pour l'écran Palette et un duo
 * de polices de la banque, proposés et jamais appliqués d'office.
 *
 * @package Fluffless\Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules\Migration;

use Fluffless\Suite\Modules\Fonts;
use Fluffless\Suite\Modules\Palette;

defined( 'ABSPATH' ) || exit;

/**
 * Passage des couleurs et familles du constructeur aux rôles de la Suite.
 *
 * @phpstan-type Candidate array{palette: array<string, string>, failures: list<string>}
 */
final class Globals {

	/** Familles tenues pour la pile système du site. */
	private const SYSTEM = array( '', 'arial', 'helvetica', 'helvetica neue', 'system-ui', '-apple-system', 'sans-serif', 'serif', 'georgia', 'verdana', 'tahoma', 'times new roman', 'segoe ui', 'roboto', 'inherit', 'default' );

	/**
	 * Palette candidate depuis les couleurs du constructeur (indices text, primary, secondary,
	 * accent, background, button, button-text), avec ses paires sous le seuil ; null sans texte
	 * ni couleur principale.
	 *
	 * @param array<string, string> $colors Couleurs par indice (HEX à six chiffres).
	 * @return Candidate|null
	 */
	public static function palette( array $colors ): ?array {
		$text    = $colors['text'] ?? $colors['body'] ?? null;
		$primary = $colors['primary'] ?? null;
		if ( null === $text || null === $primary ) {
			return null;
		}
		$base      = $colors['background'] ?? '#ffffff';
		$secondary = $colors['secondary'] ?? $primary;
		$candidate = array(
			'base'      => $base,
			'surface'   => $base,
			'contrast'  => $text,
			'muted'     => $text,
			'divider'   => $secondary,
			'accent'    => $primary,
			'on-accent' => $colors['button-text'] ?? '#ffffff',
			'accent-2'  => $secondary,
			'alert'     => $colors['accent'] ?? $primary,
		);
		if ( isset( $colors['button'] ) ) {
			$candidate['button-bg'] = $colors['button'];
		}
		$palette = Palette::sanitize_palette( $candidate );
		if ( null === $palette ) {
			return null;
		}
		return array(
			'palette'  => $palette,
			'failures' => Palette::failures( $palette ),
		);
	}

	/**
	 * Rang du duo de la banque qui correspond aux familles du constructeur (indices primary ou
	 * secondary pour les titres, text ou body pour le texte), ou null.
	 *
	 * @param array<string, string> $families Familles par indice.
	 */
	public static function pair( array $families ): ?int {
		$heading = self::slug( $families['primary'] ?? '' ) ?? self::slug( $families['secondary'] ?? '' );
		if ( null === $heading ) {
			return null;
		}
		$body_name = $families['text'] ?? $families['body'] ?? '';
		$body      = self::slug( $body_name );
		if ( null === $body && self::is_system( $body_name ) ) {
			$body = 'system';
		}
		foreach ( Fonts::PAIRS as $index => $pair ) {
			if ( $pair[0] === $heading && $pair[1] === $body ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Identifiant d'une famille de la banque depuis son nom, ou null.
	 *
	 * @param string $name Nom de famille tel que le constructeur l'écrit.
	 */
	public static function slug( string $name ): ?string {
		$name = trim( $name, " \t\"'" );
		if ( '' === $name ) {
			return null;
		}
		foreach ( Fonts::catalogue() as $slug => $family ) {
			if ( 0 === strcasecmp( $family['name'], $name ) ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * Famille rendue par la pile système du site (ou non réglée).
	 *
	 * @param string $name Nom de famille.
	 */
	public static function is_system( string $name ): bool {
		return in_array( strtolower( trim( $name, " \t\"'" ) ), self::SYSTEM, true );
	}
}
