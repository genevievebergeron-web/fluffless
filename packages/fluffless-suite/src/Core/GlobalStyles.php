<?php
/**
 * Styles globaux de l'utilisateur (wp_global_styles) : lecture et écriture par chemin pointé.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Partagé par les modules qui écrivent dans les styles globaux (Forme, Palette). Chacun garde
 * la valeur d'origine des clés qu'il touche et la remet au retour aux valeurs du thème.
 */
final class GlobalStyles {

	/**
	 * Styles globaux de l'utilisateur pour le thème actif (créés au besoin).
	 *
	 * @return array{id: int, data: array<string, mixed>}|null Null hors thème de blocs.
	 */
	public static function user(): ?array {
		if ( ! class_exists( WP_Theme_JSON_Resolver::class ) || ! wp_is_block_theme() ) {
			return null;
		}
		$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		if ( ! isset( $post['ID'] ) ) {
			return null;
		}
		$data = json_decode( is_string( $post['post_content'] ?? null ) ? $post['post_content'] : '', true );
		return array(
			'id'   => (int) $post['ID'],
			'data' => is_array( $data ) ? $data : array(
				'version'                     => 3,
				'isGlobalStylesUserThemeJSON' => true,
			),
		);
	}

	/**
	 * Valeur à un chemin pointé ; null si absente.
	 *
	 * @param array<string, mixed> $data Données.
	 * @param string               $path Chemin a.b.c.
	 * @return mixed
	 */
	public static function get( array $data, string $path ) {
		$node = $data;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return null;
			}
			$node = $node[ $key ];
		}
		return $node;
	}

	/**
	 * Pose une valeur à un chemin ; null retire la clé et les parents devenus vides.
	 *
	 * @param array<string, mixed> $data  Données.
	 * @param string               $path  Chemin a.b.c.
	 * @param mixed                $value Valeur, ou null.
	 * @return array<string, mixed>
	 */
	public static function put( array $data, string $path, $value ): array {
		$keys = explode( '.', $path );
		$key  = array_shift( $keys );
		if ( array() === $keys ) {
			if ( null === $value ) {
				unset( $data[ $key ] );
			} else {
				$data[ $key ] = $value;
			}
			return $data;
		}
		$child = is_array( $data[ $key ] ?? null ) ? $data[ $key ] : array();
		$child = self::put( $child, implode( '.', $keys ), $value );
		if ( array() === $child ) {
			unset( $data[ $key ] );
		} else {
			$data[ $key ] = $child;
		}
		return $data;
	}

	/**
	 * Enregistre les styles globaux et vide les caches de theme.json.
	 *
	 * @param int                  $id   Article wp_global_styles.
	 * @param array<string, mixed> $data Contenu complet.
	 */
	public static function save( int $id, array $data ): bool {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return false;
		}
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => $json,
				)
			),
			true
		);
		WP_Theme_JSON_Resolver::clean_cached_data();
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
		return ! is_wp_error( $result );
	}
}
