<?php
/**
 * Title: Page not found
 * Slug: fluffless/page-404
 * Categories: fluffless-pages
 * Keywords: 404, not found, error, missing page, search
 * Viewport Width: 1400
 * Post Types: wp_template
 * Template Types: 404
 * Description: A calm 404 page: what happened, a search field and three useful links, so visitors find their way without starting over.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained","contentSize":"44rem"}} -->
<main class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"muted","fontSize":"small"} -->
	<p class="has-muted-color has-text-color has-small-font-size" style="font-weight:800"><?php esc_html_e( 'Error 404', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"level":1,"fontSize":"xx-large"} -->
	<h1 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'This page has moved or no longer exists', 'fluffless-suite' ); ?></h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"fontSize":"medium"} -->
	<p class="has-medium-font-size"><?php esc_html_e( 'The link may be old or mistyped. Search the site, or start from one of the pages below.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:search {"showLabel":false,"buttonUseIcon":true} /-->

	<!-- wp:list {"className":"is-style-numeros"} -->
	<ul class="wp-block-list is-style-numeros">
		<!-- wp:list-item -->
		<li><a href="/"><?php esc_html_e( 'Home page', 'fluffless-suite' ); ?></a></li>
		<!-- /wp:list-item -->

		<!-- wp:list-item -->
		<li><a href="#"><?php esc_html_e( 'Your most visited page', 'fluffless-suite' ); ?></a></li>
		<!-- /wp:list-item -->

		<!-- wp:list-item -->
		<li><a href="#"><?php esc_html_e( 'Contact', 'fluffless-suite' ); ?></a></li>
		<!-- /wp:list-item -->
	</ul>
	<!-- /wp:list -->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->
