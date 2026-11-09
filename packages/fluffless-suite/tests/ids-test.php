<?php
/**
 * Test des identifiants de rendu stables (Presentation\Ids) : même bloc, même id d'une page à l'autre.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/ids-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Modules\Expression;
use Fluffless\Suite\Presentation\Ids;

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
if ( ! $fluffless_registry->is_registered( 'fluffless/icone' ) ) {
	throw new RuntimeException( 'Blocs Fluffless non enregistrés : activer l\'extension (wp plugin activate fluffless-suite).' );
}
// Le module Expressivité (bandeau, pop-up, onglets) est inactif par défaut : ses blocs sont posés ici au
// besoin, retirés à la fin.
$fluffless_before = array_keys( $fluffless_registry->get_all_registered() );
if ( ! $fluffless_registry->is_registered( Expression::POPUP ) ) {
	Expression::register_block();
}

// 1. Fabrique : forme, stabilité, suffixe des répétitions, remise à zéro.
Ids::reset();
$fluffless_a = Ids::stable( 'fl-x-', 'graine' );
$fluffless_assert( 1 === preg_match( '/^fl-x-[a-f0-9]{8}$/', $fluffless_a ), 'forme : ' . $fluffless_a );
$fluffless_assert( Ids::stable( 'fl-x-', 'graine' ) === $fluffless_a . '-2' && Ids::stable( 'fl-x-', 'graine' ) === $fluffless_a . '-3', 'répétition sans suffixe -2, -3' );
$fluffless_assert( Ids::stable( 'fl-x-', 'autre' ) !== $fluffless_a, 'graines différentes, même id' );
Ids::reset();
$fluffless_assert( Ids::stable( 'fl-x-', 'graine' ) === $fluffless_a, 'reset : l\'id de base ne revient pas' );

// 2. Même bloc à des positions différentes de deux pages : même id ; le cœur rendu avant ne compte plus.
$fluffless_page = static function ( string $before, string $block ): string {
	Ids::reset();
	return do_blocks( $before . $block );
};
// Dernière occurrence : la page de contrôle place un autre bloc de la Suite avant celui qu'on compare.
$fluffless_id     = static function ( string $html, string $pattern ): string {
	return 0 < preg_match_all( $pattern, $html, $m ) ? (string) end( $m[1] ) : '';
};
$fluffless_cases  = array(
	'icone'   => array( '<!-- wp:fluffless/icone {"icon":"leaf","label":"Feuille"} /-->', '/aria-labelledby="(fluffless-icone-[^"]+)"/' ),
	'bandeau' => array( '<!-- wp:fluffless/bandeau {"items":["Un","Deux"]} /-->', '/id="(fl-bandeau-[^"]+)"/' ),
	'popup'   => array( '<!-- wp:fluffless/popup {"label":"Ouvrir"} --><!-- wp:paragraph --><p>Contenu</p><!-- /wp:paragraph --><!-- /wp:fluffless/popup -->', '/popovertarget="(fluffless-popup-[^"]+)"/' ),
	'onglets' => array( '<!-- wp:fluffless/onglets --><!-- wp:details --><details class="wp-block-details"><summary>A</summary><!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --></details><!-- /wp:details --><!-- /wp:fluffless/onglets -->', '/name="(fluffless-onglets-[^"]+)"/' ),
);
$fluffless_filler = str_repeat( '<!-- wp:paragraph --><p>Texte</p><!-- /wp:paragraph --><!-- wp:details --><details class="wp-block-details"><summary>D</summary>d</details><!-- /wp:details -->', 3 );
foreach ( $fluffless_cases as $fluffless_slug => list( $fluffless_block, $fluffless_pattern ) ) {
	$fluffless_first  = $fluffless_id( $fluffless_page( '', $fluffless_block ), $fluffless_pattern );
	$fluffless_second = $fluffless_id( $fluffless_page( $fluffless_filler . '<!-- wp:fluffless/icone {"icon":"leaf","label":"Autre"} /-->', $fluffless_block ), $fluffless_pattern );
	$fluffless_assert( '' !== $fluffless_first && $fluffless_first === $fluffless_second, "{$fluffless_slug} : id instable entre deux pages ({$fluffless_first} / {$fluffless_second})" );
	$fluffless_assert( 1 === preg_match( '/-[a-f0-9]{8}$/', $fluffless_first ), "{$fluffless_slug} : id non dérivé d'un condensé : {$fluffless_first}" );
	$fluffless_twice = $fluffless_page( '', $fluffless_block . $fluffless_block );
	$fluffless_assert( str_contains( $fluffless_twice, $fluffless_first . '-2' ), "{$fluffless_slug} : deux blocs identiques sans suffixe -2" );
}

// 3. Pop-up : un formulaire dans le pop-up (jeton horodaté) ne change pas l'id, dérivé du contenu enregistré.
$fluffless_form  = '<!-- wp:fluffless/formulaire {"formId":"idstab1"} --><!-- wp:fluffless/champ {"type":"text","label":"Nom"} /--><!-- /wp:fluffless/formulaire -->';
$fluffless_popup = '<!-- wp:fluffless/popup {"label":"Écrire"} -->' . $fluffless_form . '<!-- /wp:fluffless/popup -->';
$fluffless_p1    = $fluffless_id( $fluffless_page( '', $fluffless_popup ), '/popovertarget="(fluffless-popup-[^"]+)"/' );
$fluffless_p2    = $fluffless_id( $fluffless_page( '', $fluffless_popup ), '/popovertarget="(fluffless-popup-[^"]+)"/' );
$fluffless_assert( '' !== $fluffless_p1 && $fluffless_p1 === $fluffless_p2, "popup avec formulaire : id instable ({$fluffless_p1} / {$fluffless_p2})" );

// 4. Plus aucun compteur global dans les rendus.
foreach ( array( 'src/Blocks/Icone.php', 'src/Blocks/Bandeau.php', 'src/Blocks/Sections.php', 'src/Modules/Expression.php', 'src/Presentation/Ring.php' ) as $fluffless_file ) {
	$fluffless_assert( ! str_contains( (string) file_get_contents( WP_PLUGIN_DIR . '/fluffless-suite/' . $fluffless_file ), 'wp_unique_id(' ), "{$fluffless_file} : wp_unique_id() encore utilisé" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

foreach ( array_diff( array_keys( $fluffless_registry->get_all_registered() ), $fluffless_before ) as $fluffless_name ) {
	unregister_block_type( $fluffless_name );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Échecs ids :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "ids : {$fluffless_checks} vérifications passées." );
