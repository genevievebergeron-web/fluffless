<?php
/**
 * Test de l'affichage « JavaScript ajouté pour les visiteurs » : le total déclaré par le filtre
 * fluffless_visitor_scripts doit couvrir le JavaScript que les composants servent réellement
 * (modules de la Suite, script Échap du thème, bannière de Fluffless Consent), sans chiffre
 * écrit en dur.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/visitor-js-test.php
 * L'option des modules est remise à son état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Blocks\ModeCouleur;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Expression;
use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Presentation\Scheme;

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

$fluffless_plugin  = dirname( __DIR__ );
$fluffless_initial = get_option( Options::NAME, null );

try {
	$fluffless_stored                          = is_array( $fluffless_initial ) ? $fluffless_initial : array();
	$fluffless_stored['modules']['expression'] = true;
	update_option( Options::NAME, $fluffless_stored );
	Options::flush();
	$fluffless_modules = Loader::modules();
	// Le module était éteint au démarrage : ses crochets ne sont pas posés, on pose celui qui nous intéresse.
	if ( false === has_filter( 'fluffless_visitor_scripts', array( Expression::class, 'declare_visitor_scripts' ) ) ) {
		add_filter( 'fluffless_visitor_scripts', array( Expression::class, 'declare_visitor_scripts' ) );
	}

	// 1. Réel : tout bloc qui déclare un module de vue (block.json) sert ce fichier aux visiteurs.
	$fluffless_real = 0;
	foreach ( (array) glob( $fluffless_plugin . '/blocks/*/block.json' ) as $fluffless_json_file ) {
		if ( ! is_string( $fluffless_json_file ) ) {
			continue;
		}
		$fluffless_json = json_decode( (string) file_get_contents( $fluffless_json_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$fluffless_view = is_array( $fluffless_json ) ? ( $fluffless_json['viewScriptModule'] ?? '' ) : '';
		if ( is_string( $fluffless_view ) && '' !== $fluffless_view ) {
			$fluffless_path  = dirname( $fluffless_json_file ) . '/' . preg_replace( '#^file:\./#', '', $fluffless_view );
			$fluffless_real += is_readable( $fluffless_path ) ? (int) filesize( $fluffless_path ) : 0;
		}
	}
	$fluffless_assert( $fluffless_real > 0, 'aucun module de vue trouvé dans les blocs : le test ne mesure rien' );

	// 2. Réel : le script de repli du récit est écrit en ligne par Expression.
	$fluffless_real += strlen( Expression::recit_js() );

	// 3. Déclaré : le module Expressivité actif doit annoncer au moins ce JavaScript.
	$fluffless_expression = SettingsPage::visitor_js_bytes( array( 'expression' => $fluffless_modules['expression'] ) );
	$fluffless_assert( $fluffless_expression >= $fluffless_real, "Expressivité déclare {$fluffless_expression} o de JavaScript pour {$fluffless_real} o servis" );

	// 4. Module actif sans JavaScript : toujours 0.
	$fluffless_assert( 0 === SettingsPage::visitor_js_bytes( array( 'cleanup' => $fluffless_modules['cleanup'] ) ), 'cleanup ne sert aucun JavaScript' );

	// 5. Module éteint : 0, même s'il déclare des fichiers.
	$fluffless_stored['modules']['expression'] = false;
	update_option( Options::NAME, $fluffless_stored );
	Options::flush();
	$fluffless_assert( 0 === SettingsPage::visitor_js_bytes( array( 'expression' => $fluffless_modules['expression'] ) ), 'module éteint compté' );
	$fluffless_stored['modules']['expression'] = true;
	update_option( Options::NAME, $fluffless_stored );
	Options::flush();

	// 6. Thème : le script Échap est déclaré quand le thème Fluffless est actif.
	if ( function_exists( 'fluffless_nav_escape_script' ) ) {
		$fluffless_theme_js = (int) filesize( get_template_directory() . '/assets/js/nav-escape.js' );
		$fluffless_total    = SettingsPage::visitor_js_total( $fluffless_modules );
		$fluffless_assert( $fluffless_total >= $fluffless_expression + $fluffless_theme_js, "le script du thème ({$fluffless_theme_js} o) n'est pas compté : total {$fluffless_total} o" );
	}

	// 7. Entrées invalides du filtre ignorées, entiers négatifs ramenés à 0.
	$fluffless_junk = static function ( $scripts ): array {
		$scripts               = is_array( $scripts ) ? $scripts : array();
		$scripts['expression'] = array_merge( $scripts['expression'] ?? array(), array( 5, -9, array(), 'fichier-inexistant.js' ) );
		$scripts[42]           = array( 'x.js' );
		$scripts['cleanup']    = 'pas une liste';
		return $scripts;
	};
	add_filter( 'fluffless_visitor_scripts', $fluffless_junk, 99 );
	$fluffless_with_junk = SettingsPage::visitor_js_bytes( array( 'expression' => $fluffless_modules['expression'] ) );
	remove_filter( 'fluffless_visitor_scripts', $fluffless_junk, 99 );
	$fluffless_assert( $fluffless_with_junk === $fluffless_expression + 5, 'entrées invalides du filtre mal gérées : ' . $fluffless_with_junk . ' au lieu de ' . ( $fluffless_expression + 5 ) );

	// 7 bis. X5-107 : le script en ligne du mode clair ou sombre est compté au module Palette quand
	// la fonction est active, et seulement alors.
	$fluffless_palette_was = get_option( Palette::OPTION, null );
	if ( false === has_filter( 'fluffless_visitor_scripts', array( ModeCouleur::class, 'declare_visitor_scripts' ) ) ) {
		add_filter( 'fluffless_visitor_scripts', array( ModeCouleur::class, 'declare_visitor_scripts' ) );
	}
	$fluffless_stored['modules']['palette'] = true;
	update_option( Options::NAME, $fluffless_stored );
	Options::flush();
	Scheme::reset();
	Scheme::save( true );
	Scheme::reset();
	$fluffless_assert( Scheme::active(), 'mode clair ou sombre : la palette par défaut doit avoir une jumelle' );
	$fluffless_scheme_js = strlen( ModeCouleur::script() );
	$fluffless_palette   = SettingsPage::visitor_js_bytes( array( 'palette' => $fluffless_modules['palette'] ) );
	$fluffless_assert( $fluffless_palette >= $fluffless_scheme_js, "mode clair ou sombre actif : Palette déclare {$fluffless_palette} o pour {$fluffless_scheme_js} o servis en ligne" );
	Scheme::save( false );
	Scheme::reset();
	$fluffless_assert( 0 === SettingsPage::visitor_js_bytes( array( 'palette' => $fluffless_modules['palette'] ) ), 'mode clair ou sombre éteint : aucun JavaScript compté pour Palette' );
	if ( null === $fluffless_palette_was ) {
		delete_option( Palette::OPTION );
	} else {
		update_option( Palette::OPTION, $fluffless_palette_was, false );
	}
	Scheme::reset();

	// 8. Accrochage réel de la bannière : le tableau de bord (admin, REST, WP-CLI) n'a pas fait tourner
	// le hook « wp » de Fluffless Consent. Le filtre seul doit déjà déclarer consent.js quand un service existe.
	if ( class_exists( \Fluffless\Consent\Consent::class ) ) {
		$fluffless_with_ga                     = is_array( $fluffless_initial ) ? $fluffless_initial : array();
		$fluffless_with_ga['consent']['ga_id'] = 'G-TEST12345';
		update_option( Options::NAME, $fluffless_with_ga );
		Options::flush();
		$fluffless_filtered = apply_filters( 'fluffless_visitor_scripts', array() );
		$fluffless_assert( is_array( $fluffless_filtered ) && isset( $fluffless_filtered['consent'] ), 'consent.js absent du filtre fluffless_visitor_scripts hors du hook wp (tableau de bord, REST, WP-CLI)' );
		$fluffless_consent_js = (int) filesize( WP_PLUGIN_DIR . '/fluffless-consent/assets/consent/consent.js' );
		$fluffless_assert( SettingsPage::component_js_bytes() >= $fluffless_consent_js, 'le total du tableau de bord ne compte pas consent.js (' . SettingsPage::component_js_bytes() . ' o pour ' . $fluffless_consent_js . ' o servis)' );
		// Sans service déclaré (ni contenu intégré noté par l'option), rien n'est compté.
		$fluffless_embeds = get_option( \Fluffless\Consent\Consent::EMBEDS_OPTION, false );
		delete_option( \Fluffless\Consent\Consent::EMBEDS_OPTION );
		update_option( Options::NAME, is_array( $fluffless_initial ) ? $fluffless_initial : array() );
		Options::flush();
		$fluffless_filtered = apply_filters( 'fluffless_visitor_scripts', array() );
		$fluffless_assert( ! isset( $fluffless_filtered['consent'] ) || array() !== \Fluffless\Consent\Consent::services(), 'consent.js compté sans service déclaré' );
		if ( false !== $fluffless_embeds ) {
			update_option( \Fluffless\Consent\Consent::EMBEDS_OPTION, $fluffless_embeds, false );
		}
	}
} finally {
	remove_filter( 'fluffless_visitor_scripts', array( Expression::class, 'declare_visitor_scripts' ) );
	remove_filter( 'fluffless_visitor_scripts', array( ModeCouleur::class, 'declare_visitor_scripts' ) );
	if ( null === $fluffless_initial ) {
		delete_option( Options::NAME );
	} else {
		update_option( Options::NAME, $fluffless_initial );
	}
	Options::flush();
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "JavaScript visiteur : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "JavaScript visiteur : {$fluffless_checks} vérifications passées." );
