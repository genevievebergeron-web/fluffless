<?php
/**
 * Test du bloc Jalon (fluffless/jalon), l'étape du bloc Frise : enregistré comme descendant de la
 * Frise, rend le même conteneur que l'ancien groupe natif, les frises déjà enregistrées avec des
 * groupes restent rendues.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/jalon-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\Sections;
use Fluffless\Suite\Modules\Expression;
use const Fluffless\Suite\FILE;

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

$fluffless_registry = WP_Block_Type_Registry::get_instance();
$fluffless_before   = array_keys( $fluffless_registry->get_all_registered() );
if ( ! $fluffless_registry->is_registered( 'fluffless/' . Sections::STEP ) ) {
	Expression::register_block();
}
$fluffless_type = $fluffless_registry->get_registered( 'fluffless/' . Sections::STEP );
if ( ! $fluffless_type instanceof WP_Block_Type ) {
	throw new RuntimeException( 'Bloc Jalon non enregistré.' );
}
$fluffless_dir = dirname( FILE ) . '/blocks/';

try {
	// 1. Déclaration : descendant de la Frise (ancestor, pas parent : les jalons vivent dans le groupe
	// liste), ancre, pas de script ni de feuille côté site.
	$fluffless_assert( array( 'fluffless/frise' ) === $fluffless_type->ancestor && null === $fluffless_type->parent, 'jalon : ancestor/parent inattendus' );
	$fluffless_assert( block_has_support( $fluffless_type, 'anchor' ) && array() === $fluffless_type->view_script_handles && array() === $fluffless_type->style_handles, 'jalon : support anchor ou ressources côté site' );
	$fluffless_asset = include $fluffless_dir . Sections::STEP . '/index.asset.php';
	$fluffless_assert( is_array( $fluffless_asset ) && in_array( Sections::EDITOR_SCRIPT, $fluffless_asset['dependencies'] ?? array(), true ), 'index.asset.php : aides communes absentes des dépendances' );

	// 2. Rendu : le conteneur de l'ancienne étape, les blocs internes dedans ; chaque jalon est un
	// enfant direct de la liste, donc ciblé par la feuille de la Frise.
	$fluffless_step = static fn( string $date, string $title ): string => '<!-- wp:fluffless/jalon --><!-- wp:paragraph {"className":"fluffless-frise__date"} --><p class="fluffless-frise__date">' . $date . '</p><!-- /wp:paragraph --><!-- wp:heading {"level":3} --><h3>' . $title . '</h3><!-- /wp:heading --><!-- /wp:fluffless/jalon -->';
	$fluffless_html = do_blocks( '<!-- wp:fluffless/frise --><!-- wp:heading --><h2>How</h2><!-- /wp:heading --><!-- wp:group {"className":"fluffless-frise__list","layout":{"type":"default"}} --><div class="wp-block-group fluffless-frise__list">' . $fluffless_step( '2019', 'Start' ) . $fluffless_step( '2024', 'Now' ) . '</div><!-- /wp:group --><!-- /wp:fluffless/frise -->' );
	$fluffless_assert( 2 === substr_count( $fluffless_html, '<div class="wp-block-fluffless-jalon fluffless-frise__step">' ), 'frise : deux jalons attendus : ' . $fluffless_html );
	$fluffless_assert( 1 === preg_match( '#<div class="wp-block-group fluffless-frise__list[^"]*"><div class="wp-block-fluffless-jalon fluffless-frise__step"><p class="fluffless-frise__date[^"]*">2019</p><h3[^>]*>Start</h3></div><div class="wp-block-fluffless-jalon fluffless-frise__step">#', $fluffless_html ), 'frise : jalons non enfants directs de la liste, ou contenu interne perdu : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-frise is-direction-vertical' ), 'frise : conteneur de la frise absent' );

	// 3. Ancre : l'id sur le conteneur du jalon.
	$fluffless_html = do_blocks( '<!-- wp:fluffless/jalon {"anchor":"etape-2019"} --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- /wp:fluffless/jalon -->' );
	$fluffless_assert( 1 === preg_match( '#^<div id="etape-2019" class="wp-block-fluffless-jalon fluffless-frise__step"><p[^>]*>x</p></div>#', $fluffless_html ), 'ancre : id absent du jalon : ' . $fluffless_html );

	// 4. Contenu déjà enregistré avec des groupes natifs : toujours rendu tel quel.
	$fluffless_html = do_blocks( '<!-- wp:fluffless/frise --><!-- wp:group {"className":"fluffless-frise__list"} --><div class="wp-block-group fluffless-frise__list"><!-- wp:group {"className":"fluffless-frise__step"} --><div class="wp-block-group fluffless-frise__step"><!-- wp:heading {"level":3} --><h3>Old</h3><!-- /wp:heading --></div><!-- /wp:group --></div><!-- /wp:group --><!-- /wp:fluffless/frise -->' );
	$fluffless_assert( 1 === preg_match( '#<div class="wp-block-group fluffless-frise__step[^"]*"><h3[^>]*>Old</h3></div>#', $fluffless_html ), 'ancien contenu : étape en groupe natif perdue : ' . $fluffless_html );

	// 5. L'éditeur de la Frise crée des jalons, plus des groupes ; le motif Frise aussi.
	$fluffless_js = (string) file_get_contents( $fluffless_dir . 'frise/index.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
	$fluffless_assert( str_contains( $fluffless_js, "S.createBlock( 'fluffless/jalon' )" ) && ! str_contains( $fluffless_js, 'fluffless-frise__step' ), 'frise/index.js : crée encore des groupes natifs' );
	$fluffless_pattern = (string) file_get_contents( dirname( FILE ) . '/patterns/section-frise.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
	$fluffless_assert( str_contains( $fluffless_pattern, '<!-- wp:fluffless/jalon -->' ) && ! str_contains( $fluffless_pattern, 'fluffless-frise__step' ), 'motif section-frise : étapes encore en groupes natifs' );
} finally {
	foreach ( array_diff( array_keys( $fluffless_registry->get_all_registered() ), $fluffless_before ) as $fluffless_extra ) {
		unregister_block_type( $fluffless_extra );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Jalon : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Jalon : {$fluffless_checks} vérifications passées." );
