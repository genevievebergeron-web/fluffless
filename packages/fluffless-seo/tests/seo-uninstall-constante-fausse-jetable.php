<?php
/**
 * Désinstallation avec la constante FLUFFLESS_SEO_KEEP_DATA définie à false : elle ne garde rien, comme
 * FLUFFLESS_KEEP_DATA dans Fluffless Suite (la valeur compte, pas la seule présence de la constante).
 * Fichier à part : une constante ne se redéfinit pas, et seo-uninstall-jetable.php la pose à true.
 *
 * Lancer sur un banc jetable seulement (le test supprime des tables) :
 *   wp option update fluffless_test_jetable 1
 *   wp eval-file wp-content/plugins/fluffless-seo/tests/seo-uninstall-constante-fausse-jetable.php
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

// Constante à false : tables et réglages effacés comme par défaut.
$fluffless_seed( array() );
define( 'FLUFFLESS_SEO_KEEP_DATA', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante documentée de wp-config.php, banc jetable.
$fluffless_assert( 2 === $fluffless_rules(), 'départ : 2 règles attendues, reçu ' . $fluffless_rules() );
$fluffless_uninstall();
$fluffless_assert( -1 === $fluffless_rules(), 'constante à false : la table des redirections devrait être supprimée, reçu ' . $fluffless_rules() );
$fluffless_assert( false === get_option( Settings::NAME, false ), 'constante à false : l’option des réglages devrait être supprimée' );

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles :\n- " . implode( "\n- ", $fluffless_failures ) );
}
WP_CLI::success( $fluffless_checks . ' contrôles de la constante SEO à false passés.' );
