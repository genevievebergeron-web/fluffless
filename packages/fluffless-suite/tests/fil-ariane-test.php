<?php
/**
 * Test du bloc Fil d'Ariane (fluffless/fil-ariane) et du chemin partagé Presentation\Trail : mêmes
 * maillons pour le bloc et pour le JSON-LD BreadcrumbList du module SEO.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/fil-ariane-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\Sections;
use Fluffless\Suite\Modules\Expression;
use Fluffless\Suite\Modules\Seo;
use Fluffless\Suite\Presentation\Trail;
use const Fluffless\Suite\FILE;

defined( 'ABSPATH' ) || exit;

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_registry = WP_Block_Type_Registry::get_instance();
$fluffless_before   = array_keys( $fluffless_registry->get_all_registered() );
if ( ! $fluffless_registry->is_registered( 'fluffless/' . Sections::BREADCRUMB ) ) {
	Expression::register_block();
}
$fluffless_name = 'fluffless/' . Sections::BREADCRUMB;
$fluffless_type = $fluffless_registry->get_registered( $fluffless_name );
if ( ! $fluffless_type instanceof WP_Block_Type ) {
	throw new RuntimeException( 'Bloc Fil d\'Ariane non enregistré.' );
}

// Charge une requête comme si le visiteur ouvrait ce contenu.
$fluffless_visit  = static function ( array $query ): void {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	$GLOBALS['wp_query']     = new WP_Query( $query );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	$GLOBALS['post']         = $GLOBALS['wp_query']->post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	Seo::reset();
};
$fluffless_render = static fn( array $attrs = array() ): string => render_block(
	array(
		'blockName'    => $fluffless_name,
		'attrs'        => $attrs,
		'innerBlocks'  => array(),
		'innerHTML'    => '',
		'innerContent' => array(),
	)
);
$fluffless_names  = static fn( string $html ): array => array_map( 'html_entity_decode', preg_match_all( '#<li[^>]*>(?:<a[^>]*>)?([^<]*)#', $html, $m ) ? $m[1] : array() );

switch_to_locale( 'en_US' );
wp_set_current_user( 0 );
$fluffless_ids     = array();
$fluffless_options = array(
	'show_on_front'  => get_option( 'show_on_front' ),
	'page_on_front'  => get_option( 'page_on_front' ),
	'page_for_posts' => get_option( 'page_for_posts' ),
);
$fluffless_insert  = static function ( array $args ) use ( &$fluffless_ids ): int {
	$id = wp_insert_post( $args + array( 'post_status' => 'publish' ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Création impossible : ' . esc_html( $id->get_error_message() ) );
	}
	$fluffless_ids[] = $id;
	return $id;
};

try {
	update_option( 'show_on_front', 'posts' );
	$fluffless_parent = $fluffless_insert(
		array(
			'post_type'  => 'page',
			'post_title' => 'Services <b>&amp; more</b>',
		)
	);
	$fluffless_child  = $fluffless_insert(
		array(
			'post_type'   => 'page',
			'post_title'  => 'Child "quoted"',
			'post_parent' => $fluffless_parent,
		)
	);
	$fluffless_leaf   = $fluffless_insert(
		array(
			'post_type'   => 'page',
			'post_title'  => 'Leaf',
			'post_parent' => $fluffless_child,
		)
	);

	// Les titres passent par les filtres, comme depuis une extension ou une importation : le parent
	// devient hostile.
	$fluffless_title_filter = static fn( string $title, int $id = 0 ): string => $id === $fluffless_parent ? 'Services </script><b>&</b> "more"' : $title;
	add_filter( 'the_title', $fluffless_title_filter, 10, 2 );
	$fluffless_expected     = array( 'Home', 'Services & "more"', 'Child “quoted”', 'Leaf' );

	// 1. Page petite-fille : Accueil, parent, enfant, courante (texte, aria-current, sans lien).
	$fluffless_visit( array( 'page_id' => $fluffless_leaf ) );
	$fluffless_html = $fluffless_render();
	$fluffless_assert( str_starts_with( $fluffless_html, '<nav class="wp-block-fluffless-fil-ariane fluffless-fil-ariane is-home-shown is-current-shown" aria-label="Breadcrumb"><ol>' ), 'rendu : nav/ol/classes/aria-label inattendus : ' . $fluffless_html );
	$fluffless_assert( $fluffless_expected === $fluffless_names( $fluffless_html ), 'rendu : maillons inattendus : ' . wp_json_encode( $fluffless_names( $fluffless_html ) ) );
	$fluffless_assert( str_ends_with( $fluffless_html, '<li aria-current="page">Leaf</li></ol></nav>' ), 'rendu : maillon courant sans aria-current ou avec lien : ' . $fluffless_html );
	$fluffless_assert( 3 === substr_count( $fluffless_html, '<a href="' ) && str_contains( $fluffless_html, '<a href="' . esc_url( (string) get_permalink( $fluffless_child ) ) . '">' ), 'rendu : liens des maillons parents' );
	$fluffless_assert( ! str_contains( $fluffless_html, '</script>' ) && ! str_contains( $fluffless_html, '<b>' ) && ! str_contains( $fluffless_html, '"more"' ) && str_contains( $fluffless_html, 'Services &amp; &quot;more&quot;' ), 'rendu : titre hostile non échappé : ' . $fluffless_html );
	$fluffless_assert( ! str_contains( $fluffless_html, 'fluffless-section' ), 'rendu : classe fluffless-section posée (marge verticale commune non voulue)' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'ld+json' ), 'rendu : JSON-LD sorti par le bloc (le module SEO s\'en charge)' );

	// 2. Réglages : Accueil caché, courante cachée, alignement et classe.
	$fluffless_html = $fluffless_render( array( 'home' => 'hidden' ) );
	$fluffless_assert( array_slice( $fluffless_expected, 1 ) === $fluffless_names( $fluffless_html ) && str_contains( $fluffless_html, 'is-home-hidden' ), 'home=hidden : Accueil toujours là' );
	$fluffless_html = $fluffless_render(
		array(
			'current'   => 'hidden',
			'align'     => 'wide',
			'className' => 'extra <x>',
		)
	);
	$fluffless_assert( array_slice( $fluffless_expected, 0, 3 ) === $fluffless_names( $fluffless_html ) && ! str_contains( $fluffless_html, 'aria-current' ), 'current=hidden : courante toujours là' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-current-hidden alignwide extra x"' ), 'align/className : classes inattendues : ' . $fluffless_html );
	$fluffless_assert( '' === $fluffless_render( array( 'home' => 'nope' ) ) || str_contains( $fluffless_render( array( 'home' => 'nope' ) ), 'is-home-shown' ), 'valeur hors liste : non ramenée au défaut' );

	// 3. Page de premier niveau, les deux cachés : rien. Ancre : l'id va sur le nav.
	$fluffless_visit( array( 'page_id' => $fluffless_parent ) );
	$fluffless_assert(
		'' === $fluffless_render(
			array(
				'home'    => 'hidden',
				'current' => 'hidden',
			)
		),
		'premier niveau, tout caché : rendu non vide'
	);
	$fluffless_assert( str_starts_with( $fluffless_render( array( 'anchor' => 'chemin' ) ), '<nav id="chemin" class="' ), 'ancre : id absent du nav' );

	// 4. Page d'accueil statique : rien ; page des articles : Accueil, Blog (titre de la page).
	$fluffless_blog = $fluffless_insert(
		array(
			'post_type'  => 'page',
			'post_title' => 'News &amp; notes',
		)
	);
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $fluffless_parent );
	update_option( 'page_for_posts', $fluffless_blog );
	$fluffless_visit( array( 'page_id' => $fluffless_parent ) );
	$fluffless_assert( is_front_page() && '' === $fluffless_render() && array() === Trail::of(), 'page d\'accueil : rendu ou chemin non vide' );
	$fluffless_article = $fluffless_insert(
		array(
			'post_type'  => 'post',
			'post_title' => 'Hello',
		)
	);
	$fluffless_visit( array( 'p' => $fluffless_article ) );
	$fluffless_trail = Trail::of();
	$fluffless_assert( array( 'Home', 'News & notes', 'Hello' ) === array_column( $fluffless_trail, 'name' ) && get_permalink( $fluffless_blog ) === ( $fluffless_trail[1]['url'] ?? '' ), 'article : page des articles absente du chemin : ' . wp_json_encode( $fluffless_trail ) );
	$fluffless_visit( array( 'pagename' => get_post_field( 'post_name', $fluffless_blog ) ) );
	$GLOBALS['wp_query']->is_home     = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation de la page des articles.
	$GLOBALS['wp_query']->is_page     = false; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation de la page des articles.
	$GLOBALS['wp_query']->is_singular = false; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation de la page des articles.
	$fluffless_assert( array( 'Home', 'News & notes' ) === array_column( Trail::of(), 'name' ), 'page des articles : chemin inattendu : ' . wp_json_encode( Trail::of() ) );

	// 5. Archive de catégorie, recherche, 404 : un maillon sans lien après Accueil.
	$fluffless_cat = wp_insert_term( 'Topic <i>x</i>', 'category' );
	if ( is_wp_error( $fluffless_cat ) ) {
		throw new RuntimeException( 'Création de la catégorie impossible.' );
	}
	wp_set_post_terms( $fluffless_article, array( (int) $fluffless_cat['term_id'] ), 'category' );
	$fluffless_visit( array( 'cat' => (int) $fluffless_cat['term_id'] ) );
	$fluffless_trail = Trail::of();
	$fluffless_assert( is_archive() && 2 === count( $fluffless_trail ) && str_contains( $fluffless_trail[1]['name'], 'Topic x' ) && '' === $fluffless_trail[1]['url'], 'archive : chemin inattendu : ' . wp_json_encode( $fluffless_trail ) );
	$fluffless_html = $fluffless_render();
	$fluffless_assert( 1 === substr_count( $fluffless_html, '<a href="' ) && str_contains( $fluffless_html, 'aria-current="page"' ), 'archive : rendu inattendu : ' . $fluffless_html );
	$fluffless_visit( array( 's' => 'fluff' ) );
	$fluffless_assert( array( 'Home', 'Search results' ) === array_column( Trail::of(), 'name' ), 'recherche : chemin inattendu' );
	$fluffless_visit( array( 'page_id' => 999999999 ) );
	$GLOBALS['wp_query']->set_404();
	$fluffless_assert( array( 'Home', 'Page not found' ) === array_column( Trail::of(), 'name' ), '404 : chemin inattendu' );

	// 6. Filtre : maillon ajouté, retour invalide ignoré, maillons mal formés écartés.
	$fluffless_visit( array( 'page_id' => $fluffless_leaf ) );
	$fluffless_filter = static function ( array $trail, $post ) use ( $fluffless_leaf ): array {
		$trail[] = array(
			'name' => $post instanceof WP_Post && $post->ID === $fluffless_leaf ? ' Extra ' : 'bad',
			'url'  => '',
		);
		$trail[] = array( 'url' => 'https://example.com/' );
		$trail[] = array(
			'name' => '   ',
			'url'  => '',
		);
		$trail[] = array(
			'name' => 'Num',
			'url'  => 42,
		);
		$trail[] = 'string';
		return $trail;
	};
	add_filter( Trail::FILTER, $fluffless_filter, 10, 2 );
	$fluffless_trail = Trail::of();
	remove_filter( Trail::FILTER, $fluffless_filter );
	$fluffless_assert( array_merge( $fluffless_expected, array( 'Extra' ) ) === array_column( $fluffless_trail, 'name' ) && array( 0, 1, 2, 3, 4 ) === array_keys( $fluffless_trail ), 'filtre : maillons invalides gardés ou valide perdu : ' . wp_json_encode( $fluffless_trail ) );
	add_filter( Trail::FILTER, '__return_false' );
	$fluffless_assert( 4 === count( Trail::of() ), 'filtre : retour non-tableau non ignoré' );
	remove_filter( Trail::FILTER, '__return_false' );
	$fluffless_assert( 'https://example.com/x' === ( Trail::of( get_post( $fluffless_leaf ), 'https://example.com/x' )[3]['url'] ?? '' ), 'url canonique : non reprise sur le dernier maillon' );

	// 6b. Rendu par adresse, pas par rang : un maillon ajouté avant Accueil ou après la page reste un lien ;
	// masquer Accueil retire l'Accueil, masquer la page courante retire le maillon de la page, pas le dernier.
	$fluffless_wrap = static function ( array $trail ): array {
		array_unshift(
			$trail,
			array(
				'name' => 'Group',
				'url'  => 'https://example.com/group/',
			)
		);
		$trail[] = array(
			'name' => 'After',
			'url'  => 'https://example.com/after/',
		);
		return $trail;
	};
	add_filter( Trail::FILTER, $fluffless_wrap );
	$fluffless_html = $fluffless_render();
	$fluffless_assert( array_merge( array( 'Group' ), $fluffless_expected, array( 'After' ) ) === $fluffless_names( $fluffless_html ), 'filtre : maillons encadrants : ' . $fluffless_html );
	$fluffless_assert( 1 === preg_match( '#<li aria-current="page">Leaf</li><li><a href="https://example.com/after/">After</a></li></ol>#', $fluffless_html ), 'filtre : la page courante doit garder aria-current et le maillon suivant rester un lien : ' . $fluffless_html );
	$fluffless_assert( 1 === preg_match( '#^<nav[^>]*><ol><li><a href="https://example.com/group/">Group</a></li><li><a href="[^"]+">Home</a></li>#', $fluffless_html ), 'filtre : le maillon avant Accueil doit rester un lien : ' . $fluffless_html );
	$fluffless_html = $fluffless_render( array( 'home' => 'hidden' ) );
	$fluffless_assert( array_merge( array( 'Group' ), array_slice( $fluffless_expected, 1 ), array( 'After' ) ) === $fluffless_names( $fluffless_html ), 'home=hidden : doit retirer Accueil, pas le premier maillon : ' . $fluffless_html );
	$fluffless_html = $fluffless_render( array( 'current' => 'hidden' ) );
	$fluffless_assert( array_merge( array( 'Group' ), array_slice( $fluffless_expected, 0, 3 ), array( 'After' ) ) === $fluffless_names( $fluffless_html ) && false === strpos( $fluffless_html, 'aria-current' ), 'current=hidden : doit retirer la page, pas le dernier maillon : ' . $fluffless_html );
	remove_filter( Trail::FILTER, $fluffless_wrap );
	$fluffless_assert( '' === $fluffless_render( array( 'home' => 'hidden' ) ) || 3 === count( $fluffless_names( $fluffless_render( array( 'home' => 'hidden' ) ) ) ), 'home=hidden sans filtre : compte inattendu' );

	// 6b'. Hors singulier, le dernier maillon est le courant, même ajouté par filtre ; adresse réécrite
	// par filtre sur un singulier : repli sur le dernier maillon.
	$fluffless_tail = static function ( array $trail ): array {
		$trail[] = array(
			'name' => 'Tail',
			'url'  => '',
		);
		return $trail;
	};
	add_filter( Trail::FILTER, $fluffless_tail );
	$fluffless_visit( array( 's' => 'fluff' ) );
	$fluffless_html = $fluffless_render();
	remove_filter( Trail::FILTER, $fluffless_tail );
	$fluffless_assert( 1 === preg_match( '#<li>Search results</li><li aria-current="page">Tail</li></ol>#', $fluffless_html ), 'recherche + filtre : le dernier maillon doit être le courant : ' . $fluffless_html );
	$fluffless_rewrite = static function ( array $trail ): array {
		$trail[ array_key_last( $trail ) ]['url'] .= '?x=1';
		return $trail;
	};
	add_filter( Trail::FILTER, $fluffless_rewrite );
	$fluffless_visit( array( 'page_id' => $fluffless_leaf ) );
	$fluffless_html = $fluffless_render( array( 'current' => 'hidden' ) );
	remove_filter( Trail::FILTER, $fluffless_rewrite );
	$fluffless_assert( array_slice( $fluffless_expected, 0, 3 ) === $fluffless_names( $fluffless_html ), 'adresse réécrite par filtre : current=hidden doit encore retirer le dernier maillon : ' . $fluffless_html );

	// 6c. Type de contenu avec archive : maillon de l'archive seulement quand elle a une adresse.
	register_post_type(
		'fluffless_dossier',
		array(
			'public'      => true,
			'has_archive' => true,
			'label'       => 'Dossiers',
			'rewrite'     => false,
		)
	);
	$fluffless_dossier = $fluffless_insert(
		array(
			'post_type'  => 'fluffless_dossier',
			'post_title' => 'Dossier A',
		)
	);
	$fluffless_visit(
		array(
			'post_type' => 'fluffless_dossier',
			'p'         => $fluffless_dossier,
		)
	);
	$fluffless_trail = Trail::of();
	unregister_post_type( 'fluffless_dossier' );
	$fluffless_assert( array( 'Home', 'Dossiers', 'Dossier A' ) === array_column( $fluffless_trail, 'name' ) && '' !== $fluffless_trail[1]['url'], 'type avec archive : maillon de l\'archive absent ou sans adresse : ' . wp_json_encode( $fluffless_trail ) );
	$fluffless_visit( array( 'page_id' => $fluffless_leaf ) );

	// 7. Parité avec le JSON-LD du module SEO : mêmes noms, dans le même ordre, dernier item = canonique.
	$fluffless_head = Seo::head_tags();
	$fluffless_assert( 1 === preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $fluffless_head, $fluffless_match ), 'seo : bloc JSON-LD absent' );
	$fluffless_ld   = json_decode( $fluffless_match[1] ?? '', true );
	$fluffless_list = array();
	foreach ( is_array( $fluffless_ld ) ? ( $fluffless_ld['@graph'] ?? array() ) : array() as $fluffless_node ) {
		if ( 'BreadcrumbList' === ( $fluffless_node['@type'] ?? '' ) ) {
			$fluffless_list = $fluffless_node['itemListElement'] ?? array();
		}
	}
	$fluffless_assert( array_column( Trail::of(), 'name' ) === array_column( $fluffless_list, 'name' ) && array( 1, 2, 3, 4 ) === array_column( $fluffless_list, 'position' ), 'seo : BreadcrumbList différente du bloc : ' . wp_json_encode( $fluffless_list ) );
	$fluffless_assert( Seo::canonical_url() === ( $fluffless_list[3]['item'] ?? '' ), 'seo : dernier item différent de la canonique' );

	// 8. Fichiers du bloc : pas de script sur le site, feuille courte, sans couleur en dur, dépendances de l'éditeur.
	$fluffless_dir = dirname( FILE ) . '/blocks/' . Sections::BREADCRUMB;
	$fluffless_assert( array() === $fluffless_type->view_script_handles, 'bloc : script côté site' );
	$fluffless_css = (string) file_get_contents( $fluffless_dir . '/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
	$fluffless_assert( strlen( $fluffless_css ) < 1024 && 0 === preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_css ), 'style.css : trop lourd ou couleur en dur' );
	$fluffless_asset = include $fluffless_dir . '/index.asset.php';
	$fluffless_assert( is_array( $fluffless_asset ) && in_array( Sections::EDITOR_SCRIPT, $fluffless_asset['dependencies'] ?? array(), true ), 'index.asset.php : aides communes absentes des dépendances' );
	$fluffless_json = json_decode( (string) file_get_contents( $fluffless_dir . '/block.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
	$fluffless_assert( is_array( $fluffless_json ) && array( 'shown', 'hidden' ) === ( $fluffless_json['attributes']['home']['enum'] ?? null ) && 'shown' === ( $fluffless_json['attributes']['current']['default'] ?? null ), 'block.json : attributs home/current' );
} finally {
	if ( isset( $fluffless_title_filter ) ) {
		remove_filter( 'the_title', $fluffless_title_filter );
	}
	foreach ( $fluffless_options as $fluffless_key => $fluffless_value ) {
		update_option( $fluffless_key, $fluffless_value );
	}
	if ( isset( $fluffless_cat ) && ! is_wp_error( $fluffless_cat ) ) {
		wp_delete_term( (int) $fluffless_cat['term_id'], 'category' );
	}
	foreach ( $fluffless_ids as $fluffless_id ) {
		wp_delete_post( $fluffless_id, true );
	}
	foreach ( array_diff( array_keys( $fluffless_registry->get_all_registered() ), $fluffless_before ) as $fluffless_extra ) {
		unregister_block_type( $fluffless_extra );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Fil d'Ariane : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Fil d'Ariane : {$fluffless_checks} vérifications passées." );
