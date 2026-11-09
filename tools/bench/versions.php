<?php
/**
 * Affiche en JSON les versions actives (WordPress, thème, extensions) du banc d'essai.
 * Usage : wp eval-file /bench/versions.php
 *
 * Pas de declare( strict_types=1 ) : wp eval-file évalue ce fichier au milieu de son propre code.
 *
 * @package Fluffless
 */

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$bench_theme   = wp_get_theme();
$bench_plugins = array();
foreach ( (array) get_option( 'active_plugins', array() ) as $bench_file ) {
	$bench_data                             = get_plugin_data( WP_PLUGIN_DIR . '/' . $bench_file, false, false );
	$bench_plugins[ $bench_data['Name'] ] = $bench_data['Version'];
}

echo wp_json_encode(
	array(
		'wordpress' => get_bloginfo( 'version' ),
		'php'       => PHP_VERSION,
		'theme'     => array( $bench_theme->get( 'Name' ) => $bench_theme->get( 'Version' ) ),
		'plugins'   => $bench_plugins,
	)
);
