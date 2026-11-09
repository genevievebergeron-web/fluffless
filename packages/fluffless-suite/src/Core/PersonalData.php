<?php
/**
 * Exportateur et effaceur de données personnelles de WordPress (Outils > Exporter ou Effacer les
 * données personnelles) pour ce que la Suite garde avec un courriel.
 *
 * Quatre sources : les réponses de formulaire gardées (une valeur égale au courriel), le journal
 * d'activité (identifiant tenté égal au courriel, avec l'adresse IP des échecs), les échecs d'envoi
 * de courriel (destinataire) et les inscriptions à l'infolettre en attente du courriel de secours.
 * Enregistré même quand un module est éteint : ses données restent jusqu'à leur purge.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

use Fluffless\Suite\Modules\Form;
use Fluffless\Suite\Modules\Mail;
use Fluffless\Suite\Modules\Newsletter;
use Fluffless\Suite\Modules\Security\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Une demande traite tout en une page : un site vitrine garde peu de lignes par personne.
 */
final class PersonalData {

	/** Clé de l'exportateur et de l'effaceur. */
	public const KEY = 'fluffless-suite';

	/** Branche les filtres de WordPress. */
	public static function hook(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	/**
	 * Sur wp_privacy_personal_data_exporters.
	 *
	 * @param mixed $exporters Exportateurs déjà inscrits.
	 * @return array<string, mixed>
	 */
	public static function exporters( $exporters ): array {
		$exporters              = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Fluffless Suite', 'fluffless-suite' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Sur wp_privacy_personal_data_erasers.
	 *
	 * @param mixed $erasers Effaceurs déjà inscrits.
	 * @return array<string, mixed>
	 */
	public static function erasers( $erasers ): array {
		$erasers              = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Fluffless Suite', 'fluffless-suite' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Données gardées pour ce courriel.
	 *
	 * @param string $email Courriel de la demande.
	 * @param int    $page  Page demandée ; tout part sur la première.
	 * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
	 */
	public static function export( $email, $page = 1 ): array {
		$email = self::normalize( (string) $email );
		if ( '' === $email || (int) $page > 1 ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$data   = array();
		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		foreach ( Form::responses_with( $email ) as $row ) {
			$labels = array_column( $row['fields'], 'label', 'name' );
			$items  = array(
				array(
					'name'  => __( 'Form', 'fluffless-suite' ),
					'value' => $row['form'],
				),
				array(
					'name'  => __( 'Date', 'fluffless-suite' ),
					'value' => (string) wp_date( $format, $row['time'] ),
				),
			);
			foreach ( $row['values'] as $name => $value ) {
				$items[] = array(
					'name'  => (string) ( $labels[ $name ] ?? $name ),
					'value' => is_array( $value ) ? implode( ', ', $value ) : $value,
				);
			}
			$data[] = array(
				'group_id'    => 'fluffless-form-responses',
				'group_label' => __( 'Form responses', 'fluffless-suite' ),
				'item_id'     => 'fluffless-response-' . $row['id'],
				'data'        => $items,
			);
		}
		foreach ( self::journal_rows( $email ) as $row ) {
			$data[] = array(
				'group_id'    => 'fluffless-activity-log',
				'group_label' => __( 'Activity log', 'fluffless-suite' ),
				'item_id'     => 'fluffless-journal-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date', 'fluffless-suite' ),
						'value' => (string) wp_date( $format, (int) strtotime( $row['created'] . ' UTC' ) ),
					),
					array(
						'name'  => __( 'Event', 'fluffless-suite' ),
						'value' => (string) ( Journal::labels()[ $row['event'] ] ?? $row['event'] ),
					),
					array(
						'name'  => __( 'IP address', 'fluffless-suite' ),
						'value' => $row['ip'],
					),
				),
			);
		}
		foreach ( Mail::failures() as $i => $failure ) {
			if ( self::matches( $failure['to'], $email ) ) {
				$data[] = array(
					'group_id'    => 'fluffless-mail-failures',
					'group_label' => __( 'Emails not sent', 'fluffless-suite' ),
					'item_id'     => 'fluffless-mail-' . $i,
					'data'        => array(
						array(
							'name'  => __( 'Date', 'fluffless-suite' ),
							'value' => (string) wp_date( $format, $failure['time'] ),
						),
						array(
							'name'  => __( 'Recipient', 'fluffless-suite' ),
							'value' => $failure['to'],
						),
					),
				);
			}
		}
		foreach ( Newsletter::pending() as $i => $entry ) {
			if ( self::matches( $entry['email'], $email ) ) {
				$data[] = array(
					'group_id'    => 'fluffless-newsletter-pending',
					'group_label' => __( 'Newsletter sign-ups not sent', 'fluffless-suite' ),
					'item_id'     => 'fluffless-pending-' . $i,
					'data'        => array(
						array(
							'name'  => __( 'Date', 'fluffless-suite' ),
							'value' => (string) wp_date( $format, $entry['time'] ),
						),
						array(
							'name'  => __( 'Email', 'fluffless-suite' ),
							'value' => $entry['email'],
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Efface ce qui est gardé pour ce courriel.
	 *
	 * @param string $email Courriel de la demande.
	 * @param int    $page  Page demandée ; tout part sur la première.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase( $email, $page = 1 ): array {
		$email   = self::normalize( (string) $email );
		$removed = 0;
		if ( '' !== $email && (int) $page <= 1 ) {
			$removed += Form::delete_responses( array_column( Form::responses_with( $email ), 'id' ) );
			$removed += self::forget_journal( $email );
			$failures = Mail::failures();
			$kept     = array_values( array_filter( $failures, static fn( array $f ): bool => ! self::matches( $f['to'], $email ) ) );
			if ( count( $kept ) !== count( $failures ) ) {
				$removed += count( $failures ) - count( $kept );
				if ( array() === $kept ) {
					Mail::forget_failures();
				} else {
					set_transient( Lifecycle::TRANSIENT_MAIL_FAILURES, $kept, Mail::FAILURES_TTL );
				}
			}
			$pending = Newsletter::pending();
			$left    = array_values( array_filter( $pending, static fn( array $p ): bool => ! self::matches( $p['email'], $email ) ) );
			if ( count( $left ) !== count( $pending ) ) {
				$removed += count( $pending ) - count( $left );
				if ( array() === $left ) {
					delete_option( Lifecycle::OPTION_NEWSLETTER_PENDING );
				} else {
					update_option( Lifecycle::OPTION_NEWSLETTER_PENDING, $left, false );
				}
			}
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Courriel en minuscules, sans espaces autour.
	 *
	 * @param string $email Courriel.
	 */
	private static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Une liste d'adresses séparées par des virgules contient-elle ce courriel ?
	 *
	 * @param string $addresses Adresses.
	 * @param string $email Courriel normalisé.
	 */
	private static function matches( string $addresses, string $email ): bool {
		return in_array( $email, array_map( array( self::class, 'normalize' ), explode( ',', $addresses ) ), true );
	}

	/**
	 * Lignes du journal dont l'identifiant tenté est ce courriel.
	 *
	 * @param string $email Courriel normalisé.
	 * @return list<array{id: int, created: string, event: string, ip: string}>
	 */
	private static function journal_rows( string $email ): array {
		if ( ! Journal::table_exists() ) {
			return array();
		}
		global $wpdb;
		$table = Journal::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table préfixé par wpdb ; demande rare.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created, event, ip FROM {$table} WHERE subject = %s ORDER BY id", $email ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'id'      => (int) ( $row['id'] ?? 0 ),
					'created' => (string) ( $row['created'] ?? '' ),
					'event'   => (string) ( $row['event'] ?? '' ),
					'ip'      => (string) ( $row['ip'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Retire du journal les lignes dont l'identifiant tenté est ce courriel.
	 *
	 * @param string $email Courriel normalisé.
	 * @return int Lignes retirées.
	 */
	private static function forget_journal( string $email ): int {
		if ( ! Journal::table_exists() ) {
			return 0;
		}
		global $wpdb;
		$table = Journal::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table préfixé par wpdb ; demande rare.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE subject = %s", $email ) );
		return is_int( $deleted ) ? $deleted : 0;
	}
}
