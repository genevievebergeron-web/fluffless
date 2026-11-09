<?php
/**
 * Lecteur du constructeur E : l'arbre JSON rangé dans la métadonnée _elementor_data devient une
 * liste de blocs natifs. Structure (section, colonne, conteneur) en groupes et colonnes, gadgets
 * en blocs du cœur, gadget inconnu gardé en texte et compté au rapport.
 *
 * Le nom du constructeur reste un identifiant technique : aucune chaîne affichée ne le nomme.
 *
 * @package Fluffless\Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Conversion de l'arbre du constructeur E.
 *
 * @phpstan-import-type Block from Blocks
 * @phpstan-type Element array<string, mixed>
 */
final class Elementor extends Reader {

	public const META_DATA = '_elementor_data';
	public const META_MODE = '_elementor_edit_mode';

	/** Type de contenu des gabarits enregistrés (en-têtes, pieds, fenêtres surgissantes, sections). */
	public const TYPE_LIBRARY = 'elementor_library';

	/** Sorte d'un gabarit enregistré. */
	public const META_TEMPLATE_TYPE = '_elementor_template_type';

	/** Option qui pointe le kit actif (réglages globaux). */
	public const OPTION_KIT = 'elementor_active_kit';

	/** Réglages du kit, sur le contenu du kit. */
	public const META_KIT = '_elementor_page_settings';

	/** Sortes de gabarits insérés dans les pages, convertis sur place et non listés. */
	private const INSERTED = array( 'page', 'section', 'container', 'widget' );

	/** Monnaies du tableau de prix. */
	private const CURRENCIES = array(
		'dollar' => '$',
		'euro'   => '€',
		'pound'  => '£',
		'yen'    => '¥',
		'franc'  => '₣',
		'rupee'  => '₹',
		'real'   => 'R$',
		'krona'  => 'kr',
		'won'    => '₩',
		'peso'   => '₱',
		'ruble'  => '₽',
		'shekel' => '₪',
		'lira'   => '₺',
		'baht'   => '฿',
	);

	/**
	 * Profondeur des gabarits insérés en cours de conversion (garde contre les boucles).
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/** Contexte : élément de premier niveau. */
	private const TOP = 'top';

	/** Contexte : enfant d'une rangée, la colonne est posée par l'appelant. */
	private const ROW = 'row';

	/** Contexte : enfant d'une colonne. */
	private const COLUMN = 'column';

	/** Identifiant du lecteur. */
	public static function id(): string {
		return 'elementor';
	}

	/**
	 * La page est prise en charge par le constructeur (données présentes).
	 *
	 * @param int $post_id Identifiant.
	 */
	public static function supports( int $post_id ): bool {
		return array() !== self::tree( $post_id );
	}

	/**
	 * Clause de repérage : la métadonnée des données existe.
	 *
	 * @return array<string, string>
	 */
	public static function clause(): array {
		return array(
			'key'     => self::META_DATA,
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
	 * La page cesse d'être rendue par le constructeur.
	 *
	 * @param int $post_id Identifiant.
	 */
	public static function detach( int $post_id ): void {
		delete_post_meta( $post_id, self::META_MODE );
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
	 * Arbre de la page, vide si absent ou illisible.
	 *
	 * @param int $post_id Identifiant.
	 * @return list<Element>
	 */
	public static function tree( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_DATA, true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$out = array();
		foreach ( $data as $element ) {
			if ( is_array( $element ) && is_string( $element['elType'] ?? null ) ) {
				$out[] = $element;
			}
		}
		return $out;
	}

	/**
	 * Nombre d'éléments par sorte (widgetType pour un gadget, elType sinon), enfants compris.
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
			$kind            = self::kind( $element );
			$counts[ $kind ] = ( $counts[ $kind ] ?? 0 ) + 1;
			if ( is_array( $element['elements'] ?? null ) ) {
				foreach ( self::count( $element['elements'] ) as $child => $n ) {
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
	 * Sorte d'un élément.
	 *
	 * @param array<string, mixed> $element Élément.
	 * @phpstan-param Element $element
	 */
	private static function kind( array $element ): string {
		$type = is_string( $element['elType'] ?? null ) ? $element['elType'] : '';
		if ( 'widget' === $type ) {
			$widget = $element['widgetType'] ?? '';
			return is_string( $widget ) && '' !== $widget ? $widget : 'widget';
		}
		return '' === $type ? 'unknown' : $type;
	}

	/**
	 * Un élément en blocs.
	 *
	 * @param array<string, mixed> $element Élément.
	 * @phpstan-param Element $element
	 * @param string               $context Contexte (top, row, column).
	 * @param bool                 $keep    Garder les couleurs fixes.
	 * @param Report               $report  Rapport.
	 * @return list<Block>
	 */
	private static function element( array $element, string $context, bool $keep, Report $report ): array {
		$type     = is_string( $element['elType'] ?? null ) ? $element['elType'] : '';
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$children = is_array( $element['elements'] ?? null ) ? array_values( array_filter( $element['elements'], 'is_array' ) ) : array();
		switch ( $type ) {
			case 'section':
				return self::section( $settings, $children, $context, $keep, $report );
			case 'column':
				return self::children( $children, self::COLUMN, $keep, $report );
			case 'container':
				return self::container( $settings, $children, $context, $keep, $report );
			case 'widget':
				if ( is_numeric( $element['templateID'] ?? null ) ) {
					return self::library( (int) $element['templateID'], $context, $keep, $report );
				}
				return self::widget( self::kind( $element ), $settings, $children, $keep, $report );
			default:
				$report->skip( '' === $type ? 'unknown' : $type );
				return array();
		}
	}

	/**
	 * Enfants convertis à la suite.
	 *
	 * @param array<int, array<string, mixed>> $children Enfants.
	 * @param string                           $context  Contexte.
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @phpstan-param list<Element> $children
	 * @return list<Block>
	 */
	private static function children( array $children, string $context, bool $keep, Report $report ): array {
		$blocks = array();
		foreach ( $children as $child ) {
			foreach ( self::element( $child, $context, $keep, $report ) as $block ) {
				$blocks[] = $block;
			}
		}
		return $blocks;
	}

	/**
	 * Section : une colonne devient un groupe, plusieurs deviennent des colonnes dans un groupe ;
	 * une section intérieure ne pose pas de groupe.
	 *
	 * @param array<string, mixed>             $settings Réglages.
	 * @param array<int, array<string, mixed>> $children Colonnes.
	 * @param string                           $context  Contexte.
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @return list<Block>
	 * @phpstan-param list<Element> $children
	 */
	private static function section( array $settings, array $children, string $context, bool $keep, Report $report ): array {
		$columns = array();
		$widths  = array();
		foreach ( $children as $child ) {
			$columns[] = self::element( $child, self::COLUMN, $keep, $report );
			$size      = is_array( $child['settings'] ?? null ) ? ( $child['settings']['_inline_size'] ?? $child['settings']['_column_size'] ?? null ) : null;
			$widths[]  = self::percent( $size );
		}
		if ( count( $columns ) > 1 && ! in_array( null, $widths, true ) && count( array_unique( $widths ) ) === 1 ) {
			$widths = array_fill( 0, count( $widths ), null );
		}
		$inner = 1 === count( $columns ) ? $columns[0] : ( array() === $columns ? array() : array( Blocks::columns( $columns, $widths ) ) );
		if ( self::TOP !== $context ) {
			return $inner;
		}
		return array( self::group( $inner, $settings, $keep, $report ) );
	}

	/**
	 * Conteneur : en ligne, des colonnes ; en colonne, un groupe (ou le contenu nu dans une rangée).
	 *
	 * @param array<string, mixed>             $settings Réglages.
	 * @param array<int, array<string, mixed>> $children Enfants.
	 * @param string                           $context  Contexte.
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @return list<Block>
	 * @phpstan-param list<Element> $children
	 */
	private static function container( array $settings, array $children, string $context, bool $keep, Report $report ): array {
		$direction = is_string( $settings['flex_direction'] ?? null ) ? $settings['flex_direction'] : 'column';
		if ( str_starts_with( $direction, 'row' ) && count( $children ) > 1 ) {
			$columns = array();
			$widths  = array();
			foreach ( $children as $child ) {
				$columns[] = self::element( $child, self::ROW, $keep, $report );
				$child_set = is_array( $child['settings'] ?? null ) ? $child['settings'] : array();
				$width     = is_array( $child_set['width'] ?? null ) ? $child_set['width'] : null;
				$widths[]  = null !== $width && '%' === ( $width['unit'] ?? '' ) ? self::percent( $width['size'] ?? null ) : null;
			}
			if ( count( array_unique( $widths ) ) === 1 ) {
				$widths = array_fill( 0, count( $widths ), null );
			}
			$inner = array( Blocks::columns( $columns, $widths ) );
		} else {
			$inner = self::children( $children, self::COLUMN, $keep, $report );
		}
		if ( self::ROW === $context || ( self::COLUMN === $context && ! self::framed( $settings ) ) ) {
			return $inner;
		}
		return array( self::group( $inner, $settings, $keep, $report ) );
	}

	/**
	 * Un conteneur imbriqué mérite son propre groupe seulement s'il porte une ancre ou un fond.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 */
	private static function framed( array $settings ): bool {
		return null !== self::anchor( $settings['_element_id'] ?? null ) || 'classic' === ( $settings['background_background'] ?? '' );
	}

	/**
	 * Groupe posé autour d'une section ou d'un conteneur : ancre, pleine largeur, fond.
	 *
	 * @param array<int, array<string, mixed>> $inner    Blocs.
	 * @param array<string, mixed>             $settings Réglages.
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @return Block
	 * @phpstan-param list<Block> $inner
	 */
	private static function group( array $inner, array $settings, bool $keep, Report $report ): array {
		$anchor     = self::anchor( $settings['_element_id'] ?? null );
		$full       = 'full_width' === ( $settings['layout'] ?? '' ) || 'full' === ( $settings['content_width'] ?? '' );
		$background = null;
		if ( 'classic' === ( $settings['background_background'] ?? '' ) ) {
			$background = self::color( $settings['background_color'] ?? null, $keep, $report );
		}
		return Blocks::group( $inner, $anchor, $full, $background );
	}

	/**
	 * Un gadget en blocs.
	 *
	 * @param string                           $kind     Sorte.
	 * @param array<string, mixed>             $settings Réglages.
	 * @param array<int, array<string, mixed>> $children Enfants (onglets et accordéons imbriqués).
	 * @param bool                             $keep     Garder les couleurs fixes.
	 * @param Report                           $report   Rapport.
	 * @return list<Block>
	 * @phpstan-param list<Element> $children
	 */
	private static function widget( string $kind, array $settings, array $children, bool $keep, Report $report ): array {
		$align = self::align( $settings['align'] ?? null );
		switch ( $kind ) {
			case 'heading':
				$title = self::string( $settings['title'] ?? null );
				if ( '' === $title ) {
					$report->skip( $kind );
					return array();
				}
				$size  = self::string( $settings['header_size'] ?? null );
				$color = self::color( $settings['title_color'] ?? null, $keep, $report );
				self::drop_typography( $settings, 'typography_typography', $report );
				$link = is_array( $settings['link'] ?? null ) ? self::string( $settings['link']['url'] ?? null ) : '';
				$html = esc_html( $title );
				if ( '' !== $link ) {
					$html = '<a href="' . esc_url( $link ) . '">' . $html . '</a>';
				}
				if ( 1 === preg_match( '/^h([1-6])$/', $size, $m ) ) {
					return array( Blocks::heading( (int) $m[1], $html, $align, null, $color ) );
				}
				return array( Blocks::paragraph( $html, $align, $color ) );
			case 'text-editor':
				$color = self::color( $settings['text_color'] ?? null, $keep, $report );
				self::drop_typography( $settings, 'typography_typography', $report );
				return Blocks::rich( self::string( $settings['editor'] ?? null ), $color );
			case 'image':
				$image = is_array( $settings['image'] ?? null ) ? $settings['image'] : array();
				$url   = self::string( $image['url'] ?? null );
				if ( '' === $url ) {
					$report->skip( $kind );
					return array();
				}
				$id      = self::attachment( $image, $report );
				$alt     = self::string( $image['alt'] ?? null );
				$alt     = '' === $alt && $id > 0 ? self::string( get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : $alt;
				$caption = 'custom' === ( $settings['caption_source'] ?? 'none' ) ? esc_html( self::string( $settings['caption'] ?? null ) ) : '';
				$link    = '';
				if ( 'custom' === ( $settings['link_to'] ?? 'none' ) && is_array( $settings['link'] ?? null ) ) {
					$link = self::string( $settings['link']['url'] ?? null );
				} elseif ( 'file' === ( $settings['link_to'] ?? 'none' ) ) {
					$link = $url;
				}
				return array( Blocks::image( $url, $id, $alt, $caption, $link, $align ) );
			case 'button':
				$text = self::string( $settings['text'] ?? null );
				if ( '' === $text ) {
					$report->skip( $kind );
					return array();
				}
				$link = is_array( $settings['link'] ?? null ) ? $settings['link'] : array();
				self::color( $settings['background_color'] ?? null, $keep, $report );
				self::color( $settings['button_text_color'] ?? null, $keep, $report );
				return array(
					Blocks::buttons(
						array(
							array(
								'text'     => $text,
								'url'      => self::string( $link['url'] ?? null ),
								'external' => 'on' === ( $link['is_external'] ?? '' ),
							),
						),
						$align
					),
				);
			case 'video':
				return self::video( $settings, $report );
			case 'divider':
				return array( Blocks::separator() );
			case 'spacer':
				$space = is_array( $settings['space'] ?? null ) ? $settings['space'] : array();
				$size  = is_numeric( $space['size'] ?? null ) ? (int) $space['size'] : 50;
				return array( Blocks::spacer( $size ) );
			case 'icon-list':
				$items = array();
				foreach ( self::repeater( $settings['icon_list'] ?? null ) as $item ) {
					$text = esc_html( self::string( $item['text'] ?? null ) );
					$url  = is_array( $item['link'] ?? null ) ? self::string( $item['link']['url'] ?? null ) : '';
					if ( '' === $text ) {
						continue;
					}
					$items[] = '' === $url ? $text : '<a href="' . esc_url( $url ) . '">' . $text . '</a>';
				}
				if ( array() === $items ) {
					$report->skip( $kind );
					return array();
				}
				return array( Blocks::listing( $items ) );
			case 'icon-box':
			case 'image-box':
			case 'call-to-action':
				return self::box( $kind, $settings, $keep, $report );
			case 'testimonial':
				$content = Blocks::rich( self::string( $settings['testimonial_content'] ?? null ) );
				if ( array() === $content ) {
					$report->skip( $kind );
					return array();
				}
				$name = esc_html( self::string( $settings['testimonial_name'] ?? null ) );
				$job  = esc_html( self::string( $settings['testimonial_job'] ?? null ) );
				return array( Blocks::quote( $content, trim( $name . ( '' !== $job ? ', ' . $job : '' ) ) ) );
			case 'tabs':
			case 'accordion':
			case 'toggle':
				$blocks = array();
				foreach ( self::repeater( $settings['tabs'] ?? null ) as $tab ) {
					$title   = esc_html( self::string( $tab['tab_title'] ?? null ) );
					$content = Blocks::rich( self::string( $tab['tab_content'] ?? null ) );
					if ( '' === $title && array() === $content ) {
						continue;
					}
					$blocks[] = Blocks::details( '' === $title ? '…' : $title, $content );
				}
				if ( array() === $blocks ) {
					$report->skip( $kind );
				}
				return $blocks;
			case 'nested-tabs':
			case 'nested-accordion':
				$titles = self::repeater( $settings['tabs'] ?? $settings['items'] ?? null );
				$blocks = array();
				foreach ( $children as $i => $child ) {
					$title   = esc_html( self::string( $titles[ $i ]['tab_title'] ?? $titles[ $i ]['item_title'] ?? null ) );
					$content = self::element( $child, self::ROW, $keep, $report );
					if ( '' === $title && array() === $content ) {
						continue;
					}
					$blocks[] = Blocks::details( '' === $title ? '…' : $title, $content );
				}
				if ( array() === $blocks ) {
					$report->skip( $kind );
				}
				return $blocks;
			case 'alert':
				$title = esc_html( self::string( $settings['alert_title'] ?? null ) );
				$desc  = Blocks::rich( self::string( $settings['alert_description'] ?? null ) );
				$out   = '' === $title ? array() : array( Blocks::paragraph( '<strong>' . $title . '</strong>' ) );
				return array_merge( $out, $desc );
			case 'html':
				$html = trim( self::string( $settings['html'] ?? null ) );
				return '' === $html ? array() : array( Blocks::html( $html ) );
			case 'shortcode':
				$code = trim( self::string( $settings['shortcode'] ?? null ) );
				return '' === $code ? array() : array( Blocks::shortcode( $code ) );
			case 'image-gallery':
			case 'image-carousel':
				$images = array();
				foreach ( self::repeater( $settings[ 'image-gallery' === $kind ? 'wp_gallery' : 'carousel' ] ?? null ) as $item ) {
					$url = self::string( $item['url'] ?? null );
					if ( '' !== $url ) {
						$images[] = array(
							'url' => $url,
							'id'  => self::attachment( $item, $report ),
							'alt' => self::string( $item['alt'] ?? null ),
						);
					}
				}
				if ( array() === $images ) {
					$report->skip( $kind );
					return array();
				}
				$columns = is_numeric( $settings['gallery_columns'] ?? null ) ? (int) $settings['gallery_columns'] : 3;
				return array( Blocks::gallery( $images, $columns ) );
			case 'social-icons':
				$links = array();
				foreach ( self::repeater( $settings['social_icon_list'] ?? null ) as $item ) {
					$url  = is_array( $item['link'] ?? null ) ? self::string( $item['link']['url'] ?? null ) : '';
					$icon = is_array( $item['social_icon'] ?? null ) ? self::string( $item['social_icon']['value'] ?? null ) : self::string( $item['social'] ?? null );
					$serv = self::service( $icon, $url );
					if ( '' !== $url && '' !== $serv ) {
						$links[] = array(
							'url'     => $url,
							'service' => $serv,
						);
					}
				}
				if ( array() === $links ) {
					$report->skip( $kind );
					return array();
				}
				return array( Blocks::social( $links ) );
			case 'counter':
				$number = self::string( $settings['ending_number'] ?? null );
				$title  = self::string( $settings['title'] ?? null );
				$html   = esc_html( self::string( $settings['prefix'] ?? null ) . $number . self::string( $settings['suffix'] ?? null ) );
				$out    = '' === trim( $html ) ? array() : array( Blocks::heading( 3, $html, $align ) );
				return array_merge( $out, Blocks::text( $title ) );
			case 'read-more':
				return array( Blocks::more() );
			case 'menu-anchor':
				$anchor = self::anchor( $settings['anchor'] ?? null );
				return null === $anchor ? array() : array( Blocks::group( array(), $anchor ) );
			case 'form':
				return self::form( $settings, $report );
			case 'price-table':
				return self::price_table( $settings, $keep, $report );
			case 'price-list':
				return self::price_list( $settings, $report );
			case 'posts':
			case 'archive-posts':
			case 'portfolio':
				return self::posts( $settings, $report );
			case 'slides':
				return self::slides( $settings, $keep, $report );
			case 'flip-box':
				return self::flip_box( $settings, $keep, $report );
			case 'template':
				return self::library( (int) self::string( $settings['template_id'] ?? null ), self::COLUMN, $keep, $report );
			case 'popup':
			case 'login':
			case 'search-form':
			case 'nav-menu':
			case 'breadcrumbs':
			case 'sitemap':
			case 'author-box':
			case 'post-navigation':
			case 'post-comments':
			case 'share-buttons':
			case 'facebook-comments':
			case 'facebook-page':
			case 'facebook-embed':
			case 'facebook-button':
			case 'lottie':
			case 'animated-headline':
				return self::listed( $kind, $settings, $report );
			case 'sidebar':
			case 'wp-widget-nav_menu':
			case 'wp-widget-search':
			case 'wp-widget-recent-posts':
			case 'wp-widget-recent-comments':
				$report->skip( $kind );
				return array();
			default:
				return self::unknown( $kind, $settings, $report );
		}
	}

	/**
	 * Boîte à icône, à image ou appel à l'action : titre, description, image, bouton.
	 *
	 * @param string               $kind     Sorte.
	 * @param array<string, mixed> $settings Réglages.
	 * @param bool                 $keep     Garder les couleurs fixes.
	 * @param Report               $report   Rapport.
	 * @return list<Block>
	 */
	private static function box( string $kind, array $settings, bool $keep, Report $report ): array {
		$blocks = array();
		$image  = is_array( $settings['image'] ?? null ) ? $settings['image'] : array();
		$url    = self::string( $image['url'] ?? null );
		if ( '' !== $url ) {
			$blocks[] = Blocks::image( $url, self::attachment( $image, $report ), self::string( $image['alt'] ?? null ) );
		}
		$title = self::string( $settings['title_text'] ?? $settings['title'] ?? null );
		$link  = is_array( $settings['link'] ?? null ) ? $settings['link'] : array();
		$href  = self::string( $link['url'] ?? null );
		if ( '' !== $title ) {
			$html     = esc_html( $title );
			$blocks[] = Blocks::heading( 3, '' !== $href && 'icon-box' !== $kind && ! isset( $settings['button'] ) ? '<a href="' . esc_url( $href ) . '">' . $html . '</a>' : $html );
		}
		foreach ( Blocks::rich( self::string( $settings['description_text'] ?? $settings['description'] ?? null ) ) as $block ) {
			$blocks[] = $block;
		}
		$button = self::string( $settings['button'] ?? null );
		if ( '' !== $button ) {
			$blocks[] = Blocks::buttons(
				array(
					array(
						'text'     => $button,
						'url'      => $href,
						'external' => 'on' === ( $link['is_external'] ?? '' ),
					),
				)
			);
		}
		if ( array() === $blocks ) {
			$report->skip( $kind );
			return array();
		}
		if ( 'icon-box' === $kind ) {
			$report->keep( 'icon' );
		}
		self::color( $settings['background_color'] ?? null, $keep, $report );
		return 'call-to-action' === $kind ? array( Blocks::group( $blocks ) ) : $blocks;
	}

	/**
	 * Tableau de prix : titre, sous-titre, prix, liste, bouton et note, dans un groupe.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @param bool                 $keep     Garder les couleurs fixes.
	 * @param Report               $report   Rapport.
	 * @return list<Block>
	 */
	private static function price_table( array $settings, bool $keep, Report $report ): array {
		$blocks  = array();
		$heading = self::string( $settings['heading'] ?? null );
		$ribbon  = 'yes' === ( $settings['show_ribbon'] ?? '' ) ? self::string( $settings['ribbon_title'] ?? null ) : '';
		if ( '' !== $heading ) {
			$blocks[] = Blocks::heading( 3, esc_html( $heading ) . ( '' !== $ribbon ? ' <em>(' . esc_html( $ribbon ) . ')</em>' : '' ) );
		}
		$sub = self::string( $settings['sub_heading'] ?? null );
		if ( '' !== $sub ) {
			$blocks[] = Blocks::paragraph( esc_html( $sub ) );
		}
		$price = self::string( $settings['price'] ?? null );
		if ( '' !== $price ) {
			$symbol   = self::string( $settings['currency_symbol'] ?? null );
			$symbol   = 'custom' === $symbol ? self::string( $settings['currency_symbol_custom'] ?? null ) : ( self::CURRENCIES[ $symbol ] ?? '' );
			$cents    = self::string( $settings['fractional_part'] ?? null );
			$period   = self::string( $settings['period'] ?? null );
			$amount   = $price . ( '' !== $cents ? ( ',' === ( $settings['currency_format'] ?? '' ) ? ',' : '.' ) . $cents : '' );
			$text     = '$' === $symbol || '' === $symbol ? $amount . ( '' !== $symbol ? "\u{00A0}" . $symbol : '' ) : $symbol . $amount;
			$blocks[] = Blocks::paragraph( '<strong>' . esc_html( $text ) . '</strong>' . ( '' !== $period ? ' ' . esc_html( $period ) : '' ) );
		}
		$items = array();
		foreach ( self::repeater( $settings['features_list'] ?? null ) as $row ) {
			$item = self::string( $row['item_text'] ?? null );
			if ( '' !== $item ) {
				$items[] = esc_html( $item );
			}
		}
		if ( array() !== $items ) {
			$blocks[] = Blocks::listing( $items );
		}
		$button = self::string( $settings['button_text'] ?? null );
		$link   = is_array( $settings['link'] ?? null ) ? $settings['link'] : array();
		if ( '' !== $button ) {
			$blocks[] = Blocks::buttons(
				array(
					array(
						'text'     => $button,
						'url'      => self::string( $link['url'] ?? null ),
						'external' => 'on' === ( $link['is_external'] ?? '' ),
					),
				)
			);
		}
		$note = self::string( $settings['footer_additional_info'] ?? null );
		if ( '' !== $note ) {
			$blocks[] = Blocks::paragraph( '<em>' . esc_html( $note ) . '</em>' );
		}
		if ( array() === $blocks ) {
			return self::skipped( 'price-table', $report );
		}
		self::color( $settings['header_bg_color'] ?? null, $keep, $report );
		self::color( $settings['price_color'] ?? null, $keep, $report );
		return array( Blocks::group( $blocks ) );
	}

	/**
	 * Liste de prix : chaque ligne devient « titre : prix » suivi de sa description.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @param Report               $report   Rapport.
	 * @return list<Block>
	 */
	private static function price_list( array $settings, Report $report ): array {
		$items = array();
		foreach ( self::repeater( $settings['price_list'] ?? null ) as $row ) {
			$title = self::string( $row['title'] ?? null );
			$price = self::string( $row['price'] ?? null );
			$desc  = self::string( $row['item_description'] ?? null );
			$link  = is_array( $row['link'] ?? null ) ? self::string( $row['link']['url'] ?? null ) : '';
			if ( '' === $title && '' === $price ) {
				continue;
			}
			$html = esc_html( $title );
			if ( '' !== $link ) {
				$html = '<a href="' . esc_url( $link ) . '">' . $html . '</a>';
			}
			if ( '' !== $price ) {
				$html = '<strong>' . $html . "\u{00A0}: " . esc_html( $price ) . '</strong>';
			}
			if ( '' !== $desc ) {
				$html .= '<br>' . esc_html( $desc );
			}
			$items[] = $html;
		}
		return array() === $items ? self::skipped( 'price-list', $report ) : array( Blocks::listing( $items ) );
	}

	/**
	 * Articles ou portfolio : requête simple sur le type de contenu, autant de contenus et de
	 * colonnes que le gadget ; la sélection fine (catégories, ordre, habillage) est à refaire.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @param Report               $report   Rapport.
	 * @return list<Block>
	 */
	private static function posts( array $settings, Report $report ): array {
		$skin     = self::string( $settings['_skin'] ?? null );
		$skin     = '' === $skin ? 'classic' : $skin;
		$per_page = (int) self::string( $settings['posts_per_page'] ?? $settings[ $skin . '_posts_per_page' ] ?? null );
		$columns  = (int) self::string( $settings[ $skin . '_columns' ] ?? $settings['columns'] ?? null );
		$type     = self::string( $settings['posts_post_type'] ?? $settings['portfolio_post_type'] ?? null );
		$type     = '' !== $type && post_type_exists( $type ) && 'any' !== $type ? $type : 'post';
		$report->todo( __( 'A list of posts was turned into a simple Query block: check its filters, order and look in the editor.', 'fluffless-suite' ) );
		return array( Blocks::query( $per_page > 0 ? $per_page : 6, $type, $columns > 0 ? $columns : 3 ) );
	}

	/**
	 * Diaporama : la première diapositive devient une section (image, titre, texte, bouton) ;
	 * les autres sont listées au rapport.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @param bool                 $keep     Garder les couleurs fixes.
	 * @param Report               $report   Rapport.
	 * @return list<Block>
	 */
	private static function slides( array $settings, bool $keep, Report $report ): array {
		$slides = self::repeater( $settings['slides'] ?? null );
		if ( array() === $slides ) {
			return self::skipped( 'slides', $report );
		}
		$first  = $slides[0];
		$blocks = array();
		$image  = is_array( $first['background_image'] ?? null ) ? $first['background_image'] : array();
		$url    = self::string( $image['url'] ?? null );
		if ( '' !== $url ) {
			$blocks[] = Blocks::image( $url, self::attachment( $image, $report ), self::string( $image['alt'] ?? null ) );
		}
		$heading = self::string( $first['heading'] ?? null );
		if ( '' !== $heading ) {
			$blocks[] = Blocks::heading( 2, esc_html( $heading ) );
		}
		foreach ( Blocks::rich( self::string( $first['description'] ?? null ) ) as $block ) {
			$blocks[] = $block;
		}
		$button = self::string( $first['button_text'] ?? null );
		$link   = is_array( $first['link'] ?? null ) ? $first['link'] : array();
		if ( '' !== $button ) {
			$blocks[] = Blocks::buttons(
				array(
					array(
						'text'     => $button,
						'url'      => self::string( $link['url'] ?? null ),
						'external' => 'on' === ( $link['is_external'] ?? '' ),
					),
				)
			);
		}
		$others = array();
		foreach ( array_slice( $slides, 1 ) as $slide ) {
			$title = self::string( $slide['heading'] ?? null );
			if ( '' !== $title ) {
				$others[] = $title;
			}
		}
		if ( count( $slides ) > 1 ) {
			/* translators: 1: number of slides, 2: titles of the other slides */
			$report->todo( sprintf( __( 'Slideshow of %1$d slides: only the first one is kept as a section. Other slides: %2$s.', 'fluffless-suite' ), count( $slides ), array() === $others ? __( 'untitled', 'fluffless-suite' ) : implode( ', ', $others ) ) );
		}
		if ( array() === $blocks ) {
			return self::skipped( 'slides', $report );
		}
		$background = self::color( $first['background_color'] ?? null, $keep, $report );
		return array( Blocks::group( $blocks, null, false, $background ) );
	}

	/**
	 * Boîte à retournement : les deux faces à la suite (image, titre et texte de la face avant,
	 * titre, texte et bouton de la face arrière).
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @param bool                 $keep     Garder les couleurs fixes.
	 * @param Report               $report   Rapport.
	 * @return list<Block>
	 */
	private static function flip_box( array $settings, bool $keep, Report $report ): array {
		$blocks = array();
		$image  = is_array( $settings['image'] ?? null ) ? $settings['image'] : array();
		$url    = self::string( $image['url'] ?? null );
		if ( '' !== $url ) {
			$blocks[] = Blocks::image( $url, self::attachment( $image, $report ), self::string( $image['alt'] ?? null ) );
		}
		foreach ( array(
			'a' => 3,
			'b' => 4,
		) as $side => $level ) {
			$title = self::string( $settings[ 'title_text_' . $side ] ?? null );
			if ( '' !== $title ) {
				$blocks[] = Blocks::heading( $level, esc_html( $title ) );
			}
			foreach ( Blocks::rich( self::string( $settings[ 'description_text_' . $side ] ?? null ) ) as $block ) {
				$blocks[] = $block;
			}
		}
		$button = self::string( $settings['button_text'] ?? null );
		$link   = is_array( $settings['link'] ?? null ) ? $settings['link'] : array();
		if ( '' !== $button ) {
			$blocks[] = Blocks::buttons(
				array(
					array(
						'text'     => $button,
						'url'      => self::string( $link['url'] ?? null ),
						'external' => 'on' === ( $link['is_external'] ?? '' ),
					),
				)
			);
		}
		if ( array() === $blocks ) {
			return self::skipped( 'flip-box', $report );
		}
		self::color( $settings['background_color_a'] ?? null, $keep, $report );
		self::color( $settings['background_color_b'] ?? null, $keep, $report );
		return array( Blocks::group( $blocks ) );
	}

	/**
	 * Gabarit enregistré (gadget Template ou gadget global) converti sur place, trois niveaux
	 * au plus ; un gabarit absent est noté au rapport.
	 *
	 * @param int    $post_id Identifiant du gabarit.
	 * @param string $context Contexte.
	 * @param bool   $keep    Garder les couleurs fixes.
	 * @param Report $report  Rapport.
	 * @return list<Block>
	 */
	private static function library( int $post_id, string $context, bool $keep, Report $report ): array {
		if ( $post_id <= 0 || ! self::supports( $post_id ) ) {
			/* translators: %d: template id */
			$report->todo( sprintf( __( 'A saved template of the page builder (%d) no longer exists: nothing was kept from it.', 'fluffless-suite' ), $post_id ) );
			return array();
		}
		if ( self::$depth >= 3 ) {
			return self::skipped( 'template', $report );
		}
		++self::$depth;
		try {
			return self::children( self::tree( $post_id ), $context, $keep, $report );
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Gabarits enregistrés du constructeur qui ne s'insèrent pas dans une page (en-têtes, pieds,
	 * modèles d'article ou d'archive, fenêtres surgissantes…) : sorte => titres.
	 *
	 * @return array<string, list<string>>
	 */
	public static function templates(): array {
		$ids = get_posts(
			array(
				'post_type'        => self::TYPE_LIBRARY,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'   => Migrator::LIMIT,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$type = sanitize_key( (string) get_post_meta( $id, self::META_TEMPLATE_TYPE, true ) );
			if ( '' === $type || in_array( $type, self::INSERTED, true ) ) {
				continue;
			}
			$title          = get_the_title( $id );
			$out[ $type ][] = '' === $title ? (string) $id : $title;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Réglages globaux du kit actif : couleurs par indice (text, primary, secondary, accent,
	 * background, button, button-text, puis les couleurs sur mesure par titre) et familles par
	 * indice (primary, secondary, text, accent, body).
	 *
	 * @return array{colors: array<string, string>, families: array<string, string>}
	 */
	public static function globals(): array {
		$out = array(
			'colors'   => array(),
			'families' => array(),
		);
		$kit = (int) get_option( self::OPTION_KIT, 0 );
		$raw = $kit > 0 ? get_post_meta( $kit, self::META_KIT, true ) : null;
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( array( 'system_colors', 'custom_colors' ) as $key ) {
			foreach ( self::repeater( $raw[ $key ] ?? null ) as $row ) {
				$hex = self::hex( $row['color'] ?? null );
				$id  = 'system_colors' === $key ? sanitize_key( self::string( $row['_id'] ?? null ) ) : trim( self::string( $row['title'] ?? null ) );
				if ( null !== $hex && '' !== $id && ! isset( $out['colors'][ $id ] ) ) {
					$out['colors'][ $id ] = $hex;
				}
			}
		}
		$direct = array(
			'body_background_color'   => 'background',
			'body_color'              => 'body',
			'button_background_color' => 'button',
			'button_text_color'       => 'button-text',
		);
		foreach ( $direct as $key => $hint ) {
			$hex = self::hex( $raw[ $key ] ?? null );
			if ( null !== $hex ) {
				$out['colors'][ $hint ] = $hex;
			}
		}
		foreach ( array( 'system_typography', 'custom_typography' ) as $key ) {
			foreach ( self::repeater( $raw[ $key ] ?? null ) as $row ) {
				$family = trim( self::string( $row['typography_font_family'] ?? null ) );
				$id     = 'system_typography' === $key ? sanitize_key( self::string( $row['_id'] ?? null ) ) : trim( self::string( $row['title'] ?? null ) );
				if ( '' !== $family && '' !== $id && ! isset( $out['families'][ $id ] ) ) {
					$out['families'][ $id ] = $family;
				}
			}
		}
		$body = trim( self::string( $raw['body_typography_font_family'] ?? null ) );
		if ( '' !== $body ) {
			$out['families']['body'] = $body;
		}
		return $out;
	}

	/**
	 * Formulaire : à refaire avec le module Formulaire ; les champs sont listés au rapport et
	 * rappelés dans la page.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @param Report               $report   Rapport.
	 * @return list<Block>
	 */
	private static function form( array $settings, Report $report ): array {
		$labels = array();
		foreach ( self::repeater( $settings['form_fields'] ?? null ) as $field ) {
			$label = self::string( $field['field_label'] ?? null );
			$type  = self::string( $field['field_type'] ?? null );
			if ( '' !== $label ) {
				$labels[] = $label . ( '' !== $type && 'text' !== $type ? ' (' . $type . ')' : '' );
			}
		}
		return self::form_todo( self::string( $settings['form_name'] ?? null ), $labels, $report );
	}

	/**
	 * Vidéo : intégration pour YouTube, Vimeo et Dailymotion, bloc vidéo pour un fichier du site.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @param Report               $report   Rapport.
	 * @return list<Block>
	 */
	private static function video( array $settings, Report $report ): array {
		$type = self::string( $settings['video_type'] ?? null );
		if ( 'hosted' === $type ) {
			$hosted = is_array( $settings['hosted_url'] ?? null ) ? self::string( $settings['hosted_url']['url'] ?? null ) : '';
			$ext    = is_array( $settings['external_url'] ?? null ) ? self::string( $settings['external_url']['url'] ?? null ) : '';
			$url    = 'on' === ( $settings['insert_url'] ?? '' ) ? $ext : $hosted;
			return '' === $url ? self::skipped( 'video', $report ) : array( Blocks::video( $url ) );
		}
		$url = self::string( $settings[ ( '' === $type ? 'youtube' : $type ) . '_url' ] ?? null );
		return '' === $url ? self::skipped( 'video', $report ) : array( Blocks::embed( $url ) );
	}
}
