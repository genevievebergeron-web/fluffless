<?php
/**
 * Test de l'image de partage du module SEO (og:image) quand le site convertit les envois en WebP
 * ou AVIF : l'original JPEG ou PNG resté sur disque est servi, mais seulement s'il montre la même
 * image que le fichier principal. Même logique que Fluffless SEO, qui l'appelle.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/seo-image-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Seo;

defined( 'ABSPATH' ) || exit;

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Charge une requête comme si le visiteur ouvrait ce contenu.
$fluffless_visit = static function ( array $query ): void {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	$GLOBALS['wp_query']     = new WP_Query( $query );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	$GLOBALS['post']         = $GLOBALS['wp_query']->post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	Seo::reset();
};

// Réglages de la Suite imposés le temps du test.
$fluffless_settings_cb = null;
$fluffless_settings    = static function ( array $settings ) use ( &$fluffless_settings_cb ): void {
	if ( null !== $fluffless_settings_cb ) {
		remove_filter( 'pre_option_' . Options::NAME, $fluffless_settings_cb );
	}
	$fluffless_settings_cb = static fn(): array => $settings;
	add_filter( 'pre_option_' . Options::NAME, $fluffless_settings_cb );
	Options::flush();
	Seo::reset();
};

$fluffless_up  = wp_get_upload_dir();
$fluffless_png = $fluffless_up['path'] . '/fluffless-suite-og-test.png';
$fluffless_jpg = $fluffless_up['path'] . '/fluffless-suite-og-test.jpg';
$fluffless_gd  = imagecreatetruecolor( 12, 6 );
imagepng( $fluffless_gd, $fluffless_png );
$fluffless_post          = (int) wp_insert_post(
	array(
		'post_title'  => 'Image de partage',
		'post_status' => 'publish',
		'post_type'   => 'post',
	)
);
$fluffless_att           = (int) wp_insert_attachment(
	array(
		'post_mime_type' => 'image/webp',
		'post_title'     => 'fluffless-suite-og-test',
		'post_status'    => 'inherit',
	),
	$fluffless_up['path'] . '/fluffless-suite-og-test.webp'
);
$fluffless_webp          = $fluffless_up['url'] . '/fluffless-suite-og-test-1024x538.webp';
$fluffless_meta          = static function ( array $changes ) use ( $fluffless_att, $fluffless_up ): void {
	wp_update_attachment_metadata(
		$fluffless_att,
		array_merge(
			array(
				'width'          => 12,
				'height'         => 6,
				'file'           => ltrim( $fluffless_up['subdir'] . '/fluffless-suite-og-test.webp', '/' ),
				'sizes'          => array(
					'large' => array(
						'file'      => 'fluffless-suite-og-test-1024x538.webp',
						'width'     => 1024,
						'height'    => 538,
						'mime-type' => 'image/webp',
					),
				),
				'original_image' => 'fluffless-suite-og-test.png',
			),
			$changes
		)
	);
	Seo::reset();
};
$fluffless_webp_original = static function ( $url ) {
	return str_replace( '.png', '.webp', (string) $url );
};

try {
	set_post_thumbnail( $fluffless_post, $fluffless_att );
	$fluffless_visit( array( 'p' => $fluffless_post ) );
	$fluffless_meta( array() );
	$fluffless_assert( Seo::image_url() === $fluffless_up['url'] . '/fluffless-suite-og-test.png', 'Image mise en avant WebP : l\'original PNG n\'est pas servi : ' . Seo::image_url() );

	$fluffless_meta( array( 'width' => 24 ) );
	$fluffless_assert( Seo::image_url() === $fluffless_webp, 'Original d\'une autre taille (-scaled, pivoté) : la taille « large » devait rester : ' . Seo::image_url() );

	$fluffless_meta( array( 'original_image' => 'fluffless-suite-og-test.webp' ) );
	$fluffless_assert( Seo::image_url() === $fluffless_webp, 'Original WebP : la taille « large » devait rester : ' . Seo::image_url() );

	$fluffless_meta( array( 'original_image' => '' ) );
	$fluffless_assert( Seo::image_url() === $fluffless_webp, 'Sans original : la taille « large » devait rester : ' . Seo::image_url() );

	$fluffless_meta( array() );
	update_post_meta( $fluffless_att, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => 'fluffless-suite-og-test.webp' ) ) );
	Seo::reset();
	$fluffless_assert( Seo::image_url() === $fluffless_webp, 'Image retouchée : l\'original ignorerait la retouche : ' . Seo::image_url() );
	delete_post_meta( $fluffless_att, '_wp_attachment_backup_sizes' );

	add_filter( 'wp_get_original_image_url', $fluffless_webp_original );
	Seo::reset();
	$fluffless_assert( Seo::image_url() === $fluffless_webp, 'Adresse d\'original réécrite en WebP par un filtre : elle ne devait pas être servie : ' . Seo::image_url() );
	remove_filter( 'wp_get_original_image_url', $fluffless_webp_original );

	// Image par défaut (réglage du module) : même règle quand le contenu n'a pas d'image mise en avant.
	delete_post_thumbnail( $fluffless_post );
	$fluffless_settings( array( 'seo' => array( 'social_image_id' => $fluffless_att ) ) );
	$fluffless_assert( Seo::image_url() === $fluffless_up['url'] . '/fluffless-suite-og-test.png', 'Image par défaut WebP : l\'original PNG n\'est pas servi : ' . Seo::image_url() );

	file_put_contents( $fluffless_png, str_repeat( "\0", 5 * MB_IN_BYTES ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- original de plus de 5 Mo.
	clearstatcache();
	Seo::reset();
	$fluffless_assert( Seo::image_url() === $fluffless_webp, 'Original de plus de 5 Mo : la taille « large » devait rester : ' . Seo::image_url() );

	wp_delete_file( $fluffless_png );
	Seo::reset();
	$fluffless_assert( Seo::image_url() === $fluffless_webp, 'Original absent : la taille « large » devait rester : ' . Seo::image_url() );

	// Original JPEG : servi tel quel, sauf s'il porte une orientation EXIF (pivoté à l'affichage).
	$fluffless_gd = imagecreatetruecolor( 12, 6 );
	imagejpeg( $fluffless_gd, $fluffless_jpg );
	$fluffless_meta( array( 'original_image' => 'fluffless-suite-og-test.jpg' ) );
	$fluffless_assert( Seo::image_url() === $fluffless_up['url'] . '/fluffless-suite-og-test.jpg', 'Original JPEG sans EXIF : il n\'est pas servi : ' . Seo::image_url() );
	$fluffless_bytes = (string) file_get_contents( $fluffless_jpg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du test.
	$fluffless_tiff  = "Exif\0\0II*\0" . pack( 'V', 8 ) . pack( 'v', 1 ) . pack( 'vvVvv', 0x0112, 3, 1, 6, 0 ) . pack( 'V', 0 );
	file_put_contents( $fluffless_jpg, substr( $fluffless_bytes, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $fluffless_tiff ) + 2 ) . $fluffless_tiff . substr( $fluffless_bytes, 2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- JPEG du test avec orientation 6.
	Seo::reset();
	$fluffless_assert( Seo::image_url() === $fluffless_webp, 'Original JPEG pivoté par EXIF : la taille « large » devait rester : ' . Seo::image_url() );

	$fluffless_meta(
		array(
			'file'  => ltrim( $fluffless_up['subdir'] . '/fluffless-suite-og-test.jpg', '/' ),
			'sizes' => array(
				'large' => array(
					'file'      => 'fluffless-suite-og-test-1024x538.jpg',
					'width'     => 1024,
					'height'    => 538,
					'mime-type' => 'image/jpeg',
				),
			),
		)
	);
	$fluffless_assert( Seo::image_url() === $fluffless_up['url'] . '/fluffless-suite-og-test-1024x538.jpg', 'Taille « large » en JPEG : elle devait rester telle quelle : ' . Seo::image_url() );
	$fluffless_assert( Seo::share_image_url( $fluffless_att ) === Seo::image_url(), 'share_image_url() et image_url() ne rendent pas la même adresse' );
} finally {
	remove_filter( 'wp_get_original_image_url', $fluffless_webp_original );
	if ( null !== $fluffless_settings_cb ) {
		remove_filter( 'pre_option_' . Options::NAME, $fluffless_settings_cb );
		Options::flush();
	}
	wp_delete_attachment( $fluffless_att, true );
	wp_delete_post( $fluffless_post, true );
	foreach ( array( $fluffless_png, $fluffless_jpg ) as $fluffless_file ) {
		if ( is_file( $fluffless_file ) ) {
			wp_delete_file( $fluffless_file );
		}
	}
	Seo::reset();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "SEO, image de partage : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "SEO, image de partage : {$fluffless_checks} vérifications passées." );
