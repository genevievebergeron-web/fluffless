<?php
/**
 * Gabarits de pages enregistrés par l'agence : une page existante devient une carte de l'écran
 * de départ, offerte comme les compositions de pages du thème et de la Suite.
 *
 * Rangés dans l'option fluffless_page_templates (jamais autochargée), inscrits comme compositions
 * de blocs (core/post-content, pages) hors de l'outil d'insertion, et transportés d'un site à
 * l'autre par l'export des réglages de la Suite.
 *
 * @package Fluffless\Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules\Editor;

use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Lifecycle;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture, enregistrement, retrait et exposition REST des gabarits de pages.
 *
 * @phpstan-type Template array{id: string, title: string, content: string}
 */
final class Templates {

	public const OPTION  = Lifecycle::OPTION_PAGE_TEMPLATES;
	public const PREFIX  = 'fluffless-page/';
	public const REST_NS = 'fluffless/v1';
	public const ROUTE   = '/page-templates';

	/** Nombre de gabarits gardés. */
	public const MAX = 20;

	/** Longueur du contenu d'un gabarit (blocs sérialisés). */
	public const MAX_BYTES = 102400;

	/** Longueur du titre. */
	public const MAX_TITLE = 80;

	/** Crochets : compositions à l'init, routes REST. */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_patterns' ), 20 );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Gabarits enregistrés, assainis.
	 *
	 * @return list<Template>
	 */
	public static function all(): array {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Liste propre : identifiants uniques, titres bornés, contenus bornés avec au moins un bloc, au plus MAX entrées.
	 *
	 * @param mixed $raw Valeur brute (option, export).
	 * @return list<Template>
	 */
	public static function sanitize( $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id      = sanitize_key( (string) ( $entry['id'] ?? '' ) );
			$title   = self::clean_title( $entry['title'] ?? '' );
			$content = is_string( $entry['content'] ?? null ) ? trim( $entry['content'] ) : '';
			if ( '' === $id || '' === $title || '' === $content || strlen( $content ) > self::MAX_BYTES || isset( $out[ $id ] ) || ! self::has_blocks( $content ) ) {
				continue;
			}
			$out[ $id ] = array(
				'id'      => $id,
				'title'   => $title,
				'content' => $content,
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return array_values( $out );
	}

	/**
	 * Un gabarit par identifiant.
	 *
	 * @param string $id Identifiant.
	 * @return Template|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $template ) {
			if ( $template['id'] === $id ) {
				return $template;
			}
		}
		return null;
	}

	/**
	 * Enregistre une page comme gabarit. Un titre déjà pris remplace le gabarit du même nom.
	 * Le contenu passe par kses quand le compte n'a pas unfiltered_html, comme une page.
	 *
	 * @param string $title   Titre affiché sur la carte.
	 * @param string $content Blocs sérialisés de la page.
	 * @return Template|WP_Error
	 */
	public static function save( string $title, string $content ) {
		$title = self::clean_title( $title );
		if ( '' === $title ) {
			return new WP_Error( 'fluffless_template_title', __( 'Give the template a name.', 'fluffless-suite' ), array( 'status' => 400 ) );
		}
		$content = trim( current_user_can( 'unfiltered_html' ) ? $content : wp_kses_post( $content ) );
		if ( strlen( $content ) > self::MAX_BYTES ) {
			return new WP_Error( 'fluffless_template_size', __( 'This page is too large to keep as a template.', 'fluffless-suite' ), array( 'status' => 400 ) );
		}
		if ( ! self::has_blocks( $content ) ) {
			return new WP_Error( 'fluffless_template_empty', __( 'The page has no block to keep.', 'fluffless-suite' ), array( 'status' => 400 ) );
		}
		$id = sanitize_key( sanitize_title( $title ) );
		if ( '' === $id ) {
			$id = 'gabarit-' . substr( md5( $title ), 0, 8 );
		}
		$template  = array(
			'id'      => $id,
			'title'   => $title,
			'content' => $content,
		);
		$templates = self::all();
		$replaced  = false;
		foreach ( $templates as $i => $existing ) {
			if ( $existing['id'] === $id ) {
				$templates[ $i ] = $template;
				$replaced        = true;
			}
		}
		if ( ! $replaced ) {
			if ( count( $templates ) >= self::MAX ) {
				return new WP_Error( 'fluffless_template_full', __( 'The limit of saved templates is reached. Remove one first.', 'fluffless-suite' ), array( 'status' => 400 ) );
			}
			$templates[] = $template;
		}
		self::store( $templates );
		return $template;
	}

	/**
	 * Retire un gabarit.
	 *
	 * @param string $id Identifiant.
	 * @return bool Vrai s'il existait.
	 */
	public static function delete( string $id ): bool {
		$templates = self::all();
		$kept      = array_values( array_filter( $templates, static fn( array $t ): bool => $t['id'] !== $id ) );
		if ( count( $kept ) === count( $templates ) ) {
			return false;
		}
		self::store( $kept );
		return true;
	}

	/**
	 * Remplace toute la liste (import des réglages).
	 *
	 * @param array<int, array<string, string>> $templates Liste assainie.
	 * @phpstan-param list<Template> $templates
	 */
	public static function store( array $templates ): void {
		if ( array() === $templates ) {
			delete_option( self::OPTION );
			return;
		}
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $templates, '', false );
			return;
		}
		update_option( self::OPTION, $templates, false );
	}

	/**
	 * Nom de composition d'un gabarit.
	 *
	 * @param string $id Identifiant.
	 */
	public static function pattern_name( string $id ): string {
		return self::PREFIX . $id;
	}

	/** Chaque gabarit devient une composition de page, hors de l'outil d'insertion. */
	public static function register_patterns(): void {
		foreach ( self::all() as $template ) {
			register_block_pattern(
				self::pattern_name( $template['id'] ),
				array(
					'title'      => $template['title'],
					'content'    => $template['content'],
					'blockTypes' => array( 'core/post-content' ),
					'postTypes'  => array( 'page' ),
					'inserter'   => false,
					'source'     => 'plugin',
				)
			);
		}
	}

	/** Routes : enregistrer (POST) et retirer (DELETE), pour qui gère les options. */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_save' ),
				'permission_callback' => array( self::class, 'permission' ),
				'args'                => array(
					'title'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NS,
			self::ROUTE . '/(?P<id>[a-z0-9_-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'rest_delete' ),
				'permission_callback' => array( self::class, 'permission' ),
			)
		);
	}

	/**
	 * Qui gère les options enregistre et retire des gabarits.
	 *
	 * @return true|WP_Error
	 */
	public static function permission() {
		if ( current_user_can( SettingsPage::CAPABILITY ) ) {
			return true;
		}
		return new WP_Error( 'fluffless_template_forbidden', __( 'You are not allowed to manage page templates.', 'fluffless-suite' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * POST : enregistre et répond avec la carte prête pour l'écran de départ.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_save( WP_REST_Request $request ) {
		$saved = self::save( (string) $request->get_param( 'title' ), (string) $request->get_param( 'content' ) );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}
		return new WP_REST_Response( self::card( $saved ) + array( 'content' => $saved['content'] ), 201 );
	}

	/**
	 * DELETE : retire, 404 s'il n'existe pas.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_delete( WP_REST_Request $request ) {
		$id = sanitize_key( (string) $request->get_param( 'id' ) );
		if ( ! self::delete( $id ) ) {
			return new WP_Error( 'fluffless_template_missing', __( 'This template no longer exists.', 'fluffless-suite' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( array( 'deleted' => $id ) );
	}

	/**
	 * Carte de l'écran de départ (sans le contenu : l'éditeur le lit par les compositions).
	 *
	 * @param array<string, string> $template Gabarit.
	 * @phpstan-param Template $template
	 * @return array{name: string, id: string, title: string, description: string, icon: string, saved: bool, questions: list<never>}
	 */
	public static function card( array $template ): array {
		return array(
			'name'        => self::pattern_name( $template['id'] ),
			'id'          => $template['id'],
			'title'       => $template['title'],
			'description' => __( 'A page saved as a template on this site.', 'fluffless-suite' ),
			'icon'        => 'clipboard-note',
			'saved'       => true,
			'questions'   => array(),
		);
	}

	/**
	 * Titre propre, borné.
	 *
	 * @param mixed $raw Valeur brute.
	 */
	private static function clean_title( $raw ): string {
		return is_string( $raw ) ? mb_substr( sanitize_text_field( $raw ), 0, self::MAX_TITLE ) : '';
	}

	/**
	 * Au moins un bloc nommé dans le contenu.
	 *
	 * @param string $content Blocs sérialisés.
	 */
	private static function has_blocks( string $content ): bool {
		foreach ( parse_blocks( $content ) as $block ) {
			if ( is_string( $block['blockName'] ?? null ) && '' !== $block['blockName'] ) {
				return true;
			}
		}
		return false;
	}
}
