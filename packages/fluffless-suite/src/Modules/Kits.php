<?php
/**
 * Module Trousses de site : un site complet importé en un clic pour un secteur donné (organisme,
 * artisan, consultante, restaurant) : les pages (compositions pleine page du thème et de la Suite),
 * la palette, le duo de polices et la forme des coins.
 *
 * Rien n'est jamais supprimé : une page dont l'adresse existe déjà est laissée telle quelle et la
 * page d'accueil n'est réglée que si le site n'en a pas. La palette, les polices et la forme
 * remplacent l'état courant par les écrans de la Suite, qui ne gardent que l'état d'avant leur
 * première application (bouton Revenir de chacun) ; les versions intermédiaires restent dans les
 * révisions des styles de l'éditeur de site (C2-3-06). Les pages créées s'ajoutent au menu partagé
 * (C1-9-01). Chaque partie ne s'applique que si son module est allumé.
 * Le filtre fluffless_kits permet à une agence d'ajouter ou de retirer des trousses.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Admin\Home;
use Fluffless\Suite\Admin\Menu;
use Fluffless\Suite\Admin\Screen;
use Fluffless\Suite\Admin\SettingsPage;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Core\Sanitize;
use WP_Block_Patterns_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Trousses de site par secteur.
 *
 * @phpstan-type KitPage array{pattern: string, title: string, slug: string, front: bool}
 * @phpstan-type Kit array{id: string, label: string, description: string, pages: list<KitPage>, palette: array<string, string>, fonts: int, shape: string}
 * @phpstan-type Result array{created: list<int>, skipped: list<string>, front: bool, palette: string, fonts: string, shape: string}
 */
final class Kits implements Module {

	/** Écran et action admin-post. */
	public const PAGE   = 'fluffless-kits';
	public const ACTION = 'fluffless_kit_import';

	/** Parties d'une trousse, dans l'ordre d'application. */
	public const PARTS = array( 'pages', 'palette', 'fonts', 'shape' );

	/** Sections de premier niveau verrouillées en mode Simple (même liste que SECTIONS dans editor-core.js). */
	public const SECTIONS = array( 'core/group', 'core/cover', 'core/columns', 'core/media-text' );

	/** Identifiant du module. */
	public static function id(): string {
		return 'kits';
	}

	/** Nom affiché. */
	public static function label(): string {
		return __( 'Site kits', 'fluffless-suite' );
	}

	/** Description affichée. */
	public static function description(): string {
		return __( 'A whole site in one click for a given sector: pages, palette, fonts and shape. Nothing is ever deleted, an existing page is left alone, and every part can be undone from its own screen.', 'fluffless-suite' );
	}

	/** Actif sur une installation neuve : un simple écran, rien côté visiteur. */
	public static function enabled_by_default(): bool {
		return true;
	}

	/** Aucun réglage. */
	public static function defaults(): array {
		return array();
	}

	/**
	 * Assainissement de la section.
	 *
	 * @param array<mixed> $input Section brute.
	 */
	public static function sanitize( array $input ): array {
		return Sanitize::section( $input, self::fields(), self::defaults() );
	}

	/** Aucun champ. */
	public static function fields(): array {
		return array();
	}

	/** Aucun état particulier. */
	public static function status(): string {
		return '';
	}

	/** Pose les crochets : onglet Apparence et action d'import. */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action(
			'admin_menu',
			static function (): void {
				Menu::add_tab( 'appearance', self::PAGE, static fn(): string => __( 'Site kits', 'fluffless-suite' ), array( self::class, 'render' ), 5 );
			}
		);
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Les trousses livrées, puis le filtre fluffless_kits ; chaque trousse est vérifiée et les
	 * trousses invalides sont écartées.
	 *
	 * @return array<string, Kit>
	 */
	public static function all(): array {
		$kits = apply_filters( 'fluffless_kits', self::builtin() );
		$out  = array();
		foreach ( is_array( $kits ) ? $kits : array() as $kit ) {
			$kit = is_array( $kit ) ? self::sanitize_kit( $kit ) : null;
			if ( null !== $kit ) {
				$out[ $kit['id'] ] = $kit;
			}
		}
		return $out;
	}

	/**
	 * Une trousse par identifiant.
	 *
	 * @param string $id Identifiant.
	 * @return Kit|null
	 */
	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Vérifie une trousse : identifiant, au moins une page dont la composition est enregistrée,
	 * palette complète qui passe les contrastes, duo de polices connu, forme connue.
	 *
	 * @param array<mixed> $kit Trousse brute.
	 * @return Kit|null
	 */
	public static function sanitize_kit( array $kit ): ?array {
		$id = is_string( $kit['id'] ?? null ) ? sanitize_key( $kit['id'] ) : '';
		if ( '' === $id ) {
			return null;
		}
		$registry = WP_Block_Patterns_Registry::get_instance();
		$pages    = array();
		$front    = false;
		foreach ( is_array( $kit['pages'] ?? null ) ? $kit['pages'] : array() as $page ) {
			if ( ! is_array( $page ) || ! is_string( $page['pattern'] ?? null ) || ! $registry->is_registered( $page['pattern'] ) ) {
				continue;
			}
			$slug = is_string( $page['slug'] ?? null ) ? sanitize_title( $page['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$is_front = ! $front && ! empty( $page['front'] );
			$front    = $front || $is_front;
			$pages[]  = array(
				'pattern' => $page['pattern'],
				'title'   => is_string( $page['title'] ?? null ) && '' !== $page['title'] ? sanitize_text_field( $page['title'] ) : (string) ( $registry->get_registered( $page['pattern'] )['title'] ?? $slug ),
				'slug'    => $slug,
				'front'   => $is_front,
			);
		}
		$palette = is_array( $kit['palette'] ?? null ) ? Palette::sanitize_palette( $kit['palette'] ) : null;
		$fonts   = is_numeric( $kit['fonts'] ?? null ) && isset( Fonts::PAIRS[ (int) $kit['fonts'] ] ) ? (int) $kit['fonts'] : -1;
		$shape   = is_string( $kit['shape'] ?? null ) && isset( Shape::PRESETS[ $kit['shape'] ] ) ? $kit['shape'] : '';
		if ( array() === $pages || null === $palette || array() !== Palette::failures( $palette ) || -1 === $fonts || '' === $shape ) {
			return null;
		}
		return array(
			'id'          => $id,
			'label'       => is_string( $kit['label'] ?? null ) && '' !== $kit['label'] ? sanitize_text_field( $kit['label'] ) : $id,
			'description' => is_string( $kit['description'] ?? null ) ? sanitize_text_field( $kit['description'] ) : '',
			'pages'       => $pages,
			'palette'     => $palette,
			'fonts'       => $fonts,
			'shape'       => $shape,
		);
	}

	/**
	 * Les quatre trousses livrées. Les palettes passent les contrastes de l'écran Palette ; les
	 * duos renvoient à Fonts::PAIRS ; les adresses sont traduites (contexte « page address » :
	 * about devient a-propos en français) pour répondre aux liens du menu du thème, traduits de même.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function builtin(): array {
		return array(
			array(
				'id'          => 'nonprofit',
				'label'       => __( 'Nonprofit', 'fluffless-suite' ),
				'description' => __( 'An organisation that needs to explain its mission, show its programs and collect donations. Warm palette, very legible fonts, rounded corners.', 'fluffless-suite' ),
				'pages'       => array(
					self::page( 'fluffless/page-accueil', __( 'Home', 'fluffless-suite' ), _x( 'home', 'page address', 'fluffless-suite' ), true ),
					self::page( 'fluffless/page-a-propos', __( 'Our mission', 'fluffless-suite' ), _x( 'about', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-services', __( 'Our programs', 'fluffless-suite' ), _x( 'services', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-don', __( 'Donate', 'fluffless-suite' ), _x( 'donate', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-contact', __( 'Contact', 'fluffless-suite' ), _x( 'contact', 'page address', 'fluffless-suite' ) ),
				),
				'palette'     => array(
					'base'      => '#fbf7f1',
					'surface'   => '#f3ece1',
					'contrast'  => '#23302b',
					'muted'     => '#5c655f',
					'divider'   => '#d9cfbf',
					'accent'    => '#1f6f5a',
					'on-accent' => '#ffffff',
					'accent-2'  => '#9a4118',
					'alert'     => '#a3232b',
				),
				'fonts'       => 2,
				'shape'       => 'rounded',
			),
			array(
				'id'          => 'artisan',
				'label'       => __( 'Craftsperson', 'fluffless-suite' ),
				'description' => __( 'A workshop that sells what it makes: the work first, then the story and the ways to order. Earthy palette, a serif for titles, slanted corners.', 'fluffless-suite' ),
				'pages'       => array(
					self::page( 'fluffless/page-accueil', __( 'Home', 'fluffless-suite' ), _x( 'home', 'page address', 'fluffless-suite' ), true ),
					self::page( 'fluffless/page-portfolio', __( 'Work', 'fluffless-suite' ), _x( 'work', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-services', __( 'Custom orders', 'fluffless-suite' ), _x( 'services', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-a-propos', __( 'The workshop', 'fluffless-suite' ), _x( 'about', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-contact', __( 'Contact', 'fluffless-suite' ), _x( 'contact', 'page address', 'fluffless-suite' ) ),
				),
				'palette'     => array(
					'base'      => '#f7f3ee',
					'surface'   => '#ece4da',
					'contrast'  => '#2b2420',
					'muted'     => '#6a5d54',
					'divider'   => '#d6c9bb',
					'accent'    => '#8a4b2a',
					'on-accent' => '#fff8f2',
					'accent-2'  => '#4f6b4a',
					'alert'     => '#a11e1e',
				),
				'fonts'       => 1,
				'shape'       => 'oblique',
			),
			array(
				'id'          => 'consultant',
				'label'       => __( 'Consultant', 'fluffless-suite' ),
				'description' => __( 'A solo consultant who sells expertise: services, a case study as proof, and a contact page with booking. Crisp palette, classic fonts, square corners.', 'fluffless-suite' ),
				'pages'       => array(
					self::page( 'fluffless/page-accueil', __( 'Home', 'fluffless-suite' ), _x( 'home', 'page address', 'fluffless-suite' ), true ),
					self::page( 'fluffless/page-services', __( 'Services', 'fluffless-suite' ), _x( 'services', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-etude-de-cas', __( 'Case study', 'fluffless-suite' ), _x( 'case-study', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-a-propos', __( 'About', 'fluffless-suite' ), _x( 'about', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-contact-rdv', __( 'Contact', 'fluffless-suite' ), _x( 'contact', 'page address', 'fluffless-suite' ) ),
				),
				'palette'     => array(
					'base'      => '#ffffff',
					'surface'   => '#f2f5f9',
					'contrast'  => '#14213d',
					'muted'     => '#4f5b70',
					'divider'   => '#d3dae6',
					'accent'    => '#1d4ed8',
					'on-accent' => '#ffffff',
					'accent-2'  => '#0f766e',
					'alert'     => '#b91c1c',
				),
				'fonts'       => 0,
				'shape'       => 'square',
			),
			array(
				'id'          => 'restaurant',
				'label'       => __( 'Restaurant', 'fluffless-suite' ),
				'description' => __( 'A place to eat: the menu, the story of the kitchen, hours and address. Dark, warm palette, a display serif for titles, pill buttons.', 'fluffless-suite' ),
				'pages'       => array(
					self::page( 'fluffless/page-accueil', __( 'Home', 'fluffless-suite' ), _x( 'home', 'page address', 'fluffless-suite' ), true ),
					self::page( 'fluffless/page-menu', __( 'Menu', 'fluffless-suite' ), _x( 'menu', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-a-propos', __( 'Our kitchen', 'fluffless-suite' ), _x( 'about', 'page address', 'fluffless-suite' ) ),
					self::page( 'fluffless/page-contact', __( 'Hours and address', 'fluffless-suite' ), _x( 'contact', 'page address', 'fluffless-suite' ) ),
				),
				'palette'     => array(
					'base'      => '#1c1917',
					'surface'   => '#292421',
					'contrast'  => '#f5efe6',
					'muted'     => '#c9bfb2',
					'divider'   => '#4a413b',
					'accent'    => '#e8b04b',
					'on-accent' => '#1c1917',
					'accent-2'  => '#d97757',
					'alert'     => '#f87171',
				),
				'fonts'       => 9,
				'shape'       => 'pill',
			),
		);
	}

	/**
	 * Une page d'une trousse.
	 *
	 * @param string $pattern Composition entière (core/post-content).
	 * @param string $title   Titre de la page.
	 * @param string $slug    Adresse.
	 * @param bool   $front   Page d'accueil.
	 * @return array{pattern: string, title: string, slug: string, front: bool}
	 */
	private static function page( string $pattern, string $title, string $slug, bool $front = false ): array {
		return array(
			'pattern' => $pattern,
			'title'   => $title,
			'slug'    => $slug,
			'front'   => $front,
		);
	}

	/**
	 * Un site sans page publiée à soi : ni la page d'exemple de l'installation ni la page de
	 * confidentialité ne comptent, comme dans Home::is_own_page().
	 */
	public static function site_is_empty(): bool {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post__not_in'   => array_filter( array( (int) get_option( 'wp_page_for_privacy_policy' ) ) ),
				'posts_per_page' => 3,
				'no_found_rows'  => true,
			)
		);
		foreach ( $pages as $page ) {
			if ( Home::is_own_page( $page ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Importe une trousse, partie par partie. Chaque partie hors de $parts est « skip » ; une
	 * partie dont le module est éteint est « off » ; sinon « ok », « fail », et pour les polices
	 * « applied », « download », « integrity », « write » ou « error » (voir Fonts::apply_pair). Les pages créées et les
	 * adresses laissées telles quelles sont listées.
	 *
	 * @param array<string, mixed> $kit     Trousse vérifiée.
	 * @param array<int, string>   $parts   Parties à appliquer.
	 * @param bool                 $publish Publier les pages créées (sinon brouillons).
	 * @phpstan-param Kit $kit
	 * @phpstan-param list<string> $parts
	 * @return Result
	 */
	public static function import( array $kit, array $parts, bool $publish ): array {
		$result = array(
			'created' => array(),
			'skipped' => array(),
			'front'   => false,
			'palette' => 'skip',
			'fonts'   => 'skip',
			'shape'   => 'skip',
		);
		if ( in_array( 'pages', $parts, true ) ) {
			$result = self::import_pages( $kit, $publish, $result );
		}
		if ( in_array( 'palette', $parts, true ) ) {
			$result['palette'] = ! Options::is_enabled( Palette::id() ) ? 'off' : ( Palette::apply( $kit['palette'] ) ? 'ok' : 'fail' );
		}
		if ( in_array( 'fonts', $parts, true ) ) {
			$result['fonts'] = ! Options::is_enabled( Fonts::id() ) ? 'off' : Fonts::apply_pair( $kit['fonts'] );
		}
		if ( in_array( 'shape', $parts, true ) ) {
			$result['shape'] = ! Options::is_enabled( Shape::id() ) ? 'off' : ( Shape::apply( array( 'preset' => $kit['shape'] ) ) ? 'ok' : 'fail' );
		}
		return $result;
	}

	/**
	 * Crée les pages absentes à partir des compositions, sur le gabarit pleine largeur du thème,
	 * sections verrouillées en contentOnly pour qui est en mode Simple (P4-02), puis ajoutées au
	 * menu partagé du thème, sauf l'accueil (C1-9-01) ; règle la page d'accueil si le site n'en a pas et que la page d'accueil est publiée.
	 *
	 * @param array<string, mixed> $kit     Trousse.
	 * @param bool                 $publish Publier.
	 * @param array<string, mixed> $result  Résultat en cours.
	 * @phpstan-param Kit $kit
	 * @phpstan-param Result $result
	 * @return Result
	 */
	private static function import_pages( array $kit, bool $publish, array $result ): array {
		$registry  = WP_Block_Patterns_Registry::get_instance();
		$templates = wp_get_theme()->get_page_templates( null, 'page' );
		$lock      = Editor::is_simple();
		$menu      = array();
		foreach ( $kit['pages'] as $page ) {
			if ( null !== get_page_by_path( $page['slug'], OBJECT, 'page' ) ) {
				$result['skipped'][] = $page['slug'];
				continue;
			}
			$pattern = $registry->get_registered( $page['pattern'] );
			$content = is_array( $pattern ) && is_string( $pattern['content'] ?? null ) ? $pattern['content'] : '';
			// wp_insert_post() déséchappe ses données : sans wp_slash, les \u002d et \u0026 des attributs perdent leur barre oblique.
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'page',
						'post_status'  => $publish ? 'publish' : 'draft',
						'post_title'   => $page['title'],
						'post_name'    => $page['slug'],
						'post_content' => $lock ? self::lock_sections( $content ) : $content,
						'meta_input'   => isset( $templates[ Editor::FULL_TEMPLATE ] ) ? array( '_wp_page_template' => Editor::FULL_TEMPLATE ) : array(),
					)
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				$result['skipped'][] = $page['slug'];
				continue;
			}
			$result['created'][] = (int) $id;
			if ( $page['front'] && $publish && 'page' !== get_option( 'show_on_front' ) && 0 === (int) get_option( 'page_on_front' ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', (int) $id );
				$result['front'] = true;
			}
			if ( ! $page['front'] ) {
				$menu[] = get_post( (int) $id );
			}
		}
		// Pages de la trousse au menu partagé du thème (une seule entité pour l'en-tête et les pieds).
		if ( array() !== $menu && function_exists( 'fluffless_navigation_add_pages' ) ) {
			fluffless_navigation_add_pages( $menu );
		}
		return $result;
	}

	/**
	 * Verrouille en contentOnly les sections de premier niveau, comme lockSections dans
	 * editor-core.js pour le questionnaire et la bibliothèque du mode Simple. Le reste est intact.
	 *
	 * @param string $content Contenu en blocs.
	 */
	public static function lock_sections( string $content ): string {
		$blocks = parse_blocks( $content );
		foreach ( $blocks as $index => $block ) {
			if ( in_array( $block['blockName'], self::SECTIONS, true ) ) {
				$blocks[ $index ]['attrs']['templateLock'] = 'contentOnly';
			}
		}
		return serialize_blocks( $blocks );
	}

	/** Reçoit le formulaire : nonce, droit, import, retour à l'écran avec le résultat. */
	public static function handle(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'fluffless-suite' ), 403 );
		}
		check_admin_referer( self::ACTION );
		wp_safe_redirect( self::run( (array) wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Lit les champs du formulaire, importe et renvoie l'adresse de retour, résultat en paramètres.
	 *
	 * @param array<mixed> $input Champs envoyés.
	 */
	public static function run( array $input ): string {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		$kit = is_string( $input['kit'] ?? null ) ? self::get( sanitize_key( $input['kit'] ) ) : null;
		if ( null === $kit ) {
			return add_query_arg( 'fl_kit_error', 'unknown', $url );
		}
		$parts = array_values( array_intersect( self::PARTS, is_array( $input['parts'] ?? null ) ? array_map( 'strval', $input['parts'] ) : array() ) );
		if ( array() === $parts ) {
			return add_query_arg( 'fl_kit_error', 'nothing', $url );
		}
		$result = self::import( $kit, $parts, ! empty( $input['publish'] ) );
		return add_query_arg(
			array(
				'fl_kit'     => $kit['id'],
				'fl_created' => count( $result['created'] ),
				'fl_skipped' => count( $result['skipped'] ),
				'fl_front'   => $result['front'] ? '1' : '0',
				'fl_palette' => $result['palette'],
				'fl_fonts'   => $result['fonts'],
				'fl_shape'   => $result['shape'],
			),
			$url
		);
	}

	/** Écran Trousses de site. */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap">';
		Screen::header( __( 'Site kits', 'fluffless-suite' ), __( 'Pick a sector and get a whole site: the pages, the palette, the fonts and the shape of the corners. Then replace the words with yours, page by page.', 'fluffless-suite' ) );
		self::render_result();
		echo '<p>' . esc_html__( 'Nothing is deleted. A page whose address already exists is left alone and the home page is set only if the site has none. The palette, fonts and shape replace the current ones: their screens can go back to the state before their first change, and earlier versions stay in the style revisions of the site editor. The kit\'s pages are added to the menu shared by the header and the footers.', 'fluffless-suite' ) . '</p>';
		$kits = self::all();
		if ( array() === $kits ) {
			echo Screen::note( 'warn', __( 'No kit is available.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			echo '</div>';
			return;
		}
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="fl-kits">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<fieldset><legend>' . esc_html__( 'Sector', 'fluffless-suite' ) . '</legend><div class="fl-grid">';
		$first = array_key_first( $kits );
		foreach ( $kits as $kit ) {
			self::render_kit( $kit, $kit['id'] === $first );
		}
		echo '</div></fieldset>';
		self::render_parts();
		echo '<p class="fl-savebar">';
		submit_button( __( 'Import this kit', 'fluffless-suite' ), 'primary', 'submit', false );
		echo '</p></form></div>';
	}

	/**
	 * Une carte de trousse : bouton radio, description, pages, palette, polices, forme.
	 *
	 * @param array<string, mixed> $kit     Trousse.
	 * @param bool                 $checked Cochée par défaut.
	 * @phpstan-param Kit $kit
	 */
	private static function render_kit( array $kit, bool $checked ): void {
		$catalogue = Fonts::catalogue();
		$pair      = Fonts::PAIRS[ $kit['fonts'] ];
		$names     = array( $catalogue[ $pair[0] ]['name'] ?? $pair[0], 'system' === $pair[1] ? __( 'system font', 'fluffless-suite' ) : ( $catalogue[ $pair[1] ]['name'] ?? $pair[1] ) );
		$shapes    = array(
			'square'   => __( 'Square', 'fluffless-suite' ),
			'rounded'  => __( 'Rounded', 'fluffless-suite' ),
			'pill'     => __( 'Pill', 'fluffless-suite' ),
			'oblique'  => __( 'Slanted', 'fluffless-suite' ),
			'diagonal' => __( 'Diagonal', 'fluffless-suite' ),
		);
		echo '<section class="fl-card fl-kit">';
		echo '<label class="fl-choice"><input type="radio" name="kit" value="' . esc_attr( $kit['id'] ) . '"' . checked( $checked, true, false ) . '><span>' . esc_html( $kit['label'] ) . '</span></label>';
		if ( '' !== $kit['description'] ) {
			echo '<p>' . esc_html( $kit['description'] ) . '</p>';
		}
		echo '<h3>' . esc_html__( 'Pages', 'fluffless-suite' ) . '</h3><ul>';
		foreach ( $kit['pages'] as $page ) {
			echo '<li>' . esc_html( $page['title'] ) . ' <code>/' . esc_html( $page['slug'] ) . '/</code>' . ( $page['front'] ? ' <span class="fl-muted">' . esc_html__( '(home page)', 'fluffless-suite' ) . '</span>' : '' ) . '</li>';
		}
		echo '</ul>';
		echo '<h3>' . esc_html__( 'Palette', 'fluffless-suite' ) . '</h3><p class="fl-kit__swatches">';
		foreach ( $kit['palette'] as $role => $color ) {
			echo '<span class="fl-swatch" style="background:' . esc_attr( $color ) . '" title="' . esc_attr( $role ) . '"></span>';
		}
		echo '</p>';
		/* translators: 1: heading font, 2: body font */
		echo '<p>' . esc_html( sprintf( __( 'Fonts: %1$s for titles, %2$s for text.', 'fluffless-suite' ), $names[0], $names[1] ) ) . '<br>';
		/* translators: %s: shape preset */
		echo esc_html( sprintf( __( 'Shape: %s.', 'fluffless-suite' ), $shapes[ $kit['shape'] ] ?? $kit['shape'] ) ) . '</p>';
		echo '</section>';
	}

	/** Cases des parties à appliquer et de la publication, jamais cochée d'office : les pages contiennent des consignes. */
	private static function render_parts(): void {
		$labels = array(
			'pages'   => __( 'Pages', 'fluffless-suite' ),
			'palette' => __( 'Palette', 'fluffless-suite' ),
			'fonts'   => __( 'Fonts', 'fluffless-suite' ),
			'shape'   => __( 'Shape', 'fluffless-suite' ),
		);
		$module = array(
			'pages'   => true,
			'palette' => Options::is_enabled( Palette::id() ),
			'fonts'   => Options::is_enabled( Fonts::id() ),
			'shape'   => Options::is_enabled( Shape::id() ),
		);
		echo '<fieldset class="fl-fields"><legend>' . esc_html__( 'What to import', 'fluffless-suite' ) . '</legend>';
		foreach ( self::PARTS as $part ) {
			$id = 'fl-part-' . $part;
			echo '<p><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="parts[]" value="' . esc_attr( $part ) . '"' . checked( $module[ $part ], true, false ) . disabled( $module[ $part ], false, false ) . '> ' . esc_html( $labels[ $part ] );
			if ( ! $module[ $part ] ) {
				echo ' <span class="fl-muted">' . esc_html__( '(turn the module on in Modules first)', 'fluffless-suite' ) . '</span>';
			}
			echo '</label></p>';
		}
		$empty = self::site_is_empty();
		echo '<p><label for="fl-kit-publish"><input type="checkbox" id="fl-kit-publish" name="publish" value="1"> ' . esc_html__( 'Publish the pages right away (they contain instructions to replace).', 'fluffless-suite' );
		echo ' <span class="fl-muted">' . esc_html( ( $empty ? __( 'The site has no published page yet.', 'fluffless-suite' ) . ' ' : '' ) . __( 'Unchecked, the pages arrive as drafts to review first.', 'fluffless-suite' ) ) . '</span></label></p>';
		echo '</fieldset>';
	}

	/** Résultat du dernier import, lu dans l'adresse après la redirection. */
	private static function render_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- simples indicateurs d'affichage après redirection.
		$read = static fn( string $key ): string => isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $key ] ) ) : '';
		$done = isset( $_GET['fl_kit'] );
		// phpcs:enable
		$error = $read( 'fl_kit_error' );
		if ( '' !== $error ) {
			echo Screen::note( 'bad', 'nothing' === $error ? __( 'Nothing was selected to import.', 'fluffless-suite' ) : __( 'Unknown kit.', 'fluffless-suite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
			return;
		}
		if ( ! $done ) {
			return;
		}
		$lines   = array();
		$created = (int) $read( 'fl_created' );
		$skipped = (int) $read( 'fl_skipped' );
		if ( $created > 0 || $skipped > 0 ) {
			/* translators: %d: number of pages */
			$lines[] = sprintf( _n( '%d page created.', '%d pages created.', $created, 'fluffless-suite' ), $created );
			if ( $skipped > 0 ) {
				/* translators: %d: number of pages */
				$lines[] = sprintf( _n( '%d page already existed and was left alone.', '%d pages already existed and were left alone.', $skipped, 'fluffless-suite' ), $skipped );
			}
			if ( '1' === $read( 'fl_front' ) ) {
				$lines[] = __( 'The home page is set.', 'fluffless-suite' );
			}
		}
		$parts = array(
			'fl_palette' => __( 'Palette', 'fluffless-suite' ),
			'fl_fonts'   => __( 'Fonts', 'fluffless-suite' ),
			'fl_shape'   => __( 'Shape', 'fluffless-suite' ),
		);
		$bad   = false;
		foreach ( $parts as $key => $label ) {
			$state = $read( $key );
			if ( 'skip' === $state || '' === $state ) {
				continue;
			}
			$bad     = $bad || in_array( $state, array( 'fail', 'error', 'download', 'integrity', 'write', 'save' ), true );
			$lines[] = $label . ' : ' . self::state_word( $state );
		}
		if ( array() !== $lines ) {
			echo Screen::note( $bad ? 'warn' : 'good', implode( ' ', $lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans Screen::note().
		}
	}

	/**
	 * Mot d'état d'une partie.
	 *
	 * @param string $state État (ok, off, fail, applied, download, integrity, write, save, error).
	 */
	private static function state_word( string $state ): string {
		switch ( $state ) {
			case 'ok':
			case 'applied':
				return __( 'applied.', 'fluffless-suite' );
			case 'off':
				return __( 'not applied, the module is off. Turn it on in Fluffless → Modules, then apply the kit again.', 'fluffless-suite' );
			case 'download':
				return __( 'not applied, a font file could not be downloaded. Try again from the Fonts screen.', 'fluffless-suite' );
			case 'integrity':
				return __( 'not applied, the font file of this pair has changed at its distributor. Choose another pair from the Fonts screen.', 'fluffless-suite' );
			case 'write':
				return __( 'not applied, the fonts folder does not accept files. Ask your host to allow writing in wp-content/fonts.', 'fluffless-suite' );
			default:
				return __( 'not applied.', 'fluffless-suite' );
		}
	}
}
