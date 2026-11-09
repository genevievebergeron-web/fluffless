<?php
/**
 * Plugin Name:       Fluffless Suite
 * Plugin URI:        https://fluffless.dev
 * Description:       Advanced patterns, block styles and digital sobriety tools for the Fluffless theme.
 * Version:           0.2.1
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Geneviève Bergeron
 * Author URI:        https://genevievebergeron.ca
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://api.fluffless.dev/fluffless-suite
 * Text Domain:       fluffless-suite
 * Domain Path:       /languages
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.1';
const FILE    = __FILE__;

/*
 * Chargement PSR-4 minimal : Fluffless\Suite\Core\Options => src/Core/Options.php.
 * Trois lignes maison plutôt qu'un vendor/ Composer livré avec l'extension.
 */
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
 * Enregistre les blocs de l'extension. Ajouter un bloc = une ligne dans la liste.
 */
function register_blocks(): void {
	// Script d'éditeur commun aux blocs du pied de page (aperçu serveur), déclaré avant les blocs qui le nomment.
	$editor_script = plugins_url( 'assets/footer-blocks-editor.js', FILE );
	if ( '' !== $editor_script ) {
		wp_register_script(
			'fluffless-footer-blocks-editor',
			$editor_script,
			array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
			VERSION,
			true
		);
		wp_set_script_translations( 'fluffless-footer-blocks-editor', 'fluffless-suite', __DIR__ . '/languages' );
	}
	foreach ( array( 'icone', 'empreinte', 'badge-hebergement', 'reseaux', 'certifications', 'lien-infolettre', 'legal' ) as $block ) {
		register_block_type( __DIR__ . '/blocks/' . $block );
	}
}

/**
 * Charge les traductions PHP livrées dans languages/ (l'extension n'est pas sur WordPress.org).
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'fluffless-suite', false, dirname( plugin_basename( FILE ) ) . '/languages' );
}

add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 0 );
add_action( 'init', __NAMESPACE__ . '\\register_blocks' );
// Réglages déclarés pour l'API REST ; côté administration, SettingsPage::register_fields() s'en charge.
add_action( 'rest_api_init', array( Core\Options::class, 'register' ) );
add_action( 'enqueue_block_editor_assets', array( Blocks\Icone::class, 'add_editor_data' ) );

// Patterns et styles avancés, seulement sous le thème Fluffless (Presentation\Patterns::applies()).
add_action( 'init', array( Presentation\Patterns::class, 'register' ), Presentation\Patterns::PRIORITY );
add_action( 'init', array( Presentation\Patterns::class, 'register_categories' ), 11 );
add_filter( 'render_block_core/image', array( Presentation\Patterns::class, 'photo_frame' ), 20, 2 );
add_action( 'init', array( Presentation\BlockStyles::class, 'register' ) );
Presentation\PageStyle::register();
// Page de connexion aux couleurs du site, sous le thème Fluffless (repris du thème le 7 nov. 2026).
Presentation\LoginStyle::register();
// Ancre des blocs fluffless/* posée en id au rendu, quel que soit le thème.
add_filter( 'render_block', array( Presentation\Anchor::class, 'render' ), 10, 2 );
add_action( 'enqueue_block_assets', array( Presentation\BlockStyles::class, 'editor_assets' ) );
add_action( 'enqueue_block_editor_assets', array( Presentation\BlockStyles::class, 'editor_script' ) );

// Liste des modules et leurs dépendances : Core\Loader::MODULES, seul endroit à modifier.
add_action( 'plugins_loaded', array( Core\Loader::class, 'boot' ) );

// Métadonnée d'empreinte lue par le bloc même quand le panneau de l'éditeur est désactivé.
add_action( 'init', array( Modules\Footprint::class, 'register_meta' ) );
add_filter( 'rest_request_before_callbacks', array( Modules\Footprint::class, 'read_only_meta' ), 10, 3 );

require __DIR__ . '/src/functions.php';

if ( is_admin() ) {
	add_action( 'admin_menu', array( Admin\SettingsPage::class, 'add_page' ) );
	add_action( 'admin_init', array( Admin\SettingsPage::class, 'register_fields' ) );
	add_action( 'admin_init', array( Core\Lifecycle::class, 'welcome_redirect' ) );
	add_action( 'admin_notices', array( Admin\ThemeNotice::class, 'render' ) );
	Admin\Transfer::register();
	Admin\Guide::register();
	add_action( 'after_plugin_row_' . plugin_basename( __FILE__ ), array( Modules\Form::class, 'render_deletion_notice' ), 10, 0 );
	add_action( 'admin_menu', array( Admin\Report::class, 'add_page' ) );
	add_action( 'admin_menu', array( Admin\Hosts::class, 'add_page' ) );
	add_action( 'enqueue_block_editor_assets', array( Admin\Report::class, 'editor_assets' ) );
}

// wp fluffless diagnostic, wp fluffless update check.
add_action( 'cli_init', array( Core\Commands::class, 'register' ) );

register_activation_hook( __FILE__, array( Core\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Core\Lifecycle::class, 'deactivate' ) );
