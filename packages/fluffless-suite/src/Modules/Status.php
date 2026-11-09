<?php
/**
 * Module État du site : une route REST protégée par clé qui résume la santé du site pour un
 * tableau de parc externe (mises à jour en attente, bilan de sobriété, sauvegarde déclarée,
 * derniers événements du journal, mode maintenance).
 *
 * Lecture seule, jamais de donnée de visiteur, aucune adresse IP. La clé se lit dans la constante
 * FLUFFLESS_STATUS_KEY (wp-config.php) ou la variable d'environnement du même nom, jamais dans
 * une option ; elle voyage dans l'en-tête X-Fluffless-Key, jamais dans l'adresse. Un compte qui
 * peut gérer les options (connexion par témoin et nonce REST) lit la même réponse sans clé.
 * Rien n'est bâti au-delà de la route : le tableau de parc, s'il vient un jour, la consommera.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;
use Fluffless\Suite\Modules\Security\Journal;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * État du site par API.
 */
final class Status implements Module {

	/** Constante (ou variable d'environnement) qui porte la clé. */
	public const KEY_CONSTANT = 'FLUFFLESS_STATUS_KEY';

	/** En-tête qui transporte la clé. */
	public const HEADER = 'X-Fluffless-Key';

	/** Espace et chemin de la route. */
	public const REST_NS = 'fluffless/v1';
	public const ROUTE   = '/status';

	/** Longueur minimale d'une clé acceptée. */
	public const MIN_KEY = 16;

	/** Bornes du nombre d'événements du journal renvoyés. */
	public const MAX_EVENTS = 100;

	/** Identifiant du module. */
	public static function id(): string {
		return 'status';
	}

	/** Nom affiché. */
	public static function label(): string {
		return __( 'Site status', 'fluffless-suite' );
	}

	/** Description affichée. */
	public static function description(): string {
		return __( 'Answers a key-protected REST request with the site’s health: pending updates, sobriety check, declared backup, last activity log entries. For a dashboard that watches several sites. Read only, no visitor data.', 'fluffless-suite' );
	}

	/** Inactif sur une installation neuve. */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** Valeurs par défaut. */
	public static function defaults(): array {
		return array(
			'events' => 20,
		);
	}

	/**
	 * Assainissement de la section.
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** Champs des réglages. */
	public static function fields(): array {
		return array(
			'events' => array(
				'type'  => 'int',
				'label' => __( 'Activity log entries in the answer', 'fluffless-suite' ),
				'help'  => __( 'From 0 to 100, most recent first. Only when the activity log of the Basic security module is on. No IP address is ever sent.', 'fluffless-suite' ),
				'min'   => 0,
				'max'   => self::MAX_EVENTS,
			),
		);
	}

	/** État affiché sous la description : réclame la clé, puis rappelle l'adresse et l'en-tête. */
	public static function status(): string {
		if ( ! Options::is_enabled( self::id() ) ) {
			return '';
		}
		if ( '' === self::key() ) {
			/* translators: %s: name of the constant */
			return sprintf( __( 'No key: add define( \'%s\', \'a long random key\' ); to wp-config.php, 16 characters at least. Until then the route answers 503.', 'fluffless-suite' ), self::KEY_CONSTANT );
		}
		/* translators: 1: address of the route, 2: name of the header */
		return sprintf( __( 'Answers GET %1$s with the key in the %2$s header.', 'fluffless-suite' ), rest_url( self::REST_NS . self::ROUTE ), self::HEADER );
	}

	/** Pose les crochets. */
	public function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/** Clé lue dans la constante, sinon dans l'environnement ; vide si trop courte. */
	public static function key(): string {
		$key = defined( self::KEY_CONSTANT ) ? constant( self::KEY_CONSTANT ) : '';
		if ( ! is_string( $key ) || '' === trim( $key ) ) {
			$env = getenv( self::KEY_CONSTANT );
			$key = is_string( $env ) ? $env : '';
		}
		$key = trim( $key );
		return strlen( $key ) < self::MIN_KEY ? '' : $key;
	}

	/** Route. */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NS,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'respond' ),
				'permission_callback' => array( self::class, 'permission' ),
			)
		);
	}

	/**
	 * Clé exacte dans l'en-tête, ou compte qui gère les options.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return true|WP_Error
	 */
	public static function permission( WP_REST_Request $request ) {
		if ( current_user_can( SettingsPage::CAPABILITY ) ) {
			return true;
		}
		$key = self::key();
		if ( '' === $key ) {
			return new WP_Error( 'fluffless_status_no_key', __( 'No key is set for the site status.', 'fluffless-suite' ), array( 'status' => 503 ) );
		}
		$given = $request->get_header( self::HEADER );
		if ( ! is_string( $given ) || ! hash_equals( $key, trim( $given ) ) ) {
			return new WP_Error( 'fluffless_status_key', __( 'Wrong or missing key.', 'fluffless-suite' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/** Réponse, jamais mise en cache. */
	public static function respond(): WP_REST_Response {
		$response = new WP_REST_Response( self::payload() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Tout l'état, en un tableau prêt pour JSON.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		$theme = wp_get_theme();
		return array(
			'generated'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site'        => array(
				'name'     => get_bloginfo( 'name' ),
				'url'      => home_url(),
				'locale'   => get_locale(),
				'timezone' => wp_timezone_string(),
			),
			'versions'    => array(
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'suite'     => VERSION,
				'theme'     => array(
					'name'    => (string) $theme->get( 'Name' ),
					'version' => (string) $theme->get( 'Version' ),
				),
			),
			'updates'     => self::updates(),
			'audit'       => self::audit(),
			'backups'     => Audit::backups(),
			'maintenance' => array(
				'active' => Options::is_enabled( Maintenance::id() ),
				'mode'   => Options::is_enabled( Maintenance::id() ) ? Maintenance::mode() : '',
			),
			'journal'     => self::journal(),
		);
	}

	/**
	 * Mises à jour en attente, lues dans les transitoires du cœur (aucune vérification déclenchée).
	 *
	 * @return array{core: string, plugins: list<array{name: string, current: string, available: string}>, themes: list<array{name: string, current: string, available: string}>, count: int}
	 */
	public static function updates(): array {
		$core   = '';
		$update = get_site_transient( 'update_core' );
		if ( is_object( $update ) && is_array( $update->updates ?? null ) ) {
			foreach ( $update->updates as $offer ) {
				if ( is_object( $offer ) && 'upgrade' === ( $offer->response ?? '' ) && is_string( $offer->current ?? null ) ) {
					$core = $offer->current;
					break;
				}
			}
		}

		$plugins = array();
		$update  = get_site_transient( 'update_plugins' );
		if ( is_object( $update ) && is_array( $update->response ?? null ) && array() !== $update->response ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$known = get_plugins();
			foreach ( $update->response as $file => $offer ) {
				$file      = (string) $file;
				$plugins[] = array(
					'name'      => (string) ( $known[ $file ]['Name'] ?? $file ),
					'current'   => (string) ( $known[ $file ]['Version'] ?? '' ),
					'available' => is_object( $offer ) ? (string) ( $offer->new_version ?? '' ) : '',
				);
			}
		}

		$themes = array();
		$update = get_site_transient( 'update_themes' );
		if ( is_object( $update ) && is_array( $update->response ?? null ) ) {
			foreach ( $update->response as $slug => $offer ) {
				$theme    = wp_get_theme( (string) $slug );
				$themes[] = array(
					'name'      => $theme->exists() ? (string) $theme->get( 'Name' ) : (string) $slug,
					'current'   => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
					'available' => is_array( $offer ) ? (string) ( $offer['new_version'] ?? '' ) : '',
				);
			}
		}

		return array(
			'core'    => $core,
			'plugins' => $plugins,
			'themes'  => $themes,
			'count'   => ( '' === $core ? 0 : 1 ) + count( $plugins ) + count( $themes ),
		);
	}

	/**
	 * Dernier bilan de sobriété en cache : date, pire statut, compte par statut, titres.
	 *
	 * @return array{checked: string, url: string, local: bool, overall: string, counts: array<string, int>, items: array<string, array{status: string, title: string}>}
	 */
	public static function audit(): array {
		$report = Audit::cached();
		$counts = array(
			'good'    => 0,
			'warn'    => 0,
			'bad'     => 0,
			'unknown' => 0,
		);
		$items  = array();
		$rank   = array(
			'good'    => 0,
			'unknown' => 1,
			'warn'    => 2,
			'bad'     => 3,
		);
		$worst  = 'good';
		$all    = array(
			'backups'    => Audit::check_backups(),
			'page_cache' => Audit::check_page_cache(),
			'videos'     => Audit::check_videos(),
			'images'     => Audit::check_images(),
		) + ( null === $report ? array() : $report['items'] );
		foreach ( $all as $id => $item ) {
			$status = $item['status'];
			++$counts[ $status ];
			$items[ (string) $id ] = array(
				'status' => $status,
				'title'  => $item['title'],
			);
			if ( $rank[ $status ] > $rank[ $worst ] ) {
				$worst = $status;
			}
		}
		return array(
			'checked' => null === $report ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $report['checked'] ),
			'url'     => null === $report ? '' : $report['url'],
			'local'   => null !== $report && $report['local'],
			'overall' => $worst,
			'counts'  => $counts,
			'items'   => $items,
		);
	}

	/**
	 * Derniers événements du journal, sans adresse IP.
	 *
	 * @return array{enabled: bool, events: list<array{time: string, event: string, label: string, actor: string, subject: string}>}
	 */
	public static function journal(): array {
		$limit = Options::get_int( self::id(), 'events' );
		if ( ! Journal::enabled() || $limit < 1 ) {
			return array(
				'enabled' => Journal::enabled(),
				'events'  => array(),
			);
		}
		$labels = Journal::labels();
		$events = array();
		foreach ( Journal::recent( $limit ) as $entry ) {
			$events[] = array(
				'time'    => 0 === $entry['time'] ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $entry['time'] ),
				'event'   => $entry['event'],
				'label'   => $labels[ $entry['event'] ] ?? $entry['event'],
				'actor'   => $entry['actor'],
				'subject' => $entry['subject'],
			);
		}
		return array(
			'enabled' => true,
			'events'  => $events,
		);
	}
}
