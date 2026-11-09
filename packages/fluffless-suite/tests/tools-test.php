<?php
/**
 * Tests du noyau et des modules de Fluffless Suite.
 *
 * Lancer : npm run test:tools
 * (docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/tools-test.php)
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * L'option fluffless_settings est sauvegardée au début et restaurée à la fin, même en cas d'échec.
 *
 * @package Fluffless_Suite
 */

// Le test déclenche des crochets du cœur (pre_http_request, xmlrpc_enabled...) et simule
// la désinstallation (WP_UNINSTALL_PLUGIN) : ces noms ne peuvent pas porter le préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Http;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Cleanup;
use Fluffless\Suite\Modules\Footer;
use Fluffless\Suite\Modules\Images;
use Fluffless\Suite\Modules\Media\Quarantine;
use Fluffless\Suite\Modules\Visibility;

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les textes en en_US : indépendants de la langue du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Options::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

require_once __DIR__ . '/fixtures/class-fluffless-test-module.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_backup = get_option( Options::NAME, null );
$fluffless_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$fluffless_admin  = (int) ( $fluffless_admins[0] ?? 0 );

try {
	// 1. Assainissement : valeurs hors liste rejetées, clés inconnues écartées, bornes appliquées.
	$fluffless_clean    = Options::sanitize(
		array(
			'modules' => array(
				'cleanup' => '0',
				'images'  => 'oui',
				'bogus'   => true,
			),
			'cleanup' => array(
				'emoji'    => 'false',
				'xmlrpc'   => array( 1 ),
				'inventee' => true,
			),
			'images'  => array(
				'output_format' => 'gif',
				'remove_sizes'  => array( 'bogus', 'medium_large', 'medium_large', 42 ),
				'warn_kb'       => '9000',
				'max_kb'        => 'abc',
			),
			'http'    => array( 'allowed_hosts' => "https://Example.org/chemin\n<script>\nsous.domaine.ca\n-bad-.com" ),
		)
	);
	$fluffless_defaults = Images::defaults();
	$fluffless_assert( Options::SCHEMA === $fluffless_clean['version'], 'sanitize : version absente' );
	$fluffless_assert( false === $fluffless_clean['modules']['cleanup'], 'sanitize : « 0 » ne désactive pas le module' );
	$fluffless_assert( true === $fluffless_clean['modules']['images'], 'sanitize : valeur « oui » non ramenée à la valeur courante' );
	$fluffless_assert( ! isset( $fluffless_clean['modules']['bogus'] ), 'sanitize : module inconnu conservé' );
	$fluffless_assert( false === $fluffless_clean['cleanup']['emoji'], 'sanitize : « false » non accepté' );
	$fluffless_assert( true === $fluffless_clean['cleanup']['xmlrpc'], 'sanitize : tableau accepté comme booléen' );
	$fluffless_assert( ! isset( $fluffless_clean['cleanup']['inventee'] ), 'sanitize : clé inconnue conservée' );
	$fluffless_assert( 'webp' === $fluffless_clean['images']['output_format'], 'sanitize : format « gif » accepté' );
	$fluffless_assert( array( 'medium_large' ) === $fluffless_clean['images']['remove_sizes'], 'sanitize : tailles hors liste ou doublons conservés : ' . wp_json_encode( $fluffless_clean['images']['remove_sizes'] ) );
	$fluffless_assert( $fluffless_defaults['max_kb'] === $fluffless_clean['images']['max_kb'], 'sanitize : max_kb « abc » non ramené au défaut' );
	$fluffless_assert( $fluffless_clean['images']['max_kb'] === $fluffless_clean['images']['warn_kb'], 'sanitize : alerte au-delà du refus non ramenée au plafond' );
	$fluffless_assert( array( 'example.org', 'sous.domaine.ca' ) === $fluffless_clean['http']['allowed_hosts'], 'sanitize : domaines mal filtrés : ' . wp_json_encode( $fluffless_clean['http']['allowed_hosts'] ) );
	$fluffless_assert( Options::all() === array_diff_key( Options::sanitize( 'pas un tableau' ), array_flip( Options::FOREIGN ) ), 'sanitize : entrée non tableau ne rend pas la valeur courante' );
	// Section de Fluffless Consent : reçue, elle est gardée telle quelle ; invalide, la section stockée (ou rien) la remplace.
	$fluffless_foreign = Options::sanitize( array( 'consent' => array( 'ga_id' => 'G-KEEP1234' ) ) );
	$fluffless_assert( array( 'ga_id' => 'G-KEEP1234' ) === ( $fluffless_foreign['consent'] ?? null ), 'sanitize : section consent d\'une autre extension perdue' );
	$fluffless_assert( ! is_string( Options::sanitize( array( 'consent' => 'pas un tableau' ) )['consent'] ?? null ), 'sanitize : section consent non tableau conservée' );
	$fluffless_hint = static fn(): array => array(
		'video' => array(
			'category' => 'media',
			'label'    => 'Vidéo',
		),
	);
	add_filter( 'fluffless_consent_services', $fluffless_hint );
	$fluffless_assert( SettingsPage::declares_consent_services(), 'réglages : service déclaré non détecté pour recommander Fluffless Consent' );
	remove_filter( 'fluffless_consent_services', $fluffless_hint );
	$fluffless_assert( Options::sanitize( $fluffless_clean ) === $fluffless_clean, 'sanitize : non idempotent' );

	// 2. Loader : module désactivé = ni instance, ni crochet ; activé = les deux.
	$fluffless_add_test_module = static function ( array $modules ): array {
		$modules['fluffless_test'] = Fluffless_Test_Module::class;
		return $modules;
	};
	$fluffless_disable_test    = static function (): array {
		return array( 'modules' => array( 'fluffless_test' => false ) );
	};
	add_filter( 'fluffless_modules', $fluffless_add_test_module );
	add_filter( 'pre_option_' . Options::NAME, $fluffless_disable_test );
	Loader::reset();
	$fluffless_booted = Loader::boot_modules( array( 'fluffless_test' => Fluffless_Test_Module::class ) );
	$fluffless_assert( array() === $fluffless_booted, 'loader : module désactivé enregistré' );
	$fluffless_assert( 0 === Fluffless_Test_Module::$instances, 'loader : module désactivé instancié' );
	$fluffless_assert( false === has_action( 'fluffless_test_ping' ), 'loader : module désactivé a posé un crochet' );
	remove_filter( 'pre_option_' . Options::NAME, $fluffless_disable_test );
	Options::flush();
	$fluffless_booted = Loader::boot_modules( array( 'fluffless_test' => Fluffless_Test_Module::class ) );
	$fluffless_assert( array( 'fluffless_test' ) === $fluffless_booted && 1 === Fluffless_Test_Module::$instances, 'loader : module actif par défaut non enregistré' );
	$fluffless_assert( false !== has_action( 'fluffless_test_ping' ), 'loader : crochet du module actif absent' );
	remove_filter( 'fluffless_modules', $fluffless_add_test_module );
	add_filter( 'fluffless_modules', static fn(): array => array( 'autre' => Cleanup::class ), 99 );
	Loader::reset();
	$fluffless_doing_it_wrong = 0;
	add_action(
		'doing_it_wrong_run',
		static function () use ( &$fluffless_doing_it_wrong ): void {
			++$fluffless_doing_it_wrong;
		}
	);
	$fluffless_assert( array() === Loader::modules() && 1 === $fluffless_doing_it_wrong, 'loader : entrée dont la clé diffère de id() acceptée ou non signalée' );
	remove_all_filters( 'fluffless_modules' );
	Loader::reset();
	$fluffless_assert( array( 'cleanup', 'images', 'visibility', 'http' ) === array_slice( array_keys( Loader::modules() ), 0, 4 ), 'loader : liste par défaut inattendue' );
	$fluffless_assert( Loader::defaults() === Loader::modules(), 'loader : liste filtrée différente de la liste de base sans filtre' );
	foreach ( array_keys( Loader::defaults() ) as $fluffless_id ) {
		$fluffless_unknown = array_diff( Loader::requires( $fluffless_id ), array_keys( Loader::defaults() ) );
		$fluffless_assert( array() === $fluffless_unknown, 'loader : ' . $fluffless_id . ' requiert un module inconnu : ' . implode( ', ', $fluffless_unknown ) );
	}
	// Dépendance inactive : le dépendant reste enregistré, le manque est relevé et l'avis le nomme.
	$fluffless_no_audit = static function (): array {
		return array(
			'modules' => array(
				'audit'      => false,
				'footer'     => true,
				'visibility' => true,
			),
		);
	};
	add_filter( 'pre_option_' . Options::NAME, $fluffless_no_audit );
	Options::flush();
	$fluffless_booted = Loader::boot_modules( Loader::modules() );
	$fluffless_assert( in_array( 'footer', $fluffless_booted, true ) && ! in_array( 'audit', $fluffless_booted, true ), 'loader : dépendant non enregistré quand son module requis est inactif' );
	$fluffless_assert( array( 'audit' ) === ( Loader::unmet()['footer'] ?? null ), 'loader : dépendance manquante non relevée : ' . wp_json_encode( Loader::unmet() ) );
	wp_set_current_user( 1 );
	ob_start();
	Loader::notice_unmet();
	$fluffless_notice = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_notice, 'notice-warning' ) && str_contains( $fluffless_notice, esc_html( Audit::label() ) ), 'loader : avis de mode réduit absent ou sans le nom du module requis' );
	wp_set_current_user( 0 );
	remove_filter( 'pre_option_' . Options::NAME, $fluffless_no_audit );
	Options::flush();
	$fluffless_all_on = static function (): array {
		return array(
			'modules' => array(
				'audit'      => true,
				'footer'     => true,
				'visibility' => true,
			),
		);
	};
	add_filter( 'pre_option_' . Options::NAME, $fluffless_all_on );
	Options::flush();
	Loader::boot_modules(
		array(
			'audit'      => Audit::class,
			'visibility' => Visibility::class,
			'footer'     => Footer::class,
		)
	);
	$fluffless_assert( array() === Loader::unmet(), 'loader : dépendance relevée alors que le module requis est actif : ' . wp_json_encode( Loader::unmet() ) );
	remove_filter( 'pre_option_' . Options::NAME, $fluffless_all_on );
	Loader::reset();
	Loader::boot_modules( Loader::modules() );

	// P5-02 : site neuf, aucun mode réduit. Pied de page ne demande Affichage selon l'appareil
	// que si un élément vise un appareil, et Rapport carbone attend Statistiques, éteint par défaut.
	$fluffless_new_site = static function (): array {
		return array();
	};
	add_filter( 'pre_option_' . Options::NAME, $fluffless_new_site );
	Options::flush();
	Loader::boot_modules( Loader::modules() );
	$fluffless_assert( array() === Loader::unmet(), 'P5-02 : site neuf en mode réduit : ' . wp_json_encode( Loader::unmet() ) );
	remove_filter( 'pre_option_' . Options::NAME, $fluffless_new_site );
	$fluffless_mobile_only = static function (): array {
		return array(
			'modules' => array(
				'audit'      => true,
				'footer'     => true,
				'visibility' => false,
			),
			'footer'  => array( 'credit_device' => 'mobile' ),
		);
	};
	add_filter( 'pre_option_' . Options::NAME, $fluffless_mobile_only );
	Options::flush();
	Loader::boot_modules( Loader::modules() );
	$fluffless_assert( array( 'visibility' ) === ( Loader::unmet()['footer'] ?? null ), 'P5-02 : un élément réservé au mobile doit encore demander Affichage selon l\'appareil : ' . wp_json_encode( Loader::unmet() ) );
	remove_filter( 'pre_option_' . Options::NAME, $fluffless_mobile_only );
	Loader::reset();
	Options::flush();
	Loader::boot_modules( Loader::modules() );

	// 3. Nettoyage : aucun émoji ni lien hérité dans l'en-tête rendu.
	if ( ! in_array( 'cleanup', Loader::active(), true ) ) {
		( new Cleanup() )->register();
	}
	// WP-CLI ne définit pas SERVER_NAME, que wp_head() lit.
	if ( ! isset( $_SERVER['SERVER_NAME'] ) ) {
		$_SERVER['SERVER_NAME'] = 'localhost';
	}
	ob_start();
	wp_head();
	$fluffless_head = (string) ob_get_clean();
	foreach ( array( 'wp-emoji', '_wpemojiSettings', 'emoji', 's.w.org', 'EditURI', 'wlwmanifest', 'name="generator"', 'oembed', 'comments-rss2', 'rel=\'shortlink\'' ) as $fluffless_needle ) {
		$fluffless_assert( ! str_contains( $fluffless_head, $fluffless_needle ), 'nettoyage : « ' . $fluffless_needle . ' » encore présent dans wp_head' );
	}
	$fluffless_assert( str_contains( $fluffless_head, 'feed=rss2' ) || str_contains( $fluffless_head, '/feed/' ), 'nettoyage : flux RSS principal retiré à tort' );
	$fluffless_assert( false === has_action( 'wp_head', 'print_emoji_detection_script' ), 'nettoyage : détection des émojis encore accrochée' );
	$fluffless_assert( false === apply_filters( 'xmlrpc_enabled', true ) && array() === apply_filters( 'xmlrpc_methods', array( 'a' => 'b' ) ), 'nettoyage : XML-RPC encore actif' );
	$fluffless_assert( array( 'x' ) === Cleanup::drop_tinymce_emoji( array( 'wpemoji', 'x' ) ), 'nettoyage : extension TinyMCE des émojis conservée' );
	$fluffless_assert( array() === Cleanup::drop_emoji_prefetch( array( 'https://s.w.org/images/core/emoji/' ), 'dns-prefetch' ), 'nettoyage : prefetch s.w.org conservé' );

	// Heartbeat : jamais désinscrit dans l'administration (wp-auth-check en dépend), ralenti hors des éditeurs.
	$fluffless_assert( false === has_action( 'admin_enqueue_scripts', array( Cleanup::class, 'deregister_heartbeat_outside_editor' ) ), 'nettoyage : heartbeat encore désinscrit dans l\'administration' );
	$fluffless_assert( false !== has_action( 'wp_enqueue_scripts', array( Cleanup::class, 'deregister_heartbeat' ) ), 'nettoyage : heartbeat gardé côté visiteur' );
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	$fluffless_pagenow = $GLOBALS['pagenow'] ?? null;
	set_current_screen( 'dashboard' );
	$GLOBALS['pagenow'] = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'un écran d'administration.
	$fluffless_beat     = apply_filters( 'heartbeat_settings', array() );
	$fluffless_assert( is_array( $fluffless_beat ) && Cleanup::HEARTBEAT_SLOW === ( $fluffless_beat['interval'] ?? null ), 'nettoyage : heartbeat non ralenti hors éditeur : ' . wp_json_encode( $fluffless_beat ) );
	$GLOBALS['pagenow'] = 'post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation de l'éditeur.
	$fluffless_beat     = apply_filters( 'heartbeat_settings', array() );
	$fluffless_assert( is_array( $fluffless_beat ) && ! isset( $fluffless_beat['interval'] ), 'nettoyage : heartbeat ralenti dans l\'éditeur (autosauvegarde)' );
	$GLOBALS['pagenow'] = $fluffless_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restauration.
	unset( $GLOBALS['current_screen'] );

	// XML-RPC gardé quand Jetpack en a besoin, avec une note dans l'état du module.
	remove_all_filters( 'xmlrpc_enabled' );
	add_filter( 'fluffless_xmlrpc_needed', '__return_true' );
	( new Cleanup() )->register();
	$fluffless_assert( true === apply_filters( 'xmlrpc_enabled', true ) && str_contains( Cleanup::status(), 'Jetpack' ), 'nettoyage : XML-RPC coupé malgré Jetpack, ou état muet' );
	remove_filter( 'fluffless_xmlrpc_needed', '__return_true' );
	$fluffless_assert( '' === Cleanup::status(), 'nettoyage : note Jetpack affichée sans Jetpack' );

	// En-têtes de sécurité : absents par défaut ; sur demande, ajoutés sans écraser une valeur déjà posée.
	$fluffless_headers = apply_filters( 'wp_headers', array() );
	$fluffless_assert( is_array( $fluffless_headers ) && ! isset( $fluffless_headers['X-Frame-Options'] ), 'nettoyage : en-têtes de sécurité envoyés par défaut' );
	$fluffless_secure = static fn(): array => array( 'cleanup' => array( 'security_headers' => true ) );
	add_filter( 'pre_option_' . Options::NAME, $fluffless_secure );
	Options::flush();
	( new Cleanup() )->register();
	$fluffless_headers = apply_filters( 'wp_headers', array( 'X-Frame-Options' => 'DENY' ) );
	$fluffless_assert(
		is_array( $fluffless_headers ) && 'nosniff' === ( $fluffless_headers['X-Content-Type-Options'] ?? null ) && 'strict-origin-when-cross-origin' === ( $fluffless_headers['Referrer-Policy'] ?? null ) && 'DENY' === ( $fluffless_headers['X-Frame-Options'] ?? null ),
		'nettoyage : en-têtes de sécurité absents ou valeur existante écrasée : ' . wp_json_encode( $fluffless_headers )
	);
	$fluffless_assert( false !== has_action( 'send_headers', array( Cleanup::class, 'remove_powered_by' ) ), 'nettoyage : X-Powered-By non retiré' );
	remove_filter( 'pre_option_' . Options::NAME, $fluffless_secure );
	remove_filter( 'wp_headers', array( Cleanup::class, 'security_headers' ) );
	Options::flush();

	// 4. Images : format de sortie, tailles, seuils d'envoi.
	if ( ! in_array( 'images', Loader::active(), true ) ) {
		( new Images() )->register();
	}
	$fluffless_webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	$fluffless_map  = apply_filters( 'image_editor_output_format', array(), 'photo.jpg', 'image/jpeg' );
	$fluffless_assert( ! $fluffless_webp || ( 'image/webp' === ( $fluffless_map['image/jpeg'] ?? null ) && 'image/webp' === ( $fluffless_map['image/png'] ?? null ) ), 'images : JPEG/PNG non convertis en WebP : ' . wp_json_encode( $fluffless_map ) );
	$fluffless_assert( ! isset( $fluffless_map['image/gif'] ), 'images : GIF converti (animation perdue)' );
	if ( $fluffless_webp && function_exists( 'imagecreatetruecolor' ) ) {
		$fluffless_src = wp_tempnam( 'fluffless-test.jpg' ) . '.jpg';
		$fluffless_gd  = imagecreatetruecolor( 64, 48 );
		if ( false !== $fluffless_gd ) {
			imagejpeg( $fluffless_gd, $fluffless_src );
			$fluffless_editor = wp_get_image_editor( $fluffless_src );
			$fluffless_saved  = is_wp_error( $fluffless_editor ) ? $fluffless_editor : $fluffless_editor->save();
			$fluffless_assert( is_array( $fluffless_saved ) && 'image/webp' === $fluffless_saved['mime-type'], 'images : fichier redimensionné pas en WebP : ' . wp_json_encode( is_wp_error( $fluffless_saved ) ? $fluffless_saved->get_error_message() : $fluffless_saved ) );
			if ( is_array( $fluffless_saved ) ) {
				wp_delete_file( $fluffless_saved['path'] );
			}
			wp_delete_file( $fluffless_src );
		}
	}
	$fluffless_sizes = apply_filters(
		'intermediate_image_sizes_advanced',
		array(
			'thumbnail'    => array(),
			'medium_large' => array(),
			'2048x2048'    => array(),
			'theme-card'   => array(),
		)
	);
	$fluffless_assert( array( 'thumbnail', 'theme-card' ) === array_keys( $fluffless_sizes ), 'images : tailles retirées inattendues : ' . wp_json_encode( array_keys( $fluffless_sizes ) ) );

	wp_set_current_user( $fluffless_admin );
	$fluffless_file = array(
		'name'     => 'lourde.jpg',
		'type'     => 'image/jpeg',
		'size'     => 3 * MB_IN_BYTES,
		'tmp_name' => '',
		'error'    => 0,
	);
	// Par défaut, rien n'est refusé : sans fichier à réduire, l'image passe avec un message.
	$fluffless_out = Images::check_upload( $fluffless_file );
	$fluffless_assert( is_array( $fluffless_out ) && 0 === $fluffless_out['error'], 'images : image de 3 Mo refusée en mode réduction' );
	$fluffless_assert( 1 === count( Images::notices()[ $fluffless_admin ] ?? array() ), 'images : aucun message pour une image lourde non réduite' );
	delete_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES );
	$fluffless_images = get_option( Options::NAME );
	$fluffless_images = is_array( $fluffless_images ) ? $fluffless_images : array();
	update_option( Options::NAME, array_merge( $fluffless_images, array( 'images' => array_merge( Images::defaults(), array( 'oversize' => 'refuse' ) ) ) ) );
	Options::flush();
	$fluffless_out = Images::check_upload( $fluffless_file );
	$fluffless_assert( is_array( $fluffless_out ) && is_string( $fluffless_out['error'] ) && '' !== $fluffless_out['error'], 'images : image de 3 Mo acceptée en mode refus' );
	$fluffless_file['size'] = 500 * KB_IN_BYTES;
	$fluffless_out          = Images::check_upload( $fluffless_file );
	$fluffless_assert( is_array( $fluffless_out ) && 0 === $fluffless_out['error'], 'images : image de 500 ko refusée' );
	$fluffless_assert( 1 === count( Images::notices()[ $fluffless_admin ] ?? array() ), 'images : aucun avertissement gardé pour 500 ko' );
	ob_start();
	Images::print_notices();
	$fluffless_notice = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_notice, 'notice-warning' ) && array() === ( Images::notices()[ $fluffless_admin ] ?? array() ), 'images : avertissement non affiché ou non oublié' );
	$fluffless_pdf = Images::check_upload(
		array_merge(
			$fluffless_file,
			array(
				'type' => 'application/pdf',
				'size' => 9 * MB_IN_BYTES,
			)
		)
	);
	$fluffless_assert( is_array( $fluffless_pdf ) && 0 === $fluffless_pdf['error'], 'images : PDF de 9 Mo refusé par le module Images' );
	// Vidéo lourde : jamais refusée, même en mode refus ; un message avec la recette d'encodage au-dessus du seuil.
	delete_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES );
	$fluffless_clip = Images::check_upload(
		array_merge(
			$fluffless_file,
			array(
				'name' => 'clip.mp4',
				'type' => 'video/mp4',
				'size' => 30 * MB_IN_BYTES,
			)
		)
	);
	$fluffless_assert( is_array( $fluffless_clip ) && 0 === $fluffless_clip['error'], 'images : vidéo de 30 Mo refusée' );
	$fluffless_notices = Images::notices()[ $fluffless_admin ] ?? array();
	$fluffless_assert( 1 === count( $fluffless_notices ) && str_contains( $fluffless_notices[0], '30.0 MB' ) && str_contains( $fluffless_notices[0], 'HandBrake' ) && str_contains( $fluffless_notices[0], '1080p' ), 'images : recette d’encodage absente pour une vidéo lourde : ' . wp_json_encode( $fluffless_notices ) );
	delete_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES );
	$fluffless_clip = Images::check_upload(
		array_merge(
			$fluffless_file,
			array(
				'name' => 'clip.webm',
				'type' => 'video/webm',
				'size' => 5 * MB_IN_BYTES,
			)
		)
	);
	$fluffless_assert( is_array( $fluffless_clip ) && 0 === $fluffless_clip['error'] && array() === ( Images::notices()[ $fluffless_admin ] ?? array() ), 'images : message pour une vidéo de 5 Mo' );
	$fluffless_assert( 20 * MB_IN_BYTES === Images::video_warn_bytes() && str_contains( Images::video_recipe(), 'AV1' ), 'images : seuil vidéo par défaut ou recette inattendus' );

	// Seuil lu dans le fichier : type générique déclaré, puis envoi sans « size » (import, WP-CLI, media_sideload_image).
	$fluffless_png = wp_tempnam( 'fluffless-lourde.png' );
	// PNG 1 x 1 réel suivi de 3 Mo de remplissage : reconnu comme image, lourd sur le disque.
	file_put_contents( $fluffless_png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) . str_repeat( "\0", 3 * MB_IN_BYTES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fichier temporaire de test, image connue.
	$fluffless_generic = Images::check_upload(
		array(
			'name'     => 'lourde.png',
			'type'     => 'application/octet-stream',
			'size'     => (int) filesize( $fluffless_png ),
			'tmp_name' => $fluffless_png,
			'error'    => 0,
		)
	);
	$fluffless_assert( is_array( $fluffless_generic ) && is_string( $fluffless_generic['error'] ) && '' !== $fluffless_generic['error'], 'images : image de 3 Mo acceptée sous un type générique' );
	$fluffless_sideload = Images::check_upload(
		array(
			'name'     => 'lourde.png',
			'type'     => 'image/png',
			'tmp_name' => $fluffless_png,
			'error'    => 0,
		)
	);
	$fluffless_assert( is_array( $fluffless_sideload ) && is_string( $fluffless_sideload['error'] ) && '' !== $fluffless_sideload['error'], 'images : taille non lue sur le disque sans « size » (import)' );
	$fluffless_unreadable = Images::check_upload(
		array(
			'name'     => 'x.png',
			'type'     => 'image/png',
			'tmp_name' => '/inexistant/x.png',
			'error'    => 0,
		)
	);
	$fluffless_assert( is_array( $fluffless_unreadable ) && 0 === $fluffless_unreadable['error'], 'images : fichier illisible refusé sans preuve de son poids' );

	// Mode réduction (défaut) : la même image lourde est réécrite en place, plus légère, et acceptée.
	update_option( Options::NAME, array_merge( $fluffless_images, array( 'images' => Images::defaults() ) ) );
	Options::flush();
	delete_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES );
	$fluffless_before = (int) filesize( $fluffless_png );
	$fluffless_shrunk = Images::check_upload(
		array(
			'name'     => 'lourde.png',
			'type'     => 'image/png',
			'size'     => $fluffless_before,
			'tmp_name' => $fluffless_png,
			'error'    => 0,
		)
	);
	clearstatcache( true, $fluffless_png );
	$fluffless_assert( is_array( $fluffless_shrunk ) && 0 === $fluffless_shrunk['error'], 'images : image lourde refusée en mode réduction' );
	$fluffless_assert( is_array( $fluffless_shrunk ) && $fluffless_shrunk['size'] < 100 * KB_IN_BYTES && (int) filesize( $fluffless_png ) === $fluffless_shrunk['size'], 'images : image non réduite en place (' . (int) filesize( $fluffless_png ) . ' octets)' );
	$fluffless_assert( 'image/png' === wp_check_filetype_and_ext( $fluffless_png, 'lourde.png' )['type'], 'images : format changé par la réduction' );
	$fluffless_assert( str_contains( implode( ' ', Images::notices()[ $fluffless_admin ] ?? array() ), 'reduced' ), 'images : réduction non annoncée' );
	wp_delete_file( $fluffless_png );

	// 5. Visibilité : classe rendue, CSS chargé seulement si utilisé, valeur hors liste ignorée.
	$fluffless_plain = do_blocks( '<!-- wp:paragraph --><p>Partout</p><!-- /wp:paragraph -->' );
	$fluffless_assert( ! str_contains( $fluffless_plain, 'fluffless-hide' ), 'visibilité : classe sur un bloc sans réglage' );
	$fluffless_assert( ! wp_style_is( 'fluffless-visibility', 'enqueued' ), 'visibilité : CSS chargé sans bloc qui l\'utilise' );
	if ( ! in_array( 'visibility', Loader::active(), true ) ) {
		( new Visibility() )->register();
	}
	$fluffless_html       = do_blocks( '<!-- wp:paragraph {"flufflessVisibility":"desktop"} --><p class="has-x">Ordinateur</p><!-- /wp:paragraph --><!-- wp:paragraph {"flufflessVisibility":"mobile"} --><p>Mobile</p><!-- /wp:paragraph -->' );
	$fluffless_classes_of = static function ( string $html, string $text ): array {
		return 1 === preg_match( '#<p class="([^"]*)">' . preg_quote( $text, '#' ) . '<#', $html, $m ) ? explode( ' ', $m[1] ) : array();
	};
	$fluffless_desktop    = $fluffless_classes_of( $fluffless_html, 'Ordinateur' );
	$fluffless_assert( in_array( 'fluffless-hide-mobile', $fluffless_desktop, true ) && in_array( 'has-x', $fluffless_desktop, true ), 'visibilité : classe fluffless-hide-mobile absente ou classe existante perdue : ' . $fluffless_html );
	$fluffless_assert( in_array( 'fluffless-hide-desktop', $fluffless_classes_of( $fluffless_html, 'Mobile' ), true ), 'visibilité : classe fluffless-hide-desktop absente : ' . $fluffless_html );
	$fluffless_assert( wp_style_is( 'fluffless-visibility', 'enqueued' ), 'visibilité : CSS non chargé' );
	$fluffless_assert( str_contains( Visibility::css(), '(min-width:782px)' ) && str_contains( Visibility::css(), '(max-width:781.98px)' ), 'visibilité : point de rupture inattendu : ' . Visibility::css() );
	$fluffless_bad = do_blocks( '<!-- wp:paragraph {"flufflessVisibility":"tablette\" onclick=\"x"} --><p>X</p><!-- /wp:paragraph -->' );
	$fluffless_assert( ! str_contains( $fluffless_bad, 'fluffless-hide' ) && ! str_contains( $fluffless_bad, 'onclick' ), 'visibilité : valeur hors liste rendue : ' . $fluffless_bad );
	$fluffless_args = Visibility::add_attribute( array(), 'core/paragraph' );
	$fluffless_assert( is_array( $fluffless_args ) && 'string' === ( $fluffless_args['attributes'][ Visibility::ATTRIBUTE ]['type'] ?? null ), 'visibilité : attribut non déclaré au serveur' );

	// 6. Liste blanche HTTP : hôtes autorisés, refus journalisé.
	delete_transient( Lifecycle::TRANSIENT_HTTP_REFUSALS );
	$fluffless_http = new Http();
	$fluffless_http->register();
	$fluffless_assert( false === apply_filters( 'pre_http_request', false, array(), 'https://api.wordpress.org/core/version-check/1.7/' ), 'http : sous-domaine de wordpress.org bloqué' );
	$fluffless_assert( false === apply_filters( 'pre_http_request', false, array(), home_url( '/wp-cron.php' ) ), 'http : le site lui-même bloqué' );
	$fluffless_blocked = apply_filters( 'pre_http_request', false, array(), 'https://tracker.example.com/pixel' );
	$fluffless_assert( is_wp_error( $fluffless_blocked ) && 'fluffless_http_blocked' === $fluffless_blocked->get_error_code(), 'http : domaine hors liste non bloqué' );
	$fluffless_assert( 1 === ( Http::refusals()['tracker.example.com']['count'] ?? 0 ), 'http : refus non journalisé' );
	$fluffless_assert( ! Http::is_allowed( 'evilwordpress.org', array( 'wordpress.org' ) ), 'http : suffixe sans point accepté' );
	remove_filter( 'pre_http_request', array( $fluffless_http, 'filter' ) );

	// 7. Page de réglages : rendu, nonce, capacité ; API REST.
	Options::register();
	SettingsPage::register_fields();
	wp_set_current_user( 0 );
	ob_start();
	SettingsPage::render();
	$fluffless_assert( '' === ob_get_clean(), 'réglages : écran rendu sans la capacité manage_options' );
	wp_set_current_user( $fluffless_admin );
	ob_start();
	SettingsPage::render();
	$fluffless_page = (string) ob_get_clean();
	foreach ( array(
		'<h1>' . esc_html__( 'Modules', 'fluffless-suite' ) . '</h1>',
		'action="' . admin_url( 'options.php' ) . '"',
		"name='option_page' value='fluffless'",
		'name="_wpnonce"',
		'name="fluffless_settings[modules][cleanup]"',
		'name="fluffless_settings[cleanup][xmlrpc]"',
		'name="fluffless_settings[images][remove_sizes][]"',
		'name="fluffless_settings[images][output_format]"',
		'name="fluffless_settings[http][allowed_hosts]"',
		'for="fluffless-images-warn-kb"',
		'<caption><h2 class="fl-modules__title">',
		'<th scope="row">',
	) as $fluffless_needle ) {
		$fluffless_assert( str_contains( $fluffless_page, $fluffless_needle ), 'réglages : « ' . $fluffless_needle . ' » absent du rendu' );
	}
	$fluffless_assert( 1 === substr_count( $fluffless_page, 'fluff</em>' ), 'réglages : plus d\'une touche d\'humour sur l\'écran' );

	$fluffless_rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );
	$fluffless_data = $fluffless_rest->get_data();
	$fluffless_assert( is_array( $fluffless_data ) && is_array( $fluffless_data[ Options::NAME ]['modules'] ?? null ), 'rest : fluffless_settings absent de /wp/v2/settings' );
	$fluffless_post = new WP_REST_Request( 'POST', '/wp/v2/settings' );
	$fluffless_post->set_body_params( array( Options::NAME => array( 'images' => array( 'output_format' => 'gif' ) ) ) );
	$fluffless_assert( 400 === rest_do_request( $fluffless_post )->get_status(), 'rest : format « gif » accepté par le schéma' );

	// 8. Activation puis désinstallation : option créée, puis supprimée avec les transitoires.
	delete_option( Options::NAME );
	Lifecycle::activate();
	$fluffless_autoload = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", Options::NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lecture de contrôle, aucune API ne rend la colonne autoload.
	$fluffless_assert( in_array( $fluffless_autoload, array( 'yes', 'on', 'auto-on' ), true ), 'activation : option absente ou non chargée automatiquement (autoload : ' . wp_json_encode( $fluffless_autoload ) . ')' );
	set_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES, array( 1 => array( 'x' ) ), 60 );
	// Transitoire au nom construit, comme ceux de la limite de débit des statistiques.
	set_transient( 'fluffless_stats_rl_test', 1, 60 );
	// Modules Forme, Médias, Minify, Pexels : données de l'instance de dev sauvegardées, remises dans finally.
	$fluffless_extra_backup  = array(
		Lifecycle::OPTION_SHAPE       => get_option( Lifecycle::OPTION_SHAPE, null ),
		Lifecycle::OPTION_WEBP_REPORT => get_option( Lifecycle::OPTION_WEBP_REPORT, null ),
	);
	$fluffless_studio_backup = get_user_meta( $fluffless_admin, Lifecycle::USER_META_STUDIO, true );
	$fluffless_mode_backup   = metadata_exists( 'user', $fluffless_admin, Lifecycle::USER_META_EDITOR_MODE ) ? get_user_meta( $fluffless_admin, Lifecycle::USER_META_EDITOR_MODE, true ) : '';
	update_option( Lifecycle::OPTION_SHAPE, array( 'test' => 1 ) );
	update_option( Lifecycle::OPTION_WEBP_REPORT, array( 'test' => 1 ) );
	update_user_meta( $fluffless_admin, Lifecycle::USER_META_STUDIO, '1' );
	update_user_meta( $fluffless_admin, Lifecycle::USER_META_EDITOR_MODE, 'full' );
	set_transient( Lifecycle::TRANSIENT_MINIFY, array( 'test' => 1 ), 60 );
	set_transient( 'fluffless_pexels_test', array( 'test' => 1 ), 60 );
	// Donnée de l'utilisatrice : un média en quarantaine garde sa métadonnée de restauration.
	$fluffless_quarantined = wp_insert_attachment(
		array(
			'post_title'     => 'Quarantaine de test',
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		),
		false,
		0,
		true
	);
	$fluffless_quarantined = is_int( $fluffless_quarantined ) ? $fluffless_quarantined : 0;
	update_post_meta( $fluffless_quarantined, Quarantine::META, array( 'kind' => 'attachment' ) );
	// Section de Fluffless Consent : elle doit survivre à la désinstallation de Suite.
	update_option( Options::NAME, array_merge( (array) get_option( Options::NAME, array() ), array( 'consent' => array( 'test' => 1 ) ) ) );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'fluffless-suite/fluffless-suite.php' );
	}
	include dirname( __DIR__ ) . '/uninstall.php';
	wp_cache_delete( 'alloptions', 'options' );
	$fluffless_assert( array( 'consent' ) === array_keys( (array) get_option( Options::NAME, array() ) ), 'désinstallation : fluffless_settings devrait ne garder que la section de Fluffless Consent, reçu : ' . wp_json_encode( get_option( Options::NAME, false ) ) );
	delete_option( Options::NAME );
	$fluffless_assert( false === get_transient( Lifecycle::TRANSIENT_HTTP_REFUSALS ) && false === get_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES ), 'désinstallation : transitoires encore présents' );
	$fluffless_left = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s", $GLOBALS['wpdb']->esc_like( '_transient_fluffless_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- contrôle en base, le cache ne dit rien des lignes restantes.
	$fluffless_assert( 0 === $fluffless_left, 'désinstallation : ' . $fluffless_left . ' transitoire(s) fluffless_ encore en base (limite de débit, etc.)' );
	$fluffless_assert( false === get_option( Lifecycle::OPTION_SHAPE, false ) && false === get_option( Lifecycle::OPTION_WEBP_REPORT, false ), 'désinstallation : fluffless_shape ou fluffless_webp_report encore présente' );
	$fluffless_assert( '' === get_user_meta( $fluffless_admin, Lifecycle::USER_META_STUDIO, true ), 'désinstallation : métadonnée fluffless_studio encore présente' );
	$fluffless_assert( ! metadata_exists( 'user', $fluffless_admin, Lifecycle::USER_META_EDITOR_MODE ), 'désinstallation : métadonnée fluffless_editor_mode encore présente' );
	$fluffless_assert( false === get_transient( Lifecycle::TRANSIENT_MINIFY ) && false === get_transient( 'fluffless_pexels_test' ), 'désinstallation : transitoire Minify ou Pexels encore présent' );
	$fluffless_assert( $fluffless_quarantined > 0 && null !== get_post( $fluffless_quarantined ) && '' !== get_post_meta( $fluffless_quarantined, Quarantine::META, true ), 'désinstallation : média en quarantaine ou sa métadonnée de restauration supprimés (données de l\'utilisatrice)' );
} finally {
	if ( isset( $fluffless_extra_backup ) ) {
		foreach ( $fluffless_extra_backup as $fluffless_name => $fluffless_value ) {
			if ( null === $fluffless_value ) {
				delete_option( $fluffless_name );
			} else {
				update_option( $fluffless_name, $fluffless_value );
			}
		}
	}
	if ( isset( $fluffless_studio_backup ) && '' !== $fluffless_studio_backup ) {
		update_user_meta( $fluffless_admin, Lifecycle::USER_META_STUDIO, $fluffless_studio_backup );
	}
	if ( isset( $fluffless_mode_backup ) && '' !== $fluffless_mode_backup ) {
		update_user_meta( $fluffless_admin, Lifecycle::USER_META_EDITOR_MODE, $fluffless_mode_backup );
	}
	if ( ! empty( $fluffless_quarantined ) ) {
		wp_delete_post( $fluffless_quarantined, true );
	}
	if ( null === $fluffless_backup ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_backup, true );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}

echo esc_html( 'OK : ' . $fluffless_checks . " contrôles réussis (Fluffless Suite : options, loader, nettoyage, images, visibilité, http, réglages, désinstallation).\n" );
