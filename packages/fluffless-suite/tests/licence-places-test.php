<?php
/**
 * Tests des états de licence rendus par l'API (places, révocation, vérification, grâce, avis),
 * avec réponses HTTP simulées : aucune requête réelle vers l'API.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/licence-places-test.php
 *
 * @package Fluffless_Suite
 */

// Le test simule des réponses par le crochet du cœur pre_http_request.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Modules\Updates;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Updates::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_plugin  = 'fluffless-suite/fluffless-suite.php';
$fluffless_licence = 'FL-TEST-' . wp_generate_password( 16, false );
/**
 * Requêtes interceptées.
 *
 * @var list<array{url: string, args: array<string, mixed>}> $fluffless_calls
 */
$fluffless_calls = array();
/**
 * Réponse simulée de l'API, remplacée à chaque étape : code, corps, en-têtes.
 *
 * Sous 'release', une autre réponse pour la seule route de libération.
 *
 * @var array{code: int, body?: mixed, headers?: array<string, string>, release?: array{code: int, body?: mixed}} $fluffless_reply
 */
$fluffless_reply = array( 'code' => 204 );
$fluffless_mock  = static function ( $pre, $args, $url ) use ( &$fluffless_calls, &$fluffless_reply ) {
	unset( $pre );
	$fluffless_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);
	$fluffless_now     = isset( $fluffless_reply['release'] ) && str_contains( (string) $url, '/v1/licences/release' ) ? $fluffless_reply['release'] : $fluffless_reply;
	if ( 0 === $fluffless_now['code'] ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
	}
	return array(
		'headers'  => $fluffless_now['headers'] ?? array(),
		'body'     => isset( $fluffless_now['body'] ) ? (string) wp_json_encode( $fluffless_now['body'] ) : '',
		'response' => array(
			'code'    => $fluffless_now['code'],
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
$fluffless_reset = static function () use ( &$fluffless_calls ): void {
	$fluffless_calls = array();
	delete_transient( Lifecycle::TRANSIENT_UPDATES );
	delete_transient( Lifecycle::TRANSIENT_LICENCE );
};
$fluffless_card  = static function (): string {
	ob_start();
	Updates::render_licence();
	return (string) ob_get_clean();
};

add_filter( 'pre_http_request', $fluffless_mock, 1, 3 );
$fluffless_module = new Updates();
$fluffless_saved  = get_option( Lifecycle::OPTION_LICENCE, null );
$fluffless_editor = 0;

try {
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence, false );
	$fluffless_reset();

	// v6-09. Clé révoquée : un état à part, jamais « clé non reconnue ».
	$fluffless_reply = array(
		'code' => 403,
		'body' => array( 'code' => 'licence_revoked' ),
	);
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( 'licence_revoked' === Updates::licence_state(), 'révoquée (update) : état ' . Updates::licence_state() );
	$fluffless_assert( str_contains( Updates::status(), 'revoked' ) && ! str_contains( Updates::status(), 'not recognized' ), 'révoquée : message ' . Updates::status() );
	$fluffless_assert( ! Updates::needs_licence(), 'révoquée : la Suite demande une autre clé' );
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 200,
		'body' => array(
			'valid'      => false,
			'code'       => 'licence_revoked',
			'plan'       => null,
			'expires'    => null,
			'sites_used' => 0,
			'sites_max'  => 0,
		),
	);
	Updates::verify_licence();
	$fluffless_assert( 'licence_revoked' === Updates::licence_state(), 'révoquée (verify) : état ' . Updates::licence_state() );

	// v6-08. Illimitée : aucun plafond affiché ; à son plafond interne, une vérification, pas « plus de sites ».
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 403,
		'body' => array( 'code' => 'licence_review' ),
	);
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( 'licence_review' === Updates::licence_state(), 'vérification : état ' . Updates::licence_state() );
	$fluffless_assert( str_contains( Updates::status(), 'quick check' ) && ! str_contains( Updates::status(), 'upgrade' ), 'vérification : message ' . Updates::status() );
	$fluffless_assert( ! Updates::needs_licence(), 'vérification : la Suite demande une autre clé' );
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 200,
		'body' => array(
			'valid'      => true,
			'plan'       => 'illimitee',
			'expires'    => '2099-01-01T00:00:00.000Z',
			'sites_used' => 312,
			'sites_max'  => null,
			'unlimited'  => true,
		),
	);
	Updates::verify_licence();
	$fluffless_html = $fluffless_card();
	$fluffless_assert( str_contains( $fluffless_html, '312 active sites' ), 'illimitée : ligne d\'état absente' );
	$fluffless_assert( ! str_contains( $fluffless_html, '10000' ) && ! str_contains( $fluffless_html, '10 000' ) && ! str_contains( $fluffless_html, '10,000' ) && 1 !== preg_match( '/\d+ of \d+/', $fluffless_html ), 'illimitée : un maximum est affiché' );
	// Une réponse à jour de /v1/update garde la ligne d'état apprise par verify.
	$fluffless_reply = array( 'code' => 204 );
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( str_contains( $fluffless_card(), '312 active sites' ), 'illimitée : ligne d\'état perdue après /v1/update' );
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 200,
		'body' => array(
			'valid'      => true,
			'plan'       => 'cinq',
			'expires'    => '2099-01-01T00:00:00.000Z',
			'sites_used' => 3,
			'sites_max'  => 5,
		),
	);
	Updates::verify_licence();
	$fluffless_assert( str_contains( $fluffless_card(), '3 of 5 sites' ), '5 sites : ligne d\'état absente' );

	// v6-03. Grâce de 30 jours : date de fin, date limite et lien signé ; après la grâce, l'avis habituel.
	$fluffless_end   = time() - 10 * DAY_IN_SECONDS;
	$fluffless_grace = $fluffless_end + 30 * DAY_IN_SECONDS;
	$fluffless_renew = \Fluffless\Suite\Core\Api::base() . '/v1/licences/renew?licence=7&until=' . $fluffless_end . '&token=' . str_repeat( 'a', 64 );
	$fluffless_iso   = static fn( int $at ): string => gmdate( 'Y-m-d\TH:i:s.000\Z', $at );
	$fluffless_date  = static fn( int $at ): string => (string) wp_date( (string) get_option( 'date_format' ), $at );
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 403,
		'body' => array(
			'code'        => 'licence_expired',
			'expires'     => $fluffless_iso( $fluffless_end ),
			'grace_until' => $fluffless_iso( $fluffless_grace ),
			'renew_url'   => $fluffless_renew,
		),
	);
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_status = Updates::status();
	$fluffless_assert( str_contains( $fluffless_status, 'ended on ' . $fluffless_date( $fluffless_end ) ) && str_contains( $fluffless_status, 'Renew by ' . $fluffless_date( $fluffless_grace ) . ' to keep your price' ), 'grâce : message ' . $fluffless_status );
	$fluffless_html = $fluffless_card();
	$fluffless_assert( str_contains( $fluffless_html, 'href="' . esc_url( $fluffless_renew ) . '"' ) && str_contains( $fluffless_html, 'Renew my licence' ), 'grâce : lien de renouvellement absent de la carte' );
	$fluffless_assert( Updates::needs_licence(), 'grâce : licence échue, la clé reste à renouveler' );
	// Un lien hors de l'API est ignoré : l'avis habituel reste.
	$fluffless_reset();
	$fluffless_reply['body']['renew_url'] = 'https://ailleurs.example/v1/licences/renew?licence=7';
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( ! str_contains( Updates::status(), 'Renew by' ) && ! str_contains( $fluffless_card(), 'ailleurs.example' ), 'grâce : lien étranger accepté' );
	// Après la grâce : le code seul, l'avis habituel, le lien vers la page des prix (P4-06), aucun lien signé.
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 403,
		'body' => array( 'code' => 'licence_expired' ),
	);
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( str_contains( Updates::status(), 'has expired' ) && str_contains( $fluffless_card(), 'href="https://fluffless.dev/en/pricing/"' ) && ! str_contains( $fluffless_card(), '/v1/licences/renew' ), 'après la grâce : avis ' . Updates::status() );
	// verify porte les mêmes champs.
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 200,
		'body' => array(
			'valid'       => false,
			'code'        => 'licence_expired',
			'plan'        => 'solo',
			'expires'     => $fluffless_iso( $fluffless_end ),
			'sites_used'  => 1,
			'sites_max'   => 1,
			'grace_until' => $fluffless_iso( $fluffless_grace ),
			'renew_url'   => $fluffless_renew,
		),
	);
	Updates::verify_licence();
	$fluffless_assert( str_contains( Updates::status(), 'Renew by ' . $fluffless_date( $fluffless_grace ) ), 'grâce (verify) : message ' . Updates::status() );

	// v6-06. Avis écrit en cours (notice_until) : visible pour l'administration seulement, retiré quand l'API ne le porte plus.
	$fluffless_until  = time() + 20 * DAY_IN_SECONDS;
	$fluffless_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$fluffless_ed_id  = wp_insert_user(
		array(
			'user_login' => 'fl-editeur-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => 'editor',
		)
	);
	if ( is_wp_error( $fluffless_ed_id ) ) {
		throw new RuntimeException( esc_html( 'Utilisateur Éditeur de test non créé : ' . $fluffless_ed_id->get_error_message() ) );
	}
	$fluffless_editor = $fluffless_ed_id;
	$fluffless_reset();
	$fluffless_reply = array(
		'code'    => 204,
		'headers' => array( 'x-fluffless-notice-until' => $fluffless_iso( $fluffless_until ) ),
	);
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
	$fluffless_assert( 'valid' === Updates::licence_state() && str_contains( Updates::usage_notice(), 'answer before ' . $fluffless_date( $fluffless_until ) ), 'avis (204) : message ' . Updates::usage_notice() );
	$fluffless_assert( str_contains( $fluffless_card(), 'answer before' ), 'avis : absent de la carte' );
	wp_set_current_user( $fluffless_editor );
	$fluffless_assert( '' === Updates::usage_notice(), 'avis : visible pour un rôle Éditeur' );
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
	$fluffless_reply = array( 'code' => 204 );
	delete_transient( Lifecycle::TRANSIENT_UPDATES );
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( '' === Updates::usage_notice(), 'avis : resté après une réponse sans notice_until' );
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 200,
		'body' => array(
			'valid'        => true,
			'plan'         => 'solo',
			'expires'      => '2099-01-01T00:00:00.000Z',
			'sites_used'   => 1,
			'sites_max'    => 1,
			'notice_until' => $fluffless_iso( $fluffless_until ),
		),
	);
	Updates::verify_licence();
	$fluffless_assert( str_contains( Updates::usage_notice(), 'answer before' ), 'avis (verify) : absent' );

	// P6c-06. Sites de développement : hôte local ou préproduction déclarée (décision 34) ; aucune place prise.
	$fluffless_api = \Fluffless\Suite\Core\Api::class;
	foreach ( array( 'localhost', 'LOCALHOST', 'localhost.', '[localhost]', 'a.localhost', 'client.local', 'x.y.test', '127.0.0.1', '[::1]' ) as $fluffless_host ) {
		$fluffless_assert( $fluffless_api::is_dev_host( $fluffless_host ), 'hôte local refusé : ' . $fluffless_host );
	}
	foreach ( array( 'local', 'test', 'monsite.local.ca', 'test.ca', 'latest', 'contest.com', 'localhost.ca', '192.168.1.10', 'intranet', 'site.lan', 'site.example', 'xn--caf-dma.ca', '' ) as $fluffless_host ) {
		$fluffless_assert( ! $fluffless_api::is_dev_host( $fluffless_host ), 'hôte de production pris pour local : ' . $fluffless_host );
	}
	$fluffless_assert( $fluffless_api::site_is_dev( 'preprod.client.ca', 'staging' ) && $fluffless_api::site_is_dev( 'client.ca', 'development' ) && $fluffless_api::site_is_dev( 'client.ca', 'local' ), 'préproduction déclarée : pas un site de développement' );
	$fluffless_assert( $fluffless_api::site_is_dev( 'client.local', 'production' ) && $fluffless_api::site_is_dev( '127.0.0.1', 'production' ) && $fluffless_api::site_is_dev( '[::1]', 'production' ), 'hôte local en production : pas un site de développement' );
	$fluffless_assert( ! $fluffless_api::site_is_dev( 'client.ca', 'production' ) && ! $fluffless_api::site_is_dev( '192.168.1.10', 'production' ), 'production prise pour un site de développement' );

	// Les deux appels qui inscrivent le site envoient le drapeau, et seulement sur un site de développement.
	$fluffless_home = static fn(): string => 'https://client.local:8882/sous-dossier';
	add_filter( 'home_url', $fluffless_home, 99 );
	$fluffless_reset();
	$fluffless_reply = array( 'code' => 204 );
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( str_contains( $fluffless_calls[0]['url'] ?? '', 'dev=1' ), 'local : /v1/update sans dev=1' );
	$fluffless_assert( ! str_contains( $fluffless_calls[0]['url'] ?? '', 'client.local' ), 'local : l\'hôte en clair est parti' );
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 200,
		'body' => array(
			'valid'      => true,
			'plan'       => 'solo',
			'expires'    => '2099-01-01T00:00:00.000Z',
			'sites_used' => 0,
			'sites_max'  => 1,
			'dev'        => true,
		),
	);
	Updates::verify_licence();
	$fluffless_sent = json_decode( (string) ( $fluffless_calls[0]['args']['body'] ?? '' ), true );
	$fluffless_assert( is_array( $fluffless_sent ) && true === ( $fluffless_sent['dev'] ?? null ), 'local : verify sans dev:true' );
	$fluffless_assert( str_contains( $fluffless_card(), 'Development site' ), 'local : pastille absente' );
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 403,
		'body' => array( 'code' => 'dev_limit' ),
	);
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( 'dev_limit' === Updates::licence_state() && str_contains( Updates::status(), 'development sites' ) && ! Updates::needs_licence(), 'dev_limit : état ou message ' . Updates::status() );
	remove_filter( 'home_url', $fluffless_home, 99 );
	$fluffless_home = static fn(): string => 'https://client.ca';
	add_filter( 'home_url', $fluffless_home, 99 );
	$fluffless_reset();
	$fluffless_reply = array( 'code' => 204 );
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( ! str_contains( $fluffless_calls[0]['url'] ?? '', 'dev=' ), 'production : dev envoyé' );
	Updates::verify_licence();
	$fluffless_sent = json_decode( (string) ( $fluffless_calls[1]['args']['body'] ?? '' ), true );
	$fluffless_assert( is_array( $fluffless_sent ) && ! array_key_exists( 'dev', $fluffless_sent ), 'production : verify porte dev' );
	remove_filter( 'home_url', $fluffless_home, 99 );
	// P6c-06 : libérer une place depuis la carte (ce site, ou une autre adresse dont seule l'empreinte part).
	remove_filter( 'home_url', $fluffless_home, 99 );
	$fluffless_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 1 ) );
	$fluffless_release_card = static function (): string {
		ob_start();
		Updates::render_licence();
		Updates::render_release_form();
		return (string) ob_get_clean();
	};
	$fluffless_html         = $fluffless_release_card();
	$fluffless_assert( str_contains( $fluffless_html, 'Free this site&#039;s seat' ) && str_contains( $fluffless_html, 'Free the seat of another address' ), 'libération : bouton ou champ absent de la carte' );
	$fluffless_assert( str_contains( $fluffless_html, 'admin-post.php' ) && str_contains( $fluffless_html, 'fluffless_release_seat' ) && str_contains( $fluffless_html, 'form="fl-release"' ), 'libération : formulaire séparé absent' );
	// Ce site : succès, la clé enregistrée est retirée, seule l'empreinte part.
	$fluffless_reset();
	$fluffless_reply  = array(
		'code' => 200,
		'body' => array(
			'released'   => true,
			'sites_used' => 0,
			'sites_max'  => 1,
		),
	);
	$fluffless_result = Updates::release_seat( '' );
	$fluffless_sent   = json_decode( (string) ( $fluffless_calls[0]['args']['body'] ?? '' ), true );
	$fluffless_assert( str_ends_with( $fluffless_calls[0]['url'] ?? '', '/v1/licences/release' ) && array( 'site' => Fluffless\Suite\Core\Api::site_hash() ) === $fluffless_sent, 'ce site : requête ou corps inattendu' );
	$fluffless_assert( ( $fluffless_calls[0]['args']['headers']['X-Fluffless-Licence'] ?? '' ) === $fluffless_licence, 'ce site : clé absente de l\'en-tête' );
	$fluffless_assert( 'good' === $fluffless_result['status'] && str_contains( $fluffless_result['message'], 'no longer uses a seat' ), 'ce site : message ' . $fluffless_result['message'] );
	$fluffless_assert( '' === get_option( Lifecycle::OPTION_LICENCE, '' ) && '' === Updates::licence(), 'ce site : clé gardée' );
	$fluffless_assert( 1 === count( $fluffless_calls ), 'ce site : la libération a été suivie d\'une autre requête' );
	// Sans clé : aucun bouton.
	$fluffless_assert( ! str_contains( $fluffless_release_card(), 'Free this site' ), 'sans clé : bouton affiché' );
	// Autre adresse : empreinte de l'adresse normalisée, l'adresse ne part pas, la clé reste.
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence, false );
	$fluffless_reset();
	$fluffless_result = Updates::release_seat( 'https://Ancien.Exemple.ca:443/blogue/' );
	$fluffless_sent   = json_decode( (string) ( $fluffless_calls[0]['args']['body'] ?? '' ), true );
	$fluffless_assert( array( 'site' => hash( 'sha256', 'ancien.exemple.ca/blogue' ) ) === $fluffless_sent, 'autre adresse : empreinte inattendue' );
	$fluffless_assert( ! str_contains( strtolower( (string) wp_json_encode( $fluffless_calls ) ), 'exemple' ), 'autre adresse : l\'adresse quitte le site' );
	$fluffless_assert( 'good' === $fluffless_result['status'] && Updates::licence() === $fluffless_licence, 'autre adresse : statut ou clé retirée' );
	// Autre adresse sans place sur cette licence.
	$fluffless_reset();
	$fluffless_reply['body']['released'] = false;
	$fluffless_result                    = Updates::release_seat( 'https://ancien.exemple.ca' );
	$fluffless_assert( 'warn' === $fluffless_result['status'], 'autre adresse sans place : statut ' . $fluffless_result['status'] );
	// Adresse invalide : rien ne part.
	$fluffless_reset();
	$fluffless_result = Updates::release_seat( 'pas une adresse' );
	$fluffless_assert( array() === $fluffless_calls && 'bad' === $fluffless_result['status'], 'adresse invalide : requête envoyée' );
	// Plafond : 429 release_limit, date de reprise.
	$fluffless_reset();
	$fluffless_reply  = array(
		'code' => 429,
		'body' => array(
			'code'     => 'release_limit',
			'retry_at' => '2099-03-04T12:00:00.000Z',
		),
	);
	$fluffless_result = Updates::release_seat( '' );
	$fluffless_assert( str_contains( $fluffless_result['message'], 'in the last 30 days' ) && str_contains( $fluffless_result['message'], $fluffless_date( (int) strtotime( '2099-03-04T12:00:00Z' ) ) ), 'plafond : message ' . $fluffless_result['message'] );
	$fluffless_assert( Updates::licence() === $fluffless_licence, 'plafond : clé retirée' );
	// Route absente (API sans libération) : ni « licence invalide », ni clé retirée.
	foreach ( array( '', 'https://ancien.exemple.ca' ) as $fluffless_address ) {
		$fluffless_reset();
		$fluffless_reply  = array(
			'code' => 404,
			'body' => array( 'code' => 'not_found' ),
		);
		$fluffless_result = Updates::release_seat( $fluffless_address );
		$fluffless_assert( 'warn' === $fluffless_result['status'] && str_contains( $fluffless_result['message'], 'not available' ) && ! str_contains( $fluffless_result['message'], 'not recognized' ), 'route absente : message ' . $fluffless_result['message'] );
		$fluffless_assert( Updates::licence() === $fluffless_licence, 'route absente : clé retirée' );
	}
	// Échec réseau : la clé de ce site est retirée quand même.
	$fluffless_reset();
	$fluffless_reply  = array( 'code' => 0 );
	$fluffless_result = Updates::release_seat( '' );
	$fluffless_assert( str_contains( $fluffless_result['message'], "didn't answer" ) && str_contains( $fluffless_result['message'], 'removed anyway' ) && '' === Updates::licence(), 'réseau : message ou clé ' . $fluffless_result['message'] );
	// Gestion du formulaire : droits, jeton, puis avis retenu et redirection.
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence, false );
	$fluffless_die      = static function () {
		return static function ( $message ): void {
			throw new RuntimeException( esc_html( 'wp_die: ' . ( is_string( $message ) ? $message : 'objet' ) ) );
		};
	};
	$fluffless_redirect = static function ( $location ) {
		if ( is_string( $location ) ) {
			throw new RuntimeException( esc_html( 'redirect: ' . $location ) );
		}
		return $location;
	};
	add_filter( 'wp_die_handler', $fluffless_die, 99 );
	add_filter( 'wp_redirect', $fluffless_redirect, 99 );
	$fluffless_admin_id = get_current_user_id();
	foreach ( array( 'editor', 'nonce' ) as $fluffless_case ) {
		$fluffless_reset();
		wp_set_current_user( 'editor' === $fluffless_case ? $fluffless_editor : $fluffless_admin_id );
		$_POST         = array( 'fluffless_release_nonce' => 'editor' === $fluffless_case ? wp_create_nonce( 'fluffless_release_seat' ) : 'mauvais' );
		$_REQUEST      = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- requête simulée.
		$fluffless_out = '';
		try {
			Updates::handle_release();
		} catch ( RuntimeException $fluffless_e ) {
			$fluffless_out = $fluffless_e->getMessage();
		}
		$fluffless_assert( str_starts_with( $fluffless_out, 'wp_die' ) && array() === $fluffless_calls && Updates::licence() === $fluffless_licence, "formulaire ($fluffless_case) : refus attendu, obtenu « $fluffless_out »" );
	}
	wp_set_current_user( $fluffless_admin_id );
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 200,
		'body' => array(
			'released'   => true,
			'sites_used' => 0,
			'sites_max'  => 1,
		),
	);
	$_POST           = array(
		'fluffless_release_nonce' => wp_create_nonce( 'fluffless_release_seat' ),
		'fluffless_release_url'   => 'https://ancien.exemple.ca/',
		'fluffless_release'       => 'other',
	);
	$_REQUEST        = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- requête simulée.
	$fluffless_out   = '';
	try {
		Updates::handle_release();
	} catch ( RuntimeException $fluffless_e ) {
		$fluffless_out = $fluffless_e->getMessage();
	}
	$fluffless_assert( str_starts_with( $fluffless_out, 'redirect: ' ) && 1 === count( $fluffless_calls ), 'formulaire valide : ' . $fluffless_out );
	$fluffless_assert( str_contains( $fluffless_release_card(), 'is free' ), 'formulaire valide : avis absent de la carte' );
	$fluffless_assert( ! str_contains( $fluffless_release_card(), 'is free' ), 'formulaire valide : avis réaffiché' );
	remove_filter( 'wp_die_handler', $fluffless_die, 99 );
	remove_filter( 'wp_redirect', $fluffless_redirect, 99 );
	$_POST    = array();
	$_REQUEST = array();
	// F-licence-places §6 : l'avis site_limit pointe vers la libération dans Fluffless → Modules.
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 403,
		'body' => array( 'code' => 'site_limit' ),
	);
	$fluffless_module->plugin_update( false, array(), $fluffless_plugin, array() );
	$fluffless_assert( 'site_limit' === Updates::licence_state() && str_contains( Updates::status(), 'Free the seat of a site you no longer manage in Fluffless → Modules' ), 'site_limit : message ' . Updates::status() );
	// F-licence-places §6 : la mention Loi 25 nomme le nouveau champ envoyé (site de développement).
	$fluffless_assert( str_contains( $fluffless_release_card(), 'and whether it is a development site' ), 'Loi 25 : champ dev absent de la mention' );
	// F-licence-places §2.3 : crochets séparés. Retrait ou changement vers une clé valide : l'ancienne place se libère.
	$fluffless_paths    = static function () use ( &$fluffless_calls ): array {
		return array_map(
			static function ( array $call ): string {
				$path = (string) wp_parse_url( $call['url'], PHP_URL_PATH );
				return $path . ' ' . ( $call['args']['headers']['X-Fluffless-Licence'] ?? '' );
			},
			$fluffless_calls
		);
	};
	$fluffless_licence2 = 'FL-TEST-' . wp_generate_password( 16, false );
	$fluffless_valid    = array(
		'code' => 200,
		'body' => array(
			'valid'      => true,
			'plan'       => 'solo',
			'expires'    => '2099-01-01T00:00:00.000Z',
			'sites_used' => 1,
			'sites_max'  => 1,
			'released'   => true,
		),
	);
	// Première clé (add_option) : rien à libérer.
	delete_option( Lifecycle::OPTION_LICENCE );
	$fluffless_reset();
	$fluffless_reply = $fluffless_valid;
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence, false );
	$fluffless_assert( array( '/v1/licences/verify ' . $fluffless_licence ) === $fluffless_paths(), 'add_option : ' . implode( ', ', $fluffless_paths() ) );
	// Nouvelle clé valide : vérifiée d'abord, puis l'ancienne libère sa place.
	$fluffless_reset();
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence2, false );
	$fluffless_assert( array( '/v1/licences/verify ' . $fluffless_licence2, '/v1/licences/release ' . $fluffless_licence ) === $fluffless_paths(), 'changement valide : ' . implode( ', ', $fluffless_paths() ) );
	$fluffless_sent = json_decode( (string) ( $fluffless_calls[1]['args']['body'] ?? '' ), true );
	$fluffless_assert( array( 'site' => Fluffless\Suite\Core\Api::site_hash() ) === $fluffless_sent, 'changement valide : corps de release' );
	// Nouvelle clé refusée : rien n'est libéré, l'ancienne clé peut revenir sans perdre sa place.
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 200,
		'body' => array( 'valid' => false ),
	);
	update_option( Lifecycle::OPTION_LICENCE, 'FL-TEST-mauvaise-cle', false );
	$fluffless_assert( array( '/v1/licences/verify FL-TEST-mauvaise-cle' ) === $fluffless_paths(), 'changement refusé : ' . implode( ', ', $fluffless_paths() ) );
	// Retrait : l'ancienne clé libère la place de ce site.
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence, false );
	$fluffless_reset();
	$fluffless_reply = $fluffless_valid;
	update_option( Lifecycle::OPTION_LICENCE, '', false );
	$fluffless_assert( array( '/v1/licences/release ' . $fluffless_licence ) === $fluffless_paths(), 'retrait : ' . implode( ', ', $fluffless_paths() ) );
	// Retrait sans réponse de l'API : la clé part quand même, avec un avis.
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence, false );
	$fluffless_reset();
	$fluffless_reply = array( 'code' => 0 );
	update_option( Lifecycle::OPTION_LICENCE, '', false );
	$fluffless_assert( '' === Updates::licence() && str_contains( $fluffless_release_card(), 'be freed: the update server' ), 'retrait sans réponse : clé ou avis' );
	// Retrait quand l'API n'a pas la route de libération : un avis le dit, la place reste prise.
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence, false );
	$fluffless_reset();
	$fluffless_reply = array(
		'code' => 404,
		'body' => array( 'code' => 'not_found' ),
	);
	update_option( Lifecycle::OPTION_LICENCE, '', false );
	$fluffless_card_now = $fluffless_release_card();
	$fluffless_assert( str_contains( $fluffless_card_now, 'not available' ) && str_contains( $fluffless_card_now, 'The key was removed' ), 'retrait, route absente : avis absent' );
	// Remplacement par une clé valide quand l'API n'a pas la route : la nouvelle clé reste, avec l'avis.
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence, false );
	$fluffless_reset();
	$fluffless_reply = $fluffless_valid + array(
		'release' => array(
			'code' => 404,
			'body' => array( 'code' => 'not_found' ),
		),
	);
	update_option( Lifecycle::OPTION_LICENCE, $fluffless_licence2, false );
	$fluffless_card_now = $fluffless_release_card();
	$fluffless_assert( Updates::licence() === $fluffless_licence2 && str_contains( $fluffless_card_now, 'not available' ) && ! str_contains( $fluffless_card_now, 'The key was removed' ), 'remplacement, route absente : clé ou avis' );
} finally {
	wp_set_current_user( 0 );
	if ( $fluffless_editor > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $fluffless_editor );
	}
	if ( null === $fluffless_saved ) {
		delete_option( Lifecycle::OPTION_LICENCE );
	} else {
		update_option( Lifecycle::OPTION_LICENCE, $fluffless_saved, false );
	}
	remove_filter( 'pre_http_request', $fluffless_mock, 1 );
	delete_transient( Lifecycle::TRANSIENT_UPDATES );
	delete_transient( Lifecycle::TRANSIENT_LICENCE );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Places et états de licence, {$fluffless_checks} vérifications réussies.\n" );
