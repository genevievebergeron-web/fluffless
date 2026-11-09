<?php
/**
 * Module Qualité du contenu : un panneau de l'éditeur relève les titres mal ordonnés, les images
 * sans texte de remplacement et les liens « cliquez ici ». Règles locales, aucun appel réseau.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Module;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Le jugement se fait dans le navigateur (assets/quality/quality-core.js, testé sous Node).
 * Le serveur dit seulement quels gabarits affichent déjà le titre en H1 : sur page.html, un H1
 * dans le contenu fait double emploi ; sur Sections ou Toile vierge, la page en a besoin d'un.
 */
final class Quality implements Module {

	public const SCRIPT_HANDLE = 'fluffless-quality-editor';
	public const CORE_HANDLE   = 'fluffless-quality-core';

	/** Borne des gabarits et compositions suivis pour trouver le titre (parties, compositions). */
	private const MAX_DEPTH = 5;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'quality';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Content quality', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Adds a Content quality panel to the editor: headings out of order, images without alternative text and “click here” links, each one a click away from its block. Plain rules that run in your browser and send nothing anywhere; visitors download nothing.', 'fluffless-suite' );
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
		unset( $input );
		return array();
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
	}

	/** Panneau de l'éditeur et ce qu'il doit savoir des gabarits. */
	public static function editor_assets(): void {
		// Éditeur de publication et éditeur de site seulement : ni widgets ni outil de personnalisation.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen instanceof \WP_Screen && ! in_array( $screen->base, array( 'post', 'site-editor' ), true ) ) {
			return;
		}
		wp_enqueue_script( self::CORE_HANDLE, plugins_url( 'assets/quality/quality-core.js', FILE ), array(), VERSION, true );
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/quality/editor.js', FILE ),
			array( self::CORE_HANDLE, 'wp-a11y', 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			VERSION,
			true
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
		$post      = get_post();
		$templates = self::title_templates();
		$data      = wp_json_encode(
			array(
				'templates'       => $templates,
				'defaultTemplate' => $post instanceof \WP_Post ? self::default_template( $post, $templates ) : '',
			)
		);
		if ( false !== $data ) {
			wp_add_inline_script( self::SCRIPT_HANDLE, 'window.flufflessQuality=' . $data . ';', 'before' );
		}
	}

	/**
	 * Gabarits du site : slug => true s'il affiche le titre de la publication en H1.
	 *
	 * @return array<string, bool>
	 */
	public static function title_templates(): array {
		$out = array();
		foreach ( get_block_templates( array(), 'wp_template' ) as $template ) {
			$out[ $template->slug ] = self::shows_title_h1( parse_blocks( (string) $template->content ), 0 );
		}
		return $out;
	}

	/**
	 * Gabarit que WordPress prendra pour cette publication si aucun n'est choisi : le premier
	 * de la hiérarchie qui existe. Chaîne vide si aucun n'existe (thème classique).
	 *
	 * @param \WP_Post            $post  Publication ouverte dans l'éditeur.
	 * @param array<string, bool> $known Gabarits du site (title_templates()).
	 */
	public static function default_template( \WP_Post $post, array $known ): string {
		$type = $post->post_type;
		if ( 'page' === $type ) {
			$candidates = array( 'page-' . $post->post_name, 'page-' . $post->ID, 'page', 'singular', 'index' );
			// Page d'accueil statique : front-page passe devant toute la hiérarchie des pages.
			if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post->ID ) {
				array_unshift( $candidates, 'front-page' );
			}
		} else {
			$candidates = array( 'single-' . $type . '-' . $post->post_name, 'single-' . $type, 'single', 'singular', 'index' );
		}
		foreach ( $candidates as $slug ) {
			if ( isset( $known[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Un bloc Titre de la publication de niveau 1 se trouve dans ces blocs, parties de gabarit
	 * et compositions comprises.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Blocs analysés (parse_blocks).
	 * @param int                                     $depth  Profondeur de parties et compositions suivies.
	 */
	public static function shows_title_h1( array $blocks, int $depth ): bool {
		if ( $depth > self::MAX_DEPTH ) {
			return false;
		}
		foreach ( $blocks as $block ) {
			$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			// Le bloc Titre de la publication est un H2 sans attribut level.
			if ( 'core/post-title' === $name && 1 === (int) ( $attrs['level'] ?? 2 ) ) {
				return true;
			}
			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			if ( self::shows_title_h1( $inner, $depth ) ) {
				return true;
			}
			$nested = self::referenced( $name, $attrs );
			if ( '' !== $nested && self::shows_title_h1( parse_blocks( $nested ), $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Contenu d'une partie de gabarit ou d'une composition appelée par un bloc, sinon chaîne vide.
	 *
	 * @param string               $name  Nom du bloc.
	 * @param array<string, mixed> $attrs Attributs du bloc.
	 */
	private static function referenced( string $name, array $attrs ): string {
		$slug = isset( $attrs['slug'] ) && is_string( $attrs['slug'] ) ? $attrs['slug'] : '';
		if ( '' === $slug ) {
			return '';
		}
		if ( 'core/template-part' === $name ) {
			$theme = isset( $attrs['theme'] ) && is_string( $attrs['theme'] ) ? $attrs['theme'] : get_stylesheet();
			$part  = get_block_template( $theme . '//' . $slug, 'wp_template_part' );
			return $part ? (string) $part->content : '';
		}
		if ( 'core/pattern' === $name ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
			return is_array( $pattern ) && isset( $pattern['content'] ) ? (string) $pattern['content'] : '';
		}
		return '';
	}
}
