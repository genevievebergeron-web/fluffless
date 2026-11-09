<?php
/**
 * Test des pieds de page de la Suite : le menu partagé du thème (P4-01), un repli sans le thème,
 * et des exemples qui ne se publient pas tant qu'ils ne sont pas modifiés.
 *
 * Lancer (avec le thème) : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/pieds-test.php
 * Lancer (sans le thème) : ajouter --skip-themes avant eval-file.
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

$fluffless_dir   = dirname( __DIR__ ) . '/patterns/';
$fluffless_files = array( 'pied-de-page', 'pied-de-page-compact', 'pied-de-page-colonnes', 'pied-de-page-editorial' );
$fluffless_theme = function_exists( 'fluffless_footer_nav' );

$fluffless_assert( function_exists( 'fluffless_suite_footer_nav' ), 'fluffless_suite_footer_nav() existe' );
$fluffless_assert( function_exists( 'fluffless_suite_hide_example_content' ), 'le filtre de masquage de la Suite existe' );

foreach ( $fluffless_files as $fluffless_name ) {
	$fluffless_source = (string) file_get_contents( $fluffless_dir . $fluffless_name . '.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( ! str_contains( $fluffless_source, 'fluffless_nav_links' ), $fluffless_name . ' : plus d\'appel direct à fluffless_nav_links' );
	$fluffless_assert( str_contains( $fluffless_source, 'fluffless_suite_navigation_block(' ), $fluffless_name . ' : passe par fluffless_suite_navigation_block()' );
	$fluffless_assert( ! preg_match( '/is_admin|REST_REQUEST|fluffless_editing/', $fluffless_source ), $fluffless_name . ' : aucune garde d\'éditeur' );

	ob_start();
	include $fluffless_dir . $fluffless_name . '.php';
	$fluffless_markup = (string) ob_get_clean();
	// P4-01 : avec le thème, le menu partagé par ref ; sans le thème, les liens de repli.
	$fluffless_assert( $fluffless_theme && function_exists( 'fluffless_navigation_id' ) && fluffless_navigation_id() > 0 ? str_contains( $fluffless_markup, '<!-- wp:navigation {"ref":' . fluffless_navigation_id() . ',' ) : str_contains( $fluffless_markup, 'wp:navigation-link' ), $fluffless_name . ' : menu partagé du thème, sinon au moins un lien de navigation' );
	$fluffless_assert( str_contains( $fluffless_markup, 'fluffless-example' ), $fluffless_name . ' : exemples marqués dans le gabarit' );
	$fluffless_assert( ! str_contains( $fluffless_markup, 'href="#"' ), $fluffless_name . ' : aucun lien #' );
}

// P5-01 : les deux pieds qui portent le même slug que ceux du thème cèdent la place au thème
// quand le module Pied de page est éteint (sinon le © et le lien de confidentialité disparaissent).
foreach ( array( 'pied-de-page', 'pied-de-page-compact' ) as $fluffless_name ) {
	$fluffless_read = \Fluffless\Suite\Presentation\Patterns::read( $fluffless_dir . $fluffless_name . '.php' );
	$fluffless_assert( in_array( 'footer', (array) ( $fluffless_read['modules'] ?? array() ), true ), $fluffless_name . ' : en-tête « Fluffless Modules: footer »' );
	$fluffless_off = \Fluffless\Suite\Core\Loader::is_active( 'footer' ) ? array() : array( 'footer' );
	$fluffless_assert( ( array() === $fluffless_off ) === \Fluffless\Suite\Presentation\Patterns::modules_active( (array) $fluffless_read ), $fluffless_name . ' : proposé seulement quand Pied de page est actif' );
}

$fluffless_nav = fluffless_suite_footer_nav();
if ( $fluffless_theme ) {
	$fluffless_assert( fluffless_footer_nav() === $fluffless_nav, 'avec le thème : mêmes liens que le thème' );
} else {
	$fluffless_assert( 1 === substr_count( $fluffless_nav, 'wp:navigation-link' ), 'sans le thème : un seul lien' );
	$fluffless_assert( str_contains( $fluffless_nav, (string) wp_json_encode( home_url( '/' ), JSON_UNESCAPED_SLASHES ) ), 'sans le thème : le lien mène à l\'accueil' );
}

$fluffless_block = array( 'attrs' => array( 'className' => 'fluffless-example' ) );
$fluffless_assert( '' === fluffless_suite_hide_example_content( '<p class="fluffless-example">Your email address</p>', $fluffless_block ), 'exemple intact masqué' );
$fluffless_assert( '' === fluffless_suite_hide_example_content( '<p class="fluffless-example">Your&nbsp;phone number</p>', $fluffless_block ), 'exemple avec insécable masqué' );
$fluffless_kept = fluffless_suite_hide_example_content( '<p class="fluffless-example"><a href="mailto:a@exemple.ca">bonjour@client.ca</a></p>', $fluffless_block );
$fluffless_assert( str_contains( $fluffless_kept, 'bonjour@client.ca' ) && ! str_contains( $fluffless_kept, 'exemple.ca' ), 'texte modifié publié sans le lien factice' );
$fluffless_other = '<p>Your email address</p>';
$fluffless_assert( fluffless_suite_hide_example_content( $fluffless_other, array( 'attrs' => array() ) ) === $fluffless_other, 'paragraphe sans repère jamais touché' );

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( 'pieds : ' . count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} :\n - " . implode( "\n - ", $fluffless_failures ) );
}
WP_CLI::success( "pieds : {$fluffless_checks} vérifications passées." );
