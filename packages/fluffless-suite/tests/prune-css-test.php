<?php
/**
 * Tests du module Élagage du CSS en ligne.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/prune-css-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Modules\PruneCss;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( PruneCss::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_same     = static function ( string $name, string $got, string $expected ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( $got !== $expected ) {
		$fluffless_failures[] = "$name\n  attendu : $expected\n  obtenu  : $got";
	}
};

// La page n'utilise que ces classes (guillemets doubles, simples et valeur sans guillemets).
PruneCss::reset();
PruneCss::collect( '<p class="has-noir-color x"></p><div class=\'has-papier-background-color\'></div><span class=has-sapin-color>' );
$fluffless_classes = PruneCss::classes();
$fluffless_same( 'classes : guillemets simples', isset( $fluffless_classes['has-papier-background-color'] ) ? 'vu' : 'absent', 'vu' );
$fluffless_same( 'classes : sans guillemets', isset( $fluffless_classes['has-sapin-color'] ) ? 'vu' : 'absent', 'vu' );

$fluffless_prune = static fn( string $css ): string => PruneCss::prune( $css, array( PruneCss::class, 'keep_preset' ) );

$fluffless_same( 'préréglage inutilisé retiré', $fluffless_prune( '.has-alert-color{color:red}.has-noir-color{color:#000}' ), '.has-noir-color{color:#000}' );
$fluffless_same( ':root préfixé retiré', $fluffless_prune( ':root .has-alert-background-color{background:red}' ), '' );
$fluffless_same( 'préréglage de bloc retiré', $fluffless_prune( ':where(.wp-block-group).has-alert-color{color:red}:where(.wp-block-group).has-noir-color{a:b}' ), ':where(.wp-block-group).has-noir-color{a:b}' );
$fluffless_same( ':where de bloc avec :not gardé', $fluffless_prune( ':where(.wp-block-group):not(.has-alert-color){a:b}' ), ':where(.wp-block-group):not(.has-alert-color){a:b}' );
$fluffless_same( ':not( gardé', $fluffless_prune( '.wp-block-button__link:not(.has-text-color){color:red}' ), '.wp-block-button__link:not(.has-text-color){color:red}' );
$fluffless_same( 'sélecteur composé gardé', $fluffless_prune( '.wp-block-x .has-alert-color{color:red}' ), '.wp-block-x .has-alert-color{color:red}' );
$fluffless_same( 'accolade dans une chaîne', $fluffless_prune( '.a::before{content:"}"}.has-alert-color{color:red}.garde{color:green}' ), '.a::before{content:"}"}.garde{color:green}' );
$fluffless_same( 'parenthèse dans un attribut', $fluffless_prune( '.a[data-x=")"],.has-alert-color{color:red}' ), '.a[data-x=")"]{color:red}' );
$fluffless_same( 'commentaire', $fluffless_prune( '/* { */.garde{color:green}.has-alert-color{color:red}' ), '/* { */.garde{color:green}' );
$fluffless_same( 'échappement', $fluffless_prune( ".a::after{content:'\\'}'}.garde{x:1}" ), ".a::after{content:'\\'}'}.garde{x:1}" );
$fluffless_same( 'dans @media', $fluffless_prune( '@media (min-width:1px){.has-alert-color{a:b}.garde{a:b}}' ), '@media (min-width:1px){.garde{a:b}}' );

// Visionneuse : ses règles et ses animations partent, le reste du bloc Image reste.
$fluffless_same(
	'visionneuse retirée',
	PruneCss::prune( '.wp-block-image img{a:b}.wp-lightbox-overlay{c:d}@keyframes turn-on-visibility{0%{e:f}}@keyframes autre{0%{g:h}}', array( PruneCss::class, 'keep_without_lightbox' ) ),
	'.wp-block-image img{a:b}@keyframes autre{0%{g:h}}'
);

// apply() : rien d'inventorié, rien ne change ; sinon global-styles est élagué et la visionneuse retirée.
$fluffless_styles = wp_styles();
foreach ( array( 'global-styles', 'wp-block-image' ) as $fluffless_handle ) {
	if ( ! isset( $fluffless_styles->registered[ $fluffless_handle ] ) ) {
		$fluffless_styles->add( $fluffless_handle, false );
	}
}
$fluffless_styles->registered['global-styles']->extra['after']  = array( '.has-alert-color{a:b}.has-noir-color{a:b}' );
$fluffless_styles->registered['wp-block-image']->extra['after'] = array( '.wp-lightbox-overlay{c:d}.x{y:z}' );
PruneCss::reset();
PruneCss::apply();
$fluffless_same( 'apply sans inventaire : intact', $fluffless_styles->registered['global-styles']->extra['after'][0], '.has-alert-color{a:b}.has-noir-color{a:b}' );
PruneCss::collect( '<p class="has-noir-color"></p>' );
PruneCss::apply();
$fluffless_same( 'apply : préréglage inutilisé retiré', $fluffless_styles->registered['global-styles']->extra['after'][0], '.has-noir-color{a:b}' );
$fluffless_same( 'apply : visionneuse retirée sans image qui l\'active', $fluffless_styles->registered['wp-block-image']->extra['after'][0], '.x{y:z}' );
$fluffless_styles->registered['wp-block-image']->extra['after'] = array( '.wp-lightbox-overlay{c:d}' );
PruneCss::collect( '<figure class="wp-lightbox-container"></figure>' );
PruneCss::apply();
$fluffless_same( 'apply : visionneuse gardée quand une image l\'active', $fluffless_styles->registered['wp-block-image']->extra['after'][0], '.wp-lightbox-overlay{c:d}' );
PruneCss::reset();

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Élagage du CSS en ligne, {$fluffless_checks} vérifications réussies.\n" );
