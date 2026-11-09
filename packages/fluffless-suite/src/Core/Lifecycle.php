<?php
/**
 * Activation, désactivation et désinstallation.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Seul endroit qui connaît toutes les données créées par l'extension :
 * uninstall.php s'en sert sans charger le reste du code.
 */
final class Lifecycle {

	public const TRANSIENT_HTTP_REFUSALS       = 'fluffless_http_refusals';
	public const TRANSIENT_UPLOAD_NOTICES      = 'fluffless_upload_notices';
	public const TRANSIENT_STATS_SCRIPT        = 'fluffless_stats_script';
	public const TRANSIENT_AUDIT_REPORT        = 'fluffless_audit_report';
	public const TRANSIENT_HOST_STATUS         = 'fluffless_host_status';
	public const TRANSIENT_STATS_FAILURE       = 'fluffless_stats_script_failure';
	public const TRANSIENT_UNUSED_MEDIA        = 'fluffless_unused_media';
	public const TRANSIENT_MINIFY              = 'fluffless_minify_measure';
	public const TRANSIENT_UPDATES             = 'fluffless_updates';
	public const TRANSIENT_LICENCE             = 'fluffless_licence_status';
	public const TRANSIENT_API_SILENT          = 'fluffless_api_silent';
	public const TRANSIENT_REPORTS             = 'fluffless_report_log';
	public const TRANSIENT_MAIL_FAILURES       = 'fluffless_mail_failures';
	public const TRANSIENT_MAIL_TEST           = 'fluffless_mail_test';
	public const TRANSIENT_CARBON              = 'fluffless_carbone';
	public const TRANSIENT_WELCOME             = 'fluffless_welcome';
	public const TRANSIENT_AUDIT_IMAGES        = 'fluffless_audit_images';
	public const TRANSIENT_NEWSLETTER_FAILURES = 'fluffless_newsletter_failures';
	public const TRANSIENT_NEWSLETTER_TEST     = 'fluffless_newsletter_test';
	public const TRANSIENT_NEWSLETTER_LOCK     = 'fluffless_newsletter_lock';

	/** Clé de licence des mises à jour : option à part, sans chargement automatique ni exposition REST. */
	public const OPTION_LICENCE = 'fluffless_licence';

	/** Valeurs d'origine des styles globaux touchés par le module Forme. */
	public const OPTION_SHAPE = 'fluffless_shape';

	/** Palette appliquée et valeur d'origine de la palette des styles globaux (module Palette). */
	public const OPTION_PALETTE = 'fluffless_palette';

	/** Bilan des conversions WebP (module Médias). */
	public const OPTION_WEBP_REPORT = 'fluffless_webp_report';

	/** Définitions des formulaires rendus (module Formulaire), sans autochargement. */
	public const OPTION_FORMS = 'fluffless_forms';

	/** Règles du module Conditions d'affichage. */
	public const OPTION_CONDITIONS = 'fluffless_conditions';

	/** Fiche de boucle par type de contenu (module Fiches de boucle). */
	public const OPTION_FICHES = 'fluffless_fiches';

	/** Types de contenu et champs personnalisés (module Champs personnalisés). */
	public const OPTION_CHAMPS = 'fluffless_champs';

	/** Version du schéma du journal d'activité (module Sécurité de base). */
	public const OPTION_JOURNAL_VERSION = 'fluffless_journal_version';

	/** Gabarits de pages enregistrés par l'agence (Éditeur, écran de départ). */
	public const OPTION_PAGE_TEMPLATES = 'fluffless_page_templates';

	/** Préréglages de style de bloc (module Préréglages de style). */
	public const OPTION_STYLES = 'fluffless_styles';

	/** Instant d'activation de la preuve de travail (module Formulaire), sans autochargement. */
	public const OPTION_ALTCHA_SINCE = 'fluffless_form_altcha_since';

	/** Préfixe des marques de preuves de travail déjà servies (module Formulaire), sans autochargement. */
	public const OPTION_ALTCHA_MARK = 'fluffless_altcha_used_';

	/**
	 * Options des modules sans constante ici (P5-03) : adresse et identifiant du flux des événements,
	 * vidéo de section, réglages d'avant le dernier import, polices du module Polices.
	 * Les modules ne sont pas chargés à la désinstallation : noms écrits en toutes lettres.
	 */
	public const OPTIONS_MODULES = array( 'fluffless_events_slug', 'fluffless_events_uid', 'fluffless_suite_video', 'fluffless_transfer_previous', 'fluffless_fonts', 'fluffless_utm_presets' );

	/** Type des événements (Events\Event::TYPE). */
	public const POST_TYPE_EVENT = 'fluffless_event';

	/**
	 * Métadonnées d'article de suivi (date d'avant un report, page convertie et son rapport). La copie
	 * d'origine d'une page migrée (_fluffless_migration_backup) reste : ce sont les données du constructeur.
	 */
	public const META_TRACKING = array( '_fluffless_event_previous_start', '_fluffless_migrated', '_fluffless_migration_report' );

	/** Métadonnée d'utilisateur : avis de mode réduit fermé (Loader). */
	public const USER_META_UNMET = 'fluffless_unmet_dismissed';

	/** Inscriptions à l'infolettre non transmises, en attente du courriel au site (une heure au plus). */
	public const OPTION_NEWSLETTER_PENDING = 'fluffless_newsletter_pending';

	/** Envoi différé de ce courriel (module Infolettre). */
	public const CRON_NEWSLETTER_DIGEST = 'fluffless_newsletter_digest';

	/** Purge quotidienne des réponses de formulaire échues (module Formulaire). */
	public const CRON_FORM_PURGE = 'fluffless_form_purge';

	/** Table du journal d'activité, sans le préfixe de wpdb (module Sécurité de base). */
	public const TABLE_JOURNAL = 'fluffless_journal';

	/** Réponses de formulaire conservées (module Formulaire). */
	public const POST_TYPE_RESPONSE = 'fluffless_response';

	/** Mode Studio du module Forme, par personne. */
	public const USER_META_STUDIO = 'fluffless_studio';

	/** Mode de l'éditeur de pages (simple ou complet), par personne. */
	public const USER_META_EDITOR_MODE = 'fluffless_editor_mode';

	/** Préfixe de tous les transitoires de l'extension, y compris ceux aux noms construits. */
	public const PREFIX = 'fluffless_';

	/** Description par contenu du module SEO. */
	public const META_SEO_DESCRIPTION = '_fluffless_description';

	/** Dernière mesure d'empreinte d'un contenu (module Empreinte). */
	public const META_FOOTPRINT = '_fluffless_footprint';

	/** Rôle du module Réviseur, retiré à la désinstallation (un compte qui n'avait que ce rôle repasse à Abonné, voir demote_reviewers()). */
	public const ROLE_REVIEWER = 'fluffless_reviewer';

	/** Pages confiées au rôle Réviseur (module Réviseur). */
	public const META_REVIEWERS = '_fluffless_reviewers';

	private const TRANSIENTS = array( self::TRANSIENT_HTTP_REFUSALS, self::TRANSIENT_UPLOAD_NOTICES, self::TRANSIENT_STATS_SCRIPT, self::TRANSIENT_AUDIT_REPORT, self::TRANSIENT_HOST_STATUS, self::TRANSIENT_STATS_FAILURE, self::TRANSIENT_UNUSED_MEDIA, self::TRANSIENT_MINIFY, self::TRANSIENT_UPDATES, self::TRANSIENT_LICENCE, self::TRANSIENT_API_SILENT, self::TRANSIENT_REPORTS, self::TRANSIENT_MAIL_FAILURES, self::TRANSIENT_MAIL_TEST, self::TRANSIENT_CARBON, self::TRANSIENT_WELCOME, self::TRANSIENT_AUDIT_IMAGES, self::TRANSIENT_NEWSLETTER_FAILURES, self::TRANSIENT_NEWSLETTER_TEST, self::TRANSIENT_NEWSLETTER_LOCK );

	/** Borne du parcours multisite à la désinstallation. */
	private const MAX_SITES = 10000;

	/**
	 * Écrit les valeurs par défaut si l'option n'existe pas encore (autoload), puis demande
	 * la redirection vers l'accueil à la prochaine requête d'administration (C26).
	 *
	 * @param bool|null $network_wide Activation pour tout un réseau : pas de redirection, l'écran n'existe pas au réseau.
	 *                                WP-CLI passe null pour une activation simple : typé bool seul, l'activation planterait.
	 */
	public static function activate( ?bool $network_wide = false ): void {
		// Défaut explicite : sans lui, get_option() rendrait le défaut de register_setting().
		if ( false === get_option( Options::NAME, false ) ) {
			add_option( Options::NAME, Options::defaults(), '', true );
		}
		if ( true !== $network_wide ) {
			set_transient( self::TRANSIENT_WELCOME, get_current_user_id(), MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Adresse de l'accueil si l'extension vient d'être activée par une personne qui peut l'ouvrir, sinon null.
	 * Consomme le drapeau : une seule redirection, jamais lors d'une activation groupée ni en AJAX.
	 * Le drapeau porte l'ID de la personne qui a activé : une autre requête d'admin ne le consomme pas.
	 *
	 * @param array<string, mixed> $query Paramètres de la requête (activate-multi marque l'activation groupée).
	 */
	public static function welcome_url( array $query = array() ): ?string {
		$owner = get_transient( self::TRANSIENT_WELCOME );
		if ( false === $owner || get_current_user_id() !== (int) $owner ) {
			return null;
		}
		if ( wp_doing_ajax() || wp_doing_cron() || isset( $query['activate-multi'] ) || is_network_admin() ) {
			return null;
		}
		delete_transient( self::TRANSIENT_WELCOME );
		if ( ! current_user_can( \Fluffless\Suite\Admin\SettingsPage::CAPABILITY ) ) {
			return null;
		}
		return \Fluffless\Suite\Admin\Menu::url();
	}

	/** Redirige vers l'accueil juste après l'activation (admin_init). */
	public static function welcome_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture seule d'un marqueur d'activation groupée.
		$url = self::welcome_url( (array) wp_unslash( $_GET ) );
		if ( null !== $url && wp_safe_redirect( $url ) ) {
			exit;
		}
	}

	/** Retire les données temporaires ; les réglages restent. */
	public static function deactivate(): void {
		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
		self::delete_prefixed_transients();
		// Adresses en attente du courriel d'échecs : jamais gardées sans le module pour les envoyer.
		delete_option( self::OPTION_NEWSLETTER_PENDING );
		wp_clear_scheduled_hook( self::CRON_NEWSLETTER_DIGEST );
		wp_clear_scheduled_hook( self::CRON_FORM_PURGE );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- marques temporaires de la preuve de travail, retirées d'un coup.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::OPTION_ALTCHA_MARK ) . '%' ) );
	}

	/** Retire la table du journal d'activité. */
	private static function drop_journal(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_JOURNAL;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table propre à l'extension, nom préfixé par wpdb.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Transitoires aux noms construits (limite de débit par adresse, etc.) : tout ce qui porte
	 * le préfixe fluffless_, en une requête. Avec un cache d'objets persistant, les transitoires
	 * vivent dans ce cache : le groupe des options y est vidé si le cache le permet.
	 */
	public static function delete_prefixed_transients(): int {
		global $wpdb;
		// Aucune API ne supprime par préfixe ; requête préparée, noms échappés pour LIKE.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%'
			)
		);
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'options' );
			wp_cache_flush_group( 'transient' );
		} else {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Retire tout ce que l'extension a créé, sur chaque site d'un réseau.
	 *
	 * Restent en place, parce qu'ils appartiennent à l'utilisatrice et non à l'extension :
	 * - les médias en quarantaine (statut fluffless_quarantine), leurs fichiers dans
	 *   uploads/fluffless-quarantaine/ et la métadonnée _fluffless_quarantine qui permet de les restaurer ;
	 * - les images converties et la métadonnée _fluffless_webp (anciens fichiers, correspondance des adresses) ;
	 * - les crédits Pexels (_fluffless_pexels) des photos importées ;
	 * - les polices installées par le module Polices (wp_font_family, wp_font_face et leurs fichiers) ;
	 * - la copie d'origine des pages converties par la Migration (_fluffless_migration_backup).
	 * Rien de cela n'est supprimé sans un clic dans l'écran qui s'en occupe.
	 */
	public static function uninstall(): void {
		if ( self::constant_keeps_data() ) {
			return;
		}
		if ( ! is_multisite() ) {
			if ( self::keeps_data() ) {
				return;
			}
			self::delete_user_meta();
			self::delete_site_data();
			return;
		}
		$site_ids  = get_sites(
			array(
				'fields' => 'ids',
				'number' => self::MAX_SITES,
			)
		);
		$any_keeps = false;
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			$any_keeps = self::keeps_data() || $any_keeps;
			self::delete_site_data();
			restore_current_blog();
		}
		// Les métadonnées d'utilisateur sont communes au réseau : elles restent si un seul site garde ses données.
		if ( ! $any_keeps ) {
			self::delete_user_meta();
		}
	}

	/** Métadonnées d'utilisateur de la Suite, lues par l'option « garder » avant tout effacement. */
	private static function delete_user_meta(): void {
		delete_metadata( 'user', 0, self::USER_META_STUDIO, '', true );
		delete_metadata( 'user', 0, self::USER_META_EDITOR_MODE, '', true );
		delete_metadata( 'user', 0, self::USER_META_UNMET, '', true );
	}

	/** La constante FLUFFLESS_KEEP_DATA (wp-config.php) est-elle vraie ? Garde tout, pour tout le réseau. */
	private static function constant_keeps_data(): bool {
		return filter_var( defined( 'FLUFFLESS_KEEP_DATA' ) ? constant( 'FLUFFLESS_KEEP_DATA' ) : false, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * La désinstallation doit-elle laisser les données du site courant : constante, ou case « Garder mes
	 * données » du module Formulaire. Lit l'option brute : les modules ne sont pas chargés à la désinstallation.
	 */
	public static function keeps_data(): bool {
		if ( self::constant_keeps_data() ) {
			return true;
		}
		$stored = get_option( Options::NAME );
		$form   = is_array( $stored ) && is_array( $stored['form'] ?? null ) ? $stored['form'] : array();
		return true === ( $form['keep_data'] ?? false );
	}

	/** Données d'un site : les options, les métadonnées et les transitoires. */
	private static function delete_site_data(): void {
		if ( self::keeps_data() ) {
			return;
		}
		// Les sections des autres extensions (Fluffless Consent) survivent à la désinstallation de Suite.
		$stored  = get_option( Options::NAME );
		$foreign = is_array( $stored ) ? array_intersect_key( $stored, array_flip( Options::FOREIGN ) ) : array();
		if ( array() === $foreign ) {
			delete_option( Options::NAME );
		} else {
			// Sans ce retrait, l'assainissement de Suite remettrait toutes ses sections par défaut.
			remove_all_filters( 'sanitize_option_' . Options::NAME );
			update_option( Options::NAME, $foreign );
		}
		delete_option( self::OPTION_SHAPE );
		delete_option( self::OPTION_PALETTE );
		delete_option( self::OPTION_WEBP_REPORT );
		delete_option( self::OPTION_LICENCE );
		delete_option( self::OPTION_FORMS );
		delete_option( self::OPTION_CONDITIONS );
		delete_option( self::OPTION_FICHES );
		delete_option( self::OPTION_CHAMPS );
		delete_option( self::OPTION_JOURNAL_VERSION );
		delete_option( self::OPTION_PAGE_TEMPLATES );
		delete_option( self::OPTION_STYLES );
		delete_option( self::OPTION_ALTCHA_SINCE );
		foreach ( self::OPTIONS_MODULES as $option ) {
			delete_option( $option );
		}
		self::drop_journal();
		self::delete_posts_of_type( self::POST_TYPE_RESPONSE );
		self::delete_posts_of_type( self::POST_TYPE_EVENT );
		foreach ( self::META_TRACKING as $meta ) {
			delete_post_meta_by_key( $meta );
		}
		// La description est partagée avec Fluffless SEO : on la garde si l'extension est installée.
		if ( ! file_exists( WP_PLUGIN_DIR . '/fluffless-seo/fluffless-seo.php' ) ) {
			delete_post_meta_by_key( self::META_SEO_DESCRIPTION );
		}
		delete_post_meta_by_key( self::META_FOOTPRINT );
		self::demote_reviewers();
		remove_role( self::ROLE_REVIEWER );
		delete_post_meta_by_key( self::META_REVIEWERS );
		self::deactivate();
	}

	/** Un compte qui n'avait que le rôle Réviseur repasse à Abonné : sans cela, il resterait sans aucun rôle (C-S05-02). */
	private static function demote_reviewers(): void {
		$reviewers = get_users(
			array(
				'role'   => self::ROLE_REVIEWER,
				'fields' => 'ID',
			)
		);
		foreach ( $reviewers as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$user->remove_role( self::ROLE_REVIEWER );
			if ( array() === $user->roles ) {
				$user->add_role( 'subscriber' );
			}
		}
	}

	/**
	 * Efface tous les contenus d'un type, avec leurs métadonnées, sans passer par la corbeille.
	 *
	 * @param string $type Type de contenu.
	 */
	private static function delete_posts_of_type( string $type ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- désinstallation, rien à mettre en cache.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $type ) );
		foreach ( $ids as $id ) {
			$wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $id ), array( '%d' ) );
			$wpdb->delete( $wpdb->posts, array( 'ID' => (int) $id ), array( '%d' ) );
		}
		// phpcs:enable
	}
}
