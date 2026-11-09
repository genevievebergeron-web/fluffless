<?php
/**
 * Title: Editorial pull quote
 * Slug: fluffless/editorial-citation
 * Categories: fluffless-prouver
 * Keywords: quote, pull quote, citation, serif, magazine, style-editorial
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: A pull quote set in large serif between two fine rules, pushed to the right. A small label in the margin says where the quote comes from.
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
		<!-- wp:column {"width":"25%","style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column" style="flex-basis:25%">
			<!-- wp:fluffless/icone {"icon":"message-square","size":"large","textColor":"accent"} /-->

			<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em"}},"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size" style="letter-spacing:0.12em;text-transform:uppercase"><?php esc_html_e( 'In their words', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"75%"} -->
		<div class="wp-block-column" style="flex-basis:75%">
			<!-- wp:quote {"style":{"spacing":{"blockGap":"var:preset|spacing|40"},"typography":{"fontWeight":"400"}},"fontSize":"small","fontFamily":"serif"} -->
			<blockquote class="wp-block-quote has-serif-font-family has-small-font-size" style="font-weight:400">
				<!-- wp:paragraph {"style":{"typography":{"lineHeight":"1.2"}},"fontSize":"xx-large"} -->
				<p class="has-xx-large-font-size" style="line-height:1.2"><em><?php esc_html_e( 'Pick the sentence a reader would underline. Short enough to remember, specific enough to be true.', 'fluffless-suite' ); ?></em></p>
				<!-- /wp:paragraph -->
				<cite><?php esc_html_e( 'First and last name, role', 'fluffless-suite' ); ?></cite>
			</blockquote>
			<!-- /wp:quote -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:separator {"align":"wide","backgroundColor":"divider","className":"is-style-wide"} -->
	<hr class="wp-block-separator alignwide has-text-color has-divider-color has-alpha-channel-opacity has-divider-background-color has-background is-style-wide"/>
	<!-- /wp:separator -->
</div>
<!-- /wp:group -->
