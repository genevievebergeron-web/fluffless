<?php
/**
 * Tests de « Report a problem » et du diagnostic, avec réponses HTTP simulées.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/report-test.php
 *
 * @package Fluffless_Suite
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Suite\Admin\Report;
use Fluffless\Suite\Core\Diagnostic;
use Fluffless\Suite\Core\Lifecycle;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Report::class ) ) {
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

// Modifiés par référence dans la fermeture et par les étapes du test.
/**
 * Requêtes interceptées.
 *
 * @var list<array{url: string, body: mixed}> $fluffless_calls
 */
$fluffless_calls = array();
/**
 * Code HTTP simulé ; 0 simule une panne réseau.
 *
 * @var int $fluffless_code
 */
$fluffless_code  = 201;
$fluffless_mock  = static function ( $pre, $args, $url ) use ( &$fluffless_calls, &$fluffless_code ) {
	$fluffless_calls[] = array(
		'url'  => $url,
		'body' => json_decode( (string) ( $args['body'] ?? '' ), true ),
	);
	if ( 0 === $fluffless_code ) {
		return new WP_Error( 'http_request_failed', 'cURL error 6: could not resolve host' );
	}
	return array(
		'headers'  => array(),
		'body'     => 201 === $fluffless_code ? '{"id":"r_42"}' : '{}',
		'response' => array(
			'code'    => $fluffless_code,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
$fluffless_input = array(
	'type'     => 'bug',
	'what'     => 'Le bouton « Enregistrer » disparaît.',
	'expected' => 'Qu’il reste visible.',
	'email'    => 'personne@example.com',
);

add_filter( 'pre_http_request', $fluffless_mock, 1, 3 );
delete_transient( Lifecycle::TRANSIENT_REPORTS );

try {
	// 1. Diagnostic : les champs du contrat, aucune donnée personnelle ni adresse du site.
	$fluffless_diag = Diagnostic::collect();
	foreach ( array( 'wp', 'php', 'theme', 'theme_version', 'tools_version', 'modules', 'multisite', 'locale' ) as $fluffless_key ) {
		$fluffless_assert( array_key_exists( $fluffless_key, $fluffless_diag ), "diagnostic : champ {$fluffless_key} absent" );
	}
	$fluffless_all  = (string) wp_json_encode( $fluffless_diag ) . Diagnostic::text();
	$fluffless_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$fluffless_user = get_userdata( 1 );
	$fluffless_assert( ! str_contains( $fluffless_all, $fluffless_host ) && ! str_contains( $fluffless_all, (string) get_option( 'admin_email' ) ) && ( false === $fluffless_user || ! str_contains( $fluffless_all, $fluffless_user->user_login ) ), 'diagnostic : donnée personnelle ou domaine en clair' );
	$fluffless_assert( str_contains( Diagnostic::text(), \Fluffless\Suite\Core\Api::site_hash() ), 'diagnostic : empreinte du site absente du texte affiché' );

	// 2. Formulaire : trois types, diagnostic affiché, case décochée, jeton de sécurité.
	wp_set_current_user( 1 );
	ob_start();
	Report::render_form( array(), null );
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( 3 === substr_count( $fluffless_html, 'name="fluffless_report[type]"' ), 'formulaire : types absents' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="_wpnonce"' ), 'formulaire : jeton absent' );
	$fluffless_assert( 1 === preg_match( '/<input[^>]*name="fluffless_report\[diagnostics\]"[^>]*>/', $fluffless_html, $fluffless_box ) && ! str_contains( $fluffless_box[0], 'checked' ), 'formulaire : case de diagnostic cochée par défaut' );
	$fluffless_assert( str_contains( $fluffless_html, esc_html( 'tools_version: ' . \Fluffless\Suite\VERSION ) ), 'formulaire : diagnostic non affiché avant envoi' );
	$fluffless_assert( str_contains( $fluffless_html, 'accept="image/png,image/webp"' ), 'formulaire : capture sans filtre de type' );

	// 3. Envoi sans diagnostic : ni diagnostics ni site dans la charge utile.
	$fluffless_result = Report::submit( $fluffless_input, null );
	$fluffless_body   = $fluffless_calls[0]['body'] ?? array();
	$fluffless_assert( 'sent' === $fluffless_result['status'] && 'r_42' === ( $fluffless_result['id'] ?? '' ), 'envoi : échec inattendu' );
	$fluffless_assert( str_ends_with( $fluffless_calls[0]['url'] ?? '', '/v1/reports' ) && 'bug' === $fluffless_body['type'] && 'personne@example.com' === $fluffless_body['reply_email'], 'envoi : charge utile incorrecte' );
	$fluffless_assert( ! isset( $fluffless_body['diagnostics'] ) && ! isset( $fluffless_body['site'] ), 'envoi : diagnostic joint sans consentement' );

	// 4. Envoi avec diagnostic coché.
	Report::submit( $fluffless_input + array( 'diagnostics' => '1' ), null );
	$fluffless_body = $fluffless_calls[1]['body'] ?? array();
	$fluffless_assert( isset( $fluffless_body['diagnostics']['tools_version'] ) && \Fluffless\Suite\Core\Api::site_hash() === ( $fluffless_body['site'] ?? '' ), 'envoi : diagnostic coché absent' );

	// 5. Validation : texte vide ou type inconnu refusés sans requête.
	$fluffless_count = count( $fluffless_calls );
	$fluffless_bad   = Report::submit( array( 'type' => 'rant' ) + $fluffless_input, null );
	$fluffless_empty = Report::submit( array( 'what' => '  ' ) + $fluffless_input, null );
	$fluffless_assert( 'invalid' === $fluffless_bad['status'] && 'invalid' === $fluffless_empty['status'] && count( $fluffless_calls ) === $fluffless_count, 'validation : saisie invalide envoyée' );

	// 6. Échec : message, repli courriel sans diagnostic ni courriel dans l'adresse.
	$fluffless_code   = 0;
	$fluffless_result = Report::submit( $fluffless_input + array( 'diagnostics' => '1' ), null );
	$fluffless_assert( 'failed' === $fluffless_result['status'], 'échec réseau : non signalé' );
	$fluffless_mail = Report::mailto( $fluffless_input );
	$fluffless_assert( str_starts_with( $fluffless_mail, 'mailto:genevieve@fluffless.dev?' ) && str_contains( rawurldecode( $fluffless_mail ), 'disparaît' ), 'repli : lien courriel incorrect' );
	$fluffless_assert( ! str_contains( rawurldecode( $fluffless_mail ), 'personne@example.com' ) && ! str_contains( rawurldecode( $fluffless_mail ), 'tools_version' ) && ! str_contains( $fluffless_mail, \Fluffless\Suite\Core\Api::site_hash() ), 'repli : donnée hors saisie dans l\'adresse' );
	ob_start();
	Report::render_form( $fluffless_input, $fluffless_result );
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html, 'Copy the diagnostic' ) && str_contains( $fluffless_html, 'mailto:genevieve@fluffless.dev' ), 'repli : bouton de copie ou lien absent' );
	// Copie partagée avec Serveurs contactés (assets/admin/copy.js) : messages rendus par PHP.
	$fluffless_assert( str_contains( $fluffless_html, 'data-fl-copy-done="Diagnostic copied."' ) && str_contains( $fluffless_html, 'data-fl-copy-fail="Copy failed: select the text above instead."' ), 'repli : messages de copie absents du bouton' );
	ob_start();
	Report::render();
	ob_end_clean();
	$fluffless_assert( wp_script_is( 'fluffless-copy', 'enqueued' ) && wp_script_is( 'fluffless-report', 'enqueued' ), 'écran : copy.js ou report.js absent de la file' );
	$fluffless_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin/report.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du paquet.
	$fluffless_assert( ! str_contains( $fluffless_js, 'clipboard' ), 'report.js : la copie n\'est pas passée dans copy.js' );

	// 7. 429 du serveur.
	$fluffless_code   = 429;
	$fluffless_result = Report::submit( $fluffless_input, null );
	$fluffless_assert( 'failed' === $fluffless_result['status'] && str_contains( $fluffless_result['message'], 'Too many' ), '429 : message absent' );

	// 8. Limite locale : 5 envois par heure, le 6e ne part pas.
	delete_transient( Lifecycle::TRANSIENT_REPORTS );
	$fluffless_code = 201;
	for ( $fluffless_i = 0; $fluffless_i < Report::MAX_PER_HOUR; $fluffless_i++ ) {
		Report::submit( $fluffless_input, null );
	}
	$fluffless_count  = count( $fluffless_calls );
	$fluffless_result = Report::submit( $fluffless_input, null );
	$fluffless_assert( 'limited' === $fluffless_result['status'] && count( $fluffless_calls ) === $fluffless_count, 'limite : 6e envoi parti' );

	// 9. Capture : type et poids vérifiés côté serveur.
	delete_transient( Lifecycle::TRANSIENT_REPORTS );
	$fluffless_png = wp_tempnam( 'fluffless-shot.png' );
	$fluffless_img = imagecreatetruecolor( 20, 20 );
	imagepng( $fluffless_img, $fluffless_png );
	$fluffless_file = array(
		'tmp_name' => $fluffless_png,
		'name'     => 'shot.png',
		'size'     => (int) filesize( $fluffless_png ),
		'error'    => UPLOAD_ERR_OK,
	);
	Report::submit( $fluffless_input, $fluffless_file );
	$fluffless_body = end( $fluffless_calls )['body'] ?? array();
	$fluffless_assert( isset( $fluffless_body['screenshot'] ) && str_starts_with( (string) base64_decode( (string) $fluffless_body['screenshot'] ), "\x89PNG" ), 'capture : absente de la charge utile' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- vérification du test.
	file_put_contents( $fluffless_png, '<?php echo 1;' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fichier temporaire du test.
	$fluffless_file['size'] = (int) filesize( $fluffless_png );
	$fluffless_result       = Report::submit( $fluffless_input, $fluffless_file );
	$fluffless_assert( 'invalid' === $fluffless_result['status'], 'capture : faux PNG accepté' );
	$fluffless_file['size'] = MB_IN_BYTES + 1;
	$fluffless_result       = Report::submit( $fluffless_input, $fluffless_file );
	$fluffless_assert( 'invalid' === $fluffless_result['status'], 'capture : plus de 1 Mo acceptée' );
	wp_delete_file( $fluffless_png );
} finally {
	remove_filter( 'pre_http_request', $fluffless_mock, 1 );
	delete_transient( Lifecycle::TRANSIENT_REPORTS );
	wp_set_current_user( 0 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Signalement, {$fluffless_checks} vérifications réussies.\n" );
