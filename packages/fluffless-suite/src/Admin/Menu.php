<?php
/**
 * Menu d'administration « Fluffless » : Accueil, puis quatre groupes à onglets (Apparence, Contenu, Médias, Réglages).
 * Chaque entrée du menu porte le titre de l'écran qu'elle ouvre (le premier onglet du groupe : Trousses de site,
 * Modules...) ; le nom du groupe ne sert qu'à la barre d'onglets. Décision de Gen du 20 sept. 2026 (relais 11).
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registre d'onglets. Chaque module déclare son onglet (groupe, slug, titre, rendu) au lieu d'un sous-menu.
 *
 * Chaque onglet reste une vraie page d'administration avec son slug historique (admin.php?page=fluffless-palette...) :
 * les crochets d'écran, les formulaires admin-post et leurs redirections n'ont rien à savoir des onglets.
 * Seul le premier onglet d'un groupe apparaît dans le menu, sous son propre titre ; les autres sont retirés
 * du menu juste avant son affichage, une fois la page courante résolue par WordPress.
 *
 * @phpstan-type Tab array{title: string|callable, render: callable, order: int, capability: string}
 */
final class Menu {

	/** Slug de l'accueil, qui porte aussi le menu principal. */
	public const SLUG = 'fluffless';

	/** Groupes, dans l'ordre du menu. */
	public const GROUPS = array( 'appearance', 'content', 'media', 'settings' );

	/**
	 * Anciennes adresses : écran de départ => page de destination.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const LEGACY = array(
		'options-general.php' => array( 'fluffless' => SettingsPage::SLUG ),
	);

	/**
	 * Onglets déclarés, par groupe puis par slug.
	 *
	 * @var array<string, array<string, Tab>>
	 */
	private static array $tabs = array();

	/**
	 * Crochets déjà posés ?
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Déclare un onglet. Appelé sur admin_menu (priorité 10) ; le menu est construit à la priorité 99.
	 *
	 * @param string          $group      appearance, content, media ou settings ; un groupe inconnu tombe dans settings.
	 * @param string          $slug       Identifiant de la page (paramètre page=).
	 * @param string|callable $title      Titre de l'onglet et de l'écran, ou une fonction qui le renvoie (traduction différée à admin_menu).
	 * @param callable        $render     Rendu de l'écran.
	 * @param int             $order      Position dans le groupe, croissante.
	 * @param string          $capability Droit exigé par add_submenu_page() pour cet onglet (manage_options par défaut).
	 */
	public static function add_tab( string $group, string $slug, $title, callable $render, int $order = 50, string $capability = SettingsPage::CAPABILITY ): void {
		$group                         = in_array( $group, self::GROUPS, true ) ? $group : 'settings';
		self::$tabs[ $group ][ $slug ] = array(
			'title'      => $title,
			'render'     => $render,
			'order'      => $order,
			'capability' => $capability,
		);
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'admin_menu', array( self::class, 'build' ), 99 );
			add_action( 'admin_menu', array( self::class, 'redirect_legacy' ), 100 );
			add_action( 'admin_head', array( self::class, 'hide_tabs' ) );
			add_filter( 'submenu_file', array( self::class, 'submenu_file' ) );
		}
	}

	/**
	 * Compatibilité : ancien ajout d'écran, rangé en fin du groupe Réglages.
	 *
	 * @param string   $slug   Identifiant de la page.
	 * @param string   $title  Titre.
	 * @param callable $render Rendu.
	 */
	public static function add( string $slug, string $title, callable $render ): void {
		self::add_tab( 'settings', $slug, $title, $render, 90 );
	}

	/**
	 * Nom d'un groupe, repère de sa barre d'onglets (aria-label) ; jamais une entrée de menu.
	 *
	 * @param string $group Identifiant du groupe.
	 */
	public static function group_label( string $group ): string {
		$labels = array(
			'appearance' => __( 'Appearance', 'fluffless-suite' ),
			'content'    => __( 'Content', 'fluffless-suite' ),
			'media'      => __( 'Media', 'fluffless-suite' ),
			'settings'   => __( 'Settings', 'fluffless-suite' ),
		);
		return $labels[ $group ] ?? $group;
	}

	/**
	 * Onglets d'un groupe, triés (ordre, puis ordre de déclaration).
	 *
	 * @param string $group Identifiant du groupe.
	 * @return array<string, string> Slug => titre.
	 */
	public static function tabs( string $group ): array {
		$tabs = self::$tabs[ $group ] ?? array();
		// uasort est stable depuis PHP 8 : à ordre égal, l'ordre de déclaration tient.
		uasort( $tabs, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
		return array_map( static fn( array $tab ): string => is_callable( $tab['title'] ) ? (string) call_user_func( $tab['title'] ) : (string) $tab['title'], $tabs );
	}

	/**
	 * Groupe d'un slug, ou null s'il n'est pas un onglet.
	 *
	 * @param string $slug Identifiant de la page.
	 */
	public static function group_of( string $slug ): ?string {
		foreach ( self::$tabs as $group => $tabs ) {
			if ( isset( $tabs[ $slug ] ) ) {
				return $group;
			}
		}
		return null;
	}

	/**
	 * Adresse d'un onglet ou de l'accueil.
	 *
	 * @param string $slug Identifiant de la page.
	 */
	public static function url( string $slug = self::SLUG ): string {
		return admin_url( 'admin.php?page=' . $slug );
	}

	/**
	 * Barre d'onglets : de simples liens, l'onglet courant porte aria-current="page". Rien sous deux onglets.
	 *
	 * @param string $group   Identifiant du groupe.
	 * @param string $current Slug de la page affichée.
	 */
	public static function nav( string $group, string $current ): string {
		$tabs = self::tabs( $group );
		if ( count( $tabs ) < 2 ) {
			return '';
		}
		$html = '<nav class="fl-tabs" aria-label="' . esc_attr( self::group_label( $group ) ) . '"><ul>';
		foreach ( $tabs as $slug => $title ) {
			$html .= sprintf(
				'<li><a href="%1$s"%2$s>%4$s%3$s</a></li>',
				esc_url( self::url( $slug ) ),
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $title ),
				'' === Icon::for_tab( $slug ) ? '' : Icon::svg( Icon::for_tab( $slug ) ) // SVG généré, liste blanche (tools/build-icons.mjs).
			);
		}
		return $html . '</ul></nav>';
	}

	/** Enregistre l'accueil et les onglets ; l'entrée de menu d'un groupe est son premier onglet, sous son titre. */
	public static function build(): void {
		Screen::register();
		$home = self::screen( '', self::SLUG, array( Home::class, 'render' ) );
		// Icône propre (plume), pas celle de la boîte à commentaires : SVG généré, liste blanche (tools/build-icons.mjs).
		add_menu_page( 'Fluffless', 'Fluffless', SettingsPage::CAPABILITY, self::SLUG, $home, 'data:image/svg+xml;base64,' . base64_encode( Icon::svg( 'feather' ) ), 81 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URI de données de l'icône de menu (SVG de la liste blanche).
		add_submenu_page( self::SLUG, 'Fluffless', __( 'Home', 'fluffless-suite' ), SettingsPage::CAPABILITY, self::SLUG, $home );

		foreach ( self::GROUPS as $group ) {
			foreach ( self::tabs( $group ) as $slug => $title ) {
				$tab = self::$tabs[ $group ][ $slug ];
				add_submenu_page( self::SLUG, $title, $title, $tab['capability'], $slug, self::screen( $group, $slug, $tab['render'] ) );
			}
		}
	}

	/**
	 * Rendu d'un écran : onglets du groupe, écran du module, pied commun.
	 *
	 * @param string   $group  Groupe ('' pour l'accueil).
	 * @param string   $slug   Slug de la page.
	 * @param callable $render Rendu du module.
	 */
	private static function screen( string $group, string $slug, callable $render ): \Closure {
		return static function () use ( $group, $slug, $render ): void {
			echo self::nav( $group, $slug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans nav().
			call_user_func( $render );
			Screen::footer();
		};
	}

	/**
	 * Retire du menu les onglets qui ne sont pas en tête de groupe. Posé sur admin_head : la page courante
	 * est déjà résolue (titre, parent, droit d'accès), et le menu n'est pas encore affiché.
	 */
	public static function hide_tabs(): void {
		foreach ( self::GROUPS as $group ) {
			foreach ( array_slice( array_keys( self::tabs( $group ) ), 1 ) as $slug ) {
				remove_submenu_page( self::SLUG, $slug );
			}
		}
	}

	/**
	 * Sur un onglet masqué, l'entrée de son groupe reste en surbrillance dans le menu.
	 *
	 * @param mixed $submenu_file Entrée courante calculée par WordPress.
	 * @return mixed
	 */
	public static function submenu_file( $submenu_file ) {
		$group = is_string( $submenu_file ) ? self::group_of( $submenu_file ) : null;
		return null === $group ? $submenu_file : array_key_first( self::tabs( $group ) );
	}

	/**
	 * Destination d'une ancienne adresse, ou null.
	 *
	 * @param string               $pagenow Écran demandé (options-general.php...).
	 * @param array<string, mixed> $query   Paramètres de la requête.
	 */
	public static function legacy_url( string $pagenow, array $query ): ?string {
		$page = is_string( $query['page'] ?? null ) ? $query['page'] : '';
		$to   = self::LEGACY[ $pagenow ][ $page ] ?? null;
		if ( null === $to ) {
			return null;
		}
		$url = self::url( $to );
		// Le message « Réglages enregistrés » suit l'ancienne adresse de retour d'options.php.
		if ( is_string( $query['settings-updated'] ?? null ) ) {
			$url = add_query_arg( 'settings-updated', rawurlencode( sanitize_key( $query['settings-updated'] ) ), $url );
		}
		return $url;
	}

	/** Redirige une ancienne adresse avant le contrôle d'accès de WordPress, qui la refuserait. */
	public static function redirect_legacy(): void {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture seule d'une adresse, aucune action.
		$url = is_string( $pagenow ) ? self::legacy_url( $pagenow, (array) wp_unslash( $_GET ) ) : null;
		if ( null !== $url && wp_safe_redirect( $url ) ) {
			exit;
		}
	}
}
