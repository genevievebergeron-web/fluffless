<?php
/**
 * Module Statistiques : Plausible ou Umami, sans témoin, en defer.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\DeclaresHosts;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Le relais par le domaine du site (désactivé par défaut) passe par trois routes REST.
 * Il n'appelle jamais que l'hôte du script configuré : l'adresse de destination ne vient
 * pas de la requête du visiteur. Débit borné par adresse IP, corps borné, en-têtes minimaux.
 * L'adresse transmise au fournisseur est tronquée (/24 en IPv4, /64 en IPv6), sans exception.
 */
final class Stats implements Module, DeclaresHosts {

	public const REST_NAMESPACE = 'fluffless/v1';
	public const ROUTE_SCRIPT   = '/stats/script';
	public const ROUTE_EVENT    = '/stats/event';
	/** Umami ajoute « /api/send » à data-host-url : la route suit cette forme. */
	public const ROUTE_UMAMI = '/stats/api/send';

	/** Événements relayés par minute et par adresse IP. */
	public const RATE_LIMIT = 60;
	/** Événements relayés par minute pour tout le site, toutes adresses confondues. */
	public const GLOBAL_RATE_LIMIT = 600;

	/** Sans cache d'objets : le compteur global avance par tranches (une écriture sur dix par adresse). */
	public const GLOBAL_RATE_STEP = 10;
	public const RATE_PREFIX      = 'fluffless_stats_rl_';
	public const GLOBAL_RATE_KEY  = self::RATE_PREFIX . 'global';
	/** Taille maximale d'un événement reçu, en octets. */
	public const MAX_BODY = 4096;

	/** Échec du script mémorisé entre FAILURE_MIN et FAILURE_MAX minutes ; dernière copie gardée 30 jours. */
	private const FAILURE_MIN = 5;
	private const FAILURE_MAX = 15;
	private const COPY_TTL    = 30 * DAY_IN_SECONDS;

	/** Fournisseur => nom affiché et chemin de collecte chez le fournisseur. */
	private const PROVIDERS = array(
		'plausible' => array(
			'name'     => 'Plausible Analytics',
			'endpoint' => '/api/event',
		),
		'umami'     => array(
			'name'     => 'Umami',
			'endpoint' => '/api/send',
		),
	);

	/** {@inheritDoc} */
	public static function id(): string {
		return 'stats';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Statistics', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Counts visits with Plausible or Umami: no cookie, usually no consent banner needed, one small deferred script.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'provider'     => 'plausible',
			'script_url'   => '',
			'site_id'      => '',
			'skip_editors' => true,
			'proxy'        => false,
		);
	}

	/**
	 * {@inheritDoc} L'identifiant de site garde seulement les caractères d'un domaine ou d'un UUID.
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		$clean = Sanitize::section( $input, self::fields(), self::defaults() );
		if ( ! is_string( $clean['site_id'] ) || 1 !== preg_match( '/^[A-Za-z0-9.,:_-]{1,200}$/D', $clean['site_id'] ) ) {
			$clean['site_id'] = '';
		}
		return $clean;
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array(
			'provider'     => array(
				'type'    => 'choice',
				'label'   => __( 'Provider', 'fluffless-suite' ),
				'options' => array(
					'plausible' => 'Plausible',
					'umami'     => 'Umami',
				),
			),
			'script_url'   => array(
				'type'  => 'url',
				'label' => __( 'Script address', 'fluffless-suite' ),
				'help'  => __( 'https only, e.g. https://plausible.io/js/script.js or the script.js of your Umami instance.', 'fluffless-suite' ),
			),
			'site_id'      => array(
				'type'  => 'text',
				'label' => __( 'Site ID', 'fluffless-suite' ),
				'help'  => __( 'Plausible: your domain. Umami: the website ID.', 'fluffless-suite' ),
			),
			'skip_editors' => array(
				'type'  => 'bool',
				'label' => __( 'Don’t count people who edit the site', 'fluffless-suite' ),
				'help'  => __( 'Logged-in users who can write content are not counted.', 'fluffless-suite' ),
			),
			'proxy'        => array(
				'type'  => 'bool',
				'label' => __( 'Route through my domain', 'fluffless-suite' ),
				'help'  => __( 'The script and visits go through your site before reaching the provider, so ad blockers let them through. The provider only gets a shortened IP address, enough to find the country. People who share a network and a browser may count as one visitor. Costs one PHP request per page view. If Outgoing requests is on, add the provider’s domain to it.', 'fluffless-suite' ),
			),
		);
	}

	/** État affiché dans les réglages. */
	public static function status(): string {
		if ( '' === Options::get_string( self::id(), 'script_url' ) || '' === Options::get_string( self::id(), 'site_id' ) ) {
			return __( 'Nothing is counted until the script address and the site ID are filled in.', 'fluffless-suite' );
		}
		return '';
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'wp_head', array( self::class, 'print_script' ), 20 );
		add_action( 'admin_init', array( self::class, 'add_privacy_text' ) );
		if ( Options::get_bool( self::id(), 'proxy' ) ) {
			add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		}
	}

	/** Imprime la balise script, si la visite doit être comptée. */
	public static function print_script(): void {
		echo self::script_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_script_tag() échappe chaque attribut.
	}

	/** Balise script de la requête courante, ou '' (réglages incomplets, personne qui édite le site). */
	public static function script_tag(): string {
		$attributes = self::script_attributes();
		return array() === $attributes ? '' : wp_get_script_tag( $attributes );
	}

	/**
	 * Attributs de la balise. data-no-optimize tient le script hors de la combinaison JS de LiteSpeed Cache.
	 *
	 * @return array<string, string|true>
	 */
	public static function script_attributes(): array {
		$url     = Options::get_string( self::id(), 'script_url' );
		$site_id = Options::get_string( self::id(), 'site_id' );
		$skip    = Options::get_bool( self::id(), 'skip_editors' ) && current_user_can( 'edit_posts' );
		if ( '' === $url || '' === $site_id || $skip ) {
			return array();
		}
		$proxy      = Options::get_bool( self::id(), 'proxy' );
		$attributes = array(
			'src'              => $proxy ? rest_url( self::REST_NAMESPACE . self::ROUTE_SCRIPT ) : $url,
			'defer'            => true,
			'data-no-optimize' => '1',
		);
		if ( 'umami' === self::provider() ) {
			$attributes['data-website-id'] = $site_id;
			if ( $proxy ) {
				$attributes['data-host-url'] = untrailingslashit( rest_url( self::REST_NAMESPACE . '/stats' ) );
			}
		} else {
			$attributes['data-domain'] = $site_id;
			if ( $proxy ) {
				$attributes['data-api'] = rest_url( self::REST_NAMESPACE . self::ROUTE_EVENT );
			}
		}
		return $attributes;
	}

	/** Fournisseur valide. */
	private static function provider(): string {
		$provider = Options::get_string( self::id(), 'provider' );
		return isset( self::PROVIDERS[ $provider ] ) ? $provider : 'plausible';
	}

	/** Adresse de collecte : même hôte que le script, jamais une adresse reçue. */
	public static function endpoint(): string {
		$parts = wp_parse_url( Options::get_string( self::id(), 'script_url' ) );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return 'https://' . $parts['host'] . $port . self::PROVIDERS[ self::provider() ]['endpoint'];
	}

	/** Routes du relais. */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SCRIPT,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'serve_script' ),
				'permission_callback' => '__return_true',
			)
		);
		foreach ( array( self::ROUTE_EVENT, self::ROUTE_UMAMI ) as $route ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'relay_event' ),
					'permission_callback' => '__return_true',
				)
			);
		}
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_raw' ), 10, 3 );
	}

	/**
	 * Script du fournisseur : une copie fraîche une journée, puis gardée comme dernière copie valide.
	 * Un échec est mémorisé 5 à 15 minutes (délai aléatoire, pour ne pas relancer tous les sites
	 * en même temps) : le fournisseur en panne n'est pas rappelé à chaque page vue.
	 */
	public static function serve_script(): \WP_REST_Response|\WP_Error {
		$url    = Options::get_string( self::id(), 'script_url' );
		$cached = get_transient( Lifecycle::TRANSIENT_STATS_SCRIPT );
		$copy   = is_array( $cached ) && ( $cached['url'] ?? null ) === $url && is_string( $cached['body'] ?? null ) && '' !== $cached['body'] ? $cached['body'] : null;
		$fresh  = null !== $copy && is_int( $cached['fresh_until'] ?? null ) && $cached['fresh_until'] > time();

		if ( null !== $copy && ( $fresh || false !== get_transient( Lifecycle::TRANSIENT_STATS_FAILURE ) ) ) {
			return self::script_response( $copy );
		}
		if ( '' === $url || false !== get_transient( Lifecycle::TRANSIENT_STATS_FAILURE ) ) {
			return self::unavailable( 'fluffless_stats_script', '' === $url ? 'no script address is set up' : 'recent failure still cached' );
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 100 * KB_IN_BYTES,
			)
		);
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$body     = wp_remote_retrieve_body( $response );
		if ( 200 !== $code || '' === $body ) {
			set_transient( Lifecycle::TRANSIENT_STATS_FAILURE, time(), wp_rand( self::FAILURE_MIN, self::FAILURE_MAX ) * MINUTE_IN_SECONDS );
			$reason = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
			return null === $copy ? self::unavailable( 'fluffless_stats_script', $url . ' answered ' . $reason ) : self::script_response( $copy );
		}
		set_transient(
			Lifecycle::TRANSIENT_STATS_SCRIPT,
			array(
				'url'         => $url,
				'body'        => $body,
				'fresh_until' => time() + DAY_IN_SECONDS,
			),
			self::COPY_TTL
		);
		return self::script_response( $body );
	}

	/**
	 * Erreur 502 générique pour le navigateur ; le détail (hôte, code) va au journal seulement.
	 *
	 * @param string $code   Code d'erreur.
	 * @param string $detail Cause, jamais montrée au visiteur.
	 */
	private static function unavailable( string $code, string $detail ): \WP_Error {
		/**
		 * Échec du relais des statistiques, avec sa cause (hôte, code HTTP).
		 *
		 * @param string $code   Code d'erreur.
		 * @param string $detail Cause.
		 */
		do_action( 'fluffless_stats_failure', $code, $detail );
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// Journal du serveur seulement : le visiteur reçoit le message générique.
			error_log( 'Fluffless Suite, statistics relay: ' . $code . ': ' . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return new \WP_Error( $code, 'Statistics provider unavailable.', array( 'status' => 502 ) );
	}

	/**
	 * Réponse JavaScript du script relayé.
	 *
	 * @param string $body Script du fournisseur.
	 */
	private static function script_response( string $body ): \WP_REST_Response {
		return new \WP_REST_Response(
			$body,
			200,
			array(
				'Content-Type'           => 'application/javascript; charset=UTF-8',
				'Cache-Control'          => 'public, max-age=86400',
				'X-Content-Type-Options' => 'nosniff',
			)
		);
	}

	/**
	 * Sert le script tel quel plutôt qu'en chaîne JSON.
	 *
	 * @param mixed $served  Vrai si un autre filtre a déjà servi la réponse.
	 * @param mixed $result  Réponse.
	 * @param mixed $request Requête.
	 * @return mixed
	 */
	public static function serve_raw( $served, $result, $request ) {
		if ( $request instanceof \WP_REST_Request && '/' . self::REST_NAMESPACE . self::ROUTE_SCRIPT === $request->get_route()
			&& $result instanceof \WP_REST_Response && 200 === $result->get_status() && is_string( $result->get_data() ) ) {
			echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript du fournisseur configuré, servi en application/javascript avec nosniff.
			return true;
		}
		return $served;
	}

	/**
	 * Relaie un événement vers le fournisseur.
	 *
	 * @param \WP_REST_Request $request Requête du navigateur.
	 */
	public static function relay_event( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ip = self::client_ip();
		if ( ! self::within_rate_limit( $ip ) ) {
			return new \WP_Error( 'fluffless_stats_rate', 'Too many statistics events from this address; limit is ' . self::RATE_LIMIT . ' per minute.', array( 'status' => 429 ) );
		}
		$body = $request->get_body();
		if ( '' === $body || strlen( $body ) > self::MAX_BODY || ! is_array( json_decode( $body, true ) ) ) {
			return new \WP_Error( 'fluffless_stats_body', 'Statistics event rejected: expected a JSON object of at most ' . self::MAX_BODY . ' bytes, got ' . strlen( $body ) . ' bytes.', array( 'status' => 400 ) );
		}
		$endpoint = self::endpoint();
		if ( '' === $endpoint ) {
			return new \WP_Error( 'fluffless_stats_off', 'No statistics script address is set up.', array( 'status' => 404 ) );
		}
		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'             => 3,
				'redirection'         => 0,
				'limit_response_size' => KB_IN_BYTES,
				'body'                => $body,
				'headers'             => array(
					'Content-Type'    => 'application/json',
					'User-Agent'      => substr( (string) $request->get_header( 'user_agent' ), 0, 512 ),
					'X-Forwarded-For' => self::truncate_ip( $ip ),
				),
			)
		);
		$code     = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			return self::unavailable( 'fluffless_stats_upstream', $endpoint . ' answered ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code ) );
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return new \WP_REST_Response( is_array( $decoded ) ? $decoded : null, $code );
	}

	/**
	 * Au plus RATE_LIMIT événements par minute pour une adresse (clé hachée, rien en clair),
	 * et GLOBAL_RATE_LIMIT pour tout le site : faire tourner les adresses ne suffit pas.
	 *
	 * @param string $ip Adresse du visiteur.
	 * @phpstan-impure
	 */
	public static function within_rate_limit( string $ip ): bool {
		$key = self::rate_key( $ip );
		// Cache d'objets persistant : compteurs en mémoire, aucune écriture en base par événement.
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'fluffless_rate', MINUTE_IN_SECONDS );
			wp_cache_add( self::GLOBAL_RATE_KEY, 0, 'fluffless_rate', MINUTE_IN_SECONDS );
			$count  = (int) wp_cache_incr( $key, 1, 'fluffless_rate' );
			$global = (int) wp_cache_incr( self::GLOBAL_RATE_KEY, 1, 'fluffless_rate' );
			return $count <= self::RATE_LIMIT && $global <= self::GLOBAL_RATE_LIMIT;
		}
		$count  = (int) get_transient( $key );
		$global = (int) get_transient( self::GLOBAL_RATE_KEY );
		if ( $count >= self::RATE_LIMIT || $global >= self::GLOBAL_RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		// Sans cache d'objets, chaque écriture touche wp_options : le compteur global n'est écrit qu'au premier
		// événement d'une adresse, puis par tranche de GLOBAL_RATE_STEP (écart d'au plus STEP - 1 par adresse).
		$step = self::global_rate_step( $count );
		if ( $step > 0 ) {
			set_transient( self::GLOBAL_RATE_KEY, $global + $step, MINUTE_IN_SECONDS );
		}
		return true;
	}

	/**
	 * Ce qu'ajoute au compteur global l'événement suivant d'une adresse qui en compte déjà $count.
	 *
	 * @param int $count Événements déjà comptés pour l'adresse dans la minute.
	 */
	public static function global_rate_step( int $count ): int {
		if ( 0 === $count ) {
			return 1;
		}
		return 0 === $count % self::GLOBAL_RATE_STEP ? self::GLOBAL_RATE_STEP : 0;
	}

	/**
	 * Clé de limite de débit : l'adresse IPv4 entière, ou le préfixe /64 d'une IPv6
	 * (un seul abonnement reçoit souvent tout un /64 : chaque adresse ne compterait sinon qu'une fois).
	 *
	 * @param string $ip Adresse du visiteur.
	 */
	public static function rate_key( string $ip ): string {
		$packed  = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? inet_pton( $ip ) : false;
		$subject = false === $packed ? $ip : bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
		return self::RATE_PREFIX . substr( wp_hash( $subject ), 0, 20 );
	}

	/**
	 * Adresse transmise au fournisseur : IPv4 au /24 (dernier octet à zéro), IPv6 au /64,
	 * IPv6 qui encapsule une IPv4 (::ffff:a.b.c.d) traitée comme IPv4. Toute autre entrée : 0.0.0.0.
	 * Assez pour le pays, trop peu pour reconnaître une personne (décision 8).
	 *
	 * @param string $ip Adresse du visiteur, entière.
	 */
	public static function truncate_ip( string $ip ): string {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return (string) preg_replace( '/\.\d+$/', '.0', $ip );
		}
		$packed = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? inet_pton( $ip ) : false;
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return '0.0.0.0';
		}
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			return self::truncate_ip( (string) inet_ntop( substr( $packed, 12 ) ) );
		}
		return (string) inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) );
	}

	/** Adresse du visiteur : REMOTE_ADDR, jamais un en-tête falsifiable (filtre pour un mandataire inverse). */
	private static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Adresse IP du visiteur, avant troncature : la limite de débit la garde entière,
		 * le fournisseur la reçoit tronquée (/24, /64) par truncate_ip().
		 *
		 * @param string $remote REMOTE_ADDR.
		 */
		$ip = apply_filters( 'fluffless_stats_client_ip', $remote );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/** Paragraphe proposé pour la politique de confidentialité. */
	public static function add_privacy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Fluffless Suite: statistics', 'fluffless-suite' ), wpautop( esc_html( self::privacy_text() ) ) );
		}
	}

	/** Texte de la mention, selon le fournisseur et l'hôte configurés. */
	public static function privacy_text(): string {
		$host = wp_parse_url( Options::get_string( self::id(), 'script_url' ), PHP_URL_HOST );
		return sprintf(
			/* translators: 1: provider name (Plausible Analytics or Umami), 2: server domain name */
			__( 'This site measures its audience with %1$s. The tool sets no cookie and stores nothing on your device. It records the page visited, the referring site, the browser, the type of device and the country deduced from your IP address; the IP address itself is not kept. The statistics are stored on %2$s.', 'fluffless-suite' ),
			self::PROVIDERS[ self::provider() ]['name'],
			is_string( $host ) ? $host : '-'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function hosts(): array {
		$host  = (string) wp_parse_url( Options::get_string( self::id(), 'script_url' ), PHP_URL_HOST );
		$proxy = Options::get_bool( self::id(), 'proxy' );
		return array(
			array(
				'host'    => $host,
				'service' => __( 'Statistics', 'fluffless-suite' ),
				'side'    => $proxy ? 'server' : 'browser',
				'when'    => 'always',
				'data'    => $proxy
					? __( 'Address of the page, user agent and a shortened IP address of each visit', 'fluffless-suite' )
					: __( 'Address of the page, user agent and full IP address of each visitor', 'fluffless-suite' ),
				'active'  => '' !== $host && '' !== Options::get_string( self::id(), 'site_id' ),
			),
		);
	}
}
