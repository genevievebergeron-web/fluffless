<?php
/**
 * Accueil Fluffless : trois chiffres, puis les prochaines actions utiles.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Audit;
use Fluffless\Suite\Modules\Footprint;
use Fluffless\Suite\Modules\Media\Quarantine;
use Fluffless\Suite\Modules\MediaWebp;
use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Modules\Updates;
use Fluffless\Suite\Presentation\Patterns;

defined( 'ABSPATH' ) || exit;

/**
 * Crochet public :
 * - filtre fluffless_admin_actions : list<array{text: string, url: string}>, prochaines actions affichées sur l'accueil.
 *
 * @phpstan-type Action array{text: string, url: string}
 * @phpstan-type Step array{text: string, url: string, done: bool}
 */
final class Home {

	/** Bornes : actions affichées, mesures de pages lues pour la moyenne. */
	public const MAX_ACTIONS  = 8;
	public const MAX_MEASURES = 200;

	/** Écran d'accueil. */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		$modules = Loader::modules();
		echo '<div class="wrap fl-home">';
		// Une seule touche d'humour sur cet écran, et jamais dans une erreur.
		Screen::header( 'Fluffless', __( 'Each switch removes something your visitors never asked for. Less <em>fluff</em>, same site.', 'fluffless-suite' ) );

		$active = count( array_filter( array_keys( $modules ), array( Options::class, 'is_enabled' ) ) );
		$bytes  = SettingsPage::visitor_js_total( $modules );
		echo '<dl class="fl-figures">';
		self::figure( number_format_i18n( $active ), _n( 'module on', 'modules on', $active, 'fluffless-suite' ), '', 'power', 'present' );
		self::figure( 0 === $bytes ? __( '0 KB', 'fluffless-suite' ) : (string) size_format( $bytes, 1 ), __( 'of JavaScript added for visitors', 'fluffless-suite' ), __( 'File size before compression; usually less is sent over the network.', 'fluffless-suite' ), 'feather', 'lighten' );
		$weight = self::average_page_weight();
		if ( null !== $weight ) {
			self::figure(
				(string) size_format( $weight['bytes'], 1 ),
				__( 'average page weight', 'fluffless-suite' ),
				/* translators: %s: number of pages */
				sprintf( _n( 'Measured on %s page.', 'Measured on %s pages.', $weight['pages'], 'fluffless-suite' ), number_format_i18n( $weight['pages'] ) ),
				'scale',
				'measure'
			);
		}
		echo '</dl>';

		self::first_steps_section();
		echo '<section class="fl-next" aria-labelledby="fl-next-title"><h2 id="fl-next-title">' . esc_html__( 'Next steps', 'fluffless-suite' ) . '</h2>';
		$actions = self::actions();
		if ( array() === $actions ) {
			echo '<p class="fl-muted">' . esc_html__( 'Nothing waiting for you.', 'fluffless-suite' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $actions as $action ) {
				// Flèche : SVG généré, balises et attributs en liste blanche (tools/build-icons.mjs).
				printf( '<li><a href="%1$s"><span>%2$s</span>%3$s</a></li>', esc_url( $action['url'] ), esc_html( $action['text'] ), Icon::svg( 'arrow-right' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- voir ci-dessus.
			}
			echo '</ul>';
		}
		echo '</section></div>';
	}

	/**
	 * Trois étapes numérotées pour démarrer, affichées tant qu'une reste à faire (C26).
	 * Les étapes faites restent visibles, cochées : la liste rend compte du chemin parcouru.
	 *
	 * @return list<Step>
	 */
	public static function first_steps(): array {
		return array(
			array(
				'text' => ThemeNotice::installed() ? __( 'Activate the Fluffless theme', 'fluffless-suite' ) : __( 'Upload and activate the Fluffless theme (ZIP)', 'fluffless-suite' ),
				'url'  => '' !== ThemeNotice::theme_url() ? ThemeNotice::theme_url() : admin_url( 'themes.php' ),
				'done' => Patterns::applies(),
			),
			array(
				'text' => __( 'Create your first page', 'fluffless-suite' ),
				'url'  => admin_url( 'post-new.php?post_type=page' ),
				'done' => self::has_own_page(),
			),
			array(
				'text' => __( 'Choose your colours', 'fluffless-suite' ),
				'url'  => Options::is_enabled( Palette::id() ) ? Menu::url( Palette::PAGE ) : SettingsPage::url( 'fl-title-' . Palette::id() ),
				'done' => Palette::is_custom(),
			),
		);
	}

	/**
	 * Une page que la personne a écrite : WordPress crée lui-même la page d'exemple et le brouillon
	 * de politique de confidentialité, et une installation neuve ne doit pas cocher l'étape pour eux.
	 */
	public static function has_own_page(): bool {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'post__not_in'   => array_filter( array( (int) get_option( 'wp_page_for_privacy_policy' ) ) ),
				'posts_per_page' => 3,
				'no_found_rows'  => true,
			)
		);
		foreach ( $pages as $page ) {
			if ( self::is_own_page( $page ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Vrai sauf pour la page d'exemple de l'installation (slug localisé par WordPress).
	 *
	 * @param \WP_Post $page Page à juger.
	 */
	public static function is_own_page( \WP_Post $page ): bool {
		// Appel direct, pas _x() : l'extracteur de chaînes ne distingue pas les domaines et réclamerait ce slug de WordPress au .po de la Suite.
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.LowLevelTranslationFunction -- on relit le slug que WordPress donne à sa page d'exemple.
		$sample = array( 'sample-page', sanitize_title( translate_with_gettext_context( 'sample-page', 'Default page slug', 'default' ) ) );
		return ! in_array( $page->post_name, $sample, true );
	}

	/** Vrai tant qu'une des premières étapes reste à faire. */
	public static function steps_left(): bool {
		return array() !== array_filter( self::first_steps(), static fn( array $step ): bool => ! $step['done'] );
	}

	/** Liste des premières étapes, ou rien quand tout est fait. */
	private static function first_steps_section(): void {
		if ( ! self::steps_left() ) {
			return;
		}
		$steps = self::first_steps();
		echo '<section class="fl-start" aria-labelledby="fl-start-title"><h2 id="fl-start-title">' . esc_html__( 'Getting started', 'fluffless-suite' ) . '</h2><ol>';
		foreach ( $steps as $step ) {
			// Coche : SVG généré, balises et attributs en liste blanche (tools/build-icons.mjs).
			if ( $step['done'] ) {
				printf( '<li class="fl-start__done">%2$s<span>%1$s</span> <span class="screen-reader-text">%3$s</span></li>', esc_html( $step['text'] ), Icon::svg( 'check' ), esc_html__( '(done)', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- voir ci-dessus.
			} else {
				printf( '<li><a href="%1$s"><span>%2$s</span>%3$s</a></li>', esc_url( $step['url'] ), esc_html( $step['text'] ), Icon::svg( 'arrow-right' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- voir ci-dessus.
			}
		}
		echo '</ol></section>';
	}

	/**
	 * Un chiffre : valeur en grand, libellé, précision facultative.
	 *
	 * @param string $value Valeur affichée.
	 * @param string $label Ce que la valeur compte.
	 * @param string $note  Précision ('' : aucune).
	 * @param string $icon  Nom de l'icône.
	 * @param string $hue   Teinte de la tuile (lighten, found, measure, present, care).
	 */
	private static function figure( string $value, string $label, string $note, string $icon, string $hue ): void {
		// Tuile : SVG généré, balises et attributs en liste blanche (tools/build-icons.mjs).
		printf( '<div class="fl-figure fl-hue-%1$s"><dt>%2$s</dt><dd>%4$s<span>%3$s</span></dd>', esc_attr( $hue ), esc_html( $label ), esc_html( $value ), Icon::tile( $icon ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- voir ci-dessus.
		if ( '' !== $note ) {
			echo '<dd class="fl-muted">' . esc_html( $note ) . '</dd>';
		}
		echo '</div>';
	}

	/**
	 * Poids moyen mesuré par le module Empreinte (dernière mesure de chaque contenu), ou null sans mesure.
	 *
	 * @return array{bytes: int, pages: int}|null
	 */
	public static function average_page_weight(): ?array {
		if ( ! Options::is_enabled( Footprint::id() ) ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'publish',
				'meta_key'         => Footprint::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- borné à MAX_MEASURES, écran d'administration seulement.
				'fields'           => 'ids',
				'numberposts'      => self::MAX_MEASURES,
				'no_found_rows'    => true,
				'orderby'          => 'modified',
				'suppress_filters' => false,
			)
		);
		$ids = array_map( 'intval', $ids );
		// Une requête pour toutes les métadonnées, au lieu d'une par contenu.
		update_meta_cache( 'post', $ids );
		$total = 0;
		$pages = 0;
		foreach ( $ids as $id ) {
			$result = Footprint::stored( $id );
			if ( null !== $result && $result['bytes'] > 0 ) {
				$total += $result['bytes'];
				++$pages;
			}
		}
		return 0 === $pages ? null : array(
			'bytes' => intdiv( $total, $pages ),
			'pages' => $pages,
		);
	}

	/**
	 * Prochaines actions : celles de Fluffless, puis celles du filtre, validées et bornées.
	 *
	 * @return list<Action>
	 */
	public static function actions(): array {
		/**
		 * Filtre les prochaines actions de l'accueil.
		 *
		 * @param list<Action> $actions Texte court et adresse de l'écran où agir.
		 */
		return self::valid_actions( apply_filters( 'fluffless_admin_actions', self::default_actions() ) );
	}

	/**
	 * Actions calculées sans balayage coûteux : médias en quarantaine, bilan jamais lancé.
	 *
	 * @return list<Action>
	 */
	private static function default_actions(): array {
		$actions = array();
		if ( Options::is_enabled( MediaWebp::id() ) ) {
			$count = count( Quarantine::items() );
			if ( $count > 0 ) {
				$actions[] = array(
					/* translators: %s: number of images */
					'text' => sprintf( _n( '%s unused image in quarantine to review', '%s unused images in quarantine to review', $count, 'fluffless-suite' ), number_format_i18n( $count ) ),
					'url'  => Quarantine::url(),
				);
			}
		}
		$renew = Options::is_enabled( Updates::id() ) ? Updates::renew_url() : '';
		if ( '' !== $renew ) {
			// Licence échue : la clé est là, c'est le renouvellement qui manque.
			$actions[] = array(
				'text' => __( 'Renew your licence to get updates again', 'fluffless-suite' ),
				'url'  => $renew,
			);
		} elseif ( Options::is_enabled( Updates::id() ) && Updates::needs_licence() ) {
			$actions[] = array(
				'text' => __( 'Add your licence key to get updates', 'fluffless-suite' ),
				'url'  => SettingsPage::url( 'fl-title-' . Updates::id() ),
			);
		}
		if ( Options::is_enabled( Audit::id() ) && null === Audit::cached() ) {
			$actions[] = array(
				'text' => __( 'Run the Fluff check', 'fluffless-suite' ),
				'url'  => Menu::url( Audit::PAGE ),
			);
		}
		return $actions;
	}

	/**
	 * Garde les actions valides ; un tiers peut renvoyer n'importe quoi dans le filtre.
	 *
	 * @param mixed $actions Valeur rendue par le filtre.
	 * @return list<Action>
	 */
	public static function valid_actions( $actions ): array {
		$valid = array();
		foreach ( is_array( $actions ) ? $actions : array() as $action ) {
			if ( ! is_array( $action ) || ! is_string( $action['text'] ?? null ) || ! is_string( $action['url'] ?? null ) ) {
				continue;
			}
			$text = trim( $action['text'] );
			$url  = esc_url_raw( $action['url'], array( 'http', 'https' ) );
			if ( '' !== $text && '' !== $url ) {
				$valid[] = array(
					'text' => $text,
					'url'  => $url,
				);
			}
			if ( count( $valid ) >= self::MAX_ACTIONS ) {
				break;
			}
		}
		return $valid;
	}
}
