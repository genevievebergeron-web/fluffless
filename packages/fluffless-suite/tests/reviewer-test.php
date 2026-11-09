<?php
/**
 * Tests du rôle Réviseur : capacité sans écriture, pages confiées obligatoires, contexte admis
 * obligatoire (éditeur classique en lecture, ou route REST de la liste blanche), barrière REST qui
 * refuse tout le reste pour ce rôle sans toucher aux autres comptes, méta des pages confiées jamais
 * ouverte par edit_post, garde fine des notes (création, lecture en liste et à l'unité, modification,
 * suppression), retrait du courriel/IP/mot de passe d'autrui des réponses REST, verrou d'édition,
 * autosauvegarde, XML-RPC, admin-ajax, admin-post, mots de passe d'application, redirections d'écrans.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/reviewer-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul. Le contenu et les comptes créés
 * sont supprimés à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Reviewer;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Reviewer::class ) || ! isset( Loader::modules()['reviewer'] ) ) {
	throw new RuntimeException( 'Module Réviseur absent.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

/** Invoque une méthode privée/statique de Reviewer par réflexion, pour tester une décision pure. */
$fluffless_call_private = static function ( string $method, ...$args ) {
	$reflection = new ReflectionMethod( Reviewer::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, ...$args );
};

/** Déballe l'identifiant renvoyé par wp_insert_user()/wp_insert_post(), ou arrête la mise en place du test. */
$fluffless_require_id = static function ( $id_or_error ): int {
	if ( is_wp_error( $id_or_error ) ) {
		throw new RuntimeException( esc_html( 'Mise en place du test : ' . $id_or_error->get_error_message() ) );
	}
	return (int) $id_or_error;
};

$fluffless_user_before  = get_current_user_id();
$fluffless_reviewer_id  = 0;
$fluffless_reviewer2_id = 0;
$fluffless_assigned     = 0;
$fluffless_other        = 0;
$fluffless_protected    = 0;
$fluffless_note_own     = 0;
$fluffless_note_other   = 0;
$fluffless_note_child   = 0;
$fluffless_comment_pub  = 0;
$fluffless_author_id    = 0;
$fluffless_author_post  = 0;

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

	$fluffless_reviewer_id  = $fluffless_require_id(
		wp_insert_user(
			array(
				'user_login' => 'fluffless-reviewer-test',
				'user_pass'  => wp_generate_password( 32 ),
				'user_email' => 'fluffless-reviewer-test@example.com',
				'role'       => Reviewer::ROLE,
			)
		)
	);
	$fluffless_reviewer2_id = $fluffless_require_id(
		wp_insert_user(
			array(
				'user_login' => 'fluffless-reviewer-test-2',
				'user_pass'  => wp_generate_password( 32 ),
				'user_email' => 'fluffless-reviewer-test-2@example.com',
				'role'       => Reviewer::ROLE,
			)
		)
	);
	$fluffless_assigned     = (int) wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Page confiée test',
		)
	);
	$fluffless_other        = (int) wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Page non confiée test',
		)
	);
	$fluffless_protected    = (int) wp_insert_post(
		array(
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_title'    => 'Page protégée test',
			'post_password' => 'motdepasse-reel-jamais-saisi-par-lagent',
		)
	);
	update_post_meta( $fluffless_assigned, Reviewer::META_REVIEWERS, array( $fluffless_reviewer_id ) );
	update_post_meta( $fluffless_protected, Reviewer::META_REVIEWERS, array( $fluffless_reviewer_id ) );

	// Rôle et capacité : lecture et la capacité propre seulement, jamais d'écriture.
	$fluffless_role = wp_roles()->get_role( Reviewer::ROLE );
	if ( null === $fluffless_role ) {
		throw new RuntimeException( 'Mise en place du test : rôle Réviseur absent.' );
	}
	$fluffless_assert( true === ( $fluffless_role->capabilities['read'] ?? false ), 'rôle : lecture' );
	$fluffless_assert( true === ( $fluffless_role->capabilities[ Reviewer::CAP ] ?? false ), 'rôle : capacité propre' );
	foreach ( array( 'edit_posts', 'edit_pages', 'edit_others_posts', 'delete_posts', 'publish_posts', 'upload_files', 'moderate_comments', 'unfiltered_html' ) as $fluffless_forbidden ) {
		$fluffless_assert( true !== ( $fluffless_role->capabilities[ $fluffless_forbidden ] ?? false ), "rôle : jamais {$fluffless_forbidden}" );
	}

	wp_set_current_user( $fluffless_reviewer_id );

	// Hors tout contexte admis : refus, même sur la page confiée.
	$fluffless_assert( ! current_user_can( 'edit_post', $fluffless_assigned ), 'hors contexte : édition refusée même confiée' );
	$fluffless_assert( ! current_user_can( 'read_post', $fluffless_assigned ), 'hors contexte : lecture refusée même confiée' );

	// post.php GET action=edit : admis seulement sur la page confiée.
	$GLOBALS['pagenow']        = 'post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simule le contexte wp-admin réel pour ce test, sans wp-admin en jeu.
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_GET['action']            = 'edit';
	$fluffless_assert( current_user_can( 'edit_post', $fluffless_assigned ), 'post.php GET action=edit : édition admise sur la page confiée' );
	$fluffless_assert( ! current_user_can( 'edit_post', $fluffless_other ), 'post.php GET action=edit : refus sur une page non confiée' );

	// post.php POST action=editpost : refusé même sur la page confiée (lecture seule cette session).
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_GET['action']            = 'editpost';
	$fluffless_assert( ! current_user_can( 'edit_post', $fluffless_assigned ), 'post.php POST action=editpost : toujours refusé' );
	unset( $_GET['action'] );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$GLOBALS['pagenow']        = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- remise à zéro du contexte simulé ci-dessus.

	// Barrière REST : chemin de la liste blanche admis sur la page confiée, refusé sur l'autre.
	$fluffless_route = (string) rest_get_route_for_post( $fluffless_assigned );
	$fluffless_assert( '' !== $fluffless_route, 'route REST de la page confiée trouvée' );
	$fluffless_req = new WP_REST_Request( 'GET', $fluffless_route );
	$fluffless_req->set_param( 'context', 'edit' );
	$fluffless_res_assigned = rest_do_request( $fluffless_req );
	$fluffless_assert( 200 === $fluffless_res_assigned->get_status(), 'REST liste blanche + page confiée : 200' );

	$fluffless_route_other = (string) rest_get_route_for_post( $fluffless_other );
	$fluffless_req_other   = new WP_REST_Request( 'GET', $fluffless_route_other );
	$fluffless_req_other->set_param( 'context', 'edit' );
	$fluffless_res_other = rest_do_request( $fluffless_req_other );
	$fluffless_assert( 403 === $fluffless_res_other->get_status(), 'REST liste blanche + page non confiée : refus' );

	// Remise à zéro de l'état REST : hors REST, le contexte n'est plus admis.
	$fluffless_assert( ! current_user_can( 'edit_post', $fluffless_assigned ), 'après une requête REST : contexte remis à zéro' );

	// Barrière REST : route factice (n'existe nulle part) refusée pour ce rôle, même en GET.
	$fluffless_res_decoy = rest_do_request( new WP_REST_Request( 'GET', '/fluffless/v1/route-factice-inexistante' ) );
	$fluffless_assert( 403 === $fluffless_res_decoy->get_status(), 'REST hors liste blanche : route factice refusée' );
	$fluffless_res_posts = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
	$fluffless_assert( 403 === $fluffless_res_posts->get_status(), 'REST hors liste blanche : /wp/v2/posts (collection) refusé' );
	$fluffless_res_put_page = rest_do_request( new WP_REST_Request( 'PUT', $fluffless_route ) );
	$fluffless_assert( 403 === $fluffless_res_put_page->get_status(), 'REST hors liste blanche : PUT sur la page confiée refusé' );
	// P3-01 : le refus dit à la personne qui relit ce qu'elle peut faire, en anglais source.
	switch_to_locale( 'en_US' );
	$fluffless_put_error   = rest_do_request( new WP_REST_Request( 'PUT', $fluffless_route ) )->as_error();
	$fluffless_put_message = null === $fluffless_put_error ? '' : $fluffless_put_error->get_error_message();
	restore_previous_locale();
	$fluffless_assert( str_contains( $fluffless_put_message, 'notes' ) && ! str_contains( $fluffless_put_message, 'route' ), 'refus REST en jargon pour la personne qui relit : ' . $fluffless_put_message );

	// La barrière ne touche qu'à ce rôle : un autre compte passe sur une route hors liste.
	wp_set_current_user( $fluffless_admin_id );
	$fluffless_res_admin = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
	$fluffless_assert( 200 === $fluffless_res_admin->get_status(), 'barrière : sans effet sur un autre rôle' );
	wp_set_current_user( $fluffless_reviewer_id );

	// Méta des pages confiées : jamais ouverte par edit_post, seulement par edit_others_posts.
	$fluffless_meta = get_registered_meta_keys( 'post', 'page' );
	$fluffless_assert( isset( $fluffless_meta[ Reviewer::META_REVIEWERS ]['auth_callback'] ) && is_callable( $fluffless_meta[ Reviewer::META_REVIEWERS ]['auth_callback'] ), 'méta pages confiées : enregistrée avec auth_callback' );
	$fluffless_auth = $fluffless_meta[ Reviewer::META_REVIEWERS ]['auth_callback'];
	$fluffless_assert( false === (bool) $fluffless_auth(), 'méta pages confiées : un compte Réviseur ne peut pas l’écrire' );
	wp_set_current_user( $fluffless_admin_id );
	$fluffless_assert( true === (bool) $fluffless_auth(), 'méta pages confiées : un compte avec edit_others_posts le peut' );

	// Enregistrement dans l'éditeur par un compte Auteur : l'objet meta revient en entier, liste vide comprise.
	$fluffless_author_id   = $fluffless_require_id(
		wp_insert_user(
			array(
				'user_login' => 'fluffless_test_auteur',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'fluffless_test_auteur@example.invalid',
				'role'       => 'author',
			)
		)
	);
	$fluffless_author_post = $fluffless_require_id(
		wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Article d’un compte Auteur',
				'post_author' => $fluffless_author_id,
			),
			true
		)
	);
	wp_set_current_user( $fluffless_author_id );
	$fluffless_author_route = '/wp/v2/posts/' . $fluffless_author_post;
	$fluffless_author_read  = new WP_REST_Request( 'GET', $fluffless_author_route );
	$fluffless_author_read->set_query_params( array( 'context' => 'edit' ) );
	$fluffless_author_meta = (array) ( rest_do_request( $fluffless_author_read )->get_data()['meta'] ?? array() );
	$fluffless_assert( array_key_exists( Reviewer::META_REVIEWERS, $fluffless_author_meta ), 'méta pages confiées : lisible dans l’objet meta de l’éditeur' );
	$fluffless_author_save = new WP_REST_Request( 'POST', $fluffless_author_route );
	$fluffless_author_save->set_body_params(
		array(
			'title' => 'Article d’un compte Auteur, modifié',
			'meta'  => $fluffless_author_meta,
		)
	);
	$fluffless_author_status = rest_do_request( $fluffless_author_save )->get_status();
	$fluffless_assert( 200 === $fluffless_author_status, 'méta pages confiées : enregistrement d’un compte Auteur refusé sans y toucher (statut ' . $fluffless_author_status . ')' );
	$fluffless_author_meta[ Reviewer::META_REVIEWERS ] = array( $fluffless_reviewer_id );
	$fluffless_author_save->set_body_params( array( 'meta' => $fluffless_author_meta ) );
	$fluffless_author_status = rest_do_request( $fluffless_author_save )->get_status();
	$fluffless_assert( 200 === $fluffless_author_status && array() === get_post_meta( $fluffless_author_post, Reviewer::META_REVIEWERS, true ), 'méta pages confiées : un compte Auteur a confié son article, ou refus en erreur (statut ' . $fluffless_author_status . ')' );

	// Liste confiée entre-temps depuis l'écran Révision : l'éditeur de l'Auteur renvoie l'ancienne.
	wp_set_current_user( $fluffless_admin_id );
	$fluffless_author_save->set_body_params( array( 'meta' => array( Reviewer::META_REVIEWERS => array( $fluffless_reviewer_id ) ) ) );
	$fluffless_author_status = rest_do_request( $fluffless_author_save )->get_status();
	$fluffless_assert( 200 === $fluffless_author_status && array( $fluffless_reviewer_id ) === get_post_meta( $fluffless_author_post, Reviewer::META_REVIEWERS, true ), 'méta pages confiées : un compte avec edit_others_posts ne peut plus la modifier par REST (statut ' . $fluffless_author_status . ')' );
	wp_set_current_user( $fluffless_author_id );
	foreach ( array( array(), array( $fluffless_reviewer_id ) ) as $fluffless_author_list ) {
		$fluffless_author_meta[ Reviewer::META_REVIEWERS ] = $fluffless_author_list;
		$fluffless_author_save->set_body_params(
			array(
				'title' => 'Article d’un compte Auteur, liste ' . count( $fluffless_author_list ),
				'meta'  => $fluffless_author_meta,
			)
		);
		$fluffless_author_status = rest_do_request( $fluffless_author_save )->get_status();
		$fluffless_assert( 200 === $fluffless_author_status && array( $fluffless_reviewer_id ) === get_post_meta( $fluffless_author_post, Reviewer::META_REVIEWERS, true ), 'méta pages confiées : enregistrement d’un compte Auteur avec une liste de ' . count( $fluffless_author_list ) . ' refusé ou liste changée (statut ' . $fluffless_author_status . ')' );
	}
	wp_set_current_user( $fluffless_reviewer_id );

	// --- Notes : création ---------------------------------------------------------------------

	$fluffless_req_create = new WP_REST_Request( 'POST', '/wp/v2/comments' );
	$fluffless_req_create->set_param( 'post', $fluffless_assigned );
	$fluffless_req_create->set_param( 'type', 'note' );
	$fluffless_req_create->set_param( 'content', 'Ma première note <script>alert(1)</script>' );
	$fluffless_req_create->set_param( 'status', 'hold' );
	$fluffless_res_create = rest_do_request( $fluffless_req_create );
	$fluffless_assert( in_array( $fluffless_res_create->get_status(), array( 200, 201 ), true ), 'note : création sur la page confiée admise' );
	$fluffless_note_own = (int) ( $fluffless_res_create->get_data()['id'] ?? 0 );
	$fluffless_assert( $fluffless_note_own > 0, 'note : identifiant renvoyé' );
	$fluffless_assert( false === strpos( (string) ( $fluffless_res_create->get_data()['content']['rendered'] ?? '' ), '<script' ), 'note : balise <script> retirée du contenu' );

	$fluffless_req_create_other_post = new WP_REST_Request( 'POST', '/wp/v2/comments' );
	$fluffless_req_create_other_post->set_param( 'post', $fluffless_other );
	$fluffless_req_create_other_post->set_param( 'type', 'note' );
	$fluffless_req_create_other_post->set_param( 'content', 'Note refusée' );
	$fluffless_res_create_other = rest_do_request( $fluffless_req_create_other_post );
	$fluffless_assert( 403 === $fluffless_res_create_other->get_status(), 'note : création refusée sur une page non confiée' );

	$fluffless_req_create_wrong_type = new WP_REST_Request( 'POST', '/wp/v2/comments' );
	$fluffless_req_create_wrong_type->set_param( 'post', $fluffless_assigned );
	$fluffless_req_create_wrong_type->set_param( 'type', 'comment' );
	$fluffless_req_create_wrong_type->set_param( 'content', 'Commentaire public refusé' );
	$fluffless_res_create_wrong_type = rest_do_request( $fluffless_req_create_wrong_type );
	$fluffless_assert( 403 === $fluffless_res_create_wrong_type->get_status(), 'note : création refusée si le type n’est pas note' );

	$fluffless_req_create_wrong_author = new WP_REST_Request( 'POST', '/wp/v2/comments' );
	$fluffless_req_create_wrong_author->set_param( 'post', $fluffless_assigned );
	$fluffless_req_create_wrong_author->set_param( 'type', 'note' );
	$fluffless_req_create_wrong_author->set_param( 'content', 'Note usurpée' );
	$fluffless_req_create_wrong_author->set_param( 'author', $fluffless_admin_id );
	$fluffless_res_create_wrong_author = rest_do_request( $fluffless_req_create_wrong_author );
	$fluffless_assert( 403 === $fluffless_res_create_wrong_author->get_status(), 'note : création refusée si l’autrice n’est pas le compte courant' );

	$fluffless_req_create_bad_parent = new WP_REST_Request( 'POST', '/wp/v2/comments' );
	$fluffless_req_create_bad_parent->set_param( 'post', $fluffless_assigned );
	$fluffless_req_create_bad_parent->set_param( 'type', 'note' );
	$fluffless_req_create_bad_parent->set_param( 'content', 'Réponse à une note inexistante' );
	$fluffless_req_create_bad_parent->set_param( 'parent', 999999999 );
	$fluffless_res_create_bad_parent = rest_do_request( $fluffless_req_create_bad_parent );
	$fluffless_assert( 403 === $fluffless_res_create_bad_parent->get_status(), 'note : réponse refusée si la note parente n’existe pas' );

	$fluffless_req_create_bad_status = new WP_REST_Request( 'POST', '/wp/v2/comments' );
	$fluffless_req_create_bad_status->set_param( 'post', $fluffless_assigned );
	$fluffless_req_create_bad_status->set_param( 'type', 'note' );
	$fluffless_req_create_bad_status->set_param( 'content', 'Note avec statut interdit' );
	$fluffless_req_create_bad_status->set_param( 'status', 'spam' );
	$fluffless_res_create_bad_status = rest_do_request( $fluffless_req_create_bad_status );
	$fluffless_assert( 403 === $fluffless_res_create_bad_status->get_status(), 'note : création refusée si le statut n’est pas hold/approved' );

	$fluffless_req_create_bad_meta = new WP_REST_Request( 'POST', '/wp/v2/comments' );
	$fluffless_req_create_bad_meta->set_param( 'post', $fluffless_assigned );
	$fluffless_req_create_bad_meta->set_param( 'type', 'note' );
	$fluffless_req_create_bad_meta->set_param( 'content', 'Note avec méta interdite' );
	$fluffless_req_create_bad_meta->set_param(
		'meta',
		array(
			'_wp_note_status' => 'resolved',
			'autre_cle'       => 'x',
		)
	);
	$fluffless_res_create_bad_meta = rest_do_request( $fluffless_req_create_bad_meta );
	$fluffless_assert( 403 === $fluffless_res_create_bad_meta->get_status(), 'note : création refusée si la méta porte autre chose que _wp_note_status' );

	$fluffless_req_create_reopen_meta = new WP_REST_Request( 'POST', '/wp/v2/comments' );
	$fluffless_req_create_reopen_meta->set_param( 'post', $fluffless_assigned );
	$fluffless_req_create_reopen_meta->set_param( 'type', 'note' );
	$fluffless_req_create_reopen_meta->set_param( 'content', '' );
	$fluffless_req_create_reopen_meta->set_param( 'status', 'approved' );
	$fluffless_req_create_reopen_meta->set_param( 'parent', $fluffless_note_own );
	$fluffless_req_create_reopen_meta->set_param( 'meta', array( '_wp_note_status' => 'reopen' ) );
	$fluffless_res_create_reopen_meta = rest_do_request( $fluffless_req_create_reopen_meta );
	$fluffless_assert( in_array( $fluffless_res_create_reopen_meta->get_status(), array( 200, 201 ), true ), 'note : création admise avec méta _wp_note_status seule (flux réouverture réel)' );
	$fluffless_note_child = (int) ( $fluffless_res_create_reopen_meta->get_data()['id'] ?? 0 );

	// Note d'un autre compte sur la même page confiée, pour les tests de lecture/PII ci-dessous.
	wp_set_current_user( $fluffless_admin_id );
	$fluffless_note_other  = (int) wp_insert_comment(
		array(
			'comment_post_ID'      => $fluffless_assigned,
			'comment_content'      => 'Note de quelqu’un d’autre',
			'comment_type'         => 'note',
			'comment_approved'     => '0',
			'user_id'              => $fluffless_admin_id,
			'comment_author_email' => 'admin-prive@example.com',
		)
	);
	$fluffless_comment_pub = (int) wp_insert_comment(
		array(
			'comment_post_ID'  => $fluffless_assigned,
			'comment_content'  => 'Commentaire public existant',
			'comment_type'     => 'comment',
			'comment_approved' => '1',
			'user_id'          => 0,
		)
	);
	wp_set_current_user( $fluffless_reviewer_id );

	// --- Notes : lecture en liste (le correctif de la session 3) ------------------------------

	$fluffless_req_list = new WP_REST_Request( 'GET', '/wp/v2/comments' );
	$fluffless_req_list->set_param( 'context', 'edit' );
	$fluffless_req_list->set_param( 'type', 'note' );
	$fluffless_req_list->set_param( 'status', 'all' );
	$fluffless_req_list->set_param( 'post', $fluffless_assigned );
	$fluffless_res_list = rest_do_request( $fluffless_req_list );
	$fluffless_assert( 200 === $fluffless_res_list->get_status(), 'note : liste admise sur la page confiée (type=note, context=edit)' );
	$fluffless_list_data = $fluffless_res_list->get_data();
	$fluffless_assert( is_array( $fluffless_list_data ) && count( $fluffless_list_data ) >= 3, 'note : liste renvoie bien les notes de la page confiée' );
	$fluffless_assert( ! current_user_can( 'edit_posts' ), 'note : liste ne fuit pas edit_posts hors REST' );

	$fluffless_other_entry = null;
	foreach ( $fluffless_list_data as $fluffless_entry ) {
		if ( (int) ( $fluffless_entry['id'] ?? 0 ) === $fluffless_note_other ) {
			$fluffless_other_entry = $fluffless_entry;
		}
	}
	$fluffless_assert( null !== $fluffless_other_entry, 'note : la liste inclut aussi la note d’un autre compte sur la page confiée' );
	if ( null !== $fluffless_other_entry ) {
		$fluffless_assert( '' === ( $fluffless_other_entry['author_email'] ?? 'non-vide' ), 'note : liste - courriel d’autrui retiré' );
	}

	$fluffless_req_list_other = new WP_REST_Request( 'GET', '/wp/v2/comments' );
	$fluffless_req_list_other->set_param( 'type', 'note' );
	$fluffless_req_list_other->set_param( 'post', $fluffless_other );
	$fluffless_res_list_other = rest_do_request( $fluffless_req_list_other );
	$fluffless_assert( 403 === $fluffless_res_list_other->get_status(), 'note : liste refusée sur une page non confiée' );

	$fluffless_req_list_no_post = new WP_REST_Request( 'GET', '/wp/v2/comments' );
	$fluffless_req_list_no_post->set_param( 'type', 'note' );
	$fluffless_res_list_no_post = rest_do_request( $fluffless_req_list_no_post );
	$fluffless_assert( 403 === $fluffless_res_list_no_post->get_status(), 'note : liste refusée sans publication précisée' );

	$fluffless_res_list_bare = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/comments' ) );
	$fluffless_assert( 200 === $fluffless_res_list_bare->get_status(), 'note : liste sans paramètre reste 200 (comportement déjà sûr, non affecté)' );

	// Le correctif ne fuit vers aucun autre compte Réviseur.
	wp_set_current_user( $fluffless_reviewer2_id );
	$fluffless_assert( ! current_user_can( 'edit_posts' ), 'note : correctif sans effet sur un autre compte Réviseur' );
	$fluffless_req_list_reviewer2 = new WP_REST_Request( 'GET', '/wp/v2/comments' );
	$fluffless_req_list_reviewer2->set_param( 'type', 'note' );
	$fluffless_req_list_reviewer2->set_param( 'post', $fluffless_assigned );
	$fluffless_res_list_reviewer2 = rest_do_request( $fluffless_req_list_reviewer2 );
	$fluffless_assert( 403 === $fluffless_res_list_reviewer2->get_status(), 'note : un autre compte Réviseur non confié reste refusé' );
	wp_set_current_user( $fluffless_reviewer_id );

	// --- Notes : lecture à l'unité -------------------------------------------------------------

	$fluffless_res_get_own = rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/comments/{$fluffless_note_own}" ) );
	$fluffless_assert( 200 === $fluffless_res_get_own->get_status(), 'note : lecture à l’unité de sa propre note admise' );

	// --- Notes : modification ------------------------------------------------------------------

	$fluffless_req_put_content = new WP_REST_Request( 'PUT', "/wp/v2/comments/{$fluffless_note_own}" );
	$fluffless_req_put_content->set_param( 'content', 'Contenu modifié' );
	$fluffless_res_put_content = rest_do_request( $fluffless_req_put_content );
	$fluffless_assert( 200 === $fluffless_res_put_content->get_status(), 'note : modification du contenu de sa propre note admise' );

	$fluffless_req_put_meta = new WP_REST_Request( 'PUT', "/wp/v2/comments/{$fluffless_note_own}" );
	$fluffless_req_put_meta->set_param( 'meta', array( '_wp_note_status' => 'resolved' ) );
	$fluffless_res_put_meta = rest_do_request( $fluffless_req_put_meta );
	$fluffless_assert( 200 === $fluffless_res_put_meta->get_status(), 'note : modification de la méta de résolution seule admise' );

	foreach ( array( 'post', 'parent', 'author', 'author_email' ) as $fluffless_field ) {
		$fluffless_req_put_forbidden = new WP_REST_Request( 'PUT', "/wp/v2/comments/{$fluffless_note_own}" );
		$fluffless_req_put_forbidden->set_param( $fluffless_field, 'post' === $fluffless_field || 'parent' === $fluffless_field ? $fluffless_other : ( 'author' === $fluffless_field ? $fluffless_admin_id : 'x@example.com' ) );
		$fluffless_res_put_forbidden = rest_do_request( $fluffless_req_put_forbidden );
		$fluffless_assert( 403 === $fluffless_res_put_forbidden->get_status(), "note : modification refusée sur le champ {$fluffless_field}" );
	}

	$fluffless_req_put_other = new WP_REST_Request( 'PUT', "/wp/v2/comments/{$fluffless_note_other}" );
	$fluffless_req_put_other->set_param( 'content', 'Tentative de modifier la note d’un autre compte' );
	$fluffless_res_put_other = rest_do_request( $fluffless_req_put_other );
	$fluffless_assert( 403 === $fluffless_res_put_other->get_status(), 'note : modification du contenu de la note d’un autre compte refusée' );

	$fluffless_req_put_status_root = new WP_REST_Request( 'PUT', "/wp/v2/comments/{$fluffless_note_own}" );
	$fluffless_req_put_status_root->set_param( 'status', 'approved' );
	$fluffless_res_put_status_root = rest_do_request( $fluffless_req_put_status_root );
	$fluffless_assert( 200 === $fluffless_res_put_status_root->get_status(), 'note : statut approved sur la note parente admise' );

	$fluffless_req_put_status_child = new WP_REST_Request( 'PUT', "/wp/v2/comments/{$fluffless_note_child}" );
	$fluffless_req_put_status_child->set_param( 'status', 'approved' );
	$fluffless_res_put_status_child = rest_do_request( $fluffless_req_put_status_child );
	$fluffless_assert( 403 === $fluffless_res_put_status_child->get_status(), 'note : statut refusé hors note parente d’un fil' );

	$fluffless_req_put_status_bad = new WP_REST_Request( 'PUT', "/wp/v2/comments/{$fluffless_note_own}" );
	$fluffless_req_put_status_bad->set_param( 'status', 'spam' );
	$fluffless_res_put_status_bad = rest_do_request( $fluffless_req_put_status_bad );
	$fluffless_assert( 403 === $fluffless_res_put_status_bad->get_status(), 'note : valeur de statut hors hold/approved refusée' );

	$fluffless_req_put_public = new WP_REST_Request( 'PUT', "/wp/v2/comments/{$fluffless_comment_pub}" );
	$fluffless_req_put_public->set_param( 'status', 'hold' );
	$fluffless_res_put_public = rest_do_request( $fluffless_req_put_public );
	$fluffless_assert( 403 === $fluffless_res_put_public->get_status(), 'note : modération d’un commentaire public refusée (pas une note)' );

	// --- Notes : suppression -------------------------------------------------------------------

	$fluffless_res_delete_other = rest_do_request( new WP_REST_Request( 'DELETE', "/wp/v2/comments/{$fluffless_note_other}" ) );
	$fluffless_assert( 403 === $fluffless_res_delete_other->get_status(), 'note : suppression de la note d’un autre compte refusée' );

	$fluffless_res_delete_own = rest_do_request( new WP_REST_Request( 'DELETE', "/wp/v2/comments/{$fluffless_note_child}" ) );
	$fluffless_assert( in_array( $fluffless_res_delete_own->get_status(), array( 200, 410 ), true ), 'note : suppression de sa propre note admise' );
	$fluffless_note_child = 0;

	// --- Réponses REST : mot de passe et PII retirés -------------------------------------------

	$fluffless_route_protected = (string) rest_get_route_for_post( $fluffless_protected );
	$fluffless_req_protected   = new WP_REST_Request( 'GET', $fluffless_route_protected );
	$fluffless_req_protected->set_param( 'context', 'edit' );
	$fluffless_res_protected = rest_do_request( $fluffless_req_protected );
	$fluffless_assert( 200 === $fluffless_res_protected->get_status(), 'page protégée confiée : lecture admise' );
	$fluffless_assert( '' === ( $fluffless_res_protected->get_data()['password'] ?? 'non-vide' ), 'page protégée : mot de passe retiré de la réponse' );

	$fluffless_req_get_note_other = new WP_REST_Request( 'GET', "/wp/v2/comments/{$fluffless_note_other}" );
	$fluffless_req_get_note_other->set_param( 'context', 'edit' );
	$fluffless_res_get_note_other = rest_do_request( $fluffless_req_get_note_other );
	$fluffless_assert( 200 === $fluffless_res_get_note_other->get_status(), 'note d’un autre compte : lecture à l’unité admise (page confiée)' );
	$fluffless_assert( '' === ( $fluffless_res_get_note_other->get_data()['author_email'] ?? '' ), 'note d’un autre compte : courriel retiré à l’unité' );

	$fluffless_req_get_note_own_check = new WP_REST_Request( 'GET', "/wp/v2/comments/{$fluffless_note_own}" );
	$fluffless_req_get_note_own_check->set_param( 'context', 'edit' );
	$fluffless_res_get_note_own_check = rest_do_request( $fluffless_req_get_note_own_check );
	$fluffless_assert( '' !== ( $fluffless_res_get_note_own_check->get_data()['author_email'] ?? '' ), 'note propre : champ courriel non forcé à vide' );

	// --- Verrou d'édition, autosauvegarde -------------------------------------------------------

	$fluffless_lock_blocked = Reviewer::block_edit_lock( null, $fluffless_assigned, '_edit_lock', (string) time() );
	$fluffless_assert( true === $fluffless_lock_blocked, '_edit_lock : écriture bloquée pour ce rôle' );
	wp_set_current_user( $fluffless_admin_id );
	$fluffless_lock_admin = Reviewer::block_edit_lock( null, $fluffless_assigned, '_edit_lock', (string) time() );
	$fluffless_assert( null === $fluffless_lock_admin, '_edit_lock : sans effet pour un autre rôle' );
	wp_set_current_user( $fluffless_reviewer_id );

	$fluffless_autosave_settings = Reviewer::neutralize_autosave( array(), null );
	$fluffless_assert( 86400 === ( $fluffless_autosave_settings['autosaveInterval'] ?? 0 ), 'autosauvegarde : intervalle repoussé à 86400s pour ce rôle' );

	// --- XML-RPC et mots de passe d'application -------------------------------------------------

	if ( ! defined( 'XMLRPC_REQUEST' ) ) {
		define( 'XMLRPC_REQUEST', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- nom du cœur WordPress, pas une constante du plugin.
	}
	$fluffless_reviewer_user = get_userdata( $fluffless_reviewer_id );
	$fluffless_xmlrpc_result = Reviewer::block_xmlrpc( $fluffless_reviewer_user, 'x', 'y' );
	$fluffless_assert( is_wp_error( $fluffless_xmlrpc_result ), 'XML-RPC : authentification refusée pour ce rôle' );
	$fluffless_admin_user   = get_userdata( $fluffless_admin_id );
	$fluffless_xmlrpc_admin = Reviewer::block_xmlrpc( $fluffless_admin_user, 'x', 'y' );
	$fluffless_assert( $fluffless_admin_user === $fluffless_xmlrpc_admin, 'XML-RPC : sans effet pour un autre rôle' );

	$fluffless_app_pw_denied = Reviewer::deny_app_passwords( true, $fluffless_reviewer_user );
	$fluffless_assert( false === $fluffless_app_pw_denied, 'mots de passe d’application : refusés pour ce rôle' );
	$fluffless_app_pw_admin = Reviewer::deny_app_passwords( true, $fluffless_admin_user );
	$fluffless_assert( true === $fluffless_app_pw_admin, 'mots de passe d’application : sans effet pour un autre rôle' );

	// --- admin-ajax.php / admin-post.php : décisions pures --------------------------------------

	$fluffless_assert( true === $fluffless_call_private( 'admin_entry_refused', 'admin-post.php', '' ), 'admin-post.php : toujours refusé' );
	$fluffless_assert( true === $fluffless_call_private( 'admin_entry_refused', 'admin-ajax.php', 'action-factice' ), 'admin-ajax.php : action factice refusée' );
	$fluffless_assert( false === $fluffless_call_private( 'admin_entry_refused', 'admin-ajax.php', 'heartbeat' ), 'admin-ajax.php : heartbeat admis' );
	$fluffless_assert( false === $fluffless_call_private( 'admin_entry_refused', 'post.php', '' ), 'admin_entry_refused : n’affecte pas post.php' );

	// --- Redirection des écrans wp-admin : décisions pures --------------------------------------

	foreach ( array( 'post.php', 'profile.php', 'admin-ajax.php', 'admin-post.php' ) as $fluffless_pagenow ) {
		$fluffless_assert( false === $fluffless_call_private( 'admin_screen_needs_redirect', $fluffless_pagenow, '' ), "redirection : jamais sur {$fluffless_pagenow}" );
	}
	$fluffless_assert( false === $fluffless_call_private( 'admin_screen_needs_redirect', 'admin.php', 'fluffless-review' ), 'redirection : jamais sur son propre écran (évite la boucle)' );
	$fluffless_assert( true === $fluffless_call_private( 'admin_screen_needs_redirect', 'admin.php', 'autre-page' ), 'redirection : nécessaire sur un autre écran admin.php' );
	$fluffless_assert( true === $fluffless_call_private( 'admin_screen_needs_redirect', 'index.php', '' ), 'redirection : nécessaire sur le tableau de bord' );

	// --- P3-01 : éditeur de la relecture (confort ; la sûreté reste la liste blanche REST) ------

	wp_set_current_user( $fluffless_reviewer_id );
	Reviewer::hide_inserter_for_reviewer();
	$fluffless_lock_css = implode( '', (array) wp_styles()->get_data( 'fluffless-reviewer-lock', 'after' ) );
	foreach ( array( '.editor-post-publish-button__button', '.editor-post-save-draft', '.editor-post-publish-panel__toggle' ) as $fluffless_save_button ) {
		$fluffless_assert( str_contains( $fluffless_lock_css, $fluffless_save_button ), "relecture : bouton d'enregistrement {$fluffless_save_button} encore visible" );
	}
	$fluffless_assert( wp_script_is( 'fluffless-reviewer-lock', 'enqueued' ), 'relecture : script de saisie refusée non chargé' );
	$fluffless_lock_data = implode( '', (array) wp_scripts()->get_data( 'fluffless-reviewer-lock', 'before' ) );
	$fluffless_assert( str_contains( $fluffless_lock_data, 'window.flufflessReviewerLock=' ) && str_contains( $fluffless_lock_data, '"notice"' ), 'relecture : avis à la deuxième personne absent du script' );
	wp_dequeue_script( 'fluffless-reviewer-lock' );
	wp_dequeue_style( 'fluffless-reviewer-lock' );
	wp_set_current_user( $fluffless_admin_id );
} finally {
	unset( $_GET['action'] );
	$GLOBALS['pagenow'] = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- remise à zéro du contexte simulé plus haut.
	wp_set_current_user( $fluffless_admin_id ?? $fluffless_user_before );
	if ( $fluffless_note_own > 0 ) {
		wp_delete_comment( $fluffless_note_own, true );
	}
	if ( $fluffless_note_other > 0 ) {
		wp_delete_comment( $fluffless_note_other, true );
	}
	if ( $fluffless_note_child > 0 ) {
		wp_delete_comment( $fluffless_note_child, true );
	}
	if ( $fluffless_comment_pub > 0 ) {
		wp_delete_comment( $fluffless_comment_pub, true );
	}
	if ( $fluffless_assigned > 0 ) {
		wp_delete_post( $fluffless_assigned, true );
	}
	if ( $fluffless_other > 0 ) {
		wp_delete_post( $fluffless_other, true );
	}
	if ( $fluffless_protected > 0 ) {
		wp_delete_post( $fluffless_protected, true );
	}
	if ( $fluffless_reviewer_id > 0 ) {
		wp_delete_user( $fluffless_reviewer_id );
	}
	if ( $fluffless_reviewer2_id > 0 ) {
		wp_delete_user( $fluffless_reviewer2_id );
	}
	if ( $fluffless_author_post > 0 ) {
		wp_delete_post( $fluffless_author_post, true );
	}
	if ( $fluffless_author_id > 0 ) {
		wp_delete_user( $fluffless_author_id );
	}
	wp_set_current_user( $fluffless_user_before );
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Réviseur : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Réviseur : {$fluffless_checks} vérifications passées." );
