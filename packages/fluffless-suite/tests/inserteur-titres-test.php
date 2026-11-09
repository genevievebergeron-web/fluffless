<?php
/**
 * Tests de l'inserteur : aucun titre de bloc Fluffless en double, aucun titre identique à un bloc natif de WordPress.
 *
 * Lancer : tools/run-tests.sh inserteur-titres
 *
 * @package Fluffless_Suite
 */

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

// Titres anglais des blocs natifs que le thème et les extensions doublent sans les remplacer.
$fluffless_native = array( 'breadcrumbs', 'breadcrumb', 'tabs', 'accordion', 'details' );

$fluffless_files  = array_merge(
	(array) glob( WP_PLUGIN_DIR . '/fluffless-*/blocks/*/block.json' ),
	(array) glob( get_theme_root() . '/fluffless*/blocks/*/block.json' )
);
$fluffless_titles = array();
foreach ( $fluffless_files as $fluffless_file ) {
	if ( ! is_string( $fluffless_file ) ) {
		continue;
	}
	$fluffless_block = json_decode( (string) file_get_contents( $fluffless_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du dépôt.
	if ( ! is_array( $fluffless_block ) || ! isset( $fluffless_block['name'], $fluffless_block['title'] ) ) {
		continue;
	}
	$fluffless_titles[ strtolower( (string) $fluffless_block['title'] ) ][] = (string) $fluffless_block['name'];
}
$fluffless_assert( count( $fluffless_titles ) > 20, 'inserteur : trop peu de blocs trouvés (' . count( $fluffless_titles ) . ')' );
foreach ( $fluffless_titles as $fluffless_title => $fluffless_names ) {
	$fluffless_assert( 1 === count( $fluffless_names ), "inserteur : le titre « {$fluffless_title} » est porté par plusieurs blocs : " . implode( ', ', $fluffless_names ) );
	$fluffless_assert( ! in_array( $fluffless_title, $fluffless_native, true ), "inserteur : le titre « {$fluffless_title} » (" . implode( ', ', $fluffless_names ) . ') est identique à celui d\'un bloc natif' );
}

// Les dix blocs les plus employés dans les patterns offrent un aperçu au survol de l'inserteur.
$fluffless_with_example = array( 'icone', 'formulaire', 'reseaux', 'popup', 'lien-infolettre', 'legal', 'empreinte', 'certifications', 'badge-hebergement', 'vague' );
foreach ( $fluffless_with_example as $fluffless_slug ) {
	$fluffless_json = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . "/fluffless-suite/blocks/{$fluffless_slug}/block.json" ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du dépôt.
	$fluffless_assert( is_array( $fluffless_json ) && isset( $fluffless_json['example'] ) && is_array( $fluffless_json['example'] ), "inserteur : le bloc {$fluffless_slug} doit déclarer un example" );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Titres de l'inserteur, {$fluffless_checks} vérifications réussies.\n" );
