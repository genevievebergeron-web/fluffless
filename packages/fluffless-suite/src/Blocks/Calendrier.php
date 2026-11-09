<?php
/**
 * Bloc fluffless/calendrier : grille mensuelle des événements, rendue en PHP, navigation par liens,
 * vue liste dans un conteneur étroit. Aucun script sur le site.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Blocks;

use DateTimeImmutable;
use Fluffless\Suite\Events\Event;
use Fluffless\Suite\Modules\Events;
use Fluffless\Suite\Modules\Expression;

defined( 'ABSPATH' ) || exit;

/**
 * Mois affiché, jours, événements de chaque jour et feuille. Enregistré par le module Événements.
 */
final class Calendrier {

	public const NAME  = 'fluffless/calendrier';
	public const STYLE = 'fluffless-calendrier';

	/** Mois accessibles de part et d'autre du mois courant. */
	public const RANGE = 24;

	/** Plafond des événements lus pour un mois. */
	public const MAX_EVENTS = 300;

	public const LEVELS = array( 2, 3, 4 );

	/**
	 * Premier jour du mois demandé par ?fluffless-month=AAAA-MM, borné à RANGE mois ; le mois courant sinon.
	 *
	 * @param mixed                  $requested Valeur reçue.
	 * @param DateTimeImmutable|null $now       Maintenant (tests).
	 */
	public static function month( $requested, ?DateTimeImmutable $now = null ): DateTimeImmutable {
		$now     = ( $now ?? Events::now() )->modify( 'first day of this month' )->setTime( 0, 0 );
		$matches = array();
		if ( ! is_string( $requested ) || 1 !== preg_match( '/^(\d{4})-(\d{2})$/', $requested, $matches ) || (int) $matches[2] < 1 || (int) $matches[2] > 12 ) {
			return $now;
		}
		$month = $now->setDate( (int) $matches[1], (int) $matches[2], 1 );
		$gap   = self::gap( $now, $month );
		return abs( $gap ) > self::RANGE ? $now : $month;
	}

	/**
	 * Écart en mois entre deux premiers jours de mois.
	 *
	 * @param DateTimeImmutable $from Mois de départ.
	 * @param DateTimeImmutable $to   Mois d'arrivée.
	 */
	public static function gap( DateTimeImmutable $from, DateTimeImmutable $to ): int {
		return ( (int) $to->format( 'Y' ) - (int) $from->format( 'Y' ) ) * 12 + (int) $to->format( 'n' ) - (int) $from->format( 'n' );
	}

	/**
	 * Événements du mois, rangés par jour (« Y-m-d »), dans l'ordre des débuts.
	 *
	 * @param DateTimeImmutable $month Premier jour du mois.
	 * @return array<string, list<Event>>
	 */
	public static function days( DateTimeImmutable $month ): array {
		$last   = $month->modify( 'last day of this month' );
		$events = Events::events_between( $month->format( 'Y-m-d 00:00' ), $last->format( 'Y-m-d 23:59' ), self::MAX_EVENTS );
		$days   = array();
		for ( $day = $month; $day <= $last; $day = $day->modify( '+1 day' ) ) {
			$ymd          = $day->format( 'Y-m-d' );
			$days[ $ymd ] = array_values( array_filter( $events, static fn( Event $event ): bool => $event->covers( $ymd ) ) );
		}
		return $days;
	}

	/**
	 * Rendu du bloc.
	 *
	 * @param array<mixed>|mixed $attributes Attributs du bloc.
	 */
	public static function render( $attributes ): string {
		if ( is_feed() ) {
			return '';
		}
		$attributes = is_array( $attributes ) ? $attributes : array();
		$level      = (int) ( $attributes['level'] ?? 2 );
		$level      = in_array( $level, self::LEVELS, true ) ? $level : 2;
		$now        = Events::now();
		$month      = self::month( isset( $_GET[ Events::MONTH_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ Events::MONTH_ARG ] ) ) : null, $now ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture seule, valeur bornée.
		$current    = $now->modify( 'first day of this month' )->setTime( 0, 0 );
		$days       = self::days( $month );
		$today      = $now->format( 'Y-m-d' );
		$time       = (string) get_option( 'time_format' );
		$id         = wp_unique_id( 'fl-cal-' );

		$items = '';
		$found = false;
		$first = true;
		foreach ( $days as $ymd => $events ) {
			$date    = new DateTimeImmutable( $ymd, wp_timezone() );
			$classes = array();
			if ( $first ) {
				$column    = ( (int) $date->format( 'w' ) - (int) get_option( 'start_of_week', 1 ) + 7 ) % 7 + 1;
				$classes[] = $column > 1 ? 'fl-cal-c' . $column : '';
				$first     = false;
			}
			$list = '';
			foreach ( $events as $event ) {
				$found = true;
				$label = Event::CANCELLED === $event->status || Event::POSTPONED === $event->status ? ' <span class="fl-cal-status">(' . esc_html( $event->status_label() ) . ')</span>' : '';
				$hour  = ! $event->all_day && $event->start->format( 'Y-m-d' ) === $ymd ? '<span class="fl-cal-time">' . esc_html( (string) wp_date( $time, $event->start->getTimestamp(), wp_timezone() ) ) . '</span> ' : '';
				$list .= '<li>' . $hour . '<a href="' . esc_url( $event->url() ) . '">' . esc_html( $event->title() ) . '</a>' . $label . '</li>';
			}
			$classes[] = '' !== $list ? 'has-events' : '';
			$classes   = array_filter( $classes );
			$items    .= sprintf(
				'<li%1$s%2$s><span class="fl-cal-n" aria-hidden="true">%3$s</span><span class="fl-cal-date">%4$s</span>%5$s</li>',
				array() === $classes ? '' : ' class="' . esc_attr( implode( ' ', $classes ) ) . '"',
				$ymd === $today ? ' aria-current="date"' : '',
				esc_html( number_format_i18n( (int) $date->format( 'j' ) ) ),
				esc_html( (string) wp_date( (string) get_option( 'date_format' ), $date->getTimestamp(), wp_timezone() ) ),
				'' === $list ? '' : '<ul>' . $list . '</ul>'
			);
		}

		$nav = '';
		foreach ( array( -1, 1 ) as $step ) {
			$target = $month->modify( ( $step > 0 ? '+' : '-' ) . '1 month' );
			if ( abs( self::gap( $current, $target ) ) > self::RANGE ) {
				continue;
			}
			$url  = 0 === self::gap( $current, $target ) ? remove_query_arg( Events::MONTH_ARG ) : add_query_arg( Events::MONTH_ARG, $target->format( 'Y-m' ) );
			$text = esc_html( self::title( $target ) );
			$nav .= sprintf(
				'<a class="fl-cal-%1$s" href="%2$s" rel="nofollow">%3$s</a>',
				$step > 0 ? 'next' : 'prev',
				esc_url( $url ),
				$step > 0 ? $text . ' <span aria-hidden="true">→</span>' : '<span aria-hidden="true">←</span> ' . $text
			);
		}

		Expression::enqueue_inline( self::STYLE, self::css() );
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) && null !== \WP_Block_Supports::$block_to_render
			? get_block_wrapper_attributes( array( 'class' => 'fl-calendrier' ) )
			: 'class="fl-calendrier"';
		return sprintf(
			'<section %1$s aria-labelledby="%2$s"><h%3$d id="%2$s" class="fl-cal-title">%4$s</h%3$d>%5$s<div class="fl-cal-head" aria-hidden="true">%6$s</div><ol class="fl-cal-days">%7$s</ol>%8$s</section>',
			$wrapper,
			esc_attr( $id ),
			$level,
			esc_html( self::title( $month ) ),
			'' === $nav ? '' : '<nav class="fl-cal-nav" aria-label="' . esc_attr__( 'Other months', 'fluffless-suite' ) . '">' . $nav . '</nav>',
			self::weekdays(),
			$items,
			$found ? '' : '<p class="fl-cal-empty">' . esc_html__( 'No events this month.', 'fluffless-suite' ) . '</p>'
		);
	}

	/**
	 * « Octobre 2026 », selon la langue du site, avec une majuscule initiale.
	 *
	 * @param DateTimeImmutable $month Mois.
	 */
	public static function title( DateTimeImmutable $month ): string {
		/* translators: PHP date format for a month heading in the calendar, see https://www.php.net/manual/datetime.format.php */
		$text = (string) wp_date( _x( 'F Y', 'calendar month heading', 'fluffless-suite' ), $month->getTimestamp(), wp_timezone() );
		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/** Rangée des jours de la semaine, dans l'ordre du réglage « La semaine commence le ». */
	private static function weekdays(): string {
		global $wp_locale;
		$start = (int) get_option( 'start_of_week', 1 );
		$out   = '';
		for ( $i = 0; $i < 7; $i++ ) {
			$day  = ( $start + $i ) % 7;
			$name = $wp_locale instanceof \WP_Locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $day ) ) : (string) $day;
			$out .= '<span>' . esc_html( $name ) . '</span>';
		}
		return $out;
	}

	/** Feuille du bloc : grille de sept colonnes, liste des seuls jours occupés sous 36 rem. */
	public static function css(): string {
		$starts = '';
		for ( $i = 2; $i <= 7; $i++ ) {
			$starts .= '.fl-cal-c' . $i . '{grid-column:' . $i . '}';
		}
		$line = 'color-mix(in srgb,currentColor 20%,transparent)';
		return '.fl-calendrier{width:100%;container-type:inline-size}'
			. '.fl-cal-nav{display:flex;flex-wrap:wrap;justify-content:space-between;gap:1rem;margin-block:1rem}'
			. '.fl-cal-next{margin-inline-start:auto}'
			. '.fl-cal-head,.fl-cal-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:1px;margin:0;padding:0;list-style:none}'
			. '.fl-cal-head{font-size:.875em;font-weight:600;text-align:center}'
			. '.fl-cal-days>li{position:relative;min-height:6rem;padding:.25rem;outline:1px solid ' . $line . '}'
			. '.fl-cal-days>[aria-current]{outline:3px solid currentColor;outline-offset:-3px;font-weight:600}'
			. '.fl-cal-days ul{margin:.25rem 0 0;padding:0;list-style:none;font-size:.875em;overflow-wrap:anywhere}'
			. '.fl-cal-date{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}'
			. $starts
			. '@container (max-width:36rem){'
			. '.fl-cal-head,.fl-cal-n,.fl-cal-days>li:not(.has-events){display:none}'
			. '.fl-cal-days{display:block}'
			. '.fl-cal-days>li{min-height:0;padding:.75rem 0;outline:0;border-block-end:1px solid ' . $line . '}'
			. '.fl-cal-date{position:static;width:auto;height:auto;clip-path:none;white-space:normal;font-weight:600}}'
			. '@media (forced-colors:active){.fl-cal-days>li{outline-color:CanvasText}.fl-cal-days>[aria-current]{outline-color:Highlight}}';
	}
}
