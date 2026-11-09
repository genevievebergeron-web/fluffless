<?php
/**
 * Tests des réglages par appareil du module Afficher selon l'appareil : marge intérieure,
 * alignement et taille de texte sur mobile, bornés aux préréglages du thème, rendus en classes,
 * feuille en ligne limitée aux classes rencontrées, rien quand aucun bloc ne s'en sert, préréglages
 * transmis à l'éditeur.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/visibility-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Modules\Visibility;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// 1. Préréglages lus dans le thème.
$fluffless_spacing = Visibility::spacing_slugs();
$fluffless_sizes   = Visibility::size_slugs();
$fluffless_assert( in_array( '40', $fluffless_spacing, true ) && in_array( '80', $fluffless_spacing, true ), 'espacements du thème absents : ' . implode( ',', $fluffless_spacing ) );
$fluffless_assert( in_array( 'small', $fluffless_sizes, true ) && in_array( 'large', $fluffless_sizes, true ), 'tailles du thème absentes : ' . implode( ',', $fluffless_sizes ) );
foreach ( array_merge( $fluffless_spacing, $fluffless_sizes ) as $fluffless_slug ) {
	if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $fluffless_slug ) ) {
		$fluffless_assert( false, 'slug hors format : ' . $fluffless_slug );
	}
}

// 2. Classes bornées : valeurs hors liste ignorées, sans classe quand tout est vide.
$fluffless_assert( array() === Visibility::mobile_classes( array() ), 'classes sans réglage' );
$fluffless_assert(
	array( 'fluffless-m-pad-40', 'fluffless-m-align-center', 'fluffless-m-size-small' ) === Visibility::mobile_classes(
		array(
			'padding' => '40',
			'align'   => 'center',
			'size'    => 'small',
		)
	),
	'classes attendues'
);
$fluffless_assert(
	array() === Visibility::mobile_classes(
		array(
			'padding' => '40"onclick',
			'align'   => 'justify',
			'size'    => '<b>',
		)
	),
	'valeurs hors liste rendues'
);
$fluffless_assert(
	array( 'fluffless-m-align-right' ) === Visibility::mobile_classes(
		array(
			'padding' => 12,
			'align'   => 'right',
			'size'    => array( 'x' ),
		)
	),
	'types inattendus tolérés'
);

// 3. Rendu : classes posées sur le premier élément, classes rencontrées notées, feuille chargée. Le module
// est inactif par défaut (donc sur la CI) : ses crochets sont posés ici au besoin et retirés à la fin.
$fluffless_hooked = false !== has_filter( 'render_block', array( Visibility::class, 'render' ) );
if ( ! $fluffless_hooked ) {
	( new Visibility() )->register();
}
Visibility::reset_device_classes();
$fluffless_html = do_blocks( '<!-- wp:paragraph {"flufflessMobile":{"padding":"60","align":"center"}} --><p>Texte</p><!-- /wp:paragraph -->' );
$fluffless_assert( str_contains( $fluffless_html, 'fluffless-m-pad-60' ) && str_contains( $fluffless_html, 'fluffless-m-align-center' ), 'classes mobiles absentes : ' . $fluffless_html );
$fluffless_assert( array( 'fluffless-m-pad-60', 'fluffless-m-align-center' ) === Visibility::device_classes(), 'classes rencontrées : ' . implode( ',', Visibility::device_classes() ) );
$fluffless_assert( wp_style_is( 'fluffless-device', 'enqueued' ), 'feuille des appareils non chargée' );
$fluffless_both = do_blocks( '<!-- wp:paragraph {"flufflessVisibility":"desktop","flufflessMobile":{"size":"large"}} --><p>Deux</p><!-- /wp:paragraph -->' );
$fluffless_assert( str_contains( $fluffless_both, 'fluffless-hide-mobile' ) && str_contains( $fluffless_both, 'fluffless-m-size-large' ), 'affichage et réglage mobile ensemble : ' . $fluffless_both );
$fluffless_assert( 3 === count( Visibility::device_classes() ), 'classes cumulées : ' . count( Visibility::device_classes() ) );
$fluffless_bad = do_blocks( '<!-- wp:paragraph {"flufflessMobile":{"padding":"x\" onclick=\"y"}} --><p>Trois</p><!-- /wp:paragraph -->' );
$fluffless_assert( ! str_contains( $fluffless_bad, 'fluffless-m-' ) && ! str_contains( $fluffless_bad, 'onclick' ), 'valeur hors liste rendue : ' . $fluffless_bad );
$fluffless_assert( '<p>Vide</p>' === Visibility::render( '<p>Vide</p>', array( 'attrs' => array( 'flufflessMobile' => 'texte' ) ) ), 'attribut mobile non tableau' );

// 4. Feuille : seulement les classes rencontrées, sous le point de rupture, rien sans classe.
$fluffless_css = Visibility::device_css( array( 'fluffless-m-pad-60', 'fluffless-m-align-center', 'fluffless-m-size-large', 'fluffless-m-pad-60', 'autre' ) );
$fluffless_assert( str_starts_with( $fluffless_css, '@media (max-width:781.98px){' ), 'point de rupture : ' . $fluffless_css );
$fluffless_assert( str_contains( $fluffless_css, '.fluffless-m-pad-60{padding:var(--wp--preset--spacing--60)!important}' ), 'marge intérieure : ' . $fluffless_css );
$fluffless_assert( str_contains( $fluffless_css, '.fluffless-m-align-center{text-align:center!important}' ), 'alignement : ' . $fluffless_css );
$fluffless_assert( str_contains( $fluffless_css, '.fluffless-m-size-large{font-size:var(--wp--preset--font-size--large)!important}' ), 'taille : ' . $fluffless_css );
$fluffless_assert( 1 === substr_count( $fluffless_css, 'fluffless-m-pad-60' ) && ! str_contains( $fluffless_css, 'autre' ), 'doublon ou classe étrangère : ' . $fluffless_css );
$fluffless_assert( '' === Visibility::device_css( array() ) && '' === Visibility::device_css( array( 'autre' ) ), 'feuille non vide sans classe' );
$fluffless_assert( strlen( $fluffless_css ) < 400 && 0 === preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_css ), 'feuille lourde ou HEX' );

// 5. Feuille imprimée au pied de page avec les classes de la page.
ob_start();
Visibility::print_device_style();
ob_get_clean();
$fluffless_inline = wp_styles()->get_data( 'fluffless-device', 'after' );
$fluffless_assert( is_array( $fluffless_inline ) && str_contains( implode( '', $fluffless_inline ), '.fluffless-m-pad-60{' ) && str_contains( implode( '', $fluffless_inline ), '.fluffless-m-size-large{' ), 'feuille en ligne absente ou incomplète' );

// 6. Attributs déclarés au serveur, préréglages transmis à l'éditeur.
$fluffless_args = Visibility::add_attribute( array(), 'core/group' );
$fluffless_assert( 'object' === ( $fluffless_args['attributes'][ Visibility::MOBILE_ATTRIBUTE ]['type'] ?? null ), 'attribut mobile non déclaré' );
Visibility::editor_assets();
$fluffless_before = wp_scripts()->get_data( 'fluffless-visibility-editor', 'before' );
$fluffless_script = is_array( $fluffless_before ) ? implode( '', $fluffless_before ) : '';
$fluffless_assert( str_contains( $fluffless_script, 'window.flufflessDevice = {"spacing":[{"slug":"' ) && str_contains( $fluffless_script, '"sizes":[{"slug":"' ), 'préréglages non transmis : ' . $fluffless_script );
$fluffless_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/visibility-editor.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fluffless_assert( str_contains( $fluffless_js, "'flufflessMobile'" ) && str_contains( $fluffless_js, 'window.flufflessDevice' ) && str_contains( $fluffless_js, "__( 'On mobile', 'fluffless-suite' )" ), 'script d\'éditeur sans panneau mobile' );

Visibility::reset_device_classes();

if ( ! $fluffless_hooked ) {
	remove_filter( 'register_block_type_args', array( Visibility::class, 'add_attribute' ), 10 );
	remove_filter( 'render_block', array( Visibility::class, 'render' ), 10 );
	remove_action( 'enqueue_block_editor_assets', array( Visibility::class, 'editor_assets' ) );
	remove_action( 'wp_footer', array( Visibility::class, 'print_device_style' ), 5 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Afficher selon l'appareil : {$fluffless_checks} vérifications passées." );
