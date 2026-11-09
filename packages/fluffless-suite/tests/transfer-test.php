<?php
/**
 * Tests de l'export et de l'import des réglages : adresse de connexion absente du fichier,
 * module Sécurité jamais activé sans confirmation, aperçu avant application, état précédent gardé et annulation.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/transfer-test.php
 * Les réglages sont remis à leur état de départ à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Admin\Transfer;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Security;

defined( 'ABSPATH' ) || exit;
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

$fluffless_backup_option = get_option( Options::NAME );
$fluffless_backup_prev   = get_option( 'fluffless_transfer_previous' );

/**
 * Remplace l'option des réglages et vide le cache.
 *
 * @param array<string, mixed> $value Valeur stockée.
 */
$fluffless_set = static function ( array $value ): void {
	update_option( Options::NAME, $value );
	Options::flush();
};

$fluffless_secret = 'entree-secrete-du-test';
$fluffless_set(
	array(
		'version'  => Options::SCHEMA,
		'modules'  => array( 'security' => false ),
		'security' => array( 'login_slug' => $fluffless_secret ),
	)
);
delete_option( 'fluffless_transfer_previous' );

// 1. C-S05-05 : l'export ne porte pas l'adresse de connexion cachée.
$fluffless_payload = Transfer::payload();
$fluffless_json    = (string) wp_json_encode( $fluffless_payload );
$fluffless_assert( false === strpos( $fluffless_json, $fluffless_secret ), 'Export : l’adresse de connexion cachée est absente du fichier.' );
$fluffless_assert( '' === ( $fluffless_payload['settings']['security']['login_slug'] ?? 'absent' ), 'Export : le champ de l’adresse est vidé.' );

// 2. C-S2-07 : un fichier qui active Sécurité ne l’active pas sans confirmation.
$fluffless_file                                       = $fluffless_payload;
$fluffless_file['settings']['modules']['security']    = true;
$fluffless_file['settings']['security']['login_slug'] = 'adresse-du-fichier';
$fluffless_assert( true === Transfer::apply( $fluffless_file ), 'Import d’un fichier qui active Sécurité.' );
Options::flush();
$fluffless_assert( ! Options::is_enabled( 'security' ), 'Import : Sécurité reste inactif sans confirmation.' );
$fluffless_assert( Security::slug() === $fluffless_secret, 'Import : l’adresse de connexion en place n’est pas remplacée.' );

// 3. C-S05-06 : l’état précédent est gardé, l’annulation le rétablit.
$fluffless_assert( is_array( get_option( 'fluffless_transfer_previous' ) ), 'Import : l’état précédent est conservé.' );
$fluffless_set(
	array(
		'version'  => Options::SCHEMA,
		'modules'  => array( 'security' => false ),
		'security' => array( 'login_slug' => $fluffless_secret ),
	)
);
$fluffless_file2                                = $fluffless_payload;
$fluffless_file2['settings']['modules']['form'] = ! Options::is_enabled( 'form' );
$fluffless_assert( true === Transfer::apply( $fluffless_file2 ), 'Second import.' );
$fluffless_assert( true === Transfer::undo(), 'Annulation du dernier import.' );
Options::flush();
$fluffless_assert( Security::slug() === $fluffless_secret && ! Options::is_enabled( 'security' ), 'Annulation : réglages d’avant l’import rétablis.' );

// 4. Aperçu : ce qui sera réellement écrit, avant toute écriture. Sécurité, sensible, n'est pas annoncé « activé » sans confirmation.
$fluffless_before = get_option( Options::NAME );
$fluffless_plan   = Transfer::plan( $fluffless_file );
$fluffless_assert( is_array( $fluffless_plan ) && ! in_array( 'security', $fluffless_plan['enable'], true ), 'Aperçu : Sécurité n’est pas annoncé « activé » sans confirmation.' );
$fluffless_assert( is_array( $fluffless_plan ) && in_array( 'security', $fluffless_plan['sensitive'], true ) && in_array( 'security', $fluffless_plan['held'], true ), 'Aperçu : Sécurité est sensible et retenu.' );
$fluffless_confirmed_plan = Transfer::plan( $fluffless_file, array( 'security' ) );
$fluffless_assert( is_array( $fluffless_confirmed_plan ) && in_array( 'security', $fluffless_confirmed_plan['enable'], true ) && array() === $fluffless_confirmed_plan['held'], 'Aperçu avec confirmation : Sécurité activé, plus rien de retenu.' );
$fluffless_assert( get_option( Options::NAME ) === $fluffless_before, 'Aperçu : aucune écriture.' );

// 4b. Objection 5 : plan() annonce exactement ce qu'apply() écrit, avec et sans confirmation.
foreach ( array( array(), array( 'security' ) ) as $fluffless_conf ) {
	$fluffless_set(
		array(
			'version'  => Options::SCHEMA,
			'modules'  => array( 'security' => false ),
			'security' => array( 'login_slug' => $fluffless_secret ),
		)
	);
	$fluffless_from = Options::all();
	$fluffless_pl   = Transfer::plan( $fluffless_file, $fluffless_conf );
	$fluffless_assert( true === Transfer::apply( $fluffless_file, $fluffless_conf ), 'plan/apply : import appliqué.' );
	Options::flush();
	$fluffless_to  = Options::all();
	$fluffless_en  = array();
	$fluffless_dis = array();
	foreach ( $fluffless_to['modules'] as $fluffless_id => $fluffless_on ) {
		if ( $fluffless_on && empty( $fluffless_from['modules'][ $fluffless_id ] ) ) {
			$fluffless_en[] = $fluffless_id;
		} elseif ( ! $fluffless_on && ! empty( $fluffless_from['modules'][ $fluffless_id ] ) ) {
			$fluffless_dis[] = $fluffless_id;
		}
	}
	$fluffless_changed = array();
	foreach ( $fluffless_to as $fluffless_id => $fluffless_value ) {
		if ( 'modules' !== $fluffless_id && 'version' !== $fluffless_id && ( $fluffless_from[ $fluffless_id ] ?? null ) !== $fluffless_value ) {
			$fluffless_changed[] = (string) $fluffless_id;
		}
	}
	$fluffless_tag = array() === $fluffless_conf ? 'sans confirmation' : 'avec confirmation';
	$fluffless_assert( is_array( $fluffless_pl ) && $fluffless_pl['enable'] === $fluffless_en, 'plan/apply (' . $fluffless_tag . ') : modules activés identiques.' );
	$fluffless_assert( is_array( $fluffless_pl ) && $fluffless_pl['disable'] === $fluffless_dis, 'plan/apply (' . $fluffless_tag . ') : modules éteints identiques.' );
	$fluffless_assert( is_array( $fluffless_pl ) && $fluffless_pl['sections'] === $fluffless_changed, 'plan/apply (' . $fluffless_tag . ') : sections modifiées identiques (annoncé : ' . wp_json_encode( is_array( $fluffless_pl ) ? $fluffless_pl['sections'] : null ) . ', écrit : ' . wp_json_encode( $fluffless_changed ) . ').' );
}

// 4c. Ce que le fichier laisse de côté reste tel quel (le texte de l'aperçu le dit).
$fluffless_set(
	array(
		'version'  => Options::SCHEMA,
		'modules'  => array(
			'security' => false,
			'form'     => true,
		),
		'security' => array( 'login_slug' => $fluffless_secret ),
	)
);
$fluffless_partial = array(
	'fluffless_suite' => '0',
	'schema'          => Options::SCHEMA,
	'settings'        => array( 'modules' => array( 'security' => false ) ),
);
$fluffless_assert( true === Transfer::apply( $fluffless_partial ), 'Fichier partiel appliqué.' );
Options::flush();
$fluffless_assert( Options::is_enabled( 'form' ), 'Fichier partiel : un module absent du fichier garde son état.' );
$fluffless_plan_a = Transfer::plan( $fluffless_file );
$fluffless_lines  = is_array( $fluffless_plan_a ) ? Transfer::preview_lines( $fluffless_plan_a ) : array();
$fluffless_text   = implode( "\n", $fluffless_lines );
$fluffless_assert( false !== strpos( $fluffless_text, 'leaves out stay as they are' ) && false === strpos( $fluffless_text, 'defaults' ), 'Aperçu : le texte dit que l’absent reste tel quel (plus « retourne aux défauts »).' );
$fluffless_assert( false !== strpos( $fluffless_text, 'unless you confirm it' ), 'Aperçu : le module sensible retenu est nommé.' );
$fluffless_days = (string) ( Transfer::UNDO_TTL / DAY_IN_SECONDS );
$fluffless_assert( false !== strpos( $fluffless_text, 'kept for ' . $fluffless_days . ' days' ), 'Aperçu : le délai annoncé est celui du code (' . $fluffless_days . ' jours).' );

// 4d. Date de l'export dans l'aperçu, et gabarits comptés après assainissement (pas le nombre brut du fichier).
$fluffless_dated                   = $fluffless_file;
$fluffless_dated['exported']       = '2026-10-01T12:00:00Z';
$fluffless_dated['site']           = 'https://exemple.test';
$fluffless_dated['page_templates'] = array(
	array(
		'id'      => 'bon',
		'title'   => 'Bon gabarit',
		'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
	),
	array(
		'id'      => 'sans-bloc',
		'title'   => 'Sans bloc',
		'content' => 'du texte sans bloc',
	),
);
$fluffless_dated_plan              = Transfer::plan( $fluffless_dated );
$fluffless_assert( is_array( $fluffless_dated_plan ) && 1 === $fluffless_dated_plan['templates'], 'Aperçu : un gabarit valide sur deux, le compte annoncé est celui qui sera gardé.' );
$fluffless_assert( is_array( $fluffless_dated_plan ) && false !== strpos( implode( ' ', Transfer::preview_lines( $fluffless_dated_plan ) ), 'https://exemple.test' ), 'Aperçu : la date et le site du fichier figurent.' );

// 4e. Annulation avec expiration : l'état gardé sert 7 jours, puis il s'efface.
update_option(
	Transfer::PREVIOUS,
	array(
		'at'        => gmdate( 'Y-m-d\TH:i:s\Z', time() - Transfer::UNDO_TTL - 60 ),
		'settings'  => array(),
		'templates' => array(),
	),
	false
);
$fluffless_assert( null === Transfer::previous() && false === get_option( Transfer::PREVIOUS, false ), 'Annulation : l’état de plus de 7 jours est effacé.' );
$fluffless_expired = Transfer::undo();
$fluffless_assert( is_wp_error( $fluffless_expired ) && 'nothing' === $fluffless_expired->get_error_code(), 'Annulation expirée : rien à rétablir.' );
update_option(
	Transfer::PREVIOUS,
	array(
		'at'        => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ),
		'settings'  => array(),
		'templates' => array(),
	),
	false
);
$fluffless_assert( is_array( Transfer::previous() ), 'Annulation : l’état d’hier est encore disponible.' );
$fluffless_admin_ids = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$fluffless_assert( isset( $fluffless_admin_ids[0] ), 'Un compte administrateur existe pour le test de l’écran.' );
ob_start();
if ( isset( $fluffless_admin_ids[0] ) ) {
	wp_set_current_user( (int) $fluffless_admin_ids[0] );
	Transfer::render();
	wp_set_current_user( 0 );
}
$fluffless_screen = (string) ob_get_clean();
$fluffless_assert( false !== strpos( $fluffless_screen, 'available until' ) && false !== strpos( $fluffless_screen, 'saved on' ), 'Écran : le bouton d’annulation montre la date de l’instantané et celle d’expiration.' );
delete_option( Transfer::PREVIOUS );

// 5. Confirmation explicite : Sécurité s’active, l’adresse du fichier n’est jamais reprise.
$fluffless_set(
	array(
		'version'  => Options::SCHEMA,
		'modules'  => array( 'security' => false ),
		'security' => array( 'login_slug' => $fluffless_secret ),
	)
);
$fluffless_assert( true === Transfer::apply( $fluffless_file, array( 'security' ) ), 'Import avec confirmation de Sécurité.' );
Options::flush();
$fluffless_assert( Options::is_enabled( 'security' ), 'Confirmation : Sécurité activé.' );
$fluffless_assert( Security::slug() === $fluffless_secret, 'Confirmation : l’adresse du fichier n’est jamais reprise.' );

// 6. Écran : deux formulaires, avertissement à jour (l’adresse ne voyage plus).
ob_start();
Transfer::render();
$fluffless_html = (string) ob_get_clean();
$fluffless_assert( false === strpos( $fluffless_html, 'travels with the file' ), 'Écran : plus d’avis disant que l’adresse voyage avec le fichier.' );

// 7. Aperçu à l’écran : fichier en attente, case Sécurité décochée, rien d’écrit avant la confirmation.
$fluffless_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$fluffless_admin  = isset( $fluffless_admins[0] ) ? (int) $fluffless_admins[0] : 0;
$fluffless_assert( $fluffless_admin > 0, 'Un compte administrateur existe pour le test.' );
if ( $fluffless_admin > 0 ) {
	wp_set_current_user( $fluffless_admin );
	$fluffless_set(
		array(
			'version'  => Options::SCHEMA,
			'modules'  => array( 'security' => false ),
			'security' => array( 'login_slug' => $fluffless_secret ),
		)
	);
	$fluffless_before = get_option( Options::NAME );
	$fluffless_assert( true === Transfer::stage_json( (string) wp_json_encode( $fluffless_file ) ), 'Fichier mis en attente.' );
	$fluffless_assert( get_option( Options::NAME ) === $fluffless_before, 'Mise en attente : aucune écriture dans les réglages.' );
	ob_start();
	Transfer::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( false !== strpos( $fluffless_html, 'Nothing has been changed yet' ), 'Aperçu affiché.' );
	$fluffless_assert( 1 === preg_match( '/<input type="checkbox" name="fl_confirm_module\[\]" value="security">/', $fluffless_html ), 'Case Sécurité présente et décochée.' );
	$fluffless_assert( false !== strpos( $fluffless_html, Transfer::ACTION_CONFIRM ), 'Formulaire de confirmation.' );
	$fluffless_assert( is_wp_error( Transfer::stage_json( 'pas du json' ) ), 'Un fichier invalide n’est pas mis en attente.' );
	delete_transient( 'fluffless_transfer_stage_' . $fluffless_admin );
	wp_set_current_user( 0 );
}

// Remise à l’état de départ.
if ( false === $fluffless_backup_option ) {
	delete_option( Options::NAME );
} else {
	update_option( Options::NAME, $fluffless_backup_option );
}
if ( false === $fluffless_backup_prev ) {
	delete_option( 'fluffless_transfer_previous' );
} else {
	update_option( 'fluffless_transfer_previous', $fluffless_backup_prev );
}
Options::flush();

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Transfert des réglages : ' . $fluffless_checks . ' vérifications passées.' );
