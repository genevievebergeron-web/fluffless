<?php
/**
 * Un événement lu et normalisé : début, fin, journée entière, lieu, liens, statut.
 *
 * @package Fluffless_Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Events;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Les dates sont en heure du site (wp_timezone()), stockées sans décalage : « Y-m-d » pour une
 * journée entière, « Y-m-d H:i » sinon. La fin a toujours la forme du début.
 */
final class Event {

	/** Type de contenu. */
	public const TYPE = 'fluffless_event';

	/** Métadonnées (protégées par le soulignement initial : absentes de la boîte « Champs personnalisés »). */
	public const START   = '_fluffless_event_start';
	public const END     = '_fluffless_event_end';
	public const PLACE   = '_fluffless_event_place';
	public const STREET  = '_fluffless_event_street';
	public const CITY    = '_fluffless_event_city';
	public const REGION  = '_fluffless_event_region';
	public const POSTAL  = '_fluffless_event_postal';
	public const COUNTRY = '_fluffless_event_country';
	public const ONLINE  = '_fluffless_event_online';
	public const SIGNUP  = '_fluffless_event_signup';
	public const STATUS  = '_fluffless_event_status';

	/** Métas dérivées, jamais saisies : fin réelle (« Y-m-d H:i », heure du site) pour trier et masquer les passés ; numéro de version (SEQUENCE) ; premier début publié, gardé quand la date change. */
	public const UNTIL    = '_fluffless_event_until';
	public const SEQUENCE = '_fluffless_event_sequence';
	public const PREVIOUS = '_fluffless_event_previous_start';

	/** Pays par défaut de l'adresse (ISO 3166-1 alpha-2). */
	public const DEFAULT_COUNTRY = 'CA';

	/** Statuts permis. */
	public const SCHEDULED = 'scheduled';
	public const CANCELLED = 'cancelled';
	public const POSTPONED = 'postponed';
	public const STATUSES  = array( self::SCHEDULED, self::CANCELLED, self::POSTPONED );

	/** Parties de l'adresse => méta. */
	private const ADDRESS = array(
		'street'  => self::STREET,
		'city'    => self::CITY,
		'region'  => self::REGION,
		'postal'  => self::POSTAL,
		'country' => self::COUNTRY,
	);

	/**
	 * Événement normalisé.
	 *
	 * @param int                    $id      Contenu.
	 * @param DateTimeImmutable      $start   Début (minuit pour une journée entière).
	 * @param DateTimeImmutable|null $end     Fin, jamais avant le début ; null sans fin.
	 * @param bool                   $all_day Journée entière (sans heure).
	 * @param string                 $status  Un de STATUSES.
	 * @param string                 $place   Nom du lieu.
	 * @param array<string, string>  $address Adresse : street, city, region, postal, country.
	 * @param string                 $online  Lien en ligne.
	 * @param string                 $signup  Lien d'inscription.
	 * @param DateTimeImmutable|null $previous Début publié avant un changement de date, null sinon.
	 * @param int                    $sequence Version (SEQUENCE du .ics).
	 */
	public function __construct(
		public readonly int $id,
		public readonly DateTimeImmutable $start,
		public readonly ?DateTimeImmutable $end,
		public readonly bool $all_day,
		public readonly string $status = self::SCHEDULED,
		public readonly string $place = '',
		public readonly array $address = array(),
		public readonly string $online = '',
		public readonly string $signup = '',
		public readonly ?DateTimeImmutable $previous = null,
		public readonly int $sequence = 0
	) {}

	/**
	 * Lit un événement ; null sans date de début valide ou hors du type Événement.
	 *
	 * @param int|\WP_Post $post Contenu.
	 */
	public static function from_post( $post ): ?self {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || self::TYPE !== $post->post_type ) {
			return null;
		}
		$start = self::parse( (string) get_post_meta( $post->ID, self::START, true ) );
		if ( null === $start ) {
			return null;
		}
		$all_day  = ! $start['timed'];
		$end      = self::end_for( $start, (string) get_post_meta( $post->ID, self::END, true ) );
		$status   = (string) get_post_meta( $post->ID, self::STATUS, true );
		$previous = self::parse( (string) get_post_meta( $post->ID, self::PREVIOUS, true ) );
		$address  = array();
		foreach ( self::ADDRESS as $part => $key ) {
			$address[ $part ] = trim( (string) get_post_meta( $post->ID, $key, true ) );
		}
		return new self(
			$post->ID,
			$start['date'],
			$end,
			$all_day,
			in_array( $status, self::STATUSES, true ) ? $status : self::SCHEDULED,
			(string) get_post_meta( $post->ID, self::PLACE, true ),
			$address,
			(string) get_post_meta( $post->ID, self::ONLINE, true ),
			(string) get_post_meta( $post->ID, self::SIGNUP, true ),
			null !== $previous && $previous['date']->getTimestamp() !== $start['date']->getTimestamp() ? $previous['date'] : null,
			max( 0, (int) get_post_meta( $post->ID, self::SEQUENCE, true ) )
		);
	}

	/**
	 * Lit « Y-m-d » ou « Y-m-d H:i » en heure du site ; null pour une forme ou une date impossible.
	 *
	 * @param string $value Valeur stockée.
	 * @return array{date: DateTimeImmutable, timed: bool}|null
	 */
	public static function parse( string $value ): ?array {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?$/', $value, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[1] < 1970 ) {
			return null;
		}
		$hour   = $m[4] ?? '';
		$minute = $m[5] ?? '';
		$timed  = '' !== $minute;
		if ( $timed && ( (int) $hour > 23 || (int) $minute > 59 ) ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . ( $timed ? $hour . ':' . $minute : '00:00' ), wp_timezone() );
		if ( false === $date ) {
			return null;
		}
		return array(
			'date'  => $date,
			'timed' => $timed,
		);
	}

	/**
	 * Fin normalisée : même forme que le début, jamais avant lui. Une heure seule (« H:i ») veut dire le jour
	 * du début ; une heure de fin plus tôt que le début, le même jour, veut dire le lendemain (soirée qui
	 * finit après minuit). Une fin sans heure pour un début avec heure devient la fin de ce jour-là ; une fin
	 * avec heure pour une journée entière perd son heure.
	 *
	 * @param array{date: DateTimeImmutable, timed: bool} $start Début lu par parse().
	 * @param string                                      $raw   Fin stockée.
	 */
	private static function end_for( array $start, string $raw ): ?DateTimeImmutable {
		if ( 1 === preg_match( '/^\d{2}:\d{2}$/', $raw ) ) {
			$raw = $start['date']->format( 'Y-m-d' ) . ' ' . $raw;
		}
		$end = self::parse( $raw );
		if ( null === $end ) {
			return null;
		}
		if ( ! $start['timed'] ) {
			$date = $end['date']->setTime( 0, 0 );
		} elseif ( ! $end['timed'] ) {
			$date = $end['date']->setTime( 23, 59 );
		} else {
			$date = $end['date'];
			if ( $date < $start['date'] && $date->format( 'Y-m-d' ) === $start['date']->format( 'Y-m-d' ) ) {
				$date = $date->modify( '+1 day' );
			}
		}
		if ( $date < $start['date'] || ( ! $start['timed'] && $date == $start['date'] ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- comparaison de dates, pas d'identité.
			return null;
		}
		return $date;
	}

	/**
	 * Valeur stockable d'une date de début : « Y-m-d » ou « Y-m-d H:i », sinon chaîne vide.
	 *
	 * @param mixed $value Valeur brute (REST, formulaire).
	 */
	public static function sanitize_date( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = str_replace( 'T', ' ', trim( $value ) );
		$value = (string) preg_replace( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}):\d{2}$/', '$1', $value );
		return null === self::parse( $value ) ? '' : $value;
	}

	/**
	 * Valeur stockable d'une fin : comme un début, ou une heure seule (« H:i », le jour du début).
	 *
	 * @param mixed $value Valeur brute.
	 */
	public static function sanitize_end( $value ): string {
		if ( is_string( $value ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d(?::\d{2})?$/', trim( $value ) ) ) {
			return substr( trim( $value ), 0, 5 );
		}
		return self::sanitize_date( $value );
	}

	/**
	 * Code de pays stockable : deux lettres majuscules, sinon le pays par défaut.
	 *
	 * @param mixed $value Valeur brute.
	 */
	public static function sanitize_country( $value ): string {
		$value = is_string( $value ) ? strtoupper( trim( $value ) ) : '';
		return 1 === preg_match( '/^[A-Z]{2}$/', $value ) ? $value : self::DEFAULT_COUNTRY;
	}

	/**
	 * Adresse en clair, une ligne par partie : rue, puis ville, province et code postal. Le pays n'est pas
	 * écrit (le JSON-LD le porte) ; vide sans rue ni ville.
	 *
	 * @param string $glue Séparateur des lignes.
	 */
	public function address_text( string $glue = "\n" ): string {
		$a        = $this->address + array_fill_keys( array( 'street', 'city', 'region', 'postal' ), '' );
		$locality = '' !== $a['city'] && '' !== $a['region']
			/* translators: 1: city, 2: province or state. */
			? sprintf( __( '%1$s, %2$s', 'fluffless-suite' ), $a['city'], $a['region'] )
			: $a['city'] . $a['region'];
		$line2 = trim( $locality . ( '' !== $a['postal'] ? '  ' . $a['postal'] : '' ) );
		return implode( $glue, array_filter( array( $a['street'], $line2 ), static fn( string $l ): bool => '' !== $l ) );
	}

	/**
	 * Fin réelle : la fin donnée ; sans fin, 23 h 59 le jour du début (un atelier de 19 h sans heure de fin
	 * n'est pas « passé » à 19 h 01) ; 23 h 59 du dernier jour pour une journée entière.
	 */
	public function until(): DateTimeImmutable {
		if ( null === $this->end ) {
			return $this->start->setTime( 23, 59 );
		}
		return $this->all_day ? $this->end->setTime( 23, 59 ) : $this->end;
	}

	/**
	 * L'événement est terminé.
	 *
	 * @param DateTimeImmutable|null $now Maintenant (tests).
	 */
	public function is_past( ?DateTimeImmutable $now = null ): bool {
		return $this->until() < ( $now ?? new DateTimeImmutable( 'now', wp_timezone() ) );
	}

	/**
	 * L'événement touche ce jour (heure du site).
	 *
	 * @param string $ymd Jour, « Y-m-d ».
	 */
	public function covers( string $ymd ): bool {
		return $this->start->format( 'Y-m-d' ) <= $ymd && $this->until()->format( 'Y-m-d' ) >= $ymd;
	}

	/** Titre en texte brut. */
	public function title(): string {
		return html_entity_decode( wp_strip_all_tags( get_the_title( $this->id ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** Extrait en texte brut (celui saisi, sinon celui tiré du contenu). */
	public function summary(): string {
		$post = get_post( $this->id );
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return '';
		}
		$text = '' !== $post->post_excerpt ? $post->post_excerpt : wp_trim_words( excerpt_remove_blocks( strip_shortcodes( $post->post_content ) ), 55, '…' );
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
	}

	/** Adresse de la page. */
	public function url(): string {
		return (string) get_permalink( $this->id );
	}

	/** Quand, en clair, selon les formats de date et d'heure du site. */
	public function when(): string {
		$date = static fn( DateTimeImmutable $d ): string => (string) wp_date( (string) get_option( 'date_format' ), $d->getTimestamp(), wp_timezone() );
		$time = static fn( DateTimeImmutable $d ): string => (string) wp_date( (string) get_option( 'time_format' ), $d->getTimestamp(), wp_timezone() );
		$end  = $this->end;
		if ( $this->all_day ) {
			if ( null === $end ) {
				return $date( $this->start );
			}
			/* translators: 1: first day of the event, 2: last day of the event. */
			return sprintf( _x( '%1$s to %2$s', 'first and last day of an event', 'fluffless-suite' ), $date( $this->start ), $date( $end ) );
		}
		if ( null === $end ) {
			/* translators: 1: event date, 2: start time. */
			return sprintf( __( '%1$s at %2$s', 'fluffless-suite' ), $date( $this->start ), $time( $this->start ) );
		}
		if ( $end->format( 'Y-m-d' ) === $this->start->format( 'Y-m-d' ) ) {
			/* translators: 1: event date, 2: start time, 3: end time. */
			return sprintf( __( '%1$s, %2$s to %3$s', 'fluffless-suite' ), $date( $this->start ), $time( $this->start ), $time( $end ) );
		}
		/* translators: 1: start date, 2: start time, 3: end date, 4: end time. */
		return sprintf( __( '%1$s at %2$s to %3$s at %4$s', 'fluffless-suite' ), $date( $this->start ), $time( $this->start ), $date( $end ), $time( $end ) );
	}

	/**
	 * Statut à afficher : annulé, reporté, passé, ou vide.
	 *
	 * @param DateTimeImmutable|null $now Maintenant (tests).
	 */
	public function status_label( ?DateTimeImmutable $now = null ): string {
		if ( self::CANCELLED === $this->status ) {
			return _x( 'Cancelled', 'event status', 'fluffless-suite' );
		}
		if ( self::POSTPONED === $this->status ) {
			return _x( 'Postponed', 'event status', 'fluffless-suite' );
		}
		return $this->is_past( $now ) ? _x( 'Past', 'event status', 'fluffless-suite' ) : '';
	}
}
