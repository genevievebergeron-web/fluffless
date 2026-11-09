<?php
/**
 * Bloc fluffless/graphique : secteurs, anneau ou barres, rendu en PHP, CSS en ligne, aucun script
 * sur le site ni bibliothèque. Le dessin est décoratif (aria-hidden) : toute l'information est dans
 * un vrai tableau visible, qui sert aussi de légende.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Blocks;

use Fluffless\Suite\Modules\Expression;

defined( 'ABSPATH' ) || exit;

/**
 * Attributs bornés, calcul des parts, rendu et feuilles du graphique. Enregistré par le module
 * Expressivité.
 */
final class Graphique {

	public const NAME  = 'fluffless/graphique';
	public const STYLE = 'fluffless-graphique';

	public const TYPES = array( 'donut', 'pie', 'bars' );

	/** Diamètre du disque : clé de l'attribut => valeur de --fl-g-s. La feuille porte medium. */
	public const SIZES = array(
		'small'  => '12rem',
		'medium' => '16rem',
		'large'  => '22rem',
	);

	public const MIN_ROWS  = 2;
	public const MAX_ROWS  = 8;
	public const MAX_LABEL = 40;
	public const MAX_TITLE = 120;
	public const MAX_UNIT  = 12;
	public const MAX_VALUE = 1.0e12;

	private const SIZE = 'medium';

	/**
	 * Séquence fixe des couleurs : slug de la palette, part en pourcentage (100 : la couleur
	 * telle quelle, moins : teinte mêlée au fond), et part de repli de la couleur du texte quand le
	 * slug manque à la palette.
	 */
	private const SEQUENCE = array(
		array( 'accent', 100, 100 ),
		array( 'contrast', 100, 70 ),
		array( 'accent-2', 100, 50 ),
		array( 'muted', 100, 36 ),
		array( 'accent', 55, 26 ),
		array( 'divider', 100, 18 ),
		array( 'accent-2', 55, 12 ),
		array( 'contrast', 30, 7 ),
	);

	/**
	 * Attributs nettoyés. Une rangée sans étiquette ou sans valeur numérique finie et positive est
	 * écartée : aucune donnée n'est inventée pour la remplacer.
	 *
	 * @param array<mixed> $attributes Attributs bruts du bloc.
	 * @return array{type: string, title: string, rows: array<int, array{label: string, value: float}>, unit: string, showValues: bool, size: string, align: string, className: string}
	 */
	public static function attributes( array $attributes ): array {
		$rows = array();
		foreach ( is_array( $attributes['rows'] ?? null ) ? $attributes['rows'] : array() as $row ) {
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
			$label = is_array( $row ) && is_string( $row['label'] ?? null ) ? trim( sanitize_text_field( $row['label'] ) ) : '';
			$value = is_array( $row ) ? ( $row['value'] ?? null ) : null;
			if ( '' === $label || ! ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) ) {
				continue;
			}
			$value = (float) $value;
			if ( is_finite( $value ) && $value >= 0 ) {
				$rows[] = array(
					'label' => mb_substr( $label, 0, self::MAX_LABEL ),
					'value' => min( $value, self::MAX_VALUE ),
				);
			}
		}
		$text = static function ( string $key, int $max ) use ( $attributes ): string {
			$value = $attributes[ $key ] ?? null;
			return is_string( $value ) ? mb_substr( trim( sanitize_text_field( $value ) ), 0, $max ) : '';
		};
		$type = $attributes['type'] ?? null;
		$size = $attributes['size'] ?? null;
		return array(
			'type'       => is_string( $type ) && in_array( $type, self::TYPES, true ) ? $type : self::TYPES[0],
			'title'      => $text( 'title', self::MAX_TITLE ),
			'rows'       => $rows,
			'unit'       => $text( 'unit', self::MAX_UNIT ),
			'showValues' => is_bool( $attributes['showValues'] ?? null ) ? $attributes['showValues'] : true,
			'size'       => is_string( $size ) && isset( self::SIZES[ $size ] ) ? $size : self::SIZE,
			'align'      => Expression::align( $attributes['align'] ?? null ),
			'className'  => Expression::class_list( $attributes['className'] ?? null ),
		);
	}

	/**
	 * Parts entières qui totalisent toujours 100 (méthode des plus grands restes : chaque part est
	 * arrondie vers le bas, puis les points manquants vont aux plus grands restes, à égalité à la
	 * première rangée). Total nul : aucune part.
	 *
	 * @param array<int, int|float> $values Valeurs positives.
	 * @return array<int, int>
	 */
	public static function shares( array $values ): array {
		$values = array_values( $values );
		$total  = array_sum( $values );
		if ( $total <= 0 ) {
			return array();
		}
		$shares     = array();
		$remainders = array();
		foreach ( $values as $index => $value ) {
			$exact                = $value / $total * 100;
			$shares[ $index ]     = (int) floor( $exact );
			$remainders[ $index ] = $exact - $shares[ $index ];
		}
		// Tri stable (PHP 8) : à reste égal, l'ordre des rangées est gardé.
		arsort( $remainders );
		$missing = 100 - array_sum( $shares );
		foreach ( array_keys( $remainders ) as $index ) {
			if ( $missing <= 0 ) {
				break;
			}
			++$shares[ $index ];
			--$missing;
		}
		return $shares;
	}

	/**
	 * Les huit couleurs de la séquence, en valeurs CSS. Après les quatre rôles de la palette, des
	 * teintes par color-mix vers le fond. Un slug absent de la palette (autre thème) est remplacé
	 * par une teinte de la couleur du texte : les huit valeurs restent distinctes.
	 *
	 * @param array<int, string>|null $slugs Slugs de la palette ; null : palette du site.
	 * @return array<int, string>
	 */
	public static function colours( ?array $slugs = null ): array {
		$slugs   = $slugs ?? Expression::palette_slugs();
		$ground  = in_array( 'base', $slugs, true ) ? 'var(--wp--preset--color--base)' : 'transparent';
		$colours = array();
		foreach ( self::SEQUENCE as list( $slug, $share, $fallback ) ) {
			if ( ! in_array( $slug, $slugs, true ) ) {
				$colours[] = 'color-mix(in srgb,currentColor ' . $fallback . '%,transparent)';
				continue;
			}
			$preset    = 'var(--wp--preset--color--' . $slug . ')';
			$colours[] = 100 === $share ? $preset : 'color-mix(in srgb,' . $preset . ' ' . $share . '%,' . $ground . ')';
		}
		return $colours;
	}

	/**
	 * Rendu du bloc. Moins de deux rangées valides ou total nul : rien, et aucune feuille. Dans un
	 * extrait ou un flux : le tableau seul.
	 *
	 * @param array<mixed>|mixed $attributes Attributs du bloc.
	 */
	public static function render( $attributes ): string {
		$a      = self::attributes( is_array( $attributes ) ? $attributes : array() );
		$shares = self::shares( array_column( $a['rows'], 'value' ) );
		if ( count( $a['rows'] ) < self::MIN_ROWS || array() === $shares ) {
			return '';
		}
		$plain = doing_filter( 'get_the_excerpt' ) || is_feed();
		$bars  = 'bars' === $a['type'];
		$max   = max( array_column( $a['rows'], 'value' ) );

		$body  = '';
		$stops = array();
		$sum   = 0;
		foreach ( $a['rows'] as $index => $row ) {
			$colour = 'var(--g' . ( $index + 1 ) . ')';
			$cells  = '';
			if ( $bars && ! $plain ) {
				// Nombre arrondi par PHP : seul contenu variable du style.
				$cells = '<td class="fl-gr-bar" aria-hidden="true"><span style="color:' . $colour . ';--v:' . round( $row['value'] / $max * 100, 1 ) . '"></span></td>';
			}
			if ( $a['showValues'] ) {
				$cells .= '<td>' . esc_html( self::number( $row['value'] ) . ( '' === $a['unit'] ? '' : "\u{00A0}" . $a['unit'] ) ) . '</td>';
			}
			/* translators: %s: share of the total, a whole number from 0 to 100. */
			$cells .= '<td>' . esc_html( sprintf( __( '%s%%', 'fluffless-suite' ), number_format_i18n( $shares[ $index ] ) ) ) . '</td>';
			$chip   = $bars || $plain ? '' : '<span class="fl-gr-chip" aria-hidden="true" style="color:' . $colour . '"></span>';
			$body  .= '<tr><th scope="row">' . $chip . esc_html( $row['label'] ) . '</th>' . $cells . '</tr>';
			if ( $shares[ $index ] > 0 ) {
				$sum    += $shares[ $index ];
				$stops[] = $colour . ' 0 ' . $sum . '%';
			}
		}
		$head  = '<th scope="col">' . esc_html__( 'Label', 'fluffless-suite' ) . '</th>'
			. ( $bars && ! $plain ? '<td></td>' : '' )
			. ( $a['showValues'] ? '<th scope="col">' . esc_html__( 'Value', 'fluffless-suite' ) . '</th>' : '' )
			. '<th scope="col">' . esc_html__( 'Share', 'fluffless-suite' ) . '</th>';
		$table = '<table class="fl-gr-table"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
		if ( $plain ) {
			return $table;
		}

		// Valeurs sorties de constantes, de la palette validée ou de calculs entiers, jamais d'une saisie.
		$style = '';
		foreach ( array_slice( self::colours(), 0, count( $a['rows'] ) ) as $index => $colour ) {
			$style .= '--g' . ( $index + 1 ) . ':' . $colour . ';';
		}
		$style  .= self::SIZE === $a['size'] ? '' : '--fl-g-s:' . self::SIZES[ $a['size'] ];
		$classes = array_filter(
			array(
				'fl-graphique',
				'is-type-' . $a['type'],
				'' === $a['align'] ? '' : 'align' . $a['align'],
				$a['className'],
			)
		);
		$draw    = '';
		if ( ! $bars ) {
			// Le dernier arrêt va jusqu'au bout du disque.
			$stops[ count( $stops ) - 1 ] = (string) preg_replace( '/ \d+%$/', '', $stops[ count( $stops ) - 1 ] );
			$draw                         = '<div class="fl-gr-draw" aria-hidden="true" style="background:conic-gradient(' . esc_attr( implode( ',', $stops ) ) . ')"></div>';
		}

		Expression::enqueue_inline( self::STYLE, self::base_css() );
		Expression::enqueue_inline( self::STYLE . '-' . $a['type'], self::type_css( $a['type'] ) );
		return sprintf(
			'<figure class="%1$s" style="%2$s">%3$s<div class="fl-gr-body">%4$s%5$s</div></figure>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( rtrim( $style, ';' ) ),
			'' === $a['title'] ? '' : '<figcaption class="fl-gr-title">' . esc_html( $a['title'] ) . '</figcaption>',
			$draw,
			$table
		);
	}

	/**
	 * Feuille complète d'un type (ou de tous, pour l'éditeur). Le site charge la base et chaque
	 * type sous sa propre poignée.
	 *
	 * @param string $type Type de TYPES, ou '' pour tous.
	 */
	public static function css( string $type = '' ): string {
		$css = self::base_css();
		foreach ( self::TYPES as $candidate ) {
			$css .= in_array( $type, array( '', $candidate ), true ) ? self::type_css( $candidate ) : '';
		}
		return $css;
	}

	/**
	 * Données du script d'éditeur : mêmes bornes, mêmes tailles et mêmes couleurs que le rendu.
	 *
	 * @return array{colours: array<int, string>, sizes: array<string, string>, minRows: int, maxRows: int, maxLabel: int, maxTitle: int, maxUnit: int}
	 */
	public static function editor_data(): array {
		return array(
			'colours'  => self::colours(),
			'sizes'    => self::SIZES,
			'minRows'  => self::MIN_ROWS,
			'maxRows'  => self::MAX_ROWS,
			'maxLabel' => self::MAX_LABEL,
			'maxTitle' => self::MAX_TITLE,
			'maxUnit'  => self::MAX_UNIT,
		);
	}

	/**
	 * Valeur localisée, avec des décimales seulement si elles servent (deux au plus).
	 *
	 * @param float $value Valeur de la rangée.
	 */
	private static function number( float $value ): string {
		$rounded  = round( $value, 2 );
		$decimals = floor( $rounded ) === $rounded ? 0 : ( round( $rounded, 1 ) === $rounded ? 1 : 2 );
		return number_format_i18n( $rounded, $decimals );
	}

	/**
	 * Base : le titre, le corps qui passe à la ligne sous le disque quand la place manque, et le
	 * tableau. La pastille est une bordure en couleur courante, pas un fond : elle reste visible
	 * en couleurs forcées et à l'impression sans réglage.
	 */
	private static function base_css(): string {
		return '.fl-gr-title{margin-bottom:1rem;font-weight:700}'
			. '.fl-gr-body{display:flex;flex-wrap:wrap;align-items:center;gap:1.5rem 2.5rem}'
			. '.fl-gr-table{flex:1 1 16rem;width:auto;border-collapse:collapse;font-variant-numeric:tabular-nums}'
			. '.fl-gr-table :is(th,td){padding:.45em .5em;border:0;border-bottom:1px solid;font-weight:400;text-align:end}'
			. '.fl-gr-table th:first-child{text-align:start}'
			. '.fl-gr-table thead th{font-size:.8em;font-weight:700}'
			. '.fl-gr-chip{display:inline-block;margin-inline-end:.6em;border:.4em solid;border-radius:50%}';
	}

	/**
	 * Part d'un type. Disque : fond en dégradé conique, gardé à l'impression, masqué en couleurs
	 * forcées où un fond disparaît (le tableau reste). Anneau : le même disque percé par un masque.
	 * Barres : trait en bordure haute dont la largeur vient de --v, visible en couleurs forcées.
	 *
	 * @param string $type Type de TYPES.
	 */
	private static function type_css( string $type ): string {
		if ( 'bars' === $type ) {
			return '.fl-gr-bar.fl-gr-bar{width:40%;padding-inline-end:0}'
				. '.fl-gr-bar span{display:block;width:calc(var(--v)*1%);border-top:.9em solid}';
		}
		return '.fl-gr-draw{flex:none;width:var(--fl-g-s,16rem);max-width:100%;margin-inline:auto;aspect-ratio:1;border-radius:50%;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
			. '@media (forced-colors:active){.fl-gr-draw{display:none}}'
			. ( 'donut' === $type ? '.is-type-donut .fl-gr-draw{mask:radial-gradient(closest-side,transparent 58%,black 59%)}' : '' );
	}
}
