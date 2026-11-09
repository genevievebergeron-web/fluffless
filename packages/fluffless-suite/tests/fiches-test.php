<?php
/**
 * Tests du module Fiches de boucle : fiches du thème et compositions synchronisées listées,
 * réglage assaini, rendu du bloc fluffless/fiche avec le contexte du contenu, remplacement du
 * Modèle de publication d'une boucle par la fiche du type, classe d'exclusion, attribut du bloc,
 * garde de récursion, écran d'administration, bloc sans script côté visiteur.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/fiches-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Modules\Loops;

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les textes de l'écran en en_US : indépendants de la langue du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// Jeu d'essai : deux articles publiés et une composition synchronisée, retirés à la fin.
$fluffless_ids = array();
foreach ( array( 'Fiche essai un', 'Fiche essai deux' ) as $fluffless_title ) {
	$fluffless_ids[] = (int) wp_insert_post(
		array(
			'post_title'   => $fluffless_title,
			'post_content' => '<!-- wp:paragraph --><p>Texte de ' . $fluffless_title . '</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
}
$fluffless_block_id = (int) wp_insert_post(
	array(
		'post_title'   => 'Fiche maison',
		'post_content' => '<!-- wp:group {"className":"fiche-maison"} --><div class="wp-block-group fiche-maison"><!-- wp:post-title {"level":4} /--><!-- wp:post-excerpt /--></div><!-- /wp:group -->',
		'post_status'  => 'publish',
		'post_type'    => 'wp_block',
	)
);
$fluffless_loop_id  = (int) wp_insert_post(
	array(
		'post_title'   => 'Fiche qui se contient',
		'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:fluffless/fiche /--></div><!-- /wp:group -->',
		'post_status'  => 'publish',
		'post_type'    => 'wp_block',
	)
);
if ( ! has_action( 'init', array( Loops::class, 'register_block' ) ) ) {
	( new Loops() )->register();
	Loops::register_block();
}
$fluffless_cleanup = static function () use ( $fluffless_ids, $fluffless_block_id, $fluffless_loop_id ): void {
	foreach ( array_merge( $fluffless_ids, array( $fluffless_block_id, $fluffless_loop_id ) ) as $fluffless_id ) {
		if ( $fluffless_id > 0 ) {
			wp_delete_post( $fluffless_id, true );
		}
	}
	delete_option( Lifecycle::OPTION_FICHES );
};

try {
	// 1. Fiches disponibles : les trois du thème puis les compositions synchronisées.
	$fluffless_cards = Loops::cards();
	$fluffless_assert( isset( $fluffless_cards['carte'], $fluffless_cards['liste'], $fluffless_cards['mosaique'] ), 'fiches du thème absentes : ' . implode( ',', array_keys( $fluffless_cards ) ) );
	$fluffless_assert( isset( $fluffless_cards[ 'block:' . $fluffless_block_id ] ) && str_contains( $fluffless_cards[ 'block:' . $fluffless_block_id ], 'Fiche maison' ), 'composition synchronisée absente' );
	$fluffless_assert( isset( Loops::types()['post'], Loops::types()['page'] ) && ! isset( Loops::types()['attachment'] ), 'types publics' );

	// 2. Réglage assaini : types inconnus, identifiants mal formés et choix vides retirés.
	$fluffless_clean = Loops::sanitize_settings(
		array(
			'post'       => 'carte',
			'page'       => '',
			'inconnu'    => 'carte',
			'product'    => 'block:12',
			'sensei'     => '<b>x</b>',
			'attachment' => 'liste',
		)
	);
	$fluffless_assert( array( 'post' => 'carte' ) === array_intersect_key( $fluffless_clean, array( 'post' => 1 ) ) && ! isset( $fluffless_clean['page'], $fluffless_clean['inconnu'], $fluffless_clean['sensei'], $fluffless_clean['attachment'] ), 'assainissement : ' . wp_json_encode( $fluffless_clean ) );
	$fluffless_assert( '' === Loops::card_content( 'nope' ) && '' === Loops::card_content( 'block:999999999' ) && '' === Loops::card_content( '../x' ), 'contenu d\'une fiche absente' );
	$fluffless_assert( str_contains( Loops::card_content( 'carte' ), 'fluffless-fiche-carte' ) && str_contains( Loops::card_content( 'liste' ), 'wp:post-excerpt' ) && str_contains( Loops::card_content( 'mosaique' ), '"aspectRatio":"1"' ), 'contenu des fiches du thème' );
	$fluffless_assert( str_contains( Loops::card_content( 'block:' . $fluffless_block_id ), 'fiche-maison' ), 'contenu de la composition synchronisée' );

	// 3. Rendu du bloc avec contexte : le titre du bon article, fiche du thème par défaut.
	$fluffless_block = new WP_Block(
		array(
			'blockName'    => Loops::BLOCK,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		array(
			'postId'   => $fluffless_ids[1],
			'postType' => 'post',
		)
	);
	$fluffless_html  = $fluffless_block->render();
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless-fiche-carte' ) && str_contains( $fluffless_html, 'Fiche essai deux' ) && ! str_contains( $fluffless_html, 'Fiche essai un' ), 'rendu avec contexte : ' . substr( $fluffless_html, 0, 300 ) );
	$fluffless_assert( str_contains( $fluffless_html, (string) get_permalink( $fluffless_ids[1] ) ), 'lien du titre absent' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ), 'script dans la fiche' );
	$fluffless_assert( '' === Loops::render_block( array( 'card' => 'carte' ), '', null ) || is_int( get_the_ID() ), 'rendu hors boucle tolérant' );

	// 4. Attribut du bloc : fiche liste, puis composition synchronisée, puis fiche inconnue (retour à carte).
	$fluffless_render = static function ( string $card, int $post_id ): string {
		return ( new WP_Block(
			array(
				'blockName'    => Loops::BLOCK,
				'attrs'        => array( 'card' => $card ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array(
				'postId'   => $post_id,
				'postType' => 'post',
			)
		) )->render();
	};
	$fluffless_liste  = $fluffless_render( 'liste', $fluffless_ids[0] );
	$fluffless_assert( str_contains( $fluffless_liste, 'fluffless-fiche-liste' ) && str_contains( $fluffless_liste, 'Texte de Fiche essai un' ), 'fiche liste par attribut : ' . substr( $fluffless_liste, 0, 300 ) );
	$fluffless_maison = $fluffless_render( 'block:' . $fluffless_block_id, $fluffless_ids[0] );
	$fluffless_assert( str_contains( $fluffless_maison, 'fiche-maison' ) && str_contains( $fluffless_maison, '<h4' ) && str_contains( $fluffless_maison, 'Fiche essai un' ), 'composition synchronisée par attribut : ' . substr( $fluffless_maison, 0, 300 ) );
	$fluffless_assert( str_contains( $fluffless_render( 'inconnue', $fluffless_ids[0] ), 'fluffless-fiche-carte' ), 'fiche inconnue sans retour à la carte' );

	// 5. Garde de récursion : une composition qui contient le bloc ne boucle pas.
	$fluffless_boucle = $fluffless_render( 'block:' . $fluffless_loop_id, $fluffless_ids[0] );
	$fluffless_assert( substr_count( $fluffless_boucle, 'wp-block-group' ) <= 2, 'récursion non coupée : ' . substr( $fluffless_boucle, 0, 300 ) );

	// 6. Boucle de requête : sans réglage, la boucle garde ses blocs ; avec, la fiche du type.
	$fluffless_query = '<!-- wp:query {"queryId":9,"query":{"perPage":5,"postType":"post","inherit":false,"orderBy":"title","order":"asc","search":"Fiche essai"}} --><div class="wp-block-query">'
		. '<!-- wp:post-template --><!-- wp:post-title {"level":5} /--><!-- /wp:post-template -->'
		. '</div><!-- /wp:query -->';
	delete_option( Lifecycle::OPTION_FICHES );
	$fluffless_free = do_blocks( $fluffless_query );
	$fluffless_assert( str_contains( $fluffless_free, '<h5' ) && ! str_contains( $fluffless_free, 'fluffless-fiche' ), 'boucle sans réglage modifiée : ' . substr( $fluffless_free, 0, 300 ) );
	update_option( Lifecycle::OPTION_FICHES, array( 'post' => 'mosaique' ), false );
	$fluffless_swapped = do_blocks( $fluffless_query );
	$fluffless_assert( 2 === substr_count( $fluffless_swapped, 'fluffless-fiche-mosaique' ) && ! str_contains( $fluffless_swapped, '<h5' ), 'boucle non remplacée par la fiche mosaïque : ' . substr( $fluffless_swapped, 0, 400 ) );
	$fluffless_assert( str_contains( $fluffless_swapped, 'Fiche essai un' ) && str_contains( $fluffless_swapped, 'Fiche essai deux' ), 'les deux articles ne sont pas rendus' );
	$fluffless_assert( str_contains( $fluffless_swapped, '<li class="wp-block-post' ), 'éléments de liste du Modèle de publication perdus' );
	$fluffless_other = do_blocks( str_replace( '"postType":"post"', '"postType":"page"', $fluffless_query ) );
	$fluffless_assert( ! str_contains( $fluffless_other, 'fluffless-fiche' ), 'boucle d\'un autre type remplacée' );
	$fluffless_kept = do_blocks( str_replace( '<!-- wp:post-template -->', '<!-- wp:post-template {"className":"fluffless-fiche-libre"} -->', $fluffless_query ) );
	$fluffless_assert( str_contains( $fluffless_kept, '<h5' ) && ! str_contains( $fluffless_kept, 'fluffless-fiche-mosaique' ), 'classe d\'exclusion ignorée' );
	$fluffless_assert( 'post' === Loops::loop_type( null ) && '' === Loops::card_for( 'page' ) && 'mosaique' === Loops::card_for( 'post' ), 'type de boucle ou fiche du type' );
	$fluffless_assert( 'mosaique' === Loops::card_for( 'post' ) && '' === Loops::card_for( 'inconnu' ), 'fiche par type' );
	update_option( Lifecycle::OPTION_FICHES, array( 'post' => 'block:' . $fluffless_block_id ), false );
	$fluffless_synced = do_blocks( $fluffless_query );
	$fluffless_assert( 2 === substr_count( $fluffless_synced, 'fiche-maison' ) && str_contains( $fluffless_synced, 'Texte de Fiche essai deux' ), 'composition synchronisée par réglage : ' . substr( $fluffless_synced, 0, 400 ) );
	delete_option( Lifecycle::OPTION_FICHES );

	// 7. Bloc enregistré : contexte, attribut, script d'éditeur, aucun script côté visiteur.
	$fluffless_type = WP_Block_Type_Registry::get_instance()->get_registered( Loops::BLOCK );
	$fluffless_assert( $fluffless_type instanceof WP_Block_Type, 'bloc non enregistré' );
	if ( $fluffless_type instanceof WP_Block_Type ) {
		$fluffless_json = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/blocks/fiche/block.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$fluffless_assert( is_array( $fluffless_json ) && array( 'postId', 'postType' ) === ( $fluffless_json['usesContext'] ?? null ), 'contexte du bloc' );
		$fluffless_assert( 'string' === ( $fluffless_type->attributes['card']['type'] ?? null ), 'attribut card' );
		$fluffless_assert( ! empty( $fluffless_type->editor_script_handles ) && empty( $fluffless_type->view_script_handles ) && empty( $fluffless_type->view_script_module_ids ) && empty( $fluffless_type->style_handles ), 'scripts ou feuilles du bloc' );
	}
	Loops::editor_assets();
	$fluffless_before = wp_scripts()->get_data( generate_block_asset_handle( Loops::BLOCK, 'editorScript' ), 'before' );
	$fluffless_script = is_array( $fluffless_before ) ? implode( '', $fluffless_before ) : '';
	$fluffless_assert( str_starts_with( $fluffless_script, 'window.flufflessFiches={"cards":[' ) && str_contains( $fluffless_script, '{"value":"carte"' ), 'fiches non transmises à l\'éditeur : ' . $fluffless_script );
	$fluffless_js = (string) file_get_contents( dirname( __DIR__ ) . '/blocks/fiche/index.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( str_contains( $fluffless_js, "'fluffless/fiche'" ) && str_contains( $fluffless_js, 'window.flufflessFiches' ) && str_contains( $fluffless_js, "__( 'As set for this content type', 'fluffless-suite' )" ) && str_contains( $fluffless_js, 'wp.serverSideRender' ), 'script d\'éditeur incomplet' );

	// 8. Écran : rendu pour un administrateur seulement, un choix par type, fiche disparue signalée.
	$fluffless_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$fluffless_user   = get_current_user_id();
	ob_start();
	Loops::render();
	$fluffless_anon = (string) ob_get_clean();
	$fluffless_assert( '' === $fluffless_anon, 'écran rendu sans capacité' );
	if ( array() !== $fluffless_admins ) {
		wp_set_current_user( (int) $fluffless_admins[0] );
		update_option( Lifecycle::OPTION_FICHES, array( 'post' => 'disparue' ), false );
		ob_start();
		Loops::render();
		$fluffless_screen = (string) ob_get_clean();
		wp_set_current_user( $fluffless_user );
		$fluffless_assert( str_contains( $fluffless_screen, 'name="card[post]"' ) && str_contains( $fluffless_screen, 'name="card[page]"' ) && ! str_contains( $fluffless_screen, 'name="card[attachment]"' ), 'un choix par type public' );
		$fluffless_assert( str_contains( $fluffless_screen, 'disparue (missing)' ) && str_contains( $fluffless_screen, 'value="block:' . $fluffless_block_id . '"' ), 'fiche disparue ou composition synchronisée absentes de l\'écran' );
		$fluffless_assert( str_contains( $fluffless_screen, 'name="_wpnonce"' ) && str_contains( $fluffless_screen, 'value="' . Loops::ACTION . '"' ), 'nonce ou action absents' );
		delete_option( Lifecycle::OPTION_FICHES );
	}
} finally {
	$fluffless_cleanup();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Fiches de boucle : {$fluffless_checks} vérifications passées." );
