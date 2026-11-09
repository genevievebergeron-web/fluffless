<?php
/**
 * Title: Data table, adjustable
 * Slug: fluffless/section-tableau
 * Categories: fluffless-structure
 * Keywords: table, data, comparison, rows, columns, adjustable
 * Viewport Width: 1400
 * Post Types: page, post, wp_template
 * Fluffless Modules: expression
 * Description: A heading and a three-column table with a header row, striped rows and stacked rows on small screens. Every setting can be changed in the sidebar.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/tableau {"stripes":"striped","heads":"bold","mobile":"stack"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Side by side, in numbers', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->
<!-- wp:table {"hasFixedLayout":true} -->
<figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th><?php esc_html_e( 'Feature', 'fluffless-suite' ); ?></th><th><?php esc_html_e( 'Option A', 'fluffless-suite' ); ?></th><th><?php esc_html_e( 'Option B', 'fluffless-suite' ); ?></th></tr></thead><tbody><tr><td><?php esc_html_e( 'Monthly price', 'fluffless-suite' ); ?></td><td>29 $</td><td>49 $</td></tr><tr><td><?php esc_html_e( 'Pages included', 'fluffless-suite' ); ?></td><td>5</td><td>20</td></tr><tr><td><?php esc_html_e( 'Support', 'fluffless-suite' ); ?></td><td><?php esc_html_e( 'By email', 'fluffless-suite' ); ?></td><td><?php esc_html_e( 'By phone', 'fluffless-suite' ); ?></td></tr></tbody></table></figure>
<!-- /wp:table -->
<!-- /wp:fluffless/tableau -->
