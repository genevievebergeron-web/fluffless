<?php
/**
 * Title: Progress bars, adjustable
 * Slug: fluffless/section-barres
 * Categories: fluffless-prouver
 * Keywords: progress, bars, percent, skills, goals, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and three bars with a label and a value out of 100. Switches in the sidebar set two to eight bars, make them thicker or hide the values.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_bars = array(
	array( __( 'Funds raised toward the goal', 'fluffless-suite' ), 80 ),
	array( __( 'Sites migrated this year', 'fluffless-suite' ), 65 ),
	array( __( 'Pages under 500 KB', 'fluffless-suite' ), 45 ),
);
?>
<!-- wp:fluffless/barres {"align":"wide"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Where we stand', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"className":"fluffless-barres__list","layout":{"type":"default"}} -->
<div class="wp-block-group fluffless-barres__list">
<?php foreach ( $fluffless_bars as $fluffless_bar ) : ?>
<!-- wp:fluffless/barre 
	<?php
	echo wp_json_encode(
		array(
			'label' => $fluffless_bar[0],
			'value' => $fluffless_bar[1],
		)
	);
	?>
						/-->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/barres -->
