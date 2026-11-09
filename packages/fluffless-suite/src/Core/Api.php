<?php
/**
 * Client de l'API Fluffless (mises à jour, détails, licences, signalements).
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Seul endroit qui construit une requête vers l'API. Les requêtes passent par wp_remote_*(),
 * donc par la liste blanche du module Requêtes sortantes quand il est actif.
 *
 * Loi 25 : le user-agent par défaut de WordPress contient l'adresse du site en clair ;
 * il est remplacé ici, et le site n'est désigné que par l'empreinte SHA-256 de son adresse normalisée.
 */
final class Api {

	public const DEFAULT_URL = 'https://api.fluffless.dev';
	public const TIMEOUT     = 10;

	/** Taille maximale d'une réponse lue (les réponses attendues font quelques ko). */
	private const MAX_RESPONSE = 512 * KB_IN_BYTES;

	/** Adresse de base, sans barre finale : constante FLUFFLESS_API_URL (wp-config.php) ou défaut. */
	public static function base(): string {
		$url = defined( 'FLUFFLESS_API_URL' ) ? constant( 'FLUFFLESS_API_URL' ) : '';
		$url = is_string( $url ) ? esc_url_raw( trim( $url ), array( 'https', 'http' ) ) : '';
		return untrailingslashit( '' === $url ? self::DEFAULT_URL : $url );
	}

	/** Hôte de l'API, pour la liste des hôtes signés. */
	public static function host(): string {
		$host = wp_parse_url( self::base(), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/** En-tête qui porte la licence : jamais dans l'adresse, qui finit dans les journaux. */
	public const LICENCE_HEADER = 'X-Fluffless-Licence';

	/**
	 * Adresse normalisée avant l'empreinte, règle reproduite par le serveur (docs/PUBLICATION.md) :
	 * schéma retiré, hôte en minuscules, port retiré s'il est celui du schéma (80, 443),
	 * chemin gardé tel quel sans barre finale. « www. » est gardé.
	 *
	 * @param string $url Adresse du site, ex. https://Exemple.ca:443/blogue/.
	 */
	public static function site_identity( string $url ): string {
		$parts   = wp_parse_url( $url );
		$parts   = is_array( $parts ) ? $parts : array();
		$scheme  = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$port    = (int) ( $parts['port'] ?? 0 );
		$default = ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port );
		return strtolower( (string) ( $parts['host'] ?? '' ) )
			. ( 0 !== $port && ! $default ? ':' . $port : '' )
			. rtrim( (string) ( $parts['path'] ?? '' ), '/' );
	}

	/** Suffixes d'un hôte de développement (décision 7), comparés avec le point. Liste fermée. */
	public const DEV_SUFFIXES = array( '.localhost', '.local', '.test' );

	/** Hôtes de bouclage comptés comme locaux (décision 34). Les IP privées ne le sont pas. */
	private const DEV_HOSTS = array( 'localhost', '127.0.0.1', '::1' );

	/** Environnements WordPress d'un site de développement ou de préproduction (décision 34). */
	private const DEV_ENVIRONMENTS = array( 'local', 'development', 'staging' );

	/**
	 * Hôte local : localhost, *.localhost, *.local, *.test, 127.0.0.1 ou [::1].
	 *
	 * @param string $host Hôte, avec ou sans crochets ni point final.
	 */
	public static function is_dev_host( string $host ): bool {
		$host = rtrim( trim( strtolower( $host ), '[]' ), '.' );
		if ( in_array( $host, self::DEV_HOSTS, true ) ) {
			return true;
		}
		foreach ( self::DEV_SUFFIXES as $suffix ) {
			if ( strlen( $host ) > strlen( $suffix ) && str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Site de développement : hôte local, ou environnement local, development ou staging.
	 * Seule la Suite voit l'adresse : le serveur croit ce drapeau, borné par un plafond interne.
	 *
	 * @param string $host        Hôte du site.
	 * @param string $environment Valeur de wp_get_environment_type().
	 */
	public static function site_is_dev( string $host, string $environment ): bool {
		return self::is_dev_host( $host ) || in_array( $environment, self::DEV_ENVIRONMENTS, true );
	}

	/** Ce site-ci est-il un site de développement ? */
	public static function current_site_is_dev(): bool {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return self::site_is_dev( is_string( $host ) ? $host : '', wp_get_environment_type() );
	}

	/** Empreinte du site : SHA-256 de l'adresse normalisée, jamais l'adresse elle-même. */
	public static function site_hash(): string {
		return hash( 'sha256', self::site_identity( home_url() ) );
	}

	/**
	 * Requête GET.
	 *
	 * @param string                $path    Chemin, ex. /v1/update.
	 * @param array<string, string> $query   Paramètres.
	 * @param string                $licence Clé de licence, envoyée en en-tête ; '' pour aucune.
	 * @return array{code: int, body: mixed, notice_until: string}|\WP_Error
	 */
	public static function get( string $path, array $query, string $licence = '' ) {
		return self::parse( wp_remote_get( add_query_arg( array_map( 'rawurlencode', $query ), self::base() . $path ), self::args( $licence ) ) );
	}

	/**
	 * Requête POST en JSON.
	 *
	 * @param string       $path    Chemin, ex. /v1/reports.
	 * @param array<mixed> $data    Corps.
	 * @param string       $licence Clé de licence, envoyée en en-tête ; '' pour aucune.
	 * @return array{code: int, body: mixed, notice_until: string}|\WP_Error
	 */
	public static function post( string $path, array $data, string $licence = '' ) {
		$args            = self::args( $licence );
		$args['body']    = (string) wp_json_encode( $data );
		$args['headers'] = array( 'Content-Type' => 'application/json' ) + $args['headers'];
		return self::parse( wp_remote_post( self::base() . $path, $args ) );
	}

	/**
	 * Arguments communs.
	 *
	 * @param string $licence Clé de licence ; '' pour aucune.
	 * @return array{timeout: int, user-agent: string, limit_response_size: int, headers: array<string, string>}
	 */
	private static function args( string $licence ): array {
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $licence ) {
			$headers[ self::LICENCE_HEADER ] = $licence;
		}
		return array(
			'timeout'             => self::TIMEOUT,
			'user-agent'          => 'FlufflessSuite/' . VERSION,
			'limit_response_size' => self::MAX_RESPONSE,
			'headers'             => $headers,
		);
	}

	/**
	 * Code, corps décodé et en-tête d'avis ; une erreur de transport reste une WP_Error.
	 *
	 * @param array<mixed>|\WP_Error $response Réponse brute.
	 * @return array{code: int, body: mixed, notice_until: string}|\WP_Error
	 */
	private static function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body   = wp_remote_retrieve_body( $response );
		$notice = wp_remote_retrieve_header( $response, 'x-fluffless-notice-until' );
		return array(
			'code'         => (int) wp_remote_retrieve_response_code( $response ),
			'body'         => '' === $body ? null : json_decode( $body, true ),
			// Avis écrit en cours sur la licence : seul moyen de le dire sur un 204, qui n'a pas de corps.
			'notice_until' => is_string( $notice ) ? $notice : '',
		);
	}
}
