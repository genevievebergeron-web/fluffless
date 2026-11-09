<?php
/**
 * Tests du Mode maintenance : réglages assainis, qui est bloqué, en-têtes selon le mode, page
 * d'attente rendue dans les styles du thème sans script, robots, rappel dans la barre d'administration.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/maintenance-test.php
 * Les réglages sont remis à leur état de départ à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Maintenance;
use Fluffless\Suite\Modules\Security;

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

$fluffless_backup_option = get_option( Options::NAME );

// WP-CLI ne définit pas SERVER_NAME, que wp_head() lit, et ne charge pas la barre d'administration.
if ( ! isset( $_SERVER['SERVER_NAME'] ) ) {
	$_SERVER['SERVER_NAME'] = 'localhost';
}
require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';

/**
 * Pose les réglages du module et vide le cache.
 *
 * @param bool                 $enabled Module actif ?
 * @param array<string, mixed> $section Section du module.
 */
$fluffless_set = static function ( bool $enabled, array $section = array() ): void {
	$all = get_option( Options::NAME );
	$all = is_array( $all ) ? $all : array();
	if ( ! isset( $all['modules'] ) || ! is_array( $all['modules'] ) ) {
		$all['modules'] = array();
	}
	$all['modules'][ Maintenance::id() ] = $enabled;
	$all[ Maintenance::id() ]            = Maintenance::sanitize( $section );
	update_option( Options::NAME, $all );
	Options::flush();
};

try {
	// 1. Contrat et branchement.
	$fluffless_loader = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Loader.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( 'maintenance' === Maintenance::id(), 'Identifiant du module.' );
	$fluffless_assert( false === Maintenance::enabled_by_default(), 'Inactif par défaut.' );
	$fluffless_assert( 1 === preg_match( '/Maintenance::class\s+=> array\(\),/', $fluffless_loader ), 'Module déclaré sans dépendance dans le chargeur.' );
	$fluffless_assert( array( 'mode', 'title', 'message', 'button' ) === array_keys( Maintenance::fields() ), 'Quatre champs de réglages.' );

	// 2. Assainissement.
	$fluffless_clean = Maintenance::sanitize(
		array(
			'mode'    => 'soon',
			'title'   => ' <b>Chantier</b> en cours ',
			'message' => "Première ligne<script>x</script>\n\nDeuxième ligne",
			'button'  => '0',
		)
	);
	$fluffless_assert( 'soon' === $fluffless_clean['mode'], 'Mode soon accepté.' );
	$fluffless_assert( 'Chantier en cours' === $fluffless_clean['title'], 'Titre sans balises ni espaces.' );
	$fluffless_assert( is_string( $fluffless_clean['message'] ) && str_contains( $fluffless_clean['message'], "Première ligne\n\nDeuxième ligne" ) && ! str_contains( $fluffless_clean['message'], '<script' ), 'Message sur plusieurs lignes, balises retirées.' );
	$fluffless_assert( false === $fluffless_clean['button'], 'Lien de connexion désactivable.' );
	$fluffless_clean = Maintenance::sanitize(
		array(
			'mode'  => 'ferme',
			'title' => str_repeat( 'a', 500 ),
		)
	);
	$fluffless_assert( 'maintenance' === $fluffless_clean['mode'], 'Mode inconnu ramené à maintenance.' );
	$fluffless_assert( is_string( $fluffless_clean['title'] ) && strlen( $fluffless_clean['title'] ) < 500, 'Titre borné.' );
	$fluffless_assert( true === $fluffless_clean['button'], 'Lien de connexion par défaut.' );

	// 3. Module inactif : aucun état, aucun blocage.
	$fluffless_set( false );
	$fluffless_assert( '' === Maintenance::status(), 'Aucun état quand le module est inactif.' );

	// 4. Module actif, mode maintenance, sous WP-CLI : laissé ouvert, sauf si le filtre ferme.
	$fluffless_set( true, array( 'mode' => 'maintenance' ) );
	wp_set_current_user( 0 );
	$fluffless_assert( str_contains( Maintenance::status(), 'maintenance page' ), 'État du mode maintenance.' );
	$fluffless_assert( 'maintenance' === Maintenance::mode(), 'Mode lu.' );
	$fluffless_assert( false === Maintenance::should_block(), 'WP-CLI n’est jamais bloqué.' );
	$fluffless_assert( 'Back soon' === Maintenance::title(), 'Titre par défaut du mode maintenance.' );
	$fluffless_assert( array( 'This site is under maintenance for a short while. Thank you for your patience.' ) === Maintenance::paragraphs(), 'Message par défaut du mode maintenance.' );
	$fluffless_robots = Maintenance::robots( array( 'max-image-preview' => 'large' ) );
	$fluffless_assert( ! isset( $fluffless_robots['noindex'] ), 'Robots intacts quand la requête passe.' );

	add_filter( 'fluffless_maintenance_bypass', '__return_false' );
	$fluffless_assert( true === Maintenance::should_block(), 'Le filtre peut fermer la requête.' );
	$fluffless_robots = Maintenance::robots( array( 'max-image-preview' => 'large' ) );
	$fluffless_assert( true === ( $fluffless_robots['noindex'] ?? false ) && true === ( $fluffless_robots['nofollow'] ?? false ) && 'large' === $fluffless_robots['max-image-preview'], 'Robots : noindex et nofollow ajoutés, le reste gardé.' );
	remove_filter( 'fluffless_maintenance_bypass', '__return_false' );

	add_filter( 'fluffless_maintenance_bypass', '__return_true' );
	$fluffless_assert( false === Maintenance::should_block(), 'Le filtre peut ouvrir la requête.' );
	remove_filter( 'fluffless_maintenance_bypass', '__return_true' );

	// 5. En-têtes selon le mode.
	$fluffless_headers = Maintenance::headers( 'maintenance' );
	$fluffless_assert( in_array( 'HTTP/1.1 503 Service Unavailable', $fluffless_headers, true ), 'Maintenance : statut 503.' );
	$fluffless_assert( in_array( 'Retry-After: ' . Maintenance::RETRY_AFTER, $fluffless_headers, true ), 'Maintenance : Retry-After d’une heure.' );
	$fluffless_assert( 1 === count( array_filter( $fluffless_headers, static fn( string $h ): bool => str_starts_with( $h, 'Cache-Control: no-store' ) ) ), 'Maintenance : jamais mise en cache.' );
	$fluffless_headers = Maintenance::headers( 'soon' );
	$fluffless_assert( in_array( 'HTTP/1.1 200 OK', $fluffless_headers, true ), 'Bientôt : statut 200.' );
	$fluffless_assert( in_array( 'X-Robots-Tag: noindex, nofollow', $fluffless_headers, true ), 'Bientôt : X-Robots-Tag noindex.' );
	$fluffless_assert( 0 === count( array_filter( $fluffless_headers, static fn( string $h ): bool => str_starts_with( $h, 'Retry-After' ) ) ), 'Bientôt : pas de Retry-After.' );

	// 6. Blocs et page rendue, mode maintenance avec réglages.
	$fluffless_set(
		true,
		array(
			'mode'    => 'maintenance',
			'title'   => 'Peinture & Cie',
			'message' => "Retour lundi.\nMerci & à bientôt.",
			'button'  => true,
		)
	);
	$fluffless_assert( 'Peinture & Cie' === Maintenance::title(), 'Titre du réglage.' );
	$fluffless_assert( array( 'Retour lundi.', 'Merci & à bientôt.' ) === Maintenance::paragraphs(), 'Une ligne par paragraphe.' );
	$fluffless_blocks = Maintenance::blocks();
	$fluffless_assert( str_contains( $fluffless_blocks, '<!-- wp:site-title' ) && str_contains( $fluffless_blocks, '<!-- wp:heading {"level":1} -->' ), 'Blocs : titre du site et titre de niveau 1.' );
	$fluffless_assert( str_contains( $fluffless_blocks, 'Peinture &amp; Cie' ) && str_contains( $fluffless_blocks, 'Merci &amp; à bientôt.' ), 'Blocs : titre et message échappés.' );
	$fluffless_assert( str_contains( $fluffless_blocks, esc_url( wp_login_url( home_url( '/' ) ) ) ) && str_contains( $fluffless_blocks, '>Log in</a>' ), 'Blocs : lien de connexion.' );

	$fluffless_html = Maintenance::page();
	$fluffless_assert( str_starts_with( $fluffless_html, '<!DOCTYPE html><html' ) && str_ends_with( $fluffless_html, '</body></html>' ), 'Page complète.' );
	$fluffless_assert( str_contains( $fluffless_html, '<title>Peinture &amp; Cie – ' . esc_html( get_bloginfo( 'name' ) ) . '</title>' ), 'Titre de la page.' );
	$fluffless_assert( str_contains( $fluffless_html, 'name="viewport"' ) && str_contains( $fluffless_html, 'class="fluffless-maintenance"' ), 'Viewport et classe du corps.' );
	$fluffless_assert( str_contains( $fluffless_html, 'wp-block-site-title' ) && str_contains( $fluffless_html, esc_html( get_bloginfo( 'name' ) ) ), 'Nom du site rendu.' );
	$fluffless_assert( str_contains( $fluffless_html, '<h1 class="wp-block-heading">Peinture &amp; Cie</h1>' ), 'Titre rendu.' );
	$fluffless_assert( 1 === preg_match( '#<p[^>]*>Retour lundi\.</p>#', $fluffless_html ) && 1 === preg_match( '#<p[^>]*>Merci &amp; à bientôt\.</p>#', $fluffless_html ), 'Deux paragraphes rendus.' );
	$fluffless_assert( str_contains( $fluffless_html, 'global-styles-inline-css' ), 'Styles globaux du thème présents.' );
	$fluffless_assert( 1 !== preg_match( '#<script[^>]*src=[^>]*fluffless-suite#', $fluffless_html ), 'Aucun script de la Suite.' );

	// 7. Mode bientôt, sans lien, message vide.
	$fluffless_set(
		true,
		array(
			'mode'   => 'soon',
			'button' => false,
		)
	);
	$fluffless_assert( str_contains( Maintenance::status(), 'coming soon page' ), 'État du mode bientôt.' );
	$fluffless_assert( 'Coming soon' === Maintenance::title(), 'Titre par défaut du mode bientôt.' );
	$fluffless_assert( array( 'This site is being prepared. Come back in a little while.' ) === Maintenance::paragraphs(), 'Message par défaut du mode bientôt.' );
	$fluffless_assert( ! str_contains( Maintenance::blocks(), '>Log in</a>' ), 'Sans lien de connexion.' );

	// C1-5-01 : l'adresse de connexion cachée par Sécurité de base ne paraît jamais sur la page d'attente.
	$fluffless_set(
		true,
		array(
			'mode'   => 'soon',
			'button' => true,
		)
	);
	$fluffless_all                   = get_option( Options::NAME );
	$fluffless_all['modules']        = array( Security::id() => true ) + ( is_array( $fluffless_all['modules'] ?? null ) ? $fluffless_all['modules'] : array() );
	$fluffless_all[ Security::id() ] = Security::sanitize( array( 'login_slug' => 'entrer' ) );
	update_option( Options::NAME, $fluffless_all );
	Options::flush();
	$fluffless_blocks = Maintenance::blocks();
	$fluffless_assert( ! str_contains( $fluffless_blocks, '>Log in</a>' ) && ! str_contains( $fluffless_blocks, 'entrer' ), 'Adresse de connexion cachée : aucun lien sur la page d’attente.' );
	$fluffless_all['modules'][ Security::id() ] = false;
	update_option( Options::NAME, $fluffless_all );
	Options::flush();
	$fluffless_assert( str_contains( Maintenance::blocks(), '>Log in</a>' ), 'Sécurité de base éteinte : le lien revient.' );
	$fluffless_help = Maintenance::fields()['button']['help'] ?? '';
	$fluffless_assert( str_contains( $fluffless_help, 'Basic security' ) && ! str_contains( $fluffless_help, 'usual login page' ), 'Aide du lien : dit quand il disparaît.' );

	// 8. Barre d'administration : un administrateur voit le rappel, un abonné non.
	$fluffless_bar = new WP_Admin_Bar();
	wp_set_current_user( 1 );
	Maintenance::admin_bar( $fluffless_bar );
	$fluffless_node = $fluffless_bar->get_node( 'fluffless-maintenance' );
	$fluffless_node = null === $fluffless_node ? array() : get_object_vars( $fluffless_node );
	$fluffless_assert( 'Coming soon page is on' === ( $fluffless_node['title'] ?? '' ) && str_contains( (string) ( $fluffless_node['href'] ?? '' ), 'page=fluffless-settings' ), 'Rappel dans la barre pour un administrateur, lié aux réglages.' );
	$fluffless_assert( false === Maintenance::should_block(), 'Un administrateur voit le site.' );
	$fluffless_bar = new WP_Admin_Bar();
	wp_set_current_user( 0 );
	Maintenance::admin_bar( $fluffless_bar );
	$fluffless_assert( null === $fluffless_bar->get_node( 'fluffless-maintenance' ), 'Aucun rappel sans droit.' );

	// 9. Crochets posés par register().
	( new Maintenance() )->register();
	$fluffless_assert( 0 === has_action( 'template_redirect', array( Maintenance::class, 'intercept' ) ), 'template_redirect à la priorité 0.' );
	$fluffless_assert( 100 === has_action( 'admin_bar_menu', array( Maintenance::class, 'admin_bar' ) ), 'admin_bar_menu posé.' );
	$fluffless_assert( false !== has_filter( 'wp_robots', array( Maintenance::class, 'robots' ) ), 'wp_robots posé.' );
} finally {
	wp_set_current_user( 0 );
	if ( false === $fluffless_backup_option ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_backup_option );
	}
	Options::flush();
}

if ( array() !== $fluffless_failures ) {
	foreach ( $fluffless_failures as $fluffless_failure ) {
		WP_CLI::warning( $fluffless_failure );
	}
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . ' vérifications.' );
}
WP_CLI::success( 'Mode maintenance : ' . $fluffless_checks . ' vérifications passées.' );
