<?php
/**
 * Couche de présentation commune des écrans Fluffless : feuille de style, en-tête, interrupteur, pastilles.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Un seul fichier CSS, chargé seulement sur les écrans dont l'identifiant contient « fluffless ».
 * Les autres écrans de l'administration et le site public n'en reçoivent rien.
 */
final class Screen {

	public const HANDLE = 'fluffless-admin';

	/**
	 * Crochets déjà posés ?
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/** Pose les crochets ; appelé depuis SettingsPage::add_page(), donc en administration seulement. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
	}

	/**
	 * L'écran appartient-il à Fluffless ?
	 *
	 * @param string $hook Suffixe de l'écran (settings_page_fluffless, fluffless_page_fluffless-shape...).
	 */
	public static function is_ours( string $hook ): bool {
		return str_contains( $hook, 'fluffless' );
	}

	/**
	 * Feuille de style commune.
	 *
	 * @param mixed $hook Suffixe de l'écran courant.
	 */
	public static function enqueue( $hook ): void {
		if ( is_string( $hook ) && self::is_ours( $hook ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/admin/admin.css', FILE ), array(), VERSION );
		}
	}

	/**
	 * Classe de portée : tout le CSS est limité à .fluffless-screen.
	 *
	 * @param mixed $classes Classes du body de l'administration (chaîne).
	 * @return string
	 */
	public static function body_class( $classes ): string {
		$classes = is_string( $classes ) ? $classes : '';
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen && self::is_ours( $screen->id ) ? $classes . ' fluffless-screen' : $classes;
	}

	/**
	 * En-tête d'écran : titre et une phrase d'aide (qui peut porter l'unique touche d'humour, en <em>).
	 *
	 * @param string $title Titre de l'écran.
	 * @param string $help  Phrase d'aide ; seul <em> est permis.
	 */
	public static function header( string $title, string $help ): void {
		echo '<div class="fl-head"><h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $help ) {
			echo '<p>' . wp_kses( $help, array( 'em' => array() ) ) . '</p>';
		}
		echo '</div>';
	}

	/** Aide par défaut : l'adresse courriel de Fluffless (D45), pas une page. */
	public const HELP_URL = 'mailto:genevieve@fluffless.dev';

	/**
	 * Adresse de l'aide, qu'une agence peut remplacer par la sienne.
	 */
	public static function help_url(): string {
		/**
		 * Filtre l'adresse du lien « Help » au pied des écrans Fluffless.
		 *
		 * @param string $url Adresse http(s) ou mailto:.
		 */
		$url = apply_filters( 'fluffless_help_url', self::HELP_URL );
		if ( ! is_string( $url ) ) { // @phpstan-ignore function.alreadyNarrowedType (un filtre peut renvoyer autre chose qu'une chaîne)
			return self::HELP_URL;
		}
		$url = esc_url_raw( $url, array( 'http', 'https', 'mailto' ) );
		return '' === $url ? self::HELP_URL : $url;
	}

	/** Pied d'écran commun : l'aide et le signalement, discrets, après le contenu. */
	public static function footer(): void {
		// Icônes : SVG générés, balises et attributs en liste blanche (tools/build-icons.mjs).
		printf(
			'<footer class="fl-foot"><a href="%1$s">%2$s%3$s</a> <a href="%4$s">%5$s%6$s</a></footer>',
			esc_url( self::help_url() ),
			Icon::svg( 'circle-info' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- voir ci-dessus.
			esc_html__( 'Help', 'fluffless-suite' ),
			esc_url( Report::url() ),
			Icon::svg( 'message' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- voir ci-dessus.
			esc_html__( 'Report a problem', 'fluffless-suite' )
		);
	}

	/**
	 * Pastille d'état. Le texte dit l'état ; la couleur le double seulement.
	 *
	 * @param string $status good, warn, bad ou idle.
	 * @param string $label  Texte de l'état.
	 */
	public static function pill( string $status, string $label ): string {
		$status = in_array( $status, array( 'good', 'warn', 'bad' ), true ) ? $status : 'idle';
		return '<span class="fl-pill fl-pill--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Message dans l'écran. Pour une erreur : dire ce qui s'est passé et quoi faire, sans blague.
	 *
	 * @param string $status  good, warn, bad ou idle.
	 * @param string $message Texte brut.
	 */
	public static function note( string $status, string $message ): string {
		$status = in_array( $status, array( 'good', 'warn', 'bad' ), true ) ? $status : 'idle';
		$role   = 'bad' === $status ? ' role="alert"' : ' role="status"';
		// Icône : SVG généré, liste blanche (tools/build-icons.mjs).
		return '<div class="fl-note fl-note--' . esc_attr( $status ) . '"' . $role . '>' . Icon::svg( Icon::for_status( $status ) ) . '<p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Interrupteur : case à cocher native avec role="switch" (motif APG). L'état annoncé est
	 * celui de la case (:checked), donc il ne peut pas mentir, et il fonctionne sans JavaScript.
	 * Le champ caché envoie 0 quand l'interrupteur est éteint.
	 *
	 * @param string $name        Nom du champ.
	 * @param string $id          Identifiant HTML.
	 * @param bool   $checked     Allumé ou éteint.
	 * @param string $labelledby  Identifiant de l'élément qui nomme l'interrupteur ('' : un label for le nomme).
	 * @param string $describedby Identifiant de la description ('' : aucune).
	 */
	public static function switch_control( string $name, string $id, bool $checked, string $labelledby = '', string $describedby = '' ): string {
		$aria  = '' === $labelledby ? '' : ' aria-labelledby="' . esc_attr( $labelledby ) . '"';
		$aria .= '' === $describedby ? '' : ' aria-describedby="' . esc_attr( $describedby ) . '"';
		return sprintf(
			'<span class="fl-switch"><input type="hidden" name="%1$s" value="0"><input type="checkbox" role="switch" id="%2$s" name="%1$s" value="1"%3$s%4$s><span class="fl-switch__state" aria-hidden="true"><span class="fl-on">%5$s</span><span class="fl-off">%6$s</span></span></span>',
			esc_attr( $name ),
			esc_attr( $id ),
			$checked ? ' checked' : '',
			$aria,
			esc_html__( 'On', 'fluffless-suite' ),
			esc_html__( 'Off', 'fluffless-suite' )
		);
	}
}
