<?php
/**
 * Title: Tag cloud
 * Slug: fluffless/nuage-etiquettes
 * Categories: fluffless-raconter
 * Keywords: tags, cloud, topics, blog, sidebar
 * Viewport Width: 800
 * Post Types: page, wp_template, wp_template_part
 * Description: The tags of the blog, sized by how often they are used. A native block, no script.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
	<!-- wp:heading {"level":2} -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'Topics', 'fluffless' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:tag-cloud {"numberOfTags":20,"smallestFontSize":"0.875rem","largestFontSize":"1.5rem"} /-->
</div>
<!-- /wp:group -->
