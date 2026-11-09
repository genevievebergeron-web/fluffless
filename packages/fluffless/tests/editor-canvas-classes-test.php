<?php
/**
 * Tests du support de thème fluffless-editor-canvas-classes : lecture et nettoyage de la liste,
 * script chargé seulement en administration et seulement avec des classes.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/editor-canvas-classes-test.php
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
$fluffless_handle   = 'fluffless-editor-canvas-classes';
$fluffless_reset    = static function () use ( $fluffless_handle ): void {
	remove_theme_support( 'fluffless-editor-canvas-classes' );
	wp_dequeue_script( $fluffless_handle );
	wp_deregister_script( $fluffless_handle );
};

try {
	// Sans support : liste vide.
	$fluffless_reset();
	$fluffless_assert( array() === fluffless_editor_canvas_classes(), 'sans support : liste vide' );

	// Nettoyage : sanitize_html_class, vides et doublons retirés, non-chaînes ignorées, 20 au plus.
	add_theme_support( 'fluffless-editor-canvas-classes', array( 'rx', ' rx-page ', 'Bad Class!', '', 'rx', 7, null ) );
	$fluffless_assert( array( 'rx', 'rx-page', 'BadClass' ) === fluffless_editor_canvas_classes(), 'nettoyage : ' . implode( ',', fluffless_editor_canvas_classes() ) );
	$fluffless_reset();
	add_theme_support( 'fluffless-editor-canvas-classes', array_map( static fn( int $i ): string => 'c' . $i, range( 1, 25 ) ) );
	$fluffless_assert( 20 === count( fluffless_editor_canvas_classes() ), 'plafond de 20 classes' );
	$fluffless_reset();

	// Support sans tableau, ou tableau vide : rien.
	add_theme_support( 'fluffless-editor-canvas-classes' );
	$fluffless_assert( array() === fluffless_editor_canvas_classes(), 'support sans liste : vide' );
	$fluffless_reset();

	// Fichier livré.
	$fluffless_assert( is_readable( get_template_directory() . '/assets/js/editor-canvas-classes.js' ), 'script présent dans le thème' );
	$fluffless_assert( false !== has_action( 'enqueue_block_assets', 'fluffless_enqueue_editor_canvas_classes' ), 'branché sur enqueue_block_assets' );

	// Hors administration (WP-CLI) : rien, même avec des classes.
	add_theme_support( 'fluffless-editor-canvas-classes', array( 'rx' ) );
	fluffless_enqueue_editor_canvas_classes();
	$fluffless_assert( ! wp_script_is( $fluffless_handle, 'enqueued' ), 'site public : script non chargé' );

	// Administration simulée : script chargé avec la liste en ligne, avant.
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( 'post' );
	$fluffless_assert( is_admin(), 'écran d’administration simulé' );
	fluffless_enqueue_editor_canvas_classes();
	$fluffless_assert( wp_script_is( $fluffless_handle, 'enqueued' ), 'administration : script chargé' );
	$fluffless_before = wp_scripts()->get_data( $fluffless_handle, 'before' );
	$fluffless_assert( is_array( $fluffless_before ) && str_contains( implode( "\n", $fluffless_before ), 'window.flufflessEditorCanvasClasses = ["rx"];' ), 'liste passée en ligne avant le script' );
	$fluffless_src = wp_scripts()->registered[ $fluffless_handle ]->src ?? '';
	$fluffless_assert( str_ends_with( (string) $fluffless_src, '/fluffless/assets/js/editor-canvas-classes.js' ), 'adresse du script : ' . $fluffless_src );
	$fluffless_reset();

	// Administration sans classes : rien.
	fluffless_enqueue_editor_canvas_classes();
	$fluffless_assert( ! wp_script_is( $fluffless_handle, 'enqueued' ), 'administration sans support : rien' );
} finally {
	$fluffless_reset();
	$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( 'ÉCHEC : ' . $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'editor-canvas-classes : ' . $fluffless_checks . ' vérifications passées.' );
