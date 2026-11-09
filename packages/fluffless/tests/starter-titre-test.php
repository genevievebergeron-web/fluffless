<?php
/**
 * Tests du site neuf : un titre géant dans une colonne suit la largeur de la colonne.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/starter-titre-test.php
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

// Titre géant dans une colonne : la taille suit la colonne (cqi), pas seulement l'écran.
$fluffless_css = (string) file_get_contents( get_template_directory() . '/assets/css/blocks/geant.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fluffless_assert( (bool) preg_match( '/\.wp-block-column:has\(> \.is-style-geant\)\s*\{[^}]*container-type:\s*inline-size/', $fluffless_css ), 'colonne à titre géant sans container-type' );
$fluffless_assert( (bool) preg_match( '/\.wp-block-column > \.is-style-geant\s*\{[^}]*\dcqi\b/', $fluffless_css ), 'titre géant de colonne sans taille en cqi' );
$fluffless_assert( (bool) preg_match( '/\.is-style-geant\s*\{[^}]*overflow-wrap:\s*break-word/', $fluffless_css ), 'titre géant sans coupe de mot trop long (overflow-wrap)' );
// Le plancher du clamp borne le bas; le plafond reste le préréglage géant du thème : jamais plus gros qu'avant.
$fluffless_assert( (bool) preg_match( '/\.wp-block-column > \.is-style-geant\s*\{[^}]*font-size:\s*clamp\(\s*[\d.]+rem,\s*[\d.]+cqi,\s*var\(--wp--preset--font-size--giant\)\s*\)/', $fluffless_css ), 'taille du titre de colonne : clamp(plancher, cqi, préréglage géant) attendu' );
// Le conteneur et la taille en cqi doivent viser la même colonne, sinon cqi retombe sur la fenêtre.
$fluffless_assert( substr_count( $fluffless_css, '.wp-block-column:has(> .is-style-geant)' ) === 1 && substr_count( $fluffless_css, '.wp-block-column > .is-style-geant' ) >= 1, 'conteneur et titre de colonne ne visent pas le même parent' );
// Limite : test de texte de feuille de style. Le rendu sans mot coupé à quatre largeurs est prouvé au navigateur (outils/k2a-mesure.mjs).

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( 'starter-titre : ' . count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} :\n - " . implode( "\n - ", $fluffless_failures ) );
}
WP_CLI::success( "starter-titre : {$fluffless_checks} vérifications passées." );
