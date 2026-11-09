<?php
/**
 * Title: Editorial front page
 * Slug: fluffless/editorial-une
 * Categories: fluffless-raconter
 * Keywords: blog, magazine, front page, posts, serif, query, style-editorial
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: A magazine front page: a masthead between two rules, the lead story on the left with a large serif title, and the next three posts in a narrow column separated by fine rules.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:separator {"backgroundColor":"contrast","className":"is-style-wide"} -->
		<hr class="wp-block-separator has-text-color has-contrast-color has-alpha-channel-opacity has-contrast-background-color has-background is-style-wide"/>
		<!-- /wp:separator -->

		<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
		<div class="wp-block-group">
			<!-- wp:heading {"style":{"typography":{"fontWeight":"400"}},"fontSize":"xx-large","fontFamily":"didone"} -->
			<h2 class="wp-block-heading has-didone-font-family has-xx-large-font-size" style="font-weight:400"><?php esc_html_e( 'The name of your journal', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em"}},"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size" style="letter-spacing:0.12em;text-transform:uppercase"><?php esc_html_e( 'What it covers, in five words', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:separator {"backgroundColor":"divider","className":"is-style-wide"} -->
		<hr class="wp-block-separator has-text-color has-divider-color has-alpha-channel-opacity has-divider-background-color has-background is-style-wide"/>
		<!-- /wp:separator -->
	</div>
	<!-- /wp:group -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"64%"} -->
		<div class="wp-block-column" style="flex-basis:64%">
			<!-- wp:query {"queryId":21,"query":{"perPage":1,"pages":1,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
			<div class="wp-block-query">
				<!-- wp:post-template -->
					<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"default"}} -->
					<div class="wp-block-group">
						<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"3/2"} /-->

						<!-- wp:post-terms {"term":"category","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em"}},"textColor":"accent","fontSize":"small"} /-->

						<!-- wp:post-title {"level":3,"isLink":true,"style":{"typography":{"fontWeight":"400","lineHeight":"1.05"}},"fontSize":"xx-large","fontFamily":"didone"} /-->

						<!-- wp:post-excerpt {"excerptLength":36,"fontSize":"medium","fontFamily":"serif"} /-->

						<!-- wp:post-date {"textColor":"muted","fontSize":"small"} /-->
					</div>
					<!-- /wp:group -->
				<!-- /wp:post-template -->

				<!-- wp:query-no-results -->
					<!-- wp:paragraph {"fontFamily":"serif"} -->
					<p class="has-serif-font-family"><?php esc_html_e( 'No posts yet. The first one is the hardest, then it gets easier.', 'fluffless-suite' ); ?></p>
					<!-- /wp:paragraph -->
				<!-- /wp:query-no-results -->
			</div>
			<!-- /wp:query -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"36%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:36%">
			<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em"}},"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size" style="letter-spacing:0.12em;text-transform:uppercase"><?php esc_html_e( 'Also in this issue', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:query {"queryId":22,"query":{"perPage":3,"pages":1,"offset":1,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
			<div class="wp-block-query">
				<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
					<!-- wp:separator {"backgroundColor":"divider","className":"is-style-wide"} -->
					<hr class="wp-block-separator has-text-color has-divider-color has-alpha-channel-opacity has-divider-background-color has-background is-style-wide"/>
					<!-- /wp:separator -->

					<!-- wp:post-title {"level":3,"isLink":true,"style":{"typography":{"fontWeight":"400"}},"fontSize":"large","fontFamily":"serif"} /-->

					<!-- wp:post-date {"textColor":"muted","fontSize":"small"} /-->
				<!-- /wp:post-template -->
			</div>
			<!-- /wp:query -->

			<!-- wp:paragraph {"fontFamily":"serif"} -->
			<p class="has-serif-font-family"><em><?php esc_html_e( 'Read the archive', 'fluffless-suite' ); ?></em></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
