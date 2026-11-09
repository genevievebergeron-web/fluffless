<?php
/**
 * Chemin de navigation (fil d'Ariane) : la même liste de maillons pour le bloc Fil d'Ariane et
 * pour le JSON-LD BreadcrumbList du module SEO, pour qu'un visiteur et un moteur lisent la même
 * chose.
 *
 * Accueil, puis selon la requête : contenu singulier (page des articles ou archive du type quand
 * elle existe, pages parentes, contenu courant), page des articles, archive (son titre), recherche,
 * page introuvable. Rien sur la page d'accueil. Un thème ajoute ou retire des maillons par le filtre
 * fluffless_suite_breadcrumb_trail( array $trail, ?WP_Post $post ) ; le résultat est revalidé : un
 * maillon est un tableau name (texte brut non vide) et url (chaîne, vide pour un maillon sans lien).
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Maillons du chemin courant.
 */
final class Trail {

	public const FILTER = 'fluffless_suite_breadcrumb_trail';

	/**
	 * Maillons du chemin : nom en texte brut et adresse (vide = pas de lien). Vide sur la page
	 * d'accueil et quand la requête ne décrit rien (rendu hors requête, par exemple).
	 *
	 * @param WP_Post|null $post Contenu courant ; null pour le lire dans la requête.
	 * @param string       $url  Adresse du dernier maillon (la canonique du SEO) ; vide pour le permalien.
	 * @return list<array{name: string, url: string}>
	 */
	public static function of( ?WP_Post $post = null, string $url = '' ): array {
		$trail = array();
		if ( ! is_front_page() ) {
			$queried = null === $post && is_singular() ? get_queried_object() : $post;
			$post    = $queried instanceof WP_Post ? $queried : null;
			$trail[] = self::crumb( __( 'Home', 'fluffless-suite' ), home_url( '/' ) );
			if ( null !== $post ) {
				$trail = array_merge( $trail, self::singular( $post, $url ) );
			} elseif ( is_home() ) {
				$blog    = (int) get_option( 'page_for_posts' );
				$trail[] = self::crumb( $blog > 0 ? self::plain( get_the_title( $blog ) ) : __( 'Blog', 'fluffless-suite' ), '' );
			} elseif ( is_search() ) {
				$trail[] = self::crumb( __( 'Search results', 'fluffless-suite' ), '' );
			} elseif ( is_404() ) {
				$trail[] = self::crumb( __( 'Page not found', 'fluffless-suite' ), '' );
			} elseif ( is_archive() ) {
				$trail[] = self::crumb( self::plain( get_the_archive_title() ), '' );
			} else {
				$trail = array();
			}
		}
		/**
		 * Maillons du fil d'Ariane, avant validation.
		 *
		 * @param list<array{name: string, url: string}> $trail Maillons calculés.
		 * @param WP_Post|null                           $post  Contenu courant, s'il y en a un.
		 */
		$filtered = apply_filters( 'fluffless_suite_breadcrumb_trail', $trail, $post );
		// @phpstan-ignore function.alreadyNarrowedType (un filtre tiers peut rendre n'importe quoi)
		return self::valid( is_array( $filtered ) ? $filtered : $trail );
	}

	/**
	 * Maillons d'un contenu singulier : page des articles ou archive du type, pages parentes, contenu.
	 *
	 * @param WP_Post $post Contenu.
	 * @param string  $url  Adresse du contenu ; vide pour le permalien.
	 * @return list<array{name: string, url: string}>
	 */
	private static function singular( WP_Post $post, string $url ): array {
		$crumbs = array();
		$type   = get_post_type_object( $post->post_type );
		$blog   = (int) get_option( 'page_for_posts' );
		if ( 'post' === $post->post_type && $blog > 0 && (int) get_option( 'page_on_front' ) !== $blog ) {
			$crumbs[] = self::crumb( self::plain( get_the_title( $blog ) ), (string) get_permalink( $blog ) );
		} elseif ( null !== $type && 'post' !== $post->post_type && false !== $type->has_archive ) {
			// Pas de maillon sans adresse au milieu du chemin : un ListItem intermédiaire sans item
			// invalide la BreadcrumbList.
			$archive = get_post_type_archive_link( $post->post_type );
			if ( is_string( $archive ) && '' !== $archive ) {
				$crumbs[] = self::crumb( self::plain( $type->labels->name ), $archive );
			}
		}
		// get_post_ancestors() est borné par WordPress (boucles de parenté détectées).
		foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
			$crumbs[] = self::crumb( self::plain( get_the_title( $ancestor ) ), (string) get_permalink( $ancestor ) );
		}
		$crumbs[] = self::crumb( self::plain( get_the_title( $post ) ), '' !== $url ? $url : (string) get_permalink( $post ) );
		return $crumbs;
	}

	/**
	 * Adresse du maillon courant tel que of() le construit sans adresse imposée : le permalien du
	 * contenu singulier affiché, vide ailleurs (archive, recherche, page introuvable, page des
	 * articles). Sert à reconnaître ce maillon par son adresse plutôt que par son rang.
	 *
	 * @internal Partagé avec le bloc Fil d'Ariane ; pas une API pour les thèmes.
	 */
	public static function current_url(): string {
		$queried = is_singular() ? get_queried_object() : null;
		return $queried instanceof WP_Post ? (string) get_permalink( $queried ) : '';
	}

	/**
	 * Un maillon.
	 *
	 * @param string $name Nom en texte brut.
	 * @param string $url  Adresse, vide pour un maillon sans lien.
	 * @return array{name: string, url: string}
	 */
	private static function crumb( string $name, string $url ): array {
		return array(
			'name' => $name,
			'url'  => $url,
		);
	}

	/**
	 * Garde les maillons bien formés (un filtre tiers peut rendre n'importe quoi), réindexés.
	 *
	 * @param array<mixed> $trail Maillons filtrés.
	 * @return list<array{name: string, url: string}>
	 */
	private static function valid( array $trail ): array {
		$clean = array();
		foreach ( $trail as $crumb ) {
			if ( ! is_array( $crumb ) || ! is_string( $crumb['name'] ?? null ) || '' === trim( $crumb['name'] ) || ! is_string( $crumb['url'] ?? '' ) ) {
				continue;
			}
			$clean[] = self::crumb( trim( $crumb['name'] ), (string) ( $crumb['url'] ?? '' ) );
		}
		return $clean;
	}

	/**
	 * Texte brut : balises retirées, entités décodées, espaces normalisées. Les espaces insécables
	 * (U+00A0, U+202F) seules restent : elles tiennent la ponctuation française à son mot (« équipe : »).
	 * Un blanc qui mêle insécable et espace ordinaire (paragraphe « &nbsp; ») se replie en une espace.
	 *
	 * @internal Partagé avec le module SEO ; pas une API pour les thèmes.
	 * @param string $text Texte ou HTML.
	 */
	public static function plain( string $text ): string {
		$text   = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$length = strlen( $text );
		// Un seul passage sur chaque blanc : linéaire même sans le JIT de PCRE. En bordure, le blanc disparaît.
		return (string) preg_replace_callback(
			'/\s+/u',
			static function ( array $blank ) use ( $length ): string {
				list( $run, $at ) = $blank[0];
				if ( 0 === $at || $at + strlen( $run ) === $length ) {
					return '';
				}
				return preg_match( '/^[\x{00A0}\x{202F}]+$/u', $run ) ? $run : ' ';
			},
			$text,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
	}
}
