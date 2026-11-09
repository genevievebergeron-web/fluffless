<?php
/**
 * Title: Editorial opening
 * Slug: fluffless/editorial-ouverture
 * Categories: fluffless-ouvertures
 * Keywords: hero, opening, magazine, serif, editorial, headline, style-editorial
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: A magazine cover in black and white: a thin rule, a narrow margin column for the section name, a large serif headline, a standfirst and a byline. The wide image comes after the words.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|70"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:separator {"backgroundColor":"contrast","className":"is-style-wide"} -->
		<hr class="wp-block-separator has-text-color has-contrast-color has-alpha-channel-opacity has-contrast-background-color has-background is-style-wide"/>
		<!-- /wp:separator -->

		<!-- wp:columns {"style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|70"}}}} -->
		<div class="wp-block-columns">
			<!-- wp:column {"width":"22%","style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
			<div class="wp-block-column" style="flex-basis:22%">
				<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.12em"}},"textColor":"accent","fontSize":"small"} -->
				<p class="has-accent-color has-text-color has-small-font-size" style="letter-spacing:0.12em;text-transform:uppercase"><?php esc_html_e( 'Section name', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph {"textColor":"muted","fontSize":"small","fontFamily":"serif"} -->
				<p class="has-muted-color has-text-color has-serif-font-family has-small-font-size"><em><?php esc_html_e( 'Date, or issue number', 'fluffless-suite' ); ?></em></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column {"width":"78%","style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
			<div class="wp-block-column" style="flex-basis:78%">
				<!-- wp:heading {"level":1,"style":{"typography":{"lineHeight":"1.02","fontWeight":"400"}},"fontSize":"giant","fontFamily":"didone"} -->
				<h1 class="wp-block-heading has-didone-font-family has-giant-font-size" style="font-weight:400;line-height:1.02"><?php esc_html_e( 'A headline that makes a claim, not a label', 'fluffless-suite' ); ?></h1>
				<!-- /wp:heading -->

				<!-- wp:paragraph {"style":{"typography":{"lineHeight":"1.4"}},"fontSize":"x-large","fontFamily":"serif"} -->
				<p class="has-serif-font-family has-x-large-font-size" style="line-height:1.4"><em><?php esc_html_e( 'The standfirst: two sentences that tell readers what they will understand by the end, and why it matters to them now.', 'fluffless-suite' ); ?></em></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
				<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'By first and last name · 6 min read', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->
	</div>
	<!-- /wp:group -->

	<!-- wp:image {"align":"wide","aspectRatio":"21/9","scale":"cover","sizeSlug":"full"} -->
	<figure class="wp-block-image alignwide size-full"><img alt="" style="aspect-ratio:21/9;object-fit:cover"/><figcaption class="wp-element-caption"><?php esc_html_e( 'A caption that adds something the photo cannot say. Credit the photographer.', 'fluffless-suite' ); ?></figcaption></figure>
	<!-- /wp:image -->
</div>
<!-- /wp:group -->
