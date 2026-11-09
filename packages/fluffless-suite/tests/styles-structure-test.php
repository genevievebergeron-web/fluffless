<?php
/**
 * Tests des quatorze styles de structure, de carte et d'image de la Suite (plan, liste à filets,
 * disque tronqué, autocollant, bloc de fermeture, rythme alterné, filigrane flottant, sections
 * empilées, inversion au passage, cercle, pilule, arche, coins décalés, photo cerclée), de leurs
 * panneaux d'options et du fond de l'alternance exposé au style « Texte contour ».
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/styles-structure-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Modules\Palette;
use Fluffless\Suite\Presentation\BlockStyles;
use Fluffless\Suite\Presentation\Patterns;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( BlockStyles::class ) ) {
	throw new RuntimeException( 'Fluffless Suite non chargé : activer l\'extension (wp plugin activate fluffless-suite).' );
}
if ( Patterns::TEMPLATE !== get_template() ) {
	throw new RuntimeException( esc_html( 'Thème actif : ' . get_template() . '. Le test attend le thème fluffless (wp theme activate fluffless).' ) );
}
switch_to_locale( 'en_US' );

$fluffless_failures = array();
$fluffless_checks   = 0;

$fluffless_assert = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_crops = array( 'core/image', 'core/post-featured-image', 'fluffless/texte-photo' );

// Slug => [ types de bloc, libellé en_US, fragments attendus dans la feuille sans commentaires, bouge ].
$fluffless_styles = array(
	'plan'                 => array( array( 'core/group', 'core/columns', 'fluffless/colonnes' ), 'Blueprint', array( 'border: 1px solid var(--fl-plan, currentColor)', 'border-radius: 0', '.wp-block-column + .wp-block-column', 'border-inline-start: 1px solid', 'border-block-start: 1px solid', '@media (max-width: 781px)', ':not(.is-not-stacked-on-mobile)', '.fl-plan-neon', '--wp--preset--color--accent', '0 0 0.5rem' ), false ),
	'liste-filets'         => array( array( 'core/list', 'fluffless/liste', 'fluffless/tarifs', 'fluffless/coordonnees' ), 'Ruled list', array( 'list-style-type: ""', 'border-block-start: 1px solid', ' li', 'dl > div', 'background: none' ), false ),
	'disque-tronque'       => array( array( 'fluffless/carte', 'fluffless/texte-photo', 'core/media-text' ), 'Clipped disc', array( 'figure::before', 'border-radius: 50%', '--wp--preset--color--accent', 'clip-path: inset(', 'z-index: -1', 'isolation: isolate', 'overflow: visible', 'margin-block-start: -', '@media print' ), false ),
	'autocollant'          => array( array( 'core/group', 'core/paragraph', 'core/image' ), 'Sticker', array( 'rotate: -3deg', 'rotate: 3deg', '.fl-autocollant-droite', 'solid var(--wp--preset--color--base)', 'box-shadow:', '--wp--preset--color--accent)', '--wp--preset--color--on-accent)' ), false ),
	'fermeture'            => array( array( 'fluffless/appel-action', 'core/group' ), 'Closing block', array( ':not(.has-background)', '--wp--preset--color--accent)', '--wp--preset--color--on-accent)', 'text-align: center', 'padding-block: clamp(', 'border-image:', '.wp-element-button', 'font-size: clamp(' ), false ),
	'rythme-alterne'       => array( array( 'fluffless/galerie', 'core/post-template', 'core/group' ), 'Alternating rhythm', array( 'display: grid', 'repeat(3, minmax(0, 1fr))', ':nth-child(4n + 1)', 'grid-column: 1 / -1', '@media (max-width: 599px)', '.wp-block-gallery' ), false ),
	'filigrane'            => array( array( 'core/group' ), 'Floating watermark', array( '@media screen', '> :is(p, h1, h2, h3, h4, h5, h6):first-child', '> .fl-filigrane', 'z-index: -1', 'isolation: isolate', 'overflow: hidden', 'opacity:', 'pointer-events: none', '@keyframes fl-filigrane', '40s', 'translate' ), true ),
	'empilees'             => array( array( 'core/group' ), 'Stacked sections', array( '@media screen and (min-height: 600px) and (prefers-reduced-motion: no-preference)', 'position: sticky', 'top: var(--wp-admin--admin-bar--height, 0px)', '--wp--preset--color--base)', 'background: inherit', ':focus-within' ), false ),
	'inversion'            => array( array( 'core/image', 'core/site-logo', 'core/group', 'fluffless/icone' ), 'Inverts over backgrounds', array( 'mix-blend-mode: difference', 'color: white', 'forced-colors: active', 'mix-blend-mode: normal' ), false ),
	'cercle'               => array( $fluffless_crops, 'Circle', array( ' img', 'aspect-ratio: 1', 'object-fit: cover', 'border-radius: 50%' ), false ),
	'pilule'               => array( $fluffless_crops, 'Pill', array( ' img', 'border-radius: 9999px', 'object-fit: cover', 'aspect-ratio: 3 / 2', '.fl-pilule-haute', 'aspect-ratio: 2 / 3' ), false ),
	'arche'                => array( $fluffless_crops, 'Arch', array( ' img', 'border-radius: 9999px 9999px 0 0', 'object-fit: cover', 'aspect-ratio: 3 / 4' ), false ),
	'coins-decales'        => array( $fluffless_crops, 'Offset corners', array( ' img', 'border-radius: var(--fl-coin) 0', 'border-radius: 0 var(--fl-coin)', '.fl-coins-miroir' ), false ),
	'fond-decale'          => array( $fluffless_crops, 'Backdrop', array( ' img', 'box-shadow: var(--fl-fond) var(--fl-fond) 0 0 var(--wp--preset--color--accent-2)', 'max-width: calc(100% - var(--fl-fond))', 'margin-inline-end: var(--fl-fond)', 'margin-block-end: var(--fl-fond)', '[dir="rtl"]', 'calc(-1 * var(--fl-fond))' ), false ),
	'photo-panneau'        => array( array( 'core/cover' ), 'Photo panel', array( '> .wp-block-cover__background:not([class*="has-background-dim-"])', 'opacity: 0', '.wp-block-cover__inner-container', 'background: var(--wp--preset--color--surface)', ':not(.has-text-color) .wp-block-cover__inner-container', 'color: var(--wp--preset--color--contrast)', 'max-width: min(100%, 36rem)' ), false ),
	'photo-panneau-accent' => array( array( 'core/cover' ), 'Photo panel, accent', array( '> .wp-block-cover__background:not([class*="has-background-dim-"])', 'opacity: 0', 'background: var(--wp--preset--color--accent)', ':not(.has-text-color) .wp-block-cover__inner-container', 'color: var(--wp--preset--color--on-accent)', 'a:where(:not(.wp-element-button))', 'color: inherit', '.wp-element-button:where(:not(.has-background))', 'background: var(--wp--preset--color--on-accent)' ), false ),
	'photo-cerclee'        => array( array( 'core/image' ), 'Ringed photo', array( 'overflow: hidden', ' img', 'border-radius: 50%', 'aspect-ratio: 1', '.fl-ring', '@keyframes fl-ring', '30s linear infinite', 'rotate(1turn)', ':hover .fl-ring', ':focus-within .fl-ring', 'animation-play-state: paused', '@media print', '.has-fl-ring figcaption', 'clip-path: inset(50%)' ), true ),
);

$fluffless_registry = WP_Block_Styles_Registry::get_instance();
$fluffless_labels   = BlockStyles::labels();
$fluffless_by_slug  = array();
foreach ( BlockStyles::all() as $fluffless_def ) {
	$fluffless_by_slug[ $fluffless_def['slug'] ] = $fluffless_def;
}
$fluffless_handles = array_map( array( BlockStyles::class, 'handle' ), array_keys( $fluffless_styles ) );
array_map( 'wp_dequeue_style', $fluffless_handles );

// Logo, image mise en avant et gabarit de publication n'ont de rendu qu'avec leur contexte (logo réglé,
// publication en cours) : pour eux, le filtre de chargement est appelé tel que render_block l'appelle.
$fluffless_contextual = array( 'core/site-logo', 'core/post-featured-image', 'core/post-template' );
$fluffless_render     = static function ( string $block, string $class_name ) use ( $fluffless_contextual ): string {
	$markup = 'core/image' === $block
		? '<figure class="wp-block-image ' . esc_attr( $class_name ) . '"><img src="/x.webp" alt=""/></figure>'
		: '<div class="' . esc_attr( $class_name ) . '"><p>Un mot</p></div>';
	$parsed = array(
		'blockName'    => $block,
		'attrs'        => '' === $class_name ? array() : array( 'className' => $class_name ),
		'innerBlocks'  => array(),
		'innerHTML'    => $markup,
		'innerContent' => array( $markup ),
	);
	return in_array( $block, $fluffless_contextual, true ) ? BlockStyles::enqueue_used( $markup, $parsed ) : render_block( $parsed );
};

/*
 * Sans le style, aucune des quatorze feuilles ne se charge.
 */
foreach ( array( 'core/group', 'core/image', 'core/list', 'core/columns' ) as $fluffless_block ) {
	$fluffless_render( $fluffless_block, '' );
	$fluffless_render( $fluffless_block, 'is-style-inconnu fl-plan-neon fl-pilule-haute fl-filigrane' );
}
$fluffless_loaded = array_filter( $fluffless_handles, static fn( string $handle ): bool => wp_style_is( $handle, 'enqueued' ) );
$fluffless_assert( array() === $fluffless_loaded, 'Sans style de structure, aucune feuille chargée : ' . implode( ', ', $fluffless_loaded ) );

foreach ( $fluffless_styles as $fluffless_slug => list( $fluffless_types, $fluffless_label, $fluffless_needles, $fluffless_moves ) ) {
	$fluffless_assert( isset( $fluffless_by_slug[ $fluffless_slug ] ) && $fluffless_types === $fluffless_by_slug[ $fluffless_slug ]['blockTypes'], "Style {$fluffless_slug} défini pour ses types de blocs." );
	$fluffless_assert( ( $fluffless_labels[ $fluffless_slug ] ?? null ) === $fluffless_label && ( $fluffless_by_slug[ $fluffless_slug ]['title'] ?? null ) === $fluffless_label, "Style {$fluffless_slug} : libellé en_US « {$fluffless_label} » dans labels() et dans le JSON." );
	foreach ( $fluffless_types as $fluffless_block ) {
		$fluffless_assert( is_array( $fluffless_registry->get_registered( $fluffless_block, $fluffless_slug ) ), "Style {$fluffless_slug} enregistré pour {$fluffless_block}." );
	}

	$fluffless_path = BlockStyles::css_path( $fluffless_slug );
	$fluffless_lean = is_readable( $fluffless_path ) ? BlockStyles::strip_comments( (string) file_get_contents( $fluffless_path ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local de l'extension.
	$fluffless_assert( '' !== $fluffless_lean && strlen( $fluffless_lean ) < 1024, "Feuille {$fluffless_slug} sous 1 ko sans commentaires (" . strlen( $fluffless_lean ) . ' o).' );
	$fluffless_assert( str_contains( $fluffless_lean, '.is-style-' . $fluffless_slug ), "Feuille {$fluffless_slug} ciblée par sa classe." );
	$fluffless_assert( ! str_contains( $fluffless_lean, 'url(' ) && ! str_contains( $fluffless_lean, '@import' ) && ! str_contains( $fluffless_lean, '@font-face' ) && ! str_contains( $fluffless_lean, '<script' ), "Feuille {$fluffless_slug} sans image, import, police chargée ni script." );
	$fluffless_assert( 1 !== preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_lean ), "Feuille {$fluffless_slug} sans couleur HEX." );
	$fluffless_assert( ! str_contains( $fluffless_lean, 'position: fixed' ), "Feuille {$fluffless_slug} sans position fixe." );
	$fluffless_assert( 1 !== preg_match( '/aspect-ratio\s*:[^;}]*!important/', $fluffless_lean ), "Feuille {$fluffless_slug} : la proportion choisie sur le bloc (style en ligne) garde la main sur celle du style." );
	if ( $fluffless_moves ) {
		$fluffless_assert( 1 === preg_match( '/@media \(prefers-reduced-motion: reduce\) \{[^}]*\{[^}]*animation: none/', $fluffless_lean ), "Feuille {$fluffless_slug} : animation coupée sous prefers-reduced-motion." );
		$fluffless_assert( ! str_contains( $fluffless_lean, 'transition' ), "Feuille {$fluffless_slug} : aucune transition hors du garde-fou." );
	} else {
		$fluffless_assert( ! str_contains( $fluffless_lean, 'animation' ) && ! str_contains( $fluffless_lean, 'transition' ) && ! str_contains( $fluffless_lean, '@keyframes' ), "Feuille {$fluffless_slug} immobile : ni animation ni transition." );
	}
	foreach ( $fluffless_needles as $fluffless_needle ) {
		$fluffless_assert( str_contains( $fluffless_lean, $fluffless_needle ), "Feuille {$fluffless_slug} : « {$fluffless_needle} » attendu." );
	}

	$fluffless_handle = BlockStyles::handle( $fluffless_slug );
	foreach ( $fluffless_types as $fluffless_block ) {
		wp_dequeue_style( $fluffless_handle );
		$fluffless_render( $fluffless_block, 'is-style-' . $fluffless_slug );
		$fluffless_assert( wp_style_is( $fluffless_handle, 'enqueued' ), "Feuille {$fluffless_handle} chargée au rendu de {$fluffless_block}." );
	}
	$fluffless_inline = implode( '', (array) wp_styles()->get_data( $fluffless_handle, 'after' ) );
	$fluffless_assert( $fluffless_lean === $fluffless_inline, "Feuille {$fluffless_handle} en ligne, sans ses commentaires." );
}

// Sémantique de liste gardée : list-style: none retire le rôle de liste dans Safari et VoiceOver.
$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( 'liste-filets' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local de l'extension.
$fluffless_assert( 1 !== preg_match( '/list-style(-type)?:\s*none/', $fluffless_lean ), 'Liste à filets : jamais list-style: none.' );

// Filigrane et sections empilées : tout est sous @media, le repli est le contenu normal (impression comprise).
foreach ( array( 'filigrane', 'empilees' ) as $fluffless_slug ) {
	$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( $fluffless_slug ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local de l'extension.
	$fluffless_assert( 1 !== preg_match( '/^\.is-style/m', $fluffless_lean ), "Feuille {$fluffless_slug} : aucune règle hors @media screen." );
}

/*
 * Panneaux d'options : une classe par valeur hors défaut, source unique STYLE_OPTIONS.
 */
$fluffless_panels = array();
foreach ( BlockStyles::panels_data() as $fluffless_panel ) {
	$fluffless_panels[ $fluffless_panel['style'] ] = $fluffless_panel;
}
$fluffless_expected = array(
	'plan'          => array( 'line', 'fl-plan-', array( 'plain', 'neon' ) ),
	'autocollant'   => array( 'tilt', 'fl-autocollant-', array( 'gauche', 'droite' ) ),
	'pilule'        => array( 'format', 'fl-pilule-', array( 'large', 'haute' ) ),
	'coins-decales' => array( 'diagonal', 'fl-coins-', array( 'normal', 'miroir' ) ),
);
$fluffless_js       = (string) file_get_contents( dirname( __DIR__ ) . '/assets/block-styles-editor.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local de l'extension.
foreach ( $fluffless_expected as $fluffless_slug => list( $fluffless_group, $fluffless_head, $fluffless_values ) ) {
	$fluffless_panel = $fluffless_panels[ $fluffless_slug ] ?? array();
	$fluffless_lean  = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( $fluffless_slug ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local de l'extension.
	$fluffless_assert( ( $fluffless_by_slug[ $fluffless_slug ]['blockTypes'] ?? null ) === ( $fluffless_panel['blocks'] ?? false ), "Panneau {$fluffless_slug} : offert sur les blocs du style." );
	$fluffless_found = $fluffless_panel['groups'][ $fluffless_group ] ?? null;
	$fluffless_assert( is_array( $fluffless_found ) && $fluffless_found['head'] === $fluffless_head && $fluffless_found['values'] === $fluffless_values, "Panneau {$fluffless_slug} : tête {$fluffless_head} et valeurs attendues." );
	$fluffless_assert( ! str_contains( $fluffless_lean, '.' . $fluffless_head . $fluffless_values[0] ), "Panneau {$fluffless_slug} : le défaut n’écrit aucune classe." );
	$fluffless_assert( str_contains( $fluffless_lean, '.is-style-' . $fluffless_slug . '.' . $fluffless_head . $fluffless_values[1] ), "Panneau {$fluffless_slug} : règle pour {$fluffless_values[1]}." );
	$fluffless_assert( str_contains( $fluffless_js, "'" . $fluffless_slug . "':" ) || str_contains( $fluffless_js, "\t\t" . $fluffless_slug . ':' ), "Script du panneau : libellés pour {$fluffless_slug}." );
	$fluffless_assert( ! str_contains( $fluffless_js, $fluffless_head ), "Script du panneau : préfixe {$fluffless_head} jamais en dur." );
}

/*
 * Dette du lot A : le fond de l'alternance de la Palette arrive au style « Texte contour ».
 */
$fluffless_saved = Palette::alternation();
Palette::save_alternation(
	array(
		'on'     => '1',
		'first'  => 'surface',
		'second' => 'accent',
	)
);
$fluffless_css = Palette::alternation_rules();
$fluffless_assert( str_contains( $fluffless_css, '.is-fl-alt-1{background:var(--wp--preset--color--surface);color:var(--wp--preset--color--contrast);--fl-alt-fond:var(--wp--preset--color--surface)}' ) && str_contains( $fluffless_css, ';--fl-alt-fond:var(--wp--preset--color--accent)}' ), 'Alternance : fond exposé par --fl-alt-fond : ' . $fluffless_css );
$fluffless_assert( strlen( $fluffless_css ) < 1024, 'Alternance : feuille sous 1 ko (' . strlen( $fluffless_css ) . ' o).' );
Palette::save_alternation(
	array(
		'on'     => $fluffless_saved['on'] ? '1' : '',
		'first'  => $fluffless_saved['first'],
		'second' => $fluffless_saved['second'],
	)
);
$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( 'texte-contour' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local de l'extension.
$fluffless_assert( 1 === preg_match( '/\.is-fl-alt-1,\s*\.is-fl-alt-2 \{\s*--fl-contour-fond: var\(--fl-alt-fond\);/', $fluffless_lean ), 'Texte contour : remplissage au fond de l’alternance.' );
$fluffless_assert( strpos( $fluffless_lean, '.is-fl-alt-1' ) < strpos( $fluffless_lean, '.has-surface-background-color' ), 'Texte contour : une couleur de fond choisie passe après l’alternance.' );
$fluffless_assert( strlen( $fluffless_lean ) < 1024, 'Texte contour : toujours sous 1 ko (' . strlen( $fluffless_lean ) . ' o).' );

// Panneaux de la Couverture : aucune position forcée, le réglage Position du contenu du bloc décide
// (centre-centre ne pose aucune classe, une règle :not([class*="is-position-"]) l'empêcherait).
foreach ( array( 'photo-panneau', 'photo-panneau-accent' ) as $fluffless_slug ) {
	$fluffless_assert( ! str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/assets/block-styles/' . $fluffless_slug . '.css' ), 'is-position-' ), "Feuille {$fluffless_slug} : la position du contenu reste au bloc." ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local de l'extension.
}

// Feuilles des blocs : la proportion fixée par une composition (Équipe, Carte, Carrousel, Logos) cède
// au réglage Proportion natif de l'image, écrit en style en ligne ; aucune ne doit le forcer.
foreach ( array_filter( (array) glob( dirname( __DIR__ ) . '/blocks/*/style.css' ), 'is_string' ) as $fluffless_sheet ) {
	$fluffless_assert( 1 !== preg_match( '/aspect-ratio\s*:[^;}]*!important/', (string) file_get_contents( $fluffless_sheet ) ), 'Feuille ' . basename( dirname( $fluffless_sheet ) ) . ' : proportion sans !important.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local de l'extension.
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Styles de structure et d'image de Suite : {$fluffless_checks} vérifications passées." );
