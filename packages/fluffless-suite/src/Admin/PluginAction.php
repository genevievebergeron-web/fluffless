<?php
/**
 * Bouton qui active une extension requise par un module, ou mène à son installation.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Installée : lien d'activation de WordPress (nonce du cœur). Absente : recherche au répertoire
 * WordPress.org. Null quand la personne n'a ni l'un ni l'autre des droits.
 */
final class PluginAction {

	/**
	 * Bouton pour une extension.
	 *
	 * @param string $file Nom de base, ex. woocommerce/woocommerce.php.
	 * @param string $name Nom affiché de l'extension.
	 * @return array{text: string, url: string}|null
	 */
	public static function for_plugin( string $file, string $name ): ?array {
		if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return null;
			}
			return array(
				/* translators: %s: plugin name */
				'text' => sprintf( __( 'Activate %s', 'fluffless-suite' ), $name ),
				'url'  => wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ),
			);
		}
		if ( ! current_user_can( 'install_plugins' ) ) {
			return null;
		}
		return array(
			/* translators: %s: plugin name */
			'text' => sprintf( __( 'Install %s', 'fluffless-suite' ), $name ),
			'url'  => admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( dirname( $file ) ) ),
		);
	}
}
