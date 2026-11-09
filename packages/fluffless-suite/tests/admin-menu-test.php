<?php
/**
 * Tests du menu regroupé : registre d'onglets, anciennes adresses, accueil, onglet Modules.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/admin-menu-test.php
 *
 * @package Fluffless_Suite
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.WP.GlobalVariablesOverride.Prohibited

use Fluffless\Suite\Admin\Home;
use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Admin\ThemeNotice;
use WP_Post;
use Fluffless\Suite\Presentation\Patterns;
use Fluffless\Suite\Core\Lifecycle;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Menu::class ) ) {
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

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$fluffless_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );

try {
	// Construction du menu, comme sur un écran d'administration ordinaire.
	// En WP-CLI, is_admin() est faux : l'extension n'a pas accroché son onglet Modules.
	if ( false === has_action( 'admin_menu', array( SettingsPage::class, 'add_page' ) ) ) {
		add_action( 'admin_menu', array( SettingsPage::class, 'add_page' ) );
	}
	$GLOBALS['menu']    = array();
	$GLOBALS['submenu'] = array();
	$GLOBALS['pagenow'] = 'admin.php';
	do_action( 'admin_menu', '' );

	$fluffless_submenu = static fn(): array => (array) ( $GLOBALS['submenu'][ Menu::SLUG ] ?? array() );
	$fluffless_pages   = static fn(): array => (array) ( $GLOBALS['_registered_pages'] ?? array() );
	$fluffless_slugs   = array_column( $fluffless_submenu(), 2 );
	$fluffless_assert( Menu::SLUG === ( $fluffless_slugs[0] ?? '' ), 'menu : Accueil absent ou pas en tête' );
	$fluffless_assert( in_array( Menu::SLUG, array_column( (array) $GLOBALS['menu'], 2 ), true ), 'menu : entrée principale absente' );
	$fluffless_assert( 'settings' === Menu::group_of( SettingsPage::SLUG ), 'registre : Modules hors du groupe Réglages' );
	$fluffless_assert( SettingsPage::SLUG === array_key_first( Menu::tabs( 'settings' ) ), 'registre : Modules pas en tête de Réglages' );
	$fluffless_assert( __( 'Palette', 'fluffless-suite' ) === ( Menu::tabs( 'appearance' )['fluffless-palette'] ?? '' ), 'registre : titre différé (fermeture) non résolu en chaîne' );
	$fluffless_assert( __( 'Modules', 'fluffless-suite' ) === ( Menu::tabs( 'settings' )[ SettingsPage::SLUG ] ?? '' ), 'registre : titre de Modules non résolu' );

	// Chaque onglet garde son adresse admin.php?page=<slug> et le crochet que les modules attendent.
	$fluffless_expected = 0;
	foreach ( Menu::GROUPS as $fluffless_group ) {
		$fluffless_tabs      = Menu::tabs( $fluffless_group );
		$fluffless_expected += array() === $fluffless_tabs ? 0 : 1;
		foreach ( array_keys( $fluffless_tabs ) as $fluffless_slug ) {
			$fluffless_hook = get_plugin_page_hookname( $fluffless_slug, Menu::SLUG );
			$fluffless_assert( 'fluffless_page_' . $fluffless_slug === $fluffless_hook, "crochet inattendu pour {$fluffless_slug} : {$fluffless_hook}" );
			$fluffless_assert( isset( $fluffless_pages()[ $fluffless_hook ] ), "page non enregistrée : {$fluffless_slug}" );
			$fluffless_assert( in_array( $fluffless_slug, $fluffless_slugs, true ), "onglet absent du menu avant masquage : {$fluffless_slug}" );
		}
	}
	foreach ( array( 'fluffless-palette', 'fluffless-shape' ) as $fluffless_slug ) {
		if ( null !== Menu::group_of( $fluffless_slug ) ) {
			$fluffless_assert( 'appearance' === Menu::group_of( $fluffless_slug ), "registre : {$fluffless_slug} hors d'Apparence" );
		}
	}

	// Chaque entrée de menu porte le titre de l'écran qu'elle ouvre, jamais le nom du groupe (relais 11).
	foreach ( Menu::GROUPS as $fluffless_group ) {
		$fluffless_tabs = Menu::tabs( $fluffless_group );
		if ( array() === $fluffless_tabs ) {
			continue;
		}
		$fluffless_entry = array_values( array_filter( $fluffless_submenu(), static fn( array $item ): bool => array_key_first( $fluffless_tabs ) === $item[2] ) )[0] ?? array();
		$fluffless_assert( reset( $fluffless_tabs ) === ( $fluffless_entry[0] ?? '' ), "menu : entrée de {$fluffless_group} sans le titre de son premier écran" );
		$fluffless_assert( Menu::group_label( $fluffless_group ) !== ( $fluffless_entry[0] ?? '' ), "menu : entrée de {$fluffless_group} nommée par le groupe" );
	}
	$fluffless_kits = array_values( array_filter( $fluffless_submenu(), static fn( array $item ): bool => 'fluffless-kits' === $item[2] ) )[0] ?? array();
	if ( array() !== $fluffless_kits ) {
		$fluffless_assert( __( 'Site kits', 'fluffless-suite' ) === $fluffless_kits[0], 'menu : entrée Trousses de site' );
	}
	$fluffless_settings_entry = array_values( array_filter( $fluffless_submenu(), static fn( array $item ): bool => SettingsPage::SLUG === $item[2] ) )[0] ?? array();
	$fluffless_assert( __( 'Modules', 'fluffless-suite' ) === ( $fluffless_settings_entry[0] ?? '' ), 'menu : entrée Modules' );

	// Après admin_head : Accueil + une entrée par groupe non vide, les pages restent accessibles.
	Menu::hide_tabs();
	$fluffless_count = count( $fluffless_submenu() );
	$fluffless_assert( 1 + $fluffless_expected === $fluffless_count, "menu : {$fluffless_count} entrées après masquage, attendu " . ( 1 + $fluffless_expected ) );
	$fluffless_assert( isset( $fluffless_pages()[ 'fluffless_page_' . SettingsPage::SLUG ] ), 'masquage : page Modules désenregistrée' );

	// Onglets de test, déclarés après la construction pour ne pas fausser les comptes ci-dessus.
	$fluffless_noop = static function (): void {};
	Menu::add_tab( 'media', 'fl-test-late', 'Late', $fluffless_noop, 99 );
	Menu::add_tab( 'media', 'fl-test-early', 'Early', $fluffless_noop, 1 );
	Menu::add_tab( 'nowhere', 'fl-test-lost', 'Lost', $fluffless_noop );
	$fluffless_media = array_keys( Menu::tabs( 'media' ) );
	$fluffless_assert( 'fl-test-early' === $fluffless_media[0] && 'fl-test-late' === end( $fluffless_media ), 'registre : ordre des onglets non respecté' );
	$fluffless_assert( 'settings' === Menu::group_of( 'fl-test-lost' ), 'registre : groupe inconnu non rangé dans Réglages' );
	$fluffless_assert( null === Menu::group_of( 'nope' ), 'registre : slug inconnu rattaché à un groupe' );

	// Barre d'onglets : liens, un seul aria-current, sur la bonne page.
	$fluffless_nav = Menu::nav( 'media', 'fl-test-late' );
	$fluffless_assert( str_contains( $fluffless_nav, '<nav class="fl-tabs" aria-label="Media">' ), 'nav : repère de navigation absent' );
	$fluffless_assert( 1 === substr_count( $fluffless_nav, 'aria-current="page"' ), 'nav : aria-current absent ou multiple' );
	$fluffless_assert( 1 === preg_match( '#<a href="[^"]*page=fl-test-late" aria-current="page">Late</a>#', $fluffless_nav ), 'nav : aria-current sur le mauvais onglet' );
	$fluffless_assert( ! str_contains( $fluffless_nav, 'role="tab' ), 'nav : rôles de tablist ARIA' );
	$fluffless_assert( '' === Menu::nav( 'nowhere', 'x' ), 'nav : barre affichée pour un groupe vide' );

	// Surbrillance : un onglet masqué éclaire l'entrée de son groupe.
	$fluffless_assert( 'fl-test-early' === Menu::submenu_file( 'fl-test-late' ), 'submenu_file : groupe non éclairé' );
	$fluffless_assert( 'autre.php' === Menu::submenu_file( 'autre.php' ), 'submenu_file : entrée étrangère modifiée' );
	$fluffless_assert( null === Menu::submenu_file( null ), 'submenu_file : null modifié' );

	// Anciennes adresses.
	$fluffless_assert( Menu::url( SettingsPage::SLUG ) === Menu::legacy_url( 'options-general.php', array( 'page' => 'fluffless' ) ), 'legacy : Réglages → Fluffless non redirigé' );
	$fluffless_assert(
		str_contains(
			(string) Menu::legacy_url(
				'options-general.php',
				array(
					'page'             => 'fluffless',
					'settings-updated' => 'true',
				)
			),
			'settings-updated=true'
		),
		'legacy : settings-updated perdu'
	);
	$fluffless_assert( null === Menu::legacy_url( 'options-general.php', array( 'page' => 'autre' ) ), 'legacy : page étrangère redirigée' );
	$fluffless_assert( null === Menu::legacy_url( 'admin.php', array( 'page' => 'fluffless' ) ), 'legacy : accueil redirigé en boucle' );
	$fluffless_assert( null === Menu::legacy_url( 'options-general.php', array( 'page' => array( 'x' ) ) ), 'legacy : paramètre non textuel accepté' );
	$fluffless_assert( str_starts_with( SettingsPage::url( 'fl-group-present' ), admin_url( 'admin.php?page=fluffless-settings' ) ) && str_ends_with( SettingsPage::url( 'fl-group-present' ), '#fl-group-present' ), 'url : ancre de Réglages' );

	// Accueil : grands chiffres, actions filtrées et validées.
	$fluffless_actions = static function ( array $actions ): array {
		$actions[] = array(
			'text' => 'Valid step',
			'url'  => 'https://example.org/step',
		);
		$actions[] = array(
			'text' => 'Evil step',
			'url'  => 'javascript:alert(1)',
		);
		$actions[] = array( 'text' => 'No url' );
		$actions[] = 'junk';
		return $actions;
	};
	add_filter( 'fluffless_admin_actions', $fluffless_actions );
	ob_start();
	Home::render();
	$fluffless_home = (string) ob_get_clean();
	remove_filter( 'fluffless_admin_actions', $fluffless_actions );
	$fluffless_assert( str_contains( $fluffless_home, '<dl class="fl-figures">' ), 'accueil : chiffres absents' );
	$fluffless_assert( str_contains( $fluffless_home, 'of JavaScript added for visitors' ), 'accueil : chiffre JS absent' );
	$fluffless_assert( str_contains( $fluffless_home, 'href="https://example.org/step"' ), 'accueil : action valide absente' );
	$fluffless_assert( ! str_contains( $fluffless_home, 'Evil step' ) && ! str_contains( $fluffless_home, 'javascript:' ), 'accueil : adresse javascript: acceptée' );
	$fluffless_assert( ! str_contains( $fluffless_home, 'No url' ), 'accueil : action sans adresse acceptée' );
	$fluffless_assert(
		count(
			Home::valid_actions(
				array_fill(
					0,
					50,
					array(
						'text' => 'x',
						'url'  => 'https://example.org',
					)
				)
			)
		) <= Home::MAX_ACTIONS,
		'accueil : actions non bornées'
	);
	$fluffless_assert( array() === Home::valid_actions( 'junk' ), 'accueil : filtre non tableau accepté' );

	// C26 : trois premières étapes numérotées, affichées tant qu'une reste à faire.
	$fluffless_steps = Home::first_steps();
	$fluffless_assert( 3 === count( $fluffless_steps ), 'étapes : pas trois étapes' );
	foreach ( $fluffless_steps as $fluffless_step ) {
		$fluffless_assert( '' !== $fluffless_step['text'] && '' !== esc_url_raw( $fluffless_step['url'] ), 'étapes : forme invalide' );
	}
	$fluffless_assert( str_contains( $fluffless_steps[0]['url'], 'themes.php' ) || str_contains( $fluffless_steps[0]['url'], 'theme-install.php' ), 'étapes : lien du thème absent' );
	// Page d'exemple simulée : une vraie page « sample-page » existe déjà sur une installation neuve (CI) et le slug deviendrait « sample-page-2 ».
	$fluffless_sample_post = new WP_Post( (object) array( 'post_name' => 'sample-page' ) );
	$fluffless_own         = (int) wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Nos services',
		)
	);
	$fluffless_own_post    = get_post( $fluffless_own );
	$fluffless_assert( $fluffless_own_post instanceof WP_Post && ! Home::is_own_page( $fluffless_sample_post ) && Home::is_own_page( $fluffless_own_post ), 'étapes : la page d\'exemple de WordPress compte comme une page écrite' );
	wp_delete_post( $fluffless_own, true );
	$fluffless_assert( str_contains( $fluffless_steps[1]['url'], 'post-new.php?post_type=page' ), 'étapes : lien de création de page absent' );
	$fluffless_assert( str_contains( $fluffless_steps[2]['url'], 'page=fluffless' ), 'étapes : lien de la palette absent' );
	$fluffless_todo = array_filter( $fluffless_steps, static fn( array $step ): bool => ! $step['done'] );
	$fluffless_assert( ( array() !== $fluffless_todo ) === str_contains( $fluffless_home, '<section class="fl-start"' ), 'étapes : section affichée à tort ou manquante' );
	if ( array() !== $fluffless_todo ) {
		$fluffless_assert( str_contains( $fluffless_home, '<ol>' ) && str_contains( $fluffless_home, 'Getting started' ), 'étapes : liste numérotée absente' );
	}

	// C28 : avis global « thème Fluffless absent », un bouton, jamais refermable, seulement pour qui peut changer de thème.
	$fluffless_other_theme   = static fn(): string => 'twentytwentyfive';
	add_filter( 'template', $fluffless_other_theme );
	$fluffless_notice_action = ThemeNotice::action();
	ob_start();
	ThemeNotice::render();
	$fluffless_notice = (string) ob_get_clean();
	remove_filter( 'template', $fluffless_other_theme );
	$fluffless_assert( is_array( $fluffless_notice_action ) && ( str_contains( $fluffless_notice_action['url'], 'themes.php?action=activate&amp;stylesheet=fluffless&amp;_wpnonce=' ) || str_contains( $fluffless_notice_action['url'], 'theme-install.php' ) ), 'avis thème : bouton absent sous un autre thème' );
	$fluffless_assert( str_contains( $fluffless_notice, 'notice-warning' ) && str_contains( $fluffless_notice, 'class="button button-secondary"' ) && ! str_contains( $fluffless_notice, 'is-dismissible' ), 'avis thème : rendu incomplet ou refermable' );
	ob_start();
	ThemeNotice::render();
	$fluffless_assert( Patterns::applies() === ( '' === (string) ob_get_clean() ), 'avis thème : affiché avec le thème actif, ou absent sans lui' );
	// Thème absent : installation par ZIP (écran de téléversement), jamais la recherche du répertoire WordPress.org.
	$fluffless_install_url = ThemeNotice::install_url();
	$fluffless_assert( str_contains( $fluffless_install_url, 'theme-install.php' ) && ! str_contains( $fluffless_install_url, 'search=' ), 'avis thème : installation menée vers la recherche du répertoire' );
	wp_set_current_user( 0 );
	add_filter( 'template', $fluffless_other_theme );
	$fluffless_assert( null === ThemeNotice::action(), 'avis thème : offert sans droit switch_themes' );
	remove_filter( 'template', $fluffless_other_theme );
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );

	// C31 : icône de menu propre, pied à deux liens (Aide, Signaler un problème), adresse d'aide filtrable et bornée.
	$fluffless_menu_icon = '';
	foreach ( (array) $GLOBALS['menu'] as $fluffless_entry ) {
		if ( Menu::SLUG === ( $fluffless_entry[2] ?? '' ) ) {
			$fluffless_menu_icon = (string) ( $fluffless_entry[6] ?? '' );
		}
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- on relit l'icône SVG encodée par Menu::build().
	$fluffless_assert( str_starts_with( $fluffless_menu_icon, 'data:image/svg+xml;base64,' ) && str_contains( (string) base64_decode( substr( $fluffless_menu_icon, 26 ), true ), '<svg' ), 'menu : icône dashicons-feedback encore là' );
	ob_start();
	Screen::footer();
	$fluffless_foot = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_foot, '>Help</a>' ) && str_contains( $fluffless_foot, 'Report a problem</a>' ) && ! str_contains( $fluffless_foot, 'Report a fluff' ) && 2 === substr_count( $fluffless_foot, '<a ' ), 'pied : deux liens attendus' );
	$fluffless_assert( str_contains( $fluffless_foot, 'href="' . esc_url( Screen::HELP_URL ) . '"' ), 'pied : adresse d\'aide par défaut absente' );
	// X5-31 (D45) : l'aide est une adresse courriel, pas une page.
	$fluffless_assert( str_contains( $fluffless_foot, 'href="mailto:genevieve@fluffless.dev"' ), 'X5-31 : pied sans mailto:genevieve@fluffless.dev' );
	$fluffless_help = static fn(): string => 'mailto:aide@agence.example';
	add_filter( 'fluffless_help_url', $fluffless_help );
	$fluffless_assert( 'mailto:aide@agence.example' === Screen::help_url(), 'X5-31 : adresse d\'aide mailto: d\'une agence refusée' );
	remove_filter( 'fluffless_help_url', $fluffless_help );
	$fluffless_help = static fn(): string => 'javascript:alert(1)';
	add_filter( 'fluffless_help_url', $fluffless_help );
	$fluffless_assert( Screen::HELP_URL === Screen::help_url(), 'pied : adresse d\'aide javascript: acceptée' );
	remove_filter( 'fluffless_help_url', $fluffless_help );
	$fluffless_help = static fn(): string => 'https://example.org/aide';
	add_filter( 'fluffless_help_url', $fluffless_help );
	$fluffless_assert( 'https://example.org/aide' === Screen::help_url(), 'pied : filtre de l\'adresse d\'aide ignoré' );
	remove_filter( 'fluffless_help_url', $fluffless_help );

	// C26 : redirection vers l'accueil une seule fois après l'activation, jamais lors d'une activation groupée.
	delete_transient( Lifecycle::TRANSIENT_WELCOME );
	$fluffless_assert( null === Lifecycle::welcome_url(), 'accueil : redirection sans activation' );
	Lifecycle::activate( true );
	$fluffless_assert( false === get_transient( Lifecycle::TRANSIENT_WELCOME ), 'accueil : drapeau posé pour une activation réseau' );
	Lifecycle::activate( null );
	$fluffless_assert( false !== get_transient( Lifecycle::TRANSIENT_WELCOME ), 'accueil : activation par WP-CLI (null) refusée ou sans drapeau' );
	delete_transient( Lifecycle::TRANSIENT_WELCOME );
	Lifecycle::activate();
	$fluffless_assert( false !== get_transient( Lifecycle::TRANSIENT_WELCOME ), 'accueil : drapeau absent après activation' );
	$fluffless_assert( null === Lifecycle::welcome_url( array( 'activate-multi' => '1' ) ), 'accueil : redirection lors d\'une activation groupée' );
	$fluffless_assert( false !== get_transient( Lifecycle::TRANSIENT_WELCOME ), 'accueil : drapeau consommé par une activation groupée' );
	$fluffless_assert( Menu::url() === Lifecycle::welcome_url(), 'accueil : pas de redirection après activation' );
	$fluffless_assert( false === get_transient( Lifecycle::TRANSIENT_WELCOME ), 'accueil : drapeau encore là après la redirection' );
	$fluffless_assert( null === Lifecycle::welcome_url(), 'accueil : redirection répétée' );
	Lifecycle::activate();
	wp_set_current_user( 0 );
	$fluffless_assert( null === Lifecycle::welcome_url() && false !== get_transient( Lifecycle::TRANSIENT_WELCOME ), 'accueil : drapeau consommé par une autre personne que celle qui a activé' );
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );
	$fluffless_assert( Menu::url() === Lifecycle::welcome_url(), 'accueil : la personne qui a activé n\'est pas redirigée' );
	wp_set_current_user( 0 );
	Lifecycle::activate();
	$fluffless_assert( null === Lifecycle::welcome_url() && false === get_transient( Lifecycle::TRANSIENT_WELCOME ), 'accueil : redirection offerte sans droit, ou drapeau gardé' );
	wp_set_current_user( (int) ( $fluffless_admins[0] ?? 0 ) );

	// Onglet Modules : tableau sémantique, réglages repliés, barre d'enregistrement.
	ob_start();
	SettingsPage::render();
	$fluffless_settings = (string) ob_get_clean();
	foreach ( array( '<table class="fl-modules fl-hue-lighten"', '<caption>', '<span class="fl-tile"><svg class="fl-icon"', 'aria-hidden="true" focusable="false"', '<div class="fl-module__head"><span class="fl-tile">', '<th scope="col">Module</th>', '<th scope="col">Active</th>', '<th scope="row">', '<details class="fl-module__toggle"><summary', 'class="fl-module__panel"', 'data-fl-savebar', 'data-label="Effect for visitors"' ) as $fluffless_needle ) {
		$fluffless_assert( str_contains( $fluffless_settings, $fluffless_needle ), "Modules : {$fluffless_needle} absent" );
	}
	$fluffless_assert( ! str_contains( $fluffless_settings, 'Report a problem' ), 'Modules : Signaler encore en haut de l\'écran' );
	$fluffless_assert( wp_script_is( 'fluffless-savebar', 'enqueued' ), 'Modules : savebar.js non chargé' );
} finally {
	wp_set_current_user( 0 );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
echo esc_html( "OK : Menu admin, {$fluffless_checks} vérifications réussies.\n" );
