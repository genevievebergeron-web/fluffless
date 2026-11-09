<?php
/**
 * Tests du module Cours sobres.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/courses-test.php
 * Les vérifications qui touchent Sensei ne tournent que s'il est actif.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Courses;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Courses::class ) ) {
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
$fluffless_saved    = get_option( Options::NAME );
$fluffless_set      = static function ( array $section ): void {
	$all            = get_option( Options::NAME );
	$all            = is_array( $all ) ? $all : array();
	$all['courses'] = array_merge( Courses::defaults(), $section );
	update_option( Options::NAME, Options::sanitize( $all ) );
	Options::flush();
};

try {
	// Contrat du module.
	$fluffless_assert( 'courses' === Courses::id(), 'id' );
	$fluffless_assert( Loader::is_active( 'courses' ), 'module actif par défaut' );
	$fluffless_assert( array_keys( Courses::fields() ) === array_keys( Courses::defaults() ), 'un champ par réglage' );
	$fluffless_clean = Courses::sanitize( array( 'assets' => '0' ) );
	$fluffless_assert( false === $fluffless_clean['assets'] && true === $fluffless_clean['dashicons'], 'bool assaini, clé absente au défaut' );

	// Détection de la page de cours, forcée par le filtre dans un sens puis dans l'autre.
	$fluffless_force = static fn(): bool => true;
	$fluffless_block = static fn(): bool => false;
	add_filter( 'fluffless_course_page', $fluffless_force );
	$fluffless_assert( Courses::is_course_page(), 'filtre force la page de cours' );
	remove_filter( 'fluffless_course_page', $fluffless_force );
	add_filter( 'fluffless_course_page', $fluffless_block );
	$fluffless_assert( ! Courses::is_course_page(), 'filtre empêche la page de cours' );
	remove_filter( 'fluffless_course_page', $fluffless_block );

	if ( Courses::sensei_active() ) {
		$fluffless_assert( '' === Courses::status(), 'aucun avertissement avec Sensei' );
		$fluffless_base = Courses::sensei_url();
		$fluffless_assert( str_ends_with( $fluffless_base, '/sensei-lms/' ), 'dossier de Sensei repéré' );

		// Une feuille et un script de Sensei, une feuille d'ailleurs, la police d'icônes.
		wp_register_style( 'fluffless-test-sensei', $fluffless_base . 'assets/dist/css/frontend.css', array(), '1' );
		wp_register_script( 'fluffless-test-sensei-js', $fluffless_base . 'assets/dist/js/x.js', array(), '1', true );
		wp_register_style( 'fluffless-test-other', get_template_directory_uri() . '/style.css', array(), '1' );
		// File directe : wp_style_is() répond aussi vrai quand une feuille en file dépend de dashicons (la barre d'administration).
		$fluffless_queued = static fn( string $handle ): bool => in_array( $handle, wp_styles()->queue, true );
		$fluffless_queue  = static function (): void {
			wp_enqueue_style( 'fluffless-test-sensei' );
			wp_enqueue_script( 'fluffless-test-sensei-js' );
			wp_enqueue_style( 'fluffless-test-other' );
			wp_enqueue_style( Courses::DASHICONS );
		};

		// Hors page de cours, visiteur : tout Sensei et dashicons partent, le reste reste.
		$fluffless_set( array() );
		wp_set_current_user( 0 );
		add_filter( 'fluffless_course_page', $fluffless_block );
		$fluffless_queue();
		Courses::dequeue_off_courses();
		$fluffless_assert( ! wp_style_is( 'fluffless-test-sensei', 'enqueued' ), 'feuille de Sensei retirée hors cours' );
		$fluffless_assert( ! wp_script_is( 'fluffless-test-sensei-js', 'enqueued' ), 'script de Sensei retiré hors cours' );
		$fluffless_assert( wp_style_is( 'fluffless-test-other', 'enqueued' ), 'feuille d\'ailleurs gardée' );
		$fluffless_assert( ! $fluffless_queued( Courses::DASHICONS ), 'dashicons retirée pour un visiteur' );

		// Barre d'administration visible : dashicons reste.
		$fluffless_admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);
		if ( array() !== $fluffless_admins ) {
			wp_set_current_user( (int) $fluffless_admins[0] );
			$fluffless_show = '__return_true';
			add_filter( 'show_admin_bar', $fluffless_show );
			_wp_admin_bar_init();
			$fluffless_queue();
			Courses::dequeue_off_courses();
			$fluffless_assert( is_admin_bar_showing() && $fluffless_queued( Courses::DASHICONS ), 'dashicons gardée avec la barre d\'administration' );
			$fluffless_assert( ! wp_style_is( 'fluffless-test-sensei', 'enqueued' ), 'feuille de Sensei retirée même connecté' );
			remove_filter( 'show_admin_bar', $fluffless_show );
			wp_set_current_user( 0 );
			add_filter( 'show_admin_bar', '__return_false' ); // WordPress garde sa réponse en cache.
		}

		// Réglage assets éteint : Sensei reste, dashicons part encore.
		$fluffless_set( array( 'assets' => false ) );
		$fluffless_queue();
		Courses::dequeue_off_courses();
		$fluffless_assert( wp_style_is( 'fluffless-test-sensei', 'enqueued' ), 'feuille de Sensei gardée quand assets est éteint' );
		$fluffless_assert( ! $fluffless_queued( Courses::DASHICONS ), 'dashicons retirée même sans assets' );
		wp_dequeue_style( 'fluffless-test-sensei' );

		// Réglage dashicons éteint : la police reste.
		$fluffless_set( array( 'dashicons' => false ) );
		$fluffless_queue();
		Courses::dequeue_off_courses();
		$fluffless_assert( $fluffless_queued( Courses::DASHICONS ), 'dashicons gardée quand le réglage est éteint' );
		wp_dequeue_style( Courses::DASHICONS );
		remove_filter( 'fluffless_course_page', $fluffless_block );

		// Page de cours : rien ne bouge.
		$fluffless_set( array() );
		add_filter( 'fluffless_course_page', $fluffless_force );
		$fluffless_queue();
		Courses::dequeue_off_courses();
		$fluffless_assert( wp_style_is( 'fluffless-test-sensei', 'enqueued' ) && $fluffless_queued( Courses::DASHICONS ), 'tout reste sur une page de cours' );
		remove_filter( 'fluffless_course_page', $fluffless_force );
		remove_filter( 'show_admin_bar', '__return_false' );
		wp_dequeue_style( 'fluffless-test-sensei' );
		wp_dequeue_script( 'fluffless-test-sensei-js' );
		wp_dequeue_style( 'fluffless-test-other' );
		wp_dequeue_style( Courses::DASHICONS );

		// Branchement selon les réglages.
		$fluffless_set( array() );
		( new Courses() )->register();
		$fluffless_assert( false !== has_action( 'wp_enqueue_scripts', array( Courses::class, 'dequeue_off_courses' ) ), 'retrait hors cours branché' );
		remove_action( 'wp_enqueue_scripts', array( Courses::class, 'dequeue_off_courses' ), 100 );
		$fluffless_set(
			array(
				'assets'    => false,
				'dashicons' => false,
			)
		);
		( new Courses() )->register();
		$fluffless_assert( false === has_action( 'wp_enqueue_scripts', array( Courses::class, 'dequeue_off_courses' ) ), 'rien de branché quand tout est éteint' );
	} else {
		$fluffless_assert( '' !== Courses::status(), 'avertissement sans Sensei' );
		( new Courses() )->register();
		$fluffless_assert( false === has_action( 'wp_enqueue_scripts', array( Courses::class, 'dequeue_off_courses' ) ), 'rien de branché sans Sensei' );
	}
} finally {
	update_option( Options::NAME, $fluffless_saved );
	Options::flush();
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		echo 'ÉCHEC : ', esc_html( $fluffless_failure ), "\n";
	}
	exit( 1 );
}
echo 'OK : Cours sobres, ', esc_html( (string) $fluffless_checks ), " vérifications réussies.\n";
