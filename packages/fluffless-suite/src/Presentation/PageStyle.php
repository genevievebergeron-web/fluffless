<?php
/**
 * Style par page : une variation du thème appliquée à une seule page, aux couleurs du site.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

use WP_Theme_JSON_Data;
use WP_Theme_JSON_Resolver;
use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * La méta META porte le nom de fichier d'une variation (styles/<nom>.json du thème enfant ou
 * parent, sans les sous-dossiers typography/ et blocks/). Sur cette page, et dans son éditeur,
 * la variation est fusionnée par-dessus les styles du site, sans sa palette ni ses dégradés :
 * les couleurs restent celles du site, peu importe le style choisi.
 */
final class PageStyle {

	public const META    = '_fluffless_page_style';
	private const HANDLE = 'fluffless-page-style';

	/**
	 * Identifiant de la page en cours ; null tant qu'il n'est pas connu.
	 *
	 * @var int|null
	 */
	private static ?int $post = null;

	/**
	 * Variation imposée pour l'aperçu de l'éditeur ; null hors aperçu.
	 *
	 * @var string|null
	 */
	private static ?string $preview = null;

	/** Branche les crochets. */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_meta' ) );
		add_action( 'wp', array( self::class, 'front' ) );
		add_action( 'current_screen', array( self::class, 'screen' ) );
		add_filter( 'wp_theme_json_data_user', array( self::class, 'merge' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/** Route de l'aperçu : la feuille de styles du site avec une variation, aux couleurs du site. */
	public static function routes(): void {
		register_rest_route(
			'fluffless/v1',
			'/page-style/(?P<slug>[a-z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn(): bool => current_user_can( 'edit_pages' ),
				'callback'            => array( self::class, 'preview' ),
			)
		);
	}

	/**
	 * Feuille de styles calculée avec la variation demandée, ou sans variation pour une chaîne vide.
	 *
	 * @param \WP_REST_Request $request Requête.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview( \WP_REST_Request $request ) {
		$slug = (string) $request['slug'];
		if ( 'none' !== $slug && ! isset( self::choices()[ $slug ] ) ) {
			return new \WP_Error( 'fluffless_page_style', __( 'Unknown page style.', 'fluffless-suite' ), array( 'status' => 404 ) );
		}
		self::$preview = 'none' === $slug ? '' : $slug;
		WP_Theme_JSON_Resolver::clean_cached_data();
		$css           = wp_get_global_stylesheet( array( 'variables', 'presets', 'styles' ) );
		self::$preview = null;
		WP_Theme_JSON_Resolver::clean_cached_data();
		return rest_ensure_response( array( 'css' => $css ) );
	}

	/** Méta de la page, lisible et modifiable par l'API REST de l'éditeur. */
	public static function register_meta(): void {
		register_post_meta(
			'page',
			self::META,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => static fn( $value ): string => is_string( $value ) && isset( self::choices()[ $value ] ) ? $value : '',
				'auth_callback'     => static fn( $allowed, $key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
			)
		);
	}

	/**
	 * Variations offertes, nom de fichier => titre. Le thème enfant remplace un fichier du parent.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		static $choices = null;
		if ( null !== $choices ) {
			return $choices;
		}
		$choices = array();
		foreach ( array_unique( array( get_template_directory(), get_stylesheet_directory() ) ) as $dir ) {
			foreach ( (array) glob( $dir . '/styles/*.json' ) as $file ) {
				$data = wp_json_file_decode( (string) $file, array( 'associative' => true ) );
				if ( is_array( $data ) ) {
					$name = basename( (string) $file, '.json' );
					// phpcs:ignore WordPress.WP.I18n -- titre du thème, traduit dans son domaine comme le fait WordPress.
					$choices[ $name ] = is_string( $data['title'] ?? null ) ? translate_with_gettext_context( $data['title'], 'Style variation name', (string) wp_get_theme()->get( 'TextDomain' ) ) : $name;
				}
			}
		}
		return $choices;
	}

	/** Page affichée sur le site. */
	public static function front(): void {
		if ( is_page() ) {
			self::set( (int) get_queried_object_id() );
		}
	}

	/**
	 * Page ouverte dans l'éditeur.
	 *
	 * @param \WP_Screen $screen Écran courant.
	 */
	public static function screen( \WP_Screen $screen ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture seule de l'identifiant ouvert dans l'éditeur.
		$post = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		if ( 'page' === $screen->post_type && 'post' === $screen->base && $post > 0 ) {
			self::set( $post );
		}
	}

	/**
	 * Retient la page et vide le cache du résolveur si elle a un style, pour que la fusion
	 * s'applique même si les styles globaux ont déjà été lus plus tôt dans la requête.
	 *
	 * @param int $post Identifiant de la page.
	 */
	private static function set( int $post ): void {
		self::$post = $post;
		if ( '' !== self::slug() ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}
	}

	/** Variation choisie pour la page en cours, ou chaîne vide. */
	private static function slug(): string {
		if ( null !== self::$preview ) {
			return self::$preview;
		}
		if ( null === self::$post ) {
			return '';
		}
		$slug = get_post_meta( self::$post, self::META, true );
		return is_string( $slug ) && isset( self::choices()[ $slug ] ) ? $slug : '';
	}

	/**
	 * Fusionne la variation, sans ses couleurs, par-dessus les styles de la personne.
	 *
	 * @param WP_Theme_JSON_Data $data Styles de la personne.
	 */
	public static function merge( WP_Theme_JSON_Data $data ): WP_Theme_JSON_Data {
		$slug = self::slug();
		if ( '' === $slug ) {
			return $data;
		}
		$file = get_theme_file_path( 'styles/' . $slug . '.json' );
		$json = is_readable( $file ) ? wp_json_file_decode( $file, array( 'associative' => true ) ) : null;
		if ( ! is_array( $json ) ) {
			return $data;
		}
		unset( $json['settings']['color']['palette'], $json['settings']['color']['gradients'], $json['title'], $json['slug'] );
		$json['version'] = $json['version'] ?? 3;
		return $data->update_with( $json );
	}

	/** Panneau de la barre latérale, sur les pages seulement. */
	public static function editor_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'page' !== $screen->post_type || ! Patterns::applies() ) {
			return;
		}
		$url = plugins_url( 'assets/page-style.js', FILE );
		wp_enqueue_script( self::HANDLE, $url, array( 'wp-plugins', 'wp-element', 'wp-i18n', 'wp-components', 'wp-data', 'wp-core-data', 'wp-editor', 'wp-api-fetch' ), VERSION, true );
		wp_set_script_translations( self::HANDLE, 'fluffless-suite', dirname( FILE ) . '/languages' );
		$choices = array();
		foreach ( self::choices() as $value => $label ) {
			$choices[] = array(
				'value' => $value,
				'label' => $label,
			);
		}
		wp_add_inline_script( self::HANDLE, 'window.flufflessPageStyle = ' . wp_json_encode( array( 'choices' => $choices ) ) . ';', 'before' );
	}
}
