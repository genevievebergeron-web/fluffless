<?php
/**
 * Tests des dimensions d'image lues dans le thème : un bloc Image ou Média et texte dont l'image
 * est un fichier du thème (pas une pièce jointe) reçoit width et height, et le cœur peut alors
 * poser loading et decoding. Rien pour une image déjà dimensionnée, extérieure au thème ou
 * dont le chemin remonte.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/image-dimensions-test.php
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
$fluffless_dir      = get_template_directory() . '/tests/tmp-images';
$fluffless_uri      = get_template_directory_uri() . '/tests/tmp-images';
$fluffless_files    = array();

// Attributs de la première <img> du rendu.
$fluffless_img   = static function ( string $html ): array {
	$tags = new WP_HTML_Tag_Processor( $html );
	if ( ! $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		return array();
	}
	$out = array();
	foreach ( array( 'src', 'width', 'height', 'loading', 'decoding', 'fetchpriority' ) as $name ) {
		$value = $tags->get_attribute( $name );
		if ( null !== $value ) {
			$out[ $name ] = $value;
		}
	}
	return $out;
};
$fluffless_image = static function ( string $src, string $extra = '' ): string {
	return do_blocks( '<!-- wp:image --><figure class="wp-block-image"><img src="' . $src . '" alt=""' . $extra . '/></figure><!-- /wp:image -->' );
};

try {
	if ( ! wp_mkdir_p( $fluffless_dir ) ) {
		throw new RuntimeException( 'Dossier de test impossible à créer.' );
	}
	$fluffless_png = imagecreatetruecolor( 12, 8 );
	if ( false === $fluffless_png || ! imagepng( $fluffless_png, $fluffless_dir . '/a.png' ) ) {
		throw new RuntimeException( 'PNG de test impossible à écrire.' );
	}
	$fluffless_files[] = $fluffless_dir . '/a.png';
	$fluffless_files[] = $fluffless_dir . '/b.svg';
	$fluffless_files[] = $fluffless_dir . '/c.svg';
	$fluffless_files[] = $fluffless_dir . '/d.svg';
	$fluffless_files[] = $fluffless_dir . '/e.txt';
	file_put_contents( $fluffless_dir . '/b.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64.4 32.6"><rect width="10" height="10"/></svg>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $fluffless_dir . '/c.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="40px" height="20"><rect width="10" height="10"/></svg>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $fluffless_dir . '/d.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $fluffless_dir . '/e.txt', 'pas une image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	// 1. PNG du thème : dimensions lues dans le fichier.
	$fluffless_attrs = $fluffless_img( $fluffless_image( $fluffless_uri . '/a.png' ) );
	$fluffless_assert( '12' === ( $fluffless_attrs['width'] ?? '' ) && '8' === ( $fluffless_attrs['height'] ?? '' ), 'png : ' . wp_json_encode( $fluffless_attrs ) );

	// 2. SVG : viewBox arrondi en entiers ; width/height en attributs ; rien sans l'un ni l'autre.
	$fluffless_attrs = $fluffless_img( $fluffless_image( $fluffless_uri . '/b.svg' ) );
	$fluffless_assert( '64' === ( $fluffless_attrs['width'] ?? '' ) && '33' === ( $fluffless_attrs['height'] ?? '' ), 'svg viewBox : ' . wp_json_encode( $fluffless_attrs ) );
	$fluffless_attrs = $fluffless_img( $fluffless_image( $fluffless_uri . '/c.svg' ) );
	$fluffless_assert( '40' === ( $fluffless_attrs['width'] ?? '' ) && '20' === ( $fluffless_attrs['height'] ?? '' ), 'svg width/height : ' . wp_json_encode( $fluffless_attrs ) );
	$fluffless_attrs = $fluffless_img( $fluffless_image( $fluffless_uri . '/d.svg' ) );
	$fluffless_assert( ! isset( $fluffless_attrs['width'] ) && ! isset( $fluffless_attrs['height'] ), 'svg sans dimensions : inventées' );

	// 3. Chaîne de requête, adresse sans schéma, encodage : toujours le même fichier.
	$fluffless_attrs = $fluffless_img( $fluffless_image( $fluffless_uri . '/a.png?v=3#x' ) );
	$fluffless_assert( '12' === ( $fluffless_attrs['width'] ?? '' ), 'chaîne de requête : ' . wp_json_encode( $fluffless_attrs ) );
	$fluffless_attrs = $fluffless_img( $fluffless_image( (string) preg_replace( '#^https?:#', '', $fluffless_uri ) . '/a.png' ) );
	$fluffless_assert( '12' === ( $fluffless_attrs['width'] ?? '' ), 'adresse sans schéma : ' . wp_json_encode( $fluffless_attrs ) );
	$fluffless_attrs = $fluffless_img( $fluffless_image( $fluffless_uri . '/a%2Epng' ) );
	$fluffless_assert( '12' === ( $fluffless_attrs['width'] ?? '' ), 'encodage : ' . wp_json_encode( $fluffless_attrs ) );

	// 4. Refus : width déjà là, image hors du thème, chemin qui remonte (brut ou encodé), extension
	// hors liste, fichier absent.
	$fluffless_attrs = $fluffless_img( $fluffless_image( $fluffless_uri . '/a.png', ' width="3"' ) );
	$fluffless_assert( '3' === ( $fluffless_attrs['width'] ?? '' ) && ! isset( $fluffless_attrs['height'] ), 'width existant écrasé ou complété' );
	foreach ( array(
		'externe'      => 'https://example.com/a.png',
		'téléversé'    => content_url( '/uploads/a.png' ),
		'remonte'      => $fluffless_uri . '/../tmp-images/a.png',
		'remonte enc.' => $fluffless_uri . '/%2e%2e/tmp-images/a.png',
		'extension'    => $fluffless_uri . '/e.txt',
		'absent'       => $fluffless_uri . '/zz.png',
		'style.css'    => get_template_directory_uri() . '/style.css',
	) as $fluffless_case => $fluffless_src ) {
		$fluffless_attrs = $fluffless_img( $fluffless_image( $fluffless_src ) );
		$fluffless_assert( ! isset( $fluffless_attrs['width'] ) && ! isset( $fluffless_attrs['height'] ), "{$fluffless_case} : dimensions posées" );
	}

	// 5. Média et texte : même traitement.
	$fluffless_html  = do_blocks( '<!-- wp:media-text {"mediaType":"image"} --><div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="' . $fluffless_uri . '/a.png" alt=""/></figure><div class="wp-block-media-text__content"><p>x</p></div></div><!-- /wp:media-text -->' );
	$fluffless_attrs = $fluffless_img( $fluffless_html );
	$fluffless_assert( '12' === ( $fluffless_attrs['width'] ?? '' ) && '8' === ( $fluffless_attrs['height'] ?? '' ), 'média et texte : ' . wp_json_encode( $fluffless_attrs ) );

	// 6. Avec les dimensions, le cœur pose decoding et, passé le seuil, loading="lazy" : il le
	// fait par partie de gabarit et pour le contenu (le contexte « template » entier est sauté).
	$fluffless_page = '';
	for ( $fluffless_i = 0; $fluffless_i < 5; $fluffless_i++ ) {
		$fluffless_page .= '<!-- wp:image --><figure class="wp-block-image"><img src="' . $fluffless_uri . '/a.png?i=' . $fluffless_i . '" alt=""/></figure><!-- /wp:image -->';
	}
	$fluffless_page = wp_filter_content_tags( do_blocks( $fluffless_page ), 'the_content' );
	preg_match_all( '/<img[^>]+>/', $fluffless_page, $fluffless_imgs );
	$fluffless_last = end( $fluffless_imgs[0] );
	$fluffless_assert( is_string( $fluffless_last ) && str_contains( $fluffless_last, 'loading="lazy"' ) && str_contains( $fluffless_last, 'decoding="async"' ), 'cinquième image sans loading lazy : ' . (string) $fluffless_last );
	$fluffless_assert( str_contains( $fluffless_imgs[0][0], 'decoding="async"' ), 'première image sans decoding (hors boucle principale, le cœur diffère tout : pas de test sur loading ici)' );
} finally {
	foreach ( $fluffless_files as $fluffless_file ) {
		if ( file_exists( $fluffless_file ) ) {
			wp_delete_file( $fluffless_file );
		}
	}
	if ( is_dir( $fluffless_dir ) ) {
		rmdir( $fluffless_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( 'image-dimensions-test : ' . count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} :\n - " . implode( "\n - ", $fluffless_failures ) );
}
WP_CLI::success( "image-dimensions-test : {$fluffless_checks} vérifications passées." );
