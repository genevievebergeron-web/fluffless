<?php
/**
 * Title: Sliding gallery
 * Slug: fluffless/galerie-glisse
 * Categories: fluffless-presenter
 * Keywords: gallery, photos, images, carousel, slider, horizontal scroll, portfolio
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Photos with short captions in a row that scrolls sideways and snaps into place. No script and no autoplay.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:heading {"fontSize":"xx-large"} -->
		<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Seen in the field', 'fluffless-suite' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"textColor":"muted"} -->
		<p class="has-muted-color has-text-color"><?php esc_html_e( 'Scroll sideways for more. Describe each photo in its alternative text, then give it a caption of a few words.', 'fluffless-suite' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"align":"wide","className":"is-style-defilement","layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"top"}} -->
	<div class="wp-block-group alignwide is-style-defilement">
		<!-- wp:image {"aspectRatio":"4/5","scale":"cover","sizeSlug":"large"} -->
		<figure class="wp-block-image size-large"><img alt="" style="aspect-ratio:4/5;object-fit:cover"/><figcaption class="wp-element-caption"><?php esc_html_e( 'Caption of a few words', 'fluffless-suite' ); ?></figcaption></figure>
		<!-- /wp:image -->

		<!-- wp:image {"aspectRatio":"4/5","scale":"cover","sizeSlug":"large"} -->
		<figure class="wp-block-image size-large"><img alt="" style="aspect-ratio:4/5;object-fit:cover"/><figcaption class="wp-element-caption"><?php esc_html_e( 'Caption of a few words', 'fluffless-suite' ); ?></figcaption></figure>
		<!-- /wp:image -->

		<!-- wp:image {"aspectRatio":"4/5","scale":"cover","sizeSlug":"large"} -->
		<figure class="wp-block-image size-large"><img alt="" style="aspect-ratio:4/5;object-fit:cover"/><figcaption class="wp-element-caption"><?php esc_html_e( 'Caption of a few words', 'fluffless-suite' ); ?></figcaption></figure>
		<!-- /wp:image -->

		<!-- wp:image {"aspectRatio":"4/5","scale":"cover","sizeSlug":"large"} -->
		<figure class="wp-block-image size-large"><img alt="" style="aspect-ratio:4/5;object-fit:cover"/><figcaption class="wp-element-caption"><?php esc_html_e( 'Caption of a few words', 'fluffless-suite' ); ?></figcaption></figure>
		<!-- /wp:image -->

		<!-- wp:image {"aspectRatio":"4/5","scale":"cover","sizeSlug":"large"} -->
		<figure class="wp-block-image size-large"><img alt="" style="aspect-ratio:4/5;object-fit:cover"/><figcaption class="wp-element-caption"><?php esc_html_e( 'Caption of a few words', 'fluffless-suite' ); ?></figcaption></figure>
		<!-- /wp:image -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
