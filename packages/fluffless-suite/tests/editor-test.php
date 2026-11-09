<?php
/**
 * Tests du module Éditeur : questionnaires, noms de blocs, mode par personne, réglages Simple,
 * retrait des compositions de départ natives.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/editor-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * La personne de test et sa préférence sont supprimées à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Editor;
use Fluffless\Suite\Presentation\Patterns;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Editor::class ) || ! isset( Loader::modules()['editor'] ) ) {
	throw new RuntimeException( 'Module Éditeur absent : activer l\'extension et l\'inscrire dans Loader.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// 1. Questionnaires : 4 à 6 questions par page, textes non vides, noms uniques présents dans la composition.
$fluffless_registry = WP_Block_Patterns_Registry::get_instance();
$fluffless_theme_on = 'fluffless' === get_template();
foreach ( Editor::questions() as $fluffless_page => $fluffless_questions ) {
	$fluffless_count = count( $fluffless_questions );
	$fluffless_assert( $fluffless_count >= 4 && $fluffless_count <= 6, "$fluffless_page : $fluffless_count questions (attendu 4 à 6)" );
	$fluffless_names = array_column( $fluffless_questions, 'name' );
	$fluffless_assert( count( array_unique( $fluffless_names ) ) === $fluffless_count, "$fluffless_page : noms en double" );
	foreach ( $fluffless_questions as $fluffless_q ) {
		$fluffless_assert( '' !== trim( $fluffless_q['label'] ) && '' !== trim( $fluffless_q['example'] ), "$fluffless_page / {$fluffless_q['name']} : libellé ou exemple vide" );
	}
	if ( ! $fluffless_theme_on ) {
		continue;
	}
	$fluffless_pattern = $fluffless_registry->get_registered( $fluffless_page );
	$fluffless_assert( is_array( $fluffless_pattern ), "$fluffless_page : composition non enregistrée" );
	$fluffless_found = array();
	foreach ( parse_blocks( is_array( $fluffless_pattern ) ? (string) $fluffless_pattern['content'] : '' ) as $fluffless_block ) {
		$fluffless_stack = array( $fluffless_block );
		for ( $fluffless_i = 0; $fluffless_stack && $fluffless_i < 1000; $fluffless_i++ ) {
			$fluffless_b = array_pop( $fluffless_stack );
			$fluffless_n = $fluffless_b['attrs']['metadata']['name'] ?? null;
			if ( is_string( $fluffless_n ) ) {
				$fluffless_found[ $fluffless_n ] = $fluffless_b['blockName'];
			}
			array_push( $fluffless_stack, ...$fluffless_b['innerBlocks'] );
		}
	}
	foreach ( $fluffless_names as $fluffless_name ) {
		$fluffless_type = $fluffless_found[ $fluffless_name ] ?? 'absent';
		$fluffless_assert( in_array( $fluffless_type, array( 'core/heading', 'core/paragraph' ), true ), "$fluffless_page : bloc $fluffless_name $fluffless_type (attendu titre ou paragraphe)" );
	}
}
$fluffless_assert( array() === array_diff( array_keys( Editor::questions() ), Editor::PAGES ), 'questionnaires et cartes désaccordés' );
$fluffless_assert( 12 === count( array_unique( Editor::PAGES ) ), 'douze compositions de pages, sans doublon' );
if ( $fluffless_theme_on ) {
	$fluffless_cards = Editor::pages();
	$fluffless_assert( array_slice( array_column( $fluffless_cards, 'name' ), 0, 12 ) === Editor::PAGES, 'écran de départ : ' . count( $fluffless_cards ) . ' cartes (attendu les douze compositions dans l\'ordre)' );
	$fluffless_with = array_filter( $fluffless_cards, static fn( array $c ): bool => array() !== $c['questions'] );
	$fluffless_assert( 7 === count( $fluffless_with ), 'sept cartes avec questionnaire (dont Don, Tarifs et Équipe), les cinq autres de la Suite sans' );
	foreach ( array_slice( $fluffless_cards, 0, 12 ) as $fluffless_card ) {
		$fluffless_assert( '' !== $fluffless_card['title'] && '' !== $fluffless_card['icon'] && ! $fluffless_card['saved'], 'carte ' . $fluffless_card['name'] . ' : titre, icône, pas un gabarit enregistré' );
	}
	$fluffless_assert( Editor::FULL_TEMPLATE === Editor::editor_data()['fullTpl'], 'gabarit sans titre du thème non transmis à l\'éditeur' );
}

// 2. Mode par personne : Simple par défaut, bascule Complet, valeur inconnue ramenée à Simple, Studio l'emporte.
$fluffless_user = wp_insert_user(
	array(
		'user_login' => 'fl-editor-test-' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'editor',
	)
);
if ( is_wp_error( $fluffless_user ) ) {
	throw new RuntimeException( esc_html( 'Personne de test non créée : ' . $fluffless_user->get_error_message() ) );
}
$fluffless_previous = get_current_user_id();
wp_set_current_user( $fluffless_user );
try {
	$fluffless_assert( Editor::SIMPLE === Editor::mode() && Editor::is_simple(), 'défaut : Simple attendu' );
	update_user_meta( $fluffless_user, Editor::MODE_META, Editor::FULL );
	$fluffless_assert( Editor::FULL === Editor::mode() && ! Editor::is_simple(), 'bascule Complet non lue' );
	update_user_meta( $fluffless_user, Editor::MODE_META, 'expert' );
	$fluffless_assert( Editor::SIMPLE === get_user_meta( $fluffless_user, Editor::MODE_META, true ), 'valeur inconnue non assainie à l\'écriture' );
	$fluffless_assert( Editor::SIMPLE === Editor::sanitize_mode( array( 'full' ) ), 'valeur non textuelle acceptée' );
	update_user_meta( $fluffless_user, Lifecycle::USER_META_STUDIO, '1' );
	$fluffless_assert( ! Editor::is_simple(), 'mode Studio : rien ne doit être rangé' );
	delete_user_meta( $fluffless_user, Lifecycle::USER_META_STUDIO );

	// Préférence exposée par REST à la personne elle-même.
	$fluffless_request = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
	$fluffless_request->set_body_params( array( 'meta' => array( Editor::MODE_META => Editor::FULL ) ) );
	$fluffless_response = rest_do_request( $fluffless_request );
	$fluffless_assert( 200 === $fluffless_response->get_status() && Editor::FULL === Editor::mode(), 'écriture REST de la préférence : HTTP ' . $fluffless_response->get_status() );
	$fluffless_request->set_body_params( array( 'meta' => array( Editor::MODE_META => 'expert' ) ) );
	$fluffless_assert( 400 === rest_do_request( $fluffless_request )->get_status(), 'REST : valeur hors liste acceptée' );

	// 3. Réglages : Simple range, Complet laisse, hors page laisse.
	$fluffless_base     = array(
		'__experimentalFeatures' => array(
			'typography' => array(
				'letterSpacing'  => true,
				'customFontSize' => true,
				'fontSizes'      => array( 'theme' => array( 1 ) ),
			),
			'spacing'    => array( 'customSpacingSize' => true ),
			'color'      => array( 'palette' => array( 'theme' => array( 1 ) ) ),
		),
		'codeEditingEnabled'     => true,
	);
	$fluffless_page_ctx = new WP_Block_Editor_Context( array( 'post' => new WP_Post( (object) array( 'post_type' => 'page' ) ) ) );
	$fluffless_post_ctx = new WP_Block_Editor_Context( array( 'post' => new WP_Post( (object) array( 'post_type' => 'post' ) ) ) );
	update_user_meta( $fluffless_user, Editor::MODE_META, Editor::SIMPLE );
	$fluffless_simple = Editor::filter_settings( $fluffless_base, $fluffless_page_ctx );
	$fluffless_typo   = $fluffless_simple['__experimentalFeatures']['typography'];
	$fluffless_assert( false === $fluffless_typo['letterSpacing'] && false === $fluffless_typo['customFontSize'] && false === $fluffless_typo['fontWeight'], 'Simple : typographie fine non rangée' );
	$fluffless_assert( array( 1 ) === $fluffless_typo['fontSizes']['theme'], 'Simple : tailles du thème perdues' );
	$fluffless_assert( false === $fluffless_simple['__experimentalFeatures']['spacing']['customSpacingSize'], 'Simple : espacements libres non rangés' );
	$fluffless_assert( $fluffless_base['__experimentalFeatures']['color'] === $fluffless_simple['__experimentalFeatures']['color'], 'Simple : couleurs modifiées' );
	$fluffless_assert( false === $fluffless_simple['codeEditingEnabled'] && true === $fluffless_simple['disableCustomFontSizes'], 'Simple : éditeur de code ou tailles libres encore offerts' );
	$fluffless_assert( Editor::filter_settings( $fluffless_base, $fluffless_post_ctx ) === $fluffless_base, 'Simple : articles touchés (pages seulement)' );
	update_user_meta( $fluffless_user, Editor::MODE_META, Editor::FULL );
	$fluffless_assert( Editor::filter_settings( $fluffless_base, $fluffless_page_ctx ) === $fluffless_base, 'Complet : réglages modifiés' );

	// 4. Compositions de départ : page seule perd core/post-content ; page parmi d'autres perd la page.
	$fluffless_stripped = Editor::strip_starters(
		array(
			array(
				'block_types' => array( 'core/post-content' ),
				'post_types'  => array( 'page' ),
			),
			array(
				'block_types' => array( 'core/post-content' ),
				'post_types'  => array(),
			),
			array(
				'block_types' => array( 'core/post-content', 'core/group' ),
				'post_types'  => array( 'post', 'page' ),
			),
			array( 'block_types' => array( 'core/group' ) ),
			array(
				'block_types' => array( 'core/post-content' ),
				'post_types'  => array( 'post' ),
			),
		)
	);
	$fluffless_assert( array() === $fluffless_stripped[0]['block_types'] && array() === $fluffless_stripped[1]['block_types'], 'départ natif : pages seules non retirées' );
	$fluffless_assert( array( 'post' ) === $fluffless_stripped[2]['post_types'] && 2 === count( $fluffless_stripped[2]['block_types'] ), 'départ natif : types mixtes mal traités' );
	$fluffless_assert( array( 'core/group' ) === $fluffless_stripped[3]['block_types'] && array( 'post' ) === $fluffless_stripped[4]['post_types'], 'départ natif : composition étrangère modifiée' );

	// Par la vraie route REST : plus aucune composition de départ pour les pages.
	$fluffless_patterns = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/block-patterns/patterns' ) )->get_data();
	$fluffless_starters = array_filter(
		is_array( $fluffless_patterns ) ? $fluffless_patterns : array(),
		static fn( $p ): bool => in_array( 'core/post-content', (array) ( $p['block_types'] ?? array() ), true ) && ( array() === (array) ( $p['post_types'] ?? array() ) || in_array( 'page', (array) $p['post_types'], true ) )
	);
	$fluffless_assert( array() === $fluffless_starters, 'REST : ' . count( $fluffless_starters ) . ' compositions de départ pour les pages encore servies' );
	// L'article de départ reste offert à la création d'un article (fenêtre native, l'écran de départ ne sert que les pages).
	if ( $fluffless_theme_on ) {
		$fluffless_article = array_values( array_filter( is_array( $fluffless_patterns ) ? $fluffless_patterns : array(), static fn( $p ): bool => 'fluffless/article' === ( $p['name'] ?? '' ) ) );
		$fluffless_assert( 1 === count( $fluffless_article ) && array( 'core/post-content' ) === $fluffless_article[0]['block_types'] && array( 'post' ) === $fluffless_article[0]['post_types'], 'REST : article de départ absent ou retiré des articles' );
		$fluffless_assert( isset( $fluffless_article[0] ) && ! preg_match( '/<h1[\s>]/', (string) $fluffless_article[0]['content'] ), 'article de départ : aucun H1, le gabarit d\'article affiche déjà le titre' );

		// Balisage des pages entières : l'analyse puis la sérialisation rendent le même contenu (JSON des blocs lisible, rien de perdu).
		$fluffless_flat = static fn( string $html ): string => trim( (string) preg_replace( '/\s+/', ' ', $html ) );
		foreach ( array_merge( Editor::PAGES, array( 'fluffless/article' ) ) as $fluffless_name ) {
			$fluffless_registered = WP_Block_Patterns_Registry::get_instance()->get_registered( $fluffless_name );
			$fluffless_content    = (string) ( $fluffless_registered['content'] ?? '' );
			$fluffless_assert( '' !== $fluffless_content && $fluffless_flat( $fluffless_content ) === $fluffless_flat( serialize_blocks( parse_blocks( $fluffless_content ) ) ), $fluffless_name . ' : balisage modifié par l\'aller-retour analyse et sérialisation' );
		}
	}

	// 5. Données passées au JS : catégories dans l'ordre, textes présents.
	$fluffless_data = Editor::editor_data();
	$fluffless_assert( Editor::MODE_META === $fluffless_data['meta'] && is_array( $fluffless_data['i18n'] ) && isset( $fluffless_data['i18n']['back'], $fluffless_data['i18n']['addSection'] ), 'données JS incomplètes' );
	$fluffless_assert( array_keys( $fluffless_data['categories'] ) === array_values( array_intersect( Editor::LIBRARY, array_keys( $fluffless_data['categories'] ) ) ), 'catégories hors ordre' );

	// 6. C27 : sections retenues par un module inactif, une tuile par module avec le lien vers son interrupteur.
	$fluffless_assert( is_array( $fluffless_data['locked'] ) && isset( $fluffless_data['i18n']['lockedMany'], $fluffless_data['i18n']['lockedOpen'] ), 'tuile des sections retenues : données absentes' );
	// La tuile n'est montrée qu'à qui gère les réglages : sans manage_options, la liste est vide par conception.
	$fluffless_assert( array() === $fluffless_data['locked'], 'tuile des sections retenues : montrée à une personne sans manage_options' );
	$fluffless_manage = static fn( array $caps ): array => array_merge( $caps, array( 'manage_options' => true ) );
	add_filter( 'user_has_cap', $fluffless_manage );
	$fluffless_data   = Editor::editor_data();
	foreach ( $fluffless_data['locked'] as $fluffless_lock ) {
		$fluffless_assert( $fluffless_lock['count'] > 0 && '' !== $fluffless_lock['label'] && str_contains( $fluffless_lock['url'], '#fl-title-' ), 'tuile des sections retenues : forme invalide' );
	}
	// Un module absent du chargeur (WooCommerce inactif en CI, par exemple) n'a pas de tuile : on ne compte que les modules connus.
	$fluffless_hidden = Patterns::hidden( Editor::LIBRARY );
	$fluffless_assert( count( array_intersect_key( $fluffless_hidden, Loader::modules() ) ) === count( $fluffless_data['locked'] ), 'tuile des sections retenues : décompte différent de Patterns::hidden() : ' . wp_json_encode( array( $fluffless_hidden, array_keys( Loader::modules() ), $fluffless_data['locked'], current_user_can( 'manage_options' ) ) ) );
	remove_filter( 'user_has_cap', $fluffless_manage );
	$fluffless_assert( array_sum( $fluffless_hidden ) <= array_sum( Patterns::hidden() ), 'Patterns::hidden : le filtre de catégories ajoute des patterns' );
	foreach ( Patterns::files() as $fluffless_file ) {
		$fluffless_pattern = Patterns::read( $fluffless_file );
		if ( null !== $fluffless_pattern && ! Patterns::modules_active( $fluffless_pattern ) ) {
			$fluffless_assert( array() !== array_intersect_key( Patterns::hidden(), array_flip( (array) $fluffless_pattern['modules'] ) ), 'Patterns::hidden : pattern retenu non compté' );
		}
	}
} finally {
	wp_set_current_user( $fluffless_previous );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $fluffless_user );
}

if ( $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Éditeur : échecs\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Éditeur : $fluffless_checks vérifications." );
