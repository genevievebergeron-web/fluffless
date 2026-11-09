<?php
/**
 * Title: Donate page
 * Slug: fluffless/page-don
 * Categories: fluffless-pages
 * Keywords: donate, donation, give, support, charity, nonprofit, page, full page
 * Viewport Width: 1400
 * Block Types: core/post-content
 * Post Types: page
 * Description: A full Donate page: what a gift changes, the cause, what one gift makes possible, where the money goes, two ways to help, then one clear button.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:paragraph {"align":"wide","textColor":"accent","fontSize":"small"} -->
	<p class="alignwide has-accent-color has-text-color has-small-font-size"><?php esc_html_e( 'Donate', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"metadata":{"name":"fl:accroche"},"level":1,"align":"wide","className":"is-style-geant"} -->
	<h1 class="wp-block-heading alignwide is-style-geant"><?php esc_html_e( 'Say what a donation changes, in a few words.', 'fluffless-suite' ); ?></h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"metadata":{"name":"fl:cause"},"align":"wide","textColor":"muted","fontSize":"medium"} -->
	<p class="alignwide has-muted-color has-text-color has-medium-font-size"><?php esc_html_e( 'Name the cause and the people it serves. One or two sentences are enough.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons {"align":"wide"} -->
	<div class="wp-block-buttons alignwide">
		<!-- wp:button -->
		<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php esc_html_e( 'Make a donation', 'fluffless-suite' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|50","bottom":"var:preset|spacing|70","left":"var:preset|spacing|50"}}},"backgroundColor":"surface","textColor":"contrast","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-color has-surface-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|60","left":"var:preset|spacing|70"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column">
			<!-- wp:heading {"fontSize":"x-large"} -->
			<h2 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'What one gift makes possible', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"metadata":{"name":"fl:impact"},"fontSize":"large"} -->
			<p class="has-large-font-size"><?php esc_html_e( 'Give a concrete amount and what it covers, for example a week of meals.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
		<div class="wp-block-column">
			<!-- wp:heading {"fontSize":"x-large"} -->
			<h2 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Where the money goes', 'fluffless-suite' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"metadata":{"name":"fl:transparence"}} -->
			<p><?php esc_html_e( 'Share the part that goes to the cause and link to the annual report. Clear numbers build trust.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:quote {"align":"wide"} -->
	<blockquote class="wp-block-quote alignwide">
		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'Paste a real sentence from a person you helped or a client, word for word and with their consent.', 'fluffless-suite' ); ?></p>
		<!-- /wp:paragraph -->
	</blockquote>
	<!-- /wp:quote -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|60"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"align":"wide","fontSize":"xx-large"} -->
	<h2 class="wp-block-heading alignwide has-xx-large-font-size"><?php esc_html_e( 'Two ways to help', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|50","left":"var:preset|spacing|50"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"style":{"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|50","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"surface"} -->
		<div class="wp-block-column has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)">
			<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
			<h3 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Give', 'fluffless-suite' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Name what one gift covers, for example a week of meals or a school kit. A concrete amount helps people decide.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"muted","fontSize":"small"} -->
			<p class="has-muted-color has-text-color has-small-font-size"><?php esc_html_e( 'Mention official donation receipts here if you issue them.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button -->
				<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php esc_html_e( 'Make a donation', 'fluffless-suite' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"style":{"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|50","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"surface"} -->
		<div class="wp-block-column has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)">
			<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
			<h3 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Volunteer', 'fluffless-suite' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Describe a typical shift: how long it lasts, what people do and who they meet.', 'fluffless-suite' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-outline"} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#"><?php esc_html_e( 'Sign up to volunteer', 'fluffless-suite' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|40"}},"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"constrained","contentSize":"40rem"}} -->
<div class="wp-block-group alignfull has-on-accent-color has-accent-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"metadata":{"name":"fl:appel"},"textAlign":"center","fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-xx-large-font-size"><?php esc_html_e( 'Write the words of the donate button as a promise.', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"backgroundColor":"base","textColor":"contrast"} -->
		<div class="wp-block-button"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" href="#"><?php esc_html_e( 'Make a donation', 'fluffless-suite' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
