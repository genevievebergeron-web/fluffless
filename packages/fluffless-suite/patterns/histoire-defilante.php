<?php
/**
 * Title: Scrolling story
 * Slug: fluffless/histoire-defilante
 * Categories: fluffless-raconter
 * Keywords: story, history, about, scrollytelling, scroll, sticky, timeline
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: One image stays in place while three short chapters of your story scroll past it. Motion is skipped for visitors who prefer less of it.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'How we got here', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:fluffless/recit {"effect":"rise"} -->
		<!-- wp:group {"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:image {"aspectRatio":"4/5","scale":"cover","sizeSlug":"large"} -->
			<figure class="wp-block-image size-large"><img alt="" style="aspect-ratio:4/5;object-fit:cover"/></figure>
			<!-- /wp:image -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"muted","fontSize":"small"} -->
				<p class="has-muted-color has-text-color has-small-font-size" style="font-weight:800"><?php esc_html_e( 'The beginning', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
				<h3 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Why it started', 'fluffless-suite' ); ?></h3>
				<!-- /wp:heading -->

				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'The problem you saw, the year, and who was there. Three sentences at most.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"muted","fontSize":"small"} -->
				<p class="has-muted-color has-text-color has-small-font-size" style="font-weight:800"><?php esc_html_e( 'The turning point', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
				<h3 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'What changed', 'fluffless-suite' ); ?></h3>
				<!-- /wp:heading -->

				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'A decision, a meeting or a mistake that shaped how you work today.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"muted","fontSize":"small"} -->
				<p class="has-muted-color has-text-color has-small-font-size" style="font-weight:800"><?php esc_html_e( 'Today', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
				<h3 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Where you are now', 'fluffless-suite' ); ?></h3>
				<!-- /wp:heading -->

				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Who you serve now and what comes next. End with an invitation to take the next step.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
	<!-- /wp:fluffless/recit -->
</div>
<!-- /wp:group -->
