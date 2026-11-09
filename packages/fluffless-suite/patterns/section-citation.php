<?php
/**
 * Title: Quote, adjustable
 * Slug: fluffless/section-citation
 * Categories: fluffless-prouver
 * Keywords: quote, pull quote, citation, words, proof, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: One quote set large beside a fine rule, followed by the author’s name. Switches in the sidebar make it compact, swap the rule for quotation marks, and add or remove the author.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/citation {"align":"wide"} -->
<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p><?php esc_html_e( 'Pick the sentence a reader would underline. Short enough to remember, specific enough to be true.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph --></blockquote>
<!-- /wp:quote -->

<!-- wp:paragraph {"className":"fluffless-citation__author"} -->
<p class="fluffless-citation__author"><?php esc_html_e( 'First and last name, role', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->
<!-- /wp:fluffless/citation -->
