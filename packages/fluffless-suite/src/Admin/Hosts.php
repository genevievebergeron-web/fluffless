<?php
/**
 * Écran « Serveurs contactés » : ce que le site contacte avec ses réglages courants.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

use Fluffless\Suite\Core\Hosts as Registry;
use Fluffless\Suite\Modules\Audit;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture seule : aucun formulaire, aucune action, aucune requête. Toujours affiché, même quand
 * Requêtes sortantes est éteint. Réservé à SettingsPage::CAPABILITY.
 *
 * @phpstan-import-type Entry from Registry
 */
final class Hosts {

	public const SLUG = 'fluffless-hosts';

	/** Onglet du groupe Réglages, après le Bilan. */
	public static function add_page(): void {
		Menu::add_tab( 'settings', self::SLUG, static fn(): string => __( 'Contacted servers', 'fluffless-suite' ), array( self::class, 'render' ), 25 );
	}

	/** Adresse de l'écran. */
	public static function url(): string {
		return Menu::url( self::SLUG );
	}

	/** Écran. */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		wp_enqueue_script( 'fluffless-copy', plugins_url( 'assets/admin/copy.js', FILE ), array(), VERSION, true );
		$entries = Registry::all();
		echo '<div class="wrap fl-settings">';
		Screen::header( __( 'Contacted servers', 'fluffless-suite' ), __( 'The servers your site contacts with your current settings. Nothing is checked online to build this list.', 'fluffless-suite' ) );
		$titles = array(
			'server'        => __( 'Your server', 'fluffless-suite' ),
			'browser'       => __( 'Visitors’ browsers', 'fluffless-suite' ),
			'admin_browser' => __( 'People who manage the site (their browser)', 'fluffless-suite' ),
		);
		foreach ( $titles as $side => $title ) {
			$rows = Registry::side( $entries, $side );
			echo '<h2>' . esc_html( $title ) . '</h2>';
			if ( array() === $rows ) {
				echo '<p class="fl-muted">' . esc_html__( 'None with your current settings.', 'fluffless-suite' ) . '</p>';
			} else {
				self::table( $rows, $title );
			}
			if ( 'server' === $side ) {
				echo '<p class="fl-muted">' . esc_html__( 'Every request from your server tells WordPress and the services above your site’s address, except the Fluffless API.', 'fluffless-suite' ) . '</p>';
			}
		}
		self::seen();
		echo '<p>' . esc_html__( 'Other plugins can contact other servers. The Fluff check and Outgoing requests show them.', 'fluffless-suite' ) . '</p>';
		self::copy( $entries );
		echo '</div>';
	}

	/**
	 * Tableau d'un côté.
	 *
	 * @param array<int, array<string, mixed>> $rows    Entrées.
	 * @param string                           $caption Légende.
	 * @phpstan-param list<Entry> $rows
	 */
	private static function table( array $rows, string $caption ): void {
		echo '<div class="fl-table-scroll"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption><thead><tr>';
		foreach ( array( __( 'Service', 'fluffless-suite' ), __( 'Domain', 'fluffless-suite' ), __( 'When', 'fluffless-suite' ), __( 'Data sent', 'fluffless-suite' ), __( 'Status', 'fluffless-suite' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr data-fl-status="%1$s"><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_attr( $row['status'] ),
				esc_html( implode( '; ', $row['service'] ) ),
				self::domain( $row ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans domain().
				esc_html( implode( '; ', array_map( array( Registry::class, 'when_label' ), $row['when'] ) ) ),
				esc_html( implode( '; ', $row['data'] ) ),
				esc_html( Registry::status_label( $row['status'] ) )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Domaine affiché, échappé.
	 *
	 * @param array<string, mixed> $row Entrée.
	 * @phpstan-param Entry $row
	 */
	private static function domain( array $row ): string {
		if ( 'linked' === $row['scope'] ) {
			return esc_html__( 'Depends on your content', 'fluffless-suite' );
		}
		if ( '' === $row['host'] ) {
			return esc_html__( 'Not set', 'fluffless-suite' );
		}
		return '<code>' . esc_html( $row['host'] ) . '</code>';
	}

	/** Domaines tiers vus au dernier Bilan, lus dans son rapport en cache. */
	private static function seen(): void {
		echo '<h2>' . esc_html__( 'Seen at the last Fluff check', 'fluffless-suite' ) . '</h2>';
		$report = Audit::cached();
		$hosts  = is_array( $report['third_party_hosts'] ?? null ) ? array_filter( $report['third_party_hosts'], 'is_string' ) : null;
		if ( null === $hosts ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( Menu::url( Audit::PAGE ) ),
				esc_html__( 'Run the Fluff check to see what your home page loads.', 'fluffless-suite' )
			);
			return;
		}
		if ( array() === $hosts ) {
			echo '<p class="fl-muted">' . esc_html__( 'Your home page loads no file from another domain.', 'fluffless-suite' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $hosts as $host ) {
			echo '<li><code>' . esc_html( $host ) . '</code></li>';
		}
		echo '</ul>';
	}

	/**
	 * Liste à copier : un champ en lecture seule, lisible sans JavaScript ; le bouton n'apparaît
	 * que si le navigateur sait copier (assets/admin/copy.js).
	 *
	 * @param array<int, array<string, mixed>> $entries Entrées.
	 * @phpstan-param list<Entry> $entries
	 */
	private static function copy( array $entries ): void {
		$lines = array();
		foreach ( $entries as $entry ) {
			if ( in_array( $entry['status'], array( 'inactive', 'env_off' ), true ) ) {
				continue;
			}
			$domain  = 'linked' === $entry['scope'] ? __( 'Depends on your content', 'fluffless-suite' ) : $entry['host'];
			$lines[] = implode( '; ', $entry['service'] ) . ': ' . $domain . ' (' . implode( '; ', array_map( array( Registry::class, 'when_label' ), $entry['when'] ) ) . ')';
		}
		echo '<h2><label for="fl-hosts-list">' . esc_html__( 'Copy the list', 'fluffless-suite' ) . '</label></h2>';
		printf( '<textarea id="fl-hosts-list" class="large-text code" rows="8" readonly>%s</textarea>', esc_textarea( implode( "\n", $lines ) ) );
		printf(
			'<p><button type="button" class="button" data-fl-copy="fl-hosts-list" data-fl-copy-done="%1$s" data-fl-copy-fail="%2$s" hidden>%3$s</button> <span class="fl-muted" role="status" data-fl-copy-status></span></p>',
			esc_attr__( 'List copied.', 'fluffless-suite' ),
			esc_attr__( 'Copy failed: select the text above instead.', 'fluffless-suite' ),
			esc_html__( 'Copy the list', 'fluffless-suite' )
		);
	}
}
