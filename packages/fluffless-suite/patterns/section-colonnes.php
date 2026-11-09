<?php
/**
 * Title: Heading, text and columns, adjustable
 * Slug: fluffless/section-colonnes
 * Categories: fluffless-presenter
 * Keywords: columns, services, features, icons, grid, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading, a short text and three columns with an icon. Switches in the sidebar set 2, 3 or 4 columns, an icon or an image, and a button in each column.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/colonnes {"align":"wide"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Name what these columns have in common', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'One sentence that says why these points matter to your reader.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:fluffless/icone {"icon":"sparkles"} /-->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php esc_html_e( 'Name the first point in a few words', 'fluffless-suite' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Explain it in one or two sentences, with a concrete example.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:fluffless/icone {"icon":"message-circle"} /-->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php esc_html_e( 'Name the second point in a few words', 'fluffless-suite' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Explain it in one or two sentences, with a concrete example.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:fluffless/icone {"icon":"file-text"} /-->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php esc_html_e( 'Name the third point in a few words', 'fluffless-suite' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Explain it in one or two sentences, with a concrete example.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:fluffless/colonnes -->
