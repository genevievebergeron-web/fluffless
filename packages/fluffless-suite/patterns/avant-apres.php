<?php
/**
 * Title: Before and after
 * Slug: fluffless/avant-apres
 * Categories: fluffless-raconter
 * Keywords: before, after, comparison, transformation, results, renovation
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Two photos side by side, labelled Before and After, each with one sentence on what changed. Stacks on small screens.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'What changed', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|50"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800","textTransform":"uppercase","letterSpacing":"0.08em"}},"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size" style="font-weight:800;letter-spacing:0.08em;text-transform:uppercase"><?php esc_html_e( 'Before', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:image {"aspectRatio":"4/3","scale":"cover","sizeSlug":"large"} -->
			<figure class="wp-block-image size-large"><img alt="" style="aspect-ratio:4/3;object-fit:cover"/></figure>
			<!-- /wp:image -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'The starting point, in one honest sentence.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800","textTransform":"uppercase","letterSpacing":"0.08em"}},"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size" style="font-weight:800;letter-spacing:0.08em;text-transform:uppercase"><?php esc_html_e( 'After', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:image {"aspectRatio":"4/3","scale":"cover","sizeSlug":"large"} -->
			<figure class="wp-block-image size-large"><img alt="" style="aspect-ratio:4/3;object-fit:cover"/></figure>
			<!-- /wp:image -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'The result, with a number if you have one: time saved, cost, people reached.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
