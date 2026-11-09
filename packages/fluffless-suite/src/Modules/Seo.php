<?php
/**
 * Module SEO léger : description, canonique, Open Graph, Twitter Card et JSON-LD.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Blocks\Sections;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;
use Fluffless\Suite\Presentation\Trail;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Aucune requête externe : tout est calculé depuis la base. Le titre reste celui du
 * support natif title-tag. Le module ne pose aucun crochet si une extension SEO
 * reconnue est active (filtre fluffless_seo_conflict pour en ajouter ou en simuler une).
 */
final class Seo implements Module {

	public const META_DESCRIPTION   = Lifecycle::META_SEO_DESCRIPTION;
	public const DESCRIPTION_LENGTH = 160;

	/** Longueur stockée : un peu de marge au-delà de ce que les moteurs affichent. */
	private const META_MAX = 300;

	/** Constante de l'extension compagne Fluffless SEO : elle reprend ces balises et les étend, sans être un conflit. */
	private const COMPANION = 'FLUFFLESS_SEO_VERSION';

	/** Constante définie par chaque extension SEO tierce reconnue => nom affiché. */
	private const CONFLICTS = array(
		'WPSEO_VERSION'             => 'Yoast SEO',
		'RANK_MATH_VERSION'         => 'Rank Math',
		'SEOPRESS_VERSION'          => 'SEOPress',
		'THE_SEO_FRAMEWORK_VERSION' => 'The SEO Framework',
		'AIOSEO_VERSION'            => 'All in One SEO',
		'SLIM_SEO_VER'              => 'Slim SEO',
		'SQ_VERSION'                => 'Squirrly SEO',
	);

	/** Bornes du parcours des blocs : blocs visités, profondeur, questions de FAQ gardées. */
	private const MAX_BLOCKS = 500;
	private const MAX_DEPTH  = 10;
	private const MAX_FAQ    = 50;

	/** Produits (blocs Produit de la Vente simple) écrits en nœuds Product, au plus. */
	private const MAX_PRODUCTS = 20;

	/**
	 * Blocs analysés par contenu, pour ne pas relire le même contenu deux fois dans une requête.
	 *
	 * @var array<int, array<mixed>>
	 */
	private static array $parsed = array();

	/**
	 * Image de partage de la requête, filtrée une seule fois ; null tant que non calculée.
	 *
	 * @var string|null
	 */
	private static ?string $image = null;

	private const SCRIPT_HANDLE = 'fluffless-seo-editor';
	private const SCRIPT_DEPS   = array( 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' );

	/** {@inheritDoc} */
	public static function id(): string {
		return 'seo';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Light SEO', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Adds the description, canonical link, social sharing tags and structured data search engines read. A few hundred bytes, no script. Turns itself off if another SEO plugin is active.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'org_name'        => '',
			'logo_url'        => '',
			'social_image_id' => 0,
			'faq_schema'      => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array(
			'org_name'        => array(
				'type'  => 'text',
				'label' => __( 'Organization name', 'fluffless-suite' ),
				'help'  => __( 'Shown to search engines. Empty: the site title.', 'fluffless-suite' ),
			),
			'logo_url'        => array(
				'type'  => 'url',
				'label' => __( 'Logo address', 'fluffless-suite' ),
				'help'  => __( 'https only. Empty: the site logo, then the site icon.', 'fluffless-suite' ),
			),
			'social_image_id' => array(
				'type'  => 'int',
				'label' => __( 'Default sharing image (media ID)', 'fluffless-suite' ),
				'help'  => __( 'Used when a page has no featured image. Open the image in Media: the number after item= in the address. 0: the logo is used.', 'fluffless-suite' ),
				'min'   => 0,
				'max'   => PHP_INT_MAX,
			),
			'faq_schema'      => array(
				'type'  => 'bool',
				'label' => __( 'Describe Details blocks as an FAQ', 'fluffless-suite' ),
				'help'  => __( 'Adds FAQ page data for the questions in Details blocks. Turn off when they are not a real FAQ.', 'fluffless-suite' ),
			),
		);
	}

	/** Extension SEO active qui prend le relais, ou null. */
	public static function conflict(): ?string {
		$found = null;
		foreach ( self::CONFLICTS as $constant => $name ) {
			if ( defined( $constant ) ) {
				$found = $name;
				break;
			}
		}
		/**
		 * Extension SEO détectée qui désactive le module.
		 *
		 * @param string|null $found Nom de l'extension, ou null si aucune.
		 */
		$filtered = apply_filters( 'fluffless_seo_conflict', $found );
		return is_string( $filtered ) && '' !== $filtered ? $filtered : null;
	}

	/** Vrai si Fluffless SEO est active : le module lui cède la place. */
	public static function companion(): bool {
		return defined( self::COMPANION );
	}

	/** État affiché dans les réglages. */
	public static function status(): string {
		$taken_by = self::conflict() ?? ( self::companion() ? 'Fluffless SEO' : null );
		if ( null === $taken_by ) {
			return '';
		}
		return sprintf(
			/* translators: %s: plugin name, e.g. Yoast SEO */
			__( 'Turned off automatically: %s is active and already writes these tags.', 'fluffless-suite' ),
			$taken_by
		);
	}

	/** {@inheritDoc} */
	public function register(): void {
		if ( self::companion() || null !== self::conflict() ) {
			return;
		}
		add_action( 'init', array( self::class, 'register_meta' ) );
		add_action( 'after_setup_theme', array( self::class, 'add_title_support' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		// Remplacée par la nôtre, qui couvre aussi l'accueil et les archives.
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', array( self::class, 'print_head' ), 1 );
		add_filter( 'pll_rel_hreflang_attributes', array( self::class, 'polylang_hreflang' ) );
	}

	/**
	 * Polylang imprime ses propres liens hreflang : quand ce module imprime les siens, ceux de Polylang sont retirés.
	 *
	 * @param mixed $hreflangs Langue => adresse.
	 * @return mixed
	 */
	public static function polylang_hreflang( $hreflangs ) {
		return array() === self::alternates() ? $hreflangs : array();
	}

	/** Balise title par le support natif, si le thème ne l'a pas déjà. */
	public static function add_title_support(): void {
		if ( ! current_theme_supports( 'title-tag' ) ) {
			add_theme_support( 'title-tag' );
		}
	}

	/** Champ « description » de chaque contenu, lisible et modifiable par l'API REST. */
	public static function register_meta(): void {
		register_post_meta(
			'',
			self::META_DESCRIPTION,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => array(
					'schema' => array(
						'type'      => 'string',
						'maxLength' => self::META_MAX,
					),
				),
				'sanitize_callback' => array( self::class, 'sanitize_description' ),
				'auth_callback'     => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
			)
		);
	}

	/**
	 * Texte brut sur une ligne, borné.
	 *
	 * @param mixed $value Valeur reçue.
	 */
	public static function sanitize_description( $value ): string {
		return is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, self::META_MAX ) : '';
	}

	/**
	 * Site privé (Réglages → Lecture) : noindex, même si un thème retire le filtre du cœur.
	 *
	 * @param mixed $robots Directives.
	 * @return mixed
	 */
	public static function robots( $robots ) {
		if ( is_array( $robots ) && '0' === (string) get_option( 'blog_public' ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/** Champ de description dans le panneau du document : JS natif, sans build. */
	public static function editor_assets(): void {
		wp_enqueue_script( self::SCRIPT_HANDLE, plugins_url( 'assets/seo/editor.js', FILE ), self::SCRIPT_DEPS, VERSION, true );
		wp_set_script_translations( self::SCRIPT_HANDLE, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
	}

	/** Imprime les balises dans wp_head. */
	public static function print_head(): void {
		echo self::head_tags(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chaque valeur est échappée dans head_tags().
	}

	/** Balises de la requête courante, déjà échappées. Rien pour une recherche ou une 404. */
	public static function head_tags(): string {
		if ( is_404() || is_search() ) {
			return '';
		}
		$description = self::current_description();
		$canonical   = self::canonical_url();
		$image       = self::image_url();
		$locale      = self::content_locale();
		$tags        = array();

		if ( '' !== $description ) {
			$tags[] = '<meta name="description" content="' . esc_attr( $description ) . '">';
		}
		if ( '' !== $canonical ) {
			$tags[] = '<link rel="canonical" href="' . esc_url( $canonical ) . '">';
		}
		$open_graph = array(
			'og:type'        => is_singular( 'post' ) ? 'article' : 'website',
			'og:title'       => self::plain( wp_get_document_title() ),
			'og:description' => $description,
			'og:url'         => $canonical,
			'og:site_name'   => self::plain( get_bloginfo( 'name' ) ),
			'og:locale'      => $locale,
			'og:image'       => $image,
		);
		foreach ( array_filter( $open_graph, static fn( string $value ): bool => '' !== $value ) as $property => $value ) {
			$content = 'og:image' === $property || 'og:url' === $property ? esc_url( $value ) : esc_attr( $value );
			$tags[]  = '<meta property="' . esc_attr( $property ) . '" content="' . $content . '">';
		}
		foreach ( self::alternates() as $alt_locale => $alt_url ) {
			if ( $alt_locale !== $locale ) {
				$tags[] = '<meta property="og:locale:alternate" content="' . esc_attr( $alt_locale ) . '">';
			}
			$tags[] = '<link rel="alternate" hreflang="' . esc_attr( self::language_tag( $alt_locale ) ) . '" href="' . esc_url( $alt_url ) . '">';
		}
		// X lit les balises og: ; seule la forme de la carte lui est propre.
		$tags[] = '<meta name="twitter:card" content="' . ( '' === $image ? 'summary' : 'summary_large_image' ) . '">';
		$tags[] = self::json_ld();
		return implode( "\n", $tags ) . "\n";
	}

	/** Description : champ du contenu, puis extrait, puis premiers paragraphes des blocs ; slogan du site en dernier recours. */
	public static function current_description(): string {
		$text   = '';
		$object = get_queried_object();
		if ( is_singular() && $object instanceof \WP_Post ) {
			$text = (string) get_post_meta( $object->ID, self::META_DESCRIPTION, true );
			if ( '' === $text ) {
				// Pas de get_the_excerpt() : il rendrait tous les blocs de la page pour en garder 160 caractères.
				$text = '' !== $object->post_excerpt ? $object->post_excerpt : self::text_from_blocks( $object );
			}
		} elseif ( $object instanceof \WP_Term ) {
			$text = $object->description;
		}
		if ( '' === trim( $text ) && ( is_singular() || is_front_page() || is_home() ) ) {
			$text = get_bloginfo( 'description' );
		}
		return self::shorten( self::plain( $text ), self::DESCRIPTION_LENGTH );
	}

	/**
	 * Blocs analysés d'un contenu, gardés pour la requête.
	 *
	 * @param \WP_Post $post Contenu.
	 * @return array<mixed>
	 */
	private static function blocks_of( \WP_Post $post ): array {
		if ( ! isset( self::$parsed[ $post->ID ] ) ) {
			self::$parsed[ $post->ID ] = parse_blocks( $post->post_content );
		}
		return self::$parsed[ $post->ID ];
	}

	/** Oublie les blocs analysés (tests, contenu modifié dans la même requête). */
	public static function reset(): void {
		self::$parsed = array();
		self::$image  = null;
	}

	/**
	 * Premiers paragraphes du contenu, jusqu'à la longueur d'une description, à toute profondeur
	 * (groupes, colonnes, sections). excerpt_remove_blocks() ne garde que le premier niveau :
	 * une page construite en sections n'avait donc aucune description.
	 *
	 * @param \WP_Post $post Contenu.
	 */
	private static function text_from_blocks( \WP_Post $post ): string {
		$parts   = array();
		$visited = 0;
		self::collect_paragraphs( self::blocks_of( $post ), 0, $parts, $visited );
		return implode( ' ', $parts );
	}

	/**
	 * Parcours borné (MAX_BLOCKS, MAX_DEPTH). Écarte les paragraphes vides, les consignes
	 * entre crochets et ceux qui ne contiennent qu'un lien (lien de langue, bouton texte).
	 *
	 * @param array<mixed> $blocks  Blocs.
	 * @param int          $depth   Profondeur courante.
	 * @param string[]     $parts   Textes gardés.
	 * @param int          $visited Blocs déjà visités.
	 */
	private static function collect_paragraphs( array $blocks, int $depth, array &$parts, int &$visited ): void {
		foreach ( $blocks as $block ) {
			if ( ++$visited > self::MAX_BLOCKS || mb_strlen( implode( ' ', $parts ) ) >= self::DESCRIPTION_LENGTH ) {
				return;
			}
			if ( ! is_array( $block ) ) {
				continue;
			}
			$html = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
			if ( in_array( $block['blockName'] ?? null, array( 'core/paragraph', null ), true ) ) {
				$text = self::plain( strip_shortcodes( $html ) );
				if ( '' !== $text && 1 !== preg_match( '/^\[.*\]$/su', $text ) && 1 !== preg_match( '#^\s*(<p\b[^>]*>)?\s*<a\b[^>]*>.*</a>\s*(</p>)?\s*$#is', $html ) ) {
					$parts[] = $text;
				}
			}
			if ( $depth < self::MAX_DEPTH && is_array( $block['innerBlocks'] ?? null ) ) {
				self::collect_paragraphs( $block['innerBlocks'], $depth + 1, $parts, $visited );
			}
		}
	}

	/**
	 * Questions et réponses des blocs Détails (core/details) du contenu, à toute profondeur.
	 * Seuls les résumés qui finissent par « ? » comptent : un bloc Détails qui sert de section
	 * repliable (« Modules », « Délais ») ne fait pas d'une page une FAQ.
	 *
	 * @param \WP_Post $post Contenu.
	 * @return list<array{0: string, 1: string}>
	 */
	public static function faq_pairs( \WP_Post $post ): array {
		/**
		 * Les blocs Détails décrivent-ils une FAQ? Faux : aucune paire, donc aucun FAQPage,
		 * que l'émetteur soit la Suite ou Fluffless SEO.
		 *
		 * @param bool     $on   Réglage « faq_schema » de la Suite (vrai par défaut).
		 * @param \WP_Post $post Contenu.
		 */
		$on = apply_filters( 'fluffless_suite_seo_faq', false !== Options::get( 'seo', 'faq_schema' ), $post );
		if ( true !== $on ) {
			return array();
		}
		$pairs   = array();
		$visited = 0;
		self::collect_details( self::blocks_of( $post ), 0, $pairs, $visited );
		return $pairs;
	}

	/**
	 * Parcours borné des blocs Détails : question = résumé, réponse = texte des blocs internes.
	 *
	 * @param array<mixed>                      $blocks  Blocs.
	 * @param int                               $depth   Profondeur courante.
	 * @param list<array{0: string, 1: string}> $pairs   Paires gardées.
	 * @param int                               $visited Blocs déjà visités.
	 */
	private static function collect_details( array $blocks, int $depth, array &$pairs, int &$visited ): void {
		foreach ( $blocks as $block ) {
			if ( ++$visited > self::MAX_BLOCKS || count( $pairs ) >= self::MAX_FAQ ) {
				return;
			}
			if ( ! is_array( $block ) ) {
				continue;
			}
			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			if ( 'core/details' === ( $block['blockName'] ?? null ) ) {
				$html     = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
				$question = 1 === preg_match( '#<summary\b[^>]*>(.*?)</summary>#is', $html, $match ) ? self::plain( $match[1] ) : '';
				$answer   = self::shorten( self::plain( self::inner_html( $inner, 0 ) ), 1000 );
				if ( self::is_question( $question ) && '' !== $answer ) {
					$pairs[] = array( $question, $answer );
				}
				continue;
			}
			if ( $depth < self::MAX_DEPTH ) {
				self::collect_details( $inner, $depth + 1, $pairs, $visited );
			}
		}
	}

	/**
	 * Produits du contenu (blocs Produit de la Vente simple) en nœuds Product avec leur offre :
	 * nom du premier titre, prix du bloc Prix, description des paragraphes et listes, image et
	 * adresse du bouton de paiement. Un produit sans nom ou sans montant n'est pas écrit.
	 *
	 * @param \WP_Post $post Contenu.
	 * @param string   $url  Adresse canonique du contenu.
	 * @return list<array<string, mixed>>
	 */
	public static function products( \WP_Post $post, string $url ): array {
		$nodes   = array();
		$visited = 0;
		self::collect_products( self::blocks_of( $post ), 0, $url, $nodes, $visited );
		return $nodes;
	}

	/**
	 * Parcours borné des blocs Produit, à toute profondeur.
	 *
	 * @param array<mixed>               $blocks  Blocs.
	 * @param int                        $depth   Profondeur courante.
	 * @param string                     $url     Adresse du contenu.
	 * @param list<array<string, mixed>> $nodes   Nœuds gardés.
	 * @param int                        $visited Blocs déjà visités.
	 */
	private static function collect_products( array $blocks, int $depth, string $url, array &$nodes, int &$visited ): void {
		foreach ( $blocks as $block ) {
			if ( ++$visited > self::MAX_BLOCKS || count( $nodes ) >= self::MAX_PRODUCTS ) {
				return;
			}
			if ( ! is_array( $block ) ) {
				continue;
			}
			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			if ( 'fluffless/produit' === ( $block['blockName'] ?? null ) ) {
				$node = self::product_node( $inner, $url, count( $nodes ) + 1 );
				if ( null !== $node ) {
					$nodes[] = $node;
				}
				continue;
			}
			if ( $depth < self::MAX_DEPTH ) {
				self::collect_products( $inner, $depth + 1, $url, $nodes, $visited );
			}
		}
	}

	/**
	 * Nœud Product d'un bloc Produit, ou null sans nom ni montant.
	 *
	 * @param array<mixed> $blocks Blocs internes du produit.
	 * @param string       $url    Adresse du contenu.
	 * @param int          $rank   Rang du produit dans la page (1 et plus).
	 * @return array<string, mixed>|null
	 */
	private static function product_node( array $blocks, string $url, int $rank ): ?array {
		$parts = array(
			'name'  => '',
			'image' => '',
			'link'  => '',
			'price' => array(),
			'text'  => array(),
		);
		self::product_parts( $blocks, 0, $parts );
		$price = Sections::price_attributes( $parts['price'] );
		if ( '' === $parts['name'] || 0.0 === $price['amount'] ) {
			return null;
		}
		return array_filter(
			array(
				'@type'       => 'Product',
				'@id'         => $url . '#produit-' . $rank,
				'name'        => $parts['name'],
				'description' => self::shorten( self::plain( implode( ' ', $parts['text'] ) ), 300 ),
				'image'       => $parts['image'],
				'offers'      => array(
					'@type'         => 'Offer',
					'price'         => number_format( $price['amount'], 2, '.', '' ),
					'priceCurrency' => $price['currency'],
					'availability'  => Sections::AVAILABILITY[ $price['availability'] ],
					'url'           => '' !== $parts['link'] ? $parts['link'] : $url,
				),
			)
		);
	}

	/**
	 * Morceaux d'un produit lus dans ses blocs : premier titre, premier bloc Prix, première
	 * image, premier bouton, paragraphes et listes.
	 *
	 * @param array<mixed>                                                                              $blocks Blocs.
	 * @param int                                                                                       $depth  Profondeur courante.
	 * @param array{name: string, image: string, link: string, price: array<mixed>, text: list<string>} $parts  Morceaux trouvés.
	 */
	private static function product_parts( array $blocks, int $depth, array &$parts ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = $block['blockName'] ?? null;
			$html  = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			if ( 'core/heading' === $name && '' === $parts['name'] ) {
				$parts['name'] = self::plain( $html );
			} elseif ( 'fluffless/prix' === $name && array() === $parts['price'] ) {
				$parts['price'] = $attrs;
			} elseif ( 'core/image' === $name && '' === $parts['image'] ) {
				$parts['image'] = self::image_of( $attrs, $html );
			} elseif ( 'core/button' === $name && '' === $parts['link'] ) {
				$parts['link'] = 1 === preg_match( '#\shref="([^"]+)"#i', $html, $match ) ? esc_url_raw( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
			} elseif ( 'core/paragraph' === $name ) {
				$parts['text'][] = $html;
			} elseif ( 'core/list' === $name ) {
				$parts['text'][] = self::inner_html( $inner, 0 );
			} elseif ( $depth < self::MAX_DEPTH && array() !== $inner ) {
				self::product_parts( $inner, $depth + 1, $parts );
			}
		}
	}

	/**
	 * Adresse de l'image d'un bloc Image : taille large de la médiathèque, sinon le src écrit.
	 *
	 * @param array<mixed> $attrs Attributs du bloc.
	 * @param string       $html  HTML du bloc.
	 */
	private static function image_of( array $attrs, string $html ): string {
		$id = $attrs['id'] ?? 0;
		if ( is_int( $id ) && $id > 0 ) {
			$src = wp_get_attachment_image_url( $id, 'large' );
			if ( is_string( $src ) && '' !== $src ) {
				return $src;
			}
		}
		return 1 === preg_match( '#<img\b[^>]*\ssrc="([^"]+)"#i', $html, $match ) ? esc_url_raw( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
	}

	/**
	 * Résumé formulé en question : finit par « ? » (ou « ？ »), espaces et insécables ignorés.
	 *
	 * @param string $summary Résumé en texte brut.
	 */
	public static function is_question( string $summary ): bool {
		return 1 === preg_match( '/[?\x{FF1F}][\s\x{00A0}\x{202F}]*$/u', $summary );
	}

	/**
	 * HTML statique de blocs imbriqués, sans rendu (aucun bloc dynamique exécuté).
	 *
	 * @param array<mixed> $blocks Blocs.
	 * @param int          $depth  Profondeur courante.
	 */
	private static function inner_html( array $blocks, int $depth ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$html .= ' ' . ( is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '' );
			if ( $depth < self::MAX_DEPTH && is_array( $block['innerBlocks'] ?? null ) ) {
				$html .= self::inner_html( $block['innerBlocks'], $depth + 1 );
			}
		}
		return $html;
	}

	/** Langue du contenu affiché (locale, ex. fr_CA) : celle de Polylang si présent, sinon celle du site. */
	public static function content_locale(): string {
		$object = get_queried_object();
		if ( $object instanceof \WP_Post && function_exists( 'pll_get_post_language' ) ) {
			$locale = pll_get_post_language( $object->ID, 'locale' );
			if ( is_string( $locale ) && '' !== $locale ) {
				return $locale;
			}
		}
		return get_locale();
	}

	/**
	 * Traductions publiées du contenu (Polylang), locale => adresse ; vide s'il n'y en a pas d'autre.
	 *
	 * @return array<string, string>
	 */
	public static function alternates(): array {
		$object = get_queried_object();
		if ( ! is_singular() || ! $object instanceof \WP_Post || ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_get_post_language' ) ) {
			return array();
		}
		$urls = array();
		foreach ( (array) pll_get_post_translations( $object->ID ) as $translation ) {
			$id     = (int) $translation;
			$locale = pll_get_post_language( $id, 'locale' );
			$url    = get_permalink( $id );
			if ( 'publish' === get_post_status( $id ) && is_string( $locale ) && '' !== $locale && is_string( $url ) ) {
				$urls[ $locale ] = $url;
			}
		}
		return count( $urls ) > 1 ? $urls : array();
	}

	/**
	 * Locale WordPress (fr_CA) en étiquette de langue BCP 47 (fr-CA), pour hreflang et inLanguage.
	 *
	 * @param string $locale Locale.
	 */
	private static function language_tag( string $locale ): string {
		return str_replace( '_', '-', $locale );
	}

	/** Adresse canonique ; vide pour les pages sans version de référence (dates, auteurs). */
	public static function canonical_url(): string {
		$object = get_queried_object();
		if ( is_singular() ) {
			$url = wp_get_canonical_url();
		} elseif ( is_front_page() ) {
			$url = home_url( '/' );
		} elseif ( is_home() ) {
			$url = get_permalink( (int) get_option( 'page_for_posts' ) );
		} elseif ( $object instanceof \WP_Term ) {
			$url = get_term_link( $object );
		} else {
			return '';
		}
		if ( ! is_string( $url ) ) {
			return '';
		}
		$paged = (int) get_query_var( 'paged' );
		return $paged > 1 && ! is_singular() ? get_pagenum_link( $paged, false ) : $url;
	}

	/**
	 * Image de partage : image mise en avant, puis image par défaut, puis logo ; puis le filtre
	 * fluffless_seo_image, appliqué une seule fois par requête (og:image, twitter:image et le
	 * champ image des nœuds schema.org lisent la même valeur).
	 */
	public static function image_url(): string {
		if ( null !== self::$image ) {
			return self::$image;
		}
		$url = '';
		if ( is_singular() ) {
			$featured = (int) get_post_thumbnail_id( get_queried_object_id() );
			$url      = $featured > 0 ? self::share_image_url( $featured ) : '';
		}
		if ( '' === $url ) {
			$default = Options::get_int( self::id(), 'social_image_id' );
			$chosen  = $default > 0 ? self::share_image_url( $default ) : '';
			$url     = '' !== $chosen ? $chosen : self::logo_url();
		}
		/**
		 * Image de partage de la page. Rendre une chaîne vide retire og:image et twitter:image
		 * (la carte X passe à « summary »). Le même filtre existe dans Fluffless SEO.
		 *
		 * @param string $url     Adresse absolue résolue, ou chaîne vide.
		 * @param int    $post_id Contenu dont les champs SEO s'appliquent (page des articles comprise), sinon 0.
		 */
		$filtered    = apply_filters( 'fluffless_seo_image', $url, self::context_post_id() );
		self::$image = is_string( $filtered ) ? esc_url_raw( $filtered ) : ''; // @phpstan-ignore function.alreadyNarrowedType (un filtre tiers peut rendre autre chose qu'une chaîne)
		return self::$image;
	}

	/**
	 * Taille « large » d'une image, pour og:image. Si le site l'a seulement convertie en WebP ou AVIF
	 * à l'envoi, l'original JPEG ou PNG resté sur disque : plusieurs réseaux affichent mal un og:image
	 * WebP. L'original n'est pris que s'il a les mêmes dimensions que le fichier principal (ni réduit
	 * en « -scaled » ni pivoté), sans orientation EXIF, jamais retouché dans l'éditeur de médias
	 * (l'original ignorerait la retouche), et s'il pèse au plus 5 Mo (plafond de LinkedIn).
	 * Un média déporté vers un CDN garde la taille « large ». Fluffless SEO s'en sert aussi.
	 *
	 * @param int $id Fichier joint.
	 */
	public static function share_image_url( int $id ): string {
		$url = wp_get_attachment_image_url( $id, 'large' );
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		if ( ! self::has_extension( $url, 'webp|avif' ) || ! empty( get_post_meta( $id, '_wp_attachment_backup_sizes', true ) ) ) {
			return $url;
		}
		$meta = wp_get_attachment_metadata( $id );
		$path = wp_get_original_image_path( $id );
		if ( ! is_array( $meta ) || empty( $meta['original_image'] ) || ! is_string( $path ) || ! self::has_extension( $path, 'jpe?g|png' ) || ! is_file( $path ) || filesize( $path ) > 5 * MB_IN_BYTES ) {
			return $url;
		}
		$size = wp_getimagesize( $path );
		if ( ! is_array( $size ) || (int) ( $meta['width'] ?? 0 ) !== $size[0] || (int) ( $meta['height'] ?? 0 ) !== $size[1] ) {
			return $url;
		}
		if ( IMAGETYPE_JPEG === $size[2] && 1 !== self::jpeg_orientation( $path ) ) {
			return $url;
		}
		$original = wp_get_original_image_url( $id );
		return is_string( $original ) && self::has_extension( $original, 'jpe?g|png' ) ? $original : $url;
	}

	/**
	 * Orientation EXIF d'un JPEG : 1 sans EXIF, 0 si l'extension exif manque (on ne sait pas).
	 * Lue ici : wp_read_image_metadata() n'est chargée que dans l'admin, pas sur les pages publiques.
	 *
	 * @param string $path Chemin du JPEG.
	 */
	private static function jpeg_orientation( string $path ): int {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return 0;
		}
		$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- un EXIF malformé lève un avertissement.
		return is_array( $exif ) && ! empty( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;
	}

	/**
	 * L'adresse ou le chemin finit-il par une de ces extensions ?
	 *
	 * @param string $file       Adresse ou chemin.
	 * @param string $extensions Extensions en alternance d'expression régulière (webp|avif).
	 */
	private static function has_extension( string $file, string $extensions ): bool {
		$path = wp_parse_url( $file, PHP_URL_PATH );
		return 1 === preg_match( '/\\.(' . $extensions . ')$/i', is_string( $path ) ? $path : $file );
	}

	/** Contenu dont les champs SEO s'appliquent : contenu singulier, page des articles, sinon 0. */
	public static function context_post_id(): int {
		if ( is_singular() ) {
			return get_queried_object_id();
		}
		if ( is_home() ) {
			return (int) get_option( 'page_for_posts' );
		}
		return 0;
	}

	/** Logo : réglage, puis logo du thème, puis icône du site. */
	public static function logo_url(): string {
		$logo = Options::get_string( self::id(), 'logo_url' );
		if ( '' === $logo ) {
			$custom = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'full' );
			$logo   = is_string( $custom ) ? $custom : get_site_icon_url( 512 );
		}
		return $logo;
	}

	/** Bloc JSON-LD : WebSite, Organization, Article (articles), BreadcrumbList (contenus). */
	public static function json_ld(): string {
		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => self::graph(),
			),
			// JSON_HEX_TAG : aucun « </script> » possible dans le bloc, quel que soit le titre.
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
		);
		return false === $json ? '' : wp_get_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
	}

	/**
	 * Nœuds du graphe schema.org de la requête courante.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function graph(): array {
		$home      = home_url( '/' );
		$org_id    = $home . '#organization';
		$org_name  = Options::get_string( self::id(), 'org_name' );
		$site_name = self::plain( get_bloginfo( 'name' ) );
		$org       = array(
			'@type' => 'Organization',
			'@id'   => $org_id,
			'name'  => '' !== $org_name ? $org_name : $site_name,
			'url'   => $home,
		);
		$logo      = self::logo_url();
		if ( '' !== $logo ) {
			$org['logo'] = $logo;
		}
		$graph = array(
			array(
				'@type'      => 'WebSite',
				'@id'        => $home . '#website',
				'name'       => $site_name,
				'url'        => $home,
				'inLanguage' => get_bloginfo( 'language' ),
				'publisher'  => array( '@id' => $org_id ),
			),
			$org,
		);

		$post = get_queried_object();
		if ( ! is_singular() || ! $post instanceof \WP_Post ) {
			return $graph;
		}
		$url   = self::canonical_url();
		$title = self::plain( get_the_title( $post ) );
		if ( 'post' === $post->post_type ) {
			$article = array(
				'@type'            => 'Article',
				'headline'         => $title,
				'datePublished'    => (string) get_the_date( 'c', $post ),
				'dateModified'     => (string) get_the_modified_date( 'c', $post ),
				'author'           => array(
					'@type' => 'Person',
					'name'  => self::plain( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ),
				),
				'publisher'        => array( '@id' => $org_id ),
				'mainEntityOfPage' => $url,
				'inLanguage'       => self::language_tag( self::content_locale() ),
			);
			$image   = self::image_url();
			if ( '' !== $image ) {
				$article['image'] = $image;
			}
			$graph[] = $article;
		}
		foreach ( self::products( $post, $url ) as $product ) {
			$graph[] = $product;
		}
		if ( ! is_front_page() ) {
			$graph[] = self::breadcrumbs( $post, $url );
		}
		$faq = self::faq_pairs( $post );
		if ( array() !== $faq ) {
			$graph[] = array(
				'@type'      => 'FAQPage',
				'@id'        => $url . '#faq',
				'inLanguage' => self::language_tag( self::content_locale() ),
				'mainEntity' => array_map(
					static fn( array $pair ): array => array(
						'@type'          => 'Question',
						'name'           => $pair[0],
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => $pair[1],
						),
					),
					$faq
				),
			);
		}
		return $graph;
	}

	/**
	 * Fil d'Ariane : le même chemin que le bloc Fil d'Ariane (Presentation\Trail), avec l'adresse
	 * canonique comme dernier maillon. Un maillon sans adresse n'a pas d'item.
	 *
	 * @param \WP_Post $post Contenu courant.
	 * @param string   $url  Adresse canonique du contenu.
	 * @return array<string, mixed>
	 */
	private static function breadcrumbs( \WP_Post $post, string $url ): array {
		$items = array();
		foreach ( Trail::of( $post, $url ) as $index => $crumb ) {
			$item = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $crumb['name'],
			);
			if ( '' !== $crumb['url'] ) {
				$item['item'] = $crumb['url'];
			}
			$items[] = $item;
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}

	/**
	 * Texte brut : balises retirées, entités décodées, espaces normalisées.
	 *
	 * @param string $text Texte ou HTML.
	 */
	private static function plain( string $text ): string {
		return Trail::plain( $text );
	}

	/**
	 * Coupe au dernier mot entier avant la limite et ajoute « … ».
	 *
	 * @param string $text  Texte brut.
	 * @param int    $limit Nombre de caractères.
	 */
	private static function shorten( string $text, int $limit ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $limit - 1 );
		$space = mb_strrpos( $cut, ' ' );
		// Expression plutôt que rtrim() : rtrim() couperait octet par octet une espace insécable (C2 A0).
		return (string) preg_replace( '/[\s,;:.-]+$/u', '', false === $space ? $cut : mb_substr( $cut, 0, $space ) ) . '…';
	}
}
