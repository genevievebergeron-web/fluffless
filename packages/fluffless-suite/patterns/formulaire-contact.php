<?php
/**
 * Title: Contact form
 * Slug: fluffless/formulaire-contact
 * Categories: fluffless-agir
 * Keywords: contact, form, message, email, reach us, write to us
 * Viewport Width: 1000
 * Post Types: page
 * Fluffless Modules: form
 * Description: A short contact form: name, email, phone (optional), subject and message, with a copy sent to the person.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained","contentSize":"720px"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Write to us', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'A reply within two working days. No account, no newsletter.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:fluffless/formulaire {"confirm":true,"submitLabel":"<?php echo esc_attr__( 'Send the message', 'fluffless-suite' ); ?>","success":"<?php echo esc_attr__( 'Thank you, your message has been sent. We reply within two working days.', 'fluffless-suite' ); ?>"} -->
		<!-- wp:fluffless/champ {"type":"text","label":"<?php echo esc_attr__( 'Name', 'fluffless-suite' ); ?>","name":"nom","required":true} /-->

		<!-- wp:fluffless/champ {"type":"email","label":"<?php echo esc_attr__( 'Email', 'fluffless-suite' ); ?>","name":"courriel","required":true} /-->

		<!-- wp:fluffless/champ {"type":"phone","label":"<?php echo esc_attr__( 'Phone', 'fluffless-suite' ); ?>","name":"telephone","help":"<?php echo esc_attr__( 'Optional, if you prefer a call.', 'fluffless-suite' ); ?>"} /-->

		<!-- wp:fluffless/champ {"type":"select","label":"<?php echo esc_attr__( 'Subject', 'fluffless-suite' ); ?>","name":"sujet","options":"<?php echo esc_attr__( 'A question', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'A project', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Something else', 'fluffless-suite' ); ?>","placeholder":"<?php echo esc_attr__( 'Choose…', 'fluffless-suite' ); ?>"} /-->

		<!-- wp:fluffless/champ {"type":"textarea","label":"<?php echo esc_attr__( 'Message', 'fluffless-suite' ); ?>","name":"message","required":true} /-->

		<!-- wp:fluffless/champ {"type":"hidden","label":"<?php echo esc_attr__( 'Source', 'fluffless-suite' ); ?>","name":"source","value":"<?php echo esc_attr__( 'Contact page', 'fluffless-suite' ); ?>"} /-->
	<!-- /wp:fluffless/formulaire -->
</div>
<!-- /wp:group -->
