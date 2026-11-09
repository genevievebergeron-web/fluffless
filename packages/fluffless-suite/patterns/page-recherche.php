<?php
/**
 * Title: Search results
 * Slug: fluffless/page-recherche
 * Categories: fluffless-pages
 * Keywords: search, results, find, query
 * Viewport Width: 1400
 * Post Types: wp_template
 * Template Types: search
 * Description: Search results as a plain list: title, date and excerpt, the search field repeated at the top, and a helpful message when nothing matches.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained","contentSize":"48rem"}} -->
<main class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:query-title {"type":"search","level":1,"fontSize":"xx-large"} /-->

	<!-- wp:search {"showLabel":false,"buttonUseIcon":true} /-->

	<!-- wp:query {"queryId":1,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"relevance","inherit":true}} -->
	<div class="wp-block-query">
		<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:post-title {"level":2,"isLink":true,"fontSize":"large"} /-->

				<!-- wp:post-date {"textColor":"muted","fontSize":"small"} /-->

				<!-- wp:post-excerpt {"excerptLength":30} /-->
			</div>
			<!-- /wp:group -->
		<!-- /wp:post-template -->

		<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<!-- wp:query-pagination-previous /-->

			<!-- wp:query-pagination-next /-->
		<!-- /wp:query-pagination -->

		<!-- wp:query-no-results -->
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Nothing matches this search. Try a shorter word, or check the spelling.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		<!-- /wp:query-no-results -->
	</div>
	<!-- /wp:query -->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->
