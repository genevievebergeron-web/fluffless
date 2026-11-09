<?php
/**
 * Fichier iCalendar (RFC 5545) des événements : un seul, ou le flux d'abonnement.
 *
 * @package Fluffless_Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Production du texte .ics. Aucune lecture de requête ici : les événements arrivent déjà choisis.
 */
final class Ics {

	/** Option : jeton aléatoire du site, pour des UID qui ne dépendent pas du domaine. */
	public const UID_OPTION = 'fluffless_events_uid';

	/** Longueur maximale d'une ligne, en octets, sans le CRLF (RFC 5545, 3.1). */
	private const LINE = 75;

	/**
	 * Calendrier complet.
	 *
	 * @param array<int, Event> $events Événements, dans l'ordre voulu.
	 * @param string            $name   Nom du calendrier (X-WR-CALNAME), vide pour un seul événement.
	 */
	public static function calendar( array $events, string $name = '' ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Fluffless//Fluffless Suite//' . strtoupper( substr( get_locale(), 0, 2 ) ),
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
		);
		if ( '' !== $name ) {
			$lines[] = 'X-WR-CALNAME:' . self::text( $name );
		}
		foreach ( $events as $event ) {
			array_push( $lines, ...self::vevent( $event ) );
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * Lignes d'un VEVENT, non pliées.
	 *
	 * @param Event $event Événement.
	 * @return list<string>
	 */
	public static function vevent( Event $event ): array {
		$post  = get_post( $event->id );
		$stamp = $post instanceof \WP_Post && '0000-00-00 00:00:00' !== $post->post_modified_gmt ? strtotime( $post->post_modified_gmt . ' UTC' ) : time();
		$lines = array(
			'BEGIN:VEVENT',
			'UID:event-' . $event->id . '-' . self::site_token() . '@fluffless',
			'SEQUENCE:' . $event->sequence,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z', false === $stamp ? time() : $stamp ),
		);
		if ( $event->all_day ) {
			$last    = $event->end ?? $event->start;
			$lines[] = 'DTSTART;VALUE=DATE:' . $event->start->format( 'Ymd' );
			$lines[] = 'DTEND;VALUE=DATE:' . $last->modify( '+1 day' )->format( 'Ymd' );
		} else {
			$utc     = new \DateTimeZone( 'UTC' );
			$lines[] = 'DTSTART:' . $event->start->setTimezone( $utc )->format( 'Ymd\THis\Z' );
			if ( null !== $event->end ) {
				$lines[] = 'DTEND:' . $event->end->setTimezone( $utc )->format( 'Ymd\THis\Z' );
			}
		}
		$lines[] = 'SUMMARY:' . self::text( $event->title() );
		$where   = implode( ', ', array_filter( array( trim( $event->place ), $event->address_text( ', ' ) ) ) );
		if ( '' !== $where ) {
			$lines[] = 'LOCATION:' . self::text( $where );
		}
		$summary = $event->summary();
		$online  = '' !== $event->online ? $event->online : '';
		$about   = trim( $summary . ( '' !== $online ? "\n\n" . $online : '' ) );
		if ( '' !== $about ) {
			$lines[] = 'DESCRIPTION:' . self::text( $about );
		}
		$lines[] = 'URL:' . self::uri( $event->url() );
		$lines[] = 'STATUS:' . match ( $event->status ) {
			Event::CANCELLED => 'CANCELLED',
			Event::POSTPONED => 'TENTATIVE',
			default          => 'CONFIRMED',
		};
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/**
	 * Jeton du site, créé au premier besoin : changer de domaine ne change pas les UID, donc les agendas
	 * abonnés mettent à jour leurs événements au lieu de les dédoubler.
	 */
	public static function site_token(): string {
		$token = get_option( self::UID_OPTION );
		if ( is_string( $token ) && 1 === preg_match( '/^[a-z0-9]{12}$/', $token ) ) {
			return $token;
		}
		$token = strtolower( wp_generate_password( 12, false, false ) );
		update_option( self::UID_OPTION, $token, false );
		return $token;
	}

	/**
	 * Valeur TEXT : barre oblique inverse, point-virgule, virgule et retours de ligne échappés.
	 *
	 * @param string $value Texte brut.
	 */
	public static function text( string $value ): string {
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $value );
		return strtr(
			$value,
			array(
				'\\' => '\\\\',
				';'  => '\;',
				','  => '\,',
				"\n" => '\n',
			)
		);
	}

	/**
	 * Valeur URI : sans caractère de contrôle ni retour de ligne (aucun échappement TEXT).
	 *
	 * @param string $url Adresse.
	 */
	public static function uri( string $url ): string {
		return (string) preg_replace( '/[\x00-\x20\x7F]/', '', $url );
	}

	/**
	 * Plie une ligne à 75 octets au plus, sans couper un caractère UTF-8 ; chaque suite commence par une espace.
	 *
	 * @param string $line Ligne.
	 */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= self::LINE ) {
			return $line;
		}
		$out    = array();
		$limit  = self::LINE;
		$length = strlen( $line );
		while ( $length > $limit ) {
			$cut = $limit;
			// Recule tant que l'octet de coupe est un octet de continuation UTF-8 (10xxxxxx).
			while ( $cut > 0 && 0x80 === ( ord( $line[ $cut ] ) & 0xC0 ) ) {
				--$cut;
			}
			$out[]  = substr( $line, 0, $cut );
			$line   = substr( $line, $cut );
			$length = strlen( $line );
			$limit  = self::LINE - 1;
		}
		$out[] = $line;
		return implode( "\r\n ", $out );
	}
}
