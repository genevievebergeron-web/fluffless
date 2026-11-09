<?php
/**
 * Module Images : formats légers, tailles inutiles non générées, seuils de poids à l'envoi.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Seules les tailles du cœur de WordPress peuvent être retirées : celles que le thème
 * ou une extension enregistrent sont toujours gardées.
 */
final class Images implements Module {

	/** Formats convertis. Le GIF est laissé tel quel (animation). */
	private const CONVERTED = array( 'image/jpeg', 'image/png' );

	/** Au-delà de ce poids, une vidéo hébergée ici est à corriger dans le Bilan de sobriété. */
	public const VIDEO_HEAVY_MB = 100;

	/** Plus grand côté d'une vidéo avant d'être signalée (1080p). */
	public const VIDEO_MAX_EDGE = 1920;

	/** Vidéos relues au plus dans la médiathèque. */
	public const VIDEO_LIMIT = 200;

	/** Images relues au plus dans la médiathèque par le Bilan, les plus récentes d'abord. */
	public const IMAGE_LIMIT = 20000;

	/** Images lues par requête. */
	public const IMAGE_BATCH = 500;

	/** Messages gardés par personne, et personnes gardées, en attente d'affichage. */
	private const NOTICES_PER_USER = 5;
	private const NOTICE_USERS     = 50;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'images';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Images', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Lighter files for every image you upload, and a warning before a heavy one reaches your pages.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'output_format' => 'webp',
			'remove_sizes'  => array( 'medium_large', '1536x1536', '2048x2048' ),
			'warn_kb'       => 400,
			'max_kb'        => 2048,
			'oversize'      => 'shrink',
			'video_warn_mb' => 20,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		$clean = Sanitize::section( $input, self::fields(), self::defaults() );
		// Un avertissement au-delà du refus n'aurait jamais lieu.
		if ( $clean['warn_kb'] > $clean['max_kb'] ) {
			$clean['warn_kb'] = $clean['max_kb'];
		}
		return $clean;
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array(
			'output_format' => array(
				'type'    => 'choice',
				'label'   => __( 'Format of resized images', 'fluffless-suite' ),
				'help'    => __( 'Applies to JPEG and PNG images uploaded from now on. The original file is kept.', 'fluffless-suite' ),
				'options' => array(
					'webp'     => __( 'WebP (recommended)', 'fluffless-suite' ),
					'avif'     => __( 'AVIF, smaller, if the server supports it (otherwise WebP)', 'fluffless-suite' ),
					'original' => __( 'Keep the original format', 'fluffless-suite' ),
				),
			),
			'remove_sizes'  => array(
				'type'    => 'multi',
				'label'   => __( 'Sizes not to generate', 'fluffless-suite' ),
				'help'    => __( 'Each size is one more file on the server for every upload. Sizes added by your theme or plugins are always kept.', 'fluffless-suite' ),
				'options' => array(
					'thumbnail'    => __( 'Thumbnail', 'fluffless-suite' ),
					'medium'       => __( 'Medium', 'fluffless-suite' ),
					'medium_large' => __( 'Medium large (768 px wide, srcset only)', 'fluffless-suite' ),
					'large'        => __( 'Large', 'fluffless-suite' ),
					'1536x1536'    => __( '1536 px (high-density screens)', 'fluffless-suite' ),
					'2048x2048'    => __( '2048 px (high-density screens)', 'fluffless-suite' ),
				),
			),
			'warn_kb'       => array(
				'type'  => 'int',
				'label' => __( 'Warn above (KB)', 'fluffless-suite' ),
				'min'   => 50,
				'max'   => 51200,
			),
			'max_kb'        => array(
				'type'  => 'int',
				'label' => __( 'Heavy image limit (KB)', 'fluffless-suite' ),
				'min'   => 50,
				'max'   => 51200,
			),
			'oversize'      => array(
				'type'    => 'choice',
				'label'   => __( 'Above the limit', 'fluffless-suite' ),
				'options' => array(
					'shrink' => __( 'Reduce the image automatically (recommended)', 'fluffless-suite' ),
					'refuse' => __( 'Refuse the upload', 'fluffless-suite' ),
				),
			),
			'video_warn_mb' => array(
				'type'  => 'int',
				'label' => __( 'Warn for a video above (MB)', 'fluffless-suite' ),
				'min'   => 1,
				'max'   => 4096,
			),
		);
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_filter( 'image_editor_output_format', array( self::class, 'output_format' ) );
		add_filter( 'intermediate_image_sizes_advanced', array( self::class, 'remove_sizes' ) );
		add_filter( 'wp_handle_upload_prefilter', array( self::class, 'check_upload' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( self::class, 'check_upload' ) );
		add_action( 'admin_notices', array( self::class, 'print_notices' ) );
	}

	/**
	 * Type MIME de sortie retenu, ou null pour garder le format d'origine.
	 * AVIF seulement si l'éditeur d'image du serveur sait l'écrire, sinon WebP.
	 */
	public static function target_mime(): ?string {
		$format = Options::get_string( self::id(), 'output_format' );
		if ( 'avif' === $format && wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			return 'image/avif';
		}
		if ( in_array( $format, array( 'webp', 'avif' ), true ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return 'image/webp';
		}
		return null;
	}

	/**
	 * Correspondances de sortie pour image_editor_output_format.
	 *
	 * @param mixed $formats Type d'entrée => type de sortie.
	 * @return mixed
	 */
	public static function output_format( $formats ) {
		$target = self::target_mime();
		if ( null === $target || ! is_array( $formats ) ) {
			return $formats;
		}
		foreach ( self::CONVERTED as $mime ) {
			$formats[ $mime ] = $target;
		}
		return $formats;
	}

	/**
	 * Retire les tailles du cœur choisies ; les autres restent.
	 *
	 * @param mixed $sizes Nom => dimensions.
	 * @return mixed
	 */
	public static function remove_sizes( $sizes ) {
		if ( ! is_array( $sizes ) ) {
			return $sizes;
		}
		return array_diff_key( $sizes, array_flip( Options::get_list( self::id(), 'remove_sizes' ) ) );
	}

	/**
	 * Au-delà du plafond : réduit l'image (par défaut) ou la refuse. Au-delà du seuil d'alerte,
	 * garde un message pour la personne. Une photo de cellulaire n'est jamais refusée d'office.
	 *
	 * @param mixed $file Tableau $_FILES d'un fichier.
	 * @return mixed
	 */
	public static function check_upload( $file ) {
		if ( ! is_array( $file ) ) {
			return $file;
		}
		if ( self::is_video( $file ) ) {
			return self::check_video( $file );
		}
		if ( ! self::is_image( $file ) ) {
			return $file;
		}
		$size = self::size_of( $file );
		if ( null === $size ) {
			return $file;
		}
		$max  = Options::get_int( self::id(), 'max_kb' ) * KB_IN_BYTES;
		$warn = Options::get_int( self::id(), 'warn_kb' ) * KB_IN_BYTES;

		if ( $max > 0 && $size > $max && 'refuse' !== Options::get_string( self::id(), 'oversize' ) ) {
			$reduced = self::shrink( $file );
			if ( null !== $reduced && $reduced < $size ) {
				$file['size'] = $reduced;
				self::queue_notice(
					sprintf(
						/* translators: 1: original size, e.g. 4.2 MB; 2: new size, e.g. 380 KB */
						__( 'This image weighed %1$s. It was reduced to %2$s so your pages load fast.', 'fluffless-suite' ),
						self::format_size( $size ),
						self::format_size( $reduced )
					)
				);
			} else {
				self::queue_notice(
					sprintf(
						/* translators: 1: file size, e.g. 4.2 MB; 2: limit, e.g. 2 MB */
						__( 'This image weighs %1$s, over the %2$s limit, and this server could not reduce it. It was kept as is; a smaller version will make your pages faster.', 'fluffless-suite' ),
						self::format_size( $size ),
						self::format_size( $max )
					)
				);
			}
		} elseif ( $max > 0 && $size > $max ) {
			$file['error'] = sprintf(
				/* translators: 1: file size, e.g. 2.4 MB; 2: limit, e.g. 2 MB */
				__( 'This image weighs %1$s, over the %2$s limit set in Fluffless → Modules. Resize or compress it, then upload it again.', 'fluffless-suite' ),
				self::format_size( $size ),
				self::format_size( $max )
			);
		} elseif ( $warn > 0 && $size > $warn ) {
			self::queue_notice(
				sprintf(
					/* translators: 1: file size, e.g. 640 KB; 2: suggested maximum, e.g. 400 KB */
					__( 'This image weighs %1$s. Want to trim it? Aim for under %2$s.', 'fluffless-suite' ),
					self::format_size( $size ),
					self::format_size( $warn )
				)
			);
		}
		return $file;
	}

	/**
	 * Vidéo lourde : jamais refusée ni réécrite (la Suite n'encode pas), mais au-dessus du seuil
	 * la personne reçoit la recette pour l'encoder sur son ordinateur avant de la remettre.
	 *
	 * @param array<mixed> $file Tableau $_FILES d'un fichier.
	 * @return array<mixed>
	 */
	private static function check_video( array $file ): array {
		$size = self::size_of( $file );
		$warn = self::video_warn_bytes();
		if ( null !== $size && $size > $warn ) {
			self::queue_notice(
				sprintf(
					/* translators: 1: file size, e.g. 30 MB; 2: threshold, e.g. 20 MB */
					__( 'This video weighs %1$s, above %2$s: every visitor who plays it downloads that much.', 'fluffless-suite' ),
					self::format_size( $size ),
					self::format_size( $warn )
				) . ' ' . self::video_recipe()
			);
		}
		return $file;
	}

	/**
	 * Seuil au-delà duquel une vidéo est signalée, en octets (réglage du module, 20 Mo à défaut).
	 */
	public static function video_warn_bytes(): int {
		$mb = Options::get_int( self::id(), 'video_warn_mb' );
		return ( $mb > 0 ? $mb : 20 ) * MB_IN_BYTES;
	}

	/** Recette d'encodage sur l'ordinateur, la même au téléversement et dans le Bilan. */
	public static function video_recipe(): string {
		return __( 'Encode it on your computer before uploading, with HandBrake (free): H.264 or AV1, 1080p at most, 2 to 4 Mbit/s, audio at 128 kbit/s. Then add a poster image in the Video block: nothing downloads before the click.', 'fluffless-suite' );
	}

	/**
	 * Vidéo selon le type déclaré, sinon selon le contenu réel.
	 *
	 * @param array<mixed> $file Tableau $_FILES d'un fichier.
	 */
	private static function is_video( array $file ): bool {
		$declared = is_string( $file['type'] ?? null ) ? $file['type'] : '';
		if ( str_starts_with( $declared, 'video/' ) ) {
			return true;
		}
		$tmp  = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$name = is_string( $file['name'] ?? null ) ? $file['name'] : '';
		if ( '' === $tmp || '' === $name || ! is_readable( $tmp ) ) {
			return false;
		}
		$checked = wp_check_filetype_and_ext( $tmp, $name );
		return is_string( $checked['type'] ) && str_starts_with( $checked['type'], 'video/' );
	}

	/**
	 * Vidéos hébergées dans la médiathèque : poids lu dans les métadonnées de WordPress (posées
	 * au téléversement), sinon sur le disque ; dimensions quand WordPress les a lues, 0 sinon.
	 *
	 * @return list<array{id: int, title: string, bytes: int, width: int, height: int}>
	 */
	public static function video_library(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'video',
				'posts_per_page' => self::VIDEO_LIMIT,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$meta  = wp_get_attachment_metadata( $id );
			$meta  = is_array( $meta ) ? $meta : array();
			$bytes = is_int( $meta['filesize'] ?? null ) ? $meta['filesize'] : 0;
			if ( $bytes <= 0 ) {
				$path  = get_attached_file( $id );
				$size  = is_string( $path ) && is_readable( $path ) ? filesize( $path ) : false;
				$bytes = false === $size ? 0 : $size;
			}
			$out[] = array(
				'id'     => $id,
				'title'  => get_the_title( $id ),
				'bytes'  => $bytes,
				'width'  => is_int( $meta['width'] ?? null ) ? $meta['width'] : 0,
				'height' => is_int( $meta['height'] ?? null ) ? $meta['height'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Images de la médiathèque, les plus récentes d'abord : poids et dimensions du fichier que
	 * WordPress sert en taille « complète » (la copie réduite au-delà de 2560 px, l'original gardé
	 * à côté n'étant jamais servi). Poids lu dans les métadonnées, sinon sur le disque. Une image de
	 * plus que IMAGE_LIMIT au plus : sa présence dit que la médiathèque n'a pas été lue en entier.
	 *
	 * @return list<array{id: int, title: string, bytes: int, width: int, height: int}>
	 */
	public static function image_library(): array {
		global $wpdb;
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( (string) $uploads['basedir'] );
		$out     = array();
		$before  = PHP_INT_MAX;
		$read    = 0;
		// Par lots paginés sur l'identifiant (jamais d'OFFSET), en lisant directement les deux métadonnées
		// utiles : aucun article chargé, aucun cache rempli.
		while ( $read <= self::IMAGE_LIMIT ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture ponctuelle du Bilan, lancée à la main.
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, m.meta_value AS meta, f.meta_value AS file
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
					LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
					WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE %s AND p.ID < %d
					ORDER BY p.ID DESC LIMIT %d",
					$wpdb->esc_like( 'image/' ) . '%',
					$before,
					self::IMAGE_BATCH
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$before = (int) $row['ID'];
				$meta   = maybe_unserialize( (string) $row['meta'] );
				$meta   = is_array( $meta ) ? $meta : array();
				$bytes  = is_int( $meta['filesize'] ?? null ) ? $meta['filesize'] : 0;
				$file   = (string) $row['file'];
				if ( $bytes <= 0 && '' !== $file ) {
					$path  = path_is_absolute( $file ) ? $file : $base . $file;
					$size  = is_readable( $path ) ? filesize( $path ) : false;
					$bytes = false === $size ? 0 : $size;
				}
				$out[] = array(
					'id'     => (int) $row['ID'],
					'title'  => '' === (string) $row['post_title'] ? wp_basename( $file ) : (string) $row['post_title'],
					'bytes'  => $bytes,
					'width'  => is_int( $meta['width'] ?? null ) ? $meta['width'] : 0,
					'height' => is_int( $meta['height'] ?? null ) ? $meta['height'] : 0,
				);
			}
			$read = count( $out );
			if ( count( $rows ) < self::IMAGE_BATCH ) {
				break;
			}
		}
		return array_slice( $out, 0, self::IMAGE_LIMIT + 1 );
	}

	/** Seuil d'alerte des images, en octets (réglage du module, 400 ko à défaut). */
	public static function image_warn_bytes(): int {
		$kb = Options::get_int( self::id(), 'warn_kb' );
		return ( $kb > 0 ? $kb : 400 ) * KB_IN_BYTES;
	}

	/** Plafond des images, en octets (réglage du module, 2 Mo à défaut). */
	public static function image_max_bytes(): int {
		$kb = Options::get_int( self::id(), 'max_kb' );
		return ( $kb > 0 ? $kb : 2048 ) * KB_IN_BYTES;
	}

	/** Bord au-delà duquel WordPress réduit une image téléversée (2560 px, filtre du cœur). */
	public static function max_edge(): int {
		$edge = (int) apply_filters( 'big_image_size_threshold', 2560, array( 0, 0 ), '', 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur, même seuil que WordPress.
		return $edge > 0 ? $edge : 2560;
	}

	/**
	 * Réduit l'image en place : redressée, ramenée au bord maximal de WordPress (2560 px), qualité 82,
	 * dans son format d'origine (le format de sortie du module vaut pour les tailles, pas ici).
	 * Rend le nouveau poids, ou null si le serveur ne sait pas la réécrire.
	 *
	 * @param array<mixed> $file Tableau $_FILES d'un fichier.
	 */
	private static function shrink( array $file ): ?int {
		$path = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}
		$type = wp_check_filetype_and_ext( $path, is_string( $file['name'] ?? null ) ? $file['name'] : '' );
		$mime = is_string( $type['type'] ) ? $type['type'] : '';
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true ) ) {
			return null;
		}
		// Copie avec la bonne extension : Imagick devine le format par le nom (/tmp/phpA1b2 ou .tmp échouent).
		$slot = wp_tempnam( 'fluffless-shrink' );
		$work = $slot . '.' . $type['ext'];
		wp_delete_file( $slot );
		if ( ! copy( $path, $work ) ) {
			return null;
		}
		$editor = wp_get_image_editor( $work );
		if ( is_wp_error( $editor ) ) {
			wp_delete_file( $work );
			return null;
		}
		$editor->maybe_exif_rotate();
		$dims = $editor->get_size();
		$edge = (int) apply_filters( 'big_image_size_threshold', 2560, array( $dims['width'], $dims['height'] ), $path, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur, même seuil que WordPress.
		$edge = $edge > 0 ? $edge : 2560;
		if ( max( $dims['width'], $dims['height'] ) > $edge ) {
			$editor->resize( $edge, $edge );
		}
		$editor->set_quality( 82 );
		// Même format que le fichier envoyé : son nom et son contenu doivent concorder.
		remove_filter( 'image_editor_output_format', array( self::class, 'output_format' ) );
		$saved = $editor->save( $work, $mime );
		add_filter( 'image_editor_output_format', array( self::class, 'output_format' ) );
		if ( is_wp_error( $saved ) || $saved['mime-type'] !== $mime || ! is_readable( $saved['path'] ) ) {
			wp_delete_file( $work );
			return null;
		}
		$size = (int) filesize( $saved['path'] );
		// Copie sur le fichier temporaire (pas rename) : move_uploaded_file le reconnaît toujours.
		$kept = $size > 0 && $size < (int) filesize( $path ) && copy( $saved['path'], $path );
		wp_delete_file( $saved['path'] );
		wp_delete_file( $work );
		clearstatcache( true, $path );
		return $kept ? $size : null;
	}

	/**
	 * Image selon son contenu réel (wp_check_filetype_and_ext), pas seulement le type déclaré :
	 * un type générique (application/octet-stream) ne fait plus passer une photo sous le seuil.
	 *
	 * @param array<mixed> $file Tableau $_FILES d'un fichier.
	 */
	private static function is_image( array $file ): bool {
		$declared = is_string( $file['type'] ?? null ) ? $file['type'] : '';
		if ( str_starts_with( $declared, 'image/' ) ) {
			return true;
		}
		$tmp  = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$name = is_string( $file['name'] ?? null ) ? $file['name'] : '';
		if ( '' === $tmp || '' === $name || ! is_readable( $tmp ) ) {
			return false;
		}
		$checked = wp_check_filetype_and_ext( $tmp, $name );
		return is_string( $checked['type'] ) && str_starts_with( $checked['type'], 'image/' );
	}

	/**
	 * Poids du fichier : « size » de l'envoi, sinon lu sur le disque (import, WP-CLI, media_sideload_image).
	 *
	 * @param array<mixed> $file Tableau $_FILES d'un fichier.
	 */
	private static function size_of( array $file ): ?int {
		if ( is_int( $file['size'] ?? null ) && $file['size'] > 0 ) {
			return $file['size'];
		}
		$tmp  = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$size = '' !== $tmp && is_readable( $tmp ) ? filesize( $tmp ) : false;
		return false === $size ? null : $size;
	}

	/**
	 * Poids lisible (2,4 Mo en fr_CA, 2.4 MB en en_CA).
	 *
	 * @param int $bytes Octets.
	 */
	private static function format_size( int $bytes ): string {
		$formatted = size_format( $bytes, 1 );
		return is_string( $formatted ) ? $formatted : (string) $bytes;
	}

	/**
	 * Garde un message pour la personne connectée, affiché au prochain écran d'administration.
	 *
	 * @param string $message Message déjà traduit.
	 */
	private static function queue_notice( string $message ): void {
		$user = get_current_user_id();
		if ( 0 === $user ) {
			return;
		}
		$all          = self::notices();
		$mine         = $all[ $user ] ?? array();
		$mine[]       = $message;
		$all[ $user ] = array_slice( $mine, -self::NOTICES_PER_USER );
		set_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES, array_slice( $all, -self::NOTICE_USERS, null, true ), DAY_IN_SECONDS );
	}

	/**
	 * Messages en attente, par personne.
	 *
	 * @return array<int, list<string>>
	 */
	public static function notices(): array {
		$stored = get_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES );
		$clean  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $user => $messages ) {
			if ( is_int( $user ) && is_array( $messages ) ) {
				$clean[ $user ] = array_values( array_filter( $messages, 'is_string' ) );
			}
		}
		return $clean;
	}

	/** Affiche puis oublie les messages de la personne connectée. */
	public static function print_notices(): void {
		$user = get_current_user_id();
		$all  = self::notices();
		if ( empty( $all[ $user ] ) ) {
			return;
		}
		foreach ( $all[ $user ] as $message ) {
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
		unset( $all[ $user ] );
		set_transient( Lifecycle::TRANSIENT_UPLOAD_NOTICES, $all, DAY_IN_SECONDS );
	}
}
