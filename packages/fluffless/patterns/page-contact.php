<?php
/**
 * Title: Contact page
 * Slug: fluffless/page-contact
 * Categories: fluffless-pages
 * Keywords: contact, address, hours, page, full page, style-brut
 * Viewport Width: 1400
 * Block Types: core/post-content
 * Post Types: page
 * Description: A full Contact page: a short headline, your details with hours and a static map, then the answers that save an email.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|20","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:paragraph {"align":"wide","style":{"typography":{"fontWeight":"700"}},"textColor":"accent","fontSize":"small"} -->
	<p class="alignwide has-accent-color has-text-color has-small-font-size" style="font-weight:700"><?php esc_html_e( 'Contact', 'fluffless' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"metadata":{"name":"fl:accroche"},"level":1,"align":"wide","className":"is-style-geant"} -->
	<h1 class="wp-block-heading alignwide is-style-geant"><?php esc_html_e( 'Let’s talk', 'fluffless' ); ?></h1>
	<!-- /wp:heading -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"55%","style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
		<div class="wp-block-column" style="flex-basis:55%">
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700"}},"fontSize":"large"} -->
			<p class="has-large-font-size" style="font-weight:700"><?php esc_html_e( 'Write to us. A human answers.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"metadata":{"name":"fl:delai"},"textColor":"muted","fontSize":"medium"} -->
			<p class="has-muted-color has-text-color has-medium-font-size"><?php esc_html_e( 'How soon do you reply, and what should people include in their first message? Say it here.', 'fluffless' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group">
				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
				<div class="wp-block-group">
					<!-- wp:paragraph {"fontSize":"small"} -->
					<p class="has-small-font-size"><strong><?php esc_html_e( 'Email', 'fluffless' ); ?></strong></p>
					<!-- /wp:paragraph -->

					<!-- wp:paragraph {"metadata":{"name":"fl:courriel"},"fontSize":"large"} -->
					<p class="has-large-font-size"><?php esc_html_e( 'hello@example.ca', 'fluffless' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
				<div class="wp-block-group">
					<!-- wp:paragraph {"fontSize":"small"} -->
					<p class="has-small-font-size"><strong><?php esc_html_e( 'Phone', 'fluffless' ); ?></strong></p>
					<!-- /wp:paragraph -->

					<!-- wp:paragraph {"metadata":{"name":"fl:telephone"},"fontSize":"large"} -->
					<p class="has-large-font-size"><?php esc_html_e( '555-555-0123', 'fluffless' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
				<div class="wp-block-group">
					<!-- wp:paragraph {"fontSize":"small"} -->
					<p class="has-small-font-size"><strong><?php esc_html_e( 'Address', 'fluffless' ); ?></strong></p>
					<!-- /wp:paragraph -->

					<!-- wp:paragraph {"metadata":{"name":"fl:adresse"},"fontSize":"large"} -->
					<p class="has-large-font-size"><?php esc_html_e( 'Street address, city, province, postal code', 'fluffless' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"45%","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
		<div class="wp-block-column" style="flex-basis:45%">
			<!-- wp:image {"aspectRatio":"4/5","scale":"cover","sizeSlug":"large","className":"is-style-cadre-decale"} -->
			<figure class="wp-block-image size-large is-style-cadre-decale"><img alt="" style="aspect-ratio:4/5;object-fit:cover"/><figcaption class="wp-element-caption"><?php esc_html_e( 'Replace with a screenshot of your map (OpenStreetMap works well). In the alt text, write the address and the nearest landmark.', 'fluffless' ); ?></figcaption></figure>
			<!-- /wp:image -->

			<!-- wp:group {"className":"is-style-carte","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
			<div class="wp-block-group is-style-carte">
				<!-- wp:heading {"fontSize":"large"} -->
				<h2 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'Opening hours', 'fluffless' ); ?></h2>
				<!-- /wp:heading -->

				<!-- wp:table {"className":"is-style-regular"} -->
				<figure class="wp-block-table is-style-regular"><table class="has-fixed-layout"><tbody><tr><td><?php esc_html_e( 'Monday to Thursday', 'fluffless' ); ?></td><td><?php esc_html_e( '9 a.m. to 5 p.m.', 'fluffless' ); ?></td></tr><tr><td><?php esc_html_e( 'Friday', 'fluffless' ); ?></td><td><?php esc_html_e( '9 a.m. to noon', 'fluffless' ); ?></td></tr><tr><td><?php esc_html_e( 'Weekends and holidays', 'fluffless' ); ?></td><td><?php esc_html_e( 'Closed', 'fluffless' ); ?></td></tr></tbody></table></figure>
				<!-- /wp:table -->

				<!-- wp:buttons -->
				<div class="wp-block-buttons">
					<!-- wp:button {"className":"is-style-contour"} -->
					<div class="wp-block-button is-style-contour"><a class="wp-block-button__link wp-element-button" href="#"><?php esc_html_e( 'Get directions', 'fluffless' ); ?></a></div>
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
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'How quickly will I get an answer?', 'fluffless' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Give a real delay, for example two business days, and say what happens when you are away.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->

			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'Can we meet by video or in person?', 'fluffless' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Say which formats you offer, how long a first meeting lasts and how to book it.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->

			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'Do you travel to clients?', 'fluffless' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Name the area you cover and whether travel costs extra. If you only work remotely, say so.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->

			<!-- wp:details {"className":"is-style-bordure-brute"} -->
			<details class="wp-block-details is-style-bordure-brute"><summary><?php esc_html_e( 'What should I put in my first message?', 'fluffless' ); ?></summary>
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'List the two or three details that let you give a useful answer on the first try.', 'fluffless' ); ?></p>
				<!-- /wp:paragraph -->
			</details>
			<!-- /wp:details -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
