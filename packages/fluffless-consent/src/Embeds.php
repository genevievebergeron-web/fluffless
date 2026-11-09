<?php
/**
 * Contenu intégré de tiers (iframe, embed, object, video, scripts sociaux) : neutralisé au rendu, activé par consent.js après un oui.
 *
 * @package Fluffless_Consent
 */

declare( strict_types=1 );

namespace Fluffless\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Le serveur neutralise toujours les contenus de services connus, sans jamais lire de témoin :
 * le HTML est le même pour tout le monde, donc compatible avec les caches de page.
 *
 * - iframe et embed : remplacés par un espace réservé avec un lien direct vers le contenu (lecture sans
 *   JavaScript) ; consent.js recrée l'iframe quand la catégorie « media » est acceptée.
 * - object et video : remplacés par un lien seulement (rien à recréer).
 * - script d'un service connu (X, Instagram, TikTok, Facebook) : rendu inerte, consent.js le réactive.
 * - iframe dont l'adresse http(s) n'a pas d'hôte analysable : retirée (on ne peut pas dire où elle mène).
 *
 * Hôtes inconnus : non touchés. Le filtre fluffless_consent_embed_hosts en ajoute (« hote » ou « hote/chemin »).
 * Une iframe qui porte srcdoc (contenu en ligne, sans adresse tierce) n'est pas touchée.
 * Les extraits n'affichent jamais le texte de l'espace réservé ; les flux reçoivent un simple lien.
 */
final class Embeds {

	/** Services reconnus : hôte (ou hôte/préfixe de chemin) => nom affiché. */
	private const HOSTS = array(
		'youtube.com'          => 'YouTube',
		'youtube-nocookie.com' => 'YouTube',
		'youtu.be'             => 'YouTube',
		'vimeo.com'            => 'Vimeo',
		'dailymotion.com'      => 'Dailymotion',
		'twitch.tv'            => 'Twitch',
		'openstreetmap.org'    => 'OpenStreetMap',
		'google.com/maps'      => 'Google Maps',
		'maps.google.com'      => 'Google Maps',
		'bing.com/maps'        => 'Bing Maps',
		'spotify.com'          => 'Spotify',
		'soundcloud.com'       => 'SoundCloud',
		'mixcloud.com'         => 'Mixcloud',
		'facebook.com'         => 'Facebook',
		'facebook.net'         => 'Facebook',
		'instagram.com'        => 'Instagram',
		'tiktok.com'           => 'TikTok',
		'twitter.com'          => 'X',
		'x.com'                => 'X',
		'linkedin.com'         => 'LinkedIn',
		'pinterest.com'        => 'Pinterest',
		'loom.com'             => 'Loom',
		'calendly.com'         => 'Calendly',
		'issuu.com'            => 'Issuu',
	);

	/**
	 * Éléments visés, avec leur contenu : script (jusqu'à sa fermeture), iframe (imbriquées comprises, la
	 * fermeture est facultative), object et video (jusqu'à leur fermeture) et embed. Les guillemets des
	 * attributs peuvent contenir « > » ; une apostrophe ou un guillemet n'ouvre une valeur qu'après un « = »
	 * (ATTRS). Un script est lu en entier avant tout le reste : une balise écrite
	 * dans son texte n'est jamais touchée.
	 */
	private const ATTRS = '(?:[^>"\'=]++|=\\s*+"[^"]*+"|=\\s*+\'[^\']*+\'|=|["\'])*+';

	private const PATTERN = '#<script\\b' . self::ATTRS . '>(?:[^<]++|<(?!/script\\b))*+(?:</script\\s*>|\\z)'
		. '|(?<f><iframe\\b' . self::ATTRS . '>(?:(?:[^<]++|(?&f)|<(?!/?iframe\\b))*+</iframe\\s*>)?+)'
		. '|<(?<o>object|video)\\b' . self::ATTRS . '>(?:(?:[^<]++|<(?!/\\k<o>\\b))*+</\\k<o>\\s*>)?+'
		. '|<embed\\b' . self::ATTRS . '>#i';

	/** Attributs recopiés sur l'iframe recréée (jamais src, srcdoc ni on*). */
	private const KEPT = array( 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy', 'sandbox', 'name' );

	/** Classes des citations qu'un script social habille : leur présence suffit à annoncer la bannière. */
	private const MARKERS = array( 'twitter-tweet', 'instagram-media', 'tiktok-embed', 'fb-post', 'fb-page', 'fb-video' );

	/**
	 * Au moins une balise a été neutralisée pendant ce rendu ?
	 *
	 * @var bool
	 */
	private static bool $found = false;

	/**
	 * Source brute d'un contenu : contient-elle un contenu tiers neutralisable, un bloc Intégration ou un code court embed ?
	 *
	 * @param string $content Contenu brut d'un article.
	 */
	public static function in_source( string $content ): bool {
		if ( str_contains( $content, '<!-- wp:embed' ) || str_contains( $content, '[embed' ) ) {
			if ( self::listed_link( $content ) ) {
				return true;
			}
		}
		foreach ( self::MARKERS as $marker ) {
			if ( str_contains( $content, $marker ) ) {
				return true;
			}
		}
		$count = preg_match_all( self::PATTERN, $content, $found );
		if ( false === $count ) {
			return true;
		}
		foreach ( $found[0] as $tag ) {
			if ( null !== self::inspect( $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Un bloc Intégration ou un code court embed de la source pointe-t-il vers un service de la liste ?
	 *
	 * @param string $content Contenu brut d'un article.
	 */
	private static function listed_link( string $content ): bool {
		$found = preg_match_all( '#<!-- wp:embed\\s+\\{[^}]*?"url"\\s*:\\s*"([^"]+)"#i', $content, $blocks );
		$short = preg_match_all( '#\\[embed[^\\]]*\\]\\s*(https?://[^\\s\\[]+)#i', $content, $codes );
		if ( false === $found || false === $short ) {
			return true;
		}
		foreach ( array_merge( $blocks[1], $codes[1] ) as $url ) {
			if ( null !== self::provider( stripslashes( str_replace( '\\/', '/', $url ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Une balise a été neutralisée sur cette page : la bannière doit exister. */
	public static function found(): bool {
		return self::$found;
	}

	/** Oublie l'état de la page (tests). */
	public static function reset(): void {
		self::$found = false;
	}

	/** Crochets de rendu : contenu de l'article, blocs (modèles et parties de modèle compris), widgets, extraits. */
	public static function register(): void {
		foreach ( self::HOOKS as $hook ) {
			add_filter( $hook, array( self::class, 'filter' ), 100 );
		}
	}

	/** Retire les crochets de rendu (tests). */
	public static function unregister(): void {
		foreach ( self::HOOKS as $hook ) {
			remove_filter( $hook, array( self::class, 'filter' ), 100 );
		}
	}

	/** Filtres qui rendent du HTML. */
	private const HOOKS = array( 'the_content', 'render_block', 'widget_text', 'widget_block_content', 'widget_custom_html_content' );

	/**
	 * Neutralise les contenus tiers de services connus.
	 *
	 * @param mixed $html Rendu reçu d'un filtre.
	 * @return mixed
	 */
	public static function filter( $html ) {
		if ( ! is_string( $html ) || ! self::may_contain( $html ) ) {
			return $html;
		}
		$out = preg_replace_callback( self::PATTERN, array( self::class, 'replace' ), $html );
		if ( is_string( $out ) ) {
			return $out;
		}
		error_log( 'Fluffless Consent : l\'expression régulière des contenus intégrés a échoué, repli sur la lecture balise par balise (preg_last_error ' . preg_last_error() . ', ' . preg_last_error_msg() . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- trace de diagnostic, sans donnée de visiteur.
		return self::degrade( $html );
	}

	/**
	 * Le texte contient-il une balise visée ?
	 *
	 * @param string $html HTML.
	 */
	private static function may_contain( string $html ): bool {
		foreach ( array( '<iframe', '<embed', '<object', '<video', '<script' ) as $needle ) {
			if ( false !== stripos( $html, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Une balise trouvée : remplacement si le contenu vient d'un service connu, sinon la balise telle quelle.
	 *
	 * @param array<int, string> $hit Correspondance de l'expression régulière.
	 */
	public static function replace( array $hit ): string {
		$found = self::inspect( $hit[0] );
		if ( null === $found ) {
			return $hit[0];
		}
		$kind = $found['kind'];
		if ( doing_filter( 'get_the_excerpt' ) ) {
			return '';
		}
		$feed = isset( $GLOBALS['wp_query'] ) && is_feed();
		if ( $feed ) {
			return in_array( $kind, array( 'frame', 'link' ), true ) ? '<p>' . self::link( $found, false ) . '</p>' : '';
		}
		if ( 'drop' === $kind ) {
			return '<div class="fluffless-embed"><p class="fluffless-embed__text">' . esc_html__( 'This external content could not be verified and is not displayed.', 'fluffless-consent' ) . '</p></div>';
		}
		self::$found = true;
		self::announce();

		if ( 'script' === $kind ) {
			$tag = new \WP_HTML_Tag_Processor( $hit[0] );
			$tag->next_tag();
			$tag->remove_attribute( 'type' );
			return substr_replace( $tag->get_updated_html(), ' type="text/plain" data-fluffless-consent="media"', 7, 0 );
		}
		if ( 'link' === $kind ) {
			return sprintf(
				'<div class="fluffless-embed"><p class="fluffless-embed__text">%1$s</p><p class="fluffless-embed__actions">%2$s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: service name, such as YouTube */
						__( 'This content comes from %s and is not loaded on this page.', 'fluffless-consent' ),
						$found['label']
					)
				),
				self::link( $found, true )
			);
		}

		$attrs = $found['attrs'];
		$ratio = '';
		if ( 1 === preg_match( '/^\d{2,4}$/D', $attrs['width'] ?? '' ) && 1 === preg_match( '/^\d{2,4}$/D', $attrs['height'] ?? '' ) ) {
			$ratio = ' style="aspect-ratio:' . (int) $attrs['width'] . '/' . (int) $attrs['height'] . ';max-width:' . (int) $attrs['width'] . 'px"';
		}
		$data = wp_json_encode(
			array(
				's' => $found['src'],
				'a' => (object) $attrs,
			)
		);

		return sprintf(
			'<div class="fluffless-embed" data-fluffless-embed="%1$s"%2$s>'
				. '<p class="fluffless-embed__text">%3$s</p>'
				. '<p class="fluffless-embed__actions">%4$s <button type="button" hidden data-fluffless-consent-open>%5$s</button></p></div>',
			esc_attr( false === $data ? '{}' : $data ),
			$ratio,
			esc_html(
				sprintf(
					/* translators: %s: service name, such as YouTube */
					__( 'This content comes from %s. It stays blocked until you allow embedded content.', 'fluffless-consent' ),
					$found['label']
				)
			),
			self::link( $found, true ),
			esc_html__( 'Load after consent', 'fluffless-consent' )
		);
	}

	/**
	 * Lien « Ouvrir sur … » vers le contenu.
	 *
	 * @param array{kind: string, src: string, label: string, attrs: array<string, string>} $found  Balise lue.
	 * @param bool                                                                          $blank  Ouvrir dans un nouvel onglet.
	 */
	private static function link( array $found, bool $blank ): string {
		return sprintf(
			'<a href="%1$s"%2$s rel="noopener noreferrer">%3$s</a>',
			esc_url( self::open_url( $found['src'] ) ),
			$blank ? ' target="_blank"' : '',
			esc_html(
				sprintf(
					/* translators: %s: service name, such as YouTube */
					__( 'Open on %s', 'fluffless-consent' ),
					$found['label']
				)
			)
		);
	}

	/**
	 * Lit une balise : genre de remplacement, adresse, service reconnu et attributs à recopier, ou null si elle
	 * reste telle quelle (hôte inconnu, adresse relative, srcdoc, script déjà inerte).
	 *
	 * Genres : frame (iframe ou embed, activable), link (object ou video, lien seulement), script (rendu inerte),
	 * drop (adresse http(s) sans hôte analysable).
	 *
	 * @param string $html Balise avec son contenu.
	 * @return array{kind: string, src: string, label: string, attrs: array<string, string>}|null
	 */
	private static function inspect( string $html ): ?array {
		$tag = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tag->next_tag() ) {
			return null;
		}
		$name = (string) $tag->get_tag();

		if ( 'SCRIPT' === $name ) {
			$src = $tag->get_attribute( 'src' );
			if ( ! is_string( $src ) || null !== $tag->get_attribute( 'data-fluffless-consent' ) ) {
				return null;
			}
			$known = self::resolve( $src );
			return null === $known || '' === $known['label'] ? null : array(
				'kind'  => 'script',
				'src'   => $known['src'],
				'label' => $known['label'],
				'attrs' => array(),
			);
		}

		if ( 'OBJECT' === $name || 'VIDEO' === $name ) {
			$candidates = array();
			do {
				$own = match ( (string) $tag->get_tag() ) {
					'OBJECT' => $tag->get_attribute( 'data' ),
					'VIDEO', 'EMBED', 'SOURCE' => $tag->get_attribute( 'src' ),
					default => null,
				};
				if ( is_string( $own ) ) {
					$candidates[] = $own;
				}
			} while ( $tag->next_tag() );
			foreach ( $candidates as $candidate ) {
				$known = self::resolve( $candidate );
				if ( null !== $known && '' !== $known['label'] ) {
					return array(
						'kind'  => 'link',
						'src'   => $known['src'],
						'label' => $known['label'],
						'attrs' => array(),
					);
				}
			}
			return null;
		}

		if ( null !== $tag->get_attribute( 'srcdoc' ) ) {
			return null;
		}
		$src = $tag->get_attribute( 'src' );
		if ( ! is_string( $src ) ) {
			return null;
		}
		$known = self::resolve( $src );
		if ( null === $known ) {
			return null;
		}
		if ( '' === $known['label'] ) {
			return array(
				'kind'  => 'drop',
				'src'   => '',
				'label' => '',
				'attrs' => array(),
			);
		}
		$attrs = array();
		foreach ( self::KEPT as $key ) {
			$value = $tag->get_attribute( $key );
			if ( is_string( $value ) ) {
				$attrs[ $key ] = $value;
			} elseif ( true === $value ) {
				$attrs[ $key ] = '';
			}
		}
		return array(
			'kind'  => 'frame',
			'src'   => $known['src'],
			'label' => $known['label'],
			'attrs' => $attrs,
		);
	}

	/**
	 * Repli quand l'expression régulière échoue (limite PCRE) : sans elle, on ne laisse passer aucune adresse
	 * tierce. Les attributs d'adresse sont retirés et les scripts rendus inertes ; le texte de la page reste.
	 *
	 * @param string $html HTML d'origine.
	 */
	private static function degrade( string $html ): string {
		$tag  = new \WP_HTML_Tag_Processor( $html );
		$hits = false;
		while ( $tag->next_tag() ) {
			$name = (string) $tag->get_tag();
			$attr = match ( $name ) {
				'OBJECT' => 'data',
				'IFRAME', 'EMBED', 'VIDEO', 'SOURCE', 'SCRIPT' => 'src',
				default => '',
			};
			$value = '' === $attr ? null : $tag->get_attribute( $attr );
			if ( ! is_string( $value ) || ( 'IFRAME' === $name && null !== $tag->get_attribute( 'srcdoc' ) ) ) {
				continue;
			}
			$known = self::resolve( $value );
			if ( null === $known ) {
				continue;
			}
			if ( 'SCRIPT' === $name ) {
				if ( null !== $tag->get_attribute( 'data-fluffless-consent' ) || '' === $known['label'] ) {
					continue;
				}
				$tag->set_attribute( 'data-fluffless-consent', 'media' );
				$tag->set_attribute( 'type', 'text/plain' );
			} else {
				$tag->remove_attribute( $attr );
				if ( 'IFRAME' === $name && '' !== $known['label'] ) {
					// Jamais de carré blanc muet : le lien de lecture tient lieu de contenu, sans rien charger.
					$tag->set_attribute( 'srcdoc', '<a href="' . esc_url( self::open_url( $known['src'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( sprintf( /* translators: %s: service name, such as YouTube */ __( 'Open on %s', 'fluffless-consent' ), $known['label'] ) ) . '</a>' );
				}
			}
			$hits = true;
		}
		if ( $hits && ! doing_filter( 'get_the_excerpt' ) ) {
			self::$found = true;
			self::announce();
		}
		return $tag->get_updated_html();
	}

	/**
	 * Adresse telle que le navigateur la lit : sans tabulation ni saut de ligne, barres inverses en barres droites,
	 * toute adresse http(s) ou à double barre ramenée à https://. Une adresse relative ou d'un autre schéma reste telle quelle.
	 *
	 * @param string $raw Valeur de l'attribut.
	 */
	private static function normalize( string $raw ): string {
		$src  = trim( str_replace( array( "\t", "\n", "\r" ), '', $raw ), "\x00..\x20" );
		$src  = str_replace( '\\', '/', $src );
		$head = strtolower( substr( $src, 0, 6 ) );
		if ( str_starts_with( $head, 'https:' ) || str_starts_with( $head, 'http:' ) ) {
			$rest = substr( $src, str_starts_with( $head, 'https:' ) ? 6 : 5 );
			return 'https://' . ltrim( $rest, '/' );
		}
		return str_starts_with( $src, '//' ) ? 'https://' . ltrim( $src, '/' ) : $src;
	}

	/**
	 * Lit une adresse : null si elle ne concerne pas le filtre, label vide si elle est http(s) sans hôte analysable,
	 * sinon l'adresse en https et le nom du service.
	 *
	 * @param string $raw Valeur de l'attribut.
	 * @return array{src: string, label: string}|null
	 */
	private static function resolve( string $raw ): ?array {
		$src = self::normalize( $raw );
		if ( ! str_starts_with( $src, 'https://' ) ) {
			return null;
		}
		$parts = wp_parse_url( $src );
		$host  = is_array( $parts ) ? self::host_of( (string) ( $parts['host'] ?? '' ) ) : '';
		if ( '' === $host ) {
			return array(
				'src'   => '',
				'label' => '',
			);
		}
		$raw_host = (string) ( $parts['host'] ?? '' );
		if ( 1 === preg_match( '/[^\x00-\x7F%]/', $raw_host ) || str_contains( $raw_host, '%' ) ) {
			// L'adresse conservée porte l'hôte ASCII que le navigateur atteindra, jamais la forme déguisée.
			$tail = 1 === preg_match( '~^https://[^/?\#]*(.*)$~s', $src, $m ) ? $m[1] : '/';
			$src  = 'https://' . $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $tail;
		}
		$provider = self::provider( $src );
		return null === $provider ? null : array(
			'src'   => $src,
			'label' => $provider['label'],
		);
	}

	/**
	 * Hôte tel que le navigateur le lit : décodé, en minuscules, sans point final, puis ramené en ASCII (UTS46, qui
	 * plie la pleine chasse). Sans l'extension intl, tout hôte non ASCII est écarté : l'appelant le traite comme un hôte
	 * qu'on ne sait pas vérifier.
	 *
	 * @param string $raw Hôte tel que lu dans l'adresse.
	 */
	private static function host_of( string $raw ): string {
		$host = rtrim( strtolower( rawurldecode( $raw ) ), '.' );
		if ( 1 !== preg_match( '/[^\x00-\x7F]/', $host ) ) {
			return $host;
		}
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			return '';
		}
		$ascii = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
		return false === $ascii ? '' : rtrim( strtolower( $ascii ), '.' );
	}

	/**
	 * Service reconnu pour une adresse, ou null (hôte inconnu, adresse relative ou invalide).
	 *
	 * @param string $src Adresse absolue.
	 * @return array{label: string}|null
	 */
	public static function provider( string $src ): ?array {
		$parts = wp_parse_url( $src );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		$host = self::host_of( $parts['host'] ?? '' );
		$path = $parts['path'] ?? '/';
		if ( '' === $host || strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host ) {
			return null;
		}
		/**
		 * Services dont le contenu intégré attend le consentement.
		 *
		 * @param array<mixed> $hosts Hôte (ou hôte/préfixe de chemin) => nom affiché.
		 */
		$hosts = apply_filters( 'fluffless_consent_embed_hosts', self::HOSTS );
		foreach ( (array) $hosts as $entry => $label ) {
			if ( ! is_string( $entry ) || ! is_string( $label ) ) {
				continue;
			}
			$slash  = strpos( $entry, '/' );
			$domain = false === $slash ? $entry : substr( $entry, 0, $slash );
			$prefix = false === $slash ? '' : substr( $entry, $slash );
			if ( ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) && ( '' === $prefix || str_starts_with( $path, $prefix ) ) ) {
				return array( 'label' => $label );
			}
		}
		return null;
	}

	/**
	 * Lien de lecture sans JavaScript : page publique de la vidéo quand on la connaît, sinon l'adresse intégrée.
	 *
	 * @param string $src Adresse intégrée.
	 */
	private static function open_url( string $src ): string {
		if ( 1 === preg_match( '#^https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,20})#', $src, $m ) ) {
			return 'https://www.youtube.com/watch?v=' . $m[1];
		}
		if ( 1 === preg_match( '#^https?://player\.vimeo\.com/video/(\d+)#', $src, $m ) ) {
			return 'https://vimeo.com/' . $m[1];
		}
		return $src;
	}

	/** Accroche la bannière et son script au pied de page : le contenu est rendu après wp_body_open. */
	private static function announce(): void {
		if ( false === has_action( 'wp_footer', array( Consent::class, 'print_late' ) ) ) {
			add_action( 'wp_footer', array( Consent::class, 'print_late' ), 1 );
		}
	}
}
