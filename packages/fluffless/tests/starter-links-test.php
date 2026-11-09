<?php
/**
 * Tests du site neuf : aucun lien public (menu, pied de page, boutons des motifs) vers une page absente.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/starter-links-test.php
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( 'fluffless' !== get_template() ) {
	throw new RuntimeException( 'Thème Fluffless inactif : wp theme activate fluffless.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};
$fluffless_created  = array();

$fluffless_assert( function_exists( 'fluffless_page_url' ) && function_exists( 'fluffless_nav_links' ), 'fonctions fluffless_page_url et fluffless_nav_links absentes' );

if ( function_exists( 'fluffless_page_url' ) && function_exists( 'fluffless_nav_links' ) ) {
	// Page absente : le lien ne mène pas vers une adresse qui répond 404.
	$fluffless_assert( '#' === fluffless_page_url( '/zz-page-inexistante/' ), 'une page absente donne autre chose que #' );
	$fluffless_assert( home_url( '/' ) === fluffless_page_url( '/' ), 'la racine doit mener à l’accueil' );

	// Page publiée : son adresse réelle.
	$fluffless_id        = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Page de test K2a',
			'post_name'   => 'zz-page-k2a',
		)
	);
	$fluffless_created[] = $fluffless_id;
	$fluffless_assert( get_permalink( $fluffless_id ) === fluffless_page_url( '/zz-page-k2a/' ), 'une page publiée doit donner son adresse' );

	// Menu : une entrée sans page reste dans le menu enregistré mais n'est pas rendue en public; l'accueil reste.
	$fluffless_items = array(
		array( 'Accueil', '/' ),
		array( 'Présente', '/zz-page-k2a/' ),
		array( 'Absente', '/zz-page-inexistante/' ),
	);
	$fluffless_nav   = fluffless_nav_links( $fluffless_items );
	$fluffless_pub   = do_blocks( '<!-- wp:navigation -->' . $fluffless_nav . '<!-- /wp:navigation -->' );
	$fluffless_assert( str_contains( $fluffless_nav, '"label":"Accueil"' ), 'le lien Accueil doit rester' );
	$fluffless_assert( str_contains( $fluffless_nav, '"label":"Absente"' ), 'le menu enregistré doit garder l’entrée sans page (P13)' );
	$fluffless_assert( str_contains( $fluffless_pub, 'Présente' ) && ! str_contains( $fluffless_pub, 'Absente' ), 'en public, l’entrée sans page est masquée et l’entrée avec page reste' );
	$fluffless_assert( ! str_contains( $fluffless_pub, 'href="#"' ), 'aucun lien # permanent dans le menu public' );

	// P13 : le menu enregistré (figé) fait apparaître l’entrée dès que la page est publiée, sans réédition.
	$fluffless_late      = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Absente',
			'post_name'   => 'zz-page-inexistante',
		)
	);
	$fluffless_created[] = $fluffless_late;
	$fluffless_pub2      = do_blocks( '<!-- wp:navigation -->' . $fluffless_nav . '<!-- /wp:navigation -->' );
	$fluffless_assert( str_contains( $fluffless_pub2, 'Absente' ) && str_contains( $fluffless_pub2, (string) get_permalink( $fluffless_late ) ), 'l’entrée enregistrée doit apparaître après la création de la page, vers son adresse (P13)' );
	// P17 : la création est vue dans le même processus (aucun résultat périmé gardé en mémoire).
	$fluffless_assert( get_permalink( $fluffless_late ) === fluffless_page_url( '/zz-page-inexistante/' ), 'la page créée doit être vue aussitôt (P17)' );
	wp_update_post(
		array(
			'ID'          => $fluffless_late,
			'post_status' => 'draft',
		)
	);
	$fluffless_assert( '#' === fluffless_page_url( '/zz-page-inexistante/' ), 'la page repassée en brouillon doit être vue aussitôt (P17)' );
	$fluffless_assert( ! str_contains( do_blocks( '<!-- wp:navigation -->' . $fluffless_nav . '<!-- /wp:navigation -->' ), 'Absente' ), 'une page en brouillon masque l’entrée' );
	wp_delete_post( $fluffless_late, true );

	// 4b : une entrée liée à une page par son identifiant est masquée par le cœur quand la page n’est pas publiée.
	$fluffless_draft     = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Brouillon',
			'post_name'   => 'zz-brouillon-k2c',
		)
	);
	$fluffless_created[] = $fluffless_draft;
	$fluffless_byid      = do_blocks( '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Brouillon","url":"' . get_permalink( $fluffless_draft ) . '","kind":"post-type","type":"page","id":' . $fluffless_draft . '} /--><!-- /wp:navigation -->' );
	$fluffless_assert( ! str_contains( $fluffless_byid, 'Brouillon' ), '4b : le cœur masque une entrée liée à une page non publiée' );

	// D3 : boutons insérés avant la création de la page, reliés au rendu; adresse saisie ensuite jamais écrasée.
	$fluffless_btn = static function ( string $href, string $classe = 'fluffless-page-zz-lien-k2c' ): string {
		return '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"' . $classe . '"} --><div class="wp-block-button ' . $classe . '"><a class="wp-block-button__link wp-element-button" href="' . $href . '">Go</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
	};
	$fluffless_out = do_blocks( $fluffless_btn( '#' ) );
	$fluffless_assert( ! str_contains( $fluffless_out, 'wp-block-button' ) && ! str_contains( $fluffless_out, 'wp-block-buttons' ), 'D3 : bouton # vers une page absente masqué, conteneur compris' );
	$fluffless_assert( ! str_contains( do_blocks( $fluffless_btn( home_url( '/zz-lien-k2c/' ) ) ), 'wp-block-button__link' ), 'D3 : adresse de la page absente masquée (page supprimée ou renommée)' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( 'http://autre-domaine.test/ailleurs/' ) ), 'http://autre-domaine.test/ailleurs/' ), 'D3 : adresse saisie par la cliente jamais écrasée' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( 'https://autre-domaine.test/zz-lien-k2c/' ) ), 'https://autre-domaine.test/zz-lien-k2c/' ), 'D3 : adresse d’un autre domaine jamais écrasée, même au même chemin' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( '/ailleurs/' ) ), '/ailleurs/' ), 'D3 : adresse relative saisie par la cliente jamais écrasée' );
	$fluffless_target    = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Lien K2c',
			'post_name'   => 'zz-lien-k2c',
		)
	);
	$fluffless_created[] = $fluffless_target;
	$fluffless_link      = (string) get_permalink( $fluffless_target );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( '#' ) ), 'href="' . $fluffless_link . '"' ), 'D3 : le bouton # devient valide à la création de la page' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( home_url( '/zz-lien-k2c/' ) ) ), 'href="' . $fluffless_link . '"' ), 'D3 : adresse relative reconnue reliée à la page' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( 'http://ancien-site.test/zz-lien-k2c/' ) ), 'http://ancien-site.test/zz-lien-k2c/' ), 'D3 : adresse d’un autre domaine laissée telle quelle' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( '/fr/zz-lien-k2c/' ) ), 'href="' . $fluffless_link . '"' ), 'D3 : préfixe de langue ou de dossier ignoré à la reconnaissance' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( home_url( '/?page_id=' . $fluffless_target ) ) ), 'href="' . $fluffless_link . '"' ), 'D3 : adresse ?page_id= de la page reconnue' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( home_url( '/?page_id=' . $fluffless_target . '#formulaire' ) ) ), '#formulaire' ), 'D3 : ancre après ?page_id= conservée' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( home_url( '/?page_id=' . $fluffless_target . '&ref=x' ) ) ), 'ref=x' ), 'D3 : paramètres avec ?page_id= conservés' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( home_url( '/zz-lien-k2c/#formulaire' ) ) ), '#formulaire' ), 'D3 : ancre saisie par la cliente conservée' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( home_url( '/zz-lien-k2c/?ref=x' ) ) ), 'ref=x' ), 'D3 : paramètres saisis par la cliente conservés' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( home_url( '/equipe/zz-lien-k2c/' ) ) ), '/equipe/zz-lien-k2c/' ), 'D3 : autre page de même fin de chemin jamais réécrite' );
	$fluffless_gone      = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Éphémère',
			'post_name'   => 'zz-ephemere-k2c',
		)
	);
	$fluffless_gone_href = home_url( '/?page_id=' . $fluffless_gone );
	wp_delete_post( $fluffless_gone, true );
	$fluffless_assert( str_contains( do_blocks( $fluffless_btn( $fluffless_gone_href ) ), 'href="' . $fluffless_link . '"' ), 'D3 : ?page_id= d’une page supprimée relié à la cible' );
	wp_delete_post( $fluffless_target, true );
	$fluffless_assert( ! str_contains( do_blocks( $fluffless_btn( $fluffless_link ) ), 'wp-block-button__link' ), 'D3 : page supprimée, bouton masqué (aucun 404 permanent)' );
	// Conteneur : un autre bouton, non marqué, garde le conteneur.
	$fluffless_two     = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"fluffless-page-zz-lien-k2c"} --><div class="wp-block-button fluffless-page-zz-lien-k2c"><a class="wp-block-button__link wp-element-button" href="#">Go</a></div><!-- /wp:button --><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://exemple.test/">Autre</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
	$fluffless_two_out = do_blocks( $fluffless_two );
	$fluffless_assert( str_contains( $fluffless_two_out, 'wp-block-buttons' ) && str_contains( $fluffless_two_out, 'Autre' ) && ! str_contains( $fluffless_two_out, '>Go<' ), 'conteneur gardé avec le bouton restant, bouton masqué retiré' );
	// Lien en ligne dans un paragraphe : texte simple quand la page est absente.
	$fluffless_inline = do_blocks( '<!-- wp:paragraph --><p>Texte <a class="fluffless-page-zz-lien-k2c" href="#">le lien</a> fin.</p><!-- /wp:paragraph -->' );
	$fluffless_assert( str_contains( $fluffless_inline, 'Texte le lien fin.' ) && ! str_contains( $fluffless_inline, '<a ' ), 'lien en ligne vers une page absente : texte simple' );
	$fluffless_assert( str_contains( do_blocks( '<!-- wp:paragraph --><p><a href="#">mien</a></p><!-- /wp:paragraph -->' ), 'href="#"' ), 'un lien # sans repère n’est jamais touché' );

	// Les motifs à bouton portent le repère et ne publient aucun # permanent.
	foreach ( array( 'fluffless/page-accueil', 'fluffless/appel-action', 'fluffless/entete-bandeau', 'fluffless/annonce-details' ) as $fluffless_pat ) {
		$fluffless_pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $fluffless_pat );
		$fluffless_assert( is_array( $fluffless_pattern ) && str_contains( (string) $fluffless_pattern['content'], 'fluffless-page-contact' ), "{$fluffless_pat} : repère fluffless-page-contact absent" );
		$fluffless_rendered = do_blocks( is_array( $fluffless_pattern ) ? (string) $fluffless_pattern['content'] : '' );
		$fluffless_assert( ! str_contains( $fluffless_rendered, 'href="#"' ), "{$fluffless_pat} : lien # publié en public" );
	}

	// Site neuf (aucune page d'accueil définie) : « Accueil » ne prend jamais l'adresse de la page affichée.
	$fluffless_front = get_option( 'page_on_front' );
	update_option( 'page_on_front', 0 );
	$GLOBALS['post']    = get_post( $fluffless_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simule la page affichée.
	$fluffless_nav_home = fluffless_nav_links( array( array( 'Accueil', '/' ) ) );
	wp_reset_postdata();
	update_option( 'page_on_front', $fluffless_front );
	$fluffless_assert( str_contains( $fluffless_nav_home, '"url":"' . home_url( '/' ) . '"' ), 'Accueil doit mener à l’accueil du site, pas à la page affichée' );
	$fluffless_assert( ! str_contains( $fluffless_nav_home, 'zz-page-k2a' ), 'Accueil ne doit pas prendre l’adresse de la page affichée' );
	$fluffless_assert( str_contains( $fluffless_nav, '"label":"Présente"' ), 'le lien vers une page publiée doit rester' );
}

// Pieds de page (thème et Suite) : chaque lien interne mène à une page publiée, avec ou sans permaliens.
$fluffless_footers = array();
foreach ( array_keys( WP_Block_Patterns_Registry::get_instance()->get_all_registered() ) as $fluffless_key ) {
	$fluffless_name = (string) ( WP_Block_Patterns_Registry::get_instance()->get_all_registered()[ $fluffless_key ]['name'] ?? '' );
	if ( str_starts_with( $fluffless_name, 'fluffless/pied-de-page' ) ) {
		$fluffless_footers[] = $fluffless_name;
	}
}
$fluffless_assert( count( $fluffless_footers ) >= 4, 'les quatre pieds de page (thème et Suite) doivent être couverts' );
$fluffless_home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$fluffless_structure = (string) get_option( 'permalink_structure' );
foreach ( array( $fluffless_structure, '' ) as $fluffless_mode ) {
	$GLOBALS['wp_rewrite']->set_permalink_structure( $fluffless_mode );
	foreach ( array_unique( $fluffless_footers ) as $fluffless_slug ) {
		$fluffless_html = do_blocks( '<!-- wp:pattern {"slug":"' . $fluffless_slug . '"} /-->' );
		preg_match_all( '/<a [^>]*href="([^"]*)"/', $fluffless_html, $fluffless_found );
		foreach ( $fluffless_found[1] as $fluffless_href ) {
			$fluffless_href = html_entity_decode( $fluffless_href );
			$fluffless_host = (string) wp_parse_url( $fluffless_href, PHP_URL_HOST );
			if ( '' !== $fluffless_host && $fluffless_host !== $fluffless_home_host ) {
				continue;
			}
			$fluffless_assert( '#' !== $fluffless_href, "{$fluffless_slug} : lien # permanent" );
			if ( '#' === $fluffless_href || str_starts_with( $fluffless_href, 'mailto:' ) || str_starts_with( $fluffless_href, 'tel:' ) ) {
				continue;
			}
			$fluffless_pid = url_to_postid( $fluffless_href );
			if ( 0 === $fluffless_pid ) {
				$fluffless_assert( untrailingslashit( home_url() ) === untrailingslashit( $fluffless_href ), "{$fluffless_slug} ({$fluffless_mode}) : {$fluffless_href} ne mène à aucune page" );
			} else {
				$fluffless_assert( 'publish' === get_post_status( $fluffless_pid ), "{$fluffless_slug} ({$fluffless_mode}) : {$fluffless_href} mène à une page non publiée" );
			}
		}
	}
}
$GLOBALS['wp_rewrite']->set_permalink_structure( $fluffless_structure );

// Tous les motifs qui appellent fluffless_page_url : avec les pages publiées, plus aucun lien « # ».
$fluffless_pages = array();
foreach ( array( 'services', 'contact', 'portfolio', _x( 'about', 'page address', 'fluffless' ) ) as $fluffless_slug_page ) {
	if ( null === fluffless_published_page( '/' . $fluffless_slug_page . '/' ) ) {
		$fluffless_pages[] = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Page ' . $fluffless_slug_page,
				'post_name'   => $fluffless_slug_page,
			)
		);
	}
}
$fluffless_created = array_merge( $fluffless_created, $fluffless_pages );
$fluffless_dirs    = array_values( array_filter( array_merge( (array) glob( get_template_directory() . '/patterns/*.php' ), (array) glob( WP_PLUGIN_DIR . '/fluffless-suite/patterns/*.php' ) ), 'is_string' ) );
$fluffless_dead    = array();
$fluffless_covered = 0;
foreach ( $fluffless_dirs as $fluffless_file ) {
	$fluffless_source = (string) file_get_contents( $fluffless_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! str_contains( $fluffless_source, 'fluffless_page_url' ) ) {
		continue;
	}
	++$fluffless_covered;
	ob_start();
	include $fluffless_file;
	$fluffless_markup = (string) ob_get_clean();
	if ( preg_match( '/<a [^>]*href="#"|"url":"#"/', $fluffless_markup ) ) {
		$fluffless_dead[] = basename( $fluffless_file );
	}
}
$fluffless_assert( $fluffless_covered > 0, 'aucun motif ne passe par fluffless_page_url ?' );
$fluffless_assert( array() === $fluffless_dead, 'motifs avec un lien # malgré les pages publiées : ' . implode( ', ', $fluffless_dead ) );

// Plus aucun chemin de page codé en dur dans les motifs.
$fluffless_hardcoded = array();
$fluffless_files     = array_merge( (array) glob( get_template_directory() . '/patterns/*.php' ), (array) glob( WP_PLUGIN_DIR . '/fluffless-suite/patterns/*.php' ) );
$fluffless_files     = array_values( array_filter( $fluffless_files, 'is_string' ) );
foreach ( $fluffless_files as $fluffless_file ) {
	$fluffless_source = (string) file_get_contents( $fluffless_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( preg_match( "/home_url\( '\/(?:contact|services|portfolio|a-propos)\/' \)|home_url\( '\/' \. _x\( 'about'/", $fluffless_source ) ) {
		$fluffless_hardcoded[] = basename( $fluffless_file );
	}
}
$fluffless_assert( array() === $fluffless_hardcoded, 'chemins de page codés en dur : ' . implode( ', ', $fluffless_hardcoded ) );


foreach ( $fluffless_created as $fluffless_post_id ) {
	wp_delete_post( (int) $fluffless_post_id, true );
}

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( 'starter-links : ' . count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} :\n - " . implode( "\n - ", $fluffless_failures ) );
}
WP_CLI::success( "starter-links : {$fluffless_checks} vérifications passées." );
