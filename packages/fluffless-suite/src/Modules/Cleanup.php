<?php
/**
 * Module Nettoyage : retire ce que WordPress ajoute à chaque page sans que le site s'en serve.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Chaque sous-option a son interrupteur. Les styles globaux de WordPress ne sont
 * jamais touchés : le thème en dépend.
 */
final class Cleanup implements Module {

	/** Écrans où l'autosauvegarde et le verrouillage des articles ont besoin du heartbeat. */
	private const HEARTBEAT_SCREENS = array( 'post.php', 'post-new.php', 'site-editor.php' );

	/** Intervalle du heartbeat hors éditeurs, en secondes (le maximum accepté par WordPress). */
	public const HEARTBEAT_SLOW = 120;

	/** En-têtes ajoutés quand l'option security_headers est cochée. */
	public const SECURITY_HEADERS = array(
		'X-Content-Type-Options' => 'nosniff',
		'Referrer-Policy'        => 'strict-origin-when-cross-origin',
		'X-Frame-Options'        => 'SAMEORIGIN',
	);

	/** {@inheritDoc} */
	public static function id(): string {
		return 'cleanup';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Cleanup', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Removes code that WordPress adds to every page and that most sites never use. Global styles are left alone: your theme needs them.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'emoji'            => true,
			'oembed'           => true,
			'head_links'       => true,
			'comment_feeds'    => true,
			'heartbeat'        => true,
			'xmlrpc'           => true,
			'security_headers' => false,
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
			'emoji'            => array(
				'type'  => 'bool',
				'label' => __( 'Remove the emoji script and styles', 'fluffless-suite' ),
				'help'  => __( 'Current browsers show emoji on their own. Also removes the DNS prefetch to s.w.org.', 'fluffless-suite' ),
			),
			'oembed'           => array(
				'type'  => 'bool',
				'label' => __( 'Remove embed discovery for other sites', 'fluffless-suite' ),
				'help'  => __( 'Other sites can no longer turn your pages into embed cards, and the wp-embed script is gone. Your own YouTube or Vimeo embeds still work, unless Outgoing requests blocks them.', 'fluffless-suite' ),
			),
			'head_links'       => array(
				'type'  => 'bool',
				'label' => __( 'Remove legacy header links', 'fluffless-suite' ),
				'help'  => __( 'RSD, Windows Live Writer, shortlink and the WordPress version tag.', 'fluffless-suite' ),
			),
			'comment_feeds'    => array(
				'type'  => 'bool',
				'label' => __( 'Remove comment feeds', 'fluffless-suite' ),
				'help'  => __( 'Your main RSS feed stays.', 'fluffless-suite' ),
			),
			'heartbeat'        => array(
				'type'  => 'bool',
				'label' => __( 'Stop the heartbeat for visitors, slow it down in the admin', 'fluffless-suite' ),
				'help'  => __( 'The heartbeat checks in with the server every 15 to 60 seconds. Visitors never need it. In the admin it runs every 2 minutes, and at full speed in the editors, where autosave needs it.', 'fluffless-suite' ),
			),
			'xmlrpc'           => array(
				'type'  => 'bool',
				'label' => __( 'Turn off XML-RPC', 'fluffless-suite' ),
				'help'  => __( 'An old remote publishing protocol, often targeted by attacks. It stays on automatically while Jetpack is active. Leave it on if you use the WordPress mobile app.', 'fluffless-suite' ),
			),
			'security_headers' => array(
				'type'  => 'bool',
				'label' => __( 'Send basic security headers', 'fluffless-suite' ),
				'help'  => __( 'Adds nosniff, a strict referrer policy and same-origin framing to your pages, and hides the PHP version when the server allows it. Off by default: your host may already send them.', 'fluffless-suite' ),
			),
		);
	}

	/** {@inheritDoc} */
	public function register(): void {
		$map = array(
			'emoji'            => 'remove_emoji',
			'oembed'           => 'remove_oembed',
			'head_links'       => 'remove_head_links',
			'comment_feeds'    => 'remove_comment_feeds',
			'heartbeat'        => 'limit_heartbeat',
			'xmlrpc'           => 'disable_xmlrpc',
			'security_headers' => 'add_security_headers',
		);
		foreach ( $map as $key => $method ) {
			if ( Options::get_bool( self::id(), $key ) ) {
				$this->$method();
			}
		}
	}

	/** Émojis : scripts, styles, TinyMCE, DNS prefetch, flux et courriels. */
	private function remove_emoji(): void {
		self::unhook_emoji();
		// Les crochets d'administration sont posés après plugins_loaded (admin-filters.php).
		add_action( 'admin_init', array( self::class, 'unhook_emoji' ) );
		add_filter( 'tiny_mce_plugins', array( self::class, 'drop_tinymce_emoji' ) );
		add_filter( 'wp_resource_hints', array( self::class, 'drop_emoji_prefetch' ), 10, 2 );
	}

	/** Retire tous les crochets d'émojis de WordPress (visiteur, administration, intégrations, flux, courriels). */
	public static function unhook_emoji(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'embed_head', 'print_emoji_detection_script' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	}

	/**
	 * Retire l'extension TinyMCE des émojis (éditeur classique).
	 *
	 * @param mixed $plugins Extensions TinyMCE.
	 * @return mixed
	 */
	public static function drop_tinymce_emoji( $plugins ) {
		return is_array( $plugins ) ? array_values( array_diff( $plugins, array( 'wpemoji' ) ) ) : $plugins;
	}

	/**
	 * Retire le DNS prefetch vers s.w.org (CDN des émojis).
	 *
	 * @param mixed  $urls          Indices de ressources.
	 * @param string $relation_type Type de relation (dns-prefetch, preconnect...).
	 * @return mixed
	 */
	public static function drop_emoji_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
			return $urls;
		}
		return array_values(
			array_filter(
				$urls,
				static function ( $url ): bool {
					$href = is_array( $url ) ? ( $url['href'] ?? '' ) : $url;
					return ! ( is_string( $href ) && str_contains( $href, 's.w.org' ) );
				}
			)
		);
	}

	/** Découverte oEmbed et script wp-embed côté visiteur ; les intégrations sortantes restent. */
	private function remove_oembed(): void {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_filter( 'embed_oembed_html', 'wp_maybe_enqueue_oembed_host_js' );
	}

	/** Liens RSD, wlwmanifest, shortlink (balise et en-tête HTTP) et générateur. */
	private function remove_head_links(): void {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
	}

	/** Flux des commentaires (global et par article) ; le flux principal reste. */
	private function remove_comment_feeds(): void {
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_filter( 'feed_links_extra_show_post_comments_feed', '__return_false' );
	}

	/**
	 * Côté visiteur, heartbeat retiré ; dans l'administration, ralenti hors des éditeurs.
	 * Le retirer de l'administration casserait la reconnexion (wp-auth-check) et produirait un avertissement.
	 */
	private function limit_heartbeat(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'deregister_heartbeat' ), 1 );
		add_filter( 'heartbeat_settings', array( self::class, 'slow_heartbeat' ) );
	}

	/** Côté visiteur : aucun heartbeat. */
	public static function deregister_heartbeat(): void {
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Administration hors éditeurs : un battement toutes les HEARTBEAT_SLOW secondes.
	 * $pagenow et non l'écran courant : heartbeat_settings passe avant que l'écran soit connu.
	 *
	 * @param mixed $settings Réglages du heartbeat.
	 * @return mixed
	 */
	public static function slow_heartbeat( $settings ) {
		$page = is_string( $GLOBALS['pagenow'] ?? null ) ? $GLOBALS['pagenow'] : '';
		if ( ! is_array( $settings ) || ! is_admin() || in_array( $page, self::HEARTBEAT_SCREENS, true ) ) {
			return $settings;
		}
		$settings['interval'] = self::HEARTBEAT_SLOW;
		return $settings;
	}

	/**
	 * Jetpack (ou une autre extension, par le filtre) a besoin de XML-RPC.
	 */
	public static function xmlrpc_needed(): bool {
		/**
		 * Vrai si une extension active a besoin de XML-RPC.
		 *
		 * @param bool $needed Vrai si Jetpack est actif.
		 */
		return true === apply_filters( 'fluffless_xmlrpc_needed', defined( 'JETPACK__VERSION' ) || class_exists( 'Jetpack', false ) );
	}

	/** État affiché dans les réglages. */
	public static function status(): string {
		if ( Options::get_bool( self::id(), 'xmlrpc' ) && self::xmlrpc_needed() ) {
			return __( 'XML-RPC stays on: Jetpack is active and needs it to connect to WordPress.com.', 'fluffless-suite' );
		}
		return '';
	}

	/** XML-RPC coupé, méthodes vidées, en-tête X-Pingback retiré. Gardé si Jetpack en a besoin. */
	private function disable_xmlrpc(): void {
		if ( self::xmlrpc_needed() ) {
			return;
		}
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );
		add_filter( 'wp_headers', array( self::class, 'drop_pingback_header' ) );
	}

	/**
	 * Retire l'en-tête X-Pingback, inutile sans XML-RPC.
	 *
	 * @param mixed $headers En-têtes HTTP.
	 * @return mixed
	 */
	public static function drop_pingback_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/** En-têtes de sécurité des pages publiques ; l'administration envoie déjà les siens. */
	private function add_security_headers(): void {
		add_filter( 'wp_headers', array( self::class, 'security_headers' ) );
		add_action( 'send_headers', array( self::class, 'remove_powered_by' ) );
	}

	/**
	 * Ajoute SECURITY_HEADERS sans écraser une valeur déjà posée par le thème ou une extension.
	 *
	 * @param mixed $headers En-têtes HTTP.
	 * @return mixed
	 */
	public static function security_headers( $headers ) {
		return is_array( $headers ) ? $headers + self::SECURITY_HEADERS : $headers;
	}

	/** Retire X-Powered-By (version de PHP) quand le serveur le permet. */
	public static function remove_powered_by(): void {
		if ( function_exists( 'header_remove' ) && ! headers_sent() ) {
			header_remove( 'X-Powered-By' );
		}
	}
}
