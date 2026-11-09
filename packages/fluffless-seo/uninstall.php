<?php
/**
 * Désinstallation : supprime tout ce que Fluffless SEO a créé, sauf si
 * FLUFFLESS_SEO_KEEP_DATA est vraie (wp-config.php, sa valeur compte comme FLUFFLESS_KEEP_DATA dans Fluffless Suite) ou que l'option « Garder mes données » est cochée
 * dans les réglages. WordPress n'offre pas d'étape de confirmation à la suppression (voir README.md).
 *
 * Multisite : chaque site a ses tables et ses réglages ; on les traite un par un (au plus 10 000 sites),
 * et un site qui a coché « Garder mes données » garde les siennes.
 *
 * Gardé : la description (_fluffless_description), partagée avec Fluffless Suite.
 *
 * @package Fluffless_Seo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( filter_var( defined( 'FLUFFLESS_SEO_KEEP_DATA' ) ? constant( 'FLUFFLESS_SEO_KEEP_DATA' ) : false, FILTER_VALIDATE_BOOLEAN ) ) {
	return;
}

/**
 * Nettoie le site courant, sauf si son option « Garder mes données » est cochée.
 * L'extension n'est pas chargée ici : lecture brute de l'option (Réglages, C-S05-04).
 */
$fluffless_seo_clean_site = static function (): void {
	global $wpdb;

	$stored = get_option( 'fluffless_seo_settings', array() );
	if ( is_array( $stored ) && true === ( $stored['keep_data'] ?? false ) ) {
		return;
	}

	delete_option( 'fluffless_seo_settings' );
	wp_clear_scheduled_hook( 'fluffless_seo_purge' );

	foreach ( array( 'fluffless_seo_redirects', 'fluffless_seo_404' ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- nom de table interne, désinstallation.
	}

	// Même liste que Meta::OWN_KEYS (vérifié par tests/seo-test.php) : l'extension n'est pas chargée ici.
	foreach ( array( '_fluffless_seo_title', '_fluffless_seo_canonical', '_fluffless_seo_robots', '_fluffless_seo_og_title', '_fluffless_seo_og_desc', '_fluffless_seo_og_image', '_fluffless_seo_keyword', '_fluffless_seo_schema', '_fluffless_seo_schema_data' ) as $key ) {
		delete_post_meta_by_key( $key );
	}
};

if ( ! is_multisite() ) {
	$fluffless_seo_clean_site();
	return;
}

foreach ( get_sites(
	array(
		'fields' => 'ids',
		'number' => 10000,
	)
) as $fluffless_seo_site_id ) {
	switch_to_blog( (int) $fluffless_seo_site_id );
	$fluffless_seo_clean_site();
	restore_current_blog();
}
