<?php
/**
 * Test des espaces insécables dans le texte brut partagé (Presentation\Trail::plain) et dans la
 * description du module SEO : U+00A0 et U+202F restent, les autres blancs se replient en une espace.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/espace-insecable-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Modules\Seo;
use Fluffless\Suite\Presentation\Trail;

defined( 'ABSPATH' ) || exit;

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_nbsp  = "\u{00A0}";
$fluffless_nnbsp = "\u{202F}";

$fluffless_assert( "On décide en équipe{$fluffless_nbsp}: écrivez-nous." === Trail::plain( "On décide en équipe{$fluffless_nbsp}: écrivez-nous." ), 'U+00A0 avant le deux-points perdu' );
$fluffless_assert( "Prêts{$fluffless_nnbsp}?" === Trail::plain( "Prêts{$fluffless_nnbsp}?" ), 'U+202F avant le point d\'interrogation perdu' );
$fluffless_assert( "Contact{$fluffless_nbsp}: ici" === Trail::plain( '<p>Contact&nbsp;: ici</p>' ), '&nbsp; décodé puis perdu' );
$fluffless_assert( 'un deux trois' === Trail::plain( " un \t deux\n\n trois " ), 'blancs ordinaires non repliés' );
$fluffless_assert( 'à la' === Trail::plain( 'à la' ), 'caractère accentué abîmé' );
$fluffless_assert( 'Un Deux' === Trail::plain( "<p>Un</p>\n<p>&nbsp;</p>\n<p>Deux</p>" ), 'paragraphe &nbsp; : double espace' );
$fluffless_assert( 'Texte' === Trail::plain( '&nbsp;Texte&nbsp;' ), 'insécable laissée en bordure' );
$fluffless_assert( 'a b' === Trail::plain( 'a ' . str_repeat( $fluffless_nbsp, 20000 ) . ' b' ), 'long blanc mêlé : mal replié ou texte perdu' );

$fluffless_id = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Essai espace insécable',
	)
);
try {
	$fluffless_visit = static function ( string $description ) use ( $fluffless_id ): string {
		update_post_meta( $fluffless_id, Seo::META_DESCRIPTION, wp_slash( $description ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
		$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $fluffless_id ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
		$GLOBALS['post']         = $GLOBALS['wp_query']->post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
		Seo::reset();
		return Seo::current_description();
	};

	$fluffless_court = "Embauche en électromécanique. On décide en équipe{$fluffless_nbsp}: écrivez-nous.";
	$fluffless_assert( $fluffless_court === $fluffless_visit( $fluffless_court ), 'description courte : espace insécable perdue' );

	// Coupe à 160 caractères juste après « équipe : » : ni deux-points ni espace insécable avant « … ».
	$fluffless_long = str_repeat( 'mot ', 37 ) . "équipe{$fluffless_nbsp}: suite assez longue pour dépasser la limite.";
	$fluffless_cut  = $fluffless_visit( $fluffless_long );
	$fluffless_assert( mb_strlen( $fluffless_cut ) <= 160 && str_ends_with( $fluffless_cut, '…' ), 'description longue : coupe ou points de suspension manquants : ' . $fluffless_cut );
	$fluffless_assert( 1 !== preg_match( '/[\s:]…$/u', $fluffless_cut ), 'description longue : blanc ou deux-points avant « … » : ' . $fluffless_cut );
	$fluffless_assert( mb_check_encoding( $fluffless_cut, 'UTF-8' ), 'description longue : UTF-8 abîmé' );
} finally {
	wp_delete_post( (int) $fluffless_id, true );
	wp_reset_postdata();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Espaces insécables : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Espaces insécables : {$fluffless_checks} vérifications passées." );
