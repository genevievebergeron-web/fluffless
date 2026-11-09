<?php
/**
 * Title: Full-bleed image
 * Slug: fluffless/ouverture-image-pleine
 * Categories: fluffless-ouvertures
 * Keywords: hero, opener, image, photo, banner
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: A full-width photo and a giant full-width headline, followed by the message and the button. The dark overlay keeps the text readable on any photo.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:cover {"templateLock":"contentOnly","dimRatio":70,"overlayColor":"base","isUserOverlayColor":true,"minHeight":85,"minHeightUnit":"vh","contentPosition":"bottom left","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|70","left":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-cover alignfull has-custom-content-position is-position-bottom-left" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--50);min-height:85vh"><span aria-hidden="true" class="wp-block-cover__background has-base-background-color has-background-dim-70 has-background-dim"></span><div class="wp-block-cover__inner-container">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"textColor":"contrast","layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide has-contrast-color has-text-color">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">
			<!-- wp:fluffless/icone {"icon":"map-pin","size":"medium","textColor":"accent-2"} /-->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'Where the photo was taken, or what it shows', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:heading {"level":1,"className":"is-style-geant"} -->
		<h1 class="wp-block-heading is-style-geant"><?php esc_html_e( 'The headline this photo backs up', 'fluffless-suite' ); ?></h1>
		<!-- /wp:heading -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"fontSize":"medium"} -->
			<p class="has-medium-font-size"><?php esc_html_e( 'Choose a real photo: your places, your people, your work. One sentence is enough here.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-fleche"} -->
				<div class="wp-block-button is-style-fleche"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Your main action', 'fluffless-suite' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div></div>
<!-- /wp:cover -->
