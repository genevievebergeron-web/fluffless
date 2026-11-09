<?php
/**
 * Title: Recent posts, as a list
 * Slug: fluffless/articles-recents-liste
 * Categories: fluffless-raconter
 * Keywords: blog, posts, recent, list, query, sidebar
 * Viewport Width: 800
 * Post Types: page, wp_template, wp_template_part
 * Description: The five latest posts as a plain list of titles and dates, without images. Fits a sidebar, a footer or the end of a page.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
	<!-- wp:heading {"level":2} -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'Recent posts', 'fluffless' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:query {"queryId":12,"query":{"perPage":5,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
	<div class="wp-block-query">
		<!-- wp:post-template {"layout":{"type":"default"}} -->
		<!-- wp:post-title {"isLink":true,"level":3,"fontSize":"medium"} /-->
		<!-- wp:post-date {"fontSize":"small"} /-->
		<!-- /wp:post-template -->

		<!-- wp:query-no-results -->
		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'No post yet.', 'fluffless' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- /wp:query-no-results -->
	</div>
	<!-- /wp:query -->
</div>
<!-- /wp:group -->
