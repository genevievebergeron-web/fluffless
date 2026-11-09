<?php
/**
 * Guide du tableau de bord : à la connexion, ce qu'il y a à faire, selon qui se connecte.
 *
 * Dans la Suite plutôt que dans le thème : le répertoire des thèmes garde l'interface d'un thème
 * sous Apparence et ses messages dans des avis à fermer (exigences de revue, section 12).
 *
 * @package Fluffless_Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Home;
use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Module Guide du tableau de bord.
 *
 * Qui gère les options voit les premières étapes tant qu'il en reste, puis les prochaines actions
 * de l'accueil Fluffless. Toute personne qui écrit voit les gestes courants : modifier ou ajouter
 * une page, écrire un article, voir le site. Le widget se masque par les options de l'écran, comme
 * ceux du cœur, et le module s'éteint pour tout le site.
 *
 * @phpstan-type Link array{text: string, url: string}
 */
final class Dashboard implements Module {

	/** Identifiant du widget, aussi l'id de sa boîte dans le tableau de bord. */
	public const WIDGET = 'fluffless_guide';

	/** {@inheritDoc} */
	public static function id(): string {
		return 'dashboard';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Dashboard guide', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Shows what to do next at the top of the dashboard, for whoever logs in: the first steps and pending actions for the person who manages the site, the everyday tasks for everyone who writes.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add_widget' ) );
	}

	/**
	 * Ajoute le widget pour qui peut écrire : en tête de la première colonne tant qu'il guide
	 * vraiment, parmi les boîtes du cœur ensuite.
	 */
	public static function add_widget(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_add_dashboard_widget( self::WIDGET, __( 'Fluffless: where to start', 'fluffless-suite' ), array( self::class, 'render' ), null, null, 'normal', self::is_starting() ? 'high' : 'core' );
	}

	/**
	 * Début de parcours : il reste une première étape à qui gère le site, ou la personne
	 * n'a encore rien publié.
	 */
	public static function is_starting(): bool {
		if ( current_user_can( SettingsPage::CAPABILITY ) ) {
			return Home::steps_left();
		}
		return 0 === (int) count_user_posts( get_current_user_id(), array( 'post', 'page' ) );
	}

	/**
	 * Gestes courants, selon les droits de la personne connectée.
	 *
	 * @return list<Link>
	 */
	public static function tasks(): array {
		$tasks = array();
		if ( current_user_can( 'edit_pages' ) ) {
			$tasks[] = array(
				'text' => __( 'Edit a page', 'fluffless-suite' ),
				'url'  => admin_url( 'edit.php?post_type=page' ),
			);
			$tasks[] = array(
				'text' => __( 'Add a page', 'fluffless-suite' ),
				'url'  => admin_url( 'post-new.php?post_type=page' ),
			);
		}
		$tasks[] = array(
			'text' => __( 'Write a post', 'fluffless-suite' ),
			'url'  => admin_url( 'post-new.php' ),
		);
		$tasks[] = array(
			'text' => __( 'View the site', 'fluffless-suite' ),
			'url'  => home_url( '/' ),
		);
		return $tasks;
	}

	/** Contenu du widget : listes de liens, sans script ni feuille de style propre. */
	public static function render(): void {
		if ( current_user_can( SettingsPage::CAPABILITY ) ) {
			if ( Home::steps_left() ) {
				echo '<h3>' . esc_html__( 'Getting started', 'fluffless-suite' ) . '</h3><ol>';
				foreach ( Home::first_steps() as $step ) {
					if ( $step['done'] ) {
						printf( '<li><span class="dashicons dashicons-yes" aria-hidden="true"></span>%1$s <span class="screen-reader-text">%2$s</span></li>', esc_html( $step['text'] ), esc_html__( '(done)', 'fluffless-suite' ) );
					} else {
						printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $step['url'] ), esc_html( $step['text'] ) );
					}
				}
				echo '</ol>';
			}
			$actions = Home::actions();
			if ( array() !== $actions ) {
				echo '<h3>' . esc_html__( 'Next steps', 'fluffless-suite' ) . '</h3>';
				self::links( $actions );
			}
		}
		echo '<h3>' . esc_html__( 'Your content', 'fluffless-suite' ) . '</h3>';
		self::links( self::tasks() );
		if ( current_user_can( SettingsPage::CAPABILITY ) ) {
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( Menu::url() ), esc_html__( 'Open Fluffless', 'fluffless-suite' ) );
		}
	}

	/**
	 * Liste de liens.
	 *
	 * @param array<int, Link> $links Texte et adresse.
	 */
	private static function links( array $links ): void {
		echo '<ul>';
		foreach ( $links as $link ) {
			printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $link['url'] ), esc_html( $link['text'] ) );
		}
		echo '</ul>';
	}
}
