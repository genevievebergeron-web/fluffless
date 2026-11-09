<?php
/**
 * Tests des gabarits de pages enregistrés par l'agence (Éditeur) : assainissement, enregistrement,
 * remplacement, plafond, kses, retrait, compositions inscrites et cartes de l'écran de départ,
 * routes REST pour un administrateur et un visiteur, export et import des réglages.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/page-templates-test.php
 * L'option est restaurée à la fin, les compositions de test retirées, le compte de test supprimé.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Admin\Transfer;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Modules\Editor;
use Fluffless\Suite\Modules\Editor\Templates;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Templates::class ) ) {
	throw new RuntimeException( 'Classe Templates absente : activer l\'extension.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_before   = get_option( Templates::OPTION, false );
$fluffless_previous = get_current_user_id();
$fluffless_admins   = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$fluffless_admin    = (int) ( $fluffless_admins[0] ?? 0 );
$fluffless_editor   = 0;
$fluffless_content  = '<!-- wp:group {"metadata":{"name":"fl:test"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Un titre</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Un paragraphe.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$fluffless_names    = array();

try {
	Templates::store( array() );

	// 1. Contrat et branchement.
	$fluffless_assert( 'fluffless_page_templates' === Lifecycle::OPTION_PAGE_TEMPLATES, 'Option nommée dans Lifecycle.' ); // @phpstan-ignore identical.alwaysTrue
	$fluffless_assert( 20 === has_action( 'init', array( Templates::class, 'register_patterns' ) ), 'Compositions inscrites sur init (20).' );
	$fluffless_assert( 10 === has_action( 'rest_api_init', array( Templates::class, 'register_routes' ) ), 'Routes REST branchées.' );
	$fluffless_assert( array() === Templates::all(), 'Aucun gabarit au départ.' );
	$fluffless_assert( 'fluffless-page/abc' === Templates::pattern_name( 'abc' ), 'Nom de composition préfixé.' );

	// 2. Assainissement : entrées invalides écartées, identifiants uniques, bornes, plafond.
	$fluffless_raw   = array(
		'texte',
		array(
			'id'      => 'ok',
			'title'   => ' Bonjour <b>là</b> ',
			'content' => $fluffless_content,
		),
		array(
			'id'      => 'ok',
			'title'   => 'Doublon',
			'content' => $fluffless_content,
		),
		array(
			'id'      => 'Sans Contenu',
			'title'   => 'Vide',
			'content' => '',
		),
		array(
			'id'      => 'sans-titre',
			'title'   => '',
			'content' => $fluffless_content,
		),
		array(
			'id'      => 'trop-gros',
			'title'   => 'Gros',
			'content' => str_repeat( 'a', Templates::MAX_BYTES + 1 ),
		),
		array(
			'id'      => 'Deux',
			'title'   => str_repeat( 'x', 200 ),
			'content' => $fluffless_content,
		),
	);
	$fluffless_clean = Templates::sanitize( $fluffless_raw );
	$fluffless_assert( array( 'ok', 'deux' ) === array_column( $fluffless_clean, 'id' ), 'Deux entrées gardées (sans bloc écartée) : ' . implode( ',', array_column( $fluffless_clean, 'id' ) ) );
	$fluffless_assert( 'Bonjour là' === $fluffless_clean[0]['title'], 'Titre nettoyé des balises et des espaces.' );
	$fluffless_assert( Templates::MAX_TITLE === mb_strlen( $fluffless_clean[1]['title'] ), 'Titre borné à ' . Templates::MAX_TITLE . ' caractères.' );
	$fluffless_assert( array() === Templates::sanitize( 'texte' ) && array() === Templates::sanitize( null ), 'Valeur non tableau : liste vide.' );
	$fluffless_many = array();
	for ( $fluffless_i = 0; $fluffless_i < Templates::MAX + 5; $fluffless_i++ ) {
		$fluffless_many[] = array(
			'id'      => 'g' . $fluffless_i,
			'title'   => 'G ' . $fluffless_i,
			'content' => $fluffless_content,
		);
	}
	$fluffless_assert( Templates::MAX === count( Templates::sanitize( $fluffless_many ) ), 'Plafond de ' . Templates::MAX . ' gabarits à l’assainissement.' );

	// 3. Enregistrement par un administrateur : identifiant depuis le titre, remplacement, refus.
	wp_set_current_user( $fluffless_admin );
	$fluffless_saved = Templates::save( 'Page Équipe 2026', $fluffless_content );
	$fluffless_assert( is_array( $fluffless_saved ) && 'page-equipe-2026' === $fluffless_saved['id'] && 'Page Équipe 2026' === $fluffless_saved['title'], 'Gabarit enregistré, identifiant tiré du titre.' );
	$fluffless_assert( 1 === count( Templates::all() ) && null !== Templates::get( 'page-equipe-2026' ), 'Un gabarit en option.' );
	$fluffless_again = Templates::save( 'Page Équipe 2026', str_replace( 'Un titre', 'Autre titre', $fluffless_content ) );
	$fluffless_assert( is_array( $fluffless_again ) && 1 === count( Templates::all() ) && str_contains( (string) ( Templates::get( 'page-equipe-2026' )['content'] ?? '' ), 'Autre titre' ), 'Même titre : gabarit remplacé, pas doublé.' );
	$fluffless_error = Templates::save( '   ', $fluffless_content );
	$fluffless_assert( $fluffless_error instanceof WP_Error && 'fluffless_template_title' === $fluffless_error->get_error_code(), 'Titre vide refusé.' );
	$fluffless_error = Templates::save( 'Vide', '<p>pas un bloc</p>' );
	$fluffless_assert( $fluffless_error instanceof WP_Error && 'fluffless_template_empty' === $fluffless_error->get_error_code(), 'Contenu sans bloc refusé.' );
	$fluffless_error = Templates::save( 'Gros', '<!-- wp:paragraph --><p>' . str_repeat( 'a', Templates::MAX_BYTES ) . '</p><!-- /wp:paragraph -->' );
	$fluffless_assert( $fluffless_error instanceof WP_Error && 'fluffless_template_size' === $fluffless_error->get_error_code(), 'Contenu trop gros refusé.' );
	$fluffless_assert( false === get_option( Templates::OPTION . '_missing', false ) && 'no' === ( wp_load_alloptions()[ Templates::OPTION ] ?? 'no' ), 'Option jamais autochargée.' );

	// 4. Plafond à l'enregistrement.
	Templates::store( Templates::sanitize( $fluffless_many ) );
	$fluffless_error = Templates::save( 'Un de trop', $fluffless_content );
	$fluffless_assert( $fluffless_error instanceof WP_Error && 'fluffless_template_full' === $fluffless_error->get_error_code(), 'Plafond atteint : enregistrement refusé.' );
	$fluffless_assert( is_array( Templates::save( 'G3', $fluffless_content ) ), 'Plafond atteint : remplacer un gabarit existant reste possible.' );
	Templates::store( array() );

	// 5. kses : un compte auteur (sans unfiltered_html) voit le contenu filtré comme une page.
	$fluffless_inserted = wp_insert_user(
		array(
			'user_login' => 'fl-templates-test-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => 'author',
		)
	);
	$fluffless_editor   = is_int( $fluffless_inserted ) ? $fluffless_inserted : 0;
	wp_set_current_user( $fluffless_editor );
	$fluffless_unsafe = str_replace( '<p>Un paragraphe.</p>', '<p>Un paragraphe.<script>alert(1)</script></p>', $fluffless_content );
	$fluffless_saved  = Templates::save( 'Sans script', $fluffless_unsafe );
	$fluffless_assert( is_array( $fluffless_saved ) && ! str_contains( $fluffless_saved['content'], '<script' ) && str_contains( $fluffless_saved['content'], 'Un paragraphe.' ), 'Script retiré par kses pour un compte sans unfiltered_html.' );
	$fluffless_first  = Templates::delete( 'sans-script' );
	$fluffless_second = Templates::delete( 'sans-script' );
	$fluffless_assert( $fluffless_first && ! $fluffless_second, 'Retrait : vrai puis faux.' );

	// 6. Compositions inscrites et cartes de l'écran de départ.
	wp_set_current_user( $fluffless_admin );
	$fluffless_saved = Templates::save( 'Équipe', $fluffless_content );
	if ( ! is_array( $fluffless_saved ) ) {
		throw new RuntimeException( 'Enregistrement impossible.' );
	}
	$fluffless_names[] = Templates::pattern_name( $fluffless_saved['id'] );
	Templates::register_patterns();
	$fluffless_registry = WP_Block_Patterns_Registry::get_instance();
	$fluffless_pattern  = $fluffless_registry->get_registered( $fluffless_names[0] );
	$fluffless_assert( is_array( $fluffless_pattern ) && array( 'core/post-content' ) === $fluffless_pattern['blockTypes'] && array( 'page' ) === $fluffless_pattern['postTypes'] && false === $fluffless_pattern['inserter'], 'Composition de page inscrite, hors de l’outil d’insertion.' );
	$fluffless_cards = Editor::pages();
	$fluffless_last  = end( $fluffless_cards );
	$fluffless_assert( is_array( $fluffless_last ) && $fluffless_names[0] === $fluffless_last['name'] && $fluffless_last['saved'] && 'Équipe' === $fluffless_last['title'] && array() === $fluffless_last['questions'] && 'clipboard-note' === $fluffless_last['icon'], 'Carte du gabarit en fin d’écran de départ, sans questionnaire.' );
	$fluffless_data = Editor::editor_data();
	$fluffless_assert( true === ( $fluffless_data['templates']['save'] ?? null ) && isset( $fluffless_data['icons']['clipboard-note'] ) && isset( $fluffless_data['i18n']['saveTemplate'], $fluffless_data['i18n']['removeConfirm'] ), 'Données de l’éditeur : droit d’enregistrer, icône et chaînes.' );
	$fluffless_stripped = Editor::strip_starters(
		array(
			array(
				'name'        => $fluffless_names[0],
				'block_types' => array( 'core/post-content' ),
				'post_types'  => array( 'page' ),
			),
		)
	);
	$fluffless_assert( array() === $fluffless_stripped[0]['block_types'], 'Le gabarit ne nourrit pas la fenêtre de départ native.' );

	// 7. REST : POST et DELETE pour un administrateur, refus pour un visiteur, 404 sur un identifiant inconnu.
	rest_get_server();
	$fluffless_routes = rest_get_server()->get_routes( Templates::REST_NS );
	$fluffless_assert( isset( $fluffless_routes[ '/' . Templates::REST_NS . Templates::ROUTE ] ), 'Route POST enregistrée.' );
	$fluffless_request = new WP_REST_Request( 'POST', '/' . Templates::REST_NS . Templates::ROUTE );
	$fluffless_request->set_body_params(
		array(
			'title'   => 'Par REST',
			'content' => $fluffless_content,
		)
	);
	$fluffless_response     = rest_do_request( $fluffless_request );
	$fluffless_body         = $fluffless_response->get_data();
	$fluffless_body_content = is_array( $fluffless_body ) ? (string) ( $fluffless_body['content'] ?? '' ) : '';
	$fluffless_body_ok      = is_array( $fluffless_body ) && 'fluffless-page/par-rest' === ( $fluffless_body['name'] ?? '' ) && true === ( $fluffless_body['saved'] ?? false ) && $fluffless_body_content === $fluffless_content;
	$fluffless_assert( 201 === $fluffless_response->get_status() && $fluffless_body_ok, 'POST : 201 et carte avec contenu.' );
	$fluffless_names[] = 'fluffless-page/par-rest';
	$fluffless_request = new WP_REST_Request( 'POST', '/' . Templates::REST_NS . Templates::ROUTE );
	$fluffless_request->set_body_params( array( 'title' => 'Sans contenu' ) );
	$fluffless_assert( 400 === rest_do_request( $fluffless_request )->get_status(), 'POST sans contenu : 400.' );
	$fluffless_assert( 404 === rest_do_request( new WP_REST_Request( 'DELETE', '/' . Templates::REST_NS . Templates::ROUTE . '/inconnu' ) )->get_status(), 'DELETE inconnu : 404.' );
	$fluffless_response = rest_do_request( new WP_REST_Request( 'DELETE', '/' . Templates::REST_NS . Templates::ROUTE . '/par-rest' ) );
	$fluffless_assert( 200 === $fluffless_response->get_status() && null === Templates::get( 'par-rest' ), 'DELETE : 200 et gabarit retiré.' );
	wp_set_current_user( $fluffless_editor );
	$fluffless_request = new WP_REST_Request( 'POST', '/' . Templates::REST_NS . Templates::ROUTE );
	$fluffless_request->set_body_params(
		array(
			'title'   => 'Interdit',
			'content' => $fluffless_content,
		)
	);
	$fluffless_assert( 403 === rest_do_request( $fluffless_request )->get_status(), 'POST par un compte auteur : 403.' );
	wp_set_current_user( 0 );
	$fluffless_request = new WP_REST_Request( 'POST', '/' . Templates::REST_NS . Templates::ROUTE );
	$fluffless_request->set_body_params(
		array(
			'title'   => 'Interdit',
			'content' => $fluffless_content,
		)
	);
	$fluffless_assert( 401 === rest_do_request( $fluffless_request )->get_status(), 'POST par un visiteur : 401.' );
	$fluffless_assert( 401 === rest_do_request( new WP_REST_Request( 'DELETE', '/' . Templates::REST_NS . Templates::ROUTE . '/equipe' ) )->get_status() && null !== Templates::get( 'equipe' ), 'DELETE par un visiteur : 401, gabarit intact.' );

	// 8. Export et import des réglages : les gabarits voyagent, réassainis.
	wp_set_current_user( $fluffless_admin );
	$fluffless_payload = Transfer::payload();
	$fluffless_assert( 1 === count( $fluffless_payload['page_templates'] ) && 'equipe' === $fluffless_payload['page_templates'][0]['id'], 'Export : gabarits présents.' );
	$fluffless_json = (string) wp_json_encode( $fluffless_payload );
	$fluffless_assert( strlen( $fluffless_json ) < Transfer::MAX_BYTES, 'Export sous la taille maximale d’import.' );
	Templates::store( array() );
	$fluffless_assert( true === Transfer::apply_json( $fluffless_json ) && null !== Templates::get( 'equipe' ), 'Import : gabarit restauré.' );
	$fluffless_payload['page_templates'][] = array(
		'id'      => 'Retouché !',
		'title'   => 'Retouché',
		'content' => 'rien',
	);
	$fluffless_payload['page_templates'][] = array(
		'id'      => 'valide',
		'title'   => 'Valide',
		'content' => $fluffless_content,
	);
	$fluffless_assert( true === Transfer::apply( $fluffless_payload ) && array( 'equipe', 'valide' ) === array_column( Templates::all(), 'id' ), 'Import : entrée invalide écartée, valide gardée.' );
	unset( $fluffless_payload['page_templates'] );
	$fluffless_assert( true === Transfer::apply( $fluffless_payload ) && 2 === count( Templates::all() ), 'Import sans la clé : gabarits en place intacts.' );
	$fluffless_payload['page_templates'] = array();
	$fluffless_assert( true === Transfer::apply( $fluffless_payload ) && array() === Templates::all() && false === get_option( Templates::OPTION, false ), 'Import avec liste vide : option retirée.' );
} finally {
	wp_set_current_user( $fluffless_previous );
	foreach ( $fluffless_names as $fluffless_name ) {
		if ( WP_Block_Patterns_Registry::get_instance()->is_registered( $fluffless_name ) ) {
			unregister_block_pattern( $fluffless_name );
		}
	}
	if ( false === $fluffless_before ) {
		delete_option( Templates::OPTION );
	} else {
		update_option( Templates::OPTION, $fluffless_before, false );
	}
	delete_option( Transfer::PREVIOUS );
	if ( $fluffless_editor > 0 ) {
		wp_delete_user( $fluffless_editor );
	}
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Gabarits de pages : ' . $fluffless_checks . ' vérifications passées.' );
