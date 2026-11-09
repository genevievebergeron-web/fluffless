<?php
/**
 * Tests du site neuf : la phrase de pied de page à remplacer ne se publie pas, et aucun motif
 * ne rend d'image sans source.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/starter-consignes-test.php
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

// Rendu public des pieds de page : la phrase d'exemple n'est pas publiée. Elle se compare dans sa forme
// d'origine et dans la langue du site.
$fluffless_registry = WP_Block_Patterns_Registry::get_instance();
$fluffless_footers  = array( 'fluffless/pied-de-page', 'fluffless/pied-de-page-editorial' );
$fluffless_english  = 'Write the sentence you want people to remember as they leave your site.';
// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.MissingTranslatorsComment -- phrase des motifs, dans la langue du site.
$fluffless_sentences = array( $fluffless_english, __( 'Write the sentence you want people to remember as they leave your site.', 'fluffless' ), __( 'Write the sentence you want people to remember as they leave your site.', 'fluffless-suite' ) );
// phpcs:enable
foreach ( $fluffless_footers as $fluffless_slug ) {
	if ( ! $fluffless_registry->is_registered( $fluffless_slug ) ) {
		continue;
	}
	$fluffless_html = do_blocks( '<!-- wp:pattern {"slug":"' . $fluffless_slug . '"} /-->' );
	$fluffless_assert( '' !== trim( $fluffless_html ), "{$fluffless_slug} ne rend rien" );
	foreach ( $fluffless_sentences as $fluffless_sentence ) {
		$fluffless_assert( ! str_contains( $fluffless_html, $fluffless_sentence ), "{$fluffless_slug} : phrase d'exemple dans le rendu public" );
	}
}

// Aucun motif Fluffless ne rend une image sans source (le noyau retire le bloc image vide).
foreach ( $fluffless_registry->get_all_registered() as $fluffless_pattern ) {
	if ( 1 !== preg_match( '#^fluffless(-suite)?/#', $fluffless_pattern['name'] ) ) {
		continue;
	}
	$fluffless_rendered = do_blocks( $fluffless_pattern['content'] );
	$fluffless_assert( 0 === preg_match( '/<img(?![^>]*\ssrc=)[^>]*>/i', $fluffless_rendered ), "{$fluffless_pattern['name']} : image sans source dans le rendu public" );
}

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( 'starter-consignes : ' . count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} :\n - " . implode( "\n - ", $fluffless_failures ) );
}
WP_CLI::success( "starter-consignes : {$fluffless_checks} vérifications passées." );
