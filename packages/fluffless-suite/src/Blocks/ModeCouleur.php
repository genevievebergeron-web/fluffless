<?php
/**
 * Bloc fluffless/mode-couleur : le bouton « Mode sombre » des visiteurs, et ce qui le fait
 * marcher sur toutes les pages quand le mode clair ou sombre est actif (écran Palette) : la
 * feuille light-dark(), le script en ligne dans <head>, et l'insertion après le menu par les
 * Block Hooks. Rien n'est servi quand la fonction est éteinte ou que la jumelle échoue.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Blocks;

use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Presentation\Scheme;
use WP_Block_Template;
use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistrement, rendu, feuille, script et insertion du bouton. Inscrit par le module Palette.
 */
final class ModeCouleur {

	public const NAME   = 'fluffless/mode-couleur';
	public const STYLE  = 'fluffless-scheme';
	public const SCRIPT = 'fluffless-scheme';

	/** Clé du stockage local qui garde le choix de la personne (la même que head.js). */
	public const KEY = 'fluffless-scheme';

	/** Bloc après lequel le bouton s'insère de lui-même. */
	public const ANCHOR = 'core/navigation';

	/** Motifs où le bouton s'insère (le menu partagé des en-têtes du thème). */
	public const PATTERNS = array( 'fluffless/menu' );

	/** Inscrit les crochets du bouton. */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_action( 'wp_head', array( self::class, 'print_script' ), 1 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
		add_filter( 'hooked_block_types', array( self::class, 'hook' ), 10, 4 );
		add_filter( 'hooked_block_' . self::NAME, array( self::class, 'hooked' ), 10, 4 );
		add_filter( 'fluffless_consent_storage', array( self::class, 'storage' ) );
		add_filter( 'fluffless_visitor_scripts', array( self::class, 'declare_visitor_scripts' ) );
	}

	/**
	 * Déclare au module Palette le script en ligne du choix clair ou sombre, mesuré tel quel,
	 * quand la fonction est active : il est servi sur toutes les pages des visiteurs.
	 *
	 * @param mixed $scripts Déclarations déjà posées par les autres composants.
	 * @return array<mixed>
	 */
	public static function declare_visitor_scripts( $scripts ): array {
		$scripts = is_array( $scripts ) ? $scripts : array();
		if ( Scheme::active() ) {
			$declared                 = $scripts[ Palette::id() ] ?? array();
			$scripts[ Palette::id() ] = array_merge( is_array( $declared ) ? $declared : array(), array( strlen( self::script() ) ) );
		}
		return $scripts;
	}

	/**
	 * Déclare la clé du choix à fluffless-consent, pour la politique de confidentialité, quand
	 * la fonction est active.
	 *
	 * @param mixed $items Réglages déjà déclarés.
	 * @return array<mixed>
	 */
	public static function storage( $items ): array {
		$items = (array) $items;
		if ( Scheme::active() ) {
			$items[ self::KEY ] = array( 'label' => __( 'Dark mode choice', 'fluffless-suite' ) );
		}
		return $items;
	}

	/** Enregistre le bloc (rendu serveur). */
	public static function register_block(): void {
		register_block_type( dirname( FILE ) . '/blocks/mode-couleur', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	/**
	 * Bouton à bascule au nom fixe : l'état passe par aria-pressed, que le script tient juste.
	 * Caché par la feuille tant que le script n'a pas vérifié que le navigateur comprend
	 * light-dark(). Rien quand la fonction est éteinte.
	 *
	 * @param array<mixed> $attributes Attributs (aucun).
	 */
	public static function render( $attributes = array() ): string {
		unset( $attributes );
		if ( ! Scheme::active() ) {
			return '';
		}
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : 'class="wp-block-fluffless-mode-couleur"';
		return '<div ' . $wrapper . '><button type="button" class="fl-scheme-toggle" aria-pressed="false">'
			. '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/></svg>'
			. '<span>' . esc_html__( 'Dark mode', 'fluffless-suite' ) . '</span></button></div>';
	}

	/** Feuille du visiteur, après les styles globaux qu'elle redéfinit. */
	public static function enqueue(): void {
		if ( ! Scheme::active() ) {
			return;
		}
		$twin = Scheme::current_twin();
		if ( null === $twin ) {
			return;
		}
		$deps = wp_style_is( 'global-styles', 'registered' ) ? array( 'global-styles' ) : array();
		wp_register_style( self::STYLE, false, $deps, VERSION );
		wp_enqueue_style( self::STYLE );
		wp_add_inline_style( self::STYLE, Scheme::css( Palette::current(), $twin ) );
	}

	/** Script du choix, sur toutes les pages dès que la fonction est active (pas seulement là où le bouton paraît). */
	public static function print_script(): void {
		if ( ! Scheme::active() ) {
			return;
		}
		wp_print_inline_script_tag(
			self::script(),
			array(
				'id'               => self::SCRIPT . '-js',
				'data-no-optimize' => '1',
				'data-no-defer'    => '1',
				'data-cfasync'     => 'false',
			)
		);
	}

	/** Source du script, sans son commentaire d'en-tête. */
	public static function script(): string {
		$source = (string) file_get_contents( dirname( FILE ) . '/blocks/mode-couleur/head.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local de l'extension.
		return trim( (string) preg_replace( '#^\s*/\*.*?\*/\s*#s', '', $source ) );
	}

	/**
	 * Ajoute le bouton après le menu, quand la fonction est active, dans un en-tête : partie de
	 * gabarit de zone « header », motif d'en-tête ou menu partagé du thème.
	 *
	 * @param mixed  $hooked   Blocs déjà accrochés.
	 * @param string $position before, after, first_child ou last_child.
	 * @param string $anchor   Bloc d'ancrage.
	 * @param mixed  $context  Gabarit, partie, motif ou publication.
	 * @return mixed
	 */
	public static function hook( $hooked, $position, $anchor, $context ) {
		if ( ! is_array( $hooked ) || 'after' !== $position || self::ANCHOR !== $anchor || ! self::in_header( $context ) || ! Scheme::active() ) {
			return $hooked;
		}
		$hooked[] = self::NAME;
		return $hooked;
	}

	/**
	 * Écarte le bouton après un menu vertical (le menu des petits écrans, dans son panneau) :
	 * il reste un seul bouton, à côté du menu horizontal.
	 *
	 * @param mixed  $parsed   Bloc accroché analysé, ou null.
	 * @param string $type     Type du bloc accroché.
	 * @param string $position Position.
	 * @param mixed  $anchor   Bloc d'ancrage analysé.
	 * @return mixed
	 */
	public static function hooked( $parsed, $type = '', $position = '', $anchor = null ) {
		unset( $type, $position );
		$layout = is_array( $anchor ) ? ( $anchor['attrs']['layout'] ?? array() ) : array();
		if ( is_array( $layout ) && 'vertical' === ( $layout['orientation'] ?? '' ) ) {
			return null;
		}
		return $parsed;
	}

	/**
	 * Le contexte est-il un en-tête ?
	 *
	 * @param mixed $context Gabarit, partie, motif ou publication.
	 */
	public static function in_header( $context ): bool {
		if ( $context instanceof WP_Block_Template ) {
			return 'wp_template_part' === $context->type && 'header' === $context->area;
		}
		if ( is_array( $context ) ) {
			$slug       = is_string( $context['slug'] ?? null ) ? $context['slug'] : '';
			$categories = is_array( $context['categories'] ?? null ) ? $context['categories'] : array();
			$types      = is_array( $context['blockTypes'] ?? null ) ? $context['blockTypes'] : array();
			return in_array( $slug, self::PATTERNS, true ) || in_array( 'header', $categories, true ) || in_array( 'core/template-part/header', $types, true );
		}
		return false;
	}

	/** Données de l'éditeur : état de la fonction et lien vers l'écran Palette. */
	public static function editor_data(): void {
		$handle = generate_block_asset_handle( self::NAME, 'editorScript' );
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}
		wp_add_inline_script(
			$handle,
			'window.flufflessModeCouleur=' . wp_json_encode(
				array(
					'active' => Scheme::active(),
					'url'    => current_user_can( 'edit_theme_options' ) ? admin_url( 'admin.php?page=' . Palette::PAGE ) : '',
				)
			) . ';',
			'before'
		);
	}
}
