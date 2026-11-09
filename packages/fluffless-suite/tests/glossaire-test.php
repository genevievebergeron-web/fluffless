<?php
/**
 * Test des blocs Glossaire (fluffless/glossaire) et Terme (fluffless/terme) : une liste de
 * définitions native (dl > div > dt + dd), les termes rendus une seule fois par le bloc Terme,
 * colonnes en classe, entrée vide non rendue, terme isolé toujours valide, ancre sur l'entrée.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/glossaire-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\Sections;
use Fluffless\Suite\Modules\Expression;
use const Fluffless\Suite\FILE;

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
$fluffless_before   = array_keys( $fluffless_registry->get_all_registered() );
if ( ! $fluffless_registry->is_registered( 'fluffless/' . Sections::TERM ) ) {
	Expression::register_block();
}
$fluffless_type   = $fluffless_registry->get_registered( 'fluffless/' . Sections::TERM );
$fluffless_parent = $fluffless_registry->get_registered( 'fluffless/glossaire' );
if ( ! $fluffless_type instanceof WP_Block_Type || ! $fluffless_parent instanceof WP_Block_Type ) {
	throw new RuntimeException( 'Blocs Glossaire ou Terme non enregistrés.' );
}
$fluffless_dir  = dirname( FILE ) . '/blocks/';
$fluffless_term = static fn( string $name, string $definition = '' ): string => '<!-- wp:fluffless/terme --><!-- wp:paragraph --><p>' . $name . '</p><!-- /wp:paragraph -->' . ( '' !== $definition ? '<!-- wp:paragraph --><p>' . $definition . '</p><!-- /wp:paragraph -->' : '' ) . '<!-- /wp:fluffless/terme -->';
$fluffless_list = static fn( string $terms, string $attributes = '' ): string => '<!-- wp:fluffless/glossaire ' . $attributes . '-->' . $terms . '<!-- /wp:fluffless/glossaire -->';

try {
	// 1. Déclaration : Terme descendant du Glossaire, qui ne reçoit que des Termes et lui passe son
	// contexte ; ancre des deux côtés ; rien côté site pour le Terme ; le cœur ne rend pas les
	// paragraphes du Terme (skip_inner_blocks), le bloc s'en charge.
	$fluffless_assert( array( 'fluffless/glossaire' ) === $fluffless_type->ancestor && array( 'fluffless/glossaire' ) === ( $fluffless_type->uses_context ?? null ), 'terme : ancestor ou usesContext inattendus' );
	$fluffless_assert( true === ( $fluffless_type->skip_inner_blocks ?? null ), 'terme : skip_inner_blocks absent' );
	$fluffless_assert( block_has_support( $fluffless_type, 'anchor' ) && array() === $fluffless_type->view_script_handles && array() === $fluffless_type->style_handles, 'terme : support anchor ou ressources côté site' );
	$fluffless_assert( array( 'fluffless/terme' ) === $fluffless_parent->allowed_blocks && array( 'fluffless/glossaire' => 'columns' ) === $fluffless_parent->provides_context, 'glossaire : allowedBlocks ou providesContext inattendus' );
	$fluffless_assert( block_has_support( $fluffless_parent, 'anchor' ) && 2 === ( $fluffless_parent->attributes['columns']['default'] ?? null ), 'glossaire : support anchor ou défaut de columns' );
	foreach ( array( 'glossaire', Sections::TERM ) as $fluffless_slug ) {
		$fluffless_asset = include $fluffless_dir . $fluffless_slug . '/index.asset.php';
		$fluffless_assert( is_array( $fluffless_asset ) && in_array( Sections::EDITOR_SCRIPT, $fluffless_asset['dependencies'] ?? array(), true ), $fluffless_slug . '/index.asset.php : aides communes absentes des dépendances' );
	}

	// 2. Rendu : un dl dans la section, une entrée div > dt + dd par terme, dans l'ordre, les
	// paragraphes gardés ; deux colonnes par défaut.
	$fluffless_html = do_blocks( $fluffless_list( $fluffless_term( 'Bundle', 'A bundle.' ) . $fluffless_term( 'Fluff', 'What we remove.' ) ) );
	$fluffless_assert( 1 === preg_match( '#^<div class="wp-block-fluffless-glossaire fluffless-section fluffless-glossaire is-columns-2"><dl class="fluffless-glossaire__list"><div class="wp-block-fluffless-terme fluffless-terme"><dt class="fluffless-terme__name"><p[^>]*>Bundle</p></dt><dd class="fluffless-terme__definition"><p[^>]*>A bundle\.</p></dd></div><div class="wp-block-fluffless-terme fluffless-terme"><dt[^>]*><p[^>]*>Fluff</p></dt><dd[^>]*><p[^>]*>What we remove\.</p></dd></div></dl></div>$#', $fluffless_html ), 'glossaire : structure dl > div > dt + dd attendue : ' . $fluffless_html );
	$fluffless_assert( 1 === substr_count( $fluffless_html, '<dl ' ) && 1 === substr_count( $fluffless_html, 'Bundle' ), 'glossaire : un seul dl, chaque terme rendu une seule fois : ' . $fluffless_html );

	// 3. Colonnes : 1 en classe ; valeur hors liste ramenée au défaut.
	$fluffless_assert( str_contains( do_blocks( $fluffless_list( $fluffless_term( 'A', 'b' ), '{"columns":1} ' ) ), 'is-columns-1' ), 'glossaire : columns 1 non rendu' );
	$fluffless_assert( str_contains( do_blocks( $fluffless_list( $fluffless_term( 'A', 'b' ), '{"columns":7} ' ) ), 'is-columns-2' ), 'glossaire : columns hors liste non ramené au défaut' );

	// 4. Entrées incomplètes : sans terme, rien ; sans définition, dd vide (HTML valide).
	$fluffless_html = do_blocks( $fluffless_list( $fluffless_term( '', 'Orphan definition' ) . $fluffless_term( 'Alone' ) ) );
	$fluffless_assert( ! str_contains( $fluffless_html, 'Orphan' ) && 1 === preg_match( '#<dl class="fluffless-glossaire__list"><div class="wp-block-fluffless-terme fluffless-terme"><dt class="fluffless-terme__name"><p[^>]*>Alone</p></dt><dd class="fluffless-terme__definition"></dd></div></dl>#', $fluffless_html ), 'terme : entrée vide rendue, ou dd manquant : ' . $fluffless_html );
	$fluffless_assert( '<div class="wp-block-fluffless-glossaire fluffless-section fluffless-glossaire is-columns-2"><dl class="fluffless-glossaire__list"></dl></div>' === do_blocks( $fluffless_list( '' ) ), 'glossaire vide : dl vide attendu' );

	// 5. Terme hors Glossaire : son propre dl, HTML valide.
	$fluffless_html = do_blocks( $fluffless_term( 'Loose', 'Still valid.' ) );
	$fluffless_assert( 1 === preg_match( '#^<dl class="fluffless-glossaire__list"><div class="wp-block-fluffless-terme fluffless-terme"><dt[^>]*><p[^>]*>Loose</p></dt><dd[^>]*><p[^>]*>Still valid\.</p></dd></div></dl>$#', $fluffless_html ), 'terme isolé : dl absent : ' . $fluffless_html );

	// 6. Ancre : l'id sur l'entrée (div), pas sur le dl ; le Glossaire garde la sienne.
	$fluffless_html = do_blocks( $fluffless_list( '<!-- wp:fluffless/terme {"anchor":"terme-fluff"} --><!-- wp:paragraph --><p>Fluff</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Def</p><!-- /wp:paragraph --><!-- /wp:fluffless/terme -->', '{"anchor":"lexique"} ' ) );
	$fluffless_assert( str_starts_with( $fluffless_html, '<div id="lexique" class="wp-block-fluffless-glossaire' ) && str_contains( $fluffless_html, '<dl class="fluffless-glossaire__list"><div id="terme-fluff" class="wp-block-fluffless-terme fluffless-terme"><dt' ), 'ancres : id du glossaire ou du terme absent : ' . $fluffless_html );

	// 7. Styles des paragraphes conservés (le rendu passe par le cœur, filtres compris).
	$fluffless_html = do_blocks( $fluffless_list( '<!-- wp:fluffless/terme --><!-- wp:paragraph {"textColor":"accent"} --><p class="has-accent-color has-text-color">Tinted</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Def</p><!-- /wp:paragraph --><!-- /wp:fluffless/terme -->' ) );
	$fluffless_assert( 1 === preg_match( '#<dt class="fluffless-terme__name"><p class="has-accent-color has-text-color[^"]*">Tinted</p></dt>#', $fluffless_html ), 'terme : classes du paragraphe perdues : ' . $fluffless_html );

	// 8. Les filtres du cœur passent sur les paragraphes du Terme (visibilité conditionnelle,
	// traduction), une seule fois : render_block remplace la définition et compte ses passages.
	$fluffless_hits = 0;
	$fluffless_pre  = static function ( $html, array $parsed ) use ( &$fluffless_hits ) {
		if ( 'core/paragraph' === ( $parsed['blockName'] ?? '' ) && str_contains( (string) ( $parsed['innerHTML'] ?? '' ), 'Def' ) ) {
			++$fluffless_hits;
			return '<p>Filtered</p>';
		}
		return $html;
	};
	add_filter( 'render_block', $fluffless_pre, 10, 2 );
	$fluffless_html = do_blocks( $fluffless_list( $fluffless_term( 'Hooked', 'Def' ) ) );
	remove_filter( 'render_block', $fluffless_pre, 10 );
	// Les filtres suivants du cœur (classe wp-block-paragraph) passent après le remplacement.
	$fluffless_assert( 1 === $fluffless_hits && 1 === preg_match( '#<dd class="fluffless-terme__definition"><p[^>]*>Filtered</p></dd>#', $fluffless_html ) && str_contains( $fluffless_html, 'Hooked' ) && ! str_contains( $fluffless_html, 'Def' ), 'terme : render_block ignoré ou passé plus d’une fois sur la définition : ' . $fluffless_html );

	// 8. L'éditeur du Glossaire ne crée que des Termes ; sa feuille lit les colonnes.
	$fluffless_js = (string) file_get_contents( $fluffless_dir . 'glossaire/index.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
	$fluffless_assert( str_contains( $fluffless_js, "S.createBlock( 'fluffless/terme' )" ) && ! str_contains( $fluffless_js, 'core/heading' ), 'glossaire/index.js : autre chose que des Termes dans le gabarit' );
	$fluffless_css = (string) file_get_contents( $fluffless_dir . 'glossaire/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
	$fluffless_assert( str_contains( $fluffless_css, '.is-columns-2 dl{columns:2' ) && str_contains( $fluffless_css, 'break-inside:avoid' ), 'glossaire/style.css : colonnes CSS absentes' );
} finally {
	foreach ( array_diff( array_keys( $fluffless_registry->get_all_registered() ), $fluffless_before ) as $fluffless_extra ) {
		unregister_block_type( $fluffless_extra );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Glossaire : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Glossaire : {$fluffless_checks} vérifications passées." );
