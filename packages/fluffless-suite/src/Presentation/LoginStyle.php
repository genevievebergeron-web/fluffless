<?php
/**
 * Page de connexion aux couleurs du site, sous le thème Fluffless.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

use const Fluffless\Suite\FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Palette, police, rayon et logo du site sur wp-login.php (et sur l'adresse de connexion cachée
 * de Sécurité, qui inclut le même fichier), sans réglage ni script. Les variables viennent des
 * styles globaux, donc de la variation choisie et des écrans Palette et Polices. Règles dans
 * assets/login.css, en ligne après la feuille du cœur. Seulement sous le thème Fluffless : la
 * feuille s'appuie sur ses rôles de palette. Filtre fluffless_login_styles (faux pour garder
 * la page du cœur).
 */
final class LoginStyle {

	/** Branche les crochets. */
	public static function register(): void {
		add_action( 'login_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'login_headerurl', array( self::class, 'header_url' ) );
		add_filter( 'login_headertext', array( self::class, 'header_text' ) );
		add_action( 'login_head', array( self::class, 'font_faces' ), 50 );
	}

	/** La page est habillée : thème Fluffless actif et filtre laissé à vrai. */
	public static function applies(): bool {
		/**
		 * Permet de garder la page de connexion du cœur.
		 *
		 * @param bool $styled Vrai pour habiller la page aux couleurs du site.
		 */
		return Patterns::applies() && (bool) apply_filters( 'fluffless_login_styles', true );
	}

	/** Ajoute la feuille en ligne après login.css du cœur. */
	public static function enqueue(): void {
		if ( self::applies() ) {
			wp_add_inline_style( 'login', self::css() );
		}
	}

	/** Toute la feuille de la page de connexion : variables du site, règles, logo. */
	public static function css(): string {
		$css = file_get_contents( dirname( FILE ) . '/assets/login.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier de l'extension, lecture locale.
		return self::variables() . ( is_string( $css ) ? $css : '' ) . self::logo_css();
	}

	/**
	 * Variables des styles globaux utiles à la page de connexion : couleurs, familles et tailles
	 * de police, valeurs sur mesure (rayons, interlignes), puis la police du corps du site.
	 * Le reste de la feuille globale (espacements, ombres, rapports d'image) n'est pas repris.
	 */
	public static function variables(): string {
		$sheet = wp_get_global_stylesheet( array( 'variables' ) );
		$kept  = array();
		if ( 1 === preg_match( '/:root\s*\{([^}]*)\}/', $sheet, $m ) ) {
			foreach ( explode( ';', $m[1] ) as $declaration ) {
				$declaration = trim( $declaration );
				if ( 1 === preg_match( '/^--wp--(preset--(color|font-family|font-size)|custom)--/', $declaration ) ) {
					$kept[] = $declaration;
				}
			}
		}
		$font = wp_get_global_styles( array( 'typography', 'fontFamily' ) );
		$font = is_string( $font ) ? (string) preg_replace( '/^var:([a-z]+)\|([a-z-]+)\|([a-z0-9-]+)$/', 'var(--wp--$1--$2--$3)', $font ) : '';
		$root = array() === $kept ? '' : ':root{' . implode( ';', $kept ) . '}';
		$body = '' === $font ? '' : 'body.login{font-family:' . $font . '}';
		return $root . $body;
	}

	/**
	 * Logo du site (bloc Logo du site), sinon icône du site, posé à la place du nom en texte.
	 * Rien quand ni l'un ni l'autre n'est réglé : le nom du site reste en texte.
	 */
	public static function logo_css(): string {
		$url  = '';
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $logo > 0 ) {
			$src = wp_get_attachment_image_url( $logo, 'medium' );
			$url = is_string( $src ) ? $src : '';
		}
		if ( '' === $url ) {
			$url = get_site_icon_url( 96 );
		}
		if ( '' === $url ) {
			return '';
		}
		return '.login h1 a{display:block;width:100%;max-width:320px;height:96px;text-indent:-9999px;overflow:hidden;background:url(' . esc_url( $url ) . ') center/contain no-repeat}';
	}

	/**
	 * Le titre de la page de connexion mène au site plutôt qu'à WordPress.org.
	 *
	 * @param string $url Adresse du cœur.
	 */
	public static function header_url( $url ): string {
		return self::applies() ? home_url( '/' ) : (string) $url;
	}

	/**
	 * Nom du site comme texte du titre de la page de connexion.
	 *
	 * @param string $text Texte du cœur.
	 */
	public static function header_text( $text ): string {
		return self::applies() ? get_bloginfo( 'name', 'display' ) : (string) $text;
	}

	/** Polices du site (déclarations @font-face des styles globaux ; aucune avec les polices du système). */
	public static function font_faces(): void {
		if ( self::applies() ) {
			wp_print_font_faces();
		}
	}
}
