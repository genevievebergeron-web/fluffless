<?php
/**
 * Tests de Fluffless SEO : retrait du module de Suite, gabarits, balises, robots,
 * données structurées (SoftwareApplication, filtre du graphe), fil d'Ariane,
 * plan de site (statut 200), hreflang x-default, liste de désinstallation.
 *
 * Lancer : npm run test:tools -- seo
 * (docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-seo/tests/seo-test.php)
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul. Réglages et contenus restaurés à la fin.
 *
 * @package Fluffless_Seo
 */

use Fluffless\Seo\Breadcrumbs;
use Fluffless\Seo\Cli;
use Fluffless\Seo\Context;
use Fluffless\Seo\Head;
use Fluffless\Seo\Meta;
use Fluffless\Seo\Plugin;
use Fluffless\Seo\Robots;
use Fluffless\Seo\Schema;
use Fluffless\Seo\Settings;
use Fluffless\Seo\Sitemaps;
use Fluffless\Seo\Store;
use Fluffless\Suite\Modules\Seo as LightSeo;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

// Extension optionnelle : inactive sur le banc, le test est sauté et le dit, sans échouer.
if ( ! defined( 'FLUFFLESS_SEO_VERSION' ) || ! class_exists( Head::class ) ) {
	echo esc_html( "SAUTÉ : Fluffless SEO inactive (wp plugin activate fluffless-seo pour lancer ces contrôles).\n" );
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

$fluffless_saved = get_option( Settings::NAME );
$fluffless_post  = 0;
$fluffless_pages = array();

/**
 * Place la requête principale sur un contenu, comme au rendu d'une page.
 *
 * @param array<string, mixed> $query Arguments de WP_Query.
 */
$fluffless_go = static function ( array $query ): void {
	$GLOBALS['wp_query']     = new WP_Query( $query ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation de la requête principale.
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- idem.
	if ( $GLOBALS['wp_query']->have_posts() ) {
		$GLOBALS['post'] = $GLOBALS['wp_query']->posts[0]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- idem.
	}
	Context::reset();
	Breadcrumbs::reset();
};

try {
	// Le module SEO léger de Suite cède la place.
	// Le module SEO léger de Suite cède la place, sans compter Fluffless SEO comme un conflit.
	$fluffless_assert( LightSeo::companion() && null === LightSeo::conflict(), 'Suite devrait voir Fluffless SEO comme compagne, pas comme conflit, reçu : ' . var_export( LightSeo::conflict(), true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- message de test.
	$fluffless_assert( false === has_action( 'wp_head', array( LightSeo::class, 'print_head' ) ), 'Le module SEO de Suite imprime encore ses balises malgré Fluffless SEO.' );
	$fluffless_assert( str_contains( LightSeo::status(), 'Fluffless SEO' ), 'Les réglages de Suite devraient dire que Fluffless SEO a pris le relais.' );

	// Avis quand Rank Math est actif sans avoir terminé sa configuration (C-S8-09).
	$fluffless_rm_force = static fn(): string => 'Rank Math';
	add_filter( 'fluffless_seo_suite_conflict', $fluffless_rm_force );
	$fluffless_rm_user  = get_current_user_id();
	wp_set_current_user( 1 );
	$fluffless_rm_saved = get_option( 'rank_math_is_configured', null );
	delete_option( 'rank_math_is_configured' );
	ob_start();
	\Fluffless\Seo\Plugin::notice_rank_math_unconfigured();
	$fluffless_rm_out = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_rm_out, 'notice-warning' ) && str_contains( $fluffless_rm_out, 'Rank Math' ), 'Aucun avis alors que Rank Math est actif sans configuration.' );
	update_option( 'rank_math_is_configured', true );
	ob_start();
	\Fluffless\Seo\Plugin::notice_rank_math_unconfigured();
	$fluffless_assert( '' === (string) ob_get_clean(), 'Avis affiché alors que Rank Math est configuré.' );
	remove_filter( 'fluffless_seo_suite_conflict', $fluffless_rm_force );
	delete_option( 'rank_math_is_configured' );
	ob_start();
	\Fluffless\Seo\Plugin::notice_rank_math_unconfigured();
	$fluffless_assert( '' === (string) ob_get_clean(), 'Avis affiché sans extension concurrente.' );
	if ( null !== $fluffless_rm_saved ) {
		update_option( 'rank_math_is_configured', $fluffless_rm_saved );
	}
	wp_set_current_user( $fluffless_rm_user );

	// Liste de désinstallation identique à Meta::OWN_KEYS.
	$fluffless_uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
	preg_match_all( "/'(_fluffless_seo_[a-z_]+)'/", $fluffless_uninstall, $fluffless_keys );
	$fluffless_assert( Meta::OWN_KEYS === $fluffless_keys[1], 'uninstall.php et Meta::OWN_KEYS divergent : ' . implode( ', ', array_diff( Meta::OWN_KEYS, $fluffless_keys[1] ) ) );
	$fluffless_assert( ! str_contains( $fluffless_uninstall, "'_fluffless_description'" ), 'La désinstallation ne doit pas effacer la description partagée avec Suite.' );

	Settings::update(
		array(
			'templates'           => array( 'post' => array( 'title' => '%%title%% %%sep%% %%sitename%%' ) ),
			'sep'                 => '|',
			'sitemap_exclude_tax' => array( 'post_tag' ),
		)
	);
	Settings::flush();

	$fluffless_created = wp_insert_post(
		array(
			'post_title'   => 'Titre de test SEO',
			'post_content' => '<!-- wp:paragraph --><p>Contenu de test pour la description automatique.</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		),
		true
	);
	if ( is_wp_error( $fluffless_created ) ) {
		throw new RuntimeException( esc_html( 'Création du contenu de test impossible : ' . $fluffless_created->get_error_message() ) );
	}
	$fluffless_post = $fluffless_created;
	$fluffless_go( array( 'p' => $fluffless_post ) );

	// Gabarits : variables remplacées, inconnues retirées, séparateurs doublés nettoyés.
	$fluffless_expected = 'Titre de test SEO | ' . get_bloginfo( 'name' );
	$fluffless_assert( 'post' === Context::key(), 'Contexte attendu post, reçu ' . Context::key() );
	$fluffless_assert( Context::title() === $fluffless_expected, 'Titre par gabarit inattendu : ' . Context::title() );
	$fluffless_assert( Context::replace( '%%title%% %%sep%% %%inconnue%% %%sep%% %%sitename%%' ) === $fluffless_expected, 'Variable inconnue mal retirée : ' . Context::replace( '%%title%% %%sep%% %%inconnue%% %%sep%% %%sitename%%' ) );
	$fluffless_assert( '' === Context::replace( '%%inconnue%%' ), 'Un gabarit fait seulement de variables inconnues doit donner une chaîne vide.' );
	$fluffless_assert( str_contains( Context::description(), 'Contenu de test' ), 'Description tirée du contenu attendue, reçu : ' . Context::description() );
	$fluffless_assert( mb_strlen( Context::shorten( str_repeat( 'mot ', 100 ), 160 ) ) <= 160, 'shorten() dépasse la limite.' );

	// Champs du contenu prioritaires sur le gabarit.
	update_post_meta( $fluffless_post, Meta::TITLE, 'Titre choisi' );
	update_post_meta( $fluffless_post, Meta::ROBOTS, array( 'noindex', 'nosnippet' ) );
	$fluffless_go( array( 'p' => $fluffless_post ) );
	$fluffless_assert( str_starts_with( Context::title(), 'Titre choisi' ), 'Le titre du contenu doit l\'emporter : ' . Context::title() );
	$fluffless_robots = Head::robots(
		array(
			'index'  => true,
			'follow' => true,
		)
	);
	$fluffless_assert( ! empty( $fluffless_robots['noindex'] ) && ! empty( $fluffless_robots['nosnippet'] ) && ! isset( $fluffless_robots['index'] ), 'Directives robots du contenu mal appliquées : ' . wp_json_encode( $fluffless_robots ) );
	delete_post_meta( $fluffless_post, Meta::ROBOTS );
	$fluffless_go( array( 'p' => $fluffless_post ) );

	// Balises : description, canonique, partage, JSON-LD, sous le budget de 3 ko.
	$fluffless_tags = Head::tags();
	foreach ( array( '<meta name="description"', '<link rel="canonical"', 'property="og:title"', 'name="twitter:card"', 'application/ld+json' ) as $fluffless_needle ) {
		$fluffless_assert( str_contains( $fluffless_tags, $fluffless_needle ), 'Balise absente de l\'en-tête : ' . $fluffless_needle );
	}
	$fluffless_assert( strlen( $fluffless_tags ) < 3072, 'En-tête au-delà de 3 ko : ' . strlen( $fluffless_tags ) . ' octets.' );
	$fluffless_assert( ! preg_match( '/<script(?![^>]*application\/ld\+json)/', $fluffless_tags ), 'Aucun script exécutable ne doit être servi aux visiteurs.' );

	// Graphe : un seul bloc, WebSite, page, article, fil d'Ariane.
	$fluffless_json = Schema::json_ld();
	$fluffless_assert( 1 === substr_count( $fluffless_json, '<script' ), 'Un seul bloc JSON-LD attendu.' );
	foreach ( array( '"@graph"', '"WebSite"', '"WebPage"', '"BreadcrumbList"' ) as $fluffless_needle ) {
		$fluffless_assert( str_contains( $fluffless_json, $fluffless_needle ), 'JSON-LD sans ' . $fluffless_needle );
	}
	$fluffless_assert( (bool) preg_match( '/"(Article|BlogPosting)"/', $fluffless_json ), 'Un article devrait être décrit comme Article ou BlogPosting.' );
	$fluffless_payload = preg_replace( '/^<script[^>]*>|<\/script>\s*$/', '', trim( $fluffless_json ) );
	$fluffless_assert( is_array( json_decode( (string) $fluffless_payload, true ) ), 'JSON-LD illisible : ' . json_last_error_msg() );

	// Vente simple : une page avec un bloc Produit donne un nœud Product et son offre dans le graphe.
	$fluffless_produit = wp_insert_post(
		array(
			'post_title'   => 'Page produit de test SEO',
			'post_content' => '<!-- wp:fluffless/produit --><div class="wp-block-fluffless-produit fluffless-section fluffless-produit is-photo-side"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Atelier</h3><!-- /wp:heading --><!-- wp:fluffless/prix {"price":"49.99"} /--><!-- wp:paragraph --><p>Deux heures.</p><!-- /wp:paragraph --></div><!-- /wp:fluffless/produit -->',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		),
		true
	);
	if ( ! is_wp_error( $fluffless_produit ) ) {
		$fluffless_pages[] = $fluffless_produit;
		$fluffless_go( array( 'page_id' => $fluffless_produit ) );
		LightSeo::reset();
		$fluffless_json = Schema::json_ld();
		$fluffless_assert( str_contains( $fluffless_json, '"Product"' ) && str_contains( $fluffless_json, '"Offer"' ) && str_contains( $fluffless_json, '"price":"49.99"' ), 'JSON-LD sans le produit de la Vente simple : ' . $fluffless_json );
		$fluffless_go( array( 'p' => $fluffless_post ) );
	} else {
		$fluffless_assert( false, 'Page produit de test impossible : ' . $fluffless_produit->get_error_message() );
	}

	// FAQPage : une page avec un bloc Détails; réglage faq_schema éteint, aucun FAQPage; filtre fluffless_seo_schema_faq pris en compte.
	$fluffless_faq_page = wp_insert_post(
		array(
			'post_title'   => 'Page FAQ de test SEO',
			'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:details --><details class="wp-block-details"><summary>Question de test?</summary><!-- wp:paragraph --><p>Réponse de test.</p><!-- /wp:paragraph --></details><!-- /wp:details --></div><!-- /wp:group -->',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		),
		true
	);
	if ( ! is_wp_error( $fluffless_faq_page ) ) {
		$fluffless_pages[] = $fluffless_faq_page;
		$fluffless_go( array( 'page_id' => $fluffless_faq_page ) );
		LightSeo::reset();
		$fluffless_assert( str_contains( Schema::json_ld(), '"FAQPage"' ), 'FAQPage absente avec faq_schema par défaut.' );
		Settings::update( array( 'faq_schema' => false ) );
		Settings::flush();
		LightSeo::reset();
		$fluffless_assert( ! str_contains( Schema::json_ld(), 'FAQPage' ), 'FAQPage émise malgré faq_schema éteint.' );
		Settings::update( array( 'faq_schema' => true ) );
		Settings::flush();
		add_filter( 'fluffless_seo_schema_faq', '__return_null' );
		LightSeo::reset();
		$fluffless_assert( ! str_contains( Schema::json_ld(), 'FAQPage' ), 'Le filtre fluffless_seo_schema_faq n\'a pas retiré la FAQPage.' );
		remove_filter( 'fluffless_seo_schema_faq', '__return_null' );
		$fluffless_go( array( 'p' => $fluffless_post ) );
	} else {
		$fluffless_assert( false, 'Page FAQ de test impossible : ' . $fluffless_faq_page->get_error_message() );
	}

	// Filtre fluffless_seo_image : reçoit l'adresse résolue et le contenu, appliqué une fois, vide = pas d'image.
	$fluffless_image_calls = array();
	$fluffless_image_cb    = static function ( string $url, int $post_id ) use ( &$fluffless_image_calls ): string {
		$fluffless_image_calls[] = array( $url, $post_id );
		return 'https://example.invalid/filtre.png';
	};
	add_filter( 'fluffless_seo_image', $fluffless_image_cb, 10, 2 );
	Context::reset();
	$fluffless_tags = Head::tags();
	Schema::json_ld();
	remove_filter( 'fluffless_seo_image', $fluffless_image_cb, 10 );
	$fluffless_assert( str_contains( $fluffless_tags, '<meta property="og:image" content="https://example.invalid/filtre.png">' ), 'Filtre fluffless_seo_image ignoré : ' . $fluffless_tags );
	$fluffless_assert( array( $fluffless_post ) === array_column( $fluffless_image_calls, 1 ), 'Filtre fluffless_seo_image appelé plus d\'une fois ou sans le contenu : ' . wp_json_encode( $fluffless_image_calls ) );
	add_filter( 'fluffless_seo_image', '__return_empty_string' );
	Context::reset();
	$fluffless_tags = Head::tags();
	remove_filter( 'fluffless_seo_image', '__return_empty_string' );
	$fluffless_assert( ! str_contains( $fluffless_tags, 'og:image' ) && str_contains( $fluffless_tags, 'name="twitter:card" content="summary"' ), 'Image retirée par le filtre : og:image ou carte large encore là : ' . $fluffless_tags );
	Context::reset();

	// Image convertie en WebP à l'envoi : og:image sert l'original PNG resté sur disque (LinkedIn lit mal le WebP),
	// mais seulement s'il montre la même image que le fichier principal.
	$fluffless_up  = wp_get_upload_dir();
	$fluffless_png = $fluffless_up['path'] . '/fluffless-og-test.png';
	$fluffless_jpg = $fluffless_up['path'] . '/fluffless-og-test.jpg';
	$fluffless_gd  = imagecreatetruecolor( 12, 6 );
	imagepng( $fluffless_gd, $fluffless_png );
	$fluffless_att           = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/webp',
			'post_title'     => 'fluffless-og-test',
			'post_status'    => 'inherit',
		),
		$fluffless_up['path'] . '/fluffless-og-test.webp'
	);
	$fluffless_webp          = $fluffless_up['url'] . '/fluffless-og-test-1024x538.webp';
	$fluffless_meta          = static function ( array $changes ) use ( $fluffless_att, $fluffless_up ): void {
		wp_update_attachment_metadata(
			$fluffless_att,
			array_merge(
				array(
					'width'          => 12,
					'height'         => 6,
					'file'           => ltrim( $fluffless_up['subdir'] . '/fluffless-og-test.webp', '/' ),
					'sizes'          => array(
						'large' => array(
							'file'      => 'fluffless-og-test-1024x538.webp',
							'width'     => 1024,
							'height'    => 538,
							'mime-type' => 'image/webp',
						),
					),
					'original_image' => 'fluffless-og-test.png',
				),
				$changes
			)
		);
		Context::reset();
	};
	$fluffless_webp_original = static function ( $url ) {
		return str_replace( '.png', '.webp', (string) $url );
	};
	try {
		update_post_meta( $fluffless_post, Meta::OG_IMAGE, $fluffless_att );
		$fluffless_meta( array() );
		$fluffless_assert( Context::image() === $fluffless_up['url'] . '/fluffless-og-test.png', 'Image WebP : l\'original PNG n\'est pas servi : ' . Context::image() );

		$fluffless_meta( array( 'width' => 24 ) );
		$fluffless_assert( Context::image() === $fluffless_webp, 'Original d\'une autre taille (-scaled, pivoté) : la taille « large » devait rester : ' . Context::image() );

		$fluffless_meta( array( 'original_image' => 'fluffless-og-test.webp' ) );
		$fluffless_assert( Context::image() === $fluffless_webp, 'Original WebP : la taille « large » devait rester : ' . Context::image() );

		$fluffless_meta( array( 'original_image' => '' ) );
		$fluffless_assert( Context::image() === $fluffless_webp, 'Sans original : la taille « large » devait rester : ' . Context::image() );

		$fluffless_meta( array() );
		update_post_meta( $fluffless_att, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => 'fluffless-og-test.webp' ) ) );
		$fluffless_assert( Context::image() === $fluffless_webp, 'Image retouchée : l\'original ignorerait la retouche : ' . Context::image() );
		delete_post_meta( $fluffless_att, '_wp_attachment_backup_sizes' );

		add_filter( 'wp_get_original_image_url', $fluffless_webp_original );
		Context::reset();
		$fluffless_assert( Context::image() === $fluffless_webp, 'Adresse d\'original réécrite en WebP par un filtre : elle ne devait pas être servie : ' . Context::image() );
		remove_filter( 'wp_get_original_image_url', $fluffless_webp_original );

		file_put_contents( $fluffless_png, str_repeat( "\0", 5 * MB_IN_BYTES ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- original de plus de 5 Mo.
		clearstatcache();
		Context::reset();
		$fluffless_assert( Context::image() === $fluffless_webp, 'Original de plus de 5 Mo : la taille « large » devait rester : ' . Context::image() );

		wp_delete_file( $fluffless_png );
		Context::reset();
		$fluffless_assert( Context::image() === $fluffless_webp, 'Original absent : la taille « large » devait rester : ' . Context::image() );

		// Original JPEG : servi tel quel, sauf s'il porte une orientation EXIF (pivoté à l'affichage).
		$fluffless_gd = imagecreatetruecolor( 12, 6 );
		imagejpeg( $fluffless_gd, $fluffless_jpg );
		$fluffless_meta( array( 'original_image' => 'fluffless-og-test.jpg' ) );
		$fluffless_assert( Context::image() === $fluffless_up['url'] . '/fluffless-og-test.jpg', 'Original JPEG sans EXIF : il n\'est pas servi : ' . Context::image() );
		$fluffless_bytes = (string) file_get_contents( $fluffless_jpg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du test.
		$fluffless_tiff  = "Exif\0\0II*\0" . pack( 'V', 8 ) . pack( 'v', 1 ) . pack( 'vvVvv', 0x0112, 3, 1, 6, 0 ) . pack( 'V', 0 );
		file_put_contents( $fluffless_jpg, substr( $fluffless_bytes, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $fluffless_tiff ) + 2 ) . $fluffless_tiff . substr( $fluffless_bytes, 2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- JPEG du test avec orientation 6.
		Context::reset();
		$fluffless_assert( Context::image() === $fluffless_webp, 'Original JPEG pivoté par EXIF : la taille « large » devait rester : ' . Context::image() );

		$fluffless_meta(
			array(
				'file'  => ltrim( $fluffless_up['subdir'] . '/fluffless-og-test.jpg', '/' ),
				'sizes' => array(
					'large' => array(
						'file'      => 'fluffless-og-test-1024x538.jpg',
						'width'     => 1024,
						'height'    => 538,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);
		$fluffless_assert( Context::image() === $fluffless_up['url'] . '/fluffless-og-test-1024x538.jpg', 'Taille « large » en JPEG : elle devait rester telle quelle : ' . Context::image() );
	} finally {
		remove_filter( 'wp_get_original_image_url', $fluffless_webp_original );
		delete_post_meta( $fluffless_post, Meta::OG_IMAGE );
		wp_delete_attachment( $fluffless_att, true );
		foreach ( array( $fluffless_png, $fluffless_jpg ) as $fluffless_file ) {
			if ( is_file( $fluffless_file ) ) {
				wp_delete_file( $fluffless_file );
			}
		}
		Context::reset();
	}

	// Fil d'Ariane : accueil puis contenu, page courante marquée.
	$fluffless_trail = Breadcrumbs::trail();
	$fluffless_html  = Breadcrumbs::render( array() );
	$fluffless_assert( count( $fluffless_trail ) >= 2, 'Fil d\'Ariane trop court : ' . count( $fluffless_trail ) );
	$fluffless_assert( str_contains( $fluffless_html, '<nav' ) && str_contains( $fluffless_html, 'aria-current="page"' ) && str_contains( $fluffless_html, 'Titre de test SEO' ), 'Rendu du fil d\'Ariane incomplet.' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ), 'Le bloc ne doit charger aucun script.' );

	// Plan de site : taxonomie exclue, contenus en noindex retirés de la requête.
	$fluffless_tax = Sitemaps::taxonomies(
		array(
			'category' => get_taxonomy( 'category' ),
			'post_tag' => get_taxonomy( 'post_tag' ),
		)
	);
	$fluffless_assert( is_array( $fluffless_tax ) && isset( $fluffless_tax['category'] ) && ! isset( $fluffless_tax['post_tag'] ), 'Exclusion de taxonomie non appliquée.' );
	$fluffless_args = Sitemaps::posts_query_args( array( 'post_type' => 'post' ) );
	$fluffless_assert( is_array( $fluffless_args ) && isset( $fluffless_args['meta_query'] ), 'Requête du plan de site sans filtre noindex.' );

	// F-serveurs-contactes-01 : IndexNow déclaré à l'écran Serveurs contactés de la Suite, seulement s'il est allumé.
	$fluffless_assert( false !== has_filter( 'fluffless_outgoing_hosts', array( Robots::class, 'outgoing_hosts' ) ), 'Serveurs contactés : IndexNow non branché sur fluffless_outgoing_hosts.' );
	$fluffless_known = array( array( 'host' => 'deja.exemple.ca' ) );
	Settings::update( array( 'indexnow' => false ) );
	Settings::flush();
	$fluffless_assert( Robots::outgoing_hosts( $fluffless_known ) === $fluffless_known, 'Serveurs contactés : IndexNow éteint ajoute une entrée.' );
	Settings::update( array( 'indexnow' => true ) );
	Settings::flush();
	$fluffless_hosts = Robots::outgoing_hosts( $fluffless_known );
	$fluffless_entry = is_array( $fluffless_hosts ) ? end( $fluffless_hosts ) : null;
	$fluffless_assert(
		is_array( $fluffless_hosts ) && 2 === count( $fluffless_hosts ) && $fluffless_known[0] === $fluffless_hosts[0]
		&& is_array( $fluffless_entry ) && 'api.indexnow.org' === ( $fluffless_entry['host'] ?? '' ) && 'server' === ( $fluffless_entry['side'] ?? '' )
		&& 'on_publish' === ( $fluffless_entry['when'] ?? '' ) && '' !== ( $fluffless_entry['service'] ?? '' ) && true === ( $fluffless_entry['active'] ?? null ),
		'Serveurs contactés : entrée IndexNow inattendue : ' . wp_json_encode( $fluffless_hosts )
	);
	$fluffless_private = static fn(): string => '0';
	add_filter( 'pre_option_blog_public', $fluffless_private );

	$fluffless_hosts = Robots::outgoing_hosts( array() );
	remove_filter( 'pre_option_blog_public', $fluffless_private );
	$fluffless_assert( is_array( $fluffless_hosts ) && false === ( $fluffless_hosts[0]['active'] ?? null ), 'Serveurs contactés : IndexNow actif sur un site qui demande à ne pas être indexé.' );
	Settings::update( array( 'indexnow' => false ) );
	Settings::flush();

	// Plan de site : la requête /wp-sitemap.xml ne trouve aucun contenu ; WP::handle_404() ne doit pas la marquer introuvable.
	$fluffless_assert( false !== has_filter( 'pre_handle_404', array( Sitemaps::class, 'pre_handle_404' ) ), 'Filtre pre_handle_404 du plan de site absent.' );
	$fluffless_wp_vars         = $GLOBALS['wp']->query_vars;
	$GLOBALS['wp']->query_vars = array( 'sitemap' => 'index' );
	$fluffless_go( $GLOBALS['wp']->query_vars );
	// Comme sur une vraie requête de plan de site : aucun contenu trouvé.
	$GLOBALS['wp_query']->posts      = array();
	$GLOBALS['wp_query']->post_count = 0;
	$GLOBALS['wp']->handle_404();
	$fluffless_assert( ! is_404(), 'Le plan de site est marqué introuvable (statut 404).' );
	$GLOBALS['wp']->query_vars = $fluffless_wp_vars;
	$fluffless_assert( true === Sitemaps::pre_handle_404( false ) && 'x' === Sitemaps::pre_handle_404( 'x' ), 'pre_handle_404 : décision du plan de site ou d\'un autre filtre perdue.' );
	$fluffless_go( array( 'p' => $fluffless_post ) );
	$fluffless_assert( false === Sitemaps::pre_handle_404( false ), 'pre_handle_404 ne doit rien décider hors du plan de site.' );

	// hreflang : x-default vers la langue choisie, seulement si la page existe dans cette langue.
	$fluffless_alternates = array(
		'fr_CA' => 'https://exemple.test/prix/',
		'en_CA' => 'https://exemple.test/en/pricing/',
	);
	$fluffless_links      = implode( "\n", Head::hreflang_tags( $fluffless_alternates, 'en_CA' ) );
	$fluffless_assert( str_contains( $fluffless_links, 'hreflang="fr-CA" href="https://exemple.test/prix/"' ) && str_contains( $fluffless_links, 'hreflang="x-default" href="https://exemple.test/en/pricing/"' ), 'x-default absent ou faux : ' . $fluffless_links );
	$fluffless_assert( 3 === count( Head::hreflang_tags( $fluffless_alternates, 'en_CA' ) ), 'Trois liens attendus (deux langues et x-default).' );
	$fluffless_assert( ! str_contains( implode( '', Head::hreflang_tags( $fluffless_alternates, '' ) ), 'x-default' ), 'x-default écrit sans langue par défaut réglée.' );
	$fluffless_assert( ! str_contains( implode( '', Head::hreflang_tags( $fluffless_alternates, 'es_ES' ) ), 'x-default' ), 'x-default écrit vers une langue absente de la page.' );
	$fluffless_assert( array() === Head::hreflang_tags( array(), 'en_CA' ), 'x-default écrit sans traduction.' );
	$fluffless_assert( '' === Settings::string( 'hreflang_default' ), 'Langue par défaut de x-default : aucune attendue sur une installation neuve.' );
	$fluffless_assert( null === Settings::field( 'zz_ZZ', Settings::fields()['hreflang_default'] ) && '' === Settings::field( '', Settings::fields()['hreflang_default'] ), 'Langue par défaut de x-default : valeur inconnue acceptée ou « aucune » refusée.' );

	// SoftwareApplication : catégorie, système, éditeur et offre gratuite (prix 0 gardé).
	update_post_meta( $fluffless_post, Meta::SCHEMA, 'SoftwareApplication' );
	update_post_meta(
		$fluffless_post,
		Meta::SCHEMA_DATA,
		array(
			'app_category' => 'WebApplication',
			'os'           => 'WordPress',
			'price'        => '0',
			'currency'     => 'cad',
		)
	);
	$fluffless_go( array( 'p' => $fluffless_post ) );
	$fluffless_app = array_values( array_filter( Schema::graph(), static fn( array $node ): bool => 'SoftwareApplication' === ( $node['@type'] ?? null ) ) );
	$fluffless_app = $fluffless_app[0] ?? array();
	$fluffless_assert( 'WebApplication' === ( $fluffless_app['applicationCategory'] ?? null ) && 'WordPress' === ( $fluffless_app['operatingSystem'] ?? null ), 'SoftwareApplication sans catégorie ou système : ' . wp_json_encode( $fluffless_app ) );
	$fluffless_assert( '0' === ( $fluffless_app['offers']['price'] ?? null ) && 'CAD' === ( $fluffless_app['offers']['priceCurrency'] ?? null ), 'SoftwareApplication : offre gratuite perdue : ' . wp_json_encode( $fluffless_app['offers'] ?? null ) );
	$fluffless_assert( isset( $fluffless_app['publisher']['@id'] ), 'SoftwareApplication sans éditeur.' );
	$fluffless_assert( array( 'app_category' => 'Game' ) === Meta::sanitize_schema_data( array( 'app_category' => 'Game' ) ), 'Champ app_category refusé.' );
	delete_post_meta( $fluffless_post, Meta::SCHEMA );
	delete_post_meta( $fluffless_post, Meta::SCHEMA_DATA );
	$fluffless_go( array( 'p' => $fluffless_post ) );

	// Filtre fluffless_seo_graph : un nœud ajouté sort ; une réponse invalide garde le graphe d'origine.
	$fluffless_count = count( Schema::graph() );
	$fluffless_add   = static function ( $graph ) {
		$graph[] = array(
			'@type' => 'SoftwareApplication',
			'name'  => 'Ajout par filtre',
		);
		$graph[] = 'pas un nœud';
		return $graph;
	};
	add_filter( 'fluffless_seo_graph', $fluffless_add );
	$fluffless_graph = Schema::graph();
	remove_filter( 'fluffless_seo_graph', $fluffless_add );
	$fluffless_last = end( $fluffless_graph );
	$fluffless_assert( count( $fluffless_graph ) === $fluffless_count + 1 && 'Ajout par filtre' === ( $fluffless_last['name'] ?? null ), 'fluffless_seo_graph : nœud ajouté absent ou valeur non-nœud gardée.' );
	$fluffless_bad = static fn(): string => 'cassé';
	add_filter( 'fluffless_seo_graph', $fluffless_bad );
	$fluffless_assert( count( Schema::graph() ) === $fluffless_count, 'fluffless_seo_graph : une réponse invalide doit garder le graphe d\'origine.' );
	remove_filter( 'fluffless_seo_graph', $fluffless_bad );

	// Chemin d'erreur : famille éteinte, aucune balise de partage.
	Settings::update( array( 'social' => false ) );
	Settings::flush();
	$fluffless_go( array( 'p' => $fluffless_post ) );
	$fluffless_assert( ! str_contains( Head::tags(), 'og:title' ), 'Partage éteint : og:title ne devrait plus sortir.' );
} finally {
	if ( $fluffless_post > 0 ) {
		wp_delete_post( $fluffless_post, true );
	}
	foreach ( $fluffless_pages as $fluffless_page ) {
		wp_delete_post( $fluffless_page, true );
	}
	if ( false === $fluffless_saved ) {
		delete_option( Settings::NAME );
	} else {
		update_option( Settings::NAME, $fluffless_saved );
	}
	Settings::flush();
	wp_reset_query(); // phpcs:ignore WordPress.WP.DiscouragedFunctions.wp_reset_query_wp_reset_query -- la requête principale a été remplacée, pas seulement $post.
}

// WP-CLI : les résumés des commandes sont traduits, donc enregistrés sur cli_init (init), jamais à plugins_loaded.
// Avant init, WordPress 6.7 refuse de charger le domaine de texte et signale « triggered too early ».
$fluffless_assert( false !== has_action( 'cli_init', array( Cli::class, 'register' ) ), 'WP-CLI : commandes enregistrées avant init (domaine de texte chargé trop tôt)' );

// Multisite : un site créé après l'activation réseau reçoit ses tables tout de suite, sans attendre
// une visite de son admin ; sinon chaque 404 de ce site écrit une erreur SQL (P6b-12).
$fluffless_assert( false !== has_action( 'wp_initialize_site', array( Plugin::class, 'initialize_site' ) ), 'Multisite : aucun crochet wp_initialize_site, tables absentes sur un nouveau site.' );
if ( is_multisite() ) {
	$fluffless_site = 0;
	try {
		$fluffless_site = wp_insert_site(
			array(
				'domain' => get_network()->domain ?? '',
				'path'   => '/fl-test-seo-' . wp_rand( 1000, 9999 ) . '/',
				'title'  => 'Test SEO',
			)
		);
		$fluffless_assert( is_int( $fluffless_site ), 'Multisite : site de test non créé.' );
		if ( is_int( $fluffless_site ) ) {
			global $wpdb;
			$fluffless_prefix = $wpdb->get_blog_prefix( $fluffless_site );
			foreach ( array( 'fluffless_seo_redirects', 'fluffless_seo_404' ) as $fluffless_table ) {
				$fluffless_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fluffless_prefix . $fluffless_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test du schéma.
				$fluffless_assert( $fluffless_prefix . $fluffless_table === $fluffless_found, 'Multisite : table ' . $fluffless_prefix . $fluffless_table . ' absente sur le nouveau site.' );
			}
			switch_to_blog( $fluffless_site );
			$fluffless_assert( false !== wp_next_scheduled( Store::CRON ), 'Multisite : purge quotidienne non planifiée sur le nouveau site.' );
			restore_current_blog();
		}
	} finally {
		if ( is_int( $fluffless_site ) && $fluffless_site > 0 ) {
			switch_to_blog( $fluffless_site );
			Store::drop();
			restore_current_blog();
			wp_delete_site( $fluffless_site );
		}
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles (SEO) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}

echo esc_html( 'OK : ' . $fluffless_checks . " contrôles réussis (SEO : retrait de Suite, gabarits, robots, balises, JSON-LD, SoftwareApplication, filtre du graphe, fil d'Ariane, plan de site, x-default, désinstallation).\n" );
