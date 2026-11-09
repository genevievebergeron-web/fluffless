<?php
/**
 * Lecteur du constructeur D : les codes courts imbriqués rangés dans le contenu de la page
 * deviennent une liste de blocs natifs. Structure (section, rangée, colonne) en groupes et
 * colonnes, modules en blocs du cœur, module inconnu gardé en texte et compté au rapport.
 *
 * Le nom du constructeur reste un identifiant technique : aucune chaîne affichée ne le nomme.
 *
 * @package Fluffless\Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules\Migration;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Conversion des codes courts du constructeur D.
 *
 * @phpstan-import-type Block from Blocks
 * @phpstan-type Element array{tag: string, attrs: array<string, string>, content: string, children: list<array<string, mixed>>}
 */
final class Divi extends Reader {

	/** Marqueur de prise en charge (« on » ou « off »). */
	public const META_MODE = '_et_pb_use_builder';

	/** Option des réglages du thème (couleurs et polices globales). */
	public const OPTION_THEME = 'et_divi';

	/** Option des couleurs globales. */
	public const OPTION_COLORS = 'et_global_colors';

	/** Type de contenu des mises en page enregistrées (modules globaux). */
	public const TYPE_LIBRARY = 'et_pb_layout';

	/** Types de contenu du constructeur de thème : type => sorte listée. */
	private const THEME_TYPES = array(
		'et_header_layout' => 'header',
		'et_body_layout'   => 'body',
		'et_footer_layout' => 'footer',
	);

	/** Préfixe des codes courts. */
	private const PREFIX = 'et_pb_';

	/** Contexte : élément de premier niveau. */
	private const TOP = 'top';

	/** Contexte : sous une colonne ou une section déjà posée. */
	private const INNER = 'inner';

	/**
	 * Profondeur des modules globaux en cours d'insertion (garde contre les boucles).
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/** Identifiant du lecteur. */
	public static function id(): string {
		return 'divi';
	}

	/**
	 * La page est prise en charge par le constructeur (codes courts présents).
	 *
	 * @param int $post_id Identifiant.
	 */
	public static function supports( int $post_id ): bool {
		return array() !== self::tree( $post_id );
	}

	/**
	 * Arbre de la page : codes courts du contenu, ou de la copie d'avant quand la page est déjà
	 * convertie.
	 *
	 * @param int $post_id Identifiant.
	 * @return list<Element>
	 */
	public static function tree( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$backup  = get_post_meta( $post_id, Migrator::META_BACKUP, true );
		$content = is_array( $backup ) && is_string( $backup['content'] ?? null ) && self::id() === ( $backup['builder'] ?? '' ) ? $backup['content'] : $post->post_content;
		return self::parse( $content );
	}

	/**
	 * Codes courts imbriqués d'un texte, en arbre. Les codes de même nom ne s'imbriquent jamais
	 * dans ce constructeur (rangée intérieure, colonne intérieure), la première fermeture est donc
	 * la bonne.
	 *
	 * @param string $text Texte.
	 * @return list<Element>
	 */
	public static function parse( string $text ): array {
		$out    = array();
		$offset = 0;
		while ( 1 === preg_match( '/\[(' . self::PREFIX . '[a-z0-9_]+)(?:\s[^\]]*?)?\/?\]/', $text, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$tag     = $m[1][0];
			$after   = (int) $m[0][1] + strlen( $m[0][0] );
			$self    = str_ends_with( $m[0][0], '/]' );
			$content = '';
			$end     = $after;
			if ( ! $self ) {
				$close = strpos( $text, '[/' . $tag . ']', $after );
				if ( false !== $close ) {
					$content = substr( $text, $after, $close - $after );
					$end     = $close + strlen( '[/' . $tag . ']' );
				}
			}
			$attrs    = array();
			$raw_atts = trim( substr( $m[0][0], strlen( $tag ) + 1, $self ? -2 : -1 ) );
			$parsed   = '' === $raw_atts ? array() : shortcode_parse_atts( $raw_atts );
			foreach ( $parsed as $key => $value ) {
				if ( is_string( $key ) && is_string( $value ) ) {
					$attrs[ strtolower( $key ) ] = self::decode( $value );
				}
			}
			$nested = str_contains( $content, '[' . self::PREFIX );
			$out[]  = array(
				'tag'      => substr( $tag, strlen( self::PREFIX ) ),
				'attrs'    => $attrs,
				'content'  => $nested ? '' : trim( $content ),
				'children' => $nested ? self::parse( $content ) : array(),
			);
			$offset = $end;
		}
		return $out;
	}

	/**
	 * Nombre d'éléments par sorte (nom du code court sans préfixe), enfants compris.
	 *
	 * @param array<int, mixed> $tree Arbre.
	 * @return array<string, int>
	 */
	public static function count( array $tree ): array {
		$counts = array();
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$kind            = is_string( $element['tag'] ?? null ) && '' !== $element['tag'] ? $element['tag'] : 'unknown';
			$counts[ $kind ] = ( $counts[ $kind ] ?? 0 ) + 1;
			if ( is_array( $element['children'] ?? null ) ) {
				foreach ( self::count( $element['children'] ) as $child => $n ) {
					$counts[ $child ] = ( $counts[ $child ] ?? 0 ) + $n;
				}
			}
		}
		return $counts;
	}

	/**
	 * Conversion de l'arbre en blocs.
	 *
	 * @param array<int, mixed> $tree        Arbre.
	 * @param bool              $keep_colors Garder les couleurs fixes.
	 * @param Report            $report      Rapport à remplir.
	 * @return list<Block>
	 */
	public static function convert( array $tree, bool $keep_colors, Report $report ): array {
		$blocks = array();
		foreach ( $tree as $element ) {
			if ( is_array( $element ) ) {
				foreach ( self::element( $element, self::TOP, $keep_colors, $report ) as $block ) {
					$blocks[] = $block;
				}
			}
		}
		$report->count_blocks( $blocks );
		return $blocks;
	}

	/**
	 * Gabarits du constructeur de thème (en-têtes, corps, pieds) : sorte => titres. Les mises en
	 * page enregistrées s'insèrent dans les pages et ne sont pas listées.
	 *
	 * @return array<string, list<string>>
	 */
	public static function templates(): array {
		$out = array();
		foreach ( self::THEME_TYPES as $type => $kind ) {
			$ids = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page'   => Migrator::LIMIT,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => false,
				)
			);
			foreach ( $ids as $id ) {
				$title          = get_the_title( (int) $id );
				$out[ $kind ][] = '' === $title ? (string) $id : $title;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Réglages globaux du thème : couleurs par indice (text, primary, link, heading, button,
	 * button-text, puis les couleurs globales par identifiant) et familles (body, primary).
	 *
	 * @return array{colors: array<string, string>, families: array<string, string>}
	 */
	public static function globals(): array {
		$out   = array(
			'colors'   => array(),
			'families' => array(),
		);
		$theme = get_option( self::OPTION_THEME );
		$theme = is_array( $theme ) ? $theme : array();
		$keys  = array(
			'font_color'             => 'text',
			'accent_color'           => 'primary',
			'link_color'             => 'link',
			'header_color'           => 'heading',
			'all_buttons_bg_color'   => 'button',
			'all_buttons_text_color' => 'button-text',
		);
		foreach ( $keys as $key => $hint ) {
			$hex = self::hex( $theme[ $key ] ?? null );
			if ( null !== $hex ) {
				$out['colors'][ $hint ] = $hex;
			}
		}
		$globals = get_option( self::OPTION_COLORS );
		foreach ( is_array( $globals ) ? $globals : array() as $id => $row ) {
			$hex = is_array( $row ) ? self::hex( $row['color'] ?? null ) : null;
			$id  = is_string( $id ) ? (string) preg_replace( '/^gcid-/', '', $id ) : '';
			if ( null !== $hex && '' !== $id && ! isset( $out['colors'][ $id ] ) && 'no' !== ( $row['active'] ?? 'yes' ) ) {
				$out['colors'][ $id ] = $hex;
			}
		}
		foreach ( array(
			'body_font'    => 'body',
			'heading_font' => 'primary',
		) as $key => $hint ) {
			$family = trim( self::string( $theme[ $key ] ?? null ) );
			if ( '' !== $family && ! in_array( strtolower( $family ), array( 'none', 'default' ), true ) ) {
				$out['families'][ $hint ] = $family;
			}
		}
		return $out;
	}

	/**
	 * Clause de repérage : le marqueur du constructeur existe.
	 *
	 * @return array<string, string>
	 */
	public static function clause(): array {
		return array(
			'key'     => self::META_MODE,
			'compare' => 'EXISTS',
		);
	}

	/**
	 * Marqueur de prise en charge à garder avant la conversion.
	 *
	 * @param int $post_id Identifiant.
	 */
	public static function mode( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_MODE, true );
	}

	/**
	 * La page cesse d'être rendue par le constructeur : marqueur à « off ».
	 *
	 * @param int $post_id Identifiant.
	 */
	public static function detach( int $post_id ): void {
		update_post_meta( $post_id, self::META_MODE, 'off' );
	}

	/**
	 * Le marqueur d'avant est remis.
	 *
	 * @param int    $post_id Identifiant.
	 * @param string $mode    Marqueur gardé.
	 */
	public static function reattach( int $post_id, string $mode ): void {
		if ( '' !== $mode ) {
			update_post_meta( $post_id, self::META_MODE, $mode );
		}
	}

	/**
	 * Valeur d'attribut décodée (le constructeur encode guillemets, crochets et barre oblique).
	 *
	 * @param string $value Valeur brute.
	 */
	private static function decode( string $value ): string {
		return str_replace( array( '%22', '%91', '%93', '%92' ), array( '"', '[', ']', '\\' ), $value );
	}

	/**
	 * Un élément en blocs.
	 *
	 * @param array<string, mixed> $element Élément.
	 * @param string               $context Contexte (top, inner).
	 * @param bool                 $keep    Garder les couleurs fixes.
	 * @param Report               $report  Rapport.
	 * @return list<Block>
	 */
	private static function element( array $element, string $context, bool $keep, Report $report ): array {
		$tag   = is_string( $element['tag'] ?? null ) ? $element['tag'] : '';
		$attrs = array();
		foreach ( is_array( $element['attrs'] ?? null ) ? $element['attrs'] : array() as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$attrs[ $key ] = $value;
			}
		}
		$content  = is_string( $element['content'] ?? null ) ? $element['content'] : '';
		$children = is_array( $element['children'] ?? null ) ? array_values( array_filter( $element['children'], 'is_array' ) ) : array();
		if ( is_numeric( $attrs['global_module'] ?? null ) && '' === $content && array() === $children ) {
			return self::library( (int) $attrs['global_module'], $context, $keep, $report );
		}
		switch ( $tag ) {
			case 'section':
				return self::section( $attrs, $children, $context, $keep, $report );
			case 'row':
			case 'row_inner':
				return self::row( $attrs, $children, $keep, $report );
			case 'column':
			case 'column_inner':
				return self::children( $children, $keep, $report );
			case '':
				$report->skip( 'unknown' );
				return array();
			default:
				if ( self::has_json_attrs( $attrs ) ) {
					/* translators: %s: module kind (et_pb_ shortcode name without its prefix) */
					$report->todo( sprintf( __( 'The "%s" module uses page builder version 5 values (hover, responsive breakpoints) that this reader does not decode: check it in the editor.', 'fluffless-suite' ), $tag ) );
					$clean = array_filter( $attrs, static fn( string $value ): bool => ! self::is_json( $value ) );
					return self::unknown( $tag, array_merge( $clean, array( 'content' => $content ) ), $report );
				}
				return self::module( $tag, $attrs, $content, $children, $keep, $report );
		}
	}

	/**
	 * Un attribut de l'élément ressemble à une valeur encodée en JSON : le constructeur D en
	 * version 5 encode ainsi les états de survol et les points de rupture, sous les mêmes codes
	 * courts qu'en version 4. Aucun attribut normal de ce constructeur ne commence par une
	 * accolade ; ce repère suffit à distinguer les deux versions sans dépendre d'un numéro de
	 * version qui n'est pas toujours présent dans l'export.
	 *
	 * @param array<string, string> $attrs Attributs de l'élément.
	 */
	private static function has_json_attrs( array $attrs ): bool {
		foreach ( $attrs as $value ) {
			if ( self::is_json( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enfants convertis à la suite.
	 *
	 * @param array<int, array<string, mixed>> $children Enfants.
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @return list<Block>
	 */
	private static function children( array $children, bool $keep, Report $report ): array {
		$blocks = array();
		foreach ( $children as $child ) {
			foreach ( self::element( $child, self::INNER, $keep, $report ) as $block ) {
				$blocks[] = $block;
			}
		}
		return $blocks;
	}

	/**
	 * Section : un groupe avec ancre, pleine largeur et fond ; ses rangées ou, pour une section
	 * spéciale ou pleine largeur, ses colonnes et modules directement.
	 *
	 * @param array<string, string>            $attrs    Attributs.
	 * @param array<int, array<string, mixed>> $children Enfants.
	 * @param string                           $context  Contexte.
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @return list<Block>
	 */
	private static function section( array $attrs, array $children, string $context, bool $keep, Report $report ): array {
		$columns = array();
		$widths  = array();
		foreach ( $children as $child ) {
			$tag = is_string( $child['tag'] ?? null ) ? $child['tag'] : '';
			if ( 'column' !== $tag ) {
				$columns = array();
				break;
			}
			$columns[] = self::element( $child, self::INNER, $keep, $report );
			$widths[]  = self::fraction( is_array( $child['attrs'] ?? null ) ? $child['attrs']['type'] ?? null : null );
		}
		if ( count( $columns ) > 1 ) {
			if ( count( array_unique( $widths ) ) === 1 ) {
				$widths = array_fill( 0, count( $widths ), null );
			}
			$inner = array( Blocks::columns( $columns, $widths ) );
		} else {
			$inner = self::children( $children, $keep, $report );
		}
		$background = 'off' === ( $attrs['background_enable_color'] ?? 'on' ) ? null : self::colour( $attrs['background_color'] ?? null, $keep, $report );
		$anchor     = self::anchor( $attrs['module_id'] ?? null );
		if ( self::TOP !== $context && null === $anchor && null === $background ) {
			return $inner;
		}
		$fullwidth = $attrs['fullwidth'] ?? '';
		return array( Blocks::group( $inner, $anchor, 'on' === $fullwidth, $background ) );
	}

	/**
	 * Rangée : colonnes avec leurs largeurs ; une colonne seule est aplatie ; une ancre pose un
	 * groupe.
	 *
	 * @param array<string, string>            $attrs    Attributs.
	 * @param array<int, array<string, mixed>> $children Colonnes.
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @return list<Block>
	 */
	private static function row( array $attrs, array $children, bool $keep, Report $report ): array {
		$columns = array();
		$widths  = array();
		foreach ( $children as $child ) {
			$columns[] = self::element( $child, self::INNER, $keep, $report );
			$widths[]  = self::fraction( is_array( $child['attrs'] ?? null ) ? $child['attrs']['type'] ?? null : null );
		}
		if ( count( $columns ) > 1 && count( array_unique( $widths ) ) === 1 ) {
			$widths = array_fill( 0, count( $widths ), null );
		}
		$inner      = 1 === count( $columns ) ? $columns[0] : ( array() === $columns ? array() : array( Blocks::columns( $columns, $widths ) ) );
		$anchor     = self::anchor( $attrs['module_id'] ?? null );
		$background = 'off' === ( $attrs['background_enable_color'] ?? 'on' ) ? null : self::colour( $attrs['background_color'] ?? null, $keep, $report );
		if ( null === $anchor && null === $background ) {
			return $inner;
		}
		return array( Blocks::group( $inner, $anchor, false, $background ) );
	}

	/**
	 * Un module en blocs.
	 *
	 * @param string                           $kind     Sorte (nom du code court sans préfixe).
	 * @param array<string, string>            $attrs    Attributs.
	 * @param string                           $content  Contenu HTML.
	 * @param array<int, array<string, mixed>> $children Enfants (éléments d'accordéon, onglets, diapositives…).
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @return list<Block>
	 */
	private static function module( string $kind, array $attrs, string $content, array $children, bool $keep, Report $report ): array {
		$align = self::align( str_replace( 'justified', '', $attrs['text_orientation'] ?? '' ) );
		switch ( $kind ) {
			case 'text':
				self::colour( $attrs['background_color'] ?? null, $keep, $report );
				return Blocks::rich( $content, self::colour( $attrs['text_text_color'] ?? null, $keep, $report ) );
			case 'image':
			case 'fullwidth_image':
				$url = $attrs['src'] ?? '';
				if ( '' === $url ) {
					return self::skipped( $kind, $report );
				}
				$id   = self::attachment( array( 'url' => $url ), $report );
				$alt  = $attrs['alt'] ?? '';
				$alt  = '' === $alt && $id > 0 ? self::string( get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : $alt;
				$link = $attrs['url'] ?? '';
				$link = '' === $link && 'on' === ( $attrs['show_in_lightbox'] ?? '' ) ? $url : $link;
				return array( Blocks::image( $url, $id, $alt, '', $link, self::align( $attrs['align'] ?? null ) ) );
			case 'button':
				$text = $attrs['button_text'] ?? '';
				if ( '' === $text ) {
					return self::skipped( $kind, $report );
				}
				if ( 'on' === ( $attrs['custom_button'] ?? '' ) ) {
					self::colour( $attrs['button_bg_color'] ?? null, $keep, $report );
					self::colour( $attrs['button_text_color'] ?? null, $keep, $report );
				}
				return array( Blocks::buttons( array( self::button( $text, $attrs['button_url'] ?? '', $attrs['url_new_window'] ?? '' ) ), self::align( $attrs['button_alignment'] ?? null ) ) );
			case 'blurb':
				$blocks = array();
				$image  = $attrs['image'] ?? '';
				if ( '' !== $image ) {
					$blocks[] = Blocks::image( $image, self::attachment( array( 'url' => $image ), $report ), $attrs['alt'] ?? '' );
				} elseif ( 'on' === ( $attrs['use_icon'] ?? '' ) ) {
					$report->keep( 'icon' );
				}
				$title = $attrs['title'] ?? '';
				$href  = $attrs['url'] ?? '';
				if ( '' !== $title ) {
					$html     = esc_html( $title );
					$blocks[] = Blocks::heading( 3, '' !== $href ? '<a href="' . esc_url( $href ) . '">' . $html . '</a>' : $html );
				}
				$blocks = array_merge( $blocks, Blocks::rich( $content ) );
				self::colour( $attrs['background_color'] ?? null, $keep, $report );
				return array() === $blocks ? self::skipped( $kind, $report ) : $blocks;
			case 'cta':
				$blocks = array();
				$title  = $attrs['title'] ?? '';
				if ( '' !== $title ) {
					$blocks[] = Blocks::heading( 3, esc_html( $title ), $align );
				}
				$blocks = array_merge( $blocks, Blocks::rich( $content ) );
				$text   = $attrs['button_text'] ?? '';
				if ( '' !== $text ) {
					$blocks[] = Blocks::buttons( array( self::button( $text, $attrs['button_url'] ?? '', $attrs['url_new_window'] ?? '' ) ), $align );
				}
				if ( array() === $blocks ) {
					return self::skipped( $kind, $report );
				}
				return array( Blocks::group( $blocks, null, false, self::colour( $attrs['background_color'] ?? null, $keep, $report ) ) );
			case 'testimonial':
				$quote = Blocks::rich( $content );
				if ( array() === $quote ) {
					return self::skipped( $kind, $report );
				}
				$parts = array_filter( array( $attrs['author'] ?? '', $attrs['job_title'] ?? '', $attrs['company_name'] ?? '' ) );
				return array( Blocks::quote( $quote, esc_html( implode( ', ', $parts ) ) ) );
			case 'toggle':
				$title = esc_html( $attrs['title'] ?? '' );
				$inner = Blocks::rich( $content );
				if ( '' === $title && array() === $inner ) {
					return self::skipped( $kind, $report );
				}
				return array( Blocks::details( '' === $title ? '…' : $title, $inner ) );
			case 'accordion':
			case 'tabs':
				$blocks = array();
				foreach ( $children as $child ) {
					$child_attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
					$title       = esc_html( self::string( $child_attrs['title'] ?? null ) );
					$inner       = Blocks::rich( self::string( $child['content'] ?? null ) );
					if ( '' === $title && array() === $inner ) {
						continue;
					}
					$blocks[] = Blocks::details( '' === $title ? '…' : $title, $inner );
				}
				return array() === $blocks ? self::skipped( $kind, $report ) : $blocks;
			case 'divider':
				if ( 'off' === ( $attrs['show_divider'] ?? 'on' ) ) {
					return array( Blocks::spacer( is_numeric( $attrs['height'] ?? null ) ? (int) $attrs['height'] : 23 ) );
				}
				return array( Blocks::separator() );
			case 'video':
				$url = $attrs['src'] ?? '';
				if ( '' === $url ) {
					return self::skipped( $kind, $report );
				}
				return array( 1 === preg_match( '#^https?://([a-z0-9-]+\.)?(youtube\.com|youtu\.be|vimeo\.com|dailymotion\.com)/#i', $url ) ? Blocks::embed( $url ) : Blocks::video( $url ) );
			case 'audio':
				$url = $attrs['audio'] ?? '';
				if ( '' === $url ) {
					return self::skipped( $kind, $report );
				}
				$label    = trim( implode( ' · ', array_filter( array( $attrs['title'] ?? '', $attrs['artist'] ?? '' ) ) ) );
				$blocks   = '' === $label ? array() : array( Blocks::paragraph( '<strong>' . esc_html( $label ) . '</strong>' ) );
				$blocks[] = Blocks::audio( $url );
				return $blocks;
			case 'gallery':
				$images = array();
				foreach ( array_filter( array_map( 'intval', explode( ',', $attrs['gallery_ids'] ?? '' ) ) ) as $id ) {
					$url = (string) wp_get_attachment_url( $id );
					if ( '' !== $url ) {
						$images[] = array(
							'url' => $url,
							'id'  => $id,
							'alt' => self::string( get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
						);
					}
				}
				if ( array() === $images ) {
					return self::skipped( $kind, $report );
				}
				$fullwidth = $attrs['fullwidth'] ?? '';
				return array( Blocks::gallery( $images, 'on' === $fullwidth ? 1 : 3 ) );
			case 'number_counter':
			case 'circle_counter':
				$number = $attrs['number'] ?? '';
				$sign   = 'circle_counter' === $kind || 'off' !== ( $attrs['percent_sign'] ?? 'on' );
				$html   = '' === $number ? '' : esc_html( $number . ( $sign ? "\u{00A0}%" : '' ) );
				$out    = '' === $html ? array() : array( Blocks::heading( 3, $html, $align ) );
				return array_merge( $out, Blocks::text( $attrs['title'] ?? '' ) );
			case 'bar_counters':
				$items = array();
				foreach ( $children as $child ) {
					$child_attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
					$label       = self::string( $child['content'] ?? null );
					$percent     = self::string( $child_attrs['percent'] ?? null );
					if ( '' !== $label ) {
						$items[] = esc_html( $label . ( '' !== $percent ? "\u{00A0}: " . $percent . "\u{00A0}%" : '' ) );
					}
				}
				return array() === $items ? self::skipped( $kind, $report ) : array( Blocks::listing( $items ) );
			case 'pricing_tables':
				return self::pricing( $children, $keep, $report );
			case 'contact_form':
				$labels = array();
				foreach ( $children as $child ) {
					$child_attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
					$label       = self::string( $child_attrs['field_title'] ?? null );
					$type        = self::string( $child_attrs['field_type'] ?? null );
					if ( '' !== $label ) {
						$labels[] = $label . ( '' !== $type && 'input' !== $type ? ' (' . $type . ')' : '' );
					}
				}
				return self::form_todo( $attrs['title'] ?? '', $labels, $report );
			case 'code':
			case 'fullwidth_code':
				$html = trim( self::decode( $content ) );
				return '' === $html ? array() : array( Blocks::html( $html ) );
			case 'slider':
			case 'fullwidth_slider':
				return self::slider( $children, $keep, $report );
			case 'blog':
			case 'post_slider':
			case 'fullwidth_post_slider':
			case 'portfolio':
			case 'fullwidth_portfolio':
			case 'filterable_portfolio':
				$per_page = is_numeric( $attrs['posts_number'] ?? null ) ? (int) $attrs['posts_number'] : 10;
				$type     = str_contains( $kind, 'portfolio' ) ? 'project' : ( $attrs['post_type'] ?? 'post' );
				$type     = post_type_exists( $type ) ? $type : 'post';
				$columns  = 'blog' === $kind ? ( 'on' === ( $attrs['fullwidth'] ?? '' ) ? 1 : 3 ) : ( str_contains( $kind, 'slider' ) ? 1 : 4 );
				$report->todo( __( 'A list of posts was turned into a simple Query block: check its filters, order and look in the editor.', 'fluffless-suite' ) );
				return array( Blocks::query( $per_page, $type, $columns ) );
			case 'social_media_follow':
				$links = array();
				foreach ( $children as $child ) {
					$child_attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
					$url         = self::string( $child_attrs['url'] ?? null );
					$serv        = self::service( self::string( $child_attrs['social_network'] ?? null ), $url );
					if ( '' !== $url && '' !== $serv ) {
						$links[] = array(
							'url'     => $url,
							'service' => $serv,
						);
					}
				}
				return array() === $links ? self::skipped( $kind, $report ) : array( Blocks::social( $links ) );
			case 'fullwidth_header':
				$blocks = array();
				$title  = $attrs['title'] ?? '';
				if ( '' !== $title ) {
					$blocks[] = Blocks::heading( 1, esc_html( $title ), $align );
				}
				$sub = $attrs['subhead'] ?? '';
				if ( '' !== $sub ) {
					$blocks[] = Blocks::paragraph( esc_html( $sub ), $align );
				}
				$blocks = array_merge( $blocks, Blocks::rich( $content ) );
				$image  = $attrs['header_image_url'] ?? '';
				if ( '' !== $image ) {
					$blocks[] = Blocks::image( $image, self::attachment( array( 'url' => $image ), $report ) );
				}
				$buttons = array();
				foreach ( array( 'one', 'two' ) as $n ) {
					$text = $attrs[ 'button_' . $n . '_text' ] ?? '';
					if ( '' !== $text ) {
						$buttons[] = self::button( $text, $attrs[ 'button_' . $n . '_url' ] ?? '', $attrs[ 'button_' . $n . '_url_new_window' ] ?? '' );
					}
				}
				if ( array() !== $buttons ) {
					$blocks[] = Blocks::buttons( $buttons, $align );
				}
				if ( array() === $blocks ) {
					return self::skipped( $kind, $report );
				}
				return array( Blocks::group( $blocks, self::anchor( $attrs['module_id'] ?? null ), true, self::colour( $attrs['background_color'] ?? null, $keep, $report ) ) );
			case 'team_member':
			case 'person':
				$blocks = array();
				$image  = $attrs['image_url'] ?? '';
				if ( '' !== $image ) {
					$blocks[] = Blocks::image( $image, self::attachment( array( 'url' => $image ), $report ), $attrs['name'] ?? '' );
				}
				$name = $attrs['name'] ?? '';
				if ( '' !== $name ) {
					$blocks[] = Blocks::heading( 3, esc_html( $name ) );
				}
				$position = $attrs['position'] ?? '';
				if ( '' !== $position ) {
					$blocks[] = Blocks::paragraph( '<em>' . esc_html( $position ) . '</em>' );
				}
				$blocks = array_merge( $blocks, Blocks::rich( $content ) );
				$links  = array();
				foreach ( array( 'facebook', 'twitter', 'linkedin', 'instagram' ) as $network ) {
					$url = $attrs[ $network . '_url' ] ?? '';
					if ( '' !== $url ) {
						$links[] = array(
							'url'     => $url,
							'service' => self::service( $network, $url ),
						);
					}
				}
				if ( array() !== $links ) {
					$blocks[] = Blocks::social( $links );
				}
				return array() === $blocks ? self::skipped( $kind, $report ) : $blocks;
			case 'post_title':
			case 'fullwidth_post_title':
			case 'sidebar':
			case 'comments':
			case 'post_nav':
				return self::skipped( $kind, $report );
			case 'map':
			case 'fullwidth_map':
				$attrs['pins'] = array();
				foreach ( $children as $child ) {
					$attrs['pins'][] = array_merge( is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array(), array( 'content' => self::string( $child['content'] ?? null ) ) );
				}
				return self::listed( $kind, $attrs, $report );
			case 'search':
			case 'login':
			case 'signup':
			case 'menu':
			case 'fullwidth_menu':
			case 'shop':
			case 'icon':
			case 'video_slider':
			case 'countdown_timer':
				return self::listed( $kind, array_merge( $attrs, array( 'content' => $content ) ), $report );
			default:
				if ( str_starts_with( $kind, 'wc_' ) ) {
					return self::listed( $kind, $attrs, $report );
				}
				return self::unknown( $kind, array_merge( $attrs, array( 'content' => $content ) ), $report );
		}
	}

	/**
	 * Un bouton pour la fabrique.
	 *
	 * @param string $text       Libellé.
	 * @param string $url        Adresse.
	 * @param string $new_window « on » pour un nouvel onglet.
	 * @return array{text: string, url: string, external: bool}
	 */
	private static function button( string $text, string $url, string $new_window ): array {
		return array(
			'text'     => $text,
			'url'      => $url,
			'external' => 'on' === $new_window,
		);
	}

	/**
	 * Tableaux de prix : un groupe par tableau (titre, sous-titre, prix, liste, bouton), côte à
	 * côte quand il y en a plusieurs.
	 *
	 * @param array<int, array<string, mixed>> $children Tableaux.
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @return list<Block>
	 */
	private static function pricing( array $children, bool $keep, Report $report ): array {
		$tables = array();
		foreach ( $children as $child ) {
			$attrs  = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
			$blocks = array();
			$title  = self::string( $attrs['title'] ?? null );
			if ( '' !== $title ) {
				$blocks[] = Blocks::heading( 3, esc_html( $title ) );
			}
			$sub = self::string( $attrs['subtitle'] ?? null );
			if ( '' !== $sub ) {
				$blocks[] = Blocks::paragraph( esc_html( $sub ) );
			}
			$sum = self::string( $attrs['sum'] ?? null );
			if ( '' !== $sum ) {
				$currency = self::string( $attrs['currency'] ?? null );
				$per      = self::string( $attrs['per'] ?? null );
				$amount   = '$' === $currency || '' === $currency ? $sum . ( '' !== $currency ? "\u{00A0}" . $currency : '' ) : $currency . $sum;
				$blocks[] = Blocks::paragraph( '<strong>' . esc_html( $amount ) . '</strong>' . ( '' !== $per ? ' ' . esc_html( $per ) : '' ) );
			}
			$items = array();
			$lines = preg_split( '/\R/', wp_strip_all_tags( self::string( $child['content'] ?? null ) ) );
			foreach ( false === $lines ? array() : $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				if ( str_starts_with( $line, '-' ) ) {
					$items[] = '<s>' . esc_html( trim( substr( $line, 1 ) ) ) . '</s>';
				} else {
					$items[] = esc_html( str_starts_with( $line, '+' ) ? trim( substr( $line, 1 ) ) : $line );
				}
			}
			if ( array() !== $items ) {
				$blocks[] = Blocks::listing( $items );
			}
			$text = self::string( $attrs['button_text'] ?? null );
			if ( '' !== $text ) {
				$blocks[] = Blocks::buttons( array( self::button( $text, self::string( $attrs['button_url'] ?? null ), self::string( $attrs['url_new_window'] ?? null ) ) ) );
			}
			self::colour( $attrs['header_background_color'] ?? null, $keep, $report );
			if ( array() !== $blocks ) {
				$tables[] = array( Blocks::group( $blocks ) );
			}
		}
		if ( array() === $tables ) {
			return self::skipped( 'pricing_tables', $report );
		}
		return 1 === count( $tables ) ? $tables[0] : array( Blocks::columns( $tables ) );
	}

	/**
	 * Diaporama : la première diapositive devient une section, les autres sont listées à refaire.
	 *
	 * @param array<int, array<string, mixed>> $children Diapositives.
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @return list<Block>
	 */
	private static function slider( array $children, bool $keep, Report $report ): array {
		if ( array() === $children ) {
			return self::skipped( 'slider', $report );
		}
		$first  = is_array( $children[0]['attrs'] ?? null ) ? $children[0]['attrs'] : array();
		$blocks = array();
		$image  = self::string( $first['image'] ?? $first['background_image'] ?? null );
		if ( '' !== $image ) {
			$blocks[] = Blocks::image( $image, self::attachment( array( 'url' => $image ), $report ) );
		}
		$heading = self::string( $first['heading'] ?? null );
		if ( '' !== $heading ) {
			$blocks[] = Blocks::heading( 2, esc_html( $heading ) );
		}
		$blocks = array_merge( $blocks, Blocks::rich( self::string( $children[0]['content'] ?? null ) ) );
		$text   = self::string( $first['button_text'] ?? null );
		if ( '' !== $text ) {
			$blocks[] = Blocks::buttons( array( self::button( $text, self::string( $first['button_link'] ?? null ), self::string( $first['url_new_window'] ?? null ) ) ) );
		}
		$others = array();
		foreach ( array_slice( $children, 1 ) as $slide ) {
			$attrs    = is_array( $slide['attrs'] ?? null ) ? $slide['attrs'] : array();
			$label    = trim( self::string( $attrs['heading'] ?? null ) );
			$others[] = '' === $label ? '…' : $label;
		}
		if ( array() !== $others ) {
			/* translators: 1: number of slides, 2: titles of the other slides */
			$report->todo( sprintf( __( 'Slideshow of %1$d slides: only the first one is kept as a section. Other slides: %2$s.', 'fluffless-suite' ), count( $children ), implode( ', ', $others ) ) );
		}
		if ( array() === $blocks ) {
			return self::skipped( 'slider', $report );
		}
		return array( Blocks::group( $blocks, null, false, self::colour( $first['background_color'] ?? null, $keep, $report ) ) );
	}

	/**
	 * Module global : le contenu de la mise en page enregistrée est converti sur place.
	 *
	 * @param int    $post_id Identifiant de la mise en page.
	 * @param string $context Contexte.
	 * @param bool   $keep    Garder les couleurs fixes.
	 * @param Report $report  Rapport.
	 * @return list<Block>
	 */
	private static function library( int $post_id, string $context, bool $keep, Report $report ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || self::TYPE_LIBRARY !== $post->post_type ) {
			/* translators: %d: template id */
			$report->todo( sprintf( __( 'A saved template of the page builder (%d) no longer exists: nothing was kept from it.', 'fluffless-suite' ), $post_id ) );
			return array();
		}
		if ( self::$depth >= 3 ) {
			return self::skipped( 'template', $report );
		}
		++self::$depth;
		try {
			$blocks = array();
			foreach ( self::parse( $post->post_content ) as $element ) {
				foreach ( self::element( $element, $context, $keep, $report ) as $block ) {
					$blocks[] = $block;
				}
			}
			return $blocks;
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Couleur HEX (trois ou six chiffres) gardée ou écartée et comptée.
	 *
	 * @param mixed  $raw    Valeur brute.
	 * @param bool   $keep   Garder.
	 * @param Report $report Rapport.
	 */
	private static function colour( $raw, bool $keep, Report $report ): ?string {
		return self::color( self::hex( $raw ), $keep, $report );
	}

	/**
	 * Largeur d'une colonne depuis sa fraction (« 1_3 ») en pourcentage, ou null.
	 *
	 * @param mixed $raw Valeur brute.
	 */
	private static function fraction( $raw ): ?string {
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^(\d+)_(\d+)$/', $raw, $m ) || 0 === (int) $m[2] ) {
			return null;
		}
		return self::percent( (int) $m[1] * 100 / (int) $m[2] );
	}
}
