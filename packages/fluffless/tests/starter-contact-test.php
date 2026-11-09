<?php
/**
 * Tests du site neuf : aucune coordonnée factice dans le rendu public des pieds de page.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/starter-contact-test.php
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

// Rendu public des pieds de page : ni courriel ni téléphone factices.
foreach ( array( 'fluffless/pied-de-page', 'fluffless/pied-de-page-compact' ) as $fluffless_slug ) {
	$fluffless_html = do_blocks( '<!-- wp:pattern {"slug":"' . $fluffless_slug . '"} /-->' );
	$fluffless_assert( '' !== trim( $fluffless_html ), "{$fluffless_slug} ne rend rien" );
	$fluffless_assert( ! str_contains( $fluffless_html, 'exemple.ca' ), "{$fluffless_slug} : courriel factice dans le rendu public" );
	$fluffless_assert( ! str_contains( $fluffless_html, '5555550123' ), "{$fluffless_slug} : téléphone factice dans le rendu public" );
}


if ( array() !== $fluffless_failures ) {
	WP_CLI::error( 'starter-contact : ' . count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} :\n - " . implode( "\n - ", $fluffless_failures ) );
}
WP_CLI::success( "starter-contact : {$fluffless_checks} vérifications passées." );
