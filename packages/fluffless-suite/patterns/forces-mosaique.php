<?php
/**
 * Title: Strengths mosaic
 * Slug: fluffless/forces-mosaique
 * Categories: fluffless-presenter
 * Keywords: strengths, benefits, grid, bento, mosaic
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Five tiles in different sizes and colours on a mesh background. The biggest one carries your main strength; the bottom band leads to action.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","gradient":"maille","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-maille-gradient-background has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"66.66%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:66.66%">
			<!-- wp:heading {"className":"is-style-geant"} -->
			<h2 class="wp-block-heading is-style-geant"><?php esc_html_e( 'What we do better than anyone', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"33.33%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:33.33%">
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Rank your strengths from most to least important. The tile sizes follow that order.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"grid","columnCount":4,"minimumColumnWidth":"12rem"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"layout":{"columnSpan":2,"rowSpan":2},"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|60"}},"dimensions":{"minHeight":"22rem"}},"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"space-between"}} -->
		<div class="wp-block-group has-on-accent-color has-accent-background-color has-text-color has-background" style="min-height:22rem;padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--60)">
			<!-- wp:fluffless/icone {"icon":"target","size":"xl","textColor":"on-accent"} /-->

			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:heading {"level":3,"fontSize":"xx-large"} -->
				<h3 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Your main strength, in four words', 'fluffless-suite' ); ?></h3>
				<!-- /wp:heading -->

				<!-- wp:paragraph {"fontSize":"medium"} -->
				<p class="has-medium-font-size"><?php esc_html_e( 'Show the proof: an action, a result, a story from the field. This is the tile people read first.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"layout":{"columnSpan":2,"rowSpan":1},"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"default"}} -->
		<div class="wp-block-group has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
			<!-- wp:fluffless/icone {"icon":"heart-handshake","size":"large","textColor":"accent-2"} /-->

			<!-- wp:heading {"level":3} -->
			<h3 class="wp-block-heading"><?php esc_html_e( 'A second strength', 'fluffless-suite' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Write what people get out of it, not what you put into it.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"is-style-bordure-brute","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"textColor":"contrast","layout":{"type":"default"}} -->
		<div class="wp-block-group is-style-bordure-brute has-contrast-color has-text-color">
			<!-- wp:fluffless/icone {"icon":"recycle","size":"medium","textColor":"accent"} /-->

			<!-- wp:heading {"level":3,"fontSize":"medium"} -->
			<h3 class="wp-block-heading has-medium-font-size"><?php esc_html_e( 'A detail that matters', 'fluffless-suite' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'One short sentence, one fact you can check.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"is-style-carte","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group is-style-carte">
			<!-- wp:fluffless/icone {"icon":"lightbulb","size":"medium","textColor":"accent-2"} /-->

			<!-- wp:heading {"level":3,"fontSize":"medium"} -->
			<h3 class="wp-block-heading has-medium-font-size"><?php esc_html_e( 'A way of working', 'fluffless-suite' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'What you do differently, and why.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"is-style-inverse","style":{"layout":{"columnSpan":4,"rowSpan":1},"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|60","bottom":"var:preset|spacing|50","left":"var:preset|spacing|60"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
		<div class="wp-block-group is-style-inverse has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--60)">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"fontSize":"large"} -->
			<p class="has-large-font-size" style="font-weight:800"><?php esc_html_e( 'Finish with the strength people bring up when they recommend you.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"backgroundColor":"base","textColor":"contrast","className":"is-style-fleche"} -->
				<div class="wp-block-button is-style-fleche"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button"><?php esc_html_e( 'Read the testimonials', 'fluffless-suite' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
