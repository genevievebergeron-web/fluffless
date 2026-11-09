<?php
/**
 * Tests des Trousses de site : contrat, trousses livrées, vérification d'une trousse, filtre,
 * import des pages (brouillons, publication, page d'accueil, adresses laissées telles quelles),
 * palette, polices et forme selon les modules, formulaire et écran.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/kits-test.php
 * Les pages créées sont supprimées à la fin ; réglages, styles globaux et options restaurés.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\GlobalStyles;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Editor;
use Fluffless\Suite\Modules\Fonts;
use Fluffless\Suite\Modules\Kits;
use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Modules\Shape;

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les textes en en_US : indépendants de la langue du site.
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_set = static function ( string $id, bool $enabled ): void {
	$all = get_option( Options::NAME );
	$all = is_array( $all ) ? $all : array();
	if ( ! isset( $all['modules'] ) || ! is_array( $all['modules'] ) ) {
		$all['modules'] = array();
	}
	$all['modules'][ $id ] = $enabled;
	update_option( Options::NAME, $all );
	Options::flush();
};

$fluffless_settings_before = get_option( Options::NAME );
$fluffless_styles_before   = GlobalStyles::user();
$fluffless_palette_before  = get_option( Palette::OPTION );
$fluffless_shape_before    = get_option( Shape::OPTION );
$fluffless_front_before    = array( get_option( 'show_on_front' ), get_option( 'page_on_front' ) );
$fluffless_created         = array();
$fluffless_user_before     = get_current_user_id();
$fluffless_nav_id          = function_exists( 'fluffless_navigation_id' ) ? fluffless_navigation_id() : 0;
$fluffless_nav_before      = $fluffless_nav_id > 0 ? (string) get_post_field( 'post_content', $fluffless_nav_id ) : '';

$fluffless_test_kit = static function ( string $suffix, bool $front = true ): array {
	return array(
		'id'          => 'fl-test-' . $suffix,
		'label'       => 'Trousse de test',
		'description' => 'Pour les tests.',
		'pages'       => array(
			array(
				'pattern' => 'fluffless/page-accueil',
				'title'   => 'Accueil de test',
				'slug'    => 'fl-kit-test-' . $suffix . '-accueil',
				'front'   => $front,
			),
			array(
				'pattern' => 'fluffless/page-contact',
				'title'   => 'Contact de test',
				'slug'    => 'fl-kit-test-' . $suffix . '-contact',
			),
		),
		'palette'     => Kits::builtin()[0]['palette'],
		'fonts'       => 2,
		'shape'       => 'pill',
	);
};

try {
	// 1. Contrat et branchement.
	$fluffless_assert( 'kits' === Kits::id() && Kits::enabled_by_default(), 'Module kits, actif par défaut.' );
	$fluffless_assert( array() === Kits::defaults() && array() === Kits::fields() && '' === Kits::status(), 'Aucun réglage, aucun état.' );
	$fluffless_assert( array() === Kits::sanitize( array( 'x' => 1 ) ), 'Section assainie vide.' );
	$fluffless_assert( isset( Loader::modules()['kits'] ) && Kits::class === Loader::modules()['kits'], 'Déclaré dans le Loader.' );
	$fluffless_assert( array( 'pages', 'palette', 'fonts', 'shape' ) === Kits::PARTS, 'Quatre parties dans l’ordre.' ); // @phpstan-ignore identical.alwaysTrue

	// 2. Trousses livrées : quatre secteurs, toutes valides.
	$fluffless_kits = Kits::all();
	$fluffless_assert( array( 'nonprofit', 'artisan', 'consultant', 'restaurant' ) === array_keys( $fluffless_kits ), 'Quatre trousses : organisme, artisan, consultante, restaurant.' );
	$fluffless_assert( count( Kits::builtin() ) === count( $fluffless_kits ), 'Aucune trousse livrée écartée.' );
	$fluffless_registry = WP_Block_Patterns_Registry::get_instance();
	foreach ( $fluffless_kits as $fluffless_id => $fluffless_kit ) {
		$fluffless_fronts = array_sum( array_map( static fn( array $p ): int => $p['front'] ? 1 : 0, $fluffless_kit['pages'] ) );
		$fluffless_assert( count( $fluffless_kit['pages'] ) >= 4 && 1 === $fluffless_fronts && 'fluffless/page-accueil' === $fluffless_kit['pages'][0]['pattern'] && $fluffless_kit['pages'][0]['front'], $fluffless_id . ' : au moins quatre pages, l’accueil en tête et seule page d’accueil.' );
		$fluffless_ok = true;
		foreach ( $fluffless_kit['pages'] as $fluffless_page ) {
			$fluffless_ok = $fluffless_ok && $fluffless_registry->is_registered( $fluffless_page['pattern'] ) && '' !== $fluffless_page['title'] && sanitize_title( $fluffless_page['slug'] ) === $fluffless_page['slug'];
		}
		$fluffless_assert( $fluffless_ok, $fluffless_id . ' : compositions enregistrées, titres et adresses propres.' );
		$fluffless_assert( array() === Palette::failures( $fluffless_kit['palette'] ) && 9 === count( $fluffless_kit['palette'] ), $fluffless_id . ' : palette complète qui passe les contrastes.' );
		$fluffless_assert( isset( Fonts::PAIRS[ $fluffless_kit['fonts'] ] ) && isset( Shape::PRESETS[ $fluffless_kit['shape'] ] ), $fluffless_id . ' : duo de polices et forme connus.' );
		$fluffless_assert( '' !== $fluffless_kit['description'] && $fluffless_kit['label'] !== $fluffless_id, $fluffless_id . ' : libellé et description.' );
	}
	$fluffless_assert( count( array_unique( array_column( array_values( $fluffless_kits ), 'fonts' ) ) ) === count( $fluffless_kits ) && count( array_unique( array_column( array_values( $fluffless_kits ), 'shape' ) ) ) === count( $fluffless_kits ), 'Chaque trousse a son duo et sa forme.' );
	$fluffless_assert( null !== Kits::get( 'restaurant' ) && null === Kits::get( 'inconnue' ), 'get par identifiant.' );
	// C1-9-02 : la page Menu du Restaurant est une carte (plats, descriptions, prix), pas la page Services.
	$fluffless_menu_page = array_values( array_filter( Kits::get( 'restaurant' )['pages'] ?? array(), static fn( array $page ): bool => _x( 'menu', 'page address', 'fluffless-suite' ) === $page['slug'] ) )[0] ?? array();
	$fluffless_menu_pat  = (array) WP_Block_Patterns_Registry::get_instance()->get_registered( 'fluffless/page-menu' );
	$fluffless_menu_html = (string) ( $fluffless_menu_pat['content'] ?? '' );
	$fluffless_assert( 'fluffless/page-menu' === ( $fluffless_menu_page['pattern'] ?? '' ), 'Restaurant : la page Menu vient de la composition de carte (C1-9-02).' );
	$fluffless_assert( substr_count( $fluffless_menu_html, 'fl-menu-dish' ) >= 6 && preg_match_all( '/\$\d+/', $fluffless_menu_html ) >= 6 && str_contains( $fluffless_menu_html, 'allerg' ), 'Carte : au moins six plats avec prix, consigne sur les allergènes (C1-9-02).' );
	$fluffless_assert( array() !== parse_blocks( $fluffless_menu_html ) && ! str_contains( $fluffless_menu_html, 'Choose your plan' ), 'Carte : blocs valides, sans les forfaits de la page Tarifs (C1-9-02).' );

	// 3. Vérification d'une trousse.
	$fluffless_base = $fluffless_test_kit( 'v' );
	$fluffless_assert( null !== Kits::sanitize_kit( $fluffless_base ), 'Trousse de test acceptée.' );
	$fluffless_assert( null === Kits::sanitize_kit( array_merge( $fluffless_base, array( 'id' => '' ) ) ), 'Refusée sans identifiant.' );
	$fluffless_assert(
		null === Kits::sanitize_kit(
			array_merge(
				$fluffless_base,
				array(
					'pages' => array(
						array(
							'pattern' => 'fluffless/inconnue',
							'slug'    => 'x',
						),
					),
				)
			)
		),
		'Refusée sans composition enregistrée.'
	);
	$fluffless_assert( null === Kits::sanitize_kit( array_merge( $fluffless_base, array( 'palette' => array_merge( $fluffless_base['palette'], array( 'contrast' => $fluffless_base['palette']['base'] ) ) ) ) ), 'Refusée quand la palette rate un contraste.' );
	$fluffless_assert( null === Kits::sanitize_kit( array_merge( $fluffless_base, array( 'palette' => array( 'base' => '#fff' ) ) ) ), 'Refusée avec une palette incomplète.' );
	$fluffless_assert( null === Kits::sanitize_kit( array_merge( $fluffless_base, array( 'shape' => 'hexagone' ) ) ), 'Refusée avec une forme inconnue.' );
	$fluffless_assert( null === Kits::sanitize_kit( array_merge( $fluffless_base, array( 'fonts' => 99 ) ) ), 'Refusée avec un duo inconnu.' );
	$fluffless_loose            = $fluffless_base;
	$fluffless_loose['fonts']   = '3';
	$fluffless_loose['pages'][] = array(
		'pattern' => 'fluffless/page-services',
		'slug'    => '',
	);
	$fluffless_loose['pages'][] = array(
		'pattern' => 'fluffless/page-services',
		'slug'    => 'Services de Test',
		'front'   => true,
	);
	$fluffless_clean            = Kits::sanitize_kit( $fluffless_loose );
	$fluffless_assert( null !== $fluffless_clean && 3 === $fluffless_clean['fonts'], 'Indice de duo numérique accepté.' );
	$fluffless_assert( null !== $fluffless_clean && 3 === count( $fluffless_clean['pages'] ) && 'services-de-test' === $fluffless_clean['pages'][2]['slug'], 'Page sans adresse écartée, adresse normalisée.' );
	// Le titre par défaut est celui de la composition, enregistrée à l'init dans la langue du site.
	$fluffless_services = (array) WP_Block_Patterns_Registry::get_instance()->get_registered( 'fluffless/page-services' );
	$fluffless_assert( null !== $fluffless_clean && ! $fluffless_clean['pages'][2]['front'] && '' !== (string) ( $fluffless_services['title'] ?? '' ) && $fluffless_services['title'] === $fluffless_clean['pages'][2]['title'], 'Une seule page d’accueil ; titre de la composition par défaut.' );

	// 4. Filtre fluffless_kits.
	$fluffless_filter = static fn(): array => array( $fluffless_test_kit( 'f' ), array( 'id' => 'cassée' ), 'rien' );
	add_filter( 'fluffless_kits', $fluffless_filter );
	$fluffless_assert( array( 'fl-test-f' ) === array_keys( Kits::all() ), 'Le filtre remplace les trousses ; les entrées invalides sont écartées.' );
	remove_filter( 'fluffless_kits', $fluffless_filter );

	// 5. Import des pages en brouillon, puis adresses laissées telles quelles.
	$fluffless_kit_a = Kits::sanitize_kit( $fluffless_test_kit( 'a' ) );
	if ( null === $fluffless_kit_a ) {
		throw new RuntimeException( 'Trousse de test invalide.' );
	}
	$fluffless_result  = Kits::import( $fluffless_kit_a, array( 'pages' ), false );
	$fluffless_created = array_merge( $fluffless_created, $fluffless_result['created'] );
	$fluffless_assert( 2 === count( $fluffless_result['created'] ) && array() === $fluffless_result['skipped'] && ! $fluffless_result['front'], 'Deux pages créées, aucune laissée, accueil non touché.' );
	$fluffless_assert( 'skip' === $fluffless_result['palette'] && 'skip' === $fluffless_result['fonts'] && 'skip' === $fluffless_result['shape'], 'Parties non demandées : skip.' );
	$fluffless_post = get_post( $fluffless_result['created'][0] );
	$fluffless_assert( $fluffless_post instanceof WP_Post && 'draft' === $fluffless_post->post_status && 'page' === $fluffless_post->post_type && 'Accueil de test' === $fluffless_post->post_title, 'Page en brouillon, type page, titre de la trousse.' );
	$fluffless_assert( $fluffless_post instanceof WP_Post && 'fl-kit-test-a-accueil' === $fluffless_post->post_name, 'Adresse de la trousse.' );
	$fluffless_pattern  = $fluffless_registry->get_registered( 'fluffless/page-accueil' );
	$fluffless_sections = static function ( string $content ): array {
		$locks = array();
		foreach ( parse_blocks( $content ) as $block ) {
			if ( in_array( $block['blockName'], array( 'core/group', 'core/cover', 'core/columns', 'core/media-text' ), true ) ) {
				$locks[] = (string) ( $block['attrs']['templateLock'] ?? '' );
			}
		}
		return $locks;
	};
	$fluffless_locks    = $fluffless_post instanceof WP_Post ? $fluffless_sections( $fluffless_post->post_content ) : array();
	$fluffless_assert( array() !== $fluffless_locks && array( 'contentOnly' ) === array_values( array_unique( $fluffless_locks ) ), 'Mode Simple : sections de premier niveau verrouillées en contentOnly (P4-02).' );
	$fluffless_assert( $fluffless_post instanceof WP_Post && is_array( $fluffless_pattern ) && Kits::lock_sections( $fluffless_pattern['content'] ) === $fluffless_post->post_content && str_contains( $fluffless_post->post_content, '<!-- wp:' ), 'Contenu de la composition, en blocs.' );
	$fluffless_assert( is_array( $fluffless_pattern ) && $fluffless_sections( $fluffless_pattern['content'] ) !== $fluffless_locks && wp_strip_all_tags( $fluffless_pattern['content'] ) === wp_strip_all_tags( Kits::lock_sections( $fluffless_pattern['content'] ) ), 'Le verrou ne change que les attributs des sections (P4-02).' );
	$fluffless_assert( $fluffless_post instanceof WP_Post && Editor::FULL_TEMPLATE === get_page_template_slug( $fluffless_post ), 'Gabarit pleine largeur du thème.' );
	$fluffless_again = Kits::import( $fluffless_kit_a, array( 'pages' ), true );
	$fluffless_assert( array() === $fluffless_again['created'] && array( 'fl-kit-test-a-accueil', 'fl-kit-test-a-contact' ) === $fluffless_again['skipped'] && ! $fluffless_again['front'], 'Second import : rien de créé, adresses laissées telles quelles, brouillons intacts.' );
	$fluffless_assert( 'draft' === get_post_status( $fluffless_result['created'][0] ), 'Le brouillon existant n’est pas publié par le second import.' );

	// P4-02 : en mode Complet, rien n'est verrouillé, comme à l'insertion dans l'éditeur.
	$fluffless_full_admin = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$fluffless_full_id    = (int) ( $fluffless_full_admin[0] ?? 1 );
	$fluffless_mode_meta  = get_user_meta( $fluffless_full_id, Editor::MODE_META, true );
	update_user_meta( $fluffless_full_id, Editor::MODE_META, Editor::FULL );
	wp_set_current_user( $fluffless_full_id );
	$fluffless_kit_e   = Kits::sanitize_kit( $fluffless_test_kit( 'e' ) );
	$fluffless_result  = null === $fluffless_kit_e ? array( 'created' => array() ) : Kits::import( $fluffless_kit_e, array( 'pages' ), false );
	$fluffless_created = array_merge( $fluffless_created, $fluffless_result['created'] );
	wp_set_current_user( 0 );
	if ( '' === $fluffless_mode_meta ) {
		delete_user_meta( $fluffless_full_id, Editor::MODE_META );
	} else {
		update_user_meta( $fluffless_full_id, Editor::MODE_META, $fluffless_mode_meta );
	}
	$fluffless_post_e = isset( $fluffless_result['created'][0] ) ? get_post( $fluffless_result['created'][0] ) : null;
	$fluffless_assert( $fluffless_post_e instanceof WP_Post && ! in_array( 'contentOnly', $fluffless_sections( $fluffless_post_e->post_content ), true ), 'Mode Complet : sections non verrouillées (P4-02).' );

	// P4-02 : une composition d'agence avec des tirets doubles échappés, & ou < dans ses attributs arrive intacte, en Simple comme en Complet.
	$fluffless_amp_content = '<!-- wp:group {"style":{"color":{"background":"var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dcolor\u002d\u002dbase)"}},"className":"a\u0026b \u003cc\u003e","layout":{"type":"constrained"}} --><div class="wp-block-group a&amp;b &lt;c&gt;"><!-- wp:paragraph --><p>Prix &amp; forfaits</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
	register_block_pattern(
		'fluffless/fl-test-esperluette',
		array(
			'title'   => 'Esperluette de test',
			'content' => $fluffless_amp_content,
		)
	);
	$fluffless_amp_kit    = static function ( string $suffix ) use ( $fluffless_test_kit ): ?array {
		$kit          = $fluffless_test_kit( $suffix, false );
		$kit['pages'] = array(
			array(
				'pattern' => 'fluffless/fl-test-esperluette',
				'title'   => 'Esperluette ' . $suffix,
				'slug'    => 'fl-kit-test-' . $suffix . '-esperluette',
			),
		);
		return Kits::sanitize_kit( $kit );
	};
	$fluffless_amp_stored = array();
	$fluffless_amp_modes  = array(
		'g' => Editor::SIMPLE,
		'h' => Editor::FULL,
	);
	foreach ( $fluffless_amp_modes as $fluffless_suffix => $fluffless_mode ) {
		update_user_meta( $fluffless_full_id, Editor::MODE_META, $fluffless_mode );
		wp_set_current_user( $fluffless_full_id );
		$fluffless_kit_amp                       = $fluffless_amp_kit( $fluffless_suffix );
		$fluffless_result                        = null === $fluffless_kit_amp ? array( 'created' => array() ) : Kits::import( $fluffless_kit_amp, array( 'pages' ), false );
		$fluffless_created                       = array_merge( $fluffless_created, $fluffless_result['created'] );
		$fluffless_amp_stored[ $fluffless_mode ] = isset( $fluffless_result['created'][0] ) ? (string) get_post_field( 'post_content', $fluffless_result['created'][0] ) : '';
	}
	wp_set_current_user( 0 );
	if ( '' === $fluffless_mode_meta ) {
		delete_user_meta( $fluffless_full_id, Editor::MODE_META );
	} else {
		update_user_meta( $fluffless_full_id, Editor::MODE_META, $fluffless_mode_meta );
	}
	unregister_block_pattern( 'fluffless/fl-test-esperluette' );
	$fluffless_assert( Kits::lock_sections( $fluffless_amp_content ) === $fluffless_amp_stored[ Editor::SIMPLE ], 'Mode Simple : attributs avec tirets doubles échappés, & et < intacts après le verrou (P4-02).' );
	$fluffless_assert( $fluffless_amp_content === $fluffless_amp_stored[ Editor::FULL ], 'Mode Complet : composition avec tirets doubles échappés, & et < enregistrée telle quelle.' );

	// 6. Publication et page d'accueil réglée seulement si le site n'en a pas.
	update_option( 'show_on_front', 'posts' );
	update_option( 'page_on_front', 0 );
	$fluffless_kit_b = Kits::sanitize_kit( $fluffless_test_kit( 'b' ) );
	if ( null === $fluffless_kit_b ) {
		throw new RuntimeException( 'Trousse de test invalide.' );
	}
	$fluffless_result  = Kits::import( $fluffless_kit_b, array( 'pages' ), true );
	$fluffless_created = array_merge( $fluffless_created, $fluffless_result['created'] );
	$fluffless_assert( 2 === count( $fluffless_result['created'] ) && $fluffless_result['front'], 'Pages publiées, page d’accueil réglée.' );
	$fluffless_assert( 'publish' === get_post_status( $fluffless_result['created'][0] ) && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $fluffless_result['created'][0], 'Réglages de lecture : page statique, la page d’accueil de la trousse.' );
	// C1-9-01 : les pages créées, sauf l'accueil, rejoignent le menu partagé du thème.
	if ( $fluffless_nav_id > 0 ) {
		$fluffless_nav_now = (string) get_post_field( 'post_content', $fluffless_nav_id );
		$fluffless_assert( str_contains( $fluffless_nav_now, '"label":"Contact de test"' ) && ! str_contains( $fluffless_nav_now, '"label":"Accueil de test"' ), 'Pages de la trousse ajoutées au menu, sauf l’accueil (C1-9-01).' );
	}
	$fluffless_kit_c = Kits::sanitize_kit( $fluffless_test_kit( 'c' ) );
	if ( null === $fluffless_kit_c ) {
		throw new RuntimeException( 'Trousse de test invalide.' );
	}
	$fluffless_result  = Kits::import( $fluffless_kit_c, array( 'pages' ), true );
	$fluffless_created = array_merge( $fluffless_created, $fluffless_result['created'] );
	$fluffless_assert( 2 === count( $fluffless_result['created'] ) && ! $fluffless_result['front'] && (int) get_option( 'page_on_front' ) !== $fluffless_result['created'][0], 'Une page d’accueil déjà réglée n’est jamais remplacée.' );
	$fluffless_kit_d = Kits::sanitize_kit( $fluffless_test_kit( 'd' ) );
	update_option( 'show_on_front', 'posts' );
	update_option( 'page_on_front', 0 );
	if ( null === $fluffless_kit_d ) {
		throw new RuntimeException( 'Trousse de test invalide.' );
	}
	$fluffless_result  = Kits::import( $fluffless_kit_d, array( 'pages' ), false );
	$fluffless_created = array_merge( $fluffless_created, $fluffless_result['created'] );
	$fluffless_assert( ! $fluffless_result['front'] && 'posts' === get_option( 'show_on_front' ), 'Un brouillon ne devient jamais page d’accueil.' );
	update_option( 'show_on_front', $fluffless_front_before[0] );
	update_option( 'page_on_front', $fluffless_front_before[1] );

	// 7. Palette, polices et forme : selon les modules.
	$fluffless_set( Palette::id(), false );
	$fluffless_set( Fonts::id(), false );
	$fluffless_set( Shape::id(), false );
	$fluffless_result = Kits::import( $fluffless_kit_a, array( 'palette', 'fonts', 'shape' ), false );
	$fluffless_assert( 'off' === $fluffless_result['palette'] && 'off' === $fluffless_result['fonts'] && 'off' === $fluffless_result['shape'] && array() === $fluffless_result['created'] && array() === $fluffless_result['skipped'], 'Modules éteints : rien d’appliqué, pages non touchées.' );
	$fluffless_set( Palette::id(), true );
	$fluffless_set( Shape::id(), true );
	$fluffless_result = Kits::import( $fluffless_kit_a, array( 'palette', 'shape' ), false );
	$fluffless_assert( 'ok' === $fluffless_result['palette'] && 'ok' === $fluffless_result['shape'] && 'skip' === $fluffless_result['fonts'], 'Palette et forme appliquées.' );
	$fluffless_current_palette = Palette::current();
	$fluffless_assert( $fluffless_current_palette === $fluffless_kit_a['palette'], 'La palette du site est celle de la trousse.' );
	$fluffless_assert( 'pill' === Shape::current_state()['preset'], 'La forme du site est celle de la trousse.' );
	$fluffless_assert( Palette::reset() && Shape::reset(), 'Chaque partie se défait de son écran.' );
	$fluffless_assert( 'pill' !== Shape::current_state()['preset'], 'Forme d’avant revenue.' );

	// 8. Formulaire : trousse inconnue, rien de coché, import complet.
	$fluffless_url = Kits::run( array( 'kit' => 'inconnue' ) );
	$fluffless_assert( str_contains( $fluffless_url, 'fl_kit_error=unknown' ) && str_contains( $fluffless_url, 'page=' . Kits::PAGE ), 'Trousse inconnue signalée.' );
	$fluffless_url = Kits::run(
		array(
			'kit'   => 'nonprofit',
			'parts' => array( 'rien' ),
		)
	);
	$fluffless_assert( str_contains( $fluffless_url, 'fl_kit_error=nothing' ), 'Rien de coché signalé.' );
	add_filter( 'fluffless_kits', $fluffless_filter );
	$fluffless_url = Kits::run(
		array(
			'kit'     => 'fl-test-f',
			'parts'   => array( 'pages', 'shape', 'x' ),
			'publish' => '1',
		)
	);
	remove_filter( 'fluffless_kits', $fluffless_filter );
	$fluffless_page_f = get_page_by_path( 'fl-kit-test-f-accueil', OBJECT, 'page' );
	if ( $fluffless_page_f instanceof WP_Post ) {
		$fluffless_created[] = $fluffless_page_f->ID;
	}
	$fluffless_page_f2 = get_page_by_path( 'fl-kit-test-f-contact', OBJECT, 'page' );
	if ( $fluffless_page_f2 instanceof WP_Post ) {
		$fluffless_created[] = $fluffless_page_f2->ID;
	}
	$fluffless_query = wp_parse_url( $fluffless_url, PHP_URL_QUERY );
	parse_str( is_string( $fluffless_query ) ? $fluffless_query : '', $fluffless_args );
	$fluffless_assert( 'fl-test-f' === ( $fluffless_args['fl_kit'] ?? '' ) && '2' === ( $fluffless_args['fl_created'] ?? '' ) && '0' === ( $fluffless_args['fl_skipped'] ?? '' ) && 'ok' === ( $fluffless_args['fl_shape'] ?? '' ) && 'skip' === ( $fluffless_args['fl_palette'] ?? '' ) && 'skip' === ( $fluffless_args['fl_fonts'] ?? '' ), 'Retour avec le résultat en paramètres.' );
	$fluffless_assert( $fluffless_page_f instanceof WP_Post && 'publish' === $fluffless_page_f->post_status, 'Pages publiées par le formulaire.' );
	Shape::reset();

	// C2-3-02 : la page d'exemple de l'installation ne compte pas, comme dans Home::is_own_page().
	$fluffless_probe_id   = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Sonde C2-3-02',
		)
	);
	$fluffless_created[]  = (int) $fluffless_probe_id;
	$fluffless_fake_pages = static function ( array $names ) use ( $fluffless_probe_id ): callable {
		return static function ( $posts, WP_Query $query ) use ( $names, $fluffless_probe_id ) {
			if ( 'page' !== $query->get( 'post_type' ) ) {
				return $posts;
			}
			return array_map(
				static function ( string $name ) use ( $fluffless_probe_id ): WP_Post {
					$probe = get_post( (int) $fluffless_probe_id );
					if ( ! $probe instanceof WP_Post ) {
						throw new RuntimeException( 'Sonde C2-3-02 absente.' );
					}
					$fake              = clone $probe;
					$fake->post_name   = $name;
					$fake->post_status = 'publish';
					$fake->filter      = 'raw';
					return $fake;
				},
				$names
			);
		};
	};
	$fluffless_pre        = $fluffless_fake_pages( array( 'sample-page' ) );
	add_filter( 'posts_pre_query', $fluffless_pre, 10, 2 );
	$fluffless_assert( Kits::site_is_empty(), 'Site vide quand seule la page d’exemple est publiée (C2-3-02).' );
	remove_filter( 'posts_pre_query', $fluffless_pre, 10 );
	$fluffless_pre = $fluffless_fake_pages( array( 'sample-page', 'nos-services' ) );
	add_filter( 'posts_pre_query', $fluffless_pre, 10, 2 );
	$fluffless_assert( ! Kits::site_is_empty(), 'Site non vide dès qu’une page à soi est publiée (C2-3-02).' );
	remove_filter( 'posts_pre_query', $fluffless_pre, 10 );

	// 9. Écran : rien pour un visiteur, formulaire complet pour qui gère les options.
	wp_set_current_user( 0 );
	ob_start();
	Kits::render();
	$fluffless_assert( '' === (string) ob_get_clean(), 'Écran vide pour un visiteur.' );
	$fluffless_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 1 ) );
	ob_start();
	Kits::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html, 'name="action" value="' . Kits::ACTION . '"' ) && str_contains( $fluffless_html, '_wpnonce' ), 'Formulaire admin-post avec nonce.' );
	$fluffless_assert( 4 === substr_count( $fluffless_html, 'type="radio" name="kit"' ) && str_contains( $fluffless_html, 'value="nonprofit" checked' ), 'Quatre trousses, la première cochée.' );
	$fluffless_assert( 4 === substr_count( $fluffless_html, 'name="parts[]"' ) && str_contains( $fluffless_html, 'id="fl-kit-publish"' ), 'Quatre parties et la case de publication.' );
	// P9a-04 : la case de publication n'est jamais cochée d'office, même sur un site sans page publiée
	// (site vide simulé comme pour C2-3-02 : aucune page publiée renvoyée à site_is_empty()).
	$fluffless_pre = $fluffless_fake_pages( array() );
	add_filter( 'posts_pre_query', $fluffless_pre, 10, 2 );
	ob_start();
	Kits::render();
	$fluffless_empty_html = (string) ob_get_clean();
	remove_filter( 'posts_pre_query', $fluffless_pre, 10 );
	$fluffless_assert( (bool) preg_match( '/id="fl-kit-publish"[^>]*>/', $fluffless_empty_html, $fluffless_box ) && ! str_contains( $fluffless_box[0], 'checked' ), 'Case de publication décochée d’office, même sur un site vide (P9a-04).' );
	$fluffless_assert( str_contains( $fluffless_empty_html, 'The site has no published page yet.' ), 'Site vide bien simulé : la mention du site sans page publiée s’affiche (P9a-04).' );
	$fluffless_assert( str_contains( $fluffless_html, 'Publish the pages right away (they contain instructions to replace).' ), 'Libellé de la case : les pages contiennent des consignes (P9a-04).' );
	$fluffless_assert( (bool) preg_match( '/id="fl-kit-publish"[^>]*>/', $fluffless_html, $fluffless_box ) && ! str_contains( $fluffless_box[0], 'checked' ), 'Case de publication décochée d’office (P9a-04).' );
	$fluffless_assert( str_contains( $fluffless_html, 'value="fonts" disabled' ) && str_contains( $fluffless_html, 'value="palette" checked' ), 'Partie sans module désactivée, les autres cochées.' );
	$fluffless_assert( 36 === substr_count( $fluffless_html, 'class="fl-swatch"' ) && str_contains( $fluffless_html, '<code>/' . _x( 'donate', 'page address', 'fluffless-suite' ) . '/</code>' ), 'Neuf pastilles par trousse, adresses affichées (traduites : /don/ en français).' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ), 'Aucun script.' );
	// C2-3-06 : l'écran ne promet plus que rien n'est écrasé (palette, polices et forme remplacent l'état courant).
	$fluffless_assert( ! str_contains( $fluffless_html, 'overwritten' ) && str_contains( $fluffless_html, 'replace the current ones' ) && str_contains( $fluffless_html, 'style revisions' ), 'Écran : palette, polices et forme remplacent l’état courant, sans promesse « ni écrasé » (C2-3-06).' );
	// C1-9-01 : l'écran annonce l'ajout des pages au menu partagé.
	$fluffless_assert( str_contains( $fluffless_html, 'added to the menu shared by the header and the footers' ) && ! str_contains( $fluffless_html, 'The header menu keeps its links' ), 'Écran : les pages de la trousse vont au menu partagé (C1-9-01).' );
	$_GET['fl_kit']     = 'nonprofit';
	$_GET['fl_created'] = '3';
	$_GET['fl_skipped'] = '2';
	$_GET['fl_front']   = '1';
	$_GET['fl_palette'] = 'ok';
	$_GET['fl_fonts']   = 'download';
	$_GET['fl_shape']   = 'skip';
	ob_start();
	Kits::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html, 'fl-note--warn' ) && str_contains( $fluffless_html, '3 pages created.' ) && str_contains( $fluffless_html, '2 pages already existed' ) && str_contains( $fluffless_html, 'The home page is set.' ) && str_contains( $fluffless_html, 'could not be downloaded' ) && ! str_contains( $fluffless_html, 'Shape :' ), 'Résultat lu dans l’adresse, avertissement quand une partie manque.' );
	$_GET                 = array();
	$_GET['fl_kit_error'] = 'unknown';
	ob_start();
	Kits::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html, 'fl-note--bad' ) && str_contains( $fluffless_html, 'Unknown kit.' ), 'Erreur affichée.' );
	$_GET = array();
} finally {
	if ( $fluffless_nav_id > 0 ) {
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $fluffless_nav_id,
					'post_content' => $fluffless_nav_before,
				)
			)
		);
	}
	foreach ( array_unique( $fluffless_created ) as $fluffless_id ) {
		wp_delete_post( $fluffless_id, true );
	}
	update_option( 'show_on_front', $fluffless_front_before[0] );
	update_option( 'page_on_front', $fluffless_front_before[1] );
	if ( null !== $fluffless_styles_before ) {
		GlobalStyles::save( $fluffless_styles_before['id'], $fluffless_styles_before['data'] );
	}
	if ( false === $fluffless_palette_before ) {
		delete_option( Palette::OPTION );
	} else {
		update_option( Palette::OPTION, $fluffless_palette_before, false );
	}
	if ( false === $fluffless_shape_before ) {
		delete_option( Shape::OPTION );
	} else {
		update_option( Shape::OPTION, $fluffless_shape_before, false );
	}
	if ( false === $fluffless_settings_before ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_settings_before );
	}
	Options::flush();
	wp_set_current_user( $fluffless_user_before );
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Trousses de site : ' . $fluffless_checks . ' vérifications passées.' );
