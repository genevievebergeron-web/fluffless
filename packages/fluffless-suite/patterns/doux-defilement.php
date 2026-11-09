<?php
/**
 * Title: Soft sideways cards
 * Slug: fluffless/doux-defilement
 * Categories: fluffless-prouver
 * Keywords: testimonials, reviews, cards, horizontal scroll, carousel, soft, rounded, style-doux
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Short testimonials on rounded cards that scroll sideways and snap into place. No script and no autoplay: people scroll at their own pace.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:heading {"fontSize":"xx-large","fontFamily":"rounded"} -->
		<h2 class="wp-block-heading has-rounded-font-family has-xx-large-font-size"><?php esc_html_e( 'Small wins, in their words', 'fluffless-suite' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"textColor":"muted"} -->
		<p class="has-muted-color has-text-color"><?php esc_html_e( 'Scroll sideways to read more. Four or five short quotes are plenty.', 'fluffless-suite' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"align":"wide","className":"is-style-defilement","layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"stretch"}} -->
	<div class="wp-block-group alignwide is-style-defilement">
		<!-- wp:group {"className":"is-style-carte","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"space-between"}} -->
		<div class="wp-block-group is-style-carte">
			<!-- wp:paragraph {"fontSize":"large","fontFamily":"rounded"} -->
			<p class="has-rounded-font-family has-large-font-size"><?php esc_html_e( '“It finally feels simple.”', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'First name, organization', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"is-style-carte","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"space-between"}} -->
		<div class="wp-block-group is-style-carte">
			<!-- wp:paragraph {"fontSize":"large","fontFamily":"rounded"} -->
			<p class="has-rounded-font-family has-large-font-size"><?php esc_html_e( '“We knew what came next at every step.”', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'First name, organization', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"is-style-carte","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"space-between"}} -->
		<div class="wp-block-group is-style-carte">
			<!-- wp:paragraph {"fontSize":"large","fontFamily":"rounded"} -->
			<p class="has-rounded-font-family has-large-font-size"><?php esc_html_e( '“Our volunteers update the site themselves now.”', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'First name, organization', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"is-style-carte","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"space-between"}} -->
		<div class="wp-block-group is-style-carte">
			<!-- wp:paragraph {"fontSize":"large","fontFamily":"rounded"} -->
			<p class="has-rounded-font-family has-large-font-size"><?php esc_html_e( '“Kind, clear and on time.”', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'First name, organization', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
