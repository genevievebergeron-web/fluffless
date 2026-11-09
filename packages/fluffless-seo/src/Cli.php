<?php
/**
 * Commandes WP-CLI : wp fluffless-seo redirect|404|import.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Chaque commande délègue à Store ou Importer : la ligne de commande et l'écran
 * d'administration passent par les mêmes validations.
 */
final class Cli {

	/** Enregistre les commandes. */
	public static function register(): void {
		$commands = array(
			'redirect list'   => array( 'redirect_list', __( 'List redirects, newest first.', 'fluffless-seo' ) ),
			'redirect add'    => array( 'redirect_add', __( 'Add or replace a redirect.', 'fluffless-seo' ) ),
			'redirect delete' => array( 'redirect_delete', __( 'Delete a redirect by ID.', 'fluffless-seo' ) ),
			'redirect import' => array( 'redirect_import', __( 'Import redirects from a CSV file: source,target,code,regex.', 'fluffless-seo' ) ),
			'404 list'        => array( 'not_found_list', __( 'List not found addresses, most frequent first.', 'fluffless-seo' ) ),
			'404 purge'       => array( 'not_found_purge', __( 'Delete entries older than 90 days, or all with --all.', 'fluffless-seo' ) ),
			'import'          => array( 'import', __( 'Copy SEO fields from Yoast SEO, SEOPress or Rank Math. Dry run unless --apply.', 'fluffless-seo' ) ),
		);
		foreach ( $commands as $name => [ $method, $summary ] ) {
			\WP_CLI::add_command(
				'fluffless-seo ' . $name,
				array( self::class, $method ),
				array(
					'shortdesc' => $summary,
					'synopsis'  => self::synopsis( $method ),
				)
			);
		}
	}

	/**
	 * Arguments de chaque commande.
	 *
	 * @param string $method Méthode.
	 * @return list<array<string, mixed>>
	 */
	private static function synopsis( string $method ): array {
		$format = array(
			'type'     => 'assoc',
			'name'     => 'format',
			'optional' => true,
			'default'  => 'table',
			'options'  => array( 'table', 'csv', 'json' ),
		);
		return match ( $method ) {
			'redirect_list'   => array( $format ),
			'redirect_add'    => array(
				array(
					'type' => 'positional',
					'name' => 'source',
				),
				array(
					'type'     => 'positional',
					'name'     => 'target',
					'optional' => true,
				),
				array(
					'type'     => 'assoc',
					'name'     => 'code',
					'optional' => true,
					'default'  => '301',
					'options'  => array( '301', '302', '307', '410' ),
				),
				array(
					'type'     => 'flag',
					'name'     => 'regex',
					'optional' => true,
				),
			),
			'redirect_delete' => array(
				array(
					'type' => 'positional',
					'name' => 'id',
				),
			),
			'redirect_import' => array(
				array(
					'type' => 'positional',
					'name' => 'file',
				),
			),
			'not_found_list'  => array(
				array(
					'type'     => 'assoc',
					'name'     => 'limit',
					'optional' => true,
					'default'  => '50',
				),
				$format,
			),
			'not_found_purge' => array(
				array(
					'type'     => 'flag',
					'name'     => 'all',
					'optional' => true,
				),
			),
			'import'          => array(
				array(
					'type'    => 'positional',
					'name'    => 'source',
					'options' => Importer::sources(),
				),
				array(
					'type'     => 'flag',
					'name'     => 'apply',
					'optional' => true,
				),
			),
			default           => array(),
		};
	}

	/**
	 * Wp fluffless-seo redirect list.
	 *
	 * @param string[]              $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public static function redirect_list( array $args, array $assoc ): void {
		unset( $args );
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', Store::redirects( 500 ), array( 'id', 'source', 'target', 'code', 'is_regex', 'hits', 'last_hit' ) );
	}

	/**
	 * Wp fluffless-seo redirect add <source> [<target>] [--code=301] [--regex].
	 *
	 * @param string[]              $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public static function redirect_add( array $args, array $assoc ): void {
		try {
			$id = Store::add_redirect( $args[0] ?? '', $args[1] ?? '', (int) ( $assoc['code'] ?? 301 ), isset( $assoc['regex'] ) );
		} catch ( \InvalidArgumentException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		/* translators: %d: redirect ID. */
		\WP_CLI::success( sprintf( __( 'Redirect %d saved.', 'fluffless-seo' ), $id ) );
	}

	/**
	 * Wp fluffless-seo redirect delete <id>.
	 *
	 * @param string[] $args Arguments.
	 */
	public static function redirect_delete( array $args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( ! Store::delete_redirect( $id ) ) {
			/* translators: %d: redirect ID. */
			\WP_CLI::error( sprintf( __( 'No redirect with ID %d.', 'fluffless-seo' ), $id ) );
		}
		/* translators: %d: redirect ID. */
		\WP_CLI::success( sprintf( __( 'Redirect %d deleted.', 'fluffless-seo' ), $id ) );
	}

	/**
	 * Wp fluffless-seo redirect import <file>.
	 *
	 * @param string[] $args Arguments.
	 */
	public static function redirect_import( array $args ): void {
		try {
			$result = Store::import_csv( $args[0] ?? '' );
		} catch ( \RuntimeException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		foreach ( $result['errors'] as $message ) {
			\WP_CLI::warning( $message );
		}
		/* translators: %d: number of redirects. */
		\WP_CLI::success( sprintf( _n( '%d redirect imported.', '%d redirects imported.', $result['added'], 'fluffless-seo' ), $result['added'] ) );
	}

	/**
	 * Wp fluffless-seo 404 list [--limit=50].
	 *
	 * @param string[]              $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public static function not_found_list( array $args, array $assoc ): void {
		unset( $args );
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', Store::not_found( (int) ( $assoc['limit'] ?? 50 ) ), array( 'url', 'hits', 'last_seen' ) );
	}

	/**
	 * Wp fluffless-seo 404 purge [--all].
	 *
	 * @param string[]              $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public static function not_found_purge( array $args, array $assoc ): void {
		unset( $args );
		$count = Store::purge( isset( $assoc['all'] ) );
		/* translators: %d: number of entries. */
		\WP_CLI::success( sprintf( _n( '%d entry deleted.', '%d entries deleted.', $count, 'fluffless-seo' ), $count ) );
	}

	/**
	 * Wp fluffless-seo import <yoast|seopress|rankmath> [--apply].
	 *
	 * @param string[]              $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public static function import( array $args, array $assoc ): void {
		$apply = isset( $assoc['apply'] );
		try {
			$report = Importer::run( $args[0] ?? '', $apply );
		} catch ( \InvalidArgumentException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		foreach ( $report['fields'] as $key => $count ) {
			\WP_CLI::log( sprintf( '%s: %d', $key, $count ) );
		}
		$summary = sprintf(
			/* translators: 1: posts scanned, 2: fields written or to write, 3: fields kept because already filled. */
			__( '%1$d posts scanned, %2$d fields, %3$d kept as they were already filled.', 'fluffless-seo' ),
			$report['posts'],
			$report['written'],
			$report['kept']
		);
		if ( $apply ) {
			\WP_CLI::success( $summary );
		} else {
			\WP_CLI::log( __( 'Dry run: nothing was written. Add --apply to import.', 'fluffless-seo' ) );
			\WP_CLI::log( $summary );
		}
	}
}
