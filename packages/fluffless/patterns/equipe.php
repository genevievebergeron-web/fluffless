<?php
/**
 * Title: Team mosaic
 * Slug: fluffless/equipe
 * Categories: fluffless-presenter
 * Keywords: team, people, staff, about, portraits, style-brut
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: The introduction takes two cells, then one portrait per person with name, role and one line that is truly theirs. The last card invites people to join.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"15rem"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"layout":{"columnSpan":2},"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"bottom"}} -->
		<div class="wp-block-group">
			<!-- wp:heading {"fontSize":"xx-large"} -->
			<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'The people who pick up the phone', 'fluffless' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"medium"} -->
			<p class="has-muted-color has-text-color has-medium-font-size"><?php esc_html_e( 'Why this team, for these clients? One or two sentences. Skip “passionate”: everyone says it.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:group {"className":"fluffless-cadre-image","layout":{"type":"default"}} -->
			<div class="wp-block-group fluffless-cadre-image">
				<!-- wp:image {"aspectRatio":"3/4","scale":"cover","sizeSlug":"medium","className":"is-style-cadre-decale"} -->
				<figure class="wp-block-image size-medium is-style-cadre-decale"><img alt="" style="aspect-ratio:3/4;object-fit:cover"/></figure>
				<!-- /wp:image -->
			</div>
			<!-- /wp:group -->

			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'First and last name', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size"><?php esc_html_e( 'Role, in plain words', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'One thing this person does that nobody else on the team does.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:group {"className":"fluffless-cadre-image","layout":{"type":"default"}} -->
			<div class="wp-block-group fluffless-cadre-image">
				<!-- wp:image {"aspectRatio":"3/4","scale":"cover","sizeSlug":"medium","className":"is-style-nb-survol"} -->
				<figure class="wp-block-image size-medium is-style-nb-survol"><img alt="" style="aspect-ratio:3/4;object-fit:cover"/></figure>
				<!-- /wp:image -->
			</div>
			<!-- /wp:group -->

			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'First and last name', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size"><?php esc_html_e( 'Role, in plain words', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'One thing this person does that nobody else on the team does.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:group {"className":"fluffless-cadre-image","layout":{"type":"default"}} -->
			<div class="wp-block-group fluffless-cadre-image">
				<!-- wp:image {"aspectRatio":"3/4","scale":"cover","sizeSlug":"medium","className":"is-style-nb-survol"} -->
				<figure class="wp-block-image size-medium is-style-nb-survol"><img alt="" style="aspect-ratio:3/4;object-fit:cover"/></figure>
				<!-- /wp:image -->
			</div>
			<!-- /wp:group -->

			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'First and last name', 'fluffless' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"accent","fontSize":"small"} -->
			<p class="has-accent-color has-text-color has-small-font-size"><?php esc_html_e( 'Role, in plain words', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'One thing this person does that nobody else on the team does.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"is-style-carte","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"space-between"}} -->
		<div class="wp-block-group is-style-carte">
			<!-- wp:paragraph {"fontSize":"large"} -->
			<p class="has-large-font-size"><?php esc_html_e( 'Hiring? Say so here, and who you are looking for.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-contour<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"} -->
				<div class="wp-block-button is-style-contour<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Send your application', 'fluffless' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
