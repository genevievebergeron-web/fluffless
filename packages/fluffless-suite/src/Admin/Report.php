<?php
/**
 * « Report a problem » : signaler un bogue, proposer une idée ou poser une question.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

use Fluffless\Suite\Core\Api;
use Fluffless\Suite\Core\Diagnostic;
use Fluffless\Suite\Core\Lifecycle;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Écran caché (sans entrée de menu), ouvert depuis Fluffless → Modules et le menu ⋮ de l'éditeur.
 * L'envoi part du serveur ; le diagnostic n'est joint que si la case est cochée.
 */
final class Report {

	public const SLUG         = 'fluffless-report';
	public const ACTION       = 'fluffless_report';
	public const MAX_PER_HOUR = 5;
	public const MAX_TEXT     = 2000;
	public const MAX_SHOT     = MB_IN_BYTES;
	public const CONTACT      = 'genevieve@fluffless.dev';

	/** Longueur maximale du corps du lien mailto (les clients de courriel tronquent au-delà). */
	private const MAX_MAILTO = 1500;

	/** Écran caché sous options.php : aucune entrée de menu de plus. */
	public static function add_page(): void {
		add_submenu_page( 'options.php', __( 'Report a problem', 'fluffless-suite' ), __( 'Report a problem', 'fluffless-suite' ), SettingsPage::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
	}

	/** Adresse de l'écran. */
	public static function url(): string {
		return admin_url( 'options.php?page=' . self::SLUG );
	}

	/** Entrée « Report a problem » du menu ⋮ de l'éditeur, pour qui peut signaler. */
	public static function editor_assets(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		$handle = 'fluffless-report-menu';
		wp_enqueue_script( $handle, plugins_url( 'assets/admin/report-menu.js', FILE ), array( 'wp-plugins', 'wp-element', 'wp-i18n', 'wp-editor' ), VERSION, true );
		wp_add_inline_script( $handle, 'window.flufflessReport=' . wp_json_encode( array( 'url' => self::url() ) ) . ';', 'before' );
		wp_set_script_translations( $handle, 'fluffless-suite', dirname( FILE ) . '/languages' );
	}

	/** Écran : traite l'envoi, puis affiche le formulaire. */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		$input  = array();
		$result = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- le jeton est vérifié juste après.
		if ( isset( $_POST[ self::ACTION ] ) ) {
			check_admin_referer( self::ACTION );
			$input  = is_array( $_POST[ self::ACTION ] ) ? wp_unslash( $_POST[ self::ACTION ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- assaini dans submit().
			$file   = isset( $_FILES['fluffless_screenshot'] ) && is_array( $_FILES['fluffless_screenshot'] ) ? $_FILES['fluffless_screenshot'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- vérifié dans screenshot().
			$result = self::submit( $input, $file );
			if ( 'sent' === $result['status'] ) {
				$input = array();
			}
		}
		wp_enqueue_script( 'fluffless-copy', plugins_url( 'assets/admin/copy.js', FILE ), array(), VERSION, true );
		wp_enqueue_script( 'fluffless-report', plugins_url( 'assets/admin/report.js', FILE ), array( 'wp-i18n' ), VERSION, true );
		wp_set_script_translations( 'fluffless-report', 'fluffless-suite', dirname( FILE ) . '/languages' );
		echo '<div class="wrap fl-settings">';
		Screen::header( __( 'Report a problem', 'fluffless-suite' ), __( 'Something broke, an idea, a question: it goes straight to the person who builds Fluffless.', 'fluffless-suite' ) );
		self::render_form( $input, $result );
		echo '</div>';
	}

	/**
	 * Formulaire, prérempli après un échec pour ne rien perdre.
	 *
	 * @param array<mixed>                                             $input  Saisie précédente.
	 * @param array{status: string, message: string, id?: string}|null $result Résultat d'un envoi.
	 */
	public static function render_form( array $input, ?array $result ): void {
		$value = static fn( string $key ): string => is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';
		$type  = in_array( $value( 'type' ), array_keys( self::types() ), true ) ? $value( 'type' ) : 'bug';

		if ( null !== $result ) {
			echo Screen::note( 'sent' === $result['status'] ? 'good' : 'bad', $result['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			if ( 'failed' === $result['status'] ) {
				self::render_fallback( $input );
			}
		}

		printf( '<form method="post" enctype="multipart/form-data" action="%s" class="fl-report">', esc_url( self::url() ) );
		wp_nonce_field( self::ACTION );
		printf( '<fieldset class="fl-field"><legend class="fl-field__label">%s</legend><div>', esc_html__( 'What kind of message?', 'fluffless-suite' ) );
		foreach ( self::types() as $key => $label ) {
			printf( '<label><input type="radio" name="%1$s[type]" value="%2$s"%3$s> %4$s</label><br>', esc_attr( self::ACTION ), esc_attr( $key ), checked( $type, $key, false ), esc_html( $label ) );
		}
		echo '</div></fieldset>';

		foreach ( array(
			'what'     => array( __( 'What happened?', 'fluffless-suite' ), true ),
			'expected' => array( __( 'What did you expect?', 'fluffless-suite' ), false ),
		) as $key => [ $label, $required ] ) {
			printf(
				'<div class="fl-field"><label class="fl-field__label" for="fl-report-%1$s">%2$s</label><div><textarea id="fl-report-%1$s" name="%3$s[%1$s]" rows="4" class="large-text" maxlength="%4$d"%5$s>%6$s</textarea></div></div>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( self::ACTION ),
				(int) self::MAX_TEXT,
				$required ? ' required' : '',
				esc_textarea( $value( $key ) )
			);
		}

		printf(
			'<div class="fl-field"><label class="fl-field__label" for="fl-report-email">%1$s</label><div><input type="email" id="fl-report-email" name="%2$s[email]" class="regular-text" autocomplete="email" value="%3$s" aria-describedby="fl-report-email-help"><p class="description" id="fl-report-email-help">%4$s</p></div></div>',
			esc_html__( 'Reply email (optional)', 'fluffless-suite' ),
			esc_attr( self::ACTION ),
			esc_attr( $value( 'email' ) ),
			esc_html__( 'Only if you want an answer. It is used for that reply and nothing else.', 'fluffless-suite' )
		);

		printf(
			'<div class="fl-field"><label class="fl-field__label" for="fl-report-shot">%1$s</label><div><input type="file" id="fl-report-shot" name="fluffless_screenshot" accept="image/png,image/webp" aria-describedby="fl-report-shot-help"><p class="description" id="fl-report-shot-help">%2$s</p></div></div>',
			esc_html__( 'Screenshot (optional)', 'fluffless-suite' ),
			esc_html__( 'PNG or WebP, 1 MB at most. Larger images are shrunk in your browser before sending. Hide anything private first.', 'fluffless-suite' )
		);

		printf(
			'<div class="fl-field"><span class="fl-field__label">%1$s</span><div><p>%2$s</p><pre class="fl-diagnostic" id="fl-report-diagnostic" tabindex="0">%3$s</pre><label><input type="checkbox" name="%4$s[diagnostics]" value="1"> %5$s</label></div></div>',
			esc_html__( 'Diagnostic', 'fluffless-suite' ),
			esc_html__( 'Versions and settings only, exactly as shown. No addresses, names or content. Your site is identified by a one-way hash.', 'fluffless-suite' ),
			esc_html( Diagnostic::text() ),
			esc_attr( self::ACTION ),
			esc_html__( 'Include this diagnostic', 'fluffless-suite' )
		);

		echo '<div class="fl-savebar">';
		submit_button( __( 'Send', 'fluffless-suite' ), 'primary', 'submit', false );
		echo '</div></form>';
	}

	/**
	 * Repli quand l'envoi échoue : copier le diagnostic, écrire par courriel.
	 *
	 * @param array<mixed> $input Saisie.
	 */
	private static function render_fallback( array $input ): void {
		printf(
			'<p><button type="button" class="button" data-fl-copy="fl-report-diagnostic" data-fl-copy-done="%4$s" data-fl-copy-fail="%5$s" hidden>%1$s</button> <a class="button" href="%2$s">%3$s</a> <span class="fl-muted" role="status" data-fl-copy-status></span></p>',
			esc_html__( 'Copy the diagnostic', 'fluffless-suite' ),
			esc_url( self::mailto( $input ), array( 'mailto' ) ),
			/* translators: %s: email address */
			esc_html( sprintf( __( 'Write to %s instead', 'fluffless-suite' ), self::CONTACT ) ),
			esc_attr__( 'Diagnostic copied.', 'fluffless-suite' ),
			esc_attr__( 'Copy failed: select the text above instead.', 'fluffless-suite' )
		);
	}

	/**
	 * Lien mailto prérempli avec le texte saisi seulement : ni courriel, ni diagnostic, ni empreinte.
	 *
	 * @param array<mixed> $input Saisie.
	 */
	public static function mailto( array $input ): string {
		$types = self::types();
		$type  = is_string( $input['type'] ?? null ) && isset( $types[ $input['type'] ] ) ? $types[ $input['type'] ] : '';
		$body  = '';
		foreach ( array( 'what', 'expected' ) as $key ) {
			$text  = is_string( $input[ $key ] ?? null ) ? sanitize_textarea_field( $input[ $key ] ) : '';
			$body .= '' === $text ? '' : $text . "\n\n";
		}
		$subject = 'Fluffless: ' . ( '' === $type ? __( 'Report a problem', 'fluffless-suite' ) : $type );
		return 'mailto:' . self::CONTACT . '?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( mb_substr( trim( $body ), 0, self::MAX_MAILTO ) );
	}

	/**
	 * Types de message : clé du contrat => libellé.
	 *
	 * @return array<'bug'|'idea'|'question', string>
	 */
	public static function types(): array {
		return array(
			'bug'      => __( 'Fluff: something is broken', 'fluffless-suite' ),
			'idea'     => __( 'Idea', 'fluffless-suite' ),
			'question' => __( 'Question', 'fluffless-suite' ),
		);
	}

	/**
	 * Valide et envoie un signalement. Limite locale : MAX_PER_HOUR envois par heure pour le site.
	 *
	 * @param array<mixed>      $input Saisie : type, what, expected, email, diagnostics.
	 * @param array<mixed>|null $file  Entrée de $_FILES pour la capture, ou null.
	 * @return array{status: 'sent'|'invalid'|'limited'|'failed', message: string, id?: string}
	 */
	public static function submit( array $input, ?array $file ): array {
		$type     = is_string( $input['type'] ?? null ) && isset( self::types()[ $input['type'] ] ) ? $input['type'] : '';
		$what     = self::text( $input['what'] ?? '' );
		$expected = self::text( $input['expected'] ?? '' );
		$email    = is_string( $input['email'] ?? null ) ? sanitize_email( $input['email'] ) : '';
		if ( '' === $type || '' === $what ) {
			return self::result( 'invalid', __( 'Choose a kind of message and describe what happened.', 'fluffless-suite' ) );
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			return self::result( 'invalid', __( 'The reply email does not look valid. Fix it or leave it empty.', 'fluffless-suite' ) );
		}
		$screenshot = self::screenshot( $file );
		if ( false === $screenshot ) {
			return self::result( 'invalid', __( 'The screenshot must be a PNG or WebP image of 1 MB or less.', 'fluffless-suite' ) );
		}

		$sent = self::recent();
		if ( count( $sent ) >= self::MAX_PER_HOUR ) {
			return self::result( 'limited', __( 'Five reports were sent from this site in the last hour. Wait a little, or write by email.', 'fluffless-suite' ) );
		}
		$sent[] = time();
		set_transient( Lifecycle::TRANSIENT_REPORTS, $sent, HOUR_IN_SECONDS );

		$payload = array(
			'type'     => $type,
			'what'     => $what,
			'expected' => $expected,
			'locale'   => str_starts_with( determine_locale(), 'fr' ) ? 'fr_CA' : 'en_CA',
		);
		if ( '' !== $email ) {
			$payload['reply_email'] = $email;
		}
		if ( ! empty( $input['diagnostics'] ) ) {
			$payload['diagnostics'] = Diagnostic::collect();
			$payload['site']        = Api::site_hash();
		}
		if ( '' !== $screenshot ) {
			$payload['screenshot'] = $screenshot;
		}

		$response = Api::post( '/v1/reports', $payload );
		$code     = is_wp_error( $response ) ? 0 : $response['code'];
		if ( 201 === $code ) {
			$id = is_array( $response['body'] ?? null ) && is_scalar( $response['body']['id'] ?? null ) ? sanitize_text_field( (string) $response['body']['id'] ) : '';
			/* translators: %s: report reference, e.g. r_42 */
			return array( 'id' => $id ) + self::result( 'sent', sprintf( __( 'Thank you, your report was received (reference %s).', 'fluffless-suite' ), $id ) );
		}
		if ( 429 === $code ) {
			return self::result( 'failed', __( 'Too many reports reached the server right now. Try again in an hour, or write by email.', 'fluffless-suite' ) );
		}
		return self::result( 'failed', __( 'The report could not be sent. Nothing was lost: copy the diagnostic or write by email below.', 'fluffless-suite' ) );
	}

	/**
	 * Résultat typé.
	 *
	 * @param 'sent'|'invalid'|'limited'|'failed' $status  État.
	 * @param string                              $message Message.
	 * @return array{status: 'sent'|'invalid'|'limited'|'failed', message: string}
	 */
	private static function result( string $status, string $message ): array {
		return array(
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * Texte saisi, balises retirées, borné.
	 *
	 * @param mixed $raw Valeur reçue.
	 */
	private static function text( $raw ): string {
		return is_string( $raw ) ? trim( mb_substr( sanitize_textarea_field( $raw ), 0, self::MAX_TEXT ) ) : '';
	}

	/**
	 * Dates des envois de la dernière heure.
	 *
	 * @return list<int>
	 */
	private static function recent(): array {
		$log = get_transient( Lifecycle::TRANSIENT_REPORTS );
		return array_values( array_filter( is_array( $log ) ? $log : array(), static fn( $at ): bool => is_int( $at ) && $at > time() - HOUR_IN_SECONDS ) );
	}

	/**
	 * Capture en base64 : '' si aucune, false si refusée (type réel ou poids).
	 *
	 * @param array<mixed>|null $file Entrée de $_FILES.
	 * @return string|false
	 */
	private static function screenshot( ?array $file ) {
		if ( null === $file || UPLOAD_ERR_NO_FILE === ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return '';
		}
		$path = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$size = is_int( $file['size'] ?? null ) ? $file['size'] : PHP_INT_MAX;
		if ( UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || '' === $path || ! is_file( $path ) || $size > self::MAX_SHOT || (int) filesize( $path ) > self::MAX_SHOT ) {
			return false;
		}
		$info = getimagesize( $path );
		if ( false === $info || ! in_array( $info['mime'], array( 'image/png', 'image/webp' ), true ) ) {
			return false;
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier téléversé local.
		return false === $bytes ? false : base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format du contrat de l'API.
	}
}
