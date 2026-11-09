<?php
/**
 * Désinstallation de Fluffless Consent : l'option des contenus intégrés et la section « consent » sont
 * retirées, les autres sections de fluffless_settings (Fluffless Suite) restent intactes. Rien n'est
 * retiré quand la case « Garder mes données » de Fluffless Suite est cochée ou que la constante
 * FLUFFLESS_KEEP_DATA est vraie (même règle que Fluffless Suite).
 *
 * Lancer sur un banc jetable seulement (le test efface des données) :
 *   wp option update fluffless_test_jetable 1
 *   wp eval-file wp-content/plugins/fluffless-consent/tests/consent-uninstall-jetable.php
 *
 * @package Fluffless_Consent
 */

defined( 'ABSPATH' ) || exit;

if ( '1' !== (string) get_option( 'fluffless_test_jetable', '' ) ) {
	throw new RuntimeException( 'Ce test efface des données : banc jetable seulement (option fluffless_test_jetable absente).' );
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'fluffless-consent/fluffless-consent.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante de WordPress simulée, banc jetable.
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

update_option(
	'fluffless_settings',
	array(
		'consent' => array( 'enabled' => true ),
		'modules' => array( 'form' => true ),
		'form'    => array( 'keep_days' => 30 ),
	)
);
update_option( 'fluffless_consent_embeds', array( 'youtube' => true ) );

require dirname( __DIR__ ) . '/uninstall.php';
wp_cache_flush();

$fluffless_after = get_option( 'fluffless_settings' );
$fluffless_assert( false === get_option( 'fluffless_consent_embeds', false ), 'l\'option fluffless_consent_embeds devrait être absente' );
$fluffless_assert( is_array( $fluffless_after ) && ! array_key_exists( 'consent', $fluffless_after ), 'la section consent devrait être retirée' );
$fluffless_assert( is_array( $fluffless_after ) && true === ( $fluffless_after['modules']['form'] ?? null ) && 30 === ( $fluffless_after['form']['keep_days'] ?? null ), 'les options de la Suite devraient rester intactes, reçu : ' . wp_json_encode( $fluffless_after ) );

// 2. Case « Garder mes données » cochée (fluffless_settings[form][keep_data]) : rien n'est retiré.
$fluffless_kept_settings = array(
	'consent' => array( 'enabled' => true ),
	'modules' => array( 'form' => true ),
	'form'    => array(
		'keep_days' => 30,
		'keep_data' => true,
	),
);
update_option( 'fluffless_settings', $fluffless_kept_settings );
update_option( 'fluffless_consent_embeds', array( 'youtube' => true ) );
require dirname( __DIR__ ) . '/uninstall.php';
wp_cache_flush();
$fluffless_assert( array( 'youtube' => true ) === get_option( 'fluffless_consent_embeds', false ), 'case cochée : l\'option fluffless_consent_embeds devrait rester' );
$fluffless_assert( get_option( 'fluffless_settings' ) === $fluffless_kept_settings, 'case cochée : fluffless_settings devrait rester intacte, reçu : ' . wp_json_encode( get_option( 'fluffless_settings' ) ) );

// 3. Constante FLUFFLESS_KEEP_DATA (dernier : une constante ne se retire pas).
define( 'FLUFFLESS_KEEP_DATA', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante documentée de wp-config.php, banc jetable.
update_option( 'fluffless_settings', array( 'consent' => array( 'enabled' => true ) ) );
update_option( 'fluffless_consent_embeds', array( 'youtube' => true ) );
require dirname( __DIR__ ) . '/uninstall.php';
wp_cache_flush();
$fluffless_assert( array( 'youtube' => true ) === get_option( 'fluffless_consent_embeds', false ), 'constante : l\'option fluffless_consent_embeds devrait rester' );
$fluffless_assert( array( 'consent' => array( 'enabled' => true ) ) === get_option( 'fluffless_settings' ), 'constante : la section consent devrait rester, reçu : ' . wp_json_encode( get_option( 'fluffless_settings' ) ) );

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( count( $fluffless_failures ) . ' échec(s) sur ' . $fluffless_checks . " contrôles :\n- " . implode( "\n- ", $fluffless_failures ) );
}
WP_CLI::success( $fluffless_checks . ' contrôles de désinstallation de Consent passés.' );
