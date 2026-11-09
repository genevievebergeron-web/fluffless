<?php
/**
 * Reprise des champs de Yoast SEO, SEOPress et Rank Math.
 *
 * @package Fluffless_Seo
 */

declare( strict_types=1 );

namespace Fluffless\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Simulation par défaut. Un champ déjà rempli chez nous n'est jamais écrasé, et les
 * données d'origine ne sont jamais supprimées : on peut revenir en arrière en réactivant
 * l'ancienne extension.
 */
final class Importer {

	/** Contenus traités par lot. */
	public const BATCH = 500;

	/** Lots au plus (500 x 400 = 200 000 contenus). */
	private const MAX_BATCHES = 400;

	/**
	 * Clé d'origine par notre clé, pour chaque source. Les noms de clés sont ceux que ces
	 * extensions écrivent dans wp_postmeta (interopérabilité, aucun code repris).
	 */
	private const MAPS = array(
		'yoast'    => array(
			Meta::TITLE       => '_yoast_wpseo_title',
			Meta::DESCRIPTION => '_yoast_wpseo_metadesc',
			Meta::CANONICAL   => '_yoast_wpseo_canonical',
			Meta::OG_TITLE    => '_yoast_wpseo_opengraph-title',
			Meta::OG_DESC     => '_yoast_wpseo_opengraph-description',
			Meta::OG_IMAGE    => '_yoast_wpseo_opengraph-image-id',
			Meta::KEYWORD     => '_yoast_wpseo_focuskw',
		),
		'seopress' => array(
			Meta::TITLE       => '_seopress_titles_title',
			Meta::DESCRIPTION => '_seopress_titles_desc',
			Meta::CANONICAL   => '_seopress_robots_canonical',
			Meta::OG_TITLE    => '_seopress_social_fb_title',
			Meta::OG_DESC     => '_seopress_social_fb_desc',
			Meta::OG_IMAGE    => '_seopress_social_fb_img_attachment_id',
			Meta::KEYWORD     => '_seopress_analysis_target_kw',
		),
		'rankmath' => array(
			Meta::TITLE       => 'rank_math_title',
			Meta::DESCRIPTION => 'rank_math_description',
			Meta::CANONICAL   => 'rank_math_canonical_url',
			Meta::OG_TITLE    => 'rank_math_facebook_title',
			Meta::OG_DESC     => 'rank_math_facebook_description',
			Meta::OG_IMAGE    => 'rank_math_facebook_image_id',
			Meta::KEYWORD     => 'rank_math_focus_keyword',
		),
	);

	/** Clés d'origine des directives robots, par source : clé => [valeur attendue => directive]. */
	private const ROBOTS = array(
		'yoast'    => array(
			'_yoast_wpseo_meta-robots-noindex'  => array( '1' => 'noindex' ),
			'_yoast_wpseo_meta-robots-nofollow' => array( '1' => 'nofollow' ),
			'_yoast_wpseo_meta-robots-adv'      => array(
				'noarchive' => 'noarchive',
				'nosnippet' => 'nosnippet',
			),
		),
		'seopress' => array(
			'_seopress_robots_index'   => array( 'yes' => 'noindex' ),
			'_seopress_robots_follow'  => array( 'yes' => 'nofollow' ),
			'_seopress_robots_archive' => array( 'yes' => 'noarchive' ),
			'_seopress_robots_snippet' => array( 'yes' => 'nosnippet' ),
		),
		'rankmath' => array(
			'rank_math_robots' => array(
				'noindex'   => 'noindex',
				'nofollow'  => 'nofollow',
				'noarchive' => 'noarchive',
				'nosnippet' => 'nosnippet',
			),
		),
	);

	/** Variables d'origine => les nôtres (après passage de %x% à %%x%%). */
	private const VARIABLES = array(
		'post_title'       => 'title',
		'sitetitle'        => 'sitename',
		'tagline'          => 'sitedesc',
		'post_excerpt'     => 'excerpt',
		'excerpt_only'     => 'excerpt',
		'term_title'       => 'term',
		'primary_category' => 'category',
		'post_author'      => 'author',
		'name'             => 'author',
		'pagenumber'       => 'page',
		'current_year'     => 'currentyear',
		'seo_title'        => 'title',
	);

	/**
	 * Sources connues.
	 *
	 * @return list<string>
	 */
	public static function sources(): array {
		return array_keys( self::MAPS );
	}

	/**
	 * Parcourt les contenus de la source et écrit (ou compte, en simulation) les champs repris.
	 *
	 * @param string $source yoast, seopress ou rankmath.
	 * @param bool   $apply  Écrit réellement.
	 * @return array{posts: int, written: int, kept: int, fields: array<string, int>}
	 * @throws \InvalidArgumentException Source inconnue.
	 */
	public static function run( string $source, bool $apply = false ): array {
		if ( ! isset( self::MAPS[ $source ] ) ) {
			/* translators: 1: source received, 2: accepted sources. */
			throw new \InvalidArgumentException( sprintf( __( 'Unknown source %1$s. Use one of: %2$s.', 'fluffless-seo' ), $source, implode( ', ', self::sources() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte brut, échappé à l'affichage (Screen::note, WP_CLI).
		}
		$report = array(
			'posts'   => 0,
			'written' => 0,
			'kept'    => 0,
			'fields'  => array(),
		);
		$keys   = array_merge( array_values( self::MAPS[ $source ] ), array_keys( self::ROBOTS[ $source ] ) );
		for ( $batch = 0, $last = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$ids = self::post_ids( $keys, $last );
			if ( array() === $ids ) {
				break;
			}
			// Une requête par lot pour toutes les métadonnées, au lieu d'une par contenu.
			update_meta_cache( 'post', $ids );
			foreach ( $ids as $post_id ) {
				++$report['posts'];
				foreach ( self::changes( $source, $post_id ) as $key => $value ) {
					if ( ! self::is_empty( get_post_meta( $post_id, $key, true ) ) ) {
						++$report['kept'];
						continue;
					}
					++$report['written'];
					$report['fields'][ $key ] = ( $report['fields'][ $key ] ?? 0 ) + 1;
					if ( $apply ) {
						update_post_meta( $post_id, $key, $value );
					}
				}
			}
			$last = (int) end( $ids );
		}
		return $report;
	}

	/**
	 * Contenus (après $after) qui portent au moins une des clés, par lot.
	 *
	 * @param string[] $keys  Clés d'origine.
	 * @param int      $after Dernier identifiant traité.
	 * @return list<int>
	 */
	private static function post_ids( array $keys, int $after ): array {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$ids          = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- importation ponctuelle, par lot.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- valeurs passées en tableau.
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id > %d AND meta_key IN ({$placeholders}) ORDER BY post_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- espaces réservés générés.
				array_merge( array( $after ), $keys, array( self::BATCH ) )
			)
		);
		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * Valeurs à écrire pour un contenu, notre clé => valeur convertie.
	 *
	 * @param string $source  Source.
	 * @param int    $post_id Contenu.
	 * @return array<string, mixed>
	 */
	public static function changes( string $source, int $post_id ): array {
		$changes = array();
		foreach ( self::MAPS[ $source ] ?? array() as $ours => $theirs ) {
			$value = get_post_meta( $post_id, $theirs, true );
			if ( self::is_empty( $value ) || ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			$clean = match ( $ours ) {
				Meta::OG_IMAGE  => (int) $value > 0 ? (int) $value : null,
				Meta::CANONICAL => '' !== Meta::sanitize_url( $value ) ? Meta::sanitize_url( $value ) : null,
				// SEOPress et Rank Math acceptent plusieurs mots-clés séparés par des virgules : le premier est le principal.
				Meta::KEYWORD   => mb_substr( sanitize_text_field( trim( explode( ',', $value )[0] ) ), 0, 100 ),
				default         => mb_substr( sanitize_text_field( self::convert_variables( $value ) ), 0, Meta::TITLE === $ours || Meta::OG_TITLE === $ours ? 200 : 300 ),
			};
			if ( null !== $clean && '' !== $clean ) {
				$changes[ $ours ] = $clean;
			}
		}
		$robots = self::robots( $source, $post_id );
		if ( array() !== $robots ) {
			$changes[ Meta::ROBOTS ] = $robots;
		}
		return $changes;
	}

	/**
	 * Directives robots reprises.
	 *
	 * @param string $source  Source.
	 * @param int    $post_id Contenu.
	 * @return list<string>
	 */
	private static function robots( string $source, int $post_id ): array {
		$found = array();
		foreach ( self::ROBOTS[ $source ] ?? array() as $key => $map ) {
			$value  = get_post_meta( $post_id, $key, true );
			$values = is_array( $value ) ? array_map( 'strval', $value ) : array_map( 'trim', explode( ',', is_scalar( $value ) ? (string) $value : '' ) );
			foreach ( $map as $expected => $directive ) {
				if ( in_array( (string) $expected, $values, true ) ) {
					$found[] = $directive;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Variables des autres extensions converties aux nôtres ; les inconnues restent et seront retirées au rendu.
	 *
	 * @param string $text Texte d'origine.
	 */
	public static function convert_variables( string $text ): string {
		// Rank Math : %title% ; les autres : %%title%%.
		$text = (string) preg_replace( '/(?<!%)%([a-z_]{1,30})%(?!%)/', '%%$1%%', $text );
		return (string) preg_replace_callback(
			'/%%([a-z_]{1,30})%%/',
			static fn( array $found ): string => '%%' . ( self::VARIABLES[ $found[1] ] ?? $found[1] ) . '%%',
			$text
		);
	}

	/**
	 * Valeur vide (y compris tableau vide).
	 *
	 * @param mixed $value Valeur.
	 */
	private static function is_empty( $value ): bool {
		return null === $value || '' === $value || array() === $value || false === $value;
	}
}
