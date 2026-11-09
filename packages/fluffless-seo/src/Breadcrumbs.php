<?php
/**
 * Fil d'Ariane : chemin de la page courante, bloc serveur et nœud BreadcrumbList.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

use const Fluffless\Seo\FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Un seul calcul du chemin sert au bloc affiché et au schéma : les deux ne peuvent pas diverger.
 */
final class Breadcrumbs {

	/** Profondeur maximale (pages parentes, catégories parentes). */
	private const MAX_DEPTH = 10;

	/**
	 * Chemin calculé dans la requête.
	 *
	 * @var list<array{0: string, 1: string}>|null
	 */
	private static ?array $trail = null;

	/** Oublie le chemin calculé (tests). */
	public static function reset(): void {
		self::$trail = null;
	}

	/** Enregistre le bloc fluffless-seo/fil-ariane (rendu serveur, aucun script visiteur). */
	public static function register_block(): void {
		$type = register_block_type(
			dirname( FILE ) . '/blocks/fil-ariane',
			array( 'render_callback' => array( self::class, 'render' ) )
		);
		if ( $type instanceof \WP_Block_Type ) {
			foreach ( $type->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'fluffless-seo', dirname( FILE ) . '/languages' );
			}
		}
	}

	/**
	 * Chemin de la page : liste de [libellé, adresse], de l'accueil à la page courante.
	 * La dernière adresse peut être vide (recherche, 404).
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public static function trail(): array {
		if ( null !== self::$trail ) {
			return self::$trail;
		}
		$label = Settings::string( 'bc_home' );
		$trail = array( array( '' !== $label ? $label : __( 'Home', 'fluffless-seo' ), home_url( '/' ) ) );
		if ( is_front_page() ) {
			self::$trail = $trail;
			return $trail;
		}
		$object  = get_queried_object();
		$current = Context::canonical();
		if ( is_singular() && $object instanceof \WP_Post ) {
			$trail   = array_merge( $trail, self::post_parents( $object ) );
			$trail[] = array( Context::plain( get_the_title( $object ) ), $current );
		} elseif ( is_home() && $object instanceof \WP_Post ) {
			$trail[] = array( Context::plain( get_the_title( $object ) ), $current );
		} elseif ( $object instanceof \WP_Term ) {
			$trail   = array_merge( $trail, self::term_parents( $object ) );
			$trail[] = array( Context::plain( $object->name ), $current );
		} elseif ( is_search() ) {
			$trail[] = array( __( 'Search results', 'fluffless-seo' ), '' );
		} elseif ( is_404() ) {
			$trail[] = array( __( 'Page not found', 'fluffless-seo' ), '' );
		} else {
			$title = Context::plain( (string) get_the_archive_title() );
			if ( '' !== $title ) {
				$trail[] = array( $title, $current );
			}
		}
		self::$trail = $trail;
		return $trail;
	}

	/**
	 * Parents d'un contenu : page des articles et catégorie (articles), archive (types avec archive), pages parentes.
	 *
	 * @param \WP_Post $post Contenu.
	 * @return list<array{0: string, 1: string}>
	 */
	private static function post_parents( \WP_Post $post ): array {
		$parents = array();
		if ( 'post' === $post->post_type ) {
			$blog = (int) get_option( 'page_for_posts' );
			if ( $blog > 0 && 'page' === get_option( 'show_on_front' ) ) {
				$parents[] = array( Context::plain( get_the_title( $blog ) ), (string) get_permalink( $blog ) );
			}
			$terms = get_the_terms( $post, 'category' );
			if ( is_array( $terms ) && isset( $terms[0] ) ) {
				$parents   = array_merge( $parents, self::term_parents( $terms[0] ) );
				$link      = get_term_link( $terms[0] );
				$parents[] = array( Context::plain( $terms[0]->name ), is_string( $link ) ? $link : '' );
			}
			return $parents;
		}
		$type = get_post_type_object( $post->post_type );
		if ( null !== $type && $type->has_archive ) {
			$link      = get_post_type_archive_link( $post->post_type );
			$parents[] = array( Context::plain( (string) $type->labels->name ), is_string( $link ) ? $link : '' );
		}
		$ancestors = array_slice( array_reverse( get_post_ancestors( $post ) ), 0, self::MAX_DEPTH );
		// Une seule requête pour tous les parents, au lieu d'une par parent.
		_prime_post_caches( $ancestors, false, false );
		foreach ( $ancestors as $ancestor ) {
			$parents[] = array( Context::plain( get_the_title( $ancestor ) ), (string) get_permalink( $ancestor ) );
		}
		return $parents;
	}

	/**
	 * Termes parents d'un terme, du plus haut au plus proche.
	 *
	 * @param \WP_Term $term Terme.
	 * @return list<array{0: string, 1: string}>
	 */
	private static function term_parents( \WP_Term $term ): array {
		$parents = array();
		foreach ( array_slice( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ), 0, self::MAX_DEPTH ) as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof \WP_Term ) {
				$link      = get_term_link( $ancestor );
				$parents[] = array( Context::plain( $ancestor->name ), is_string( $link ) ? $link : '' );
			}
		}
		return $parents;
	}

	/**
	 * Nœud BreadcrumbList ; null s'il n'y a que l'accueil.
	 *
	 * @param string $page_url Adresse canonique de la page.
	 * @return array<string, mixed>|null
	 */
	public static function schema( string $page_url ): ?array {
		$trail = self::trail();
		if ( count( $trail ) < 2 ) {
			return null;
		}
		$items = array();
		foreach ( $trail as $index => [ $name, $url ] ) {
			$item = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $name,
			);
			if ( '' !== $url ) {
				$item['item'] = $url;
			}
			$items[] = $item;
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $page_url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * Rendu du bloc : liste ordonnée dans un nav, page courante marquée aria-current.
	 *
	 * @param mixed $attributes Attributs du bloc.
	 */
	public static function render( $attributes ): string {
		$trail = self::trail();
		if ( count( $trail ) < 2 ) {
			return '';
		}
		$separator = is_array( $attributes ) && is_string( $attributes['separator'] ?? null ) ? mb_substr( $attributes['separator'], 0, 3 ) : '›';
		$last      = count( $trail ) - 1;
		$items     = array();
		foreach ( $trail as $index => [ $name, $url ] ) {
			$text    = $index === $last || '' === $url
				? '<span' . ( $index === $last ? ' aria-current="page"' : '' ) . '>' . esc_html( $name ) . '</span>'
				: '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
			$sep     = $index < $last && '' !== $separator ? ' <span aria-hidden="true">' . esc_html( $separator ) . '</span>' : '';
			$items[] = '<li>' . $text . $sep . '</li>';
		}
		$wrapper = get_block_wrapper_attributes( array( 'aria-label' => __( 'Breadcrumb', 'fluffless-seo' ) ) );
		return '<nav ' . $wrapper . '><ol style="list-style:none;display:flex;flex-wrap:wrap;gap:.25em .5em;margin:0;padding:0">' . implode( '', $items ) . '</ol></nav>';
	}
}
