<?php
/**
 * Module Pexels : chercher et importer une photo libre depuis l'éditeur, côté serveur.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\DeclaresHosts;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Sanitize;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Sécurité : la clé d'API se lit SEULEMENT dans la constante FLUFFLESS_PEXELS_KEY (wp-config.php).
 * Elle n'est jamais enregistrée en base, jamais renvoyée au navigateur, jamais écrite au journal.
 * Le navigateur ne choisit qu'un identifiant de photo : l'adresse du fichier est relue chez Pexels
 * par le serveur, puis vérifiée (https, domaine images.pexels.com) avant tout téléchargement.
 *
 * Sobriété : aucune requête vers Pexels côté visiteur ; recherches mises en cache 15 minutes ;
 * une seule photo importée, réduite à la largeur utile, convertie en WebP.
 *
 * Conditions de l'API Pexels : lien visible vers Pexels dans le panneau, et crédit du photographe
 * (légende avec liens, et métadonnée _fluffless_pexels).
 *
 * @phpstan-type Photo array{id: int, width: int, height: int, alt: string, photographer: string, photographer_url: string, url: string, thumb: string, original: string}
 */
final class Pexels implements Module, DeclaresHosts {

	public const KEY_CONSTANT = 'FLUFFLESS_PEXELS_KEY';
	public const REST_NS      = 'fluffless/v1';
	public const API          = 'https://api.pexels.com/v1/';
	public const IMAGE_HOST   = 'images.pexels.com';
	public const META         = '_fluffless_pexels';
	public const HANDLE       = 'fluffless-pexels-editor';

	/** Bornes : résultats par page, pages, longueur de la recherche, durée du cache. */
	public const PER_PAGE    = 15;
	public const MAX_PAGE    = 20;
	public const MAX_QUERY   = 100;
	private const CACHE_TTL  = 15 * MINUTE_IN_SECONDS;
	private const TIMEOUT    = 15;
	private const MAX_SOURCE = 30 * MB_IN_BYTES;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'pexels';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Pexels photos', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Search free Pexels photos from the editor. Only the photo you pick is imported, resized and converted to WebP, with its photographer credit. Visitors never contact Pexels.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array( 'width' => 1600 );
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
			'width' => array(
				'type'  => 'int',
				'label' => __( 'Width of imported photos (px)', 'fluffless-suite' ),
				'help'  => __( '1600 px covers a full-width image on most screens. Wider means heavier.', 'fluffless-suite' ),
				'min'   => 320,
				'max'   => 3840,
			),
		);
	}

	/** État de la clé, sans jamais l'afficher. */
	public static function status(): string {
		return self::has_key()
			? __( 'API key found in wp-config.php.', 'fluffless-suite' )
			: __( 'No key yet, so the module stays off. Ask the person who manages your hosting to add the free Pexels key (FLUFFLESS_PEXELS_KEY) to wp-config.php. Keys: https://www.pexels.com/api/', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
	}

	/** Clé présente dans wp-config.php ? */
	public static function has_key(): bool {
		return '' !== self::key();
	}

	/** Clé lue dans la constante ; jamais ailleurs. */
	private static function key(): string {
		$key = defined( self::KEY_CONSTANT ) ? constant( self::KEY_CONSTANT ) : '';
		return is_string( $key ) ? trim( $key ) : '';
	}

	/** Routes de recherche et d'import. */
	public static function register_routes(): void {
		$permission = static fn(): bool => current_user_can( 'upload_files' );
		register_rest_route(
			self::REST_NS,
			'/pexels/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_search' ),
				'permission_callback' => $permission,
				'args'                => array(
					'query' => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => self::MAX_QUERY,
					),
					'page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_PAGE,
						'default' => 1,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/pexels/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_import' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id'      => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'post_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Recherche, mise en cache.
	 *
	 * @param \WP_REST_Request $request Requête.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_search( \WP_REST_Request $request ) {
		$query = sanitize_text_field( (string) $request['query'] );
		$page  = (int) $request['page'];
		$found = self::search( $query, $page );
		return is_wp_error( $found ) ? $found : rest_ensure_response( $found );
	}

	/**
	 * Cherche des photos. Ne renvoie que les champs utiles au panneau.
	 *
	 * @param string $query Mots cherchés.
	 * @param int    $page  Page de résultats.
	 * @return array{photos: list<Photo>, total: int, page: int}|\WP_Error
	 */
	public static function search( string $query, int $page ) {
		$query = trim( mb_substr( $query, 0, self::MAX_QUERY ) );
		if ( '' === $query ) {
			return new \WP_Error( 'fluffless_pexels_query', __( 'Type a few words to search.', 'fluffless-suite' ), array( 'status' => 400 ) );
		}
		$page      = max( 1, min( self::MAX_PAGE, $page ) );
		$cache_key = 'fluffless_pexels_' . md5( strtolower( $query ) . '|' . $page . '|' . get_locale() );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['photos'], $cached['total'], $cached['page'] ) ) {
			/**
			 * Résultat mis en cache par cette même méthode, déjà validé.
			 *
			 * @var array{photos: list<Photo>, total: int, page: int} $cached
			 */
			return $cached;
		}
		$body = self::api(
			'search?' . http_build_query(
				array(
					'query'    => $query,
					'page'     => $page,
					'per_page' => self::PER_PAGE,
					'locale'   => str_replace( '_', '-', get_locale() ),
				)
			)
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$photos = array();
		foreach ( is_array( $body['photos'] ?? null ) ? array_slice( $body['photos'], 0, self::PER_PAGE ) : array() as $raw ) {
			$photo = self::photo( $raw );
			if ( null !== $photo ) {
				$photos[] = $photo;
			}
		}
		$result = array(
			'photos' => $photos,
			'total'  => is_int( $body['total_results'] ?? null ) ? $body['total_results'] : count( $photos ),
			'page'   => $page,
		);
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Importe une photo choisie par son identifiant.
	 *
	 * @param \WP_REST_Request $request Requête.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_import( \WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			$post_id = 0;
		}
		$id = self::import( (int) $request['id'], $post_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response(
			array(
				'id'      => $id,
				'url'     => (string) wp_get_attachment_url( $id ),
				'alt'     => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'caption' => (string) get_post_field( 'post_excerpt', $id ),
			)
		);
	}

	/**
	 * Télécharge, réduit, convertit en WebP et ajoute la photo à la médiathèque.
	 *
	 * @param int $photo_id Identifiant Pexels.
	 * @param int $post_id  Contenu parent (0 = aucun).
	 * @return int|\WP_Error Identifiant du média.
	 */
	public static function import( int $photo_id, int $post_id = 0 ) {
		$existing = self::existing( $photo_id );
		if ( $existing > 0 ) {
			return $existing;
		}
		$body = self::api( 'photos/' . $photo_id );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$photo = self::photo( $body );
		if ( null === $photo || $photo['id'] !== $photo_id ) {
			return new \WP_Error( 'fluffless_pexels_photo', __( 'Pexels sent an unexpected answer for this photo. Try another one.', 'fluffless-suite' ), array( 'status' => 502 ) );
		}
		$width  = self::import_width();
		$source = add_query_arg(
			array(
				'auto' => 'compress',
				'cs'   => 'tinysrgb',
				'w'    => min( $width, $photo['width'] ),
			),
			$photo['original']
		);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $source, self::TIMEOUT );
		if ( is_wp_error( $tmp ) ) {
			return new \WP_Error( 'fluffless_pexels_download', __( 'The photo could not be downloaded. Try again in a moment.', 'fluffless-suite' ) . ' ' . $tmp->get_error_message(), array( 'status' => 502 ) );
		}
		$webp = self::to_webp( $tmp, $width );
		wp_delete_file( $tmp );
		if ( is_wp_error( $webp ) ) {
			return $webp;
		}

		$slug = sanitize_title( '' !== $photo['alt'] ? mb_substr( $photo['alt'], 0, 60 ) : 'photo' );
		$file = array(
			'name'     => 'pexels-' . $photo['id'] . '-' . $slug . '.webp',
			'tmp_name' => $webp,
		);
		$id   = media_handle_sideload(
			$file,
			$post_id,
			'' !== $photo['alt'] ? $photo['alt'] : null,
			array( 'post_excerpt' => self::caption( $photo ) )
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $webp );
			return $id;
		}
		// Texte alternatif prérempli : la personne le relit dans la médiathèque ou le bloc Image.
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $photo['alt'] ) );
		update_post_meta(
			$id,
			self::META,
			array(
				'id'               => $photo['id'],
				'url'              => $photo['url'],
				'photographer'     => $photo['photographer'],
				'photographer_url' => $photo['photographer_url'],
			)
		);
		return $id;
	}

	/**
	 * Média déjà importé pour cette photo : on ne la télécharge pas deux fois.
	 *
	 * @param int $photo_id Identifiant Pexels.
	 */
	private static function existing( int $photo_id ): int {
		$found = get_posts(
			array(
				'post_type'     => 'attachment',
				'post_status'   => 'inherit',
				'numberposts'   => 1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- un seul import à la fois, sur clic.
				'meta_query'    => array(
					array(
						'key'     => self::META,
						'value'   => sprintf( 's:2:"id";i:%d;', $photo_id ),
						'compare' => 'LIKE',
					),
				),
			)
		);
		return (int) ( $found[0] ?? 0 );
	}

	/** Largeur d'import réglée, bornée. */
	private static function import_width(): int {
		$width = \Fluffless\Suite\Core\Options::get_int( self::id(), 'width' );
		return $width >= 320 ? min( $width, 3840 ) : 1600;
	}

	/**
	 * Réduit à la largeur voulue et enregistre en WebP dans un fichier temporaire.
	 *
	 * @param string $path  Fichier téléchargé.
	 * @param int    $width Largeur maximale.
	 * @return string|\WP_Error Chemin du WebP.
	 */
	private static function to_webp( string $path, int $width ) {
		if ( ! is_readable( $path ) || (int) filesize( $path ) > self::MAX_SOURCE ) {
			return new \WP_Error( 'fluffless_pexels_file', __( 'The downloaded file is missing or too large.', 'fluffless-suite' ), array( 'status' => 502 ) );
		}
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return new \WP_Error( 'fluffless_pexels_webp', __( 'This server cannot write WebP images. Ask your host to enable WebP in GD or Imagick.', 'fluffless-suite' ), array( 'status' => 500 ) );
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$size = $editor->get_size();
		if ( $size['width'] > $width ) {
			$resized = $editor->resize( $width, null, false );
			if ( is_wp_error( $resized ) ) {
				return $resized;
			}
		}
		$saved = $editor->save( wp_tempnam( 'pexels' ) . '.webp', 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return $saved['path'];
	}

	/**
	 * Légende de crédit, avec liens, comme le demandent les conditions de Pexels.
	 *
	 * @param array<string, int|string> $photo Photo.
	 * @phpstan-param Photo $photo
	 */
	public static function caption( array $photo ): string {
		return sprintf(
			/* translators: 1: photographer name linked to their profile; 2: "Pexels" linked to the photo page */
			__( 'Photo: %1$s on %2$s', 'fluffless-suite' ),
			'<a href="' . esc_url( $photo['photographer_url'] ) . '">' . esc_html( $photo['photographer'] ) . '</a>',
			'<a href="' . esc_url( $photo['url'] ) . '">Pexels</a>'
		);
	}

	/**
	 * Appel à l'API Pexels, avec la clé en en-tête (jamais dans l'adresse).
	 *
	 * @param string $path Chemin et paramètres après /v1/.
	 * @return array<mixed>|\WP_Error Corps décodé.
	 */
	private static function api( string $path ) {
		$key = self::key();
		if ( '' === $key ) {
			return new \WP_Error( 'fluffless_pexels_no_key', __( 'Pexels is not set up: add your free API key to wp-config.php (FLUFFLESS_PEXELS_KEY). Get one at https://www.pexels.com/api/', 'fluffless-suite' ), array( 'status' => 503 ) );
		}
		$response = wp_safe_remote_get(
			self::API . $path,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Authorization' => $key ),
			)
		);
		if ( is_wp_error( $response ) ) {
			// Le message d'origine peut venir de la liste blanche HTTP : il dit quel domaine autoriser.
			return new \WP_Error( 'fluffless_pexels_http', __( 'Pexels could not be reached.', 'fluffless-suite' ) . ' ' . $response->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			return new \WP_Error( 'fluffless_pexels_key', __( 'Pexels refused the API key. Check FLUFFLESS_PEXELS_KEY in wp-config.php.', 'fluffless-suite' ), array( 'status' => 502 ) );
		}
		if ( 429 === $code ) {
			return new \WP_Error( 'fluffless_pexels_limit', __( 'Pexels search limit reached for now. Try again later.', 'fluffless-suite' ), array( 'status' => 429 ) );
		}
		if ( 200 !== $code ) {
			return new \WP_Error(
				'fluffless_pexels_status',
				/* translators: %d: HTTP status code, e.g. 500 */
				sprintf( __( 'Pexels answered with an error (code %d). Try again later.', 'fluffless-suite' ), $code ),
				array( 'status' => 502 )
			);
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'fluffless_pexels_json', __( 'Pexels sent an unreadable answer. Try again later.', 'fluffless-suite' ), array( 'status' => 502 ) );
		}
		return $body;
	}

	/**
	 * Photo validée : seules les adresses https de Pexels passent.
	 *
	 * @param mixed $raw Photo brute de l'API.
	 * @return Photo|null
	 */
	public static function photo( $raw ): ?array {
		if ( ! is_array( $raw ) || ! is_int( $raw['id'] ?? null ) || ! is_array( $raw['src'] ?? null ) ) {
			return null;
		}
		$original = is_string( $raw['src']['original'] ?? null ) ? $raw['src']['original'] : '';
		$thumb    = is_string( $raw['src']['medium'] ?? null ) ? $raw['src']['medium'] : '';
		if ( ! self::is_pexels_image( $original ) || ! self::is_pexels_image( $thumb ) ) {
			return null;
		}
		$text = static fn( $value ): string => is_string( $value ) ? sanitize_text_field( $value ) : '';
		$link = static fn( $value ): string => is_string( $value ) && str_starts_with( $value, 'https://www.pexels.com/' ) ? esc_url_raw( $value ) : 'https://www.pexels.com/';
		return array(
			'id'               => $raw['id'],
			'width'            => is_int( $raw['width'] ?? null ) ? $raw['width'] : 0,
			'height'           => is_int( $raw['height'] ?? null ) ? $raw['height'] : 0,
			'alt'              => $text( $raw['alt'] ?? '' ),
			'photographer'     => $text( $raw['photographer'] ?? '' ),
			'photographer_url' => $link( $raw['photographer_url'] ?? '' ),
			'url'              => $link( $raw['url'] ?? '' ),
			'thumb'            => $thumb,
			'original'         => $original,
		);
	}

	/**
	 * Adresse https sur images.pexels.com ?
	 *
	 * @param string $url Adresse.
	 */
	private static function is_pexels_image( string $url ): bool {
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && self::IMAGE_HOST === wp_parse_url( $url, PHP_URL_HOST );
	}

	/** Panneau de l'éditeur, pour qui peut téléverser. */
	public static function editor_assets(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/pexels/editor.js', FILE ),
			array( 'wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-url' ),
			VERSION,
			true
		);
		wp_set_script_translations( self::HANDLE, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
		$data = wp_json_encode(
			array(
				'hasKey'  => self::has_key(),
				'keyHelp' => 'https://www.pexels.com/api/',
			)
		);
		if ( is_string( $data ) ) {
			wp_add_inline_script( self::HANDLE, 'window.flufflessPexels=' . $data . ';', 'before' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function hosts(): array {
		$data    = array(
			(string) wp_parse_url( self::API, PHP_URL_HOST ) => __( 'Search words, page number, site language and your API key', 'fluffless-suite' ),
			self::IMAGE_HOST => __( 'A file request, once per photo imported', 'fluffless-suite' ),
		);
		$entries = array();
		foreach ( $data as $host => $sent ) {
			$entries[] = array(
				'host'    => $host,
				'service' => __( 'Pexels photos', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'action',
				'data'    => $sent,
				'active'  => self::has_key(),
			);
		}
		return $entries;
	}
}
