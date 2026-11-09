<?php
/**
 * Title: Soft call to action
 * Slug: fluffless/doux-appel-action
 * Categories: fluffless-agir
 * Keywords: call to action, contact, booking, soft, rounded, calm, style-doux
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: A rounded card that floats on the soft background with a gentle shadow: one invitation, one button, and a line that lowers the pressure.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","className":"is-style-forme-accent fl-accent-shape-ring fl-accent-size-large","style":{"border":{"radius":"var(--wp--custom--radius--soft)"},"spacing":{"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|60","bottom":"var:preset|spacing|70","left":"var:preset|spacing|60"}},"shadow":"var:preset|shadow|soft"},"backgroundColor":"base","layout":{"type":"constrained","contentSize":"80rem"}} -->
	<div class="wp-block-group alignwide is-style-forme-accent fl-accent-shape-ring fl-accent-size-large has-base-background-color has-background" style="border-radius:var(--wp--custom--radius--soft);padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--60);box-shadow:var(--wp--preset--shadow--soft)">
		<!-- wp:columns {"verticalAlignment":"center","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|70"}}}} -->
		<div class="wp-block-columns are-vertically-aligned-center">
			<!-- wp:column {"verticalAlignment":"center","width":"62%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
			<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:62%">
				<!-- wp:fluffless/icone {"icon":"message-circle","size":"large","textColor":"accent"} /-->

				<!-- wp:heading {"fontSize":"xx-large","fontFamily":"rounded"} -->
				<h2 class="wp-block-heading has-rounded-font-family has-xx-large-font-size"><?php esc_html_e( 'Let’s start with a chat', 'fluffless-suite' ); ?></h2>
				<!-- /wp:heading -->

				<!-- wp:paragraph {"textColor":"muted","fontSize":"medium"} -->
				<p class="has-muted-color has-text-color has-medium-font-size"><?php esc_html_e( 'Say exactly what happens when someone clicks: how long it lasts, whether it’s free, and that there’s no obligation.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column {"verticalAlignment":"center","width":"38%","style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
			<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:38%">
				<!-- wp:buttons -->
				<div class="wp-block-buttons">
					<!-- wp:button {"style":{"border":{"radius":"var(--wp--custom--radius--round)"}},"fontSize":"medium"} -->
					<div class="wp-block-button"><a class="wp-block-button__link has-medium-font-size has-custom-font-size wp-element-button" style="border-radius:var(--wp--custom--radius--round)"><?php esc_html_e( 'Pick a time', 'fluffless-suite' ); ?></a></div>
					<!-- /wp:button -->
				</div>
				<!-- /wp:buttons -->

				<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
				<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'Prefer writing? Leave your email instead.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
