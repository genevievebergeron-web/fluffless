<?php
/**
 * Module Courriel sortant : le site envoie ses courriels par un compte SMTP.
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

defined( 'ABSPATH' ) || exit;

/**
 * Pourquoi : la fonction mail() du serveur part sans authentification et finit dans les
 * pourriels, quand elle part. Relier le courriel du client est toujours compliqué ; un
 * réglage SMTP dans Suite, avec un envoi d'essai et un journal des échecs, règle le cas
 * sans extension de plus.
 *
 * Sécurité : le mot de passe se lit SEULEMENT dans la constante FLUFFLESS_SMTP_PASSWORD
 * (wp-config.php) ou la variable d'environnement du même nom. Jamais en base, jamais
 * réaffiché. Sans mot de passe, le module ne touche pas à l'envoi.
 *
 * Ce que le module pose :
 * - phpmailer_init : PHPMailer passe en SMTP avec l'hôte, le port, le chiffrement et
 *   l'identifiant des réglages ;
 * - wp_mail_from et wp_mail_from_name : expéditeur des réglages, pour tous les envois
 *   (force_from) ou seulement quand WordPress n'en a pas d'autre que wordpress@ ;
 * - wp_mail_failed : les derniers échecs gardés 30 jours, affichés dans les réglages ;
 * - admin_post_fluffless_mail_test : envoi d'essai à l'adresse de la personne connectée.
 *
 * Aucun octet côté visiteur.
 *
 * @phpstan-type Failure array{time: int, to: string, message: string}
 * @phpstan-type TestResult array{time: int, to: string, ok: bool, message: string}
 */
final class Mail implements Module, DeclaresHosts {

	public const PASSWORD_CONSTANT = 'FLUFFLESS_SMTP_PASSWORD';
	public const ACTION            = 'fluffless_mail_test';
	public const MAX_FAILURES      = 10;
	public const FAILURES_TTL      = 30 * DAY_IN_SECONDS;
	public const TIMEOUT           = 15;

	/** Chiffrement => valeur SMTPSecure de PHPMailer. */
	public const ENCRYPTION = array(
		'tls'  => 'tls',
		'ssl'  => 'ssl',
		'none' => '',
	);

	/** {@inheritDoc} */
	public static function id(): string {
		return 'mail';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Outgoing email', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Sends the site’s email (forms, orders, password resets) through an SMTP account instead of the server’s mail function, so it actually arrives. The password stays in wp-config.php.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls',
			'username'   => '',
			'from_email' => '',
			'from_name'  => '',
			'force_from' => true,
			'log'        => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * L'hôte doit être un nom de domaine et l'expéditeur une adresse valide ; sinon le champ se vide.
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		$clean = Sanitize::section( $input, self::fields(), self::defaults() );
		$host  = is_string( $clean['host'] ) ? strtolower( trim( $clean['host'] ) ) : '';
		if ( '' !== $host && ! Sanitize::is_hostname( $host ) ) {
			$host = '';
		}
		$clean['host']       = $host;
		$email               = is_string( $clean['from_email'] ) ? sanitize_email( $clean['from_email'] ) : '';
		$clean['from_email'] = is_email( $email ) ? $email : '';
		return $clean;
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array(
			'host'       => array(
				'type'  => 'text',
				'label' => __( 'SMTP server', 'fluffless-suite' ),
				'help'  => __( 'Given by your email provider or host, e.g. smtp.example.com.', 'fluffless-suite' ),
			),
			'port'       => array(
				'type'  => 'int',
				'label' => __( 'Port', 'fluffless-suite' ),
				'min'   => 1,
				'max'   => 65535,
				'help'  => __( '587 with STARTTLS, 465 with SSL/TLS.', 'fluffless-suite' ),
			),
			'encryption' => array(
				'type'    => 'choice',
				'label'   => __( 'Encryption', 'fluffless-suite' ),
				'options' => array(
					'tls'  => __( 'STARTTLS (port 587)', 'fluffless-suite' ),
					'ssl'  => __( 'SSL/TLS (port 465)', 'fluffless-suite' ),
					'none' => __( 'None (local relay only)', 'fluffless-suite' ),
				),
			),
			'username'   => array(
				'type'  => 'text',
				'label' => __( 'Username', 'fluffless-suite' ),
				'help'  => __( 'Usually the full email address. The password goes in wp-config.php: define( \'FLUFFLESS_SMTP_PASSWORD\', \'…\' ); or the environment variable of the same name.', 'fluffless-suite' ),
			),
			'from_email' => array(
				'type'  => 'text',
				'label' => __( 'Sender address', 'fluffless-suite' ),
				'help'  => __( 'An address the SMTP account is allowed to send from. Empty: WordPress keeps its own.', 'fluffless-suite' ),
			),
			'from_name'  => array(
				'type'  => 'text',
				'label' => __( 'Sender name', 'fluffless-suite' ),
			),
			'force_from' => array(
				'type'  => 'bool',
				'label' => __( 'Use this sender for every email the site sends', 'fluffless-suite' ),
				'help'  => __( 'Unchecked: only replaces the default wordpress@ address; a plugin that sets its own sender keeps it.', 'fluffless-suite' ),
			),
			'log'        => array(
				'type'  => 'bool',
				'label' => __( 'Keep the last failed sends', 'fluffless-suite' ),
				'help'  => __( 'Recipient, date and error of the last 10 failures, kept 30 days, shown here. Never the content.', 'fluffless-suite' ),
			),
		);
	}

	/** État affiché sous la description : ce qui manque pour que le SMTP serve. */
	public static function status(): string {
		if ( '' === Options::get_string( self::id(), 'host' ) ) {
			return __( 'No SMTP server set: email still goes through the server’s mail function.', 'fluffless-suite' );
		}
		if ( ! self::has_password() ) {
			return __( 'No password: add define( \'FLUFFLESS_SMTP_PASSWORD\', \'your password\' ); to wp-config.php, or set the environment variable of the same name. Until then, email goes through the server’s mail function.', 'fluffless-suite' );
		}
		return '';
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'phpmailer_init', array( self::class, 'configure' ) );
		if ( '' !== Options::get_string( self::id(), 'from_email' ) ) {
			add_filter( 'wp_mail_from', array( self::class, 'from_email' ) );
			add_filter( 'wp_mail_from_name', array( self::class, 'from_name' ) );
		}
		if ( Options::get_bool( self::id(), 'log' ) ) {
			add_action( 'wp_mail_failed', array( self::class, 'record_failure' ) );
		}
		if ( is_admin() ) {
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_test' ) );
		}
	}

	/** Hôte et mot de passe présents : le SMTP peut servir. */
	public static function ready(): bool {
		return '' !== Options::get_string( self::id(), 'host' ) && self::has_password();
	}

	/** Mot de passe présent dans la constante ou l'environnement ? */
	public static function has_password(): bool {
		return '' !== self::password();
	}

	/** Mot de passe lu dans la constante, sinon l'environnement ; jamais ailleurs. */
	private static function password(): string {
		$password = defined( self::PASSWORD_CONSTANT ) ? constant( self::PASSWORD_CONSTANT ) : '';
		if ( ! is_string( $password ) || '' === $password ) {
			$env      = getenv( self::PASSWORD_CONSTANT );
			$password = is_string( $env ) ? $env : '';
		}
		return $password;
	}

	/**
	 * Passe PHPMailer en SMTP avec les réglages. Sans hôte ou sans mot de passe : inchangé.
	 *
	 * @param mixed $mailer Instance de PHPMailer.
	 */
	public static function configure( $mailer ): void {
		if ( ! self::ready() || ! $mailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
			return;
		}
		$encryption = Options::get_string( self::id(), 'encryption' );
		$username   = Options::get_string( self::id(), 'username' );
		$mailer->isSMTP();
		$mailer->Host        = Options::get_string( self::id(), 'host' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés de PHPMailer.
		$mailer->Port        = Options::get_int( self::id(), 'port' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->SMTPSecure  = self::ENCRYPTION[ $encryption ] ?? 'tls'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->SMTPAutoTLS = 'none' !== $encryption; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->SMTPAuth    = '' !== $username; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Username    = $username; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Password    = '' !== $username ? self::password() : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Timeout     = self::TIMEOUT; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Adresse d'expédition : celle des réglages, partout (force_from) ou seulement à la place
	 * du wordpress@ par défaut de WordPress.
	 *
	 * @param string $from Adresse en cours.
	 */
	public static function from_email( $from ): string {
		$from   = (string) $from;
		$custom = Options::get_string( self::id(), 'from_email' );
		if ( '' === $custom ) {
			return $from;
		}
		return Options::get_bool( self::id(), 'force_from' ) || self::is_default_from( $from ) ? $custom : $from;
	}

	/**
	 * Nom d'expédition, même règle que l'adresse (WordPress met « WordPress » par défaut).
	 *
	 * @param string $name Nom en cours.
	 */
	public static function from_name( $name ): string {
		$name   = (string) $name;
		$custom = Options::get_string( self::id(), 'from_name' );
		if ( '' === $custom ) {
			return $name;
		}
		return Options::get_bool( self::id(), 'force_from' ) || 'WordPress' === $name ? $custom : $name;
	}

	/**
	 * Adresse par défaut de wp_mail() : wordpress@ suivi du domaine du site (sans www.).
	 *
	 * @param string $from Adresse à tester.
	 */
	public static function is_default_from( string $from ): bool {
		$host = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return strtolower( $from ) === 'wordpress@' . $host;
	}

	/**
	 * Garde le dernier échec d'envoi : destinataire, date, erreur. Jamais le contenu.
	 *
	 * @param mixed $error WP_Error de wp_mail_failed (données : to, subject, message...).
	 */
	public static function record_failure( $error ): void {
		if ( ! $error instanceof \WP_Error ) {
			return;
		}
		$data = $error->get_error_data();
		$to   = is_array( $data ) && isset( $data['to'] ) ? $data['to'] : '';
		if ( is_array( $to ) ) {
			$to = implode( ', ', array_map( 'strval', $to ) );
		}
		$failures = self::failures();
		array_unshift(
			$failures,
			array(
				'time'    => time(),
				'to'      => mb_substr( sanitize_text_field( (string) $to ), 0, 200 ),
				'message' => mb_substr( sanitize_text_field( $error->get_error_message() ), 0, 300 ),
			)
		);
		set_transient( Lifecycle::TRANSIENT_MAIL_FAILURES, array_slice( $failures, 0, self::MAX_FAILURES ), self::FAILURES_TTL );
	}

	/**
	 * Derniers échecs, du plus récent au plus ancien.
	 *
	 * @return list<Failure>
	 */
	public static function failures(): array {
		$stored = get_transient( Lifecycle::TRANSIENT_MAIL_FAILURES );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$failures = array();
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && isset( $entry['time'], $entry['to'], $entry['message'] ) && is_int( $entry['time'] ) && is_string( $entry['to'] ) && is_string( $entry['message'] ) ) {
				$failures[] = array(
					'time'    => $entry['time'],
					'to'      => $entry['to'],
					'message' => $entry['message'],
				);
			}
		}
		return $failures;
	}

	/** Oublie les échecs gardés. */
	public static function forget_failures(): void {
		delete_transient( Lifecycle::TRANSIENT_MAIL_FAILURES );
	}

	/** Bouton « Envoyer un essai » : vérifie, envoie à la personne connectée, revient aux réglages. */
	public static function handle_test(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to send a test email.', 'fluffless-suite' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$user = wp_get_current_user();
		self::send_test( (string) $user->user_email );
		wp_safe_redirect( SettingsPage::url( 'fl-title-' . self::id() ) );
		exit;
	}

	/**
	 * Envoie le courriel d'essai et garde le résultat une heure pour l'afficher.
	 *
	 * @param string $to Adresse de destination.
	 * @return TestResult
	 */
	public static function send_test( string $to ): array {
		$to     = sanitize_email( $to );
		$result = array(
			'time'    => time(),
			'to'      => $to,
			'ok'      => false,
			'message' => '',
		);
		if ( ! is_email( $to ) ) {
			$result['message'] = __( 'Your account has no valid email address.', 'fluffless-suite' );
		} else {
			$caught  = '';
			$capture = static function ( $error ) use ( &$caught ): void {
				if ( $error instanceof \WP_Error ) {
					$caught = $error->get_error_message();
				}
			};
			add_action( 'wp_mail_failed', $capture );
			/* translators: %s: site name */
			$subject = sprintf( __( 'Test email from %s', 'fluffless-suite' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) );
			$body    = self::ready()
				/* translators: %s: SMTP server host name */
				? sprintf( __( 'This message left through the SMTP server %s. If you are reading it, outgoing email works.', 'fluffless-suite' ), Options::get_string( self::id(), 'host' ) )
				: __( 'This message left through the server’s mail function, not SMTP: the module is missing a server or a password.', 'fluffless-suite' );
			$result['ok'] = wp_mail( $to, $subject, $body );
			remove_action( 'wp_mail_failed', $capture );
			$result['message'] = $result['ok'] ? '' : mb_substr( sanitize_text_field( $caught ), 0, 300 );
		}
		set_transient( Lifecycle::TRANSIENT_MAIL_TEST, $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Résultat du dernier essai, s'il date de moins d'une heure.
	 *
	 * @return TestResult|null
	 */
	public static function last_test(): ?array {
		$stored = get_transient( Lifecycle::TRANSIENT_MAIL_TEST );
		if ( ! is_array( $stored ) || ! isset( $stored['time'], $stored['to'], $stored['ok'], $stored['message'] ) || ! is_int( $stored['time'] ) || ! is_string( $stored['to'] ) || ! is_bool( $stored['ok'] ) || ! is_string( $stored['message'] ) ) {
			return null;
		}
		return array(
			'time'    => $stored['time'],
			'to'      => $stored['to'],
			'ok'      => $stored['ok'],
			'message' => $stored['message'],
		);
	}

	/** Panneau dans les réglages : dernier essai, bouton d'essai, derniers échecs. */
	public static function render_panel(): void {
		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		$test   = self::last_test();
		if ( null !== $test ) {
			$date = wp_date( $format, $test['time'] );
			$date = false === $date ? '' : $date;
			if ( $test['ok'] ) {
				/* translators: 1: email address, 2: date and time */
				echo Screen::note( 'good', sprintf( __( 'Test email sent to %1$s on %2$s. Check the inbox, and the spam folder.', 'fluffless-suite' ), $test['to'], $date ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			} else {
				/* translators: 1: email address, 2: date and time, 3: error message */
				echo Screen::note( 'bad', sprintf( __( 'Test email to %1$s failed on %2$s: %3$s', 'fluffless-suite' ), $test['to'], $date, '' === $test['message'] ? __( 'no detail from the mailer', 'fluffless-suite' ) : $test['message'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			}
		}
		$user = wp_get_current_user();
		$url  = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <span class="fl-muted">%3$s</span></p>',
			esc_url( $url ),
			esc_html__( 'Send a test email', 'fluffless-suite' ),
			/* translators: %s: email address of the current user */
			esc_html( sprintf( __( 'to %s, with the saved settings.', 'fluffless-suite' ), (string) $user->user_email ) )
		);
		$failures = self::failures();
		if ( array() === $failures ) {
			return;
		}
		echo '<div class="fl-table-scroll"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Recent failed sends', 'fluffless-suite' ) . '</caption><thead><tr><th scope="col">'
			. esc_html__( 'Date', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'Recipient', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'Error', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $failures as $failure ) {
			$date = wp_date( $format, $failure['time'] );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( false === $date ? '' : $date ),
				esc_html( $failure['to'] ),
				esc_html( $failure['message'] )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function hosts(): array {
		$host = Options::get_string( self::id(), 'host' );
		return array(
			array(
				'host'    => $host,
				'service' => __( 'Outgoing email (SMTP)', 'fluffless-suite' ),
				'side'    => 'server',
				'when'    => 'on_email',
				'data'    => __( 'Recipients, subject and content of each email, with the account username and password', 'fluffless-suite' ),
				'active'  => '' !== $host,
				'http'    => false,
			),
		);
	}
}
