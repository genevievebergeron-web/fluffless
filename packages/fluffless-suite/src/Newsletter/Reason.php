<?php
/**
 * Raisons d'échec d'une inscription : une liste fermée, jamais le texte brut de l'outil.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Newsletter;

defined( 'ABSPATH' ) || exit;

/**
 * Pourquoi une liste fermée : les messages d'erreur des outils répètent souvent l'adresse de la
 * personne. Seule la raison classée est gardée et affichée.
 */
final class Reason {

	public const KEY          = 'key';
	public const LIST         = 'list';
	public const UNSUBSCRIBED = 'unsubscribed';
	public const INVALID      = 'invalid';
	public const THROTTLED    = 'throttled';
	public const UNREACHABLE  = 'unreachable';
	public const BLOCKED      = 'blocked';
	public const NO_PROOF     = 'no_proof';
	public const OTHER        = 'other';

	public const ALL = array( self::KEY, self::LIST, self::UNSUBSCRIBED, self::INVALID, self::THROTTLED, self::UNREACHABLE, self::BLOCKED, self::NO_PROOF, self::OTHER );

	/**
	 * Raison tirée du seul code HTTP, quand l'outil n'en dit pas plus.
	 *
	 * @param int $code Code HTTP.
	 */
	public static function from_code( int $code ): string {
		if ( 401 === $code || 403 === $code ) {
			return self::KEY;
		}
		if ( 404 === $code ) {
			return self::LIST;
		}
		if ( 429 === $code ) {
			return self::THROTTLED;
		}
		if ( 0 === $code || $code >= 500 ) {
			return self::UNREACHABLE;
		}
		if ( $code >= 400 ) {
			return self::INVALID;
		}
		return self::OTHER;
	}

	/**
	 * Phrase affichée pour une raison.
	 *
	 * @param string $reason Raison de la liste.
	 */
	public static function text( string $reason ): string {
		switch ( $reason ) {
			case self::KEY:
				return __( 'the key was refused', 'fluffless-suite' );
			case self::LIST:
				return __( 'the list or group was not found', 'fluffless-suite' );
			case self::UNSUBSCRIBED:
				return __( 'the address had unsubscribed, and the tool does not allow signing it up again this way', 'fluffless-suite' );
			case self::INVALID:
				return __( 'the tool refused the address or a field', 'fluffless-suite' );
			case self::THROTTLED:
				return __( 'too many requests to the tool, try later', 'fluffless-suite' );
			case self::UNREACHABLE:
				return __( 'the tool did not answer in time', 'fluffless-suite' );
			case self::BLOCKED:
				return __( 'the Outgoing requests module blocked the tool’s domain', 'fluffless-suite' );
			case self::NO_PROOF:
				return __( 'the person was added, but the proof of consent was not recorded', 'fluffless-suite' );
			default:
				return __( 'unexpected answer from the tool', 'fluffless-suite' );
		}
	}
}
