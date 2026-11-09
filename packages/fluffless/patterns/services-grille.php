<?php
/**
 * Title: Services grid
 * Slug: fluffless/services-grille
 * Categories: fluffless-presenter
 * Keywords: services, offer, cards, grid
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Three services in an asymmetric grid: the featured service spans two columns in the accent colour, and the other two stack beside it.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"60%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:60%">
			<!-- wp:heading {"fontSize":"xx-large"} -->
			<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Three concrete ways we can help', 'fluffless' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"40%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:40%">
			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'One sentence on who these services are for. Put the most requested one in the big tile.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"layout":{"columnSpan":2,"rowSpan":2},"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|60"},"blockGap":"var:preset|spacing|50"},"dimensions":{"minHeight":"24rem"}},"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"space-between"}} -->
		<div class="wp-block-group has-on-accent-color has-accent-background-color has-text-color has-background" style="min-height:24rem;padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--60)">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"on-accent","fontSize":"x-large"} -->
			<p class="has-on-accent-color has-text-color has-x-large-font-size" style="font-weight:800">01</p>
			<!-- /wp:paragraph -->

			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:heading {"level":3,"fontSize":"xx-large"} -->
				<h3 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Your featured service', 'fluffless' ); ?></h3>
				<!-- /wp:heading -->

				<!-- wp:paragraph {"fontSize":"medium"} -->
				<p class="has-medium-font-size"><?php esc_html_e( 'What people get, in one or two sentences. Name the result, not the method.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:buttons -->
				<div class="wp-block-buttons">
					<!-- wp:button {"backgroundColor":"base","textColor":"contrast","className":"is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/services/' ) ); ?>"} -->
					<div class="wp-block-button is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/services/' ) ); ?>"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/services/' ) ); ?>"><?php esc_html_e( 'See the details', 'fluffless' ); ?></a></div>
					<!-- /wp:button -->
				</div>
				<!-- /wp:buttons -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"is-style-bordure-brute","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"textColor":"contrast","layout":{"type":"default"}} -->
		<div class="wp-block-group is-style-bordure-brute has-contrast-color has-text-color">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"accent","fontSize":"x-large"} -->
			<p class="has-accent-color has-text-color has-x-large-font-size" style="font-weight:800">02</p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":3} -->
			<h3 class="wp-block-heading"><?php esc_html_e( 'Second service', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Who it’s for, and what it changes. One sentence.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"is-style-carte","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group is-style-carte">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800"}},"textColor":"accent","fontSize":"x-large"} -->
			<p class="has-accent-color has-text-color has-x-large-font-size" style="font-weight:800">03</p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":3} -->
			<h3 class="wp-block-heading"><?php esc_html_e( 'Third service', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'A practical detail helps people decide: length, format, starting price.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
