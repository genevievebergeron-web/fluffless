<?php
/**
 * Journal d'activité du module Sécurité de base : qui a fait quoi, et quand.
 *
 * Réduit exprès. Il garde ce qu'un site vitrine a besoin de retrouver après coup : connexions et
 * échecs, verrouillages, extensions et thème activés ou mis à jour, réglages de la Suite changés,
 * comptes créés, modifiés ou supprimés. Les modifications de contenu restent aux révisions de
 * WordPress. Simple History fait le journal complet ; on ne le refait pas.
 *
 * Données : une table à part, purgée après le délai réglé (90 jours par défaut) au fil des
 * écritures, sans tâche planifiée. L'adresse IP n'est gardée que pour les échecs de connexion et
 * les verrouillages, jamais pour le reste.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules\Security;

use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Security;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Table, enregistrement, purge et écran du journal.
 *
 * @phpstan-type Entry array{id: int, time: int, event: string, actor: string, subject: string, ip: string}
 */
final class Journal {

	/** Version du schéma, gardée dans une option pour ne créer la table qu'une fois. */
	public const SCHEMA = 1;

	/** Événements reconnus. */
	public const EVENTS = array(
		'login',
		'login_failed',
		'lockout',
		'plugin_activated',
		'plugin_deactivated',
		'plugin_updated',
		'plugin_installed',
		'theme_switched',
		'theme_updated',
		'theme_installed',
		'core_updated',
		'settings_changed',
		'user_created',
		'user_updated',
		'user_deleted',
		'role_changed',
		'page_converted',
		'page_reverted',
	);

	/** Lignes affichées à l'écran. */
	public const SCREEN_ROWS = 200;

	/** Bornes des colonnes. */
	public const MAX_ACTOR   = 60;
	public const MAX_SUBJECT = 200;

	/** Action du bouton qui vide le journal. */
	public const ACTION_CLEAR = 'fluffless_journal_clear';

	/** Transitoire qui espace les purges : une par jour. */
	public const TRANSIENT_PURGED = 'fluffless_journal_purged';

	/** Nom complet de la table. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Lifecycle::TABLE_JOURNAL;
	}

	/** Le journal est-il en service (module actif et réglage coché) ? */
	public static function enabled(): bool {
		return Options::is_enabled( Security::id() ) && Options::get_bool( Security::id(), 'journal' );
	}

	/** Crée la table si elle manque ou si son schéma a changé. Une lecture d'option, rien de plus, le reste du temps. */
	public static function install(): void {
		if ( self::SCHEMA === (int) get_option( Lifecycle::OPTION_JOURNAL_VERSION, 0 ) && self::table_exists() ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created datetime NOT NULL,
	event varchar(40) NOT NULL,
	actor varchar(60) NOT NULL DEFAULT '',
	subject varchar(200) NOT NULL DEFAULT '',
	ip varchar(45) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	KEY created (created),
	KEY event (event)
) {$collate};"
		);
		update_option( Lifecycle::OPTION_JOURNAL_VERSION, self::SCHEMA, false );
	}

	/** La table est-elle là ? */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- vérification de schéma.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/** Pose les crochets qui alimentent le journal. */
	public static function hook(): void {
		self::install();
		add_action( 'admin_init', array( self::class, 'add_privacy_text' ) );
		add_action( 'wp_login', array( self::class, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( self::class, 'on_login_failed' ) );
		add_action( 'activated_plugin', array( self::class, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( self::class, 'on_plugin_deactivated' ) );
		add_action( 'switch_theme', array( self::class, 'on_theme_switched' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'on_upgrade' ), 10, 2 );
		add_action( 'update_option_' . Options::NAME, array( self::class, 'on_settings_changed' ), 10, 2 );
		add_action( 'user_register', array( self::class, 'on_user_created' ) );
		add_action( 'profile_update', array( self::class, 'on_user_updated' ) );
		add_action( 'deleted_user', array( self::class, 'on_user_deleted' ), 10, 3 );
		add_action( 'set_user_role', array( self::class, 'on_role_changed' ), 10, 3 );
		if ( is_admin() ) {
			add_action( 'admin_post_' . self::ACTION_CLEAR, array( self::class, 'handle_clear' ) );
		}
	}

	/**
	 * Écrit une ligne, puis purge au plus une fois par jour.
	 *
	 * @param string $event   Un des EVENTS.
	 * @param string $subject Ce qui est touché : compte, extension, thème, sections changées.
	 * @param string $ip      Adresse IP, seulement pour les échecs de connexion et les verrouillages.
	 */
	public static function record( string $event, string $subject = '', string $ip = '' ): void {
		if ( ! self::enabled() || ! in_array( $event, self::EVENTS, true ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table propre au module.
		$wpdb->insert(
			self::table(),
			array(
				'created' => current_time( 'mysql', true ),
				'event'   => $event,
				'actor'   => mb_substr( self::actor(), 0, self::MAX_ACTOR ),
				'subject' => mb_substr( sanitize_text_field( $subject ), 0, self::MAX_SUBJECT ),
				'ip'      => false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '',
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		self::maybe_purge();
	}

	/** Qui agit : le compte connecté, WP-CLI, ou personne (visiteur, cron). */
	private static function actor(): string {
		$user = wp_get_current_user();
		if ( $user->ID > 0 ) {
			return (string) $user->user_login;
		}
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return 'WP-CLI';
		}
		return '';
	}

	/** Paragraphe proposé pour la politique de confidentialité. */
	public static function add_privacy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Fluffless Suite: activity log', 'fluffless-suite' ), wpautop( esc_html( self::privacy_text() ) ) );
		}
	}

	/** Texte de la mention : ce que garde le journal, et combien de temps. */
	public static function privacy_text(): string {
		return sprintf(
			/* translators: %d: number of days the activity log keeps its entries. */
			__( 'The activity log of this site records sign-ins to its dashboard. After a failed sign-in or a lockout, it keeps the username or email address that was tried and the IP address it came from. Entries are deleted after %d days.', 'fluffless-suite' ),
			Options::get_int( Security::id(), 'journal_days' )
		);
	}

	/** Purge une fois par jour au plus, selon le délai réglé. */
	private static function maybe_purge(): void {
		if ( false !== get_transient( self::TRANSIENT_PURGED ) ) {
			return;
		}
		set_transient( self::TRANSIENT_PURGED, 1, DAY_IN_SECONDS );
		self::purge( Options::get_int( Security::id(), 'journal_days' ) );
	}

	/**
	 * Retire les lignes plus vieilles que le délai.
	 *
	 * @param int $days Délai en jours ; 0 ou moins ne purge rien.
	 * @return int Lignes retirées.
	 */
	public static function purge( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table = self::table();
		$limit = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table préfixé par wpdb.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created < %s", $limit ) );
		return is_int( $deleted ) ? $deleted : 0;
	}

	/** Vide le journal. */
	public static function clear(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table préfixé par wpdb.
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/** Nombre de lignes. */
	public static function count(): int {
		if ( ! self::table_exists() ) {
			return 0;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table préfixé par wpdb.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Dernières lignes, de la plus récente à la plus ancienne.
	 *
	 * @param int $limit Lignes au plus (1 à 1000).
	 * @return list<Entry>
	 */
	public static function recent( int $limit = self::SCREEN_ROWS ): array {
		if ( ! self::table_exists() ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 1000, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table préfixé par wpdb.
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, created, event, actor, subject, ip FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		$entries = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$time      = strtotime( (string) ( $row['created'] ?? '' ) . ' UTC' );
			$entries[] = array(
				'id'      => (int) ( $row['id'] ?? 0 ),
				'time'    => false === $time ? 0 : $time,
				'event'   => (string) ( $row['event'] ?? '' ),
				'actor'   => (string) ( $row['actor'] ?? '' ),
				'subject' => (string) ( $row['subject'] ?? '' ),
				'ip'      => (string) ( $row['ip'] ?? '' ),
			);
		}
		return $entries;
	}

	/**
	 * Libellés des événements.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'login'              => __( 'Logged in', 'fluffless-suite' ),
			'login_failed'       => __( 'Failed login', 'fluffless-suite' ),
			'lockout'            => __( 'Login locked', 'fluffless-suite' ),
			'plugin_activated'   => __( 'Plugin activated', 'fluffless-suite' ),
			'plugin_deactivated' => __( 'Plugin deactivated', 'fluffless-suite' ),
			'plugin_updated'     => __( 'Plugin updated', 'fluffless-suite' ),
			'plugin_installed'   => __( 'Plugin installed', 'fluffless-suite' ),
			'theme_switched'     => __( 'Theme activated', 'fluffless-suite' ),
			'theme_updated'      => __( 'Theme updated', 'fluffless-suite' ),
			'theme_installed'    => __( 'Theme installed', 'fluffless-suite' ),
			'core_updated'       => __( 'WordPress updated', 'fluffless-suite' ),
			'settings_changed'   => __( 'Suite settings changed', 'fluffless-suite' ),
			'user_created'       => __( 'Account created', 'fluffless-suite' ),
			'user_updated'       => __( 'Account updated', 'fluffless-suite' ),
			'user_deleted'       => __( 'Account deleted', 'fluffless-suite' ),
			'role_changed'       => __( 'Role changed', 'fluffless-suite' ),
			'page_converted'     => __( 'Page converted from a page builder', 'fluffless-suite' ),
			'page_reverted'      => __( 'Page put back as before the conversion', 'fluffless-suite' ),
		);
	}

	/**
	 * Sur wp_login.
	 *
	 * @param string $user_login Identifiant.
	 * @param mixed  $user       WP_User.
	 */
	public static function on_login( $user_login, $user = null ): void {
		unset( $user );
		self::record( 'login', (string) $user_login );
	}

	/**
	 * Sur wp_login_failed : identifiant tenté et adresse IP.
	 *
	 * @param string $username Identifiant tenté.
	 */
	public static function on_login_failed( $username ): void {
		self::record( 'login_failed', (string) $username, Security::client_ip() );
	}

	/**
	 * Sur activated_plugin.
	 *
	 * @param string $plugin Fichier de l'extension.
	 */
	public static function on_plugin_activated( $plugin ): void {
		self::record( 'plugin_activated', self::plugin_name( (string) $plugin ) );
	}

	/**
	 * Sur deactivated_plugin.
	 *
	 * @param string $plugin Fichier de l'extension.
	 */
	public static function on_plugin_deactivated( $plugin ): void {
		self::record( 'plugin_deactivated', self::plugin_name( (string) $plugin ) );
	}

	/**
	 * Sur switch_theme.
	 *
	 * @param string $new_name Nom du thème activé.
	 */
	public static function on_theme_switched( $new_name ): void {
		self::record( 'theme_switched', (string) $new_name );
	}

	/**
	 * Sur upgrader_process_complete : mises à jour et installations d'extensions, de thèmes et du cœur.
	 *
	 * @param mixed $upgrader   WP_Upgrader.
	 * @param mixed $hook_extra type (plugin, theme, core, translation), action (update, install), plugins ou themes.
	 */
	public static function on_upgrade( $upgrader, $hook_extra ): void {
		unset( $upgrader );
		if ( ! is_array( $hook_extra ) ) {
			return;
		}
		$type   = (string) ( $hook_extra['type'] ?? '' );
		$action = 'install' === ( $hook_extra['action'] ?? '' ) ? 'installed' : 'updated';
		if ( 'core' === $type ) {
			self::record( 'core_updated', get_bloginfo( 'version' ) );
			return;
		}
		if ( 'plugin' !== $type && 'theme' !== $type ) {
			return;
		}
		$items = $hook_extra[ 'plugin' === $type ? 'plugins' : 'themes' ] ?? ( $hook_extra[ $type ] ?? array() );
		$items = is_array( $items ) ? $items : array( $items );
		$names = array();
		foreach ( $items as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$names[] = 'plugin' === $type ? self::plugin_name( $item ) : $item;
			}
		}
		self::record( $type . '_' . $action, implode( ', ', $names ) );
	}

	/**
	 * Sur update_option_fluffless_settings : les sections qui changent, modules cochés ou décochés compris.
	 *
	 * @param mixed $old   Ancienne valeur.
	 * @param mixed $fresh Nouvelle valeur.
	 */
	public static function on_settings_changed( $old, $fresh ): void {
		if ( ! is_array( $old ) || ! is_array( $fresh ) ) {
			return;
		}
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $fresh ) ) ) as $key ) {
			if ( ( $old[ $key ] ?? null ) === ( $fresh[ $key ] ?? null ) ) {
				continue;
			}
			if ( 'modules' === $key ) {
				$before = is_array( $old['modules'] ?? null ) ? $old['modules'] : array();
				$after  = is_array( $fresh['modules'] ?? null ) ? $fresh['modules'] : array();
				foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $module ) {
					if ( ( $before[ $module ] ?? false ) !== ( $after[ $module ] ?? false ) ) {
						$changed[] = $module . ( true === ( $after[ $module ] ?? false ) ? ' on' : ' off' );
					}
				}
				continue;
			}
			$changed[] = (string) $key;
		}
		if ( array() !== $changed ) {
			self::record( 'settings_changed', implode( ', ', $changed ) );
		}
	}

	/**
	 * Sur user_register.
	 *
	 * @param int $user_id Identifiant du compte.
	 */
	public static function on_user_created( $user_id ): void {
		self::record( 'user_created', self::user_login( (int) $user_id ) );
	}

	/**
	 * Sur profile_update.
	 *
	 * @param int $user_id Identifiant du compte.
	 */
	public static function on_user_updated( $user_id ): void {
		self::record( 'user_updated', self::user_login( (int) $user_id ) );
	}

	/**
	 * Sur deleted_user.
	 *
	 * @param int   $user_id  Identifiant du compte.
	 * @param mixed $reassign Compte qui reprend les contenus, ou null.
	 * @param mixed $user     WP_User supprimé.
	 */
	public static function on_user_deleted( $user_id, $reassign = null, $user = null ): void {
		unset( $reassign );
		$login = $user instanceof WP_User ? (string) $user->user_login : '#' . (int) $user_id;
		self::record( 'user_deleted', $login );
	}

	/**
	 * Sur set_user_role : ignoré à la création du compte (aucun ancien rôle), déjà journalisée.
	 *
	 * @param int    $user_id   Identifiant du compte.
	 * @param string $role      Nouveau rôle.
	 * @param mixed  $old_roles Anciens rôles.
	 */
	public static function on_role_changed( $user_id, $role, $old_roles = array() ): void {
		if ( ! is_array( $old_roles ) || array() === $old_roles ) {
			return;
		}
		self::record( 'role_changed', self::user_login( (int) $user_id ) . ': ' . implode( ', ', array_map( 'strval', $old_roles ) ) . ' > ' . (string) $role );
	}

	/**
	 * Identifiant d'un compte, ou son numéro s'il n'existe plus.
	 *
	 * @param int $user_id Identifiant.
	 */
	private static function user_login( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user instanceof WP_User ? (string) $user->user_login : '#' . $user_id;
	}

	/**
	 * Nom d'une extension d'après son fichier, sans lire tout le répertoire ; le fichier sinon.
	 *
	 * @param string $plugin Fichier relatif, ex. fluffless-suite/fluffless-suite.php.
	 */
	private static function plugin_name( string $plugin ): string {
		$path = WP_PLUGIN_DIR . '/' . $plugin;
		if ( '' === $plugin || ! is_file( $path ) ) {
			return $plugin;
		}
		$data = get_file_data( $path, array( 'Name' => 'Plugin Name' ) );
		$name = trim( (string) ( $data['Name'] ?? '' ) );
		return '' === $name ? $plugin : $name;
	}

	/** Bouton « Vider le journal » : capacité, nonce, retour à l'écran. */
	public static function handle_clear(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the activity log.', 'fluffless-suite' ), 403 );
		}
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'The activity log is cleared from its screen only.', 'fluffless-suite' ), 405 );
		}
		check_admin_referer( self::ACTION_CLEAR );
		$back = admin_url( 'admin.php?page=' . Security::PAGE );
		if ( ! isset( $_POST['confirm'] ) || '1' !== sanitize_text_field( wp_unslash( (string) $_POST['confirm'] ) ) ) {
			wp_safe_redirect( add_query_arg( 'cleared', 'unconfirmed', $back ) );
			exit;
		}
		self::clear();
		wp_safe_redirect( add_query_arg( 'cleared', '1', $back ) );
		exit;
	}

	/** Écran Journal d'activité. */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		$days = Options::get_int( Security::id(), 'journal_days' );
		echo '<div class="wrap">';
		Screen::header(
			__( 'Activity log', 'fluffless-suite' ),
			sprintf(
				/* translators: %d: number of days */
				_n( 'Logins and failures, plugins and theme activated or updated, Suite settings changed, accounts created or modified. Entries are removed after %d day. Content changes stay in WordPress revisions.', 'Logins and failures, plugins and theme activated or updated, Suite settings changed, accounts created or modified. Entries are removed after %d days. Content changes stay in WordPress revisions.', $days, 'fluffless-suite' ),
				$days
			)
		);
		if ( isset( $_GET['cleared'] ) && 'unconfirmed' === $_GET['cleared'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple message après redirection.
			echo Screen::note( 'warn', __( 'Nothing was cleared: tick the confirmation box next to the button.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		} elseif ( isset( $_GET['cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple message après redirection.
			echo Screen::note( 'good', __( 'The activity log is empty.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		if ( ! self::enabled() ) {
			echo Screen::note( 'warn', __( 'The log is off: turn on Basic security and its “Keep an activity log” setting.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		$entries = self::recent();
		$total   = self::count();
		$labels  = self::labels();
		$format  = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'Nothing recorded yet.', 'fluffless-suite' ) . '</p></div>';
			return;
		}
		printf(
			'<p class="fl-muted">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: entries shown, 2: entries kept */
					__( 'Last %1$s of %2$s entries.', 'fluffless-suite' ),
					number_format_i18n( count( $entries ) ),
					number_format_i18n( $total )
				)
			)
		);
		echo '<div class="fl-table-scroll"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Activity log', 'fluffless-suite' ) . '</caption><thead><tr><th scope="col">'
			. esc_html__( 'Date', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'Event', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'Who', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'Detail', 'fluffless-suite' ) . '</th><th scope="col">'
			. esc_html__( 'IP address', 'fluffless-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$date = wp_date( $format, $entry['time'] );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( false === $date ? '' : $date ),
				esc_html( $labels[ $entry['event'] ] ?? $entry['event'] ),
				esc_html( $entry['actor'] ),
				esc_html( $entry['subject'] ),
				esc_html( $entry['ip'] )
			);
		}
		echo '</tbody></table></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CLEAR ) . '">';
		wp_nonce_field( self::ACTION_CLEAR );
		printf(
			'<p><label><input type="checkbox" name="confirm" value="1"> %1$s</label></p><p><button type="submit" class="button button-link-delete">%2$s</button></p></form>',
			esc_html__( 'I understand that every entry of the log will be deleted for good.', 'fluffless-suite' ),
			esc_html__( 'Clear the log', 'fluffless-suite' )
		);
		echo '</div>';
	}
}
