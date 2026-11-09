<?php
/**
 * Title: Details in a pop-up
 * Slug: fluffless/fenetre-info
 * Categories: fluffless-presenter
 * Keywords: popup, modal, details, more information, conditions, pop-up
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A short section whose fine print opens in a window only when the visitor asks for it. Opens on click, never on its own.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|50","bottom":"var:preset|spacing|70","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"surface","textColor":"contrast","layout":{"type":"constrained","contentSize":"40rem"}} -->
<div class="wp-block-group alignfull has-contrast-color has-surface-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"x-large"} -->
	<h2 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'The short version', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'What most people need to know, in two sentences. Put the details that only some people need in the window.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:fluffless/popup -->
		<!-- wp:heading {"level":3,"fontSize":"large"} -->
		<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'The details', 'fluffless-suite' ); ?></h3>
		<!-- /wp:heading -->

		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'Conditions, deadlines, exceptions. Anything essential to a decision belongs on the page, not here.', 'fluffless-suite' ); ?></p>
		<!-- /wp:paragraph -->
	<!-- /wp:fluffless/popup -->
</div>
<!-- /wp:group -->
