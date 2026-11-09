<?php
/**
 * Module Bilan de sobriété : vérifie l'hébergement réel du site, à la demande, dans l'administration.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\AuditFix;
use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Http;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\DeclaresHosts;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Aucun appel réseau sans clic : le premier bilan et chaque relance passent par un formulaire
 * (nonce + capacité). Résultat en cache 24 h. Le visiteur ne paie rien : tout est dans l'administration.
 *
 * Forme d'un point : array{status: 'good'|'warn'|'bad'|'unknown', title: string, detail: string, fix: string}
 *
 * @phpstan-type Item array{status: 'good'|'warn'|'bad'|'unknown', title: string, detail: string, fix: string}
 * @phpstan-type Report array{checked: int, url: string, local: bool, items: array<string, Item>, third_party_hosts?: list<string>}
 */
final class Audit implements Module, DeclaresHosts {

	public const PAGE   = 'fluffless-audit';
	public const ACTION = 'fluffless_audit_run';

	/** Budgets de docs/PLAN.md (« Budgets de sobriété ») : accueil ≤ 300 ko transférés, ≤ 20 requêtes. */
	public const HOME_BUDGET_BYTES = 300 * 1000;
	public const REQUEST_BUDGET    = 20;

	/**
	 * Seuils proposés (à faire approuver) pour les pages de boutique, d'après le banc du 18 sept.
	 * (docs/boutique-sobre-2026-09.md, Boutique sobre en marche) : boutique 546 ko, fiche 405 ko,
	 * panier 844 ko, caisse 823 ko. Au-delà de 1,5 fois : à corriger.
	 */
	public const SHOP_BUDGET_BYTES     = 600 * KB_IN_BYTES;
	public const CHECKOUT_BUDGET_BYTES = MB_IN_BYTES;

	/** Styles de blocs du cœur écrits dans la page au-delà desquels le point passe à « à revoir » (le bloc Navigation seul en pèse près de 21 ko). */
	public const CORE_CSS_WARN_BYTES = 30 * 1000;

	/** Seuil proposé (à faire approuver) : un fichier statique versionné se garde au moins 30 jours. */
	public const CACHE_GOOD_SECONDS = 30 * DAY_IN_SECONDS;

	/** Le statut de l'hébergeur sert au badge du pied de page : au-delà de 30 jours, il faut revérifier. */
	public const HOST_STATUS_TTL = 30 * DAY_IN_SECONDS;

	public const GWF_API       = 'https://api.thegreenwebfoundation.org/api/v3/greencheck/';
	public const GWF_DIRECTORY = 'https://www.thegreenwebfoundation.org/directory/';

	/** Bornes : ressources examinées sur l'accueil, délai par requête (secondes), taille de HTML lue. */
	private const MAX_RESOURCES = 40;

	/** Fichiers relevés au plus sur une page de boutique (73 requêtes au panier au banc du 18 sept.). */
	private const MAX_SHOP_RESOURCES = 120;

	/** Part de fichiers au poids inconnu au-delà de laquelle une page sous son seuil n'est pas dite bonne. */
	private const MAX_UNKNOWN_SHARE = 0.25;

	/** Secondes accordées aux pages de boutique, sous le délai courant d'un mandataire (60 s). */
	private const SHOP_TIME_BUDGET = 45;

	/** Durée de vie du point « images » gardé entre deux affichages ou appels de la route État du site. */
	private const IMAGES_CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** Fichier relu en entier, quand HEAD ne donne pas sa taille, jusqu'à cette taille. */
	private const MAX_FILE = 5000000;
	private const TIMEOUT  = 8;
	private const MAX_HTML = 2000000;

	/** Une sauvegarde déclarée se revérifie au moins une fois l'an. */
	public const BACKUP_MAX_AGE = YEAR_IN_SECONDS;

	/** Un cache de page déclaré se revérifie au moins une fois l'an, comme les sauvegardes. */
	public const PAGE_CACHE_MAX_AGE = YEAR_IN_SECONDS;

	/** Suffixes de domaines qui ne sont jamais publics (RFC 2606, RFC 6761, usages courants). */
	private const LOCAL_SUFFIXES = array( '.localhost', '.local', '.test', '.example', '.invalid', '.internal', '.lan', '.home.arpa' );

	/** {@inheritDoc} */
	public static function id(): string {
		return 'audit';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Fluff check', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Checks your real hosting on demand: renewable-powered host, HTTP/2, compression, caching, home page weight, heavy images and, with WooCommerce, the shop pages. Admin only, nothing is added to your pages.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'backups'            => false,
			'backups_checked'    => '',
			'page_cache'         => false,
			'page_cache_checked' => '',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		$section                       = Sanitize::section( $input, self::fields(), self::defaults() );
		$section['backups_checked']    = self::sanitize_date( is_string( $section['backups_checked'] ?? null ) ? $section['backups_checked'] : '' );
		$section['page_cache_checked'] = self::sanitize_date( is_string( $section['page_cache_checked'] ?? null ) ? $section['page_cache_checked'] : '' );
		return $section;
	}

	/**
	 * Date AAAA-MM-JJ réelle et pas dans le futur, sinon chaîne vide.
	 *
	 * @param string $raw Date saisie.
	 */
	public static function sanitize_date( string $raw ): string {
		$raw = trim( $raw );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $raw > wp_date( 'Y-m-d' ) ? '' : $raw;
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array(
			'backups'            => array(
				'type'  => 'bool',
				'label' => __( 'The host backs up this site', 'fluffless-suite' ),
				'help'  => __( 'A backup made by a plugin zips the site from PHP, on the same server, and slows it down. The host’s backups, kept elsewhere, are the reference. Tick this once the host has confirmed how often the site is backed up and how a copy is restored. The Fluff check reminds you while it is empty.', 'fluffless-suite' ),
			),
			'backups_checked'    => array(
				'type'  => 'text',
				'label' => __( 'Checked on', 'fluffless-suite' ),
				'help'  => __( 'Date of that confirmation, as YYYY-MM-DD. Check again at least once a year.', 'fluffless-suite' ),
			),
			'page_cache'         => array(
				'type'  => 'bool',
				'label' => __( 'The host caches pages for this site', 'fluffless-suite' ),
				'help'  => __( 'A page cache built into the theme would duplicate what most hosts already do, and stay a step behind a change on the page. Tick this once the host has confirmed it caches full pages and how a change (a sale ending, a form closing) reaches visitors. If a shop is set up, ask it to exclude the cart, checkout and account pages from that cache. The Fluff check reminds you while it is empty.', 'fluffless-suite' ),
			),
			'page_cache_checked' => array(
				'type'  => 'text',
				'label' => __( 'Checked on', 'fluffless-suite' ),
				'help'  => __( 'Date of that confirmation, as YYYY-MM-DD. Check again at least once a year.', 'fluffless-suite' ),
			),
		);
	}

	/**
	 * Sauvegarde déclarée et date de la dernière vérification (pour l'écran et l'état du site).
	 *
	 * @return array{declared: bool, checked: string}
	 */
	public static function backups(): array {
		return array(
			'declared' => Options::get_bool( self::id(), 'backups' ),
			'checked'  => self::sanitize_date( Options::get_string( self::id(), 'backups_checked' ) ),
		);
	}

	/**
	 * Point « sauvegardes » : rien n'est vérifié sur le serveur, c'est la déclaration qui est gardée.
	 * Absente : à corriger. Vieille d'un an ou sans date : à revoir. Sinon : bon, avec la date.
	 *
	 * @return Item
	 */
	public static function check_backups(): array {
		$title   = __( 'Backups by the host', 'fluffless-suite' );
		$backups = self::backups();
		$ask     = __( 'Ask your host how often the site is backed up, where the copies are kept and how a copy is restored. Then tick “The host backs up this site” on the Modules screen, with the date.', 'fluffless-suite' );
		if ( ! $backups['declared'] ) {
			return self::item( 'bad', $title, __( 'No backup declared. This suite never backs up the site itself: that job belongs to the host, away from the server visitors use.', 'fluffless-suite' ), $ask );
		}
		if ( '' === $backups['checked'] ) {
			return self::item( 'warn', $title, __( 'The host backs up this site, but the confirmation has no date. Add the date of your last check.', 'fluffless-suite' ), '' );
		}
		$checked = strtotime( $backups['checked'] . ' 00:00:00 UTC' );
		$date    = wp_date( get_option( 'date_format' ), false === $checked ? null : $checked );
		$date    = false === $date ? $backups['checked'] : $date;
		if ( false === $checked || time() - $checked > self::BACKUP_MAX_AGE ) {
			/* translators: %s: date of the last check */
			return self::item( 'warn', $title, sprintf( __( 'The host backs up this site, but the last check goes back to %s, more than a year ago. Ask again, then update the date.', 'fluffless-suite' ), $date ), '' );
		}
		/* translators: %s: date of the last check */
		return self::item( 'good', $title, sprintf( __( 'The host backs up this site, checked on %s.', 'fluffless-suite' ), $date ), '' );
	}

	/**
	 * Cache de page déclaré par l'hébergeur et date de la dernière vérification (pour l'écran et l'état du site).
	 *
	 * @return array{declared: bool, checked: string}
	 */
	public static function page_cache(): array {
		return array(
			'declared' => Options::get_bool( self::id(), 'page_cache' ),
			'checked'  => self::sanitize_date( Options::get_string( self::id(), 'page_cache_checked' ) ),
		);
	}

	/**
	 * Point « cache de page » : décision de périmètre depuis la Phase 0 (`PLAN.md`, « Décisions de
	 * périmètre ») : coupé, géré par le serveur. Même logique que les sauvegardes : rien n'est
	 * vérifié sur le serveur, c'est la déclaration qui est gardée. Absente : à corriger. Vieille
	 * d'un an ou sans date : à revoir. Sinon : bon, avec la date.
	 *
	 * @return Item
	 */
	public static function check_page_cache(): array {
		$title = __( 'Page cache by the host', 'fluffless-suite' );
		$cache = self::page_cache();
		$ask   = __( 'Ask your host whether it caches full pages and how a change on the page (a sale ending, a form closing) reaches visitors. If a shop is set up, ask it to exclude the cart, checkout and account pages. Then tick “The host caches pages for this site” on the Modules screen, with the date.', 'fluffless-suite' );
		if ( ! $cache['declared'] ) {
			return self::item( 'bad', $title, __( 'No page cache declared. This suite never caches pages itself: a cache built into the theme would duplicate what most hosts already do, and stay a step behind a change on the page.', 'fluffless-suite' ), $ask );
		}
		if ( '' === $cache['checked'] ) {
			return self::item( 'warn', $title, __( 'The host caches pages for this site, but the confirmation has no date. Add the date of your last check.', 'fluffless-suite' ), '' );
		}
		$checked = strtotime( $cache['checked'] . ' 00:00:00 UTC' );
		$date    = wp_date( get_option( 'date_format' ), false === $checked ? null : $checked );
		$date    = false === $date ? $cache['checked'] : $date;
		if ( false === $checked || time() - $checked > self::PAGE_CACHE_MAX_AGE ) {
			/* translators: %s: date of the last check */
			return self::item( 'warn', $title, sprintf( __( 'The host caches pages for this site, but the last check goes back to %s, more than a year ago. Ask again, then update the date.', 'fluffless-suite' ), $date ), '' );
		}
		/* translators: %s: date of the last check */
		return self::item( 'good', $title, sprintf( __( 'The host caches pages for this site, checked on %s.', 'fluffless-suite' ), $date ), '' );
	}

	/**
	 * Point « vidéos » : une vidéo hébergée ici pèse vite plus que le reste du site réuni, et
	 * chaque lecture la télécharge. Au-delà de 100 Mo : à corriger. Au-delà du seuil du module
	 * Images ou de 1080p : à revoir. Rien de tout ça n'est encodé par la Suite.
	 *
	 * @return Item
	 */
	public static function check_videos(): array {
		$title  = __( 'Videos in the media library', 'fluffless-suite' );
		$videos = Images::video_library();
		if ( array() === $videos ) {
			return self::item( 'good', $title, __( 'No video file is hosted here.', 'fluffless-suite' ), '' );
		}
		$warn  = Images::video_warn_bytes();
		$heavy = Images::VIDEO_HEAVY_MB * MB_IN_BYTES;
		$bad   = array();
		$flag  = array();
		foreach ( $videos as $video ) {
			$label = $video['title'] . ' (' . (string) size_format( $video['bytes'], 1 );
			if ( $video['width'] > 0 && $video['height'] > 0 ) {
				/* translators: 1: width in pixels; 2: height in pixels */
				$label .= ', ' . sprintf( __( '%1$d × %2$d px', 'fluffless-suite' ), $video['width'], $video['height'] );
			}
			$label .= ')';
			if ( $video['bytes'] > $heavy ) {
				$bad[] = $label;
			} elseif ( $video['bytes'] > $warn || max( $video['width'], $video['height'] ) > Images::VIDEO_MAX_EDGE ) {
				$flag[] = $label;
			}
		}
		$fix        = Images::video_recipe() . ' ' . __( 'Or host it on a video platform and paste its link in the Video block.', 'fluffless-suite' );
		$warn_size  = (string) size_format( $warn );
		$heavy_size = (string) size_format( $heavy );
		if ( array() !== $bad ) {
			/* translators: 1: number of videos; 2: weight, e.g. 100 MB; 3: list of videos with their weight */
			return self::item( 'bad', $title, sprintf( _n( '%1$d video weighs more than %2$s: %3$s.', '%1$d videos weigh more than %2$s: %3$s.', count( $bad ), 'fluffless-suite' ), count( $bad ), $heavy_size, implode( ', ', array_slice( $bad, 0, 5 ) ) ), $fix );
		}
		if ( array() !== $flag ) {
			/* translators: 1: number of videos; 2: weight, e.g. 20 MB; 3: list of videos with their weight */
			return self::item( 'warn', $title, sprintf( _n( '%1$d video is above %2$s or 1080p: %3$s.', '%1$d videos are above %2$s or 1080p: %3$s.', count( $flag ), 'fluffless-suite' ), count( $flag ), $warn_size, implode( ', ', array_slice( $flag, 0, 5 ) ) ), $fix );
		}
		/* translators: 1: number of videos; 2: weight, e.g. 20 MB */
		return self::item( 'good', $title, sprintf( _n( '%1$d video is hosted here, under %2$s and 1080p.', '%1$d videos are hosted here, all under %2$s and 1080p.', count( $videos ), 'fluffless-suite' ), count( $videos ), $warn_size ), '' );
	}

	/**
	 * Point « images » : le fichier servi en taille complète de chaque image de la médiathèque,
	 * comparé aux seuils du module Images. Au-delà du plafond : à corriger. Au-delà du seuil
	 * d'alerte ou du bord de WordPress : à revoir. Les Images::IMAGE_LIMIT plus récentes au plus.
	 * Résultat gardé IMAGES_CACHE_TTL, vidé à chaque ajout, retrait ou retouche d'image, pour que
	 * la route État du site ne relise pas toute la médiathèque à chaque appel.
	 *
	 * @return Item
	 */
	public static function check_images(): array {
		$warn   = Images::image_warn_bytes();
		$max    = Images::image_max_bytes();
		$edge   = Images::max_edge();
		$key    = md5( implode( '|', array( $warn, $max, $edge, get_user_locale() ) ) );
		$cached = get_transient( Lifecycle::TRANSIENT_AUDIT_IMAGES );
		if ( is_array( $cached ) && hash_equals( $key, (string) ( $cached['key'] ?? '' ) ) && is_array( $cached['item'] ?? null ) ) {
			/**
			 * Point écrit plus bas par ce même code.
			 *
			 * @var Item $item
			 */
			$item = $cached['item'];
			return $item;
		}
		$item = self::images_item( $warn, $max, $edge );
		set_transient(
			Lifecycle::TRANSIENT_AUDIT_IMAGES,
			array(
				'key'  => $key,
				'item' => $item,
			),
			self::IMAGES_CACHE_TTL
		);
		return $item;
	}

	/**
	 * Vide le résultat gardé quand les métadonnées d'une image changent (filtre : valeur rendue telle quelle).
	 *
	 * @param mixed $data Métadonnées.
	 * @return mixed
	 */
	public static function forget_images_filter( $data ) {
		self::forget_images();
		return $data;
	}

	/** Vide le résultat gardé du point « images ». */
	public static function forget_images(): void {
		delete_transient( Lifecycle::TRANSIENT_AUDIT_IMAGES );
	}

	/**
	 * Point « images » calculé.
	 *
	 * @param int $warn Seuil d'alerte, en octets.
	 * @param int $max  Plafond, en octets.
	 * @param int $edge Plus grand côté accepté, en pixels.
	 * @return Item
	 */
	private static function images_item( int $warn, int $max, int $edge ): array {
		$title  = __( 'Images in the media library', 'fluffless-suite' );
		$images = Images::image_library();
		if ( array() === $images ) {
			return self::item( 'good', $title, __( 'No image is hosted here.', 'fluffless-suite' ), '' );
		}
		$capped = count( $images ) > Images::IMAGE_LIMIT;
		$images = array_slice( $images, 0, Images::IMAGE_LIMIT );
		$bad    = array();
		$flag   = array();
		foreach ( $images as $image ) {
			if ( $image['bytes'] > $max ) {
				$bad[] = $image;
			} elseif ( $image['bytes'] > $warn || max( $image['width'], $image['height'] ) > $edge ) {
				$flag[] = $image;
			}
		}
		$heaviest = static function ( array $images ): string {
			usort( $images, static fn( array $a, array $b ): int => $b['bytes'] <=> $a['bytes'] );
			$labels = array();
			foreach ( array_slice( $images, 0, 5 ) as $image ) {
				$label = $image['title'] . ' (' . (string) size_format( $image['bytes'], 1 );
				if ( $image['width'] > 0 && $image['height'] > 0 ) {
					/* translators: 1: width in pixels; 2: height in pixels */
					$label .= ', ' . sprintf( __( '%1$d × %2$d px', 'fluffless-suite' ), $image['width'], $image['height'] );
				}
				$labels[] = $label . ')';
			}
			return implode( ', ', $labels );
		};
		$scope    = $capped
			/* translators: %s: number of images, e.g. 20,000 */
			? ' ' . sprintf( __( 'Only the %s most recent images were read.', 'fluffless-suite' ), number_format_i18n( Images::IMAGE_LIMIT ) )
			: '';
		$unknown = count( array_filter( $images, static fn( array $image ): bool => $image['bytes'] <= 0 ) );
		if ( $unknown > 0 ) {
			$scope .= ' ' . sprintf(
				/* translators: %s: number of images */
				_n( 'The weight of %s image is unknown: its file is not on this server.', 'The weight of %s images is unknown: their files are not on this server.', $unknown, 'fluffless-suite' ),
				number_format_i18n( $unknown )
			);
		}
		$size = (string) size_format( $warn );
		$fix  = sprintf(
			/* translators: %s: weight, e.g. 400 KB */
			__( 'Export a lighter copy on your computer, under %s: 2000 px wide at most, WebP at quality 75 (lower it to 60 if it is still too heavy). Upload it, then use Replace in each block that shows the image; for a product photo, open the product and change its image. Then, in Media > Library, open the old image and choose Delete permanently. An animated GIF weighs far less as a short video. New uploads go through the Images module.', 'fluffless-suite' ),
			$size
		);
		if ( array() !== $bad ) {
			/* translators: 1: number of images; 2: weight, e.g. 2 MB; 3: list of images with their weight */
			return self::item( 'bad', $title, sprintf( _n( '%1$d image weighs more than %2$s: %3$s.', '%1$d images weigh more than %2$s: %3$s.', count( $bad ), 'fluffless-suite' ), count( $bad ), (string) size_format( $max ), $heaviest( $bad ) ) . $scope, $fix );
		}
		if ( array() !== $flag ) {
			/* translators: 1: number of images; 2: weight, e.g. 400 KB; 3: width in pixels, e.g. 2560; 4: list of images with their weight */
			return self::item( 'warn', $title, sprintf( _n( '%1$d image is above %2$s or %3$d px: %4$s.', '%1$d images are above %2$s or %3$d px: %4$s.', count( $flag ), 'fluffless-suite' ), count( $flag ), $size, $edge, $heaviest( $flag ) ) . $scope, $fix );
		}
		$known = count( $images ) - $unknown;
		/* translators: 1: number of images; 2: weight, e.g. 400 KB */
		$detail = 0 === $known ? trim( $scope ) : sprintf( _n( '%1$d image is hosted here, under %2$s.', '%1$d images are hosted here, all under %2$s.', $known, 'fluffless-suite' ), $known, $size ) . $scope;
		// Médiathèque lue en partie, ou trop de poids inconnus : rien ne permet de dire « bon ».
		return self::item( $capped || $unknown > count( $images ) * self::MAX_UNKNOWN_SHARE ? 'unknown' : 'good', $title, $detail, '' );
	}

	/** {@inheritDoc} */
	public function register(): void {
		// Téléversements et retouches passent aussi par la REST de l'éditeur : avant le test is_admin().
		foreach ( array( 'add_attachment', 'delete_attachment', 'edit_attachment' ) as $hook ) {
			add_action( $hook, array( self::class, 'forget_images' ) );
		}
		add_filter( 'wp_update_attachment_metadata', array( self::class, 'forget_images_filter' ) );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_run' ) );
		AuditFix::register();
	}

	/**
	 * Domaines tiers chargés par l'accueil (dernier bilan) et consentement. Sans Fluffless Consent,
	 * rien ne retient un service qui dépose des témoins ou suit les visites avant un oui (Loi 25).
	 *
	 * @param array<int, string> $hosts Domaines tiers de l'accueil.
	 * @return Item
	 */
	public static function check_consent( array $hosts ): array {
		$title = __( 'Consent before third-party services', 'fluffless-suite' );
		if ( array() === $hosts ) {
			return self::item( 'good', $title, __( 'The home page loads no file from another domain.', 'fluffless-suite' ), '' );
		}
		/* translators: 1: number of domains; 2: comma-separated list of domains */
		$detail   = sprintf( _n( 'The home page loads files from %1$d other domain: %2$s.', 'The home page loads files from %1$d other domains: %2$s.', count( $hosts ), 'fluffless-suite' ), count( $hosts ), implode( ', ', array_slice( $hosts, 0, 5 ) ) );
		$declared = AuditFix::consent_services();
		if ( $declared > 0 ) {
			/* translators: %d: number of services declared in Fluffless Consent */
			return self::item( 'good', $title, $detail . ' ' . sprintf( _n( 'Fluffless Consent holds back %d declared service until the visitor says yes. Check that it covers every service on this list that needs consent.', 'Fluffless Consent holds back %d declared services until the visitor says yes. Check that they cover every service on this list that needs consent.', $declared, 'fluffless-suite' ), $declared ), '' );
		}
		if ( AuditFix::consent_active() ) {
			return self::item( 'warn', $title, $detail . ' ' . __( 'Fluffless Consent is active, but no service is declared in it: nothing is held back.', 'fluffless-suite' ), __( 'If one of these services sets cookies or follows visitors (statistics, video, map, chat), Quebec’s Law 25 asks for consent first. Declare it in the Fluffless Consent settings.', 'fluffless-suite' ) );
		}
		$fix = __( 'If one of these services sets cookies or follows visitors (statistics, video, map, chat), Quebec’s Law 25 asks for consent first. Fluffless Consent holds it back until a yes; declare the service in its settings.', 'fluffless-suite' );
		if ( ! AuditFix::consent_installed() ) {
			$fix .= ' ' . __( 'Install the Fluffless Consent plugin first.', 'fluffless-suite' );
		}
		return self::item( 'warn', $title, $detail, $fix );
	}

	/** Ajoute Fluffless → Bilan. */
	public static function add_page(): void {
		Menu::add_tab( 'settings', self::PAGE, static fn(): string => __( 'Fluff check', 'fluffless-suite' ), array( self::class, 'render' ), 20 );
	}

	/**
	 * Statut de l'hébergeur d'après la dernière vérification de moins de 30 jours.
	 *
	 * @return 'yes'|'no'|'unknown'
	 */
	public static function host_status(): string {
		$status = get_transient( Lifecycle::TRANSIENT_HOST_STATUS );
		if ( ! is_array( $status ) || ! is_bool( $status['green'] ?? null ) ) {
			return 'unknown';
		}
		return $status['green'] ? 'yes' : 'no';
	}

	/** Moment de la dernière vérification de l'hébergeur (horodatage), ou null. Daté sur le badge (loi C-59). */
	public static function host_checked(): ?int {
		$status = get_transient( Lifecycle::TRANSIENT_HOST_STATUS );
		return is_array( $status ) && is_int( $status['checked'] ?? null ) && $status['checked'] > 0 ? $status['checked'] : null;
	}

	/**
	 * Dernier bilan en cache, ou null.
	 *
	 * @return Report|null
	 */
	public static function cached(): ?array {
		$report = get_transient( Lifecycle::TRANSIENT_AUDIT_REPORT );
		return is_array( $report ) && is_int( $report['checked'] ?? null ) && is_array( $report['items'] ?? null ) ? $report : null; // @phpstan-ignore return.type (forme écrite par run() seulement)
	}

	/** Formulaire « Lancer » ou « Relancer » : vérifie, calcule, met en cache, revient à l'écran. */
	public static function handle_run(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to run the Fluff check.', 'fluffless-suite' ), 403 );
		}
		check_admin_referer( self::ACTION );
		self::run();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Lance tous les contrôles sur l'accueil du site et met le résultat en cache 24 h.
	 *
	 * @return Report
	 */
	public static function run(): array {
		$url    = home_url( '/' );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$local  = self::is_local_host( $host );
		$report = array(
			'checked' => time(),
			'url'     => $url,
			'local'   => $local,
			'items'   => array( 'host' => self::check_host( $host ) ),
		);
		// Accueil, puis jusqu'à quatre pages de boutique : bien plus de requêtes qu'un affichage.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 180 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- bilan lancé à la main depuis l'administration.
		}
		self::forget_images();
		$home_bytes       = 0;
		$memo             = array();
		$third            = array();
		$report['items'] += self::check_home( $url, $local, $home_bytes, $memo, $third );
		// Domaines tiers chargés par l'accueil, lus par l'écran Serveurs contactés : aucune requête de plus.
		$report['third_party_hosts'] = $third;

		$pages = self::shop_pages();
		if ( array() !== $pages ) {
			// L'accueil d'abord gardé : une coupure pendant la boutique ne le perd pas.
			set_transient( Lifecycle::TRANSIENT_AUDIT_REPORT, $report, DAY_IN_SECONDS );
			$report['items']['shop'] = self::check_shop( $pages, $home_bytes, $memo, microtime( true ) + self::SHOP_TIME_BUDGET );
		}
		set_transient( Lifecycle::TRANSIENT_AUDIT_REPORT, $report, DAY_IN_SECONDS );
		return $report;
	}

	/**
	 * Domaines des fichiers chargés qui ne sont pas le site lui-même (même règle que Requêtes
	 * sortantes pour reconnaître le site), triés, sans doublon.
	 *
	 * @param list<array{url: string, type: string}> $resources Fichiers relevés par resources().
	 * @return list<string>
	 */
	public static function third_party_hosts( array $resources ): array {
		$hosts = array();
		foreach ( $resources as $resource ) {
			$host = wp_parse_url( $resource['url'], PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host && ! Http::is_allowed( $host, array() ) ) {
				$hosts[] = strtolower( $host );
			}
		}
		$hosts = array_values( array_unique( $hosts ) );
		sort( $hosts );
		return $hosts;
	}

	/**
	 * Domaine non joignable depuis Internet : la Green Web Foundation ne pourrait rien en dire.
	 *
	 * @param string $host Nom d'hôte, en minuscules.
	 */
	public static function is_local_host( string $host ): bool {
		$host = trim( $host, '[].' );
		if ( '' === $host || 'localhost' === $host ) {
			return true;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		if ( ! str_contains( $host, '.' ) ) {
			return true;
		}
		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hébergeur reconnu par la Green Web Foundation. Enregistre aussi le statut pour le badge.
	 *
	 * @param string $host Domaine du site.
	 * @return Item
	 */
	public static function check_host( string $host ): array {
		$title = __( 'Host powered by renewable energy', 'fluffless-suite' );
		$green = null;
		$item  = self::item( 'unknown', $title, '', '' );

		if ( self::is_local_host( $host ) ) {
			$item['detail'] = sprintf(
				/* translators: %s: domain name, e.g. localhost */
				__( 'Impossible to check: %s is not a public domain. Run the check again once the site is online.', 'fluffless-suite' ),
				$host
			);
		} else {
			$response = wp_remote_get( self::GWF_API . rawurlencode( $host ), array( 'timeout' => self::TIMEOUT ) );
			$data     = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response )
				? null
				: json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && is_bool( $data['green'] ?? null ) ) {
				$green     = $data['green'];
				$hosted_by = is_string( $data['hosted_by'] ?? null ) ? sanitize_text_field( $data['hosted_by'] ) : '';
				$item      = $green
					? self::item(
						'good',
						$title,
						'' === $hosted_by
							? __( 'The Green Web Foundation lists your host as running on renewable energy.', 'fluffless-suite' )
							/* translators: %s: hosting company name */
							: sprintf( __( 'The Green Web Foundation lists your host (%s) as running on renewable energy.', 'fluffless-suite' ), $hosted_by ),
						''
					)
					: self::item(
						'bad',
						$title,
						__( 'The Green Web Foundation does not recognize your host. Until it does, do not claim renewable-powered hosting on your site.', 'fluffless-suite' ),
						__( 'Ask your host whether its data centres run on renewable energy and whether it is registered with the Green Web Foundation. Otherwise, the Green Web Foundation directory lists verified hosts by country.', 'fluffless-suite' )
					);
			} else {
				$item['detail'] = is_wp_error( $response )
					/* translators: %s: error message from the HTTP request */
					? sprintf( __( 'Impossible to check: the Green Web Foundation could not be reached (%s).', 'fluffless-suite' ), $response->get_error_message() )
					: __( 'Impossible to check: the Green Web Foundation sent an unexpected answer.', 'fluffless-suite' );
			}
		}

		// Le dernier résultat remplace toujours le précédent : une vérification ratée éteint le badge.
		set_transient(
			Lifecycle::TRANSIENT_HOST_STATUS,
			array(
				'green'   => $green,
				'checked' => time(),
			),
			self::HOST_STATUS_TTL
		);
		return $item;
	}

	/**
	 * Protocole, compression, cache et poids, d'après l'accueil.
	 *
	 * @param string                                                                    $url   Adresse de l'accueil.
	 * @param bool                                                                      $local Site local : le protocole ne dit rien de l'hébergeur final.
	 * @param int                                                                       $bytes Reçoit le poids mesuré de l'accueil (0 sans réponse), pour la comparaison avec la boutique.
	 * @param array<string, array{bytes: int|null, cache_control: string, error: bool}> $memo Tailles déjà relevées, partagées avec la boutique.
	 * @param array<int, string>                                                        $third Reçoit les domaines tiers des fichiers chargés.
	 * @phpstan-param list<string> $third
	 * @return array<string, Item>
	 */
	public static function check_home( string $url, bool $local, int &$bytes = 0, array &$memo = array(), array &$third = array() ): array {
		$page = self::fetch( $url, $url, 5, self::MAX_RESOURCES, $memo );
		if ( is_string( $page ) ) {
			/* translators: %s: error message or HTTP status code */
			$detail = sprintf( __( 'Impossible to check: the home page did not answer (%s).', 'fluffless-suite' ), $page );
			$items  = array();
			foreach ( self::home_titles() as $key => $title ) {
				$items[ $key ] = self::item( 'unknown', $title, $detail, '' );
			}
			return $items;
		}

		[ 'response' => $response, 'html' => $html, 'resources' => $resources, 'sizes' => $sizes ] = $page;
		$third = self::third_party_hosts( $resources );

		$items = array(
			'protocol'    => self::check_protocol( $url, self::header( $response, 'alt-svc' ), $local ),
			'compression' => self::check_compression( self::header( $response, 'content-encoding' ) ),
		);
		foreach ( array( 'css', 'image', 'font' ) as $type ) {
			$items[ 'cache_' . $type ] = self::check_cache( $type, $resources, $sizes );
		}
		$items['weight']   = self::check_weight( $page['html_bytes'], $page['compressed'], $sizes );
		$bytes             = self::weigh( $page['html_bytes'], $page['compressed'], $sizes )['bytes'];
		$items['captcha']  = self::check_captcha( $html );
		$items['core_css'] = self::check_core_css( $html );
		return $items;
	}

	/**
	 * Titres des points tirés de l'accueil.
	 *
	 * @return array<string, string>
	 */
	private static function home_titles(): array {
		return array(
			'protocol'    => __( 'HTTP/2 or HTTP/3', 'fluffless-suite' ),
			'compression' => __( 'Brotli or Gzip compression', 'fluffless-suite' ),
			'cache_css'   => __( 'Browser caching of style sheets', 'fluffless-suite' ),
			'cache_image' => __( 'Browser caching of images', 'fluffless-suite' ),
			'cache_font'  => __( 'Browser caching of fonts', 'fluffless-suite' ),
			'weight'      => __( 'Home page weight', 'fluffless-suite' ),
			'captcha'     => __( 'Third-party captcha', 'fluffless-suite' ),
			'core_css'    => __( 'Block styles written by WordPress', 'fluffless-suite' ),
		);
	}

	/**
	 * Captcha tiers (reCAPTCHA, hCaptcha, Turnstile) : chargé sur l'accueil, ou réglé dans une
	 * extension de formulaires établie. Chaque visite passe alors par un serveur étranger, et le
	 * visiteur est profilé. ALTCHA fait le même travail sur le site, sans appel dehors.
	 *
	 * @param string $html Accueil rendu.
	 * @return Item
	 */
	public static function check_captcha( string $html ): array {
		$title    = self::home_titles()['captcha'];
		$loaded   = Forms::captcha_hosts_in_html( $html );
		$services = Forms::captcha_services();
		$fix      = __( 'Switch the form extension to ALTCHA (free ALTCHA plugin, works with Contact Form 7, WPForms, Fluent Forms, Formidable and Gravity Forms), or to the Form block of this suite, which needs no captcha.', 'fluffless-suite' );
		if ( array() !== $loaded ) {
			/* translators: %s: comma-separated list of captcha services */
			$detail = sprintf( __( 'The home page loads %s: every visit calls a third-party server that profiles the visitor.', 'fluffless-suite' ), implode( ', ', $loaded ) );
			return self::item( 'bad', $title, $detail, $fix );
		}
		if ( array() !== $services ) {
			$pairs = array();
			foreach ( $services as $plugin => $service ) {
				$pairs[] = $plugin . ' (' . $service . ')';
			}
			/* translators: %s: comma-separated list of form extensions with their captcha service */
			$detail = sprintf( __( 'A third-party captcha is set in %s: pages that show a form call its server, and the visitor is profiled.', 'fluffless-suite' ), implode( ', ', $pairs ) );
			return self::item( 'warn', $title, $detail, $fix );
		}
		return self::item( 'good', $title, __( 'No reCAPTCHA, hCaptcha or Turnstile on the home page, none set in a known form extension.', 'fluffless-suite' ), '' );
	}

	/**
	 * Styles de blocs que le cœur écrit dans la page (balises style « wp-block-*-inline-css » et
	 * « core-block-supports-inline-css ») : un coût de WordPress, hors budget du thème, mesuré à
	 * part pour que le poids de l'accueil ne lui soit pas imputé. Le bloc Navigation seul pèse
	 * près de 21 ko.
	 *
	 * @param string $html Accueil rendu.
	 * @return Item
	 */
	public static function check_core_css( string $html ): array {
		$title  = self::home_titles()['core_css'];
		$sheets = self::core_sheets( $html );
		if ( array() === $sheets ) {
			return self::item( 'good', $title, __( 'No block style written in the page by WordPress core.', 'fluffless-suite' ), '' );
		}
		arsort( $sheets, SORT_NUMERIC );
		$total   = array_sum( $sheets );
		$largest = (string) array_key_first( $sheets );
		$detail  = sprintf(
			/* translators: 1: total weight, e.g. 24 KB, 2: number of style sheets, 3: name of the largest block, e.g. navigation, 4: its weight */
			_n(
				'%1$s in %2$s style sheet, for the %3$s block (%4$s). This weight comes from WordPress core, not from the theme, and is counted apart from its budget.',
				'%1$s in %2$s style sheets, the largest for the %3$s block (%4$s). This weight comes from WordPress core, not from the theme, and is counted apart from its budget.',
				count( $sheets ),
				'fluffless-suite'
			),
			size_format( $total ),
			number_format_i18n( count( $sheets ) ),
			$largest,
			size_format( $sheets[ $largest ] )
		);
		$status = $total > self::CORE_CSS_WARN_BYTES ? 'warn' : 'good';
		$fix    = 'good' === $status ? '' : __( 'Nothing to fix in the theme. Fewer kinds of core blocks on the home page mean fewer style sheets; the Navigation block alone weighs about 20 KB.', 'fluffless-suite' );
		return self::item( $status, $title, $detail, $fix );
	}

	/**
	 * Poids des styles de blocs du cœur écrits dans la page, par nom de bloc (« navigation »,
	 * « block-supports »), en octets.
	 *
	 * @param string $html Accueil rendu.
	 * @return array<string, int>
	 */
	public static function core_sheets( string $html ): array {
		$sheets = array();
		if ( ! preg_match_all( '#<style\b[^>]*\bid=(["\'])(wp-block-([a-z0-9-]+)|core-block-supports)-inline-css\1[^>]*>(.*?)</style>#si', $html, $matches, PREG_SET_ORDER ) ) {
			return $sheets;
		}
		foreach ( $matches as $match ) {
			$name            = '' === $match[3] ? 'block-supports' : $match[3];
			$sheets[ $name ] = ( $sheets[ $name ] ?? 0 ) + strlen( $match[4] );
		}
		return $sheets;
	}

	/**
	 * HTTP/3 annoncé par Alt-Svc, sinon version négociée par la sonde.
	 *
	 * @param string $url     Adresse de l'accueil.
	 * @param string $alt_svc En-tête Alt-Svc reçu.
	 * @param bool   $local   Site local.
	 * @return Item
	 */
	public static function check_protocol( string $url, string $alt_svc, bool $local ): array {
		$title = self::home_titles()['protocol'];
		$fix   = __( 'Ask your host to turn on HTTP/2 (or HTTP/3) for your domain. It is usually a setting, not a migration.', 'fluffless-suite' );
		if ( $local ) {
			return self::item( 'unknown', $title, __( 'Impossible to check on a local site: the result would describe your computer, not your host.', 'fluffless-suite' ), '' );
		}
		if ( 1 === preg_match( '/\bh3\b/', $alt_svc ) ) {
			return self::item( 'good', $title, __( 'Your server offers HTTP/3.', 'fluffless-suite' ), '' );
		}
		$version = self::http_version( $url );
		if ( null === $version ) {
			return self::item( 'unknown', $title, __( 'Impossible to check: this server cannot test the HTTP version.', 'fluffless-suite' ), '' );
		}
		if ( in_array( $version, array( '2', '3' ), true ) ) {
			/* translators: %s: HTTP version number, e.g. 2 */
			return self::item( 'good', $title, sprintf( __( 'Your server answers in HTTP/%s.', 'fluffless-suite' ), $version ), '' );
		}
		/* translators: %s: HTTP version number, e.g. 1.1 */
		return self::item( 'bad', $title, sprintf( __( 'Your server answers in HTTP/%s: each file waits its turn.', 'fluffless-suite' ), $version ), $fix );
	}

	/**
	 * Version HTTP négociée avec l'accueil. L'API HTTP de WordPress (Requests) force HTTP/1.1
	 * et ne sait pas lire une réponse HTTP/2 : la sonde passe donc par cURL directement, après les
	 * mêmes gardes que l'API HTTP.
	 *
	 * @param string $url Adresse https de l'accueil.
	 * @return '1.0'|'1.1'|'2'|'3'|null Null si impossible à savoir.
	 */
	public static function http_version( string $url ): ?string {
		/**
		 * Court-circuite la sonde (tests, ou serveur sans cURL mais avec un autre moyen de savoir).
		 *
		 * @param string|null $version Version ('1.1', '2', '3') ou null pour lancer la sonde.
		 * @param string      $url     Adresse sondée.
		 */
		$pre = apply_filters( 'fluffless_audit_http_version', null, $url );
		if ( in_array( $pre, array( '1.0', '1.1', '2', '3' ), true ) ) {
			return $pre;
		}
		if ( ! function_exists( 'curl_init' ) || ! str_starts_with( $url, 'https://' ) || ! defined( 'CURL_HTTP_VERSION_2TLS' ) ) {
			return null;
		}
		// Mêmes gardes que wp_safe_remote_head() : adresse sûre, puis pre_http_request, où vit la liste
		// des domaines permis (Requêtes sortantes) et tout autre blocage posé par une extension.
		if ( false === wp_http_validate_url( $url ) ) {
			return null;
		}
		$args = array(
			'method'             => 'HEAD',
			'timeout'            => self::TIMEOUT,
			'reject_unsafe_urls' => true,
		);
		if ( false !== apply_filters( 'pre_http_request', false, $args, $url ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur, appliqué comme WP_Http::request().
			return null;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- voir le docblock : Requests ne négocie pas HTTP/2.
		$handle = curl_init( $url );
		if ( false === $handle ) {
			return null;
		}
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_NOBODY         => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => self::TIMEOUT,
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			)
		);
		$ok      = false !== curl_exec( $handle );
		$version = (int) curl_getinfo( $handle, CURLINFO_HTTP_VERSION );
		// phpcs:enable
		// Pas de curl_close() : sans effet depuis PHP 8.0, la poignée est libérée en sortie de fonction.
		$map = array(
			CURL_HTTP_VERSION_1_0 => '1.0',
			CURL_HTTP_VERSION_1_1 => '1.1',
			CURL_HTTP_VERSION_2_0 => '2',
		);
		return $ok ? ( $map[ $version ] ?? null ) : null;
	}

	/**
	 * Compression de l'accueil.
	 *
	 * @param string $encoding En-tête Content-Encoding reçu.
	 * @return Item
	 */
	public static function check_compression( string $encoding ): array {
		$title    = self::home_titles()['compression'];
		$encoding = strtolower( trim( $encoding ) );
		if ( in_array( $encoding, array( 'br', 'zstd' ), true ) ) {
			return self::item( 'good', $title, __( 'Pages are compressed with Brotli (or better).', 'fluffless-suite' ), '' );
		}
		if ( in_array( $encoding, array( 'gzip', 'deflate' ), true ) ) {
			return self::item( 'good', $title, __( 'Pages are compressed with Gzip. Brotli would save a little more.', 'fluffless-suite' ), '' );
		}
		return self::item(
			'bad',
			$title,
			__( 'The home page travels uncompressed: text files are often three to five times heavier than they need to be.', 'fluffless-suite' ),
			__( 'Ask your host to turn on Brotli or Gzip compression for HTML, CSS, JavaScript and SVG.', 'fluffless-suite' )
		);
	}

	/**
	 * Cache-Control du premier fichier du type demandé.
	 *
	 * @param string                                                                         $type      css, image ou font.
	 * @param list<array{url: string, type: string}>                                         $resources Ressources de l'accueil.
	 * @param array<string, array{bytes: int|null, cache_control: string, error: bool}|null> $heads     Réponses HEAD, par adresse.
	 * @return Item
	 */
	public static function check_cache( string $type, array $resources, array $heads ): array {
		$title = self::home_titles()[ 'cache_' . $type ];
		$fix   = __( 'Ask your host to send “Cache-Control: max-age=31536000, immutable” for versioned CSS, JavaScript, fonts and images.', 'fluffless-suite' );
		foreach ( $resources as $resource ) {
			$head = $heads[ $resource['url'] ] ?? null;
			if ( $type !== $resource['type'] || null === $head || $head['error'] ) {
				continue;
			}
			$file   = wp_basename( (string) wp_parse_url( $resource['url'], PHP_URL_PATH ) );
			$header = strtolower( $head['cache_control'] );
			$max    = 1 === preg_match( '/max-age=(\d+)/', $header, $m ) ? (int) $m[1] : 0;
			if ( str_contains( $header, 'immutable' ) || $max >= self::CACHE_GOOD_SECONDS ) {
				/* translators: %s: file name */
				return self::item( 'good', $title, sprintf( __( 'Returning visitors reuse %s without downloading it again.', 'fluffless-suite' ), $file ), '' );
			}
			if ( $max > 0 && ! str_contains( $header, 'no-store' ) ) {
				return self::item(
					'warn',
					$title,
					/* translators: 1: file name, 2: number of days */
					sprintf( _n( '%1$s is kept for %2$s day only.', '%1$s is kept for %2$s days only.', max( 1, intdiv( $max, DAY_IN_SECONDS ) ), 'fluffless-suite' ), $file, number_format_i18n( intdiv( $max, DAY_IN_SECONDS ) ) ),
					$fix
				);
			}
			/* translators: %s: file name */
			return self::item( 'bad', $title, sprintf( __( 'No caching instruction for %s: browsers may download it on every visit.', 'fluffless-suite' ), $file ), $fix );
		}
		return self::item( 'unknown', $title, __( 'Impossible to check: no file of this type was found on the home page.', 'fluffless-suite' ), '' );
	}

	/**
	 * Poids transféré de l'accueil comparé au budget.
	 *
	 * @param int                                                                            $html_bytes Poids du HTML.
	 * @param bool                                                                           $compressed Le poids du HTML vient de Content-Length (transféré).
	 * @param array<string, array{bytes: int|null, cache_control: string, error: bool}|null> $heads      Réponses HEAD, par adresse.
	 * @return Item
	 */
	public static function check_weight( int $html_bytes, bool $compressed, array $heads ): array {
		$title = self::home_titles()['weight'];
		[ 'bytes' => $total, 'requests' => $requests, 'unknown' => $unknown ] = self::weigh( $html_bytes, $compressed, $heads );
		$detail = sprintf(
			/* translators: 1: page weight, e.g. 212 KB, 2: budget, e.g. 300 KB, 3: number of requests, 4: request budget */
			__( '%1$s for a budget of %2$s, %3$s requests for a budget of %4$s.', 'fluffless-suite' ),
			size_format( $total ),
			size_format( self::HOME_BUDGET_BYTES ),
			number_format_i18n( $requests ),
			number_format_i18n( self::REQUEST_BUDGET )
		);
		if ( $unknown > 0 ) {
			$detail .= ' ' . sprintf(
				/* translators: %s: number of files */
				_n( 'Estimate: the size of %s file is unknown.', 'Estimate: the size of %s files is unknown.', $unknown, 'fluffless-suite' ),
				number_format_i18n( $unknown )
			);
		}
		$status = 'good';
		if ( $total > self::HOME_BUDGET_BYTES * 1.5 ) {
			$status = 'bad';
		} elseif ( $total > self::HOME_BUDGET_BYTES || $requests > self::REQUEST_BUDGET ) {
			$status = 'warn';
		}
		$fix = 'good' === $status ? '' : __( 'Start with the largest files (images, fonts, scripts). In the editor, the Page footprint panel names the heaviest one.', 'fluffless-suite' );
		return self::item( $status, $title, $detail, $fix );
	}

	/**
	 * Poids transféré d'une page : HTML plus fichiers appelés, et fichiers au poids inconnu.
	 *
	 * @param int                                                                            $html_bytes Poids du HTML.
	 * @param bool                                                                           $compressed Le poids du HTML vient de Content-Length (transféré).
	 * @param array<string, array{bytes: int|null, cache_control: string, error: bool}|null> $heads      Réponses HEAD, par adresse.
	 * @return array{bytes: int, requests: int, unknown: int}
	 */
	private static function weigh( int $html_bytes, bool $compressed, array $heads ): array {
		$total   = $html_bytes;
		$unknown = $compressed ? 0 : 1;
		foreach ( $heads as $head ) {
			if ( null === $head || null === $head['bytes'] ) {
				++$unknown;
				continue;
			}
			$total += $head['bytes'];
		}
		return array(
			'bytes'    => $total,
			'requests' => 1 + count( $heads ),
			'unknown'  => $unknown,
		);
	}

	/**
	 * Charge une page comme un navigateur sans témoin, puis la taille de chaque fichier qu'elle appelle.
	 *
	 * @param string                                                                    $url         Page.
	 * @param string                                                                    $home        Accueil du site (fichiers du site ou tiers).
	 * @param int                                                                       $redirection Redirections suivies (0 : une redirection est un échec).
	 * @param int                                                                       $limit       Fichiers relevés au plus.
	 * @param array<string, array{bytes: int|null, cache_control: string, error: bool}> $memo Réponses HEAD déjà obtenues, par adresse, partagées entre pages.
	 * @param float                                                                     $deadline    Heure (microtime) au-delà de laquelle plus aucun fichier n'est relevé ; 0 : aucune.
	 * @return array{response: array<mixed>, html: string, html_bytes: int, compressed: bool, resources: list<array{url: string, type: string}>, sizes: array<string, array{bytes: int|null, cache_control: string, error: bool}>, truncated: bool}|string La page, ou la raison de l'échec (message, code HTTP, ou code suivi de l'adresse de destination pour une redirection).
	 */
	private static function fetch( string $url, string $home, int $redirection = 5, int $limit = self::MAX_RESOURCES, array &$memo = array(), float $deadline = 0.0 ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'limit_response_size' => self::MAX_HTML,
				'redirection'         => $redirection,
				'headers'             => array( 'Accept-Encoding' => 'br, gzip' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return $code >= 300 && $code < 400 ? trim( $code . ' ' . self::header( $response, 'location' ) ) : (string) $code;
		}
		$html      = wp_remote_retrieve_body( $response );
		$resources = self::resources( $html, $url, $limit );
		$sizes     = array();
		$late      = false;
		foreach ( $resources as $resource ) {
			if ( ! isset( $memo[ $resource['url'] ] ) && $deadline > 0 && microtime( true ) > $deadline ) {
				$late = true;
				break;
			}
			$memo[ $resource['url'] ] ??= self::head( $resource['url'], $home );
			$sizes[ $resource['url'] ] = $memo[ $resource['url'] ];
		}
		$length = wp_remote_retrieve_header( $response, 'content-length' );
		return array(
			'response'   => $response,
			'html'       => $html,
			'html_bytes' => is_numeric( $length ) ? (int) $length : strlen( $html ),
			'compressed' => is_numeric( $length ),
			'resources'  => $resources,
			'sizes'      => $sizes,
			'truncated'  => $late || count( $resources ) >= $limit,
		);
	}

	/**
	 * Pages de boutique à mesurer quand WooCommerce est actif : la boutique, la fiche du dernier
	 * produit publié et visible au catalogue, le panier et la caisse, chacune avec son seuil.
	 *
	 * @return list<array{key: string, label: string, url: string, budget: int}>
	 */
	public static function shop_pages(): array {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
			return array();
		}
		$pages = array();
		if ( wc_get_page_id( 'shop' ) > 0 ) {
			$pages[] = array(
				'key'    => 'shop',
				'label'  => __( 'Shop', 'fluffless-suite' ),
				'url'    => wc_get_page_permalink( 'shop' ),
				'budget' => self::SHOP_BUDGET_BYTES,
			);
		}
		$product = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- un seul produit, bilan lancé à la main.
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => array( 'exclude-from-catalog' ),
						'operator' => 'NOT IN',
					),
				),
			)
		);
		$link    = array() === $product ? false : get_permalink( (int) $product[0] );
		if ( is_string( $link ) ) {
			$pages[] = array(
				'key'    => 'product',
				'label'  => __( 'Product page', 'fluffless-suite' ),
				'url'    => $link,
				'budget' => self::SHOP_BUDGET_BYTES,
			);
		}
		foreach ( array(
			'cart'     => __( 'Cart', 'fluffless-suite' ),
			'checkout' => __( 'Checkout', 'fluffless-suite' ),
		) as $page => $label ) {
			if ( wc_get_page_id( $page ) > 0 ) {
				$pages[] = array(
					'key'    => $page,
					'label'  => $label,
					'url'    => wc_get_page_permalink( $page ),
					'budget' => self::CHECKOUT_BUDGET_BYTES,
				);
			}
		}
		return $pages;
	}

	/** Boutique fermée aux visiteurs : mode Bientôt en ligne de la Suite ou de WooCommerce. */
	public static function shop_hidden(): bool {
		return Options::is_enabled( Maintenance::id() ) || 'yes' === get_option( 'woocommerce_coming_soon' );
	}

	/**
	 * Point « boutique » : poids de chaque page de boutique comparé à son seuil, mesuré sans
	 * panier, comme à une première visite. Une caisse vide renvoie d'ordinaire au panier : elle
	 * est alors dite non mesurée, et le point ne peut plus être bon. Une page tronquée, hors délai,
	 * avec un script ou une feuille de style de taille inconnue, ou aux poids trop souvent
	 * inconnus n'est jamais dite bonne.
	 *
	 * @param list<array{key?: string, label: string, url: string, budget: int}>        $pages      Pages, de shop_pages().
	 * @param int                                                                       $home_bytes Poids de l'accueil, 0 s'il est inconnu.
	 * @param array<string, array{bytes: int|null, cache_control: string, error: bool}> $memo       Tailles déjà relevées sur l'accueil.
	 * @param float                                                                     $deadline   Heure (microtime) au-delà de laquelle plus rien n'est mesuré ; 0 : aucune.
	 * @return Item
	 */
	public static function check_shop( array $pages, int $home_bytes = 0, array &$memo = array(), float $deadline = 0.0 ): array {
		$title = __( 'Shop pages weight', 'fluffless-suite' );
		if ( self::shop_hidden() ) {
			return self::item( 'unknown', $title, __( 'The shop is not open to visitors yet (coming soon or maintenance mode): its pages cannot be measured. Run the check again once it is open.', 'fluffless-suite' ), '' );
		}
		$home     = home_url( '/' );
		$cart     = (string) ( array_column( $pages, 'url', 'key' )['cart'] ?? '' );
		$rank     = array(
			'good'    => 0,
			'unknown' => 1,
			'warn'    => 2,
			'bad'     => 3,
		);
		$status   = 'good';
		$lines    = array();
		$unknown  = 0;
		$measured = 0;
		// Adresse comparée chemin et requête compris : avec des permaliens simples, tout passe par « / ».
		$place = static function ( string $url ): string {
			$parts = wp_parse_url( $url );
			$parts = is_array( $parts ) ? $parts : array();
			return untrailingslashit( (string) ( $parts['path'] ?? '' ) ) . '?' . (string) ( $parts['query'] ?? '' );
		};
		foreach ( $pages as $page ) {
			if ( $deadline > 0 && microtime( true ) > $deadline ) {
				/* translators: %s: page name, e.g. Checkout */
				$lines[] = sprintf( __( '%s: not measured, the check ran out of time.', 'fluffless-suite' ), $page['label'] );
				$status  = 'good' === $status ? 'unknown' : $status;
				continue;
			}
			$checkout = 'checkout' === ( $page['key'] ?? '' );
			$fetched  = self::fetch( $page['url'], $home, $checkout ? 0 : 5, self::MAX_SHOP_RESOURCES, $memo, $deadline );
			if ( is_string( $fetched ) ) {
				$parts  = explode( ' ', $fetched, 2 );
				$status = 'good' === $status ? 'unknown' : $status;
				if ( $checkout && 1 === preg_match( '/^3\d\d$/', $parts[0] ) ) {
					$to      = $parts[1] ?? '';
					$lines[] = '' !== $cart && $place( $to ) === $place( $cart )
						/* translators: %s: page name, e.g. Checkout */
						? sprintf( __( '%s: not measured. With an empty cart, WooCommerce sends the visitor to the cart; the checkout also loads the payment scripts, so it weighs more than the cart.', 'fluffless-suite' ), $page['label'] )
						/* translators: 1: page name, e.g. Checkout; 2: address */
						: sprintf( __( '%1$s: sends the visitor to %2$s, no measure.', 'fluffless-suite' ), $page['label'], '' === $to ? '?' : $to );
					continue;
				}
				/* translators: 1: page name, e.g. Cart; 2: error message or HTTP status code */
				$lines[] = sprintf( __( '%1$s: did not answer (%2$s).', 'fluffless-suite' ), $page['label'], $fetched );
				continue;
			}
			++$measured;
			$weight   = self::weigh( $fetched['html_bytes'], $fetched['compressed'], $fetched['sizes'] );
			$unknown += $weight['unknown'];
			// Script ou feuille de style de taille inconnue : souvent le plus lourd, le total n'est qu'un minimum.
			$partial = false;
			foreach ( $fetched['resources'] as $resource ) {
				if ( in_array( $resource['type'], array( 'css', 'js' ), true ) && null === ( $fetched['sizes'][ $resource['url'] ]['bytes'] ?? null ) ) {
					$partial = true;
					break;
				}
			}
			$line = $fetched['truncated']
				? sprintf(
					/* translators: 1: page name, e.g. Cart; 2: page weight, e.g. 844 KB; 3: number of requests; 4: budget, e.g. 1 MB */
					__( '%1$s: at least %2$s in more than %3$s requests, for a budget of %4$s.', 'fluffless-suite' ),
					$page['label'],
					size_format( $weight['bytes'] ),
					number_format_i18n( $weight['requests'] ),
					size_format( $page['budget'] )
				)
				: sprintf(
					$partial
						/* translators: 1: page name, e.g. Cart; 2: page weight, e.g. 844 KB; 3: number of requests; 4: budget, e.g. 1 MB */
						? _n( '%1$s: at least %2$s in %3$s request, for a budget of %4$s.', '%1$s: at least %2$s in %3$s requests, for a budget of %4$s.', $weight['requests'], 'fluffless-suite' )
						/* translators: 1: page name, e.g. Cart; 2: page weight, e.g. 844 KB; 3: number of requests; 4: budget, e.g. 1 MB */
						: _n( '%1$s: %2$s in %3$s request, for a budget of %4$s.', '%1$s: %2$s in %3$s requests, for a budget of %4$s.', $weight['requests'], 'fluffless-suite' ),
					$page['label'],
					size_format( $weight['bytes'] ),
					number_format_i18n( $weight['requests'] ),
					size_format( $page['budget'] )
				);
			if ( $home_bytes > 0 && $weight['bytes'] >= $home_bytes * 1.5 ) {
				/* translators: %s: ratio, e.g. 2.5 */
				$line .= ' ' . sprintf( __( 'That is %s times the home page.', 'fluffless-suite' ), number_format_i18n( $weight['bytes'] / $home_bytes, 1 ) );
			}
			$lines[]     = $line;
			$page_status = $weight['bytes'] > $page['budget'] * 1.5 ? 'bad' : ( $weight['bytes'] > $page['budget'] ? 'warn' : 'good' );
			if ( 'good' === $page_status && ( $fetched['truncated'] || $partial || $weight['unknown'] > $weight['requests'] * self::MAX_UNKNOWN_SHARE ) ) {
				$page_status = 'unknown';
			}
			if ( $rank[ $page_status ] > $rank[ $status ] ) {
				$status = $page_status;
			}
		}
		if ( 0 === $measured ) {
			return self::item( 'unknown', $title, implode( ' ', $lines ), '' );
		}
		$detail = implode( ' ', $lines ) . ' ' . __( 'Measured with an empty cart, as on a first visit.', 'fluffless-suite' );
		if ( $unknown > 0 ) {
			$detail .= ' ' . sprintf(
				/* translators: %s: number of files */
				_n( 'Estimate: the size of %s file is unknown.', 'Estimate: the size of %s files is unknown.', $unknown, 'fluffless-suite' ),
				number_format_i18n( $unknown )
			);
		}
		$fixes = array();
		if ( 'warn' === $status || 'bad' === $status ) {
			$fixes[] = __( 'On the shop and product pages, start with the product photos (2000 px wide at most). On the cart and checkout, most of the weight is WooCommerce’s own scripts: a block cart and checkout weigh more than the classic ones.', 'fluffless-suite' );
		}
		// Module éteint : conseil en tête, sans changer la couleur d'un poids sous son seuil.
		if ( ! Options::is_enabled( Shop::id() ) ) {
			array_unshift( $fixes, __( 'Turn on the Lean shop module: WooCommerce scripts then stay on the pages that sell, and product images are served at a sensible size.', 'fluffless-suite' ) );
		}
		return self::item( $status, $title, $detail, implode( ' ', $fixes ) );
	}

	/**
	 * Feuilles de style, scripts, images et polices appelés par le HTML, dédoublonnés et bornés.
	 *
	 * @param string $html HTML de l'accueil.
	 * @param string $base  Adresse de l'accueil, pour résoudre les chemins relatifs.
	 * @param int    $limit Fichiers relevés au plus.
	 * @return list<array{url: string, type: string}>
	 */
	public static function resources( string $html, string $base, int $limit = self::MAX_RESOURCES ): array {
		$found = array();
		$tags  = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( count( $found ) >= $limit ) {
				break;
			}
			$tag  = $tags->get_tag();
			$rel  = strtolower( (string) $tags->get_attribute( 'rel' ) );
			$type = null;
			$src  = null;
			if ( 'LINK' === $tag && str_contains( $rel, 'stylesheet' ) ) {
				$type = 'css';
				$src  = $tags->get_attribute( 'href' );
			} elseif ( 'LINK' === $tag && str_contains( $rel, 'preload' ) && 'font' === $tags->get_attribute( 'as' ) ) {
				$type = 'font';
				$src  = $tags->get_attribute( 'href' );
			} elseif ( 'SCRIPT' === $tag ) {
				$type = 'js';
				$src  = $tags->get_attribute( 'src' );
			} elseif ( 'IMG' === $tag ) {
				$type = 'image';
				$src  = $tags->get_attribute( 'src' );
			}
			if ( null !== $type && is_string( $src ) && '' !== $src && ! str_starts_with( $src, 'data:' ) ) {
				$found[ \WP_Http::make_absolute_url( html_entity_decode( $src ), $base ) ] = $type;
			}
		}
		// Polices déclarées en @font-face dans les styles en ligne (theme.json les écrit ainsi).
		if ( preg_match_all( '/url\(\s*[\'"]?([^\'")\s]+\.woff2?)(?:\?[^\'")\s]*)?[\'"]?\s*\)/i', $html, $matches ) ) {
			foreach ( $matches[1] as $font ) {
				if ( count( $found ) >= $limit ) {
					break;
				}
				$found[ \WP_Http::make_absolute_url( $font, $base ) ] ??= 'font';
			}
		}
		$list = array();
		foreach ( $found as $url => $type ) {
			if ( '' !== $url && null !== wp_parse_url( $url, PHP_URL_HOST ) ) {
				$list[] = array(
					'url'  => $url,
					'type' => $type,
				);
			}
		}
		return $list;
	}

	/**
	 * Requête HEAD : poids transféré et Cache-Control. Fichiers tiers par wp_safe_remote_head()
	 * (aucune adresse interne), fichiers du site par wp_remote_head().
	 *
	 * @param string $url  Fichier.
	 * @param string $home Accueil du site.
	 * @return array{bytes: int|null, cache_control: string, error: bool}
	 */
	private static function head( string $url, string $home ): array {
		$args     = array(
			'timeout' => self::TIMEOUT,
			'headers' => array( 'Accept-Encoding' => 'br, gzip' ),
		);
		$same     = wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( $home, PHP_URL_HOST );
		$response = $same ? wp_remote_head( $url, $args ) : wp_safe_remote_head( $url, $args );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array(
				'bytes'         => null,
				'cache_control' => '',
				'error'         => true,
			);
		}
		$length = wp_remote_retrieve_header( $response, 'content-length' );
		$bytes  = is_numeric( $length ) ? (int) $length : null;
		if ( null === $bytes ) {
			// Fichier compressé à la volée (souvent CSS et JS) : HEAD tait sa taille. Relu tel qu'il
			// voyage, sans décompression, comme le compterait un navigateur.
			$args['decompress']          = false;
			$args['limit_response_size'] = self::MAX_FILE;
			$full                        = $same ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
			if ( ! is_wp_error( $full ) && 200 === wp_remote_retrieve_response_code( $full ) ) {
				$bytes = strlen( wp_remote_retrieve_body( $full ) );
			}
		}
		return array(
			'bytes'         => $bytes,
			'cache_control' => self::header( $response, 'cache-control' ),
			'error'         => false,
		);
	}

	/**
	 * En-tête HTTP en une chaîne : un en-tête répété arrive en tableau, ses valeurs sont jointes.
	 *
	 * @param array<mixed> $response Réponse de l'API HTTP de WordPress.
	 * @param string       $name     Nom de l'en-tête.
	 */
	private static function header( array $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		return is_array( $value ) ? implode( ', ', $value ) : $value;
	}

	/**
	 * Construit un point du bilan.
	 *
	 * @param 'good'|'warn'|'bad'|'unknown' $status État.
	 * @param string                        $title  Ce qui est vérifié.
	 * @param string                        $detail Ce qu'on a trouvé, en langage clair.
	 * @param string                        $fix    Ce qu'il faut demander à l'hébergeur ('' si rien).
	 * @return Item
	 */
	private static function item( string $status, string $title, string $detail, string $fix ): array {
		return array(
			'status' => $status,
			'title'  => $title,
			'detail' => $detail,
			'fix'    => $fix,
		);
	}

	/** Écran Fluffless → Bilan. */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		$report = self::cached();
		echo '<div class="wrap">';
		Screen::header( __( 'Fluff check', 'fluffless-suite' ), __( 'This check looks at your live site the way a visitor’s browser does. It runs only when you ask, and the result is kept for 24 hours.', 'fluffless-suite' ) );
		// Divulgation : le point « hébergement renouvelable » sort du site (nom d'hôte seulement).
		echo AuditFix::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		echo '<p>' . esc_html__( 'To check your hosting, this screen sends your site’s host name to the Green Web Foundation, which lists hosts running on renewable energy. Nothing else leaves your site.', 'fluffless-suite' ) . '</p>';

		// Les points « sauvegardes », « cache de page », « vidéos » et « images » viennent des réglages et de la médiathèque, pas du bilan en cache : toujours à jour, toujours affichés.
		$items = array(
			'backups'    => self::check_backups(),
			'page_cache' => self::check_page_cache(),
			'videos'     => self::check_videos(),
			'images'     => self::check_images(),
		);
		if ( null !== $report ) {
			$date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $report['checked'] );
			/* translators: %s: date and time of the check */
			echo '<p>' . esc_html( sprintf( __( 'Last check: %s.', 'fluffless-suite' ), false === $date ? '' : $date ) ) . '</p>';
			if ( $report['local'] ) {
				echo Screen::note( 'idle', __( 'This is a local site: some points cannot be checked, and the others describe your computer, not your future host. Run the check again once the site is online.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			}
			$items           += $report['items'];
			$items['consent'] = self::check_consent( $report['third_party_hosts'] ?? array() );
		}
		self::render_items( $items );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		submit_button( null === $report ? __( 'Run the check', 'fluffless-suite' ) : __( 'Run again', 'fluffless-suite' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Liste des points : l'état est écrit en toutes lettres, la couleur ne fait que le doubler.
	 *
	 * @param array<string, Item> $items Points du bilan.
	 */
	private static function render_items( array $items ): void {
		// État du bilan => état de pastille (unknown devient idle, le gris neutre).
		$labels = array(
			'good'    => array( 'good', __( 'Good', 'fluffless-suite' ) ),
			'warn'    => array( 'warn', __( 'To improve', 'fluffless-suite' ) ),
			'bad'     => array( 'bad', __( 'To fix', 'fluffless-suite' ) ),
			'unknown' => array( 'idle', __( 'Impossible to check', 'fluffless-suite' ) ),
		);
		echo '<ul class="fl-grid">';
		foreach ( $items as $key => $item ) {
			[ $pill, $label ] = $labels[ $item['status'] ];
			printf(
				'<li class="fl-card"><div class="fl-card__head"><h2>%1$s</h2>%2$s</div><p>%3$s</p>',
				esc_html( $item['title'] ),
				Screen::pill( $pill, $label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::pill().
				esc_html( $item['detail'] )
			);
			if ( '' !== $item['fix'] ) {
				echo '<p><strong>' . esc_html__( 'What to do:', 'fluffless-suite' ) . '</strong> ' . esc_html( $item['fix'] ) . '</p>';
			}
			$fix = AuditFix::for_item( (string) $key, $item );
			if ( null !== $fix ) {
				echo AuditFix::form( $fix ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans AuditFix::form().
			}
			if ( 'host' === $key && 'bad' === $item['status'] ) {
				echo '<p><a href="' . esc_url( self::GWF_DIRECTORY ) . '" rel="noopener noreferrer" target="_blank">' . esc_html__( 'Green Web Foundation directory of verified hosts (opens in a new tab)', 'fluffless-suite' ) . '</a></p>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function hosts(): array {
		return array(
			array(
				'host'    => (string) wp_parse_url( self::GWF_API, PHP_URL_HOST ),
				'service' => __( 'Green Web Foundation (Fluff check)', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'action',
				'data'    => __( 'The host name of your site', 'fluffless-suite' ),
			),
			array(
				'scope'   => 'linked',
				'service' => __( 'Files your home page loads from other domains (Fluff check)', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'action',
				'data'    => __( 'A request from your server’s IP address', 'fluffless-suite' ),
			),
		);
	}
}
