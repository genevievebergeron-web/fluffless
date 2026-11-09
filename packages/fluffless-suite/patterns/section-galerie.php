<?php
/**
 * Title: Photo gallery, adjustable
 * Slug: fluffless/section-galerie
 * Categories: fluffless-presenter
 * Keywords: gallery, photos, images, mosaic, portfolio, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and a plain gallery of photos on three columns that enlarge on click. Switches in the sidebar set 2, 3 or 4 columns, a mosaic, and show or hide the captions.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/galerie {"align":"wide"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Name what these photos show', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:gallery {"columns":3,"linkTo":"lightbox","sizeSlug":"large"} -->
<figure class="wp-block-gallery has-nested-images columns-3 is-cropped">
<?php for ( $fluffless_i = 0; $fluffless_i < 3; $fluffless_i++ ) : ?>
<!-- wp:image {"lightbox":{"enabled":true},"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img alt=""/></figure>
<!-- /wp:image -->
<?php endfor; ?>
</figure>
<!-- /wp:gallery -->
<!-- /wp:fluffless/galerie -->
