<?php
/**
 * Tests du bloc Mots-clés qui défilent (module Expressivité) : attributs bornés et listes fermées,
 * injections refusées, vraie liste et copie masquée aux lecteurs d'écran, bouton Pause sans script
 * (WCAG 2.2.2), rien qui bouge hors de no-preference, liste simple à l'impression, poids, CSS
 * chargé seulement si utilisé.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/bandeau-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * L'enregistrement du bloc est remis à son état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\Bandeau;
use Fluffless\Suite\Blocks\Flottant;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Expression;
use Fluffless\Suite\Modules\Palette;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Bandeau::class ) || ! isset( Loader::modules()['expression'] ) ) {
	throw new RuntimeException( 'Bloc Mots-clés qui défilent absent : classe Blocks\Bandeau ou module Expressivité manquant.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};

$fluffless_registry   = WP_Block_Type_Registry::get_instance();
$fluffless_registered = $fluffless_registry->is_registered( Bandeau::NAME );
$fluffless_handles    = static function (): array {
	$handles = array( Bandeau::STYLE );
	foreach ( Bandeau::LOOKS as $look ) {
		$handles[] = Bandeau::STYLE . '-' . $look;
	}
	return $handles;
};

try {
	// 1. Attributs : défauts.
	$fluffless_a = Bandeau::attributes( array() );
	$fluffless_assert( count( $fluffless_a['items'] ) >= Bandeau::MIN_ITEMS, 'mots par défaut : au moins MIN_ITEMS' );
	$fluffless_assert( 'dot' === $fluffless_a['separator'], 'séparateur par défaut : dot' );
	$fluffless_assert( 'medium' === $fluffless_a['speed'], 'vitesse par défaut : medium' );
	$fluffless_assert( 'left' === $fluffless_a['direction'], 'sens par défaut : left' );
	$fluffless_assert( 'accent' === $fluffless_a['look'], 'allure par défaut : accent' );
	$fluffless_assert( 'medium' === $fluffless_a['size'], 'taille par défaut : medium' );
	$fluffless_assert( '' === $fluffless_a['colour'], 'couleur par défaut : automatique' );

	// 2. Listes fermées et bornes.
	$fluffless_a = Bandeau::attributes(
		array(
			'items'     => 'pas un tableau',
			'separator' => '"}body{display:none',
			'speed'     => 'warp',
			'direction' => 'up',
			'look'      => 'neon',
			'size'      => 'colossal',
			'colour'    => 'red;background:url(x)',
			'align'     => 'left',
			'className' => 'ok "><script>',
		)
	);
	$fluffless_assert( Bandeau::attributes( array() )['items'] === $fluffless_a['items'], 'items non tableau : mots par défaut' );
	$fluffless_assert( 'dot' === $fluffless_a['separator'] && 'medium' === $fluffless_a['speed'] && 'left' === $fluffless_a['direction'], 'séparateur, vitesse et sens hors liste : défauts' );
	$fluffless_assert( 'accent' === $fluffless_a['look'] && 'medium' === $fluffless_a['size'], 'allure et taille hors liste : défauts' );
	$fluffless_assert( '' === $fluffless_a['colour'], 'couleur hors palette : automatique' );
	$fluffless_assert( '' === $fluffless_a['align'], 'alignement hors wide et full : aucun' );
	$fluffless_assert( ! str_contains( $fluffless_a['className'], '<' ) && ! str_contains( $fluffless_a['className'], '"' ), 'className assaini' );

	foreach ( array(
		'separator' => array_keys( Bandeau::SEPARATORS ),
		'speed'     => array_keys( Bandeau::SPEEDS ),
		'direction' => Bandeau::DIRECTIONS,
		'look'      => Bandeau::LOOKS,
		'size'      => Bandeau::SIZES,
	) as $fluffless_key => $fluffless_values ) {
		foreach ( $fluffless_values as $fluffless_value ) {
			$fluffless_assert( Bandeau::attributes( array( $fluffless_key => $fluffless_value ) )[ $fluffless_key ] === $fluffless_value, "{$fluffless_key} {$fluffless_value} accepté" );
		}
	}

	$fluffless_a = Bandeau::attributes(
		array(
			'items' => array_merge(
				array( '  Stratégie  ', '', array( 'x' ), 42, '<b>Design</b>', str_repeat( 'é', 200 ) ),
				array_fill( 0, 30, 'Web' )
			),
		)
	);
	$fluffless_assert( Bandeau::MAX_ITEMS === count( $fluffless_a['items'] ), 'mots bornés à MAX_ITEMS' );
	$fluffless_assert( 'Stratégie' === $fluffless_a['items'][0], 'mot rogné de ses espaces' );
	$fluffless_assert( 'Design' === $fluffless_a['items'][1], 'entrées vides et non textuelles retirées, balises retirées' );
	$fluffless_assert( Bandeau::MAX_ITEM === mb_strlen( $fluffless_a['items'][2] ), 'mot borné à MAX_ITEM caractères' );
	$fluffless_defaults = Bandeau::attributes( array() )['items'];
	$fluffless_assert( Bandeau::attributes( array( 'items' => array( 'Seul' ) ) )['items'] === $fluffless_defaults, 'moins de MIN_ITEMS mots valides : mots par défaut' );

	// 3. Durée : bornée, proportionnelle au texte, plus courte quand la vitesse monte.
	$fluffless_short = array( 'a', 'b' );
	$fluffless_long  = array_fill( 0, 12, str_repeat( 'm', 60 ) );
	foreach ( array_keys( Bandeau::SPEEDS ) as $fluffless_speed ) {
		foreach ( array( $fluffless_short, $fluffless_long ) as $fluffless_items ) {
			$fluffless_d = Bandeau::duration( $fluffless_items, $fluffless_speed );
			$fluffless_assert( $fluffless_d >= Bandeau::MIN_DURATION && $fluffless_d <= Bandeau::MAX_DURATION, "durée bornée ({$fluffless_speed})" );
		}
	}
	$fluffless_mid = array_fill( 0, 6, 'Douze lettres' );
	$fluffless_assert( Bandeau::duration( $fluffless_mid, 'slow' ) > Bandeau::duration( $fluffless_mid, 'medium' ) && Bandeau::duration( $fluffless_mid, 'medium' ) > Bandeau::duration( $fluffless_mid, 'fast' ), 'slow plus long que medium, medium plus long que fast' );
	$fluffless_assert( Bandeau::duration( $fluffless_mid, 'warp' ) === Bandeau::duration( $fluffless_mid, 'medium' ), 'vitesse inconnue : medium' );

	// 4. Rendu : vraie liste, copie masquée, séparateurs décoratifs, texte échappé.
	$fluffless_html = Bandeau::render(
		array(
			'items'     => array( 'Stratégie', 'Design <i>sobre</i>', '"Web" & co' ),
			'separator' => 'star',
			'direction' => 'right',
			'size'      => 'giant',
			'align'     => 'full',
		)
	);
	$fluffless_assert( 2 === substr_count( $fluffless_html, '<ul' ), 'deux listes : la vraie et sa copie' );
	$fluffless_assert( 1 === substr_count( $fluffless_html, '<ul class="fl-bd-list" aria-hidden="true"' ), 'la copie est masquée aux lecteurs d\'écran' );
	$fluffless_assert( 1 === preg_match( '/<ul class="fl-bd-list">/', $fluffless_html ), 'la vraie liste n\'est pas masquée' );
	$fluffless_assert( 6 === substr_count( $fluffless_html, '<li>' ), 'trois mots dans chaque liste' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<i>' ) && str_contains( $fluffless_html, '&quot;Web&quot; &amp; co' ), 'mots échappés, balises retirées' );
	$fluffless_assert( 6 === substr_count( $fluffless_html, '<span class="fl-bd-sep" aria-hidden="true">' . Bandeau::SEPARATORS['star'] . '</span>' ), 'séparateur décoratif après chaque mot' );
	$fluffless_assert( str_contains( $fluffless_html, '--fl-b-r:reverse' ) && str_contains( $fluffless_html, '--fl-b-s:' . Bandeau::FONT_SIZES['giant'] ) && str_contains( $fluffless_html, 'is-look-accent' ) && str_contains( $fluffless_html, 'alignfull' ), 'sens et taille en variables, classes de l\'allure et de l\'alignement' );
	$fluffless_assert( 1 === preg_match( '/--fl-b-d:\d+s[;"]/', $fluffless_html ), 'durée en secondes entières dans la variable' );
	$fluffless_assert( str_contains( $fluffless_html, '--fl-b-bg:var(--wp--preset--color--accent)' ), 'allure accent sans couleur : fond accent' );
	$fluffless_assert( 1 === preg_match( '/--fl-b-fg:var\(--wp--preset--color--[a-z0-9-]+\)/', $fluffless_html ), 'texte en variable de palette' );
	$fluffless_plain = Bandeau::render( array( 'direction' => 'left' ) );
	$fluffless_assert( ! str_contains( $fluffless_plain, '--fl-b-r' ) && ! str_contains( $fluffless_plain, '--fl-b-s' ), 'sens left et taille medium : défauts de la feuille, aucune variable' );
	$fluffless_assert( array() === array_diff( Bandeau::SIZES, array_keys( Bandeau::FONT_SIZES ), array( 'medium' ) ) && array() === array_diff( array_keys( Bandeau::FONT_SIZES ), Bandeau::SIZES ), 'un corps par taille hors défaut' );

	// 5. Bouton Pause sans script : case à cocher nommée par son libellé, avant la bande.
	$fluffless_assert( 1 === preg_match( '/<input type="checkbox" id="(fl-bandeau-[a-f0-9]{8}(?:-\d+)?)" class="fl-bd-box"><div class="fl-bd-band">.+<\/div><label for="\1" class="fl-bd-pause">[^<]+<\/label><\/div>$/s', $fluffless_html ), 'case à cocher avant la bande, libellé lié après' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ) && ! str_contains( $fluffless_html, 'onclick' ), 'aucun script' );
	preg_match( '/id="(fl-bandeau-[a-f0-9]{8}(?:-\d+)?)"/', $fluffless_html, $fluffless_first );
	preg_match( '/id="(fl-bandeau-[a-f0-9]{8}(?:-\d+)?)"/', Bandeau::render( array() ), $fluffless_second );
	$fluffless_assert( isset( $fluffless_first[1], $fluffless_second[1] ) && $fluffless_first[1] !== $fluffless_second[1], 'identifiant unique par bandeau' );

	// 6. Couleur : fond et texte appariés pour accent, couleur du texte pour les autres allures.
	$fluffless_hex = Flottant::palette_hex();
	foreach ( array_keys( $fluffless_hex ) as $fluffless_slug ) {
		$fluffless_out = Bandeau::render( array( 'colour' => $fluffless_slug ) );
		$fluffless_ok  = 1 === preg_match( '/--fl-b-bg:var\(--wp--preset--color--([a-z0-9-]+)\);--fl-b-fg:var\(--wp--preset--color--([a-z0-9-]+)\)/', $fluffless_out, $fluffless_m );
		$fluffless_assert( $fluffless_ok && $fluffless_m[1] === $fluffless_slug, "fond {$fluffless_slug} rendu" );
		$fluffless_best = 0.0;
		foreach ( $fluffless_hex as $fluffless_other ) {
			$fluffless_best = max( $fluffless_best, Palette::ratio( $fluffless_hex[ $fluffless_slug ], $fluffless_other ) );
		}
		if ( $fluffless_ok && isset( $fluffless_hex[ $fluffless_m[2] ] ) && $fluffless_best >= 4.5 ) {
			$fluffless_assert( Palette::ratio( $fluffless_hex[ $fluffless_slug ], $fluffless_hex[ $fluffless_m[2] ] ) >= 4.5, "texte sur {$fluffless_slug} sous 4,5:1" );
		}
	}
	foreach ( array( 'outline', 'stroke' ) as $fluffless_look ) {
		$fluffless_out = Bandeau::render( array( 'look' => $fluffless_look ) );
		$fluffless_assert( ! str_contains( $fluffless_out, '--fl-b-bg' ) && ! str_contains( $fluffless_out, '--fl-b-fg' ), "allure {$fluffless_look} sans couleur : couleur du texte héritée" );
		$fluffless_out = Bandeau::render(
			array(
				'look'   => $fluffless_look,
				'colour' => 'contrast',
			)
		);
		$fluffless_assert( str_contains( $fluffless_out, '--fl-b-fg:var(--wp--preset--color--contrast)' ) && ! str_contains( $fluffless_out, '--fl-b-bg' ), "allure {$fluffless_look} : la couleur est celle du texte" );
	}
	$fluffless_out = Bandeau::render( array( 'colour' => 'red;} body{display:none' ) );
	$fluffless_assert( ! str_contains( $fluffless_out, 'display:none' ) && ! str_contains( $fluffless_out, 'red' ), 'injection CSS par la couleur refusée' );

	// 7. Extrait : la liste seule, ni copie ni bouton (le texte ne serait pas doublé).
	$fluffless_plain = '';
	$fluffless_probe = static function () use ( &$fluffless_plain ): string {
		$fluffless_plain = Bandeau::render( array() );
		return '';
	};
	add_filter( 'get_the_excerpt', $fluffless_probe );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur, appelé pour simuler un extrait.
	apply_filters( 'get_the_excerpt', '', null );
	remove_filter( 'get_the_excerpt', $fluffless_probe );
	$fluffless_assert( 1 === substr_count( (string) $fluffless_plain, '<ul' ) && ! str_contains( (string) $fluffless_plain, '<input' ), 'extrait : une seule liste, aucun bouton' );

	// 8. CSS : poids, mouvement, impression, focus, garde-fous.
	$fluffless_css = Bandeau::css();
	foreach ( Bandeau::LOOKS as $fluffless_look ) {
		$fluffless_bare = strlen( Bandeau::css( $fluffless_look, false ) );
		$fluffless_full = strlen( Bandeau::css( $fluffless_look ) );
		$fluffless_assert( $fluffless_bare < 1100, "CSS de l'allure {$fluffless_look} sans le bouton : {$fluffless_bare} o, budget 1100" );
		$fluffless_assert( $fluffless_full < 1450, "CSS de l'allure {$fluffless_look} avec le bouton : {$fluffless_full} o, budget 1450" );
		$fluffless_assert( $fluffless_full < strlen( $fluffless_css ) && $fluffless_bare < $fluffless_full, "CSS de l'allure {$fluffless_look} : seulement sa part" );
		$fluffless_assert( str_contains( Bandeau::css( $fluffless_look, false ), '.is-look-' . $fluffless_look ), "règle de l'allure {$fluffless_look}" );
	}
	$fluffless_still = preg_replace( '/@media screen and \(prefers-reduced-motion:no-preference\)\{(?:[^{}]*\{(?:[^{}]|\{[^{}]*\})*\})*\}/', '', $fluffless_css );
	$fluffless_assert( is_string( $fluffless_still ) && strlen( $fluffless_still ) < strlen( $fluffless_css ), 'bloc no-preference présent, limité à l\'écran' );
	$fluffless_assert( is_string( $fluffless_still ) && ! str_contains( $fluffless_still, 'animation' ) && ! str_contains( $fluffless_still, '@keyframes' ), 'aucune animation hors de no-preference' );
	$fluffless_assert( is_string( $fluffless_still ) && str_contains( $fluffless_still, '.fl-bd-list+.fl-bd-list,.fl-bd-box,.fl-bd-pause{display:none}' ), 'sans mouvement et à l\'impression : copie et bouton masqués' );
	$fluffless_assert( is_string( $fluffless_still ) && str_contains( $fluffless_still, 'flex-wrap:wrap' ), 'sans mouvement : la ligne peut se replier' );
	$fluffless_assert( is_string( $fluffless_still ) && str_contains( $fluffless_still, 'padding:0 1rem;' ), 'sans mouvement : marge intérieure, les mots ne touchent pas les bords' );
	$fluffless_assert( str_contains( $fluffless_css, 'justify-content:space-around;padding:0}' ), 'en mouvement : liste sans marge intérieure, défilement au pixel près' );
	$fluffless_assert( str_contains( $fluffless_css, 'overflow:hidden' ), 'bande en overflow hidden' );
	$fluffless_assert( str_contains( $fluffless_css, 'translateX(-50%)' ) && str_contains( $fluffless_css, 'linear infinite' ), 'défilement de 0 à -50 %, linéaire et infini' );
	$fluffless_assert( str_contains( $fluffless_css, 'var(--fl-b-r,normal)' ), 'sens de l\'animation par variable, normal par défaut' );
	$fluffless_assert( str_contains( $fluffless_css, '.fl-bandeau:is(:hover,:focus-within) .fl-bd-track' ), 'pause au survol et au focus' );
	$fluffless_assert( str_contains( $fluffless_css, ',:checked~.fl-bd-band>*{animation-play-state:paused}' ) && str_contains( $fluffless_css, 'animation-play-state:paused' ), 'case cochée : animation en pause' );
	$fluffless_assert( str_contains( $fluffless_css, ':focus-visible~.fl-bd-pause{outline:' ), 'focus visible sur le bouton Pause' );
	$fluffless_assert( str_contains( $fluffless_css, 'min-height:44px' ), 'cible tactile de 44 px' );
	$fluffless_assert( ! str_contains( Bandeau::css( 'accent', false ), ':checked' ), 'sans le bouton : aucune règle de case cochée' );
	$fluffless_assert( ! str_contains( $fluffless_css, 'list-style:none' ) && ! str_contains( $fluffless_css, 'list-style-type:none' ), 'jamais list-style none (rôle de liste dans Safari)' );
	$fluffless_assert( str_contains( Bandeau::css( 'accent' ), '@media print' ), 'allure accent : fond retiré à l\'impression' );
	$fluffless_assert( str_contains( Bandeau::css( 'stroke' ), '@supports' ) && str_contains( Bandeau::css( 'stroke' ), '@media screen and (forced-colors:none)' ), 'allure stroke : sous @supports, à l\'écran et hors couleurs forcées seulement' );
	$fluffless_assert( ! str_contains( $fluffless_css, 'url(' ) && ! str_contains( $fluffless_css, '@import' ) && ! str_contains( $fluffless_css, 'position:fixed' ) && 0 === preg_match( '/#[0-9a-f]{3,8}\b/i', $fluffless_css ), 'aucun url(), @import, position fixed ni couleur en dur' );
	$fluffless_assert( str_contains( Bandeau::editor_css(), 'animation:none' ), 'éditeur : aperçu immobile' );

	// 9. CSS chargé seulement si le bloc est rendu, base et allure séparées.
	foreach ( $fluffless_handles() as $fluffless_handle ) {
		wp_dequeue_style( $fluffless_handle );
		wp_deregister_style( $fluffless_handle );
	}
	$fluffless_assert( ! wp_style_is( Bandeau::STYLE, 'enqueued' ), 'CSS chargé sans le bloc' );
	Bandeau::render( array( 'look' => 'outline' ) );
	$fluffless_assert( wp_style_is( Bandeau::STYLE, 'enqueued' ) && wp_style_is( Bandeau::STYLE . '-outline', 'enqueued' ), 'base et allure outline chargées après un rendu' );
	$fluffless_assert( ! wp_style_is( Bandeau::STYLE . '-stroke', 'enqueued' ) && ! wp_style_is( Bandeau::STYLE . '-accent', 'enqueued' ), 'allures non utilisées non chargées' );
	Bandeau::render( array( 'look' => 'stroke' ) );
	$fluffless_assert( wp_style_is( Bandeau::STYLE . '-stroke', 'enqueued' ), 'seconde allure de la page chargée à son tour' );

	// 10. Bloc réel et block.json aligné sur les constantes.
	if ( ! $fluffless_registered ) {
		Expression::register_block();
	}
	$fluffless_assert( $fluffless_registry->is_registered( Bandeau::NAME ), 'bloc fluffless/bandeau non enregistré' );
	$fluffless_html = do_blocks( '<!-- wp:fluffless/bandeau {"items":["Un","Deux","Trois"],"separator":"arrow","look":"outline"} /-->' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-look-outline' ) && 6 === substr_count( $fluffless_html, '<li>' ) && str_contains( $fluffless_html, Bandeau::SEPARATORS['arrow'] ), 'do_blocks : rendu du bloc' );
	$fluffless_json = wp_json_file_decode( dirname( __DIR__ ) . '/blocks/bandeau/block.json', array( 'associative' => true ) );
	foreach ( array(
		'separator' => array_keys( Bandeau::SEPARATORS ),
		'speed'     => array_keys( Bandeau::SPEEDS ),
		'direction' => Bandeau::DIRECTIONS,
		'look'      => Bandeau::LOOKS,
		'size'      => Bandeau::SIZES,
	) as $fluffless_key => $fluffless_values ) {
		$fluffless_enum = $fluffless_json['attributes'][ $fluffless_key ]['enum'] ?? null;
		$fluffless_assert( $fluffless_enum === $fluffless_values, "block.json : {$fluffless_key} aligné" );
	}
	$fluffless_assert( 'array' === ( $fluffless_json['attributes']['items']['type'] ?? null ) && 'string' === ( $fluffless_json['attributes']['items']['items']['type'] ?? null ), 'block.json : items est un tableau de chaînes' );
	$fluffless_assert( false === ( $fluffless_json['supports']['html'] ?? null ) && array( 'wide', 'full' ) === ( $fluffless_json['supports']['align'] ?? null ), 'block.json : html false, align wide et full' );
	$fluffless_data = Bandeau::editor_data();
	$fluffless_assert( Bandeau::SEPARATORS === $fluffless_data['separators'] && Bandeau::FONT_SIZES === $fluffless_data['sizes'] && Bandeau::MAX_ITEMS === $fluffless_data['maxItems'] && Bandeau::MIN_ITEMS === $fluffless_data['minItems'] && Bandeau::MAX_ITEM === $fluffless_data['maxItem'], 'données de l\'éditeur : mêmes bornes que le rendu' );
	$fluffless_assert( Bandeau::attributes( array() )['items'] === $fluffless_data['defaults'], 'données de l\'éditeur : mêmes mots par défaut' );
} finally {
	if ( ! $fluffless_registered && $fluffless_registry->is_registered( Bandeau::NAME ) ) {
		unregister_block_type( Bandeau::NAME );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Mots-clés qui défilent : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Mots-clés qui défilent : {$fluffless_checks} vérifications passées." );
