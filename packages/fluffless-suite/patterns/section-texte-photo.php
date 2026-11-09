<?php
/**
 * Title: Text and photo, adjustable
 * Slug: fluffless/section-texte-photo
 * Categories: fluffless-presenter
 * Keywords: text, photo, image, side by side, offer, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading, a short text and a button beside a photo. Switches in the sidebar move the photo, change the proportion, remove the button or colour the background.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/texte-photo {"align":"wide"} -->
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Say in one sentence what you offer', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Who it is for and what changes for them, in two or three sentences. Then choose a photo that shows the result, and describe it in its alternative text.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Name the next step', 'fluffless-suite' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img alt=""/></figure>
<!-- /wp:image -->
<!-- /wp:fluffless/texte-photo -->
