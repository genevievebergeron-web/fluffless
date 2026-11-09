<?php
/**
 * Module Mode maintenance : ferme le site aux visiteurs le temps d'un chantier, ou l'annonce
 * avant son lancement.
 *
 * Deux modes. « Maintenance » répond 503 avec Retry-After : les moteurs gardent leurs pages et
 * repassent. « Bientôt en ligne » répond 200 sans indexation : la page d'attente peut être vue,
 * mais elle n'entre pas dans l'index. Dans les deux cas, la page est composée de blocs du cœur
 * rendus dans les styles du thème (palette, polices, espacements), sans script.
 *
 * Qui voit le site quand même : quiconque peut modifier des contenus (edit_posts), avec un
 * rappel dans la barre d'administration. L'administration, la connexion, l'API REST, le cron,
 * AJAX et WP-CLI ne sont jamais bloqués. Le filtre fluffless_maintenance_bypass laisse une
 * extension ouvrir le site à d'autres (adresse IP d'un client, par exemple).
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;
use WP_Admin_Bar;

defined( 'ABSPATH' ) || exit;

/**
 * Mode maintenance ou bientôt en ligne.
 */
final class Maintenance implements Module {

	/** Modes reconnus. */
	public const MODES = array( 'maintenance', 'soon' );

	/** Délai annoncé aux moteurs dans Retry-After, en secondes (une heure). */
	public const RETRY_AFTER = 3600;

	/** Identifiant du module. */
	public static function id(): string {
		return 'maintenance';
	}

	/** Nom affiché. */
	public static function label(): string {
		return __( 'Maintenance mode', 'fluffless-suite' );
	}

	/** Description affichée. */
	public static function description(): string {
		return __( 'Shows visitors a waiting page in the site’s own styles while you work, or before launch. People who can edit content keep seeing the site.', 'fluffless-suite' );
	}

	/** Inactif sur une installation neuve. */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** Valeurs par défaut. */
	public static function defaults(): array {
		return array(
			'mode'    => 'maintenance',
			'title'   => '',
			'message' => '',
			'button'  => true,
		);
	}

	/**
	 * Assainissement de la section.
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** Champs des réglages. */
	public static function fields(): array {
		return array(
			'mode'    => array(
				'type'    => 'choice',
				'label'   => __( 'Mode', 'fluffless-suite' ),
				'help'    => __( 'Maintenance answers with status 503 and asks search engines to come back in an hour: they keep your pages as they are. Coming soon answers with status 200 and asks them not to index the waiting page.', 'fluffless-suite' ),
				'options' => array(
					'maintenance' => __( 'Maintenance: the site is closed for a while', 'fluffless-suite' ),
					'soon'        => __( 'Coming soon: the site is not launched yet', 'fluffless-suite' ),
				),
			),
			'title'   => array(
				'type'  => 'text',
				'label' => __( 'Title', 'fluffless-suite' ),
				'help'  => __( 'Empty: “Back soon” or “Coming soon”, depending on the mode.', 'fluffless-suite' ),
			),
			'message' => array(
				'type'  => 'textarea',
				'label' => __( 'Message', 'fluffless-suite' ),
				'help'  => __( 'Plain text, one paragraph per line. Empty: a short sentence that fits the mode.', 'fluffless-suite' ),
			),
			'button'  => array(
				'type'  => 'bool',
				'label' => __( 'Show a “Log in” link at the bottom of the page', 'fluffless-suite' ),
				'help'  => __( 'For the people who work on the site. The link goes to the login page. It is left out while Basic security hides the login address, so visitors never see it.', 'fluffless-suite' ),
			),
		);
	}

	/** État affiché sous la description. */
	public static function status(): string {
		if ( ! Options::is_enabled( self::id() ) ) {
			return '';
		}
		return 'soon' === self::mode()
			? __( 'Visitors see the coming soon page. Log in to see the site.', 'fluffless-suite' )
			: __( 'Visitors see the maintenance page. Log in to see the site.', 'fluffless-suite' );
	}

	/** Pose les crochets. */
	public function register(): void {
		add_action( 'template_redirect', array( self::class, 'intercept' ), 0 );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 100 );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	/** Mode en vigueur, parmi MODES. */
	public static function mode(): string {
		$mode = Options::get_string( self::id(), 'mode' );
		return in_array( $mode, self::MODES, true ) ? $mode : 'maintenance';
	}

	/**
	 * La requête courante doit-elle recevoir la page d'attente ?
	 *
	 * Jamais en administration, en REST, en cron, en AJAX, sous WP-CLI, sur la page de connexion,
	 * ni pour quiconque peut modifier des contenus. Le filtre fluffless_maintenance_bypass
	 * (booléen, vrai pour laisser passer) tranche en dernier.
	 */
	public static function should_block(): bool {
		$open = is_admin()
			|| ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) )
			|| ( defined( 'DOING_CRON' ) && constant( 'DOING_CRON' ) )
			|| ( defined( 'DOING_AJAX' ) && constant( 'DOING_AJAX' ) )
			|| ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) )
			|| ( defined( 'XMLRPC_REQUEST' ) && constant( 'XMLRPC_REQUEST' ) ) // @phpstan-ignore booleanAnd.rightAlwaysTrue (stub WordPress : la constante est typée true, sa valeur réelle ne l'est pas toujours)
			|| ( function_exists( 'is_login' ) && is_login() )
			|| current_user_can( 'edit_posts' );
		/**
		 * Laisse passer une requête malgré le mode maintenance.
		 *
		 * @param bool $bypass Vrai pour montrer le site.
		 */
		return ! apply_filters( 'fluffless_maintenance_bypass', $open );
	}

	/** Sur template_redirect : sert la page d'attente et arrête la requête. */
	public static function intercept(): void {
		if ( ! self::should_block() ) {
			return;
		}
		foreach ( self::headers( self::mode() ) as $header ) {
			header( $header );
		}
		echo self::page(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- composé de blocs rendus et de valeurs échappées.
		exit;
	}

	/**
	 * En-têtes HTTP du mode.
	 *
	 * @param string $mode maintenance ou soon.
	 * @return list<string>
	 */
	public static function headers( string $mode ): array {
		$headers = array( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		if ( 'soon' === $mode ) {
			$headers[] = 'HTTP/1.1 200 OK';
			$headers[] = 'X-Robots-Tag: noindex, nofollow';
			return $headers;
		}
		$headers[] = 'HTTP/1.1 503 Service Unavailable';
		$headers[] = 'Retry-After: ' . self::RETRY_AFTER;
		return $headers;
	}

	/**
	 * Sur wp_robots : la page d'attente n'entre pas dans l'index.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public static function robots( array $robots ): array {
		if ( self::should_block() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/** Titre affiché : réglage, sinon un titre selon le mode. */
	public static function title(): string {
		$title = trim( Options::get_string( self::id(), 'title' ) );
		if ( '' !== $title ) {
			return $title;
		}
		return 'soon' === self::mode() ? __( 'Coming soon', 'fluffless-suite' ) : __( 'Back soon', 'fluffless-suite' );
	}

	/**
	 * Paragraphes du message : réglage (une ligne par paragraphe), sinon une phrase selon le mode.
	 *
	 * @return list<string>
	 */
	public static function paragraphs(): array {
		$lines = preg_split( '/\R+/', trim( Options::get_string( self::id(), 'message' ) ) );
		$lines = array_values( array_filter( array_map( 'trim', false === $lines ? array() : $lines ), static fn( string $line ): bool => '' !== $line ) );
		if ( array() !== $lines ) {
			return $lines;
		}
		return array(
			'soon' === self::mode()
				? __( 'This site is being prepared. Come back in a little while.', 'fluffless-suite' )
				: __( 'This site is under maintenance for a short while. Thank you for your patience.', 'fluffless-suite' ),
		);
	}

	/** Blocs de la page d'attente, sérialisés : titre du site, titre, paragraphes, lien de connexion. */
	public static function blocks(): string {
		$blocks  = '<!-- wp:group {"tagName":"main","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->';
		$blocks .= '<main class="wp-block-group fluffless-attente" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">';
		$blocks .= '<!-- wp:site-title {"level":0,"isLink":false} /-->';
		$blocks .= '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">' . esc_html( self::title() ) . '</h1><!-- /wp:heading -->';
		foreach ( self::paragraphs() as $paragraph ) {
			$blocks .= '<!-- wp:paragraph --><p>' . esc_html( $paragraph ) . '</p><!-- /wp:paragraph -->';
		}
		// Le lien mènerait à l'adresse cachée par Sécurité de base : toute visiteuse la lirait.
		if ( Options::get_bool( self::id(), 'button' ) && ! Security::hides_login() ) {
			$blocks .= '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size"><a href="' . esc_url( wp_login_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log in', 'fluffless-suite' ) . '</a></p><!-- /wp:paragraph -->';
		}
		return $blocks . '</main><!-- /wp:group -->';
	}

	/**
	 * Page complète : en-tête et pied du thème par wp_head et wp_footer (styles globaux, favicon),
	 * aucun script du module. Les blocs sont rendus avant wp_footer pour que leurs styles soient
	 * imprimés.
	 */
	public static function page(): string {
		$content = do_blocks( self::blocks() );
		// Le filtre pre_get_document_title court-circuite l'échappement du cœur : on échappe ici.
		$title = static fn(): string => esc_html( self::title() . ' – ' . get_bloginfo( 'name' ) );
		add_filter( 'pre_get_document_title', $title );
		ob_start();
		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><meta name="viewport" content="width=device-width, initial-scale=1">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributs de langue échappés par le cœur.
		if ( ! current_theme_supports( 'title-tag' ) ) {
			echo '<title>' . $title() . '</title>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans la fermeture.
		}
		wp_head();
		remove_filter( 'pre_get_document_title', $title );
		echo '</head><body class="fluffless-maintenance">';
		wp_body_open();
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- blocs rendus par do_blocks.
		wp_footer();
		echo '</body></html>';
		return (string) ob_get_clean();
	}

	/**
	 * Sur admin_bar_menu : rappel visible pour ceux qui voient le site malgré le mode.
	 *
	 * @param WP_Admin_Bar $bar Barre d'administration.
	 */
	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'fluffless-maintenance',
				'title' => 'soon' === self::mode() ? __( 'Coming soon page is on', 'fluffless-suite' ) : __( 'Maintenance mode is on', 'fluffless-suite' ),
				'href'  => admin_url( 'admin.php?page=fluffless-settings' ),
				'meta'  => array( 'class' => 'fluffless-maintenance-bar' ),
			)
		);
	}
}
