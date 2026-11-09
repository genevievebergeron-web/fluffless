<?php
/**
 * Module Empreinte : estimation SWDM v4 des émissions d'une page, calculée dans l'éditeur.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Le navigateur de l'éditeur mesure le poids de la page ; le serveur fait le calcul et
 * l'enregistre. Le calcul reprend le modèle Sustainable Web Design v4 de CO2.js 0.19.0
 * (Green Web Foundation, Apache 2.0), réécrit ici plutôt que livré : il tient en quelques
 * lignes, doit tourner côté serveur (la métadonnée ne se fie jamais à un chiffre du client)
 * et se teste avec wp eval-file. Constantes : dist/esm/constants/index.js de @tgwf/co2 0.19.0.
 *
 * @phpstan-type Result array{version: int, bytes: int, requests: int, new: float, return: float, grade: string, host: string, country: string, intensity: float, measured: int, largest_url: string, largest_bytes: int}
 */
final class Footprint implements Module {

	public const META          = Lifecycle::META_FOOTPRINT;
	public const SCRIPT_HANDLE = 'fluffless-footprint-editor';
	public const REST_NS       = 'fluffless/v1';
	public const MEASURE_ARG   = 'fluffless_measure';

	/** Au-delà, la mesure est jugée ancienne : alerte dans l'éditeur (docs/PLAN.md : 6 mois). */
	public const STALE_SECONDS = 182 * DAY_IN_SECONDS;

	/** Budget « contenu » de docs/PLAN.md et seuil à partir duquel une ressource mérite un conseil. */
	public const PAGE_BUDGET_BYTES  = 500 * 1000;
	public const HEAVY_RESOURCE     = 100 * 1000;
	public const DEFAULT_METHOD_URL = 'https://sustainablewebdesign.org/estimating-digital-emissions/';
	private const MAX_BYTES         = 1000000000;
	private const MAX_REQUESTS      = 5000;
	private const RESULT_VERSION    = 1;

	/** SWDM v4 : kWh par Go transféré, par segment (SWDV4 de CO2.js). */
	public const KWH_PER_GB = array(
		'operational_datacenter' => 0.055,
		'operational_network'    => 0.059,
		'operational_device'     => 0.080,
		'embodied_datacenter'    => 0.012,
		'embodied_network'       => 0.013,
		'embodied_device'        => 0.081,
	);

	/** SWDM v4 : intensité mondiale (g éq. CO₂/kWh) de tous les segments par défaut. */
	public const GLOBAL_GRID_INTENSITY = 494.0;

	/** Part des données rechargées à une visite de retour (PERCENTAGE_OF_DATA_LOADED_ON_SUBSEQUENT_LOAD). */
	public const RETURN_DATA_RATIO = 0.02;

	/** Note SWDM v4 : plafond en grammes par visite de chaque lettre (SWDMV4_RATINGS), au-delà : F. */
	public const GRADES = array(
		'A+' => 0.040,
		'A'  => 0.079,
		'B'  => 0.145,
		'C'  => 0.209,
		'D'  => 0.278,
		'E'  => 0.359,
	);

	/**
	 * Intensité moyenne du réseau (g éq. CO₂/kWh), data/average-intensities de CO2.js 0.19.0 (Ember).
	 * WORLD reprend la valeur mondiale propre au modèle v4 (494), pas la moyenne Ember (472,94).
	 */
	public const GRID_INTENSITY = array(
		'WORLD' => 494.0,
		'CAN'   => 184.99,
		'USA'   => 383.55,
		'FRA'   => 44.18,
		'BEL'   => 117.58,
		'CHE'   => 36.72,
		'DEU'   => 342.06,
		'NLD'   => 252.7,
		'GBR'   => 215.79,
		'IRL'   => 279.79,
		'ESP'   => 146.15,
		'ITA'   => 287.75,
		'SWE'   => 35.89,
		'NOR'   => 30.75,
		'FIN'   => 72.25,
		'ISL'   => 28.33,
		'AUS'   => 553.76,
	);

	/** {@inheritDoc} */
	public static function id(): string {
		return 'footprint';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Page footprint', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Adds a Page footprint panel to the editor: page weight, two emission estimates (new visit and returning visit) and a rating, calculated with the Sustainable Web Design model. Editor only: your visitors download nothing.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array( 'country' => 'WORLD' );
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
			'country' => array(
				'type'    => 'choice',
				'label'   => __( 'Country of your host’s data centre', 'fluffless-suite' ),
				'help'    => __( 'Sets the electricity mix used for the data centre share of the estimate. Leave “World average” if you are not sure.', 'fluffless-suite' ),
				'options' => self::countries(),
			),
		);
	}

	/**
	 * Pays proposés, dans l'ordre de GRID_INTENSITY.
	 *
	 * @return array<string, string>
	 */
	public static function countries(): array {
		return array(
			'WORLD' => __( 'World average', 'fluffless-suite' ),
			'CAN'   => __( 'Canada', 'fluffless-suite' ),
			'USA'   => __( 'United States', 'fluffless-suite' ),
			'FRA'   => __( 'France', 'fluffless-suite' ),
			'BEL'   => __( 'Belgium', 'fluffless-suite' ),
			'CHE'   => __( 'Switzerland', 'fluffless-suite' ),
			'DEU'   => __( 'Germany', 'fluffless-suite' ),
			'NLD'   => __( 'Netherlands', 'fluffless-suite' ),
			'GBR'   => __( 'United Kingdom', 'fluffless-suite' ),
			'IRL'   => __( 'Ireland', 'fluffless-suite' ),
			'ESP'   => __( 'Spain', 'fluffless-suite' ),
			'ITA'   => __( 'Italy', 'fluffless-suite' ),
			'SWE'   => __( 'Sweden', 'fluffless-suite' ),
			'NOR'   => __( 'Norway', 'fluffless-suite' ),
			'FIN'   => __( 'Finland', 'fluffless-suite' ),
			'ISL'   => __( 'Iceland', 'fluffless-suite' ),
			'AUS'   => __( 'Australia', 'fluffless-suite' ),
		);
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_filter( 'show_admin_bar', array( self::class, 'hide_admin_bar_while_measuring' ) );
	}

	/**
	 * Métadonnée lisible par l'API REST (bloc et panneau), jamais écrite par elle :
	 * seule la route de mesure l'écrit, après calcul serveur. Déclarée sur init dans tous les cas,
	 * car le bloc l'affiche même si le panneau est désactivé.
	 */
	public static function register_meta(): void {
		$number = array( 'type' => 'number' );
		$int    = array( 'type' => 'integer' );
		$string = array( 'type' => 'string' );
		register_post_meta(
			'',
			self::META,
			array(
				'type'              => 'object',
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_result' ),
				'auth_callback'     => '__return_false',
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'version'       => $int,
							'bytes'         => $int,
							'requests'      => $int,
							'new'           => $number,
							'return'        => $number,
							'grade'         => $string,
							'host'          => $string,
							'country'       => $string,
							'intensity'     => $number,
							'measured'      => $int,
							'largest_url'   => $string,
							'largest_bytes' => $int,
						),
					),
				),
			)
		);
	}

	/**
	 * Retire l'empreinte des écritures REST de contenus, sans erreur : une écriture est ignorée,
	 * jamais refusée. L'éditeur renvoie tout l'objet meta à chaque enregistrement (dès qu'une autre
	 * méta change) ; WordPress refuserait alors toute la requête en 403 pour cette clé qu'il n'a
	 * pas le droit d'écrire. Seules les routes de contenus sont touchées (pas les notes ni les comptes).
	 *
	 * @param mixed $response Réponse ou erreur déjà décidée, rendue telle quelle.
	 * @param mixed $handler  Gestionnaire de la route.
	 * @param mixed $request  Requête.
	 * @return mixed
	 */
	public static function read_only_meta( $response, $handler, $request ) {
		$controller = is_array( $handler ) && is_array( $handler['callback'] ?? null ) ? ( $handler['callback'][0] ?? null ) : null;
		if ( ! $request instanceof \WP_REST_Request || 'GET' === $request->get_method() || ! $controller instanceof \WP_REST_Posts_Controller ) {
			return $response;
		}
		$meta = $request->get_param( 'meta' );
		if ( is_array( $meta ) && array_key_exists( self::META, $meta ) ) {
			unset( $meta[ self::META ] );
			$request->set_param( 'meta', $meta );
		}
		return $response;
	}

	/**
	 * Grammes d'éq. CO₂ pour une première visite qui transfère $bytes octets (perVisit de CO2.js v4).
	 *
	 * @param int   $bytes                 Octets transférés.
	 * @param bool  $green                 Hébergeur reconnu : le segment opérationnel du centre de données passe à 0.
	 * @param float $datacenter_intensity  Intensité du réseau du centre de données (g/kWh).
	 */
	public static function per_visit( int $bytes, bool $green, float $datacenter_intensity ): float {
		if ( $bytes < 1 ) {
			return 0.0;
		}
		$gb     = $bytes / 1e9;
		$kwh    = self::KWH_PER_GB;
		$global = self::GLOBAL_GRID_INTENSITY;
		// Seul le segment opérationnel du centre de données dépend du pays et de l'hébergeur ;
		// réseau, appareil et fabrication restent à l'intensité mondiale, comme dans CO2.js.
		$datacenter = $gb * $kwh['operational_datacenter'] * $datacenter_intensity * ( $green ? 0.0 : 1.0 );
		$others     = $gb * ( $kwh['operational_network'] + $kwh['operational_device'] ) * $global;
		$embodied   = $gb * ( $kwh['embodied_datacenter'] + $kwh['embodied_network'] + $kwh['embodied_device'] ) * $global;
		return $datacenter + $others + $embodied;
	}

	/**
	 * Note A+ à F d'une visite (outputRating de CO2.js, bornes incluses).
	 *
	 * @param float $grams Émissions d'une première visite.
	 */
	public static function grade( float $grams ): string {
		foreach ( self::GRADES as $grade => $ceiling ) {
			if ( $grams <= $ceiling ) {
				return $grade;
			}
		}
		return 'F';
	}

	/**
	 * Résultat complet d'une mesure, avec le réglage de pays et le statut de l'hébergeur courants.
	 *
	 * @param int    $bytes         Poids transféré mesuré.
	 * @param int    $requests      Nombre de requêtes.
	 * @param string $largest_url   Plus grosse ressource.
	 * @param int    $largest_bytes Son poids.
	 * @return Result
	 */
	public static function measure( int $bytes, int $requests, string $largest_url = '', int $largest_bytes = 0 ): array {
		$country = Options::get_string( self::id(), 'country' );
		$country = isset( self::GRID_INTENSITY[ $country ] ) ? $country : 'WORLD';
		$host    = Audit::host_status();
		$new     = self::per_visit( $bytes, 'yes' === $host, self::GRID_INTENSITY[ $country ] );
		return self::sanitize_result(
			array(
				'bytes'         => $bytes,
				'requests'      => $requests,
				'new'           => $new,
				'return'        => $new * self::RETURN_DATA_RATIO,
				'grade'         => self::grade( $new ),
				'host'          => $host,
				'country'       => $country,
				'intensity'     => self::GRID_INTENSITY[ $country ],
				'measured'      => time(),
				'largest_url'   => $largest_url,
				'largest_bytes' => $largest_bytes,
			)
		);
	}

	/**
	 * Forme stable de la métadonnée : chaque valeur est typée et bornée.
	 *
	 * @param mixed $raw Valeur reçue.
	 * @return Result
	 */
	public static function sanitize_result( $raw ): array {
		$raw   = is_array( $raw ) ? $raw : array();
		$int   = static fn( string $key, int $max ): int => max( 0, min( $max, (int) ( is_numeric( $raw[ $key ] ?? null ) ? $raw[ $key ] : 0 ) ) );
		$float = static fn( string $key ): float => max( 0.0, (float) ( is_numeric( $raw[ $key ] ?? null ) ? $raw[ $key ] : 0 ) );
		$grade = is_string( $raw['grade'] ?? null ) && ( isset( self::GRADES[ $raw['grade'] ] ) || 'F' === $raw['grade'] ) ? $raw['grade'] : 'F';
		$host  = in_array( $raw['host'] ?? null, array( 'yes', 'no', 'unknown' ), true ) ? $raw['host'] : 'unknown';
		$cntry = is_string( $raw['country'] ?? null ) && isset( self::GRID_INTENSITY[ $raw['country'] ] ) ? $raw['country'] : 'WORLD';
		$url   = is_string( $raw['largest_url'] ?? null ) ? esc_url_raw( mb_substr( $raw['largest_url'], 0, 2048 ), array( 'http', 'https' ) ) : '';
		return array(
			'version'       => self::RESULT_VERSION,
			'bytes'         => $int( 'bytes', self::MAX_BYTES ),
			'requests'      => $int( 'requests', self::MAX_REQUESTS ),
			'new'           => $float( 'new' ),
			'return'        => $float( 'return' ),
			'grade'         => $grade,
			'host'          => $host,
			'country'       => $cntry,
			'intensity'     => $float( 'intensity' ),
			'measured'      => $int( 'measured', PHP_INT_MAX ),
			'largest_url'   => $url,
			'largest_bytes' => $int( 'largest_bytes', self::MAX_BYTES ),
		);
	}

	/**
	 * Dernière mesure enregistrée d'un contenu, ou null s'il n'y en a pas.
	 *
	 * @param int $post_id Contenu.
	 * @return Result|null
	 */
	public static function stored( int $post_id ): ?array {
		$raw = $post_id > 0 ? get_post_meta( $post_id, self::META, true ) : null;
		if ( ! is_array( $raw ) || ! is_numeric( $raw['measured'] ?? null ) || (int) $raw['measured'] <= 0 ) {
			return null;
		}
		return self::sanitize_result( $raw );
	}

	/**
	 * Grammes lisibles : deux chiffres significatifs, virgule selon la langue du site.
	 *
	 * @param float $grams Valeur.
	 */
	public static function format_grams( float $grams ): string {
		if ( $grams <= 0.0 ) {
			return number_format_i18n( 0 );
		}
		if ( $grams < 0.0001 ) {
			return '< ' . number_format_i18n( 0.0001, 4 );
		}
		$decimals = (int) max( 0, min( 4, 1 - (int) floor( log10( $grams ) ) ) );
		return number_format_i18n( $grams, $decimals );
	}

	/**
	 * Conseil ayant le plus d'effet, en une phrase.
	 *
	 * @param array{bytes: int, largest_url: string, largest_bytes: int} $result Mesure.
	 */
	public static function tip( array $result ): string {
		if ( $result['largest_bytes'] >= self::HEAVY_RESOURCE && '' !== $result['largest_url'] ) {
			return sprintf(
				/* translators: 1: file name, 2: file size, e.g. 240 KB */
				__( 'The heaviest file is %1$s (%2$s). Trimming it will do the most good.', 'fluffless-suite' ),
				wp_basename( (string) wp_parse_url( $result['largest_url'], PHP_URL_PATH ) ),
				size_format( $result['largest_bytes'] )
			);
		}
		if ( $result['bytes'] > self::PAGE_BUDGET_BYTES ) {
			return sprintf(
				/* translators: %s: budget, e.g. 500 KB */
				__( 'This page is over the %s budget, with no single file to blame: look at the number of images and scripts.', 'fluffless-suite' ),
				size_format( self::PAGE_BUDGET_BYTES )
			);
		}
		return __( 'Nothing urgent: this page is within budget.', 'fluffless-suite' );
	}

	/** Route de mesure : le navigateur envoie le poids, le serveur calcule et enregistre. */
	public static function register_route(): void {
		$int = static fn( int $max ): array => array(
			'type'     => 'integer',
			'minimum'  => 0,
			'maximum'  => $max,
			'required' => false,
			'default'  => 0,
		);
		register_rest_route(
			self::REST_NS,
			'/footprint/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_measure' ),
				'permission_callback' => static fn( \WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['id'] ),
				'args'                => array(
					'bytes'         => array( 'required' => true ) + $int( self::MAX_BYTES ),
					'requests'      => $int( self::MAX_REQUESTS ),
					'largest_bytes' => $int( self::MAX_BYTES ),
					'largest_url'   => array(
						'type'      => 'string',
						'maxLength' => 2048,
						'default'   => '',
					),
				),
			)
		);
	}

	/**
	 * Calcule, enregistre et renvoie la mesure avec son conseil.
	 *
	 * @param \WP_REST_Request $request Requête.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function rest_measure( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request['id'];
		if ( null === get_post( $post_id ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'This content no longer exists.', 'fluffless-suite' ) ), 404 );
		}
		$result = self::measure( (int) $request['bytes'], (int) $request['requests'], (string) $request['largest_url'], (int) $request['largest_bytes'] );
		update_post_meta( $post_id, self::META, $result );
		return new \WP_REST_Response( $result + array( 'tip' => self::tip( $result ) ) );
	}

	/**
	 * Masque la barre d'administration pendant la mesure dans l'éditeur : le visiteur ne la reçoit pas.
	 *
	 * @param bool $show Vrai pour afficher la barre.
	 */
	public static function hide_admin_bar_while_measuring( $show ): bool {
		// Lecture seule d'un drapeau d'affichage, sans effet de bord : pas de nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ self::MEASURE_ARG ] ) ? false : (bool) $show;
	}

	/** Panneau de l'éditeur : script natif, sans build, avec l'état utile en ligne. */
	public static function editor_assets(): void {
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/footprint/editor.js', FILE ),
			array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-url' ),
			VERSION,
			true
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
		$country = Options::get_string( self::id(), 'country' );
		$country = isset( self::GRID_INTENSITY[ $country ] ) ? $country : 'WORLD';
		$data    = wp_json_encode(
			array(
				'metaKey'      => self::META,
				'measureArg'   => self::MEASURE_ARG,
				'host'         => Audit::host_status(),
				'country'      => self::countries()[ $country ],
				'intensity'    => self::GRID_INTENSITY[ $country ],
				'auditUrl'     => admin_url( 'admin.php?page=' . Audit::PAGE ),
				'carbonUrl'    => 'https://www.websitecarbon.com/',
				'staleSeconds' => self::STALE_SECONDS,
			)
		);
		if ( false !== $data ) {
			wp_add_inline_script( self::SCRIPT_HANDLE, 'window.flufflessFootprint=' . $data . ';', 'before' );
		}
	}
}
