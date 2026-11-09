<?php
/**
 * Module Ménage : liste ce qui encombre le site et ne supprime jamais rien seul.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Règles : chaque action part d'un clic (nonce + capacité), toute suppression de contenu
 * passe par la corbeille, et rien ne se fait si la corbeille du site est désactivée.
 * Les révisions n'ont pas de corbeille : elles sont listées, jamais supprimées par ce module.
 *
 * @phpstan-type Report array{revisions: list<array{id: int, title: string, count: int}>, plugins: list<string>, themes: list<string>, drafts: list<array{id: int, title: string, modified: string}>, media: list<array{id: int, title: string}>|null, transients: int}
 */
final class Housekeeping implements Module {

	public const PAGE   = 'fluffless-housekeeping';
	public const ACTION = 'fluffless_housekeeping';

	/** Bornes : lignes par liste, médias examinés par recherche (une requête combinée par lot de MEDIA_BATCH). */
	public const LIST_LIMIT       = 50;
	public const MEDIA_SCAN_LIMIT = 100;
	public const MEDIA_BATCH      = 25;
	/** Liste des médias non utilisés gardée 15 minutes, oubliée dès qu'une action met un contenu à la corbeille. */
	public const MEDIA_CACHE_TTL   = 15 * MINUTE_IN_SECONDS;
	public const DRAFT_AGE         = '1 year ago';
	private const SCAN_MEDIA_PARAM = 'scan-media';
	/** Crochet qui reçoit la liste des médias non utilisés (écouté par Media\Quarantine). */
	public const UNUSED_MEDIA_HOOK = 'fluffless_housekeeping_unused_media';

	/** {@inheritDoc} */
	public static function id(): string {
		return 'housekeeping';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Housekeeping', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Lists what clutters your site (extra revisions, inactive plugins and themes, old drafts, unused media, expired temporary data). It never deletes anything on its own: you decide, and content goes to the trash.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array(
			'revisions_keep' => 10,
			'cap_revisions'  => false,
		);
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
		return array(
			'revisions_keep' => array(
				'type'  => 'int',
				'label' => __( 'Revisions to keep per content', 'fluffless-suite' ),
				'help'  => __( 'Housekeeping lists the content that has more.', 'fluffless-suite' ),
				'min'   => 1,
				'max'   => 100,
			),
			'cap_revisions'  => array(
				'type'  => 'bool',
				'label' => __( 'Apply this limit when content is saved', 'fluffless-suite' ),
				'help'  => __( 'Off by default. When on, WordPress permanently deletes the oldest revisions of a content each time someone saves it, beyond the limit above.', 'fluffless-suite' ),
			),
		);
	}

	/** {@inheritDoc} */
	public function register(): void {
		if ( Options::get_bool( self::id(), 'cap_revisions' ) ) {
			add_filter( 'wp_revisions_to_keep', array( self::class, 'revisions_to_keep' ) );
		}
		if ( is_admin() ) {
			add_action( 'admin_menu', array( self::class, 'add_page' ) );
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_post' ) );
		}
	}

	/**
	 * Plafond de révisions, sans jamais relever un plafond déjà plus bas (0 = révisions coupées).
	 *
	 * @param mixed $num Plafond de WordPress (-1 = illimité).
	 */
	public static function revisions_to_keep( $num ): int {
		$keep = Options::get_int( self::id(), 'revisions_keep' );
		$num  = (int) $num;
		return $num < 0 ? $keep : min( $num, $keep );
	}

	/** Ajoute Fluffless → Ménage. */
	public static function add_page(): void {
		Menu::add_tab( 'settings', self::PAGE, static fn(): string => __( 'Housekeeping', 'fluffless-suite' ), array( self::class, 'render' ), 30 );
	}

	/**
	 * Inventaire en lecture seule.
	 *
	 * @param bool $scan_media Chercher aussi les médias non utilisés (plus coûteux, sur demande).
	 * @return Report
	 */
	public static function report( bool $scan_media ): array {
		return array(
			'revisions'  => self::revisions(),
			'plugins'    => self::inactive_plugins(),
			'themes'     => self::inactive_themes(),
			'drafts'     => self::old_drafts(),
			'media'      => $scan_media ? self::unused_media() : null,
			'transients' => self::expired_transients(),
		);
	}

	/**
	 * Contenus qui ont plus de révisions que le plafond, les plus chargés d'abord.
	 *
	 * @return list<array{id: int, title: string, count: int}>
	 */
	public static function revisions(): array {
		global $wpdb;
		// Agrégat impossible avec WP_Query ; lecture seule, bornée, calculée à l'affichage de l'écran seulement.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_parent AS id, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_name NOT LIKE %s GROUP BY post_parent HAVING total > %d ORDER BY total DESC LIMIT %d",
				'%' . $wpdb->esc_like( 'autosave' ) . '%',
				Options::get_int( self::id(), 'revisions_keep' ),
				self::LIST_LIMIT
			),
			ARRAY_A
		);
		$list = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$list[] = array(
				'id'    => (int) $row['id'],
				'title' => get_the_title( (int) $row['id'] ),
				'count' => (int) $row['total'],
			);
		}
		return $list;
	}

	/**
	 * Extensions installées mais inactives (ni sur ce site ni sur le réseau).
	 *
	 * @return list<string>
	 */
	public static function inactive_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$names = array();
		foreach ( get_plugins() as $file => $plugin ) {
			if ( ! is_plugin_active( $file ) ) {
				$names[] = (string) $plugin['Name'];
			}
		}
		return array_slice( $names, 0, self::LIST_LIMIT );
	}

	/**
	 * Thèmes installés qui ne sont ni le thème actif ni son parent.
	 *
	 * @return list<string>
	 */
	public static function inactive_themes(): array {
		$used  = array( get_stylesheet(), get_template() );
		$names = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( ! in_array( (string) $slug, $used, true ) ) {
				$names[] = (string) $theme->get( 'Name' );
			}
		}
		return array_slice( $names, 0, self::LIST_LIMIT );
	}

	/**
	 * Brouillons non modifiés depuis plus d'un an, les plus anciens d'abord.
	 *
	 * @return list<array{id: int, title: string, modified: string}>
	 */
	public static function old_drafts(): array {
		$posts = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'draft',
				'numberposts'      => self::LIST_LIMIT,
				'orderby'          => 'modified',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// post_modified et non post_modified_gmt : WordPress laisse la date GMT des brouillons à zéro.
				'date_query'       => array(
					array(
						'column' => 'post_modified',
						'before' => self::DRAFT_AGE,
					),
				),
			)
		);
		$list  = array();
		foreach ( $posts as $post ) {
			$list[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'modified' => (string) get_the_modified_date( '', $post ),
			);
		}
		return $list;
	}

	/**
	 * Médias non rattachés et introuvables dans les contenus, métadonnées et options.
	 * Détection prudente : dans le doute (nom de fichier trouvé quelque part), le média est gardé.
	 *
	 * @return list<array{id: int, title: string}>
	 */
	public static function unused_media(): array {
		$cached = get_transient( Lifecycle::TRANSIENT_UNUSED_MEDIA );
		if ( is_array( $cached ) ) {
			return self::media_rows( array_values( array_filter( $cached, 'is_int' ) ) );
		}
		$ids = get_posts(
			array(
				'post_type'     => 'attachment',
				'post_status'   => 'inherit',
				'post_parent'   => 0,
				'numberposts'   => self::MEDIA_SCAN_LIMIT,
				'orderby'       => 'date',
				'order'         => 'ASC',
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		$ids = array_map( 'intval', $ids );
		// Noms de fichiers de tout le lot en une requête, au lieu d'une par média.
		update_meta_cache( 'post', $ids );
		$unused = array();
		foreach ( array_chunk( $ids, self::MEDIA_BATCH ) as $batch ) {
			$unused = array_merge( $unused, array_diff( $batch, self::referenced_among( $batch ) ) );
			if ( count( $unused ) >= self::LIST_LIMIT ) {
				break;
			}
		}
		$unused = array_slice( $unused, 0, self::LIST_LIMIT );
		set_transient( Lifecycle::TRANSIENT_UNUSED_MEDIA, $unused, self::MEDIA_CACHE_TTL );
		return self::media_rows( $unused );
	}

	/**
	 * Lignes affichées : identifiant et titre (titres chargés en une requête).
	 *
	 * @param int[] $ids Médias.
	 * @return list<array{id: int, title: string}>
	 */
	private static function media_rows( array $ids ): array {
		_prime_post_caches( $ids, false, false );
		$rows = array();
		foreach ( $ids as $id ) {
			$rows[] = array(
				'id'    => $id,
				'title' => get_the_title( $id ),
			);
		}
		return $rows;
	}

	/** Oublie la liste des médias non utilisés : elle sera recalculée au prochain affichage. */
	public static function forget_media_scan(): void {
		delete_transient( Lifecycle::TRANSIENT_UNUSED_MEDIA );
	}

	/**
	 * Le média est-il cité quelque part ? Voir referenced_among().
	 *
	 * @param int $id Média.
	 */
	public static function is_media_referenced( int $id ): bool {
		return in_array( $id, self::referenced_among( array( $id ) ), true );
	}

	/**
	 * Médias du lot cités quelque part : logo, icône, image mise en avant, contenu
	 * (classe wp-image-ID, attribut "id":ID, nom de fichier), métadonnées d'autres contenus
	 * (constructeurs de pages), options (widgets, réglages du thème). Une seule requête par lot :
	 * une colonne par média, chacune vraie dès qu'une des recherches trouve le média.
	 * Détection prudente : sans nom de fichier exploitable, le média est jugé cité.
	 *
	 * @param int[] $ids Médias (au plus MEDIA_BATCH).
	 * @return list<int>
	 */
	public static function referenced_among( array $ids ): array {
		global $wpdb;
		$special    = array( (int) get_option( 'site_icon' ), (int) get_option( 'site_logo' ), (int) get_theme_mod( 'custom_logo' ) );
		$referenced = array();
		$columns    = array();
		$args       = array();
		foreach ( array_slice( $ids, 0, self::MEDIA_BATCH ) as $id ) {
			$base = pathinfo( (string) get_post_meta( $id, '_wp_attached_file', true ), PATHINFO_FILENAME );
			if ( in_array( $id, $special, true ) || strlen( $base ) < 3 ) {
				$referenced[] = $id;
				continue;
			}
			$like      = '%' . $wpdb->esc_like( $base ) . '%';
			$columns[] = "(EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s)"
				. " OR EXISTS (SELECT 1 FROM {$wpdb->posts} WHERE ID <> %d AND post_type NOT IN ('revision', 'attachment') AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s))"
				. " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_value LIKE %s)"
				. " OR EXISTS (SELECT 1 FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_value LIKE %s)) AS m{$id}";
			array_push( $args, (string) $id, $id, '%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%', '%' . $wpdb->esc_like( '"id":' . $id ) . '%', $like, $id, $like, $wpdb->esc_like( '_transient' ) . '%', $like );
		}
		if ( array() === $columns ) {
			return $referenced;
		}
		// Lecture seule, bornée par MEDIA_SCAN_LIMIT, lancée sur demande et mise en cache.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . implode( ', ', $columns ), $args ), ARRAY_A );
		// phpcs:enable
		foreach ( is_array( $row ) ? $row : array() as $column => $found ) {
			if ( '1' === (string) $found ) {
				$referenced[] = (int) substr( (string) $column, 1 );
			}
		}
		return $referenced;
	}

	/** Transitoires expirés encore en base (site et réseau). */
	public static function expired_transients(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compte en lecture seule, aucune API ne l'offre.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				time()
			)
		);
		return (int) $count;
	}

	/** La corbeille du site est-elle active ? Sinon, wp_trash_post() supprimerait pour de bon. */
	public static function trash_enabled(): bool {
		return defined( 'EMPTY_TRASH_DAYS' ) && (int) constant( 'EMPTY_TRASH_DAYS' ) > 0;
	}

	/**
	 * Met un brouillon ancien à la corbeille, après avoir revérifié qu'il fait toujours partie
	 * de la liste. Jamais de suppression définitive, jamais de média : ceux-là passent par la
	 * quarantaine du module Médias, qui garde les fichiers et se restaure en un clic.
	 *
	 * @param int $post_id Contenu.
	 */
	public static function trash( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( null === $post || ! self::trash_enabled() || ! current_user_can( 'delete_post', $post_id ) ) {
			return false;
		}
		$modified  = get_post_timestamp( $post, 'modified' );
		$old_draft = 'draft' === $post->post_status && false !== $modified && $modified < (int) strtotime( self::DRAFT_AGE );
		if ( ! $old_draft ) {
			return false;
		}
		$trashed = wp_trash_post( $post_id ) instanceof \WP_Post;
		if ( $trashed ) {
			self::forget_media_scan();
		}
		return $trashed;
	}

	/**
	 * Purge les transitoires expirés (fonction du cœur) et renvoie le nombre retiré.
	 */
	public static function purge_transients(): int {
		$before = self::expired_transients();
		delete_expired_transients( true );
		return max( 0, $before - self::expired_transients() );
	}

	/**
	 * Nom d'action du nonce, propre à la tâche et au contenu visé.
	 *
	 * @param string $task    trash ou purge.
	 * @param int    $post_id Contenu (0 pour purge).
	 */
	public static function nonce_action( string $task, int $post_id ): string {
		return self::ACTION . '_' . $task . '_' . $post_id;
	}

	/**
	 * Traite une demande du formulaire. Séparée de la redirection pour être testable.
	 *
	 * @param array<mixed> $request Données reçues ($_POST).
	 * @return 'trashed'|'purged'|'refused'|'denied' Code de l'avis à afficher.
	 */
	public static function handle_request( array $request ): string {
		$task    = is_string( $request['task'] ?? null ) ? sanitize_key( $request['task'] ) : '';
		$post_id = absint( is_scalar( $request['post_id'] ?? null ) ? $request['post_id'] : 0 );
		$nonce   = is_string( $request['_wpnonce'] ?? null ) ? sanitize_text_field( $request['_wpnonce'] ) : '';
		if ( ! current_user_can( SettingsPage::CAPABILITY ) || ! wp_verify_nonce( $nonce, self::nonce_action( $task, $post_id ) ) ) {
			return 'denied';
		}
		if ( 'trash' === $task ) {
			return self::trash( $post_id ) ? 'trashed' : 'refused';
		}
		if ( 'purge' === $task ) {
			self::purge_transients();
			return 'purged';
		}
		return 'refused';
	}

	/** Formulaire posté vers admin-post.php : traite, puis revient à l'écran avec un avis. */
	public static function handle_post(): void {
		// Le nonce est vérifié dans handle_request(), propre à chaque tâche et contenu.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$code = self::handle_request( wp_unslash( $_POST ) );
		wp_safe_redirect( add_query_arg( 'fluffless-done', $code, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Bouton d'action : un petit formulaire par ligne, avec son propre nonce.
	 *
	 * @param string $task    trash ou purge.
	 * @param int    $post_id Contenu (0 pour purge).
	 * @param string $label   Texte du bouton.
	 */
	private static function action_button( string $task, int $post_id, string $label ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="task" value="' . esc_attr( $task ) . '">'
			. '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '">'
			. wp_nonce_field( self::nonce_action( $task, $post_id ), '_wpnonce', false, false )
			. '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
	}

	/** Écran Fluffless → Ménage. */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		// Paramètres d'affichage en lecture seule : aucun effet de bord, pas de nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$scan = isset( $_GET[ self::SCAN_MEDIA_PARAM ] );
		$done = isset( $_GET['fluffless-done'] ) ? sanitize_key( wp_unslash( $_GET['fluffless-done'] ) ) : '';
		// phpcs:enable
		$report = self::report( $scan );

		echo '<div class="wrap"><h1>' . esc_html__( 'Housekeeping', 'fluffless-suite' ) . '</h1>';
		echo '<p>' . esc_html__( 'Nothing here is deleted without your click. Content goes to the trash, where you can restore it.', 'fluffless-suite' ) . '</p>';
		$notices = array(
			'trashed' => array( 'good', __( 'Moved to the trash. You can restore it from the trash.', 'fluffless-suite' ) ),
			'purged'  => array( 'good', __( 'Expired temporary data removed.', 'fluffless-suite' ) ),
			'refused' => array( 'warn', __( 'Nothing was changed: this item no longer matches the list, or the trash is turned off on this site.', 'fluffless-suite' ) ),
			'denied'  => array( 'bad', __( 'Nothing was changed: the request expired or you are not allowed to do this. Reload the page and try again.', 'fluffless-suite' ) ),
		);
		if ( isset( $notices[ $done ] ) ) {
			echo Screen::note( $notices[ $done ][0], $notices[ $done ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
		if ( ! self::trash_enabled() ) {
			echo Screen::note( 'warn', __( 'The trash is turned off on this site (EMPTY_TRASH_DAYS): Housekeeping only lists, it moves nothing.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}

		self::render_revisions( $report['revisions'] );
		self::render_names(
			__( 'Inactive plugins', 'fluffless-suite' ),
			$report['plugins'],
			__( 'An inactive plugin still needs updates. Delete the ones you no longer need from the Plugins screen.', 'fluffless-suite' ),
			admin_url( 'plugins.php?plugin_status=inactive' ),
			__( 'Open inactive plugins', 'fluffless-suite' )
		);
		self::render_names(
			__( 'Inactive themes', 'fluffless-suite' ),
			$report['themes'],
			__( 'Keeping one default theme as a fallback is a good habit. Delete the others from the Themes screen.', 'fluffless-suite' ),
			admin_url( 'themes.php' ),
			__( 'Open themes', 'fluffless-suite' )
		);
		self::render_drafts( $report['drafts'] );
		self::render_media( $report['media'] );

		echo '<h2>' . esc_html__( 'Expired temporary data', 'fluffless-suite' ) . '</h2><p>';
		/* translators: %s: number of expired transients */
		echo esc_html( sprintf( _n( '%s expired transient is still stored.', '%s expired transients are still stored.', $report['transients'], 'fluffless-suite' ), number_format_i18n( $report['transients'] ) ) );
		echo '</p>';
		if ( $report['transients'] > 0 ) {
			echo self::action_button( 'purge', 0, __( 'Remove expired temporary data', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans action_button().
		}
		echo '</div>';
	}

	/**
	 * Section révisions : informative seulement (pas de corbeille pour les révisions).
	 *
	 * @param list<array{id: int, title: string, count: int}> $rows Contenus.
	 */
	private static function render_revisions( array $rows ): void {
		$keep = Options::get_int( self::id(), 'revisions_keep' );
		echo '<h2>' . esc_html__( 'Extra revisions', 'fluffless-suite' ) . '</h2>';
		/* translators: %s: number of revisions */
		echo '<p>' . esc_html( sprintf( _n( 'Content with more than %s revision.', 'Content with more than %s revisions.', $keep, 'fluffless-suite' ), number_format_i18n( $keep ) ) ) . ' ';
		echo esc_html__( 'Revisions have no trash, so Housekeeping never deletes them. To limit them, turn on the revision limit in Fluffless → Modules.', 'fluffless-suite' ) . '</p>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Nothing to report.', 'fluffless-suite' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $rows as $row ) {
			printf(
				'<li><a href="%1$s">%2$s</a> : %3$s</li>',
				esc_url( (string) get_edit_post_link( $row['id'] ) ),
				esc_html( '' === $row['title'] ? '#' . $row['id'] : $row['title'] ),
				/* translators: %s: number of revisions */
				esc_html( sprintf( _n( '%s revision', '%s revisions', $row['count'], 'fluffless-suite' ), number_format_i18n( $row['count'] ) ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Liste de noms (extensions, thèmes) avec un lien vers l'écran de WordPress qui s'en occupe.
	 *
	 * @param string   $title Titre de section.
	 * @param string[] $names Noms.
	 * @param string   $help  Conseil.
	 * @param string   $url   Écran de WordPress.
	 * @param string   $link  Texte du lien.
	 * @phpstan-param list<string> $names
	 */
	private static function render_names( string $title, array $names, string $help, string $url, string $link ): void {
		echo '<h2>' . esc_html( $title ) . '</h2>';
		if ( array() === $names ) {
			echo '<p>' . esc_html__( 'Nothing to report.', 'fluffless-suite' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html( $help ) . '</p><ul>';
		foreach ( $names as $name ) {
			echo '<li>' . esc_html( $name ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( $url ) . '">' . esc_html( $link ) . '</a></p>';
	}

	/**
	 * Brouillons de plus d'un an, chacun avec son bouton Corbeille.
	 *
	 * @param list<array{id: int, title: string, modified: string}> $rows Brouillons.
	 */
	private static function render_drafts( array $rows ): void {
		echo '<h2>' . esc_html__( 'Drafts untouched for more than a year', 'fluffless-suite' ) . '</h2>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Nothing to report.', 'fluffless-suite' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $rows as $row ) {
			printf(
				'<li><a href="%1$s">%2$s</a> (%3$s) %4$s</li>',
				esc_url( (string) get_edit_post_link( $row['id'] ) ),
				esc_html( '' === $row['title'] ? '#' . $row['id'] : $row['title'] ),
				/* translators: %s: date */
				esc_html( sprintf( __( 'last edited %s', 'fluffless-suite' ), $row['modified'] ) ),
				self::trash_enabled() ? self::action_button( 'trash', $row['id'], __( 'Move to the trash', 'fluffless-suite' ) ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans action_button().
			);
		}
		echo '</ul>';
	}

	/**
	 * Médias non utilisés : recherche sur demande, résultats « à vérifier ».
	 *
	 * @param list<array{id: int, title: string}>|null $rows Médias, ou null si la recherche n'a pas été lancée.
	 */
	private static function render_media( ?array $rows ): void {
		echo '<h2>' . esc_html__( 'Media that seem unused', 'fluffless-suite' ) . '</h2>';
		if ( null === $rows ) {
			/* translators: %s: number of media files */
			echo '<p>' . esc_html( sprintf( __( 'The search looks at the %s oldest unattached media files and can take a few seconds.', 'fluffless-suite' ), number_format_i18n( self::MEDIA_SCAN_LIMIT ) ) ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&' . self::SCAN_MEDIA_PARAM . '=1' ) ) . '">' . esc_html__( 'Search for unused media', 'fluffless-suite' ) . '</a></p>';
			return;
		}
		echo '<p>' . esc_html__( 'To check: a file can be used in a way this search cannot see (a link in an email, another site, a plugin setting). Open each one before throwing it out.', 'fluffless-suite' ) . '</p>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Nothing to report.', 'fluffless-suite' ) . '</p>';
			return;
		}
		if ( has_action( self::UNUSED_MEDIA_HOOK ) ) {
			/**
			 * Liste des médias non utilisés, rendue par l'écran de quarantaine du module Médias
			 * (Quarantine::housekeeping_media) : avis par média et lien vers la quarantaine, qui porte
			 * toute action sur les fichiers. Ce module n'en ajoute aucune.
			 *
			 * @param list<array{id: int, title: string}> $rows Médias listés.
			 */
			do_action( 'fluffless_housekeeping_unused_media', $rows ); // Nom littéral : phpcs vérifie le préfixe ; même valeur que UNUSED_MEDIA_HOOK.
			return;
		}
		echo '<ul>';
		foreach ( $rows as $row ) {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( (string) get_edit_post_link( $row['id'] ) ),
				esc_html( '' === $row['title'] ? '#' . $row['id'] : $row['title'] )
			);
		}
		echo '</ul>';
		echo Screen::note( 'idle', __( 'Housekeeping never deletes media. To move these files to a quarantine you can restore from, turn on the media module in Fluffless → Modules.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
	}
}
