<?php
/**
 * Fichiers virtuels (robots.txt, llms.txt, clé IndexNow) et avis IndexNow à la publication.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Aucun fichier n'est écrit sur le disque : tout est servi à la demande.
 * IndexNow est la seule requête sortante de l'extension ; éteinte par défaut,
 * elle part vers api.indexnow.org, sans attendre la réponse.
 */
final class Robots {

	public const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

	/** Pages listées au plus dans le llms.txt généré. */
	private const LLMS_PAGES = 50;

	/**
	 * Adresses déjà signalées dans la requête (une publication peut déclencher plusieurs transitions).
	 *
	 * @var array<string, true>
	 */
	private static array $pinged = array();

	/** Pose les crochets des familles allumées. */
	public static function register(): void {
		add_filter( 'fluffless_outgoing_hosts', array( self::class, 'outgoing_hosts' ) );
		if ( Settings::on( 'robots_txt' ) ) {
			add_filter( 'robots_txt', array( self::class, 'robots_txt' ), 20, 2 );
		}
		if ( Settings::on( 'llms' ) || Settings::on( 'indexnow' ) ) {
			add_action( 'template_redirect', array( self::class, 'serve' ), 0 );
		}
		if ( Settings::on( 'indexnow' ) ) {
			add_action( 'transition_post_status', array( self::class, 'on_transition' ), 10, 3 );
		}
	}

	/**
	 * Contenu personnalisé du robots.txt, avec la ligne du plan de site.
	 *
	 * @param mixed $output Contenu généré.
	 * @param mixed $site_public Le site accepte l'indexation ('1') ou non.
	 * @return mixed
	 */
	public static function robots_txt( $output, $site_public ) {
		$custom = trim( Settings::string( 'robots_txt_content' ) );
		// Site privé : WordPress interdit tout ; on ne rouvre pas la porte.
		if ( '' === $custom || '0' === (string) $site_public ) {
			return $output;
		}
		$sitemap = function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() ? get_sitemap_url( 'index' ) : false;
		if ( is_string( $sitemap ) && false === stripos( $custom, 'sitemap:' ) ) {
			$custom .= "\n\nSitemap: " . $sitemap;
		}
		return $custom . "\n";
	}

	/** Sert /llms.txt et /<clé>.txt (IndexNow) ; laisse passer le reste. */
	public static function serve(): void {
		$file = self::requested_file();
		$body = null;
		if ( 'llms.txt' === $file && Settings::on( 'llms' ) ) {
			$body = self::llms_text();
		} elseif ( Settings::on( 'indexnow' ) && '' !== Settings::string( 'indexnow_key' ) && Settings::string( 'indexnow_key' ) . '.txt' === $file ) {
			$body = Settings::string( 'indexnow_key' );
		}
		if ( null === $body ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- texte brut servi en text/plain.
		exit;
	}

	/** Nom du fichier demandé à la racine du site, ou ''. */
	private static function requested_file(): string {
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = Redirects::request_path();
		$base = is_string( $home ) ? untrailingslashit( $home ) : '';
		if ( '' !== $base && str_starts_with( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return 1 === preg_match( '#^/([A-Za-z0-9_\-]{1,100}\.txt)$#', $path, $match ) ? $match[1] : '';
	}

	/** Texte du llms.txt : personnalisé, sinon résumé du site et de ses pages. */
	public static function llms_text(): string {
		$custom = trim( Settings::string( 'llms_content' ) );
		if ( '' !== $custom ) {
			return $custom . "\n";
		}
		$lines   = array( '# ' . Context::plain( get_bloginfo( 'name' ) ) );
		$tagline = Context::plain( get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}
		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'posts_per_page'   => self::LLMS_PAGES,
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'has_password'     => false,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- seulement quand llms.txt est demandé.
					'relation' => 'OR',
					array(
						'key'     => Meta::ROBOTS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Meta::ROBOTS,
						'value'   => '"noindex"',
						'compare' => 'NOT LIKE',
					),
				),
			)
		);
		if ( array() !== $pages ) {
			$lines[] = '';
			$lines[] = '## ' . __( 'Pages', 'fluffless-seo' );
			$lines[] = '';
			foreach ( $pages as $page ) {
				$title   = str_replace( array( '[', ']' ), '', Context::plain( get_the_title( $page ) ) );
				$lines[] = '- [' . $title . '](' . get_permalink( $page ) . ')';
			}
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Publication ou mise à jour d'un contenu public : avis IndexNow.
	 *
	 * @param mixed $new_status Nouvel état.
	 * @param mixed $old_status Ancien état.
	 * @param mixed $post       Contenu.
	 */
	public static function on_transition( $new_status, $old_status, $post ): void {
		unset( $old_status );
		if ( 'publish' !== $new_status || ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( null === $type || ! $type->public || in_array( 'noindex', Meta::robots( $post->ID ), true ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( '' !== $url ) {
			self::ping( $url );
		}
	}

	/**
	 * Corps de la requête IndexNow pour une adresse, ou null si l'avis ne doit pas partir
	 * (site privé, environnement local, clé absente, adresse hors du site).
	 *
	 * @param string $url Adresse publiée.
	 * @return array{host: string, key: string, keyLocation: string, urlList: list<string>}|null
	 */
	public static function payload( string $url ): ?array {
		$key  = Settings::string( 'indexnow_key' );
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( '' === $key || ! is_string( $host ) || '0' === (string) get_option( 'blog_public' ) || in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return null;
		}
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			return null;
		}
		return array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList'     => array( $url ),
		);
	}

	/**
	 * Envoie l'avis, sans attendre la réponse, une fois par adresse et par requête.
	 *
	 * @param string $url Adresse publiée.
	 */
	private static function ping( string $url ): void {
		$payload = isset( self::$pinged[ $url ] ) ? null : self::payload( $url );
		$body    = null === $payload ? false : wp_json_encode( $payload );
		if ( false === $body ) {
			return;
		}
		self::$pinged[ $url ] = true;
		wp_remote_post(
			self::INDEXNOW_ENDPOINT,
			array(
				'blocking' => false,
				'timeout'  => 3,
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => $body,
			)
		);
	}

	/**
	 * Déclare IndexNow à l'écran Serveurs contactés de Fluffless Suite (filtre fluffless_outgoing_hosts),
	 * seulement s'il est allumé. Mêmes conditions que payload() : rien ne part d'un site qui demande
	 * à ne pas être indexé, ni d'un environnement local ou de développement.
	 *
	 * @param mixed $entries Entrées déjà déclarées.
	 * @return mixed
	 */
	public static function outgoing_hosts( $entries ) {
		if ( ! is_array( $entries ) || ! Settings::on( 'indexnow' ) ) {
			return $entries;
		}
		$entries[] = array(
			'host'    => (string) wp_parse_url( self::INDEXNOW_ENDPOINT, PHP_URL_HOST ),
			'service' => __( 'IndexNow (Fluffless SEO)', 'fluffless-seo' ),
			'side'    => 'server',
			'when'    => 'on_publish',
			'data'    => __( 'The address of the published page and the IndexNow key of your site', 'fluffless-seo' ),
			'active'  => '0' !== (string) get_option( 'blog_public' ),
			'env_off' => in_array( wp_get_environment_type(), array( 'local', 'development' ), true ),
		);
		return $entries;
	}
}
