<?php
/**
 * Module Infolettre : un formulaire inscrit la personne dans l'outil d'infolettre du site.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\DeclaresHosts;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;
use Fluffless\Suite\Newsletter\Cyberimpact;
use Fluffless\Suite\Newsletter\Mailchimp;
use Fluffless\Suite\Newsletter\Provider;
use Fluffless\Suite\Newsletter\Reason;

defined( 'ABSPATH' ) || exit;

/**
 * Pourquoi : le modèle « Inscription à l'infolettre » ne faisait qu'un courriel au site, à
 * recopier à la main dans l'outil. La liaison passe par l'API de l'outil, côté serveur, jamais
 * par un script dans la page.
 *
 * Règles :
 * - Rien ne part sans la case de consentement désignée du formulaire, obligatoire et cochée.
 *   Une autre case (politique de confidentialité...) ne vaut jamais inscription (LCAP).
 * - Ce qui part : courriel, prénom et nom s'il y en a, langue de la page, et la preuve de
 *   consentement quand l'outil a un champ prévu. Jamais l'adresse IP.
 * - Une adresse déjà présente dans l'outil n'est jamais modifiée : sinon n'importe qui pourrait
 *   faire repasser une personne abonnée en attente, ou changer son nom.
 * - La clé se lit SEULEMENT dans la constante FLUFFLESS_NEWSLETTER_KEY (wp-config.php) ou la
 *   variable d'environnement du même nom. Jamais en base, jamais réaffichée.
 * - L'appel part après la réponse au visiteur (fastcgi_finish_request() quand le serveur
 *   l'offre), avec un plafond de 3 secondes, tous appels compris, et aucune redirection suivie.
 * - Un échec : aucune nouvelle tentative ni file d'attente. Un courriel au site regroupe les
 *   échecs, un par heure au plus ; les adresses attendent ce courriel au plus une heure, dans
 *   une option non chargée d'office, puis sont effacées. L'écran ne montre que le nombre
 *   d'échecs et la raison, tirée d'une liste fermée.
 *
 * @phpstan-import-type Contact from Provider
 * @phpstan-import-type Settings from Provider
 * @phpstan-import-type Request from Provider
 * @phpstan-import-type Definition from Form
 * @phpstan-type Pending array{time: int, email: string, reason: string}
 * @phpstan-type Failure array{time: int, reason: string}
 * @phpstan-type TestResult array{time: int, provider: string, outcome: string}
 */
final class Newsletter implements Module, DeclaresHosts {

	public const KEY_CONSTANT = 'FLUFFLESS_NEWSLETTER_KEY';
	public const ACTION       = 'fluffless_newsletter_test';
	public const CRON_HOOK    = Lifecycle::CRON_NEWSLETTER_DIGEST;
	public const DEADLINE     = 3.0;
	public const FAILURES_TTL = 30 * DAY_IN_SECONDS;
	public const MAX_FAILURES = 100;
	public const MAX_PENDING  = 200;
	public const FIELD_MAX    = 100;

	/** Outils pris en charge, dans l'ordre proposé. Cyberimpact d'abord : données au Canada. */
	public const PROVIDERS = array(
		'cyberimpact' => Cyberimpact::class,
		'mailchimp'   => Mailchimp::class,
	);

	/**
	 * Inscriptions en attente de la fin de la requête.
	 *
	 * @var list<array{contact: Contact, list: string}>
	 */
	private static array $queue = array();

	/**
	 * Fonction de fin de requête déjà posée ?
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'newsletter';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Newsletter', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'A Form block can sign people up to the site’s newsletter tool (Cyberimpact or Mailchimp) through its API, from the server, only when they check the newsletter consent box. The key stays in wp-config.php.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'provider'     => 'cyberimpact',
			'list'         => '',
			'double_optin' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Une liste de la mauvaise forme pour l'outil choisi se vide.
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		$clean    = Sanitize::section( $input, self::fields(), self::defaults() );
		$list_id  = is_string( $clean['list'] ) ? str_replace( ' ', '', trim( $clean['list'] ) ) : '';
		$provider = self::PROVIDERS[ is_string( $clean['provider'] ) ? $clean['provider'] : '' ] ?? Cyberimpact::class;
		if ( '' !== $list_id && ! $provider::valid_list( $list_id ) ) {
			$list_id = '';
		}
		$clean['list'] = $list_id;
		return $clean;
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		$options = array();
		foreach ( self::PROVIDERS as $id => $provider ) {
			$options[ $id ] = $provider::label();
		}
		return array(
			'provider'     => array(
				'type'    => 'choice',
				'label'   => __( 'Tool', 'fluffless-suite' ),
				'options' => $options,
				'help'    => __( 'Cyberimpact keeps its data in Canada, Mailchimp in the United States. The key goes in wp-config.php: define( \'FLUFFLESS_NEWSLETTER_KEY\', \'…\' ); or the environment variable of the same name.', 'fluffless-suite' ),
			),
			'list'         => array(
				'type'  => 'text',
				'label' => __( 'List or group', 'fluffless-suite' ),
				'help'  => __( 'Cyberimpact: the group number, or several separated by commas. Mailchimp: the audience ID (Audience → Settings). A form can name its own in the block settings.', 'fluffless-suite' ),
			),
			'double_optin' => array(
				'type'  => 'bool',
				'label' => __( 'Double opt-in', 'fluffless-suite' ),
				'help'  => __( 'The tool emails the person a link to confirm. Without the click, they are not signed up. Recommended: it proves consent and keeps typos and fake addresses out of the list.', 'fluffless-suite' ),
			),
		);
	}

	/** État affiché sous la description : ce qui manque pour que la liaison serve. */
	public static function status(): string {
		$provider = self::provider();
		if ( '' === self::key() ) {
			return __( 'No key: add define( \'FLUFFLESS_NEWSLETTER_KEY\', \'your key\' ); to wp-config.php, or set the environment variable of the same name. Until then, nothing is sent to the tool.', 'fluffless-suite' );
		}
		if ( ! $provider::valid_key( self::key() ) ) {
			/* translators: %s: newsletter tool name */
			return sprintf( __( 'The key in wp-config.php does not look like a %s key. Nothing is sent to the tool.', 'fluffless-suite' ), $provider::label() );
		}
		if ( '' === Options::get_string( self::id(), 'list' ) ) {
			return __( 'No list or group set: only forms that name their own list send anything.', 'fluffless-suite' );
		}
		return '';
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'fluffless_form_sent', array( self::class, 'on_sent' ), 10, 2 );
		add_action( self::CRON_HOOK, array( self::class, 'send_digest' ) );
		add_action( 'admin_init', array( self::class, 'add_privacy_text' ) );
		if ( is_admin() ) {
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_test' ) );
		}
	}

	/**
	 * Classe de l'outil choisi.
	 *
	 * @return class-string<Provider>
	 */
	public static function provider(): string {
		return self::PROVIDERS[ Options::get_string( self::id(), 'provider' ) ] ?? Cyberimpact::class;
	}

	/** Clé lue dans la constante, sinon l'environnement ; jamais ailleurs. */
	private static function key(): string {
		$key = defined( self::KEY_CONSTANT ) ? constant( self::KEY_CONSTANT ) : '';
		if ( ! is_string( $key ) || '' === $key ) {
			$env = getenv( self::KEY_CONSTANT );
			$key = is_string( $env ) ? $env : '';
		}
		return trim( $key );
	}

	/**
	 * Domaine de l'outil, ajouté à la liste du module Requêtes sortantes. Vide si le module est
	 * éteint ou la clé absente ou mal formée.
	 */
	public static function host(): string {
		if ( ! Options::is_enabled( self::id() ) ) {
			return '';
		}
		$provider = self::provider();
		$key      = self::key();
		return $provider::valid_key( $key ) ? $provider::host( $key ) : '';
	}

	// ----- Inscription ------------------------------------------------------------------------

	/**
	 * Après l'envoi réussi d'un formulaire : met l'inscription en attente de la fin de la requête.
	 *
	 * @param mixed $definition Définition du formulaire.
	 * @param mixed $values     Réponses validées.
	 */
	public static function on_sent( $definition, $values ): void {
		if ( ! is_array( $definition ) || ! is_array( $values ) ) {
			return;
		}
		/**
		 * Définition transmise par Form::send().
		 *
		 * @var Definition $definition
		 * @var array<string, string|list<string>> $values
		 */
		$contact = self::contact( $definition, $values );
		if ( null === $contact ) {
			return;
		}
		self::$queue[] = array(
			'contact' => $contact,
			'list'    => $definition['newsletter_list'],
		);
		if ( ! self::$hooked ) {
			self::$hooked = true;
			register_shutdown_function( array( self::class, 'finish' ) );
		}
	}

	/**
	 * La personne à inscrire, ou null si rien ne doit partir : liaison éteinte sur ce formulaire,
	 * case de consentement non désignée, non obligatoire ou non cochée, adresse absente.
	 *
	 * @param array<string, mixed>               $definition Définition.
	 * @param array<string, string|list<string>> $values     Réponses validées.
	 * @phpstan-param Definition $definition
	 * @return Contact|null
	 */
	public static function contact( array $definition, array $values ): ?array {
		if ( ! $definition['newsletter'] || '' === $definition['newsletter_consent'] ) {
			return null;
		}
		$consent = null;
		foreach ( $definition['fields'] as $field ) {
			if ( $field['name'] === $definition['newsletter_consent'] ) {
				$consent = $field;
				break;
			}
		}
		if ( null === $consent || 'consent' !== $consent['type'] ) {
			return null;
		}
		$checked = $values[ $consent['name'] ] ?? '';
		if ( ! is_string( $checked ) || '' === $checked ) {
			return null;
		}
		$email = Form::sender( $definition, $values );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}
		$path = Form::request_target(); // Avec la requête en permaliens simples, sinon le chemin seul.
		return array(
			'email'    => $email,
			'first'    => self::name_field( $definition, $values, 'given-name', array( 'prenom', 'first_name', 'firstname' ) ),
			'last'     => self::last_name( $definition, $values ),
			'language' => determine_locale(),
			'consent'  => $consent['label'],
			'page'     => home_url( '' === $path ? '/' : $path ),
			'time'     => time(),
		);
	}

	/**
	 * Prénom ou nom : le champ dont l'autocomplete correspond, sinon le premier aux noms connus.
	 *
	 * @param array<string, mixed>               $definition   Définition.
	 * @param array<string, string|list<string>> $values       Réponses.
	 * @param string                             $autocomplete given-name ou family-name.
	 * @param string[]                           $names        Noms de champ reconnus.
	 * @phpstan-param Definition $definition
	 * @phpstan-param list<string> $names
	 */
	private static function name_field( array $definition, array $values, string $autocomplete, array $names ): string {
		$found = '';
		foreach ( $definition['fields'] as $field ) {
			if ( $autocomplete === $field['autocomplete'] ) {
				$found = $field['name'];
				break;
			}
			if ( '' === $found && in_array( $field['name'], $names, true ) ) {
				$found = $field['name'];
			}
		}
		$value = '' === $found ? '' : ( $values[ $found ] ?? '' );
		return is_string( $value ) ? mb_substr( trim( $value ), 0, self::FIELD_MAX ) : '';
	}

	/**
	 * Nom de famille : l'autocomplete family-name, sinon un champ « nom » seulement quand le
	 * formulaire a aussi un prénom (seul, « nom » contient souvent le nom complet).
	 *
	 * @param array<string, mixed>               $definition Définition.
	 * @param array<string, string|list<string>> $values     Réponses.
	 * @phpstan-param Definition $definition
	 */
	private static function last_name( array $definition, array $values ): string {
		$names = array( 'last_name', 'lastname' );
		foreach ( $definition['fields'] as $field ) {
			if ( 'given-name' === $field['autocomplete'] || in_array( $field['name'], array( 'prenom', 'first_name', 'firstname' ), true ) ) {
				$names[] = 'nom';
				break;
			}
		}
		return self::name_field( $definition, $values, 'family-name', $names );
	}

	/** Fin de la requête : la réponse part au visiteur, puis les appels à l'outil. */
	public static function finish(): void {
		if ( array() === self::$queue ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		self::flush();
	}

	/** Envoie les inscriptions en attente, tout de suite (tests, fin de requête). */
	public static function flush(): void {
		$queue       = self::$queue;
		self::$queue = array();
		foreach ( $queue as $item ) {
			$outcome = self::subscribe( $item['contact'], $item['list'] );
			if ( Provider::DONE !== $outcome ) {
				self::record_failure( $item['contact']['email'], $outcome );
			}
		}
	}

	/**
	 * Inscrit une personne. Rend Provider::DONE ou une raison de Reason.
	 *
	 * @param array<string, mixed> $contact Personne à inscrire.
	 * @param string               $list_id    Liste propre au formulaire, ou vide pour celle du module.
	 * @phpstan-param Contact $contact
	 */
	public static function subscribe( array $contact, string $list_id = '' ): string {
		$provider = self::provider();
		$settings = self::settings( $list_id );
		if ( null === $settings ) {
			return Reason::LIST;
		}
		if ( ! $provider::valid_key( $settings['key'] ) ) {
			return Reason::KEY;
		}
		$deadline = microtime( true ) + self::DEADLINE;
		foreach ( $provider::subscribe( $contact, $settings ) as $request ) {
			$outcome = self::call( $provider, $request, $deadline );
			if ( 'consent' === $request['step'] && ! in_array( $outcome, array( Provider::NEXT, Provider::DONE ), true ) ) {
				return Reason::NO_PROOF; // Personne déjà ajoutée : seule la preuve manque.
			}
			if ( Provider::NEXT !== $outcome ) {
				return $outcome;
			}
		}
		return Provider::DONE;
	}

	/**
	 * Réglages d'un envoi : la liste du formulaire si elle est donnée, sinon celle du module.
	 * Null si la liste retenue manque ou n'a pas la forme attendue par l'outil.
	 *
	 * @param string $list_id Liste propre au formulaire.
	 * @return Settings|null
	 */
	private static function settings( string $list_id ): ?array {
		$provider = self::provider();
		$list_id  = '' !== $list_id ? $list_id : Options::get_string( self::id(), 'list' );
		if ( '' === $list_id || ! $provider::valid_list( $list_id ) ) {
			return null;
		}
		return array(
			'list'         => $list_id,
			'double_optin' => Options::get_bool( self::id(), 'double_optin' ),
			'key'          => self::key(),
		);
	}

	/**
	 * Une requête à l'outil, dans le temps qui reste. Rend NEXT, DONE ou une raison.
	 *
	 * @param string               $provider Outil.
	 * @param array<string, mixed> $request  Requête.
	 * @param float                $deadline Instant limite (microtime).
	 * @phpstan-param class-string<Provider> $provider
	 * @phpstan-param Request $request
	 */
	private static function call( string $provider, array $request, float $deadline ): string {
		$left = $deadline - microtime( true );
		if ( $left < 0.2 ) {
			return Reason::UNREACHABLE;
		}
		if ( ! str_starts_with( $request['url'], 'https://' ) ) {
			return Reason::KEY;
		}
		$args = array(
			'method'      => $request['method'],
			'headers'     => $request['headers'],
			'timeout'     => $left,
			'redirection' => 0,
			'user-agent'  => 'Fluffless Suite; ' . home_url( '/' ),
		);
		if ( '' !== $request['body'] ) {
			$args['body'] = $request['body'];
		}
		$response = wp_safe_remote_request( $request['url'], $args );
		if ( is_wp_error( $response ) ) {
			return 'fluffless_http_blocked' === $response->get_error_code() ? Reason::BLOCKED : Reason::UNREACHABLE;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $provider::outcome( $request['step'], (int) wp_remote_retrieve_response_code( $response ), is_array( $body ) ? $body : array() );
	}

	// ----- Échecs -----------------------------------------------------------------------------

	/**
	 * Note un échec : la raison pour l'écran, l'adresse pour le prochain courriel au site.
	 *
	 * @param string $email  Adresse.
	 * @param string $reason Raison de Reason.
	 */
	public static function record_failure( string $email, string $reason ): void {
		$reason = in_array( $reason, Reason::ALL, true ) ? $reason : Reason::OTHER;
		$now    = time();

		$failures = self::failures();
		array_unshift(
			$failures,
			array(
				'time'   => $now,
				'reason' => $reason,
			)
		);
		set_transient( Lifecycle::TRANSIENT_NEWSLETTER_FAILURES, array_slice( $failures, 0, self::MAX_FAILURES ), self::FAILURES_TTL );

		$pending   = self::pending();
		$pending[] = array(
			'time'   => $now,
			'email'  => $email,
			'reason' => $reason,
		);
		update_option( Lifecycle::OPTION_NEWSLETTER_PENDING, array_slice( $pending, -self::MAX_PENDING ), false );

		if ( false === get_transient( Lifecycle::TRANSIENT_NEWSLETTER_LOCK ) ) {
			self::send_digest();
		} elseif ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( $now + HOUR_IN_SECONDS, self::CRON_HOOK );
		}
	}

	/**
	 * Échecs des 30 derniers jours, du plus récent au plus ancien. Jamais d'adresse.
	 *
	 * @return list<Failure>
	 */
	public static function failures(): array {
		$stored = get_transient( Lifecycle::TRANSIENT_NEWSLETTER_FAILURES );
		$since  = time() - self::FAILURES_TTL;
		$kept   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['time'], $entry['reason'] ) && is_int( $entry['time'] ) && $entry['time'] >= $since && in_array( $entry['reason'], Reason::ALL, true ) ) {
				$kept[] = array(
					'time'   => $entry['time'],
					'reason' => (string) $entry['reason'],
				);
			}
		}
		return $kept;
	}

	/**
	 * Adresses en attente du prochain courriel au site.
	 *
	 * @return list<Pending>
	 */
	public static function pending(): array {
		$stored  = get_option( Lifecycle::OPTION_NEWSLETTER_PENDING, array() );
		$entries = array();
		foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['time'], $entry['email'], $entry['reason'] ) && is_int( $entry['time'] ) && is_string( $entry['email'] ) && is_string( $entry['reason'] ) ) {
				$entries[] = array(
					'time'   => $entry['time'],
					'email'  => $entry['email'],
					'reason' => $entry['reason'],
				);
			}
		}
		return $entries;
	}

	/** Un courriel au site avec les échecs en attente, puis plus rien n'est gardé ; pause d'une heure. */
	public static function send_digest(): void {
		$pending = self::pending();
		delete_option( Lifecycle::OPTION_NEWSLETTER_PENDING );
		if ( array() === $pending ) {
			return;
		}
		set_transient( Lifecycle::TRANSIENT_NEWSLETTER_LOCK, 1, HOUR_IN_SECONDS );
		$tool   = self::provider()::label();
		$site   = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		$lines  = array();
		foreach ( $pending as $entry ) {
			$date    = wp_date( $format, $entry['time'] );
			$lines[] = '- ' . $entry['email'] . ' (' . Reason::text( $entry['reason'] ) . ', ' . ( false === $date ? '' : $date ) . ')';
		}
		$body = sprintf(
			/* translators: %s: newsletter tool name */
			__( 'These newsletter sign-ups from the site’s forms did not fully reach %s:', 'fluffless-suite' ),
			$tool
		) . "\n\n" . implode( "\n", $lines ) . "\n\n" . sprintf(
			/* translators: %s: newsletter tool name */
			__( 'Invite these people to confirm their sign-up from %s. Do not add them to the list yourself without their confirmation.', 'fluffless-suite' ),
			$tool
		) . "\n\n" . sprintf(
			/* translators: %s: address of the settings page */
			__( 'Settings and connection test: %s', 'fluffless-suite' ),
			SettingsPage::url( 'fl-title-' . self::id() )
		);
		$sent = wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: 1: site name, 2: newsletter tool name */
			sprintf( __( '[%1$s] Newsletter sign-ups not sent to %2$s', 'fluffless-suite' ), $site, $tool ),
			$body,
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
		// Courriel refusé : les adresses restent pour un nouvel essai dans une heure, un jour au plus.
		$pending = array_values( array_filter( $pending, static fn( array $entry ): bool => $entry['time'] >= time() - DAY_IN_SECONDS ) );
		if ( ! $sent && array() !== $pending ) {
			update_option( Lifecycle::OPTION_NEWSLETTER_PENDING, $pending, false );
			if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::CRON_HOOK );
			}
		}
	}

	// ----- Test de connexion ------------------------------------------------------------------

	/** Bouton « Tester la connexion » : vérifie, appelle l'outil en lecture seule, revient aux réglages. */
	public static function handle_test(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to test the newsletter connection.', 'fluffless-suite' ), 403 );
		}
		check_admin_referer( self::ACTION );
		self::test();
		wp_safe_redirect( SettingsPage::url( 'fl-title-' . self::id() ) );
		exit;
	}

	/**
	 * Appel en lecture seule : la clé est-elle acceptée, la liste existe-t-elle ? N'inscrit personne.
	 *
	 * @return TestResult
	 */
	public static function test(): array {
		$provider = self::provider();
		$settings = self::settings( '' );
		if ( null === $settings ) {
			$outcome = Reason::LIST;
		} elseif ( ! $provider::valid_key( $settings['key'] ) ) {
			$outcome = Reason::KEY;
		} else {
			$outcome = self::call( $provider, $provider::check( $settings ), microtime( true ) + self::DEADLINE );
			$outcome = Provider::NEXT === $outcome ? Provider::DONE : $outcome;
		}
		$result = array(
			'time'     => time(),
			'provider' => $provider::id(),
			'outcome'  => $outcome,
		);
		set_transient( Lifecycle::TRANSIENT_NEWSLETTER_TEST, $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Résultat du dernier test, s'il date de moins d'une heure.
	 *
	 * @return TestResult|null
	 */
	public static function last_test(): ?array {
		$stored = get_transient( Lifecycle::TRANSIENT_NEWSLETTER_TEST );
		if ( ! is_array( $stored ) || ! isset( $stored['time'], $stored['provider'], $stored['outcome'] ) || ! is_int( $stored['time'] ) || ! is_string( $stored['provider'] ) || ! is_string( $stored['outcome'] ) ) {
			return null;
		}
		return array(
			'time'     => $stored['time'],
			'provider' => $stored['provider'],
			'outcome'  => $stored['outcome'],
		);
	}

	/** Panneau dans les réglages : dernier test, bouton de test, échecs des 30 derniers jours. */
	public static function render_panel(): void {
		$test = self::last_test();
		if ( null !== $test && isset( self::PROVIDERS[ $test['provider'] ] ) ) {
			$tool = self::PROVIDERS[ $test['provider'] ]::label();
			if ( Provider::DONE === $test['outcome'] ) {
				/* translators: %s: newsletter tool name */
				echo Screen::note( 'good', sprintf( __( '%s answered: the key is accepted and the list exists. Nobody was signed up.', 'fluffless-suite' ), $tool ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			} else {
				/* translators: 1: newsletter tool name, 2: reason of the failure */
				echo Screen::note( 'bad', sprintf( __( 'Connection to %1$s failed: %2$s.', 'fluffless-suite' ), $tool, Reason::text( $test['outcome'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			}
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <span class="fl-muted">%3$s</span></p>',
			esc_url( $url ),
			esc_html__( 'Test the connection', 'fluffless-suite' ),
			esc_html__( 'with the saved settings, read only.', 'fluffless-suite' )
		);
		$failures = self::failures();
		if ( array() === $failures ) {
			return;
		}
		$message = sprintf(
				/* translators: 1: number of failed sign-ups, 2: reason of the last failure */
			_n( '%1$s sign-up was not sent to the tool in the last 30 days. Last reason: %2$s. The site’s administration address received the details.', '%1$s sign-ups were not sent to the tool in the last 30 days. Last reason: %2$s. The site’s administration address received the details.', count( $failures ), 'fluffless-suite' ),
			number_format_i18n( count( $failures ) ),
			Reason::text( $failures[0]['reason'] )
		);
		echo Screen::note( 'warn', $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
	}

	// ----- Politique de confidentialité -------------------------------------------------------

	/** Paragraphe proposé pour la politique de confidentialité. */
	public static function add_privacy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Fluffless Suite: newsletter', 'fluffless-suite' ), wpautop( esc_html( self::privacy_text() ) ) );
		}
	}

	/** Texte de la mention, selon l'outil choisi. */
	public static function privacy_text(): string {
		$provider = self::provider();
		$double   = Options::get_bool( self::id(), 'double_optin' );
		$text     = array(
			sprintf(
				/* translators: 1: newsletter tool name, 2: where the tool stores data, with its preposition (in Canada) */
				__( 'When you sign up for the newsletter with a form on this site and check the consent box, your email address, your first and last name if you give them and the language of the page are sent to %1$s, the newsletter tool of this site, which stores them %2$s.', 'fluffless-suite' ),
				$provider::label(),
				$provider::country()
			),
		);
		if ( Mailchimp::id() === $provider::id() ) {
			$text[] = __( 'The date and time of your sign-up are also sent.', 'fluffless-suite' );
		} elseif ( ! $double ) {
			$text[] = __( 'The date of your consent, the wording of the box and the address of the page are also sent, as proof of consent.', 'fluffless-suite' );
		}
		if ( $double ) {
			/* translators: %s: newsletter tool name */
			$text[] = sprintf( __( '%s emails you a link to confirm. Without your click, you are not signed up.', 'fluffless-suite' ), $provider::label() );
		}
		$text[] = __( 'If the newsletter tool cannot be reached, your email address waits on this site for a day at most and is emailed to the site administrator, who can invite you to confirm your sign-up.', 'fluffless-suite' );
		$text[] = __( 'Your IP address is not sent. Each newsletter has an unsubscribe link.', 'fluffless-suite' );
		return implode( ' ', $text );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function hosts(): array {
		$host = self::host();
		if ( '' === $host ) {
			return array();
		}
		return array(
			array(
				'host'    => $host,
				'service' => __( 'Newsletter', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'action',
				'data'    => __( 'Email address, name if asked, page language, after the consent box is checked', 'fluffless-suite' ),
			),
		);
	}
}
