<?php
/**
 * Tests du module Boutique sobre.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/shop-test.php
 * Les vérifications qui touchent WooCommerce ne tournent que s'il est actif.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Shop;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Shop::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Contrat du module.
$fluffless_assert( 'shop' === Shop::id(), 'id' );
$fluffless_assert( Loader::is_active( 'shop' ), 'module actif par défaut' );
$fluffless_defaults = Shop::defaults();
$fluffless_assert( 'off' === $fluffless_defaults['attribution'], 'attribution coupée par défaut' );
$fluffless_assert( array_keys( Shop::fields() ) === array_keys( $fluffless_defaults ), 'un champ par réglage' );
$fluffless_clean = Shop::sanitize(
	array(
		'attribution' => 'bogus',
		'assets'      => '0',
		'prefetch'    => 'yes',
	)
);
$fluffless_assert( 'off' === $fluffless_clean['attribution'], 'choix inconnu ramené au défaut' );
$fluffless_assert( false === $fluffless_clean['assets'], 'bool assaini' );
$fluffless_assert( true === $fluffless_clean['gallery_full'], 'clé absente ramenée au défaut' );
$fluffless_assert( true === $fluffless_clean['grid_sizes'], 'grid_sizes au défaut' );

// Attribut sizes d'une grille : une colonne sur la largeur de la mise en page.
$fluffless_assert( 'calc(min(100vw, 80rem) / 3)' === Shop::grid_sizes_for( 3, '80rem' ), 'sizes trois colonnes' );
$fluffless_assert( 'min(100vw, 80rem)' === Shop::grid_sizes_for( 1, '80rem' ), 'sizes une colonne' );
$fluffless_assert( 'calc(100vw / 2)' === Shop::grid_sizes_for( 2, '' ), 'sizes sans largeur de thème' );
$fluffless_assert( '100vw' === Shop::grid_sizes( '100vw' ), 'sizes intact hors grille' );
Shop::note_columns(
	array(
		'blockName' => 'woocommerce/product-template',
		'attrs'     => array(
			'layout' => array(
				'type'        => 'grid',
				'columnCount' => 4,
			),
		),
	)
);
$fluffless_assert( 'calc(min(100vw, 80rem) / 4)' === Shop::grid_sizes( '100vw' ), 'sizes dans une grille de quatre' );
Shop::forget_columns( '', array( 'blockName' => 'woocommerce/product-template' ) );
$fluffless_assert( '100vw' === Shop::grid_sizes( '100vw' ), 'grille oubliée après rendu' );

// Blocs accrochés : retirés quel que soit l'ancrage, le reste intact.
$fluffless_hooked = Shop::drop_header_blocks( array( 'woocommerce/mini-cart', 'core/social-links', 'woocommerce/customer-account' ) );
$fluffless_assert( array( 'core/social-links' ) === $fluffless_hooked, 'blocs d\'en-tête retirés' );
$fluffless_assert( 'x' === Shop::drop_header_blocks( 'x' ), 'valeur non tableau rendue telle quelle' );

// Détection du contenu de boutique.
$fluffless_assert( Shop::has_shop_content( '<!-- wp:woocommerce/all-products /-->' ), 'bloc WooCommerce' );
$fluffless_assert( Shop::has_shop_content( '<p>[products limit="3"]</p>' ), 'code court products' );
$fluffless_assert( Shop::has_shop_content( '[woocommerce_cart]' ), 'code court woocommerce_cart' );
$fluffless_assert( Shop::has_shop_content( '[add_to_cart id="1"]' ), 'code court add_to_cart' );
$fluffless_assert( ! Shop::has_shop_content( '<!-- wp:paragraph --><p>Bonjour [contact-form-7 id="1"]</p><!-- /wp:paragraph -->' ), 'contenu sans boutique' );
$fluffless_assert( ! Shop::has_shop_content( '' ), 'contenu vide' );
$fluffless_assert( Shop::is_shop_shortcode( 'product_page' ) && ! Shop::is_shop_shortcode( 'gallery' ), 'codes courts reconnus' );

// Préchargements : seuls ceux de WooCommerce partent, et seulement en prefetch ou prerender.
$fluffless_hints          = array(
	'http://x/wp-content/plugins/woocommerce/assets/client/blocks/cart.js',
	array( 'href' => 'http://x/wp-content/plugins/woocommerce/assets/client/blocks/checkout.js' ),
	'http://x/wp-content/themes/fluffless/x.js',
);
$fluffless_expected_hints = array( 'http://x/wp-content/themes/fluffless/x.js' );
$fluffless_assert( Shop::drop_prefetch( $fluffless_hints, 'prefetch' ) === $fluffless_expected_hints, 'prefetch WooCommerce retiré' );
$fluffless_assert( Shop::drop_prefetch( $fluffless_hints, 'dns-prefetch' ) === $fluffless_hints, 'dns-prefetch intact' );

// Galerie : « large » si la taille existe.
$fluffless_expected = isset( wp_get_registered_image_subsizes()['large'] ) ? 'large' : 'full';
$fluffless_assert( Shop::gallery_full_size( 'full' ) === $fluffless_expected, 'taille pleine de la galerie' );

// Service déclaré à Consent.
$fluffless_services = Shop::declare_service( array( 'a' => array() ) );
$fluffless_assert( 'analytics' === ( $fluffless_services['woocommerce-attribution']['category'] ?? '' ) && isset( $fluffless_services['a'] ), 'service d\'attribution déclaré' );

// Avec WooCommerce : les crochets sont posés et les options court-circuitées.
if ( Shop::woocommerce_active() ) {
	$fluffless_assert( '' === Shop::status(), 'aucun avertissement avec WooCommerce actif' );
	$fluffless_assert( 'no' === get_option( 'woocommerce_show_marketplace_suggestions' ), 'suggestions du magasin coupées' );
	$fluffless_assert( 'no' === get_option( 'woocommerce_allow_tracking' ), 'pistage WooCommerce.com coupé' );
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- crochets de WooCommerce et de WordPress, appelés pour lire leur résultat.
	$fluffless_assert( false === apply_filters( 'wc_order_attribution_allow_tracking', true ), 'attribution refusée' );
	$fluffless_assert( ! in_array( 'woocommerce/mini-cart', (array) apply_filters( 'hooked_block_types', array(), 'after', 'core/navigation', null ), true ), 'mini-panier absent des blocs accrochés' );
	// phpcs:enable
	$fluffless_assert( false !== has_action( 'wp_enqueue_scripts', array( Shop::class, 'dequeue_off_shop' ) ), 'retrait hors boutique branché' );
	$fluffless_assert( false !== has_action( 'wp_enqueue_scripts', array( Shop::class, 'dequeue_attribution' ) ), 'retrait de l\'attribution branché' );
} else {
	$fluffless_assert( '' !== Shop::status(), 'avertissement sans WooCommerce' );
	$fluffless_assert( false === has_action( 'wp_enqueue_scripts', array( Shop::class, 'dequeue_off_shop' ) ), 'rien de branché sans WooCommerce' );
}

if ( $fluffless_failures ) {
	echo esc_html( "Échecs :\n- " . implode( "\n- ", $fluffless_failures ) . "\n" );
	exit( 1 );
}
echo esc_html( "OK : Boutique sobre, {$fluffless_checks} vérifications réussies.\n" );
