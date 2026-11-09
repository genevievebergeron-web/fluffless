<?php
/**
 * Tests du module Rapport carbone : période bornée, lecture des réponses Plausible et Umami,
 * appels au fournisseur avec la clé en constante (doublure HTTP), cache, erreurs, rapport
 * page par page (mesuré ou estimé), totaux, masse lisible, CSV, écran pour un administrateur.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/carbone-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Carbon;
use Fluffless\Suite\Modules\Footprint;

defined( 'ABSPATH' ) || exit;
// Les contrôles lisent les textes et les nombres en en_US : indépendants de la langue du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_backup_option = get_option( Options::NAME );
$fluffless_pages         = array();
$fluffless_requests      = array();
$fluffless_stub          = null;

/**
 * Enregistre la section stats et l'état des modules comme le ferait la page de réglages.
 *
 * @param array<string, mixed> $stats Réglages de Statistiques.
 */
$fluffless_set = static function ( array $stats ): void {
	update_option(
		Options::NAME,
		Options::sanitize(
			array(
				'modules' => array(
					'stats'     => true,
					'footprint' => true,
					'carbone'   => true,
				),
				'stats'   => $stats + array(
					'provider'     => 'plausible',
					'script_url'   => '',
					'site_id'      => '',
					'skip_editors' => true,
					'proxy'        => false,
				),
			)
		)
	);
	Options::flush();
	delete_transient( Carbon::TRANSIENT );
};

try {
	// 1. Contrat du module et câblage.
	$fluffless_assert( 'carbone' === Carbon::id(), 'id' );
	$fluffless_assert( ! Carbon::enabled_by_default(), 'éteint par défaut, comme Statistiques dont il dépend (P5-02)' );
	// Décision 12 : un seul réglage, le lien vers Planetair, éteint d'office.
	$fluffless_assert( array( 'contribution_link' => false ) === Carbon::defaults(), 'réglage contribution éteint d\'office : ' . wp_json_encode( Carbon::defaults() ) );
	$fluffless_assert( 'bool' === ( Carbon::fields()['contribution_link']['type'] ?? '' ) && array( 'contribution_link' ) === array_keys( Carbon::fields() ), 'champ contribution_link de type bool' );
	$fluffless_assert( array( 'contribution_link' => true ) === Carbon::sanitize( array( 'contribution_link' => '1' ) ), 'assainissement : « 1 » donne vrai' );
	$fluffless_assert( array( 'contribution_link' => false ) === Carbon::sanitize( array( 'x' => 1 ) ), 'assainissement : clé inconnue ignorée' );
	foreach ( array( array( 'a' ), 'abc' ) as $fluffless_bad ) {
		$fluffless_assert( true !== ( Carbon::sanitize( array( 'contribution_link' => $fluffless_bad ) )['contribution_link'] ?? null ), 'assainissement : ' . wp_json_encode( $fluffless_bad ) . ' ne donne jamais vrai' );
	}
	$fluffless_help = strtolower( (string) ( Carbon::fields()['contribution_link']['help'] ?? '' ) );
	$fluffless_assert( str_contains( $fluffless_help, 'off by default' ) && str_contains( $fluffless_help, 'does not erase them' ), 'aide : éteint d\'office, une contribution n\'efface pas les chiffres (F-planetair-03)' );
	foreach ( array( 'fr_CA', 'fr_FR' ) as $fluffless_locale ) {
		$fluffless_assert( 'https://planetair.ca/compenser/' === Carbon::contribution_url( $fluffless_locale ), 'contribution_url( ' . $fluffless_locale . ' ) en /compenser/' );
	}
	foreach ( array( 'en_US', 'en_CA', '', 'de_DE' ) as $fluffless_locale ) {
		$fluffless_url = Carbon::contribution_url( $fluffless_locale );
		$fluffless_assert( 'https://planetair.ca/en/offset' === $fluffless_url && ! str_contains( $fluffless_url, '?' ) && ! str_contains( $fluffless_url, '#' ), 'contribution_url( ' . $fluffless_locale . ' ) en /en/offset, sans paramètre' );
	}
	$fluffless_loader = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Loader.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( 1 === preg_match( '/Carbon::class\s+=> array\(\s*\'stats\',\s*\'footprint\',\s*\)/', $fluffless_loader ), 'Carbon exige stats et footprint dans Loader' );
	$fluffless_lifecycle = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Lifecycle.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( 1 === preg_match( '/TRANSIENTS = array\(.*self::TRANSIENT_CARBON/', $fluffless_lifecycle ), 'transient purgé à la désinstallation' );

	// 2. Période : préréglages et dates bornées (référence : jeudi 18 septembre 2026, 15 h).
	$fluffless_now = (int) ( new DateTimeImmutable( '2026-09-18 15:00:00', wp_timezone() ) )->format( 'U' );
	$fluffless_p   = Carbon::period( '30', '', '', $fluffless_now );
	$fluffless_assert( '2026-08-19' === $fluffless_p['from'] && '2026-09-18' === $fluffless_p['to'] && '30' === $fluffless_p['preset'], '30 jours : ' . wp_json_encode( $fluffless_p ) );
	$fluffless_p = Carbon::period( 'month', '', '', $fluffless_now );
	$fluffless_assert( '2026-08-01' === $fluffless_p['from'] && '2026-08-31' === $fluffless_p['to'], 'mois dernier : ' . wp_json_encode( $fluffless_p ) );
	$fluffless_p = Carbon::period( 'quarter', '', '', $fluffless_now );
	$fluffless_assert( '2026-04-01' === $fluffless_p['from'] && '2026-06-30' === $fluffless_p['to'], 'trimestre dernier : ' . wp_json_encode( $fluffless_p ) );
	$fluffless_p = Carbon::period( 'year', '', '', $fluffless_now );
	$fluffless_assert( '2026-01-01' === $fluffless_p['from'] && '2026-09-18' === $fluffless_p['to'], 'année en cours : ' . wp_json_encode( $fluffless_p ) );
	$fluffless_p = Carbon::period( 'last-year', '', '', $fluffless_now );
	$fluffless_assert( '2025-01-01' === $fluffless_p['from'] && '2025-12-31' === $fluffless_p['to'], 'année dernière : ' . wp_json_encode( $fluffless_p ) );
	$fluffless_p = Carbon::period( 'custom', '2026-02-01', '2026-02-28', $fluffless_now );
	$fluffless_assert( '2026-02-01' === $fluffless_p['from'] && '2026-02-28' === $fluffless_p['to'] && 'custom' === $fluffless_p['preset'], 'dates libres : ' . wp_json_encode( $fluffless_p ) );
	$fluffless_p = Carbon::period( 'custom', '2020-01-01', '2030-01-01', $fluffless_now );
	$fluffless_assert( '2024-09-17' === $fluffless_p['from'] && '2026-09-18' === $fluffless_p['to'], 'dates bornées à deux ans et à aujourd\'hui : ' . wp_json_encode( $fluffless_p ) );
	$fluffless_p = Carbon::period( 'custom', '2026-02-30', 'hier', $fluffless_now );
	$fluffless_assert( '2026-08-19' === $fluffless_p['from'] && '2026-09-18' === $fluffless_p['to'], 'dates invalides ramenées aux 30 jours : ' . wp_json_encode( $fluffless_p ) );
	$fluffless_p = Carbon::period( 'custom', '2026-05-10', '2026-05-01', $fluffless_now );
	$fluffless_assert( '2026-05-01' === $fluffless_p['from'] && '2026-05-01' === $fluffless_p['to'], 'début après la fin ramené à la fin' );
	$fluffless_p = Carbon::period( 'nimporte', '', '', $fluffless_now );
	$fluffless_assert( '30' === $fluffless_p['preset'], 'préréglage inconnu ramené à 30 jours' );

	// 3. Lecture Plausible : chemin sans requête, ligne malformée ignorée, zéro vue ignorée, visiteurs bornés aux vues.
	$fluffless_read = Carbon::parse_plausible(
		array(
			'results' => array(
				array(
					'metrics'    => array( 10, 15 ),
					'dimensions' => array( '/carbone-test-a/?utm=x' ),
				),
				array(
					'metrics'    => array( 30, 20 ),
					'dimensions' => array( '/carbone-test-b/' ),
				),
				array( 'metrics' => array( 1, 1 ) ),
				array(
					'metrics'    => array( 0, 0 ),
					'dimensions' => array( '/vide/' ),
				),
				'texte',
			),
		)
	);
	$fluffless_assert( 2 === count( $fluffless_read ), 'deux pages lues : ' . count( $fluffless_read ) );
	$fluffless_assert( '/carbone-test-a/' === $fluffless_read[0]['path'] && 10 === $fluffless_read[0]['visitors'] && 15 === $fluffless_read[0]['pageviews'], 'page A lue : ' . wp_json_encode( $fluffless_read[0] ) );
	$fluffless_assert( 20 === $fluffless_read[1]['visitors'] && 20 === $fluffless_read[1]['pageviews'], 'visiteurs bornés aux vues' );
	$fluffless_assert( array() === Carbon::parse_plausible( array( 'error' => 'x' ) ), 'réponse sans résultats' );

	// 4. Lecture Umami : part de visiteurs du site appliquée à chaque page, stats à plat ou sous value.
	$fluffless_read = Carbon::parse_umami(
		array(
			array(
				'x' => '/carbone-test-a/',
				'y' => 100,
			),
			array(
				'x' => '/carbone-test-b/',
				'y' => '10',
			),
			array( 'x' => '/sans-y/' ),
		),
		array(
			'pageviews' => array( 'value' => 200 ),
			'visitors'  => array( 'value' => 50 ),
		)
	);
	$fluffless_assert( 2 === count( $fluffless_read ) && 25 === $fluffless_read[0]['visitors'] && 100 === $fluffless_read[0]['pageviews'] && 3 === $fluffless_read[1]['visitors'], 'Umami réparti : ' . wp_json_encode( $fluffless_read ) );
	$fluffless_read = Carbon::parse_umami(
		array(
			array(
				'x' => '/x/',
				'y' => 4,
			),
		),
		array(
			'pageviews' => 4,
			'visitors'  => 9,
		)
	);
	$fluffless_assert( 4 === $fluffless_read[0]['visitors'], 'part plafonnée à 1' );

	// 4 bis. Lecture Umami 3 (/metrics/expanded) : vues et visiteurs propres à chaque page, vues en chaîne.
	$fluffless_read = Carbon::parse_umami_expanded(
		array(
			array(
				'name'      => '/carbone-test-a/',
				'pageviews' => '2',
				'visitors'  => 1,
			),
			array(
				'name'      => '/carbone-test-b/',
				'pageviews' => 1,
				'visitors'  => 3,
			),
			array( 'name' => '/sans-vues/' ),
			'pas une ligne',
		)
	);
	$fluffless_assert( 2 === count( $fluffless_read ) && 1 === $fluffless_read[0]['visitors'] && 2 === $fluffless_read[0]['pageviews'] && 1 === $fluffless_read[1]['visitors'], 'Umami 3 lu par page : ' . wp_json_encode( $fluffless_read ) );

	// 5. Statut : statistiques incomplètes, puis clé absente, puis rien à redire.
	$fluffless_set( array() );
	$fluffless_assert( '' !== Carbon::status() && str_contains( Carbon::status(), 'site ID' ), 'statut : statistiques incomplètes' );
	$fluffless_pages_error = Carbon::pages( Carbon::period( '30' ) );
	$fluffless_assert( $fluffless_pages_error instanceof WP_Error && 'fluffless_carbone_stats' === $fluffless_pages_error->get_error_code(), 'pages() refuse sans statistiques' );
	$fluffless_set(
		array(
			'script_url' => 'https://plausible.example.com/js/script.js',
			'site_id'    => 'example.com',
		)
	);
	if ( defined( Carbon::KEY_CONSTANT ) ) {
		throw new RuntimeException( 'La constante de clé est déjà définie : test impossible.' );
	}
	$fluffless_assert( str_contains( Carbon::status(), Carbon::KEY_CONSTANT ), 'statut : clé absente nomme la constante' );
	$fluffless_pages_error = Carbon::pages( Carbon::period( '30' ) );
	$fluffless_assert( $fluffless_pages_error instanceof WP_Error && 'fluffless_carbone_key' === $fluffless_pages_error->get_error_code(), 'pages() refuse sans clé' );
	define( Carbon::KEY_CONSTANT, '  cle-de-test  ' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- FLUFFLESS_STATS_API_KEY, préfixée.
	$fluffless_assert( 'cle-de-test' === Carbon::key(), 'clé lue dans la constante, sans espaces' );
	$fluffless_assert( '' === Carbon::status(), 'statut vide quand tout est là' );

	// 6. Contenus de test : page A mesurée (0,5 g puis 0,1 g), page B jamais mesurée.
	foreach ( array( 'a', 'b' ) as $fluffless_suffix ) {
		$fluffless_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Carbone, test ' . $fluffless_suffix,
				'post_name'    => 'carbone-test-' . $fluffless_suffix,
				'post_content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
			),
			true
		);
		if ( ! is_int( $fluffless_id ) ) {
			throw new RuntimeException( 'Page de test non créée.' );
		}
		$fluffless_pages[ $fluffless_suffix ] = $fluffless_id;
	}
	update_post_meta(
		$fluffless_pages['a'],
		Footprint::META,
		array(
			'version'       => 1,
			'bytes'         => 250000,
			'requests'      => 12,
			'new'           => 0.5,
			'return'        => 0.1,
			'grade'         => 'B',
			'host'          => 'green',
			'country'       => 'CA',
			'intensity'     => 0.03,
			'measured'      => time(),
			'largest_url'   => '',
			'largest_bytes' => 0,
		)
	);
	$fluffless_assert( null !== Footprint::stored( $fluffless_pages['a'] ) && null === Footprint::stored( $fluffless_pages['b'] ), 'mesure posée sur A seulement' );
	$fluffless_assert( Carbon::post_of( '/carbone-test-a/' ) === $fluffless_pages['a'], 'chemin résolu en contenu : ' . Carbon::post_of( '/carbone-test-a/' ) );
	$fluffless_assert( 0 === Carbon::post_of( '/nulle-part-' . wp_rand() . '/' ), 'chemin inconnu sans contenu' );
	$fluffless_front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
	$fluffless_assert( Carbon::post_of( '/' ) === $fluffless_front, 'accueil résolu en page de garde' );

	// 7. Appel Plausible par doublure : adresse, clé en Bearer, corps, puis cache sans second appel.
	$fluffless_stub = static function ( $pre, array $args, string $url ) use ( &$fluffless_requests ) {
		$fluffless_requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( str_contains( $url, 'erreur-500' ) ) {
			return array(
				'response' => array( 'code' => 500 ),
				'body'     => '{"error":"x"}',
			);
		}
		if ( str_contains( $url, 'erreur-reseau' ) ) {
			return new WP_Error( 'http_request_failed', 'Réseau coupé.' );
		}
		if ( str_contains( $url, '/api/v2/query' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						'results' => array(
							array(
								'metrics'    => array( 10, 15 ),
								'dimensions' => array( '/carbone-test-a/' ),
							),
							array(
								'metrics'    => array( 20, 20 ),
								'dimensions' => array( '/carbone-test-b/' ),
							),
							array(
								'metrics'    => array( 3, 5 ),
								'dimensions' => array( '/inconnue-' . wp_rand() . '/' ),
							),
						),
					)
				),
			);
		}
		if ( str_contains( $url, '/stats?' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"pageviews":{"value":40},"visitors":{"value":20}}',
			);
		}
		if ( str_contains( $url, '/metrics/expanded?' ) ) {
			if ( str_contains( $url, 'umami2.example.com' ) ) {
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => '{"error":"Not found"}',
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '[{"name":"/carbone-test-a/","pageviews":"30","visitors":15},{"name":"/carbone-test-b/","pageviews":"10","visitors":5}]',
			);
		}
		if ( str_contains( $url, '/metrics?' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '[{"x":"/carbone-test-a/","y":30},{"x":"/carbone-test-b/","y":10}]',
			);
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $fluffless_stub, 10, 3 );
	$fluffless_period = Carbon::period( 'custom', '2026-08-01', '2026-08-31', $fluffless_now );
	$fluffless_got    = Carbon::pages( $fluffless_period );
	$fluffless_assert( is_array( $fluffless_got ) && 3 === count( $fluffless_got ), 'trois pages reçues de Plausible : ' . wp_json_encode( $fluffless_got ) );
	$fluffless_assert( 1 === count( $fluffless_requests ) && 'https://plausible.example.com/api/v2/query' === $fluffless_requests[0]['url'], 'une requête à l\'API Plausible : ' . wp_json_encode( array_column( $fluffless_requests, 'url' ) ) );
	$fluffless_args = $fluffless_requests[0]['args'];
	$fluffless_assert( 'POST' === ( $fluffless_args['method'] ?? '' ) && 'Bearer cle-de-test' === ( $fluffless_args['headers']['Authorization'] ?? '' ) && 10 === ( $fluffless_args['timeout'] ?? 0 ), 'méthode, clé et délai : ' . wp_json_encode( $fluffless_args ) );
	$fluffless_body = json_decode( (string) ( $fluffless_args['body'] ?? '' ), true );
	$fluffless_assert( is_array( $fluffless_body ) && 'example.com' === $fluffless_body['site_id'] && array( '2026-08-01', '2026-08-31' ) === $fluffless_body['date_range'] && array( 'event:page' ) === $fluffless_body['dimensions'] && Carbon::MAX_PAGES === $fluffless_body['pagination']['limit'], 'corps de la requête : ' . wp_json_encode( $fluffless_body ) );
	Carbon::pages( $fluffless_period );
	$fluffless_assert( 1 === count( $fluffless_requests ), 'deuxième lecture servie par le cache' );
	$fluffless_cache = get_transient( Carbon::TRANSIENT );
	$fluffless_assert( is_array( $fluffless_cache ) && 1 === count( $fluffless_cache ), 'transient avec une entrée' );
	Carbon::pages( Carbon::period( 'custom', '2026-07-01', '2026-07-31', $fluffless_now ) );
	$fluffless_assert( 2 === count( $fluffless_requests ), 'autre période, autre requête' );

	// 8. Erreurs du fournisseur : statut 500, réseau coupé, sans rien en cache.
	$fluffless_set(
		array(
			'script_url' => 'https://erreur-500.example.com/js/script.js',
			'site_id'    => 'example.com',
		)
	);
	$fluffless_got = Carbon::pages( $fluffless_period );
	$fluffless_assert( $fluffless_got instanceof WP_Error && 'fluffless_carbone_response' === $fluffless_got->get_error_code() && str_contains( $fluffless_got->get_error_message(), '500' ), 'statut 500 signalé : ' . wp_json_encode( $fluffless_got ) );
	$fluffless_set(
		array(
			'script_url' => 'https://erreur-reseau.example.com/js/script.js',
			'site_id'    => 'example.com',
		)
	);
	$fluffless_got = Carbon::pages( $fluffless_period );
	$fluffless_assert( $fluffless_got instanceof WP_Error && 'fluffless_carbone_http' === $fluffless_got->get_error_code() && str_contains( $fluffless_got->get_error_message(), 'Réseau coupé' ), 'erreur réseau signalée' );
	$fluffless_assert( false === get_transient( Carbon::TRANSIENT ), 'rien en cache après une erreur' );

	// 9. Umami 3 : une requête (metrics/expanded, type=path) sur l'API de cloud.umami.is, clé dans les deux en-têtes, millisecondes.
	$fluffless_set(
		array(
			'provider'   => 'umami',
			'script_url' => 'https://cloud.umami.is/script.js',
			'site_id'    => 'abc-123',
		)
	);
	$fluffless_before = count( $fluffless_requests );
	$fluffless_got    = Carbon::pages( $fluffless_period );
	$fluffless_umami  = array_slice( $fluffless_requests, $fluffless_before );
	$fluffless_assert( is_array( $fluffless_got ) && 2 === count( $fluffless_got ) && 15 === $fluffless_got[0]['visitors'] && 30 === $fluffless_got[0]['pageviews'] && 5 === $fluffless_got[1]['visitors'], 'pages Umami 3 lues : ' . wp_json_encode( $fluffless_got ) );
	$fluffless_assert( 1 === count( $fluffless_umami ) && str_starts_with( $fluffless_umami[0]['url'], 'https://api.umami.is/v1/api/websites/abc-123/metrics/expanded?' ) && str_contains( $fluffless_umami[0]['url'], 'type=path' ), 'adresses Umami 3 : ' . wp_json_encode( array_column( $fluffless_umami, 'url' ) ) );
	$fluffless_assert( 'cle-de-test' === ( $fluffless_umami[0]['args']['headers']['x-umami-api-key'] ?? '' ) && 'Bearer cle-de-test' === ( $fluffless_umami[0]['args']['headers']['Authorization'] ?? '' ), 'clé Umami dans les en-têtes' );
	$fluffless_assert( 1 === preg_match( '/startAt=\d{13}&endAt=\d{13}/', rawurldecode( $fluffless_umami[0]['url'] ) ), 'bornes en millisecondes : ' . $fluffless_umami[0]['url'] );

	// 9 bis. Umami 2 auto-hébergé : metrics/expanded absent, repli sur stats puis metrics?type=url.
	$fluffless_set(
		array(
			'provider'   => 'umami',
			'script_url' => 'https://umami2.example.com/script.js',
			'site_id'    => 'abc-123',
		)
	);
	$fluffless_before = count( $fluffless_requests );
	$fluffless_got    = Carbon::pages( $fluffless_period );
	$fluffless_umami  = array_slice( $fluffless_requests, $fluffless_before );
	$fluffless_assert( is_array( $fluffless_got ) && 2 === count( $fluffless_got ) && 15 === $fluffless_got[0]['visitors'] && 30 === $fluffless_got[0]['pageviews'], 'pages Umami 2 réparties : ' . wp_json_encode( $fluffless_got ) );
	$fluffless_assert( 3 === count( $fluffless_umami ) && str_contains( $fluffless_umami[1]['url'], 'https://umami2.example.com/api/websites/abc-123/stats?' ) && str_contains( $fluffless_umami[2]['url'], '/metrics?' ) && str_contains( $fluffless_umami[2]['url'], 'type=url' ), 'repli Umami 2 : ' . wp_json_encode( array_column( $fluffless_umami, 'url' ) ) );

	// 10. Rapport : A mesurée (10 × 0,5 + 5 × 0,1 = 5,5 g), B et l'inconnue estimées à la moyenne (5,5 / 15 par vue).
	$fluffless_set(
		array(
			'script_url' => 'https://plausible.example.com/js/script.js',
			'site_id'    => 'example.com',
		)
	);
	$fluffless_got = Carbon::pages( $fluffless_period );
	if ( ! is_array( $fluffless_got ) ) {
		throw new RuntimeException( 'Pages Plausible attendues.' );
	}
	$fluffless_report = Carbon::report( $fluffless_got );
	$fluffless_rows   = $fluffless_report['rows'];
	$fluffless_totals = $fluffless_report['totals'];
	$fluffless_by     = array_column( $fluffless_rows, null, 'path' );
	$fluffless_assert( abs( $fluffless_by['/carbone-test-a/']['grams'] - 5.5 ) < 0.0001 && $fluffless_by['/carbone-test-a/']['measured'] && $fluffless_pages['a'] === $fluffless_by['/carbone-test-a/']['post_id'] && 'Carbone, test a' === $fluffless_by['/carbone-test-a/']['title'], 'page A : ' . wp_json_encode( $fluffless_by['/carbone-test-a/'] ) );
	$fluffless_assert( abs( $fluffless_by['/carbone-test-b/']['grams'] - 20 * 5.5 / 15 ) < 0.0001 && ! $fluffless_by['/carbone-test-b/']['measured'] && $fluffless_pages['b'] === $fluffless_by['/carbone-test-b/']['post_id'], 'page B estimée : ' . wp_json_encode( $fluffless_by['/carbone-test-b/'] ) );
	$fluffless_assert( 3 === $fluffless_totals['pages'] && 1 === $fluffless_totals['measured'] && 33 === $fluffless_totals['visitors'] && 40 === $fluffless_totals['pageviews'], 'totaux de comptage : ' . wp_json_encode( $fluffless_totals ) );
	$fluffless_assert( abs( $fluffless_totals['measured_grams'] - 5.5 ) < 0.0001 && abs( $fluffless_totals['estimated_grams'] - 25 * 5.5 / 15 ) < 0.0001 && abs( $fluffless_totals['grams'] - 40 * 5.5 / 15 ) < 0.0001 && abs( $fluffless_totals['per_view'] - 5.5 / 15 ) < 0.0001, 'totaux de grammes : ' . wp_json_encode( $fluffless_totals ) );
	$fluffless_assert( '/carbone-test-b/' === $fluffless_rows[0]['path'], 'lignes triées par grammes décroissants' );
	$fluffless_empty = Carbon::report( array() );
	$fluffless_assert( array() === $fluffless_empty['rows'] && 0 === $fluffless_empty['totals']['pages'] && 0.0 === $fluffless_empty['totals']['grams'], 'rapport vide' );
	$fluffless_none = Carbon::report( array( $fluffless_got[1] ) );
	$fluffless_assert( 0.0 === $fluffless_none['rows'][0]['grams'] && 0.0 === $fluffless_none['totals']['per_view'], 'sans page mesurée, rien n\'est estimé' );

	// 11. Masse lisible et CSV.
	$fluffless_assert( '5.5 g' === Carbon::format_mass( 5.5 ) && '1.5 kg' === Carbon::format_mass( 1500.0 ) && '250 kg' === Carbon::format_mass( 250000.0 ) && '0 g' === Carbon::format_mass( 0.0 ), 'masses : ' . Carbon::format_mass( 5.5 ) . ' ' . Carbon::format_mass( 1500.0 ) . ' ' . Carbon::format_mass( 250000.0 ) );
	$fluffless_csv   = Carbon::csv( $fluffless_report, $fluffless_period );
	$fluffless_lines = explode( "\r\n", trim( $fluffless_csv ) );
	$fluffless_assert( 'from,to,path,title,visitors,pageviews,grams_co2e,measured' === $fluffless_lines[0] && 5 === count( $fluffless_lines ), 'CSV : en-tête et cinq lignes : ' . count( $fluffless_lines ) );
	$fluffless_assert( in_array( '2026-08-01,2026-08-31,/carbone-test-a/,"Carbone, test a",10,15,5.5,yes', $fluffless_lines, true ), 'ligne A du CSV : ' . $fluffless_csv );
	$fluffless_assert( str_contains( $fluffless_lines[1], '/carbone-test-b/,"Carbone, test b",20,20,7.3333,estimated' ), 'ligne B du CSV : ' . $fluffless_lines[1] );
	$fluffless_assert( str_starts_with( $fluffless_lines[4], '2026-08-01,2026-08-31,,total,33,40,14.6667,1/3' ), 'ligne de total : ' . $fluffless_lines[4] );
	$fluffless_hostile = Carbon::csv(
		array(
			'rows'   => array(
				array(
					'path'      => '/x/',
					'post_id'   => 0,
					'title'     => '=SUM(A1)',
					'visitors'  => 1,
					'pageviews' => 1,
					'grams'     => 1.0,
					'measured'  => false,
				),
			),
			'totals' => $fluffless_empty['totals'],
		),
		$fluffless_period
	);
	$fluffless_assert( str_contains( $fluffless_hostile, ",'=SUM(A1)," ), 'formule neutralisée dans le CSV' );

	// 12. Écran : rendu pour un administrateur avec formulaire, tableau, export et méthode ; rien pour un visiteur.
	wp_set_current_user( 0 );
	ob_start();
	Carbon::render();
	$fluffless_assert( '' === (string) ob_get_clean(), 'rien pour un visiteur' );
	$fluffless_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	if ( array() !== $fluffless_admins ) {
		wp_set_current_user( (int) $fluffless_admins[0] );
		$_GET['fl_period'] = 'custom';
		$_GET['fl_from']   = '2026-08-01';
		$_GET['fl_to']     = '2026-08-31';
		ob_start();
		Carbon::render();
		$fluffless_html = (string) ob_get_clean();
		unset( $_GET['fl_period'], $_GET['fl_from'], $_GET['fl_to'] );
		$fluffless_assert( str_contains( $fluffless_html, 'name="fl_period"' ) && str_contains( $fluffless_html, 'value="custom" selected' ), 'formulaire de période' );
		$fluffless_assert( str_contains( $fluffless_html, 'Carbone, test a' ) && str_contains( $fluffless_html, '<code>/carbone-test-b/</code>' ) && str_contains( $fluffless_html, 'Estimate' ) && str_contains( $fluffless_html, 'Measured' ), 'tableau des pages' );
		$fluffless_assert( str_contains( $fluffless_html, '15 g' ) && str_contains( $fluffless_html, '1 of 3' ), 'grands chiffres' );
		$fluffless_assert( str_contains( $fluffless_html, 'Export CSV' ) && str_contains( $fluffless_html, 'name="action" value="' . Carbon::ACTION . '"' ) && str_contains( $fluffless_html, '_wpnonce' ), 'export avec nonce' );
		$fluffless_assert( str_contains( $fluffless_html, 'Method' ) && str_contains( $fluffless_html, 'not an offset' ) && ! str_contains( $fluffless_html, '<script' ), 'méthode et aucun script' );
		foreach ( array( 'green', 'carbon neutral', 'zero emission', 'eco-friendly' ) as $fluffless_word ) {
			$fluffless_assert( ! str_contains( strtolower( $fluffless_html ), $fluffless_word ), 'mot de vertu dans l\'écran : ' . $fluffless_word );
		}
		$fluffless_assert( false === stripos( $fluffless_html, 'planetair' ), 'lien éteint : aucune mention de Planetair' );

		// 13. Lien Planetair allumé (décision 12) : une ancre propre, sans suivi ni référent (F-planetair-02).
		$fluffless_with   = static function ( array $carbone, array $modules = array() ): void {
			$fluffless_option            = (array) get_option( Options::NAME, array() );
			$fluffless_option['carbone'] = $carbone;
			$fluffless_option['modules'] = $modules + (array) ( $fluffless_option['modules'] ?? array() );
			update_option( Options::NAME, $fluffless_option );
			Options::flush();
		};
		$fluffless_render = static function (): string {
			$_GET['fl_period'] = 'custom';
			$_GET['fl_from']   = '2026-08-01';
			$_GET['fl_to']     = '2026-08-31';
			ob_start();
			Carbon::render();
			unset( $_GET['fl_period'], $_GET['fl_from'], $_GET['fl_to'] );
			return (string) ob_get_clean();
		};
		$fluffless_with( array( 'contribution_link' => true ), array( 'audit' => true ) );
		$fluffless_html = $fluffless_render();
		preg_match_all( '/<a\s[^>]*planetair[^>]*>/i', $fluffless_html, $fluffless_anchors );
		$fluffless_anchor = $fluffless_anchors[0][0] ?? '';
		$fluffless_assert( 1 === count( $fluffless_anchors[0] ) && str_contains( $fluffless_anchor, 'href="https://planetair.ca/en/offset"' ), 'une seule ancre vers planetair.ca/en/offset : ' . wp_json_encode( $fluffless_anchors[0] ) );
		$fluffless_assert( 1 === preg_match( '/rel="[^"]*noopener[^"]*"/', $fluffless_anchor ) && 1 === preg_match( '/rel="[^"]*noreferrer[^"]*"/', $fluffless_anchor ) && str_contains( $fluffless_anchor, 'referrerpolicy="no-referrer"' ), 'ancre en noopener noreferrer et referrerpolicy no-referrer : ' . $fluffless_anchor );
		$fluffless_block = (string) strstr( $fluffless_html, 'Reduce first' );
		$fluffless_block = substr( $fluffless_block, 0, (int) strpos( $fluffless_block . 'Report generated', 'Report generated' ) );
		$fluffless_assert( str_contains( $fluffless_html, 'Reduce first, contribute after.' ) && str_contains( $fluffless_html, 'Contribute to a climate project (Planetair)' ) && str_contains( $fluffless_html, '(opens in a new tab)' ), 'textes du lien présents' );
		$fluffless_assert( str_contains( $fluffless_block, 'page=' . Audit::PAGE ), 'lien vers le Bilan de sobriété' );
		foreach ( array( '<img', '<script', 'utm', 'neutral', 'carbon-free', 'cancel', 'offset your' ) as $fluffless_word ) {
			$fluffless_assert( ! str_contains( strtolower( $fluffless_block ), $fluffless_word ), 'bloc du lien sans « ' . $fluffless_word . ' »' );
		}
		$fluffless_assert( 1 === preg_match( '/href="https:\/\/planetair[^"?#]*"/', $fluffless_anchor ), 'aucun paramètre dans l\'adresse de Planetair' );
		$fluffless_assert( strpos( $fluffless_html, 'not an offset' ) < strpos( $fluffless_html, 'Reduce first' ) && strpos( $fluffless_html, 'Reduce first' ) < strpos( $fluffless_html, 'Report generated' ), 'lien après la méthode, avant la date' );
		$fluffless_user = get_current_user_id();
		update_user_meta( $fluffless_user, 'locale', 'fr_CA' );
		$fluffless_fr = $fluffless_render();
		delete_user_meta( $fluffless_user, 'locale' );
		$fluffless_assert( str_contains( $fluffless_fr, 'href="https://planetair.ca/compenser/"' ), 'langue de l\'admin en français : lien en /compenser/' );
		$fluffless_with( array( 'contribution_link' => true ), array( 'audit' => false ) );
		$fluffless_html  = $fluffless_render();
		$fluffless_block = (string) strstr( $fluffless_html, 'Reduce first' );
		$fluffless_assert( str_contains( $fluffless_html, 'Reduce first, contribute after.' ) && ! str_contains( $fluffless_block, 'page=' . Audit::PAGE ), 'Bilan éteint : la phrase reste, le lien vers le Bilan disparaît' );
		$fluffless_assert( false === stripos( Carbon::csv( $fluffless_report, $fluffless_period ), 'planetair' ), 'CSV sans Planetair' );
		wp_set_current_user( 0 );
		ob_start();
		Carbon::render();
		$fluffless_assert( '' === (string) ob_get_clean(), 'lien allumé : rien pour un visiteur' );
		$fluffless_src = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS ) ) as $fluffless_file ) {
			if ( false !== stripos( (string) file_get_contents( (string) $fluffless_file ), 'planetair' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$fluffless_src[] = basename( (string) $fluffless_file );
			}
		}
		$fluffless_assert( array( 'Carbon.php' ) === $fluffless_src, 'Planetair nommé seulement dans Carbon.php : ' . wp_json_encode( $fluffless_src ) );
	}
} finally {
	if ( null !== $fluffless_stub ) {
		remove_filter( 'pre_http_request', $fluffless_stub, 10 );
	}
	foreach ( $fluffless_pages as $fluffless_id ) {
		wp_delete_post( $fluffless_id, true );
	}
	delete_transient( Carbon::TRANSIENT );
	if ( false === $fluffless_backup_option ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_backup_option );
	}
	Options::flush();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Rapport carbone : {$fluffless_checks} vérifications passées." );
