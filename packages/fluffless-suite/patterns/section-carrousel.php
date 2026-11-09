<?php
/**
 * Title: Carousel, adjustable
 * Slug: fluffless/section-carrousel
 * Categories: fluffless-presenter
 * Keywords: carousel, slider, slides, photos, scroll, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and four slides that scroll sideways and snap into place, three at a time on wide screens. Switches in the sidebar set two to eight slides and how many show at once. No library, no script.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_slides = array(
	__( 'One line under the picture: what it shows, or where it was taken.', 'fluffless-suite' ),
	__( 'Pick pictures that share a light and a format: the strip reads as one story.', 'fluffless-suite' ),
	__( 'Four slides are plenty. Beyond eight, a gallery serves better.', 'fluffless-suite' ),
	__( 'Visitors scroll with a finger, the trackpad or the keyboard: nothing to learn.', 'fluffless-suite' ),
);
?>
<!-- wp:fluffless/carrousel {"align":"wide"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'A few moments from the field', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"className":"fluffless-carrousel__track","layout":{"type":"default"}} -->
<div class="wp-block-group fluffless-carrousel__track">
<?php foreach ( $fluffless_slides as $fluffless_slide ) : ?>
<!-- wp:group {"className":"fluffless-carrousel__item"} -->
<div class="wp-block-group fluffless-carrousel__item"><!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img alt=""/></figure>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p><?php echo esc_html( $fluffless_slide ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/carrousel -->
