<?php
/**
 * Styles de blocs avancés livrés par Suite pour le thème Fluffless.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Un style = styles/blocks/<fichier>.json (mêmes champs qu'une variation de thème)
 * et, au besoin, assets/block-styles/<slug>.css. La feuille ne se charge que sur une page
 * qui affiche un bloc portant ce style (classe is-style-<slug>), en ligne et sans ses
 * commentaires. wp_enqueue_block_style chargeait toutes les feuilles d'un type de bloc dès
 * qu'un bloc de ce type était rendu : les 9 feuilles de core/group sur presque chaque page.
 *
 * Thèmes : WordPress enregistre lui-même les variations déclarées dans styles/ d'un thème (parent
 * et enfant) ; Suite ne relit pas ces JSON. Elle charge en revanche, de la même façon, une feuille
 * assets/block-styles/<slug>.css trouvée dans le thème enfant, puis le parent, pour tout style
 * enregistré (du thème ou de Suite) qui porte ce slug : un thème ajoute ainsi une feuille à son
 * style, ou remplace celle d'un style de Suite. Une feuille de thème est mise en ligne telle quelle,
 * ses url() relatives résolues par rapport au fichier.
 */
final class BlockStyles {

	/**
	 * Slugs à feuille, par type de bloc ; rempli par register().
	 *
	 * @var array<string, list<string>>
	 */
	private static array $sheets = array();

	/**
	 * Feuilles des thèmes : slug => chemin et adresse, thème enfant d'abord ; calculé une fois.
	 *
	 * @var array<string, array{path: string, url: string}>|null
	 */
	private static ?array $theme_sheets = null;

	/** Préfixe des poignées de feuilles : fluffless-suite-style-<slug>. */
	private const HANDLE_PREFIX = 'fluffless-suite-style-';

	/** Slug accepté : minuscules, chiffres et tirets, comme la classe is-style-<slug>. */
	private const SLUG_PATTERN = '#^[a-z0-9-]+$#D';

	/** Styles dont le conteneur doit recevoir le focus clavier (voir focusable()). */
	private const FOCUSABLE = array( 'retournee', 'defilement' );

	/** Script d'éditeur des styles : le panneau des styles à options (STYLE_OPTIONS). */
	public const EDITOR_SCRIPT = 'fluffless-block-styles-editor';

	/** Style qui porte ce panneau, et préfixe des classes qu'il écrit dans className. */
	public const ACCENT_STYLE  = 'forme-accent';
	public const ACCENT_PREFIX = 'fl-accent-';

	/**
	 * Options de la forme d'accent : groupe => valeurs, la première étant le défaut (aucune classe
	 * écrite). Chaque autre valeur a sa règle dans assets/block-styles/forme-accent.css.
	 *
	 * @var array<string, list<string>>
	 */
	public const ACCENT_OPTIONS = array(
		'shape' => array( 'disc', 'half-moon', 'ring', 'drop', 'dot-grid' ),
		'place' => array( 'top-right', 'bottom-left', 'title', 'right-edge' ),
		'size'  => array( 'medium', 'small', 'large' ),
		'alpha' => array( '25', '10', '50', '100' ),
	);

	/**
	 * Styles à options, écrites en classes dans className par le panneau d'éditeur. Par style : le
	 * préfixe des classes, « grouped » (vrai : préfixe-groupe-valeur, faux : préfixe-valeur, pour
	 * un style à un seul groupe) et les options, groupe => valeurs, la première étant le défaut
	 * (aucune classe écrite). Chaque autre valeur a sa règle dans la feuille du style.
	 *
	 * @var array<string, array{prefix: string, grouped: bool, options: array<string, list<string>>}>
	 */
	public const STYLE_OPTIONS = array(
		self::ACCENT_STYLE => array(
			'prefix'  => self::ACCENT_PREFIX,
			'grouped' => true,
			'options' => self::ACCENT_OPTIONS,
		),
		'index-mono'       => array(
			'prefix'  => 'fl-index-',
			'grouped' => false,
			'options' => array( 'format' => array( 'brackets', 'slash', 'dot' ) ),
		),
		'degrade-texte'    => array(
			'prefix'  => 'fl-degrade-',
			'grouped' => false,
			'options' => array( 'angle' => array( 'horizontal', 'diagonal', 'vertical' ) ),
		),
		'plan'             => array(
			'prefix'  => 'fl-plan-',
			'grouped' => false,
			'options' => array( 'line' => array( 'plain', 'neon' ) ),
		),
		'autocollant'      => array(
			'prefix'  => 'fl-autocollant-',
			'grouped' => false,
			'options' => array( 'tilt' => array( 'gauche', 'droite' ) ),
		),
		'pilule'           => array(
			'prefix'  => 'fl-pilule-',
			'grouped' => false,
			'options' => array( 'format' => array( 'large', 'haute' ) ),
		),
		'coins-decales'    => array(
			'prefix'  => 'fl-coins-',
			'grouped' => false,
			'options' => array( 'diagonal' => array( 'normal', 'miroir' ) ),
		),
	);

	/**
	 * Libellés traduits, en toutes lettres pour que make-pot les trouve.
	 * Ajouter un style = un fichier JSON, sa feuille CSS s'il en faut une, et une ligne ici.
	 *
	 * @return array<string, string> Slug => libellé.
	 */
	public static function labels(): array {
		return array(
			'bandeau'              => _x( 'Banner', 'Style variation name', 'fluffless-suite' ),
			'coin-coupe'           => _x( 'Cut corner', 'Style variation name', 'fluffless-suite' ),
			'bord-oblique-haut'    => _x( 'Slanted top edge', 'Style variation name', 'fluffless-suite' ),
			'bord-oblique-bas'     => _x( 'Slanted bottom edge', 'Style variation name', 'fluffless-suite' ),
			'numeros'              => _x( 'Numbered', 'Style variation name', 'fluffless-suite' ),
			'defilement'           => _x( 'Horizontal scroll', 'Style variation name', 'fluffless-suite' ),
			'bande'                => _x( 'Scrolling band', 'Style variation name', 'fluffless-suite' ),
			'frise'                => _x( 'Timeline', 'Style variation name', 'fluffless-suite' ),
			'forme-douce'          => _x( 'Soft shape', 'Style variation name', 'fluffless-suite' ),
			'forme-accent'         => _x( 'Accent shape', 'Style variation name', 'fluffless-suite' ),
			'grain'                => _x( 'Grain', 'Style variation name', 'fluffless-suite' ),
			'revele'               => _x( 'Reveal on scroll', 'Style variation name', 'fluffless-suite' ),
			'retournee'            => _x( 'Flip card', 'Style variation name', 'fluffless-suite' ),
			'pixel'                => _x( 'Pixel', 'Style variation name', 'fluffless-suite' ),
			'retro'                => _x( 'Retro shadow', 'Style variation name', 'fluffless-suite' ),
			'inverse'              => _x( 'Invert on hover', 'Style variation name', 'fluffless-suite' ),
			'souligne'             => _x( 'Animated underline', 'Style variation name', 'fluffless-suite' ),
			'fleche-defilement'    => _x( 'Scroll cue', 'Style variation name', 'fluffless-suite' ),
			'ancres'               => _x( 'Anchor index', 'Style variation name', 'fluffless-suite' ),
			'souleve'              => _x( 'Lift on hover', 'Style variation name', 'fluffless-suite' ),
			'zoom-survol'          => _x( 'Zoom on hover', 'Style variation name', 'fluffless-suite' ),
			'apparait'             => _x( 'Fade in', 'Style variation name', 'fluffless-suite' ),
			'glisse'               => _x( 'Slide in', 'Style variation name', 'fluffless-suite' ),
			'grandit'              => _x( 'Grow in', 'Style variation name', 'fluffless-suite' ),
			'reduit'               => _x( 'Sticky, shrinks on scroll', 'Style variation name', 'fluffless-suite' ),
			'index-mono'           => _x( 'Mono index', 'Style variation name', 'fluffless-suite' ),
			'sur-titre'            => _x( 'Eyebrow', 'Style variation name', 'fluffless-suite' ),
			'geant'                => _x( 'Oversized', 'Style variation name', 'fluffless-suite' ),
			'texte-contour'        => _x( 'Outlined text', 'Style variation name', 'fluffless-suite' ),
			'degrade-texte'        => _x( 'Gradient text', 'Style variation name', 'fluffless-suite' ),
			'mot-manuscrit'        => _x( 'Handwritten word', 'Style variation name', 'fluffless-suite' ),
			'surligneur'           => _x( 'Highlighter', 'Style variation name', 'fluffless-suite' ),
			'etiquette-verticale'  => _x( 'Vertical label', 'Style variation name', 'fluffless-suite' ),
			'plan'                 => _x( 'Blueprint', 'Style variation name', 'fluffless-suite' ),
			'liste-filets'         => _x( 'Ruled list', 'Style variation name', 'fluffless-suite' ),
			'disque-tronque'       => _x( 'Clipped disc', 'Style variation name', 'fluffless-suite' ),
			'autocollant'          => _x( 'Sticker', 'Style variation name', 'fluffless-suite' ),
			'fermeture'            => _x( 'Closing block', 'Style variation name', 'fluffless-suite' ),
			'rythme-alterne'       => _x( 'Alternating rhythm', 'Style variation name', 'fluffless-suite' ),
			'filigrane'            => _x( 'Floating watermark', 'Style variation name', 'fluffless-suite' ),
			'empilees'             => _x( 'Stacked sections', 'Style variation name', 'fluffless-suite' ),
			'inversion'            => _x( 'Inverts over backgrounds', 'Style variation name', 'fluffless-suite' ),
			'cercle'               => _x( 'Circle', 'Style variation name', 'fluffless-suite' ),
			'pilule'               => _x( 'Pill', 'Style variation name', 'fluffless-suite' ),
			'arche'                => _x( 'Arch', 'Style variation name', 'fluffless-suite' ),
			'coins-decales'        => _x( 'Offset corners', 'Style variation name', 'fluffless-suite' ),
			'photo-cerclee'        => _x( 'Ringed photo', 'Style variation name', 'fluffless-suite' ),
			'fond-decale'          => _x( 'Backdrop', 'Style variation name', 'fluffless-suite' ),
			'photo-panneau'        => _x( 'Photo panel', 'Style variation name', 'fluffless-suite' ),
			'photo-panneau-accent' => _x( 'Photo panel, accent', 'Style variation name', 'fluffless-suite' ),
			'survol-surlignage'    => _x( 'Hover: live highlight', 'Style variation name', 'fluffless-suite' ),
			'survol-contraste'     => _x( 'Hover: contrast swap', 'Style variation name', 'fluffless-suite' ),
			'survol-etire'         => _x( 'Hover: stretching underline', 'Style variation name', 'fluffless-suite' ),
			'survol-incline'       => _x( 'Hover: tilt', 'Style variation name', 'fluffless-suite' ),
			'permutation'          => _x( 'Label swap', 'Style variation name', 'fluffless-suite' ),
			'ondulation'           => _x( 'Ripple', 'Style variation name', 'fluffless-suite' ),
			'capsule'              => _x( 'Floating capsule', 'Style variation name', 'fluffless-suite' ),
			'detache'              => _x( 'Detaches on scroll', 'Style variation name', 'fluffless-suite' ),
		);
	}

	/** Dossier des définitions JSON. */
	public static function dir(): string {
		return dirname( __DIR__, 2 ) . '/styles/blocks/';
	}

	/**
	 * Chemin de la feuille d'un style (le fichier peut ne pas exister).
	 *
	 * @param string $slug Slug du style.
	 */
	public static function css_path( string $slug ): string {
		return dirname( __DIR__, 2 ) . '/assets/block-styles/' . $slug . '.css';
	}

	/**
	 * Racines de thème où chercher une feuille : enfant actif, puis parent s'il diffère.
	 * Jamais Suite : ses feuilles passent par css_path().
	 *
	 * @return list<array{dir: string, url: string}>
	 */
	public static function theme_roots(): array {
		$roots = array(
			array(
				'dir' => get_stylesheet_directory(),
				'url' => get_stylesheet_directory_uri(),
			),
		);
		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$roots[] = array(
				'dir' => get_template_directory(),
				'url' => get_template_directory_uri(),
			);
		}
		return $roots;
	}

	/**
	 * Feuilles assets/block-styles/*.css des thèmes, slug => chemin et adresse ; la première racine
	 * qui a un slug gagne. Un seul parcours par racine et par requête ; un nom de fichier qui n'est
	 * pas un slug valide est ignoré.
	 *
	 * @return array<string, array{path: string, url: string}>
	 */
	public static function theme_sheets(): array {
		if ( null !== self::$theme_sheets ) {
			return self::$theme_sheets;
		}
		$sheets = array();
		foreach ( self::theme_roots() as $root ) {
			$files = glob( $root['dir'] . '/assets/block-styles/*.css' );
			foreach ( false === $files ? array() : $files as $file ) {
				$slug = basename( $file, '.css' );
				if ( 1 === preg_match( self::SLUG_PATTERN, $slug ) && ! isset( $sheets[ $slug ] ) && is_readable( $file ) ) {
					$sheets[ $slug ] = array(
						'path' => $file,
						'url'  => $root['url'] . '/assets/block-styles/' . $slug . '.css',
					);
				}
			}
		}
		self::$theme_sheets = $sheets;
		return $sheets;
	}

	/**
	 * Chemin de la feuille réellement retenue pour un slug : thème enfant, parent, puis Suite
	 * (css_path(), lisible ou non). Les survols de liens n'ont pas de fichier : voir sheet().
	 *
	 * @param string $slug Slug du style.
	 */
	public static function resolved_path( string $slug ): string {
		return self::theme_sheets()[ $slug ]['path'] ?? self::css_path( $slug );
	}

	/**
	 * Adresse et version de la feuille retenue, pour l'éditeur : date du fichier pour un thème,
	 * version de l'extension pour Suite.
	 *
	 * @param string $slug Slug du style.
	 * @return array{url: string, version: string}
	 */
	public static function sheet_url( string $slug ): array {
		$theme = self::theme_sheets()[ $slug ] ?? null;
		if ( null !== $theme ) {
			$mtime = filemtime( $theme['path'] );
			return array(
				'url'     => $theme['url'],
				'version' => false === $mtime ? VERSION : (string) $mtime,
			);
		}
		return array(
			'url'     => plugins_url( 'assets/block-styles/' . $slug . '.css', FILE ),
			'version' => VERSION,
		);
	}

	/** Oublie les feuilles de thème trouvées (tests, fichier ajouté dans la même requête). */
	public static function flush(): void {
		self::$theme_sheets = null;
	}

	/**
	 * Poignée de la feuille d'un style.
	 *
	 * @param string $slug Slug du style.
	 */
	public static function handle( string $slug ): string {
		return self::HANDLE_PREFIX . $slug;
	}

	/**
	 * Enregistre chaque style et branche sa feuille sur le rendu des blocs concernés.
	 */
	public static function register(): void {
		if ( ! Patterns::applies() ) {
			return;
		}
		$labels = self::labels();
		foreach ( self::all() as $style ) {
			register_block_style(
				$style['blockTypes'],
				array(
					'name'       => $style['slug'],
					'label'      => $labels[ $style['slug'] ] ?? $style['title'],
					'style_data' => $style['styles'],
				)
			);
			if ( ! self::has_sheet( $style['slug'] ) ) {
				continue;
			}
			foreach ( $style['blockTypes'] as $block_type ) {
				self::$sheets[ $block_type ][] = $style['slug'];
			}
		}
		$editor_script = plugins_url( 'assets/block-styles-editor.js', FILE );
		if ( '' !== $editor_script ) {
			wp_register_script(
				self::EDITOR_SCRIPT,
				$editor_script,
				array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
				VERSION,
				true
			);
		}
		add_filter( 'render_block', array( self::class, 'enqueue_used' ), 10, 2 );
		add_filter( 'render_block_core/group', array( self::class, 'focusable' ) );
		add_filter( 'render_block_core/group', array( self::class, 'band_pause' ) );
		add_filter( 'render_block_core/image', array( Ring::class, 'image' ) );
		add_filter( 'render_block_core/button', array( LabelSwap::class, 'button' ), 10, 2 );
		add_filter( 'render_block_core/navigation', array( LabelSwap::class, 'navigation' ), 10, 2 );
	}

	/**
	 * Un style a une feuille quand son fichier existe ou quand elle est générée (survols de liens).
	 *
	 * @param string $slug Slug du style.
	 */
	public static function has_sheet( string $slug ): bool {
		return '' !== LinkHover::effect_of( $slug ) || is_readable( self::resolved_path( $slug ) );
	}

	/**
	 * Feuille d'un style : générée par LinkHover pour les survols de liens (source unique partagée
	 * avec le réglage du site), lue dans le thème enfant, le parent, puis assets/block-styles/ de
	 * Suite sinon. Chaîne vide sans feuille. Une feuille de thème est rendue telle quelle, ses url()
	 * relatives résolues par rapport au fichier ; celles de Suite sont débarrassées de leurs
	 * commentaires (elles n'ont pas de « /* » dans une chaîne, un tiers pourrait).
	 *
	 * @param string $slug Slug du style.
	 */
	public static function sheet( string $slug ): string {
		$effect = LinkHover::effect_of( $slug );
		if ( '' !== $effect ) {
			return LinkHover::rules( $effect, '.is-style-' . $slug );
		}
		$theme = self::theme_sheets()[ $slug ] ?? null;
		// Fichiers locaux du thème ou de l'extension, pas des URL.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( null !== $theme ) {
			return _wp_normalize_relative_css_links( (string) file_get_contents( $theme['path'] ), $theme['url'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- feuille locale du thème, pas une adresse distante.
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_readable( self::css_path( $slug ) ) ? self::strip_comments( (string) file_get_contents( self::css_path( $slug ) ) ) : '';
	}

	/**
	 * Charge la feuille d'un style au rendu d'un bloc qui le porte, et seulement alors.
	 *
	 * @param string|mixed $content HTML rendu du bloc.
	 * @param array|mixed  $block   Bloc analysé.
	 */
	public static function enqueue_used( $content, $block ): string {
		$content = (string) $content;
		$name    = is_array( $block ) && is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		$class   = is_array( $block ) && is_string( $block['attrs']['className'] ?? null ) ? $block['attrs']['className'] : '';
		if ( '' === $name || ! str_contains( $class, 'is-style-' ) ) {
			return $content;
		}
		$classes = (array) preg_split( '/\s+/', $class );
		$own     = self::$sheets[ $name ] ?? array();
		foreach ( $own as $slug ) {
			if ( in_array( 'is-style-' . $slug, $classes, true ) ) {
				self::enqueue( $slug );
			}
		}
		// Styles enregistrés par le thème (JSON de styles/ ou register_block_style) dont la feuille
		// est dans le thème. Le registre est consulté au rendu : les variations d'un thème s'y
		// inscrivent à la demande, avant ce filtre.
		foreach ( array_keys( self::theme_sheets() ) as $slug ) {
			if ( ! in_array( $slug, $own, true ) && in_array( 'is-style-' . $slug, $classes, true ) && \WP_Block_Styles_Registry::get_instance()->is_registered( $name, $slug ) ) {
				self::enqueue( $slug );
			}
		}
		return $content;
	}

	/**
	 * Feuille d'un style, en ligne et sans commentaires (une fois par page).
	 *
	 * @param string $slug Slug du style.
	 */
	public static function enqueue( string $slug ): void {
		$handle = self::handle( $slug );
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, array(), VERSION );
			wp_add_inline_style( $handle, self::sheet( $slug ) );
		}
		wp_enqueue_style( $handle );
	}

	/**
	 * Retire les commentaires et les lignes vides d'une feuille (les nôtres n'ont pas de « /* » dans une chaîne).
	 *
	 * @param string $css Feuille.
	 */
	public static function strip_comments( string $css ): string {
		return trim( (string) preg_replace( array( '#/\*.*?\*/#s', '/\n\s*\n/' ), array( '', "\n" ), $css ) );
	}

	/**
	 * Bande défilante : bouton Pause sans script (WCAG 2.2.2), comme le bloc Bandeau. Une case à
	 * cocher en premier enfant (la feuille met en pause tout ce qui la suit quand elle est cochée)
	 * et son libellé en dernier enfant. Sans mouvement (mouvement réduit), la feuille masque les deux.
	 *
	 * @param string|mixed $content HTML rendu du bloc.
	 */
	public static function band_pause( $content ): string {
		$content = (string) $content;
		$tags    = new \WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag() || true !== $tags->has_class( 'is-style-bande' ) ) {
			return $content;
		}
		$open  = strpos( $content, '>' );
		$close = strrpos( $content, '</' );
		if ( false === $open || false === $close || $close <= $open ) {
			return $content;
		}
		$id = Ids::stable( 'fl-bande-', $content );
		return substr( $content, 0, $open + 1 )
			. '<input type="checkbox" id="' . esc_attr( $id ) . '" class="fl-bande-box">'
			. substr( $content, $open + 1, $close - $open - 1 )
			. '<label for="' . esc_attr( $id ) . '" class="fl-bande-pause">' . esc_html__( 'Pause', 'fluffless-suite' ) . '</label>'
			. substr( $content, $close );
	}

	/**
	 * Carte recto verso : sans lien ni bouton dedans, :focus-within ne se déclenche jamais.
	 * Le conteneur reçoit alors tabindex="0", role="group" et le texte du premier titre comme
	 * nom accessible. Avec un élément focalisable dedans, rien n'est ajouté : ce serait un arrêt
	 * de tabulation de trop. Un tabindex déjà posé est gardé.
	 *
	 * @param string|mixed $content HTML rendu du bloc.
	 */
	public static function focusable( $content ): string {
		$content = (string) $content;
		$tags    = new \WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag() || null !== $tags->get_attribute( 'tabindex' ) ) {
			return $content;
		}
		$styled = false;
		foreach ( self::FOCUSABLE as $slug ) {
			$styled = $styled || true === $tags->has_class( 'is-style-' . $slug );
		}
		if ( ! $styled || ! $tags->set_bookmark( 'card' ) ) {
			return $content;
		}
		while ( $tags->next_tag() ) {
			if ( self::is_focusable( $tags ) ) {
				return $content;
			}
		}
		$tags->seek( 'card' );
		$tags->set_attribute( 'tabindex', '0' );
		if ( null === $tags->get_attribute( 'role' ) ) {
			$tags->set_attribute( 'role', 'group' );
		}
		$label = 1 === preg_match( '#<h[1-6][^>]*>(.*?)</h[1-6]>#is', $content, $heading ) ? trim( wp_strip_all_tags( $heading[1] ) ) : '';
		if ( '' !== $label && null === $tags->get_attribute( 'aria-label' ) && null === $tags->get_attribute( 'aria-labelledby' ) ) {
			$tags->set_attribute( 'aria-label', $label );
		}
		return $tags->get_updated_html();
	}

	/**
	 * La balise courante reçoit-elle déjà le focus clavier ?
	 *
	 * @param \WP_HTML_Tag_Processor $tags Processeur placé sur une balise ouvrante.
	 */
	private static function is_focusable( \WP_HTML_Tag_Processor $tags ): bool {
		if ( null !== $tags->get_attribute( 'tabindex' ) ) {
			return true;
		}
		if ( 'A' === $tags->get_tag() ) {
			return null !== $tags->get_attribute( 'href' );
		}
		return in_array( $tags->get_tag(), array( 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'SUMMARY', 'IFRAME' ), true );
	}

	/**
	 * Éditeur : toutes les feuilles, pour que l'aperçu d'un style choisi soit juste (enqueue_block_assets).
	 */
	public static function editor_assets(): void {
		if ( ! is_admin() || ! Patterns::applies() ) {
			return;
		}
		foreach ( self::all() as $style ) {
			if ( '' !== LinkHover::effect_of( $style['slug'] ) ) {
				self::enqueue( $style['slug'] );
			} elseif ( is_readable( self::resolved_path( $style['slug'] ) ) ) {
				$sheet = self::sheet_url( $style['slug'] );
				wp_enqueue_style( self::handle( $style['slug'] ), $sheet['url'], array(), $sheet['version'] );
			}
		}
		// Feuilles de thème des styles que Suite n'a pas enregistrés : tout style du registre, une
		// fois les variations du thème inscrites (get_theme_data() les enregistre à la demande).
		if ( array() === self::theme_sheets() ) {
			return;
		}
		\WP_Theme_JSON_Resolver::get_theme_data();
		$registry = \WP_Block_Styles_Registry::get_instance();
		foreach ( self::theme_sheets() as $slug => $sheet ) {
			if ( '' !== LinkHover::effect_of( $slug ) || wp_style_is( self::handle( $slug ), 'enqueued' ) ) {
				continue;
			}
			foreach ( array_keys( $registry->get_all_registered() ) as $block_type ) {
				if ( $registry->is_registered( $block_type, $slug ) ) {
					$url = self::sheet_url( $slug );
					wp_enqueue_style( self::handle( $slug ), $url['url'], array(), $url['version'] );
					break;
				}
			}
		}
	}

	/**
	 * Éditeur : le panneau des styles à options, avec ses listes (une seule source, ici) et ses
	 * traductions. Un style seul, sans ce script, marche encore : les classes se posent à la main.
	 */
	public static function editor_script(): void {
		if ( ! Patterns::applies() || ! wp_script_is( self::EDITOR_SCRIPT, 'registered' ) ) {
			return;
		}
		wp_add_inline_script( self::EDITOR_SCRIPT, 'window.flufflessBlockStyles=' . wp_json_encode( array( 'panels' => self::panels_data() ) ) . ';', 'before' );
		wp_enqueue_script( self::EDITOR_SCRIPT );
		wp_set_script_translations( self::EDITOR_SCRIPT, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
	}

	/**
	 * Données du panneau : par style à options, les blocs qui l'offrent et, par groupe, la tête de
	 * classe (tout ce qui précède la valeur) et les valeurs. Un style absent du dossier est omis.
	 *
	 * @return list<array{style: string, blocks: list<string>, groups: array<string, array{head: string, values: list<string>}>}>
	 */
	public static function panels_data(): array {
		$blocks = array();
		foreach ( self::all() as $style ) {
			$blocks[ $style['slug'] ] = $style['blockTypes'];
		}
		$panels = array();
		foreach ( self::STYLE_OPTIONS as $slug => $config ) {
			if ( ! isset( $blocks[ $slug ] ) ) {
				continue;
			}
			$groups = array();
			foreach ( $config['options'] as $group => $values ) {
				$groups[ $group ] = array(
					'head'   => $config['prefix'] . ( $config['grouped'] ? $group . '-' : '' ),
					'values' => $values,
				);
			}
			$panels[] = array(
				'style'  => $slug,
				'blocks' => $blocks[ $slug ],
				'groups' => $groups,
			);
		}
		return $panels;
	}

	/**
	 * Styles valides du dossier, triés par nom de fichier.
	 *
	 * @return list<array{slug: string, title: string, blockTypes: list<string>, styles: array<string, mixed>}>
	 */
	public static function all(): array {
		$files = glob( self::dir() . '*.json' );
		if ( false === $files ) {
			return array();
		}
		sort( $files );
		return array_values( array_filter( array_map( array( self::class, 'read' ), $files ) ) );
	}

	/**
	 * Lit et valide une définition JSON.
	 *
	 * @param string $file Chemin absolu du fichier.
	 * @return array{slug: string, title: string, blockTypes: list<string>, styles: array<string, mixed>}|null Null si invalide.
	 */
	public static function read( string $file ): ?array {
		// Fichier local livré avec l'extension, pas une URL.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw  = is_readable( $file ) ? file_get_contents( $file ) : false;
		$data = false === $raw ? null : json_decode( $raw, true );

		$slug   = is_array( $data ) && is_string( $data['slug'] ?? null ) ? $data['slug'] : '';
		$blocks = is_array( $data ) && is_array( $data['blockTypes'] ?? null ) ? array_values( array_filter( $data['blockTypes'], 'is_string' ) ) : array();
		if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) || array() === $blocks ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'Style de bloc ignoré : %s. Champs attendus : « slug » en minuscules et tirets, « blockTypes » non vide (reçu : slug « %s », %d type(s) de bloc).', $file, $slug, count( $blocks ) ) ),
				'0.1.0'
			);
			return null;
		}

		return array(
			'slug'       => $slug,
			'title'      => is_string( $data['title'] ?? null ) ? $data['title'] : $slug,
			'blockTypes' => $blocks,
			'styles'     => is_array( $data['styles'] ?? null ) ? $data['styles'] : array(),
		);
	}
}
