<?php
/**
 * Tests du bloc Récit défilant (module Expressivité) : attributs bornés, enveloppe du contenu,
 * CSS (collant, animation sous @supports, rien de caché hors no-preference), script de repli
 * sous 1,2 ko chargé seulement si un récit animé est rendu.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/recit-test.php
 * L'enregistrement du bloc est remis à son état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Modules\Expression;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! defined( Expression::class . '::RECIT' ) ) {
	throw new RuntimeException( 'Récit défilant absent du module Expressivité.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_registry   = WP_Block_Type_Registry::get_instance();
$fluffless_registered = $fluffless_registry->is_registered( Expression::RECIT );
$fluffless_had_vague  = $fluffless_registry->is_registered( Expression::VAGUE );

try {
	// 1. Attributs : défauts et listes fermées.
	$fluffless_a = Expression::recit_attributes( array() );
	$fluffless_assert( 'start' === $fluffless_a['scene'], 'scène par défaut : start' );
	$fluffless_assert( 'rise' === $fluffless_a['effect'], 'effet par défaut : rise' );
	$fluffless_a = Expression::recit_attributes(
		array(
			'scene'     => 'milieu',
			'effect'    => 'explosion',
			'align'     => 'gauche',
			'className' => 'ok "><script>',
		)
	);
	$fluffless_assert( 'start' === $fluffless_a['scene'], 'scène hors liste : retour au défaut' );
	$fluffless_assert( 'rise' === $fluffless_a['effect'], 'effet hors liste : retour au défaut' );
	$fluffless_assert( '' === $fluffless_a['align'], 'alignement hors liste refusé' );
	$fluffless_assert( ! str_contains( $fluffless_a['className'], '<' ), 'classe non assainie' );
	foreach ( Expression::RECIT_EFFECTS as $fluffless_effect ) {
		$fluffless_kept = Expression::recit_attributes( array( 'effect' => $fluffless_effect ) )['effect'];
		$fluffless_assert( $fluffless_kept === $fluffless_effect, "effet {$fluffless_effect} refusé" );
	}

	// 2. Rendu : le contenu enregistré est enveloppé tel quel, jamais réécrit.
	$fluffless_inner = '<div class="wp-block-group">Scène</div><div class="wp-block-group"><p>Un</p><p>Deux</p></div>';
	$fluffless_html  = Expression::render_recit(
		array(
			'scene'  => 'end',
			'effect' => 'fade',
			'align'  => 'wide',
		),
		$fluffless_inner
	);
	$fluffless_assert( str_contains( $fluffless_html, $fluffless_inner ), 'contenu du récit modifié au rendu' );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-recit' ) && str_contains( $fluffless_html, 'is-scene-end' ), 'classes de scène absentes' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-effect-fade' ) && str_contains( $fluffless_html, 'alignwide' ), 'classes d\'effet ou d\'alignement absentes' );
	$fluffless_assert( ! str_contains( $fluffless_html, 'hidden' ) && ! str_contains( $fluffless_html, 'opacity' ), 'le HTML ne doit rien cacher : seul le CSS anime' );
	$fluffless_assert( '' !== Expression::render_recit( 'x', 'y' ), 'attributs non tableau : rendu par défaut attendu' );

	// 3. CSS : collant, animation native sous @supports, rien de caché en mouvement réduit.
	$fluffless_css = Expression::recit_css();
	$fluffless_assert( strlen( $fluffless_css ) < 1536, 'CSS du récit au-delà de 1,5 ko (' . strlen( $fluffless_css ) . ' o)' );
	$fluffless_assert( str_contains( $fluffless_css, 'position:sticky' ), 'scène non collante' );
	$fluffless_assert( str_contains( $fluffless_css, '@supports (animation-timeline:view())' ), 'animation liée au défilement hors @supports' );
	$fluffless_motion = strpos( $fluffless_css, 'prefers-reduced-motion:no-preference' );
	$fluffless_hidden = strpos( $fluffless_css, 'opacity:0' );
	$fluffless_assert( false !== $fluffless_motion, 'aucune garde prefers-reduced-motion' );
	$fluffless_assert( false === $fluffless_hidden || ( false !== $fluffless_motion && $fluffless_hidden > $fluffless_motion ), 'opacity:0 hors de la garde no-preference : contenu caché en mouvement réduit' );
	$fluffless_assert( ! str_contains( Expression::recit_layout_css(), 'animation' ), 'l\'éditeur ne doit recevoir que la mise en page, sans animation' );

	// 4. Script de repli : court, sort tôt si le CSS natif suffit ou en mouvement réduit.
	$fluffless_js = Expression::recit_js();
	$fluffless_assert( strlen( $fluffless_js ) <= 1229, 'script de repli au-delà de 1,2 ko (' . strlen( $fluffless_js ) . ' o)' );
	$fluffless_assert( str_contains( $fluffless_js, 'animation-timeline' ), 'le repli doit sortir si animation-timeline est pris en charge' );
	$fluffless_assert( str_contains( $fluffless_js, 'prefers-reduced-motion' ), 'le repli doit sortir en mouvement réduit' );
	$fluffless_assert( str_contains( $fluffless_js, 'IntersectionObserver' ), 'le repli doit passer par IntersectionObserver' );

	// 5. Chargement : script seulement pour un récit animé ; CSS pour tout récit.
	foreach ( array( Expression::STYLE_RECIT ) as $fluffless_h ) {
		wp_dequeue_style( $fluffless_h );
		wp_deregister_style( $fluffless_h );
	}
	wp_dequeue_script( Expression::SCRIPT_RECIT );
	wp_deregister_script( Expression::SCRIPT_RECIT );
	Expression::render_recit( array( 'effect' => 'none' ), '' );
	$fluffless_assert( wp_style_is( Expression::STYLE_RECIT, 'enqueued' ), 'CSS non chargé après un rendu' );
	$fluffless_assert( ! wp_script_is( Expression::SCRIPT_RECIT, 'enqueued' ), 'script chargé pour un récit sans effet' );
	Expression::render_recit( array( 'effect' => 'rise' ), '' );
	$fluffless_assert( wp_script_is( Expression::SCRIPT_RECIT, 'enqueued' ), 'script de repli non chargé pour un récit animé' );

	// 6. Bloc réel : enregistré depuis block.json, rendu par do_blocks().
	if ( ! $fluffless_registered ) {
		Expression::register_block();
	}
	$fluffless_assert( $fluffless_registry->is_registered( Expression::RECIT ), 'bloc fluffless/recit non enregistré' );
	$fluffless_html = do_blocks( '<!-- wp:fluffless/recit {"scene":"end"} --><!-- wp:paragraph --><p>Étape</p><!-- /wp:paragraph --><!-- /wp:fluffless/recit -->' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-scene-end' ) && str_contains( $fluffless_html, 'Étape</p>' ), 'do_blocks ne passe pas par le rendu du module' );
} finally {
	if ( ! $fluffless_registered && $fluffless_registry->is_registered( Expression::RECIT ) ) {
		unregister_block_type( Expression::RECIT );
	}
	if ( ! $fluffless_had_vague && $fluffless_registry->is_registered( Expression::VAGUE ) ) {
		unregister_block_type( Expression::VAGUE );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Récit : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Récit : {$fluffless_checks} vérifications passées." );
