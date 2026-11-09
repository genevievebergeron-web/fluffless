<?php
/**
 * Module Sécurité de base : ce qu'un site vitrine devrait avoir sans y penser.
 *
 * Quatre gestes, tous réglables : l'adresse de connexion cachée derrière un slug au choix
 * (wp-login.php et l'administration hors session répondent 404), les tentatives de connexion
 * limitées par adresse IP, les en-têtes de sécurité habituels, XML-RPC fermé. Et un journal
 * d'activité court (voir Security\Journal). Ni pare-feu ni analyse de fichiers : c'est le travail
 * de l'hébergeur, pas d'une extension.
 *
 * Aucun mot de passe n'entre jamais ici : le module ne lit ni ne garde d'identifiants. Seuls les
 * échecs de connexion gardent l'adresse IP, pour le compte des tentatives.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;
use Fluffless\Suite\Modules\Security\Journal;
use WP_Error;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Adresse de connexion cachée, tentatives limitées, en-têtes, XML-RPC fermé, journal.
 */
final class Security implements Module {

	/** Slug de l'écran Journal d'activité. */
	public const PAGE = 'fluffless-journal';

	/** Slugs de connexion refusés : ils existent déjà ou prêtent à confusion. */
	public const RESERVED_SLUGS = array( 'admin', 'login', 'dashboard', 'index', 'feed', 'xmlrpc', 'wp-admin', 'wp-login' );

	/** Longueur maximale du slug. */
	public const MAX_SLUG = 60;

	/** Préfixe des transitoires de comptage des tentatives. */
	public const LOCK_PREFIX = 'fluffless_lock_';

	/** Code d'erreur renvoyé à une adresse verrouillée. */
	public const ERROR_LOCKED = 'fluffless_locked';

	/**
	 * La requête courante vise-t-elle l'adresse de connexion cachée ?
	 *
	 * @var bool
	 */
	private static bool $on_slug = false;

	/** Identifiant du module. */
	public static function id(): string {
		return 'security';
	}

	/** Nom affiché. */
	public static function label(): string {
		return __( 'Basic security', 'fluffless-suite' );
	}

	/** Description affichée. */
	public static function description(): string {
		return __( 'Hides the login address, limits failed login attempts, sends the usual security headers, turns XML-RPC off and keeps a short activity log. No firewall and no file scan: those belong to the host.', 'fluffless-suite' );
	}

	/** Inactif sur une installation neuve. */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** Valeurs par défaut. */
	public static function defaults(): array {
		return array(
			'login_slug'   => '',
			'attempts'     => 5,
			'lockout'      => 15,
			'headers'      => true,
			'xmlrpc'       => true,
			'journal'      => true,
			'journal_days' => 90,
		);
	}

	/**
	 * Assainissement de la section : le slug passe par sanitize_slug, le reste par les champs.
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		$clean               = Sanitize::section( $input, self::fields(), self::defaults() );
		$clean['login_slug'] = self::sanitize_login_slug( (string) ( $input['login_slug'] ?? '' ) );
		return $clean;
	}

	/**
	 * Mot de connexion à garder : la saisie assainie, ou l'ancien réglage quand elle est refusée
	 * (même règle que le cœur pour une adresse courriel invalide). Le refus est dit dans les réglages.
	 *
	 * @param string $raw Valeur saisie.
	 */
	private static function sanitize_login_slug( string $raw ): string {
		$slug     = self::sanitize_slug( $raw );
		$previous = self::slug();
		if ( '' === $slug && '' !== trim( $raw, " \t\n\r/" ) ) {
			// Une saisie vidée par l'assainissement (mot réservé, préfixe wp-, balises) laisserait croire l'adresse cachée.
			self::refuse_slug( __( 'This word cannot be the login address: use letters, digits and hyphens, not a word WordPress already uses (such as admin or login) and not one that starts with “wp-”. The previous setting is kept.', 'fluffless-suite' ) );
			return $previous;
		}
		if ( '' === $slug || $slug === $previous ) {
			return $slug;
		}
		if ( self::slug_taken( $slug ) ) {
			self::refuse_slug(
				sprintf(
					/* translators: %s: word typed for the login address */
					__( '“%s” is already the address of content on this site, so it cannot be the login address. The previous setting is kept.', 'fluffless-suite' ),
					$slug
				)
			);
			return $previous;
		}
		return $slug;
	}

	/**
	 * Un contenu publié porte-t-il déjà ce chemin ? L'adresse cachée le remplacerait par le formulaire.
	 *
	 * @param string $slug Mot assaini.
	 */
	public static function slug_taken( string $slug ): bool {
		if ( '' === $slug ) {
			return false;
		}
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$post  = get_page_by_path( $slug, OBJECT, $types );
		if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
			return true;
		}
		return url_to_postid( home_url( '/' . $slug . '/' ) ) > 0;
	}

	/**
	 * Dit dans l'écran des réglages pourquoi le mot saisi n'est pas gardé.
	 *
	 * @param string $message Raison, déjà traduite.
	 */
	private static function refuse_slug( string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( Options::NAME, 'fluffless_login_slug', $message );
		}
	}

	/**
	 * Slug de connexion : minuscules, tirets, borné, jamais un chemin réservé ni un préfixe wp-.
	 *
	 * @param string $raw Valeur saisie.
	 */
	public static function sanitize_slug( string $raw ): string {
		$slug = sanitize_title( trim( $raw, " \t\n\r/" ) );
		$slug = substr( $slug, 0, self::MAX_SLUG );
		if ( '' === $slug || in_array( $slug, self::RESERVED_SLUGS, true ) || str_starts_with( $slug, 'wp-' ) ) {
			return '';
		}
		return $slug;
	}

	/** Champs des réglages. */
	public static function fields(): array {
		return array(
			'login_slug'   => array(
				'type'  => 'text',
				'label' => __( 'Login address', 'fluffless-suite' ),
				'help'  => __( 'A word after the site address, for example “entrance”: the login page then answers there, while wp-login.php and the administration (/wp-admin) answer “not found” until you log in. Empty: the usual address. The maintenance page and password-protected content never show the word, but other login links shown to visitors, such as the one under comments, lead to it. Write it down: the Suite never shows it again outside this screen. Forgot it? At your host, rename the wp-content/plugins/fluffless-suite folder: the usual address answers again.', 'fluffless-suite' ),
			),
			'attempts'     => array(
				'type'  => 'int',
				'label' => __( 'Failed attempts before a pause', 'fluffless-suite' ),
				'help'  => __( 'Counted per IP address. 0 turns the limit off.', 'fluffless-suite' ),
				'min'   => 0,
				'max'   => 20,
			),
			'lockout'      => array(
				'type'  => 'int',
				'label' => __( 'Pause, in minutes', 'fluffless-suite' ),
				'help'  => __( 'How long the address waits after too many failed attempts.', 'fluffless-suite' ),
				'min'   => 5,
				'max'   => 1440,
			),
			'headers'      => array(
				'type'  => 'bool',
				'label' => __( 'Send the usual security headers', 'fluffless-suite' ),
				'help'  => __( 'No sniffing of file types, no framing by other sites, a strict referrer policy, camera, microphone and location turned off, and a year of HTTPS once the site is served over it. The fluffless_security_headers filter adjusts the list.', 'fluffless-suite' ),
			),
			'xmlrpc'       => array(
				'type'  => 'bool',
				'label' => __( 'Turn XML-RPC off', 'fluffless-suite' ),
				'help'  => __( 'An old remote interface that mostly serves brute-force attempts today. Keep it on only if Jetpack or the WordPress mobile app needs it.', 'fluffless-suite' ),
			),
			'journal'      => array(
				'type'  => 'bool',
				'label' => __( 'Keep an activity log', 'fluffless-suite' ),
				'help'  => __( 'Logins and failures, plugins and theme activated or updated, Suite settings changed, accounts created or modified. Only failed logins keep the IP address. Content changes stay in WordPress revisions.', 'fluffless-suite' ),
			),
			'journal_days' => array(
				'type'  => 'int',
				'label' => __( 'Keep entries for, in days', 'fluffless-suite' ),
				'help'  => __( 'Older entries are removed as new ones come in.', 'fluffless-suite' ),
				'min'   => 7,
				'max'   => 365,
			),
		);
	}

	/** État affiché sous la description. */
	public static function status(): string {
		if ( ! Options::is_enabled( self::id() ) ) {
			return '';
		}
		$slug = self::slug();
		if ( '' === $slug ) {
			return __( 'The login address is the usual one. Set a word to hide it.', 'fluffless-suite' );
		}
		$status = sprintf(
			/* translators: %s: login URL */
			__( 'Login address: %s', 'fluffless-suite' ),
			home_url( '/' . $slug )
		);
		if ( self::slug_taken( $slug ) ) {
			$status .= ' ' . __( 'Content on this site uses this address: visitors see the login form there instead. Choose another word.', 'fluffless-suite' );
		}
		return $status;
	}

	/** Slug réglé, ou chaîne vide quand l'adresse n'est pas cachée. */
	public static function slug(): string {
		return self::sanitize_slug( Options::get_string( self::id(), 'login_slug' ) );
	}

	/** L'adresse de connexion est-elle cachée (module actif et mot réglé) ? */
	public static function hides_login(): bool {
		return Options::is_enabled( self::id() ) && '' !== self::slug();
	}

	/** Pose les crochets. Appelé sur plugins_loaded. */
	public function register(): void {
		if ( Options::get_bool( self::id(), 'journal' ) ) {
			Journal::hook();
			add_action(
				'admin_menu',
				static function (): void {
					Menu::add_tab( 'settings', self::PAGE, __( 'Activity log', 'fluffless-suite' ), array( Journal::class, 'render' ), 45 );
				},
				9
			);
		}
		if ( Options::get_bool( self::id(), 'headers' ) ) {
			add_action( 'send_headers', array( self::class, 'send_headers' ) );
			add_action( 'admin_init', array( self::class, 'send_headers' ) );
			add_action( 'login_init', array( self::class, 'send_headers' ) );
		}
		if ( Options::get_bool( self::id(), 'xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'wp_headers', array( self::class, 'strip_pingback' ) );
			remove_action( 'wp_head', 'rsd_link' );
		}
		if ( Options::get_int( self::id(), 'attempts' ) > 0 ) {
			add_action( 'wp_login_failed', array( self::class, 'on_failed' ) );
			add_filter( 'authenticate', array( self::class, 'check_lock' ), 30, 3 );
			add_action( 'wp_login', array( self::class, 'on_login' ), 10, 2 );
		}
		if ( '' !== self::slug() ) {
			self::hide_login();
		}
	}

	/* --- Adresse de connexion cachée. --- */

	/** Branche l'adresse cachée : détection de la requête, réécriture des liens, routage sur wp_loaded. */
	private static function hide_login(): void {
		$slug          = self::slug();
		self::$on_slug = self::request_path() === $slug;
		if ( self::$on_slug ) {
			// Le cœur lit $pagenow pour savoir qu'il est sur la page de connexion (is_login, styles, redirections).
			$GLOBALS['pagenow']     = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- c'est le but : la requête est la page de connexion.
			$_SERVER['SCRIPT_NAME'] = '/' . $slug;
		}
		add_filter( 'site_url', array( self::class, 'filter_site_url' ), 10, 3 );
		add_filter( 'network_site_url', array( self::class, 'filter_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( self::class, 'filter_redirect' ) );
		add_action( 'wp_loaded', array( self::class, 'dispatch' ), 0 );
		// Le cœur renvoie /wp-login.php et /login introuvables vers wp_login_url() : ce serait donner l'adresse cachée.
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
		add_action( 'template_redirect', array( self::class, 'redirect_admin_locations' ), 1000 );
	}

	/**
	 * Sur template_redirect : garde le raccourci /admin et /dashboard du cœur, sans ceux de la connexion.
	 */
	public static function redirect_admin_locations(): void {
		if ( ! ( is_404() && ! is_admin() ) ) {
			return;
		}
		if ( in_array( self::request_path(), array( 'admin', 'dashboard' ), true ) ) {
			wp_redirect( admin_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- même adresse que le cœur.
			exit;
		}
	}

	/**
	 * Chemin demandé, relatif à la racine du site : « entrer » pour https://site/entrer/.
	 *
	 * @param string|null $uri REQUEST_URI à examiner ; celui de la requête par défaut.
	 */
	public static function request_path( ?string $uri = null ): string {
		if ( null === $uri ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparé à un slug assaini, jamais imprimé.
		}
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = rtrim( $home, '/' );
		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return strtolower( trim( rawurldecode( $path ), '/' ) );
	}

	/**
	 * Remplace wp-login.php par l'adresse cachée dans une URL (absolue ou relative), reste inclus.
	 *
	 * @param string      $url    URL à réécrire.
	 * @param string|null $scheme Schéma demandé à site_url, ou null.
	 */
	public static function rewrite( string $url, ?string $scheme = null ): string {
		$slug = self::slug();
		if ( '' === $slug || false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}
		$position = strpos( $url, 'wp-login.php' );
		$rest     = substr( $url, (int) $position + strlen( 'wp-login.php' ) );
		if ( 1 === preg_match( '/[?&]action=postpass(?:[&#]|$)/', $rest ) ) {
			// Formulaire des contenus protégés, imprimé pour toute visiteuse : il garde wp-login.php (voir route()).
			return $url;
		}
		return home_url( '/' . $slug, $scheme ) . $rest;
	}

	/**
	 * Sur site_url et network_site_url.
	 *
	 * @param string      $url    URL calculée.
	 * @param string      $path   Chemin demandé.
	 * @param string|null $scheme Schéma demandé.
	 */
	public static function filter_site_url( $url, $path = '', $scheme = null ): string {
		unset( $path );
		return self::rewrite( (string) $url, is_string( $scheme ) ? $scheme : null );
	}

	/**
	 * Sur wp_redirect.
	 *
	 * @param string $location Destination.
	 */
	public static function filter_redirect( $location ): string {
		return self::rewrite( (string) $location );
	}

	/**
	 * Ce que la requête courante mérite : « login » (servir wp-login.php sous l'adresse cachée),
	 * « not_found » (wp-login.php direct, sauf l'envoi du mot de passe d'un contenu protégé, ou
	 * l'administration hors session), ou rien.
	 *
	 * @param string $pagenow Valeur de $pagenow au moment du test.
	 */
	public static function route( string $pagenow ): string {
		if ( self::$on_slug ) {
			return 'login';
		}
		if ( 'wp-login.php' === $pagenow ) {
			return self::is_postpass() ? '' : 'not_found';
		}
		$ajax = defined( 'DOING_AJAX' ) && constant( 'DOING_AJAX' );
		if ( is_admin() && ! $ajax && 'admin-post.php' !== $pagenow && ! is_user_logged_in() ) {
			return 'not_found';
		}
		return '';
	}

	/**
	 * Envoi du mot de passe d'un contenu protégé (POST sur wp-login.php?action=postpass), laissé au cœur.
	 * Le cœur lit l'action dans $_REQUEST, où le corps POST l'emporte sur l'adresse : chaque source
	 * présente doit valoir postpass, sinon un corps action=login servirait la connexion sur wp-login.php.
	 */
	private static function is_postpass(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- lecture de l'action du cœur, qui vérifie lui-même l'envoi.
		$sources = array( $_GET, $_POST, $_REQUEST );
		// phpcs:enable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
			return false;
		}
		foreach ( $sources as $source ) {
			if ( isset( $source['action'] ) && ( ! is_string( $source['action'] ) || 'postpass' !== $source['action'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Sur wp_loaded, priorité 0. */
	public static function dispatch(): void {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return;
		}
		$route = self::route( (string) ( $GLOBALS['pagenow'] ?? '' ) );
		if ( 'login' === $route ) {
			// wp-login.php est écrit pour la portée globale : ses variables partagées avec login_header() et login_footer() doivent l'être ici aussi.
			global $error, $interim_login, $action, $user_login;
			require_once ABSPATH . 'wp-login.php';
			exit;
		}
		if ( 'not_found' === $route ) {
			self::not_found();
		}
	}

	/**
	 * Message du 404 de l'administration hors session : une phrase neutre pour qui gère le site
	 * (un lien du cœur vers l'administration, reçu par courriel) et un lien vers l'accueil.
	 * L'adresse cachée n'y paraît jamais.
	 */
	public static function admin_not_found_message(): string {
		return '<p>' . esc_html__( 'Page not found.', 'fluffless-suite' ) . '</p>'
			. '<p>' . esc_html__( 'If you manage this site, log in through your login address, then open this link again.', 'fluffless-suite' ) . '</p>'
			. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Go to the home page', 'fluffless-suite' ) . '</a></p>';
	}

	/** Répond 404 par le gabarit du thème, sans deviner de permalien. */
	private static function not_found(): void {
		if ( is_admin() ) {
			// Le thème ne se rend pas en contexte d'administration (barre d'administration sans écran) : réponse nue.
			wp_die( wp_kses_post( self::admin_not_found_message() ), '', array( 'response' => 404 ) );
		}
		$GLOBALS['pagenow'] = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- on quitte la page de connexion.
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante du cœur, attendue par template-loader.
		}
		add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
		wp( 'error=404' );
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		require ABSPATH . 'wp-includes/template-loader.php';
		exit;
	}

	/* --- En-têtes. --- */

	/**
	 * En-têtes envoyés.
	 *
	 * @return list<string>
	 */
	public static function headers(): array {
		$headers = array(
			'X-Content-Type-Options: nosniff',
			'X-Frame-Options: SAMEORIGIN',
			'Referrer-Policy: strict-origin-when-cross-origin',
			'Permissions-Policy: camera=(), microphone=(), geolocation=()',
		);
		if ( is_ssl() ) {
			$headers[] = 'Strict-Transport-Security: max-age=31536000';
		}
		/**
		 * En-têtes de sécurité envoyés par la Suite.
		 *
		 * @param list<string> $headers Lignes « Nom: valeur ».
		 */
		$filtered = apply_filters( 'fluffless_security_headers', $headers );
		return array_values( array_filter( (array) $filtered, 'is_string' ) );
	}

	/** Sur send_headers, admin_init et login_init. */
	public static function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		foreach ( self::headers() as $header ) {
			header( $header );
		}
	}

	/**
	 * Sur wp_headers : plus de X-Pingback quand XML-RPC est fermé.
	 *
	 * @param mixed $headers En-têtes du cœur.
	 * @return array<string, string>
	 */
	public static function strip_pingback( $headers ): array {
		if ( ! is_array( $headers ) ) {
			return array();
		}
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/* --- Tentatives limitées. --- */

	/** Adresse IP de la requête, validée ; 0.0.0.0 sinon. */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Adresse IP retenue pour compter les tentatives (derrière un mandataire, par exemple).
		 *
		 * @param string $remote REMOTE_ADDR.
		 */
		$ip = apply_filters( 'fluffless_security_client_ip', $remote );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Clé du transitoire d'une adresse.
	 *
	 * @param string $ip Adresse IP.
	 */
	private static function lock_key( string $ip ): string {
		return self::LOCK_PREFIX . md5( $ip );
	}

	/**
	 * Échecs comptés pour une adresse.
	 *
	 * @param string $ip Adresse IP.
	 */
	public static function failures( string $ip ): int {
		return (int) get_transient( self::lock_key( $ip ) );
	}

	/**
	 * L'adresse est-elle en pause ?
	 *
	 * @param string $ip Adresse IP.
	 */
	public static function is_locked( string $ip ): bool {
		$attempts = Options::get_int( self::id(), 'attempts' );
		return $attempts > 0 && self::failures( $ip ) >= $attempts;
	}

	/**
	 * Sur wp_login_failed : un échec de plus, et le journal note le verrouillage quand le seuil est atteint.
	 *
	 * @param string $username Identifiant tenté.
	 */
	public static function on_failed( $username ): void {
		unset( $username );
		$ip       = self::client_ip();
		$attempts = Options::get_int( self::id(), 'attempts' );
		$count    = self::failures( $ip );
		if ( $count >= $attempts ) {
			return;
		}
		++$count;
		set_transient( self::lock_key( $ip ), $count, Options::get_int( self::id(), 'lockout' ) * MINUTE_IN_SECONDS );
		if ( $count >= $attempts ) {
			Journal::record( 'lockout', '', $ip );
		}
	}

	/**
	 * Sur authenticate : une adresse en pause n'entre pas, quels que soient les identifiants.
	 *
	 * @param mixed  $user     WP_User, WP_Error ou null.
	 * @param string $username Identifiant.
	 * @param string $password Mot de passe, jamais lu.
	 * @return mixed
	 */
	public static function check_lock( $user, $username = '', $password = '' ) {
		unset( $username, $password );
		if ( ! self::is_locked( self::client_ip() ) ) {
			return $user;
		}
		$minutes = Options::get_int( self::id(), 'lockout' );
		return new WP_Error(
			self::ERROR_LOCKED,
			sprintf(
				/* translators: %d: minutes */
				_n( 'Too many failed attempts. Try again in %d minute.', 'Too many failed attempts. Try again in %d minutes.', $minutes, 'fluffless-suite' ),
				$minutes
			)
		);
	}

	/**
	 * Sur wp_login : le compte est reparti à zéro.
	 *
	 * @param string $user_login Identifiant.
	 * @param mixed  $user       WP_User.
	 */
	public static function on_login( $user_login, $user = null ): void {
		unset( $user_login );
		if ( $user instanceof WP_User || null === $user ) {
			delete_transient( self::lock_key( self::client_ip() ) );
		}
	}
}
