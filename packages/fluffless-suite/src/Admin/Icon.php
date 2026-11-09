<?php
/**
 * Icônes des écrans Fluffless : SVG en ligne, tirés du jeu pixelarticons généré (assets/admin/icons.php).
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Admin;

use const Fluffless\Suite\FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Aucune requête ni police d'icônes : chaque SVG est écrit dans la page, décoratif (aria-hidden).
 * Le contenu vient du fichier généré par tools/build-icons.mjs, filtré par liste blanche de balises et d'attributs.
 */
final class Icon {

	/** Icône de repli : un module ou un groupe sans icône attitrée. */
	public const FALLBACK = 'blocks';

	/** Module => icône. */
	private const MODULES = array(
		'cleanup'       => 'brush',
		'images'        => 'image',
		'http'          => 'shield',
		'housekeeping'  => 'database',
		'seo'           => 'target',
		'audit'         => 'clipboard-note',
		'footprint'     => 'leaf',
		'stats'         => 'chart-line',
		'editor'        => 'pen-square',
		'shape'         => 'shapes',
		'footer'        => 'dock',
		'visibility'    => 'eye',
		'updates'       => 'refresh',
		'canvas'        => 'layout',
		'pexels'        => 'camera',
		'palette'       => 'colors-swatch',
		'fonts'         => 'heading',
		'expression'    => 'sparkles',
		'minify'        => 'collapse',
		'prune-css'     => 'feather',
		'mail'          => 'mail',
		'form'          => 'clipboard-note',
		'events'        => 'calendar',
		'shop'          => 'archive',
		'courses'       => 'book-open',
		'forms'         => 'message',
		'kits'          => 'grid-3x3',
		'migration'     => 'arrow-right',
		'conditions'    => 'sliders-horizontal',
		'fiches'        => 'article',
		'champs'        => 'file',
		'styles'        => 'layout-solid',
		'review'        => 'flag',
		'reviewer'      => 'user',
		'quality'       => 'check',
		'dashboard'     => 'home',
		'media_webp'    => 'images',
		'newsletter'    => 'users',
		'carbone'       => 'scale',
		'maintenance'   => 'power',
		'security'      => 'unlock',
		'status'        => 'circle-info',
		'carbon-glance' => 'cloud',
		'utm'           => 'link',
	);

	/** Groupe de l'onglet Modules => icône. */
	private const GROUPS = array(
		'lighten' => 'feather',
		'found'   => 'search',
		'measure' => 'speed-fast',
		'present' => 'colors-swatch',
		'content' => 'article',
		'care'    => 'refresh',
		'protect' => 'shield',
		'reach'   => 'message',
		'extend'  => 'grid-3x3',
	);

	/** Onglet (slug de la page) => icône. Un slug absent n'a pas d'icône. */
	private const TABS = array(
		'fluffless-settings'     => 'sliders-horizontal',
		'fluffless-audit'        => 'clipboard-note',
		'fluffless-housekeeping' => 'database',
		'fluffless-palette'      => 'colors-swatch',
		'fluffless-fonts'        => 'heading',
		'fluffless-shape'        => 'shapes',
		'fluffless-webp'         => 'images',
		'fluffless-quarantine'   => 'archive',
	);

	/** État d'un message => icône : la forme double la couleur. */
	private const STATUSES = array(
		'good' => 'check',
		'warn' => 'warning-diamond',
		'bad'  => 'square-alert',
		'idle' => 'circle-info',
	);

	/**
	 * Jeu chargé (nom => contenu interne du SVG).
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $icons = null;

	/**
	 * SVG décoratif, ou '' si l'icône et le repli manquent.
	 *
	 * @param string $name Nom pixelarticons.
	 */
	public static function svg( string $name ): string {
		$icons = self::icons();
		$inner = $icons[ $name ] ?? $icons[ self::FALLBACK ] ?? '';
		if ( '' === $inner ) {
			return '';
		}
		return '<svg class="fl-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" shape-rendering="crispEdges" aria-hidden="true" focusable="false">' . $inner . '</svg>';
	}

	/**
	 * Icône dans sa tuile de couleur (la teinte vient de la classe fl-hue-* d'un parent).
	 *
	 * @param string $name Nom pixelarticons.
	 */
	public static function tile( string $name ): string {
		$svg = self::svg( $name );
		return '' === $svg ? '' : '<span class="fl-tile">' . $svg . '</span>';
	}

	/**
	 * Icône d'un module.
	 *
	 * @param string $id Identifiant du module.
	 */
	public static function for_module( string $id ): string {
		return self::MODULES[ $id ] ?? self::FALLBACK;
	}

	/**
	 * Icône d'un groupe de l'onglet Modules.
	 *
	 * @param string $gid Identifiant du groupe.
	 */
	public static function for_group( string $gid ): string {
		return self::GROUPS[ $gid ] ?? self::FALLBACK;
	}

	/**
	 * Icône d'un onglet, ou '' s'il n'en a pas.
	 *
	 * @param string $slug Slug de la page.
	 */
	public static function for_tab( string $slug ): string {
		return self::TABS[ $slug ] ?? '';
	}

	/**
	 * Icône d'un état de message.
	 *
	 * @param string $status good, warn, bad ou idle.
	 */
	public static function for_status( string $status ): string {
		return self::STATUSES[ $status ] ?? self::STATUSES['idle'];
	}

	/**
	 * Tracés (attribut d) d'icônes, pour les écrans en JavaScript : l'éditeur construit ses SVG avec.
	 *
	 * @param string[] $names Noms pixelarticons.
	 * @return array<string, list<string>>
	 */
	public static function paths( array $names ): array {
		$icons = self::icons();
		$out   = array();
		foreach ( $names as $name ) {
			if ( isset( $icons[ $name ] ) && preg_match_all( '/ d="([^"]*)"/', $icons[ $name ], $m ) > 0 ) {
				$out[ $name ] = $m[1];
			}
		}
		return $out;
	}

	/**
	 * Jeu d'icônes, lu une fois par requête.
	 *
	 * @return array<string, string>
	 */
	private static function icons(): array {
		if ( null === self::$icons ) {
			$path        = dirname( FILE ) . '/assets/admin/icons.php';
			$data        = is_readable( $path ) ? include $path : array();
			self::$icons = is_array( $data ) ? array_filter( $data, 'is_string' ) : array();
		}
		return self::$icons;
	}
}
