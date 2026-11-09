<?php
/**
 * Title: Job application
 * Slug: fluffless/formulaire-candidature
 * Categories: fluffless-agir
 * Keywords: job, application, apply, hiring, career, candidate, form, steps
 * Viewport Width: 1000
 * Post Types: page
 * Fluffless Modules: form
 * Description: An application in three steps: contact details, background, then availability and motivation. Kept only if the block says so.
 *
 * @package Fluffless_Suite
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","right":"var:preset|spacing|50","bottom":"var:preset|spacing|80","left":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|50"}},"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained","contentSize":"720px"}} -->
<div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Apply', 'fluffless-suite' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'Three short steps, about five minutes. We read every application and reply to everyone.', 'fluffless-suite' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:fluffless/formulaire {"confirm":true,"submitLabel":"<?php echo esc_attr__( 'Send the application', 'fluffless-suite' ); ?>","success":"<?php echo esc_attr__( 'Thank you, your application has been received. We come back to you within two weeks.', 'fluffless-suite' ); ?>"} -->
		<!-- wp:fluffless/etape {"title":"<?php echo esc_attr__( 'Contact details', 'fluffless-suite' ); ?>"} -->
		<!-- wp:fluffless/champ {"type":"text","label":"<?php echo esc_attr__( 'Full name', 'fluffless-suite' ); ?>","name":"nom","required":true} /-->
		<!-- wp:fluffless/champ {"type":"email","label":"<?php echo esc_attr__( 'Email', 'fluffless-suite' ); ?>","name":"courriel","required":true} /-->
		<!-- wp:fluffless/champ {"type":"phone","label":"<?php echo esc_attr__( 'Phone', 'fluffless-suite' ); ?>","name":"telephone","required":true} /-->
		<!-- wp:fluffless/champ {"type":"text","label":"<?php echo esc_attr__( 'City', 'fluffless-suite' ); ?>","name":"ville"} /-->
		<!-- /wp:fluffless/etape -->

		<!-- wp:fluffless/etape {"title":"<?php echo esc_attr__( 'Background', 'fluffless-suite' ); ?>"} -->
		<!-- wp:fluffless/champ {"type":"select","label":"<?php echo esc_attr__( 'Position', 'fluffless-suite' ); ?>","name":"poste","options":"<?php echo esc_attr__( 'Developer', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Designer', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Project manager', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Other', 'fluffless-suite' ); ?>","placeholder":"<?php echo esc_attr__( 'Choose…', 'fluffless-suite' ); ?>","required":true} /-->
		<!-- wp:fluffless/champ {"type":"radio","label":"<?php echo esc_attr__( 'Years of experience', 'fluffless-suite' ); ?>","name":"experience","options":"<?php echo esc_attr__( 'Less than 2', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( '2 to 5', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( '5 to 10', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'More than 10', 'fluffless-suite' ); ?>","required":true} /-->
		<!-- wp:fluffless/champ {"type":"text","label":"<?php echo esc_attr__( 'Link to your work', 'fluffless-suite' ); ?>","name":"portfolio","help":"<?php echo esc_attr__( 'Website, code repository or public profile.', 'fluffless-suite' ); ?>"} /-->
		<!-- /wp:fluffless/etape -->

		<!-- wp:fluffless/etape {"title":"<?php echo esc_attr__( 'Availability and motivation', 'fluffless-suite' ); ?>"} -->
		<!-- wp:fluffless/champ {"type":"date","label":"<?php echo esc_attr__( 'Available from', 'fluffless-suite' ); ?>","name":"disponibilite","required":true} /-->
		<!-- wp:fluffless/champ {"type":"radio","label":"<?php echo esc_attr__( 'Wished schedule', 'fluffless-suite' ); ?>","name":"horaire","options":"<?php echo esc_attr__( 'Full time', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Part time', 'fluffless-suite' ); ?>\n<?php echo esc_attr__( 'Contract', 'fluffless-suite' ); ?>","required":true} /-->
		<!-- wp:fluffless/champ {"type":"textarea","label":"<?php echo esc_attr__( 'Why this position', 'fluffless-suite' ); ?>","name":"motivation","required":true} /-->
		<!-- wp:fluffless/champ {"type":"consent","label":"<?php echo esc_attr__( 'I agree that this application is kept for six months', 'fluffless-suite' ); ?>","name":"consentement","required":true} /-->
		<!-- /wp:fluffless/etape -->
	<!-- /wp:fluffless/formulaire -->
</div>
<!-- /wp:group -->
