<?php
/**
 * Plugin Name:       Fluffless Consent
 * Plugin URI:        https://fluffless.dev
 * Description:       A light cookie consent banner that appears only when a non-essential service is declared. Nothing from the services it lists loads before a yes, and refusing is as easy as accepting.
 * Version:           0.2.1
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Geneviève Bergeron
 * Author URI:        https://genevievebergeron.ca
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fluffless-consent
 * Domain Path:       /languages
 *
 * @package Fluffless_Consent
 */

declare( strict_types=1 );

namespace Fluffless\Consent;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.1';
const FILE    = __FILE__;

// Chargement PSR-4 minimal : Fluffless\Consent\Settings => src/Settings.php.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( str_starts_with( $class_name, $prefix ) ) {
			$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	}
);

require __DIR__ . '/src/functions.php';

/**
 * Charge les traductions livrées dans languages/.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'fluffless-consent', false, dirname( plugin_basename( FILE ) ) . '/languages' );
}

add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 0 );
add_action( 'init', array( Consent::class, 'register_shortcode' ) );
// Les services d'un thème (functions.php) ne sont connus qu'après after_setup_theme, d'où « wp ».
add_action( 'wp', array( Consent::class, 'start_if_needed' ) );
add_action( 'wp', array( Consent::class, 'start_embeds' ) );
// Le tableau de bord de Fluffless Suite compte consent.js hors d'une requête de visiteur (admin, REST, WP-CLI), où « wp » ne tourne pas.
add_filter( 'fluffless_visitor_scripts', array( Consent::class, 'declare_visitor_scripts' ) );
add_filter( 'fluffless_outgoing_hosts', array( Consent::class, 'outgoing_hosts' ) );
add_action( 'save_post', array( Consent::class, 'note_embeds' ), 10, 2 );
add_action( 'admin_init', array( Consent::class, 'add_privacy_text' ) );
// Section « consent » de l'option partagée fluffless_settings (aussi lue par Fluffless Suite).
add_filter( 'allowed_options', array( Settings::class, 'allow' ) );
add_filter( 'sanitize_option_' . Settings::NAME, array( Settings::class, 'sanitize_option' ), 20 );

if ( is_admin() ) {
	add_action( 'admin_menu', array( Settings::class, 'add_page' ) );
}
