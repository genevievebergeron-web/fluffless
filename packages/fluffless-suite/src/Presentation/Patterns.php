<?php
/**
 * Patterns avancés livrés par Suite pour le thème Fluffless.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Presentation;

use Fluffless\Suite\Core\Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Lit les en-têtes des fichiers de patterns/ et les enregistre, seulement sous le thème Fluffless.
 *
 * Ordre : le cœur enregistre les patterns du thème sur init 10 et saute tout slug déjà pris
 * (_register_theme_block_patterns). Suite passe sur init 9 : quand un slug existe des deux côtés
 * (pied-de-page, pied-de-page-compact), la version enrichie de Suite gagne, sans désenregistrer.
 * Elles portent « Fluffless Modules: footer » : module Pied de page éteint, celle du thème reste (P5-01).
 */
final class Patterns {

	/** Thème parent dont les patterns attendent les jetons (couleurs, espacements, styles). */
	public const TEMPLATE = 'fluffless';

	/** Priorité sur init : avant _register_theme_block_patterns (10). */
	public const PRIORITY = 9;

	/**
	 * En-têtes déjà lus pendant cette requête : init et l'éditeur relisent les mêmes fichiers.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $read = array();

	/** Slug accepté : même espace de nom que les patterns du thème. */
	private const SLUG_PATTERN = '#^fluffless/[a-z0-9-]+$#D';

	/** En-têtes lus, mêmes noms que ceux des patterns de thème. */
	private const HEADERS = array(
		'title'         => 'Title',
		'slug'          => 'Slug',
		'description'   => 'Description',
		'viewportWidth' => 'Viewport Width',
		'inserter'      => 'Inserter',
		'categories'    => 'Categories',
		'keywords'      => 'Keywords',
		'blockTypes'    => 'Block Types',
		'postTypes'     => 'Post Types',
		'templateTypes' => 'Template Types',
		// Propre à Suite : modules dont le pattern utilise les blocs (ex. expression).
		'modules'       => 'Fluffless Modules',
	);

	/** En-têtes qui contiennent une liste séparée par des virgules. */
	private const LISTS = array( 'categories', 'keywords', 'blockTypes', 'postTypes', 'templateTypes', 'modules' );

	/** Classe des images de pattern qui montrent un cadre vide tant qu'aucune photo n'est choisie. */
	public const PHOTO_FRAME = 'fluffless-cadre-photo';

	/** Vrai quand le thème actif (ou le parent d'un thème enfant) est Fluffless. */
	public static function applies(): bool {
		return self::TEMPLATE === get_template();
	}

	/** Dossier des patterns de Suite, avec la barre oblique finale. */
	public static function dir(): string {
		return dirname( __DIR__, 2 ) . '/patterns/';
	}

	/**
	 * Cadre vide pour une image de pattern encore sans photo (classe PHOTO_FRAME).
	 *
	 * Sans source, le cœur ne rend rien (render_block_core_image) : la page Équipe publiée
	 * avant l'ajout des portraits perdrait ses cadres. Le cadre garde le ratio de l'image et
	 * montre une icône de photo, masquée aux lecteurs d'écran puisqu'elle ne dit rien du contenu.
	 *
	 * @param string               $content Rendu du bloc.
	 * @param array<string, mixed> $block   Bloc analysé.
	 */
	public static function photo_frame( $content, $block ): string {
		$content = (string) $content;
		$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) );
		if ( '' !== trim( $content ) || ! is_array( $classes ) || ! in_array( self::PHOTO_FRAME, $classes, true ) || ! self::applies() ) {
			return $content;
		}
		$ratio = (string) ( $attrs['aspectRatio'] ?? '' );
		if ( 1 !== preg_match( '#^[1-9][0-9]?/[1-9][0-9]?$#D', $ratio ) ) {
			$ratio = '1';
		}
		$icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="m21 16-5-5-8 8"/></svg>';
		return sprintf(
			'<figure class="wp-block-image %1$s" aria-hidden="true" style="aspect-ratio:%2$s;display:grid;place-items:center;margin-inline:0;border:1px dashed currentcolor;color:inherit;opacity:.45">%3$s</figure>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $ratio ),
			$icon
		);
	}

	/**
	 * Enregistre les catégories absentes puis chaque pattern du dossier.
	 */
	public static function register(): void {
		if ( ! self::applies() ) {
			return;
		}
		$registry = \WP_Block_Patterns_Registry::get_instance();
		foreach ( self::files() as $file ) {
			$pattern = self::read( $file );
			if ( null === $pattern || $registry->is_registered( $pattern['slug'] ) || ! self::modules_active( $pattern ) ) {
				continue;
			}
			unset( $pattern['modules'] );
			register_block_pattern( $pattern['slug'], $pattern );
		}
	}

	/**
	 * Vrai si chaque module de l'en-tête « Fluffless Modules » est actif. Un pattern dont un
	 * module est inactif contiendrait des blocs non enregistrés : il n'est pas proposé.
	 *
	 * @param array<string, mixed> $pattern Propriétés lues par read().
	 */
	public static function modules_active( array $pattern ): bool {
		foreach ( (array) ( $pattern['modules'] ?? array() ) as $module ) {
			if ( ! Loader::is_active( (string) $module ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Patterns laissés de côté parce qu'un de leurs modules est inactif : id du module => nombre (C27).
	 * Avec une liste de catégories, seuls les patterns d'au moins une de ces catégories comptent.
	 *
	 * @param array<int, string> $categories Catégories retenues, ou toutes si vide.
	 * @return array<string, int>
	 */
	public static function hidden( array $categories = array() ): array {
		if ( ! self::applies() ) {
			return array();
		}
		$hidden = array();
		foreach ( self::files() as $file ) {
			$pattern = self::read( $file );
			if ( null === $pattern || self::modules_active( $pattern ) ) {
				continue;
			}
			if ( array() !== $categories && array() === array_intersect( (array) ( $pattern['categories'] ?? array() ), $categories ) ) {
				continue;
			}
			foreach ( (array) ( $pattern['modules'] ?? array() ) as $module ) {
				$module = (string) $module;
				if ( ! Loader::is_active( $module ) ) {
					$hidden[ $module ] = ( $hidden[ $module ] ?? 0 ) + 1;
				}
			}
		}
		return $hidden;
	}

	/**
	 * Catégories du thème, enregistrées seulement si le thème ne l'a pas fait (init 11, après lui).
	 */
	public static function register_categories(): void {
		if ( ! self::applies() ) {
			return;
		}
		$registry = \WP_Block_Pattern_Categories_Registry::get_instance();
		foreach ( self::categories() as $slug => $label ) {
			if ( ! $registry->is_registered( $slug ) ) {
				register_block_pattern_category( $slug, array( 'label' => $label ) );
			}
		}
	}

	/**
	 * Catégories utilisées par les patterns : mêmes slugs que le thème.
	 *
	 * @return array<string, string> Slug => libellé traduit.
	 */
	public static function categories(): array {
		return array(
			'fluffless-structure'  => __( 'Structure', 'fluffless-suite' ),
			'fluffless-ouvertures' => __( 'Openers', 'fluffless-suite' ),
			'fluffless-presenter'  => __( 'Showcase', 'fluffless-suite' ),
			'fluffless-prouver'    => __( 'Proof', 'fluffless-suite' ),
			'fluffless-raconter'   => __( 'Storytelling', 'fluffless-suite' ),
			'fluffless-agir'       => __( 'Action', 'fluffless-suite' ),
			'fluffless-pages'      => __( 'Full pages', 'fluffless-suite' ),
		);
	}

	/**
	 * Fichiers de patterns, triés pour un ordre d'insertion stable.
	 *
	 * @return list<string>
	 */
	public static function files(): array {
		$files = glob( self::dir() . '*.php' );
		if ( false === $files ) {
			return array();
		}
		sort( $files );
		return $files;
	}

	/**
	 * Lit les en-têtes d'un fichier de pattern.
	 *
	 * @param string $file Chemin absolu du fichier.
	 * @return array<string, mixed>|null Propriétés pour register_block_pattern(), null si l'en-tête est invalide.
	 */
	public static function read( string $file ): ?array {
		if ( array_key_exists( $file, self::$read ) ) {
			return self::$read[ $file ];
		}
		self::$read[ $file ] = self::parse( $file );
		return self::$read[ $file ];
	}

	/**
	 * Lit et valide les en-têtes d'un fichier de pattern.
	 *
	 * @param string $file Chemin du fichier.
	 * @return array<string, mixed>|null
	 */
	private static function parse( string $file ): ?array {
		$headers = get_file_data( $file, self::HEADERS );
		if ( '' === $headers['title'] || 1 !== preg_match( self::SLUG_PATTERN, $headers['slug'] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'Pattern ignoré : %s. En-têtes attendus : « Title » non vide et « Slug » de forme fluffless/mon-pattern (reçu : « %s »).', $file, $headers['slug'] ) ),
				'0.1.0'
			);
			return null;
		}

		$pattern = array( 'filePath' => $file );
		foreach ( $headers as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$pattern[ $key ] = in_array( $key, self::LISTS, true )
				? array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) )
				: $value;
		}

		// Même traitement que le cœur pour les patterns de thème, dans le domaine de Suite.
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.LowLevelTranslationFunction
		$pattern['title'] = translate_with_gettext_context( $headers['title'], 'Pattern title', 'fluffless-suite' );
		if ( isset( $pattern['description'] ) ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.LowLevelTranslationFunction
			$pattern['description'] = translate_with_gettext_context( $headers['description'], 'Pattern description', 'fluffless-suite' );
		}
		if ( isset( $pattern['viewportWidth'] ) ) {
			$pattern['viewportWidth'] = (int) $pattern['viewportWidth'];
		}
		if ( isset( $pattern['inserter'] ) ) {
			$pattern['inserter'] = in_array( strtolower( $headers['inserter'] ), array( 'yes', 'true' ), true );
		}
		return $pattern;
	}
}
