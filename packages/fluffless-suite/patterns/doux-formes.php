<?php
/**
 * Title: Soft rounded section
 * Slug: fluffless/doux-formes
 * Categories: fluffless-presenter
 * Keywords: soft, rounded, shape, benefits, reassurance, calm, style-doux
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: A rounded panel with a soft organic shape in its corner: a reassuring promise on the left, four pill-shaped points on the right. The shape is inline SVG, no image to load.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|50","bottom":"var:preset|spacing|70","left":"var:preset|spacing|50"}}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","className":"is-style-forme-douce","layout":{"type":"constrained","contentSize":"72rem"}} -->
	<div class="wp-block-group alignwide is-style-forme-douce">
		<!-- wp:columns {"verticalAlignment":"center","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|70"}}}} -->
		<div class="wp-block-columns are-vertically-aligned-center">
			<!-- wp:column {"verticalAlignment":"center","width":"55%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
			<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:55%">
				<!-- wp:heading {"style":{"typography":{"lineHeight":"1.1"}},"fontSize":"xx-large","fontFamily":"rounded"} -->
				<h2 class="wp-block-heading has-rounded-font-family has-xx-large-font-size" style="line-height:1.1"><?php esc_html_e( 'A calmer way to get it done', 'fluffless-suite' ); ?></h2>
				<!-- /wp:heading -->

				<!-- wp:paragraph {"fontSize":"medium"} -->
				<p class="has-medium-font-size"><?php esc_html_e( 'Say what feels lighter once people work with you. Two sentences are enough.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:buttons -->
				<div class="wp-block-buttons">
					<!-- wp:button -->
					<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Start gently', 'fluffless-suite' ); ?></a></div>
					<!-- /wp:button -->
				</div>
				<!-- /wp:buttons -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column {"verticalAlignment":"center","width":"45%","style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
			<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:45%">
				<!-- wp:group {"style":{"border":{"radius":"999px"},"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|50","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"base","layout":{"type":"flex","flexWrap":"nowrap"}} -->
				<div class="wp-block-group has-base-background-color has-background" style="border-radius:999px;padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--40)">
					<!-- wp:fluffless/icone {"icon":"leaf","size":"medium","textColor":"accent"} /-->

					<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}}} -->
					<p style="font-weight:700"><?php esc_html_e( 'Clear steps, no jargon', 'fluffless-suite' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"style":{"border":{"radius":"999px"},"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|50","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"base","layout":{"type":"flex","flexWrap":"nowrap"}} -->
				<div class="wp-block-group has-base-background-color has-background" style="border-radius:999px;padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--40)">
					<!-- wp:fluffless/icone {"icon":"clock","size":"medium","textColor":"accent"} /-->

					<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}}} -->
					<p style="font-weight:700"><?php esc_html_e( 'Answers within a day', 'fluffless-suite' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"style":{"border":{"radius":"999px"},"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|50","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"base","layout":{"type":"flex","flexWrap":"nowrap"}} -->
				<div class="wp-block-group has-base-background-color has-background" style="border-radius:999px;padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--40)">
					<!-- wp:fluffless/icone {"icon":"heart-handshake","size":"medium","textColor":"accent"} /-->

					<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}}} -->
					<p style="font-weight:700"><?php esc_html_e( 'Room to change your mind', 'fluffless-suite' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
