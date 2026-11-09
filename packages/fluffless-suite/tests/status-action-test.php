<?php
/**
 * Tests des boutons sous l'état d'un module (status_action) : Sensei LMS, WooCommerce, Réviseur.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/status-action-test.php
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Admin\PluginAction;
use Fluffless\Suite\Modules\Courses;
use Fluffless\Suite\Modules\Reviewer;
use Fluffless\Suite\Modules\Shop;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( PluginAction::class ) ) {
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

$fluffless_user = get_current_user_id();
$fluffless_new  = array();
try {
	// Sans droit : aucun bouton, ni pour une extension ni pour un compte.
	wp_set_current_user( 0 );
	$fluffless_assert( null === PluginAction::for_plugin( 'extension-absente/extension-absente.php', 'Absente' ), 'Sans droit : aucun bouton d\'extension.' );
	$fluffless_assert( null === Reviewer::status_action(), 'Sans droit : aucun bouton de compte Réviseur.' );

	$fluffless_admin = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	wp_set_current_user( (int) ( $fluffless_admin[0] ?? 1 ) );

	// Extension absente : installation depuis le répertoire.
	$fluffless_missing = PluginAction::for_plugin( 'extension-absente/extension-absente.php', 'Absente' );
	$fluffless_assert( is_array( $fluffless_missing ) && 'Install Absente' === $fluffless_missing['text'], 'Extension absente : bouton Installer.' );
	$fluffless_assert( is_array( $fluffless_missing ) && str_contains( $fluffless_missing['url'], 'plugin-install.php' ) && str_contains( $fluffless_missing['url'], 'plugin=extension-absente' ), 'Extension absente : lien vers sa fiche d\'installation.' );

	// Extension installée : lien d'activation avec le nonce du cœur.
	$fluffless_installed = PluginAction::for_plugin( 'fluffless-suite/fluffless-suite.php', 'Fluffless Suite' );
	$fluffless_assert( is_array( $fluffless_installed ) && 'Activate Fluffless Suite' === $fluffless_installed['text'], 'Extension installée : bouton Activer.' );
	$fluffless_assert( is_array( $fluffless_installed ) && str_contains( $fluffless_installed['url'], 'action=activate' ) && str_contains( $fluffless_installed['url'], '_wpnonce=' ), 'Extension installée : lien d\'activation avec nonce.' );

	// Modules : bouton seulement quand l'extension manque.
	$fluffless_courses = Courses::status_action();
	$fluffless_assert( Courses::sensei_active() ? null === $fluffless_courses : ( is_array( $fluffless_courses ) && str_contains( $fluffless_courses['text'], 'Sensei LMS' ) ), 'Cours : bouton Sensei LMS seulement si Sensei manque.' );
	$fluffless_shop = Shop::status_action();
	$fluffless_assert( Shop::woocommerce_active() ? ( null === $fluffless_shop || str_contains( $fluffless_shop['text'], 'Fluffless Consent' ) ) : ( is_array( $fluffless_shop ) && str_contains( $fluffless_shop['text'], 'WooCommerce' ) ), 'Boutique : bouton WooCommerce seulement si WooCommerce manque.' );

	// Réviseur : état sans compte, bouton d'ajout toujours offert.
	if ( 0 === Reviewer::count_accounts() ) {
		$fluffless_assert( str_contains( Reviewer::status(), 'No Reviewer account yet' ) && str_contains( Reviewer::status(), 'Users → Add User' ), 'Réviseur : sans compte, l\'état dit comment en créer un.' );
		$fluffless_new[] = wp_insert_user(
			array(
				'user_login' => 'fl-reviseur-test',
				'user_pass'  => wp_generate_password(),
				'role'       => Reviewer::ROLE,
			)
		);
	}
	$fluffless_assert( Reviewer::count_accounts() > 0, 'Réviseur : compte compté après création.' );
	$fluffless_assert( '' === Reviewer::status(), 'Réviseur : avec un compte, aucun avertissement.' );
	$fluffless_action = Reviewer::status_action();
	$fluffless_assert( is_array( $fluffless_action ) && str_contains( $fluffless_action['url'], 'user-new.php' ), 'Réviseur : bouton d\'ajout de compte offert.' );

	// L'écran Modules affiche le bouton.
	$fluffless_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsPage.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local de test.
	$fluffless_assert( str_contains( $fluffless_source, "'status_action'" ) && str_contains( $fluffless_source, 'fl-module__action' ), 'Écran Modules : bouton status_action rendu.' );
} finally {
	foreach ( $fluffless_new as $fluffless_id ) {
		if ( is_int( $fluffless_id ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $fluffless_id );
		}
	}
	wp_set_current_user( $fluffless_user );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Boutons sous l’état des modules, {$fluffless_checks} vérifications réussies.\n" );
