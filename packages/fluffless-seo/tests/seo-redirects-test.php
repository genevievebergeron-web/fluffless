<?php
/**
 * Tests des redirections et du journal des adresses introuvables.
 *
 * Lancer : npm run test:tools -- seo-redirects
 * Règles et entrées créées ici sont supprimées à la fin ; réglages restaurés.
 *
 * @package Fluffless_Seo
 */

use Fluffless\Seo\Redirects;
use Fluffless\Seo\Settings;
use Fluffless\Seo\Store;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

// Extension optionnelle : inactive sur le banc, le test est sauté et le dit, sans échouer.
if ( ! class_exists( Store::class ) ) {
	echo esc_html( "SAUTÉ : Fluffless SEO inactive (wp plugin activate fluffless-seo pour lancer ces contrôles).\n" );
	return;
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

/**
 * Vrai si l'appel lève InvalidArgumentException.
 *
 * @param callable $call Appel.
 */
$fluffless_throws = static function ( callable $call ): bool {
	try {
		$call();
	} catch ( InvalidArgumentException $error ) {
		return '' !== $error->getMessage();
	}
	return false;
};

$fluffless_saved = get_option( Settings::NAME );
$fluffless_ids   = array();
$fluffless_paths = array( '/fl-test-introuvable', '/fl-test-ancienne' );
$fluffless_csv   = '';

try {
	global $wpdb;
	Store::maybe_upgrade();
	Settings::update(
		array(
			'redirects'           => true,
			'log404'              => true,
			'redirect_everywhere' => false,
		)
	);
	Settings::flush();

	// Chemins normalisés : barre finale, encodage.
	$fluffless_assert( '/a/b' === Store::normalize_path( 'https://ailleurs.example/a/b/?x=1' ), 'normalize_path() : ' . Store::normalize_path( 'https://ailleurs.example/a/b/?x=1' ) );
	$fluffless_assert( '/' === Store::normalize_path( '' ), 'Chemin vide attendu /.' );

	// 301 simple.
	$fluffless_ids[] = Store::add_redirect( '/fl-test-ancienne/', '/fl-test-nouvelle', 301 );
	$fluffless_hit   = Redirects::decide( '/fl-test-ancienne', true );
	$fluffless_assert( null !== $fluffless_hit && 301 === $fluffless_hit['code'] && home_url( '/fl-test-nouvelle' ) === $fluffless_hit['target'], '301 simple : ' . wp_json_encode( $fluffless_hit ) );

	// Même source : remplacée, pas dupliquée.
	$fluffless_again = Store::add_redirect( '/fl-test-ancienne', '/fl-test-autre', 302 );
	$fluffless_hit   = Redirects::decide( '/fl-test-ancienne', true );
	$fluffless_assert( null !== $fluffless_hit && 302 === $fluffless_hit['code'] && str_ends_with( $fluffless_hit['target'], '/fl-test-autre' ), 'Remplacement d\'une source : ' . wp_json_encode( $fluffless_hit ) );
	$fluffless_ids[] = $fluffless_again;

	// Page existante : pas de redirection tant que « partout » est éteint.
	$fluffless_assert( null === Redirects::decide( '/fl-test-ancienne', false ), 'Une page existante ne doit pas être redirigée par défaut.' );

	// Regex avec groupe, encodé ; résultat hors site refusé.
	$fluffless_ids[] = Store::add_redirect( '^/fl-test-blog/(.+)$', '/fl-test-articles/$1', 301, true );
	$fluffless_hit   = Redirects::decide( '/fl-test-blog/mon texte', true );
	$fluffless_assert( null !== $fluffless_hit && home_url( '/fl-test-articles/mon%20texte' ) === $fluffless_hit['target'], 'Regex : ' . wp_json_encode( $fluffless_hit ) );
	$fluffless_ids[] = Store::add_redirect( '^/fl-test-go/(.*)$', '/$1', 301, true );
	$fluffless_hit   = Redirects::decide( '/fl-test-go//evil.example', true );
	$fluffless_home  = wp_parse_url( home_url(), PHP_URL_HOST );
	$fluffless_assert( null === $fluffless_hit || wp_parse_url( $fluffless_hit['target'], PHP_URL_HOST ) === $fluffless_home, 'Redirection ouverte vers un autre hôte : ' . wp_json_encode( $fluffless_hit ) );

	// 410.
	$fluffless_ids[] = Store::add_redirect( '/fl-test-parti', 'ignoré', 410 );
	$fluffless_hit   = Redirects::decide( '/fl-test-parti', true );
	$fluffless_assert( null !== $fluffless_hit && 410 === $fluffless_hit['code'], '410 : ' . wp_json_encode( $fluffless_hit ) );

	// Erreurs : code inconnu, motif invalide, cible dangereuse.
	$fluffless_assert( $fluffless_throws( static fn() => Store::add_redirect( '/x', '/y', 999 ) ), 'Code 999 accepté.' );
	$fluffless_assert( $fluffless_throws( static fn() => Store::add_redirect( '^/(x', '/y', 301, true ) ), 'Motif invalide accepté.' );
	$fluffless_assert( $fluffless_throws( static fn() => Store::add_redirect( '/x', 'javascript:alert(1)', 301 ) ), 'Cible javascript: acceptée.' );

	// Journal : chemin, compteur, date ; ni IP ni agent utilisateur (Loi 25).
	Store::forget_404( '/fl-test-introuvable' );
	$fluffless_assert( null === Redirects::decide( '/fl-test-introuvable', true ), 'Aucune règle : décision nulle attendue.' );
	Redirects::decide( '/fl-test-introuvable', true );
	$fluffless_row = array_values( array_filter( Store::not_found( 1000 ), static fn( array $row ): bool => '/fl-test-introuvable' === $row['url'] ) );
	$fluffless_assert( 1 === count( $fluffless_row ) && 2 === $fluffless_row[0]['hits'], 'Journal : une ligne à 2 visites attendue, reçu ' . wp_json_encode( $fluffless_row ) );
	$fluffless_columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Store::log_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table interne, test.
	$fluffless_assert( array() === preg_grep( '/ip|agent|referer|user/i', $fluffless_columns ), 'Colonnes personnelles dans le journal : ' . implode( ', ', $fluffless_columns ) );
	$fluffless_assert( Store::purge() >= 0, 'purge() doit rendre un nombre de lignes positif ou nul.' );

	// Journal éteint : rien d'écrit.
	Settings::update( array( 'log404' => false ) );
	Settings::flush();
	Redirects::decide( '/fl-test-introuvable-2', true );
	$fluffless_assert( array() === array_filter( Store::not_found( 1000 ), static fn( array $row ): bool => '/fl-test-introuvable-2' === $row['url'] ), 'Journal éteint mais écrit.' );

	// Journal plafonné : des visiteurs anonymes ne peuvent pas le faire grossir sans fin (P6a-02).
	// Plafond réduit à 20 pour le test ; plein, il cède les adresses les moins vues pour garder les nouvelles.
	$fluffless_cap = static fn(): int => 20;
	add_filter( 'fluffless_seo_404_max_rows', $fluffless_cap );
	for ( $fluffless_i = 1; $fluffless_i <= 30; $fluffless_i++ ) {
		$fluffless_paths[] = '/fl-test-plafond-' . $fluffless_i;
		Store::log_404( '/fl-test-plafond-' . $fluffless_i );
	}
	remove_filter( 'fluffless_seo_404_max_rows', $fluffless_cap );
	$fluffless_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Store::log_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table interne, test.
	$fluffless_assert( $fluffless_rows <= 20, 'Journal 404 sans plafond : ' . $fluffless_rows . ' lignes pour un plafond de 20.' );
	$fluffless_assert( array() !== array_filter( Store::not_found( 500 ), static fn( array $row ): bool => '/fl-test-plafond-30' === $row['url'] ), 'Journal plein : la dernière adresse introuvable doit être gardée.' );

	// CSV : en-tête ignoré, lignes valides ajoutées, lignes fautives rapportées avec leur numéro.
	$fluffless_csv = (string) wp_tempnam( 'fl-seo.csv' );
	file_put_contents( $fluffless_csv, "source,target,code,regex\n/fl-test-csv-1,/fl-test-a,301,0\n/fl-test-csv-2,https://example.org/b,302,0\n/fl-test-csv-3,/c,999,0\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fichier temporaire de test.
	$fluffless_import = Store::import_csv( $fluffless_csv );
	$fluffless_assert( 2 === $fluffless_import['added'] && 1 === count( $fluffless_import['errors'] ) && str_contains( $fluffless_import['errors'][0], '4' ), 'Import CSV : ' . wp_json_encode( $fluffless_import ) );
	$fluffless_paths[] = '/fl-test-csv-1';
	try {
		Store::import_csv( '/fichier/absent.csv' );
		$fluffless_assert( false, 'Fichier absent : RuntimeException attendue.' );
	} catch ( RuntimeException $error ) {
		$fluffless_assert( str_contains( $error->getMessage(), 'absent.csv' ), 'Le message doit nommer le fichier : ' . $error->getMessage() );
	}
} finally {
	foreach ( Store::redirects( 1000 ) as $fluffless_rule ) {
		if ( str_contains( $fluffless_rule['source'], 'fl-test-' ) ) {
			Store::delete_redirect( $fluffless_rule['id'] );
		}
	}
	foreach ( array_merge( $fluffless_paths, array( '/fl-test-introuvable-2' ) ) as $fluffless_path ) {
		Store::forget_404( $fluffless_path );
	}
	if ( '' !== $fluffless_csv ) {
		wp_delete_file( $fluffless_csv );
	}
	if ( false === $fluffless_saved ) {
		delete_option( Settings::NAME );
	} else {
		update_option( Settings::NAME, $fluffless_saved );
	}
	Settings::flush();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles (redirections) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}

echo esc_html( 'OK : ' . $fluffless_checks . " contrôles réussis (redirections : 301, 302, regex, 410, erreurs, journal sans données personnelles, CSV).\n" );
