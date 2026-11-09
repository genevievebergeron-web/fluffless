<?php
/**
 * Title: Partner logos
 * Slug: fluffless/logos-partenaires
 * Categories: fluffless-prouver
 * Keywords: logos, partners, clients, sponsors, funders, trust
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: One line of context and six partner or client logos on a quiet grid, in greyscale until the pointer moves over them.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|50","bottom":"var:preset|spacing|70","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:paragraph {"align":"center","textColor":"muted"} -->
	<p class="has-text-align-center has-muted-color has-text-color"><?php esc_html_e( 'They work with us. Add a logo only if you have permission to show it.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"grid","columnCount":6,"minimumColumnWidth":"7rem"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:image {"aspectRatio":"3/2","scale":"contain","sizeSlug":"medium","className":"is-style-nb-survol"} -->
		<figure class="wp-block-image size-medium is-style-nb-survol"><img alt="" style="aspect-ratio:3/2;object-fit:contain"/></figure>
		<!-- /wp:image -->

		<!-- wp:image {"aspectRatio":"3/2","scale":"contain","sizeSlug":"medium","className":"is-style-nb-survol"} -->
		<figure class="wp-block-image size-medium is-style-nb-survol"><img alt="" style="aspect-ratio:3/2;object-fit:contain"/></figure>
		<!-- /wp:image -->

		<!-- wp:image {"aspectRatio":"3/2","scale":"contain","sizeSlug":"medium","className":"is-style-nb-survol"} -->
		<figure class="wp-block-image size-medium is-style-nb-survol"><img alt="" style="aspect-ratio:3/2;object-fit:contain"/></figure>
		<!-- /wp:image -->

		<!-- wp:image {"aspectRatio":"3/2","scale":"contain","sizeSlug":"medium","className":"is-style-nb-survol"} -->
		<figure class="wp-block-image size-medium is-style-nb-survol"><img alt="" style="aspect-ratio:3/2;object-fit:contain"/></figure>
		<!-- /wp:image -->

		<!-- wp:image {"aspectRatio":"3/2","scale":"contain","sizeSlug":"medium","className":"is-style-nb-survol"} -->
		<figure class="wp-block-image size-medium is-style-nb-survol"><img alt="" style="aspect-ratio:3/2;object-fit:contain"/></figure>
		<!-- /wp:image -->

		<!-- wp:image {"aspectRatio":"3/2","scale":"contain","sizeSlug":"medium","className":"is-style-nb-survol"} -->
		<figure class="wp-block-image size-medium is-style-nb-survol"><img alt="" style="aspect-ratio:3/2;object-fit:contain"/></figure>
		<!-- /wp:image -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
