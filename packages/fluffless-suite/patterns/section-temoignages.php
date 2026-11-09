<?php
/**
 * Title: Testimonials, adjustable
 * Slug: fluffless/section-temoignages
 * Categories: fluffless-prouver
 * Keywords: testimonials, reviews, quotes, clients, proof, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: Three testimonials side by side, each with a photo, a quote and a name. Switches in the sidebar set one, two or three, and add or remove photos and company logos.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/temoignages {"align":"wide","className":"is-style-forme-accent fl-accent-shape-drop fl-accent-place-title fl-accent-size-small"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Let your clients say it for you', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->
<div class="wp-block-group"><!-- wp:group -->
<div class="wp-block-group"><!-- wp:image {"sizeSlug":"thumbnail","className":"fluffless-temoignages__photo"} -->
<figure class="wp-block-image size-thumbnail fluffless-temoignages__photo"><img alt=""/></figure>
<!-- /wp:image -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p><?php esc_html_e( 'Quote the client word for word: the result they got, in their own words.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph --><cite><?php esc_html_e( 'First name, role, company', 'fluffless-suite' ); ?></cite></blockquote>
<!-- /wp:quote --></div>
<!-- /wp:group -->

<!-- wp:group -->
<div class="wp-block-group"><!-- wp:image {"sizeSlug":"thumbnail","className":"fluffless-temoignages__photo"} -->
<figure class="wp-block-image size-thumbnail fluffless-temoignages__photo"><img alt=""/></figure>
<!-- /wp:image -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p><?php esc_html_e( 'Pick a testimonial that answers a doubt your readers often have.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph --><cite><?php esc_html_e( 'First name, role, company', 'fluffless-suite' ); ?></cite></blockquote>
<!-- /wp:quote --></div>
<!-- /wp:group -->

<!-- wp:group -->
<div class="wp-block-group"><!-- wp:image {"sizeSlug":"thumbnail","className":"fluffless-temoignages__photo"} -->
<figure class="wp-block-image size-thumbnail fluffless-temoignages__photo"><img alt=""/></figure>
<!-- /wp:image -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p><?php esc_html_e( 'Ask permission before you publish a name and a photo.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph --><cite><?php esc_html_e( 'First name, role, company', 'fluffless-suite' ); ?></cite></blockquote>
<!-- /wp:quote --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- /wp:fluffless/temoignages -->
