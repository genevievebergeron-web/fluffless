<?php
/**
 * Blocs modulables. Vague 1 : texte-photo, liste, colonnes, appel-action, témoignages, ressource.
 * Vague 2 : citation, valeurs, qui-nous-sommes, équipe, galerie.
 * Vague 3 : services, questions, chiffres, logos.
 * Vague 4 : onglets, carrousel, tarifs, frise. Vague 7 (19 sept.) : tableau.
 *
 * @package Fluffless_Suite
 */

declare( strict_types=1 );

namespace Fluffless\Suite\Blocks;

use Fluffless\Suite\Core\Sanitize;
use Fluffless\Suite\Modules\Footer;
use Fluffless\Suite\Modules\Form;
use Fluffless\Suite\Presentation\Ids;
use Fluffless\Suite\Presentation\Trail;
use WP_Block;
use WP_Block_Type_Registry;
use const Fluffless\Suite\FILE;
use const Fluffless\Suite\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Chaque bloc est un conteneur de blocs natifs, sans balisage propre dans le contenu enregistré :
 * extension désactivée, titres, textes, listes et images restent lisibles.
 * - Réglages de présentation : listes fermées, rendues en classes is-<réglage>-<valeur> sur
 *   l'enveloppe. La première valeur de chaque liste est le défaut ; block.json dit la même chose
 *   (vérifié par tests/sections-test.php).
 * - Réglages de structure (bouton oui ou non, nombre de colonnes, photo, logo) : l'éditeur ajoute
 *   ou retire des blocs enfants. Rien n'est stocké en double, donc rien à resynchroniser quand la
 *   personne retire un bouton à la main.
 * Site public : 0 JS. CSS commun en ligne (fonds) et CSS propre au bloc (block.json, « style »),
 * chargés seulement quand un de ces blocs est rendu.
 */
final class Sections {

	/** Script d'éditeur commun (aides des interrupteurs), dépendance de chaque index.js. */
	public const EDITOR_SCRIPT = 'fluffless-sections-editor';

	/** Feuille commune (fonds uni et accent), déclarée dans le « style » de chaque block.json. */
	public const STYLE = 'fluffless-sections';

	/** Réglages de présentation par bloc : valeurs permises, la première est le défaut. */
	public const OPTIONS = array(
		'texte-photo'        => array(
			'media'      => array( 'end', 'start' ),
			'ratio'      => array( '50-50', '60-40' ),
			'background' => array( 'surface', 'accent' ),
		),
		'liste'              => array(
			'marker'  => array( 'bullet', 'check', 'number' ),
			'columns' => array( 1, 2 ),
		),
		'colonnes'           => array(),
		'appel-action'       => array(
			'background' => array( 'accent', 'surface', 'image' ),
			'justify'    => array( 'center', 'start' ),
			'size'       => array( 'generous', 'thin' ),
		),
		'temoignages'        => array(),
		'ressource'          => array(
			'preview' => array( 'image', 'icon' ),
		),
		'citation'           => array(
			'size' => array( 'large', 'compact' ),
			'mark' => array( 'rule', 'quotes' ),
		),
		'valeurs'            => array(
			'display' => array( 'columns', 'list' ),
		),
		'qui-nous-sommes'    => array(),
		'equipe'             => array(
			'photo' => array( 'round', 'square' ),
		),
		'galerie'            => array(
			'arrangement' => array( 'grid', 'mosaic' ),
			'captions'    => array( 'shown', 'hidden' ),
		),
		'services'           => array(
			'display' => array( 'cards', 'list' ),
		),
		'questions'          => array(),
		'glossaire'          => array(
			'columns' => array( 2, 1 ),
		),
		'chiffres'           => array(
			'size' => array( 'large', 'compact' ),
		),
		'logos'              => array(
			'arrangement' => array( 'grid', 'row' ),
			'tone'        => array( 'mono', 'color' ),
		),
		'onglets'            => array(
			'display' => array( 'tabs', 'accordion' ),
		),
		'carrousel'          => array(
			'width' => array( 'third', 'half', 'full' ),
		),
		'tarifs'             => array(
			'featured' => array( 0, 1, 2, 3, 4 ),
		),
		'frise'              => array(
			'direction' => array( 'vertical', 'horizontal' ),
			'marker'    => array( 'dot', 'number' ),
		),
		'barres'             => array(
			'size'   => array( 'thin', 'thick' ),
			'values' => array( 'shown', 'hidden' ),
		),
		'compte-a-rebours'   => array(
			'size' => array( 'large', 'compact' ),
		),
		'partage'            => array(
			'display' => array( 'icons', 'labels' ),
		),
		'carte'              => array(
			'layout' => array( 'side', 'stacked' ),
		),
		'avant-apres'        => array(
			'start'  => array( 50, 25, 75 ),
			'labels' => array( 'shown', 'hidden' ),
		),
		'video'              => array(
			'ratio' => array( '16-9', '4-3', '1-1' ),
		),
		'table-des-matieres' => array(
			'levels'    => array( 'h2', 'h2-h3' ),
			'numbering' => array( 'plain', 'numbered' ),
		),
		'coordonnees'        => array(
			'hours' => array( 'shown', 'hidden' ),
		),
		'produit'            => array(
			'photo' => array( 'side', 'top' ),
		),
		'tableau'            => array(
			'density' => array( 'comfortable', 'compact' ),
			'lines'   => array( 'rows', 'grid', 'none' ),
			'stripes' => array( 'plain', 'striped' ),
			'header'  => array( 'plain', 'accent', 'sticky' ),
			'heads'   => array( 'plain', 'bold' ),
			'numbers' => array( 'right', 'left' ),
			'mobile'  => array( 'scroll', 'stack' ),
		),
	);

	/** Blocs dont la feuille propre peut aller jusqu'à 2 ko (sept réglages) ; les autres restent sous 1 ko. */
	public const WIDE_CSS = array( 'tableau', 'carrousel' );

	/**
	 * Blocs qui portent un petit module côté visiteur (viewScriptModule, moins de 1 ko, sans
	 * dépendance) : seule exception à la règle « aucun script » des blocs modulables.
	 */
	public const VIEW = array( 'avant-apres', 'video' );

	/** Option posée dès qu'une publication porte le bloc Vidéo : le service est alors déclaré au consentement. */
	public const VIDEO_OPTION = 'fluffless_suite_video';

	/**
	 * Serveurs du bloc Vidéo, déclarés dès qu'une publication porte le bloc (VIDEO_OPTION) : le
	 * navigateur de la visite charge le lecteur sans témoin, seulement après un clic.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function hosts(): array {
		if ( ! get_option( self::VIDEO_OPTION ) ) {
			return array();
		}
		$entries = array();
		foreach ( self::VIDEO as $provider ) {
			$entries[] = array(
				'host'    => (string) wp_parse_url( $provider['embed'], PHP_URL_HOST ),
				/* translators: %s: video service, e.g. YouTube */
				'service' => sprintf( __( 'Video block (%s)', 'fluffless-suite' ), $provider['label'] ),
				'side'    => 'browser',
				'when'    => 'after_click',
				'data'    => __( 'IP address and browser of the visitor who clicks play', 'fluffless-suite' ),
			);
		}
		return $entries;
	}

	/** Fournisseurs de vidéo reconnus : motif d'URL (identifiant capturé), page publique, intégration sans témoin. */
	private const VIDEO = array(
		'youtube' => array(
			'pattern' => '~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~',
			'page'    => 'https://www.youtube.com/watch?v=%s',
			'embed'   => 'https://www.youtube-nocookie.com/embed/%s?autoplay=1&rel=0',
			'label'   => 'YouTube',
		),
		'vimeo'   => array(
			'pattern' => '~^https?://(?:www\.)?(?:vimeo\.com/(?:video/)?|player\.vimeo\.com/video/)(\d{5,12})~',
			'page'    => 'https://vimeo.com/%s',
			'embed'   => 'https://player.vimeo.com/video/%s?autoplay=1&dnt=1',
			'label'   => 'Vimeo',
		),
	);

	/** Jours de schema.org vers l'indice de WP_Locale::get_weekday(). */
	private const DAYS = array(
		'Su' => 0,
		'Mo' => 1,
		'Tu' => 2,
		'We' => 3,
		'Th' => 4,
		'Fr' => 5,
		'Sa' => 6,
	);

	/**
	 * Limaces déjà posées sur les titres du contenu en cours (table des matières).
	 *
	 * @var array<string, int>
	 */
	private static array $seen = array();

	/**
	 * Script inerte du consentement déjà écrit sur la page.
	 *
	 * @var bool
	 */
	private static bool $consent_hook = false;

	/** Bloc enfant des barres de progression, rendu à part (une barre = un libellé et une valeur). */
	public const BAR = 'barre';

	/** Bloc Prix, enfant du bloc Produit (Vente simple) : montant, devise et disponibilité. */
	public const PRICE = 'prix';

	/** Bloc Fil d'Ariane : rendu à part (un nav, pas une section ; chemin de Presentation\Trail). */
	public const BREADCRUMB = 'fil-ariane';

	/** Bloc Jalon, une étape du bloc Frise (ancestor) : un conteneur, ses blocs internes. */
	public const STEP = 'jalon';

	/** Bloc Terme, l'entrée du Glossaire (rendu par render_terme). */
	public const TERM = 'terme';

	/** Devises acceptées par le bloc Prix ; la première est le défaut. */
	public const CURRENCIES = array( 'CAD', 'USD', 'EUR' );

	/** Disponibilités du bloc Prix => adresse schema.org de l'offre ; la première est le défaut. */
	public const AVAILABILITY = array(
		'available' => 'https://schema.org/InStock',
		'soldout'   => 'https://schema.org/SoldOut',
		'preorder'  => 'https://schema.org/PreOrder',
	);

	/** Borne haute d'un montant (exclue) : au-delà, la saisie est tenue pour une erreur. */
	public const MAX_AMOUNT = 10000000;

	/** Réseaux du bloc Partage, dans l'ordre d'affichage, avec le gabarit de leur page de partage. */
	public const SHARE = array(
		'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=%1$s',
		'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=%1$s',
		'bluesky'  => 'https://bsky.app/intent/compose?text=%2$s%%20%1$s',
		'threads'  => 'https://www.threads.net/intent/post?text=%2$s%%20%1$s',
		'email'    => 'mailto:?subject=%2$s&body=%1$s',
	);

	/** Attribut qui porte l'identifiant d'un fichier de la médiathèque, par bloc. */
	public const MEDIA = array(
		'appel-action' => 'imageId',
		'ressource'    => 'fileId',
		'video'        => 'posterId',
	);

	/** Enregistre la feuille commune, le script d'éditeur commun, puis chaque bloc depuis son block.json. */
	public static function register(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			wp_register_style( self::STYLE, false, array(), VERSION );
			wp_add_inline_style( self::STYLE, self::shared_css() );
		}
		$editor_script = plugins_url( 'assets/sections-editor.js', FILE );
		if ( '' !== $editor_script ) {
			wp_register_script(
				self::EDITOR_SCRIPT,
				$editor_script,
				array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
				VERSION,
				true
			);
		}
		foreach ( array_keys( self::OPTIONS ) as $slug ) {
			$render = 'ressource' === $slug
				? array( self::class, 'render_ressource' )
				: static function ( $attributes, $content = '', $block = null ) use ( $slug ): string {
					return self::render( $slug, $attributes, $content, $block );
				};
			register_block_type( dirname( FILE ) . '/blocks/' . $slug, array( 'render_callback' => $render ) );
		}
		register_block_type( dirname( FILE ) . '/blocks/' . self::BAR, array( 'render_callback' => array( self::class, 'render_barre' ) ) );
		register_block_type( dirname( FILE ) . '/blocks/' . self::PRICE, array( 'render_callback' => array( self::class, 'render_prix' ) ) );
		register_block_type( dirname( FILE ) . '/blocks/' . self::BREADCRUMB, array( 'render_callback' => array( self::class, 'render_fil_ariane' ) ) );
		register_block_type( dirname( FILE ) . '/blocks/' . self::STEP, array( 'render_callback' => array( self::class, 'render_jalon' ) ) );
		// skip_inner_blocks : render_terme rend lui-même ses paragraphes (terme dans dt, définition
		// dans dd) ; le cœur ne les rend pas une première fois pour rien.
		register_block_type(
			dirname( FILE ) . '/blocks/' . self::TERM,
			array(
				'render_callback'   => array( self::class, 'render_terme' ),
				'skip_inner_blocks' => true,
			)
		);
		add_filter( 'render_block_core/heading', array( self::class, 'heading_id' ), 10, 2 );
		add_filter( 'the_content', array( self::class, 'reset_headings' ), 1 );
		add_action( 'save_post', array( self::class, 'note_video' ), 10, 2 );
		add_filter( 'fluffless_consent_services', array( self::class, 'consent_services' ) );
	}

	/** Traductions des scripts d'éditeur (commun et un par bloc). */
	public static function editor_assets(): void {
		$languages = plugin_dir_path( FILE ) . 'languages';
		wp_set_script_translations( self::EDITOR_SCRIPT, 'fluffless-suite', $languages );
		foreach ( array_merge( array_keys( self::OPTIONS ), array( self::BAR, self::PRICE, self::BREADCRUMB, self::STEP, self::TERM ) ) as $slug ) {
			wp_set_script_translations( generate_block_asset_handle( 'fluffless/' . $slug, 'editorScript' ), 'fluffless-suite', $languages );
		}
		$data = wp_json_encode(
			array(
				'seo'  => class_exists( '\\Fluffless\\Seo\\Settings', false ),
				'html' => self::contact_details( array( 'hours' => 'shown' ) ),
			)
		);
		if ( false !== $data ) {
			wp_add_inline_script( generate_block_asset_handle( 'fluffless/coordonnees', 'editorScript' ), 'window.flufflessCoordonnees=' . $data . ';', 'before' );
		}
	}

	/**
	 * Attributs bornés : un réglage hors liste reprend son défaut, un identifiant de fichier non
	 * entier positif vaut 0.
	 *
	 * @param string       $slug       Bloc, clé de OPTIONS.
	 * @param array<mixed> $attributes Attributs bruts.
	 * @return array<string, int|string>
	 */
	public static function attributes( string $slug, array $attributes ): array {
		$clean = array();
		foreach ( self::OPTIONS[ $slug ] ?? array() as $name => $allowed ) {
			$value          = $attributes[ $name ] ?? null;
			$clean[ $name ] = in_array( $value, $allowed, true ) ? $value : $allowed[0];
		}
		if ( isset( self::MEDIA[ $slug ] ) ) {
			$id                = $attributes[ self::MEDIA[ $slug ] ] ?? 0;
			$clean['media_id'] = is_int( $id ) && $id > 0 ? $id : 0;
		}
		$align              = $attributes['align'] ?? null;
		$clean['align']     = in_array( $align, array( 'wide', 'full' ), true ) ? (string) $align : '';
		$tokens             = is_string( $attributes['className'] ?? null ) ? preg_split( '/\s+/', $attributes['className'] ) : false;
		$clean['className'] = implode( ' ', array_filter( array_map( 'sanitize_html_class', false === $tokens ? array() : $tokens ) ) );
		return $clean;
	}

	/**
	 * Classes de l'enveloppe, dans le même ordre que l'éditeur (sections-editor.js, shell()).
	 *
	 * @param string                    $slug Bloc.
	 * @param array<string, int|string> $a    Attributs déjà bornés.
	 */
	public static function classes( string $slug, array $a ): string {
		$classes = array( 'wp-block-fluffless-' . $slug, 'fluffless-section', 'fluffless-' . $slug );
		foreach ( array_keys( self::OPTIONS[ $slug ] ?? array() ) as $name ) {
			$classes[] = 'is-' . $name . '-' . $a[ $name ];
		}
		$classes[] = '' !== $a['align'] ? 'align' . $a['align'] : '';
		$classes[] = (string) $a['className'];
		return implode( ' ', array_filter( $classes ) );
	}

	/**
	 * Enveloppe autour du contenu enregistré, qui n'est jamais réécrit.
	 *
	 * @param string $slug       Bloc, clé de OPTIONS.
	 * @param mixed  $attributes Attributs du bloc (vérifiés : le rendu peut être appelé hors du cœur).
	 * @param mixed  $content    HTML des blocs enfants, déjà rendu par le cœur.
	 * @param mixed  $block      Instance WP_Block (graine des identifiants : contenu enregistré, pas rendu).
	 */
	public static function render( string $slug, $attributes, $content = '', $block = null ): string {
		$a      = self::attributes( $slug, is_array( $attributes ) ? $attributes : array() );
		$raw    = is_array( $attributes ) ? $attributes : array();
		$html   = is_string( $content ) ? $content : '';
		$seed   = (string) wp_json_encode( $raw ) . ( $block instanceof WP_Block ? (string) ( $block->parsed_block['innerHTML'] ?? '' ) : '' );
		$before = match ( $slug ) {
			'appel-action' => 'image' === $a['background'] ? self::background_image( (int) $a['media_id'] ) : '',
			'video'        => self::video_facade( $raw, (int) $a['media_id'] ),
			default        => '',
		};
		$html = match ( $slug ) {
			'onglets'     => self::group_details( $html, $seed ),
			'carrousel'   => self::carousel_keyboard( $html, $seed ),
			'avant-apres' => self::compare_frame( $html, $raw ),
			'tableau'     => self::table_cells( $html, $a ),
			'glossaire'   => '<dl class="fluffless-glossaire__list">' . $html . '</dl>',
			default       => $html,
		};
		$after = match ( $slug ) {
			'compte-a-rebours'   => self::countdown( $raw ),
			'partage'            => self::share_links( $raw ),
			'table-des-matieres' => self::toc( $raw ),
			'coordonnees'        => self::contact_details( $raw ),
			default              => '',
		};
		return sprintf( '<div class="%s">%s%s%s</div>', esc_attr( self::classes( $slug, $a ) ), $before, $html, $after );
	}

	/**
	 * Bloc Fil d'Ariane : le chemin de Presentation\Trail dans un nav nommé. Le maillon Accueil
	 * (adresse de l'accueil) et le maillon courant (adresse de la page affichée, texte avec
	 * aria-current plutôt que lien) sont reconnus par leur adresse, pas par leur rang : un maillon
	 * ajouté par filtre avant ou après reste un lien ordinaire. Chacun se cache par réglage ; rien
	 * n'est rendu quand il ne reste aucun maillon. Pas de classe fluffless-section : ce n'est pas
	 * une section (pas de marge verticale commune).
	 *
	 * @param mixed $attributes Attributs du bloc.
	 */
	public static function render_fil_ariane( $attributes ): string {
		$raw     = is_array( $attributes ) ? $attributes : array();
		$a       = self::attributes( self::BREADCRUMB, $raw );
		$home    = 'hidden' === ( $raw['home'] ?? '' ) ? 'hidden' : 'shown';
		$current = 'hidden' === ( $raw['current'] ?? '' ) ? 'hidden' : 'shown';
		$trail   = Trail::of();
		$urls    = array_column( $trail, 'url' );
		$here    = Trail::current_url();
		// Premier maillon à l'adresse de l'accueil ; dernier maillon à l'adresse de la page affichée,
		// sinon le dernier du chemin (archive, recherche, page introuvable, ou adresse réécrite par un
		// filtre) : une page d'accueil ancêtre ou un maillon ajouté par filtre ne change pas lequel se cache.
		$home_at = array_search( home_url( '/' ), $urls, true );
		$here_at = '' !== $here ? array_search( $here, array_reverse( $urls, true ), true ) : false;
		if ( false === $here_at ) {
			$here_at = array_key_last( $trail );
		}
		$items = '';
		foreach ( $trail as $i => $crumb ) {
			if ( ( $i === $home_at && 'hidden' === $home ) || ( $i === $here_at && 'hidden' === $current ) ) {
				continue;
			}
			$name = esc_html( $crumb['name'] );
			if ( $i === $here_at ) {
				$items .= '<li aria-current="page">' . $name . '</li>';
			} elseif ( '' !== $crumb['url'] ) {
				$items .= '<li><a href="' . esc_url( $crumb['url'] ) . '">' . $name . '</a></li>';
			} else {
				$items .= '<li>' . $name . '</li>';
			}
		}
		if ( '' === $items ) {
			return '';
		}
		$classes = array_filter(
			array(
				'wp-block-fluffless-' . self::BREADCRUMB,
				'fluffless-' . self::BREADCRUMB,
				'is-home-' . $home,
				'is-current-' . $current,
				'' !== $a['align'] ? 'align' . $a['align'] : '',
				(string) $a['className'],
			)
		);
		return sprintf( '<nav class="%s" aria-label="%s"><ol>%s</ol></nav>', esc_attr( implode( ' ', $classes ) ), esc_attr__( 'Breadcrumb', 'fluffless-suite' ), $items );
	}

	/**
	 * Bloc Jalon : une étape de la Frise, le même conteneur que l'ancien groupe natif
	 * (fluffless-frise__step), pour que la feuille de la Frise et les contenus déjà enregistrés
	 * continuent de se présenter pareil.
	 *
	 * @param mixed  $attributes Attributs du bloc (aucun ; l'ancre passe par Presentation\Anchor).
	 * @param string $content    Blocs internes rendus.
	 */
	public static function render_jalon( $attributes, $content = '' ): string {
		unset( $attributes );
		return '<div class="wp-block-fluffless-' . self::STEP . ' fluffless-frise__step">' . (string) $content . '</div>';
	}

	/**
	 * Bloc Terme : une entrée de liste de définitions. Le premier bloc interne (le terme) va dans
	 * dt, les suivants (la définition) dans dd, le tout dans un div (valide dans un dl). Rien n'est
	 * rendu sans terme ; sans définition, le dd reste vide (un div de dl exige un dd après le dt).
	 * Hors d'un Glossaire (contenu déplacé, rendu isolé), l'entrée porte son propre dl pour rester
	 * du HTML valide. Les blocs internes sont rendus ici (skip_inner_blocks), une seule fois, par
	 * WP_Block::render() comme le cœur rend ses propres blocs internes : render_block_data et
	 * render_block_context sont passés à la construction de la liste, render_block passe sur
	 * chaque paragraphe, le contexte du parent (boucle, liaisons) est conservé. pre_render_block
	 * ne s'applique qu'aux blocs de premier niveau, ici comme dans le cœur.
	 *
	 * @param mixed $attributes Attributs du bloc (aucun ; l'ancre passe par Presentation\Anchor).
	 * @param mixed $content    Ignoré (le cœur ne rend pas les blocs internes).
	 * @param mixed $block      Instance WP_Block : blocs internes et contexte du Glossaire.
	 */
	public static function render_terme( $attributes, $content = '', $block = null ): string {
		unset( $attributes, $content );
		if ( ! $block instanceof WP_Block ) {
			return '';
		}
		$name       = '';
		$definition = '';
		$first      = true;
		foreach ( $block->inner_blocks as $inner ) {
			$part = $inner instanceof WP_Block ? $inner->render() : '';
			if ( $first ) {
				$name  = $part;
				$first = false;
			} else {
				$definition .= $part;
			}
		}
		if ( '' === trim( wp_strip_all_tags( $name ) ) ) {
			return '';
		}
		$entry = '<div class="wp-block-fluffless-' . self::TERM . ' fluffless-' . self::TERM . '"><dt class="fluffless-terme__name">' . $name . '</dt><dd class="fluffless-terme__definition">' . $definition . '</dd></div>';
		return isset( $block->context['fluffless/glossaire'] ) ? $entry : '<dl class="fluffless-glossaire__list">' . $entry . '</dl>';
	}

	/**
	 * Une barre de progression : libellé, valeur en texte, piste décorative remplie par une
	 * variable CSS. Valeur bornée de 0 à 100 ; une chaîne numérique est acceptée.
	 *
	 * @param mixed $attributes Attributs du bloc.
	 */
	public static function render_barre( $attributes ): string {
		$a     = is_array( $attributes ) ? $attributes : array();
		$value = is_numeric( $a['value'] ?? null ) ? (int) round( (float) $a['value'] ) : 0;
		$value = max( 0, min( 100, $value ) );
		$label = is_string( $a['label'] ?? null ) ? wp_kses(
			$a['label'],
			array(
				'strong' => array(),
				'em'     => array(),
			)
		) : '';
		return sprintf(
			'<div class="wp-block-fluffless-barre fluffless-barre"><span class="fluffless-barre__label">%s</span><span class="fluffless-barre__value">%s</span><span class="fluffless-barre__track" aria-hidden="true"><span class="fluffless-barre__fill" style="--fl-v:%d%%"></span></span></div>',
			$label,
			/* translators: %s: value from 0 to 100 */
			esc_html( sprintf( __( '%s%%', 'fluffless-suite' ), number_format_i18n( $value ) ) ),
			$value
		);
	}

	/**
	 * Compte à rebours calculé par le serveur : jours restants (heures le dernier jour), puis
	 * la date en clair dans un élément time. Une fois la date passée, la ligne « après » du
	 * bloc ou une phrase neutre. Rien si la date est vide ou illisible.
	 *
	 * @param array<mixed> $a Attributs bruts.
	 */
	public static function countdown( array $a ): string {
		$raw = is_string( $a['date'] ?? null ) ? trim( $a['date'] ) : '';
		if ( '' === $raw ) {
			return '';
		}
		try {
			$target = new \DateTimeImmutable( $raw, wp_timezone() );
		} catch ( \Exception ) {
			return '';
		}
		$now  = new \DateTimeImmutable( 'now', wp_timezone() );
		$left = $target->getTimestamp() - $now->getTimestamp();
		$when = sprintf(
			'<time class="fluffless-compte-a-rebours__date" datetime="%s">%s</time>',
			esc_attr( $target->format( 'Y-m-d\TH:i' ) ),
			esc_html( (string) wp_date( (string) get_option( 'date_format' ), $target->getTimestamp() ) )
		);
		if ( $left <= 0 ) {
			$after = is_string( $a['after'] ?? null ) ? trim( $a['after'] ) : '';
			$text  = '' !== $after ? $after : __( 'This date has passed.', 'fluffless-suite' );
			return '<p class="fluffless-compte-a-rebours__figure is-past">' . esc_html( $text ) . '</p>';
		}
		if ( $left >= 2 * DAY_IN_SECONDS ) {
			$n    = (int) ceil( $left / DAY_IN_SECONDS );
			$unit = _n( 'day left', 'days left', $n, 'fluffless-suite' );
		} else {
			$n    = max( 1, (int) ceil( $left / HOUR_IN_SECONDS ) );
			$unit = _n( 'hour left', 'hours left', $n, 'fluffless-suite' );
		}
		return sprintf(
			'<p class="fluffless-compte-a-rebours__figure"><span class="fluffless-compte-a-rebours__number">%s</span> <span class="fluffless-compte-a-rebours__unit">%s</span></p>%s',
			esc_html( number_format_i18n( $n ) ),
			esc_html( $unit ),
			$when
		);
	}

	/**
	 * Liens de partage de la page en cours : un lien ordinaire par réseau coché, vers la page
	 * de partage du réseau ou l'application de courriel. Aucun script, rien de chargé avant le clic.
	 *
	 * @param array<mixed> $a Attributs bruts.
	 */
	public static function share_links( array $a ): string {
		$url   = is_singular() ? (string) get_permalink() : home_url( '/' );
		$title = is_singular() ? (string) get_the_title() : (string) get_bloginfo( 'name' );
		$items = '';
		foreach ( self::SHARE as $network => $pattern ) {
			if ( false === ( $a[ $network ] ?? true ) ) {
				continue;
			}
			$label = 'email' === $network
				? __( 'Share by email', 'fluffless-suite' )
				/* translators: %s: network name */
				: sprintf( __( 'Share on %s', 'fluffless-suite' ), Footer::NETWORKS[ $network ] );
			$href   = sprintf( $pattern, rawurlencode( $url ), rawurlencode( $title ) );
			$items .= sprintf(
				'<li><a class="fluffless-partage__link" href="%s" rel="noopener noreferrer"%s>%s<span class="%s">%s</span></a></li>',
				esc_url( $href, array( 'https', 'mailto' ) ),
				'email' === $network ? '' : ' target="_blank"',
				PiedDePage::glyph( $network ),
				'labels' === ( $a['display'] ?? 'icons' ) ? 'fluffless-partage__label' : 'screen-reader-text',
				esc_html( $label )
			);
		}
		return '' === $items ? '' : '<ul class="fluffless-partage__list">' . $items . '</ul>';
	}

	/**
	 * Cellules du tableau natif : les en-têtes du thead reçoivent scope="col" (toujours, pour que
	 * les lecteurs d'écran annoncent la colonne de chaque cellule), chaque cellule du corps reçoit
	 * en plus le libellé de sa colonne (data-label, lu par le CSS des rangées empilées) quand les
	 * rangées s'empilent, et la classe is-num quand elle ne contient qu'un nombre, un prix ou un
	 * pourcentage et que les nombres s'alignent à droite. Sans en-tête, pas de scope ni de libellé ;
	 * le reste du balisage n'est pas touché.
	 *
	 * @param string                    $html Tableau rendu par le cœur.
	 * @param array<string, int|string> $a    Attributs bornés.
	 */
	public static function table_cells( string $html, array $a ): string {
		$html    = self::table_header_scope( $html );
		$stack   = 'stack' === ( $a['mobile'] ?? null );
		$numbers = 'right' === ( $a['numbers'] ?? null );
		if ( ! $stack && ! $numbers ) {
			return $html;
		}
		$labels = array();
		if ( $stack && 1 === preg_match( '~<thead\b[^>]*>(.*?)</thead>~s', $html, $head ) && 0 < preg_match_all( '~<th\b[^>]*>(.*?)</th>~s', $head[1], $cells ) ) {
			$labels = array_map( static fn( string $cell ): string => trim( wp_strip_all_tags( $cell ) ), $cells[1] );
		}
		$rows = preg_replace_callback(
			'~<tr\b[^>]*>.*?</tr>~s',
			static function ( array $row ) use ( $labels, $numbers ): string {
				$index = 0;
				$out   = preg_replace_callback(
					'~<td\b([^>]*)>(.*?)</td>~s',
					static function ( array $cell ) use ( $labels, $numbers, &$index ): string {
						$attributes = $cell[1];
						$text       = trim( wp_strip_all_tags( $cell[2] ) );
						$label      = $labels[ $index ] ?? '';
						++$index;
						if ( '' !== $label ) {
							$attributes .= ' data-label="' . esc_attr( $label ) . '"';
						}
						if ( $numbers && '' !== $text && 1 === preg_match( '~^[$€£]?\s*[-+−]?\d[\d\s\x{00A0}\x{202F},.]*\s*(?:%|\$|€|£|[A-Za-z]{1,4})?$~u', $text ) ) {
							$attributes = 1 === preg_match( '~\bclass="~', $attributes )
								? (string) preg_replace( '~\bclass="~', 'class="is-num ', $attributes, 1 )
								: $attributes . ' class="is-num"';
						}
						return '<td' . $attributes . '>' . $cell[2] . '</td>';
					},
					$row[0]
				);
				return is_string( $out ) ? $out : $row[0];
			},
			$html
		);
		return is_string( $rows ) ? $rows : $html;
	}

	/**
	 * Ajoute scope="col" aux <th> du thead qui n'en ont pas déjà un, sans toucher au reste du
	 * balisage. Aucun <th> en dehors d'un thead n'est touché.
	 *
	 * @param string $html Tableau rendu par le cœur.
	 */
	private static function table_header_scope( string $html ): string {
		$out = preg_replace_callback(
			'~(<thead\b[^>]*>)(.*?)(</thead>)~s',
			static function ( array $thead ): string {
				$body = preg_replace_callback(
					'~<th\b([^>]*)>~',
					static function ( array $th ): string {
						return 1 === preg_match( '~\bscope=~', $th[1] )
							? $th[0]
							: '<th' . $th[1] . ' scope="col">';
					},
					$thead[2]
				);
				return $thead[1] . ( is_string( $body ) ? $body : $thead[2] ) . $thead[3];
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Avant et après : les deux images enregistrées, deux étiquettes et un curseur natif dans un
	 * cadre. La feuille empile les images, rogne la seconde par une variable CSS et dessine une
	 * poignée sur le trait ; le module view.js (moins de 1 ko) fait suivre la variable et la valeur
	 * lue (aria-valuetext, modèle traduit dans data-fl-valuetext) au curseur. Sans script, le
	 * curseur bouge et les deux images restent visibles à la position de départ.
	 *
	 * @param string       $html Contenu rendu du bloc (deux blocs Image).
	 * @param array<mixed> $a    Attributs bruts.
	 */
	public static function compare_frame( string $html, array $a ): string {
		$start  = in_array( $a['start'] ?? null, self::OPTIONS['avant-apres']['start'], true ) ? (int) $a['start'] : 50;
		$before = is_string( $a['before'] ?? null ) && '' !== trim( $a['before'] ) ? $a['before'] : __( 'Before', 'fluffless-suite' );
		$after  = is_string( $a['after'] ?? null ) && '' !== trim( $a['after'] ) ? $a['after'] : __( 'After', 'fluffless-suite' );
		$labels = 'hidden' === ( $a['labels'] ?? null )
			? ''
			: sprintf( '<span class="fluffless-avant-apres__label is-before">%s</span><span class="fluffless-avant-apres__label is-after">%s</span>', esc_html( $before ), esc_html( $after ) );
		/* translators: 1: label of the before image, 2: share of the before image, 3: label of the after image, 4: share of the after image. */
		$text = sprintf( __( '%1$s %2$s%%, %3$s %4$s%%', 'fluffless-suite' ), $before, '{b}', $after, '{a}' );
		return sprintf(
			'<div class="fluffless-avant-apres__frame" style="--fl-p:%1$d%%">%2$s%3$s<input type="range" class="fluffless-avant-apres__range" min="0" max="100" step="1" value="%1$d" aria-label="%4$s" aria-valuetext="%5$s" data-fl-valuetext="%6$s"></div>',
			$start,
			$html,
			$labels,
			esc_attr__( 'Compare before and after', 'fluffless-suite' ),
			esc_attr( str_replace( array( '{b}', '{a}' ), array( (string) $start, (string) ( 100 - $start ) ), $text ) ),
			esc_attr( $text )
		);
	}

	/**
	 * Fournisseur, identifiant, page publique et intégration d'une adresse de vidéo ; null si
	 * l'adresse n'est pas une vidéo YouTube ou Vimeo reconnue.
	 *
	 * @param string $url Adresse saisie.
	 * @return array{provider: string, id: string, page: string, embed: string, label: string}|null
	 */
	public static function video( string $url ): ?array {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 200 ) {
			return null;
		}
		foreach ( self::VIDEO as $provider => $v ) {
			if ( 1 === preg_match( $v['pattern'], $url, $m ) ) {
				return array(
					'provider' => $provider,
					'id'       => $m[1],
					'page'     => sprintf( $v['page'], rawurlencode( $m[1] ) ),
					'embed'    => sprintf( $v['embed'], rawurlencode( $m[1] ) ),
					'label'    => $v['label'],
				);
			}
		}
		return null;
	}

	/**
	 * Vidéo en façade : un lien vers la page de la vidéo, l'affiche choisie et un bouton de lecture
	 * dessinés par nous. Rien du fournisseur n'est chargé avant le clic. Le module view.js remplace
	 * le lien par le lecteur sans témoin (youtube-nocookie, Vimeo dnt) au clic, seulement quand
	 * l'extension de consentement est absente ou que la catégorie « Contenu intégré » est acceptée ;
	 * sinon le lien s'ouvre sur la page du fournisseur. Sans adresse reconnue, rien.
	 *
	 * @param array<mixed> $a         Attributs bruts.
	 * @param int          $poster_id Affiche, identifiant de la médiathèque (0 sans).
	 */
	public static function video_facade( array $a, int $poster_id ): string {
		$hosted = self::hosted_video( $a, $poster_id );
		if ( null !== $hosted ) {
			return $hosted;
		}
		$video = self::video( is_string( $a['url'] ?? null ) ? $a['url'] : '' );
		if ( null === $video ) {
			return '';
		}
		$title  = is_string( $a['title'] ?? null ) ? sanitize_text_field( $a['title'] ) : '';
		$poster = $poster_id > 0 && wp_attachment_is_image( $poster_id )
			? wp_get_attachment_image(
				$poster_id,
				'large',
				false,
				array(
					'class'    => 'fluffless-video__poster',
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			)
			: '';
		$label  = '' !== $title
			/* translators: %s: video title. */
			? sprintf( __( 'Play the video “%s”', 'fluffless-suite' ), $title )
			: __( 'Play the video', 'fluffless-suite' );
		/* translators: %s: YouTube or Vimeo. */
		$where = sprintf( __( 'Opens on %s until embedded content is allowed.', 'fluffless-suite' ), $video['label'] );
		$hook  = '';
		if ( ! self::$consent_hook && class_exists( '\\Fluffless\\Consent\\Consent', false ) ) {
			self::$consent_hook = true;
			$hook               = '<script type="text/plain" data-fluffless-consent="media">document.documentElement.dataset.flMedia="1"</script>';
		}
		return sprintf(
			'<a class="fluffless-video__facade" href="%s" target="_blank" rel="noopener noreferrer" data-embed="%s" data-title="%s">%s<span class="fluffless-video__play">%s<span class="screen-reader-text">%s</span></span></a>%s',
			esc_url( $video['page'] ),
			esc_url( $video['embed'] ),
			esc_attr( '' !== $title ? $title : $video['label'] ),
			$poster,
			Icone::svg( 'play' ),
			esc_html( $label . ' ' . $where ),
			$hook
		);
	}

	/**
	 * Vidéo hébergée ici (fichier de la médiathèque) : le lecteur du navigateur derrière l'affiche,
	 * preload="none" pour que rien ne se télécharge avant le clic, sans script ni consentement.
	 * Le fichier passe avant le lien. Null si l'identifiant ne désigne pas une vidéo.
	 *
	 * @param array<mixed> $a         Attributs bruts.
	 * @param int          $poster_id Affiche, identifiant de la médiathèque (0 sans).
	 */
	private static function hosted_video( array $a, int $poster_id ): ?string {
		$id = $a['videoId'] ?? 0;
		if ( ! is_int( $id ) || $id <= 0 ) {
			return null;
		}
		$mime = get_post_mime_type( $id );
		$src  = wp_get_attachment_url( $id );
		if ( ! is_string( $mime ) || ! str_starts_with( $mime, 'video/' ) || ! is_string( $src ) ) {
			return null;
		}
		$title  = is_string( $a['title'] ?? null ) ? sanitize_text_field( $a['title'] ) : '';
		$poster = $poster_id > 0 && wp_attachment_is_image( $poster_id ) ? wp_get_attachment_image_url( $poster_id, 'large' ) : false;
		return sprintf(
			'<video class="fluffless-video__player" controls playsinline preload="none"%s%s src="%s"></video>',
			is_string( $poster ) ? ' poster="' . esc_url( $poster ) . '"' : '',
			'' !== $title ? ' title="' . esc_attr( $title ) . '"' : '',
			esc_url( $src )
		);
	}

	/**
	 * Un bloc Vidéo avec un lien de plateforme (et sans fichier hébergé ici) dans l'arbre.
	 *
	 * @param array<mixed> $blocks Blocs analysés.
	 */
	private static function embeds_video( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = $block['blockName'] ?? '';
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$file  = $attrs['videoId'] ?? 0;
			if ( 'fluffless/video' === $name && ( ! is_int( $file ) || $file <= 0 ) && null !== self::video( is_string( $attrs['url'] ?? null ) ? $attrs['url'] : '' ) ) {
				return true;
			}
			$inner = $block['innerBlocks'] ?? null;
			if ( is_array( $inner ) && self::embeds_video( $inner ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Note qu'une publication porte le bloc Vidéo avec un lien de plateforme (un fichier hébergé
	 * ici ne déclare rien) : le service « YouTube et Vimeo » est alors
	 * déclaré à l'extension de consentement, sur toutes les pages, pour que la liste des services
	 * (et donc le choix mémorisé) ne change pas d'une page à l'autre.
	 *
	 * @param int      $post_id Publication enregistrée.
	 * @param \WP_Post $post    Publication.
	 */
	public static function note_video( int $post_id, \WP_Post $post ): void {
		if ( 'auto-draft' !== $post->post_status && ! wp_is_post_revision( $post_id ) && has_block( 'fluffless/video', $post ) && self::embeds_video( parse_blocks( $post->post_content ) ) ) {
			update_option( self::VIDEO_OPTION, 1, false );
		}
	}

	/**
	 * Service déclaré au consentement dès qu'une publication a porté le bloc Vidéo.
	 *
	 * @param mixed $services Services déjà déclarés.
	 * @return mixed
	 */
	public static function consent_services( $services ) {
		if ( ! is_array( $services ) || ! get_option( self::VIDEO_OPTION ) ) {
			return $services;
		}
		$services['video-embeds'] = array(
			'category' => 'media',
			'label'    => __( 'YouTube and Vimeo videos', 'fluffless-suite' ),
			'location' => __( 'United States', 'fluffless-suite' ),
		);
		return $services;
	}

	/**
	 * Identifiant stable d'un titre : son ancre si l'auteur en a mis une, sinon son texte en
	 * limace, numéroté au besoin (-2, -3) dans l'ordre du document.
	 *
	 * @param array<mixed>       $attrs Attributs du bloc Titre.
	 * @param string             $html  HTML intérieur du bloc.
	 * @param array<string, int> $seen  Limaces déjà posées, mises à jour.
	 */
	private static function heading_slug( array $attrs, string $html, array &$seen ): string {
		$anchor = is_string( $attrs['anchor'] ?? null ) ? sanitize_html_class( $attrs['anchor'] ) : '';
		if ( '' !== $anchor ) {
			return $anchor;
		}
		$slug = substr( sanitize_title( wp_strip_all_tags( $html ) ), 0, 80 );
		$slug = '' === $slug ? 'section' : $slug;
		$n    = ( $seen[ $slug ] ?? 0 ) + 1;

		$seen[ $slug ] = $n;
		return $n > 1 ? $slug . '-' . $n : $slug;
	}

	/**
	 * Titres de niveau 2 et 3 d'un contenu, dans l'ordre, hors ceux d'une table des matières,
	 * avec l'identifiant que heading_id() leur posera au rendu.
	 *
	 * @param array<mixed>       $blocks Blocs analysés.
	 * @param array<string, int> $seen   Limaces déjà posées.
	 * @param int                $depth  Profondeur courante.
	 * @return list<array{level: int, text: string, id: string}>
	 */
	private static function headings( array $blocks, array &$seen, int $depth = 0 ): array {
		$found = array();
		if ( $depth > 10 ) {
			return $found;
		}
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || 'fluffless/table-des-matieres' === ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$html  = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
			if ( 'core/heading' === ( $block['blockName'] ?? '' ) ) {
				$level = (int) ( $attrs['level'] ?? 2 );
				$text  = trim( wp_strip_all_tags( $html ) );
				if ( ( 2 === $level || 3 === $level ) && '' !== $text ) {
					$found[] = array(
						'level' => $level,
						'text'  => $text,
						'id'    => self::heading_slug( $attrs, $html, $seen ),
					);
				}
			}
			if ( is_array( $block['innerBlocks'] ?? null ) && array() !== $block['innerBlocks'] ) {
				$found = array_merge( $found, self::headings( $block['innerBlocks'], $seen, $depth + 1 ) );
			}
		}
		return $found;
	}

	/**
	 * Table des matières calculée au serveur depuis le contenu de la publication affichée :
	 * liste imbriquée de liens vers les titres de niveau 2 (et 3 au choix). Rien sans titre.
	 *
	 * @param array<mixed> $a Attributs bruts.
	 */
	public static function toc( array $a ): string {
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$seen  = array();
		$items = self::headings( parse_blocks( (string) $post->post_content ), $seen );
		$deep  = 'h2-h3' === ( $a['levels'] ?? null );
		$out   = '';
		$open  = false;
		foreach ( $items as $item ) {
			$link = sprintf( '<a href="#%s">%s</a>', esc_attr( $item['id'] ), esc_html( $item['text'] ) );
			if ( 2 === $item['level'] || '' === $out ) {
				$out .= ( $open ? '</ol></li>' : ( '' === $out ? '' : '</li>' ) ) . '<li>' . $link;
				$open = false;
			} elseif ( $deep ) {
				$out .= ( $open ? '' : '<ol>' ) . '<li>' . $link . '</li>';
				$open = true;
			}
		}
		if ( '' === $out ) {
			return '';
		}
		$out .= $open ? '</ol></li>' : '</li>';
		return sprintf(
			'<nav class="fluffless-table-des-matieres__nav" aria-label="%s"><ol class="fluffless-table-des-matieres__list">%s</ol></nav>',
			esc_attr__( 'Table of contents', 'fluffless-suite' ),
			$out
		);
	}

	/**
	 * Remise à zéro des limaces posées avant chaque rendu de contenu (the_content, priorité 1,
	 * avant do_blocks), pour que la table des matières et les titres comptent de la même façon.
	 *
	 * @param mixed $content Contenu.
	 * @return mixed
	 */
	public static function reset_headings( $content ) {
		self::$seen = array();
		return $content;
	}

	/**
	 * Pose un identifiant sur les titres de niveau 2 et 3 d'une publication qui porte une table
	 * des matières, calculé comme toc() le calcule. Un titre qui a déjà une ancre la garde.
	 *
	 * @param mixed $html  Rendu du bloc Titre.
	 * @param mixed $block Bloc analysé.
	 * @return mixed
	 */
	public static function heading_id( $html, $block ) {
		if ( ! is_string( $html ) || ! is_array( $block ) || ! has_block( 'fluffless/table-des-matieres' ) ) {
			return $html;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$level = (int) ( $attrs['level'] ?? 2 );
		if ( ( 2 !== $level && 3 !== $level ) || '' !== ( $attrs['anchor'] ?? '' ) ) {
			return $html;
		}
		$inner = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : $html;
		if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
			return $html;
		}
		$id = self::heading_slug( $attrs, $inner, self::$seen );
		return (string) preg_replace( '/<h([1-6])\b/', '<h$1 id="' . esc_attr( $id ) . '"', $html, 1 );
	}

	/**
	 * Coordonnées et heures d'ouverture, lues dans les réglages Entreprise locale de Fluffless SEO
	 * (une seule source, aussi balisée en LocalBusiness par SEO). Rien sans l'extension ni sans
	 * réglage rempli. Les heures en forme schema.org (Mo-Fr 09:00-17:00) deviennent des phrases
	 * dans la langue du site ; une ligne non comprise est montrée telle quelle.
	 *
	 * @param array<mixed> $a Attributs bruts.
	 */
	public static function contact_details( array $a ): string {
		if ( ! class_exists( '\\Fluffless\\Seo\\Settings', false ) ) {
			return '';
		}
		$get   = static fn( string $key ): string => trim( \Fluffless\Seo\Settings::string( $key ) );
		$lines = array_filter(
			array(
				$get( 'street' ),
				trim( $get( 'city' ) . ( '' !== $get( 'region' ) ? ', ' . $get( 'region' ) : '' ) . ' ' . $get( 'postal' ) ),
				$get( 'country' ),
			),
			static fn( string $line ): bool => '' !== $line
		);
		$out   = array() !== $lines
			? '<address class="fluffless-coordonnees__address">' . implode( '<br>', array_map( 'esc_html', $lines ) ) . '</address>'
			: '';
		$phone = $get( 'phone' );
		if ( '' !== $phone ) {
			$out .= sprintf(
				'<p class="fluffless-coordonnees__phone"><a href="tel:%s">%s</a></p>',
				esc_attr( (string) preg_replace( '/[^+\d]/', '', $phone ) ),
				esc_html( $phone )
			);
		}
		if ( 'hidden' !== ( $a['hours'] ?? null ) ) {
			$rows = '';
			foreach ( \Fluffless\Seo\Settings::list( 'hours' ) as $line ) {
				$rows .= self::hours_row( $line );
			}
			$out .= '' !== $rows ? '<dl class="fluffless-coordonnees__hours">' . $rows . '</dl>' : '';
		}
		return $out;
	}

	/**
	 * Une ligne d'heures : « Mo-Fr 09:00-17:00 » devient jours et heures dans la langue du site.
	 *
	 * @param string $line Ligne des réglages.
	 */
	private static function hours_row( string $line ): string {
		$line = trim( $line );
		if ( '' === $line ) {
			return '';
		}
		if ( 1 !== preg_match( '/^([A-Z][a-z])(?:-([A-Z][a-z]))?\s+(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/', $line, $m ) || ! isset( self::DAYS[ $m[1] ] ) || ( '' !== $m[2] && ! isset( self::DAYS[ $m[2] ] ) ) ) {
			return '<div><dd>' . esc_html( $line ) . '</dd></div>';
		}
		$wp_locale = $GLOBALS['wp_locale'] ?? null;
		$day       = static function ( string $key ) use ( $wp_locale ): string {
			return $wp_locale instanceof \WP_Locale ? $wp_locale->get_weekday( self::DAYS[ $key ] ) : $key;
		};
		$days      = '' !== $m[2]
			/* translators: 1: first day, 2: last day. */
			? sprintf( __( '%1$s to %2$s', 'fluffless-suite' ), $day( $m[1] ), $day( $m[2] ) )
			: $day( $m[1] );
		$time = static function ( string $hm ): string {
			$t = \DateTimeImmutable::createFromFormat( '!H:i', $hm, wp_timezone() );
			return false === $t ? $hm : (string) wp_date( (string) get_option( 'time_format', 'H:i' ), $t->getTimestamp() );
		};
		return sprintf(
			'<div><dt>%s</dt><dd>%s</dd></div>',
			esc_html( $days ),
			/* translators: 1: opening time, 2: closing time. */
			esc_html( sprintf( __( '%1$s to %2$s', 'fluffless-suite' ), $time( $m[3] ), $time( $m[4] ) ) )
		);
	}

	/**
	 * Onglets : un seul panneau ouvert à la fois, par l'attribut name natif de details (même
	 * nom = groupe exclusif, sans script). Nom propre à chaque rendu, donc deux blocs Onglets
	 * sur une page ne se ferment pas l'un l'autre ; un Détails qui porte déjà un nom le garde.
	 * Seule réécriture du contenu enregistré dans ces blocs.
	 *
	 * @param string $html Contenu rendu du bloc.
	 * @param string $seed Graine de l'identifiant de groupe : attributs et contenu enregistré du bloc.
	 */
	public static function group_details( string $html, string $seed = '' ): string {
		if ( ! str_contains( $html, '<details' ) ) {
			return $html;
		}
		$name    = Ids::stable( 'fluffless-onglets-', $seed );
		$grouped = preg_replace( '/<details\b(?![^>]*\sname=)/', '<details name="' . esc_attr( $name ) . '"', $html );
		return is_string( $grouped ) ? $grouped : $html;
	}

	/**
	 * Carrousel atteignable au clavier sans script : la piste devient une région focalisable et nommée
	 * (le défilement horizontal se fait alors aux flèches), chaque diapositive reçoit un identifiant et
	 * une position annoncée, et une liste de liens d'ancrage passe de l'une à l'autre (le défilement
	 * aimanté du navigateur s'en charge). Rien n'est ajouté sans diapositive.
	 *
	 * @param string $html Contenu rendu du bloc (titre, puis la piste de diapositives).
	 * @param string $seed Graine de l'identifiant : attributs et contenu enregistré du bloc.
	 */
	public static function carousel_keyboard( string $html, string $seed = '' ): string {
		if ( ! str_contains( $html, 'fluffless-carrousel__item' ) ) {
			return $html;
		}
		$count = 0;
		$scan  = new \WP_HTML_Tag_Processor( $html );
		while ( $scan->next_tag() ) {
			if ( $scan->has_class( 'fluffless-carrousel__item' ) ) {
				++$count;
			}
		}
		$base = Ids::stable( 'fluffless-carrousel-', $seed );
		// Plusieurs carrousels sur la page : le deuxième et les suivants (base-2…) suffixent leurs noms.
		$rank  = 1 === preg_match( '/-(\d+)$/', substr( $base, strlen( 'fluffless-carrousel-' ) + 8 ), $found ) ? (int) $found[1] : 1;
		$name  = $rank > 1 ? sprintf( '%s %d', __( 'Slides', 'fluffless-suite' ), $rank ) : __( 'Slides', 'fluffless-suite' );
		$nav   = $rank > 1 ? sprintf( '%s %d', __( 'Slide navigation', 'fluffless-suite' ), $rank ) : __( 'Slide navigation', 'fluffless-suite' );
		$tags  = new \WP_HTML_Tag_Processor( $html );
		$slide = 0;
		while ( $tags->next_tag() ) {
			if ( $tags->has_class( 'fluffless-carrousel__track' ) ) {
				$tags->set_attribute( 'tabindex', '0' );
				$tags->set_attribute( 'role', 'region' );
				$tags->set_attribute( 'aria-label', $name );
			} elseif ( $tags->has_class( 'fluffless-carrousel__item' ) ) {
				++$slide;
				$tags->set_attribute( 'id', $base . '-' . $slide );
				$tags->set_attribute( 'role', 'group' );
				$tags->set_attribute( 'aria-roledescription', __( 'slide', 'fluffless-suite' ) );
				/* translators: 1: slide number, 2: number of slides. */
				$tags->set_attribute( 'aria-label', sprintf( __( 'Slide %1$d of %2$d', 'fluffless-suite' ), $slide, $count ) );
			}
		}
		$links = '';
		for ( $i = 1; $i <= $slide; $i++ ) {
			$links .= sprintf(
				'<li><a href="#%1$s" aria-label="%2$s">%3$d</a></li>',
				esc_attr( $base . '-' . $i ),
				/* translators: 1: slide number, 2: number of slides. */
				esc_attr( sprintf( __( 'Slide %1$d of %2$d', 'fluffless-suite' ), $i, $slide ) ),
				$i
			);
		}
		return $tags->get_updated_html() . '<nav class="fluffless-carrousel__nav" aria-label="' . esc_attr( $nav ) . '"><ul>' . $links . '</ul></nav>';
	}

	/**
	 * Image de fond de l'appel à l'action : décorative (alt vide), tailles responsives du cœur,
	 * chargement différé. Rien si l'identifiant ne désigne pas une image.
	 *
	 * @param int $id Identifiant de la pièce jointe.
	 */
	private static function background_image( int $id ): string {
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			return '';
		}
		return wp_get_attachment_image(
			$id,
			'full',
			false,
			array(
				'class'    => 'fluffless-appel-action__image',
				'alt'      => '',
				'loading'  => 'lazy',
				'decoding' => 'async',
				'sizes'    => '100vw',
			)
		);
	}

	/**
	 * Prix d'un produit (bloc Prix) : montant lisible dans un élément data qui porte la valeur
	 * machine, puis la disponibilité en étiquette. Rien sans montant ni étiquette.
	 *
	 * @param mixed $attributes Attributs du bloc.
	 */
	public static function render_prix( $attributes ): string {
		$a     = self::price_attributes( is_array( $attributes ) ? $attributes : array() );
		$label = self::availability_label( $a['availability'] );
		if ( 0.0 === $a['amount'] && '' === $label ) {
			return '';
		}
		$parts = array();
		if ( $a['amount'] > 0 ) {
			$parts[] = sprintf(
				'<data class="fluffless-produit__amount" value="%s">%s</data>',
				esc_attr( number_format( $a['amount'], 2, '.', '' ) ),
				esc_html( self::price_text( $a['amount'], $a['currency'] ) )
			);
		}
		if ( '' !== $label ) {
			$parts[] = '<span class="fluffless-produit__availability">' . esc_html( $label ) . '</span>';
		}
		return sprintf(
			'<p class="wp-block-fluffless-prix fluffless-produit__price is-availability-%s">%s</p>',
			esc_attr( $a['availability'] ),
			implode( ' ', $parts )
		);
	}

	/**
	 * Attributs du bloc Prix, bornés : montant lisible ou 0.0, devise et disponibilité de la liste.
	 *
	 * @param array<mixed> $attributes Attributs bruts.
	 * @return array{amount: float, currency: string, availability: string}
	 */
	public static function price_attributes( array $attributes ): array {
		$currency     = $attributes['currency'] ?? null;
		$availability = $attributes['availability'] ?? null;
		return array(
			'amount'       => self::amount( $attributes['price'] ?? null ),
			'currency'     => in_array( $currency, self::CURRENCIES, true ) ? (string) $currency : self::CURRENCIES[0],
			'availability' => is_string( $availability ) && isset( self::AVAILABILITY[ $availability ] ) ? $availability : 'available',
		);
	}

	/**
	 * Montant d'un prix saisi librement : « 49 », « 49,99 », « 1 234,50 », « $1,234.56 ». Quand
	 * virgule et point cohabitent, le dernier des deux sépare les décimales. 0.0 si rien de
	 * lisible, négatif, nul ou au-delà de MAX_AMOUNT.
	 *
	 * @param mixed $price Valeur brute.
	 */
	public static function amount( $price ): float {
		if ( ! is_string( $price ) && ! is_int( $price ) && ! is_float( $price ) ) {
			return 0.0;
		}
		$raw = trim( (string) $price );
		if ( str_starts_with( $raw, '-' ) ) {
			return 0.0;
		}
		$digits = (string) preg_replace( '/[^0-9.,]/u', '', $raw );
		$comma  = strrpos( $digits, ',' );
		$dot    = strrpos( $digits, '.' );
		if ( false !== $comma && false !== $dot ) {
			$digits = $comma > $dot ? str_replace( '.', '', $digits ) : str_replace( ',', '', $digits );
		}
		$digits = str_replace( ',', '.', $digits );
		if ( ! is_numeric( $digits ) ) {
			return 0.0;
		}
		$amount = round( (float) $digits, 2 );
		return $amount > 0 && $amount < self::MAX_AMOUNT ? $amount : 0.0;
	}

	/**
	 * Prix lisible : montant dans la langue du site, symbole placé selon le format traduit.
	 *
	 * @param float  $amount   Montant positif.
	 * @param string $currency Devise, valeur de CURRENCIES.
	 */
	public static function price_text( float $amount, string $currency ): string {
		$symbol = match ( $currency ) {
			'USD'   => _x( 'US$', 'currency symbol', 'fluffless-suite' ),
			'EUR'   => _x( '€', 'currency symbol', 'fluffless-suite' ),
			default => _x( '$', 'currency symbol', 'fluffless-suite' ),
		};
		$number = number_format_i18n( $amount, 2 );
		/* translators: "before" or "after": where the currency symbol goes around the amount in this language ($49 or 49 $). */
		return 'after' === _x( 'before', 'currency symbol position', 'fluffless-suite' ) ? $number . "\u{00A0}" . $symbol : $symbol . $number;
	}

	/**
	 * Étiquette de disponibilité ; vide quand le produit est simplement disponible.
	 *
	 * @param string $availability Clé de AVAILABILITY.
	 */
	public static function availability_label( string $availability ): string {
		return match ( $availability ) {
			'soldout'  => __( 'Sold out', 'fluffless-suite' ),
			'preorder' => __( 'Pre-order', 'fluffless-suite' ),
			default    => '',
		};
	}

	/**
	 * Ressource : aperçu ou icône, puis le contenu et un lien de téléchargement explicite.
	 * Sans fichier valide, ni aperçu ni lien : un lien mort ne sert personne.
	 *
	 * @param mixed $attributes Attributs du bloc.
	 * @param mixed $content    HTML des blocs enfants, déjà rendu par le cœur.
	 */
	public static function render_ressource( $attributes, $content = '' ): string {
		$raw     = is_array( $attributes ) ? $attributes : array();
		$a       = self::attributes( 'ressource', $raw );
		$id      = (int) $a['media_id'];
		$link    = self::download_link( $id );
		$preview = '' === $link ? '' : self::preview( $id, (string) $a['preview'] );
		if ( '' !== $link && true === Sanitize::to_bool( $raw['emailFirst'] ?? false ) ) {
			$link = self::gate( $raw, $id, $link );
		}
		return sprintf(
			'<div class="%s">%s<div class="fluffless-ressource__body">%s%s</div></div>',
			esc_attr( self::classes( 'ressource', $a ) ),
			$preview,
			is_string( $content ) ? $content : '',
			$link
		);
	}

	/**
	 * « Demander un courriel d'abord » : le formulaire du module Formulaire (un champ courriel, un
	 * consentement si l'auteur en écrit un) prend la place du lien ; une fois l'envoi réussi, la page
	 * redirigée affiche le lien. Barrière douce : le fichier reste une adresse publique de la
	 * médiathèque, personne n'est empêché de la connaître. Sans le module Formulaire, le lien s'affiche
	 * tel quel : une barrière sans formulaire ne mènerait nulle part.
	 *
	 * @param array<mixed> $raw  Attributs bruts du bloc.
	 * @param int          $id   Identifiant de la pièce jointe.
	 * @param string       $link Lien de téléchargement déjà rendu.
	 */
	private static function gate( array $raw, int $id, string $link ): string {
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Form::FORM ) ) {
			return $link;
		}
		$form_id = 'ressource' . $id;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- drapeau d'affichage posé par la redirection du formulaire, sans effet.
		$sent = isset( $_GET[ Form::QUERY_SENT ] ) ? sanitize_key( (string) wp_unslash( $_GET[ Form::QUERY_SENT ] ) ) : '';
		if ( $sent === $form_id ) {
			return sprintf(
				'<div class="fluffless-ressource__gate is-sent"><p class="fluffless-ressource__thanks" role="status" tabindex="-1" id="fl-%s" autofocus>%s</p>%s</div>',
				esc_attr( $form_id ),
				esc_html__( 'Thank you. Your download is ready.', 'fluffless-suite' ),
				$link
			);
		}
		$fields = array(
			array(
				'blockName'    => Form::FIELD,
				'attrs'        => array(
					'type'     => 'email',
					'label'    => __( 'Email address', 'fluffless-suite' ),
					'name'     => 'email',
					'required' => true,
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
		);
		$agree  = is_string( $raw['consent'] ?? null ) ? trim( wp_strip_all_tags( $raw['consent'] ) ) : '';
		if ( '' !== $agree ) {
			$fields[] = array(
				'blockName'    => Form::FIELD,
				'attrs'        => array(
					'type'     => 'consent',
					'label'    => $agree,
					'name'     => 'consent',
					'required' => true,
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		}
		$title = self::file_details( $id )['title'];
		$form  = render_block(
			array(
				'blockName'    => Form::FORM,
				'attrs'        => array(
					'formId'      => $form_id,
					'recipients'  => is_string( $raw['recipients'] ?? null ) ? $raw['recipients'] : '',
					/* translators: %s: file title. */
					'subject'     => sprintf( __( 'Download: %s', 'fluffless-suite' ), $title ),
					'confirm'     => false,
					'keep'        => false,
					'submitLabel' => __( 'Get the file', 'fluffless-suite' ),
				),
				'innerBlocks'  => $fields,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, count( $fields ), null ),
			)
		);
		return sprintf(
			'<div class="fluffless-ressource__gate"><p class="fluffless-ressource__ask">%s</p>%s</div>',
			esc_html__( 'Enter your email to get the file.', 'fluffless-suite' ),
			$form
		);
	}

	/**
	 * Lien de téléchargement : titre, format et taille dans le texte même du lien.
	 *
	 * @param int $id Identifiant de la pièce jointe.
	 */
	public static function download_link( int $id ): string {
		$url = $id > 0 ? wp_get_attachment_url( $id ) : false;
		if ( false === $url || '' === $url ) {
			return '';
		}
		$file  = self::file_details( $id );
		$label = '' === $file['size']
			/* translators: 1: file title, 2: file format such as PDF. */
			? sprintf( __( 'Download %1$s (%2$s)', 'fluffless-suite' ), $file['title'], $file['format'] )
			/* translators: 1: file title, 2: file format such as PDF, 3: file size such as 240 KB. */
			: sprintf( __( 'Download %1$s (%2$s, %3$s)', 'fluffless-suite' ), $file['title'], $file['format'], $file['size'] );
		return sprintf(
			'<p class="fluffless-ressource__link"><a class="wp-element-button" href="%s" download>%s</a></p>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Titre, format (extension en capitales) et taille lisible d'un fichier de la médiathèque.
	 * Taille lue dans les métadonnées, sinon sur le disque ; vide si aucune n'est disponible.
	 *
	 * @param int $id Identifiant de la pièce jointe.
	 * @return array{title: string, format: string, size: string}
	 */
	public static function file_details( int $id ): array {
		$path  = get_attached_file( $id );
		$name  = is_string( $path ) && '' !== $path ? $path : (string) wp_get_attachment_url( $id );
		$ext   = wp_check_filetype( $name )['ext'];
		$meta  = wp_get_attachment_metadata( $id );
		$bytes = is_array( $meta ) && is_int( $meta['filesize'] ?? null ) ? $meta['filesize'] : 0;
		if ( 0 === $bytes && is_string( $path ) && is_readable( $path ) ) {
			$bytes = (int) filesize( $path );
		}
		$title = trim( (string) get_post_field( 'post_title', $id, 'raw' ) );
		$size  = $bytes > 0 ? size_format( $bytes ) : false;
		return array(
			'title'  => '' !== $title ? $title : wp_basename( $name ),
			'format' => is_string( $ext ) && '' !== $ext ? strtoupper( $ext ) : __( 'file', 'fluffless-suite' ),
			'size'   => is_string( $size ) ? $size : '',
		);
	}

	/**
	 * Aperçu : vignette du fichier (image, ou PDF dont WordPress a tiré un aperçu), sinon icône.
	 * Décoratif dans les deux cas : le lien dit déjà tout.
	 *
	 * @param int    $id   Identifiant de la pièce jointe.
	 * @param string $mode image ou icon.
	 */
	private static function preview( int $id, string $mode ): string {
		$image = 'image' === $mode
			? wp_get_attachment_image(
				$id,
				'medium',
				false,
				array(
					'class'    => 'fluffless-ressource__preview',
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			)
			: '';
		return '' !== $image ? $image : '<span class="fluffless-ressource__icon">' . Icone::svg( 'file-text' ) . '</span>';
	}

	/**
	 * Fonds communs. Le bouton passe en couleurs inversées sur l'accent, sinon il s'y fondrait ;
	 * le texte atténué y reprend on-accent (les classes de couleur du cœur sont en !important,
	 * d'où celui-ci). Rembourrage latéral seulement quand il y a un fond.
	 */
	public static function shared_css(): string {
		return '.fluffless-section{padding-block:var(--wp--preset--spacing--60,3rem)}'
			. '.fluffless-section:is(.is-background-surface,.is-background-accent,.is-background-image){padding-inline:var(--wp--preset--spacing--50,2rem)}'
			. '.fluffless-section.is-background-surface{background:var(--wp--preset--color--surface);color:var(--wp--preset--color--contrast)}'
			. '.fluffless-section.is-background-accent{background:var(--wp--preset--color--accent);color:var(--wp--preset--color--on-accent)}'
			. '.fluffless-section.is-background-accent .has-muted-color{color:inherit!important}'
			. '.fluffless-section.is-background-accent .wp-block-button:not(.is-style-outline)>.wp-element-button{background:var(--wp--preset--color--on-accent);color:var(--wp--preset--color--accent)}';
	}
}
