<?php
/**
 * Title: Article
 * Slug: fluffless/article
 * Categories: fluffless-pages
 * Keywords: article, blog post, post, news, story, long read
 * Viewport Width: 1000
 * Block Types: core/post-content
 * Post Types: post
 * Description: A ready-to-write article: a standfirst that says what the reader will learn, three sections with their own subheading, a photo with its caption, a quote, a short summary, then one next step. The title stays in the post title field.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size"><?php esc_html_e( 'Say in two sentences what the reader will learn and why it matters now. Someone who stops here should still leave with the main idea.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'First idea, as a subheading that says something', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Start with an example, a number or a situation your readers know. One idea per paragraph, three to five sentences each.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Explain what it means for them. When you cite a fact, link to its source with words that name it, not “here”.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img alt=""/><figcaption class="wp-element-caption"><?php esc_html_e( 'A caption that adds something the photo does not show on its own.', 'fluffless-suite' ); ?></figcaption></figure>
<!-- /wp:image -->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Second idea', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'A list helps when the steps or the options can be read in any order:', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list">
	<!-- wp:list-item -->
	<li><?php esc_html_e( 'One point per line', 'fluffless-suite' ); ?></li>
	<!-- /wp:list-item -->

	<!-- wp:list-item -->
	<li><?php esc_html_e( 'Short enough to read at a glance', 'fluffless-suite' ); ?></li>
	<!-- /wp:list-item -->

	<!-- wp:list-item -->
	<li><?php esc_html_e( 'Three to six points, rarely more', 'fluffless-suite' ); ?></li>
	<!-- /wp:list-item -->
</ul>
<!-- /wp:list -->

<!-- wp:quote -->
<blockquote class="wp-block-quote">
	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'A sentence from a client, an expert or a source, word for word.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->
	<cite><?php esc_html_e( 'Name, role or source', 'fluffless-suite' ); ?></cite>
</blockquote>
<!-- /wp:quote -->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Third idea', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Answer the question readers will have at this point. Add a level 3 subheading only if this section gets long.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"surface","layout":{"type":"default"}} -->
<div class="wp-block-group has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"large"} -->
	<h2 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'In short', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:list {"className":"is-style-coches"} -->
	<ul class="wp-block-list is-style-coches">
		<!-- wp:list-item -->
		<li><?php esc_html_e( 'The first thing to remember', 'fluffless-suite' ); ?></li>
		<!-- /wp:list-item -->

		<!-- wp:list-item -->
		<li><?php esc_html_e( 'The second, in a few words', 'fluffless-suite' ); ?></li>
		<!-- /wp:list-item -->

		<!-- wp:list-item -->
		<li><?php esc_html_e( 'What to do next, if there is one thing', 'fluffless-suite' ); ?></li>
		<!-- /wp:list-item -->
	</ul>
	<!-- /wp:list -->
</div>
<!-- /wp:group -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'End with one next step for readers who want to go further: a related service, a resource or a way to reach you.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons">
	<!-- wp:button {"className":"is-style-outline"} -->
	<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#"><?php esc_html_e( 'Your next step', 'fluffless-suite' ); ?></a></div>
	<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
