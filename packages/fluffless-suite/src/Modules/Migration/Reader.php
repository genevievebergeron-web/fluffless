<?php
/**
 * Socle des lecteurs de constructeurs : contrat (repérage, arbre, conversion, gabarits, réglages
 * globaux, marqueur) et recettes communes (couleurs écartées, fichiers joints, texte d'un élément
 * inconnu, formulaire à refaire).
 *
 * @package Fluffless\Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Un lecteur par constructeur, la même écriture en blocs.
 *
 * @phpstan-import-type Block from Blocks
 */
abstract class Reader {

	/** Réseaux reconnus dans le nom d'icône (fab fa-…) ou l'adresse pour le bloc Icônes sociales. */
	protected const SOCIAL = array( 'facebook', 'instagram', 'linkedin', 'youtube', 'tiktok', 'pinterest', 'mastodon', 'bluesky', 'threads', 'vimeo', 'github', 'twitter', 'x-twitter' );

	/** Identifiant technique du lecteur (jamais affiché). */
	abstract public static function id(): string;

	/**
	 * La page est prise en charge par le constructeur.
	 *
	 * @param int $post_id Identifiant.
	 */
	abstract public static function supports( int $post_id ): bool;

	/**
	 * Arbre de la page, vide si absent ou illisible.
	 *
	 * @param int $post_id Identifiant.
	 * @return list<array<string, mixed>>
	 */
	abstract public static function tree( int $post_id ): array;

	/**
	 * Nombre d'éléments par sorte, enfants compris.
	 *
	 * @param array<int, mixed> $tree Arbre.
	 * @return array<string, int>
	 */
	abstract public static function count( array $tree ): array;

	/**
	 * Conversion de l'arbre en blocs.
	 *
	 * @param array<int, mixed> $tree        Arbre.
	 * @param bool              $keep_colors Garder les couleurs fixes.
	 * @param Report            $report      Rapport à remplir.
	 * @return list<Block>
	 */
	abstract public static function convert( array $tree, bool $keep_colors, Report $report ): array;

	/**
	 * Gabarits du constructeur qui ne s'insèrent pas dans une page : sorte => titres.
	 *
	 * @return array<string, list<string>>
	 */
	abstract public static function templates(): array;

	/**
	 * Réglages globaux : couleurs et familles par indice.
	 *
	 * @return array{colors: array<string, string>, families: array<string, string>}
	 */
	abstract public static function globals(): array;

	/**
	 * Clause de repérage pour une meta_query (une page candidate).
	 *
	 * @return array<string, string>
	 */
	abstract public static function clause(): array;

	/**
	 * Marqueur de prise en charge à garder avant la conversion.
	 *
	 * @param int $post_id Identifiant.
	 */
	abstract public static function mode( int $post_id ): string;

	/**
	 * La page cesse d'être rendue par le constructeur.
	 *
	 * @param int $post_id Identifiant.
	 */
	abstract public static function detach( int $post_id ): void;

	/**
	 * Le marqueur d'avant est remis.
	 *
	 * @param int    $post_id Identifiant.
	 * @param string $mode    Marqueur gardé.
	 */
	abstract public static function reattach( int $post_id, string $mode ): void;

	/**
	 * Formulaire à refaire avec le module Formulaire : phrase au rapport, repère invisible dans la page.
	 *
	 * Le repère est un commentaire HTML `fluffless-todo` dans un bloc HTML personnalisé : la relecture de
	 * l'éditeur le compte et le liste, les visiteurs ne le voient jamais.
	 *
	 * @param string             $name   Nom du formulaire.
	 * @param array<int, string> $labels Champs.
	 * @phpstan-param list<string> $labels
	 * @param Report             $report Rapport.
	 * @return list<Block>
	 */
	protected static function form_todo( string $name, array $labels, Report $report ): array {
		/* translators: 1: form name, 2: field list */
		$sentence = sprintf( __( 'Form to rebuild with the Form module: %1$s. Fields: %2$s.', 'fluffless-suite' ), '' === $name ? __( 'untitled', 'fluffless-suite' ) : $name, array() === $labels ? __( 'none', 'fluffless-suite' ) : implode( ', ', $labels ) );
		$report->todo( $sentence );
		return array( Blocks::html( '<!-- fluffless-todo: ' . str_replace( '--', '- -', esc_html( $sentence ) ) . ' -->' ) );
	}

	/**
	 * Rien à garder : compte et renvoie vide.
	 *
	 * @param string $kind   Sorte.
	 * @param Report $report Rapport.
	 * @return list<Block>
	 */
	protected static function skipped( string $kind, Report $report ): array {
		$report->skip( $kind );
		return array();
	}

	/**
	 * Une couleur HEX gardée ou écartée (comptée).
	 *
	 * @param mixed  $raw    Valeur brute.
	 * @param bool   $keep   Garder.
	 * @param Report $report Rapport.
	 */
	protected static function color( $raw, bool $keep, Report $report ): ?string {
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^#[0-9a-f]{6}$/i', trim( $raw ) ) ) {
			return null;
		}
		if ( ! $keep ) {
			++$report->styles;
			return null;
		}
		return strtolower( trim( $raw ) );
	}

	/**
	 * Une typographie sur mesure est écartée et comptée.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @param string               $key      Clé du drapeau.
	 * @param Report               $report   Rapport.
	 */
	protected static function drop_typography( array $settings, string $key, Report $report ): void {
		if ( 'custom' === ( $settings[ $key ] ?? '' ) ) {
			++$report->styles;
		}
	}

	/**
	 * Identifiant d'un fichier joint encore en médiathèque, 0 sinon (l'adresse est notée).
	 *
	 * @param array<string, mixed> $image  Image (id, url).
	 * @param Report               $report Rapport.
	 */
	protected static function attachment( array $image, Report $report ): int {
		$id = is_numeric( $image['id'] ?? null ) ? (int) $image['id'] : 0;
		if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
			return $id;
		}
		$url = self::string( $image['url'] ?? null );
		if ( '' !== $url ) {
			$found = attachment_url_to_postid( $url );
			if ( $found > 0 ) {
				return $found;
			}
			$report->image( $url );
		}
		return 0;
	}

	/**
	 * Service d'une icône sociale.
	 *
	 * @param string $icon Nom d'icône (fab fa-facebook) ou réseau.
	 * @param string $url  Adresse.
	 */
	protected static function service( string $icon, string $url ): string {
		$haystack = strtolower( $icon . ' ' . $url );
		foreach ( self::SOCIAL as $service ) {
			if ( str_contains( $haystack, $service ) ) {
				return 'twitter' === $service || 'x-twitter' === $service ? 'x' : $service;
			}
		}
		return '' === $url ? '' : 'chain';
	}

	/**
	 * Chaîne ou vide.
	 *
	 * @param mixed $raw Valeur brute.
	 */
	protected static function string( $raw ): string {
		return is_string( $raw ) ? $raw : ( is_numeric( $raw ) ? (string) $raw : '' );
	}

	/**
	 * Lignes d'un répéteur.
	 *
	 * @param mixed $raw Valeur brute.
	 * @return list<array<string, mixed>>
	 */
	protected static function repeater( $raw ): array {
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : array();
	}

	/**
	 * Alignement reconnu ou null.
	 *
	 * @param mixed $raw Valeur brute.
	 */
	protected static function align( $raw ): ?string {
		return is_string( $raw ) && in_array( $raw, array( 'left', 'center', 'right' ), true ) ? $raw : null;
	}

	/**
	 * Ancre propre ou null.
	 *
	 * @param mixed $raw Valeur brute.
	 */
	protected static function anchor( $raw ): ?string {
		$anchor = is_string( $raw ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $raw ) : '';
		return '' === $anchor ? null : $anchor;
	}

	/**
	 * Largeur en pourcentage (« 33.33% ») ou null.
	 *
	 * @param mixed $raw Valeur brute.
	 */
	protected static function percent( $raw ): ?string {
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		$value = (float) $raw;
		if ( $value <= 0 || $value > 100 ) {
			return null;
		}
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' ) . '%';
	}

	/**
	 * Couleur HEX à six chiffres en minuscules, ou null (les rgba et les variables sont écartés).
	 *
	 * @param mixed $raw Valeur brute.
	 */
	protected static function hex( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$raw = strtolower( trim( $raw ) );
		if ( 1 === preg_match( '/^#[0-9a-f]{3}$/', $raw ) ) {
			$raw = '#' . $raw[1] . $raw[1] . $raw[2] . $raw[2] . $raw[3] . $raw[3];
		}
		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $raw ) ? $raw : null;
	}

	/**
	 * Gadget inconnu : chaque réglage textuel devient un paragraphe, et le gadget est compté.
	 *
	 * @param string               $kind     Sorte.
	 * @param array<string, mixed> $settings Réglages.
	 * @param Report               $report   Rapport.
	 * @return list<Block>
	 */
	protected static function unknown( string $kind, array $settings, Report $report ): array {
		$blocks = array();
		foreach ( self::texts( $settings ) as $text ) {
			foreach ( 1 === preg_match( '/<[a-z]/i', $text ) ? Blocks::rich( $text ) : Blocks::text( $text ) as $block ) {
				$blocks[] = $block;
			}
		}
		if ( array() === $blocks ) {
			$report->skip( $kind );
		} else {
			$report->keep( $kind );
		}
		return $blocks;
	}

	/**
	 * Réglages textuels d'un gadget, répéteurs compris : ni clé privée, ni couleur, ni adresse,
	 * ni nombre, ni mot-clé de réglage, ni valeur encodée en JSON (un constructeur peut en glisser
	 * une dans un réglage imbriqué, par exemple un point de carte, sans que l'appelant l'ait vue).
	 *
	 * @param array<int|string, mixed> $settings Réglages.
	 * @return list<string>
	 */
	protected static function texts( array $settings ): array {
		$out = array();
		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && str_starts_with( $key, '_' ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				foreach ( self::texts( $value ) as $text ) {
					$out[] = $text;
				}
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( strlen( $value ) < 3 || self::is_json( $value ) || 1 === preg_match( '/^(#[0-9a-f]{3,8}|https?:\/\/\S+|[\d.,%\s]+|[a-z0-9_-]+|(fab|fas|far|eicon)\s\S+)$/i', $value ) ) {
				continue;
			}
			$out[] = $value;
		}
		return $out;
	}

	/**
	 * Chaîne qui se décode comme un objet JSON, repère générique pour écarter une valeur qu'un
	 * constructeur a encodée ainsi plutôt qu'en texte simple (par exemple les états de survol et
	 * points de rupture du constructeur D en version 5).
	 *
	 * @param string $value Valeur.
	 */
	protected static function is_json( string $value ): bool {
		$trimmed = trim( $value );
		if ( '' === $trimmed || '{' !== $trimmed[0] ) {
			return false;
		}
		json_decode( $trimmed, true );
		return JSON_ERROR_NONE === json_last_error();
	}

	/**
	 * Gadget sans équivalent en bloc (menu, connexion, partage, animation…) : ses textes sont
	 * gardés et le gadget est nommé au rapport comme chose à refaire.
	 *
	 * @param string               $kind     Sorte.
	 * @param array<string, mixed> $settings Réglages.
	 * @param Report               $report   Rapport.
	 * @return list<Block>
	 */
	protected static function listed( string $kind, array $settings, Report $report ): array {
		/* translators: %s: widget kind */
		$report->todo( sprintf( __( 'A “%s” element has no native block: rebuild it with a block, a pattern or a Suite module.', 'fluffless-suite' ), $kind ) );
		return self::unknown( $kind, $settings, $report );
	}
}
