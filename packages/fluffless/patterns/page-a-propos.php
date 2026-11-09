<?php
/**
 * Title: About page
 * Slug: fluffless/page-a-propos
 * Categories: fluffless-pages
 * Keywords: about, story, team, page, full page, style-brut
 * Viewport Width: 1400
 * Block Types: core/post-content
 * Post Types: page
 * Description: A full About page: why you exist, the numbers that back it up, the team, a client in their own words, and an invitation to get in touch.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|70","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:paragraph {"align":"wide","style":{"typography":{"fontWeight":"700"}},"textColor":"accent","fontSize":"small"} -->
	<p class="alignwide has-accent-color has-text-color has-small-font-size" style="font-weight:700"><?php esc_html_e( 'About us', 'fluffless' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"metadata":{"name":"fl:pourquoi"},"level":1,"align":"wide","className":"is-style-geant"} -->
	<h1 class="wp-block-heading alignwide is-style-geant"><?php esc_html_e( 'Why we started, in one sentence', 'fluffless' ); ?></h1>
	<!-- /wp:heading -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"33.33%"} -->
		<div class="wp-block-column" style="flex-basis:33.33%"></div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"66.66%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:66.66%">
			<!-- wp:paragraph {"metadata":{"name":"fl:histoire"},"fontSize":"large"} -->
			<p class="has-large-font-size"><?php esc_html_e( 'Tell the moment it started: a problem you saw, a person you wanted to help. A story beats a mission statement.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"metadata":{"name":"fl:refus"},"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'Then what you still refuse to do, years later. That’s what sets you apart.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"is-style-inverse","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-inverse has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"58%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:58%">
			<!-- wp:heading {"style":{"typography":{"fontWeight":"900"}},"fontSize":"xx-large"} -->
			<h2 class="wp-block-heading has-xx-large-font-size" style="font-weight:900"><?php esc_html_e( 'Facts, not adjectives', 'fluffless' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"42%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:42%">
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Pick four numbers you can back up. Put the most telling one in the big tile and always say where it comes from.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"grid","columnCount":4,"minimumColumnWidth":"13rem"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"layout":{"columnSpan":2,"rowSpan":2},"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|60"}},"dimensions":{"minHeight":"22rem"}},"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"space-between"}} -->
		<div class="wp-block-group has-on-accent-color has-accent-background-color has-text-color has-background" style="min-height:22rem;padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--60)">
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph {"style":{"typography":{"fontWeight":"900","lineHeight":"0.9"}},"fontSize":"giant"} -->
				<p class="has-giant-font-size" style="font-weight:900;line-height:0.9"><?php esc_html_e( '1,200', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph {"fontSize":"medium"} -->
				<p class="has-medium-font-size"><?php esc_html_e( 'Swap in your real number, then say what it counts: people, hours, meals, files.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"layout":{"columnSpan":2,"rowSpan":1},"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"default"}} -->
		<div class="wp-block-group has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"900"}},"textColor":"accent","fontSize":"xx-large"} -->
			<p class="has-accent-color has-text-color has-xx-large-font-size" style="font-weight:900"><?php esc_html_e( '92%', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'A percentage only means something with its source. Say where it comes from.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"is-style-bordure-brute","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"textColor":"contrast","layout":{"type":"default"}} -->
		<div class="wp-block-group is-style-bordure-brute has-contrast-color has-text-color">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"900"}},"fontSize":"xx-large"} -->
			<p class="has-xx-large-font-size" style="font-weight:900"><?php esc_html_e( '15 years', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'How long you’ve been doing this work.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"default"}} -->
		<div class="wp-block-group has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"900"}},"textColor":"accent-2","fontSize":"xx-large"} -->
			<p class="has-accent-2-color has-text-color has-xx-large-font-size" style="font-weight:900"><?php esc_html_e( '48', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size"><?php esc_html_e( 'A concrete, everyday number: projects, partners, cities.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"15rem"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"layout":{"columnSpan":2},"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"bottom"}} -->
		<div class="wp-block-group">
			<!-- wp:heading {"fontSize":"xx-large"} -->
			<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'The people who pick up the phone', 'fluffless' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"metadata":{"name":"fl:equipe"},"textColor":"muted","fontSize":"medium"} -->
			<p class="has-muted-color has-text-color has-medium-font-size"><?php esc_html_e( 'Why this team, for these clients? One or two sentences. Skip “passionate”: everyone says it.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
		<div class="wp-block-group">
			<!-- wp:image {"aspectRatio":"3/4","scale":"cover","sizeSlug":"medium","className":"is-style-cadre-decale"} -->
			<figure class="wp-block-image size-medium is-style-cadre-decale"><img alt="" style="aspect-ratio:3/4;object-fit:cover"/></figure>
			<!-- /wp:image -->

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
			<!-- wp:image {"aspectRatio":"3/4","scale":"cover","sizeSlug":"medium","className":"is-style-nb-survol"} -->
			<figure class="wp-block-image size-medium is-style-nb-survol"><img alt="" style="aspect-ratio:3/4;object-fit:cover"/></figure>
			<!-- /wp:image -->

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
			<!-- wp:image {"aspectRatio":"3/4","scale":"cover","sizeSlug":"medium","className":"is-style-nb-survol"} -->
			<figure class="wp-block-image size-medium is-style-nb-survol"><img alt="" style="aspect-ratio:3/4;object-fit:cover"/></figure>
			<!-- /wp:image -->

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

<!-- wp:group {"align":"full","className":"is-style-inverse","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-inverse" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:quote {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"fontSize":"xx-large"} -->
	<blockquote class="wp-block-quote alignwide has-xx-large-font-size">
		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'Paste a real sentence from a client, word for word. The one where they describe what changed for them, not how nice you were.', 'fluffless' ); ?></p>
		<!-- /wp:paragraph -->
	</blockquote>
	<!-- /wp:quote -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|50"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"58%"} -->
		<div class="wp-block-column" style="flex-basis:58%"></div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"42%"} -->
		<div class="wp-block-column" style="flex-basis:42%">
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group">
				<!-- wp:image {"width":"6rem","aspectRatio":"1","scale":"cover","sizeSlug":"thumbnail","className":"is-style-nb-survol"} -->
				<figure class="wp-block-image size-thumbnail is-resized is-style-nb-survol"><img alt="" style="aspect-ratio:1;object-fit:cover;width:6rem;height:auto"/></figure>
				<!-- /wp:image -->

				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
				<div class="wp-block-group">
					<!-- wp:paragraph {"fontSize":"large"} -->
					<p class="has-large-font-size"><strong><?php esc_html_e( 'Client’s name', 'fluffless' ); ?></strong></p>
					<!-- /wp:paragraph -->

					<!-- wp:paragraph {"fontSize":"small"} -->
					<p class="has-small-font-size"><?php esc_html_e( 'Role and organization, with permission', 'fluffless' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"}}},"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-on-accent-color has-accent-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"68%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:68%">
			<!-- wp:heading {"metadata":{"name":"fl:appel"},"className":"is-style-geant"} -->
			<h2 class="wp-block-heading is-style-geant"><?php esc_html_e( 'One sentence that makes people want to act today', 'fluffless' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"32%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:32%">
			<!-- wp:paragraph {"fontSize":"medium"} -->
			<p class="has-medium-font-size"><?php esc_html_e( 'Say what happens after the click: a 20-minute call, a short form, a two-step donation.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"backgroundColor":"base","textColor":"contrast","className":"is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"} -->
				<div class="wp-block-button is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Your next step', 'fluffless' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
