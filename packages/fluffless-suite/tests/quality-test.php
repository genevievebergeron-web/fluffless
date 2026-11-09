<?php
/**
 * Tests du module Qualité du contenu (côté serveur : gabarits qui montrent le titre en H1).
 * Les règles du panneau se testent sous Node : tests/quality-core-test.mjs.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/quality-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Quality;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Quality::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};
$fluffless_page     = 0;
$fluffless_post     = 0;

try {
	// Contrat du module.
	$fluffless_assert( 'quality' === Quality::id(), 'id' );
	$fluffless_assert( Loader::is_active( 'quality' ), 'module actif par défaut' );
	$fluffless_assert( array() === Quality::defaults() && array() === Quality::fields() && array() === Quality::sanitize( array( 'x' => 1 ) ), 'aucun réglage' );

	// Titre H1 trouvé dans les blocs, imbriqué ou non ; le niveau absent vaut 2.
	$fluffless_h1 = static fn( string $html ): bool => Quality::shows_title_h1( parse_blocks( $html ), 0 );
	$fluffless_assert( $fluffless_h1( '<!-- wp:group --><div><!-- wp:post-title {"level":1} /--></div><!-- /wp:group -->' ), 'titre H1 imbriqué' );
	$fluffless_assert( ! $fluffless_h1( '<!-- wp:post-title /-->' ), 'titre sans niveau = H2' );
	$fluffless_assert( ! $fluffless_h1( '<!-- wp:post-title {"level":3} /--><!-- wp:heading {"level":1} --><h1>x</h1><!-- /wp:heading -->' ), 'un titre de contenu ne compte pas' );

	// Compositions et parties de gabarit suivies, avec borne.
	register_block_pattern(
		'fluffless-test/titre',
		array(
			'title'   => 'Titre',
			'content' => '<!-- wp:post-title {"level":1} /-->',
		)
	);
	register_block_pattern(
		'fluffless-test/boucle',
		array(
			'title'   => 'Boucle',
			'content' => '<!-- wp:pattern {"slug":"fluffless-test/boucle"} /-->',
		)
	);
	$fluffless_assert( $fluffless_h1( '<!-- wp:pattern {"slug":"fluffless-test/titre"} /-->' ), 'titre dans une composition' );
	$fluffless_assert( ! $fluffless_h1( '<!-- wp:pattern {"slug":"fluffless-test/boucle"} /-->' ), 'composition qui s’appelle elle-même : bornée' );
	$fluffless_assert( ! $fluffless_h1( '<!-- wp:pattern {"slug":"fluffless-test/absente"} /--><!-- wp:template-part {"slug":"absente"} /-->' ), 'référence absente' );
	$fluffless_assert( ! $fluffless_h1( '<!-- wp:template-part {"slug":"header"} /-->' ), 'en-tête du thème sans titre H1' );

	// Gabarits du thème : page et article montrent le titre, Sections et Toile vierge non.
	$fluffless_templates = Quality::title_templates();
	$fluffless_assert( true === ( $fluffless_templates['page'] ?? null ), 'page.html montre le titre en H1' );
	$fluffless_assert( true === ( $fluffless_templates['single'] ?? null ), 'single.html montre le titre en H1' );
	foreach ( array( 'page-sections', 'page-toile-vierge', 'page-toile-vierge-nue' ) as $fluffless_slug ) {
		$fluffless_assert( false === ( $fluffless_templates[ $fluffless_slug ] ?? null ), $fluffless_slug . ' ne montre pas le titre' );
	}
	$fluffless_assert( false === ( $fluffless_templates['index'] ?? null ), 'index : titres en lien, H2' );

	// Gabarit par défaut selon la hiérarchie.
	$fluffless_page = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_title'  => 'Qualité',
			'post_status' => 'draft',
		)
	);
	$fluffless_post = wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_title'  => 'Qualité',
			'post_status' => 'draft',
		)
	);

	$fluffless_page_obj = get_post( $fluffless_page );
	$fluffless_post_obj = get_post( $fluffless_post );
	if ( ! $fluffless_page_obj instanceof WP_Post || ! $fluffless_post_obj instanceof WP_Post ) {
		throw new RuntimeException( 'Brouillons de test non créés.' );
	}
	$fluffless_assert( 'page' === Quality::default_template( $fluffless_page_obj, $fluffless_templates ), 'page : gabarit page' );
	$fluffless_assert( 'single' === Quality::default_template( $fluffless_post_obj, $fluffless_templates ), 'article : gabarit single' );
	$fluffless_assert( '' === Quality::default_template( $fluffless_post_obj, array() ), 'aucun gabarit : chaîne vide' );
	$fluffless_front = array( get_option( 'show_on_front' ), get_option( 'page_on_front' ) );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $fluffless_page );
	$fluffless_assert( 'front-page' === Quality::default_template( $fluffless_page_obj, array( 'front-page' => false ) + $fluffless_templates ), 'page d\'accueil : front-page' );
	$fluffless_assert( 'page' === Quality::default_template( $fluffless_page_obj, $fluffless_templates ), 'page d\'accueil sans front-page : page' );
	update_option( 'show_on_front', $fluffless_front[0] );
	update_option( 'page_on_front', $fluffless_front[1] );

	// Scripts de l'éditeur : cœur avant le panneau, données en ligne.
	$GLOBALS['post'] = $fluffless_page_obj; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simule l'écran d'édition.
	Quality::editor_assets();
	$fluffless_assert( wp_script_is( Quality::CORE_HANDLE, 'enqueued' ) && wp_script_is( Quality::SCRIPT_HANDLE, 'enqueued' ), 'scripts mis en file' );
	$fluffless_deps = wp_scripts()->registered[ Quality::SCRIPT_HANDLE ]->deps;
	$fluffless_assert( in_array( Quality::CORE_HANDLE, $fluffless_deps, true ) && in_array( 'wp-a11y', $fluffless_deps, true ), 'le panneau dépend du cœur et de wp-a11y' );
	$fluffless_inline = implode( '', (array) wp_scripts()->get_data( Quality::SCRIPT_HANDLE, 'before' ) );
	$fluffless_assert( str_contains( $fluffless_inline, '"defaultTemplate":"page"' ) && str_contains( $fluffless_inline, '"page-sections":false' ), 'données en ligne : ' . substr( $fluffless_inline, 0, 120 ) );
	$fluffless_panel = (string) file_get_contents( plugin_dir_path( Fluffless\Suite\FILE ) . 'assets/quality/editor.js' );
	$fluffless_assert( ! str_contains( $fluffless_panel, 'apiFetch' ), 'aucun appel réseau dans le panneau' );
	$fluffless_assert( str_contains( $fluffless_panel, "getBlocksByName( 'core/post-content' )" ), 'blocs de la publication, pas du gabarit affiché' );

	// Écran des widgets : rien n'est chargé.
	wp_dequeue_script( Quality::SCRIPT_HANDLE );
	wp_dequeue_script( Quality::CORE_HANDLE );
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( 'widgets' );
	Quality::editor_assets();
	$fluffless_assert( ! wp_script_is( Quality::SCRIPT_HANDLE, 'enqueued' ), 'rien sur l\'écran des widgets' );
} finally {
	unregister_block_pattern( 'fluffless-test/titre' );
	unregister_block_pattern( 'fluffless-test/boucle' );
	foreach ( array( $fluffless_page, $fluffless_post ) as $fluffless_id ) {
		if ( $fluffless_id ) {
			wp_delete_post( $fluffless_id, true );
		}
	}
	restore_previous_locale();
}

if ( $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		echo 'ÉCHEC : ', esc_html( $fluffless_failure ), "\n";
	}
	exit( 1 );
}
echo 'OK : Qualité du contenu, ', esc_html( (string) $fluffless_checks ), " vérifications réussies.\n";
