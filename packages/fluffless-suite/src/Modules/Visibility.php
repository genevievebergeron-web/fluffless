<?php
/**
 * Module Visibilité : afficher un bloc partout, sur ordinateur seulement ou sur mobile seulement,
 * et régler à part, sur mobile, son espacement intérieur, son alignement et sa taille de texte.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Modules;

use Fluffless\Suite\Core\Module;

use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Rendu : une classe sur la première balise du bloc, et quelques lignes de CSS en ligne,
 * ajoutées seulement si un bloc de la page s'en sert (0 requête, 0 JS côté visiteur).
 * Le bloc masqué reste dans le HTML.
 *
 * Point de rupture unique : 782 px, celui où WordPress empile ses colonnes.
 * Sous 782 px = mobile ; à partir de 782 px = ordinateur. Filtre : fluffless_visibility_breakpoint.
 *
 * Réglages mobiles : attribut flufflessMobile {padding, align, size} rendu en classes
 * fluffless-m-*, et une feuille en ligne imprimée en pied de page avec les seules règles
 * des classes rencontrées. Les préréglages viennent du thème (espacements et tailles de police) ;
 * une valeur qui n'en fait pas partie est ignorée.
 */
final class Visibility implements Module {

	public const ATTRIBUTE        = 'flufflessVisibility';
	public const MOBILE_ATTRIBUTE = 'flufflessMobile';
	public const BREAKPOINT       = 782;

	/** Valeur de l'attribut => classe rendue. */
	public const CLASSES = array(
		'desktop' => 'fluffless-hide-mobile',
		'mobile'  => 'fluffless-hide-desktop',
	);

	/** Alignements de texte acceptés sur mobile. */
	public const ALIGNS = array( 'left', 'center', 'right' );

	private const STYLE_HANDLE  = 'fluffless-visibility';
	private const DEVICE_HANDLE = 'fluffless-device';
	private const SCRIPT_HANDLE = 'fluffless-visibility-editor';
	private const SCRIPT_DEPS   = array( 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-element', 'wp-hooks', 'wp-i18n' );

	/**
	 * Classes mobiles rencontrées au rendu, dans l'ordre.
	 *
	 * @var list<string>
	 */
	private static array $device_classes = array();

	/** {@inheritDoc} */
	public static function id(): string {
		return 'visibility';
	}

	/** {@inheritDoc} */
	public static function label(): string {
		return __( 'Show by device', 'fluffless-suite' );
	}

	/** {@inheritDoc} */
	public static function description(): string {
		return __( 'Adds a “Show on” choice to every block: everywhere, desktop only or mobile only. Also a separate padding, alignment and text size for mobile. A few lines of CSS, only on pages that use it.', 'fluffless-suite' );
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
		add_filter( 'register_block_type_args', array( self::class, 'add_attribute' ), 10, 2 );
		add_filter( 'render_block', array( self::class, 'render' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_action( 'wp_footer', array( self::class, 'print_device_style' ), 5 );
	}

	/**
	 * Déclare les attributs sur chaque bloc enregistré côté serveur.
	 *
	 * @param mixed  $args Arguments d'enregistrement du bloc.
	 * @param string $name Nom du bloc.
	 * @return mixed
	 */
	public static function add_attribute( $args, $name ) {
		unset( $name );
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$attributes                           = is_array( $args['attributes'] ?? null ) ? $args['attributes'] : array();
		$attributes[ self::ATTRIBUTE ]        = array(
			'type'    => 'string',
			'default' => '',
		);
		$attributes[ self::MOBILE_ATTRIBUTE ] = array(
			'type'    => 'object',
			'default' => array(),
		);
		$args['attributes']                   = $attributes;
		return $args;
	}

	/**
	 * Ajoute les classes au premier élément du bloc. Valeur hors liste : ignorée.
	 *
	 * @param string       $content Balisage rendu.
	 * @param array<mixed> $block   Bloc analysé.
	 */
	public static function render( $content, $block ): string {
		$content = (string) $content;
		$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$value   = $attrs[ self::ATTRIBUTE ] ?? null;
		$hide    = is_string( $value ) && isset( self::CLASSES[ $value ] ) ? self::CLASSES[ $value ] : '';
		$mobile  = self::mobile_classes( is_array( $attrs[ self::MOBILE_ATTRIBUTE ] ?? null ) ? $attrs[ self::MOBILE_ATTRIBUTE ] : array() );
		if ( ( '' === $hide && array() === $mobile ) || '' === trim( $content ) ) {
			return $content;
		}
		$tags = new \WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag() ) {
			return $content;
		}
		if ( '' !== $hide ) {
			$tags->add_class( $hide );
			self::enqueue_style();
		}
		foreach ( $mobile as $class ) {
			$tags->add_class( $class );
			if ( ! in_array( $class, self::$device_classes, true ) ) {
				self::$device_classes[] = $class;
			}
		}
		if ( array() !== $mobile ) {
			self::enqueue_device_style();
		}
		return $tags->get_updated_html();
	}

	/**
	 * Classes mobiles d'un attribut flufflessMobile, bornées aux préréglages du thème.
	 *
	 * @param array<mixed> $mobile Valeur brute {padding, align, size}.
	 * @return list<string>
	 */
	public static function mobile_classes( array $mobile ): array {
		$classes = array();
		$padding = $mobile['padding'] ?? '';
		if ( is_string( $padding ) && '' !== $padding && in_array( $padding, self::spacing_slugs(), true ) ) {
			$classes[] = 'fluffless-m-pad-' . $padding;
		}
		$align = $mobile['align'] ?? '';
		if ( is_string( $align ) && in_array( $align, self::ALIGNS, true ) ) {
			$classes[] = 'fluffless-m-align-' . $align;
		}
		$size = $mobile['size'] ?? '';
		if ( is_string( $size ) && '' !== $size && in_array( $size, self::size_slugs(), true ) ) {
			$classes[] = 'fluffless-m-size-' . $size;
		}
		return $classes;
	}

	/**
	 * Préréglages d'espacement du thème : slug => nom.
	 *
	 * @return array<string, string>
	 */
	public static function spacing_presets(): array {
		return self::presets( array( 'spacing', 'spacingSizes' ) );
	}

	/**
	 * Préréglages de taille de police du thème : slug => nom.
	 *
	 * @return array<string, string>
	 */
	public static function size_presets(): array {
		return self::presets( array( 'typography', 'fontSizes' ) );
	}

	/**
	 * Slugs d'espacement acceptés.
	 *
	 * @return list<string>
	 */
	public static function spacing_slugs(): array {
		return array_map( 'strval', array_keys( self::spacing_presets() ) );
	}

	/**
	 * Slugs de taille de police acceptés.
	 *
	 * @return list<string>
	 */
	public static function size_slugs(): array {
		return array_map( 'strval', array_keys( self::size_presets() ) );
	}

	/**
	 * Lit une liste de préréglages dans les réglages globaux (thème d'abord, puis le cœur).
	 *
	 * @param array<int, string> $path Chemin dans les réglages.
	 * @return array<string, string>
	 */
	private static function presets( array $path ): array {
		$settings = wp_get_global_settings( $path );
		$lists    = array();
		if ( is_array( $settings ) ) {
			foreach ( array( 'custom', 'theme', 'default' ) as $origin ) {
				if ( is_array( $settings[ $origin ] ?? null ) ) {
					$lists[] = $settings[ $origin ];
				}
			}
			if ( array() === $lists && array_is_list( $settings ) ) {
				$lists[] = $settings;
			}
		}
		$out = array();
		foreach ( $lists as $list ) {
			foreach ( $list as $preset ) {
				$slug = is_array( $preset ) ? ( $preset['slug'] ?? '' ) : '';
				if ( ! is_string( $slug ) || '' === $slug || isset( $out[ $slug ] ) || 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
					continue;
				}
				$name         = $preset['name'] ?? $slug;
				$out[ $slug ] = is_string( $name ) ? $name : $slug;
			}
		}
		return $out;
	}

	/** CSS en ligne, enregistré une fois, sans fichier ni requête. Public : le module Pied de page s'en sert aussi. */
	public static function enqueue_style(): void {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style( self::STYLE_HANDLE, false, array(), VERSION );
			wp_add_inline_style( self::STYLE_HANDLE, self::css() );
		}
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/** Feuille des réglages mobiles : vide au départ, remplie en pied de page avec les classes rencontrées. */
	private static function enqueue_device_style(): void {
		if ( ! wp_style_is( self::DEVICE_HANDLE, 'registered' ) ) {
			wp_register_style( self::DEVICE_HANDLE, false, array(), VERSION );
		}
		wp_enqueue_style( self::DEVICE_HANDLE );
	}

	/** Pose les règles des classes rencontrées, juste avant l'impression des feuilles tardives. */
	public static function print_device_style(): void {
		if ( array() === self::$device_classes || ! wp_style_is( self::DEVICE_HANDLE, 'enqueued' ) ) {
			return;
		}
		wp_add_inline_style( self::DEVICE_HANDLE, self::device_css( self::$device_classes ) );
	}

	/**
	 * Classes rencontrées jusqu'ici (tests).
	 *
	 * @return list<string>
	 */
	public static function device_classes(): array {
		return self::$device_classes;
	}

	/** Oublie les classes rencontrées (tests). */
	public static function reset_device_classes(): void {
		self::$device_classes = array();
	}

	/** Point de rupture mobile / ordinateur, en pixels (320 à 3000). */
	public static function breakpoint(): int {
		/**
		 * Point de rupture mobile / ordinateur, en pixels (320 à 3000).
		 *
		 * @param int $breakpoint Défaut : 782.
		 */
		$breakpoint = (int) apply_filters( 'fluffless_visibility_breakpoint', self::BREAKPOINT );
		return max( 320, min( 3000, $breakpoint ) );
	}

	/** Les deux règles, autour du point de rupture unique. */
	public static function css(): string {
		$breakpoint = self::breakpoint();
		return sprintf(
			'@media (max-width:%1$spx){.fluffless-hide-mobile{display:none!important}}@media (min-width:%2$dpx){.fluffless-hide-desktop{display:none!important}}',
			$breakpoint - 0.02,
			$breakpoint
		);
	}

	/**
	 * Règles mobiles des seules classes données, sous le point de rupture. !important : le cœur pose
	 * ses espacements et tailles en style en ligne.
	 *
	 * @param array<int, string> $classes Classes fluffless-m-*.
	 */
	public static function device_css( array $classes ): string {
		$rules = '';
		foreach ( array_unique( $classes ) as $class ) {
			if ( 1 === preg_match( '/^fluffless-m-pad-([a-z0-9-]+)$/', $class, $m ) ) {
				$rules .= '.' . $class . '{padding:var(--wp--preset--spacing--' . $m[1] . ')!important}';
			} elseif ( 1 === preg_match( '/^fluffless-m-align-(left|center|right)$/', $class, $m ) ) {
				$rules .= '.' . $class . '{text-align:' . $m[1] . '!important}';
			} elseif ( 1 === preg_match( '/^fluffless-m-size-([a-z0-9-]+)$/', $class, $m ) ) {
				$rules .= '.' . $class . '{font-size:var(--wp--preset--font-size--' . $m[1] . ')!important}';
			}
		}
		if ( '' === $rules ) {
			return '';
		}
		return sprintf( '@media (max-width:%spx){%s}', self::breakpoint() - 0.02, $rules );
	}

	/** Contrôles « Afficher sur » et « Sur mobile » dans l'éditeur : JS natif, sans build. */
	public static function editor_assets(): void {
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/visibility-editor.js', FILE ),
			self::SCRIPT_DEPS,
			VERSION,
			true
		);
		$presets = array(
			'spacing' => self::presets_for_editor( self::spacing_presets() ),
			'sizes'   => self::presets_for_editor( self::size_presets() ),
		);
		wp_add_inline_script( self::SCRIPT_HANDLE, 'window.flufflessDevice = ' . wp_json_encode( $presets ) . ';', 'before' );
		wp_set_script_translations( self::SCRIPT_HANDLE, 'fluffless-suite', plugin_dir_path( FILE ) . 'languages' );
	}

	/**
	 * Liste {slug, name} pour l'éditeur.
	 *
	 * @param array<string, string> $presets slug => nom.
	 * @return list<array{slug: string, name: string}>
	 */
	private static function presets_for_editor( array $presets ): array {
		$out = array();
		foreach ( $presets as $slug => $name ) {
			$out[] = array(
				'slug' => (string) $slug,
				'name' => $name,
			);
		}
		return $out;
	}
}
