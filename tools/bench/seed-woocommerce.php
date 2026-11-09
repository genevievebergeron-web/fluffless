<?php
/**
 * Banc d'essai : semence WooCommerce.
 *
 * @package Fluffless
 */

/**
 * Trois produits de démonstration avec image générée (GD), pour mesurer la boutique en local.
 * Usage : docker compose run --rm -T cli wp eval-file - < tools/bench/seed-woocommerce.php
 * Passe aussi la boutique en ligne (woocommerce_coming_soon = no). Instance de dev seulement.
 */
function fluffless_bench_seed_woocommerce() {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	update_option( 'woocommerce_coming_soon', 'no' );
	$items  = array(
		array( 'Chandail de laine mérinos', 'chandail-merinos', 129, 'Tricoté au Québec, laine mérinos certifiée. Coupe droite, col rond.', array( 96, 74, 58 ) ),
		array( 'Tasse en grès émaillé', 'tasse-gres', 34, 'Tournée à la main, émail bleu nuit. Passe au lave-vaisselle.', array( 38, 58, 96 ) ),
		array( 'Carnet relié, papier recyclé', 'carnet-recycle', 22, 'Reliure cousue, 160 pages, papier 100 % recyclé.', array( 78, 96, 60 ) ),
	);
	$cat    = wp_insert_term( 'Atelier', 'product_cat' );
	$cat_id = is_wp_error( $cat ) ? (int) get_term_by( 'name', 'Atelier', 'product_cat' )->term_id : (int) $cat['term_id'];
	foreach ( $items as $i => $it ) {
		list( $name, $slug, $price, $desc, $rgb ) = $it;
		if ( get_page_by_path( $slug, OBJECT, 'product' ) ) {
			continue; }
		$w  = 1600;
		$h  = 1067;
		$im = imagecreatetruecolor( $w, $h );
		// Dégradé plus bruit : une photo d'objet compresse à peu près ainsi (150 à 250 ko).
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$k = 0.55 + 0.45 * ( $x / $w ) * ( 1 - $y / $h );
				$n = wp_rand( -18, 18 );
				$c = imagecolorallocate( $im, max( 0, min( 255, (int) ( $rgb[0] * $k * 1.6 + $n ) ) ), max( 0, min( 255, (int) ( $rgb[1] * $k * 1.6 + $n ) ) ), max( 0, min( 255, (int) ( $rgb[2] * $k * 1.6 + $n ) ) ) );
				imagesetpixel( $im, $x, $y, $c );
			}
		}
		$tmp = wp_tempnam( $slug . '.jpg' );
		imagejpeg( $im, $tmp, 82 );
		imagedestroy( $im );
		$file    = array(
			'name'     => $slug . '.jpg',
			'tmp_name' => $tmp,
		);
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_slug( $slug );
		$product->set_regular_price( (string) $price );
		$product->set_description( $desc . "\n\n" . $desc );
		$product->set_short_description( $desc );
		$product->set_status( 'publish' );
		$product->set_category_ids( array( $cat_id ) );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 12 );
		$id  = $product->save();
		$att = media_handle_sideload( $file, $id, $name );
		if ( ! is_wp_error( $att ) ) {
			$product->set_image_id( $att );
			$product->save();
		}
		WP_CLI::log( "$name : produit $id, image " . ( is_wp_error( $att ) ? $att->get_error_message() : $att ) );
	}
}
fluffless_bench_seed_woocommerce();
