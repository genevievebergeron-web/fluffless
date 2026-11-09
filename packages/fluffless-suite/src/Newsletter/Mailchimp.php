<?php
/**
 * Mailchimp : données aux États-Unis.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Newsletter;

defined( 'ABSPATH' ) || exit;

/**
 * Référence : https://mailchimp.com/developer/marketing/api/list-members/ (API Marketing 3.0).
 *
 * - POST /3.0/lists/{liste}/members, qui crée seulement. Une adresse déjà présente répond
 *   « Member Exists » et reste telle quelle : jamais de PUT, qui pourrait faire repasser une
 *   personne abonnée en attente de confirmation, ou changer son nom, depuis le formulaire.
 * - Double confirmation : statut « pending », Mailchimp envoie le courriel de confirmation.
 * - Preuve : timestamp_signup. L'adresse IP n'est jamais transmise.
 * - Authentification : Basic, la clé comme mot de passe. Le centre de données (us21...) est
 *   la fin de la clé et donne l'hôte.
 *
 * @phpstan-import-type Contact from Provider
 * @phpstan-import-type Settings from Provider
 * @phpstan-import-type Request from Provider
 */
final class Mailchimp implements Provider {

	/** Titres d'erreur de Mailchimp pour une adresse désabonnée ou effacée. */
	private const UNSUBSCRIBED_TITLES = array( 'Member In Compliance State', 'Forgotten Email Not Subscribed' );

	/** {@inheritDoc} */
	public static function id(): string {
		return 'mailchimp';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return 'Mailchimp';
	}

	/** {@inheritDoc} */
	public static function country(): string {
		return __( 'in the United States', 'fluffless-suite' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Identifiant d'audience : lettres minuscules et chiffres.
	 *
	 * @param string $list_id Valeur saisie.
	 */
	public static function valid_list( string $list_id ): bool {
		return 1 === preg_match( '/^[a-z0-9]{5,20}$/D', $list_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * 32 caractères hexadécimaux, un tiret, le centre de données.
	 *
	 * @param string $key Clé lue dans wp-config.php.
	 */
	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^[0-9a-f]{32}-[a-z]{2,3}\d{1,2}$/D', $key );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Clé.
	 */
	public static function host( string $key ): string {
		if ( ! self::valid_key( $key ) ) {
			return '';
		}
		return substr( $key, (int) strrpos( $key, '-' ) + 1 ) . '.api.mailchimp.com';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $contact  Personne à inscrire.
	 * @param array<string, mixed> $settings Réglages et clé.
	 * @phpstan-param Contact  $contact
	 * @phpstan-param Settings $settings
	 * @return list<Request>
	 */
	public static function subscribe( array $contact, array $settings ): array {
		$member = array(
			'email_address'    => $contact['email'],
			'status'           => $settings['double_optin'] ? 'pending' : 'subscribed',
			'language'         => self::language( $contact['language'] ),
			'timestamp_signup' => gmdate( 'c', $contact['time'] ),
		);
		$merge  = array_filter(
			array(
				'FNAME' => $contact['first'],
				'LNAME' => $contact['last'],
			),
			static fn( string $value ): bool => '' !== $value
		);
		if ( array() !== $merge ) {
			$member['merge_fields'] = $merge;
		}
		return array( self::request( 'add', 'POST', '/lists/' . rawurlencode( $settings['list'] ) . '/members', $settings['key'], $member ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $settings Réglages et clé.
	 * @phpstan-param Settings $settings
	 * @return Request
	 */
	public static function check( array $settings ): array {
		return self::request( 'check', 'GET', '/lists/' . rawurlencode( $settings['list'] ) . '?fields=id', $settings['key'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $step Étape de la requête.
	 * @param int                  $code Code HTTP.
	 * @param array<string, mixed> $body Corps JSON décodé.
	 */
	public static function outcome( string $step, int $code, array $body ): string {
		unset( $step );
		if ( $code >= 200 && $code < 300 ) {
			return self::DONE;
		}
		$title = isset( $body['title'] ) && is_string( $body['title'] ) ? $body['title'] : '';
		if ( 400 === $code && 'Member Exists' === $title ) {
			return self::DONE; // Déjà dans l'audience : laissée telle quelle.
		}
		if ( 400 === $code && in_array( $title, self::UNSUBSCRIBED_TITLES, true ) ) {
			return Reason::UNSUBSCRIBED;
		}
		return Reason::from_code( $code );
	}

	/**
	 * Langue Mailchimp tirée de la langue de la page.
	 *
	 * @param string $locale Langue WordPress (fr_CA, en_US...).
	 */
	public static function language( string $locale ): string {
		if ( 'fr_CA' === $locale ) {
			return 'fr_CA';
		}
		return str_starts_with( $locale, 'fr' ) ? 'fr' : 'en';
	}

	/**
	 * Une requête à l'API. Sans clé valide, l'hôte reste vide et le module n'envoie rien.
	 *
	 * @param string                    $step   Étape.
	 * @param string                    $method Méthode HTTP.
	 * @param string                    $path   Chemin après /3.0.
	 * @param string                    $key    Clé.
	 * @param array<string, mixed>|null $body   Corps JSON, ou null.
	 * @return Request
	 */
	private static function request( string $step, string $method, string $path, string $key, ?array $body = null ): array {
		$headers = array(
			'Authorization' => 'Basic ' . base64_encode( 'fluffless:' . $key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- authentification Basic.
			'Accept'        => 'application/json',
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		$host = self::host( $key );
		return array(
			'step'    => $step,
			'method'  => $method,
			'url'     => '' === $host ? '' : 'https://' . $host . '/3.0' . $path,
			'headers' => $headers,
			'body'    => null === $body ? '' : (string) wp_json_encode( $body ),
		);
	}
}
