<?php
/**
 * Tests du bloc Panneau de menu (module Expressivité) : élément de menu qui tient un <details>
 * natif, panneau de blocs libres rendu tel quel, libellé assaini et borné, largeur fermée, un
 * seul panneau ouvert à la fois (name partagé), 0 JS, feuille sous 1 ko sans HEX, inséré dans
 * un bloc Navigation du cœur sans que celui-ci charge son script.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/panneau-menu-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Modules\Expression;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! defined( Expression::class . '::PANNEAU' ) ) {
	throw new RuntimeException( 'Panneau de menu absent du module Expressivité.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// 1. Attributs : libellé texte seul et borné, défaut traduisible, largeur fermée.
$fluffless_a = Expression::panneau_attributes( array() );
$fluffless_assert( 'full' === $fluffless_a['width'], 'largeur par défaut : full' );
$fluffless_assert( '' !== $fluffless_a['label'], 'libellé par défaut vide' );
$fluffless_a = Expression::panneau_attributes(
	array(
		'width'     => 'huge',
		'label'     => '<b onmouseover="x">Offre</b>' . str_repeat( 'a', 200 ),
		'className' => 'is-style-x <script>',
	)
);
$fluffless_assert( 'full' === $fluffless_a['width'], 'largeur hors liste : retour au défaut' );
$fluffless_assert( ! str_contains( $fluffless_a['label'], '<' ) && ! str_contains( $fluffless_a['label'], 'onmouseover' ), 'balises gardées dans le libellé' );
$fluffless_assert( mb_strlen( $fluffless_a['label'] ) <= Expression::POPUP_LABEL_MAX, 'libellé non borné' );
$fluffless_assert( ! str_contains( $fluffless_a['className'], '<' ), 'classe non assainie' );
$fluffless_assert( 'content' === Expression::panneau_attributes( array( 'width' => 'content' ) )['width'], 'largeur content refusée' );

// 2. Rendu : élément de menu, details nommé, résumé déclencheur, contenu intact, aucun script.
$fluffless_inner        = '<div class="wp-block-columns"><p>Colonne</p></div>';
$fluffless_escape_calls = 0;
$fluffless_escape_hook  = static function () use ( &$fluffless_escape_calls ): void {
	++$fluffless_escape_calls;
};
add_action( 'fluffless_nav_escape', $fluffless_escape_hook );
$fluffless_html = Expression::render_panneau( array( 'label' => 'Nos services' ), $fluffless_inner );
remove_action( 'fluffless_nav_escape', $fluffless_escape_hook );
$fluffless_assert( 1 === $fluffless_escape_calls, 'action fluffless_nav_escape non déclenchée au rendu (' . $fluffless_escape_calls . ')' );
$fluffless_assert( str_starts_with( $fluffless_html, '<li class="wp-block-navigation-item ' ), 'enveloppe hors d\'un élément de menu' );
$fluffless_assert( str_contains( $fluffless_html, 'fluffless-panneau-menu is-width-full' ), 'classes du bloc absentes' );
$fluffless_assert( str_contains( $fluffless_html, '<details class="fluffless-panneau-menu__details" name="fluffless-panneau-menu" data-fluffless-esc>' ), 'details sans name partagé ou sans data-fluffless-esc' );
$fluffless_assert( str_contains( $fluffless_html, '<summary class="wp-block-navigation-item__content fluffless-panneau-menu__summary">Nos services</summary>' ), 'résumé absent ou mal classé' );
$fluffless_assert( str_contains( $fluffless_html, '<div class="fluffless-panneau-menu__panel">' . $fluffless_inner . '</div></details></li>' ), 'contenu du panneau modifié' );
$fluffless_assert( ! str_contains( $fluffless_html, '<script' ) && ! str_contains( $fluffless_html, ' open' ), 'script ou panneau ouvert d\'office' );
$fluffless_html = Expression::render_panneau(
	array(
		'label'     => 'A <b>&</b> B',
		'width'     => 'content',
		'className' => 'perso',
	),
	''
);
$fluffless_assert( str_contains( $fluffless_html, '>A &amp; B</summary>' ), 'libellé non échappé' );
$fluffless_assert( str_contains( $fluffless_html, 'is-width-content perso"' ), 'largeur content ou classe perso absente' );
$fluffless_assert( str_starts_with( Expression::render_panneau( 'x', null ), '<li ' ), 'rendu hors du cœur : types tolérés' );

// 3. Feuille : sous 1 ko, sans HEX, empilée sous 600 px, panneau absolu sinon.
$fluffless_css = (string) file_get_contents( dirname( __DIR__ ) . '/blocks/panneau-menu/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fluffless_assert( '' !== $fluffless_css && strlen( $fluffless_css ) < 1024, 'feuille absente ou de 1 ko et plus : ' . strlen( $fluffless_css ) );
$fluffless_assert( 0 === preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_css ), 'HEX dans la feuille' );
$fluffless_assert( str_contains( $fluffless_css, 'position:absolute' ) && str_contains( $fluffless_css, '@media (max-width:600px)' ) && str_contains( $fluffless_css, 'position:static' ), 'panneau absolu ou empilement absent' );
$fluffless_assert( ! str_contains( $fluffless_css, 'url(' ), 'url() dans la feuille' );

// 4. Bloc : enregistré, parent Navigation du cœur, feuille et script d'éditeur déclarés. Le module
// est inactif par défaut (donc sur la CI) : ses blocs sont enregistrés ici au besoin et retirés à la fin.
$fluffless_registry = WP_Block_Type_Registry::get_instance();
$fluffless_before   = array();
foreach ( array( Expression::VAGUE, Expression::RECIT, Expression::POPUP, Expression::PANNEAU ) as $fluffless_name ) {
	$fluffless_before[ $fluffless_name ] = $fluffless_registry->is_registered( $fluffless_name );
}
if ( ! $fluffless_before[ Expression::PANNEAU ] ) {
	Expression::register_block();
}
$fluffless_type = WP_Block_Type_Registry::get_instance()->get_registered( Expression::PANNEAU );
$fluffless_assert( $fluffless_type instanceof WP_Block_Type, 'bloc non enregistré' );
if ( $fluffless_type instanceof WP_Block_Type ) {
	$fluffless_assert( array( 'core/navigation' ) === $fluffless_type->parent, 'parent autre que core/navigation' );
	$fluffless_assert( ! empty( $fluffless_type->style_handles ) && ! empty( $fluffless_type->editor_script_handles ), 'feuille ou script d\'éditeur non déclaré' );
	$fluffless_assert( empty( $fluffless_type->view_script_handles ) && empty( $fluffless_type->view_script_module_ids ), 'script côté visiteur déclaré' );
	$fluffless_assert( ! empty( $fluffless_type->editor_style_handles ), 'feuille d\'éditeur non déclarée' );
	$fluffless_editor_css = file_get_contents( __DIR__ . '/../blocks/panneau-menu/editor.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( false !== $fluffless_editor_css && strlen( $fluffless_editor_css ) < 1024 && str_contains( $fluffless_editor_css, '.editor-styles-wrapper .fluffless-panneau-menu__panel{position:static' ) && ! preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_editor_css ), 'feuille d\'éditeur absente, trop lourde, sans mise en flux ou avec HEX' );
	$fluffless_assert( array( 'full', 'content' ) === ( $fluffless_type->attributes['width']['enum'] ?? null ), 'largeurs de block.json différentes du rendu' );
}

// 5. Dans un bloc Navigation du cœur rendu par do_blocks : le panneau sort, sans interactivité.
$fluffless_page = do_blocks(
	'<!-- wp:navigation {"overlayMenu":"never","showSubmenuIcon":false} -->'
	. '<!-- wp:navigation-link {"label":"Accueil","url":"/","kind":"custom"} /-->'
	. '<!-- wp:fluffless/panneau-menu {"label":"Offre"} --><!-- wp:paragraph --><p>Tout le détail</p><!-- /wp:paragraph --><!-- /wp:fluffless/panneau-menu -->'
	. '<!-- /wp:navigation -->'
);
$fluffless_assert( str_contains( $fluffless_page, 'wp-block-navigation' ) && str_contains( $fluffless_page, '<details class="fluffless-panneau-menu__details"' ), 'panneau absent du menu rendu' );
$fluffless_assert( str_contains( $fluffless_page, 'Tout le détail</p>' ), 'contenu du panneau perdu dans le menu' );
$fluffless_assert( ! str_contains( $fluffless_page, 'data-wp-interactive' ) && ! str_contains( $fluffless_page, '<script' ), 'le menu charge son interactivité' );
$fluffless_assert( wp_style_is( 'fluffless-panneau-menu-style', 'enqueued' ), 'feuille du bloc non chargée au rendu' );
foreach ( $fluffless_before as $fluffless_name => $fluffless_was ) {
	if ( ! $fluffless_was && $fluffless_registry->is_registered( $fluffless_name ) ) {
		unregister_block_type( $fluffless_name );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Panneau de menu : {$fluffless_checks} vérifications passées." );
