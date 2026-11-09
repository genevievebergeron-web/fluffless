<?php
/**
 * Module Événements : un type de contenu daté, ses liaisons de blocs, le flux .ics, le JSON-LD Event
 * et le bloc Calendrier. Aucun JavaScript côté visiteur.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use DateTimeImmutable;
use Fluffless\Suite\Blocks\Calendrier;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Events\Event;
use Fluffless\Suite\Events\Ics;
use WP_Block;
use WP_Query;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Fonctionnement :
 * - Type fluffless_event, adresse fixée une fois dans l'option fluffless_events_slug d'après la langue du
 *   site (jamais celle de la requête) et une page déjà présente : Polylang ou un changement de langue ne
 *   font pas alterner les règles de réécriture.
 * - Dates en heure du site, sans décalage (voir Event). La méta dérivée _fluffless_event_until sert seule
 *   à trier et à masquer les passés, dans l'archive, le flux RSS et la Query Loop.
 * - Source de liaison fluffless/evenement ; une liaison vide retire son bloc (pas de bouton mort).
 * - Un contenu protégé par mot de passe ne sort nulle part : liaisons, .ics, calendrier, JSON-LD.
 */
final class Events implements Module {

	public const SOURCE      = 'fluffless/evenement';
	public const SLUG_OPTION = 'fluffless_events_slug';
	public const FEED        = 'ics';
	public const MONTH_ARG   = 'fluffless-month';
	public const PAST_CLASS  = 'is-past-events';
	public const TEMPLATES   = array( 'single-fluffless_event', 'archive-fluffless_event' );

	/** Flux d'abonnement : les événements à venir et ceux des derniers jours, plafonnés. */
	public const FEED_PAST_DAYS = 90;
	public const FEED_MAX       = 200;

	/** Clés de la source de liaison. Les trois dernières ne dépendent pas du contenu. */
	public const KEYS = array( 'when', 'place', 'address', 'signup', 'online', 'ics', 'status', 'feed', 'webcal', 'google' );

	/** Blocs retirés quand leur liaison est vide. */
	private const DROPPABLE = array( 'core/button', 'core/paragraph', 'core/heading' );

	/** Métas dont un changement fait monter la SEQUENCE du .ics. */
	private const VERSIONED = array( Event::START, Event::END, Event::STATUS, Event::PLACE, Event::STREET, Event::CITY, Event::REGION, Event::POSTAL, Event::COUNTRY, Event::ONLINE );

	private const FLUSH_LOCK = 'fluffless_events_flush';
	private const HANDLE     = 'fluffless-evenements-editor';
	private const DEPS       = array( 'wp-blocks', 'wp-components', 'wp-core-data', 'wp-data', 'wp-date', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' );

	/**
	 * Pile des Query Loop en cours de rendu : vrai pour celles qui portent la classe is-past-events.
	 *
	 * @var list<bool>
	 */
	private static array $past = array();

	/**
	 * Événements en cours de suppression.
	 *
	 * @var list<int>
	 */
	private static array $deleting = array();

	/** {@inheritDoc} */
	public static function id(): string {
		return 'events';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Events', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'An Event content type with date, place and sign-up link, past events hidden from lists, Event structured data, a calendar feed visitors can subscribe to, and a monthly Calendar block. No JavaScript on the site.', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function enabled_by_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public static function defaults(): array {
		return array();
	}

	/**
	 * Aucun réglage.
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return array();
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_type' ), 5 );
		add_action( 'init', array( self::class, 'register_source' ) );
		add_action( 'init', array( self::class, 'register_templates' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
		add_action( 'update_post_meta', array( self::class, 'before_meta_update' ), 10, 4 );
		add_action( 'added_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'deleting' ) );
		add_action( 'pre_get_posts', array( self::class, 'main_query' ) );
		add_filter( 'rest_' . Event::TYPE . '_query', array( self::class, 'rest_query' ) );
		add_filter( 'rest_prepare_' . Event::TYPE, array( self::class, 'rest_prepare' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'sitemap_query' ), 10, 2 );
		add_filter( 'query_loop_block_query_vars', array( self::class, 'loop_query' ), 10, 2 );
		add_filter( 'render_block_data', array( self::class, 'enter_query' ) );
		add_filter( 'render_block', array( self::class, 'render_block' ), 10, 3 );
		add_filter( 'feed_content_type', array( self::class, 'content_type' ), 10, 2 );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_action( 'wp_head', array( self::class, 'head' ) );
		// Priorité 5 : le panneau Événement s'enregistre avant Empreinte et Qualité, donc passe en tête (P4-10).
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ), 5 );
		if ( is_admin() ) {
			add_filter( 'manage_' . Event::TYPE . '_posts_columns', array( self::class, 'columns' ) );
			add_action( 'manage_' . Event::TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
			add_filter( 'manage_edit-' . Event::TYPE . '_sortable_columns', array( self::class, 'sortable' ) );
			add_action( 'admin_notices', array( self::class, 'notices' ) );
		}
	}

	/* ---------- Type de contenu et métas ---------- */

	/**
	 * Adresse de l'archive, fixée au premier appel : la langue du site (option WPLANG, pas celle de la
	 * requête), puis le premier candidat qu'aucune page n'occupe.
	 */
	public static function slug(): string {
		$slug = get_option( self::SLUG_OPTION );
		if ( is_string( $slug ) && 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $slug ) ) {
			return $slug;
		}
		$candidates = self::candidates();
		$slug       = 'fluffless-events';
		foreach ( $candidates as $candidate ) {
			if ( null === get_page_by_path( $candidate, OBJECT, array( 'page', 'post' ) ) ) {
				$slug = $candidate;
				break;
			}
		}
		update_option( self::SLUG_OPTION, $slug, true );
		return $slug;
	}

	/**
	 * Adresses proposées, dans l'ordre, selon la langue du site.
	 *
	 * @return list<string>
	 */
	private static function candidates(): array {
		return str_starts_with( (string) get_option( 'WPLANG' ), 'fr' )
			? array( 'evenements', 'agenda', 'calendrier' )
			: array( 'events', 'event-calendar', 'calendar' );
	}

	/** Type de contenu, flux .ics et métas. */
	public static function register_type(): void {
		// Avant le type : la règle du flux de l'archive se calcule à l'enregistrement du type.
		add_feed( self::FEED, array( self::class, 'feed' ) );
		$slug = self::slug();
		register_post_type(
			Event::TYPE,
			array(
				'labels'           => array(
					'name'                   => __( 'Events', 'fluffless-suite' ),
					'singular_name'          => __( 'Event', 'fluffless-suite' ),
					'menu_name'              => __( 'Events', 'fluffless-suite' ),
					'all_items'              => __( 'All events', 'fluffless-suite' ),
					'add_new_item'           => __( 'Add event', 'fluffless-suite' ),
					'edit_item'              => __( 'Edit event', 'fluffless-suite' ),
					'new_item'               => __( 'New event', 'fluffless-suite' ),
					'view_item'              => __( 'View event', 'fluffless-suite' ),
					'view_items'             => __( 'View events', 'fluffless-suite' ),
					'search_items'           => __( 'Search events', 'fluffless-suite' ),
					'not_found'              => __( 'No events found.', 'fluffless-suite' ),
					'not_found_in_trash'     => __( 'No events found in the trash.', 'fluffless-suite' ),
					'archives'               => __( 'Events', 'fluffless-suite' ),
					'item_published'         => __( 'Event published.', 'fluffless-suite' ),
					'item_updated'           => __( 'Event updated.', 'fluffless-suite' ),
					'item_scheduled'         => __( 'Event scheduled for publication.', 'fluffless-suite' ),
					'item_reverted_to_draft' => __( 'Event reverted to draft.', 'fluffless-suite' ),
				),
				'public'           => true,
				'show_in_rest'     => true,
				'has_archive'      => $slug,
				'rewrite'          => array(
					'slug'       => $slug,
					'with_front' => false,
					'feeds'      => true,
				),
				'menu_icon'        => 'dashicons-calendar-alt',
				'menu_position'    => 21,
				'supports'         => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields', 'author' ),
				'delete_with_user' => false,
			)
		);
		$text = static fn( $value ): string => sanitize_text_field( is_string( $value ) ? $value : '' );
		$url  = static fn( $value ): string => is_string( $value ) ? esc_url_raw( trim( $value ), array( 'http', 'https' ) ) : '';
		$meta = array(
			Event::START   => array( Event::class, 'sanitize_date' ),
			Event::END     => array( Event::class, 'sanitize_end' ),
			Event::PLACE   => $text,
			Event::STREET  => $text,
			Event::CITY    => $text,
			Event::REGION  => $text,
			Event::POSTAL  => $text,
			Event::COUNTRY => array( Event::class, 'sanitize_country' ),
			Event::ONLINE  => $url,
			Event::SIGNUP  => $url,
			Event::STATUS  => static fn( $value ): string => is_string( $value ) && in_array( $value, Event::STATUSES, true ) ? $value : Event::SCHEDULED,
		);
		foreach ( $meta as $key => $sanitize ) {
			register_post_meta(
				Event::TYPE,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => Event::COUNTRY === $key ? Event::DEFAULT_COUNTRY : ( Event::STATUS === $key ? Event::SCHEDULED : '' ),
					'show_in_rest'      => true,
					'revisions_enabled' => true,
					'sanitize_callback' => $sanitize,
					'auth_callback'     => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
				)
			);
		}
	}

	/**
	 * Avant un changement de date de début sur un événement publié : garde la première date publiée
	 * (EventRescheduled, previousStartDate). Revenir à cette date efface la mémoire.
	 *
	 * @param int    $meta_id   Méta.
	 * @param int    $object_id Contenu.
	 * @param string $meta_key  Clé.
	 * @param mixed  $value     Nouvelle valeur.
	 */
	public static function before_meta_update( $meta_id, $object_id, $meta_key, $value ): void {
		if ( Event::START !== $meta_key || Event::TYPE !== get_post_type( (int) $object_id ) || 'publish' !== get_post_status( (int) $object_id ) ) {
			return;
		}
		$old      = (string) get_post_meta( (int) $object_id, Event::START, true );
		$previous = (string) get_post_meta( (int) $object_id, Event::PREVIOUS, true );
		if ( is_string( $value ) && $value === $previous ) {
			delete_post_meta( (int) $object_id, Event::PREVIOUS );
		} elseif ( '' === $previous && '' !== $old && $old !== $value ) {
			update_post_meta( (int) $object_id, Event::PREVIOUS, $old );
		}
	}

	/**
	 * Après un changement de méta d'un événement : recalcule la fin réelle, monte la version.
	 *
	 * @param int|list<int> $meta_id   Méta (liste à la suppression).
	 * @param int           $object_id Contenu.
	 * @param string        $meta_key  Clé.
	 */
	public static function meta_changed( $meta_id, $object_id, $meta_key ): void {
		if ( ! in_array( $meta_key, self::VERSIONED, true ) || Event::TYPE !== get_post_type( (int) $object_id ) ) {
			return;
		}
		$post_id = (int) $object_id;
		if ( in_array( $post_id, self::$deleting, true ) ) {
			return;
		}
		$bump = 'added_post_meta' === current_action() ? 'publish' === get_post_status( $post_id ) : true;
		if ( $bump ) {
			update_post_meta( $post_id, Event::SEQUENCE, (string) ( (int) get_post_meta( $post_id, Event::SEQUENCE, true ) + 1 ) );
		}
		if ( Event::START !== $meta_key && Event::END !== $meta_key ) {
			return;
		}
		$event = Event::from_post( $post_id );
		if ( null === $event ) {
			delete_post_meta( $post_id, Event::UNTIL );
			return;
		}
		update_post_meta( $post_id, Event::UNTIL, $event->until()->format( 'Y-m-d H:i' ) );
	}

	/**
	 * Suppression d'un contenu : ses métas partent sans monter de version ni recréer de ligne.
	 *
	 * @param int $post_id Contenu.
	 */
	public static function deleting( $post_id ): void {
		if ( Event::TYPE === get_post_type( (int) $post_id ) ) {
			self::$deleting[] = (int) $post_id;
		}
	}

	/**
	 * API REST : sans droit de modification, la liste ne contient aucun événement protégé.
	 *
	 * @param mixed $args Variables de requête.
	 * @return array<mixed>
	 */
	public static function rest_query( $args ): array {
		$args = is_array( $args ) ? $args : array();
		if ( ! current_user_can( 'edit_posts' ) ) {
			$args['has_password'] = false;
		}
		return $args;
	}

	/**
	 * API REST : un événement protégé, lu sans droit de modification, ne livre aucune méta (lieu, liens, dates).
	 *
	 * @param mixed $response Réponse.
	 * @param mixed $post     Contenu.
	 * @return mixed
	 */
	public static function rest_prepare( $response, $post ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || '' === $post->post_password || current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) && array_key_exists( 'meta', $data ) ) {
			$data['meta'] = array();
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Plan du site : aucun événement protégé.
	 *
	 * @param mixed  $args      Variables de requête.
	 * @param string $post_type Type.
	 * @return array<mixed>
	 */
	public static function sitemap_query( $args, $post_type = '' ): array {
		$args = is_array( $args ) ? $args : array();
		if ( Event::TYPE === $post_type ) {
			$args['has_password'] = false;
		}
		return $args;
	}

	/**
	 * Vide les règles de réécriture si celle du flux de l'archive manque (activation du module, permaliens
	 * enregistrés pendant que le module était éteint) ; au plus une fois l'heure.
	 */
	public static function maybe_flush(): void {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return;
		}
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || array() === $rules || self::has_feed_rule( $rules ) || false !== get_transient( self::FLUSH_LOCK ) ) {
			return;
		}
		set_transient( self::FLUSH_LOCK, 1, HOUR_IN_SECONDS );
		flush_rewrite_rules( false );
	}

	/**
	 * La règle du flux .ics de l'archive est là.
	 *
	 * @param array<mixed> $rules Règles enregistrées.
	 */
	public static function has_feed_rule( array $rules ): bool {
		$prefix = self::slug() . '/feed/';
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( is_string( $pattern ) && str_starts_with( $pattern, $prefix ) && str_contains( $pattern, '|' . self::FEED ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- Requêtes ---------- */

	/**
	 * Requête principale : passés masqués dans l'archive et son flux RSS ; tri par date dans l'administration.
	 *
	 * @param mixed $query Requête (WP_Query attendue).
	 */
	public static function main_query( $query ): void {
		if ( ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( is_admin() ) {
			if ( Event::TYPE === $query->get( 'post_type' ) && 'fluffless_event_date' === $query->get( 'orderby' ) ) {
				$query->set( 'meta_key', Event::START );
				$query->set( 'orderby', 'meta_value' );
			}
			return;
		}
		if ( $query->is_search() ) {
			$hidden = get_posts(
				array(
					'post_type'      => Event::TYPE,
					'post_status'    => 'publish',
					'has_password'   => true,
					'posts_per_page' => 100,
					'no_found_rows'  => true,
					'fields'         => 'ids',
				)
			);
			if ( array() !== $hidden ) {
				$query->set( 'post__not_in', array_merge( wp_parse_id_list( $query->get( 'post__not_in' ) ), wp_parse_id_list( $hidden ) ) );
			}
			return;
		}
		if ( $query->is_post_type_archive( Event::TYPE ) && ! $query->is_feed( self::FEED ) ) {
			foreach ( self::query_vars( array(), false ) as $key => $value ) {
				$query->set( $key, $value );
			}
		}
	}

	/**
	 * Variables de requête des événements : à venir triés par début croissant, ou passés du plus récent au plus ancien ;
	 * jamais un contenu protégé par mot de passe.
	 *
	 * @param array<string, mixed> $vars Variables d'origine.
	 * @param bool                 $past Passés plutôt qu'à venir.
	 * @return array<string, mixed>
	 */
	public static function query_vars( array $vars, bool $past ): array {
		$meta = isset( $vars['meta_query'] ) && is_array( $vars['meta_query'] ) ? $vars['meta_query'] : array();

		$vars['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- jointures sur meta_key (indexée) ; un site garde peu d'événements.
			'relation'           => 'AND',
			'fluffless_until'    => array(
				'key'     => Event::UNTIL,
				'value'   => self::now()->format( 'Y-m-d H:i' ),
				'compare' => $past ? '<' : '>=',
			),
			'fluffless_start'    => array(
				'key'     => Event::START,
				'compare' => 'EXISTS',
			),
			'fluffless_original' => $meta,
		);
		if ( array() === $meta ) {
			unset( $vars['meta_query']['fluffless_original'] );
		}
		$vars['orderby']      = array( 'fluffless_start' => $past ? 'DESC' : 'ASC' );
		$vars['has_password'] = false; // Un événement protégé ne sort dans aucune liste.
		unset( $vars['order'] );
		/**
		 * Filtre les variables de requête des événements (archive, flux RSS, Query Loop).
		 *
		 * @param array<string, mixed> $vars Variables.
		 * @param bool                 $past Passés plutôt qu'à venir.
		 */
		// (array) : un filtre tiers peut rendre n'importe quoi.
		return (array) apply_filters( 'fluffless_events_query_vars', $vars, $past );
	}

	/**
	 * Query Loop non héritée dont le type est Événement : même filtre et même tri que l'archive.
	 *
	 * @param mixed         $query Variables construites par le bloc.
	 * @param WP_Block|null $block Bloc core/post-template.
	 * @return array<string, mixed>
	 */
	public static function loop_query( $query, $block = null ): array {
		$query = is_array( $query ) ? $query : array();
		$type  = $query['post_type'] ?? '';
		if ( Event::TYPE !== $type && array( Event::TYPE ) !== $type ) {
			return $query;
		}
		return self::query_vars( $query, (bool) end( self::$past ) );
	}

	/**
	 * Entrée dans une Query Loop : retient si elle demande les passés (classe is-past-events).
	 *
	 * @param mixed $parsed Bloc analysé.
	 * @return array<mixed>
	 */
	public static function enter_query( $parsed ): array {
		$parsed = is_array( $parsed ) ? $parsed : array();
		if ( 'core/query' === ( $parsed['blockName'] ?? '' ) ) {
			$class        = (string) ( $parsed['attrs']['className'] ?? '' );
			self::$past[] = in_array( self::PAST_CLASS, explode( ' ', $class ), true );
		}
		return $parsed;
	}

	/**
	 * Sortie d'une Query Loop ; retrait des blocs dont la liaison fluffless/evenement est vide.
	 *
	 * @param mixed         $content Rendu.
	 * @param mixed         $parsed  Bloc analysé.
	 * @param WP_Block|null $block   Instance.
	 */
	public static function render_block( $content, $parsed, $block = null ): string {
		$content = (string) $content;
		$name    = is_array( $parsed ) ? (string) ( $parsed['blockName'] ?? '' ) : '';
		if ( 'core/query' === $name ) {
			array_pop( self::$past );
			return $content;
		}
		if ( 'core/buttons' === $name && ! str_contains( $content, '<a' ) && ! str_contains( $content, '<button' ) && '' === trim( wp_strip_all_tags( $content ) ) ) {
			return '';
		}
		if ( ! in_array( $name, self::DROPPABLE, true ) ) {
			return $content;
		}
		$bindings = $parsed['attrs']['metadata']['bindings'] ?? null;
		if ( ! is_array( $bindings ) ) {
			return $content;
		}
		foreach ( $bindings as $attribute => $binding ) {
			if ( ! is_array( $binding ) || self::SOURCE !== ( $binding['source'] ?? '' ) ) {
				continue;
			}
			$args  = is_array( $binding['args'] ?? null ) ? $binding['args'] : array();
			$value = self::value( $args, $block instanceof WP_Block ? $block : null, (string) $attribute );
			if ( null === $value || '' === $value ) {
				return '';
			}
		}
		return $content;
	}

	/* ---------- Liaisons ---------- */

	/** Source de liaison fluffless/evenement. */
	public static function register_source(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'Event (Fluffless)', 'fluffless-suite' ),
				'get_value_callback' => array( self::class, 'value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * Valeur d'une liaison : texte échappé pour un contenu, adresse brute pour un attribut url (le cœur l'échappe).
	 *
	 * @param array<string, mixed> $args           Arguments (key).
	 * @param WP_Block|null        $block          Bloc lié.
	 * @param string               $attribute_name Attribut lié.
	 */
	public static function value( array $args, $block = null, string $attribute_name = 'content' ): ?string {
		$key  = is_string( $args['key'] ?? null ) ? $args['key'] : '';
		$text = 'url' !== $attribute_name;
		switch ( $key ) {
			case 'feed':
				return $text ? '<a href="' . esc_url( self::feed_url() ) . '">' . esc_html( self::feed_url() ) . '</a>' : self::feed_url();
			case 'webcal':
				return self::webcal_url();
			case 'google':
				return 'https://calendar.google.com/calendar/r?cid=' . rawurlencode( self::webcal_url() );
		}
		$context = $block instanceof WP_Block ? $block->context : array();
		$post_id = (int) ( $context['postId'] ?? get_the_ID() );
		if ( $post_id <= 0 || post_password_required( $post_id ) ) {
			return null;
		}
		$event = Event::from_post( $post_id );
		if ( null === $event ) {
			return null;
		}
		$value = match ( $key ) {
			'when'    => esc_html( $event->when() ),
			'place'   => esc_html( $event->place ),
			'address' => nl2br( esc_html( $event->address_text() ), false ),
			'signup'  => $event->signup,
			'online'  => $event->online,
			'ics'     => self::event_feed_url( $post_id ),
			'status'  => esc_html( $event->status_label() ),
			default   => '',
		};
		return '' === $value ? null : $value;
	}

	/** Adresse du flux d'abonnement. */
	public static function feed_url(): string {
		return (string) get_post_type_archive_feed_link( Event::TYPE, self::FEED );
	}

	/** Adresse du flux en webcal:// (Apple Calendrier, Outlook). */
	public static function webcal_url(): string {
		return (string) preg_replace( '#^https?://#', 'webcal://', self::feed_url() );
	}

	/**
	 * Adresse du .ics d'un seul événement.
	 *
	 * @param int $post_id Événement.
	 */
	public static function event_feed_url( int $post_id ): string {
		$link = (string) get_permalink( $post_id );
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'feed', self::FEED, $link );
		}
		return trailingslashit( $link ) . user_trailingslashit( 'feed/' . self::FEED, 'single_feed' );
	}

	/* ---------- Flux .ics ---------- */

	/**
	 * Type MIME du flux .ics.
	 *
	 * @param string $type Type proposé.
	 * @param string $feed Flux demandé.
	 */
	public static function content_type( $type, $feed ): string {
		return self::FEED === $feed ? 'text/calendar' : (string) $type;
	}

	/** Sortie du flux .ics : un événement, l'archive, ou 404 ailleurs. */
	public static function feed(): void {
		$payload = self::feed_payload();
		if ( null === $payload ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'No calendar here.', 'fluffless-suite' );
			return;
		}
		header( 'Content-Type: text/calendar; charset=utf-8' );
		if ( '' !== $payload['filename'] ) {
			header( 'Content-Disposition: attachment; filename="' . $payload['filename'] . '"' );
		}
		echo $payload['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- texte iCalendar, échappé par Ics::text().
	}

	/**
	 * Contenu du flux pour la requête courante, null pour une 404.
	 *
	 * @return array{body: string, filename: string}|null
	 */
	public static function feed_payload(): ?array {
		if ( is_singular( Event::TYPE ) ) {
			$post  = get_queried_object();
			$event = $post instanceof \WP_Post && 'publish' === $post->post_status && '' === $post->post_password ? Event::from_post( $post ) : null;
			if ( null === $event ) {
				return null;
			}
			return array(
				'body'     => Ics::calendar( array( $event ) ),
				'filename' => sanitize_file_name( $post->post_name . '.ics' ),
			);
		}
		if ( is_post_type_archive( Event::TYPE ) ) {
			return array(
				'body'     => Ics::calendar( self::feed_events(), self::calendar_name() ),
				'filename' => '',
			);
		}
		return null;
	}

	/**
	 * Événements du flux d'abonnement, par début croissant : d'abord ceux à venir ou en cours (jusqu'au plafond),
	 * puis, dans la place qui reste, les plus récents des FEED_PAST_DAYS derniers jours.
	 *
	 * @return list<Event>
	 */
	public static function feed_events(): array {
		$now      = self::now();
		$upcoming = self::events_between( $now->format( 'Y-m-d H:i' ), null, self::FEED_MAX );
		$room     = self::FEED_MAX - count( $upcoming );
		if ( $room <= 0 ) {
			return $upcoming;
		}
		$since = $now->modify( '-' . self::FEED_PAST_DAYS . ' days' )->format( 'Y-m-d H:i' );
		$past  = array_values( array_filter( self::events_between( $since, $now->format( 'Y-m-d H:i' ), self::FEED_MAX ), static fn( Event $event ): bool => $event->is_past( $now ) ) );
		return array_merge( array_slice( $past, -$room ), $upcoming );
	}

	/**
	 * Événements publiés, sans mot de passe, dont la fin réelle tombe après $from et le début avant $to.
	 *
	 * @param string      $from  Fin réelle au plus tôt (« Y-m-d H:i »).
	 * @param string|null $to    Début au plus tard (« Y-m-d H:i »), null sans borne.
	 * @param int         $limit Plafond.
	 * @return list<Event>
	 */
	public static function events_between( string $from, ?string $to, int $limit ): array {
		$meta = array(
			'relation'        => 'AND',
			'fluffless_until' => array(
				'key'     => Event::UNTIL,
				'value'   => $from,
				'compare' => '>=',
			),
			'fluffless_start' => array(
				'key'     => Event::START,
				'compare' => 'EXISTS',
			),
		);
		if ( null !== $to ) {
			$meta['fluffless_start'] = array(
				'key'     => Event::START,
				'value'   => $to,
				'compare' => '<=',
			);
		}
		// Contenus complets : les métas de tous arrivent en une requête, pas une par événement.
		$posts  = get_posts(
			array(
				'post_type'        => Event::TYPE,
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => $limit,
				'no_found_rows'    => true,
				'meta_query'       => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- jointures sur meta_key (indexée) ; un site garde peu d'événements.
				'orderby'          => array( 'fluffless_start' => 'ASC' ),
				'suppress_filters' => false,
			)
		);
		$events = array();
		foreach ( $posts as $post ) {
			$event = Event::from_post( $post );
			if ( null !== $event ) {
				$events[] = $event;
			}
		}
		return $events;
	}

	/** Nom du calendrier d'abonnement : « Événements · Nom du site ». */
	private static function calendar_name(): string {
		/* translators: %s: site name. */
		return sprintf( __( 'Events, %s', 'fluffless-suite' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
	}

	/* ---------- En-tête : lien du flux, JSON-LD, robots ---------- */

	/** Lien du calendrier et données structurées. */
	public static function head(): void {
		if ( is_post_type_archive( Event::TYPE ) ) {
			printf( '<link rel="alternate" type="text/calendar" title="%s" href="%s">' . "\n", esc_attr( self::calendar_name() ), esc_url( self::feed_url() ) );
			return;
		}
		if ( ! is_singular( Event::TYPE ) ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( post_password_required( $post_id ) ) {
			return;
		}
		printf( '<link rel="alternate" type="text/calendar" title="%s" href="%s">' . "\n", esc_attr( get_the_title( $post_id ) ), esc_url( self::event_feed_url( $post_id ) ) );
		$node = self::schema( $post_id );
		if ( null !== $node ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n";
		}
	}

	/**
	 * Nœud Event d'un événement ; null sans date, protégé, ou si fluffless-seo en décide.
	 *
	 * @param int $post_id Événement.
	 * @return array<string, mixed>|null
	 */
	public static function schema( int $post_id ): ?array {
		$event = post_password_required( $post_id ) ? null : Event::from_post( $post_id );
		if ( null === $event || self::seo_decides( $post_id ) ) {
			return null;
		}
		$date     = static fn( DateTimeImmutable $d ): string => $event->all_day ? $d->format( 'Y-m-d' ) : $d->format( 'Y-m-d\TH:i:sP' );
		$address  = $event->address + array_fill_keys( array( 'street', 'city', 'region', 'postal', 'country' ), '' );
		$physical = '' !== trim( $event->place ) || '' !== $address['street'] || '' !== $address['city'];
		$online   = '' !== $event->online;
		$location = array();
		if ( $physical ) {
			$postal     = array_filter(
				array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => $address['street'],
					'addressLocality' => $address['city'],
					'addressRegion'   => $address['region'],
					'postalCode'      => $address['postal'],
					'addressCountry'  => '' !== $address['country'] ? $address['country'] : Event::DEFAULT_COUNTRY,
				)
			);
			$location[] = array_filter(
				array(
					'@type'   => 'Place',
					'name'    => '' !== trim( $event->place ) ? $event->place : $event->address_text( ', ' ),
					'address' => count( $postal ) > 2 ? $postal : array(),
				)
			);
		}
		if ( $online ) {
			$location[] = array(
				'@type' => 'VirtualLocation',
				'url'   => $event->online,
			);
		}
		$status = match ( true ) {
			Event::CANCELLED === $event->status => 'EventCancelled',
			Event::POSTPONED === $event->status => 'EventPostponed',
			null !== $event->previous           => 'EventRescheduled',
			default                             => 'EventScheduled',
		};
		$mode  = $physical && $online ? 'MixedEventAttendanceMode' : ( $online ? 'OnlineEventAttendanceMode' : 'OfflineEventAttendanceMode' );
		$image = get_the_post_thumbnail_url( $post_id, 'full' );
		$home  = home_url( '/' );
		return array_filter(
			array(
				'@context'            => 'https://schema.org',
				'@type'               => 'Event',
				'name'                => $event->title(),
				'description'         => $event->summary(),
				'startDate'           => $date( $event->start ),
				'endDate'             => null !== $event->end ? $date( $event->end ) : '',
				'previousStartDate'   => 'EventRescheduled' === $status ? $date( $event->previous ?? $event->start ) : '',
				'eventStatus'         => 'https://schema.org/' . $status,
				'eventAttendanceMode' => 'https://schema.org/' . $mode,
				'location'            => 1 === count( $location ) ? $location[0] : $location,
				'image'               => is_string( $image ) ? $image : '',
				'url'                 => $event->url(),
				'organizer'           => defined( 'FLUFFLESS_SEO_VERSION' )
					? array( '@id' => $home . '#identity' )
					: array(
						'@type' => 'Organization',
						'name'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
						'url'   => $home,
					),
			),
			static fn( $v ): bool => '' !== $v && array() !== $v
		);
	}

	/**
	 * Fluffless SEO émet le schéma de cette page : un autre type que Event y est choisi, ou Event avec
	 * sa propre date de début. Choisir Event sans rien remplir laisse le module émettre.
	 *
	 * @param int $post_id Événement.
	 */
	public static function seo_decides( int $post_id ): bool {
		if ( ! defined( 'FLUFFLESS_SEO_VERSION' ) ) {
			return false;
		}
		$type = (string) get_post_meta( $post_id, '_fluffless_seo_schema', true );
		if ( '' === $type ) {
			return false;
		}
		if ( 'Event' !== $type ) {
			return true;
		}
		$data = get_post_meta( $post_id, '_fluffless_seo_schema_data', true );
		return is_array( $data ) && '' !== (string) ( $data['start'] ?? '' );
	}

	/**
	 * Une page de calendrier sur un autre mois que le courant n'entre pas dans l'index.
	 *
	 * @param mixed $robots Directives.
	 * @return array<string, mixed>
	 */
	public static function robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();
		if ( isset( $_GET[ self::MONTH_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture seule.
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/* ---------- Gabarits et bloc ---------- */

	/** Gabarits d'un événement et de l'archive, modifiables dans l'éditeur de site. */
	public static function register_templates(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}
		$labels = array(
			'[[signup]]'    => esc_html__( 'Register', 'fluffless-suite' ),
			'[[online]]'    => esc_html__( 'Join online', 'fluffless-suite' ),
			'[[ics]]'       => esc_html__( 'Add to my calendar', 'fluffless-suite' ),
			'[[subscribe]]' => esc_html__( 'Subscribe to the calendar', 'fluffless-suite' ),
			'[[webcal]]'    => esc_html__( 'Apple Calendar or Outlook', 'fluffless-suite' ),
			'[[google]]'    => esc_html__( 'Google Calendar', 'fluffless-suite' ),
			'[[copy]]'      => esc_html__( 'Or copy this address into any calendar app:', 'fluffless-suite' ),
			'[[delay]]'     => esc_html__( 'Subscribed calendars refresh on their own schedule: Google Calendar can take up to a day to show a change.', 'fluffless-suite' ),
			'[[none]]'      => esc_html__( 'No upcoming events for now.', 'fluffless-suite' ),
		);
		$meta   = array(
			'single-fluffless_event'  => array( __( 'Event', 'fluffless-suite' ), __( 'One event: title, status, date and time, place, sign-up and calendar buttons, then the free content.', 'fluffless-suite' ) ),
			'archive-fluffless_event' => array( __( 'Events archive', 'fluffless-suite' ), __( 'Upcoming events, soonest first, with links to subscribe to the calendar.', 'fluffless-suite' ) ),
		);
		foreach ( self::TEMPLATES as $slug ) {
			$file = dirname( FILE ) . '/templates/' . $slug . '.html';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			register_block_template(
				'fluffless-suite//' . $slug,
				array(
					'title'       => $meta[ $slug ][0],
					'description' => $meta[ $slug ][1],
					'content'     => strtr( (string) file_get_contents( $file ), $labels ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					'post_types'  => array( Event::TYPE ),
					'plugin'      => 'fluffless-suite',
				)
			);
		}
	}

	/** Bloc Calendrier. */
	public static function register_block(): void {
		register_block_type(
			dirname( FILE ) . '/blocks/calendrier',
			array( 'render_callback' => array( Calendrier::class, 'render' ) )
		);
	}

	/* ---------- Éditeur ---------- */

	/** Panneau « Événement » et source de liaison côté éditeur : JS natif, sans build. */
	public static function editor_assets(): void {
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/evenements-editor.js', FILE ), self::DEPS, VERSION, true );
		wp_add_inline_script( self::HANDLE, 'window.flufflessEvenements=' . wp_json_encode( self::editor_data() ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
		wp_set_script_translations( generate_block_asset_handle( Calendrier::NAME, 'editorScript' ), 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
	}

	/**
	 * Données du script d'éditeur.
	 *
	 * @return array<string, mixed>
	 */
	public static function editor_data(): array {
		return array(
			'type'       => Event::TYPE,
			'source'     => self::SOURCE,
			'keys'       => array(
				'start'   => Event::START,
				'end'     => Event::END,
				'place'   => Event::PLACE,
				'street'  => Event::STREET,
				'city'    => Event::CITY,
				'region'  => Event::REGION,
				'postal'  => Event::POSTAL,
				'country' => Event::COUNTRY,
				'online'  => Event::ONLINE,
				'signup'  => Event::SIGNUP,
				'status'  => Event::STATUS,
			),
			'dateFormat' => (string) get_option( 'date_format' ),
			'feed'       => self::feed_url(),
			'webcal'     => self::webcal_url(),
			'google'     => 'https://calendar.google.com/calendar/r?cid=' . rawurlencode( self::webcal_url() ),
			'tzWarning'  => self::fixed_offset(),
			'tzUrl'      => admin_url( 'options-general.php#timezone_string' ),
		);
	}

	/* ---------- Administration ---------- */

	/**
	 * Colonne « Date de l'événement », après le titre.
	 *
	 * @param mixed $columns Colonnes.
	 * @return array<string, string>
	 */
	public static function columns( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();
		$out     = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['fluffless_event_date'] = __( 'Event date', 'fluffless-suite' );
			}
		}
		return $out;
	}

	/**
	 * Contenu de la colonne.
	 *
	 * @param string $column  Colonne.
	 * @param int    $post_id Événement.
	 */
	public static function column( $column, $post_id ): void {
		if ( 'fluffless_event_date' !== $column ) {
			return;
		}
		$event = Event::from_post( (int) $post_id );
		if ( null === $event ) {
			echo esc_html__( 'No date', 'fluffless-suite' );
			return;
		}
		$label = $event->status_label();
		echo esc_html( $event->when() . ( '' !== $label ? ' (' . $label . ')' : '' ) );
	}

	/**
	 * Colonne triable.
	 *
	 * @param mixed $columns Colonnes triables.
	 * @return array<string, string>
	 */
	public static function sortable( $columns ): array {
		$columns                         = is_array( $columns ) ? $columns : array();
		$columns['fluffless_event_date'] = 'fluffless_event_date';
		return $columns;
	}

	/** Avis sur l'écran des événements : fuseau à décalage fixe, adresse de repli. */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'edit-' . Event::TYPE !== $screen->id ) {
			return;
		}
		if ( self::fixed_offset() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'The site time zone is a fixed offset or UTC: calendars and search engines will show event times one hour off during daylight saving time. Pick a city instead.', 'fluffless-suite' ),
				esc_url( admin_url( 'options-general.php#timezone_string' ) ),
				esc_html__( 'Time zone settings', 'fluffless-suite' )
			);
		}
		$preferred = self::candidates()[0];
		$slug      = self::slug();
		if ( $slug !== $preferred ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: address already used by a page, 2: address of the events. */
						__( 'A page already uses /%1$s/, so events live at /%2$s/.', 'fluffless-suite' ),
						$preferred,
						$slug
					)
				)
			);
		}
	}

	/** Le fuseau du site est un décalage fixe (ou UTC) plutôt qu'une ville. */
	public static function fixed_offset(): bool {
		$zone = (string) get_option( 'timezone_string' );
		return '' === $zone || str_starts_with( $zone, 'UTC' ) || str_starts_with( $zone, 'Etc/' );
	}

	/** Maintenant, en heure du site. */
	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', wp_timezone() );
	}
}
