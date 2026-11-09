<?php
/**
 * Cyberimpact : entreprise québécoise, données au Canada.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Newsletter;

defined( 'ABSPATH' ) || exit;

/**
 * Référence : https://api.cyberimpact.com/docs (openapi.yaml, lu le 9 oct. 2026).
 *
 * - Double confirmation : POST /members/optins (courriel et groupes obligatoires). Cyberimpact
 *   envoie le courriel de confirmation et crée le membre au besoin ; le clic fait la preuve.
 * - Sans double confirmation : GET /members/unsubscribed/{courriel} d'abord (une personne
 *   désabonnée n'est jamais réinscrite), puis POST /members, puis PUT /members/{courriel}/consent
 *   (consentement exprès, date, texte de la case et page).
 * - Authentification : jeton porteur (Développeurs → Jetons API dans Cyberimpact).
 *
 * @phpstan-import-type Contact from Provider
 * @phpstan-import-type Settings from Provider
 * @phpstan-import-type Request from Provider
 */
final class Cyberimpact implements Provider {

	public const HOST = 'api.cyberimpact.com';

	/** {@inheritDoc} */
	public static function id(): string {
		return 'cyberimpact';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return 'Cyberimpact';
	}

	/** {@inheritDoc} */
	public static function country(): string {
		return __( 'in Canada', 'fluffless-suite' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Un ou plusieurs identifiants de groupe numériques, séparés par des virgules (10 au plus).
	 *
	 * @param string $list_id Valeur saisie.
	 */
	public static function valid_list( string $list_id ): bool {
		return 1 === preg_match( '/^\d{1,12}(,\d{1,12}){0,9}$/D', $list_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Clé lue dans wp-config.php.
	 */
	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9._~+\/=-]{20,4096}$/D', $key );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Clé.
	 */
	public static function host( string $key ): string {
		unset( $key );
		return self::HOST;
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
			'email'    => $contact['email'],
			'groups'   => $settings['list'],
			'language' => str_starts_with( $contact['language'], 'fr' ) ? 'fr_ca' : 'en_ca',
		);
		if ( '' !== $contact['first'] ) {
			$member['firstname'] = $contact['first'];
		}
		if ( '' !== $contact['last'] ) {
			$member['lastname'] = $contact['last'];
		}
		if ( $settings['double_optin'] ) {
			return array( self::request( 'optin', 'POST', '/members/optins', $settings['key'], $member ) );
		}
		$email = rawurlencode( $contact['email'] );
		return array(
			self::request( 'unsubscribed', 'GET', '/members/unsubscribed/' . $email, $settings['key'] ),
			self::request( 'add', 'POST', '/members', $settings['key'], $member ),
			self::request(
				'consent',
				'PUT',
				'/members/' . $email . '/consent',
				$settings['key'],
				array(
					'relationType' => 'express-consent',
					'consentDate'  => (string) wp_date( 'Y-m-d', $contact['time'] ),
					'consentProof' => sprintf(
						/* translators: 1: text of the consent checkbox, 2: address of the page with the form. */
						__( 'Checked the box “%1$s” on %2$s', 'fluffless-suite' ),
						$contact['consent'],
						$contact['page']
					),
				)
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $settings Réglages et clé.
	 * @phpstan-param Settings $settings
	 * @return Request
	 */
	public static function check( array $settings ): array {
		$group = (string) strtok( $settings['list'], ',' );
		return self::request( 'check', 'GET', '/groups/' . rawurlencode( $group ), $settings['key'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $step Étape de la requête.
	 * @param int                  $code Code HTTP.
	 * @param array<string, mixed> $body Corps JSON décodé.
	 */
	public static function outcome( string $step, int $code, array $body ): string {
		unset( $body );
		if ( 'unsubscribed' === $step ) {
			// 404 : la personne ne s'est jamais désabonnée. 200 : elle l'a fait, on s'arrête.
			if ( 404 === $code ) {
				return self::NEXT;
			}
			return 200 === $code ? Reason::UNSUBSCRIBED : Reason::from_code( $code );
		}
		if ( 'consent' === $step && 422 === $code ) {
			return self::DONE; // Consentement déjà fixé, et non modifiable : rien à faire.
		}
		if ( $code >= 200 && $code < 300 ) {
			return 'add' === $step ? self::NEXT : self::DONE;
		}
		if ( 404 === $code && 'consent' === $step ) {
			return Reason::INVALID;
		}
		return Reason::from_code( $code );
	}

	/**
	 * Une requête à l'API.
	 *
	 * @param string                    $step   Étape.
	 * @param string                    $method Méthode HTTP.
	 * @param string                    $path   Chemin.
	 * @param string                    $key    Jeton.
	 * @param array<string, mixed>|null $body   Corps JSON, ou null.
	 * @return Request
	 */
	private static function request( string $step, string $method, string $path, string $key, ?array $body = null ): array {
		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Accept'        => 'application/json',
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		return array(
			'step'    => $step,
			'method'  => $method,
			'url'     => 'https://' . self::HOST . $path,
			'headers' => $headers,
			'body'    => null === $body ? '' : (string) wp_json_encode( $body ),
		);
	}
}
