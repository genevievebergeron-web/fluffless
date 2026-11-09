<?php
/**
 * Rapport d'une conversion : blocs créés, éléments gardés en texte, ignorés, à refaire,
 * réglages de style écartés, images gardées par adresse. Rangé en métadonnée de la page.
 *
 * @package Fluffless\Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Compteurs et listes d'une conversion.
 *
 * @phpstan-type ReportData array{blocks: int, kept: array<string, int>, skipped: array<string, int>, todo: list<string>, styles: int, images: list<string>}
 */
final class Report {

	/**
	 * Nombre de blocs écrits, enfants compris.
	 *
	 * @var int
	 */
	public int $blocks = 0;

	/**
	 * Éléments gardés en texte : sorte => nombre.
	 *
	 * @var array<string, int>
	 */
	public array $kept = array();

	/**
	 * Éléments ignorés (rien à garder) : sorte => nombre.
	 *
	 * @var array<string, int>
	 */
	public array $skipped = array();

	/**
	 * Choses à refaire à la main, une phrase chacune.
	 *
	 * @var list<string>
	 */
	public array $todo = array();

	/**
	 * Couleurs et tailles fixes écartées.
	 *
	 * @var int
	 */
	public int $styles = 0;

	/**
	 * Images gardées par adresse, sans fichier joint.
	 *
	 * @var list<string>
	 */
	public array $images = array();

	/**
	 * Un élément gardé en texte.
	 *
	 * @param string $kind Sorte d'élément.
	 */
	public function keep( string $kind ): void {
		$this->kept[ $kind ] = ( $this->kept[ $kind ] ?? 0 ) + 1;
	}

	/**
	 * Un élément ignoré.
	 *
	 * @param string $kind Sorte d'élément.
	 */
	public function skip( string $kind ): void {
		$this->skipped[ $kind ] = ( $this->skipped[ $kind ] ?? 0 ) + 1;
	}

	/**
	 * Une chose à refaire.
	 *
	 * @param string $text Phrase.
	 */
	public function todo( string $text ): void {
		if ( count( $this->todo ) < 100 ) {
			$this->todo[] = $text;
		}
	}

	/**
	 * Une image sans fichier joint.
	 *
	 * @param string $url Adresse.
	 */
	public function image( string $url ): void {
		if ( count( $this->images ) < 100 && ! in_array( $url, $this->images, true ) ) {
			$this->images[] = $url;
		}
	}

	/**
	 * Compte les blocs d'une liste, enfants compris.
	 *
	 * @param array<int, mixed> $blocks Blocs au format de parse_blocks().
	 */
	public function count_blocks( array $blocks ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			++$this->blocks;
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$this->count_blocks( $block['innerBlocks'] );
			}
		}
	}

	/**
	 * Tableau à ranger.
	 *
	 * @return ReportData
	 */
	public function to_array(): array {
		return array(
			'blocks'  => $this->blocks,
			'kept'    => $this->kept,
			'skipped' => $this->skipped,
			'todo'    => $this->todo,
			'styles'  => $this->styles,
			'images'  => $this->images,
		);
	}

	/**
	 * Depuis un tableau rangé.
	 *
	 * @param mixed $raw Valeur brute.
	 */
	public static function from_array( $raw ): self {
		$report = new self();
		if ( ! is_array( $raw ) ) {
			return $report;
		}
		$report->blocks = max( 0, (int) ( $raw['blocks'] ?? 0 ) );
		$report->styles = max( 0, (int) ( $raw['styles'] ?? 0 ) );
		foreach ( array( 'kept', 'skipped' ) as $key ) {
			$list = is_array( $raw[ $key ] ?? null ) ? $raw[ $key ] : array();
			foreach ( $list as $kind => $count ) {
				if ( is_string( $kind ) && '' !== $kind ) {
					$report->{$key}[ $kind ] = max( 0, (int) $count );
				}
			}
		}
		foreach ( array( 'todo', 'images' ) as $key ) {
			$list = is_array( $raw[ $key ] ?? null ) ? $raw[ $key ] : array();
			foreach ( $list as $text ) {
				if ( is_string( $text ) && '' !== $text ) {
					$report->{$key}[] = $text;
				}
			}
		}
		return $report;
	}
}
