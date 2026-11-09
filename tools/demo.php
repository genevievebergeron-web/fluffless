<?php
/**
 * Monte les pages de démonstration du WordPress local à partir des compositions.
 *
 * Lu sur STDIN par `wp eval-file -` (voir tools/demo.sh) : le conteneur ne monte pas tools/.
 * Le contenu de chaque composition est copié dans la page, jamais appelé par son slug :
 * la page reste lisible même si Fluffless Suite est désactivée.
 *
 * @package Fluffless
 */

$fluffless_pages = array(
	'accueil'     => array(
		'title'    => 'Accueil',
		'patterns' => array( 'fluffless/page-accueil' ),
		'front'    => true,
	),
	'a-propos'    => array(
		'title'    => 'À propos',
		'patterns' => array( 'fluffless/page-a-propos' ),
	),
	'services'    => array(
		'title'    => 'Services',
		'patterns' => array( 'fluffless/page-services' ),
	),
	'contact'     => array(
		'title'    => 'Contact',
		'patterns' => array( 'fluffless/page-contact' ),
	),
	'oui-tout'    => array(
		'title'    => 'Oui, tout',
		'patterns' => array(
			'fluffless/ouverture-affiche',
			'fluffless/section-qui-nous-sommes',
			'fluffless/doux-services',
			'fluffless/cartes-retournees',
			'fluffless/histoire-defilante',
			'fluffless/chiffres',
			'fluffless/section-temoignages',
			'fluffless/galerie-glisse',
			'fluffless/section-vague',
			'fluffless/section-appel-action',
		),
	),
	'page-blanche' => array(
		'title'    => 'Page blanche',
		'patterns' => array(),
	),
);

$fluffless_registry = WP_Block_Patterns_Registry::get_instance();
$fluffless_made     = array();

foreach ( $fluffless_pages as $fluffless_slug => $fluffless_page ) {
	$fluffless_content = array();
	foreach ( $fluffless_page['patterns'] as $fluffless_name ) {
		$fluffless_pattern = $fluffless_registry->get_registered( $fluffless_name );
		if ( null === $fluffless_pattern ) {
			WP_CLI::warning( sprintf( 'Composition « %s » absente : ignorée.', $fluffless_name ) );
			continue;
		}
		$fluffless_content[] = trim( (string) $fluffless_pattern['content'] );
	}

	$fluffless_existing = get_page_by_path( $fluffless_slug );
	$fluffless_data     = array(
		'post_type'    => 'page',
		'post_title'   => $fluffless_page['title'],
		'post_name'    => $fluffless_slug,
		'post_status'  => 'publish',
		'post_content' => implode( "\n\n", $fluffless_content ),
		// Les compositions de page portent leur propre titre : gabarit pleine largeur, sans titre.
		'page_template' => array() === $fluffless_page['patterns'] ? '' : 'page-sections',
	);

	if ( $fluffless_existing instanceof WP_Post ) {
		$fluffless_data['ID'] = $fluffless_existing->ID;
		$fluffless_id         = wp_update_post( $fluffless_data, true );
	} else {
		$fluffless_id = wp_insert_post( $fluffless_data, true );
	}

	if ( is_wp_error( $fluffless_id ) ) {
		WP_CLI::error( sprintf( 'Page « %s » : %s', $fluffless_slug, $fluffless_id->get_error_message() ) );
	}

	$fluffless_made[ $fluffless_slug ] = (int) $fluffless_id;

	if ( ! empty( $fluffless_page['front'] ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', (int) $fluffless_id );
	}
}

// Contenu d'exemple de WordPress : à la corbeille seulement s'il n'a jamais été modifié.
foreach ( array( 1, 2 ) as $fluffless_default ) {
	$fluffless_post = get_post( $fluffless_default );
	if ( $fluffless_post instanceof WP_Post
		&& 'trash' !== $fluffless_post->post_status
		&& $fluffless_post->post_date_gmt === $fluffless_post->post_modified_gmt ) {
		wp_trash_post( $fluffless_default );
	}
}

// Menu de navigation : les pages de la visite, dans l'ordre.
$fluffless_menu = wp_get_nav_menu_object( 'Visite' );
if ( ! $fluffless_menu ) {
	$fluffless_menu_id = wp_create_nav_menu( 'Visite' );
} else {
	$fluffless_menu_id = (int) $fluffless_menu->term_id;
	foreach ( wp_get_nav_menu_items( $fluffless_menu_id ) ?: array() as $fluffless_item ) {
		wp_delete_post( $fluffless_item->ID, true );
	}
}

foreach ( $fluffless_made as $fluffless_slug => $fluffless_id ) {
	wp_update_nav_menu_item(
		$fluffless_menu_id,
		0,
		array(
			'menu-item-object-id' => $fluffless_id,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
		)
	);
}

WP_CLI::success( sprintf( '%d pages de démonstration : %s.', count( $fluffless_made ), implode( ', ', array_keys( $fluffless_made ) ) ) );
