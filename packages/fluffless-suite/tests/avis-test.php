<?php
/**
 * Tests du premier contact dans l'administration : un seul avis de mode réduit, fermable et mémorisé.
 *
 * Lancer : tools/run-tests.sh avis
 *
 * @package Fluffless_Suite
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.WP.GlobalVariablesOverride.Prohibited

use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Options;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Loader::class ) ) {
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
$fluffless_admin  = (int) ( $fluffless_admins[0] ?? 0 );
wp_set_current_user( $fluffless_admin );

// Deux modules dépendent d'un module éteint (un élément du pied réservé au mobile, Rapport carbone sans Statistiques).
$fluffless_defaults = static function (): array {
	return array(
		'modules' => array(
			'footer'     => true,
			'visibility' => false,
			'carbone'    => true,
			'stats'      => false,
		),
		'footer'  => array( 'credit_device' => 'mobile' ),
	);
};

try {
	delete_user_meta( $fluffless_admin, 'fluffless_unmet_dismissed' );
	add_filter( 'pre_option_' . Options::NAME, $fluffless_defaults );
	Options::flush();
	Loader::boot_modules( Loader::modules() );
	$fluffless_assert( count( Loader::unmet() ) >= 2, 'avis : au moins deux modules en mode réduit attendus : ' . wp_json_encode( Loader::unmet() ) );

	ob_start();
	Loader::notice_unmet();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( 1 === substr_count( $fluffless_html, 'class="notice' ), 'avis : un seul avis attendu pour tous les modules en mode réduit, trouvé ' . substr_count( $fluffless_html, 'class="notice' ) );
	$fluffless_assert( str_contains( $fluffless_html, 'Footer' ) && str_contains( $fluffless_html, 'Carbon' ), 'avis : l\'avis unique doit nommer les deux modules : ' . $fluffless_html );
	$fluffless_assert( str_contains( $fluffless_html, 'fluffless_dismiss_unmet' ) && str_contains( $fluffless_html, '_wpnonce=' ), 'avis : lien pour fermer l\'avis (avec nonce) absent' );

	// Fermé, l'avis reste fermé tant que l'ensemble des manques ne change pas.
	Loader::remember_dismissal( $fluffless_admin );
	ob_start();
	Loader::notice_unmet();
	$fluffless_closed = (string) ob_get_clean();
	$fluffless_assert( '' === $fluffless_closed, 'avis : l\'avis fermé réapparaît : ' . $fluffless_closed );

	// Un nouveau manque le fait revenir.
	remove_filter( 'pre_option_' . Options::NAME, $fluffless_defaults );
	$fluffless_more = static function () use ( $fluffless_defaults ): array {
		$opt                        = $fluffless_defaults();
		$opt['modules']['audit']    = false;
		$opt['modules']['reviewer'] = true;
		return $opt;
	};
	add_filter( 'pre_option_' . Options::NAME, $fluffless_more );
	Options::flush();
	Loader::boot_modules( Loader::modules() );
	ob_start();
	Loader::notice_unmet();
	$fluffless_back = (string) ob_get_clean();
	$fluffless_assert( '' !== $fluffless_back, 'avis : un nouveau manque doit rouvrir l\'avis' );
	remove_filter( 'pre_option_' . Options::NAME, $fluffless_more );

	// Résultat d'import : tout en haut de l'écran Modules, et l'avis de mode réduit s'efface devant lui.
	delete_user_meta( $fluffless_admin, 'fluffless_unmet_dismissed' );
	add_filter( 'pre_option_' . Options::NAME, $fluffless_defaults );
	Options::flush();
	Loader::boot_modules( Loader::modules() );
	wp_set_current_user( $fluffless_admin );
	$_GET['fl_imported'] = '1';
	ob_start();
	Loader::notice_unmet();
	$fluffless_during = (string) ob_get_clean();
	$fluffless_assert( '' === $fluffless_during, 'import : l\'avis de mode réduit doit s\'effacer quand un résultat d\'import est affiché' );
	ob_start();
	SettingsPage::render();
	$fluffless_screen = (string) ob_get_clean();
	unset( $_GET['fl_imported'] );
	$fluffless_pos_result = strpos( $fluffless_screen, 'Settings imported' );
	$fluffless_pos_form   = strpos( $fluffless_screen, 'options.php' );
	$fluffless_assert( false !== $fluffless_pos_result && 1 === substr_count( $fluffless_screen, 'Settings imported' ), 'import : le résultat doit paraître une seule fois' );
	$fluffless_assert( false !== $fluffless_pos_result && false !== $fluffless_pos_form && $fluffless_pos_result < $fluffless_pos_form, 'import : le résultat doit précéder le formulaire des modules, pas la section du bas' );
	remove_filter( 'pre_option_' . Options::NAME, $fluffless_defaults );

	// Sans droit de gestion, rien.
	wp_set_current_user( 0 );
	ob_start();
	Loader::notice_unmet();
	$fluffless_nobody = (string) ob_get_clean();
	$fluffless_assert( '' === $fluffless_nobody, 'avis : un visiteur ne doit rien voir' );
} finally {
	wp_set_current_user( 0 );
	delete_user_meta( $fluffless_admin, 'fluffless_unmet_dismissed' );
	remove_filter( 'pre_option_' . Options::NAME, $fluffless_defaults );
	Loader::reset();
	Loader::boot_modules( Loader::modules() );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Avis d'administration, {$fluffless_checks} vérifications réussies.\n" );
