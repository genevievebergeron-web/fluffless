<?php
/**
 * Tests des échecs du module Formulaire : échec d'envoi gardé et signalé même sans le module Courriel,
 * message avec un autre chemin, plafond qui garde les champs et ignore les envois en échec,
 * action du formulaire et redirection en permaliens simples.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/form-echecs-test.php
 * Les réglages, l'option des formulaires, les transitoires et les permaliens sont remis en état à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Form;
use Fluffless\Suite\Modules\Mail;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Form::class ) ) {
	throw new RuntimeException( 'Module Formulaire absent.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_original  = get_option( Options::NAME );
$fluffless_forms     = get_option( Form::OPTION );
$fluffless_structure = get_option( 'permalink_structure' );
$fluffless_server    = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sauvegarde brute, remise telle quelle en fin de test.
$fluffless_ip        = '203.0.113.9';
$fluffless_fail      = false;
$fluffless_capture   = static function () use ( &$fluffless_fail ) {
	return ! $fluffless_fail; // @phpstan-ignore booleanNot.alwaysTrue
};
add_filter( 'pre_wp_mail', $fluffless_capture, 10, 0 );

switch_to_locale( 'en_US' );

try {
	Form::forget();
	Mail::forget_failures();
	delete_transient( Form::rate_key( $fluffless_ip ) );
	update_option( Options::NAME, Options::sanitize( array( 'form' => Form::defaults() ) ) );
	Options::flush();
	$_SERVER['REQUEST_URI'] = '/contact/';
	// Le module Courriel est supposé inactif : son accroche d'échec n'est pas branchée.
	remove_action( 'wp_mail_failed', array( Mail::class, 'record_failure' ) );

	$fluffless_def = array(
		'key'                => 'echec1234-k',
		'id'                 => 'echec1234',
		'recipients'         => array( 'contact@example.com' ),
		'route_field'        => '',
		'routes'             => array(),
		'subject'            => 'Essai',
		'confirm'            => false,
		'keep'               => false,
		'success'            => '',
		'submit'             => '',
		'newsletter'         => false,
		'newsletter_list'    => '',
		'newsletter_consent' => '',
		'steps'              => array(),
		'fields'             => array(
			array(
				'name'         => 'nom',
				'label'        => 'Nom',
				'type'         => 'text',
				'required'     => true,
				'step'         => 0,
				'placeholder'  => '',
				'autocomplete' => '',
				'value'        => '',
				'help'         => '',
				'options'      => array(),
				'when'         => '',
				'is'           => '',
			),
		),
	);
	Form::remember( $fluffless_def );
	$fluffless_post = array(
		Form::FIELD_KEY   => 'echec1234-k',
		Form::FIELD_TOKEN => Form::token( 'echec1234-k', time() - 10 ),
		'fl'              => array( 'nom' => 'Alice' ),
	);

	// C-S06-02 : un échec d'envoi est gardé et dit à la visiteuse où écrire.
	$fluffless_fail = true;
	$fluffless_r    = Form::process( $fluffless_post, $fluffless_ip );
	$fluffless_fail = false;
	$fluffless_assert( ! $fluffless_r['ok'] && str_contains( $fluffless_r['message'], 'could not be sent' ), 'échec d\'envoi non signalé' );
	$fluffless_assert( ! str_contains( $fluffless_r['message'], 'contact@example.com' ), 'l\'adresse du destinataire ne doit pas être montrée à la visiteuse : ' . $fluffless_r['message'] );
	$fluffless_assert( 1 === count( Mail::failures() ), 'C-S06-02 : échec non gardé avec le module Courriel inactif (' . count( Mail::failures() ) . ')' );
	$fluffless_public = static fn(): string => 'public@example.org';
	add_filter( 'fluffless_form_failure_contact', $fluffless_public );
	$fluffless_fail   = true;
	$fluffless_r2     = Form::process( $fluffless_post, $fluffless_ip );
	$fluffless_fail   = false;
	remove_filter( 'fluffless_form_failure_contact', $fluffless_public );
	$fluffless_assert( str_contains( $fluffless_r2['message'], 'public@example.org' ), 'le filtre permet de proposer une adresse publique : ' . $fluffless_r2['message'] );
	delete_transient( Form::rate_key( $fluffless_ip ) . 'f' );
	Mail::forget_failures();

	// Le même échec n'est gardé qu'une fois quand le module Courriel est actif et journalise.
	Mail::forget_failures();
	add_action( 'wp_mail_failed', array( Mail::class, 'record_failure' ) );
	$fluffless_boom = static function (): bool {
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP down', array( 'to' => array( 'contact@example.com' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- crochet du cœur.
		return false;
	};
	remove_filter( 'pre_wp_mail', $fluffless_capture, 10 );
	add_filter( 'pre_wp_mail', $fluffless_boom, 10, 0 );
	Form::process( $fluffless_post, $fluffless_ip );
	remove_filter( 'pre_wp_mail', $fluffless_boom, 10 );
	add_filter( 'pre_wp_mail', $fluffless_capture, 10, 0 );
	remove_action( 'wp_mail_failed', array( Mail::class, 'record_failure' ) );
	$fluffless_assert( 1 === count( Mail::failures() ), 'C-S06-02 : échec gardé deux fois (' . count( Mail::failures() ) . ')' );
	Mail::forget_failures();

	// Avis pour l'administratrice : absent sans échec, présent après un échec.
	wp_set_current_user( 1 );
	set_current_screen( 'dashboard' );
	ob_start();
	Form::render_failure_notice();
	$fluffless_quiet = (string) ob_get_clean();
	$fluffless_assert( '' === $fluffless_quiet, 'C-S06-02 : avis affiché sans échec' );
	$fluffless_fail = true;
	Form::process( $fluffless_post, $fluffless_ip );
	$fluffless_fail = false;
	ob_start();
	Form::render_failure_notice();
	$fluffless_notice = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_notice, 'notice-warning' ) && str_contains( $fluffless_notice, 'contact@example.com' ), 'C-S06-02 : avis absent après un échec : ' . $fluffless_notice );
	$fluffless_assert( ! str_contains( $fluffless_notice, 'never reaches you' ), 'X5-106 : « never reaches you » alors que des réponses peuvent être gardées' );
	// X5-106 : les réponses gardées non envoyées sont comptées, avec un lien vers le panneau Formulaire.
	$fluffless_unsent = Form::store(
		array_merge(
			$fluffless_def,
			array(
				'subject'   => '',
				'fields'    => array(),
				'keep_days' => 0,
			)
		),
		array()
	);
	ob_start();
	Form::render_failure_notice();
	$fluffless_notice = (string) ob_get_clean();
	Form::delete_all( 'echec1234' );
	$fluffless_assert( $fluffless_unsent > 0 && str_contains( $fluffless_notice, '1 form response was kept but not sent' ) && str_contains( $fluffless_notice, 'fl-title-form' ), 'X5-106 : réponses non envoyées non comptées : ' . $fluffless_notice );
	wp_set_current_user( 0 );
	ob_start();
	Form::render_failure_notice();
	$fluffless_assert( '' === (string) ob_get_clean(), 'C-S06-02 : avis montré à une personne sans droit' );
	Mail::forget_failures();

	// C-S06-10 : un envoi en échec ne compte pas au plafond.
	delete_transient( Form::rate_key( $fluffless_ip ) );
	delete_transient( Form::rate_key( $fluffless_ip ) . 'f' );
	$fluffless_fail = true;
	Form::process( $fluffless_post, $fluffless_ip );
	$fluffless_fail = false;
	$fluffless_assert( 0 === Form::rate_count( $fluffless_ip ), 'C-S06-10 : un envoi en échec compte au plafond (' . Form::rate_count( $fluffless_ip ) . ')' );
	Mail::forget_failures();

	// Les échecs comptent à part, avec un plafond plus large : rate x FAIL_FACTOR.
	delete_transient( Form::rate_key( $fluffless_ip ) );
	delete_transient( Form::rate_key( $fluffless_ip ) . 'f' );
	$fluffless_cap  = Options::get_int( 'form', 'rate' ) * Form::FAIL_FACTOR;
	$fluffless_fail = true;
	for ( $fluffless_i = 0; $fluffless_i < $fluffless_cap; $fluffless_i++ ) {
		Form::process( $fluffless_post, $fluffless_ip );
	}
	$fluffless_over   = Form::process( $fluffless_post, $fluffless_ip );
	$fluffless_fail   = false;
	$fluffless_failed = Form::fail_count( $fluffless_ip );
	$fluffless_assert( $fluffless_cap === $fluffless_failed && 0 === Form::rate_count( $fluffless_ip ), 'les échecs comptent à part (' . $fluffless_failed . ')' );
	$fluffless_assert( ! $fluffless_over['ok'] && str_contains( $fluffless_over['message'], 'Too many' ), 'le plafond des échecs n\'est pas appliqué' );
	delete_transient( Form::rate_key( $fluffless_ip ) . 'f' );

	// C-S06-10 : le plafond atteint garde les champs saisis.
	update_option( Options::NAME, Options::sanitize( array( 'form' => array_merge( Form::defaults(), array( 'rate' => 1 ) ) ) ) );
	Options::flush();
	delete_transient( Form::rate_key( $fluffless_ip ) );
	$fluffless_first = Form::process( $fluffless_post, $fluffless_ip );
	$fluffless_assert( $fluffless_first['ok'], 'premier envoi refusé : ' . $fluffless_first['message'] );
	$fluffless_second = Form::process( $fluffless_post, $fluffless_ip );
	$fluffless_assert( ! $fluffless_second['ok'] && str_contains( $fluffless_second['message'], 'Too many' ), 'plafond non appliqué' );
	$fluffless_assert( 'Alice' === ( $fluffless_second['values']['nom'] ?? null ), 'C-S06-10 : le plafond vide les champs saisis' );
	update_option( Options::NAME, Options::sanitize( array( 'form' => Form::defaults() ) ) );
	Options::flush();
	delete_transient( Form::rate_key( $fluffless_ip ) );

	// C-S06-09 : en permaliens simples, l'action et la redirection gardent la requête qui identifie la page.
	$fluffless_content = '<!-- wp:fluffless/formulaire {"formId":"echec1234"} --><!-- wp:fluffless/champ {"type":"text","label":"Nom"} /--><!-- /wp:fluffless/formulaire -->';
	update_option( 'permalink_structure', '' );
	$_SERVER['REQUEST_URI'] = '/?page_id=6&utm_source=x';
	$fluffless_html         = do_blocks( $fluffless_content );
	$fluffless_assert( 1 === preg_match( '/<form [^>]*action="([^"]*)"/', $fluffless_html, $fluffless_m ) && str_contains( html_entity_decode( $fluffless_m[1] ), 'page_id=6' ), 'C-S06-09 : l\'action perd la requête en permaliens simples : ' . ( $fluffless_m[1] ?? '' ) );
	$fluffless_assert( str_contains( Form::sent_url( 'echec1234' ), 'page_id=6' ), 'C-S06-09 : la redirection perd la requête (ou sent_url absente)' );
	update_option( 'permalink_structure', '/%postname%/' );
	$_SERVER['REQUEST_URI'] = '/contact/?utm_source=x';
	$fluffless_html         = do_blocks( $fluffless_content );
	$fluffless_assert( 1 === preg_match( '/<form [^>]*action="([^"]*)"/', $fluffless_html, $fluffless_m ) && '/contact/#fl-echec1234-errors' === $fluffless_m[1], 'permaliens propres : action sans requête : ' . ( $fluffless_m[1] ?? '' ) );
} finally {
	remove_filter( 'pre_wp_mail', $fluffless_capture, 10 );
	Form::delete_all( 'echec1234' );
	Mail::forget_failures();
	delete_transient( Form::rate_key( $fluffless_ip ) );
	Form::show_errors( null );
	if ( false === $fluffless_forms ) {
		Form::forget();
	} else {
		update_option( Form::OPTION, $fluffless_forms, false );
	}
	if ( false === $fluffless_original ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_original );
	}
	if ( false === $fluffless_structure ) {
		delete_option( 'permalink_structure' );
	} else {
		update_option( 'permalink_structure', $fluffless_structure );
	}
	Options::flush();
	restore_previous_locale();
	if ( null === $fluffless_server ) {
		unset( $_SERVER['REQUEST_URI'] );
	} else {
		$_SERVER['REQUEST_URI'] = $fluffless_server;
	}
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		echo esc_html( 'ÉCHEC : ' . $fluffless_failure ) . "\n";
	}
	exit( 1 );
}
echo esc_html( sprintf( 'Échecs de formulaire : %d vérifications réussies.', $fluffless_checks ) ) . "\n";
