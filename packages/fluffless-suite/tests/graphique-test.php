<?php
/**
 * Tests du bloc Graphique (module Expressivité) : attributs bornés et listes fermées, injections
 * refusées, parts qui totalisent 100, total nul et données invalides rendus vides, dessin masqué
 * aux lecteurs d'écran et vrai tableau visible, style en ligne fait de nombres et de valeurs de
 * listes fermées seulement, couleurs forcées, impression, poids, CSS chargé seulement si utilisé.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/plugins/fluffless-suite/tests/graphique-test.php
 * Une exception non attrapée fait sortir WP-CLI avec un code non nul.
 * L'enregistrement du bloc est remis à son état initial à la fin.
 *
 * @package Fluffless_Suite
 */

use Fluffless\Suite\Blocks\Graphique;
use Fluffless\Suite\Core\Loader;
use Fluffless\Suite\Modules\Expression;

defined( 'ABSPATH' ) || exit;
// Les assertions portent sur les chaînes sources anglaises, pas sur la traduction du site.
switch_to_locale( 'en_US' );

if ( ! class_exists( Graphique::class ) || ! isset( Loader::modules()['expression'] ) ) {
	throw new RuntimeException( 'Bloc Graphique absent : classe Blocks\Graphique ou module Expressivité manquant.' );
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
$fluffless_registered = $fluffless_registry->is_registered( Graphique::NAME );
$fluffless_handles    = static function (): array {
	$handles = array( Graphique::STYLE );
	foreach ( Graphique::TYPES as $type ) {
		$handles[] = Graphique::STYLE . '-' . $type;
	}
	return $handles;
};
// Même format traduit que le rendu : « 40% » en anglais, « 40 % » en français.
$fluffless_percent = static function ( int $share ): string {
	/* translators: %s: share of the total, a whole number from 0 to 100. */
	return sprintf( __( '%s%%', 'fluffless-suite' ), number_format_i18n( $share ) );
};
$fluffless_rows    = array(
	array(
		'label' => 'Hébergement',
		'value' => 40,
	),
	array(
		'label' => 'Images',
		'value' => 35,
	),
	array(
		'label' => 'Scripts',
		'value' => 25,
	),
);

try {
	// 1. Défauts.
	$fluffless_a = Graphique::attributes( array() );
	$fluffless_assert( 'donut' === $fluffless_a['type'] && 'medium' === $fluffless_a['size'] && true === $fluffless_a['showValues'], 'défauts : anneau, taille moyenne, valeurs montrées' );
	$fluffless_assert( array() === $fluffless_a['rows'] && '' === $fluffless_a['title'] && '' === $fluffless_a['unit'], 'défauts : aucune rangée, ni titre ni unité (aucune donnée factice sur le site)' );

	// 2. Listes fermées et bornes.
	foreach ( array( 'type', 'size' ) as $fluffless_key ) {
		$fluffless_bad = Graphique::attributes( array( $fluffless_key => '"><script>' ) );
		$fluffless_assert( $fluffless_bad[ $fluffless_key ] === $fluffless_a[ $fluffless_key ], "{$fluffless_key} hors liste : défaut" );
	}
	foreach ( Graphique::TYPES as $fluffless_type ) {
		$fluffless_assert( Graphique::attributes( array( 'type' => $fluffless_type ) )['type'] === $fluffless_type, "type {$fluffless_type} accepté" );
	}
	foreach ( array_keys( Graphique::SIZES ) as $fluffless_size ) {
		$fluffless_assert( Graphique::attributes( array( 'size' => $fluffless_size ) )['size'] === $fluffless_size, "taille {$fluffless_size} acceptée" );
	}
	$fluffless_long = Graphique::attributes(
		array(
			'title' => str_repeat( 'é', 300 ),
			'unit'  => str_repeat( 'k', 50 ),
			'rows'  => array(
				array(
					'label' => str_repeat( 'à', 90 ),
					'value' => 1,
				),
			),
		)
	);
	$fluffless_assert( Graphique::MAX_TITLE === mb_strlen( $fluffless_long['title'] ) && Graphique::MAX_UNIT === mb_strlen( $fluffless_long['unit'] ) && Graphique::MAX_LABEL === mb_strlen( $fluffless_long['rows'][0]['label'] ), 'titre, unité et étiquette coupés à leur borne, en caractères' );
	$fluffless_many = Graphique::attributes( array( 'rows' => array_fill( 0, 30, $fluffless_rows[0] ) ) );
	$fluffless_assert( Graphique::MAX_ROWS === count( $fluffless_many['rows'] ), 'au plus huit rangées' );
	$fluffless_mixed  = Graphique::attributes(
		array(
			'rows' => array(
				'pas un tableau',
				array( 'label' => 'Sans valeur' ),
				array(
					'label' => '',
					'value' => 4,
				),
				array(
					'label' => 'Négatif',
					'value' => -3,
				),
				array(
					'label' => 'Texte',
					'value' => 'douze',
				),
				array(
					'label' => 'Infini',
					'value' => INF,
				),
				array(
					'label' => 'Tableau',
					'value' => array( 1 ),
				),
				array(
					'label' => 'Chaîne numérique',
					'value' => '12.5',
				),
				array(
					'label' => 'Zéro',
					'value' => 0,
				),
				array(
					'label' => 'Énorme',
					'value' => 1e30,
				),
			),
		)
	);
	$fluffless_labels = array_column( $fluffless_mixed['rows'], 'label' );
	$fluffless_assert( array( 'Chaîne numérique', 'Zéro', 'Énorme' ) === $fluffless_labels, 'rangées invalides écartées (non-tableau, sans valeur, sans étiquette, négatif, texte, infini, tableau) : ' . implode( ', ', $fluffless_labels ) );
	$fluffless_assert( 12.5 === $fluffless_mixed['rows'][0]['value'] && Graphique::MAX_VALUE === $fluffless_mixed['rows'][2]['value'], 'chaîne numérique convertie, valeur plafonnée' );
	$fluffless_assert( false === Graphique::attributes( array( 'showValues' => false ) )['showValues'] && true === Graphique::attributes( array( 'showValues' => 'non' ) )['showValues'], 'showValues : booléen strict, défaut sinon' );
	$fluffless_assert( 'wide' === Graphique::attributes( array( 'align' => 'wide' ) )['align'] && '' === Graphique::attributes( array( 'align' => 'left"' ) )['align'], 'alignement : wide ou full seulement' );

	// 3. Parts : entiers qui totalisent toujours 100.
	foreach ( array(
		array( 1, 1, 1 ),
		array( 40, 35, 25 ),
		array( 1, 1, 1, 1, 1, 1, 1 ),
		array( 0.1, 0.1, 99.8 ),
		array( 1, 0, 2 ),
		array( 1e12, 1, 1 ),
		array( 33.3, 33.3, 33.4, 0.001 ),
		array( 5, 5 ),
	) as $fluffless_values ) {
		$fluffless_shares = Graphique::shares( $fluffless_values );
		$fluffless_assert( 100 === array_sum( $fluffless_shares ) && count( $fluffless_values ) === count( $fluffless_shares ), 'parts de ' . implode( ' / ', $fluffless_values ) . ' : total ' . array_sum( $fluffless_shares ) );
		$fluffless_ints = array_filter( $fluffless_shares, static fn( int $share ): bool => $share >= 0 );
		$fluffless_assert( count( $fluffless_ints ) === count( $fluffless_shares ), 'parts positives (entières par le type de retour)' );
	}
	$fluffless_assert( array( 34, 33, 33 ) === Graphique::shares( array( 1, 1, 1 ) ), 'plus grands restes : le point manquant va aux premières rangées' );
	$fluffless_assert( 0 === Graphique::shares( array( 1, 0, 2 ) )[1], 'valeur nulle : part nulle' );
	$fluffless_assert( array() === Graphique::shares( array( 0, 0 ) ) && array() === Graphique::shares( array() ), 'total nul : aucune part' );

	// 4. Rendu vide et propre : total nul, moins de deux rangées valides, données invalides.
	foreach ( array(
		'aucune rangée'       => array(),
		'une seule rangée'    => array( 'rows' => array( $fluffless_rows[0] ) ),
		'total nul'           => array(
			'rows' => array(
				array(
					'label' => 'A',
					'value' => 0,
				),
				array(
					'label' => 'B',
					'value' => 0,
				),
			),
		),
		'rows pas un tableau' => array( 'rows' => 'x' ),
	) as $fluffless_case => $fluffless_attrs ) {
		foreach ( $fluffless_handles() as $fluffless_handle ) {
			wp_dequeue_style( $fluffless_handle );
			wp_deregister_style( $fluffless_handle );
		}
		$fluffless_assert( '' === Graphique::render( $fluffless_attrs ), "{$fluffless_case} : rendu vide" );
		$fluffless_assert( ! wp_style_is( Graphique::STYLE, 'enqueued' ), "{$fluffless_case} : aucune feuille chargée" );
	}
	$fluffless_assert( '' === Graphique::render( 'pas un tableau' ), 'attributs pas un tableau : rendu vide' );

	// 5. Rendu : figure, légende, dessin masqué, vrai tableau.
	$fluffless_html = Graphique::render(
		array(
			'type'      => 'donut',
			'title'     => 'Poids de la page',
			'unit'      => 'ko',
			'rows'      => $fluffless_rows,
			'align'     => 'wide',
			'className' => 'ma-classe',
		)
	);
	$fluffless_assert( str_starts_with( $fluffless_html, '<figure class="fl-graphique is-type-donut alignwide ma-classe"' ) && str_ends_with( $fluffless_html, '</figure>' ), 'figure avec type, alignement et classe' );
	$fluffless_assert( 1 === substr_count( $fluffless_html, '<figcaption' ) && str_contains( $fluffless_html, '>Poids de la page</figcaption>' ), 'figcaption avec le titre' );
	$fluffless_assert( 1 === preg_match( '/<div class="fl-gr-draw" aria-hidden="true" style="background:conic-gradient\(([^"]+)\)"><\/div>/', $fluffless_html, $fluffless_m ), 'un seul élément de dessin, aria-hidden, conic-gradient' );
	$fluffless_assert( 'var(--g1) 0 40%,var(--g2) 0 75%,var(--g3) 0' === ( $fluffless_m[1] ?? '' ), 'arrêts du dégradé cumulés : ' . ( $fluffless_m[1] ?? '' ) );
	$fluffless_assert( 1 === substr_count( $fluffless_html, 'aria-hidden="true" style="background' ) && 1 === substr_count( $fluffless_html, '<table' ) && ! str_contains( $fluffless_html, 'role=' ), 'un dessin, un tableau natif, aucun rôle ajouté' );
	$fluffless_assert( 3 === substr_count( $fluffless_html, '<th scope="row">' ) && 3 === substr_count( $fluffless_html, '<th scope="col">' ), 'en-têtes de rangée et de colonne' );
	$fluffless_assert( 3 === substr_count( $fluffless_html, '<span class="fl-gr-chip" aria-hidden="true"' ), 'une pastille de couleur décorative par rangée' );
	$fluffless_assert( str_contains( $fluffless_html, "<td>40\u{00A0}ko</td><td>" . esc_html( $fluffless_percent( 40 ) ) . '</td>' ), 'valeur et unité jointes par une espace insécable, part au format traduit' );
	$fluffless_assert( ! str_contains( $fluffless_html, '<script' ) && ! str_contains( $fluffless_html, ' on' ) && ! str_contains( $fluffless_html, 'url(' ), 'aucun script, gestionnaire ni url' );
	$fluffless_none = Graphique::render(
		array(
			'rows'       => $fluffless_rows,
			'showValues' => false,
		)
	);
	$fluffless_assert( ! str_contains( $fluffless_none, '<figcaption' ) && 2 === substr_count( $fluffless_none, '<th scope="col">' ) && str_contains( $fluffless_none, '</th><td>' . esc_html( $fluffless_percent( 40 ) ) . '</td></tr>' ), 'sans titre ni valeurs : pas de figcaption, colonne de part seule' );
	$fluffless_pie = Graphique::render(
		array(
			'type' => 'pie',
			'rows' => $fluffless_rows,
			'size' => 'large',
		)
	);
	$fluffless_assert( str_contains( $fluffless_pie, 'is-type-pie' ) && str_contains( $fluffless_pie, '--fl-g-s:' . Graphique::SIZES['large'] ), 'secteurs : classe du type, taille en variable' );
	$fluffless_assert( ! str_contains( $fluffless_html, '--fl-g-s' ), 'taille moyenne : défaut de la feuille, aucune variable' );

	// 6. Barres : largeur en pourcentage du maximum par une variable numérique.
	$fluffless_bars = Graphique::render(
		array(
			'type' => 'bars',
			'rows' => array(
				array(
					'label' => 'A',
					'value' => 200,
				),
				array(
					'label' => 'B',
					'value' => 50,
				),
				array(
					'label' => 'C',
					'value' => 0,
				),
			),
		)
	);
	$fluffless_assert( ! str_contains( $fluffless_bars, 'conic-gradient' ) && ! str_contains( $fluffless_bars, 'fl-gr-draw' ), 'barres : aucun disque' );
	$fluffless_assert( 3 === preg_match_all( '/<td class="fl-gr-bar" aria-hidden="true"><span style="color:var\(--g\d\);--v:([0-9.]+)"><\/span><\/td>/', $fluffless_bars, $fluffless_m ), 'barres : une cellule décorative par rangée' );
	$fluffless_assert( array( '100', '25', '0' ) === $fluffless_m[1], 'barres : pourcentage du maximum : ' . implode( ', ', $fluffless_m[1] ) );
	$fluffless_assert( str_contains( $fluffless_bars, '<td>200</td><td>' . esc_html( $fluffless_percent( 80 ) ) . '</td>' ), 'barres : valeur et part du total dans le tableau' );

	// 7. Style en ligne : seulement des nombres et des valeurs construites par le bloc.
	$fluffless_evil = Graphique::render(
		array(
			'type'  => 'bars',
			'title' => '<img src=x onerror=alert(1)>Titre',
			'unit'  => '"><b>',
			'rows'  => array(
				array(
					'label' => '<script>alert(1)</script>Un"',
					'value' => '5;background:url(//x)',
				),
				array(
					'label' => 'Deux',
					'value' => '7',
				),
				array(
					'label' => 'Trois',
					'value' => '3e2',
				),
			),
		)
	);
	$fluffless_assert( ! str_contains( $fluffless_evil, '<script' ) && ! str_contains( $fluffless_evil, '<img' ) && ! str_contains( $fluffless_evil, '<b>' ) && ! str_contains( $fluffless_evil, 'onerror' ) && ! str_contains( $fluffless_evil, 'url(' ), 'injections refusées dans le titre, l\'unité, l\'étiquette et la valeur' );
	$fluffless_assert( 2 === substr_count( $fluffless_evil, '<th scope="row">' ), 'valeur non numérique : rangée écartée' );
	preg_match_all( '/style="([^"]*)"/', $fluffless_evil . $fluffless_html . $fluffless_pie, $fluffless_m );
	$fluffless_safe = array_filter( $fluffless_m[1], static fn( string $style ): bool => 1 === preg_match( '/^[a-zA-Z0-9 ,.%();:-]+$/', $style ) );
	$fluffless_assert( count( $fluffless_safe ) === count( $fluffless_m[1] ) && count( $fluffless_m[1] ) > 8, 'tout attribut style tient dans un alphabet fermé (lettres, chiffres, ponctuation CSS)' );

	// 8. Couleurs : séquence fixe de la palette, huit valeurs distinctes, aucune couleur libre.
	$fluffless_colours = Graphique::colours();
	$fluffless_assert( Graphique::MAX_ROWS === count( $fluffless_colours ) && count( array_unique( $fluffless_colours ) ) === count( $fluffless_colours ), 'huit couleurs distinctes' );
	$fluffless_slugs = Expression::palette_slugs();
	foreach ( $fluffless_colours as $fluffless_index => $fluffless_colour ) {
		$fluffless_assert( 1 === preg_match( '/^(var\(--wp--preset--color--[a-z0-9-]+\)|color-mix\(in srgb,(var\(--wp--preset--color--[a-z0-9-]+\)|currentColor) \d+%,(var\(--wp--preset--color--[a-z0-9-]+\)|transparent)\))$/', $fluffless_colour ), "couleur {$fluffless_index} : variable de palette ou teinte par color-mix : {$fluffless_colour}" );
		preg_match_all( '/--color--([a-z0-9-]+)\)/', $fluffless_colour, $fluffless_m );
		$fluffless_assert( array() === array_diff( $fluffless_m[1], $fluffless_slugs ), "couleur {$fluffless_index} : slugs présents dans la palette" );
	}
	$fluffless_assert( str_contains( $fluffless_html, '--g1:' . $fluffless_colours[0] . ';--g2:' ) && ! str_contains( $fluffless_html, '--g4:' ), 'seules les couleurs des rangées rendues sont posées sur la figure' );
	$fluffless_bare = Graphique::colours( array() );
	$fluffless_assert( Graphique::MAX_ROWS === count( array_unique( $fluffless_bare ) ) && ! str_contains( implode( '', $fluffless_bare ), '--wp--preset' ), 'palette sans les slugs attendus : teintes de la couleur du texte, toujours distinctes' );

	// 9. CSS : poids, garde-fous.
	foreach ( Graphique::TYPES as $fluffless_type ) {
		$fluffless_css = Graphique::css( $fluffless_type );
		$fluffless_len = strlen( $fluffless_css );
		$fluffless_assert( $fluffless_len < 1024, "CSS du type {$fluffless_type} : {$fluffless_len} o, budget 1024" );
		$fluffless_assert( 0 === preg_match( '/#[0-9a-f]{3,8}\b|rgb\(|hsl\(|url\(|@import|@font-face|animation|transition|position:fixed/i', $fluffless_css ), "CSS du type {$fluffless_type} : aucune couleur en dur, url, import, police, animation ni position fixed" );
		$fluffless_assert( str_contains( $fluffless_css, 'font-variant-numeric:tabular-nums' ), "CSS du type {$fluffless_type} : chiffres tabulaires" );
	}
	foreach ( array( 'pie', 'donut' ) as $fluffless_type ) {
		$fluffless_css = Graphique::css( $fluffless_type );
		$fluffless_assert( 1 === preg_match( '/@media \(forced-colors:active\)\{[^}]*\.fl-gr-draw[^}]*\{display:none\}/', $fluffless_css ), "CSS du type {$fluffless_type} : dessin masqué en couleurs forcées, le tableau reste" );
		$fluffless_assert( str_contains( $fluffless_css, 'print-color-adjust:exact' ), "CSS du type {$fluffless_type} : couleurs gardées à l'impression" );
	}
	$fluffless_assert( str_contains( Graphique::css( 'donut' ), 'mask:' ) && ! str_contains( Graphique::css( 'pie' ), 'mask:' ), 'anneau : masque, absent des secteurs' );
	$fluffless_assert( str_contains( Graphique::css( 'bars' ), 'calc(var(--v)*1%)' ) && str_contains( Graphique::css( 'bars' ), 'border-top:' ) && ! str_contains( Graphique::css( 'bars' ), 'fl-gr-draw' ), 'barres : largeur par la variable, trait en bordure (visible en couleurs forcées), aucun disque' );
	$fluffless_all = Graphique::css();
	foreach ( Graphique::TYPES as $fluffless_type ) {
		$fluffless_assert( strlen( $fluffless_all ) > strlen( Graphique::css( $fluffless_type ) ), "feuille de l'éditeur plus complète que celle du type {$fluffless_type}" );
	}

	// 10. CSS chargé seulement si le bloc est rendu, base et type séparés.
	foreach ( $fluffless_handles() as $fluffless_handle ) {
		wp_dequeue_style( $fluffless_handle );
		wp_deregister_style( $fluffless_handle );
	}
	Graphique::render(
		array(
			'type' => 'bars',
			'rows' => $fluffless_rows,
		)
	);
	$fluffless_assert( wp_style_is( Graphique::STYLE, 'enqueued' ) && wp_style_is( Graphique::STYLE . '-bars', 'enqueued' ), 'base et type barres chargés après un rendu' );
	$fluffless_assert( ! wp_style_is( Graphique::STYLE . '-pie', 'enqueued' ) && ! wp_style_is( Graphique::STYLE . '-donut', 'enqueued' ), 'types non utilisés non chargés' );

	// 11. Extraits et flux : le tableau seul, sans dessin ni style.
	$fluffless_excerpt                   = static fn(): string => Graphique::render(
		array(
			'title' => 'Titre',
			'rows'  => $GLOBALS['fluffless_graphique_rows'],
		)
	);
	$GLOBALS['fluffless_graphique_rows'] = $fluffless_rows;
	add_filter( 'get_the_excerpt', $fluffless_excerpt, 1 );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur appelé pour placer le rendu dans un extrait.
	$fluffless_html = apply_filters( 'get_the_excerpt', '' );
	remove_filter( 'get_the_excerpt', $fluffless_excerpt, 1 );
	unset( $GLOBALS['fluffless_graphique_rows'] );
	$fluffless_assert( str_contains( $fluffless_html, '<table' ) && ! str_contains( $fluffless_html, 'style=' ) && ! str_contains( $fluffless_html, 'fl-gr-draw' ), 'extrait : tableau seul' );

	// 12. Nombres localisés.
	$fluffless_big = Graphique::render(
		array(
			'rows' => array(
				array(
					'label' => 'A',
					'value' => 1234567,
				),
				array(
					'label' => 'B',
					'value' => 2.5,
				),
			),
		)
	);
	$fluffless_assert( str_contains( $fluffless_big, '<td>' . esc_html( number_format_i18n( 1234567 ) ) . '</td>' ) && str_contains( $fluffless_big, '<td>' . esc_html( number_format_i18n( 2.5, 1 ) ) . '</td>' ), 'valeurs par number_format_i18n, décimales seulement si utiles' );

	// 13. Bloc réel et block.json aligné sur les constantes.
	if ( ! $fluffless_registered ) {
		Expression::register_block();
	}
	$fluffless_assert( $fluffless_registry->is_registered( Graphique::NAME ), 'bloc fluffless/graphique non enregistré' );
	$fluffless_html = do_blocks( '<!-- wp:fluffless/graphique {"type":"pie","rows":[{"label":"Un","value":1},{"label":"Deux","value":3}]} /-->' );
	$fluffless_assert( str_contains( $fluffless_html, 'is-type-pie' ) && str_contains( $fluffless_html, 'var(--g1) 0 25%,var(--g2) 0' ), 'do_blocks : rendu du bloc' );
	$fluffless_json = wp_json_file_decode( dirname( __DIR__ ) . '/blocks/graphique/block.json', array( 'associative' => true ) );
	$fluffless_enum = $fluffless_json['attributes']['type']['enum'] ?? null;
	$fluffless_assert( Graphique::TYPES === $fluffless_enum, 'block.json : type aligné' );
	$fluffless_enum = $fluffless_json['attributes']['size']['enum'] ?? null;
	$fluffless_assert( array_keys( Graphique::SIZES ) === $fluffless_enum, 'block.json : size aligné' );
	$fluffless_assert( 'array' === ( $fluffless_json['attributes']['rows']['type'] ?? null ) && 'object' === ( $fluffless_json['attributes']['rows']['items']['type'] ?? null ), 'block.json : rows est un tableau d\'objets' );
	$fluffless_assert( false === ( $fluffless_json['supports']['html'] ?? null ) && array( 'wide', 'full' ) === ( $fluffless_json['supports']['align'] ?? null ), 'block.json : html false, align wide et full' );
	$fluffless_data = Graphique::editor_data();
	$fluffless_assert( Graphique::colours() === $fluffless_data['colours'] && Graphique::SIZES === $fluffless_data['sizes'] && Graphique::MAX_ROWS === $fluffless_data['maxRows'] && Graphique::MIN_ROWS === $fluffless_data['minRows'] && Graphique::MAX_LABEL === $fluffless_data['maxLabel'], 'données de l\'éditeur : mêmes bornes et mêmes couleurs que le rendu' );
} finally {
	if ( ! $fluffless_registered && $fluffless_registry->is_registered( Graphique::NAME ) ) {
		unregister_block_type( Graphique::NAME );
	}
}

if ( array() !== $fluffless_failures ) {
	throw new RuntimeException( esc_html( "Graphique : échecs (sur {$fluffless_checks} contrôles) :\n- " . implode( "\n- ", $fluffless_failures ) ) );
}
WP_CLI::success( "Graphique : {$fluffless_checks} vérifications passées." );
