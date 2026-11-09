<?php
/**
 * Registre des serveurs que le site contacte avec ses réglages courants.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

use Fluffless\Suite\Blocks\Sections;
use Fluffless\Suite\Modules\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Liste calculée, jamais observée : chaque module allumé déclare ses hôtes (DeclaresHosts),
 * WordPress ajoute les siens (core()), les autres extensions passent par le filtre
 * fluffless_outgoing_hosts. Aucune requête réseau, aucune écriture en base.
 *
 * Champs d'une entrée :
 * - host : domaine, vide pour une entrée « linked » (des domaines qui dépendent du contenu) ;
 * - scope : host ou linked ;
 * - service : libellés réunis quand deux entrées visent le même domaine du même côté ;
 * - side : server (ton serveur), browser (les visites), admin_browser (les personnes qui gèrent le site) ;
 * - when : always, action, cron, dashboard, editor, after_click, after_consent, on_publish, on_email ;
 * - data : ce qui part ;
 * - active : faux si le réglage nécessaire est vide ;
 * - env_off : vrai si WordPress coupe cet envoi dans cet environnement ;
 * - http : vrai si l'envoi passe par l'API HTTP de WordPress, donc par Requêtes sortantes ;
 * - location : siège du service, seulement s'il est fourni (Consent) ;
 * - status : active, inactive, env_off, blocked ou blocked_linked.
 *
 * @phpstan-type Entry array{host: string, scope: string, service: list<string>, side: string, when: list<string>, data: list<string>, active: bool, env_off: bool, http: bool, location: string, status: string}
 */
final class Hosts {

	/** Côtés, dans l'ordre de l'écran. */
	public const SIDES = array( 'server', 'browser', 'admin_browser' );

	/** Moments reconnus. */
	public const WHEN = array( 'always', 'action', 'cron', 'dashboard', 'editor', 'after_click', 'after_consent', 'on_publish', 'on_email' );

	/**
	 * Toutes les entrées, nettoyées, fusionnées, avec leur état.
	 *
	 * @return list<Entry>
	 */
	public static function all(): array {
		$raw = self::suite();
		foreach ( Loader::modules() as $id => $class ) {
			if ( Options::is_enabled( $id ) && is_a( $class, DeclaresHosts::class, true ) ) {
				$raw = array_merge( $raw, $class::hosts() );
			}
		}
		$raw = array_merge( $raw, Sections::hosts(), self::core() );
		/**
		 * Serveurs contactés par une autre extension, avec ses réglages courants. Chaque entrée :
		 * host, service, side (server, browser, admin_browser), when, data, active, location.
		 * Une entrée dont le domaine n'est pas valide est ignorée.
		 *
		 * @param list<array<string, mixed>> $entries Entrées déjà connues.
		 *
		 * @var mixed $filtered Un filtre tiers peut renvoyer autre chose qu'une liste : vérifié ci-dessous.
		 */
		$filtered = apply_filters( 'fluffless_outgoing_hosts', array() );
		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $entry ) {
				if ( is_array( $entry ) ) {
					$entry['scope'] = 'host';
					$raw[]          = $entry;
				}
			}
		}
		return self::with_status( self::merge( $raw ) );
	}

	/**
	 * Entrées d'un côté.
	 *
	 * @param array<int, array<string, mixed>> $entries Entrées.
	 * @param string                           $side    Côté.
	 * @phpstan-param list<Entry> $entries
	 * @return list<Entry>
	 */
	public static function side( array $entries, string $side ): array {
		return array_values( array_filter( $entries, static fn( array $entry ): bool => $side === $entry['side'] ) );
	}

	/**
	 * Une entrée nettoyée, ou null si elle est invalide. Le domaine passe par wp_parse_url(),
	 * strtolower() et Sanitize::is_hostname() ; une entrée linked n'a pas de domaine.
	 *
	 * @param array<string, mixed> $raw Entrée brute.
	 * @return Entry|null
	 */
	public static function clean( array $raw ): ?array {
		$scope = 'linked' === ( $raw['scope'] ?? 'host' ) ? 'linked' : 'host';
		$host  = is_string( $raw['host'] ?? null ) ? trim( $raw['host'] ) : '';
		if ( 'host' === $scope && '' !== $host ) {
			$parsed = wp_parse_url( str_contains( $host, '://' ) ? $host : 'https://' . $host );
			if ( ! is_array( $parsed ) || ! is_string( $parsed['host'] ?? null ) || ( isset( $parsed['path'] ) && '/' !== $parsed['path'] ) || isset( $parsed['query'] ) ) {
				return null;
			}
			$host = strtolower( rtrim( $parsed['host'], '.' ) );
			if ( ! Sanitize::is_hostname( $host ) ) {
				return null;
			}
		} elseif ( 'host' === $scope && false !== ( $raw['active'] ?? true ) ) {
			// Un domaine vide n'est admis que pour un réglage vide (état « Inactif »).
			return null;
		}
		if ( 'linked' === $scope ) {
			$host = '';
		}
		$service = is_string( $raw['service'] ?? null ) ? sanitize_text_field( $raw['service'] ) : '';
		$side    = in_array( $raw['side'] ?? '', self::SIDES, true ) ? (string) $raw['side'] : '';
		$when    = in_array( $raw['when'] ?? '', self::WHEN, true ) ? (string) $raw['when'] : 'action';
		if ( '' === $service || '' === $side ) {
			return null;
		}
		$data = is_string( $raw['data'] ?? null ) ? sanitize_text_field( $raw['data'] ) : '';
		return array(
			'host'     => $host,
			'scope'    => $scope,
			'service'  => array( $service ),
			'side'     => $side,
			'when'     => array( $when ),
			'data'     => '' === $data ? array() : array( $data ),
			'active'   => false !== ( $raw['active'] ?? true ),
			'env_off'  => true === ( $raw['env_off'] ?? false ),
			'http'     => 'server' === $side && false !== ( $raw['http'] ?? true ),
			'location' => is_string( $raw['location'] ?? null ) ? sanitize_text_field( $raw['location'] ) : '',
			'status'   => '',
		);
	}

	/**
	 * Nettoie et fusionne : même domaine (ou même service linked) du même côté donne une ligne.
	 *
	 * @param list<array<string, mixed>> $raw Entrées brutes.
	 * @return list<Entry>
	 */
	private static function merge( array $raw ): array {
		$merged = array();
		foreach ( $raw as $entry ) {
			$clean = self::clean( $entry );
			if ( null === $clean ) {
				continue;
			}
			$key = $clean['side'] . '|' . ( '' !== $clean['host'] ? $clean['host'] : 'linked:' . $clean['service'][0] );
			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = $clean;
				continue;
			}
			$merged[ $key ]['service']  = array_values( array_unique( array_merge( $merged[ $key ]['service'], $clean['service'] ) ) );
			$merged[ $key ]['when']     = array_values( array_unique( array_merge( $merged[ $key ]['when'], $clean['when'] ) ) );
			$merged[ $key ]['data']     = array_values( array_unique( array_merge( $merged[ $key ]['data'], $clean['data'] ) ) );
			$merged[ $key ]['active']   = $merged[ $key ]['active'] || $clean['active'];
			$merged[ $key ]['env_off']  = $merged[ $key ]['env_off'] && $clean['env_off'];
			$merged[ $key ]['http']     = $merged[ $key ]['http'] || $clean['http'];
			$merged[ $key ]['location'] = '' !== $merged[ $key ]['location'] ? $merged[ $key ]['location'] : $clean['location'];
		}
		return array_values( $merged );
	}

	/**
	 * État de chaque entrée. Requêtes sortantes ne voit que ton serveur, et seulement l'API HTTP :
	 * une entrée du navigateur ou du SMTP n'est jamais bloquée. La liste est celle de Http::filter().
	 *
	 * @param array<int, array<string, mixed>> $entries Entrées.
	 * @phpstan-param list<Entry> $entries
	 * @return list<Entry>
	 */
	private static function with_status( array $entries ): array {
		$firewall = Options::is_enabled( Http::id() );
		$allowed  = Http::effective_allowed();
		foreach ( $entries as $i => $entry ) {
			if ( $entry['env_off'] ) {
				$status = 'env_off';
			} elseif ( ! $entry['active'] ) {
				$status = 'inactive';
			} elseif ( $firewall && $entry['http'] ) {
				if ( 'linked' === $entry['scope'] ) {
					$status = 'blocked_linked';
				} else {
					$status = Http::is_allowed( $entry['host'], $allowed ) ? 'active' : 'blocked';
				}
			} else {
				$status = 'active';
			}
			$entries[ $i ]['status'] = $status;
		}
		return $entries;
	}

	/**
	 * Entrées de la Suite hors modules : le signalement d'un problème part toujours vers l'API.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function suite(): array {
		return array(
			array(
				'host'    => Api::host(),
				'service' => __( 'Fluffless API: problem reports', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'action',
				'data'    => __( 'Your description and, if you tick the box, the diagnostic', 'fluffless-suite' ),
			),
		);
	}

	/**
	 * Entrées de WordPress, calculées depuis ses options et ses fonctions, sans réseau.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function core(): array {
		$pings_off = function_exists( 'wp_should_disable_pings_for_environment' ) && wp_should_disable_pings_for_environment();
		$entries   = array(
			array(
				'host'    => 'api.wordpress.org',
				'service' => __( 'WordPress updates', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'cron',
				'data'    => __( 'Versions of WordPress, PHP, themes, plugins and translations', 'fluffless-suite' ),
			),
			array(
				'host'    => 'downloads.wordpress.org',
				'service' => __( 'WordPress updates', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'cron',
				'data'    => __( 'A package request', 'fluffless-suite' ),
			),
			array(
				'host'    => 'api.wordpress.org',
				'service' => __( 'WordPress events and news', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'dashboard',
				'data'    => __( 'Browser and PHP versions, the admin language and an approximate location', 'fluffless-suite' ),
			),
			array(
				'host'    => 'wordpress.org',
				'service' => __( 'WordPress events and news', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'dashboard',
				'data'    => __( 'A news feed request', 'fluffless-suite' ),
			),
			array(
				'host'    => 'planet.wordpress.org',
				'service' => __( 'WordPress events and news', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'dashboard',
				'data'    => __( 'A news feed request', 'fluffless-suite' ),
			),
			array(
				'host'    => 'api.wordpress.org',
				'service' => __( 'Block and pattern directory', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'editor',
				'data'    => __( 'The site language and the WordPress version', 'fluffless-suite' ),
			),
			array(
				'host'    => 'api.openverse.org',
				'service' => __( 'Openverse media search', 'fluffless-suite' ),
				'side'    => 'admin_browser',
				'when'    => 'after_click',
				'data'    => __( 'Search words', 'fluffless-suite' ),
			),
			array(
				'host'    => 's.w.org',
				'service' => __( 'Google Fonts collection of the Font Library', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'after_click',
				'data'    => __( 'A file request', 'fluffless-suite' ),
			),
			array(
				'host'    => 'fonts.gstatic.com',
				'service' => __( 'Google Fonts collection of the Font Library', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'after_click',
				'data'    => __( 'A file request', 'fluffless-suite' ),
			),
			array(
				'scope'   => 'linked',
				'service' => __( 'Sites linked in your posts (pingbacks)', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'on_publish',
				'data'    => __( 'The address of your post', 'fluffless-suite' ),
				'active'  => 1 === (int) get_option( 'default_pingback_flag' ),
				'env_off' => $pings_off,
			),
			array(
				'scope'   => 'linked',
				'service' => __( 'Audio and video files linked in your posts', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'on_publish',
				'data'    => __( 'A file request', 'fluffless-suite' ),
			),
			array(
				'scope'   => 'linked',
				'service' => __( 'The service behind each embedded address: YouTube, Vimeo…', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'editor',
				'data'    => __( 'The embedded address', 'fluffless-suite' ),
			),
		);
		foreach ( self::ping_hosts() as $host ) {
			$entries[] = array(
				'host'    => $host,
				'service' => __( 'Update services (ping)', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'on_publish',
				'data'    => __( 'The name and address of your site', 'fluffless-suite' ),
				'env_off' => $pings_off,
			);
		}
		if ( '1' === (string) get_option( 'show_avatars' ) ) {
			$avatar    = get_avatar_url( 'exemple@exemple.invalid' );
			$entries[] = array(
				'host'    => is_string( $avatar ) ? (string) wp_parse_url( $avatar, PHP_URL_HOST ) : '',
				'service' => __( 'Profile pictures (Gravatar)', 'fluffless-suite' ),
				'side'    => 'admin_browser',
				'when'    => 'always',
				'data'    => __( 'A fingerprint of each email address shown', 'fluffless-suite' ),
			);
		}
		if ( ! Options::is_enabled( Cleanup::id() ) || ! Options::get_bool( Cleanup::id(), 'emoji' ) ) {
			$entries[] = array(
				'host'    => 's.w.org',
				'service' => __( 'WordPress emoji', 'fluffless-suite' ),
				'side'    => 'browser',
				'when'    => 'always',
				'data'    => __( 'IP address and browser of each visitor whose browser lacks an emoji', 'fluffless-suite' ),
			);
		}
		return $entries;
	}

	/**
	 * Domaines des services de mise à jour (option ping_sites), vides si le site demande à ne pas
	 * être indexé (même règle que privacy_ping_filter du cœur). Une ligne sans domaine est ignorée.
	 *
	 * @return list<string>
	 */
	public static function ping_hosts(): array {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return array();
		}
		$hosts = array();
		$lines = preg_split( '/\R/', (string) get_option( 'ping_sites' ) );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$host = wp_parse_url( trim( $line ), PHP_URL_HOST );
			if ( is_string( $host ) && Sanitize::is_hostname( strtolower( $host ) ) ) {
				$hosts[] = strtolower( $host );
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Libellé d'un moment.
	 *
	 * @param string $when Moment.
	 */
	public static function when_label( string $when ): string {
		$labels = array(
			'always'        => __( 'On every page view', 'fluffless-suite' ),
			'action'        => __( 'When you click a button', 'fluffless-suite' ),
			'cron'          => __( 'On a schedule (WP-Cron)', 'fluffless-suite' ),
			'dashboard'     => __( 'When the dashboard opens', 'fluffless-suite' ),
			'editor'        => __( 'When the editor opens', 'fluffless-suite' ),
			'after_click'   => __( 'After a click', 'fluffless-suite' ),
			'after_consent' => __( 'After consent', 'fluffless-suite' ),
			'on_publish'    => __( 'When a post is published', 'fluffless-suite' ),
			'on_email'      => __( 'Each time the site sends an email', 'fluffless-suite' ),
		);
		return $labels[ $when ] ?? $when;
	}

	/**
	 * Libellé d'un état.
	 *
	 * @param string $status État.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'active'         => __( 'Active', 'fluffless-suite' ),
			'inactive'       => __( 'Inactive: setting left empty', 'fluffless-suite' ),
			'env_off'        => __( 'Turned off by WordPress on this environment', 'fluffless-suite' ),
			'blocked'        => __( 'Blocked by Outgoing requests', 'fluffless-suite' ),
			'blocked_linked' => __( 'Blocked, except allowed domains', 'fluffless-suite' ),
		);
		return $labels[ $status ] ?? $status;
	}
}
