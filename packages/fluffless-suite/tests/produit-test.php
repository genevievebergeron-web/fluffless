<?php
/**
 * Tests de la Vente simple : bloc Produit (blocs natifs, sans panier ni script), bloc Prix rendu
 * par le serveur (montant lisible, valeur machine, disponibilité), nœuds Product et Offer du
 * module SEO léger, repris par le graphe de Fluffless SEO.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/produit-test.php
 * Les contenus créés sont retirés à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\Sections;
use Fluffless\Suite\Modules\Seo;

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les textes en en_US : indépendants de la langue du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_registry = WP_Block_Type_Registry::get_instance();
$fluffless_names    = array( 'fluffless/produit', 'fluffless/' . Sections::PRICE );
$fluffless_before   = array_filter( $fluffless_names, array( $fluffless_registry, 'is_registered' ) );
$fluffless_pages    = array();
$fluffless_dir      = dirname( __DIR__ ) . '/blocks/';

/**
 * Bloc Produit sérialisé, avec ses blocs internes.
 *
 * @param string $name  Nom du produit (vide : aucun titre).
 * @param string $price Attributs du bloc Prix en JSON (vide : aucun bloc Prix).
 * @param string $extra Blocs de plus, sérialisés.
 */
$fluffless_produit = static function ( string $name = 'Atelier de 2 h', string $price = '{"price":"49.99"}', string $extra = '' ): string {
	$blocks  = '<!-- wp:image {"id":2424,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="' . home_url( '/wp-content/uploads/atelier.jpg' ) . '" alt="" class="wp-image-2424"/></figure><!-- /wp:image -->';
	$blocks .= '' !== $name ? '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $name . '</h3><!-- /wp:heading -->' : '';
	$blocks .= '' !== $price ? '<!-- wp:fluffless/prix ' . $price . ' /-->' : '';
	$blocks .= '<!-- wp:paragraph --><p>Deux heures pour <strong>repartir</strong> avec un plan.</p><!-- /wp:paragraph -->';
	$blocks .= '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://pay.example.com/atelier?x=1&amp;y=2">Buy now</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
	$blocks .= $extra;
	return '<!-- wp:fluffless/produit --><div class="wp-block-fluffless-produit fluffless-section fluffless-produit is-photo-side">' . $blocks . '</div><!-- /wp:fluffless/produit -->';
};

/**
 * Page de test publiée avec ce contenu.
 *
 * @param string $content Blocs sérialisés.
 */
$fluffless_page = static function ( string $content ) use ( &$fluffless_pages ): WP_Post {
	$id = wp_insert_post(
		array(
			'post_title'   => 'Vente simple',
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( esc_html( 'Page de test refusée : ' . $id->get_error_message() ) );
	}
	$fluffless_pages[] = $id;
	$post              = get_post( $id );
	if ( ! $post instanceof WP_Post ) {
		throw new RuntimeException( 'Page de test introuvable.' );
	}
	return $post;
};

try {
	if ( array() !== array_diff( $fluffless_names, $fluffless_before ) ) {
		Sections::register();
	}

	// 1. Enregistrement et fichiers : Prix réservé au Produit, aucun script côté visiteur, feuille légère.
	$fluffless_prix = $fluffless_registry->get_registered( 'fluffless/' . Sections::PRICE );
	$fluffless_assert( $fluffless_prix instanceof WP_Block_Type && array( 'fluffless/produit' ) === $fluffless_prix->parent, 'prix : bloc non enregistré ou parent différent de fluffless/produit' );
	$fluffless_assert( $fluffless_prix instanceof WP_Block_Type && array() === $fluffless_prix->view_script_handles && array() === $fluffless_prix->script_handles && array() === $fluffless_prix->view_script_module_ids, 'prix : script côté visiteur' );
	$fluffless_bloc = $fluffless_registry->get_registered( 'fluffless/produit' );
	$fluffless_assert( $fluffless_bloc instanceof WP_Block_Type && array() === $fluffless_bloc->view_script_handles && array() === $fluffless_bloc->view_script_module_ids, 'produit : script côté visiteur' );
	$fluffless_json = wp_json_file_decode( $fluffless_dir . 'produit/block.json', array( 'associative' => true ) );
	$fluffless_json = is_array( $fluffless_json ) ? $fluffless_json : array();
	$fluffless_assert( ( $fluffless_json['attributes']['photo']['enum'] ?? null ) === Sections::OPTIONS['produit']['photo'], 'produit : réglage photo de block.json différent de Sections::OPTIONS' );
	$fluffless_json = wp_json_file_decode( $fluffless_dir . Sections::PRICE . '/block.json', array( 'associative' => true ) );
	$fluffless_json = is_array( $fluffless_json ) ? $fluffless_json : array();
	$fluffless_assert( ( $fluffless_json['attributes']['currency']['enum'] ?? null ) === Sections::CURRENCIES, 'prix : devises de block.json différentes de Sections::CURRENCIES' );
	$fluffless_assert( ( $fluffless_json['attributes']['availability']['enum'] ?? null ) === array_keys( Sections::AVAILABILITY ), 'prix : disponibilités de block.json différentes de Sections::AVAILABILITY' );
	$fluffless_assert( ! isset( $fluffless_json['style'] ) && ! isset( $fluffless_json['viewScript'] ) && ! isset( $fluffless_json['viewScriptModule'] ), 'prix : ni feuille propre ni script attendus' );

	// 2. Montant : saisies libres, négatif, texte, zéro, plafond.
	foreach ( array(
		'49'         => 49.0,
		'49,99'      => 49.99,
		'1 234,50'   => 1234.5,
		'$1,234.56'  => 1234.56,
		'1.234,56'   => 1234.56,
		'12.345'     => 12.35,
		'-5'         => 0.0,
		'abc'        => 0.0,
		'0'          => 0.0,
		''           => 0.0,
		'10000000'   => 0.0,
		'9999999.99' => 9999999.99,
	) as $fluffless_in => $fluffless_out ) {
		$fluffless_assert( Sections::amount( (string) $fluffless_in ) === $fluffless_out, "amount( '{$fluffless_in}' ) : " . Sections::amount( (string) $fluffless_in ) . " au lieu de {$fluffless_out}" );
	}
	$fluffless_assert( 12.0 === Sections::amount( 12 ) && 0.0 === Sections::amount( null ) && 0.0 === Sections::amount( array( '5' ) ), 'amount : entier accepté, null et tableau refusés' );

	// 3. Attributs bornés, texte du prix, étiquette de disponibilité.
	$fluffless_a = Sections::price_attributes( array() );
	$fluffless_assert( 0.0 === $fluffless_a['amount'] && 'CAD' === $fluffless_a['currency'] && 'available' === $fluffless_a['availability'], 'price_attributes : défauts inattendus' );
	$fluffless_a = Sections::price_attributes(
		array(
			'price'        => '20',
			'currency'     => 'GBP',
			'availability' => 'bientot',
		)
	);
	$fluffless_assert( 20.0 === $fluffless_a['amount'] && 'CAD' === $fluffless_a['currency'] && 'available' === $fluffless_a['availability'], 'price_attributes : valeurs hors liste acceptées' );
	$fluffless_a = Sections::price_attributes(
		array(
			'currency'     => 'EUR',
			'availability' => 'preorder',
		)
	);
	$fluffless_assert( 'EUR' === $fluffless_a['currency'] && 'preorder' === $fluffless_a['availability'], 'price_attributes : valeurs de la liste refusées' );
	$fluffless_assert( str_contains( Sections::price_text( 49.99, 'CAD' ), '$' ) && str_contains( Sections::price_text( 49.99, 'CAD' ), '49' ), 'price_text CAD : ' . Sections::price_text( 49.99, 'CAD' ) );
	$fluffless_assert( str_contains( Sections::price_text( 5.0, 'USD' ), 'US$' ), 'price_text USD : ' . Sections::price_text( 5.0, 'USD' ) );
	$fluffless_assert( str_contains( Sections::price_text( 5.0, 'EUR' ), '€' ), 'price_text EUR : ' . Sections::price_text( 5.0, 'EUR' ) );
	$fluffless_assert( '' === Sections::availability_label( 'available' ) && '' !== Sections::availability_label( 'soldout' ) && '' !== Sections::availability_label( 'preorder' ), 'availability_label : étiquettes inattendues' );

	// 4. Rendu du bloc Prix : rien sans montant ni étiquette, valeur machine, classe et étiquette.
	$fluffless_assert( '' === Sections::render_prix( array() ) && '' === Sections::render_prix( 'x' ), 'render_prix : rendu sans montant ni étiquette' );
	$fluffless_html = Sections::render_prix( array( 'price' => '1 234,5' ) );
	$fluffless_assert( str_contains( $fluffless_html, 'class="wp-block-fluffless-prix fluffless-produit__price is-availability-available"' ), 'render_prix : enveloppe absente : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, '<data class="fluffless-produit__amount" value="1234.50">' ), 'render_prix : valeur machine absente : ' . $fluffless_html );
	$fluffless_assert( ! str_contains( $fluffless_html, 'fluffless-produit__availability' ), 'render_prix : étiquette présente pour un produit disponible' );
	$fluffless_html = Sections::render_prix(
		array(
			'price'        => '<b>10</b>',
			'availability' => 'soldout',
		)
	);
	$fluffless_assert( str_contains( $fluffless_html, 'is-availability-soldout' ) && str_contains( $fluffless_html, '<span class="fluffless-produit__availability">' ), 'render_prix : disponibilité épuisée non rendue : ' . $fluffless_html );
	$fluffless_assert( ! str_contains( $fluffless_html, '<b>' ) && str_contains( $fluffless_html, 'value="10.00"' ), 'render_prix : balise de la saisie passée au rendu' );
	$fluffless_html = Sections::render_prix( array( 'availability' => 'preorder' ) );
	$fluffless_assert( '' !== $fluffless_html && ! str_contains( $fluffless_html, '<data' ) && str_contains( $fluffless_html, 'is-availability-preorder' ), 'render_prix : précommande sans montant' );

	// 5. Enveloppe du Produit : réglage photo borné.
	$fluffless_html = Sections::render( 'produit', array( 'photo' => 'top' ), '<p>x</p>' );
	$fluffless_assert( str_contains( $fluffless_html, 'wp-block-fluffless-produit fluffless-section fluffless-produit is-photo-top' ), 'produit : classes inattendues : ' . $fluffless_html );
	$fluffless_html = Sections::render( 'produit', array( 'photo' => 'left' ), '<p>x</p>' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-photo-side' ), 'produit : valeur hors liste non ramenée au défaut' );

	// 6. Page rendue par do_blocks : image, titre, prix après le titre, bouton, aucun script.
	$fluffless_post = $fluffless_page( $fluffless_produit() );
	$fluffless_html = do_blocks( $fluffless_post->post_content );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-produit is-photo-side' ) && str_contains( $fluffless_html, 'wp-image-2424' ), 'page : enveloppe ou image absente' );
	$fluffless_assert( strpos( $fluffless_html, 'Atelier de 2 h' ) < strpos( $fluffless_html, 'fluffless-produit__amount' ), 'page : le prix devrait suivre le titre' );
	$fluffless_assert( str_contains( $fluffless_html, 'value="49.99"' ) && str_contains( $fluffless_html, 'href="https://pay.example.com/atelier?x=1&amp;y=2"' ), 'page : prix ou bouton absent' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ), 'page : script dans le rendu' );

	// 7. Nœuds Product du module SEO léger : champs, offre, image, lien du bouton.
	$fluffless_url   = (string) get_permalink( $fluffless_post );
	$fluffless_nodes = Seo::products( $fluffless_post, $fluffless_url );
	$fluffless_assert( 1 === count( $fluffless_nodes ), 'products : un nœud attendu, reçu ' . count( $fluffless_nodes ) );
	$fluffless_node = $fluffless_nodes[0] ?? array();
	$fluffless_assert( 'Product' === ( $fluffless_node['@type'] ?? null ) && ( $fluffless_node['@id'] ?? null ) === $fluffless_url . '#produit-1', 'products : type ou identifiant inattendu' );
	$fluffless_assert( 'Atelier de 2 h' === ( $fluffless_node['name'] ?? null ) && str_contains( (string) ( $fluffless_node['description'] ?? '' ), 'repartir avec un plan' ) && ! str_contains( (string) ( $fluffless_node['description'] ?? '' ), '<strong>' ), 'products : nom ou description inattendus : ' . wp_json_encode( $fluffless_node ) );
	$fluffless_offer = $fluffless_node['offers'] ?? array();
	$fluffless_assert( 'Offer' === ( $fluffless_offer['@type'] ?? null ) && '49.99' === ( $fluffless_offer['price'] ?? null ) && 'CAD' === ( $fluffless_offer['priceCurrency'] ?? null ), 'products : offre inattendue : ' . wp_json_encode( $fluffless_offer ) );
	$fluffless_assert( 'https://schema.org/InStock' === ( $fluffless_offer['availability'] ?? null ) && 'https://pay.example.com/atelier?x=1&y=2' === ( $fluffless_offer['url'] ?? null ), 'products : disponibilité ou lien de l\'offre inattendus : ' . wp_json_encode( $fluffless_offer ) );
	$fluffless_assert( is_string( $fluffless_node['image'] ?? null ) && '' !== $fluffless_node['image'], 'products : image absente' );

	// Épuisé en euros, dans un groupe, sans bouton : SoldOut, EUR, adresse de la page.
	$fluffless_post  = $fluffless_page( '<!-- wp:group --><div class="wp-block-group">' . str_replace( '<!-- wp:buttons -->', '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --><!-- wp:buttons {"x":1} -->', $fluffless_produit( 'Épuisé', '{"price":"12","currency":"EUR","availability":"soldout"}' ) ) . '</div><!-- /wp:group -->' );
	$fluffless_url   = (string) get_permalink( $fluffless_post );
	$fluffless_nodes = Seo::products( $fluffless_post, $fluffless_url );
	$fluffless_offer = $fluffless_nodes[0]['offers'] ?? array();
	$fluffless_assert( 1 === count( $fluffless_nodes ) && 'https://schema.org/SoldOut' === ( $fluffless_offer['availability'] ?? null ) && 'EUR' === ( $fluffless_offer['priceCurrency'] ?? null ) && '12.00' === ( $fluffless_offer['price'] ?? null ), 'products : produit imbriqué épuisé mal lu : ' . wp_json_encode( $fluffless_nodes ) );

	// Sans titre ou sans prix : aucun nœud ; précommande sans montant non plus.
	$fluffless_post = $fluffless_page( $fluffless_produit( '' ) . $fluffless_produit( 'Sans prix', '' ) . $fluffless_produit( 'Précommande', '{"availability":"preorder"}' ) );
	$fluffless_assert( array() === Seo::products( $fluffless_post, (string) get_permalink( $fluffless_post ) ), 'products : nœud produit sans nom ou sans montant' );

	// Vingt-deux produits : vingt nœuds, numérotés.
	$fluffless_post  = $fluffless_page( str_repeat( $fluffless_produit(), 22 ) );
	$fluffless_url   = (string) get_permalink( $fluffless_post );
	$fluffless_nodes = Seo::products( $fluffless_post, $fluffless_url );
	$fluffless_assert( 20 === count( $fluffless_nodes ) && ( $fluffless_nodes[19]['@id'] ?? null ) === $fluffless_url . '#produit-20', 'products : borne de vingt produits : ' . count( $fluffless_nodes ) );

	// 8. Graphe JSON-LD du module SEO léger sur la page du produit.
	$fluffless_post          = $fluffless_page( $fluffless_produit() );
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $fluffless_post->ID ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation de la requête principale.
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- idem.
	$GLOBALS['post']         = $fluffless_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- idem.
	Seo::reset();
	$fluffless_json = Seo::json_ld();
	$fluffless_assert( str_contains( $fluffless_json, '"Product"' ) && str_contains( $fluffless_json, '"Offer"' ) && str_contains( $fluffless_json, '"priceCurrency":"CAD"' ), 'json_ld : Product ou Offer absents du graphe' );
	$fluffless_assert( 1 === substr_count( $fluffless_json, '"Product"' ), 'json_ld : un seul nœud Product attendu' );
	$fluffless_payload = preg_replace( '/^<script[^>]*>|<\/script>\s*$/', '', trim( $fluffless_json ) );
	$fluffless_assert( is_array( json_decode( (string) $fluffless_payload, true ) ), 'json_ld : JSON illisible : ' . json_last_error_msg() );
} finally {
	foreach ( $fluffless_pages as $fluffless_id ) {
		wp_delete_post( $fluffless_id, true );
	}
	foreach ( array_diff( $fluffless_names, $fluffless_before ) as $fluffless_name ) {
		if ( $fluffless_registry->is_registered( $fluffless_name ) ) {
			unregister_block_type( $fluffless_name );
		}
	}
	Seo::reset();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Vente simple : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Vente simple : {$fluffless_checks} vérifications passées." );
