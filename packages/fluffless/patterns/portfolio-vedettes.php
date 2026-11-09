<?php
/**
 * Title: Three featured projects
 * Slug: fluffless/portfolio-vedettes
 * Categories: fluffless-presenter
 * Keywords: portfolio, projects, featured, work, case studies, showcase, style-brut
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: Three projects you pick by hand: the strongest one takes two thirds of the width, the other two stack beside it. Each has an image, its type, a title and one line of result.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:heading {"fontSize":"xx-large"} -->
		<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Three projects we’re proud of', 'fluffless' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:buttons -->
		<div class="wp-block-buttons">
			<!-- wp:button {"backgroundColor":"accent","textColor":"on-accent","className":"is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/portfolio/' ) ); ?>"} -->
			<div class="wp-block-button is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/portfolio/' ) ); ?>"><a class="wp-block-button__link has-on-accent-color has-accent-background-color has-text-color has-background wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/portfolio/' ) ); ?>"><?php esc_html_e( 'See all projects', 'fluffless' ); ?></a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"layout":{"columnSpan":2,"rowSpan":2},"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:group {"className":"fluffless-cadre-image","layout":{"type":"default"}} -->
			<div class="wp-block-group fluffless-cadre-image">
				<!-- wp:image {"aspectRatio":"4/3","scale":"cover","sizeSlug":"large","className":"is-style-nb-survol"} -->
				<figure class="wp-block-image size-large is-style-nb-survol"><img alt="" style="aspect-ratio:4/3;object-fit:cover"/></figure>
				<!-- /wp:image -->
			</div>
			<!-- /wp:group -->

			<!-- wp:paragraph {"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size"><?php esc_html_e( 'Type of project', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
			<h3 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Your strongest project, linked to its case study', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'One line on the result for the client, not on the technique.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:group {"className":"fluffless-cadre-image","layout":{"type":"default"}} -->
			<div class="wp-block-group fluffless-cadre-image">
				<!-- wp:image {"aspectRatio":"3/2","scale":"cover","sizeSlug":"medium_large","className":"is-style-nb-survol"} -->
				<figure class="wp-block-image size-medium_large is-style-nb-survol"><img alt="" style="aspect-ratio:3/2;object-fit:cover"/></figure>
				<!-- /wp:image -->
			</div>
			<!-- /wp:group -->

			<!-- wp:paragraph {"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size"><?php esc_html_e( 'Type of project', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'Second project', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:group {"className":"fluffless-cadre-image","layout":{"type":"default"}} -->
			<div class="wp-block-group fluffless-cadre-image">
				<!-- wp:image {"aspectRatio":"3/2","scale":"cover","sizeSlug":"medium_large","className":"is-style-nb-survol"} -->
				<figure class="wp-block-image size-medium_large is-style-nb-survol"><img alt="" style="aspect-ratio:3/2;object-fit:cover"/></figure>
				<!-- /wp:image -->
			</div>
			<!-- /wp:group -->

			<!-- wp:paragraph {"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size"><?php esc_html_e( 'Type of project', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'Third project', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
