<?php
/**
 * Tests des huit styles typographiques de la Suite (index mono, sur-titre, géant, texte contour,
 * dégradé dans le texte, mot manuscrit, surligneur, étiquette verticale) et du panneau d'options.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/styles-typo-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 *
 * @package Fluffless_Suite
 */

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

// Slug => [ types de bloc, libellé en_US, fragments attendus dans la feuille sans commentaires ].
$fluffless_typo = array(
	'index-mono'          => array( array( 'core/heading' ), 'Mono index', array( 'counter-reset: fluffless-index', 'counter-increment: fluffless-index', 'decimal-leading-zero', '--wp--preset--font-family--mono', '/ ""', '.fl-index-slash', '.fl-index-dot' ) ),
	'sur-titre'           => array( array( 'core/heading', 'core/paragraph' ), 'Eyebrow', array( 'font-size: var(--wp--preset--font-size--small, 0.875rem)', 'letter-spacing', 'text-transform: uppercase', '--wp--preset--font-family--mono' ) ),
	'geant'               => array( array( 'core/heading' ), 'Oversized', array( 'clamp(', 'vw', 'rem +', 'line-height: 0.95', 'text-wrap: balance', 'overflow-wrap: anywhere' ) ),
	'texte-contour'       => array( array( 'core/heading' ), 'Outlined text', array( '@supports (-webkit-text-stroke:', 'paint-order: stroke fill', '--fl-contour-fond, var(--wp--preset--color--base)', '.has-surface-background-color', '@media print', ' em', 'a:hover', 'a:focus-visible', 'forced-colors: active' ) ),
	'degrade-texte'       => array( array( 'core/heading' ), 'Gradient text', array( '@supports (', 'background-clip: text', '--wp--preset--color--accent)', '--wp--preset--color--accent-2)', '.fl-degrade-diagonal', '.fl-degrade-vertical', 'forced-colors: active', '@media print' ) ),
	'mot-manuscrit'       => array( array( 'core/heading' ), 'Handwritten word', array( ' em', '"Snell Roundhand", "Segoe Script", "Apple Chancery", "Brush Script MT", var(--wp--preset--font-family--serif, serif)', 'font-style: normal', 'font-weight: 400' ) ),
	'surligneur'          => array( array( 'core/heading', 'core/paragraph' ), 'Highlighter', array( 'em', 'mark', 'linear-gradient(', '--wp--preset--color--accent', 'box-decoration-break: clone', 'font-style: normal' ) ),
	'etiquette-verticale' => array( array( 'core/group' ), 'Vertical label', array( '> p:first-child', '@media (min-width: 782px)', 'writing-mode: vertical-rl', 'display: grid', '--wp--preset--font-family--mono', '--wp--preset--font-size--small' ) ),
);

$fluffless_registry = WP_Block_Styles_Registry::get_instance();
$fluffless_labels   = BlockStyles::labels();
$fluffless_by_slug  = array();
foreach ( BlockStyles::all() as $fluffless_def ) {
	$fluffless_by_slug[ $fluffless_def['slug'] ] = $fluffless_def;
}
$fluffless_handles = array_map( array( BlockStyles::class, 'handle' ), array_keys( $fluffless_typo ) );
array_map( 'wp_dequeue_style', $fluffless_handles );

$fluffless_tag    = static fn( string $block ): string => match ( $block ) {
	'core/heading'   => 'h2',
	'core/paragraph' => 'p',
	default          => 'div',
};
$fluffless_render = static function ( string $block, string $class_name ) use ( $fluffless_tag ): string {
	$tag    = $fluffless_tag( $block );
	$markup = '<' . $tag . ' class="' . esc_attr( $class_name ) . '">Un <em>mot</em></' . $tag . '>';
	return render_block(
		array(
			'blockName'    => $block,
			'attrs'        => '' === $class_name ? array() : array( 'className' => $class_name ),
			'innerBlocks'  => array(),
			'innerHTML'    => $markup,
			'innerContent' => array( $markup ),
		)
	);
};

/*
 * Sans le style, aucune des huit feuilles ne se charge.
 */
foreach ( array( 'core/heading', 'core/paragraph', 'core/group' ) as $fluffless_block ) {
	$fluffless_render( $fluffless_block, '' );
	$fluffless_render( $fluffless_block, 'is-style-inconnu fl-index-slash fl-degrade-vertical' );
}
$fluffless_loaded = array_filter( $fluffless_handles, static fn( string $handle ): bool => wp_style_is( $handle, 'enqueued' ) );
$fluffless_assert( array() === $fluffless_loaded, 'Sans style typographique, aucune feuille chargée : ' . implode( ', ', $fluffless_loaded ) );

foreach ( $fluffless_typo as $fluffless_slug => list( $fluffless_types, $fluffless_label, $fluffless_needles ) ) {
	$fluffless_assert( isset( $fluffless_by_slug[ $fluffless_slug ] ) && $fluffless_types === $fluffless_by_slug[ $fluffless_slug ]['blockTypes'], "Style {$fluffless_slug} défini pour ses types de blocs." );
	$fluffless_assert( ( $fluffless_labels[ $fluffless_slug ] ?? null ) === $fluffless_label && ( $fluffless_by_slug[ $fluffless_slug ]['title'] ?? null ) === $fluffless_label, "Style {$fluffless_slug} : libellé en_US « {$fluffless_label} » dans labels() et dans le JSON." );
	foreach ( $fluffless_types as $fluffless_block ) {
		$fluffless_assert( is_array( $fluffless_registry->get_registered( $fluffless_block, $fluffless_slug ) ), "Style {$fluffless_slug} enregistré pour {$fluffless_block}." );
	}

	$fluffless_raw  = is_readable( BlockStyles::css_path( $fluffless_slug ) ) ? (string) file_get_contents( BlockStyles::css_path( $fluffless_slug ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_lean = BlockStyles::strip_comments( $fluffless_raw );
	$fluffless_assert( '' !== $fluffless_lean && strlen( $fluffless_lean ) < 1024, "Feuille {$fluffless_slug} sous 1 ko sans commentaires (" . strlen( $fluffless_lean ) . ' o).' );
	$fluffless_assert( str_contains( $fluffless_lean, '.is-style-' . $fluffless_slug ), "Feuille {$fluffless_slug} ciblée par sa classe." );
	$fluffless_assert( ! str_contains( $fluffless_lean, 'url(' ) && ! str_contains( $fluffless_lean, '@import' ) && ! str_contains( $fluffless_lean, '@font-face' ) && ! str_contains( $fluffless_lean, '<script' ), "Feuille {$fluffless_slug} sans image, import, police chargée ni script." );
	$fluffless_assert( 1 !== preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_lean ), "Feuille {$fluffless_slug} sans couleur HEX." );
	$fluffless_assert( ! str_contains( $fluffless_lean, 'animation' ) && ! str_contains( $fluffless_lean, 'transition' ), "Feuille {$fluffless_slug} immobile : ni animation ni transition." );
	$fluffless_assert( ! str_contains( $fluffless_lean, 'position: absolute' ) && ! str_contains( $fluffless_lean, 'position: fixed' ), "Feuille {$fluffless_slug} sans position absolue : rien ne recouvre le contenu." );
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

// Le contour et le dégradé ne s'appliquent que sous @supports : sans lui, le texte reste plein et lisible.
foreach ( array( 'texte-contour', 'degrade-texte', 'surligneur' ) as $fluffless_slug ) {
	$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( $fluffless_slug ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( str_starts_with( $fluffless_lean, '@supports' ) && 1 !== preg_match( '/^\.is-style/m', $fluffless_lean ), "Feuille {$fluffless_slug} : aucune règle hors @supports ou @media (repli = texte normal)." );
}
// Le géant garde une part en rem dans son clamp : le zoom du texte agit encore (WCAG 1.4.4).
$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( 'geant' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fluffless_assert( 1 === preg_match( '/font-size: clamp\([\d.]+rem, [\d.]+rem \+ [\d.]+vw, [\d.]+rem\)/', $fluffless_lean ), 'Géant : clamp( rem, rem + vw, rem ).' );

/*
 * Panneau d'options : une source (BlockStyles::STYLE_OPTIONS), chaque valeur hors défaut a sa règle,
 * les données passées au script nomment le style, ses blocs et la tête de classe de chaque groupe.
 */
$fluffless_panels = array();
foreach ( BlockStyles::panels_data() as $fluffless_panel ) {
	$fluffless_panels[ $fluffless_panel['style'] ] = $fluffless_panel;
}
$fluffless_assert( array( 'forme-accent', 'index-mono', 'degrade-texte' ) === array_slice( array_keys( $fluffless_panels ), 0, 3 ), 'panels_data() rend d’abord les trois panneaux du lot typographique : forme d’accent, index mono, dégradé dans le texte.' );
$fluffless_heads = array(
	'index-mono'    => array( 'format' => 'fl-index-' ),
	'degrade-texte' => array( 'angle' => 'fl-degrade-' ),
	'forme-accent'  => array(
		'shape' => 'fl-accent-shape-',
		'place' => 'fl-accent-place-',
		'size'  => 'fl-accent-size-',
		'alpha' => 'fl-accent-alpha-',
	),
);
foreach ( $fluffless_heads as $fluffless_slug => $fluffless_expected ) {
	$fluffless_panel = $fluffless_panels[ $fluffless_slug ] ?? array(
		'blocks' => array(),
		'groups' => array(),
	);
	$fluffless_assert( $fluffless_by_slug[ $fluffless_slug ]['blockTypes'] === $fluffless_panel['blocks'], "Panneau {$fluffless_slug} : offert sur les blocs du style." );
	$fluffless_assert( array_map( static fn( array $group ): string => $group['head'], $fluffless_panel['groups'] ) === $fluffless_expected, "Panneau {$fluffless_slug} : têtes de classe attendues." );
	$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( $fluffless_slug ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	foreach ( $fluffless_panel['groups'] as $fluffless_group => $fluffless_data ) {
		$fluffless_assert( BlockStyles::STYLE_OPTIONS[ $fluffless_slug ]['options'][ $fluffless_group ] === $fluffless_data['values'], "Panneau {$fluffless_slug} : valeurs de {$fluffless_group} égales à la constante." );
		$fluffless_assert( ! str_contains( $fluffless_lean, '.' . $fluffless_data['head'] . $fluffless_data['values'][0] ), "Panneau {$fluffless_slug} : le défaut de {$fluffless_group} n’écrit aucune classe." );
		foreach ( array_slice( $fluffless_data['values'], 1 ) as $fluffless_value ) {
			$fluffless_assert( str_contains( $fluffless_lean, '.is-style-' . $fluffless_slug . '.' . $fluffless_data['head'] . $fluffless_value ), "Panneau {$fluffless_slug} : règle pour {$fluffless_group} {$fluffless_value}." );
		}
	}
}
$fluffless_assert( array( 'brackets', 'slash', 'dot' ) === ( $fluffless_panels['index-mono']['groups']['format']['values'] ?? null ) && array( 'horizontal', 'diagonal', 'vertical' ) === ( $fluffless_panels['degrade-texte']['groups']['angle']['values'] ?? null ), 'Défauts : [01] pour l’index, horizontal pour le dégradé.' );

// Le script du panneau lit les panneaux de PHP et ne garde aucune liste de valeurs de son côté.
$fluffless_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/block-styles-editor.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fluffless_assert( str_contains( $fluffless_js, 'window.flufflessBlockStyles' ) && str_contains( $fluffless_js, '.panels' ), 'Script du panneau : lit window.flufflessBlockStyles.panels.' );
$fluffless_assert( ! str_contains( $fluffless_js, 'fl-index-' ) && ! str_contains( $fluffless_js, 'fl-degrade-' ) && ! str_contains( $fluffless_js, "'fl-accent-" ), 'Script du panneau : aucun préfixe de classe en dur.' );
foreach ( array_keys( BlockStyles::STYLE_OPTIONS ) as $fluffless_slug ) {
	$fluffless_assert( str_contains( $fluffless_js, "'" . $fluffless_slug . "':" ), "Script du panneau : libellés pour {$fluffless_slug}." );
}
$fluffless_assert( wp_script_is( BlockStyles::EDITOR_SCRIPT, 'registered' ) && ! wp_script_is( BlockStyles::EDITOR_SCRIPT, 'enqueued' ), 'Script du panneau enregistré, jamais chargé sur le site public.' );

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Styles typographiques de Suite : {$fluffless_checks} vérifications passées." );
