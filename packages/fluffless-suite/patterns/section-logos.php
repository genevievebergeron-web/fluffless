<?php
/**
 * Title: Partner logos, adjustable
 * Slug: fluffless/section-logos
 * Categories: fluffless-prouver
 * Keywords: logos, partners, clients, sponsors, funders, trust, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: One line of context and six logos on a quiet grid, in greyscale until the pointer moves over them. Switches in the sidebar set three to eight logos, a single row, or full colour.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/logos {"align":"wide"} -->
<!-- wp:paragraph {"align":"center","textColor":"muted"} -->
<p class="has-text-align-center has-muted-color has-text-color"><?php esc_html_e( 'They work with us. Add a logo only if you have permission to show it.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:group {"layout":{"type":"grid","columnCount":6,"minimumColumnWidth":"7rem"}} -->
<div class="wp-block-group">
<?php for ( $fluffless_i = 0; $fluffless_i < 6; $fluffless_i++ ) : ?>
<!-- wp:image {"aspectRatio":"3/2","scale":"contain","sizeSlug":"medium"} -->
<figure class="wp-block-image size-medium"><img alt="" style="aspect-ratio:3/2;object-fit:contain"/></figure>
<!-- /wp:image -->
<?php endfor; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/logos -->
