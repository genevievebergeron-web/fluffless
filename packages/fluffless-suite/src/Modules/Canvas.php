<?php
/**
 * Module Canevas : image de fond sur plus de blocs, fond propre au petit écran, calques
 * décoratifs libres et bascule Visuel / HTML évidente, pour les graphistes.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Core\Module;
use Fluffless\Suite\Core\Options;
use WP_HTML_Tag_Processor;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Natif d'abord : WordPress 7.1 donne déjà l'image de fond (position, taille, répétition, fixe)
 * à Groupe, Contenu de la publication, Citation, Exergue, Vers et Accordéon. Ce module ne code
 * que l'écart :
 * - le même support natif sur Colonnes, Colonne, Détails et Média et texte (BLOCKS) ; le moteur
 *   de styles du cœur produit le CSS, rien d'autre à rendre ;
 * - un fond différent (ou aucun) sous 782 px : une classe et une variable CSS en ligne, et une
 *   règle ajoutée seulement si un bloc de la page s'en sert ;
 * - le bloc fluffless/calque : une image de la médiathèque posée en X/Y dans son conteneur ;
 * - dans l'éditeur : bouton Visuel / HTML dans l'en-tête et « Modifier en HTML » dans les
 *   sections contentOnly, où le menu natif le retire.
 *
 * Qui voit les contrôles : les personnes qui peuvent déverrouiller les sections (module Forme :
 * mode Studio ou rôle autorisé). Le mode Simple garde son interface. Le rendu, lui, vaut pour tous.
 *
 * Site public : 0 JS ; CSS en ligne (quelques centaines d'octets) seulement sur les pages qui
 * contiennent un calque ou un fond mobile.
 */
final class Canvas implements Module {

	/** Blocs qui reçoivent le support d'image de fond natif, absent de leur block.json. */
	public const BLOCKS = array( 'core/columns', 'core/column', 'core/details', 'core/media-text' );

	public const BLOCK         = 'fluffless/calque';
	public const MOBILE_ATTR   = 'flufflessMobileBg';
	public const BREAKPOINT    = 782;
	public const PARENT_CLASS  = 'fluffless-has-calque';
	public const MOBILE_CLASS  = 'fluffless-bg-m';
	public const BLEND_MODES   = array( 'normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-burn', 'soft-light', 'difference', 'luminosity' );
	private const STYLE_CALQUE = 'fluffless-calque';
	private const STYLE_MOBILE = 'fluffless-bg-mobile';
	private const SCRIPT       = 'fluffless-canvas-editor';
	private const SCRIPT_DEPS  = array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-keyboard-shortcuts', 'wp-plugins' );

	/** Bornes des attributs du calque : [min, max, défaut]. */
	public const LIMITS = array(
		'x'       => array( -50, 150, 50 ),
		'y'       => array( -50, 150, 50 ),
		'width'   => array( 2, 200, 30 ),
		'rotate'  => array( -180, 180, 0 ),
		'opacity' => array( 0, 100, 100 ),
	);

	/** Ancrages : le point de l'image qui reste collé à (X, Y) quand la section change de taille. */
	public const PINS = array( 'center', 'top-left', 'top-right', 'bottom-left', 'bottom-right' );

	/** Découpe : l'image reste dans la section, ou déborde en hauteur (jamais en largeur). */
	public const CLIPS = array( 'clip', 'overflow' );

	/** Marge de découpe maximale, en px. */
	public const CLIP_MARGIN_MAX = 64;

	/** Bornes de largeur acceptées, par unité : au-delà, la valeur est ignorée. */
	private const LENGTH_MAX = array(
		'px'  => 4000,
		'rem' => 250,
	);

	/** {@inheritDoc} */
	public static function id(): string {
		return 'canvas';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Canvas: backgrounds and layers', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'For designers: background images on columns, details and media blocks, a different background on small screens, free decorative image layers, and a visible Visual / HTML switch in the editor. A few lines of CSS, only on pages that use them.', 'fluffless-suite' );
	}

	/**
	 * Inactif par défaut : le module change aussi le site public (calques, fond mobile), comme Visibilité.
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
		add_filter( 'register_block_type_args', array( self::class, 'block_args' ), 10, 2 );
		add_filter( 'render_block', array( self::class, 'render_block' ), 11, 2 );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_action( 'enqueue_block_assets', array( self::class, 'editor_canvas_styles' ) );
	}

	/* ---------- Qui dessine ---------- */

	/**
	 * La personne courante voit-elle les contrôles de graphiste ?
	 * Module Forme actif : mode Studio ou rôle autorisé à déverrouiller. Sinon : edit_theme_options.
	 */
	public static function can_design(): bool {
		if ( Loader::is_active( 'shape' ) ) {
			return Shape::can_unlock();
		}
		return current_user_can( 'edit_theme_options' );
	}

	/* ---------- Supports et attributs ---------- */

	/**
	 * Ajoute l'image de fond native aux blocs de BLOCKS, et l'attribut de fond mobile à tout
	 * bloc qui a l'image de fond (native ou ajoutée). Les supports déjà déclarés sont gardés.
	 *
	 * @param mixed  $args Arguments d'enregistrement.
	 * @param string $name Nom du bloc.
	 * @return mixed
	 */
	public static function block_args( $args, $name ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$supports = is_array( $args['supports'] ?? null ) ? $args['supports'] : array();
		if ( in_array( $name, self::BLOCKS, true ) ) {
			$background             = is_array( $supports['background'] ?? null ) ? $supports['background'] : array();
			$supports['background'] = array_merge(
				array(
					'backgroundImage' => true,
					'backgroundSize'  => true,
				),
				$background
			);
			$args['supports']       = $supports;
		}
		if ( true === ( $supports['background']['backgroundImage'] ?? false ) ) {
			$attributes                      = is_array( $args['attributes'] ?? null ) ? $args['attributes'] : array();
			$attributes[ self::MOBILE_ATTR ] = array( 'type' => 'object' );
			$args['attributes']              = $attributes;
		}
		return $args;
	}

	/* ---------- Rendu public ---------- */

	/**
	 * Fond mobile et classe du conteneur d'un calque. Bloc sans l'un ni l'autre : inchangé.
	 *
	 * @param string $content Balisage rendu.
	 * @param mixed  $block   Bloc analysé (vérifié : un filtre tiers peut passer n'importe quoi).
	 */
	public static function render_block( $content, $block ): string {
		$content = (string) $content;
		if ( '' === trim( $content ) || ! is_array( $block ) ) {
			return $content;
		}
		$mobile     = self::mobile_value( $block['attrs'][ self::MOBILE_ATTR ] ?? null );
		$has_calque = self::has_calque_child( $block );
		if ( null === $mobile && ! $has_calque ) {
			return $content;
		}
		$tags = new WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag() ) {
			return $content;
		}
		if ( $has_calque ) {
			$tags->add_class( self::PARENT_CLASS );
			self::enqueue_inline( self::STYLE_CALQUE, self::calque_css() );
		}
		if ( null !== $mobile ) {
			$style = (string) $tags->get_attribute( 'style' );
			$style = ( '' === $style || str_ends_with( $style, ';' ) ) ? $style : $style . ';';
			$tags->set_attribute( 'style', $style . '--fluffless-bg-m:' . $mobile );
			$tags->add_class( self::MOBILE_CLASS );
			self::enqueue_inline( self::STYLE_MOBILE, self::mobile_css() );
		}
		return $tags->get_updated_html();
	}

	/**
	 * Valeur CSS du fond mobile : url() d'une image de la médiathèque, none, ou null (rien à faire).
	 * L'URL vient toujours de l'identifiant, jamais du balisage : aucune injection CSS possible.
	 *
	 * @param mixed $raw Attribut flufflessMobileBg.
	 */
	public static function mobile_value( $raw ): ?string {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		if ( true === ( $raw['hide'] ?? false ) ) {
			return 'none';
		}
		$id  = is_numeric( $raw['id'] ?? null ) ? (int) $raw['id'] : 0;
		$url = $id > 0 && wp_attachment_is_image( $id ) ? wp_get_attachment_image_url( $id, 'large' ) : false;
		if ( ! is_string( $url ) || '' === $url || 1 === preg_match( '/["\'\\\\()\s]/', $url ) ) {
			return null;
		}
		return 'url("' . esc_url( $url ) . '")';
	}

	/**
	 * Un enfant direct est-il un calque ?
	 *
	 * @param array<mixed> $block Bloc analysé.
	 */
	private static function has_calque_child( array $block ): bool {
		foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $child ) {
			if ( is_array( $child ) && self::BLOCK === ( $child['blockName'] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- Bloc calque ---------- */

	/** Enregistre le bloc depuis son block.json, avec le rendu de ce module. */
	public static function register_block(): void {
		register_block_type(
			dirname( FILE ) . '/blocks/calque',
			array( 'render_callback' => array( self::class, 'render_calque' ) )
		);
	}

	/**
	 * Attributs du calque, bornés. Toute valeur hors bornes ou hors liste reprend sa borne ou son défaut.
	 *
	 * @param array<mixed> $attributes Attributs bruts.
	 * @return array{id: int, alt: string, decorative: bool, x: int, y: int, width: int, rotate: int, opacity: int, blend: string, front: bool, hideMobile: bool, className: string, pin: string, clip: string, clipMargin: int, minWidth: string, maxWidth: string, xMobile: ?int, yMobile: ?int, widthMobile: ?int}
	 */
	public static function calque_attributes( array $attributes ): array {
		$clean = array(
			'id'          => is_numeric( $attributes['id'] ?? null ) ? max( 0, (int) $attributes['id'] ) : 0,
			'alt'         => is_string( $attributes['alt'] ?? null ) ? sanitize_text_field( $attributes['alt'] ) : '',
			'decorative'  => false !== ( $attributes['decorative'] ?? true ),
			'blend'       => in_array( $attributes['blend'] ?? null, self::BLEND_MODES, true ) ? (string) $attributes['blend'] : 'normal',
			'front'       => 'front' === ( $attributes['layer'] ?? 'back' ),
			'hideMobile'  => true === ( $attributes['hideMobile'] ?? false ),
			'className'   => '',
			'pin'         => in_array( $attributes['pin'] ?? null, self::PINS, true ) ? (string) $attributes['pin'] : 'center',
			'clip'        => in_array( $attributes['clip'] ?? null, self::CLIPS, true ) ? (string) $attributes['clip'] : 'clip',
			'clipMargin'  => self::bounded( $attributes['clipMargin'] ?? null, 0, self::CLIP_MARGIN_MAX ) ?? 0,
			'minWidth'    => self::length( $attributes['minWidth'] ?? null ),
			'maxWidth'    => self::length( $attributes['maxWidth'] ?? null ),
			'xMobile'     => self::bounded( $attributes['xMobile'] ?? null, self::LIMITS['x'][0], self::LIMITS['x'][1] ),
			'yMobile'     => self::bounded( $attributes['yMobile'] ?? null, self::LIMITS['y'][0], self::LIMITS['y'][1] ),
			'widthMobile' => self::bounded( $attributes['widthMobile'] ?? null, self::LIMITS['width'][0], self::LIMITS['width'][1] ),
		);
		foreach ( self::LIMITS as $key => $limit ) {
			$clean[ $key ] = self::bounded( $attributes[ $key ] ?? null, $limit[0], $limit[1] ) ?? $limit[2];
		}
		if ( is_string( $attributes['className'] ?? null ) ) {
			$tokens             = preg_split( '/\s+/', $attributes['className'] );
			$clean['className'] = implode( ' ', array_filter( array_map( 'sanitize_html_class', false === $tokens ? array() : $tokens ) ) );
		}
		return $clean;
	}

	/**
	 * Entier borné, ou null si la valeur n'est pas un nombre (réglage absent).
	 *
	 * @param mixed $raw Valeur brute.
	 * @param int   $min Borne basse.
	 * @param int   $max Borne haute.
	 */
	private static function bounded( $raw, int $min, int $max ): ?int {
		return is_numeric( $raw ) ? max( $min, min( $max, (int) round( (float) $raw ) ) ) : null;
	}

	/**
	 * Borne de largeur en px ou rem (unités absolues : elles ne dépendent pas de la section).
	 * Toute autre forme (vw, calc, %) est ignorée : elle n'atteint jamais le CSS.
	 *
	 * @param mixed $raw Valeur brute.
	 */
	private static function length( $raw ): string {
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^(\d{1,4}(?:\.\d{1,2})?)(px|rem)$/', $raw, $match ) ) {
			return '';
		}
		return (float) $match[1] <= self::LENGTH_MAX[ $match[2] ] ? $raw : '';
	}

	/**
	 * Rendu du calque : une couche dédiée (span) qui couvre la section et porte la découpe, le
	 * mélange et l'unité de largeur (cqi), et dans elle une balise img de la médiathèque (srcset,
	 * WebP selon les modules Images et MediaWebp). La découpe vit sur la couche, jamais sur la
	 * section : les contours de focus et les menus des blocs voisins restent entiers.
	 * Sans image valide : rien.
	 *
	 * @param mixed $attributes Attributs du bloc (vérifiés : le rendu peut être appelé hors du cœur).
	 */
	public static function render_calque( $attributes ): string {
		$a = self::calque_attributes( is_array( $attributes ) ? $attributes : array() );
		if ( 0 === $a['id'] || ! wp_attachment_is_image( $a['id'] ) ) {
			return '';
		}
		$image_attributes = array(
			'class'    => 'fluffless-calque',
			'alt'      => $a['decorative'] ? '' : $a['alt'],
			'loading'  => 'lazy',
			'decoding' => 'async',
			// Borne haute : largeur du calque rapportée à la fenêtre (section pleine largeur au pire).
			'sizes'    => self::sizes( $a['width'], $a['widthMobile'] ),
		);
		if ( $a['decorative'] ) {
			$image_attributes['aria-hidden'] = 'true';
		}
		$html = wp_get_attachment_image( $a['id'], 'large', false, $image_attributes );
		if ( '' === $html ) {
			return '';
		}
		self::enqueue_inline( self::STYLE_CALQUE, self::calque_css() );
		return sprintf( '<span class="%s" style="%s">%s</span>', esc_attr( self::layer_classes( $a ) ), esc_attr( self::layer_style( $a ) ), $html );
	}

	/**
	 * Classes de la couche.
	 *
	 * @param array{front: bool, hideMobile: bool, pin: string, clip: string, className: string} $a Attributs bornés.
	 */
	private static function layer_classes( array $a ): string {
		return implode(
			' ',
			array_filter(
				array(
					'wp-block-fluffless-calque',
					'fluffless-calque-layer',
					$a['front'] ? 'is-front' : 'is-back',
					'is-pin-' . $a['pin'],
					'is-' . $a['clip'],
					$a['hideMobile'] ? 'is-hide-mobile' : '',
					$a['className'],
				)
			)
		);
	}

	/**
	 * Variables CSS de la couche. Un réglage absent n'écrit rien : le CSS reprend son défaut.
	 * La largeur est un nombre sans unité, multiplié par 1cqi dans le CSS.
	 *
	 * @param array{x: int, y: int, width: int, rotate: int, opacity: int, blend: string, clipMargin: int, minWidth: string, maxWidth: string, xMobile: ?int, yMobile: ?int, widthMobile: ?int} $a Attributs bornés.
	 */
	private static function layer_style( array $a ): string {
		$style = sprintf( '--fluffless-x:%d%%;--fluffless-y:%d%%;--fluffless-w:%d;--fluffless-r:%ddeg;--fluffless-o:%s', $a['x'], $a['y'], $a['width'], $a['rotate'], self::opacity( $a['opacity'] ) );
		$extra = array(
			'blend' => 'normal' !== $a['blend'] ? $a['blend'] : null,
			'min'   => '' !== $a['minWidth'] ? $a['minWidth'] : null,
			'max'   => '' !== $a['maxWidth'] ? $a['maxWidth'] : null,
			'clip'  => $a['clipMargin'] > 0 ? $a['clipMargin'] . 'px' : null,
			'xm'    => null !== $a['xMobile'] ? $a['xMobile'] . '%' : null,
			'ym'    => null !== $a['yMobile'] ? $a['yMobile'] . '%' : null,
			'wm'    => null !== $a['widthMobile'] ? (string) $a['widthMobile'] : null,
		);
		foreach ( array_filter( $extra, 'is_string' ) as $name => $value ) {
			$style .= ';--fluffless-' . $name . ':' . $value;
		}
		return $style;
	}

	/**
	 * Attribut sizes : largeur du calque en vw, distincte sous 782 px si réglée.
	 *
	 * @param int      $width        Largeur de bureau, en % de la section.
	 * @param int|null $width_mobile Largeur sous 782 px, ou null.
	 */
	private static function sizes( int $width, ?int $width_mobile ): string {
		$desktop = min( 100, $width ) . 'vw';
		return null === $width_mobile ? $desktop : '(max-width: ' . self::mobile_max() . ') ' . min( 100, $width_mobile ) . 'vw, ' . $desktop;
	}

	/**
	 * Opacité 0 à 100 en valeur CSS 0 à 1, point décimal quelle que soit la langue.
	 *
	 * @param int $percent Opacité en pour cent.
	 */
	private static function opacity( int $percent ): string {
		return 100 === $percent ? '1' : rtrim( rtrim( number_format( $percent / 100, 2, '.', '' ), '0' ), '.' );
	}

	/* ---------- CSS ---------- */

	/** Largeur maximale du « petit écran », en px, alignée sur Visibilité (782 px). */
	private static function mobile_max(): string {
		return ( self::BREAKPOINT - 0.02 ) . 'px';
	}

	/**
	 * Calque, en trois étages :
	 * - la section : repère (relative, spécificité nulle pour qu'un sticky gagne) et contexte isolé,
	 *   sans aucune découpe ;
	 * - la couche : absolue, elle couvre la boîte de rembourrage de la section sans y prendre de
	 *   place (flex, grille, flux), porte la découpe, le mélange et container-type (1cqi = 1 % de
	 *   la section). Classe doublée : bat le max-width et le margin auto !important du layout
	 *   constrained. En largeur, la couche coupe toujours à ses bords (overflow-x:clip) : une
	 *   marge de découpe latérale ferait défiler une section pleine largeur (mesuré : 40 px à
	 *   320 px). La marge de découpe ne vaut donc qu'en hauteur, par clip-path, sans
	 *   overflow-clip-margin ;
	 * - l'image : ancrée par un coin ou par son centre, largeur bornée par clamp().
	 * Dernière règle : un calque en tête d'un flux ne décale plus le premier bloc de l'écart (blockGap).
	 */
	public static function calque_css(): string {
		$img = '.fluffless-calque-layer>.fluffless-calque';
		return ':where(.fluffless-has-calque){position:relative;isolation:isolate}'
			. '.fluffless-calque-layer.fluffless-calque-layer{--_x:var(--fluffless-x,50%);--_y:var(--fluffless-y,50%);--_w:var(--fluffless-w,30);position:absolute;inset:0;display:block;width:auto;height:auto;max-width:none!important;margin:0!important;padding:0;container-type:inline-size;overflow-x:clip;opacity:var(--fluffless-o,1);mix-blend-mode:var(--fluffless-blend,normal);pointer-events:none;z-index:-1}'
			. '.fluffless-calque-layer.is-front{z-index:1}'
			. '.fluffless-calque-layer.is-clip{clip-path:inset(calc(-1*var(--fluffless-clip,0px)) 0)}'
			. $img . '{position:absolute;left:var(--_x);top:var(--_y);translate:-50% -50%;rotate:var(--fluffless-r,0deg);display:block;width:clamp(var(--fluffless-min,0px),calc(var(--_w)*1cqi),var(--fluffless-max,100000px));max-width:none!important;height:auto;margin:0!important}'
			. '.is-pin-top-left>.fluffless-calque{translate:none}'
			. '.is-pin-top-right>.fluffless-calque{left:auto;right:var(--_x);translate:none}'
			. '.is-pin-bottom-left>.fluffless-calque{top:auto;bottom:var(--_y);translate:none}'
			. '.is-pin-bottom-right>.fluffless-calque{inset:auto var(--_x) var(--_y) auto;translate:none}'
			. '@media (max-width:' . self::mobile_max() . '){.fluffless-calque-layer.fluffless-calque-layer{--_x:var(--fluffless-xm,var(--fluffless-x,50%));--_y:var(--fluffless-ym,var(--fluffless-y,50%));--_w:var(--fluffless-wm,var(--fluffless-w,30))}.fluffless-calque-layer.is-hide-mobile{display:none}}'
			. ':where(.is-layout-flow,.is-layout-constrained)>.fluffless-calque-layer:first-child~:not(.fluffless-calque-layer,:not(.fluffless-calque-layer)~*){margin-block-start:0}';
	}

	/**
	 * Fond mobile : remplace l'image du bloc sous 782 px. Le navigateur ne télécharge que l'image
	 * appliquée : sur mobile, la grande image de bureau n'est pas chargée.
	 */
	public static function mobile_css(): string {
		return '@media (max-width:' . self::mobile_max() . '){.fluffless-bg-m{background-image:var(--fluffless-bg-m)!important}}';
	}

	/**
	 * CSS en ligne, sans fichier ni requête, enregistré une fois par requête.
	 *
	 * @param string $handle Poignée.
	 * @param string $css    Règles.
	 */
	private static function enqueue_inline( string $handle, string $css ): void {
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, array(), VERSION );
			wp_add_inline_style( $handle, $css );
		}
		wp_enqueue_style( $handle );
	}

	/* ---------- Éditeur ---------- */

	/**
	 * Données passées au script d'éditeur.
	 *
	 * @return array{canDesign: bool, blocks: list<string>, warnKb: int, breakpoint: int}
	 */
	public static function editor_data(): array {
		return array(
			'canDesign'  => self::can_design(),
			'blocks'     => self::BLOCKS,
			// Seuil d'alerte du module Images ; 0 si le module est inactif (aucune alerte).
			'warnKb'     => Loader::is_active( 'images' ) ? Options::get_int( 'images', 'warn_kb' ) : 0,
			'breakpoint' => self::BREAKPOINT,
		);
	}

	/** Script d'éditeur : JS natif, sans build. */
	public static function editor_assets(): void {
		wp_enqueue_script( self::SCRIPT, plugins_url( 'assets/canvas-editor.js', FILE ), self::SCRIPT_DEPS, VERSION, true );
		wp_add_inline_script( self::SCRIPT, 'window.flufflessCanvas=' . wp_json_encode( self::editor_data() ) . ';', 'before' );
		wp_set_script_translations( self::SCRIPT, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
	}

	/**
	 * Dans le cadre de l'éditeur seulement : le CSS des calques et du fond mobile, pour que
	 * l'aperçu suive le site, plus un contour pour repérer et sélectionner un calque.
	 * Le site public passe par render_block(), jamais par ce crochet.
	 */
	public static function editor_canvas_styles(): void {
		if ( ! is_admin() ) {
			return;
		}
		self::enqueue_inline(
			'fluffless-canvas-editor',
			self::calque_css() . self::mobile_css()
				. ':where(.editor-styles-wrapper) :has(> .fluffless-calque-layer){position:relative;isolation:isolate}'
				. '.editor-styles-wrapper .fluffless-calque-layer>.fluffless-calque{pointer-events:auto;outline:1px dashed currentcolor;outline-offset:2px}'
		);
	}
}
