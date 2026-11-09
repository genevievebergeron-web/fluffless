<?php
/**
 * Migrateur : repère les pages prises en charge par un constructeur, les convertit sur place en
 * blocs natifs (contenu d'avant gardé en métadonnée, marqueur du constructeur retiré) et les
 * remet en place. Les données du constructeur ne sont jamais supprimées.
 *
 * @package Fluffless\Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules\Migration;

use Fluffless\Suite\Core\Options;
use Fluffless\Suite\Modules\Fonts;
use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Modules\Security\Journal;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Repérage, conversion et remise en place.
 *
 * @phpstan-type Found array{id: int, title: string, type: string, status: string, builder: string, elements: int, migrated: string}
 * @phpstan-type Outcome array{ok: bool, code: string, report: Report|null}
 * @phpstan-type Batch array{converted: int, failed: int, remaining: int}
 * @phpstan-type Proposal array{builder: string, colors: array<string, string>, families: array<string, string>, palette: array<string, string>|null, failures: list<string>, pair: int|null}
 */
final class Migrator {

	public const META_DONE   = '_fluffless_migrated';
	public const META_BACKUP = '_fluffless_migration_backup';
	public const META_REPORT = '_fluffless_migration_report';

	/** Pages listées au plus. */
	public const LIMIT = 200;

	/** Pages converties par lot depuis l'écran. */
	public const BATCH = 20;

	/**
	 * Lecteurs disponibles : identifiant => classe.
	 *
	 * @return array<string, class-string<Reader>>
	 */
	public static function readers(): array {
		return array(
			Elementor::id() => Elementor::class,
			Divi::id()      => Divi::class,
		);
	}

	/**
	 * Lecteur d'une page, ou null si aucun constructeur ne la prend en charge.
	 *
	 * @param int $post_id Identifiant.
	 * @return class-string<Reader>|null
	 */
	public static function reader( int $post_id ): ?string {
		$done = get_post_meta( $post_id, self::META_DONE, true );
		if ( is_array( $done ) && is_string( $done['builder'] ?? null ) && isset( self::readers()[ $done['builder'] ] ) ) {
			$known = self::readers()[ $done['builder'] ];
			if ( $known::supports( $post_id ) ) {
				return $known;
			}
		}
		foreach ( self::readers() as $reader ) {
			if ( $reader::supports( $post_id ) ) {
				return $reader;
			}
		}
		return null;
	}

	/**
	 * Pages et articles prises en charge par un constructeur, converties ou non.
	 *
	 * @return list<Found>
	 */
	public static function detect(): array {
		$clauses = array( 'relation' => 'OR' );
		foreach ( self::readers() as $reader ) {
			$clauses[] = $reader::clause();
		}
		$clauses[] = array(
			'key'     => self::META_DONE,
			'compare' => 'EXISTS',
		);
		$ids       = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => self::LIMIT,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => $clauses, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- repérage, écran d'administration seulement.
			)
		);
		$found     = array();
		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$reader = self::reader( $id );
			$post   = get_post( $id );
			if ( null === $reader || ! $post instanceof WP_Post ) {
				continue;
			}
			$done    = get_post_meta( $id, self::META_DONE, true );
			$found[] = array(
				'id'       => $id,
				'title'    => '' === $post->post_title ? __( '(no title)', 'fluffless-suite' ) : $post->post_title,
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'builder'  => $reader::id(),
				'elements' => array_sum( $reader::count( $reader::tree( $id ) ) ),
				'migrated' => is_array( $done ) && is_string( $done['date'] ?? null ) ? $done['date'] : '',
			);
		}
		return $found;
	}

	/**
	 * Convertit une page sur place.
	 *
	 * @param int  $post_id     Identifiant.
	 * @param bool $keep_colors Garder les couleurs fixes.
	 * @return Outcome
	 */
	public static function convert( int $post_id, bool $keep_colors ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return self::outcome( 'missing' );
		}
		$reader = self::reader( $post_id );
		if ( null === $reader ) {
			return self::outcome( 'unsupported' );
		}
		$report = new Report();
		$blocks = $reader::convert( $reader::tree( $post_id ), $keep_colors, $report );
		if ( array() === $blocks ) {
			return self::outcome( 'empty', $report );
		}
		$content = Blocks::serialize( $blocks );
		if ( false === get_post_meta( $post_id, self::META_BACKUP, true ) || '' === get_post_meta( $post_id, self::META_BACKUP, true ) ) {
			update_post_meta(
				$post_id,
				self::META_BACKUP,
				array(
					'content' => $post->post_content,
					'mode'    => $reader::mode( $post_id ),
					'builder' => $reader::id(),
				)
			);
		}
		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( ! is_int( $updated ) ) {
			return self::outcome( 'write' );
		}
		$reader::detach( $post_id );
		update_post_meta(
			$post_id,
			self::META_DONE,
			array(
				'builder' => $reader::id(),
				'date'    => gmdate( 'Y-m-d H:i:s' ),
				'colors'  => $keep_colors,
			)
		);
		update_post_meta( $post_id, self::META_REPORT, $report->to_array() );
		Journal::record( 'page_converted', self::subject( $post ) );
		return array(
			'ok'     => true,
			'code'   => 'converted',
			'report' => $report,
		);
	}

	/**
	 * Remet une page dans son état d'avant la conversion.
	 *
	 * @param int $post_id Identifiant.
	 */
	public static function revert( int $post_id ): bool {
		$backup = get_post_meta( $post_id, self::META_BACKUP, true );
		if ( ! is_array( $backup ) || ! is_string( $backup['content'] ?? null ) || ! get_post( $post_id ) instanceof WP_Post ) {
			return false;
		}
		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $backup['content'] ),
			),
			true
		);
		if ( ! is_int( $updated ) ) {
			return false;
		}
		$mode    = is_string( $backup['mode'] ?? null ) ? $backup['mode'] : '';
		$builder = is_string( $backup['builder'] ?? null ) ? $backup['builder'] : Elementor::id();
		$reader  = self::readers()[ $builder ] ?? null;
		if ( null !== $reader ) {
			$reader::reattach( $post_id, $mode );
		}
		delete_post_meta( $post_id, self::META_BACKUP );
		delete_post_meta( $post_id, self::META_DONE );
		delete_post_meta( $post_id, self::META_REPORT );
		Journal::record( 'page_reverted', self::subject( get_post( $post_id ) ) );
		return true;
	}

	/**
	 * Convertit en une passe les pages repérées qui ne le sont pas encore, par lot : les pages
	 * déjà converties sont sautées, une page qui échoue est comptée et la passe continue. Relancer
	 * la même passe reprend là où elle s'est arrêtée.
	 *
	 * @param bool $keep_colors Garder les couleurs fixes.
	 * @param int  $limit       Pages converties au plus dans cette passe.
	 * @return Batch
	 */
	public static function convert_all( bool $keep_colors, int $limit = self::BATCH ): array {
		$pending = array();
		foreach ( self::detect() as $found ) {
			if ( '' === $found['migrated'] ) {
				$pending[] = $found['id'];
			}
		}
		$converted = 0;
		$failed    = 0;
		foreach ( array_slice( $pending, 0, max( 0, $limit ) ) as $id ) {
			if ( self::convert( $id, $keep_colors )['ok'] ) {
				++$converted;
			} else {
				++$failed;
			}
		}
		return array(
			'converted' => $converted,
			'failed'    => $failed,
			'remaining' => max( 0, count( $pending ) - $converted - $failed ),
		);
	}

	/**
	 * Sujet d'une ligne du journal : identifiant et titre.
	 *
	 * @param WP_Post $post Page.
	 */
	private static function subject( WP_Post $post ): string {
		return '#' . $post->ID . ' ' . $post->post_title;
	}

	/**
	 * Rapport rangé d'une page convertie, ou null.
	 *
	 * @param int $post_id Identifiant.
	 */
	public static function report( int $post_id ): ?Report {
		$raw = get_post_meta( $post_id, self::META_REPORT, true );
		return is_array( $raw ) ? Report::from_array( $raw ) : null;
	}

	/**
	 * Gabarits des constructeurs qui ne s'insèrent pas dans une page : sorte => titres.
	 *
	 * @return array<string, list<string>>
	 */
	public static function templates(): array {
		$out = array();
		foreach ( self::readers() as $reader ) {
			foreach ( $reader::templates() as $type => $titles ) {
				foreach ( $titles as $title ) {
					$out[ $type ][] = $title;
				}
			}
		}
		return $out;
	}

	/**
	 * Réglages globaux du premier constructeur qui en a : couleurs et familles relues, palette
	 * candidate avec ses paires sous le seuil, rang du duo de polices reconnu ; null sans rien.
	 *
	 * @return Proposal|null
	 */
	public static function globals(): ?array {
		foreach ( self::readers() as $id => $reader ) {
			$read = $reader::globals();
			if ( array() === $read['colors'] && array() === $read['families'] ) {
				continue;
			}
			$candidate = Globals::palette( $read['colors'] );
			return array(
				'builder'  => $id,
				'colors'   => $read['colors'],
				'families' => $read['families'],
				'palette'  => null === $candidate ? null : $candidate['palette'],
				'failures' => null === $candidate ? array() : $candidate['failures'],
				'pair'     => Globals::pair( $read['families'] ),
			);
		}
		return null;
	}

	/**
	 * Applique la palette candidate : « ok », « fail » (aucune, sous le seuil ou refusée) ou
	 * « off » (module Palette éteint).
	 */
	public static function apply_palette(): string {
		$proposal = self::globals();
		if ( null === $proposal || null === $proposal['palette'] || array() !== $proposal['failures'] ) {
			return 'fail';
		}
		if ( ! Options::is_enabled( Palette::id() ) ) {
			return 'off';
		}
		return Palette::apply( $proposal['palette'] ) ? 'ok' : 'fail';
	}

	/**
	 * Applique le duo reconnu : « applied », « download », « integrity », « write », « error » (voir Fonts::apply_pair) ou
	 * « off » (module Polices éteint).
	 */
	public static function apply_fonts(): string {
		$proposal = self::globals();
		if ( null === $proposal || null === $proposal['pair'] ) {
			return 'error';
		}
		if ( ! Options::is_enabled( Fonts::id() ) ) {
			return 'off';
		}
		return Fonts::apply_pair( $proposal['pair'] );
	}

	/**
	 * Résultat raté.
	 *
	 * @param string      $code   Code.
	 * @param Report|null $report Rapport.
	 * @return Outcome
	 */
	private static function outcome( string $code, ?Report $report = null ): array {
		return array(
			'ok'     => false,
			'code'   => $code,
			'report' => $report,
		);
	}
}
