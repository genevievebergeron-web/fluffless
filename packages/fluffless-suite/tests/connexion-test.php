<?php
/**
 * Tests de la page de connexion aux couleurs du site : branchement, variables, feuille, logo, titre.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/connexion-test.php
 * Le logo posé pour le test est retiré à la fin, avec le fichier joint créé.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Presentation\LoginStyle;

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

$fluffless_logo_before = get_theme_mod( 'custom_logo' );
$fluffless_attachment  = 0;

try {
	if ( ! LoginStyle::applies() ) {
		throw new RuntimeException( 'Activer le thème Fluffless pour ce test.' );
	}

	// 1. Branchement.
	$fluffless_assert( 10 === has_action( 'login_enqueue_scripts', array( LoginStyle::class, 'enqueue' ) ), 'Feuille posée sur login_enqueue_scripts.' );
	$fluffless_assert( 50 === has_action( 'login_head', array( LoginStyle::class, 'font_faces' ) ), 'Polices du site imprimées sur login_head.' );
	$fluffless_assert( 10 === has_filter( 'login_headerurl', array( LoginStyle::class, 'header_url' ) ), 'Adresse du titre filtrée.' );
	$fluffless_assert( 10 === has_filter( 'login_headertext', array( LoginStyle::class, 'header_text' ) ), 'Texte du titre filtré.' );
	$fluffless_assert( home_url( '/' ) === LoginStyle::header_url( 'https://wordpress.org/' ), 'Le titre mène à l’accueil.' );
	$fluffless_assert( get_bloginfo( 'name', 'display' ) === LoginStyle::header_text( 'WordPress' ), 'Le titre porte le nom du site.' );

	// 2. Feuille : aucune couleur fixe, seulement des variables du thème, aucun script.
	$fluffless_file = WP_PLUGIN_DIR . '/fluffless-suite/assets/login.css';
	$fluffless_css  = (string) file_get_contents( $fluffless_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( '' !== $fluffless_css, 'assets/login.css présent.' );
	$fluffless_assert( 0 === preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_css ), 'Aucune couleur HEX dans la feuille.' );
	$fluffless_assert( 0 === preg_match( '/\b(rgb|hsl)a?\(/', $fluffless_css ), 'Aucune couleur rgb ou hsl dans la feuille.' );
	foreach ( array( 'base', 'surface', 'contrast', 'muted', 'divider', 'accent', 'on-accent', 'accent-2', 'alert' ) as $fluffless_slug ) {
		$fluffless_assert( str_contains( $fluffless_css, '--wp--preset--color--' . $fluffless_slug . ')' ), 'Rôle de palette utilisé : ' . $fluffless_slug );
	}
	$fluffless_assert( str_contains( $fluffless_css, '--wp--preset--color--button-bg, var(--wp--preset--color--accent)' ), 'Bouton : même recette que theme.json (button-bg puis accent).' );
	$fluffless_assert( str_contains( $fluffless_css, '--wp--custom--radius--soft' ), 'Rayon du thème.' );
	$fluffless_assert( ! str_contains( $fluffless_css, '<script' ) && ! str_contains( $fluffless_css, 'url(' ), 'Ni script ni ressource externe dans la feuille.' );
	$fluffless_assert( strlen( $fluffless_css ) < 8192, 'Feuille sous 8 ko.' );

	// 3. Variables : couleurs, polices, tailles et valeurs sur mesure des styles globaux, rien d'autre.
	$fluffless_vars = LoginStyle::variables();
	$fluffless_assert( str_starts_with( $fluffless_vars, ':root{' ), 'Variables posées sur :root.' );
	$fluffless_assert( str_contains( $fluffless_vars, '--wp--preset--color--base:' ) && str_contains( $fluffless_vars, '--wp--preset--color--accent:' ), 'Couleurs du site présentes.' );
	$fluffless_assert( str_contains( $fluffless_vars, '--wp--preset--font-family--sans:' ), 'Familles de police présentes.' );
	$fluffless_assert( str_contains( $fluffless_vars, '--wp--preset--font-size--large:' ), 'Tailles de police présentes.' );
	$fluffless_assert( str_contains( $fluffless_vars, '--wp--custom--radius--soft:' ) && str_contains( $fluffless_vars, '--wp--custom--line-height--snug:' ), 'Valeurs sur mesure présentes.' );
	$fluffless_assert( ! str_contains( $fluffless_vars, '--wp--preset--spacing--' ) && ! str_contains( $fluffless_vars, '--wp--preset--aspect-ratio--' ) && ! str_contains( $fluffless_vars, '--wp--preset--shadow--' ), 'Espacements, rapports et ombres écartés.' );
	$fluffless_assert( 1 === preg_match( '/body\.login\{font-family:var\(--wp--preset--font-family--[a-z0-9-]+\)\}$/', $fluffless_vars ), 'Police du corps du site posée sur la page.' );
	$fluffless_assert( strlen( $fluffless_vars ) < 4096, 'Variables sous 4 ko.' );

	// 4. Feuille complète et branchement par wp_add_inline_style sur la feuille du cœur.
	$fluffless_full = LoginStyle::css();
	$fluffless_assert( str_starts_with( $fluffless_full, $fluffless_vars ) && str_contains( $fluffless_full, 'body.login {' ), 'Variables puis règles.' );
	wp_register_style( 'login', admin_url( 'css/login.css' ), array(), 'test' );
	LoginStyle::enqueue();
	$fluffless_after = wp_styles()->get_data( 'login', 'after' );
	$fluffless_assert( is_array( $fluffless_after ) && in_array( $fluffless_full, $fluffless_after, true ), 'Feuille ajoutée en ligne après login.css du cœur.' );
	add_filter( 'fluffless_login_styles', '__return_false' );
	$fluffless_count = is_array( $fluffless_after ) ? count( $fluffless_after ) : 0;
	LoginStyle::enqueue();
	$fluffless_after = wp_styles()->get_data( 'login', 'after' );
	$fluffless_now   = is_array( $fluffless_after ) ? count( $fluffless_after ) : 0;
	$fluffless_assert( $fluffless_count === $fluffless_now, 'Filtre à faux : rien d’ajouté.' );
	$fluffless_assert( 'WordPress' === LoginStyle::header_text( 'WordPress' ), 'Filtre à faux : titre du cœur gardé.' );
	remove_filter( 'fluffless_login_styles', '__return_false' );

	// 5. Logo : rien sans logo ni icône ; le logo du site prend la place du nom.
	remove_theme_mod( 'custom_logo' );
	$fluffless_icon     = get_option( 'site_icon' );
	$fluffless_logo_css = LoginStyle::logo_css();
	$fluffless_assert( $fluffless_icon ? '' !== $fluffless_logo_css : '' === $fluffless_logo_css, 'Sans logo : icône du site si réglée, sinon rien.' );
	$fluffless_attachment = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Logo de test Fluffless',
			'post_status'    => 'inherit',
		),
		get_template_directory() . '/screenshot.png'
	);
	$fluffless_attachment = (int) $fluffless_attachment;
	$fluffless_assert( $fluffless_attachment > 0, 'Fichier joint de test créé.' );
	set_theme_mod( 'custom_logo', $fluffless_attachment );
	$fluffless_logo = LoginStyle::logo_css();
	$fluffless_assert( str_starts_with( $fluffless_logo, '.login h1 a{' ) && str_contains( $fluffless_logo, 'screenshot.png' ) && str_contains( $fluffless_logo, 'text-indent:-9999px' ), 'Logo posé en fond du titre, nom masqué.' );
	$fluffless_assert( str_contains( LoginStyle::css(), $fluffless_logo ), 'Le logo termine la feuille complète.' );
	$fluffless_assert( ! str_contains( $fluffless_logo, '<' ), 'Adresse échappée.' );
} finally {
	if ( $fluffless_attachment > 0 ) {
		wp_delete_attachment( $fluffless_attachment, true );
	}
	if ( false === $fluffless_logo_before || '' === $fluffless_logo_before ) {
		remove_theme_mod( 'custom_logo' );
	} else {
		set_theme_mod( 'custom_logo', $fluffless_logo_before );
	}
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Page de connexion : ' . $fluffless_checks . ' vérifications passées.' );
