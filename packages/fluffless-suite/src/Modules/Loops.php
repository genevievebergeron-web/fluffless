<?php
/**
 * Module Fiches de boucle : une fiche (carte, liste, mosaïque du thème ou composition synchronisée)
 * choisie une fois par type de contenu et rendue dans toutes les boucles de ce type, sans ouvrir
 * chaque gabarit ; bloc fluffless/fiche pour la poser soi-même dans une boucle.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use WP_Block;
use WP_Block_Patterns_Registry;
use WP_Post;
use const Fluffless\Suite\FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Une fiche est désignée par un identifiant :
 * - <slug>      : composition du thème fluffless/fiche-<slug> (carte, liste, mosaique, ou toute
 *                 composition de ce préfixe ajoutée par un thème enfant) ;
 * - block:<id>  : composition synchronisée (wp_block) publiée.
 *
 * L'option fluffless_fiches associe un type de contenu public à une fiche. Quand un type a sa
 * fiche, le bloc Modèle de publication de chaque boucle de ce type est rendu avec un seul bloc
 * fluffless/fiche à la place de ses blocs ; la classe fluffless-fiche-libre sur le Modèle de
 * publication le laisse tel quel. Le bloc fluffless/fiche rend la fiche en passant le contexte
 * du contenu (postId, postType) à ses blocs, ce que le bloc Composition du cœur ne fait pas.
 */
final class Loops implements Module {

	public const PAGE       = 'fluffless-fiches';
	public const ACTION     = 'fluffless_fiches_save';
	public const BLOCK      = 'fluffless/fiche';
	public const PREFIX     = 'fluffless/fiche-';
	public const DEFAULT    = 'carte';
	public const FREE       = 'fluffless-fiche-libre';
	public const MAX_BLOCKS = 50;
	private const CARD_RULE = '/^(block:[1-9][0-9]{0,9}|[a-z0-9-]{1,40})$/';

	/**
	 * Fiche en cours de rendu : coupe une composition qui se contiendrait elle-même.
	 *
	 * @var bool
	 */
	private static bool $rendering = false;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'fiches';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Loop cards', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Chooses once, per content type, how each post looks in every Query Loop: card, list, mosaic or a synced pattern of yours. Change it in one place and all grids follow. Nothing is added to pages.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Réglages bruts.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		return array();
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_filter( 'render_block_data', array( self::class, 'swap_loop' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		if ( is_admin() ) {
			Menu::add_tab( 'appearance', self::PAGE, static fn(): string => __( 'Loop cards', 'fluffless-suite' ), array( self::class, 'render' ), 50 );
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_save' ) );
		}
	}

	/** Enregistre le bloc depuis son block.json. */
	public static function register_block(): void {
		register_block_type(
			dirname( FILE ) . '/blocks/fiche',
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
	}

	/** Liste des fiches et traductions du script d'éditeur. */
	public static function editor_assets(): void {
		$handle = generate_block_asset_handle( self::BLOCK, 'editorScript' );
		$cards  = array();
		foreach ( self::cards() as $value => $label ) {
			$cards[] = array(
				'value' => $value,
				'label' => $label,
			);
		}
		wp_add_inline_script( $handle, 'window.flufflessFiches=' . wp_json_encode( array( 'cards' => $cards ) ) . ';', 'before' );
		wp_set_script_translations( $handle, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
	}

	/* ---------- Fiches et réglage ---------- */

	/**
	 * Fiches disponibles : identifiant => titre. Compositions du thème fluffless/fiche-*, puis
	 * compositions synchronisées publiées (cinquante au plus, par titre).
	 *
	 * @return array<string, string>
	 */
	public static function cards(): array {
		$out = array();
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$name = (string) ( $pattern['name'] ?? '' );
			if ( str_starts_with( $name, self::PREFIX ) ) {
				$slug = substr( $name, strlen( self::PREFIX ) );
				if ( 1 === preg_match( self::CARD_RULE, $slug ) && ! str_starts_with( $slug, 'block:' ) ) {
					$out[ $slug ] = (string) ( $pattern['title'] ?? $slug );
				}
			}
		}
		$blocks = get_posts(
			array(
				'post_type'        => 'wp_block',
				'post_status'      => 'publish',
				'numberposts'      => self::MAX_BLOCKS,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		foreach ( $blocks as $block ) {
			/* translators: %s: synced pattern title */
			$out[ 'block:' . $block->ID ] = sprintf( __( 'Synced pattern: %s', 'fluffless-suite' ), '' !== $block->post_title ? $block->post_title : (string) $block->ID );
		}
		return $out;
	}

	/**
	 * Types de contenu publics (sauf les fichiers joints) : slug => libellé.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$out[ $type->name ] = $type->labels->name;
			}
		}
		return $out;
	}

	/**
	 * Fiche choisie par type de contenu, assainie.
	 *
	 * @return array<string, string>
	 */
	public static function settings(): array {
		$raw = get_option( Lifecycle::OPTION_FICHES, array() );
		return self::sanitize_settings( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Garde seulement les types publics et les identifiants de fiche bien formés ; un choix vide
	 * retire le type.
	 *
	 * @param array<mixed> $raw type => fiche.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( array $raw ): array {
		$types = self::types();
		$out   = array();
		foreach ( $raw as $type => $card ) {
			if ( is_string( $type ) && isset( $types[ $type ] ) && is_string( $card ) && '' !== $card && 1 === preg_match( self::CARD_RULE, $card ) ) {
				$out[ $type ] = $card;
			}
		}
		return $out;
	}

	/**
	 * Fiche à rendre pour un type : réglage du type, sinon rien ('').
	 *
	 * @param string $type Type de contenu.
	 */
	public static function card_for( string $type ): string {
		return self::settings()[ $type ] ?? '';
	}

	/**
	 * Contenu (blocs sérialisés) d'une fiche, ou '' quand elle n'existe pas ou plus.
	 *
	 * @param string $card Identifiant de la fiche.
	 */
	public static function card_content( string $card ): string {
		if ( 1 !== preg_match( self::CARD_RULE, $card ) ) {
			return '';
		}
		if ( str_starts_with( $card, 'block:' ) ) {
			$post = get_post( (int) substr( $card, 6 ) );
			return $post instanceof WP_Post && 'wp_block' === $post->post_type && 'publish' === $post->post_status ? (string) $post->post_content : '';
		}
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( self::PREFIX . $card );
		return (string) ( $pattern['content'] ?? '' );
	}

	/* ---------- Rendu ---------- */

	/**
	 * Rend la fiche pour le contenu courant de la boucle, en passant postId et postType aux blocs.
	 *
	 * @param array<string, mixed> $attributes Attributs du bloc.
	 * @param string               $content    Contenu (vide, bloc dynamique).
	 * @param WP_Block|null        $block      Bloc rendu, avec son contexte.
	 */
	public static function render_block( array $attributes, string $content = '', ?WP_Block $block = null ): string {
		$context = $block instanceof WP_Block ? $block->context : array();
		$post_id = (int) ( $context['postId'] ?? get_the_ID() );
		if ( $post_id <= 0 ) {
			return '';
		}
		$post_type = (string) ( $context['postType'] ?? get_post_type( $post_id ) );
		$card      = is_string( $attributes['card'] ?? null ) ? $attributes['card'] : '';
		if ( '' === $card ) {
			$card = self::card_for( $post_type );
		}
		$html = '' !== $card ? self::card_content( $card ) : '';
		if ( '' === $html ) {
			$html = self::card_content( self::DEFAULT );
		}
		if ( '' === $html || self::$rendering ) {
			return '';
		}
		// Les blocs du cœur (titre, date) lisent le contenu global : le poser quand la fiche est
		// rendue hors d'une boucle, comme dans l'aperçu de l'éditeur.
		$previous = $GLOBALS['post'] ?? null;
		$switch   = ! ( $previous instanceof WP_Post ) || $previous->ID !== $post_id;
		if ( $switch ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return '';
			}
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $post );
		}
		self::$rendering = true;
		$out             = '';
		$available       = array(
			'postId'   => $post_id,
			'postType' => $post_type,
		);
		foreach ( parse_blocks( $html ) as $parsed ) {
			if ( ! is_string( $parsed['blockName'] ?? null ) ) {
				continue;
			}
			$out .= ( new WP_Block( $parsed, $available ) )->render();
		}
		self::$rendering = false;
		if ( $switch ) {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			if ( $previous instanceof WP_Post ) {
				setup_postdata( $previous );
			}
		}
		return $out;
	}

	/**
	 * Remplace les blocs du Modèle de publication d'une boucle par un bloc fluffless/fiche quand le
	 * type de contenu de la boucle a sa fiche, sauf si le modèle porte la classe fluffless-fiche-libre.
	 *
	 * @param array<string, mixed>|mixed $parsed Bloc analysé.
	 * @param array<string, mixed>|mixed $source Bloc d'origine.
	 * @param WP_Block|null|mixed        $query  Bloc parent (la boucle).
	 * @return array<string, mixed>|mixed
	 */
	public static function swap_loop( $parsed, $source = null, $query = null ) {
		if ( ! is_array( $parsed ) || 'core/post-template' !== ( $parsed['blockName'] ?? '' ) || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $parsed;
		}
		$attrs = is_array( $parsed['attrs'] ?? null ) ? $parsed['attrs'] : array();
		if ( is_string( $attrs['className'] ?? null ) && str_contains( $attrs['className'], self::FREE ) ) {
			return $parsed;
		}
		$type = self::loop_type( $query instanceof WP_Block ? $query : null );
		if ( '' === $type || '' === self::card_for( $type ) ) {
			return $parsed;
		}
		$parsed['innerBlocks']  = array(
			array(
				'blockName'    => self::BLOCK,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
		);
		$parsed['innerContent'] = array( null );
		$parsed['innerHTML']    = '';
		return $parsed;
	}

	/**
	 * Type de contenu d'une boucle : celui de sa requête, ou celui de la requête principale quand
	 * elle en hérite ('' quand il est mêlé, comme une recherche sur tous les types).
	 *
	 * @param WP_Block|null $query Bloc Boucle de requête.
	 */
	public static function loop_type( ?WP_Block $query ): string {
		$args = null !== $query && is_array( $query->parsed_block['attrs']['query'] ?? null ) ? $query->parsed_block['attrs']['query'] : array();
		if ( empty( $args['inherit'] ) ) {
			$type = $args['postType'] ?? 'post';
			return is_string( $type ) ? $type : '';
		}
		if ( is_search() ) {
			return '';
		}
		$type = get_query_var( 'post_type' );
		if ( is_array( $type ) ) {
			return 1 === count( $type ) && is_string( $type[0] ) ? $type[0] : '';
		}
		if ( is_string( $type ) && '' !== $type ) {
			return $type;
		}
		return is_home() || is_archive() ? 'post' : '';
	}

	/* ---------- Écran ---------- */

	/** Enregistre la fiche de chaque type. */
	public static function handle_save(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change loop cards.', 'fluffless-suite' ) );
		}
		check_admin_referer( self::ACTION );
		$raw      = isset( $_POST['card'] ) && is_array( $_POST['card'] ) ? wp_unslash( $_POST['card'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- assaini par sanitize_settings().
		$settings = self::sanitize_settings( $raw );
		$cards    = self::cards();
		foreach ( $settings as $type => $card ) {
			if ( ! isset( $cards[ $card ] ) ) {
				unset( $settings[ $type ] );
			}
		}
		if ( array() === $settings ) {
			delete_option( Lifecycle::OPTION_FICHES );
		} else {
			update_option( Lifecycle::OPTION_FICHES, $settings, false );
		}
		wp_safe_redirect( add_query_arg( 'fl_fiches', 'saved', Menu::url( self::PAGE ) ) );
		exit;
	}

	/** Écran Fiches de boucle. */
	public static function render(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		Screen::header( __( 'Loop cards', 'fluffless-suite' ), __( 'Choose once how each post of a content type looks in every Query Loop of the site: blog grids, archives, related posts. Leave “Loop’s own layout” to keep what each loop holds.', 'fluffless-suite' ) );
		$done = isset( $_GET['fl_fiches'] ) ? sanitize_key( wp_unslash( $_GET['fl_fiches'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage du résultat.
		if ( 'saved' === $done ) {
			echo Screen::note( 'good', __( 'Saved. Every loop of these content types now shows the chosen card.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		$cards    = self::cards();
		$settings = self::settings();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<table class="widefat striped fl-fiches"><thead><tr><th scope="col">' . esc_html__( 'Content type', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Card', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
		foreach ( self::types() as $type => $label ) {
			$value   = $settings[ $type ] ?? '';
			$options = $cards;
			if ( '' !== $value && ! isset( $options[ $value ] ) ) {
				/* translators: %s: card identifier */
				$options[ $value ] = sprintf( __( '%s (missing)', 'fluffless-suite' ), $value );
			}
			echo '<tr><th scope="row"><label for="fl-card-' . esc_attr( $type ) . '">' . esc_html( $label ) . '</label></th><td><select id="fl-card-' . esc_attr( $type ) . '" name="card[' . esc_attr( $type ) . ']"><option value="">' . esc_html__( 'Loop’s own layout', 'fluffless-suite' ) . '</option>';
			foreach ( $options as $id => $title ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $id ), selected( $value, (string) $id, false ), esc_html( $title ) );
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Cards are the “Loop card” patterns of the theme (card, list, mosaic) and your synced patterns: make one from post blocks (title, image, date, excerpt) in the editor and it appears here. A Query Loop whose Post Template carries the class fluffless-fiche-libre keeps its own layout.', 'fluffless-suite' ) . '</p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save the cards', 'fluffless-suite' ) . '</button></p></form>';
		echo '</div>';
	}
}
