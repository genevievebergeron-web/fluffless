<?php
/**
 * Title: Blog as an editorial list
 * Slug: fluffless/blogue-liste-editoriale
 * Categories: fluffless-raconter
 * Keywords: blog, posts, articles, list, archive, query, style-editorial, style-brut
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: One post per line, no images: date on the left, title and excerpt in the middle, category on the right. Light to load and easy to scan.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"60%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:60%">
			<!-- wp:heading {"fontSize":"xx-large"} -->
			<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Recent writing', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"40%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:40%">
			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'Say how often you publish and what readers will find here. A promise you can keep beats a big one.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:query {"queryId":12,"query":{"perPage":6,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false},"align":"wide"} -->
	<div class="wp-block-query alignwide">
		<!-- wp:post-template {"style":{"spacing":{"blockGap":"0"}}} -->
			<!-- wp:separator {"backgroundColor":"divider","className":"is-style-wide"} -->
			<hr class="wp-block-separator has-text-color has-divider-color has-alpha-channel-opacity has-divider-background-color has-background is-style-wide"/>
			<!-- /wp:separator -->

			<!-- wp:columns {"style":{"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40"},"blockGap":{"top":"var:preset|spacing|20","left":"var:preset|spacing|60"}}}} -->
			<div class="wp-block-columns" style="padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40)">
				<!-- wp:column {"width":"16%"} -->
				<div class="wp-block-column" style="flex-basis:16%">
					<!-- wp:post-date {"textColor":"muted","fontSize":"small","fontFamily":"mono"} /-->
				</div>
				<!-- /wp:column -->

				<!-- wp:column {"width":"64%","style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
				<div class="wp-block-column" style="flex-basis:64%">
					<!-- wp:post-title {"level":3,"isLink":true,"fontSize":"x-large"} /-->

					<!-- wp:post-excerpt {"textColor":"muted","excerptLength":28} /-->
				</div>
				<!-- /wp:column -->

				<!-- wp:column {"width":"20%"} -->
				<div class="wp-block-column" style="flex-basis:20%">
					<!-- wp:post-terms {"term":"category","textColor":"accent","fontSize":"small"} /-->
				</div>
				<!-- /wp:column -->
			</div>
			<!-- /wp:columns -->
		<!-- /wp:post-template -->

		<!-- wp:query-no-results -->
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'No posts yet. The first one is the hardest, then it gets easier.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		<!-- /wp:query-no-results -->

		<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"left"}} -->
			<!-- wp:query-pagination-previous /-->
			<!-- wp:query-pagination-numbers /-->
			<!-- wp:query-pagination-next /-->
		<!-- /wp:query-pagination -->
	</div>
	<!-- /wp:query -->
</div>
<!-- /wp:group -->
