<?php
/**
 * Title: Soft services
 * Slug: fluffless/doux-services
 * Categories: fluffless-presenter
 * Keywords: services, offer, cards, soft, rounded, calm, style-doux
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: The introduction on the left, three rounded cards with soft shadows on the right, the middle one slightly lower to keep the rhythm calm. A fourth card helps people who are not sure where to start.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"34%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:34%">
			<!-- wp:heading {"fontSize":"xx-large","fontFamily":"rounded"} -->
			<h2 class="wp-block-heading has-rounded-font-family has-xx-large-font-size"><?php esc_html_e( 'How we can walk alongside you', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"medium"} -->
			<p class="has-muted-color has-text-color has-medium-font-size"><?php esc_html_e( 'One sentence on how you work: pace, format, who does what. People choose a way of working as much as a service.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"66%"} -->
		<div class="wp-block-column" style="flex-basis:66%">
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"grid","columnCount":2,"minimumColumnWidth":"15rem"}} -->
			<div class="wp-block-group">
				<!-- wp:group {"style":{"border":{"radius":"var(--wp--custom--radius--soft)"},"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"},"shadow":"var:preset|shadow|soft"},"backgroundColor":"base","layout":{"type":"default"}} -->
				<div class="wp-block-group has-base-background-color has-background" style="border-radius:var(--wp--custom--radius--soft);padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50);box-shadow:var(--wp--preset--shadow--soft)">
					<!-- wp:fluffless/icone {"icon":"sprout","size":"large","textColor":"accent"} /-->

					<!-- wp:heading {"level":3,"fontFamily":"rounded"} -->
					<h3 class="wp-block-heading has-rounded-font-family"><?php esc_html_e( 'First service', 'fluffless-suite' ); ?></h3>
					<!-- /wp:heading -->

					<!-- wp:paragraph -->
					<p><?php esc_html_e( 'What the person feels after, in one sentence. Relief counts as a result.', 'fluffless-suite' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"style":{"border":{"radius":"var(--wp--custom--radius--soft)"},"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30","margin":{"top":"var:preset|spacing|60"}},"shadow":"var:preset|shadow|soft"},"backgroundColor":"base","layout":{"type":"default"}} -->
				<div class="wp-block-group has-base-background-color has-background" style="border-radius:var(--wp--custom--radius--soft);margin-top:var(--wp--preset--spacing--60);padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50);box-shadow:var(--wp--preset--shadow--soft)">
					<!-- wp:fluffless/icone {"icon":"heart-handshake","size":"large","textColor":"accent"} /-->

					<!-- wp:heading {"level":3,"fontFamily":"rounded"} -->
					<h3 class="wp-block-heading has-rounded-font-family"><?php esc_html_e( 'Second service', 'fluffless-suite' ); ?></h3>
					<!-- /wp:heading -->

					<!-- wp:paragraph -->
					<p><?php esc_html_e( 'Who it suits best, and one practical detail: length, place, or price.', 'fluffless-suite' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"style":{"border":{"radius":"var(--wp--custom--radius--soft)"},"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"},"shadow":"var:preset|shadow|soft"},"backgroundColor":"base","layout":{"type":"default"}} -->
				<div class="wp-block-group has-base-background-color has-background" style="border-radius:var(--wp--custom--radius--soft);padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50);box-shadow:var(--wp--preset--shadow--soft)">
					<!-- wp:fluffless/icone {"icon":"sun","size":"large","textColor":"accent"} /-->

					<!-- wp:heading {"level":3,"fontFamily":"rounded"} -->
					<h3 class="wp-block-heading has-rounded-font-family"><?php esc_html_e( 'Third service', 'fluffless-suite' ); ?></h3>
					<!-- /wp:heading -->

					<!-- wp:paragraph -->
					<p><?php esc_html_e( 'What it includes, and what it does not. Clear limits are reassuring.', 'fluffless-suite' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"style":{"border":{"radius":"var(--wp--custom--radius--soft)"},"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40","margin":{"top":"var:preset|spacing|60"}}},"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"space-between"}} -->
				<div class="wp-block-group has-on-accent-color has-accent-background-color has-text-color has-background" style="border-radius:var(--wp--custom--radius--soft);margin-top:var(--wp--preset--spacing--60);padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
					<!-- wp:paragraph {"fontSize":"large","fontFamily":"rounded"} -->
					<p class="has-rounded-font-family has-large-font-size"><?php esc_html_e( 'Not sure which one fits? That’s normal. Ask us.', 'fluffless-suite' ); ?></p>
					<!-- /wp:paragraph -->

					<!-- wp:buttons -->
					<div class="wp-block-buttons">
						<!-- wp:button {"backgroundColor":"base","textColor":"contrast","style":{"border":{"radius":"var(--wp--custom--radius--round)"}}} -->
						<div class="wp-block-button"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" style="border-radius:var(--wp--custom--radius--round)"><?php esc_html_e( 'Ask a question', 'fluffless-suite' ); ?></a></div>
						<!-- /wp:button -->
					</div>
					<!-- /wp:buttons -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
