<?php
/**
 * Métadonnées SEO de chaque contenu, lisibles et modifiables par l'API REST (panneau d'éditeur).
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

use Fluffless\Suite\Core\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * La description reprend la clé de Fluffless Suite : passer du module léger à l'extension
 * complète garde les descriptions déjà écrites. Les métadonnées d'un contenu sont chargées
 * en une fois avec la requête principale (cache des métas) : les lire ne coûte aucune requête.
 */
final class Meta {

	public const DESCRIPTION = Lifecycle::META_SEO_DESCRIPTION;
	public const TITLE       = '_fluffless_seo_title';
	public const CANONICAL   = '_fluffless_seo_canonical';
	public const ROBOTS      = '_fluffless_seo_robots';
	public const OG_TITLE    = '_fluffless_seo_og_title';
	public const OG_DESC     = '_fluffless_seo_og_desc';
	public const OG_IMAGE    = '_fluffless_seo_og_image';
	public const KEYWORD     = '_fluffless_seo_keyword';
	public const SCHEMA      = '_fluffless_seo_schema';
	public const SCHEMA_DATA = '_fluffless_seo_schema_data';

	/** Clés propres à l'extension (supprimées à la désinstallation ; la description appartient aussi à Suite). */
	public const OWN_KEYS = array( self::TITLE, self::CANONICAL, self::ROBOTS, self::OG_TITLE, self::OG_DESC, self::OG_IMAGE, self::KEYWORD, self::SCHEMA, self::SCHEMA_DATA );

	/** Directives robots permises par contenu ; max-image-preview:large est déjà le défaut du cœur. */
	public const ROBOTS_VALUES = array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'max-image-preview:standard', 'max-image-preview:none' );

	/** Types schema.org choisissables par contenu ; '' = automatique, none = aucun nœud principal. */
	public const SCHEMA_TYPES = array( '', 'none', 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'AboutPage', 'ContactPage', 'Service', 'Event', 'Product', 'SoftwareApplication', 'Person' );

	/** Détails schema.org (événement, produit, service, personne), tous en texte. */
	public const SCHEMA_FIELDS = array( 'start', 'end', 'place', 'address', 'online', 'price', 'currency', 'availability', 'sku', 'brand', 'service_type', 'area', 'job_title', 'app_category', 'os' );

	private const MAX_TEXT = 300;

	/** Déclare les métadonnées pour tous les types de contenu. */
	public static function register(): void {
		$strings = array(
			self::DESCRIPTION => self::MAX_TEXT,
			self::TITLE       => 200,
			self::OG_TITLE    => 200,
			self::OG_DESC     => self::MAX_TEXT,
			self::KEYWORD     => 100,
		);
		foreach ( $strings as $key => $max ) {
			self::register_one(
				$key,
				array(
					'type'      => 'string',
					'maxLength' => $max,
				),
				'',
				static fn( $value ): string => is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, $max ) : ''
			);
		}
		self::register_one(
			self::CANONICAL,
			array(
				'type'      => 'string',
				'maxLength' => 2000,
			),
			'',
			array( self::class, 'sanitize_url' )
		);
		self::register_one(
			self::OG_IMAGE,
			array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			0,
			static fn( $value ): int => max( 0, (int) $value )
		);
		self::register_one(
			self::SCHEMA,
			array(
				'type' => 'string',
				'enum' => self::SCHEMA_TYPES,
			),
			'',
			static fn( $value ): string => is_string( $value ) && in_array( $value, self::SCHEMA_TYPES, true ) ? $value : ''
		);
		self::register_one(
			self::ROBOTS,
			array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
					'enum' => self::ROBOTS_VALUES,
				),
			),
			array(),
			array( self::class, 'sanitize_robots' )
		);
		$properties = array();
		foreach ( self::SCHEMA_FIELDS as $field ) {
			$properties[ $field ] = array(
				'type'      => 'string',
				'maxLength' => 200,
			);
		}
		self::register_one(
			self::SCHEMA_DATA,
			array(
				'type'                 => 'object',
				'properties'           => $properties,
				'additionalProperties' => false,
			),
			array(),
			array( self::class, 'sanitize_schema_data' )
		);
	}

	/**
	 * Une métadonnée, pour tous les types, modifiable par qui peut modifier le contenu.
	 *
	 * @param string               $key      Clé.
	 * @param array<string, mixed> $schema   Schéma REST.
	 * @param mixed                $fallback Valeur par défaut.
	 * @param callable             $sanitize Assainissement.
	 */
	private static function register_one( string $key, array $schema, $fallback, callable $sanitize ): void {
		register_post_meta(
			'',
			$key,
			array(
				'type'              => $schema['type'],
				'single'            => true,
				'default'           => $fallback,
				'show_in_rest'      => array( 'schema' => $schema ),
				'sanitize_callback' => $sanitize,
				'auth_callback'     => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
			)
		);
	}

	/**
	 * Adresse http(s) absolue, ou vide.
	 *
	 * @param mixed $value Valeur reçue.
	 */
	public static function sanitize_url( $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		$url = esc_url_raw( trim( $value ), array( 'http', 'https' ) );
		return false !== wp_http_validate_url( $url ) ? $url : '';
	}

	/**
	 * Directives connues seulement, sans doublon.
	 *
	 * @param mixed $value Valeur reçue.
	 * @return list<string>
	 */
	public static function sanitize_robots( $value ): array {
		$values = is_array( $value ) ? $value : array();
		return array_values( array_intersect( self::ROBOTS_VALUES, $values ) );
	}

	/**
	 * Détails schema.org : clés connues, texte brut borné.
	 *
	 * @param mixed $value Valeur reçue.
	 * @return array<string, string>
	 */
	public static function sanitize_schema_data( $value ): array {
		$clean = array();
		if ( ! is_array( $value ) ) {
			return $clean;
		}
		foreach ( self::SCHEMA_FIELDS as $field ) {
			if ( is_scalar( $value[ $field ] ?? null ) ) {
				$text = mb_substr( sanitize_text_field( (string) $value[ $field ] ), 0, 200 );
				if ( '' !== $text ) {
					$clean[ $field ] = $text;
				}
			}
		}
		return $clean;
	}

	/**
	 * Valeur texte d'un contenu.
	 *
	 * @param int    $post_id Contenu.
	 * @param string $key     Clé.
	 */
	public static function text( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Directives robots d'un contenu.
	 *
	 * @param int $post_id Contenu.
	 * @return list<string>
	 */
	public static function robots( int $post_id ): array {
		return self::sanitize_robots( get_post_meta( $post_id, self::ROBOTS, true ) );
	}

	/**
	 * Détails schema.org d'un contenu.
	 *
	 * @param int $post_id Contenu.
	 * @return array<string, string>
	 */
	public static function schema_data( int $post_id ): array {
		return self::sanitize_schema_data( get_post_meta( $post_id, self::SCHEMA_DATA, true ) );
	}
}
