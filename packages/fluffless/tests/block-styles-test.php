<?php
/**
 * Tests des styles de blocs du thème faits de CSS seulement (assets/css/blocks/) : enregistrement et règles.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/block-styles-test.php
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

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

$fluffless_registry = WP_Block_Styles_Registry::get_instance();
// style.css et les feuilles de assets/css/blocks/ mises en ligne selon les classes de la page.
$fluffless_css = implode( "\n", array_map( 'file_get_contents', array_merge( array( get_template_directory() . '/style.css' ), array_filter( (array) glob( get_template_directory() . '/assets/css/blocks/*.css' ), 'is_string' ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

// Styles CSS seulement, enregistrés sans feuille propre : leurs règles vivent dans assets/css/blocks/.
$fluffless_expected = array(
	'core/button'  => array( 'fleche', 'contour-pixel', 'fleche-pixel' ),
	'core/heading' => array( 'surligne' ),
	'core/image'   => array( 'cadre-decale', 'nb-survol', 'monochrome' ),
	'core/list'    => array( 'coches' ),
);
foreach ( $fluffless_expected as $fluffless_block => $fluffless_names ) {
	foreach ( $fluffless_names as $fluffless_name ) {
		$fluffless_assert( $fluffless_registry->is_registered( $fluffless_block, $fluffless_name ), "style {$fluffless_name} non enregistré pour {$fluffless_block}" );
		$fluffless_style = $fluffless_registry->get_registered_styles_for_block( $fluffless_block )[ $fluffless_name ] ?? array();
		$fluffless_assert( empty( $fluffless_style['style_handle'] ) && empty( $fluffless_style['inline_style'] ), "style {$fluffless_name} : feuille ou CSS en ligne inutile" );
		$fluffless_assert( str_contains( $fluffless_css, ".is-style-{$fluffless_name}" ), "style {$fluffless_name} sans règle dans les feuilles du thème" );
		$fluffless_assert( in_array( "is-style-{$fluffless_name}", fluffless_block_sheets(), true ), "style {$fluffless_name} : aucune feuille ne le charge" );
	}
}
// Les libellés sont enregistrés à l'init dans la langue du site : on compare à la traduction courante.
$fluffless_assert( _x( 'Pixel outline', 'Style variation name', 'fluffless' ) === ( $fluffless_registry->get_registered_styles_for_block( 'core/button' )['contour-pixel']['label'] ?? '' ), 'libellé de contour-pixel' );
$fluffless_assert( _x( 'Pixel arrow', 'Style variation name', 'fluffless' ) === ( $fluffless_registry->get_registered_styles_for_block( 'core/button' )['fleche-pixel']['label'] ?? '' ), 'libellé de fleche-pixel' );

// Contour pixel : bordure carrée de 3 px, angles rognés par clip-path, focus ramené à l'intérieur ; aucune variable de fond.
$fluffless_rule    = static function ( string $selector ) use ( $fluffless_css ): string {
	$pos = strpos( $fluffless_css, $selector );
	if ( false === $pos ) {
		return '';
	}
	$end = strpos( $fluffless_css, '}', $pos );
	return false === $end ? '' : substr( $fluffless_css, $pos, $end - $pos );
};
$fluffless_contour = $fluffless_rule( '.wp-block-button.is-style-contour-pixel .wp-block-button__link {' );
$fluffless_assert( str_contains( $fluffless_contour, 'border: 3px solid currentColor' ) && str_contains( $fluffless_contour, 'border-radius: 0' ), 'contour pixel : bordure carrée de 3 px' );
$fluffless_assert( str_contains( $fluffless_contour, 'clip-path: polygon(3px 0' ) && 12 === substr_count( $fluffless_contour, ',' ) + 1, 'contour pixel : polygone à douze sommets' );
$fluffless_assert( ! str_contains( $fluffless_contour, '--flf-' ), 'contour pixel : variable propre au site' );
$fluffless_assert( str_contains( $fluffless_rule( '.wp-block-button.is-style-contour-pixel .wp-block-button__link:focus-visible {' ), 'outline-offset: -6px' ), 'contour pixel : focus non ramené à l’intérieur' );

// Flèche pixel : masque SVG en données, couleur du texte, même mouvement au survol que la flèche fine.
$fluffless_fleche = $fluffless_rule( '.wp-block-button.is-style-fleche-pixel .wp-block-button__link::after {' );
$fluffless_assert( str_contains( $fluffless_fleche, 'background-color: currentColor' ), 'flèche pixel : pas de la couleur du texte' );
$fluffless_assert( 1 === preg_match( '#mask: url\("data:image/svg\+xml,([^"]+)"\)#', $fluffless_fleche, $fluffless_m ), 'flèche pixel : masque absent' );
$fluffless_svg = rawurldecode( $fluffless_m[1] ?? '' );
$fluffless_assert( str_starts_with( $fluffless_svg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 12 12">' ) && 1 === substr_count( $fluffless_svg, '<path' ), 'flèche pixel : SVG de 12 × 12 à un tracé' );
$fluffless_assert( 0 === preg_match( '/[^MHVZhvz0-9 .-]/', (string) preg_replace( '/^.*d="([^"]+)".*$/s', '$1', $fluffless_svg ) ), 'flèche pixel : tracé au-delà des segments droits (rien de courbe, rien de rond)' );
$fluffless_assert( str_contains( $fluffless_rule( '.wp-block-button.is-style-fleche-pixel .wp-block-button__link:hover::after {' ), 'translateX(0.25em)' ), 'flèche pixel : sans mouvement au survol' );
$fluffless_assert( ! str_contains( $fluffless_css, 'url(http' ) && ! str_contains( $fluffless_css, '@import' ), 'feuilles du thème : ressource distante' );

// Chargeur : chaque feuille existe, et seule la classe exacte l'appelle (fleche ne tire pas fleche-pixel).
foreach ( array_keys( fluffless_block_sheets() ) as $fluffless_sheet ) {
	$fluffless_assert( is_readable( get_template_directory() . "/assets/css/blocks/{$fluffless_sheet}.css" ), "feuille {$fluffless_sheet}.css absente" );
}
$fluffless_assert( array() === array_diff( array_map( static fn( string $f ): string => basename( $f, '.css' ), array_filter( (array) glob( get_template_directory() . '/assets/css/blocks/*.css' ), 'is_string' ) ), array_keys( fluffless_block_sheets() ) ), 'feuille de assets/css/blocks/ que rien ne charge' );
// Comme en vrai : le gabarit se rend avant que style.css soit enfilé, la feuille attend.
wp_deregister_style( 'fluffless' );
fluffless_inline_block_sheets( '<div class="wp-block-buttons"><div class="wp-block-button is-style-fleche-pixel"><a class="wp-block-button__link">x</a></div></div>' );
fluffless_enqueue_styles();
$fluffless_inline = implode( "\n", (array) wp_styles()->get_data( 'fluffless', 'after' ) );
$fluffless_assert( str_contains( $fluffless_inline, '.is-style-fleche-pixel' ), 'chargeur : fleche-pixel non mise en ligne' );
$fluffless_assert( ! str_contains( $fluffless_inline, '.is-style-fleche .' ) && ! str_contains( $fluffless_inline, 'has-giant-font-size' ), 'chargeur : feuille sans sa classe mise en ligne' );
fluffless_inline_block_sheets( '<div class="wp-block-button is-style-fleche-pixel"></div>' );
$fluffless_assert( 1 === substr_count( implode( "\n", (array) wp_styles()->get_data( 'fluffless', 'after' ) ), 'is-style-fleche-pixel .wp-block-button__link::after {' ), 'chargeur : feuille mise en ligne deux fois' );
// Bloc rendu après l'impression de style.css : la feuille arrive avec le bloc au lieu de se perdre.
wp_styles()->done[] = 'fluffless';
$fluffless_late     = fluffless_inline_block_sheets( '<ul class="wp-block-list is-style-coches"><li>x</li></ul>' );
$fluffless_assert( str_starts_with( $fluffless_late, '<style id="fluffless-coches-inline-css">' ) && str_contains( $fluffless_late, '.is-style-coches' ), 'chargeur : feuille perdue pour un bloc rendu après l\'en-tête' );
// Taille Géante : un vrai titre rendu par le cœur (do_blocks) appelle sa feuille. Un titre ajouté en JavaScript
// ne passe pas par le rendu des blocs et ne l'appelle pas : test:giant-size pose donc la feuille lui-même.
$fluffless_giant = do_blocks( '<!-- wp:heading {"fontSize":"giant"} --><h2 class="wp-block-heading has-giant-font-size">x</h2><!-- /wp:heading -->' );
$fluffless_assert( str_contains( $fluffless_giant, '<style id="fluffless-taille-geante-inline-css">' ) && str_contains( $fluffless_giant, 'overflow-wrap: break-word' ), 'chargeur : un titre Géant rendu n\'appelle pas taille-geante.css' );

// Focus visible (C-S3-07) : le trait contrast est bordé de base des deux côtés. Le liseré couvre donc le décalage
// et la largeur du trait, plus une marge de 2 px, pour que l'anneau ne dépende pas du fond de la section.
$fluffless_focus = $fluffless_rule( ':root :is(a, button, input, select, textarea, summary, [tabindex]):focus-visible {' );
$fluffless_assert( 1 === preg_match( '/outline: (\d+)px solid var\(--wp--preset--color--contrast\)/', $fluffless_focus, $fluffless_w ) && 1 === preg_match( '/outline-offset: (\d+)px/', $fluffless_focus, $fluffless_o ) && 1 === preg_match( '/box-shadow: 0 0 0 (\d+)px var\(--wp--preset--color--base\)/', $fluffless_focus, $fluffless_s ), 'focus : règle globale du double anneau introuvable' );
$fluffless_assert( (int) ( $fluffless_s[1] ?? 0 ) >= (int) ( $fluffless_o[1] ?? 99 ) + (int) ( $fluffless_w[1] ?? 99 ) + 2, 'focus : le liseré base ne déborde pas du trait (anneau contre le fond de la section)' );

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( "Styles de blocs du thème :\n- " . implode( "\n- ", $fluffless_failures ) );
}
WP_CLI::success( "Styles de blocs du thème, {$fluffless_checks} vérifications réussies." );
