<?php
/**
 * Title: Key figures, adjustable
 * Slug: fluffless/section-chiffres
 * Categories: fluffless-prouver
 * Keywords: numbers, statistics, impact, results, proof, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and three large figures side by side, each with one line that says what it counts. Switches in the sidebar set two to four figures or make the numbers compact.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_figures = array(
	array( __( '1,200', 'fluffless-suite' ), __( 'Swap in your real number, then say what it counts: people, hours, meals, files.', 'fluffless-suite' ) ),
	array( __( '92%', 'fluffless-suite' ), __( 'A rate needs its base: 92% of whom, measured how.', 'fluffless-suite' ) ),
	array( __( '14', 'fluffless-suite' ), __( 'Years, regions, partners: always say where the number comes from.', 'fluffless-suite' ) ),
);
?>
<!-- wp:fluffless/chiffres {"align":"wide"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Facts, not adjectives', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"10rem"}} -->
<div class="wp-block-group">
<?php foreach ( $fluffless_figures as $fluffless_figure ) : ?>
<!-- wp:group {"className":"fluffless-chiffres__item"} -->
<div class="wp-block-group fluffless-chiffres__item"><!-- wp:paragraph {"className":"fluffless-chiffres__number"} -->
<p class="fluffless-chiffres__number"><?php echo esc_html( $fluffless_figure[0] ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><?php echo esc_html( $fluffless_figure[1] ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/chiffres -->
