<?php
/**
 * Réglages du plan de site natif de WordPress, sans moteur parallèle.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Exclusions, date de modification et retrait des contenus en noindex.
 * Les images ne sont pas ajoutées : le rendu natif n'accepte que loc, lastmod, changefreq
 * et priority (wp-includes/sitemaps/class-wp-sitemaps-renderer.php).
 */
final class Sitemaps {

	/** Pose les filtres. */
	public static function register(): void {
		add_filter( 'wp_sitemaps_post_types', array( self::class, 'post_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( self::class, 'taxonomies' ) );
		add_filter( 'wp_sitemaps_add_provider', array( self::class, 'provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'posts_query_args' ) );
		add_filter( 'pre_handle_404', array( self::class, 'pre_handle_404' ) );
		if ( Settings::on( 'sitemap_lastmod' ) ) {
			add_filter( 'wp_sitemaps_posts_entry', array( self::class, 'posts_entry' ), 10, 2 );
		}
	}

	/**
	 * Plan de site servi en 200. Sur fluffless.dev (WordPress 7.1, sept. 2026), /wp-sitemap.xml et
	 * ses sous-plans sortaient en 404 : la requête ne trouve aucun contenu et WP::handle_404() peut
	 * la marquer introuvable avant le rendu du plan. Non reproduit sur un banc neuf en 7.1.3 ; garde
	 * préventive, sans effet hors du plan de site. Un moteur ignore un plan en 404.
	 *
	 * @param mixed $preempt Décision déjà prise par un autre filtre.
	 * @return mixed
	 */
	public static function pre_handle_404( $preempt ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$sitemap = get_query_var( 'sitemap' );
		return is_string( $sitemap ) && '' !== $sitemap ? true : $preempt;
	}

	/**
	 * Types exclus retirés.
	 *
	 * @param mixed $types Types du plan.
	 * @return mixed
	 */
	public static function post_types( $types ) {
		return is_array( $types ) ? array_diff_key( $types, array_flip( Settings::list( 'sitemap_exclude' ) ) ) : $types;
	}

	/**
	 * Taxonomies exclues ou en noindex retirées.
	 *
	 * @param mixed $taxonomies Taxonomies du plan.
	 * @return mixed
	 */
	public static function taxonomies( $taxonomies ) {
		$drop = array_merge( Settings::list( 'sitemap_exclude_tax' ), Settings::list( 'noindex_taxonomies' ) );
		return is_array( $taxonomies ) ? array_diff_key( $taxonomies, array_flip( $drop ) ) : $taxonomies;
	}

	/**
	 * Pages d'auteur retirées sauf demande (et toujours si elles sont en noindex).
	 *
	 * @param mixed  $provider Fournisseur.
	 * @param string $name     Nom du fournisseur.
	 * @return mixed
	 */
	public static function provider( $provider, $name ) {
		if ( 'users' === $name && ( ! Settings::on( 'sitemap_users' ) || in_array( 'author', Settings::list( 'noindex_archives' ), true ) ) ) {
			return false;
		}
		return $provider;
	}

	/**
	 * Contenus en noindex retirés de la requête du plan.
	 *
	 * @param mixed $args Arguments de WP_Query.
	 * @return mixed
	 */
	public static function posts_query_args( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$meta               = is_array( $args['meta_query'] ?? null ) ? $args['meta_query'] : array();
		$meta[]             = array(
			'relation' => 'OR',
			array(
				'key'     => Meta::ROBOTS,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Meta::ROBOTS,
				'value'   => '"noindex"',
				'compare' => 'NOT LIKE',
			),
		);
		$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- seulement à la génération du plan de site.
		return $args;
	}

	/**
	 * Date de modification ajoutée à chaque entrée.
	 *
	 * @param mixed $entry Entrée.
	 * @param mixed $post  Contenu.
	 * @return mixed
	 */
	public static function posts_entry( $entry, $post ) {
		if ( is_array( $entry ) && $post instanceof \WP_Post ) {
			$modified = get_post_modified_time( 'c', true, $post );
			if ( is_string( $modified ) ) {
				$entry['lastmod'] = $modified;
			}
		}
		return $entry;
	}
}
