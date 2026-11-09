<?php
/**
 * Title: Map, adjustable
 * Slug: fluffless/section-carte
 * Categories: fluffless-presenter
 * Keywords: map, address, directions, hours, location, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A still map or a photo of the entrance beside the address, the hours and a button that opens the visitor's own maps app. No embedded map, no third-party request, no consent banner.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/carte {"align":"wide"} -->
<!-- wp:image {"sizeSlug":"large","className":"fluffless-carte__image"} -->
<figure class="wp-block-image size-large fluffless-carte__image"><img alt=""/></figure>
<!-- /wp:image -->

<!-- wp:group {"className":"fluffless-carte__text","layout":{"type":"default"}} -->
<div class="wp-block-group fluffless-carte__text"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php esc_html_e( 'Where to find us', 'fluffless-suite' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( '123 Main Street, Your Town, Postal code. Open Tuesday to Saturday, 10 a.m. to 6 p.m.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Open in your maps app', 'fluffless-suite' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
<!-- /wp:fluffless/carte -->
