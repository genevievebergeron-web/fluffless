<?php
/**
 * Tests des styles de blocs livrés par Suite.
 *
 * Lancer : npm run test:tools
 * (docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/block-styles-test.php)
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 *
 * @package Fluffless_Suite
 */

// Le test filtre des crochets du cœur : ces noms ne peuvent pas porter le préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

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

$fluffless_wrong = array();
add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
add_action(
	'doing_it_wrong_run',
	static function ( string $function_name, string $message ) use ( &$fluffless_wrong ): void {
		$fluffless_wrong[] = $function_name . ' ' . $message;
	},
	10,
	2
);

$fluffless_tmp = static function ( string $content ): string {
	$file = wp_tempnam( 'fluffless-style' );
	file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return $file;
};

/*
 * Lecture valide, puis JSON cassé et slug invalide : rejetés avec le fichier en contexte.
 */
$fluffless_file  = $fluffless_tmp( '{"title":"Probe","slug":"sonde","blockTypes":["core/group"],"styles":{"border":{"radius":"1rem"}}}' );
$fluffless_style = BlockStyles::read( $fluffless_file );
$fluffless_assert( is_array( $fluffless_style ) && 'sonde' === $fluffless_style['slug'] && array( 'core/group' ) === $fluffless_style['blockTypes'], 'Une définition valide doit être lue.' );
$fluffless_assert( '1rem' === ( $fluffless_style['styles']['border']['radius'] ?? null ), 'Les styles sont conservés tels quels.' );
wp_delete_file( $fluffless_file );

$fluffless_file = $fluffless_tmp( '{"slug":"sonde", "blockTypes":' );
$fluffless_assert( null === BlockStyles::read( $fluffless_file ), 'Un JSON cassé doit être rejeté.' );
$fluffless_assert( str_contains( (string) end( $fluffless_wrong ), $fluffless_file ), 'Le rejet nomme le fichier.' );
wp_delete_file( $fluffless_file );

$fluffless_file = $fluffless_tmp( '{"slug":"Pas Bon","blockTypes":["core/group"]}' );
$fluffless_assert( null === BlockStyles::read( $fluffless_file ), 'Un slug avec majuscules ou espaces doit être rejeté.' );
$fluffless_assert( str_contains( (string) end( $fluffless_wrong ), 'Pas Bon' ), 'Le rejet cite la valeur reçue.' );
wp_delete_file( $fluffless_file );

/*
 * Chaque définition du dossier est enregistrée, avec un libellé traduisible et ses styles.
 */
$fluffless_registry = WP_Block_Styles_Registry::get_instance();
$fluffless_labels   = BlockStyles::labels();
$fluffless_slugs    = array();
foreach ( BlockStyles::all() as $fluffless_def ) {
	$fluffless_slug    = $fluffless_def['slug'];
	$fluffless_slugs[] = $fluffless_slug;
	$fluffless_assert( isset( $fluffless_labels[ $fluffless_slug ] ), "Libellé traduisible pour {$fluffless_slug} dans BlockStyles::labels()." );
	foreach ( $fluffless_def['blockTypes'] as $fluffless_block ) {
		$fluffless_registered = $fluffless_registry->get_registered( $fluffless_block, $fluffless_slug );
		$fluffless_assert( is_array( $fluffless_registered ), "Style {$fluffless_slug} enregistré pour {$fluffless_block}." );
		$fluffless_assert( ( $fluffless_registered['style_data'] ?? null ) === $fluffless_def['styles'], "style_data de {$fluffless_slug} transmis pour {$fluffless_block}." );
	}
}
$fluffless_assert( count( $fluffless_slugs ) === count( array_unique( $fluffless_slugs ) ), 'Aucun slug de style en double.' );
$fluffless_assert( array() === array_diff( array_keys( $fluffless_labels ), $fluffless_slugs ), 'Aucun libellé sans définition JSON : ' . implode( ', ', array_diff( array_keys( $fluffless_labels ), $fluffless_slugs ) ) );

/*
 * Chaque feuille appartient à un style, et ne se charge qu'au rendu d'un bloc concerné.
 */
$fluffless_css = glob( dirname( __DIR__ ) . '/assets/block-styles/*.css' );
foreach ( false === $fluffless_css ? array() : $fluffless_css as $fluffless_sheet ) {
	$fluffless_slug = basename( $fluffless_sheet, '.css' );
	$fluffless_assert( in_array( $fluffless_slug, $fluffless_slugs, true ), "Feuille orpheline : {$fluffless_slug}.css n'a pas de définition JSON." );
}
$fluffless_handles = array();
foreach ( BlockStyles::all() as $fluffless_def ) {
	if ( is_readable( BlockStyles::css_path( $fluffless_def['slug'] ) ) ) {
		$fluffless_handles[] = BlockStyles::handle( $fluffless_def['slug'] );
	}
}
array_map( 'wp_dequeue_style', $fluffless_handles );
render_block(
	array(
		'blockName'    => 'core/group',
		'attrs'        => array( 'className' => 'is-style-inconnu' ),
		'innerBlocks'  => array(),
		'innerHTML'    => '<div class="wp-block-group"></div>',
		'innerContent' => array( '<div class="wp-block-group"></div>' ),
	)
);
$fluffless_loaded = array_filter( $fluffless_handles, static fn( string $handle ): bool => wp_style_is( $handle, 'enqueued' ) );
$fluffless_assert( array() === $fluffless_loaded, 'Un groupe sans style de Suite ne charge aucune feuille : ' . implode( ', ', $fluffless_loaded ) );
$fluffless_assert( '.a{b:c}' === BlockStyles::strip_comments( "/* x\n y */\n.a{b:c}\n\n/* z */" ), 'Les commentaires et lignes vides sont retirés.' );

foreach ( BlockStyles::all() as $fluffless_def ) {
	if ( ! is_readable( BlockStyles::css_path( $fluffless_def['slug'] ) ) ) {
		continue;
	}
	$fluffless_handle = BlockStyles::handle( $fluffless_def['slug'] );
	$fluffless_block  = $fluffless_def['blockTypes'][0];
	$fluffless_class  = 'is-style-' . $fluffless_def['slug'];
	// Bouton et Image sont dynamiques en WP 7.1 : sans lien ou sans image, leur rendu est vide et rien ne se charge.
	$fluffless_markup = match ( $fluffless_block ) {
		'core/button' => '<div class="wp-block-button ' . $fluffless_class . '"><a class="wp-block-button__link wp-element-button" href="/x">x</a></div>',
		'core/image'  => '<figure class="wp-block-image ' . $fluffless_class . '"><img src="/x.webp" alt=""/></figure>',
		default       => '<div class="' . $fluffless_class . '"></div>',
	};
	render_block(
		array(
			'blockName'    => $fluffless_block,
			'attrs'        => array( 'className' => $fluffless_class ),
			'innerBlocks'  => array(),
			'innerHTML'    => $fluffless_markup,
			'innerContent' => array( $fluffless_markup ),
		)
	);
	$fluffless_assert( wp_style_is( $fluffless_handle, 'enqueued' ), "Feuille {$fluffless_handle} chargée au rendu de {$fluffless_block}." );
	$fluffless_inline = implode( '', (array) wp_styles()->get_data( $fluffless_handle, 'after' ) );
	$fluffless_assert( '' !== $fluffless_inline && ! str_contains( $fluffless_inline, '/*' ), "Feuille {$fluffless_handle} en ligne et sans commentaires." );
}

/*
 * Carte recto verso : sans lien ni bouton dedans, :focus-within ne se déclenche jamais.
 * Le rendu public lui donne tabindex="0", role="group" et le titre comme nom accessible.
 */
$fluffless_card = '<div class="wp-block-group is-style-retournee"><h3>Recto <em>vif</em></h3><p>Verso</p></div>';
$fluffless_out  = BlockStyles::focusable( $fluffless_card );
$fluffless_tags = new WP_HTML_Tag_Processor( $fluffless_out );
$fluffless_tags->next_tag();
$fluffless_assert( '0' === $fluffless_tags->get_attribute( 'tabindex' ), 'La carte recto verso doit recevoir tabindex="0".' );
$fluffless_assert( 'group' === $fluffless_tags->get_attribute( 'role' ), 'La carte focalisable est un groupe (role="group").' );
$fluffless_assert( 'Recto vif' === $fluffless_tags->get_attribute( 'aria-label' ), 'Le nom accessible de la carte est le texte de son titre.' );
$fluffless_assert( 1 === substr_count( $fluffless_out, 'tabindex' ), 'Seul le conteneur de la carte reçoit tabindex, pas ses faces.' );
$fluffless_linked = '<div class="wp-block-group is-style-retournee"><h3>Recto</h3><p><a href="/x">Lien</a></p></div>';
$fluffless_assert( BlockStyles::focusable( $fluffless_linked ) === $fluffless_linked, 'Avec un lien dedans, la carte ne reçoit pas d\'arrêt de tabulation de plus.' );
$fluffless_plain = '<div class="wp-block-group is-style-carte"><p>x</p></div>';
$fluffless_assert( BlockStyles::focusable( $fluffless_plain ) === $fluffless_plain, 'Un groupe sans le style recto verso reste intact.' );
$fluffless_set = '<div class="wp-block-group is-style-retournee" tabindex="-1"><p>x</p></div>';
$fluffless_assert( BlockStyles::focusable( $fluffless_set ) === $fluffless_set, 'Un tabindex déjà posé n\'est pas écrasé.' );
$fluffless_rendered = render_block(
	array(
		'blockName'    => 'core/group',
		'attrs'        => array( 'className' => 'is-style-retournee' ),
		'innerBlocks'  => array(),
		'innerHTML'    => $fluffless_card,
		'innerContent' => array( $fluffless_card ),
	)
);
$fluffless_assert( str_contains( $fluffless_rendered, 'tabindex="0"' ), 'Le rendu de core/group passe par BlockStyles::focusable().' );

/*
 * Mouvement (étape 4 du plan de parité) : quatre styles de bouton, deux survols, trois entrées.
 * Tout en CSS ; chaque feuille pèse moins de 1 ko une fois ses commentaires retirés ; les entrées
 * au défilement ne bougent qu'avec animation-timeline et sans mouvement réduit ; les survols
 * répondent aussi au clavier ; les couleurs viennent de la palette, jamais en HEX.
 */
$fluffless_motion  = array(
	'pixel'       => array( 'core/button' ),
	'retro'       => array( 'core/button', 'core/group', 'fluffless/carte' ),
	'inverse'     => array( 'core/button' ),
	'souligne'    => array( 'core/button' ),
	'souleve'     => array( 'core/group', 'core/columns', 'core/column', 'core/image' ),
	'zoom-survol' => array( 'core/image', 'core/cover' ),
	'apparait'    => array( 'core/group', 'core/columns', 'core/image', 'core/heading' ),
	'glisse'      => array( 'core/group', 'core/columns', 'core/image', 'core/heading' ),
	'grandit'     => array( 'core/group', 'core/columns', 'core/image', 'core/heading' ),
	'reduit'      => array( 'core/group' ),
);
$fluffless_labels  = BlockStyles::labels();
$fluffless_by_slug = array();
foreach ( BlockStyles::all() as $fluffless_def ) {
	$fluffless_by_slug[ $fluffless_def['slug'] ] = $fluffless_def;
}
$fluffless_assert( 58 === count( $fluffless_slugs ), 'Suite livre 58 styles de blocs (11 avant le mouvement, 9 avec, 1 de navigation, 1 bande défilante, 1 flèche de défilement, 1 index d’ancres, 1 forme d’accent, 8 typographiques, 9 de structure et de carte, 6 d’image, 2 de couverture, 4 survols de liens, 2 survols de bouton, 2 d’entête) : ' . count( $fluffless_slugs ) );
// Forme d'accent : un pseudo-élément, une règle par option hors défaut, panneau d'éditeur nourri par PHP.
$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( BlockStyles::ACCENT_STYLE ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fluffless_assert( str_contains( $fluffless_lean, '::before' ) && str_contains( $fluffless_lean, 'pointer-events:none' ) && str_contains( $fluffless_lean, '--wp--preset--color--accent' ) && str_contains( $fluffless_lean, '@media print' ), 'Forme d’accent : pseudo-élément décoratif en couleur accent, absent à l’impression.' );
$fluffless_assert( ! str_contains( $fluffless_lean, 'url(' ) && ! str_contains( $fluffless_lean, '<script' ) && 1 !== preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_lean ) && strlen( $fluffless_lean ) < 2048, 'Forme d’accent : sans image, script ni couleur fixe, sous 2 ko (' . strlen( $fluffless_lean ) . ' o).' );
foreach ( BlockStyles::ACCENT_OPTIONS as $fluffless_group => $fluffless_values ) {
	foreach ( array_slice( $fluffless_values, 1 ) as $fluffless_value ) {
		$fluffless_assert( str_contains( $fluffless_lean, '.is-style-forme-accent.' . BlockStyles::ACCENT_PREFIX . $fluffless_group . '-' . $fluffless_value ), "Forme d’accent : règle pour {$fluffless_group} {$fluffless_value}." );
	}
}
$fluffless_accent = current( array_filter( BlockStyles::panels_data(), static fn( array $panel ): bool => BlockStyles::ACCENT_STYLE === $panel['style'] ) );
$fluffless_assert( is_array( $fluffless_accent ) && array_keys( BlockStyles::ACCENT_OPTIONS ) === array_keys( $fluffless_accent['groups'] ) && BlockStyles::ACCENT_PREFIX . 'shape-' === $fluffless_accent['groups']['shape']['head'] && BlockStyles::ACCENT_OPTIONS['shape'] === $fluffless_accent['groups']['shape']['values'] && in_array( 'core/group', $fluffless_accent['blocks'], true ) && in_array( 'fluffless/appel-action', $fluffless_accent['blocks'], true ), 'Forme d’accent : données du panneau (style, groupes, tête de classe, valeurs, blocs dont le groupe et l’appel à l’action).' );
$fluffless_assert( wp_script_is( BlockStyles::EDITOR_SCRIPT, 'registered' ) && ! wp_script_is( BlockStyles::EDITOR_SCRIPT, 'enqueued' ), 'Forme d’accent : script du panneau enregistré, pas chargé hors éditeur.' );
$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( 'fleche-defilement' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fluffless_assert( str_contains( $fluffless_lean, 'prefers-reduced-motion: no-preference' ) && str_contains( $fluffless_lean, 'animation-play-state: paused' ) && str_contains( $fluffless_lean, ':focus-visible' ) && strlen( $fluffless_lean ) < 1024, 'Flèche de défilement : immobile en mouvement réduit, en pause au survol et au focus, sous 1 ko.' );
$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( 'ancres' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fluffless_assert( str_contains( $fluffless_lean, 'position: sticky' ) && str_contains( $fluffless_lean, 'min-height: 44px' ) && ! str_contains( $fluffless_lean, '<script' ) && strlen( $fluffless_lean ) < 1024 && 1 !== preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_lean ), 'Index d’ancres : collé en haut, cibles de 44 px, sans script ni couleur fixe, sous 1 ko.' );
$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( 'bande' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fluffless_assert( str_contains( $fluffless_lean, 'prefers-reduced-motion: no-preference' ) && str_contains( $fluffless_lean, 'animation-play-state: paused' ) && str_contains( $fluffless_lean, ':focus-within' ), 'Bande défilante : immobile en mouvement réduit, en pause au survol et au focus clavier.' );
// C3-2-01 : bouton Pause sans script (WCAG 2.2.2), même mécanisme que le bloc Bandeau. Une bande de
// textes seuls n'a rien de focalisable : sans la case, aucune pause au clavier.
$fluffless_assert( str_contains( $fluffless_lean, '.fl-bande-box:checked ~ *' ) && str_contains( $fluffless_lean, '.fl-bande-pause' ) && str_contains( $fluffless_lean, 'min-height: 44px' ), 'Bande défilante : case Pause qui arrête la bande, cible de 44 px.' );
$fluffless_band = render_block(
	array(
		'blockName'    => 'core/group',
		'attrs'        => array( 'className' => 'is-style-bande' ),
		'innerBlocks'  => array(),
		'innerHTML'    => '<div class="wp-block-group is-style-bande"><p>Un</p><p>Deux</p></div>',
		'innerContent' => array( '<div class="wp-block-group is-style-bande"><p>Un</p><p>Deux</p></div>' ),
	)
);
$fluffless_box  = 1 === preg_match( '#^<div class="[^"]*is-style-bande[^"]*"><input type="checkbox" id="(fl-bande-[0-9a-f]+[0-9-]*)" class="fl-bande-box"><p>Un</p><p>Deux</p><label for="([^"]+)" class="fl-bande-pause">[^<]+</label></div>$#', $fluffless_band, $fluffless_ids );
$fluffless_assert( $fluffless_box && $fluffless_ids[2] === $fluffless_ids[1], 'Bande défilante : case en premier enfant, libellé Pause lié en dernier : ' . $fluffless_band );
$fluffless_plain = '<div class="wp-block-group"><p>Un</p></div>';
$fluffless_assert( BlockStyles::band_pause( $fluffless_plain ) === $fluffless_plain, 'Groupe sans le style : rien d’ajouté.' );
foreach ( $fluffless_motion as $fluffless_slug => $fluffless_types ) {
	$fluffless_assert( isset( $fluffless_by_slug[ $fluffless_slug ] ) && $fluffless_types === $fluffless_by_slug[ $fluffless_slug ]['blockTypes'], "Style {$fluffless_slug} défini pour ses types de blocs." );
	$fluffless_assert( isset( $fluffless_labels[ $fluffless_slug ] ), "Style {$fluffless_slug} libellé." );
	$fluffless_raw  = is_readable( BlockStyles::css_path( $fluffless_slug ) ) ? (string) file_get_contents( BlockStyles::css_path( $fluffless_slug ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_lean = BlockStyles::strip_comments( $fluffless_raw );
	$fluffless_assert( '' !== $fluffless_lean && strlen( $fluffless_lean ) < 1024, "Feuille {$fluffless_slug} sous 1 ko sans commentaires (" . strlen( $fluffless_lean ) . ' o).' );
	$fluffless_assert( 1 !== preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_lean ), "Feuille {$fluffless_slug} sans couleur HEX." );
	$fluffless_assert( str_contains( $fluffless_lean, 'is-style-' . $fluffless_slug ), "Feuille {$fluffless_slug} ciblée par sa classe." );
	$fluffless_assert( ! str_contains( $fluffless_lean, '<script' ) && ! str_contains( $fluffless_lean, 'url(' ), "Feuille {$fluffless_slug} sans script ni ressource externe." );
}
foreach ( array( 'apparait', 'glisse', 'grandit' ) as $fluffless_slug ) {
	$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( $fluffless_slug ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( str_contains( $fluffless_lean, '@supports (animation-timeline: view())' ) && str_contains( $fluffless_lean, 'prefers-reduced-motion: no-preference' ), "Entrée {$fluffless_slug} gardée par @supports et le mouvement réduit." );
	$fluffless_assert( str_contains( $fluffless_lean, "@keyframes fluffless-{$fluffless_slug}" ) && str_contains( $fluffless_lean, 'opacity: 1' ), "Entrée {$fluffless_slug} finit visible." );
}
$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( 'reduit' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fluffless_assert( str_contains( $fluffless_lean, 'header.wp-block-template-part:has(> .is-style-reduit)' ) && str_contains( $fluffless_lean, 'position: sticky' ), 'En-tête réduit : <header> colle quand son groupe racine porte le style.' );
$fluffless_assert( str_contains( $fluffless_lean, '@supports (animation-timeline: scroll())' ) && str_contains( $fluffless_lean, 'prefers-reduced-motion: no-preference' ) && str_contains( $fluffless_lean, 'animation-timeline: scroll(root)' ), 'En-tête réduit : resserrement piloté par le défilement, gardé par @supports et le mouvement réduit.' );
/**
 * Règles de focus d'une feuille : au moins une règle porte le sélecteur voulu, déclare quelque chose
 * et n'éteint jamais le contour (outline: none / 0). Avec $pair, la même règle doit citer aussi ce sélecteur.
 * Ne prouve que le texte des feuilles; le rendu au clavier est vérifié au navigateur (outils/k6-focus.mjs).
 */
$fluffless_focus_rules_ok = static function ( string $css, string $selector, string $pair = '' ): bool {
	$found = false;
	if ( false === preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
		return false;
	}
	foreach ( $rules as $rule ) {
		if ( ! str_contains( $rule[1], $selector ) ) {
			continue;
		}
		if ( '' !== $pair && ! str_contains( $rule[1], $pair ) ) {
			continue;
		}
		if ( '' === trim( $rule[2] ) || 1 === preg_match( '/outline(-style)?\s*:\s*(none|0)\b/i', $rule[2] ) ) {
			return false;
		}
		$found = true;
	}
	return $found;
};
foreach ( array( 'pixel', 'retro', 'inverse', 'souligne' ) as $fluffless_slug ) {
	$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( $fluffless_slug ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( str_contains( $fluffless_lean, ':focus-visible' ), "Bouton {$fluffless_slug} : l'effet répond aussi au clavier." );
	$fluffless_assert( $fluffless_focus_rules_ok( $fluffless_lean, ':focus-visible' ), "Bouton {$fluffless_slug} : la règle de focus déclare un style et n'enlève pas le contour." );
	$fluffless_assert( ! str_contains( $fluffless_lean, 'gradient(' ) || 'souligne' === $fluffless_slug, "Bouton {$fluffless_slug} sans dégradé animé." );
}
foreach ( array( 'souleve', 'zoom-survol' ) as $fluffless_slug ) {
	$fluffless_lean = BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( $fluffless_slug ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fluffless_assert( str_contains( $fluffless_lean, ':focus-within' ), "Survol {$fluffless_slug} : l'effet répond aussi au focus clavier." );
	$fluffless_assert( $fluffless_focus_rules_ok( $fluffless_lean, ':focus-within', ':hover' ), "Survol {$fluffless_slug} : le focus clavier partage la règle du survol (même effet) et n'enlève pas le contour." );
}
$fluffless_assert( str_contains( BlockStyles::strip_comments( (string) file_get_contents( BlockStyles::css_path( 'pixel' ) ) ), 'outline-offset: -0.35em' ), 'Le bouton pixel garde un anneau de focus visible dans sa découpe.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

$fluffless_other_theme = static fn(): string => 'twentytwentyfive';
add_filter( 'template', $fluffless_other_theme );
$fluffless_assert( false === Patterns::applies(), 'Sous un autre thème, Suite n\'enregistre aucun style.' );
remove_filter( 'template', $fluffless_other_theme );

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} vérifications :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Styles de blocs de Suite : {$fluffless_checks} vérifications passées (" . count( $fluffless_slugs ) . ' styles).' );
