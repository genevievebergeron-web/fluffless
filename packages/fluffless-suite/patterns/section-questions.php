<?php
/**
 * Title: Frequently asked questions, adjustable
 * Slug: fluffless/section-questions
 * Categories: fluffless-prouver
 * Keywords: faq, questions, answers, accordion, details, help, adjustable
 * Viewport Width: 1400
 * Post Types: page, wp_template
 * Fluffless Modules: expression
 * Description: A heading and four questions that open one by one, without JavaScript. The sidebar adds a question in one click and opens the first answer by default; the SEO module turns them into FAQ structured data.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

$fluffless_questions = array(
	array( __( 'How much does it cost?', 'fluffless-suite' ), __( 'Give a starting price or a range. People leave when they have to ask for it.', 'fluffless-suite' ) ),
	array( __( 'How long does it take?', 'fluffless-suite' ), __( 'A realistic timeline, from the first call to the finished work. Mention what can slow things down.', 'fluffless-suite' ) ),
	array( __( 'Who is it not for?', 'fluffless-suite' ), __( 'Saying no clearly saves everyone time, and it makes the right people trust you more.', 'fluffless-suite' ) ),
	array( __( 'What happens after I get in touch?', 'fluffless-suite' ), __( 'Walk through the next three steps. Nobody likes wondering whether their message got lost.', 'fluffless-suite' ) ),
);
?>
<!-- wp:fluffless/questions {"align":"wide"} -->
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'The questions we hear every week', 'fluffless-suite' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"className":"fluffless-questions__list","layout":{"type":"default"}} -->
<div class="wp-block-group fluffless-questions__list">
<?php foreach ( $fluffless_questions as $fluffless_question ) : ?>
<!-- wp:details {"className":"is-style-bordure-brute"} -->
<details class="wp-block-details is-style-bordure-brute"><summary><?php echo esc_html( $fluffless_question[0] ); ?></summary>
<!-- wp:paragraph -->
<p><?php echo esc_html( $fluffless_question[1] ); ?></p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
<!-- /wp:fluffless/questions -->
