<?php
/**
 * Option unique fluffless_settings : lecture typée, assainissement, schéma REST.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Forme stockée (autoload) :
 * array{ version: int, modules: array<string, bool>, <id de module>: array<string, mixed> }
 */
final class Options {

	public const NAME   = 'fluffless_settings';
	public const GROUP  = 'fluffless';
	public const SCHEMA = 1;

	/** Sections de l'option tenues par d'autres extensions (Fluffless Consent) : Suite les garde sans les assainir. */
	public const FOREIGN = array( 'consent' );

	/**
	 * Réglages fusionnés avec les défauts, mis en cache pour la requête.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Déclaration déjà faite dans cette requête ?
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Déclare l'option à la Settings API et à l'API REST.
	 * Accrochée à admin_init et rest_api_init seulement : le visiteur n'en paie pas le coût.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		register_setting(
			self::GROUP,
			self::NAME,
			array(
				'type'              => 'object',
				'description'       => 'Fluffless Suite settings.',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => array( 'schema' => self::schema() ),
			)
		);
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . self::NAME, array( self::class, 'flush' ) );
		}
	}

	/** Vide le cache (après écriture, ou dans les tests). */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Valeurs par défaut de toute l'option, modules filtrés compris.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$modules  = array();
		$sections = array();
		foreach ( Loader::modules() as $id => $class ) {
			$modules[ $id ]  = $class::enabled_by_default();
			$sections[ $id ] = $class::defaults();
		}
		return array(
			'version' => self::SCHEMA,
			'modules' => $modules,
		) + $sections;
	}

	/**
	 * Réglages courants : valeurs stockées valides, défauts pour le reste.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored   = get_option( self::NAME, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = self::defaults();

		foreach ( Loader::modules() as $id => $class ) {
			$enabled = $stored['modules'][ $id ] ?? null;
			if ( is_bool( $enabled ) ) {
				$settings['modules'][ $id ] = $enabled;
			}
			$section = $stored[ $id ] ?? null;
			if ( is_array( $section ) ) {
				// Seules les clés connues, du même type que leur défaut, sont reprises.
				foreach ( $class::defaults() as $key => $default ) {
					if ( array_key_exists( $key, $section ) && gettype( $section[ $key ] ) === gettype( $default ) ) {
						$settings[ $id ][ $key ] = $section[ $key ];
					}
				}
			}
		}
		self::$cache = $settings;
		return $settings;
	}

	/**
	 * Le module est-il actif ? Un identifiant inconnu est considéré inactif.
	 *
	 * @param string $module Identifiant du module.
	 */
	public static function is_enabled( string $module ): bool {
		$modules = self::all()['modules'];
		return is_array( $modules ) && true === ( $modules[ $module ] ?? false );
	}

	/**
	 * Valeur brute d'un réglage de module ; null si la clé n'existe pas.
	 *
	 * @param string $module Identifiant du module.
	 * @param string $key    Clé du réglage.
	 * @return mixed
	 */
	public static function get( string $module, string $key ) {
		$section = self::all()[ $module ] ?? null;
		return is_array( $section ) ? ( $section[ $key ] ?? null ) : null;
	}

	/**
	 * Réglage booléen.
	 *
	 * @param string $module Identifiant du module.
	 * @param string $key    Clé du réglage.
	 */
	public static function get_bool( string $module, string $key ): bool {
		return true === self::get( $module, $key );
	}

	/**
	 * Réglage entier (0 si absent ou d'un autre type).
	 *
	 * @param string $module Identifiant du module.
	 * @param string $key    Clé du réglage.
	 */
	public static function get_int( string $module, string $key ): int {
		$value = self::get( $module, $key );
		return is_int( $value ) ? $value : 0;
	}

	/**
	 * Réglage texte ('' si absent ou d'un autre type).
	 *
	 * @param string $module Identifiant du module.
	 * @param string $key    Clé du réglage.
	 */
	public static function get_string( string $module, string $key ): string {
		$value = self::get( $module, $key );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Réglage liste de chaînes.
	 *
	 * @param string $module Identifiant du module.
	 * @param string $key    Clé du réglage.
	 * @return list<string>
	 */
	public static function get_list( string $module, string $key ): array {
		$value = self::get( $module, $key );
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Rappel sanitize_callback. Toute section absente garde sa valeur courante ;
	 * toute valeur invalide reprend son défaut ; les clés inconnues sont écartées.
	 *
	 * @param mixed $input Valeur reçue (formulaire options.php ou API REST).
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$current = self::all();
		if ( ! is_array( $input ) ) {
			return self::with_foreign( $current, array() );
		}
		$toggles  = is_array( $input['modules'] ?? null ) ? $input['modules'] : array();
		$was_on   = is_array( $current['modules'] ?? null ) ? $current['modules'] : array();
		$modules  = array();
		$sections = array();

		foreach ( Loader::modules() as $id => $class ) {
			$enabled        = array_key_exists( $id, $toggles ) ? Sanitize::to_bool( $toggles[ $id ] ) : null;
			$modules[ $id ] = $enabled ?? (bool) ( $was_on[ $id ] ?? $class::enabled_by_default() );

			$section         = is_array( $input[ $id ] ?? null ) ? $input[ $id ] : array();
			$base            = is_array( $current[ $id ] ?? null ) ? $current[ $id ] : array();
			$sections[ $id ] = $class::sanitize( $section + $base );
		}
		return self::with_foreign(
			array(
				'version' => self::SCHEMA,
				'modules' => $modules,
			) + $sections,
			$input
		);
	}

	/**
	 * Reprend les sections d'autres extensions (FOREIGN) : celle reçue, sinon celle stockée.
	 * Chaque extension assainit sa propre section ; Suite ne fait que ne pas la perdre.
	 *
	 * @param array<string, mixed> $settings Réglages de Suite, assainis.
	 * @param array<mixed>         $input    Valeur reçue.
	 * @return array<string, mixed>
	 */
	private static function with_foreign( array $settings, array $input ): array {
		$stored = get_option( self::NAME, array() );
		foreach ( self::FOREIGN as $key ) {
			$section = is_array( $input[ $key ] ?? null ) ? $input[ $key ] : ( is_array( $stored ) ? ( $stored[ $key ] ?? null ) : null );
			if ( is_array( $section ) ) {
				$settings[ $key ] = $section;
			}
		}
		return $settings;
	}

	/**
	 * Schéma JSON de l'option pour /wp/v2/settings, dérivé des champs déclarés.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$modules    = array();
		$properties = array(
			'version' => array( 'type' => 'integer' ),
		);
		foreach ( Loader::modules() as $id => $class ) {
			$modules[ $id ] = array( 'type' => 'boolean' );
			$fields         = array();
			foreach ( $class::fields() as $key => $field ) {
				$options        = array_keys( $field['options'] ?? array() );
				$fields[ $key ] = match ( $field['type'] ) {
					'bool'   => array( 'type' => 'boolean' ),
					'int'    => array(
						'type'    => 'integer',
						'minimum' => $field['min'] ?? 0,
						'maximum' => $field['max'] ?? PHP_INT_MAX,
					),
					'choice' => array(
						'type' => 'string',
						'enum' => $options,
					),
					'multi'  => array(
						'type'     => 'array',
						'maxItems' => Sanitize::MAX_LIST,
						'items'    => array(
							'type' => 'string',
							'enum' => $options,
						),
					),
					'hosts'  => array(
						'type'     => 'array',
						'maxItems' => Sanitize::MAX_LIST,
						'items'    => array( 'type' => 'string' ),
					),
					'text'   => array(
						'type'      => 'string',
						'maxLength' => Sanitize::MAX_TEXT,
					),
					'textarea' => array(
						'type'      => 'string',
						'maxLength' => Sanitize::MAX_LONG_TEXT,
					),
					'lines'  => array(
						'type'     => 'array',
						'maxItems' => Sanitize::MAX_LIST,
						'items'    => array(
							'type'      => 'string',
							'maxLength' => Sanitize::MAX_TEXT,
						),
					),
					'url'    => array(
						'type'      => 'string',
						'maxLength' => Sanitize::MAX_TEXT,
						'pattern'   => '^(https://.+)?$',
					),
				};
			}
			$properties[ $id ] = array(
				'type'                 => 'object',
				'properties'           => $fields,
				'additionalProperties' => false,
			);
		}
		// Sections d'autres extensions : admises telles quelles, leur extension les assainit (sanitize_option_*).
		// additionalProperties explicite : sinon l'API REST le force à false et rejette toute l'option.
		foreach ( self::FOREIGN as $key ) {
			$properties[ $key ] = array(
				'type'                 => 'object',
				'additionalProperties' => true,
			);
		}
		$properties['modules'] = array(
			'type'                 => 'object',
			'properties'           => $modules,
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}
}
