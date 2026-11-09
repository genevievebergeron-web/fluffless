<?php
/**
 * Tests de la désinstallation : réponses de formulaire effacées par défaut, gardées avec l'option
 * « Garder mes données » ou la constante FLUFFLESS_KEEP_DATA, comptes réviseur ramenés à Abonné.
 *
 * Lancer sur un banc jetable seulement (le test efface des données) :
 *   wp option update fluffless_test_jetable 1
 *   wp eval-file wp-content/plugins/fluffless-suite/tests/lifecycle-jetable.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Form;
use Fluffless\Suite\Modules\Reviewer;

defined( 'ABSPATH' ) || exit;

if ( '1' !== (string) get_option( 'fluffless_test_jetable', '' ) ) {
	throw new RuntimeException( 'Ce test efface des données : banc jetable seulement (option fluffless_test_jetable absente).' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'fluffless-suite/fluffless-suite.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante de WordPress simulée, banc jetable.
}

$fluffless_count = static function (): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Lifecycle::POST_TYPE_RESPONSE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- contrôle en base.
};

$fluffless_seed = static function ( array $form_section ): int {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- remise à zéro du banc jetable.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_type = %s", Lifecycle::POST_TYPE_RESPONSE ) );
	update_option( Options::NAME, array_merge( Options::defaults(), array( 'form' => array_merge( Form::defaults(), $form_section ) ) ) );
	Options::flush();
	for ( $i = 1; $i <= 2; $i++ ) {
		wp_insert_post(
			array(
				'post_type'    => Lifecycle::POST_TYPE_RESPONSE,
				'post_status'  => 'private',
				'post_title'   => 'Réponse ' . $i,
				'post_content' => '{"nom":"Test ' . $i . '"}',
				'meta_input'   => array( Form::META_FORM => 'contact' ),
			)
		);
	}
	if ( null === get_role( Lifecycle::ROLE_REVIEWER ) ) {
		Reviewer::ensure_role();
	}
	$user = wp_insert_user(
		array(
			'user_login' => 'reviseur-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 20 ),
			'user_email' => wp_generate_password( 8, false ) . '@exemple.test',
			'role'       => Lifecycle::ROLE_REVIEWER,
		)
	);
	return is_int( $user ) ? $user : 0;
};

$fluffless_uninstall = static function (): void {
	include dirname( __DIR__ ) . '/uninstall.php';
	wp_cache_flush();
	Options::flush();
};

// 1. Défaut : les réponses sont effacées (comportement annoncé), le compte réviseur garde un rôle.
$fluffless_user = $fluffless_seed( array() );
$fluffless_assert( 2 === $fluffless_count(), 'départ : 2 réponses attendues, reçu ' . $fluffless_count() );
// P5-03 : options, événements et métadonnées laissés après la désinstallation.
$fluffless_orphans = array( 'fluffless_events_slug', 'fluffless_events_uid', 'fluffless_suite_video', 'fluffless_transfer_previous', 'fluffless_fonts' );
foreach ( $fluffless_orphans as $fluffless_option ) {
	update_option( $fluffless_option, 'essai' );
}
$fluffless_event    = wp_insert_post(
	array(
		'post_type'   => 'fluffless_event',
		'post_status' => 'publish',
		'post_title'  => 'Événement d\'essai',
		'meta_input'  => array( '_fluffless_event_previous_start' => '2026-01-01T10:00' ),
	)
);
$fluffless_migrated = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'draft',
		'post_title'  => 'Page migrée',
		'meta_input'  => array(
			'_fluffless_migrated'         => '1',
			'_fluffless_migration_report' => array( 'kept' => 1 ),
			'_fluffless_migration_backup' => array( 'content' => '[et_pb_section][/et_pb_section]' ),
		),
	)
);
update_user_meta( $fluffless_user, 'fluffless_unmet_dismissed', 'essai' );
$fluffless_uninstall();
foreach ( $fluffless_orphans as $fluffless_option ) {
	$fluffless_assert( false === get_option( $fluffless_option, false ), 'P5-03 : l\'option ' . $fluffless_option . ' devrait être effacée' );
}
$fluffless_assert( null === get_post( (int) $fluffless_event ), 'P5-03 : les événements devraient être effacés' );
$fluffless_assert( '' === get_post_meta( (int) $fluffless_migrated, '_fluffless_migrated', true ) && '' === get_post_meta( (int) $fluffless_migrated, '_fluffless_migration_report', true ), 'P5-03 : les métadonnées de suivi de la migration devraient être effacées' );
$fluffless_assert( is_array( get_post_meta( (int) $fluffless_migrated, '_fluffless_migration_backup', true ) ), 'P5-03 : la copie d\'origine d\'une page migrée reste (données du constructeur)' );
$fluffless_assert( '' === get_user_meta( $fluffless_user, 'fluffless_unmet_dismissed', true ), 'P5-03 : la métadonnée d\'utilisateur fluffless_unmet_dismissed devrait être effacée' );
wp_delete_post( (int) $fluffless_migrated, true );
$fluffless_assert( 0 === $fluffless_count(), 'défaut : les réponses devraient être effacées, reste ' . $fluffless_count() );
$fluffless_account = get_userdata( $fluffless_user );
$fluffless_assert( $fluffless_account instanceof WP_User && array( 'subscriber' ) === array_values( $fluffless_account->roles ), 'défaut : le compte réviseur devrait repasser à Abonné, reçu : ' . wp_json_encode( $fluffless_account instanceof WP_User ? $fluffless_account->roles : null ) );
$fluffless_assert( $fluffless_account instanceof WP_User && $fluffless_account->has_cap( 'read' ), 'défaut : le compte réviseur devrait pouvoir lire (capacité read)' );
$fluffless_assert( null === get_role( Lifecycle::ROLE_REVIEWER ), 'défaut : le rôle réviseur devrait être retiré (audit F2)' );
wp_delete_user( $fluffless_user );

// 2. Option « Garder mes données » : rien n'est effacé.
$fluffless_user = $fluffless_seed( array( 'keep_data' => true ) );
update_user_meta( $fluffless_user, Lifecycle::USER_META_STUDIO, '1' );
$fluffless_uninstall();
$fluffless_assert( '1' === get_user_meta( $fluffless_user, Lifecycle::USER_META_STUDIO, true ), 'option : la métadonnée d\'utilisateur devrait rester' );
$fluffless_assert( 2 === $fluffless_count(), 'option : les réponses devraient rester, reçu ' . $fluffless_count() );
$fluffless_assert( false !== get_option( Options::NAME, false ), 'option : fluffless_settings devrait rester' );
$fluffless_assert( null !== get_role( Lifecycle::ROLE_REVIEWER ), 'option : le rôle réviseur devrait rester' );
wp_delete_user( $fluffless_user );

// 4. Avis de suppression dans l'écran Extensions, même quand le module Formulaire est inactif.
$fluffless_user                        = $fluffless_seed( array() );
$fluffless_settings                    = get_option( Options::NAME );
$fluffless_settings['modules']['form'] = false;
update_option( Options::NAME, $fluffless_settings );
Options::flush();
$fluffless_admin = wp_insert_user(
	array(
		'user_login' => 'admin-' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 20 ),
		'user_email' => wp_generate_password( 8, false ) . '@exemple.test',
		'role'       => 'administrator',
	)
);
wp_set_current_user( is_int( $fluffless_admin ) ? $fluffless_admin : 0 );
$fluffless_assert( ! Options::is_enabled( 'form' ), 'avis : le module Formulaire devrait être inactif pour ce contrôle' );
ob_start();
Form::render_deletion_notice();
$fluffless_notice = (string) ob_get_clean();
$fluffless_assert( str_contains( $fluffless_notice, 'FLUFFLESS_KEEP_DATA' ) && str_contains( $fluffless_notice, '2' ), 'avis : module inactif avec 2 réponses, l\'avis devrait nommer la constante et le nombre, reçu : ' . $fluffless_notice );
$fluffless_hook = (bool) has_action( 'after_plugin_row_fluffless-suite/fluffless-suite.php', array( Form::class, 'render_deletion_notice' ) );
$fluffless_assert( $fluffless_hook || ! is_admin(), 'avis : le crochet devrait être posé par le fichier principal, module inactif' );
$fluffless_assert( 2 === $fluffless_count(), 'avis : afficher l\'avis ne doit rien effacer, reçu ' . $fluffless_count() );
wp_delete_user( $fluffless_user );
if ( is_int( $fluffless_admin ) ) {
	wp_delete_user( $fluffless_admin );
}

// 3. Constante FLUFFLESS_KEEP_DATA (dernier : une constante ne se retire pas).
$fluffless_user = $fluffless_seed( array() );
define( 'FLUFFLESS_KEEP_DATA', true );
$fluffless_uninstall();
$fluffless_assert( 2 === $fluffless_count(), 'constante : les réponses devraient rester, reçu ' . $fluffless_count() );
wp_delete_user( $fluffless_user );

// Les cas qui gardent les données laissent le rôle en place : on remet celui du module, avec ses vraies capacités.
remove_role( Lifecycle::ROLE_REVIEWER );
Reviewer::ensure_role();

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles :\n- " . implode( "\n- ", $fluffless_failures ) );
}
WP_CLI::success( $fluffless_checks . ' contrôles de désinstallation passés.' );
