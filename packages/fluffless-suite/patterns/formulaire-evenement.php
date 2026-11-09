<?php
/**
 * Title: Event registration
 * Slug: fluffless/formulaire-evenement
 * Categories: fluffless-agir
 * Keywords: event, registration, sign up, attend, workshop, conference, form, steps
 * Viewport Width: 1000
 * Post Types: page
 * Fluffless Modules: form
 * Description: A registration in two steps: who is coming, then how many seats and any need to know. A copy goes to the person.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained","contentSize":"720px"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Register for the event', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'Two short steps. A confirmation lands in your inbox right after.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:fluffless/formulaire {"confirm":true,"submitLabel":"<?php echo esc_attr__( 'Register', 'fluffless-suite' ); ?>","success":"<?php echo esc_attr__( 'Thank you, your registration has been received. A copy has been sent to your email address.', 'fluffless-suite' ); ?>"} -->
		<!-- wp:fluffless/etape {"title":"<?php echo esc_attr__( 'Who is coming', 'fluffless-suite' ); ?>"} -->
		<!-- wp:fluffless/champ {"type":"text","label":"<?php echo esc_attr__( 'Name', 'fluffless-suite' ); ?>","name":"nom","required":true} /-->
		<!-- wp:fluffless/champ {"type":"email","label":"<?php echo esc_attr__( 'Email', 'fluffless-suite' ); ?>","name":"courriel","required":true} /-->
		<!-- wp:fluffless/champ {"type":"phone","label":"<?php echo esc_attr__( 'Phone', 'fluffless-suite' ); ?>","name":"telephone","help":"<?php echo esc_attr__( 'Optional, only to reach you on the day.', 'fluffless-suite' ); ?>"} /-->
		<!-- /wp:fluffless/etape -->

		<!-- wp:fluffless/etape {"title":"<?php echo esc_attr__( 'Your seats', 'fluffless-suite' ); ?>"} -->
		<!-- wp:fluffless/champ {"type":"number","label":"<?php echo esc_attr__( 'Number of seats', 'fluffless-suite' ); ?>","name":"places","required":true,"placeholder":"<?php echo esc_attr__( '1', 'fluffless-suite' ); ?>"} /-->
		<!-- wp:fluffless/champ {"type":"radio","label":"<?php echo esc_attr__( 'Will you attend', 'fluffless-suite' ); ?>","name":"presence","options":"<?php echo esc_attr__( 'In person', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Online', 'fluffless-suite' ); ?>","required":true} /-->
		<!-- wp:fluffless/champ {"type":"checkboxes","label":"<?php echo esc_attr__( 'Please note', 'fluffless-suite' ); ?>","name":"besoins","options":"<?php echo esc_attr__( 'Vegetarian meal', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Wheelchair access', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Sign language interpretation', 'fluffless-suite' ); ?>"} /-->
		<!-- wp:fluffless/champ {"type":"textarea","label":"<?php echo esc_attr__( 'Anything else we should know', 'fluffless-suite' ); ?>","name":"notes"} /-->
		<!-- wp:fluffless/champ {"type":"consent","label":"<?php echo esc_attr__( 'I agree to be contacted about this event', 'fluffless-suite' ); ?>","name":"consentement","required":true} /-->
		<!-- /wp:fluffless/etape -->
	<!-- /wp:fluffless/formulaire -->
</div>
<!-- /wp:group -->
