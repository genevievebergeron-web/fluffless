<?php
/**
 * Tests du module Révision : lecture des fils de notes (ouverts, réglés, tous), réponses comptées
 * sans les marques de résolution vides, extrait en texte brut, compteur de l'onglet, adresse de
 * l'éditeur, script chargé seulement avec le paramètre, écran échappé et réservé aux droits voulus.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/review-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * Le contenu et les notes créés sont supprimés à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Review;
use Fluffless\Suite\Modules\Reviewer;

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les textes en en_US : indépendants de la langue du site.
switch_to_locale( 'en_US' );

/** Invoque une méthode privée/statique de Review par réflexion, pour tester une décision pure. */
$fluffless_call_private = static function ( string $method, ...$args ) {
	$reflection = new ReflectionMethod( Review::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, ...$args );
};

/** Déballe l'identifiant renvoyé par wp_insert_user(), ou arrête la mise en place du test. */
$fluffless_require_id = static function ( $id_or_error ): int {
	if ( is_wp_error( $id_or_error ) ) {
		throw new RuntimeException( esc_html( 'Mise en place du test : ' . $id_or_error->get_error_message() ) );
	}
	return (int) $id_or_error;
};

if ( ! class_exists( Review::class ) || ! isset( Loader::modules()['review'] ) ) {
	throw new RuntimeException( 'Module Révision absent.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_user        = get_current_user_id();
$fluffless_post        = 0;
$fluffless_other_post  = 0;
$fluffless_reviewer_id = 0;
$fluffless_ids         = array();
$fluffless_note        = static function ( int $post, string $content, string $approved, int $parent_id = 0, string $status = '' ) use ( &$fluffless_ids ): int {
	$id = (int) wp_insert_comment(
		array(
			'comment_post_ID'  => $post,
			'comment_type'     => 'note',
			'comment_content'  => $content,
			'comment_approved' => $approved,
			'comment_parent'   => $parent_id,
			'comment_author'   => 'Réviseure test',
		)
	);
	if ( '' !== $status ) {
		add_comment_meta( $id, '_wp_note_status', $status );
	}
	$fluffless_ids[] = $id;
	return $id;
};

try {
	$fluffless_admins   = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ids',
		)
	);
	$fluffless_admin_id = (int) ( $fluffless_admins[0] ?? 1 );
	wp_set_current_user( $fluffless_admin_id );

	$fluffless_reviewer_id = $fluffless_require_id(
		wp_insert_user(
			array(
				'user_login' => 'fluffless-review-screen-test',
				'user_pass'  => wp_generate_password( 32 ),
				'user_email' => 'fluffless-review-screen-test@example.com',
				'role'       => Reviewer::ROLE,
			)
		)
	);

	$fluffless_post       = (int) wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Page révision test',
		)
	);
	$fluffless_other_post = (int) wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Page non confiée révision test',
		)
	);
	update_post_meta( $fluffless_post, Reviewer::META_REVIEWERS, array( $fluffless_reviewer_id ) );
	$fluffless_note_other_post = $fluffless_note( $fluffless_other_post, 'Note sur la page non confiée', '0' );
	$fluffless_open_before     = Review::count_open();
	$fluffless_open            = $fluffless_note( $fluffless_post, "Changer <strong>ce titre</strong>\n\npour  quelque chose de plus court", '0' );
	$fluffless_resolved        = $fluffless_note( $fluffless_post, 'Photo trop sombre', '1' );
	$fluffless_note( $fluffless_post, 'Je propose « Nos services ».', '0', $fluffless_open );
	$fluffless_note( $fluffless_post, 'Réponse à la note réglée', '1', $fluffless_resolved );
	$fluffless_note( $fluffless_post, '', '1', $fluffless_resolved, 'resolved' );
	$fluffless_comment = (int) wp_insert_comment(
		array(
			'comment_post_ID'  => $fluffless_post,
			'comment_content'  => 'Commentaire public',
			'comment_approved' => '0',
		)
	);
	$fluffless_ids[]   = $fluffless_comment;

	// Lecture.
	$fluffless_find = static function ( array $threads, int $id ): ?array {
		foreach ( $threads as $thread ) {
			if ( $thread['id'] === $id ) {
				return $thread;
			}
		}
		return null;
	};
	$fluffless_o    = Review::threads( 'open' );
	$fluffless_r    = Review::threads( 'resolved' );
	$fluffless_a    = Review::threads( 'all' );
	$fluffless_assert( null !== $fluffless_find( $fluffless_o, $fluffless_open ) && null === $fluffless_find( $fluffless_o, $fluffless_resolved ), 'ouvertes : le fil ouvert seulement' );
	$fluffless_assert( null !== $fluffless_find( $fluffless_r, $fluffless_resolved ) && null === $fluffless_find( $fluffless_r, $fluffless_open ), 'réglées : le fil réglé seulement' );
	$fluffless_assert( null !== $fluffless_find( $fluffless_a, $fluffless_open ) && null !== $fluffless_find( $fluffless_a, $fluffless_resolved ), 'toutes : les deux fils' );
	$fluffless_all_ids = array_column( $fluffless_a, 'id' );
	$fluffless_assert( ! in_array( $fluffless_comment, $fluffless_all_ids, true ), 'un commentaire ordinaire n\'est jamais un fil' );
	$fluffless_assert( ! array_intersect( array_slice( $fluffless_ids, 3, 3 ), $fluffless_all_ids ), 'les réponses ne sont pas des fils' );
	$fluffless_t = $fluffless_find( $fluffless_a, $fluffless_open );
	$fluffless_assert( is_array( $fluffless_t ) && $fluffless_post === $fluffless_t['post'] && false === $fluffless_t['resolved'] && 'Réviseure test' === $fluffless_t['author'], 'fil : contenu, état, auteur' );
	$fluffless_assert( is_array( $fluffless_t ) && 'Changer ce titre pour quelque chose de plus court' === $fluffless_t['excerpt'], 'extrait : balises retirées, espaces ramenés' );
	$fluffless_assert( is_array( $fluffless_t ) && 1 === $fluffless_t['replies'], 'réponses : une' );
	$fluffless_t = $fluffless_find( $fluffless_a, $fluffless_resolved );
	$fluffless_assert( is_array( $fluffless_t ) && true === $fluffless_t['resolved'] && 1 === $fluffless_t['replies'], 'réponses : la marque de résolution vide ne compte pas' );
	$fluffless_assert( Review::threads( 'nimporte' ) === $fluffless_o, 'filtre inconnu : ouvertes' );
	$fluffless_assert( Review::count_open() === $fluffless_open_before + 1, 'compteur : un fil ouvert de plus' );

	// Extrait.
	$fluffless_long = Review::excerpt( str_repeat( 'é', 300 ) );
	$fluffless_assert( Review::EXCERPT === mb_strlen( $fluffless_long ) && str_ends_with( $fluffless_long, '…' ), 'extrait : coupé à la longueur, points de suspension' );
	$fluffless_assert( 'alert(1)' === Review::excerpt( '<script>alert(1)</script>' ) || '' === Review::excerpt( '<script>alert(1)</script>' ), 'extrait : aucune balise' );

	// Adresse et script.
	$fluffless_url = Review::editor_url( $fluffless_post, $fluffless_open );
	$fluffless_assert( str_contains( $fluffless_url, 'post=' . $fluffless_post ) && str_contains( $fluffless_url, Review::QUERY_NOTE . '=' . $fluffless_open ), 'adresse : page et note' );
	$fluffless_assert( '' === Review::editor_url( 999999999, 1 ), 'adresse : contenu inexistant, aucune' );
	$fluffless_js = Review::editor_script( 42 );
	$fluffless_assert( str_contains( $fluffless_js, ',42);' ) && str_contains( $fluffless_js, 'selectBlock' ) && str_contains( $fluffless_js, 'noteId' ), 'script : sélectionne le bloc de la note' );
	unset( $_GET[ Review::QUERY_NOTE ] );
	Review::editor_assets();
	$fluffless_assert( ! wp_script_is( 'fluffless-review', 'registered' ), 'script : rien sans le paramètre' );
	$_GET[ Review::QUERY_NOTE ] = 'abc';
	Review::editor_assets();
	$fluffless_assert( ! wp_script_is( 'fluffless-review', 'registered' ), 'script : rien avec un paramètre non numérique' );
	$_GET[ Review::QUERY_NOTE ] = (string) $fluffless_open;
	Review::editor_assets();
	$fluffless_assert( wp_script_is( 'fluffless-review', 'enqueued' ), 'script : chargé avec le paramètre' );
	unset( $_GET[ Review::QUERY_NOTE ] );

	// Écran.
	$_GET['fl_filter'] = 'all';
	ob_start();
	Review::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html, 'Page révision test' ) && str_contains( $fluffless_html, 'Photo trop sombre' ), 'écran : page et notes' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<strong>ce titre' ) && ! str_contains( $fluffless_html, 'Commentaire public' ), 'écran : aucune balise de note, aucun commentaire public' );
	$fluffless_assert( str_contains( $fluffless_html, esc_url( $fluffless_url ) ), 'écran : lien vers le bloc' );
	$fluffless_assert( str_contains( $fluffless_html, 'aria-current="page"' ), 'écran : filtre actif marqué' );
	$_GET['fl_filter'] = '"><script>';
	ob_start();
	Review::render();
	$fluffless_html = (string) ob_get_clean();
	$fluffless_assert( ! str_contains( $fluffless_html, '<script>' ), 'écran : filtre injecté ignoré' );
	unset( $_GET['fl_filter'] );

	// Droit synthétique CAPABILITY : décision pure, un ou l'autre public, jamais un vrai droit stocké.
	$fluffless_grant_none  = Review::grant_screen_capability( array(), array( Review::CAPABILITY ), array(), new WP_User( 0 ) );
	$fluffless_grant_edit  = Review::grant_screen_capability( array( 'edit_others_posts' => true ), array( Review::CAPABILITY ), array(), new WP_User( 0 ) );
	$fluffless_grant_rev   = Review::grant_screen_capability( array( Reviewer::CAP => true ), array( Review::CAPABILITY ), array(), new WP_User( 0 ) );
	$fluffless_grant_other = Review::grant_screen_capability( array( 'edit_others_posts' => true ), array( 'read' ), array(), new WP_User( 0 ) );
	$fluffless_assert( true !== ( $fluffless_grant_none[ Review::CAPABILITY ] ?? false ), 'CAPABILITY : ni edit_others_posts ni Reviewer::CAP, refusé' );
	$fluffless_assert( true === ( $fluffless_grant_edit[ Review::CAPABILITY ] ?? false ), 'CAPABILITY : accordé à edit_others_posts' );
	$fluffless_assert( true === ( $fluffless_grant_rev[ Review::CAPABILITY ] ?? false ), 'CAPABILITY : accordé au rôle Réviseur' );
	$fluffless_assert( ! isset( $fluffless_grant_other[ Review::CAPABILITY ] ) || true !== $fluffless_grant_other[ Review::CAPABILITY ], 'CAPABILITY : n\'entre en jeu que si demandé explicitement' );

	// Réduction des identifiants soumis par le formulaire aux seuls comptes Réviseur réels.
	$fluffless_sanitized = $fluffless_call_private( 'sanitize_reviewer_ids', array( $fluffless_reviewer_id, 999999999 ) );
	$fluffless_assert( array( $fluffless_reviewer_id ) === $fluffless_sanitized, 'formulaire réviseurs : identifiant invalide écarté, identifiant réel gardé' );

	// Portée et affichage pour un compte Réviseur assigné à une seule des deux pages.
	wp_set_current_user( $fluffless_reviewer_id );
	$fluffless_assert( current_user_can( Review::CAPABILITY ), 'CAPABILITY : accordé au compte Réviseur assigné' );
	$fluffless_scope = $fluffless_call_private( 'current_scope' );
	$fluffless_assert( array( $fluffless_post ) === $fluffless_scope, 'portée : seule la page confiée' );
	$fluffless_assert( 1 === Review::count_open( array( $fluffless_post ) ), 'compteur scopé : un fil ouvert sur la page confiée' );
	$fluffless_assert( 0 === Review::count_open( array() ), 'compteur scopé : zéro sur un tableau vide, sans requête' );
	$fluffless_assert( array() === Review::threads( 'all', array() ), 'fils scopés : rien sur un tableau vide' );
	$fluffless_scoped_threads = Review::threads( 'all', $fluffless_scope );
	$fluffless_assert( null === $fluffless_find( $fluffless_scoped_threads, $fluffless_note_other_post ), 'fils scopés : la note de l\'autre page n\'apparaît pas' );

	ob_start();
	Review::render();
	$fluffless_html_reviewer = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html_reviewer, 'Page révision test' ) && ! str_contains( $fluffless_html_reviewer, 'Page non confiée révision test' ), 'écran réviseur : seule la page confiée' );
	$fluffless_assert( ! str_contains( $fluffless_html_reviewer, 'name="fl_reviewers[]"' ), 'écran réviseur : jamais le formulaire de confiage, réservé à edit_others_posts' );
	$fluffless_assert( str_contains( $fluffless_html_reviewer, esc_url( $fluffless_url ) ), 'écran réviseur : lien vers l\'éditeur sur sa page confiée' );

	$fluffless_can_link_assigned = $fluffless_call_private( 'can_link_to_editor', $fluffless_post );
	$fluffless_can_link_other    = $fluffless_call_private( 'can_link_to_editor', $fluffless_other_post );
	$fluffless_assert( true === $fluffless_can_link_assigned, 'affichage seul : lien vers l\'éditeur sur la page confiée' );
	$fluffless_assert( false === $fluffless_can_link_other, 'affichage seul : aucun lien sur une page non confiée' );

	// Le formulaire de confiage n'est proposé qu'à un compte qui édite tout le contenu.
	wp_set_current_user( $fluffless_admin_id );
	ob_start();
	Review::render();
	$fluffless_html_admin = (string) ob_get_clean();
	$fluffless_assert( str_contains( $fluffless_html_admin, 'name="fl_reviewers[]"' ) && str_contains( $fluffless_html_admin, 'Save reviewers' ), 'écran admin : formulaire de confiage présent' );

	wp_set_current_user( 0 );
	ob_start();
	Review::render();
	$fluffless_assert( '' === (string) ob_get_clean(), 'écran : rien sans droit' );
} finally {
	foreach ( $fluffless_ids as $fluffless_id ) {
		wp_delete_comment( $fluffless_id, true );
	}
	if ( $fluffless_post > 0 ) {
		wp_delete_post( $fluffless_post, true );
	}
	if ( $fluffless_other_post > 0 ) {
		wp_delete_post( $fluffless_other_post, true );
	}
	if ( $fluffless_reviewer_id > 0 ) {
		wp_delete_user( $fluffless_reviewer_id );
	}
	wp_set_current_user( $fluffless_user );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Révision : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Révision : {$fluffless_checks} vérifications passées." );
