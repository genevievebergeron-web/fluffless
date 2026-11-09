<?php
/**
 * Module Éditeur de pages : écran de départ, questionnaire qui écrit la page, bibliothèque
 * « Ajouter une section » et mode Simple ou Complet choisi par chaque personne.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Icon;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Lifecycle;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Presentation\Patterns;
use WP_Block_Editor_Context;
use WP_Block_Patterns_Registry;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Tout se passe dans l'éditeur de pages (type page) : 0 octet côté visiteur.
 *
 * - Écran de départ : sur une page neuve et vide, il remplace la fenêtre native « Choisir une
 *   composition ». Celle-ci ne s'ouvre que s'il existe des compositions de départ (block_types
 *   core/post-content) ; la route REST des compositions les retire de ce rôle tant que le module
 *   est actif. Désactiver le module rend la fenêtre native, sans préférence modifiée.
 * - Questionnaire : les réponses remplissent les blocs repérés par metadata.name (voir questions()).
 *   Ajouter une question = nommer un bloc dans la composition et ajouter une entrée dans questions().
 * - Mode Simple (défaut) ou Complet, par personne (métadonnée MODE_META). En Simple, les sections
 *   insérées sont verrouillées en contentOnly par le JS et les réglages fins sont rangés par
 *   block_editor_settings_all. Le mode Studio du module Forme l'emporte : rien n'est rangé.
 */
final class Editor implements Module {

	public const MODE_META = Lifecycle::USER_META_EDITOR_MODE;
	public const SIMPLE    = 'simple';
	public const FULL      = 'full';

	/** Catégories de la bibliothèque « Ajouter une section », dans l'ordre affiché. */
	public const LIBRARY = array( 'fluffless-ouvertures', 'fluffless-presenter', 'fluffless-raconter', 'fluffless-prouver', 'fluffless-agir', 'fluffless-structure' );

	/**
	 * Compositions de pages proposées sur l'écran de départ, dans l'ordre des cartes : les quatre du thème,
	 * puis les huit de la Suite ; sept ont un questionnaire (voir questions()). Les gabarits enregistrés par
	 * l'agence (Editor\Templates) s'ajoutent à la suite.
	 */
	public const PAGES = array(
		'fluffless/page-accueil',
		'fluffless/page-a-propos',
		'fluffless/page-services',
		'fluffless/page-contact',
		'fluffless/page-don',
		'fluffless/page-tarifs',
		'fluffless/page-equipe',
		'fluffless/page-vente',
		'fluffless/page-portfolio',
		'fluffless/page-pilier',
		'fluffless/page-contact-rdv',
		'fluffless/page-etude-de-cas',
	);

	/** Gabarit pleine largeur sans titre du thème : les compositions de pages portent déjà leur h1. */
	public const FULL_TEMPLATE = 'page-sections';

	/** Icône de chaque carte de l'écran de départ (pixelarticons). */
	private const PAGE_ICONS = array(
		'fluffless/page-accueil'      => 'home',
		'fluffless/page-a-propos'     => 'user',
		'fluffless/page-services'     => 'briefcase',
		'fluffless/page-contact'      => 'mail',
		'fluffless/page-don'          => 'heart',
		'fluffless/page-tarifs'       => 'coins',
		'fluffless/page-equipe'       => 'users',
		'fluffless/page-vente'        => 'target',
		'fluffless/page-portfolio'    => 'images',
		'fluffless/page-pilier'       => 'book-open',
		'fluffless/page-contact-rdv'  => 'message',
		'fluffless/page-etude-de-cas' => 'chart-line',
	);

	/** Icône de chaque catégorie de la bibliothèque. */
	private const LIBRARY_ICONS = array(
		'fluffless-ouvertures' => 'flag',
		'fluffless-presenter'  => 'article',
		'fluffless-raconter'   => 'book-open',
		'fluffless-prouver'    => 'trophy',
		'fluffless-agir'       => 'zap',
		'fluffless-structure'  => 'grid-3x3',
	);

	private const POST_TYPE    = 'page';
	private const ROUTE        = '/wp/v2/block-patterns/patterns';
	private const POST_CONTENT = 'core/post-content';

	/** Réglages de typographie fine rangés en mode Simple. */
	private const FINE_TYPOGRAPHY = array( 'customFontSize', 'dropCap', 'fontStyle', 'fontWeight', 'letterSpacing', 'lineHeight', 'textColumns', 'textDecoration', 'textIndent', 'textTransform', 'writingMode' );

	/** Identifiant stable. */
	public static function id(): string {
		return 'editor';
	}

	/** Nom affiché. */
	public static function label(): string {
		return __( 'Page editor', 'fluffless-suite' );
	}

	/** Ce que le module change. */
	public static function description(): string {
		return __( 'A welcome screen for new pages, a short questionnaire that writes the first draft, an "Add a section" library, and a simple editing mode each person can switch off.', 'fluffless-suite' );
	}

	/** Actif sur une installation neuve : éditeur de pages seulement. */
	public static function enabled_by_default(): bool {
		return true;
	}

	/**
	 * Aucun réglage : le mode se choisit dans l'éditeur, par personne.
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
		add_action( 'init', array( self::class, 'register_meta' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_action( 'enqueue_block_assets', array( self::class, 'canvas_assets' ) );
		// Après le mode Studio (priorité 20) : Studio l'emporte de toute façon, voir filter_settings().
		add_filter( 'block_editor_settings_all', array( self::class, 'filter_settings' ), 30, 2 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'hide_native_starter' ), 10, 3 );
		Editor\Templates::register();
	}

	/* ---------- Mode par personne ---------- */

	/** Préférence lisible et modifiable par la personne elle-même, via /wp/v2/users/me. */
	public static function register_meta(): void {
		register_meta(
			'user',
			self::MODE_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => self::SIMPLE,
				'sanitize_callback' => array( self::class, 'sanitize_mode' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $user_id ): bool {
					unset( $allowed, $meta_key );
					return current_user_can( 'edit_user', (int) $user_id );
				},
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( self::SIMPLE, self::FULL ),
					),
				),
			)
		);
	}

	/**
	 * Valeur de mode valide : toute valeur inconnue revient à Simple.
	 *
	 * @param mixed $value Valeur brute.
	 */
	public static function sanitize_mode( $value ): string {
		return self::FULL === $value ? self::FULL : self::SIMPLE;
	}

	/**
	 * Mode de la personne (défaut : courante).
	 *
	 * @param int $user_id Identifiant, 0 pour la personne courante.
	 */
	public static function mode( int $user_id = 0 ): string {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		return $user_id > 0 ? self::sanitize_mode( get_user_meta( $user_id, self::MODE_META, true ) ) : self::SIMPLE;
	}

	/**
	 * Interface simplifiée pour cette personne ? Simple, et pas en mode Studio (module Forme).
	 *
	 * @param int $user_id Identifiant, 0 pour la personne courante.
	 */
	public static function is_simple( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		return self::SIMPLE === self::mode( $user_id ) && '1' !== get_user_meta( $user_id, Lifecycle::USER_META_STUDIO, true );
	}

	/* ---------- Réglages de l'éditeur ---------- */

	/**
	 * Range les réglages avancés en mode Simple, dans l'éditeur de pages seulement.
	 *
	 * @param mixed $settings Réglages de l'éditeur.
	 * @param mixed $context  Contexte de l'éditeur.
	 * @return mixed
	 */
	public static function filter_settings( $settings, $context ) {
		if ( ! is_array( $settings ) || ! $context instanceof WP_Block_Editor_Context || ! $context->post instanceof WP_Post || self::POST_TYPE !== $context->post->post_type || ! self::is_simple() ) {
			return $settings;
		}
		return self::simplify( $settings );
	}

	/**
	 * Typographie fine, tailles et espacements libres, éditeur de code : rangés. Les préréglages
	 * du thème (tailles, espacements, couleurs) restent. Le CSS additionnel des blocs (canEditCSS)
	 * est posé par WordPress après ce filtre : le JS le range.
	 *
	 * @param array<mixed> $settings Réglages de l'éditeur.
	 * @return array<mixed>
	 */
	public static function simplify( array $settings ): array {
		$features   = is_array( $settings['__experimentalFeatures'] ?? null ) ? $settings['__experimentalFeatures'] : array();
		$typography = is_array( $features['typography'] ?? null ) ? $features['typography'] : array();
		$spacing    = is_array( $features['spacing'] ?? null ) ? $features['spacing'] : array();
		foreach ( self::FINE_TYPOGRAPHY as $key ) {
			$typography[ $key ] = false;
		}
		$spacing['customSpacingSize'] = false;

		$features['typography']                = $typography;
		$features['spacing']                   = $spacing;
		$settings['__experimentalFeatures']    = $features;
		$settings['disableCustomFontSizes']    = true;
		$settings['disableCustomSpacingSizes'] = true;
		$settings['enableCustomLineHeight']    = false;
		$settings['codeEditingEnabled']        = false;
		return $settings;
	}

	/* ---------- Fenêtre native de départ ---------- */

	/**
	 * Retire le rôle « composition de départ » des compositions de pages dans la réponse REST,
	 * pour que la fenêtre native ne s'ouvre pas par-dessus l'écran de départ du module.
	 *
	 * @param mixed           $response Réponse de la route.
	 * @param mixed           $handler  Gestionnaire (inutilisé).
	 * @param WP_REST_Request $request  Requête.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function hide_native_starter( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $response instanceof WP_REST_Response || self::ROUTE !== $request->get_route() || ! current_user_can( 'edit_pages' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$response->set_data( self::strip_starters( $data ) );
		}
		return $response;
	}

	/**
	 * Fonction pure. Page seule (ou aucun type, que WordPress lit comme page) : le bloc
	 * core/post-content est retiré de block_types. Page parmi d'autres types : la page est retirée
	 * de post_types, la composition reste de départ pour les autres types.
	 *
	 * @param array<mixed> $patterns Compositions de la route REST.
	 * @return array<mixed>
	 */
	public static function strip_starters( array $patterns ): array {
		foreach ( $patterns as $i => $pattern ) {
			if ( ! is_array( $pattern ) || ! in_array( self::POST_CONTENT, (array) ( $pattern['block_types'] ?? array() ), true ) ) {
				continue;
			}
			$types = array_values( (array) ( $pattern['post_types'] ?? array() ) );
			if ( array() === $types || array( self::POST_TYPE ) === $types ) {
				$patterns[ $i ]['block_types'] = array_values( array_diff( (array) $pattern['block_types'], array( self::POST_CONTENT ) ) );
			} elseif ( in_array( self::POST_TYPE, $types, true ) ) {
				$patterns[ $i ]['post_types'] = array_values( array_diff( $types, array( self::POST_TYPE ) ) );
			}
		}
		return $patterns;
	}

	/* ---------- Scripts et données ---------- */

	/** Éditeur d'une page, par une personne qui peut modifier les pages ? */
	private static function is_page_editor(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen && 'post' === $screen->base && self::POST_TYPE === $screen->post_type && current_user_can( 'edit_pages' );
	}

	/** Scripts de l'éditeur de pages. */
	public static function editor_assets(): void {
		if ( ! self::is_page_editor() ) {
			return;
		}
		$deps = array( 'fluffless-editor-core', 'wp-a11y', 'wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-data', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-notices', 'wp-plugins', 'wp-url' );
		wp_enqueue_script( 'fluffless-editor-core', plugins_url( 'assets/editor/editor-core.js', FILE ), array(), VERSION, true );
		wp_enqueue_script( 'fluffless-editor', plugins_url( 'assets/editor/editor.js', FILE ), $deps, VERSION, true );
		wp_add_inline_script( 'fluffless-editor', 'window.flufflessEditor=' . wp_json_encode( self::editor_data() ) . ';', 'before' );
		wp_enqueue_style( 'fluffless-editor', plugins_url( 'assets/editor/editor.css', FILE ), array(), VERSION );
	}

	/** Même feuille dans le canevas (iframe) de l'éditeur, pour les boutons « Ajouter une section ». */
	public static function canvas_assets(): void {
		if ( is_admin() && self::is_page_editor() ) {
			wp_enqueue_style( 'fluffless-editor', plugins_url( 'assets/editor/editor.css', FILE ), array(), VERSION );
		}
	}

	/**
	 * Données et textes de l'éditeur.
	 *
	 * @return array<string, mixed>
	 */
	public static function editor_data(): array {
		$categories = array_intersect_key( Patterns::categories(), array_flip( self::LIBRARY ) );
		$ordered    = array();
		foreach ( self::LIBRARY as $slug ) {
			if ( isset( $categories[ $slug ] ) ) {
				$ordered[ $slug ] = $categories[ $slug ];
			}
		}
		return array(
			'meta'       => self::MODE_META,
			'mode'       => self::mode(),
			'simple'     => self::is_simple(),
			'pages'      => self::pages(),
			'categories' => $ordered,
			'pageIcons'  => self::PAGE_ICONS,
			'catIcons'   => self::LIBRARY_ICONS,
			// Gabarit pleine largeur sans titre du thème : les compositions de pages portent déjà leur h1.
			'fullTpl'    => array_key_exists( self::FULL_TEMPLATE, wp_get_theme()->get_page_templates( null, 'page' ) ) ? self::FULL_TEMPLATE : '',
			'icons'      => Icon::paths( array_merge( array_values( self::PAGE_ICONS ), array_values( self::LIBRARY_ICONS ), array( 'file', 'plus', 'unlock', 'layout-solid', 'eye', 'pen-square', 'clipboard-note' ) ) ),
			'home'       => home_url( '/' ),
			'templates'  => array( 'save' => current_user_can( SettingsPage::CAPABILITY ) ),
			'locked'     => self::locked(),
			'i18n'       => self::strings(),
		);
	}

	/**
	 * Sections de la bibliothèque retenues par un module inactif : une tuile par module en fin de
	 * bibliothèque, avec le lien vers son interrupteur (C27). Les personnes sans droit sur les modules
	 * ne voient rien : la tuile ne mènerait nulle part.
	 *
	 * @return list<array{count: int, label: string, url: string}>
	 */
	public static function locked(): array {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return array();
		}
		$modules = Loader::modules();
		$locked  = array();
		foreach ( Patterns::hidden( self::LIBRARY ) as $id => $count ) {
			if ( ! isset( $modules[ $id ] ) ) {
				continue;
			}
			$locked[] = array(
				'count' => $count,
				'label' => $modules[ $id ]::label(),
				'url'   => SettingsPage::url( 'fl-title-' . $id ),
			);
		}
		return $locked;
	}

	/**
	 * Cartes de l'écran de départ : compositions de pages enregistrées (thème Fluffless actif), puis
	 * gabarits enregistrés par l'agence. Le contenu n'est pas repris : l'éditeur le charge déjà par la
	 * route REST des compositions (core-data).
	 *
	 * @return list<array{name: string, id?: string, title: string, description: string, icon: string, saved: bool, questions: list<array{name: string, label: string, example: string, long: bool}>}>
	 */
	public static function pages(): array {
		$registry  = WP_Block_Patterns_Registry::get_instance();
		$titles    = self::titles();
		$questions = self::questions();
		$cards     = array();
		foreach ( self::PAGES as $name ) {
			$pattern = $registry->get_registered( $name );
			if ( ! is_array( $pattern ) ) {
				continue;
			}
			$cards[] = array(
				'name'        => $name,
				'title'       => $titles[ $name ] ?? ( is_string( $pattern['title'] ?? null ) ? $pattern['title'] : $name ),
				'description' => is_string( $pattern['description'] ?? null ) ? $pattern['description'] : '',
				'icon'        => self::PAGE_ICONS[ $name ],
				'saved'       => false,
				'questions'   => $questions[ $name ] ?? array(),
			);
		}
		foreach ( Editor\Templates::all() as $template ) {
			if ( is_array( $registry->get_registered( Editor\Templates::pattern_name( $template['id'] ) ) ) ) {
				$cards[] = Editor\Templates::card( $template );
			}
		}
		return $cards;
	}

	/**
	 * Titres courts des cartes.
	 *
	 * @return array<string, string>
	 */
	private static function titles(): array {
		return array(
			'fluffless/page-accueil'      => __( 'Home', 'fluffless-suite' ),
			'fluffless/page-a-propos'     => __( 'About', 'fluffless-suite' ),
			'fluffless/page-services'     => __( 'Services', 'fluffless-suite' ),
			'fluffless/page-contact'      => __( 'Contact', 'fluffless-suite' ),
			'fluffless/page-don'          => __( 'Donate', 'fluffless-suite' ),
			'fluffless/page-tarifs'       => __( 'Pricing', 'fluffless-suite' ),
			'fluffless/page-equipe'       => __( 'Team', 'fluffless-suite' ),
			'fluffless/page-vente'        => __( 'Sales page', 'fluffless-suite' ),
			'fluffless/page-portfolio'    => __( 'Portfolio', 'fluffless-suite' ),
			'fluffless/page-pilier'       => __( 'Pillar guide', 'fluffless-suite' ),
			'fluffless/page-contact-rdv'  => __( 'Contact with booking', 'fluffless-suite' ),
			'fluffless/page-etude-de-cas' => __( 'Case study', 'fluffless-suite' ),
		);
	}

	/**
	 * Questions par composition : name = metadata.name du bloc rempli (titre ou paragraphe).
	 * long : réponse sur plusieurs lignes.
	 *
	 * @return array<string, list<array{name: string, label: string, example: string, long: bool}>>
	 */
	public static function questions(): array {
		$call_to_action = __( 'What should visitors do today?', 'fluffless-suite' );
		return array(
			'fluffless/page-accueil'  => array(
				self::question( 'fl:accroche', __( 'In a few words, what changes for the people you help?', 'fluffless-suite' ), __( 'Example: Your books are done, your evenings are free.', 'fluffless-suite' ) ),
				self::question( 'fl:sous-titre', __( 'Who do you work for, and what do they get when they choose you?', 'fluffless-suite' ), __( 'Example: We keep the books of craft breweries, so owners can focus on brewing.', 'fluffless-suite' ), true ),
				self::question( 'fl:force', __( 'What do you do better than anyone?', 'fluffless-suite' ), __( 'Example: Answers within one business day', 'fluffless-suite' ) ),
				self::question( 'fl:offre', __( 'Which service do people ask for most?', 'fluffless-suite' ), __( 'Example: Monthly bookkeeping', 'fluffless-suite' ) ),
				self::question( 'fl:appel', $call_to_action, __( 'Example: Book a free 20-minute call this week.', 'fluffless-suite' ) ),
			),
			'fluffless/page-a-propos' => array(
				self::question( 'fl:pourquoi', __( 'Why did you start, in one sentence?', 'fluffless-suite' ), __( 'Example: We were tired of seeing good caterers lose money on paperwork.', 'fluffless-suite' ) ),
				self::question( 'fl:histoire', __( 'Tell the moment it started.', 'fluffless-suite' ), __( 'Example: In 2015, a client called us the night before a tax deadline. We stayed until midnight.', 'fluffless-suite' ), true ),
				self::question( 'fl:refus', __( 'What do you still refuse to do, years later?', 'fluffless-suite' ), __( 'Example: We never lock clients into a yearly contract.', 'fluffless-suite' ), true ),
				self::question( 'fl:equipe', __( 'Why this team, for these clients?', 'fluffless-suite' ), __( 'Example: Three accountants who used to run restaurants themselves.', 'fluffless-suite' ), true ),
				self::question( 'fl:appel', $call_to_action, __( 'Example: Come meet the team over coffee.', 'fluffless-suite' ) ),
			),
			'fluffless/page-services' => array(
				self::question( 'fl:promesse', __( 'What do you promise, in one sentence you can keep?', 'fluffless-suite' ), __( 'Example: Clean books every month, with no surprises in April.', 'fluffless-suite' ) ),
				self::question( 'fl:probleme', __( 'What problem do you solve, and for whom?', 'fluffless-suite' ), __( 'Example: Owners spend their Sundays sorting receipts. We take that off their plate.', 'fluffless-suite' ), true ),
				self::question( 'fl:service-1', __( 'Name your first service the way your clients say it.', 'fluffless-suite' ), __( 'Example: Monthly bookkeeping', 'fluffless-suite' ) ),
				self::question( 'fl:service-2', __( 'Name your second service, with what people get from it.', 'fluffless-suite' ), __( 'Example: Tax season, handled from start to finish', 'fluffless-suite' ) ),
				self::question( 'fl:appel', $call_to_action, __( 'Example: Ask for a quote. We answer within a day.', 'fluffless-suite' ) ),
			),
			'fluffless/page-contact'  => array(
				self::question( 'fl:accroche', __( 'How do you want to greet people?', 'fluffless-suite' ), __( 'Example: Tell us about your project', 'fluffless-suite' ) ),
				self::question( 'fl:delai', __( 'How soon do you reply, and what should a first message include?', 'fluffless-suite' ), __( 'Example: We reply within one business day. Tell us your deadline and your budget.', 'fluffless-suite' ), true ),
				self::question( 'fl:courriel', __( 'Your email address', 'fluffless-suite' ), __( 'Example: hello@yourbusiness.ca', 'fluffless-suite' ) ),
				self::question( 'fl:telephone', __( 'Your phone number', 'fluffless-suite' ), __( 'Example: 418-555-0123', 'fluffless-suite' ) ),
				self::question( 'fl:adresse', __( 'Your street address', 'fluffless-suite' ), __( 'Example: 123 Main Street, Quebec City, QC G1K 3A9', 'fluffless-suite' ) ),
			),
			'fluffless/page-don'      => array(
				self::question( 'fl:accroche', __( 'What does a donation change, in a few words?', 'fluffless-suite' ), __( 'Example: A warm meal for every child, every school day.', 'fluffless-suite' ) ),
				self::question( 'fl:cause', __( 'Which cause does the organization serve, and for whom?', 'fluffless-suite' ), __( 'Example: We cook breakfast for 300 children in Limoilou schools, five days a week.', 'fluffless-suite' ), true ),
				self::question( 'fl:impact', __( 'What does one typical gift make possible?', 'fluffless-suite' ), __( 'Example: $25 covers a week of breakfasts for one child.', 'fluffless-suite' ) ),
				self::question( 'fl:transparence', __( 'Where does the money go?', 'fluffless-suite' ), __( 'Example: 87 cents of every dollar goes to food. Our annual report is online.', 'fluffless-suite' ), true ),
				self::question( 'fl:appel', __( 'What should the donate button say?', 'fluffless-suite' ), __( 'Example: Give a week of breakfasts', 'fluffless-suite' ) ),
			),
			'fluffless/page-tarifs'   => array(
				self::question( 'fl:accroche', __( 'What do people get for their money, in one sentence?', 'fluffless-suite' ), __( 'Example: Your books, done every month, for one flat fee.', 'fluffless-suite' ) ),
				self::question( 'fl:principe', __( 'How do your prices work?', 'fluffless-suite' ), __( 'Example: A flat monthly fee, taxes extra. No contract: you can stop any month.', 'fluffless-suite' ), true ),
				self::question( 'fl:inclus', __( 'What does every plan include?', 'fluffless-suite' ), __( 'Example: Bank reconciliation, sales tax returns and one call per quarter.', 'fluffless-suite' ), true ),
				self::question( 'fl:engagement', __( 'What happens if it is not working out?', 'fluffless-suite' ), __( 'Example: Stop with 30 days’ notice. You keep all your files.', 'fluffless-suite' ), true ),
				self::question( 'fl:appel', __( 'What should people do if they are unsure which plan fits?', 'fluffless-suite' ), __( 'Example: Book a free 20-minute call. We will tell you which plan fits, even if it is the smallest.', 'fluffless-suite' ) ),
			),
			'fluffless/page-equipe'   => array(
				self::question( 'fl:accroche', __( 'How would you introduce the team in a few words?', 'fluffless-suite' ), __( 'Example: Five accountants who used to run restaurants.', 'fluffless-suite' ) ),
				self::question( 'fl:apport', __( 'What do clients get from working with this team?', 'fluffless-suite' ), __( 'Example: You talk to the person doing the work, from the first call to the last invoice.', 'fluffless-suite' ), true ),
				self::question( 'fl:facon', __( 'How does the team work with clients, day to day?', 'fluffless-suite' ), __( 'Example: Every file has two people on it, so nothing stops when someone is on vacation.', 'fluffless-suite' ), true ),
				self::question( 'fl:recrutement', __( 'Are you hiring, and how can someone get in touch?', 'fluffless-suite' ), __( 'Example: We hire one apprentice every fall. Send us a few lines, no cover letter needed.', 'fluffless-suite' ), true ),
				self::question( 'fl:appel', $call_to_action, __( 'Example: Come meet the team over coffee.', 'fluffless-suite' ) ),
			),
		);
	}

	/**
	 * Une question.
	 *
	 * @param string $name    metadata.name du bloc rempli.
	 * @param string $label   Question affichée.
	 * @param string $example Exemple sous le champ.
	 * @param bool   $long    Réponse sur plusieurs lignes.
	 * @return array{name: string, label: string, example: string, long: bool}
	 */
	private static function question( string $name, string $label, string $example, bool $long = false ): array {
		return array(
			'name'    => $name,
			'label'   => $label,
			'example' => $example,
			'long'    => $long,
		);
	}

	/**
	 * Textes de l'interface (sprintf côté JS pour %s et %1$d).
	 *
	 * @return array<string, string>
	 */
	private static function strings(): array {
		return array(
			'title'                 => __( 'What kind of page?', 'fluffless-suite' ),
			'intro'                 => __( 'Pick a starting point. You can change every word afterwards.', 'fluffless-suite' ),
			'ask'                   => __( 'Answer a few questions', 'fluffless-suite' ),
			'template'              => __( 'Start from the template', 'fluffless-suite' ),
			'blank'                 => __( 'Blank page', 'fluffless-suite' ),
			'blankHelp'             => __( 'Start with an empty page and add sections one at a time.', 'fluffless-suite' ),
			'blankStart'            => __( 'Start blank', 'fluffless-suite' ),
			/* translators: 1: question number, 2: number of questions. */
			'step'                  => __( 'Question %1$d of %2$d', 'fluffless-suite' ),
			'skip'                  => __( 'Skip', 'fluffless-suite' ),
			'back'                  => __( 'Back to page types', 'fluffless-suite' ),
			'discard'               => __( 'Close the questions? Your answers will be lost.', 'fluffless-suite' ),
			'previous'              => __( 'Previous', 'fluffless-suite' ),
			'next'                  => __( 'Next', 'fluffless-suite' ),
			'finish'                => __( 'Build my page', 'fluffless-suite' ),
			'keys'                  => __( 'Press Enter for the next question. In a long answer, press Ctrl+Enter.', 'fluffless-suite' ),
			'ready'                 => __( 'Your page is ready to review. The Fluffless panel lists what is left to replace.', 'fluffless-suite' ),
			'reviewOpen'            => __( 'Show what is left', 'fluffless-suite' ),
			'reviewTitle'           => __( 'Before publishing', 'fluffless-suite' ),
			/* translators: %d: number of instruction texts still in the page. */
			'reviewTexts'           => __( 'Instructions still to replace: %d', 'fluffless-suite' ),
			/* translators: %d: number of buttons without a working link. */
			'reviewLinks'           => __( 'Buttons still to link: %d', 'fluffless-suite' ),
			/* translators: %d: number of form reminders left by a migration. */
			'reviewReminders'       => __( 'Forms still to rebuild: %d', 'fluffless-suite' ),
			/* translators: %d: number of items still in the page. */
			'reviewOff'             => __( 'This page still has %d item(s) to settle, and the checks before publishing are turned off: Publish will not show them.', 'fluffless-suite' ),
			/* translators: %d: number of forms to rebuild. */
			'reviewRemindersNotice' => __( 'This page was migrated: %d form(s) still to rebuild with the Form module before publishing.', 'fluffless-suite' ),
			'reviewConfirm'         => __( 'Publish anyway, with the items still in the page', 'fluffless-suite' ),
			'reviewNoLink'          => __( 'no link yet', 'fluffless-suite' ),
			/* translators: %s: page address, such as /contact/. */
			'reviewMissing'         => __( 'no page at %s yet', 'fluffless-suite' ),
			'reviewDone'            => __( 'No instructions left, and every button leads to a page.', 'fluffless-suite' ),
			'reviewHelp'            => __( 'Select an item to go to its block.', 'fluffless-suite' ),
			'addSection'            => __( 'Add a section', 'fluffless-suite' ),
			'search'                => __( 'Search sections', 'fluffless-suite' ),
			'all'                   => __( 'All', 'fluffless-suite' ),
			'categories'            => __( 'Categories', 'fluffless-suite' ),
			'noResults'             => __( 'No section matches your search.', 'fluffless-suite' ),
			'resultsOne'            => __( '1 section found.', 'fluffless-suite' ),
			/* translators: %d: number of sections shown in the library, 2 or more. */
			'resultsMany'           => __( '%d sections found.', 'fluffless-suite' ),
			/* translators: %s: module name, such as Expression: shapes and motion. */
			'lockedOne'             => __( '1 more section once you turn on %s.', 'fluffless-suite' ),
			/* translators: %1$d: number of sections, %2$s: module name, such as Expression: shapes and motion. */
			'lockedMany'            => __( '%1$d more sections once you turn on %2$s.', 'fluffless-suite' ),
			'lockedOpen'            => __( 'Turn it on', 'fluffless-suite' ),
			'newTab'                => __( '(opens in a new tab)', 'fluffless-suite' ),
			'changeTemplate'        => __( 'Change template', 'fluffless-suite' ),
			'changeTemplateConfirm' => __( 'Start again from a template? What you wrote on this page will be replaced.', 'fluffless-suite' ),
			'sidebar'               => __( 'Fluffless', 'fluffless-suite' ),
			'modeLabel'             => __( 'Simple editor', 'fluffless-suite' ),
			'modeHelp'              => __( 'Sections keep their layout and advanced settings stay tucked away. Turn off for the full WordPress editor.', 'fluffless-suite' ),
			'toSimple'              => __( 'Switch to the simple editor', 'fluffless-suite' ),
			'toFull'                => __( 'Switch to the full editor', 'fluffless-suite' ),
			'modeSaved'             => __( 'Editor mode saved. Reload the editor to apply it.', 'fluffless-suite' ),
			'reload'                => __( 'Reload now', 'fluffless-suite' ),
			/* translators: %s: error message. */
			'modeError'             => __( 'The editor mode could not be saved: %s', 'fluffless-suite' ),
			'unlock'                => __( 'Unlock section', 'fluffless-suite' ),
			/* translators: %s: page type, such as Home. */
			'preview'               => __( 'Preview of the %s page', 'fluffless-suite' ),
			'saveTemplate'          => __( 'Save as a page template', 'fluffless-suite' ),
			'saveTitle'             => __( 'Template name', 'fluffless-suite' ),
			'saveHelp'              => __( 'The page as it is now becomes a card on the start screen of every new page. A template with the same name is replaced.', 'fluffless-suite' ),
			'saveConfirm'           => __( 'Save the template', 'fluffless-suite' ),
			'cancel'                => __( 'Cancel', 'fluffless-suite' ),
			/* translators: %s: template name. */
			'saved'                 => __( 'Template “%s” saved. It is offered on the start screen of new pages.', 'fluffless-suite' ),
			/* translators: %s: error message. */
			'saveError'             => __( 'The template could not be saved: %s', 'fluffless-suite' ),
			'remove'                => __( 'Remove this template', 'fluffless-suite' ),
			/* translators: %s: template name. */
			'removeConfirm'         => __( 'Remove the template “%s”? Pages built from it are kept.', 'fluffless-suite' ),
			'savedBadge'            => __( 'Saved on this site', 'fluffless-suite' ),
		);
	}
}
