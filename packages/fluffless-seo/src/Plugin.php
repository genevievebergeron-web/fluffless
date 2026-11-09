<?php
/**
 * Démarrage : pose les crochets de chaque famille allumée.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Une famille éteinte ne pose aucun crochet. Si une autre extension SEO est active,
 * seuls l'écran de réglages et l'importation restent : aucune balise en double.
 */
final class Plugin {

	/** Constante de chaque extension SEO reconnue => nom affiché. */
	private const CONFLICTS = array(
		'WPSEO_VERSION'             => 'Yoast SEO',
		'RANK_MATH_VERSION'         => 'Rank Math',
		'SEOPRESS_VERSION'          => 'SEOPress',
		'THE_SEO_FRAMEWORK_VERSION' => 'The SEO Framework',
		'AIOSEO_VERSION'            => 'All in One SEO',
		'SLIM_SEO_VER'              => 'Slim SEO',
		'SQ_VERSION'                => 'Squirrly SEO',
	);

	/** Point d'entrée, sur plugins_loaded (priorité 5). */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );
		// Suite manquante (désactivé par WP-CLI ou supprimé à la main) : rien d'autre ne peut marcher.
		if ( ! class_exists( Sanitize::class ) ) {
			add_action( 'admin_notices', array( self::class, 'notice_missing_tools' ) );
			return;
		}
		add_action( 'init', array( Meta::class, 'register' ) );
		add_action( 'init', array( Breadcrumbs::class, 'register_block' ) );
		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 20 );
		add_action(
			Store::CRON,
			static function (): void {
				Store::purge();
			}
		);
		if ( is_admin() ) {
			Admin\SettingsScreen::register();
			add_action( 'admin_init', array( Store::class, 'maybe_upgrade' ) );
		}
		// Les résumés des commandes sont traduits : cli_init (WP-CLI, sur init) et non plugins_loaded,
		// sinon WordPress 6.7 refuse le domaine de texte (« triggered too early »).
		add_action( 'cli_init', array( Cli::class, 'register' ) );
		if ( null !== self::conflict() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( self::class, 'notice_rank_math_unconfigured' ) );
			}
			return;
		}
		Head::register();
		Editor::register();
		if ( Settings::on( 'sitemaps' ) ) {
			Sitemaps::register();
		}
		if ( Settings::on( 'redirects' ) || Settings::on( 'log404' ) ) {
			Redirects::register();
		}
		Robots::register();
	}

	/** Autre extension SEO active, ou null. */
	public static function conflict(): ?string {
		$found = null;
		foreach ( self::CONFLICTS as $constant => $name ) {
			if ( defined( $constant ) ) {
				$found = $name;
				break;
			}
		}
		/**
		 * Extension SEO qui met Fluffless SEO en retrait (sortie publique coupée, importation proposée).
		 *
		 * @param string|null $found Nom de l'extension, ou null.
		 */
		$filtered = apply_filters( 'fluffless_seo_suite_conflict', $found );
		return is_string( $filtered ) && '' !== $filtered ? $filtered : null;
	}

	/** Traductions livrées dans languages/. */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'fluffless-seo', false, dirname( plugin_basename( FILE ) ) . '/languages' );
	}

	/** Activation : tables et tâche quotidienne de purge. */
	public static function activate(): void {
		if ( class_exists( Sanitize::class ) ) {
			Store::install();
		}
		if ( false === wp_next_scheduled( Store::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Store::CRON );
		}
	}

	/**
	 * Multisite : un site créé après l'activation réseau reçoit ses tables et sa purge tout de suite,
	 * sans attendre une visite de son admin (sinon chaque 404 de ce site écrit une erreur SQL).
	 * Priorité 20 : après wp_initialize_site de WordPress (10), qui crée les tables du site.
	 *
	 * @param \WP_Site $site Site créé.
	 */
	public static function initialize_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::activate();
		} finally {
			restore_current_blog();
		}
	}

	/** Désactivation : la tâche planifiée s'arrête ; les données restent. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Store::CRON );
	}

	/**
	 * Avis : Rank Math est actif mais son assistant n'a jamais été terminé. Fluffless SEO reste en retrait,
	 * sans rien changer à son comportement ; l'avis explique pourquoi les balises peuvent manquer.
	 */
	public static function notice_rank_math_unconfigured(): void {
		if ( 'Rank Math' !== self::conflict() || ! current_user_can( 'activate_plugins' ) || get_option( 'rank_math_is_configured' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Rank Math is active but its setup is not finished, and Fluffless SEO stays quiet while Rank Math is active. Finish the Rank Math setup, or deactivate Rank Math to let Fluffless SEO handle your search tags.', 'fluffless-seo' ) . '</p></div>';
	}

	/** Avis : Fluffless Suite absent. */
	public static function notice_missing_tools(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Fluffless SEO needs Fluffless Suite. Activate Fluffless Suite to turn search features back on.', 'fluffless-seo' ) . '</p></div>';
		}
	}
}
