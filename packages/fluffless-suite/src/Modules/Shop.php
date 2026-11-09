<?php
/**
 * Module Boutique sobre : WooCommerce ne pèse que sur les pages de boutique.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\PluginAction;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Mesuré le 18 septembre 2026 (docs/boutique-sobre-2026-09.md) : WooCommerce ajoute
 * environ 130 ko et 26 requêtes à chaque page du site, boutique ou non. Le module
 * ne touche à rien tant que WooCommerce est absent.
 */
final class Shop implements Module {

	/** Feuilles de style de WooCommerce retirées des pages sans boutique. */
	public const STYLES = array(
		'woocommerce-layout',
		'woocommerce-smallscreen',
		'woocommerce-general',
		'woocommerce-blocktheme',
		'woocommerce-inline',
		'wc-blocks-style',
		'wc-blocks-packages-style',
	);

	/** Scripts de WooCommerce retirés des pages sans boutique (l'attribution a son propre réglage). */
	public const SCRIPTS = array(
		'wc-add-to-cart',
		'woocommerce',
		'wc-cart-fragments',
		'wc-jquery-blockui',
		'wc-js-cookie',
	);

	/** Scripts de l'attribution des commandes. */
	public const ATTRIBUTION_SCRIPTS = array( 'sourcebuster-js', 'wc-order-attribution' );

	/** Blocs que WooCommerce accroche à l'en-tête des thèmes de blocs. */
	public const HEADER_BLOCKS = array( 'woocommerce/mini-cart', 'woocommerce/customer-account' );

	/** Codes courts de WooCommerce qui ne commencent ni par « product » ni par « woocommerce_ ». */
	private const OTHER_SHORTCODES = array( 'add_to_cart', 'add_to_cart_url', 'shop_messages' );

	/**
	 * Un bloc ou un code court de WooCommerce a été rendu dans la page en cours.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/**
	 * Colonnes de la grille de produits en cours de rendu, 0 hors d’une grille.
	 *
	 * @var int
	 */
	private static int $columns = 0;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'shop';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Lean shop', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Keeps WooCommerce on the pages that sell. Its scripts and styles leave the rest of the site, order tracking follows the visitor’s choice, and product images stop being served at full size.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'assets'       => true,
			'header'       => true,
			'attribution'  => 'off',
			'gallery_full' => true,
			'grid_sizes'   => true,
			'prefetch'     => true,
			'marketplace'  => true,
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
			'assets'       => array(
				'type'  => 'bool',
				'label' => __( 'Load WooCommerce only on shop pages', 'fluffless-suite' ),
				'help'  => __( 'A page counts as a shop page when it is the shop, a product, the cart, the checkout, the account, or when it holds a WooCommerce block or shortcode. Everywhere else, WooCommerce’s scripts and styles are not sent.', 'fluffless-suite' ),
			),
			'header'       => array(
				'type'  => 'bool',
				'label' => __( 'Keep the mini-cart and account icon out of the header', 'fluffless-suite' ),
				'help'  => __( 'WooCommerce adds them to the header of block themes on its own, which loads its store scripts on every page. Add the Mini-Cart block to your header yourself if you want it.', 'fluffless-suite' ),
			),
			'attribution'  => array(
				'type'    => 'choice',
				'label'   => __( 'Order attribution', 'fluffless-suite' ),
				'help'    => __( 'WooCommerce records where each visitor came from in cookies and attaches it to their order. With consent, nothing is recorded until the visitor accepts analytics in the Fluffless Consent banner.', 'fluffless-suite' ),
				'options' => array(
					'off'     => __( 'Off (recommended)', 'fluffless-suite' ),
					'consent' => __( 'After consent, with Fluffless Consent', 'fluffless-suite' ),
					'keep'    => __( 'On, as WooCommerce ships it', 'fluffless-suite' ),
				),
			),
			'gallery_full' => array(
				'type'  => 'bool',
				'label' => __( 'Serve the large size in the product gallery, not the original', 'fluffless-suite' ),
				'help'  => __( 'The gallery zoom and lightbox use the original upload, often several megabytes. The large size (1024 pixels by default) is enough on screen.', 'fluffless-suite' ),
			),
			'grid_sizes'   => array(
				'type'  => 'bool',
				'label' => __( 'Size product grid images by their column count', 'fluffless-suite' ),
				'help'  => __( 'The product grid tells the browser each image fills the screen, so it downloads a large file for a small tile. With the real column width, a phone gets the small size instead.', 'fluffless-suite' ),
			),
			'prefetch'     => array(
				'type'  => 'bool',
				'label' => __( 'Do not prefetch the cart and checkout scripts', 'fluffless-suite' ),
				'help'  => __( 'As soon as the cart has something in it, WooCommerce asks the browser to download dozens of checkout files in advance, on every page. They load when the visitor gets there.', 'fluffless-suite' ),
			),
			'marketplace'  => array(
				'type'  => 'bool',
				'label' => __( 'No marketplace suggestions, no usage tracking', 'fluffless-suite' ),
				'help'  => __( 'Hides WooCommerce’s extension suggestions in the admin and keeps it from sending usage data to WooCommerce.com.', 'fluffless-suite' ),
			),
		);
	}

	/** WooCommerce est chargé (toutes les extensions sont incluses quand les modules s’enregistrent). */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce', false );
	}

	/** Fluffless Consent est chargée. */
	public static function consent_active(): bool {
		return class_exists( '\\Fluffless\\Consent\\Consent', false );
	}

	/** État affiché dans les réglages. */
	public static function status(): string {
		if ( ! self::woocommerce_active() ) {
			return __( 'WooCommerce is not active: this module has nothing to do until it is. To use it: 1) go to Plugins → Add Plugin and install WooCommerce (or find it under Installed Plugins), 2) activate it and follow its setup, 3) come back here and choose what to trim.', 'fluffless-suite' );
		}
		if ( 'consent' === Options::get_string( self::id(), 'attribution' ) && ! self::consent_active() ) {
			return __( 'Fluffless Consent is not active: order attribution stays off until it is. Activate it under Plugins → Installed Plugins, or choose another attribution setting below.', 'fluffless-suite' );
		}
		return '';
	}

	/**
	 * Bouton sous l'état : activer ou installer WooCommerce, sinon Fluffless Consent si l'attribution l'attend.
	 *
	 * @return array{text: string, url: string}|null
	 */
	public static function status_action(): ?array {
		if ( ! self::woocommerce_active() ) {
			return PluginAction::for_plugin( 'woocommerce/woocommerce.php', 'WooCommerce' );
		}
		if ( 'consent' === Options::get_string( self::id(), 'attribution' ) && ! self::consent_active() && file_exists( WP_PLUGIN_DIR . '/fluffless-consent/fluffless-consent.php' ) ) {
			return PluginAction::for_plugin( 'fluffless-consent/fluffless-consent.php', 'Fluffless Consent' );
		}
		return null;
	}

	/** {@inheritDoc} */
	public function register(): void {
		if ( ! self::woocommerce_active() ) {
			return;
		}
		if ( Options::get_bool( self::id(), 'header' ) ) {
			add_filter( 'hooked_block_types', array( self::class, 'drop_header_blocks' ), 20 );
		}
		if ( Options::get_bool( self::id(), 'assets' ) ) {
			add_filter( 'render_block', array( self::class, 'note_block' ), 10, 2 );
			add_filter( 'do_shortcode_tag', array( self::class, 'note_shortcode' ), 10, 2 );
			add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue_off_shop' ), 100 );
			// Les avis de WooCommerce rajoutent wc-blocks-style à wp_head (priorité 10), après l'impression des feuilles.
			add_action( 'wp_print_footer_scripts', array( self::class, 'dequeue_off_shop' ), 1 );
		}
		$this->register_attribution( Options::get_string( self::id(), 'attribution' ) );
		if ( Options::get_bool( self::id(), 'gallery_full' ) ) {
			add_filter( 'woocommerce_gallery_full_size', array( self::class, 'gallery_full_size' ) );
		}
		if ( Options::get_bool( self::id(), 'grid_sizes' ) ) {
			add_filter( 'render_block_data', array( self::class, 'note_columns' ) );
			add_filter( 'render_block', array( self::class, 'forget_columns' ), 10, 2 );
			add_filter( 'wp_calculate_image_sizes', array( self::class, 'grid_sizes' ) );
		}
		if ( Options::get_bool( self::id(), 'prefetch' ) ) {
			add_action( 'wp_loaded', array( self::class, 'unhook_prefetch' ) );
			add_filter( 'wp_resource_hints', array( self::class, 'drop_prefetch' ), 20, 2 );
		}
		if ( Options::get_bool( self::id(), 'marketplace' ) ) {
			add_filter( 'pre_option_woocommerce_show_marketplace_suggestions', array( self::class, 'no' ) );
			add_filter( 'pre_option_woocommerce_allow_tracking', array( self::class, 'no' ) );
		}
	}

	/**
	 * Retire le mini-panier et l’icône de compte des blocs accrochés, quel que soit l’ancrage.
	 *
	 * @param mixed $hooked Types de blocs accrochés.
	 * @return mixed
	 */
	public static function drop_header_blocks( $hooked ) {
		if ( ! is_array( $hooked ) ) {
			return $hooked;
		}
		return array_values( array_diff( $hooked, self::HEADER_BLOCKS ) );
	}

	/**
	 * Note qu’un bloc de WooCommerce a été rendu (les thèmes de blocs rendent le gabarit avant wp_head).
	 *
	 * @param mixed $content Rendu du bloc.
	 * @param mixed $block   Bloc analysé.
	 * @return mixed
	 */
	public static function note_block( $content, $block ) {
		$name = is_array( $block ) ? ( $block['blockName'] ?? '' ) : '';
		if ( is_string( $name ) && str_starts_with( $name, 'woocommerce/' ) ) {
			self::$rendered = true;
		}
		return $content;
	}

	/**
	 * Note qu’un code court de WooCommerce a été rendu.
	 *
	 * @param mixed $output Rendu du code court.
	 * @param mixed $tag    Nom du code court.
	 * @return mixed
	 */
	public static function note_shortcode( $output, $tag ) {
		if ( is_string( $tag ) && self::is_shop_shortcode( $tag ) ) {
			self::$rendered = true;
		}
		return $output;
	}

	/**
	 * Code court de WooCommerce.
	 *
	 * @param string $tag Nom du code court.
	 */
	public static function is_shop_shortcode( string $tag ): bool {
		return str_starts_with( $tag, 'product' ) || str_starts_with( $tag, 'woocommerce_' ) || in_array( $tag, self::OTHER_SHORTCODES, true );
	}

	/**
	 * Le contenu contient un bloc ou un code court de WooCommerce.
	 *
	 * @param string $content Contenu brut d’un article ou d’une page.
	 */
	public static function has_shop_content( string $content ): bool {
		if ( str_contains( $content, '<!-- wp:woocommerce/' ) ) {
			return true;
		}
		if ( ! str_contains( $content, '[' ) ) {
			return false;
		}
		preg_match_all( '/\[([a-z_]+)/', $content, $matches );
		foreach ( $matches[1] as $tag ) {
			if ( self::is_shop_shortcode( $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/** La page en cours est une page de boutique : WooCommerce y garde tout. */
	public static function is_shop_page(): bool {
		$shop = self::$rendered
			|| ( function_exists( 'is_woocommerce' ) && is_woocommerce() )
			|| ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );
		if ( ! $shop && is_singular() ) {
			$post = get_post();
			$shop = $post instanceof \WP_Post && self::has_shop_content( (string) $post->post_content );
		}
		/**
		 * Force ou empêche le retrait de WooCommerce sur la page en cours.
		 *
		 * @param bool $shop True si la page a besoin de WooCommerce.
		 */
		return (bool) apply_filters( 'fluffless_shop_page', $shop );
	}

	/** Retire les scripts et feuilles de WooCommerce quand la page n’a pas de boutique. */
	public static function dequeue_off_shop(): void {
		if ( self::is_shop_page() ) {
			return;
		}
		foreach ( self::STYLES as $handle ) {
			wp_dequeue_style( $handle );
		}
		foreach ( self::SCRIPTS as $handle ) {
			wp_dequeue_script( $handle );
		}
	}

	/**
	 * Attribution des commandes : coupée, après consentement, ou telle quelle.
	 *
	 * @param string $mode off, consent ou keep.
	 */
	private function register_attribution( string $mode ): void {
		if ( 'keep' === $mode ) {
			return;
		}
		if ( 'consent' === $mode && self::consent_active() ) {
			add_filter( 'wc_order_attribution_allow_tracking', '__return_false' );
			add_filter( 'fluffless_consent_services', array( self::class, 'declare_service' ) );
			add_action( 'wp_enqueue_scripts', array( self::class, 'add_consent_bridge' ), 100 );
			return;
		}
		add_filter( 'wc_order_attribution_allow_tracking', '__return_false' );
		add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue_attribution' ), 100 );
	}

	/** Retire les scripts de l’attribution partout. */
	public static function dequeue_attribution(): void {
		foreach ( self::ATTRIBUTION_SCRIPTS as $handle ) {
			wp_dequeue_script( $handle );
		}
	}

	/**
	 * Déclare l’attribution à Fluffless Consent, catégorie statistiques.
	 *
	 * @param mixed $services Services déclarés.
	 * @return mixed
	 */
	public static function declare_service( $services ) {
		if ( ! is_array( $services ) ) {
			return $services;
		}
		$services['woocommerce-attribution'] = array(
			'category' => 'analytics',
			'label'    => __( 'WooCommerce order attribution', 'fluffless-suite' ),
		);
		return $services;
	}

	/**
	 * Relie l’attribution au consentement : suit chaque décision, y compris celle enregistrée
	 * d’une visite précédente (Consent la rejoue au chargement de chaque page).
	 */
	public static function add_consent_bridge(): void {
		if ( ! wp_script_is( 'wc-order-attribution', 'enqueued' ) ) {
			return;
		}
		wp_add_inline_script(
			'wc-order-attribution',
			'document.addEventListener("fluffless:consent",function(e){var a=window.wc_order_attribution;if(a&&a.setOrderTracking){a.setOrderTracking(!!(e.detail&&e.detail.analytics));}});'
		);
	}

	/**
	 * Retient le nombre de colonnes d’une grille de produits avant son rendu.
	 *
	 * @param mixed $block Bloc analysé.
	 * @return mixed
	 */
	public static function note_columns( $block ) {
		if ( is_array( $block ) && 'woocommerce/product-template' === ( $block['blockName'] ?? '' ) ) {
			$layout        = $block['attrs']['layout'] ?? array();
			$type          = is_array( $layout ) ? ( $layout['type'] ?? 'grid' ) : 'grid';
			$count         = is_array( $layout ) ? (int) ( $layout['columnCount'] ?? 3 ) : 3;
			self::$columns = 'grid' === $type ? max( 1, $count ) : 1;
		}
		return $block;
	}

	/**
	 * Oublie la grille une fois rendue.
	 *
	 * @param mixed $content Rendu du bloc.
	 * @param mixed $block   Bloc rendu.
	 * @return mixed
	 */
	public static function forget_columns( $content, $block ) {
		if ( is_array( $block ) && 'woocommerce/product-template' === ( $block['blockName'] ?? '' ) ) {
			self::$columns = 0;
		}
		return $content;
	}

	/**
	 * Attribut sizes d’une image de grille : la largeur d’une colonne, pas l’écran entier.
	 * Le bloc image de WooCommerce force la taille large et « 100vw » ; le navigateur prend
	 * alors un fichier de 1024 px pour une vignette de 160 px sur un cellulaire.
	 *
	 * @param mixed $sizes Attribut calculé par WordPress.
	 * @return mixed
	 */
	public static function grid_sizes( $sizes ) {
		if ( self::$columns < 1 ) {
			return $sizes;
		}
		return self::grid_sizes_for( self::$columns, self::wide_size() );
	}

	/**
	 * Formule de l’attribut sizes pour une grille.
	 *
	 * @param int    $columns Colonnes.
	 * @param string $wide    Largeur maximale de la mise en page (unité CSS), vide si aucune.
	 */
	public static function grid_sizes_for( int $columns, string $wide ): string {
		$columns = max( 1, $columns );
		$width   = '' === $wide ? '100vw' : sprintf( 'min(100vw, %s)', $wide );
		if ( 1 === $columns ) {
			return $width;
		}
		return sprintf( 'calc(%s / %d)', $width, $columns );
	}

	/**
	 * Largeur large du thème (theme.json), ou vide.
	 */
	private static function wide_size(): string {
		$layout = wp_get_global_settings( array( 'layout' ) );
		$wide   = is_array( $layout ) ? ( $layout['wideSize'] ?? $layout['contentSize'] ?? '' ) : '';
		return is_string( $wide ) ? trim( $wide ) : '';
	}

	/**
	 * Taille servie par le zoom et la visionneuse de la galerie : « large » quand elle existe.
	 *
	 * @param mixed $size Taille d’origine (« full »).
	 * @return mixed
	 */
	public static function gallery_full_size( $size ) {
		return isset( wp_get_registered_image_subsizes()['large'] ) ? 'large' : $size;
	}

	/**
	 * Décroche le préchargement de WooCommerce à la source : il précharge aussi des scripts
	 * de WordPress (React, composants) que le filtre par adresse ne peut pas reconnaître.
	 */
	public static function unhook_prefetch(): void {
		$package    = 'Automattic\\WooCommerce\\Blocks\\Package';
		$controller = 'Automattic\\WooCommerce\\Blocks\\AssetsController';
		$factory    = array( $package, 'container' );
		if ( ! class_exists( $package ) || ! class_exists( $controller ) || ! is_callable( $factory ) ) {
			return;
		}
		try {
			$container = call_user_func( $factory );
			$instance  = is_object( $container ) && method_exists( $container, 'get' ) ? $container->get( $controller ) : null;
		} catch ( \Throwable $e ) {
			return;
		}
		if ( is_object( $instance ) ) {
			remove_filter( 'wp_resource_hints', array( $instance, 'add_resource_hints' ), 10 );
		}
	}

	/**
	 * Retire les préchargements restants de WooCommerce par leur adresse (prefetch et prerender).
	 *
	 * @param mixed  $urls          Indices de ressources.
	 * @param string $relation_type Type de relation.
	 * @return mixed
	 */
	public static function drop_prefetch( $urls, $relation_type ) {
		if ( ! in_array( $relation_type, array( 'prefetch', 'prerender' ), true ) || ! is_array( $urls ) ) {
			return $urls;
		}
		return array_values(
			array_filter(
				$urls,
				static function ( $url ): bool {
					$href = is_array( $url ) ? ( $url['href'] ?? '' ) : $url;
					return ! ( is_string( $href ) && str_contains( $href, '/woocommerce/' ) );
				}
			)
		);
	}

	/** Valeur « no » pour les options de WooCommerce court-circuitées. */
	public static function no(): string {
		return 'no';
	}
}
