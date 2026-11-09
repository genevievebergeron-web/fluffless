<?php
/**
 * Title: Timeline
 * Slug: fluffless/frise
 * Categories: fluffless-raconter
 * Keywords: timeline, history, milestones, steps, process, story
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Four dates or steps strung along one line, each with a dot, a short title and one sentence. Tells your story or your process in reading order.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"surface","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-surface-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"58%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:58%">
			<!-- wp:heading {"fontSize":"xx-large"} -->
			<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'How it unfolds', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"42%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:42%">
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Four dates or four steps, in order. Keep each one to a title and a sentence.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:group {"align":"wide","className":"is-style-frise","layout":{"type":"grid","columnCount":4,"minimumColumnWidth":"12rem"}} -->
	<div class="wp-block-group alignwide is-style-frise">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size" style="font-weight:800"><?php esc_html_e( 'Step 1', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":3,"fontSize":"medium"} -->
			<h3 class="wp-block-heading has-medium-font-size"><?php esc_html_e( 'We listen', 'fluffless-suite' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'One sentence on what happens first and how long it takes.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size" style="font-weight:800"><?php esc_html_e( 'Step 2', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":3,"fontSize":"medium"} -->
			<h3 class="wp-block-heading has-medium-font-size"><?php esc_html_e( 'We propose', 'fluffless-suite' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'What people receive at this stage: a plan, a price, a date.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size" style="font-weight:800"><?php esc_html_e( 'Step 3', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":3,"fontSize":"medium"} -->
			<h3 class="wp-block-heading has-medium-font-size"><?php esc_html_e( 'We build', 'fluffless-suite' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'The part that takes the longest, and how you keep people informed.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size" style="font-weight:800"><?php esc_html_e( 'Step 4', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":3,"fontSize":"medium"} -->
			<h3 class="wp-block-heading has-medium-font-size"><?php esc_html_e( 'We hand over', 'fluffless-suite' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'What people own at the end, and who to call afterwards.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
