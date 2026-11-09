<?php
/**
 * Tests de Sécurité de base : réglages assainis, adresse de connexion cachée (réécriture des liens,
 * détection de la requête, routage), en-têtes, XML-RPC fermé, tentatives limitées, journal
 * d'activité (table, écriture, crochets, purge, écran).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/security-test.php
 * Les réglages sont remis à leur état de départ à la fin ; les lignes de journal créées sont retirées.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Security;
use Fluffless\Suite\Modules\Security\Journal;

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les textes en en_US : indépendants de la langue du site.
switch_to_locale( 'en_US' );

// Les crochets déclenchés sont ceux du cœur ; REQUEST_URI et REMOTE_ADDR sont sauvegardés tels quels pour être remis.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.ValidatedSanitizedInput

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_backup_option = get_option( Options::NAME );
$fluffless_backup_uri    = $_SERVER['REQUEST_URI'] ?? null;
$fluffless_backup_ip     = $_SERVER['REMOTE_ADDR'] ?? null;
$fluffless_first_row     = 0;
$fluffless_page          = 0;

// add_settings_error() et get_settings_errors() vivent dans l'administration, que WP-CLI ne charge pas.
require_once ABSPATH . 'wp-admin/includes/template.php';

/** Codes des erreurs de réglages posées depuis le dernier appel, puis remise à zéro. */
$fluffless_errors = static function (): array {
	$codes                         = array_column( get_settings_errors( Options::NAME ), 'code' );
	$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- remise à zéro entre deux contrôles.
	return $codes;
};

/**
 * Pose les réglages du module et vide le cache.
 *
 * @param bool                 $enabled Module actif ?
 * @param array<string, mixed> $section Section du module.
 */
$fluffless_set = static function ( bool $enabled, array $section = array() ): void {
	$all = get_option( Options::NAME );
	$all = is_array( $all ) ? $all : array();
	if ( ! isset( $all['modules'] ) || ! is_array( $all['modules'] ) ) {
		$all['modules'] = array();
	}
	$all['modules'][ Security::id() ] = $enabled;
	$all[ Security::id() ]            = Security::sanitize( $section );
	update_option( Options::NAME, $all );
	Options::flush();
};

try {
	// 1. Contrat et branchement.
	$fluffless_loader = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Loader.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( 'security' === Security::id(), 'Identifiant du module.' );
	$fluffless_assert( false === Security::enabled_by_default(), 'Inactif par défaut.' );
	$fluffless_assert( 1 === preg_match( '/Security::class\s+=> array\(\),/', $fluffless_loader ), 'Module déclaré sans dépendance dans le chargeur.' );
	$fluffless_assert( array( 'login_slug', 'attempts', 'lockout', 'headers', 'xmlrpc', 'journal', 'journal_days' ) === array_keys( Security::fields() ), 'Sept champs de réglages.' );
	// C5-1-02 : l'aide dit que l'administration répond aussi 404, et quoi faire si le mot est oublié.
	$fluffless_help = Security::fields()['login_slug']['help'] ?? '';
	$fluffless_assert( str_contains( $fluffless_help, '/wp-admin' ) && str_contains( $fluffless_help, 'until you log in' ), 'Aide : l’administration répond aussi « not found ».' );
	$fluffless_assert( str_contains( $fluffless_help, 'Forgot it?' ) && str_contains( $fluffless_help, 'wp-content/plugins/fluffless-suite' ), 'Aide : chemin de retour si le mot est oublié.' );
	$fluffless_assert( ! str_contains( $fluffless_help, 'maintenance page, follow' ) && str_contains( $fluffless_help, 'under comments' ), 'Aide : quels liens publics mènent au mot, la page de maintenance n’en est plus.' );
	$fluffless_set( false );
	$fluffless_assert( '' === Security::status(), 'Aucun état quand le module est inactif.' );

	// 2. Assainissement.
	$fluffless_clean = Security::sanitize(
		array(
			'login_slug'   => ' /Entrée Privée/ ',
			'attempts'     => '99',
			'lockout'      => '1',
			'headers'      => '0',
			'xmlrpc'       => '',
			'journal'      => '1',
			'journal_days' => '1000',
		)
	);
	$fluffless_assert( 'entree-privee' === $fluffless_clean['login_slug'], 'Slug en minuscules, accents et espaces ramenés.' );
	$fluffless_assert( 20 === $fluffless_clean['attempts'] && 5 === $fluffless_clean['lockout'] && 365 === $fluffless_clean['journal_days'], 'Entiers bornés.' );
	$fluffless_assert( false === $fluffless_clean['headers'] && false === $fluffless_clean['xmlrpc'] && true === $fluffless_clean['journal'], 'Booléens lus.' );
	$fluffless_assert( '' === Security::sanitize_slug( 'wp-admin' ) && '' === Security::sanitize_slug( 'wp-quoi' ) && '' === Security::sanitize_slug( 'login' ) && '' === Security::sanitize_slug( 'admin' ), 'Slugs réservés et préfixe wp- refusés.' );
	$fluffless_assert( 60 === strlen( Security::sanitize_slug( str_repeat( 'a', 90 ) ) ), 'Slug borné à 60 caractères.' );
	$fluffless_assert( '' === Security::sanitize_slug( '<script>' ), 'Balises ramenées à rien.' );
	$fluffless_assert(
		array(
			'login_slug'   => '',
			'attempts'     => 5,
			'lockout'      => 15,
			'headers'      => true,
			'xmlrpc'       => true,
			'journal'      => true,
			'journal_days' => 90,
		) === Security::sanitize( array() ),
		'Valeurs par défaut.'
	); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine, Universal.Arrays.MixedArrayKeyTypes -- lisible sur une ligne.

	// 3. Réécriture de l'adresse de connexion.
	$fluffless_set( true, array( 'login_slug' => 'entrer' ) );
	$fluffless_assert( 'entrer' === Security::slug(), 'Slug lu depuis les réglages.' );

	// C1-5-03 et C5-1-04 : un mot déjà porté par un contenu publié est refusé, l'ancien réglage reste.
	$fluffless_errors();
	$fluffless_page = (int) wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Page F13',
			'post_name'   => 'f13-page-prise',
		)
	);
	$fluffless_assert( Security::slug_taken( 'f13-page-prise' ) && ! Security::slug_taken( 'f13-libre' ) && ! Security::slug_taken( '' ), 'Mot pris : une page publiée porte ce chemin.' );
	$fluffless_assert( 'entrer' === Security::sanitize( array( 'login_slug' => 'f13-page-prise' ) )['login_slug'], 'Mot pris : l’ancien réglage reste.' );
	$fluffless_assert( array( 'fluffless_login_slug' ) === $fluffless_errors(), 'Mot pris : une erreur de réglages le dit.' );
	$fluffless_assert( 'f13-libre' === Security::sanitize( array( 'login_slug' => 'f13-libre' ) )['login_slug'] && array() === $fluffless_errors(), 'Mot libre : accepté sans erreur.' );
	$fluffless_assert( 'entrer' === Security::sanitize( array( 'login_slug' => 'entrer' ) )['login_slug'] && array() === $fluffless_errors(), 'Mot inchangé : aucune erreur.' );
	$fluffless_all                           = get_option( Options::NAME );
	$fluffless_all['security']['login_slug'] = 'f13-page-prise';
	update_option( Options::NAME, $fluffless_all );
	Options::flush();
	$fluffless_assert( str_contains( Security::status(), 'Content on this site uses this address' ), 'Page publiée après coup : l’état avertit.' );
	$fluffless_assert( 'f13-page-prise' === Security::sanitize( array( 'login_slug' => 'f13-page-prise' ) )['login_slug'] && array() === $fluffless_errors(), 'Page publiée après coup : enregistrer un autre réglage ne répète pas l’erreur.' );
	$fluffless_set( true, array( 'login_slug' => 'entrer' ) );
	$fluffless_assert( ! str_contains( Security::status(), 'Content on this site uses this address' ), 'Mot libre : aucun avertissement.' );

	// C5-1-05 : un mot réservé ou en wp- n'est plus vidé en silence.
	$fluffless_assert( 'entrer' === Security::sanitize( array( 'login_slug' => 'admin' ) )['login_slug'] && array( 'fluffless_login_slug' ) === $fluffless_errors(), 'Mot réservé : l’ancien réglage reste, une erreur le dit.' );
	$fluffless_assert( 'entrer' === Security::sanitize( array( 'login_slug' => 'wp-quoi' ) )['login_slug'] && array( 'fluffless_login_slug' ) === $fluffless_errors(), 'Préfixe wp- : l’ancien réglage reste, une erreur le dit.' );
	$fluffless_assert( '' === Security::sanitize( array( 'login_slug' => ' / ' ) )['login_slug'] && array() === $fluffless_errors(), 'Champ vidé : adresse habituelle, sans erreur.' );
	$fluffless_assert( str_contains( Security::status(), home_url( '/entrer' ) ), 'État avec la nouvelle adresse.' );
	$fluffless_assert( home_url( '/entrer' ) . '?action=lostpassword' === Security::rewrite( home_url( '/wp-login.php?action=lostpassword' ) ), 'Réécriture avec la requête conservée.' );
	$fluffless_assert( home_url( '/entrer' ) === Security::rewrite( '/wp-login.php' ), 'URL relative réécrite en absolue.' );
	$fluffless_assert( home_url( '/entrer', 'https' ) === Security::rewrite( home_url( '/wp-login.php' ), 'https' ), 'Schéma demandé respecté.' );
	$fluffless_assert( home_url( '/page/' ) === Security::rewrite( home_url( '/page/' ) ), 'Autre URL laissée telle quelle.' );
	$fluffless_assert( 'entrer' === Security::request_path( '/entrer' ) && 'entrer' === Security::request_path( '/entrer/' ) && 'entrer' === Security::request_path( '/EnTrer/?x=1' ) && 'entrer' === Security::request_path( '/entr%65r' ), 'Chemin demandé reconnu avec barre, majuscules, requête et encodage.' );
	$fluffless_assert( 'entrer-pas' === Security::request_path( '/entrer-pas' ) && '' === Security::request_path( '/' ), 'Autres chemins distincts.' );

	// 4. register() sur une requête ordinaire : liens réécrits, routage.
	$_SERVER['REQUEST_URI'] = '/a-propos/';
	( new Security() )->register();
	$fluffless_assert( false !== has_filter( 'site_url', array( Security::class, 'filter_site_url' ) ) && false !== has_filter( 'wp_redirect', array( Security::class, 'filter_redirect' ) ), 'Filtres de réécriture posés.' );
	$fluffless_assert( 0 === has_action( 'wp_loaded', array( Security::class, 'dispatch' ) ), 'Routage sur wp_loaded à la priorité 0.' );
	$fluffless_assert( str_starts_with( wp_login_url(), home_url( '/entrer' ) ), 'wp_login_url pointe vers l’adresse cachée.' );
	$fluffless_assert( str_starts_with( wp_logout_url(), home_url( '/entrer' ) ) && str_contains( wp_logout_url(), 'action=logout' ), 'wp_logout_url réécrite avec son action.' );
	$fluffless_assert( str_starts_with( wp_registration_url(), home_url( '/entrer' ) ), 'wp_registration_url réécrite (wp_lostpassword_url est reprise par WooCommerce en local).' );
	$fluffless_assert( home_url( '/entrer' ) === Security::filter_redirect( home_url( '/wp-login.php' ) ), 'Redirection vers wp-login.php réécrite.' );
	$fluffless_assert( false === has_action( 'template_redirect', 'wp_redirect_admin_locations' ) && 1000 === has_action( 'template_redirect', array( Security::class, 'redirect_admin_locations' ) ), 'Raccourcis du cœur remplacés : /login ne mène plus à l’adresse cachée.' );
	$fluffless_assert( '' === Security::route( 'index.php' ), 'Requête ordinaire : rien.' );
	$fluffless_assert( 'not_found' === Security::route( 'wp-login.php' ), 'wp-login.php direct : 404.' );

	// C1-5-02 : le formulaire des contenus protégés par mot de passe ne révèle pas le mot et reste servi.
	$fluffless_form = get_the_password_form( new WP_Post( (object) array( 'ID' => 0 ) ) );
	$fluffless_assert( str_contains( $fluffless_form, 'wp-login.php?action=postpass' ) && ! str_contains( $fluffless_form, 'entrer' ), 'Contenu protégé : le formulaire vise wp-login.php, sans le mot.' );
	$fluffless_backup_method   = $_SERVER['REQUEST_METHOD'] ?? null;
	$fluffless_backup_get      = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sauvegarde, remise plus bas.
	$_GET['action']            = 'postpass';
	$fluffless_backup_post     = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sauvegarde, remise plus bas.
	$fluffless_backup_request  = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sauvegarde, remise plus bas.
	$_POST                     = array();
	$_REQUEST                  = array( 'action' => 'postpass' );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$fluffless_assert( '' === Security::route( 'wp-login.php' ), 'Contenu protégé : l’envoi du mot de passe est laissé au cœur.' );
	// Le cœur lit l'action dans $_REQUEST, où le corps POST l'emporte sur l'adresse (wp_magic_quotes).
	foreach ( array( 'login', 'lostpassword', 'register', 'rp' ) as $fluffless_body_action ) {
		$_POST    = array( 'action' => $fluffless_body_action );
		$_REQUEST = array_merge( $_GET, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- reproduit wp_magic_quotes().
		$fluffless_assert( 'not_found' === Security::route( 'wp-login.php' ), 'action=postpass dans l’adresse, action=' . $fluffless_body_action . ' dans le corps : 404.' );
	}
	$_POST    = array( 'action' => 'postpass' );
	$_REQUEST = array( 'action' => 'login' );
	$fluffless_assert( 'not_found' === Security::route( 'wp-login.php' ), 'action=postpass dans l’adresse et le corps, autre action dans $_REQUEST : 404.' );
	$_POST    = array( 'action' => 'postpass' );
	$_REQUEST = array( 'action' => 'postpass' );
	$fluffless_assert( '' === Security::route( 'wp-login.php' ), 'action=postpass partout : laissé au cœur.' );
	$_POST                     = $fluffless_backup_post;
	$_REQUEST                  = $fluffless_backup_request;
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$fluffless_assert( 'not_found' === Security::route( 'wp-login.php' ), 'action=postpass en GET : 404.' );
	$_GET['action']            = 'lostpassword';
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$fluffless_assert( 'not_found' === Security::route( 'wp-login.php' ), 'Autre action en POST : 404.' );
	$_GET = $fluffless_backup_get;
	if ( null === $fluffless_backup_method ) {
		unset( $_SERVER['REQUEST_METHOD'] );
	} else {
		$_SERVER['REQUEST_METHOD'] = $fluffless_backup_method;
	}

	// C5-1-01 : l'administration hors session répond 404, avec une phrase neutre et un lien vers l'accueil, sans le mot.
	$fluffless_screen          = $GLOBALS['current_screen'] ?? null;
	$GLOBALS['current_screen'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- écran factice, remis plus bas.
		/** Écran d'administration factice : is_admin() lit in_admin(). */
		public function in_admin(): bool {
			return true;
		}
	};
	wp_set_current_user( 0 );
	$fluffless_assert( 'not_found' === Security::route( 'index.php' ), 'Administration hors session : 404.' );
	$fluffless_assert( '' === Security::route( 'admin-post.php' ), 'admin-post.php hors session : laissé au cœur.' );
	$GLOBALS['current_screen'] = $fluffless_screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- écran d'origine remis.
	$fluffless_message         = (string) Security::admin_not_found_message();
	$fluffless_assert( str_contains( $fluffless_message, 'Page not found.' ) && str_contains( $fluffless_message, 'If you manage this site, log in through your login address, then open this link again.' ), 'Message 404 de l’administration : phrase neutre.' );
	$fluffless_assert( str_contains( $fluffless_message, 'href="' . esc_url( home_url( '/' ) ) . '"' ), 'Message 404 de l’administration : lien vers l’accueil.' );
	$fluffless_assert( '' !== $fluffless_message && ! str_contains( $fluffless_message, 'entrer' ), 'Message 404 de l’administration : le mot n’y est pas.' );
	Security::dispatch();
	$fluffless_assert( true, 'dispatch ne fait rien sous WP-CLI (aucune sortie, pas d’arrêt).' );
	$fluffless_recent    = Journal::recent( 1 );
	$fluffless_first_row = array() === $fluffless_recent ? 1 : $fluffless_recent[0]['id'] + 1;

	// 5. En-têtes.
	$fluffless_assert( false !== has_action( 'send_headers', array( Security::class, 'send_headers' ) ) && false !== has_action( 'login_init', array( Security::class, 'send_headers' ) ) && false !== has_action( 'admin_init', array( Security::class, 'send_headers' ) ), 'En-têtes posés sur le site, la connexion et l’administration.' );
	$fluffless_headers = Security::headers();
	$fluffless_assert( in_array( 'X-Content-Type-Options: nosniff', $fluffless_headers, true ) && in_array( 'X-Frame-Options: SAMEORIGIN', $fluffless_headers, true ) && in_array( 'Referrer-Policy: strict-origin-when-cross-origin', $fluffless_headers, true ) && in_array( 'Permissions-Policy: camera=(), microphone=(), geolocation=()', $fluffless_headers, true ), 'Quatre en-têtes de base.' );
	$fluffless_assert( array() === array_filter( $fluffless_headers, static fn( string $h ): bool => str_starts_with( $h, 'Strict-Transport-Security' ) ), 'Pas de HSTS hors HTTPS.' );
	$fluffless_filter = static fn( array $h ): array => array_merge( $h, array( 'X-Test: 1', 42 ) );
	add_filter( 'fluffless_security_headers', $fluffless_filter );
	$fluffless_assert( in_array( 'X-Test: 1', Security::headers(), true ) && ! in_array( 42, Security::headers(), true ), 'Filtre appliqué, valeurs non textuelles écartées.' );
	remove_filter( 'fluffless_security_headers', $fluffless_filter );

	// 6. XML-RPC.
	$fluffless_assert( false === apply_filters( 'xmlrpc_enabled', true ), 'XML-RPC coupé.' );
	$fluffless_assert( array() === apply_filters( 'xmlrpc_methods', array( 'system.listMethods' => 'x' ) ), 'Aucune méthode XML-RPC.' );
	$fluffless_assert(
		array( 'A' => 'b' ) === Security::strip_pingback(
			array(
				'A'          => 'b',
				'X-Pingback' => 'x',
			)
		),
		'X-Pingback retiré.'
	); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine
	$fluffless_assert( false === has_action( 'wp_head', 'rsd_link' ), 'Lien RSD retiré.' );

	// 7. Tentatives limitées.
	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	delete_transient( Security::LOCK_PREFIX . md5( '203.0.113.9' ) );
	$fluffless_assert( '203.0.113.9' === Security::client_ip(), 'Adresse IP lue.' );
	$fluffless_assert( false === Security::is_locked( '203.0.113.9' ) && 0 === Security::failures( '203.0.113.9' ), 'Aucun échec au départ.' );
	$fluffless_set(
		true,
		array(
			'attempts' => 3,
			'lockout'  => 5,
		)
	);
	do_action( 'wp_login_failed', 'bob' );
	do_action( 'wp_login_failed', 'bob' );
	$fluffless_assert( 2 === Security::failures( '203.0.113.9' ) && false === Security::is_locked( '203.0.113.9' ), 'Deux échecs comptés, pas encore en pause.' );
	$fluffless_result = apply_filters( 'authenticate', null, 'bob', 'x' );
	$fluffless_assert( ! ( $fluffless_result instanceof WP_Error ) || Security::ERROR_LOCKED !== $fluffless_result->get_error_code(), 'Avant le seuil, authenticate laisse le cœur trancher.' );
	do_action( 'wp_login_failed', 'bob' );
	do_action( 'wp_login_failed', 'bob' );
	$fluffless_assert( 3 === Security::failures( '203.0.113.9' ) && true === Security::is_locked( '203.0.113.9' ), 'Seuil atteint, compte plafonné, adresse en pause.' );
	$fluffless_result = apply_filters( 'authenticate', null, 'bob', 'x' );
	$fluffless_assert( $fluffless_result instanceof WP_Error && Security::ERROR_LOCKED === $fluffless_result->get_error_code() && str_contains( $fluffless_result->get_error_message(), '5' ), 'authenticate refuse avec le délai en minutes.' );
	$fluffless_user = get_user_by( 'id', 1 );
	do_action( 'wp_login', 'dev', $fluffless_user );
	$fluffless_assert( 0 === Security::failures( '203.0.113.9' ), 'Connexion réussie : compteur remis à zéro.' );
	$_SERVER['REMOTE_ADDR'] = 'pas-une-ip';
	$fluffless_assert( '0.0.0.0' === Security::client_ip(), 'Adresse invalide ramenée à 0.0.0.0.' );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

	// 8. Journal : table, écriture, lecture.
	$fluffless_assert( Journal::table_exists(), 'Table du journal créée par register().' );
	$fluffless_assert( Journal::SCHEMA === (int) get_option( Lifecycle::OPTION_JOURNAL_VERSION ), 'Version du schéma gardée.' );
	$fluffless_assert( Journal::enabled(), 'Journal en service.' );
	// C3-1-02 : paragraphe proposé pour la politique, avec l'IP des échecs et la durée réglée.
	$fluffless_assert( str_contains( Journal::privacy_text(), 'IP address' ) && str_contains( Journal::privacy_text(), (string) Options::get_int( Security::id(), 'journal_days' ) . ' days' ), 'C3-1-02 : mention du journal absente ou sans durée' );
	$fluffless_assert( false !== has_action( 'admin_init', array( Journal::class, 'add_privacy_text' ) ), 'C3-1-02 : mention du journal non proposée' );
	$fluffless_lockouts = array_filter( Journal::recent( 5 ), static fn( array $e ): bool => 'lockout' === $e['event'] && '203.0.113.9' === $e['ip'] && $e['id'] >= $fluffless_first_row );
	$fluffless_assert( array() !== $fluffless_lockouts, 'Le verrouillage a été journalisé avec l’adresse IP.' );
	Journal::record( 'login', 'dev' );
	Journal::record( 'login_failed', 'bob', '203.0.113.9' );
	Journal::record( 'inconnu', 'x' );
	Journal::record( 'login', str_repeat( 'a', 300 ), 'pas-une-ip' );
	$fluffless_recent = Journal::recent( 3 );
	$fluffless_assert( 3 === count( $fluffless_recent ) && 'login' === $fluffless_recent[0]['event'] && 200 === strlen( $fluffless_recent[0]['subject'] ) && '' === $fluffless_recent[0]['ip'], 'Sujet borné, adresse invalide écartée, événement inconnu ignoré.' );
	$fluffless_assert( 'login_failed' === $fluffless_recent[1]['event'] && '203.0.113.9' === $fluffless_recent[1]['ip'] && 'bob' === $fluffless_recent[1]['subject'], 'Échec de connexion avec adresse IP.' );
	$fluffless_assert( 'WP-CLI' === $fluffless_recent[2]['actor'] && abs( time() - $fluffless_recent[2]['time'] ) < 120, 'Acteur WP-CLI et horodatage UTC lus.' );
	$fluffless_assert( count( Journal::recent( 1000 ) ) === Journal::count() || Journal::count() > 1000, 'count() et recent() concordent.' );
	$fluffless_assert( array() === array_diff( Journal::EVENTS, array_keys( Journal::labels() ) ), 'Chaque événement a son libellé.' );

	// 9. Journal : crochets.
	$fluffless_before = Journal::count();
	do_action( 'wp_login', 'dev', $fluffless_user );
	do_action( 'wp_login_failed', 'mallory' );
	do_action( 'activated_plugin', 'fluffless-suite/fluffless-suite.php' );
	do_action( 'deactivated_plugin', 'inconnu/inconnu.php' );
	do_action( 'switch_theme', 'Fluffless', null, null );
	Journal::on_upgrade(
		null,
		array(
			'type'    => 'plugin',
			'action'  => 'update',
			'plugins' => array( 'fluffless-suite/fluffless-suite.php', 'inconnu/inconnu.php' ),
		)
	);
	Journal::on_upgrade(
		null,
		array(
			'type'   => 'theme',
			'action' => 'install',
			'themes' => array( 'fluffless' ),
		)
	);
	Journal::on_upgrade(
		null,
		array(
			'type'   => 'core',
			'action' => 'update',
		)
	);
	Journal::on_upgrade(
		null,
		array(
			'type'   => 'translation',
			'action' => 'update',
		)
	);
	$fluffless_old                         = array(
		'modules'  => array(
			'security' => true,
			'seo'      => false,
		),
		'security' => array( 'attempts' => 3 ),
		'mail'     => array( 'a' => 1 ),
	);
	$fluffless_new                         = $fluffless_old;
	$fluffless_new['modules']['seo']       = true;
	$fluffless_new['security']['attempts'] = 5;
	Journal::on_settings_changed( $fluffless_old, $fluffless_new );
	Journal::on_settings_changed( $fluffless_old, $fluffless_old );
	Journal::on_role_changed( 1, 'editor', array() );
	Journal::on_role_changed( 1, 'editor', array( 'subscriber' ) );
	Journal::on_user_created( 1 );
	Journal::on_user_updated( 999999 );
	Journal::on_user_deleted( 999999, null, null );
	$fluffless_rows   = array_reverse( array_slice( Journal::recent( 20 ), 0, Journal::count() - $fluffless_before ) );
	$fluffless_events = array_column( $fluffless_rows, 'event' );
	$fluffless_assert( array( 'login', 'login_failed', 'plugin_activated', 'plugin_deactivated', 'theme_switched', 'plugin_updated', 'theme_installed', 'core_updated', 'settings_changed', 'role_changed', 'user_created', 'user_updated', 'user_deleted' ) === $fluffless_events, 'Treize événements journalisés par les crochets, dans l’ordre : ' . implode( ',', $fluffless_events ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine
	$fluffless_by = count( $fluffless_events ) === count( $fluffless_rows ) ? array_combine( $fluffless_events, $fluffless_rows ) : array();
	$fluffless_assert( 'Fluffless Suite' === ( $fluffless_by['plugin_activated']['subject'] ?? '' ), 'Nom de l’extension lu dans son en-tête.' );
	$fluffless_assert( 'inconnu/inconnu.php' === ( $fluffless_by['plugin_deactivated']['subject'] ?? '' ), 'Extension inconnue : son fichier.' );
	$fluffless_assert( 'Fluffless Suite, inconnu/inconnu.php' === ( $fluffless_by['plugin_updated']['subject'] ?? '' ), 'Mises à jour groupées.' );
	$fluffless_assert( 'fluffless' === ( $fluffless_by['theme_installed']['subject'] ?? '' ), 'Installation de thème distinguée de la mise à jour.' );
	$fluffless_assert( get_bloginfo( 'version' ) === ( $fluffless_by['core_updated']['subject'] ?? '' ), 'Version du cœur notée.' );
	$fluffless_assert( 'seo on, security' === ( $fluffless_by['settings_changed']['subject'] ?? '' ), 'Sections changées et module activé listés : ' . ( $fluffless_by['settings_changed']['subject'] ?? '' ) );
	$fluffless_login   = get_userdata( 1 ) instanceof WP_User ? get_userdata( 1 )->user_login : '#1'; // « dev » en local, « ci » sur la CI.
	$fluffless_subject = (string) ( $fluffless_by['role_changed']['subject'] ?? '' );
	$fluffless_assert( $fluffless_login . ': subscriber > editor' === $fluffless_subject, 'Changement de rôle noté, pas à la création : ' . $fluffless_subject );
	$fluffless_assert( '#999999' === ( $fluffless_by['user_updated']['subject'] ?? '' ) && '#999999' === ( $fluffless_by['user_deleted']['subject'] ?? '' ), 'Compte disparu désigné par son numéro.' );
	$fluffless_assert( '203.0.113.9' === ( $fluffless_by['login_failed']['ip'] ?? '' ) && '' === ( $fluffless_by['login']['ip'] ?? 'x' ), 'Adresse IP gardée pour l’échec seulement.' );

	// 10. Purge.
	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		Journal::table(),
		array(
			'created' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ),
			'event'   => 'login',
			'actor'   => 'vieux',
		)
	);
	$fluffless_assert( 0 === Journal::purge( 0 ), 'Délai nul : rien purgé.' );
	$fluffless_assert( 1 === Journal::purge( 90 ), 'Une ligne de 100 jours purgée à 90 jours.' );
	$fluffless_assert( 0 === Journal::purge( 90 ), 'Rien de plus à purger.' );

	// 11. Écran.
	wp_set_current_user( 0 );
	ob_start();
	Journal::render();
	$fluffless_assert( '' === ob_get_clean(), 'Aucun écran pour un visiteur.' );
	wp_set_current_user( 1 );
	ob_start();
	Journal::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html, 'Activity log' ) && str_contains( $fluffless_html, '<table' ) && str_contains( $fluffless_html, 'Failed login' ) && str_contains( $fluffless_html, 'mallory' ) && str_contains( $fluffless_html, '203.0.113.9' ), 'Écran avec le tableau, les libellés et les lignes.' );
	$fluffless_assert( str_contains( $fluffless_html, '<form method="post"' ) && str_contains( $fluffless_html, 'name="action" value="' . Journal::ACTION_CLEAR . '"' ) && str_contains( $fluffless_html, 'name="_wpnonce"' ) && str_contains( $fluffless_html, 'name="confirm" value="1"' ), 'Vider : formulaire POST avec nonce et case de confirmation (audit C2).' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'admin-post.php?action=' . Journal::ACTION_CLEAR ), 'Vider : plus de lien GET (audit C2).' );
	// Gestionnaire : GET refusé, POST sans case refusé, POST confirmé vide le journal.
	$fluffless_stop   = static function ( $location ): never {
		throw new RuntimeException( 'redirect:' . esc_url_raw( $location ) );
	};
	$fluffless_die    = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			throw new RuntimeException( 'die:' . ( is_array( $args ) ? (int) ( $args['response'] ?? 0 ) : 0 ) );
		};
	};
	$fluffless_call   = static function ( callable $handler, string $method, array $post ) use ( $fluffless_stop, $fluffless_die ): string {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST                     = $post;
		$_REQUEST                  = $post;
		add_filter( 'wp_redirect', $fluffless_stop, 1 ); // @phpstan-ignore return.missing
		add_filter( 'wp_die_handler', $fluffless_die, PHP_INT_MAX );
		try {
			$handler();
			return 'no-exit';
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $fluffless_stop, 1 );
			remove_filter( 'wp_die_handler', $fluffless_die, PHP_INT_MAX );
			$_POST    = array();
			$_REQUEST = array();
			unset( $_SERVER['REQUEST_METHOD'] );
		}
	};
	$fluffless_h      = array( Journal::class, 'handle_clear' );
	$fluffless_before = Journal::count();
	$fluffless_ok     = array(
		'confirm'  => '1',
		'_wpnonce' => wp_create_nonce( Journal::ACTION_CLEAR ),
	);
	$fluffless_out    = $fluffless_call( $fluffless_h, 'GET', $fluffless_ok );
	$fluffless_assert( 'die:405' === $fluffless_out && Journal::count() === $fluffless_before && $fluffless_before > 0, 'Vider en GET : refusé, journal intact (' . $fluffless_out . ').' );
	$fluffless_out = $fluffless_call( $fluffless_h, 'POST', array( '_wpnonce' => $fluffless_ok['_wpnonce'] ) );
	$fluffless_assert( str_contains( $fluffless_out, 'cleared=unconfirmed' ) && Journal::count() === $fluffless_before, 'Vider sans la case : rien effacé (' . $fluffless_out . ').' );
	$fluffless_out = $fluffless_call( $fluffless_h, 'POST', $fluffless_ok );
	$fluffless_assert( str_contains( $fluffless_out, 'cleared=1' ) && 0 === Journal::count(), 'Vider confirmé : journal vide (' . $fluffless_out . ').' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ), 'Aucun script sur l’écran.' );
	$fluffless_assert( 1 === preg_match( '/removed after 90 days/', $fluffless_html ), 'Délai de conservation annoncé.' );

	// 12. Journal fermé : plus rien ne s'écrit.
	$fluffless_set( true, array( 'journal' => false ) );
	$fluffless_count = Journal::count();
	Journal::record( 'login', 'dev' );
	$fluffless_assert( Journal::count() === $fluffless_count && ! Journal::enabled(), 'Journal fermé : aucune écriture.' );
	$fluffless_set( false );
	$fluffless_assert( '' === Security::slug() || ! Options::is_enabled( Security::id() ), 'Module inactif.' );
} finally {
	wp_set_current_user( 0 );
	if ( $fluffless_page > 0 ) {
		wp_delete_post( $fluffless_page, true );
	}
	if ( $fluffless_first_row > 0 && Journal::table_exists() ) {
		global $wpdb;
		$fluffless_table = Journal::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$fluffless_table} WHERE id >= %d", $fluffless_first_row ) );
	}
	delete_transient( Security::LOCK_PREFIX . md5( '203.0.113.9' ) );
	delete_transient( Journal::TRANSIENT_PURGED );
	if ( null === $fluffless_backup_uri ) {
		unset( $_SERVER['REQUEST_URI'] );
	} else {
		$_SERVER['REQUEST_URI'] = $fluffless_backup_uri;
	}
	if ( null === $fluffless_backup_ip ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $fluffless_backup_ip;
	}
	if ( false === $fluffless_backup_option ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_backup_option );
	}
	Options::flush();
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Sécurité de base : ' . $fluffless_checks . ' vérifications passées.' );
