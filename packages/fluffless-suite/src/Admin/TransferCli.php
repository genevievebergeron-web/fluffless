<?php
/**
 * Commandes WP-CLI : wp fluffless settings export|import|undo.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

use Fluffless\Suite\Modules\Media\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * La ligne de commande passe par les mêmes fonctions que l'écran Modules (Transfer::plan, preview_lines,
 * apply_json, undo) : mêmes validations, mêmes textes d'aperçu, même état gardé pour l'annulation.
 */
final class TransferCli {

	/** Déclare les trois commandes (seulement quand WP-CLI tourne, voir Cli::add()). */
	public static function register(): void {
		Cli::add( 'fluffless settings export', array( self::class, 'export' ) );
		Cli::add( 'fluffless settings import', array( self::class, 'import' ) );
		Cli::add( 'fluffless settings undo', array( self::class, 'undo' ) );
	}

	/**
	 * Écrit les réglages de la Suite dans un fichier JSON, ou sur la sortie standard sans fichier.
	 * L'adresse de connexion cachée n'y est jamais.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Fichier à écrire. Sans lui, le JSON va sur la sortie standard.
	 *
	 * [--force]
	 * : Écrase le fichier s'il existe déjà.
	 *
	 * @param array<int, string>   $args       Arguments positionnels.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public static function export( array $args, array $assoc_args ): void {
		$json = wp_json_encode( Transfer::payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			\WP_CLI::error( 'The settings could not be encoded as JSON.' );
		}
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file ) {
			Cli::log( (string) $json );
			return;
		}
		if ( file_exists( $file ) && ! Cli::flag( $assoc_args, 'force', false ) ) {
			\WP_CLI::error( sprintf( '%s already exists. Use --force to overwrite it.', $file ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fichier choisi par l'administratrice en ligne de commande.
		if ( false === file_put_contents( $file, $json . "\n", LOCK_EX ) ) {
			\WP_CLI::error( sprintf( 'Could not write %s.', $file ) );
		}
		Cli::success( sprintf( 'Settings exported to %s.', $file ) );
	}

	/**
	 * Lit un fichier d'export, montre l'aperçu de ce qu'il changerait, puis l'applique après confirmation.
	 * L'état d'avant est gardé 7 jours : `wp fluffless settings undo` le rétablit.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Fichier JSON exporté par la Suite.
	 *
	 * [--confirm-module=<modules>]
	 * : Modules sensibles à activer vraiment (security). Sans cette option, ils restent tels quels.
	 *
	 * [--dry-run]
	 * : Montre l'aperçu et s'arrête, sans rien écrire.
	 *
	 * [--yes]
	 * : Applique sans demander confirmation.
	 *
	 * @param array<int, string>   $args       Arguments positionnels.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public static function import( array $args, array $assoc_args ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file || is_dir( $file ) || ! is_readable( $file ) ) {
			\WP_CLI::error( sprintf( 'Cannot read %s.', '' === $file ? 'the file' : $file ) );
		}
		$size = filesize( $file );
		if ( false === $size || $size > Transfer::MAX_BYTES ) {
			\WP_CLI::error( Transfer::error_message( 'format' ) );
		}
		$raw = file_get_contents( $file, false, null, 0, Transfer::MAX_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier choisi par l'administratrice en ligne de commande.
		if ( false === $raw ) {
			\WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
		}

		$confirmed = array();
		$asked     = array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $assoc_args['confirm-module'] ?? '' ) ) ) );
		foreach ( $asked as $module ) {
			if ( ! in_array( $module, Transfer::SENSITIVE, true ) ) {
				\WP_CLI::error( sprintf( 'Unknown module to confirm: %1$s. Allowed: %2$s.', $module, implode( ', ', Transfer::SENSITIVE ) ) );
			}
			$confirmed[] = $module;
		}

		$plan = Transfer::plan( json_decode( $raw, true ), $confirmed );
		if ( is_wp_error( $plan ) ) {
			\WP_CLI::error( Transfer::error_message( (string) $plan->get_error_code() ) );
		}
		foreach ( Transfer::preview_lines( $plan ) as $line ) {
			Cli::log( '- ' . $line );
		}
		if ( array() !== $plan['held'] ) {
			Cli::log( sprintf( 'Add --confirm-module=%s to turn on the module the file asks for.', implode( ',', $plan['held'] ) ) );
		}
		if ( Cli::flag( $assoc_args, 'dry-run', false ) ) {
			Cli::success( 'Dry run: nothing was changed.' );
			return;
		}
		\WP_CLI::confirm( 'Apply this import?', $assoc_args );
		$result = Transfer::apply_json( $raw, $confirmed );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( Transfer::error_message( (string) $result->get_error_code() ) );
		}
		Cli::success( 'Settings imported. Run "wp fluffless settings undo" within 7 days to restore the earlier state.' );
	}

	/**
	 * Rétablit les réglages d'avant le dernier import, tant que l'état gardé n'a pas expiré (7 jours).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Rétablit sans demander confirmation.
	 *
	 * @param array<int, string>   $args       Arguments positionnels.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public static function undo( array $args, array $assoc_args ): void {
		unset( $args );
		$previous = Transfer::previous();
		if ( null === $previous ) {
			\WP_CLI::error( Transfer::error_message( 'nothing' ) );
		}
		Cli::log( sprintf( 'The earlier state was saved on %s.', (string) $previous['at'] ) );
		\WP_CLI::confirm( 'Restore it?', $assoc_args );
		$result = Transfer::undo();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( Transfer::error_message( (string) $result->get_error_code() ) );
		}
		Cli::success( 'The settings from before the last import are back.' );
	}
}
