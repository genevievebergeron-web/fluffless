<?php
/**
 * Title: Quote request
 * Slug: fluffless/formulaire-devis
 * Categories: fluffless-agir
 * Keywords: quote, estimate, request, price, budget, project, form
 * Viewport Width: 1000
 * Post Types: page
 * Fluffless Modules: form
 * Description: A quote request with a budget range and a project type; a company field appears only for organizations, without JavaScript.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained","contentSize":"720px"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Request a quote', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'Tell us about your project. We come back with a price and a timeline, without commitment.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:fluffless/formulaire {"confirm":true,"submitLabel":"<?php echo esc_attr__( 'Request the quote', 'fluffless-suite' ); ?>","success":"<?php echo esc_attr__( 'Thank you, your request has been received. We reply with a quote within five working days.', 'fluffless-suite' ); ?>"} -->
		<!-- wp:fluffless/champ {"type":"text","label":"<?php echo esc_attr__( 'Name', 'fluffless-suite' ); ?>","name":"nom","required":true} /-->

		<!-- wp:fluffless/champ {"type":"email","label":"<?php echo esc_attr__( 'Email', 'fluffless-suite' ); ?>","name":"courriel","required":true} /-->

		<!-- wp:fluffless/champ {"type":"radio","label":"<?php echo esc_attr__( 'You are', 'fluffless-suite' ); ?>","name":"statut","options":"<?php echo esc_attr__( 'An individual', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'An organization', 'fluffless-suite' ); ?>","required":true} /-->

		<!-- wp:fluffless/champ {"type":"text","label":"<?php echo esc_attr__( 'Organization name', 'fluffless-suite' ); ?>","name":"organisation","required":true,"when":"statut","is":"<?php echo esc_attr__( 'An organization', 'fluffless-suite' ); ?>"} /-->

		<!-- wp:fluffless/champ {"type":"select","label":"<?php echo esc_attr__( 'Project type', 'fluffless-suite' ); ?>","name":"type-de-projet","options":"<?php echo esc_attr__( 'New website', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Redesign', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Maintenance', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Other', 'fluffless-suite' ); ?>","placeholder":"<?php echo esc_attr__( 'Choose…', 'fluffless-suite' ); ?>","required":true} /-->

		<!-- wp:fluffless/champ {"type":"select","label":"<?php echo esc_attr__( 'Budget', 'fluffless-suite' ); ?>","name":"budget","options":"<?php echo esc_attr__( 'Under 2,000 $', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( '2,000 $ to 5,000 $', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( '5,000 $ to 15,000 $', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Over 15,000 $', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Not decided yet', 'fluffless-suite' ); ?>","placeholder":"<?php echo esc_attr__( 'Choose…', 'fluffless-suite' ); ?>"} /-->

		<!-- wp:fluffless/champ {"type":"date","label":"<?php echo esc_attr__( 'Wished delivery date', 'fluffless-suite' ); ?>","name":"echeance"} /-->

		<!-- wp:fluffless/champ {"type":"textarea","label":"<?php echo esc_attr__( 'Describe the project', 'fluffless-suite' ); ?>","name":"description","required":true,"help":"<?php echo esc_attr__( 'Goals, audience, pages or features, what exists already.', 'fluffless-suite' ); ?>"} /-->
	<!-- /wp:fluffless/formulaire -->
</div>
<!-- /wp:group -->
