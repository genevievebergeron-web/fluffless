<?php
/**
 * Tests de la désinstallation : tables et réglages effacés par défaut, gardés avec l'option
 * « Garder mes données » ou la constante FLUFFLESS_SEO_KEEP_DATA.
 *
 * Lancer sur un banc jetable seulement (le test supprime des tables) :
 *   wp option update fluffless_test_jetable 1
 *   wp eval-file wp-content/plugins/fluffless-seo/tests/seo-uninstall-jetable.php
 *
 * @package Fluffless_Seo
 */

use Fluffless\Seo\Settings;
use Fluffless\Seo\Store;

defined( 'ABSPATH' ) || exit;

if ( '1' !== (string) get_option( 'fluffless_test_jetable', '' ) ) {
	throw new RuntimeException( 'Ce test supprime des tables : banc jetable seulement (option fluffless_test_jetable absente).' );
}
if ( ! class_exists( Store::class ) ) {
	throw new RuntimeException( 'Fluffless SEO doit être active sur le banc jetable.' );
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
	define( 'WP_UNINSTALL_PLUGIN', 'fluffless-seo/fluffless-seo.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante de WordPress simulée, banc jetable.
}

$fluffless_rules = static function (): int {
	global $wpdb;
	$table = Store::redirects_table();
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- contrôle en base.
		return -1;
	}
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- nom de table interne.
};

$fluffless_seed = static function ( array $settings ) {
	global $wpdb;
	Store::install();
	$wpdb->query( 'DELETE FROM ' . Store::redirects_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- remise à zéro du banc jetable.
	update_option( Settings::NAME, array_merge( Settings::defaults(), $settings ) );
	Settings::flush();
	Store::add_redirect( '/ancienne-page', '/nouvelle-page' );
	Store::add_redirect( '/autre-page', '/autre-cible' );
};

$fluffless_uninstall = static function (): void {
	include dirname( __DIR__ ) . '/uninstall.php';
	wp_cache_flush();
	Settings::flush();
};

// 1. Défaut : tables et réglages effacés (comportement annoncé).
$fluffless_seed( array() );
$fluffless_assert( 2 === $fluffless_rules(), 'départ : 2 règles attendues, reçu ' . $fluffless_rules() );
$fluffless_uninstall();
$fluffless_assert( -1 === $fluffless_rules(), 'défaut : la table des redirections devrait être supprimée, reçu ' . $fluffless_rules() );
$fluffless_assert( false === get_option( Settings::NAME, false ), 'défaut : l’option des réglages devrait être supprimée' );

// 2. Option « Garder mes données » : rien n'est effacé.
$fluffless_seed( array( 'keep_data' => true ) );
$fluffless_uninstall();
$fluffless_assert( 2 === $fluffless_rules(), 'option : les 2 règles devraient rester, reçu ' . $fluffless_rules() );
$fluffless_assert( false !== get_option( Settings::NAME, false ), 'option : l’option des réglages devrait rester' );

// 3. Constante FLUFFLESS_SEO_KEEP_DATA (dernier : une constante ne se retire pas).
$fluffless_seed( array() );
define( 'FLUFFLESS_SEO_KEEP_DATA', true );
$fluffless_uninstall();
$fluffless_assert( 2 === $fluffless_rules(), 'constante : les 2 règles devraient rester, reçu ' . $fluffless_rules() );

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles :\n- " . implode( "\n- ", $fluffless_failures ) );
}
WP_CLI::success( $fluffless_checks . ' contrôles de désinstallation SEO passés.' );
