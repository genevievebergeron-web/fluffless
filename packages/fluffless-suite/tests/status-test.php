<?php
/**
 * Tests de l'État du site : contrat, clé, permission de la route, forme de la réponse.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/status-test.php
 * Les réglages sont remis à leur état de départ à la fin ; la clé est posée par variable d'environnement.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Maintenance;
use Fluffless\Suite\Modules\Security;
use Fluffless\Suite\Modules\Status;

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

$fluffless_backup_option = get_option( Options::NAME );

/**
 * Pose l'état d'un module et sa section, puis vide le cache.
 *
 * @param string               $id      Identifiant du module.
 * @param bool                 $enabled Module actif ?
 * @param array<string, mixed> $section Section brute (assainie par le module).
 */
$fluffless_set = static function ( string $id, bool $enabled, array $section = array() ): void {
	$all = get_option( Options::NAME );
	$all = is_array( $all ) ? $all : array();
	if ( ! isset( $all['modules'] ) || ! is_array( $all['modules'] ) ) {
		$all['modules'] = array();
	}
	$all['modules'][ $id ] = $enabled;
	if ( array() !== $section ) {
		$class      = \Fluffless\Suite\Core\Loader::modules()[ $id ];
		$all[ $id ] = $class::sanitize( $section );
	}
	update_option( Options::NAME, $all );
	Options::flush();
};

/**
 * Requête GET sur la route, avec ou sans clé.
 *
 * @param string|null $key Clé envoyée dans l'en-tête.
 */
$fluffless_call = static function ( ?string $key ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', '/' . Status::REST_NS . Status::ROUTE );
	if ( null !== $key ) {
		$request->set_header( Status::HEADER, $key );
	}
	return rest_do_request( $request );
};

try {
	// 1. Contrat et branchement.
	$fluffless_loader = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Loader.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( 'status' === Status::id(), 'Identifiant du module.' );
	$fluffless_assert( false === Status::enabled_by_default(), 'Inactif par défaut.' );
	$fluffless_assert( 1 === preg_match( '/Status::class\s+=> array\(\),/', $fluffless_loader ), 'Module déclaré sans dépendance dans le chargeur.' );
	$fluffless_assert( array( 'events' => 20 ) === Status::defaults(), 'Vingt événements par défaut.' );
	$fluffless_assert( array( 'events' => 100 ) === Status::sanitize( array( 'events' => '999' ) ), 'Événements plafonnés à cent.' );
	$fluffless_assert( array( 'events' => 0 ) === Status::sanitize( array( 'events' => '-3' ) ), 'Événements jamais négatifs.' );
	$fluffless_assert( array( 'events' => 20 ) === Status::sanitize( array( 'events' => 'abc' ) ), 'Valeur invalide ramenée au défaut.' );

	// 2. Clé : absente, trop courte, puis valable par variable d'environnement.
	$fluffless_assert( ! defined( Status::KEY_CONSTANT ), 'Constante absente en test.' );
	putenv( Status::KEY_CONSTANT . '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- clé de test, retirée à la fin.
	$fluffless_assert( '' === Status::key(), 'Aucune clé.' );
	$fluffless_set( Status::id(), false );
	$fluffless_assert( '' === Status::status(), 'Aucun état quand le module est inactif.' );
	$fluffless_set( Status::id(), true );
	$fluffless_assert( str_contains( Status::status(), Status::KEY_CONSTANT ), 'Statut : clé absente nomme la constante.' );
	putenv( Status::KEY_CONSTANT . '=courte' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- clé de test, retirée à la fin.
	$fluffless_assert( '' === Status::key(), 'Clé trop courte refusée.' );
	putenv( Status::KEY_CONSTANT . '=  cle-de-test-assez-longue-1234  ' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- clé de test, retirée à la fin.
	$fluffless_assert( 'cle-de-test-assez-longue-1234' === Status::key(), 'Clé lue dans l’environnement, sans espaces.' );
	$fluffless_assert( str_contains( Status::status(), Status::HEADER ) && str_contains( Status::status(), rest_url( Status::REST_NS . Status::ROUTE ) ), 'Statut : adresse et en-tête rappelés.' );

	// 3. Route : enregistrée par le module, refusée sans clé ou avec une mauvaise clé, ouverte avec la bonne.
	wp_set_current_user( 0 );
	rest_get_server();
	( new Status() )->register();
	Status::register_routes();
	$fluffless_assert( array_key_exists( '/' . Status::REST_NS . Status::ROUTE, rest_get_server()->get_routes( Status::REST_NS ) ), 'Route enregistrée.' );
	$fluffless_assert( 401 === $fluffless_call( null )->get_status(), 'Sans clé : 401.' );
	$fluffless_assert( 401 === $fluffless_call( 'mauvaise-cle-assez-longue' )->get_status(), 'Mauvaise clé : 401.' );
	$fluffless_response = $fluffless_call( 'cle-de-test-assez-longue-1234' );
	$fluffless_assert( 200 === $fluffless_response->get_status(), 'Bonne clé : 200.' );
	$fluffless_assert( 'no-store' === ( $fluffless_response->get_headers()['Cache-Control'] ?? '' ), 'Réponse jamais mise en cache.' );

	putenv( Status::KEY_CONSTANT . '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- clé de test, retirée à la fin.
	$fluffless_assert( 503 === $fluffless_call( 'cle-de-test-assez-longue-1234' )->get_status(), 'Aucune clé réglée : 503, même avec une clé envoyée.' );
	putenv( Status::KEY_CONSTANT . '=cle-de-test-assez-longue-1234' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- clé de test, retirée à la fin.

	// 4. Forme de la réponse.
	$fluffless_data = $fluffless_response->get_data();
	$fluffless_assert( is_array( $fluffless_data ) && array( 'generated', 'site', 'versions', 'updates', 'audit', 'backups', 'maintenance', 'journal' ) === array_keys( $fluffless_data ), 'Huit sections dans l’ordre.' );
	$fluffless_assert( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ( $fluffless_data['generated'] ?? '' ) ), 'Date de génération en UTC.' );
	$fluffless_assert( home_url() === ( $fluffless_data['site']['url'] ?? '' ) && get_bloginfo( 'name' ) === ( $fluffless_data['site']['name'] ?? '' ), 'Site nommé.' );
	$fluffless_assert( get_bloginfo( 'version' ) === ( $fluffless_data['versions']['wordpress'] ?? '' ) && PHP_VERSION === ( $fluffless_data['versions']['php'] ?? '' ), 'Versions de WordPress et de PHP.' );
	$fluffless_assert( is_string( $fluffless_data['versions']['theme']['name'] ?? null ) && '' !== $fluffless_data['versions']['theme']['name'], 'Thème nommé.' );
	$fluffless_updates = $fluffless_data['updates'] ?? array();
	$fluffless_assert( is_array( $fluffless_updates ) && array( 'core', 'plugins', 'themes', 'count' ) === array_keys( $fluffless_updates ), 'Mises à jour : cœur, extensions, thèmes, total.' );
	$fluffless_assert( ( '' === $fluffless_updates['core'] ? 0 : 1 ) + count( $fluffless_updates['plugins'] ) + count( $fluffless_updates['themes'] ) === $fluffless_updates['count'], 'Total cohérent.' );
	foreach ( array_merge( $fluffless_updates['plugins'], $fluffless_updates['themes'] ) as $fluffless_offer ) {
		$fluffless_assert( is_array( $fluffless_offer ) && array( 'name', 'current', 'available' ) === array_keys( $fluffless_offer ) && '' !== $fluffless_offer['name'], 'Chaque mise à jour nommée avec versions.' );
		break;
	}
	$fluffless_assert( Audit::backups() === ( $fluffless_data['backups'] ?? null ), 'Sauvegarde déclarée telle que le Bilan la lit.' );
	$fluffless_audit = $fluffless_data['audit'] ?? array();
	$fluffless_assert( is_array( $fluffless_audit ) && array( 'checked', 'url', 'local', 'overall', 'counts', 'items' ) === array_keys( $fluffless_audit ), 'Bilan : date, adresse, local, pire statut, comptes, points.' );
	$fluffless_assert( isset( $fluffless_audit['items']['backups']['status'] ) && Audit::check_backups()['status'] === $fluffless_audit['items']['backups']['status'], 'Le point sauvegardes est toujours dans le bilan.' );
	$fluffless_assert( isset( $fluffless_audit['items']['page_cache']['status'] ) && Audit::check_page_cache()['status'] === $fluffless_audit['items']['page_cache']['status'], 'Le point cache de page est toujours dans le bilan.' );
	$fluffless_assert( isset( $fluffless_audit['items']['images']['status'], $fluffless_audit['items']['videos']['status'] ) && Audit::check_images()['status'] === $fluffless_audit['items']['images']['status'], 'Les points images et vidéos, lus en direct, sont toujours dans le bilan.' );
	$fluffless_assert( array_sum( $fluffless_audit['counts'] ) === count( $fluffless_audit['items'] ), 'Comptes par statut cohérents.' );
	$fluffless_assert( in_array( $fluffless_audit['overall'], array( 'good', 'warn', 'bad', 'unknown' ), true ), 'Pire statut connu.' );
	$fluffless_assert( is_bool( $fluffless_data['maintenance']['active'] ?? null ) && Options::is_enabled( Maintenance::id() ) === $fluffless_data['maintenance']['active'], 'Mode maintenance rapporté.' );

	// 5. Pire statut : « à corriger » quand rien n'est déclaré, meilleur quand la sauvegarde est déclarée récente.
	$fluffless_backup_audit = Options::all()[ Audit::id() ] ?? array();
	$fluffless_set( Audit::id(), true, array() );
	$fluffless_assert( 'bad' === Status::audit()['overall'] && 1 <= Status::audit()['counts']['bad'], 'Sans sauvegarde déclarée, le pire statut est « à corriger ».' );
	$fluffless_set(
		Audit::id(),
		true,
		array(
			'backups'            => true,
			'backups_checked'    => gmdate( 'Y-m-d' ),
			'page_cache'         => true,
			'page_cache_checked' => gmdate( 'Y-m-d' ),
		)
	);
	$fluffless_assert( 'good' === Status::audit()['items']['backups']['status'], 'Sauvegarde déclarée aujourd’hui : bon.' );
	$fluffless_assert( 'good' === Status::audit()['items']['page_cache']['status'], 'Cache de page déclaré aujourd’hui : bon.' );

	// 6. Journal : éteint sans le module Sécurité de base ; allumé, jamais d'adresse IP, borné par le réglage.
	$fluffless_security_on = Options::is_enabled( Security::id() );
	$fluffless_set( Security::id(), false );
	$fluffless_journal = Status::journal();
	$fluffless_assert( false === $fluffless_journal['enabled'] && array() === $fluffless_journal['events'], 'Journal éteint : rien.' );
	$fluffless_set( Security::id(), true, array( 'journal' => true ) );
	$fluffless_set( Status::id(), true, array( 'events' => 3 ) );
	$fluffless_journal = Status::journal();
	$fluffless_assert( true === $fluffless_journal['enabled'] && count( $fluffless_journal['events'] ) <= 3, 'Journal allumé : au plus trois événements.' );
	$fluffless_first = (array) wp_json_encode( $fluffless_journal['events'][0] ?? array() );
	$fluffless_assert( array() === $fluffless_journal['events'] || ( str_contains( (string) $fluffless_first[0], '"label"' ) && ! str_contains( (string) $fluffless_first[0], '"ip"' ) ), 'Événement avec libellé, sans adresse IP.' );
	$fluffless_set( Status::id(), true, array( 'events' => 0 ) );
	$fluffless_assert( array() === Status::journal()['events'], 'Zéro événement demandé : liste vide.' );
	$fluffless_assert( false === strpos( (string) wp_json_encode( Status::payload() ), '"ip"' ), 'Aucune clé ip dans toute la réponse.' );

	// 7. Un administrateur connecté lit la réponse sans clé.
	putenv( Status::KEY_CONSTANT . '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- clé de test, retirée à la fin.
	$fluffless_admin = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	wp_set_current_user( (int) ( $fluffless_admin[0] ?? 0 ) );
	$fluffless_assert( 200 === $fluffless_call( null )->get_status(), 'Administrateur connecté : 200 sans clé.' );
	wp_set_current_user( 0 );
	$fluffless_assert( 503 === $fluffless_call( null )->get_status(), 'Visiteur sans clé réglée : 503.' );
} finally {
	wp_set_current_user( 0 );
	putenv( Status::KEY_CONSTANT ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- clé de test, retirée à la fin.
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
WP_CLI::success( 'État du site : ' . $fluffless_checks . ' vérifications passées.' );
