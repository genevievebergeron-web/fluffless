<?php
/**
 * Test de la désinstallation en multisite (audit C4) : chaque site perd ses tables et ses réglages,
 * sauf celui qui a coché « Garder mes données ».
 *
 * Lancer sur un banc jetable multisite seulement ; sur un site simple, le test s'arrête avec un avertissement.
 *
 * @package Fluffless_Seo
 */

use Fluffless\Seo\Settings;
use Fluffless\Seo\Store;

defined( 'ABSPATH' ) || exit;

if ( '1' !== (string) get_option( 'fluffless_test_jetable', '' ) ) {
	throw new RuntimeException( 'Ce test supprime des tables : banc jetable seulement (option fluffless_test_jetable absente).' );
}
if ( ! is_multisite() ) {
	WP_CLI::warning( 'Banc non multisite : test de désinstallation multisite sauté.' );
	return;
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

$fluffless_has_table = static function (): bool {
	global $wpdb;
	$table = $wpdb->prefix . 'fluffless_seo_redirects';
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- contrôle en base.
};

$fluffless_seed = static function ( array $settings ): void {
	Store::install();
	update_option( Settings::NAME, array_merge( Settings::defaults(), $settings ) );
	Settings::flush();
};

$fluffless_main  = get_current_blog_id();
$fluffless_other = wpmu_create_blog( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '/seo-jetable-' . wp_rand() . '/', 'SEO jetable', 1 );
$fluffless_keep  = wpmu_create_blog( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '/seo-garde-' . wp_rand() . '/', 'SEO garde', 1 );
if ( ! is_int( $fluffless_other ) || ! is_int( $fluffless_keep ) ) {
	throw new RuntimeException( 'Création des sites de test impossible.' );
}

$fluffless_seed( array() );
switch_to_blog( $fluffless_other );
$fluffless_seed( array() );
restore_current_blog();
switch_to_blog( $fluffless_keep );
$fluffless_seed( array( 'keep_data' => true ) );
restore_current_blog();

require dirname( __DIR__ ) . '/uninstall.php';
wp_cache_flush();

$fluffless_assert( ! $fluffless_has_table() && false === get_option( Settings::NAME, false ), 'multisite : site principal nettoyé' );
switch_to_blog( $fluffless_other );
$fluffless_assert( ! $fluffless_has_table() && false === get_option( Settings::NAME, false ), 'multisite : deuxième site nettoyé' );
restore_current_blog();
switch_to_blog( $fluffless_keep );
$fluffless_assert( $fluffless_has_table() && false !== get_option( Settings::NAME, false ), 'multisite : le site « Garder mes données » garde tout' );
restore_current_blog();
$fluffless_assert( get_current_blog_id() === $fluffless_main, 'multisite : retour au site de départ' );

wp_delete_site( $fluffless_other );
wp_delete_site( $fluffless_keep );
Store::install();

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles :\n- " . implode( "\n- ", $fluffless_failures ) );
}
WP_CLI::success( $fluffless_checks . ' contrôles de désinstallation SEO multisite passés.' );
