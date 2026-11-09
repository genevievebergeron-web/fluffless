<?php
/**
 * Guide « Bien démarrer » : widget du tableau de bord et panneau de l'éditeur qui montrent où trouver
 * les blocs, les compositions, les modèles de page et la Suite, sans avoir à fouiller.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Palette;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Le widget se masque comme tout widget du tableau de bord : le lien « Masquer ce guide » l'ajoute à
 * l'option native metaboxhidden_dashboard, donc « Options de l'écran » le ramène.
 */
final class Guide {

	public const WIDGET        = 'fluffless_getting_started';
	public const HIDE_ACTION   = 'fluffless_guide_hide';
	public const SCRIPT_HANDLE = 'fluffless-guide-editor';
	public const STYLE_HANDLE  = 'fluffless-guide';
	public const OPEN_ARG      = 'fluffless_open';

	/** Branche les crochets (administration seulement). */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add_widget' ) );
		add_action( 'admin_post_' . self::HIDE_ACTION, array( self::class, 'hide' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'dashboard_style' ) );
	}

	/** Widget pour qui peut modifier des pages. */
	public static function add_widget(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		wp_add_dashboard_widget( self::WIDGET, __( 'Getting started with your site', 'fluffless-suite' ), array( self::class, 'render' ), null, null, 'normal', 'high' );
	}

	/**
	 * Actions du guide : chaque élément mène directement à l'endroit visé.
	 *
	 * @return array<int, array{icon: string, title: string, text: string, url: string}>
	 */
	private static function actions(): array {
		$new     = admin_url( 'post-new.php?post_type=page' );
		$palette = Options::is_enabled( Palette::id() );
		$custom  = ! $palette || Palette::is_custom();
		$colour  = array(
			'icon'  => 'colors-swatch',
			'title' => __( 'Choose your colours', 'fluffless-suite' ),
			'text'  => $custom ? __( 'Colours and fonts of the whole site.', 'fluffless-suite' ) : __( 'Start here: enter your colours once, every page style will use them.', 'fluffless-suite' ),
			'url'   => $palette ? Menu::url( Palette::PAGE ) : admin_url( 'site-editor.php?p=%2Fstyles' ),
		);
		$list    = array(
			array(
				'icon'  => 'pen-square',
				'title' => __( 'Create a page', 'fluffless-suite' ),
				'text'  => __( 'Pick a ready-made page or start blank.', 'fluffless-suite' ),
				'url'   => $new,
			),
			array(
				'icon'  => 'layout',
				'title' => __( 'Edit a page', 'fluffless-suite' ),
				'text'  => __( 'The list of your pages.', 'fluffless-suite' ),
				'url'   => admin_url( 'edit.php?post_type=page' ),
			),
			array(
				'icon'  => 'blocks',
				'title' => __( 'Add a block', 'fluffless-suite' ),
				'text'  => __( 'Opens the block library in a new page.', 'fluffless-suite' ),
				'url'   => add_query_arg( self::OPEN_ARG, 'blocks', $new ),
			),
			array(
				'icon'  => 'shapes',
				'title' => __( 'Add a ready-made section', 'fluffless-suite' ),
				'text'  => __( 'Opens the sections: openings, services, testimonials, contact and more.', 'fluffless-suite' ),
				'url'   => add_query_arg( self::OPEN_ARG, 'patterns', $new ),
			),
		);
		// Tant que les couleurs ne sont pas entrées, c'est la première étape, mise en avant.
		return $custom ? array_merge( $list, array( $colour ) ) : array_merge( array( $colour ), $list );
	}

	/** Contenu du widget. */
	public static function render(): void {
		echo '<div class="fl-guide">';
		printf( '<p class="fl-guide-brand">%s<span>Fluffless</span></p>', Icon::svg( 'feather' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG de la liste blanche.
		echo '<p class="fl-guide-intro">' . esc_html__( 'Your site runs on the Fluffless theme: light pages, ready-made sections and a palette you control. Click where you want to go.', 'fluffless-suite' ) . '</p>';

		echo '<ul class="fl-guide-actions">';
		foreach ( self::actions() as $action ) {
			printf(
				'<li><a href="%1$s">%2$s<span><strong>%3$s</strong> %4$s</span></a></li>',
				esc_url( $action['url'] ),
				Icon::svg( $action['icon'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG de la liste blanche.
				esc_html( $action['title'] ),
				esc_html( $action['text'] )
			);
		}
		echo '</ul>';

		if ( current_user_can( SettingsPage::CAPABILITY ) ) {
			printf(
				'<p class="fl-guide-suite">%s <a href="%s">%s</a></p>',
				esc_html__( 'The Fluffless Suite adds the modules (forms, events, newsletter, images, security).', 'fluffless-suite' ),
				esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ),
				esc_html__( 'Open the Suite', 'fluffless-suite' )
			);
		}

		printf(
			'<p class="fl-guide-hide"><a href="%s">%s</a> <span>%s</span></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::HIDE_ACTION ), self::HIDE_ACTION ) ),
			esc_html__( 'Hide this guide', 'fluffless-suite' ),
			esc_html__( '(Screen Options, at the top of the dashboard, brings it back.)', 'fluffless-suite' )
		);
		echo '</div>';
	}

	/** Masque le widget pour la personne connectée, de façon réversible. */
	public static function hide(): void {
		check_admin_referer( self::HIDE_ACTION );
		$user   = get_current_user_id();
		$hidden = get_user_option( 'metaboxhidden_dashboard', $user );
		$hidden = is_array( $hidden ) ? $hidden : array();
		if ( ! in_array( self::WIDGET, $hidden, true ) ) {
			$hidden[] = self::WIDGET;
			update_user_option( $user, 'metaboxhidden_dashboard', $hidden, true );
		}
		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}

	/**
	 * Feuille du widget, sur le tableau de bord seulement.
	 *
	 * @param string $hook Écran courant.
	 */
	public static function dashboard_style( string $hook ): void {
		if ( 'index.php' === $hook ) {
			wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'assets/guide.css', FILE ), array(), VERSION );
		}
	}

	/** Panneau « Where to find what » de l'éditeur. */
	public static function editor_assets(): void {
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/guide-editor.js', FILE ),
			array( 'wp-plugins', 'wp-element', 'wp-i18n', 'wp-components', 'wp-data', 'wp-editor' ),
			VERSION,
			true
		);
		wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'assets/guide.css', FILE ), array(), VERSION );
		wp_set_script_translations( self::SCRIPT_HANDLE, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
	}
}
