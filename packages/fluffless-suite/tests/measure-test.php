<?php
/**
 * Tests des modules de mesure : Empreinte (SWDM v4, note, bloc), Bilan de sobriété, Ménage.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/measure-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * Aucun appel réseau : toutes les réponses HTTP sont simulées par pre_http_request.
 * Contenus et transitoires créés par le test sont retirés à la fin, même en cas d'échec.
 *
 * @package Fluffless_Suite
 */

// Le test simule des crochets du cœur (pre_http_request) : ces noms ne peuvent pas porter le préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Footprint;
use Fluffless\Suite\Modules\Housekeeping;
use Fluffless\Suite\Modules\Media\Quarantine;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Footprint::class ) || ! WP_Block_Type_Registry::get_instance()->is_registered( 'fluffless/empreinte' ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

/** Égalité à 1e-9 près en relatif : même calcul que CO2.js, aux arrondis flottants près. */
$fluffless_close = static fn( float $a, float $b ): bool => abs( $a - $b ) <= 1e-9 * max( 1.0, abs( $b ) );

/**
 * Réponse HTTP simulée au format de l'API HTTP de WordPress.
 *
 * @param int                   $code    Statut.
 * @param array<string, string> $headers En-têtes (clés en minuscules).
 * @param string                $body    Corps.
 */
$fluffless_response = static fn( int $code, array $headers = array(), string $body = '' ): array => array(
	'headers'  => $headers,
	'body'     => $body,
	'response' => array(
		'code'    => $code,
		'message' => '',
	),
	'cookies'  => array(),
	'filename' => null,
);

$fluffless_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
if ( ! current_user_can( 'manage_options' ) ) {
	throw new RuntimeException( 'Aucun compte administrateur : le test du Ménage en a besoin.' );
}

$fluffless_backup = array(
	'host'   => get_transient( Lifecycle::TRANSIENT_HOST_STATUS ),
	'report' => get_transient( Lifecycle::TRANSIENT_AUDIT_REPORT ),
);
$fluffless_posts  = array();
// Partagé avec le filtre, qui le modifie : nombre d'appels et réponse simulée courante (null = réseau refusé).
// Propriétés typées : l'analyse statique ne les croit pas constantes.
$fluffless_net = new class() {
	/**
	 * Appels HTTP interceptés.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Réponse simulée : fonction ( $args, $url ), ou null pour refuser le réseau.
	 *
	 * @var callable|null
	 */
	public $mock = null;
};
// Identifiant d'un contenu de test créé, ou arrêt immédiat : la suite n'aurait aucun sens.
$fluffless_id   = static fn( $result ): int => is_int( $result ) && $result > 0 ? $result : throw new RuntimeException( esc_html( 'Création de contenu de test impossible : ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'identifiant nul' ) ) );
$fluffless_http = static function ( $pre, $args, $url ) use ( $fluffless_net ) {
	++$fluffless_net->calls;
	$mock = $fluffless_net->mock;
	return is_callable( $mock ) ? $mock( $args, $url ) : new WP_Error( 'fluffless_test', 'réseau interdit pendant le test : ' . $url );
};
add_filter( 'pre_http_request', $fluffless_http, 1, 3 );
$fluffless_calls = static fn(): int => $fluffless_net->calls;

try {
	// 1. SWDM v4 : trois poids témoins contre CO2.js 0.19.0 (perVisitTrace, voir le rapport de livraison).
	// [ octets, hébergeur reconnu, pays du centre de données, nouvelle visite, visite de retour, note ].
	$fluffless_refs = array(
		array( 250000, false, 'WORLD', 0.03705, 0.000741, 'A+' ),
		array( 1000000, true, 'WORLD', 0.12103, 0.0024206, 'B' ),
		array( 3500000, false, 'CAN', 0.459215575, 0.0091843115, 'F' ),
	);
	foreach ( $fluffless_refs as [ $fluffless_bytes, $fluffless_green, $fluffless_country, $fluffless_first, $fluffless_back, $fluffless_grade ] ) {
		$fluffless_new = Footprint::per_visit( $fluffless_bytes, $fluffless_green, Footprint::GRID_INTENSITY[ $fluffless_country ] );
		$fluffless_assert( $fluffless_close( $fluffless_new, $fluffless_first ), "swdm : {$fluffless_bytes} o ({$fluffless_country}) = {$fluffless_new}, attendu {$fluffless_first}" );
		$fluffless_assert( $fluffless_close( $fluffless_new * Footprint::RETURN_DATA_RATIO, $fluffless_back ), "swdm : visite de retour {$fluffless_bytes} o, attendu {$fluffless_back}" );
		$fluffless_assert( Footprint::grade( $fluffless_new ) === $fluffless_grade, "swdm : note de {$fluffless_bytes} o = " . Footprint::grade( $fluffless_new ) . ", attendu {$fluffless_grade}" );
	}
	$fluffless_assert( 0.0 === Footprint::per_visit( 0, false, 494.0 ), 'swdm : 0 octet ne donne pas 0' );

	// 2. Note : bornes incluses, comme outputRating() de CO2.js.
	$fluffless_bounds = array(
		'0.04'   => 'A+',
		'0.0401' => 'A',
		'0.079'  => 'A',
		'0.145'  => 'B',
		'0.209'  => 'C',
		'0.278'  => 'D',
		'0.359'  => 'E',
		'0.3591' => 'F',
	);
	foreach ( $fluffless_bounds as $fluffless_g => $fluffless_grade ) {
		$fluffless_assert( Footprint::grade( (float) $fluffless_g ) === $fluffless_grade, "note : {$fluffless_g} g = " . Footprint::grade( (float) $fluffless_g ) . ", attendu {$fluffless_grade}" );
	}
	$fluffless_junk = Footprint::sanitize_result(
		array(
			'bytes' => -5,
			'grade' => 'Z',
			'host'  => 'oui',
		)
	);
	$fluffless_assert( 0 === $fluffless_junk['bytes'] && 'F' === $fluffless_junk['grade'] && 'unknown' === $fluffless_junk['host'], 'mesure : valeurs invalides conservées' );

	// 3. Bilan : site local = impossible à vérifier, sans aucun appel réseau.
	$fluffless_net->calls = 0;
	$fluffless_item       = Audit::check_host( 'localhost' );
	$fluffless_assert( 'unknown' === $fluffless_item['status'] && 0 === $fluffless_calls(), 'bilan : site local vérifié ou appel réseau lancé' );
	$fluffless_assert( ! fluffless_host_is_green(), 'bilan : statut vert sans vérification' );
	foreach ( array( 'fluffless.local', 'wp.test', '192.168.1.4', 'intranet' ) as $fluffless_host ) {
		$fluffless_assert( Audit::is_local_host( $fluffless_host ), "bilan : {$fluffless_host} jugé public" );
	}
	$fluffless_assert( ! Audit::is_local_host( 'genevievebergeron.ca' ) && ! Audit::is_local_host( '8.8.8.8' ), 'bilan : domaine public jugé local' );

	// Hébergeur reconnu.
	$fluffless_net->mock = static function ( $args, $url ) use ( $fluffless_response ) {
		return str_starts_with( $url, Audit::GWF_API . 'example.org' )
			? $fluffless_response(
				200,
				array(),
				(string) wp_json_encode(
					array(
						'green'     => true,
						'hosted_by' => 'Hébergeur <b>Test</b>',
					)
				)
			)
			: new WP_Error( 'fluffless_test', $url );
	};
	$fluffless_item      = Audit::check_host( 'example.org' );
	$fluffless_assert( 'good' === $fluffless_item['status'] && str_contains( $fluffless_item['detail'], 'Hébergeur Test' ), 'bilan : hébergeur reconnu mal rendu : ' . $fluffless_item['detail'] );
	$fluffless_assert( fluffless_host_is_green() && 'yes' === Audit::host_status(), 'bilan : fluffless_host_is_green() faux après confirmation' );

	// Hébergeur non reconnu : rouge, correction à demander, badge éteint.
	$fluffless_net->mock = static fn() => $fluffless_response( 200, array(), '{"green":false,"url":"example.org"}' );
	$fluffless_item      = Audit::check_host( 'example.org' );
	$fluffless_assert( 'bad' === $fluffless_item['status'] && '' !== $fluffless_item['fix'], 'bilan : hébergeur non reconnu sans correction' );
	$fluffless_assert( ! fluffless_host_is_green() && 'no' === Audit::host_status(), 'bilan : badge encore allumé pour un hébergeur non reconnu' );

	// Erreur réseau puis réponse inattendue : impossible à vérifier, jamais un faux résultat.
	$fluffless_net->mock = static fn() => new WP_Error( 'http_request_failed', 'délai dépassé' );
	$fluffless_item      = Audit::check_host( 'example.org' );
	$fluffless_assert( 'unknown' === $fluffless_item['status'] && str_contains( $fluffless_item['detail'], 'délai dépassé' ), 'bilan : erreur réseau non signalée' );
	$fluffless_assert( 'unknown' === Audit::host_status() && ! fluffless_host_is_green(), 'bilan : statut conservé après une vérification ratée' );
	$fluffless_net->mock = static fn() => $fluffless_response( 503, array(), 'indisponible' );
	$fluffless_assert( 'unknown' === Audit::check_host( 'example.org' )['status'], 'bilan : réponse 503 prise pour un résultat' );

	// Accueil simulé : HTTP/3 annoncé, Brotli, trois politiques de cache, poids sous le budget.
	// HTML de fixture, jamais affiché : les sniffs d'enregistrement de scripts et styles ne s'appliquent pas.
	// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.WP.EnqueuedResources.NonEnqueuedScript
	$fluffless_html = '<!doctype html><html><head><link rel="stylesheet" href="/style.css?ver=1"><style>@font-face{src:url(/fonts/inter.woff2) format("woff2")}</style>'
		. '<script src="/app.js"></script></head><body><img src="/uploads/photo.webp" alt=""><img src="data:image/gif;base64,R0lG" alt=""></body></html>';
	// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.WP.EnqueuedResources.NonEnqueuedScript
	$fluffless_net->mock = static function ( $args, $url ) use ( $fluffless_response, $fluffless_html ) {
		$heads = array(
			'https://example.org/style.css?ver=1'    => array( 10000, 'public, max-age=31536000' ),
			'https://example.org/fonts/inter.woff2'  => array( 20000, '' ),
			'https://example.org/app.js'             => array( 1000, 'max-age=31536000, immutable' ),
			'https://example.org/uploads/photo.webp' => array( 50000, 'max-age=172800' ),
		);
		if ( 'https://example.org/' === $url ) {
			return $fluffless_response(
				200,
				array(
					'content-encoding' => 'br',
					'content-length'   => '5000',
					'alt-svc'          => 'h3=":443"; ma=86400',
				),
				$fluffless_html
			);
		}
		if ( 'HEAD' === ( $args['method'] ?? '' ) && isset( $heads[ $url ] ) ) {
			return $fluffless_response(
				200,
				array_filter(
					array(
						'content-length' => (string) $heads[ $url ][0],
						'cache-control'  => $heads[ $url ][1],
					)
				)
			);
		}
		return new WP_Error( 'fluffless_test', 'adresse inattendue : ' . $url );
	};
	$fluffless_resources = Audit::resources( $fluffless_html, 'https://example.org/' );
	$fluffless_assert( 4 === count( $fluffless_resources ), 'bilan : ressources de l\'accueil mal extraites : ' . wp_json_encode( $fluffless_resources ) );
	$fluffless_items    = Audit::check_home( 'https://example.org/', false );
	$fluffless_expected = array(
		'protocol'    => 'good',
		'compression' => 'good',
		'cache_css'   => 'good',
		'cache_image' => 'warn',
		'cache_font'  => 'bad',
		'weight'      => 'good',
		'captcha'     => 'good',
	);
	foreach ( $fluffless_expected as $fluffless_key => $fluffless_status ) {
		$fluffless_got = $fluffless_items[ $fluffless_key ]['status'] ?? 'absent';
		$fluffless_assert( $fluffless_got === $fluffless_status, "bilan : {$fluffless_key} = {$fluffless_got}, attendu {$fluffless_status}" );
	}
	$fluffless_assert( str_contains( $fluffless_items['weight']['detail'], size_format( 86000 ) ), 'bilan : poids de l\'accueil faux : ' . $fluffless_items['weight']['detail'] );
	$fluffless_assert( 'bad' === Audit::check_compression( '' )['status'] && 'bad' === Audit::check_weight( 500000, true, array() )['status'], 'bilan : absence de compression ou page lourde non signalée' );
	add_filter( 'fluffless_audit_http_version', static fn() => '1.1' );
	$fluffless_assert( 'bad' === Audit::check_protocol( 'https://example.org/', '', false )['status'], 'bilan : HTTP/1.1 non signalé' );
	$fluffless_assert( 'unknown' === Audit::check_protocol( 'http://localhost/', '', true )['status'], 'bilan : protocole jugé sur un site local' );
	remove_all_filters( 'fluffless_audit_http_version' );
	// La sonde HTTP/2 passe par pre_http_request, donc par la liste des domaines permis, avant cURL.
	$fluffless_probed = array();
	$fluffless_block  = static function ( $pre, $args, $url ) use ( &$fluffless_probed ) {
		$fluffless_probed[] = $url;
		return new WP_Error( 'fluffless_http_blocked', 'bloqué' );
	};
	add_filter( 'pre_http_request', $fluffless_block, 1, 3 );
	$fluffless_assert( null === Audit::http_version( 'https://example.org/' ) && array( 'https://example.org/' ) === $fluffless_probed, 'bilan : sonde HTTP/2 hors de la liste des domaines permis' );
	remove_filter( 'pre_http_request', $fluffless_block, 1 );

	// Accueil injoignable : chaque point est « impossible à vérifier ».
	$fluffless_net->mock = static fn() => new WP_Error( 'http_request_failed', 'refusé' );
	foreach ( Audit::check_home( 'https://example.org/', false ) as $fluffless_key => $fluffless_item ) {
		$fluffless_assert( 'unknown' === $fluffless_item['status'], "bilan : {$fluffless_key} jugé sans réponse de l'accueil" );
	}
	$fluffless_net->mock = null;

	// 4. Bloc Empreinte : rien sans mesure ; phrase, note, date et lien avec mesure ; aucun mot interdit.
	$fluffless_page    = $fluffless_id(
		wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Test empreinte',
			),
			true
		)
	);
	$fluffless_posts[] = $fluffless_page;
	$GLOBALS['post']   = get_post( (int) $fluffless_page ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- contexte du rendu de bloc.
	$fluffless_markup  = '<!-- wp:fluffless/empreinte /-->';
	$fluffless_assert( '' === trim( do_blocks( $fluffless_markup ) ), 'bloc : rendu non vide sans mesure' );

	// Enregistrement dans l'éditeur : il renvoie tout l'objet meta lu, empreinte comprise, même vide.
	$fluffless_save = static function ( int $page, string $title ): array {
		$read = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $page );
		$read->set_query_params( array( 'context' => 'edit' ) );
		$meta  = (array) ( rest_do_request( $read )->get_data()['meta'] ?? array() );
		$write = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $page );
		$write->set_body_params(
			array(
				'title' => $title,
				'meta'  => $meta,
			)
		);
		return array( array_key_exists( Footprint::META, $meta ), rest_do_request( $write )->get_status() );
	};

	list( $fluffless_sent, $fluffless_status ) = $fluffless_save( (int) $fluffless_page, 'Test empreinte bis' );
	$fluffless_assert( $fluffless_sent && 200 === $fluffless_status, 'rest : enregistrement refusé sans mesure (statut ' . $fluffless_status . ')' );
	$fluffless_assert( null === Footprint::stored( (int) $fluffless_page ), 'rest : empreinte créée par un enregistrement de l’éditeur' );

	$fluffless_result = Footprint::measure( 420000, 18, 'https://example.org/wp-content/uploads/hero.jpg', 250000 );
	update_post_meta( (int) $fluffless_page, Footprint::META, $fluffless_result );
	$fluffless_html = do_blocks( $fluffless_markup );
	// Promesse C-59 : deux scénarios étiquetés, jamais une « fourchette » « ≈ X à Y ».
	$fluffless_assert( ! str_contains( $fluffless_html, '≈' ) && str_contains( $fluffless_html, 'SWDM v4' ) && str_contains( $fluffless_html, $fluffless_result['grade'] ), 'bloc : fourchette « ≈ » au lieu de deux estimations SWDM v4, ou note absente : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, Footprint::format_grams( $fluffless_result['new'] ) . ' g CO₂e (new visit), ' . Footprint::format_grams( $fluffless_result['return'] ) . ' g (returning visit)' ), 'bloc : chiffres non étiquetés (nouvelle visite, puis visite de retour) : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, (string) wp_date( (string) get_option( 'date_format' ), $fluffless_result['measured'] ) ), 'bloc : date de mesure absente' );
	$fluffless_assert( str_contains( $fluffless_html, 'href="' . Footprint::DEFAULT_METHOD_URL . '"' ), 'bloc : lien Méthode par défaut absent' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ) && ! str_contains( $fluffless_html, '<style' ) && strlen( $fluffless_html ) < 512, 'bloc : script, style ou plus de 512 octets : ' . strlen( $fluffless_html ) );
	$fluffless_custom = do_blocks( '<!-- wp:fluffless/empreinte {"methodUrl":"https://example.org/methode/"} /-->' );
	$fluffless_assert( str_contains( $fluffless_custom, 'href="https://example.org/methode/"' ), 'bloc : adresse de méthode réglée ignorée' );
	$fluffless_hostile = do_blocks( '<!-- wp:fluffless/empreinte {"methodUrl":"javascript:alert(1)"} /-->' );
	$fluffless_assert( ! str_contains( $fluffless_hostile, 'javascript:' ), 'bloc : adresse javascript: acceptée' );

	$fluffless_forbidden = '/\b(green|vert|verte|carboneutre|carbon[- ]neutral|z[ée]ro [ée]missions?)\b/iu';
	$fluffless_rendered  = array( 'en' => $fluffless_html );
	if ( switch_to_locale( 'fr_CA' ) ) {
		$fluffless_rendered['fr_CA'] = do_blocks( $fluffless_markup );
		restore_previous_locale();
		// La traduction de la nouvelle phrase viendra avec la passe bilingue ; d'ici là, aucune fourchette ne doit ressortir.
		$fluffless_assert( ! str_contains( $fluffless_rendered['fr_CA'], '≈' ), 'bloc : fourchette « ≈ » dans le rendu fr_CA : ' . $fluffless_rendered['fr_CA'] );
	}
	foreach ( $fluffless_rendered as $fluffless_locale => $fluffless_text ) {
		$fluffless_assert( 0 === preg_match( $fluffless_forbidden, wp_strip_all_tags( $fluffless_text ) ), "bloc : mot interdit dans le rendu {$fluffless_locale} : {$fluffless_text}" );
	}
	$fluffless_assert( str_contains( Footprint::tip( $fluffless_result ), 'hero.jpg' ), 'empreinte : conseil sans la plus grosse ressource' );

	// Route de mesure : calcul serveur pour qui peut modifier ; refus sinon ; API REST standard sans droit d'écriture.
	$fluffless_request = new WP_REST_Request( 'POST', '/fluffless/v1/footprint/' . $fluffless_page );
	$fluffless_request->set_body_params(
		array(
			'bytes'    => 1000000,
			'requests' => 12,
		)
	);
	$fluffless_rest = rest_do_request( $fluffless_request );
	$fluffless_meta = Footprint::stored( (int) $fluffless_page );
	$fluffless_assert( 200 === $fluffless_rest->get_status() && null !== $fluffless_meta && 1000000 === $fluffless_meta['bytes'], 'rest : mesure non enregistrée (' . $fluffless_rest->get_status() . ')' );
	$fluffless_forged = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $fluffless_page );
	$fluffless_forged->set_body_params( array( 'meta' => array( Footprint::META => array( 'bytes' => 1 ) ) ) );
	$fluffless_forged_status = rest_do_request( $fluffless_forged )->get_status();
	$fluffless_meta_after    = Footprint::stored( (int) $fluffless_page );
	$fluffless_assert( null !== $fluffless_meta_after && 1000000 === $fluffless_meta_after['bytes'], 'rest : métadonnée réécrite par /wp/v2 (statut ' . $fluffless_forged_status . ')' );
	$fluffless_assert( 200 === $fluffless_forged_status, 'rest : écriture de l’empreinte refusée en erreur au lieu d’être ignorée (statut ' . $fluffless_forged_status . ')' );
	list( $fluffless_sent, $fluffless_status ) = $fluffless_save( (int) $fluffless_page, 'Test empreinte ter' );
	$fluffless_meta_kept                       = Footprint::stored( (int) $fluffless_page );
	$fluffless_assert( $fluffless_sent && 200 === $fluffless_status && $fluffless_meta_kept === $fluffless_meta_after, 'rest : enregistrement refusé ou empreinte modifiée avec mesure (statut ' . $fluffless_status . ')' );
	wp_set_current_user( 0 );
	$fluffless_assert( 401 === rest_do_request( $fluffless_request )->get_status(), 'rest : mesure acceptée sans connexion' );
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );

	// 5. Ménage : rien ne bouge sans action ; l'action met à la corbeille, jamais plus loin.
	$fluffless_old       = $fluffless_id(
		wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Vieux brouillon de test',
			),
			true
		)
	);
	$fluffless_new_draft = $fluffless_id(
		wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Brouillon récent de test',
			),
			true
		)
	);
	$fluffless_posts[]   = $fluffless_old;
	$fluffless_posts[]   = $fluffless_new_draft;
	$fluffless_two_years = gmdate( 'Y-m-d H:i:s', strtotime( '-2 years' ) );
	$GLOBALS['wpdb']->update(
		$GLOBALS['wpdb']->posts,
		array(
			'post_modified'     => $fluffless_two_years,
			'post_modified_gmt' => $fluffless_two_years,
		),
		array( 'ID' => $fluffless_old )
	);
	clean_post_cache( (int) $fluffless_old );
	update_option( '_transient_timeout_fluffless_test_expired', time() - 60, false );
	update_option( '_transient_fluffless_test_expired', 'périmé', false );
	$fluffless_count_before = (int) wp_count_posts( 'post' )->draft;

	( new Housekeeping() )->register();
	// Chargement de l'administration, limité aux rappels de Fluffless : ceux des autres extensions
	// (WooCommerce, par exemple) supposent un vrai écran d'administration, absent en ligne de commande.
	foreach ( $GLOBALS['wp_filter']['admin_init']->callbacks ?? array() as $fluffless_callbacks ) {
		foreach ( $fluffless_callbacks as $fluffless_callback ) {
			$fluffless_fn    = $fluffless_callback['function'];
			$fluffless_owner = is_array( $fluffless_fn ) ? ( is_object( $fluffless_fn[0] ) ? get_class( $fluffless_fn[0] ) : (string) $fluffless_fn[0] ) : ( is_string( $fluffless_fn ) ? $fluffless_fn : '' );
			if ( str_starts_with( ltrim( $fluffless_owner, '\\' ), 'Fluffless\\' ) ) {
				call_user_func( $fluffless_fn, '' );
			}
		}
	}
	$fluffless_report = Housekeeping::report( true );
	$fluffless_assert( in_array( (int) $fluffless_old, array_column( $fluffless_report['drafts'], 'id' ), true ), 'ménage : vieux brouillon absent de la liste' );
	$fluffless_assert( ! in_array( (int) $fluffless_new_draft, array_column( $fluffless_report['drafts'], 'id' ), true ), 'ménage : brouillon récent listé' );
	$fluffless_assert( $fluffless_report['transients'] >= 1, 'ménage : transitoire expiré non compté' );
	wp_cache_delete( 'posts', 'counts' );
	$fluffless_assert( 'draft' === get_post_status( (int) $fluffless_old ) && (int) wp_count_posts( 'post' )->draft === $fluffless_count_before, 'ménage : un contenu a bougé sans action' );
	$fluffless_assert( 'périmé' === get_option( '_transient_fluffless_test_expired' ), 'ménage : transitoire purgé sans action' );

	$fluffless_assert(
		'denied' === Housekeeping::handle_request(
			array(
				'task'     => 'trash',
				'post_id'  => $fluffless_old,
				'_wpnonce' => 'faux',
			)
		) && 'draft' === get_post_status( (int) $fluffless_old ),
		'ménage : action acceptée sans nonce valide'
	);
	$fluffless_assert(
		'denied' === Housekeeping::handle_request(
			array(
				'task'     => 'trash',
				'post_id'  => $fluffless_old,
				'_wpnonce' => wp_create_nonce( Housekeeping::nonce_action( 'trash', (int) $fluffless_new_draft ) ),
			)
		),
		'ménage : nonce d\'un autre contenu accepté'
	);
	$fluffless_assert(
		'refused' === Housekeeping::handle_request(
			array(
				'task'     => 'trash',
				'post_id'  => $fluffless_new_draft,
				'_wpnonce' => wp_create_nonce( Housekeeping::nonce_action( 'trash', (int) $fluffless_new_draft ) ),
			)
		) && 'draft' === get_post_status( (int) $fluffless_new_draft ),
		'ménage : brouillon récent mis à la corbeille'
	);
	$fluffless_code = Housekeeping::handle_request(
		array(
			'task'     => 'trash',
			'post_id'  => $fluffless_old,
			'_wpnonce' => wp_create_nonce( Housekeeping::nonce_action( 'trash', (int) $fluffless_old ) ),
		)
	);
	$fluffless_assert( 'trashed' === $fluffless_code && 'trash' === get_post_status( (int) $fluffless_old ), 'ménage : action sans corbeille (' . $fluffless_code . ')' );
	$fluffless_assert( get_post( (int) $fluffless_old ) instanceof WP_Post, 'ménage : contenu supprimé définitivement au lieu de la corbeille' );
	$fluffless_code = Housekeeping::handle_request(
		array(
			'task'     => 'purge',
			'post_id'  => 0,
			'_wpnonce' => wp_create_nonce( Housekeeping::nonce_action( 'purge', 0 ) ),
		)
	);
	// La purge passe par SQL : le cache de la requête garde l'ancienne valeur, d'où le vidage.
	wp_cache_delete( '_transient_fluffless_test_expired', 'options' );
	$fluffless_assert( 'purged' === $fluffless_code && false === get_option( '_transient_fluffless_test_expired' ), 'ménage : transitoire expiré non purgé' );

	// Média : cité dans un contenu = gardé ; jamais jeté sans corbeille des médias.
	$fluffless_media   = $fluffless_id(
		wp_insert_attachment(
			array(
				'post_title'     => 'Média de test',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			),
			false,
			0,
			true
		)
	);
	$fluffless_posts[] = $fluffless_media;
	update_post_meta( (int) $fluffless_media, '_wp_attached_file', '2020/01/fluffless-test-orphelin-q7z.jpg' );
	$fluffless_assert( ! Housekeeping::is_media_referenced( (int) $fluffless_media ), 'ménage : média orphelin jugé utilisé' );
	// Le Ménage ne jette jamais un média, même avec MEDIA_TRASH : la quarantaine s'en charge.
	// get_post_status() renvoie « publish » pour un média sans parent : on lit le champ brut.
	$fluffless_assert( ! Housekeeping::trash( (int) $fluffless_media ) && 'inherit' === get_post_field( 'post_status', (int) $fluffless_media ), 'ménage : média mis à la corbeille au lieu de passer par la quarantaine' );
	$fluffless_user_post = $fluffless_id(
		wp_insert_post(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Contenu qui cite le média',
				'post_content' => '<img src="/wp-content/uploads/2020/01/fluffless-test-orphelin-q7z-300x200.jpg">',
			),
			true
		)
	);
	$fluffless_posts[]   = $fluffless_user_post;
	$fluffless_assert( Housekeeping::is_media_referenced( (int) $fluffless_media ), 'ménage : média cité dans un contenu jugé inutilisé' );

	// Médias non utilisés : une requête combinée par lot (au lieu de 4 par média), liste en cache, oubliée après une action.
	$fluffless_orphan = 0;
	for ( $fluffless_i = 0; $fluffless_i < 10; $fluffless_i++ ) {
		$fluffless_orphan  = $fluffless_id(
			wp_insert_attachment(
				array(
					'post_title'     => 'Orphelin ' . $fluffless_i,
					'post_mime_type' => 'image/jpeg',
					'post_status'    => 'inherit',
				),
				false,
				0,
				true
			)
		);
		$fluffless_posts[] = $fluffless_orphan;
		update_post_meta( $fluffless_orphan, '_wp_attached_file', '2020/01/fluffless-lot-' . $fluffless_i . '-q7z.jpg' );
	}
	Housekeeping::forget_media_scan();
	wp_cache_flush();
	$fluffless_before  = $GLOBALS['wpdb']->num_queries;
	$fluffless_scan    = array_column( Housekeeping::unused_media(), 'id' );
	$fluffless_first   = $GLOBALS['wpdb']->num_queries - $fluffless_before;
	$fluffless_before  = $GLOBALS['wpdb']->num_queries;
	$fluffless_again   = array_column( Housekeeping::unused_media(), 'id' );
	$fluffless_second  = $GLOBALS['wpdb']->num_queries - $fluffless_before;
	$fluffless_scanned = count(
		get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 0,
				'numberposts' => Housekeeping::MEDIA_SCAN_LIMIT,
				'fields'      => 'ids',
			)
		)
	);
	$fluffless_batches = (int) ceil( $fluffless_scanned / Housekeeping::MEDIA_BATCH );
	echo esc_html( "Mesure ménage : {$fluffless_scanned} médias examinés, {$fluffless_first} requêtes au premier affichage, {$fluffless_second} au second.\n" );
	$fluffless_assert( $fluffless_first <= 12 + $fluffless_batches, "ménage : {$fluffless_first} requêtes pour {$fluffless_scanned} médias ({$fluffless_batches} lot(s)), plafond " . ( 12 + $fluffless_batches ) );
	$fluffless_assert( in_array( $fluffless_orphan, $fluffless_scan, true ) && ! in_array( (int) $fluffless_media, $fluffless_scan, true ), 'ménage : orphelin absent de la liste, ou média cité listé' );
	$fluffless_assert( $fluffless_again === $fluffless_scan && $fluffless_second <= 1, "ménage : liste recalculée au lieu du cache ({$fluffless_second} requêtes)" );
	$fluffless_cache_draft = $fluffless_id(
		wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Brouillon pour le cache',
			),
			true
		)
	);
	$fluffless_posts[]     = $fluffless_cache_draft;
	$GLOBALS['wpdb']->update(
		$GLOBALS['wpdb']->posts,
		array(
			'post_modified'     => $fluffless_two_years,
			'post_modified_gmt' => $fluffless_two_years,
		),
		array( 'ID' => $fluffless_cache_draft )
	);
	clean_post_cache( $fluffless_cache_draft );
	$fluffless_assert( Housekeeping::trash( $fluffless_cache_draft ) && false === get_transient( Lifecycle::TRANSIENT_UNUSED_MEDIA ), 'ménage : liste des médias gardée en cache après une mise à la corbeille' );

	// Plafond de révisions : jamais au-dessus d'un plafond déjà plus bas.
	$fluffless_assert( 10 === Housekeeping::revisions_to_keep( -1 ) && 3 === Housekeeping::revisions_to_keep( 3 ) && 0 === Housekeeping::revisions_to_keep( 0 ), 'ménage : plafond de révisions mal appliqué' );
	$fluffless_assert( false === Options::get_bool( 'housekeeping', 'cap_revisions' ), 'ménage : plafond appliqué par défaut' );

	// Médias non utilisés vus par la quarantaine : un avis par média, un lien, aucun formulaire d'action.
	ob_start();
	Quarantine::housekeeping_media(
		array(
			array(
				'id'    => $fluffless_orphan,
				'title' => 'Orphelin',
			),
			array(
				'id'    => (int) $fluffless_media,
				'title' => 'Média cité',
			),
			'pas un tableau',
		)
	);
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( 2 === substr_count( $fluffless_html, '<li>' ), 'ménage : la quarantaine ne rend pas une ligne par média valide' );
	$fluffless_assert( str_contains( $fluffless_html, 'fl-pill--good' ), 'ménage : média cité dans un contenu non signalé « utilisé » par Quarantine::check()' );
	$fluffless_assert( str_contains( $fluffless_html, 'page=' . Quarantine::PAGE ), 'ménage : lien vers l\'écran de quarantaine absent' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'admin-post.php' ), 'ménage : formulaire d\'action dans la liste des médias' );
	ob_start();
	Quarantine::housekeeping_media( null );
	$fluffless_assert( '' === ob_get_clean(), 'ménage : sortie produite pour une liste invalide' );

	// Écran Ménage : avis par Screen::note(), médias sans bouton Corbeille.
	$fluffless_listener = has_action( Housekeeping::UNUSED_MEDIA_HOOK, array( Quarantine::class, 'housekeeping_media' ) );
	if ( false === $fluffless_listener ) {
		add_action( Housekeeping::UNUSED_MEDIA_HOOK, array( Quarantine::class, 'housekeeping_media' ) );
	}
	$_GET['scan-media']     = '1';
	$_GET['fluffless-done'] = 'purged';
	ob_start();
	Housekeeping::render();
	$fluffless_page = (string) ob_get_clean();
	unset( $_GET['scan-media'], $_GET['fluffless-done'] );
	if ( false === $fluffless_listener ) {
		remove_action( Housekeeping::UNUSED_MEDIA_HOOK, array( Quarantine::class, 'housekeeping_media' ) );
	}
	$fluffless_assert( str_contains( $fluffless_page, 'fl-note fl-note--good' ) && ! str_contains( $fluffless_page, 'notice notice-' ), 'ménage : avis rendu sans Screen::note()' );
	$fluffless_assert( ! str_contains( $fluffless_page, 'name="post_id" value="' . $fluffless_orphan . '"' ), 'ménage : bouton Corbeille proposé pour un média' );
	if ( array() !== Housekeeping::report( true )['media'] ) {
		$fluffless_assert( str_contains( $fluffless_page, 'page=' . Quarantine::PAGE ), 'ménage : médias listés sans lien vers la quarantaine' );
	}
} finally {
	remove_filter( 'pre_http_request', $fluffless_http, 1 );
	// Contenus créés par ce test seulement (identifiants garantis par $fluffless_id).
	foreach ( $fluffless_posts as $fluffless_post_id ) {
		wp_delete_post( $fluffless_post_id, true );
	}
	delete_option( '_transient_timeout_fluffless_test_expired' );
	delete_option( '_transient_fluffless_test_expired' );
	foreach ( array(
		Lifecycle::TRANSIENT_HOST_STATUS  => $fluffless_backup['host'],
		Lifecycle::TRANSIENT_AUDIT_REPORT => $fluffless_backup['report'],
	) as $fluffless_name => $fluffless_value ) {
		if ( false === $fluffless_value ) {
			delete_transient( $fluffless_name );
		} else {
			set_transient( $fluffless_name, $fluffless_value, DAY_IN_SECONDS );
		}
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}

echo esc_html( 'OK : ' . $fluffless_checks . " contrôles réussis (mesure : SWDM, note, bloc Empreinte, route REST, Bilan, Ménage).\n" );
