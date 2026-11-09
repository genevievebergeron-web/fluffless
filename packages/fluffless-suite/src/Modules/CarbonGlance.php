<?php
/**
 * Aperçu carbone : un encadré du tableau de bord qui résume ce que mesurent déjà Empreinte et Rapport carbone.
 *
 * Aucun calcul neuf : les grammes viennent de la métadonnée d'Empreinte (SWDM v4), les visites et les
 * totaux de Carbon::report(), avec son cache de six heures. Sans visites, l'encadré montre quand même
 * les grammes par page mesurée.
 *
 * @package Fluffless_Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Sanitize;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Module Aperçu carbone.
 *
 * Grammes moyens par vue, total sur trente jours, pages les plus lourdes et tendance face aux trente
 * jours d'avant. Les barres sont des éléments en ligne, sans script ni librairie de graphiques.
 *
 * @phpstan-type Heavy array{post_id: int, title: string, grams: float}
 * @phpstan-type Summary array{measured: int, average: float, heaviest: list<Heavy>}
 * @phpstan-type Trend array{direction: 'down'|'up'|'flat'|'none', percent: int}
 */
final class CarbonGlance implements Module {

	/** Identifiant du widget, aussi l'id de sa boîte dans le tableau de bord. */
	public const WIDGET = 'fluffless_carbon_glance';

	/** Pages lourdes affichées. */
	public const SHOWN = 5;

	/** Contenus mesurés lus au plus, pour garder la requête légère. */
	public const MAX_POSTS = 500;

	/** Écart, en pour cent, sous lequel la tendance est dite stable. */
	public const FLAT_PERCENT = 5;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'carbon-glance';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Carbon at a glance', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Adds a dashboard box with the grams of CO2e per view, the total over the last 30 days, the heaviest pages and the trend. It reuses the figures of Page footprint and Carbon report; without visits, it still shows the grams of each measured page.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add_widget' ) );
	}

	/** Ajoute le widget pour qui gère le site, parmi les boîtes du cœur. */
	public static function add_widget(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		wp_add_dashboard_widget( self::WIDGET, __( 'Fluffless: carbon at a glance', 'fluffless-suite' ), array( self::class, 'render' ), null, null, 'side', 'core' );
	}

	/**
	 * Grammes par visite neuve de chaque contenu mesuré, du plus lourd au plus léger.
	 *
	 * @return array<int, float> Identifiant du contenu => grammes.
	 */
	public static function measures(): array {
		$ids   = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_POSTS,
				'fields'         => 'ids',
				'meta_key'       => Footprint::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- une clé indexée, bornée à MAX_POSTS.
				'no_found_rows'  => true,
			)
		);
		$grams = array();
		foreach ( $ids as $id ) {
			$stored = Footprint::stored( (int) $id );
			if ( null !== $stored ) {
				$grams[ (int) $id ] = $stored['new'];
			}
		}
		arsort( $grams );
		return $grams;
	}

	/**
	 * Résumé sans visites : nombre de pages mesurées, moyenne et pages les plus lourdes.
	 *
	 * @param array<int, float> $grams Identifiant => grammes, du plus lourd au plus léger.
	 * @return Summary
	 */
	public static function summary( array $grams ): array {
		$heaviest = array();
		foreach ( array_slice( $grams, 0, self::SHOWN, true ) as $id => $value ) {
			$heaviest[] = array(
				'post_id' => (int) $id,
				'title'   => get_the_title( (int) $id ),
				'grams'   => (float) $value,
			);
		}
		return array(
			'measured' => count( $grams ),
			'average'  => array() === $grams ? 0.0 : array_sum( $grams ) / count( $grams ),
			'heaviest' => $heaviest,
		);
	}

	/**
	 * Tendance entre deux moyennes par vue : baisse, hausse, stable, ou rien à comparer.
	 *
	 * @param float $now    Moyenne de la période.
	 * @param float $before Moyenne de la période d'avant.
	 * @return Trend
	 */
	public static function trend( float $now, float $before ): array {
		if ( $now <= 0.0 || $before <= 0.0 ) {
			return array(
				'direction' => 'none',
				'percent'   => 0,
			);
		}
		$percent = (int) round( ( $now - $before ) / $before * 100 );
		if ( abs( $percent ) < self::FLAT_PERCENT ) {
			$direction = 'flat';
		} else {
			$direction = $percent < 0 ? 'down' : 'up';
		}
		return array(
			'direction' => $direction,
			'percent'   => abs( $percent ),
		);
	}

	/**
	 * Rapports des trente derniers jours et des trente d'avant, ou null sans visites configurées.
	 *
	 * @param int|null $now Horodatage de référence, pour les tests.
	 * @return array{current: array<string, mixed>, previous: array<string, mixed>|null}|null
	 */
	public static function reports( ?int $now = null ): ?array {
		if ( ! Loader::is_active( Carbon::id() ) || '' !== Carbon::status() ) {
			return null;
		}
		$period = Carbon::period( '30', '', '', $now );
		$pages  = Carbon::pages( $period );
		if ( $pages instanceof WP_Error ) {
			return null;
		}
		$end      = ( new \DateTimeImmutable( $period['from'], wp_timezone() ) )->modify( '-1 day' );
		$before   = Carbon::period( 'custom', $end->modify( '-30 days' )->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), $now );
		$previous = Carbon::pages( $before );
		return array(
			'current'  => Carbon::report( $pages ),
			'previous' => $previous instanceof WP_Error ? null : Carbon::report( $previous ),
		);
	}

	/** Contenu du widget : chiffres, barres en ligne et liens, sans script ni feuille de style propre. */
	public static function render(): void {
		if ( ! Loader::is_active( Footprint::id() ) ) {
			echo '<p>' . esc_html__( 'Turn on Page footprint to measure your pages.', 'fluffless-suite' ) . '</p>';
			return;
		}
		$summary = self::summary( self::measures() );
		if ( 0 === $summary['measured'] ) {
			echo '<p>' . esc_html__( 'No page measured yet. Open a page in the editor and measure it from the Page footprint panel.', 'fluffless-suite' ) . '</p>';
			return;
		}
		$reports = self::reports();
		echo '<dl class="fluffless-glance">';
		if ( null !== $reports ) {
			$totals = $reports['current']['totals'];
			$trend  = self::trend( (float) $totals['per_view'], null === $reports['previous'] ? 0.0 : (float) $reports['previous']['totals']['per_view'] );
			self::figure( __( 'Average per view', 'fluffless-suite' ), Carbon::format_mass( (float) $totals['per_view'] ) );
			self::figure( __( 'Total, last 30 days', 'fluffless-suite' ), Carbon::format_mass( (float) $totals['grams'] ) );
			self::figure( __( 'Trend', 'fluffless-suite' ), self::trend_text( $trend ) );
		} else {
			self::figure( __( 'Average per page, first visit', 'fluffless-suite' ), Carbon::format_mass( $summary['average'] ) );
			self::figure( __( 'Measured pages', 'fluffless-suite' ), number_format_i18n( $summary['measured'] ) );
		}
		echo '</dl>';
		if ( null === $reports ) {
			echo '<p class="description">' . esc_html__( 'Totals and trend appear once Carbon report reads your visits.', 'fluffless-suite' ) . '</p>';
		}
		echo '<h3>' . esc_html__( 'Heaviest pages', 'fluffless-suite' ) . '</h3>';
		self::bars( $summary['heaviest'] );
		if ( null !== $reports && current_user_can( SettingsPage::CAPABILITY ) ) {
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( admin_url( 'admin.php?page=' . Carbon::PAGE ) ), esc_html__( 'Open the carbon report', 'fluffless-suite' ) );
		}
	}

	/**
	 * Phrase de tendance.
	 *
	 * @param array<string, mixed> $trend Tendance.
	 * @phpstan-param Trend $trend
	 */
	public static function trend_text( array $trend ): string {
		switch ( $trend['direction'] ) {
			case 'down':
				/* translators: %s: percentage. */
				return sprintf( __( 'Down %s%% from the previous 30 days', 'fluffless-suite' ), number_format_i18n( $trend['percent'] ) );
			case 'up':
				/* translators: %s: percentage. */
				return sprintf( __( 'Up %s%% from the previous 30 days', 'fluffless-suite' ), number_format_i18n( $trend['percent'] ) );
			case 'flat':
				return __( 'Stable from the previous 30 days', 'fluffless-suite' );
			default:
				return __( 'Not enough visits to compare', 'fluffless-suite' );
		}
	}

	/**
	 * Une paire terme et valeur.
	 *
	 * @param string $term  Libellé.
	 * @param string $value Valeur lisible.
	 */
	private static function figure( string $term, string $value ): void {
		printf( '<dt>%1$s</dt><dd style="margin:0 0 .5em;font-size:1.3em">%2$s</dd>', esc_html( $term ), esc_html( $value ) );
	}

	/**
	 * Barres horizontales en ligne, longueur relative à la page la plus lourde.
	 *
	 * @param array<int, array<string, mixed>> $heaviest Pages lourdes.
	 * @phpstan-param list<Heavy> $heaviest
	 */
	private static function bars( array $heaviest ): void {
		$max = array() === $heaviest ? 0.0 : $heaviest[0]['grams'];
		echo '<ul style="margin:0">';
		foreach ( $heaviest as $page ) {
			$width = $max > 0.0 ? max( 2, (int) round( $page['grams'] / $max * 100 ) ) : 0;
			$title = '' === $page['title'] ? __( '(no title)', 'fluffless-suite' ) : $page['title'];
			$link  = get_edit_post_link( $page['post_id'] );
			printf(
				'<li style="margin:0 0 .6em"><span style="display:flex;justify-content:space-between;gap:1em">%1$s<span>%2$s</span></span><span aria-hidden="true" style="display:block;height:.4em;width:%3$d%%;background:currentColor;opacity:.45;border-radius:2px"></span></li>',
				null === $link ? esc_html( $title ) : '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>',
				esc_html( Carbon::format_mass( $page['grams'] ) ),
				(int) $width
			);
		}
		echo '</ul>';
	}
}
