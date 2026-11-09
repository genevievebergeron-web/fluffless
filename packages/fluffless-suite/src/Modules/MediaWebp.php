<?php
/**
 * Module Médiathèque WebP : conversion des images déjà téléversées, sur clic, par petits lots.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;
use Fluffless\Suite\Modules\Media\Cli;
use Fluffless\Suite\Modules\Media\Quarantine;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Pour chaque image JPEG ou PNG :
 * 1. le fichier d'origine (avant réduction « -scaled ») est réenregistré en WebP ou AVIF ;
 * 2. le média pointe vers le nouveau fichier, ses tailles et métadonnées sont régénérées ;
 * 3. si le nouveau format n'est pas plus léger, tout est remis comme avant (statut « skipped ») ;
 * 4. les adresses des anciens fichiers sont remplacées dans les contenus (révisions exceptées) ;
 * 5. les anciens fichiers restent sur le disque, sauf si l'option les envoie en quarantaine.
 *
 * Aucun traitement de masse sans clic : lots lancés par l'écran (REST, nonce, upload_files) ou par WP-CLI.
 *
 * @phpstan-type Result array{status: 'converted'|'skipped'|'error', saved: int, message: string}
 */
final class MediaWebp implements Module {

	public const META    = '_fluffless_webp';
	public const REPORT  = Lifecycle::OPTION_WEBP_REPORT;
	public const PAGE    = 'fluffless-webp';
	public const REST_NS = 'fluffless/v1';
	public const HANDLE  = 'fluffless-webp-admin';

	/** Formats convertis ; le GIF reste tel quel (animation). */
	public const SOURCE_MIMES = array( 'image/jpeg', 'image/png' );

	/** Bornes : images par lot, durée d'un lot, contenus réécrits par adresse. */
	public const BATCH              = 5;
	public const MAX_BATCH          = 20;
	private const TIME_BUDGET       = 20.0;
	private const MAX_REWRITE_POSTS = 500;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'media_webp';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'WebP media library', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Converts the images already in your media library to WebP (or AVIF), in small batches, when you click. Your pages then use the lighter files.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'format'               => 'webp',
			'quarantine_originals' => false,
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
			'format'               => array(
				'type'    => 'choice',
				'label'   => __( 'Target format', 'fluffless-suite' ),
				'options' => array(
					'webp' => __( 'WebP (recommended)', 'fluffless-suite' ),
					'avif' => __( 'AVIF, smaller, if the server supports it (otherwise WebP)', 'fluffless-suite' ),
				),
			),
			'quarantine_originals' => array(
				'type'  => 'bool',
				'label' => __( 'Move originals to quarantine after conversion', 'fluffless-suite' ),
				'help'  => __( 'Off by default: originals stay on the server. When on, each JPEG or PNG goes to quarantine once its WebP is checked. Links to the old file from outside your content (emails, other sites, custom fields) will break. You can restore it, and nothing is deleted until you empty the quarantine.', 'fluffless-suite' ),
			),
		);
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		Quarantine::register();
		if ( is_admin() ) {
			add_action( 'admin_menu', array( self::class, 'add_page' ) );
		}
		Cli::add( 'fluffless webp', array( self::class, 'cli' ) );
	}

	/** Type MIME visé, ou null si le serveur ne sait écrire ni AVIF ni WebP. */
	public static function target_mime(): ?string {
		if ( 'avif' === Options::get_string( self::id(), 'format' ) && wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			return 'image/avif';
		}
		return wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'image/webp' : null;
	}

	/**
	 * Images en attente de conversion, les plus anciennes d'abord.
	 *
	 * @param int  $limit    Nombre maximal.
	 * @param bool $restrict Limiter aux médias de la personne si elle ne peut pas modifier ceux des autres.
	 * @return list<int>
	 */
	public static function candidates( int $limit, bool $restrict = true ): array {
		$args = self::queue_args() + array(
			'numberposts'   => max( 1, $limit ),
			'fields'        => 'ids',
			'no_found_rows' => true,
			'orderby'       => 'ID',
			'order'         => 'ASC',
		);
		if ( $restrict && ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}
		return array_values( array_map( static fn( $post ): int => $post instanceof \WP_Post ? $post->ID : (int) $post, get_posts( $args ) ) );
	}

	/**
	 * Requête de la file : JPEG et PNG jamais traités.
	 *
	 * @return array<string, mixed>
	 */
	private static function queue_args(): array {
		return array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::SOURCE_MIMES,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- file d'attente lue sur clic seulement.
			'meta_query'     => array(
				array(
					'key'     => self::META,
					'compare' => 'NOT EXISTS',
				),
			),
		);
	}

	/**
	 * Bilan : images en attente, converties, gardées, en erreur, octets économisés.
	 *
	 * @return array{remaining: int, converted: int, skipped: int, errors: int, saved: int}
	 */
	public static function stats(): array {
		$query  = new \WP_Query(
			self::queue_args() + array(
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$report = self::report();
		return array( 'remaining' => (int) $query->found_posts ) + $report;
	}

	/**
	 * Totaux cumulés (option non chargée automatiquement).
	 *
	 * @return array{converted: int, skipped: int, errors: int, saved: int}
	 */
	public static function report(): array {
		$stored = get_option( self::REPORT, array() );
		$clean  = array();
		foreach ( array( 'converted', 'skipped', 'errors', 'saved' ) as $key ) {
			$clean[ $key ] = is_array( $stored ) && is_int( $stored[ $key ] ?? null ) ? $stored[ $key ] : 0;
		}
		return $clean;
	}

	/**
	 * Ajoute un résultat aux totaux.
	 *
	 * @param array<string, int|string> $result Résultat d'une conversion.
	 * @phpstan-param Result $result
	 */
	private static function add_to_report( array $result ): void {
		$report = self::report();
		$key    = 'converted' === $result['status'] ? 'converted' : ( 'skipped' === $result['status'] ? 'skipped' : 'errors' );
		++$report[ $key ];
		$report['saved'] += $result['saved'];
		update_option( self::REPORT, $report, false );
	}

	/**
	 * Convertit une image. Toute sortie laisse le média dans un état cohérent.
	 *
	 * @param int $id Média.
	 * @return Result
	 */
	public static function convert( int $id ): array {
		$result = self::convert_unrecorded( $id );
		if ( 'converted' !== $result['status'] ) {
			// Marque l'image pour qu'elle quitte la file : un lot ne bute jamais deux fois sur la même.
			update_post_meta(
				$id,
				self::META,
				array(
					'status'  => $result['status'],
					'message' => $result['message'],
					'time'    => time(),
				)
			);
		}
		self::add_to_report( $result );
		return $result;
	}

	/**
	 * Conversion proprement dite.
	 *
	 * @param int $id Média.
	 * @return Result
	 */
	private static function convert_unrecorded( int $id ): array {
		$mime = (string) get_post_mime_type( $id );
		if ( ! in_array( $mime, self::SOURCE_MIMES, true ) ) {
			return self::failure( 'skipped', __( 'Not a JPEG or PNG image.', 'fluffless-suite' ) );
		}
		$target = self::target_mime();
		if ( null === $target ) {
			return self::failure( 'error', __( 'This server cannot write WebP images. Ask your host to enable WebP in GD or Imagick.', 'fluffless-suite' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$old_meta     = wp_get_attachment_metadata( $id );
		$old_attached = get_attached_file( $id, true );
		$source       = wp_get_original_image_path( $id, true );
		$source       = is_string( $source ) && is_readable( $source ) ? $source : $old_attached;
		if ( ! is_array( $old_meta ) || ! is_string( $old_attached ) || ! is_string( $source ) || ! is_readable( $source ) ) {
			return self::failure( 'error', __( 'The image file is missing on the server.', 'fluffless-suite' ) );
		}
		$old_files = self::files( $old_attached, $old_meta );
		$old_bytes = self::bytes( $old_files );

		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return self::failure( 'error', $editor->get_error_message() );
		}
		$dir   = dirname( $source );
		$base  = (string) preg_replace( '/-scaled$/', '', pathinfo( $source, PATHINFO_FILENAME ) );
		$name  = wp_unique_filename( $dir, $base . ( 'image/avif' === $target ? '.avif' : '.webp' ) );
		$saved = $editor->save( $dir . '/' . $name, $target );
		if ( is_wp_error( $saved ) ) {
			return self::failure( 'error', $saved->get_error_message() );
		}

		update_attached_file( $id, $saved['path'] );
		self::set_mime( $id, $target );
		$new_meta     = wp_generate_attachment_metadata( $id, $saved['path'] );
		$new_attached = get_attached_file( $id, true );
		$new_files    = is_string( $new_attached ) ? self::files( $new_attached, $new_meta ) : array( $saved['path'] );
		$new_bytes    = self::bytes( $new_files );

		if ( ! is_string( $new_attached ) || ! self::is_valid_image( $new_attached, $target ) || $new_bytes >= $old_bytes ) {
			// Pas plus léger, ou fichier illisible : on remet tout comme avant.
			foreach ( array_diff( $new_files, $old_files ) as $file ) {
				wp_delete_file( $file );
			}
			update_attached_file( $id, $old_attached );
			self::set_mime( $id, $mime );
			wp_update_attachment_metadata( $id, $old_meta );
			return self::failure( 'skipped', __( 'The new format was not lighter: the original is kept.', 'fluffless-suite' ) );
		}
		wp_update_attachment_metadata( $id, $new_meta );

		$map = self::url_map( $old_attached, $old_meta, $new_attached, $new_meta );
		self::rewrite_content( $map );
		update_post_meta(
			$id,
			self::META,
			array(
				'status'    => 'converted',
				'old_mime'  => $mime,
				'old_meta'  => $old_meta,
				'old_files' => array_map( '_wp_relative_upload_path', $old_files ),
				'map'       => $map,
				'old_bytes' => $old_bytes,
				'new_bytes' => $new_bytes,
				'time'      => time(),
			)
		);
		if ( Options::get_bool( self::id(), 'quarantine_originals' ) ) {
			Quarantine::quarantine_originals( $id );
		}
		return array(
			'status'  => 'converted',
			'saved'   => $old_bytes - $new_bytes,
			'message' => '',
		);
	}

	/**
	 * Résultat d'une conversion qui n'a pas eu lieu.
	 *
	 * @param string $status  skipped ou error.
	 * @param string $message Message pour la personne.
	 * @phpstan-param 'skipped'|'error' $status
	 * @return array<string, int|string>
	 * @phpstan-return Result
	 */
	private static function failure( string $status, string $message ): array {
		return array(
			'status'  => $status,
			'saved'   => 0,
			'message' => $message,
		);
	}

	/**
	 * Change le type MIME sans passer par wp_update_post (aucun crochet de sauvegarde de contenu).
	 *
	 * @param int    $id   Média.
	 * @param string $mime Type MIME.
	 */
	private static function set_mime( int $id, string $mime ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- une ligne, cache vidé juste après.
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $mime ), array( 'ID' => $id ) );
		clean_post_cache( $id );
	}

	/**
	 * Fichiers d'un média : fichier servi, original avant réduction, tailles.
	 *
	 * @param string       $attached Chemin du fichier servi.
	 * @param array<mixed> $meta     Métadonnées.
	 * @return list<string>
	 */
	public static function files( string $attached, array $meta ): array {
		$dir   = dirname( $attached );
		$files = array( $attached );
		if ( is_string( $meta['original_image'] ?? null ) ) {
			$files[] = $dir . '/' . wp_basename( $meta['original_image'] );
		}
		foreach ( is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array() as $size ) {
			if ( is_array( $size ) && is_string( $size['file'] ?? null ) ) {
				$files[] = $dir . '/' . wp_basename( $size['file'] );
			}
		}
		return array_values( array_filter( array_unique( $files ), 'is_file' ) );
	}

	/**
	 * Poids total de fichiers.
	 *
	 * @param string[] $files Chemins.
	 * @phpstan-param list<string> $files
	 */
	public static function bytes( array $files ): int {
		return (int) array_sum( array_map( static fn( string $f ): int => (int) filesize( $f ), $files ) );
	}

	/**
	 * Image lisible, du type attendu, avec des dimensions ?
	 *
	 * @param string $path Chemin.
	 * @param string $mime Type attendu.
	 */
	public static function is_valid_image( string $path, string $mime ): bool {
		if ( ! is_readable( $path ) || wp_get_image_mime( $path ) !== $mime ) {
			return false;
		}
		$size = wp_getimagesize( $path );
		return is_array( $size ) && $size[0] > 0 && $size[1] > 0;
	}

	/**
	 * Anciennes adresses (chemin depuis la racine du site) => nouvelles, les plus longues d'abord.
	 *
	 * @param string       $old_attached Ancien fichier servi.
	 * @param array<mixed> $old_meta     Anciennes métadonnées.
	 * @param string       $new_attached Nouveau fichier servi.
	 * @param array<mixed> $new_meta     Nouvelles métadonnées.
	 * @return array<string, string>
	 */
	public static function url_map( string $old_attached, array $old_meta, string $new_attached, array $new_meta ): array {
		$base    = untrailingslashit( (string) wp_parse_url( wp_upload_dir()['baseurl'], PHP_URL_PATH ) );
		$old_dir = $base . '/' . ltrim( dirname( _wp_relative_upload_path( $old_attached ) ), './' );
		$new_dir = $base . '/' . ltrim( dirname( _wp_relative_upload_path( $new_attached ) ), './' );
		$join    = static fn( string $dir, string $file ): string => rtrim( $dir, '/' ) . '/' . wp_basename( $file );
		$main    = $join( $new_dir, $new_attached );

		$map = array( $join( $old_dir, $old_attached ) => $main );
		if ( is_string( $old_meta['original_image'] ?? null ) ) {
			$map[ $join( $old_dir, $old_meta['original_image'] ) ] = is_string( $new_meta['original_image'] ?? null ) ? $join( $new_dir, $new_meta['original_image'] ) : $main;
		}
		$new_sizes = is_array( $new_meta['sizes'] ?? null ) ? $new_meta['sizes'] : array();
		foreach ( is_array( $old_meta['sizes'] ?? null ) ? $old_meta['sizes'] : array() as $name => $size ) {
			if ( is_array( $size ) && is_string( $size['file'] ?? null ) ) {
				$new_file                                = $new_sizes[ $name ]['file'] ?? null;
				$map[ $join( $old_dir, $size['file'] ) ] = is_string( $new_file ) ? $join( $new_dir, $new_file ) : $main;
			}
		}
		uksort( $map, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		return $map;
	}

	/**
	 * Remplace les adresses dans les contenus de tout type (modèles et styles globaux compris),
	 * révisions exceptées : l'historique garde ce qui a été publié.
	 *
	 * @param array<string, string> $map Anciennes => nouvelles adresses.
	 * @return int Contenus modifiés.
	 */
	public static function rewrite_content( array $map ): int {
		global $wpdb;
		$touched = array();
		foreach ( $map as $old => $new ) {
			// Recherche bornée, puis remplacement ciblé par identifiant ; appelé sur clic seulement.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$ids = array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND post_content LIKE %s LIMIT %d",
						'%' . $wpdb->esc_like( $old ) . '%',
						self::MAX_REWRITE_POSTS
					)
				)
			);
			if ( array() === $ids ) {
				continue;
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders ne contient que des %d.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE ID IN ($placeholders)", array_merge( array( $old, $new ), $ids ) ) );
			$touched = array_merge( $touched, $ids );
		}
		// phpcs:enable
		$touched = array_unique( $touched );
		foreach ( $touched as $post_id ) {
			clean_post_cache( $post_id );
		}
		return count( $touched );
	}

	/** Routes : bilan et lot. */
	public static function register_routes(): void {
		$permission = static fn(): bool => current_user_can( 'upload_files' );
		register_rest_route(
			self::REST_NS,
			'/webp/status',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => rest_ensure_response( self::stats() ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::REST_NS,
			'/webp/batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_batch' ),
				'permission_callback' => $permission,
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_BATCH,
						'default' => self::BATCH,
					),
				),
			)
		);
	}

	/**
	 * Convertit un lot, dans la limite de temps.
	 *
	 * @param \WP_REST_Request $request Requête.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function rest_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$start     = microtime( true );
		$processed = array();
		foreach ( self::candidates( (int) $request['limit'] ) as $id ) {
			if ( microtime( true ) - $start > self::TIME_BUDGET ) {
				break;
			}
			$processed[] = array(
				'id'    => $id,
				'title' => get_the_title( $id ),
			) + self::convert( $id );
		}
		return new \WP_REST_Response( array( 'processed' => $processed ) + self::stats() );
	}

	/** Ajoute Fluffless → Médiathèque WebP. */
	public static function add_page(): void {
		Menu::add_tab( 'media', self::PAGE, static fn(): string => __( 'WebP media library', 'fluffless-suite' ), array( self::class, 'render' ), 10 );
	}

	/**
	 * Poids lisible.
	 *
	 * @param int $bytes Octets.
	 */
	public static function size( int $bytes ): string {
		$formatted = size_format( $bytes, 1 );
		return is_string( $formatted ) ? html_entity_decode( $formatted, ENT_QUOTES, 'UTF-8' ) : (string) $bytes;
	}

	/**
	 * Nombre localisé en texte brut (sans &nbsp;), pour passer ensuite par esc_html().
	 *
	 * @param int $number Nombre.
	 */
	public static function number( int $number ): string {
		return html_entity_decode( number_format_i18n( $number ), ENT_QUOTES, 'UTF-8' );
	}

	/** Écran : bilan, bouton, barre de progression. */
	public static function render(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$stats  = self::stats();
		$target = self::target_mime();
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/media-webp/admin.js', FILE ), array( 'wp-api-fetch', 'wp-i18n' ), VERSION, true );
		wp_set_script_translations( self::HANDLE, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );

		echo '<div class="wrap">';
		Screen::header( __( 'WebP media library', 'fluffless-suite' ), __( 'Converts your JPEG and PNG images to a lighter format, five at a time. Your pages are updated to use the new files. Originals stay on the server unless you send them to quarantine.', 'fluffless-suite' ) );
		if ( null === $target ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This server cannot write WebP images. Ask your host to enable WebP in GD or Imagick.', 'fluffless-suite' ) . '</p></div></div>';
			return;
		}
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Make a backup of your site before a large conversion.', 'fluffless-suite' ) . '</p></div>';
		printf(
			'<p id="fluffless-webp-stats" data-remaining="%1$d">%2$s</p>',
			(int) $stats['remaining'],
			esc_html(
				sprintf(
					/* translators: 1: images waiting; 2: images converted; 3: images kept as is; 4: weight saved, e.g. 2.4 MB */
					__( 'Waiting: %1$s. Converted: %2$s. Kept as is: %3$s. Saved so far: %4$s.', 'fluffless-suite' ),
					self::number( $stats['remaining'] ),
					self::number( $stats['converted'] ),
					self::number( $stats['skipped'] + $stats['errors'] ),
					self::size( $stats['saved'] )
				)
			)
		);
		echo '<p><button type="button" class="button button-primary" id="fluffless-webp-start"' . disabled( 0, $stats['remaining'], false ) . '>' . esc_html__( 'Convert my images', 'fluffless-suite' ) . '</button> ';
		echo '<button type="button" class="button" id="fluffless-webp-stop" hidden>' . esc_html__( 'Pause', 'fluffless-suite' ) . '</button></p>';
		echo '<p><label for="fluffless-webp-progress">' . esc_html__( 'Progress', 'fluffless-suite' ) . '</label><br><progress id="fluffless-webp-progress" max="' . esc_attr( (string) max( 1, $stats['remaining'] ) ) . '" value="0"></progress></p>';
		echo '<div id="fluffless-webp-log" role="status" aria-live="polite"></div>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . Quarantine::PAGE ) ) . '">' . esc_html__( 'Unused images and quarantine', 'fluffless-suite' ) . '</a></p></div>';
	}

	/**
	 * Convertit les images de la médiathèque.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Nombre maximal d'images. Défaut : 100.
	 *
	 * [--dry-run]
	 * : Liste les images sans rien convertir.
	 *
	 * @param string[]              $args       Arguments positionnels (inutilisés).
	 * @param array<string, string> $assoc_args Options.
	 */
	public static function cli( array $args, array $assoc_args ): void {
		unset( $args );
		$limit = max( 1, min( 10000, (int) ( $assoc_args['limit'] ?? 100 ) ) );
		$ids   = self::candidates( $limit, false );
		if ( Cli::flag( $assoc_args, 'dry-run', false ) ) {
			foreach ( $ids as $id ) {
				Cli::log( sprintf( '#%d %s', $id, get_the_title( $id ) ) );
			}
			Cli::success( sprintf( '%d image(s) would be converted.', count( $ids ) ) );
			return;
		}
		$saved = 0;
		foreach ( $ids as $id ) {
			$result = self::convert( $id );
			$saved += $result['saved'];
			Cli::log( sprintf( '#%d %s %s %s', $id, $result['status'], self::size( $result['saved'] ), $result['message'] ) );
		}
		Cli::success( sprintf( '%d image(s) processed, %s saved.', count( $ids ), self::size( $saved ) ) );
	}
}
