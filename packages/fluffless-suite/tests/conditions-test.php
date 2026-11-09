<?php
/**
 * Tests du module Conditions d'affichage : règles assainies et bornées, correspondance à la visite
 * (page précise, type, catégorie, recherche, 404, page des articles), première règle gagnante,
 * gabarit mis en tête de la hiérarchie, en-tête remplacé sur la seule partie « header », écran
 * d'administration rendu avec ses choix, option retirée à la désinstallation.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/conditions-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Conditions;

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

// Charge une requête comme si le visiteur ouvrait cette adresse.
$fluffless_visit = static function ( array $args, bool $not_found = false ): void {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	$GLOBALS['wp_query'] = new WP_Query( $args );
	if ( $not_found ) {
		$GLOBALS['wp_query']->set_404();
	}
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	Conditions::reset();
};

$fluffless_page_id = (int) get_posts(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
)[0];
if ( 0 === $fluffless_page_id ) {
	throw new RuntimeException( 'Aucune page publiée pour le test.' );
}
$fluffless_saved = get_option( Lifecycle::OPTION_CONDITIONS, null );
( new Conditions() )->register();

try {
	// 1. Module déclaré, désactivé par défaut, sans réglage propre.
	$fluffless_assert( in_array( Conditions::class, array_keys( Loader::modules() ), true ) || in_array( 'conditions', array_keys( Loader::modules() ), true ), 'module absent du Loader' );
	$fluffless_assert( ! Conditions::enabled_by_default() && array() === Conditions::defaults() && array() === Conditions::fields(), 'module actif d\'office ou avec réglages' );
	$fluffless_assert( 'conditions' === Conditions::id() && '' !== Conditions::label(), 'identifiant ou libellé' );

	// 2. Assainissement : cibles reconnues, identifiants, slugs, règles vides retirées, borne.
	$fluffless_rules = Conditions::sanitize_rules(
		array(
			array(
				'where'    => 'pages',
				'ids'      => ' 12, 0, abc; 12 34 ',
				'template' => 'page-sections',
				'part'     => '',
			),
			array(
				'where'    => 'type:page',
				'template' => 'Page Sections',
				'part'     => 'header-boutique',
			),
			array(
				'where'    => 'term:category:1',
				'template' => '',
				'part'     => 'header-centre',
			),
			array(
				'where'    => 'tax:category',
				'template' => str_repeat( 'a', 81 ),
			),
			array(
				'where'    => 'pages',
				'ids'      => array(),
				'template' => 'x',
			),
			array(
				'where'    => 'type:nope',
				'template' => 'x',
			),
			array(
				'where'    => 'term:category:999999',
				'template' => 'x',
			),
			'pas un tableau',
			array( 'where' => 'front' ),
		)
	);
	$fluffless_assert( 3 === count( $fluffless_rules ), 'règles gardées : ' . count( $fluffless_rules ) );
	$fluffless_assert( array( 12, 34 ) === $fluffless_rules[0]['ids'] && 'page-sections' === $fluffless_rules[0]['template'] && '' === $fluffless_rules[0]['part'], 'identifiants ou gabarit de la règle pages' );
	$fluffless_assert( '' === $fluffless_rules[1]['template'] && 'header-boutique' === $fluffless_rules[1]['part'], 'slug de gabarit invalide gardé' );
	$fluffless_assert( 'term:category:1' === $fluffless_rules[2]['where'] && array() === $fluffless_rules[2]['ids'], 'règle de terme' );
	$fluffless_many = array_fill(
		0,
		60,
		array(
			'where'    => 'front',
			'template' => 'index',
		)
	);
	$fluffless_assert( Conditions::MAX_RULES === count( Conditions::sanitize_rules( $fluffless_many ) ), 'borne de cinquante règles' );
	$fluffless_post_type = get_post_type_object( 'post' );
	$fluffless_assert( $fluffless_post_type instanceof WP_Post_Type && Conditions::valid_where( 'archive:post' ) === (bool) $fluffless_post_type->has_archive, 'archive d\'un type sans archive acceptée' );
	$fluffless_assert( ! Conditions::valid_where( 'type:revision' ) && ! Conditions::valid_where( 'tax:nav_menu' ) && ! Conditions::valid_where( '' ), 'type ou taxonomie non publics acceptés' );

	// 3. Choix de l'écran : simples, types publics, taxonomies et leurs termes (au plus cinquante).
	$fluffless_choices = Conditions::choices();
	$fluffless_assert( isset( $fluffless_choices['front'], $fluffless_choices['404'], $fluffless_choices['pages'], $fluffless_choices['type:page'], $fluffless_choices['type:post'], $fluffless_choices['tax:category'], $fluffless_choices['term:category:1'] ), 'choix attendus absents : ' . implode( ',', array_keys( $fluffless_choices ) ) );
	$fluffless_assert( ! isset( $fluffless_choices['type:attachment'], $fluffless_choices['tax:post_format'] ), 'médias ou formats listés' );
	foreach ( array_keys( $fluffless_choices ) as $fluffless_key ) {
		if ( ! Conditions::valid_where( (string) $fluffless_key ) ) {
			$fluffless_assert( false, 'choix proposé mais refusé : ' . $fluffless_key );
		}
	}

	$fluffless_header_slug = static function (): string {
		$swapped = Conditions::swap_header(
			array(
				'blockName' => 'core/template-part',
				'attrs'     => array( 'slug' => 'header' ),
			)
		);
		return (string) $swapped['attrs']['slug'];
	};
	// 4. Correspondance à la visite et première règle gagnante.
	update_option(
		Lifecycle::OPTION_CONDITIONS,
		array(
			array(
				'where'    => 'pages',
				'ids'      => array( $fluffless_page_id ),
				'template' => 'page-toile-vierge',
				'part'     => 'header-centre',
			),
			array(
				'where'    => 'type:page',
				'template' => 'page-sections',
				'part'     => '',
			),
			array(
				'where'    => 'term:category:1',
				'template' => '',
				'part'     => 'header-boutique',
			),
			array(
				'where'    => 'search',
				'template' => 'index',
				'part'     => '',
			),
			array(
				'where'    => '404',
				'template' => 'page-toile-vierge-nue',
				'part'     => '',
			),
			array(
				'where'    => 'home',
				'template' => '',
				'part'     => 'header',
			),
		),
		false
	);
	$fluffless_assert( 6 === count( Conditions::rules() ), 'règles relues : ' . count( Conditions::rules() ) );

	$fluffless_visit( array( 'page_id' => $fluffless_page_id ) );
	$fluffless_rule = Conditions::current();
	$fluffless_assert( null !== $fluffless_rule && 'pages' === $fluffless_rule['where'], 'page précise : première règle attendue, obtenue ' . ( $fluffless_rule['where'] ?? 'aucune' ) );
	$fluffless_assert( array( 'page-toile-vierge.php', 'page.php' ) === Conditions::template_hierarchy( array( 'page.php' ) ), 'gabarit non mis en tête' );
	$fluffless_assert( array( 'page-toile-vierge.php', 'page.php' ) === apply_filters( 'page_template_hierarchy', array( 'page.php' ) ), 'filtre de hiérarchie non branché' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur.
	$fluffless_part = Conditions::swap_header(
		array(
			'blockName' => 'core/template-part',
			'attrs'     => array(
				'slug' => 'header',
				'area' => 'header',
			),
		)
	);
	$fluffless_assert( 'header-centre' === $fluffless_part['attrs']['slug'], 'en-tête non remplacé' );
	$fluffless_footer = Conditions::swap_header(
		array(
			'blockName' => 'core/template-part',
			'attrs'     => array( 'slug' => 'footer' ),
		)
	);
	$fluffless_assert( 'footer' === $fluffless_footer['attrs']['slug'], 'pied de page touché' );
	$fluffless_assert( 'x' === Conditions::swap_header( 'x' ) && array( 'blockName' => 'core/paragraph' ) === Conditions::swap_header( array( 'blockName' => 'core/paragraph' ) ), 'autre bloc touché' );

	// Autre page : la règle du type gagne, sans en-tête.
	$fluffless_other = (int) wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Conditions test',
		)
	);
	$fluffless_visit( array( 'page_id' => $fluffless_other ) );
	$fluffless_rule = Conditions::current();
	$fluffless_assert( null !== $fluffless_rule && 'type:page' === $fluffless_rule['where'], 'type de contenu : règle attendue, obtenue ' . ( $fluffless_rule['where'] ?? 'aucune' ) );
	$fluffless_assert( 'page-sections.php' === Conditions::template_hierarchy( array( 'page.php' ) )[0], 'gabarit du type absent' );
	$fluffless_assert( 'header' === $fluffless_header_slug(), 'en-tête remplacé sans partie choisie' );
	wp_delete_post( $fluffless_other, true );

	// Catégorie 1 : en-tête seul, hiérarchie intacte.
	$fluffless_visit( array( 'cat' => 1 ) );
	$fluffless_rule = Conditions::current();
	$fluffless_assert( null !== $fluffless_rule && 'term:category:1' === $fluffless_rule['where'], 'catégorie : règle attendue, obtenue ' . ( $fluffless_rule['where'] ?? 'aucune' ) );
	$fluffless_assert( array( 'category.php' ) === Conditions::template_hierarchy( array( 'category.php' ) ), 'hiérarchie modifiée sans gabarit' );
	$fluffless_assert( 'header-boutique' === $fluffless_header_slug(), 'en-tête de la catégorie non remplacé' );

	// Recherche, 404, page des articles.
	$fluffless_visit( array( 's' => 'fluffless' ) );
	$fluffless_assert( 'search' === ( Conditions::current()['where'] ?? '' ), 'recherche non reconnue' );
	$fluffless_visit( array( 'p' => 999999999 ), true );
	$fluffless_assert( '404' === ( Conditions::current()['where'] ?? '' ), '404 non reconnue' );
	$fluffless_visit( array( 'post_type' => 'post' ) );
	$fluffless_assert( 'home' === ( Conditions::current()['where'] ?? '' ), 'page des articles non reconnue' );
	$fluffless_assert( 'header' === $fluffless_header_slug(), 'en-tête « header » choisi : doit rester tel quel' );

	// Sans règle : rien ne change.
	delete_option( Lifecycle::OPTION_CONDITIONS );
	$fluffless_visit( array( 'page_id' => $fluffless_page_id ) );
	$fluffless_assert( null === Conditions::current() && array( 'page.php' ) === Conditions::template_hierarchy( array( 'page.php' ) ), 'sans règle : hiérarchie modifiée' );

	// 5. Gabarits et en-têtes listés depuis le thème.
	$fluffless_templates = Conditions::templates();
	$fluffless_headers   = Conditions::headers();
	$fluffless_assert( isset( $fluffless_templates['page-sections'], $fluffless_templates['page-toile-vierge'] ), 'gabarits du thème absents : ' . implode( ',', array_keys( $fluffless_templates ) ) );
	$fluffless_assert( isset( $fluffless_headers['header'] ) && ! isset( $fluffless_headers['footer'] ), 'parties d\'en-tête : ' . implode( ',', array_keys( $fluffless_headers ) ) );
	$fluffless_assert( isset( $fluffless_headers['header-sobre'], $fluffless_headers['header-centre'], $fluffless_headers['header-bandeau'], $fluffless_headers['header-boutique'] ), 'les quatre en-têtes du thème manquent : ' . implode( ',', array_keys( $fluffless_headers ) ) );

	// Bout en bout : la partie « header » d'un gabarit devient l'en-tête centré sur la page visée.
	update_option(
		Lifecycle::OPTION_CONDITIONS,
		array(
			array(
				'where'    => 'pages',
				'ids'      => array( $fluffless_page_id ),
				'template' => '',
				'part'     => 'header-centre',
			),
		)
	);
	Conditions::reset();
	$fluffless_visit( array( 'page_id' => $fluffless_page_id ) );
	$fluffless_rendered = do_blocks( '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->' );
	$fluffless_assert( str_contains( $fluffless_rendered, 'fluffless-entete-centre' ) && ! str_contains( $fluffless_rendered, 'fluffless-entete-defaut' ), 'en-tête centré non rendu de bout en bout' );
	$fluffless_assert( str_contains( $fluffless_rendered, '<details' ) && ! str_contains( $fluffless_rendered, '<script' ), 'menu partagé absent ou script chargé' );
	delete_option( Lifecycle::OPTION_CONDITIONS );
	Conditions::reset();
	$fluffless_visit( array( 'page_id' => $fluffless_page_id ) );
	$fluffless_assert( str_contains( do_blocks( '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->' ), 'fluffless-entete-defaut' ), 'sans règle : en-tête par défaut attendu' );

	// 6. Écran : rendu pour un administrateur, rien pour un visiteur, choix et nonce présents.
	$fluffless_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$fluffless_admin  = (int) ( $fluffless_admins[0] ?? 0 );
	wp_set_current_user( 0 );
	ob_start();
	Conditions::render();
	$fluffless_assert( '' === ob_get_clean(), 'écran rendu pour un visiteur' );
	if ( $fluffless_admin > 0 ) {
		wp_set_current_user( $fluffless_admin );
		update_option(
			Lifecycle::OPTION_CONDITIONS,
			array(
				array(
					'where'    => 'type:page',
					'ids'      => array(),
					'template' => 'page-sections',
					'part'     => 'disparu',
				),
			),
			false
		);
		ob_start();
		Conditions::render();
		$fluffless_html = (string) ob_get_clean();
		$fluffless_assert( str_contains( $fluffless_html, 'name="rule[0][where]"' ) && str_contains( $fluffless_html, 'name="rule[1][where]"' ), 'rangées de l\'écran' );
		$fluffless_assert( str_contains( $fluffless_html, '<option value="type:page" selected=\'selected\'>' ), 'cible enregistrée non sélectionnée' );
		$fluffless_assert( str_contains( $fluffless_html, '<option value="page-sections" selected=\'selected\'>' ), 'gabarit enregistré non sélectionné' );
		$fluffless_assert( str_contains( $fluffless_html, 'value="disparu" selected' ) && str_contains( $fluffless_html, '(missing)' ), 'partie disparue non signalée' );
		$fluffless_assert( str_contains( $fluffless_html, 'name="_wpnonce"' ) && str_contains( $fluffless_html, 'value="' . Conditions::ACTION . '"' ), 'nonce ou action absents' );
		$fluffless_assert( str_contains( $fluffless_html, 'name="rule[0][delete]"' ) && ! str_contains( $fluffless_html, 'name="rule[1][delete]"' ), 'case Retirer sur la mauvaise rangée' );
		$fluffless_assert( ! str_contains( $fluffless_html, '<script' ), 'script dans l\'écran' );
		wp_set_current_user( 0 );
	}

	// 7. Désinstallation : l'option est retirée.
	$fluffless_uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Lifecycle.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( str_contains( $fluffless_uninstall, 'delete_option( self::OPTION_CONDITIONS );' ), 'option non retirée à la désinstallation' );
} finally {
	if ( null === $fluffless_saved ) {
		delete_option( Lifecycle::OPTION_CONDITIONS );
	} else {
		update_option( Lifecycle::OPTION_CONDITIONS, $fluffless_saved, false );
	}
	Conditions::reset();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Conditions d'affichage : {$fluffless_checks} vérifications passées." );
