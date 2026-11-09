<?php
/**
 * Balises de l'en-tête : titre, description, canonique, robots, hreflang, partage, vérification, JSON-LD.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

use Fluffless\Suite\Modules\Seo as LightSeo;

defined( 'ABSPATH' ) || exit;

/**
 * Tout est écrit côté serveur, sans script pour les visiteurs. Chaque famille éteinte
 * retire ses balises. Les codes de vérification ne sont écrits que sur l'accueil :
 * les moteurs ne les lisent que là.
 */
final class Head {

	/** Pose les crochets du site public. */
	public static function register(): void {
		add_action( 'after_setup_theme', array( self::class, 'add_title_support' ), 20 );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 20 );
		add_action( 'wp_head', array( self::class, 'print_head' ), 1 );
		if ( Settings::on( 'titles' ) ) {
			add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );
			// Remplacée par la nôtre, qui couvre aussi l'accueil, les archives et le champ du contenu.
			remove_action( 'wp_head', 'rel_canonical' );
		}
		if ( Settings::on( 'hreflang' ) ) {
			add_filter( 'pll_rel_hreflang_attributes', array( LightSeo::class, 'polylang_hreflang' ) );
		}
	}

	/**
	 * Liens hreflang des traductions, puis x-default vers la langue par défaut réglée
	 * quand la page existe dans cette langue (sinon aucun x-default : mieux vaut rien qu'un faux).
	 *
	 * @param array<string, string> $alternates Locale => adresse.
	 * @param string                $fallback   Locale de x-default ('' : aucun).
	 * @return list<string>
	 */
	public static function hreflang_tags( array $alternates, string $fallback ): array {
		$tags = array();
		foreach ( $alternates as $locale => $url ) {
			$tags[] = '<link rel="alternate" hreflang="' . esc_attr( str_replace( '_', '-', $locale ) ) . '" href="' . esc_url( $url ) . '">';
		}
		if ( '' !== $fallback && isset( $alternates[ $fallback ] ) ) {
			$tags[] = '<link rel="alternate" hreflang="x-default" href="' . esc_url( $alternates[ $fallback ] ) . '">';
		}
		return $tags;
	}

	/** Balise title par le support natif, quel que soit le thème. */
	public static function add_title_support(): void {
		if ( ! current_theme_supports( 'title-tag' ) ) {
			add_theme_support( 'title-tag' );
		}
	}

	/**
	 * Titre calculé ; '' garde celui du cœur.
	 *
	 * @param mixed $title Titre déjà proposé par un autre filtre.
	 * @return mixed
	 */
	public static function document_title( $title ) {
		if ( is_string( $title ) && '' !== $title ) {
			return $title;
		}
		$ours = Context::title();
		return '' !== $ours ? $ours : $title;
	}

	/**
	 * Directives robots du contenu et des archives exclues.
	 *
	 * @param mixed $robots Directives.
	 * @return mixed
	 */
	public static function robots( $robots ) {
		if ( ! is_array( $robots ) ) {
			return $robots;
		}
		$archives = Settings::list( 'noindex_archives' );
		$object   = get_queried_object();
		$noindex  = ( is_author() && in_array( 'author', $archives, true ) )
			|| ( is_date() && in_array( 'date', $archives, true ) )
			|| ( is_attachment() && in_array( 'attachment', $archives, true ) )
			|| ( $object instanceof \WP_Term && in_array( $object->taxonomy, Settings::list( 'noindex_taxonomies' ), true ) );
		if ( $noindex ) {
			$robots['noindex'] = true;
		}
		$post = Context::post();
		foreach ( null === $post ? array() : Meta::robots( $post->ID ) as $directive ) {
			if ( str_starts_with( $directive, 'max-image-preview:' ) ) {
				$robots['max-image-preview'] = substr( $directive, strlen( 'max-image-preview:' ) );
			} else {
				$robots[ $directive ] = true;
			}
		}
		// Un index/follow du cœur contredirait la directive : on le retire.
		if ( ! empty( $robots['noindex'] ) ) {
			unset( $robots['index'] );
		}
		if ( ! empty( $robots['nofollow'] ) ) {
			unset( $robots['follow'] );
		}
		return $robots;
	}

	/** Imprime les balises dans wp_head. */
	public static function print_head(): void {
		echo self::tags(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chaque valeur est échappée dans tags().
	}

	/** Balises de la requête courante, déjà échappées, une par ligne. */
	public static function tags(): string {
		$tags   = array();
		$is_404 = is_404();
		if ( Settings::on( 'titles' ) && ! $is_404 ) {
			$description = Context::description();
			$canonical   = Context::canonical();
			if ( '' !== $description ) {
				$tags[] = '<meta name="description" content="' . esc_attr( $description ) . '">';
			}
			if ( '' !== $canonical ) {
				$tags[] = '<link rel="canonical" href="' . esc_url( $canonical ) . '">';
			}
		}
		$alternates = Settings::on( 'hreflang' ) ? LightSeo::alternates() : array();
		$tags       = array_merge( $tags, self::hreflang_tags( $alternates, Settings::string( 'hreflang_default' ) ) );
		if ( Settings::on( 'social' ) && ! $is_404 && ! is_search() ) {
			$tags = array_merge( $tags, self::social( array_keys( $alternates ) ) );
		}
		if ( is_front_page() ) {
			$tags = array_merge( $tags, self::verification() );
		}
		if ( Settings::on( 'schema' ) && ! $is_404 ) {
			$tags[] = Schema::json_ld();
		}
		$tags = array_filter( $tags, static fn( string $tag ): bool => '' !== $tag );
		return array() === $tags ? '' : implode( "\n", $tags ) . "\n";
	}

	/**
	 * Open Graph et carte X. X lit les balises og: ; seules la forme de la carte et le compte lui sont propres.
	 *
	 * @param string[] $locales Langues des traductions (Polylang), pour og:locale:alternate.
	 * @return list<string>
	 */
	private static function social( array $locales ): array {
		$post      = Context::post();
		$is_post   = null !== $post && is_singular( 'post' );
		$title     = self::social_text( $post, Meta::OG_TITLE, null !== $post && ! is_home() ? Context::plain( get_the_title( $post ) ) : wp_get_document_title() );
		$desc      = self::social_text( $post, Meta::OG_DESC, Context::description() );
		$image     = Context::image();
		$locale    = LightSeo::content_locale();
		$site      = Context::plain( get_bloginfo( 'name' ) );
		$canonical = Context::canonical();
		$pairs     = array(
			array( 'og:type', $is_post ? 'article' : 'website' ),
			array( 'og:title', Context::plain( $title ) ),
			array( 'og:description', $desc ),
			array( 'og:url', $canonical ),
			array( 'og:site_name', $site ),
			array( 'og:locale', $locale ),
			array( 'og:image', $image ),
		);
		foreach ( $locales as $alternate ) {
			if ( $alternate !== $locale ) {
				$pairs[] = array( 'og:locale:alternate', $alternate );
			}
		}
		if ( $is_post ) {
			$pairs[] = array( 'article:published_time', (string) get_the_date( 'c', $post ) );
			$pairs[] = array( 'article:modified_time', (string) get_the_modified_date( 'c', $post ) );
		}
		$tags = array();
		foreach ( $pairs as [ $property, $value ] ) {
			if ( '' !== $value ) {
				$content = in_array( $property, array( 'og:image', 'og:url' ), true ) ? esc_url( $value ) : esc_attr( $value );
				$tags[]  = '<meta property="' . esc_attr( $property ) . '" content="' . $content . '">';
			}
		}
		$tags[] = '<meta name="twitter:card" content="' . ( '' === $image ? 'summary' : 'summary_large_image' ) . '">';
		$handle = ltrim( Settings::string( 'x_site' ), '@' );
		if ( 1 === preg_match( '/^[A-Za-z0-9_]{1,15}$/', $handle ) ) {
			$tags[] = '<meta name="twitter:site" content="@' . esc_attr( $handle ) . '">';
		}
		return $tags;
	}

	/**
	 * Texte de partage : champ du contenu (variables permises), sinon repli.
	 *
	 * @param \WP_Post|null $post     Contenu.
	 * @param string        $key      Clé de métadonnée.
	 * @param string        $fallback Repli.
	 */
	private static function social_text( ?\WP_Post $post, string $key, string $fallback ): string {
		$custom = null === $post ? '' : Meta::text( $post->ID, $key );
		return '' !== $custom ? Context::replace( $custom ) : $fallback;
	}

	/**
	 * Balises de vérification des outils pour webmestres.
	 *
	 * @return list<string>
	 */
	private static function verification(): array {
		$tags = array();
		foreach ( array(
			'google-site-verification' => 'verify_google',
			'msvalidate.01'            => 'verify_bing',
		) as $name => $key ) {
			$code = Settings::string( $key );
			if ( '' !== $code ) {
				$tags[] = '<meta name="' . $name . '" content="' . esc_attr( $code ) . '">';
			}
		}
		return $tags;
	}
}
