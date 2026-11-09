<?php
/**
 * Assainissement générique piloté par les champs déclarés d'un module.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Chaque module décrit ses champs ; ce code commun applique la règle
 * « valeur valide ou valeur par défaut », sans jamais conserver une valeur hors liste.
 *
 * @phpstan-import-type Field from Module
 */
final class Sanitize {

	/** Borne sur les listes reçues (domaines, tailles) : une entrée non bornée ne passe pas. */
	public const MAX_LIST = 50;

	/** Longueur maximale d'un champ texte ou adresse. */
	public const MAX_TEXT = 200;

	/** Longueur maximale d'un champ texte sur plusieurs lignes (reconnaissance territoriale). */
	public const MAX_LONG_TEXT = 600;

	/**
	 * Assainit une section d'après ses champs et ses valeurs par défaut.
	 *
	 * @param array<mixed>                                $input    Section brute.
	 * @param array<string, Field>                        $fields   Champs déclarés.
	 * @param array<string, bool|int|string|list<string>> $defaults Valeurs par défaut.
	 * @return array<string, bool|int|string|list<string>>
	 */
	public static function section( array $input, array $fields, array $defaults ): array {
		$clean = $defaults;
		foreach ( $fields as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = self::field( $input[ $key ], $field );
			if ( null !== $value ) {
				$clean[ $key ] = $value;
			}
		}
		return $clean;
	}

	/**
	 * Valeur assainie d'un champ, ou null si elle est invalide.
	 *
	 * @param mixed $raw   Valeur reçue.
	 * @param array $field Description du champ.
	 * @phpstan-param Field $field
	 * @return bool|int|string|list<string>|null
	 */
	public static function field( $raw, array $field ) {
		$options = $field['options'] ?? array();
		return match ( $field['type'] ) {
			'bool'   => self::to_bool( $raw ),
			'int'    => self::int( $raw, $field['min'] ?? PHP_INT_MIN, $field['max'] ?? PHP_INT_MAX ),
			'choice' => is_string( $raw ) && isset( $options[ $raw ] ) ? $raw : null,
			'multi'  => self::multi( $raw, $options ),
			'hosts'  => self::hosts( $raw ),
			'text'   => self::text( $raw ),
			'textarea' => self::textarea( $raw ),
			'lines'  => self::lines( $raw ),
			'url'    => self::https_url( $raw ),
		};
	}

	/**
	 * Texte brut sur plusieurs lignes (balises retirées), borné ; null si ce n'est pas une chaîne.
	 *
	 * @param mixed $raw Valeur reçue.
	 */
	public static function textarea( $raw ): ?string {
		return is_string( $raw ) ? mb_substr( sanitize_textarea_field( $raw ), 0, self::MAX_LONG_TEXT ) : null;
	}

	/**
	 * Liste de lignes : chaîne (une par ligne) ou tableau. Balises retirées, lignes vides et
	 * doublons écartés. Pas de sanitize_text_field : il retirerait les %XX des adresses.
	 * Le module valide ensuite le contenu de chaque ligne.
	 *
	 * @param mixed $raw Valeur reçue.
	 * @return list<string>|null
	 */
	public static function lines( $raw ): ?array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\R/u', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$lines = array();
		foreach ( array_slice( $raw, 0, self::MAX_LIST ) as $entry ) {
			$line = is_string( $entry ) ? mb_substr( trim( wp_strip_all_tags( $entry ) ), 0, self::MAX_TEXT ) : '';
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return array_values( array_unique( $lines ) );
	}

	/**
	 * Ligne de texte brut (balises et sauts de ligne retirés), bornée ; null si ce n'est pas une chaîne.
	 *
	 * @param mixed $raw Valeur reçue.
	 */
	public static function text( $raw ): ?string {
		return is_string( $raw ) ? mb_substr( sanitize_text_field( $raw ), 0, self::MAX_TEXT ) : null;
	}

	/**
	 * Adresse https valide, '' pour vider le champ ; null pour tout le reste
	 * (http, identifiants dans l'adresse, hôte invalide, trop longue).
	 *
	 * @param mixed $raw Valeur reçue.
	 */
	public static function https_url( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$parts = wp_parse_url( $raw );
		if ( strlen( $raw ) > self::MAX_TEXT || ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || ! self::is_hostname( strtolower( $parts['host'] ?? '' ) ) ) {
			return null;
		}
		$clean = esc_url_raw( $raw, array( 'https' ) );
		return '' === $clean ? null : $clean;
	}

	/**
	 * Entier ramené dans ses bornes, ou null si ce n'est pas un entier.
	 *
	 * @param mixed $raw Valeur reçue.
	 * @param int   $min Borne basse.
	 * @param int   $max Borne haute.
	 */
	public static function int( $raw, int $min, int $max ): ?int {
		$int = filter_var( $raw, FILTER_VALIDATE_INT );
		return false === $int ? null : max( $min, min( $max, $int ) );
	}

	/**
	 * Valeurs de la liste permise, sans doublon ; null si ce n'est pas un tableau.
	 *
	 * @param mixed                 $raw     Valeur reçue.
	 * @param array<string, string> $options Valeurs permises => libellés.
	 * @return list<string>|null
	 */
	public static function multi( $raw, array $options ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$kept = array_filter( array_slice( $raw, 0, self::MAX_LIST ), static fn( $v ): bool => is_string( $v ) && isset( $options[ $v ] ) );
		return array_values( array_unique( $kept ) );
	}

	/**
	 * Booléen strict : accepte true/false, 1/0 et leurs chaînes, rien d'autre.
	 *
	 * @param mixed $raw Valeur reçue.
	 */
	public static function to_bool( $raw ): ?bool {
		if ( is_bool( $raw ) ) {
			return $raw;
		}
		if ( in_array( $raw, array( 1, '1', 'true', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $raw, array( 0, '0', 'false', '' ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Liste de domaines : chaîne (un par ligne) ou tableau. Schéma et chemin retirés ;
	 * tout ce qui n'est pas un nom d'hôte valide est écarté.
	 *
	 * @param mixed $raw Valeur reçue.
	 * @return list<string>|null
	 */
	public static function hosts( $raw ): ?array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$hosts = array();
		foreach ( array_slice( $raw, 0, self::MAX_LIST ) as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$entry = strtolower( trim( $entry ) );
			$host  = str_contains( $entry, '://' ) ? wp_parse_url( $entry, PHP_URL_HOST ) : $entry;
			if ( is_string( $host ) && self::is_hostname( $host ) ) {
				$hosts[] = $host;
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Nom d'hôte ASCII (IDN déjà encodé en punycode), 253 caractères au plus.
	 *
	 * @param string $host Nom à vérifier.
	 */
	public static function is_hostname( string $host ): bool {
		return strlen( $host ) <= 253
			&& 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host );
	}
}
