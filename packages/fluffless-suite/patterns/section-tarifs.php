<?php
/**
 * Title: Pricing table, adjustable
 * Slug: fluffless/section-tarifs
 * Categories: fluffless-agir
 * Keywords: pricing, plans, prices, offer, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and three plans side by side, the middle one highlighted: name, price, who it is for, what is included, a button. Switches in the sidebar set two to four plans and which one stands out.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_plans    = array(
	array( __( 'Starter', 'fluffless-suite' ), __( '$29 / month', 'fluffless-suite' ), __( 'For one person getting started.', 'fluffless-suite' ) ),
	array( __( 'Team', 'fluffless-suite' ), __( '$79 / month', 'fluffless-suite' ), __( 'For a small team that works together every day.', 'fluffless-suite' ) ),
	array( __( 'Organization', 'fluffless-suite' ), __( '$190 / month', 'fluffless-suite' ), __( 'For several teams, with support on a first-name basis.', 'fluffless-suite' ) ),
);
$fluffless_included = array(
	__( 'What is included, in plain words', 'fluffless-suite' ),
	__( 'One line per thing that matters', 'fluffless-suite' ),
	__( 'Say what is not, when it avoids a call', 'fluffless-suite' ),
);
?>
<!-- wp:fluffless/tarifs {"featured":2,"align":"wide","className":"is-style-forme-accent fl-accent-shape-dot-grid fl-accent-alpha-50"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Clear prices, no surprises', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"14rem"}} -->
<div class="wp-block-group">
<?php foreach ( $fluffless_plans as $fluffless_plan ) : ?>
<!-- wp:group {"className":"fluffless-tarifs__plan"} -->
<div class="wp-block-group fluffless-tarifs__plan"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html( $fluffless_plan[0] ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"fluffless-tarifs__price"} -->
<p class="fluffless-tarifs__price"><?php echo esc_html( $fluffless_plan[1] ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><?php echo esc_html( $fluffless_plan[2] ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list">
	<?php
	foreach ( $fluffless_included as $fluffless_line ) :
		?>
	<!-- wp:list-item -->
<li><?php echo esc_html( $fluffless_line ); ?></li>
<!-- /wp:list-item -->
	<?php endforeach; ?></ul>
<!-- /wp:list -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php esc_html_e( 'Choose this plan', 'fluffless-suite' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/tarifs -->
