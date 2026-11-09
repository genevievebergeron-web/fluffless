<?php
/**
 * Tests du module Courriel sortant.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/mail-test.php
 * Aucun courriel ne part : wp_mail() est court-circuité par pre_wp_mail. Le mot de passe
 * d'essai passe par la variable d'environnement, jamais par une constante ni la base.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Mail;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés de PHPMailer.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- mot de passe d'essai par l'environnement, jamais en base.

if ( ! class_exists( Mail::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
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

$fluffless_backup_option = get_option( Options::NAME );

/**
 * Enregistre la section mail (et l'état du module) comme le ferait la page de réglages.
 *
 * @param array<string, mixed> $section Réglages du module.
 * @param bool                 $enabled Module actif.
 */
$fluffless_set = static function ( array $section, bool $enabled = true ): void {
	update_option(
		Options::NAME,
		Options::sanitize(
			array(
				'modules' => array( 'mail' => $enabled ),
				'mail'    => $section + Mail::defaults(),
			)
		)
	);
	Options::flush();
};

try {
	// Contrat du module.
	$fluffless_assert( 'mail' === Mail::id(), 'id' );
	$fluffless_assert( ! Mail::enabled_by_default(), 'inactif par défaut' );
	$fluffless_defaults = Mail::defaults();
	$fluffless_assert( 587 === $fluffless_defaults['port'] && 'tls' === $fluffless_defaults['encryption'], 'défauts : 587 et STARTTLS' );
	$fluffless_assert( array_keys( Mail::fields() ) === array_keys( $fluffless_defaults ), 'un champ par réglage' );

	// Assainissement.
	$fluffless_clean = Mail::sanitize(
		array(
			'host'       => ' SMTP.Example.com ',
			'port'       => '70000',
			'encryption' => 'bogus',
			'from_email' => 'Bonjour@Example.com',
			'from_name'  => '<b>Site</b>',
			'force_from' => '0',
		)
	);
	$fluffless_assert( 'smtp.example.com' === $fluffless_clean['host'], 'hôte en minuscules, sans espaces' );
	$fluffless_assert( 65535 === $fluffless_clean['port'], 'port borné' );
	$fluffless_assert( 'tls' === $fluffless_clean['encryption'], 'chiffrement inconnu ramené au défaut' );
	$fluffless_assert( 'Bonjour@Example.com' === $fluffless_clean['from_email'], 'adresse valide gardée' );
	$fluffless_assert( 'Site' === $fluffless_clean['from_name'], 'nom sans balises' );
	$fluffless_assert( false === $fluffless_clean['force_from'], 'bool assaini' );
	$fluffless_assert( true === $fluffless_clean['log'], 'clé absente ramenée au défaut' );
	$fluffless_bad = Mail::sanitize(
		array(
			'host'       => 'not a host',
			'from_email' => 'not-an-address',
		)
	);
	$fluffless_assert( '' === $fluffless_bad['host'], 'hôte invalide vidé' );
	$fluffless_assert( '' === $fluffless_bad['from_email'], 'adresse invalide vidée' );

	// Mot de passe : constante ou environnement, jamais la base.
	putenv( Mail::PASSWORD_CONSTANT );
	$fluffless_set( array( 'host' => 'smtp.example.com' ) );
	$fluffless_assert( defined( Mail::PASSWORD_CONSTANT ) || ! Mail::has_password(), 'sans mot de passe' );
	$fluffless_assert( defined( Mail::PASSWORD_CONSTANT ) || ! Mail::ready(), 'pas prêt sans mot de passe' );
	$fluffless_assert( defined( Mail::PASSWORD_CONSTANT ) || '' !== Mail::status(), 'état : mot de passe manquant' );
	putenv( Mail::PASSWORD_CONSTANT . '=secret-de-test' );
	$fluffless_assert( Mail::has_password(), 'mot de passe lu dans l\'environnement' );
	$fluffless_assert( Mail::ready(), 'prêt avec hôte et mot de passe' );
	$fluffless_assert( '' === Mail::status(), 'aucun avertissement une fois prêt' );
	$fluffless_set( array( 'host' => '' ) );
	$fluffless_assert( ! Mail::ready() && '' !== Mail::status(), 'pas prêt sans hôte' );
	$fluffless_assert( false === strpos( (string) wp_json_encode( get_option( Options::NAME ) ), 'secret-de-test' ), 'le mot de passe n\'est jamais en base' );

	// PHPMailer : configuré seulement quand prêt. Chargé comme le fait wp_mail().
	if ( ! class_exists( PHPMailer\PHPMailer\PHPMailer::class, false ) ) {
		require_once ABSPATH . 'wp-includes/PHPMailer/PHPMailer.php';
		require_once ABSPATH . 'wp-includes/PHPMailer/SMTP.php';
		require_once ABSPATH . 'wp-includes/PHPMailer/Exception.php';
	}
	$fluffless_mailer = new PHPMailer\PHPMailer\PHPMailer( true );
	Mail::configure( $fluffless_mailer );
	$fluffless_assert( 'mail' === $fluffless_mailer->Mailer, 'PHPMailer intact sans hôte' );
	$fluffless_set(
		array(
			'host'       => 'smtp.example.com',
			'port'       => 465,
			'encryption' => 'ssl',
			'username'   => 'bonjour@example.com',
		)
	);
	Mail::configure( $fluffless_mailer );
	$fluffless_assert( 'smtp' === $fluffless_mailer->Mailer, 'PHPMailer en SMTP' );
	$fluffless_assert( 'smtp.example.com' === $fluffless_mailer->Host && 465 === $fluffless_mailer->Port, 'hôte et port' );
	$fluffless_assert( 'ssl' === $fluffless_mailer->SMTPSecure && $fluffless_mailer->SMTPAutoTLS, 'SSL, STARTTLS automatique permis' );
	$fluffless_assert( $fluffless_mailer->SMTPAuth && 'bonjour@example.com' === $fluffless_mailer->Username && 'secret-de-test' === $fluffless_mailer->Password, 'authentification' );
	$fluffless_assert( Mail::TIMEOUT === $fluffless_mailer->Timeout, 'délai borné' );
	$fluffless_set(
		array(
			'host'       => 'localhost',
			'encryption' => 'none',
			'username'   => '',
		)
	);
	$fluffless_mailer = new PHPMailer\PHPMailer\PHPMailer( true );
	Mail::configure( $fluffless_mailer );
	$fluffless_assert( '' === $fluffless_mailer->SMTPSecure && ! $fluffless_mailer->SMTPAutoTLS, 'sans chiffrement : STARTTLS automatique coupé' );
	$fluffless_assert( ! $fluffless_mailer->SMTPAuth && '' === $fluffless_mailer->Password, 'sans identifiant : ni authentification ni mot de passe transmis' );
	Mail::configure( 'pas un mailer' );
	$fluffless_assert( true, 'valeur inattendue ignorée sans erreur' );

	// Expéditeur.
	$fluffless_default_from = 'wordpress@' . preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) ) );
	$fluffless_set(
		array(
			'from_email' => 'bonjour@example.com',
			'from_name'  => 'Exemple',
			'force_from' => true,
		)
	);
	$fluffless_assert( 'bonjour@example.com' === Mail::from_email( 'autre@example.org' ), 'expéditeur forcé partout' );
	$fluffless_assert( 'Exemple' === Mail::from_name( 'Autre' ), 'nom forcé partout' );
	$fluffless_set(
		array(
			'from_email' => 'bonjour@example.com',
			'from_name'  => 'Exemple',
			'force_from' => false,
		)
	);
	$fluffless_assert( 'bonjour@example.com' === Mail::from_email( $fluffless_default_from ), 'remplace le wordpress@ par défaut' );
	$fluffless_assert( 'autre@example.org' === Mail::from_email( 'autre@example.org' ), 'garde un expéditeur posé par une extension' );
	$fluffless_assert( 'Exemple' === Mail::from_name( 'WordPress' ) && 'Autre' === Mail::from_name( 'Autre' ), 'même règle pour le nom' );
	$fluffless_set( array( 'from_email' => '' ) );
	$fluffless_assert( Mail::from_email( $fluffless_default_from ) === $fluffless_default_from, 'sans réglage : inchangé' );

	// Journal des échecs : destinataire, date, erreur ; jamais le contenu.
	Mail::forget_failures();
	$fluffless_assert( array() === Mail::failures(), 'journal vide' );
	for ( $fluffless_i = 1; $fluffless_i <= Mail::MAX_FAILURES + 2; $fluffless_i++ ) {
		Mail::record_failure(
			new WP_Error(
				'wp_mail_failed',
				"SMTP Error {$fluffless_i}",
				array(
					'to'      => array( "p{$fluffless_i}@example.com" ),
					'subject' => 'Sujet secret',
					'message' => 'Contenu secret',
				)
			)
		);
	}
	$fluffless_failures_log = Mail::failures();
	$fluffless_assert( Mail::MAX_FAILURES === count( $fluffless_failures_log ), 'journal borné' );
	$fluffless_assert( 'SMTP Error 12' === $fluffless_failures_log[0]['message'] && 'p12@example.com' === $fluffless_failures_log[0]['to'], 'plus récent en premier' );
	$fluffless_assert( false === strpos( (string) wp_json_encode( get_transient( Lifecycle::TRANSIENT_MAIL_FAILURES ) ), 'secret' ), 'ni sujet ni contenu gardés' );
	Mail::record_failure( 'pas une erreur' );
	$fluffless_assert( Mail::MAX_FAILURES === count( Mail::failures() ), 'valeur inattendue ignorée' );
	Mail::forget_failures();

	// Envoi d'essai, sans envoi réel.
	$fluffless_sent      = array();
	$fluffless_intercept = static function ( $short, array $atts ) use ( &$fluffless_sent ) {
		$fluffless_sent = $atts;
		return true;
	};
	add_filter( 'pre_wp_mail', $fluffless_intercept, 10, 2 );
	$fluffless_result = Mail::send_test( 'admin@example.com' );
	remove_filter( 'pre_wp_mail', $fluffless_intercept, 10 );
	$fluffless_assert( true === $fluffless_result['ok'] && 'admin@example.com' === $fluffless_result['to'], 'essai envoyé' );
	$fluffless_assert( 'admin@example.com' === ( $fluffless_sent['to'] ?? '' ) && false !== strpos( (string) ( $fluffless_sent['subject'] ?? '' ), 'Test email' ), 'destinataire et sujet' );
	$fluffless_last = Mail::last_test();
	$fluffless_assert( null !== $fluffless_last && $fluffless_last['ok'], 'résultat gardé' );
	$fluffless_result = Mail::send_test( 'pas-une-adresse' );
	$fluffless_assert( false === $fluffless_result['ok'] && '' !== $fluffless_result['message'], 'adresse invalide refusée sans envoi' );
	$fluffless_failing = static function ( $short, array $atts ) {
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Connection refused', $atts ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- crochet de WordPress, rejoué comme le fait wp_mail().
		return false;
	};
	add_filter( 'pre_wp_mail', $fluffless_failing, 10, 2 );
	$fluffless_result = Mail::send_test( 'admin@example.com' );
	remove_filter( 'pre_wp_mail', $fluffless_failing, 10 );
	$fluffless_assert( false === $fluffless_result['ok'] && 'Connection refused' === $fluffless_result['message'], 'échec rapporté avec le message du mailer' );
	delete_transient( Lifecycle::TRANSIENT_MAIL_TEST );

	// Crochets posés par register(), selon les réglages.
	$fluffless_set(
		array(
			'from_email' => 'bonjour@example.com',
			'log'        => false,
		)
	);
	remove_action( 'phpmailer_init', array( Mail::class, 'configure' ) );
	remove_filter( 'wp_mail_from', array( Mail::class, 'from_email' ) );
	remove_action( 'wp_mail_failed', array( Mail::class, 'record_failure' ) );
	( new Mail() )->register();
	$fluffless_assert( false !== has_action( 'phpmailer_init', array( Mail::class, 'configure' ) ), 'phpmailer_init branché' );
	$fluffless_assert( false !== has_filter( 'wp_mail_from', array( Mail::class, 'from_email' ) ), 'expéditeur branché quand réglé' );
	$fluffless_assert( false === has_action( 'wp_mail_failed', array( Mail::class, 'record_failure' ) ), 'journal débranché quand coupé' );
	remove_action( 'phpmailer_init', array( Mail::class, 'configure' ) );
	remove_filter( 'wp_mail_from', array( Mail::class, 'from_email' ) );
	remove_filter( 'wp_mail_from_name', array( Mail::class, 'from_name' ) );
	$fluffless_assert( in_array( Lifecycle::TRANSIENT_MAIL_FAILURES, ( new ReflectionClassConstant( Lifecycle::class, 'TRANSIENTS' ) )->getValue(), true ), 'transitoire du journal nettoyé à la désactivation' );
} finally {
	putenv( Mail::PASSWORD_CONSTANT );
	if ( false === $fluffless_backup_option ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_backup_option );
	}
	Options::flush();
	Mail::forget_failures();
	delete_transient( Lifecycle::TRANSIENT_MAIL_TEST );
}

if ( $fluffless_failures ) {
	echo esc_html( "Échecs :\n- " . implode( "\n- ", $fluffless_failures ) . "\n" );
	exit( 1 );
}
echo esc_html( "OK : Courriel sortant, {$fluffless_checks} vérifications réussies.\n" );
