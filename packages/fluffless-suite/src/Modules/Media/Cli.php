<?php
/**
 * Petit adaptateur WP-CLI pour les modules médias.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Les modules passent par ici plutôt que par la classe WP_CLI : elle n'existe qu'en ligne de commande,
 * et l'analyse statique n'a pas à la connaître.
 */
final class Cli {

	/**
	 * Déclare une commande, seulement quand WP-CLI tourne.
	 *
	 * @param string   $name     Commande, ex. « fluffless webp ».
	 * @param callable $callback Rappel.
	 */
	public static function add( string $name, callable $callback ): void {
		// WP_CLI vrai garantit la classe WP_CLI : c'est WP-CLI qui définit la constante.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( $name, $callback );
		}
	}

	/**
	 * Ligne d'information.
	 *
	 * @param string $message Texte.
	 */
	public static function log( string $message ): void {
		self::call( 'log', $message );
	}

	/**
	 * Ligne de réussite.
	 *
	 * @param string $message Texte.
	 */
	public static function success( string $message ): void {
		self::call( 'success', $message );
	}

	/**
	 * Appelle WP_CLI::$method(), ou écrit le texte hors ligne de commande.
	 *
	 * @param string $method  log|success.
	 * @param string $message Texte.
	 */
	private static function call( string $method, string $message ): void {
		$callback = array( 'WP_CLI', $method );
		if ( is_callable( $callback ) ) {
			$callback( $message );
			return;
		}
		echo esc_html( $message ) . "\n";
	}

	/**
	 * Drapeau : --nom vaut vrai, --no-nom vaut faux, absent : la valeur de repli.
	 *
	 * @param array<string, mixed> $assoc_args Options reçues.
	 * @param string               $name       Nom du drapeau.
	 * @param bool                 $fallback   Valeur si le drapeau est absent.
	 */
	public static function flag( array $assoc_args, string $name, bool $fallback ): bool {
		if ( ! array_key_exists( $name, $assoc_args ) ) {
			return $fallback;
		}
		$value = $assoc_args[ $name ];
		if ( is_bool( $value ) ) {
			return $value;
		}
		return ! in_array( strtolower( is_scalar( $value ) ? (string) $value : '' ), array( '', '0', 'false', 'no' ), true );
	}
}
