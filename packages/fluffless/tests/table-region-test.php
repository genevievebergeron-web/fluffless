<?php
/**
 * Tests du repère défilant des tableaux : la figure du bloc Tableau devient une région
 * atteignable au clavier et nommée par sa légende, sinon « Table » (ou le nom donné par le
 * filtre fluffless_table_region_label), numéroté à partir du deuxième tableau sans légende.
 * Attributs déjà présents gardés.
 *
 * Lancer : docker compose run --rm -T cli wp eval-file wp-content/themes/fluffless/tests/table-region-test.php
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

// Les contrôles lisent les textes en en_US : indépendants de la langue du site.
switch_to_locale( 'en_US' );

if ( 'fluffless' !== get_template() ) {
	throw new RuntimeException( 'Thème Fluffless inactif : wp theme activate fluffless.' );
}

$fluffless_failures = array();
$fluffless_checks   = 0;
$fluffless_assert   = static function ( bool $condition, string $message ) use ( &$fluffless_failures, &$fluffless_checks ): void {
	++$fluffless_checks;
	if ( ! $condition ) {
		$fluffless_failures[] = $message;
	}
};
$fluffless_table    = '<table><tbody><tr><td>1</td><td>2</td></tr></tbody></table>';
$fluffless_render   = static function ( string $figure_attrs = '', string $caption = '' ) use ( $fluffless_table ): string {
	$figcaption = '' === $caption ? '' : '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
	return do_blocks( '<!-- wp:table --><figure class="wp-block-table"' . $figure_attrs . '>' . $fluffless_table . $figcaption . '</figure><!-- /wp:table -->' );
};
// Attributs de la première balise portant ce nom.
$fluffless_attrs = static function ( string $html, string $tag ): array {
	$tags = new WP_HTML_Tag_Processor( $html );
	if ( ! $tags->next_tag( array( 'tag_name' => $tag ) ) ) {
		return array();
	}
	$out = array();
	foreach ( array( 'id', 'tabindex', 'role', 'aria-label', 'aria-labelledby', 'class' ) as $name ) {
		$value = $tags->get_attribute( $name );
		if ( null !== $value ) {
			$out[ $name ] = $value;
		}
	}
	return $out;
};

// 1. Sans légende : région focalisable nommée « Table ».
$fluffless_html   = $fluffless_render();
$fluffless_figure = $fluffless_attrs( $fluffless_html, 'FIGURE' );
$fluffless_assert( '0' === ( $fluffless_figure['tabindex'] ?? '' ) && 'region' === ( $fluffless_figure['role'] ?? '' ), 'sans légende : tabindex ou role ' . wp_json_encode( $fluffless_figure ) );
$fluffless_assert( 'Table' === ( $fluffless_figure['aria-label'] ?? '' ) && ! isset( $fluffless_figure['aria-labelledby'] ), 'sans légende : nom ' . wp_json_encode( $fluffless_figure ) );
$fluffless_assert( str_contains( $fluffless_html, $fluffless_table ), 'tableau modifié' );

// 2. Avec légende : aria-labelledby vers la légende, qui reçoit un id stable ; deux tableaux
// identiques sur la page ont des ids distincts.
$fluffless_html    = $fluffless_render( '', 'Prix 2026' );
$fluffless_figure  = $fluffless_attrs( $fluffless_html, 'FIGURE' );
$fluffless_caption = $fluffless_attrs( $fluffless_html, 'FIGCAPTION' );
$fluffless_assert( isset( $fluffless_caption['id'] ) && 1 === preg_match( '/^fluffless-table-[a-f0-9]{8}$/', (string) $fluffless_caption['id'] ), 'légende : id ' . wp_json_encode( $fluffless_caption ) );
$fluffless_assert( ( $fluffless_caption['id'] ?? 'x' ) === ( $fluffless_figure['aria-labelledby'] ?? '' ) && ! isset( $fluffless_figure['aria-label'] ), 'légende : aria-labelledby ' . wp_json_encode( $fluffless_figure ) );
$fluffless_again = $fluffless_attrs( $fluffless_render( '', 'Prix 2026' ), 'FIGCAPTION' );
$fluffless_assert( ( $fluffless_caption['id'] ?? '' ) . '-2' === ( $fluffless_again['id'] ?? '' ), 'légende répétée : id ' . wp_json_encode( $fluffless_again ) );
$fluffless_assert( ( $fluffless_caption['id'] ?? '' ) !== ( $fluffless_attrs( $fluffless_render( '', 'Prix 2027' ), 'FIGCAPTION' )['id'] ?? '' ), 'légende différente, même id' );
// Légende avec un id (ancre) : gardé, et c'est lui qui nomme.
$fluffless_html   = do_blocks( '<!-- wp:table --><figure class="wp-block-table">' . $fluffless_table . '<figcaption class="wp-element-caption" id="prix">Prix</figcaption></figure><!-- /wp:table -->' );
$fluffless_figure = $fluffless_attrs( $fluffless_html, 'FIGURE' );
$fluffless_assert( 'prix' === ( $fluffless_figure['aria-labelledby'] ?? '' ) && 'prix' === ( $fluffless_attrs( $fluffless_html, 'FIGCAPTION' )['id'] ?? '' ), 'légende avec id : ' . wp_json_encode( $fluffless_figure ) );

// 3. Filtre fluffless_table_region_label : nom sans légende ; sans effet avec une légende.
$fluffless_label_hook = static function ( string $label, array $block ): string {
	return 'Tarifs (' . $label . ', ' . ( $block['blockName'] ?? '' ) . ')';
};
add_filter( 'fluffless_table_region_label', $fluffless_label_hook, 10, 2 );
$fluffless_assert( 1 === preg_match( '/^Tarifs \(Table( \d+)?, core\/table\)$/', (string) ( $fluffless_attrs( $fluffless_render(), 'FIGURE' )['aria-label'] ?? '' ) ), 'filtre : nom non pris' );
$fluffless_figure = $fluffless_attrs( $fluffless_render( '', 'Légende' ), 'FIGURE' );
$fluffless_assert( ! isset( $fluffless_figure['aria-label'] ) && isset( $fluffless_figure['aria-labelledby'] ), 'filtre : la légende devrait primer' );
remove_filter( 'fluffless_table_region_label', $fluffless_label_hook );

// 4. Attributs déjà présents : gardés tels quels, rien d'ajouté par-dessus.
$fluffless_figure = $fluffless_attrs( $fluffless_render( ' tabindex="-1" role="group" aria-label="Mon tableau"' ), 'FIGURE' );
$fluffless_assert( '-1' === ( $fluffless_figure['tabindex'] ?? '' ) && 'group' === ( $fluffless_figure['role'] ?? '' ) && 'Mon tableau' === ( $fluffless_figure['aria-label'] ?? '' ), 'attributs présents modifiés : ' . wp_json_encode( $fluffless_figure ) );
$fluffless_figure = $fluffless_attrs( $fluffless_render( ' aria-labelledby="titre"' ), 'FIGURE' );
$fluffless_assert( 'titre' === ( $fluffless_figure['aria-labelledby'] ?? '' ) && ! isset( $fluffless_figure['aria-label'] ) && '0' === ( $fluffless_figure['tabindex'] ?? '' ), 'aria-labelledby présent : ' . wp_json_encode( $fluffless_figure ) );

// 5. Un enfant retire le filtre : rendu du cœur intact.
remove_filter( 'render_block_core/table', 'fluffless_table_scroll_region' );
$fluffless_assert( array( 'class' => 'wp-block-table' ) === $fluffless_attrs( $fluffless_render(), 'FIGURE' ), 'filtre retiré : attributs encore posés' );
add_filter( 'render_block_core/table', 'fluffless_table_scroll_region', 10, 2 );

// 6. Deux tableaux sans légende sur la même page (S5-05) : deux repères de noms distincts,
// le premier garde « Table », les suivants sont numérotés.
$fluffless_html  = do_blocks( str_repeat( '<!-- wp:table --><figure class="wp-block-table">' . $fluffless_table . '</figure><!-- /wp:table -->', 2 ) );
$fluffless_names = array();
$fluffless_tags  = new WP_HTML_Tag_Processor( $fluffless_html );
while ( $fluffless_tags->next_tag( array( 'tag_name' => 'FIGURE' ) ) ) {
	$fluffless_names[] = (string) $fluffless_tags->get_attribute( 'aria-label' );
}
$fluffless_assert( 2 === count( $fluffless_names ) && 2 === count( array_unique( $fluffless_names ) ), 'deux tableaux sans légende : noms en double ' . wp_json_encode( $fluffless_names ) );
$fluffless_assert( 1 === preg_match( '/^Table \d+$/', $fluffless_names[1] ?? '' ), 'second tableau sans légende : nom non numéroté ' . wp_json_encode( $fluffless_names ) );

if ( array() !== $fluffless_failures ) {
	WP_CLI::error( 'table-region-test : ' . count( $fluffless_failures ) . " échec(s) sur {$fluffless_checks} :\n - " . implode( "\n - ", $fluffless_failures ) );
}
WP_CLI::success( "table-region-test : {$fluffless_checks} vérifications passées." );
