<?php
/**
 * Panneau SEO de l'éditeur de blocs : JavaScript natif, sans étape de build.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

use const Fluffless\Seo\FILE;
use const Fluffless\Seo\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Chargé dans l'éditeur seulement ; le site public ne reçoit aucun script.
 * L'analyse du mot-clé tourne dans le navigateur de la rédactrice, sans réseau ni IA.
 */
final class Editor {

	public const HANDLE          = 'fluffless-seo-editor';
	public const ANALYSIS_HANDLE = 'fluffless-seo-analysis';

	/** Pose le crochet. */
	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	/** Scripts du panneau, pour les types de contenu publics. */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = null !== $screen ? $screen->post_type : '';
		if ( '' === $type || ! array_key_exists( $type, Settings::public_types() ) ) {
			return;
		}
		$base = plugins_url( 'assets/', FILE );
		wp_register_script( self::ANALYSIS_HANDLE, $base . 'analysis.js', array(), VERSION, true );
		wp_enqueue_script(
			self::HANDLE,
			$base . 'editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-block-editor', self::ANALYSIS_HANDLE ),
			VERSION,
			true
		);
		wp_add_inline_script( self::HANDLE, 'window.flufflessSeo=' . wp_json_encode( self::data( $type ) ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'fluffless-seo', dirname( FILE ) . '/languages' );
	}

	/**
	 * Données du panneau : gabarits effectifs du type, variables du site, choix possibles.
	 *
	 * @param string $type Type de contenu édité.
	 * @return array<string, mixed>
	 */
	public static function data( string $type ): array {
		return array(
			'titleTemplate' => Context::template( $type, 'title' ),
			'descTemplate'  => Context::template( $type, 'desc' ),
			'sitename'      => Context::plain( get_bloginfo( 'name' ) ),
			'tagline'       => Context::plain( get_bloginfo( 'description' ) ),
			'sep'           => Settings::string( 'sep' ),
			'home'          => home_url( '/' ),
			'analysis'      => Settings::on( 'analysis' ),
			'social'        => Settings::on( 'social' ),
			'schema'        => Settings::on( 'schema' ),
			'defaultSchema' => 'post' === $type ? 'Article' : 'none',
			'schemaTypes'   => array_values( array_filter( Meta::SCHEMA_TYPES ) ),
		);
	}
}
