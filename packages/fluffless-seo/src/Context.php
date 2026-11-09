<?php
/**
 * Requête courante vue par le SEO : contexte, titre, description, canonique, image, variables.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

use Fluffless\Suite\Modules\Seo as LightSeo;

defined( 'ABSPATH' ) || exit;

/**
 * Ordre de priorité, du plus précis au plus général : champ du contenu, gabarit enregistré
 * pour le contexte, gabarit par défaut. Les calculs sont gardés pour la requête (memo).
 */
final class Context {

	public const DESCRIPTION_LENGTH = 160;

	/** Variables des gabarits, dans l'ordre de l'aide affichée. */
	public const VARIABLES = array( 'title', 'sitename', 'sitedesc', 'sep', 'excerpt', 'term', 'term_description', 'category', 'author', 'date', 'modified', 'post_type', 'pt_plural', 'search', 'page', 'currentyear' );

	/**
	 * Résultats déjà calculés dans la requête.
	 *
	 * @var array<string, mixed>
	 */
	private static array $memo = array();

	/** Oublie les calculs (tests, plusieurs visites simulées dans un processus). */
	public static function reset(): void {
		self::$memo = array();
		LightSeo::reset();
	}

	/**
	 * Clé de contexte de la requête : front, home, un type de contenu, tax:<taxonomie>,
	 * archive:<type>, author, date, search, 404, archive.
	 */
	public static function key(): string {
		return match ( true ) {
			is_404()                => '404',
			is_search()             => 'search',
			is_front_page()         => 'front',
			is_home()               => 'home',
			is_singular()           => self::post()->post_type ?? 'page',
			is_category(), is_tag(), is_tax() => 'tax:' . ( get_queried_object() instanceof \WP_Term ? get_queried_object()->taxonomy : 'category' ),
			is_post_type_archive()  => 'archive:' . self::archive_type(),
			is_author()             => 'author',
			is_date()               => 'date',
			default                 => 'archive',
		};
	}

	/** Contenu dont les champs SEO s'appliquent : contenu affiché, ou page des articles. */
	public static function post(): ?\WP_Post {
		$object = get_queried_object();
		if ( ( is_singular() || is_home() ) && $object instanceof \WP_Post ) {
			return $object;
		}
		return null;
	}

	/** Type de l'archive de type de contenu affichée. */
	private static function archive_type(): string {
		$type = get_query_var( 'post_type' );
		if ( is_array( $type ) ) {
			$type = reset( $type );
		}
		return is_string( $type ) ? $type : '';
	}

	/**
	 * Gabarit par défaut d'un contexte.
	 *
	 * @param string $key   Clé de contexte.
	 * @param string $field title ou desc.
	 */
	public static function default_template( string $key, string $field ): string {
		$suffix = ' %%page%% %%sep%% %%sitename%%';
		if ( 'desc' === $field ) {
			return match ( true ) {
				str_starts_with( $key, 'tax:' ) => '%%term_description%%',
				in_array( $key, array( 'search', '404', 'author', 'date', 'archive' ), true ), str_starts_with( $key, 'archive:' ) => '',
				default => '%%excerpt%%',
			};
		}
		return match ( true ) {
			'front' === $key                    => '%%sitename%% %%sep%% %%sitedesc%%',
			'404' === $key                      => __( 'Page not found', 'fluffless-seo' ) . ' %%sep%% %%sitename%%',
			/* translators: %s: the search variable, keep it as is. */
			'search' === $key                   => sprintf( __( 'Search results for %s', 'fluffless-seo' ), '%%search%%' ) . $suffix,
			str_starts_with( $key, 'tax:' )     => '%%term%%' . $suffix,
			str_starts_with( $key, 'archive:' ) => '%%pt_plural%%' . $suffix,
			'author' === $key                   => '%%author%%' . $suffix,
			'date' === $key                     => '%%date%%' . $suffix,
			default                             => '%%title%%' . $suffix,
		};
	}

	/**
	 * Gabarit effectif d'un contexte : enregistré, sinon par défaut.
	 *
	 * @param string $key   Clé de contexte.
	 * @param string $field title ou desc.
	 */
	public static function template( string $key, string $field ): string {
		return Settings::template( $key, $field ) ?? self::default_template( $key, $field );
	}

	/** Titre du document ; '' laisse le titre du cœur. */
	public static function title(): string {
		return self::memo(
			'title',
			static function (): string {
				$post   = self::post();
				$custom = null === $post ? '' : Meta::text( $post->ID, Meta::TITLE );
				return self::replace( '' !== $custom ? $custom : self::template( self::key(), 'title' ) );
			}
		);
	}

	/** Description, bornée à 160 caractères ; '' si rien de pertinent. */
	public static function description(): string {
		return self::memo(
			'description',
			static function (): string {
				$post   = self::post();
				$custom = null === $post ? '' : Meta::text( $post->ID, Meta::DESCRIPTION );
				$text   = '' !== $custom ? self::replace( $custom ) : self::replace( self::template( self::key(), 'desc' ) );
				return self::shorten( $text, self::DESCRIPTION_LENGTH );
			}
		);
	}

	/** Adresse canonique : champ du contenu, sinon celle calculée par Fluffless Suite. */
	public static function canonical(): string {
		return self::memo(
			'canonical',
			static function (): string {
				$post   = self::post();
				$custom = null === $post ? '' : Meta::text( $post->ID, Meta::CANONICAL );
				return '' !== $custom ? $custom : LightSeo::canonical_url();
			}
		);
	}

	/**
	 * Image de partage : champ du contenu, image mise en avant, image par défaut, logo ; puis le
	 * filtre fluffless_seo_image, appliqué une seule fois par requête (og:image, twitter:image
	 * et le champ image des nœuds schema.org lisent la même valeur).
	 */
	public static function image(): string {
		return self::memo(
			'image',
			static function (): string {
				$post  = self::post();
				$ids   = array(
					null === $post ? 0 : (int) get_post_meta( $post->ID, Meta::OG_IMAGE, true ),
					null === $post ? 0 : (int) get_post_thumbnail_id( $post ),
					Settings::int( 'default_image' ),
				);
				$found = '';
				// Une Suite d'avant share_image_url() (les deux extensions se mettent à jour séparément) :
				// taille « large » telle quelle, sans erreur fatale.
				$share = method_exists( LightSeo::class, 'share_image_url' ); // @phpstan-ignore function.alreadyNarrowedType (une Suite plus ancienne ne l'a pas)
				foreach ( $ids as $id ) {
					if ( $id <= 0 ) {
						continue;
					}
					$url = $share ? LightSeo::share_image_url( $id ) : (string) wp_get_attachment_image_url( $id, 'large' );
					if ( '' !== $url ) {
						$found = $url;
						break;
					}
				}
				if ( '' === $found ) {
					$found = self::logo();
				}
				/**
				 * Image de partage de la page. Rendre une chaîne vide retire og:image et
				 * twitter:image (la carte X passe à « summary »). Même filtre que dans Fluffless Suite.
				 *
				 * @param string $url     Adresse absolue résolue, ou chaîne vide.
				 * @param int    $post_id Contenu dont les champs SEO s'appliquent (page des articles comprise), sinon 0.
				 */
				$filtered = apply_filters( 'fluffless_seo_image', $found, null === $post ? 0 : $post->ID );
				return is_string( $filtered ) ? esc_url_raw( $filtered ) : ''; // @phpstan-ignore function.alreadyNarrowedType (un filtre tiers peut rendre autre chose qu'une chaîne)
			}
		);
	}

	/** Logo : réglage de l'extension, sinon celui de Fluffless Suite (logo du thème, icône du site). */
	public static function logo(): string {
		$logo = Settings::string( 'logo' );
		return '' !== $logo ? $logo : LightSeo::logo_url();
	}

	/** Langue du contenu en étiquette BCP 47 (fr-CA). */
	public static function language(): string {
		return str_replace( '_', '-', LightSeo::content_locale() );
	}

	/**
	 * Remplace les variables %%nom%% d'un gabarit ; une variable inconnue ou vide disparaît.
	 *
	 * @param string $template Gabarit.
	 */
	public static function replace( string $template ): string {
		if ( '' === $template ) {
			return '';
		}
		$sep  = Settings::string( 'sep' );
		$text = (string) preg_replace_callback(
			'/%%([a-z_]{1,30})%%/',
			static fn( array $found ): string => self::variable( $found[1] ),
			$template
		);
		return self::tidy( $text, '' !== $sep ? $sep : '|' );
	}

	/**
	 * Valeur d'une variable pour la requête courante.
	 *
	 * @param string $name Nom sans les %%.
	 */
	private static function variable( string $name ): string {
		$post = self::post();
		return self::plain(
			(string) match ( $name ) {
				'title'            => null !== $post ? get_the_title( $post ) : self::archive_title(),
				'sitename'         => get_bloginfo( 'name' ),
				'sitedesc'         => get_bloginfo( 'description' ),
				'sep'              => Settings::string( 'sep' ),
				'excerpt'          => LightSeo::current_description(),
				'term'             => is_category() || is_tag() || is_tax() ? single_term_title( '', false ) : '',
				'term_description' => is_category() || is_tag() || is_tax() ? term_description() : '',
				'category'         => self::first_category( $post ),
				'author'           => self::author( $post ),
				'date'             => self::date_label( $post ),
				'modified'         => null !== $post ? (string) get_the_modified_date( '', $post ) : '',
				'post_type'        => self::type_label( $post, 'singular_name' ),
				'pt_plural'        => self::type_label( $post, 'name' ),
				'search'           => get_search_query( false ),
				'page'             => self::page_label(),
				'currentyear'      => wp_date( 'Y' ),
				default            => '',
			}
		);
	}

	/** Titre d'archive sans préfixe (« Catégorie : »). */
	private static function archive_title(): string {
		$remove = static fn(): string => '';
		add_filter( 'get_the_archive_title_prefix', $remove );
		$title  = get_the_archive_title();
		remove_filter( 'get_the_archive_title_prefix', $remove );
		return $title;
	}

	/**
	 * Première catégorie d'un article (termes déjà en cache avec la requête principale).
	 *
	 * @param \WP_Post|null $post Contenu.
	 */
	private static function first_category( ?\WP_Post $post ): string {
		if ( null === $post ) {
			return is_category() ? (string) single_term_title( '', false ) : '';
		}
		$terms = get_the_terms( $post, 'category' );
		return is_array( $terms ) && isset( $terms[0] ) ? $terms[0]->name : '';
	}

	/**
	 * Auteur du contenu, ou de l'archive d'auteur.
	 *
	 * @param \WP_Post|null $post Contenu.
	 */
	private static function author( ?\WP_Post $post ): string {
		$object = get_queried_object();
		if ( $object instanceof \WP_User ) {
			return $object->display_name;
		}
		return null !== $post ? (string) get_the_author_meta( 'display_name', (int) $post->post_author ) : '';
	}

	/**
	 * Date du contenu, ou libellé de l'archive de dates (jour, mois, année).
	 *
	 * @param \WP_Post|null $post Contenu.
	 */
	private static function date_label( ?\WP_Post $post ): string {
		if ( is_date() ) {
			return self::archive_title();
		}
		return null !== $post ? (string) get_the_date( '', $post ) : '';
	}

	/**
	 * Nom du type de contenu affiché.
	 *
	 * @param \WP_Post|null $post  Contenu.
	 * @param string        $label singular_name ou name.
	 */
	private static function type_label( ?\WP_Post $post, string $label ): string {
		$type   = null !== $post ? $post->post_type : self::archive_type();
		$object = '' !== $type ? get_post_type_object( $type ) : null;
		$value  = null !== $object ? ( $object->labels->{$label} ?? '' ) : '';
		return is_string( $value ) ? $value : '';
	}

	/** « Page 2 sur 5 » au-delà de la première page ; vide sinon. */
	private static function page_label(): string {
		global $wp_query;
		$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		if ( $paged < 2 ) {
			return '';
		}
		$total = $wp_query instanceof \WP_Query ? (int) $wp_query->max_num_pages : 0;
		return $total > 1
			/* translators: 1: current page number, 2: total pages. */
			? sprintf( __( 'Page %1$d of %2$d', 'fluffless-seo' ), $paged, $total )
			/* translators: %d: current page number. */
			: sprintf( __( 'Page %d', 'fluffless-seo' ), $paged );
	}

	/**
	 * Nettoie un titre assemblé : espaces, séparateurs doublés ou en bordure (variable vide).
	 *
	 * @param string $text Texte assemblé.
	 * @param string $sep  Séparateur.
	 */
	private static function tidy( string $text, string $sep ): string {
		$quoted = preg_quote( $sep, '/' );
		$text   = (string) preg_replace( '/\s+/u', ' ', $text );
		$text   = (string) preg_replace( '/(\s*' . $quoted . '\s*){2,}/u', ' ' . $sep . ' ', $text );
		$text   = (string) preg_replace( '/^\s*' . $quoted . '\s*|\s*' . $quoted . '\s*$/u', '', $text );
		return trim( $text );
	}

	/**
	 * Texte brut : balises retirées, entités décodées, espaces normalisées.
	 *
	 * @param string $text Texte ou HTML.
	 */
	public static function plain( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Coupe au dernier mot entier avant la limite et ajoute « … ».
	 *
	 * @param string $text  Texte brut.
	 * @param int    $limit Nombre de caractères.
	 */
	public static function shorten( string $text, int $limit ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $limit - 1 );
		$space = mb_strrpos( $cut, ' ' );
		return rtrim( false === $space ? $cut : mb_substr( $cut, 0, $space ), ' ,;:.-' ) . '…';
	}

	/**
	 * Calcul gardé pour la requête.
	 *
	 * @param string            $key     Clé du calcul.
	 * @param callable():string $compute Calcul.
	 */
	private static function memo( string $key, callable $compute ): string {
		if ( ! isset( self::$memo[ $key ] ) ) {
			self::$memo[ $key ] = $compute();
		}
		$value = self::$memo[ $key ];
		return is_string( $value ) ? $value : '';
	}
}
