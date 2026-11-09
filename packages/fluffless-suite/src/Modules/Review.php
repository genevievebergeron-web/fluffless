<?php
/**
 * Module Révision : vue d'ensemble des notes de l'éditeur.
 *
 * WordPress 7.1 garde les notes posées sur les blocs comme des commentaires de type « note ».
 * Le fil commence par une note de premier niveau, dont l'état porte la résolution : « approved »
 * pour réglée, « hold » pour ouverte. Le bloc la retrouve par metadata.noteId. L'éditeur ne montre
 * que les notes de la page ouverte : cet écran les liste toutes, page par page, avec leur état,
 * et mène au bloc visé. Il ne modifie rien.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Core\Module;

use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Écran Révision.
 */
final class Review implements Module {

	public const PAGE = 'fluffless-review';

	/**
	 * Droit synthétique de l'onglet et de l'écran : jamais un vrai droit stocké sur un rôle,
	 * seulement le OU de deux publics déjà résolus (voir grant_screen_capability()) : quiconque
	 * édite tout le contenu, ou un compte Réviseur sur les pages qui lui sont confiées.
	 */
	public const CAPABILITY = 'fluffless_review_screen';

	/** Paramètre d'adresse de l'éditeur qui désigne la note à montrer. */
	public const QUERY_NOTE = 'fl_note';

	/** Fils lus au plus par affichage. */
	public const MAX = 500;

	/** Longueur de l'extrait de la première note, en caractères. */
	public const EXCERPT = 140;

	/** Filtres offerts. */
	public const FILTERS = array( 'open', 'resolved', 'all' );

	/** Action admin-post.php qui enregistre les réviseurs assignés depuis cet écran. */
	public const ACTION_SET_REVIEWERS = 'fluffless_review_set_reviewers';

	/** Identifiant stable. */
	public static function id(): string {
		return 'review';
	}

	/** Nom affiché. */
	public static function label(): string {
		return __( 'Review', 'fluffless-suite' );
	}

	/** Ce que le module change. */
	public static function description(): string {
		return __( 'Lists every editor note on the site, page by page, open or resolved, with a link to the block it points to.', 'fluffless-suite' );
	}

	/** Actif sur une installation neuve : il ne fait que lire. */
	public static function enabled_by_default(): bool {
		return true;
	}

	/**
	 * Aucun réglage.
	 *
	 * @return array<string, bool|int|string|list<string>>
	 */
	public static function defaults(): array {
		return array();
	}

	/**
	 * Aucun réglage à assainir.
	 *
	 * @param array<mixed> $input Section brute.
	 * @return array<string, bool|int|string|list<string>>
	 */
	public static function sanitize( array $input ): array {
		unset( $input );
		return array();
	}

	/**
	 * Aucun champ dans Fluffless → Modules.
	 *
	 * @return array<string, array{type: 'bool', label: string}>
	 */
	public static function fields(): array {
		return array();
	}

	/** Crochets. */
	public function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_filter( 'user_has_cap', array( self::class, 'grant_screen_capability' ), 10, 4 );
		add_action( 'admin_post_' . self::ACTION_SET_REVIEWERS, array( self::class, 'handle_set_reviewers' ) );
	}

	/** Onglet Révision du groupe Contenu, avec le nombre de fils ouverts (scopé pour un compte Réviseur). */
	public static function add_page(): void {
		Menu::add_tab(
			'content',
			self::PAGE,
			static function (): string {
				$open = self::count_open( self::current_scope() );
				/* translators: %d: number of open notes. */
				return $open > 0 ? sprintf( __( 'Review (%d)', 'fluffless-suite' ), $open ) : __( 'Review', 'fluffless-suite' );
			},
			array( self::class, 'render' ),
			20,
			self::CAPABILITY
		);
	}

	/**
	 * Droit synthétique de l'onglet : n'accorde rien de nouveau, additionne deux capacités déjà
	 * résolues par WordPress. Jamais un vrai droit stocké ; ne s'active que si CAPABILITY est demandé.
	 *
	 * @param array<string, bool> $allcaps Droits déjà résolus pour ce compte.
	 * @param array<int, string>  $caps    Droits primitifs demandés pour cette vérification.
	 * @param array<int, mixed>   $args    Arguments de la vérification, non utilisés ici.
	 * @param \WP_User            $user    Compte concerné, non utilisé ici.
	 * @return array<string, bool>
	 */
	public static function grant_screen_capability( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		unset( $args, $user );
		if ( in_array( self::CAPABILITY, $caps, true ) && ( ! empty( $allcaps['edit_others_posts'] ) || ! empty( $allcaps[ Reviewer::CAP ] ) ) ) {
			$allcaps[ self::CAPABILITY ] = true;
		}
		return $allcaps;
	}

	/**
	 * Portée du compte courant sur l'écran Révision : null (site entier) pour qui édite tout le
	 * contenu, les pages confiées seulement pour un compte Réviseur, un tableau vide pour qui n'a
	 * pas accès à l'écran (le nombre affiché reste alors à zéro, sans requête).
	 *
	 * @return list<int>|null
	 */
	private static function current_scope(): ?array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return array();
		}
		return current_user_can( 'edit_others_posts' ) ? null : Reviewer::assigned_post_ids( get_current_user_id() );
	}

	/**
	 * Enregistre les réviseurs confiés sur un contenu depuis le formulaire de l'écran Révision.
	 * Même méta que le panneau du document (_fluffless_reviewers) : les deux champs se recouvrent.
	 */
	public static function handle_set_reviewers(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fluffless-suite' ), 403 );
		}
		check_admin_referer( self::ACTION_SET_REVIEWERS );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié juste au-dessus par check_admin_referer().
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) instanceof \WP_Post ) {
			wp_die( esc_html__( 'This page could not be found.', 'fluffless-suite' ), 404 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- chaque valeur est passée à absint() ci-dessous ; le nonce est vérifié au-dessus par check_admin_referer().
		$submitted = isset( $_POST['fl_reviewers'] ) && is_array( $_POST['fl_reviewers'] ) ? array_values( array_map( 'absint', wp_unslash( $_POST['fl_reviewers'] ) ) ) : array();
		update_post_meta( $post_id, Reviewer::META_REVIEWERS, self::sanitize_reviewer_ids( $submitted ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- lecture seule, reprise dans la redirection ; vérifiée par check_admin_referer() au-dessus.
		$filter = isset( $_POST['fl_filter'] ) ? sanitize_key( wp_unslash( $_POST['fl_filter'] ) ) : 'open';
		$filter = in_array( $filter, self::FILTERS, true ) ? $filter : 'open';
		$url    = add_query_arg(
			array(
				'page'      => self::PAGE,
				'fl_filter' => $filter,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Réduit une liste d'identifiants soumis aux seuls comptes qui ont vraiment le rôle Réviseur :
	 * décision pure, séparée de handle_set_reviewers() pour rester testable (celle-ci se termine par
	 * exit).
	 *
	 * @param array<int> $submitted Identifiants soumis par le formulaire.
	 * @return list<int>
	 */
	private static function sanitize_reviewer_ids( array $submitted ): array {
		$valid_ids = wp_list_pluck( Reviewer::choices(), 'id' );
		return array_values( array_intersect( $submitted, $valid_ids ) );
	}

	/* ---------- Lecture ---------- */

	/**
	 * Nombre de fils ouverts, sur tout le site (null) ou sur les seuls contenus indiqués.
	 *
	 * @param list<int>|null $post_ids Contenus à considérer, ou null pour le site entier.
	 */
	public static function count_open( ?array $post_ids = null ): int {
		if ( is_array( $post_ids ) && array() === $post_ids ) {
			return 0;
		}
		$args = array(
			'type'    => 'note',
			'parent'  => 0,
			'status'  => 'hold',
			'count'   => true,
			'orderby' => 'none',
		);
		if ( is_array( $post_ids ) ) {
			$args['post__in'] = $post_ids;
		}
		return (int) get_comments( $args );
	}

	/**
	 * Fils de notes, les plus récents d'abord, sur tout le site (null) ou sur les seuls contenus indiqués.
	 *
	 * @param string         $filter   « open », « resolved » ou « all ».
	 * @param list<int>|null $post_ids Contenus à considérer, ou null pour le site entier.
	 * @return list<array{id: int, post: int, author: string, date: string, excerpt: string, replies: int, resolved: bool}>
	 */
	public static function threads( string $filter = 'open', ?array $post_ids = null ): array {
		if ( is_array( $post_ids ) && array() === $post_ids ) {
			return array();
		}
		$status = array(
			'open'     => 'hold',
			'resolved' => 'approve',
			'all'      => 'all',
		);
		$args   = array(
			'type'    => 'note',
			'parent'  => 0,
			'status'  => $status[ $filter ] ?? 'hold',
			'number'  => self::MAX,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);
		if ( is_array( $post_ids ) ) {
			$args['post__in'] = $post_ids;
		}
		$notes = get_comments( $args );
		$out   = array();
		foreach ( $notes as $note ) {
			$out[] = array(
				'id'       => (int) $note->comment_ID,
				'post'     => (int) $note->comment_post_ID,
				'author'   => $note->comment_author,
				'date'     => $note->comment_date_gmt,
				'excerpt'  => self::excerpt( $note->comment_content ),
				'replies'  => self::replies( (int) $note->comment_ID ),
				'resolved' => '1' === (string) $note->comment_approved,
			);
		}
		return $out;
	}

	/**
	 * Réponses d'un fil, sans les marques de résolution ou de réouverture laissées vides.
	 *
	 * @param int $id Note de premier niveau.
	 */
	private static function replies( int $id ): int {
		$children = get_comments(
			array(
				'type'   => 'note',
				'parent' => $id,
				'status' => 'all',
				'fields' => 'ids',
			)
		);
		$count    = 0;
		foreach ( $children as $child_id ) {
			$child = get_comment( (int) $child_id );
			if ( $child instanceof \WP_Comment && '' !== trim( $child->comment_content ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Extrait en texte brut.
	 *
	 * @param string $content Contenu de la note.
	 */
	public static function excerpt( string $content ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $content ) ) );
		return mb_strlen( $text ) > self::EXCERPT ? rtrim( mb_substr( $text, 0, self::EXCERPT - 1 ) ) . '…' : $text;
	}

	/**
	 * Adresse de l'éditeur qui montre le bloc de la note. Ne passe jamais par get_edit_post_link() :
	 * son current_user_can( 'edit_post' ) refuserait toujours un compte Réviseur sur cet écran, hors
	 * du contexte admis par Reviewer::guard() (voir in_whitelisted_context()), alors même que
	 * can_link_to_editor() a déjà tranché, par Reviewer::CAP, qu'un lien a du sens ici.
	 *
	 * @param int $post Contenu.
	 * @param int $note Note de premier niveau.
	 */
	public static function editor_url( int $post, int $note ): string {
		$post_object      = get_post( $post );
		$post_type_object = $post_object instanceof \WP_Post ? get_post_type_object( $post_object->post_type ) : null;
		if ( ! $post_type_object instanceof \WP_Post_Type || '' === (string) $post_type_object->_edit_link ) {
			return '';
		}
		$url = admin_url( sprintf( $post_type_object->_edit_link . '&action=edit', $post_object->ID ) );
		return add_query_arg( self::QUERY_NOTE, $note, $url );
	}

	/* ---------- Éditeur ---------- */

	/**
	 * Sélectionne le bloc de la note quand l'éditeur est ouvert depuis l'écran Révision.
	 * Rien n'est chargé sans le paramètre.
	 */
	public static function editor_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture d'un identifiant, aucune écriture.
		$note = isset( $_GET[ self::QUERY_NOTE ] ) ? absint( wp_unslash( $_GET[ self::QUERY_NOTE ] ) ) : 0;
		if ( $note <= 0 ) {
			return;
		}
		wp_register_script( 'fluffless-review', false, array( 'wp-data', 'wp-block-editor' ), VERSION, array( 'in_footer' => true ) );
		wp_enqueue_script( 'fluffless-review' );
		wp_add_inline_script( 'fluffless-review', self::editor_script( $note ) );
	}

	/**
	 * Petit script : sélectionne le bloc qui porte la note dès qu'il est chargé.
	 *
	 * @param int $note Note de premier niveau.
	 */
	public static function editor_script( int $note ): string {
		// L'éditeur remplace ses blocs après le premier chargement : la sélection est refaite
		// si elle saute, puis la surveillance s'arrête après cinq secondes.
		return '(function(d,n){var u=d.subscribe(function(){var s=d.select("core/block-editor"),c=s.getSelectedBlockClientId();'
			. 'var has=function(id){var m=(s.getBlockAttributes(id)||{}).metadata;return !!m&&Number(m.noteId)===n;};'
			. 'if(c&&has(c)){return;}var ids=s.getClientIdsWithDescendants();'
			. 'for(var i=0;i<ids.length;i++){if(has(ids[i])){d.dispatch("core/block-editor").selectBlock(ids[i]);return;}}});'
			. 'setTimeout(u,5000);})(wp.data,' . $note . ');';
	}

	/* ---------- Écran ---------- */

	/** Écran Révision, scopé aux pages confiées pour un compte Réviseur. */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$post_ids = self::current_scope();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple filtre d'affichage.
		$filter = isset( $_GET['fl_filter'] ) ? sanitize_key( wp_unslash( $_GET['fl_filter'] ) ) : 'open';
		$filter = in_array( $filter, self::FILTERS, true ) ? $filter : 'open';
		echo '<div class="wrap">';
		Screen::header( __( 'Review', 'fluffless-suite' ), __( 'Every note left in the editor, page by page. Notes are added, answered and resolved in the editor; this screen shows where things stand.', 'fluffless-suite' ) );
		self::render_filters( $filter );
		if ( is_array( $post_ids ) && array() === $post_ids ) {
			echo '<p>' . esc_html__( 'No page has been assigned to you yet. Ask the person who manages the site to assign you one in Fluffless → Review; it will then appear here.', 'fluffless-suite' ) . '</p></div>';
			return;
		}
		$threads = self::threads( $filter, $post_ids );
		if ( array() === $threads ) {
			$empty = array(
				'open'     => __( 'No open note. Everything is resolved.', 'fluffless-suite' ),
				'resolved' => __( 'No resolved note yet.', 'fluffless-suite' ),
				'all'      => __( 'No note yet. To add one: 1) open a page in the editor, 2) select the block to comment on, 3) in its toolbar menu (⋮), choose Add note, write it and save. Notes then appear here, page by page.', 'fluffless-suite' ),
			);
			echo '<p>' . esc_html( $empty[ $filter ] ) . '</p></div>';
			return;
		}
		$by_post = array();
		foreach ( $threads as $thread ) {
			$by_post[ $thread['post'] ][] = $thread;
		}
		foreach ( $by_post as $post_id => $group ) {
			self::render_post( (int) $post_id, $group, $filter );
		}
		if ( count( $threads ) >= self::MAX ) {
			/* translators: %d: maximum number of notes shown. */
			echo '<p>' . esc_html( sprintf( __( 'Only the %d most recent notes are shown.', 'fluffless-suite' ), self::MAX ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Liens de filtre, l'actif marqué.
	 *
	 * @param string $current Filtre actif.
	 */
	private static function render_filters( string $current ): void {
		$labels = array(
			'open'     => _x( 'Open', 'notes filter', 'fluffless-suite' ),
			'resolved' => _x( 'Resolved', 'notes filter', 'fluffless-suite' ),
			'all'      => _x( 'All', 'notes filter', 'fluffless-suite' ),
		);
		echo '<ul class="subsubsub">';
		$last = array_key_last( $labels );
		foreach ( $labels as $key => $label ) {
			$url  = add_query_arg(
				array(
					'page'      => self::PAGE,
					'fl_filter' => $key,
				),
				admin_url( 'admin.php' )
			);
			$attr = $key === $current ? ' class="current" aria-current="page"' : '';
			echo '<li><a href="' . esc_url( $url ) . '"' . $attr . '>' . esc_html( $label ) . '</a>' . ( $key === $last ? '' : ' |' ) . '</li> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attr est une chaîne fixe.
		}
		echo '</ul><br class="clear">';
	}

	/**
	 * Tableau des fils d'un contenu.
	 *
	 * @param int                                                                                                          $post_id Contenu.
	 * @param list<array{id: int, post: int, author: string, date: string, excerpt: string, replies: int, resolved: bool}> $threads Fils.
	 * @param string                                                                                                       $filter  Filtre actif, repris par le formulaire des réviseurs pour sa redirection.
	 */
	private static function render_post( int $post_id, array $threads, string $filter ): void {
		$post  = get_post( $post_id );
		$title = $post instanceof \WP_Post ? get_the_title( $post ) : '';
		$title = '' !== $title ? $title : __( '(no title)', 'fluffless-suite' );
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Note', 'fluffless-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'By', 'fluffless-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Date', 'fluffless-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Replies', 'fluffless-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'fluffless-suite' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'fluffless-suite' ) . '</span></th>';
		echo '</tr></thead><tbody>';
		foreach ( $threads as $thread ) {
			$url  = self::can_link_to_editor( $post_id ) ? self::editor_url( $post_id, $thread['id'] ) : '';
			$date = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( $thread['date'] ) );
			echo '<tr>';
			echo '<td>' . esc_html( '' !== $thread['excerpt'] ? $thread['excerpt'] : __( '(empty note)', 'fluffless-suite' ) ) . '</td>';
			echo '<td>' . esc_html( $thread['author'] ) . '</td>';
			echo '<td>' . esc_html( is_string( $date ) ? $date : '' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $thread['replies'] ) ) . '</td>';
			echo '<td>' . esc_html( $thread['resolved'] ? __( 'Resolved', 'fluffless-suite' ) : __( 'Open', 'fluffless-suite' ) ) . '</td>';
			echo '<td>';
			if ( '' !== $url ) {
				/* translators: %s: page title. */
				echo '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( sprintf( __( 'See this note in the editor of %s', 'fluffless-suite' ), $title ) ) . '">' . esc_html__( 'See in the editor', 'fluffless-suite' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_reviewers_field( $post_id, $filter );
	}

	/**
	 * Champ « Réviseurs » de l'écran Révision : mêmes comptes, même méta que le panneau du document
	 * (Reviewer::editor_assets()) ; jamais montré à un compte Réviseur, qui ne peut pas se confier
	 * de pages à lui-même.
	 *
	 * @param int    $post_id Contenu.
	 * @param string $filter  Filtre actif, repris par la redirection après enregistrement.
	 */
	private static function render_reviewers_field( int $post_id, string $filter ): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$reviewers = Reviewer::choices();
		if ( array() === $reviewers ) {
			return;
		}
		$assigned = get_post_meta( $post_id, Reviewer::META_REVIEWERS, true );
		$assigned = is_array( $assigned ) ? array_map( 'absint', $assigned ) : array();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SET_REVIEWERS );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SET_REVIEWERS ) . '">';
		echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '">';
		echo '<input type="hidden" name="fl_filter" value="' . esc_attr( $filter ) . '">';
		echo '<p><strong>' . esc_html__( 'Reviewers', 'fluffless-suite' ) . '</strong></p><p>';
		foreach ( $reviewers as $reviewer ) {
			echo '<label style="margin-right:1em;display:inline-block;"><input type="checkbox" name="fl_reviewers[]" value="' . esc_attr( (string) $reviewer['id'] ) . '"' . checked( in_array( $reviewer['id'], $assigned, true ), true, false ) . '> ' . esc_html( $reviewer['name'] ) . '</label>';
		}
		echo '</p><p><button type="submit" class="button">' . esc_html__( 'Save reviewers', 'fluffless-suite' ) . '</button></p></form>';
	}

	/**
	 * Le lien « Voir dans l'éditeur » a-t-il un sens pour ce compte sur ce contenu ? Affichage
	 * seulement, jamais une garde de sécurité : la sûreté du clic reste entière une fois dans
	 * l'éditeur, portée par map_meta_cap (voir Reviewer::guard()) et par Reviewer::is_assigned_for_display(),
	 * qui ne fait, elle non plus, qu'un constat de lecture.
	 *
	 * @param int $post_id Contenu visé par le fil.
	 */
	private static function can_link_to_editor( int $post_id ): bool {
		if ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}
		return current_user_can( Reviewer::CAP ) && Reviewer::is_assigned_for_display( $post_id, get_current_user_id() );
	}
}
