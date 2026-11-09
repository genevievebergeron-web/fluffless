<?php
/**
 * Tests de l'écran Modules : titres de groupe, navigation par ancres, recherche.
 *
 * Lancer : tools/run-tests.sh modules-page
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Loader;

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
wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );

try {
	$fluffless_groups = SettingsPage::groups( Loader::modules() );
	ob_start();
	SettingsPage::render();
	$fluffless_html = (string) ob_get_clean();

	$fluffless_assert( substr_count( $fluffless_html, '<table class="fl-modules' ) === count( $fluffless_groups ), 'modules : un tableau par groupe attendu' );
	$fluffless_assert( substr_count( $fluffless_html, '<h2 class="fl-modules__title">' ) === count( $fluffless_groups ), 'modules : un titre h2 par groupe attendu, trouvé ' . substr_count( $fluffless_html, '<h2 class="fl-modules__title">' ) );
	foreach ( array_keys( $fluffless_groups ) as $fluffless_gid ) {
		$fluffless_assert( str_contains( $fluffless_html, 'href="#fl-group-' . $fluffless_gid . '"' ), "modules : lien d'ancre vers le groupe {$fluffless_gid} absent" );
	}
	// P5-07 : chaque module de base a un groupe nommé et une icône à lui ; l'écran n'a plus de groupe « Autres ».
	$fluffless_assert( ! isset( $fluffless_groups['other'] ), 'modules : modules sans groupe nommé : ' . implode( ', ', $fluffless_groups['other']['modules'] ?? array() ) );
	$fluffless_assert( in_array( 'newsletter', $fluffless_groups['reach']['modules'] ?? array(), true ), 'modules : Infolettre attendue dans « Joindre les gens »' );
	$fluffless_icons = ( new ReflectionClassConstant( \Fluffless\Suite\Admin\Icon::class, 'MODULES' ) )->getValue();
	foreach ( array_keys( Loader::defaults() ) as $fluffless_id ) {
		$fluffless_assert( isset( $fluffless_icons[ $fluffless_id ] ) && '' !== \Fluffless\Suite\Admin\Icon::svg( $fluffless_icons[ $fluffless_id ] ), "modules : {$fluffless_id} sans icône attitrée" );
	}
	$fluffless_assert( array() === array_diff( array_keys( $fluffless_icons ), array_keys( Loader::defaults() ) ), 'modules : icônes de modules qui n\'existent pas : ' . implode( ', ', array_diff( array_keys( $fluffless_icons ), array_keys( Loader::defaults() ) ) ) );
	// P4-09 : les messages renvoient à « Fluffless → Modules » ; l'écran porte ce nom.
	$fluffless_assert( str_contains( $fluffless_html, '<h1>Modules</h1>' ), 'modules : le titre de l\'écran doit être « Modules », comme son entrée de menu' );
	$fluffless_assert( str_contains( $fluffless_html, 'type="search"' ), 'modules : champ de recherche absent' );
	$fluffless_assert( str_contains( $fluffless_html, 'data-fl-modules-search' ), 'modules : le champ de recherche doit porter data-fl-modules-search' );
	$fluffless_assert( preg_match( '/<input[^>]*type="search"[^>]*hidden|<[^>]*hidden[^>]*>\s*<label[^>]*>[^<]*<\/label>\s*<input[^>]*type="search"/', $fluffless_html ) === 1, 'modules : sans JavaScript la recherche doit rester masquée (attribut hidden)' );
} finally {
	wp_set_current_user( 0 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Écran Modules, {$fluffless_checks} vérifications réussies.\n" );
