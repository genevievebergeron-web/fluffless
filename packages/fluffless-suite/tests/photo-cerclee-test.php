<?php
/**
 * Tests de la « Photo cerclée » : anneau de texte SVG injecté au rendu de core/image à partir de
 * la légende (Ring::image) et fabrique réutilisable de l'anneau (Ring::svg).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/photo-cerclee-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Presentation\Patterns;
use Fluffless\Suite\Presentation\Ring;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Ring::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé ou classe Ring absente (wp plugin activate fluffless-suite).' );
}
if ( Patterns::TEMPLATE !== get_template() ) {
	throw new RuntimeException( esc_html( 'Thème actif : ' . get_template() . '. Le test attend le thème fluffless (wp theme activate fluffless).' ) );
}

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_image = static function ( string $class_name, string $caption ): string {
	$markup = '<figure class="wp-block-image size-large' . ( '' === $class_name ? '' : ' ' . $class_name ) . '"><img src="photo.jpg" alt="Portrait"/>' . ( '' === $caption ? '' : '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' ) . '</figure>';
	return render_block(
		array(
			'blockName'    => 'core/image',
			'attrs'        => '' === $class_name ? array() : array( 'className' => $class_name ),
			'innerBlocks'  => array(),
			'innerHTML'    => $markup,
			'innerContent' => array( $markup ),
		)
	);
};

/*
 * Avec une légende : un SVG décoratif, la légende gardée pour les lecteurs d'écran.
 */
$fluffless_html = $fluffless_image( 'is-style-photo-cerclee', 'Atelier <em>ouvert</em> le samedi' );
$fluffless_assert( 1 === substr_count( $fluffless_html, '<svg' ) && 1 === substr_count( $fluffless_html, '</svg>' ), 'Avec légende : un seul SVG : ' . $fluffless_html );
$fluffless_assert( str_contains( $fluffless_html, '<svg class="fl-ring"' ) && str_contains( $fluffless_html, 'aria-hidden="true"' ) && str_contains( $fluffless_html, 'focusable="false"' ), 'Avec légende : SVG décoratif, caché des lecteurs d’écran.' );
$fluffless_assert( str_contains( $fluffless_html, '<textPath' ) && 1 === preg_match( '/<path id="(fl-ring-[^"]+)"[^>]*\/><text[^>]*><textPath href="#\1"/', $fluffless_html ), 'Avec légende : textPath posé sur le cercle par son identifiant.' );
$fluffless_assert( 1 === preg_match( '/<text font-size="[0-9.]+" textLength="[0-9.]+">/', $fluffless_html ) && ! str_contains( $fluffless_html, '<textPath textLength' ) && 1 !== preg_match( '/<textPath[^>]*textLength/', $fluffless_html ), 'Avec légende : textLength sur text et non sur textPath, où Firefox l’ignore (mesuré : 175 sur 279 unités).' );
$fluffless_assert( substr_count( $fluffless_html, 'Atelier ouvert le samedi' ) >= 2, 'Avec légende : texte sans balise, répété pour remplir le tour.' );
$fluffless_assert( str_contains( $fluffless_html, '<figcaption class="wp-element-caption">Atelier <em>ouvert</em> le samedi</figcaption>' ), 'Avec légende : figcaption intacte pour les lecteurs d’écran.' );
$fluffless_assert( str_contains( $fluffless_html, 'has-fl-ring' ) && str_ends_with( trim( $fluffless_html ), '</svg></figure>' ), 'Avec légende : figure marquée has-fl-ring, SVG à l’intérieur de la figure.' );
$fluffless_tags = new WP_HTML_Tag_Processor( $fluffless_html );
$fluffless_assert( $fluffless_tags->next_tag( array( 'tag_name' => 'figure' ) ) && true === $fluffless_tags->has_class( 'has-fl-ring' ) && true === $fluffless_tags->has_class( 'is-style-photo-cerclee' ), 'Avec légende : classes posées sur la figure.' );

$fluffless_second = $fluffless_image( 'is-style-photo-cerclee', 'Deuxième photo' );
preg_match( '/id="(fl-ring-[^"]+)"/', $fluffless_html, $fluffless_first_id );
preg_match( '/id="(fl-ring-[^"]+)"/', $fluffless_second, $fluffless_second_id );
$fluffless_assert( isset( $fluffless_first_id[1], $fluffless_second_id[1] ) && $fluffless_first_id[1] !== $fluffless_second_id[1], 'Deux photos cerclées : identifiants de cercle distincts.' );

/*
 * Sans légende ou sans le style : aucun SVG, rendu inchangé.
 */
foreach ( array(
	'style sans légende'    => array( 'is-style-photo-cerclee', '' ),
	'légende vide'          => array( 'is-style-photo-cerclee', '   ' ),
	'légende sans le style' => array( '', 'Une légende' ),
	'autre style'           => array( 'is-style-cercle', 'Une légende' ),
	'classe au nom voisin'  => array( 'is-style-photo-cerclee-bis', 'Une légende' ),
) as $fluffless_case => list( $fluffless_class, $fluffless_caption ) ) {
	$fluffless_html = $fluffless_image( $fluffless_class, $fluffless_caption );
	$fluffless_assert( ! str_contains( $fluffless_html, '<svg' ) && ! str_contains( $fluffless_html, 'has-fl-ring' ), "Aucun SVG : {$fluffless_case}." );
}

/*
 * Échappement : une légende hostile ne sort jamais du texte.
 */
$fluffless_hostile = '&lt;script&gt;alert("x")&lt;/script&gt; l\'atelier "ouvert" &amp; &lt;b&gt;';
$fluffless_html    = $fluffless_image( 'is-style-photo-cerclee', $fluffless_hostile );
$fluffless_svg     = (string) substr( $fluffless_html, (int) strpos( $fluffless_html, '<svg' ) );
$fluffless_assert( ! str_contains( $fluffless_svg, '<script' ) && ! str_contains( $fluffless_svg, '<b>' ) && str_contains( $fluffless_svg, '&lt;script&gt;' ), 'Échappement : chevrons rendus en entités dans le SVG.' );
$fluffless_assert( ! str_contains( $fluffless_svg, 'alert("x")' ) && str_contains( $fluffless_svg, 'alert(&quot;x&quot;)' ) && ! str_contains( $fluffless_svg, "l'atelier" ), 'Échappement : guillemets et apostrophes en entités.' );
$fluffless_direct = Ring::svg( '"><script>alert(1)</script><svg onload="x', 100 );
$fluffless_assert( ! str_contains( $fluffless_direct, '<script' ) && ! str_contains( $fluffless_direct, 'onload="x' ) && 1 === substr_count( $fluffless_direct, '<svg' ), 'Ring::svg : texte brut hostile échappé.' );

/*
 * Fabrique réutilisable : géométrie, bornes, poids.
 */
$fluffless_assert( '' === Ring::svg( '', 100 ) && '' === Ring::svg( "  \n ", 100 ) && '' === Ring::svg( 'Texte', 0 ) && '' === Ring::svg( 'Texte', -40 ), 'Ring::svg : rien sans texte ni taille positive.' );
$fluffless_small = Ring::svg( 'Sceau', 100 );
$fluffless_large = Ring::svg( 'Sceau', 240 );
$fluffless_assert( str_contains( $fluffless_small, 'viewBox="0 0 100 100"' ) && str_contains( $fluffless_large, 'viewBox="0 0 240 240"' ), 'Ring::svg : viewBox à la taille demandée.' );
$fluffless_assert( substr_count( $fluffless_small, 'Sceau' ) >= 2 && substr_count( $fluffless_small, 'Sceau' ) <= Ring::MAX_REPEATS, 'Ring::svg : texte court répété, répétitions bornées.' );
$fluffless_long = Ring::svg( str_repeat( 'Un très long texte de légende. ', 40 ), 100 );
$fluffless_assert( 1 === substr_count( $fluffless_long, 'Un très long texte' ) || 2 === substr_count( $fluffless_long, 'Un très long texte' ), 'Ring::svg : texte long coupé à la capacité du tour, jamais répété au-delà.' );
$fluffless_assert( str_contains( $fluffless_long, '…' ) && strlen( $fluffless_long ) < 900, 'Ring::svg : texte long tronqué d’une ellipse (' . strlen( $fluffless_long ) . ' o).' );
$fluffless_shell = strlen( $fluffless_small ) - strlen( (string) preg_replace( '/^.*<textPath[^>]*>(.*)<\/textPath>.*$/s', '$1', $fluffless_small ) );
$fluffless_assert( $fluffless_shell > 0 && $fluffless_shell < 600, "Ring::svg : enveloppe sous 600 o hors texte ({$fluffless_shell} o)." );
$fluffless_assert( ! str_contains( $fluffless_small, 'http' ) && ! str_contains( $fluffless_small, '<style' ) && ! str_contains( $fluffless_small, '<script' ), 'Ring::svg : ni ressource externe, ni style, ni script.' );
$fluffless_accent = Ring::svg( 'Été à Québec ', 100 );
$fluffless_assert( str_contains( $fluffless_accent, 'Été à Québec' ) && 1 === preg_match( '//u', $fluffless_accent ), 'Ring::svg : accents gardés, UTF-8 valide.' );

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Photo cerclée de Suite : {$fluffless_checks} vérifications passées." );
