<?php
/**
 * Tests du module Événements et du bloc Calendrier : adresse de l'archive et repli, nettoyage des dates,
 * fin après minuit et heure seule, fin réelle, version et ancien début tenus à jour, requêtes à venir et
 * passées sans contenu protégé, liaisons (chaque clé, mot de passe, valeur vide, blocs retirés), fichier
 * .ics (UID, SEQUENCE, UTC, journée entière, pliage, échappement, 404), JSON-LD (statuts, adresse,
 * mode de présence), robots, et calendrier (bornes du mois, jours, rendu, poids et règles du CSS).
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/events-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * Les contenus d'essai, les options touchées, $_GET et la requête globale sont remis à leur état à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\Calendrier;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Events\Event;
use Fluffless\Suite\Events\Ics;
use Fluffless\Suite\Modules\Events;
use Fluffless\Suite\Modules\Fields;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Events::class ) || ! isset( Loader::modules()['events'] ) || ! post_type_exists( Event::TYPE ) ) {
	throw new RuntimeException( 'Module Événements absent ou éteint : activer le module « events » avant ce test.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

// P4-10 : le panneau Événement s'enregistre avant les autres panneaux de la Suite, donc paraît en tête.
$fluffless_assert( 5 === has_action( 'enqueue_block_editor_assets', array( Events::class, 'editor_assets' ) ), 'P4-10 : script du panneau Événement attendu en priorité 5' );

$fluffless_posts     = array();
$fluffless_slug_was  = get_option( Events::SLUG_OPTION );
$fluffless_lang_was  = get_option( 'WPLANG' );
$fluffless_get_was   = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sauvegarde pour le test.
$fluffless_query_was = $GLOBALS['wp_query'] ?? null;
$fluffless_the_was   = $GLOBALS['wp_the_query'] ?? null;
$fluffless_post_was  = $GLOBALS['post'] ?? null;
$fluffless_user_was  = get_current_user_id();

// Crée un événement publié avec ses métas ; gardé pour le ménage.
$fluffless_event = static function ( string $title, array $meta, array $args = array() ) use ( &$fluffless_posts ): int {
	$id = wp_insert_post(
		$args + array(
			'post_type'   => Event::TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);
	if ( $id <= 0 ) {
		throw new RuntimeException( esc_html( "Événement d'essai non créé : {$title}" ) );
	}
	$fluffless_posts[] = $id;
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

// Bloc lié au contenu, comme le cœur le prépare au rendu.
$fluffless_block = static function ( int $post_id, string $name = 'core/paragraph' ): WP_Block {
	$block          = new WP_Block(
		array(
			'blockName'    => $name,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
	$block->context = array(
		'postId'   => $post_id,
		'postType' => Event::TYPE,
	);
	return $block;
};

// Requête principale simulée (is_singular, is_post_type_archive, get_queried_object).
$fluffless_main = static function ( array $vars ): void {
	$query                   = new WP_Query( $vars );
	$GLOBALS['wp_query']     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	$GLOBALS['post']         = $query->post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
};

try {
	$fluffless_tz  = wp_timezone();
	$fluffless_now = Events::now();
	$fluffless_day = static fn( int $days ): DateTimeImmutable => $fluffless_now->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' );

	// 1. Adresse de l'archive : option valide gardée, candidats selon la langue, page occupée sautée.
	delete_option( Events::SLUG_OPTION );
	update_option( 'WPLANG', 'fr_CA' );
	$fluffless_assert( 'evenements' === Events::slug(), 'adresse en français : evenements' );
	$fluffless_assert( 'evenements' === get_option( Events::SLUG_OPTION ), 'adresse gardée en option' );
	delete_option( Events::SLUG_OPTION );
	update_option( 'WPLANG', '' );
	$fluffless_taken   = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Events',
			'post_name'   => 'events',
		)
	);
	$fluffless_posts[] = (int) $fluffless_taken;
	$fluffless_got     = Events::slug();
	$fluffless_assert( 'events' !== $fluffless_got && 'event-calendar' === $fluffless_got, 'page « events » occupée : repli sur event-calendar, reçu ' . $fluffless_got );
	update_option( Events::SLUG_OPTION, 'Mauvaise Adresse' );
	$fluffless_assert( 'Mauvaise Adresse' !== Events::slug(), 'option mal formée ignorée' );
	if ( false === $fluffless_slug_was ) {
		delete_option( Events::SLUG_OPTION );
	} else {
		update_option( Events::SLUG_OPTION, $fluffless_slug_was );
	}
	if ( false === $fluffless_lang_was ) {
		delete_option( 'WPLANG' );
	} else {
		update_option( 'WPLANG', $fluffless_lang_was );
	}
	$fluffless_assert( Events::has_feed_rule( array( Events::slug() . '/feed/(feed|rdf|rss|rss2|atom|ics)/?$' => 'x' ) ) && ! Events::has_feed_rule( array( Events::slug() . '/feed/(feed|rss)/?$' => 'x' ) ), 'règle du flux .ics reconnue' );

	// 2. Nettoyage des dates.
	$fluffless_assert( '2026-10-21 18:30' === Event::sanitize_date( '2026-10-21T18:30:00' ), 'date ISO ramenée à « Y-m-d H:i »' );
	$fluffless_assert( '2026-10-21' === Event::sanitize_date( ' 2026-10-21 ' ), 'journée entière gardée' );
	foreach ( array( '2026-02-30', '2026-10-21 24:00', '1969-12-31', '21/10/2026', '<b>2026-10-21</b>', 42, null, array() ) as $fluffless_bad ) {
		$fluffless_assert( '' === Event::sanitize_date( $fluffless_bad ), 'date refusée : ' . wp_json_encode( $fluffless_bad ) );
	}
	$fluffless_assert( '20:30' === Event::sanitize_end( '20:30:00' ) && '2026-10-22 01:00' === Event::sanitize_end( '2026-10-22 01:00' ) && '' === Event::sanitize_end( '25:00' ), 'fin : heure seule ou date' );
	$fluffless_assert( 'FR' === Event::sanitize_country( ' fr ' ) && 'CA' === Event::sanitize_country( 'Canada' ) && 'CA' === Event::sanitize_country( null ), 'code de pays' );
	$fluffless_assert( null === Event::parse( '2026-13-01' ) && true === ( Event::parse( '2026-10-21 18:30' )['timed'] ?? null ) && false === ( Event::parse( '2026-10-21' )['timed'] ?? null ), 'parse : forme et heure' );

	// 3. Fin : heure seule le jour du début, après minuit le lendemain, fin avant le début ignorée.
	$fluffless_late = $fluffless_event(
		'Soirée',
		array(
			Event::START => '2026-11-14 21:00',
			Event::END   => '01:30',
		)
	);
	$fluffless_e    = Event::from_post( $fluffless_late );
	$fluffless_assert( null !== $fluffless_e && null !== $fluffless_e->end && '2026-11-15 01:30' === $fluffless_e->end->format( 'Y-m-d H:i' ), 'fin après minuit : lendemain' );
	$fluffless_assert( '2026-11-15 01:30' === get_post_meta( $fluffless_late, Event::UNTIL, true ), 'fin réelle calculée : ' . (string) get_post_meta( $fluffless_late, Event::UNTIL, true ) );
	$fluffless_assert( null !== $fluffless_e && $fluffless_e->covers( '2026-11-14' ) && $fluffless_e->covers( '2026-11-15' ) && ! $fluffless_e->covers( '2026-11-16' ), 'soirée : couvre deux jours' );
	$fluffless_back = $fluffless_event(
		'À rebours',
		array(
			Event::START => '2026-11-14 10:00',
			Event::END   => '2026-11-13 12:00',
		)
	);
	$fluffless_e    = Event::from_post( $fluffless_back );
	$fluffless_assert( null !== $fluffless_e && null === $fluffless_e->end, 'fin avant le début : ignorée' );
	$fluffless_days = $fluffless_event(
		'Deux jours',
		array(
			Event::START => '2026-11-20',
			Event::END   => '2026-11-21 15:00',
		)
	);
	$fluffless_e    = Event::from_post( $fluffless_days );
	$fluffless_assert( null !== $fluffless_e && $fluffless_e->all_day && null !== $fluffless_e->end && '2026-11-21 00:00' === $fluffless_e->end->format( 'Y-m-d H:i' ) && '2026-11-21 23:59' === $fluffless_e->until()->format( 'Y-m-d H:i' ), 'journée entière : fin sans heure, fin réelle 23 h 59' );
	$fluffless_assert( null === Event::from_post( $fluffless_event( 'Sans date', array() ) ), 'sans début : pas un événement' );
	$fluffless_assert( null === Event::from_post( (int) $fluffless_taken ), 'autre type : pas un événement' );

	// 4. Version et ancien début.
	$fluffless_moved = $fluffless_event( 'Déplacé', array( Event::START => $fluffless_day( 30 )->format( 'Y-m-d 10:00' ) ) );
	$fluffless_seq   = (int) get_post_meta( $fluffless_moved, Event::SEQUENCE, true );
	$fluffless_first = (string) get_post_meta( $fluffless_moved, Event::START, true );
	update_post_meta( $fluffless_moved, Event::START, $fluffless_day( 31 )->format( 'Y-m-d 10:00' ) );
	$fluffless_assert( (int) get_post_meta( $fluffless_moved, Event::SEQUENCE, true ) === $fluffless_seq + 1, 'date changée : SEQUENCE monte' );
	$fluffless_assert( get_post_meta( $fluffless_moved, Event::PREVIOUS, true ) === $fluffless_first, 'date changée : ancien début gardé' );
	$fluffless_assert( get_post_meta( $fluffless_moved, Event::UNTIL, true ) === $fluffless_day( 31 )->format( 'Y-m-d 23:59' ), 'date changée, sans fin : fin réelle à 23 h 59 du jour' );
	update_post_meta( $fluffless_moved, Event::START, $fluffless_day( 32 )->format( 'Y-m-d 10:00' ) );
	$fluffless_assert( get_post_meta( $fluffless_moved, Event::PREVIOUS, true ) === $fluffless_first, 'deuxième changement : le premier début reste' );
	update_post_meta( $fluffless_moved, Event::START, $fluffless_first );
	$fluffless_assert( '' === get_post_meta( $fluffless_moved, Event::PREVIOUS, true ), 'retour à la date d\'origine : ancien début effacé' );
	update_post_meta( $fluffless_moved, Event::PLACE, 'Salle B' );
	$fluffless_seq = (int) get_post_meta( $fluffless_moved, Event::SEQUENCE, true );
	update_post_meta( $fluffless_moved, Event::PLACE, 'Salle C' );
	$fluffless_assert( (int) get_post_meta( $fluffless_moved, Event::SEQUENCE, true ) === $fluffless_seq + 1, 'lieu changé : SEQUENCE monte' );
	$fluffless_seq = (int) get_post_meta( $fluffless_moved, Event::SEQUENCE, true );
	update_post_meta( $fluffless_moved, Event::SIGNUP, 'https://example.org/a' );
	update_post_meta( $fluffless_moved, Event::SIGNUP, 'https://example.org/b' );
	$fluffless_assert( (int) get_post_meta( $fluffless_moved, Event::SEQUENCE, true ) === $fluffless_seq, 'lien d\'inscription : pas de nouvelle version' );
	update_post_meta( $fluffless_moved, Event::CITY, 'Lévis' );
	$fluffless_assert( (int) get_post_meta( $fluffless_moved, Event::SEQUENCE, true ) === $fluffless_seq + 1, 'ville ajoutée après publication : SEQUENCE monte' );
	$fluffless_gone_id = $fluffless_event(
		'Supprimé',
		array(
			Event::START => '2026-12-05 10:00',
			Event::PLACE => 'Salle A',
		)
	);
	update_post_meta( $fluffless_gone_id, Event::CITY, 'Québec' );
	wp_delete_post( $fluffless_gone_id, true );
	$fluffless_left = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id = %d", $fluffless_gone_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- vérification après suppression, sans cache.
	$fluffless_assert( 0 === $fluffless_left, 'suppression : aucune méta orpheline (' . $fluffless_left . ')' );
	$fluffless_draft = $fluffless_event( 'Brouillon', array( Event::START => '2026-12-01 10:00' ), array( 'post_status' => 'draft' ) );
	update_post_meta( $fluffless_draft, Event::START, '2026-12-02 10:00' );
	$fluffless_assert( '' === get_post_meta( $fluffless_draft, Event::PREVIOUS, true ), 'brouillon : pas d\'ancien début' );
	delete_post_meta( $fluffless_draft, Event::START );
	$fluffless_assert( '' === get_post_meta( $fluffless_draft, Event::UNTIL, true ), 'début effacé : fin réelle effacée' );

	// 5. Requêtes : à venir et passés, jamais un contenu protégé.
	$fluffless_up     = $fluffless_event(
		'Atelier à venir',
		array(
			Event::START  => $fluffless_day( 3 )->format( 'Y-m-d 18:30' ),
			Event::END    => '20:30',
			Event::PLACE  => 'Café Alphée',
			Event::STREET => '123, rue Saint-Joseph Est',
			Event::CITY   => 'Québec',
			Event::REGION => 'QC',
			Event::POSTAL => 'G1K 3A9',
			Event::SIGNUP => 'https://example.org/inscription',
		),
		array( 'post_excerpt' => 'Deux heures; une pause, des questions.' )
	);
	$fluffless_past   = $fluffless_event( 'Atelier passé', array( Event::START => $fluffless_day( -5 )->format( 'Y-m-d 10:00' ) ) );
	$fluffless_old    = $fluffless_event( 'Très ancien', array( Event::START => $fluffless_day( -200 )->format( 'Y-m-d' ) ) );
	$fluffless_secret = $fluffless_event( 'Secret', array( Event::START => $fluffless_day( 4 )->format( 'Y-m-d 10:00' ) ), array( 'post_password' => 'abc' ) );
	// Début à minuit : « il y a une minute » changerait de jour (donc d'échéance) dans la minute qui suit minuit.
	$fluffless_begun = $fluffless_event( 'Commencé sans fin', array( Event::START => $fluffless_now->format( 'Y-m-d 00:00' ) ) );
	$fluffless_e     = Event::from_post( $fluffless_begun );
	$fluffless_assert( null !== $fluffless_e && ! $fluffless_e->is_past( $fluffless_now ), 'commencé sans heure de fin : pas encore passé' );
	$fluffless_ids    = static fn( array $vars ): array => wp_parse_id_list(
		get_posts(
			Events::query_vars(
				$vars + array(
					'post_type'      => Event::TYPE,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				),
				false
			)
		)
	);
	$fluffless_coming = $fluffless_ids( array() );
	$fluffless_assert( in_array( $fluffless_up, $fluffless_coming, true ) && ! in_array( $fluffless_past, $fluffless_coming, true ), 'à venir : sans les passés' );
	$fluffless_assert( ! in_array( $fluffless_secret, $fluffless_coming, true ), 'à venir : sans contenu protégé' );
	$fluffless_pos = array_flip( $fluffless_coming );
	$fluffless_assert( isset( $fluffless_pos[ $fluffless_up ], $fluffless_pos[ $fluffless_moved ] ) && $fluffless_pos[ $fluffless_up ] < $fluffless_pos[ $fluffless_moved ], 'à venir : par début croissant' );
	$fluffless_gone = wp_parse_id_list(
		get_posts(
			Events::query_vars(
				array(
					'post_type'      => Event::TYPE,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				),
				true
			)
		)
	);
	$fluffless_assert( in_array( $fluffless_past, $fluffless_gone, true ) && ! in_array( $fluffless_up, $fluffless_gone, true ), 'passés : sans ceux à venir' );
	$fluffless_pos = array_flip( $fluffless_gone );
	$fluffless_assert( isset( $fluffless_pos[ $fluffless_past ], $fluffless_pos[ $fluffless_old ] ) && $fluffless_pos[ $fluffless_past ] < $fluffless_pos[ $fluffless_old ], 'passés : du plus récent au plus ancien' );
	$fluffless_vars = Events::query_vars(
		array(
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- test.
				array(
					'key'   => Event::CITY,
					'value' => 'Québec',
				),
			),
		),
		false
	);
	$fluffless_assert( isset( $fluffless_vars['meta_query']['fluffless_original'] ) && false === $fluffless_vars['has_password'], 'filtre d\'origine gardé, mot de passe exclu' );
	$fluffless_assert( array( 'post_type' => 'post' ) === Events::loop_query( array( 'post_type' => 'post' ) ), 'Query Loop d\'un autre type : intacte' );
	Events::enter_query(
		array(
			'blockName' => 'core/query',
			'attrs'     => array( 'className' => 'alignwide ' . Events::PAST_CLASS ),
		)
	);
	$fluffless_loop = Events::loop_query( array( 'post_type' => Event::TYPE ) );
	Events::render_block( '', array( 'blockName' => 'core/query' ) );
	$fluffless_assert( '<' === ( $fluffless_loop['meta_query']['fluffless_until']['compare'] ?? '' ) && false === $fluffless_loop['has_password'], 'Query Loop avec is-past-events : passés' );
	$fluffless_loop = Events::loop_query( array( 'post_type' => Event::TYPE ) );
	$fluffless_assert( '>=' === ( $fluffless_loop['meta_query']['fluffless_until']['compare'] ?? '' ), 'pile vidée : retour aux événements à venir' );
	$fluffless_feed = array_map( static fn( Event $e ): int => $e->id, Events::feed_events() );
	$fluffless_assert( in_array( $fluffless_up, $fluffless_feed, true ) && in_array( $fluffless_past, $fluffless_feed, true ) && ! in_array( $fluffless_old, $fluffless_feed, true ) && ! in_array( $fluffless_secret, $fluffless_feed, true ), 'flux : à venir et 90 derniers jours, sans protégé' );
	$fluffless_pos = array_flip( $fluffless_feed );
	$fluffless_assert( isset( $fluffless_pos[ $fluffless_past ], $fluffless_pos[ $fluffless_begun ], $fluffless_pos[ $fluffless_up ] ) && $fluffless_pos[ $fluffless_past ] < $fluffless_pos[ $fluffless_begun ] && $fluffless_pos[ $fluffless_begun ] < $fluffless_pos[ $fluffless_up ] && count( $fluffless_feed ) === count( array_unique( $fluffless_feed ) ), 'flux : passés puis en cours puis à venir, sans doublon' );
	$fluffless_assert( false === ( Events::sitemap_query( array(), Event::TYPE )['has_password'] ?? null ) && array() === Events::sitemap_query( array(), 'post' ), 'plan du site : sans protégé, autres types intacts' );
	$fluffless_search        = new WP_Query();
	$GLOBALS['wp_the_query'] = $fluffless_search; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulation d'une visite.
	$fluffless_found         = wp_parse_id_list(
		$fluffless_search->query(
			array(
				's'         => 'Secret',
				'post_type' => Event::TYPE,
				'fields'    => 'ids',
			)
		)
	);
	$fluffless_assert( ! in_array( $fluffless_secret, $fluffless_found, true ), 'recherche : sans protégé' );
	wp_set_current_user( 0 );
	$fluffless_rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Event::TYPE . '/' . $fluffless_secret ) )->get_data();
	$fluffless_assert( is_array( $fluffless_rest ) && array() === ( $fluffless_rest['meta'] ?? null ), 'REST anonyme : protégé sans méta' );
	$fluffless_rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Event::TYPE . '/' . $fluffless_up ) )->get_data();
	$fluffless_assert( is_array( $fluffless_rest ) && 'Café Alphée' === ( $fluffless_rest['meta'][ Event::PLACE ] ?? null ), 'REST anonyme : événement public avec ses métas' );
	$fluffless_list = new WP_REST_Request( 'GET', '/wp/v2/' . Event::TYPE );
	$fluffless_list->set_param( 'per_page', 100 );
	$fluffless_rest = rest_do_request( $fluffless_list )->get_data();
	$fluffless_assert( is_array( $fluffless_rest ) && ! in_array( $fluffless_secret, array_map( static fn( $item ): int => (int) ( $item['id'] ?? 0 ), $fluffless_rest ), true ), 'REST anonyme : liste sans protégé' );

	// 6. Liaisons.
	$fluffless_b = $fluffless_block( $fluffless_up );
	$fluffless_e = Event::from_post( $fluffless_up );
	$fluffless_assert( null !== $fluffless_e && esc_html( $fluffless_e->when() ) === Events::value( array( 'key' => 'when' ), $fluffless_b ), 'liaison when' );
	$fluffless_assert( 'Café Alphée' === Events::value( array( 'key' => 'place' ), $fluffless_b ), 'liaison place' );
	$fluffless_assert( '123, rue Saint-Joseph Est<br>' . "\n" . 'Québec, QC  G1K 3A9' === Events::value( array( 'key' => 'address' ), $fluffless_b ), 'liaison address : ' . (string) Events::value( array( 'key' => 'address' ), $fluffless_b ) );
	$fluffless_assert( 'https://example.org/inscription' === Events::value( array( 'key' => 'signup' ), $fluffless_b, 'url' ), 'liaison signup' );
	$fluffless_assert( null === Events::value( array( 'key' => 'online' ), $fluffless_b, 'url' ) && null === Events::value( array( 'key' => 'status' ), $fluffless_b ), 'valeurs vides : null' );
	$fluffless_assert( Events::event_feed_url( $fluffless_up ) === Events::value( array( 'key' => 'ics' ), $fluffless_b, 'url' ) && str_ends_with( Events::event_feed_url( $fluffless_up ), '/feed/ics/' ), 'liaison ics' );
	$fluffless_assert( Events::feed_url() === Events::value( array( 'key' => 'feed' ), null, 'url' ) && str_starts_with( (string) Events::value( array( 'key' => 'feed' ) ), '<a href="' ), 'liaison feed : adresse ou lien' );
	$fluffless_assert( str_starts_with( (string) Events::value( array( 'key' => 'webcal' ), null, 'url' ), 'webcal://' ), 'liaison webcal' );
	$fluffless_assert( 'https://calendar.google.com/calendar/r?cid=' . rawurlencode( Events::webcal_url() ) === Events::value( array( 'key' => 'google' ), null, 'url' ), 'liaison google' );
	$fluffless_assert( null === Events::value( array( 'key' => 'inconnue' ), $fluffless_b ) && null === Events::value( array(), $fluffless_b ), 'clé inconnue ou absente' );
	$fluffless_assert( null === Events::value( array( 'key' => 'when' ), $fluffless_block( $fluffless_secret ) ) && null === Events::value( array( 'key' => 'ics' ), $fluffless_block( $fluffless_secret ), 'url' ), 'protégé : aucune liaison' );
	$fluffless_assert( null === Events::value( array( 'key' => 'when' ), $fluffless_block( (int) $fluffless_taken ) ), 'autre type : aucune liaison' );
	update_post_meta( $fluffless_past, Event::STATUS, Event::CANCELLED );
	$fluffless_assert( _x( 'Cancelled', 'event status', 'fluffless-suite' ) === Events::value( array( 'key' => 'status' ), $fluffless_block( $fluffless_past ) ), 'statut annulé' );
	delete_post_meta( $fluffless_past, Event::STATUS );
	$fluffless_assert( _x( 'Past', 'event status', 'fluffless-suite' ) === Events::value( array( 'key' => 'status' ), $fluffless_block( $fluffless_past ) ), 'statut passé' );
	update_post_meta( $fluffless_up, 'poste', 'Visible' );
	update_post_meta( $fluffless_secret, 'poste', 'Caché' );
	$fluffless_assert( 'Visible' === Fields::value( array( 'key' => 'poste' ), $fluffless_b ) && null === Fields::value( array( 'key' => 'poste' ), $fluffless_block( $fluffless_secret ) ), 'source fluffless/champ : protégé, rien' );

	// 7. Blocs retirés quand leur liaison est vide.
	$fluffless_bound = static fn( string $name, string $key, string $attr = 'content' ): array => array(
		'blockName' => $name,
		'attrs'     => array(
			'metadata' => array(
				'bindings' => array(
					$attr => array(
						'source' => Events::SOURCE,
						'args'   => array( 'key' => $key ),
					),
				),
			),
		),
	);
	$fluffless_assert( '' === Events::render_block( '<p>x</p>', $fluffless_bound( 'core/paragraph', 'status' ), $fluffless_b ), 'paragraphe lié vide : retiré' );
	$fluffless_assert( '<p>x</p>' === Events::render_block( '<p>x</p>', $fluffless_bound( 'core/paragraph', 'place' ), $fluffless_b ), 'paragraphe lié rempli : gardé' );
	$fluffless_assert( '' === Events::render_block( '<div><a>x</a></div>', $fluffless_bound( 'core/button', 'online', 'url' ), $fluffless_b ), 'bouton lié vide : retiré' );
	$fluffless_assert( '' === Events::render_block( "<div class=\"wp-block-buttons\">\n</div>", array( 'blockName' => 'core/buttons' ) ), 'groupe de boutons vide : retiré' );
	$fluffless_assert( '<div class="wp-block-buttons"><a href="#">x</a></div>' === Events::render_block( '<div class="wp-block-buttons"><a href="#">x</a></div>', array( 'blockName' => 'core/buttons' ) ), 'groupe de boutons rempli : gardé' );
	$fluffless_assert( '<div class="wp-block-buttons"><button aria-label="x"></button></div>' === Events::render_block( '<div class="wp-block-buttons"><button aria-label="x"></button></div>', array( 'blockName' => 'core/buttons' ) ), 'groupe à bouton sans texte : gardé' );
	$fluffless_assert( '<img>' === Events::render_block( '<img>', $fluffless_bound( 'core/image', 'online', 'url' ), $fluffless_b ), 'bloc hors liste : jamais retiré' );
	$fluffless_assert(
		'<p>x</p>' === Events::render_block(
			'<p>x</p>',
			array(
				'blockName' => 'core/paragraph',
				'attrs'     => array( 'metadata' => array( 'bindings' => array( 'content' => array( 'source' => 'core/post-meta' ) ) ) ),
			),
			$fluffless_b
		),
		'autre source : intacte'
	);

	// 8. Fichier .ics.
	$fluffless_e   = Event::from_post( $fluffless_up );
	$fluffless_ics = null === $fluffless_e ? '' : Ics::calendar( array( $fluffless_e ), 'Événements, Essai' );
	$fluffless_assert( str_starts_with( $fluffless_ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" ) && str_ends_with( $fluffless_ics, "END:VCALENDAR\r\n" ) && ! preg_match( "/[^\r]\n/", $fluffless_ics ), '.ics : CRLF partout' );
	$fluffless_assert( str_contains( $fluffless_ics, 'UID:event-' . $fluffless_up . '-' . Ics::site_token() . '@fluffless' ) && 1 === preg_match( '/^[a-z0-9]{12}$/', Ics::site_token() ), '.ics : UID stable' );
	$fluffless_utc = null === $fluffless_e ? '' : $fluffless_e->start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	$fluffless_assert( str_contains( $fluffless_ics, 'DTSTART:' . $fluffless_utc ), '.ics : début en UTC' );
	$fluffless_assert( str_contains( $fluffless_ics, 'LOCATION:Café Alphée\, 123\, rue Saint-Joseph Est\, Québec\, QC  G1K 3A9' ) && str_contains( $fluffless_ics, 'DESCRIPTION:Deux heures\; une pause\, des questions.' ), '.ics : lieu, adresse et échappement' );
	$fluffless_assert( str_contains( $fluffless_ics, 'X-WR-CALNAME:Événements\, Essai' ) && str_contains( $fluffless_ics, 'STATUS:CONFIRMED' ), '.ics : nom et statut' );
	$fluffless_e = Event::from_post( $fluffless_days );
	$fluffless_v = null === $fluffless_e ? array() : Ics::vevent( $fluffless_e );
	$fluffless_assert( in_array( 'DTSTART;VALUE=DATE:20261120', $fluffless_v, true ) && in_array( 'DTEND;VALUE=DATE:20261122', $fluffless_v, true ), '.ics : journée entière, fin exclusive' );
	update_post_meta( $fluffless_days, Event::STATUS, Event::POSTPONED );
	$fluffless_e = Event::from_post( $fluffless_days );
	$fluffless_assert( null !== $fluffless_e && in_array( 'STATUS:TENTATIVE', Ics::vevent( $fluffless_e ), true ), '.ics : reporté en TENTATIVE' );
	update_post_meta( $fluffless_days, Event::STATUS, Event::CANCELLED );
	$fluffless_e = Event::from_post( $fluffless_days );
	$fluffless_assert( null !== $fluffless_e && in_array( 'STATUS:CANCELLED', Ics::vevent( $fluffless_e ), true ) && 1 <= $fluffless_e->sequence, '.ics : annulé, nouvelle version' );
	$fluffless_long = 'SUMMARY:' . str_repeat( 'é', 60 );
	$fluffless_fold = Ics::fold( $fluffless_long );
	$fluffless_ok   = str_replace( "\r\n ", '', $fluffless_fold ) === $fluffless_long;
	foreach ( explode( "\r\n", $fluffless_fold ) as $fluffless_line ) {
		$fluffless_ok = $fluffless_ok && strlen( $fluffless_line ) <= 75 && mb_check_encoding( $fluffless_line, 'UTF-8' );
	}
	$fluffless_assert( $fluffless_ok && str_contains( $fluffless_fold, "\r\n " ), '.ics : pliage à 75 octets sans couper un caractère' );
	$fluffless_assert( 'a\\\\b\;c\,d\ne' === Ics::text( "a\\b;c,d\r\ne\x07" ) && 'https://x.org/a' === Ics::uri( "https://x.org/a\r\n" ), '.ics : TEXT et URI' );

	// 9. Flux selon la requête : un événement, l'archive, 404 ailleurs et pour un protégé.
	$fluffless_main(
		array(
			'post_type' => Event::TYPE,
			'p'         => $fluffless_up,
		)
	);
	$fluffless_pay = Events::feed_payload();
	$fluffless_assert( is_array( $fluffless_pay ) && str_ends_with( $fluffless_pay['filename'], '.ics' ) && 1 === substr_count( $fluffless_pay['body'], 'BEGIN:VEVENT' ), 'flux d\'un événement : un VEVENT, un nom de fichier' );
	$fluffless_main(
		array(
			'post_type' => Event::TYPE,
			'p'         => $fluffless_secret,
		)
	);
	$fluffless_assert( null === Events::feed_payload(), 'flux d\'un protégé : 404' );
	$fluffless_main( array( 'post_type' => Event::TYPE ) );
	$fluffless_pay = Events::feed_payload();
	$fluffless_assert( is_array( $fluffless_pay ) && '' === $fluffless_pay['filename'] && str_contains( $fluffless_pay['body'], 'X-WR-CALNAME:' ) && ! str_contains( $fluffless_pay['body'], 'SUMMARY:Secret' ), 'flux de l\'archive : calendrier nommé, sans protégé' );
	$fluffless_main( array( 'page_id' => (int) $fluffless_taken ) );
	$fluffless_assert( null === Events::feed_payload(), 'flux ailleurs : 404' );
	$fluffless_assert( 'text/calendar' === Events::content_type( 'application/rss+xml', 'ics' ) && 'application/rss+xml' === Events::content_type( 'application/rss+xml', 'rss2' ), 'type de contenu du flux' );

	// 10. JSON-LD.
	$fluffless_s = Events::schema( $fluffless_up );
	if ( Events::seo_decides( $fluffless_up ) ) {
		$fluffless_assert( null === $fluffless_s, 'Fluffless SEO décide : pas de nœud' );
	} else {
		$fluffless_assert( is_array( $fluffless_s ) && 'Event' === $fluffless_s['@type'] && 'https://schema.org/EventScheduled' === $fluffless_s['eventStatus'] && 'https://schema.org/OfflineEventAttendanceMode' === $fluffless_s['eventAttendanceMode'], 'schéma : prévu, en personne' );
		$fluffless_assert( is_array( $fluffless_s ) && 'PostalAddress' === ( $fluffless_s['location']['address']['@type'] ?? '' ) && 'CA' === ( $fluffless_s['location']['address']['addressCountry'] ?? '' ) && 'G1K 3A9' === ( $fluffless_s['location']['address']['postalCode'] ?? '' ), 'schéma : adresse postale, pays par défaut' );
		$fluffless_assert( is_array( $fluffless_s ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T18:30:00[+-]\d{2}:\d{2}$/', (string) $fluffless_s['startDate'] ) && ! isset( $fluffless_s['previousStartDate'] ), 'schéma : début avec décalage, sans ancien début' );
		$fluffless_assert( is_array( $fluffless_s ) && isset( $fluffless_s['organizer'] ) && ! isset( $fluffless_s['image'] ), 'schéma : organisateur, pas d\'image vide' );
		update_post_meta( $fluffless_up, Event::ONLINE, 'https://example.org/direct' );
		$fluffless_s = Events::schema( $fluffless_up );
		$fluffless_assert( is_array( $fluffless_s ) && 'https://schema.org/MixedEventAttendanceMode' === $fluffless_s['eventAttendanceMode'] && 2 === count( $fluffless_s['location'] ) && 'VirtualLocation' === ( $fluffless_s['location'][1]['@type'] ?? '' ), 'schéma : présence mixte, deux lieux' );
		$fluffless_s = Events::schema( $fluffless_days );
		$fluffless_assert( is_array( $fluffless_s ) && 'https://schema.org/EventCancelled' === $fluffless_s['eventStatus'] && '2026-11-20' === $fluffless_s['startDate'] && ! isset( $fluffless_s['location'] ), 'schéma : annulé, journée entière, sans lieu' );
		$fluffless_s = Events::schema( $fluffless_moved );
		$fluffless_assert( is_array( $fluffless_s ) && 'https://schema.org/EventScheduled' === $fluffless_s['eventStatus'], 'schéma : revenu à sa date, prévu' );
		update_post_meta( $fluffless_moved, Event::START, $fluffless_day( 40 )->format( 'Y-m-d 10:00' ) );
		$fluffless_s = Events::schema( $fluffless_moved );
		$fluffless_assert( is_array( $fluffless_s ) && 'https://schema.org/EventRescheduled' === $fluffless_s['eventStatus'] && str_starts_with( (string) ( $fluffless_s['previousStartDate'] ?? '' ), $fluffless_now->modify( '+30 days' )->format( 'Y-m-d' ) ), 'schéma : déplacé, ancien début' );
	}
	$fluffless_assert( null === Events::schema( $fluffless_secret ) && null === Events::schema( (int) $fluffless_taken ), 'schéma : rien pour un protégé ou un autre type' );
	if ( ! defined( 'FLUFFLESS_SEO_VERSION' ) ) {
		$fluffless_assert( false === Events::seo_decides( $fluffless_up ), 'sans Fluffless SEO : le module décide' );
	}

	// 11. Robots.
	unset( $_GET[ Events::MONTH_ARG ] );
	$fluffless_assert( array( 'max-image-preview' => 'large' ) === Events::robots( array( 'max-image-preview' => 'large' ) ), 'robots : intacts sans mois' );
	$_GET[ Events::MONTH_ARG ] = '2026-11';
	$fluffless_r               = Events::robots( array() );
	$fluffless_assert( true === ( $fluffless_r['noindex'] ?? null ) && true === ( $fluffless_r['follow'] ?? null ), 'robots : autre mois, noindex follow' );

	// 12. Calendrier : mois demandé.
	$fluffless_ref = new DateTimeImmutable( '2026-10-09 14:00', $fluffless_tz );
	$fluffless_assert( '2026-10-01 00:00' === Calendrier::month( null, $fluffless_ref )->format( 'Y-m-d H:i' ), 'mois : courant par défaut' );
	$fluffless_assert( '2026-12-01' === Calendrier::month( '2026-12', $fluffless_ref )->format( 'Y-m-d' ) && '2028-10-01' === Calendrier::month( '2028-10', $fluffless_ref )->format( 'Y-m-d' ), 'mois : demandé, borne comprise' );
	foreach ( array( '2028-11', '2024-09', '2026-13', '2026-00', '2026-1', 'x', array( '2026-11' ) ) as $fluffless_bad ) {
		$fluffless_assert( '2026-10-01' === Calendrier::month( $fluffless_bad, $fluffless_ref )->format( 'Y-m-d' ), 'mois refusé : ' . wp_json_encode( $fluffless_bad ) );
	}
	$fluffless_assert( 13 === Calendrier::gap( new DateTimeImmutable( '2026-12-01' ), new DateTimeImmutable( '2028-01-01' ) ), 'écart en mois' );
	$fluffless_m    = new DateTimeImmutable( '2026-11-01', $fluffless_tz );
	$fluffless_grid = Calendrier::days( $fluffless_m );
	$fluffless_assert( 30 === count( $fluffless_grid ) && array_key_first( $fluffless_grid ) === '2026-11-01', 'novembre : 30 jours' );
	$fluffless_on = static fn( string $ymd ): array => array_map( static fn( Event $e ): int => $e->id, $fluffless_grid[ $ymd ] ?? array() );
	$fluffless_assert( in_array( $fluffless_late, $fluffless_on( '2026-11-14' ), true ) && in_array( $fluffless_late, $fluffless_on( '2026-11-15' ), true ), 'soirée : deux jours du calendrier' );
	$fluffless_assert( in_array( $fluffless_days, $fluffless_on( '2026-11-21' ), true ) && ! in_array( $fluffless_days, $fluffless_on( '2026-11-22' ), true ), 'deux jours : pas un de plus' );
	$fluffless_assert( 29 === count( Calendrier::days( new DateTimeImmutable( '2028-02-01', $fluffless_tz ) ) ), 'février bissextile' );

	// 13. Calendrier : rendu.
	$fluffless_week = get_option( 'start_of_week' );
	update_option( 'start_of_week', 1 );
	$_GET[ Events::MONTH_ARG ] = '2026-11';
	$fluffless_html            = Calendrier::render( array( 'level' => 3 ) );
	$fluffless_assert( 1 === preg_match( '/<section class="fl-calendrier" aria-labelledby="(fl-cal-\d+)"><h3 id="\1" class="fl-cal-title">/', $fluffless_html ), 'rendu : section nommée par son titre h3' );
	// Classes voisines possibles (has-events quand une fixture relative à aujourd'hui tombe le 1er).
	$fluffless_assert( 1 === preg_match( '/<li class="fl-cal-c7(?: [^"]*)?"/', $fluffless_html ), 'rendu : 1er novembre 2026 (dimanche) en 7e colonne' );
	update_option( 'start_of_week', 0 );
	$fluffless_assert( ! str_contains( Calendrier::render( array() ), 'fl-cal-c' ), 'semaine commençant le dimanche : 1er novembre en 1re colonne' );
	update_option( 'start_of_week', $fluffless_week );
	// Novembre 2026 contient aujourd'hui pendant ce mois-là : un seul jour marqué, sinon aucun.
	$fluffless_today = '2026-11' === $fluffless_now->format( 'Y-m' ) ? 1 : 0;
	$fluffless_marks = substr_count( $fluffless_html, 'aria-current' );
	$fluffless_assert( 30 === substr_count( $fluffless_html, 'class="fl-cal-date"' ) && $fluffless_marks === $fluffless_today, 'rendu : 30 jours, aujourd\'hui marqué seulement en novembre 2026' );
	$fluffless_assert( str_contains( $fluffless_html, 'Soirée</a>' ) && ! str_contains( $fluffless_html, 'Secret' ) && ! str_contains( $fluffless_html, 'fl-cal-empty' ), 'rendu : événements du mois, sans protégé' );
	$fluffless_assert( str_contains( $fluffless_html, '(' . esc_html( _x( 'Cancelled', 'event status', 'fluffless-suite' ) ) . ')' ), 'rendu : statut annulé' );
	$fluffless_assert( 2 === substr_count( $fluffless_html, 'rel="nofollow"' ) && str_contains( $fluffless_html, Events::MONTH_ARG . '=2026-12' ), 'rendu : mois voisins en liens nofollow' );
	$_GET[ Events::MONTH_ARG ] = $fluffless_now->modify( 'first day of this month' )->modify( '+' . Calendrier::RANGE . ' months' )->format( 'Y-m' );
	$fluffless_html            = Calendrier::render( array( 'level' => 9 ) );
	$fluffless_assert( str_contains( $fluffless_html, '<h2 ' ) && str_contains( $fluffless_html, 'fl-cal-empty' ) && ! str_contains( $fluffless_html, 'fl-cal-next' ) && str_contains( $fluffless_html, 'fl-cal-prev' ), 'rendu : niveau borné, mois vide, pas de lien au-delà de la borne' );
	unset( $_GET[ Events::MONTH_ARG ] );
	$fluffless_html = Calendrier::render( array() );
	$fluffless_assert( 1 === substr_count( $fluffless_html, 'aria-current="date"' ), 'rendu : aujourd\'hui marqué' );
	$fluffless_head = 1 === preg_match( '#<div class="fl-cal-head" aria-hidden="true">(.*?)</div>#', $fluffless_html, $fluffless_match ) ? $fluffless_match[1] : '';
	$fluffless_assert( 7 === substr_count( $fluffless_head, '<span>' ), 'rendu : sept jours de semaine masqués aux lecteurs d\'écran' );

	// 14. Calendrier : CSS.
	$fluffless_css = Calendrier::css();
	$fluffless_assert( strlen( $fluffless_css ) <= 1536, 'CSS du calendrier : ' . strlen( $fluffless_css ) . ' o, budget 1536' );
	$fluffless_assert( 0 === preg_match( '/#[0-9a-f]{3,8}\b|(?<!s)rgba?\(|hsla?\(|url\(|animation|transition|position:\s*fixed|!important/i', $fluffless_css ), 'CSS du calendrier : ni couleur codée, ni url, ni animation, ni fixed' );
	$fluffless_assert( str_contains( $fluffless_css, '@media (forced-colors:active)' ) && str_contains( $fluffless_css, '@container (max-width:36rem)' ), 'CSS du calendrier : couleurs forcées et vue liste' );
	$fluffless_assert( substr_count( $fluffless_css, '{' ) === substr_count( $fluffless_css, '}' ), 'CSS du calendrier : accolades équilibrées' );

	// 15. Bloc enregistré, gabarits.
	$fluffless_assert( WP_Block_Type_Registry::get_instance()->is_registered( Calendrier::NAME ), 'bloc fluffless/calendrier enregistré' );
	$fluffless_json = wp_json_file_decode( dirname( __DIR__ ) . '/blocks/calendrier/block.json', array( 'associative' => true ) );
	$fluffless_assert( Calendrier::LEVELS === ( $fluffless_json['attributes']['level']['enum'] ?? null ), 'block.json : niveaux alignés' );
	foreach ( Events::TEMPLATES as $fluffless_t ) {
		$fluffless_raw = (string) file_get_contents( dirname( __DIR__ ) . '/templates/' . $fluffless_t . '.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
		$fluffless_assert( '' !== $fluffless_raw && 0 === preg_match( '/"key":"(?!(' . implode( '|', Events::KEYS ) . ')")/', $fluffless_raw ), "gabarit {$fluffless_t} : clés de liaison connues" );
	}
} finally {
	wp_set_current_user( $fluffless_user_was );
	foreach ( $fluffless_posts as $fluffless_id ) {
		wp_delete_post( $fluffless_id, true );
	}
	if ( false === $fluffless_slug_was ) {
		delete_option( Events::SLUG_OPTION );
	} else {
		update_option( Events::SLUG_OPTION, $fluffless_slug_was );
	}
	if ( false === $fluffless_lang_was ) {
		delete_option( 'WPLANG' );
	} else {
		update_option( 'WPLANG', $fluffless_lang_was );
	}
	$_GET                    = $fluffless_get_was;
	$GLOBALS['wp_query']     = $fluffless_query_was; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restauration après le test.
	$GLOBALS['wp_the_query'] = $fluffless_the_was; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restauration après le test.
	$GLOBALS['post']         = $fluffless_post_was; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restauration après le test.
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Événements : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Événements : {$fluffless_checks} vérifications passées." );
