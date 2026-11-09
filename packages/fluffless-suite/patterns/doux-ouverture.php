<?php
/**
 * Title: Soft opening
 * Slug: fluffless/doux-ouverture
 * Categories: fluffless-ouvertures
 * Keywords: hero, opening, soft, rounded, calm, welcome, style-doux
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: A calm welcome on a soft surface: rounded heading, a reassuring sentence, one button, and a rounded image with a gentle shadow. For services where people arrive a little worried.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"center","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|80"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-center">
		<!-- wp:column {"verticalAlignment":"center","width":"54%","style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:54%">
			<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"left"}} -->
			<div class="wp-block-group">
				<!-- wp:group {"style":{"border":{"radius":"var(--wp--custom--radius--round)"},"spacing":{"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|40","bottom":"var:preset|spacing|20","left":"var:preset|spacing|30"},"blockGap":"var:preset|spacing|20"}},"backgroundColor":"base","layout":{"type":"flex","flexWrap":"nowrap"}} -->
				<div class="wp-block-group has-base-background-color has-background" style="border-radius:var(--wp--custom--radius--round);padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30)">
					<!-- wp:fluffless/icone {"icon":"leaf","size":"small","textColor":"accent"} /-->

					<!-- wp:paragraph {"fontSize":"small"} -->
					<p class="has-small-font-size"><?php esc_html_e( 'Who you help, in a few words', 'fluffless-suite' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:group -->

			<!-- wp:heading {"level":1,"style":{"typography":{"lineHeight":"1.1"}},"fontSize":"xx-large","fontFamily":"rounded"} -->
			<h1 class="wp-block-heading has-rounded-font-family has-xx-large-font-size" style="line-height:1.1"><?php esc_html_e( 'Say what gets easier, gently', 'fluffless-suite' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"large"} -->
			<p class="has-muted-color has-text-color has-large-font-size"><?php esc_html_e( 'Name the worry people arrive with, then how you take it off their shoulders. Two lines, no promises you can’t keep.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"style":{"border":{"radius":"var(--wp--custom--radius--round)"}}} -->
				<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" style="border-radius:var(--wp--custom--radius--round)"><?php esc_html_e( 'Book a first conversation', 'fluffless-suite' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"center","width":"46%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:46%">
			<!-- wp:image {"aspectRatio":"4/5","scale":"cover","sizeSlug":"large","style":{"border":{"radius":"var(--wp--custom--radius--soft)"},"shadow":"var:preset|shadow|soft"}} -->
			<figure class="wp-block-image size-large has-custom-border"><img alt="" style="border-radius:var(--wp--custom--radius--soft);box-shadow:var(--wp--preset--shadow--soft);aspect-ratio:4/5;object-fit:cover"/></figure>
			<!-- /wp:image -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
