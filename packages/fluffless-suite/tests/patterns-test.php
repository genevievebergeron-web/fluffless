<?php
/**
 * Tests de l'enregistrement des patterns de Suite.
 *
 * Lancer : npm run test:tools
 * (docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/patterns-test.php)
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 *
 * @package Fluffless_Suite
 */

// Le test filtre des crochets du cœur : ces noms ne peuvent pas porter le préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use Fluffless\Suite\Presentation\Patterns;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Patterns::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}
if ( Patterns::TEMPLATE !== get_template() ) {
	throw new RuntimeException( esc_html( 'Thème actif : ' . get_template() . '. Le test attend le thème fluffless (wp theme activate fluffless).' ) );
}
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Les erreurs de développeur sont captées, pas affichées : le test vérifie leur contenu.
$fluffless_wrong = array();
add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
add_action(
	'doing_it_wrong_run',
	static function ( string $function_name, string $message ) use ( &$fluffless_wrong ): void {
		$fluffless_wrong[] = $function_name . ' ' . $message;
	},
	10,
	2
);

$fluffless_tmp = static function ( string $content ): string {
	$file = wp_tempnam( 'fluffless-pattern' );
	file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return $file;
};

/*
 * Lecture d'en-tête valide : listes découpées, largeur en entier, filePath conservé.
 */
$fluffless_file    = $fluffless_tmp( "<?php\n/**\n * Title: Probe pattern\n * Slug: fluffless/probe\n * Categories: fluffless-pages, fluffless-agir\n * Keywords: one, two\n * Viewport Width: 1200\n * Inserter: no\n * Description: A probe.\n */\n?>\n<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->\n" );
$fluffless_pattern = Patterns::read( $fluffless_file );
$fluffless_assert( is_array( $fluffless_pattern ), 'Un en-tête valide doit être lu.' );
$fluffless_pattern = (array) $fluffless_pattern;
$fluffless_assert( 'fluffless/probe' === ( $fluffless_pattern['slug'] ?? null ), 'Slug lu.' );
$fluffless_assert( 'Probe pattern' === ( $fluffless_pattern['title'] ?? null ), 'Titre lu (en_US : non traduit).' );
$fluffless_assert( array( 'fluffless-pages', 'fluffless-agir' ) === ( $fluffless_pattern['categories'] ?? null ), 'Catégories découpées aux virgules.' );
$fluffless_assert( 1200 === ( $fluffless_pattern['viewportWidth'] ?? null ), 'Largeur convertie en entier.' );
$fluffless_assert( false === ( $fluffless_pattern['inserter'] ?? null ), 'Inserter: no donne false.' );
$fluffless_assert( ( $fluffless_pattern['filePath'] ?? null ) === $fluffless_file, 'filePath pointe sur le fichier lu.' );
$fluffless_assert( ! isset( $fluffless_pattern['postTypes'] ), 'Un en-tête absent reste absent.' );
wp_delete_file( $fluffless_file );

/*
 * En-tête Fluffless Modules : lu en liste ; un module inconnu ou inactif écarte le pattern.
 */
$fluffless_file    = $fluffless_tmp( "<?php\n/**\n * Title: Needs module\n * Slug: fluffless/probe-module\n * Fluffless Modules: expression, module-inexistant\n */\n?>\n" );
$fluffless_pattern = (array) Patterns::read( $fluffless_file );
$fluffless_assert( array( 'expression', 'module-inexistant' ) === ( $fluffless_pattern['modules'] ?? null ), 'Fluffless Modules découpé aux virgules.' );
$fluffless_assert( false === Patterns::modules_active( $fluffless_pattern ), 'Un module inexistant écarte le pattern.' );
$fluffless_assert( true === Patterns::modules_active( array( 'slug' => 'fluffless/x' ) ), 'Sans en-tête Fluffless Modules, le pattern est proposé.' );
wp_delete_file( $fluffless_file );

/*
 * Chemin d'erreur : slug hors espace de nom fluffless/, rejeté avec le fichier et la valeur reçue.
 */
$fluffless_file = $fluffless_tmp( "<?php\n/**\n * Title: Foreign\n * Slug: autre/probe\n */\n" );
$fluffless_assert( null === Patterns::read( $fluffless_file ), 'Un slug hors fluffless/ doit être rejeté.' );
$fluffless_last = (string) end( $fluffless_wrong );
$fluffless_assert( str_contains( $fluffless_last, $fluffless_file ) && str_contains( $fluffless_last, 'autre/probe' ), 'Le rejet nomme le fichier et le slug reçu.' );
wp_delete_file( $fluffless_file );

$fluffless_file = $fluffless_tmp( "<?php\n/**\n * Slug: fluffless/sans-titre\n */\n" );
$fluffless_assert( null === Patterns::read( $fluffless_file ), 'Un pattern sans titre doit être rejeté.' );
wp_delete_file( $fluffless_file );

/*
 * Chaque fichier de patterns/ est enregistré, et c'est bien la version de Suite qui gagne.
 */
$fluffless_registry = WP_Block_Patterns_Registry::get_instance();
foreach ( Patterns::files() as $fluffless_path ) {
	$fluffless_read = Patterns::read( $fluffless_path );
	$fluffless_name = basename( $fluffless_path );
	$fluffless_assert( null !== $fluffless_read, "En-tête valide : {$fluffless_name}." );
	if ( null === $fluffless_read ) {
		continue;
	}
	$fluffless_registered = $fluffless_registry->get_registered( $fluffless_read['slug'] );
	if ( ! Patterns::modules_active( $fluffless_read ) ) {
		$fluffless_assert( null === $fluffless_registered, "{$fluffless_read['slug']} attend un module inactif : il ne doit pas être enregistré." );
		continue;
	}
	$fluffless_assert( is_array( $fluffless_registered ), "Pattern enregistré : {$fluffless_read['slug']}." );
	$fluffless_assert( ! isset( $fluffless_registered['modules'] ), "{$fluffless_read['slug']} : l'en-tête Fluffless Modules n'est pas transmis au registre." );
	$fluffless_assert( ( $fluffless_registered['filePath'] ?? null ) === $fluffless_path, "{$fluffless_read['slug']} vient de Suite (reçu : " . ( $fluffless_registered['filePath'] ?? 'aucun filePath' ) . ').' );
	$fluffless_assert( ! str_contains( (string) file_get_contents( $fluffless_path ), "'fluffless' )" ), "{$fluffless_name} traduit dans fluffless-suite, pas dans le domaine du thème." ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

/*
 * Cadre photo vide : une image marquée sans source garde son cadre, les autres ne changent pas.
 */
$fluffless_team = WP_Block_Patterns_Registry::get_instance()->get_registered( 'fluffless/page-equipe' );
$fluffless_html = do_blocks( (string) ( $fluffless_team['content'] ?? '' ) );
$fluffless_assert( 4 === substr_count( $fluffless_html, 'class="wp-block-image is-style-nb-survol ' . Patterns::PHOTO_FRAME . '" aria-hidden="true" style="aspect-ratio:3/4;' ), 'Page Équipe sans photos : quatre cadres vides au ratio 3/4, masqués aux lecteurs d\'écran.' );
$fluffless_assert( 4 === substr_count( $fluffless_html, '<svg ' ), 'Page Équipe sans photos : une icône de photo par cadre.' );
$fluffless_assert( '' === trim( do_blocks( '<!-- wp:image --><figure class="wp-block-image"><img alt=""/></figure><!-- /wp:image -->' ) ), 'Image vide sans la classe : rien n\'est rendu, comme dans le cœur.' );
$fluffless_filled = do_blocks( '<!-- wp:image {"className":"' . Patterns::PHOTO_FRAME . '"} --><figure class="wp-block-image ' . Patterns::PHOTO_FRAME . '"><img src="https://example.test/a.jpg" alt="Portrait"/></figure><!-- /wp:image -->' );
$fluffless_assert( str_contains( $fluffless_filled, 'src="https://example.test/a.jpg"' ) && ! str_contains( $fluffless_filled, 'aria-hidden' ), 'Image marquée avec une photo : rendu du cœur inchangé.' );
$fluffless_odd = do_blocks( '<!-- wp:image {"aspectRatio":"1/0;color:red","className":"' . Patterns::PHOTO_FRAME . '"} --><figure class="wp-block-image ' . Patterns::PHOTO_FRAME . '"><img alt=""/></figure><!-- /wp:image -->' );
$fluffless_assert( str_contains( $fluffless_odd, 'aspect-ratio:1;' ) && ! str_contains( $fluffless_odd, 'color:red' ), 'Ratio invalide : cadre carré, aucune déclaration injectée.' );

/*
 * Catégories présentes, et hors thème Fluffless rien n'est enregistré.
 */
foreach ( array_keys( Patterns::categories() ) as $fluffless_category ) {
	$fluffless_assert( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $fluffless_category ), "Catégorie enregistrée : {$fluffless_category}." );
}
$fluffless_other_theme = static fn(): string => 'twentytwentyfive';
add_filter( 'template', $fluffless_other_theme );
$fluffless_assert( false === Patterns::applies(), 'Sous un autre thème, Suite n\'enregistre aucun pattern.' );
remove_filter( 'template', $fluffless_other_theme );

/*
 * Compositions de pages (F16) : le fichier est rendu tel quel, comme à l'insertion.
 */
$fluffless_compose       = static function ( string $path ): string {
	ob_start();
	include $path;
	return (string) ob_get_clean();
};
$fluffless_suite_pattern = static fn( string $name ): string => $fluffless_compose( dirname( __DIR__ ) . '/patterns/' . $name . '.php' );
$fluffless_theme_pattern = static fn( string $name ): string => $fluffless_compose( get_template_directory() . '/patterns/' . $name . '.php' );
// Extrait entre deux repères (vide si l'un manque) : sert à situer un bloc dans la page.
$fluffless_between = static function ( string $html, string $from, string $to ): string {
	$start = strpos( $html, $from );
	$end   = false === $start ? false : strpos( $html, $to, $start + strlen( $from ) );
	return false === $end ? '' : substr( $html, (int) $start, $end - (int) $start );
};

// C1-3-01 : sans catégorie « portfolio », la grille est vide et affiche son message, pas tout le blogue.
$fluffless_no_portfolio = static fn( $terms, $taxonomies, $args ) => in_array( 'portfolio', (array) ( $args['slug'] ?? array() ), true ) ? array() : $terms;
add_filter( 'get_terms', $fluffless_no_portfolio, 10, 3 );
$fluffless_assert( (int) wp_count_posts()->publish > 0, 'C1-3-01 : le banc doit avoir au moins un article publié pour que le test ait un sens.' );
$fluffless_html = do_blocks( $fluffless_suite_pattern( 'page-portfolio' ) );
$fluffless_assert( str_contains( $fluffless_html, 'No projects yet.' ), 'C1-3-01 : Portfolio sans catégorie « portfolio » : message d’état vide attendu, pas la liste des articles.' );
remove_filter( 'get_terms', $fluffless_no_portfolio, 10 );

// P9a-07 : un seul repère, l'étiquette ; le filtre liste les étiquettes des projets, sans repli statique.
$fluffless_cat  = wp_insert_term( 'Portfolio', 'category', array( 'slug' => 'portfolio' ) );
$fluffless_cat  = is_wp_error( $fluffless_cat ) ? (int) $fluffless_cat->get_error_data( 'term_exists' ) : (int) $fluffless_cat['term_id'];
$fluffless_post = wp_insert_post(
	array(
		'post_title'    => 'Projet F16',
		'post_status'   => 'publish',
		'post_category' => array( $fluffless_cat ),
		'tags_input'    => array( 'image-f16' ),
	)
);
$fluffless_html = $fluffless_suite_pattern( 'page-portfolio' );
$fluffless_assert( str_contains( $fluffless_html, 'tag=image-f16' ), 'P9a-07 : le filtre mène à « portfolio » restreint à l’étiquette du projet.' );
$fluffless_assert( ! str_contains( $fluffless_html, '>Branding<' ) && ! str_contains( $fluffless_html, '>Websites<' ), 'P9a-07 : aucun type statique sans lien.' );
wp_delete_post( (int) $fluffless_post, true );
$fluffless_tag = get_term_by( 'slug', 'image-f16', 'post_tag' );
if ( $fluffless_tag instanceof WP_Term ) {
	wp_delete_term( $fluffless_tag->term_id, 'post_tag' );
}
wp_delete_term( $fluffless_cat, 'category' );
$fluffless_html = $fluffless_suite_pattern( 'page-portfolio' );
$fluffless_assert( ! str_contains( $fluffless_html, '>Branding<' ) && ! str_contains( $fluffless_html, 'Filter:' ), 'P9a-07 : sans projet ni étiquette, aucune ligne de filtre.' );

// C1-9-04 : le dernier bouton de Réalisations mène à Contact.
$fluffless_html = $fluffless_suite_pattern( 'page-portfolio' );
$fluffless_assert( 1 === preg_match( '/' . preg_quote( fluffless_page_class( '/contact/' ), '/' ) . '[^"]*"><a [^>]*href="[^"]+">Your next step</', $fluffless_html ), 'C1-9-04 : « Your next step » de Réalisations porte une adresse et le repère de /contact/.' );

// C1-3-02 : la carte « Donner » et l'ouverture ont un bouton de don plein.
$fluffless_html = $fluffless_suite_pattern( 'page-don' );
$fluffless_give = $fluffless_between( $fluffless_html, 'Give</h3>', 'Volunteer</h3>' );
$fluffless_assert( str_contains( $fluffless_give, '>Make a donation</a>' ) && ! str_contains( $fluffless_give, 'is-style-outline' ), 'C1-3-02 : carte « Donner » : bouton plein « Make a donation ».' );
$fluffless_assert( str_contains( $fluffless_between( $fluffless_html, '<h1', 'What one gift makes possible' ), '>Make a donation</a>' ), 'C1-3-02 : bouton de don sous la promesse de l’ouverture.' );

// P9a-12 : une preuve près du don et près des prix.
$fluffless_html = $fluffless_suite_pattern( 'page-don' );
$fluffless_assert( str_contains( $fluffless_between( $fluffless_html, 'Where the money goes', 'Two ways to help' ), '<blockquote' ), 'P9a-12 : Don : citation après « Where the money goes ».' );
$fluffless_html = $fluffless_suite_pattern( 'page-tarifs' );
$fluffless_assert( str_contains( $fluffless_between( $fluffless_html, 'Choose your plan', 'In every plan' ), '<blockquote' ), 'P9a-12 : Tarifs : citation avant « In every plan ».' );

// P9a-11 : l'étude de cas a une mise en bouche entre le titre et les métadonnées.
$fluffless_html = $fluffless_suite_pattern( 'page-etude-de-cas' );
$fluffless_assert( str_contains( $fluffless_between( $fluffless_html, '</h1>', 'Sector:' ), 'Three or four sentences: the strongest result' ), 'P9a-11 : mise en bouche après le h1 de l’étude de cas.' );

// P9a-05 : page de vente, déroulement et présentation ajoutés, preuve et garantie avant le prix.
$fluffless_html   = $fluffless_suite_pattern( 'page-vente' );
$fluffless_prices = (int) strpos( $fluffless_html, 'The prices, in plain sight' );
$fluffless_assert( str_contains( $fluffless_between( $fluffless_html, 'What changes once it’s done', 'Is it for you?' ), '>How it works<' ), 'P9a-05 : « How it works » après les changements.' );
$fluffless_assert( str_contains( $fluffless_between( $fluffless_html, 'Is it for you?', 'The prices, in plain sight' ), Patterns::PHOTO_FRAME ), 'P9a-05 : « Who I am » avec un cadre photo avant le prix.' );
foreach ( array( 'Paste a real sentence from a client', 'Our guarantee, with its limits' ) as $fluffless_marker ) {
	$fluffless_at = strpos( $fluffless_html, $fluffless_marker );
	$fluffless_assert( false !== $fluffless_at && $fluffless_at < $fluffless_prices, "P9a-05 : « {$fluffless_marker} » avant le prix." );
}

// P9a-05 : la garantie est maintenant plus haut que les objections.
$fluffless_assert( ! str_contains( $fluffless_suite_pattern( 'page-vente' ), 'guarantee below' ), 'P9a-05 : les objections ne renvoient plus à une garantie « plus bas ».' );

// P9b-10 : aucun superlatif invérifiable en titre de départ.
$fluffless_assert( ! str_contains( $fluffless_theme_pattern( 'page-accueil' ), 'better than anyone' ), 'P9b-10 : « What we do better than anyone » retiré de l’accueil.' );

// P9b-11 : le bouton du héros et l'appel final de l'accueil portent le même libellé.
preg_match_all( '/wp-element-button"[^>]*>([^<]+)<\/a>/', $fluffless_theme_pattern( 'page-accueil' ), $fluffless_labels );
$fluffless_assert( count( $fluffless_labels[1] ) >= 2 && reset( $fluffless_labels[1] ) === end( $fluffless_labels[1] ), 'P9b-11 : premier et dernier bouton de l’accueil identiques (reçu : ' . implode( ' / ', $fluffless_labels[1] ) . ').' );

// P9b-16 : le bouton vers À propos ne promet pas des témoignages.
$fluffless_assert( ! str_contains( $fluffless_theme_pattern( 'page-accueil' ), 'Read the testimonials' ), 'P9b-16 : « Read the testimonials » ne mène plus à À propos.' );

// P9b-13 : le titre de la liste de prix ne contredit plus la tuile sur mesure.
$fluffless_assert( ! str_contains( $fluffless_theme_pattern( 'liste-de-prix' ), 'no quote to ask for' ), 'P9b-13 : « no quote to ask for » retiré du titre.' );

// P9b-14 : le lien d'itinéraire est une adresse de départ signalée, pas la racine d'OSM.
foreach ( array( 'page-contact', 'contact' ) as $fluffless_name ) {
	$fluffless_assert( ! str_contains( $fluffless_theme_pattern( $fluffless_name ), 'openstreetmap.org/"' ), "P9b-14 : {$fluffless_name} : itinéraire sans la racine d’OpenStreetMap." );
}

// P9b-15 : recrutement en contour, libellé qui dit ce qui se passe à /contact/.
foreach ( array( 'page-a-propos', 'equipe' ) as $fluffless_name ) {
	$fluffless_html = $fluffless_theme_pattern( $fluffless_name );
	$fluffless_assert( ! str_contains( $fluffless_html, 'See open roles' ), "P9b-15 : {$fluffless_name} : « See open roles » retiré." );
	$fluffless_assert( 1 === preg_match( '/is-style-contour[^"]*"><a [^>]*>Send your application</', $fluffless_html ), "P9b-15 : {$fluffless_name} : « Send your application » en contour." );
}

// P9b-17 : la FAQ de Contact est pratique, distincte de celle de Services.
preg_match_all( '/<summary>([^<]+)<\/summary>/', $fluffless_theme_pattern( 'page-contact' ), $fluffless_contact_q );
preg_match_all( '/<summary>([^<]+)<\/summary>/', $fluffless_theme_pattern( 'page-services' ), $fluffless_services_q );
$fluffless_shared = array_intersect( $fluffless_contact_q[1], $fluffless_services_q[1] );
$fluffless_assert( count( $fluffless_contact_q[1] ) >= 3 && array() === $fluffless_shared, 'P9b-17 : questions de Contact distinctes de Services (en commun : ' . implode( ' / ', $fluffless_shared ) . ').' );

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Patterns de Suite : {$fluffless_checks} vérifications passées (" . count( Patterns::files() ) . ' fichiers).' );
