<?php
/**
 * Feuilles de styles de blocs fournies par un thème (assets/block-styles/<slug>.css du thème
 * enfant ou parent) : trouvées, chargées au rendu pour un style enregistré, url() relatives
 * résolues, commentaires gardés, remplacement d'une feuille de Suite, éditeur. Le thème actif
 * sert de « thème enfant » : les fichiers sont créés puis retirés.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/styles-theme-sheet-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Presentation\BlockStyles;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( BlockStyles::class ) ) {
	throw new RuntimeException( 'Fluffless Suite inactive.' );
}
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

// Faux thème enfant dans les téléversements : le dossier du thème n'est pas inscriptible par le conteneur de la CI.
$fluffless_uploads = wp_upload_dir();
$fluffless_theme   = $fluffless_uploads['basedir'] . '/fl-test-theme';
$fluffless_theme_u = $fluffless_uploads['baseurl'] . '/fl-test-theme';
add_filter( 'stylesheet_directory', static fn(): string => $fluffless_theme );
add_filter( 'stylesheet_directory_uri', static fn(): string => $fluffless_theme_u );

$fluffless_dir      = $fluffless_theme . '/assets/block-styles';
$fluffless_url      = $fluffless_theme_u . '/assets/block-styles';
$fluffless_made_dir = ! is_dir( $fluffless_dir );
$fluffless_slug     = 'test-feuille-theme';
$fluffless_orphan   = 'test-feuille-orpheline';
$fluffless_files    = array(
	$fluffless_slug . '.css'   => "/* commentaire gardé */\n.is-style-{$fluffless_slug} { background: url(../images/motif.svg); }\n",
	'inverse.css'              => ".is-style-inverse { color: red; }\n",
	$fluffless_orphan . '.css' => ".is-style-{$fluffless_orphan} { color: blue; }\n",
	'Mauvais Slug.css'         => 'x',
);
$fluffless_handles  = array( 'fluffless-suite-style-' . $fluffless_slug, 'fluffless-suite-style-inverse', 'fluffless-suite-style-' . $fluffless_orphan );
$fluffless_cleanup  = static function () use ( $fluffless_dir, $fluffless_theme, $fluffless_made_dir, $fluffless_files, $fluffless_handles, $fluffless_slug ): void {
	foreach ( array_keys( $fluffless_files ) as $fluffless_name ) {
		if ( file_exists( $fluffless_dir . '/' . $fluffless_name ) ) {
			unlink( $fluffless_dir . '/' . $fluffless_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fichier de test créé plus haut.
		}
	}
	if ( $fluffless_made_dir && is_dir( $fluffless_dir ) ) {
		rmdir( $fluffless_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier de test créé plus haut.
		rmdir( dirname( $fluffless_dir ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier de test créé plus haut.
		rmdir( $fluffless_theme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier de test créé plus haut.
	}
	unregister_block_style( 'core/paragraph', $fluffless_slug );
	foreach ( $fluffless_handles as $fluffless_handle ) {
		wp_dequeue_style( $fluffless_handle );
		wp_deregister_style( $fluffless_handle );
	}
	BlockStyles::flush();
	$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
};

try {
	// Sans fichier dans le thème : rien de trouvé, les chemins de Suite restent ceux de Suite.
	BlockStyles::flush();
	$fluffless_assert( ! isset( BlockStyles::theme_sheets()[ $fluffless_slug ] ), 'sans fichier : slug absent' );
	$fluffless_assert( BlockStyles::resolved_path( 'inverse' ) === BlockStyles::css_path( 'inverse' ), 'sans fichier : la feuille de Suite est retenue' );
	$fluffless_assert( str_contains( BlockStyles::css_path( 'inverse' ), '/fluffless-suite/assets/block-styles/inverse.css' ), 'css_path() reste le chemin de Suite' );

	if ( $fluffless_made_dir && ! mkdir( $fluffless_dir, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- dossier de test, hors du flux WordPress.
		throw new RuntimeException( 'Impossible de créer ' . $fluffless_dir );
	}
	foreach ( $fluffless_files as $fluffless_name => $fluffless_rules ) {
		file_put_contents( $fluffless_dir . '/' . $fluffless_name, $fluffless_rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	BlockStyles::flush();
	register_block_style(
		'core/paragraph',
		array(
			'name'  => $fluffless_slug,
			'label' => 'Feuille de test',
		)
	);

	// Découverte : fichiers du thème, slug invalide ignoré.
	$fluffless_sheets = BlockStyles::theme_sheets();
	$fluffless_assert( isset( $fluffless_sheets[ $fluffless_slug ], $fluffless_sheets['inverse'], $fluffless_sheets[ $fluffless_orphan ] ), 'feuilles du thème trouvées : ' . implode( ',', array_keys( $fluffless_sheets ) ) );
	$fluffless_assert( ! isset( $fluffless_sheets['Mauvais Slug'] ), 'nom de fichier qui n’est pas un slug : ignoré' );
	$fluffless_assert( BlockStyles::resolved_path( $fluffless_slug ) === $fluffless_dir . '/' . $fluffless_slug . '.css', 'resolved_path() : fichier du thème' );
	$fluffless_assert( str_contains( BlockStyles::css_path( $fluffless_slug ), '/fluffless-suite/' ), 'css_path() inchangé pour un slug du thème' );
	$fluffless_assert( BlockStyles::has_sheet( $fluffless_slug ), 'has_sheet() vrai pour une feuille du thème' );

	// Contenu : commentaires gardés, url() relative résolue par rapport au fichier.
	$fluffless_css = BlockStyles::sheet( $fluffless_slug );
	$fluffless_assert( str_contains( $fluffless_css, 'commentaire gardé' ), 'feuille de thème : commentaires gardés' );
	// WordPress rend l'adresse relative à la racine du site, « ../ » compris (comme pour theme.json).
	$fluffless_assert( str_contains( $fluffless_css, 'fl-test-theme/assets/block-styles/../images/motif.svg' ) && ! str_contains( $fluffless_css, 'url(../' ), 'url() relative résolue par rapport au fichier du thème : ' . $fluffless_css );

	// Remplacement d'une feuille de Suite par le thème.
	$fluffless_assert( BlockStyles::resolved_path( 'inverse' ) === $fluffless_dir . '/inverse.css', 'inverse : feuille du thème retenue' );
	$fluffless_assert( str_contains( BlockStyles::sheet( 'inverse' ), 'color: red' ), 'inverse : contenu du thème' );

	// Rendu : un bloc qui porte le style charge la feuille, en ligne.
	$fluffless_html = do_blocks( '<!-- wp:paragraph {"className":"is-style-' . $fluffless_slug . '"} --><p class="is-style-' . $fluffless_slug . '">x</p><!-- /wp:paragraph -->' );
	$fluffless_assert( wp_style_is( $fluffless_handles[0], 'enqueued' ), 'style du thème rendu : feuille chargée' );
	$fluffless_inline = wp_styles()->get_data( $fluffless_handles[0], 'after' );
	$fluffless_assert( is_array( $fluffless_inline ) && str_contains( implode( "\n", $fluffless_inline ), 'fl-test-theme/assets/block-styles/../images/motif.svg' ), 'feuille en ligne avec l’adresse résolue' );

	// Un fichier sans style enregistré ne charge rien, même avec la classe.
	do_blocks( '<!-- wp:paragraph {"className":"is-style-' . $fluffless_orphan . '"} --><p class="is-style-' . $fluffless_orphan . '">x</p><!-- /wp:paragraph -->' );
	$fluffless_assert( ! wp_style_is( $fluffless_handles[2], 'enqueued' ), 'feuille sans style enregistré : rien' );

	// Style de Suite remplacé : le rendu charge le contenu du thème.
	do_blocks( '<!-- wp:buttons --><!-- wp:button {"className":"is-style-inverse"} --><div class="wp-block-button is-style-inverse"><a class="wp-block-button__link">x</a></div><!-- /wp:button --><!-- /wp:buttons -->' );
	$fluffless_inline = wp_styles()->get_data( $fluffless_handles[1], 'after' );
	$fluffless_assert( is_array( $fluffless_inline ) && str_contains( implode( "\n", $fluffless_inline ), 'color: red' ), 'inverse rendu : feuille du thème en ligne' );

	// Éditeur : adresse du thème, version = date du fichier.
	foreach ( $fluffless_handles as $fluffless_handle ) {
		wp_dequeue_style( $fluffless_handle );
		wp_deregister_style( $fluffless_handle );
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( 'post' );
	BlockStyles::editor_assets();
	$fluffless_src = (string) ( wp_styles()->registered[ $fluffless_handles[0] ]->src ?? '' );
	$fluffless_ver = (string) ( wp_styles()->registered[ $fluffless_handles[0] ]->ver ?? '' );
	$fluffless_assert( wp_style_is( $fluffless_handles[0], 'enqueued' ) && $fluffless_url . '/' . $fluffless_slug . '.css' === $fluffless_src, 'éditeur : feuille du thème par son adresse : ' . $fluffless_src );
	$fluffless_assert( 1 === preg_match( '/^\d{9,}$/', $fluffless_ver ), 'éditeur : version = date du fichier : ' . $fluffless_ver );
	$fluffless_src = (string) ( wp_styles()->registered[ $fluffless_handles[1] ]->src ?? '' );
	$fluffless_assert( $fluffless_url . '/inverse.css' === $fluffless_src, 'éditeur : inverse remplacé par l’adresse du thème : ' . $fluffless_src );
	$fluffless_assert( ! wp_style_is( $fluffless_handles[2], 'enqueued' ), 'éditeur : feuille sans style enregistré : rien' );
} finally {
	$fluffless_cleanup();
}

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( "Feuilles de thème :\n- " . implode( "\n- ", $fluffless_failures ) );
}
WP_CLI::success( "Feuilles de thème, {$fluffless_checks} vérifications réussies." );
