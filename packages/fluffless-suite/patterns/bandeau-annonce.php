<?php
/**
 * Title: Announcement bar
 * Slug: fluffless/bandeau-annonce
 * Categories: fluffless-agir
 * Keywords: announcement, banner, notice, news, closing, alert
 * Viewport Width: 1400
 * Post Types: page, wp_template, wp_template_part
 * Fluffless Modules: expression
 * Description: A short notice at the top of the page that visitors can close, such as holiday hours or an event date. Native HTML, no script.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:fluffless/popup {"mode":"banner"} -->
	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'One sentence of news, with a date if it expires. Link to the details.', 'fluffless-suite' ); ?> <a href="#"><?php esc_html_e( 'Read more', 'fluffless-suite' ); ?></a></p>
	<!-- /wp:paragraph -->
<!-- /wp:fluffless/popup -->
