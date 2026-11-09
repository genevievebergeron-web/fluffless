<?php
/**
 * Title: Resource download, adjustable
 * Slug: fluffless/section-ressource
 * Categories: fluffless-agir
 * Keywords: download, resource, file, pdf, guide, checklist
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and a short text above a download link. Choose the file in the sidebar: the link shows its format and size on its own, with a thumbnail or an icon.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/ressource -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Name the resource and the problem it solves', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Say what is inside, how long it takes to read, and who it is for. Then choose the file in the sidebar.', 'fluffless-suite' ); ?></p>
<!-- /wp:paragraph -->
<!-- /wp:fluffless/ressource -->
