<?php
/**
 * Module Forme et densité : arrondi, coin coupé, bord oblique et espacements du site entier,
 * déverrouillage des sections et mode Studio par personne.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\GlobalStyles;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use WP_REST_Request;
use WP_REST_Response;
use WP_Theme_JSON_Resolver;
use WP_User;
use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Forme et densité s'écrivent dans les styles globaux de l'utilisateur (wp_global_styles) :
 * les patterns lisent déjà ces jetons, ils suivent sans modification. Avant la première écriture,
 * la valeur d'origine de chaque clé touchée est gardée (option fluffless_shape) ; « Revenir aux
 * valeurs du thème » la remet, ou retire la clé si elle n'existait pas.
 *
 * Clés touchées :
 * - settings.custom.radius.soft           : arrondi global, 0 à 32 px ;
 * - settings.custom.cut, settings.custom.slant : coin coupé et bord oblique (préréglage Oblique) ;
 * - styles.elements.button.border.radius  : boutons en pilule (préréglage Pilule) ;
 * - settings.spacing.spacingSizes         : crans d'espacement multipliés par la densité.
 *
 * Mode Studio (métadonnée fluffless_studio, posée par une personne qui peut edit_theme_options) :
 * couleurs, tailles et espacements libres dans l'éditeur, pour cette personne seulement, par
 * block_editor_settings_all. Le site public ne reçoit que le CSS du résultat.
 *
 * @phpstan-type State array{preset: string, radius: int, density: string}
 */
final class Shape implements Module {

	public const PAGE        = 'fluffless-shape';
	public const ACTION      = 'fluffless_shape';
	public const OPTION      = Lifecycle::OPTION_SHAPE;
	public const STUDIO_META = Lifecycle::USER_META_STUDIO;
	public const RADIUS_MAX  = 32;

	/** Arrondi du thème (0.5rem), montré tant que rien n'est enregistré. */
	private const THEME_RADIUS = 8;

	/**
	 * Préréglages : arrondi en px, coin coupé, bord oblique, rayon des boutons (null : valeur d'origine).
	 * Diagonale ajoute ses deux jetons, grand coin et petit coin : haut-gauche et bas-droit grands,
	 * haut-droit et bas-gauche petits. Ils sont écrits pour que la forme se voie aussi sur une
	 * variation qui met les rayons à zéro.
	 * Valeurs d'aperçu (preview_*) en px pour l'écran d'administration.
	 */
	public const PRESETS = array(
		'square'   => array(
			'radius' => 0,
			'cut'    => null,
			'slant'  => null,
			'button' => null,
		),
		'rounded'  => array(
			'radius' => 12,
			'cut'    => null,
			'slant'  => null,
			'button' => null,
		),
		'pill'     => array(
			'radius' => 20,
			'cut'    => null,
			'slant'  => null,
			'button' => 'var:custom|radius|round',
		),
		'oblique'  => array(
			'radius' => 0,
			'cut'    => '1.25rem',
			'slant'  => '6vw',
			'button' => null,
		),
		'diagonal' => array(
			'radius' => 8,
			'cut'    => null,
			'slant'  => null,
			'button' => self::DIAGONAL,
			'large'  => '1.5rem',
			'small'  => '0.25rem',
		),
	);

	/** Coins en diagonale, tirés des jetons grand et petit coin du thème. */
	public const DIAGONAL = array(
		'topLeft'     => 'var:custom|radius|large',
		'topRight'    => 'var:custom|radius|small',
		'bottomRight' => 'var:custom|radius|large',
		'bottomLeft'  => 'var:custom|radius|small',
	);

	/** Facteur appliqué aux crans d'espacement. */
	public const DENSITY = array(
		'compact' => 0.8,
		'normal'  => 1.0,
		'airy'    => 1.25,
	);

	/** Chemins des styles globaux que le module peut écrire. */
	private const PATHS = array(
		'settings.custom.radius.soft',
		'settings.custom.radius.large',
		'settings.custom.radius.small',
		'settings.custom.cut',
		'settings.custom.slant',
		'styles.elements.button.border.radius',
		'settings.spacing.spacingSizes',
	);

	/** Identifiant stable. */
	public static function id(): string {
		return 'shape';
	}

	/** Nom affiché. */
	public static function label(): string {
		return __( 'Shape and density', 'fluffless-suite' );
	}

	/** Ce que le module change. */
	public static function description(): string {
		return __( 'Corner rounding, slanted edges and spacing for the whole site, and what editors may change.', 'fluffless-suite' );
	}

	/** Actif sur une installation neuve. */
	public static function enabled_by_default(): bool {
		return true;
	}

	/**
	 * Déverrouillage désactivé par défaut : le mode Simple garde ses sections contentOnly.
	 *
	 * @return array{unlock_sections: bool, unlock_roles: list<string>}
	 */
	public static function defaults(): array {
		return array(
			'unlock_sections' => false,
			'unlock_roles'    => array( 'administrator', 'editor' ),
		);
	}

	/**
	 * Rôles hors liste écartés.
	 *
	 * @param array<mixed> $input Section brute.
	 * @return array{unlock_sections: bool, unlock_roles: list<string>}
	 */
	public static function sanitize( array $input ): array {
		$roles = array_keys( self::role_names() );
		$given = is_array( $input['unlock_roles'] ?? null ) ? $input['unlock_roles'] : self::defaults()['unlock_roles'];
		return array(
			'unlock_sections' => in_array( $input['unlock_sections'] ?? false, array( true, 1, '1', 'true', 'on' ), true ),
			'unlock_roles'    => array_values( array_intersect( array_filter( $given, 'is_string' ), $roles ) ),
		);
	}

	/**
	 * Réglages visibles dans Fluffless → Modules.
	 *
	 * @return array<string, array{type: 'bool'|'multi', label: string, help?: string, options?: array<string, string>}>
	 */
	public static function fields(): array {
		return array(
			'unlock_sections' => array(
				'type'  => 'bool',
				'label' => __( 'Let people change section layouts', 'fluffless-suite' ),
				'help'  => __( 'Off: sections inserted from the patterns only let people change text and images. On: the chosen roles can also move, add or remove blocks inside them.', 'fluffless-suite' ),
			),
			'unlock_roles'    => array(
				'type'    => 'multi',
				'label'   => __( 'Roles that can change layouts', 'fluffless-suite' ),
				'options' => self::role_names(),
			),
		);
	}

	/**
	 * Rôles du site, nom traduit.
	 *
	 * @return array<string, string>
	 */
	private static function role_names(): array {
		$names = array();
		foreach ( wp_roles()->get_names() as $role => $name ) {
			$names[ (string) $role ] = translate_user_role( (string) $name );
		}
		return $names;
	}

	/** Crochets. */
	public function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'unlock_patterns_response' ), 10, 3 );
		add_filter( 'block_editor_settings_all', array( self::class, 'studio_settings' ), 20 );
		add_action( 'personal_options', array( self::class, 'render_studio_field' ) );
		add_action( 'personal_options_update', array( self::class, 'save_studio_field' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_studio_field' ) );
	}

	/** Écran Fluffless → Forme et densité. */
	public static function add_page(): void {
		Menu::add_tab( 'appearance', self::PAGE, static fn(): string => __( 'Shape and density', 'fluffless-suite' ), array( self::class, 'render' ), 30 );
	}

	/**
	 * Aperçu en direct : 1,3 ko, seulement sur cet écran.
	 *
	 * @param mixed $hook Suffixe de l'écran.
	 */
	public static function enqueue( $hook ): void {
		if ( is_string( $hook ) && str_contains( $hook, self::PAGE ) ) {
			wp_enqueue_script(
				'fluffless-shape',
				plugins_url( 'assets/admin/shape.js', FILE ),
				array(),
				VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
	}

	/* ---------- Styles globaux ---------- */

	/**
	 * Valeurs bornées : arrondi 0 à 32, préréglage et densité connus.
	 *
	 * @param array<mixed> $input État brut.
	 * @return State
	 */
	public static function sanitize_state( array $input ): array {
		$preset = is_string( $input['preset'] ?? null ) && isset( self::PRESETS[ $input['preset'] ] ) ? $input['preset'] : 'custom';
		$radius = is_numeric( $input['radius'] ?? null ) ? (int) $input['radius'] : self::THEME_RADIUS;
		$radius = 'custom' === $preset ? max( 0, min( self::RADIUS_MAX, $radius ) ) : self::PRESETS[ $preset ]['radius'];
		return array(
			'preset'  => $preset,
			'radius'  => $radius,
			'density' => is_string( $input['density'] ?? null ) && isset( self::DENSITY[ $input['density'] ] ) ? $input['density'] : 'normal',
		);
	}

	/**
	 * État enregistré, ou celui du thème.
	 *
	 * @return State
	 */
	public static function current_state(): array {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) && is_array( $stored['state'] ?? null )
			? self::sanitize_state( $stored['state'] )
			: array(
				'preset'  => 'custom',
				'radius'  => self::THEME_RADIUS,
				'density' => 'normal',
			);
	}

	/** Une forme personnalisée est-elle en place ? */
	public static function is_custom(): bool {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) && is_array( $stored['state'] ?? null );
	}

	/**
	 * Styles globaux de l'utilisateur pour le thème actif (créés au besoin).
	 *
	 * @return array{id: int, data: array<string, mixed>}|null
	 */
	public static function user_styles(): ?array {
		return GlobalStyles::user();
	}

	/**
	 * Écrit la forme et la densité. Renvoie faux si les styles globaux sont indisponibles.
	 *
	 * @param array<mixed> $input État brut (formulaire ou code).
	 */
	public static function apply( array $input ): bool {
		$state  = self::sanitize_state( $input );
		$styles = self::user_styles();
		if ( null === $styles ) {
			return false;
		}
		$data   = $styles['data'];
		$backup = self::backup();
		foreach ( self::PATHS as $path ) {
			if ( ! array_key_exists( $path, $backup ) ) {
				$backup[ $path ] = self::get_path( $data, $path );
			}
		}
		foreach ( self::values_for( $state, $backup ) as $path => $value ) {
			$data = GlobalStyles::put( $data, $path, $value ?? $backup[ $path ] );
		}
		if ( ! GlobalStyles::save( $styles['id'], $data ) ) {
			return false;
		}
		update_option(
			self::OPTION,
			array(
				'state'  => $state,
				'backup' => $backup,
			),
			false
		);
		return true;
	}

	/** Revenir aux valeurs du thème : chaque clé touchée reprend sa valeur d'avant. */
	public static function reset(): bool {
		$backup = self::backup();
		$styles = self::user_styles();
		if ( null === $styles ) {
			return false;
		}
		$data = $styles['data'];
		foreach ( $backup as $path => $value ) {
			if ( in_array( $path, self::PATHS, true ) ) {
				$data = GlobalStyles::put( $data, $path, $value );
			}
		}
		if ( array() !== $backup && ! GlobalStyles::save( $styles['id'], $data ) ) {
			return false;
		}
		delete_option( self::OPTION );
		return true;
	}

	/**
	 * Valeurs d'origine gardées à la première écriture (null : clé absente).
	 *
	 * @return array<string, mixed>
	 */
	private static function backup(): array {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) && is_array( $stored['backup'] ?? null ) ? $stored['backup'] : array();
	}

	/**
	 * Valeur de chaque chemin pour un état ; null : remettre la valeur d'origine.
	 *
	 * @param array                $state  État assaini.
	 * @param array<string, mixed> $backup Valeurs d'origine.
	 * @phpstan-param State $state
	 * @return array<string, mixed>
	 */
	public static function values_for( array $state, array $backup ): array {
		$preset = self::PRESETS[ $state['preset'] ] ?? null;
		return array(
			'settings.custom.radius.soft'          => $state['radius'] . 'px',
			'settings.custom.radius.large'         => $preset['large'] ?? null,
			'settings.custom.radius.small'         => $preset['small'] ?? null,
			'settings.custom.cut'                  => $preset['cut'] ?? null,
			'settings.custom.slant'                => $preset['slant'] ?? null,
			'styles.elements.button.border.radius' => $preset['button'] ?? null,
			'settings.spacing.spacingSizes'        => self::spacing_for( $state['density'], $backup['settings.spacing.spacingSizes'] ?? null ),
		);
	}

	/**
	 * Crans d'espacement multipliés par le facteur de densité ; null pour Normal.
	 * La base est la valeur d'origine de l'utilisateur (variation de style), sinon celle du thème.
	 *
	 * @param string $density Compact, normal ou airy.
	 * @param mixed  $base    Crans d'origine de l'utilisateur.
	 * @return list<array{slug: string, name: string, size: string}>|null
	 */
	public static function spacing_for( string $density, $base = null ): ?array {
		$factor = self::DENSITY[ $density ] ?? 1.0;
		if ( 1.0 === $factor ) {
			return null;
		}
		$sizes = is_array( $base ) ? $base : self::theme_spacing();
		$out   = array();
		foreach ( $sizes as $size ) {
			if ( is_array( $size ) && is_string( $size['slug'] ?? null ) && is_string( $size['size'] ?? null ) ) {
				$out[] = array(
					'slug' => $size['slug'],
					'name' => is_string( $size['name'] ?? null ) ? $size['name'] : $size['slug'],
					'size' => 'calc(' . $size['size'] . ' * ' . rtrim( rtrim( sprintf( '%.2F', $factor ), '0' ), '.' ) . ')',
				);
			}
		}
		return array() === $out ? null : $out;
	}

	/**
	 * Crans d'espacement déclarés par le thème.
	 *
	 * @return array<mixed>
	 */
	private static function theme_spacing(): array {
		$settings = WP_Theme_JSON_Resolver::get_theme_data( array(), array( 'with_supports' => false ) )->get_settings();
		$sizes    = $settings['spacing']['spacingSizes']['theme'] ?? array();
		return is_array( $sizes ) ? $sizes : array();
	}

	/**
	 * Valeur à un chemin pointé ; null si absente.
	 *
	 * @param array<string, mixed> $data Données.
	 * @param string               $path Chemin a.b.c.
	 * @return mixed
	 */
	public static function get_path( array $data, string $path ) {
		return GlobalStyles::get( $data, $path );
	}

	/** Formulaire : enregistrer ou revenir aux valeurs du thème. */
	public static function handle_save(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change the shape of this site.', 'fluffless-suite' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$reset = isset( $_POST['fl_reset'] );
		$ok    = $reset ? self::reset() : self::apply(
			array(
				'preset'  => sanitize_key( wp_unslash( $_POST['preset'] ?? '' ) ),
				'radius'  => absint( wp_unslash( $_POST['radius'] ?? 0 ) ),
				'density' => sanitize_key( wp_unslash( $_POST['density'] ?? '' ) ),
			)
		);
		$done  = $ok ? ( $reset ? 'reset' : 'saved' ) : 'error';
		wp_safe_redirect( add_query_arg( 'fl_shape', $done, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/** Écran : réglages à gauche, aperçu à droite. */
	public static function render(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$state  = self::current_state();
		$preset = self::PRESETS[ $state['preset'] ] ?? null;
		echo '<div class="wrap">';
		Screen::header( __( 'Shape and density', 'fluffless-suite' ), __( 'Change the look of the whole site in one move. Every page and pattern follows; nothing to redo by hand.', 'fluffless-suite' ) );

		$done  = isset( $_GET['fl_shape'] ) ? sanitize_key( wp_unslash( $_GET['fl_shape'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage du résultat.
		$notes = array(
			'saved' => array( 'good', __( 'Saved. Every page now uses this shape.', 'fluffless-suite' ) ),
			'reset' => array( 'good', __( 'Back to the theme values.', 'fluffless-suite' ) ),
			'error' => array( 'bad', __( 'The shape could not be saved and your site is unchanged. Check that the active theme is a block theme, then try again.', 'fluffless-suite' ) ),
		);
		if ( isset( $notes[ $done ] ) ) {
			echo Screen::note( $notes[ $done ][0], $notes[ $done ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		echo '<p>' . Screen::pill( self::is_custom() ? 'good' : 'idle', self::is_custom() ? __( 'Custom shape in use', 'fluffless-suite' ) : __( 'Theme values in use', 'fluffless-suite' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::pill().

		echo '<form id="fl-shape-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<div class="fl-shape"><div>';

		// Préréglages : data-* nourrissent l'aperçu (valeurs en px pour l'écran seulement).
		$labels  = array(
			'custom'   => __( 'Custom', 'fluffless-suite' ),
			'square'   => __( 'Square', 'fluffless-suite' ),
			'rounded'  => __( 'Rounded', 'fluffless-suite' ),
			'pill'     => __( 'Pill', 'fluffless-suite' ),
			'oblique'  => __( 'Slanted', 'fluffless-suite' ),
			'diagonal' => __( 'Diagonal', 'fluffless-suite' ),
		);
		$preview = array(
			'pill'     => array( 'btn' => '999px' ),
			'oblique'  => array(
				'cut'   => '20px',
				'slant' => '28px',
			),
			'diagonal' => array( 'btn' => '24px 4px' ),
		);
		echo '<fieldset aria-describedby="fl-preset-help"><legend>' . esc_html__( 'Starting point', 'fluffless-suite' ) . '</legend><div class="fl-choices">';
		foreach ( $labels as $value => $label ) {
			$data = '';
			if ( isset( self::PRESETS[ $value ] ) ) {
				$data .= ' data-radius="' . esc_attr( (string) self::PRESETS[ $value ]['radius'] ) . '"';
			}
			foreach ( $preview[ $value ] ?? array() as $k => $v ) {
				$data .= ' data-' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
			}
			printf(
				'<label class="fl-choice"><input type="radio" name="preset" value="%1$s"%2$s%3$s><span>%4$s</span></label>',
				esc_attr( $value ),
				checked( $state['preset'], $value, false ),
				$data, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributs échappés ci-dessus.
				esc_html( $label )
			);
		}
		echo '</div><p class="description" id="fl-preset-help">' . esc_html__( 'Pill makes buttons fully round. Slanted cuts card corners and tilts section edges that use those block styles.', 'fluffless-suite' ) . '</p></fieldset>';

		echo '<fieldset><legend><label for="fl-radius">' . esc_html__( 'Corner rounding', 'fluffless-suite' ) . '</label></legend><div class="fl-range">';
		printf(
			'<input type="range" id="fl-radius" name="radius" min="0" max="%1$d" step="1" value="%2$d" aria-describedby="fl-radius-help"><output id="fl-radius-out" for="fl-radius">%2$d px</output>',
			(int) self::RADIUS_MAX,
			(int) $state['radius']
		);
		echo '</div><p class="description" id="fl-radius-help">' . esc_html__( 'From 0 (sharp) to 32 px. A preset other than Custom sets this value.', 'fluffless-suite' ) . '</p></fieldset>';

		$densities = array(
			'compact' => __( 'Compact', 'fluffless-suite' ),
			'normal'  => __( 'Normal', 'fluffless-suite' ),
			'airy'    => __( 'Airy', 'fluffless-suite' ),
		);
		echo '<fieldset aria-describedby="fl-density-help"><legend>' . esc_html__( 'Density', 'fluffless-suite' ) . '</legend><div class="fl-choices">';
		foreach ( $densities as $value => $label ) {
			printf(
				'<label class="fl-choice"><input type="radio" name="density" value="%1$s" data-factor="%2$s"%3$s><span>%4$s</span></label>',
				esc_attr( $value ),
				esc_attr( (string) self::DENSITY[ $value ] ),
				checked( $state['density'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</div><p class="description" id="fl-density-help">' . esc_html__( 'Scales every spacing step of the theme: 20% tighter, as designed, or 25% roomier.', 'fluffless-suite' ) . '</p></fieldset>';

		echo '<div class="fl-savebar">';
		submit_button( __( 'Save the shape', 'fluffless-suite' ), 'primary', 'submit', false );
		if ( self::is_custom() ) {
			echo '<button type="submit" name="fl_reset" value="1" class="button">' . esc_html__( 'Back to theme values', 'fluffless-suite' ) . '</button>';
		}
		echo '</div></div>';

		printf(
			'<figure class="fl-preview" id="fl-preview" style="--p-r:%1$dpx;--p-br:%2$s;--p-cut:%3$s;--p-slant:%4$s;--p-d:%5$s">',
			(int) $state['radius'],
			esc_attr( isset( $preview[ $state['preset'] ]['btn'] ) ? $preview[ $state['preset'] ]['btn'] : $state['radius'] . 'px' ),
			esc_attr( null !== $preset && null !== $preset['cut'] ? '20px' : '0px' ),
			esc_attr( null !== $preset && null !== $preset['slant'] ? '28px' : '0px' ),
			esc_attr( (string) self::DENSITY[ $state['density'] ] )
		);
		echo '<div aria-hidden="true"><div class="fl-preview__band"><strong>' . esc_html__( 'A clear title', 'fluffless-suite' ) . '</strong><span class="fl-preview__btn">' . esc_html__( 'Get in touch', 'fluffless-suite' ) . '</span></div>';
		echo '<div class="fl-preview__cards"><div class="fl-preview__card"><span></span><span></span></div><div class="fl-preview__card"><span></span><span></span></div></div></div>';
		echo '<figcaption>' . esc_html__( 'Preview. Your pages use the same values once saved.', 'fluffless-suite' ) . '</figcaption></figure>';
		echo '</div></form>';

		echo '<h2>' . esc_html__( 'Who can change what', 'fluffless-suite' ) . '</h2><ul class="fl-muted">';
		printf(
			'<li>%1$s <a href="%2$s">%3$s</a></li>',
			esc_html( Options::get_bool( self::id(), 'unlock_sections' ) ? __( 'Section layouts: unlocked for the chosen roles.', 'fluffless-suite' ) : __( 'Section layouts: locked, people change text and images only.', 'fluffless-suite' ) ),
			esc_url( SettingsPage::url( 'fl-group-present' ) ),
			esc_html__( 'Change in Settings', 'fluffless-suite' )
		);
		printf(
			'<li>%1$s <a href="%2$s">%3$s</a></li></ul></div>',
			esc_html__( 'Studio mode is set per person, on each user profile.', 'fluffless-suite' ),
			esc_url( admin_url( 'users.php' ) ),
			esc_html__( 'Open Users', 'fluffless-suite' )
		);
	}

	/* ---------- Déverrouillage et mode Studio ---------- */

	/**
	 * La personne peut-elle changer la mise en page des sections ?
	 * Mode Studio : toujours. Sinon : réglage actif et un de ses rôles choisi.
	 *
	 * @param WP_User|null $user Personne (défaut : courante).
	 */
	public static function can_unlock( ?WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();
		if ( ! $user->exists() ) {
			return false;
		}
		if ( self::is_studio( $user->ID ) ) {
			return true;
		}
		return Options::get_bool( self::id(), 'unlock_sections' )
			&& array() !== array_intersect( $user->roles, Options::get_list( self::id(), 'unlock_roles' ) );
	}

	/**
	 * Mode Studio actif pour cette personne ?
	 *
	 * @param int $user_id Identifiant.
	 */
	public static function is_studio( int $user_id ): bool {
		return $user_id > 0 && '1' === get_user_meta( $user_id, self::STUDIO_META, true );
	}

	/**
	 * Retire templateLock: contentOnly des patterns renvoyés à l'éditeur, pour qui peut déverrouiller.
	 * Les sections déjà insérées dans une page gardent leur verrou.
	 *
	 * @param mixed           $response Réponse de la route.
	 * @param mixed           $handler  Gestionnaire (inutilisé).
	 * @param WP_REST_Request $request  Requête.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function unlock_patterns_response( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $response instanceof WP_REST_Response || '/wp/v2/block-patterns/patterns' !== $request->get_route() || ! self::can_unlock() ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		foreach ( $data as $i => $pattern ) {
			if ( is_array( $pattern ) && is_string( $pattern['content'] ?? null ) ) {
				$data[ $i ]['content'] = self::strip_content_lock( $pattern['content'] );
			}
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Contenu de pattern sans verrou contentOnly (les autres verrous restent).
	 *
	 * @param string $content Balisage de blocs.
	 */
	public static function strip_content_lock( string $content ): string {
		if ( ! str_contains( $content, 'contentOnly' ) ) {
			return $content;
		}
		return serialize_blocks( self::unlock_blocks( parse_blocks( $content ) ) );
	}

	/**
	 * Parcours récursif des blocs.
	 *
	 * @param array<mixed> $blocks Blocs analysés.
	 * @return array<mixed>
	 */
	private static function unlock_blocks( array $blocks ): array {
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'contentOnly' === ( $block['attrs']['templateLock'] ?? null ) ) {
				unset( $blocks[ $i ]['attrs']['templateLock'] );
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$blocks[ $i ]['innerBlocks'] = self::unlock_blocks( $block['innerBlocks'] );
			}
		}
		return $blocks;
	}

	/**
	 * Mode Studio : valeurs libres dans l'éditeur, pour cette personne seulement.
	 *
	 * @param mixed $settings Réglages de l'éditeur.
	 * @return mixed
	 */
	public static function studio_settings( $settings ) {
		if ( ! is_array( $settings ) || ! self::is_studio( get_current_user_id() ) ) {
			return $settings;
		}
		$features = is_array( $settings['__experimentalFeatures'] ?? null ) ? $settings['__experimentalFeatures'] : array();
		foreach ( array( 'color', 'typography', 'spacing' ) as $group ) {
			$features[ $group ] = is_array( $features[ $group ] ?? null ) ? $features[ $group ] : array();
		}
		$features['color']['custom']              = true;
		$features['color']['customGradient']      = true;
		$features['typography']['customFontSize'] = true;
		$features['spacing']['customSpacingSize'] = true;
		$units                                    = is_array( $features['spacing']['units'] ?? null ) ? $features['spacing']['units'] : array();
		$features['spacing']['units']             = array_values( array_unique( array_merge( $units, array( 'px', 'rem', 'em', '%', 'vw', 'vh', 'ch' ) ) ) );

		$settings['__experimentalFeatures'] = $features;
		$settings['disableCustomColors']    = false;
		$settings['disableCustomGradients'] = false;
		$settings['disableCustomFontSizes'] = false;
		$settings['enableCustomSpacing']    = true;
		$settings['enableCustomUnits']      = $features['spacing']['units'];
		return $settings;
	}

	/**
	 * Case du profil. Visible pour qui peut edit_theme_options, sur le profil d'une personne qui rédige.
	 *
	 * @param mixed $user Profil affiché.
	 */
	public static function render_studio_field( $user ): void {
		if ( ! $user instanceof WP_User || ! current_user_can( 'edit_theme_options' ) || ! user_can( $user, 'edit_posts' ) ) {
			return;
		}
		printf(
			'<tr class="fluffless-studio"><th scope="row">%1$s</th><td><label for="fluffless-studio"><input type="checkbox" id="fluffless-studio" name="fluffless_studio" value="1"%2$s> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Fluffless Studio mode', 'fluffless-suite' ),
			checked( self::is_studio( $user->ID ), true, false ),
			esc_html__( 'Custom colours, free sizes and spacing, and unlocked sections in the editor, for this person only.', 'fluffless-suite' ),
			wp_kses( __( 'Studio mode: all the knobs, none of the <em>fluff</em>.', 'fluffless-suite' ), array( 'em' => array() ) )
		);
	}

	/**
	 * Enregistre la case du profil. Nonce vérifié par WordPress (update-user_{id}) avant ce crochet.
	 *
	 * @param mixed $user_id Personne modifiée.
	 */
	public static function save_studio_field( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié par user-edit.php.
		if ( isset( $_POST['fluffless_studio'] ) && '1' === sanitize_key( wp_unslash( $_POST['fluffless_studio'] ) ) ) {
			update_user_meta( $user_id, self::STUDIO_META, '1' );
		} else {
			delete_user_meta( $user_id, self::STUDIO_META );
		}
	}
}
