<?php
/**
 * Title: Frequently asked questions
 * Slug: fluffless/faq
 * Categories: fluffless-prouver
 * Keywords: faq, questions, answers, accordion, details, help, style-brut, style-editorial
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Description: The heading stays on the left, the questions open one by one on the right. Native HTML accordions: no JavaScript, keyboard ready.
 *
 * @package Fluffless
 */

defined( 'ABSPATH' ) || exit;

?>
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
