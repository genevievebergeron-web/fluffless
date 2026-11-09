<?php
/**
 * Title: Editorial magazine spread
 * Slug: fluffless/editorial-magazine
 * Categories: fluffless-raconter
 * Keywords: magazine, long read, article, drop cap, columns, serif, editorial, style-editorial
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: A magazine spread: a label and byline in the margin, a large serif headline with its deck, then three columns of text opening on a drop cap and closing on a pull number.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:separator {"align":"wide","backgroundColor":"contrast","className":"is-style-wide"} -->
	<hr class="wp-block-separator alignwide has-text-color has-contrast-color has-alpha-channel-opacity has-contrast-background-color has-background is-style-wide"/>
	<!-- /wp:separator -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"25%","style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
		<div class="wp-block-column" style="flex-basis:25%">
			<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em","fontWeight":"700"}},"textColor":"accent","fontSize":"small","fontFamily":"sans"} -->
			<p class="has-accent-color has-text-color has-sans-font-family has-small-font-size" style="font-weight:700;letter-spacing:0.12em;text-transform:uppercase"><?php esc_html_e( 'In depth', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'By First Last · 6-minute read', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"75%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:75%">
			<!-- wp:heading {"style":{"typography":{"fontWeight":"400","lineHeight":"1.05"}},"fontSize":"xx-large","fontFamily":"didone"} -->
			<h2 class="wp-block-heading has-didone-font-family has-xx-large-font-size" style="font-weight:400;line-height:1.05"><?php esc_html_e( 'A long-form headline that promises one clear idea', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"style":{"typography":{"fontStyle":"italic"}},"fontSize":"large","fontFamily":"serif"} -->
			<p class="has-serif-font-family has-large-font-size" style="font-style:italic"><?php esc_html_e( 'A two-line deck: what readers will learn, and why it matters now.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:separator {"align":"wide","backgroundColor":"divider","className":"is-style-wide"} -->
	<hr class="wp-block-separator alignwide has-text-color has-divider-color has-alpha-channel-opacity has-divider-background-color has-background is-style-wide"/>
	<!-- /wp:separator -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|60"}}},"fontFamily":"serif"} -->
	<div class="wp-block-columns alignwide has-serif-font-family">
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:paragraph {"dropCap":true} -->
			<p class="has-drop-cap"><?php esc_html_e( 'Open with a scene, a number or a question. The drop cap marks where the reading starts, so the first sentence has to earn it.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Keep paragraphs short. On a phone, these three columns become one and the rhythm still holds.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'The middle column carries the argument: one idea per paragraph, each one backed by a fact, an example or a name.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Quote the people involved. Their words read faster than a summary of them.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
		<div class="wp-block-column">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"400","lineHeight":"1"}},"textColor":"accent","fontSize":"xx-large","fontFamily":"didone"} -->
			<p class="has-accent-color has-text-color has-didone-font-family has-xx-large-font-size" style="font-weight:400;line-height:1"><?php esc_html_e( '3 of 4', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"small","fontFamily":"sans"} -->
			<p class="has-muted-color has-text-color has-sans-font-family has-small-font-size"><?php esc_html_e( 'End on the one number the article proves, with its source in a single line.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
