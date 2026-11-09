<?php
/**
 * Plugin Name:       Fluffless SEO
 * Plugin URI:        https://fluffless.dev
 * Description:       Complete, server-side SEO for any theme: titles, social tags, structured data, breadcrumbs, redirects and sitemaps. No JavaScript for visitors.
 * Version:           0.2.1
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Requires Plugins:  fluffless-suite
 * Author:            Geneviève Bergeron
 * Author URI:        https://genevievebergeron.ca
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://api.fluffless.dev/fluffless-seo
 * Text Domain:       fluffless-seo
 * Domain Path:       /languages
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.1';
const FILE    = __FILE__;

/*
 * Lue par Fluffless Suite (Modules\Seo::CONFLICTS) : son module SEO léger se retire
 * dès que cette extension est chargée, donc aucune balise en double.
 */
if ( ! defined( 'FLUFFLESS_SEO_VERSION' ) ) {
	define( 'FLUFFLESS_SEO_VERSION', VERSION );
}

// Chargement PSR-4 minimal, comme Fluffless Suite : Fluffless\Seo\Admin\Screen => src/Admin/Screen.php.
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

/**
 * Inscription auprès du client de mises à jour de Fluffless Suite. Sans ce client, le filtre
 * n'est jamais appliqué et l'inscription reste sans effet.
 *
 * @param mixed $packages Paquets déjà inscrits : slug => description.
 * @return mixed
 */
function register_update_package( $packages ) {
	if ( ! is_array( $packages ) ) {
		return $packages;
	}
	$packages['fluffless-seo'] = array(
		'type'    => 'plugin',
		'file'    => plugin_basename( FILE ),
		'version' => VERSION,
	);
	return $packages;
}
add_filter( 'fluffless_update_packages', __NAMESPACE__ . '\\register_update_package' );

// Suite est chargé après cette extension (ordre alphabétique) : démarrage sur plugins_loaded.
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ), 5 );
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
