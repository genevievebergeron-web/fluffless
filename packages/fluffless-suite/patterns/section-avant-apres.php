<?php
/**
 * Title: Before and after, adjustable
 * Slug: fluffless/section-avant-apres
 * Categories: fluffless-raconter
 * Keywords: before, after, compare, slider, images, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: Two images of the same frame, a handle to slide between them. Works without JavaScript at the chosen split; a script under 1 kB moves the handle.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Before and after', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Drag the handle to compare the two states.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:fluffless/avant-apres {"align":"wide"} -->
<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img alt=""/></figure>
<!-- /wp:image -->

<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img alt=""/></figure>
<!-- /wp:image -->
<!-- /wp:fluffless/avant-apres -->
