<?php
/**
 * Module Palette : générateur de palettes durables, appliqué aux styles globaux du site.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Blocks\ModeCouleur;
use Fluffless\Suite\Blocks\Sections;
use Fluffless\Suite\Core\GlobalStyles;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Presentation\LinkHover;
use Fluffless\Suite\Presentation\Patterns;
use Fluffless\Suite\Presentation\Scheme;
use WP_Theme_JSON_Resolver;
use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * La palette s'écrit dans settings.color.palette.theme des styles globaux de l'utilisateur,
 * comme le fait l'éditeur de site : les 9 rôles gardent leurs slugs et leurs noms, chaque
 * pattern suit. Avant la première écriture, la valeur d'origine de la clé est gardée (option
 * fluffless_palette) ; « Revenir aux couleurs du thème » la remet, ou retire la clé.
 *
 * Le serveur refuse toute palette sous son seuil (4,5:1 texte, 3:1 bouton) sur les paires de
 * tools/check-contrast.mjs. Le générateur (assets/admin/palette-core.js) les respecte déjà, mais
 * une saisie à la main aussi.
 * L'écran marche sans JS (9 champs couleur) ; le JS ajoute le générateur et l'aperçu en direct.
 * Rien n'est chargé côté visiteur.
 */
final class Palette implements Module {

	public const PAGE   = 'fluffless-palette';
	public const ACTION = 'fluffless_palette';
	public const OPTION = Lifecycle::OPTION_PALETTE;

	/** Poignée de la feuille en ligne de l'effet de survol des liens. */
	public const LINK_HOVER_HANDLE = 'fluffless-link-hover';

	/** Nombre maximal de palettes enregistrées. */
	public const MAX_SAVED = 12;

	/** Nombre maximal de codes Pantone gardés (un par couleur HEX). */
	public const MAX_PANTONE = 60;

	/** Rôles de theme.json, dans le même ordre. */
	public const ROLES = array( 'base', 'surface', 'contrast', 'muted', 'divider', 'accent', 'on-accent', 'accent-2', 'alert' );

	/**
	 * Rôles facultatifs : absents de theme.json, une variation ou un thème enfant peut les déclarer.
	 * button-bg : fond des boutons ; sans lui, les boutons gardent accent (repli CSS de theme.json).
	 */
	public const OPTIONAL_ROLES = array( 'button-bg' );

	/** Paires [texte, fond] à 4,5:1, les mêmes que tools/check-contrast.mjs et palette-core.js. */
	public const PAIRS = array(
		array( 'contrast', 'base' ),
		array( 'contrast', 'surface' ),
		array( 'muted', 'base' ),
		array( 'muted', 'surface' ),
		array( 'accent', 'base' ),
		array( 'accent', 'surface' ),
		array( 'on-accent', 'accent' ),
		array( 'accent-2', 'base' ),
		array( 'accent-2', 'surface' ),
		array( 'base', 'accent-2' ),
		array( 'alert', 'base' ),
		array( 'base', 'contrast' ),
		array( 'on-accent', 'button-bg' ),
	);

	/** Paires [composant, fond adjacent] à 3:1 (WCAG 1.4.11), les mêmes que tools/check-contrast.mjs. */
	public const UI_PAIRS = array(
		array( 'button-bg', 'base' ),
		array( 'button-bg', 'surface' ),
	);

	/**
	 * Bordure de bouton déclarée par le thème (voir button()) : c'est elle, et non le fond, qui
	 * délimite le bouton ; elle remplace UI_PAIRS. « button-border » n'est pas un rôle de palette,
	 * sa couleur vient du rôle ou du code hexadécimal posé sur la bordure.
	 */
	public const BORDER_PAIRS = array(
		array( 'button-border', 'base' ),
		array( 'button-border', 'surface' ),
	);

	/** Texte large (WCAG 1.4.3) en pixels : 14 pt en gras, 18 pt sinon. */
	private const LARGE_BOLD = 18.66;
	private const LARGE      = 24.0;

	private const AA   = 4.5;
	private const UI   = 3.0;
	private const PATH = 'settings.color.palette.theme';

	/** Couleurs hors rôles gardées au plus : une palette de marque tient bien en dessous. */
	private const EXTRAS_MAX = 32;

	/** Part de l'écran occupée par chaque rôle, pour l'indice (comme palette-core.js). */
	private const WEIGHT = array(
		'base'     => 0.55,
		'surface'  => 0.2,
		'muted'    => 0.12,
		'accent'   => 0.08,
		'accent-2' => 0.05,
	);

	/** Identifiant stable. */
	public static function id(): string {
		return 'palette';
	}

	/** Nom affiché. */
	public static function label(): string {
		return __( 'Palette', 'fluffless-suite' );
	}

	/** Ce que le module change. */
	public static function description(): string {
		return __( 'Build a readable colour palette for the whole site, and see an estimate of the screen power darker colours can save on OLED screens, compared with a white page.', 'fluffless-suite' );
	}

	/** Actif sur une installation neuve : écran d'administration seulement. */
	public static function enabled_by_default(): bool {
		return true;
	}

	/**
	 * Aucun réglage : tout se passe sur l'écran Palette.
	 *
	 * @return array<string, bool|int|string|list<string>>
	 */
	public static function defaults(): array {
		return array();
	}

	/**
	 * Aucun réglage à assainir.
	 *
	 * @param array<mixed> $input Section brute.
	 * @return array<string, bool|int|string|list<string>>
	 */
	public static function sanitize( array $input ): array {
		unset( $input );
		return array();
	}

	/**
	 * Aucun champ dans Fluffless → Modules.
	 *
	 * @return array<string, array{type: 'bool', label: string}>
	 */
	public static function fields(): array {
		return array();
	}

	/** Crochets. */
	public function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'alternation_css' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'link_hover_css' ) );
		add_filter( 'the_content', array( self::class, 'start_alternation' ), 1 );
		add_filter( 'the_content', array( self::class, 'stop_alternation' ), 99 );
		add_filter( 'render_block_data', array( self::class, 'alternate_block' ), 10, 3 );
		add_filter( 'render_block', array( self::class, 'mark_alternation' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'scheme_notice' ) );
		add_filter( 'wp_theme_json_data_user', array( self::class, 'keep_colors' ), 30 );
		add_action( 'save_post_wp_global_styles', array( self::class, 'restore_colors' ) );
		ModeCouleur::register();
	}

	/** Écran Fluffless → Palette. */
	public static function add_page(): void {
		Menu::add_tab( 'appearance', self::PAGE, static fn(): string => __( 'Palette', 'fluffless-suite' ), array( self::class, 'render' ), 10 );
	}

	/**
	 * Générateur et aperçu, seulement sur cet écran.
	 *
	 * @param mixed $hook Suffixe de l'écran.
	 */
	public static function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || ! str_contains( $hook, self::PAGE ) ) {
			return;
		}
		$args = array(
			'in_footer' => true,
			'strategy'  => 'defer',
		);
		wp_enqueue_script( 'fluffless-palette-core', plugins_url( 'assets/admin/palette-core.js', FILE ), array(), VERSION, $args );
		wp_enqueue_script( 'fluffless-palette', plugins_url( 'assets/admin/palette.js', FILE ), array( 'fluffless-palette-core' ), VERSION, $args );
	}

	/** Panneau de l'éditeur de site : générateur, écriture dans les styles globaux en cours d'édition. */
	public static function editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'site-editor' !== $screen->id || ! current_user_can( 'edit_theme_options' ) || ! Patterns::applies() ) {
			return;
		}
		wp_enqueue_script( 'fluffless-palette-core', plugins_url( 'assets/admin/palette-core.js', FILE ), array(), VERSION, true );
		wp_enqueue_script( 'fluffless-palette-editor', plugins_url( 'assets/palette-editor.js', FILE ), array( 'fluffless-palette-core', 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-plugins' ), VERSION, true );
		wp_add_inline_script( 'fluffless-palette-editor', 'window.flufflessPalette=' . wp_json_encode( self::editor_data() ) . ';', 'before' );
		wp_register_style( 'fluffless-palette-editor', false, array(), VERSION );
		wp_enqueue_style( 'fluffless-palette-editor' );
		wp_add_inline_style( 'fluffless-palette-editor', '.fl-palette-panel__roles{margin:1rem 0 0;padding:0;list-style:none}.fl-palette-panel__roles li{display:flex;align-items:center;gap:8px;margin-bottom:8px}.fl-palette-panel .components-button{margin-block:8px}' );
	}

	/**
	 * Données et textes du panneau de l'éditeur de site.
	 *
	 * @return array<string, mixed>
	 */
	public static function editor_data(): array {
		$options = array();
		foreach ( self::generator_options() as $key => $option ) {
			$options[ $key ] = array(
				'label'   => $option[0],
				'choices' => $option[1],
			);
		}
		return array(
			'palette' => self::current(),
			'roles'   => self::pair_labels(),
			'button'  => self::button(),
			'names'   => self::theme_names() + self::role_labels(),
			'extras'  => self::extras(),
			'options' => $options,
			'screen'  => admin_url( 'admin.php?page=' . self::PAGE ),
			'i18n'    => array(
				'title'    => __( 'Palette', 'fluffless-suite' ),
				'generate' => __( 'Generate', 'fluffless-suite' ),
				'keep'     => __( 'Check a colour to keep it the next time you generate.', 'fluffless-suite' ),
				/* translators: %d: energy index from 0 to 100. */
				'score'    => __( 'OLED energy index: %d/100', 'fluffless-suite' ),
				'ok'       => __( 'Every colour pair reaches its contrast ratio: 4.5:1 for text, 3:1 for buttons.', 'fluffless-suite' ),
				/* translators: %s: list of colour pairs, like "Accent on Background". */
				'bad'      => __( 'Hard to read: %s. Generate again or uncheck some colours.', 'fluffless-suite' ),
				/* translators: 1: text colour role, 2: background colour role. Example: "Accent on Background". */
				'pair'     => __( '%1$s on %2$s', 'fluffless-suite' ),
				'apply'    => __( 'Apply to site styles', 'fluffless-suite' ),
				'save'     => __( 'The editor shows the new colours right away. Save the site to keep them.', 'fluffless-suite' ),
				'screen'   => __( 'Brand sheet and saved palettes', 'fluffless-suite' ),
			),
		);
	}

	/* ---------- Couleurs ---------- */

	/**
	 * Les 9 rôles en #rrggbb minuscules, dans l'ordre de ROLES, puis les rôles facultatifs fournis ;
	 * null si un rôle manque ou si une couleur fournie est invalide. Un rôle facultatif vide est ignoré.
	 *
	 * @param array<mixed> $input Couleurs brutes par rôle.
	 * @return array<string, string>|null
	 */
	public static function sanitize_palette( array $input ): ?array {
		// Palettes enregistrées avant que le rôle « border » devienne « divider ».
		if ( ! isset( $input['divider'] ) && isset( $input['border'] ) ) {
			$input['divider'] = $input['border'];
		}
		$out = array();
		foreach ( self::ROLES as $role ) {
			$value = $input[ $role ] ?? null;
			if ( ! is_string( $value ) || 1 !== preg_match( '/^#[0-9a-f]{6}$/iD', $value ) ) {
				return null;
			}
			$out[ $role ] = strtolower( $value );
		}
		foreach ( self::OPTIONAL_ROLES as $role ) {
			$value = $input[ $role ] ?? '';
			if ( '' === $value ) {
				continue;
			}
			if ( ! is_string( $value ) || 1 !== preg_match( '/^#[0-9a-f]{6}$/iD', $value ) ) {
				return null;
			}
			$out[ $role ] = strtolower( $value );
		}
		return $out;
	}

	/**
	 * Toutes les paires vérifiées : [premier plan, fond, ratio minimal].
	 * Le texte du bouton descend à 3:1 s'il est large ; une bordure déclarée remplace le fond du
	 * bouton dans les paires de contour.
	 *
	 * @param array{border: string, large: bool}|null $button Style des boutons ; celui du thème par défaut.
	 * @return list<array{0: string, 1: string, 2: float}>
	 */
	public static function checks( ?array $button = null ): array {
		$button = $button ?? self::button();
		$out    = array();
		foreach ( self::PAIRS as $pair ) {
			$large = $button['large'] && 'button-bg' === $pair[1];
			$out[] = array( $pair[0], $pair[1], $large ? self::UI : self::AA );
		}
		foreach ( '' === $button['border'] ? self::UI_PAIRS : self::BORDER_PAIRS as $pair ) {
			$out[] = array( $pair[0], $pair[1], self::UI );
		}
		return $out;
	}

	/**
	 * Paires sous leur seuil (4,5:1 pour le texte, 3:1 pour le texte large du bouton et pour le
	 * contour d'un composant), au format « premier plan/fond ». Une paire dont un rôle facultatif
	 * est absent n'est pas vérifiée.
	 *
	 * @param array<string, string>                   $palette Palette assainie.
	 * @param array{border: string, large: bool}|null $button  Style des boutons ; celui du thème par défaut.
	 * @return list<string>
	 */
	public static function failures( array $palette, ?array $button = null ): array {
		$button  = $button ?? self::button();
		$palette = self::with_border( $palette, $button );
		$bad     = array();
		foreach ( self::checks( $button ) as $check ) {
			if ( isset( $palette[ $check[0] ], $palette[ $check[1] ] ) && self::ratio( $palette[ $check[0] ], $palette[ $check[1] ] ) < $check[2] ) {
				$bad[] = $check[0] . '/' . $check[1];
			}
		}
		return $bad;
	}

	/**
	 * Ajoute à la palette la couleur de la bordure de bouton, sous « button-border ».
	 *
	 * @param array<string, string>              $palette Palette assainie.
	 * @param array{border: string, large: bool} $button  Style des boutons.
	 * @return array<string, string>
	 */
	private static function with_border( array $palette, array $button ): array {
		$border = $button['border'];
		if ( '' !== $border ) {
			$color = '#' === $border[0] ? $border : ( $palette[ $border ] ?? '' );
			if ( '' !== $color ) {
				$palette['button-border'] = $color;
			}
		}
		return $palette;
	}

	/**
	 * Style des boutons déclaré par le thème (et les styles globaux) : couleur de bordure, en rôle
	 * de palette ou en #rrggbb, vide si la bordure est absente, transparente ou d'épaisseur nulle ;
	 * texte large si la taille et la graisse atteignent le seuil WCAG. Une taille fluide compte
	 * pour son minimum, une taille inconnue pour du texte courant.
	 *
	 * @return array{border: string, large: bool}
	 */
	public static function button(): array {
		$style  = function_exists( 'wp_get_global_styles' ) ? (array) wp_get_global_styles( array( 'elements', 'button' ) ) : array();
		$border = (array) ( $style['border'] ?? array() );
		$type   = (array) ( $style['typography'] ?? array() );
		return array(
			'border' => self::border_color( $border ),
			'large'  => self::is_large( (string) ( $type['fontSize'] ?? '' ), (string) ( $type['fontWeight'] ?? '' ) ),
		);
	}

	/**
	 * Couleur d'une bordure visible : slug de rôle ou #rrggbb ; vide sinon.
	 *
	 * @param array<mixed> $border Réglages border d'un élément.
	 */
	private static function border_color( array $border ): string {
		$style = strtolower( (string) ( $border['style'] ?? 'solid' ) );
		if ( self::px( (string) ( $border['width'] ?? '' ) ) <= 0 || in_array( $style, array( 'none', 'hidden' ), true ) ) {
			return '';
		}
		$color = strtolower( trim( (string) ( $border['color'] ?? '' ) ) );
		if ( 1 === preg_match( '/^#[0-9a-f]{6}$/D', $color ) ) {
			return $color;
		}
		if ( 1 === preg_match( '/^(?:var:preset\|color\||var\(--wp--preset--color--)([a-z0-9-]+)/', $color, $m ) && in_array( $m[1], array_merge( self::ROLES, self::OPTIONAL_ROLES ), true ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Texte large au sens de WCAG 1.4.3 : au moins 14 pt en gras ou 18 pt.
	 *
	 * @param string $size   Taille : préréglage (var:preset|font-size|slug), px, rem, em ou clamp().
	 * @param string $weight Graisse.
	 */
	private static function is_large( string $size, string $weight ): bool {
		if ( 1 === preg_match( '/^(?:var:preset\|font-size\||var\(--wp--preset--font-size--)([a-z0-9-]+)/', $size, $m ) ) {
			$size = '';
			foreach ( (array) wp_get_global_settings( array( 'typography', 'fontSizes' ) ) as $origin ) {
				foreach ( (array) $origin as $preset ) {
					if ( is_array( $preset ) && ( $preset['slug'] ?? '' ) === $m[1] ) {
						$fluid = $preset['fluid'] ?? null;
						$size  = (string) ( is_array( $fluid ) && isset( $fluid['min'] ) ? $fluid['min'] : ( $preset['size'] ?? '' ) );
					}
				}
			}
		}
		$px   = self::px( $size );
		$bold = in_array( strtolower( $weight ), array( 'bold', 'bolder' ), true ) || (int) $weight >= 700;
		return $px >= self::LARGE || ( $bold && $px >= self::LARGE_BOLD );
	}

	/**
	 * Longueur en pixels (px, rem et em à 16 px ; premier terme d'un clamp() ou d'un min()) ; 0 si inconnue.
	 *
	 * @param string $length Longueur CSS.
	 */
	private static function px( string $length ): float {
		if ( 1 !== preg_match( '/^\s*(?:(?:clamp|min)\(\s*)?([0-9]*\.?[0-9]+)(px|rem|em)\b/i', $length, $m ) ) {
			return 0.0;
		}
		return (float) $m[1] * ( 'px' === strtolower( $m[2] ) ? 1 : 16 );
	}

	/**
	 * Noms des paires : rôles, plus la bordure de bouton.
	 *
	 * @return array<string, string>
	 */
	public static function pair_labels(): array {
		return self::role_labels() + array( 'button-border' => __( 'Button border', 'fluffless-suite' ) );
	}

	/**
	 * Indice de 0 à 100 : part de l'énergie d'écran évitée par rapport au blanc pur, sur écran OLED.
	 * Modèle du générateur : somme RVB, bleu pénalisé de 25 %, pondérée par la surface de chaque rôle.
	 *
	 * @param array<string, string> $palette Palette assainie.
	 */
	public static function score( array $palette ): int {
		$total = 0.0;
		foreach ( self::WEIGHT as $role => $weight ) {
			$rgb    = self::rgb( $palette[ $role ] ?? '#000000' );
			$total += ( $rgb[0] + $rgb[1] + $rgb[2] * 1.25 ) / ( 255 * 3.25 ) * $weight;
		}
		return (int) max( 0, min( 100, round( ( 1 - $total ) * 100 ) ) );
	}

	/**
	 * Palette en place sur le site (styles globaux fusionnés), dans l'ordre de ROLES, puis les
	 * rôles facultatifs déclarés.
	 *
	 * @return array<string, string>
	 */
	public static function current(): array {
		$map = array();
		foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $color ) {
			if ( is_array( $color ) && is_string( $color['slug'] ?? null ) && is_string( $color['color'] ?? null ) ) {
				$map[ $color['slug'] ] = strtolower( $color['color'] );
			}
		}
		$out = array();
		foreach ( array_merge( self::ROLES, self::OPTIONAL_ROLES ) as $role ) {
			if ( isset( $map[ $role ] ) ) {
				$out[ $role ] = $map[ $role ];
			}
		}
		return $out;
	}

	/** Une palette autre que celle du thème est-elle en place (écran Palette ou éditeur de site) ? */
	public static function is_custom(): bool {
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && is_array( $stored['palette'] ?? null ) ) {
			return true;
		}
		$styles = GlobalStyles::user();
		return null !== $styles && null !== GlobalStyles::get( $styles['data'], self::PATH );
	}

	/**
	 * Applique une palette lisible au site entier ; refuse toute paire sous son seuil (4,5:1 texte, 3:1 bouton).
	 *
	 * @param array<mixed> $input Couleurs par rôle.
	 */
	public static function apply( array $input ): bool {
		$palette = self::sanitize_palette( $input );
		$styles  = GlobalStyles::user();
		if ( null === $palette || array() !== self::failures( $palette ) || null === $styles ) {
			return false;
		}
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		if ( ! array_key_exists( 'backup', $stored ) ) {
			$stored['backup'] = GlobalStyles::get( $styles['data'], self::PATH );
		}
		if ( ! self::write( $styles['id'], GlobalStyles::put( $styles['data'], self::PATH, self::entries( $palette ) ) ) ) {
			return false;
		}
		$stored['palette'] = $palette;
		update_option( self::OPTION, $stored, false );
		return true;
	}

	/** Remet la palette d'avant la première application. */
	public static function reset(): bool {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		// Sans copie d'origine (palette posée depuis l'éditeur de site), la clé est retirée.
		$styles  = GlobalStyles::user();
		$written = null === $styles ? null : GlobalStyles::get( $styles['data'], self::PATH );
		if ( array_key_exists( 'backup', $stored ) || null !== $written ) {
			if ( null === $styles || ! self::write( $styles['id'], GlobalStyles::put( $styles['data'], self::PATH, $stored['backup'] ?? null ) ) ) {
				return false;
			}
		}
		unset( $stored['backup'], $stored['palette'] );
		self::store( $stored );
		return true;
	}

	/**
	 * Enregistre les styles de la personne sans que restore_colors() y remette l'ancienne palette :
	 * l'option n'est mise à jour qu'après cet enregistrement.
	 *
	 * @param int                  $id   Identifiant des styles.
	 * @param array<string, mixed> $data Styles à enregistrer.
	 */
	private static function write( int $id, array $data ): bool {
		remove_action( 'save_post_wp_global_styles', array( self::class, 'restore_colors' ) );
		$saved = GlobalStyles::save( $id, $data );
		add_action( 'save_post_wp_global_styles', array( self::class, 'restore_colors' ) );
		return $saved;
	}

	/**
	 * Écrit l'option du module, ou la retire quand il n'y reste rien.
	 *
	 * @param array<string, mixed> $stored Contenu de l'option.
	 */
	private static function store( array $stored ): void {
		if ( array() === $stored ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $stored, false );
	}

	/* ---------- Fonds en alternance ---------- */

	/** Rôles offerts comme fond alterné, avec le rôle de texte lisible dessus (paires de PAIRS à 4,5:1). */
	public const ALTERNATE_ROLES = array(
		'base'     => 'contrast',
		'surface'  => 'contrast',
		'accent'   => 'on-accent',
		'accent-2' => 'base',
		'contrast' => 'base',
	);

	/** Blocs du cœur qui forment une section de page dans les compositions du thème. */
	public const ALTERNATE_BLOCKS = array( 'core/group' );

	/**
	 * Compteur des sections alternées de la publication en cours ; -1 hors de the_content.
	 *
	 * @var int
	 */
	private static int $alternated = -1;

	/**
	 * Réglage des fonds en alternance : éteint par défaut, Base puis Surface.
	 *
	 * @return array{on: bool, first: string, second: string}
	 */
	public static function alternation(): array {
		$stored = get_option( self::OPTION );
		$raw    = is_array( $stored ) && is_array( $stored['alternate'] ?? null ) ? $stored['alternate'] : array();
		$first  = is_string( $raw['first'] ?? null ) && isset( self::ALTERNATE_ROLES[ $raw['first'] ] ) ? $raw['first'] : 'base';
		$second = is_string( $raw['second'] ?? null ) && isset( self::ALTERNATE_ROLES[ $raw['second'] ] ) ? $raw['second'] : 'surface';
		return array(
			'on'     => true === ( $raw['on'] ?? false ),
			'first'  => $first,
			'second' => $second,
		);
	}

	/**
	 * Enregistre le réglage ; deux rôles identiques ou inconnus reviennent à Base et Surface.
	 *
	 * @param array<mixed> $input on, first, second.
	 */
	public static function save_alternation( array $input ): void {
		$first  = is_string( $input['first'] ?? null ) && isset( self::ALTERNATE_ROLES[ $input['first'] ] ) ? $input['first'] : 'base';
		$second = is_string( $input['second'] ?? null ) && isset( self::ALTERNATE_ROLES[ $input['second'] ] ) ? $input['second'] : 'surface';
		if ( $first === $second ) {
			$first  = 'base';
			$second = 'surface';
		}
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		$on     = in_array( $input['on'] ?? false, array( true, 1, '1', 'true', 'on' ), true );
		if ( ! $on && 'base' === $first && 'surface' === $second ) {
			unset( $stored['alternate'] );
		} else {
			$stored['alternate'] = array(
				'on'     => $on,
				'first'  => $first,
				'second' => $second,
			);
		}
		self::store( $stored );
	}

	/**
	 * Feuille des deux fonds : les sections marquées, et les zébrures du Tableau qui reprennent la
	 * même paire. Sur un fond accent, le bouton et le texte atténué suivent les règles des
	 * sections à fond accent. Rien n'est écrit quand le réglage est éteint.
	 */
	public static function alternation_css(): void {
		$css = self::alternation_rules();
		if ( '' === $css ) {
			return;
		}
		wp_register_style( 'fluffless-alternate', false, array(), VERSION );
		wp_enqueue_style( 'fluffless-alternate' );
		wp_add_inline_style( 'fluffless-alternate', $css );
	}

	/** Règles CSS de l'alternance ; '' quand le réglage est éteint. */
	public static function alternation_rules(): string {
		$alt = self::alternation();
		if ( ! $alt['on'] ) {
			return '';
		}
		$css = '';
		foreach ( array(
			1 => $alt['first'],
			2 => $alt['second'],
		) as $n => $role ) {
			$bg   = 'var(--wp--preset--color--' . $role . ')';
			$text = 'var(--wp--preset--color--' . self::ALTERNATE_ROLES[ $role ] . ')';
			$css .= '.is-fl-alt-' . $n . '{background:' . $bg . ';color:' . $text . ';--fl-alt-fond:' . $bg . '}';
			if ( 'accent' === $role ) {
				$css .= '.is-fl-alt-' . $n . ' .has-muted-color{color:inherit!important}'
					. '.is-fl-alt-' . $n . ' .wp-block-button:not(.is-style-outline)>.wp-element-button{background:' . $text . ';color:' . $bg . '}';
			}
			$row  = 1 === $n ? 'even' : 'odd';
			$css .= 'body .fluffless-tableau.is-stripes-striped tbody tr:nth-child(' . $row . '){background:' . $bg . ';color:' . $text . '}';
		}
		return $css . '.fluffless-section:is(.is-fl-alt-1,.is-fl-alt-2){padding-inline:var(--wp--preset--spacing--50,2rem)}';
	}

	/**
	 * Ouvre le comptage au début du contenu d'une publication (priorité 1, avant do_blocks).
	 *
	 * @param mixed $content Contenu.
	 * @return mixed
	 */
	public static function start_alternation( $content ) {
		self::$alternated = 0;
		return $content;
	}

	/**
	 * Ferme le comptage une fois les blocs rendus : les groupes du gabarit qui suivent ne comptent pas.
	 *
	 * @param mixed $content Contenu.
	 * @return mixed
	 */
	public static function stop_alternation( $content ) {
		self::$alternated = -1;
		return $content;
	}

	/**
	 * Marque une section de premier niveau du contenu (bloc de la Suite ou groupe du thème) qui
	 * n'a pas son propre fond : attribut flAlternate à 1 ou 2 en alternance, que mark_alternation()
	 * traduit en classe. Les sections à
	 * fond surface, accent ou image, les groupes à couleur, dégradé ou style inversé gardent le leur
	 * et ne comptent pas.
	 *
	 * @param mixed $parsed_block Bloc analysé.
	 * @param mixed $source_block Bloc d'origine.
	 * @param mixed $parent_block Bloc parent, null au premier niveau.
	 * @return mixed
	 */
	public static function alternate_block( $parsed_block, $source_block, $parent_block ) {
		unset( $source_block );
		if ( null !== $parent_block || self::$alternated < 0 || ! is_array( $parsed_block ) || ! self::alternation()['on'] ) {
			return $parsed_block;
		}
		$name  = is_string( $parsed_block['blockName'] ?? null ) ? $parsed_block['blockName'] : '';
		$attrs = is_array( $parsed_block['attrs'] ?? null ) ? $parsed_block['attrs'] : array();
		if ( ! self::alternates( $name, $attrs ) ) {
			return $parsed_block;
		}
		++self::$alternated;
		$parsed_block['attrs']                = $attrs;
		$parsed_block['attrs']['flAlternate'] = 1 + ( ( self::$alternated - 1 ) % 2 );
		return $parsed_block;
	}

	/**
	 * Pose la classe is-fl-alt-1 ou is-fl-alt-2 sur l'enveloppe d'un bloc marqué par alternate_block().
	 * Le cœur ne réécrit pas className sur un groupe enregistré, d'où la classe posée sur le HTML rendu.
	 *
	 * @param mixed $content Rendu du bloc.
	 * @param mixed $block   Bloc analysé.
	 * @return mixed
	 */
	public static function mark_alternation( $content, $block ) {
		$n = is_array( $block ) && is_array( $block['attrs'] ?? null ) ? ( $block['attrs']['flAlternate'] ?? 0 ) : 0;
		if ( ! is_string( $content ) || ! in_array( $n, array( 1, 2 ), true ) ) {
			return $content;
		}
		$p = new \WP_HTML_Tag_Processor( $content );
		if ( ! $p->next_tag() ) {
			return $content;
		}
		$p->add_class( 'is-fl-alt-' . $n );
		return $p->get_updated_html();
	}

	/**
	 * Un bloc sans fond propre, parmi les sections de la Suite et les groupes du thème ?
	 *
	 * @param string       $name  Nom du bloc.
	 * @param array<mixed> $attrs Attributs.
	 */
	private static function alternates( string $name, array $attrs ): bool {
		$class = is_string( $attrs['className'] ?? null ) ? $attrs['className'] : '';
		if ( str_contains( $class, 'is-style-inverse' ) || str_contains( $class, 'is-fl-alt-' ) ) {
			return false;
		}
		if ( str_starts_with( $name, 'fluffless/' ) && class_exists( Sections::class ) && isset( Sections::OPTIONS[ substr( $name, 10 ) ] ) ) {
			$background = $attrs['background'] ?? 'none';
			return ! is_string( $background ) || 'none' === $background;
		}
		if ( ! in_array( $name, self::ALTERNATE_BLOCKS, true ) ) {
			return false;
		}
		$style = is_array( $attrs['style'] ?? null ) && is_array( $attrs['style']['color'] ?? null ) ? $attrs['style']['color'] : array();
		return ! isset( $attrs['backgroundColor'] ) && ! isset( $attrs['gradient'] ) && ! isset( $style['background'] ) && ! isset( $style['gradient'] );
	}

	/** Formulaire des fonds en alternance, sous la palette. */
	private static function render_alternation(): void {
		$alt    = self::alternation();
		$labels = self::pair_labels();
		echo '<section class="fl-palette-alternate" aria-labelledby="fl-alt-title"><h2 id="fl-alt-title">' . esc_html__( 'Alternating backgrounds', 'fluffless-suite' ) . '</h2>';
		printf( '<form method="post" action="%1$s"><input type="hidden" name="action" value="%2$s">', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( self::ACTION ) );
		wp_nonce_field( self::ACTION );
		echo '<p id="fl-alt-help" class="description">' . esc_html__( 'Sections of a page take the two colours in turn, one after the other. Sections that already have a background (surface, accent, image) keep it. The Data table block uses the same pair for its stripes.', 'fluffless-suite' ) . '</p>';
		echo '<p><label for="fl-alt-on">' . esc_html__( 'Alternate section backgrounds', 'fluffless-suite' ) . '</label> ' . Screen::switch_control( 'fl_alternate[on]', 'fl-alt-on', $alt['on'], '', 'fl-alt-help' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::switch_control().
		foreach ( array(
			'first'  => __( 'First colour', 'fluffless-suite' ),
			'second' => __( 'Second colour', 'fluffless-suite' ),
		) as $key => $label ) {
			echo '<p><label for="fl-alt-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label> <select id="fl-alt-' . esc_attr( $key ) . '" name="fl_alternate[' . esc_attr( $key ) . ']">';
			foreach ( array_keys( self::ALTERNATE_ROLES ) as $role ) {
				echo '<option value="' . esc_attr( $role ) . '"' . selected( $alt[ $key ], $role, false ) . '>' . esc_html( $labels[ $role ] ?? $role ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '<p><button type="submit" class="button button-secondary" name="fl_alternate_save" value="1">' . esc_html__( 'Save alternation', 'fluffless-suite' ) . '</button></p>';
		echo '</form></section>';
	}

	/* ---------- Clair ou sombre selon l'appareil ---------- */

	/**
	 * Aperçu du logo du site sur le fond de la palette, puis sur celui de la jumelle : les images
	 * gardent leurs couleurs, un logo sombre peut disparaître. Vide sans logo.
	 *
	 * @param array<string, string> $palette Palette en place.
	 * @param array<string, string> $twin    Jumelle.
	 */
	public static function scheme_logo( array $palette, array $twin ): string {
		$id    = (int) get_option( 'site_logo' );
		$id    = $id > 0 ? $id : (int) get_theme_mod( 'custom_logo' );
		$image = $id > 0 ? wp_get_attachment_image( $id, 'medium', false, array( 'alt' => '' ) ) : '';
		if ( '' === $image ) {
			return '';
		}
		$tiles = '';
		foreach ( array( $palette['base'] ?? '', $twin['base'] ?? '' ) as $ground ) {
			if ( 1 !== preg_match( '/^#[0-9a-f]{6}$/D', $ground ) ) {
				return '';
			}
			$tiles .= '<div class="fl-scheme-logo__tile" style="background:' . esc_attr( $ground ) . '">' . $image . '</div>';
		}
		return '<figure class="fl-scheme-logo"><div class="fl-scheme-logo__tiles">' . $tiles . '</div><figcaption class="description">' . esc_html__( 'Your logo on the background of your palette, then on the background of this version. Images keep their colours: if the logo is hard to see on one of them, use a version that reads on both backgrounds.', 'fluffless-suite' ) . '</figcaption></figure>';
	}

	/**
	 * Section « Clair ou sombre selon l'appareil » : la case, la jumelle en pastilles, l'aperçu du
	 * logo, le contrôle de contraste et l'indice d'énergie des deux versions.
	 *
	 * @param array<string, string> $palette Palette en place.
	 */
	private static function render_scheme( array $palette ): void {
		$on      = Scheme::enabled();
		$result  = Scheme::check( $palette, self::button() );
		$twin    = $result['twin'];
		$to_dark = ! Scheme::is_dark( $palette );
		echo '<section class="fl-palette-scheme" aria-labelledby="fl-scheme-title"><h2 id="fl-scheme-title">' . esc_html__( 'Light or dark by device', 'fluffless-suite' ) . '</h2>';
		printf( '<form method="post" action="%1$s"><input type="hidden" name="action" value="%2$s">', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( self::ACTION ) );
		wp_nonce_field( self::ACTION );
		echo '<p id="fl-scheme-help" class="description">' . esc_html__( 'Visitors whose phone or computer is set to the other mode see a matching version of your palette, computed for you and checked for contrast. A Dark mode button is added after the header menu so they can switch, and their choice is kept on every page.', 'fluffless-suite' ) . '</p>';
		echo '<p><label for="fl-scheme-on">' . esc_html__( 'Follow the device light or dark setting', 'fluffless-suite' ) . '</label> ' . Screen::switch_control( 'fl_scheme', 'fl-scheme-on', $on, '', 'fl-scheme-help' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::switch_control().
		echo '<p><button type="submit" class="button button-secondary" name="fl_scheme_save" value="1">' . esc_html__( 'Save light or dark setting', 'fluffless-suite' ) . '</button></p>';
		echo '</form>';
		if ( null === $twin ) {
			echo Screen::note( 'bad', __( 'This palette is incomplete, so no second version can be computed. Apply a palette with all nine colours first.', 'fluffless-suite' ) ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			return;
		}
		echo '<p>' . esc_html( $to_dark ? __( 'Your palette is light: devices set to dark see this dark version.', 'fluffless-suite' ) : __( 'Your palette is dark: devices set to light see this light version.', 'fluffless-suite' ) ) . '</p>';
		$labels = self::role_labels();
		echo '<ul class="fl-palette-twin">';
		foreach ( $twin as $role => $color ) {
			echo '<li><span class="fl-swatch" style="background:' . esc_attr( $color ) . '" aria-hidden="true"></span>' . esc_html( $labels[ $role ] ?? $role ) . ' <code>' . esc_html( $color ) . '</code></li>';
		}
		echo '</ul>';
		echo self::scheme_logo( $palette, $twin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans scheme_logo().
		if ( array() === $result['failures'] ) {
			echo Screen::note( 'good', __( 'Every colour pair of this version reaches its contrast ratio: 4.5:1 for text, 3:1 for buttons and field borders.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		} else {
			/* translators: %s: list of colour pairs, like "Accent on Background". */
			echo Screen::note( 'bad', sprintf( __( 'This version would be hard to read: %s. Nothing is sent to visitors until your palette lets it pass, for example with a less saturated accent.', 'fluffless-suite' ), implode( ', ', self::pair_names( $result['failures'] ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: energy index of the palette in use, 2: energy index of the second version, both from 0 to 100. */
					__( 'OLED energy index: %1$d/100 for your palette, %2$d/100 for this version.', 'fluffless-suite' ),
					self::score( $palette ),
					self::score( $twin )
				)
			)
		);
		if ( ! $to_dark ) {
			echo '<p class="description">' . esc_html__( 'A light version uses more screen power than your dark palette. Visitors only see it when their device asks for light.', 'fluffless-suite' ) . '</p>';
		}
		echo '</section>';
	}

	/**
	 * Noms lisibles de paires « premier plan/fond ».
	 *
	 * @param string[] $pairs Paires.
	 * @return list<string>
	 */
	private static function pair_names( array $pairs ): array {
		$named = self::pair_labels();
		$out   = array();
		foreach ( $pairs as $pair ) {
			$parts = explode( '/', $pair );
			if ( 2 === count( $parts ) ) {
				/* translators: 1: text colour role, 2: background colour role. Example: "Accent on Background". */
				$out[] = sprintf( __( '%1$s on %2$s', 'fluffless-suite' ), $named[ $parts[0] ] ?? $parts[0], $named[ $parts[1] ] ?? $parts[1] );
			}
		}
		return $out;
	}

	/**
	 * Avis d'administration quand le réglage est coché mais que la jumelle ne passe pas (par
	 * exemple après un changement de palette) : sans lui, le bouton disparaîtrait sans bruit.
	 */
	public static function scheme_notice(): void {
		if ( ! current_user_can( 'edit_theme_options' ) || ! Scheme::enabled() || Scheme::active() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null !== $screen && str_ends_with( (string) $screen->id, self::PAGE ) ) {
			return; // L'écran Palette le dit déjà, avec les paires en cause.
		}
		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Light or dark by device is on, but your current palette does not allow a readable second version, so visitors see your palette only.', 'fluffless-suite' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'See the colours to change', 'fluffless-suite' )
		);
	}

	/* ---------- Effet de survol des liens du site ---------- */

	/** Effet choisi pour les liens du contenu et de la navigation ; '' : aucun (défaut), comme pour une valeur inconnue. */
	public static function link_hover(): string {
		$stored = get_option( self::OPTION );
		$effect = is_array( $stored ) && is_string( $stored['link_hover'] ?? null ) ? $stored['link_hover'] : '';
		return in_array( $effect, LinkHover::EFFECTS, true ) ? $effect : '';
	}

	/**
	 * Enregistre l'effet ; une valeur inconnue revient à « aucun », et « aucun » ne garde rien dans l'option.
	 *
	 * @param string $effect Effet de LinkHover::EFFECTS, ou chaîne vide.
	 */
	public static function save_link_hover( string $effect ): void {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		if ( in_array( $effect, LinkHover::EFFECTS, true ) ) {
			$stored['link_hover'] = $effect;
		} else {
			unset( $stored['link_hover'] );
		}
		self::store( $stored );
	}

	/** Règles CSS de l'effet, sorties de LinkHover (même source que les styles de bloc « survol-* ») ; '' sans effet. */
	public static function link_hover_rules(): string {
		return LinkHover::rules( self::link_hover(), LinkHover::SITE_SCOPE );
	}

	/** Feuille en ligne de l'effet. Rien n'est écrit quand aucun effet n'est choisi. */
	public static function link_hover_css(): void {
		$css = self::link_hover_rules();
		if ( '' === $css ) {
			return;
		}
		wp_register_style( self::LINK_HOVER_HANDLE, false, array(), VERSION );
		wp_enqueue_style( self::LINK_HOVER_HANDLE );
		wp_add_inline_style( self::LINK_HOVER_HANDLE, $css );
	}

	/** Formulaire de l'effet de survol des liens, sous les fonds en alternance. */
	private static function render_link_hover(): void {
		$current = self::link_hover();
		echo '<section class="fl-palette-link-hover" aria-labelledby="fl-hover-title"><h2 id="fl-hover-title">' . esc_html__( 'Link hover effect', 'fluffless-suite' ) . '</h2>';
		printf( '<form method="post" action="%1$s"><input type="hidden" name="action" value="%2$s">', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( self::ACTION ) );
		wp_nonce_field( self::ACTION );
		echo '<p id="fl-hover-help" class="description">' . esc_html__( 'Applies to the links of the content and of the Navigation block, on hover and on keyboard focus. Buttons and links around an image are left alone. The same effects exist as block styles for a single paragraph or menu.', 'fluffless-suite' ) . '</p>';
		echo '<p><label for="fl-hover-effect">' . esc_html__( 'Effect', 'fluffless-suite' ) . '</label> <select id="fl-hover-effect" name="fl_link_hover" aria-describedby="fl-hover-help">';
		echo '<option value=""' . selected( $current, '', false ) . '>' . esc_html__( 'None', 'fluffless-suite' ) . '</option>';
		foreach ( LinkHover::effects() as $effect => $label ) {
			echo '<option value="' . esc_attr( $effect ) . '"' . selected( $current, $effect, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><button type="submit" class="button button-secondary" name="fl_link_hover_save" value="1">' . esc_html__( 'Save hover effect', 'fluffless-suite' ) . '</button></p>';
		echo '</form></section>';
	}

	/* ---------- Codes Pantone fournis par la marque ---------- */

	/**
	 * Code Pantone tel qu'écrit dans le guide de marque (« PANTONE 186 C ») ; '' si vide ou douteux.
	 * Aucune conversion : la correspondance Pantone est sous licence, seule la marque la fournit.
	 *
	 * @param mixed $code Saisie brute.
	 */
	public static function sanitize_pantone( $code ): string {
		if ( ! is_string( $code ) ) {
			return '';
		}
		$code = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $code ) ) );
		return 1 === preg_match( '/^[\p{L}\p{N}][\p{L}\p{N} .\-\/]{0,39}$/uD', $code ) ? $code : '';
	}

	/**
	 * Codes Pantone gardés, par couleur #rrggbb.
	 *
	 * @return array<string, string>
	 */
	public static function pantone(): array {
		$stored = get_option( self::OPTION );
		$out    = array();
		foreach ( (array) ( is_array( $stored ) ? ( $stored['pantone'] ?? array() ) : array() ) as $hex => $code ) {
			$code = self::sanitize_pantone( $code );
			if ( 1 === preg_match( '/^#[0-9a-f]{6}$/D', (string) $hex ) && '' !== $code ) {
				$out[ (string) $hex ] = $code;
			}
		}
		return $out;
	}

	/**
	 * Associe à chaque couleur de la palette le code saisi pour son rôle ; un champ vide retire le code.
	 * Les codes survivent au retour au thème : ils décrivent la marque, pas le site.
	 *
	 * @param array<string, string> $palette Palette assainie.
	 * @param array<mixed>          $codes   Codes bruts par rôle.
	 */
	public static function note_pantone( array $palette, array $codes ): void {
		$map = array_diff_key( self::pantone(), array_flip( $palette ) );
		// Deux rôles peuvent partager une couleur : le code saisi pour l'un vaut pour l'autre.
		foreach ( $palette as $role => $hex ) {
			$code = self::sanitize_pantone( $codes[ $role ] ?? '' );
			if ( '' !== $code ) {
				$map[ $hex ] = $code;
			}
		}
		// Les plus récents d'abord gardés.
		$map    = array_slice( $map, -self::MAX_PANTONE, null, true );
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		if ( array() === $map ) {
			unset( $stored['pantone'] );
		} else {
			$stored['pantone'] = $map;
		}
		self::store( $stored );
	}

	/* ---------- Palettes enregistrées ---------- */

	/**
	 * Garde les couleurs entrées dans Suite par-dessus toute variation choisie ensuite dans
	 * l'éditeur de site (qui réécrit la palette) ou par page (PageStyle). Seuls les rôles sont
	 * remplacés ; les autres couleurs de la palette restent. Aucun appel aux styles globaux ici :
	 * ce filtre tourne pendant leur calcul.
	 *
	 * @param mixed $data Styles de la personne (WP_Theme_JSON_Data ; tout autre filtre peut le changer).
	 * @return mixed
	 */
	public static function keep_colors( $data ) {
		$stored  = get_option( self::OPTION );
		$palette = is_array( $stored ) && is_array( $stored['palette'] ?? null ) ? self::sanitize_palette( $stored['palette'] ) : null;
		if ( null === $palette || ! $data instanceof \WP_Theme_JSON_Data ) {
			return $data;
		}
		$current = $data->get_data()['settings']['color']['palette']['theme'] ?? array();
		$entries = self::with_roles( is_array( $current ) ? $current : array(), $palette );
		return $data->update_with(
			array(
				'version'  => 3,
				'settings' => array( 'color' => array( 'palette' => array( 'theme' => $entries ) ) ),
			)
		);
	}

	/**
	 * Remet les couleurs de Suite dans les styles enregistrés quand une variation choisie dans
	 * l'éditeur de site les a remplacées : l'éditeur calcule ses couleurs à partir de ce contenu
	 * brut, sans passer par keep_colors().
	 *
	 * @param int $post_id Identifiant des styles de la personne.
	 */
	public static function restore_colors( int $post_id ): void {
		$stored  = get_option( self::OPTION );
		$palette = is_array( $stored ) && is_array( $stored['palette'] ?? null ) ? self::sanitize_palette( $stored['palette'] ) : null;
		// Lire le billet reçu plutôt que GlobalStyles::user() : celui-ci crée le billet au besoin,
		// ce qui relancerait ce crochet sans fin.
		$post = null === $palette ? null : get_post( $post_id );
		// Même nom que celui que WordPress donne au billet (urlencode, pas rawurlencode).
		if ( ! $post instanceof \WP_Post || 'wp-global-styles-' . urlencode( get_stylesheet() ) !== $post->post_name ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode
			return;
		}
		$data    = json_decode( $post->post_content, true );
		$styles  = array(
			'id'   => $post_id,
			'data' => is_array( $data ) ? $data : array(
				'version'                     => 3,
				'isGlobalStylesUserThemeJSON' => true,
			),
		);
		$current = GlobalStyles::get( $styles['data'], self::PATH );
		$entries = self::with_roles( is_array( $current ) ? $current : array(), $palette );
		if ( $entries !== $current ) {
			remove_action( 'save_post_wp_global_styles', array( self::class, 'restore_colors' ) );
			GlobalStyles::save( $styles['id'], GlobalStyles::put( $styles['data'], self::PATH, $entries ) );
			add_action( 'save_post_wp_global_styles', array( self::class, 'restore_colors' ) );
		}
	}

	/**
	 * Palette où chaque rôle de Suite reprend sa couleur ; les autres couleurs restent, dans l'ordre.
	 *
	 * @param array<int, mixed>     $current Entrées en place.
	 * @param array<string, string> $palette Palette assainie de Suite.
	 * @return list<array<string, mixed>>
	 */
	private static function with_roles( array $current, array $palette ): array {
		$names   = self::role_labels();
		$entries = array();
		$seen    = array();
		foreach ( $current as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['slug'] ?? null ) ) {
				continue;
			}
			if ( isset( $palette[ $entry['slug'] ] ) ) {
				$entry['color']         = $palette[ $entry['slug'] ];
				$seen[ $entry['slug'] ] = true;
			}
			$entries[] = $entry;
		}
		foreach ( array_diff_key( $palette, $seen ) as $slug => $color ) {
			$entries[] = array(
				'slug'  => $slug,
				'name'  => $names[ $slug ] ?? $slug,
				'color' => $color,
			);
		}
		return $entries;
	}

	/**
	 * Palettes enregistrées, par identifiant.
	 *
	 * @return array<string, array{name: string, palette: array<string, string>}>
	 */
	public static function saved(): array {
		$stored = get_option( self::OPTION );
		$out    = array();
		foreach ( (array) ( is_array( $stored ) ? ( $stored['saved'] ?? array() ) : array() ) as $slug => $entry ) {
			$palette = is_array( $entry ) && is_array( $entry['palette'] ?? null ) ? self::sanitize_palette( $entry['palette'] ) : null;
			if ( null !== $palette && is_string( $entry['name'] ?? null ) ) {
				$out[ (string) $slug ] = array(
					'name'    => $entry['name'],
					'palette' => $palette,
				);
			}
		}
		return $out;
	}

	/**
	 * Enregistre une palette lisible sous un nom ; le même nom remplace l'ancienne.
	 *
	 * @param string       $name  Nom donné par la personne.
	 * @param array<mixed> $input Couleurs par rôle.
	 * @return string|null Identifiant, ou null si refusée (nom vide, palette illisible, liste pleine).
	 */
	public static function keep( string $name, array $input ): ?string {
		$name    = mb_substr( trim( sanitize_text_field( $name ) ), 0, 60 );
		$slug    = sanitize_title( $name );
		$palette = self::sanitize_palette( $input );
		$saved   = self::saved();
		if ( '' === $slug || null === $palette || array() !== self::failures( $palette ) || ( ! isset( $saved[ $slug ] ) && count( $saved ) >= self::MAX_SAVED ) ) {
			return null;
		}
		$saved[ $slug ]  = array(
			'name'    => $name,
			'palette' => $palette,
		);
		$stored          = get_option( self::OPTION );
		$stored          = is_array( $stored ) ? $stored : array();
		$stored['saved'] = $saved;
		self::store( $stored );
		return $slug;
	}

	/**
	 * Retire une palette enregistrée.
	 *
	 * @param string $slug Identifiant.
	 */
	public static function forget( string $slug ): bool {
		$saved = self::saved();
		if ( ! isset( $saved[ $slug ] ) ) {
			return false;
		}
		unset( $saved[ $slug ] );
		$stored          = get_option( self::OPTION );
		$stored          = is_array( $stored ) ? $stored : array();
		$stored['saved'] = $saved;
		if ( array() === $saved ) {
			unset( $stored['saved'] );
		}
		self::store( $stored );
		return true;
	}

	/**
	 * Entrées de palette à écrire : les rôles, puis les couleurs hors rôles déjà en place.
	 *
	 * @param array<string, string> $palette Palette assainie.
	 * @return list<array{slug: string, name: string, color: string}>
	 */
	private static function entries( array $palette ): array {
		$names   = self::theme_names() + self::role_labels();
		$entries = array();
		foreach ( $palette as $slug => $color ) {
			$entries[] = array(
				'slug'  => $slug,
				'name'  => $names[ $slug ] ?? $slug,
				'color' => $color,
			);
		}
		return array_merge( $entries, self::extras() );
	}

	/**
	 * Couleurs de la palette en place dont le slug n'est pas un rôle (couleurs de marque d'une
	 * variation ou de l'éditeur de site). Elles sont réécrites telles quelles avec les rôles, sans
	 * entrer dans les paires de contraste. « border » est écarté : c'est l'ancien nom de divider,
	 * déjà relayé par le thème.
	 *
	 * @return list<array{slug: string, name: string, color: string}>
	 */
	public static function extras(): array {
		$known = array_merge( self::ROLES, self::OPTIONAL_ROLES, array( 'border' ) );
		$out   = array();
		foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $color ) {
			if ( count( $out ) >= self::EXTRAS_MAX ) {
				break;
			}
			$slug  = is_array( $color ) ? ( $color['slug'] ?? null ) : null;
			$value = is_array( $color ) ? ( $color['color'] ?? null ) : null;
			if ( ! is_string( $slug ) || ! is_string( $value ) || in_array( $slug, $known, true ) || isset( $out[ $slug ] ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) || 1 !== preg_match( '/^[#a-zA-Z0-9(),.%\s-]{1,64}$/', $value ) ) {
				continue;
			}
			$name         = $color['name'] ?? null;
			$out[ $slug ] = array(
				'slug'  => $slug,
				'name'  => is_string( $name ) && '' !== $name ? $name : $slug,
				'color' => $value,
			);
		}
		return array_values( $out );
	}

	/**
	 * Noms des rôles dans le thème (traduits par le résolveur).
	 *
	 * @return array<string, string>
	 */
	private static function theme_names(): array {
		$names = array();
		foreach ( WP_Theme_JSON_Resolver::get_theme_data()->get_settings()['color']['palette']['theme'] ?? array() as $color ) {
			if ( is_string( $color['slug'] ?? null ) && is_string( $color['name'] ?? null ) ) {
				$names[ $color['slug'] ] = $color['name'];
			}
		}
		return $names;
	}

	/**
	 * Composantes RVB d'un #rrggbb.
	 *
	 * @param string $hex Couleur.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function rgb( string $hex ): array {
		return array( (int) hexdec( substr( $hex, 1, 2 ) ), (int) hexdec( substr( $hex, 3, 2 ) ), (int) hexdec( substr( $hex, 5, 2 ) ) );
	}

	/**
	 * Luminance relative WCAG.
	 *
	 * @param string $hex Couleur.
	 */
	private static function luminance( string $hex ): float {
		$c = array_map(
			static function ( int $v ): float {
				$v /= 255;
				return $v <= 0.04045 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
			},
			self::rgb( $hex )
		);
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	/**
	 * Ratio de contraste WCAG, de 1 à 21.
	 *
	 * @param string $a Couleur.
	 * @param string $b Couleur.
	 */
	public static function ratio( string $a, string $b ): float {
		$x = self::luminance( $a );
		$y = self::luminance( $b );
		return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	}

	/** Palettes enregistrées : appliquer ou supprimer, un formulaire par palette. */
	private static function render_saved(): void {
		$saved = self::saved();
		if ( array() === $saved ) {
			return;
		}
		$nonce = wp_create_nonce( self::ACTION );
		echo '<section class="fl-palette-saved" aria-labelledby="fl-saved-title"><h2 id="fl-saved-title">' . esc_html__( 'Saved palettes', 'fluffless-suite' ) . '</h2><ul>';
		foreach ( $saved as $slug => $entry ) {
			echo '<li><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="fl_saved" value="' . esc_attr( $slug ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
			echo '<span class="fl-palette-saved__swatches" aria-hidden="true">';
			foreach ( $entry['palette'] as $color ) {
				echo '<span class="fl-swatch" style="background:' . esc_attr( $color ) . '"></span>';
			}
			echo '</span><strong>' . esc_html( $entry['name'] ) . '</strong> ';
			/* translators: %s: palette name. */
			echo '<button type="submit" class="button" aria-label="' . esc_attr( sprintf( __( 'Apply palette %s', 'fluffless-suite' ), $entry['name'] ) ) . '">' . esc_html__( 'Apply', 'fluffless-suite' ) . '</button> ';
			/* translators: %s: palette name. */
			echo '<button type="submit" name="fl_forget" value="1" class="button-link button-link-delete" aria-label="' . esc_attr( sprintf( __( 'Delete palette %s', 'fluffless-suite' ), $entry['name'] ) ) . '">' . esc_html__( 'Delete', 'fluffless-suite' ) . '</button>';
			echo '</form></li>';
		}
		echo '</ul></section>';
	}

	/* ---------- Fiche de marque ---------- */

	/**
	 * Équivalent CMJN naïf, en pourcentages : approximation écran, sans profil ICC d'imprimeur.
	 *
	 * @param string $hex Couleur #rrggbb.
	 * @return array{c: int, m: int, y: int, k: int}
	 */
	public static function cmyk( string $hex ): array {
		list( $r, $g, $b ) = array_map( static fn( int $v ): float => $v / 255, self::rgb( $hex ) );
		$k                 = 1 - max( $r, $g, $b );
		if ( $k >= 1 ) {
			return array(
				'c' => 0,
				'm' => 0,
				'y' => 0,
				'k' => 100,
			);
		}
		$part = static fn( float $v ): int => (int) round( ( 1 - $v - $k ) / ( 1 - $k ) * 100 );
		return array(
			'c' => $part( $r ),
			'm' => $part( $g ),
			'y' => $part( $b ),
			'k' => (int) round( $k * 100 ),
		);
	}

	/**
	 * Variables CSS des préréglages de couleur, à coller dans une autre feuille.
	 *
	 * @param array<string, string> $palette Palette assainie.
	 */
	public static function css_vars( array $palette ): string {
		$pantone = self::pantone();
		$lines   = array();
		foreach ( $palette as $slug => $color ) {
			$lines[] = "\t--wp--preset--color--{$slug}: {$color};" . ( isset( $pantone[ $color ] ) ? " /* {$pantone[ $color ]} */" : '' );
		}
		return ":root {\n" . implode( "\n", $lines ) . "\n}";
	}

	/**
	 * Variation de style theme.json (styles/<nom>.json) portant la palette.
	 *
	 * @param array<string, string> $palette Palette assainie.
	 */
	public static function variation_json( array $palette ): string {
		$entries = self::entries( $palette );
		return (string) wp_json_encode(
			array(
				'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
				'version'  => 3,
				'title'    => __( 'My palette', 'fluffless-suite' ),
				'settings' => array( 'color' => array( 'palette' => $entries ) ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Fiche de marque imprimable de la palette en place.
	 *
	 * @param array<string, string> $palette Palette en place.
	 */
	private static function render_sheet( array $palette ): void {
		$labels  = self::role_labels();
		$pantone = array_intersect_key( self::pantone(), array_flip( $palette ) );
		echo '<section class="fl-brand-sheet" aria-labelledby="fl-sheet-title"><h2 id="fl-sheet-title">' . esc_html__( 'Brand sheet', 'fluffless-suite' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The colours currently used on the site. CMYK values are a screen approximation: ask your printer to convert them with their colour profile.', 'fluffless-suite' ) . '</p>';
		if ( array() !== $pantone ) {
			echo '<p class="description">' . esc_html__( 'Pantone codes come from your brand guide, as you entered them. Fluffless does not convert colours to Pantone.', 'fluffless-suite' ) . '</p>';
		}
		echo '<div class="fl-table-scroll"><table class="widefat striped"><thead><tr>';
		$heads = array( __( 'Role', 'fluffless-suite' ), __( 'HEX', 'fluffless-suite' ), __( 'RGB', 'fluffless-suite' ), __( 'CMYK (approximation)', 'fluffless-suite' ) );
		if ( array() !== $pantone ) {
			$heads[] = __( 'Pantone (brand guide)', 'fluffless-suite' );
		}
		foreach ( $heads as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $palette as $role => $color ) {
			$cmyk = self::cmyk( $color );
			printf(
				'<tr><th scope="row"><span class="fl-swatch" style="background:%1$s" aria-hidden="true"></span>%2$s</th><td><code>%1$s</code></td><td>%3$s</td><td>%4$s</td>%5$s</tr>',
				esc_attr( $color ),
				esc_html( $labels[ $role ] ?? $role ),
				esc_html( implode( ', ', self::rgb( $color ) ) ),
				esc_html( sprintf( '%d, %d, %d, %d', $cmyk['c'], $cmyk['m'], $cmyk['y'], $cmyk['k'] ) ),
				array() === $pantone ? '' : '<td>' . esc_html( $pantone[ $color ] ?? '' ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé ici.
			);
		}
		echo '</tbody></table></div>';

		echo '<h3>' . esc_html__( 'Contrast', 'fluffless-suite' ) . '</h3><div class="fl-table-scroll"><table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Text', 'fluffless-suite' ), __( 'Background', 'fluffless-suite' ), __( 'Ratio', 'fluffless-suite' ), __( 'Result', 'fluffless-suite' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$labels = self::pair_labels();
		$button = self::button();
		$paired = self::with_border( $palette, $button );
		foreach ( self::checks( $button ) as $check ) {
			if ( ! isset( $paired[ $check[0] ], $paired[ $check[1] ] ) ) {
				continue;
			}
			$ratio = self::ratio( $paired[ $check[0] ], $paired[ $check[1] ] );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( $labels[ $check[0] ] ),
				esc_html( $labels[ $check[1] ] ),
				esc_html( number_format_i18n( floor( $ratio * 10 ) / 10, 1 ) . ':1' ),
				Screen::pill( $ratio >= $check[2] ? 'good' : 'bad', $ratio >= $check[2] ? __( 'Readable', 'fluffless-suite' ) : __( 'Too low', 'fluffless-suite' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::pill().
			);
		}
		echo '</tbody></table></div>';

		printf(
			'<details><summary>%1$s</summary><textarea readonly rows="12" aria-label="%1$s">%2$s</textarea></details>',
			esc_html__( 'CSS variables', 'fluffless-suite' ),
			esc_textarea( self::css_vars( $palette ) )
		);
		printf(
			'<details><summary>%1$s</summary><p class="description">%2$s</p><textarea readonly rows="16" aria-label="%1$s">%3$s</textarea></details>',
			esc_html__( 'Style variation (JSON)', 'fluffless-suite' ),
			esc_html__( 'Save this file in the styles folder of a child theme to keep this palette as a variation.', 'fluffless-suite' ),
			esc_textarea( self::variation_json( $palette ) )
		);
		echo '<p><button type="button" class="button" data-print hidden>' . esc_html__( 'Print the sheet', 'fluffless-suite' ) . '</button></p></section>';
	}

	/* ---------- Écran ---------- */

	/**
	 * Noms des rôles en langage clair.
	 *
	 * @return array<string, string>
	 */
	public static function role_labels(): array {
		return array(
			'base'      => __( 'Background', 'fluffless-suite' ),
			'surface'   => __( 'Surface', 'fluffless-suite' ),
			'contrast'  => __( 'Text', 'fluffless-suite' ),
			'muted'     => __( 'Secondary text', 'fluffless-suite' ),
			'divider'   => __( 'Divider', 'fluffless-suite' ),
			'accent'    => __( 'Accent', 'fluffless-suite' ),
			'on-accent' => __( 'Text on accent', 'fluffless-suite' ),
			'accent-2'  => __( 'Second accent', 'fluffless-suite' ),
			'alert'     => __( 'Alert', 'fluffless-suite' ),
			'button-bg' => __( 'Button background', 'fluffless-suite' ),
		);
	}

	/** Formulaire : appliquer ou revenir aux couleurs du thème. */
	public static function handle_save(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change the colours of this site.', 'fluffless-suite' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$args = array();
		$slug = isset( $_POST['fl_saved'] ) ? sanitize_title( wp_unslash( $_POST['fl_saved'] ) ) : '';
		if ( isset( $_POST['fl_alternate_save'] ) ) {
			$raw = isset( $_POST['fl_alternate'] ) && is_array( $_POST['fl_alternate'] ) ? map_deep( wp_unslash( $_POST['fl_alternate'] ), 'sanitize_text_field' ) : array();
			self::save_alternation( (array) $raw );
			$args['fl_palette'] = 'alternate';
		} elseif ( isset( $_POST['fl_scheme_save'] ) ) {
			Scheme::save( isset( $_POST['fl_scheme'] ) && '1' === $_POST['fl_scheme'] );
			$args['fl_palette'] = 'scheme';
		} elseif ( isset( $_POST['fl_link_hover_save'] ) ) {
			self::save_link_hover( isset( $_POST['fl_link_hover'] ) && is_string( $_POST['fl_link_hover'] ) ? sanitize_key( wp_unslash( $_POST['fl_link_hover'] ) ) : '' );
			$args['fl_palette'] = 'link-hover';
		} elseif ( isset( $_POST['fl_reset'] ) ) {
			$args['fl_palette'] = self::reset() ? 'reset' : 'error';
		} elseif ( '' !== $slug && isset( $_POST['fl_forget'] ) ) {
			$args['fl_palette'] = self::forget( $slug ) ? 'forgotten' : 'error';
		} elseif ( '' !== $slug ) {
			$saved              = self::saved();
			$args['fl_palette'] = isset( $saved[ $slug ] ) && self::apply( $saved[ $slug ]['palette'] ) ? 'saved' : 'error';
		} elseif ( isset( $_POST['fl_keep'] ) ) {
			$raw    = isset( $_POST['colors'] ) && is_array( $_POST['colors'] ) ? map_deep( wp_unslash( $_POST['colors'] ), 'sanitize_text_field' ) : array();
			$name   = isset( $_POST['fl_name'] ) ? sanitize_text_field( wp_unslash( $_POST['fl_name'] ) ) : '';
			$colors = self::sanitize_palette( (array) $raw );
			if ( null === $colors ) {
				$args['fl_palette'] = 'invalid';
			} elseif ( array() !== self::failures( $colors ) ) {
				$args['fl_palette'] = 'kept-contrast';
				$args['fl_pairs']   = implode( ',', self::failures( $colors ) );
			} elseif ( '' === sanitize_title( $name ) ) {
				$args['fl_palette'] = 'no-name';
			} else {
				$args['fl_palette'] = null !== self::keep( $name, $colors ) ? 'kept' : 'not-kept';
				if ( 'kept' === $args['fl_palette'] ) {
					self::note_pantone( $colors, self::posted_pantone() );
				}
			}
		} else {
			$raw    = isset( $_POST['colors'] ) && is_array( $_POST['colors'] ) ? map_deep( wp_unslash( $_POST['colors'] ), 'sanitize_text_field' ) : array();
			$colors = self::sanitize_palette( (array) $raw );
			if ( null === $colors ) {
				$args['fl_palette'] = 'invalid';
			} elseif ( array() !== self::failures( $colors ) ) {
				$args['fl_palette'] = 'contrast';
				$args['fl_pairs']   = implode( ',', self::failures( $colors ) );
			} else {
				$args['fl_palette'] = self::apply( $colors ) ? 'saved' : 'error';
				if ( 'saved' === $args['fl_palette'] ) {
					self::note_pantone( $colors, self::posted_pantone() );
				}
			}
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Codes Pantone envoyés par le formulaire, par rôle (nonce vérifié par handle_save()).
	 *
	 * @return array<mixed>
	 */
	private static function posted_pantone(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans handle_save().
		return isset( $_POST['pantone'] ) && is_array( $_POST['pantone'] ) ? (array) map_deep( wp_unslash( $_POST['pantone'] ), 'sanitize_text_field' ) : array();
	}

	/** Écran : couleurs et générateur à gauche, aperçu à droite. */
	public static function render(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		Screen::header( __( 'Palette', 'fluffless-suite' ), __( 'Choose the colours of the whole site. Every page and pattern picks them up, and text always stays readable.', 'fluffless-suite' ) );
		if ( ! Patterns::applies() ) {
			echo Screen::note( 'bad', __( 'The palette works with the Fluffless theme only. Activate it to use this screen.', 'fluffless-suite' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			return;
		}
		$labels = self::role_labels();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- simple affichage du résultat.
		$done  = isset( $_GET['fl_palette'] ) ? sanitize_key( wp_unslash( $_GET['fl_palette'] ) ) : '';
		$pairs = isset( $_GET['fl_pairs'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['fl_pairs'] ) ) ) : array();
		// phpcs:enable
		$names = array();
		$named = self::pair_labels();
		foreach ( self::checks() as $pair ) {
			if ( in_array( $pair[0] . '/' . $pair[1], $pairs, true ) ) {
				/* translators: 1: text colour role, 2: background colour role. Example: "Accent on Background". */
				$names[] = sprintf( __( '%1$s on %2$s', 'fluffless-suite' ), $named[ $pair[0] ], $named[ $pair[1] ] );
			}
		}
		$notes = array(
			'saved'         => array( 'good', __( 'Applied. Every page now uses this palette.', 'fluffless-suite' ) ),
			'reset'         => array( 'good', __( 'Back to the theme colours.', 'fluffless-suite' ) ),
			'kept'          => array( 'good', __( 'Palette saved. Find it below, under Saved palettes.', 'fluffless-suite' ) ),
			'no-name'       => array( 'bad', __( 'Not saved. Name the palette first.', 'fluffless-suite' ) ),
			/* translators: %s: list of colour pairs, like "Accent on Background". */
			'kept-contrast' => array( 'bad', sprintf( __( 'Not saved, because some colours would be hard to read: %s. Change them so each pair reaches its contrast ratio: 4.5:1 for text, 3:1 for buttons.', 'fluffless-suite' ), implode( ', ', $names ) ) ),
			/* translators: %d: maximum number of saved palettes. */
			'not-kept'      => array( 'bad', sprintf( __( 'Not saved: you already keep %d palettes. Delete one, or reuse a name to replace its palette.', 'fluffless-suite' ), self::MAX_SAVED ) ),
			'forgotten'     => array( 'good', __( 'Palette deleted.', 'fluffless-suite' ) ),
			'invalid'       => array( 'bad', __( 'Not applied: each colour must be a six-digit hex code, like #1f4e3d.', 'fluffless-suite' ) ),
			/* translators: %s: list of colour pairs, like "Accent on Background". */
			'contrast'      => array( 'bad', sprintf( __( 'Not applied, because some colours would be hard to read: %s. Change them so each pair reaches its contrast ratio: 4.5:1 for text, 3:1 for buttons.', 'fluffless-suite' ), implode( ', ', $names ) ) ),
			'error'         => array( 'bad', __( 'The palette could not be saved and your site is unchanged. Check that the active theme is a block theme, then try again.', 'fluffless-suite' ) ),
			'alternate'     => array( 'good', __( 'Alternating backgrounds saved.', 'fluffless-suite' ) ),
			'link-hover'    => array( 'good', __( 'Link hover effect saved.', 'fluffless-suite' ) ),
			'scheme'        => array( 'good', __( 'Light or dark setting saved.', 'fluffless-suite' ) ),
		);
		if ( isset( $notes[ $done ] ) ) {
			echo Screen::note( $notes[ $done ][0], $notes[ $done ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		echo '<p>' . Screen::pill( self::is_custom() ? 'good' : 'idle', self::is_custom() ? __( 'Generated palette in use', 'fluffless-suite' ) : __( 'Theme colours in use', 'fluffless-suite' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::pill().

		$palette = self::current();
		$i18n    = array(
			'roles'  => self::pair_labels(),
			'button' => self::button(),
			/* translators: %d: energy index from 0 to 100. */
			'score'  => __( 'OLED energy index: %d/100', 'fluffless-suite' ),
			'ok'     => __( 'Every colour pair reaches its contrast ratio: 4.5:1 for text, 3:1 for buttons.', 'fluffless-suite' ),
			/* translators: %s: list of colour pairs, like "Accent on Background". */
			'bad'    => __( 'Hard to read: %s. Change these colours, or untick Keep so the generator can adjust them.', 'fluffless-suite' ),
			/* translators: 1: text colour role, 2: background colour role. Example: "Accent on Background". */
			'pair'   => __( '%1$s on %2$s', 'fluffless-suite' ),
		);
		printf(
			'<form id="fl-palette-form" method="post" action="%1$s" data-i18n="%2$s"><input type="hidden" name="action" value="%3$s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( (string) wp_json_encode( $i18n ) ),
			esc_attr( self::ACTION )
		);
		wp_nonce_field( self::ACTION );
		echo '<div class="fl-shape"><div>';

		self::render_generator();

		$pantone = self::pantone();
		echo '<fieldset aria-describedby="fl-palette-help"><legend>' . esc_html__( 'Colours', 'fluffless-suite' ) . '</legend><div class="fl-palette-roles">';
		foreach ( $labels as $role => $label ) {
			// Un rôle facultatif n'a de champ que s'il est déclaré : sinon il resterait posé à noir.
			if ( in_array( $role, self::OPTIONAL_ROLES, true ) && ! isset( $palette[ $role ] ) ) {
				continue;
			}
			$color = $palette[ $role ] ?? '#000000';
			printf(
				'<div class="fl-palette-role"><label for="fl-c-%1$s">%2$s</label><input type="color" id="fl-c-%1$s" name="colors[%1$s]" value="%3$s"><label class="fl-palette-lock" hidden><input type="checkbox" data-lock="%1$s"> %4$s</label><input type="text" class="fl-palette-pantone" name="pantone[%1$s]" value="%5$s" maxlength="40" autocomplete="off" aria-label="%6$s" placeholder="%7$s"></div>',
				esc_attr( $role ),
				esc_html( $label ),
				esc_attr( $color ),
				esc_html__( 'Keep', 'fluffless-suite' ),
				esc_attr( $pantone[ $color ] ?? '' ),
				/* translators: %s: colour role, like "Accent". */
				esc_attr( sprintf( __( 'Pantone code for %s (optional)', 'fluffless-suite' ), $label ) ),
				esc_attr( 'Pantone' ) // Nom de marque, même dans toutes les langues ; « facultatif » est dans aria-label.
			);
		}
		echo '</div><p class="description" id="fl-palette-help">' . esc_html__( 'Enter your brand colours, then tick Keep so the generator builds the rest around them. If your brand guide gives a Pantone code, copy it next to that colour and it will appear on the brand sheet.', 'fluffless-suite' ) . '</p></fieldset>';

		printf(
			'<p id="fl-palette-report" class="fl-palette-report" aria-live="polite">%s</p>',
			esc_html(
				/* translators: %d: energy index from 0 to 100. */
				sprintf( __( 'OLED energy index: %d/100', 'fluffless-suite' ), self::score( $palette ) )
			)
		);
		echo '<p class="description">' . esc_html__( 'The index estimates the share of screen power saved compared with a pure white page, on OLED screens, from each colour and the area it usually covers. On LCD screens, colour barely changes power use.', 'fluffless-suite' ) . '</p>';

		echo '<div class="fl-savebar">';
		submit_button( __( 'Apply to my site', 'fluffless-suite' ), 'primary', 'submit', false );
		if ( self::is_custom() ) {
			echo '<button type="submit" name="fl_reset" value="1" class="button">' . esc_html__( 'Back to theme colours', 'fluffless-suite' ) . '</button>';
		}
		echo '</div>';
		printf(
			'<p class="fl-palette-keep"><label for="fl-palette-name">%1$s</label> <input type="text" id="fl-palette-name" name="fl_name" maxlength="60" class="regular-text"> <button type="submit" name="fl_keep" value="1" class="button">%2$s</button></p>',
			esc_html__( 'Palette name', 'fluffless-suite' ),
			esc_html__( 'Save palette', 'fluffless-suite' )
		);
		echo '</div>';

		$vars = '';
		foreach ( $palette as $role => $color ) {
			$vars .= '--c-' . $role . ':' . $color . ';';
		}
		echo '<figure class="fl-preview fl-palette-preview" id="fl-palette-preview" style="' . esc_attr( $vars ) . '"><div aria-hidden="true">';
		echo '<div class="fl-pal__band"><strong>' . esc_html__( 'A clear title', 'fluffless-suite' ) . '</strong><p>' . esc_html__( 'Secondary text that explains the offer.', 'fluffless-suite' ) . '</p><span class="fl-pal__btn">' . esc_html__( 'Get in touch', 'fluffless-suite' ) . '</span></div>';
		echo '<div class="fl-pal__card"><strong>' . esc_html__( 'A card', 'fluffless-suite' ) . '</strong><span class="fl-pal__tag">' . esc_html__( 'Second accent', 'fluffless-suite' ) . '</span></div>';
		echo '<p class="fl-pal__alert">' . esc_html__( 'This field is required.', 'fluffless-suite' ) . '</p>';
		echo '</div><figcaption>' . esc_html__( 'Preview. Your pages use the same colours once applied.', 'fluffless-suite' ) . '</figcaption></figure>';
		echo '</div></form>';
		self::render_scheme( $palette );
		self::render_alternation();
		self::render_link_hover();
		self::render_saved();
		self::render_sheet( $palette );
		echo '</div>';
	}

	/**
	 * Réglages du générateur : [libellé, [valeur => libellé]], les valeurs de palette-core.js.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function generator_options(): array {
		return array(
			'mood'    => array(
				__( 'Mood', 'fluffless-suite' ),
				array(
					'variee'     => __( 'Varied', 'fluffless-suite' ),
					'terreuse'   => __( 'Earthy', 'fluffless-suite' ),
					'foret'      => __( 'Forest', 'fluffless-suite' ),
					'crepuscule' => __( 'Dusk', 'fluffless-suite' ),
					'prune'      => __( 'Plum', 'fluffless-suite' ),
					'lagon'      => __( 'Lagoon', 'fluffless-suite' ),
					'bijou'      => __( 'Jewel', 'fluffless-suite' ),
					'neutre'     => __( 'Neutral', 'fluffless-suite' ),
				),
			),
			'strict'  => array(
				__( 'Energy priority', 'fluffless-suite' ),
				array(
					'max'       => __( 'Maximum', 'fluffless-suite' ),
					'equilibre' => __( 'Balanced', 'fluffless-suite' ),
					'souple'    => __( 'Relaxed', 'fluffless-suite' ),
				),
			),
			'harmony' => array(
				__( 'Accent harmony', 'fluffless-suite' ),
				array(
					'libre'          => __( 'Free', 'fluffless-suite' ),
					'mono'           => __( 'Monochrome', 'fluffless-suite' ),
					'analogue'       => __( 'Analogous', 'fluffless-suite' ),
					'complementaire' => __( 'Complementary', 'fluffless-suite' ),
					'triade'         => __( 'Triad', 'fluffless-suite' ),
				),
			),
		);
	}

	/** Réglages du générateur, montrés par le JS (inutiles sans lui). */
	private static function render_generator(): void {
		$selects = self::generator_options();
		echo '<fieldset id="fl-palette-generator" hidden><legend>' . esc_html__( 'Generator', 'fluffless-suite' ) . '</legend><div class="fl-palette-gen">';
		foreach ( $selects as $name => $select ) {
			printf( '<label>%1$s <select name="%2$s" data-gen="%2$s">', esc_html( $select[0] ), esc_attr( $name ) );
			foreach ( $select[1] as $value => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( 'strict' === $name && 'equilibre' === $value, true, false ), esc_html( $label ) );
			}
			echo '</select></label>';
		}
		echo '<button type="button" class="button" data-gen="run">' . esc_html__( 'Generate', 'fluffless-suite' ) . '</button></div></fieldset>';
	}
}
