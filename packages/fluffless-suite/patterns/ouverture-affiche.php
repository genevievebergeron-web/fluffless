<?php
/**
 * Title: Activist poster
 * Slug: fluffless/ouverture-affiche
 * Categories: fluffless-ouvertures
 * Keywords: hero, opener, poster, cause, mobilization
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: A full-width poster on a cream background, with a giant banner headline on the left and a raw-bordered box holding the call to action on the right. For a cause, a campaign or a mobilization.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"templateLock":"contentOnly","align":"full","className":"is-style-inverse","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"}}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-inverse has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"60%","style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:60%">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800","textTransform":"uppercase","letterSpacing":"0.08em"}},"fontSize":"small"} -->
			<p class="has-small-font-size" style="font-weight:800;letter-spacing:0.08em;text-transform:uppercase"><?php esc_html_e( 'Name the campaign or the key date', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":1,"className":"is-style-bandeau","fontSize":"xx-large"} -->
			<h1 class="wp-block-heading is-style-bandeau has-xx-large-font-size"><?php esc_html_e( 'What you’re demanding, in everyday words', 'fluffless-suite' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"medium"} -->
			<p class="has-medium-font-size"><?php esc_html_e( 'Explain who is affected and what happens if nothing changes. One precise fact convinces more than a slogan.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"40%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:40%">
			<!-- wp:group {"className":"is-style-bordure-brute","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"textColor":"contrast","layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group is-style-bordure-brute has-contrast-color has-text-color">
				<!-- wp:fluffless/icone {"icon":"megaphone","size":"large","textColor":"accent"} /-->

				<!-- wp:paragraph {"style":{"typography":{"fontWeight":"900"}},"fontSize":"xx-large"} -->
				<p class="has-xx-large-font-size" style="font-weight:900"><?php esc_html_e( '1,250', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Swap in the number of people who have already signed or taken part. Keep it up to date.', 'fluffless-suite' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:buttons -->
				<div class="wp-block-buttons">
					<!-- wp:button {"className":"is-style-fleche"} -->
					<div class="wp-block-button is-style-fleche"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Sign the petition', 'fluffless-suite' ); ?></a></div>
					<!-- /wp:button -->
				</div>
				<!-- /wp:buttons -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
