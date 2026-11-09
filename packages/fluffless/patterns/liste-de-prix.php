<?php
/**
 * Title: Price list
 * Slug: fluffless/liste-de-prix
 * Categories: fluffless-presenter
 * Keywords: pricing, prices, plans, offers, packages, tiles
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Three raw-bordered tiles, each with a plan name, a price, three included items and a button. Only the recommended plan gets the filled button.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"align":"wide","fontSize":"xx-large"} -->
	<h2 class="wp-block-heading alignwide has-xx-large-font-size"><?php esc_html_e( 'Prices shown for the essentials', 'fluffless' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|50"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"className":"is-style-bordure-brute","style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column is-style-bordure-brute">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}},"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size" style="font-weight:700"><?php esc_html_e( 'Starter', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800","lineHeight":"1"}},"fontSize":"xx-large"} -->
			<p class="has-xx-large-font-size" style="font-weight:800;line-height:1"><?php esc_html_e( '$490', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'Who this plan is for, in one sentence.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:list {"className":"is-style-coches"} -->
			<ul class="wp-block-list is-style-coches">
				<!-- wp:list-item -->
				<li><?php esc_html_e( 'First thing included', 'fluffless' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php esc_html_e( 'Second thing included', 'fluffless' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php esc_html_e( 'Third thing included', 'fluffless' ); ?></li>
				<!-- /wp:list-item -->
			</ul>
			<!-- /wp:list -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-contour<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"} -->
				<div class="wp-block-button is-style-contour<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Choose this plan', 'fluffless' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"className":"is-style-bordure-brute","style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column is-style-bordure-brute">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}},"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size" style="font-weight:700"><?php esc_html_e( 'Recommended', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800","lineHeight":"1"}},"fontSize":"xx-large"} -->
			<p class="has-xx-large-font-size" style="font-weight:800;line-height:1"><?php esc_html_e( '$990', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'The plan most people pick, and why.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:list {"className":"is-style-coches"} -->
			<ul class="wp-block-list is-style-coches">
				<!-- wp:list-item -->
				<li><?php esc_html_e( 'Everything in the first plan', 'fluffless' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php esc_html_e( 'One more thing people ask for', 'fluffless' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php esc_html_e( 'A concrete delivery time', 'fluffless' ); ?></li>
				<!-- /wp:list-item -->
			</ul>
			<!-- /wp:list -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"} -->
				<div class="wp-block-button is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Choose this plan', 'fluffless' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"className":"is-style-bordure-brute","style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column is-style-bordure-brute">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}},"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size" style="font-weight:700"><?php esc_html_e( 'Custom', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"800","lineHeight":"1"}},"fontSize":"x-large"} -->
			<p class="has-x-large-font-size" style="font-weight:800;line-height:1"><?php esc_html_e( 'On request', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'For the projects that do not fit a plan.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:list {"className":"is-style-coches"} -->
			<ul class="wp-block-list is-style-coches">
				<!-- wp:list-item -->
				<li><?php esc_html_e( 'Scope set together', 'fluffless' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php esc_html_e( 'A written estimate within a week', 'fluffless' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php esc_html_e( 'One contact person', 'fluffless' ); ?></li>
				<!-- /wp:list-item -->
			</ul>
			<!-- /wp:list -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-contour<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"} -->
				<div class="wp-block-button is-style-contour<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Tell us about it', 'fluffless' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
