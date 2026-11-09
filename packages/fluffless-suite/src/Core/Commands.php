<?php
/**
 * Commandes WP-CLI de soutien : wp fluffless diagnostic, wp fluffless update check, wp fluffless settings export|import|undo.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

use Fluffless\Suite\Admin\TransferCli;
use Fluffless\Suite\Modules\Media\Cli;
use Fluffless\Suite\Modules\Updates;

defined( 'ABSPATH' ) || exit;

/** Enregistrées seulement quand WP-CLI tourne (voir Cli::add()). */
final class Commands {

	/** Déclare les commandes. */
	public static function register(): void {
		Cli::add( 'fluffless diagnostic', array( self::class, 'diagnostic' ) );
		Cli::add( 'fluffless update check', array( self::class, 'update_check' ) );
		TransferCli::register();
	}

	/**
	 * Affiche le diagnostic joint aux signalements (aucune donnée personnelle).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : text ou json.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * @param array<int, string>   $args       Arguments positionnels.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public static function diagnostic( array $args, array $assoc_args ): void {
		unset( $args );
		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			Cli::log( (string) wp_json_encode( Diagnostic::collect() + array( 'site' => Api::site_hash() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		Cli::log( Diagnostic::text() );
	}

	/**
	 * Interroge l'API tout de suite pour chaque paquet Fluffless, sans attendre le cache de 12 h.
	 *
	 * @param array<int, string>   $args       Arguments positionnels.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public static function update_check( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		if ( ! Loader::is_active( Updates::id() ) ) {
			Cli::log( 'The Updates module is turned off: turn it on in Fluffless → Modules.' );
			return;
		}
		$blocked = Updates::status();
		if ( '' !== $blocked ) {
			Cli::log( $blocked );
		}
		foreach ( Updates::check_all() as $slug => $row ) {
			$why  = Updates::outcome_message( $row['outcome'] );
			$line = match ( true ) {
				'' !== $row['state']                                          => 'not checked',
				'' === $row['available']                                      => '' === $why ? 'no answer from the API, try again later' : $why,
				version_compare( $row['available'], $row['installed'], '>' ) => $row['available'] . ' available',
				default                                                       => 'up to date',
			};
			Cli::log( sprintf( '%s %s: %s', $slug, $row['installed'], $line ) );
		}
		Cli::success( 'Check finished.' );
	}
}
