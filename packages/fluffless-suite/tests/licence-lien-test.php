<?php
/**
 * Tests du chemin vers la clé de licence : l'accueil renvoie au champ quand les mises à jour en dépendent.
 *
 * Lancer : tools/run-tests.sh licence-lien
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Admin\Home;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Modules\Updates;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Updates::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}

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

$fluffless_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );

$fluffless_saved = get_option( Lifecycle::OPTION_LICENCE, null );
try {
	delete_option( Lifecycle::OPTION_LICENCE );
	$fluffless_status = Updates::status();
	$fluffless_assert( str_contains( $fluffless_status, 'licence' ), 'licence : sans clé, l\'état des mises à jour doit parler de licence : ' . $fluffless_status );

	$fluffless_target  = SettingsPage::url( 'fl-title-updates' );
	$fluffless_actions = Home::actions();
	$fluffless_urls    = array_column( $fluffless_actions, 'url' );
	$fluffless_assert( in_array( $fluffless_target, $fluffless_urls, true ), 'licence : l\'accueil doit proposer une action vers ' . $fluffless_target . ', actions : ' . wp_json_encode( $fluffless_urls ) );

	// Le lien et le panneau : la cible de l'ancre existe sur l'écran Modules.
	ob_start();
	SettingsPage::render();
	$fluffless_screen = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_screen, 'id="fl-title-updates"' ), 'licence : la cible fl-title-updates doit exister sur l\'écran Modules' );
	$fluffless_assert( str_contains( $fluffless_screen, 'id="fluffless-licence-key"' ), 'licence : le champ de clé doit exister sur l\'écran Modules' );

	// P4-06 : licence échue, l'accueil propose de la renouveler (pas d'ajouter une clé), avec un lien direct.
	update_option( Lifecycle::OPTION_LICENCE, 'FL-TEST-' . wp_generate_password( 16, false ), false );
	set_transient( Lifecycle::TRANSIENT_LICENCE, array( 'state' => 'licence_expired' ), HOUR_IN_SECONDS );
	$fluffless_actions = Home::actions();
	$fluffless_texts   = implode( ' | ', array_column( $fluffless_actions, 'text' ) );
	$fluffless_urls    = array_column( $fluffless_actions, 'url' );
	$fluffless_assert( ! str_contains( $fluffless_texts, 'Add your licence key' ) && str_contains( $fluffless_texts, 'Renew your licence' ), 'P4-06 : échue, l\'accueil dit : ' . $fluffless_texts );
	$fluffless_assert( in_array( 'https://fluffless.dev/en/pricing/', $fluffless_urls, true ), 'P4-06 : échue hors grâce, lien de renouvellement absent : ' . wp_json_encode( $fluffless_urls ) );
	ob_start();
	Updates::render_licence();
	$fluffless_card = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_card, 'href="https://fluffless.dev/en/pricing/"' ), 'P4-06 : carte sans lien de renouvellement' );
	// Pendant la grâce : le lien signé de l'API.
	$fluffless_renew = \Fluffless\Suite\Core\Api::base() . '/v1/licences/renew?licence=1&until=2&token=abc';
	set_transient(
		Lifecycle::TRANSIENT_LICENCE,
		array(
			'state'   => 'licence_expired',
			'details' => array(
				'expires'     => time() - DAY_IN_SECONDS,
				'grace_until' => time() + 10 * DAY_IN_SECONDS,
				'renew_url'   => $fluffless_renew,
			),
		),
		HOUR_IN_SECONDS
	);
	$fluffless_assert( in_array( $fluffless_renew, array_column( Home::actions(), 'url' ), true ), 'P4-06 : grâce, l\'accueil ne mène pas au lien signé' );
} finally {
	delete_transient( Lifecycle::TRANSIENT_LICENCE );
	delete_option( Lifecycle::OPTION_LICENCE );
	wp_set_current_user( 0 );
	if ( null !== $fluffless_saved ) {
		update_option( Lifecycle::OPTION_LICENCE, $fluffless_saved, false );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Lien vers la licence, {$fluffless_checks} vérifications réussies.\n" );
