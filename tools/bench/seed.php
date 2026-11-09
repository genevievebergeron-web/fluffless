<?php
/**
 * Crée la page de référence du banc d'essai et en fait la page d'accueil.
 * Lancé par setup.sh : wp eval-file /bench/seed.php <fluffless|fluffless-gratuit|ollie|elementor>
 * Lit les fichiers produits par build-content.mjs dans /bench-build.
 *
 * Pas de declare( strict_types=1 ) : wp eval-file évalue ce fichier au milieu de son propre code.
 *
 * @package Fluffless
 */

$bench_variant = $args[0] ?? '';
$bench_page_templates = array(
	'fluffless'         => 'page-sections',        // Pleine largeur sans titre, entête et pied de page.
	'fluffless-gratuit' => 'page-sections',        // Même gabarit, thème seul.
	'ollie'             => 'page-no-title',        // Idem chez Ollie.
	'elementor'         => 'elementor_header_footer', // Pleine largeur Elementor avec entête et pied du thème.
);
if ( ! isset( $bench_page_templates[ $bench_variant ] ) ) {
	WP_CLI::error( "Variante attendue : fluffless, fluffless-gratuit, ollie ou elementor (reçu : '{$bench_variant}')." );
}

$bench_read = static function ( string $file ): string {
	$path = "/bench-build/{$file}";
	$data = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $data ) {
		WP_CLI::error( "Lecture impossible : {$path}. Lancer d'abord node tools/bench/build-content.mjs." );
	}
	return trim( $data );
};
$bench_fail = static function ( $result, string $what ): int {
	if ( is_wp_error( $result ) || ! $result ) {
		WP_CLI::error( "Échec : {$what}. " . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
	}
	return (int) $result;
};

$bench_content = json_decode( $bench_read( 'content.json' ), true, 16, JSON_THROW_ON_ERROR );
update_option( 'blogname', $bench_content['siteTitle'] );
update_option( 'blogdescription', '' );

$bench_page_id = $bench_fail(
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Home',
			'post_name'    => 'home',
			'post_content' => 'elementor' === $bench_variant ? '' : $bench_read( 'page.html' ),
		),
		true
	),
	'création de la page'
);
update_post_meta( $bench_page_id, '_wp_page_template', $bench_page_templates[ $bench_variant ] );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $bench_page_id );

if ( 'elementor' === $bench_variant ) {
	if ( ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( '\Elementor\Plugin' ) ) {
		WP_CLI::error( 'Elementor doit être actif avant le semis.' );
	}
	update_post_meta( $bench_page_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $bench_page_id, '_elementor_template_type', 'wp-page' );
	update_post_meta( $bench_page_id, '_elementor_version', ELEMENTOR_VERSION );
	update_post_meta( $bench_page_id, '_elementor_data', wp_slash( $bench_read( 'elementor.json' ) ) );

	// Menus classiques : Hello Elementor affiche menu-1 dans l'entête et menu-2 dans le pied.
	$bench_locations = array();
	foreach ( array( 'menu-1' => $bench_content['nav'], 'menu-2' => $bench_content['footerLinks'] ) as $location => $links ) {
		$menu_id = $bench_fail( wp_create_nav_menu( "Bench {$location}" ), "menu {$location}" );
		foreach ( $links as $link ) {
			$bench_fail(
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'  => $link[0],
						'menu-item-url'    => $link[1],
						'menu-item-type'   => 'custom',
						'menu-item-status' => 'publish',
					)
				),
				"lien {$link[0]}"
			);
		}
		$bench_locations[ $location ] = $menu_id;
	}
	set_theme_mod( 'nav_menu_locations', $bench_locations );

	// Texte du pied de page : réglage « copyright » de Hello, stocké dans le kit Elementor actif.
	$kit_id = (int) get_option( 'elementor_active_kit' );
	if ( ! $kit_id ) {
		WP_CLI::error( 'Aucun kit Elementor actif (option elementor_active_kit vide).' );
	}
	$kit_settings = (array) get_post_meta( $kit_id, '_elementor_page_settings', true );
	$kit_settings['hello_footer_copyright_display'] = 'yes';
	$kit_settings['hello_footer_copyright_text']    = $bench_content['footerText'];
	update_post_meta( $kit_id, '_elementor_page_settings', $kit_settings );

	\Elementor\Plugin::$instance->files_manager->clear_cache();
	$container = \Elementor\Plugin::$instance->experiments->is_feature_active( 'container' ) ? 'actif' : 'INACTIF';
	WP_CLI::log( "Elementor " . ELEMENTOR_VERSION . " : conteneurs {$container}, CSS régénéré." );
} else {
	$bench_nav_items = '';
	foreach ( $bench_content['nav'] as $link ) {
		$bench_nav_items .= '<!-- wp:navigation-link ' . wp_json_encode(
			array(
				'label' => $link[0],
				'url'   => $link[1],
				'kind'  => 'custom',
			)
		) . " /-->\n";
	}
	$bench_nav_id = $bench_fail(
		wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Main',
				'post_content' => $bench_nav_items,
			),
			true
		),
		'menu wp_navigation'
	);

	// Remplace les parties d'entête et de pied du thème par le même balisage dans A et B.
	foreach ( array( 'header', 'footer' ) as $area ) {
		$part_id = $bench_fail(
			wp_insert_post(
				array(
					'post_type'    => 'wp_template_part',
					'post_status'  => 'publish',
					'post_name'    => $area,
					'post_title'   => ucfirst( $area ),
					'post_content' => str_replace( '__NAV_REF__', (string) $bench_nav_id, $bench_read( "{$area}.html" ) ),
				),
				true
			),
			"partie {$area}"
		);
		wp_set_object_terms( $part_id, get_stylesheet(), 'wp_theme' );
		wp_set_object_terms( $part_id, $area, 'wp_template_part_area' );
	}
}

WP_CLI::success( "Page de référence {$bench_page_id} ({$bench_variant}) définie comme accueil." );
