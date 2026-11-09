<?php
/**
 * Module Réviseur : rôle et capacité pour la deuxième étape du module Révision. Un compte confié
 * peut ouvrir l'éditeur de blocs d'une page qu'on lui a assignée et y laisser des notes natives,
 * sans pouvoir modifier quoi que ce soit d'autre.
 *
 * Refus par défaut, permission par liste blanche, seulement sur les pages confiées : la capacité
 * fluffless_review n'accorde en elle-même aucune écriture ; toute la logique passe par la garde
 * map_meta_cap, par la barrière REST et par la garde fine des notes ci-dessous.
 *
 * WordPress 7.1 porte nativement un type de commentaire « note » (class-wp-rest-comments-controller.php).
 * La lecture d'une note précise (GET /wp/v2/comments/{id}) et l'écriture exigent déjà edit_post /
 * edit_comment sur la publication cible, donc déjà couvertes par la garde map_meta_cap ci-dessous
 * (pages confiées + contexte admis). La lecture en liste (GET /wp/v2/comments?type=note), en revanche,
 * exige sans condition current_user_can('edit_posts') dans get_items_permissions_check dès que
 * type=note est demandé, une capacité primitive que ce rôle n'a jamais, même avec post et
 * context=edit correctement renseignés (vérifié par requêtes directes contre WP 7.1). La garde fine
 * ci-dessous accorde donc cette capacité, le temps strict d'une requête déjà validée (publications
 * confiées uniquement), via un filtre user_has_cap borné. Ce que le cœur ne couvre par ailleurs pas,
 * et que la garde fine ajoute aussi : restreindre la modification à ses propres notes (contenu et méta
 * de résolution seulement), interdire de changer publication, parent ou autrice, limiter le statut à
 * hold/approved et seulement sur la note parente d'un fil, et retirer courriel/IP d'autrui des réponses.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Sanitize;
use WP_Comment;
use WP_Error;
use WP_REST_Posts_Controller;
use WP_REST_Request;
use WP_User;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Le cœur ramène toujours edit_comment à edit_post (aucun cas particulier pour les notes) : la
 * garde ci-dessous, posée sur edit_post/edit_page/read_post, couvre donc déjà la lecture et
 * l'écriture des notes sans capacité additionnelle.
 */
final class Reviewer implements Module {

	/** Rôle créé par le module, retiré à la désinstallation. */
	public const ROLE = 'fluffless_reviewer';

	/** Capacité propre du rôle ; n'accorde par elle-même aucune écriture. */
	public const CAP = 'fluffless_review';

	/** Méta des pages confiées : liste d'ID de comptes Réviseur. */
	public const META_REVIEWERS = '_fluffless_reviewers';

	/** Types de contenu où la méta des pages confiées peut se poser. */
	private const POST_TYPES = array( 'post', 'page' );

	/** Capacités meta gardées : les seules que ce rôle peut voir accorder par cette garde. */
	private const GUARDED_CAPS = array( 'edit_post', 'edit_page', 'read_post' );

	/**
	 * Chemins REST préchargés par l'éditeur de blocs (edit-form-blocks.php), relevés en session 1 :
	 * route (expression régulière) => méthodes admises. Lecture seule ; l'écriture des notes
	 * s'ajoute en session 3 avec sa propre garde fine.
	 */
	private const REST_WHITELIST = array(
		'#^/$#'                                            => array( 'GET' ),
		'#^/wp/v2/types$#'                                 => array( 'GET' ),
		'#^/wp/v2/types/(?:post|page)$#'                   => array( 'GET' ),
		'#^/wp/v2/taxonomies$#'                            => array( 'GET' ),
		'#^/wp/v2/users/me$#'                              => array( 'GET' ),
		'#^/wp/v2/users/\d+$#'                             => array( 'GET' ),
		'#^/wp/v2/(?:posts|pages)$#'                       => array( 'OPTIONS' ),
		'#^/wp/v2/media$#'                                 => array( 'OPTIONS' ),
		'#^/wp/v2/blocks$#'                                => array( 'OPTIONS' ),
		'#^/wp/v2/templates$#'                             => array( 'OPTIONS' ),
		'#^/wp/v2/(?:posts|pages)/\d+$#'                   => array( 'GET' ),
		'#^/wp/v2/(?:posts|pages)/\d+/autosaves$#'         => array( 'GET' ),
		'#^/wp/v2/settings$#'                              => array( 'GET', 'OPTIONS' ),
		'#^/wp/v2/global-styles/themes/[^/]+$#'            => array( 'GET' ),
		'#^/wp/v2/global-styles/themes/[^/]+/variations$#' => array( 'GET' ),
		'#^/wp/v2/themes$#'                                => array( 'GET' ),
		'#^/wp/v2/global-styles/\d+$#'                     => array( 'GET', 'OPTIONS' ),
		'#^/wp/v2/block-patterns/categories$#'             => array( 'GET' ),
		'#^/wp/v2/templates/lookup$#'                      => array( 'GET' ),
		'#^/wp/v2/comments$#'                              => array( 'GET', 'POST' ),
		'#^/wp/v2/comments/\d+$#'                          => array( 'GET', 'PUT', 'PATCH', 'DELETE' ),
	);

	/**
	 * Interval d'autosauvegarde (secondes) imposé pour ce rôle : 24 h, bien en deçà du plafond
	 * 32 bits de setTimeout (~24,8 jours). Confort seulement : la sûreté vient de la liste blanche
	 * REST ci-dessus, qui n'admet pas l'écriture des autosaves (POST) pour ce rôle.
	 */
	private const AUTOSAVE_DISABLED_INTERVAL = 86400;

	/** Champs qu'une modification de note peut porter sans toucher au statut. */
	private const NOTE_EDITABLE_FIELDS = array( 'content', 'meta' );

	/** Valeurs de statut admises sur la note parente d'un fil, au sens strict du design. */
	private const NOTE_ALLOWED_STATUSES = array( 'hold', 'approved' );

	/**
	 * Vrai entre rest_request_before_callbacks et rest_request_after_callbacks, jamais lu ailleurs.
	 *
	 * @var bool
	 */
	private static bool $rest_ok = false;

	/**
	 * Vrai seulement le temps d'une liste de notes déjà validée par guard_comment_list() (voir
	 * grant_edit_posts_for_note_list() ci-dessous) ; jamais lu ailleurs.
	 *
	 * @var bool
	 */
	private static bool $note_list_ok = false;

	/**
	 * Nom du type de publication (post|page) le temps d'une lecture de
	 * /wp/v2/types/{ce type}?context=edit déjà validée par guard_types_route() (voir
	 * grant_edit_posts_for_types_context() ci-dessous) ; null hors de cette fenêtre. N'accorde
	 * jamais la capacité d'un autre type que celui demandé par la route en cours.
	 *
	 * @var string|null
	 */
	private static ?string $types_edit_context = null;

	/** {@inheritDoc} */
	public static function id(): string {
		return 'reviewer';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Reviewer', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'A Reviewer role that can leave notes on the pages it is assigned to, and change nothing else: no other capability, no route outside a strict allow list.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array();
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
		return array();
	}

	/** Comptes au rôle Réviseur. */
	public static function count_accounts(): int {
		return count(
			get_users(
				array(
					'role'   => self::ROLE,
					'fields' => 'ID',
					'number' => 1,
				)
			)
		);
	}

	/** État affiché dans les réglages : sans compte, le rôle n'a personne à qui servir. */
	public static function status(): string {
		if ( self::count_accounts() > 0 ) {
			return '';
		}
		return __( 'No Reviewer account yet. To add one: 1) go to Users → Add User, 2) fill in the name and email, choose the Reviewer role and save, 3) assign pages to it, in Fluffless → Review or in the Reviewers panel of the page editor. The person then logs in and leaves notes on those pages only.', 'fluffless-suite' );
	}

	/**
	 * Bouton sous l'état : ajouter un compte (le rôle se choisit dans le formulaire de WordPress).
	 *
	 * @return array{text: string, url: string}|null
	 */
	public static function status_action(): ?array {
		if ( ! current_user_can( 'create_users' ) ) {
			return null;
		}
		return array(
			'text' => __( 'Add a Reviewer account', 'fluffless-suite' ),
			'url'  => admin_url( 'user-new.php' ),
		);
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'init', array( self::class, 'ensure_role' ) );
		add_action( 'init', array( self::class, 'register_meta' ) );
		add_action( 'init', array( self::class, 'refuse_admin_ajax_and_post' ) );
		add_action( 'admin_init', array( self::class, 'guard_admin_screens' ) );
		add_filter( 'map_meta_cap', array( self::class, 'guard' ), 10, 4 );
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest_barrier' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'mark_rest_context' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'ignore_meta_without_right' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'guard_comments_route' ), 15, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'guard_types_route' ), 15, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'clear_rest_context' ), 10, 3 );
		add_filter( 'user_has_cap', array( self::class, 'grant_edit_posts_for_note_list' ), 10, 4 );
		add_filter( 'user_has_cap', array( self::class, 'grant_edit_posts_for_types_context' ), 10, 4 );
		foreach ( self::POST_TYPES as $post_type ) {
			add_filter( "rest_prepare_{$post_type}", array( self::class, 'strip_password' ), 10, 3 );
		}
		add_filter( 'rest_prepare_comment', array( self::class, 'strip_comment_pii' ), 10, 3 );
		add_filter( 'update_post_metadata', array( self::class, 'block_edit_lock' ), 10, 5 );
		add_filter( 'block_editor_settings_all', array( self::class, 'neutralize_autosave' ), 10, 2 );
		add_filter( 'block_editor_settings_all', array( self::class, 'lock_template' ), 10, 2 );
		add_filter( 'authenticate', array( self::class, 'block_xmlrpc' ), 40, 3 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( self::class, 'deny_app_passwords' ), 10, 2 );
		add_filter( 'woocommerce_prevent_admin_access', array( self::class, 'allow_woocommerce_admin_access' ), 10 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'hide_inserter_for_reviewer' ) );
	}

	/** Rôle sans capacité d'écriture ; add_role() ne fait rien si le rôle existe déjà. Sur init : le nom traduit ne doit pas se résoudre avant le chargement du domaine de traduction. */
	public static function ensure_role(): void {
		add_role(
			self::ROLE,
			__( 'Reviewer', 'fluffless-suite' ),
			array(
				'read'    => true,
				self::CAP => true,
			)
		);
	}

	/**
	 * Retire la liste des réviseurs des écritures REST de contenus pour qui n'a pas edit_others_posts :
	 * l'écriture est ignorée, jamais refusée. L'éditeur renvoie tout l'objet meta à chaque
	 * enregistrement, liste comprise, même vide ou périmée (confiée entre-temps depuis l'écran
	 * Révision) ; WordPress refuserait alors en 403 l'enregistrement d'un compte Auteur ou
	 * Contributeur, après avoir déjà écrit le contenu. Le panneau n'est offert qu'avec ce droit.
	 *
	 * @param mixed           $response Réponse ou erreur déjà décidée, rendue telle quelle.
	 * @param mixed           $handler  Gestionnaire de la route.
	 * @param WP_REST_Request $request  Requête.
	 * @return mixed
	 */
	public static function ignore_meta_without_right( $response, $handler, WP_REST_Request $request ) {
		$controller = is_array( $handler ) && is_array( $handler['callback'] ?? null ) ? ( $handler['callback'][0] ?? null ) : null;
		if ( 'GET' === $request->get_method() || ! $controller instanceof WP_REST_Posts_Controller || current_user_can( 'edit_others_posts' ) ) {
			return $response;
		}
		$meta = $request->get_param( 'meta' );
		if ( is_array( $meta ) && array_key_exists( self::META_REVIEWERS, $meta ) ) {
			unset( $meta[ self::META_REVIEWERS ] );
			$request->set_param( 'meta', $meta );
		}
		return $response;
	}

	/**
	 * Méta des pages confiées : jamais ouverte par edit_post (ce serait la capacité même que la
	 * garde restreint), toujours par edit_others_posts.
	 */
	public static function register_meta(): void {
		$args = array(
			'type'              => 'array',
			'single'            => true,
			'default'           => array(),
			'label'             => __( 'Reviewers assigned to this content', 'fluffless-suite' ),
			'description'       => __( 'User IDs allowed to leave notes here as a Reviewer.', 'fluffless-suite' ),
			'show_in_rest'      => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
			'sanitize_callback' => static function ( $value ): array {
				return is_array( $value ) ? array_values( array_unique( array_map( 'absint', $value ) ) ) : array();
			},
			'auth_callback'     => static function (): bool {
				return current_user_can( 'edit_others_posts' );
			},
		);
		foreach ( self::POST_TYPES as $post_type ) {
			register_post_meta( $post_type, self::META_REVIEWERS, $args );
		}
	}

	/**
	 * Le contenu a-t-il été confié à ce compte ?
	 *
	 * @param int $post_id Contenu.
	 * @param int $user_id Compte.
	 */
	private static function is_assigned( int $post_id, int $user_id ): bool {
		$reviewers = get_post_meta( $post_id, self::META_REVIEWERS, true );
		return is_array( $reviewers ) && in_array( $user_id, array_map( 'absint', $reviewers ), true );
	}

	/**
	 * Lecture d'affichage seulement, jamais une garde de sécurité : le contenu est-il confié à ce
	 * compte ? Enveloppe publique et documentée de is_assigned() ci-dessus, pour un module tiers
	 * (Review) qui doit décider si un lien vers l'éditeur a du sens hors du contexte admis par
	 * guard() (l'écran Révision, par exemple), jamais pour autoriser quoi que ce soit par elle-même.
	 *
	 * @param int $post_id Contenu.
	 * @param int $user_id Compte.
	 */
	public static function is_assigned_for_display( int $post_id, int $user_id ): bool {
		return self::is_assigned( $post_id, $user_id );
	}

	/**
	 * Contenus confiés à ce compte, tous les types de POST_TYPES et tous statuts confondus (un
	 * brouillon confié doit rester trouvable). Lecture seule, utilisée pour scoper l'écran Révision
	 * à ce compte ; n'accorde et ne vérifie aucune capacité par elle-même.
	 *
	 * @param int $user_id Compte.
	 * @return list<int>
	 */
	public static function assigned_post_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPES,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- liste blanche de comptes assez petite pour rester rapide ; aucune autre voie n'existe sur une méta sérialisée.
					array(
						'key'     => self::META_REVIEWERS,
						'value'   => 'i:' . $user_id . ';',
						'compare' => 'LIKE',
					),
				),
			)
		);
		return array_values( array_map( 'absint', $ids ) );
	}

	/**
	 * Comptes Réviseur disponibles pour le champ « Réviseurs », triés par nom affiché. Source
	 * commune au panneau du document (assets/reviewer/editor.js) et à l'écran Révision.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	public static function choices(): array {
		$users = get_users(
			array(
				'role'    => self::ROLE,
				'orderby' => 'display_name',
				'fields'  => array( 'ID', 'display_name' ),
			)
		);
		return array_values(
			array_map(
				static fn( $user ): array => array(
					'id'   => (int) $user->ID,
					'name' => (string) $user->display_name,
				),
				$users
			)
		);
	}

	/**
	 * Contexte admis : requête REST déjà passée par la barrière ci-dessous, ou chargement de
	 * l'éditeur classique en lecture (GET post.php?action=edit). Jamais lu dans $_SERVER pour le
	 * contexte REST : cet état est posé et remis à zéro par les deux crochets rest_request_*.
	 */
	private static function in_whitelisted_context(): bool {
		if ( self::$rest_ok ) {
			return true;
		}
		$pagenow = is_string( $GLOBALS['pagenow'] ?? null ) ? $GLOBALS['pagenow'] : '';
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture du contexte seulement, aucune écriture ici.
		return 'post.php' === $pagenow && 'GET' === $method && 'edit' === $action;
	}

	/**
	 * Garde unique map_meta_cap pour ce rôle : pages confiées et contexte admis, sinon refus. Ne
	 * touche à rien pour les comptes sans la capacité fluffless_review.
	 *
	 * @param array<int, string> $caps    Capacités primitives déjà résolues par le cœur.
	 * @param string             $cap     Capacité meta demandée.
	 * @param int                $user_id Compte qui demande.
	 * @param array<mixed>       $args    Arguments de la capacité meta ; [0] est l'ID du contenu ici.
	 * @return array<int, string>
	 */
	public static function guard( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, self::GUARDED_CAPS, true ) || ! user_can( $user_id, self::CAP ) ) {
			return $caps;
		}
		$post_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( $post_id > 0 && self::is_assigned( $post_id, $user_id ) && self::in_whitelisted_context() ) {
			return array();
		}
		return array( 'do_not_allow' );
	}

	/**
	 * Barrière REST : pour ce rôle seulement, tout ce qui n'est pas dans REST_WHITELIST répond 403,
	 * routes tierces comprises. Double sûreté avec la garde map_meta_cap ci-dessus.
	 *
	 * @param mixed           $result  Résultat déjà posé par un filtre précédent, ou null.
	 * @param mixed           $server  Serveur REST (\WP_REST_Server), non utilisé ici.
	 * @param WP_REST_Request $request Requête.
	 * @return mixed
	 */
	public static function rest_barrier( $result, $server, WP_REST_Request $request ) {
		if ( null !== $result || ! current_user_can( self::CAP ) ) {
			return $result;
		}
		if ( self::route_whitelisted( $request->get_route(), $request->get_method() ) ) {
			return $result;
		}
		return new WP_Error(
			'fluffless_reviewer_forbidden',
			__( 'Your review account can read the pages entrusted to you and add notes, but it cannot change them.', 'fluffless-suite' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Le chemin et la méthode font partie de REST_WHITELIST ?
	 *
	 * @param string $route  Chemin de la requête (sans le domaine ni la chaîne de requête).
	 * @param string $method Méthode HTTP.
	 */
	private static function route_whitelisted( string $route, string $method ): bool {
		foreach ( self::REST_WHITELIST as $pattern => $methods ) {
			if ( in_array( $method, $methods, true ) && 1 === preg_match( $pattern, $route ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pose l'état de contexte REST juste avant l'appel du contrôleur, donc avant son
	 * permission_callback (qui invoque map_meta_cap) : la garde ci-dessus peut donc le lire.
	 *
	 * @param mixed           $response Réponse déjà posée, ou null.
	 * @param array<mixed>    $handler  Gestionnaire de la route.
	 * @param WP_REST_Request $request  Requête.
	 * @return mixed
	 */
	public static function mark_rest_context( $response, $handler, WP_REST_Request $request ) {
		self::$rest_ok = true;
		return $response;
	}

	/**
	 * Remet l'état à zéro juste après l'appel du contrôleur ; jamais laissé posé entre deux
	 * requêtes REST internes (rest_do_request), d'où l'exigence des tests.
	 *
	 * @param mixed           $response Réponse du contrôleur.
	 * @param array<mixed>    $handler  Gestionnaire de la route.
	 * @param WP_REST_Request $request  Requête.
	 * @return mixed
	 */
	public static function clear_rest_context( $response, $handler, WP_REST_Request $request ) {
		self::$rest_ok            = false;
		self::$note_list_ok       = false;
		self::$types_edit_context = null;
		return $response;
	}

	/**
	 * Garde fine de /wp/v2/types/{post|page} pour ce rôle : déjà sur liste blanche ci-dessus
	 * (préchargée par l'éditeur de blocs), mais avec context=edit le cœur exige edit_posts sans
	 * condition, sans rapport avec une publication précise (WP_REST_Post_Types_Controller::get_item(),
	 * vérifié par requêtes directes contre WP 7.1), capacité que ce rôle n'a jamais. Sans ce
	 * contournement, la réponse ne porte pas le champ supports, et l'éditeur de blocs ne montre jamais
	 * le panneau Notes (PostTypeSupportCheck côté client cherche editor.notes dans ce champ). Le champ
	 * demandé ne décrit que le type lui-même (étiquettes, supports, capacités génériques), jamais le
	 * contenu d'une publication : accorder edit_posts le temps strict de cet appel n'ouvre rien de
	 * plus que ce que la liste blanche admettait déjà en principe pour cette route.
	 *
	 * @param mixed           $response Réponse déjà posée par un filtre précédent, ou null.
	 * @param array<mixed>    $handler  Gestionnaire de la route.
	 * @param WP_REST_Request $request  Requête.
	 * @return mixed
	 */
	public static function guard_types_route( $response, $handler, WP_REST_Request $request ) {
		if ( null !== $response || ! current_user_can( self::CAP ) ) {
			return $response;
		}
		if ( 1 !== preg_match( '#^/wp/v2/types/(post|page)$#', $request->get_route(), $matches )
			|| 'GET' !== $request->get_method()
			|| 'edit' !== $request->get_param( 'context' )
		) {
			return $response;
		}
		self::$types_edit_context = $matches[1];
		return $response;
	}

	/**
	 * Accorde le temps strict d'une lecture de /wp/v2/types/{post|page}?context=edit déjà validée par
	 * guard_types_route() ci-dessus la capacité primitive réelle du seul type capturé par cette
	 * garde : get_item() du cœur teste $obj->cap->edit_posts, qui vaut edit_pages pour le type page
	 * (capability_type distinct), pas edit_posts littéralement. N'accorde jamais la capacité d'un
	 * autre type que celui de la route en cours, même si une autre vérification tourne pendant la
	 * même fenêtre de requête. Ne touche à aucune autre capacité ni aucun autre compte ; remis à
	 * zéro par clear_rest_context() dès la fin de la requête.
	 *
	 * @param array<string, bool> $allcaps Capacités déjà résolues pour ce compte.
	 * @param array<int, string>  $caps    Capacités primitives demandées.
	 * @param array<mixed>        $args    Arguments de la capacité, non utilisés ici.
	 * @param mixed               $user    Compte concerné, non utilisé ici.
	 * @return array<string, bool>
	 */
	public static function grant_edit_posts_for_types_context( array $allcaps, array $caps, array $args, $user ): array {
		if ( null === self::$types_edit_context ) {
			return $allcaps;
		}
		$post_type_object = get_post_type_object( self::$types_edit_context );
		if ( $post_type_object && in_array( $post_type_object->cap->edit_posts, $caps, true ) ) {
			$allcaps[ $post_type_object->cap->edit_posts ] = true;
		}
		return $allcaps;
	}

	/**
	 * Garde fine de /wp/v2/comments pour ce rôle : la lecture d'une note précise (GET par ID) est
	 * déjà correctement bornée par le cœur (get_item_permissions_check, via edit_comment/edit_post),
	 * donc laissée passer telle quelle. La lecture en liste délègue à guard_comment_list(), la
	 * création à guard_comment_create(), la modification/suppression à guard_comment_write(). Tout
	 * le reste sur cette route (méthode non listée) est refusé.
	 *
	 * @param mixed           $response Réponse déjà posée par un filtre précédent, ou null.
	 * @param array<mixed>    $handler  Gestionnaire de la route.
	 * @param WP_REST_Request $request  Requête.
	 * @return mixed
	 */
	public static function guard_comments_route( $response, $handler, WP_REST_Request $request ) {
		if ( null !== $response || ! current_user_can( self::CAP ) ) {
			return $response;
		}
		if ( 1 !== preg_match( '#^/wp/v2/comments(?:/(\d+))?$#', $request->get_route(), $matches ) ) {
			return $response;
		}
		$method = $request->get_method();
		if ( in_array( $method, array( 'HEAD', 'OPTIONS' ), true ) ) {
			return $response;
		}
		$user_id = get_current_user_id();
		if ( 'GET' === $method ) {
			return isset( $matches[1] ) ? $response : self::guard_comment_list( $request, $user_id );
		}
		if ( 'POST' === $method && ! isset( $matches[1] ) ) {
			return self::guard_comment_create( $request, $user_id );
		}
		if ( isset( $matches[1] ) && in_array( $method, array( 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return self::guard_comment_write( $request, $method, (int) $matches[1], $user_id );
		}
		return self::forbidden();
	}

	/**
	 * Liste des notes (GET /wp/v2/comments, sans ID) : le cœur ferme sans condition ce point
	 * d'entrée aux comptes sans edit_posts dès que type=note est demandé
	 * (get_items_permissions_check), capacité que ce rôle n'a jamais, même avec post et
	 * context=edit correctement renseignés (vérifié par requêtes directes contre WP 7.1). Seul
	 * contournement propre : valider ici que chaque publication demandée est confiée à ce compte,
	 * puis accorder edit_posts le temps strict de cette requête (grant_edit_posts_for_note_list()
	 * ci-dessous). Une liste sans publication précise reste refusée : elle ouvrirait sur tout le
	 * site. Un type autre que note est laissé au cœur, déjà correctement restrictif dans ce cas.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @param int             $user_id Compte qui demande.
	 * @return mixed
	 */
	private static function guard_comment_list( WP_REST_Request $request, int $user_id ) {
		if ( 'note' !== $request->get_param( 'type' ) ) {
			return null;
		}
		$posts = (array) ( $request->get_param( 'post' ) ?? array() );
		if ( array() === $posts ) {
			return self::forbidden();
		}
		foreach ( $posts as $post_id ) {
			if ( ! self::is_assigned( absint( $post_id ), $user_id ) ) {
				return self::forbidden();
			}
		}
		self::$note_list_ok = true;
		return null;
	}

	/**
	 * Accorde edit_posts le temps strict d'une liste de notes déjà validée par
	 * guard_comment_list() ci-dessus (publications confiées uniquement) : le cœur exige cette
	 * capacité primitive sans condition dès que type=note est demandé, quel que soit le filtrage
	 * par publication déjà en place. Ne touche à aucune autre capacité ni aucun autre compte ;
	 * remis à zéro par clear_rest_context() dès la fin de la requête.
	 *
	 * @param array<string, bool> $allcaps Capacités déjà résolues pour ce compte.
	 * @param array<int, string>  $caps    Capacités primitives demandées.
	 * @param array<mixed>        $args    Arguments de la capacité, non utilisés ici.
	 * @param mixed               $user    Compte concerné, non utilisé ici.
	 * @return array<string, bool>
	 */
	public static function grant_edit_posts_for_note_list( array $allcaps, array $caps, array $args, $user ): array {
		if ( self::$note_list_ok && in_array( 'edit_posts', $caps, true ) ) {
			$allcaps['edit_posts'] = true;
		}
		return $allcaps;
	}

	/**
	 * Création d'une note : seul le type note est admis, l'autrice ne peut être que le compte
	 * courant, la méta ne peut porter que _wp_note_status (résolution/réouverture, seule méta que
	 * le client envoie à la création), le statut ne peut être que hold/approved, et une réponse à
	 * un fil doit rester sur une note du même fil et de la même publication. Le statut est vérifié
	 * ici en défense en profondeur : create_item_permissions_check du cœur ne fait que vérifier que
	 * le compte a le droit de le poser du tout (edit_post sur la publication, déjà couvert par
	 * guard() ci-dessus), jamais quelle valeur. Le reste (edit_post lui-même, entre autres) reste au
	 * cœur : retourner null laisse le cœur trancher.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @param int             $user_id Compte qui demande.
	 * @return mixed
	 */
	private static function guard_comment_create( WP_REST_Request $request, int $user_id ) {
		if ( 'note' !== $request->get_param( 'type' ) ) {
			return self::forbidden();
		}
		$author = $request->get_param( 'author' );
		if ( null !== $author && absint( $author ) !== $user_id ) {
			return self::forbidden();
		}
		$status = $request->get_param( 'status' );
		if ( null !== $status && ! in_array( $status, self::NOTE_ALLOWED_STATUSES, true ) ) {
			return self::forbidden();
		}
		$meta = $request->get_param( 'meta' );
		if ( null !== $meta && ( ! is_array( $meta ) || array( '_wp_note_status' ) !== array_keys( $meta ) ) ) {
			return self::forbidden();
		}
		$parent_id = absint( $request->get_param( 'parent' ) ?? 0 );
		if ( $parent_id > 0 ) {
			$parent = get_comment( $parent_id );
			if ( ! $parent instanceof WP_Comment || 'note' !== $parent->comment_type ) {
				return self::forbidden();
			}
			$post_param = absint( $request->get_param( 'post' ) ?? 0 );
			if ( absint( $parent->comment_post_ID ) !== $post_param ) {
				return self::forbidden();
			}
		}
		return null;
	}

	/**
	 * Modification ou suppression d'une note existante : toujours restreint à ses propres notes.
	 * Suppression : uniquement sa propre note. Modification : soit un changement de statut seul
	 * (hold/approved uniquement, et seulement sur la note parente d'un fil), soit un changement de
	 * contenu et/ou de méta de résolution (_wp_note_status) sur sa propre note ; jamais de
	 * publication, de parent ou d'autrice.
	 *
	 * @param WP_REST_Request $request    Requête.
	 * @param string          $method     Méthode HTTP (PUT, PATCH ou DELETE).
	 * @param int             $comment_id Identifiant de la note visée.
	 * @param int             $user_id    Compte qui demande.
	 * @return mixed
	 */
	private static function guard_comment_write( WP_REST_Request $request, string $method, int $comment_id, int $user_id ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof WP_Comment || 'note' !== $comment->comment_type ) {
			return self::forbidden();
		}
		$is_own = absint( $comment->user_id ) === $user_id;

		if ( 'DELETE' === $method ) {
			return $is_own ? null : self::forbidden();
		}

		$params = $request->get_params();
		unset( $params['id'], $params['context'], $params['_locale'] );
		$fields = array_keys( $params );

		foreach ( array( 'post', 'parent', 'author', 'author_name', 'author_email', 'author_url', 'author_ip', 'author_user_agent', 'author_avatar_urls', 'date', 'date_gmt' ) as $forbidden_field ) {
			if ( in_array( $forbidden_field, $fields, true ) ) {
				return self::forbidden();
			}
		}

		if ( array( 'status' ) === $fields ) {
			$is_thread_root = 0 === absint( $comment->comment_parent );
			$status         = $request->get_param( 'status' );
			if ( $is_thread_root && in_array( $status, self::NOTE_ALLOWED_STATUSES, true ) ) {
				return null;
			}
			return self::forbidden();
		}

		if ( ! $is_own || array() === $fields || in_array( 'status', $fields, true ) ) {
			return self::forbidden();
		}
		foreach ( $fields as $field ) {
			if ( ! in_array( $field, self::NOTE_EDITABLE_FIELDS, true ) ) {
				return self::forbidden();
			}
		}
		$meta = $request->get_param( 'meta' );
		if ( null !== $meta && ( ! is_array( $meta ) || array( '_wp_note_status' ) !== array_keys( $meta ) ) ) {
			return self::forbidden();
		}
		return null;
	}

	/** Réponse 403 uniforme pour la garde fine des notes. */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'fluffless_reviewer_forbidden',
			__( 'This account cannot make this change.', 'fluffless-suite' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Retire le mot de passe de la réponse REST des publications, pour ce rôle. Le cœur l'expose
	 * sans condition en context=edit (class-wp-rest-posts-controller.php) ; vidé plutôt que retiré,
	 * pour garder le champ présent et conforme au schéma attendu par l'éditeur.
	 *
	 * @param mixed $response Réponse du contrôleur (WP_REST_Response).
	 * @param mixed $post     Publication (WP_Post), non utilisée ici.
	 * @param mixed $request  Requête, non utilisée ici.
	 * @return mixed
	 */
	public static function strip_password( $response, $post, $request ) {
		if ( current_user_can( self::CAP ) && is_object( $response ) && isset( $response->data['password'] ) ) {
			$response->data['password'] = '';
		}
		return $response;
	}

	/**
	 * Retire courriel, IP et agent utilisateur des notes d'autrui de la réponse REST, pour ce rôle.
	 * Le cœur les expose sans condition en context=edit (class-wp-rest-comments-controller.php) ;
	 * ses propres notes gardent ces champs, vidés plutôt que retirés pour rester conformes au schéma.
	 *
	 * @param mixed $response Réponse du contrôleur (WP_REST_Response).
	 * @param mixed $comment  Commentaire (WP_Comment), non utilisé ici.
	 * @param mixed $request  Requête, non utilisée ici.
	 * @return mixed
	 */
	public static function strip_comment_pii( $response, $comment, $request ) {
		if ( ! current_user_can( self::CAP ) || ! is_object( $response ) ) {
			return $response;
		}
		if ( $comment instanceof WP_Comment && absint( $comment->user_id ) === get_current_user_id() ) {
			return $response;
		}
		foreach ( array( 'author_email', 'author_ip', 'author_user_agent' ) as $field ) {
			if ( isset( $response->data[ $field ] ) ) {
				$response->data[ $field ] = '';
			}
		}
		return $response;
	}

	/**
	 * Bloque l'écriture de _edit_lock pour ce rôle : ni wp_set_post_lock() (post.php, déjà en
	 * lecture seule cette session) ni un rafraîchissement de verrou par heartbeat ne peuvent le
	 * poser. Retourner une valeur non nulle court-circuite l'écriture (update_metadata()).
	 *
	 * @param mixed  $check      Valeur qui court-circuite l'écriture si non nulle.
	 * @param int    $object_id  Publication visée, non utilisée ici (la garde porte sur le compte agissant).
	 * @param string $meta_key   Clé de méta.
	 * @param mixed  $meta_value Valeur, non utilisée ici.
	 * @param mixed  $prev_value Valeur précédente, non utilisée ici.
	 * @return mixed
	 */
	public static function block_edit_lock( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		if ( '_edit_lock' === $meta_key && current_user_can( self::CAP ) ) {
			return true;
		}
		return $check;
	}

	/**
	 * Confort seulement (voir AUTOSAVE_DISABLED_INTERVAL) : repousse l'intervalle d'autosauvegarde
	 * de l'éditeur de blocs pour ce rôle, la vraie sûreté venant de la liste blanche REST qui refuse
	 * déjà l'écriture des autosaves.
	 *
	 * @param array<mixed> $editor_settings Réglages transmis à l'éditeur de blocs.
	 * @param mixed        $block_editor_context Contexte de l'éditeur, non utilisé ici.
	 * @return array<mixed>
	 */
	public static function neutralize_autosave( array $editor_settings, $block_editor_context ): array {
		if ( current_user_can( self::CAP ) ) {
			$editor_settings['autosaveInterval'] = self::AUTOSAVE_DISABLED_INTERVAL;
		}
		return $editor_settings;
	}

	/**
	 * Confort seulement (voir le point 7 du design) : verrouille la structure de blocs pour ce
	 * rôle (ajout, suppression et déplacement de blocs empêchés dans l'interface). La sûreté ne
	 * repose jamais là-dessus : la liste blanche REST refuse de toute façon l'écriture du contenu.
	 *
	 * @param array<mixed> $editor_settings Réglages transmis à l'éditeur de blocs.
	 * @param mixed        $block_editor_context Contexte de l'éditeur, non utilisé ici.
	 * @return array<mixed>
	 */
	public static function lock_template( array $editor_settings, $block_editor_context ): array {
		if ( current_user_can( self::CAP ) ) {
			$editor_settings['templateLock'] = 'all';
		}
		return $editor_settings;
	}

	/**
	 * Confort seulement, complément visuel de lock_template() : masque pour ce rôle le bouton
	 * d'insertion de bloc et les boutons d'enregistrement (la liste blanche REST les refuserait),
	 * puis charge assets/reviewer/lock.js, qui refuse la saisie dans le canevas et affiche un avis
	 * à la deuxième personne (P3-01). Le texte reste sélectionnable pour y poser une note.
	 */
	public static function hide_inserter_for_reviewer(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		wp_register_style( 'fluffless-reviewer-lock', false, array(), VERSION );
		wp_enqueue_style( 'fluffless-reviewer-lock' );
		wp_add_inline_style(
			'fluffless-reviewer-lock',
			'.editor-document-tools__inserter-toggle,.edit-post-header-toolbar__inserter-toggle,'
			. '.editor-post-publish-button__button,.editor-post-publish-panel__toggle,.editor-post-save-draft,'
			. '.editor-post-switch-to-draft,.editor-post-saved-state{display:none!important}'
		);
		wp_enqueue_script(
			'fluffless-reviewer-lock',
			plugins_url( 'assets/reviewer/lock.js', FILE ),
			array( 'wp-data', 'wp-dom-ready', 'wp-notices' ),
			VERSION,
			true
		);
		$data = wp_json_encode(
			array(
				'notice' => __( 'You are reviewing this page: your notes save on their own, and the text cannot be changed from this account.', 'fluffless-suite' ),
			)
		);
		if ( false !== $data ) {
			wp_add_inline_script( 'fluffless-reviewer-lock', 'window.flufflessReviewerLock=' . $data . ';', 'before' );
		}
	}

	/**
	 * Panneau du document « Réviseurs » (assets/reviewer/editor.js) : liste des comptes Réviseur à
	 * cocher pour cette page ou cet article. Visible seulement pour edit_others_posts, jamais pour
	 * ce rôle lui-même (voir le point 2 du design).
	 */
	public static function editor_assets(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->post_type, self::POST_TYPES, true ) ) {
			return;
		}
		wp_enqueue_script(
			'fluffless-reviewer-editor',
			plugins_url( 'assets/reviewer/editor.js', FILE ),
			array( 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			VERSION,
			true
		);
		wp_set_script_translations( 'fluffless-reviewer-editor', 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
		$data = wp_json_encode(
			array(
				'metaKey'   => self::META_REVIEWERS,
				'reviewers' => self::choices(),
			)
		);
		if ( false !== $data ) {
			wp_add_inline_script( 'fluffless-reviewer-editor', 'window.flufflessReviewer=' . $data . ';', 'before' );
		}
	}

	/**
	 * Ferme XML-RPC pour ce rôle, quel que soit le mécanisme d'authentification employé (mot de
	 * passe ou mot de passe d'application) : à la priorité 40, les trois filtres natifs qui
	 * résolvent les identifiants (priorité 20) et l'authentification par cookie (priorité 30) ont
	 * déjà tranché, donc $user est soit un WP_User déjà résolu, soit une erreur qu'on laisse passer.
	 *
	 * @param mixed  $user     Compte déjà résolu par un filtre précédent, ou WP_Error/null.
	 * @param string $username Nom d'utilisateur soumis, non utilisé ici.
	 * @param string $password Mot de passe soumis, non utilisé ici.
	 * @return mixed
	 */
	public static function block_xmlrpc( $user, string $username, string $password ) {
		if ( $user instanceof WP_User && defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && user_can( $user, self::CAP ) ) { // @phpstan-ignore booleanAnd.rightAlwaysTrue (stub WordPress : la constante est typée true, sa valeur réelle ne l'est pas toujours)
			return new WP_Error(
				'fluffless_reviewer_forbidden',
				__( 'This account cannot use XML-RPC.', 'fluffless-suite' )
			);
		}
		return $user;
	}

	/**
	 * Refuse les mots de passe d'application pour ce rôle. Referme aussi, comme effet de bord
	 * voulu, la section correspondante de profile.php : son affichage dépend de ce même filtre.
	 *
	 * @param bool  $available Disponibilité déjà établie par le cœur ou un filtre précédent.
	 * @param mixed $user      Compte visé (WP_User ou ID).
	 * @return bool
	 */
	public static function deny_app_passwords( bool $available, $user ): bool {
		if ( ! $available ) {
			return $available;
		}
		$user_obj = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( $user_obj instanceof WP_User && user_can( $user_obj, self::CAP ) ) {
			return false;
		}
		return $available;
	}

	/**
	 * Refus matériel, hors REST : admin-ajax.php n'admet que l'action heartbeat pour ce rôle (dont
	 * le rafraîchissement de verrou est de toute façon neutralisé par block_edit_lock ci-dessus),
	 * admin-post.php est refusé sans exception. Posé sur init, qui s'exécute pour ces deux points
	 * d'entrée comme pour tous les autres. La décision elle-même est pure (admin_entry_refused()
	 * ci-dessous), pour rester testable sans invoquer wp_die() depuis la suite de tests.
	 */
	public static function refuse_admin_ajax_and_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$pagenow = is_string( $GLOBALS['pagenow'] ?? null ) ? $GLOBALS['pagenow'] : '';
		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture du contexte seulement, aucune écriture ici.
		if ( self::admin_entry_refused( $pagenow, $action ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Décision pure derrière refuse_admin_ajax_and_post() ci-dessus : admin-post.php toujours
	 * refusé, admin-ajax.php refusé sauf l'action heartbeat, tout autre point d'entrée admis (pas
	 * son affaire).
	 *
	 * @param string $pagenow Fichier d'entrée courant ($GLOBALS['pagenow']).
	 * @param string $action  Action demandée ($_REQUEST['action'], déjà nettoyée).
	 */
	private static function admin_entry_refused( string $pagenow, string $action ): bool {
		if ( 'admin-post.php' === $pagenow ) {
			return true;
		}
		return 'admin-ajax.php' === $pagenow && 'heartbeat' !== $action;
	}

	/**
	 * Redirige tout écran wp-admin autre que post.php, profile.php et l'écran d'accueil de révision
	 * vers ce dernier, pour ce rôle. admin_init s'exécute aussi pour admin-ajax.php et
	 * admin-post.php (vérifié en session 3, contrairement à l'hypothèse initiale) : ces deux points
	 * d'entrée sont exclus ici puisqu'ils ont déjà leur propre refus matériel ci-dessus. La décision
	 * elle-même est pure (admin_screen_needs_redirect() ci-dessous), pour rester testable sans
	 * invoquer exit depuis la suite de tests.
	 *
	 * Note pour la session 4 (Interface) : Review::render() exige encore edit_others_posts, que ce
	 * rôle n'aura jamais ; la redirection ci-dessous mène donc pour l'instant vers un écran vide
	 * pour un compte Réviseur, en attendant que la session 4 ouvre cet écran à ce rôle.
	 */
	public static function guard_admin_screens(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$pagenow = is_string( $GLOBALS['pagenow'] ?? null ) ? $GLOBALS['pagenow'] : '';
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture du contexte seulement, aucune écriture ici.
		if ( self::admin_screen_needs_redirect( $pagenow, $page ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . Review::PAGE ) );
			exit;
		}
	}

	/**
	 * Décision pure derrière guard_admin_screens() ci-dessus : redirection nécessaire sauf sur
	 * post.php, profile.php, admin-ajax.php, admin-post.php (déjà couverts ailleurs), ou l'écran
	 * d'accueil de révision lui-même (éviterait une boucle de redirection).
	 *
	 * @param string $pagenow Fichier d'entrée courant ($GLOBALS['pagenow']).
	 * @param string $page    Paramètre page de la requête ($_GET['page'], déjà nettoyé).
	 */
	private static function admin_screen_needs_redirect( string $pagenow, string $page ): bool {
		if ( in_array( $pagenow, array( 'admin-ajax.php', 'admin-post.php', 'post.php', 'profile.php' ), true ) ) {
			return false;
		}
		return ! ( 'admin.php' === $pagenow && Review::PAGE === $page );
	}

	/**
	 * WooCommerce (si actif) redirige d'office vers /mon-compte/ tout utilisateur sans
	 * edit_posts, manage_woocommerce ni view_admin_dashboard : ce rôle n'a par conception
	 * aucune de ces trois capacités (voir ensure_role()). Sans ce filtre, la Réviseure ne
	 * peut jamais entrer dans wp-admin sur un site où WooCommerce tourne. Ce filtre ne lève
	 * aucune restriction ailleurs : il ne fait que laisser passer la porte d'entrée, les
	 * garde-fous propres au module (map_meta_cap, REST) s'appliquent ensuite normalement.
	 *
	 * @param bool $prevent_access Décision de WooCommerce avant ce filtre.
	 * @return bool
	 */
	public static function allow_woocommerce_admin_access( bool $prevent_access ): bool {
		if ( current_user_can( self::CAP ) ) {
			return false;
		}
		return $prevent_access;
	}
}
