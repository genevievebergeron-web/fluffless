<?php
/**
 * Test de l'ancre des blocs fluffless/* : l'attribut anchor devient l'id du rendu (Presentation\Anchor).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/ancre-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Modules\Expression;
use Fluffless\Suite\Presentation\Anchor;

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
$fluffless_assert( false !== has_filter( 'render_block', array( Anchor::class, 'render' ) ), 'filtre render_block absent' );
// Le module Expressivité est inactif par défaut (donc sur la CI) : ses blocs sont posés ici au besoin,
// retirés à la fin.
$fluffless_before = array_keys( $fluffless_registry->get_all_registered() );
if ( ! $fluffless_registry->is_registered( Expression::POPUP ) ) {
	Expression::register_block();
}

// 1. Tous les blocs fluffless/* qui déclarent le support anchor : l'ancre est posée une seule fois, sur
// la première balise utile. Les blocs dont le rendu reste vide sans attributs sont listés, pas comptés.
$fluffless_minimal = array(
	'fluffless/icone'     => array( 'icon' => 'leaf' ),
	'fluffless/graphique' => array(
		'rows' => array(
			array(
				'label' => 'A',
				'value' => 1,
			),
			array(
				'label' => 'B',
				'value' => 2,
			),
		),
	),
	'fluffless/bandeau'   => array( 'items' => array( 'Un', 'Deux' ) ),
	'fluffless/flottant'  => array( 'text' => 'Allo' ),
	'fluffless/video'     => array( 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ),
);
$fluffless_empty   = array();
$fluffless_covered = 0;
foreach ( $fluffless_registry->get_all_registered() as $fluffless_name => $fluffless_type ) {
	if ( ! str_starts_with( $fluffless_name, 'fluffless/' ) || ! block_has_support( $fluffless_type, 'anchor' ) ) {
		continue;
	}
	$fluffless_attrs = array_merge( $fluffless_minimal[ $fluffless_name ] ?? array(), array( 'anchor' => 'ancre-test' ) );
	$fluffless_inner = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
	$fluffless_html  = do_blocks( '<!-- wp:' . $fluffless_name . ' ' . wp_json_encode( $fluffless_attrs ) . ' -->' . $fluffless_inner . '<!-- /wp:' . $fluffless_name . ' -->' );
	if ( '' === trim( $fluffless_html ) || str_starts_with( trim( $fluffless_html ), '<!--' ) ) {
		$fluffless_empty[] = $fluffless_name;
		continue;
	}
	++$fluffless_covered;
	$fluffless_count = substr_count( $fluffless_html, 'id="ancre-test"' );
	$fluffless_assert( 1 === $fluffless_count, "{$fluffless_name} : id=\"ancre-test\" présent {$fluffless_count} fois : " . substr( $fluffless_html, 0, 200 ) );
	$fluffless_tags = new WP_HTML_Tag_Processor( $fluffless_html );
	$fluffless_tags->next_tag();
	$fluffless_assert( 'ancre-test' === $fluffless_tags->get_attribute( 'id' ), "{$fluffless_name} : l'id n'est pas sur la première balise : " . substr( $fluffless_html, 0, 200 ) );
}
$fluffless_assert( $fluffless_covered >= 20, "blocs avec ancre vérifiés : {$fluffless_covered} (20 attendus au moins)" );
if ( array() !== $fluffless_empty ) {
	WP_CLI::log( 'Rendu vide sans attributs, ancre non vérifiée : ' . implode( ', ', $fluffless_empty ) );
}

// 2. Règles : ancre vide ou avec espace ignorée ; id déjà présent gardé ; bloc du cœur ou sans support intact.
$fluffless_block = static fn( string $name, $anchor ): array => array(
	'blockName'   => $name,
	'attrs'       => array( 'anchor' => $anchor ),
	'innerHTML'   => '',
	'innerBlocks' => array(),
);
$fluffless_assert( '<div class="a">x</div>' === Anchor::render( '<div class="a">x</div>', $fluffless_block( 'fluffless/icone', '' ) ), 'ancre vide posée' );
$fluffless_assert( '<div>x</div>' === Anchor::render( '<div>x</div>', $fluffless_block( 'fluffless/icone', 'deux mots' ) ), 'ancre avec espace posée' );
$fluffless_assert( '<div id="réalisations-2026">x</div>' === Anchor::render( '<div>x</div>', $fluffless_block( 'fluffless/icone', ' réalisations-2026 ' ) ), 'ancre accentuée refusée (le cœur l\'accepte)' );
$fluffless_assert( '<div id="mien">x</div>' === Anchor::render( '<div id="mien">x</div>', $fluffless_block( 'fluffless/icone', 'autre' ) ), 'id existant écrasé' );
$fluffless_assert( '<div>x</div>' === Anchor::render( '<div>x</div>', $fluffless_block( 'core/group', 'a' ) ), 'bloc du cœur touché' );
$fluffless_assert( '<div>x</div>' === Anchor::render( '<div>x</div>', $fluffless_block( 'fluffless/inconnu', 'a' ) ), 'bloc non enregistré touché' );
$fluffless_assert( '<style>.a{}</style><section id="s">x</section>' === Anchor::render( '<style>.a{}</style><section>x</section>', $fluffless_block( 'fluffless/icone', 's' ) ), 'balise style prise pour l\'enveloppe' );
$fluffless_assert( '<div>x</div>' === Anchor::render( '<div>x</div>', 'pas un bloc' ), 'bloc non tableau' );
$fluffless_assert( '<div id="a&quot;&gt;">x</div>' === Anchor::render( '<div>x</div>', $fluffless_block( 'fluffless/icone', 'a">' ) ), 'ancre non échappée' );

// 3. Filtre fluffless_suite_anchor_target : l'id va sur la balise nommée.
$fluffless_target = static fn( $tag, array $block ) => 'fluffless/icone' === $block['blockName'] ? 'section' : $tag;
add_filter( 'fluffless_suite_anchor_target', $fluffless_target, 10, 2 );
$fluffless_assert( '<div><section id="s">x</section></div>' === Anchor::render( '<div><section>x</section></div>', $fluffless_block( 'fluffless/icone', 's' ) ), 'cible du filtre ignorée' );
$fluffless_assert( '<div><p>x</p></div>' === Anchor::render( '<div><p>x</p></div>', $fluffless_block( 'fluffless/icone', 's' ) ), 'cible absente : id posé ailleurs' );
remove_filter( 'fluffless_suite_anchor_target', $fluffless_target, 10 );

// 4. C2-4-01 : chaque bloc fluffless/* qui annonce l'ancre déclare lui-même l'attribut anchor. Sans
// cette déclaration, rien ne l'enregistre côté serveur avant WP 7.0 (et, en 7.x, rien pour un bloc
// enregistré après le passage du cœur) : le rendu de l'éditeur (block-renderer) refuse l'attribut.
$fluffless_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
foreach ( $fluffless_registry->get_all_registered() as $fluffless_name => $fluffless_type ) {
	if ( ! str_starts_with( $fluffless_name, 'fluffless/' ) || ! block_has_support( $fluffless_type, 'anchor' ) ) {
		continue;
	}
	$fluffless_assert( 'string' === ( $fluffless_type->attributes['anchor']['type'] ?? null ), "{$fluffless_name} : attribut anchor non déclaré" );
	if ( ! $fluffless_type->is_dynamic() ) {
		continue;
	}
	$fluffless_request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . $fluffless_name );
	$fluffless_request->set_param( 'context', 'edit' );
	$fluffless_request->set_param( 'attributes', array_merge( $fluffless_minimal[ $fluffless_name ] ?? array(), array( 'anchor' => 'ancre-rest' ) ) );
	$fluffless_response = rest_do_request( $fluffless_request );
	$fluffless_assert( 200 === $fluffless_response->get_status(), "{$fluffless_name} : block-renderer refuse l'ancre (" . $fluffless_response->get_status() . ')' );
}
wp_set_current_user( 0 );

foreach ( array_diff( array_keys( $fluffless_registry->get_all_registered() ), $fluffless_before ) as $fluffless_name ) {
	unregister_block_type( $fluffless_name );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Échecs ancre :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "ancre : {$fluffless_checks} vérifications passées ({$fluffless_covered} blocs)." );
