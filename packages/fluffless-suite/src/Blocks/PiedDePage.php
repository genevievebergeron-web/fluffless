<?php
/**
 * Rendu serveur des petits blocs du pied de page : badge d'hébergement, réseaux,
 * certifications, lien d'infolettre et barre légale.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Blocks;

use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Footer;

defined( 'ABSPATH' ) || exit;

/**
 * Chaque méthode rend '' si l'élément est éteint ou incomplet : aucun balisage vide,
 * donc aucun espace fantôme dans la zone flex. Aucun JS ; aucune ressource externe
 * (les adresses des réseaux et de la Green Web Foundation ne sont que des liens).
 */
final class PiedDePage {

	/** Page publique de vérification de la Green Web Foundation (paramètre url : le domaine). */
	public const GWF_CHECK = 'https://www.thegreenwebfoundation.org/green-web-check/';

	public const CREDIT_URL = 'https://fluffless.dev';

	/** Borne sur les langues d'un filtre tiers. */
	private const MAX_LANGUAGES = 20;

	/**
	 * Tracés Simple Icons, chargés une fois.
	 *
	 * @var array<string, array{title: string, path: string}>|null
	 */
	private static ?array $icons = null;

	/**
	 * Badge d'hébergement, seulement sur preuve récente et datée (loi C-59) : il dit ce que la
	 * Green Web Foundation affirme (hébergeur répertorié) et quand le Bilan l'a vérifié, jamais
	 * « vérifié » seul, car la fondation accepte aussi les compensations.
	 */
	public static function badge(): string {
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$checked = Audit::host_checked();
		if ( ! Footer::shows( 'badge' ) || ! fluffless_host_is_green() || '' === $host || null === $checked ) {
			return '';
		}
		$date = wp_date( (string) get_option( 'date_format' ), $checked );
		return sprintf(
			'<p %1$s>%2$s<a href="%3$s">%4$s</a></p>',
			self::wrapper( 'badge-hebergement', Footer::device_class( 'badge' ) ),
			Icone::svg( 'leaf' ),
			esc_url( add_query_arg( 'url', rawurlencode( $host ), self::GWF_CHECK ) ),
			esc_html(
				sprintf(
					/* translators: %s: date of the last sobriety check */
					__( 'Host listed by the Green Web Foundation (checked on %s)', 'fluffless-suite' ),
					false === $date ? '' : $date
				)
			)
		);
	}

	/** Icônes des réseaux saisis, dans l'ordre de saisie. */
	public static function networks(): string {
		if ( ! Footer::shows( 'networks' ) ) {
			return '';
		}
		$site  = self::site_name();
		$items = array();
		foreach ( Options::get_list( Footer::id(), 'network_urls' ) as $url ) {
			$network = Footer::network_of( $url );
			if ( null === $network ) {
				continue;
			}
			$items[] = sprintf(
				'<li><a href="%1$s" aria-label="%2$s"%3$s>%4$s</a></li>',
				esc_url( $url, array( 'https', 'mailto' ) ),
				esc_attr( Footer::link_label( $network, $site ) ),
				'email' === $network ? '' : ' rel="me"',
				self::glyph( $network )
			);
		}
		if ( array() === $items ) {
			return '';
		}
		return sprintf( '<ul %1$s>%2$s</ul>', self::wrapper( 'reseaux', Footer::device_class( 'networks' ) ), implode( '', $items ) );
	}

	/** Images de certifications de la médiathèque (tailles et srcset de WordPress, chargement différé). */
	public static function certifications(): string {
		if ( ! Footer::shows( 'certifications' ) ) {
			return '';
		}
		$items = array();
		foreach ( Options::get_list( Footer::id(), 'certification_ids' ) as $raw_id ) {
			$id = (int) $raw_id;
			if ( ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			// Un logo de certification porte une information : sans texte de remplacement, son titre en tient lieu.
			$alt   = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			$alt   = '' === $alt ? (string) get_post_field( 'post_title', $id, 'raw' ) : $alt;
			$image = wp_get_attachment_image(
				$id,
				'medium',
				false,
				array(
					'alt'      => $alt,
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			);
			if ( '' !== $image ) {
				$items[] = '<li>' . $image . '</li>';
			}
		}
		if ( array() === $items ) {
			return '';
		}
		return sprintf( '<ul %1$s>%2$s</ul>', self::wrapper( 'certifications', Footer::device_class( 'certifications' ) ), implode( '', $items ) );
	}

	/** Lien vers la page d'inscription à l'infolettre. */
	public static function newsletter(): string {
		$url = Options::get_string( Footer::id(), 'newsletter_url' );
		if ( ! Footer::shows( 'newsletter' ) || '' === $url ) {
			return '';
		}
		return sprintf(
			'<p %1$s><a href="%2$s">%3$s</a></p>',
			self::wrapper( 'lien-infolettre', Footer::device_class( 'newsletter' ) ),
			esc_url( $url ),
			esc_html__( 'Subscribe to the newsletter', 'fluffless-suite' )
		);
	}

	/**
	 * Barre légale (©, politique, témoins, langues), reconnaissance territoriale et crédit.
	 * Les éléments de la barre sont séparés par l'espacement flex ; le seul « · » sépare
	 * les langues, et n'est écrit qu'entre deux langues rendues.
	 */
	public static function legal(): string {
		if ( ! Options::is_enabled( Footer::id() ) ) {
			return '';
		}
		$bar = array(
			'<span>' . esc_html(
				sprintf(
					/* translators: 1: current year, 2: site name */
					__( '© %1$s %2$s', 'fluffless-suite' ),
					(string) wp_date( 'Y' ),
					self::site_name()
				)
			) . '</span>',
		);

		$privacy = Options::get_string( Footer::id(), 'privacy_url' );
		$privacy = '' === $privacy ? get_privacy_policy_url() : $privacy;
		if ( Footer::shows( 'privacy' ) && '' !== $privacy ) {
			$bar[] = self::item( 'privacy', 'span', sprintf( '<a href="%1$s">%2$s</a>', esc_url( $privacy ), esc_html__( 'Privacy policy', 'fluffless-suite' ) ) );
		}
		$cookies = Footer::shows( 'cookies' ) ? self::cookie_button() : '';
		if ( '' !== $cookies ) {
			$bar[] = self::item( 'cookies', 'span', $cookies );
		}
		$languages = Footer::shows( 'languages' ) ? self::language_links() : '';
		if ( '' !== $languages ) {
			$bar[] = self::item( 'languages', 'span', $languages );
		}

		$html = '<p class="wp-block-fluffless-legal__bar">' . implode( '', $bar ) . '</p>';
		$land = Options::get_string( Footer::id(), 'land_text' );
		if ( Footer::shows( 'land' ) && '' !== $land ) {
			$html .= self::item( 'land', 'p', esc_html( $land ), 'wp-block-fluffless-legal__land' );
		}
		if ( Footer::shows( 'credit' ) ) {
			$html .= self::item( 'credit', 'p', sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::CREDIT_URL ), esc_html__( 'Made with Fluffless', 'fluffless-suite' ) ) );
		}
		return sprintf( '<div %1$s>%2$s</div>', self::wrapper( 'legal', '' ), $html );
	}

	/**
	 * Élément de la barre légale, avec sa classe d'appareil.
	 *
	 * @param string $element Un des Footer::ELEMENTS.
	 * @param string $tag     Balise (span ou p).
	 * @param string $inner   Balisage déjà échappé.
	 * @param string $base    Classe de base facultative.
	 */
	private static function item( string $element, string $tag, string $inner, string $base = '' ): string {
		$class = trim( $base . ' ' . Footer::device_class( $element ) );
		return '<' . $tag . ( '' === $class ? '' : ' class="' . esc_attr( $class ) . '"' ) . '>' . $inner . '</' . $tag . '>';
	}

	/** Bouton « Gérer mes témoins » de l'extension Fluffless Consent, '' si elle est absente ou sans service déclaré. */
	private static function cookie_button(): string {
		return function_exists( 'fluffless_consent_link' ) ? fluffless_consent_link() : '';
	}

	/** Liens de langue ; rien s'il y a moins de deux langues. */
	private static function language_links(): string {
		/**
		 * Langues du sélecteur de la barre légale. Par défaut : celles de Polylang s'il est actif.
		 *
		 * @param array<mixed> $languages Liste de array{slug: string, name: string, url: string, current: bool, hreflang?: string}.
		 */
		$languages = (array) apply_filters( 'fluffless_footer_languages', self::polylang_languages() );
		$links     = array();
		// (array) : un filtre tiers peut rendre n'importe quoi ; chaque entrée est validée ensuite.
		foreach ( array_slice( $languages, 0, self::MAX_LANGUAGES ) as $language ) {
			$slug = is_array( $language ) && is_string( $language['slug'] ?? null ) ? strtolower( $language['slug'] ) : '';
			$url  = is_array( $language ) && is_string( $language['url'] ?? null ) ? $language['url'] : '';
			if ( 1 !== preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/D', $slug ) || '' === $url ) {
				continue;
			}
			$hreflang = is_string( $language['hreflang'] ?? null ) && 1 === preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/Di', $language['hreflang'] ) ? $language['hreflang'] : $slug;
			$code     = esc_html( strtoupper( substr( $slug, 0, 2 ) ) );
			$links[]  = true === ( $language['current'] ?? false )
				? sprintf( '<span lang="%1$s" aria-current="true">%2$s</span>', esc_attr( $hreflang ), $code )
				: sprintf( '<a href="%1$s" lang="%2$s" hreflang="%2$s">%3$s</a>', esc_url( $url ), esc_attr( $hreflang ), $code );
		}
		return count( $links ) < 2 ? '' : implode( '<span aria-hidden="true"> · </span>', $links );
	}

	/**
	 * Langues de Polylang, ou liste vide sans Polylang.
	 *
	 * @return list<array{slug: string, name: string, url: string, current: bool, hreflang: string}>
	 */
	private static function polylang_languages(): array {
		if ( ! function_exists( 'pll_the_languages' ) ) {
			return array();
		}
		// Sans traduction publiée, pas de lien vers l'accueil de l'autre langue.
		$raw       = pll_the_languages(
			array(
				'raw'                    => 1,
				'hide_if_no_translation' => 1,
			)
		);
		$languages = array();
		foreach ( is_array( $raw ) ? $raw : array() as $language ) {
			if ( is_array( $language ) && is_string( $language['slug'] ?? null ) && is_string( $language['url'] ?? null ) ) {
				$languages[] = array(
					'slug'     => $language['slug'],
					'name'     => is_string( $language['name'] ?? null ) ? $language['name'] : $language['slug'],
					'url'      => $language['url'],
					'current'  => ! empty( $language['current_lang'] ),
					// Même code que le <head> (fr-CA, en-CA) plutôt que le seul slug.
					'hreflang' => is_string( $language['locale'] ?? null ) ? str_replace( '_', '-', $language['locale'] ) : $language['slug'],
				);
			}
		}
		return $languages;
	}

	/**
	 * Pictogramme d'un réseau : Simple Icons, « mail » de Lucide pour le courriel, texte à défaut.
	 *
	 * @param string $network Identifiant de réseau.
	 */
	public static function glyph( string $network ): string {
		if ( 'email' === $network ) {
			return Icone::svg( 'mail' );
		}
		$icon = self::icons()[ $network ] ?? null;
		if ( null === $icon ) {
			return '<span class="wp-block-fluffless-reseaux__text">' . esc_html( Footer::NETWORKS[ $network ] ?? $network ) . '</span>';
		}
		// Le tracé vient d'un fichier généré sous liste blanche de caractères (tools/build-social-icons.mjs).
		return '<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="' . esc_attr( $icon['path'] ) . '"/></svg>';
	}

	/**
	 * Tracés générés.
	 *
	 * @return array<string, array{title: string, path: string}>
	 */
	private static function icons(): array {
		if ( null === self::$icons ) {
			$loaded      = require dirname( __DIR__, 2 ) . '/blocks/reseaux/icons.php';
			self::$icons = is_array( $loaded ) ? $loaded : array();
		}
		return self::$icons;
	}

	/** Nom du site, sans les entités de l'option. */
	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Attributs de l'élément racine : ceux des supports du bloc pendant un rendu, sinon la classe du bloc.
	 *
	 * @param string $block Nom court du bloc.
	 * @param string $extra Classe supplémentaire ('' si aucune).
	 */
	private static function wrapper( string $block, string $extra ): string {
		if ( null !== \WP_Block_Supports::$block_to_render ) {
			return get_block_wrapper_attributes( '' === $extra ? array() : array( 'class' => $extra ) );
		}
		return 'class="' . esc_attr( trim( 'wp-block-fluffless-' . $block . ' ' . $extra ) ) . '"';
	}
}
