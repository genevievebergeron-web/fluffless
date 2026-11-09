<?php
/**
 * Title: Services page
 * Slug: fluffless/page-services
 * Categories: fluffless-pages
 * Keywords: services, offer, pricing, page, full page, style-brut
 * Viewport Width: 1400
 * Block Types: core/post-content
 * Post Types: page
 * Description: A full Services page: a short opening, each service with its image, the questions people ask before buying, and a single call to action. One filled button per screen: the opener and the closing band; the section in between uses the outlined button.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"metadata":{"name":"fl:promesse"},"level":1,"align":"wide","className":"is-style-geant"} -->
	<h1 class="wp-block-heading alignwide is-style-geant"><?php esc_html_e( 'Your promise, in one sentence that holds up', 'fluffless' ); ?></h1>
	<!-- /wp:heading -->

	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"38%","style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:38%">
			<!-- wp:paragraph {"metadata":{"name":"fl:probleme"},"fontSize":"medium"} -->
			<p class="has-medium-font-size"><?php esc_html_e( 'Two or three sentences: the problem you solve, who you solve it for, and what sets you apart. Write the way you’d talk to a client.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"} -->
				<div class="wp-block-button is-style-fleche<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Your main action', 'fluffless' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->

			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
				<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'A concrete guarantee, like how fast you reply', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"bottom","width":"62%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:62%">
			<!-- wp:group {"className":"fluffless-cadre-image","style":{"dimensions":{"minHeight":"20rem"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group fluffless-cadre-image" style="min-height:20rem">
				<!-- wp:image {"aspectRatio":"4/3","scale":"cover","sizeSlug":"large","className":"is-style-cadre-decale"} -->
				<figure class="wp-block-image size-large is-style-cadre-decale"><img alt="" style="aspect-ratio:4/3;object-fit:cover"/></figure>
				<!-- /wp:image -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|70"}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"align":"wide","className":"is-style-geant","textColor":"accent"} -->
	<h2 class="wp-block-heading alignwide is-style-geant has-accent-color has-text-color"><?php esc_html_e( 'Our services', 'fluffless' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|60"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"16%"} -->
		<div class="wp-block-column" style="flex-basis:16%">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"900","lineHeight":"0.9"}},"textColor":"accent-2","fontSize":"xx-large"} -->
			<p class="has-accent-2-color has-text-color has-xx-large-font-size" style="font-weight:900;line-height:0.9"><?php esc_html_e( '01', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"42%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:42%">
			<!-- wp:heading {"metadata":{"name":"fl:service-1"},"level":3,"fontSize":"x-large"} -->
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
				<!-- wp:button {"className":"is-style-contour<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"} -->
				<div class="wp-block-button is-style-contour<?php echo esc_attr( ' ' . fluffless_page_class( '/contact/' ) ); ?>"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( fluffless_page_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Let’s talk', 'fluffless' ); ?></a></div>
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
			<!-- wp:heading {"metadata":{"name":"fl:service-2"},"level":3,"fontSize":"x-large"} -->
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

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"36%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:36%">
			<!-- wp:heading {"fontSize":"xx-large"} -->
			<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'The questions we hear every week', 'fluffless' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'Copy the real questions from your inbox, in your clients’ own words. If an answer needs three paragraphs, it deserves its own page.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"64%","style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column" style="flex-basis:64%">
			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'How much does it cost?', 'fluffless' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Give a starting price or a range. People leave when they have to ask for it.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->

			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'How long does it take?', 'fluffless' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'A realistic timeline, from the first call to the finished work. Mention what can slow things down.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->

			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'Who is it not for?', 'fluffless' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Saying no clearly saves everyone time, and it makes the right people trust you more.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->

			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'What happens after I get in touch?', 'fluffless' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Walk through the next three steps. Nobody likes wondering whether their message got lost.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->
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
