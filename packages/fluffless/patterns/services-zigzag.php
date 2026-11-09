<?php
/**
 * Title: Services zigzag
 * Slug: fluffless/services-zigzag
 * Categories: fluffless-presenter
 * Keywords: services, zigzag, alternating, image, text
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Two services alternating text and image, each with a very large number in the margin, so you can take the time to explain each one.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|70"}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"align":"wide","textColor":"accent","fontSize":"xx-large"} -->
	<h2 class="wp-block-heading alignwide has-accent-color has-text-color has-xx-large-font-size"><?php esc_html_e( 'Our services', 'fluffless' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|60"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"16%"} -->
		<div class="wp-block-column" style="flex-basis:16%">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"900","lineHeight":"0.9"}},"textColor":"accent","fontSize":"xx-large"} -->
			<p class="has-accent-color has-text-color has-xx-large-font-size" style="font-weight:900;line-height:0.9"><?php esc_html_e( '01', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"42%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:42%">
			<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
			<h3 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'First service, named the way your clients name it', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Describe where the person started, then what changed afterward. Three or four sentences, no more.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:list {"className":"is-style-coches"} -->
			<ul class="wp-block-list is-style-coches"><!-- wp:list-item -->
<li><?php esc_html_e( 'What’s included', 'fluffless' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'How long it takes', 'fluffless' ); ?></li>
<!-- /wp:list-item --></ul>
			<!-- /wp:list -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"} -->
				<div class="wp-block-button is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Let’s talk', 'fluffless' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"42%"} -->
		<div class="wp-block-column" style="flex-basis:42%">
			<!-- wp:group {"className":"fluffless-cadre-image","style":{"dimensions":{"minHeight":"20rem"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group fluffless-cadre-image" style="min-height:20rem">
				<!-- wp:image {"aspectRatio":"4/3","scale":"cover","sizeSlug":"large","className":"is-style-nb-survol"} -->
				<figure class="wp-block-image size-large is-style-nb-survol"><img alt="" style="aspect-ratio:4/3;object-fit:cover"/></figure>
				<!-- /wp:image -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","className":"fluffless-zigzag-inverse","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|60"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom fluffless-zigzag-inverse">
		<!-- wp:column {"verticalAlignment":"bottom","width":"42%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:42%">
			<!-- wp:group {"className":"fluffless-cadre-image","style":{"dimensions":{"minHeight":"20rem"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group fluffless-cadre-image" style="min-height:20rem">
				<!-- wp:image {"aspectRatio":"3/4","scale":"cover","sizeSlug":"large","className":"is-style-nb-survol"} -->
				<figure class="wp-block-image size-large is-style-nb-survol"><img alt="" style="aspect-ratio:3/4;object-fit:cover"/></figure>
				<!-- /wp:image -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"42%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:42%">
			<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
			<h3 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Second service, with a clear benefit', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Same structure: the situation, the change, the proof. If you have a short testimonial, this is where it goes.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"16%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:16%">
			<!-- wp:paragraph {"align":"right","style":{"typography":{"fontWeight":"900","lineHeight":"0.9"}},"textColor":"accent","fontSize":"xx-large"} -->
			<p class="has-text-align-right has-accent-color has-text-color has-xx-large-font-size" style="font-weight:900;line-height:0.9"><?php esc_html_e( '02', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
