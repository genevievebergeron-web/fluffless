<?php
/**
 * Title: Blog: featured post
 * Slug: fluffless/blogue-a-la-une
 * Categories: fluffless-raconter
 * Keywords: blog, featured, latest, posts, news, query, style-brut
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Your latest post takes the big spot, the next two stack beside it. Good on a home page to show the site is alive.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:fluffless/icone {"icon":"newspaper","size":"medium","textColor":"accent"} /-->

		<!-- wp:heading {"fontSize":"x-large"} -->
		<h2 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Fresh off the desk', 'fluffless-suite' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|60"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"62%"} -->
		<div class="wp-block-column" style="flex-basis:62%">
			<!-- wp:query {"queryId":13,"query":{"perPage":1,"pages":1,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
			<div class="wp-block-query">
				<!-- wp:post-template -->
					<!-- wp:group {"className":"is-style-carte","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"default"}} -->
					<div class="wp-block-group is-style-carte">
						<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"16/9","className":"is-style-cadre-decale"} /-->

						<!-- wp:post-terms {"term":"category","textColor":"accent","fontSize":"small"} /-->

						<!-- wp:post-title {"level":3,"isLink":true,"fontSize":"xx-large"} /-->

						<!-- wp:post-excerpt {"excerptLength":32,"fontSize":"medium"} /-->

						<!-- wp:post-date {"textColor":"muted","fontSize":"small"} /-->
					</div>
					<!-- /wp:group -->
				<!-- /wp:post-template -->

				<!-- wp:query-no-results -->
					<!-- wp:paragraph -->
					<p><?php esc_html_e( 'No posts yet. The first one is the hardest, then it gets easier.', 'fluffless-suite' ); ?></p>
					<!-- /wp:paragraph -->
				<!-- /wp:query-no-results -->
			</div>
			<!-- /wp:query -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"38%"} -->
		<div class="wp-block-column" style="flex-basis:38%">
			<!-- wp:query {"queryId":14,"query":{"perPage":2,"pages":1,"offset":1,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
			<div class="wp-block-query">
				<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
					<!-- wp:group {"className":"is-style-bordure-brute","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
					<div class="wp-block-group is-style-bordure-brute">
						<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"3/2","className":"is-style-nb-survol"} /-->

						<!-- wp:post-title {"level":3,"isLink":true,"fontSize":"large"} /-->

						<!-- wp:post-date {"textColor":"muted","fontSize":"small"} /-->
					</div>
					<!-- /wp:group -->
				<!-- /wp:post-template -->
			</div>
			<!-- /wp:query -->

			<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"var:preset|spacing|50"}}}} -->
			<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--50)">
				<!-- wp:button {"backgroundColor":"base","textColor":"contrast","className":"is-style-fleche"} -->
				<div class="wp-block-button is-style-fleche"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button"><?php esc_html_e( 'All posts', 'fluffless-suite' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
