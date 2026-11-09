<?php
/**
 * Bannière de consentement native, affichée seulement si un service non essentiel est déclaré.
 *
 * @package Fluffless_Consent
 */

declare( strict_types=1 );

namespace Fluffless\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Aucun service déclaré : aucun octet ajouté. Sinon, la page reçoit une bannière HTML,
 * quelques lignes de CSS en ligne et un script en defer. Le HTML ne contient aucune
 * balise des services : consent.js les injecte après un oui, catégorie par catégorie.
 *
 * Déclarer un service (thème ou extension) :
 * add_filter( 'fluffless_consent_services', fn( $s ) => $s + array( 'youtube' => array(
 *     'category' => 'media', 'label' => 'YouTube', 'location' => 'United States',
 *     'src' => 'https://…', // facultatif, chargé après consentement.
 * ) ) );
 * Un script inerte <script type="text/plain" data-fluffless-consent="media"> est aussi activé après un oui,
 * et l'événement DOM « fluffless:consent » annonce les catégories acceptées.
 *
 * Déclarer un réglage d'affichage gardé dans le navigateur, sans bannière (réservé à un choix
 * fait par la personne qui visite, jamais à un identifiant ou à du suivi) :
 * add_filter( 'fluffless_consent_storage', fn( $s ) => $s + array( 'cle-locale' => array( 'label' => 'Taille du texte' ) ) );
 * La clé est listée dans la section proposée de la politique de confidentialité.
 */
final class Consent {

	public const STORAGE_KEY  = 'fluffless-consent';
	public const MAX_SERVICES = 20;
	public const SHORTCODE    = 'fluffless_consent_link';

	/** Balise de Google Analytics injectée par consent.js après un oui. */
	public const GA_SCRIPT = 'https://www.googletagmanager.com/gtag/js';

	/** Option posée dès qu'un contenu publié porte un contenu intégré de tiers (service implicite « media »). */
	public const EMBEDS_OPTION = 'fluffless_consent_embeds';

	private const HANDLE = 'fluffless-consent';

	/** Longueur maximale d'un libellé ou d'une adresse de service. */
	private const MAX_TEXT = 200;

	/**
	 * La bannière est imprimée une fois par page (wp_body_open, ou wp_footer en repli).
	 *
	 * @var bool
	 */
	private static bool $banner_printed = false;

	/**
	 * Le lien « Gérer mes témoins » a été rendu par le code court ?
	 *
	 * @var bool
	 */
	private static bool $link_rendered = false;

	/**
	 * Profils juridiques.
	 *
	 * @return array<string, string>
	 */
	public static function profiles(): array {
		return array(
			'quebec' => __( 'Quebec (Law 25)', 'fluffless-consent' ),
			'canada' => __( 'Rest of Canada (PIPEDA)', 'fluffless-consent' ),
			'europe' => __( 'European Union (GDPR)', 'fluffless-consent' ),
		);
	}

	/**
	 * Catégories de services non essentiels.
	 *
	 * @return array<string, string>
	 */
	public static function categories(): array {
		return array(
			'analytics' => __( 'Audience measurement', 'fluffless-consent' ),
			'marketing' => __( 'Advertising', 'fluffless-consent' ),
			'media'     => __( 'Embedded content', 'fluffless-consent' ),
		);
	}

	/**
	 * Identifiant GA4 valide, ou ''.
	 *
	 * @param string $raw Saisie.
	 */
	public static function ga_id( string $raw ): string {
		$id = strtoupper( trim( $raw ) );
		return 1 === preg_match( '/^G-[A-Z0-9]{4,15}$/D', $id ) ? $id : '';
	}

	/**
	 * Services déclarés, validés : Google Analytics (réglage) puis le filtre fluffless_consent_services.
	 *
	 * @return array<string, array{category: string, label: string, location: string, src: string, ga: string}>
	 */
	public static function services(): array {
		$valid = self::declared();
		if ( false !== get_option( self::EMBEDS_OPTION, false ) || Embeds::found() ) {
			$valid['embedded-content'] = array(
				'category' => 'media',
				'label'    => __( 'Videos, maps and other external content', 'fluffless-consent' ),
				'location' => '',
				'src'      => '',
				'ga'       => '',
			);
		}
		return $valid;
	}

	/**
	 * Services déclarés par le réglage et le filtre, sans le service implicite des contenus intégrés.
	 *
	 * @return array<string, array{category: string, label: string, location: string, src: string, ga: string}>
	 */
	private static function declared(): array {
		$services = array();
		$ga       = Settings::all()['ga_id'];
		if ( '' !== $ga ) {
			$services['google-analytics'] = array(
				'category' => 'analytics',
				'label'    => 'Google Analytics',
				'location' => __( 'United States', 'fluffless-consent' ),
				'ga'       => $ga,
			);
		}
		/**
		 * Services non essentiels soumis au consentement.
		 *
		 * @param array<mixed> $services Identifiant => array{category: analytics|marketing|media, label: string, location?: string, src?: https string}.
		 */
		$filtered = apply_filters( 'fluffless_consent_services', $services );

		$valid      = array();
		$categories = self::categories();
		// (array) : un filtre tiers peut rendre n'importe quoi ; chaque entrée est validée ensuite.
		foreach ( array_slice( (array) $filtered, 0, self::MAX_SERVICES, true ) as $id => $service ) {
			$key = is_string( $id ) ? sanitize_key( $id ) : '';
			if ( '' === $key || ! is_array( $service ) || ! is_string( $service['category'] ?? null ) || ! isset( $categories[ $service['category'] ] ) ) {
				continue;
			}
			$label = self::text( $service['label'] ?? null );
			if ( '' === $label ) {
				continue;
			}
			$valid[ $key ] = array(
				'category' => $service['category'],
				'label'    => $label,
				'location' => self::text( $service['location'] ?? '' ),
				'src'      => self::https_url( $service['src'] ?? '' ),
				'ga'       => is_string( $service['ga'] ?? null ) ? self::ga_id( $service['ga'] ) : '',
			);
		}
		return $valid;
	}

	/**
	 * Déclare à l'écran Serveurs contactés de Fluffless Suite (filtre fluffless_outgoing_hosts) chaque
	 * service dont le navigateur charge une adresse connue, après un oui : la balise de Google Analytics
	 * (consent.js) ou le src déclaré. Un service sans adresse (script inerte, contenus intégrés) n'a pas
	 * de domaine fixe et n'y figure pas ; les intégrations sont déjà déclarées par la Suite.
	 *
	 * @param mixed $entries Entrées déjà déclarées.
	 * @return mixed
	 */
	public static function outgoing_hosts( $entries ) {
		if ( ! is_array( $entries ) ) {
			return $entries;
		}
		foreach ( self::services() as $service ) {
			$src  = '' !== $service['ga'] ? self::GA_SCRIPT : $service['src'];
			$host = '' === $src ? '' : (string) wp_parse_url( $src, PHP_URL_HOST );
			if ( '' === $host ) {
				continue;
			}
			$entries[] = array(
				'host'     => strtolower( $host ),
				'service'  => $service['label'],
				'side'     => 'browser',
				'when'     => 'after_consent',
				'data'     => __( 'The IP address and browser details of each visitor who says yes', 'fluffless-consent' ),
				'active'   => true,
				'location' => $service['location'],
			);
		}
		return $entries;
	}

	/** Branché sur « wp » : enregistre la bannière côté visiteur si au moins un service est déclaré. */
	public static function start_if_needed(): void {
		if ( array() !== self::services() ) {
			self::register();
		}
	}

	/** État affiché dans les réglages : actif ou non, et ce que ça coûte. */
	public static function status(): string {
		$services = self::services();
		if ( array() === $services ) {
			return __( 'Off: no non-essential service is declared, so no banner is shown.', 'fluffless-consent' );
		}
		$status = sprintf(
			/* translators: 1: number of services, 2: list of service names */
			_n( 'On automatically: %1$d non-essential service is declared (%2$s).', 'On automatically: %1$d non-essential services are declared (%2$s).', count( $services ), 'fluffless-consent' ),
			count( $services ),
			implode( ', ', array_column( $services, 'label' ) )
		) . ' ' . __( 'Cost: about 2 KB of JavaScript for the banner. Google Analytics then adds tens of KB and requests to Google servers, only after a yes.', 'fluffless-consent' );
		if ( 'quebec' === self::profile() ) {
			$status .= ' ' . __( 'Law 25: a privacy impact assessment is required before personal information leaves Quebec.', 'fluffless-consent' );
		}
		return $status;
	}

	/** Crochets de la bannière, côté visiteur. */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_body_open', array( self::class, 'print_banner' ), 1 );
		// Repli pour les thèmes qui n'appellent pas wp_body_open.
		add_action( 'wp_footer', array( self::class, 'print_banner' ), 1 );
		add_action( 'wp_footer', array( self::class, 'print_manage_fallback' ), 2 );
	}

	/**
	 * Déclare au tableau de bord de Fluffless Suite le script de la bannière, servi seulement
	 * quand un service soumis au consentement est déclaré (voir enqueue()).
	 *
	 * @param mixed $scripts Déclarations déjà posées par les autres composants.
	 * @return array<string, list<string|int>>
	 */
	public static function declare_visitor_scripts( $scripts ): array {
		$scripts = is_array( $scripts ) ? $scripts : array();
		if ( array() !== self::services() ) {
			$scripts['consent'] = array( dirname( FILE ) . '/assets/consent/consent.js' );
		}
		return $scripts;
	}

	/** Code court toujours déclaré : sans service, il rend '' au lieu d'afficher [fluffless_consent_link] en clair. */
	public static function register_shortcode(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
	}

	/** Profil juridique valide. */
	private static function profile(): string {
		$profile = Settings::all()['profile'];
		return isset( self::profiles()[ $profile ] ) ? $profile : 'quebec';
	}

	/** CSS en ligne (sans requête) et script en defer, seulement si un service est déclaré. */
	public static function enqueue(): void {
		if ( array() === self::services() ) {
			return;
		}
		wp_register_style( self::HANDLE, false, array(), VERSION );
		wp_add_inline_style( self::HANDLE, self::css() );
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/consent/consent.js', FILE ),
			array(),
			VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	/**
	 * Couleurs du thème (jetons), couleurs système en repli. Aucune animation :
	 * prefers-reduced-motion n'a rien à couper. Boutons de même taille et de même style.
	 */
	public static function css(): string {
		return '.fluffless-consent{position:fixed;inset:auto 1rem 1rem;z-index:100000;box-sizing:border-box;max-width:40rem;max-height:calc(100vh - 2rem);margin-inline:auto;padding:1.25rem;overflow:auto;'
			. 'background:var(--wp--preset--color--base,Canvas);color:var(--wp--preset--color--contrast,CanvasText);border:2px solid currentColor;border-radius:.5rem}'
			. '.fluffless-consent h2{margin:0 0 .5rem;font-size:1.25rem}.fluffless-consent p{margin:0 0 .75rem}'
			. '.fluffless-consent fieldset{margin:0 0 .75rem;padding:0;border:0}.fluffless-consent legend{font-weight:700}'
			. '.fluffless-consent label{display:flex;gap:.5rem;align-items:center;min-height:44px}.fluffless-consent input{width:1.25rem;height:1.25rem;margin:0}'
			. '.fluffless-consent__actions{display:flex;flex-wrap:wrap;gap:.5rem}'
			. '.fluffless-consent__actions button{flex:1 1 10rem;min-height:44px;padding:.5rem 1rem;font:inherit;color:inherit;background:transparent;border:2px solid currentColor;border-radius:.25rem;cursor:pointer}'
			. '.fluffless-consent-link{min-height:44px;padding:.5rem 0;font:inherit;color:inherit;background:none;border:0;text-decoration:underline;cursor:pointer}'
			. '.fluffless-embed{box-sizing:border-box;display:flex;flex-direction:column;justify-content:center;min-height:12rem;max-width:100%;margin-block:1rem;padding:1rem;border:2px solid currentColor;border-radius:.5rem}'
			. '.fluffless-embed:has(> iframe){min-height:0;padding:0;border:0}.fluffless-embed p{margin:0 0 .5rem}.fluffless-embed iframe{width:100%;border:0}.fluffless-embed[style] iframe{height:100%}.fluffless-embed__actions{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center}'
			. '.fluffless-embed button{min-height:44px;padding:.5rem 1rem;font:inherit;color:inherit;background:transparent;border:2px solid currentColor;border-radius:.25rem;cursor:pointer}'
			. '.fluffless-embed a{display:inline-flex;align-items:center;min-height:44px}.fluffless-embed :focus-visible{outline:3px solid currentColor;outline-offset:2px}'
			. '.fluffless-consent :focus-visible,.fluffless-consent h2:focus,.fluffless-consent-link:focus-visible{outline:3px solid currentColor;outline-offset:2px}'
			// Petit écran : au plus 40 % de la hauteur, texte resserré, boutons sur une ligne et collés au bas
			// du panneau pour rester visibles pendant le défilement interne ; scroll-padding garde le focus hors
			// de leur bande. consent.js réserve la même hauteur au défilement de la page.
			. '@media (max-width:781.98px),(max-height:500px){'
			. '.fluffless-consent{max-height:40vh;max-height:40dvh;padding:.75rem;font-size:.875rem;line-height:1.4;scroll-padding-bottom:4.5rem}'
			. '.fluffless-consent h2{margin-bottom:.25rem;font-size:1rem}.fluffless-consent p,.fluffless-consent fieldset{margin-bottom:.5rem}'
			. '.fluffless-consent .fluffless-consent__actions{position:sticky;bottom:-.75rem;gap:.375rem;margin:0 -.75rem .5rem;padding:.5rem .75rem;background:var(--wp--preset--color--base,Canvas)}'
			. '.fluffless-consent__actions button{flex:1 1 5rem;padding:.25rem .5rem}}';
	}

	/** Imprime la bannière une fois par page. */
	public static function print_banner(): void {
		if ( self::$banner_printed ) {
			return;
		}
		$html = self::banner_html();
		if ( '' !== $html ) {
			self::$banner_printed = true;
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chaque valeur est échappée dans banner_html().
		}
	}

	/** Bannière masquée : consent.js l'affiche s'il n'y a pas de choix valide. Sans JS, aucun service ne se charge non plus. */
	public static function banner_html(): string {
		$services = self::services();
		if ( array() === $services ) {
			return '';
		}
		$months = Settings::all()['retention_months'];
		$config = array(
			'k' => self::STORAGE_KEY,
			// Nouvelle liste de services ou nouveau profil : le choix est redemandé.
			'v' => substr( md5( (string) wp_json_encode( array( self::declared(), self::profile() ) ) ), 0, 8 ),
			'd' => $months * 30,
			's' => array_values(
				array_map(
					static fn( array $s ): array => array_filter(
						array(
							'c'   => $s['category'],
							'src' => $s['src'],
							'ga'  => $s['ga'],
						)
					),
					$services
				)
			),
		);

		$choices    = '';
		$categories = self::categories();
		foreach ( array_unique( array_column( $services, 'category' ) ) as $category ) {
			$names = array();
			foreach ( $services as $service ) {
				if ( $service['category'] === $category ) {
					$names[] = '' === $service['location'] ? $service['label'] : $service['label'] . ' (' . $service['location'] . ')';
				}
			}
			$choices .= sprintf(
				'<label><input type="checkbox" data-category="%1$s"> <span>%2$s</span></label>',
				esc_attr( $category ),
				sprintf(
					/* translators: 1: consent category, such as Audience measurement, 2: services in that category */
					esc_html_x( '%1$s: %2$s', 'consent category and its services', 'fluffless-consent' ),
					'<strong>' . esc_html( $categories[ $category ] ) . '</strong>',
					esc_html( implode( ', ', $names ) )
				)
			);
		}

		$privacy = get_privacy_policy_url();
		$note    = sprintf(
			/* translators: %d: number of months */
			_n( 'Your choice is kept on this device for %d month. You can change it at any time with “Manage my cookies”.', 'Your choice is kept on this device for %d months. You can change it at any time with “Manage my cookies”.', $months, 'fluffless-consent' ),
			$months
		);
		$texts = self::banner_texts();

		return sprintf(
			'<div id="fluffless-consent" class="fluffless-consent" role="region" aria-labelledby="fluffless-consent-title" aria-describedby="fluffless-consent-text" data-config="%1$s" hidden>'
				. '<h2 id="fluffless-consent-title" tabindex="-1">%2$s</h2><p id="fluffless-consent-text">%3$s</p>'
				. '<fieldset><legend>%4$s</legend>%5$s</fieldset>'
				. '<p class="fluffless-consent__actions"><button type="button" data-consent="reject">%6$s</button><button type="button" data-consent="save">%7$s</button><button type="button" data-consent="accept">%8$s</button></p>'
				. '<p>%9$s%10$s</p></div>',
			esc_attr( (string) wp_json_encode( $config ) ),
			esc_html( $texts['title'] ),
			esc_html( $texts['intro'] ),
			esc_html__( 'Choose by category', 'fluffless-consent' ),
			$choices,
			esc_html__( 'Refuse all', 'fluffless-consent' ),
			esc_html__( 'Save my choices', 'fluffless-consent' ),
			esc_html__( 'Accept all', 'fluffless-consent' ),
			esc_html( $note ),
			'' === $privacy ? '' : ' <a href="' . esc_url( $privacy ) . '">' . esc_html__( 'Privacy policy', 'fluffless-consent' ) . '</a>'
		);
	}

	/**
	 * Titre et texte de la bannière selon le profil juridique.
	 *
	 * @return array{title: string, intro: string}
	 */
	public static function banner_texts(): array {
		$intro = match ( self::profile() ) {
			'canada' => __( 'With your consent, this site would like to use the services below. They stay off until you say yes, and refusing changes nothing else on the site. Some of them process your information outside Canada.', 'fluffless-consent' ),
			'europe' => __( 'With your consent (GDPR, art. 6(1)(a)), this site would like to use the services below. They stay off until you say yes, and refusing changes nothing else on the site. Some of them transfer your data outside the European Union.', 'fluffless-consent' ),
			default  => __( 'With your consent, this site would like to use the services below. They stay off until you say yes, and refusing changes nothing else on the site. Some of them send your information outside Quebec, where it may not be protected the same way.', 'fluffless-consent' ),
		};
		return array(
			'title' => __( 'Your choice about cookies', 'fluffless-consent' ),
			'intro' => $intro,
		);
	}

	/** Bouton « Gérer mes témoins », caché sans JavaScript (il ne ferait rien) ; consent.js le révèle et rouvre la bannière au clic. */
	private static function manage_button(): string {
		return '<button type="button" class="fluffless-consent-link" hidden data-fluffless-consent-open>' . esc_html__( 'Manage my cookies', 'fluffless-consent' ) . '</button>';
	}

	/** Code court [fluffless_consent_link], à placer dans le pied de page. Rien si aucun service. */
	public static function shortcode(): string {
		if ( array() === self::services() ) {
			return '';
		}
		self::$link_rendered = true;
		return self::manage_button();
	}

	/** Le thème n'a pas placé le code court : le lien est ajouté en bas de page, pour que le retrait reste possible. */
	public static function print_manage_fallback(): void {
		if ( self::$link_rendered || ! self::$banner_printed ) {
			return;
		}
		echo '<p class="fluffless-consent-manage">' . self::manage_button() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- libellé échappé dans manage_button().
	}

	/** Oublie l'état de la page (tests). */
	public static function reset(): void {
		self::$banner_printed = false;
		self::$link_rendered  = false;
		Embeds::reset();
	}

	/** Neutralisation des contenus intégrés, toujours active, sans lire de témoin (même HTML pour tout le monde, compatible avec les caches de page). Branchée sur « wp » : jamais dans l'administration ni l'API REST. */
	public static function start_embeds(): void {
		Embeds::register();
	}

	/**
	 * Enregistre qu'un contenu publié porte un contenu intégré : la bannière propose alors la catégorie « media » sur tout le site.
	 *
	 * @param int      $post_id Contenu enregistré.
	 * @param \WP_Post $post    Son objet.
	 */
	public static function note_embeds( int $post_id, \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status || wp_is_post_revision( $post_id ) || false !== get_option( self::EMBEDS_OPTION, false ) ) {
			return;
		}
		if ( Embeds::in_source( $post->post_content ) ) {
			update_option( self::EMBEDS_OPTION, 1, false );
		}
	}

	/**
	 * Pied de page, un contenu intégré ayant été neutralisé : la bannière et son script y sont ajoutés si le rendu
	 * précédant le contenu ne les a pas imprimés, et l'option est posée pour que le prochain rendu les place en tête.
	 */
	public static function print_late(): void {
		if ( false === get_option( self::EMBEDS_OPTION, false ) ) {
			update_option( self::EMBEDS_OPTION, 1, false );
		}
		if ( self::$banner_printed ) {
			return;
		}
		self::enqueue();
		self::print_banner();
		if ( false === has_action( 'wp_footer', array( self::class, 'print_manage_fallback' ) ) ) {
			add_action( 'wp_footer', array( self::class, 'print_manage_fallback' ), 2 );
		}
	}

	/**
	 * Réglages gardés dans le navigateur, choisis par la personne qui visite (mode sombre, etc.) :
	 * déclarés pour la politique de confidentialité, sans bannière ni consentement.
	 *
	 * @return array<string, string> Clé du stockage local => libellé.
	 */
	public static function storage(): array {
		/**
		 * Réglages du stockage local posés à la demande de la personne qui visite.
		 *
		 * @param array<mixed> $items Clé du stockage local => array{label: string}.
		 */
		$filtered = apply_filters( 'fluffless_consent_storage', array() );
		$valid    = array();
		// (array) : un filtre tiers peut rendre n'importe quoi ; chaque entrée est validée ensuite.
		foreach ( array_slice( (array) $filtered, 0, self::MAX_SERVICES, true ) as $key => $item ) {
			$label = is_array( $item ) ? self::text( $item['label'] ?? null ) : '';
			if ( is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/D', $key ) && '' !== $label ) {
				$valid[ $key ] = $label;
			}
		}
		return $valid;
	}

	/** Section proposée pour la politique de confidentialité, si un service ou un réglage est déclaré. */
	public static function add_privacy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) && ( array() !== self::services() || array() !== self::storage() ) ) {
			$title = array() === self::services() ? __( 'Fluffless Consent: settings kept in the browser', 'fluffless-consent' ) : __( 'Fluffless Consent: cookies and consent', 'fluffless-consent' );
			wp_add_privacy_policy_content( $title, self::privacy_html() );
		}
	}

	/** Texte proposé : services, adapté au profil juridique avec la consigne EFVP pour le Québec, puis réglages du navigateur. */
	public static function privacy_html(): string {
		$html = array() === self::services() ? '' : self::services_html();
		$kept = array();
		foreach ( self::storage() as $key => $label ) {
			$kept[] = $label . ' (' . $key . ')';
		}
		if ( array() !== $kept ) {
			$html .= '<p>' . esc_html(
				sprintf(
					/* translators: %s: list of settings with their local storage key, like "Dark mode choice (fluffless-scheme)" */
					__( 'This site keeps the following display settings in the browser (local storage), only when the visitor chooses them: %s. They serve only to remember these choices and are erased by clearing this site\'s data in the browser.', 'fluffless-consent' ),
					implode( ', ', $kept )
				)
			) . '</p>';
		}
		return $html;
	}

	/** Paragraphes des services non essentiels, adaptés au profil juridique. */
	private static function services_html(): string {
		$names = array();
		foreach ( self::services() as $service ) {
			$names[] = '' === $service['location'] ? $service['label'] : $service['label'] . ' (' . $service['location'] . ')';
		}
		$base = sprintf(
			/* translators: %s: list of services */
			__( 'This site uses the following non-essential services only if you accept them: %s. Your choice is stored in your browser (local storage), not in a cookie set by this site. You can withdraw it at any time with the “Manage my cookies” link at the bottom of each page; the services then stop loading.', 'fluffless-consent' ),
			implode( ', ', $names )
		);
		$law = match ( self::profile() ) {
			'canada' => __( 'Under the Personal Information Protection and Electronic Documents Act (PIPEDA), you can access the information about you and ask for it to be corrected. You can also withdraw your consent at any time, subject to legal or contractual restrictions. Some of these services store information outside Canada.', 'fluffless-consent' ),
			'europe' => __( 'Under the GDPR, the legal basis is your consent (art. 6(1)(a)). You have the rights of access, rectification, erasure, restriction, objection and portability, and you can lodge a complaint with a supervisory authority. Some of these services transfer data outside the European Union.', 'fluffless-consent' ),
			default  => __( 'Under the Act respecting the protection of personal information in the private sector (as amended by Law 25), you can access the information about you, have it corrected and withdraw your consent. Some of these services send information outside Quebec.', 'fluffless-consent' ),
		};
		$html = '<p>' . esc_html( $base ) . '</p><p>' . esc_html( $law ) . '</p>';
		if ( 'quebec' === self::profile() ) {
			// Consigne pour la personne qui rédige la politique ; WordPress ne la copie pas avec le texte proposé.
			$html .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Before sending personal information outside Quebec, Law 25 requires a privacy impact assessment (PIA). Name the person in charge of protecting personal information and how to reach them.', 'fluffless-consent' ) . '</p>';
		}
		return $html;
	}

	/**
	 * Ligne de texte brut (balises et sauts de ligne retirés), bornée ; '' si ce n'est pas une chaîne.
	 *
	 * @param mixed $raw Valeur reçue d'un filtre tiers.
	 */
	private static function text( $raw ): string {
		return is_string( $raw ) ? mb_substr( sanitize_text_field( $raw ), 0, self::MAX_TEXT ) : '';
	}

	/**
	 * Adresse https valide, ou '' pour tout le reste (http, identifiants dans l'adresse, hôte invalide, trop longue).
	 *
	 * @param mixed $raw Valeur reçue d'un filtre tiers.
	 */
	private static function https_url( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw   = trim( $raw );
		$parts = wp_parse_url( $raw );
		$host  = is_array( $parts ) ? strtolower( $parts['host'] ?? '' ) : '';
		if ( '' === $raw || strlen( $raw ) > self::MAX_TEXT || ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || strlen( $host ) > 253
			|| 1 !== preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host ) ) {
			return '';
		}
		return esc_url_raw( $raw, array( 'https' ) );
	}
}
