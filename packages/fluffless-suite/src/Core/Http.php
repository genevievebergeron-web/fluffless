<?php
/**
 * Liste blanche des requêtes sortantes (pre_http_request).
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

use Fluffless\Suite\Modules\Newsletter;

defined( 'ABSPATH' ) || exit;

/**
 * Désactivé par défaut : l'activer peut bloquer une extension qui appelle son serveur.
 * Le site lui-même (boucle locale, WP-Cron) est toujours autorisé.
 */
final class Http implements Module {

	/** Nombre de domaines refusés gardés au journal. */
	public const LOG_SIZE = 20;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'http';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Outgoing requests', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Lets your site contact only the services you approve. Off by default: turning it on can block plugins, and WordPress embeds or Google Fonts in the Font Library, until you allow their domain.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array( 'allowed_hosts' => array( 'wordpress.org', 'fluffless.dev' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array(
			'allowed_hosts' => array(
				'type'  => 'hosts',
				'label' => __( 'Allowed domains', 'fluffless-suite' ),
				'help'  => __( 'One domain per line. Subdomains are included (wordpress.org covers api.wordpress.org). Your own site is always allowed.', 'fluffless-suite' ),
			),
		);
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_filter( 'pre_http_request', array( $this, 'filter' ), 10, 3 );
	}

	/**
	 * Laisse passer, ou court-circuite la requête par une erreur.
	 *
	 * @param false|array<mixed>|\WP_Error $pre  Réponse déjà fournie par un autre filtre.
	 * @param array<mixed>                 $args Arguments de la requête.
	 * @param string                       $url  Adresse demandée.
	 * @return false|array<mixed>|\WP_Error
	 */
	public function filter( $pre, $args, $url ) {
		unset( $args );
		if ( false !== $pre ) {
			return $pre;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host || self::is_allowed( $host, self::effective_allowed() ) ) {
			return $pre;
		}
		$host = strtolower( $host );
		self::log( $host );
		return new \WP_Error(
			'fluffless_http_blocked',
			sprintf(
				/* translators: %s: domain name, e.g. example.com */
				__( 'Fluffless Suite blocked an outgoing request to %s. To allow it, add this domain in Fluffless → Modules.', 'fluffless-suite' ),
				$host
			)
		);
	}

	/**
	 * Liste blanche réellement appliquée par filter() : celle du réglage, plus l'API Fluffless,
	 * joignable même si FLUFFLESS_API_URL pointe hors de fluffless.dev (test, préproduction), et
	 * l'outil d'infolettre choisi dans le module Infolettre. L'écran Serveurs contactés lit la même.
	 *
	 * @return list<string>
	 */
	public static function effective_allowed(): array {
		return array_merge( Options::get_list( self::id(), 'allowed_hosts' ), array_filter( array( Api::host(), Newsletter::host() ) ) );
	}

	/**
	 * Domaine autorisé : le site lui-même, ou égal à une entrée, ou sous-domaine d'une entrée.
	 *
	 * @param string   $host    Domaine demandé.
	 * @param string[] $allowed Liste blanche.
	 * @phpstan-param list<string> $allowed
	 */
	public static function is_allowed( string $host, array $allowed ): bool {
		$host = strtolower( rtrim( $host, '.' ) );
		$own  = array( 'localhost', '127.0.0.1', '[::1]' );
		foreach ( array( home_url(), site_url() ) as $url ) {
			$own_host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $own_host ) ) {
				$own[] = strtolower( $own_host );
			}
		}
		foreach ( array_merge( $own, $allowed ) as $entry ) {
			if ( $host === $entry || str_ends_with( $host, '.' . $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Journal borné des refus : domaine => nombre et dernière date, gardé une semaine.
	 *
	 * @param string $host Domaine refusé.
	 */
	private static function log( string $host ): void {
		$log          = self::refusals();
		$count        = $log[ $host ]['count'] ?? 0;
		$log[ $host ] = array(
			'count' => $count + 1,
			'last'  => time(),
		);
		uasort( $log, static fn( array $a, array $b ): int => $b['last'] <=> $a['last'] );
		set_transient( Lifecycle::TRANSIENT_HTTP_REFUSALS, array_slice( $log, 0, self::LOG_SIZE, true ), WEEK_IN_SECONDS );
	}

	/**
	 * Refus récents, du plus récent au plus ancien.
	 *
	 * @return array<string, array{count: int, last: int}>
	 */
	public static function refusals(): array {
		$log   = get_transient( Lifecycle::TRANSIENT_HTTP_REFUSALS );
		$clean = array();
		foreach ( is_array( $log ) ? $log : array() as $host => $entry ) {
			if ( is_string( $host ) && is_array( $entry ) && is_int( $entry['count'] ?? null ) && is_int( $entry['last'] ?? null ) ) {
				$clean[ $host ] = array(
					'count' => $entry['count'],
					'last'  => $entry['last'],
				);
			}
		}
		return $clean;
	}
}
