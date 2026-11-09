<?php
/**
 * Title: Full-page testimonial
 * Slug: fluffless/temoignage-pleine-page
 * Categories: fluffless-prouver
 * Keywords: testimonial, quote, review, client, proof, style-brut
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: One testimonial, very large, on a dark background. The portrait and name sit low on the right, like a signature. One strong quote beats ten lukewarm ones.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","className":"is-style-inverse","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-inverse" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:quote {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"fontSize":"xx-large"} -->
	<blockquote class="wp-block-quote alignwide has-xx-large-font-size">
		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'Paste a real sentence from a client, word for word. The one where they describe what changed for them, not how nice you were.', 'fluffless' ); ?></p>
		<!-- /wp:paragraph -->
	</blockquote>
	<!-- /wp:quote -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|50"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"58%"} -->
		<div class="wp-block-column" style="flex-basis:58%"></div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"42%"} -->
		<div class="wp-block-column" style="flex-basis:42%">
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group">
				<!-- wp:image {"width":"6rem","aspectRatio":"1","scale":"cover","sizeSlug":"thumbnail","className":"is-style-nb-survol"} -->
				<figure class="wp-block-image size-thumbnail is-resized is-style-nb-survol"><img alt="" style="aspect-ratio:1;object-fit:cover;width:6rem;height:auto"/></figure>
				<!-- /wp:image -->

				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
				<div class="wp-block-group">
					<!-- wp:paragraph {"fontSize":"large"} -->
					<p class="has-large-font-size"><strong><?php esc_html_e( 'Client’s name', 'fluffless' ); ?></strong></p>
					<!-- /wp:paragraph -->

					<!-- wp:paragraph {"fontSize":"small"} -->
					<p class="has-small-font-size"><?php esc_html_e( 'Role and organization, with permission', 'fluffless' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
