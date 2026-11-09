<?php
/**
 * Title: Contact details and hours
 * Slug: fluffless/contact
 * Categories: fluffless-agir
 * Keywords: contact, address, phone, email, hours, map, directions, style-brut
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Email, phone, address and opening hours on the left; a static map image with a directions button on the right. No embedded map, so no third-party tracking and no heavy script.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"55%","style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
		<div class="wp-block-column" style="flex-basis:55%">
			<!-- wp:heading {"fontSize":"xx-large"} -->
			<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Write to us. A human answers.', 'fluffless' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"medium"} -->
			<p class="has-muted-color has-text-color has-medium-font-size"><?php esc_html_e( 'How soon do you reply, and what should people include in their first message? Say it here.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
				<div class="wp-block-group">
					<!-- wp:paragraph {"fontSize":"small"} -->
					<p class="has-small-font-size"><strong><?php esc_html_e( 'Email', 'fluffless' ); ?></strong></p>
					<!-- /wp:paragraph -->

					<!-- wp:paragraph {"fontSize":"large"} -->
					<p class="has-large-font-size"><?php esc_html_e( 'hello@example.ca', 'fluffless' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
				<div class="wp-block-group">
					<!-- wp:paragraph {"fontSize":"small"} -->
					<p class="has-small-font-size"><strong><?php esc_html_e( 'Phone', 'fluffless' ); ?></strong></p>
					<!-- /wp:paragraph -->

					<!-- wp:paragraph {"fontSize":"large"} -->
					<p class="has-large-font-size"><?php esc_html_e( '555-555-0123', 'fluffless' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
				<div class="wp-block-group">
					<!-- wp:paragraph {"fontSize":"small"} -->
					<p class="has-small-font-size"><strong><?php esc_html_e( 'Address', 'fluffless' ); ?></strong></p>
					<!-- /wp:paragraph -->

					<!-- wp:paragraph {"fontSize":"large"} -->
					<p class="has-large-font-size"><?php esc_html_e( 'Street address, city, province, postal code', 'fluffless' ); ?></p>
					<!-- /wp:paragraph -->

					<!-- wp:buttons -->
					<div class="wp-block-buttons">
						<!-- wp:button {"className":"is-style-contour"} -->
						<div class="wp-block-button is-style-contour"><a class="wp-block-button__link wp-element-button" href="#"><?php esc_html_e( 'Get directions', 'fluffless' ); ?></a></div>
						<!-- /wp:button -->
					</div>
					<!-- /wp:buttons -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:group -->

			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'Opening hours', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:table {"className":"is-style-regular"} -->
			<figure class="wp-block-table is-style-regular"><table class="has-fixed-layout"><tbody><tr><td><?php esc_html_e( 'Monday to Thursday', 'fluffless' ); ?></td><td><?php esc_html_e( '9 a.m. to 5 p.m.', 'fluffless' ); ?></td></tr><tr><td><?php esc_html_e( 'Friday', 'fluffless' ); ?></td><td><?php esc_html_e( '9 a.m. to noon', 'fluffless' ); ?></td></tr><tr><td><?php esc_html_e( 'Weekends and holidays', 'fluffless' ); ?></td><td><?php esc_html_e( 'Closed', 'fluffless' ); ?></td></tr></tbody></table></figure>
			<!-- /wp:table -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"45%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:45%">
			<!-- wp:image {"aspectRatio":"4/5","scale":"cover","sizeSlug":"large","className":"is-style-cadre-decale"} -->
			<figure class="wp-block-image size-large is-style-cadre-decale"><img alt="" style="aspect-ratio:4/5;object-fit:cover"/><figcaption class="wp-element-caption"><?php esc_html_e( 'Replace with a screenshot of your map (OpenStreetMap works well). In the alt text, write the address and the nearest landmark.', 'fluffless' ); ?></figcaption></figure>
			<!-- /wp:image -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
