<?php
/**
 * Rapport carbone : visites par page du fournisseur de statistiques, multipliées par les grammes
 * mesurés par le module Empreinte, sur une période choisie, avec export CSV.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use DateTimeImmutable;
use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\DeclaresHosts;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Module Rapport carbone.
 *
 * Rien n'est mesuré ici : les visites viennent de Plausible ou d'Umami par leur API, avec une clé
 * gardée dans une constante ou une variable d'environnement, et les grammes viennent des mesures
 * déjà faites page par page. Une page jamais mesurée reçoit la moyenne des pages mesurées, et le
 * rapport le dit. Aucun mot de vertu : des chiffres, une méthode, des dates.
 *
 * @phpstan-type Page array{path: string, visitors: int, pageviews: int}
 * @phpstan-type Period array{preset: string, from: string, to: string}
 * @phpstan-type Row array{path: string, post_id: int, title: string, visitors: int, pageviews: int, grams: float, measured: bool}
 * @phpstan-type Totals array{pages: int, measured: int, visitors: int, pageviews: int, grams: float, measured_grams: float, estimated_grams: float, per_view: float}
 * @phpstan-type Report array{rows: list<Row>, totals: Totals}
 */
final class Carbon implements Module, DeclaresHosts {

	public const KEY_CONSTANT = 'FLUFFLESS_STATS_API_KEY';
	public const PAGE         = 'fluffless-carbone';

	/** Page de contribution de Planetair en français (décision 12). Aucun paramètre, aucun suivi. */
	public const PLANETAIR_FR = 'https://planetair.ca/compenser/';

	/** Page de contribution de Planetair en anglais. */
	public const PLANETAIR_EN = 'https://planetair.ca/en/offset';
	public const ACTION       = 'fluffless_carbone_export';
	public const TRANSIENT    = Lifecycle::TRANSIENT_CARBON;
	public const PRESETS      = array( '30', 'month', 'quarter', 'year', 'last-year', 'custom' );
	public const MAX_PAGES    = 1000;
	public const MAX_DAYS     = 731;
	public const SHOWN_ROWS   = 100;
	private const CACHE_TTL   = 6 * HOUR_IN_SECONDS;
	private const CACHE_SIZE  = 5;
	private const TIMEOUT     = 10;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'carbone';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Carbon report', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Multiplies the visits counted by Statistics by the grams measured by Page footprint, over the period you choose, with a CSV export. Needs an API key of your Plausible or Umami instance.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		// Éteint comme Statistiques, dont il dépend : allumé seul, il signalerait un mode réduit dès l'installation (P5-02).
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array( 'contribution_link' => false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $input Section brute, ignorée : le module n'a aucun réglage.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array(
			'contribution_link' => array(
				'type'  => 'bool',
				'label' => __( 'Show a contribution link under the method', 'fluffless-suite' ),
				'help'  => __( 'Off by default. Adds, at the end of the report, a link to the contribution page of Planetair, a Quebec non-profit that funds climate projects, in the language of your admin screen. Nothing is sent to Planetair until you click. The figures of the report stay as they are: a contribution does not erase them.', 'fluffless-suite' ),
			),
		);
	}

	/** Statut affiché sous le module : statistiques incomplètes ou clé absente. */
	public static function status(): string {
		$stats = Stats::status();
		if ( '' !== $stats ) {
			return $stats;
		}
		if ( '' === self::key() ) {
			/* translators: %s: constant name. */
			return sprintf( __( 'Nothing is reported until the %s constant holds an API key of your statistics instance.', 'fluffless-suite' ), self::KEY_CONSTANT );
		}
		return '';
	}

	/** {@inheritDoc} */
	public function register(): void {
		if ( is_admin() ) {
			// Sur admin_menu : la traduction du titre attend que le domaine soit chargé (init).
			add_action(
				'admin_menu',
				static function (): void {
					Menu::add_tab( 'settings', self::PAGE, __( 'Carbon report', 'fluffless-suite' ), array( self::class, 'render' ), 40 );
				}
			);
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'export' ) );
		}
	}

	/** Clé d'API du fournisseur : constante de wp-config.php, sinon variable d'environnement, jamais une option. */
	public static function key(): string {
		$key = defined( self::KEY_CONSTANT ) ? constant( self::KEY_CONSTANT ) : '';
		if ( ! is_string( $key ) || '' === trim( $key ) ) {
			$env = getenv( self::KEY_CONSTANT );
			$key = is_string( $env ) ? $env : '';
		}
		return trim( $key );
	}

	/**
	 * Période bornée : un préréglage, ou deux dates AAAA-MM-JJ, jamais plus de deux ans en arrière
	 * ni après aujourd'hui.
	 *
	 * @param string   $preset Préréglage demandé.
	 * @param string   $from   Début demandé (custom).
	 * @param string   $to     Fin demandée (custom).
	 * @param int|null $now    Horodatage de référence, pour les tests.
	 * @return Period
	 */
	public static function period( string $preset, string $from = '', string $to = '', ?int $now = null ): array {
		$today  = ( new DateTimeImmutable( '@' . (string) ( $now ?? time() ) ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );
		$preset = in_array( $preset, self::PRESETS, true ) ? $preset : '30';
		switch ( $preset ) {
			case 'month':
				$start = $today->modify( 'first day of last month' );
				$end   = $today->modify( 'last day of last month' );
				break;
			case 'quarter':
				$quarter = (int) floor( ( (int) $today->format( 'n' ) - 1 ) / 3 );
				$start   = $today->setDate( (int) $today->format( 'Y' ), $quarter * 3 + 1, 1 )->modify( '-3 months' );
				$end     = $start->modify( '+3 months -1 day' );
				break;
			case 'year':
				$start = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
				$end   = $today;
				break;
			case 'last-year':
				$start = $today->setDate( (int) $today->format( 'Y' ) - 1, 1, 1 );
				$end   = $today->setDate( (int) $today->format( 'Y' ) - 1, 12, 31 );
				break;
			case 'custom':
				$start = self::date( $from, $today->modify( '-30 days' ) );
				$end   = self::date( $to, $today );
				break;
			default:
				$preset = '30';
				$start  = $today->modify( '-30 days' );
				$end    = $today;
		}
		$floor = $today->modify( '-' . self::MAX_DAYS . ' days' );
		if ( $start < $floor ) {
			$start = $floor;
		}
		if ( $end > $today ) {
			$end = $today;
		}
		if ( $start > $end ) {
			$start = $end;
		}
		return array(
			'preset' => $preset,
			'from'   => $start->format( 'Y-m-d' ),
			'to'     => $end->format( 'Y-m-d' ),
		);
	}

	/**
	 * Date AAAA-MM-JJ lue, ou la valeur de repli.
	 *
	 * @param string            $value    Texte reçu.
	 * @param DateTimeImmutable $fallback Repli.
	 */
	private static function date( string $value, DateTimeImmutable $fallback ): DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $fallback;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		return false === $date || $date->format( 'Y-m-d' ) !== $value ? $fallback : $date;
	}

	/**
	 * Pages vues sur la période, du fournisseur de statistiques, en cache six heures.
	 *
	 * @param array<string, mixed> $period Période.
	 * @phpstan-param Period $period
	 * @return list<Page>|WP_Error
	 */
	public static function pages( array $period ) {
		if ( '' !== Stats::status() ) {
			return new WP_Error( 'fluffless_carbone_stats', Stats::status() );
		}
		if ( '' === self::key() ) {
			return new WP_Error( 'fluffless_carbone_key', self::status() );
		}
		$site   = Options::get_string( Stats::id(), 'site_id' );
		$hash   = md5( implode( '|', array( self::provider(), Stats::endpoint(), $site, $period['from'], $period['to'] ) ) );
		$cached = get_transient( self::TRANSIENT );
		$cached = is_array( $cached ) ? $cached : array();
		if ( isset( $cached[ $hash ] ) && is_array( $cached[ $hash ]['pages'] ?? null ) ) {
			/**
			 * Pages posées par ce module.
			 *
			 * @var list<Page> $pages
			 */
			$pages = $cached[ $hash ]['pages'];
			return $pages;
		}
		$pages = 'umami' === self::provider() ? self::fetch_umami( $site, $period ) : self::fetch_plausible( $site, $period );
		if ( $pages instanceof WP_Error ) {
			return $pages;
		}
		$cached[ $hash ] = array(
			'time'  => time(),
			'pages' => $pages,
		);
		uasort( $cached, static fn( array $a, array $b ): int => ( $b['time'] ?? 0 ) <=> ( $a['time'] ?? 0 ) );
		set_transient( self::TRANSIENT, array_slice( $cached, 0, self::CACHE_SIZE, true ), self::CACHE_TTL );
		return $pages;
	}

	/** Fournisseur réglé dans Statistiques. */
	private static function provider(): string {
		return 'umami' === Options::get_string( Stats::id(), 'provider' ) ? 'umami' : 'plausible';
	}

	/** Hôte de l'API : celui du script de statistiques, en https. */
	private static function base(): string {
		$parts = wp_parse_url( Options::get_string( Stats::id(), 'script_url' ) );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return 'https://' . $parts['host'] . $port;
	}

	/**
	 * Adresse que le rapport interroge : l'hôte du script, ou api.umami.is/v1 pour Umami Cloud.
	 * Lue aussi par hosts(), pour que l'écran Serveurs contactés suive le code qui appelle.
	 */
	public static function api_base(): string {
		$base = self::base();
		return str_ends_with( $base, 'umami.is' ) ? 'https://api.umami.is/v1' : $base;
	}

	/**
	 * Plausible : API de requêtes v2, visiteurs et pages vues par page.
	 *
	 * @param string               $site   Identifiant du site.
	 * @param array<string, mixed> $period Période.
	 * @phpstan-param Period $period
	 * @return list<Page>|WP_Error
	 */
	private static function fetch_plausible( string $site, array $period ) {
		$body = self::request(
			self::base() . '/api/v2/query',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . self::key(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'site_id'    => $site,
						'metrics'    => array( 'visitors', 'pageviews' ),
						'date_range' => array( $period['from'], $period['to'] ),
						'dimensions' => array( 'event:page' ),
						'pagination' => array( 'limit' => self::MAX_PAGES ),
					)
				),
			)
		);
		return $body instanceof WP_Error ? $body : self::parse_plausible( $body );
	}

	/**
	 * Umami 3 : vues et visiteurs par chemin (metrics/expanded). Umami 2, qui n'a pas cette route,
	 * répond 400 ou 404 : repli sur les pages vues par adresse et les visiteurs du site.
	 *
	 * @param string               $site   Identifiant du site.
	 * @param array<string, mixed> $period Période.
	 * @phpstan-param Period $period
	 * @return list<Page>|WP_Error
	 */
	private static function fetch_umami( string $site, array $period ) {
		$base     = self::api_base();
		$args     = array(
			'headers' => array(
				'x-umami-api-key' => self::key(),
				'Authorization'   => 'Bearer ' . self::key(),
			),
		);
		$query    = array(
			'startAt' => (string) ( strtotime( $period['from'] . ' 00:00:00 ' . wp_timezone_string() ) * 1000 ),
			'endAt'   => (string) ( ( strtotime( $period['to'] . ' 23:59:59 ' . wp_timezone_string() ) ) * 1000 ),
		);
		$expanded = self::request(
			add_query_arg(
				$query + array(
					'type'  => 'path',
					'limit' => (string) self::MAX_PAGES,
				),
				$base . '/api/websites/' . rawurlencode( $site ) . '/metrics/expanded'
			),
			$args
		);
		if ( ! $expanded instanceof WP_Error ) {
			return self::parse_umami_expanded( $expanded );
		}
		$status = $expanded->get_error_data();
		if ( ! is_array( $status ) || ! in_array( $status['status'] ?? 0, array( 400, 404 ), true ) ) {
			return $expanded;
		}
		$stats = self::request( add_query_arg( $query, $base . '/api/websites/' . rawurlencode( $site ) . '/stats' ), $args );
		if ( $stats instanceof WP_Error ) {
			return $stats;
		}
		$metrics = self::request(
			add_query_arg(
				$query + array(
					'type'  => 'url',
					'limit' => (string) self::MAX_PAGES,
				),
				$base . '/api/websites/' . rawurlencode( $site ) . '/metrics'
			),
			$args
		);
		return $metrics instanceof WP_Error ? $metrics : self::parse_umami( $metrics, $stats );
	}

	/**
	 * Requête au fournisseur, corps JSON décodé.
	 *
	 * @param string               $url  Adresse.
	 * @param array<string, mixed> $args Arguments de wp_safe_remote_request().
	 * @return array<mixed>|WP_Error
	 */
	private static function request( string $url, array $args ) {
		if ( '' === self::base() ) {
			return new WP_Error( 'fluffless_carbone_host', __( 'The statistics script address has no host.', 'fluffless-suite' ) );
		}
		$args['timeout'] = self::TIMEOUT;
		$response        = wp_safe_remote_request( $url, $args );
		if ( $response instanceof WP_Error ) {
			return new WP_Error( 'fluffless_carbone_http', $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'fluffless_carbone_response', sprintf( __( 'The statistics instance answered with status %d instead of a report.', 'fluffless-suite' ), (int) $code ), array( 'status' => (int) $code ) );
		}
		return $body;
	}

	/**
	 * Lecture d'une réponse Plausible : results[].dimensions[0] et metrics[visitors, pageviews].
	 *
	 * @param array<mixed> $body Corps décodé.
	 * @return list<Page>
	 */
	public static function parse_plausible( array $body ): array {
		$pages = array();
		foreach ( is_array( $body['results'] ?? null ) ? $body['results'] : array() as $result ) {
			if ( ! is_array( $result ) || ! is_array( $result['dimensions'] ?? null ) || ! is_array( $result['metrics'] ?? null ) ) {
				continue;
			}
			$page = self::page( $result['dimensions'][0] ?? '', $result['metrics'][0] ?? 0, $result['metrics'][1] ?? 0 );
			if ( null !== $page ) {
				$pages[] = $page;
			}
		}
		return array_slice( $pages, 0, self::MAX_PAGES );
	}

	/**
	 * Lecture d'une réponse Umami : metrics [{x: adresse, y: pages vues}] ; les visiteurs par page
	 * ne sont pas fournis, la part de visiteurs du site entier est appliquée à chaque page.
	 *
	 * @param array<mixed> $metrics Corps de /metrics?type=url.
	 * @param array<mixed> $stats   Corps de /stats (pageviews.value, visitors.value).
	 * @return list<Page>
	 */
	public static function parse_umami( array $metrics, array $stats ): array {
		$views    = self::stat( $stats, 'pageviews' );
		$visitors = self::stat( $stats, 'visitors' );
		$ratio    = $views > 0 ? min( 1.0, $visitors / $views ) : 1.0;
		$pages    = array();
		foreach ( $metrics as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$count = is_numeric( $row['y'] ?? null ) ? (int) $row['y'] : 0;
			$page  = self::page( $row['x'] ?? '', (int) round( $count * $ratio ), $count );
			if ( null !== $page ) {
				$pages[] = $page;
			}
		}
		return array_slice( $pages, 0, self::MAX_PAGES );
	}

	/**
	 * Lecture d'une réponse Umami 3 : metrics/expanded [{name: chemin, pageviews, visitors}], vues
	 * parfois en chaîne.
	 *
	 * @param array<mixed> $rows Corps de /metrics/expanded?type=path.
	 * @return list<Page>
	 */
	public static function parse_umami_expanded( array $rows ): array {
		$pages = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$page = self::page( $row['name'] ?? '', $row['visitors'] ?? 0, $row['pageviews'] ?? 0 );
			if ( null !== $page ) {
				$pages[] = $page;
			}
		}
		return array_slice( $pages, 0, self::MAX_PAGES );
	}

	/**
	 * Valeur d'une statistique Umami, à plat ou sous value.
	 *
	 * @param array<mixed> $stats Corps.
	 * @param string       $key   Clé.
	 */
	private static function stat( array $stats, string $key ): int {
		$value = $stats[ $key ] ?? 0;
		if ( is_array( $value ) ) {
			$value = $value['value'] ?? 0;
		}
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Page normalisée : chemin absolu sans requête, compteurs entiers, visiteurs jamais au-delà des vues.
	 *
	 * @param mixed $path      Chemin reçu.
	 * @param mixed $visitors  Visiteurs.
	 * @param mixed $pageviews Pages vues.
	 * @return Page|null
	 */
	private static function page( $path, $visitors, $pageviews ): ?array {
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}
		$parts = wp_parse_url( $path );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : $path;
		$path  = '/' . ltrim( mb_substr( $path, 0, 2048 ), '/' );
		$views = is_numeric( $pageviews ) ? max( 0, (int) $pageviews ) : 0;
		$seen  = is_numeric( $visitors ) ? max( 0, (int) $visitors ) : 0;
		if ( 0 === $views ) {
			return null;
		}
		return array(
			'path'      => $path,
			'visitors'  => min( $seen, $views ),
			'pageviews' => $views,
		);
	}

	/**
	 * Rapport : chaque page multipliée par ses grammes, les pages sans mesure estimées à la moyenne
	 * des pages mesurées, puis les totaux.
	 *
	 * @param array<mixed> $pages Pages vues.
	 * @phpstan-param list<Page> $pages
	 * @return Report
	 */
	public static function report( array $pages ): array {
		$rows           = array();
		$measured_grams = 0.0;
		$measured_views = 0;
		foreach ( $pages as $page ) {
			$post_id = self::post_of( $page['path'] );
			$stored  = Footprint::stored( $post_id );
			$row     = array(
				'path'      => $page['path'],
				'post_id'   => $post_id,
				'title'     => $post_id > 0 ? get_the_title( $post_id ) : '',
				'visitors'  => $page['visitors'],
				'pageviews' => $page['pageviews'],
				'grams'     => 0.0,
				'measured'  => null !== $stored,
			);
			if ( null !== $stored ) {
				$row['grams']    = $page['visitors'] * $stored['new'] + ( $page['pageviews'] - $page['visitors'] ) * $stored['return'];
				$measured_grams += $row['grams'];
				$measured_views += $page['pageviews'];
			}
			$rows[] = $row;
		}
		$per_view        = $measured_views > 0 ? $measured_grams / $measured_views : 0.0;
		$estimated_grams = 0.0;
		foreach ( $rows as &$row ) {
			if ( ! $row['measured'] ) {
				$row['grams']     = $row['pageviews'] * $per_view;
				$estimated_grams += $row['grams'];
			}
		}
		unset( $row );
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$by_grams = $b['grams'] <=> $a['grams'];
				return 0 !== $by_grams ? $by_grams : $b['pageviews'] <=> $a['pageviews'];
			}
		);
		return array(
			'rows'   => $rows,
			'totals' => array(
				'pages'           => count( $rows ),
				'measured'        => count( array_filter( $rows, static fn( array $r ): bool => $r['measured'] ) ),
				'visitors'        => (int) array_sum( array_column( $rows, 'visitors' ) ),
				'pageviews'       => (int) array_sum( array_column( $rows, 'pageviews' ) ),
				'grams'           => $measured_grams + $estimated_grams,
				'measured_grams'  => $measured_grams,
				'estimated_grams' => $estimated_grams,
				'per_view'        => $per_view,
			),
		);
	}

	/**
	 * Contenu derrière un chemin : page d'accueil, sinon url_to_postid().
	 *
	 * @param string $path Chemin absolu.
	 */
	public static function post_of( string $path ): int {
		if ( '/' === $path ) {
			return 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		}
		return url_to_postid( home_url( $path ) );
	}

	/**
	 * Masse lisible : grammes sous le kilo, kilogrammes ensuite, avec l'unité traduite.
	 *
	 * @param float $grams Valeur.
	 */
	public static function format_mass( float $grams ): string {
		if ( $grams >= 1000.0 ) {
			/* translators: %s: number of kilograms. */
			return sprintf( __( '%s kg', 'fluffless-suite' ), number_format_i18n( $grams / 1000, $grams >= 100000.0 ? 0 : 1 ) );
		}
		/* translators: %s: number of grams. */
		return sprintf( __( '%s g', 'fluffless-suite' ), Footprint::format_grams( $grams ) );
	}

	/**
	 * Export CSV : une ligne par page, puis une ligne de total, valeurs brutes en point décimal.
	 *
	 * @param array<string, mixed> $report Rapport.
	 * @phpstan-param Report $report
	 * @param array<string, mixed> $period Période.
	 * @phpstan-param Period $period
	 */
	public static function csv( array $report, array $period ): string {
		$lines   = array();
		$lines[] = array( 'from', 'to', 'path', 'title', 'visitors', 'pageviews', 'grams_co2e', 'measured' );
		foreach ( $report['rows'] as $row ) {
			$lines[] = array( $period['from'], $period['to'], $row['path'], $row['title'], (string) $row['visitors'], (string) $row['pageviews'], self::number( $row['grams'] ), $row['measured'] ? 'yes' : 'estimated' );
		}
		$lines[] = array( $period['from'], $period['to'], '', 'total', (string) $report['totals']['visitors'], (string) $report['totals']['pageviews'], self::number( $report['totals']['grams'] ), $report['totals']['measured'] . '/' . $report['totals']['pages'] );
		$out     = '';
		foreach ( $lines as $line ) {
			$out .= implode( ',', array_map( array( self::class, 'cell' ), $line ) ) . "\r\n";
		}
		return $out;
	}

	/**
	 * Nombre brut à quatre décimales, sans séparateur de milliers.
	 *
	 * @param float $value Valeur.
	 */
	private static function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * Cellule CSV : entre guillemets quand elle en a besoin, formule neutralisée.
	 *
	 * @param string $value Valeur.
	 */
	private static function cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		return 1 === preg_match( '/[",\r\n]/', $value ) ? '"' . str_replace( '"', '""', $value ) . '"' : $value;
	}

	/**
	 * Période lue dans une requête (GET ou POST) déjà vérifiée par l'appelant.
	 *
	 * @param array<string, mixed> $query Paramètres.
	 * @return Period
	 */
	private static function period_from( array $query ): array {
		$text = static fn( string $key ): string => is_string( $query[ $key ] ?? null ) ? sanitize_text_field( wp_unslash( $query[ $key ] ) ) : '';
		return self::period( $text( 'fl_period' ), $text( 'fl_from' ), $text( 'fl_to' ) );
	}

	/** Téléchargement du CSV. */
	public static function export(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this report.', 'fluffless-suite' ) );
		}
		$period = self::period_from( $_POST );
		$pages  = self::pages( $period );
		if ( $pages instanceof WP_Error ) {
			wp_die( esc_html( $pages->get_error_message() ) );
		}
		$name = 'carbon-' . $period['from'] . '-' . $period['to'] . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo self::csv( self::report( $pages ), $period ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fichier CSV, cellules protégées dans cell().
		exit;
	}

	/** Écran Rapport carbone. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$period = self::period_from( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple choix d'une période à afficher.
		echo '<div class="wrap">';
		Screen::header( __( 'Carbon report', 'fluffless-suite' ), __( 'Visits counted by Statistics, multiplied by the grams measured by Page footprint. Pages never measured get the average of the measured ones, and are marked as estimates.', 'fluffless-suite' ) );
		self::render_form( $period );
		$status = self::status();
		if ( '' !== $status ) {
			echo Screen::note( 'warn', $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			echo '</div>';
			return;
		}
		$pages = self::pages( $period );
		if ( $pages instanceof WP_Error ) {
			echo Screen::note( 'bad', $pages->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			echo '</div>';
			return;
		}
		$report = self::report( $pages );
		self::render_report( $report, $period );
		echo '</div>';
	}

	/**
	 * Formulaire de période.
	 *
	 * @param array<string, mixed> $period Période courante.
	 * @phpstan-param Period $period
	 */
	private static function render_form( array $period ): void {
		$labels = array(
			'30'        => __( 'Last 30 days', 'fluffless-suite' ),
			'month'     => __( 'Last month', 'fluffless-suite' ),
			'quarter'   => __( 'Last quarter', 'fluffless-suite' ),
			'year'      => __( 'Year to date', 'fluffless-suite' ),
			'last-year' => __( 'Last year', 'fluffless-suite' ),
			'custom'    => __( 'Custom dates', 'fluffless-suite' ),
		);
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="fl-card">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<div class="fl-fields"><label for="fl_period">' . esc_html__( 'Period', 'fluffless-suite' ) . '</label> <select name="fl_period" id="fl_period">';
		foreach ( $labels as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $period['preset'], (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<label for="fl_from">' . esc_html__( 'From', 'fluffless-suite' ) . '</label> <input type="date" name="fl_from" id="fl_from" value="' . esc_attr( $period['from'] ) . '"> ';
		echo '<label for="fl_to">' . esc_html__( 'To', 'fluffless-suite' ) . '</label> <input type="date" name="fl_to" id="fl_to" value="' . esc_attr( $period['to'] ) . '"> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Show', 'fluffless-suite' ) . '</button></div>';
		echo '<p class="fl-muted">' . esc_html__( 'Dates count when the period is set to custom. Nothing older than two years.', 'fluffless-suite' ) . '</p>';
		echo '</form>';
	}

	/**
	 * Chiffres, tableau, méthode et export.
	 *
	 * @param array<string, mixed> $report Rapport.
	 * @phpstan-param Report $report
	 * @param array<string, mixed> $period Période.
	 * @phpstan-param Period $period
	 */
	private static function render_report( array $report, array $period ): void {
		$totals = $report['totals'];
		$from   = wp_date( get_option( 'date_format' ), (int) strtotime( $period['from'] . ' 12:00:00 ' . wp_timezone_string() ) );
		$to     = wp_date( get_option( 'date_format' ), (int) strtotime( $period['to'] . ' 12:00:00 ' . wp_timezone_string() ) );
		/* translators: 1: start date, 2: end date. */
		echo '<p>' . esc_html( sprintf( __( 'From %1$s to %2$s.', 'fluffless-suite' ), $from, $to ) ) . '</p>';
		if ( 0 === $totals['pages'] ) {
			echo Screen::note( 'idle', __( 'No visit counted over this period.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			return;
		}
		echo '<dl class="fl-figures">';
		self::figure( __( 'Estimated emissions', 'fluffless-suite' ), self::format_mass( $totals['grams'] ), __( 'CO₂ equivalent, pages measured plus estimates', 'fluffless-suite' ) );
		self::figure( __( 'Page views', 'fluffless-suite' ), number_format_i18n( $totals['pageviews'] ), sprintf( /* translators: %s: number of visitors. */ __( '%s visitors', 'fluffless-suite' ), number_format_i18n( $totals['visitors'] ) ) );
		/* translators: 1: number of measured pages, 2: number of pages. */
		self::figure( __( 'Pages measured', 'fluffless-suite' ), sprintf( __( '%1$s of %2$s', 'fluffless-suite' ), number_format_i18n( $totals['measured'] ), number_format_i18n( $totals['pages'] ) ), sprintf( /* translators: %s: grams per page view. */ __( '%s per page view on average', 'fluffless-suite' ), self::format_mass( $totals['per_view'] ) ) );
		echo '</dl>';
		if ( $totals['measured'] < $totals['pages'] ) {
			/* translators: %s: estimated mass. */
			echo Screen::note( 'warn', sprintf( __( '%s of this total is an estimate: those pages were never measured. Open each one in the editor and run Page footprint to replace the estimate with a measure.', 'fluffless-suite' ), self::format_mass( $totals['estimated_grams'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Page', 'fluffless-suite' ), __( 'Visitors', 'fluffless-suite' ), __( 'Page views', 'fluffless-suite' ), __( 'Emissions', 'fluffless-suite' ), _x( 'Measure', 'table column', 'fluffless-suite' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $report['rows'], 0, self::SHOWN_ROWS ) as $row ) {
			$name = '' !== $row['title'] ? $row['title'] : $row['path'];
			echo '<tr><td>' . esc_html( $name ) . ' <code>' . esc_html( $row['path'] ) . '</code></td>';
			echo '<td>' . esc_html( number_format_i18n( $row['visitors'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['pageviews'] ) ) . '</td>';
			echo '<td>' . esc_html( self::format_mass( $row['grams'] ) ) . '</td>';
			echo '<td>' . Screen::pill( $row['measured'] ? 'good' : 'warn', $row['measured'] ? __( 'Measured', 'fluffless-suite' ) : __( 'Estimate', 'fluffless-suite' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::pill().
		}
		echo '</tbody></table>';
		if ( count( $report['rows'] ) > self::SHOWN_ROWS ) {
			/* translators: 1: rows shown, 2: rows in total. */
			echo '<p class="fl-muted">' . esc_html( sprintf( __( 'First %1$s pages of %2$s shown. The CSV export holds them all.', 'fluffless-suite' ), number_format_i18n( self::SHOWN_ROWS ), number_format_i18n( count( $report['rows'] ) ) ) ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="fl_period" value="' . esc_attr( $period['preset'] ) . '">';
		echo '<input type="hidden" name="fl_from" value="' . esc_attr( $period['from'] ) . '">';
		echo '<input type="hidden" name="fl_to" value="' . esc_attr( $period['to'] ) . '">';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Export CSV', 'fluffless-suite' ) . '</button></p>';
		echo '</form>';
		echo '<h2>' . esc_html__( 'Method', 'fluffless-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Visits and page views come from your statistics instance for the period above. Each page measured by Page footprint carries two figures: grams for a first visit and grams for a returning visit; the report counts one first visit per visitor and returning visits for the other views. Pages never measured are given the average grams per view of the measured pages. These figures are estimates of the transfer and use phases only, based on the page weight and the electricity of the host at the time of each measure; they leave out device manufacturing and are not an offset or a neutrality claim.', 'fluffless-suite' ) . '</p>';
		self::contribution_link();
		/* translators: %s: report generation date. */
		echo '<p class="fl-muted">' . esc_html( sprintf( __( 'Report generated on %s.', 'fluffless-suite' ), wp_date( get_option( 'date_format' ) ) ) ) . '</p>';
	}

	/**
	 * Adresse de la page de contribution de Planetair selon la langue : français pour toute locale fr_*,
	 * anglais sinon. Adresse fixe, sans paramètre ni fragment.
	 *
	 * @param string $locale Locale, par exemple fr_CA.
	 */
	public static function contribution_url( string $locale ): string {
		return str_starts_with( strtolower( $locale ), 'fr' ) ? self::PLANETAIR_FR : self::PLANETAIR_EN;
	}

	/**
	 * Lien vers Planetair sous la méthode, seulement si le réglage est allumé. « Réduire d'abord » :
	 * un renvoi vers le Bilan de sobriété quand ce module est actif. L'ancre ne transmet ni référent
	 * ni suivi (F-planetair-02) et s'ouvre dans un nouvel onglet, annoncé aux lecteurs d'écran.
	 */
	private static function contribution_link(): void {
		if ( ! Options::get_bool( self::id(), 'contribution_link' ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Reduce first, contribute after.', 'fluffless-suite' ) . '</h2>';
		if ( Options::is_enabled( Audit::id() ) ) {
			echo '<p>' . sprintf(
				/* translators: %s: link to the Fluff check screen. */
				esc_html__( 'Start with the heaviest pages: %s shows what to lighten.', 'fluffless-suite' ),
				'<a href="' . esc_url( Menu::url( Audit::PAGE ) ) . '">' . esc_html( Audit::label() ) . '</a>'
			) . '</p>';
		}
		echo '<p>' . esc_html__( 'A contribution funds a climate project elsewhere. It does not change the figures of this report.', 'fluffless-suite' ) . '</p>';
		echo '<p><a href="' . esc_url( self::contribution_url( get_user_locale() ) ) . '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">'
			. esc_html__( 'Contribute to a climate project (Planetair)', 'fluffless-suite' )
			. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'fluffless-suite' ) . '</span></a></p>';
	}

	/**
	 * Un grand chiffre.
	 *
	 * @param string $label Étiquette.
	 * @param string $value Valeur.
	 * @param string $note  Précision.
	 */
	private static function figure( string $label, string $value, string $note ): void {
		echo '<div class="fl-figure"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd><dd class="fl-muted">' . esc_html( $note ) . '</dd></div>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function hosts(): array {
		$host = (string) wp_parse_url( self::api_base(), PHP_URL_HOST );
		return array(
			array(
				'host'    => $host,
				'service' => __( 'Carbon report (statistics API)', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'action',
				'data'    => __( 'Site ID, period and API key; nothing about visitors', 'fluffless-suite' ),
				'active'  => '' !== $host && '' !== self::key() && '' !== Options::get_string( Stats::id(), 'site_id' ),
			),
		);
	}
}
