<?php
/**
 * Quarantaine des images : inutilisées, ou originaux restés après conversion WebP.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules\Media;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Modules\Housekeeping;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\MediaWebp;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Règles :
 * - rien ne se supprime sans clic : la quarantaine déplace les fichiers dans un dossier protégé
 *   (wp-content/uploads/fluffless-quarantaine/<id>-<aléa>/, .htaccess + index.php) et masque le média
 *   (statut fluffless_quarantine, invisible dans la médiathèque et sur le site) ;
 * - restauration en un clic : fichiers remis en place, statut et métadonnées d'origine ;
 * - suppression définitive seulement sur « Vider la quarantaine » (liste, case de confirmation), jamais par cron ;
 * - détection prudente : un média cité quelque part n'est jamais proposé ; un doute le marque « à vérifier », décoché.
 *
 * Pour le module Ménage : Quarantine::check( $id ) dit si une image est utilisée, et Quarantine::url()
 * donne l'adresse de cet écran.
 *
 * @phpstan-type Check array{state: 'used'|'unused'|'uncertain', reason: string}
 * @phpstan-type Candidate array{id: int, kind: 'attachment'|'originals', title: string, bytes: int, reason: string, sure: bool}
 * @phpstan-type Entry array{kind: 'attachment'|'originals', dir: string, files: array<string, string>, bytes: int, time: int}
 */
final class Quarantine {

	public const PAGE   = 'fluffless-quarantine';
	public const ACTION = 'fluffless_quarantine';
	public const STATUS = 'fluffless_quarantine';
	public const META   = '_fluffless_quarantine';
	public const DIR    = 'fluffless-quarantaine';

	/** Jours de restauration garantis avant qu'une purge soit suggérée (jamais automatique). */
	public const RESTORE_DAYS = 30;

	/** Bornes : médias examinés par recherche, lignes affichées, contenus relus pour les galeries. */
	public const SCAN_LIMIT     = 200;
	public const LIST_LIMIT     = 100;
	private const GALLERY_LIMIT = 200;

	/** Crochets : statut, écran, formulaire, WP-CLI. Appelé par MediaWebp::register(). */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_status' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( self::class, 'add_page' ) );
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_post' ) );
			add_action( Housekeeping::UNUSED_MEDIA_HOOK, array( self::class, 'housekeeping_media' ) );
		}
		Cli::add( 'fluffless media quarantine', array( self::class, 'cli_quarantine' ) );
		Cli::add( 'fluffless media restore', array( self::class, 'cli_restore' ) );
		Cli::add( 'fluffless media purge', array( self::class, 'cli_purge' ) );
	}

	/** Statut privé : le média disparaît de la médiathèque et du site, sans quitter la base. */
	public static function register_status(): void {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => __( 'In quarantine', 'fluffless-suite' ),
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
	}

	/** Adresse de l'écran. */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Écran Ménage : pour chaque média listé, l'avis de check() et un lien vers la quarantaine.
	 * Aucun bouton ici : déplacer, restaurer et vider se font dans l'écran de quarantaine.
	 *
	 * @param mixed $rows Médias listés par le Ménage (list<array{id: int, title: string}>).
	 */
	public static function housekeeping_media( $rows ): void {
		if ( ! is_array( $rows ) ) {
			return;
		}
		$labels = array(
			'used'      => array( 'good', __( 'Used after all', 'fluffless-suite' ) ),
			'unused'    => array( 'warn', __( 'Not found anywhere', 'fluffless-suite' ) ),
			'uncertain' => array( 'idle', __( 'To check', 'fluffless-suite' ) ),
		);
		echo '<ul>';
		// Borne : check() fait quelques requêtes par média ; jamais plus que ce que la quarantaine examine.
		foreach ( array_slice( $rows, 0, self::SCAN_LIMIT ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = absint( is_scalar( $row['id'] ?? null ) ? $row['id'] : 0 );
			if ( 0 === $id ) {
				continue;
			}
			$title            = is_string( $row['title'] ?? null ) && '' !== $row['title'] ? $row['title'] : '#' . $id;
			$check            = self::check( $id );
			[ $pill, $label ] = $labels[ $check['state'] ];
			printf(
				'<li><a href="%1$s">%2$s</a> %3$s <span class="fl-muted">%4$s</span></li>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( $title ),
				Screen::pill( $pill, $label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::pill().
				esc_html( $check['reason'] )
			);
		}
		echo '</ul>';
		if ( self::can_act() ) {
			echo '<p><a class="button" href="' . esc_url( add_query_arg( 'scan', '1', self::url() ) ) . '">' . esc_html__( 'Review them in the quarantine', 'fluffless-suite' ) . '</a></p>';
		}
	}

	/** Les deux capacités exigées pour agir. */
	public static function can_act(): bool {
		return current_user_can( 'delete_posts' ) && current_user_can( 'upload_files' );
	}

	/**
	 * Le média est-il utilisé ? Cherche partout où une image peut être citée.
	 *
	 * @param int $id Média.
	 * @return Check
	 */
	public static function check( int $id ): array {
		global $wpdb;
		$used = static fn( string $reason ): array => array(
			'state'  => 'used',
			'reason' => $reason,
		);

		$identity = array( (int) get_option( 'site_icon' ), (int) get_option( 'site_logo' ), (int) get_theme_mod( 'custom_logo' ) );
		$header   = get_theme_mod( 'header_image_data' );
		if ( is_object( $header ) && isset( $header->attachment_id ) ) {
			$identity[] = (int) $header->attachment_id;
		}
		if ( in_array( $id, $identity, true ) ) {
			return $used( __( 'Site logo, icon or header image', 'fluffless-suite' ) );
		}
		if ( in_array( (string) $id, Options::get_list( 'footer', 'certification_ids' ), true ) ) {
			return $used( __( 'Fluffless footer', 'fluffless-suite' ) );
		}

		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		$base = (string) preg_replace( '/-scaled$/', '', pathinfo( $file, PATHINFO_FILENAME ) );
		if ( strlen( $base ) < 3 ) {
			return array(
				'state'  => 'uncertain',
				'reason' => __( 'To check: the file name is too short to search for it.', 'fluffless-suite' ),
			);
		}
		$name = '%' . $wpdb->esc_like( $base ) . '%';

		// Recherches ponctuelles, sur clic, bornées par SCAN_LIMIT médias.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1", $id ) ) ) {
			return $used( __( 'Featured image', 'fluffless-suite' ) );
		}
		// Contenu de tout type et tout statut : brouillons, révisions, modèles, styles globaux, CSS personnalisé.
		$post = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_title FROM {$wpdb->posts} WHERE ID <> %d AND post_type <> 'attachment' AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s) LIMIT 1",
				$id,
				'%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%',
				'%' . $wpdb->esc_like( 'Id":' . $id . ',' ) . '%',
				'%' . $wpdb->esc_like( 'Id":' . $id . '}' ) . '%',
				'%' . $wpdb->esc_like( 'attachment_' . $id ) . '%',
				$name
			)
		);
		if ( null !== $post ) {
			/* translators: %s: title of the content that uses the image */
			return $used( sprintf( __( 'Used in “%s”', 'fluffless-suite' ), '' !== $post ? $post : __( '(no title)', 'fluffless-suite' ) ) );
		}
		$gallery = self::in_gallery( $id );
		if ( null !== $gallery ) {
			return $gallery;
		}
		if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_value LIKE %s LIMIT 1", $id, $name ) ) ) {
			return $used( __( 'Custom field of a content', 'fluffless-suite' ) );
		}
		if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->termmeta} WHERE meta_value LIKE %s OR meta_value = %s LIMIT 1", $name, (string) $id ) ) ) {
			return $used( __( 'Field of a category or tag', 'fluffless-suite' ) );
		}
		// Réglages : thème, widgets, réglages Fluffless (image SEO, logo), extensions.
		if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND (option_value LIKE %s OR option_value LIKE %s) LIMIT 1", $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%', $name, '%' . $wpdb->esc_like( 'attachment_id";i:' . $id . ';' ) . '%' ) ) ) {
			return $used( __( 'Site settings or widgets', 'fluffless-suite' ) );
		}
		$number_meta = $wpdb->get_var( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_value = %s LIMIT 1", $id, (string) $id ) );
		// phpcs:enable
		if ( null !== $number_meta ) {
			return array(
				'state'  => 'uncertain',
				/* translators: %s: custom field name */
				'reason' => sprintf( __( 'To check: the custom field “%s” holds this image number.', 'fluffless-suite' ), (string) $number_meta ),
			);
		}
		$parent = (int) wp_get_post_parent_id( $id );
		if ( $parent > 0 && null !== get_post( $parent ) ) {
			return array(
				'state'  => 'uncertain',
				/* translators: %s: title of the parent content */
				'reason' => sprintf( __( 'To check: attached to “%s”, an old gallery may show it.', 'fluffless-suite' ), get_the_title( $parent ) ),
			);
		}
		return array(
			'state'  => 'unused',
			'reason' => __( 'Not found in content, featured images, fields or settings.', 'fluffless-suite' ),
		);
	}

	/**
	 * Galeries : [gallery ids="1,2"] et bloc Galerie ancien ("ids":[1,2]).
	 *
	 * @param int $id Média.
	 * @return Check|null
	 */
	private static function in_gallery( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture bornée, sur clic.
		$contents = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_content FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND (post_content LIKE %s OR post_content LIKE %s) LIMIT %d",
				'%' . $wpdb->esc_like( 'ids="' ) . '%',
				'%' . $wpdb->esc_like( '"ids":[' ) . '%',
				self::GALLERY_LIMIT + 1
			)
		);
		foreach ( array_slice( $contents, 0, self::GALLERY_LIMIT ) as $content ) {
			if ( 1 === preg_match( '/(?:ids="[^"]*|"ids":\[[^\]]*)(?<![0-9])' . $id . '(?![0-9])/', (string) $content ) ) {
				return array(
					'state'  => 'used',
					'reason' => __( 'Gallery', 'fluffless-suite' ),
				);
			}
		}
		if ( count( $contents ) > self::GALLERY_LIMIT ) {
			return array(
				'state'  => 'uncertain',
				'reason' => __( 'To check: too many galleries to read them all.', 'fluffless-suite' ),
			);
		}
		return null;
	}

	/**
	 * Images proposées : inutilisées (sûres ou à vérifier) et originaux convertis.
	 *
	 * @return array{unused: list<Candidate>, originals: list<Candidate>}
	 */
	public static function scan(): array {
		$ids    = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'numberposts'    => self::SCAN_LIMIT,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$unused = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( count( $unused ) >= self::LIST_LIMIT ) {
				break;
			}
			$check = self::check( $id );
			if ( 'used' !== $check['state'] ) {
				$unused[] = array(
					'id'     => $id,
					'kind'   => 'attachment',
					'title'  => get_the_title( $id ),
					'bytes'  => self::attachment_bytes( $id ),
					'reason' => $check['reason'],
					'sure'   => 'unused' === $check['state'],
				);
			}
		}
		return array(
			'unused'    => $unused,
			'originals' => self::originals(),
		);
	}

	/**
	 * Originaux restés sur le disque après une conversion WebP vérifiée.
	 *
	 * @return list<Candidate>
	 */
	public static function originals(): array {
		$ids  = get_posts(
			array(
				'post_type'     => 'attachment',
				'post_status'   => 'inherit',
				'numberposts'   => self::LIST_LIMIT,
				'fields'        => 'ids',
				'no_found_rows' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- écran ouvert sur clic.
				'meta_key'      => MediaWebp::META,
			)
		);
		$list = array();
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$files = self::original_files( $id );
			if ( array() !== $files && self::webp_is_valid( $id ) && '' === (string) get_post_meta( $id, self::META . '_originals', true ) ) {
				$list[] = array(
					'id'     => $id,
					'kind'   => 'originals',
					'title'  => get_the_title( $id ),
					'bytes'  => MediaWebp::bytes( $files ),
					'reason' => __( 'Original JPEG or PNG kept after a checked WebP conversion.', 'fluffless-suite' ),
					'sure'   => true,
				);
			}
		}
		return $list;
	}

	/**
	 * Fichiers d'origine encore présents d'un média converti (jamais un fichier servi aujourd'hui).
	 *
	 * @param int $id Média.
	 * @return list<string>
	 */
	private static function original_files( int $id ): array {
		$data = get_post_meta( $id, MediaWebp::META, true );
		if ( ! is_array( $data ) || 'converted' !== ( $data['status'] ?? '' ) || ! is_array( $data['old_files'] ?? null ) ) {
			return array();
		}
		$uploads = trailingslashit( wp_upload_dir()['basedir'] );
		$current = self::current_files( $id );
		$files   = array();
		foreach ( $data['old_files'] as $relative ) {
			$path = $uploads . ltrim( (string) $relative, '/' );
			if ( is_string( $relative ) && is_file( $path ) && ! in_array( $path, $current, true ) ) {
				$files[] = $path;
			}
		}
		return $files;
	}

	/**
	 * Fichiers servis aujourd'hui par le média.
	 *
	 * @param int $id Média.
	 * @return list<string>
	 */
	private static function current_files( int $id ): array {
		$attached = get_attached_file( $id, true );
		$meta     = wp_get_attachment_metadata( $id );
		return is_string( $attached ) ? MediaWebp::files( $attached, is_array( $meta ) ? $meta : array() ) : array();
	}

	/**
	 * Le WebP (ou AVIF) servi est-il lisible et aux dimensions déclarées ?
	 *
	 * @param int $id Média.
	 */
	public static function webp_is_valid( int $id ): bool {
		$attached = get_attached_file( $id, true );
		$meta     = wp_get_attachment_metadata( $id );
		$mime     = (string) get_post_mime_type( $id );
		if ( ! is_string( $attached ) || ! is_array( $meta ) || ! in_array( $mime, array( 'image/webp', 'image/avif' ), true ) || ! MediaWebp::is_valid_image( $attached, $mime ) ) {
			return false;
		}
		$size = wp_getimagesize( $attached );
		return is_array( $size ) && (int) ( $meta['width'] ?? 0 ) === $size[0] && (int) ( $meta['height'] ?? 0 ) === $size[1];
	}

	/**
	 * Poids des fichiers d'un média.
	 *
	 * @param int $id Média.
	 */
	public static function attachment_bytes( int $id ): int {
		return MediaWebp::bytes( self::current_files( $id ) );
	}

	/**
	 * Met un média inutilisé en quarantaine, après avoir revérifié qu'il n'est pas utilisé.
	 *
	 * @param int $id Média.
	 * @return true|\WP_Error
	 */
	public static function quarantine_attachment( int $id ) {
		// get_post_status() dirait « publish » pour un média sans parent : on lit la colonne elle-même.
		if ( 'attachment' !== get_post_type( $id ) || 'inherit' !== get_post_field( 'post_status', $id ) ) {
			return new \WP_Error( 'fluffless_quarantine_state', __( 'This image is not in the media library.', 'fluffless-suite' ) );
		}
		$check = self::check( $id );
		if ( 'used' === $check['state'] ) {
			/* translators: %s: where the image is used */
			return new \WP_Error( 'fluffless_quarantine_used', sprintf( __( 'Kept: this image is used (%s).', 'fluffless-suite' ), $check['reason'] ) );
		}
		$moved = self::move_in( $id, self::current_files( $id ), 'attachment' );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- une ligne ; wp_update_post() refuserait ce statut sur un média.
		$wpdb->update( $wpdb->posts, array( 'post_status' => self::STATUS ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		return true;
	}

	/**
	 * Met en quarantaine les originaux d'un média converti, si son WebP est valide.
	 *
	 * @param int $id Média.
	 * @return true|\WP_Error
	 */
	public static function quarantine_originals( int $id ) {
		if ( ! self::webp_is_valid( $id ) ) {
			return new \WP_Error( 'fluffless_quarantine_webp', __( 'Kept: the WebP version is missing or unreadable.', 'fluffless-suite' ) );
		}
		$files = self::original_files( $id );
		if ( array() === $files ) {
			return new \WP_Error( 'fluffless_quarantine_none', __( 'No original file left for this image.', 'fluffless-suite' ) );
		}
		return self::move_in( $id, $files, 'originals' );
	}

	/**
	 * Déplace des fichiers dans un dossier de quarantaine propre au média.
	 *
	 * @param int      $id    Média.
	 * @param string[] $files Chemins absolus dans uploads.
	 * @param string   $kind  attachment|originals.
	 * @phpstan-param list<string> $files
	 * @return true|\WP_Error
	 */
	private static function move_in( int $id, array $files, string $kind ) {
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$uploads  = trailingslashit( wp_upload_dir()['basedir'] );
		$relative = self::DIR . '/' . $id . '-' . strtolower( wp_generate_password( 16, false ) );
		if ( ! wp_mkdir_p( $uploads . $relative ) ) {
			return new \WP_Error( 'fluffless_quarantine_dir', __( 'The quarantine folder could not be created. Check write permissions on the uploads folder.', 'fluffless-suite' ) );
		}
		$moved = array();
		foreach ( $files as $path ) {
			$target = $uploads . $relative . '/' . wp_basename( $path );
			// Même volume : rename() est atomique ; en cas d'échec, on remet ce qui a déjà bougé.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! str_starts_with( $path, $uploads ) || ! rename( $path, $target ) ) {
				foreach ( $moved as $original => $name ) {
					rename( $uploads . $relative . '/' . $name, $uploads . $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				}
				return new \WP_Error( 'fluffless_quarantine_move', __( 'A file could not be moved: nothing was changed.', 'fluffless-suite' ) );
			}
			$moved[ substr( $path, strlen( $uploads ) ) ] = wp_basename( $path );
		}
		$entry = array(
			'kind'  => $kind,
			'dir'   => $relative,
			'files' => $moved,
			'bytes' => self::dir_bytes( $uploads . $relative, $moved ),
			'time'  => time(),
		);
		update_post_meta( $id, 'originals' === $kind ? self::META . '_originals' : self::META, $entry );
		return true;
	}

	/**
	 * Poids des fichiers déplacés.
	 *
	 * @param string                $dir   Dossier.
	 * @param array<string, string> $files Fichiers.
	 */
	private static function dir_bytes( string $dir, array $files ): int {
		$total = 0;
		foreach ( $files as $name ) {
			$total += (int) filesize( $dir . '/' . $name );
		}
		return $total;
	}

	/**
	 * Crée le dossier racine protégé (accès web refusé, liste interdite).
	 *
	 * @return true|\WP_Error
	 */
	private static function root() {
		$root = trailingslashit( wp_upload_dir()['basedir'] ) . self::DIR;
		if ( ! wp_mkdir_p( $root ) ) {
			return new \WP_Error( 'fluffless_quarantine_dir', __( 'The quarantine folder could not be created. Check write permissions on the uploads folder.', 'fluffless-suite' ) );
		}
		$guards = array(
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\nOptions -Indexes\n",
			'index.php' => "<?php\n// Silence.\n",
		);
		foreach ( $guards as $name => $content ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- deux petits fichiers de garde, écrits une fois.
			if ( ! is_file( $root . '/' . $name ) && false === file_put_contents( $root . '/' . $name, $content ) ) {
				return new \WP_Error( 'fluffless_quarantine_guard', __( 'The quarantine folder could not be protected: nothing was moved.', 'fluffless-suite' ) );
			}
		}
		return true;
	}

	/**
	 * Entrée de quarantaine validée.
	 *
	 * @param int    $id  Média.
	 * @param string $key Clé de métadonnée.
	 * @return Entry|null
	 */
	private static function entry( int $id, string $key ): ?array {
		$entry = get_post_meta( $id, $key, true );
		if ( ! is_array( $entry ) || ! is_string( $entry['dir'] ?? null ) || ! is_array( $entry['files'] ?? null ) || ! str_starts_with( $entry['dir'], self::DIR . '/' ) || str_contains( $entry['dir'], '..' ) ) {
			return null;
		}
		$files = array();
		foreach ( $entry['files'] as $original => $name ) {
			if ( is_string( $original ) && is_string( $name ) && ! str_contains( $original, '..' ) && wp_basename( $name ) === $name ) {
				$files[ $original ] = $name;
			}
		}
		return array(
			'kind'  => 'originals' === ( $entry['kind'] ?? '' ) ? 'originals' : 'attachment',
			'dir'   => $entry['dir'],
			'files' => $files,
			'bytes' => is_int( $entry['bytes'] ?? null ) ? $entry['bytes'] : 0,
			'time'  => is_int( $entry['time'] ?? null ) ? $entry['time'] : 0,
		);
	}

	/**
	 * Éléments en quarantaine.
	 *
	 * @return list<array{id: int, key: string, title: string, kind: 'attachment'|'originals', dir: string, files: array<string, string>, bytes: int, time: int}>
	 */
	public static function items(): array {
		// WP_Query ignore un statut non déclaré : sans lui, les médias en quarantaine seraient introuvables.
		if ( null === get_post_status_object( self::STATUS ) ) {
			self::register_status();
		}
		$items = array();
		foreach ( array( self::META, self::META . '_originals' ) as $key ) {
			$ids = get_posts(
				array(
					'post_type'     => 'attachment',
					'post_status'   => array( 'inherit', self::STATUS ),
					'numberposts'   => self::LIST_LIMIT,
					'fields'        => 'ids',
					'no_found_rows' => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- écran ouvert sur clic.
					'meta_key'      => $key,
				)
			);
			foreach ( $ids as $id ) {
				$entry = self::entry( (int) $id, $key );
				if ( null !== $entry ) {
					$items[] = array(
						'id'    => (int) $id,
						'key'   => $key,
						'title' => get_the_title( (int) $id ),
					) + $entry;
				}
			}
		}
		return $items;
	}

	/**
	 * Remet les fichiers en place ; le média redevient visible.
	 *
	 * @param int  $id        Média.
	 * @param bool $originals Restaurer les originaux convertis plutôt que le média.
	 * @return true|\WP_Error
	 */
	public static function restore( int $id, bool $originals = false ) {
		$key   = $originals ? self::META . '_originals' : self::META;
		$entry = self::entry( $id, $key );
		if ( null === $entry ) {
			return new \WP_Error( 'fluffless_quarantine_missing', __( 'This image is not in quarantine.', 'fluffless-suite' ) );
		}
		$uploads = trailingslashit( wp_upload_dir()['basedir'] );
		foreach ( $entry['files'] as $original => $name ) {
			if ( file_exists( $uploads . $original ) ) {
				/* translators: %s: file path */
				return new \WP_Error( 'fluffless_quarantine_conflict', sprintf( __( 'A file already exists at %s: nothing was restored.', 'fluffless-suite' ), $original ) );
			}
		}
		foreach ( $entry['files'] as $original => $name ) {
			wp_mkdir_p( dirname( $uploads . $original ) );
			rename( $uploads . $entry['dir'] . '/' . $name, $uploads . $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		}
		self::remove_dir( $uploads . $entry['dir'] );
		delete_post_meta( $id, $key );
		if ( ! $originals ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- une ligne, cache vidé juste après.
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'inherit' ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		}
		return true;
	}

	/**
	 * Suppression définitive d'un élément en quarantaine. Appelée seulement sur action explicite.
	 *
	 * @param int  $id        Média.
	 * @param bool $originals Supprimer les originaux convertis (le média WebP reste).
	 * @return int|\WP_Error Octets libérés.
	 */
	public static function purge( int $id, bool $originals = false ) {
		$key   = $originals ? self::META . '_originals' : self::META;
		$entry = self::entry( $id, $key );
		if ( null === $entry ) {
			return new \WP_Error( 'fluffless_quarantine_missing', __( 'This image is not in quarantine.', 'fluffless-suite' ) );
		}
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . $entry['dir'];
		foreach ( $entry['files'] as $name ) {
			wp_delete_file( $dir . '/' . $name );
		}
		self::remove_dir( $dir );
		if ( $originals ) {
			update_post_meta( $id, $key, 'purged' );
		} else {
			wp_delete_attachment( $id, true );
		}
		return $entry['bytes'];
	}

	/**
	 * Retire un dossier de quarantaine vide.
	 *
	 * @param string $dir Dossier.
	 */
	private static function remove_dir( string $dir ): void {
		if ( is_dir( $dir ) && array() === array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier vide créé par ce module.
		}
	}

	/** Ajoute Fluffless → Quarantaine. */
	public static function add_page(): void {
		Menu::add_tab( 'media', self::PAGE, static fn(): string => __( 'Unused images', 'fluffless-suite' ), array( self::class, 'render' ), 20 );
	}

	/** Traite le formulaire : quarantaine, restauration ou purge confirmée. */
	public static function handle_post(): void {
		if ( ! self::can_act() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fluffless-suite' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$ints  = static fn( string $name ): array => array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST[ $name ] ?? array() ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint ci-dessus.
		$done  = 0;
		$bytes = 0;
		$error = '';
		$op    = sanitize_key( (string) wp_unslash( $_POST['op'] ?? '' ) );
		// Droit sur chaque image, en plus de can_act() : un auteur ne touche pas aux images des autres.
		// Vérifié ici et non dans les fonctions, que WP-CLI appelle sans utilisateur connecté.
		$mine = static fn( int $id ): bool => current_user_can( 'delete_post', $id );

		if ( isset( $_POST['restore'] ) ) {
			$parts  = explode( ':', sanitize_text_field( wp_unslash( (string) $_POST['restore'] ) ) );
			$id     = absint( $parts[1] ?? 0 );
			$result = $mine( $id ) ? self::restore( $id, 'originals' === $parts[0] ) : new \WP_Error( 'fluffless_quarantine_forbidden', __( 'You are not allowed to do this.', 'fluffless-suite' ) );
			$done   = true === $result ? 1 : 0;
			$error  = is_wp_error( $result ) ? $result->get_error_message() : '';
			$op     = 'restore';
		} elseif ( 'quarantine' === $op ) {
			foreach ( array_slice( array_filter( $ints( 'ids' ), $mine ), 0, self::LIST_LIMIT ) as $id ) {
				$size   = self::attachment_bytes( $id );
				$result = self::quarantine_attachment( $id );
				$done  += true === $result ? 1 : 0;
				$bytes += true === $result ? $size : 0;
				$error  = is_wp_error( $result ) ? $result->get_error_message() : $error;
			}
			foreach ( array_slice( array_filter( $ints( 'originals' ), $mine ), 0, self::LIST_LIMIT ) as $id ) {
				$result = self::quarantine_originals( $id );
				if ( true === $result ) {
					++$done;
					$entry  = self::entry( $id, self::META . '_originals' );
					$bytes += null === $entry ? 0 : $entry['bytes'];
				} else {
					$error = $result->get_error_message();
				}
			}
		} elseif ( 'purge' === $op && isset( $_POST['confirm'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['confirm'] ) ) ) {
			$purge = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['purge'] ?? array() ) );
			foreach ( array_slice( $purge, 0, self::LIST_LIMIT ) as $raw ) {
				$parts = explode( ':', $raw );
				if ( ! $mine( absint( $parts[1] ?? 0 ) ) ) {
					continue;
				}
				$result = self::purge( absint( $parts[1] ?? 0 ), 'originals' === $parts[0] );
				$done  += is_int( $result ) ? 1 : 0;
				$bytes += is_int( $result ) ? $result : 0;
				$error  = is_wp_error( $result ) ? $result->get_error_message() : $error;
			}
		} elseif ( 'purge' === $op ) {
			$error = __( 'Tick the confirmation box to delete for good.', 'fluffless-suite' );
		}
		$args = array(
			'done'  => $done,
			'bytes' => $bytes,
			'op'    => $op,
		);
		if ( '' !== $error ) {
			// Le message passe par un transitoire de la personne, pas par l'adresse : aucun texte injectable par lien.
			set_transient( self::ACTION . '_error_' . get_current_user_id(), $error, MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( $args, self::url() ) );
		exit;
	}

	/**
	 * Vignette ou, pour un fichier en quarantaine, rien (il n'est plus servi).
	 *
	 * @param int $id Média.
	 */
	private static function thumbnail( int $id ): string {
		$image = wp_get_attachment_image( $id, array( 60, 60 ), false, array( 'loading' => 'lazy' ) );
		return '' !== $image ? $image : '';
	}

	/**
	 * Une ligne à cocher.
	 *
	 * @param string               $name      Nom du champ.
	 * @param array<string, mixed> $candidate Image proposée.
	 * @phpstan-param Candidate $candidate
	 */
	private static function row( string $name, array $candidate ): void {
		$field = $name . '-' . $candidate['id'];
		printf(
			'<tr><th scope="row" class="check-column"><input type="checkbox" name="%1$s[]" value="%2$d" id="%3$s" data-bytes="%4$d"%5$s></th><td>%6$s</td><td><label for="%3$s">%7$s</label></td><td>%8$s</td><td>%9$s</td></tr>',
			esc_attr( $name ),
			(int) $candidate['id'],
			esc_attr( $field ),
			(int) $candidate['bytes'],
			checked( $candidate['sure'], true, false ),
			self::thumbnail( $candidate['id'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() échappe ses attributs.
			esc_html( '' !== $candidate['title'] ? $candidate['title'] : '#' . $candidate['id'] ),
			esc_html( MediaWebp::size( $candidate['bytes'] ) ),
			esc_html( $candidate['reason'] )
		);
	}

	/** Écran : images proposées, quarantaine, purge. */
	public static function render(): void {
		if ( ! self::can_act() ) {
			return;
		}
		wp_enqueue_script( 'fluffless-quarantine', plugins_url( 'assets/media-webp/quarantine.js', FILE ), array( 'wp-i18n' ), VERSION, true );
		wp_set_script_translations( 'fluffless-quarantine', 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- affichage du résultat d'une action déjà vérifiée.
		$scan   = isset( $_GET['scan'] );
		$done   = absint( $_GET['done'] ?? 0 );
		$bytes  = absint( $_GET['bytes'] ?? 0 );
		$has_op = isset( $_GET['op'] );
		// phpcs:enable
		$stored = get_transient( self::ACTION . '_error_' . get_current_user_id() );
		$error  = is_string( $stored ) ? $stored : '';
		delete_transient( self::ACTION . '_error_' . get_current_user_id() );

		echo '<div class="wrap"><h1>' . esc_html__( 'Unused images', 'fluffless-suite' ) . '</h1>';
		echo '<p>' . esc_html__( 'Moving an image to quarantine hides it and moves its files to a protected folder. You can restore it in one click. Nothing is deleted until you empty the quarantine.', 'fluffless-suite' ) . '</p>';
		if ( $has_op ) {
			/* translators: 1: number of images; 2: weight, e.g. 2.4 MB */
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( 'Done: %1$s image(s), %2$s.', 'fluffless-suite' ), number_format_i18n( $done ), MediaWebp::size( $bytes ) ) ) . '</p></div>';
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		if ( ! $scan ) {
			echo '<p><a class="button" href="' . esc_url( add_query_arg( 'scan', '1', self::url() ) ) . '">' . esc_html__( 'Look for unused images', 'fluffless-suite' ) . '</a></p>';
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of images examined */
					__( 'Examines the %d oldest images. Logo, icon, featured images and images found in any content, draft or revision are never proposed.', 'fluffless-suite' ),
					self::SCAN_LIMIT
				)
			) . '</p>';
		} else {
			$found = self::scan();
			$head  = '<table class="widefat striped"><thead><tr><td class="check-column"></td><th scope="col">' . esc_html__( 'Preview', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Image', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Weight', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Why it is proposed', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
			echo '<h2>' . esc_html__( 'Images not found anywhere', 'fluffless-suite' ) . '</h2>';
			if ( array() === $found['unused'] ) {
				echo '<p>' . esc_html__( 'None: every image examined is used.', 'fluffless-suite' ) . '</p>';
			} else {
				echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- construit ci-dessus avec esc_html.
				foreach ( $found['unused'] as $candidate ) {
					self::row( 'ids', $candidate );
				}
				echo '</tbody></table><p class="description">' . esc_html__( 'Lines marked “To check” are unticked: look before moving them.', 'fluffless-suite' ) . '</p>';
			}
			echo '<h2>' . esc_html__( 'Originals of converted images', 'fluffless-suite' ) . '</h2>';
			if ( array() === $found['originals'] ) {
				echo '<p>' . esc_html__( 'None.', 'fluffless-suite' ) . '</p>';
			} else {
				echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- construit ci-dessus avec esc_html.
				foreach ( $found['originals'] as $candidate ) {
					self::row( 'originals', $candidate );
				}
				echo '</tbody></table>';
			}
			if ( array() !== $found['unused'] || array() !== $found['originals'] ) {
				echo '<p>' . esc_html__( 'Space freed by the ticked images:', 'fluffless-suite' ) . ' <output id="fluffless-quarantine-total" data-unit="' . esc_attr( MediaWebp::size( 1000 ) ) . '">-</output></p>';
				echo '<p><button type="submit" class="button button-primary" name="op" value="quarantine">' . esc_html__( 'Move to quarantine', 'fluffless-suite' ) . '</button></p>';
			}
		}

		$items = self::items();
		echo '<h2>' . esc_html__( 'In quarantine', 'fluffless-suite' ) . '</h2>';
		if ( array() === $items ) {
			echo '<p>' . esc_html__( 'The quarantine is empty.', 'fluffless-suite' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><td class="check-column"></td><th scope="col">' . esc_html__( 'Image', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Weight', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Since', 'fluffless-suite' ) . '</th><th scope="col">' . esc_html__( 'Restore', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
			foreach ( $items as $item ) {
				$value = ( 'originals' === $item['kind'] ? 'originals' : 'attachment' ) . ':' . $item['id'];
				$title = ( '' !== $item['title'] ? $item['title'] : '#' . $item['id'] ) . ( 'originals' === $item['kind'] ? ' ' . __( '(original files)', 'fluffless-suite' ) : '' );
				$days  = (int) floor( ( time() - $item['time'] ) / DAY_IN_SECONDS );
				printf(
					'<tr><th scope="row" class="check-column"><input type="checkbox" name="purge[]" value="%1$s" id="purge-%2$s" data-bytes="%3$d" checked></th><td><label for="purge-%2$s">%4$s</label></td><td>%5$s</td><td>%6$s</td><td><button type="submit" class="button" name="restore" value="%1$s">%7$s</button></td></tr>',
					esc_attr( $value ),
					esc_attr( str_replace( ':', '-', $value ) ),
					(int) $item['bytes'],
					esc_html( $title ),
					esc_html( MediaWebp::size( $item['bytes'] ) ),
					/* translators: %d: number of days */
					esc_html( sprintf( _n( '%d day', '%d days', $days, 'fluffless-suite' ), $days ) ),
					esc_html__( 'Restore', 'fluffless-suite' )
				);
			}
			echo '</tbody></table>';
			echo '<p><label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'I understand that the ticked images will be deleted for good.', 'fluffless-suite' ) . '</label></p>';
			echo '<p><button type="submit" class="button button-link-delete" name="op" value="purge" data-confirm="' . esc_attr__( 'Delete the ticked images for good? This cannot be undone.', 'fluffless-suite' ) . '">' . esc_html__( 'Empty the quarantine', 'fluffless-suite' ) . '</button></p>';
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of days */
					__( 'Nothing is ever deleted automatically. Wait at least %d days before emptying, to be sure no page misses an image.', 'fluffless-suite' ),
					self::RESTORE_DAYS
				)
			) . '</p>';
		}
		echo '</form></div>';
	}

	/**
	 * Lit les identifiants passés à WP-CLI.
	 *
	 * @param string[] $args Arguments positionnels.
	 * @return list<int>
	 */
	private static function cli_ids( array $args ): array {
		return array_values( array_filter( array_map( 'absint', $args ) ) );
	}

	/**
	 * Met des images en quarantaine. Simulation par défaut : ajouter --no-dry-run pour agir.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Médias à mettre en quarantaine. Sans identifiant : les images sûrement inutilisées (jamais « à vérifier »).
	 *
	 * [--originals]
	 * : Mettre en quarantaine les originaux JPEG/PNG des images converties en WebP.
	 *
	 * [--dry-run]
	 * : Simulation (par défaut). --no-dry-run pour agir.
	 *
	 * @param string[]              $args       Identifiants.
	 * @param array<string, string> $assoc_args Options.
	 */
	public static function cli_quarantine( array $args, array $assoc_args ): void {
		$dry       = (bool) Cli::flag( $assoc_args, 'dry-run', true );
		$originals = (bool) Cli::flag( $assoc_args, 'originals', false );
		$ids       = self::cli_ids( $args );
		if ( array() === $ids ) {
			$found = self::scan();
			$list  = $originals ? $found['originals'] : array_filter( $found['unused'], static fn( array $c ): bool => $c['sure'] );
			$ids   = array_column( $list, 'id' );
		}
		foreach ( $ids as $id ) {
			if ( $dry ) {
				Cli::log( sprintf( '[dry-run] #%d %s', $id, get_the_title( $id ) ) );
				continue;
			}
			$result = $originals ? self::quarantine_originals( $id ) : self::quarantine_attachment( $id );
			Cli::log( sprintf( '#%d %s', $id, true === $result ? 'quarantined' : $result->get_error_message() ) );
		}
		Cli::success( sprintf( $dry ? '%d image(s) would be moved to quarantine. Add --no-dry-run to do it.' : '%d image(s) processed.', count( $ids ) ) );
	}

	/**
	 * Restaure des images. Simulation par défaut : ajouter --no-dry-run pour agir.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Médias à restaurer.
	 *
	 * [--originals]
	 * : Restaurer les originaux convertis plutôt que le média.
	 *
	 * [--dry-run]
	 * : Simulation (par défaut). --no-dry-run pour agir.
	 *
	 * @param string[]              $args       Identifiants.
	 * @param array<string, string> $assoc_args Options.
	 */
	public static function cli_restore( array $args, array $assoc_args ): void {
		$dry       = (bool) Cli::flag( $assoc_args, 'dry-run', true );
		$originals = (bool) Cli::flag( $assoc_args, 'originals', false );
		foreach ( self::cli_ids( $args ) as $id ) {
			if ( $dry ) {
				Cli::log( sprintf( '[dry-run] #%d would be restored.', $id ) );
				continue;
			}
			$result = self::restore( $id, $originals );
			Cli::log( sprintf( '#%d %s', $id, true === $result ? 'restored' : $result->get_error_message() ) );
		}
		Cli::success( $dry ? 'Dry run. Add --no-dry-run to restore.' : 'Done.' );
	}

	/**
	 * Supprime définitivement des éléments en quarantaine. Simulation par défaut : ajouter --no-dry-run pour agir.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Médias à supprimer. Sans identifiant : toute la quarantaine.
	 *
	 * [--dry-run]
	 * : Simulation (par défaut). --no-dry-run pour supprimer pour de bon.
	 *
	 * @param string[]              $args       Identifiants.
	 * @param array<string, string> $assoc_args Options.
	 */
	public static function cli_purge( array $args, array $assoc_args ): void {
		$dry   = (bool) Cli::flag( $assoc_args, 'dry-run', true );
		$ids   = self::cli_ids( $args );
		$bytes = 0;
		foreach ( self::items() as $item ) {
			if ( array() !== $ids && ! in_array( $item['id'], $ids, true ) ) {
				continue;
			}
			if ( $dry ) {
				Cli::log( sprintf( '[dry-run] #%d %s (%s)', $item['id'], $item['title'], MediaWebp::size( $item['bytes'] ) ) );
				continue;
			}
			$result = self::purge( $item['id'], 'originals' === $item['kind'] );
			$bytes += is_int( $result ) ? $result : 0;
			Cli::log( sprintf( '#%d %s', $item['id'], is_int( $result ) ? 'deleted' : $result->get_error_message() ) );
		}
		Cli::success( $dry ? 'Dry run: nothing deleted. Add --no-dry-run to delete for good.' : sprintf( '%s freed.', MediaWebp::size( $bytes ) ) );
	}
}
