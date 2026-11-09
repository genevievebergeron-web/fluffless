<?php
/**
 * Module Préréglages de style : crée des styles de bloc sans écrire de code.
 *
 * Chaque préréglage porte un nom, quelques types de blocs et des réglages pris dans des listes
 * fermées tirées des styles du thème (couleurs de la palette, espacements, ombres, tailles de
 * texte) ou de constantes (arrondi, épaisseur de bordure). Il est enregistré par
 * register_block_style() avec style_data : WordPress écrit lui-même le CSS dans les styles
 * globaux et le style apparaît dans le panneau Styles de l'éditeur, comme un style natif.
 * Aucune valeur libre n'atteint le CSS.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Blocks\Flottant;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Préréglages de style de bloc.
 */
final class Styles implements Module {

	public const PAGE   = 'fluffless-styles';
	public const ACTION = 'fluffless_styles';
	public const OPTION = Lifecycle::OPTION_STYLES;

	/** Préréglages gardés au plus : WordPress écrit leur CSS sur chaque page. */
	public const MAX = 12;

	/** Longueur maximale du nom. */
	public const LABEL_MAX = 40;

	/** Préfixe des noms de style, pour ne jamais croiser ceux du cœur, du thème ou de la Suite. */
	public const PREFIX = 'fl-preset-';

	/** Blocs qui peuvent recevoir un préréglage. */
	public const BLOCKS = array( 'core/group', 'core/columns', 'core/column', 'core/cover', 'core/button', 'core/image', 'core/quote', 'core/heading', 'core/paragraph' );

	/** Arrondis offerts. */
	public const RADII = array( '0', '4px', '8px', '16px', '24px', '999px' );

	/** Épaisseurs de bordure offertes. */
	public const WIDTHS = array( '1px', '2px', '3px' );

	/** Contraste minimal entre le texte et le fond. */
	public const MIN_RATIO = 4.5;

	/** Identifiant stable. */
	public static function id(): string {
		return 'styles';
	}

	/** Nom affiché. */
	public static function label(): string {
		return __( 'Style presets', 'fluffless-suite' );
	}

	/** Ce que le module change. */
	public static function description(): string {
		return __( 'Create block styles without code: colours, spacing, corners, border, shadow and text size, taken from the site styles. They show up in the editor Styles panel.', 'fluffless-suite' );
	}

	/** Actif sur une installation neuve : rien n'est écrit tant qu'aucun préréglage n'existe. */
	public static function enabled_by_default(): bool {
		return true;
	}

	/**
	 * Aucun réglage : tout se passe sur l'écran Styles.
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
		add_action( 'init', array( self::class, 'register_styles' ), 20 );
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_save' ) );
	}

	/** Onglet Styles du groupe Apparence. */
	public static function add_page(): void {
		Menu::add_tab( 'appearance', self::PAGE, static fn(): string => __( 'Styles', 'fluffless-suite' ), array( self::class, 'render' ), 15 );
	}

	/* ---------- Choix offerts ---------- */

	/**
	 * Préréglages du thème pour un chemin des réglages globaux, slug => nom ; le thème l'emporte sur le cœur.
	 *
	 * @param string[] $path Chemin, par exemple array( 'color', 'palette' ).
	 * @return array<string, string>
	 */
	private static function presets( array $path ): array {
		$sets = wp_get_global_settings( $path );
		$out  = array();
		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			$list = is_array( $sets ) && is_array( $sets[ $origin ] ?? null ) ? $sets[ $origin ] : array();
			foreach ( $list as $entry ) {
				$slug = is_array( $entry ) && is_string( $entry['slug'] ?? null ) ? $entry['slug'] : '';
				if ( '' === $slug || 1 !== preg_match( '/^[a-z0-9-]{1,40}$/', $slug ) ) {
					continue;
				}
				$out[ $slug ] = is_string( $entry['name'] ?? null ) && '' !== $entry['name'] ? $entry['name'] : $slug;
			}
		}
		return $out;
	}

	/**
	 * Listes fermées de l'écran, clé du réglage => valeur => libellé.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function choices(): array {
		$radii  = array(
			'0'     => __( 'Square', 'fluffless-suite' ),
			'4px'   => __( 'Slightly rounded', 'fluffless-suite' ),
			'8px'   => __( 'Rounded', 'fluffless-suite' ),
			'16px'  => __( 'Very rounded', 'fluffless-suite' ),
			'24px'  => __( 'Soft', 'fluffless-suite' ),
			'999px' => __( 'Pill', 'fluffless-suite' ),
		);
		$widths = array(
			'1px' => __( 'Thin', 'fluffless-suite' ),
			'2px' => __( 'Medium', 'fluffless-suite' ),
			'3px' => __( 'Thick', 'fluffless-suite' ),
		);
		/**
		 * La clé « 0 » devient un entier en PHP ; isset() la retrouve quand même avec la chaîne '0'.
		 *
		 * @var array<string, array<string, string>>
		 */
		return array(
			'background'   => self::presets( array( 'color', 'palette' ) ),
			'text'         => self::presets( array( 'color', 'palette' ) ),
			'padding'      => self::presets( array( 'spacing', 'spacingSizes' ) ),
			'radius'       => $radii,
			'border_width' => $widths,
			'border_color' => self::presets( array( 'color', 'palette' ) ),
			'shadow'       => self::presets( array( 'shadow', 'presets' ) ),
			'font_size'    => self::presets( array( 'typography', 'fontSizes' ) ),
		);
	}

	/**
	 * Types de blocs offerts, nom => libellé traduit.
	 *
	 * @return array<string, string>
	 */
	public static function block_labels(): array {
		return array(
			'core/group'     => __( 'Group', 'fluffless-suite' ),
			'core/columns'   => __( 'Columns', 'fluffless-suite' ),
			'core/column'    => __( 'Column', 'fluffless-suite' ),
			'core/cover'     => __( 'Cover', 'fluffless-suite' ),
			'core/button'    => __( 'Button', 'fluffless-suite' ),
			'core/image'     => __( 'Image', 'fluffless-suite' ),
			'core/quote'     => __( 'Quote', 'fluffless-suite' ),
			'core/heading'   => __( 'Heading', 'fluffless-suite' ),
			'core/paragraph' => __( 'Paragraph', 'fluffless-suite' ),
		);
	}

	/* ---------- Stockage ---------- */

	/**
	 * Préréglages gardés, chacun revalidé à la lecture ; ceux qui ne passent plus sont écartés.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		$stored  = get_option( self::OPTION );
		$out     = array();
		$choices = self::choices();
		foreach ( is_array( $stored ) ? $stored : array() as $raw ) {
			if ( count( $out ) >= self::MAX ) {
				break;
			}
			$clean = is_array( $raw ) ? self::clean( $raw, $out, $choices ) : 'invalid';
			if ( is_array( $clean ) ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Valide un préréglage brut. Rend le préréglage propre, ou un code d'erreur :
	 * « no-name », « no-block », « taken », « contrast », « empty » ou « invalid ».
	 *
	 * @param array<mixed>                              $raw      Champs du formulaire.
	 * @param list<array<string, mixed>>                $existing Préréglages déjà gardés.
	 * @param array<string, array<string, string>>|null $choices Listes fermées ; null pour les lire.
	 * @return array<string, mixed>|string
	 */
	public static function clean( array $raw, array $existing = array(), ?array $choices = null ) {
		$choices = $choices ?? self::choices();
		$label   = is_string( $raw['label'] ?? null ) ? trim( wp_strip_all_tags( $raw['label'] ) ) : '';
		$label   = mb_substr( $label, 0, self::LABEL_MAX );
		$slug    = substr( sanitize_title( $label ), 0, 30 );
		if ( '' === $label || '' === $slug ) {
			return 'no-name';
		}
		$name = self::PREFIX . $slug;
		foreach ( $existing as $preset ) {
			if ( ( $preset['name'] ?? '' ) === $name ) {
				return 'taken';
			}
		}
		$blocks = array_values( array_intersect( self::BLOCKS, is_array( $raw['blocks'] ?? null ) ? $raw['blocks'] : array() ) );
		if ( array() === $blocks ) {
			return 'no-block';
		}
		$preset = array(
			'name'   => $name,
			'label'  => $label,
			'blocks' => $blocks,
		);
		$values = array();
		$filled = false;
		foreach ( $choices as $key => $allowed ) {
			$value = is_string( $raw[ $key ] ?? null ) ? $raw[ $key ] : '';
			if ( '' !== $value && ! isset( $allowed[ $value ] ) ) {
				return 'invalid';
			}
			$values[ $key ] = $value;
			$filled         = $filled || ( '' !== $value && 'border_color' !== $key );
		}
		if ( ! $filled ) {
			return 'empty';
		}
		if ( '' !== ( $values['border_color'] ?? '' ) && '' === ( $values['border_width'] ?? '' ) ) {
			$values['border_width'] = '1px';
		}
		$background = $values['background'] ?? '';
		if ( '' !== $background ) {
			$hex = Flottant::palette_hex();
			if ( '' === ( $values['text'] ?? '' ) ) {
				$values['text'] = Flottant::text_slug( $background );
			}
			$bg   = $hex[ $background ] ?? '';
			$text = $hex[ $values['text'] ] ?? '';
			if ( '' !== $bg && '' !== $text && Palette::ratio( $bg, $text ) < self::MIN_RATIO ) {
				return 'contrast';
			}
		}
		return array_merge( $preset, $values );
	}

	/**
	 * Ajoute un préréglage ; rend « added » ou le code d'erreur de clean(), ou « full ».
	 *
	 * @param array<mixed> $raw Champs du formulaire.
	 */
	public static function add( array $raw ): string {
		$all = self::all();
		if ( count( $all ) >= self::MAX ) {
			return 'full';
		}
		$preset = self::clean( $raw, $all );
		if ( is_string( $preset ) ) {
			return $preset;
		}
		$all[] = $preset;
		update_option( self::OPTION, $all, false );
		return 'added';
	}

	/**
	 * Retire un préréglage par son nom ; vrai s'il existait.
	 *
	 * @param string $name Nom complet, préfixe compris.
	 */
	public static function remove( string $name ): bool {
		$all  = self::all();
		$kept = array_values( array_filter( $all, static fn( array $p ): bool => $p['name'] !== $name ) );
		if ( count( $kept ) === count( $all ) ) {
			return false;
		}
		if ( array() === $kept ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $kept, false );
		}
		return true;
	}

	/* ---------- Enregistrement ---------- */

	/**
	 * Objet de style au format theme.json pour un préréglage propre.
	 *
	 * @param array<string, mixed> $preset Préréglage validé.
	 * @return array<string, mixed>
	 */
	public static function style_data( array $preset ): array {
		$ref   = static fn( string $type, string $slug ): string => 'var:preset|' . $type . '|' . $slug;
		$style = array();
		foreach ( array( 'background', 'text' ) as $key ) {
			if ( '' !== $preset[ $key ] ) {
				$style['color'][ $key ] = $ref( 'color', $preset[ $key ] );
			}
		}
		if ( '' !== $preset['padding'] ) {
			$pad                         = $ref( 'spacing', $preset['padding'] );
			$style['spacing']['padding'] = array(
				'top'    => $pad,
				'right'  => $pad,
				'bottom' => $pad,
				'left'   => $pad,
			);
		}
		if ( '' !== $preset['radius'] ) {
			$style['border']['radius'] = $preset['radius'];
		}
		if ( '' !== $preset['border_width'] ) {
			$style['border']['width'] = $preset['border_width'];
			$style['border']['style'] = 'solid';
			$style['border']['color'] = '' !== $preset['border_color'] ? $ref( 'color', $preset['border_color'] ) : 'currentColor';
		}
		if ( '' !== $preset['shadow'] ) {
			$style['shadow'] = $ref( 'shadow', $preset['shadow'] );
		}
		if ( '' !== $preset['font_size'] ) {
			$style['typography']['fontSize'] = $ref( 'font-size', $preset['font_size'] );
		}
		return $style;
	}

	/** Enregistre chaque préréglage comme style de bloc natif. */
	public static function register_styles(): void {
		foreach ( self::all() as $preset ) {
			register_block_style(
				$preset['blocks'],
				array(
					'name'       => $preset['name'],
					'label'      => $preset['label'],
					'style_data' => self::style_data( $preset ),
				)
			);
		}
	}

	/* ---------- Écran ---------- */

	/** Ajout ou retrait, puis retour à l'écran avec le résultat. */
	public static function handle_save(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change the styles of this site.', 'fluffless-suite' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		if ( isset( $_POST['fl_remove'] ) && is_string( $_POST['fl_remove'] ) ) {
			$done = self::remove( sanitize_key( wp_unslash( $_POST['fl_remove'] ) ) ) ? 'removed' : 'invalid';
		} else {
			$raw  = isset( $_POST['fl_style'] ) && is_array( $_POST['fl_style'] ) ? map_deep( wp_unslash( $_POST['fl_style'] ), 'sanitize_text_field' ) : array();
			$done = self::add( (array) $raw );
		}
		wp_safe_redirect( add_query_arg( 'fl_styles', $done, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/** Écran Fluffless → Styles. */
	public static function render(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		Screen::header( __( 'Style presets', 'fluffless-suite' ), __( 'Create a style once, then apply it to any block from the Styles panel of the editor. Every value comes from the site styles, so a new palette updates every preset.', 'fluffless-suite' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage du résultat.
		$done  = isset( $_GET['fl_styles'] ) ? sanitize_key( wp_unslash( $_GET['fl_styles'] ) ) : '';
		$notes = array(
			'added'    => array( 'good', __( 'Preset created. Find it in the Styles panel of the chosen blocks.', 'fluffless-suite' ) ),
			'removed'  => array( 'good', __( 'Preset removed. Blocks that used it go back to their default look.', 'fluffless-suite' ) ),
			'no-name'  => array( 'bad', __( 'Not created. A name is required.', 'fluffless-suite' ) ),
			'no-block' => array( 'bad', __( 'Not created. Choose at least one block type.', 'fluffless-suite' ) ),
			'taken'    => array( 'bad', __( 'Not created. Another preset already has this name.', 'fluffless-suite' ) ),
			'empty'    => array( 'bad', __( 'Not created. Choose at least one setting.', 'fluffless-suite' ) ),
			'contrast' => array( 'bad', __( 'Not created, because the text would be hard to read on this background. Text and background need a contrast ratio of 4.5:1.', 'fluffless-suite' ) ),
			/* translators: %d: maximum number of presets. */
			'full'     => array( 'bad', sprintf( __( 'Not created. The site keeps %d presets at most; remove one first.', 'fluffless-suite' ), self::MAX ) ),
			'invalid'  => array( 'bad', __( 'Nothing changed. Reload the page and try again.', 'fluffless-suite' ) ),
		);
		if ( isset( $notes[ $done ] ) ) {
			echo Screen::note( $notes[ $done ][0], $notes[ $done ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		self::render_list();
		self::render_form();
		echo '</div>';
	}

	/** Préréglages existants, un bouton de retrait chacun. */
	private static function render_list(): void {
		$all    = self::all();
		$blocks = self::block_labels();
		echo '<h2>' . esc_html__( 'Presets', 'fluffless-suite' ) . '</h2>';
		if ( array() === $all ) {
			echo '<p>' . esc_html__( 'No preset yet.', 'fluffless-suite' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Name', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Blocks', 'fluffless-suite' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'fluffless-suite' ) . '</span></th></tr></thead><tbody>';
		foreach ( $all as $preset ) {
			$names = array_map( static fn( string $b ): string => $blocks[ $b ] ?? $b, $preset['blocks'] );
			echo '<tr><th scope="row">' . esc_html( $preset['label'] ) . '</th><td>' . esc_html( implode( ', ', $names ) ) . '</td><td>';
			printf( '<form method="post" action="%1$s"><input type="hidden" name="action" value="%2$s">', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( self::ACTION ) );
			wp_nonce_field( self::ACTION );
			/* translators: %s: preset name. */
			echo '<button type="submit" class="button button-link-delete" name="fl_remove" value="' . esc_attr( $preset['name'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Remove %s', 'fluffless-suite' ), $preset['label'] ) ) . '">' . esc_html__( 'Remove', 'fluffless-suite' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Formulaire d'ajout. */
	private static function render_form(): void {
		echo '<h2 id="fl-style-new">' . esc_html__( 'New preset', 'fluffless-suite' ) . '</h2>';
		if ( count( self::all() ) >= self::MAX ) {
			/* translators: %d: maximum number of presets. */
			echo '<p>' . esc_html( sprintf( __( 'The site keeps %d presets at most. Remove one to create another.', 'fluffless-suite' ), self::MAX ) ) . '</p>';
			return;
		}
		printf( '<form method="post" action="%1$s" aria-labelledby="fl-style-new"><input type="hidden" name="action" value="%2$s">', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( self::ACTION ) );
		wp_nonce_field( self::ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="fl-style-label">' . esc_html__( 'Name', 'fluffless-suite' ) . '</label></th><td><input type="text" id="fl-style-label" name="fl_style[label]" maxlength="' . esc_attr( (string) self::LABEL_MAX ) . '" required class="regular-text"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Blocks', 'fluffless-suite' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Blocks', 'fluffless-suite' ) . '</legend>';
		foreach ( self::block_labels() as $block => $label ) {
			$id = 'fl-style-' . str_replace( '/', '-', $block );
			echo '<label for="' . esc_attr( $id ) . '" style="display:inline-block;margin-inline-end:1em"><input type="checkbox" id="' . esc_attr( $id ) . '" name="fl_style[blocks][]" value="' . esc_attr( $block ) . '"> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset></td></tr>';
		$labels = array(
			'background'   => __( 'Background', 'fluffless-suite' ),
			'text'         => __( 'Text colour', 'fluffless-suite' ),
			'padding'      => __( 'Inner spacing', 'fluffless-suite' ),
			'radius'       => __( 'Corners', 'fluffless-suite' ),
			'border_width' => __( 'Border', 'fluffless-suite' ),
			'border_color' => __( 'Border colour', 'fluffless-suite' ),
			'shadow'       => __( 'Shadow', 'fluffless-suite' ),
			'font_size'    => __( 'Text size', 'fluffless-suite' ),
		);
		foreach ( self::choices() as $key => $options ) {
			$id = 'fl-style-' . $key;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $labels[ $key ] ) . '</label></th><td><select id="' . esc_attr( $id ) . '" name="fl_style[' . esc_attr( $key ) . ']"><option value="">' . esc_html__( 'Unchanged', 'fluffless-suite' ) . '</option>';
			foreach ( $options as $value => $label ) {
				echo '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			if ( 'text' === $key ) {
				echo '<p class="description">' . esc_html__( 'Left unchanged with a background, the most readable colour of the palette is chosen.', 'fluffless-suite' ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create preset', 'fluffless-suite' ) . '</button></p></form>';
	}
}
