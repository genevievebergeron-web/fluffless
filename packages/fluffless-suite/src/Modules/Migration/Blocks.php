<?php
/**
 * Écriture de blocs natifs : chaque méthode renvoie un bloc au format de parse_blocks()
 * (blockName, attrs, innerBlocks, innerHTML, innerContent), que serialize_blocks() transforme en
 * balisage. Le HTML suit la sortie d'enregistrement des blocs du cœur pour que l'éditeur ne
 * signale aucun bloc invalide.
 *
 * @package Fluffless\Suite
 */

declare(strict_types=1);

namespace Fluffless\Suite\Modules\Migration;

use DOMDocument;
use DOMElement;
use DOMNode;

defined( 'ABSPATH' ) || exit;

/**
 * Fabrique de blocs.
 *
 * @phpstan-type Block array{blockName: string, attrs: array<string, mixed>, innerBlocks: list<mixed>, innerHTML: string, innerContent: list<string|null>}
 * @phpstan-type Button array{text: string, url: string, external: bool}
 */
final class Blocks {

	/** Balises de niveau bloc dans un texte riche : au-delà d'un paragraphe, on découpe. */
	private const BLOCK_TAGS = '/<(p|h[1-6]|ul|ol|blockquote|div|table|figure|img|hr|pre|section|article)\b/i';

	/**
	 * Bloc sans enfant.
	 *
	 * @param string               $name  Nom du bloc.
	 * @param array<string, mixed> $attrs Attributs.
	 * @param string               $html  Balisage.
	 * @return Block
	 */
	public static function leaf( string $name, array $attrs, string $html ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/**
	 * Bloc avec enfants.
	 *
	 * @param string               $name  Nom du bloc.
	 * @param array<string, mixed> $attrs Attributs.
	 * @param string               $open  Balisage d'ouverture.
	 * @param array<int, mixed>    $inner Blocs enfants.
	 * @param string               $close Balisage de fermeture.
	 * @phpstan-param list<Block> $inner
	 * @return Block
	 */
	public static function wrap( string $name, array $attrs, string $open, array $inner, string $close ): array {
		$content = array( $open );
		foreach ( $inner as $ignored ) {
			$content[] = null;
		}
		$content[] = $close;
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
		);
	}

	/**
	 * Bloc dynamique sans balisage (titre d'article, image mise en avant, extrait).
	 *
	 * @param string               $name  Nom du bloc.
	 * @param array<string, mixed> $attrs Attributs.
	 * @return Block
	 */
	public static function dynamic( string $name, array $attrs = array() ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * Derniers contenus : requête simple (image mise en avant, titre lié, extrait), en grille ou en liste.
	 *
	 * @param int    $per_page  Contenus par page (1 à 24).
	 * @param string $post_type Type de contenu.
	 * @param int    $columns   Colonnes (1 pour une liste, 6 au plus).
	 * @return Block
	 */
	public static function query( int $per_page, string $post_type, int $columns ): array {
		$query    = array(
			'perPage'  => max( 1, min( 24, $per_page ) ),
			'pages'    => 0,
			'offset'   => 0,
			'postType' => $post_type,
			'order'    => 'desc',
			'orderBy'  => 'date',
			'author'   => '',
			'search'   => '',
			'exclude'  => array(),
			'sticky'   => '',
			'inherit'  => false,
		);
		$layout   = $columns > 1 ? array(
			'type'        => 'grid',
			'columnCount' => min( 6, $columns ),
		) : array( 'type' => 'default' );
		$template = self::wrap(
			'core/post-template',
			array( 'layout' => $layout ),
			'',
			array(
				self::dynamic( 'core/post-featured-image', array( 'isLink' => true ) ),
				self::dynamic( 'core/post-title', array( 'isLink' => true ) ),
				self::dynamic( 'core/post-excerpt' ),
			),
			''
		);
		return self::wrap( 'core/query', array( 'query' => $query ), '<div class="wp-block-query">', array( $template ), '</div>' );
	}

	/**
	 * Balisage d'une liste de blocs.
	 *
	 * @param array<int, mixed> $blocks Blocs.
	 * @phpstan-param list<Block> $blocks
	 */
	public static function serialize( array $blocks ): string {
		return serialize_blocks( $blocks );
	}

	/**
	 * Paragraphe.
	 *
	 * @param string      $html  Contenu (HTML en ligne déjà assaini).
	 * @param string|null $align Alignement (left, center, right) ou null.
	 * @param string|null $color Couleur du texte (HEX) ou null.
	 * @return Block
	 */
	public static function paragraph( string $html, ?string $align = null, ?string $color = null ): array {
		$attrs   = array();
		$classes = array();
		$style   = '';
		if ( null !== $align && '' !== $align ) {
			$attrs['align'] = $align;
			$classes[]      = 'has-text-align-' . $align;
		}
		if ( null !== $color ) {
			$attrs['style'] = array( 'color' => array( 'text' => $color ) );
			$classes[]      = 'has-text-color';
			$style          = ' style="color:' . $color . '"';
		}
		$class = array() === $classes ? '' : ' class="' . implode( ' ', $classes ) . '"';
		return self::leaf( 'core/paragraph', $attrs, '<p' . $class . $style . '>' . $html . '</p>' );
	}

	/**
	 * Titre.
	 *
	 * @param int         $level  Niveau 1 à 6.
	 * @param string      $html   Contenu.
	 * @param string|null $align  Alignement ou null.
	 * @param string|null $anchor Ancre ou null.
	 * @param string|null $color  Couleur du texte (HEX) ou null.
	 * @return Block
	 */
	public static function heading( int $level, string $html, ?string $align = null, ?string $anchor = null, ?string $color = null ): array {
		$level   = max( 1, min( 6, $level ) );
		$attrs   = array();
		$classes = array( 'wp-block-heading' );
		$style   = '';
		if ( null !== $align && '' !== $align ) {
			$attrs['textAlign'] = $align;
			$classes[]          = 'has-text-align-' . $align;
		}
		if ( 2 !== $level ) {
			$attrs['level'] = $level;
		}
		$id = '';
		if ( null !== $anchor && '' !== $anchor ) {
			$attrs['anchor'] = $anchor;
			$id              = ' id="' . $anchor . '"';
		}
		if ( null !== $color ) {
			$attrs['style'] = array( 'color' => array( 'text' => $color ) );
			$classes[]      = 'has-text-color';
			$style          = ' style="color:' . $color . '"';
		}
		$tag = 'h' . $level;
		return self::leaf( 'core/heading', $attrs, '<' . $tag . $id . ' class="' . implode( ' ', $classes ) . '"' . $style . '>' . $html . '</' . $tag . '>' );
	}

	/**
	 * Boutons.
	 *
	 * @param array<int, array<string, mixed>> $buttons Boutons (text, url, external).
	 * @param string|null                      $justify Alignement (left, center, right) ou null.
	 * @phpstan-param list<Button> $buttons
	 * @return Block
	 */
	public static function buttons( array $buttons, ?string $justify = null ): array {
		$inner = array();
		foreach ( $buttons as $button ) {
			$rel     = $button['external'] ? ' target="_blank" rel="noreferrer noopener"' : '';
			$attrs   = $button['external'] ? array(
				'linkTarget' => '_blank',
				'rel'        => 'noreferrer noopener',
			) : array();
			$href    = '' === $button['url'] ? '' : ' href="' . esc_url( $button['url'] ) . '"';
			$inner[] = self::leaf(
				'core/button',
				$attrs,
				'<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href . $rel . '>' . esc_html( $button['text'] ) . '</a></div>'
			);
		}
		$attrs = array();
		if ( null !== $justify && '' !== $justify ) {
			$attrs['layout'] = array(
				'type'           => 'flex',
				'justifyContent' => $justify,
			);
		}
		return self::wrap( 'core/buttons', $attrs, '<div class="wp-block-buttons">', $inner, '</div>' );
	}

	/**
	 * Groupe contraint.
	 *
	 * @param array<int, mixed> $inner      Blocs enfants.
	 * @param string|null       $anchor     Ancre ou null.
	 * @param bool              $full       Pleine largeur.
	 * @param string|null       $background Couleur de fond (HEX) ou null.
	 * @phpstan-param list<Block> $inner
	 * @return Block
	 */
	public static function group( array $inner, ?string $anchor = null, bool $full = false, ?string $background = null ): array {
		$attrs   = array();
		$classes = array( 'wp-block-group' );
		$style   = '';
		$id      = '';
		if ( null !== $anchor && '' !== $anchor ) {
			$attrs['anchor'] = $anchor;
			$id              = ' id="' . $anchor . '"';
		}
		if ( $full ) {
			$attrs['align'] = 'full';
			$classes[]      = 'alignfull';
		}
		if ( null !== $background ) {
			$attrs['style'] = array( 'color' => array( 'background' => $background ) );
			$classes[]      = 'has-background';
			$style          = ' style="background-color:' . $background . '"';
		}
		$attrs['layout'] = array( 'type' => 'constrained' );
		return self::wrap( 'core/group', $attrs, '<div' . $id . ' class="' . implode( ' ', $classes ) . '"' . $style . '>', $inner, '</div>' );
	}

	/**
	 * Colonnes.
	 *
	 * @param array<int, array<int, mixed>> $columns Blocs de chaque colonne.
	 * @param array<int, string|null>       $widths  Largeur en pourcentage par colonne, ou null.
	 * @phpstan-param list<list<Block>> $columns
	 * @return Block
	 */
	public static function columns( array $columns, array $widths = array() ): array {
		$inner = array();
		foreach ( $columns as $i => $blocks ) {
			$width = $widths[ $i ] ?? null;
			$attrs = array();
			$style = '';
			if ( null !== $width && '' !== $width ) {
				$attrs['width'] = $width;
				$style          = ' style="flex-basis:' . $width . '"';
			}
			$inner[] = self::wrap( 'core/column', $attrs, '<div class="wp-block-column"' . $style . '>', $blocks, '</div>' );
		}
		return self::wrap( 'core/columns', array(), '<div class="wp-block-columns">', $inner, '</div>' );
	}

	/**
	 * Image.
	 *
	 * @param string      $url     Adresse.
	 * @param int         $id      Identifiant du fichier joint, 0 sinon.
	 * @param string      $alt     Texte de remplacement.
	 * @param string      $caption Légende (HTML en ligne) ou vide.
	 * @param string      $link    Adresse du lien ou vide.
	 * @param string|null $align   Alignement (left, center, right) ou null.
	 * @return Block
	 */
	public static function image( string $url, int $id = 0, string $alt = '', string $caption = '', string $link = '', ?string $align = null ): array {
		$attrs   = array();
		$classes = array( 'wp-block-image' );
		if ( null !== $align && '' !== $align ) {
			$attrs['align'] = $align;
			$classes[]      = 'align' . $align;
		}
		if ( $id > 0 ) {
			$attrs['id']       = $id;
			$attrs['sizeSlug'] = 'large';
			$classes[]         = 'size-large';
		}
		$attrs['linkDestination'] = '' === $link ? 'none' : 'custom';
		$img                      = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . ( $id > 0 ? ' class="wp-image-' . $id . '"' : '' ) . '/>';
		if ( '' !== $link ) {
			$img = '<a href="' . esc_url( $link ) . '">' . $img . '</a>';
		}
		$figcaption = '' === $caption ? '' : '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
		return self::leaf( 'core/image', $attrs, '<figure class="' . implode( ' ', $classes ) . '">' . $img . $figcaption . '</figure>' );
	}

	/**
	 * Séparateur.
	 *
	 * @return Block
	 */
	public static function separator(): array {
		return self::leaf( 'core/separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
	}

	/**
	 * Espace.
	 *
	 * @param int $px Hauteur en pixels.
	 * @return Block
	 */
	public static function spacer( int $px ): array {
		$px = max( 0, min( 500, $px ) );
		return self::leaf( 'core/spacer', array( 'height' => $px . 'px' ), '<div style="height:' . $px . 'px" aria-hidden="true" class="wp-block-spacer"></div>' );
	}

	/**
	 * Liste.
	 *
	 * @param array<int, string> $items   Éléments (HTML en ligne).
	 * @param bool               $ordered Numérotée.
	 * @return Block
	 */
	public static function listing( array $items, bool $ordered = false ): array {
		$inner = array();
		foreach ( $items as $item ) {
			$inner[] = self::leaf( 'core/list-item', array(), '<li>' . $item . '</li>' );
		}
		$tag = $ordered ? 'ol' : 'ul';
		return self::wrap( 'core/list', $ordered ? array( 'ordered' => true ) : array(), '<' . $tag . ' class="wp-block-list">', $inner, '</' . $tag . '>' );
	}

	/**
	 * Citation.
	 *
	 * @param array<int, mixed> $inner Blocs (paragraphes).
	 * @param string            $cite  Auteur (HTML en ligne) ou vide.
	 * @phpstan-param list<Block> $inner
	 * @return Block
	 */
	public static function quote( array $inner, string $cite = '' ): array {
		$close = ( '' === $cite ? '' : '<cite>' . $cite . '</cite>' ) . '</blockquote>';
		return self::wrap( 'core/quote', array(), '<blockquote class="wp-block-quote">', $inner, $close );
	}

	/**
	 * Repli (détails).
	 *
	 * @param string            $summary Titre (HTML en ligne).
	 * @param array<int, mixed> $inner   Blocs du contenu.
	 * @phpstan-param list<Block> $inner
	 * @return Block
	 */
	public static function details( string $summary, array $inner ): array {
		return self::wrap( 'core/details', array(), '<details class="wp-block-details"><summary>' . $summary . '</summary>', $inner, '</details>' );
	}

	/**
	 * Vidéo hébergée ailleurs (YouTube, Vimeo…) ou lien.
	 *
	 * @param string $url Adresse.
	 * @return Block
	 */
	public static function embed( string $url ): array {
		$provider = '';
		$host     = wp_parse_url( $url, PHP_URL_HOST );
		$host     = is_string( $host ) ? strtolower( $host ) : '';
		foreach ( array( 'youtube', 'youtu.be', 'vimeo', 'dailymotion' ) as $known ) {
			if ( str_contains( $host, $known ) ) {
				$provider = 'youtu.be' === $known ? 'youtube' : $known;
			}
		}
		$attrs = array(
			'url'  => $url,
			'type' => 'video',
		);
		$class = 'wp-block-embed is-type-video';
		if ( '' !== $provider ) {
			$attrs['providerNameSlug'] = $provider;
			$class                    .= ' is-provider-' . $provider . ' wp-block-embed-' . $provider;
		}
		$attrs['responsive'] = true;
		$class              .= ' wp-embed-aspect-16-9 wp-has-aspect-ratio';
		$attrs['className']  = 'wp-embed-aspect-16-9 wp-has-aspect-ratio';
		return self::leaf( 'core/embed', $attrs, '<figure class="' . $class . '"><div class="wp-block-embed__wrapper">' . "\n" . esc_url( $url ) . "\n" . '</div></figure>' );
	}

	/**
	 * Vidéo hébergée sur le site.
	 *
	 * @param string $url Adresse du fichier.
	 * @return Block
	 */
	public static function video( string $url ): array {
		return self::leaf( 'core/video', array(), '<figure class="wp-block-video"><video controls src="' . esc_url( $url ) . '"></video></figure>' );
	}

	/**
	 * Fichier audio hébergé sur le site.
	 *
	 * @param string $url Adresse du fichier.
	 * @return Block
	 */
	public static function audio( string $url ): array {
		return self::leaf( 'core/audio', array(), '<figure class="wp-block-audio"><audio controls src="' . esc_url( $url ) . '"></audio></figure>' );
	}

	/**
	 * HTML libre.
	 *
	 * @param string $html Balisage.
	 * @return Block
	 */
	public static function html( string $html ): array {
		return self::leaf( 'core/html', array(), $html );
	}

	/**
	 * Code court.
	 *
	 * @param string $shortcode Code court.
	 * @return Block
	 */
	public static function shortcode( string $shortcode ): array {
		return self::leaf( 'core/shortcode', array(), $shortcode );
	}

	/**
	 * Galerie.
	 *
	 * @param array<int, array{url: string, id: int, alt: string}> $images Images.
	 * @param int                                                  $columns Colonnes.
	 * @return Block
	 */
	public static function gallery( array $images, int $columns = 3 ): array {
		$inner = array();
		foreach ( $images as $image ) {
			$attrs = array(
				'sizeSlug'        => 'large',
				'linkDestination' => 'none',
			);
			if ( $image['id'] > 0 ) {
				$attrs = array( 'id' => $image['id'] ) + $attrs;
			}
			$inner[] = self::leaf(
				'core/image',
				$attrs,
				'<figure class="wp-block-image size-large"><img src="' . esc_url( $image['url'] ) . '" alt="' . esc_attr( $image['alt'] ) . '"' . ( $image['id'] > 0 ? ' class="wp-image-' . $image['id'] . '"' : '' ) . '/></figure>'
			);
		}
		$columns = max( 1, min( 8, $columns ) );
		return self::wrap(
			'core/gallery',
			array(
				'columns'  => $columns,
				'linkTo'   => 'none',
				'sizeSlug' => 'large',
			),
			'<figure class="wp-block-gallery has-nested-images columns-' . $columns . ' is-cropped">',
			$inner,
			'</figure>'
		);
	}

	/**
	 * Icônes de réseaux sociaux.
	 *
	 * @param array<int, array{url: string, service: string}> $links Liens.
	 * @return Block
	 */
	public static function social( array $links ): array {
		$inner = array();
		foreach ( $links as $link ) {
			$inner[] = self::leaf(
				'core/social-link',
				array(
					'url'     => $link['url'],
					'service' => $link['service'],
				),
				''
			);
		}
		return self::wrap( 'core/social-links', array(), '<ul class="wp-block-social-links">', $inner, '</ul>' );
	}

	/**
	 * Lire la suite.
	 *
	 * @return Block
	 */
	public static function more(): array {
		return self::leaf( 'core/more', array(), '<!--more-->' );
	}

	/**
	 * Texte brut : un paragraphe, ou rien s'il est vide.
	 *
	 * @param string $text Texte.
	 * @return list<Block>
	 */
	public static function text( string $text ): array {
		$text = trim( wp_strip_all_tags( $text ) );
		return '' === $text ? array() : array( self::paragraph( esc_html( $text ) ) );
	}

	/**
	 * Texte riche (sortie d'un éditeur visuel) découpé en blocs : paragraphes, titres, listes,
	 * citations, images, séparateurs ; le reste dans un bloc HTML pour ne rien perdre.
	 *
	 * @param string      $html  HTML.
	 * @param string|null $color Couleur du texte (HEX) posée sur les paragraphes et titres, ou null.
	 * @return list<Block>
	 */
	public static function rich( string $html, ?string $color = null ): array {
		$html = trim( wp_kses_post( $html ) );
		if ( '' === $html ) {
			return array();
		}
		if ( 1 !== preg_match( self::BLOCK_TAGS, $html ) ) {
			return array( self::paragraph( self::inline( $html ), null, $color ) );
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$body = $dom->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $body instanceof DOMElement ) {
			return array( self::html( $html ) );
		}
		$blocks = array();
		foreach ( $body->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés du DOM.
			foreach ( self::node( $dom, $node, $color ) as $block ) {
				$blocks[] = $block;
			}
		}
		return $blocks;
	}

	/**
	 * Un nœud de premier niveau en blocs.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMNode     $node  Nœud.
	 * @param string|null $color Couleur du texte ou null.
	 * @return list<Block>
	 */
	private static function node( DOMDocument $dom, DOMNode $node, ?string $color ): array {
		if ( ! $node instanceof DOMElement ) {
			$text = trim( (string) $node->nodeValue ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés du DOM.
			return '' === $text ? array() : array( self::paragraph( esc_html( $text ), null, $color ) );
		}
		$tag   = strtolower( $node->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés du DOM.
		$inner = self::inner_html( $dom, $node );
		switch ( $tag ) {
			case 'p':
				$inner = self::inline( $inner );
				return '' === trim( wp_strip_all_tags( $inner ) ) ? array() : array( self::paragraph( $inner, null, $color ) );
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return array( self::heading( (int) substr( $tag, 1 ), self::inline( $inner ), null, null, $color ) );
			case 'ul':
			case 'ol':
				$items = array();
				foreach ( $node->getElementsByTagName( 'li' ) as $li ) {
					if ( $li->parentNode === $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés du DOM.
						$items[] = self::inline( self::inner_html( $dom, $li ) );
					}
				}
				return array() === $items ? array() : array( self::listing( $items, 'ol' === $tag ) );
			case 'blockquote':
				$paragraphs = array();
				foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés du DOM.
					foreach ( self::node( $dom, $child, null ) as $block ) {
						$paragraphs[] = $block;
					}
				}
				return array() === $paragraphs ? array() : array( self::quote( $paragraphs ) );
			case 'hr':
				return array( self::separator() );
			case 'img':
				$src = $node->getAttribute( 'src' );
				return '' === $src ? array() : array( self::image( $src, 0, $node->getAttribute( 'alt' ) ) );
			case 'br':
				return array();
			default:
				$outer = $dom->saveHTML( $node );
				return false === $outer || '' === trim( $outer ) ? array() : array( self::html( $outer ) );
		}
	}

	/**
	 * HTML intérieur d'un élément.
	 *
	 * @param DOMDocument $dom  Document.
	 * @param DOMElement  $node Élément.
	 */
	private static function inner_html( DOMDocument $dom, DOMElement $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés du DOM.
			$piece = $dom->saveHTML( $child );
			$html .= false === $piece ? '' : $piece;
		}
		return $html;
	}

	/**
	 * HTML en ligne propre : sans balise de bloc, espaces resserrés.
	 *
	 * @param string $html HTML.
	 */
	private static function inline( string $html ): string {
		$html = (string) preg_replace( '#</?(p|div|section|article)\b[^>]*>#i', '', $html );
		return trim( (string) preg_replace( '/\s+/', ' ', $html ) );
	}
}
