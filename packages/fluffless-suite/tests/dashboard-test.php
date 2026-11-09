<?php
/**
 * Tests du module Guide du tableau de bord.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/dashboard-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Dashboard;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Dashboard::class ) ) {
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
 * Contrat : dans la liste de base, actif d'office, sans réglage.
 */
$fluffless_assert( isset( Loader::modules()[ Dashboard::id() ] ), 'module absent du Loader' );
$fluffless_assert( Dashboard::enabled_by_default(), 'module éteint par défaut' );
$fluffless_assert( array() === Dashboard::fields() && array() === Dashboard::sanitize( array( 'x' => 1 ) ), 'réglage inattendu' );

set_current_screen( 'dashboard' );

/**
 * Place du widget pour l'utilisateur donné ('' si absent) et son rendu.
 *
 * @param int $user Identifiant de l'utilisateur.
 * @return array{priority: string, html: string}
 */
$fluffless_widget = static function ( int $user ): array {
	global $wp_meta_boxes;
	wp_set_current_user( $user );
	$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- remis à zéro entre deux personnes.
	Dashboard::add_widget();
	// add_widget() remplit la globale : relue après l'appel, pas d'après la remise à zéro plus haut.
	/**
	 * Boîtes du tableau de bord.
	 *
	 * @var array<string, mixed> $boxes
	 */
	$boxes    = $GLOBALS['wp_meta_boxes'];
	$priority = '';
	foreach ( array( 'high', 'core' ) as $fluffless_priority ) {
		if ( isset( $boxes['dashboard']['normal'][ $fluffless_priority ][ Dashboard::WIDGET ] ) ) {
			$priority = $fluffless_priority;
		}
	}
	if ( '' === $priority ) {
		return array(
			'priority' => '',
			'html'     => '',
		);
	}
	ob_start();
	Dashboard::render();
	return array(
		'priority' => $priority,
		'html'     => (string) ob_get_clean(),
	);
};

$fluffless_users = array();
$fluffless_post  = 0;
$fluffless_next  = static fn(): array => array(
	array(
		'text' => 'Fluffless next action',
		'url'  => admin_url( 'admin.php?page=fluffless' ),
	),
);

try {
	foreach ( array( 'administrator', 'editor', 'author', 'subscriber' ) as $fluffless_role ) {
		$fluffless_users[ $fluffless_role ] = wp_insert_user(
			array(
				'user_login' => 'fl-dashboard-' . $fluffless_role,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'fl-dashboard-' . $fluffless_role . '@example.test',
				'role'       => $fluffless_role,
			)
		);
		if ( ! is_int( $fluffless_users[ $fluffless_role ] ) ) {
			throw new RuntimeException( esc_html( 'Utilisateur de test non créé : ' . $fluffless_role ) );
		}
	}

	/*
	 * Administration : premières étapes tant qu'il en reste, gestes courants, lien vers Fluffless.
	 */
	add_filter( 'fluffless_admin_actions', $fluffless_next );
	$fluffless_admin = $fluffless_widget( $fluffless_users['administrator'] );
	remove_filter( 'fluffless_admin_actions', $fluffless_next );
	wp_set_current_user( $fluffless_users['administrator'] );
	$fluffless_left = Fluffless\Suite\Admin\Home::steps_left();
	$fluffless_done = array() !== array_filter( Fluffless\Suite\Admin\Home::first_steps(), static fn( array $step ): bool => $step['done'] );
	$fluffless_assert( ( $fluffless_left ? 'high' : 'core' ) === $fluffless_admin['priority'], 'administration : place du widget sans rapport avec les étapes restantes' );
	$fluffless_assert( str_contains( $fluffless_admin['html'], 'Your content' ) && str_contains( $fluffless_admin['html'], 'Edit a page' ) && str_contains( $fluffless_admin['html'], 'Write a post' ), 'administration : gestes courants absents' );
	$fluffless_assert( str_contains( $fluffless_admin['html'], 'Open Fluffless' ), 'administration : lien vers Fluffless absent' );
	$fluffless_assert( str_contains( $fluffless_admin['html'], 'Getting started' ) === $fluffless_left, 'administration : premières étapes affichées à tort ou manquantes' );
	$fluffless_assert( ! $fluffless_left || ! $fluffless_done || ( str_contains( $fluffless_admin['html'], 'dashicons-yes' ) && str_contains( $fluffless_admin['html'], '(done)' ) ), 'administration : étape faite sans coche ni mention pour les lecteurs d\'écran' );
	$fluffless_assert( str_contains( $fluffless_admin['html'], 'Next steps' ) && str_contains( $fluffless_admin['html'], 'Fluffless next action' ), 'administration : prochaine action absente' );
	$fluffless_assert( ! str_contains( $fluffless_admin['html'], '<script' ) && ! str_contains( $fluffless_admin['html'], '<style' ), 'administration : script ou style dans le widget' );
	$fluffless_assert( substr_count( $fluffless_admin['html'], '<a href="http' ) === substr_count( $fluffless_admin['html'], '<a ' ), 'administration : lien sans adresse absolue' );
	add_filter( 'fluffless_admin_actions', '__return_empty_array' );
	$fluffless_quiet = $fluffless_widget( $fluffless_users['administrator'] );
	remove_filter( 'fluffless_admin_actions', '__return_empty_array' );
	$fluffless_assert( ! str_contains( $fluffless_quiet['html'], 'Next steps' ), 'administration : titre des prochaines étapes sans action' );

	/*
	 * Édition : pages et articles, rien de réservé à qui gère les options. En tête tant que rien
	 * n'est publié, parmi les boîtes du cœur ensuite.
	 */
	$fluffless_editor = $fluffless_widget( $fluffless_users['editor'] );
	$fluffless_assert( 'high' === $fluffless_editor['priority'] && str_contains( $fluffless_editor['html'], 'Add a page' ), 'édition : widget pas en tête ou pages absentes' );
	$fluffless_assert( ! str_contains( $fluffless_editor['html'], 'Open Fluffless' ) && ! str_contains( $fluffless_editor['html'], 'Getting started' ) && ! str_contains( $fluffless_editor['html'], 'Next steps' ), 'édition : partie réservée à l\'administration affichée' );
	$fluffless_post = wp_insert_post(
		array(
			'post_title'  => 'Premier article',
			'post_status' => 'publish',
			'post_author' => $fluffless_users['editor'],
		)
	);
	$fluffless_assert( 'core' === $fluffless_widget( $fluffless_users['editor'] )['priority'], 'édition : widget toujours en tête après une publication' );

	/*
	 * Auteur : articles seulement ; abonné : aucun widget.
	 */
	$fluffless_author = $fluffless_widget( $fluffless_users['author'] );
	$fluffless_assert( '' !== $fluffless_author['priority'] && str_contains( $fluffless_author['html'], 'Write a post' ) && str_contains( $fluffless_author['html'], 'View the site' ), 'auteur : gestes courants absents' );
	$fluffless_assert( ! str_contains( $fluffless_author['html'], 'Edit a page' ) && ! str_contains( $fluffless_author['html'], 'Add a page' ), 'auteur : pages proposées sans le droit de les modifier' );
	$fluffless_assert( '' === $fluffless_widget( $fluffless_users['subscriber'] )['priority'], 'abonné : widget affiché' );
} finally {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( $fluffless_post ) {
		wp_delete_post( $fluffless_post, true );
	}
	foreach ( $fluffless_users as $fluffless_id ) {
		if ( is_int( $fluffless_id ) ) {
			wp_delete_user( $fluffless_id );
		}
	}
	wp_set_current_user( 0 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Guide du tableau de bord : {$fluffless_checks} vérifications passées." );
