<?php
/**
 * Module Expressivité : séparateurs de forme (bloc Vague), Récit défilant, puis pop-ups.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Blocks\Bandeau;
use Fluffless\Suite\Blocks\Flottant;
use Fluffless\Suite\Blocks\Graphique;
use Fluffless\Suite\Blocks\Sections;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Presentation\Ids;
use WP_Block;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Bloc fluffless/vague : un séparateur entre deux sections, en SVG en ligne rendu côté serveur.
 * - Un seul tracé par forme, dessiné pour le bas d'une section ; en haut, un miroir vertical en CSS.
 * - Le tracé suit currentColor ; la couleur vient de la palette du thème, jamais d'une valeur libre.
 * - Décoratif : caché aux lecteurs d'écran, jamais focalisable.
 * - shape() en CSS n'est pas utilisé : le SVG en ligne rend déjà la même vague dans tous les
 *   navigateurs, un second rendu ne ferait qu'ajouter du poids.
 *
 * Site public : 0 JS ; moins de 1 ko par vague, CSS en ligne (quelques centaines d'octets)
 * seulement sur les pages qui en contiennent une.
 *
 * Bloc fluffless/recit : une scène collante (premier enfant) et des étapes (enfants du second)
 * qui apparaissent au défilement.
 * - Structure par position, pas par classe : une classe retirée dans l'éditeur ne casse rien.
 * - Animation par animation-timeline: view() sous @supports ; ailleurs, repli IntersectionObserver
 *   en ligne (moins de 1,2 ko), chargé seulement si un récit animé est rendu.
 * - Rien n'est caché sans JS ni en mouvement réduit : toute règle qui masque vit sous
 *   prefers-reduced-motion: no-preference, et le repli ne masque que les étapes encore sous l'écran.
 * - Sous 782 px, la scène n'est plus collante : scène puis étapes, dans l'ordre du contenu.
 *
 * Bloc fluffless/popup : 0 JS, ouverture au clic seulement (aucune option d'ouverture
 * automatique, au délai ou à l'intention de sortie).
 * - Fenêtre : un vrai <button popovertarget> et un panneau popover ; Échap et le clic hors du
 *   panneau le ferment nativement. Sans prise en charge de popover, le panneau reste affiché
 *   dans la page : le contenu n'est jamais perdu.
 * - Bandeau d'annonce : <dialog open> refermé par <form method="dialog">, sans script.
 * - Le CSS ne pose jamais display sur le panneau ni sur un bandeau fermé : il écraserait le
 *   display:none du navigateur et les laisserait visibles.
 */
final class Expression implements Module {

	public const VAGUE        = 'fluffless/vague';
	public const STYLE_VAGUE  = 'fluffless-vague';
	public const RECIT        = 'fluffless/recit';
	public const STYLE_RECIT  = 'fluffless-recit';
	public const SCRIPT_RECIT = 'fluffless-recit';

	/** Côté de la scène sur grand écran. */
	public const RECIT_SCENES = array( 'start', 'end' );

	/** Entrée des étapes : montée et fondu, fondu seul, aucune (scène collante seulement). */
	public const RECIT_EFFECTS = array( 'rise', 'fade', 'none' );

	public const POPUP       = 'fluffless/popup';
	public const STYLE_POPUP = 'fluffless-popup';

	/** Fenêtre ouverte au clic, ou bandeau d'annonce refermable. */
	public const POPUP_MODES = array( 'popup', 'banner' );

	/** Bloc Panneau de menu : un <details> dans le bloc Navigation du cœur, panneau de blocs libres, 0 JS. */
	public const PANNEAU        = 'fluffless/panneau-menu';
	public const PANNEAU_WIDTHS = array( 'full', 'content' );

	/** Longueur maximale d'un libellé (bouton, fermeture), en caractères. */
	public const POPUP_LABEL_MAX = 80;

	/** Tracés, dans une boîte de 1200 × 120, remplis jusqu'au bas. Clé = attribut shape. */
	public const VAGUE_SHAPES = array(
		// Houle asymétrique : longue descente puis remontée douce, une seule ondulation.
		'wave'  => 'M0 10C117 10 233 18 350 36C483 58 567 90 708 92C842 94 1017 60 1200 44V120H0Z',
		'soft'  => 'M0 96Q600 0 1200 96V120H0Z',
		'peaks' => 'M0 110L150 40 300 96 450 20 600 88 750 30 900 100 1050 50 1200 104V120H0Z',
		'tilt'  => 'M0 120L1200 8V120Z',
	);

	/**
	 * Séparateurs en ligne : un filet (border en currentColor) de part et d'autre, et un motif
	 * centré pour three-dots et ornament. La hauteur devient l'espace vertical réservé.
	 */
	public const VAGUE_LINES = array( 'line', 'dots', 'three-dots', 'ornament' );

	/**
	 * Formes d'accent : un motif centré seul, sans filet, haut comme le bloc ; l'éditeur passe la
	 * couleur à accent quand on les choisit. Les mêmes formes que le style de bloc « Forme d'accent ».
	 */
	public const VAGUE_ACCENTS = array( 'disc', 'half-moon', 'ring', 'drop', 'dot-grid' );

	/** Transparence en % (attribut opacity) ; 100 n'écrit rien. */
	public const VAGUE_OPACITIES = array( 10, 25, 50, 100 );

	/**
	 * Motif centré des séparateurs en ligne et des formes d'accent : [viewBox, tracé]. Trois disques,
	 * un losange ; disque, demi-lune, anneau (deux cercles de sens opposés), goutte, trame de points.
	 */
	public const VAGUE_MARKS = array(
		'three-dots' => array( '0 0 64 16', 'M5 8a3 3 0 1 0 6 0a3 3 0 1 0-6 0M29 8a3 3 0 1 0 6 0a3 3 0 1 0-6 0M53 8a3 3 0 1 0 6 0a3 3 0 1 0-6 0' ),
		'ornament'   => array( '0 0 16 16', 'M8 1 15 8 8 15 1 8Z' ),
		'disc'       => array( '0 0 32 32', 'M16 2a14 14 0 1 0 0 28a14 14 0 1 0 0-28Z' ),
		'half-moon'  => array( '0 0 32 16', 'M2 16a14 14 0 0 1 28 0Z' ),
		'ring'       => array( '0 0 32 32', 'M16 2a14 14 0 1 0 0 28a14 14 0 1 0 0-28ZM16 9a7 7 0 1 1 0 14a7 7 0 1 1 0-14Z' ),
		'drop'       => array( '0 0 32 32', 'M16 2C16 2 5 15 5 21a11 11 0 0 0 22 0C27 15 16 2 16 2Z' ),
		'dot-grid'   => array( '0 0 88 40', 'M5 8a3 3 0 1 0 6 0a3 3 0 1 0-6 0M23 8a3 3 0 1 0 6 0a3 3 0 1 0-6 0M41 8a3 3 0 1 0 6 0a3 3 0 1 0-6 0M59 8a3 3 0 1 0 6 0a3 3 0 1 0-6 0M77 8a3 3 0 1 0 6 0a3 3 0 1 0-6 0M5 20a3 3 0 1 0 6 0a3 3 0 1 0-6 0M23 20a3 3 0 1 0 6 0a3 3 0 1 0-6 0M41 20a3 3 0 1 0 6 0a3 3 0 1 0-6 0M59 20a3 3 0 1 0 6 0a3 3 0 1 0-6 0M77 20a3 3 0 1 0 6 0a3 3 0 1 0-6 0M5 32a3 3 0 1 0 6 0a3 3 0 1 0-6 0M23 32a3 3 0 1 0 6 0a3 3 0 1 0-6 0M41 32a3 3 0 1 0 6 0a3 3 0 1 0-6 0M59 32a3 3 0 1 0 6 0a3 3 0 1 0-6 0M77 32a3 3 0 1 0 6 0a3 3 0 1 0-6 0' ),
	);

	/** Préréglages de hauteur de l'éditeur (basse, moyenne, haute), écrits dans l'attribut height. */
	public const VAGUE_PRESETS = array(
		'low'    => 32,
		'medium' => 64,
		'high'   => 128,
	);

	public const VAGUE_POSITIONS = array( 'bottom', 'top', 'both' );

	/** Hauteur en px : [min, max, défaut]. */
	public const VAGUE_HEIGHT = array( 16, 240, 64 );

	/** Couleur par défaut : la surface du thème, qui suit souvent la section suivante. */
	public const VAGUE_COLOUR = 'surface';

	/** {@inheritDoc} */
	public static function id(): string {
		return 'expression';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Expression: shapes and motion', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Wave dividers between sections, scrolling stories with a sticky scene, and pop-ups that open only on click, plus a dismissible announcement bar. Motion uses native CSS where the browser supports it, with a fallback script under 1.2 KB elsewhere, and stops when visitors ask for reduced motion. Pop-ups need no JavaScript. CSS and script load only on pages that use them.', 'fluffless-suite' );
	}

	/**
	 * Inactif par défaut : le module change le rendu public, comme Canevas.
	 */
	public static function enabled_by_default(): bool {
		return false;
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
		unset( $input );
		return array();
	}

	/** {@inheritDoc} */
	public static function fields(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_action( 'enqueue_block_assets', array( self::class, 'editor_canvas_styles' ) );
		add_filter( 'fluffless_visitor_scripts', array( self::class, 'declare_visitor_scripts' ) );
	}

	/**
	 * Déclare le JavaScript que ce module peut servir aux visiteurs : les modules de vue des blocs
	 * Avant/Après et Vidéo, et le script de repli du récit (écrit en ligne, mesuré tel quel).
	 *
	 * @param mixed $scripts Déclarations déjà posées par les autres composants.
	 * @return array<string, list<string|int>>
	 */
	public static function declare_visitor_scripts( $scripts ): array {
		$scripts = is_array( $scripts ) ? $scripts : array();
		$files   = array_map( static fn( string $slug ): string => 'blocks/' . $slug . '/view.js', Sections::VIEW );

		$scripts[ self::id() ] = array_merge( $files, array( strlen( self::recit_js() ) ) );
		return $scripts;
	}

	/* ---------- Bloc Vague ---------- */

	/** Enregistre les blocs depuis leur block.json, avec le rendu de ce module. */
	public static function register_block(): void {
		register_block_type(
			dirname( FILE ) . '/blocks/vague',
			array( 'render_callback' => array( self::class, 'render_vague' ) )
		);
		register_block_type(
			dirname( FILE ) . '/blocks/recit',
			array( 'render_callback' => array( self::class, 'render_recit' ) )
		);
		register_block_type(
			dirname( FILE ) . '/blocks/popup',
			array( 'render_callback' => array( self::class, 'render_popup' ) )
		);
		register_block_type(
			dirname( FILE ) . '/blocks/panneau-menu',
			array( 'render_callback' => array( self::class, 'render_panneau' ) )
		);
		register_block_type(
			dirname( FILE ) . '/blocks/flottant',
			array( 'render_callback' => array( Flottant::class, 'render' ) )
		);
		register_block_type(
			dirname( FILE ) . '/blocks/bandeau',
			array( 'render_callback' => array( Bandeau::class, 'render' ) )
		);
		register_block_type(
			dirname( FILE ) . '/blocks/graphique',
			array( 'render_callback' => array( Graphique::class, 'render' ) )
		);
		Sections::register();
	}

	/**
	 * Attributs de la vague, bornés. Toute valeur hors bornes ou hors liste reprend sa borne ou son défaut.
	 *
	 * @param array<mixed> $attributes Attributs bruts.
	 * @return array{shape: string, position: string, height: int, flip: bool, colour: string, opacity: int, align: string, className: string}
	 */
	public static function vague_attributes( array $attributes ): array {
		$shape    = $attributes['shape'] ?? null;
		$position = $attributes['position'] ?? null;
		$colour   = $attributes['colour'] ?? null;
		$colour   = 'border' === $colour ? 'divider' : $colour; // Ancien nom du rôle.
		$height   = $attributes['height'] ?? null;
		$opacity  = $attributes['opacity'] ?? null;
		$clean    = array(
			'shape'     => is_string( $shape ) && ( isset( self::VAGUE_SHAPES[ $shape ] ) || in_array( $shape, self::VAGUE_LINES, true ) || in_array( $shape, self::VAGUE_ACCENTS, true ) ) ? $shape : 'wave',
			'position'  => in_array( $position, self::VAGUE_POSITIONS, true ) ? (string) $position : 'bottom',
			'height'    => is_numeric( $height ) ? max( self::VAGUE_HEIGHT[0], min( self::VAGUE_HEIGHT[1], (int) round( (float) $height ) ) ) : self::VAGUE_HEIGHT[2],
			'flip'      => true === ( $attributes['flip'] ?? false ),
			'colour'    => is_string( $colour ) && in_array( $colour, self::palette_slugs(), true ) ? $colour : self::VAGUE_COLOUR,
			'opacity'   => is_numeric( $opacity ) && in_array( (int) $opacity, self::VAGUE_OPACITIES, true ) ? (int) $opacity : 100,
			'align'     => self::align( $attributes['align'] ?? null ),
			'className' => self::class_list( $attributes['className'] ?? null ),
		);
		return $clean;
	}

	/**
	 * Alignement permis par les deux blocs : large ou pleine largeur, sinon aucun.
	 *
	 * @param mixed $align Valeur brute.
	 */
	public static function align( $align ): string {
		return in_array( $align, array( 'wide', 'full' ), true ) ? (string) $align : '';
	}

	/**
	 * Classes additionnelles, assainies une à une.
	 *
	 * @param mixed $value Valeur brute de className.
	 */
	public static function class_list( $value ): string {
		$tokens = is_string( $value ) ? preg_split( '/\s+/', $value ) : false;
		return implode( ' ', array_filter( array_map( 'sanitize_html_class', false === $tokens ? array() : $tokens ) ) );
	}

	/**
	 * Identifiants de la palette active (thème et personnalisée). Ils ne contiennent que des
	 * caractères sûrs : on les revérifie quand même avant de les écrire dans une variable CSS.
	 *
	 * @return list<string>
	 */
	public static function palette_slugs(): array {
		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		$slugs   = array();
		foreach ( is_array( $palette ) ? $palette : array() as $origin ) {
			foreach ( is_array( $origin ) ? $origin : array() as $entry ) {
				$slug = is_array( $entry ) && is_string( $entry['slug'] ?? null ) ? $entry['slug'] : '';
				if ( 1 === preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
					$slugs[] = $slug;
				}
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Rendu de la vague : une enveloppe décorative et un SVG étiré sur toute la largeur.
	 *
	 * @param mixed $attributes Attributs du bloc (vérifiés : le rendu peut être appelé hors du cœur).
	 */
	public static function render_vague( $attributes ): string {
		$a       = self::vague_attributes( is_array( $attributes ) ? $attributes : array() );
		$classes = array_filter(
			array(
				'wp-block-fluffless-vague',
				'fluffless-vague',
				'is-shape-' . $a['shape'],
				isset( self::VAGUE_SHAPES[ $a['shape'] ] ) ? '' : ( in_array( $a['shape'], self::VAGUE_ACCENTS, true ) ? 'is-mark' : 'is-line' ),
				'is-' . $a['position'],
				$a['flip'] ? 'is-flip' : '',
				'' !== $a['align'] ? 'align' . $a['align'] : '',
				$a['className'],
			)
		);
		$style   = sprintf( '--fluffless-vague-h:%dpx;color:var(--wp--preset--color--%s)', $a['height'], $a['colour'] );
		if ( 100 > $a['opacity'] ) {
			$style .= ';opacity:' . rtrim( sprintf( '%.2F', $a['opacity'] / 100 ), '0' );
		}
		self::enqueue_inline( self::STYLE_VAGUE, self::vague_css() );
		if ( ! isset( self::VAGUE_SHAPES[ $a['shape'] ] ) ) {
			$mark = self::VAGUE_MARKS[ $a['shape'] ] ?? null;
			return sprintf(
				'<div class="%s" style="%s" aria-hidden="true">%s</div>',
				esc_attr( implode( ' ', $classes ) ),
				esc_attr( $style ),
				null === $mark ? '' : sprintf( '<svg viewBox="%s" focusable="false"><path fill="currentColor" d="%s"/></svg>', esc_attr( $mark[0] ), esc_attr( $mark[1] ) )
			);
		}
		$svg = sprintf( '<svg viewBox="0 0 1200 120" preserveAspectRatio="none" focusable="false"><path fill="currentColor" d="%s"/></svg>', esc_attr( self::VAGUE_SHAPES[ $a['shape'] ] ) );
		// Les deux : la forme en haut (retournée) puis en bas, une bande qui encadre un bloc de couleur.
		return sprintf(
			'<div class="%s" style="%s" aria-hidden="true">%s</div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $style ),
			'both' === $a['position'] ? $svg . $svg : $svg
		);
	}

	/* ---------- Bloc Récit défilant ---------- */

	/**
	 * Attributs du récit, bornés aux listes fermées.
	 *
	 * @param array<mixed> $attributes Attributs bruts.
	 * @return array{scene: string, effect: string, align: string, className: string}
	 */
	public static function recit_attributes( array $attributes ): array {
		$scene  = $attributes['scene'] ?? null;
		$effect = $attributes['effect'] ?? null;
		return array(
			'scene'     => in_array( $scene, self::RECIT_SCENES, true ) ? (string) $scene : 'start',
			'effect'    => in_array( $effect, self::RECIT_EFFECTS, true ) ? (string) $effect : 'rise',
			'align'     => self::align( $attributes['align'] ?? null ),
			'className' => self::class_list( $attributes['className'] ?? null ),
		);
	}

	/**
	 * Rendu du récit : une enveloppe autour du contenu enregistré, qui n'est jamais réécrit.
	 * Le script de repli n'est chargé que si les étapes s'animent.
	 *
	 * @param mixed $attributes Attributs du bloc.
	 * @param mixed $content    HTML des blocs enfants, déjà rendu par le cœur.
	 */
	public static function render_recit( $attributes, $content = '' ): string {
		$a       = self::recit_attributes( is_array( $attributes ) ? $attributes : array() );
		$classes = array_filter(
			array(
				'wp-block-fluffless-recit',
				'fluffless-recit',
				'is-scene-' . $a['scene'],
				'is-effect-' . $a['effect'],
				'' !== $a['align'] ? 'align' . $a['align'] : '',
				$a['className'],
			)
		);
		self::enqueue_inline( self::STYLE_RECIT, self::recit_css() );
		if ( 'none' !== $a['effect'] && ! wp_script_is( self::SCRIPT_RECIT, 'registered' ) ) {
			wp_register_script( self::SCRIPT_RECIT, false, array(), VERSION, array( 'in_footer' => true ) );
			wp_add_inline_script( self::SCRIPT_RECIT, self::recit_js() );
		}
		if ( 'none' !== $a['effect'] ) {
			wp_enqueue_script( self::SCRIPT_RECIT );
		}
		return sprintf(
			'<div class="%s">%s</div>',
			esc_attr( implode( ' ', $classes ) ),
			is_string( $content ) ? $content : ''
		);
	}

	/* ---------- Bloc Pop-up ---------- */

	/**
	 * Attributs du pop-up : mode fermé, libellés en texte seul, bornés, jamais vides.
	 *
	 * @param array<mixed> $attributes Attributs bruts.
	 * @return array{mode: string, label: string, closeLabel: string, className: string}
	 */
	public static function popup_attributes( array $attributes ): array {
		$mode = $attributes['mode'] ?? null;
		return array(
			'mode'       => in_array( $mode, self::POPUP_MODES, true ) ? (string) $mode : 'popup',
			'label'      => self::label_text( $attributes['label'] ?? null, __( 'Learn more', 'fluffless-suite' ) ),
			'closeLabel' => self::label_text( $attributes['closeLabel'] ?? null, __( 'Close', 'fluffless-suite' ) ),
			'className'  => self::class_list( $attributes['className'] ?? null ),
		);
	}

	/**
	 * Libellé en texte seul, borné ; le défaut s'il est vide.
	 *
	 * @param mixed  $value    Valeur brute.
	 * @param string $fallback Défaut traduit.
	 */
	private static function label_text( $value, string $fallback ): string {
		$text = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
		return '' === $text ? $fallback : mb_substr( $text, 0, self::POPUP_LABEL_MAX );
	}

	/**
	 * Rendu du pop-up : fenêtre au clic (popover) ou bandeau refermable (dialog). 0 JS.
	 *
	 * @param mixed $attributes Attributs du bloc.
	 * @param mixed $content    HTML des blocs enfants, déjà rendu par le cœur.
	 * @param mixed $block      Instance WP_Block (graine de l'identifiant : contenu enregistré, pas rendu).
	 */
	public static function render_popup( $attributes, $content = '', $block = null ): string {
		$a       = self::popup_attributes( is_array( $attributes ) ? $attributes : array() );
		$inner   = is_string( $content ) ? $content : '';
		$classes = implode( ' ', array_filter( array( 'wp-block-fluffless-popup', 'fluffless-popup', 'is-' . $a['mode'], $a['className'] ) ) );
		self::enqueue_inline( self::STYLE_POPUP, self::popup_css() );

		if ( 'banner' === $a['mode'] ) {
			return sprintf(
				'<dialog open class="%s" aria-label="%s"><div class="fluffless-popup__content">%s</div><form method="dialog"><button type="submit" class="fluffless-popup__close">%s</button></form></dialog>',
				esc_attr( $classes ),
				esc_attr( $a['label'] ),
				$inner,
				esc_html( $a['closeLabel'] )
			);
		}

		$id = Ids::stable( 'fluffless-popup-', (string) wp_json_encode( $a ) . ( $block instanceof WP_Block ? (string) ( $block->parsed_block['innerHTML'] ?? '' ) : '' ) );
		return sprintf(
			'<div class="%1$s"><button type="button" class="wp-element-button" popovertarget="%2$s">%3$s</button><div id="%2$s" popover class="fluffless-popup__panel" role="dialog" aria-label="%4$s"><button type="button" class="fluffless-popup__close" popovertarget="%2$s" popovertargetaction="hide" autofocus>%5$s</button>%6$s</div></div>',
			esc_attr( $classes ),
			esc_attr( $id ),
			esc_html( $a['label'] ),
			esc_attr( $a['label'] ),
			esc_html( $a['closeLabel'] ),
			$inner
		);
	}

	/* ---------- Bloc Panneau de menu ---------- */

	/**
	 * Attributs du panneau de menu : libellé en texte seul, borné, jamais vide ; largeur fermée.
	 *
	 * @param array<mixed> $attributes Attributs bruts.
	 * @return array{label: string, width: string, className: string}
	 */
	public static function panneau_attributes( array $attributes ): array {
		$width = $attributes['width'] ?? null;
		return array(
			'label'     => self::label_text( $attributes['label'] ?? null, __( 'Menu', 'fluffless-suite' ) ),
			'width'     => in_array( $width, self::PANNEAU_WIDTHS, true ) ? (string) $width : 'full',
			'className' => self::class_list( $attributes['className'] ?? null ),
		);
	}

	/**
	 * Rendu du panneau de menu : un élément de menu (<li>) qui tient un <details> natif ; le
	 * résumé sert de déclencheur, le panneau contient les blocs enfants tels quels. Tous les
	 * panneaux d'une page partagent le même « name » : un seul ouvert à la fois. 0 JS de la
	 * Suite ; l'attribut data-fluffless-esc et l'action fluffless_nav_escape laissent le thème
	 * Fluffless fermer le panneau à la touche Échap avec son propre script (rien ailleurs).
	 *
	 * @param mixed $attributes Attributs du bloc.
	 * @param mixed $content    HTML des blocs enfants, déjà rendu par le cœur.
	 */
	public static function render_panneau( $attributes, $content = '' ): string {
		$a       = self::panneau_attributes( is_array( $attributes ) ? $attributes : array() );
		$inner   = is_string( $content ) ? $content : '';
		$classes = implode( ' ', array_filter( array( 'wp-block-navigation-item', 'wp-block-fluffless-panneau-menu', 'fluffless-panneau-menu', 'is-width-' . $a['width'], $a['className'] ) ) );
		/**
		 * Un thème qui sait fermer un <details data-fluffless-esc> à la touche Échap enfile ici son script.
		 */
		do_action( 'fluffless_nav_escape' );
		return sprintf(
			'<li class="%s"><details class="fluffless-panneau-menu__details" name="fluffless-panneau-menu" data-fluffless-esc><summary class="wp-block-navigation-item__content fluffless-panneau-menu__summary">%s</summary><div class="fluffless-panneau-menu__panel">%s</div></details></li>',
			esc_attr( $classes ),
			esc_html( $a['label'] ),
			$inner
		);
	}

	/* ---------- CSS ---------- */

	/**
	 * Vague : bloc sans marge ni interligne (aucun jour sous le SVG), largeur pleine, hauteur par
	 * variable. Miroir en haut et à l'horizontale par scale, sans second tracé.
	 */
	public static function vague_css(): string {
		return '.fluffless-vague.fluffless-vague{display:block;margin-block:0;line-height:0;pointer-events:none}'
			. '.fluffless-vague>svg{display:block;width:100%;height:var(--fluffless-vague-h,64px)}'
			. '.fluffless-vague.is-top>svg{scale:1 -1}'
			. '.fluffless-vague.is-flip>svg{scale:-1 1}'
			. '.fluffless-vague.is-top.is-flip>svg{scale:-1 -1}'
			. '.fluffless-vague.is-both>svg:first-child{scale:1 -1}'
			. '.fluffless-vague.is-both.is-flip>svg{scale:-1 1}'
			. '.fluffless-vague.is-both.is-flip>svg:first-child{scale:-1 -1}'
			. '.fluffless-vague.is-line{display:flex;align-items:center;height:var(--fluffless-vague-h,64px)}'
			. '.fluffless-vague.is-line::before,.fluffless-vague.is-line::after{content:"";flex:1;border-top:1px solid}'
			. '.fluffless-vague.is-shape-dots::before,.fluffless-vague.is-shape-dots::after{border-top:2px dotted}'
			. '.fluffless-vague.is-line>svg{width:auto;height:1rem;margin-inline:1rem}'
			. '.fluffless-vague.is-mark{display:flex;justify-content:center}.fluffless-vague.is-mark>svg{width:auto}';
	}

	/**
	 * Récit, mise en page seule (site et éditeur) : deux colonnes dès 782 px, scène collante,
	 * étapes espacées pour laisser le temps de lire pendant que la scène reste en place.
	 */
	public static function recit_layout_css(): string {
		return '.fluffless-recit{display:grid;gap:var(--wp--style--block-gap,2rem);align-items:start}'
			. '.fluffless-recit>:last-child>*+*{margin-block-start:var(--fluffless-recit-gap,30vh)}'
			. '@media (min-width:782px){.fluffless-recit{grid-template-columns:1fr 1fr}'
			. '.fluffless-recit>:first-child{position:sticky;top:var(--fluffless-recit-top,2rem)}'
			. '.fluffless-recit.is-scene-end>:first-child{order:2}}';
	}

	/**
	 * Récit, site public : mise en page, puis mouvement sous no-preference seulement.
	 * Natif : chaque étape entre pendant sa traversée de l'écran (animation-timeline: view()).
	 * Repli : le script pose is-pending sur les étapes sous l'écran et le retire à leur entrée.
	 */
	public static function recit_css(): string {
		$steps = '.fluffless-recit:not(.is-effect-none)>:last-child>*';
		return self::recit_layout_css()
			. '.fluffless-recit.is-effect-fade{--fluffless-recit-shift:0}'
			. '@media (prefers-reduced-motion:no-preference){'
			. '@keyframes fluffless-recit-in{from{opacity:0;translate:0 var(--fluffless-recit-shift,2rem)}}'
			. '@supports (animation-timeline:view()){' . $steps . '{animation:fluffless-recit-in linear both;animation-timeline:view();animation-range:entry 0% entry 80%}}'
			. $steps . '{transition:opacity .6s,translate .6s}'
			. $steps . '.is-pending{opacity:0;translate:0 var(--fluffless-recit-shift,2rem)}}';
	}

	/**
	 * Pop-up : panneau centré par le navigateur (popover), fond assombri, fermeture en haut à
	 * droite ; bandeau pleine largeur aux couleurs d'accent. display seulement sur [open].
	 */
	public static function popup_css(): string {
		return '.fluffless-popup__panel{inline-size:min(40rem,calc(100vw - 2rem));max-block-size:calc(100dvh - 2rem);overflow:auto;padding:var(--wp--preset--spacing--40,2rem);border:1px solid var(--wp--preset--color--divider,currentColor);border-radius:var(--wp--custom--radius--medium,.5rem);background:var(--wp--preset--color--base,#fff);color:var(--wp--preset--color--contrast,#111)}'
			. '.fluffless-popup__panel::backdrop{background:rgb(0 0 0/.4)}'
			. '.fluffless-popup__panel>.fluffless-popup__close{float:inline-end;margin:0 0 1rem 1rem}'
			. '.fluffless-popup__close{font:inherit;cursor:pointer}'
			. '.fluffless-popup.is-banner{position:static;inline-size:auto;max-inline-size:none;margin:0;padding:.75rem var(--wp--style--root--padding-right,1rem);border:0;background:var(--wp--preset--color--accent,#111);color:var(--wp--preset--color--on-accent,#fff)}'
			. '.fluffless-popup.is-banner[open]{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between}'
			. '.fluffless-popup.is-banner>form{margin:0}';
	}

	/* ---------- Script de repli ---------- */

	/**
	 * Repli pour les navigateurs sans animation-timeline. Sort tout de suite si le CSS natif
	 * suffit, en mouvement réduit ou sans IntersectionObserver : le contenu reste alors visible.
	 * Ne masque que les étapes encore sous l'écran, pour ne jamais faire clignoter ce qui est lu.
	 */
	public static function recit_js(): string {
		return '(()=>{if(CSS.supports("animation-timeline","view()")||matchMedia("(prefers-reduced-motion:reduce)").matches||!("IntersectionObserver"in window))return;'
			. 'const o=new IntersectionObserver(e=>e.forEach(t=>{if(t.isIntersecting){t.target.classList.remove("is-pending");o.unobserve(t.target)}}),{rootMargin:"0px 0px -10% 0px"});'
			. 'document.querySelectorAll(".fluffless-recit:not(.is-effect-none)>:last-child>*").forEach(s=>{if(s.getBoundingClientRect().top>innerHeight){s.classList.add("is-pending");o.observe(s)}})})();';
	}

	/**
	 * CSS en ligne, sans fichier ni requête, enregistré une fois par requête.
	 *
	 * @param string $handle Poignée.
	 * @param string $css    Règles.
	 */
	public static function enqueue_inline( string $handle, string $css ): void {
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, array(), VERSION );
			wp_add_inline_style( $handle, $css );
		}
		wp_enqueue_style( $handle );
	}

	/* ---------- Éditeur ---------- */

	/**
	 * Données du script d'éditeur : les tracés (une seule source, ici) et la palette.
	 *
	 * @return array{shapes: array<string, string>, lines: list<string>, accents: list<string>, opacities: list<int>, marks: array<string, array{0: string, 1: string}>, presets: array<string, int>, height: list<int>, colour: string}
	 */
	public static function editor_data(): array {
		return array(
			'shapes'    => self::VAGUE_SHAPES,
			'lines'     => self::VAGUE_LINES,
			'accents'   => self::VAGUE_ACCENTS,
			'opacities' => self::VAGUE_OPACITIES,
			'marks'     => self::VAGUE_MARKS,
			'presets'   => self::VAGUE_PRESETS,
			'height'    => self::VAGUE_HEIGHT,
			'colour'    => self::VAGUE_COLOUR,
		);
	}

	/** Passe les données au script d'éditeur du bloc, enregistré par block.json. */
	public static function editor_assets(): void {
		Sections::editor_assets();
		$handle = generate_block_asset_handle( self::VAGUE, 'editorScript' );
		wp_add_inline_script( $handle, 'window.flufflessVague=' . wp_json_encode( self::editor_data() ) . ';', 'before' );
		wp_add_inline_script( generate_block_asset_handle( Flottant::NAME, 'editorScript' ), 'window.flufflessFlottant=' . wp_json_encode( Flottant::editor_data() ) . ';', 'before' );
		wp_add_inline_script( generate_block_asset_handle( Bandeau::NAME, 'editorScript' ), 'window.flufflessBandeau=' . wp_json_encode( Bandeau::editor_data() ) . ';', 'before' );
		wp_add_inline_script( generate_block_asset_handle( Graphique::NAME, 'editorScript' ), 'window.flufflessGraphique=' . wp_json_encode( Graphique::editor_data() ) . ';', 'before' );
		foreach ( array( self::VAGUE, self::RECIT, self::POPUP, self::PANNEAU, Flottant::NAME, Bandeau::NAME, Graphique::NAME ) as $block ) {
			wp_set_script_translations( generate_block_asset_handle( $block, 'editorScript' ), 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
		}
	}

	/**
	 * Dans le cadre de l'éditeur seulement : le CSS de la vague et la mise en page du récit, pour
	 * que l'aperçu suive le site. Sans animation : les étapes restent lisibles pendant l'édition.
	 */
	public static function editor_canvas_styles(): void {
		if ( is_admin() ) {
			self::enqueue_inline( 'fluffless-expression-editor', self::vague_css() . self::recit_layout_css() . self::popup_css() . Flottant::css() . Flottant::editor_css() . Bandeau::css() . Bandeau::editor_css() . Graphique::css() );
		}
	}
}
