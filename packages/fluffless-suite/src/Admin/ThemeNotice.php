<?php
/**
 * Avis d'administration unique et persistant quand la Suite tourne sans le thème Fluffless (C28).
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

use Fluffless\Suite\Presentation\Patterns;

defined( 'ABSPATH' ) || exit;

/**
 * Sans le thème, les compositions, la palette et les gabarits de pages n'existent pas : un seul avis,
 * sur chaque écran d'administration, avec le bouton qui active (ou installe) le thème. Jamais refermable :
 * il disparaît quand le thème est actif. Les écrans qui en dépendent gardent leur propre note (Palette).
 */
final class ThemeNotice {

	/** Le thème est installé et permis sur ce site (en réseau, l'administration doit l'avoir autorisé). */
	public static function installed(): bool {
		$theme = wp_get_theme( Patterns::TEMPLATE );
		return $theme->exists() && $theme->is_allowed();
	}

	/** Écran où téléverser le ZIP du thème : le thème n'est pas au répertoire WordPress.org, donc aucune recherche. */
	public static function install_url(): string {
		return admin_url( 'theme-install.php' );
	}

	/**
	 * Adresse qui active le thème s'il est installé, sinon l'écran d'installation (téléversement du ZIP) ;
	 * vide quand ni l'un ni l'autre n'est possible (sous-site d'un réseau sans le thème).
	 */
	public static function theme_url(): string {
		if ( self::installed() ) {
			return wp_nonce_url( admin_url( 'themes.php?action=activate&stylesheet=' . Patterns::TEMPLATE ), 'switch-theme_' . Patterns::TEMPLATE );
		}
		return current_user_can( 'install_themes' ) ? self::install_url() : '';
	}

	/**
	 * Bouton de l'avis, ou null quand le thème est actif, que la personne ne peut pas changer de thème,
	 * ou qu'aucun bouton ne mènerait quelque part.
	 *
	 * @return array{text: string, url: string}|null
	 */
	public static function action(): ?array {
		if ( Patterns::applies() || ! current_user_can( 'switch_themes' ) ) {
			return null;
		}
		$url = self::theme_url();
		if ( '' === $url ) {
			return null;
		}
		return array(
			'text' => self::installed() ? __( 'Activate the Fluffless theme', 'fluffless-suite' ) : __( 'Upload the Fluffless theme (ZIP)', 'fluffless-suite' ),
			'url'  => $url,
		);
	}

	/** Avis (admin_notices). */
	public static function render(): void {
		$action = self::action();
		if ( null === $action ) {
			return;
		}
		printf(
			'<div class="notice notice-warning fl-theme-notice"><p>%1$s <a class="button button-secondary" href="%2$s">%3$s</a></p></div>',
			esc_html__( 'The Fluffless theme is not active on this site. Its sections, page templates and palette wait for it.', 'fluffless-suite' ),
			esc_url( $action['url'] ),
			esc_html( $action['text'] )
		);
	}
}
