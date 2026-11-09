<?php
/**
 * Tests des échecs du module Mises à jour : absence de version publiée, panne réseau et paquet à
 * adresse non https se distinguent, et un échec d'installation dit quoi faire.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/updates-echecs-test.php
 * Les réponses HTTP sont simulées (aucune requête réelle) ; la licence et les transitoires sont remis en état.
 *
 * @package Fluffless_Suite
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Modules\Updates;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Updates::class ) ) {
	throw new RuntimeException( 'Module Mises à jour absent.' );
}

switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_pair = sodium_crypto_sign_keypair();
/**
 * Réponse simulée par défaut de l'API : à jour. Donne aussi la forme des réponses des étapes suivantes.
 *
 * @return array{code?: int, body?: mixed, error?: bool}
 */
function fluffless_test_echecs_reply(): array {
	return array( 'code' => 204 );
}
// Réponse simulée de l'API, remplacée à chaque étape.
$fluffless_reply   = 'fluffless_test_echecs_reply';
$fluffless_mock    = static function ( $pre, $args, $url ) use ( &$fluffless_reply ) {
	unset( $pre, $args );
	if ( str_contains( $url, '/dl/' ) ) {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	$reply = $fluffless_reply();
	if ( isset( $reply['error'] ) ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
	}
	return array(
		'headers'  => array(),
		'body'     => isset( $reply['body'] ) ? (string) wp_json_encode( $reply['body'] ) : '',
		'response' => array(
			'code'    => $reply['code'] ?? 500,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
$fluffless_reset   = static function (): void {
	delete_transient( Lifecycle::TRANSIENT_UPDATES );
	delete_transient( Lifecycle::TRANSIENT_LICENCE );
};
$fluffless_outcome = static fn( string $slug ): string => Updates::outcome( $slug );

add_filter( 'pre_http_request', $fluffless_mock, 1, 3 );

try {
	if ( defined( 'FLUFFLESS_SIGNING_PUBLIC_KEY' ) ) {
		throw new RuntimeException( 'Test impossible : une clé publique est déjà définie.' );
	}
	define( 'FLUFFLESS_SIGNING_PUBLIC_KEY', base64_encode( sodium_crypto_sign_publickey( $fluffless_pair ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format des clés de WordPress.
	update_option( Lifecycle::OPTION_LICENCE, 'FL-TEST-' . wp_generate_password( 16, false ), false );
	$fluffless_reset();
	$fluffless_slug = 'fluffless-suite';

	// C-S06-04 : aucune version publiée (404 unknown_slug) n'est pas une panne réseau.
	$fluffless_reply = static fn(): array => array(
		'code' => 404,
		'body' => array( 'code' => 'unknown_slug' ),
	);
	Updates::check( $fluffless_slug, true );
	$fluffless_assert( 'unpublished' === $fluffless_outcome( $fluffless_slug ), 'C-S06-04 : 404 unknown_slug devrait donner « unpublished », reçu : ' . $fluffless_outcome( $fluffless_slug ) );
	$fluffless_row = Updates::check_all()[ $fluffless_slug ] ?? array();
	$fluffless_assert( 'unpublished' === ( $fluffless_row['outcome'] ?? '' ), 'C-S06-04 : check_all ne rend pas le résultat' );

	$fluffless_reply = static fn(): array => array( 'error' => true );
	Updates::check( $fluffless_slug, true );
	$fluffless_assert( 'unreachable' === $fluffless_outcome( $fluffless_slug ), 'C-S06-04 : une panne réseau devrait donner « unreachable », reçu : ' . $fluffless_outcome( $fluffless_slug ) );
	$fluffless_reply = static fn(): array => array( 'code' => 503 );
	Updates::check( $fluffless_slug, true );
	$fluffless_assert( 'unreachable' === $fluffless_outcome( $fluffless_slug ), 'C-S06-04 : un 503 devrait donner « unreachable »' );

	// 401, 403 et 429 ne sont pas « serveur injoignable » : le serveur a répondu.
	foreach ( array(
		401 => 'unauthorized',
		403 => 'unauthorized',
		429 => 'rate_limited',
	) as $fluffless_http => $fluffless_expected ) {
		$fluffless_reply = static fn(): array => array(
			'code' => $fluffless_http,
			'body' => array( 'code' => 403 === $fluffless_http ? 'licence_invalid' : 'rate_limited' ),
		);
		Updates::check( $fluffless_slug, true );
		$fluffless_assert( $fluffless_expected === $fluffless_outcome( $fluffless_slug ), 'Un ' . $fluffless_http . ' devrait donner « ' . $fluffless_expected . ' », reçu : ' . $fluffless_outcome( $fluffless_slug ) );
	}
	$fluffless_assert( str_contains( Updates::outcome_message( 'unauthorized' ), 'licence key' ) && str_contains( Updates::outcome_message( 'rate_limited' ), 'try again in a few minutes' ), '401/403/429 : messages distincts, avec l’action utile' );
	$fluffless_assert( Updates::outcome_message( 'unauthorized' ) !== Updates::outcome_message( 'unreachable' ), '401/403 : pas le message de panne' );
	// Un 429 ne remplace pas un « à jour » déjà connu par « aucune mise à jour » : le dernier résultat reste.
	$fluffless_reply = static fn(): array => array( 'code' => 204 );
	Updates::check( $fluffless_slug, true );
	$fluffless_reply = static fn(): array => array( 'code' => 429 );
	$fluffless_kept  = Updates::check( $fluffless_slug, true );
	$fluffless_assert( is_array( $fluffless_kept ) && '' === $fluffless_kept['package'], '429 : le dernier résultat (à jour) est gardé, pas effacé.' );

	$fluffless_reply = static fn(): array => array( 'code' => 204 );
	Updates::check( $fluffless_slug, true );
	$fluffless_assert( 'current' === $fluffless_outcome( $fluffless_slug ), 'C-S06-04 : 204 devrait donner « current », reçu : ' . $fluffless_outcome( $fluffless_slug ) );

	// Les trois cas disent des choses différentes.
	$fluffless_say = array();
	foreach ( array( 'unpublished', 'unreachable', 'invalid_package' ) as $fluffless_o ) {
		$fluffless_say[ $fluffless_o ] = Updates::outcome_message( $fluffless_o );
	}
	$fluffless_assert( 3 === count( array_unique( $fluffless_say ) ) && ! in_array( '', $fluffless_say, true ), 'C-S06-04/05 : les trois messages ne sont pas distincts et non vides' );
	$fluffless_assert( str_contains( $fluffless_say['unpublished'], 'no published version' ), 'C-S06-04 : message « aucune version publiée » absent' );
	$fluffless_assert( str_contains( $fluffless_say['unreachable'], 'try again' ), 'C-S06-04 : message de panne sans action' );

	// C-S06-05 : un paquet à adresse non https est signalé, pas confondu avec une panne.
	$fluffless_zipsha = hash( 'sha256', 'x' );
	$fluffless_reply  = static fn(): array => array(
		'code' => 200,
		'body' => array(
			'slug'    => 'fluffless-suite',
			'version' => '9.9.0',
			'package' => 'http://example.com/fluffless-suite.zip',
			'sha256'  => hash( 'sha256', 'x' ),
		),
	);
	Updates::check( $fluffless_slug, true );
	$fluffless_assert( 'invalid_package' === $fluffless_outcome( $fluffless_slug ), 'C-S06-05 : paquet http devrait donner « invalid_package », reçu : ' . $fluffless_outcome( $fluffless_slug ) );
	$fluffless_assert( str_contains( $fluffless_say['invalid_package'], 'https' ), 'C-S06-05 : message sans mention de https' );

	// C-S06-06 : un échec d'installation dit quoi faire, sans détail brut.
	$fluffless_package = 'https://api.fluffless.dev/dl/tmp-abc/fluffless-suite.zip';
	$fluffless_hint    = static function ( $error, string $label ) use ( $fluffless_assert ): void {
		$fluffless_assert( $error instanceof WP_Error, 'C-S06-06 : erreur attendue (' . $label . ')' );
		if ( ! $error instanceof WP_Error ) {
			return;
		}
		$message = $error->get_error_message();
		$data    = $error->get_error_data();
		$fluffless_assert( str_contains( $message, 'licence' ) && str_contains( $message, 'Try again' ), 'C-S06-06 : ' . $label . ' sans action utile : ' . $message );
		$fluffless_assert( ! is_string( $data ) || 1 !== preg_match( '/^(HTTP \d+|[\d.]*)$/', $data ), 'C-S06-06 : ' . $label . ' montre un détail brut : ' . ( is_string( $data ) ? $data : '' ) );
	};
	$fluffless_body    = array(
		'slug'    => 'fluffless-suite',
		'version' => '9.9.0',
		'package' => $fluffless_package,
		'sha256'  => $fluffless_zipsha,
	);
	// Sans manifeste signé : refus avant tout téléchargement.
	$fluffless_reply = static fn(): array => array(
		'code' => 200,
		'body' => $fluffless_body,
	);
	Updates::check( $fluffless_slug, true );
	$fluffless_hint( Updates::pre_download( false, $fluffless_package, null, array() ), 'manifeste' );
	// Manifeste signé, mais le paquet servi n'a pas de signature : le détail brut « HTTP 200 » ne doit pas sortir seul.
	$fluffless_manifest = (string) wp_json_encode(
		array(
			'slug'    => 'fluffless-suite',
			'version' => '9.9.0',
			'sha256'  => $fluffless_zipsha,
		)
	);
	$fluffless_body    += array(
		'manifest'           => $fluffless_manifest,
		'manifest_signature' => base64_encode( sodium_crypto_sign_detached( $fluffless_manifest, sodium_crypto_sign_secretkey( $fluffless_pair ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format de signature.
	);
	$fluffless_reply    = static fn(): array => array(
		'code' => 200,
		'body' => $fluffless_body,
	);
	$fluffless_reset();
	Updates::check( $fluffless_slug, true );
	$fluffless_hint( Updates::pre_download( false, $fluffless_package, null, array() ), 'signature' );
} finally {
	remove_filter( 'pre_http_request', $fluffless_mock, 1 );
	delete_option( Lifecycle::OPTION_LICENCE );
	$fluffless_reset();
	Lifecycle::delete_prefixed_transients();
	restore_previous_locale();
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		echo esc_html( 'ÉCHEC : ' . $fluffless_failure ) . "\n";
	}
	exit( 1 );
}
echo esc_html( sprintf( 'Échecs de mise à jour : %d vérifications réussies.', $fluffless_checks ) ) . "\n";
