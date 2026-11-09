<?php
/**
 * Bloc fluffless/icone : rendu SVG en ligne et données de l'éditeur.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Blocks;

use Fluffless\Suite\Presentation\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Rendu serveur de l'icône et fourniture de la liste curée à l'éditeur seulement.
 */
final class Icone {

	public const SIZES        = array( 'small', 'medium', 'large', 'xl' );
	public const DEFAULT_SIZE = 'medium';

	/** Attributs communs du SVG Lucide : trait de 2 sur une grille de 24, couleur du texte. */
	private const SVG_ATTRIBUTES = 'xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

	/**
	 * SVG décoratif d'une icône de la liste curée, pour les autres blocs ('' si le nom est inconnu).
	 *
	 * @param string $name Nom Lucide (kebab-case).
	 */
	public static function svg( string $name ): string {
		$icons = self::data()['icons'];
		return isset( $icons[ $name ] ) ? '<svg ' . self::SVG_ATTRIBUTES . ' aria-hidden="true" focusable="false">' . $icons[ $name ] . '</svg>' : '';
	}

	/** Longueur maximale d'un nom : bien au-delà du plus long nom Lucide, borne l'entrée. */
	private const MAX_NAME_LENGTH = 64;

	/**
	 * Liste chargée une seule fois par requête.
	 *
	 * @var array{version: string, categories: list<array{slug: string, label: string, names: list<string>}>, icons: array<string, string>}|null
	 */
	private static ?array $data = null;

	/**
	 * Retourne le nom s'il est en kebab-case strict, sinon null.
	 * Aucun point ni barre oblique ne passe : pas de traversée possible, et le nom ne sert
	 * de toute façon qu'à indexer un tableau, jamais à construire un chemin.
	 *
	 * @param mixed $raw Valeur reçue de l'attribut.
	 */
	public static function sanitize_name( $raw ): ?string {
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_NAME_LENGTH ) {
			return null;
		}
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $raw ) ? $raw : null;
	}

	/**
	 * Données générées par tools/build-icons.mjs.
	 *
	 * @return array{version: string, categories: list<array{slug: string, label: string, names: list<string>}>, icons: array<string, string>}
	 */
	public static function data(): array {
		if ( null === self::$data ) {
			$loaded = require dirname( __DIR__, 2 ) . '/blocks/icone/icons.php';
			if ( ! is_array( $loaded ) || ! isset( $loaded['icons'], $loaded['categories'], $loaded['version'] ) ) {
				$loaded = array(
					'version'    => '',
					'categories' => array(),
					'icons'      => array(),
				);
			}
			/**
			 * Forme garantie par le générateur, vérifiée ci-dessus sur ses clés.
			 *
			 * @var array{version: string, categories: list<array{slug: string, label: string, names: list<string>}>, icons: array<string, string>} $loaded
			 */
			self::$data = $loaded;
		}
		return self::$data;
	}

	/**
	 * Construit le balisage du bloc.
	 *
	 * @param array<string, mixed> $attributes Attributs du bloc.
	 */
	public static function render( array $attributes ): string {
		$name  = self::sanitize_name( $attributes['icon'] ?? null );
		$icons = self::data()['icons'];

		if ( null === $name || ! isset( $icons[ $name ] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$shown = null === $name ? 'nom invalide' : $name;
				return '<!-- fluffless/icone : icône inconnue (' . esc_html( $shown ) . ') -->';
			}
			return '';
		}

		$size  = in_array( $attributes['size'] ?? null, self::SIZES, true ) ? (string) $attributes['size'] : self::DEFAULT_SIZE;
		$label = is_string( $attributes['label'] ?? null ) ? trim( $attributes['label'] ) : '';

		if ( '' === $label ) {
			$a11y  = 'aria-hidden="true" focusable="false"';
			$title = '';
		} else {
			$id    = Ids::stable( 'fluffless-icone-', (string) wp_json_encode( $attributes ) );
			$a11y  = 'role="img" aria-labelledby="' . esc_attr( $id ) . '" focusable="false"';
			$title = '<title id="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</title>';
		}

		$wrapper = function_exists( 'get_block_wrapper_attributes' ) && null !== \WP_Block_Supports::$block_to_render
			? get_block_wrapper_attributes( array( 'class' => 'is-size-' . $size ) )
			: 'class="wp-block-fluffless-icone is-size-' . esc_attr( $size ) . '"';

		return sprintf(
			'<div %1$s><svg ' . self::SVG_ATTRIBUTES . ' %2$s>%3$s%4$s</svg></div>',
			$wrapper,
			$a11y,
			$title,
			$icons[ $name ]
		);
	}

	/**
	 * Injecte la liste curée dans le script d'éditeur, et nulle part ailleurs.
	 * Accrochée à enqueue_block_editor_assets : le visiteur ne la reçoit jamais.
	 */
	public static function add_editor_data(): void {
		$data = self::data();
		$json = wp_json_encode(
			array(
				'categories' => $data['categories'],
				'icons'      => $data['icons'],
			)
		);
		if ( false === $json ) {
			return;
		}
		wp_add_inline_script(
			generate_block_asset_handle( 'fluffless/icone', 'editorScript' ),
			'window.flufflessIcones=' . $json . ';',
			'before'
		);
	}
}
