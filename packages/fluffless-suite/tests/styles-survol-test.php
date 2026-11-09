<?php
/**
 * Tests des survols et de l'entête qui se détache : quatre survols de liens (une seule source,
 * LinkHover, partagée avec le réglage du site de la Palette), permutation du libellé, ondulation,
 * ombre rétro étendue aux groupes et aux cartes, capsule flottante et entête qui se détache.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/styles-survol-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Presentation\BlockStyles;
use Fluffless\Suite\Presentation\LabelSwap;
use Fluffless\Suite\Presentation\LinkHover;
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

/*
 * Slug => types de blocs, libellé en_US, fragments attendus dans la feuille, feuille générée ou non.
 */
$fluffless_links  = array( 'core/navigation', 'core/paragraph' );
$fluffless_styles = array(
	'survol-surlignage' => array( $fluffless_links, 'Hover: live highlight', array( 'background-size:100% 100%', 'color-mix(in srgb,var(--wp--preset--color--accent) 40%,transparent)', 'color:inherit' ), true ),
	'survol-contraste'  => array( $fluffless_links, 'Hover: contrast swap', array( 'background:var(--wp--preset--color--contrast)', 'color:var(--wp--preset--color--base)' ), true ),
	'survol-etire'      => array( $fluffless_links, 'Hover: stretching underline', array( 'linear-gradient(currentColor,currentColor)', 'background-size:100% .1em' ), true ),
	'survol-incline'    => array( $fluffless_links, 'Hover: tilt', array( 'display:inline-block', 'transform:skewX(-10deg)' ), true ),
	'permutation'       => array( array( 'core/navigation', 'core/button' ), 'Label swap', array( '@supports (content: "x" / "")', 'content: attr(data-fl-label) / ""', '(prefers-reduced-motion: no-preference) and (forced-colors: none)', 'overflow: hidden', ':focus-visible' ), false ),
	'ondulation'        => array( array( 'core/button' ), 'Ripple', array( '::before', 'border-radius: 50%', 'scale: 0', 'overflow: hidden', 'var(--wp--preset--color--on-accent)', ':focus-visible' ), false ),
	'capsule'           => array( array( 'core/group' ), 'Floating capsule', array( 'header.wp-block-template-part:has(> .is-style-capsule)', 'position: sticky', 'var(--wp-admin--admin-bar--height, 0px)', 'border-radius: var(--wp--custom--radius--round,9999px)', '@supports (backdrop-filter: blur(1px))', 'scroll-padding-top' ), false ),
	'detache'           => array( array( 'core/group' ), 'Detaches on scroll', array( 'header.wp-block-template-part:has(> .is-style-detache)', 'position: sticky', '@supports (animation-timeline: scroll())', 'animation-timeline: scroll(root)', 'prefers-reduced-motion: no-preference', 'border-radius: 9999px', 'scroll-padding-top' ), false ),
);

$fluffless_registry = WP_Block_Styles_Registry::get_instance();
$fluffless_labels   = BlockStyles::labels();
$fluffless_by_slug  = array();
foreach ( BlockStyles::all() as $fluffless_def ) {
	$fluffless_by_slug[ $fluffless_def['slug'] ] = $fluffless_def;
}
$fluffless_handles = array_map( array( BlockStyles::class, 'handle' ), array_keys( $fluffless_styles ) );
array_map( 'wp_dequeue_style', $fluffless_handles );

$fluffless_markup = array(
	'core/navigation' => '<nav class="wp-block-navigation %s"><ul><li><a class="wp-block-navigation-item__content" href="/a"><span class="wp-block-navigation-item__label">Services</span></a></li></ul></nav>',
	'core/paragraph'  => '<p class="%s">Un <a href="/a">lien</a></p>',
	'core/button'     => '<div class="wp-block-button %s"><a class="wp-block-button__link wp-element-button" href="/a">Écrire</a></div>',
	'core/group'      => '<div class="wp-block-group %s"><p>Un mot</p></div>',
);
// core/navigation se rend depuis ses attributs, pas depuis ce HTML : le filtre de chargement est appelé tel que render_block l'appelle.
$fluffless_render = static function ( string $block, string $class_name ) use ( $fluffless_markup ): string {
	$markup = sprintf( $fluffless_markup[ $block ], esc_attr( $class_name ) );
	$parsed = array(
		'blockName'    => $block,
		'attrs'        => '' === $class_name ? array() : array( 'className' => $class_name ),
		'innerBlocks'  => array(),
		'innerHTML'    => $markup,
		'innerContent' => array( $markup ),
	);
	return 'core/navigation' === $block ? (string) apply_filters( 'render_block_core/navigation', BlockStyles::enqueue_used( $markup, $parsed ), $parsed ) : render_block( $parsed ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Filtre du cœur.
};

foreach ( array_keys( $fluffless_markup ) as $fluffless_block ) {
	$fluffless_render( $fluffless_block, '' );
	$fluffless_render( $fluffless_block, 'is-style-inconnu survol-etire' );
}
$fluffless_loaded = array_filter( $fluffless_handles, static fn( string $handle ): bool => wp_style_is( $handle, 'enqueued' ) );
$fluffless_assert( array() === $fluffless_loaded, 'Sans style de survol, aucune feuille chargée : ' . implode( ', ', $fluffless_loaded ) );

foreach ( $fluffless_styles as $fluffless_slug => list( $fluffless_types, $fluffless_label, $fluffless_needles, $fluffless_generated ) ) {
	$fluffless_assert( isset( $fluffless_by_slug[ $fluffless_slug ] ) && $fluffless_types === $fluffless_by_slug[ $fluffless_slug ]['blockTypes'], "Style {$fluffless_slug} défini pour ses types de blocs." );
	$fluffless_assert( ( $fluffless_labels[ $fluffless_slug ] ?? null ) === $fluffless_label && ( $fluffless_by_slug[ $fluffless_slug ]['title'] ?? null ) === $fluffless_label, "Style {$fluffless_slug} : libellé en_US « {$fluffless_label} » dans labels() et dans le JSON." );
	foreach ( $fluffless_types as $fluffless_block ) {
		$fluffless_assert( is_array( $fluffless_registry->get_registered( $fluffless_block, $fluffless_slug ) ), "Style {$fluffless_slug} enregistré pour {$fluffless_block}." );
	}

	$fluffless_assert( is_readable( BlockStyles::css_path( $fluffless_slug ) ) !== $fluffless_generated, "Style {$fluffless_slug} : feuille " . ( $fluffless_generated ? 'générée, sans copie en fichier.' : 'en fichier.' ) );
	$fluffless_lean = BlockStyles::strip_comments( BlockStyles::sheet( $fluffless_slug ) );
	$fluffless_assert( '' !== $fluffless_lean && strlen( $fluffless_lean ) < 1024, "Feuille {$fluffless_slug} sous 1 ko sans commentaires (" . strlen( $fluffless_lean ) . ' o).' );
	$fluffless_assert( str_contains( $fluffless_lean, '.is-style-' . $fluffless_slug ), "Feuille {$fluffless_slug} ciblée par sa classe." );
	$fluffless_assert( ! str_contains( $fluffless_lean, 'url(' ) && ! str_contains( $fluffless_lean, '@import' ) && ! str_contains( $fluffless_lean, '@font-face' ) && ! str_contains( $fluffless_lean, '<script' ), "Feuille {$fluffless_slug} sans image, import, police chargée ni script." );
	$fluffless_assert( 1 !== preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_lean ), "Feuille {$fluffless_slug} sans couleur HEX." );
	$fluffless_assert( ! str_contains( $fluffless_lean, 'position: fixed' ) && ! str_contains( $fluffless_lean, 'position:fixed' ), "Feuille {$fluffless_slug} sans position fixe." );
	// Tout mouvement vit sous « no-preference » : sous mouvement réduit, l'état final s'applique sans transition ni animation.
	$fluffless_still = (string) preg_replace( '/@media \(prefers-reduced-motion: ?no-preference\)[^{]*\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\}/', '', $fluffless_lean );
	// Les survols de liens écrivent la transition une seule fois, avec une durée nulle par défaut (var(--fl-d,0s)) : la durée réelle n'est posée que sous « no-preference ».
	$fluffless_still = str_replace( ' var(--fl-d,0s)', '', $fluffless_still );
	$fluffless_assert( ! str_contains( $fluffless_still, '--fl-d' ) && 1 !== preg_match( '/transition:[^;}]*\d/', $fluffless_still ), "Feuille {$fluffless_slug} : aucune durée de transition hors de prefers-reduced-motion: no-preference." );
	$fluffless_assert( 1 !== preg_match( '/(?<!\()animation(-timeline)?:/', $fluffless_still ), "Feuille {$fluffless_slug} : aucune animation hors de prefers-reduced-motion: no-preference." );
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

/*
 * Survols de liens : une seule source pour le réglage du site et pour les styles de bloc.
 */
$fluffless_assert( 4 === count( LinkHover::effects() ) && array_keys( LinkHover::effects() ) === LinkHover::EFFECTS, 'Survols de liens : quatre effets, chacun avec son libellé.' );
$fluffless_assert( '' === LinkHover::rules( 'pas-un-effet', '.x' ) && '' === LinkHover::rules( '', '.x' ), 'Survol inconnu : aucune règle.' );
$fluffless_assert( '' === LinkHover::rules( 'etire', '.x{}</style><script>' ), 'Portée qui n\'est pas un sélecteur simple : aucune règle.' );
foreach ( LinkHover::EFFECTS as $fluffless_effect ) {
	$fluffless_css = LinkHover::rules( $fluffless_effect, '.is-style-survol-' . $fluffless_effect );
	$fluffless_assert( BlockStyles::sheet( 'survol-' . $fluffless_effect ) === $fluffless_css, "Survol {$fluffless_effect} : la feuille du style de bloc sort de LinkHover." );
	$fluffless_assert( str_contains( $fluffless_css, ' a:not(.wp-element-button,:has(img))' ), "Survol {$fluffless_effect} : ni bouton ni lien d'image." );
	$fluffless_assert( str_contains( $fluffless_css, ':is(:hover,:focus-visible)' ) && ! str_contains( str_replace( ':is(:hover,:focus-visible)', '', $fluffless_css ), ':hover' ), "Survol {$fluffless_effect} : même effet au survol et au focus clavier." );
	$fluffless_assert( str_contains( $fluffless_css, '@media (prefers-reduced-motion:no-preference){:root{--fl-d:var(--wp--custom--transition)}}' ) && 1 === substr_count( $fluffless_css, '--fl-d:' ), "Survol {$fluffless_effect} : durée de transition posée sous no-preference seulement." );
	$fluffless_assert( ! str_contains( $fluffless_css, 'outline' ), "Survol {$fluffless_effect} : l'anneau de focus du thème n'est pas touché." );
	$fluffless_assert( strlen( LinkHover::rules( $fluffless_effect, LinkHover::SITE_SCOPE ) ) < 600, "Survol {$fluffless_effect} : réglage du site sous 600 o." );
	$fluffless_assert( str_contains( LinkHover::rules( $fluffless_effect, LinkHover::SITE_SCOPE ), ',[class*=-survol-] *)' ), "Survol {$fluffless_effect} : le réglage du site laisse un bloc qui porte son propre style de survol (sinon les deux effets se mêlent)." );
	$fluffless_assert( ! str_contains( LinkHover::rules( $fluffless_effect, '.is-style-survol-' . $fluffless_effect ), 'class*=' ), "Survol {$fluffless_effect} : le style de bloc ne s’exclut pas lui-même." );
}

/*
 * Permutation : le libellé est copié dans data-fl-label, échappé, seulement avec le style.
 */
$fluffless_html = $fluffless_render( 'core/button', 'is-style-permutation' );
$fluffless_assert( str_contains( $fluffless_html, 'data-fl-label="Écrire"' ) && 1 === substr_count( $fluffless_html, 'Écrire</a>' ), 'Permutation : libellé du bouton copié dans data-fl-label, texte gardé une fois : ' . $fluffless_html );
$fluffless_assert( ! str_contains( $fluffless_render( 'core/button', '' ), LabelSwap::ATTRIBUTE ) && ! str_contains( $fluffless_render( 'core/button', 'is-style-ondulation' ), LabelSwap::ATTRIBUTE ), 'Permutation : aucun attribut sans le style.' );
$fluffless_html = $fluffless_render( 'core/navigation', 'is-style-permutation' );
$fluffless_assert( str_contains( $fluffless_html, 'href="/a" data-fl-label="Services"' ) || str_contains( $fluffless_html, 'data-fl-label="Services" href="/a"' ) || 1 === preg_match( '/<a [^>]*data-fl-label="Services"/', $fluffless_html ), 'Permutation : libellé du lien de navigation copié : ' . $fluffless_html );
$fluffless_assert( ! str_contains( $fluffless_render( 'core/navigation', '' ), LabelSwap::ATTRIBUTE ), 'Permutation : navigation sans le style intacte.' );
$fluffless_html = LabelSwap::label_links( '<a class="k" href="/a">Un &amp; &quot;deux&quot; &lt;script&gt;alert(1)&lt;/script&gt;</a>', 'k' );
$fluffless_assert( ! str_contains( $fluffless_html, '<script>' ) && 1 === preg_match( '/data-fl-label="Un &amp; &quot;deux&quot; &lt;script&gt;alert\(1\)&lt;\/script&gt;"/', $fluffless_html ), 'Permutation : texte échappé dans l\'attribut : ' . $fluffless_html );
$fluffless_html = LabelSwap::label_links( '<a class="k" href="/a"><strong>Gras</strong> et fin</a><a class="k" href="/b"><img src="/x.webp" alt="Logo"/></a><a class="k" href="/c"> </a><a class="autre" href="/d">Autre</a>', 'k' );
$fluffless_assert( str_contains( $fluffless_html, 'data-fl-label="Gras et fin"' ) && 1 === substr_count( $fluffless_html, LabelSwap::ATTRIBUTE ), 'Permutation : texte à plat copié ; lien d\'image, lien vide et autre classe laissés tels quels : ' . $fluffless_html );
$fluffless_html = LabelSwap::label_links( '<summary class="k">Panneau</summary><button class="k" type="button">Bouton</button>', 'k' );
$fluffless_assert( ! str_contains( $fluffless_html, '<summary class="k" ' ) && str_contains( $fluffless_html, 'data-fl-label="Bouton"' ), 'Permutation : le <summary> d\'un panneau de menu garde sa flèche, un <button> reçoit la copie : ' . $fluffless_html );
$fluffless_assert( str_contains( BlockStyles::sheet( 'permutation' ), '.is-style-permutation [data-fl-label]:not(.has-child > *)' ), 'Permutation : un lien à sous-menu garde son chevron.' );
$fluffless_long = str_repeat( 'mot ', 80 );
$fluffless_assert( ! str_contains( LabelSwap::label_links( '<a class="k" href="/a">' . $fluffless_long . '</a>', 'k' ), LabelSwap::ATTRIBUTE ), 'Permutation : libellé au-delà de ' . LabelSwap::MAX_TEXT . ' caractères laissé tel quel.' );
$fluffless_many = str_repeat( '<a class="k" href="/a">Lien</a>', LabelSwap::MAX_LINKS + 5 );
$fluffless_assert( substr_count( LabelSwap::label_links( $fluffless_many, 'k' ), LabelSwap::ATTRIBUTE ) === LabelSwap::MAX_LINKS, 'Permutation : nombre de liens traités borné à ' . LabelSwap::MAX_LINKS . '.' );
$fluffless_assert( '<a class="k" href="/a">Cassé' === LabelSwap::label_links( '<a class="k" href="/a">Cassé', 'k' ) && '' === LabelSwap::label_links( '', 'k' ), 'Permutation : HTML tronqué ou vide rendu tel quel.' );
$fluffless_lean = BlockStyles::strip_comments( BlockStyles::sheet( 'permutation' ) );
$fluffless_assert( 1 !== preg_match( '/content: attr\(data-fl-label\)(?! \/ "")/', $fluffless_lean ), 'Permutation : la copie porte toujours un texte de remplacement vide, jamais lue deux fois.' );
$fluffless_assert( 0 === strpos( $fluffless_lean, '@supports' ) && 1 !== preg_match( '/^\.is-style/m', $fluffless_lean ), 'Permutation : feuille entière sous @supports, le texte réel reste visible sans prise en charge.' );

/*
 * Ombre décalée : même effet que « Ombre rétro », étendu au groupe et à la carte plutôt que doublé.
 */
$fluffless_assert( ! isset( $fluffless_by_slug['ombre-decalee'] ), 'Ombre décalée : pas de style en double de retro.' );
$fluffless_assert( array( 'core/button', 'core/group', 'fluffless/carte' ) === ( $fluffless_by_slug['retro']['blockTypes'] ?? null ), 'Ombre rétro : bouton, groupe et carte.' );
$fluffless_lean = BlockStyles::strip_comments( BlockStyles::sheet( 'retro' ) );
$fluffless_assert( strlen( $fluffless_lean ) < 1024, 'Ombre rétro : toujours sous 1 ko (' . strlen( $fluffless_lean ) . ' o).' );
$fluffless_assert( str_contains( $fluffless_lean, ':is(.wp-block-group, .fluffless-carte).is-style-retro' ) && str_contains( $fluffless_lean, ':focus-within' ) && str_contains( $fluffless_lean, 'translate:' ), 'Ombre rétro : la face du groupe et de la carte glisse vers l\'ombre au survol et au focus dedans.' );
// Capsule : fond translucide assez opaque pour qu'un lien accent reste AA quand un bloc Contraste passe derrière (3,96:1 à 85 %, 4,57:1 à 92 %).
$fluffless_assert( 1 === preg_match( '/color--base\) (\d+)%, transparent/', BlockStyles::sheet( 'capsule' ), $fluffless_m ) && (int) $fluffless_m[1] >= 92, 'Capsule : fond à 92 % au moins.' );
$fluffless_still = (string) preg_replace( '/@media \(prefers-reduced-motion: ?no-preference\)[^{]*\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\}/', '', $fluffless_lean );
$fluffless_assert( 1 === preg_match_all( '/transition:/', $fluffless_still ) && 2 === preg_match_all( '/transition:/', $fluffless_lean ), 'Ombre rétro : la transition du bouton d\'origine gardée, celle du groupe sous no-preference.' );

/*
 * Entête : la capsule ne coupe pas le panneau de menu, et le focus ne passe pas sous l'entête.
 */
foreach ( array( 'capsule', 'detache' ) as $fluffless_slug ) {
	$fluffless_lean = BlockStyles::strip_comments( BlockStyles::sheet( $fluffless_slug ) );
	$fluffless_assert( ! str_contains( $fluffless_lean, 'overflow' ) && ! str_contains( $fluffless_lean, 'clip-path' ), "Entête {$fluffless_slug} : ni overflow ni clip-path, le panneau de menu et les sous-menus dépassent librement." );
	$fluffless_assert( str_contains( $fluffless_lean, ':has(details[open])' ), "Entête {$fluffless_slug} : rayon ramené quand le panneau de menu est ouvert." );
	$fluffless_assert( 1 === preg_match( '/html:has\(\.is-style-' . $fluffless_slug . '\) \{\s*scroll-padding-top:/', $fluffless_lean ), "Entête {$fluffless_slug} : scroll-padding-top sur la page." );
	$fluffless_assert( 1 === preg_match( '/@media screen \{\s*header\.wp-block-template-part:has\(> \.is-style-' . $fluffless_slug . '\) \{[^}]*position: sticky/', $fluffless_lean ) && 1 === substr_count( $fluffless_lean, 'position: sticky' ), "Entête {$fluffless_slug} : collant à l'écran seulement, en place à l'impression." );
}
$fluffless_lean = BlockStyles::strip_comments( BlockStyles::sheet( 'detache' ) );
$fluffless_assert( 1 === preg_match( '/@media \(prefers-reduced-motion: reduce\) \{[^}]*\{[^}]*border-radius: 9999px/', $fluffless_lean ), 'Entête qui se détache : capsule fixe sous mouvement réduit.' );

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Survols et entête qui se détache de Suite : {$fluffless_checks} vérifications passées." );
