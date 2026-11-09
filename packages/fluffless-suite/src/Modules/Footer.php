<?php
/**
 * Module Pied de page : un interrupteur et un choix d'appareil par élément des zones Confiance et Barre légale.
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
 * Chaque élément est rendu par un petit bloc serveur (voir Blocks\PiedDePage) qui lit ces réglages
 * et ne rend rien s'il est éteint ou incomplet : la zone flex du thème se réorganise d'elle-même.
 * Aucun JS, aucune requête externe : icônes en SVG en ligne, badge réduit à un lien.
 *
 * Réglages : show_<élément> (bool) et <élément>_device (all|desktop|mobile, classes du module
 * Visibilité), puis les contenus. Module éteint : les blocs du pied de page ne rendent rien.
 * L'Empreinte (bloc autonome) n'obéit à son interrupteur que placée directement dans une zone
 * fluffless-zone-… d'un pied de page ; ailleurs, elle reste gérée par son propre bloc.
 */
final class Footer implements Module {

	/** Éléments, dans l'ordre de la page de réglages. */
	public const ELEMENTS = array( 'footprint', 'badge', 'certifications', 'networks', 'newsletter', 'privacy', 'cookies', 'languages', 'land', 'credit' );

	/** Réseaux reconnus : identifiant => nom de marque (non traduit). */
	public const NETWORKS = array(
		'linkedin'  => 'LinkedIn',
		'instagram' => 'Instagram',
		'facebook'  => 'Facebook',
		'youtube'   => 'YouTube',
		'tiktok'    => 'TikTok',
		'bluesky'   => 'Bluesky',
		'mastodon'  => 'Mastodon',
		'threads'   => 'Threads',
		'pinterest' => 'Pinterest',
		'vimeo'     => 'Vimeo',
		'github'    => 'GitHub',
		'behance'   => 'Behance',
		'dribbble'  => 'Dribbble',
		'email'     => 'Email',
		'rss'       => 'RSS',
	);

	public const MAX_NETWORKS       = 15;
	public const MAX_CERTIFICATIONS = 12;

	/** Attribut posé sur un bloc Empreinte placé dans une zone de pied de page. */
	public const MARKER = 'flufflessFooterItem';

	/** Domaine (et ses sous-domaines) => réseau. Mastodon et RSS se reconnaissent au chemin. */
	private const NETWORK_HOSTS = array(
		'linkedin.com'  => 'linkedin',
		'instagram.com' => 'instagram',
		'facebook.com'  => 'facebook',
		'fb.com'        => 'facebook',
		'youtube.com'   => 'youtube',
		'youtu.be'      => 'youtube',
		'tiktok.com'    => 'tiktok',
		'bsky.app'      => 'bluesky',
		'threads.net'   => 'threads',
		'threads.com'   => 'threads',
		'pinterest.com' => 'pinterest',
		'pinterest.ca'  => 'pinterest',
		'pin.it'        => 'pinterest',
		'vimeo.com'     => 'vimeo',
		'github.com'    => 'github',
		'behance.net'   => 'behance',
		'dribbble.com'  => 'dribbble',
	);

	/** {@inheritDoc} */
	public static function id(): string {
		return 'footer';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Footer', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Choose which footer elements appear, and on which devices. Each one is a small block rendered on the server: turned off or left empty, it outputs nothing and the footer closes the gap.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::ELEMENTS as $element ) {
			$defaults[ 'show_' . $element ]   = true;
			$defaults[ $element . '_device' ] = 'all';
		}
		return $defaults + array(
			'certification_ids' => array(),
			'network_urls'      => array(),
			'newsletter_url'    => '',
			'privacy_url'       => '',
			'land_text'         => '',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		$clean     = Sanitize::section( $input, self::fields(), self::defaults() );
		$urls      = $clean['network_urls'];
		$ids       = $clean['certification_ids'];
		$networks  = array();
		$media_ids = array();

		foreach ( is_array( $urls ) ? $urls : array() as $url ) {
			$normal = self::normalize_network_url( $url );
			if ( null !== $normal && count( $networks ) < self::MAX_NETWORKS ) {
				$networks[] = $normal;
			}
		}
		foreach ( is_array( $ids ) ? $ids : array() as $line ) {
			$parts = preg_split( '/[\s,]+/', $line );
			foreach ( is_array( $parts ) ? $parts : array() as $id ) {
				if ( ctype_digit( $id ) && (int) $id > 0 && wp_attachment_is_image( (int) $id ) && count( $media_ids ) < self::MAX_CERTIFICATIONS ) {
					$media_ids[] = (string) (int) $id;
				}
			}
		}
		$clean['network_urls']      = array_values( array_unique( $networks ) );
		$clean['certification_ids'] = array_values( array_unique( $media_ids ) );
		return $clean;
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		$labels  = self::element_labels();
		$fields  = array();
		$content = array(
			'certifications' => array(
				'certification_ids' => array(
					'type'  => 'lines',
					'label' => __( 'Certification images', 'fluffless-suite' ),
					'help'  => __( 'Media library IDs, one per line, in display order (open the image in Media: the number after item= in the address). Only images are kept; their alternative text is used.', 'fluffless-suite' ),
				),
			),
			'networks'       => array(
				'network_urls' => array(
					'type'  => 'lines',
					'label' => __( 'Social network addresses', 'fluffless-suite' ),
					'help'  => __( 'One address per line, in display order. Recognized: LinkedIn, Instagram, Facebook, YouTube, TikTok, Bluesky, Threads, Pinterest, Vimeo, GitHub, Behance, Dribbble, a Mastodon profile (https://instance/@name), an RSS feed (ending in /feed) and mailto:name@example.ca. Other addresses are removed on save.', 'fluffless-suite' ),
				),
			),
			'newsletter'     => array(
				'newsletter_url' => array(
					'type'  => 'url',
					'label' => __( 'Newsletter sign-up page', 'fluffless-suite' ),
					'help'  => __( 'Empty: no newsletter link.', 'fluffless-suite' ),
				),
			),
			'privacy'        => array(
				'privacy_url' => array(
					'type'  => 'url',
					'label' => __( 'Privacy policy address', 'fluffless-suite' ),
					'help'  => __( 'Empty: the page chosen in Settings → Privacy. Neither one: no link.', 'fluffless-suite' ),
				),
			),
			'land'           => array(
				'land_text' => array(
					'type'  => 'textarea',
					'label' => __( 'Land acknowledgement text', 'fluffless-suite' ),
					'help'  => __( 'Name the Indigenous territory where you work, after consulting the Nation concerned. Empty: nothing is shown.', 'fluffless-suite' ),
				),
			),
		);
		foreach ( self::ELEMENTS as $element ) {
			$fields[ 'show_' . $element ]   = array(
				'type'  => 'bool',
				'label' => $labels[ $element ],
				'with'  => $element . '_device',
			);
			$fields[ $element . '_device' ] = array(
				'type'    => 'choice',
				'label'   => __( 'Show on', 'fluffless-suite' ),
				'options' => self::devices(),
			);
			$fields                        += $content[ $element ] ?? array();
		}
		return $fields;
	}

	/**
	 * Noms des éléments, pour la page de réglages.
	 *
	 * @return array<string, string>
	 */
	public static function element_labels(): array {
		return array(
			'footprint'      => __( 'Page footprint', 'fluffless-suite' ),
			'badge'          => __( 'Hosting badge (Green Web Foundation listing, with the check date)', 'fluffless-suite' ),
			'certifications' => __( 'Certifications', 'fluffless-suite' ),
			'networks'       => __( 'Social networks', 'fluffless-suite' ),
			'newsletter'     => __( 'Newsletter link', 'fluffless-suite' ),
			'privacy'        => __( 'Privacy policy', 'fluffless-suite' ),
			'cookies'        => __( 'Manage my cookies (with the Fluffless Consent plugin)', 'fluffless-suite' ),
			'languages'      => __( 'Language switcher (with Polylang)', 'fluffless-suite' ),
			'land'           => __( 'Land acknowledgement', 'fluffless-suite' ),
			'credit'         => __( '“Made with Fluffless” credit', 'fluffless-suite' ),
		);
	}

	/**
	 * Choix d'appareil ; desktop et mobile sont les clés de Visibility::CLASSES.
	 *
	 * @return array<string, string>
	 */
	public static function devices(): array {
		return array(
			'all'     => __( 'Everywhere', 'fluffless-suite' ),
			'desktop' => __( 'Desktop only', 'fluffless-suite' ),
			'mobile'  => __( 'Mobile only', 'fluffless-suite' ),
		);
	}

	/** Explique pourquoi le badge d'hébergement est visible ou non. */
	public static function status(): string {
		if ( fluffless_host_is_green() ) {
			return __( 'Hosting badge: shown. The Green Web Foundation recognizes your host (last Fluff check).', 'fluffless-suite' );
		}
		if ( 'no' === Audit::host_status() ) {
			return __( 'Hosting badge: hidden. The Green Web Foundation does not recognize your host; its directory lists verified hosts by region.', 'fluffless-suite' );
		}
		return __( 'Hosting badge: hidden until the Fluff check confirms your host with the Green Web Foundation (Fluffless → Fluff check).', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_filter( 'render_block_data', array( self::class, 'mark_footprint' ), 10, 3 );
		add_filter( 'render_block_fluffless/empreinte', array( self::class, 'filter_footprint' ), 10, 2 );
	}

	/**
	 * L'élément doit-il s'afficher ? Faux si le module est éteint.
	 *
	 * @param string $element Un des ELEMENTS.
	 */
	public static function shows( string $element ): bool {
		return Options::is_enabled( self::id() ) && Options::get_bool( self::id(), 'show_' . $element );
	}

	/**
	 * Un module requis est-il utile avec les réglages actuels ? Affichage selon l'appareil ne sert
	 * que si un élément vise un appareil ; avec « partout » pour chacun, son absence ne réduit rien (P5-02).
	 *
	 * @param string $required Identifiant du module requis.
	 */
	public static function needs( string $required ): bool {
		if ( Visibility::id() !== $required ) {
			return true;
		}
		foreach ( self::ELEMENTS as $element ) {
			if ( 'all' !== Options::get_string( self::id(), $element . '_device' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classe d'appareil de l'élément ('' pour partout). Ajoute les deux règles CSS de Visibilité au besoin.
	 *
	 * @param string $element Un des ELEMENTS.
	 */
	public static function device_class( string $element ): string {
		$class = Visibility::CLASSES[ Options::get_string( self::id(), $element . '_device' ) ] ?? '';
		if ( '' !== $class ) {
			Visibility::enqueue_style();
		}
		return $class;
	}

	/**
	 * Réseau d'une adresse, ou null si elle n'est pas reconnue.
	 *
	 * @param string $url Adresse https ou mailto:.
	 */
	public static function network_of( string $url ): ?string {
		if ( 0 === stripos( $url, 'mailto:' ) ) {
			return is_email( substr( $url, 7 ) ) ? 'email' : null;
		}
		$clean = Sanitize::https_url( $url );
		if ( null === $clean || '' === $clean ) {
			return null;
		}
		$host = strtolower( (string) wp_parse_url( $clean, PHP_URL_HOST ) );
		foreach ( self::NETWORK_HOSTS as $domain => $network ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return $network;
			}
		}
		$path = (string) wp_parse_url( $clean, PHP_URL_PATH );
		if ( 1 === preg_match( '#^/@[A-Za-z0-9_]{1,30}/?$#D', $path ) ) {
			return 'mastodon';
		}
		if ( 1 === preg_match( '#(/(feed|rss)/?|\.(xml|rss|atom))$#Di', $path ) ) {
			return 'rss';
		}
		return null;
	}

	/**
	 * Adresse normalisée d'un réseau reconnu, ou null.
	 *
	 * @param string $url Adresse saisie.
	 */
	public static function normalize_network_url( string $url ): ?string {
		$network = self::network_of( $url );
		if ( null === $network ) {
			return null;
		}
		return 'email' === $network ? 'mailto:' . sanitize_email( substr( $url, 7 ) ) : Sanitize::https_url( $url );
	}

	/**
	 * Nom accessible d'un lien de réseau, par exemple « Nom du site on LinkedIn ».
	 *
	 * @param string $network Identifiant de réseau.
	 * @param string $site    Nom du site.
	 */
	public static function link_label( string $network, string $site ): string {
		return match ( $network ) {
			/* translators: %s: site name */
			'email' => sprintf( __( 'Email %s', 'fluffless-suite' ), $site ),
			/* translators: %s: site name */
			'rss'   => sprintf( __( '%s RSS feed', 'fluffless-suite' ), $site ),
			/* translators: 1: social network name, 2: site name */
			default => sprintf( __( '%2$s on %1$s', 'fluffless-suite' ), self::NETWORKS[ $network ] ?? $network, $site ),
		};
	}

	/**
	 * Marque le bloc Empreinte placé directement dans une zone de pied de page.
	 *
	 * @param mixed $parsed_block Bloc analysé.
	 * @param mixed $source_block Bloc d'origine (inutilisé).
	 * @param mixed $parent_block Bloc parent, s'il existe.
	 * @return mixed
	 */
	public static function mark_footprint( $parsed_block, $source_block = null, $parent_block = null ) {
		unset( $source_block );
		if ( ! is_array( $parsed_block ) || 'fluffless/empreinte' !== ( $parsed_block['blockName'] ?? null ) || ! $parent_block instanceof \WP_Block ) {
			return $parsed_block;
		}
		$class = $parent_block->parsed_block['attrs']['className'] ?? '';
		if ( is_string( $class ) && str_contains( $class, 'fluffless-zone-' ) ) {
			$parsed_block['attrs'][ self::MARKER ] = 'footprint';
		}
		return $parsed_block;
	}

	/**
	 * Empreinte marquée : rien si l'élément est éteint, sinon sa classe d'appareil.
	 *
	 * @param mixed        $content Balisage rendu.
	 * @param array<mixed> $block   Bloc analysé.
	 */
	public static function filter_footprint( $content, $block ): string {
		$content = (string) $content;
		if ( 'footprint' !== ( $block['attrs'][ self::MARKER ] ?? null ) || '' === $content ) {
			return $content;
		}
		if ( ! self::shows( 'footprint' ) ) {
			return '';
		}
		$class = self::device_class( 'footprint' );
		$tags  = new \WP_HTML_Tag_Processor( $content );
		if ( '' === $class || ! $tags->next_tag() ) {
			return $content;
		}
		$tags->add_class( $class );
		return $tags->get_updated_html();
	}
}
