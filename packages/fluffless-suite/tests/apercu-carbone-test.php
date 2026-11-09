<?php
/**
 * Tests du module Aperçu carbone.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/apercu-carbone-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\CarbonGlance;
use Fluffless\Suite\Modules\Footprint;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( CarbonGlance::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}
switch_to_locale( 'en_US' );
require_once ABSPATH . 'wp-admin/includes/dashboard.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

/*
 * Contrat : dans la liste, requiert Empreinte, actif d'office, sans réglage.
 */
$fluffless_assert( isset( Loader::modules()[ CarbonGlance::id() ] ), 'module absent du Loader' );
$fluffless_assert( array( 'footprint' ) === Loader::requires( CarbonGlance::id() ), 'Empreinte non requise' );
$fluffless_assert( CarbonGlance::enabled_by_default(), 'module éteint par défaut' );
$fluffless_assert( array() === CarbonGlance::fields() && array() === CarbonGlance::sanitize( array( 'x' => 1 ) ), 'réglage inattendu' );

/*
 * Tendance : baisse, hausse, stable sous 5 %, rien sans période d'avant.
 */
$fluffless_assert(
	array(
		'direction' => 'down',
		'percent'   => 20,
	) === CarbonGlance::trend( 0.8, 1.0 ),
	'tendance : baisse de 20 %'
);
$fluffless_assert(
	array(
		'direction' => 'up',
		'percent'   => 50,
	) === CarbonGlance::trend( 0.3, 0.2 ),
	'tendance : hausse de 50 %'
);
$fluffless_assert( 'flat' === CarbonGlance::trend( 1.03, 1.0 )['direction'], 'tendance : stable sous 5 %' );
$fluffless_assert( 'none' === CarbonGlance::trend( 1.0, 0.0 )['direction'], 'tendance : rien à comparer' );
$fluffless_assert( str_contains( CarbonGlance::trend_text( CarbonGlance::trend( 0.8, 1.0 ) ), 'Down 20%' ), 'phrase de tendance : ' . CarbonGlance::trend_text( CarbonGlance::trend( 0.8, 1.0 ) ) );

/*
 * Résumé sans visites : moyenne et pages les plus lourdes, du plus lourd au plus léger.
 */
$fluffless_summary = CarbonGlance::summary( array() );
$fluffless_assert( 0 === $fluffless_summary['measured'] && 0.0 === $fluffless_summary['average'] && array() === $fluffless_summary['heaviest'], 'résumé vide' );

$fluffless_posts = array();
$fluffless_admin = 0;
try {
	$fluffless_admin = wp_insert_user(
		array(
			'user_login' => 'fl-glance-admin',
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'fl-glance-admin@example.test',
			'role'       => 'administrator',
		)
	);
	if ( ! is_int( $fluffless_admin ) ) {
		throw new RuntimeException( 'Administratrice de test non créée.' );
	}
	// Sous une administratrice : les lectures de styles globaux ne recréent pas de billet.
	wp_set_current_user( $fluffless_admin );
	$fluffless_before = CarbonGlance::measures();

	foreach ( array(
		'Page légère'  => 50000,
		'Page lourde'  => 3000000,
		'Page moyenne' => 800000,
	) as $fluffless_title => $fluffless_bytes ) {
		$fluffless_id = wp_insert_post(
			array(
				'post_title'  => $fluffless_title,
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $fluffless_id, Footprint::META, Footprint::measure( $fluffless_bytes, 10 ) );
		$fluffless_posts[ $fluffless_title ] = $fluffless_id;
	}
	$fluffless_draft = wp_insert_post(
		array(
			'post_title'  => 'Brouillon mesuré',
			'post_type'   => 'page',
			'post_status' => 'draft',
		)
	);
	update_post_meta( $fluffless_draft, Footprint::META, Footprint::measure( 9000000, 10 ) );
	$fluffless_posts['draft'] = $fluffless_draft;

	$fluffless_measures = CarbonGlance::measures();
	$fluffless_assert( count( $fluffless_measures ) === count( $fluffless_before ) + 3, 'mesures : trois pages publiées de plus, brouillon exclu' );
	$fluffless_assert( ! isset( $fluffless_measures[ $fluffless_draft ] ), 'mesures : brouillon compté' );
	$fluffless_values = array_values( $fluffless_measures );
	$fluffless_sorted = $fluffless_values;
	rsort( $fluffless_sorted );
	$fluffless_assert( $fluffless_sorted === $fluffless_values, 'mesures : pas du plus lourd au plus léger' );
	$fluffless_assert( ( Footprint::stored( $fluffless_posts['Page lourde'] )['new'] ?? null ) === $fluffless_measures[ $fluffless_posts['Page lourde'] ], 'mesures : grammes recalculés au lieu d\'être relus' );

	$fluffless_own     = array_intersect_key( $fluffless_measures, array_flip( array( $fluffless_posts['Page légère'], $fluffless_posts['Page lourde'], $fluffless_posts['Page moyenne'] ) ) );
	$fluffless_summary = CarbonGlance::summary( $fluffless_own );
	$fluffless_assert( 3 === $fluffless_summary['measured'], 'résumé : trois pages' );
	$fluffless_assert( 'Page lourde' === $fluffless_summary['heaviest'][0]['title'] && 'Page légère' === $fluffless_summary['heaviest'][2]['title'], 'résumé : ordre des pages lourdes' );
	$fluffless_assert( abs( $fluffless_summary['average'] - array_sum( $fluffless_own ) / 3 ) < 1e-9, 'résumé : moyenne' );

	/*
	 * Widget : réservé à qui gère le site ; sans visites, grammes par page et barres, sans script.
	 */
	set_current_screen( 'dashboard' );
	$GLOBALS['wp_meta_boxes'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- remis à zéro pour le test.
	CarbonGlance::add_widget();
	$fluffless_assert( isset( $GLOBALS['wp_meta_boxes']['dashboard']['side']['core'][ CarbonGlance::WIDGET ] ), 'widget absent pour l\'administration' );
	ob_start();
	CarbonGlance::render();
	$fluffless_html = (string) ob_get_clean();
	if ( null === CarbonGlance::reports() ) {
		$fluffless_assert( str_contains( $fluffless_html, 'Average per page, first visit' ) && str_contains( $fluffless_html, 'Measured pages' ), 'sans visites : grammes par page absents' );
		$fluffless_assert( str_contains( $fluffless_html, 'Totals and trend appear once Carbon report reads your visits.' ), 'sans visites : explication absente' );
	}
	$fluffless_assert( str_contains( $fluffless_html, 'Heaviest pages' ) && str_contains( $fluffless_html, 'Page lourde' ), 'pages lourdes absentes' );
	$fluffless_assert( str_contains( $fluffless_html, 'width:100%' ), 'barre de la page la plus lourde absente' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ), 'script dans le widget' );

	$fluffless_editor = wp_insert_user(
		array(
			'user_login' => 'fl-glance-editor',
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'fl-glance-editor@example.test',
			'role'       => 'editor',
		)
	);
	if ( ! is_int( $fluffless_editor ) ) {
		throw new RuntimeException( 'Éditrice de test non créée.' );
	}
	wp_set_current_user( $fluffless_editor );
	$GLOBALS['wp_meta_boxes'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- remis à zéro pour le test.
	CarbonGlance::add_widget();
	$fluffless_assert( ! isset( $GLOBALS['wp_meta_boxes']['dashboard']['side']['core'][ CarbonGlance::WIDGET ] ), 'widget affiché à l\'édition' );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $fluffless_editor );
} finally {
	foreach ( $fluffless_posts as $fluffless_id ) {
		wp_delete_post( $fluffless_id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_int( $fluffless_admin ) && $fluffless_admin > 0 ) {
		wp_delete_user( $fluffless_admin );
	}
	wp_set_current_user( 0 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Aperçu carbone : {$fluffless_checks} vérifications passées." );
